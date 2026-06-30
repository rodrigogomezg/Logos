<?php
// Plantilla PHP para dompdf — generada a partir del diseño aprobado en comprobante_preview.html.
// Recibe $venta (array con cabecera + cliente + items[] + qr_data_uri) desde
// VentasController::comprobante(). Se incluye con ob_start()/ob_get_clean(), por eso
// no hace echo de nada fuera de las etiquetas HTML.

if (!function_exists('cp_esc')) {
    function cp_esc(?string $s): string {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('cp_fmt')) {
    function cp_fmt(?float $n): string {
        return '$ ' . number_format($n ?? 0, 2, ',', '.');
    }
}
if (!function_exists('cp_fecha')) {
    function cp_fecha(?string $f): string {
        if (!$f) return '—';
        $t = strtotime($f);
        return $t ? date('d/m/Y', $t) : '—';
    }
}
if (!function_exists('cp_cantidad')) {
    function cp_cantidad(float $c): string {
        return ((float)$c === floor($c)) ? (string)(int)$c : number_format($c, 2, ',', '.');
    }
}
if (!function_exists('cp_cuit')) {
    function cp_cuit(?string $c): string {
        $d = preg_replace('/\D/', '', $c ?? '');
        return strlen($d) === 11 ? substr($d, 0, 2) . '-' . substr($d, 2, 8) . '-' . substr($d, 10, 1) : ($c ?? '—');
    }
}
if (!function_exists('cp_iva_pct')) {
    function cp_iva_pct(float $p): string {
        return rtrim(rtrim(number_format($p, 2, ',', '.'), '0'), ',');
    }
}

$tipo          = $venta['tipo_comprobante'] ?? 'REMITO';
$esFacturaA    = $tipo === 'FC A-ELECT';
$esFacturaB    = $tipo === 'FC B-ELECT';
$esAfip        = $esFacturaA || $esFacturaB;
$tieneCae      = !empty($venta['cae']);

$docTipo = match ($tipo) {
    'FC A-ELECT', 'FC B-ELECT' => 'Factura',
    'PRESUPUESTO'              => 'Presupuesto',
    default                    => 'Remito',
};
$letra    = $esFacturaA ? 'A' : ($esFacturaB ? 'B' : null);
$letraCod = $esFacturaA ? '001' : ($esFacturaB ? '006' : null);

$logo = str_replace('\\', '/', realpath(__DIR__ . '/../../logo_background.png'));

$subtotalProductosGross = 0.0;
foreach ($venta['items'] as $it) { $subtotalProductosGross += $it['subtotal']; }
$envioGross = $venta['envio_precio'] ?? 0.0;
$totalGross = $venta['total'];

$mostrarColDesc = false;
foreach ($venta['items'] as $it) {
    if (!empty($it['ajuste_desc']) && !empty($it['ajuste_visible']) && $it['precio_original'] !== null) {
        $mostrarColDesc = true;
        break;
    }
}

$ivaFactor = 1 + ((float)$config['iva_porcentaje'] / 100);

if ($esFacturaA) {
    // Los precios cargados son siempre IVA incluido (no se vende nunca sin IVA);
    // para Factura A se discrimina el neto/IVA del MISMO total ya cobrado, sin alterarlo.
    $neto             = $totalGross / $ivaFactor;
    $iva              = $totalGross - $neto;
    $envioNeto        = $envioGross > 0 ? $envioGross / $ivaFactor : 0.0;
    $subtotalProdNeto = $neto - $envioNeto;
}

$tieneEntregaOrigen = !empty($venta['envio_direccion']) || !empty($venta['observaciones']);

$margenInferior = $tieneCae ? '72mm' : '42mm';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= cp_esc($docTipo) ?> N° <?= cp_esc($venta['numero']) ?></title>
<style>
  /* dompdf rompe el margen de @page si se usa el selector universal "*" (incluso sin
     margin/padding) — por eso el reset de box-sizing/margin/padding va elemento por elemento. */
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

  .marca-agua {
    position:fixed;
    top:133mm; left:40mm;
    width:130mm;
    opacity:.06;
    z-index:0;
  }

  /* ── Escala tipográfica del comprobante ──
     7 pasos, ratio ~1.13–1.18 (excepto .letra-box, que reproduce a propósito
     el tamaño de la letra de comprobante en una factura AFIP real):
     8.5 → 10 → 11.5(base) → 13 → 15 → 16 → 22 */

  table.cab { width:100%; border-collapse:collapse; }
  table.cab td { vertical-align:top; padding:0; }
  .cab-logo img { height:20mm; }
  .cab-empresa { font-size:10px; color:#333; margin-top:6px; line-height:1.5; }
  .cab-empresa strong { font-size:13px; color:#111; display:block; margin-bottom:2px; }

  td.cab-letra { width:16mm; text-align:center; vertical-align:top; padding-top:2px; }
  .letra-box {
    border:1.4px solid #111;
    width:13mm; height:13mm;
    margin:0 auto;
    font-size:22px;
    font-weight:700;
    text-align:center;
    line-height:13mm;
  }
  .letra-cod { font-size:8.5px; margin-top:1px; }

  td.cab-doc { width:62mm; text-align:right; }
  .doc-tipo { font-size:15px; font-weight:700; color:#660b0b; text-transform:uppercase; }
  .doc-box { border:1px solid #D5D0CA; border-radius:4px; padding:7px 10px; margin-top:6px; font-size:10px; text-align:left; }
  .doc-box div { display:block; padding:1px 0; }
  .doc-box .lbl { color:#666; }
  .doc-box .num { font-weight:700; font-size:13px; }

  .regla { border-top:2px solid #660b0b; margin:8px 0 10px; }

  table.cli { width:100%; border-collapse:collapse; margin-bottom:10px; }
  table.cli td { border:1px solid #D5D0CA; padding:6px 8px; font-size:10px; vertical-align:top; }
  table.cli .lbl { display:block; font-size:8.5px; color:#666; text-transform:uppercase; letter-spacing:.03em; margin-bottom:1px; }

  table.items { width:100%; border-collapse:collapse; margin-bottom:10px; }
  table.items th {
    background:#F0EEEB; border:1px solid #D5D0CA;
    text-align:left; padding:5px 7px; font-size:10px;
    text-transform:uppercase; letter-spacing:.02em; color:#444;
    white-space:nowrap;
  }
  table.items td { border:1px solid #D5D0CA; padding:5px 7px; background:#fff; }
  table.items td.r, table.items th.r { text-align:right; white-space:nowrap; }
  table.items tbody tr:nth-child(even) td { background:#FAFAF8; }

  .hoja-footer {
    /* dompdf posiciona "fixed" relativo al borde inferior del body (área de contenido),
       no al borde inferior de la página — por eso el offset negativo, igual al margen
       inferior reservado en @page, empuja el footer hacia abajo, dentro de ese margen. */
    position:fixed; left:14mm; right:14mm; bottom:-<?= $margenInferior ?>;
    padding:6mm 0 10mm;
    border-top:2px solid #660b0b;
    background:#fff;
  }
  table.tot { width:72mm; margin-left:auto; border-collapse:collapse; }
  table.tot td { padding:3px 6px; font-size:10px; white-space:nowrap; }
  table.tot td.r { text-align:right; }
  table.tot tr.final td { border-top:2px solid #111; font-size:16px; font-weight:700; padding-top:6px; }

  table.pie-afip { width:100%; border-collapse:collapse; margin-top:8mm; }
  table.pie-afip td { vertical-align:bottom; padding:0; }
  .qr-box img { width:22mm; height:22mm; }
  .cae-info { font-size:10px; color:#333; text-align:right; }
  .cae-info .cae-num { font-weight:700; font-size:13px; color:#111; }
</style>
</head>
<body>

<img class="marca-agua" src="<?= cp_esc($logo) ?>" alt="">

<table class="cab">
  <tr>
    <td class="cab-logo">
      <img src="<?= cp_esc($logo) ?>" alt="">
      <div class="cab-empresa">
        <strong><?= cp_esc($config['razon_social']) ?></strong>
        CUIT: <?= cp_esc(cp_cuit($config['cuit'])) ?> — <?= cp_esc($config['condicion_iva']) ?><br>
        <?= cp_esc($config['domicilio']) ?><br>
        IIBB: <?= cp_esc($config['iibb']) ?> — Tel: <?= cp_esc($config['telefono']) ?><br>
        <?= cp_esc($config['website']) ?>
      </div>
    </td>
    <td class="cab-letra">
      <?php if ($letra): ?>
        <div class="letra-box"><?= cp_esc($letra) ?></div>
        <div class="letra-cod">COD. <?= cp_esc($letraCod) ?></div>
      <?php endif; ?>
    </td>
    <td class="cab-doc">
      <div class="doc-tipo"><?= cp_esc($docTipo) ?></div>
      <div class="doc-box">
        <div class="num">N° <?= cp_esc(sprintf('%04d', (int)$config['punto_venta'])) ?>-<?= cp_esc($venta['numero']) ?></div>
        <div><span class="lbl">Fecha:</span> <?= cp_fecha($venta['fecha']) ?></div>
      </div>
    </td>
  </tr>
</table>
<div class="regla"></div>

<table class="cli">
  <tr>
    <td style="width:55%">
      <span class="lbl">Cliente</span>
      <?= cp_esc($venta['cliente_nombre'] ?? 'Consumidor final') ?>
    </td>
    <td style="width:25%">
      <span class="lbl">CUIT</span>
      <?= cp_esc($venta['cliente_cuit'] ?? '—') ?>
    </td>
    <td style="width:20%">
      <span class="lbl">Cond. IVA</span>
      <?= cp_esc($venta['cliente_condicion_iva'] ?? '—') ?>
    </td>
  </tr>
  <tr>
    <td colspan="2">
      <span class="lbl">Domicilio</span>
      <?php
        $partesDom = array_filter([$venta['cliente_domicilio'] ?? null, $venta['cliente_localidad'] ?? null, $venta['cliente_provincia'] ?? null]);
        echo $partesDom ? cp_esc(implode(', ', $partesDom)) : '—';
      ?>
    </td>
    <td>
      <span class="lbl">Teléfono</span>
      <?= cp_esc($venta['cliente_telefono'] ?? '—') ?>
    </td>
  </tr>
</table>

<?php if ($tieneEntregaOrigen): ?>
<table class="cli">
  <tr>
    <td style="width:55%">
      <span class="lbl">Entrega</span>
      <?= cp_esc($venta['envio_direccion'] ?? '—') ?>
    </td>
    <td style="width:45%">
      <span class="lbl">Observaciones</span>
      <?= cp_esc($venta['observaciones'] ?? '—') ?>
    </td>
  </tr>
</table>
<?php endif; ?>

<table class="items">
  <thead>
    <tr>
      <th>Código</th><th>Producto</th>
      <th class="r">Cant.</th><th class="r">Precio Unit.</th>
      <?php if ($mostrarColDesc): ?><th class="r">% Desc.</th><?php endif; ?>
      <th class="r">Subtotal</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($venta['items'] as $it): ?>
      <?php $tieneAjuste = !empty($it['ajuste_desc']) && !empty($it['ajuste_visible']) && $it['precio_original'] !== null; ?>
      <tr>
        <td><?= cp_esc($it['codigo'] ?? '') ?></td>
        <td><?= cp_esc($it['nombre'] ?? '') ?></td>
        <td class="r"><?= cp_cantidad($it['cantidad']) ?></td>
        <td class="r"><?= cp_fmt($it['precio_unitario']) ?></td>
        <?php if ($mostrarColDesc): ?>
          <td class="r"><?= $tieneAjuste ? cp_esc($it['ajuste_desc']) : '—' ?></td>
        <?php endif; ?>
        <td class="r"><?= cp_fmt($it['subtotal']) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<div class="hoja-footer">
  <table class="tot">
    <?php if ($esFacturaA): ?>
      <tr><td>Subtotal productos</td><td class="r"><?= cp_fmt($subtotalProdNeto) ?></td></tr>
      <?php if ($envioGross > 0): ?><tr><td>Envío</td><td class="r"><?= cp_fmt($envioNeto) ?></td></tr><?php endif; ?>
      <tr><td>IVA <?= cp_esc(cp_iva_pct((float)$config['iva_porcentaje'])) ?>%</td><td class="r"><?= cp_fmt($iva) ?></td></tr>
      <tr class="final"><td>TOTAL</td><td class="r"><?= cp_fmt($totalGross) ?></td></tr>
    <?php else: ?>
      <tr><td>Subtotal productos</td><td class="r"><?= cp_fmt($subtotalProductosGross) ?></td></tr>
      <?php if ($envioGross > 0): ?><tr><td>Envío</td><td class="r"><?= cp_fmt($envioGross) ?></td></tr><?php endif; ?>
      <tr class="final"><td>TOTAL</td><td class="r"><?= cp_fmt($totalGross) ?></td></tr>
    <?php endif; ?>
  </table>

  <?php if ($tieneCae): ?>
    <table class="pie-afip">
      <tr>
        <td style="width:22mm">
          <?php if (!empty($venta['qr_data_uri'])): ?>
            <div class="qr-box"><img src="<?= cp_esc($venta['qr_data_uri']) ?>" alt="QR AFIP"></div>
          <?php endif; ?>
        </td>
        <td></td>
        <td class="cae-info">
          <div class="cae-num">CAE: <?= cp_esc($venta['cae']) ?></div>
        </td>
      </tr>
    </table>
  <?php endif; ?>
</div>

</body>
</html>
