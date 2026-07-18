<?php

// Suite de tests de integración de BRON. Corre la API real (servidor embebido
// de PHP) contra una base de prueba `logos_test` que se recrea desde
// install/schema_limpio.sql en cada corrida. No toca la base productiva ni
// db.local.php: el server de tests recibe la base por variables de entorno.
//
// Uso:  php tools/test_integracion.php
// Requiere: MySQL corriendo, acceso root (pass vacía por defecto de XAMPP,
// o seteá LOGOS_TEST_ROOT_USER / LOGOS_TEST_ROOT_PASS).

declare(strict_types=1);

const TEST_DB   = 'logos_test';
const TEST_HOST = '127.0.0.1';
const TEST_PORT = 8123;
const BASE_URL  = 'http://' . TEST_HOST . ':' . TEST_PORT . '/Logos/api';
const TOKEN     = 'token-suite-integracion';

$rootUser = getenv('LOGOS_TEST_ROOT_USER') ?: 'root';
$rootPass = getenv('LOGOS_TEST_ROOT_PASS') ?: '';

// ── Infraestructura ───────────────────────────────────────────────────

function pdoTest(string $user, string $pass, ?string $db = null): PDO {
    $dsn = 'mysql:host=127.0.0.1;port=3306' . ($db ? ";dbname=$db" : '') . ';charset=utf8mb4';
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function http(string $metodo, string $ruta, ?array $body = null, ?string $token = TOKEN): array {
    $headers = ['Content-Type: application/json'];
    if ($token !== null) $headers[] = 'X-Auth-Token: ' . $token;
    $ctx = stream_context_create(['http' => [
        'method'        => $metodo,
        'header'        => implode("\r\n", $headers),
        'content'       => $body !== null ? json_encode($body) : '',
        'ignore_errors' => true,
        'timeout'       => 30,
    ]]);
    $resp = file_get_contents(BASE_URL . $ruta, false, $ctx);
    preg_match('/ (\d{3}) /', $http_response_header[0] ?? ' 000 ', $m);
    return ['status' => (int)$m[1], 'json' => json_decode((string)$resp, true)];
}

$pasadas = 0; $falladas = 0;
function check(string $nombre, bool $cond, string $detalle = ''): void {
    global $pasadas, $falladas;
    if ($cond) { $pasadas++;  echo "  OK    $nombre\n"; }
    else       { $falladas++; echo "  FALLA $nombre" . ($detalle ? " — $detalle" : '') . "\n"; }
}

// ── 1. Recrear base de prueba ─────────────────────────────────────────

echo "» Recreando base " . TEST_DB . "…\n";
try {
    $root = pdoTest($rootUser, $rootPass);
} catch (PDOException $e) {
    fwrite(STDERR, "No se pudo conectar como root a MySQL: {$e->getMessage()}\n" .
        "Seteá LOGOS_TEST_ROOT_USER / LOGOS_TEST_ROOT_PASS si root tiene contraseña.\n");
    exit(2);
}
$root->exec('DROP DATABASE IF EXISTS `' . TEST_DB . '`');
$root->exec('CREATE DATABASE `' . TEST_DB . '` CHARACTER SET utf8mb4');

// Usuario propio para la suite, con contraseña NO vacía: en Windows una
// variable de entorno con valor vacío no llega al proceso hijo (proc_open la
// descarta), así que una pass vacía no se puede propagar al server embebido.
foreach (['localhost', '127.0.0.1'] as $h) {
    $root->exec("DROP USER IF EXISTS 'logos_test'@'$h'");
    $root->exec("CREATE USER 'logos_test'@'$h' IDENTIFIED BY 'logos_test_pass'");
    $root->exec("GRANT ALL PRIVILEGES ON `" . TEST_DB . "`.* TO 'logos_test'@'$h'");
}

$root->exec('USE `' . TEST_DB . '`');
$schema = file_get_contents(__DIR__ . '/../install/schema_limpio.sql');
if (!$schema) { fwrite(STDERR, "No se encontró install/schema_limpio.sql\n"); exit(2); }
$root->exec($schema);

$tablas = $root->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . TEST_DB . "'")->fetchColumn();
echo "  esquema cargado ($tablas tablas)\n";

