<?php

require_once __DIR__ . '/../config/db.php';

class ClientesController {

    public function search(): void {
        $q     = trim($_GET['q'] ?? '');
        $limit = min((int)($_GET['limit'] ?? 20), 100);

        if ($q === '') {
            json(400, ['error' => 'Parámetro q requerido']);
        }

        $db   = DB::get();
        $like = '%' . $q . '%';

        $stmt = $db->prepare("
            SELECT id, nombre, cuit, condicion_iva, limite_credito, saldo_cuenta_corriente
            FROM clientes
            WHERE nombre LIKE ? OR cuit LIKE ?
            ORDER BY nombre
            LIMIT ?
        ");
        $stmt->execute([$like, $like, $limit]);

        json(200, $stmt->fetchAll());
    }

    public function get(int $id): void {
        $db = DB::get();

        $stmt = $db->prepare("
            SELECT id, nombre, cuit, condicion_iva, limite_credito, saldo_cuenta_corriente,
                   email, telefono, domicilio, localidad, provincia, observaciones
            FROM clientes WHERE id = ?
        ");
        $stmt->execute([$id]);
        $cliente = $stmt->fetch();

        if (!$cliente) {
            json(404, ['error' => 'Cliente no encontrado']);
        }

        $cliente['limite_credito']         = (float)$cliente['limite_credito'];
        $cliente['saldo_cuenta_corriente'] = (float)$cliente['saldo_cuenta_corriente'];

        json(200, $cliente);
    }

    public function crear(): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);

        $nombre        = isset($body['nombre'])         ? trim($body['nombre'])         : '';
        $cuit          = isset($body['cuit'])           ? trim($body['cuit'])           : null;
        $condicion     = isset($body['condicion_iva'])  ? trim($body['condicion_iva'])  : null;
        $email         = isset($body['email'])          ? trim($body['email'])          : null;
        $telefono      = isset($body['telefono'])       ? trim($body['telefono'])       : null;
        $domicilio     = isset($body['domicilio'])      ? trim($body['domicilio'])      : null;
        $localidad     = isset($body['localidad'])      ? trim($body['localidad'])      : null;
        $provincia     = isset($body['provincia'])      ? trim($body['provincia'])      : null;
        $limite        = isset($body['limite_credito']) && $body['limite_credito'] !== null
                         ? (float)$body['limite_credito'] : null;
        $observaciones = isset($body['observaciones'])  ? trim($body['observaciones'])  : null;

        if ($nombre === '') json(400, ['error' => 'El nombre es requerido']);

        $db = DB::get();
        $db->prepare("
            INSERT INTO clientes (nombre, cuit, condicion_iva, email, telefono, domicilio, localidad, provincia, limite_credito, observaciones)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $nombre,
            $cuit          ?: null,
            $condicion     ?: null,
            $email         ?: null,
            $telefono      ?: null,
            $domicilio     ?: null,
            $localidad     ?: null,
            $provincia     ?: null,
            $limite,
            $observaciones ?: null,
        ]);

        $id = (int)$db->lastInsertId();

        json(201, [
            'id'                      => $id,
            'nombre'                  => $nombre,
            'cuit'                    => $cuit      ?: null,
            'condicion_iva'           => $condicion ?: null,
            'saldo_cuenta_corriente'  => 0.0,
            'limite_credito'          => $limite ?? 0.0,
        ]);
    }

    public function put(int $id): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);

        $nombre       = isset($body['nombre'])       ? trim($body['nombre'])       : '';
        $cuit         = isset($body['cuit'])         ? trim($body['cuit'])         : null;
        $condicion    = isset($body['condicion_iva']) ? trim($body['condicion_iva']) : null;
        $limite       = isset($body['limite_credito']) && $body['limite_credito'] !== null
                        ? (float)$body['limite_credito'] : null;
        $email        = isset($body['email'])        ? trim($body['email'])        : null;
        $telefono     = isset($body['telefono'])     ? trim($body['telefono'])     : null;
        $domicilio    = isset($body['domicilio'])    ? trim($body['domicilio'])    : null;
        $localidad    = isset($body['localidad'])    ? trim($body['localidad'])    : null;
        $provincia    = isset($body['provincia'])    ? trim($body['provincia'])    : null;
        $observaciones = isset($body['observaciones']) ? trim($body['observaciones']) : null;

        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);

        $db = DB::get();
        $stmt = $db->prepare("SELECT id FROM clientes WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) json(404, ['error' => 'Cliente no encontrado']);

        $db->prepare("
            UPDATE clientes SET
                nombre        = ?,
                cuit          = ?,
                condicion_iva = ?,
                limite_credito = COALESCE(?, limite_credito),
                email         = ?,
                telefono      = ?,
                domicilio     = ?,
                localidad     = ?,
                provincia     = ?,
                observaciones = ?
            WHERE id = ?
        ")->execute([
            $nombre,
            $cuit      ?: null,
            $condicion ?: null,
            $limite,
            $email     ?: null,
            $telefono  ?: null,
            $domicilio ?: null,
            $localidad ?: null,
            $provincia ?: null,
            $observaciones ?: null,
            $id,
        ]);

        json(200, ['ok' => true]);
    }
}
