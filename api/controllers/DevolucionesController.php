<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Auth.php';
require_once __DIR__ . '/../helpers/LogAcciones.php';

class DevolucionesController {

    /** GET /devoluciones?venta_id=X */
    public function listarPorVenta(): void {
        $venta_id = (int)($_GET['venta_id'] ?? 0);
        if ($venta_id <= 0) json(400, ['error' => 'venta_id requerido']);

        $db = DB::get();
        $rows = $db->prepare("
            SELECT d.id, d.venta_id, d.cliente_id, d.motivo, d.monto_total, d.creado_en,
                   u.nombre AS usuario_nombre
            FROM devoluciones d
            LEFT JOIN usuarios u ON u.id = d.usuario_id
            WHERE d.venta_id = ?
            ORDER BY d.creado_en DESC
        ");
        $rows->execute([$venta_id]);
        $devoluciones = $rows->fetchAll();

        foreach ($devoluciones as &$d) {
            $d['id']          = (int)$d['id'];
            $d['venta_id']    = (int)$d['venta_id'];
            $d['cliente_id']  = (int)$d['cliente_id'];
            $d['monto_total'] = (float)$d['monto_total'];

            $items = $db->prepare("
                SELECT producto_id, nombre, cantidad, precio_unitario
                FROM devolucion_items WHERE devolucion_id = ?
            ");
            $items->execute([$d['id']]);
            $d['items'] = array_map(fn($r) => [
                'producto_id'     => (int)$r['producto_id'],
                'nombre'          => $r['nombre'],
                'cantidad'        => (float)$r['cantidad'],
                'precio_unitario' => (float)$r['precio_unitario'],
            ], $items->fetchAll());
        }
        unset($d);

        json(200, $devoluciones);
    }

    /**
     * POST /devoluciones
     * Body: { venta_id, items: [{producto_id, cantidad, precio_unitario}], motivo? }
     *
     * Efectos:
     *  1. Registra devolucion + devolucion_items
     *  2. Restaura stock (productos.stock_actual, stock_depositos, movimientos_stock)
     *  3. Acredita en CC del cliente: inserta pago en cuenta_corriente_movimientos
     *     y reduce clientes.saldo_cuenta_corriente
     */
    public function crear(): void {
        $body     = json_decode(file_get_contents('php://input'), true) ?? [];
        $venta_id = (int)($body['venta_id'] ?? 0);
        $motivo   = trim($body['motivo'] ?? '') ?: null;
        $items    = $body['items'] ?? [];

        if ($venta_id <= 0) json(400, ['error' => 'venta_id requerido']);
        if (empty($items))  json(400, ['error' => 'items requeridos']);

        $db = DB::get();

        // Cargar venta y validar que tenga cliente
        $venta = $db->prepare("
            SELECT v.id, v.cliente_id, v.estado, v.tipo_comprobante,
                   (SELECT deposito_id FROM movimientos_stock
                    WHERE referencia_id = v.id AND tipo = 'venta' LIMIT 1) AS deposito_id
            FROM ventas v WHERE v.id = ?
        ");
        $venta->execute([$venta_id]);
        $venta = $venta->fetch();
        if (!$venta) json(404, ['error' => 'Venta no encontrada']);
        if ($venta['estado'] === 'anulada') json(422, ['error' => 'No se puede devolver una venta anulada']);
        if (!$venta['cliente_id']) json(422, ['error' => 'La venta no tiene cliente asignado — las devoluciones con crédito requieren un cliente']);

        $cliente_id = (int)$venta['cliente_id'];
        $deposito_id = (int)($venta['deposito_id'] ?? 1);

        // Validar ítems contra los ítems de la venta original
        $stmtOrig = $db->prepare("
            SELECT vi.producto_id, vi.cantidad, vi.precio_unitario, p.nombre, p.activo
            FROM venta_items vi
            LEFT JOIN productos p ON p.id = vi.producto_id
            WHERE vi.venta_id = ?
        ");
        $stmtOrig->execute([$venta_id]);
        $itemsOrig = [];
        foreach ($stmtOrig->fetchAll() as $r) {
            $itemsOrig[(int)$r['producto_id']] = $r;
        }

        // Cuánto ya se devolvió de esta venta (para evitar devolver más de lo vendido)
        $stmtDevPrev = $db->prepare("
            SELECT di.producto_id, SUM(di.cantidad) AS cant_devuelta
            FROM devolucion_items di
            JOIN devoluciones d ON d.id = di.devolucion_id
            WHERE d.venta_id = ?
            GROUP BY di.producto_id
        ");
        $stmtDevPrev->execute([$venta_id]);
        $yaDevuelto = [];
        foreach ($stmtDevPrev->fetchAll() as $r) {
            $yaDevuelto[(int)$r['producto_id']] = (float)$r['cant_devuelta'];
        }

        $itemsValidados = [];
        $monto_total    = 0.0;

        foreach ($items as $i => $item) {
            $prod_id = (int)($item['producto_id'] ?? 0);
            $cant    = (float)($item['cantidad']    ?? 0);
            $precio  = isset($item['precio_unitario']) ? (float)$item['precio_unitario'] : null;

            if ($prod_id <= 0)  json(400, ['error' => "Ítem $i: producto_id inválido"]);
            if ($cant <= 0)     json(400, ['error' => "Ítem $i: cantidad debe ser mayor a 0"]);

            if (!isset($itemsOrig[$prod_id])) {
                json(422, ['error' => "El producto #$prod_id no pertenece a esta venta"]);
            }

            $orig       = $itemsOrig[$prod_id];
            $disponibled = (float)$orig['cantidad'] - ($yaDevuelto[$prod_id] ?? 0.0);
            if ($cant > $disponibled + 0.001) {
                json(422, ['error' => "El producto #$prod_id: solo quedan {$disponibled} unidades disponibles para devolver"]);
            }

            $precio_final = $precio ?? (float)$orig['precio_unitario'];
            $monto_total += $cant * $precio_final;

            $itemsValidados[] = [
                'producto_id'     => $prod_id,
                'nombre'          => $orig['nombre'] ?? "Producto #$prod_id",
                'cantidad'        => $cant,
                'precio_unitario' => $precio_final,
            ];
        }

        $monto_total = round($monto_total, 2);
        $usuario_id  = Auth::usuarioActual()['id'] ?? null;

        $db->beginTransaction();
        try {
            // 1. Registrar devolución
            $db->prepare("
                INSERT INTO devoluciones (venta_id, cliente_id, motivo, monto_total, usuario_id)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$venta_id, $cliente_id, $motivo, $monto_total, $usuario_id]);
            $dev_id = (int)$db->lastInsertId();

            $insItem = $db->prepare("
                INSERT INTO devolucion_items (devolucion_id, producto_id, nombre, cantidad, precio_unitario)
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($itemsValidados as $it) {
                $insItem->execute([$dev_id, $it['producto_id'], $it['nombre'], $it['cantidad'], $it['precio_unitario']]);

                // 2. Restaurar stock
                $db->prepare("UPDATE productos SET stock_actual = stock_actual + ? WHERE id = ?")
                   ->execute([$it['cantidad'], $it['producto_id']]);

                $db->prepare("
                    INSERT INTO stock_depositos (producto_id, deposito_id, stock_actual)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE stock_actual = stock_actual + VALUES(stock_actual)
                ")->execute([$it['producto_id'], $deposito_id, $it['cantidad']]);

                $db->prepare("
                    INSERT INTO movimientos_stock (producto_id, deposito_id, tipo, cantidad, referencia_id, fecha)
                    VALUES (?, ?, 'devolucion', ?, ?, NOW())
                ")->execute([$it['producto_id'], $deposito_id, $it['cantidad'], $dev_id]);
            }

            // 3. Acreditar en CC del cliente
            $obsItems    = implode(', ', array_map(
                fn($it) => $it['nombre'] . ' ×' . rtrim(rtrim(number_format((float)$it['cantidad'], 3), '0'), '.'),
                $itemsValidados
            ));
            $obsNumVenta  = str_pad((string)$venta_id, 5, '0', STR_PAD_LEFT);
            $observaciones = "Devolución Venta N°{$obsNumVenta} — {$obsItems}";

            $db->prepare("
                INSERT INTO cuenta_corriente_movimientos
                    (entidad_tipo, entidad_id, tipo, monto, referencia_id, observaciones, fecha)
                VALUES ('cliente', ?, 'pago', ?, ?, ?, CURDATE())
            ")->execute([$cliente_id, $monto_total, $dev_id, $observaciones]);

            $db->prepare("UPDATE clientes SET saldo_cuenta_corriente = saldo_cuenta_corriente - ? WHERE id = ?")
               ->execute([$monto_total, $cliente_id]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            json(500, ['error' => $e->getMessage()]);
        }

        LogAcciones::registrar('devolucion', 'devolucion', $dev_id, [
            'venta_id'    => $venta_id,
            'cliente_id'  => $cliente_id,
            'monto_total' => $monto_total,
            'items'       => count($itemsValidados),
        ]);

        json(201, [
            'id'          => $dev_id,
            'monto_total' => $monto_total,
            'items'       => count($itemsValidados),
        ]);
    }

    /** GET /devoluciones/{id} */
    public function listarUno(int $id): void {
        $db  = DB::get();
        $row = $db->prepare("
            SELECT d.id, d.venta_id, d.cliente_id, d.motivo, d.monto_total, d.creado_en,
                   u.nombre  AS usuario_nombre,
                   v.tipo_comprobante
            FROM devoluciones d
            LEFT JOIN usuarios u ON u.id  = d.usuario_id
            LEFT JOIN ventas   v ON v.id  = d.venta_id
            WHERE d.id = ?
        ");
        $row->execute([$id]);
        $dev = $row->fetch();
        if (!$dev) json(404, ['error' => 'Devolución no encontrada']);

        $dev['id']          = (int)$dev['id'];
        $dev['venta_id']    = (int)$dev['venta_id'];
        $dev['cliente_id']  = (int)$dev['cliente_id'];
        $dev['monto_total'] = (float)$dev['monto_total'];

        $items = $db->prepare("
            SELECT producto_id, nombre, cantidad, precio_unitario
            FROM devolucion_items WHERE devolucion_id = ?
        ");
        $items->execute([$id]);
        $dev['items'] = array_map(fn($r) => [
            'producto_id'     => (int)$r['producto_id'],
            'nombre'          => $r['nombre'],
            'cantidad'        => (float)$r['cantidad'],
            'precio_unitario' => (float)$r['precio_unitario'],
        ], $items->fetchAll());

        json(200, $dev);
    }
}
