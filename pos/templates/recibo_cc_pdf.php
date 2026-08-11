<?php
// Plantilla dompdf — recibo de pago de cuenta corriente (solo clientes).
// Variables inyectadas por ReciboGenerador::generarPdf():
//   $mov      — id, monto, fecha, medio_pago, pago_datos (array|null), observaciones, asignaciones[]
//   $cliente  — nombre, cuit, condicion_iva
//   $config   — punto_venta, razon_social, nombre_fantasia, cuit, condicion_iva, domicilio, iibb, telefono, website
//   $nRecibo  — 'PPPP-NNNNNNNN'

if (!function_exists('rc_esc')) {
    function rc_esc(?string $s): string {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('rc_fmt')) {
    function rc_fmt(?float $n): string {
        return '$ ' . number_format($n ?? 0, 2, ',', '.');
    }
}
if (!function_exists('rc_fecha')) {
    function rc_fecha(?string $f): string {
        if (!$f) return '—';
        $t = strtotime($f);
        return $t ? date('d/m/Y', $t) : '—';
    }
}

require_once __DIR__ . '/../../api/helpers/Configuracion.php';
$logo = str_replace('\\', '/', realpath(Configuracion::rutaLogo()));

// Medio de pago — igual al mapa del frontend
$medioMap = ['efectivo' => 'Efectivo', 'transferencia' => 'Transferencia bancaria', 'cheque' => 'Cheque'];
$medio    = $medioMap[$mov['medio_pago'] ?? ''] ?? rc_esc($mov['medio_pago'] ?? '—');

// Detalle del medio de pago (banco, referencia, cheque, etc.)
$pd          = $mov['pago_datos'] ?? [];
$extraParts  = [];
if (!empty($pd['banco']))      $extraParts[] = 'Banco: '        . rc_esc($pd['banco']);
if (!empty($pd['referencia'])) $extraParts[] = 'Ref: '          . rc_esc($pd['referencia']);
if (!empty($pd['numero']))     $extraParts[] = 'N° cheque: '    . rc_esc($pd['numero']);
if (!empty($pd['fecha']))      $extraParts[] = 'Fecha cheque: ' . rc_fecha($pd['fecha']);
if (!empty($pd['titular']))    $extraParts[] = 'Titular: '      . rc_esc($pd['titular']);
$detalle = $extraParts ? implode(' · ', $extraParts) : '—';

// Asignaciones y totales
$asignaciones = $mov['asignaciones'] ?? [];
$totalAsig    = array_sum(array_column($asignaciones, 'monto'));
$sinImputar   = $mov['monto'] - $totalAsig;

// Encabezado empresa
$empresa  = rc_esc($config['razon_social'] ?? $config['nombre_fantasia'] ?? '');
$cuitLine = !empty($config['cuit'])
    ? 'CUIT: ' . rc_esc($config['cuit']) . (!empty($config['condicion_iva']) ? ' — ' . rc_esc($config['condicion_iva']) : '')
    : rc_esc($config['condicion_iva'] ?? '');
$iibbTel = implode(' — ', array_filter([
    !empty($config['iibb'])     ? 'IIBB: ' . rc_esc($config['iibb'])    : '',
    !empty($config['telefono']) ? 'Tel: '  . rc_esc($config['telefono']) : '',
]));

// Altura del footer: padding 6mm + hasta 3 filas ~5mm c/u + fila final ~7mm + padding 10mm + borde ~1mm ≈ 33mm.
// Se usa 36mm para tener margen de seguridad.
$margenInferior = '36mm';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Recibo N° <?= rc_esc($nRecibo) ?></title>
<style>
  /* dompdf: NO usar selector * — rompe el margin de @page */
  table, td, th, tr, div, span, img { box-sizing: border-box; margin:0; padding:0; }

  @page {
    margin: 14mm 14mm <?= $margenInferior ?> 14mm;
  }

  body {
    margin:0; padding:0;
    font-family: "DejaVu Sans", sans-serif;
    color:#111111;
    font-size:11.5px;
    line-height:1.4;
  }

  /* position:fixed + opacity funcionan en dompdf */
  .marca-agua {
    position:fixed;
    top:133mm; left:40mm;
    width:130mm;
    opacity:.06;
    z-index:0;
  }

  table.cab { width:100%; border-collapse:collapse; }
  table.cab td { vertical-align:top; padding:0; }
  .cab-logo img { height:20mm; }
  .cab-empresa { font-size:10px; color:#333; margin-top:6px; line-height:1.5; }
  .cab-empresa strong { font-size:13px; color:#111; display:block; margin-bottom:2px; }

  td.cab-doc { width:62mm; text-align:right; }
  .doc-tipo { font-size:15px; font-weight:700; color:#163B66; text-transform:uppercase; }
  .doc-box { border:1px solid #D5D0CA; border-radius:4px; padding:7px 10px; margin-top:6px; font-size:10px; text-align:left; }
  .doc-box div { display:block; padding:1px 0; }
  .doc-box .lbl { color:#666; }
  .doc-box .num { font-weight:700; font-size:13px; }

  .regla { border-top:2px solid #163B66; margin:8px 0 10px; }

  table.cli { width:100%; border-collapse:collapse; margin-bottom:10px; }
  table.cli td { border:1px solid #D5D0CA; padding:6px 8px; font-size:10px; vertical-align:top; }
  table.cli .lbl { display:block; font-size:8.5px; color:#666; text-transform:uppercase; letter-spacing:.03em; margin-bottom:1px; }

  .sec-titulo { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#444; margin:8px 0 4px; }

  table.items { width:100%; border-collapse:collapse; margin-bottom:10px; }
  table.items th {
    background:#F0EEEB; border:1px solid #D5D0CA;
    text-align:left; padding:5px 7px; font-size:10px;
    text-transform:uppercase; letter-spacing:.02em; color:#444;
  }
  table.items td { border:1px solid #D5D0CA; padding:5px 7px; background:#fff; font-size:11px; }
  table.items td.r, table.items th.r { text-align:right; }
  table.items tbody tr:nth-child(even) td { background:#FAFAF8; }

  /* dompdf posiciona "fixed" relativo al borde inferior del body (área de contenido),
     no al borde inferior de la página. El offset negativo lo empuja dentro del margen @page. */
  .hoja-footer {
    position:fixed; left:14mm; right:14mm; bottom:-<?= $margenInferior ?>;
    padding:6mm 0 10mm;
    border-top:2px solid #163B66;
    background:#fff;
  }
  table.tot { width:72mm; margin-left:auto; border-collapse:collapse; }
  table.tot td { padding:3px 6px; font-size:10px; white-space:nowrap; }
  table.tot td.r { text-align:right; }
  table.tot tr.final td { border-top:2px solid #111; font-size:16px; font-weight:700; padding-top:6px; }
</style>
</head>
<body>

<img class="marca-agua" src="<?= rc_esc($logo) ?>" alt="">

<table class="cab">
  <tr>
    <td class="cab-logo">
      <img src="<?= rc_esc($logo) ?>" alt="">
      <div class="cab-empresa">
        <strong><?= $empresa ?></strong>
        <?= $cuitLine ?><br>
        <?= rc_esc($config['domicilio'] ?? '') ?><br>
        <?= $iibbTel ?>
        <?php if (!empty($config['website'])): ?><br><?= rc_esc($config['website']) ?><?php endif; ?>
      </div>
    </td>
    <td></td>
    <td class="cab-doc">
      <div class="doc-tipo">Recibo de Pago</div>
      <div class="doc-box">
        <div class="num">N° <?= rc_esc($nRecibo) ?></div>
        <div><span class="lbl">Fecha:</span> <?= rc_fecha($mov['fecha']) ?></div>
      </div>
    </td>
  </tr>
</table>
<div class="regla"></div>

<table class="cli">
  <tr>
    <td style="width:55%"><span class="lbl">Recibido de</span><?= rc_esc($cliente['nombre'] ?? '') ?></td>
    <td style="width:25%"><span class="lbl">CUIT</span><?= rc_esc($cliente['cuit'] ?? '—') ?></td>
    <td style="width:20%"><span class="lbl">Cond. IVA</span><?= rc_esc($cliente['condicion_iva'] ?? '—') ?></td>
  </tr>
</table>

<table class="cli">
  <tr>
    <td style="width:35%"><span class="lbl">Medio de pago</span><?= rc_esc($medio) ?></td>
    <td style="width:65%"><span class="lbl">Detalle</span><?= $detalle ?></td>
  </tr>
  <?php if (!empty($mov['observaciones'])): ?>
  <tr>
    <td colspan="2"><span class="lbl">Observaciones</span><?= rc_esc($mov['observaciones']) ?></td>
  </tr>
  <?php endif; ?>
</table>

<?php if ($asignaciones): ?>
<div class="sec-titulo">Comprobantes imputados</div>
<table class="items">
  <thead>
    <tr>
      <th>Comprobante</th>
      <th class="r">Importe imputado</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($asignaciones as $a): ?>
    <tr>
      <?php if (!empty($a['venta_id'])): ?>
      <td><?= rc_esc($a['venta_tipo'] ?? 'VTA') ?> N°<?= sprintf('%08d', (int)$a['venta_id']) ?></td>
      <?php else: ?>
      <td><?= rc_esc($a['cargo_label'] ?? 'Cargo') ?></td>
      <?php endif; ?>
      <td class="r"><?= rc_fmt((float)$a['monto']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<div class="hoja-footer">
  <table class="tot">
    <?php if ($asignaciones && $sinImputar > 0.001): ?>
    <tr><td>Subtotal imputado</td><td class="r"><?= rc_fmt($totalAsig) ?></td></tr>
    <tr><td>Sin imputar</td><td class="r"><?= rc_fmt($sinImputar) ?></td></tr>
    <?php endif; ?>
    <tr class="final"><td>TOTAL RECIBIDO</td><td class="r"><?= rc_fmt($mov['monto']) ?></td></tr>
  </table>
</div>

</body>
</html>
