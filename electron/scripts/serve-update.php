<?php
/**
 * Logos POS — Proxy de descarga para el instalador de actualizaciones.
 *
 * Hostinger bloquea la descarga directa de .exe via Apache.
 * Este script sirve el archivo desde PHP, que no tiene esa restricción.
 *
 * INSTALACIÓN (una sola vez):
 *   Subir este archivo a Hostinger en:
 *   /home/u135285498/domains/drilogs.com.ar/public_html/logos/serve-update.php
 *
 * URL de descarga resultante:
 *   https://logos.drilogs.com.ar/serve-update.php?f=Logos+POS+Setup+1.0.1.exe
 */

// Disable any compression or output buffering that could corrupt binary content
// or inject extra bytes (Hostinger/LiteSpeed may buffer PHP output)
@ini_set('zlib.output_compression', 'off');
@ini_set('output_buffering', 'off');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
    @apache_setenv('dont-vary', '1');
}
while (ob_get_level()) { ob_end_clean(); }

// Solo servir archivos que coincidan con el patrón del instalador
$file = basename(urldecode($_GET['f'] ?? ''));
if (!preg_match('/^Logos POS Setup \d+\.\d+\.\d+\.exe$/', $file)) {
    http_response_code(404);
    exit('Not Found');
}

$filePath = __DIR__ . '/' . $file;
if (!is_file($filePath)) {
    http_response_code(404);
    exit('File Not Found: ' . htmlspecialchars($file));
}

$size = filesize($filePath);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . $size);
header('Content-Encoding: identity');   // explicitly no compression
header('Cache-Control: no-cache, no-store');
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: none');

// Transmitir en bloques de 256 KB para no cargar el archivo entero en memoria
@set_time_limit(0);
@ignore_user_abort(false);

$fp = fopen($filePath, 'rb');
if ($fp === false) {
    http_response_code(500);
    exit('Cannot open file');
}

while (!feof($fp)) {
    $chunk = fread($fp, 262144);
    if ($chunk === false) break;
    echo $chunk;
    flush();
}
fclose($fp);
