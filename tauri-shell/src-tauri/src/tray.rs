// Bandeja del sistema — port de setupTray() + el close handler de
// electron/main.js. Mismo criterio de fondo (ver CLAUDE.md, caso 02/08/2026):
// cerrar la ventana con la X tiene que verse, desde el usuario, como "cerrar
// el programa" — aunque el proceso siga vivo para no cortarle el servicio a
// otras PCs cliente — y reabrir SIEMPRE tiene que pedir login de nuevo, nunca
// dejar la sesión anterior intacta. Acá se resuelve todo en el mismo lugar
// (limpiar sesión + recargar ANTES de esconder la ventana), sin necesitar el
// flag de "un solo uso por arranque" que usa Electron (preload.js) — Tauri no
// tiene el problema de reabrir sin renavegar: la ventana nunca se destruye,
// pero cada `hide()` de acá ya deja la página en login antes de esconderse.
//
// Simplificación deliberada frente a Electron: sin diálogo de confirmación
// nativo antes de "Salir" (Electron usa dialog.showMessageBox; sumar eso acá
// requeriría tauri-plugin-dialog solo para este botón). Aceptable para el
// POC — si esto avanza a producción, agregar el plugin y el confirm.

use std::sync::atomic::{AtomicBool, Ordering};
use tauri::menu::{Menu, MenuItem};
use tauri::tray::TrayIconBuilder;
use tauri::{AppHandle, Manager};

/// Flag gestionado como estado de Tauri: true solo cuando "Salir" del menú
/// de bandeja se clickeó a propósito. El close handler de la ventana lo
/// consulta para distinguir "el usuario cerró con la X" (esconder + limpiar
/// sesión) de "el usuario eligió Salir" (dejar cerrar de verdad).
pub struct Quitting(pub AtomicBool);

const JS_LIMPIAR_SESION_Y_RECARGAR: &str =
    "try { localStorage.removeItem('logos_sesion'); } catch (e) {} location.reload();";

pub fn setup_tray(app: &AppHandle) -> tauri::Result<()> {
    app.manage(Quitting(AtomicBool::new(false)));

    let mostrar = MenuItem::with_id(app, "mostrar", "Mostrar Logos POS", true, None::<&str>)?;
    let salir = MenuItem::with_id(app, "salir", "Salir", true, None::<&str>)?;
    let menu = Menu::with_items(app, &[&mostrar, &salir])?;

    let icon = app
        .default_window_icon()
        .cloned()
        .expect("icono de la app no encontrado para la bandeja");

    TrayIconBuilder::new()
        .icon(icon)
        .tooltip("Logos POS")
        .menu(&menu)
        .show_menu_on_left_click(true)
        .on_menu_event(|app, event| match event.id.as_ref() {
            "mostrar" => {
                if let Some(win) = app.get_webview_window("main") {
                    let _ = win.show();
                    let _ = win.set_focus();
                }
            }
            "salir" => {
                if let Some(state) = app.try_state::<Quitting>() {
                    state.0.store(true, Ordering::SeqCst);
                }
                app.exit(0);
            }
            _ => {}
        })
        .build(app)?;

    // Cerrar con la X → esconder a la bandeja, no matar el proceso (PHP/
    // MariaDB tienen que seguir sirviendo a otras PCs cliente en modo
    // supervisor). Antes de esconder: limpiar sesión + recargar, igual que
    // Electron — ver comentario grande arriba del archivo.
    if let Some(win) = app.get_webview_window("main") {
        let app_handle = app.clone();
        win.on_window_event(move |event| {
            if let tauri::WindowEvent::CloseRequested { api, .. } = event {
                let quitting = app_handle
                    .try_state::<Quitting>()
                    .map(|s| s.0.load(Ordering::SeqCst))
                    .unwrap_or(false);
                if quitting {
                    return; // "Salir" de verdad — dejar cerrar
                }
                api.prevent_close();
                if let Some(win) = app_handle.get_webview_window("main") {
                    let _ = win.eval(JS_LIMPIAR_SESION_Y_RECARGAR);
                    let _ = win.hide();
                }
            }
        });
    }

    Ok(())
}
