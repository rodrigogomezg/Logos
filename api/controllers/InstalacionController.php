<?php

require_once __DIR__ . '/../helpers/SystemPaths.php';
require_once __DIR__ . '/../config/db.php';

class InstalacionController {

    /**
     * GET /api/instalacion/info
     * Info de bajo nivel de ESTA instalación (rol Servidor/Cliente, puerto,
     * IP del servidor si es Cliente) — lee logos-config.json directo, el
     * mismo archivo que ya escriben tanto el instalador NSIS de Electron
     * como el de Tauri en DB::dataRoot(). Reemplaza a window.logos.getConfig()
     * (API que solo existe en Electron) para que el rol de la instalación se
     * pueda leer desde cualquier shell — o desde un navegador común en dev.
     * Sin sesión, de solo lectura, sin datos sensibles.
     */
    public function info(): void {
        $path = DB::dataRoot() . '\\logos-config.json';
        $role = null;
        $serverPort = null;
        $serverIp   = null;

        if (is_file($path)) {
            $raw  = @file_get_contents($path);
            $data = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($data)) {
                $role       = isset($data['role']) ? (string)$data['role'] : null;
                $serverPort = isset($data['serverPort']) && is_numeric($data['serverPort']) ? (int)$data['serverPort'] : null;
                $serverIp   = isset($data['serverIp']) ? $data['serverIp'] : null;
            }
        }

