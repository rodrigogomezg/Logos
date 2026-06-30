<?php

require_once __DIR__ . '/../config/db.php';

class CajasController {

    public function listar(): void {
        $stmt = DB::get()->query("SELECT id, nombre, tipo, activo, orden FROM cajas ORDER BY orden, nombre");
        $items = $stmt->fetchAll();
        foreach ($items as &$c) { $c['activo'] = (bool)$c['activo']; }
        unset($c);
        json(200, $items);
    }

    public function crear(): void {
        $body   = json_decode(file_get_contents('php://input'), true);
        $nombre = trim($body['nombre'] ?? '');
        $tipo   = $body['tipo'] ?? 'venta';
        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);
        if (!in_array($tipo, ['venta', 'compra'], true)) json(400, ['error' => 'tipo inválido']);

        $db = DB::get();
        $orden = (int)$db->query("SELECT COALESCE(MAX(orden), 0) + 1 FROM cajas")->fetchColumn();

        $db->prepare("INSERT INTO cajas (nombre, tipo, activo, orden) VALUES (?, ?, 1, ?)")
           ->execute([$nombre, $tipo, $orden]);

        json(200, ['ok' => true, 'id' => (int)$db->lastInsertId()]);
    }

    public function actualizar(int $id): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);

        $nombre = isset($body['nombre']) ? trim($body['nombre']) : '';
        $tipo   = $body['tipo'] ?? null;
        $activo = array_key_exists('activo', $body) ? ($body['activo'] ? 1 : 0) : null;

        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);
        if ($tipo !== null && !in_array($tipo, ['venta', 'compra'], true)) json(400, ['error' => 'tipo inválido']);

        $db = DB::get();
        $check = $db->prepare("SELECT id FROM cajas WHERE id = ?");
        $check->execute([$id]);
        if (!$check->fetch()) json(404, ['error' => 'Caja no encontrada']);

        $db->prepare("UPDATE cajas SET nombre = ?, tipo = COALESCE(?, tipo), activo = COALESCE(?, activo) WHERE id = ?")
           ->execute([$nombre, $tipo, $activo, $id]);

        json(200, ['ok' => true]);
    }

    public function eliminar(int $id): void {
        $db = DB::get();
        $check = $db->prepare("SELECT id FROM cajas WHERE id = ?");
        $check->execute([$id]);
        if (!$check->fetch()) json(404, ['error' => 'Caja no encontrada']);

        $db->prepare("DELETE FROM cajas WHERE id = ?")->execute([$id]);
        json(200, ['ok' => true]);
    }
}
