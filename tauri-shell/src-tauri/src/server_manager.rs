// Port a Rust de electron/services/server-manager.js. Mismo orden de pasos
// (init datadir si hace falta → spawn mysqld → esperar ping → schema/
// migraciones → spawn php -S), mismos binarios portables. Modo "initiator"
// (Tauri dueño de los procesos, como Electron en dev) — el modo "supervisor"
// (NSSM) se maneja en role.rs.

use std::fs;
use std::io::Write;
use std::net::TcpListener;
use std::path::{Path, PathBuf};
use std::process::{Child, Command, Stdio};
use std::time::{Duration, Instant};

#[cfg(windows)]
const CREATE_NO_WINDOW: u32 = 0x0800_0000;

// Caso real (ver conversación con Rodrigo, 2da PC): un crash del proceso
// principal (ej. panic por config inválida) mataba la app pero NO a los
// hijos que ya había spawneado (mysqld.exe, php.exe) — quedaban huérfanos
// corriendo en segundo plano, con archivos bloqueados, y la siguiente
// reinstalación fallaba con "Error opening file for writing" porque el
// instalador no podía sobreescribir un DLL que un mysqld.exe zombie todavía
// tenía abierto. stop_all() solo corre en un cierre normal (RunEvent::
// ExitRequested) — no ayuda si el proceso muere de otra forma.
//
// Fix real, no un parche: un Job Object de Windows con
// JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE. Windows cierra automáticamente el
// handle del Job cuando el proceso dueño termina, sin importar cómo (panic,
// crash, Task Manager, lo que sea) — y ese cierre mata a todos los procesos
// asignados al Job. Se guarda como isize (no como puntero crudo) para que
// ServerManager siga siendo Send/Sync sin unsafe impl — un isize es
// trivialmente Send+Sync, un *mut c_void no.
#[cfg(windows)]
mod job_object {
    use std::os::windows::io::AsRawHandle;

    const JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE: u32 = 0x2000;
    const JOB_OBJECT_EXTENDED_LIMIT_INFORMATION: u32 = 9;

    #[repr(C)]
    struct JobObjectBasicLimitInformation {
        per_process_user_time_limit: i64,
        per_job_user_time_limit: i64,
        limit_flags: u32,
        minimum_working_set_size: usize,
        maximum_working_set_size: usize,
        active_process_limit: u32,
        affinity: usize,
        priority_class: u32,
        scheduling_class: u32,
    }

    #[repr(C)]
    struct IoCounters {
        read_operation_count: u64,
        write_operation_count: u64,
        other_operation_count: u64,
        read_transfer_count: u64,
        write_transfer_count: u64,
        other_transfer_count: u64,
    }

    #[repr(C)]
    struct JobObjectExtendedLimitInformation {
        basic_limit_information: JobObjectBasicLimitInformation,
        io_info: IoCounters,
        process_memory_limit: usize,
        job_memory_limit: usize,
        peak_process_memory_used: usize,
        peak_job_memory_used: usize,
    }

    unsafe extern "system" {
        fn CreateJobObjectW(attrs: *const core::ffi::c_void, name: *const u16) -> isize;
        fn SetInformationJobObject(job: isize, class: u32, info: *const core::ffi::c_void, len: u32) -> i32;
        fn AssignProcessToJobObject(job: isize, process: isize) -> i32;
    }

    /// Crea el Job "kill on close" para esta sesión de la app. None si algo
    /// falla (no bloquea el arranque por esto — solo perdemos la garantía
    /// extra de limpieza, no es un fallo fatal).
    pub fn create() -> Option<isize> {
        unsafe {
            let job = CreateJobObjectW(std::ptr::null(), std::ptr::null());
            if job == 0 {
                return None;
            }
            let mut info: JobObjectExtendedLimitInformation = std::mem::zeroed();
            info.basic_limit_information.limit_flags = JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;
            let ok = SetInformationJobObject(
                job,
                JOB_OBJECT_EXTENDED_LIMIT_INFORMATION,
                &info as *const _ as *const core::ffi::c_void,
                std::mem::size_of::<JobObjectExtendedLimitInformation>() as u32,
            );
            if ok == 0 {
                return None;
            }
            Some(job)
        }
    }

