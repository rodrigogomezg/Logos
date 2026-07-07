<?php

class Seguridad {
    
    /**
     * Valida origen de la solicitud (same-origin)
     */
    public static function validarOrigen(): void {
        if (in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true)) {
            return;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        
        if ($origin && strpos($origin, $host) === false) {
            http_response_code(403);
            echo json_encode(['error' => 'Cross-origin request no permitido']);
            exit;
        }
    }
    
    /**
     * Headers de seguridad
     */
    public static function agregarHeadersSeguridad(): void {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}
