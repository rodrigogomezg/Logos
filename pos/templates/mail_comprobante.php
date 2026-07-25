<?php
// Variables esperadas:
// $venta   — array de datos de la venta (ComprobanteGenerador)
// $config  — array de Configuracion::get()
// $logoB64 — string base64 del logo del comercio, o null
// $logoMime — mime type del logo (image/png por defecto)

$negocio  = htmlspecialchars($config['nombre_fantasia'] ?: $config['razon_social']);
$cuit     = htmlspecialchars($config['cuit'] ?? '');
$domicilio = htmlspecialchars($config['domicilio'] ?? '');
$telefono  = htmlspecialchars($config['telefono'] ?? '');
$tipo      = htmlspecialchars($venta['tipo_comprobante'] ?? 'Comprobante');
$numero    = htmlspecialchars($venta['numero']);
$fecha     = date('d/m/Y', strtotime($venta['fecha']));
$total     = '$' . number_format((float)$venta['total'], 2, ',', '.');
$cliente   = htmlspecialchars($venta['cliente_nombre'] ?? 'Cliente');
$tipoPago  = htmlspecialchars($venta['tipo_pago'] ?? '');
$obs       = htmlspecialchars($venta['observaciones'] ?? '');

$logoTag = '';
if ($logoB64) {
    $logoTag = '<img src="data:' . $logoMime . ';base64,' . $logoB64 . '" alt="' . $negocio . '" style="max-height:48px;max-width:160px;display:block;">';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $tipo ?> N° <?= $numero ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f4;font-family:Arial,Helvetica,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f4;">
  <tr>
    <td align="center" style="padding:24px 16px;">

      <!-- Contenedor principal -->
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">

        <!-- ── Header ── -->
        <tr>
          <td style="background-color:#0F2A4A;padding:24px 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="vertical-align:middle;">
                  <?php if ($logoTag): ?>
                    <?= $logoTag ?>
                  <?php else: ?>
                    <span style="color:#ffffff;font-size:20px;font-weight:700;"><?= $negocio ?></span>
                  <?php endif; ?>
                </td>
                <td align="right" style="vertical-align:middle;">
                  <span style="color:rgba(255,255,255,.6);font-size:12px;font-weight:600;letter-spacing:.5px;text-transform:uppercase;">Comprobante</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- ── Franja dorada ── -->
        <tr>
          <td style="background-color:#E8B94C;padding:6px 32px;">
            <span style="color:#0F2A4A;font-size:12px;font-weight:700;letter-spacing:.4px;"><?= $negocio ?></span>
          </td>
        </tr>

        <!-- ── Saludo ── -->
        <tr>
          <td style="padding:32px 32px 0;">
            <p style="margin:0;font-size:15px;color:#1a1a1a;">Hola, <strong><?= $cliente ?></strong>:</p>
            <p style="margin:12px 0 0;font-size:14px;color:#555;line-height:1.6;">
              Adjuntamos tu comprobante en formato PDF. A continuación encontrás el resumen:
            </p>
          </td>
        </tr>

        <!-- ── Caja resumen ── -->
        <tr>
          <td style="padding:20px 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f8f7f4;border-radius:8px;border:1px solid #e8e4dc;">
              <tr>
                <td style="padding:20px 24px;">
                  <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                      <td style="padding:5px 0;">
                        <span style="font-size:12px;color:#888;text-transform:uppercase;letter-spacing:.5px;font-weight:700;">Tipo</span>
                      </td>
                      <td align="right" style="padding:5px 0;">
                        <span style="font-size:13px;color:#1a1a1a;font-weight:600;"><?= $tipo ?></span>
                      </td>
                    </tr>
                    <tr>
                      <td style="padding:5px 0;border-top:1px solid #e8e4dc;">
                        <span style="font-size:12px;color:#888;text-transform:uppercase;letter-spacing:.5px;font-weight:700;">Número</span>
                      </td>
                      <td align="right" style="padding:5px 0;border-top:1px solid #e8e4dc;">
                        <span style="font-size:13px;color:#1a1a1a;font-weight:600;"><?= $numero ?></span>
                      </td>
                    </tr>
                    <tr>
                      <td style="padding:5px 0;border-top:1px solid #e8e4dc;">
                        <span style="font-size:12px;color:#888;text-transform:uppercase;letter-spacing:.5px;font-weight:700;">Fecha</span>
                      </td>
                      <td align="right" style="padding:5px 0;border-top:1px solid #e8e4dc;">
                        <span style="font-size:13px;color:#1a1a1a;"><?= $fecha ?></span>
                      </td>
                    </tr>
                    <?php if ($tipoPago): ?>
                    <tr>
                      <td style="padding:5px 0;border-top:1px solid #e8e4dc;">
                        <span style="font-size:12px;color:#888;text-transform:uppercase;letter-spacing:.5px;font-weight:700;">Forma de pago</span>
                      </td>
                      <td align="right" style="padding:5px 0;border-top:1px solid #e8e4dc;">
                        <span style="font-size:13px;color:#1a1a1a;"><?= $tipoPago ?></span>
                      </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                      <td style="padding:14px 0 5px;border-top:2px solid #e8e4dc;">
                        <span style="font-size:13px;color:#0F2A4A;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Total</span>
                      </td>
                      <td align="right" style="padding:14px 0 5px;border-top:2px solid #e8e4dc;">
                        <span style="font-size:22px;color:#0F2A4A;font-weight:700;"><?= $total ?></span>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <?php if ($obs): ?>
        <!-- ── Observaciones ── -->
        <tr>
          <td style="padding:0 32px 20px;">
            <p style="margin:0;font-size:12px;color:#888;font-style:italic;"><?= $obs ?></p>
          </td>
        </tr>
        <?php endif; ?>

        <!-- ── CTA adjunto ── -->
        <tr>
          <td style="padding:0 32px 28px;" align="center">
            <table cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="background-color:#E8B94C;border-radius:6px;padding:12px 28px;">
                  <span style="color:#0F2A4A;font-size:13px;font-weight:700;">📎 Comprobante PDF adjunto a este mail</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- ── Datos del comercio ── -->
        <tr>
          <td style="padding:20px 32px;border-top:1px solid #eee;">
            <p style="margin:0;font-size:13px;color:#333;font-weight:700;"><?= $negocio ?></p>
            <?php if ($cuit): ?>
              <p style="margin:4px 0 0;font-size:12px;color:#777;">CUIT: <?= $cuit ?></p>
            <?php endif; ?>
            <?php if ($domicilio): ?>
              <p style="margin:4px 0 0;font-size:12px;color:#777;"><?= $domicilio ?></p>
            <?php endif; ?>
            <?php if ($telefono): ?>
              <p style="margin:4px 0 0;font-size:12px;color:#777;"><?= $telefono ?></p>
            <?php endif; ?>
          </td>
        </tr>

        <!-- ── Footer BRON ── -->
        <tr>
          <td style="background-color:#1a1a1a;padding:18px 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td>
                  <p style="margin:0;font-size:11px;color:rgba(255,255,255,.5);line-height:1.6;">
                    Este sistema de gestión es desarrollado por
                    <strong style="color:rgba(255,255,255,.75);">BRON — Sistemas y Programación</strong><br>
                    Soluciones de gestión para comercios · Software a medida
                  </p>
                </td>
                <td align="right" style="vertical-align:middle;">
                  <p style="margin:0;font-size:11px;color:rgba(255,255,255,.4);line-height:1.8;text-align:right;">
                    hola@bron.com.ar<br>
                    +54 9 11 6519-0472
                  </p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

      </table>
      <!-- /Contenedor principal -->

    </td>
  </tr>
</table>

</body>
</html>
