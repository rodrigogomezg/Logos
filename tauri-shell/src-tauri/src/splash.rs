// Splash nativo de Win32 mostrado mientras la app arranca sus servicios (DB +
// PHP) y todavía no hay ninguna ventana de Tauri visible — sin esto, un
// cliente real ve la pantalla vacía varios segundos y puede pensar que el
// programa se colgó (hallazgo real, ver plan Fase 3.1 / memoria
// project_migracion_sveltekit_tauri).
//
// Tiene que ser una ventana Win32 CRUDA, no un WebviewWindow de Tauri: el
// motivo de que manager.start_database() corra ANTES de tauri::Builder en
// lib.rs::run() es evitar un crash real (0xc0000005) de php.exe al
// spawnearlo desde un proceso con WebView2 ya inicializado — crear un
// WebviewWindow acá, aunque sea solo para un splash, reintroduciría
// exactamente ese riesgo. Mismo patrón ya usado en show_error_box() (lib.rs):
// llamadas `unsafe extern "system"` directas a la API de Windows, sin sumar
// ninguna dependencia nueva (winapi/windows-rs) solo para esto.
//
// Transparencia real (no un BMP plano con fondo blanco, ver feedback real de
// Rodrigo): ventana "layered" (WS_EX_LAYERED) compuesta vía
// UpdateLayeredWindow con un DIB de 32bpp con alfa premultiplicado — el
// mismo mecanismo que usa Windows para íconos/cursores con transparencia
// real. El píxel data sale de splash_argb.bin (generado con PHP+GD desde
// logos_logo.png, ver nsis/assets/ — no hay decodificador de PNG acá, se
// evita sumar una dependencia como el crate `image` solo para esto).
//
// Corre en un thread propio con su loop de mensajes real (GetMessageW) —
// necesario para que Windows no lo marque "Sin respuesta" mientras el hilo
// principal hace el trabajo bloqueante de arrancar mysqld/php. Se cierra
// posteando WM_CLOSE desde el hilo principal. Posición fija (centrada,
// calculada una sola vez al crear la ventana) — nunca se mueve ni se
// redimensiona después de UpdateLayeredWindow.

use std::ffi::{c_void, OsStr};
use std::iter::once;
use std::os::windows::ffi::OsStrExt;
use std::path::Path;
use std::sync::mpsc;

type Hwnd = isize;
type Hdc = isize;
type Hbitmap = isize;

const WS_POPUP: u32 = 0x8000_0000;
const WS_EX_TOPMOST: u32 = 0x0000_0008;
const WS_EX_LAYERED: u32 = 0x0008_0000;
const WS_EX_TOOLWINDOW: u32 = 0x0000_0080; // sin ícono en la barra de tareas
const SW_SHOW: i32 = 5;
const SM_CXSCREEN: i32 = 0;
const SM_CYSCREEN: i32 = 1;
const WM_CLOSE: u32 = 0x0010;
const DIB_RGB_COLORS: u32 = 0;
const BI_RGB: u32 = 0;
const ULW_ALPHA: u32 = 0x0000_0002;
const AC_SRC_OVER: u8 = 0x00;
const AC_SRC_ALPHA: u8 = 0x01;

#[repr(C)]
struct Msg {
    hwnd: Hwnd,
    message: u32,
    wparam: usize,
    lparam: isize,
    time: u32,
    pt_x: i32,
    pt_y: i32,
}

#[repr(C)]
struct PointW {
    x: i32,
    y: i32,
}

#[repr(C)]
struct SizeW {
    cx: i32,
    cy: i32,
}

#[repr(C)]
struct BlendFunction {
    blend_op: u8,
    blend_flags: u8,
    source_constant_alpha: u8,
    alpha_format: u8,
}

#[repr(C)]
struct BitmapInfoHeader {
    bi_size: u32,
    bi_width: i32,
    bi_height: i32,
    bi_planes: u16,
    bi_bit_count: u16,
    bi_compression: u32,
    bi_size_image: u32,
    bi_x_pels_per_meter: i32,
    bi_y_pels_per_meter: i32,
    bi_clr_used: u32,
    bi_clr_important: u32,
}

