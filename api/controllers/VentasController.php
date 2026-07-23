<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Auth.php';
require_once __DIR__ . '/../helpers/Configuracion.php';
require_once __DIR__ . '/../helpers/Validadores.php';
require_once __DIR__ . '/../helpers/LogAcciones.php';

class VentasController {

    private const TIPOS_PAGO_SIMPLES = ['efectivo', 'transferencia', 'cc', 'tarjeta', 'cheque', 'mercado_pago'];
    private const TIPOS_ELECTRONICOS = ['FC A-ELECT', 'FC B-ELECT', 'FC C-ELECT', 'NC A-ELECT', 'NC B-ELECT', 'NC C-ELECT'];

    /**
     * Pide el CAE a AFIP para una venta ya guardada y persiste el resultado.
     * Devuelve null si salió bien, o el mensaje de error (que también queda
     * registrado en ventas.afip_error para reintentar después).
     */
    private function solicitarCae(int $venta_id): ?string {
        require_once __DIR__ . '/../helpers/AfipWs.php';

        $db = DB::get();
        $stmt = $db->prepare("
            SELECT v.id, v.fecha, v.total, v.tipo_comprobante, v.cae, v.envio_precio,
                   v.numero_afip_pendiente,
                   v.cbte_asoc_tipo, v.cbte_asoc_pto_vta, v.cbte_asoc_nro,
                   c.cuit AS cliente_cuit, c.condicion_iva AS cliente_condicion_iva
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            WHERE v.id = ?
        ");
        $stmt->execute([$venta_id]);
        $venta = $stmt->fetch();
        if (!$venta) return 'Venta no encontrada';
        if (!in_array($venta['tipo_comprobante'], self::TIPOS_ELECTRONICOS, true)) {
            return 'Este comprobante no es electrónico.';
        }
        if (!empty($venta['cae'])) return null; // ya autorizada

        // Desglose de IVA por ítem: el comprobante debe declarar cada alícuota
        // presente en la venta (21 / 10.5 / 0), no una única alícuota global.
        // Las NC no tienen ítems: AfipWs cae al desglose de alícuota única.
        $stmtI = $db->prepare("
            SELECT vi.cantidad, vi.precio_unitario, COALESCE(p.iva_porcentaje, 21) AS iva_porcentaje
            FROM venta_items vi
            LEFT JOIN productos p ON p.id = vi.producto_id
            WHERE vi.venta_id = ?
        ");
        $stmtI->execute([$venta_id]);
        $venta['items_iva'] = $stmtI->fetchAll();

        try {
            $r = AfipWs::facturar($venta, Configuracion::get());
            $db->prepare("
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
                $venta_id,
            ]);
            return null;
        } catch (Throwable $e) {
            $msg = mb_substr($e->getMessage(), 0, 500);
            $db->prepare("UPDATE ventas SET afip_error = ? WHERE id = ?")->execute([$msg, $venta_id]);
            return $msg;
        }
    }

    /** POST /ventas/{id}/facturar — reintento manual de una factura/NC sin CAE */
    public function facturar(int $id): void {
        $error = $this->solicitarCae($id);
        if ($error !== null) json(502, ['error' => $error]);
        $this->get($id);
    }

    /**
     * POST /ventas/{id}/confirmar-presupuesto
     * Transforma un PRESUPUESTO en REMITO o Factura electrónica.
     * Actualiza tipo_comprobante, cliente y forma de pago in-place.
     * Si el destino es una FC, el frontend debe llamar /facturar por separado.
     *
     * Body JSON:
     *   tipo_comprobante  string  (requerido) REMITO|FC B-ELECT|FC A-ELECT|FC C-ELECT
     *   cliente_id        int|null (opcional) null = consumidor final; omitir = mantener el actual
     *   tipo_pago         string  (requerido) efectivo|transferencia|cc|tarjeta|cheque|mercado_pago|mixto
     *   pagos             array   (requerido si mixto) [{tipo, monto}, ...]
     */
    public function confirmarPresupuesto(int $id): void {
        Auth::requireLogin();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $tipo_nuevo    = $body['tipo_comprobante'] ?? null;
        $tipos_destino = ['REMITO', 'FC B-ELECT', 'FC A-ELECT', 'FC C-ELECT'];
        if (!in_array($tipo_nuevo, $tipos_destino, true)) {
            json(400, ['error' => 'tipo_comprobante debe ser REMITO, FC B-ELECT, FC A-ELECT o FC C-ELECT']);
        }

        $tipo_pago_nuevo = $body['tipo_pago'] ?? null;
        $tipos_pago_ok   = [...self::TIPOS_PAGO_SIMPLES, 'mixto'];
        if (!in_array($tipo_pago_nuevo, $tipos_pago_ok, true)) {
            json(400, ['error' => 'tipo_pago inválido']);
        }

        // cliente_id: false=no cambiar; null=consumidor final; int=cliente
        $cliente_id_body = array_key_exists('cliente_id', $body)
            ? (isset($body['cliente_id']) ? (int)$body['cliente_id'] : null)
            : false;

        $db = DB::get();

        $stmt = $db->prepare("SELECT id, cliente_id, tipo_comprobante, tipo_pago, total, estado FROM ventas WHERE id = ?");
        $stmt->execute([$id]);
        $venta = $stmt->fetch();
        if (!$venta) json(404, ['error' => 'Presupuesto no encontrado']);
        if ($venta['tipo_comprobante'] !== 'PRESUPUESTO') {
            json(422, ['error' => 'Solo se pueden confirmar comprobantes de tipo PRESUPUESTO']);
        }
        if ($venta['estado'] === 'anulado') {
            json(422, ['error' => 'El presupuesto está anulado y no se puede confirmar']);
        }

        $total            = (float)$venta['total'];
        $cli_id_actual    = $venta['cliente_id'] !== null ? (int)$venta['cliente_id'] : null;
        $cli_id_nuevo     = $cliente_id_body === false ? $cli_id_actual : $cliente_id_body;

        // Validar nuevo cliente si se especificó
        if ($cli_id_nuevo !== null) {
            $stmt = $db->prepare("SELECT id, saldo_cuenta_corriente, limite_credito FROM clientes WHERE id = ?");
            $stmt->execute([$cli_id_nuevo]);
            if (!$stmt->fetch()) json(404, ['error' => 'Cliente no encontrado']);
        }

        // Validar pago mixto
        $pagos_mixto = [];
        if ($tipo_pago_nuevo === 'mixto') {
            $pagos_mixto = $this->validarPagosMixto($body['pagos'] ?? [], $total);
        }

        $monto_cc_nuevo = $this->montoCC($tipo_pago_nuevo, $total, $pagos_mixto);
        if ($monto_cc_nuevo > 0 && $cli_id_nuevo === null) {
            json(422, ['error' => 'Una venta en cuenta corriente necesita un cliente asignado.']);
        }

        // Calcular CC antiguo para revertirlo
        $tipo_pago_viejo = $venta['tipo_pago'];
        $pagos_viejos    = [];
        if ($tipo_pago_viejo === 'mixto') {
            $stmt = $db->prepare("SELECT tipo_pago AS tipo, monto FROM venta_pagos WHERE venta_id = ?");
            $stmt->execute([$id]);
            $pagos_viejos = $stmt->fetchAll();
        }
        $monto_cc_viejo = $this->montoCC($tipo_pago_viejo, $total, $pagos_viejos);

        try {
            $db->beginTransaction();

            // 1. Revertir CC viejo si existía
            if ($monto_cc_viejo > 0 && $cli_id_actual !== null) {
                $db->prepare("DELETE FROM cuenta_corriente_movimientos WHERE referencia_id = ? AND entidad_tipo = 'cliente' AND tipo = 'cargo'")->execute([$id]);
                $db->prepare("UPDATE clientes SET saldo_cuenta_corriente = saldo_cuenta_corriente - ? WHERE id = ?")->execute([$monto_cc_viejo, $cli_id_actual]);
            }

            // 2. Verificar límite CC nuevo (con FOR UPDATE para evitar race condition)
            if ($monto_cc_nuevo > 0 && $cli_id_nuevo !== null) {
                $stmt = $db->prepare("SELECT saldo_cuenta_corriente, limite_credito FROM clientes WHERE id = ? FOR UPDATE");
                $stmt->execute([$cli_id_nuevo]);
                $cli = $stmt->fetch();
                if ((float)$cli['limite_credito'] > 0 && (float)$cli['saldo_cuenta_corriente'] + $monto_cc_nuevo > (float)$cli['limite_credito']) {
                    $db->rollBack();
                    json(422, [
                        'error'        => 'Límite de crédito insuficiente',
                        'saldo_actual' => (float)$cli['saldo_cuenta_corriente'],
                        'limite'       => (float)$cli['limite_credito'],
                        'monto_venta'  => $monto_cc_nuevo,
                    ]);
                }
            }

            // 3. Actualizar la venta
            $db->prepare("UPDATE ventas SET tipo_comprobante = ?, cliente_id = ?, tipo_pago = ? WHERE id = ?")
               ->execute([$tipo_nuevo, $cli_id_nuevo, $tipo_pago_nuevo, $id]);

            // 4. Reemplazar pagos mixtos
            $db->prepare("DELETE FROM venta_pagos WHERE venta_id = ?")->execute([$id]);
            if ($tipo_pago_nuevo === 'mixto') {
                foreach ($pagos_mixto as $p) {
                    $db->prepare("INSERT INTO venta_pagos (venta_id, tipo_pago, monto) VALUES (?, ?, ?)")
                       ->execute([$id, $p['tipo'], $p['monto']]);
                }
            }

            // 5. Registrar CC nuevo
            if ($monto_cc_nuevo > 0 && $cli_id_nuevo !== null) {
                $db->prepare("INSERT INTO cuenta_corriente_movimientos (entidad_tipo, entidad_id, tipo, monto, referencia_id, fecha) VALUES ('cliente', ?, 'cargo', ?, ?, CURDATE())")
                   ->execute([$cli_id_nuevo, $monto_cc_nuevo, $id]);
                $db->prepare("UPDATE clientes SET saldo_cuenta_corriente = saldo_cuenta_corriente + ? WHERE id = ?")
                   ->execute([$monto_cc_nuevo, $cli_id_nuevo]);
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            json(500, ['error' => 'Error al confirmar presupuesto: ' . $e->getMessage()]);
        }

        LogAcciones::registrar('confirmar_presupuesto', 'venta', $id, [
            'tipo_nuevo'  => $tipo_nuevo,
            'tipo_pago'   => $tipo_pago_nuevo,
            'cliente_id'  => $cli_id_nuevo,
        ]);

        $this->get($id);
    }

    /**
     * POST /ventas/{id}/nota-credito
     * Emite una Nota de Crédito A o B asociada a la factura indicada.
     *
     * Body JSON:
     *   total           float    (requerido) importe a acreditar
     *   motivo          string   (opcional)  va a observaciones
     *   confirmar_padron bool    (opcional)  true para obviar alerta de padrón
     *
     * La letra de la NC la determina el sistema (FC A → NC A, FC B → NC B).
     * La NC no ajusta automáticamente stock ni cuenta corriente: usá los módulos
     * correspondientes si el crédito implica una devolución física o de saldo.
     */
    public function emitirNc(int $venta_id): void {
        require_once __DIR__ . '/../helpers/AfipWs.php';
        require_once __DIR__ . '/../helpers/AfipPadron.php';

        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);

        $total_nc = isset($body['total']) && is_numeric($body['total']) ? round((float)$body['total'], 2) : null;
        if ($total_nc === null || $total_nc <= 0) {
            json(400, ['error' => 'total es requerido y debe ser mayor a 0']);
        }

        $motivo          = isset($body['motivo']) && trim($body['motivo']) !== '' ? trim($body['motivo']) : null;
        $confirmar_padron = !empty($body['confirmar_padron']);

        $db     = DB::get();
        $config = Configuracion::get();

        // Cargar factura original
        $stmt = $db->prepare("
            SELECT v.id, v.fecha, v.total, v.tipo_comprobante,
                   v.numero_afip, v.punto_venta, v.cae,
                   v.cliente_id,
                   c.cuit              AS cliente_cuit,
                   c.condicion_iva     AS cliente_condicion_iva,
                   c.nombre            AS cliente_nombre
            FROM ventas v
            LEFT JOIN clientes c ON c.id = v.cliente_id
            WHERE v.id = ?
        ");
        $stmt->execute([$venta_id]);
        $original = $stmt->fetch();

        if (!$original) json(404, ['error' => 'Comprobante original no encontrado']);
        if (empty($original['cae'])) {
            json(422, ['error' => 'El comprobante original no tiene CAE. Solo se pueden acreditar facturas electrónicas autorizadas por ARCA.']);
        }

        // Determinar tipo de NC según la letra de la factura original
        static $NC_MAP = [
            'FC A-ELECT' => 'NC A-ELECT',
            'FC B-ELECT' => 'NC B-ELECT',
            'FC C-ELECT' => 'NC C-ELECT',
        ];
        $tipo_nc = $NC_MAP[$original['tipo_comprobante']] ?? null;
        if ($tipo_nc === null) {
            json(422, ['error' => "No se puede emitir NC para el tipo '{$original['tipo_comprobante']}'."]);
        }

        // Punto de venta y número del comprobante original para CbtesAsoc
        $tipoCmpAfip = AfipWs::tipoCmp($original['tipo_comprobante']);
        $ptoVta      = (int)($original['punto_venta'] ?: ($config['punto_venta'] ?? 0));
        $nro_original = (int)$original['numero_afip'];

        if ($ptoVta < 1)    json(422, ['error' => 'El punto de venta no está configurado en el sistema.']);
        if ($nro_original < 1) json(422, ['error' => 'El comprobante original no tiene número AFIP válido.']);

        // Saldo disponible: total original menos lo ya acreditado con NCs autorizadas
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(total), 0) AS acreditado
            FROM ventas
            WHERE cbte_asoc_tipo = ? AND cbte_asoc_pto_vta = ? AND cbte_asoc_nro = ?
              AND tipo_comprobante IN ('NC A-ELECT', 'NC B-ELECT', 'NC C-ELECT')
              AND cae IS NOT NULL
              AND estado != 'anulado'
        ");
        $stmt->execute([$tipoCmpAfip, $ptoVta, $nro_original]);
        $acreditado = round((float)$stmt->fetchColumn(), 2);
        $saldo      = round((float)$original['total'] - $acreditado, 2);

        if ($total_nc > $saldo + 0.01) {
            json(422, [
                'error'      => "El importe de la NC ($" . number_format($total_nc, 2) . ") supera el saldo disponible ($" . number_format($saldo, 2) . ").",
                'total_orig' => (float)$original['total'],
                'acreditado' => $acreditado,
                'saldo'      => $saldo,
                'pedido'     => $total_nc,
            ]);
        }

        // Validación suave del padrón de ARCA.
        // FC C (Monotributista) va a cualquier receptor independientemente de su condición
        // de IVA, así que el check de consistencia de letra no aplica para NC C.
        $esNcC = ($tipo_nc === 'NC C-ELECT');
        if (!$confirmar_padron && !$esNcC && !empty($original['cliente_cuit'])) {
            $cuit_receptor = (int)preg_replace('/\D/', '', (string)$original['cliente_cuit']);
            if ($cuit_receptor) {
                $condicion_padron = AfipPadron::condicionIva($cuit_receptor, $config);
                if ($condicion_padron !== null) {
                    $guardada = (string)($original['cliente_condicion_iva'] ?? '');
                    if (!AfipPadron::condicionCoincide($condicion_padron, $guardada)) {
                        json(409, [
                            'error'              => 'La condición de IVA del cliente en la ficha no coincide con el padrón de ARCA.',
                            'condicion_ficha'    => $guardada ?: '(sin dato)',
                            'condicion_padron'   => $condicion_padron,
                            'requiere_confirmar' => true,
                            'hint'               => "Actualizá la ficha del cliente o reenvía con confirmar_padron:true para emitir de todas formas.",
                        ]);
                    }
                }
            }
        }

        // Insertar NC en ventas
        try {
            $db->beginTransaction();

            $usuario_id = Auth::usuarioActual()['id'] ?? null;

            $db->prepare("
                INSERT INTO ventas
                    (fecha, cliente_id, total, tipo_comprobante, tipo_pago, estado,
                     observaciones, cbte_asoc_tipo, cbte_asoc_pto_vta, cbte_asoc_nro, usuario_id)
                VALUES (CURDATE(), ?, ?, ?, 'nc', 'completado', ?, ?, ?, ?, ?)
            ")->execute([
                $original['cliente_id'],
                $total_nc,
                $tipo_nc,
                $motivo,
                $tipoCmpAfip,
                $ptoVta,
                $nro_original,
                $usuario_id,
            ]);
            $nc_id = (int)$db->lastInsertId();

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            json(500, ['error' => 'Error al crear la NC: ' . $e->getMessage()]);
        }

        // Pedir CAE fuera de la transacción
        $error = $this->solicitarCae($nc_id);
        if ($error !== null) {
            // La NC queda guardada con afip_error; se puede reintentar con POST /ventas/{nc_id}/facturar
            json(502, [
                'error'  => 'La NC se registró pero ARCA devolvió un error al pedir el CAE: ' . $error,
                'nc_id'  => $nc_id,
                'reintento' => "POST /ventas/$nc_id/facturar",
            ]);
        }

        $this->get($nc_id);
    }

