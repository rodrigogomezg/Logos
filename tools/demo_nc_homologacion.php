<?php

/**
 * Demo de emisión de comprobantes electrónicos en homologación:
 *   - Factura B (emisor RI)       + Nota de Crédito B parcial
 *   - Factura C (emisor Monotrib) + Nota de Crédito C parcial
 *
 * Cómo usar:
 *   1. Ejecutá la migración: migrate/35_nc_afip.sql
 *   2. Subí el certificado de homologación en Configuración → AFIP (entorno: homologación)
 *   3. Desde una terminal XAMPP: php tools/demo_nc_homologacion.php
 *
 * El CUIT del emisor y el punto de venta se leen de la tabla configuracion.
 * Para homologación, el CUIT configurado funciona independientemente de su condición real.
 * FC C se prueba con el mismo CUIT — ARCA acepta cualquier tipo en homologación.
 */

declare(strict_types=1);

define('LOGOS_ROOT', dirname(__DIR__));
require_once LOGOS_ROOT . '/vendor/autoload.php';
require_once LOGOS_ROOT . '/api/config/db.php';
require_once LOGOS_ROOT . '/api/helpers/Configuracion.php';
require_once LOGOS_ROOT . '/api/helpers/AfipWs.php';
require_once LOGOS_ROOT . '/api/helpers/AfipPadron.php';

date_default_timezone_set('America/Argentina/Buenos_Aires');

// ── Paso 0: Leer configuración ────────────────────────────────────────────────
$config = Configuracion::get();
echo "\n=== Demo ARCA Homologación ===\n";
echo "Emisor CUIT : {$config['cuit']}\n";
echo "Razón social: {$config['razon_social']}\n";
echo "Punto venta : {$config['punto_venta']}\n";
echo "Entorno     : {$config['afip_entorno']}\n\n";

if (empty($config['afip_cert'])) {
    die("ERROR: No hay certificado cargado. Subilo en Configuración → AFIP.\n");
}

// ── Paso 1: Simular una venta existente para obtener el número de comprobante ─
// En producción esto no hace falta: la venta ya existe en la DB con su ID.
// Acá creamos un array "venta" de prueba para llamar AfipWs directamente.

$venta_prueba = [
    'id'                    => 0,           // 0 = no guardar numero_afip_pendiente en DB
    'fecha'                 => date('Y-m-d'),
    'total'                 => 1210.00,     // $1210 = $1000 neto + $210 IVA 21%
    'tipo_comprobante'      => 'FC B-ELECT',
    'cliente_cuit'          => null,        // Consumidor Final no requiere CUIT para FC B
    'cliente_condicion_iva' => 'Consumidor Final',
    'numero_afip_pendiente' => null,
];

echo "-- Paso 1: Emitiendo FC B (homologación) --\n";
echo "Total: $" . number_format($venta_prueba['total'], 2) . "\n";

try {
    $r = AfipWs::facturar($venta_prueba, $config);
    echo "OK: Número AFIP = {$r['numero_afip']} | CAE = {$r['cae']} | Vence = {$r['cae_vencimiento']}\n\n";
} catch (AfipException $e) {
    die("ERROR al emitir FC B: " . $e->getMessage() . "\n");
}

$nro_factura  = $r['numero_afip'];
$cae_factura  = $r['cae'];
$pto_vta      = $r['punto_venta'];
$tipo_afip_fc = AfipWs::tipoCmp('FC B-ELECT'); // 6

// ── Paso 2: Consultar el estado de la factura emitida ────────────────────────
echo "-- Paso 2: Consultando estado con FECompConsultar --\n";
$estado = AfipWs::consultarComprobante($tipo_afip_fc, $pto_vta, $nro_factura, $config);
if ($estado) {
    echo "Resultado  : {$estado['resultado']}\n";
    echo "CAE        : {$estado['cae']}\n";
    echo "Vencimiento: {$estado['cae_vencimiento']}\n";
    echo "Total AFIP : $" . number_format($estado['total'], 2) . "\n\n";
} else {
    echo "No se pudo consultar (puede ser normal en homologación si el WS no devuelve resultados).\n\n";
}

// ── Paso 3: Emitir NC B sobre esa factura ────────────────────────────────────
$total_nc = 605.00; // acreditar la mitad

