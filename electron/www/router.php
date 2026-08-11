<?php
/**
 * PHP built-in server router for Logos POS.
 *
 * Maps /Logos/* requests to the actual app directory.
 * In development: LOGOS_APP_DIR env var points to the Logos source tree.
 * In production:  falls back to __DIR__/Logos (files are copied there during build).
 *
 * Handles:
 *   - / (y cualquier ruta que no empiece con /Logos/) → SPA de SvelteKit (app/build),
 *     servida en la raíz del dominio con fallback a index.html para el ruteo
 *     client-side. Ver LOGOS_APP_BUILD_DIR más abajo.
 *   - /Logos/api/* → routes through api/index.php (replicates mod_rewrite from api/.htaccess)
 *   - /Logos/pos/*.html → executes as PHP (replicates AddType from pos/.htaccess) —
 *     legacy, todavía necesario para instalar.html (fuera de alcance de la migración)
 *   - /Logos/*.php → executes as PHP
 *   - /Logos/* (static) → served directly with correct MIME type
 */

$appDir = rtrim(
    getenv('LOGOS_APP_DIR') ?: __DIR__ . DIRECTORY_SEPARATOR . 'Logos',
    '/\\'
);

$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Todo lo que no sea /Logos/* es la SPA nueva, servida en la raíz del dominio
// (mismo esquema que usa en dev el proxy de Vite: la app vive en "/", y le
// pega a "/Logos/api/*" para el backend).
if (strpos($uri, '/Logos') !== 0) {
    serveSpa($uri);
    return true;
}

if ($uri === '/Logos' || $uri === '/Logos/') {
    header('Location: /Logos/pos/index.html');
    exit;
}

// Health check for Electron's waitForUrl
if ($uri === '/Logos/ping') {
    http_response_code(200);
    echo 'ok';
    return true;
}

// Strip the /Logos prefix
$subpath  = substr($uri, strlen('/Logos'));
$filePath = $appDir . str_replace('/', DIRECTORY_SEPARATOR, $subpath ?: '/');

// ── API routing: /Logos/api/* ─────────────────────────────────────────────────
if (strpos($uri, '/Logos/api') === 0) {
    if (!file_exists($filePath) || is_dir($filePath)) {
        $apiIndex = $appDir . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'index.php';
        if (file_exists($apiIndex)) {
            chdir(dirname($apiIndex));
            require $apiIndex;
        } else {
            http_response_code(503);
            echo json_encode(['error' => 'API no disponible']);
        }
        return true;
    }
}

// Directory request → look for index.html (executed as PHP)
if (is_dir($filePath)) {
    $candidates = ['index.html', 'index.php'];
    foreach ($candidates as $idx) {
        $indexFile = rtrim($filePath, '/\\') . DIRECTORY_SEPARATOR . $idx;
        if (file_exists($indexFile)) {
            chdir(dirname($indexFile));
            require $indexFile;
            return true;
        }
    }
    http_response_code(403);
    echo "Directory listing not allowed";
    return true;
}

// File not found
if (!file_exists($filePath)) {
    http_response_code(404);
    echo "Not found: $uri";
    return true;
}

// ── pos/*.html executed as PHP ────────────────────────────────────────────────
// Sin esto, el navegador puede quedarse con una versión vieja de la página en
// caché (a diferencia de los estáticos de serveStatic(), acá no había ningún
// header de caché) — se notó recién al actualizar la app y no verse UI nueva.
if (preg_match('#^/Logos/pos/[^/]+\.html$#', $uri)) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    chdir(dirname($filePath));
    require $filePath;
    return true;
}

// ── .php files executed ───────────────────────────────────────────────────────
if (substr($filePath, -4) === '.php') {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    chdir(dirname($filePath));
    require $filePath;
    return true;
}

// ── Static assets ─────────────────────────────────────────────────────────────
serveStatic($filePath);
return true;

// ─────────────────────────────────────────────────────────────────────────────
// Sirve la SPA de SvelteKit (adapter-static) en la raíz del dominio.
// LOGOS_APP_BUILD_DIR: override para dev (el build real vive en app/build del
// repo, no dentro de electron/www/). En producción, __DIR__/app ya coincide
// con lo que empaqueta electron-builder.yml (www/app, sibling de www/Logos).
function serveSpa(string $uri): void
{
    $buildDir = rtrim(
        getenv('LOGOS_APP_BUILD_DIR') ?: __DIR__ . DIRECTORY_SEPARATOR . 'app',
        '/\\'
    );

    if (!is_dir($buildDir)) {
        http_response_code(503);
        echo 'App no disponible: build no encontrado en ' . $buildDir;
        return;
    }

    $path = str_replace('/', DIRECTORY_SEPARATOR, rtrim($uri, '/'));
    $file = $buildDir . $path;

    // Archivo estático real (JS/CSS/fuentes de _app/, robots.txt, favicon, etc.)
    if ($path !== '' && is_file($file)) {
        serveStatic($file);
        return;
    }

    // Fallback SPA: cualquier otra ruta la resuelve el router client-side
    $index = $buildDir . DIRECTORY_SEPARATOR . 'index.html';
    if (is_file($index)) {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Content-Type: text/html; charset=UTF-8');
        readfile($index);
        return;
    }

    http_response_code(404);
    echo "Not found: $uri";
}

function serveStatic(string $file): void
{
    static $mimes = [
        'html'  => 'text/html; charset=UTF-8',
        'css'   => 'text/css',
        'js'    => 'application/javascript; charset=UTF-8',
        'mjs'   => 'application/javascript; charset=UTF-8',
        'json'  => 'application/json',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'eot'   => 'application/vnd.ms-fontobject',
        'pdf'   => 'application/pdf',
        'csv'   => 'text/csv',
        'xml'   => 'application/xml',
        'map'   => 'application/json',
        'txt'   => 'text/plain',
    ];

    $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = $mimes[$ext] ?? 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($file));

    // Cache static assets for 1 hour
    if (in_array($ext, ['css', 'js', 'mjs', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf'])) {
        header('Cache-Control: public, max-age=3600');
    } else {
        header('Cache-Control: no-cache');
    }

    readfile($file);
}
