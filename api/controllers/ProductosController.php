<?php

require_once __DIR__ . '/../config/db.php';

class ProductosController {

    public function search(): void {
        $q         = trim($_GET['q']         ?? '');
        $exacto    = ($_GET['exacto']        ?? '0') === '1';
        $marca     = trim($_GET['marca']     ?? '');
        $proveedor = trim($_GET['proveedor'] ?? '');
        $categoria = trim($_GET['categoria'] ?? '');
        $con_stock = ($_GET['con_stock']     ?? '0') === '1';
        $limit     = min((int)($_GET['limit'] ?? 50), 200);

        // Requiere al menos un criterio
        if ($q === '' && $marca === '' && $proveedor === '' && $categoria === '') {
            json(400, ['error' => 'Ingresá al menos un término de búsqueda o filtro']);
        }

        $db     = DB::get();
        $where  = ['activo = 1'];
        $params = [];

        // Búsqueda por texto
        if ($q !== '') {
            if ($exacto) {
                // Exacto: la frase completa tal cual se escribió, en nombre o código
                $like     = '%' . $q . '%';
                $where[]  = '(nombre LIKE ? OR codigo LIKE ?)';
                $params[] = $like;
                $params[] = $like;
            } else {
                // Similar (default): cada palabra debe estar presente en el nombre o el
                // código, en cualquier orden — permite combinar criterios, ej. "fratacho lijador"
                $palabras     = array_values(array_filter(explode(' ', $q)));
                $condPalabras = implode(' AND ', array_fill(0, count($palabras), '(nombre LIKE ? OR codigo LIKE ?)'));
                $where[] = "($condPalabras)";
                foreach ($palabras as $p) {
                    $params[] = '%' . $p . '%';
                    $params[] = '%' . $p . '%';
                }
            }
        }

        if ($marca     !== '') { $where[] = 'marca = ?';     $params[] = $marca;     }
        if ($proveedor !== '') { $where[] = 'proveedor = ?'; $params[] = $proveedor; }
        if ($categoria !== '') { $where[] = 'categoria = ?'; $params[] = $categoria; }
        if ($con_stock)        { $where[] = 'stock_actual > 0'; }

        // Ordenar priorizando exactitud cuando se busca la frase completa
        if ($q !== '' && $exacto) {
            $order   = 'CASE WHEN codigo = ? THEN 0 WHEN codigo LIKE ? THEN 1 WHEN nombre LIKE ? THEN 2 ELSE 3 END, nombre';
            $params[] = $q;
            $params[] = $q . '%';
            $params[] = $q . '%';
        } else {
            $order = 'nombre';
        }

        $params[] = $limit;

        $sql = "
            SELECT id, codigo, nombre, marca, proveedor, categoria, subcategoria,
                   precio_venta, costo_actual, stock_actual, stock_minimo, activo
            FROM productos
            WHERE " . implode(' AND ', $where) . "
            ORDER BY $order
            LIMIT ?
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        json(200, $stmt->fetchAll());
    }

