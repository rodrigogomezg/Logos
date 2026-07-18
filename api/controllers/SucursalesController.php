<?php

require_once __DIR__ . '/../config/db.php';

class SucursalesController {

    public function listar(): void {
        $db    = DB::get();
        $todas = isset($_GET['todas']) && $_GET['todas'] === '1' && Auth::esAdmin();

        $where = $todas ? '' : 'WHERE activo = 1';
        $rows  = $db->query("
            SELECT s.id, s.nombre, s.nombre_fantasia, s.domicilio, s.telefono, s.email,
                   s.punto_venta, s.activo, s.creado_en,
                   (SELECT id FROM depositos WHERE sucursal_id = s.id AND es_principal = 1 LIMIT 1) AS deposito_principal_id
            FROM sucursales s $where ORDER BY s.nombre
        ")->fetchAll();

        foreach ($rows as &$r) {
            $r['id']                   = (int)$r['id'];
            $r['punto_venta']          = $r['punto_venta'] !== null ? (int)$r['punto_venta'] : null;
            $r['activo']               = (bool)$r['activo'];
            $r['deposito_principal_id'] = $r['deposito_principal_id'] !== null ? (int)$r['deposito_principal_id'] : null;
        }
        json(200, $rows);
    }

    public function crear(): void {
        Auth::requireAdmin();
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $nombre = trim($body['nombre'] ?? '');
        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);

        $db = DB::get();
        $db->prepare("
            INSERT INTO sucursales (nombre, nombre_fantasia, domicilio, telefono, email, punto_venta, activo)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ")->execute([
            $nombre,
            trim($body['nombre_fantasia'] ?? '') ?: null,
            trim($body['domicilio']       ?? '') ?: null,
            trim($body['telefono']        ?? '') ?: null,
            trim($body['email']           ?? '') ?: null,
            isset($body['punto_venta']) && $body['punto_venta'] !== '' ? (int)$body['punto_venta'] : null,
        ]);
        $id = (int)$db->lastInsertId();

        // Crear depósito principal automáticamente
        $db->prepare("INSERT INTO depositos (sucursal_id, nombre, es_principal, activo) VALUES (?, 'Depósito Principal', 1, 1)")
           ->execute([$id]);

