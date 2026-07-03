<?php

require_once __DIR__ . '/../config/db.php';

class ListasPrecioController {

    public function listar(): void {
        $soloActivas = ($_GET['activas'] ?? '') === '1';
        $db   = DB::get();
        $stmt = $db->prepare(
            $soloActivas
                ? "SELECT id, nombre, porcentaje, activa FROM listas_precio WHERE activa = 1 ORDER BY nombre"
                : "SELECT id, nombre, porcentaje, activa FROM listas_precio ORDER BY nombre"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['porcentaje'] = (float)$r['porcentaje'];
            $r['activa']     = (bool)$r['activa'];
        }
        json(200, $rows);
    }

    public function crear(): void {
        Auth::requireAdmin();
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);
        $nombre = trim($body['nombre'] ?? '');
        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);

        $db = DB::get();
        $db->prepare("INSERT INTO listas_precio (nombre, porcentaje, activa) VALUES (?,?,?)")->execute([
            $nombre,
            (float)($body['porcentaje'] ?? 0),
            isset($body['activa']) ? (int)(bool)$body['activa'] : 1,
        ]);
        $this->get((int)$db->lastInsertId());
    }

    public function actualizar(int $id): void {
        Auth::requireAdmin();
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);
        $nombre = trim($body['nombre'] ?? '');
        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);

        DB::get()->prepare("UPDATE listas_precio SET nombre=?, porcentaje=?, activa=? WHERE id=?")->execute([
            $nombre,
            (float)($body['porcentaje'] ?? 0),
            isset($body['activa']) ? (int)(bool)$body['activa'] : 1,
            $id,
        ]);
        $this->get($id);
    }

    public function eliminar(int $id): void {
        Auth::requireAdmin();
        $db = DB::get();
        $s  = $db->prepare("SELECT COUNT(*) FROM clientes WHERE lista_precio_id = ?");
        $s->execute([$id]);
        $c = (int)$s->fetchColumn();
        if ($c > 0) json(409, ['error' => "Esta lista está asignada a $c cliente(s). Reasigná antes de eliminar."]);

        $s->execute([$id]);
        $s = $db->prepare("SELECT COUNT(*) FROM proveedores WHERE lista_precio_id = ?");
        $s->execute([$id]);
        if ((int)$s->fetchColumn() > 0) json(409, ['error' => 'Esta lista está asignada a proveedores. Reasignala antes de eliminar.']);

        $db->prepare("DELETE FROM listas_precio WHERE id = ?")->execute([$id]);
        json(200, ['ok' => true]);
    }

    private function get(int $id): void {
        $stmt = DB::get()->prepare("SELECT id, nombre, porcentaje, activa FROM listas_precio WHERE id = ?");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) json(404, ['error' => 'Lista no encontrada']);
        $r['porcentaje'] = (float)$r['porcentaje'];
        $r['activa']     = (bool)$r['activa'];
        json(200, $r);
    }
}