    public function get(int $id): void {
        $stmt = DB::get()->prepare("
            SELECT id, codigo, nombre, marca, proveedor, categoria, subcategoria,
                   precio_venta, costo_actual, stock_actual, stock_minimo, activo
            FROM productos WHERE id = ?
        ");
        $stmt->execute([$id]);
        $producto = $stmt->fetch();

        if (!$producto) { json(404, ['error' => 'Producto no encontrado']); }

        json(200, $producto);
    }

    public function listar(): void {
        $q         = trim($_GET['q']         ?? '');
        $marca     = trim($_GET['marca']     ?? '');
        $categoria = trim($_GET['categoria'] ?? '');
        $proveedor = trim($_GET['proveedor'] ?? '');
        $orden     = trim($_GET['orden']     ?? 'nombre_asc');
        $page      = max(1, (int)($_GET['page']     ?? 1));
        $per_page  = min(1000, max(20, (int)($_GET['per_page'] ?? 100)));
        $offset    = ($page - 1) * $per_page;

        $where  = ['activo = 1'];
        $params = [];

        if ($q !== '') {
            $like    = '%' . $q . '%';
            $where[] = '(nombre LIKE ? OR codigo LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        if ($marca     !== '') { $where[] = 'marca = ?';     $params[] = $marca;     }
        if ($categoria !== '') { $where[] = 'categoria = ?'; $params[] = $categoria; }
        if ($proveedor !== '') { $where[] = 'proveedor = ?'; $params[] = $proveedor; }

        $whereStr = implode(' AND ', $where);

        $orderMap = [
            'nombre_asc'  => 'nombre ASC',
            'nombre_desc' => 'nombre DESC',
            'precio_asc'  => 'precio_venta ASC, nombre ASC',
            'precio_desc' => 'precio_venta DESC, nombre ASC',
            'costo_asc'   => 'costo_actual ASC, nombre ASC',
            'costo_desc'  => 'costo_actual DESC, nombre ASC',
        ];
        $orderSql = $orderMap[$orden] ?? 'nombre ASC';

        $db = DB::get();

        $stmtC = $db->prepare("SELECT COUNT(*) FROM productos WHERE $whereStr");
        $stmtC->execute($params);
        $total = (int)$stmtC->fetchColumn();

        $stmt = $db->prepare("
            SELECT id, codigo, nombre, marca, categoria, subcategoria, proveedor,
                   precio_venta, costo_actual, stock_minimo, activo
            FROM productos
            WHERE $whereStr
            ORDER BY $orderSql
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($params, [$per_page, $offset]));
        $items = $stmt->fetchAll();

        foreach ($items as &$p) {
            $p['precio_venta'] = (float)$p['precio_venta'];
            $p['costo_actual'] = (float)$p['costo_actual'];
            $p['stock_minimo'] = (float)$p['stock_minimo'];
            $p['activo']       = (bool)$p['activo'];
        }
        unset($p);

        json(200, [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => (int)ceil($total / max(1, $per_page)),
            'items'    => $items,
        ]);
    }

    public function put(int $id): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);

        $nombre       = isset($body['nombre'])       ? trim($body['nombre'])        : '';
        $codigo       = isset($body['codigo'])       ? trim($body['codigo'])        : null;
        $precio       = array_key_exists('precio_venta', $body) && $body['precio_venta'] !== null
                        ? (float)$body['precio_venta'] : null;
        $costo        = array_key_exists('costo_actual', $body) && $body['costo_actual'] !== null
                        ? (float)$body['costo_actual'] : null;
        $marca        = isset($body['marca'])        ? trim($body['marca'])         : null;
        $proveedor    = isset($body['proveedor'])    ? trim($body['proveedor'])     : null;
        $categoria    = isset($body['categoria'])    ? trim($body['categoria'])     : null;
        $subcategoria = isset($body['subcategoria']) ? trim($body['subcategoria'])  : null;
        $stock_min    = array_key_exists('stock_minimo', $body) && $body['stock_minimo'] !== null
                        ? (float)$body['stock_minimo'] : null;
        $activo       = array_key_exists('activo', $body) ? ($body['activo'] ? 1 : 0) : null;

        if ($nombre === '') json(400, ['error' => 'nombre es requerido']);

        $db = DB::get();
        $check = $db->prepare("SELECT id FROM productos WHERE id = ?");
        $check->execute([$id]);
        if (!$check->fetch()) json(404, ['error' => 'Producto no encontrado']);

        $db->prepare("
            UPDATE productos SET
                nombre        = ?,
                codigo        = COALESCE(?, codigo),
                precio_venta  = COALESCE(?, precio_venta),
                costo_actual  = COALESCE(?, costo_actual),
                marca         = ?,
                proveedor     = ?,
                categoria     = ?,
                subcategoria  = ?,
                stock_minimo  = COALESCE(?, stock_minimo),
                activo        = COALESCE(?, activo)
            WHERE id = ?
        ")->execute([
            $nombre,
            $codigo       ?: null,
            $precio,
            $costo,
            $marca        ?: null,
            $proveedor    ?: null,
            $categoria    ?: null,
            $subcategoria ?: null,
            $stock_min,
            $activo,
            $id,
        ]);

