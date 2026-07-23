<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Auth.php';

class VendedoresController {

    public function listar(): void {
        $todos = !empty($_GET['todos']) && Auth::puede('gestionar_vendedores');
        $db    = DB::get();
        $sql   = $todos
            ? "SELECT id, nombre, activo, meta_monto, meta_desde, meta_hasta FROM vendedores ORDER BY nombre"
            : "SELECT id, nombre, activo, meta_monto, meta_desde, meta_hasta FROM vendedores WHERE activo = 1 ORDER BY nombre";
        $filas = $db->query($sql)->fetchAll();
        foreach ($filas as &$f) {
            $f['id']         = (int)$f['id'];
            $f['activo']     = (bool)$f['activo'];
            $f['meta_monto'] = $f['meta_monto'] !== null ? (float)$f['meta_monto'] : null;
        }
        unset($f);
        json(200, $filas);
    }

    public function get(int $id): void {
        $stmt = DB::get()->prepare("SELECT id, nombre, activo, meta_monto, meta_desde, meta_hasta FROM vendedores WHERE id = ?");
        $stmt->execute([$id]);
        $f = $stmt->fetch();
        if (!$f) json(404, ['error' => 'Vendedor no encontrado']);
        $f['id']         = (int)$f['id'];
        $f['activo']     = (bool)$f['activo'];
        $f['meta_monto'] = $f['meta_monto'] !== null ? (float)$f['meta_monto'] : null;
        json(200, $f);
    }

    public function crear(): void {
        Auth::requirePermiso('gestionar_vendedores');
        $body   = json_decode(file_get_contents('php://input'), true) ?: [];
        $nombre = trim($body['nombre'] ?? '');
        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);
        if (mb_strlen($nombre) > 255) json(400, ['error' => 'nombre demasiado largo']);

        $activo     = isset($body['activo'])     ? (int)(bool)$body['activo']     : 1;
        $meta_monto = isset($body['meta_monto']) && is_numeric($body['meta_monto']) ? (float)$body['meta_monto'] : null;
        $meta_desde = $this->fecha($body['meta_desde'] ?? null);
        $meta_hasta = $this->fecha($body['meta_hasta'] ?? null);

