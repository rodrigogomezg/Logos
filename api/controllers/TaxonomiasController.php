<?php

require_once __DIR__ . '/../config/db.php';

class TaxonomiasController {

    /** Valida tipo y devuelve [tabla, campo_productos] */
    private static function meta(string $tipo): array {
        return match ($tipo) {
            'rubros' => ['rubros', 'categoria'],
            'marcas' => ['marcas', 'marca'],
            default  => json(400, ['error' => 'tipo inválido: debe ser rubros o marcas']) ?: [],
        };
    }

    public function listar(): void {
        $tipo = trim($_GET['tipo'] ?? '');
        [$tabla, $campo] = self::meta($tipo);
        $q = trim($_GET['q'] ?? '');

        $where  = '';
        $params = [];
        if ($q !== '') {
            $where    = 'WHERE t.nombre LIKE ?';
            $params[] = '%' . $q . '%';
        }

        $stmt = DB::get()->prepare("
            SELECT t.id, t.nombre,
                   COUNT(p.id) AS productos
            FROM `$tabla` t
            LEFT JOIN productos p ON p.`$campo` COLLATE utf8mb4_unicode_ci = t.nombre AND p.activo = 1
            $where
            GROUP BY t.id, t.nombre
            ORDER BY t.nombre
        ");
        $stmt->execute($params);
        $filas = $stmt->fetchAll();
        foreach ($filas as &$f) $f['productos'] = (int)$f['productos'];
        unset($f);
        json(200, $filas);
    }

    public function crear(): void {
        $body   = json_decode(file_get_contents('php://input'), true) ?: [];
        $tipo   = trim($body['tipo']   ?? '');
        $nombre = trim($body['nombre'] ?? '');
        [$tabla] = self::meta($tipo);

        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);
        if (strlen($nombre) > 100) json(400, ['error' => 'nombre demasiado largo (máx. 100 caracteres)']);

        try {
            $db = DB::get();
            $db->prepare("INSERT INTO `$tabla` (nombre) VALUES (?)")->execute([$nombre]);
            json(201, ['id' => (int)$db->lastInsertId(), 'nombre' => $nombre, 'productos' => 0]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') json(409, ['error' => "\"$nombre\" ya existe"]);
            throw $e;
        }
    }

    public function bulkCrear(): void {
        $body    = json_decode(file_get_contents('php://input'), true) ?: [];
        $tipo    = trim($body['tipo'] ?? '');
        $nombres = $body['nombres'] ?? [];
        [$tabla] = self::meta($tipo);

        if (!is_array($nombres) || empty($nombres)) json(400, ['error' => 'nombres[] requerido']);

        $nombres = array_values(array_unique(array_filter(
            array_map(fn($n) => mb_substr(trim((string)$n), 0, 100), $nombres),
            fn($n) => $n !== ''
        )));
        if (empty($nombres)) json(400, ['error' => 'No hay nombres válidos']);

        $stmt    = DB::get()->prepare("INSERT IGNORE INTO `$tabla` (nombre) VALUES (?)");
        $creados = 0;
        foreach ($nombres as $n) {
            $stmt->execute([$n]);
            $creados += $stmt->rowCount();
        }
        json(200, ['creados' => $creados, 'omitidos' => count($nombres) - $creados]);
    }

    public function renombrar(int $id): void {
        $body   = json_decode(file_get_contents('php://input'), true) ?: [];
        $tipo   = trim($body['tipo']   ?? '');
        $nombre = trim($body['nombre'] ?? '');
        [$tabla, $campo] = self::meta($tipo);

        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);
        if (strlen($nombre) > 100) json(400, ['error' => 'nombre demasiado largo']);

        $db  = DB::get();
        $old = $db->prepare("SELECT nombre FROM `$tabla` WHERE id = ?");
        $old->execute([$id]);
        $row = $old->fetch();
        if (!$row) json(404, ['error' => 'No encontrado']);

        if ($row['nombre'] === $nombre) json(200, ['ok' => true, 'nombre' => $nombre]);

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE `$tabla` SET nombre = ? WHERE id = ?")->execute([$nombre, $id]);
            $db->prepare("UPDATE productos SET `$campo` = ? WHERE `$campo` = ?")->execute([$nombre, $row['nombre']]);
            $db->commit();
            json(200, ['ok' => true, 'nombre' => $nombre]);
        } catch (PDOException $e) {
            $db->rollBack();
            if ($e->getCode() === '23000') json(409, ['error' => "\"$nombre\" ya existe"]);
            throw $e;
        }
    }

    public function eliminar(int $id): void {
        $tipo = trim($_GET['tipo'] ?? '');
        [$tabla, $campo] = self::meta($tipo);

        $db   = DB::get();
        $stmt = $db->prepare("SELECT nombre FROM `$tabla` WHERE id = ?");
        $stmt->execute([$id]);
        $row  = $stmt->fetch();
        if (!$row) json(404, ['error' => 'No encontrado']);

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE productos SET `$campo` = NULL WHERE `$campo` = ?")->execute([$row['nombre']]);
            $db->prepare("DELETE FROM `$tabla` WHERE id = ?")->execute([$id]);
            $db->commit();
            json(200, ['ok' => true]);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function bulkEliminar(): void {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $tipo = trim($body['tipo'] ?? '');
        $ids  = array_values(array_filter(array_map('intval', $body['ids'] ?? []), fn($v) => $v > 0));
        [$tabla, $campo] = self::meta($tipo);

        if (empty($ids)) json(400, ['error' => 'ids[] requerido']);

        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $db  = DB::get();
        $sel = $db->prepare("SELECT nombre FROM `$tabla` WHERE id IN ($ph)");
        $sel->execute($ids);
        $filas = $sel->fetchAll();
        if (empty($filas)) json(404, ['error' => 'Ninguno encontrado']);

        $db->beginTransaction();
        try {
            foreach ($filas as $f) {
                $db->prepare("UPDATE productos SET `$campo` = NULL WHERE `$campo` = ?")->execute([$f['nombre']]);
            }
            $db->prepare("DELETE FROM `$tabla` WHERE id IN ($ph)")->execute($ids);
            $db->commit();
            json(200, ['ok' => true, 'eliminados' => count($filas)]);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
