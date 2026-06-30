<?php

class CajaMovimientosController {

    private function turnoAbierto(int $caja_id): array {
        $stmt = DB::get()->prepare("SELECT id FROM caja_turnos WHERE caja_id = ? AND estado = 'abierto'");
        $stmt->execute([$caja_id]);
        $turno = $stmt->fetch();
        if (!$turno) json(409, ['error' => 'No hay un turno abierto en esta caja']);
        return $turno;
    }

    public function crear(): void {
        $body               = json_decode(file_get_contents('php://input'), true) ?: [];
        $caja_id            = isset($body['caja_id']) && is_numeric($body['caja_id']) ? (int)$body['caja_id'] : null;
        $tipo               = $body['tipo'] ?? null;
        $medio_pago         = $body['medio_pago'] ?? null;
        $medio_pago_destino = $body['medio_pago_destino'] ?? null;
        $monto              = isset($body['monto']) && is_numeric($body['monto']) ? (float)$body['monto'] : null;
        $motivo             = isset($body['motivo']) && trim($body['motivo']) !== '' ? trim($body['motivo']) : null;

        $mediosValidos = ['efectivo', 'transferencia', 'tarjeta'];

        if (!$caja_id) json(400, ['error' => 'caja_id requerido']);
        if (!in_array($tipo, ['ingreso', 'retiro', 'transferencia'], true)) {
            json(400, ['error' => 'tipo debe ser ingreso, retiro o transferencia']);
        }
        if (!in_array($medio_pago, $mediosValidos, true)) json(400, ['error' => 'medio_pago inválido']);
        if ($monto === null || $monto <= 0) json(400, ['error' => 'monto inválido']);

        if ($tipo === 'transferencia') {
            if (!in_array($medio_pago_destino, $mediosValidos, true)) json(400, ['error' => 'medio_pago_destino inválido']);
            if ($medio_pago_destino === $medio_pago) json(400, ['error' => 'El medio de pago de destino debe ser distinto al de origen']);
        } else {
            $medio_pago_destino = null;
        }

        $usuario = Auth::usuarioActual();
        if (!$usuario) json(401, ['error' => 'Usuario no identificado']);

        $turno = $this->turnoAbierto($caja_id);

        $stmt = DB::get()->prepare("
            INSERT INTO caja_movimientos (turno_id, tipo, medio_pago, medio_pago_destino, monto, motivo, usuario_id, creado_en)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$turno['id'], $tipo, $medio_pago, $medio_pago_destino, $monto, $motivo, $usuario['id']]);

        json(201, ['id' => (int)DB::get()->lastInsertId()]);
    }

    public function eliminar(int $id): void {
        $usuario = Auth::usuarioActual();
        if (!$usuario) json(401, ['error' => 'Usuario no identificado']);

        $stmt = DB::get()->prepare("
            SELECT m.id, t.caja_id, t.estado AS turno_estado
            FROM caja_movimientos m
            JOIN caja_turnos t ON t.id = m.turno_id
            WHERE m.id = ?
        ");
        $stmt->execute([$id]);
        $mov = $stmt->fetch();
        if (!$mov) json(404, ['error' => 'Movimiento no encontrado']);

        if (!Auth::esAdmin()) {
            $caja_id = isset($_GET['caja_id']) && is_numeric($_GET['caja_id']) ? (int)$_GET['caja_id'] : null;
            if (!$caja_id || $caja_id !== (int)$mov['caja_id']) {
                json(403, ['error' => 'Solo podés eliminar movimientos de la caja que estás operando']);
            }
        }

        if ($mov['turno_estado'] !== 'abierto') {
            json(409, ['error' => 'No se puede eliminar un movimiento de un turno ya cerrado']);
        }

        DB::get()->prepare("DELETE FROM caja_movimientos WHERE id = ?")->execute([$id]);
        json(200, ['ok' => true]);
    }

    public function listar(): void {
        $caja_id = isset($_GET['caja_id']) && is_numeric($_GET['caja_id']) ? (int)$_GET['caja_id'] : null;
        if (!Auth::esAdmin() && !$caja_id) {
            json(400, ['error' => 'caja_id requerido']);
        }

        $where  = [];
        $params = [];
        if ($caja_id)               { $where[] = 't.caja_id = ?';     $params[] = $caja_id; }
        if (!empty($_GET['desde'])) { $where[] = 'm.creado_en >= ?';  $params[] = $_GET['desde'] . ' 00:00:00'; }
        if (!empty($_GET['hasta'])) { $where[] = 'm.creado_en <= ?';  $params[] = $_GET['hasta'] . ' 23:59:59'; }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = DB::get()->prepare("
            SELECT m.*, t.caja_id, c.nombre AS caja_nombre, u.nombre AS usuario_nombre
            FROM caja_movimientos m
            JOIN caja_turnos t ON t.id = m.turno_id
            JOIN cajas c       ON c.id = t.caja_id
            JOIN usuarios u    ON u.id = m.usuario_id
            $whereSql
            ORDER BY m.id DESC
        ");
        $stmt->execute($params);
        json(200, $stmt->fetchAll());
    }
}
