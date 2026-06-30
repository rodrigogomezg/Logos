<?php

class InstalacionController {

    private const MYSQL_BIN = 'C:\\xampp\\mysql\\bin\\mysql.exe';

    // ── Estado actual de la instalación ───────────────────────────────
    public function estado(): void {
        $base = ['requiere_conexion' => false, 'requiere_schema' => false, 'requiere_admin' => false, 'requiere_negocio' => false, 'requiere_caja' => false];

        if (!DB::estaConfigurado()) {
            json(200, array_merge($base, ['requiere_conexion' => true]));
        }

        try {
            $db = DB::get();
        } catch (\Throwable $e) {
            json(200, array_merge($base, ['requiere_conexion' => true]));
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
                $cmd = '"' . self::MYSQL_BIN . '" --default-character-set=utf8mb4 -h' . $host . ' -P' . $port .
                       ' -ulogos_app ' . $passArg . $dbname . ' < "' . $schemaFile . '" 2>&1';
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
        file_put_contents(__DIR__ . '/../config/db.local.php', $contenido);

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
