<?php

class LogAcciones {

    // $uid / $unom permiten pasar el usuario explícitamente (ej: login, donde no hay sesión aún)
    public static function registrar(string $accion, ?string $entidad = null, ?int $entidad_id = null, ?array $detalle = null, ?int $uid = null, ?string $unom = null): void {
        try {
            if ($uid === null) {
                $usuario = Auth::usuarioActual();
                $uid  = $usuario['id']     ?? null;
                $unom = $usuario['nombre'] ?? null;
            }
            $ip     = $_SERVER['REMOTE_ADDR'] ?? null;
            $device = isset($_SERVER['HTTP_X_DEVICE_NAME']) ? substr(trim($_SERVER['HTTP_X_DEVICE_NAME']), 0, 100) : null;
            DB::get()->prepare("
                INSERT INTO log_acciones (fecha, usuario_id, usuario_nom, accion, entidad, entidad_id, detalle, ip, device_name)
                VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $uid,
                $unom,
                $accion,
                $entidad,
                $entidad_id,
                $detalle ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : null,
                $ip,
                $device,
            ]);
        } catch (\Throwable $e) {
            error_log('[LogAcciones] ' . $e->getMessage());
        }
    }
}
