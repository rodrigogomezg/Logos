<?php

require_once __DIR__ . '/../config/db.php';

class ClientesController {

    // ── Autocomplete para POS ─────────────────────────────────
    public function search(): void {
        $q     = trim($_GET['q'] ?? '');
        $limit = min((int)($_GET['limit'] ?? 20), 100);
        if ($q === '') json(400, ['error' => 'Parámetro q requerido']);

        $db   = DB::get();
        $like = '%' . $q . '%';
        $stmt = $db->prepare("
            SELECT id, nombre, cuit, condicion_iva, limite_credito, saldo_cuenta_corriente,
                   cc_habilitada, descuento_extra, solo_remito, lista_precio_id, domicilios_envio
            FROM clientes
            WHERE activo = 1 AND (nombre LIKE ? OR cuit LIKE ?)
            ORDER BY nombre LIMIT ?
        ");
        $stmt->execute([$like, $like, $limit]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['limite_credito']         = (float)$r['limite_credito'];
            $r['saldo_cuenta_corriente'] = (float)$r['saldo_cuenta_corriente'];
            $r['cc_habilitada']          = (bool)$r['cc_habilitada'];
            $r['descuento_extra']        = (float)$r['descuento_extra'];
            $r['solo_remito']            = (bool)$r['solo_remito'];
            $r['lista_precio_id']        = $r['lista_precio_id'] ? (int)$r['lista_precio_id'] : null;
            $r['domicilios_envio']       = $r['domicilios_envio'] ? json_decode($r['domicilios_envio'], true) : [];
        }
        json(200, $rows);
    }

    // ── Listado paginado para gestión ─────────────────────────
    public function listar(): void {
        $db     = DB::get();
        $pagina = max(1, (int)($_GET['page'] ?? 1));
        $perPag = min(100, max(10, (int)($_GET['per_page'] ?? 50)));
        $q      = trim($_GET['q'] ?? '');
        $activo = $_GET['activo'] ?? null;
        $offset = ($pagina - 1) * $perPag;

        $where  = [];
        $params = [];

        if ($q !== '') {
            $where[]  = '(c.nombre LIKE ? OR c.cuit LIKE ? OR c.email LIKE ?)';
            $like     = "%$q%";
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($activo !== null && $activo !== '') {
            $where[]  = 'c.activo = ?';
            $params[] = (int)$activo;
        }

        $whereStr = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $cs = $db->prepare("SELECT COUNT(*) FROM clientes c $whereStr");
        $cs->execute($params);
        $total = (int)$cs->fetchColumn();

        $params[] = $perPag;
        $params[] = $offset;
        $stmt = $db->prepare("
            SELECT c.id, c.nombre, c.cuit, c.condicion_iva, c.email, c.telefono,
                   c.domicilio, c.localidad, c.provincia, c.observaciones,
                   c.domicilios_envio,
                   c.activo, c.cc_habilitada, c.limite_credito, c.saldo_cuenta_corriente,
                   c.descuento_extra, c.solo_remito, c.lista_precio_id, c.creado_en,
                   lp.nombre AS lista_precio_nombre
            FROM clientes c
            LEFT JOIN listas_precio lp ON c.lista_precio_id = lp.id
            $whereStr
            ORDER BY c.nombre
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['activo']                 = (bool)$r['activo'];
            $r['cc_habilitada']          = (bool)$r['cc_habilitada'];
            $r['solo_remito']            = (bool)$r['solo_remito'];
            $r['limite_credito']         = (float)$r['limite_credito'];
            $r['saldo_cuenta_corriente'] = (float)$r['saldo_cuenta_corriente'];
            $r['descuento_extra']        = (float)$r['descuento_extra'];
            $r['lista_precio_id']        = $r['lista_precio_id'] ? (int)$r['lista_precio_id'] : null;
            $r['domicilios_envio']       = $r['domicilios_envio'] ? json_decode($r['domicilios_envio'], true) : [];
        }

        json(200, [
            'total'      => $total,
            'paginas'    => max(1, (int)ceil($total / $perPag)),
            'pagina'     => $pagina,
            'por_pagina' => $perPag,
            'datos'      => $rows,
        ]);
    }

    public function get(int $id): void {
        $stmt = DB::get()->prepare("
            SELECT c.id, c.nombre, c.cuit, c.condicion_iva, c.email, c.telefono,
                   c.domicilio, c.localidad, c.provincia, c.observaciones,
                   c.domicilios_envio,
                   c.activo, c.cc_habilitada, c.limite_credito, c.saldo_cuenta_corriente,
                   c.descuento_extra, c.solo_remito, c.lista_precio_id, c.creado_en,
                   lp.nombre AS lista_precio_nombre
            FROM clientes c
            LEFT JOIN listas_precio lp ON c.lista_precio_id = lp.id
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) json(404, ['error' => 'Cliente no encontrado']);

        $r['activo']                 = (bool)$r['activo'];
        $r['cc_habilitada']          = (bool)$r['cc_habilitada'];
        $r['solo_remito']            = (bool)$r['solo_remito'];
        $r['limite_credito']         = (float)$r['limite_credito'];
        $r['saldo_cuenta_corriente'] = (float)$r['saldo_cuenta_corriente'];
        $r['descuento_extra']        = (float)$r['descuento_extra'];
        $r['lista_precio_id']        = $r['lista_precio_id'] ? (int)$r['lista_precio_id'] : null;
        $r['domicilios_envio']       = $r['domicilios_envio'] ? json_decode($r['domicilios_envio'], true) : [];
        json(200, $r);
    }

    public function crear(): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);
        $nombre = trim($body['nombre'] ?? '');
        if ($nombre === '') json(400, ['error' => 'El nombre es requerido']);

        $db = DB::get();
        $db->prepare("
            INSERT INTO clientes
                (nombre, cuit, condicion_iva, email, telefono, domicilio, localidad, provincia,
                 observaciones, domicilios_envio, activo, cc_habilitada, limite_credito, descuento_extra, solo_remito, lista_precio_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute($this->params($body, $nombre));

        $this->get((int)$db->lastInsertId());
    }

    public function put(int $id): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);
        $nombre = trim($body['nombre'] ?? '');
        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);

