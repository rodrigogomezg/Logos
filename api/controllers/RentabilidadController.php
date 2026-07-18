<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Validadores.php';

class RentabilidadController {

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

    private static function buildWhere(string $desde, string $hasta): array {
        $where  = ["v.estado = 'completado'", "v.fecha BETWEEN ? AND ?"];
        $params = [$desde, $hasta];
        return [$where, $params];
    }

    public function resumen(): void {
        [$desde, $hasta] = self::parseFechas();
        [$where, $params] = self::buildWhere($desde, $hasta);
        $whereStr = implode(' AND ', $where);

        $db = DB::get();

        // Global
        $global = $db->prepare("
            SELECT
                COALESCE(SUM(vi.cantidad * vi.precio_unitario), 0) AS ventas,
                COALESCE(SUM(vi.cantidad * vi.costo_unitario),  0) AS costo
            FROM venta_items vi JOIN ventas v ON v.id = vi.venta_id
            WHERE $whereStr
        ");
        $global->execute($params);
        $g = $global->fetch();

        // Convención unificada con el dashboard: "Ventas" incluye los envíos
        // cobrados (plata facturada); el costo es solo de ítems. Los envíos
        // aparecen como grupo propio para que el global cierre con el desglose.
        $stmtE = $db->prepare("SELECT COALESCE(SUM(v.envio_precio), 0) FROM ventas v WHERE $whereStr");
        $stmtE->execute($params);
        $envio = (float)$stmtE->fetchColumn();

        $ventas  = (float)$g['ventas'] + $envio;
        $costo   = (float)$g['costo'];
        $ganancia = $ventas - $costo;

        // Por categoría
        $stmt = $db->prepare("
            SELECT
                COALESCE(p.categoria, '(Sin categoría)') AS grupo,
                COALESCE(SUM(vi.cantidad * vi.precio_unitario), 0) AS ventas,
                COALESCE(SUM(vi.cantidad * vi.costo_unitario),  0) AS costo
            FROM venta_items vi
            JOIN ventas v    ON v.id  = vi.venta_id
            JOIN productos p ON p.id  = vi.producto_id
            WHERE $whereStr
            GROUP BY grupo
            ORDER BY ventas DESC
        ");
        $stmt->execute($params);
        $por_categoria = array_map(fn($r) => self::enrich($r), $stmt->fetchAll());

        // Por proveedor
        $stmt = $db->prepare("
            SELECT
                COALESCE(p.proveedor, '(Sin proveedor)') AS grupo,
                COALESCE(SUM(vi.cantidad * vi.precio_unitario), 0) AS ventas,
                COALESCE(SUM(vi.cantidad * vi.costo_unitario),  0) AS costo
            FROM venta_items vi
            JOIN ventas v    ON v.id  = vi.venta_id
            JOIN productos p ON p.id  = vi.producto_id
            WHERE $whereStr
            GROUP BY grupo
            ORDER BY ventas DESC
        ");
        $stmt->execute($params);
        $por_proveedor = array_map(fn($r) => self::enrich($r), $stmt->fetchAll());

        if ($envio > 0.001) {
            $filaEnvio = ['grupo' => '(Envíos)', 'ventas' => round($envio, 2), 'costo' => 0.0,
                          'ganancia' => round($envio, 2), 'margen_pct' => 100.0];
            $por_categoria[] = $filaEnvio;
            $por_proveedor[] = $filaEnvio;
        }

        json(200, [
            'desde'          => $desde,
            'hasta'          => $hasta,
            'ventas'         => round($ventas,   2),
            'costo'          => round($costo,    2),
            'ganancia'       => round($ganancia, 2),
            'margen_pct'     => $ventas > 0 ? round($ganancia / $ventas * 100, 1) : 0,
            'por_categoria'  => $por_categoria,
            'por_proveedor'  => $por_proveedor,
        ]);
    }

    public function tendencia(): void {
        $meses = min(24, max(1, (int)($_GET['meses'] ?? 12)));

        $stmt = DB::get()->prepare("
            SELECT
                DATE_FORMAT(v.fecha, '%Y-%m') AS mes,
                COALESCE(SUM(vi.cantidad * vi.precio_unitario), 0) AS ventas,
                COALESCE(SUM(vi.cantidad * vi.costo_unitario),  0) AS costo
            FROM venta_items vi
            JOIN ventas v ON v.id = vi.venta_id
            WHERE v.estado = 'completado'
              AND v.fecha >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL ? MONTH), '%Y-%m-01')
            GROUP BY mes
            ORDER BY mes ASC
        ");
        $stmt->execute([$meses]);
        $items = $stmt->fetchAll();

        // Envíos por mes: misma convención que resumen (ventas = ítems + envíos)
        $stmtE = DB::get()->prepare("
            SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes, COALESCE(SUM(envio_precio), 0) AS envio
            FROM ventas
            WHERE estado = 'completado'
              AND fecha >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL ? MONTH), '%Y-%m-01')
            GROUP BY mes
        ");
        $stmtE->execute([$meses]);
        $envioPorMes = array_column($stmtE->fetchAll(), 'envio', 'mes');

        $rows = array_map(function ($r) use ($envioPorMes) {
            $v = (float)$r['ventas'] + (float)($envioPorMes[$r['mes']] ?? 0);
            $c = (float)$r['costo'];
            return [
                'mes'        => $r['mes'],
                'ventas'     => round($v, 2),
                'costo'      => round($c, 2),
                'ganancia'   => round($v - $c, 2),
                'margen_pct' => $v > 0 ? round(($v - $c) / $v * 100, 1) : 0,
            ];
        }, $items);

        json(200, ['meses' => $meses, 'datos' => $rows]);
    }

    public function abc(): void {
        [$desde, $hasta] = self::parseFechas();
        [$where, $params] = self::buildWhere($desde, $hasta);
        $whereStr = implode(' AND ', $where);

        $stmt = DB::get()->prepare("
            SELECT
                p.id,
                p.codigo,
                p.nombre,
                COALESCE(p.categoria,  '(Sin cat.)')  AS categoria,
                COALESCE(p.proveedor,  '(Sin prov.)') AS proveedor,
                COALESCE(SUM(vi.cantidad), 0)                                AS cantidad,
                COALESCE(SUM(vi.cantidad * vi.precio_unitario), 0)           AS ventas,
                COALESCE(SUM(vi.cantidad * vi.costo_unitario),  0)           AS costo,
                COALESCE(SUM(vi.cantidad * vi.precio_unitario)
                       - SUM(vi.cantidad * vi.costo_unitario),  0)           AS ganancia
            FROM venta_items vi
            JOIN ventas v    ON v.id  = vi.venta_id
            JOIN productos p ON p.id  = vi.producto_id
            WHERE $whereStr
            GROUP BY p.id
            ORDER BY ganancia DESC
        ");
        $stmt->execute($params);
        $productos = $stmt->fetchAll();

        $total_ganancia = array_sum(array_column($productos, 'ganancia'));
        $acum = 0;
        $result = [];
        foreach ($productos as $p) {
            $p['ventas']   = round((float)$p['ventas'],   2);
            $p['costo']    = round((float)$p['costo'],    2);
            $p['ganancia'] = round((float)$p['ganancia'], 2);
            $p['cantidad'] = round((float)$p['cantidad'], 2);
            $p['margen_pct'] = (float)$p['ventas'] > 0
                ? round((float)$p['ganancia'] / (float)$p['ventas'] * 100, 1) : 0;
            $acum += $p['ganancia'];
            $pct_acum = $total_ganancia > 0 ? $acum / $total_ganancia * 100 : 100;
            $p['pct_ganancia_acum'] = round($pct_acum, 1);
            $p['clase_abc'] = $pct_acum <= 80 ? 'A' : ($pct_acum <= 95 ? 'B' : 'C');
            $result[] = $p;
        }

        json(200, ['desde' => $desde, 'hasta' => $hasta, 'productos' => $result, 'total_ganancia' => round($total_ganancia, 2)]);
    }

    /**
     * GET /rentabilidad/margen-critico?umbral=20
     * Productos del catálogo cuyo margen ACTUAL de lista (precio vs costo
     * vigentes) está bajo el umbral o es negativo — típico de listas de
     * precios desactualizadas por inflación. No depende del período: mira
     * el estado de hoy, que es lo accionable ("actualizá este precio").
     */
    public function margenCritico(): void {
        $umbral = isset($_GET['umbral']) && is_numeric($_GET['umbral']) ? (float)$_GET['umbral'] : 20.0;
        $umbral = max(0, min(90, $umbral));

        $stmt = DB::get()->prepare("
            SELECT id, codigo, nombre,
                   COALESCE(categoria, '(Sin cat.)')  AS categoria,
                   COALESCE(proveedor, '(Sin prov.)') AS proveedor,
                   precio_venta, costo_actual, stock_actual,
                   ROUND((precio_venta - costo_actual) / precio_venta * 100, 1) AS margen_pct
            FROM productos
            WHERE activo = 1 AND costo_actual > 0 AND precio_venta > 0
              AND (precio_venta - costo_actual) / precio_venta * 100 < ?
            ORDER BY margen_pct ASC
        ");
        $stmt->execute([$umbral]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['precio_venta'] = (float)$r['precio_venta'];
            $r['costo_actual'] = (float)$r['costo_actual'];
            $r['stock_actual'] = (float)$r['stock_actual'];
            $r['margen_pct']   = (float)$r['margen_pct'];
        }
        unset($r);

        json(200, ['umbral' => $umbral, 'productos' => $rows]);
    }

    /**
     * GET /rentabilidad/clientes?desde&hasta
     * Ganancia por cliente en el período: cuánto factura y cuánto margen
     * deja cada uno. Complementa el aging de CC: a quién vale la pena
     * financiar. Ingreso = total de venta (incluye envío); costo = ítems.
     */
    public function clientes(): void {
        [$desde, $hasta] = self::parseFechas();
        [$where, $params] = self::buildWhere($desde, $hasta);
        $whereStr = implode(' AND ', $where);

        $db = DB::get();

        $stmt = $db->prepare("
            SELECT COALESCE(v.cliente_id, 0) AS cid,
                   COALESCE(c.nombre, 'Consumidor final') AS nombre,
                   COUNT(*) AS operaciones,
                   COALESCE(SUM(v.total), 0) AS ventas
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            WHERE $whereStr
            GROUP BY cid, nombre
        ");
        $stmt->execute($params);
        $porCliente = [];
        foreach ($stmt->fetchAll() as $r) {
            $porCliente[(int)$r['cid']] = [
                'cliente_id'  => (int)$r['cid'] ?: null,
                'nombre'      => $r['nombre'],
                'operaciones' => (int)$r['operaciones'],
                'ventas'      => (float)$r['ventas'],
                'costo'       => 0.0,
            ];
        }

        $stmt = $db->prepare("
            SELECT COALESCE(v.cliente_id, 0) AS cid,
                   COALESCE(SUM(vi.cantidad * vi.costo_unitario), 0) AS costo
            FROM venta_items vi
            JOIN ventas v ON v.id = vi.venta_id
            WHERE $whereStr
            GROUP BY cid
        ");
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            if (isset($porCliente[(int)$r['cid']])) $porCliente[(int)$r['cid']]['costo'] = (float)$r['costo'];
        }

        $totGanancia = 0.0;
        $tot = ['ventas' => 0.0, 'costo' => 0.0, 'ganancia' => 0.0];
        foreach ($porCliente as &$c) {
            $c['ganancia']   = round($c['ventas'] - $c['costo'], 2);
            $c['margen_pct'] = $c['ventas'] > 0 ? round($c['ganancia'] / $c['ventas'] * 100, 1) : 0;
            $c['ventas']     = round($c['ventas'], 2);
            $c['costo']      = round($c['costo'], 2);
            $tot['ventas']   += $c['ventas'];
            $tot['costo']    += $c['costo'];
            $tot['ganancia'] += $c['ganancia'];
            $totGanancia     += max(0, $c['ganancia']);
        }
        unset($c);

        $filas = array_values($porCliente);
        foreach ($filas as &$c) {
            $c['pct_ganancia'] = $totGanancia > 0 ? round(max(0, $c['ganancia']) / $totGanancia * 100, 1) : 0;
        }
        unset($c);
        usort($filas, fn($a, $b) => $b['ganancia'] <=> $a['ganancia']);

        json(200, [
            'desde'   => $desde,
            'hasta'   => $hasta,
            'filas'   => $filas,
            'totales' => [
                'ventas'     => round($tot['ventas'], 2),
                'costo'      => round($tot['costo'], 2),
                'ganancia'   => round($tot['ganancia'], 2),
                'margen_pct' => $tot['ventas'] > 0 ? round($tot['ganancia'] / $tot['ventas'] * 100, 1) : 0,
            ],
        ]);
    }

    private static function enrich(array $r): array {
        $v = (float)$r['ventas'];
        $c = (float)$r['costo'];
        $g = $v - $c;
        return [
            'grupo'      => $r['grupo'],
            'ventas'     => round($v, 2),
            'costo'      => round($c, 2),
            'ganancia'   => round($g, 2),
            'margen_pct' => $v > 0 ? round($g / $v * 100, 1) : 0,
        ];
    }
}
