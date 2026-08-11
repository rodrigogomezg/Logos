<?php
// Plantilla dompdf para Nota de Envío.
// Recibe $nota (array) y $config desde NotasEnvioController::pdf().

if (!function_exists('ne_esc')) {
    function ne_esc(?string $s): string {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('ne_cant')) {
    function ne_cant(float $c): string {
        return ((float)$c === floor($c)) ? (string)(int)$c : number_format($c, 2, ',', '.');
    }
}
if (!function_exists('ne_fecha')) {
    function ne_fecha(?string $f): string {
        if (!$f) return '—';
        $t = strtotime($f);
        return $t ? date('d/m/Y', $t) : '—';
    }
}
if (!function_exists('ne_fmt')) {
    function ne_fmt(?float $n): string {
        return '$ ' . number_format($n ?? 0, 2, ',', '.');
    }
}
if (!function_exists('ne_cuit')) {
    function ne_cuit(?string $c): string {
        $d = preg_replace('/\D/', '', $c ?? '');
        return strlen($d) === 11 ? substr($d, 0, 2) . '-' . substr($d, 2, 8) . '-' . substr($d, 10, 1) : ($c ?? '—');
    }
}

require_once __DIR__ . '/../../api/helpers/Configuracion.php';
$logo           = str_replace('\\', '/', realpath(Configuracion::rutaLogo()));
$tieneEntregas  = !empty($nota['notas_previas']);
$hayPrevios     = false;
foreach ($nota['venta_items'] as $vi) {
    if ($vi['cantidad_previo'] > 0) { $hayPrevios = true; break; }
}
$colSpan = $hayPrevios ? 6 : 5;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Nota de Envío N° <?= ne_esc((string)$nota['numero']) ?> — Remito <?= ne_esc($nota['venta_numero']) ?></title>
<style>
  table, td, th, tr, div, span, img { box-sizing: border-box; margin:0; padding:0; }

  @page { margin: 14mm 14mm 40mm 14mm; }

  body {
    margin:0; padding:0;
    font-family: "DejaVu Sans", sans-serif;
    color:#111;
    font-size:11px;
    line-height:1.4;
  }

  .marca-agua {
    position:fixed; top:133mm; left:40mm;
    width:130mm; opacity:.05; z-index:0;
  }

  /* ── Cabecera ── */
  table.cab { width:100%; border-collapse:collapse; }
  table.cab td { vertical-align:top; padding:0; }
  .cab-logo img { height:18mm; }
  .cab-empresa { font-size:9.5px; color:#333; margin-top:5px; line-height:1.5; }
  .cab-empresa strong { font-size:12px; color:#111; display:block; margin-bottom:2px; }

  td.cab-doc { width:64mm; text-align:right; }
  .doc-tipo { font-size:16px; font-weight:700; color:#1a4a8a; text-transform:uppercase; letter-spacing:.5px; }
  .doc-num  { font-size:13px; font-weight:700; color:#1a4a8a; margin-top:2px; }
  .doc-box  { border:1px solid #C8D4E8; border-radius:4px; padding:6px 10px; margin-top:6px; font-size:9.5px; text-align:left; background:#F5F8FF; }
  .doc-box div { display:block; padding:1px 0; }
  .doc-box .lbl { color:#666; }
  .doc-box .val { font-weight:600; }

  .regla { border-top:2px solid #1a4a8a; margin:8px 0 10px; }

  /* ── Cliente ── */
  table.cli { width:100%; border-collapse:collapse; margin-bottom:8px; }
  table.cli td { border:1px solid #C8D4E8; padding:5px 8px; font-size:9.5px; vertical-align:top; }
  table.cli .lbl { display:block; font-size:8px; color:#666; text-transform:uppercase; letter-spacing:.03em; margin-bottom:1px; }

  /* ── Datos de entrega ── */
  table.entrega { width:100%; border-collapse:collapse; margin-bottom:10px; }
  table.entrega td { border:1px solid #C8D4E8; padding:5px 8px; font-size:9.5px; background:#F5F8FF; vertical-align:top; }
  table.entrega .lbl { display:block; font-size:8px; color:#555; text-transform:uppercase; letter-spacing:.03em; margin-bottom:1px; }
  table.entrega .val { font-weight:600; }

  /* ── Ítems ── */
  .sec-titulo {
    font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.06em;
    color:#1a4a8a; margin:10px 0 4px; border-bottom:1px solid #C8D4E8; padding-bottom:3px;
  }

  table.items { width:100%; border-collapse:collapse; margin-bottom:10px; }
  table.items th {
    background:#E8EFF9; border:1px solid #C8D4E8;
    text-align:left; padding:5px 6px; font-size:8.5px;
    text-transform:uppercase; letter-spacing:.02em; color:#333; white-space:nowrap;
  }
  table.items th.r, table.items td.r { text-align:right; }
  table.items td { border:1px solid #C8D4E8; padding:5px 6px; font-size:10px; background:#fff; }
  table.items tbody tr:nth-child(even) td { background:#F8FAFD; }
  table.items td.pendiente-cero { color:#999; }
  table.items td.esta-entrega { font-weight:700; }

  /* ── Entregas previas ── */
  table.previas { width:100%; border-collapse:collapse; margin-bottom:6px; font-size:9.5px; }
  table.previas th { background:#F0F0F0; border:1px solid #D0D0D0; padding:4px 7px; font-size:8px; text-transform:uppercase; letter-spacing:.03em; color:#555; }
  table.previas td { border:1px solid #D0D0D0; padding:4px 7px; }
  table.previas td.r { text-align:right; }

  /* ── Observaciones ── */
  .obs-box { border:1px solid #C8D4E8; border-radius:3px; padding:7px 10px; font-size:10px; background:#F9FAFB; margin-bottom:10px; }
  .obs-label { font-size:8px; color:#666; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px; }

  /* ── Footer con firma ── */
  .hoja-footer {
    position:fixed; left:14mm; right:14mm; bottom:-40mm;
    padding:6mm 0 8mm; border-top:2px solid #1a4a8a; background:#fff;
  }
  table.firma { width:100%; border-collapse:collapse; }
  table.firma td { vertical-align:bottom; padding:0 8mm; text-align:center; font-size:9px; color:#444; }
  .linea-firma { border-bottom:1px solid #555; width:100%; display:block; margin-bottom:4px; height:14mm; }
  .pie-txt { font-size:8.5px; color:#777; text-align:center; margin-top:8px; }
</style>
</head>
<body>

<img class="marca-agua" src="<?= ne_esc($logo) ?>" alt="">

<!-- Cabecera -->
<table class="cab">
  <tr>
    <td class="cab-logo">
      <img src="<?= ne_esc($logo) ?>" alt="">
      <div class="cab-empresa">
        <strong><?= ne_esc($config['razon_social']) ?></strong>
        CUIT: <?= ne_esc(ne_cuit($config['cuit'])) ?> — <?= ne_esc($config['condicion_iva']) ?><br>
        <?= ne_esc($config['domicilio']) ?><br>
        Tel: <?= ne_esc($config['telefono']) ?>
        <?= !empty($config['website']) ? ' — ' . ne_esc($config['website']) : '' ?>
      </div>
    </td>
    <td class="cab-doc">
      <div class="doc-tipo">Nota de Envío</div>
      <div class="doc-num">N° <?= ne_esc((string)$nota['numero']) ?></div>
      <div class="doc-box">
        <div><span class="lbl">Fecha de emisión: </span><span class="val"><?= ne_fecha($nota['fecha_emision']) ?></span></div>
        <div><span class="lbl">Referencia: </span><span class="val"><?= ne_esc($nota['tipo_comprobante']) ?> N° <?= ne_esc($nota['venta_numero']) ?></span></div>
        <div><span class="lbl">Fecha del remito: </span><span class="val"><?= ne_fecha($nota['venta_fecha']) ?></span></div>
      </div>
    </td>
  </tr>
</table>

<div class="regla"></div>

<!-- Cliente -->
<table class="cli">
  <tr>
    <td style="width:60%">
      <span class="lbl">Cliente</span>
      <?= ne_esc($nota['cliente_nombre'] ?? 'Consumidor final') ?>
    </td>
    <td style="width:20%">
      <span class="lbl">CUIT</span>
      <?= ne_esc($nota['cliente_cuit'] ? ne_cuit($nota['cliente_cuit']) : '—') ?>
    </td>
    <td style="width:20%">
      <span class="lbl">Teléfono</span>
      <?= ne_esc($nota['cliente_telefono'] ?? '—') ?>
    </td>
  </tr>
</table>

<!-- Datos de entrega -->
<table class="entrega">
  <tr>
    <?php if (!empty($nota['fecha_entrega'])): ?>
    <td style="width:22%">
      <span class="lbl">Fecha de entrega</span>
      <span class="val"><?= ne_fecha($nota['fecha_entrega']) ?></span>
    </td>
    <?php endif; ?>
    <?php if (!empty($nota['transportista'])): ?>
    <td style="width:30%">
      <span class="lbl">Transportista</span>
      <span class="val"><?= ne_esc($nota['transportista']) ?></span>
    </td>
    <?php endif; ?>
    <?php if (!empty($nota['envio_direccion'])): ?>
    <td>
      <span class="lbl">Dirección de entrega</span>
      <span class="val"><?= ne_esc($nota['envio_direccion']) ?></span>
    </td>
    <?php endif; ?>
    <?php if (!empty($nota['envio_precio'])): ?>
    <td style="width:18%; text-align:right">
      <span class="lbl">Costo de envío</span>
      <span class="val"><?= ne_fmt((float)$nota['envio_precio']) ?></span>
    </td>
    <?php endif; ?>
  </tr>
</table>

<!-- Ítems -->
<div class="sec-titulo">Artículos</div>
<table class="items">
  <thead>
    <tr>
      <th style="width:10%">Código</th>
      <th>Descripción</th>
      <th class="r" style="width:11%">Cant. remito</th>
      <?php if ($hayPrevios): ?>
      <th class="r" style="width:13%">Entregado antes</th>
      <?php endif; ?>
      <th class="r" style="width:12%">Esta entrega</th>
      <th class="r" style="width:11%">Pendiente</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($nota['venta_items'] as $vi):
        $esCero = ($vi['cantidad_esta'] == 0 && $vi['cantidad_previo'] == $vi['cantidad_total']);
    ?>
    <tr>
      <td><?= ne_esc($vi['codigo'] ?? '—') ?></td>
      <td><?= ne_esc($vi['nombre']) ?></td>
      <td class="r"><?= ne_cant($vi['cantidad_total']) ?></td>
      <?php if ($hayPrevios): ?>
      <td class="r"><?= $vi['cantidad_previo'] > 0 ? ne_cant($vi['cantidad_previo']) : '—' ?></td>
      <?php endif; ?>
      <td class="r esta-entrega <?= $vi['cantidad_esta'] == 0 ? 'pendiente-cero' : '' ?>">
        <?= $vi['cantidad_esta'] > 0 ? ne_cant($vi['cantidad_esta']) : '—' ?>
      </td>
      <td class="r <?= $vi['cantidad_pendiente'] == 0 ? 'pendiente-cero' : '' ?>">
        <?= $vi['cantidad_pendiente'] > 0 ? ne_cant($vi['cantidad_pendiente']) : '✓' ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php if ($tieneEntregas): ?>
<!-- Historial de entregas anteriores -->
<div class="sec-titulo">Historial de entregas anteriores</div>
<?php foreach ($nota['notas_previas'] as $prev): ?>
<table class="previas" style="margin-bottom:4px">
  <thead>
    <tr>
      <th colspan="3">
        Entrega N° <?= (int)$prev['numero'] ?>
        — <?= ne_fecha($prev['fecha_emision']) ?>
        <?= !empty($prev['fecha_entrega']) ? ' (entrega: ' . ne_fecha($prev['fecha_entrega']) . ')' : '' ?>
        <?= !empty($prev['transportista'])  ? ' — ' . ne_esc($prev['transportista']) : '' ?>
      </th>
    </tr>
    <tr>
      <th style="width:15%">Código</th>
      <th>Descripción</th>
      <th class="r" style="width:20%">Cantidad</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($prev['items'] as $pi): ?>
    <tr>
      <td><?= ne_esc($pi['codigo'] ?? '—') ?></td>
      <td><?= ne_esc($pi['nombre']) ?></td>
      <td class="r"><?= ne_cant($pi['cantidad']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($nota['observaciones'])): ?>
<div class="obs-label">Observaciones</div>
<div class="obs-box"><?= ne_esc($nota['observaciones']) ?></div>
<?php endif; ?>

<!-- Footer con firma -->
<div class="hoja-footer">
  <table class="firma">
    <tr>
      <td>
        <span class="linea-firma"></span>
        Firma y aclaración — Recibí conforme
      </td>
      <td>
        <span class="linea-firma"></span>
        Transportista / Responsable de entrega
      </td>
      <td>
        <span class="linea-firma"></span>
        Sello y firma empresa
      </td>
    </tr>
  </table>
  <div class="pie-txt">
    <?= ne_esc($config['razon_social']) ?> — CUIT <?= ne_esc(ne_cuit($config['cuit'])) ?>
    — Nota de Envío N° <?= ne_esc((string)$nota['numero']) ?>
    referida a <?= ne_esc($nota['tipo_comprobante']) ?> N° <?= ne_esc($nota['venta_numero']) ?>
  </div>
</div>

</body>
</html>
