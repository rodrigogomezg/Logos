<?php

class NotasEnvioController {

    public function listarPorVenta(int $ventaId): void {
        $db   = DB::get();
        $stmt = $db->prepare("
            SELECT id, numero, fecha_emision, fecha_entrega, transportista,
                   envio_precio, envio_direccion, observaciones
            FROM notas_envio
            WHERE venta_id = ?
            ORDER BY numero ASC
        ");
        $stmt->execute([$ventaId]);
        $notas = $stmt->fetchAll();

        foreach ($notas as &$nota) {
            $nota['numero'] = (int)$nota['numero'];
            $si = $db->prepare("
                SELECT id, venta_item_id, producto_id, nombre, codigo, cantidad
                FROM nota_envio_items
                WHERE nota_envio_id = ?
                ORDER BY id ASC
            ");
            $si->execute([(int)$nota['id']]);
            $nota['items'] = $si->fetchAll();
            foreach ($nota['items'] as &$item) {
                $item['cantidad']      = (float)$item['cantidad'];
                $item['venta_item_id'] = (int)$item['venta_item_id'];
            }
            unset($item);
        }
        unset($nota);

        json(200, $notas);
    }

    public function crear(): void {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $ventaId       = isset($body['venta_id'])      && is_numeric($body['venta_id'])      ? (int)$body['venta_id']       : null;
        $fechaEntrega  = trim($body['fecha_entrega']   ?? '');
        $transportista = trim($body['transportista']   ?? '') ?: null;
        $envioPrecio   = isset($body['envio_precio'])  && is_numeric($body['envio_precio'])   ? (float)$body['envio_precio'] : null;
        $envioDir      = trim($body['envio_direccion'] ?? '') ?: null;
        $observaciones = trim($body['observaciones']   ?? '') ?: null;
        $items         = is_array($body['items'] ?? null) ? $body['items'] : [];

        // Billing params
        $accionPrecio  = in_array($body['accion_precio'] ?? '', ['caja', 'cc'], true) ? $body['accion_precio'] : null;
        $medioPago     = trim($body['medio_pago'] ?? '') ?: 'efectivo';
        $generarAfip   = !empty($body['generar_afip']);
        $tipoCbteEnvio = trim($body['tipo_comprobante_envio'] ?? '') ?: null;
        $cajaId        = isset($body['caja_id']) && is_numeric($body['caja_id']) ? (int)$body['caja_id'] : null;

        if (!$ventaId)    json(400, ['error' => 'venta_id requerido']);
        if (empty($items)) json(400, ['error' => 'Debe incluir al menos un ítem']);

        $db = DB::get();

        // Load venta + client info (needed for CC movement and AFIP)
        $chkStmt = $db->prepare("
            SELECT v.id, v.cliente_id, v.sucursal_id,
                   c.nombre AS cliente_nombre, c.cuit AS cliente_cuit,
                   c.condicion_iva AS cliente_condicion_iva
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            WHERE v.id = ?
        ");
        $chkStmt->execute([$ventaId]);
        $vRow = $chkStmt->fetch();
        if (!$vRow) json(404, ['error' => 'Venta no encontrada']);

        // Verificar que todos los venta_item_id pertenezcan a esta venta
        $itemIds = array_values(array_filter(array_map(fn($i) => (int)($i['venta_item_id'] ?? 0), $items)));
        if (!empty($itemIds)) {
            $ph    = implode(',', array_fill(0, count($itemIds), '?'));
            $chkIt = $db->prepare("SELECT COUNT(*) FROM venta_items WHERE id IN ($ph) AND venta_id = ?");
            $chkIt->execute([...$itemIds, $ventaId]);
            if ((int)$chkIt->fetchColumn() !== count($itemIds)) {
                json(400, ['error' => 'Uno o más ítems no pertenecen a esta venta']);
            }
        }

        // Número secuencial por venta
        $num = $db->prepare("SELECT COALESCE(MAX(numero), 0) + 1 FROM notas_envio WHERE venta_id = ?");
        $num->execute([$ventaId]);
        $numero = (int)$num->fetchColumn();

        $cbteVentaId = null;

        $db->beginTransaction();
        try {
            $ins = $db->prepare("
                INSERT INTO notas_envio
                    (venta_id, numero, fecha_emision, fecha_entrega, transportista, envio_precio, envio_direccion, observaciones)
                VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?)
            ");
            $ins->execute([$ventaId, $numero, $fechaEntrega ?: null, $transportista, $envioPrecio, $envioDir, $observaciones]);
            $notaId = (int)$db->lastInsertId();

            $insItem = $db->prepare("
                INSERT INTO nota_envio_items (nota_envio_id, venta_item_id, producto_id, nombre, codigo, cantidad)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($items as $item) {
                $cant = (float)($item['cantidad'] ?? 0);
                if ($cant <= 0) continue;
                $insItem->execute([
                    $notaId,
                    (int)($item['venta_item_id'] ?? 0),
                    isset($item['producto_id']) ? (int)$item['producto_id'] : null,
                    trim($item['nombre']  ?? ''),
                    trim($item['codigo']  ?? '') ?: null,
                    $cant,
                ]);
            }

            // Handle billing when envio_precio is set
            if ($envioPrecio > 0 && $accionPrecio !== null) {
                $usuarioId  = Auth::usuarioActual()['id'] ?? null;
                $sucursalId = (int)($vRow['sucursal_id'] ?? 1);
                $clienteId  = $vRow['cliente_id'] ? (int)$vRow['cliente_id'] : null;

                if ($accionPrecio === 'cc') {
                    if (!$clienteId) json(422, ['error' => 'La venta no tiene cliente asignado para registrar en cuenta corriente']);
                    $motivo = 'Costo de envío - Nota de envío N° ' . $numero;
                    $db->prepare("
                        INSERT INTO cuenta_corriente_movimientos
                            (entidad_tipo, entidad_id, tipo, monto, referencia_id, fecha)
                        VALUES ('cliente', ?, 'cargo', ?, ?, CURDATE())
                    ")->execute([$clienteId, $envioPrecio, $notaId]);
                    $db->prepare("UPDATE clientes SET saldo_cuenta_corriente = saldo_cuenta_corriente + ? WHERE id = ?")
                       ->execute([$envioPrecio, $clienteId]);

                } elseif ($accionPrecio === 'caja') {
                    $turnoId = null;
                    if ($cajaId) {
                        $tStmt = $db->prepare("SELECT id FROM caja_turnos WHERE caja_id = ? AND estado = 'abierto' LIMIT 1");
                        $tStmt->execute([$cajaId]);
                        $tRow    = $tStmt->fetch();
                        $turnoId = $tRow ? (int)$tRow['id'] : null;
                    }

                    if ($turnoId) {
                        $motivo = 'Costo de envío - Nota N° ' . $numero .
                                  ($vRow['cliente_nombre'] ? ' (' . $vRow['cliente_nombre'] . ')' : '');
                        $db->prepare("
                            INSERT INTO caja_movimientos (turno_id, tipo, medio_pago, monto, motivo, usuario_id, creado_en)
                            VALUES (?, 'ingreso', ?, ?, ?, ?, NOW())
                        ")->execute([$turnoId, $medioPago, $envioPrecio, $motivo, $usuarioId]);
                    }

                    if ($generarAfip && $tipoCbteEnvio) {
                        $db->prepare("
                            INSERT INTO ventas
                                (fecha, cliente_id, total, tipo_comprobante, tipo_pago, estado,
                                 observaciones, caja_id, usuario_id, turno_id, sucursal_id)
                            VALUES (CURDATE(), ?, ?, ?, ?, 'completado', ?, ?, ?, ?, ?)
                        ")->execute([
                            $clienteId,
                            $envioPrecio,
                            $tipoCbteEnvio,
                            $medioPago,
                            'Costo de envío - Nota N° ' . $numero,
                            $cajaId,
                            $usuarioId,
                            $turnoId,
                            $sucursalId,
                        ]);
                        $cbteVentaId = (int)$db->lastInsertId();
                    }
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Request CAE from AFIP after commit (avoids holding DB lock during network call)
        $caeResult = null;
        $caeError  = null;
        if ($cbteVentaId !== null) {
            require_once __DIR__ . '/../helpers/AfipWs.php';
            require_once __DIR__ . '/../helpers/Configuracion.php';

            $ivaPct = in_array($tipoCbteEnvio, ['FC C-ELECT', 'NC C-ELECT'], true) ? 0 : 21;
            $ventaAfip = [
                'id'                    => $cbteVentaId,
                'fecha'                 => date('Y-m-d'),
                'total'                 => $envioPrecio,
                'tipo_comprobante'      => $tipoCbteEnvio,
                'cae'                   => null,
                'envio_precio'          => null,
                'numero_afip_pendiente' => null,
                'cbte_asoc_tipo'        => null,
                'cbte_asoc_pto_vta'     => null,
                'cbte_asoc_nro'         => null,
                'cliente_cuit'          => $vRow['cliente_cuit'] ?? null,
                'cliente_condicion_iva' => $vRow['cliente_condicion_iva'] ?? null,
                'items_iva'             => [
                    ['cantidad' => 1, 'precio_unitario' => $envioPrecio, 'iva_porcentaje' => $ivaPct],
                ],
            ];

            try {
                $config    = Configuracion::get();
                $r         = AfipWs::facturar($ventaAfip, $config);
                $caeResult = $r;
                DB::get()->prepare("
                    UPDATE ventas
                    SET numero_afip = ?, cae = ?, cae_vencimiento = ?,
                        punto_venta = ?, afip_response = ?, afip_error = NULL
                    WHERE id = ?
                ")->execute([
                    (string)$r['numero_afip'],
                    $r['cae'],
                    $r['cae_vencimiento'],
                    $r['punto_venta'],
                    json_encode($r['response'] ?? null, JSON_UNESCAPED_UNICODE),
                    $cbteVentaId,
                ]);
            } catch (Throwable $e) {
                $caeError = mb_substr($e->getMessage(), 0, 500);
                DB::get()->prepare("UPDATE ventas SET afip_error = ? WHERE id = ?")
                         ->execute([$caeError, $cbteVentaId]);
            }
        }

        $resp = ['id' => $notaId, 'numero' => $numero];
        if ($cbteVentaId) $resp['cbte_id']     = $cbteVentaId;
        if ($caeResult)   $resp['cae']          = $caeResult['cae'];
        if ($caeResult)   $resp['numero_afip']  = $caeResult['numero_afip'];
        if ($caeError)    $resp['cae_error']    = $caeError;

        json(201, $resp);
    }

    public function pdf(int $id): void {
        if (!extension_loaded('gd')) {
            json(500, ['error' => 'La extensión GD de PHP no está habilitada. Habilitá "extension=gd" en php.ini y reiniciá Apache.']);
        }

        $db   = DB::get();
        $stmt = $db->prepare("
            SELECT ne.id, ne.venta_id, ne.numero, ne.fecha_emision, ne.fecha_entrega,
                   ne.transportista, ne.envio_precio, ne.envio_direccion, ne.observaciones,
                   LPAD(v.id, 8, '0') AS venta_numero, v.fecha AS venta_fecha,
                   v.tipo_comprobante,
                   c.nombre AS cliente_nombre, c.cuit AS cliente_cuit,
                   c.domicilio AS cliente_domicilio, c.telefono AS cliente_telefono
            FROM notas_envio ne
            JOIN ventas v     ON v.id = ne.venta_id
            LEFT JOIN clientes c ON c.id = v.cliente_id
            WHERE ne.id = ?
        ");
        $stmt->execute([$id]);
        $nota = $stmt->fetch();
        if (!$nota) json(404, ['error' => 'Nota de envío no encontrada']);

        // Ítems de ESTA nota
        $si = $db->prepare("
            SELECT venta_item_id, producto_id, nombre, codigo, cantidad
            FROM nota_envio_items
            WHERE nota_envio_id = ?
            ORDER BY id ASC
        ");
        $si->execute([$id]);
        $nota['items'] = $si->fetchAll();
        foreach ($nota['items'] as &$it) {
            $it['cantidad']      = (float)$it['cantidad'];
            $it['venta_item_id'] = (int)$it['venta_item_id'];
        }
        unset($it);

        // Mapa venta_item_id → cantidad de ESTA entrega
        $estaEntrega = [];
        foreach ($nota['items'] as $it) {
            $estaEntrega[$it['venta_item_id']] = $it['cantidad'];
        }

        // Notas ANTERIORES (para mostrar historial)
        $sp = $db->prepare("
            SELECT ne.id, ne.numero, ne.fecha_emision, ne.fecha_entrega, ne.transportista
            FROM notas_envio ne
            WHERE ne.venta_id = ? AND ne.id < ?
            ORDER BY ne.numero ASC
        ");
        $sp->execute([(int)$nota['venta_id'], $id]);
        $notasPrevias = $sp->fetchAll();

        foreach ($notasPrevias as &$prev) {
            $spi = $db->prepare("
                SELECT venta_item_id, nombre, codigo, cantidad
                FROM nota_envio_items
                WHERE nota_envio_id = ?
                ORDER BY id ASC
            ");
            $spi->execute([(int)$prev['id']]);
            $prev['items'] = $spi->fetchAll();
            foreach ($prev['items'] as &$pi) { $pi['cantidad'] = (float)$pi['cantidad']; }
            unset($pi);
        }
        unset($prev);
        $nota['notas_previas'] = $notasPrevias;

        // Todos los ítems del remito con totales enviados (incluye esta nota)
        $sv = $db->prepare("
            SELECT vi.id AS venta_item_id, vi.producto_id,
                   COALESCE(p.nombre, '(producto eliminado)') AS nombre,
                   COALESCE(p.codigo, '') AS codigo,
                   vi.cantidad AS cantidad_total,
                   COALESCE((
                       SELECT SUM(nei.cantidad)
                       FROM nota_envio_items nei
                       JOIN notas_envio ne2 ON ne2.id = nei.nota_envio_id
                       WHERE nei.venta_item_id = vi.id AND ne2.venta_id = ?
                   ), 0) AS cantidad_enviada_total
            FROM venta_items vi
            LEFT JOIN productos p ON p.id = vi.producto_id
            WHERE vi.venta_id = ?
            ORDER BY vi.id ASC
        ");
        $sv->execute([(int)$nota['venta_id'], (int)$nota['venta_id']]);
        $ventaItems = $sv->fetchAll();

        foreach ($ventaItems as &$vi) {
            $vi['venta_item_id']       = (int)$vi['venta_item_id'];
            $vi['cantidad_total']       = (float)$vi['cantidad_total'];
            $vi['cantidad_enviada_total'] = (float)$vi['cantidad_enviada_total'];
            $viId = $vi['venta_item_id'];
            $vi['cantidad_esta']       = $estaEntrega[$viId] ?? 0.0;
            $vi['cantidad_previo']     = $vi['cantidad_enviada_total'] - $vi['cantidad_esta'];
            $vi['cantidad_pendiente']  = max(0.0, $vi['cantidad_total'] - $vi['cantidad_enviada_total']);
        }
        unset($vi);
        $nota['venta_items'] = $ventaItems;

        require_once __DIR__ . '/../helpers/Configuracion.php';
        $config = Configuracion::get();

        ob_start();
        require __DIR__ . '/../../pos/templates/nota_envio_pdf.php';
        $html = ob_get_clean();

        $options = new Dompdf\Options(['isRemoteEnabled' => true]);
        $options->setChroot([realpath(__DIR__ . '/../..')]);
        $dompdf = new Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="nota-envio-' . $nota['venta_numero'] . '-' . $nota['numero'] . '.pdf"');
        echo $dompdf->output();
        exit;
    }
}
