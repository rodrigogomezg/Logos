<?php

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

// Genera el QR fiscal exigido por AFIP (RG 4892) y lo devuelve como data URI PNG,
// o null si la venta no tiene CAE (Remito/Presupuesto, o factura sin CAE todavía).
class AfipQr {

    private const TIPO_CMP = [
        'FC A-ELECT' => 1,
        'FC B-ELECT' => 6,
    ];

    public static function generar(array $venta, array $config): ?string {
        if (empty($venta['cae']) || empty($venta['numero_afip'])) {
            return null;
        }

        $tipoCmp = self::TIPO_CMP[$venta['tipo_comprobante']] ?? null;
        if ($tipoCmp === null) {
            return null;
        }

        $nroCmp = (int)preg_replace('/\D/', '', (string)$venta['numero_afip']);
        if ($nroCmp === 0) {
            $nroCmp = (int)$venta['id'];
        }

        $cuitCliente = preg_replace('/\D/', '', (string)($venta['cliente_cuit'] ?? ''));
        if ($cuitCliente !== '') {
            $tipoDocRec = 80; // CUIT
            $nroDocRec  = (int)$cuitCliente;
        } else {
            $tipoDocRec = 99; // Consumidor final
            $nroDocRec  = 0;
        }

        $payload = [
            'ver'        => 1,
            'fecha'      => $venta['fecha'],
            'cuit'       => (int)preg_replace('/\D/', '', (string)$config['cuit']),
            'ptoVta'     => (int)$config['punto_venta'],
            'tipoCmp'    => $tipoCmp,
            'nroCmp'     => $nroCmp,
            'importe'    => round((float)$venta['total'], 2),
            'moneda'     => 'PES',
            'ctz'        => 1,
            'tipoDocRec' => $tipoDocRec,
            'nroDocRec'  => $nroDocRec,
            'tipoCodAut' => 'E',
            'codAut'     => (int)preg_replace('/\D/', '', (string)$venta['cae']),
        ];

        $url = 'https://www.afip.gob.ar/fe/qr/?p=' . base64_encode(json_encode($payload));

        $result = (new Builder())->build(
            writer: new PngWriter(),
            data: $url,
            size: 220,
            margin: 4,
        );

        return $result->getDataUri();
    }
}
