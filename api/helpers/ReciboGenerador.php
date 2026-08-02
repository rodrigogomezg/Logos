<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/Configuracion.php';

class ReciboGenerador {

    /**
     * Genera el PDF de un pago de cuenta corriente de cliente.
     * Devuelve ['pdf' => string_binario, 'nRecibo' => string]
     *
     * Solo aplica a pagos de clientes. Pagos a proveedores quedan fuera de
     * alcance por ahora: la pantalla de CC de proveedores no tiene botón de
     * recibo y este generador no soporta esa rama.
     */
    public static function generarPdf(int $movId): array {
        $db = DB::get();

        // 1. Movimiento
        $stmt = $db->prepare("
            SELECT id, tipo, monto, fecha, medio_pago, pago_datos, observaciones,
                   entidad_tipo, entidad_id
            FROM cuenta_corriente_movimientos
            WHERE id = ?
        ");
        $stmt->execute([$movId]);
        $mov = $stmt->fetch();

        if (!$mov) json(404, ['error' => 'Movimiento no encontrado']);
        if ($mov['tipo'] !== 'pago') json(422, ['error' => 'El movimiento no corresponde a un pago']);
        if ($mov['entidad_tipo'] !== 'cliente') {
            json(422, ['error' => 'Los recibos de pagos a proveedores no están disponibles aún']);
        }

        $mov['monto']      = (float)$mov['monto'];
        $mov['pago_datos'] = $mov['pago_datos'] ? json_decode($mov['pago_datos'], true) : null;

        // 2. Cliente
        $stmt = $db->prepare("
            SELECT id, nombre, cuit, condicion_iva
            FROM clientes
            WHERE id = ?
        ");
        $stmt->execute([(int)$mov['entidad_id']]);
        $cliente = $stmt->fetch();
        if (!$cliente) json(404, ['error' => 'Cliente no encontrado']);

        // 3. Asignaciones: ventas CC + cargos manuales (envíos, etc.)
        $stmt = $db->prepare("
            SELECT a.monto, v.id AS venta_id, v.tipo_comprobante AS venta_tipo, NULL AS cargo_id, NULL AS cargo_label
            FROM cc_asignaciones a
            JOIN ventas v ON v.id = a.venta_id
            WHERE a.movimiento_id = ?

            UNION ALL

            SELECT a.monto, NULL AS venta_id, NULL AS venta_tipo, m.id AS cargo_id,
                   CASE WHEN ne.id IS NOT NULL THEN CONCAT('Envío N°', ne.numero)
                        ELSE COALESCE(m.comprobante, 'Cargo manual')
                   END AS cargo_label
            FROM cc_asignaciones a
            JOIN cuenta_corriente_movimientos m ON m.id = a.cargo_id
            LEFT JOIN notas_envio ne ON ne.id = m.referencia_id
            WHERE a.movimiento_id = ? AND a.cargo_id IS NOT NULL

            ORDER BY venta_id ASC, cargo_id ASC
        ");
        $stmt->execute([$movId, $movId]);
        $asignaciones = $stmt->fetchAll();
        foreach ($asignaciones as &$a) { $a['monto'] = (float)$a['monto']; }
        unset($a);

        $mov['asignaciones'] = $asignaciones;

        // 4. Config empresa + número de recibo PPPP-NNNNNNNN
        $config  = Configuracion::get();
        $nRecibo = sprintf('%04d', (int)($config['punto_venta'] ?? 1))
                 . '-'
                 . sprintf('%08d', $movId);

        // 5. Renderizar plantilla → dompdf
        ob_start();
        require __DIR__ . '/../../pos/templates/recibo_cc_pdf.php';
        $html = ob_get_clean();

        $options = new Dompdf\Options(['isRemoteEnabled' => true]);
        $options->setChroot([realpath(__DIR__ . '/../..')]);
        $dompdf = new Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return ['pdf' => $dompdf->output(), 'nRecibo' => $nRecibo];
    }
}