$nc_prueba = [
    'id'                    => 0,
    'fecha'                 => date('Y-m-d'),
    'total'                 => $total_nc,
    'tipo_comprobante'      => 'NC B-ELECT',
    'cliente_cuit'          => null,
    'cliente_condicion_iva' => 'Consumidor Final',
    'numero_afip_pendiente' => null,
    // Comprobante asociado (la factura que acabamos de emitir):
    'cbte_asoc_tipo'        => $tipo_afip_fc,
    'cbte_asoc_pto_vta'     => $pto_vta,
    'cbte_asoc_nro'         => $nro_factura,
];

echo "-- Paso 3: Emitiendo NC B (parcial, $" . number_format($total_nc, 2) . ") --\n";
echo "Comprobante asociado: FC B N° $nro_factura (pto vta $pto_vta)\n";

try {
    $rnc = AfipWs::facturar($nc_prueba, $config);
    echo "OK: Número NC AFIP = {$rnc['numero_afip']} | CAE = {$rnc['cae']} | Vence = {$rnc['cae_vencimiento']}\n\n";
} catch (AfipException $e) {
    die("ERROR al emitir NC B: " . $e->getMessage() . "\n");
}

// ── Paso 4: Demo de validación padrón (opcional) ─────────────────────────────
echo "-- Paso 4: Consulta padrón (CUIT de prueba 20000000168) --\n";
echo "(Solo funciona en producción con ws_sr_padron_a5 habilitado para tu CUIT)\n";
$condicion = AfipPadron::condicionIva(20000000168, $config);
if ($condicion !== null) {
    echo "Condición padrón: $condicion\n";
} else {
    echo "Padrón no disponible en este entorno (normal en homologación).\n";
}

// ── Paso 5: Demo FC C + NC C ──────────────────────────────────────────────────
echo "-- Paso 5: Emitiendo FC C (Monotributista, sin IVA) --\n";

$venta_c = [
    'id'                    => 0,
    'fecha'                 => date('Y-m-d'),
    'total'                 => 1000.00, // $1000 sin IVA (Monotributista no desglosa)
    'tipo_comprobante'      => 'FC C-ELECT',
    'cliente_cuit'          => null,
    'cliente_condicion_iva' => 'Consumidor Final',
    'numero_afip_pendiente' => null,
];

try {
    $rc = AfipWs::facturar($venta_c, $config);
    echo "OK: Número AFIP = {$rc['numero_afip']} | CAE = {$rc['cae']} | Vence = {$rc['cae_vencimiento']}\n\n";
} catch (AfipException $e) {
    echo "ERROR al emitir FC C: " . $e->getMessage() . "\n\n";
    $rc = null;
}

if ($rc !== null) {
    echo "-- Paso 6: Emitiendo NC C parcial sobre la FC C --\n";
    $nc_c = [
        'id'                    => 0,
        'fecha'                 => date('Y-m-d'),
        'total'                 => 400.00,
        'tipo_comprobante'      => 'NC C-ELECT',
        'cliente_cuit'          => null,
        'cliente_condicion_iva' => 'Consumidor Final',
        'numero_afip_pendiente' => null,
        'cbte_asoc_tipo'        => AfipWs::tipoCmp('FC C-ELECT'), // 11
        'cbte_asoc_pto_vta'     => $rc['punto_venta'],
        'cbte_asoc_nro'         => $rc['numero_afip'],
    ];
    try {
        $rnc_c = AfipWs::facturar($nc_c, $config);
        echo "OK: Número NC C = {$rnc_c['numero_afip']} | CAE = {$rnc_c['cae']}\n\n";
    } catch (AfipException $e) {
        echo "ERROR al emitir NC C: " . $e->getMessage() . "\n\n";
        $rnc_c = null;
    }
}

echo "\n=== Demo completado ===\n";
echo "FC B  N° $nro_factura      | CAE $cae_factura\n";
echo "NC B  N° {$rnc['numero_afip']} | CAE {$rnc['cae']}\n";
if (isset($rc))     echo "FC C  N° {$rc['numero_afip']}  | CAE {$rc['cae']}\n";
if (isset($rnc_c))  echo "NC C  N° {$rnc_c['numero_afip']}  | CAE {$rnc_c['cae']}\n";
echo "\n";
