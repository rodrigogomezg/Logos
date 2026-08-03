'use strict';
const { spawn, execFileSync, execFile } = require('child_process');
const path = require('path');
const fs   = require('fs');
const { findFreePort } = require('./port-utils');

// Windows service names — must match NSIS installer registration
const SERVICE_DB  = 'LogosPOS-DB';
const SERVICE_PHP = 'LogosPOS-PHP';

class ServerManager {
  constructor(resourcesPath, wwwPath, appDir) {
    this._resourcesPath      = resourcesPath;
    this._wwwPath            = wwwPath;
    this._appDir             = appDir;
    this._phpProc            = null;
    this._dbProc             = null;
    this._dbPort             = 3306;
    this._cachedServiceMode  = undefined;
  }

  // ── Binary resolution ────────────────────────────────────────────────────────

  // Prefer portable binaries under resources/.
  // XAMPP fallback ONLY when LOGOS_DEV_MODE=1 — never in production builds.
  _resolve(subpath) {
    const portable = path.join(this._resourcesPath, subpath);
    if (fs.existsSync(portable)) return portable;

    if (process.env.LOGOS_DEV_MODE === '1') {
      const xamppSubpath = subpath.replace(/^mariadb[\\/]/, 'mysql/');
      const xampp = path.join('C:\\xampp', xamppSubpath);
      if (fs.existsSync(xampp)) return xampp;
    }

    return portable; // _checkBinaries will throw the right error
  }

  get _phpExe()            { return this._resolve('php/php.exe'); }
  get _phpIni()            { return this._resolve('php/php.ini'); }
  get _mysqldExe()         { return this._resolve('mariadb/bin/mysqld.exe'); }
  get _mysqladminExe()     { return this._resolve('mariadb/bin/mysqladmin.exe'); }
  get _mysqlExe()          { return this._resolve('mariadb/bin/mysql.exe'); }
  get _mysqlInstallDbExe() { return this._resolve('mariadb/bin/mysql_install_db.exe'); }

  _checkBinaries() {
    const missing = [this._phpExe, this._mysqldExe].filter(f => !fs.existsSync(f));
    if (!missing.length) return;

    if (process.env.LOGOS_DEV_MODE === '1') {
      throw new Error(
        `Binarios no encontrados:\n${missing.join('\n')}\n\n` +
        `Ejecutá desde la carpeta electron/:\n  npm run setup-deps\n\n` +
        `O instalá XAMPP en C:\\xampp y volvé a intentar con LOGOS_DEV_MODE=1.`
      );
    }
    throw new Error(
      `Binarios portables no encontrados.\n\n` +
      `La instalación parece estar incompleta o dañada.\n\n` +
      `Reinstalá la aplicación desde el instalador original.`
    );
  }

  // ── Service mode detection ───────────────────────────────────────────────────

  // Returns 'supervisor' if NSSM services are registered, 'initiator' otherwise.
  // Result is cached for the lifetime of this process (service registration only
  // changes across installer runs, never mid-session).
  get serviceMode() {
    if (this._cachedServiceMode !== undefined) return this._cachedServiceMode;
    // In dev mode always use initiator (start our own server from source, ignore NSSM services)
    if (process.env.LOGOS_DEV_MODE === '1') {
      return (this._cachedServiceMode = 'initiator');
    }
    try {
      execFileSync('sc', ['query', SERVICE_DB], { stdio: 'ignore', timeout: 3000 });
      this._cachedServiceMode = 'supervisor';
    } catch {
      this._cachedServiceMode = 'initiator';
    }
    return this._cachedServiceMode;
  }

  // Expose current DB port so main.js can persist it after startup
  get dbPort() { return this._dbPort; }

  // ── INITIATOR mode — Electron owns the processes ─────────────────────────────

