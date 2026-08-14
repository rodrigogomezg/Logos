mod role;
mod server_manager;
mod splash;
mod timers;
mod tray;

use std::path::PathBuf;
use std::sync::{Arc, Mutex};
use std::time::Duration;
use tauri::{Manager, RunEvent, WebviewUrl, WebviewWindowBuilder};
use tauri_plugin_updater::{Update, UpdaterExt};
use url::Url;

use server_manager::ServerManager;

// Spike 1B (ver plan de migración / project_migracion_sveltekit_tauri en
// memoria): rutas propias, separadas de la instalación real de Electron que
// puede estar corriendo en paralelo en esta misma PC de pruebas — nunca el
// mismo datadir, puerto ni db.local.php que esa (ver server_manager.rs).
fn repo_root() -> PathBuf {
    PathBuf::from(r"C:\xampp\htdocs\Logos")
}

struct AppPaths {
    resources: PathBuf,     // contiene mariadb/, php/
    www: PathBuf,           // contiene router.php
    app_dir: PathBuf,       // contiene api/, pos/, vendor/, install/, migrate/
    app_build_dir: PathBuf, // build estático de la SPA (SvelteKit, adapter-static)
    splash_argb: PathBuf,   // dump crudo BGRA premultiplicado para el splash nativo (ver splash.rs)
}

// Caso real que motivó esto (ver conversación con Rodrigo, prueba en 2da PC):
// el binario instalado no hacía nada al abrir porque server_manager.rs tenía
// hardcodeado el checkout de este repo en la máquina de desarrollo — nunca se
// habían empaquetado los binarios portables ni el árbol PHP como resources
// del bundle. Un instalador que solo funciona en la PC de quien lo compiló no
// es un POC válido de "se puede instalar en cualquier PC".
//
// Ahora resuelve vía std::env::current_exe() en vez de app.path().resource_dir()
// — a propósito, para poder llamarse ANTES de que exista un tauri::App (ver
// comentario grande en run()). Verificado a mano contra el instalador NSIS
// real (extracción con 7z): los resources quedan flat junto al .exe, no bajo
// una subcarpeta "resources/".
fn resolve_paths() -> AppPaths {
    if let Ok(exe) = std::env::current_exe() {
        if let Some(dir) = exe.parent() {
            let dir = dir.to_path_buf();
            if dir.join("mariadb").join("bin").join("mysqld.exe").exists() {
                return AppPaths {
                    resources: dir.clone(),
                    www: dir.join("www"),
                    app_dir: dir.join("www").join("Logos"),
                    app_build_dir: dir.join("www").join("app"),
                    splash_argb: dir.join("splash_argb.bin"),
                };
            }
        }
    }
    let root = repo_root();
    let dev_resources = root.join("tauri-shell").join("src-tauri").join("resources");
    AppPaths {
        www: dev_resources.join("www"),
        resources: dev_resources,
        app_build_dir: root.join("app").join("build"),
        splash_argb: root.join("tauri-shell").join("src-tauri").join("nsis").join("assets").join("splash_argb.bin"),
        app_dir: root,
    }
}

#[cfg(windows)]
fn show_error_box(msg: &str) {
    use std::ffi::OsStr;
    use std::iter::once;
    use std::os::windows::ffi::OsStrExt;
    unsafe extern "system" {
        fn MessageBoxW(hwnd: isize, text: *const u16, caption: *const u16, utype: u32) -> i32;
    }
    let wide_text: Vec<u16> = OsStr::new(msg).encode_wide().chain(once(0)).collect();
    let wide_title: Vec<u16> = OsStr::new("Logos POS — Error de arranque").encode_wide().chain(once(0)).collect();
    unsafe {
        MessageBoxW(0, wide_text.as_ptr(), wide_title.as_ptr(), 0x10 /* MB_ICONERROR */);
    }
}

