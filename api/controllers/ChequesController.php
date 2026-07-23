<?php

require_once __DIR__ . '/../config/db.php';

class ChequesController {

    /* ── Helpers ───────────────────────────────────────────── */

    private static function validarEstado(string $estado): bool {
        return in_array($estado, ['cartera','depositado','endosado','rechazado','pendiente','debitado'], true);
    }

    private static function fila(array &$r): void {
        $r['monto'] = (float)$r['monto'];
    }

    /* ── Listar ────────────────────────────────────────────── */

    public function listar(): void {
        $tipo   = $_GET['tipo']   ?? null;
        $estado = $_GET['estado'] ?? null;
        $desde  = $_GET['desde']  ?? null;
        $hasta  = $_GET['hasta']  ?? null;
        $q      = trim($_GET['q'] ?? '');

        $where  = [];
        $params = [];

        if ($tipo && in_array($tipo, ['recibido','emitido'], true)) {
            $where[] = 'c.tipo = ?'; $params[] = $tipo;
        }
        if ($estado) {
            $es = array_filter(explode(',', $estado), fn($e) => self::validarEstado($e));
            if ($es) {
                $ph = implode(',', array_fill(0, count($es), '?'));
                $where[] = "c.estado IN ($ph)";
                $params  = array_merge($params, array_values($es));
            }
        }
        if ($desde) { $where[] = 'c.fecha_vencimiento >= ?'; $params[] = $desde; }
        if ($hasta)  { $where[] = 'c.fecha_vencimiento <= ?'; $params[] = $hasta; }
        if ($q !== '') {
            $like    = '%' . $q . '%';
            $where[] = '(c.numero LIKE ? OR c.banco LIKE ? OR c.librador LIKE ? OR c.beneficiario LIKE ?)';
            $params  = array_merge($params, [$like, $like, $like, $like]);
        }

        $sql = "
            SELECT c.*,
                   cl.nombre AS cliente_nombre,
                   p.nombre  AS proveedor_nombre,
                   v.tipo_comprobante AS venta_tipo
            FROM cheques c
            LEFT JOIN clientes    cl ON cl.id = c.cliente_id
            LEFT JOIN proveedores p  ON p.id  = c.proveedor_id
            LEFT JOIN ventas      v  ON v.id  = c.venta_id
        ";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY c.fecha_vencimiento ASC, c.id DESC';

        $stmt = DB::get()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) self::fila($r);
        unset($r);

