<?php
// Test del desglose de IVA multi-alícuota de AfipWs (sin tocar red ni base).
require_once __DIR__ . '/../api/helpers/AfipWs.php';

$m = new ReflectionMethod('AfipWs', 'desgloseIva');
$m->setAccessible(true);

$fallas = 0;
function verificar(string $caso, array $alicuotas, float $totalEsperado, array $esperado, int &$fallas): void {
    $suma = round(array_sum(array_map(fn($a) => $a['neto'] + $a['iva'], $alicuotas)), 2);
    $ok = abs($suma - $totalEsperado) < 0.001;
    foreach ($esperado as $i => $e) {
        foreach ($e as $k => $v) {
            if (abs(($alicuotas[$i][$k] ?? -999) - $v) > 0.011) { $ok = false; }
        }
    }
    if (!$ok) {
        $fallas++;
        echo "FALLA [$caso]: " . json_encode($alicuotas) . " (suma=$suma, esperado total=$totalEsperado)\n";
    } else {
        echo "OK    [$caso] suma=$suma\n";
    }
}

$config = ['iva_porcentaje' => 21];

// Caso 1: todo al 21%
$venta = ['items_iva' => [
    ['cantidad' => 2, 'precio_unitario' => 605.00, 'iva_porcentaje' => 21],   // 1210
], 'envio_precio' => null];
$r = $m->invoke(null, $venta, $config, 1210.00);
verificar('21% simple', $r, 1210.00, [0 => ['id' => 5, 'neto' => 1000.00, 'iva' => 210.00]], $fallas);

// Caso 2: mixto 21% + 10.5%
$venta = ['items_iva' => [
    ['cantidad' => 1, 'precio_unitario' => 1210.00, 'iva_porcentaje' => 21],    // neto 1000, iva 210
    ['cantidad' => 1, 'precio_unitario' => 1105.00, 'iva_porcentaje' => 10.5],  // neto 1000, iva 105
]];
$r = $m->invoke(null, $venta, $config, 2315.00);
verificar('mixto 21+10.5', $r, 2315.00, [
    0 => ['id' => 5, 'neto' => 1000.00, 'iva' => 210.00],
    1 => ['id' => 4, 'neto' => 1000.00, 'iva' => 105.00],
], $fallas);

// Caso 3: con exento (0%) y envío (va al 21%)
$venta = ['items_iva' => [
    ['cantidad' => 3, 'precio_unitario' => 100.00, 'iva_porcentaje' => 0],     // 300 exento
    ['cantidad' => 1, 'precio_unitario' => 121.00, 'iva_porcentaje' => 21],    // neto 100, iva 21
], 'envio_precio' => 121.00];                                                  // neto 100, iva 21
$r = $m->invoke(null, $venta, $config, 542.00);
verificar('exento + envio', $r, 542.00, [], $fallas);

// Caso 4: sin ítems (NC por monto) → alícuota única configurada
$venta = ['items_iva' => []];
$r = $m->invoke(null, $venta, $config, 1210.00);
verificar('NC sin items', $r, 1210.00, [0 => ['id' => 5, 'neto' => 1000.00, 'iva' => 210.00]], $fallas);

// Caso 5: redondeos incómodos — cantidades fraccionarias, suma exacta garantizada
$venta = ['items_iva' => [
    ['cantidad' => 0.333, 'precio_unitario' => 99.99,  'iva_porcentaje' => 21],
    ['cantidad' => 1.777, 'precio_unitario' => 33.33,  'iva_porcentaje' => 10.5],
    ['cantidad' => 2.5,   'precio_unitario' => 7.77,   'iva_porcentaje' => 0],
]];
$total = round(0.333 * 99.99 + 1.777 * 33.33 + 2.5 * 7.77, 2);
$r = $m->invoke(null, $venta, $config, $total);
verificar('redondeo fraccionario', $r, $total, [], $fallas);

// Caso 6: alícuota inválida debe tirar excepción
try {
    $m->invoke(null, ['items_iva' => [['cantidad' => 1, 'precio_unitario' => 100, 'iva_porcentaje' => 15]]], $config, 100.0);
    echo "FALLA [alicuota invalida]: no tiró excepción\n"; $fallas++;
} catch (AfipException $e) {
    echo "OK    [alicuota invalida] excepción: " . $e->getMessage() . "\n";
}

echo $fallas === 0 ? "\nTODO OK\n" : "\n$fallas FALLAS\n";
exit($fallas === 0 ? 0 : 1);
