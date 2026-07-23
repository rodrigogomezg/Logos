<?php

class Auth {
    /**
     * Whitelist de claves de permiso granular. Los admin bypasean todo
     * (puede() devuelve true); a los rol 'user' se les evalúa el JSON de
     * usuarios.permisos. Clave ausente = false (default seguro).
     */
    public const PERMISOS = [
        'compras',          // módulo compras completo
        'importar',         // importador de productos / clientes / proveedores
        'productos_editar', // crear/editar productos, ajustar stock, listas de precio
        'costos',           // ver costo, margen, ganancia y rentabilidad
        'reportes',         // dashboard de ventas y libro IVA
        'cc_ver',           // cuentas corrientes y vencimientos
        'cc_cobrar',        // registrar cobros/pagos de CC
        'anular',           // anular ventas y movimientos de caja sin clave
        'log',              // bitácora de acciones
        'cajas_todas',          // operar/filtrar cualquier caja
        'gestionar_vendedores', // crear/editar/desactivar vendedores y ver reportes
    ];

    private static ?array $usuario = null;
    private static bool $cargado = false;
    private static ?bool $modoInstalacion = null;

    /**
     * Identifica al usuario por el token de sesión (header X-Auth-Token).
     * El token se emite en el login y se guarda hasheado en la tabla sesiones.
     */
    public static function usuarioActual(): ?array {
        if (self::$cargado) return self::$usuario;
        self::$cargado = true;

        $token = (string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
        if ($token === '' || strlen($token) > 128) return self::$usuario = null;

        $hash = hash('sha256', $token);
        $stmt = DB::get()->prepare("
            SELECT u.id, u.nombre, u.rol, u.permisos, u.sucursal_ids, s.id AS sesion_id,
                   (s.ultimo_uso < DATE_SUB(NOW(), INTERVAL 10 MINUTE)) AS refrescar
            FROM sesiones s
            JOIN usuarios u ON u.id = s.usuario_id
            WHERE s.token_hash = ? AND s.expira > NOW() AND u.activo = 1
        ");
        $stmt->execute([$hash]);
        $fila = $stmt->fetch();
        if (!$fila) return self::$usuario = null;

        // Expiración deslizante: cada uso extiende la sesión 7 días.
        // Se escribe como mucho cada 10 minutos para no castigar la base.
        if ((int)$fila['refrescar'] === 1) {
            DB::get()->prepare("UPDATE sesiones SET ultimo_uso = NOW(), expira = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?")
                     ->execute([(int)$fila['sesion_id']]);
        }

        return self::$usuario = [
            'id'           => (int)$fila['id'],
            'nombre'       => $fila['nombre'],
            'rol'          => $fila['rol'],
            'permisos'     => json_decode((string)($fila['permisos'] ?? ''), true) ?: [],
            'sucursal_ids' => json_decode((string)($fila['sucursal_ids'] ?? ''), true),
        ];
    }

    /**
     * ¿El usuario actual tiene este permiso granular?
     * Admin (o modo instalación) → siempre sí. Los permisos se leen de la
     * base en cada request, así un cambio aplica al instante sin re-login.
     */
    public static function puede(string $permiso): bool {
        if (self::esAdmin()) return true;
        $u = self::usuarioActual();
        return $u !== null && !empty($u['permisos'][$permiso]);
    }

    public static function requirePermiso(string $permiso): void {
        if (!self::puede($permiso)) {
            json(403, ['error' => "No tenés permiso para esta acción ($permiso). Pedile acceso a un administrador."]);
        }
    }

    /**
     * Modo instalación: mientras el sistema no está completamente configurado
     * (sin admin, sin caja activa o sin negocio), el asistente de instalación
     * necesita operar sin sesión. Una vez configurado, esto queda cerrado.
     */
    public static function modoInstalacion(): bool {
        if (self::$modoInstalacion !== null) return self::$modoInstalacion;
        try {
            $db = DB::get();
            $hayAdmin = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1")->fetchColumn() > 0;
            $hayCaja  = (int)$db->query("SELECT COUNT(*) FROM cajas WHERE activo = 1")->fetchColumn() > 0;
            $negocio  = false;
            try {
                $row = $db->query("SELECT razon_social FROM configuracion WHERE id = 1")->fetch();
                $negocio = $row && trim((string)$row['razon_social']) !== '';
            } catch (\Throwable $e) {}
            return self::$modoInstalacion = !($hayAdmin && $hayCaja && $negocio);
        } catch (\Throwable $e) {
            // Sin base utilizable estamos, por definición, en instalación.
            return self::$modoInstalacion = true;
        }
    }

    public static function esAdmin(): bool {
        $u = self::usuarioActual();
        if ($u !== null && $u['rol'] === 'admin') return true;
        return self::modoInstalacion();
    }

    public static function requireAdmin(): void {
        if (!self::esAdmin()) {
            json(403, ['error' => 'Esta acción requiere permisos de administrador']);
        }
    }

    /** Cierra la sesión del token actual. */
    public static function cerrarSesion(): void {
        $token = (string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
        if ($token === '') return;
        DB::get()->prepare("DELETE FROM sesiones WHERE token_hash = ?")->execute([hash('sha256', $token)]);
    }

    /**
     * ¿El usuario actual tiene acceso a esta sucursal?
     * Admin → siempre sí. User con sucursal_ids=null → sí (todas).
     * User con sucursal_ids=[X,Y] → solo esas.
     */
    public static function sucursalPermitida(int $sucursal_id): bool {
        $u = self::usuarioActual();
        if ($u === null) return false;
        if ($u['rol'] === 'admin') return true;
        $ids = $u['sucursal_ids'] ?? null;
        return $ids === null || in_array($sucursal_id, (array)$ids, true);
    }
}