    /** GET /ventas/{id}/afip-estado — consulta FECompConsultar para un comprobante ya emitido */
    public function consultarAfip(int $id): void {
        require_once __DIR__ . '/../helpers/AfipWs.php';

        $db   = DB::get();
        $stmt = $db->prepare("SELECT tipo_comprobante, numero_afip, punto_venta FROM ventas WHERE id = ?");
        $stmt->execute([$id]);
        $venta = $stmt->fetch();

        if (!$venta) json(404, ['error' => 'Venta no encontrada']);
        if (empty($venta['numero_afip'])) {
            json(422, ['error' => 'Este comprobante no tiene número ARCA asignado todavía.']);
        }

        $config  = Configuracion::get();
        $tipoCmp = AfipWs::tipoCmp($venta['tipo_comprobante']);
        if ($tipoCmp === null) json(422, ['error' => 'Este comprobante no es electrónico.']);

        $ptoVta = (int)($venta['punto_venta'] ?: ($config['punto_venta'] ?? 0));
        $numero = (int)$venta['numero_afip'];

        $resultado = AfipWs::consultarComprobante($tipoCmp, $ptoVta, $numero, $config);
        if ($resultado === null) {
            json(502, ['error' => 'No se pudo consultar el estado en ARCA (servicio no disponible o comprobante no encontrado).']);
        }

        json(200, $resultado);
    }

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
        try {
            if ($fecha_desde !== '') Validadores::validarFecha($fecha_desde, 'fecha_desde');
            if ($fecha_hasta !== '') Validadores::validarFecha($fecha_hasta, 'fecha_hasta');
            if ($fecha_desde !== '' && $fecha_hasta !== '') Validadores::validarRangoFechas($fecha_desde, $fecha_hasta);
        } catch (Throwable $e) { json(400, ['error' => $e->getMessage()]); }
        $tipo_comprobante = trim($_GET['tipo']         ?? '');
        $cliente_id       = isset($_GET['cliente_id']) && is_numeric($_GET['cliente_id'])
                            ? (int)$_GET['cliente_id'] : null;
        $caja_id          = isset($_GET['caja_id']) && is_numeric($_GET['caja_id']) ? (int)$_GET['caja_id'] : null;
        $turno_id_filter  = isset($_GET['turno_id']) && is_numeric($_GET['turno_id']) ? (int)$_GET['turno_id'] : null;
        $sin_cae          = !empty($_GET['sin_cae']);
        $q                = trim($_GET['q']            ?? '');
        $monto_min        = isset($_GET['monto_min']) && is_numeric($_GET['monto_min']) ? (float)$_GET['monto_min'] : null;
        $monto_max        = isset($_GET['monto_max']) && is_numeric($_GET['monto_max']) ? (float)$_GET['monto_max'] : null;
        $limit            = min((int)($_GET['limit']  ?? 50), 500);
        $offset           = max((int)($_GET['offset'] ?? 0),  0);
        $mostrar_anuladas = !empty($_GET['mostrar_anuladas']);

