<?php
require __DIR__ . '/vendor/autoload.php';

$config = [
    'razon_social'  => 'BULFON GUILLERMO JESUS',
    'cuit'          => '20396273455',
    'condicion_iva' => 'Responsable Inscripto',
    'domicilio'     => 'Av. Luis María Campos 411, CP1426, CABA',
    'iibb'          => '1590479-02',
    'telefono'      => '11-3521-1985',
    'website'       => 'www.bronargentina.com.ar',
    'punto_venta'   => 7,
    'iva_porcentaje'=> 21,
];

$venta = [
    'tipo_comprobante'       => 'FC B-ELECT',
    'numero'                 => '00000900',
    'fecha'                  => '2026-06-25',
    'total'                  => 189600.02,
    'envio_precio'           => 5000,
    'observaciones'          => null,
    'envio_direccion'        => null,
    'numero_afip'            => '900',
    'cae'                    => '75312456987045',
    'cliente_nombre'         => 'Ferretería del Sur S.R.L.',
    'cliente_cuit'           => '30123456789',
    'cliente_condicion_iva'  => 'Resp. Inscripto',
    'cliente_domicilio'      => 'Av. Rivadavia 1234',
    'cliente_localidad'      => 'Morón',
    'cliente_provincia'      => 'Buenos Aires',
    'cliente_telefono'       => '11-4444-5555',
    'qr_data_uri'            => null,
    'items' => [
        ['codigo'=>'10234','nombre'=>'Cemento Loma Negra x50kg','cantidad'=>10,'precio_unitario'=>8500,'precio_original'=>null,'ajuste_desc'=>null,'ajuste_visible'=>false,'subtotal'=>85000],
        ['codigo'=>'20567','nombre'=>'Hierro del 8 x 12m','cantidad'=>24,'precio_unitario'=>3200,'precio_original'=>null,'ajuste_desc'=>null,'ajuste_visible'=>false,'subtotal'=>76800],
    ],
];

ob_start();
require __DIR__ . '/pos/templates/comprobante_pdf.php';
$html = ob_get_clean();

file_put_contents(__DIR__ . '/scratch_test_factura.html', $html);

$options = new Dompdf\Options(['isRemoteEnabled' => true]);
$options->setChroot([realpath(__DIR__)]);
$dompdf = new Dompdf\Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
file_put_contents(__DIR__ . '/scratch_test_factura.pdf', $dompdf->output());
echo "done\n";
