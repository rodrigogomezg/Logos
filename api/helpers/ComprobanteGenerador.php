<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/Configuracion.php';
require_once __DIR__ . '/AfipQr.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

class ComprobanteGenerador {

    // Retorna ['pdf' => string_binario, 'venta' => array, 'config' => array]
    // $ocultarDescuentos: pedido de Rodrigo (20/08/2026) — decisión que se
    // toma al generar el documento (no al cargar el ítem en el POS), y solo
    // tiene efecto en Remitos (comprobante_pdf.php la ignora para cualquier
    // otro tipo). Con true, cualquier ítem con descuento se imprime con su
    // precio ORIGINAL (sin descuento) y el total sube en consecuencia — el
    // remito no deja ningún rastro del descuento real, que sigue guardado
    // sin cambios en la venta.
    public static function generarPdf(int $id, bool $ocultarDescuentos = false): array {
        if (!extension_loaded('gd')) {
            json(500, ['error' => 'La extensión GD de PHP no está habilitada. Habilitá "extension=gd" en php.ini y reiniciá Apache.']);
        }

        $db   = DB::get();
        $stmt = $db->prepare("
            SELECT
                v.id,
                LPAD(v.id, 8, '0')  AS numero,
                v.fecha,
                v.tipo_comprobante,
                v.tipo_pago,
                v.total,
                v.observaciones,
                v.envio_precio,
                v.envios_detalle,
                v.envio_direccion,
                v.numero_afip,
                v.cae,
                v.cae_vencimiento,
                v.mp_init_point,
                c.id                AS cliente_id,
                c.nombre            AS cliente_nombre,
                c.cuit              AS cliente_cuit,
                c.condicion_iva     AS cliente_condicion_iva,
                c.domicilio         AS cliente_domicilio,
                c.localidad         AS cliente_localidad,
                c.provincia         AS cliente_provincia,
                c.telefono          AS cliente_telefono
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            WHERE v.id = ?
        ");
        $stmt->execute([$id]);
        $venta = $stmt->fetch();

        if (!$venta) {
            json(404, ['error' => 'Venta no encontrada']);
        }

        $stmt = $db->prepare("
            SELECT
                vi.id,
                vi.producto_id,
                p.codigo,
                COALESCE(vi.nombre_manual, p.nombre) AS nombre,
                vi.cantidad,
                vi.precio_unitario,
                vi.precio_original,
                vi.ajuste_desc,
                vi.ajuste_visible,
                (vi.cantidad * vi.precio_unitario) AS subtotal
            FROM venta_items vi
            LEFT JOIN productos p ON p.id = vi.producto_id
            WHERE vi.venta_id = ?
            ORDER BY vi.id
        ");
        $stmt->execute([$id]);
        $venta['items'] = $stmt->fetchAll();

        $venta['total']          = (float)$venta['total'];
        $venta['envio_precio']   = $venta['envio_precio'] !== null ? (float)$venta['envio_precio'] : null;
        $venta['envios_detalle'] = $venta['envios_detalle'] !== null ? json_decode($venta['envios_detalle'], true) : null;
        foreach ($venta['items'] as &$item) {
            $item['cantidad']        = (float)$item['cantidad'];
            $item['precio_unitario'] = (float)$item['precio_unitario'];
            $item['precio_original'] = $item['precio_original'] !== null ? (float)$item['precio_original'] : null;
            $item['ajuste_visible']  = (bool)$item['ajuste_visible'];
            $item['subtotal']        = (float)$item['subtotal'];
        }
        unset($item);

        $config = Configuracion::get();
        $venta['qr_data_uri'] = AfipQr::generar($venta, $config);

        $venta['mp_qr_data_uri'] = null;
        if (!empty($venta['mp_init_point'])) {
            try {
                $result = (new Builder())->build(
                    writer: new PngWriter(),
                    data:   $venta['mp_init_point'],
                    size:   280,
                    margin: 4,
                );
                $venta['mp_qr_data_uri'] = $result->getDataUri();
            } catch (Throwable) {
                // QR opcional: si GD falla, el PDF se genera sin él
            }
        }

        ob_start();
        require __DIR__ . '/../../pos/templates/comprobante_pdf.php';
        $html = ob_get_clean();

        $options = new Dompdf\Options(['isRemoteEnabled' => true]);
        // El logo vive en DB::dataRoot() (ProgramData), fuera del árbol de la
        // app — sin este segundo chroot, Options::validateLocalUri() lo
        // rechaza en silencio (mismo motivo que db.local.php, ver CLAUDE.md)
        // y el <img> del logo queda vacío en el PDF sin ningún error visible.
        $options->setChroot([realpath(__DIR__ . '/../..'), DB::dataRoot()]);
        $dompdf = new Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return ['pdf' => $dompdf->output(), 'venta' => $venta, 'config' => $config];
    }
}
