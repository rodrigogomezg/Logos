<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Validadores.php';

class ReportesController {

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

    // ── Resumen ejecutivo ─────────────────────────────────────────────────

    private static function fetchResumenEjecutivo(string $desde, string $hasta): array {
        $db = DB::get();

        $stmt = $db->prepare("
            SELECT COALESCE(SUM(vi.cantidad * vi.precio_unitario), 0) AS ventas,
                   COALESCE(SUM(vi.cantidad * vi.costo_unitario),  0) AS costo
            FROM venta_items vi JOIN ventas v ON v.id = vi.venta_id
            WHERE v.estado = 'completado' AND v.fecha BETWEEN ? AND ?
        ");
        $stmt->execute([$desde, $hasta]);
        $g = $stmt->fetch();

        $stmt = $db->prepare("
            SELECT COALESCE(SUM(total), 0)     AS total_compras,
                   COALESCE(SUM(iva_monto), 0) AS iva_compras
            FROM compras WHERE estado = 'completado' AND fecha BETWEEN ? AND ?
        ");
        $stmt->execute([$desde, $hasta]);
        $c = $stmt->fetch();

        $stmt = $db->prepare("
            SELECT DATE(fecha) AS dia, COUNT(id) AS cant, COALESCE(SUM(total), 0) AS monto
            FROM ventas WHERE estado = 'completado' AND fecha BETWEEN ? AND ?
            GROUP BY dia ORDER BY dia
        ");
        $stmt->execute([$desde, $hasta]);
        $porDia = $stmt->fetchAll();

        $stmt = $db->prepare("
            SELECT tipo_pago, COALESCE(SUM(total), 0) AS monto
            FROM ventas WHERE estado = 'completado' AND fecha BETWEEN ? AND ?
            GROUP BY tipo_pago
        ");
        $stmt->execute([$desde, $hasta]);
        $cobros = [];
        foreach ($stmt->fetchAll() as $r) {
            $cobros[$r['tipo_pago']] = round((float)$r['monto'], 2);
        }

        $ventas   = (float)$g['ventas'];
        $costo    = (float)$g['costo'];
        $ganancia = $ventas - $costo;
        $compras  = (float)$c['total_compras'];

        return [
            'desde'          => $desde,
            'hasta'          => $hasta,
            'ventas'         => round($ventas,   2),
            'costo'          => round($costo,    2),
            'ganancia_bruta' => round($ganancia, 2),
            'margen_pct'     => $ventas > 0 ? round($ganancia / $ventas * 100, 1) : 0,
            'total_compras'  => round($compras,  2),
            'iva_compras'    => round((float)$c['iva_compras'], 2),
            'balance'        => round($ventas - $compras, 2),
            'por_dia'        => array_map(fn($r) => [
                'dia'  => $r['dia'],
                'cant' => (int)$r['cant'],
                'monto'=> round((float)$r['monto'], 2),
            ], $porDia),
            'cobros_tipo'    => $cobros,
        ];
    }

    public function resumenEjecutivo(): void {
        [$desde, $hasta] = self::parseFechas();
        json(200, self::fetchResumenEjecutivo($desde, $hasta));
    }

    // ── Rotación de inventario ────────────────────────────────────────────

    private static function fetchRotacionInventario(string $desde, string $hasta, ?string $categoria): array {
        $db = DB::get();

        $catWhere = $categoria !== null ? ' AND p.categoria = ?' : '';
        $params = [$desde, $hasta];
        if ($categoria !== null) $params[] = $categoria;

        $stmt = $db->prepare("
            SELECT p.id, p.nombre, p.categoria, p.marca, p.stock_actual, p.stock_minimo,
                   COALESCE(SUM(vi.cantidad), 0)                      AS unidades_vendidas,
                   COALESCE(SUM(vi.cantidad * vi.precio_unitario), 0) AS ingresos,
                   COALESCE(SUM(vi.cantidad * vi.costo_unitario),  0) AS costo,
                   CASE WHEN p.stock_actual > 0
                        THEN ROUND(COALESCE(SUM(vi.cantidad), 0) / p.stock_actual, 2)
                        ELSE NULL END AS indice_rotacion
            FROM productos p
            LEFT JOIN venta_items vi ON p.id = vi.producto_id
            LEFT JOIN ventas v       ON v.id = vi.venta_id
                       AND v.estado = 'completado' AND v.fecha BETWEEN ? AND ?
            WHERE p.activo = 1$catWhere
            GROUP BY p.id
            ORDER BY unidades_vendidas DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $totUnidades = 0.0;
        $totIngresos = 0.0;
        $totCosto    = 0.0;
        $sinMov      = 0;
        $enQuiebre   = 0;

        $productos = array_map(function ($r) use (&$totUnidades, &$totIngresos, &$totCosto, &$sinMov, &$enQuiebre) {
            $u   = (float)$r['unidades_vendidas'];
            $ing = (float)$r['ingresos'];
            $cos = (float)$r['costo'];
            if ($u == 0) $sinMov++;
            if ((float)$r['stock_actual'] <= (float)$r['stock_minimo'] && (float)$r['stock_minimo'] > 0) $enQuiebre++;
            $totUnidades += $u;
            $totIngresos += $ing;
            $totCosto    += $cos;
            return [
                'id'               => (int)$r['id'],
                'nombre'           => $r['nombre'],
                'categoria'        => $r['categoria'] ?? '',
                'marca'            => $r['marca'] ?? '',
                'stock_actual'     => round((float)$r['stock_actual'], 2),
                'stock_minimo'     => round((float)$r['stock_minimo'], 2),
                'unidades_vendidas'=> round($u, 2),
                'ingresos'         => round($ing, 2),
                'costo'            => round($cos, 2),
                'indice_rotacion'  => $r['indice_rotacion'] !== null ? (float)$r['indice_rotacion'] : null,
            ];
        }, $rows);

        return [
            'desde'    => $desde,
            'hasta'    => $hasta,
            'productos'=> $productos,
            'totales'  => [
                'productos'      => count($rows),
                'unidades'       => round($totUnidades, 2),
                'ingresos'       => round($totIngresos, 2),
                'costo'          => round($totCosto,    2),
                'sin_movimiento' => $sinMov,
                'en_quiebre'     => $enQuiebre,
            ],
        ];
    }

    public function rotacionInventario(): void {
        [$desde, $hasta] = self::parseFechas();
        $categoria = isset($_GET['categoria']) && $_GET['categoria'] !== '' ? trim($_GET['categoria']) : null;
        json(200, self::fetchRotacionInventario($desde, $hasta, $categoria));
    }

    // ── Compras por proveedor ─────────────────────────────────────────────

    private static function fetchComprasPorProveedor(string $desde, string $hasta, ?int $proveedorId): array {
        $db = DB::get();

        $provWhere = $proveedorId !== null ? ' AND pv.id = ?' : '';
        $params = [$desde, $hasta];
        if ($proveedorId !== null) $params[] = $proveedorId;

        $stmt = $db->prepare("
            SELECT pv.id, pv.nombre, pv.cuit,
                   COUNT(c.id)                              AS cantidad_ordenes,
                   COALESCE(SUM(c.subtotal),  0)            AS subtotal,
                   COALESCE(SUM(c.iva_monto), 0)            AS iva,
                   COALESCE(SUM(c.percepcion_iibb_monto),0) AS iibb,
                   COALESCE(SUM(c.total),     0)            AS total,
                   MIN(c.fecha)                             AS primera,
                   MAX(c.fecha)                             AS ultima,
                   pv.saldo_cuenta_corriente
            FROM proveedores pv
            LEFT JOIN compras c ON pv.id = c.proveedor_id
                       AND c.estado = 'completado' AND c.fecha BETWEEN ? AND ?
            WHERE pv.activo = 1$provWhere
            GROUP BY pv.id
            ORDER BY total DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $totOrdenes  = 0;
        $totSubtotal = 0.0;
        $totIva      = 0.0;
        $totTotal    = 0.0;

        $filas = array_map(function ($r) use (&$totOrdenes, &$totSubtotal, &$totIva, &$totTotal) {
            $totOrdenes  += (int)$r['cantidad_ordenes'];
            $totSubtotal += (float)$r['subtotal'];
            $totIva      += (float)$r['iva'];
            $totTotal    += (float)$r['total'];
            return [
                'id'                    => (int)$r['id'],
                'nombre'                => $r['nombre'],
                'cuit'                  => $r['cuit'] ?? '',
                'cantidad_ordenes'      => (int)$r['cantidad_ordenes'],
                'subtotal'              => round((float)$r['subtotal'], 2),
                'iva'                   => round((float)$r['iva'], 2),
                'iibb'                  => round((float)$r['iibb'], 2),
                'total'                 => round((float)$r['total'], 2),
                'primera'               => $r['primera'],
                'ultima'                => $r['ultima'],
                'saldo_cuenta_corriente'=> round((float)$r['saldo_cuenta_corriente'], 2),
            ];
        }, $rows);

        return [
            'desde'   => $desde,
            'hasta'   => $hasta,
            'filas'   => $filas,
            'totales' => [
                'ordenes'  => $totOrdenes,
                'subtotal' => round($totSubtotal, 2),
                'iva'      => round($totIva,      2),
                'total'    => round($totTotal,    2),
            ],
        ];
    }

    public function comprasPorProveedor(): void {
        [$desde, $hasta] = self::parseFechas();
        $proveedorId = isset($_GET['proveedor_id']) && is_numeric($_GET['proveedor_id'])
            ? (int)$_GET['proveedor_id'] : null;
        json(200, self::fetchComprasPorProveedor($desde, $hasta, $proveedorId));
    }

    // ── Flujo de caja ─────────────────────────────────────────────────────

    private static function fetchFlujoCaja(string $desde, string $hasta, string $agrupar): array {
        $db = DB::get();

        $fmtMap = ['dia' => '%Y-%m-%d', 'semana' => '%Y-W%u', 'mes' => '%Y-%m'];
        $fmt    = $fmtMap[$agrupar] ?? '%Y-%m-%d';

        // Ventas cobradas al contado: excluye ventas 100% CC y descuenta la
        // porción CC de pagos mixtos. Las CC solo se cuentan cuando el cliente paga.
        $stmt = $db->prepare("
            SELECT DATE_FORMAT(v.fecha, ?) AS periodo,
                   COALESCE(SUM(
                       CASE v.tipo_pago
                           WHEN 'cc'    THEN 0
                           WHEN 'mixto' THEN v.total - COALESCE(vp_cc.monto_cc, 0)
                           ELSE v.total
                       END
                   ), 0) AS ingresos
            FROM ventas v
            LEFT JOIN (
                SELECT venta_id, SUM(monto) AS monto_cc
                FROM venta_pagos WHERE tipo_pago = 'cc'
                GROUP BY venta_id
            ) vp_cc ON vp_cc.venta_id = v.id
            WHERE v.estado = 'completado' AND v.fecha BETWEEN ? AND ?
            GROUP BY periodo ORDER BY MIN(v.fecha)
        ");
        $stmt->execute([$fmt, $desde, $hasta]);
        $ingrMap = array_column($stmt->fetchAll(), 'ingresos', 'periodo');

        // Ingresos de caja: cobros de CC de clientes + ingresos manuales
        $stmt = $db->prepare("
            SELECT DATE_FORMAT(DATE(cm.creado_en), ?) AS periodo,
                   COALESCE(SUM(cm.monto), 0) AS ingresos_caja
            FROM caja_movimientos cm
            WHERE cm.tipo = 'ingreso' AND DATE(cm.creado_en) BETWEEN ? AND ?
            GROUP BY periodo
        ");
        $stmt->execute([$fmt, $desde, $hasta]);
        $ingrCajaMap = array_column($stmt->fetchAll(), 'ingresos_caja', 'periodo');

        $stmt = $db->prepare("
            SELECT DATE_FORMAT(fecha, ?) AS periodo, COALESCE(SUM(total), 0) AS egresos
            FROM compras WHERE estado = 'completado' AND fecha BETWEEN ? AND ?
            GROUP BY periodo ORDER BY MIN(fecha)
        ");
        $stmt->execute([$fmt, $desde, $hasta]);
        $egresMap = array_column($stmt->fetchAll(), 'egresos', 'periodo');

        $stmt = $db->prepare("
            SELECT DATE_FORMAT(DATE(cm.creado_en), ?) AS periodo, COALESCE(SUM(cm.monto), 0) AS retiros
            FROM caja_movimientos cm
            WHERE cm.tipo = 'retiro' AND DATE(cm.creado_en) BETWEEN ? AND ?
            GROUP BY periodo
        ");
        $stmt->execute([$fmt, $desde, $hasta]);
        $retMap = array_column($stmt->fetchAll(), 'retiros', 'periodo');

        $periodos = array_unique(array_merge(
            array_keys($ingrMap), array_keys($ingrCajaMap),
            array_keys($egresMap), array_keys($retMap)
        ));
        sort($periodos);

        $saldoAcum = 0.0;
        $serie = [];
        foreach ($periodos as $per) {
            $ing     = (float)($ingrMap[$per]     ?? 0);
            $ingCaja = (float)($ingrCajaMap[$per] ?? 0);
            $egr     = (float)($egresMap[$per]    ?? 0);
            $ret     = (float)($retMap[$per]      ?? 0);
            $neto    = $ing + $ingCaja - $egr - $ret;
            $saldoAcum += $neto;
            $serie[] = [
                'periodo'       => $per,
                'ingresos'      => round($ing,       2),
                'ingresos_caja' => round($ingCaja,   2),
                'egresos'       => round($egr,       2),
                'retiros'       => round($ret,       2),
                'saldo_neto'    => round($neto,      2),
                'saldo_acum'    => round($saldoAcum, 2),
            ];
        }

        $totIng     = array_sum(array_column($serie, 'ingresos'));
        $totIngCaja = array_sum(array_column($serie, 'ingresos_caja'));
        $totEgr     = array_sum(array_column($serie, 'egresos'));
        $totRet     = array_sum(array_column($serie, 'retiros'));

        return [
            'desde'   => $desde,
            'hasta'   => $hasta,
            'agrupar' => $agrupar,
            'serie'   => $serie,
            'totales' => [
                'ingresos'      => round($totIng,     2),
                'ingresos_caja' => round($totIngCaja, 2),
                'egresos'       => round($totEgr,     2),
                'retiros'       => round($totRet,     2),
                'saldo_neto'    => round($totIng + $totIngCaja - $totEgr - $totRet, 2),
            ],
        ];
    }

    public function flujoCaja(): void {
        [$desde, $hasta] = self::parseFechas();
        $agrupar = in_array($_GET['agrupar'] ?? 'dia', ['dia', 'semana', 'mes'])
            ? ($_GET['agrupar'] ?? 'dia') : 'dia';
        json(200, self::fetchFlujoCaja($desde, $hasta, $agrupar));
    }

    // ── Export Excel ──────────────────────────────────────────────────────

    public function exportar(string $tipo): void {
        [$desde, $hasta] = self::parseFechas();

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();

        $hStyle = [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => [
                'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '9B1B2E'],
            ],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ];

        switch ($tipo) {
            case 'resumen-ejecutivo':
                $data = self::fetchResumenEjecutivo($desde, $hasta);
                $sheet->setTitle('Resumen Ejecutivo');
                $headers = ['Fecha', 'Monto Ventas', 'Cant. Operaciones'];
                $sheet->fromArray($headers, null, 'A1');
                $sheet->getStyle('A1:C1')->applyFromArray($hStyle);
                $row = 2;
                foreach ($data['por_dia'] as $d) {
                    $sheet->fromArray([$d['dia'], $d['monto'], $d['cant']], null, 'A' . $row++);
                }
                $row++;
                $sheet->fromArray(['Ventas totales',   $data['ventas']],         null, 'A' . $row++);
                $sheet->fromArray(['Compras totales',  $data['total_compras']],  null, 'A' . $row++);
                $sheet->fromArray(['Ganancia bruta',   $data['ganancia_bruta']], null, 'A' . $row++);
                $sheet->fromArray(['Balance operativo',$data['balance']],        null, 'A' . $row++);
                foreach (range('A', 'C') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
                break;

            case 'rotacion-inventario':
                $categoria = isset($_GET['categoria']) && $_GET['categoria'] !== '' ? trim($_GET['categoria']) : null;
                $data      = self::fetchRotacionInventario($desde, $hasta, $categoria);
                $sheet->setTitle('Rotación Inventario');
                $headers = ['Nombre', 'Categoría', 'Marca', 'Unidades Vendidas', 'Ingresos', 'Costo', 'Stock Actual', 'Índice Rotación'];
                $sheet->fromArray($headers, null, 'A1');
                $sheet->getStyle('A1:H1')->applyFromArray($hStyle);
                $row = 2;
                foreach ($data['productos'] as $p) {
                    $sheet->fromArray([
                        $p['nombre'], $p['categoria'], $p['marca'],
                        $p['unidades_vendidas'], $p['ingresos'], $p['costo'],
                        $p['stock_actual'], $p['indice_rotacion'],
                    ], null, 'A' . $row++);
                }
                foreach (range('A', 'H') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
                break;

            case 'compras-proveedor':
                $provId = isset($_GET['proveedor_id']) && is_numeric($_GET['proveedor_id'])
                    ? (int)$_GET['proveedor_id'] : null;
                $data   = self::fetchComprasPorProveedor($desde, $hasta, $provId);
                $sheet->setTitle('Compras por Proveedor');
                $headers = ['Proveedor', 'CUIT', 'Órdenes', 'Subtotal', 'IVA', 'IIBB', 'Total', 'Primera Compra', 'Última Compra'];
                $sheet->fromArray($headers, null, 'A1');
                $sheet->getStyle('A1:I1')->applyFromArray($hStyle);
                $row = 2;
                foreach ($data['filas'] as $f) {
                    $sheet->fromArray([
                        $f['nombre'], $f['cuit'], $f['cantidad_ordenes'],
                        $f['subtotal'], $f['iva'], $f['iibb'], $f['total'],
                        $f['primera'], $f['ultima'],
                    ], null, 'A' . $row++);
                }
                foreach (range('A', 'I') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
                break;

            case 'flujo-caja':
                $agrupar = in_array($_GET['agrupar'] ?? 'dia', ['dia', 'semana', 'mes'])
                    ? ($_GET['agrupar'] ?? 'dia') : 'dia';
                $data    = self::fetchFlujoCaja($desde, $hasta, $agrupar);
                $sheet->setTitle('Flujo de Caja');
                $headers = ['Período', 'Ventas cobradas', 'Cobros/Ingr. caja', 'Egresos', 'Retiros', 'Saldo Neto', 'Saldo Acumulado'];
                $sheet->fromArray($headers, null, 'A1');
                $sheet->getStyle('A1:G1')->applyFromArray($hStyle);
                $row = 2;
                foreach ($data['serie'] as $s) {
                    $sheet->fromArray([
                        $s['periodo'], $s['ingresos'], $s['ingresos_caja'],
                        $s['egresos'], $s['retiros'], $s['saldo_neto'], $s['saldo_acum'],
                    ], null, 'A' . $row++);
                }
                foreach (range('A', 'G') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
                break;

            default:
                json(400, ['error' => 'Tipo de reporte inválido']);
        }

        $nombre = 'reporte_' . preg_replace('/[^a-z0-9-]/', '', $tipo) . '_' . $desde . '_' . $hasta . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Cache-Control: max-age=0');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}
