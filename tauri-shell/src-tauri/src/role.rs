// Port a Rust del arranque en modo "supervisor" (servicios NSSM) de
// electron/main.js (startServerRole) + electron/services/server-manager.js
// (serviceMode).

use serde::Deserialize;
use std::path::PathBuf;
use std::process::Command;
use std::time::Duration;

pub const SERVICE_DB: &str = "LogosPOS-DB";
pub const SERVICE_PHP: &str = "LogosPOS-PHP";

pub fn data_root() -> PathBuf {
    PathBuf::from(r"C:\ProgramData\LogosPOS")
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ServiceMode {
    Supervisor,
    Initiator,
}

/// Igual que el getter `serviceMode` de server-manager.js: 'supervisor' si
/// el servicio NSSM del POC está registrado, 'initiator' si no.
pub fn detect_service_mode() -> ServiceMode {
    #[cfg(windows)]
    {
        use std::os::windows::process::CommandExt;
        const CREATE_NO_WINDOW: u32 = 0x0800_0000;
        let ok = Command::new("sc")
            .args(["query", SERVICE_DB])
            .creation_flags(CREATE_NO_WINDOW)
            .stdout(std::process::Stdio::null())
            .stderr(std::process::Stdio::null())
            .status()
            .map(|s| s.success())
            .unwrap_or(false);
        if ok { ServiceMode::Supervisor } else { ServiceMode::Initiator }
    }
    #[cfg(not(windows))]
    {
        ServiceMode::Initiator
    }
}

#[derive(Deserialize, Debug, Clone)]
pub struct LogosConfig {
    pub role: String,
    #[serde(rename = "serverPort")]
    pub server_port: u16,
    #[serde(rename = "dbPort")]
    pub db_port: u16,
    #[serde(rename = "serverIp")]
    pub server_ip: Option<String>,
}

/// Lee C:\ProgramData\LogosPOS\logos-config.json, escrito por
/// setup-server.ps1 (rol Servidor) o por el hook NSIS (rol Cliente).
pub fn read_config() -> Option<LogosConfig> {
    let path = data_root().join("logos-config.json");
    let content = std::fs::read_to_string(path).ok()?;
    serde_json::from_str(&content).ok()
}

#[derive(Deserialize, Debug, Default)]
pub struct InstallState {
    #[serde(default)]
    pub sin_conexion: bool,
    #[serde(default)]
    pub requiere_conexion: bool,
    #[serde(default)]
    pub requiere_schema: bool,
    #[serde(default)]
    pub requiere_admin: bool,
    #[serde(default)]
    pub requiere_negocio: bool,
    #[serde(default)]
    pub requiere_caja: bool,
}

impl InstallState {
    pub fn needs_setup(&self) -> bool {
        self.requiere_conexion
            || self.requiere_schema
            || self.requiere_admin
            || self.requiere_negocio
            || self.requiere_caja
    }
}

/// Port fiel de checkInstallState() en server-manager.js: poll a
/// /Logos/api/instalacion/estado, reintenta cada 3s mientras la respuesta
/// diga sin_conexion, hasta max_retries. None si se agotan los reintentos
/// sin conexión — Some(estado) en cualquier otro caso, aunque estado diga
/// que hace falta setup (esa decisión la toma quien llama, no esta función).
pub async fn check_install_state(base_url: &str, max_retries: u32) -> Option<InstallState> {
    let client = reqwest::Client::new();
    let url = format!("{base_url}/Logos/api/instalacion/estado");
    let mut attempt = 0;
    loop {
        let resp = tokio::time::timeout(Duration::from_secs(8), client.get(&url).send()).await;
        match resp {
            Ok(Ok(r)) => match r.json::<InstallState>().await {
                Ok(estado) => {
                    if estado.sin_conexion {
                        attempt += 1;
                        if attempt >= max_retries { return None; }
                        tokio::time::sleep(Duration::from_secs(3)).await;
                        continue;
                    }
                    return Some(estado);
                }
                Err(_) => return None,
            },
            _ => {
                attempt += 1;
                if attempt >= max_retries { return None; }
                tokio::time::sleep(Duration::from_secs(3)).await;
            }
        }
    }
}

/// Espera a que una URL responda (GET, cualquier status < 500 cuenta como
/// "arriba") — usado para /Logos/ping. Igual que waitForUrl() en main.js.
pub async fn wait_for_url(url: &str, timeout: Duration) -> Result<(), String> {
    let client = reqwest::Client::new();
    let deadline = tokio::time::Instant::now() + timeout;
    loop {
        let ok = matches!(
            tokio::time::timeout(Duration::from_secs(3), client.get(url).send()).await,
            Ok(Ok(resp)) if resp.status().as_u16() < 500
        );
        if ok { return Ok(()); }
        if tokio::time::Instant::now() >= deadline {
            return Err(format!("{url} no respondió a tiempo"));
        }
        tokio::time::sleep(Duration::from_millis(600)).await;
    }
}

/// Igual que el paso previo a quitAndInstall() en electron/main.js: en modo
/// supervisor, el instalador silencioso que dispara download_and_install()
/// no puede sobreescribir mysqld.exe/php.exe (ni sus DLLs, ej. msvcp140.dll)
/// mientras los servicios NSSM siguen corriendo y los tienen abiertos —
/// falla con "Error opening file for writing". Parar acá antes de instalar;
/// NSIS_HOOK_POSTINSTALL ya los vuelve a arrancar (rama ${Silent}) una vez
/// que termina. Best-effort — si algo falla, la instalación probablemente
/// falle también, pero no hay nada mejor que intentar antes.
#[cfg(windows)]
pub fn stop_supervisor_services() {
    use std::os::windows::process::CommandExt;
    const CREATE_NO_WINDOW: u32 = 0x0800_0000;
    for svc in [SERVICE_PHP, SERVICE_DB] {
        let _ = Command::new("sc")
            .args(["stop", svc])
            .creation_flags(CREATE_NO_WINDOW)
            .stdout(std::process::Stdio::null())
            .stderr(std::process::Stdio::null())
            .status();
    }
    // Dar tiempo real a que los procesos liberen sus DLLs — "sc stop"
    // devuelve el control apenas pide la parada, no cuando el proceso
    // efectivamente termina.
    std::thread::sleep(std::time::Duration::from_secs(3));
}
#[cfg(not(windows))]
pub fn stop_supervisor_services() {}

/// Igual que el patrón defensivo de startServerRole() en main.js: un falso
/// positivo transitorio de "hace falta setup" en modo supervisor puede
/// mandar una instalación ya funcionando de vuelta al wizard, dejando al
/// usuario atrapado (incidente real, ver CLAUDE.md 01/08/2026). Antes de
/// confiar en un primer "true", se espera 4s y se vuelve a chequear — recién
/// si el segundo chequeo también dice que hace falta setup, se procede al
/// wizard. Devuelve (necesita_setup, InstallState final usado para decidir).
pub async fn decide_needs_setup_with_recheck(base_url: &str) -> (bool, Option<InstallState>) {
    let first = check_install_state(base_url, 5).await;
    let first_needs = first.as_ref().map(InstallState::needs_setup).unwrap_or(true);
    if !first_needs {
        return (false, first);
    }
    println!("[role] ALERTA primer chequeo de instalación dice que hace falta setup — reconfirmando en 4s...");
    tokio::time::sleep(Duration::from_secs(4)).await;
    let second = check_install_state(base_url, 3).await;
    let second_needs = second.as_ref().map(InstallState::needs_setup).unwrap_or(true);
    println!("[role] segundo chequeo needs_setup={second_needs}");
    if second_needs { (true, second) } else { (false, second) }
}
