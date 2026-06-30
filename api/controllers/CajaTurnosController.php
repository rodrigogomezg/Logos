<?php

class CajaTurnosController {

    private function caja(int $caja_id): array {
        $stmt = DB::get()->prepare("SELECT id, nombre, tipo FROM cajas WHERE id = ?");
        $stmt->execute([$caja_id]);
        $caja = $stmt->fetch();
        if (!$caja) json(404, ['error' => 'Caja no encontrada']);
        return $caja;
    }

    private function breakdown(int $turno_id): array {
        $db = DB::get();

        $totales = array_fill_keys(['efectivo', 'transferencia', 'cc', 'tarjeta', 'cheque'], 0.0);
        $stmt = $db->prepare("SELECT tipo_pago, SUM(total) AS total FROM ventas WHERE turno_id = ? AND estado = 'completado' GROUP BY tipo_pago");
        $stmt->execute([$turno_id]);
        foreach ($stmt->fetchAll() as $fila) {
            if (array_key_exists($fila['tipo_pago'], $totales)) {
                $totales[$fila['tipo_pago']] = (float)$fila['total'];
            }
        }

        $netoMovs = array_fill_keys(['efectivo', 'transferencia', 'tarjeta'], 0.0);
        $ingresos = 0.0;
        $retiros  = 0.0;

        $stmt = $db->prepare("SELECT tipo, medio_pago, medio_pago_destino, monto FROM caja_movimientos WHERE turno_id = ?");
        $stmt->execute([$turno_id]);
        foreach ($stmt->fetchAll() as $fila) {
            $monto = (float)$fila['monto'];
            if ($fila['tipo'] === 'ingreso') {
                $ingresos += $monto;
                $netoMovs[$fila['medio_pago']] += $monto;
            } elseif ($fila['tipo'] === 'retiro') {
                $retiros += $monto;
                $netoMovs[$fila['medio_pago']] -= $monto;
            } elseif ($fila['tipo'] === 'transferencia') {
                $netoMovs[$fila['medio_pago']] -= $monto;
                $netoMovs[$fila['medio_pago_destino']] += $monto;
            }
        }

        return [
            'total_efectivo'      => $totales['efectivo'] + $netoMovs['efectivo'],
            'total_tarjeta'       => $totales['tarjeta'] + $netoMovs['tarjeta'],
            'total_transferencia' => $totales['transferencia'] + $netoMovs['transferencia'],
            'total_cheque'        => $totales['cheque'],
            'total_cc'            => $totales['cc'],
            'total_ingresos'      => $ingresos,
            'total_retiros'       => $retiros,
        ];
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
            $stmt = DB::get()->prepare("SELECT efectivo_contado FROM caja_turnos WHERE caja_id = ? AND estado = 'cerrado' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$caja_id]);
            $ultimo = $stmt->fetch();
            json(200, ['turno' => null, 'fondo_sugerido' => $ultimo ? (float)$ultimo['efectivo_contado'] : 0]);
        }

        json(200, array_merge(['turno' => $turno], $this->breakdown((int)$turno['id'])));
    }