        $db   = DB::get();
        $stmt = $db->prepare("SELECT id FROM clientes WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) json(404, ['error' => 'Cliente no encontrado']);

        $db->prepare("
            UPDATE clientes SET
                nombre=?, cuit=?, condicion_iva=?, email=?, telefono=?, domicilio=?,
                localidad=?, provincia=?, observaciones=?, domicilios_envio=?, activo=?, cc_habilitada=?,
                limite_credito=?, descuento_extra=?, solo_remito=?, lista_precio_id=?
            WHERE id=?
        ")->execute([...$this->params($body, $nombre), $id]);

        $this->get($id);
    }

    private function params(array $body, string $nombre): array {
        $domiciliosEnvio = null;
        if (isset($body['domicilios_envio']) && is_array($body['domicilios_envio'])) {
            $limpios = array_values(array_filter($body['domicilios_envio'], fn($d) => !empty(trim($d['domicilio'] ?? ''))));
            $domiciliosEnvio = $limpios ? json_encode($limpios, JSON_UNESCAPED_UNICODE) : null;
        }

        return [
            $nombre,
            trim($body['cuit'] ?? '')          ?: null,
            trim($body['condicion_iva'] ?? '')  ?: null,
            trim($body['email'] ?? '')          ?: null,
            trim($body['telefono'] ?? '')       ?: null,
            trim($body['domicilio'] ?? '')      ?: null,
            trim($body['localidad'] ?? '')      ?: null,
            trim($body['provincia'] ?? '')      ?: null,
            trim($body['observaciones'] ?? '')  ?: null,
            $domiciliosEnvio,
            isset($body['activo'])        ? (int)(bool)$body['activo']        : 1,
            isset($body['cc_habilitada']) ? (int)(bool)$body['cc_habilitada'] : 0,
            (float)($body['limite_credito']  ?? 0),
            (float)($body['descuento_extra'] ?? 0),
            isset($body['solo_remito'])   ? (int)(bool)$body['solo_remito']   : 0,
            isset($body['lista_precio_id']) && $body['lista_precio_id'] ? (int)$body['lista_precio_id'] : null,
        ];
    }

    public function eliminar(int $id): void {
        $db = DB::get();

        $s = $db->prepare("SELECT COUNT(*) FROM ventas WHERE cliente_id = ?");
        $s->execute([$id]);
        if ((int)$s->fetchColumn() > 0) {
            json(409, ['error' => 'El cliente tiene ventas registradas. Marcalo como Inactivo en lugar de eliminarlo.']);
        }

        $s = $db->prepare("SELECT COUNT(*) FROM cuenta_corriente_movimientos WHERE entidad_tipo = 'cliente' AND entidad_id = ?");
        $s->execute([$id]);
        if ((int)$s->fetchColumn() > 0) {
            json(409, ['error' => 'El cliente tiene movimientos en cuenta corriente. Marcalo como Inactivo en lugar de eliminarlo.']);
        }

        $db->prepare("DELETE FROM clientes WHERE id = ?")->execute([$id]);
        json(200, ['ok' => true]);
    }

    public function importar(): void {
        if (empty($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            json(400, ['error' => 'No se recibió el archivo CSV']);
        }

        $handle = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$handle) json(500, ['error' => 'No se pudo leer el archivo']);

        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        $header = fgetcsv($handle);
        if (!$header) { fclose($handle); json(400, ['error' => 'Archivo vacío o sin encabezados']); }
        $header = array_map(fn($h) => strtolower(trim($h)), $header);

        $creados = 0; $actualizados = 0; $errores = []; $fila = 1;
        $db = DB::get();

        while (($row = fgetcsv($handle)) !== false) {
            $fila++;
            if (array_filter($row) === []) continue;
            $data = array_combine($header, array_pad($row, count($header), ''));

            $nombre = trim($data['nombre'] ?? '');
            if ($nombre === '') { $errores[] = "Fila $fila: nombre vacío"; continue; }

            $cuit      = trim($data['cuit'] ?? '')          ?: null;
            $condicion = trim($data['condicion_iva'] ?? '') ?: null;
            $email     = trim($data['email'] ?? '')         ?: null;
            $telefono  = trim($data['telefono'] ?? '')      ?: null;
            $domicilio = trim($data['domicilio'] ?? '')     ?: null;
            $localidad = trim($data['localidad'] ?? '')     ?: null;
            $provincia = trim($data['provincia'] ?? '')     ?: null;
            $limite    = (float)str_replace(',', '.', trim($data['limite_credito'] ?? '0') ?: '0');
            $ccHab     = in_array(strtolower(trim($data['cc_habilitada'] ?? '')), ['1','si','sí','yes','true'], true) ? 1 : 0;
            $descuento = (float)str_replace(',', '.', trim($data['descuento_extra'] ?? '0') ?: '0');
            $obs       = trim($data['observaciones'] ?? '') ?: null;

            try {
                $existe = null;
                if ($cuit) {
                    $s = $db->prepare("SELECT id FROM clientes WHERE cuit = ? LIMIT 1");
                    $s->execute([$cuit]);
                    $existe = $s->fetchColumn() ?: null;
                }

                if ($existe) {
                    $db->prepare("
                        UPDATE clientes SET
                            nombre=?, condicion_iva=?, email=?, telefono=?, domicilio=?,
                            localidad=?, provincia=?, limite_credito=?, cc_habilitada=?,
                            descuento_extra=?, observaciones=?
                        WHERE id=?
                    ")->execute([$nombre, $condicion, $email, $telefono, $domicilio,
                                 $localidad, $provincia, $limite, $ccHab, $descuento, $obs, $existe]);
                    $actualizados++;
                } else {
                    $db->prepare("
                        INSERT INTO clientes
                            (nombre, cuit, condicion_iva, email, telefono, domicilio, localidad,
                             provincia, limite_credito, cc_habilitada, descuento_extra, observaciones)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                    ")->execute([$nombre, $cuit, $condicion, $email, $telefono, $domicilio,
                                 $localidad, $provincia, $limite, $ccHab, $descuento, $obs]);
                    $creados++;
                }
            } catch (\Throwable $e) {
                $errores[] = "Fila $fila: " . $e->getMessage();
            }
        }

        fclose($handle);
        json(200, ['creados' => $creados, 'actualizados' => $actualizados, 'errores' => $errores]);
    }

    public function plantillaCSV(): void {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="plantilla_clientes.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['nombre','cuit','condicion_iva','email','telefono','domicilio','localidad','provincia','limite_credito','cc_habilitada','descuento_extra','observaciones']);
        fputcsv($out, ['Empresa Ejemplo SA','20-12345678-9','Responsable Inscripto','contacto@empresa.com','11-4567-8901','Av. Corrientes 1234','Buenos Aires','Buenos Aires','50000','1','10','Cliente mayorista']);
        fclose($out);
        exit;
    }
}
