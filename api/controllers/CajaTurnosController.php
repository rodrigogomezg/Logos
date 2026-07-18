<?php

require_once __DIR__ . '/../helpers/Configuracion.php';
require_once __DIR__ . '/../helpers/LogAcciones.php';

class CajaTurnosController {

    private function caja(int $caja_id): array {
        $stmt = DB::get()->prepare("SELECT id, nombre, tipo, sucursal_id FROM cajas WHERE id = ?");
        $stmt->execute([$caja_id]);
        $caja = $stmt->fetch();
        if (!$caja) json(404, ['error' => 'Caja no encontrada']);
        return $caja;
    }

    /**
     * Totales de un turno, opcionalmente filtrado por período.
     * $desde_dt: si se pasa, solo incluye ventas y movimientos a partir de esa fecha.
     */
    private function breakdown(int $turno_id, ?string $desde_dt = null): array {
        $db = DB::get();

        $keys    = ['efectivo', 'transferencia', 'cc', 'tarjeta', 'cheque', 'mercado_pago'];
        $totales = array_fill_keys($keys, 0.0);
        $counts  = array_fill_keys($keys, 0);

        $filtroFecha = $desde_dt ? "AND v.fecha >= ?" : "";
        $params      = $desde_dt ? [$turno_id, $desde_dt] : [$turno_id];

        // Ventas simples — totales y conteos
        $stmt = $db->prepare("
            SELECT v.tipo_pago, SUM(v.total) AS total, COUNT(*) AS cnt
            FROM ventas v
            WHERE v.turno_id = ? AND v.estado = 'completado' AND v.tipo_pago != 'mixto'
            $filtroFecha
            GROUP BY v.tipo_pago
        ");
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $fila) {
            $k = $fila['tipo_pago'];
            if (array_key_exists($k, $totales)) {
                $totales[$k] = (float)$fila['total'];
                $counts[$k]  = (int)$fila['cnt'];
            }
        }

        // Ventas mixtas — desglosar montos por venta_pagos
        $filtroFechaMixto = $desde_dt ? "AND v.fecha >= ?" : "";
        $paramsMixto      = $desde_dt ? [$turno_id, $desde_dt] : [$turno_id];
        $stmt = $db->prepare("
            SELECT vp.tipo_pago, SUM(vp.monto) AS total
            FROM venta_pagos vp
            JOIN ventas v ON v.id = vp.venta_id
            WHERE v.turno_id = ? AND v.estado = 'completado' AND v.tipo_pago = 'mixto'
            $filtroFechaMixto
            GROUP BY vp.tipo_pago
        ");
        $stmt->execute($paramsMixto);
        foreach ($stmt->fetchAll() as $fila) {
            $k = $fila['tipo_pago'];
            if (array_key_exists($k, $totales)) {
                $totales[$k] += (float)$fila['total'];
            }
        }

        // Contar ventas mixtas (una vez cada una)
        $stmt = $db->prepare("
            SELECT COUNT(*) AS cnt
            FROM ventas v
            WHERE v.turno_id = ? AND v.estado = 'completado' AND v.tipo_pago = 'mixto'
            $filtroFecha
        ");
        $stmt->execute($params);
        $countMixto = (int)$stmt->fetchColumn();

        // Devoluciones / anulaciones (future-proof)
        $stmt = $db->prepare("
            SELECT COUNT(*) AS cnt, COALESCE(SUM(total), 0) AS total
            FROM ventas v
            WHERE v.turno_id = ? AND v.estado = 'anulado'
            $filtroFecha
        ");
        $stmt->execute($params);
        $devRow = $stmt->fetch();

        // Total de ventas completadas
        $countTotal = array_sum($counts) + $countMixto;

        // Movimientos de caja (ingresos, retiros, transferencias entre medios)
        $netoMovs = array_fill_keys(['efectivo', 'transferencia', 'tarjeta'], 0.0);
        $ingresos = 0.0;
        $retiros  = 0.0;

        $filtroMovs = $desde_dt ? "AND creado_en >= ?" : "";
        $paramsMovs = $desde_dt ? [$turno_id, $desde_dt] : [$turno_id];

        $stmt = $db->prepare("
            SELECT tipo, medio_pago, medio_pago_destino, monto
            FROM caja_movimientos
            WHERE turno_id = ?
            $filtroMovs
        ");
        $stmt->execute($paramsMovs);
        foreach ($stmt->fetchAll() as $fila) {
            $monto = (float)$fila['monto'];
            if ($fila['tipo'] === 'ingreso') {
                $ingresos += $monto;
                $netoMovs[$fila['medio_pago']] += $monto;
            } elseif ($fila['tipo'] === 'retiro') {
                $retiros += $monto;
                $netoMovs[$fila['medio_pago']] -= $monto;
            } elseif ($fila['tipo'] === 'transferencia') {
                $netoMovs[$fila['medio_pago']]         -= $monto;
                $netoMovs[$fila['medio_pago_destino']] += $monto;
            }
        }

        return [
            // Totales netos (ventas + movimientos): guían el arqueo del cajón
            'total_efectivo'       => $totales['efectivo'] + $netoMovs['efectivo'],
            'total_tarjeta'        => $totales['tarjeta']  + $netoMovs['tarjeta'],
            'total_transferencia'  => $totales['transferencia'] + $netoMovs['transferencia'],
            // Ventas puras por medio (sin movimientos): cómo pagaron los clientes
            'ventas_efectivo'      => $totales['efectivo'],
            'ventas_tarjeta'       => $totales['tarjeta'],
            'ventas_transferencia' => $totales['transferencia'],
            'ventas_cheque'        => $totales['cheque'],
            'ventas_mercado_pago'  => $totales['mercado_pago'],
            'ventas_cc'            => $totales['cc'],
            'total_cheque'         => $totales['cheque'],
            'total_mercado_pago'   => $totales['mercado_pago'],
            'total_cc'             => $totales['cc'],
            'total_ingresos'       => $ingresos,
            'total_retiros'        => $retiros,
            // Conteos
            'count_efectivo'       => $counts['efectivo'],
            'count_tarjeta'        => $counts['tarjeta'],
            'count_transferencia'  => $counts['transferencia'],
            'count_cheque'         => $counts['cheque'],
            'count_mercado_pago'   => $counts['mercado_pago'],
            'count_cc'             => $counts['cc'],
            'count_mixto'          => $countMixto,
            'count_total'          => $countTotal,
            // Devoluciones
            'count_devoluciones'   => (int)$devRow['cnt'],
            'total_devoluciones'   => (float)$devRow['total'],
        ];
    }

    /** Último cierre parcial de un turno (para calcular el fondo del siguiente período). */
    private function ultimoCierre(int $turno_id): ?array {
        $stmt = DB::get()->prepare("
            SELECT * FROM caja_cierres
            WHERE turno_id = ? AND tipo = 'parcial'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$turno_id]);
        return $stmt->fetch() ?: null;
    }

    public function actual(): void {
        $caja_id = isset($_GET['caja_id']) && is_numeric($_GET['caja_id']) ? (int)$_GET['caja_id'] : null;
        if (!$caja_id) json(400, ['error' => 'caja_id requerido']);

        $caja = $this->caja($caja_id);
        if ($caja['tipo'] !== 'venta') {
            json(200, ['turno' => null, 'no_aplica' => true]);
        }

        $stmt = DB::get()->prepare("SELECT * FROM caja_turnos WHERE caja_id = ? AND estado = 'abierto' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$caja_id]);
        $turno = $stmt->fetch();

        if (!$turno) {
            // Fondo sugerido = fondo_siguiente del último cierre (o efectivo_contado si no se definió)
            $stmt = DB::get()->prepare("
                SELECT COALESCE(fondo_siguiente, efectivo_contado, 0)
                FROM caja_turnos
                WHERE caja_id = ? AND estado = 'cerrado'
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$caja_id]);
            $fondoSugerido = (float)($stmt->fetchColumn() ?: 0);
            json(200, ['turno' => null, 'fondo_sugerido' => $fondoSugerido]);
        }

        $b = $this->breakdown((int)$turno['id']);

        $stmt = DB::get()->prepare("
            SELECT cc.*, u.nombre AS usuario_nombre
            FROM caja_cierres cc
            LEFT JOIN usuarios u ON u.id = cc.usuario_id
            WHERE cc.turno_id = ?
            ORDER BY cc.id ASC
        ");
        $stmt->execute([(int)$turno['id']]);
        $cierres = $stmt->fetchAll();

        // Ventas agrupadas por hora (para el gráfico de ritmo del turno)
        $stmt = DB::get()->prepare("
            SELECT DATE_FORMAT(v.creado_en, '%Y-%m-%d %H') AS hora, COUNT(*) AS cnt, SUM(v.total) AS total
            FROM ventas v
            WHERE v.turno_id = ? AND v.estado = 'completado'
            GROUP BY hora
            ORDER BY hora
        ");
        $stmt->execute([(int)$turno['id']]);
        $ventasPorHora = $stmt->fetchAll();

        $stmt = DB::get()->prepare("
            SELECT MIN(creado_en) AS primera, MAX(creado_en) AS ultima
            FROM ventas
            WHERE turno_id = ? AND estado = 'completado'
        ");
        $stmt->execute([(int)$turno['id']]);
        $rangoVentas = $stmt->fetch();

        json(200, array_merge([
            'turno'             => $turno,
            'cierres_parciales' => $cierres,
            'ventas_por_hora'   => $ventasPorHora,
            'primera_venta'     => $rangoVentas['primera'] ?? null,
            'ultima_venta'      => $rangoVentas['ultima'] ?? null,
        ], $b));
    }

    /** GET /caja-turnos/{id}/periodo — datos del período actual para el modal de cierre */
    public function periodo(int $id): void {
        $stmt = DB::get()->prepare("SELECT * FROM caja_turnos WHERE id = ?");
        $stmt->execute([$id]);
        $turno = $stmt->fetch();
        if (!$turno) json(404, ['error' => 'Turno no encontrado']);
        if ($turno['estado'] === 'cerrado') json(409, ['error' => 'El turno ya está cerrado']);

        $ultimo        = $this->ultimoCierre($id);
        $periodo_desde = $ultimo ? $ultimo['registrado_en'] : $turno['abierto_en'];
        // fondo_siguiente del cierre parcial anterior, o fondo_inicial si es el primero
        $fondo_periodo = $ultimo
            ? (float)($ultimo['fondo_siguiente'] ?? $ultimo['efectivo_contado'])
            : (float)$turno['fondo_inicial'];

        $b                 = $this->breakdown($id, $periodo_desde);
        $efectivo_esperado = $fondo_periodo + $b['total_efectivo'];

        json(200, array_merge($b, [
            'turno_id'          => (int)$id,
            'periodo_desde'     => $periodo_desde,
            'fondo_periodo'     => $fondo_periodo,
            'efectivo_esperado' => $efectivo_esperado,
        ]));
    }

    public function abrir(): void {
        $body          = json_decode(file_get_contents('php://input'), true) ?: [];
        $caja_id       = isset($body['caja_id']) && is_numeric($body['caja_id']) ? (int)$body['caja_id'] : null;
        $fondo_inicial = isset($body['fondo_inicial']) && is_numeric($body['fondo_inicial']) ? (float)$body['fondo_inicial'] : null;

        if (!$caja_id) json(400, ['error' => 'caja_id requerido']);
        if ($fondo_inicial === null || $fondo_inicial < 0) json(400, ['error' => 'fondo_inicial inválido']);

        $caja = $this->caja($caja_id);
        if ($caja['tipo'] !== 'venta') json(400, ['error' => 'Esta caja no requiere apertura de turno']);

        // Verificar que el usuario tiene acceso a la sucursal de esta caja
        if (!empty($caja['sucursal_id']) && !Auth::sucursalPermitida((int)$caja['sucursal_id'])) {
            json(403, ['error' => 'No tenés acceso a esta sucursal']);
        }

        $usuario     = Auth::usuarioActual();
        if (!$usuario) json(401, ['error' => 'Usuario no identificado']);
        $device_name = trim($_SERVER['HTTP_X_DEVICE_NAME'] ?? '') ?: null;

        $stmt = DB::get()->prepare("SELECT id FROM caja_turnos WHERE caja_id = ? AND estado = 'abierto'");
        $stmt->execute([$caja_id]);
        if ($stmt->fetch()) json(409, ['error' => 'Ya hay un turno abierto en esta caja']);

        $stmt = DB::get()->prepare("
            INSERT INTO caja_turnos (caja_id, usuario_id, device_name, fondo_inicial, abierto_en, estado)
            VALUES (?, ?, ?, ?, NOW(), 'abierto')
        ");
        $stmt->execute([$caja_id, $usuario['id'], $device_name, $fondo_inicial]);

        $this->get((int)DB::get()->lastInsertId());
    }

    /** POST /caja-turnos/{id}/cierre-parcial */
    public function cierreParcial(int $id): void {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];

        $efectivo_contado    = isset($body['efectivo_contado'])    && is_numeric($body['efectivo_contado'])    ? (float)$body['efectivo_contado']    : null;
        $cheques_recibidos   = isset($body['cheques_recibidos'])   && is_numeric($body['cheques_recibidos'])   ? (float)$body['cheques_recibidos']   : null;
        $depositos_recibidos = isset($body['depositos_recibidos']) && is_numeric($body['depositos_recibidos']) ? (float)$body['depositos_recibidos'] : null;
        $posnet_cierres      = isset($body['posnet_cierres'])      && is_array($body['posnet_cierres'])        ? $body['posnet_cierres']              : null;
        $mercado_pago_contado = isset($body['mercado_pago_contado']) && is_numeric($body['mercado_pago_contado']) ? (float)$body['mercado_pago_contado'] : null;
        $fondo_siguiente     = isset($body['fondo_siguiente'])     && is_numeric($body['fondo_siguiente'])     ? (float)$body['fondo_siguiente']     : null;
        $observaciones       = isset($body['observaciones'])       && trim($body['observaciones']) !== ''       ? trim($body['observaciones'])        : null;

        if ($efectivo_contado === null || $efectivo_contado < 0) json(400, ['error' => 'efectivo_contado inválido']);

        $stmt = DB::get()->prepare("SELECT * FROM caja_turnos WHERE id = ?");
        $stmt->execute([$id]);
        $turno = $stmt->fetch();
        if (!$turno) json(404, ['error' => 'Turno no encontrado']);
        if ($turno['estado'] === 'cerrado') json(409, ['error' => 'El turno ya está cerrado']);

        $usuario       = Auth::usuarioActual();
        $ultimo        = $this->ultimoCierre($id);
        $periodo_desde = $ultimo ? $ultimo['registrado_en'] : $turno['abierto_en'];
        $fondo_periodo = $ultimo
            ? (float)($ultimo['fondo_siguiente'] ?? $ultimo['efectivo_contado'])
            : (float)$turno['fondo_inicial'];

        $b                 = $this->breakdown($id, $periodo_desde);
        $efectivo_esperado = $fondo_periodo + $b['total_efectivo'];
        $diferencia        = $efectivo_contado - $efectivo_esperado;

        // Si no se especifica fondo_siguiente, queda como efectivo_contado
        if ($fondo_siguiente === null) $fondo_siguiente = $efectivo_contado;

        DB::get()->prepare("
            INSERT INTO caja_cierres
                (turno_id, tipo, registrado_en, usuario_id, periodo_desde,
                 total_efectivo, total_tarjeta, total_transferencia, total_cheque,
                 total_mercado_pago, total_cc, total_ingresos, total_retiros,
                 fondo_periodo, efectivo_esperado, efectivo_contado,
                 cheques_recibidos, depositos_recibidos, posnet_cierres,
                 mercado_pago_contado, fondo_siguiente, observaciones,
                 diferencia_efectivo)
            VALUES (?, 'parcial', NOW(), ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?)
        ")->execute([
            $id, $usuario['id'] ?? null, $periodo_desde,
            $b['total_efectivo'], $b['total_tarjeta'], $b['total_transferencia'], $b['total_cheque'],
            $b['total_mercado_pago'], $b['total_cc'], $b['total_ingresos'], $b['total_retiros'],
            $fondo_periodo, $efectivo_esperado, $efectivo_contado,
            $cheques_recibidos, $depositos_recibidos,
            $posnet_cierres !== null ? json_encode($posnet_cierres) : null,
            $mercado_pago_contado, $fondo_siguiente, $observaciones,
            $diferencia,
        ]);

        LogAcciones::registrar('cierre_parcial', 'turno', $id, ['diferencia' => $diferencia, 'efectivo_contado' => $efectivo_contado]);

        json(200, ['ok' => true, 'diferencia' => $diferencia]);
    }

    /** POST /caja-turnos/{id}/cerrar — cierre total Z */
    public function cerrar(int $id): void {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];

        $efectivo_contado    = isset($body['efectivo_contado'])    && is_numeric($body['efectivo_contado'])    ? (float)$body['efectivo_contado']    : null;
        $cheques_recibidos   = isset($body['cheques_recibidos'])   && is_numeric($body['cheques_recibidos'])   ? (float)$body['cheques_recibidos']   : null;
        $depositos_recibidos = isset($body['depositos_recibidos']) && is_numeric($body['depositos_recibidos']) ? (float)$body['depositos_recibidos'] : null;
        $posnet_cierres      = isset($body['posnet_cierres'])      && is_array($body['posnet_cierres'])        ? $body['posnet_cierres']              : null;
        $mercado_pago_contado = isset($body['mercado_pago_contado']) && is_numeric($body['mercado_pago_contado']) ? (float)$body['mercado_pago_contado'] : null;
        $fondo_siguiente     = isset($body['fondo_siguiente'])     && is_numeric($body['fondo_siguiente'])     ? (float)$body['fondo_siguiente']     : null;
        $observaciones       = isset($body['observaciones'])       && trim($body['observaciones']) !== ''       ? trim($body['observaciones'])        : null;

        if ($efectivo_contado === null || $efectivo_contado < 0) json(400, ['error' => 'efectivo_contado inválido']);

        $stmt = DB::get()->prepare("SELECT * FROM caja_turnos WHERE id = ?");
        $stmt->execute([$id]);
        $turno = $stmt->fetch();
        if (!$turno) json(404, ['error' => 'Turno no encontrado']);
        if ($turno['estado'] === 'cerrado') json(409, ['error' => 'El turno ya está cerrado']);

        $b                 = $this->breakdown($id);
        $efectivo_esperado = (float)$turno['fondo_inicial'] + $b['total_efectivo'];
        $diferencia        = $efectivo_contado - $efectivo_esperado;

        if ($fondo_siguiente === null) $fondo_siguiente = $efectivo_contado;

        DB::get()->prepare("
            UPDATE caja_turnos SET
                estado = 'cerrado', cerrado_en = NOW(),
                total_efectivo = ?, total_tarjeta = ?, total_transferencia = ?,
                total_cheque = ?, total_mercado_pago = ?, total_cc = ?,
                total_ingresos = ?, total_retiros = ?,
                efectivo_esperado = ?, efectivo_contado = ?, diferencia = ?,
                cheques_recibidos = ?, depositos_recibidos = ?,
                posnet_cierres = ?, mercado_pago_contado = ?,
                fondo_siguiente = ?, observaciones = ?
            WHERE id = ?
        ")->execute([
            $b['total_efectivo'], $b['total_tarjeta'], $b['total_transferencia'],
            $b['total_cheque'], $b['total_mercado_pago'], $b['total_cc'],
            $b['total_ingresos'], $b['total_retiros'],
            $efectivo_esperado, $efectivo_contado, $diferencia,
            $cheques_recibidos, $depositos_recibidos,
            $posnet_cierres !== null ? json_encode($posnet_cierres) : null,
            $mercado_pago_contado, $fondo_siguiente, $observaciones,
            $id,
        ]);

        LogAcciones::registrar('cierre_caja', 'turno', $id, ['diferencia' => $diferencia, 'efectivo_contado' => $efectivo_contado]);

        // Backup automático si está configurado
        $cfg = Configuracion::get();
        if (!empty($cfg['backup_auto_cierre'])) {
            try { Configuracion::ejecutarBackup(); } catch (\Throwable $e) { /* no bloquear el cierre si el backup falla */ }
        }

        $this->get($id);
    }

    public function eliminar(int $id): void {
        Auth::requireAdmin();

        $stmt = DB::get()->prepare("SELECT id, estado FROM caja_turnos WHERE id = ?");
        $stmt->execute([$id]);
        $turno = $stmt->fetch();
        if (!$turno) json(404, ['error' => 'Turno no encontrado']);
        if ($turno['estado'] !== 'cerrado') json(400, ['error' => 'Solo se pueden eliminar cierres (turnos cerrados)']);

        $stmt = DB::get()->prepare("SELECT COUNT(*) FROM ventas WHERE turno_id = ?");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) json(409, ['error' => 'Este cierre tiene ventas asociadas y no se puede eliminar']);

        $stmt = DB::get()->prepare("SELECT COUNT(*) FROM caja_movimientos WHERE turno_id = ?");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) json(409, ['error' => 'Este cierre tiene movimientos asociados y no se puede eliminar']);

        $db = DB::get();
        $db->prepare("DELETE FROM caja_cierres WHERE turno_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM caja_turnos WHERE id = ?")->execute([$id]);
        json(200, ['ok' => true]);
    }

    public function get(int $id): void {
        $stmt = DB::get()->prepare("
            SELECT t.*, c.nombre AS caja_nombre, u.nombre AS usuario_nombre
            FROM caja_turnos t
            JOIN cajas c     ON c.id = t.caja_id
            JOIN usuarios u  ON u.id = t.usuario_id
            WHERE t.id = ?
        ");
        $stmt->execute([$id]);
        $turno = $stmt->fetch();
        if (!$turno) json(404, ['error' => 'Turno no encontrado']);
        if ($turno['estado'] === 'abierto') {
            $turno = array_merge($turno, $this->breakdown($id));
        }
        json(200, $turno);
    }

    public function listar(): void {
        $caja_id     = isset($_GET['caja_id'])     && is_numeric($_GET['caja_id'])     ? (int)$_GET['caja_id']     : null;
        $sucursal_id = isset($_GET['sucursal_id']) && is_numeric($_GET['sucursal_id']) ? (int)$_GET['sucursal_id'] : null;
        if (!Auth::puede('cajas_todas') && !$caja_id) json(400, ['error' => 'caja_id requerido']);

        $where  = [];
        $params = [];
        if ($caja_id)               { $where[] = 't.caja_id = ?';       $params[] = $caja_id;     }
        if ($sucursal_id)           { $where[] = 'c.sucursal_id = ?';   $params[] = $sucursal_id; }
        if (!empty($_GET['desde'])) { $where[] = 't.abierto_en >= ?';   $params[] = $_GET['desde'] . ' 00:00:00'; }
        if (!empty($_GET['hasta'])) { $where[] = 't.abierto_en <= ?';   $params[] = $_GET['hasta'] . ' 23:59:59'; }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = DB::get()->prepare("
            SELECT t.*, c.nombre AS caja_nombre, c.sucursal_id, s.nombre AS sucursal_nombre, u.nombre AS usuario_nombre
            FROM caja_turnos t
            JOIN cajas      c ON c.id = t.caja_id
            LEFT JOIN sucursales s ON s.id = c.sucursal_id
            JOIN usuarios   u ON u.id = t.usuario_id
            $whereSql
            ORDER BY t.id DESC
        ");
        $stmt->execute($params);
        json(200, $stmt->fetchAll());
    }
}