        json(200, $rows);
    }

    /* ── Resumen por estado ────────────────────────────────── */

    public function resumen(): void {
        $db  = DB::get();
        $hoy = date('Y-m-d');

        $stmt = $db->query("
            SELECT tipo, estado, COUNT(*) AS cantidad, COALESCE(SUM(monto),0) AS total
            FROM cheques
            GROUP BY tipo, estado
        ");
        $data = [];
        foreach ($stmt->fetchAll() as $r) {
            $data[$r['tipo']][$r['estado']] = [
                'cantidad' => (int)$r['cantidad'],
                'total'    => (float)$r['total'],
            ];
        }

        $stmt = $db->prepare("
            SELECT COUNT(*) AS cant, COALESCE(SUM(monto),0) AS total
            FROM cheques
            WHERE tipo='recibido' AND estado='cartera' AND fecha_vencimiento < ?
        ");
        $stmt->execute([$hoy]);
        $vencidos = $stmt->fetch();

        $stmt = $db->prepare("
            SELECT COUNT(*) AS cant, COALESCE(SUM(monto),0) AS total
            FROM cheques
            WHERE tipo='recibido' AND estado='cartera'
              AND fecha_vencimiento BETWEEN ? AND DATE_ADD(?, INTERVAL 7 DAY)
        ");
        $stmt->execute([$hoy, $hoy]);
        $proximos = $stmt->fetch();

        json(200, [
            'data'     => $data,
            'vencidos' => ['cantidad' => (int)$vencidos['cant'],  'total' => (float)$vencidos['total']],
            'proximos' => ['cantidad' => (int)$proximos['cant'],  'total' => (float)$proximos['total']],
        ]);
    }

    /* ── Crear ─────────────────────────────────────────────── */

    public function crear(): void {
        $b = json_decode(file_get_contents('php://input'), true) ?: [];

        $tipo              = $b['tipo']              ?? null;
        $numero            = trim($b['numero']       ?? '');
        $banco             = trim($b['banco']        ?? '');
        $librador          = trim($b['librador']     ?? '');
        $beneficiario      = trim($b['beneficiario'] ?? '');
        $cuit              = trim($b['cuit']         ?? '');
        $monto             = isset($b['monto'])   ? (float)$b['monto']   : 0.0;
        $fecha_emision     = $b['fecha_emision']     ?? null;
        $fecha_vencimiento = $b['fecha_vencimiento'] ?? null;
        $notas             = trim($b['notas']        ?? '');
        $venta_id          = isset($b['venta_id'])          && is_numeric($b['venta_id'])          ? (int)$b['venta_id']          : null;
        $cliente_id        = isset($b['cliente_id'])        && is_numeric($b['cliente_id'])        ? (int)$b['cliente_id']        : null;
        $cc_movimiento_id  = isset($b['cc_movimiento_id'])  && is_numeric($b['cc_movimiento_id'])  ? (int)$b['cc_movimiento_id']  : null;
        $proveedor_id      = isset($b['proveedor_id'])      && is_numeric($b['proveedor_id'])      ? (int)$b['proveedor_id']      : null;
        $sucursal_id       = isset($b['sucursal_id'])       ? (int)$b['sucursal_id'] : 1;

        if (!in_array($tipo, ['recibido','emitido'], true))              json(400, ['error' => 'tipo debe ser recibido o emitido']);
        if ($numero === '')                                               json(400, ['error' => 'numero es requerido']);
        if ($banco === '')                                                json(400, ['error' => 'banco es requerido']);
        if ($monto <= 0)                                                  json(400, ['error' => 'monto debe ser mayor a 0']);
        if (!$fecha_vencimiento || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_vencimiento)) {
            json(400, ['error' => 'fecha_vencimiento es requerida (YYYY-MM-DD)']);
        }

        $estado = $tipo === 'recibido' ? 'cartera' : 'pendiente';

        DB::get()->prepare("
            INSERT INTO cheques
                (tipo, numero, banco, librador, beneficiario, cuit, monto,
                 fecha_emision, fecha_vencimiento, estado,
                 venta_id, cliente_id, cc_movimiento_id, proveedor_id, notas, sucursal_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $tipo, $numero, $banco,
            $librador ?: null, $beneficiario ?: null, $cuit ?: null,
            $monto, $fecha_emision ?: null, $fecha_vencimiento, $estado,
            $venta_id, $cliente_id, $cc_movimiento_id, $proveedor_id,
            $notas ?: null, $sucursal_id,
        ]);

        $id = (int)DB::get()->lastInsertId();
        json(201, ['id' => $id, 'estado' => $estado]);
    }

    /* ── Depositar ─────────────────────────────────────────── */

    public function depositar(int $id): void {
        $b             = json_decode(file_get_contents('php://input'), true) ?: [];
        $banco_destino = trim($b['banco_destino'] ?? '');
        $fecha_dep     = $b['fecha_deposito']     ?? date('Y-m-d');

        if (!$banco_destino) json(400, ['error' => 'banco_destino es requerido']);

        $db  = DB::get();
        $chq = $this->obtenerOFallar($db, $id);
        if ($chq['tipo'] !== 'recibido' || $chq['estado'] !== 'cartera') {
            json(409, ['error' => 'Solo se pueden depositar cheques recibidos en cartera']);
        }

        $db->prepare("UPDATE cheques SET estado='depositado', banco_destino=?, fecha_deposito=?, updated_at=NOW() WHERE id=?")
           ->execute([$banco_destino, $fecha_dep, $id]);

        json(200, ['ok' => true]);
    }

    /* ── Endosar ───────────────────────────────────────────── */

    public function endosar(int $id): void {
        $b            = json_decode(file_get_contents('php://input'), true) ?: [];
        $proveedor_id = isset($b['proveedor_id']) && is_numeric($b['proveedor_id']) ? (int)$b['proveedor_id'] : null;
        $obs          = trim($b['observaciones'] ?? '');
        $fecha        = $b['fecha'] ?? date('Y-m-d');

        if (!$proveedor_id) json(400, ['error' => 'proveedor_id es requerido']);

        $db  = DB::get();
        $chq = $this->obtenerOFallar($db, $id);
        if ($chq['tipo'] !== 'recibido' || $chq['estado'] !== 'cartera') {
            json(409, ['error' => 'Solo se pueden endosar cheques recibidos en cartera']);
        }

        $stmt = $db->prepare("SELECT id, nombre FROM proveedores WHERE id = ?");
        $stmt->execute([$proveedor_id]);
        $prov = $stmt->fetch();
        if (!$prov) json(404, ['error' => 'Proveedor no encontrado']);

        $db->beginTransaction();
        try {
            $obsCC = $obs ?: "Pago con cheque #{$chq['numero']} — {$chq['banco']}";
            $pd    = json_encode(['cheque_id' => $id, 'numero' => $chq['numero'], 'banco' => $chq['banco']], JSON_UNESCAPED_UNICODE);

            $db->prepare("
                INSERT INTO cuenta_corriente_movimientos
                    (entidad_tipo, entidad_id, tipo, monto, fecha, observaciones, medio_pago, pago_datos)
                VALUES ('proveedor', ?, 'pago', ?, ?, ?, 'cheque', ?)
            ")->execute([$proveedor_id, (float)$chq['monto'], $fecha, $obsCC, $pd]);

            $cc_mov_id = (int)$db->lastInsertId();

            $db->prepare("UPDATE proveedores SET saldo_cuenta_corriente = saldo_cuenta_corriente - ? WHERE id = ?")
               ->execute([(float)$chq['monto'], $proveedor_id]);

            $db->prepare("UPDATE cheques SET estado='endosado', proveedor_id=?, cc_prov_movimiento_id=?, updated_at=NOW() WHERE id=?")
               ->execute([$proveedor_id, $cc_mov_id, $id]);

            $db->commit();
            json(200, ['ok' => true, 'proveedor' => $prov['nombre'], 'cc_movimiento_id' => $cc_mov_id]);
        } catch (Throwable $e) {
            $db->rollBack();
            json(500, ['error' => $e->getMessage()]);
        }
    }

    /* ── Rechazar ──────────────────────────────────────────── */

    public function rechazar(int $id): void {
        $db  = DB::get();
        $chq = $this->obtenerOFallar($db, $id);

        $ok = ($chq['tipo'] === 'recibido' && in_array($chq['estado'], ['cartera','depositado'], true))
           || ($chq['tipo'] === 'emitido'  && $chq['estado'] === 'pendiente');
        if (!$ok) json(409, ['error' => 'No se puede rechazar el cheque en su estado actual']);

        $db->prepare("UPDATE cheques SET estado='rechazado', updated_at=NOW() WHERE id=?")->execute([$id]);
        json(200, ['ok' => true]);
    }

    /* ── Marcar debitado (emitido) ─────────────────────────── */

    public function marcarDebitado(int $id): void {
        $db  = DB::get();
        $chq = $this->obtenerOFallar($db, $id);
        if ($chq['tipo'] !== 'emitido' || $chq['estado'] !== 'pendiente') {
            json(409, ['error' => 'Solo cheques emitidos pendientes pueden marcarse como debitados']);
        }

        $db->prepare("UPDATE cheques SET estado='debitado', updated_at=NOW() WHERE id=?")->execute([$id]);
        json(200, ['ok' => true]);
    }

    /* ── Reactivar (volver a cartera/pendiente) ────────────── */

    public function reactivar(int $id): void {
        $db  = DB::get();
        $chq = $this->obtenerOFallar($db, $id);

        $revertibles = $chq['tipo'] === 'recibido'
            ? ['depositado','endosado','rechazado']
            : ['debitado','rechazado'];

        if (!in_array($chq['estado'], $revertibles, true)) {
            json(409, ['error' => 'No se puede reactivar el cheque en su estado actual']);
        }

        $db->beginTransaction();
        try {
            if ($chq['estado'] === 'endosado' && $chq['cc_prov_movimiento_id']) {
                $mov = $db->prepare("SELECT monto, entidad_id FROM cuenta_corriente_movimientos WHERE id = ?");
                $mov->execute([(int)$chq['cc_prov_movimiento_id']]);
                $m = $mov->fetch();
                if ($m) {
                    $db->prepare("DELETE FROM cuenta_corriente_movimientos WHERE id = ?")
                       ->execute([(int)$chq['cc_prov_movimiento_id']]);
                    $db->prepare("UPDATE proveedores SET saldo_cuenta_corriente = saldo_cuenta_corriente + ? WHERE id = ?")
                       ->execute([(float)$m['monto'], (int)$m['entidad_id']]);
                }
            }

            $estadoOrigen = $chq['tipo'] === 'recibido' ? 'cartera' : 'pendiente';
            $db->prepare("
                UPDATE cheques
                SET estado=?, proveedor_id=NULL, cc_prov_movimiento_id=NULL,
                    banco_destino=NULL, fecha_deposito=NULL, updated_at=NOW()
                WHERE id=?
            ")->execute([$estadoOrigen, $id]);

            $db->commit();
            json(200, ['ok' => true]);
        } catch (Throwable $e) {
            $db->rollBack();
            json(500, ['error' => $e->getMessage()]);
        }
    }

    /* ── Eliminar ──────────────────────────────────────────── */

    public function eliminar(int $id): void {
        $db  = DB::get();
        $chq = $this->obtenerOFallar($db, $id);

        if (!in_array($chq['estado'], ['cartera','pendiente'], true)) {
            json(409, ['error' => 'Solo se pueden eliminar cheques en cartera o pendientes']);
        }
        if ($chq['cc_movimiento_id']) {
            json(409, ['error' => 'El cheque está vinculado a un movimiento de CC y no puede eliminarse directamente']);
        }

        $db->prepare("DELETE FROM cheques WHERE id = ?")->execute([$id]);
        json(200, ['ok' => true]);
    }

    /* ── Actualizar notas ──────────────────────────────────── */

    public function actualizarNotas(int $id): void {
        $b     = json_decode(file_get_contents('php://input'), true) ?: [];
        $notas = trim($b['notas'] ?? '');

        $db = DB::get();
        $this->obtenerOFallar($db, $id);
        $db->prepare("UPDATE cheques SET notas=?, updated_at=NOW() WHERE id=?")->execute([$notas ?: null, $id]);
        json(200, ['ok' => true]);
    }

    /* ── Helper privado ────────────────────────────────────── */

    private function obtenerOFallar(\PDO $db, int $id): array {
        $stmt = $db->prepare("SELECT * FROM cheques WHERE id = ?");
        $stmt->execute([$id]);
        $chq = $stmt->fetch();
        if (!$chq) json(404, ['error' => 'Cheque no encontrado']);
        return $chq;
    }
}