        if (!$mostrar_anuladas) { $where[] = "v.estado != 'anulado'"; }

        if ($fecha_desde)      { $where[] = 'v.fecha >= ?';              $params[] = $fecha_desde;      }
        if ($fecha_hasta)      { $where[] = 'v.fecha <= ?';              $params[] = $fecha_hasta;      }
        if ($tipo_comprobante) { $where[] = 'v.tipo_comprobante = ?';    $params[] = $tipo_comprobante; }
        if ($cliente_id)       { $where[] = 'v.cliente_id = ?';          $params[] = $cliente_id;       }
        if ($caja_id)          { $where[] = 'v.caja_id = ?';             $params[] = $caja_id;          }
        if ($turno_id_filter)  { $where[] = 'v.turno_id = ?';            $params[] = $turno_id_filter;  }
        if ($sin_cae) {
            $where[] = "(v.tipo_comprobante IN ('FC A-ELECT','FC B-ELECT','FC C-ELECT') AND (v.cae IS NULL OR v.cae = ''))";
        }
        $sucursal_filter  = isset($_GET['sucursal_id'])  && is_numeric($_GET['sucursal_id'])  ? (int)$_GET['sucursal_id']  : null;
        $vendedor_filter  = isset($_GET['vendedor_id'])  && is_numeric($_GET['vendedor_id'])  ? (int)$_GET['vendedor_id']  : null;
        if ($sucursal_filter)  { $where[] = 'v.sucursal_id = ?';         $params[] = $sucursal_filter;  }
        if ($vendedor_filter)  { $where[] = 'v.vendedor_id = ?';         $params[] = $vendedor_filter;  }
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
                   v.fecha, v.tipo_comprobante, v.tipo_pago, v.total, v.estado, v.cae, v.afip_error,
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

