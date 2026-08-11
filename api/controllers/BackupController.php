<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Configuracion.php';

class BackupController {

    // El backup automático se considera "vencido" (y dispara un intento nuevo)
    // pasadas estas horas desde el último intento, haya sido exitoso o no.
    private const HORAS_ENTRE_INTENTOS = 20;

    // La UI avisa si no hay un backup EXITOSO dentro de este lapso.
    private const HORAS_ALERTA = 48;

    /**
     * POST /api/backup/programado
     * Llamada periódica de Electron (sin sesión, solo localhost — igual que
     * licencia/verificar). Corre un backup si el último intento fue hace más
     * de HORAS_ENTRE_INTENTOS horas o nunca hubo uno. Nunca deja que un fallo
     * del backup rompa la respuesta: Configuracion::ejecutarBackup() ya deja
     * el error registrado para que la UI lo muestre.
     */
    public function programado(): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
            http_response_code(403);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        $config     = Configuracion::get();
        $ultimoEn   = $config['backup_ultimo_en'] ?? null;
        $corresponde = $ultimoEn === null || self::horasDesde($ultimoEn) >= self::HORAS_ENTRE_INTENTOS;

        if ($corresponde) {
            try { Configuracion::ejecutarBackup(); } catch (\Throwable $e) { /* ya quedó registrado */ }
        }