  // Returns { isFirstSetup: bool }
  async startDatabase(userDataPath) {
    this._checkBinaries();

    const dataDir = path.join(userDataPath, 'mysql-data');
    const isNew   = !fs.existsSync(path.join(dataDir, 'mysql'));

    if (isNew) {
      fs.mkdirSync(dataDir, { recursive: true });

      const installDbExe  = this._mysqlInstallDbExe;
      const useInstallDb  = fs.existsSync(installDbExe);

      const mariadbBase = path.dirname(path.dirname(this._mysqldExe));
      const binDir      = path.dirname(this._mysqldExe);
      try {
        if (useInstallDb) {
          execFileSync(installDbExe, [
            `--datadir=${dataDir}`,
            '--password=',
          ], { timeout: 120000, stdio: 'pipe', cwd: binDir });
        } else {
          // MariaDB 10.11+ supports --initialize-insecure directly on mysqld
          execFileSync(this._mysqldExe, [
            '--no-defaults',
            `--basedir=${mariadbBase}`,
            `--datadir=${dataDir}`,
            '--initialize-insecure',
          ], { timeout: 120000, stdio: 'pipe', cwd: binDir });
        }
      } catch (err) {
        const detail = (err.stderr || err.stdout || err.message || '').toString().substring(0, 400);
        throw new Error(`MariaDB init falló:\n${detail}`);
      }
    }

    this._dbPort = await findFreePort(3306);

    const mariadbBase = path.dirname(path.dirname(this._mysqldExe)); // bin/../ = mariadb root
    this._dbProc = spawn(this._mysqldExe, [
      '--no-defaults',
      `--basedir=${mariadbBase}`,
      `--datadir=${dataDir}`,
      `--port=${this._dbPort}`,
      '--bind-address=127.0.0.1',
      '--skip-networking=OFF',
      '--console',
    ], { detached: false, stdio: 'ignore', windowsHide: true, cwd: path.dirname(this._mysqldExe) });

    this._dbProc.on('error', err => console.error('[MariaDB]', err.message));
    this._dbExitCode = undefined;
    this._dbProc.on('exit', code => { this._dbExitCode = code; });

    try {
      await this._waitForMysql(30000);
    } catch (err) {
      if (this._dbExitCode !== undefined) {
        throw new Error(
          `MariaDB terminó inesperadamente (código ${this._dbExitCode}).\n\n` +
          `Puede haber otro servidor MySQL usando el puerto ${this._dbPort}.`
        );
      }
      throw err;
    }

    this._assertOwnDatabase(dataDir);

    if (isNew) await this._runSchema();
    this._runMigrations();

    return { isFirstSetup: isNew };
  }

  async startPhpServer(preferredPort = 8080, userDataPath = null) {
    this._checkBinaries();
    this._writeDbLocal();

    const port         = await findFreePort(preferredPort);
    const routerScript = path.join(this._wwwPath, 'router.php');
    const mariadbBin   = path.dirname(this._mysqldExe);
    const phpBin       = path.dirname(this._phpExe);

    // Set up per-install error log so PHP errors are visible without exposing them in-browser
    const logArgs = [];
    if (userDataPath) {
      const logDir = path.join(userDataPath, 'logs');
      fs.mkdirSync(logDir, { recursive: true });
      logArgs.push('-d', `error_log=${path.join(logDir, 'php-error.log')}`);
    }

    const env = {
      ...process.env,
      PATH: [mariadbBin, phpBin, process.env.PATH || ''].filter(Boolean).join(';'),
      LOGOS_APP_DIR:  this._appDir,
      LOGOS_DB_HOST:  '127.0.0.1',
      LOGOS_DB_PORT:  String(this._dbPort),
      LOGOS_DB_NAME:  'logos',
      LOGOS_DB_USER:  'root',
      LOGOS_DB_PASS:  '',
      PHPRC: path.dirname(this._phpExe),
    };

    this._phpProc = spawn(this._phpExe, [
      '-c', this._phpIni,
      ...logArgs,
      '-S', `0.0.0.0:${port}`,
      '-t', this._wwwPath,
      routerScript,
    ], { env, cwd: this._wwwPath, detached: false, stdio: 'ignore', windowsHide: true });

    this._phpProc.on('error', err => console.error('[PHP]', err.message));

    return port;
  }