        $carrito_id       = trim($body['carrito_id'] ?? '');
        $tipo_pago        = $body['tipo_pago']        ?? 'efectivo';
        $tipo_comprobante = $body['tipo_comprobante'] ?? 'REMITO';
        $cliente_id       = isset($body['cliente_id']) ? (int)$body['cliente_id'] : null;
        $caja_id          = isset($body['caja_id'])    && is_numeric($body['caja_id'])    ? (int)$body['caja_id']    : null;
        $usuario_id       = Auth::usuarioActual()['id']
                            ?? (isset($body['usuario_id']) && is_numeric($body['usuario_id']) ? (int)$body['usuario_id'] : null);
        $observaciones    = $body['observaciones']    ?? null;
        $envio_precio     = isset($body['envio_precio']) && is_numeric($body['envio_precio']) && $body['envio_precio'] > 0
                            ? (float)$body['envio_precio'] : null;
        $envio_direccion  = isset($body['envio_direccion']) && trim($body['envio_direccion']) !== ''
                            ? trim($body['envio_direccion']) : null;
        $fecha            = isset($body['fecha']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['fecha'])
                            ? $body['fecha'] : date('Y-m-d');
        $vendedor_id      = isset($body['vendedor_id']) && is_numeric($body['vendedor_id']) ? (int)$body['vendedor_id'] : null;

        $tipos_pago_validos = [...self::TIPOS_PAGO_SIMPLES, 'mixto'];
        if (!in_array($tipo_pago, $tipos_pago_validos, true)) {
            json(400, ['error' => 'tipo_pago inválido. Valores: ' . implode(', ', $tipos_pago_validos)]);
        }

        $db = DB::get();