    pub fn assign(job: isize, child: &std::process::Child) {
        unsafe {
            AssignProcessToJobObject(job, child.as_raw_handle() as isize);
        }
    }
}

pub struct ServerManager {
    resources_path: PathBuf,
    www_path: PathBuf,
    app_dir: PathBuf,
    app_build_dir: PathBuf,
    data_dir: PathBuf,
    db_port: u16,
    php_proc: Option<Child>,
    db_proc: Option<Child>,
    // true en modo supervisor (servicios NSSM) — stop_all() no debe matar
    // procesos que no arrancó, igual que stopAll() en server-manager.js.
    supervisor: bool,
    #[cfg(windows)]
    job: Option<isize>,
}

#[derive(Debug)]
pub struct StartResult {
    pub db_port: u16,
    pub php_port: u16,
    pub is_first_setup: bool,
}

impl ServerManager {
    pub fn new(resources_path: PathBuf, www_path: PathBuf, app_dir: PathBuf, app_build_dir: PathBuf, data_dir: PathBuf) -> Self {
        Self {
            resources_path,
            www_path,
            app_dir,
            app_build_dir,
            data_dir,
            db_port: 3306,
            php_proc: None,
            db_proc: None,
            supervisor: false,
            #[cfg(windows)]
            job: job_object::create(),
        }
    }

    /// Modo supervisor: los procesos ya corren como servicios NSSM, este
    /// ServerManager no arranca ni mata nada — solo se usa para chequear que
    /// la DB responde (wait_for_database) y como estado gestionado por Tauri.
    pub fn mark_supervisor(&mut self, db_port: u16) {
        self.supervisor = true;
        self.db_port = db_port;
    }

    /// Igual que waitForDatabase() en server-manager.js: solo verifica que
    /// responda, no arranca nada (los servicios NSSM ya están corriendo).
    pub fn wait_for_database(&self, timeout: Duration) -> Result<(), String> {
        self.wait_for_mysql(timeout)
    }

    #[cfg(windows)]
    fn bind_to_job(&self, child: &Child) {
        if let Some(job) = self.job {
            job_object::assign(job, child);
        }
    }
    #[cfg(not(windows))]
    fn bind_to_job(&self, _child: &Child) {}

    fn mysqld_exe(&self) -> PathBuf { self.resources_path.join("mariadb/bin/mysqld.exe") }
    fn mysqladmin_exe(&self) -> PathBuf { self.resources_path.join("mariadb/bin/mysqladmin.exe") }
    fn mysql_exe(&self) -> PathBuf { self.resources_path.join("mariadb/bin/mysql.exe") }
    fn mysql_install_db_exe(&self) -> PathBuf { self.resources_path.join("mariadb/bin/mysql_install_db.exe") }
    fn php_exe(&self) -> PathBuf { self.resources_path.join("php/php.exe") }
    fn php_ini(&self) -> PathBuf { self.resources_path.join("php/php.ini") }

    fn check_binaries(&self) -> Result<(), String> {
        let mut missing_paths = Vec::new();
        if !self.mysqld_exe().exists() { missing_paths.push(self.mysqld_exe()); }
        if !self.php_exe().exists() { missing_paths.push(self.php_exe()); }
        if missing_paths.is_empty() {
            Ok(())
        } else {
            Err(format!(
                "Binarios portables no encontrados:\n{}",
                missing_paths
                    .iter()
                    .map(|p| p.display().to_string())
                    .collect::<Vec<_>>()
                    .join("\n")
            ))
        }
    }

    fn command_no_window(exe: &Path) -> Command {
        let mut cmd = Command::new(exe);
        #[cfg(windows)]
        {
            use std::os::windows::process::CommandExt;
            cmd.creation_flags(CREATE_NO_WINDOW);
        }
        cmd
    }

