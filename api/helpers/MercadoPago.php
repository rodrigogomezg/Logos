<?php

class MercadoPago {

    const BASE = 'https://api.mercadopago.com';

    /**
     * Crea una preferencia de pago en MP.
     * Retorna ['id' => preference_id, 'init_point' => url_de_pago]
     */
    public static function crearPreferencia(array $venta, string $token, string $webhookUrl): array {
        $titulo = $venta['tipo_comprobante'] . ' #' . $venta['numero'];
        $body = [
            'items' => [[
                'title'     => $titulo,
                'quantity'  => 1,
                'unit_price'=> (float)$venta['total'],
                'currency_id' => 'ARS',
            ]],
            'external_reference' => (string)$venta['id'],
            'notification_url'   => $webhookUrl,
            'statement_descriptor' => 'LOGOS POS',
        ];

        if (!empty($venta['cliente_nombre']) && $venta['cliente_nombre'] !== 'Consumidor final') {
            $partes = explode(' ', trim($venta['cliente_nombre']), 2);
            $body['payer'] = [
                'name'    => $partes[0],
                'surname' => $partes[1] ?? '',
            ];
        }

        return self::request('POST', '/checkout/preferences', $body, $token);
    }

    /**
     * Consulta un pago por su ID.
     * Retorna los datos del pago (status, external_reference, transaction_amount, etc.)
     */
    public static function consultarPago(string $paymentId, string $token): array {
        return self::request('GET', '/v1/payments/' . $paymentId, null, $token);
    }

    /**
     * Verifica la firma del webhook de MP (x-signature header).
     * manifest = "id:{dataId};request-id:{xRequestId};ts:{ts};"
     */
    public static function verificarWebhook(string $xSignature, string $xRequestId, string $dataId, string $secret): bool {
        // Parsear ts y v1 de x-signature: "ts=...,v1=..."
        $parts = [];
        foreach (explode(',', $xSignature) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[trim($k)] = trim($v);
        }

        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';
        if ($ts === '' || $v1 === '') return false;

        $manifest = "id:{$dataId};request-id:{$xRequestId};ts:{$ts};";
        $expected = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($expected, $v1);
    }

    private static function request(string $method, string $path, ?array $body, string $token): array {
        $ch = curl_init(self::BASE . $path);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'X-Idempotency-Key: logos-' . uniqid(),
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            throw new RuntimeException('MercadoPago: error de conexión (' . curl_strerror($errno) . ')');
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new RuntimeException('MercadoPago: respuesta inválida');
        }

        if ($httpCode >= 400) {
            $msg = $data['message'] ?? $data['error'] ?? 'Error HTTP ' . $httpCode;
            throw new RuntimeException('MercadoPago: ' . $msg);
        }

        return $data;
    }
}
