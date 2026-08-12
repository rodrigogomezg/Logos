<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Auth.php';
require_once __DIR__ . '/../helpers/LogAcciones.php';

class UsuariosController {

    public function listar(): void {
        $stmt = DB::get()->query("SELECT id, nombre, rol, activo FROM usuarios WHERE activo = 1 ORDER BY rol = 'admin' DESC, nombre");
        $items = $stmt->fetchAll();
        foreach ($items as &$u) { $u['activo'] = (bool)$u['activo']; }
        unset($u);
        json(200, $items);
    }

    public function listarTodos(): void {
        // La ruta la consume también el dashboard (estado de usuarios), así que
        // no puede ser admin-only; pero la matriz de permisos solo viaja a admin.
        $esAdmin = Auth::esAdmin();
        $cols    = $esAdmin ? "id, nombre, rol, permisos, sucursal_ids, activo" : "id, nombre, rol, activo";
        $stmt = DB::get()->query("SELECT $cols FROM usuarios ORDER BY rol = 'admin' DESC, nombre");
        $items = $stmt->fetchAll();
        foreach ($items as &$u) {
            $u['activo'] = (bool)$u['activo'];
            if ($esAdmin) {
                $u['permisos']     = $u['rol'] === 'admin' ? null : (json_decode((string)($u['permisos'] ?? ''), true) ?: []);
                $u['sucursal_ids'] = isset($u['sucursal_ids']) ? json_decode((string)$u['sucursal_ids'], true) : null;
            }
        }
        unset($u);
        json(200, $items);
    }

    /**
     * Valida y normaliza la matriz de permisos del body: solo claves de
     * Auth::PERMISOS, valores booleanos, faltantes completados en false.
     * Devuelve el JSON listo para guardar.
     */
    private function validarPermisos($raw): string {
        if (!is_array($raw)) json(400, ['error' => 'permisos debe ser un objeto {clave: bool}']);
        $out = [];
        foreach ($raw as $k => $v) {
            if (!in_array($k, Auth::PERMISOS, true)) {
                json(400, ['error' => "Permiso desconocido: $k"]);
            }
            $out[$k] = (bool)$v;
        }
        foreach (Auth::PERMISOS as $k) { $out[$k] = $out[$k] ?? false; }
        return json_encode($out);
    }