    pub fn start_database(&mut self) -> Result<StartResult, String> {
        self.check_binaries()?;

        let is_new = !self.data_dir.join("mysql").exists();
        if is_new {
            fs::create_dir_all(&self.data_dir).map_err(|e| e.to_string())?;
            self.init_data_dir()?;
        }

        self.db_port = find_free_port(3306)?;

        let mariadb_base = self.mysqld_exe().parent().unwrap().parent().unwrap().to_path_buf();
        let bin_dir = self.mysqld_exe().parent().unwrap().to_path_buf();

        let child = Self::command_no_window(&self.mysqld_exe())
            .arg("--no-defaults")
            .arg(format!("--basedir={}", mariadb_base.display()))
            .arg(format!("--datadir={}", self.data_dir.display()))
            .arg(format!("--port={}", self.db_port))
            .arg("--bind-address=127.0.0.1")
            .arg("--skip-networking=OFF")
            .current_dir(&bin_dir)
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .spawn()
            .map_err(|e| format!("No se pudo iniciar mysqld: {e}"))?;
        self.bind_to_job(&child);
        self.db_proc = Some(child);

        self.wait_for_mysql(Duration::from_secs(30))?;

        if is_new {
            self.run_schema()?;
        }
        self.run_migrations();

        let php_port = self.start_php_server(8080)?;

        Ok(StartResult { db_port: self.db_port, php_port, is_first_setup: is_new })
    }

    fn init_data_dir(&self) -> Result<(), String> {
        // mysql_install_db.exe exige un datadir COMPLETAMENTE vacío, no solo
        // que falte la carpeta "mysql" — un intento anterior fallido (ej.
        // instalación interrumpida, o esta misma corrida reintentando tras un
        // fallo de setup-server-poc.ps1) puede dejar restos sueltos. Mismo
        // bug/fix ya aplicado en electron/resources/setup-server.ps1 y en
        // setup-server-poc.ps1 (ver CLAUDE.md, caso real 02/08/2026) — acá
        // faltaba portarlo al lado Rust ("modo initiator"). Se limpia acá,
        // justo antes de inicializar, para autocorregir cualquier resto sin
        // importar de dónde vino.
        if let Ok(entries) = fs::read_dir(&self.data_dir) {
            for entry in entries.flatten() {
                let path = entry.path();
                let _ = if path.is_dir() { fs::remove_dir_all(&path) } else { fs::remove_file(&path) };
            }
        }

        let install_db = self.mysql_install_db_exe();
        let bin_dir = self.mysqld_exe().parent().unwrap().to_path_buf();
        let mariadb_base = bin_dir.parent().unwrap().to_path_buf();

        let output = if install_db.exists() {
            Self::command_no_window(&install_db)
                .arg(format!("--datadir={}", self.data_dir.display()))
                .arg("--password=")
                .current_dir(&bin_dir)
                .output()
        } else {
            Self::command_no_window(&self.mysqld_exe())
                .arg("--no-defaults")
                .arg(format!("--basedir={}", mariadb_base.display()))
                .arg(format!("--datadir={}", self.data_dir.display()))
                .arg("--initialize-insecure")
                .current_dir(&bin_dir)
                .output()
        }
        .map_err(|e| e.to_string())?;

        if !output.status.success() {
            return Err(format!(
                "MariaDB init falló:\n{}",
                String::from_utf8_lossy(&output.stderr)
            ));
        }
        Ok(())
    }

    fn wait_for_mysql(&self, timeout: Duration) -> Result<(), String> {
        let deadline = Instant::now() + timeout;
        std::thread::sleep(Duration::from_secs(2));
        loop {
            let ok = Self::command_no_window(&self.mysqladmin_exe())
                .args(["--no-defaults", "-h", "127.0.0.1", "-P"])
                .arg(self.db_port.to_string())
                .args(["-u", "root", "--connect-timeout=2", "ping"])
                .output()
                .map(|o| o.status.success())
                .unwrap_or(false);
            if ok { return Ok(()); }
            if Instant::now() >= deadline {
                return Err("MariaDB no respondió en el tiempo esperado".into());
            }
            std::thread::sleep(Duration::from_millis(1200));
        }
    }