        json(200, [
            'role'        => $role,
            'server_port' => $serverPort,
            'server_ip'   => $serverIp,
        ]);
    }

    // ── Estado actual de la instalación ───────────────────────────────
    public function estado(): void {
        $base = ['sin_conexion' => false, 'requiere_conexion' => false, 'requiere_schema' => false, 'requiere_admin' => false, 'requiere_negocio' => false, 'requiere_caja' => false];

        if (!DB::estaConfigurado()) {
            json(200, array_merge($base, ['requiere_conexion' => true]));
        }

        try {
            $db = DB::get();
        } catch (\Throwable $e) {
            // Hay configuración guardada pero el servidor no responde (ej. MySQL apagado):
            // no es una instalación pendiente, es un problema de servicio.
            json(200, array_merge($base, ['sin_conexion' => true]));
        }

        try {
            $db->query("SELECT 1 FROM usuarios LIMIT 1");
        } catch (\Throwable $e) {
            json(200, array_merge($base, ['requiere_schema' => true]));
        }

        $hayAdmin = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin'")->fetchColumn() > 0;
        $hayCaja  = (int)$db->query("SELECT COUNT(*) FROM cajas WHERE activo = 1")->fetchColumn() > 0;

        $negocioOk = false;
        try {
            $row = $db->query("SELECT razon_social FROM configuracion WHERE id = 1")->fetch();
            $negocioOk = $row && trim((string)$row['razon_social']) !== '';
        } catch (\Throwable $e) {
            $negocioOk = false;
        }

        json(200, [
            'sin_conexion'      => false,
            'requiere_conexion' => false,
            'requiere_schema'   => false,
            'requiere_admin'    => !$hayAdmin,
            'requiere_negocio'  => !$negocioOk,
            'requiere_caja'     => !$hayCaja,
        ]);
    }

    // ── Probar una conexión sin guardar nada ──────────────────────────
    public function probarConexion(): void {
        $body   = json_decode(file_get_contents('php://input'), true) ?: [];
        $host   = trim($body['host'] ?? '127.0.0.1');
        $port   = (int)($body['port'] ?? 3306);
        $dbname = trim($body['dbname'] ?? 'logos');
        $user   = trim($body['user'] ?? 'root');
        $pass   = (string)($body['pass'] ?? '');

        try {
            $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (\Throwable $e) {
            json(200, ['ok' => false, 'error' => 'No se pudo conectar al servidor de base de datos: ' . $e->getMessage()]);
        }

        $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmt->execute([$dbname]);
        $existe = (bool)$stmt->fetch();

        $tieneEsquema = false;
        if ($existe) {
            try {
                $pdo->exec("USE `" . str_replace('`', '', $dbname) . "`");
                $tieneEsquema = (bool)$pdo->query("SHOW TABLES LIKE 'usuarios'")->fetch();
            } catch (\Throwable $e) {
                $tieneEsquema = false;
            }
        }

        json(200, ['ok' => true, 'base_existe' => $existe, 'tiene_esquema' => $tieneEsquema]);
    }

    // ── Crear/conectar y escribir db.local.php ────────────────────────
    public function instalar(): void {
        // Si ya hay una instalación funcionando (config + base alcanzable con esquema),
        // no se permite reinstalar: reinstalar reescribe db.local.php y recrea el usuario
        // de aplicación, lo que desconectaría la base actual. Para reinstalar a propósito,
        // hay que borrar DB::configPath() a mano.
        if (DB::estaConfigurado()) {
            try {
                DB::get()->query("SELECT 1 FROM usuarios LIMIT 1");
                json(403, ['error' => 'El sistema ya está instalado. Si realmente necesitás reinstalar, eliminá ' . DB::configPath() . ' en el servidor y volvé a intentar.']);
            } catch (\Throwable $e) {
                // Config presente pero base inalcanzable o sin esquema: se permite
                // reinstalar como vía de recuperación.
            }
        }

        $body   = json_decode(file_get_contents('php://input'), true) ?: [];
        $modo   = $body['modo'] ?? '';
        $host   = trim($body['host'] ?? '127.0.0.1');
        $port   = (int)($body['port'] ?? 3306);
        $dbname = trim($body['dbname'] ?? 'logos');
        $user   = trim($body['user'] ?? 'root');
        $pass   = (string)($body['pass'] ?? '');

        if (!in_array($modo, ['servidor', 'cliente'], true)) json(400, ['error' => 'modo debe ser servidor o cliente']);
        if ($dbname === '' || !preg_match('/^[A-Za-z0-9_]+$/', $dbname)) json(400, ['error' => 'Nombre de base inválido (solo letras, números y guión bajo)']);
        if ($host === '') json(400, ['error' => 'host es requerido']);

        try {
            $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (\Throwable $e) {
            json(400, ['error' => 'No se pudo conectar: ' . $e->getMessage()]);
        }

        if ($modo === 'servidor') {
            try {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4");

                $appPass = bin2hex(random_bytes(12));
                $passEsc = $pdo->quote($appPass);
                $pdo->exec("DROP USER IF EXISTS 'logos_app'@'%'");
                $pdo->exec("CREATE USER 'logos_app'@'%' IDENTIFIED BY $passEsc");
                $pdo->exec("GRANT ALL PRIVILEGES ON `$dbname`.* TO 'logos_app'@'%'");
                $pdo->exec("FLUSH PRIVILEGES");
            } catch (\Throwable $e) {
                json(500, ['error' => 'No se pudo crear la base/usuario de aplicación: ' . $e->getMessage()]);
            }

            // Verificar si el esquema ya existe antes de importar
            $tieneEsquema = false;
            try {
                $pdo->exec("USE `" . str_replace('`', '', $dbname) . "`");
                $tieneEsquema = (bool)$pdo->query("SHOW TABLES LIKE 'usuarios'")->fetch();
            } catch (\Throwable $e) {}

            if (!$tieneEsquema) {
                $schemaFile = __DIR__ . '/../../install/schema_limpio.sql';
                if (!is_file($schemaFile)) json(500, ['error' => 'No se encontró install/schema_limpio.sql']);

                $passArg = '-p' . $appPass . ' ';
                $cmd = '"' . SystemPaths::findMysqlBin() . '" --default-character-set=utf8mb4'
                     . ' -h' . escapeshellarg($host)
                     . ' -P' . escapeshellarg((string)$port)
                     . ' -ulogos_app ' . $passArg . $dbname
                     . ' < "' . $schemaFile . '" 2>&1';
                exec($cmd, $salida, $codigo);
                if ($codigo !== 0) {
                    json(500, ['error' => 'Falló la creación del esquema: ' . implode(' ', $salida)]);
                }
            }

            $finalHost = '127.0.0.1';
            $finalUser = 'logos_app';
            $finalPass = $appPass;
        } else {
            try {
                $pdo->exec("USE `$dbname`");
                $tieneEsquema = (bool)$pdo->query("SHOW TABLES LIKE 'usuarios'")->fetch();
            } catch (\Throwable $e) {
                json(400, ['error' => 'No se pudo usar esa base: ' . $e->getMessage()]);
            }
            if (!$tieneEsquema) {
                json(400, ['error' => 'No se encontró el esquema de Logos en esa base. ¿Es el servidor correcto y ya está instalado?']);
            }
            $finalHost = $host;
            $finalUser = $user;
            $finalPass = $pass;
        }

        $contenido = "<?php\n" .
            "// Config de conexión específica de esta instalación. No se distribuye.\n\n" .
            "return [\n" .
            "    'host'   => " . var_export($finalHost, true) . ",\n" .
            "    'port'   => " . var_export($port, true) . ",\n" .
            "    'dbname' => " . var_export($dbname, true) . ",\n" .
            "    'user'   => " . var_export($finalUser, true) . ",\n" .
            "    'pass'   => " . var_export($finalPass, true) . ",\n" .
            "];\n";
        $dbConfigDir = dirname(DB::configPath());
        if (!is_dir($dbConfigDir)) mkdir($dbConfigDir, 0777, true);
        file_put_contents(DB::configPath(), $contenido);

        json(200, [
            'ok'   => true,
            'modo' => $modo,
            'credenciales_servidor' => $modo === 'servidor' ? ['user' => 'logos_app', 'pass' => $appPass, 'host' => $host, 'port' => $port, 'dbname' => $dbname] : null,
        ]);
    }

    // ── Recuperar ID del admin durante el setup (no expone contraseñas) ──
    public function adminId(): void {
        $db = DB::get();

        // Solo responde mientras el sistema no esté completamente configurado
        $negocioOk = false;
        try {
            $row = $db->query("SELECT razon_social FROM configuracion WHERE id = 1")->fetch();
            $negocioOk = $row && trim((string)$row['razon_social']) !== '';
        } catch (\Throwable $e) {}
        $hayCaja = (int)$db->query("SELECT COUNT(*) FROM cajas WHERE activo = 1")->fetchColumn() > 0;

        if ($negocioOk && $hayCaja) {
            json(403, ['error' => 'Sistema ya configurado. Iniciá sesión normalmente.']);
        }

        $admin = $db->query("SELECT id FROM usuarios WHERE rol = 'admin' AND activo = 1 LIMIT 1")->fetch();
        if (!$admin) json(404, ['error' => 'No hay administrador registrado aún.']);

        json(200, ['id' => (int)$admin['id']]);
    }

    // ── Crear el primer administrador (solo si no existe ninguno) ────
    public function crearAdmin(): void {
        $db = DB::get();
        $hayAdmin = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin'")->fetchColumn() > 0;
        if ($hayAdmin) json(403, ['error' => 'Ya existe un administrador. Iniciá sesión normalmente.']);

        $body   = json_decode(file_get_contents('php://input'), true) ?: [];
        $nombre = trim($body['nombre'] ?? '');
        $pin    = (string)($body['pin'] ?? '');

        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);
        if (!preg_match('/^\d{4,6}$/', $pin)) json(400, ['error' => 'pin debe ser numérico de 4 a 6 dígitos']);

        $db->prepare("INSERT INTO usuarios (nombre, pin_hash, rol, activo) VALUES (?, ?, 'admin', 1)")
           ->execute([$nombre, password_hash($pin, PASSWORD_DEFAULT)]);

        json(200, ['ok' => true, 'id' => (int)$db->lastInsertId()]);
    }
}
