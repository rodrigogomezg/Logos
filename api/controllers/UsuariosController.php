<?php

require_once __DIR__ . '/../config/db.php';

class UsuariosController {

    public function listar(): void {
        $stmt = DB::get()->query("SELECT id, nombre, rol, activo FROM usuarios WHERE activo = 1 ORDER BY rol = 'admin' DESC, nombre");
        $items = $stmt->fetchAll();
        foreach ($items as &$u) { $u['activo'] = (bool)$u['activo']; }
        unset($u);
        json(200, $items);
    }

    public function listarTodos(): void {
        $stmt = DB::get()->query("SELECT id, nombre, rol, activo FROM usuarios ORDER BY rol = 'admin' DESC, nombre");
        $items = $stmt->fetchAll();
        foreach ($items as &$u) { $u['activo'] = (bool)$u['activo']; }
        unset($u);
        json(200, $items);
    }

    public function login(): void {
        $body       = json_decode(file_get_contents('php://input'), true);
        $usuario_id = isset($body['usuario_id']) ? (int)$body['usuario_id'] : 0;
        $pin        = (string)($body['pin'] ?? '');

        if (!$usuario_id || $pin === '') json(400, ['error' => 'usuario_id y pin son requeridos']);

        $db   = DB::get();
        $stmt = $db->prepare("SELECT id, nombre, pin_hash, rol, activo FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        $usuario = $stmt->fetch();

        if (!$usuario || !$usuario['activo'] || !password_verify($pin, $usuario['pin_hash'])) {
            json(401, ['error' => 'PIN incorrecto']);
        }

        if ($usuario['rol'] === 'admin') {
            $cajas = $db->query("SELECT id, nombre, tipo, orden FROM cajas WHERE activo = 1 ORDER BY orden, nombre")->fetchAll();
            $stmt  = $db->prepare("SELECT id, nombre FROM cajas WHERE activo = 1 AND tipo = 'compra' ORDER BY orden, nombre LIMIT 1");
        } else {
            $cajas = $db->query("SELECT id, nombre, tipo, orden FROM cajas WHERE activo = 1 AND tipo = 'venta' ORDER BY orden, nombre")->fetchAll();
            $stmt  = $db->prepare("SELECT id, nombre FROM cajas WHERE activo = 1 AND tipo = 'venta' ORDER BY orden, nombre LIMIT 1");
        }
        $stmt->execute();
        $caja_default = $stmt->fetch();

        json(200, [
            'ok'           => true,
            'usuario'      => ['id' => (int)$usuario['id'], 'nombre' => $usuario['nombre'], 'rol' => $usuario['rol']],
            'cajas'        => $cajas,
            'caja_default' => $caja_default ?: null,
        ]);
    }

    public function crear(): void {
        $body   = json_decode(file_get_contents('php://input'), true);
        $nombre = trim($body['nombre'] ?? '');
        $pin    = (string)($body['pin'] ?? '');
        $rol    = $body['rol'] ?? 'user';

        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);
        if (!preg_match('/^\d{4,6}$/', $pin)) json(400, ['error' => 'pin debe ser numérico de 4 a 6 dígitos']);
        if (!in_array($rol, ['admin', 'user'], true)) json(400, ['error' => 'rol inválido']);

        $db = DB::get();
        $db->prepare("INSERT INTO usuarios (nombre, pin_hash, rol, activo) VALUES (?, ?, ?, 1)")
           ->execute([$nombre, password_hash($pin, PASSWORD_DEFAULT), $rol]);

        json(200, ['ok' => true, 'id' => (int)$db->lastInsertId()]);
    }

    public function actualizar(int $id): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);

        $db = DB::get();
        $check = $db->prepare("SELECT id FROM usuarios WHERE id = ?");
        $check->execute([$id]);
        if (!$check->fetch()) json(404, ['error' => 'Usuario no encontrado']);

        $nombre = isset($body['nombre']) ? trim($body['nombre']) : '';
        $rol    = $body['rol'] ?? null;
        $activo = array_key_exists('activo', $body) ? ($body['activo'] ? 1 : 0) : null;
        $pin    = isset($body['pin']) ? (string)$body['pin'] : '';

        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);
        if ($rol !== null && !in_array($rol, ['admin', 'user'], true)) json(400, ['error' => 'rol inválido']);
        if ($pin !== '' && !preg_match('/^\d{4,6}$/', $pin)) json(400, ['error' => 'pin debe ser numérico de 4 a 6 dígitos']);

        if ($pin !== '') {
            $db->prepare("UPDATE usuarios SET nombre = ?, rol = COALESCE(?, rol), activo = COALESCE(?, activo), pin_hash = ? WHERE id = ?")
               ->execute([$nombre, $rol, $activo, password_hash($pin, PASSWORD_DEFAULT), $id]);
        } else {
            $db->prepare("UPDATE usuarios SET nombre = ?, rol = COALESCE(?, rol), activo = COALESCE(?, activo) WHERE id = ?")
               ->execute([$nombre, $rol, $activo, $id]);
        }

        json(200, ['ok' => true]);
    }

    public function eliminar(int $id): void {
        $db = DB::get();
        $check = $db->prepare("SELECT id FROM usuarios WHERE id = ?");
        $check->execute([$id]);
        if (!$check->fetch()) json(404, ['error' => 'Usuario no encontrado']);

        $db->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);
        json(200, ['ok' => true]);
    }
}
