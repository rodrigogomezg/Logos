<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Configuracion.php';
require_once __DIR__ . '/../helpers/MercadoPago.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

class MercadoPagoController {

    /**
     * POST /mercadopago/preferencia
     * Body: { venta_id: int }
     * Crea una preferencia de pago en MP y devuelve el link + QR.
     */
    public function preferencia(): void {
        $config = Configuracion::get();
        if (empty($config['mp_access_token'])) {
            json(400, ['error' => 'MercadoPago no está configurado. Ingresá el Access Token en Configuración.']);
        }

        $body    = json_decode(file_get_contents('php://input'), true) ?: [];
        $ventaId = isset($body['venta_id']) && is_numeric($body['venta_id']) ? (int)$body['venta_id'] : 0;
        if ($ventaId <= 0) json(400, ['error' => 'venta_id inválido']);
        $montoParcial = isset($body['monto_mp']) && is_numeric($body['monto_mp']) && (float)$body['monto_mp'] > 0
            ? (float)$body['monto_mp']
            : null;

        $db   = DB::get();
        $stmt = $db->prepare("
            SELECT v.id, LPAD(v.id,8,'0') AS numero, v.total, v.tipo_comprobante,
                   c.nombre AS cliente_nombre
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            WHERE v.id = ? AND v.estado != 'anulado'
        ");
        $stmt->execute([$ventaId]);
        $venta = $stmt->fetch();
        if (!$venta) json(404, ['error' => 'Venta no encontrada']);
        $venta['total'] = $montoParcial ?? (float)$venta['total'];

        $proto      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $webhookUrl = $proto . '://' . $host . '/Logos/api/mercadopago/webhook';

        try {
            $pref = MercadoPago::crearPreferencia($venta, $config['mp_access_token'], $webhookUrl);
        } catch (RuntimeException $e) {
            json(502, ['error' => $e->getMessage()]);
        }

        $initPoint = $pref['init_point'] ?? '';

        // Persistir el link en la venta para que el PDF lo incluya al imprimir
        if ($initPoint) {
            $db->prepare("UPDATE ventas SET mp_init_point = ? WHERE id = ?")
               ->execute([$initPoint, $ventaId]);
        }

        $qrDataUri = null;
        if ($initPoint) {
            try {
                $result = (new Builder())->build(
                    writer: new PngWriter(),
                    data:   $initPoint,
                    size:   220,
                    margin: 4,
                );
                $qrDataUri = $result->getDataUri();
            } catch (Throwable) {
                // QR es opcional; si falla el helper GD, igual devolvemos el link
            }
        }

        json(200, [
            'preference_id' => $pref['id'] ?? null,
            'init_point'    => $initPoint,
            'qr_data_uri'   => $qrDataUri,
        ]);
    }

    /**
     * POST /mercadopago/confirmar-manual
     * Body: { venta_id: int }
     * Registra manualmente el pago MP en caja (para cuando el cajero lo confirma por la app de MP).
     */
    public function confirmarManual(): void {
        $body    = json_decode(file_get_contents('php://input'), true) ?: [];
        $ventaId = isset($body['venta_id']) && is_numeric($body['venta_id']) ? (int)$body['venta_id'] : 0;
        if ($ventaId <= 0) json(400, ['error' => 'venta_id inválido']);

        $db   = DB::get();
        $stmt = $db->prepare("SELECT id, caja_id, total FROM ventas WHERE id = ? AND estado != 'anulado'");
        $stmt->execute([$ventaId]);
        $venta = $stmt->fetch();
        if (!$venta) json(404, ['error' => 'Venta no encontrada']);

        $stmt = $db->prepare("
            SELECT id FROM caja_turnos
            WHERE caja_id = ? AND estado = 'abierto'
            ORDER BY abierto_en DESC LIMIT 1
        ");
        $stmt->execute([$venta['caja_id']]);
        $turno = $stmt->fetch();
        if (!$turno) json(400, ['error' => 'No hay turno abierto para esta caja. Abrí un turno antes de confirmar el pago.']);

        // Idempotencia: si esta venta ya tiene un pago MP registrado (por webhook
        // o por una confirmación manual previa), no ingresar el dinero de nuevo.
        $stmt = $db->prepare("SELECT origen FROM mp_pagos WHERE venta_id = ? LIMIT 1");
        $stmt->execute([$ventaId]);
        if ($previo = $stmt->fetch()) {
            $como = $previo['origen'] === 'webhook' ? 'automáticamente por la notificación de MP' : 'manualmente';
            json(409, ['error' => "El pago MP de esta venta ya fue registrado $como."]);
        }

        try {
            $db->beginTransaction();
            $db->prepare("INSERT INTO mp_pagos (payment_id, venta_id, monto, origen) VALUES (?, ?, ?, 'manual')")
               ->execute(['manual-' . $ventaId, $ventaId, (float)$venta['total']]);
            $db->prepare("
                INSERT INTO caja_movimientos (turno_id, tipo, medio_pago, monto, motivo, usuario_id, creado_en)
                VALUES (?, 'ingreso', 'mercado_pago', ?, 'MercadoPago (confirmado manualmente)', ?, NOW())
            ")->execute([$turno['id'], (float)$venta['total'], Auth::usuarioActual()['id'] ?? 0]);
            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            // Carrera con el webhook: la unique key hizo su trabajo
            if ($e->getCode() === '23000') json(409, ['error' => 'El pago MP de esta venta ya fue registrado.']);
            throw $e;
        }

        json(200, ['ok' => true]);
    }

    /**
     * POST /mercadopago/webhook  (ruta pública, sin auth)
     * Recibe la notificación de MP y registra el pago en caja_movimientos.
     * Siempre retorna 200 para evitar reintentos de MP.
     */
    public function webhook(): void {
        $rawBody = file_get_contents('php://input');
        $data    = json_decode($rawBody, true) ?: [];

        // Solo procesar notificaciones de tipo "payment"
        if (($data['type'] ?? '') !== 'payment') {
            json(200, ['ok' => true]);
        }

        $paymentId  = (string)($data['data']['id'] ?? '');
        $xSignature = $_SERVER['HTTP_X_SIGNATURE']   ?? '';
        $xRequestId = $_SERVER['HTTP_X_REQUEST_ID']  ?? '';

        $config = Configuracion::get();
        $token  = $config['mp_access_token']   ?? '';
        $secret = $config['mp_webhook_secret'] ?? '';

        if ($token === '') {
            json(200, ['ok' => true, 'skipped' => 'mp no configurado']);
        }

        // Con secret configurado la firma es obligatoria: una notificación sin
        // header x-signature no puede saltearse la verificación.
        if ($secret !== '') {
            if ($xSignature === '' || !MercadoPago::verificarWebhook($xSignature, $xRequestId, $paymentId, $secret)) {
                error_log('[MP Webhook] Firma inválida o ausente para payment_id=' . $paymentId);
                json(200, ['ok' => true, 'skipped' => 'firma inválida']);
            }
        }

        try {
            $pago = MercadoPago::consultarPago($paymentId, $token);
        } catch (RuntimeException $e) {
            error_log('[MP Webhook] Error consultando pago ' . $paymentId . ': ' . $e->getMessage());
            json(200, ['ok' => true, 'skipped' => 'error consultando pago']);
        }

        if (($pago['status'] ?? '') !== 'approved') {
            json(200, ['ok' => true, 'skipped' => 'pago no aprobado: ' . ($pago['status'] ?? 'desconocido')]);
        }

        $ventaId = (int)($pago['external_reference'] ?? 0);
        $monto   = (float)($pago['transaction_amount'] ?? 0);
        if ($ventaId <= 0 || $monto <= 0) {
            json(200, ['ok' => true, 'skipped' => 'datos insuficientes']);
        }

        $db   = DB::get();
        $stmt = $db->prepare("SELECT id, caja_id FROM ventas WHERE id = ? AND estado != 'anulado'");
        $stmt->execute([$ventaId]);
        $venta = $stmt->fetch();
        if (!$venta) {
            json(200, ['ok' => true, 'skipped' => 'venta no encontrada']);
        }

        // Buscar turno activo para esa caja
        $stmt = $db->prepare("
            SELECT id FROM caja_turnos
            WHERE caja_id = ? AND estado = 'abierto'
            ORDER BY abierto_en DESC LIMIT 1
        ");
        $stmt->execute([$venta['caja_id']]);
        $turno = $stmt->fetch();

        if (!$turno) {
            error_log('[MP Webhook] Pago aprobado pero sin turno abierto para caja_id=' . $venta['caja_id'] . ' venta_id=' . $ventaId . ' monto=' . $monto . ' payment_id=' . $paymentId);
            json(200, ['ok' => true, 'skipped' => 'sin turno activo']);
        }

        // Idempotencia: MP reintenta notificaciones. La unique key sobre
        // payment_id garantiza un solo ingreso en caja por pago.
        try {
            $db->beginTransaction();
            $db->prepare("INSERT INTO mp_pagos (payment_id, venta_id, monto, origen) VALUES (?, ?, ?, 'webhook')")
               ->execute([$paymentId, $ventaId, $monto]);
            $db->prepare("
                INSERT INTO caja_movimientos (turno_id, tipo, medio_pago, monto, motivo, usuario_id, creado_en)
                VALUES (?, 'ingreso', 'mercado_pago', ?, ?, 0, NOW())
            ")->execute([
                $turno['id'],
                $monto,
                'MercadoPago #' . $paymentId,
            ]);
            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            if ($e->getCode() === '23000') {
                json(200, ['ok' => true, 'skipped' => 'pago ya registrado']);
            }
            throw $e;
        }

        json(200, ['ok' => true]);
    }
}
