<?php

class Configuracion {

    private static ?array $cache = null;

    // Ubicación del logo subido por el cliente: ProgramData, no el árbol de
    // la app — mismo motivo que db.local.php (ver CLAUDE.md): el instalador
    // NSIS reempaqueta resources/ en cada actualización y se lo podía llevar
    // puesto en silencio. LOGO_LEGACY_PATH es la ubicación vieja (raíz del
    // repo, dentro de resources/www/Logos/) — se migra sola la primera vez
    // que se pide la ruta y todavía no existe en la ubicación nueva.
    //
    // Vía DB::dataRoot() (no hardcodeado acá) — mismo motivo que db.php:
    // permite que instalaciones side-by-side (POC de Tauri) nunca escriban
    // en el C:\ProgramData\LogosPOS real (hallazgo 08/08/2026, ver memoria).
    private static function logoPath(): string {
        return DB::dataRoot() . '\\logo_background.png';
    }
    private const LOGO_LEGACY_PATH = __DIR__ . '/../../logo_background.png';

    /** Ruta destino para un logo recién subido — siempre ProgramData, nunca la ubicación vieja. */
    public static function rutaLogoDestino(): string {
        $logoPath = self::logoPath();
        $dir = dirname($logoPath);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        return $logoPath;
    }

    public static function rutaLogo(): string {
        $logoPath = self::logoPath();
        if (is_file($logoPath)) return $logoPath;
        if (is_file(self::LOGO_LEGACY_PATH)) {
            $dir = dirname($logoPath);
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            @copy(self::LOGO_LEGACY_PATH, $logoPath);
            return is_file($logoPath) ? $logoPath : self::LOGO_LEGACY_PATH;
        }
        return $logoPath;
    }

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
            'backup_ultimo_en'        => null,
            'backup_ultimo_ok'        => null,
            'backup_ultimo_error'     => null,
            'ventas_sin_stock'        => 0,
            'mp_access_token'         => '',
            'mp_webhook_secret'       => '',
            'wa_phone_id'             => '',
            'wa_token'                => '',
            'wa_template_name'        => 'envio_comprobante',
            'smtp_host'               => null,
            'smtp_puerto'             => 587,
            'smtp_seguridad'          => 'tls',
            'smtp_usuario'            => null,
            'smtp_clave'              => null,
            'smtp_de_nombre'          => null,
            'smtp_de_email'           => null,
            'smtp_reply_to'           => null,
        ];
    }

    /**
     * Carpeta usada cuando el cliente nunca configuró "carpeta_backups" —
     * así el backup automático funciona con cero configuración. Vive en
     * ProgramData junto a db.local.php y los datos de MariaDB, fuera de
     * cualquier árbol que el instalador NSIS reempaquete (ver CLAUDE.md).
     */
    public static function carpetaBackupsPorDefecto(): string {
        return DB::dataRoot() . '\\backups';
    }

    /**
     * Ejecuta mysqldump. Retorna la ruta del archivo creado. Lanza
     * RuntimeException en fallo. Siempre deja registrado en `configuracion`
     * el resultado (éxito o error), incluso cuando el llamador ignora la
     * excepción — así un fallo silencioso (ej. desde el cierre de turno)
     * sigue siendo visible para BackupController::estado() / la UI.
     */
    public static function ejecutarBackup(): string {
        $config  = self::get();
        $carpeta = trim((string)($config['carpeta_backups'] ?? '')) ?: self::carpetaBackupsPorDefecto();

        if (!is_dir($carpeta) && !@mkdir($carpeta, 0777, true)) {
            $msg = "No se pudo crear la carpeta de backups: $carpeta";
            self::registrarResultado(false, $msg);
            throw new \RuntimeException($msg);
        }

        $archivo = rtrim($carpeta, '\\/') . '/logos_backup_' . date('Ymd_His') . '.sql';
        try {
            self::dumpBase($archivo);
            self::copiaSecundaria($archivo, $config);
            self::registrarResultado(true, null);
            return $archivo;
        } catch (\Throwable $e) {
            self::registrarResultado(false, $e->getMessage());
            throw $e;
        }
    }

    private static function registrarResultado(bool $ok, ?string $error): void {
        try {
            DB::get()->prepare("
                UPDATE configuracion
                   SET backup_ultimo_en = NOW(), backup_ultimo_ok = ?, backup_ultimo_error = ?
                 WHERE id = 1
            ")->execute([$ok ? 1 : 0, $error !== null ? mb_substr($error, 0, 500) : null]);
            self::invalidar();
        } catch (\Throwable $e) {
            // No dejar que un fallo al registrar el resultado tape el error real del backup.
        }
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
     * Reemplaza la base completa por el contenido de $archivo (un dump de
     * mysqldump/dumpBase). Borra la base actual antes de importar — un
     * backup viejo no incluye tablas agregadas por migraciones posteriores,
     * así que importar sin borrar antes dejaría esas tablas con datos de una
     * época distinta al resto de la base ya restaurada. Las migraciones que
     * falten para volver a la versión actual de la app se vuelven a aplicar
     * solas en el próximo arranque (server-manager.js::_runMigrations corre
     * siempre, no solo en la instalación inicial).
     *
     * El llamador es responsable de generar un backup de seguridad del
     * estado previo ANTES de llamar a esto — acá no se hace, para no
     * duplicar esa decisión en dos lugares.
     */
    public static function restaurarDesde(string $archivo): void {
        require_once __DIR__ . '/SystemPaths.php';

        $c     = DB::config();
        $mysql = SystemPaths::findMysqlBin();
        $base  = ' -h' . escapeshellarg($c['host'])
               . ' -P' . escapeshellarg((string)$c['port'])
               . ' -u' . escapeshellarg($c['user'])
               . ($c['pass'] !== '' ? ' -p' . escapeshellarg($c['pass']) : '');

        $cmdRecrear = escapeshellarg($mysql) . $base
                    . ' -e ' . escapeshellarg(
                        "DROP DATABASE IF EXISTS `{$c['dbname']}`; " .
                        "CREATE DATABASE `{$c['dbname']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
                      )
                    . ' 2>&1';
        exec($cmdRecrear, $salida1, $codigo1);
        if ($codigo1 !== 0) {
            throw new \RuntimeException('No se pudo recrear la base antes de restaurar: ' . implode(' ', $salida1));
        }

        $cmdImportar = escapeshellarg($mysql) . $base
                     . ' ' . escapeshellarg($c['dbname'])
                     . ' < ' . escapeshellarg($archivo)
                     . ' 2>&1';
        exec($cmdImportar, $salida2, $codigo2);
        if ($codigo2 !== 0) {
            throw new \RuntimeException(
                'La base quedó vacía y la importación falló: ' . implode(' ', array_slice($salida2, -20))
            );
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