  // Verify the mysqld answering on _dbPort is the one we spawned. On Windows a
  // foreign MySQL (XAMPP, servicio del instalador) puede tener 127.0.0.1:<port>
  // mientras nuestro proceso murió en silencio al no poder bindear — el ping
  // respondería igual y las migraciones correrían sobre una base ajena.
  _assertOwnDatabase(dataDir) {
    let actual;
    try {
      actual = execFileSync(this._mysqlExe, [
        '--no-defaults', '-h', '127.0.0.1', '-P', String(this._dbPort), '-u', 'root',
        '--skip-column-names', '-e', 'SELECT @@datadir;',
      ], { timeout: 10000 }).toString('utf8').trim();
    } catch {
      return; // no se pudo consultar — no bloquear el arranque por el chequeo en sí
    }
    const norm = p => path.resolve(p).toLowerCase().replace(/[\\/]+$/, '');
    if (norm(actual) !== norm(dataDir)) {
      throw new Error(
        `Otro servidor MySQL está ocupando el puerto ${this._dbPort}\n` +
        `(datadir: ${actual}).\n\n` +
        `Cerrá ese servicio (XAMPP u otra instalación de Logos POS)\n` +
        `y volvé a abrir la aplicación.`
      );
    }
  }

  // ── SUPERVISOR mode — services managed by NSSM ──────────────────────────────

  // Verify DB responds on the given port (does NOT spawn anything)
  waitForDatabase(port, timeoutMs = 15000) {
    this._dbPort = port;
    return this._waitForMysql(timeoutMs);
  }

  // Run pending migrations (safe to call in any mode; no-op if migrate/ missing)
  runMigrations() {
    this._runMigrations();
  }

  // ── Shared helpers ───────────────────────────────────────────────────────────

  _waitForMysql(timeoutMs) {
    return new Promise((resolve, reject) => {
      const deadline = Date.now() + timeoutMs;
      const attempt  = () => {
        execFile(this._mysqladminExe, [
          '--no-defaults', '-h', '127.0.0.1', '-P', String(this._dbPort),
          '-u', 'root', '--connect-timeout=2', 'ping',
        ], (err) => {
          if (!err) return resolve();
          if (Date.now() >= deadline)
            return reject(new Error('MariaDB no respondió en el tiempo esperado'));
          setTimeout(attempt, 1200);
        });
      };
      setTimeout(attempt, 2000);
    });
  }

  async _runSchema() {
    const schemaPath = path.join(this._appDir, 'install', 'schema_limpio.sql');
    if (!fs.existsSync(schemaPath))
      throw new Error(`Schema no encontrado: ${schemaPath}`);

    execFileSync(this._mysqlExe, [
      '--no-defaults', '-h', '127.0.0.1', '-P', String(this._dbPort), '-u', 'root',
      '-e', 'CREATE DATABASE IF NOT EXISTS logos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
    ], { timeout: 15000, stdio: 'ignore' });

    const schema = fs.readFileSync(schemaPath, 'utf8');
    execFileSync(this._mysqlExe, [
      '--no-defaults', '-h', '127.0.0.1', '-P', String(this._dbPort), '-u', 'root', 'logos',
    ], { input: schema, timeout: 60000, stdio: ['pipe', 'ignore', 'ignore'] });
  }