    fn run_schema(&self) -> Result<(), String> {
        let schema_path = self.app_dir.join("install").join("schema_limpio.sql");
        let schema = fs::read_to_string(&schema_path)
            .map_err(|e| format!("Schema no encontrado ({}): {e}", schema_path.display()))?;

        self.mysql_exec_no_db(&[
            "-e",
            "CREATE DATABASE IF NOT EXISTS logos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
        ])?;

        self.mysql_pipe_input("logos", &schema)?;
        Ok(())
    }

    fn run_migrations(&self) {
        let migrate_dir = self.app_dir.join("migrate");
        if !migrate_dir.is_dir() { return; }

        let _ = self.mysql_exec("logos", &["-e", "
            CREATE TABLE IF NOT EXISTS _schema_migrations (
                nombre VARCHAR(255) PRIMARY KEY,
                aplicado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB;
        "]);

        let mut files: Vec<String> = fs::read_dir(&migrate_dir)
            .into_iter()
            .flatten()
            .filter_map(|e| e.ok())
            .map(|e| e.file_name().to_string_lossy().to_string())
            .filter(|n| n.to_lowercase().ends_with(".sql"))
            .collect();
        files.sort();

        let ran: std::collections::HashSet<String> = self
            .mysql_exec("logos", &["--skip-column-names", "-e", "SELECT nombre FROM _schema_migrations ORDER BY nombre;"])
            .map(|out| out.lines().map(|l| l.trim().to_string()).filter(|l| !l.is_empty()).collect())
            .unwrap_or_default();

        let mut applied = 0;
        for file in files {
            if ran.contains(&file) { continue; }
            let sql = match fs::read_to_string(migrate_dir.join(&file)) {
                Ok(s) => s,
                Err(_) => continue,
            };
            match self.mysql_pipe_input("logos", &sql) {
                Ok(_) => println!("[migrations] OK {file}"),
                Err(e) => println!("[migrations] fallo (sellada de todos modos) {file}: {e}"),
            }
            let _ = self.mysql_exec(
                "logos",
                &["-e", &format!("INSERT IGNORE INTO _schema_migrations (nombre) VALUES ('{}');", file.replace('\'', "''"))],
            );
            applied += 1;
        }
        if applied > 0 {
            println!("[migrations] {applied} migración(es) aplicada(s).");
        }
    }

    fn mysql_exec(&self, db: &str, args: &[&str]) -> Result<String, String> {
        let out = Self::command_no_window(&self.mysql_exe())
            .args(["--no-defaults", "-h", "127.0.0.1", "-P"])
            .arg(self.db_port.to_string())
            .args(["-u", "root", db])
            .args(args)
            .output()
            .map_err(|e| e.to_string())?;
        if !out.status.success() {
            return Err(String::from_utf8_lossy(&out.stderr).to_string());
        }
        Ok(String::from_utf8_lossy(&out.stdout).to_string())
    }

    fn mysql_exec_no_db(&self, args: &[&str]) -> Result<String, String> {
        let out = Self::command_no_window(&self.mysql_exe())
            .args(["--no-defaults", "-h", "127.0.0.1", "-P"])
            .arg(self.db_port.to_string())
            .args(["-u", "root"])
            .args(args)
            .output()
            .map_err(|e| e.to_string())?;
        if !out.status.success() {
            return Err(String::from_utf8_lossy(&out.stderr).to_string());
        }
        Ok(String::from_utf8_lossy(&out.stdout).to_string())
    }

    fn mysql_pipe_input(&self, db: &str, sql: &str) -> Result<(), String> {
        let mut child = Self::command_no_window(&self.mysql_exe())
            .args(["--no-defaults", "-h", "127.0.0.1", "-P"])
            .arg(self.db_port.to_string())
            .args(["-u", "root", db])
            .stdin(Stdio::piped())
            .stdout(Stdio::null())
            .stderr(Stdio::piped())
            .spawn()
            .map_err(|e| e.to_string())?;

        child
            .stdin
            .take()
            .unwrap()
            .write_all(sql.as_bytes())
            .map_err(|e| e.to_string())?;

        let out = child.wait_with_output().map_err(|e| e.to_string())?;
        if !out.status.success() {
            return Err(String::from_utf8_lossy(&out.stderr).to_string());
        }
        Ok(())
    }

    fn start_php_server(&mut self, preferred_port: u16) -> Result<u16, String> {
        // Deliberadamente NO se llama a write_db_local() en este spike: ese
        // archivo es compartido con la instalación real de Electron/XAMPP que
        // puede estar corriendo en paralelo en la misma máquina de pruebas
        // (ver [[project_migracion_sveltekit_tauri]] / CLAUDE.md) — pisarlo
        // con el datadir/puerto propios de este POC rompería esa instalación.
        // La conexión igual funciona porque el bloque de abajo pasa
        // LOGOS_DB_* por variables de entorno, que DB::get() prioriza sobre
        // db.local.php en runtime (mismo comentario que en el original JS).
        let port = find_free_port(preferred_port)?;
        let router = self.www_path.join("router.php");
        let mariadb_bin = self.mysqld_exe().parent().unwrap().to_path_buf();
        let php_bin = self.php_exe().parent().unwrap().to_path_buf();

        let path_var = format!(
            "{};{};{}",
            mariadb_bin.display(),
            php_bin.display(),
            std::env::var("PATH").unwrap_or_default()
        );

        // Caso real (ver conversación con Rodrigo, 2da PC): con
        // Stdio::piped() en stderr, PHP moría con 0xc0000005 (access
        // violation) apenas arrancaba en -S — pero el mismo binario, mismo
        // php.ini, mismo comando -S, corrido a mano desde una consola
        // normal, andaba perfecto. Se aisló a la combinación específica
        // CREATE_NO_WINDOW + stderr como pipe anónimo (mysqld usa
        // CREATE_NO_WINDOW con stdout/stderr ambos en null, y a ese nunca le
        // pasó nada — la única asimetría era el pipe de stderr acá). En vez
        // de perseguir la causa exacta de ese caso límite de Windows, se
        // saca el pipe: stderr va a un archivo de verdad, no a un pipe
        // anónimo — se mantiene el diagnóstico sin tocar lo que causaba el
        // crash.
        let stderr_log_path = PathBuf::from(r"C:\ProgramData\LogosPOS\logs\php-stderr.log");
        if let Some(dir) = stderr_log_path.parent() {
            let _ = fs::create_dir_all(dir);
        }
        let stderr_file = fs::File::create(&stderr_log_path).map_err(|e| e.to_string())?;

        let mut child = Self::command_no_window(&self.php_exe())
            .arg("-c")
            .arg(self.php_ini())
            .arg("-S")
            .arg(format!("0.0.0.0:{port}"))
            .arg("-t")
            .arg(&self.www_path)
            .arg(&router)
            .current_dir(&self.www_path)
            .env("PATH", path_var)
            .env("LOGOS_APP_DIR", &self.app_dir)
            .env("LOGOS_APP_BUILD_DIR", &self.app_build_dir)
            // La app PHP compartida (api/) tiene varias rutas de
            // C:\ProgramData\LogosPOS hardcodeadas por default (db.local.php,
            // logo, carpeta de backups, lock de AFIP) — ver DB::dataRoot()
            // en api/config/db.php. Coincide con el default, pero se pasa
            // explícito para no depender de que nadie cambie ese default sin
            // darse cuenta.
            .env("LOGOS_DATA_ROOT", r"C:\ProgramData\LogosPOS")
            .env("LOGOS_DB_HOST", "127.0.0.1")
            .env("LOGOS_DB_PORT", self.db_port.to_string())
            .env("LOGOS_DB_NAME", "logos")
            .env("LOGOS_DB_USER", "root")
            .env("LOGOS_DB_PASS", "")
            .env("PHPRC", php_bin)
            .stdout(Stdio::null())
            .stderr(Stdio::from(stderr_file))
            .spawn()
            .map_err(|e| format!("No se pudo iniciar PHP: {e}"))?;
        self.bind_to_job(&child);

        // Caso real (ver conversación con Rodrigo, 2da PC): spawn() solo
        // confirma que el proceso arrancó, no que ya está escuchando en el
        // puerto — la ventana intentaba navegar antes de que PHP terminara
        // de bindear, y el webview mostraba ERR_CONNECTION_REFUSED. A
        // diferencia de mysqld (que sí tiene wait_for_mysql), acá faltaba el
        // mismo tipo de espera. Si el proceso murió solo (ej. dependencia
        // faltante), se lee el log de stderr para dar un error real en vez
        // de un timeout genérico.
        match Self::wait_for_php(&mut child, port, Duration::from_secs(15), &stderr_log_path) {
            Ok(()) => {
                self.php_proc = Some(child);
                Ok(port)
            }
            Err(e) => {
                let _ = child.kill();
                Err(e)
            }
        }
    }

    fn wait_for_php(child: &mut Child, port: u16, timeout: Duration, stderr_log_path: &Path) -> Result<(), String> {
        let deadline = Instant::now() + timeout;
        loop {
            if let Some(status) = child.try_wait().map_err(|e| e.to_string())? {
                let stderr = fs::read_to_string(stderr_log_path).unwrap_or_default();
                return Err(format!(
                    "PHP terminó inesperadamente (código {status}).\n{}",
                    stderr.trim()
                ));
            }
            if std::net::TcpStream::connect_timeout(
                &format!("127.0.0.1:{port}").parse().unwrap(),
                Duration::from_millis(300),
            )
            .is_ok()
            {
                return Ok(());
            }
            if Instant::now() >= deadline {
                return Err(format!("PHP no respondió en el puerto {port} a tiempo (15s)."));
            }
            std::thread::sleep(Duration::from_millis(200));
        }
    }

    pub fn stop_all(&mut self) {
        // Modo supervisor: nunca matar los servicios NSSM, no son nuestros.
        if self.supervisor { return; }
        if let Some(mut proc) = self.php_proc.take() {
            let _ = proc.kill();
        }
        if self.db_proc.take().is_some() {
            let shutdown_ok = Self::command_no_window(&self.mysqladmin_exe())
                .args(["--no-defaults", "-h", "127.0.0.1", "-P"])
                .arg(self.db_port.to_string())
                .args(["-u", "root", "--connect-timeout=3", "shutdown"])
                .output()
                .map(|o| o.status.success())
                .unwrap_or(false);
            if !shutdown_ok {
                // best-effort: si mysqladmin shutdown falla el proceso se
                // queda huérfano en este spike (sin Job Object todavía) —
                // aceptable para el POC, pendiente si esto avanza de fase.
            }
        }
    }
}

fn can_bind(port: u16, host: &str) -> bool {
    TcpListener::bind((host, port)).is_ok()
}

fn is_port_free(port: u16) -> bool {
    can_bind(port, "0.0.0.0") && can_bind(port, "127.0.0.1")
}

fn find_free_port(preferred: u16) -> Result<u16, String> {
    let base = if preferred == 80 { 8080 } else { preferred };
    for candidate in base..base.saturating_add(6) {
        if candidate != 80 && is_port_free(candidate) {
            return Ok(candidate);
        }
    }
    // último recurso: que el SO asigne uno libre
    TcpListener::bind(("127.0.0.1", 0))
        .map(|l| l.local_addr().unwrap().port())
        .map_err(|e| e.to_string())
}