// Caso real (ver conversación con Rodrigo, 2da PC): la app no abría y no
// mostraba absolutamente nada — ni ventana, ni error, ni proceso visible un
// instante. Causa: esta app es windows_subsystem="windows" (sin consola,
// ver main.rs) y CUALQUIER panic de Rust (ej. si tauri::Builder::build()
// falla porque falta el runtime de WebView2) mata el proceso en silencio —
// el mensaje del panic se escribe a un stderr que no existe para nadie.
// Este hook reemplaza eso: cualquier panic, sea cual sea la causa, ahora
// muestra un MessageBox antes de morir. No volver a sacar esto.
#[cfg(windows)]
fn instalar_panic_hook_visible() {
    std::panic::set_hook(Box::new(|info| {
        let msg = format!("La aplicación encontró un error fatal y tiene que cerrarse:\n\n{info}");
        show_error_box(&msg);
    }));
}

/// Decide a qué URL navegar, igual que startServerRole() en main.js:
/// - Supervisor: espera /Logos/ping, después aplica el doble-chequeo
///   defensivo de instalacion/estado (ver role::decide_needs_setup_with_recheck
///   y su comentario — protege contra el incidente real del 01/08/2026).
/// - Initiator: la decisión ya la dio start_database() (is_first_setup),
///   sin necesidad de otra vuelta de red — mismo criterio que Electron.
async fn decide_start_url(base_url: &str, mode: role::ServiceMode, is_first_setup_initiator: bool) -> String {
    let needs_setup = match mode {
        role::ServiceMode::Supervisor => {
            if let Err(e) = role::wait_for_url(&format!("{base_url}/Logos/ping"), Duration::from_secs(15)).await {
                println!("[role] /Logos/ping no respondió a tiempo: {e}");
            }
            let (needs_setup, _) = role::decide_needs_setup_with_recheck(base_url).await;
            needs_setup
        }
        role::ServiceMode::Initiator => is_first_setup_initiator,
    };
    if needs_setup {
        format!("{base_url}/Logos/pos/instalar.html")
    } else {
        format!("{base_url}/")
    }
}

