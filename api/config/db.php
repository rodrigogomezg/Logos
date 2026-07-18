<?php

class DB {
    private static ?PDO $instance = null;
    private static ?array $config = null;

    public static function estaConfigurado(): bool {
        return is_file(__DIR__ . '/db.local.php');
    }

    public static function config(): array {
        if (self::$config === null) {
            $local = self::estaConfigurado() ? require __DIR__ . '/db.local.php' : [];
            self::$config = array_merge([
                'host'   => '127.0.0.1',
                'port'   => 3306,
                'dbname' => 'logos',
                'user'   => 'root',
                'pass'   => '',
            ], $local);

            // Overrides por variable de entorno: los usa la suite de tests
            // (tools/test_integracion.php) para apuntar el servidor embebido a
            // una base de prueba. Bajo Apache estas variables no existen, así
            // que la operación normal no cambia.
            foreach (['host' => 'LOGOS_DB_HOST', 'port' => 'LOGOS_DB_PORT', 'dbname' => 'LOGOS_DB_NAME',
                      'user' => 'LOGOS_DB_USER', 'pass' => 'LOGOS_DB_PASS'] as $clave => $env) {
                $v = getenv($env);
                if ($v === false) continue;
                // Para pass el string vacío es un valor válido (root de XAMPP);
                // para el resto, vacío = sin override.
                if ($v === '' && $clave !== 'pass') continue;
                self::$config[$clave] = $clave === 'port' ? (int)$v : $v;
            }
        }
        return self::$config;
    }

    public static function get(): PDO {
        if (self::$instance === null) {
            $c = self::config();
            self::$instance = new PDO(
                "mysql:host={$c['host']};port={$c['port']};dbname={$c['dbname']};charset=utf8mb4",
                $c['user'],
                $c['pass'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        }
        return self::$instance;
    }
}