#[repr(C)]
struct BitmapInfo {
    header: BitmapInfoHeader,
    // Sin paleta de colores — bi_bit_count=32 no la necesita, pero el layout
    // de BITMAPINFO en la API de Windows exige el campo igual (queda vacío).
    colors: [u32; 1],
}

#[link(name = "user32")]
unsafe extern "system" {
    fn CreateWindowExW(
        ex_style: u32,
        class_name: *const u16,
        window_name: *const u16,
        style: u32,
        x: i32,
        y: i32,
        width: i32,
        height: i32,
        parent: Hwnd,
        menu: isize,
        instance: isize,
        param: *const c_void,
    ) -> Hwnd;
    fn ShowWindow(hwnd: Hwnd, cmd: i32) -> i32;
    fn DestroyWindow(hwnd: Hwnd) -> i32;
    fn PostMessageW(hwnd: Hwnd, msg: u32, wparam: usize, lparam: isize) -> i32;
    fn GetMessageW(msg: *mut Msg, hwnd: Hwnd, min: u32, max: u32) -> i32;
    fn TranslateMessage(msg: *const Msg) -> i32;
    fn DispatchMessageW(msg: *const Msg) -> isize;
    fn PostQuitMessage(exit_code: i32);
    fn GetSystemMetrics(index: i32) -> i32;
    fn GetDC(hwnd: Hwnd) -> Hdc;
    fn ReleaseDC(hwnd: Hwnd, hdc: Hdc) -> i32;
    fn UpdateLayeredWindow(
        hwnd: Hwnd,
        hdc_dst: Hdc,
        pt_dst: *const PointW,
        size: *const SizeW,
        hdc_src: Hdc,
        pt_src: *const PointW,
        cr_key: u32,
        blend: *const BlendFunction,
        flags: u32,
    ) -> i32;
}

#[link(name = "gdi32")]
unsafe extern "system" {
    fn CreateCompatibleDC(hdc: Hdc) -> Hdc;
    fn DeleteDC(hdc: Hdc) -> i32;
    fn CreateDIBSection(
        hdc: Hdc,
        info: *const BitmapInfo,
        usage: u32,
        bits: *mut *mut c_void,
        section: isize,
        offset: u32,
    ) -> Hbitmap;
    fn SelectObject(hdc: Hdc, obj: Hbitmap) -> Hbitmap;
    fn DeleteObject(obj: Hbitmap) -> i32;
}

fn wide(s: &str) -> Vec<u16> {
    OsStr::new(s).encode_wide().chain(once(0)).collect()
}

/// Lee splash_argb.bin: header de 8 bytes (width, height, u32 LE) + píxeles
/// BGRA premultiplicados. Ver nsis/assets/ (generado con PHP+GD) para el
/// formato exacto de escritura.
fn load_argb(path: &Path) -> Option<(i32, i32, Vec<u8>)> {
    let bytes = std::fs::read(path).ok()?;
    if bytes.len() < 8 { return None; }
    let width = u32::from_le_bytes(bytes[0..4].try_into().ok()?) as i32;
    let height = u32::from_le_bytes(bytes[4..8].try_into().ok()?) as i32;
    let expected = 8 + (width as usize) * (height as usize) * 4;
    if bytes.len() < expected { return None; }
    Some((width, height, bytes[8..expected].to_vec()))
}

pub struct Splash {
    hwnd: Hwnd,
    thread: Option<std::thread::JoinHandle<()>>,
}