    public function login(): void {
        $body       = json_decode(file_get_contents('php://input'), true);
        $usuario_id = isset($body['usuario_id']) ? (int)$body['usuario_id'] : 0;
        $pin        = (string)($body['pin'] ?? '');

        if (!$usuario_id || $pin === '') json(400, ['error' => 'usuario_id y pin son requeridos']);

        $db = DB::get();

        // Rate limiting: 5 PIN incorrectos bloquean el usuario por 5 minutos.
        // La comparación se hace en SQL para no depender del reloj de PHP.
        $stmt = $db->prepare("
            SELECT TIMESTAMPDIFF(SECOND, NOW(), bloqueado_hasta) AS faltan_seg
            FROM login_intentos WHERE usuario_id = ?
        ");
        $stmt->execute([$usuario_id]);
        $rl = $stmt->fetch();
        if ($rl && $rl['faltan_seg'] !== null && (int)$rl['faltan_seg'] > 0) {
            $min = (int)ceil((int)$rl['faltan_seg'] / 60);
            json(429, ['error' => "Demasiados intentos fallidos. Esperá $min minuto" . ($min > 1 ? 's' : '') . '.']);
        }

        $stmt = $db->prepare("SELECT id, nombre, pin_hash, rol, permisos, sucursal_ids, activo, debe_cambiar_pin FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        $usuario = $stmt->fetch();

        if (!$usuario || !$usuario['activo'] || !password_verify($pin, $usuario['pin_hash'])) {
            $db->prepare("
                INSERT INTO login_intentos (usuario_id, intentos, bloqueado_hasta, actualizado)
                VALUES (?, 1, NULL, NOW())
                ON DUPLICATE KEY UPDATE
                    -- bloqueado_hasta va primero: MySQL evalúa los SET en orden y
                    -- si intentos se reseteara antes, la condición vería el valor nuevo
                    bloqueado_hasta = IF(intentos + 1 >= 5, DATE_ADD(NOW(), INTERVAL 5 MINUTE), bloqueado_hasta),
                    intentos        = IF(intentos + 1 >= 5, 0, intentos + 1),
                    actualizado     = NOW()
            ")->execute([$usuario_id]);
            json(401, ['error' => 'PIN incorrecto']);
        }

        // Login OK: registrar antes de limpiar contador
        LogAcciones::registrar('login', 'usuario', (int)$usuario['id'], ['rol' => $usuario['rol']], (int)$usuario['id'], $usuario['nombre']);

        $db->prepare("DELETE FROM login_intentos WHERE usuario_id = ?")->execute([$usuario_id]);
        $db->prepare("DELETE FROM sesiones WHERE expira < NOW()")->execute();

        $token       = bin2hex(random_bytes(32));
        $device_name = isset($_SERVER['HTTP_X_DEVICE_NAME']) ? substr(trim($_SERVER['HTTP_X_DEVICE_NAME']), 0, 100) : null;
        $db->prepare("
            INSERT INTO sesiones (token_hash, usuario_id, creado_en, ultimo_uso, expira, device_name)
            VALUES (?, ?, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), ?)
        ")->execute([hash('sha256', $token), (int)$usuario['id'], $device_name]);

        $permisos     = json_decode((string)($usuario['permisos']     ?? ''), true) ?: [];
        $sucursal_ids = json_decode((string)($usuario['sucursal_ids'] ?? ''), true);
        $verTodas = $usuario['rol'] === 'admin' || !empty($permisos['cajas_todas']);

        if ($verTodas) {
            $cajas = $db->query("SELECT id, nombre, tipo, orden, sucursal_id FROM cajas WHERE activo = 1 ORDER BY orden, nombre")->fetchAll();
        } else {
            $cajas = $db->query("SELECT id, nombre, tipo, orden, sucursal_id FROM cajas WHERE activo = 1 AND tipo = 'venta' ORDER BY orden, nombre")->fetchAll();
        }
        // Caja default: los admin arrancan en la caja administrativa ('compra');
        // el resto (incluso con cajas_todas) arranca vendiendo.
        $tipoDefault = $usuario['rol'] === 'admin' ? 'compra' : 'venta';
        $stmt = $db->prepare("SELECT id, nombre FROM cajas WHERE activo = 1 AND tipo = ? ORDER BY orden, nombre LIMIT 1");
        $stmt->execute([$tipoDefault]);
        $caja_default = $stmt->fetch();

        // Sucursales accesibles para este usuario
        if ($sucursal_ids === null || $usuario['rol'] === 'admin') {
            $sucursales = $db->query("
                SELECT s.id, s.nombre, s.nombre_fantasia,
                       (SELECT id FROM depositos WHERE sucursal_id = s.id AND es_principal = 1 LIMIT 1) AS deposito_principal_id
                FROM sucursales s
                WHERE s.activo = 1
                ORDER BY s.nombre
            ")->fetchAll();
        } else {
            $placeholders = implode(',', array_fill(0, count($sucursal_ids), '?'));
            $stmt2 = $db->prepare("
                SELECT s.id, s.nombre, s.nombre_fantasia,
                       (SELECT id FROM depositos WHERE sucursal_id = s.id AND es_principal = 1 LIMIT 1) AS deposito_principal_id
                FROM sucursales s
                WHERE s.activo = 1 AND s.id IN ($placeholders)
                ORDER BY s.nombre
            ");
            $stmt2->execute($sucursal_ids);
            $sucursales = $stmt2->fetchAll();
        }
        foreach ($sucursales as &$s) {
            $s['id']                  = (int)$s['id'];
            $s['deposito_principal_id'] = $s['deposito_principal_id'] ? (int)$s['deposito_principal_id'] : null;
        }

        json(200, [
            'ok'           => true,
            'token'        => $token,
            'usuario'      => [
                'id'               => (int)$usuario['id'],
                'nombre'           => $usuario['nombre'],
                'rol'              => $usuario['rol'],
                'permisos'         => $usuario['rol'] === 'admin' ? null : $permisos,
                'sucursal_ids'     => $sucursal_ids,
                'debe_cambiar_pin' => (bool)$usuario['debe_cambiar_pin'],
            ],
            'cajas'        => $cajas,
            'caja_default' => $caja_default ?: null,
            'sucursales'   => $sucursales,
        ]);
    }

    public function logout(): void {
        Auth::cerrarSesion();
        json(200, ['ok' => true]);
    }

    public function crear(): void {
        $body   = json_decode(file_get_contents('php://input'), true);
        $nombre = trim($body['nombre'] ?? '');
        $pin    = (string)($body['pin'] ?? '');
        $rol    = $body['rol'] ?? 'user';

        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);
        if (!preg_match('/^\d{4,6}$/', $pin)) json(400, ['error' => 'pin debe ser numérico de 4 a 6 dígitos']);
        if (!in_array($rol, ['admin', 'user'], true)) json(400, ['error' => 'rol inválido']);

        // Permisos granulares: admin no los usa (NULL); user arranca con lo que
        // venga en el body o todo en false (plantilla Vendedor).
        $permisos     = null;
        $sucursal_ids = null;
        if ($rol === 'user') {
            $permisos = $this->validarPermisos($body['permisos'] ?? []);
            if (isset($body['sucursal_ids']) && is_array($body['sucursal_ids']) && count($body['sucursal_ids']) > 0) {
                $sucursal_ids = json_encode(array_map('intval', $body['sucursal_ids']));
            }
        }

        $db = DB::get();
        $db->prepare("INSERT INTO usuarios (nombre, pin_hash, rol, permisos, sucursal_ids, activo) VALUES (?, ?, ?, ?, ?, 1)")
           ->execute([$nombre, password_hash($pin, PASSWORD_DEFAULT), $rol, $permisos, $sucursal_ids]);
        $new_id = (int)$db->lastInsertId();

        LogAcciones::registrar('crear_usuario', 'usuario', $new_id, ['nombre' => $nombre, 'rol' => $rol]);

        json(200, ['ok' => true, 'id' => $new_id]);
    }

    /** ¿El usuario es el único admin activo del sistema? */
    private function esUltimoAdmin(int $id): bool {
        $db = DB::get();
        $stmt = $db->prepare("SELECT rol, activo FROM usuarios WHERE id = ?");
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if (!$u || $u['rol'] !== 'admin' || !(int)$u['activo']) return false;
        $otros = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1")->fetchColumn();
        return $otros <= 1;
    }

    public function actualizar(int $id): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);

        $db = DB::get();
        $check = $db->prepare("SELECT id FROM usuarios WHERE id = ?");
        $check->execute([$id]);
        if (!$check->fetch()) json(404, ['error' => 'Usuario no encontrado']);

        $nombre = array_key_exists('nombre', $body) ? trim($body['nombre']) : null;
        $rol    = $body['rol'] ?? null;
        $activo = array_key_exists('activo', $body) ? ($body['activo'] ? 1 : 0) : null;
        $pin    = isset($body['pin']) ? (string)$body['pin'] : '';

        if ($nombre !== null && $nombre === '') json(400, ['error' => 'nombre es requerido']);
        if ($rol !== null && !in_array($rol, ['admin', 'user'], true)) json(400, ['error' => 'rol inválido']);
        if ($pin !== '' && !preg_match('/^\d{4,6}$/', $pin)) json(400, ['error' => 'pin debe ser numérico de 4 a 6 dígitos']);

        // No dejar el sistema sin ningún administrador activo
        if (($rol === 'user' || $activo === 0) && ($rol !== null || $activo !== null) && $this->esUltimoAdmin($id)) {
            json(409, ['error' => 'Es el único administrador activo. Nombrá otro administrador antes de degradarlo o desactivarlo.']);
        }

        // Al desactivar un usuario, matar sus sesiones abiertas
        if ($activo === 0) {
            $db->prepare("DELETE FROM sesiones WHERE usuario_id = ?")->execute([$id]);
        }

        if ($pin !== '') {
            // Un PIN nuevo siempre limpia debe_cambiar_pin — ya sea que lo haya
            // tocado un admin desde Configuración o el propio usuario desde el
            // paso forzado de "PIN de fábrica" en el login (ver migrate/74_fix_admin_semilla.sql).
            $db->prepare("UPDATE usuarios SET nombre = COALESCE(?, nombre), rol = COALESCE(?, rol), activo = COALESCE(?, activo), pin_hash = ?, debe_cambiar_pin = 0 WHERE id = ?")
               ->execute([$nombre, $rol, $activo, password_hash($pin, PASSWORD_DEFAULT), $id]);
        } else {
            $db->prepare("UPDATE usuarios SET nombre = COALESCE(?, nombre), rol = COALESCE(?, rol), activo = COALESCE(?, activo) WHERE id = ?")
               ->execute([$nombre, $rol, $activo, $id]);
        }

        // Permisos granulares: si viene la matriz, validarla y guardarla.
        // Al promover a admin la matriz se limpia (NULL = acceso total implícito).
        if ($rol === 'admin') {
            $db->prepare("UPDATE usuarios SET permisos = NULL, sucursal_ids = NULL WHERE id = ?")->execute([$id]);
        } elseif (array_key_exists('permisos', $body)) {
            $db->prepare("UPDATE usuarios SET permisos = ? WHERE id = ?")
               ->execute([$this->validarPermisos($body['permisos']), $id]);
        }

        // sucursal_ids: null = todas, [] vacío = todas, [1,2] = solo esas
        if ($rol !== 'admin' && array_key_exists('sucursal_ids', $body)) {
            $ids = $body['sucursal_ids'];
            $encoded = (is_array($ids) && count($ids) > 0)
                ? json_encode(array_map('intval', $ids))
                : null;
            $db->prepare("UPDATE usuarios SET sucursal_ids = ? WHERE id = ?")->execute([$encoded, $id]);
        }

        LogAcciones::registrar('editar_usuario', 'usuario', $id, [
            'nombre'       => $nombre,
            'rol'          => $rol,
            'activo'       => $activo,
            'pin_cambiado' => $pin !== '',
        ]);

        json(200, ['ok' => true]);
    }

    public function eliminar(int $id): void {
        $db = DB::get();
        $check = $db->prepare("SELECT id, nombre FROM usuarios WHERE id = ?");
        $check->execute([$id]);
        $usuario_a_eliminar = $check->fetch();
        if (!$usuario_a_eliminar) json(404, ['error' => 'Usuario no encontrado']);

        if ($this->esUltimoAdmin($id)) {
            json(409, ['error' => 'Es el único administrador activo. Nombrá otro administrador antes de eliminarlo.']);
        }

        // Con historial (ventas, turnos, movimientos) no se elimina: se desactiva,
        // para no dejar registros huérfanos sin autor.
        foreach ([['ventas', 'usuario_id'], ['caja_turnos', 'usuario_id'], ['caja_movimientos', 'usuario_id']] as [$tabla, $col]) {
            $s = $db->prepare("SELECT COUNT(*) FROM $tabla WHERE $col = ?");
            $s->execute([$id]);
            if ((int)$s->fetchColumn() > 0) {
                json(409, ['error' => 'El usuario tiene operaciones registradas. Desactivalo en lugar de eliminarlo.']);
            }
        }

        $db->prepare("DELETE FROM sesiones WHERE usuario_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM login_intentos WHERE usuario_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);

        LogAcciones::registrar('eliminar_usuario', 'usuario', $id, ['nombre' => $usuario_a_eliminar['nombre']]);

        json(200, ['ok' => true]);
    }
}
