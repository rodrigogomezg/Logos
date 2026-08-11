<?php

class DB {
    private static ?PDO $instance = null;
    private static ?array $config = null;

    // Ubicación compartida fuera de resources/ — el instalador NSIS reempaqueta
    // ese árbol en cada actualización y puede arrastrar este archivo con él
    // (bug real, ver CLAUDE.md § db.local.php). ProgramData sobrevive updates.
    //
    // LOGOS_DATA_ROOT: override para instalaciones side-by-side (ej. el POC
    // de Tauri, que corre en la misma PC que una instalación real de
    // Electron y NUNCA debe escribir en su C:\ProgramData\LogosPOS — ver
    // plan de migración Fase 3 / memoria project_migracion_sveltekit_tauri,
    // hallazgo real del 08/08/2026). Sin la env var (caso de Electron en
    // producción, sin cambios), el default es exactamente el de siempre.
    public static function dataRoot(): string {
        return getenv('LOGOS_DATA_ROOT') ?: 'C:\\ProgramData\\LogosPOS';
    }

    public static function configPath(): string {
        return self::dataRoot() . '\\db.local.php';
    }

    // Ubicación vieja, junto al código — se sigue leyendo para no romper
    // instalaciones que actualicen desde una versión anterior a este fix.
    private const LEGACY_PATH = __DIR__ . '/db.local.php';

    // Resuelve la config real, migrando de LEGACY_PATH a configPath() la
    // primera vez que la encuentra ahí. Best-effort: si la copia falla,
    // sigue leyendo de la ruta vieja hasta que algo la reescriba en la
    // nueva ubicación.
    private static function rutaLectura(): ?string {
        $configPath = self::configPath();
        if (is_file($configPath)) return $configPath;
        if (is_file(self::LEGACY_PATH)) {
            $dir = dirname($configPath);
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            @copy(self::LEGACY_PATH, $configPath);
            return is_file($configPath) ? $configPath : self::LEGACY_PATH;
        }
        return null;
    }

    public static function estaConfigurado(): bool {
        return self::rutaLectura() !== null;
    }

    public static function config(): array {
        if (self::$config === null) {
            $ruta  = self::rutaLectura();
            $local = $ruta !== null ? require $ruta : [];
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