impl Splash {
    /// Best-effort: si algo falla (archivo no encontrado, API rara), no
    /// bloquea el arranque de la app — devuelve None y listo, el usuario
    /// simplemente no ve el splash, pero la app arranca igual.
    pub fn show(argb_path: &Path) -> Option<Splash> {
        let (w, h, pixels) = match load_argb(argb_path) {
            Some(v) => v,
            None => {
                println!("[splash] no se pudo leer {}, se omite", argb_path.display());
                return None;
            }
        };

        let (tx, rx) = mpsc::channel::<Hwnd>();

        let thread = std::thread::spawn(move || {
            let class_name = wide("STATIC");
            let window_name = wide("Logos POS");

            unsafe {
                let screen_w = GetSystemMetrics(SM_CXSCREEN);
                let screen_h = GetSystemMetrics(SM_CYSCREEN);
                let x = (screen_w - w) / 2;
                let y = (screen_h - h) / 2;

                let hwnd = CreateWindowExW(
                    WS_EX_LAYERED | WS_EX_TOPMOST | WS_EX_TOOLWINDOW,
                    class_name.as_ptr(),
                    window_name.as_ptr(),
                    WS_POPUP,
                    x,
                    y,
                    w,
                    h,
                    0,
                    0,
                    0,
                    std::ptr::null(),
                );
                if hwnd == 0 {
                    println!("[splash] CreateWindowExW falló, se omite");
                    let _ = tx.send(0);
                    return;
                }

                // DIB de 32bpp top-down (altura negativa) — coincide con el
                // orden fila-por-fila en que se escribió splash_argb.bin.
                let bmi = BitmapInfo {
                    header: BitmapInfoHeader {
                        bi_size: std::mem::size_of::<BitmapInfoHeader>() as u32,
                        bi_width: w,
                        bi_height: -h,
                        bi_planes: 1,
                        bi_bit_count: 32,
                        bi_compression: BI_RGB,
                        bi_size_image: 0,
                        bi_x_pels_per_meter: 0,
                        bi_y_pels_per_meter: 0,
                        bi_clr_used: 0,
                        bi_clr_important: 0,
                    },
                    colors: [0],
                };

                let screen_dc = GetDC(0);
                let mem_dc = CreateCompatibleDC(screen_dc);
                let mut bits_ptr: *mut c_void = std::ptr::null_mut();
                let dib = CreateDIBSection(screen_dc, &bmi, DIB_RGB_COLORS, &mut bits_ptr, 0, 0);
                if dib == 0 || bits_ptr.is_null() {
                    println!("[splash] CreateDIBSection falló, se omite");
                    ReleaseDC(0, screen_dc);
                    DeleteDC(mem_dc);
                    DestroyWindow(hwnd);
                    let _ = tx.send(0);
                    return;
                }
                std::ptr::copy_nonoverlapping(pixels.as_ptr(), bits_ptr as *mut u8, pixels.len());

                let old_bmp = SelectObject(mem_dc, dib);

                let pt_src = PointW { x: 0, y: 0 };
                let pt_dst = PointW { x, y };
                let size = SizeW { cx: w, cy: h };
                let blend = BlendFunction {
                    blend_op: AC_SRC_OVER,
                    blend_flags: 0,
                    source_constant_alpha: 255,
                    alpha_format: AC_SRC_ALPHA,
                };

                ShowWindow(hwnd, SW_SHOW);
                UpdateLayeredWindow(
                    hwnd,
                    screen_dc,
                    &pt_dst,
                    &size,
                    mem_dc,
                    &pt_src,
                    0,
                    &blend,
                    ULW_ALPHA,
                );

                // El DIB ya está copiado a la ventana por UpdateLayeredWindow
                // — se puede liberar el recurso GDI de inmediato.
                SelectObject(mem_dc, old_bmp);
                DeleteObject(dib);
                DeleteDC(mem_dc);
                ReleaseDC(0, screen_dc);

                let _ = tx.send(hwnd);

                // Loop de mensajes real — mantiene la ventana respondiendo
                // mientras el hilo principal arranca mysqld/php en paralelo.
                // Nunca se vuelve a llamar UpdateLayeredWindow ni se mueve la
                // ventana — queda fija en (x, y) hasta que se cierra.
                let mut msg: Msg = std::mem::zeroed();
                loop {
                    let ret = GetMessageW(&mut msg, 0, 0, 0);
                    if ret <= 0 { break; }
                    if msg.message == WM_CLOSE {
                        DestroyWindow(hwnd);
                        PostQuitMessage(0);
                        continue;
                    }
                    TranslateMessage(&msg);
                    DispatchMessageW(&msg);
                }
            }
        });

        match rx.recv_timeout(std::time::Duration::from_secs(3)) {
            Ok(hwnd) if hwnd != 0 => Some(Splash { hwnd, thread: Some(thread) }),
            _ => None,
        }
    }

    pub fn close(mut self) {
        unsafe {
            PostMessageW(self.hwnd, WM_CLOSE, 0, 0);
        }
        if let Some(t) = self.thread.take() {
            let _ = t.join();
        }
    }
}
