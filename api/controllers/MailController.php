<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Configuracion.php';
require_once __DIR__ . '/../helpers/ComprobanteGenerador.php';
require_once __DIR__ . '/../helpers/Mail.php';

class MailController {

    public function enviar(): void {
        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $ventaId = (int)($body['venta_id'] ?? 0);
        $para    = trim($body['para'] ?? '');
        $asunto  = trim($body['asunto'] ?? '');

        if (!$ventaId) json(400, ['error' => 'venta_id requerido']);
        if (!$para || !filter_var($para, FILTER_VALIDATE_EMAIL)) {
            json(400, ['error' => 'Dirección de email inválida']);
        }

        $config = Configuracion::get();
        $this->validarSmtp($config);

        try {
            $result = ComprobanteGenerador::generarPdf($ventaId);
            $v      = $result['venta'];
            if ($asunto === '') {
                $asunto = ($v['tipo_comprobante'] ?? 'Comprobante')
                        . ' N° ' . $v['numero']
                        . ' — ' . ($config['nombre_fantasia'] ?: $config['razon_social']);
            }
            Mail::enviarComprobante($config, $v, $result['pdf'], $para, $asunto);
            json(200, ['ok' => true]);
        } catch (\Throwable $e) {
            json(500, ['error' => 'No se pudo enviar el mail: ' . $e->getMessage()]);
        }
    }

    public function probar(): void {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $para = trim($body['para'] ?? '');
        if (!$para || !filter_var($para, FILTER_VALIDATE_EMAIL)) {
            json(400, ['error' => 'Dirección de email inválida']);
        }

        $config = Configuracion::get();
        $this->validarSmtp($config);

        try {
            Mail::probar($config, $para);
            json(200, ['ok' => true]);
        } catch (\Throwable $e) {
            json(500, ['error' => 'No se pudo conectar al servidor SMTP: ' . $e->getMessage()]);
        }
    }

    private function validarSmtp(array $config): void {
        if (empty($config['smtp_host']) || empty($config['smtp_usuario']) ||
            empty($config['smtp_clave']) || empty($config['smtp_de_email'])) {
            json(503, ['error' => 'La configuración SMTP está incompleta. Completala en Configuración → Configuración avanzada.']);
        }
    }
}
