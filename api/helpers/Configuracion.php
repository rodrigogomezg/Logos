<?php

class Configuracion {

    private static ?array $cache = null;

    public static function get(): array {
        if (self::$cache === null) {
            $stmt = DB::get()->query("SELECT * FROM configuracion WHERE id = 1");
            self::$cache = $stmt->fetch() ?: self::defaults();
        }
        return self::$cache;
    }

    public static function invalidar(): void {
        self::$cache = null;
    }

    private static function defaults(): array {
        return [
            'id'                   => 1,
            'razon_social'         => '',
            'nombre_fantasia'      => null,
            'cuit'                 => '',
            'condicion_iva'        => 'Responsable Inscripto',
            'domicilio'            => null,
            'iibb'                 => null,
            'telefono'             => null,
            'website'              => null,
            'punto_venta'          => null,
            'iva_porcentaje'       => 21.00,
            'impresora_nombre'     => null,
            'carpeta_comprobantes' => null,
            'carpeta_backups'      => null,
            'carpeta_backups_secundaria' => null,
            'afip_cert'            => null,
            'afip_key'             => null,
            'afip_entorno'         => 'homologacion',
            'clave_autorizacion_hash' => null,
            'color_tema'              => 'azul',
            'backup_auto_cierre'      => 0,
            'ventas_sin_stock'        => 0,
            'mp_access_token'         => '',
            'mp_webhook_secret'       => '',
            'wa_phone_id'             => '',
            'wa_token'                => '',
            'wa_template_name'        => 'envio_comprobante',
        ];
    }

    /** Ejecuta mysqldump. Retorna la ruta del archivo creado, o null si no hay carpeta configurada. Lanza RuntimeException en fallo. */
    public static function ejecutarBackup(): ?string {
        $config  = self::get();
        $carpeta = $config['carpeta_backups'] ?? '';
        if (!$carpeta || !is_dir($carpeta)) return null;

        $archivo = rtrim($carpeta, '\\/') . '/logos_backup_' . date('Ymd_His') . '.sql';
        self::dumpBase($archivo);
        self::copiaSecundaria($archivo, $config);
        return $archivo;
    }

    /**
     * Vuelca la base completa en $archivo con mysqldump.
     * Todos los argumentos van escapados: la contraseña o una ruta con
     * caracteres especiales no pueden inyectar comandos en el shell.
     */
    public static function dumpBase(string $archivo): void {
        require_once __DIR__ . '/SystemPaths.php';

        $c   = DB::config();
        $cmd = escapeshellarg(SystemPaths::findMysqldumpBin())
             . ' -h' . escapeshellarg($c['host'])
             . ' -P' . escapeshellarg((string)$c['port'])
             . ' -u' . escapeshellarg($c['user'])
             . ($c['pass'] !== '' ? ' -p' . escapeshellarg($c['pass']) : '')
             . ' ' . escapeshellarg($c['dbname'])
             . ' > ' . escapeshellarg($archivo) . ' 2>&1';

        exec($cmd, $salida, $codigo);
        if ($codigo !== 0 || !file_exists($archivo) || filesize($archivo) === 0) {
            @unlink($archivo);
            throw new \RuntimeException('Falló el backup: ' . implode(' ', $salida));
        }
    }

    /**
     * Copia el backup a la carpeta secundaria (unidad de red o carpeta
     * sincronizada a la nube) si está configurada. Best effort: un fallo acá
     * no invalida el backup principal, pero queda en el log.
     */
    private static function copiaSecundaria(string $archivo, array $config): void {
        $carpeta = trim((string)($config['carpeta_backups_secundaria'] ?? ''));
        if ($carpeta === '' || !is_dir($carpeta)) return;

        $destino = rtrim($carpeta, '\\/') . '/' . basename($archivo);
        if (!@copy($archivo, $destino)) {
            error_log(sprintf(
                "[%s] Backup: no se pudo copiar a la carpeta secundaria %s\n",
                date('Y-m-d H:i:s'), $carpeta
            ), 3, __DIR__ . '/../logs/error.log');
        }
    }

    public static function estaConfigurado(): bool {
        $c = self::get();
        return trim((string)($c['razon_social'] ?? '')) !== '';
    }
}