        $db = DB::get();
        $db->prepare("INSERT INTO vendedores (nombre, activo, meta_monto, meta_desde, meta_hasta) VALUES (?, ?, ?, ?, ?)")
           ->execute([$nombre, $activo, $meta_monto, $meta_desde, $meta_hasta]);
        $this->get((int)$db->lastInsertId());
    }

    public function actualizar(int $id): void {
        Auth::requirePermiso('gestionar_vendedores');
        $body   = json_decode(file_get_contents('php://input'), true) ?: [];
        $nombre = trim($body['nombre'] ?? '');
        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);
        if (mb_strlen($nombre) > 255) json(400, ['error' => 'nombre demasiado largo']);

        $activo     = isset($body['activo'])     ? (int)(bool)$body['activo']     : 1;
        $meta_monto = isset($body['meta_monto']) && is_numeric($body['meta_monto']) ? (float)$body['meta_monto'] : null;
        $meta_desde = $this->fecha($body['meta_desde'] ?? null);
        $meta_hasta = $this->fecha($body['meta_hasta'] ?? null);

        $stmt = DB::get()->prepare("UPDATE vendedores SET nombre=?, activo=?, meta_monto=?, meta_desde=?, meta_hasta=? WHERE id=?");
        $stmt->execute([$nombre, $activo, $meta_monto, $meta_desde, $meta_hasta, $id]);
        if ($stmt->rowCount() === 0) json(404, ['error' => 'Vendedor no encontrado']);
        $this->get($id);
    }

    public function desactivar(int $id): void {
        Auth::requirePermiso('gestionar_vendedores');
        $stmt = DB::get()->prepare("UPDATE vendedores SET activo = 0 WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) json(404, ['error' => 'Vendedor no encontrado']);
        json(200, ['ok' => true]);
    }

    /** GET /vendedores/reporte/ventas?desde=&hasta=&sucursal_id= */
    public function reporteVentas(): void {
        Auth::requirePermiso('gestionar_vendedores');
        $desde    = $this->fecha($_GET['desde'] ?? null) ?? date('Y-m-01');
        $hasta    = $this->fecha($_GET['hasta'] ?? null) ?? date('Y-m-d');
        $sucursal = isset($_GET['sucursal_id']) && is_numeric($_GET['sucursal_id']) ? (int)$_GET['sucursal_id'] : null;

        $cond   = "v.estado != 'anulado' AND DATE(v.fecha) BETWEEN ? AND ?";
        $params = [$desde, $hasta];
        if ($sucursal !== null) { $cond .= ' AND v.sucursal_id = ?'; $params[] = $sucursal; }

        $stmt = DB::get()->prepare("
            SELECT vd.id, vd.nombre,
                   COALESCE(vr.cant_ventas, 0) AS cant_ventas,
                   COALESCE(vr.total, 0) AS total,
                   CASE WHEN COALESCE(vr.cant_ventas,0) > 0
                        THEN ROUND(COALESCE(vr.total,0)/vr.cant_ventas,2)
                        ELSE 0 END AS ticket_promedio,
                   COALESCE(vc.monto_comisionado, 0) AS monto_comisionado,
                   vd.meta_monto, vd.meta_desde, vd.meta_hasta
            FROM vendedores vd
            LEFT JOIN (
                SELECT v.vendedor_id, COUNT(v.id) AS cant_ventas, SUM(v.total) AS total
                FROM ventas v WHERE $cond GROUP BY v.vendedor_id
            ) vr ON vr.vendedor_id = vd.id
            LEFT JOIN (
                SELECT v.vendedor_id,
                       SUM(CASE
                         WHEN p.comisionable = 1 AND p.comision_tipo = 'porcentaje'
                           THEN vi.cantidad * vi.precio_unitario * p.comision_valor / 100
                         WHEN p.comisionable = 1 AND p.comision_tipo = 'fijo'
                           THEN vi.cantidad * p.comision_valor
                         ELSE 0 END) AS monto_comisionado
                FROM ventas v
                JOIN venta_items vi ON vi.venta_id = v.id
                JOIN productos p ON p.id = vi.producto_id
                WHERE $cond GROUP BY v.vendedor_id
            ) vc ON vc.vendedor_id = vd.id
            ORDER BY total DESC
        ");
        $stmt->execute(array_merge($params, $params));
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['id']                = (int)$r['id'];
            $r['cant_ventas']       = (int)$r['cant_ventas'];
            $r['total']             = (float)$r['total'];
            $r['ticket_promedio']   = (float)$r['ticket_promedio'];
            $r['monto_comisionado'] = (float)$r['monto_comisionado'];
            $r['meta_monto']        = $r['meta_monto'] !== null ? (float)$r['meta_monto'] : null;
        }
        unset($r);
        json(200, $rows);
    }

    /** GET /vendedores/:id/reporte?desde=&hasta=&granularidad=diario|semanal|mensual|anual */
    public function reporteIndividual(int $id): void {
        Auth::requirePermiso('gestionar_vendedores');
        $desde        = $this->fecha($_GET['desde'] ?? null) ?? date('Y-m-01');
        $hasta        = $this->fecha($_GET['hasta'] ?? null) ?? date('Y-m-d');
        $granularidad = $_GET['granularidad'] ?? 'mensual';

        $stmtV = DB::get()->prepare("SELECT id, nombre, meta_monto, meta_desde, meta_hasta FROM vendedores WHERE id = ?");
        $stmtV->execute([$id]);
        $vendedor = $stmtV->fetch();
        if (!$vendedor) json(404, ['error' => 'Vendedor no encontrado']);

        $db = DB::get();

        switch ($granularidad) {
            case 'diario':
                $stmt = $db->prepare("
                    SELECT HOUR(v.creado_en) AS periodo, COALESCE(SUM(v.total),0) AS total, COUNT(*) AS cant
                    FROM ventas v
                    WHERE v.vendedor_id = ? AND v.estado != 'anulado'
                      AND DATE(v.fecha) BETWEEN ? AND ?
                    GROUP BY periodo ORDER BY periodo
                ");
                $stmt->execute([$id, $desde, $hasta]);
                $series = $stmt->fetchAll();
                $mapa = [];
                foreach ($series as $s) $mapa[(int)$s['periodo']] = $s;
                $series = [];
                for ($h = 0; $h < 24; $h++) {
                    $series[] = ['periodo' => $h, 'label' => sprintf('%02d:00', $h),
                        'total' => isset($mapa[$h]) ? (float)$mapa[$h]['total'] : 0,
                        'cant'  => isset($mapa[$h]) ? (int)$mapa[$h]['cant']   : 0];
                }
                break;

            case 'semanal':
                $stmt = $db->prepare("
                    SELECT DATE(v.fecha) AS dia,
                           IF(HOUR(v.creado_en) < 12, 'mañana', 'tarde') AS turno,
                           COALESCE(SUM(v.total),0) AS total, COUNT(*) AS cant
                    FROM ventas v
                    WHERE v.vendedor_id = ? AND v.estado != 'anulado'
                      AND DATE(v.fecha) BETWEEN ? AND ?
                    GROUP BY dia, turno ORDER BY dia, turno
                ");
                $stmt->execute([$id, $desde, $hasta]);
                $series = $stmt->fetchAll();
                foreach ($series as &$s) { $s['total'] = (float)$s['total']; $s['cant'] = (int)$s['cant']; }
                unset($s);
                break;

            case 'anual':
                $stmt = $db->prepare("
                    SELECT DATE_FORMAT(v.fecha, '%Y-%m') AS periodo, DATE_FORMAT(v.fecha, '%b %Y') AS label,
                           COALESCE(SUM(v.total),0) AS total, COUNT(*) AS cant
                    FROM ventas v
                    WHERE v.vendedor_id = ? AND v.estado != 'anulado'
                      AND DATE(v.fecha) BETWEEN ? AND ?
                    GROUP BY periodo ORDER BY periodo
                ");
                $stmt->execute([$id, $desde, $hasta]);
                $series = $stmt->fetchAll();
                foreach ($series as &$s) { $s['total'] = (float)$s['total']; $s['cant'] = (int)$s['cant']; }
                unset($s);
                break;

            default: // mensual
                $stmt = $db->prepare("
                    SELECT DAY(v.fecha) AS periodo, DATE_FORMAT(v.fecha,'%d/%m') AS label,
                           COALESCE(SUM(v.total),0) AS total, COUNT(*) AS cant
                    FROM ventas v
                    WHERE v.vendedor_id = ? AND v.estado != 'anulado'
                      AND DATE(v.fecha) BETWEEN ? AND ?
                    GROUP BY periodo ORDER BY periodo
                ");
                $stmt->execute([$id, $desde, $hasta]);
                $series = $stmt->fetchAll();
                foreach ($series as &$s) { $s['total'] = (float)$s['total']; $s['cant'] = (int)$s['cant']; $s['periodo'] = (int)$s['periodo']; }
                unset($s);
                break;
        }

        // KPIs + comisión del período
        $kpi = $db->prepare("
            SELECT COUNT(DISTINCT v.id) AS cant, COALESCE(SUM(v.total),0) AS total,
                   COALESCE(SUM(CASE
                     WHEN p.comisionable = 1 AND p.comision_tipo = 'porcentaje'
                       THEN vi.cantidad * vi.precio_unitario * p.comision_valor / 100
                     WHEN p.comisionable = 1 AND p.comision_tipo = 'fijo'
                       THEN vi.cantidad * p.comision_valor
                     ELSE 0 END), 0) AS comision
            FROM ventas v
            LEFT JOIN venta_items vi ON vi.venta_id = v.id
            LEFT JOIN productos p ON p.id = vi.producto_id
            WHERE v.vendedor_id = ? AND v.estado != 'anulado' AND DATE(v.fecha) BETWEEN ? AND ?
        ");
        $kpi->execute([$id, $desde, $hasta]);
        $k = $kpi->fetch();

        json(200, [
            'vendedor'     => ['id' => (int)$vendedor['id'], 'nombre' => $vendedor['nombre'],
                               'meta_monto' => $vendedor['meta_monto'] !== null ? (float)$vendedor['meta_monto'] : null,
                               'meta_desde' => $vendedor['meta_desde'], 'meta_hasta' => $vendedor['meta_hasta']],
            'kpi'          => ['total' => (float)$k['total'], 'cant' => (int)$k['cant'],
                               'ticket_promedio' => $k['cant'] > 0 ? round((float)$k['total'] / (int)$k['cant'], 2) : 0,
                               'comision' => (float)$k['comision']],
            'granularidad' => $granularidad,
            'desde'        => $desde,
            'hasta'        => $hasta,
            'series'       => $series,
        ]);
    }

    /** GET /vendedores/comisiones?periodo=YYYY-MM */
    public function reporteComisiones(): void {
        Auth::requirePermiso('gestionar_vendedores');
        $periodo = $_GET['periodo'] ?? date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $periodo)) json(400, ['error' => 'periodo inválido (YYYY-MM)']);

        $db = DB::get();
        $cerradoStmt = $db->prepare("SELECT cerrado_en FROM reportes_comisiones WHERE periodo = ? LIMIT 1");
        $cerradoStmt->execute([$periodo]);
        $cerradoRec = $cerradoStmt->fetch();

        if ($cerradoRec) {
            $stmt = $db->prepare("
                SELECT vendedor_id, vendedor_nombre, total_ventas, total_comision, cant_ventas
                FROM reportes_comisiones WHERE periodo = ? ORDER BY total_ventas DESC
            ");
            $stmt->execute([$periodo]);
            $filas = $stmt->fetchAll();
            foreach ($filas as &$f) {
                $f['vendedor_id']    = (int)$f['vendedor_id'];
                $f['total_ventas']   = (float)$f['total_ventas'];
                $f['total_comision'] = (float)$f['total_comision'];
                $f['cant_ventas']    = (int)$f['cant_ventas'];
            }
            unset($f);
            json(200, ['periodo' => $periodo, 'cerrado' => true, 'cerrado_en' => $cerradoRec['cerrado_en'], 'filas' => $filas]);
            return;
        }

        $desde = $periodo . '-01';
        $hasta = date('Y-m-t', strtotime($desde));
        $filas = $this->calcularComisionesPeriodo($desde, $hasta);
        json(200, ['periodo' => $periodo, 'cerrado' => false, 'cerrado_en' => null, 'filas' => $filas]);
    }

    /** POST /vendedores/comisiones/cerrar  body: {periodo: "YYYY-MM"} */
    public function cerrarMes(): void {
        Auth::requirePermiso('gestionar_vendedores');
        $body    = json_decode(file_get_contents('php://input'), true) ?: [];
        $periodo = trim($body['periodo'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $periodo)) json(400, ['error' => 'periodo inválido (YYYY-MM)']);

        $desde       = $periodo . '-01';
        $hasta       = date('Y-m-t', strtotime($desde));
        $filas       = $this->calcularComisionesPeriodo($desde, $hasta);
        $usuario     = Auth::usuarioActual();
        $cerrado_por = $usuario ? (int)$usuario['id'] : null;

        $db = DB::get();
        $db->beginTransaction();
        try {
            foreach ($filas as $f) {
                $db->prepare("
                    INSERT INTO reportes_comisiones (periodo, vendedor_id, vendedor_nombre, total_ventas, total_comision, cant_ventas, cerrado_por)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        vendedor_nombre = VALUES(vendedor_nombre),
                        total_ventas    = VALUES(total_ventas),
                        total_comision  = VALUES(total_comision),
                        cant_ventas     = VALUES(cant_ventas),
                        cerrado_en      = NOW(),
                        cerrado_por     = VALUES(cerrado_por)
                ")->execute([$periodo, $f['vendedor_id'], $f['vendedor_nombre'], $f['total_ventas'], $f['total_comision'], $f['cant_ventas'], $cerrado_por]);
            }
            $db->commit();
            json(200, ['ok' => true, 'periodo' => $periodo, 'filas' => count($filas)]);
        } catch (Throwable $e) {
            $db->rollBack();
            json(500, ['error' => $e->getMessage()]);
        }
    }

    /** GET /vendedores/comisiones/exportar?periodo=YYYY-MM */
    public function exportarCSV(): void {
        Auth::requirePermiso('gestionar_vendedores');
        $periodo = $_GET['periodo'] ?? date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $periodo)) json(400, ['error' => 'periodo inválido (YYYY-MM)']);

        $db = DB::get();
        $cerradoStmt = $db->prepare("SELECT id FROM reportes_comisiones WHERE periodo = ? LIMIT 1");
        $cerradoStmt->execute([$periodo]);

        if ($cerradoStmt->fetch()) {
            $stmt = $db->prepare("
                SELECT vendedor_nombre, total_ventas, total_comision, cant_ventas
                FROM reportes_comisiones WHERE periodo = ? ORDER BY total_ventas DESC
            ");
            $stmt->execute([$periodo]);
            $filas = $stmt->fetchAll();
        } else {
            $desde = $periodo . '-01';
            $hasta = date('Y-m-t', strtotime($desde));
            $raw   = $this->calcularComisionesPeriodo($desde, $hasta);
            $filas = array_map(fn($r) => [
                'vendedor_nombre' => $r['vendedor_nombre'],
                'total_ventas'    => $r['total_ventas'],
                'total_comision'  => $r['total_comision'],
                'cant_ventas'     => $r['cant_ventas'],
            ], $raw);
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="comisiones_' . $periodo . '.csv"');
        header('Cache-Control: no-cache');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM para Excel
        fputcsv($out, ['Vendedor', 'Total vendido ($)', 'Comisión ($)', 'Cant. ventas'], ';');
        foreach ($filas as $f) {
            fputcsv($out, [
                $f['vendedor_nombre'],
                number_format((float)$f['total_ventas'],   2, ',', '.'),
                number_format((float)$f['total_comision'], 2, ',', '.'),
                $f['cant_ventas'],
            ], ';');
        }
        fclose($out);
        exit;
    }

    private function calcularComisionesPeriodo(string $desde, string $hasta): array {
        $cond = "v.estado != 'anulado' AND DATE(v.fecha) BETWEEN ? AND ?";
        $stmt = DB::get()->prepare("
            SELECT vd.id AS vendedor_id, vd.nombre AS vendedor_nombre,
                   COALESCE(vr.cant_ventas, 0) AS cant_ventas,
                   COALESCE(vr.total, 0) AS total_ventas,
                   COALESCE(vc.monto_comisionado, 0) AS total_comision
            FROM vendedores vd
            LEFT JOIN (
                SELECT v.vendedor_id, COUNT(v.id) AS cant_ventas, SUM(v.total) AS total
                FROM ventas v WHERE $cond GROUP BY v.vendedor_id
            ) vr ON vr.vendedor_id = vd.id
            LEFT JOIN (
                SELECT v.vendedor_id,
                       SUM(CASE
                         WHEN p.comisionable = 1 AND p.comision_tipo = 'porcentaje'
                           THEN vi.cantidad * vi.precio_unitario * p.comision_valor / 100
                         WHEN p.comisionable = 1 AND p.comision_tipo = 'fijo'
                           THEN vi.cantidad * p.comision_valor
                         ELSE 0 END) AS monto_comisionado
                FROM ventas v
                JOIN venta_items vi ON vi.venta_id = v.id
                JOIN productos p ON p.id = vi.producto_id
                WHERE $cond GROUP BY v.vendedor_id
            ) vc ON vc.vendedor_id = vd.id
            WHERE vd.activo = 1
            ORDER BY total_ventas DESC
        ");
        $stmt->execute([$desde, $hasta, $desde, $hasta]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['vendedor_id']    = (int)$r['vendedor_id'];
            $r['total_ventas']   = (float)$r['total_ventas'];
            $r['total_comision'] = (float)$r['total_comision'];
            $r['cant_ventas']    = (int)$r['cant_ventas'];
        }
        unset($r);
        return $rows;
    }

    private function fecha(?string $v): ?string {
        if ($v === null || $v === '') return null;
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
