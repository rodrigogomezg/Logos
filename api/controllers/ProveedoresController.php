<?php

require_once __DIR__ . '/../config/db.php';

class ProveedoresController {

    public function search(): void {
        $q     = trim($_GET['q'] ?? '');
        $limit = min((int)($_GET['limit'] ?? 20), 100);

        if ($q === '') {
            json(400, ['error' => 'Parámetro q requerido']);
        }

        $db   = DB::get();
        $like = '%' . $q . '%';

        $stmt = $db->prepare("
            SELECT id, nombre, cuit, condicion_iva, saldo_cuenta_corriente
            FROM proveedores
            WHERE nombre LIKE ? OR cuit LIKE ?
            ORDER BY nombre
            LIMIT ?
        ");
        $stmt->execute([$like, $like, $limit]);

        $proveedores = $stmt->fetchAll();
        foreach ($proveedores as &$p) {
            $p['saldo_cuenta_corriente'] = (float)$p['saldo_cuenta_corriente'];
        }

        json(200, $proveedores);
    }

    public function get(int $id): void {
        $db = DB::get();

        $stmt = $db->prepare("
            SELECT id, nombre, cuit, condicion_iva, saldo_cuenta_corriente
            FROM proveedores WHERE id = ?
        ");
        $stmt->execute([$id]);
        $proveedor = $stmt->fetch();

        if (!$proveedor) {
            json(404, ['error' => 'Proveedor no encontrado']);
        }

        $proveedor['saldo_cuenta_corriente'] = (float)$proveedor['saldo_cuenta_corriente'];

        json(200, $proveedor);
    }

    public function crear(): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);

        $nombre    = isset($body['nombre'])        ? trim($body['nombre'])        : '';
        $cuit      = isset($body['cuit'])          ? trim($body['cuit'])          : null;
        $condicion = isset($body['condicion_iva']) ? trim($body['condicion_iva']) : null;

        if ($nombre === '') json(400, ['error' => 'El nombre es requerido']);

        $db = DB::get();
        $db->prepare("
            INSERT INTO proveedores (nombre, cuit, condicion_iva)
            VALUES (?, ?, ?)
        ")->execute([
            $nombre,
            $cuit      ?: null,
            $condicion ?: null,
        ]);

        $id = (int)$db->lastInsertId();

        json(201, [
            'id'                     => $id,
            'nombre'                 => $nombre,
            'cuit'                   => $cuit      ?: null,
            'condicion_iva'          => $condicion ?: null,
            'saldo_cuenta_corriente' => 0.0,
        ]);
    }

    public function put(int $id): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);

        $nombre    = isset($body['nombre'])        ? trim($body['nombre'])        : '';
        $cuit      = isset($body['cuit'])          ? trim($body['cuit'])          : null;
        $condicion = isset($body['condicion_iva']) ? trim($body['condicion_iva']) : null;

        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);

        $db = DB::get();
        $stmt = $db->prepare("SELECT id FROM proveedores WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) json(404, ['error' => 'Proveedor no encontrado']);

        $db->prepare("
            UPDATE proveedores SET
                nombre        = ?,
                cuit          = ?,
                condicion_iva = ?
            WHERE id = ?
        ")->execute([
            $nombre,
            $cuit      ?: null,
            $condicion ?: null,
            $id,
        ]);

        json(200, ['ok' => true]);
    }
}
