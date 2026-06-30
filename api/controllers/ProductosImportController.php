<?php

use PhpOffice\PhpSpreadsheet\IOFactory;

class ProductosImportController {

    private const EXT_OK    = ['xlsx', 'xls', 'csv'];
    private const MAX_BYTES = 15 * 1024 * 1024; // 15 MB
    private const DIR_DISCO = __DIR__ . '/../../uploads/importaciones/';

    private const CAMPOS_TEXTO = ['nombre', 'marca', 'categoria', 'subcategoria'];
    private const CAMPOS_NUM   = ['costo_actual', 'precio_venta', 'stock_minimo'];
    private const CAMPOS_TODOS = ['nombre', 'marca', 'categoria', 'subcategoria', 'proveedor', 'costo_actual', 'precio_venta', 'stock_minimo'];

    // ── Paso 1: subir y leer archivo ──────────────────────────────────
    public function leer(): void {
        if (empty($_FILES['archivo'])) json(400, ['error' => 'No se recibió ningún archivo']);
        $proveedor = trim($_POST['proveedor'] ?? '');
        if ($proveedor === '') json(400, ['error' => 'proveedor requerido']);

        $f = $_FILES['archivo'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $msgs = [
                UPLOAD_ERR_INI_SIZE  => 'El archivo supera el límite del servidor',
                UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño permitido',
                UPLOAD_ERR_PARTIAL   => 'La subida fue interrumpida',
                UPLOAD_ERR_NO_FILE   => 'No se seleccionó ningún archivo',
            ];
            json(400, ['error' => $msgs[$f['error']] ?? 'Error al subir el archivo']);
        }
        if ($f['size'] > self::MAX_BYTES) json(400, ['error' => 'El archivo supera el límite de 15 MB']);

        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXT_OK, true)) {
            json(400, ['error' => 'Formato no soportado. Subí un archivo .xlsx, .xls o .csv']);
        }

        if (!is_dir(self::DIR_DISCO)) mkdir(self::DIR_DISCO, 0755, true);
        $token = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest  = self::DIR_DISCO . $token;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            json(500, ['error' => 'No se pudo guardar el archivo en el servidor']);
        }

        set_time_limit(120);
        try {
            $filas = $this->leerFilasArchivo($dest, $ext);
        } catch (\Throwable $e) {
            @unlink($dest);
            json(400, ['error' => 'No se pudo leer el archivo: ' . $e->getMessage()]);
        }

        $filas = array_values(array_filter($filas, fn($r) => !$this->filaVacia($r)));
        if (empty($filas)) {
            @unlink($dest);
            json(400, ['error' => 'El archivo no tiene filas con datos']);
        }

        $maxCols  = max(array_map('count', array_slice($filas, 0, 30)));
        $columnas = [];
        for ($i = 0; $i < $maxCols; $i++) $columnas[] = $this->letraColumna($i);

        $plantilla = $this->buscarPlantilla($proveedor);

        json(200, [
            'token'              => $token,
            'columnas'           => $columnas,
            'primera_fila'       => $filas[0] ?? [],
            'muestra'            => array_slice($filas, 1, 4),
            'total_filas'        => count($filas),
            'mapeo_sugerido'     => $plantilla['mapeo']    ?? null,
            'opciones_sugeridas' => $plantilla['opciones'] ?? null,
        ]);
    }

    // ── Paso 2: vista previa (no toca la base) ────────────────────────
    public function preview(): void {
        $body      = json_decode(file_get_contents('php://input'), true) ?: [];
        $token     = trim($body['token']     ?? '');
        $proveedor = trim($body['proveedor'] ?? '');
        $mapeo     = $body['mapeo']     ?? [];
        $opciones  = $body['opciones']  ?? [];
        if ($token === '' || $proveedor === '') json(400, ['error' => 'token y proveedor son requeridos']);

        $r = $this->procesarArchivo($token, $proveedor, $mapeo, $opciones);

        json(200, [
            'resumen' => [
                'total_filas'    => $r['total_filas'],
                'crear'          => count($r['crear']),
                'actualizar'     => count($r['actualizar']),
                'errores'        => count($r['errores']),
                'discontinuados' => count($r['discontinuados']),
            ],
            'crear'          => $r['crear'],
            'actualizar'     => $r['actualizar'],
            'errores'        => $r['errores'],
            'discontinuados' => $r['discontinuados'],
        ]);
    }

    // ── Paso 3: confirmar (aplica los cambios) ────────────────────────
    public function confirmar(): void {
        $body             = json_decode(file_get_contents('php://input'), true) ?: [];
        $token            = trim($body['token']     ?? '');
        $proveedor        = trim($body['proveedor'] ?? '');
        $mapeo            = $body['mapeo']    ?? [];
        $opciones         = $body['opciones'] ?? [];
        $desactivarIds    = array_values(array_filter(array_map('intval', $body['desactivar_ids'] ?? []), fn($v) => $v > 0));
        $guardarPlantilla = !empty($body['guardar_plantilla']);

        if ($token === '' || $proveedor === '') json(400, ['error' => 'token y proveedor son requeridos']);

        $usuario = Auth::usuarioActual();
        if (!$usuario) json(401, ['error' => 'Usuario no identificado']);

        $resultado = $this->procesarArchivo($token, $proveedor, $mapeo, $opciones);

        $idsDiscontinuados = array_column($resultado['discontinuados'], 'id');
        $desactivarIds      = array_values(array_intersect($desactivarIds, $idsDiscontinuados));
        $discontinuadosPorId = array_column($resultado['discontinuados'], null, 'id');

        $db = DB::get();
        $db->beginTransaction();
        try {
            $stmtLote = $db->prepare("
                INSERT INTO productos_import_lotes (proveedor, archivo, usuario_id, total_filas, creados, actualizados, errores, desactivados, creado_en)
                VALUES (?, ?, ?, ?, 0, 0, 0, 0, NOW())
            ");
            $stmtLote->execute([$proveedor, basename($token), $usuario['id'], $resultado['total_filas']]);
            $loteId = (int)$db->lastInsertId();

            $stmtDet = $db->prepare("
                INSERT INTO productos_import_detalle (lote_id, producto_id, codigo, accion, antes, despues, mensaje)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtIns = $db->prepare("
                INSERT INTO productos (codigo, nombre, marca, categoria, subcategoria, proveedor, precio_venta, costo_actual, stock_actual, stock_minimo, activo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 1)
            ");

            $creados = 0;
            foreach ($resultado['crear'] as $r) {
                $stmtIns->execute([
                    $r['codigo'], $r['nombre'], $r['marca'], $r['categoria'], $r['subcategoria'], $r['proveedor'],
                    $r['precio_venta'], $r['costo_actual'], $r['stock_minimo'],
                ]);
                $nuevoId = (int)$db->lastInsertId();
                $stmtDet->execute([$loteId, $nuevoId, $r['codigo'], 'crear', null, json_encode($r, JSON_UNESCAPED_UNICODE), null]);
                $creados++;
            }

            $actualizados = 0;
            foreach ($resultado['actualizar'] as $r) {
                $set = []; $params = [];
                foreach ($r['despues'] as $campo => $valor) { $set[] = "$campo = ?"; $params[] = $valor; }
                $params[] = $r['id'];
                $db->prepare("UPDATE productos SET " . implode(', ', $set) . " WHERE id = ?")->execute($params);
                $stmtDet->execute([
                    $loteId, $r['id'], $r['codigo'], 'actualizar',
                    json_encode($r['antes'], JSON_UNESCAPED_UNICODE), json_encode($r['despues'], JSON_UNESCAPED_UNICODE), null,
                ]);
                $actualizados++;
            }

            foreach ($resultado['errores'] as $r) {
                $stmtDet->execute([$loteId, null, $r['codigo'], 'error', null, null, $r['mensaje']]);
            }

            $desactivados = 0;
            if (!empty($desactivarIds)) {
                $ph = implode(',', array_fill(0, count($desactivarIds), '?'));
                $db->prepare("UPDATE productos SET activo = 0 WHERE id IN ($ph)")->execute($desactivarIds);
                foreach ($desactivarIds as $pid) {
                    $codigoPid = $discontinuadosPorId[$pid]['codigo'] ?? null;
                    $stmtDet->execute([$loteId, $pid, $codigoPid, 'desactivado', json_encode(['activo' => 1]), json_encode(['activo' => 0]), null]);
                }
                $desactivados = count($desactivarIds);
            }

            $db->prepare("UPDATE productos_import_lotes SET creados = ?, actualizados = ?, errores = ?, desactivados = ? WHERE id = ?")
               ->execute([$creados, $actualizados, count($resultado['errores']), $desactivados, $loteId]);

            if ($guardarPlantilla) {
                $db->prepare("
                    INSERT INTO productos_import_plantillas (proveedor, mapeo, opciones)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE mapeo = VALUES(mapeo), opciones = VALUES(opciones)
                ")->execute([$proveedor, json_encode($mapeo, JSON_UNESCAPED_UNICODE), json_encode($opciones, JSON_UNESCAPED_UNICODE)]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            json(500, ['error' => 'Error al aplicar la importación: ' . $e->getMessage()]);
        }

        [$path] = $this->resolverToken($token);
        @unlink($path);

        json(200, [
            'lote_id' => $loteId,
            'resumen' => [
                'creados'      => $creados,
                'actualizados' => $actualizados,
                'errores'      => count($resultado['errores']),
                'desactivados' => $desactivados,
            ],
        ]);
    }

    // ── Plantillas guardadas ───────────────────────────────────────────
    public function plantilla(string $proveedor): void {
        if ($proveedor === '') json(400, ['error' => 'proveedor requerido']);
        $p = $this->buscarPlantilla($proveedor);
        json(200, $p ?? ['mapeo' => null, 'opciones' => null]);
    }

    // ── Historial ──────────────────────────────────────────────────────
    public function lotes(): void {
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(200, max(10, (int)($_GET['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;

        $db    = DB::get();
        $total = (int)$db->query("SELECT COUNT(*) FROM productos_import_lotes")->fetchColumn();
        $stmt  = $db->prepare("
            SELECT l.*, u.nombre AS usuario_nombre
            FROM productos_import_lotes l
            JOIN usuarios u ON u.id = l.usuario_id
            ORDER BY l.id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$perPage, $offset]);

        json(200, [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int)ceil($total / max(1, $perPage)),
            'items'    => $stmt->fetchAll(),
        ]);
    }

    public function loteDetalle(int $id): void {
        $db   = DB::get();
        $stmt = $db->prepare("SELECT l.*, u.nombre AS usuario_nombre FROM productos_import_lotes l JOIN usuarios u ON u.id = l.usuario_id WHERE l.id = ?");
        $stmt->execute([$id]);
        $lote = $stmt->fetch();
        if (!$lote) json(404, ['error' => 'Importación no encontrada']);

        $stmt = $db->prepare("SELECT * FROM productos_import_detalle WHERE lote_id = ? ORDER BY id");
        $stmt->execute([$id]);
        $detalle = $stmt->fetchAll();
        foreach ($detalle as &$d) {
            $d['antes']   = $d['antes']   !== null ? json_decode($d['antes'], true)   : null;
            $d['despues'] = $d['despues'] !== null ? json_decode($d['despues'], true) : null;
        }
        unset($d);

        json(200, ['lote' => $lote, 'detalle' => $detalle]);
    }

    // ── Procesamiento compartido (preview y confirmar) ────────────────
    private function procesarArchivo(string $token, string $proveedor, array $mapeo, array $opciones): array {
        [$path, $ext] = $this->resolverToken($token);

        $cols = $mapeo['columnas'] ?? [];
        if (!isset($cols['codigo']) || $cols['codigo'] === null || $cols['codigo'] === '') {
            json(400, ['error' => 'Tenés que mapear la columna de Código']);
        }
        $filaInicio = max(1, (int)($mapeo['fila_inicio'] ?? 1));

        $iva       = $opciones['iva'] ?? 'no_aplica';
        $margenPct = isset($opciones['margen_pct']) && $opciones['margen_pct'] !== '' && $opciones['margen_pct'] !== null
                     ? (float)$opciones['margen_pct'] : null;
        $camposUpd = array_values(array_intersect($opciones['campos_actualizar'] ?? [], self::CAMPOS_TODOS));
        $ivaPct    = $this->ivaPorcentaje();

        set_time_limit(180);
        $filas = $this->leerFilasArchivo($path, $ext);

        $db = DB::get();
        $stmtBuscar = $db->prepare("
            SELECT id, nombre, marca, categoria, subcategoria, proveedor, precio_venta, costo_actual, stock_minimo, activo
            FROM productos WHERE codigo = ?
        ");

        $crear = []; $actualizar = []; $errores = []; $vistos = [];

        for ($i = $filaInicio - 1; $i < count($filas); $i++) {
            $row = $filas[$i] ?? null;
            if ($row === null || $this->filaVacia($row)) continue;
            $numFila = $i + 1;

            $codigo = trim((string)($row[$cols['codigo']] ?? ''));
            if ($codigo === '') {
                $errores[] = ['fila' => $numFila, 'codigo' => null, 'mensaje' => 'Falta el código'];
                continue;
            }
            if (isset($vistos[$codigo])) {
                $errores[] = ['fila' => $numFila, 'codigo' => $codigo, 'mensaje' => "Código duplicado en el archivo (ya apareció en la fila {$vistos[$codigo]})"];
                continue;
            }
            $vistos[$codigo] = $numFila;

            $valores = [];
            foreach (self::CAMPOS_TEXTO as $campo) {
                $idx = $cols[$campo] ?? null;
                $valores[$campo] = ($idx !== null && $idx !== '') ? trim((string)($row[$idx] ?? '')) : null;
                if ($valores[$campo] === '') $valores[$campo] = null;
            }
            foreach (self::CAMPOS_NUM as $campo) {
                $idx = $cols[$campo] ?? null;
                $valores[$campo] = ($idx !== null && $idx !== '') ? $this->parsearNumero($row[$idx] ?? null) : null;
            }

            if ($iva === 'sin_iva') {
                if ($valores['costo_actual'] !== null) $valores['costo_actual'] = round($valores['costo_actual'] * (1 + $ivaPct / 100), 2);
                if ($valores['precio_venta'] !== null) $valores['precio_venta'] = round($valores['precio_venta'] * (1 + $ivaPct / 100), 2);
            }
            if ($valores['precio_venta'] === null && $valores['costo_actual'] !== null && $margenPct !== null) {
                $valores['precio_venta'] = round($valores['costo_actual'] * (1 + $margenPct / 100), 2);
            }
            $valores['proveedor'] = $proveedor;

            $stmtBuscar->execute([$codigo]);
            $candidatos = $stmtBuscar->fetchAll();
            $activos    = array_values(array_filter($candidatos, fn($p) => (int)$p['activo'] === 1));

            $existente = null;
            if (count($activos) > 1) {
                $errores[] = ['fila' => $numFila, 'codigo' => $codigo, 'mensaje' => 'Código ambiguo: hay ' . count($activos) . ' productos activos distintos con este código. Resolvé el duplicado a mano en Productos antes de importar.'];
                continue;
            } elseif (count($activos) === 1) {
                $existente = $activos[0];
            } elseif (count($candidatos) === 1) {
                $existente = $candidatos[0]; // único, pero inactivo: se reactiva al actualizar
            } elseif (count($candidatos) > 1) {
                $errores[] = ['fila' => $numFila, 'codigo' => $codigo, 'mensaje' => 'Código ambiguo: hay ' . count($candidatos) . ' productos inactivos con este código y ninguno activo. Resolvé el duplicado a mano en Productos antes de importar.'];
                continue;
            }

            if (!$existente) {
                if (!$valores['nombre']) {
                    $errores[] = ['fila' => $numFila, 'codigo' => $codigo, 'mensaje' => 'Falta el nombre (requerido para crear un producto nuevo)'];
                    continue;
                }
                $crear[] = [
                    'fila' => $numFila, 'codigo' => $codigo,
                    'nombre' => $valores['nombre'], 'marca' => $valores['marca'], 'categoria' => $valores['categoria'],
                    'subcategoria' => $valores['subcategoria'], 'proveedor' => $proveedor,
                    'costo_actual' => $valores['costo_actual'] ?? 0, 'precio_venta' => $valores['precio_venta'] ?? 0,
                    'stock_minimo' => $valores['stock_minimo'] ?? 0,
                ];
                continue;
            }

            $antes = []; $despues = [];
            foreach ($camposUpd as $campo) {
                if (!array_key_exists($campo, $valores) || $valores[$campo] === null) continue;
                $nuevo  = $valores[$campo];
                $actual = $existente[$campo];
                $esNum  = in_array($campo, self::CAMPOS_NUM, true);
                $distinto = $esNum ? (abs((float)$actual - (float)$nuevo) > 0.004) : ((string)$actual !== (string)$nuevo);
                if ($distinto) { $antes[$campo] = $actual; $despues[$campo] = $nuevo; }
            }
            $reactiva = (int)$existente['activo'] === 0;
            if ($reactiva) { $antes['activo'] = 0; $despues['activo'] = 1; }
            if (!empty($despues)) {
                $actualizar[] = ['fila' => $numFila, 'id' => (int)$existente['id'], 'codigo' => $codigo, 'nombre' => $existente['nombre'], 'antes' => $antes, 'despues' => $despues, 'reactiva' => $reactiva];
            }
        }

        $discontinuados = $this->buscarDiscontinuados($proveedor, array_keys($vistos));

        return [
            'total_filas'    => max(0, count($filas) - ($filaInicio - 1)),
            'crear'          => $crear,
            'actualizar'     => $actualizar,
            'errores'        => $errores,
            'discontinuados' => $discontinuados,
        ];
    }

    private function buscarDiscontinuados(string $proveedor, array $codigosVistos): array {
        $stmt = DB::get()->prepare("SELECT id, codigo, nombre FROM productos WHERE proveedor = ? AND activo = 1");
        $stmt->execute([$proveedor]);
        $vistos = array_flip($codigosVistos);
        $out = [];
        foreach ($stmt->fetchAll() as $p) {
            if (!isset($vistos[$p['codigo']])) $out[] = $p;
        }
        return $out;
    }

    private function buscarPlantilla(string $proveedor): ?array {
        $stmt = DB::get()->prepare("SELECT mapeo, opciones FROM productos_import_plantillas WHERE proveedor = ?");
        $stmt->execute([$proveedor]);
        $row = $stmt->fetch();
        if (!$row) return null;
        return ['mapeo' => json_decode($row['mapeo'], true), 'opciones' => json_decode($row['opciones'], true)];
    }

    private function ivaPorcentaje(): float {
        $v = DB::get()->query("SELECT iva_porcentaje FROM configuracion WHERE id = 1")->fetchColumn();
        return $v !== false ? (float)$v : 21.0;
    }

    private function resolverToken(string $token): array {
        $token = basename($token);
        $path  = self::DIR_DISCO . $token;
        if (!is_file($path)) json(400, ['error' => 'El archivo de la importación ya no está disponible. Volvé a subirlo.']);
        $ext = strtolower(pathinfo($token, PATHINFO_EXTENSION));
        return [$path, $ext];
    }

    private function filaVacia(array $row): bool {
        foreach ($row as $v) {
            if ($v !== null && trim((string)$v) !== '') return false;
        }
        return true;
    }

    private function letraColumna(int $i): string {
        $s = ''; $i++;
        while ($i > 0) { $i--; $s = chr(65 + ($i % 26)) . $s; $i = intdiv($i, 26); }
        return $s;
    }

    private function leerFilasArchivo(string $path, string $ext): array {
        if (in_array($ext, ['xlsx', 'xls'], true)) {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
            return $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
        }

        $contenido = file_get_contents($path);
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
        $lineas    = preg_split('/\r\n|\r|\n/', $contenido);
        $primera   = $lineas[0] ?? '';
        $delim     = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';

        $filas = [];
        foreach ($lineas as $linea) {
            if (trim($linea) === '') continue;
            $filas[] = str_getcsv($linea, $delim);
        }
        return $filas;
    }

    // Acepta "1.234,56" (AR), "1234.56" (EN/Excel) o números ya nativos.
    private function parsearNumero($valor): ?float {
        if ($valor === null || $valor === '') return null;
        if (is_int($valor) || is_float($valor)) return (float)$valor;

        $s = trim((string)$valor);
        if ($s === '') return null;
        $s = preg_replace('/[^\d.,\-]/', '', $s);
        if ($s === '' || $s === '-') return null;

        $tieneComa  = strpos($s, ',') !== false;
        $tienePunto = strpos($s, '.') !== false;

        if ($tieneComa && $tienePunto) {
            if (strrpos($s, ',') > strrpos($s, '.')) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($tieneComa) {
            $partes = explode(',', $s);
            $s = (count($partes) === 2 && strlen($partes[1]) <= 2)
                ? str_replace(',', '.', $s)
                : str_replace(',', '', $s);
        } elseif ($tienePunto) {
            $partes = explode('.', $s);
            if (count($partes) > 2 || (count($partes) === 2 && strlen($partes[1]) === 3 && strlen($partes[0]) <= 3)) {
                $s = str_replace('.', '', $s);
            }
        }

        return is_numeric($s) ? (float)$s : null;
    }
}