        json(201, ['ok' => true, 'id' => $id]);
    }

    public function actualizar(int $id): void {
        Auth::requireAdmin();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $db   = DB::get();
        $stmt = $db->prepare("SELECT id FROM sucursales WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) json(404, ['error' => 'Sucursal no encontrada']);

        $sets    = [];
        $params  = [];
        $campos  = ['nombre', 'nombre_fantasia', 'domicilio', 'telefono', 'email'];
        foreach ($campos as $c) {
            if (array_key_exists($c, $body)) {
                $sets[]   = "$c = ?";
                $params[] = trim((string)$body[$c]) ?: null;
            }
        }
        if (array_key_exists('punto_venta', $body)) {
            $sets[]   = 'punto_venta = ?';
            $params[] = $body['punto_venta'] !== '' && $body['punto_venta'] !== null ? (int)$body['punto_venta'] : null;
        }
        if (array_key_exists('activo', $body)) {
            $sets[]   = 'activo = ?';
            $params[] = $body['activo'] ? 1 : 0;
        }
        if (empty($sets)) json(400, ['error' => 'Nada que actualizar']);

        $params[] = $id;
        $db->prepare("UPDATE sucursales SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        json(200, ['ok' => true]);
    }

    public function depositos(): void {
        $sucursal_id = isset($_GET['sucursal_id']) && is_numeric($_GET['sucursal_id']) ? (int)$_GET['sucursal_id'] : null;

        $db = DB::get();
        if ($sucursal_id) {
            $rows = $db->prepare("
                SELECT d.id, d.sucursal_id, s.nombre AS sucursal_nombre,
                       d.nombre, d.descripcion, d.es_principal, d.activo, d.creado_en
                FROM depositos d
                JOIN sucursales s ON s.id = d.sucursal_id
                WHERE d.sucursal_id = ?
                ORDER BY d.es_principal DESC, d.nombre
            ");
            $rows->execute([$sucursal_id]);
        } else {
            $rows = $db->query("
                SELECT d.id, d.sucursal_id, s.nombre AS sucursal_nombre,
                       d.nombre, d.descripcion, d.es_principal, d.activo, d.creado_en
                FROM depositos d
                JOIN sucursales s ON s.id = d.sucursal_id
                WHERE d.activo = 1
                ORDER BY s.nombre, d.es_principal DESC, d.nombre
            ");
        }
        $data = $rows->fetchAll();
        foreach ($data as &$r) {
            $r['id']          = (int)$r['id'];
            $r['sucursal_id'] = (int)$r['sucursal_id'];
            $r['es_principal'] = (bool)$r['es_principal'];
            $r['activo']      = (bool)$r['activo'];
        }
        json(200, $data);
    }

    public function crearDeposito(): void {
        Auth::requireAdmin();
        $body        = json_decode(file_get_contents('php://input'), true) ?? [];
        $sucursal_id = isset($body['sucursal_id']) ? (int)$body['sucursal_id'] : 0;
        $nombre      = trim($body['nombre'] ?? '');

        if (!$sucursal_id) json(400, ['error' => 'sucursal_id requerido']);
        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);

        $db   = DB::get();
        $stmt = $db->prepare("SELECT id FROM sucursales WHERE id = ? AND activo = 1");
        $stmt->execute([$sucursal_id]);
        if (!$stmt->fetch()) json(404, ['error' => 'Sucursal no encontrada']);

        $db->prepare("
            INSERT INTO depositos (sucursal_id, nombre, descripcion, es_principal, activo)
            VALUES (?, ?, ?, 0, 1)
        ")->execute([
            $sucursal_id,
            $nombre,
            trim($body['descripcion'] ?? '') ?: null,
        ]);
        $id = (int)$db->lastInsertId();
        json(201, ['ok' => true, 'id' => $id]);
    }

    public function actualizarDeposito(int $id): void {
        Auth::requireAdmin();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $db   = DB::get();
        $stmt = $db->prepare("SELECT id, sucursal_id FROM depositos WHERE id = ?");
        $stmt->execute([$id]);
        $dep = $stmt->fetch();
        if (!$dep) json(404, ['error' => 'Depósito no encontrado']);

        // Establecer como principal: desactivar el anterior de esta sucursal primero
        if (!empty($body['es_principal'])) {
            $db->prepare("UPDATE depositos SET es_principal = 0 WHERE sucursal_id = ?")->execute([$dep['sucursal_id']]);
            $db->prepare("UPDATE depositos SET es_principal = 1 WHERE id = ?")->execute([$id]);
        }

        $sets   = [];
        $params = [];
        if (array_key_exists('nombre', $body)) {
            $sets[]   = 'nombre = ?';
            $params[] = trim((string)$body['nombre']) ?: 'Depósito';
        }
        if (array_key_exists('descripcion', $body)) {
            $sets[]   = 'descripcion = ?';
            $params[] = trim((string)$body['descripcion']) ?: null;
        }
        if (array_key_exists('activo', $body)) {
            $sets[]   = 'activo = ?';
            $params[] = $body['activo'] ? 1 : 0;
        }
        if (!empty($sets)) {
            $params[] = $id;
            $db->prepare("UPDATE depositos SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        }
        json(200, ['ok' => true]);
    }

    public function stockDeposito(): void {
        Auth::requirePermiso('costos');
        $deposito_id = isset($_GET['deposito_id']) ? (int)$_GET['deposito_id'] : 0;
        if (!$deposito_id) json(400, ['error' => 'deposito_id requerido']);

        $db = DB::get();
        $stmt = $db->prepare("
            SELECT p.id, p.codigo, p.nombre, p.categoria, p.marca,
                   COALESCE(sd.stock_actual, 0) AS stock_deposito,
                   p.stock_actual               AS stock_total,
                   p.stock_minimo,
                   p.costo_actual
            FROM productos p
            LEFT JOIN stock_depositos sd ON sd.producto_id = p.id AND sd.deposito_id = ?
            WHERE p.activo = 1
            ORDER BY p.nombre
        ");
        $stmt->execute([$deposito_id]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['id']            = (int)$r['id'];
            $r['stock_deposito'] = (float)$r['stock_deposito'];
            $r['stock_total']   = (float)$r['stock_total'];
            $r['stock_minimo']  = (float)$r['stock_minimo'];
            $r['costo_actual']  = (float)$r['costo_actual'];
        }
        json(200, $rows);
    }
}
