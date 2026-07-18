<?php

require_once __DIR__ . '/../helpers/Auth.php';

class LogAccionesController {

    public function listar(): void {
        Auth::requireAdmin();

        $donde  = [];
        $params = [];

        if (!empty($_GET['desde']))      { $donde[] = 'fecha >= ?';              $params[] = $_GET['desde'] . ' 00:00:00'; }
        if (!empty($_GET['hasta']))      { $donde[] = 'fecha <= ?';              $params[] = $_GET['hasta'] . ' 23:59:59'; }
        if (!empty($_GET['accion']))     { $donde[] = 'accion = ?';              $params[] = $_GET['accion']; }
        if (!empty($_GET['usuario_id'])) { $donde[] = 'usuario_id = ?';         $params[] = (int)$_GET['usuario_id']; }
        if (!empty($_GET['equipo']))     { $donde[] = 'device_name LIKE ?';     $params[] = '%' . $_GET['equipo'] . '%'; }

        $where  = $donde ? 'WHERE ' . implode(' AND ', $donde) : '';
        $limit  = min((int)($_GET['limit'] ?? 200), 1000);
        $params[] = $limit;

        $stmt = DB::get()->prepare("
            SELECT id, fecha, usuario_id, usuario_nom, accion, entidad, entidad_id, detalle, ip, device_name
            FROM log_acciones
            $where
            ORDER BY fecha DESC, id DESC
            LIMIT ?
        ");
        $stmt->execute($params);
        json(200, $stmt->fetchAll());
    }
}