        echo json_encode(array_merge(['ejecutado' => $corresponde], self::resumen()), JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/backup/estado
     * Solo lectura, para el badge/banner/toast de la UI. Requiere sesión
     * (no está en la lista de rutas públicas de index.php), igual que
     * cualquier otra pantalla del POS.
     */
    public function estado(): void {
        echo json_encode(self::resumen(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/backup/listar
     * Lista los .sql disponibles en carpeta_backups y carpeta_backups_secundaria
     * para elegir uno para restaurar, sin tener que subirlo a mano. Solo admin.
     */
    public function listar(): void {
        Auth::requireAdmin();

        $config   = Configuracion::get();
        $carpetas = [
            'principal'  => trim((string)($config['carpeta_backups'] ?? '')) ?: Configuracion::carpetaBackupsPorDefecto(),
            'secundaria' => trim((string)($config['carpeta_backups_secundaria'] ?? '')),
        ];

        $archivos = [];
        foreach ($carpetas as $origen => $carpeta) {
            if ($carpeta === '' || !is_dir($carpeta)) continue;
            foreach (glob(rtrim($carpeta, '\\/') . '/*.sql') ?: [] as $ruta) {
                $archivos[] = [
                    'nombre'     => basename($ruta),
                    'origen'     => $origen,
                    'tamano'     => filesize($ruta),
                    'modificado' => date('Y-m-d H:i:s', filemtime($ruta)),
                ];
            }
        }
        usort($archivos, fn($a, $b) => strcmp($b['modificado'], $a['modificado']));

        echo json_encode($archivos, JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST /api/backup/restaurar
     * Reemplaza TODA la base actual por un backup — desde la carpeta
     * configurada (campos archivo+origen) o subido en el momento (campo
     * backup, multipart). Solo admin, requiere escribir la frase de
     * confirmación exacta y, si hay clave de autorización configurada,
     * también esa clave — mismo nivel de fricción que resetFabrica().
     *
     * Antes de tocar la base actual se genera un backup de seguridad del
     * estado presente: si el archivo elegido resulta ser el equivocado, ese
     * backup previo permite volver atrás. Si ese backup de seguridad falla,
     * se aborta sin tocar nada (mismo criterio que resetFabrica()).
     */
    public function restaurar(): void {
        Auth::requireAdmin();
        set_time_limit(0); // un dump grande puede tardar varios minutos en importar

        $confirmacion = trim($_POST['confirmacion'] ?? '');
        if ($confirmacion !== 'RESTAURAR BACKUP') {
            json(400, ['error' => 'Confirmación inválida']);
        }

        $config = Configuracion::get();
        $hash   = $config['clave_autorizacion_hash'] ?? null;
        if ($hash) {
            $clave = trim($_POST['clave_autorizacion'] ?? '');
            if ($clave === '' || !password_verify($clave, $hash)) {
                json(403, ['error' => 'Clave de autorización incorrecta']);
            }
        }

        // Resolver el archivo a restaurar: subido ahora, o uno ya existente
        // en carpeta_backups/carpeta_backups_secundaria.
        if (!empty($_FILES['backup']) && $_FILES['backup']['error'] === UPLOAD_ERR_OK) {
            $rutaOrigen     = $_FILES['backup']['tmp_name'];
            $nombreMostrado = basename($_FILES['backup']['name']);
        } else {
            $nombre = basename(trim($_POST['archivo'] ?? '')); // basename(): evita path traversal
            $origen = ($_POST['origen'] ?? '') === 'secundaria' ? 'secundaria' : 'principal';
            $carpeta = $origen === 'secundaria'
                ? trim((string)($config['carpeta_backups_secundaria'] ?? ''))
                : (trim((string)($config['carpeta_backups'] ?? '')) ?: Configuracion::carpetaBackupsPorDefecto());
            if ($nombre === '' || $carpeta === '') {
                json(400, ['error' => 'No se especificó ningún archivo de backup']);
            }
            $rutaOrigen     = rtrim($carpeta, '\\/') . '/' . $nombre;
            $nombreMostrado = $nombre;
        }

        if (!is_file($rutaOrigen) || filesize($rutaOrigen) === 0) {
            json(400, ['error' => 'El archivo de backup no existe o está vacío']);
        }

        // Chequeo mínimo de que sea un dump real y no cualquier archivo subido por error.
        $muestra = file_get_contents($rutaOrigen, false, null, 0, 4096) ?: '';
        if (stripos($muestra, 'MySQL dump') === false && stripos($muestra, 'CREATE TABLE') === false) {
            json(400, ['error' => 'El archivo no parece ser un backup válido de Logos POS']);
        }

        try {
            $backupPrevio = Configuracion::ejecutarBackup();
        } catch (\Throwable $e) {
            json(500, ['error' => 'No se pudo generar el backup de seguridad previo. Se abortó la restauración sin tocar nada: ' . $e->getMessage()]);
        }

        try {
            Configuracion::restaurarDesde($rutaOrigen);
        } catch (\Throwable $e) {
            json(500, ['error' => $e->getMessage() . ' — el estado de antes de restaurar quedó guardado en: ' . $backupPrevio]);
        }

        Configuracion::invalidar();
        json(200, ['ok' => true, 'restaurado_desde' => $nombreMostrado, 'backup_previo' => $backupPrevio]);
    }

    private static function resumen(): array {
        $config     = Configuracion::get();
        $ultimoEn   = $config['backup_ultimo_en'] ?? null;
        $ultimoOk   = $config['backup_ultimo_ok'] ?? null;
        $horasDesde = $ultimoEn !== null ? self::horasDesde($ultimoEn) : null;

        // No alarmar en una instalación recién hecha: recién alertamos una vez
        // que hubo al menos un intento real (Electron llama a /programado al
        // arrancar, así que esto se resuelve solo en minutos).
        $alerta = $ultimoEn !== null && ($ultimoOk != 1 || ($horasDesde !== null && $horasDesde >= self::HORAS_ALERTA));

        $mensaje = null;
        if ($alerta) {
            $mensaje = $ultimoOk != 1
                ? ('El último backup automático falló: ' . ($config['backup_ultimo_error'] ?? 'motivo desconocido'))
                : ('No hay un backup exitoso en las últimas ' . self::HORAS_ALERTA . ' horas.');
        }

        return [
            'ultimo_en' => $ultimoEn,
            'ultimo_ok' => $ultimoOk !== null ? (bool)$ultimoOk : null,
            'alerta'    => $alerta,
            'mensaje'   => $mensaje,
        ];
    }

    private static function horasDesde(string $fechaMysql): float {
        $tz       = new DateTimeZone('America/Argentina/Buenos_Aires');
        $entonces = new DateTimeImmutable($fechaMysql, $tz);
        $ahora    = new DateTimeImmutable('now', $tz);
        return ($ahora->getTimestamp() - $entonces->getTimestamp()) / 3600;
    }
}
