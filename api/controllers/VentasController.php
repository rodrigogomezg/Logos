<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Auth.php';
require_once __DIR__ . '/../helpers/Configuracion.php';

class VentasController {

    private const TIPOS_PAGO_SIMPLES = ['efectivo', 'transferencia', 'cc', 'tarjeta', 'cheque'];

    // Valida el array de pagos de un pago mixto: cada línea con tipo simple + monto > 0,
    // y que la suma coincida con el total de la venta.
    private function validarPagosMixto(array $pagosRaw, float $total): array {
        if (count($pagosRaw) < 2) {
            json(400, ['error' => 'Un pago mixto necesita al menos 2 formas de pago']);
        }
        $pagos = [];
        $suma  = 0.0;
        foreach ($pagosRaw as $i => $p) {
            $tipo  = $p['tipo']  ?? null;
            $monto = isset($p['monto']) && is_numeric($p['monto']) ? (float)$p['monto'] : null;
            if (!in_array($tipo, self::TIPOS_PAGO_SIMPLES, true) || $monto === null || $monto <= 0) {
                json(400, ['error' => 'Pago mixto inválido en la línea ' . ($i + 1)]);
            }
            $pagos[] = ['tipo' => $tipo, 'monto' => $monto];
            $suma   += $monto;
        }
        if (abs($suma - $total) > 0.01) {
            json(400, ['error' => 'La suma de los pagos ($' . number_format($suma, 2) . ') no coincide con el total ($' . number_format($total, 2) . ')']);
        }
        return $pagos;
    }

    // Porción del total que corresponde a cuenta corriente (todo, nada, o solo la línea 'cc' de un mixto).
    private function montoCC(string $tipo_pago, float $total, array $pagos): float {
        if ($tipo_pago === 'cc') return $total;
        if ($tipo_pago === 'mixto') {
            return array_sum(array_map(fn($p) => $p['tipo'] === 'cc' ? (float)$p['monto'] : 0.0, $pagos));
        }
        return 0.0;
    }

