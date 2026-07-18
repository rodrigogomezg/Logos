<?php

// Router para el servidor embebido de PHP que usa la suite de tests
// (tools/test_integracion.php). Reenvía /Logos/api/* a api/index.php
// preservando REQUEST_URI, que es lo único que el router de la API necesita.
//
// Uso (lo hace la suite automáticamente):
//   LOGOS_DB_NAME=logos_test php -S 127.0.0.1:8123 tools/test_router.php

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($uri, '/Logos/api')) {
    require __DIR__ . '/../api/index.php';
    return true;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'El servidor de tests solo atiende /Logos/api/*']);