  _runMigrations() {
    const migrateDir = path.join(this._appDir, 'migrate');
    if (!fs.existsSync(migrateDir)) return;

    const mysql = (args, opts = {}) => execFileSync(this._mysqlExe, [
      '--no-defaults', '-h', '127.0.0.1', '-P', String(this._dbPort), '-u', 'root', 'logos',
      ...args,
    ], { timeout: 60000, ...opts });

    // Tabla de control (idempotente)
    mysql(['-e', `
      CREATE TABLE IF NOT EXISTS _schema_migrations (
        nombre VARCHAR(255) PRIMARY KEY,
        aplicado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB;
    `], { stdio: 'ignore' });

    // Archivos SQL ordenados por nombre (el prefijo numérico dicta el orden)
    const files = fs.readdirSync(migrateDir)
      .filter(f => /^\d+.*\.sql$/i.test(f))
      .sort();

    // Migraciones ya registradas
    const ran = new Set(
      mysql(['--skip-column-names', '-e', 'SELECT nombre FROM _schema_migrations ORDER BY nombre;'])
        .toString('utf8').trim().split('\n').filter(Boolean)
    );

    // NO asumir "sin registros todavía = schema_limpio.sql ya cubre todo y se puede
    // sellar todo sin correrlo". Una base vieja reutilizada (ej. tras desinstalar y
    // reinstalar conservando C:\ProgramData\LogosPOS\mysql-data) también llega acá
    // con 0 registros, aunque le falten migraciones reales — y esa suposición las
    // sellaba como aplicadas sin ejecutarlas, dejando columnas/tablas faltantes de
    // forma silenciosa y permanente (caso real 02/08/2026, ver CLAUDE.md). Cada
    // migración pasa siempre por el loop de abajo: en una instalación realmente
    // fresca simplemente falla con "ya existe" y queda sellada igual; en una base
    // vieja, la que falte se aplica de verdad.

    // Helper: extract external DB names referenced as "dbname.table" (excluding 'logos')
    const getExternalDbs = (sql) => {
      const matches = sql.matchAll(/\b([a-zA-Z_][a-zA-Z0-9_]*)\.`?[a-zA-Z_]/g);
      const dbs = new Set([...matches].map(m => m[1]).filter(d => d !== 'logos' && d !== 'VALUES'));
      return [...dbs];
    };

    const dbExists = (dbName) => {
      try {
        const out = mysql(['--skip-column-names', '-e',
          `SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=${JSON.stringify(dbName)};`,
        ]).toString('utf8').trim();
        return out !== '';
      } catch { return false; }
    };

    let applied = 0;
    for (const file of files) {
      if (ran.has(file)) continue;
      const sql = fs.readFileSync(path.join(migrateDir, file), 'utf8');

      // Skip migrations that require external databases not present in this install
      const missingDbs = getExternalDbs(sql).filter(db => !dbExists(db));
      if (missingDbs.length > 0) {
        console.log(`[migrations] ⚠ ${file} omitida (BD externa no disponible: ${missingDbs.join(', ')})`);
        continue;
      }

      try {
        mysql([], { input: sql, stdio: ['pipe', 'ignore', 'pipe'] });
        console.log(`[migrations] ✓ ${file}`);
      } catch (err) {
        const detail = (err.stderr || err.stdout || err.message || '').toString();
        console.warn(`[migrations] ⚠ ${file} falló (sellada):\n${detail.slice(0, 300)}`);
      }
      mysql(['-e', `INSERT IGNORE INTO _schema_migrations (nombre) VALUES (${JSON.stringify(file)});`],
        { stdio: 'ignore' });
      applied++;
    }
    if (applied > 0) console.log(`[migrations] ${applied} migración(es) aplicada(s).`);
  }

  // Write db.local.php so the installer wizard skips the DB step. Env vars in
  // startPhpServer take priority at runtime; this file lets PHP's
  // estaConfigurado() return true so the wizard skips the DB-connection step.
  // Lives in ProgramData, NOT under this._appDir (resources/) — that tree
  // gets reemplazado by the NSIS installer on every update, which can take
  // this file down with it. See CLAUDE.md § db.local.php.
  _writeDbLocal() {
    const dbLocalPath = 'C:\\ProgramData\\LogosPOS\\db.local.php';
    try {
      fs.mkdirSync(path.dirname(dbLocalPath), { recursive: true });
    } catch {}
    const content = [
      '<?php',
      '// Auto-generated by Logos POS — do not edit manually.',
      'return [',
      `    'host'   => '127.0.0.1',`,
      `    'port'   => ${this._dbPort},`,
      `    'dbname' => 'logos',`,
      `    'user'   => 'root',`,
      `    'pass'   => '',`,
      '];',
      '',
    ].join('\n');
    try {
      fs.writeFileSync(dbLocalPath, content, 'utf8');
    } catch (err) {
      console.warn('[db.local.php] Could not write:', err.message);
    }
  }

  // ── Teardown ─────────────────────────────────────────────────────────────────

  stopAll() {
    // In supervisor mode Electron does NOT own the processes — never kill NSSM services
    if (this.serviceMode === 'supervisor') return;

    if (this._phpProc) {
      try { this._phpProc.kill(); } catch {}
      this._phpProc = null;
    }
    if (this._dbProc) {
      try {
        execFileSync(this._mysqladminExe, [
          '--no-defaults', '-h', '127.0.0.1', '-P', String(this._dbPort),
          '-u', 'root', '--connect-timeout=3', 'shutdown',
        ], { timeout: 8000, stdio: 'ignore' });
      } catch {
        try { this._dbProc.kill(); } catch {}
      }
      this._dbProc = null;
    }
  }
}

module.exports = ServerManager;
