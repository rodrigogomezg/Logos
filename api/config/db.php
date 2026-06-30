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