        // Si la caja es de tipo venta, exigir un turno abierto
        $turno_id    = null;
        $sucursal_id = 1;
        $deposito_id = 1;
        if ($caja_id !== null) {
            $stmt = $db->prepare("SELECT tipo, sucursal_id FROM cajas WHERE id = ?");
            $stmt->execute([$caja_id]);
            $caja = $stmt->fetch();
            if ($caja) {
                $sucursal_id = (int)($caja['sucursal_id'] ?? 1);
                // Depósito principal de la sucursal (de donde se descuenta stock)
                $dep = $db->prepare("SELECT id FROM depositos WHERE sucursal_id = ? AND es_principal = 1 LIMIT 1");
                $dep->execute([$sucursal_id]);
                $depRow = $dep->fetch();
                if ($depRow) $deposito_id = (int)$depRow['id'];

                if ($caja['tipo'] === 'venta') {
                    $stmt = $db->prepare("SELECT id FROM caja_turnos WHERE caja_id = ? AND estado = 'abierto'");
                    $stmt->execute([$caja_id]);
                    $turno = $stmt->fetch();
                    if (!$turno) {
                        json(409, ['error' => 'No hay un turno de caja abierto. Abrí la caja antes de vender.']);
                    }
                    $turno_id = (int)$turno['id'];
                }
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
        $items_validados  = [];
        $total            = 0;
        $permitirSinStock = (bool)(Configuracion::get()['ventas_sin_stock'] ?? false);

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

            // Verificar stock disponible (descontando reservas de otros carritos)
            $stmtStock = $db->prepare("
                SELECT p.stock_actual - COALESCE((
                    SELECT SUM(sr.cantidad) FROM stock_reservas sr
                    WHERE sr.producto_id = p.id
                      AND sr.carrito_id != ?
                      AND sr.vence_en > NOW()
                ), 0) AS disponible
                FROM productos p WHERE p.id = ?
            ");
            $stmtStock->execute([$carrito_id ?: '', $producto_id]);
            $disponible = (float)($stmtStock->fetchColumn() ?? $producto['stock_actual']);
            if ($disponible < $cantidad && !$permitirSinStock) {
                json(422, [
                    'error'       => "Stock insuficiente para \"{$producto['nombre']}\"",
                    'disponible'  => max(0, $disponible),
                    'solicitado'  => $cantidad,
                ]);
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

        // Una venta a cuenta corriente sin cliente no se puede cobrar nunca:
        // la plata desaparecería del circuito.
        if ($monto_cc > 0 && $cliente_id === null) {
            json(422, ['error' => 'Una venta en cuenta corriente necesita un cliente asignado.']);
        }

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
        require_once __DIR__ . '/ReservasController.php';
        try {
            $db->beginTransaction();

            // Liberar reservas del carrito antes de descontar stock
            ReservasController::liberarEnTransaccion($db, $carrito_id);

            // 1. Insertar venta
            $stmt = $db->prepare("
                INSERT INTO ventas (fecha, cliente_id, total, tipo_comprobante, tipo_pago, estado, observaciones, envio_precio, envio_direccion, caja_id, usuario_id, turno_id, sucursal_id, vendedor_id)
                VALUES (?, ?, ?, ?, ?, 'completado', ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$fecha, $cliente_id, $total, $tipo_comprobante, $tipo_pago, $observaciones, $envio_precio, $envio_direccion, $caja_id, $usuario_id, $turno_id, $sucursal_id, $vendedor_id]);
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

                // 3. Descontar stock (total y por depósito)
                $db->prepare("UPDATE productos SET stock_actual = stock_actual - ? WHERE id = ?")
                   ->execute([$item['cantidad'], $item['producto_id']]);
                $db->prepare("
                    INSERT INTO stock_depositos (producto_id, deposito_id, stock_actual)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE stock_actual = stock_actual + VALUES(stock_actual)
                ")->execute([$item['producto_id'], $deposito_id, -$item['cantidad']]);

                // 4. Registrar movimiento de stock
                $db->prepare("
                    INSERT INTO movimientos_stock (producto_id, deposito_id, tipo, cantidad, referencia_id, fecha)
                    VALUES (?, ?, 'venta', ?, ?, NOW())
                ")->execute([$item['producto_id'], $deposito_id, $item['cantidad'], $venta_id]);
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
                // Re-verificar el límite con la fila bloqueada: el check previo fue
                // sin lock y dos cajas vendiendo a la vez podían superarlo juntas.
                $stmt = $db->prepare("SELECT saldo_cuenta_corriente, limite_credito FROM clientes WHERE id = ? FOR UPDATE");
                $stmt->execute([$cliente_id]);
                $cli = $stmt->fetch();
                if ((float)$cli['limite_credito'] > 0 && (float)$cli['saldo_cuenta_corriente'] + $monto_cc > (float)$cli['limite_credito']) {
                    $db->rollBack();
                    json(422, [
                        'error'        => 'Límite de crédito insuficiente',
                        'saldo_actual' => (float)$cli['saldo_cuenta_corriente'],
                        'limite'       => (float)$cli['limite_credito'],
                        'monto_venta'  => $monto_cc,
                    ]);
                }

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

        $items_con_desc = array_filter($items_validados, fn($it) => $it['ajuste_desc'] !== null);
        if (!empty($items_con_desc)) {
            LogAcciones::registrar('descuento_manual', 'venta', $venta_id, [
                'total'                => $total,
                'items_con_descuento'  => count($items_con_desc),
            ]);
        }

        // Factura electrónica: el CAE se solicita desde el frontend en segundo plano
        // (POST /ventas/{id}/facturar) inmediatamente después de recibir esta respuesta,
        // para que el POS quede libre sin esperar a ARCA.

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

        $stmt = $db->prepare("SELECT id, tipo_pago, cae FROM ventas WHERE id = ?");
        $stmt->execute([$id]);
        $venta = $stmt->fetch();
        if (!$venta) json(404, ['error' => 'Venta no encontrada']);
        if (!empty($venta['cae'])) {
            json(422, ['error' => 'Esta factura ya fue autorizada por AFIP (tiene CAE) y no se puede modificar. Corresponde emitir una nota de crédito.']);
        }

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

        $tipos_comp = ['REMITO', 'FC B-ELECT', 'FC A-ELECT', 'FC C-ELECT', 'PRESUPUESTO'];
        $tipos_pago = ['efectivo', 'transferencia', 'tarjeta', 'cheque', 'cc', 'mercado_pago'];

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

        LogAcciones::registrar('editar_venta', 'venta', $id, ['campos' => $sets, 'tipo_pago' => $nuevo_tipo_pago]);

        $this->get($id);
    }

    private function actualizarCompleto(int $id, array $body): void {
        $db = DB::get();

        $stmt = $db->prepare("SELECT id, cliente_id, tipo_pago, total, cae FROM ventas WHERE id = ?");
        $stmt->execute([$id]);
        $venta = $stmt->fetch();
        if (!$venta) json(404, ['error' => 'Venta no encontrada']);
        if (!empty($venta['cae'])) {
            json(422, ['error' => 'Esta factura ya fue autorizada por AFIP (tiene CAE) y no se puede modificar. Corresponde emitir una nota de crédito.']);
        }

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
        if ($monto_cc_nuevo > 0 && !$nuevo_cliente_id) {
            json(422, ['error' => 'Una venta en cuenta corriente necesita un cliente asignado.']);
        }

        // Re-verificar el límite de crédito con el nuevo monto (la creación lo
        // controla; la edición no puede ser la puerta de atrás)
        if ($monto_cc_nuevo > 0 && $nuevo_cliente_id) {
            $stmt = $db->prepare("SELECT saldo_cuenta_corriente, limite_credito FROM clientes WHERE id = ?");
            $stmt->execute([$nuevo_cliente_id]);
            $cli = $stmt->fetch();
            if (!$cli) json(404, ['error' => 'Cliente no encontrado']);
            $descuentaViejo = ((int)$venta['cliente_id'] === (int)$nuevo_cliente_id) ? $monto_cc_viejo : 0.0;
            $nuevoSaldo = (float)$cli['saldo_cuenta_corriente'] - $descuentaViejo + $monto_cc_nuevo;
            if ((float)$cli['limite_credito'] > 0 && $nuevoSaldo > (float)$cli['limite_credito']) {
                json(422, [
                    'error'  => 'Límite de crédito insuficiente para esta edición',
                    'saldo_actual' => (float)$cli['saldo_cuenta_corriente'],
                    'limite'       => (float)$cli['limite_credito'],
                ]);
            }
        }

        try {
            $db->beginTransaction();

            // Revertir stock de ítems viejos
            $stmt = $db->prepare("SELECT producto_id, cantidad FROM venta_items WHERE venta_id = ?");
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll() as $iv) {
                $msRow = $db->prepare("SELECT deposito_id FROM movimientos_stock WHERE referencia_id = ? AND producto_id = ? AND tipo = 'venta' LIMIT 1");
                $msRow->execute([$id, (int)$iv['producto_id']]);
                $depId = (int)(($msRow->fetch()['deposito_id'] ?? 1));

                $db->prepare("UPDATE productos SET stock_actual = stock_actual + ? WHERE id = ?")
                   ->execute([(float)$iv['cantidad'], (int)$iv['producto_id']]);
                $db->prepare("INSERT INTO stock_depositos (producto_id, deposito_id, stock_actual) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stock_actual = stock_actual + VALUES(stock_actual)")
                   ->execute([(int)$iv['producto_id'], $depId, (float)$iv['cantidad']]);
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

            // Depósito principal de la sucursal de la venta para los nuevos ítems
            $ventaSucRow = $db->prepare("SELECT sucursal_id FROM ventas WHERE id = ?");
            $ventaSucRow->execute([$id]);
            $ventaSucId = (int)(($ventaSucRow->fetch()['sucursal_id'] ?? 1));
            $ventaDepRow = $db->prepare("SELECT id FROM depositos WHERE sucursal_id = ? AND es_principal = 1 LIMIT 1");
            $ventaDepRow->execute([$ventaSucId]);
            $ventaDepId = (int)(($ventaDepRow->fetch()['id'] ?? 1));

            // Insertar nuevos ítems, descontar stock, registrar movimientos
            foreach ($items_validados as $item) {
                $db->prepare("INSERT INTO venta_items (venta_id, producto_id, cantidad, precio_unitario, precio_original, ajuste_desc, ajuste_visible, costo_unitario) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                   ->execute([$id, $item['producto_id'], $item['cantidad'], $item['precio_unitario'], $item['precio_original'], $item['ajuste_desc'], $item['ajuste_visible'], $item['costo_unitario']]);
                $db->prepare("UPDATE productos SET stock_actual = stock_actual - ? WHERE id = ?")
                   ->execute([$item['cantidad'], $item['producto_id']]);
                $db->prepare("INSERT INTO stock_depositos (producto_id, deposito_id, stock_actual) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stock_actual = stock_actual + VALUES(stock_actual)")
                   ->execute([$item['producto_id'], $ventaDepId, -$item['cantidad']]);
                $db->prepare("INSERT INTO movimientos_stock (producto_id, deposito_id, tipo, cantidad, referencia_id, fecha) VALUES (?, ?, 'venta', ?, ?, NOW())")
                   ->execute([$item['producto_id'], $ventaDepId, $item['cantidad'], $id]);
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
                // Re-verificar límite con la fila bloqueada (el saldo ya refleja la
                // reversión del cargo viejo hecha arriba, dentro de esta transacción).
                $stmt = $db->prepare("SELECT saldo_cuenta_corriente, limite_credito FROM clientes WHERE id = ? FOR UPDATE");
                $stmt->execute([$nuevo_cliente_id]);
                $cli = $stmt->fetch();
                if ((float)$cli['limite_credito'] > 0 && (float)$cli['saldo_cuenta_corriente'] + $monto_cc_nuevo > (float)$cli['limite_credito']) {
                    $db->rollBack();
                    json(422, [
                        'error'        => 'Límite de crédito insuficiente para esta edición',
                        'saldo_actual' => (float)$cli['saldo_cuenta_corriente'],
                        'limite'       => (float)$cli['limite_credito'],
                    ]);
                }

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

        LogAcciones::registrar('editar_venta', 'venta', $id, ['total' => $nuevo_total, 'tipo_pago' => $nuevo_tipo_pago]);

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

        $tipos_pago_validos = ['efectivo', 'transferencia', 'cc', 'tarjeta', 'cheque', 'mercado_pago'];
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
            if ($v['tipo_comprobante'] === 'REMITO' && $v['tipo_pago'] !== 'cc') {
                json(422, ['error' => "El remito N°{$v['numero']} tiene forma de pago \"{$v['tipo_pago']}\" y no puede unificarse. Solo se pueden unificar remitos a cuenta corriente."]);
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

        // Total: ítems + envíos de los comprobantes originales (antes se perdían)
        $envio_total = array_sum(array_map(fn($v) => (float)($v['envio_precio'] ?? 0), $ventas));
        $total_final = array_sum(array_map(fn($i) => $i['cantidad'] * $i['precio_unitario'], $items_finales)) + $envio_total;

        // Detalle de envíos individuales (solo cuando hay más de uno) para mostrar con fecha en el PDF
        $envios_con_precio = array_values(array_filter($ventas, fn($v) => (float)($v['envio_precio'] ?? 0) > 0));
        $envios_detalle    = null;
        if (count($envios_con_precio) > 1) {
            $envios_detalle = json_encode(array_map(fn($v) => [
                'fecha_corta' => (new DateTime($v['fecha']))->format('d/m'),
                'precio'      => (float)$v['envio_precio'],
            ], $envios_con_precio));
        }

        // Caja y turno: el comprobante unificado tiene que entrar al arqueo
        // como cualquier venta (antes quedaba sin caja ni turno).
        $caja_id         = isset($body['caja_id']) && is_numeric($body['caja_id']) ? (int)$body['caja_id'] : null;
        $usuario_id      = Auth::usuarioActual()['id'] ?? null;
        $turno_id        = null;
        $sucursal_id_uni = 1;
        $deposito_id_uni = 1;
        if ($caja_id !== null) {
            $stmt = $db->prepare("SELECT tipo, sucursal_id FROM cajas WHERE id = ?");
            $stmt->execute([$caja_id]);
            $caja = $stmt->fetch();
            if ($caja) {
                $sucursal_id_uni = (int)($caja['sucursal_id'] ?? 1);
                $depUniRow = $db->prepare("SELECT id FROM depositos WHERE sucursal_id = ? AND es_principal = 1 LIMIT 1");
                $depUniRow->execute([$sucursal_id_uni]);
                $depUniData = $depUniRow->fetch();
                if ($depUniData) $deposito_id_uni = (int)$depUniData['id'];
            }
            if ($caja && $caja['tipo'] === 'venta') {
                $stmt = $db->prepare("SELECT id FROM caja_turnos WHERE caja_id = ? AND estado = 'abierto'");
                $stmt->execute([$caja_id]);
                $turno = $stmt->fetch();
                if (!$turno) json(409, ['error' => 'No hay un turno de caja abierto. Abrí la caja antes de unificar con cobro.']);
                $turno_id = (int)$turno['id'];
            }
        }

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
                    $msRow = $db->prepare("SELECT deposito_id FROM movimientos_stock WHERE referencia_id = ? AND producto_id = ? AND tipo = 'venta' LIMIT 1");
                    $msRow->execute([$v['id'], (int)$item['producto_id']]);
                    $depId = (int)(($msRow->fetch()['deposito_id'] ?? 1));

                    $db->prepare("UPDATE productos SET stock_actual = stock_actual + ? WHERE id = ?")
                       ->execute([(float)$item['cantidad'], (int)$item['producto_id']]);
                    $db->prepare("INSERT INTO stock_depositos (producto_id, deposito_id, stock_actual) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stock_actual = stock_actual + VALUES(stock_actual)")
                       ->execute([(int)$item['producto_id'], $depId, (float)$item['cantidad']]);
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
                    (fecha, cliente_id, total, tipo_comprobante, tipo_pago, estado, observaciones, origen_descripcion,
                     envio_precio, envios_detalle, caja_id, usuario_id, turno_id, sucursal_id)
                VALUES (CURDATE(), ?, ?, ?, ?, 'completado', ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$cliente_id, $total_final, $tipo_final, $tipo_pago, $observaciones, $origen_descripcion,
                         $envio_total > 0 ? $envio_total : null, $envios_detalle,
                         $caja_id, $usuario_id, $turno_id, $sucursal_id_uni]);
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
                $db->prepare("INSERT INTO stock_depositos (producto_id, deposito_id, stock_actual) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stock_actual = stock_actual + VALUES(stock_actual)")
                   ->execute([$item['producto_id'], $deposito_id_uni, -$item['cantidad']]);
                $db->prepare("INSERT INTO movimientos_stock (producto_id, deposito_id, tipo, cantidad, referencia_id, fecha) VALUES (?, ?, 'venta', ?, ?, NOW())")
                   ->execute([$item['producto_id'], $deposito_id_uni, $item['cantidad'], $nueva_id]);
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
                   v.tipo_pago, v.total, v.observaciones, v.cliente_id, v.envio_precio
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
        // Sin el permiso 'anular' (los admin lo tienen implícito), la anulación
        // requiere la clave de autorización configurada por un administrador.
        if (!Auth::puede('anular')) {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $clave = trim($body['clave_autorizacion'] ?? '');
            $hash  = Configuracion::get()['clave_autorizacion_hash'] ?? null;

            if (!$hash) json(403, ['error' => 'No hay clave de autorización configurada. Pedile a un administrador que la configure.']);
            if ($clave === '' || !password_verify($clave, $hash)) {
                json(403, ['error' => 'Clave de autorización incorrecta']);
            }
        }

        $db = DB::get();

        $stmt = $db->prepare("SELECT id, cliente_id, tipo_pago, total, cae, estado FROM ventas WHERE id = ?");
        $stmt->execute([$id]);
        $venta = $stmt->fetch();
        if (!$venta) json(404, ['error' => 'Venta no encontrada']);
        if ($venta['estado'] === 'anulado') json(409, ['error' => 'Esta venta ya está anulada']);

        // Un comprobante con CAE existe ante ARCA: anularlo acá lo sacaría del
        // Libro IVA propio pero seguiría sumando débito fiscal ante el organismo.
        if (!empty($venta['cae'])) {
            json(422, ['error' => 'Este comprobante fue autorizado por ARCA (tiene CAE) y no se puede anular. Emití una Nota de Crédito para revertirlo.']);
        }

        $stmt = $db->prepare("SELECT producto_id, cantidad FROM venta_items WHERE venta_id = ?");
        $stmt->execute([$id]);
        $items = $stmt->fetchAll();

        try {
            $db->beginTransaction();

            // Revertir stock y registrar movimiento de anulación
            foreach ($items as $item) {
                $msRow = $db->prepare("SELECT deposito_id FROM movimientos_stock WHERE referencia_id = ? AND producto_id = ? AND tipo = 'venta' LIMIT 1");
                $msRow->execute([$id, (int)$item['producto_id']]);
                $depId = (int)(($msRow->fetch()['deposito_id'] ?? 1));

                $db->prepare("UPDATE productos SET stock_actual = stock_actual + ? WHERE id = ?")
                   ->execute([(float)$item['cantidad'], (int)$item['producto_id']]);
                $db->prepare("INSERT INTO stock_depositos (producto_id, deposito_id, stock_actual) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE stock_actual = stock_actual + VALUES(stock_actual)")
                   ->execute([(int)$item['producto_id'], $depId, (float)$item['cantidad']]);
                $db->prepare("INSERT INTO movimientos_stock (producto_id, deposito_id, tipo, cantidad, referencia_id, fecha) VALUES (?, ?, 'anulacion', ?, ?, NOW())")
                   ->execute([(int)$item['producto_id'], $depId, (float)$item['cantidad'], $id]);
            }

            // Revertir CC si corresponde
            $pagos_venta = [];
            if ($venta['tipo_pago'] === 'mixto') {
                $stmt = $db->prepare("SELECT tipo_pago AS tipo, monto FROM venta_pagos WHERE venta_id = ?");
                $stmt->execute([$id]);
                $pagos_venta = array_map(fn($p) => ['tipo' => $p['tipo'], 'monto' => (float)$p['monto']], $stmt->fetchAll());
            }
            $monto_cc = $this->montoCC($venta['tipo_pago'], (float)$venta['total'], $pagos_venta);
            if ($monto_cc > 0 && $venta['cliente_id']) {
                $db->prepare("DELETE FROM cuenta_corriente_movimientos WHERE referencia_id = ? AND entidad_tipo = 'cliente'")->execute([$id]);
                $db->prepare("UPDATE clientes SET saldo_cuenta_corriente = saldo_cuenta_corriente - ? WHERE id = ?")->execute([$monto_cc, (int)$venta['cliente_id']]);
            }

            // Marcar la venta como anulada (soft-delete)
            $db->prepare("UPDATE ventas SET estado = 'anulado' WHERE id = ?")->execute([$id]);

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            json(500, ['error' => 'Error al anular: ' . $e->getMessage()]);
        }

        LogAcciones::registrar('anular_venta', 'venta', $id, ['total' => (float)$venta['total'], 'tipo_pago' => $venta['tipo_pago']]);

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
                v.envios_detalle,
                v.envio_direccion,
                v.numero_afip,
                v.cae,
                v.cae_vencimiento,
                v.afip_error,
                v.punto_venta,
                v.cbte_asoc_tipo,
                v.cbte_asoc_pto_vta,
                v.cbte_asoc_nro,
                v.numero_afip_pendiente,
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
        $venta['total']                  = (float)$venta['total'];
        $venta['envio_precio']           = $venta['envio_precio'] !== null ? (float)$venta['envio_precio'] : null;
        $venta['envios_detalle']         = $venta['envios_detalle'] !== null ? json_decode($venta['envios_detalle'], true) : null;
        $venta['punto_venta']            = $venta['punto_venta'] !== null ? (int)$venta['punto_venta'] : null;
        $venta['cbte_asoc_tipo']         = $venta['cbte_asoc_tipo'] !== null ? (int)$venta['cbte_asoc_tipo'] : null;
        $venta['cbte_asoc_pto_vta']      = $venta['cbte_asoc_pto_vta'] !== null ? (int)$venta['cbte_asoc_pto_vta'] : null;
        $venta['cbte_asoc_nro']          = $venta['cbte_asoc_nro'] !== null ? (int)$venta['cbte_asoc_nro'] : null;
        $venta['numero_afip_pendiente']  = $venta['numero_afip_pendiente'] !== null ? (int)$venta['numero_afip_pendiente'] : null;
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

    public function comprobante(int $id): void {
        require_once __DIR__ . '/../helpers/ComprobanteGenerador.php';
        ['pdf' => $pdf, 'venta' => $venta] = ComprobanteGenerador::generarPdf($id);

        // Sin esto el navegador puede servir una copia vieja cacheada del PDF (mismo
        // URL para la misma venta) y esconder cambios de plantilla recién aplicados.
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="comprobante-' . $venta['numero'] . '.pdf"');
        echo $pdf;
        exit;
    }

    public function imprimir(int $id): void {
        require_once __DIR__ . '/../helpers/ComprobanteGenerador.php';
        require_once __DIR__ . '/../helpers/SilentPrint.php';

        ['pdf' => $pdf, 'venta' => $venta, 'config' => $config] = ComprobanteGenerador::generarPdf($id);

        // La copia automática solo se guarda al imprimir de verdad: si "carpeta_comprobantes"
        // está configurada como carpeta vigilada por un servicio de impresión externo, escribir
        // ahí en cada vista/descarga del PDF disparaba una impresión no deseada.
        if (!empty($config['carpeta_comprobantes']) && is_dir($config['carpeta_comprobantes'])) {
            try {
                file_put_contents(
                    rtrim($config['carpeta_comprobantes'], '\\/') . '/comprobante-' . $venta['numero'] . '.pdf',
                    $pdf
                );
            } catch (Throwable $e) {
                // No interrumpe la impresión si falla la copia automática.
            }
        }

        if (empty($config['impresora_nombre'])) {
            json(400, ['error' => 'No hay impresora configurada. Configurala en Configuración.']);
        }

        $tmp = sys_get_temp_dir() . '/logos_comprobante_' . $venta['numero'] . '.pdf';
        file_put_contents($tmp, $pdf);

        $ok = SilentPrint::imprimir($tmp, $config['impresora_nombre']);
        @unlink($tmp);

        if (!$ok) json(500, ['error' => 'No se pudo enviar a imprimir']);
        json(200, ['ok' => true]);
    }

    public function dashboard(): void {
        $fecha_desde = $_GET['desde'] ?? date('Y-m-01');
        $fecha_hasta = $_GET['hasta'] ?? date('Y-m-d');
        $caja_id     = isset($_GET['caja_id']) && is_numeric($_GET['caja_id']) ? (int)$_GET['caja_id'] : null;

        $db = DB::get();
        $where  = ["v.estado = 'completado'", "v.fecha >= ?", "v.fecha <= ?"];
        $params = [$fecha_desde . ' 00:00:00', $fecha_hasta . ' 23:59:59'];
        if ($caja_id) { $where[] = 'v.caja_id = ?'; $params[] = $caja_id; }
        $whereStr = implode(' AND ', $where);

        // Sin el permiso 'costos' el dashboard no expone costo/ganancia/margen:
        // se omiten las queries de costo y los campos correspondientes.
        $veCostos = Auth::puede('costos');

        // Totales de ventas (sin JOIN a items para evitar duplicar v.total)
        $stmtV = $db->prepare("SELECT COUNT(*) AS count_ventas, COALESCE(SUM(v.total), 0) AS total_ventas FROM ventas v WHERE $whereStr");
        $stmtV->execute($params);
        $totalesV = $stmtV->fetch();

        // Costo total desde items
        $totalesC = ['total_costo' => 0];
        if ($veCostos) {
            $stmtC = $db->prepare("SELECT COALESCE(SUM(vi.cantidad * vi.costo_unitario), 0) AS total_costo FROM venta_items vi JOIN ventas v ON v.id = vi.venta_id WHERE $whereStr");
            $stmtC->execute($params);
            $totalesC = $stmtC->fetch();
        }

        $totales = ['count_ventas' => $totalesV['count_ventas'], 'total_ventas' => $totalesV['total_ventas'], 'total_costo' => $totalesC['total_costo']];

        // Por día (ventas separado de costo para no duplicar)
        $stmtVD = $db->prepare("SELECT DATE(v.fecha) AS dia, COUNT(*) AS count_ventas, SUM(v.total) AS total_ventas FROM ventas v WHERE $whereStr GROUP BY DATE(v.fecha) ORDER BY dia ASC");
        $stmtVD->execute($params);
        $ventasPorDia = [];
        foreach ($stmtVD->fetchAll() as $r) {
            $ventasPorDia[$r['dia']] = ['dia' => $r['dia'], 'count_ventas' => (int)$r['count_ventas'], 'total_ventas' => (float)$r['total_ventas'], 'total_costo' => 0.0];
        }
        if ($veCostos) {
            $stmtCD = $db->prepare("SELECT DATE(v.fecha) AS dia, SUM(vi.cantidad * vi.costo_unitario) AS total_costo FROM venta_items vi JOIN ventas v ON v.id = vi.venta_id WHERE $whereStr GROUP BY DATE(v.fecha)");
            $stmtCD->execute($params);
            foreach ($stmtCD->fetchAll() as $r) {
                if (isset($ventasPorDia[$r['dia']])) $ventasPorDia[$r['dia']]['total_costo'] = (float)$r['total_costo'];
            }
        }
        $porDia = array_values($ventasPorDia);

        // Top 10 productos por facturación: en el pulso operativo importa qué
        // se vende; el análisis por ganancia vive en Rentabilidad (ABC).
        $ordenTop = 'SUM(vi.cantidad * vi.precio_unitario) DESC';
        $stmt = $db->prepare("
            SELECT
                p.nombre AS producto_nombre, p.codigo AS producto_codigo,
                SUM(vi.cantidad)                               AS cant_total,
                COALESCE(SUM(vi.cantidad * vi.precio_unitario), 0) AS ventas_total,
                COALESCE(SUM(vi.cantidad * vi.costo_unitario), 0)  AS costo_total
            FROM venta_items vi
            JOIN ventas v   ON v.id  = vi.venta_id
            JOIN productos p ON p.id = vi.producto_id
            WHERE $whereStr
            GROUP BY vi.producto_id, p.nombre, p.codigo
            ORDER BY $ordenTop
            LIMIT 10
        ");
        $stmt->execute($params);
        $topProductos = $stmt->fetchAll();

        // Medios de pago
        $stmtMp = $db->prepare("
            SELECT tipo_pago, COUNT(*) AS cnt, SUM(total) AS total
            FROM ventas v
            WHERE $whereStr AND tipo_pago != 'mixto'
            GROUP BY tipo_pago
        ");
        $stmtMp->execute($params);
        $mediosPago = $stmtMp->fetchAll();

        $tV = (float)$totales['total_ventas'];
        $tC = (float)$totales['total_costo'];
        $ganancia = $tV - $tC;

        $out = [
            'count_ventas'  => (int)$totales['count_ventas'],
            'total_ventas'  => $tV,
            'total_costo'   => $tC,
            'ganancia_bruta'=> $ganancia,
            'margen_pct'    => $tV > 0 ? round($ganancia / $tV * 100, 1) : 0,
            'por_dia'       => array_map(fn($r) => [
                'dia'          => $r['dia'],
                'count_ventas' => (int)$r['count_ventas'],
                'total_ventas' => (float)$r['total_ventas'],
                'total_costo'  => (float)$r['total_costo'],
                'ganancia'     => (float)$r['total_ventas'] - (float)$r['total_costo'],
            ], $porDia),
            'top_productos' => array_map(fn($r) => [
                'nombre'    => $r['producto_nombre'],
                'codigo'    => $r['producto_codigo'],
                'cant'      => (float)$r['cant_total'],
                'ventas'    => (float)$r['ventas_total'],
                'costo'     => (float)$r['costo_total'],
                'ganancia'  => (float)$r['ventas_total'] - (float)$r['costo_total'],
            ], $topProductos),
            'medios_pago' => array_map(fn($r) => [
                'tipo'  => $r['tipo_pago'],
                'cnt'   => (int)$r['cnt'],
                'total' => (float)$r['total'],
            ], $mediosPago),
        ];

        // Un solo día: desglose por hora (para la vista "Hoy" del dashboard)
        if ($fecha_desde === $fecha_hasta) {
            $stmtH = $db->prepare("SELECT HOUR(v.creado_en) AS hora, COUNT(*) AS cnt, COALESCE(SUM(v.total), 0) AS total FROM ventas v WHERE $whereStr GROUP BY hora ORDER BY hora");
            $stmtH->execute($params);
            $out['por_hora'] = array_map(fn($r) => [
                'hora'  => (int)$r['hora'],
                'cnt'   => (int)$r['cnt'],
                'total' => (float)$r['total'],
            ], $stmtH->fetchAll());
        }

        if (!$veCostos) {
            unset($out['total_costo'], $out['ganancia_bruta'], $out['margen_pct']);
            foreach ($out['por_dia'] as &$d)        { unset($d['total_costo'], $d['ganancia']); }
            unset($d);
            foreach ($out['top_productos'] as &$tp) { unset($tp['costo'], $tp['ganancia']); }
            unset($tp);
        }

        json(200, $out);
    }

    public function exportar(): void {
        $fecha_desde      = $_GET['fecha_desde']      ?? '';
        $fecha_hasta      = $_GET['fecha_hasta']       ?? '';
        $tipo_comprobante = trim($_GET['tipo']         ?? '');
        $cliente_id       = isset($_GET['cliente_id']) && is_numeric($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : null;
        $caja_id          = isset($_GET['caja_id'])    && is_numeric($_GET['caja_id'])    ? (int)$_GET['caja_id']    : null;
        $mostrar_anuladas = !empty($_GET['mostrar_anuladas']);

        $where  = ['1=1'];
        $params = [];
        if (!$mostrar_anuladas)  { $where[] = "v.estado != 'anulado'"; }
        if ($fecha_desde)        { $where[] = 'v.fecha >= ?';           $params[] = $fecha_desde; }
        if ($fecha_hasta)        { $where[] = 'v.fecha <= ?';           $params[] = $fecha_hasta; }
        if ($tipo_comprobante)   { $where[] = 'v.tipo_comprobante = ?'; $params[] = $tipo_comprobante; }
        if ($cliente_id)         { $where[] = 'v.cliente_id = ?';       $params[] = $cliente_id; }
        if ($caja_id)            { $where[] = 'v.caja_id = ?';          $params[] = $caja_id; }

        $stmt = DB::get()->prepare("
            SELECT v.id, LPAD(v.id,8,'0') AS numero, v.fecha, v.tipo_comprobante, v.tipo_pago,
                   v.total, v.estado, v.cae,
                   c.nombre AS cliente_nombre, cj.nombre AS caja_nombre, v.observaciones
            FROM ventas v
            LEFT JOIN clientes c  ON c.id  = v.cliente_id
            LEFT JOIN cajas cj    ON cj.id = v.caja_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY v.fecha DESC, v.id DESC
            LIMIT 5000
        ");
        $stmt->execute($params);
        $ventas = $stmt->fetchAll();

        // Obtener items de todas las ventas
        $ids = array_column($ventas, 'id');
        $itemsPorVenta = [];
        if ($ids) {
            $in   = implode(',', array_fill(0, count($ids), '?'));
            $stmtI = DB::get()->prepare("SELECT vi.venta_id, p.nombre AS producto_nombre, p.codigo AS producto_codigo, vi.cantidad, vi.precio_unitario, (vi.cantidad * vi.precio_unitario) AS subtotal FROM venta_items vi LEFT JOIN productos p ON p.id = vi.producto_id WHERE vi.venta_id IN ($in) ORDER BY vi.venta_id, vi.id");
            $stmtI->execute($ids);
            foreach ($stmtI->fetchAll() as $item) {
                $itemsPorVenta[$item['venta_id']][] = $item;
            }
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Ventas');

        // Encabezados
        $headers = ['N°', 'Fecha', 'Tipo', 'Cliente', 'Caja', 'Forma de pago', 'Total', 'Estado', 'CAE', 'Observaciones', 'Productos'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:K1')->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ]);

        // Datos
        $row = 2;
        foreach ($ventas as $v) {
            $items = $itemsPorVenta[$v['id']] ?? [];
            $productosStr = implode('; ', array_map(fn($it) => ($it['producto_nombre'] ?? '') . ' x' . (float)$it['cantidad'], $items));
            $sheet->fromArray([
                $v['numero'],
                $v['fecha'],
                $v['tipo_comprobante'] ?? '',
                $v['cliente_nombre']   ?? 'Consumidor final',
                $v['caja_nombre']      ?? '',
                $v['tipo_pago']        ?? '',
                (float)$v['total'],
                $v['estado']           ?? '',
                $v['cae']              ?? '',
                $v['observaciones']    ?? '',
                $productosStr,
            ], null, 'A' . $row);
            $row++;
        }

        // Auto-width
        foreach (range('A', 'K') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $desde = $fecha_desde ?: 'inicio';
        $hasta = $fecha_hasta ?: 'hoy';
        $nombre = "ventas_{$desde}_{$hasta}.xlsx";

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Cache-Control: max-age=0');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}
