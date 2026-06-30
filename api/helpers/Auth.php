<?php

class Auth {
    private static ?array $usuario = null;
    private static bool $cargado = false;

    public static function usuarioActual(): ?array {
        if (self::$cargado) return self::$usuario;
        self::$cargado = true;

        $id = $_SERVER['HTTP_X_USUARIO_ID'] ?? null;
        if (!$id || !ctype_digit((string)$id)) return self::$usuario = null;

        $stmt = DB::get()->prepare("SELECT id, nombre, rol FROM usuarios WHERE id = ? AND activo = 1");
        $stmt->execute([(int)$id]);
        return self::$usuario = ($stmt->fetch() ?: null);
    }

    public static function esAdmin(): bool {
        $u = self::usuarioActual();
        return $u !== null && $u['rol'] === 'admin';
    }

    public static function requireAdmin(): void {
        if (!self::esAdmin()) {
            json(403, ['error' => 'Esta acción requiere permisos de administrador']);
        }
    }
}
