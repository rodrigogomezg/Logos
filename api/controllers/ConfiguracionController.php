<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Configuracion.php';
require_once __DIR__ . '/../helpers/SilentPrint.php';

class ConfiguracionController {

    public function get(): void {
        $config = $this->sanitizar(Configuracion::get());
        if (!Auth::esAdmin()) {
            unset($config['carpeta_backups'], $config['carpeta_backups_secundaria'], $config['carpeta_comprobantes']);
        }
        json(200, $config);
    }

    private function sanitizar(array $config): array {
        $config['afip_configurado'] = !empty($config['afip_cert']);
        if ($config['afip_configurado']) {
            $parsed = @openssl_x509_parse($config['afip_cert']);
            $config['afip_cert_vencimiento'] = $parsed ? date('d/m/Y', $parsed['validTo_time_t']) : null;
        } else {
            $config['afip_cert_vencimiento'] = null;
        }
        unset($config['afip_cert'], $config['afip_key']);
        $config['clave_autorizacion_configurada'] = !empty($config['clave_autorizacion_hash']);
        unset($config['clave_autorizacion_hash']);
        $config['mp_configurado'] = !empty($config['mp_access_token']);
        $config['wa_configurado'] = !empty($config['wa_phone_id']) && !empty($config['wa_token']);
        unset($config['mp_access_token'], $config['mp_webhook_secret'], $config['wa_token']);
        // Decodificar columnas JSON
        if (isset($config['posnet_terminales']) && is_string($config['posnet_terminales'])) {
            $config['posnet_terminales'] = json_decode($config['posnet_terminales'], true) ?? [];
        }
        if (isset($config['tipos_habilitados']) && is_string($config['tipos_habilitados'])) {
            $config['tipos_habilitados'] = json_decode($config['tipos_habilitados'], true) ?? [];
        }
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
        $colorTema   = in_array($body['color_tema'] ?? '', $coloresTema, true) ? $body['color_tema'] : 'azul';

        $claveAutorizacion = trim($body['clave_autorizacion'] ?? '');
        $claveHash = $claveAutorizacion !== '' ? password_hash($claveAutorizacion, PASSWORD_DEFAULT) : null;

        $posnetTerminales = isset($body['posnet_terminales']) && is_array($body['posnet_terminales'])
            ? json_encode(array_values($body['posnet_terminales']))
            : null;

        $tiposValidos     = ['FC A-ELECT', 'FC B-ELECT', 'FC C-ELECT', 'REMITO', 'PRESUPUESTO'];
        $tiposHabilitados = isset($body['tipos_habilitados']) && is_array($body['tipos_habilitados'])
            ? json_encode(array_values(array_filter($body['tipos_habilitados'], fn($t) => in_array($t, $tiposValidos, true))))
            : null;

        $mpToken   = trim($body['mp_access_token']   ?? '') ?: null;
        $mpSecret  = trim($body['mp_webhook_secret'] ?? '') ?: null;
        $waPhoneId = trim($body['wa_phone_id']       ?? '') ?: null;
        $waToken   = trim($body['wa_token']           ?? '') ?: null;
        $waTemplate = trim($body['wa_template_name'] ?? '') ?: 'envio_comprobante';

        $backupAutoCierre = isset($body['backup_auto_cierre']) ? (int)(bool)$body['backup_auto_cierre'] : 0;
        $ventasSinStock   = isset($body['ventas_sin_stock'])   ? (int)(bool)$body['ventas_sin_stock']   : 0;

        DB::get()->prepare("
            INSERT INTO configuracion
                (id, razon_social, nombre_fantasia, cuit, condicion_iva, domicilio, iibb, telefono, website,
                 punto_venta, iva_porcentaje, impresora_nombre, posnet_terminales, carpeta_comprobantes, carpeta_backups,
                 carpeta_backups_secundaria,
                 clave_autorizacion_hash, color_tema, tipos_habilitados,
                 mp_access_token, mp_webhook_secret, wa_phone_id, wa_token, wa_template_name,
                 backup_auto_cierre, ventas_sin_stock,
                 actualizado_en)
            VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
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
                posnet_terminales    = COALESCE(VALUES(posnet_terminales), posnet_terminales),
                carpeta_comprobantes = VALUES(carpeta_comprobantes),
                carpeta_backups      = VALUES(carpeta_backups),
                carpeta_backups_secundaria = VALUES(carpeta_backups_secundaria),
                clave_autorizacion_hash = COALESCE(?, clave_autorizacion_hash),
                color_tema           = COALESCE(?, color_tema),
                tipos_habilitados    = COALESCE(VALUES(tipos_habilitados), tipos_habilitados),
                mp_access_token      = COALESCE(VALUES(mp_access_token),   mp_access_token),
                mp_webhook_secret    = COALESCE(VALUES(mp_webhook_secret),  mp_webhook_secret),
                wa_phone_id          = VALUES(wa_phone_id),
                wa_token             = COALESCE(VALUES(wa_token), wa_token),
                wa_template_name     = COALESCE(VALUES(wa_template_name), wa_template_name),
                backup_auto_cierre   = VALUES(backup_auto_cierre),
                ventas_sin_stock     = VALUES(ventas_sin_stock),
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
            $posnetTerminales,
            trim($body['carpeta_comprobantes'] ?? '') ?: null,
            trim($body['carpeta_backups'] ?? '') ?: null,
            trim($body['carpeta_backups_secundaria'] ?? '') ?: null,
            $claveHash,         // INSERT clave_autorizacion_hash
            $colorTema,         // INSERT color_tema
            $tiposHabilitados,  // INSERT tipos_habilitados
            $mpToken,
            $mpSecret,
            $waPhoneId,
            $waToken,
            $waTemplate,
            $backupAutoCierre,
            $ventasSinStock,
            $claveHash,         // UPDATE COALESCE clave_autorizacion_hash
            $colorTema,         // UPDATE COALESCE color_tema
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
        $carpeta = $config['carpeta_backups'] ?? '';
        if (!$carpeta) json(400, ['error' => 'No hay carpeta de backups configurada']);
        if (!is_dir($carpeta)) json(400, ['error' => 'La carpeta de backups no existe: ' . $carpeta]);

        try {
            $archivo = Configuracion::ejecutarBackup();
            json(200, ['ok' => true, 'archivo' => $archivo]);
        } catch (\RuntimeException $e) {
            json(500, ['error' => $e->getMessage()]);
        }
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

        $carpeta = $config['carpeta_backups'] ?: (__DIR__ . '/../../install/backups');
        if (!is_dir($carpeta)) @mkdir($carpeta, 0777, true);
        if (!is_dir($carpeta)) json(500, ['error' => 'No se pudo crear la carpeta de backups: ' . $carpeta]);

        $archivo = rtrim($carpeta, '\\/') . '/logos_pre_reset_' . date('Ymd_His') . '.sql';
        try {
            Configuracion::dumpBase($archivo);
        } catch (\RuntimeException $e) {
            json(500, ['error' => 'No se pudo generar el backup previo. Se abortó el reset sin borrar nada: ' . $e->getMessage()]);
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