    public function abrir(): void {
        $body          = json_decode(file_get_contents('php://input'), true) ?: [];
        $caja_id       = isset($body['caja_id']) && is_numeric($body['caja_id']) ? (int)$body['caja_id'] : null;
        $fondo_inicial = isset($body['fondo_inicial']) && is_numeric($body['fondo_inicial']) ? (float)$body['fondo_inicial'] : null;

        if (!$caja_id) json(400, ['error' => 'caja_id requerido']);
        if ($fondo_inicial === null || $fondo_inicial < 0) json(400, ['error' => 'fondo_inicial inválido']);

        $caja = $this->caja($caja_id);
        if ($caja['tipo'] !== 'venta') json(400, ['error' => 'Esta caja no requiere apertura de turno']);

        $usuario = Auth::usuarioActual();
        if (!$usuario) json(401, ['error' => 'Usuario no identificado']);

        $stmt = DB::get()->prepare("SELECT id FROM caja_turnos WHERE caja_id = ? AND estado = 'abierto'");
        $stmt->execute([$caja_id]);
        if ($stmt->fetch()) json(409, ['error' => 'Ya hay un turno abierto en esta caja']);

        $stmt = DB::get()->prepare("
            INSERT INTO caja_turnos (caja_id, usuario_id, fondo_inicial, abierto_en, estado)
            VALUES (?, ?, ?, NOW(), 'abierto')
        ");
        $stmt->execute([$caja_id, $usuario['id'], $fondo_inicial]);

        $this->get((int)DB::get()->lastInsertId());
    }

    public function cerrar(int $id): void {
        $body             = json_decode(file_get_contents('php://input'), true) ?: [];
        $efectivo_contado = isset($body['efectivo_contado']) && is_numeric($body['efectivo_contado']) ? (float)$body['efectivo_contado'] : null;

        if ($efectivo_contado === null || $efectivo_contado < 0) json(400, ['error' => 'efectivo_contado inválido']);

        $stmt = DB::get()->prepare("SELECT * FROM caja_turnos WHERE id = ?");
        $stmt->execute([$id]);
        $turno = $stmt->fetch();
        if (!$turno) json(404, ['error' => 'Turno no encontrado']);
        if ($turno['estado'] === 'cerrado') json(409, ['error' => 'El turno ya está cerrado']);

        $b = $this->breakdown($id);
        $efectivo_esperado = (float)$turno['fondo_inicial'] + $b['total_efectivo'];
        $diferencia = $efectivo_contado - $efectivo_esperado;

        $stmt = DB::get()->prepare("
            UPDATE caja_turnos SET
                estado = 'cerrado', cerrado_en = NOW(),
                total_efectivo = ?, total_tarjeta = ?, total_transferencia = ?, total_cheque = ?, total_cc = ?,
                total_ingresos = ?, total_retiros = ?,
                efectivo_esperado = ?, efectivo_contado = ?, diferencia = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $b['total_efectivo'], $b['total_tarjeta'], $b['total_transferencia'], $b['total_cheque'], $b['total_cc'],
            $b['total_ingresos'], $b['total_retiros'],
            $efectivo_esperado, $efectivo_contado, $diferencia,
            $id,
        ]);

        $this->get($id);
    }

    public function eliminar(int $id): void {
        Auth::requireAdmin();

        $stmt = DB::get()->prepare("SELECT id, estado FROM caja_turnos WHERE id = ?");
        $stmt->execute([$id]);
        $turno = $stmt->fetch();
        if (!$turno) json(404, ['error' => 'Turno no encontrado']);

        if ($turno['estado'] !== 'cerrado') {
            json(400, ['error' => 'Solo se pueden eliminar cierres (turnos cerrados)']);
        }

        $stmt = DB::get()->prepare("SELECT COUNT(*) FROM ventas WHERE turno_id = ?");
        $stmt->execute([$id]);
        $ventas = (int)$stmt->fetchColumn();

        $stmt = DB::get()->prepare("SELECT COUNT(*) FROM caja_movimientos WHERE turno_id = ?");
        $stmt->execute([$id]);
        $movimientos = (int)$stmt->fetchColumn();

        if ($ventas > 0 || $movimientos > 0) {
            json(409, ['error' => 'Este cierre tiene ventas o movimientos asociados y no se puede eliminar']);
        }

        DB::get()->prepare("DELETE FROM caja_turnos WHERE id = ?")->execute([$id]);
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
        $caja_id = isset($_GET['caja_id']) && is_numeric($_GET['caja_id']) ? (int)$_GET['caja_id'] : null;
        if (!Auth::esAdmin() && !$caja_id) {
            json(400, ['error' => 'caja_id requerido']);
        }

        $where  = [];
        $params = [];
        if ($caja_id)               { $where[] = 't.caja_id = ?';      $params[] = $caja_id; }
        if (!empty($_GET['desde'])) { $where[] = 't.abierto_en >= ?';  $params[] = $_GET['desde'] . ' 00:00:00'; }
        if (!empty($_GET['hasta'])) { $where[] = 't.abierto_en <= ?';  $params[] = $_GET['hasta'] . ' 23:59:59'; }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = DB::get()->prepare("
            SELECT t.*, c.nombre AS caja_nombre, u.nombre AS usuario_nombre
            FROM caja_turnos t
            JOIN cajas c     ON c.id = t.caja_id
            JOIN usuarios u  ON u.id = t.usuario_id
            $whereSql
            ORDER BY t.id DESC
        ");
        $stmt->execute($params);
        json(200, $stmt->fetchAll());
    }
}