// ── 2. Seed mínimo ────────────────────────────────────────────────────

$db = pdoTest($rootUser, $rootPass, TEST_DB);
$db->exec("INSERT INTO configuracion (id, razon_social, cuit, punto_venta, iva_porcentaje) VALUES (1, 'TEST SRL', '20111111112', 1, 21)");
$db->prepare("INSERT INTO usuarios (id, nombre, pin_hash, rol, activo) VALUES (1, 'admin-test', ?, 'admin', 1)")
   ->execute([password_hash('1234', PASSWORD_DEFAULT)]);
// Sucursal y depósito requeridos como FK desde migraciones 44-45
$db->exec("INSERT INTO sucursales (id, nombre, activo) VALUES (1, 'Sucursal Test', 1)");
$db->exec("INSERT INTO depositos (id, sucursal_id, nombre, es_principal, activo) VALUES (1, 1, 'Depósito Principal', 1, 1)");
$db->exec("INSERT INTO cajas (id, sucursal_id, nombre, tipo, activo) VALUES (1, 1, 'Caja Test', 'venta', 1), (2, 1, 'Caja Sin Turno', 'venta', 1)");
$db->exec("INSERT INTO caja_turnos (id, caja_id, usuario_id, fondo_inicial, abierto_en, estado) VALUES (1, 1, 1, 1000, NOW(), 'abierto')");
$db->exec("INSERT INTO productos (id, codigo, nombre, precio_venta, costo_actual, stock_actual, activo, iva_porcentaje)
           VALUES (1, 'T21', 'Producto 21%', 121, 80, 100, 1, 21.00),
                  (2, 'T105', 'Producto 10.5%', 110.5, 70, 100, 1, 10.50)");
$db->exec("INSERT INTO clientes (id, nombre, limite_credito) VALUES (1, 'Cliente Test', 1000)");
$db->prepare("INSERT INTO sesiones (token_hash, usuario_id, creado_en, ultimo_uso, expira)
              VALUES (?, 1, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))")
   ->execute([hash('sha256', TOKEN)]);

// Usuario rol 'user' con permisos configurables por test (el server los lee
// por request, así que cambiarlos por SQL aplica al instante).
const TOKEN_USER = 'token-suite-user-limitado';
$db->prepare("INSERT INTO usuarios (id, nombre, pin_hash, rol, permisos, activo) VALUES (9, 'user-test', ?, 'user', '{}', 1)")
   ->execute([password_hash('9999', PASSWORD_DEFAULT)]);
$db->prepare("INSERT INTO sesiones (token_hash, usuario_id, creado_en, ultimo_uso, expira)
              VALUES (?, 9, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))")
   ->execute([hash('sha256', TOKEN_USER)]);

function setPermisos(PDO $db, array $permisos): void {
    $db->prepare("UPDATE usuarios SET permisos = ? WHERE id = 9")->execute([json_encode($permisos)]);
}
echo "  seed listo\n";

// ── 3. Levantar servidor embebido apuntado a la base de test ─────────

echo "» Levantando servidor de tests en " . TEST_HOST . ':' . TEST_PORT . "…\n";
$raiz = dirname(__DIR__);
$env  = array_merge(getenv(), [
    'LOGOS_DB_NAME' => TEST_DB,
    'LOGOS_DB_USER' => 'logos_test',
    'LOGOS_DB_PASS' => 'logos_test_pass',
]);
// stdout/stderr van a archivo, NO a pipes: el server embebido loguea cada
// request y si nadie drena el pipe, el buffer (~4KB en Windows) se llena y
// el proceso se bloquea a mitad de la suite.
$srvLog = sys_get_temp_dir() . '/logos_test_server.log';
$proc = proc_open(
    [PHP_BINARY, '-S', TEST_HOST . ':' . TEST_PORT, 'tools/test_router.php'],
    [1 => ['file', $srvLog, 'w'], 2 => ['file', $srvLog, 'w']],
    $pipes, $raiz, $env
);
if (!is_resource($proc)) { fwrite(STDERR, "No se pudo levantar el servidor embebido\n"); exit(2); }
register_shutdown_function(function () use ($proc) { @proc_terminate($proc); });

$listo = false;
for ($i = 0; $i < 40; $i++) {
    usleep(250_000);
    $r = @http('GET', '/configuracion', null, null);
    if ($r['status'] > 0) { $listo = true; break; }
}
if (!$listo) { fwrite(STDERR, "El servidor de tests no respondió\n"); exit(2); }
echo "  servidor arriba\n\n";

// ── 4. Tests ──────────────────────────────────────────────────────────

echo "» Auth\n";
$r = http('GET', '/ventas', null, null);
check('sin token → 401', $r['status'] === 401, "status={$r['status']}");
$r = http('GET', '/ventas', null, 'token-invalido');
check('token inválido → 401', $r['status'] === 401, "status={$r['status']}");

echo "» Ventas: ciclo básico\n";
$r = http('POST', '/ventas', [
    'tipo_pago' => 'efectivo', 'tipo_comprobante' => 'REMITO', 'caja_id' => 1,
    'items' => [['producto_id' => 1, 'cantidad' => 2, 'precio_unitario' => 121]],
]);
check('crear venta efectivo → 200', $r['status'] === 200, json_encode($r['json']));
$ventaEf = $r['json']['id'] ?? 0;
check('total correcto (242)', ($r['json']['total'] ?? 0) == 242);
$stock = (float)$db->query("SELECT stock_actual FROM productos WHERE id = 1")->fetchColumn();
check('stock descontado (98)', $stock == 98.0, "stock=$stock");

$r = http('POST', '/ventas', [
    'tipo_pago' => 'efectivo', 'tipo_comprobante' => 'REMITO', 'caja_id' => 2,
    'items' => [['producto_id' => 1, 'cantidad' => 1, 'precio_unitario' => 121]],
]);
check('venta en caja sin turno → 409', $r['status'] === 409, "status={$r['status']}");

$r = http('POST', '/ventas', [
    'tipo_pago' => 'mixto', 'tipo_comprobante' => 'REMITO', 'caja_id' => 1,
    'items' => [['producto_id' => 1, 'cantidad' => 1, 'precio_unitario' => 100]],
    'pagos' => [['tipo' => 'efectivo', 'monto' => 60], ['tipo' => 'tarjeta', 'monto' => 60]],
]);
check('pago mixto que no suma el total → 400', $r['status'] === 400, "status={$r['status']}");

echo "» Cuenta corriente: límite de crédito\n";
$r = http('POST', '/ventas', [
    'tipo_pago' => 'cc', 'tipo_comprobante' => 'REMITO', 'cliente_id' => 1, 'caja_id' => 1,
    'items' => [['producto_id' => 1, 'cantidad' => 1, 'precio_unitario' => 800]],
]);
check('venta CC dentro del límite → 200', $r['status'] === 200, json_encode($r['json']));
$ventaCc = $r['json']['id'] ?? 0;

$r = http('POST', '/ventas', [
    'tipo_pago' => 'cc', 'tipo_comprobante' => 'REMITO', 'cliente_id' => 1, 'caja_id' => 1,
    'items' => [['producto_id' => 1, 'cantidad' => 1, 'precio_unitario' => 800]],
]);
check('venta CC que supera el límite → 422', $r['status'] === 422, "status={$r['status']}");

$r = http('POST', '/ventas', [
    'tipo_pago' => 'cc', 'tipo_comprobante' => 'REMITO', 'caja_id' => 1,
    'items' => [['producto_id' => 1, 'cantidad' => 1, 'precio_unitario' => 100]],
]);
check('venta CC sin cliente → 422', $r['status'] === 422, "status={$r['status']}");

echo "» Anulación\n";
$r = http('DELETE', '/ventas/' . $ventaCc);
check('anular venta CC → 200', $r['status'] === 200, json_encode($r['json']));
$saldo = (float)$db->query("SELECT saldo_cuenta_corriente FROM clientes WHERE id = 1")->fetchColumn();
check('saldo CC revertido a 0', $saldo == 0.0, "saldo=$saldo");

$r = http('DELETE', '/ventas/' . $ventaCc);
check('re-anular la misma venta → 409', $r['status'] === 409, "status={$r['status']}");
$saldo = (float)$db->query("SELECT saldo_cuenta_corriente FROM clientes WHERE id = 1")->fetchColumn();
check('saldo CC sigue en 0 (no se corrompe)', $saldo == 0.0, "saldo=$saldo");

$db->prepare("UPDATE ventas SET cae = '75000000000000' WHERE id = ?")->execute([$ventaEf]);
$r = http('DELETE', '/ventas/' . $ventaEf);
check('anular venta con CAE → 422', $r['status'] === 422, "status={$r['status']}");
$r = http('PUT', '/ventas/' . $ventaEf, ['tipo_pago' => 'tarjeta']);
check('editar venta con CAE → 422', $r['status'] === 422, "status={$r['status']}");

echo "» MercadoPago: confirmación manual idempotente\n";
$db->prepare("UPDATE ventas SET cae = NULL WHERE id = ?")->execute([$ventaEf]);
$r = http('POST', '/mercadopago/confirmar-manual', ['venta_id' => $ventaEf]);
check('primera confirmación manual → 200', $r['status'] === 200, json_encode($r['json']));
$r = http('POST', '/mercadopago/confirmar-manual', ['venta_id' => $ventaEf]);
check('segunda confirmación manual → 409', $r['status'] === 409, "status={$r['status']}");
$movs = (int)$db->query("SELECT COUNT(*) FROM caja_movimientos WHERE medio_pago = 'mercado_pago'")->fetchColumn();
check('un solo ingreso MP en caja', $movs === 1, "movimientos=$movs");

echo "» Plazo de pago por cliente\n";
$r = http('POST', '/clientes', ['nombre' => 'Cliente Plazo 7', 'plazo_pago_dias' => 7]);
check('crear cliente con plazo 7 → 200', $r['status'] === 200 && ($r['json']['plazo_pago_dias'] ?? null) === 7, json_encode($r['json']));
$cliPlazo = $r['json']['id'] ?? 0;

$r = http('POST', '/clientes', ['nombre' => 'Cliente Sin Plazo']);
check('crear cliente sin plazo → plazo null', $r['status'] === 200 && array_key_exists('plazo_pago_dias', $r['json']) && $r['json']['plazo_pago_dias'] === null, json_encode($r['json']));
$cliSinPlazo = $r['json']['id'] ?? 0;

echo "» Aging de cuenta corriente\n";
$hace40 = date('Y-m-d', strtotime('-40 days'));

// Deuda vieja (vence a los 7 días → 33 días vencida) con pago parcial asignado
$r = http('POST', '/ventas', [
    'tipo_pago' => 'cc', 'tipo_comprobante' => 'REMITO', 'cliente_id' => $cliPlazo,
    'caja_id' => 1, 'fecha' => $hace40,
    'items' => [['producto_id' => 1, 'cantidad' => 1, 'precio_unitario' => 800]],
]);
check('venta CC retroactiva (-40d) → 200', $r['status'] === 200, json_encode($r['json']));
$ventaVieja = $r['json']['id'] ?? 0;

$r = http('POST', '/cc', [
    'entidad_tipo' => 'cliente', 'entidad_id' => $cliPlazo, 'tipo' => 'pago', 'monto' => 300,
    'asignaciones' => [['venta_id' => $ventaVieja, 'monto' => 300]],
]);
check('pago parcial 300 asignado → 200', $r['status'] === 200, json_encode($r['json']));

// Deuda fresca de hoy (a vencer)
$r = http('POST', '/ventas', [
    'tipo_pago' => 'cc', 'tipo_comprobante' => 'REMITO', 'cliente_id' => $cliPlazo, 'caja_id' => 1,
    'items' => [['producto_id' => 1, 'cantidad' => 1, 'precio_unitario' => 200]],
]);
check('venta CC de hoy → 200', $r['status'] === 200, json_encode($r['json']));

// Cliente sin plazo: deuda vieja pero nunca "vence"
$r = http('POST', '/ventas', [
    'tipo_pago' => 'cc', 'tipo_comprobante' => 'REMITO', 'cliente_id' => $cliSinPlazo,
    'caja_id' => 1, 'fecha' => $hace40,
    'items' => [['producto_id' => 1, 'cantidad' => 1, 'precio_unitario' => 100]],
]);
check('venta CC cliente sin plazo → 200', $r['status'] === 200, json_encode($r['json']));

$r = http('GET', '/cc/aging?entidad_tipo=cliente');
check('GET /cc/aging → 200', $r['status'] === 200, json_encode($r['json']));
$porNombre = array_column($r['json']['filas'] ?? [], null, 'nombre');

$f = $porNombre['Cliente Plazo 7'] ?? [];
check('aging: saldo 700 (800 - 300 + 200)', ($f['saldo'] ?? 0) == 700.0, json_encode($f));
check('aging: residual viejo 500 en bucket 31-60d', ($f['v31_60'] ?? 0) == 500.0, json_encode($f));
check('aging: deuda de hoy 200 a vencer', ($f['a_vencer'] ?? 0) == 200.0, json_encode($f));
check('aging: vencido total 500', ($f['vencido'] ?? 0) == 500.0, json_encode($f));
// DATEDIFF puede variar ±1 según el momento exacto de ejecución (boundary de medianoche)
check('aging: ~33 días de vencimiento máximo', in_array($f['max_dias_vencido'] ?? 0, [32, 33]), json_encode($f));

$f = $porNombre['Cliente Sin Plazo'] ?? [];
check('aging sin plazo: todo a vencer, nada vencido', ($f['a_vencer'] ?? 0) == 100.0 && ($f['vencido'] ?? 1) == 0.0, json_encode($f));

// Filtro por entidad (lo usa el mini-dashboard de la ficha)
$r = http('GET', '/cc/aging?entidad_tipo=cliente&entidad_id=' . $cliPlazo);
check('aging filtrado por entidad → 1 fila correcta',
    $r['status'] === 200 && count($r['json']['filas'] ?? []) === 1
    && ($r['json']['filas'][0]['vencido'] ?? 0) == 500.0,
    json_encode($r['json']));

echo "» Permisos granulares: usuario todo-en-false\n";
setPermisos($db, []);
foreach ([
    ['GET',  '/compras',                    'compras'],
    ['GET',  '/rentabilidad/resumen',       'costos'],
    ['GET',  '/cc?entidad_tipo=cliente&entidad_id=1', 'cc_ver'],
    ['GET',  '/cc/aging?entidad_tipo=cliente', 'cc_ver aging'],
    ['GET',  '/ventas/dashboard',           'reportes dashboard'],
    ['GET',  '/iva/ventas',                 'reportes iva'],
    ['GET',  '/log-acciones',               'log'],
] as [$met, $ruta, $nombre]) {
    $r = http($met, $ruta, null, TOKEN_USER);
    check("sin permiso: $nombre → 403", $r['status'] === 403, "status={$r['status']}");
}
$r = http('POST', '/cc', ['entidad_tipo' => 'cliente', 'entidad_id' => 1, 'tipo' => 'pago', 'monto' => 10], TOKEN_USER);
check('sin permiso: POST /cc → 403', $r['status'] === 403, "status={$r['status']}");
$r = http('PUT', '/productos/1', ['nombre' => 'Hackeado'], TOKEN_USER);
check('sin permiso: PUT /productos → 403', $r['status'] === 403, "status={$r['status']}");

echo "» Permisos granulares: reportes sin costos\n";
setPermisos($db, ['reportes' => true]);
$r = http('GET', '/ventas/dashboard', null, TOKEN_USER);
check('dashboard 200 sin campos de costo',
    $r['status'] === 200 && !isset($r['json']['total_costo']) && !isset($r['json']['ganancia_bruta'])
    && !isset($r['json']['margen_pct']) && !isset($r['json']['por_dia'][0]['ganancia'])
    && !isset($r['json']['top_productos'][0]['ganancia']),
    json_encode(array_keys($r['json'] ?? [])));
$r = http('GET', '/productos/1', null, TOKEN_USER);
check('producto sin costo_actual', $r['status'] === 200 && !isset($r['json']['costo_actual']), json_encode($r['json']));
$r = http('GET', '/productos/1', null, TOKEN);
check('admin sigue viendo costo_actual', $r['status'] === 200 && isset($r['json']['costo_actual']), json_encode($r['json']));

echo "» Permisos granulares: anular (permiso vs clave)\n";
// Venta fresca para anular
$r = http('POST', '/ventas', [
    'tipo_pago' => 'efectivo', 'tipo_comprobante' => 'REMITO', 'caja_id' => 1,
    'items' => [['producto_id' => 1, 'cantidad' => 1, 'precio_unitario' => 50]],
]);
$ventaAnular = $r['json']['id'] ?? 0;

setPermisos($db, []);
$r = http('DELETE', '/ventas/' . $ventaAnular, null, TOKEN_USER);
check('sin permiso anular ni clave → 403', $r['status'] === 403, "status={$r['status']}");

// Con clave de autorización configurada, el flujo de clave funciona (era código muerto)
$db->prepare("UPDATE configuracion SET clave_autorizacion_hash = ? WHERE id = 1")
   ->execute([password_hash('clave123', PASSWORD_DEFAULT)]);
$r = http('DELETE', '/ventas/' . $ventaAnular, ['clave_autorizacion' => 'mala'], TOKEN_USER);
check('clave incorrecta → 403', $r['status'] === 403, "status={$r['status']}");
$r = http('DELETE', '/ventas/' . $ventaAnular, ['clave_autorizacion' => 'clave123'], TOKEN_USER);
check('clave correcta → 200 (flujo revivido)', $r['status'] === 200, json_encode($r['json']));

// Con el permiso, anula directo sin clave
$r = http('POST', '/ventas', [
    'tipo_pago' => 'efectivo', 'tipo_comprobante' => 'REMITO', 'caja_id' => 1,
    'items' => [['producto_id' => 1, 'cantidad' => 1, 'precio_unitario' => 50]],
]);
$ventaAnular2 = $r['json']['id'] ?? 0;
setPermisos($db, ['anular' => true]);
$r = http('DELETE', '/ventas/' . $ventaAnular2, null, TOKEN_USER);
check('con permiso anular → 200 sin clave', $r['status'] === 200, json_encode($r['json']));

echo "» Permisos granulares: validación y admin\n";
$r = http('PUT', '/usuarios/9', ['nombre' => 'user-test', 'permisos' => ['inventado' => true]]);
check('clave de permiso desconocida → 400', $r['status'] === 400, "status={$r['status']}");
$r = http('PUT', '/usuarios/9', ['nombre' => 'user-test', 'permisos' => ['compras' => true, 'log' => true]]);
check('guardar permisos válidos → 200', $r['status'] === 200, json_encode($r['json']));
$r = http('GET', '/compras', null, TOKEN_USER);
check('permiso recién otorgado aplica al instante', $r['status'] === 200, "status={$r['status']}");
$r = http('GET', '/rentabilidad/resumen', null, TOKEN);
check('admin bypasea todo (rentabilidad) → 200', $r['status'] === 200, "status={$r['status']}");

echo "» Rentabilidad: convención de números y reportes nuevos\n";
// Venta con envío: la convención unificada suma el envío a "Ventas" en ambos paneles
$r = http('POST', '/ventas', [
    'tipo_pago' => 'efectivo', 'tipo_comprobante' => 'REMITO', 'caja_id' => 1,
    'envio_precio' => 150, 'envio_direccion' => 'Calle Falsa 123',
    'items' => [['producto_id' => 1, 'cantidad' => 1, 'precio_unitario' => 100]],
]);
check('venta con envío → 200', $r['status'] === 200 && ($r['json']['total'] ?? 0) == 250.0, json_encode($r['json']));

$rango = 'desde=' . $hace40 . '&hasta=' . date('Y-m-d');
$rRes  = http('GET', '/rentabilidad/resumen?' . $rango);
$rDash = http('GET', '/ventas/dashboard?' . $rango);
check('resumen y dashboard 200', $rRes['status'] === 200 && $rDash['status'] === 200);
check('MISMO número de ventas en ambos paneles',
    abs(($rRes['json']['ventas'] ?? -1) - ($rDash['json']['total_ventas'] ?? -2)) < 0.011,
    'rentabilidad=' . ($rRes['json']['ventas'] ?? '?') . ' dashboard=' . ($rDash['json']['total_ventas'] ?? '?'));
$grupos = array_column($rRes['json']['por_categoria'] ?? [], 'grupo');
check('fila (Envíos) presente en el desglose', in_array('(Envíos)', $grupos, true), json_encode($grupos));

$hoy = date('Y-m-d');
$r = http('GET', "/ventas/dashboard?desde=$hoy&hasta=$hoy");
check('dashboard de un día incluye por_hora', $r['status'] === 200 && isset($r['json']['por_hora']) && is_array($r['json']['por_hora']), json_encode(array_keys($r['json'] ?? [])));

// Margen crítico: producto con 10% de margen entra; el de 33.9% no
$db->exec("INSERT INTO productos (id, codigo, nombre, precio_venta, costo_actual, stock_actual, activo, iva_porcentaje)
           VALUES (3, 'MC10', 'Producto Margen Bajo', 100, 90, 5, 1, 21.00)");
$r = http('GET', '/rentabilidad/margen-critico?umbral=20');
$codigos = array_column($r['json']['productos'] ?? [], 'codigo');
check('margen-critico detecta el producto al 10%', $r['status'] === 200 && in_array('MC10', $codigos, true), json_encode($codigos));
check('margen-critico NO incluye el producto al 33.9%', !in_array('T21', $codigos, true), json_encode($codigos));

$r = http('GET', '/rentabilidad/clientes?' . $rango);
check('rentabilidad por cliente → 200 con totales consistentes',
    $r['status'] === 200 && abs(($r['json']['totales']['ventas'] ?? -1) - ($rRes['json']['ventas'] ?? -2)) < 0.011,
    json_encode($r['json']['totales'] ?? null));

// ── 5. Resultado ──────────────────────────────────────────────────────

echo "\n" . str_repeat('─', 50) . "\n";
echo $falladas === 0
    ? "TODO OK — $pasadas tests pasaron\n"
    : "$falladas FALLAS de " . ($pasadas + $falladas) . " tests\n";
proc_terminate($proc);
exit($falladas === 0 ? 0 : 1);