        json(200, ['ok' => true]);
    }

    public function eliminar(int $id): void {
        $db = DB::get();
        $check = $db->prepare("SELECT id FROM productos WHERE id = ?");
        $check->execute([$id]);
        if (!$check->fetch()) json(404, ['error' => 'Producto no encontrado']);

        $db->prepare("UPDATE productos SET activo = 0 WHERE id = ?")->execute([$id]);

        json(200, ['ok' => true]);
    }

    public function eliminarBulk(): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);

        $modo = $body['modo'] ?? '';
        if (!in_array($modo, ['ids', 'filtro'], true)) {
            json(400, ['error' => 'modo debe ser ids o filtro']);
        }

        $where  = ['activo = 1'];
        $whereP = [];

        if ($modo === 'ids') {
            $ids = array_values(array_filter(array_map('intval', $body['ids'] ?? []), fn($v) => $v > 0));
            if (empty($ids)) json(400, ['error' => 'Se requieren ids']);
            $ph      = implode(',', array_fill(0, count($ids), '?'));
            $where[] = "id IN ($ph)";
            $whereP  = $ids;
        } else {
            $filtro = $body['filtro'] ?? [];
            $fq     = trim($filtro['q']         ?? '');
            $fm     = trim($filtro['marca']     ?? '');
            $fc     = trim($filtro['categoria'] ?? '');
            $fp     = trim($filtro['proveedor'] ?? '');
            if ($fq !== '') {
                $like     = '%' . $fq . '%';
                $where[]  = '(nombre LIKE ? OR codigo LIKE ?)';
                $whereP[] = $like;
                $whereP[] = $like;
            }
            if ($fm !== '') { $where[] = 'marca = ?';     $whereP[] = $fm; }
            if ($fc !== '') { $where[] = 'categoria = ?'; $whereP[] = $fc; }
            if ($fp !== '') { $where[] = 'proveedor = ?'; $whereP[] = $fp; }
        }

        $whereStr = implode(' AND ', $where);

        $db   = DB::get();
        $stmt = $db->prepare("UPDATE productos SET activo = 0 WHERE $whereStr");
        $stmt->execute($whereP);

        json(200, ['ok' => true, 'afectados' => $stmt->rowCount()]);
    }

    public function bulk(): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);

        $modo    = $body['modo']    ?? '';
        $cambios = $body['cambios'] ?? [];

        if (!in_array($modo, ['ids', 'filtro'], true)) {
            json(400, ['error' => 'modo debe ser ids o filtro']);
        }
        if (empty($cambios)) {
            json(400, ['error' => 'cambios requeridos']);
        }

        $where  = ['activo = 1'];
        $whereP = [];

        if ($modo === 'ids') {
            $ids = array_values(array_filter(array_map('intval', $body['ids'] ?? []), fn($v) => $v > 0));
            if (empty($ids)) json(400, ['error' => 'Se requieren ids']);
            $ph      = implode(',', array_fill(0, count($ids), '?'));
            $where[] = "id IN ($ph)";
            $whereP  = $ids;
        } else {
            $filtro = $body['filtro'] ?? [];
            $fq     = trim($filtro['q']         ?? '');
            $fm     = trim($filtro['marca']     ?? '');
            $fc     = trim($filtro['categoria'] ?? '');
            $fp     = trim($filtro['proveedor'] ?? '');
            if ($fq !== '') {
                $like     = '%' . $fq . '%';
                $where[]  = '(nombre LIKE ? OR codigo LIKE ?)';
                $whereP[] = $like;
                $whereP[] = $like;
            }
            if ($fm !== '') { $where[] = 'marca = ?';     $whereP[] = $fm; }
            if ($fc !== '') { $where[] = 'categoria = ?'; $whereP[] = $fc; }
            if ($fp !== '') { $where[] = 'proveedor = ?'; $whereP[] = $fp; }
        }

        $whereStr = implode(' AND ', $where);
        $set  = [];
        $setP = [];

        foreach (['proveedor', 'marca', 'categoria', 'subcategoria'] as $c) {
            if (array_key_exists($c, $cambios)) {
                $set[]  = "$c = ?";
                $setP[] = trim($cambios[$c]) ?: null;
            }
        }
        if (isset($cambios['precio_pct'])) {
            $pct = (float)$cambios['precio_pct'];
            if ($pct <= -100 || $pct > 10000) json(400, ['error' => 'precio_pct inválido']);
            $set[]  = 'precio_venta = ROUND(precio_venta * ?, 2)';
            $setP[] = 1 + ($pct / 100);
        }

        if (empty($set)) json(400, ['error' => 'No hay cambios válidos']);

        $db   = DB::get();
        $stmt = $db->prepare("UPDATE productos SET " . implode(', ', $set) . " WHERE $whereStr");
        $stmt->execute(array_merge($setP, $whereP));

        json(200, ['ok' => true, 'afectados' => $stmt->rowCount()]);
    }
}
