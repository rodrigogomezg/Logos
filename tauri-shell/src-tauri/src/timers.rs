// Port a Rust de los timers de electron/main.js (heartbeat de licencia,
// backup automático, health-check) — spike Fase 1B, ver plan de migración.
//
// La razón de que esto viva acá y no como setInterval/setTimeout del lado del
// webview (como en pos/*.js hoy) es el hallazgo de la investigación del plan:
// los timers de JS en un webview de Tauri dejan de correr a los ~5-6 minutos
// de tener la ventana minimizada (issue documentado de Tauri). El heartbeat
// de licencia y el backup NO pueden depender de que la ventana esté visible
// — tienen que seguir corriendo con la app en la bandeja. Por eso viven acá,
// en tareas de tokio spawneadas sobre el runtime async de Tauri, totalmente
// independientes del ciclo de eventos del webview.

use chrono::Local;
use serde::Deserialize;
use serde_json::json;
use std::fs::OpenOptions;
use std::io::Write;
use std::net::UdpSocket;
use std::path::PathBuf;
use std::time::Duration;

fn log_path() -> PathBuf {
    PathBuf::from(r"C:\ProgramData\LogosPOS\licencia-debug.log")
}

fn log_line(msg: &str) {
    if let Some(dir) = log_path().parent() {
        let _ = std::fs::create_dir_all(dir);
    }
    if let Ok(mut f) = OpenOptions::new().create(true).append(true).open(log_path()) {
        let _ = writeln!(f, "{} {}", Local::now().to_rfc3339(), msg);
    }
    println!("[timers] {msg}");
}

// Mismo truco que usa cualquier "get local IP" sin dependencias extra: conectar
// un socket UDP a una IP pública no envía ningún paquete (UDP es sin conexión),
// solo fuerza al SO a resolver qué interfaz/IP local usaría para esa ruta.
fn local_ip() -> Option<String> {
    let socket = UdpSocket::bind("0.0.0.0:0").ok()?;
    socket.connect("8.8.8.8:80").ok()?;
    socket.local_addr().ok().map(|a| a.ip().to_string())
}

#[derive(Deserialize, Default)]
struct VerificarResp {
    estado_efectivo: Option<String>,
}

const INTERVALO_LICENCIA_NORMAL: Duration = Duration::from_secs(6 * 60 * 60);
const INTERVALO_LICENCIA_RAPIDO: Duration = Duration::from_secs(5 * 60);

// Igual que ejecutarVerificacionLicencia() + startLicenciaTimer() en main.js:
// se reprograma a sí mismo con delay adaptativo — 6h si el Hub contesta
// "al_dia", 5min en cualquier otro caso (en_gracia/bloqueado/no se pudo
// verificar), para no dejar a nadie bloqueado de más tiempo del necesario
// después de que se registre un pago.
//
// `token` acá siempre viaja vacío desde lib.rs — no es un bug, es el diseño:
// LicenciaController (PHP) guarda el token real en licencia_estado.token y
// cae a ese valor cuando el body no trae uno. Tauri nunca necesita saber el
// token en sí, a diferencia de Electron (que sí lo lee de su config local).
pub async fn run_licencia_heartbeat(base_url: String, token: String, app_version: String) {
    let client = reqwest::Client::new();
    loop {
        let ip_local = local_ip();
        let url = format!("{base_url}/Logos/api/licencia/verificar");
        let body = json!({
            "token": token,
            "version_app": app_version,
            "ip_local": ip_local,
            "puerto_local": null,
        });

        log_line(&format!("licencia: INICIO url={url} token_presente={}", !token.is_empty()));

        let estado = match tokio::time::timeout(Duration::from_secs(10), client.post(&url).json(&body).send()).await {
            Ok(Ok(resp)) => {
                let status = resp.status();
                match resp.json::<VerificarResp>().await {
                    Ok(v) => {
                        log_line(&format!("licencia: RESPUESTA status={status} estado={:?}", v.estado_efectivo));
                        v.estado_efectivo
                    }
                    Err(e) => {
                        log_line(&format!("licencia: RESPUESTA_NO_JSON status={status} err={e}"));
                        None
                    }
                }
            }
            Ok(Err(e)) => {
                log_line(&format!("licencia: ERROR_CONEXION {e}"));
                None
            }
            Err(_) => {
                log_line("licencia: TIMEOUT tras 10s sin respuesta");
                None
            }
        };

        let delay = if estado.as_deref() == Some("al_dia") {
            INTERVALO_LICENCIA_NORMAL
        } else {
            INTERVALO_LICENCIA_RAPIDO
        };
        log_line(&format!("licencia: próximo chequeo en {}s", delay.as_secs()));
        tokio::time::sleep(delay).await;
    }
}

