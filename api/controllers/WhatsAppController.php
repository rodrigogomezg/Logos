<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Configuracion.php';
require_once __DIR__ . '/../helpers/WhatsApp.php';
require_once __DIR__ . '/../helpers/ComprobanteGenerador.php';

class WhatsAppController {

    /**
     * POST /whatsapp/enviar
     * Body: { venta_id: int, telefono?: string }
     * Genera el PDF del comprobante y lo envía al cliente por WhatsApp.
     */
    public function enviar(): void {
        $config = Configuracion::get();
        if (empty($config['wa_phone_id']) || empty($config['wa_token'])) {
            json(400, ['error' => 'WhatsApp no está configurado. Ingresá el Phone ID y el Token en Configuración.']);
        }

        $body    = json_decode(file_get_contents('php://input'), true) ?: [];
        $ventaId = isset($body['venta_id']) && is_numeric($body['venta_id']) ? (int)$body['venta_id'] : 0;
        if ($ventaId <= 0) json(400, ['error' => 'venta_id inválido']);

        // Determinar teléfono: body > cliente registrado
        $telefonoRaw = trim($body['telefono'] ?? '');
        if ($telefonoRaw === '') {
            // Intentar obtener de la venta/cliente
            $stmt = DB::get()->prepare("
                SELECT c.telefono FROM ventas v
                LEFT JOIN clientes c ON c.id = v.cliente_id
                WHERE v.id = ?
            ");
            $stmt->execute([$ventaId]);
            $row = $stmt->fetch();
            $telefonoRaw = trim($row['telefono'] ?? '');
        }

        if ($telefonoRaw === '') {
            json(400, ['error' => 'No hay teléfono para este cliente. Ingresá el número manualmente.']);
        }

        $telefono = self::formatearE164($telefonoRaw);
        if ($telefono === '') {
            json(400, ['error' => 'Número de teléfono inválido: "' . $telefonoRaw . '". Usá formato +549XXXXXXXXXX o 11XXXXXXXX.']);
        }

        // Generar PDF
        try {
            ['pdf' => $pdfBin, 'venta' => $venta] = ComprobanteGenerador::generarPdf($ventaId);
        } catch (Throwable $e) {
            json(500, ['error' => 'Error al generar el PDF: ' . $e->getMessage()]);
        }

        $filename = 'Comprobante_' . str_replace([' ', '/'], '_', $venta['tipo_comprobante'])
                  . '_' . $venta['numero'] . '.pdf';

        $nombreCliente = $venta['cliente_nombre'] ?: 'Cliente';
        $totalFormato  = '$' . number_format((float)$venta['total'], 2, ',', '.');

        $params = [
            $nombreCliente,
            $venta['tipo_comprobante'],
            $venta['numero'],
            $totalFormato,
        ];

        try {
            $result = WhatsApp::enviarComprobante($telefono, $pdfBin, $filename, $params, $config);
        } catch (RuntimeException $e) {
            json(502, ['error' => $e->getMessage()]);
        }

        json(200, ['ok' => true, 'message_id' => $result['message_id']]);
    }

    /**
     * Convierte un teléfono argentino al formato E.164 (+549XXXXXXXXXX).
     * Retorna string vacío si no se puede interpretar.
     */
    private static function formatearE164(string $telefono): string {
        // Quitar todo lo que no sea dígito
        $digits = preg_replace('/\D/', '', $telefono);
        if (strlen($digits) < 8) return '';

        // Ya tiene código de país completo: +54 9 11...  → 549...
        if (str_starts_with($digits, '549') && strlen($digits) >= 12) return $digits;
        if (str_starts_with($digits, '54')  && strlen($digits) >= 12) return $digits;

        // Número local argentino: 011XXXXXXXX → 54911XXXXXXXX
        //   ó 11XXXXXXXX → 54911XXXXXXXX
        //   ó 9XXXXXXXXX → 549XXXXXXXXX
        if (str_starts_with($digits, '0')) $digits = substr($digits, 1); // quitar 0 inicial
        if (str_starts_with($digits, '9') && strlen($digits) === 10) return '54' . $digits;
        if (strlen($digits) === 10) return '549' . $digits;

        return '';
    }
}
