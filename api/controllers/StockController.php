<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Auth.php';
require_once __DIR__ . '/../helpers/LogAcciones.php';

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
        $deposito_id = isset($_GET['deposito_id']) && is_numeric($_GET['deposito_id'])
                       ? (int)$_GET['deposito_id'] : null;
        $limit = min((int)($_GET['limit'] ?? 100), 500);

        $where  = ['1=1'];
        $params = [];

        if ($producto_id) {
            $where[]  = 'm.producto_id = ?';
            $params[] = $producto_id;
        }
        if ($deposito_id) {
            $where[]  = 'm.deposito_id = ?';
            $params[] = $deposito_id;
        }

        $params[] = $limit;

        $stmt = DB::get()->prepare("
            SELECT m.id, m.tipo, m.cantidad, m.fecha, m.referencia_id,
                   m.deposito_id, d.nombre AS deposito_nombre,
                   p.nombre AS producto_nombre, p.codigo AS producto_codigo
            FROM movimientos_stock m
            LEFT JOIN productos p  ON p.id = m.producto_id
            LEFT JOIN depositos  d ON d.id = m.deposito_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY m.fecha DESC, m.id DESC
            LIMIT ?
        ");
        $stmt->execute($params);
        json(200, $stmt->fetchAll());
    }

    public function alertas(): void {
        $db = DB::get();

        $items = $db->query("
            SELECT id, codigo, nombre, proveedor, categoria,
                   stock_actual, stock_minimo,
                   (stock_minimo - stock_actual) AS faltante,
                   costo_actual, precio_venta
            FROM productos
            WHERE activo = 1 AND stock_minimo > 0 AND stock_actual <= stock_minimo
            ORDER BY proveedor, nombre
        ")->fetchAll();

        foreach ($items as &$p) {
            $p['stock_actual']  = (float)$p['stock_actual'];
            $p['stock_minimo']  = (float)$p['stock_minimo'];
            $p['faltante']      = (float)$p['faltante'];
            $p['costo_actual']  = (float)$p['costo_actual'];
            $p['precio_venta']  = (float)$p['precio_venta'];
        }
        unset($p);

        // Agrupar por proveedor para facilitar el pedido
        $por_proveedor = [];
        foreach ($items as $item) {
            $prov = $item['proveedor'] ?: '(Sin proveedor)';
            if (!isset($por_proveedor[$prov])) {
                $por_proveedor[$prov] = ['proveedor' => $prov, 'items' => []];
            }
            $por_proveedor[$prov]['items'][] = $item;
        }

        json(200, [
            'count'         => count($items),
            'items'         => $items,
            'por_proveedor' => array_values($por_proveedor),
        ]);
    }

    public function ajustar(): void {
        $body = json_decode(file_get_contents('php://input'), true);

        $producto_id = (int)($body['producto_id'] ?? 0);
        $tipo        = $body['tipo']     ?? '';
        $cantidad    = (float)($body['cantidad'] ?? 0);
        $deposito_id = isset($body['deposito_id']) && is_numeric($body['deposito_id']) ? (int)$body['deposito_id'] : 1;

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
            $mov_cantidad = $cantidad - $stock_anterior;
        } elseif ($tipo === 'entrada') {
            $mov_cantidad = $cantidad;
        } else {
            $mov_cantidad = -$cantidad;
        }

        $db->beginTransaction();
        try {
            // Actualizar stock total del producto
            $db->prepare("
                UPDATE productos
                SET stock_actual = CASE
                    WHEN ? = 'ajuste'  THEN ?
                    WHEN ? = 'entrada' THEN stock_actual + ?
                    ELSE                    stock_actual - ?
                END
                WHERE id = ?
            ")->execute([$tipo, $cantidad, $tipo, $cantidad, $cantidad, $producto_id]);

            // Actualizar stock por depósito
            if ($tipo === 'ajuste') {
                $db->prepare("
                    INSERT INTO stock_depositos (producto_id, deposito_id, stock_actual)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE stock_actual = VALUES(stock_actual)
                ")->execute([$producto_id, $deposito_id, $cantidad]);
            } else {
                $delta = $tipo === 'entrada' ? $cantidad : -$cantidad;
                $db->prepare("
                    INSERT INTO stock_depositos (producto_id, deposito_id, stock_actual)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE stock_actual = stock_actual + VALUES(stock_actual)
                ")->execute([$producto_id, $deposito_id, $delta]);
            }

            $db->prepare("INSERT INTO movimientos_stock (producto_id, deposito_id, tipo, cantidad, fecha) VALUES (?, ?, ?, ?, NOW())")
               ->execute([$producto_id, $deposito_id, $tipo, $mov_cantidad]);

            $db->commit();

            $stmt = $db->prepare("SELECT stock_actual FROM productos WHERE id = ?");
            $stmt->execute([$producto_id]);
            $nuevo_stock = (float)$stmt->fetchColumn();

            LogAcciones::registrar('ajuste_stock', 'producto', $producto_id, ['nombre' => $producto['nombre'], 'tipo' => $tipo, 'delta' => $mov_cantidad, 'stock_anterior' => $stock_anterior, 'stock_actual' => $nuevo_stock]);

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