// Igual que callBackupProgramado() + startBackupTimer(): el backend decide si
// ya pasó suficiente tiempo desde el último backup real (BackupController);
// este timer solo garantiza que alguien lo llame cada 1h pase lo que pase,
// sin depender de que un usuario entre a Configuración o cierre turno.
pub async fn run_backup_timer(base_url: String) {
    let client = reqwest::Client::new();
    loop {
        let url = format!("{base_url}/Logos/api/backup/programado");
        let result = tokio::time::timeout(Duration::from_secs(20), client.post(&url).send()).await;
        match result {
            Ok(Ok(resp)) => log_line(&format!("backup: OK status={}", resp.status())),
            Ok(Err(e)) => log_line(&format!("backup: ERROR {e}")),
            Err(_) => log_line("backup: TIMEOUT tras 20s"),
        }
        tokio::time::sleep(Duration::from_secs(60 * 60)).await;
    }
}

// JS inyectado vía WebviewWindow::eval — igual espíritu que
// showClientDisconnectedError()/error.html en Electron, pero sin una ventana
// overlay aparte: un div fullscreen inyectado en la propia SPA. Idempotente
// (chequea el id antes de crear) para no duplicar si el health-check vuelve
// a fallar antes de que se resuelva la reconexión.
//
// TODO gap real de producción (no resuelto en el corte a Tauri): no existe
// todavía un comando/estado de client-config acá — el rol Cliente real de
// Electron permite cambiar la IP del servidor desde este mismo overlay; acá
// solo se ofrece "Reintentar" (el propio loop de abajo ya reintenta solo
// cada 8s, así que es cosmético). Portar el comando real de reconfigurar IP
// cuando haga falta.
const JS_MOSTRAR_OVERLAY: &str = r#"(function(){
  if (document.getElementById('logos-disconnected')) return;
  var d = document.createElement('div');
  d.id = 'logos-disconnected';
  d.style.cssText = 'position:fixed;inset:0;z-index:999999;background:rgba(15,15,20,0.92);color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;font-family:system-ui,sans-serif;text-align:center;padding:24px;';
  d.innerHTML = '<div style="font-size:20px;font-weight:600;margin-bottom:8px;">No se puede conectar al servidor</div>' +
    '<div style="font-size:14px;opacity:0.8;margin-bottom:20px;max-width:420px;">Verificá que la PC servidor esté encendida y conectada a la red. Reintentando automáticamente...</div>' +
    '<button id="logos-retry" style="padding:8px 20px;border-radius:6px;border:none;background:#fff;color:#111;font-weight:600;cursor:pointer;">Reintentar ahora</button>';
  document.body.appendChild(d);
  document.getElementById('logos-retry').onclick = function(){
    this.textContent = 'Reintentando…';
    this.disabled = true;
  };
})();"#;

const JS_OCULTAR_OVERLAY: &str = r#"(function(){
  var d = document.getElementById('logos-disconnected');
  if (d) d.remove();
})();"#;

// Igual que pingUrl()/startHealthCheck(): a diferencia del spike original
// (que solo logueaba), ahora sí muestra/oculta una pantalla de desconexión
// real en la ventana principal — necesario para la paridad de rol Cliente
// con Electron (ver plan Fase 3 / Chunk 3). El chequeo periódico en sí sigue
// corriendo igual con la ventana minimizada (el motivo original de que esto
// viva en tokio y no en JS del webview).
pub async fn run_health_check(base_url: String, app_handle: tauri::AppHandle) {
    use tauri::Manager;
    let client = reqwest::Client::new();
    let mut fails = 0u32;
    let mut disconnected = false;
    loop {
        let url = format!("{base_url}/Logos/ping");
        let ok = matches!(
            tokio::time::timeout(Duration::from_secs(4), client.head(&url).send()).await,
            Ok(Ok(resp)) if resp.status().as_u16() < 500
        );
        if ok {
            if fails > 0 { log_line("health-check: recuperado"); }
            fails = 0;
            if disconnected {
                disconnected = false;
                if let Some(win) = app_handle.get_webview_window("main") {
                    let _ = win.eval(JS_OCULTAR_OVERLAY);
                }
            }
        } else {
            fails += 1;
            log_line(&format!("health-check: falló ({fails}/3)"));
            if fails >= 3 && !disconnected {
                disconnected = true;
                log_line("health-check: 3 fallos consecutivos — mostrando pantalla de desconexión");
                if let Some(win) = app_handle.get_webview_window("main") {
                    let _ = win.eval(JS_MOSTRAR_OVERLAY);
                }
            }
        }
        tokio::time::sleep(Duration::from_secs(8)).await;
    }
}
