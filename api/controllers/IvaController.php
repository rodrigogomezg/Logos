<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Validadores.php';

class IvaController {

    private static function parseFechas(): array {
        $desde = $_GET['desde'] ?? date('Y-m-01');
        $hasta = $_GET['hasta'] ?? date('Y-m-d');
        try {
            Validadores::validarFecha($desde, 'desde');
            Validadores::validarFecha($hasta, 'hasta');
            Validadores::validarRangoFechas($desde, $hasta);
        } catch (Throwable $e) { json(400, ['error' => $e->getMessage()]); }
        return [$desde, $hasta];
    }

    private function consultaVentas(string $desde, string $hasta): array {
        $stmt = DB::get()->prepare("
            SELECT
                v.id,
                v.fecha,
                v.tipo_comprobante,
                COALESCE(v.punto_venta, 0)  AS punto_venta,
                COALESCE(v.numero_afip, '') AS numero_afip,
                v.cae,
                v.total,
                c.nombre        AS cliente_nombre,
                c.cuit          AS cliente_cuit,
                c.condicion_iva AS cliente_condicion_iva,
                COALESCE(SUM(
                    CASE WHEN COALESCE(p.iva_porcentaje, 21) = 21
                    THEN vi.cantidad * vi.precio_unitario / 1.21 ELSE 0 END
                ), 0) AS neto_21,
                COALESCE(SUM(
                    CASE WHEN COALESCE(p.iva_porcentaje, 21) = 21
                    THEN vi.cantidad * vi.precio_unitario * 0.21 / 1.21 ELSE 0 END
                ), 0) AS iva_21,
                COALESCE(SUM(
                    CASE WHEN p.iva_porcentaje = 10.5
                    THEN vi.cantidad * vi.precio_unitario / 1.105 ELSE 0 END
                ), 0) AS neto_105,
                COALESCE(SUM(
                    CASE WHEN p.iva_porcentaje = 10.5
                    THEN vi.cantidad * vi.precio_unitario * 0.105 / 1.105 ELSE 0 END
                ), 0) AS iva_105,
                COALESCE(SUM(
                    CASE WHEN p.iva_porcentaje = 0
                    THEN vi.cantidad * vi.precio_unitario ELSE 0 END
                ), 0) AS neto_exento
            FROM ventas v
            LEFT JOIN clientes c     ON c.id  = v.cliente_id
            LEFT JOIN venta_items vi ON vi.venta_id = v.id
            LEFT JOIN productos p    ON p.id  = vi.producto_id
            WHERE v.estado != 'anulado'
              AND v.tipo_comprobante NOT IN ('Presupuesto', 'Remito', 'X')
              AND v.fecha BETWEEN ? AND ?
            GROUP BY v.id
            ORDER BY v.fecha ASC, v.id ASC
        ");
        $stmt->execute([$desde, $hasta]);
        $rows = $stmt->fetchAll();

        $totales = ['total' => 0, 'neto_21' => 0, 'iva_21' => 0, 'neto_105' => 0, 'iva_105' => 0, 'neto_exento' => 0];
        foreach ($rows as &$r) {
            $r['total']       = (float)$r['total'];
            $r['neto_21']     = round((float)$r['neto_21'],     2);
            $r['iva_21']      = round((float)$r['iva_21'],      2);
            $r['neto_105']    = round((float)$r['neto_105'],    2);
            $r['iva_105']     = round((float)$r['iva_105'],     2);
            $r['neto_exento'] = round((float)$r['neto_exento'], 2);
            $r['es_nc']       = str_starts_with($r['tipo_comprobante'], 'NC');
            foreach (['total','neto_21','iva_21','neto_105','iva_105','neto_exento'] as $k) {
                $totales[$k] += $r[$k] * ($r['es_nc'] ? -1 : 1);
            }
        }
        unset($r);
        foreach ($totales as &$v) { $v = round($v, 2); }
        unset($v);

        return ['filas' => $rows, 'totales' => $totales];
    }

    private function consultaCompras(string $desde, string $hasta): array {
        $stmt = DB::get()->prepare("
            SELECT
                c.id,
                c.fecha,
                c.tipo_comprobante,
                c.numero_comprobante,
                pr.nombre AS proveedor_nombre,
                pr.cuit   AS proveedor_cuit,
                COALESCE(c.subtotal, 0)                    AS subtotal,
                COALESCE(c.iva_monto, 0)                   AS iva_total,
                COALESCE(c.percepcion_iibb_monto, 0)       AS percepcion_iibb,
                COALESCE(c.percepcion_iibb_porcentaje, 0)  AS percepcion_iibb_pct,
                c.total,
                COALESCE(SUM(
                    CASE WHEN ci.iva_porcentaje = 21
                    THEN ci.cantidad * ci.costo_unitario ELSE 0 END
                ), 0) AS neto_21,
                COALESCE(SUM(
                    CASE WHEN ci.iva_porcentaje = 21
                    THEN ci.iva_monto ELSE 0 END
                ), 0) AS iva_21,
                COALESCE(SUM(
                    CASE WHEN ci.iva_porcentaje = 10.5
                    THEN ci.cantidad * ci.costo_unitario ELSE 0 END
                ), 0) AS neto_105,
                COALESCE(SUM(
                    CASE WHEN ci.iva_porcentaje = 10.5
                    THEN ci.iva_monto ELSE 0 END
                ), 0) AS iva_105,
                COALESCE(SUM(
                    CASE WHEN ci.iva_porcentaje = 0
                    THEN ci.cantidad * ci.costo_unitario ELSE 0 END
                ), 0) AS neto_exento
            FROM compras c
            LEFT JOIN proveedores pr ON pr.id = c.proveedor_id
            LEFT JOIN compra_items ci ON ci.compra_id = c.id
            WHERE c.estado != 'anulado'
              AND c.fecha BETWEEN ? AND ?
            GROUP BY c.id
            ORDER BY c.fecha ASC, c.id ASC
        ");
        $stmt->execute([$desde, $hasta]);
        $rows = $stmt->fetchAll();

        $totales = ['total' => 0, 'subtotal' => 0, 'iva_total' => 0, 'percepcion_iibb' => 0,
                    'neto_21' => 0, 'iva_21' => 0, 'neto_105' => 0, 'iva_105' => 0, 'neto_exento' => 0];
        foreach ($rows as &$r) {
            foreach (['total','subtotal','iva_total','percepcion_iibb',
                      'neto_21','iva_21','neto_105','iva_105','neto_exento'] as $k) {
                $r[$k] = round((float)$r[$k], 2);
                $totales[$k] += $r[$k];
            }
        }
        unset($r);
        foreach ($totales as &$v) { $v = round($v, 2); }
        unset($v);

        return ['filas' => $rows, 'totales' => $totales];
    }

    public function ventas(): void {
        [$desde, $hasta] = self::parseFechas();
        $data = $this->consultaVentas($desde, $hasta);
        json(200, array_merge(['desde' => $desde, 'hasta' => $hasta], $data));
    }

    public function compras(): void {
        [$desde, $hasta] = self::parseFechas();
        $data = $this->consultaCompras($desde, $hasta);
        json(200, array_merge(['desde' => $desde, 'hasta' => $hasta], $data));
    }

    public function exportar(): void {
        $tipo = $_GET['tipo'] ?? 'ventas';
        if (!in_array($tipo, ['ventas', 'compras'], true)) json(400, ['error' => 'tipo debe ser ventas o compras']);

        [$desde, $hasta] = self::parseFechas();
        $data = $tipo === 'ventas' ? $this->consultaVentas($desde, $hasta) : $this->consultaCompras($desde, $hasta);

        $filename = "libro_iva_{$tipo}_{$desde}_{$hasta}.csv";
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Cache-Control: no-cache');
        // Anular el Content-Type JSON que pusó index.php
        header('Content-Type: text/csv; charset=utf-8', true);

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8 para Excel
        fwrite($out, "sep=;\r\n"); // fuerza separador en Excel independiente del locale

        $sep = ';';
        if ($tipo === 'ventas') {
            fputcsv($out, ['Fecha','Tipo','Pto.Vta','N° AFIP','CAE','Cliente','CUIT','Cond.IVA','Neto 21%','IVA 21%','Neto 10.5%','IVA 10.5%','Exento','Total'], $sep);
            foreach ($data['filas'] as $r) {
                fputcsv($out, [
                    $r['fecha'], $r['tipo_comprobante'], $r['punto_venta'], $r['numero_afip'], $r['cae'] ?? '',
                    $r['cliente_nombre'] ?? 'Consumidor Final', $r['cliente_cuit'] ?? '', $r['cliente_condicion_iva'] ?? '',
                    $r['neto_21'], $r['iva_21'], $r['neto_105'], $r['iva_105'], $r['neto_exento'], $r['total'],
                ], $sep);
            }
            $t = $data['totales'];
            fputcsv($out, ['','','','','','TOTALES','','', $t['neto_21'],$t['iva_21'],$t['neto_105'],$t['iva_105'],$t['neto_exento'],$t['total']], $sep);
        } else {
            fputcsv($out, ['Fecha','Tipo','N° Comprobante','Proveedor','CUIT','Neto 21%','IVA 21%','Neto 10.5%','IVA 10.5%','Exento','IVA Total','Perc.IIBB','Total'], $sep);
            foreach ($data['filas'] as $r) {
                fputcsv($out, [
                    $r['fecha'], $r['tipo_comprobante'], $r['numero_comprobante'] ?? '',
                    $r['proveedor_nombre'] ?? '', $r['proveedor_cuit'] ?? '',
                    $r['neto_21'],$r['iva_21'],$r['neto_105'],$r['iva_105'],$r['neto_exento'],
                    $r['iva_total'],$r['percepcion_iibb'],$r['total'],
                ], $sep);
            }
            $t = $data['totales'];
            fputcsv($out, ['','','TOTALES','','', $t['neto_21'],$t['iva_21'],$t['neto_105'],$t['iva_105'],$t['neto_exento'],$t['iva_total'],$t['percepcion_iibb'],$t['total']], $sep);
        }

        fclose($out);
        exit;
    }
}
