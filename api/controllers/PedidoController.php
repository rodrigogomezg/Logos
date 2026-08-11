<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Configuracion.php';

class PedidoController {

    /**
     * POST /pedido/pdf
     * Body: { grupos: [{proveedor, items: [{codigo, nombre, stock_actual, stock_minimo, a_pedir}]}] }
     * Genera y devuelve un PDF de pedido sugerido de stock.
     */
    public function pdf(): void {
        if (!extension_loaded('gd')) {
            json(500, ['error' => 'La extensión GD no está habilitada en php.ini.']);
        }

        $body   = json_decode(file_get_contents('php://input'), true) ?: [];
        $grupos = $body['grupos'] ?? [];

        if (empty($grupos)) {
            json(400, ['error' => 'Sin datos de pedido']);
        }

        $config = Configuracion::get();
        $fecha  = date('d/m/Y');
        $logo   = str_replace('\\', '/', realpath(Configuracion::rutaLogo()));

        ob_start();
        require __DIR__ . '/../../pos/templates/pedido_pdf.php';
        $html = ob_get_clean();

        $options = new Dompdf\Options(['isRemoteEnabled' => true]);
        $options->setChroot([realpath(__DIR__ . '/../..')]);
        $dompdf = new Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        header('Cache-Control: no-store');
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="pedido-stock-' . date('Y-m-d') . '.pdf"');
        echo $dompdf->output();
        exit;
    }
}
