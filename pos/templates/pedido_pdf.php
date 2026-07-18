<?php
// Plantilla de pedido sugerido de stock para dompdf.
// Variables disponibles: $grupos, $config, $fecha, $logo

if (!function_exists('pe_esc')) {
    function pe_esc(?string $s): string {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('pe_n')) {
    function pe_n($n): string {
        $v = (float)$n;
        return ($v == (int)$v) ? (string)(int)$v : number_format($v, 2, ',', '.');
    }
}

function pe_cuit(?string $c): string {
    $d = preg_replace('/\D/', '', $c ?? '');
    return strlen($d) === 11
        ? substr($d, 0, 2) . '-' . substr($d, 2, 8) . '-' . substr($d, 10)
        : ($c ?? '');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Pedido de Stock</title>
<style>
  table, td, th, tr, div, span, img { box-sizing: border-box; margin: 0; padding: 0; }
  @page { margin: 14mm 14mm 14mm 14mm; }
  body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #111; line-height: 1.45; margin: 0; padding: 0; }

  /* Cabecera empresa */
  .cab { width: 100%; border-collapse: collapse; margin-bottom: 10mm; }
  .cab td { vertical-align: top; padding: 0; }
  .cab-logo img { height: 18mm; }
  .cab-empresa { font-size: 10px; color: #444; margin-top: 4px; line-height: 1.6; }
  .cab-empresa strong { font-size: 13px; color: #111; display: block; margin-bottom: 2px; }
  .cab-titulo { text-align: right; }
  .titulo-doc { font-size: 17px; font-weight: 700; color: #660b0b; text-transform: uppercase; letter-spacing: .03em; }
  .titulo-fecha { font-size: 10px; color: #666; margin-top: 3px; }

  .regla { border-top: 2px solid #660b0b; margin-bottom: 8mm; }

  /* Bloque por proveedor */
  .grupo { margin-bottom: 8mm; }
  .grupo-titulo {
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    color: #fff; background: #660b0b; padding: 4px 8px; margin-bottom: 0;
  }
  table.items { width: 100%; border-collapse: collapse; }
  table.items th {
    background: #F0EEEB; border: 1px solid #D5D0CA;
    padding: 5px 7px; font-size: 9.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .03em; color: #555; text-align: left;
  }
  table.items th.r { text-align: right; }
  table.items td { border: 1px solid #D5D0CA; padding: 5px 7px; font-size: 11px; background: #fff; }
  table.items td.r { text-align: right; font-variant-numeric: tabular-nums; }
  table.items tbody tr:nth-child(even) td { background: #FAFAF8; }
  table.items td.pedir { font-weight: 700; color: #1a6b35; }
</style>
</head>
<body>

<table class="cab">
  <tr>
    <td class="cab-logo">
      <?php if ($logo && file_exists($logo)): ?>
        <img src="<?= pe_esc($logo) ?>" alt="">
      <?php endif; ?>
      <div class="cab-empresa">
        <strong><?= pe_esc($config['razon_social'] ?? '') ?></strong>
        CUIT: <?= pe_esc(pe_cuit($config['cuit'] ?? '')) ?>
        <?php if (!empty($config['condicion_iva'])): ?> — <?= pe_esc($config['condicion_iva']) ?><?php endif; ?><br>
        <?php if (!empty($config['domicilio'])): ?><?= pe_esc($config['domicilio']) ?><br><?php endif; ?>
        <?php if (!empty($config['telefono'])): ?>Tel: <?= pe_esc($config['telefono']) ?><?php endif; ?>
      </div>
    </td>
    <td class="cab-titulo">
      <div class="titulo-doc">Pedido sugerido de stock</div>
      <div class="titulo-fecha">Fecha: <?= pe_esc($fecha) ?></div>
    </td>
  </tr>
</table>

<div class="regla"></div>

<?php foreach ($grupos as $grupo): ?>
  <?php if (empty($grupo['items'])) continue; ?>
  <div class="grupo">
    <div class="grupo-titulo"><?= pe_esc($grupo['proveedor'] ?: 'Sin proveedor asignado') ?></div>
    <table class="items">
      <thead>
        <tr>
          <th style="width:90px">Código</th>
          <th>Nombre del producto</th>
          <th class="r" style="width:70px">A pedir</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($grupo['items'] as $item): ?>
          <tr>
            <td><span style="font-family:monospace;font-size:10px;color:#666"><?= pe_esc($item['codigo'] ?? '—') ?></span></td>
            <td><?= pe_esc($item['nombre'] ?? '') ?></td>
            <td class="r pedir"><?= pe_n($item['a_pedir'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endforeach; ?>

</body>
</html>
