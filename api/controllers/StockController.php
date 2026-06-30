<?php

require_once __DIR__ . '/../config/db.php';

class StockController {

    public function listarProductos(): void {
        $q            = trim($_GET['q']            ?? '');
        $marca        = trim($_GET['marca']        ?? '');
        $categoria    = trim($_GET['categoria']    ?? '');
        $proveedor    = trim($_GET['proveedor']    ?? '');
        $stock_filter = trim($_GET['stock_filter'] ?? '');
        $page         = max(1,   (int)($_GET['page']     ?? 1));
        $per_page     = min(200, max(20, (int)($_GET['per_page'] ?? 100)));
        $offset       = ($page - 1) * $per_page;

        $where  = ['activo = 1'];
        $params = [];

        if ($q !== '') {
            $like     = '%' . $q . '%';
            $where[]  = '(nombre LIKE ? OR codigo LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        if ($marca !== '') {
            $where[]  = 'marca = ?';
            $params[] = $marca;
        }
        if ($categoria !== '') {
            $where[]  = 'categoria = ?';
            $params[] = $categoria;
        }
        if ($proveedor !== '') {
            $where[]  = 'proveedor = ?';
            $params[] = $proveedor;
        }
        if ($stock_filter === 'con_stock') {
            $where[] = 'stock_actual > 0';
        } elseif ($stock_filter === 'sin_stock') {
            $where[] = 'stock_actual <= 0';
        } elseif ($stock_filter === 'stock_bajo') {
            $where[] = 'stock_actual > 0 AND stock_minimo > 0 AND stock_actual <= stock_minimo';
        }

        $whereStr = implode(' AND ', $where);
        $db = DB::get();

        // Total count
        $stmtC = $db->prepare("SELECT COUNT(*) FROM productos WHERE $whereStr");
        $stmtC->execute($params);
        $total = (int)$stmtC->fetchColumn();

        // Orden
        if ($q !== '') {
            $orderParams = [$q, $q . '%'];
            $order = 'CASE WHEN codigo = ? THEN 0 WHEN nombre LIKE ? THEN 1 ELSE 2 END, nombre';
        } else {
            $orderParams = [];
            $order = 'nombre';
        }

        $stmt = $db->prepare("
            SELECT id, codigo, nombre, marca, categoria, proveedor,
                   precio_venta, costo_actual, stock_actual, stock_minimo, iva_porcentaje
            FROM productos
            WHERE $whereStr
            ORDER BY $order
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, $orderParams, [$per_page, $offset]));
        $items = $stmt->fetchAll();

        foreach ($items as &$p) {
            $p['stock_actual']   = (float)$p['stock_actual'];
            $p['stock_minimo']   = (float)$p['stock_minimo'];
            $p['precio_venta']   = (float)$p['precio_venta'];
            $p['costo_actual']   = (float)$p['costo_actual'];
            $p['iva_porcentaje'] = (float)$p['iva_porcentaje'];
        }
        unset($p);

        json(200, [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => (int)ceil($total / max(1, $per_page)),
            'items'    => $items,
        ]);
    }

    public function historial(): void {
        $producto_id = isset($_GET['producto_id']) && is_numeric($_GET['producto_id'])
                       ? (int)$_GET['producto_id'] : null;
        $limit = min((int)($_GET['limit'] ?? 100), 500);

        $where  = ['1=1'];
        $params = [];

        if ($producto_id) {
            $where[]  = 'm.producto_id = ?';
            $params[] = $producto_id;
        }

        $params[] = $limit;

        $stmt = DB::get()->prepare("
            SELECT m.id, m.tipo, m.cantidad, m.fecha, m.referencia_id,
                   p.nombre AS producto_nombre, p.codigo AS producto_codigo
            FROM movimientos_stock m
            LEFT JOIN productos p ON p.id = m.producto_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY m.fecha DESC, m.id DESC
            LIMIT ?
        ");
        $stmt->execute($params);
        json(200, $stmt->fetchAll());
    }

    public function ajustar(): void {
        $body = json_decode(file_get_contents('php://input'), true);

        $producto_id = (int)($body['producto_id'] ?? 0);
        $tipo        = $body['tipo']     ?? '';
        $cantidad    = (float)($body['cantidad'] ?? 0);

        if (!$producto_id || !in_array($tipo, ['entrada', 'salida', 'ajuste'], true) || $cantidad < 0) {
            json(400, ['error' => 'producto_id, tipo (entrada|salida|ajuste) y cantidad son requeridos']);
        }
        if ($tipo !== 'ajuste' && $cantidad <= 0) {
            json(400, ['error' => 'cantidad debe ser mayor a 0 para entrada o salida']);
        }

        $db = DB::get();
        $stmt = $db->prepare("SELECT id, nombre, stock_actual FROM productos WHERE id = ?");
        $stmt->execute([$producto_id]);
        $producto = $stmt->fetch();
        if (!$producto) json(404, ['error' => 'Producto no encontrado']);

        $stock_anterior = (float)$producto['stock_actual'];

        if ($tipo === 'ajuste') {
            $mov_cantidad    = $cantidad - $stock_anterior;
            $nuevo_stock_sql = '?';
            $update_params   = [$cantidad, $producto_id];
        } elseif ($tipo === 'entrada') {
            $mov_cantidad    = $cantidad;
            $nuevo_stock_sql = 'stock_actual + ?';
            $update_params   = [$cantidad, $producto_id];
        } else {
            $mov_cantidad    = -$cantidad;
            $nuevo_stock_sql = 'stock_actual - ?';
            $update_params   = [$cantidad, $producto_id];
        }

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE productos SET stock_actual = $nuevo_stock_sql WHERE id = ?")
               ->execute($update_params);

            $db->prepare("INSERT INTO movimientos_stock (producto_id, tipo, cantidad, fecha) VALUES (?, ?, ?, NOW())")
               ->execute([$producto_id, $tipo, $mov_cantidad]);

            $db->commit();

            $stmt = $db->prepare("SELECT stock_actual FROM productos WHERE id = ?");
            $stmt->execute([$producto_id]);
            $nuevo_stock = (float)$stmt->fetchColumn();

            json(200, [
                'ok'             => true,
                'stock_anterior' => $stock_anterior,
                'stock_actual'   => $nuevo_stock,
                'delta'          => $mov_cantidad,
            ]);
        } catch (Throwable $e) {
            $db->rollBack();
            json(500, ['error' => $e->getMessage()]);
        }
    }
}
