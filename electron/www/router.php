<?php
/**
 * PHP built-in server router for Logos POS.
 *
 * Maps /Logos/* requests to the actual app directory.
 * In development: LOGOS_APP_DIR env var points to the Logos source tree.
 * In production:  falls back to __DIR__/Logos (files are copied there during build).
 *
 * Handles:
 *   - /Logos/api/* → routes through api/index.php (replicates mod_rewrite from api/.htaccess)
 *   - /Logos/pos/*.html → executes as PHP (replicates AddType from pos/.htaccess)
 *   - /Logos/*.php → executes as PHP
 *   - /Logos/* (static) → served directly with correct MIME type
 */

$appDir = rtrim(
    getenv('LOGOS_APP_DIR') ?: __DIR__ . DIRECTORY_SEPARATOR . 'Logos',
    '/\\'
);

$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Root → redirect to app
if ($uri === '/' || $uri === '') {
    header('Location: /Logos/pos/index.html');
    exit;
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

if (strpos($uri, '/Logos/') !== 0) {
    http_response_code(404);
    echo "Not found: $uri";
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
if (preg_match('#^/Logos/pos/[^/]+\.html$#', $uri)) {
    chdir(dirname($filePath));
    require $filePath;
    return true;
}

// ── .php files executed ───────────────────────────────────────────────────────
if (substr($filePath, -4) === '.php') {
    chdir(dirname($filePath));
    require $filePath;
    return true;
}

// ── Static assets ─────────────────────────────────────────────────────────────
serveStatic($filePath);
return true;

// ─────────────────────────────────────────────────────────────────────────────
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
