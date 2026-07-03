<?php

require_once __DIR__ . '/../config/db.php';

class CuentaCorrienteController {

    private function tablaEntidad(string $tipo): string {
        return $tipo === 'proveedor' ? 'proveedores' : 'clientes';
    }

    private function tablaComprobante(string $tipo): string {
        return $tipo === 'proveedor' ? 'compras' : 'ventas';
    }

    private function columnaEntidadComprobante(string $tipo): string {
        return $tipo === 'proveedor' ? 'proveedor_id' : 'cliente_id';
    }

    private function columnaAsignacion(string $tipo): string {
        return $tipo === 'proveedor' ? 'compra_id' : 'venta_id';
    }

    public function listar(): void {
        $entidad_tipo = $_GET['entidad_tipo'] ?? 'cliente';
        if (!in_array($entidad_tipo, ['cliente', 'proveedor'], true)) {
            json(400, ['error' => 'entidad_tipo inválido']);
        }

        $entidad_id = $_GET['entidad_id'] ?? $_GET['cliente_id'] ?? null;
        $entidad_id = is_numeric($entidad_id) ? (int)$entidad_id : null;
        if (!$entidad_id) json(400, ['error' => 'entidad_id requerido']);

        $limit  = min((int)($_GET['limit']  ?? 500), 2000);
        $offset = max((int)($_GET['offset'] ?? 0),   0);

        $db          = DB::get();
        $tablaEnt    = $this->tablaEntidad($entidad_tipo);
        $tablaComp   = $this->tablaComprobante($entidad_tipo);
        $colEntComp  = $this->columnaEntidadComprobante($entidad_tipo);
        $colAsig     = $this->columnaAsignacion($entidad_tipo);

        // 1. Entidad (cliente o proveedor)
        if ($entidad_tipo === 'cliente') {
            $stmt = $db->prepare("
                SELECT id, nombre, cuit, condicion_iva, limite_credito, saldo_cuenta_corriente,
                       email, telefono, domicilio, localidad, provincia, observaciones
                FROM clientes WHERE id = ?
            ");
        } else {
            $stmt = $db->prepare("
                SELECT id, nombre, cuit, condicion_iva, saldo_cuenta_corriente
                FROM proveedores WHERE id = ?
            ");
        }
        $stmt->execute([$entidad_id]);
        $entidad = $stmt->fetch();
        if (!$entidad) json(404, ['error' => ucfirst($entidad_tipo) . ' no encontrado']);
        if (isset($entidad['limite_credito'])) $entidad['limite_credito'] = (float)$entidad['limite_credito'];
        $entidad['saldo_cuenta_corriente'] = (float)$entidad['saldo_cuenta_corriente'];

        // 2. Movimientos en orden cronológico (ASC), join al comprobante para los cargos
        $stmt = $db->prepare("
            SELECT m.id, m.tipo, m.monto, m.fecha, m.referencia_id, m.observaciones,
                   m.medio_pago, m.pago_datos, m.comprobante,
                   c.tipo_comprobante AS ref_tipo
            FROM cuenta_corriente_movimientos m
            LEFT JOIN $tablaComp c ON c.id = m.referencia_id AND m.tipo = 'cargo'
            WHERE m.entidad_tipo = ? AND m.entidad_id = ?
            ORDER BY m.fecha ASC, m.id ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$entidad_tipo, $entidad_id, $limit, $offset]);
        $movimientos = $stmt->fetchAll();

        // Asignaciones de pagos
        $pago_ids = [];
        foreach ($movimientos as $m) {
            if ($m['tipo'] === 'pago') $pago_ids[] = (int)$m['id'];
        }
        $asig_map = [];
        if (!empty($pago_ids)) {
            $ph   = implode(',', array_fill(0, count($pago_ids), '?'));
            $stmt = $db->prepare("
                SELECT a.movimiento_id, a.$colAsig AS comprobante_id, a.monto,
                       c.tipo_comprobante AS comprobante_tipo
                FROM cc_asignaciones a
                JOIN $tablaComp c ON c.id = a.$colAsig
                WHERE a.movimiento_id IN ($ph)
                ORDER BY a.id ASC
            ");
            $stmt->execute($pago_ids);
            foreach ($stmt->fetchAll() as $a) {
                $asig_map[(int)$a['movimiento_id']][] = [
                    'venta_id'   => (int)$a['comprobante_id'],
                    'venta_tipo' => $a['comprobante_tipo'],
                    'monto'      => (float)$a['monto'],
                ];
            }
        }

        foreach ($movimientos as &$m) {
            $m['monto']        = (float)$m['monto'];
            $m['pago_datos']   = $m['pago_datos'] ? json_decode($m['pago_datos'], true) : null;
            $m['asignaciones'] = $asig_map[(int)$m['id']] ?? [];
        }
        unset($m);

        // 3. Comprobantes 100% CC con saldo pendiente > 0
        $stmt = $db->prepare("
            SELECT v.id, v.fecha, v.total, v.tipo_comprobante,
                   COALESCE(SUM(a.monto), 0)             AS monto_pagado,
                   (v.total - COALESCE(SUM(a.monto), 0)) AS saldo_pendiente
            FROM $tablaComp v
            LEFT JOIN cc_asignaciones a ON a.$colAsig = v.id
            WHERE v.$colEntComp = ? AND v.tipo_pago = 'cc'
            GROUP BY v.id
            HAVING saldo_pendiente > 0.001
            ORDER BY v.fecha ASC, v.id ASC
        ");
        $stmt->execute([$entidad_id]);
        $pendientes = $stmt->fetchAll();

        foreach ($pendientes as &$v) {
            $v['total']           = (float)$v['total'];
            $v['monto_pagado']    = (float)$v['monto_pagado'];
            $v['saldo_pendiente'] = (float)$v['saldo_pendiente'];
        }
        unset($v);

        $claveEntidad   = $entidad_tipo === 'cliente' ? 'cliente' : 'proveedor';
        $clavePendiente = $entidad_tipo === 'cliente' ? 'ventas_cc' : 'compras_cc';

        json(200, [
            $claveEntidad     => $entidad,
            'movimientos'     => $movimientos,
            $clavePendiente   => $pendientes,
        ]);
    }

    public function eliminar(int $id): void {
        $db = DB::get();

        $stmt = $db->prepare("
            SELECT id, entidad_tipo, tipo, monto, entidad_id, referencia_id
            FROM cuenta_corriente_movimientos
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        $mov = $stmt->fetch();

        if (!$mov) json(404, ['error' => 'Movimiento no encontrado']);

        $tablaComp  = $this->tablaComprobante($mov['entidad_tipo']);
        $tablaEnt   = $this->tablaEntidad($mov['entidad_tipo']);

        // Eliminar un cargo implica eliminar el comprobante que lo originó:
        // hay que revertir también su stock, igual que al eliminar la venta
        // o la compra directamente.
        $refId = $mov['tipo'] === 'cargo' && $mov['referencia_id'] ? (int)$mov['referencia_id'] : null;

        if ($refId !== null && $mov['entidad_tipo'] === 'cliente') {
            $stmt = $db->prepare("SELECT cae FROM ventas WHERE id = ?");
            $stmt->execute([$refId]);
            $venta = $stmt->fetch();
            if ($venta && !empty($venta['cae'])) {
                json(422, ['error' => 'La venta asociada ya fue autorizada por AFIP (tiene CAE) y no se puede eliminar. Corresponde emitir una nota de crédito.']);
            }
        }

        $db->beginTransaction();
        try {
            if ($refId !== null) {
                if ($mov['entidad_tipo'] === 'cliente') {
                    // Revertir stock de la venta y borrar sus rastros
                    $stmt = $db->prepare("SELECT producto_id, cantidad FROM venta_items WHERE venta_id = ?");
                    $stmt->execute([$refId]);
                    foreach ($stmt->fetchAll() as $it) {
                        $db->prepare("UPDATE productos SET stock_actual = stock_actual + ? WHERE id = ?")
                           ->execute([(float)$it['cantidad'], (int)$it['producto_id']]);
                    }
                    $db->prepare("DELETE FROM movimientos_stock WHERE referencia_id = ? AND tipo = 'venta'")->execute([$refId]);
                    $db->prepare("DELETE FROM venta_pagos WHERE venta_id = ?")->execute([$refId]);
                    $db->prepare("DELETE FROM venta_items WHERE venta_id = ?")->execute([$refId]);
                } else {
                    // Revertir stock de la compra (la compra lo había sumado)
                    $stmt = $db->prepare("SELECT producto_id, cantidad FROM compra_items WHERE compra_id = ?");
                    $stmt->execute([$refId]);
                    foreach ($stmt->fetchAll() as $it) {
                        $db->prepare("UPDATE productos SET stock_actual = stock_actual - ? WHERE id = ?")
                           ->execute([(float)$it['cantidad'], (int)$it['producto_id']]);
                    }
                    $db->prepare("DELETE FROM movimientos_stock WHERE referencia_id = ? AND tipo = 'compra'")->execute([$refId]);
                    $db->prepare("DELETE FROM compra_items WHERE compra_id = ?")->execute([$refId]);
                    $db->prepare("DELETE FROM compra_pagos WHERE compra_id = ?")->execute([$refId]);
                    // Retiros de caja generados por la compra: solo se revierten
                    // si el turno sigue abierto (un turno cerrado ya fue arqueado)
                    $db->prepare("
                        DELETE cm FROM caja_movimientos cm
                        JOIN caja_turnos t ON t.id = cm.turno_id
                        WHERE t.estado = 'abierto' AND cm.tipo = 'retiro' AND cm.motivo LIKE ?
                    ")->execute(['Compra #' . $refId . ' - %']);
                }
                // Eliminar el comprobante (cc_asignaciones tiene CASCADE sobre venta_id/compra_id)
                $db->prepare("DELETE FROM $tablaComp WHERE id = ?")->execute([$refId]);
            }

            // Eliminar el movimiento (cc_asignaciones.movimiento_id tiene CASCADE para pagos)
            $db->prepare("DELETE FROM cuenta_corriente_movimientos WHERE id = ?")
               ->execute([$id]);

            // Revertir saldo: cargo sumó → restamos; pago restó → sumamos
            $delta = $mov['tipo'] === 'cargo' ? -(float)$mov['monto'] : (float)$mov['monto'];
            $db->prepare("
                UPDATE $tablaEnt SET saldo_cuenta_corriente = saldo_cuenta_corriente + ?
                WHERE id = ?
            ")->execute([$delta, $mov['entidad_id']]);

            $db->commit();
            json(200, ['ok' => true]);
        } catch (Throwable $e) {
            $db->rollBack();
            json(500, ['error' => $e->getMessage()]);
        }
    }

    public function registrar(): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);

        $entidad_tipo = $body['entidad_tipo'] ?? 'cliente';
        if (!in_array($entidad_tipo, ['cliente', 'proveedor'], true)) {
            json(400, ['error' => 'entidad_tipo inválido']);
        }

        $entidad_id   = $body['entidad_id'] ?? $body['cliente_id'] ?? null;
        $entidad_id   = is_numeric($entidad_id) ? (int)$entidad_id : null;
        $monto        = isset($body['monto']) ? (float)$body['monto'] : 0.0;
        $tipo         = $body['tipo'] ?? 'pago';
        $obs          = isset($body['observaciones']) ? trim($body['observaciones']) : null;
        $fecha        = isset($body['fecha']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['fecha'])
                        ? $body['fecha'] : date('Y-m-d');
        $asignaciones = isset($body['asignaciones']) && is_array($body['asignaciones'])
                        ? $body['asignaciones'] : [];

        $medio_pago  = isset($body['medio_pago']) && in_array($body['medio_pago'], ['efectivo','transferencia','cheque'], true)
                       ? $body['medio_pago'] : 'efectivo';
        $pago_datos  = isset($body['pago_datos']) && is_array($body['pago_datos'])
                       ? json_encode($body['pago_datos'], JSON_UNESCAPED_UNICODE) : null;
        $comprobante = isset($body['comprobante']) ? trim($body['comprobante']) : null;

        if (!$entidad_id || $monto <= 0 || !in_array($tipo, ['pago', 'cargo'], true)) {
            json(400, ['error' => 'entidad_id, monto > 0 y tipo (pago|cargo) son requeridos']);
        }

        $db        = DB::get();
        $tablaEnt  = $this->tablaEntidad($entidad_tipo);
        $tablaComp = $this->tablaComprobante($entidad_tipo);
        $colEntComp = $this->columnaEntidadComprobante($entidad_tipo);
        $colAsig   = $this->columnaAsignacion($entidad_tipo);

        $stmt = $db->prepare("SELECT id, nombre, saldo_cuenta_corriente FROM $tablaEnt WHERE id = ?");
        $stmt->execute([$entidad_id]);
        $entidad = $stmt->fetch();
        if (!$entidad) json(404, ['error' => ucfirst($entidad_tipo) . ' no encontrado']);

        // Un pago de CC en efectivo o transferencia mueve plata real: entra a la
        // caja (cobro de cliente) o sale de ella (pago a proveedor). Sin esto,
        // el efectivo esperado del arqueo no coincide con el cajón.
        $caja_id  = isset($body['caja_id']) && is_numeric($body['caja_id']) ? (int)$body['caja_id'] : null;
        $turno_id = null;
        $tocaCaja = $tipo === 'pago' && in_array($medio_pago, ['efectivo', 'transferencia'], true) && $caja_id !== null;
        if ($tocaCaja) {
            $stmt = $db->prepare("SELECT id FROM caja_turnos WHERE caja_id = ? AND estado = 'abierto'");
            $stmt->execute([$caja_id]);
            $turno = $stmt->fetch();
            if (!$turno) json(409, ['error' => 'No hay un turno abierto en la caja para registrar el cobro. Abrí la caja o registrá el pago sin caja desde una sesión de administrador.']);
            $turno_id = (int)$turno['id'];
        }

        // Validar asignaciones
        $asigs_ok       = [];
        $total_asignado = 0.0;
        foreach ($asignaciones as $a) {
            $comprobante_id = isset($a['venta_id']) ? (int)$a['venta_id'] : 0;
            $monto_a        = isset($a['monto'])    ? (float)$a['monto']  : 0.0;
            if (!$comprobante_id || $monto_a < 0.001) continue;

            $stmt2 = $db->prepare("
                SELECT v.total, COALESCE(SUM(ca.monto), 0) AS ya_pagado
                FROM $tablaComp v
                LEFT JOIN cc_asignaciones ca ON ca.$colAsig = v.id
                WHERE v.id = ? AND v.$colEntComp = ? AND v.tipo_pago = 'cc'
                GROUP BY v.id
            ");
            $stmt2->execute([$comprobante_id, $entidad_id]);
            $comprobante_row = $stmt2->fetch();
            if (!$comprobante_row) json(422, ['error' => "Comprobante $comprobante_id no válido para esta entidad"]);

            $saldo_disp = (float)$comprobante_row['total'] - (float)$comprobante_row['ya_pagado'];
            if ($monto_a > $saldo_disp + 0.01) {
                json(422, ['error' => "Asignación para comprobante $comprobante_id excede el saldo disponible"]);
            }

            $asigs_ok[]      = ['comprobante_id' => $comprobante_id, 'monto' => min($monto_a, $saldo_disp)];
            $total_asignado += $monto_a;
        }

        if ($total_asignado > $monto + 0.01) {
            json(422, ['error' => 'El total asignado supera el monto del pago']);
        }

        $db->beginTransaction();
        try {
            $db->prepare("
                INSERT INTO cuenta_corriente_movimientos
                    (entidad_tipo, entidad_id, tipo, monto, fecha, observaciones,
                     medio_pago, pago_datos, comprobante)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$entidad_tipo, $entidad_id, $tipo, $monto, $fecha, $obs ?: null,
                         $medio_pago, $pago_datos, $comprobante ?: null]);
            $mov_id = (int)$db->lastInsertId();

            foreach ($asigs_ok as $a) {
                $db->prepare("INSERT INTO cc_asignaciones (movimiento_id, $colAsig, monto) VALUES (?, ?, ?)")
                   ->execute([$mov_id, $a['comprobante_id'], $a['monto']]);
            }

            $delta = $tipo === 'pago' ? -$monto : $monto;
            $db->prepare("UPDATE $tablaEnt SET saldo_cuenta_corriente = saldo_cuenta_corriente + ? WHERE id = ?")
               ->execute([$delta, $entidad_id]);

            if ($tocaCaja) {
                $tipoMov = $entidad_tipo === 'cliente' ? 'ingreso' : 'retiro';
                $motivo  = ($entidad_tipo === 'cliente' ? 'Cobro CC - ' : 'Pago CC - ') . $entidad['nombre'] . " (mov #$mov_id)";
                $db->prepare("
                    INSERT INTO caja_movimientos (turno_id, tipo, medio_pago, monto, motivo, usuario_id, creado_en)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ")->execute([$turno_id, $tipoMov, $medio_pago, $monto, $motivo, Auth::usuarioActual()['id'] ?? null]);
            }

            $db->commit();

            $stmt = $db->prepare("SELECT saldo_cuenta_corriente FROM $tablaEnt WHERE id = ?");
            $stmt->execute([$entidad_id]);
            json(200, ['ok' => true, 'saldo_actual' => (float)$stmt->fetchColumn()]);
        } catch (Throwable $e) {
            $db->rollBack();
            json(500, ['error' => $e->getMessage()]);
        }
    }
}
