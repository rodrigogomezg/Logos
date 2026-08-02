<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/ReglasPrecioHelper.php';

class ReglasPrecioController {

    public function listar(): void {
        $soloActivas = ($_GET['activas'] ?? '') === '1';
        $db   = DB::get();
        $stmt = $db->prepare(
            $soloActivas
                ? "SELECT id, nombre, porcentaje_recargo, activa FROM reglas_precio WHERE activa = 1 ORDER BY nombre"
                : "SELECT id, nombre, porcentaje_recargo, activa FROM reglas_precio ORDER BY nombre"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['porcentaje_recargo'] = (float)$r['porcentaje_recargo'];
            $r['activa']             = (bool)$r['activa'];
        }
        json(200, $rows);
    }

    public function crear(): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);
        $nombre = trim($body['nombre'] ?? '');
        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);
        if (!is_numeric($body['porcentaje_recargo'] ?? null)) json(400, ['error' => 'porcentaje_recargo es requerido']);

        $db = DB::get();
        $db->prepare("INSERT INTO reglas_precio (nombre, porcentaje_recargo, activa) VALUES (?,?,?)")->execute([
            $nombre,
            (float)$body['porcentaje_recargo'],
            isset($body['activa']) ? (int)(bool)$body['activa'] : 1,
        ]);
        $this->get((int)$db->lastInsertId());
    }

    public function actualizar(int $id): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);
        $nombre = trim($body['nombre'] ?? '');
        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);
        if (!is_numeric($body['porcentaje_recargo'] ?? null)) json(400, ['error' => 'porcentaje_recargo es requerido']);

        $db = DB::get();
        $db->prepare("UPDATE reglas_precio SET nombre=?, porcentaje_recargo=?, activa=? WHERE id=?")->execute([
            $nombre,
            (float)$body['porcentaje_recargo'],
            isset($body['activa']) ? (int)(bool)$body['activa'] : 1,
            $id,
        ]);
        // El % puede haber cambiado — todos los productos que usan esta regla
        // recalculan su precio_venta al instante, no solo los que se editen después.
        ReglasPrecioHelper::recalcularPorRegla($db, $id);
        $this->get($id);
    }

    public function eliminar(int $id): void {
        $db = DB::get();
        $s  = $db->prepare("SELECT COUNT(*) FROM productos WHERE regla_precio_id = ?");
        $s->execute([$id]);
        $c = (int)$s->fetchColumn();
        if ($c > 0) json(409, ['error' => "Esta regla está asignada a $c producto(s). Reasigná antes de eliminar."]);

        $db->prepare("DELETE FROM reglas_precio WHERE id = ?")->execute([$id]);
        json(200, ['ok' => true]);
    }

    private function get(int $id): void {
        $stmt = DB::get()->prepare("SELECT id, nombre, porcentaje_recargo, activa FROM reglas_precio WHERE id = ?");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) json(404, ['error' => 'Regla no encontrada']);
        $r['porcentaje_recargo'] = (float)$r['porcentaje_recargo'];
        $r['activa']             = (bool)$r['activa'];
        json(200, $r);
    }
}