    public function listar(): void {
        $where  = ['1=1'];
        $params = [];

        $fecha_desde      = $_GET['fecha_desde']      ?? '';
        $fecha_hasta      = $_GET['fecha_hasta']       ?? '';
        $tipo_comprobante = trim($_GET['tipo']         ?? '');
        $cliente_id       = isset($_GET['cliente_id']) && is_numeric($_GET['cliente_id'])
                            ? (int)$_GET['cliente_id'] : null;
        $caja_id          = isset($_GET['caja_id']) && is_numeric($_GET['caja_id']) ? (int)$_GET['caja_id'] : null;
        $q                = trim($_GET['q']            ?? '');
        $monto_min        = isset($_GET['monto_min']) && is_numeric($_GET['monto_min']) ? (float)$_GET['monto_min'] : null;
        $monto_max        = isset($_GET['monto_max']) && is_numeric($_GET['monto_max']) ? (float)$_GET['monto_max'] : null;
        $limit            = min((int)($_GET['limit']  ?? 50), 500);
        $offset           = max((int)($_GET['offset'] ?? 0),  0);

        if ($fecha_desde)      { $where[] = 'v.fecha >= ?';              $params[] = $fecha_desde;      }
        if ($fecha_hasta)      { $where[] = 'v.fecha <= ?';              $params[] = $fecha_hasta;      }
        if ($tipo_comprobante) { $where[] = 'v.tipo_comprobante = ?';    $params[] = $tipo_comprobante; }
        if ($cliente_id)       { $where[] = 'v.cliente_id = ?';          $params[] = $cliente_id;       }
        if ($caja_id)          { $where[] = 'v.caja_id = ?';             $params[] = $caja_id;          }
        if ($monto_min !== null){ $where[] = 'v.total >= ?';             $params[] = $monto_min;        }
        if ($monto_max !== null){ $where[] = 'v.total <= ?';             $params[] = $monto_max;        }
        if ($q !== '') {
            $like    = '%' . $q . '%';
            $where[] = '(c.nombre LIKE ? OR LPAD(v.id,8,"0") LIKE ? OR CAST(FLOOR(v.total) AS CHAR) LIKE ?)';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $params[] = $limit;
        $params[] = $offset;

        $stmt = DB::get()->prepare("
            SELECT v.id, LPAD(v.id, 8, '0') AS numero,
                   v.fecha, v.tipo_comprobante, v.tipo_pago, v.total, v.estado, v.cae,
                   v.observaciones, v.origen_descripcion, v.envio_precio, v.envio_direccion,
                   v.cliente_id, c.nombre AS cliente_nombre,
                   v.caja_id, cj.nombre AS caja_nombre
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN cajas cj ON cj.id = v.caja_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY v.fecha DESC, v.id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $ids_mixtos = array_column(array_filter($rows, fn($r) => $r['tipo_pago'] === 'mixto'), 'id');
        $pagosPorVenta = [];
        if ($ids_mixtos) {
            $in   = implode(',', array_fill(0, count($ids_mixtos), '?'));
            $stmt = DB::get()->prepare("SELECT venta_id, tipo_pago AS tipo, monto FROM venta_pagos WHERE venta_id IN ($in) ORDER BY id");
            $stmt->execute($ids_mixtos);
            foreach ($stmt->fetchAll() as $p) {
                $pagosPorVenta[$p['venta_id']][] = ['tipo' => $p['tipo'], 'monto' => (float)$p['monto']];
            }
        }

        foreach ($rows as &$r) {
            $r['total']         = (float)$r['total'];
            $r['envio_precio']  = $r['envio_precio'] !== null ? (float)$r['envio_precio'] : null;
            $r['pagos']         = $pagosPorVenta[$r['id']] ?? [];
        }
        json(200, $rows);
    }

    public function crear(): void {
        $body = json_decode(file_get_contents('php://input'), true);

        if (!$body) {
            json(400, ['error' => 'Body JSON inválido']);
        }

        // Validaciones mínimas
        $items = $body['items'] ?? [];
        if (empty($items)) {
            json(400, ['error' => 'La venta debe tener al menos un ítem']);
        }

        $tipo_pago        = $body['tipo_pago']        ?? 'efectivo';
        $tipo_comprobante = $body['tipo_comprobante'] ?? 'REMITO';
        $cliente_id       = isset($body['cliente_id']) ? (int)$body['cliente_id'] : null;
        $caja_id          = isset($body['caja_id'])    && is_numeric($body['caja_id'])    ? (int)$body['caja_id']    : null;
        $usuario_id       = isset($body['usuario_id']) && is_numeric($body['usuario_id']) ? (int)$body['usuario_id'] : null;
        $observaciones    = $body['observaciones']    ?? null;
        $envio_precio     = isset($body['envio_precio']) && is_numeric($body['envio_precio']) && $body['envio_precio'] > 0
                            ? (float)$body['envio_precio'] : null;
        $envio_direccion  = isset($body['envio_direccion']) && trim($body['envio_direccion']) !== ''
                            ? trim($body['envio_direccion']) : null;
        $fecha            = isset($body['fecha']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['fecha'])
                            ? $body['fecha'] : date('Y-m-d');

        $tipos_pago_validos = [...self::TIPOS_PAGO_SIMPLES, 'mixto'];
        if (!in_array($tipo_pago, $tipos_pago_validos, true)) {
            json(400, ['error' => 'tipo_pago inválido. Valores: ' . implode(', ', $tipos_pago_validos)]);
        }

        $db = DB::get();

        // Si la caja es de tipo venta, exigir un turno abierto
        $turno_id = null;
        if ($caja_id !== null) {
            $stmt = $db->prepare("SELECT tipo FROM cajas WHERE id = ?");
            $stmt->execute([$caja_id]);
            $caja = $stmt->fetch();
            if ($caja && $caja['tipo'] === 'venta') {
                $stmt = $db->prepare("SELECT id FROM caja_turnos WHERE caja_id = ? AND estado = 'abierto'");
                $stmt->execute([$caja_id]);
                $turno = $stmt->fetch();
                if (!$turno) {
                    json(409, ['error' => 'No hay un turno de caja abierto. Abrí la caja antes de vender.']);
                }
                $turno_id = (int)$turno['id'];
            }
        }

        // Verificar que el cliente existe si se pasó
        if ($cliente_id !== null) {
            $stmt = $db->prepare("SELECT id, nombre, saldo_cuenta_corriente, limite_credito FROM clientes WHERE id = ?");
            $stmt->execute([$cliente_id]);
            $cliente = $stmt->fetch();
            if (!$cliente) {
                json(404, ['error' => 'Cliente no encontrado']);
            }
        }

        // Cargar y validar cada ítem
        $items_validados = [];
        $total           = 0;

        foreach ($items as $i => $item) {
            $producto_id    = isset($item['producto_id'])    ? (int)$item['producto_id']       : null;
            $cantidad       = isset($item['cantidad'])       ? (float)$item['cantidad']         : null;
            $precio_unitario = isset($item['precio_unitario']) ? (float)$item['precio_unitario'] : null;

            if (!$producto_id || !$cantidad || $cantidad <= 0 || $precio_unitario === null || $precio_unitario < 0) {
                json(400, ['error' => "Ítem $i inválido: producto_id, cantidad > 0 y precio_unitario >= 0 son requeridos"]);
            }

            $stmt = $db->prepare("SELECT id, nombre, costo_actual, stock_actual FROM productos WHERE id = ? AND activo = 1");
            $stmt->execute([$producto_id]);
            $producto = $stmt->fetch();
            if (!$producto) {
                json(404, ['error' => "Producto $producto_id no encontrado o inactivo"]);
            }

            $precio_original = isset($item['precio_original']) && is_numeric($item['precio_original'])
                               ? (float)$item['precio_original'] : null;
            $ajuste_desc     = isset($item['ajuste_desc']) && trim($item['ajuste_desc']) !== ''
                               ? trim($item['ajuste_desc']) : null;
            $ajuste_visible  = isset($item['ajuste_visible']) ? (int)(bool)$item['ajuste_visible'] : 1;

            $items_validados[] = [
                'producto_id'     => $producto_id,
                'cantidad'        => $cantidad,
                'precio_unitario' => $precio_unitario,
                'precio_original' => $precio_original,
                'ajuste_desc'     => $ajuste_desc,
                'ajuste_visible'  => $ajuste_visible,
                'costo_unitario'  => (float)$producto['costo_actual'],
                'nombre'          => $producto['nombre'],
                'stock_actual'    => (float)$producto['stock_actual'],
            ];
            $total += $cantidad * $precio_unitario;
        }

        // Sumar envío al total
        if ($envio_precio !== null) {
            $total += $envio_precio;
        }

        // Pago mixto: validar el desglose contra el total ya calculado
        $pagos_mixto = [];
        if ($tipo_pago === 'mixto') {
            $pagos_mixto = $this->validarPagosMixto($body['pagos'] ?? [], $total);
        }
        $monto_cc = $this->montoCC($tipo_pago, $total, $pagos_mixto);

        // Verificar límite de CC por la porción que efectivamente va a cuenta corriente
        if ($monto_cc > 0 && $cliente_id !== null) {
            $nuevo_saldo = (float)$cliente['saldo_cuenta_corriente'] + $monto_cc;
            if ($cliente['limite_credito'] > 0 && $nuevo_saldo > (float)$cliente['limite_credito']) {
                json(422, [
                    'error'          => 'Límite de crédito insuficiente',
                    'saldo_actual'   => $cliente['saldo_cuenta_corriente'],
                    'limite'         => $cliente['limite_credito'],
                    'monto_venta'    => $monto_cc,
                ]);
            }
        }

        // Todo validado — ejecutar en transacción
        try {
            $db->beginTransaction();

            // 1. Insertar venta
            $stmt = $db->prepare("
                INSERT INTO ventas (fecha, cliente_id, total, tipo_comprobante, tipo_pago, estado, observaciones, envio_precio, envio_direccion, caja_id, usuario_id, turno_id)
                VALUES (?, ?, ?, ?, ?, 'completado', ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$fecha, $cliente_id, $total, $tipo_comprobante, $tipo_pago, $observaciones, $envio_precio, $envio_direccion, $caja_id, $usuario_id, $turno_id]);
            $venta_id = (int)$db->lastInsertId();

            foreach ($items_validados as $item) {
                // 2. Insertar ítems
                $stmt = $db->prepare("
                    INSERT INTO venta_items
                        (venta_id, producto_id, cantidad, precio_unitario, precio_original, ajuste_desc, ajuste_visible, costo_unitario)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $venta_id,
                    $item['producto_id'],
                    $item['cantidad'],
                    $item['precio_unitario'],
                    $item['precio_original'],
                    $item['ajuste_desc'],
                    $item['ajuste_visible'],
                    $item['costo_unitario'],
                ]);

                // 3. Descontar stock
                $stmt = $db->prepare("
                    UPDATE productos SET stock_actual = stock_actual - ? WHERE id = ?
                ");
                $stmt->execute([$item['cantidad'], $item['producto_id']]);

                // 4. Registrar movimiento de stock
                $stmt = $db->prepare("
                    INSERT INTO movimientos_stock (producto_id, tipo, cantidad, referencia_id, fecha)
                    VALUES (?, 'venta', ?, ?, NOW())
                ");
                $stmt->execute([$item['producto_id'], $item['cantidad'], $venta_id]);
            }

            // 5. Pagos mixtos: guardar el desglose
            if ($tipo_pago === 'mixto') {
                foreach ($pagos_mixto as $p) {
                    $db->prepare("INSERT INTO venta_pagos (venta_id, tipo_pago, monto) VALUES (?, ?, ?)")
                       ->execute([$venta_id, $p['tipo'], $p['monto']]);
                }
            }

            // 6. Cuenta corriente si corresponde (total completo, o solo la porción 'cc' de un mixto)
            if ($monto_cc > 0 && $cliente_id !== null) {
                $stmt = $db->prepare("
                    INSERT INTO cuenta_corriente_movimientos
                        (entidad_tipo, entidad_id, tipo, monto, referencia_id, fecha)
                    VALUES ('cliente', ?, 'cargo', ?, ?, CURDATE())
                ");
                $stmt->execute([$cliente_id, $monto_cc, $venta_id]);

                $stmt = $db->prepare("
                    UPDATE clientes SET saldo_cuenta_corriente = saldo_cuenta_corriente + ? WHERE id = ?
                ");
                $stmt->execute([$monto_cc, $cliente_id]);
            }

            $db->commit();

        } catch (Exception $e) {
            $db->rollBack();
            json(500, ['error' => 'Error al guardar la venta: ' . $e->getMessage()]);
        }

        // Devolver la venta creada
        $this->get($venta_id);
    }

    public function actualizar(int $id): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);

        // Reemplazo completo cuando vienen ítems (edición desde el POS)
        if (isset($body['items'])) {
            $this->actualizarCompleto($id, $body);
            return;
        }

        $db = DB::get();

        $stmt = $db->prepare("SELECT id, tipo_pago FROM ventas WHERE id = ?");
        $stmt->execute([$id]);
        $venta = $stmt->fetch();
        if (!$venta) json(404, ['error' => 'Venta no encontrada']);

        // Pago mixto: el desglose solo se edita desde "Editar ítems" (POS), no desde este modal rápido
        if (isset($body['tipo_pago']) && ($body['tipo_pago'] === 'mixto' || $venta['tipo_pago'] === 'mixto')) {
            json(422, ['error' => "No se puede cambiar la forma de pago de un pago mixto desde acá. Usá 'Editar ítems'."]);
        }

        // No permitir cambiar tipo_pago a/desde cc (implicaría mover movimientos CC)
        $nuevo_tipo_pago = $body['tipo_pago'] ?? $venta['tipo_pago'];
        if (($venta['tipo_pago'] === 'cc') !== ($nuevo_tipo_pago === 'cc')) {
            json(422, ['error' => 'No se puede cambiar la forma de pago a/desde Cuenta Corriente. Eliminá y recreá la venta.']);
        }

        $sets   = [];
        $params = [];

        $tipos_comp = ['REMITO', 'FC B-ELECT', 'FC A-ELECT', 'PRESUPUESTO'];
        $tipos_pago = ['efectivo', 'transferencia', 'tarjeta', 'cheque', 'cc'];

        if (isset($body['tipo_comprobante'])) {
            if (!in_array($body['tipo_comprobante'], $tipos_comp, true))
                json(400, ['error' => 'tipo_comprobante inválido']);
            $sets[]   = 'tipo_comprobante = ?';
            $params[] = $body['tipo_comprobante'];
        }
        if (isset($body['tipo_pago'])) {
            if (!in_array($body['tipo_pago'], $tipos_pago, true))
                json(400, ['error' => 'tipo_pago inválido']);
            $sets[]   = 'tipo_pago = ?';
            $params[] = $body['tipo_pago'];
        }
        if (array_key_exists('observaciones', $body)) {
            $sets[]   = 'observaciones = ?';
            $params[] = $body['observaciones'] !== '' ? $body['observaciones'] : null;
        }

        if (empty($sets)) json(400, ['error' => 'Nada que actualizar']);

        $params[] = $id;
        $stmt = $db->prepare("UPDATE ventas SET " . implode(', ', $sets) . " WHERE id = ?");
        $stmt->execute($params);

        $this->get($id);
    }

    private function actualizarCompleto(int $id, array $body): void {
        $db = DB::get();

        $stmt = $db->prepare("SELECT id, cliente_id, tipo_pago, total FROM ventas WHERE id = ?");
        $stmt->execute([$id]);
        $venta = $stmt->fetch();
        if (!$venta) json(404, ['error' => 'Venta no encontrada']);

        $nuevo_tipo_pago    = $body['tipo_pago'] ?? $venta['tipo_pago'];
        $tipos_pago_validos = [...self::TIPOS_PAGO_SIMPLES, 'mixto'];
        if (!in_array($nuevo_tipo_pago, $tipos_pago_validos, true)) {
            json(400, ['error' => 'tipo_pago inválido']);
        }
        $nueva_fecha = isset($body['fecha']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['fecha'])
                       ? $body['fecha'] : null;

        $nuevo_cliente_id = array_key_exists('cliente_id', $body)
            ? (isset($body['cliente_id']) ? (int)$body['cliente_id'] : null)
            : (isset($venta['cliente_id']) ? (int)$venta['cliente_id'] : null);

        // Validar ítems nuevos
        $items_raw = $body['items'];
        if (empty($items_raw)) json(400, ['error' => 'La venta debe tener al menos un ítem']);

        $items_validados = [];
        $nuevo_total     = 0.0;

        foreach ($items_raw as $i => $item) {
            $producto_id     = isset($item['producto_id'])     ? (int)$item['producto_id']       : null;
            $cantidad        = isset($item['cantidad'])        ? (float)$item['cantidad']         : null;
            $precio_unitario = isset($item['precio_unitario']) ? (float)$item['precio_unitario']  : null;

            if (!$producto_id || !$cantidad || $cantidad <= 0 || $precio_unitario === null || $precio_unitario < 0) {
                json(400, ['error' => "Ítem $i inválido"]);
            }

            $stmt = $db->prepare("SELECT id, costo_actual FROM productos WHERE id = ? AND activo = 1");
            $stmt->execute([$producto_id]);
            $producto = $stmt->fetch();
            if (!$producto) json(404, ['error' => "Producto $producto_id no encontrado o inactivo"]);

            $precio_original = isset($item['precio_original']) && is_numeric($item['precio_original'])
                               ? (float)$item['precio_original'] : null;
            $ajuste_desc     = isset($item['ajuste_desc']) && trim((string)$item['ajuste_desc']) !== ''
                               ? trim($item['ajuste_desc']) : null;
            $ajuste_visible  = isset($item['ajuste_visible']) ? (int)(bool)$item['ajuste_visible'] : 1;

            $items_validados[] = [
                'producto_id'     => $producto_id,
                'cantidad'        => $cantidad,
                'precio_unitario' => $precio_unitario,
                'precio_original' => $precio_original,
                'ajuste_desc'     => $ajuste_desc,
                'ajuste_visible'  => $ajuste_visible,
                'costo_unitario'  => (float)$producto['costo_actual'],
            ];
            $nuevo_total += $cantidad * $precio_unitario;
        }

        $nuevo_envio = isset($body['envio_precio']) && is_numeric($body['envio_precio']) && (float)$body['envio_precio'] > 0
                       ? (float)$body['envio_precio'] : null;
        if ($nuevo_envio !== null) $nuevo_total += $nuevo_envio;

        // Pago mixto nuevo (si corresponde) y desglose viejo, para mover correctamente la porción de CC
        $pagos_nuevos = [];
        if ($nuevo_tipo_pago === 'mixto') {
            $pagos_nuevos = $this->validarPagosMixto($body['pagos'] ?? [], $nuevo_total);
        }
        $pagos_viejos = [];
        if ($venta['tipo_pago'] === 'mixto') {
            $stmt = $db->prepare("SELECT tipo_pago AS tipo, monto FROM venta_pagos WHERE venta_id = ?");
            $stmt->execute([$id]);
            $pagos_viejos = array_map(fn($p) => ['tipo' => $p['tipo'], 'monto' => (float)$p['monto']], $stmt->fetchAll());
        }
        $monto_cc_viejo = $this->montoCC($venta['tipo_pago'], (float)$venta['total'], $pagos_viejos);
        $monto_cc_nuevo = $this->montoCC($nuevo_tipo_pago, $nuevo_total, $pagos_nuevos);

        if (($monto_cc_viejo > 0) !== ($monto_cc_nuevo > 0)) {
            json(422, ['error' => 'No se puede cambiar la forma de pago a/desde Cuenta Corriente.']);
        }

        try {
            $db->beginTransaction();

            // Revertir stock de ítems viejos
            $stmt = $db->prepare("SELECT producto_id, cantidad FROM venta_items WHERE venta_id = ?");
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll() as $iv) {
                $db->prepare("UPDATE productos SET stock_actual = stock_actual + ? WHERE id = ?")
                   ->execute([(float)$iv['cantidad'], (int)$iv['producto_id']]);
            }

            // Borrar ítems y movimientos viejos
            $db->prepare("DELETE FROM movimientos_stock WHERE referencia_id = ? AND tipo = 'venta'")->execute([$id]);
            $db->prepare("DELETE FROM venta_items WHERE venta_id = ?")->execute([$id]);

            // Revertir CC vieja (por el monto real viejo: todo si era 'cc', solo la porción 'cc' si era mixto)
            if ($monto_cc_viejo > 0 && $venta['cliente_id']) {
                $db->prepare("DELETE FROM cuenta_corriente_movimientos WHERE referencia_id = ? AND entidad_tipo = 'cliente'")->execute([$id]);
                $db->prepare("UPDATE clientes SET saldo_cuenta_corriente = saldo_cuenta_corriente - ? WHERE id = ?")
                   ->execute([$monto_cc_viejo, (int)$venta['cliente_id']]);
            }

            // Limpiar desglose de pago mixto viejo
            $db->prepare("DELETE FROM venta_pagos WHERE venta_id = ?")->execute([$id]);

            // Insertar nuevos ítems, descontar stock, registrar movimientos
            foreach ($items_validados as $item) {
                $db->prepare("INSERT INTO venta_items (venta_id, producto_id, cantidad, precio_unitario, precio_original, ajuste_desc, ajuste_visible, costo_unitario) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                   ->execute([$id, $item['producto_id'], $item['cantidad'], $item['precio_unitario'], $item['precio_original'], $item['ajuste_desc'], $item['ajuste_visible'], $item['costo_unitario']]);
                $db->prepare("UPDATE productos SET stock_actual = stock_actual - ? WHERE id = ?")
                   ->execute([$item['cantidad'], $item['producto_id']]);
                $db->prepare("INSERT INTO movimientos_stock (producto_id, tipo, cantidad, referencia_id, fecha) VALUES (?, 'venta', ?, ?, NOW())")
                   ->execute([$item['producto_id'], $item['cantidad'], $id]);
            }

            // Nuevo desglose de pago mixto
            if ($nuevo_tipo_pago === 'mixto') {
                foreach ($pagos_nuevos as $p) {
                    $db->prepare("INSERT INTO venta_pagos (venta_id, tipo_pago, monto) VALUES (?, ?, ?)")
                       ->execute([$id, $p['tipo'], $p['monto']]);
                }
            }

            // Nueva CC si corresponde
            if ($monto_cc_nuevo > 0 && $nuevo_cliente_id) {
                $db->prepare("INSERT INTO cuenta_corriente_movimientos (entidad_tipo, entidad_id, tipo, monto, referencia_id, fecha) VALUES ('cliente', ?, 'cargo', ?, ?, CURDATE())")
                   ->execute([$nuevo_cliente_id, $monto_cc_nuevo, $id]);
                $db->prepare("UPDATE clientes SET saldo_cuenta_corriente = saldo_cuenta_corriente + ? WHERE id = ?")
                   ->execute([$monto_cc_nuevo, $nuevo_cliente_id]);
            }

            // Actualizar cabecera
            $tipo_comprobante = $body['tipo_comprobante'] ?? null;
            $observaciones    = array_key_exists('observaciones', $body) ? ($body['observaciones'] ?: null) : null;
            $envio_direccion  = array_key_exists('envio_direccion', $body) ? ($body['envio_direccion'] ?: null) : null;

            $db->prepare("
                UPDATE ventas
                SET cliente_id       = ?,
                    total            = ?,
                    tipo_comprobante = COALESCE(?, tipo_comprobante),
                    tipo_pago        = ?,
                    observaciones    = ?,
                    envio_precio     = ?,
                    envio_direccion  = ?,
                    fecha            = COALESCE(?, fecha)
                WHERE id = ?
            ")->execute([$nuevo_cliente_id, $nuevo_total, $tipo_comprobante, $nuevo_tipo_pago, $observaciones, $nuevo_envio, $envio_direccion, $nueva_fecha, $id]);

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            json(500, ['error' => 'Error al actualizar la venta: ' . $e->getMessage()]);
        }

        $this->get($id);
    }

    public function unificar(): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);

        $ids      = $body['ids']      ?? [];
        $tipo_pago = $body['tipo_pago'] ?? 'efectivo';

        if (!is_array($ids) || count($ids) < 2) {
            json(400, ['error' => 'Se necesitan al menos 2 comprobantes para unificar']);
        }

        $tipos_pago_validos = ['efectivo', 'transferencia', 'cc', 'tarjeta', 'cheque'];
        if (!in_array($tipo_pago, $tipos_pago_validos, true)) {
            json(400, ['error' => 'tipo_pago inválido']);
        }

        $db = DB::get();

        // Cargar ventas con sus ítems
        $ventas = [];
        foreach ($ids as $vid) {
            $vid = (int)$vid;
            $v = $this->loadVenta($vid, $db);
            if (!$v) json(404, ['error' => "Venta #$vid no encontrada"]);
            $ventas[] = $v;
        }

        // Validar tipos (solo REMITO o PRESUPUESTO)
        $tipos_unificables = ['REMITO', 'PRESUPUESTO'];
        foreach ($ventas as $v) {
            if (!in_array($v['tipo_comprobante'], $tipos_unificables, true)) {
                json(422, ['error' => "El comprobante N°{$v['numero']} ({$v['tipo_comprobante']}) no puede unificarse"]);
            }
        }

        // Validar mismo cliente (incluye consumidor final = null)
        $clientes = array_unique(array_map(fn($v) => $v['cliente_id'], $ventas));
        if (count($clientes) > 1) {
            json(422, ['error' => 'Todos los comprobantes deben pertenecer al mismo cliente']);
        }

        // Ordenar por fecha ASC (el más reciente tiene prioridad de precio)
        usort($ventas, fn($a, $b) => strcmp($a['fecha'], $b['fecha']) ?: ($a['id'] - $b['id']));

        // Tipo comprobante resultado: REMITO si hay algún REMITO, si no PRESUPUESTO
        $tipos_sel  = array_unique(array_column($ventas, 'tipo_comprobante'));
        $tipo_final = in_array('REMITO', $tipos_sel) ? 'REMITO' : 'PRESUPUESTO';

        // Fusionar ítems: suma de cantidades, precio del remito más reciente
        $merged = [];
        foreach ($ventas as $v) {
            foreach ($v['items'] as $item) {
                $pid = (int)$item['producto_id'];
                if (!isset($merged[$pid])) {
                    $merged[$pid] = [
                        'producto_id'     => $pid,
                        'cantidad'        => (float)$item['cantidad'],
                        'precio_unitario' => (float)$item['precio_unitario'],
                        'precio_original' => $item['precio_original'] !== null ? (float)$item['precio_original'] : null,
                        'ajuste_desc'     => $item['ajuste_desc'],
                        'ajuste_visible'  => (int)(bool)$item['ajuste_visible'],
                        'costo_unitario'  => (float)$item['costo_unitario'],
                    ];
                } else {
                    $merged[$pid]['cantidad']        += (float)$item['cantidad'];
                    // Precio del más reciente (loop va de más antiguo a más nuevo)
                    $merged[$pid]['precio_unitario']  = (float)$item['precio_unitario'];
                    $merged[$pid]['precio_original']  = $item['precio_original'] !== null ? (float)$item['precio_original'] : null;
                    $merged[$pid]['ajuste_desc']      = $item['ajuste_desc'];
                    $merged[$pid]['ajuste_visible']   = (int)(bool)$item['ajuste_visible'];
                    $merged[$pid]['costo_unitario']   = (float)$item['costo_unitario'];
                }
            }
        }
        $items_finales = array_values($merged);

        // Total
        $total_final = array_sum(array_map(fn($i) => $i['cantidad'] * $i['precio_unitario'], $items_finales));

        // Observaciones: concatenar únicas
        $obs_partes   = array_unique(array_filter(array_map(fn($v) => trim($v['observaciones'] ?? ''), $ventas)));
        $observaciones = $obs_partes ? implode(' / ', $obs_partes) : null;

        // origen_descripcion: "Remitos del DD/MM al DD/MM. Incluye: N°... (DD/MM), ..."
        $fmtCorta = fn(string $f): string => (new DateTime($f))->format('d/m');
        $primer   = $ventas[0]['fecha'];
        $ultimo   = end($ventas)['fecha'];
        $label    = count($tipos_sel) > 1 || $tipos_sel[0] === 'REMITO' ? 'Remitos' : 'Presupuestos';
        $detalle  = implode(', ', array_map(fn($v) => "N°{$v['numero']} ({$fmtCorta($v['fecha'])})", $ventas));
        $rango    = $primer === $ultimo
                    ? "{$label} del {$fmtCorta($primer)}"
                    : "{$label} del {$fmtCorta($primer)} al {$fmtCorta($ultimo)}";
        $origen_descripcion = "{$rango}. Incluye: {$detalle}";

        $cliente_id = $ventas[0]['cliente_id'];

        try {
            $db->beginTransaction();

            // Eliminar ventas originales (revertir stock, CC, movimientos)
            foreach ($ventas as $v) {
                foreach ($v['items'] as $item) {
                    $db->prepare("UPDATE productos SET stock_actual = stock_actual + ? WHERE id = ?")
                       ->execute([(float)$item['cantidad'], (int)$item['producto_id']]);
                }
                $db->prepare("DELETE FROM movimientos_stock WHERE referencia_id = ? AND tipo = 'venta'")->execute([$v['id']]);

                $pagos_v = [];
                if ($v['tipo_pago'] === 'mixto') {
                    $stmt = $db->prepare("SELECT tipo_pago AS tipo, monto FROM venta_pagos WHERE venta_id = ?");
                    $stmt->execute([$v['id']]);
                    $pagos_v = array_map(fn($p) => ['tipo' => $p['tipo'], 'monto' => (float)$p['monto']], $stmt->fetchAll());
                }
                $monto_cc_v = $this->montoCC($v['tipo_pago'], (float)$v['total'], $pagos_v);
                if ($monto_cc_v > 0 && $v['cliente_id']) {
                    $db->prepare("DELETE FROM cuenta_corriente_movimientos WHERE referencia_id = ? AND entidad_tipo = 'cliente'")->execute([$v['id']]);
                    $db->prepare("UPDATE clientes SET saldo_cuenta_corriente = saldo_cuenta_corriente - ? WHERE id = ?")
                       ->execute([$monto_cc_v, (int)$v['cliente_id']]);
                }
                $db->prepare("DELETE FROM venta_pagos WHERE venta_id = ?")->execute([$v['id']]);
                $db->prepare("DELETE FROM venta_items WHERE venta_id = ?")->execute([$v['id']]);
                $db->prepare("DELETE FROM ventas WHERE id = ?")->execute([$v['id']]);
            }

            // Insertar nueva venta unificada
            $db->prepare("
                INSERT INTO ventas
                    (fecha, cliente_id, total, tipo_comprobante, tipo_pago, estado, observaciones, origen_descripcion)
                VALUES (CURDATE(), ?, ?, ?, ?, 'completado', ?, ?)
            ")->execute([$cliente_id, $total_final, $tipo_final, $tipo_pago, $observaciones, $origen_descripcion]);
            $nueva_id = (int)$db->lastInsertId();

            // Insertar ítems, descontar stock, movimientos
            foreach ($items_finales as $item) {
                $db->prepare("
                    INSERT INTO venta_items
                        (venta_id, producto_id, cantidad, precio_unitario, precio_original, ajuste_desc, ajuste_visible, costo_unitario)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $nueva_id, $item['producto_id'], $item['cantidad'],
                    $item['precio_unitario'], $item['precio_original'],
                    $item['ajuste_desc'], $item['ajuste_visible'], $item['costo_unitario'],
                ]);
                $db->prepare("UPDATE productos SET stock_actual = stock_actual - ? WHERE id = ?")
                   ->execute([$item['cantidad'], $item['producto_id']]);
                $db->prepare("INSERT INTO movimientos_stock (producto_id, tipo, cantidad, referencia_id, fecha) VALUES (?, 'venta', ?, ?, NOW())")
                   ->execute([$item['producto_id'], $item['cantidad'], $nueva_id]);
            }

            // CC si corresponde
            if ($tipo_pago === 'cc' && $cliente_id) {
                $db->prepare("
                    INSERT INTO cuenta_corriente_movimientos
                        (entidad_tipo, entidad_id, tipo, monto, referencia_id, fecha)
                    VALUES ('cliente', ?, 'cargo', ?, ?, CURDATE())
                ")->execute([$cliente_id, $total_final, $nueva_id]);
                $db->prepare("UPDATE clientes SET saldo_cuenta_corriente = saldo_cuenta_corriente + ? WHERE id = ?")
                   ->execute([$total_final, $cliente_id]);
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            json(500, ['error' => 'Error al unificar: ' . $e->getMessage()]);
        }

        $this->get($nueva_id);
    }

    private function loadVenta(int $id, PDO $db): ?array {
        $stmt = $db->prepare("
            SELECT v.id, LPAD(v.id,8,'0') AS numero, v.fecha, v.tipo_comprobante,
                   v.tipo_pago, v.total, v.observaciones, v.cliente_id
            FROM ventas v WHERE v.id = ?
        ");
        $stmt->execute([$id]);
        $v = $stmt->fetch();
        if (!$v) return null;

        $stmt = $db->prepare("
            SELECT vi.producto_id, vi.cantidad, vi.precio_unitario,
                   vi.precio_original, vi.ajuste_desc, vi.ajuste_visible, vi.costo_unitario
            FROM venta_items vi WHERE vi.venta_id = ?
        ");
        $stmt->execute([$id]);
        $v['items']  = $stmt->fetchAll();
        $v['total']  = (float)$v['total'];
        $v['cliente_id'] = $v['cliente_id'] !== null ? (int)$v['cliente_id'] : null;
        return $v;
    }

    public function eliminar(int $id): void {
        if (!Auth::esAdmin()) {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $clave = trim($body['clave_autorizacion'] ?? '');
            $hash  = Configuracion::get()['clave_autorizacion_hash'] ?? null;

            if (!$hash) json(403, ['error' => 'No hay clave de autorización configurada. Pedile a un administrador que la configure.']);
            if ($clave === '' || !password_verify($clave, $hash)) {
                json(403, ['error' => 'Clave de autorización incorrecta']);
            }
        }

        $db = DB::get();

        $stmt = $db->prepare("SELECT id, cliente_id, tipo_pago, total FROM ventas WHERE id = ?");
        $stmt->execute([$id]);
        $venta = $stmt->fetch();
        if (!$venta) json(404, ['error' => 'Venta no encontrada']);

        $stmt = $db->prepare("SELECT producto_id, cantidad FROM venta_items WHERE venta_id = ?");
        $stmt->execute([$id]);
        $items = $stmt->fetchAll();

        try {
            $db->beginTransaction();

            // Revertir stock
            foreach ($items as $item) {
                $stmt = $db->prepare("UPDATE productos SET stock_actual = stock_actual + ? WHERE id = ?");
                $stmt->execute([(float)$item['cantidad'], (int)$item['producto_id']]);
            }

            // Borrar movimientos de stock
            $stmt = $db->prepare("DELETE FROM movimientos_stock WHERE referencia_id = ? AND tipo = 'venta'");
            $stmt->execute([$id]);

            // Revertir CC si corresponde (todo si era 'cc', solo la porción 'cc' si era mixto)
            $pagos_venta = [];
            if ($venta['tipo_pago'] === 'mixto') {
                $stmt = $db->prepare("SELECT tipo_pago AS tipo, monto FROM venta_pagos WHERE venta_id = ?");
                $stmt->execute([$id]);
                $pagos_venta = array_map(fn($p) => ['tipo' => $p['tipo'], 'monto' => (float)$p['monto']], $stmt->fetchAll());
            }
            $monto_cc = $this->montoCC($venta['tipo_pago'], (float)$venta['total'], $pagos_venta);
            if ($monto_cc > 0 && $venta['cliente_id']) {
                $stmt = $db->prepare(
                    "DELETE FROM cuenta_corriente_movimientos WHERE referencia_id = ? AND entidad_tipo = 'cliente'"
                );
                $stmt->execute([$id]);

                $stmt = $db->prepare(
                    "UPDATE clientes SET saldo_cuenta_corriente = saldo_cuenta_corriente - ? WHERE id = ?"
                );
                $stmt->execute([$monto_cc, (int)$venta['cliente_id']]);
            }

            // Borrar desglose de pago mixto, ítems y venta
            $db->prepare("DELETE FROM venta_pagos WHERE venta_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM venta_items WHERE venta_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM ventas WHERE id = ?")->execute([$id]);

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            json(500, ['error' => 'Error al eliminar: ' . $e->getMessage()]);
        }

        json(200, ['ok' => true, 'id' => $id]);
    }

    public function get(int $id): void {
        $db = DB::get();

        $stmt = $db->prepare("
            SELECT
                v.id,
                LPAD(v.id, 8, '0')  AS numero,
                v.fecha,
                v.tipo_comprobante,
                v.tipo_pago,
                v.total,
                v.estado,
                v.observaciones,
                v.origen_descripcion,
                v.envio_precio,
                v.envio_direccion,
                v.numero_afip,
                v.cae,
                c.id                AS cliente_id,
                c.nombre            AS cliente_nombre,
                c.cuit              AS cliente_cuit,
                c.condicion_iva     AS cliente_condicion_iva
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            WHERE v.id = ?
        ");
        $stmt->execute([$id]);
        $venta = $stmt->fetch();

        if (!$venta) {
            json(404, ['error' => 'Venta no encontrada']);
        }

        $stmt = $db->prepare("
            SELECT
                vi.id,
                vi.producto_id,
                p.codigo,
                p.nombre,
                vi.cantidad,
                vi.precio_unitario,
                vi.precio_original,
                vi.ajuste_desc,
                vi.ajuste_visible,
                vi.costo_unitario,
                (vi.cantidad * vi.precio_unitario) AS subtotal
            FROM venta_items vi
            LEFT JOIN productos p ON p.id = vi.producto_id
            WHERE vi.venta_id = ?
            ORDER BY vi.id
        ");
        $stmt->execute([$id]);
        $venta['items'] = $stmt->fetchAll();

        $venta['pagos'] = [];
        if ($venta['tipo_pago'] === 'mixto') {
            $stmt = $db->prepare("SELECT tipo_pago AS tipo, monto FROM venta_pagos WHERE venta_id = ? ORDER BY id");
            $stmt->execute([$id]);
            $venta['pagos'] = array_map(fn($p) => ['tipo' => $p['tipo'], 'monto' => (float)$p['monto']], $stmt->fetchAll());
        }

        // Castear tipos numéricos
        $venta['total']        = (float)$venta['total'];
        $venta['envio_precio'] = $venta['envio_precio'] !== null ? (float)$venta['envio_precio'] : null;
        foreach ($venta['items'] as &$item) {
            $item['cantidad']        = (float)$item['cantidad'];
            $item['precio_unitario'] = (float)$item['precio_unitario'];
            $item['precio_original'] = $item['precio_original'] !== null ? (float)$item['precio_original'] : null;
            $item['ajuste_visible']  = (bool)$item['ajuste_visible'];
            $item['costo_unitario']  = (float)$item['costo_unitario'];
            $item['subtotal']        = (float)$item['subtotal'];
        }

        json(200, $venta);
    }

    private function construirPdf(int $id): array {
        require_once __DIR__ . '/../helpers/Configuracion.php';
        require_once __DIR__ . '/../helpers/AfipQr.php';

        $db = DB::get();

        $stmt = $db->prepare("
            SELECT
                v.id,
                LPAD(v.id, 8, '0')  AS numero,
                v.fecha,
                v.tipo_comprobante,
                v.tipo_pago,
                v.total,
                v.observaciones,
                v.envio_precio,
                v.envio_direccion,
                v.numero_afip,
                v.cae,
                c.id                AS cliente_id,
                c.nombre            AS cliente_nombre,
                c.cuit              AS cliente_cuit,
                c.condicion_iva     AS cliente_condicion_iva,
                c.domicilio         AS cliente_domicilio,
                c.localidad         AS cliente_localidad,
                c.provincia         AS cliente_provincia,
                c.telefono          AS cliente_telefono
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            WHERE v.id = ?
        ");
        $stmt->execute([$id]);
        $venta = $stmt->fetch();

        if (!$venta) {
            json(404, ['error' => 'Venta no encontrada']);
        }

        $stmt = $db->prepare("
            SELECT
                vi.id,
                vi.producto_id,
                p.codigo,
                p.nombre,
                vi.cantidad,
                vi.precio_unitario,
                vi.precio_original,
                vi.ajuste_desc,
                vi.ajuste_visible,
                (vi.cantidad * vi.precio_unitario) AS subtotal
            FROM venta_items vi
            LEFT JOIN productos p ON p.id = vi.producto_id
            WHERE vi.venta_id = ?
            ORDER BY vi.id
        ");
        $stmt->execute([$id]);
        $venta['items'] = $stmt->fetchAll();

        $venta['total']        = (float)$venta['total'];
        $venta['envio_precio'] = $venta['envio_precio'] !== null ? (float)$venta['envio_precio'] : null;
        foreach ($venta['items'] as &$item) {
            $item['cantidad']        = (float)$item['cantidad'];
            $item['precio_unitario'] = (float)$item['precio_unitario'];
            $item['precio_original'] = $item['precio_original'] !== null ? (float)$item['precio_original'] : null;
            $item['ajuste_visible']  = (bool)$item['ajuste_visible'];
            $item['subtotal']        = (float)$item['subtotal'];
        }
        unset($item);

        $config = Configuracion::get();
        $venta['qr_data_uri'] = AfipQr::generar($venta, $config);

        ob_start();
        require __DIR__ . '/../../pos/templates/comprobante_pdf.php';
        $html = ob_get_clean();

        $options = new Dompdf\Options(['isRemoteEnabled' => true]);
        $options->setChroot([realpath(__DIR__ . '/../..')]);
        $dompdf = new Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return [$dompdf, $venta, $config];
    }

    public function comprobante(int $id): void {
        [$dompdf, $venta] = $this->construirPdf($id);

        // Sin esto el navegador puede servir una copia vieja cacheada del PDF (mismo
        // URL para la misma venta) y esconder cambios de plantilla recién aplicados.
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="comprobante-' . $venta['numero'] . '.pdf"');
        echo $dompdf->output();
        exit;
    }

    public function imprimir(int $id): void {
        require_once __DIR__ . '/../helpers/SilentPrint.php';

        [$dompdf, $venta, $config] = $this->construirPdf($id);

        // La copia automática solo se guarda al imprimir de verdad: si "carpeta_comprobantes"
        // está configurada como carpeta vigilada por un servicio de impresión externo, escribir
        // ahí en cada vista/descarga del PDF disparaba una impresión no deseada.
        if (!empty($config['carpeta_comprobantes']) && is_dir($config['carpeta_comprobantes'])) {
            try {
                file_put_contents(
                    rtrim($config['carpeta_comprobantes'], '\\/') . '/comprobante-' . $venta['numero'] . '.pdf',
                    $dompdf->output()
                );
            } catch (Throwable $e) {
                // No interrumpe la impresión si falla la copia automática.
            }
        }

        if (empty($config['impresora_nombre'])) {
            json(400, ['error' => 'No hay impresora configurada. Configurala en Configuración.']);
        }

        $tmp = sys_get_temp_dir() . '/bron_comprobante_' . $venta['numero'] . '.pdf';
        file_put_contents($tmp, $dompdf->output());

        $ok = SilentPrint::imprimir($tmp, $config['impresora_nombre']);
        @unlink($tmp);

        if (!$ok) json(500, ['error' => 'No se pudo enviar a imprimir']);
        json(200, ['ok' => true]);
    }
}
