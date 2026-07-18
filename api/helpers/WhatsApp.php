<?php

class WhatsApp {

    const GRAPH = 'https://graph.facebook.com/v20.0';

    /**
     * Envía el comprobante PDF al cliente por WhatsApp usando Cloud API.
     * 1. Sube el PDF a Meta media
     * 2. Envía mensaje de plantilla con el PDF como header
     *
     * $params = [nombre_cliente, tipo_comprobante, nro_comprobante, total_formateado]
     * $config = ['wa_phone_id'=>..., 'wa_token'=>..., 'wa_template_name'=>...]
     *
     * Retorna ['message_id' => string]
     */
    public static function enviarComprobante(
        string $telefono,
        string $pdfBin,
        string $filename,
        array  $params,
        array  $config
    ): array {
        $mediaId = self::subirMedia($pdfBin, $filename, $config);
        return self::enviarTemplate($telefono, $mediaId, $filename, $params, $config);
    }

    /**
     * Sube un PDF a Meta media API.
     * Retorna el media_id string.
     */
    private static function subirMedia(string $pdfBin, string $filename, array $config): string {
        $url      = self::GRAPH . '/' . $config['wa_phone_id'] . '/media';
        $boundary = '----FormBoundary' . bin2hex(random_bytes(8));

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"messaging_product\"\r\n\r\nwhatsapp\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"type\"\r\n\r\napplication/pdf\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: application/pdf\r\n\r\n";
        $body .= $pdfBin . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $data = self::curl('POST', $url, $body, [
            'Authorization: Bearer ' . $config['wa_token'],
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ]);

        if (empty($data['id'])) {
            throw new RuntimeException('WhatsApp: no se obtuvo media_id. Respuesta: ' . json_encode($data));
        }

        return (string)$data['id'];
    }

    /**
     * Envía un mensaje de plantilla con documento en el header.
     * Retorna ['message_id' => string]
     */
    private static function enviarTemplate(
        string $to,
        string $mediaId,
        string $filename,
        array  $params,
        array  $config
    ): array {
        $url  = self::GRAPH . '/' . $config['wa_phone_id'] . '/messages';
        $body = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'     => $config['wa_template_name'] ?: 'envio_comprobante',
                'language' => ['code' => 'es_AR'],
                'components' => [
                    [
                        'type'       => 'header',
                        'parameters' => [[
                            'type'     => 'document',
                            'document' => [
                                'id'       => $mediaId,
                                'filename' => $filename,
                            ],
                        ]],
                    ],
                    [
                        'type'       => 'body',
                        'parameters' => array_map(
                            fn($p) => ['type' => 'text', 'text' => (string)$p],
                            $params
                        ),
                    ],
                ],
            ],
        ];

        $data = self::curl('POST', $url, json_encode($body), [
            'Authorization: Bearer ' . $config['wa_token'],
            'Content-Type: application/json',
        ]);

        $messageId = $data['messages'][0]['id'] ?? null;
        if (!$messageId) {
            throw new RuntimeException('WhatsApp: no se obtuvo message_id. Respuesta: ' . json_encode($data));
        }

        return ['message_id' => $messageId];
    }

    private static function curl(string $method, string $url, string|array $body, array $headers): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            throw new RuntimeException('WhatsApp: error de conexión (' . curl_strerror($errno) . ')');
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new RuntimeException('WhatsApp: respuesta inválida del servidor');
        }

        if ($httpCode >= 400) {
            $msg = $data['error']['message'] ?? $data['error']['type'] ?? 'Error HTTP ' . $httpCode;
            throw new RuntimeException('WhatsApp: ' . $msg);
        }

        return $data;
    }
}
