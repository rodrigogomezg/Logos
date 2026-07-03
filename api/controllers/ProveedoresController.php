<?php

require_once __DIR__ . '/../config/db.php';

class ProveedoresController {

    public function search(): void {
        $q     = trim($_GET['q'] ?? '');
        $limit = min((int)($_GET['limit'] ?? 20), 100);
        if ($q === '') json(400, ['error' => 'Parámetro q requerido']);

        $db   = DB::get();
        $like = '%' . $q . '%';
        $stmt = $db->prepare("
            SELECT id, nombre, cuit, condicion_iva, saldo_cuenta_corriente, cc_habilitada, limite_credito
            FROM proveedores
            WHERE activo = 1 AND (nombre LIKE ? OR cuit LIKE ?)
            ORDER BY nombre LIMIT ?
        ");
        $stmt->execute([$like, $like, $limit]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['saldo_cuenta_corriente'] = (float)$r['saldo_cuenta_corriente'];
            $r['cc_habilitada']          = (bool)$r['cc_habilitada'];
            $r['limite_credito']         = (float)$r['limite_credito'];
        }
        json(200, $rows);
    }

    public function listar(): void {
        $db     = DB::get();
        $pagina = max(1, (int)($_GET['page'] ?? 1));
        $perPag = min(100, max(10, (int)($_GET['per_page'] ?? 50)));
        $q      = trim($_GET['q'] ?? '');
        $activo = $_GET['activo'] ?? null;
        $offset = ($pagina - 1) * $perPag;

        $where  = [];
        $params = [];

        if ($q !== '') {
            $where[]  = '(p.nombre LIKE ? OR p.cuit LIKE ? OR p.email LIKE ?)';
            $like     = "%$q%";
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($activo !== null && $activo !== '') {
            $where[]  = 'p.activo = ?';
            $params[] = (int)$activo;
        }

        $whereStr = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $cs = $db->prepare("SELECT COUNT(*) FROM proveedores p $whereStr");
        $cs->execute($params);
        $total = (int)$cs->fetchColumn();

        $params[] = $perPag;
        $params[] = $offset;
        $stmt = $db->prepare("
            SELECT p.id, p.nombre, p.cuit, p.condicion_iva, p.email, p.telefono,
                   p.domicilio, p.localidad, p.provincia, p.observaciones,
                   p.activo, p.cc_habilitada, p.limite_credito, p.saldo_cuenta_corriente,
                   p.plazo_pago_dias, p.lista_precio_id, p.creado_en,
                   lp.nombre AS lista_precio_nombre
            FROM proveedores p
            LEFT JOIN listas_precio lp ON p.lista_precio_id = lp.id
            $whereStr
            ORDER BY p.nombre
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['activo']                 = (bool)$r['activo'];
            $r['cc_habilitada']          = (bool)$r['cc_habilitada'];
            $r['limite_credito']         = (float)$r['limite_credito'];
            $r['saldo_cuenta_corriente'] = (float)$r['saldo_cuenta_corriente'];
            $r['lista_precio_id']        = $r['lista_precio_id'] ? (int)$r['lista_precio_id'] : null;
            $r['plazo_pago_dias']        = $r['plazo_pago_dias'] ? (int)$r['plazo_pago_dias'] : null;
        }

        json(200, [
            'total'      => $total,
            'paginas'    => max(1, (int)ceil($total / $perPag)),
            'pagina'     => $pagina,
            'por_pagina' => $perPag,
            'datos'      => $rows,
        ]);
    }

    public function get(int $id): void {
        $stmt = DB::get()->prepare("
            SELECT p.id, p.nombre, p.cuit, p.condicion_iva, p.email, p.telefono,
                   p.domicilio, p.localidad, p.provincia, p.observaciones,
                   p.activo, p.cc_habilitada, p.limite_credito, p.saldo_cuenta_corriente,
                   p.plazo_pago_dias, p.lista_precio_id, p.creado_en,
                   lp.nombre AS lista_precio_nombre
            FROM proveedores p
            LEFT JOIN listas_precio lp ON p.lista_precio_id = lp.id
            WHERE p.id = ?
        ");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) json(404, ['error' => 'Proveedor no encontrado']);

        $r['activo']                 = (bool)$r['activo'];
        $r['cc_habilitada']          = (bool)$r['cc_habilitada'];
        $r['limite_credito']         = (float)$r['limite_credito'];
        $r['saldo_cuenta_corriente'] = (float)$r['saldo_cuenta_corriente'];
        $r['lista_precio_id']        = $r['lista_precio_id'] ? (int)$r['lista_precio_id'] : null;
        $r['plazo_pago_dias']        = $r['plazo_pago_dias'] ? (int)$r['plazo_pago_dias'] : null;
        json(200, $r);
    }

    public function crear(): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);
        $nombre = trim($body['nombre'] ?? '');
        if ($nombre === '') json(400, ['error' => 'El nombre es requerido']);

        $db = DB::get();
        $db->prepare("
            INSERT INTO proveedores
                (nombre, cuit, condicion_iva, email, telefono, domicilio, localidad, provincia,
                 observaciones, activo, cc_habilitada, limite_credito, plazo_pago_dias, lista_precio_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute($this->params($body, $nombre));

        $this->get((int)$db->lastInsertId());
    }

    public function put(int $id): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);
        $nombre = trim($body['nombre'] ?? '');
        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);

        $db   = DB::get();
        $stmt = $db->prepare("SELECT id FROM proveedores WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) json(404, ['error' => 'Proveedor no encontrado']);

        $db->prepare("
            UPDATE proveedores SET
                nombre=?, cuit=?, condicion_iva=?, email=?, telefono=?, domicilio=?,
                localidad=?, provincia=?, observaciones=?, activo=?, cc_habilitada=?,
                limite_credito=?, plazo_pago_dias=?, lista_precio_id=?
            WHERE id=?
        ")->execute([...$this->params($body, $nombre), $id]);

        $this->get($id);
    }

    private function params(array $body, string $nombre): array {
        return [
            $nombre,
            trim($body['cuit'] ?? '')         ?: null,
            trim($body['condicion_iva'] ?? '') ?: null,
            trim($body['email'] ?? '')         ?: null,
            trim($body['telefono'] ?? '')      ?: null,
            trim($body['domicilio'] ?? '')     ?: null,
            trim($body['localidad'] ?? '')     ?: null,
            trim($body['provincia'] ?? '')     ?: null,
            trim($body['observaciones'] ?? '') ?: null,
            isset($body['activo'])        ? (int)(bool)$body['activo']        : 1,
            isset($body['cc_habilitada']) ? (int)(bool)$body['cc_habilitada'] : 0,
            (float)($body['limite_credito'] ?? 0),
            isset($body['plazo_pago_dias']) && $body['plazo_pago_dias'] !== '' && $body['plazo_pago_dias'] !== null
                ? (int)$body['plazo_pago_dias'] : null,
            isset($body['lista_precio_id']) && $body['lista_precio_id']
                ? (int)$body['lista_precio_id'] : null,
        ];
    }

    public function eliminar(int $id): void {
        $db = DB::get();

        $s = $db->prepare("SELECT COUNT(*) FROM compras WHERE proveedor_id = ?");
        $s->execute([$id]);
        if ((int)$s->fetchColumn() > 0) {
            json(409, ['error' => 'El proveedor tiene compras registradas. Marcalo como Inactivo en lugar de eliminarlo.']);
        }

        $s = $db->prepare("SELECT COUNT(*) FROM cuenta_corriente_movimientos WHERE entidad_tipo = 'proveedor' AND entidad_id = ?");
        $s->execute([$id]);
        if ((int)$s->fetchColumn() > 0) {
            json(409, ['error' => 'El proveedor tiene movimientos en cuenta corriente. Marcalo como Inactivo en lugar de eliminarlo.']);
        }

        $db->prepare("DELETE FROM proveedores WHERE id = ?")->execute([$id]);
        json(200, ['ok' => true]);
    }
}