/// Ventana de "Actualizando..." — misma idea que `renderer/updating.html` +
/// `performUpdate()` en electron/main.js: una ventanita propia, sin marco,
/// que muestra progreso real de la descarga y se queda al frente hasta que
/// el instalador silencioso reinicia el proceso. Se muestra en vez de la
/// ventana principal (nunca se llega a ver el login) — a diferencia de la
/// versión anterior de este archivo, que chequeaba el update DESPUÉS de ya
/// haber creado y mostrado la ventana con el login.
///
/// Usa una página local embebida (`WebviewUrl::App`, bundleada vía
/// `frontendDist`) en vez de servirla desde el propio php.exe local — así
/// no depende de que el servidor local siga respondiendo durante toda la
/// descarga/instalación (en modo supervisor los servicios se paran ANTES de
/// descargar, ver abajo). El progreso se empuja con `WebviewWindow::eval()`,
/// mismo mecanismo que ya usan tray.rs/timers.rs para el overlay de
/// "servidor caído" — no hace falta IPC nuevo.
///
/// Devuelve `true` si `download_and_install()` disparó el instalador
/// silencioso (el proceso se va a reiniciar solo — no hay que seguir con el
/// arranque normal). Devuelve `false` en cualquier error — la ventana se
/// cierra y el caller sigue con el arranque normal, igual que el
/// `handleError` de Electron.
async fn mostrar_ventana_actualizando(
    app_handle: &tauri::AppHandle,
    update: &Update,
    service_mode: role::ServiceMode,
) -> bool {
    // Esperar a que la página local termine de cargar antes del primer
    // eval() — mismo motivo que Electron esperaba 'did-finish-load' antes
    // del primer webContents.send(). tokio::sync::Notify en vez de un
    // delay fijo: es una página local embebida (sin red), pero no vale la
    // pena arriesgar una carrera igual. `on_page_load` se registra en el
    // builder, ANTES de build() — build() ya dispara la carga, así que
    // engancharlo después arriesga perderse el evento Finished si carga
    // demasiado rápido.
    let cargada = Arc::new(tokio::sync::Notify::new());
    let cargada_load = cargada.clone();
    let win = match WebviewWindowBuilder::new(app_handle, "updating", WebviewUrl::App("updating.html".into()))
        .title("Logos POS — Actualizando")
        .inner_size(320.0, 320.0)
        .resizable(false)
        .decorations(false)
        .center()
        .on_page_load(move |_webview, payload| {
            if payload.event() == tauri::webview::PageLoadEvent::Finished {
                cargada_load.notify_one();
            }
        })
        .build()
    {
        Ok(w) => w,
        Err(e) => {
            println!("[updater] no se pudo crear la ventana de actualización: {e}");
            return false;
        }
    };
    let _ = tokio::time::timeout(Duration::from_secs(5), cargada.notified()).await;

    let _ = win.eval(&format!("window.updateStart && window.updateStart({:?})", update.version));

    // En modo supervisor, el instalador silencioso no puede sobreescribir
    // mysqld.exe/php.exe (ni sus DLLs) mientras los servicios NSSM siguen
    // corriendo y los tienen abiertos — mismo fix que Electron aplica antes
    // de quitAndInstall(). Se para ANTES de descargar (no solo antes de
    // instalar) para no tener que volver a tocar el orden más adelante — la
    // ventana de actualización ya no depende del servidor local para nada
    // (página local embebida), así que no hay costo por pararlos temprano.
    if service_mode == role::ServiceMode::Supervisor {
        println!("[updater] modo supervisor — deteniendo servicios antes de instalar...");
        role::stop_supervisor_services();
    }

    let win_progreso = win.clone();
    let mut descargado: usize = 0;
    let inicio_descarga = std::time::Instant::now();
    let resultado = update
        .download_and_install(
            move |chunk_len, total| {
                descargado += chunk_len;
                if let Some(total) = total {
                    if total > 0 {
                        let pct = ((descargado as f64 / total as f64) * 100.0).min(100.0) as u32;
                        // ETA a partir de la velocidad promedio hasta ahora
                        // (bytes descargados / tiempo transcurrido) — simple
                        // y suficiente acá, no hace falta una ventana
                        // deslizante para una descarga de un solo archivo.
                        let elapsed = inicio_descarga.elapsed().as_secs_f64();
                        let eta = if elapsed > 0.2 && descargado > 0 {
                            let rate = descargado as f64 / elapsed;
                            let restante = (total as f64 - descargado as f64).max(0.0);
                            restante / rate
                        } else {
                            f64::INFINITY
                        };
                        let eta_js = if eta.is_finite() { eta.to_string() } else { "null".to_string() };
                        let _ = win_progreso.eval(&format!(
                            "window.updateProgress && window.updateProgress({pct}, {eta_js})"
                        ));
                    }
                }
            },
            {
                let win = win.clone();
                move || {
                    let _ = win.eval("window.updateInstalling && window.updateInstalling()");
                }
            },
        )
        .await;

    match resultado {
        Ok(()) => {
            println!("[updater] instalación disparada — la app debería reiniciarse sola.");
            true
        }
        Err(e) => {
            println!("[updater] error al descargar/instalar: {e}");
            let _ = win.close();
            false
        }
    }
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    #[cfg(windows)]
    instalar_panic_hook_visible();

    let paths = resolve_paths();

    // Splash nativo (Win32 crudo, no WebviewWindow — ver splash.rs) mientras
    // arrancan los servicios y todavía no hay ninguna ventana de Tauri
    // visible. Best-effort: si falla, la app arranca igual sin splash — no
    // es un requisito para funcionar, solo para no parecer colgada. En los
    // pocos casos de error fatal más abajo (return temprano) el splash
    // puede quedar abierto hasta que el proceso termina un instante después
    // — aceptable, Windows lo destruye solo al salir del proceso.
    let mut splash = splash::Splash::show(&paths.splash_argb);

    let service_mode = role::detect_service_mode();
    println!("[role] modo detectado: {service_mode:?}");

    // Caso real (ver conversación con Rodrigo, 2da PC — varias rondas de
    // debugging): php.exe -S moría con 0xc0000005 (access violation) SOLO
    // cuando lo lanzaba este proceso — el mismo binario, mismos argumentos,
    // mismas variables de entorno, corrido a mano desde una consola normal
    // O desde un programa Rust mínimo sin Tauri, andaba perfecto siempre.
    // La única variable que quedó fue "spawneado desde un proceso con
    // Tauri/WebView2 ya inicializado". En vez de perseguir el mecanismo
    // exacto a nivel Win32, se evita de raíz: si este proceso arranca
    // mysqld+php (modo initiator), lo hace ACÁ, antes de que exista un
    // tauri::Builder siquiera — en un proceso todavía "limpio". En modo
    // supervisor no aplica: los servicios ya los arrancó NSSM, este proceso
    // solo verifica que respondan (wait_for_database, sin spawnear nada).
    let (base_url, manager, is_first_setup_initiator) = match service_mode {
        role::ServiceMode::Supervisor => {
            let config = match role::read_config() {
                Some(c) => c,
                None => {
                    let msg = format!(
                        "Se detectó el servicio {} registrado, pero no se encontró {}\\logos-config.json.",
                        role::SERVICE_DB,
                        role::data_root().display()
                    );
                    #[cfg(windows)]
                    show_error_box(&msg);
                    eprintln!("[role] {msg}");
                    return;
                }
            };
            let mut manager = ServerManager::new(
                paths.resources,
                paths.www,
                paths.app_dir,
                paths.app_build_dir,
                role::data_root().join("mysql-data"),
            );
            manager.mark_supervisor(config.db_port);
            if let Err(e) = manager.wait_for_database(Duration::from_secs(45)) {
                let msg = format!(
                    "El servicio {} (base de datos) no respondió a tiempo:\n\n{e}",
                    role::SERVICE_DB
                );
                #[cfg(windows)]
                show_error_box(&msg);
                eprintln!("[role] {msg}");
                return;
            }
            // A diferencia del modo initiator (start_database() ya corre
            // migraciones), acá nadie las corría — la base quedaba clavada
            // en lo último aplicado la última vez que este proceso corrió en
            // modo initiator (ej. durante desarrollo), sin importar cuántas
            // migraciones nuevas trajera cada actualización real. Mismo
            // patrón que electron/main.js: runMigrations() explícito acá.
            manager.run_migrations();
            println!("[role] supervisor — db_port={} php_port={}", config.db_port, config.server_port);
            (format!("http://127.0.0.1:{}", config.server_port), manager, false)
        }
        role::ServiceMode::Initiator => {
            let mut manager = ServerManager::new(
                paths.resources,
                paths.www,
                paths.app_dir,
                paths.app_build_dir,
                PathBuf::from(r"C:\ProgramData\LogosPOS\mysql-data"),
            );
            let start = match manager.start_database() {
                Ok(s) => s,
                Err(e) => {
                    let msg = format!("No se pudo iniciar el servidor de Logos POS:\n\n{e}");
                    #[cfg(windows)]
                    show_error_box(&msg);
                    eprintln!("[server_manager] {msg}");
                    return;
                }
            };
            println!(
                "[server_manager] listo — db_port={} php_port={} primera_instalacion={}",
                start.db_port, start.php_port, start.is_first_setup
            );
            (format!("http://127.0.0.1:{}", start.php_port), manager, start.is_first_setup)
        }
    };

    tauri::Builder::default()
        .plugin(tauri_plugin_updater::Builder::new().build())
        .setup(move |app| {
            let app_handle = app.handle().clone();
            let base_url_task = base_url.clone();

            // La decisión de URL (instalar.html vs SPA en "/") necesita red
            // (ping + doble-chequeo de instalacion/estado en modo supervisor)
            // — se resuelve en una tarea async ANTES de crear la ventana, no
            // sincrónicamente acá, para no bloquear el hilo de setup de Tauri.
            tauri::async_runtime::spawn(async move {
                // Chequeo de actualización — ANTES de decidir la URL o crear
                // la ventana principal, para que un cliente con una versión
                // vieja nunca llegue a ver el login (mismo criterio que
                // Electron: checkForUpdates() corre en app.whenReady(),
                // antes de cualquier ventana — ver electron/main.js). El
                // endpoint real (Hostinger) ya está configurado en
                // tauri.conf.json — nada que ver con base_url_task (ese es
                // el servidor local de esta PC, no el canal de updates).
                let update_disponible = match app_handle.updater_builder().build() {
                    Ok(updater) => match updater.check().await {
                        Ok(Some(update)) => Some(update),
                        Ok(None) => {
                            println!("[updater] sin actualizaciones");
                            None
                        }
                        Err(e) => {
                            println!("[updater] error al chequear: {e}");
                            None
                        }
                    },
                    Err(e) => {
                        println!("[updater] error al construir el updater: {e}");
                        None
                    }
                };

                if let Some(update) = update_disponible {
                    println!(
                        "[updater] actualización disponible: {} -> {} — descargando e instalando...",
                        update.current_version, update.version
                    );
                    // Cerrar el splash nativo ANTES de crear la ventana de
                    // actualización — si no, se queda tapando el recuadro
                    // (el splash solo se cerraba en el camino de la ventana
                    // principal, nunca en este). `.take()` (no un simple
                    // move) porque si la descarga/instalación falla, el
                    // flujo sigue más abajo hacia la ventana principal, que
                    // también intenta cerrar `splash` — con `.take()` ese
                    // segundo intento encuentra `None` y no hace nada, en
                    // vez de fallar en tiempo de compilación por usar un
                    // valor ya movido.
                    if let Some(s) = splash.take() {
                        s.close();
                    }
                    let instalado = mostrar_ventana_actualizando(&app_handle, &update, service_mode).await;
                    if instalado {
                        // El instalador silencioso reinicia el proceso solo
                        // (schtasks, ver installer-hooks.nsh) — no seguir con
                        // el arranque normal. El splash puede quedar abierto
                        // hasta que el proceso termine (Windows lo destruye
                        // solo), igual que en los demás returns tempranos.
                        return;
                    }
                    // Falló la descarga/instalación: seguir con el arranque
                    // normal más abajo, igual que Electron (handleError
                    // cierra la ventana de update y deja que la app arranque).
                }

                let target = decide_start_url(&base_url_task, service_mode, is_first_setup_initiator).await;
                let url = match Url::parse(&target) {
                    Ok(u) => u,
                    Err(e) => {
                        println!("[role] URL inválida ({target}): {e}");
                        return;
                    }
                };

                let version = app_handle.package_info().version.to_string();
                let _window = match WebviewWindowBuilder::new(&app_handle, "main", WebviewUrl::External(url))
                    .title(format!("Logos POS — v{version}"))
                    .inner_size(1280.0, 800.0)
                    .min_inner_size(1024.0, 640.0)
                    .build()
                {
                    Ok(w) => w,
                    Err(e) => {
                        println!("[role] no se pudo crear la ventana: {e}");
                        #[cfg(windows)]
                        show_error_box(&format!("No se pudo crear la ventana principal:\n\n{e}"));
                        return;
                    }
                };

                // Ventana principal lista — ya no hace falta el splash.
                if let Some(s) = splash {
                    s.close();
                }

                // Bandeja + cerrar-con-X-esconde-y-limpia-sesión — ver tray.rs.
                if let Err(e) = tray::setup_tray(&app_handle) {
                    println!("[tray] no se pudo crear la bandeja: {e}");
                }

                // Tareas en tokio, no en JS del webview — tienen que seguir
                // corriendo con la ventana minimizada (ver timers.rs).
                // El token siempre se manda vacío a propósito: LicenciaController
                // (PHP) ahora es el dueño del token — cae al que tiene guardado
                // en licencia_estado.token cuando el body no trae uno. Tauri no
                // necesita ningún mecanismo propio de leer/guardar el token (a
                // diferencia de Electron, que sigue mandando el suyo local vía
                // configManager — ambos caminos conviven, el backend resuelve).
                tauri::async_runtime::spawn(timers::run_licencia_heartbeat(
                    base_url_task.clone(),
                    String::new(),
                    version,
                ));
                tauri::async_runtime::spawn(timers::run_backup_timer(base_url_task.clone()));
                tauri::async_runtime::spawn(timers::run_afip_retry_timer(base_url_task.clone()));
                tauri::async_runtime::spawn(timers::run_health_check(base_url_task.clone(), app_handle.clone()));
            });

            app.manage(Mutex::new(manager));
            Ok(())
        })
        .build(tauri::generate_context!())
        .expect("error while building tauri application")
        .run(|app_handle, event| {
            // Both events are handled (ExitRequested: no windows left; Exit:
            // triggered directly by app.exit() from the tray "Salir") —
            // stop_all() is idempotent (Option::take()), safe if both fire.
            if matches!(event, RunEvent::ExitRequested { .. } | RunEvent::Exit) {
                if let Some(state) = app_handle.try_state::<Mutex<ServerManager>>() {
                    if let Ok(mut manager) = state.lock() {
                        manager.stop_all();
                    }
                }
            }
        });
}
