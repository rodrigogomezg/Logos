<?php

require_once __DIR__ . '/../config/db.php';

class ProveedoresController {

    public function search(): void {
        $q     = trim($_GET['q'] ?? '');
        $limit = min((int)($_GET['limit'] ?? 20), 100);
        if ($q === '') json(400, ['error' => 'Parámetro q requerido']);

        $db   = DB::get();
        $like = '%' . $q . '%';
        $stmt = $db->prepare("
            SELECT id, nombre, cuit, condicion_iva, saldo_cuenta_corriente, cc_habilitada, limite_credito
            FROM proveedores
            WHERE activo = 1 AND (nombre LIKE ? OR cuit LIKE ?)
            ORDER BY nombre LIMIT ?
        ");
        $stmt->execute([$like, $like, $limit]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['saldo_cuenta_corriente'] = (float)$r['saldo_cuenta_corriente'];
            $r['cc_habilitada']          = (bool)$r['cc_habilitada'];
            $r['limite_credito']         = (float)$r['limite_credito'];
        }
        json(200, $rows);
    }

    public function listar(): void {
        $db     = DB::get();
        $pagina = max(1, (int)($_GET['page'] ?? 1));
        $perPag = min(100, max(10, (int)($_GET['per_page'] ?? 50)));
        $q      = trim($_GET['q'] ?? '');
        $activo = $_GET['activo'] ?? null;
        $orden  = $_GET['orden']  ?? 'nombre';
        $ccOnly = !empty($_GET['cc_only']);
        $offset = ($pagina - 1) * $perPag;

        $where  = [];
        $params = [];

        if ($q !== '') {
            $where[]  = '(p.nombre LIKE ? OR p.cuit LIKE ? OR p.email LIKE ?)';
            $like     = "%$q%";
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($activo !== null && $activo !== '') {
            $where[]  = 'p.activo = ?';
            $params[] = (int)$activo;
        }
        if ($ccOnly) {
            $where[] = 'p.cc_habilitada = 1';
        }

        $whereStr = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $orderBy = match($orden) {
            'saldo'         => 'ABS(p.saldo_cuenta_corriente) DESC, p.nombre',
            'ultima_compra' => 'uc.ult_fecha DESC, p.nombre',
            default         => 'p.nombre',
        };

        $cs = $db->prepare("SELECT COUNT(*) FROM proveedores p $whereStr");
        $cs->execute($params);
        $total = (int)$cs->fetchColumn();

        $params[] = $perPag;
        $params[] = $offset;
        $stmt = $db->prepare("
            SELECT p.id, p.nombre, p.cuit, p.condicion_iva, p.email, p.telefono,
                   p.domicilio, p.localidad, p.provincia, p.observaciones,
                   p.activo, p.cc_habilitada, p.limite_credito, p.saldo_cuenta_corriente,
                   p.plazo_pago_dias, p.lista_precio_id, p.creado_en,
                   lp.nombre AS lista_precio_nombre,
                   uc.ult_fecha
            FROM proveedores p
            LEFT JOIN listas_precio lp ON p.lista_precio_id = lp.id
            LEFT JOIN (SELECT proveedor_id, MAX(fecha) AS ult_fecha FROM compras GROUP BY proveedor_id) AS uc
                   ON uc.proveedor_id = p.id
            $whereStr
            ORDER BY $orderBy
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['activo']                 = (bool)$r['activo'];
            $r['cc_habilitada']          = (bool)$r['cc_habilitada'];
            $r['limite_credito']         = (float)$r['limite_credito'];
            $r['saldo_cuenta_corriente'] = (float)$r['saldo_cuenta_corriente'];
            $r['lista_precio_id']        = $r['lista_precio_id'] ? (int)$r['lista_precio_id'] : null;
            $r['plazo_pago_dias']        = $r['plazo_pago_dias'] ? (int)$r['plazo_pago_dias'] : null;
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
            SELECT p.id, p.nombre, p.cuit, p.condicion_iva, p.email, p.telefono,
                   p.domicilio, p.localidad, p.provincia, p.observaciones,
                   p.activo, p.cc_habilitada, p.limite_credito, p.saldo_cuenta_corriente,
                   p.plazo_pago_dias, p.lista_precio_id, p.creado_en,
                   lp.nombre AS lista_precio_nombre
            FROM proveedores p
            LEFT JOIN listas_precio lp ON p.lista_precio_id = lp.id
            WHERE p.id = ?
        ");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) json(404, ['error' => 'Proveedor no encontrado']);

        $r['activo']                 = (bool)$r['activo'];
        $r['cc_habilitada']          = (bool)$r['cc_habilitada'];
        $r['limite_credito']         = (float)$r['limite_credito'];
        $r['saldo_cuenta_corriente'] = (float)$r['saldo_cuenta_corriente'];
        $r['lista_precio_id']        = $r['lista_precio_id'] ? (int)$r['lista_precio_id'] : null;
        $r['plazo_pago_dias']        = $r['plazo_pago_dias'] ? (int)$r['plazo_pago_dias'] : null;
        json(200, $r);
    }

    public function crear(): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);
        $nombre = trim($body['nombre'] ?? '');
        if ($nombre === '') json(400, ['error' => 'El nombre es requerido']);

        $db = DB::get();
        $db->prepare("
            INSERT INTO proveedores
                (nombre, cuit, condicion_iva, email, telefono, domicilio, localidad, provincia,
                 observaciones, activo, cc_habilitada, limite_credito, plazo_pago_dias, lista_precio_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute($this->params($body, $nombre));

        $this->get((int)$db->lastInsertId());
    }

    public function put(int $id): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);
        $nombre = trim($body['nombre'] ?? '');
        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);

        $db   = DB::get();
        $stmt = $db->prepare("SELECT id FROM proveedores WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) json(404, ['error' => 'Proveedor no encontrado']);

        $db->prepare("
            UPDATE proveedores SET
                nombre=?, cuit=?, condicion_iva=?, email=?, telefono=?, domicilio=?,
                localidad=?, provincia=?, observaciones=?, activo=?, cc_habilitada=?,
                limite_credito=?, plazo_pago_dias=?, lista_precio_id=?
            WHERE id=?
        ")->execute([...$this->params($body, $nombre), $id]);

        $this->get($id);
    }

    private function params(array $body, string $nombre): array {
        return [
            $nombre,
            trim($body['cuit'] ?? '')         ?: null,
            trim($body['condicion_iva'] ?? '') ?: null,
            trim($body['email'] ?? '')         ?: null,
            trim($body['telefono'] ?? '')      ?: null,
            trim($body['domicilio'] ?? '')     ?: null,
            trim($body['localidad'] ?? '')     ?: null,
            trim($body['provincia'] ?? '')     ?: null,
            trim($body['observaciones'] ?? '') ?: null,
            isset($body['activo'])        ? (int)(bool)$body['activo']        : 1,
            isset($body['cc_habilitada']) ? (int)(bool)$body['cc_habilitada'] : 0,
            (float)($body['limite_credito'] ?? 0),
            isset($body['plazo_pago_dias']) && $body['plazo_pago_dias'] !== '' && $body['plazo_pago_dias'] !== null
                ? (int)$body['plazo_pago_dias'] : null,
            isset($body['lista_precio_id']) && $body['lista_precio_id']
                ? (int)$body['lista_precio_id'] : null,
        ];
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
            $ccHab     = in_array(strtolower(trim($data['cc_habilitada'] ?? '')), ['1','si','sí','yes','true'], true) ? 1 : 0;
            $limite    = (float)str_replace(',', '.', trim($data['limite_credito'] ?? '0') ?: '0');
            $plazo     = trim($data['plazo_pago_dias'] ?? '') !== '' ? (int)$data['plazo_pago_dias'] : null;
            $obs       = trim($data['observaciones'] ?? '') ?: null;

            try {
                $existe = null;
                if ($cuit) {
                    $s = $db->prepare("SELECT id FROM proveedores WHERE cuit = ? LIMIT 1");
                    $s->execute([$cuit]);
                    $existe = $s->fetchColumn() ?: null;
                }

                if ($existe) {
                    $db->prepare("
                        UPDATE proveedores SET
                            nombre=?, condicion_iva=?, email=?, telefono=?, domicilio=?,
                            localidad=?, provincia=?, cc_habilitada=?, limite_credito=?,
                            plazo_pago_dias=?, observaciones=?
                        WHERE id=?
                    ")->execute([$nombre, $condicion, $email, $telefono, $domicilio,
                                 $localidad, $provincia, $ccHab, $limite, $plazo, $obs, $existe]);
                    $actualizados++;
                } else {
                    $db->prepare("
                        INSERT INTO proveedores
                            (nombre, cuit, condicion_iva, email, telefono, domicilio, localidad,
                             provincia, cc_habilitada, limite_credito, plazo_pago_dias, observaciones)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                    ")->execute([$nombre, $cuit, $condicion, $email, $telefono, $domicilio,
                                 $localidad, $provincia, $ccHab, $limite, $plazo, $obs]);
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
        header('Content-Disposition: attachment; filename="plantilla_proveedores.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['nombre','cuit','condicion_iva','email','telefono','domicilio','localidad','provincia','cc_habilitada','limite_credito','plazo_pago_dias','observaciones']);
        fputcsv($out, ['Proveedor Ejemplo SA','30-12345678-9','Responsable Inscripto','ventas@proveedor.com','11-5678-9012','Av. Industrial 500','Córdoba','Córdoba','1','0','30','Proveedor mayorista']);
        fclose($out);
        exit;
    }

    public function eliminar(int $id): void {
        $db = DB::get();

        $s = $db->prepare("SELECT COUNT(*) FROM compras WHERE proveedor_id = ?");
        $s->execute([$id]);
        if ((int)$s->fetchColumn() > 0) {
            json(409, ['error' => 'El proveedor tiene compras registradas. Marcalo como Inactivo en lugar de eliminarlo.']);
        }

        $s = $db->prepare("SELECT COUNT(*) FROM cuenta_corriente_movimientos WHERE entidad_tipo = 'proveedor' AND entidad_id = ?");
        $s->execute([$id]);
        if ((int)$s->fetchColumn() > 0) {
            json(409, ['error' => 'El proveedor tiene movimientos en cuenta corriente. Marcalo como Inactivo en lugar de eliminarlo.']);
        }

        $db->prepare("DELETE FROM proveedores WHERE id = ?")->execute([$id]);
        json(200, ['ok' => true]);
    }
}
