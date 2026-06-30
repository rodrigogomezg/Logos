<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Configuracion.php';
require_once __DIR__ . '/../helpers/SilentPrint.php';

class ConfiguracionController {

    public function get(): void {
        json(200, $this->sanitizar(Configuracion::get()));
    }

    private function sanitizar(array $config): array {
        $config['afip_configurado'] = !empty($config['afip_cert']);
        unset($config['afip_cert'], $config['afip_key']);
        $config['clave_autorizacion_configurada'] = !empty($config['clave_autorizacion_hash']);
        unset($config['clave_autorizacion_hash']);
        return $config;
    }

    public function actualizar(): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);

        $razonSocial = trim($body['razon_social'] ?? '');
        $cuit        = trim($body['cuit'] ?? '');
        if ($razonSocial === '') json(400, ['error' => 'razon_social es requerido']);
        if ($cuit === '')        json(400, ['error' => 'cuit es requerido']);

        $coloresTema = ['azul','verde','rojo','naranja','violeta','rosa','indigo','teal','gris','negro'];
        $colorTema   = in_array($body['color_tema'] ?? '', $coloresTema, true) ? $body['color_tema'] : null;

        $claveAutorizacion = trim($body['clave_autorizacion'] ?? '');
        $claveHash = $claveAutorizacion !== '' ? password_hash($claveAutorizacion, PASSWORD_DEFAULT) : null;

        DB::get()->prepare("
            INSERT INTO configuracion
                (id, razon_social, nombre_fantasia, cuit, condicion_iva, domicilio, iibb, telefono, website,
                 punto_venta, iva_porcentaje, impresora_nombre, carpeta_comprobantes, carpeta_backups,
                 clave_autorizacion_hash, color_tema, actualizado_en)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                razon_social         = VALUES(razon_social),
                nombre_fantasia      = VALUES(nombre_fantasia),
                cuit                 = VALUES(cuit),
                condicion_iva        = VALUES(condicion_iva),
                domicilio            = VALUES(domicilio),
                iibb                 = VALUES(iibb),
                telefono             = VALUES(telefono),
                website              = VALUES(website),
                punto_venta          = VALUES(punto_venta),
                iva_porcentaje       = VALUES(iva_porcentaje),
                impresora_nombre     = VALUES(impresora_nombre),
                carpeta_comprobantes = VALUES(carpeta_comprobantes),
                carpeta_backups      = VALUES(carpeta_backups),
                clave_autorizacion_hash = COALESCE(?, clave_autorizacion_hash),
                color_tema           = COALESCE(?, color_tema),
                actualizado_en       = NOW()
        ")->execute([
            $razonSocial,
            trim($body['nombre_fantasia'] ?? '') ?: null,
            $cuit,
            trim($body['condicion_iva'] ?? '') ?: 'Responsable Inscripto',
            trim($body['domicilio'] ?? '') ?: null,
            trim($body['iibb'] ?? '') ?: null,
            trim($body['telefono'] ?? '') ?: null,
            trim($body['website'] ?? '') ?: null,
            (int)($body['punto_venta'] ?? 1),
            (float)($body['iva_porcentaje'] ?? 21),
            trim($body['impresora_nombre'] ?? '') ?: null,
            trim($body['carpeta_comprobantes'] ?? '') ?: null,
            trim($body['carpeta_backups'] ?? '') ?: null,
            $claveHash,   // INSERT clave_autorizacion_hash
            $colorTema,   // INSERT color_tema
            $claveHash,   // UPDATE COALESCE clave_autorizacion_hash
            $colorTema,   // UPDATE COALESCE color_tema
        ]);

        Configuracion::invalidar();
        json(200, $this->sanitizar(Configuracion::get()));
    }

    public function subirCertAfip(): void {
        if (empty($_FILES['cert_p12']) || $_FILES['cert_p12']['error'] !== UPLOAD_ERR_OK) {
            json(400, ['error' => 'No se recibió el archivo .p12']);
        }

        $contenido = file_get_contents($_FILES['cert_p12']['tmp_name']);
        $pass      = (string)($_POST['cert_pass'] ?? '');
        $certs     = [];

        if (!openssl_pkcs12_read($contenido, $certs, $pass)) {
            json(400, ['error' => 'No se pudo leer el certificado. Verificá que el archivo y la contraseña sean correctos.']);
        }

        $cert = $certs['cert'] ?? null;
        $key  = $certs['pkey'] ?? null;
        if (!$cert || !$key) {
            json(400, ['error' => 'El archivo .p12 no contiene certificado y clave válidos.']);
        }

        $entorno = (($_POST['entorno'] ?? '') === 'produccion') ? 'produccion' : 'homologacion';

        DB::get()->prepare("
            INSERT INTO configuracion (id, afip_cert, afip_key, afip_entorno, actualizado_en)
            VALUES (1, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                afip_cert    = VALUES(afip_cert),
                afip_key     = VALUES(afip_key),
                afip_entorno = VALUES(afip_entorno),
                actualizado_en = NOW()
        ")->execute([$cert, $key, $entorno]);

        Configuracion::invalidar();
        json(200, ['ok' => true, 'entorno' => $entorno]);
    }

    public function listarImpresoras(): void {
        $salida = shell_exec('powershell -NoProfile -Command "Get-Printer | Select-Object -ExpandProperty Name"');
        $nombres = array_values(array_filter(array_map('trim', explode("\n", (string)$salida))));
        json(200, $nombres);
    }

    public function subirLogo(): void {
        if (empty($_FILES['logo'])) json(400, ['error' => 'No se recibió ningún archivo']);

        $f = $_FILES['logo'];
        if ($f['error'] !== UPLOAD_ERR_OK) json(400, ['error' => 'Error al subir el archivo']);
        if ($f['size'] > 5 * 1024 * 1024) json(400, ['error' => 'El archivo supera el límite de 5 MB']);

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            json(400, ['error' => 'Tipo de archivo no permitido. Solo imágenes (JPG, PNG, WEBP).']);
        }

        $destino = __DIR__ . '/../../logo_background.png';
        if (!move_uploaded_file($f['tmp_name'], $destino)) {
            json(500, ['error' => 'No se pudo guardar el logo']);
        }

        json(200, ['ok' => true, 'path' => '/Logos/logo_background.png?' . time()]);
    }

    public function probarImpresion(): void {
        $body     = json_decode(file_get_contents('php://input'), true) ?: [];
        $config   = Configuracion::get();
        $impresora = trim($body['impresora_nombre'] ?? '') ?: $config['impresora_nombre'];

        if (!$impresora) json(400, ['error' => 'No hay impresora seleccionada']);

        $options = new Dompdf\Options();
        $dompdf  = new Dompdf\Dompdf($options);
        $dompdf->loadHtml('<html><body style="font-family:sans-serif;"><h1>Prueba de impresión</h1><p>Si ves esta hoja impresa, la impresora quedó configurada correctamente.</p></body></html>');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $tmp = sys_get_temp_dir() . '/logos_prueba_impresion.pdf';
        file_put_contents($tmp, $dompdf->output());

        $ok = SilentPrint::imprimir($tmp, $impresora);
        @unlink($tmp);

        if (!$ok) json(500, ['error' => 'No se pudo enviar a imprimir. Revisá el nombre exacto de la impresora.']);
        json(200, ['ok' => true]);
    }

    public function backupAhora(): void {
        $config  = Configuracion::get();
        $carpeta = $config['carpeta_backups'];
        if (!$carpeta) json(400, ['error' => 'No hay carpeta de backups configurada']);
        if (!is_dir($carpeta)) json(400, ['error' => 'La carpeta de backups no existe: ' . $carpeta]);

        $archivo   = rtrim($carpeta, '\\/') . '/logos_backup_' . date('Ymd_His') . '.sql';
        $mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
        $c    = DB::config();
        $pass = $c['pass'] !== '' ? '-p' . $c['pass'] . ' ' : '';
        $cmd  = '"' . $mysqldump . '" -h' . $c['host'] . ' -P' . $c['port'] . ' -u' . $c['user'] . ' ' . $pass . $c['dbname'] . ' > "' . $archivo . '" 2>&1';

        exec($cmd, $salida, $codigo);

        if ($codigo !== 0 || !file_exists($archivo) || filesize($archivo) === 0) {
            json(500, ['error' => 'Falló el backup: ' . implode(' ', $salida)]);
        }

        json(200, ['ok' => true, 'archivo' => $archivo]);
    }

    public function resetFabrica(): void {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        if (trim($body['confirmacion'] ?? '') !== 'BORRAR TODO') {
            json(400, ['error' => 'Confirmación inválida']);
        }

        $config = Configuracion::get();
        $hash   = $config['clave_autorizacion_hash'] ?? null;
        if ($hash) {
            $clave = trim($body['clave_autorizacion'] ?? '');
            if ($clave === '' || !password_verify($clave, $hash)) {
                json(403, ['error' => 'Clave de autorización incorrecta']);
            }
        }

        $c       = DB::config();
        $carpeta = $config['carpeta_backups'] ?: (__DIR__ . '/../../install/backups');
        if (!is_dir($carpeta)) @mkdir($carpeta, 0777, true);
        if (!is_dir($carpeta)) json(500, ['error' => 'No se pudo crear la carpeta de backups: ' . $carpeta]);

        $archivo   = rtrim($carpeta, '\\/') . '/logos_pre_reset_' . date('Ymd_His') . '.sql';
        $mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
        $pass      = $c['pass'] !== '' ? '-p' . $c['pass'] . ' ' : '';
        $cmd       = '"' . $mysqldump . '" -h' . $c['host'] . ' -P' . $c['port'] . ' -u' . $c['user'] . ' ' . $pass . $c['dbname'] . ' > "' . $archivo . '" 2>&1';

        exec($cmd, $salida, $codigo);
        if ($codigo !== 0 || !file_exists($archivo) || filesize($archivo) === 0) {
            json(500, ['error' => 'No se pudo generar el backup previo. Se abortó el reset sin borrar nada: ' . implode(' ', $salida)]);
        }

        $tablas = [
            'venta_pagos', 'venta_items', 'ventas', 'compra_items', 'compras',
            'movimientos_stock', 'cuenta_corriente_movimientos', 'cc_asignaciones',
            'caja_movimientos', 'caja_turnos',
            'productos_import_detalle', 'productos_import_lotes', 'productos_import_plantillas',
            'productos', 'clientes', 'proveedores', 'vendedores', 'cajas', 'usuarios', 'configuracion',
        ];

        $db = DB::get();
        try {
            $db->exec("SET FOREIGN_KEY_CHECKS = 0");
            $db->beginTransaction();
            foreach ($tablas as $t) {
                $db->exec("DELETE FROM `$t`");
            }
            $db->commit();
            foreach ($tablas as $t) {
                $db->exec("ALTER TABLE `$t` AUTO_INCREMENT = 1");
            }
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
            json(500, ['error' => 'Falló el borrado (se generó el backup en ' . $archivo . ' antes de intentar): ' . $e->getMessage()]);
        }

        Configuracion::invalidar();
        json(200, ['ok' => true, 'backup' => $archivo]);
    }
}
