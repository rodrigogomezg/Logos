<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/Auth.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

class ComprasController {

    private const TIPOS_PAGO_SIMPLES = ['efectivo', 'transferencia', 'tarjeta', 'cc'];
    private const EXT_OK    = ['xlsx', 'xls', 'csv'];
    private const MAX_BYTES = 15 * 1024 * 1024;
    private const DIR_DISCO = __DIR__ . '/../../uploads/compras/';

    public function listar(): void {
        $where  = ['1=1'];
        $params = [];

        $fecha_desde  = $_GET['fecha_desde'] ?? '';
        $fecha_hasta  = $_GET['fecha_hasta']  ?? '';
        $proveedor_id = isset($_GET['proveedor_id']) && is_numeric($_GET['proveedor_id'])
                        ? (int)$_GET['proveedor_id'] : null;
        $limit        = min((int)($_GET['limit']  ?? 50), 200);
        $offset       = max((int)($_GET['offset'] ?? 0),  0);

        if ($fecha_desde)  { $where[] = 'c.fecha >= ?';      $params[] = $fecha_desde;  }
        if ($fecha_hasta)  { $where[] = 'c.fecha <= ?';      $params[] = $fecha_hasta;  }
        if ($proveedor_id) { $where[] = 'c.proveedor_id = ?'; $params[] = $proveedor_id; }

        $params[] = $limit;
        $params[] = $offset;

        $stmt = DB::get()->prepare("
            SELECT c.id, LPAD(c.id, 8, '0') AS numero, c.fecha, c.total, c.estado,
                   c.tipo_comprobante, c.numero_comprobante, c.tipo_pago,
                   p.id AS proveedor_id, p.nombre AS proveedor_nombre
            FROM compras c
            LEFT JOIN proveedores p ON p.id = c.proveedor_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY c.fecha DESC, c.id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        json(200, $stmt->fetchAll());
    }

    public function get(int $id): void {
        $db = DB::get();

        $stmt = $db->prepare("
            SELECT c.id, LPAD(c.id, 8, '0') AS numero, c.fecha, c.total, c.estado,
                   c.tipo_comprobante, c.numero_comprobante, c.subtotal, c.iva_monto,
                   c.percepcion_iibb_porcentaje, c.percepcion_iibb_monto, c.tipo_pago,
                   pr.id AS proveedor_id, pr.nombre AS proveedor_nombre
            FROM compras c
            LEFT JOIN proveedores pr ON pr.id = c.proveedor_id
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
        $compra = $stmt->fetch();
        if (!$compra) json(404, ['error' => 'Compra no encontrada']);

        $stmt = $db->prepare("
            SELECT ci.id, ci.cantidad, ci.costo_unitario, ci.iva_porcentaje, ci.iva_monto,
                   (ci.cantidad * ci.costo_unitario) AS subtotal,
                   p.id AS producto_id, p.nombre AS producto_nombre, p.codigo AS producto_codigo
            FROM compra_items ci
            LEFT JOIN productos p ON p.id = ci.producto_id
            WHERE ci.compra_id = ?
            ORDER BY ci.id
        ");
        $stmt->execute([$id]);
        $compra['items'] = $stmt->fetchAll();

        foreach ($compra['items'] as &$item) {
            $item['cantidad']       = (float)$item['cantidad'];
            $item['costo_unitario'] = (float)$item['costo_unitario'];
            $item['iva_porcentaje'] = (float)$item['iva_porcentaje'];
            $item['iva_monto']      = (float)$item['iva_monto'];
            $item['subtotal']       = (float)$item['subtotal'];
        }

        $stmt = $db->prepare("SELECT tipo_pago, monto FROM compra_pagos WHERE compra_id = ? ORDER BY id");
        $stmt->execute([$id]);
        $compra['pagos'] = $stmt->fetchAll();
        foreach ($compra['pagos'] as &$p) { $p['monto'] = (float)$p['monto']; }

        $compra['total']                      = (float)$compra['total'];
        $compra['subtotal']                   = (float)$compra['subtotal'];
        $compra['iva_monto']                  = (float)$compra['iva_monto'];
        $compra['percepcion_iibb_porcentaje'] = $compra['percepcion_iibb_porcentaje'] !== null ? (float)$compra['percepcion_iibb_porcentaje'] : null;
        $compra['percepcion_iibb_monto']      = (float)$compra['percepcion_iibb_monto'];

        json(200, $compra);
    }

    // Valida el array de pagos de un pago mixto: cada línea con tipo simple + monto > 0,
    // y que la suma coincida con el total de la compra.
    private function validarPagosMixto(array $pagosRaw, float $total): array {
        if (count($pagosRaw) < 2) {
            json(400, ['error' => 'Un pago mixto necesita al menos 2 formas de pago']);
        }
        $pagos = [];
        $suma  = 0.0;
        foreach ($pagosRaw as $i => $p) {
            $tipo  = $p['tipo']  ?? null;
            $monto = isset($p['monto']) && is_numeric($p['monto']) ? (float)$p['monto'] : null;
            if (!in_array($tipo, self::TIPOS_PAGO_SIMPLES, true) || $monto === null || $monto <= 0) {
                json(400, ['error' => 'Pago mixto inválido en la línea ' . ($i + 1)]);
            }
            $pagos[] = ['tipo' => $tipo, 'monto' => $monto];
            $suma   += $monto;
        }
        if (abs($suma - $total) > 0.01) {
            json(400, ['error' => 'La suma de los pagos ($' . number_format($suma, 2) . ') no coincide con el total ($' . number_format($total, 2) . ')']);
        }
        return $pagos;
    }

    public function crear(): void {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) json(400, ['error' => 'Body JSON inválido']);

        $items        = $body['items'] ?? [];
        $proveedor_id = isset($body['proveedor_id']) && is_numeric($body['proveedor_id']) ? (int)$body['proveedor_id'] : null;
        $tipo_comprobante  = $body['tipo_comprobante'] ?? null;
        $numero_comprobante = isset($body['numero_comprobante']) ? trim($body['numero_comprobante']) : null;
        $fecha        = isset($body['fecha']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['fecha']) ? $body['fecha'] : date('Y-m-d');
        $tipo_pago    = $body['tipo_pago'] ?? 'efectivo';
        $caja_id      = isset($body['caja_id']) && is_numeric($body['caja_id']) ? (int)$body['caja_id'] : null;
        $usuario_id   = Auth::usuarioActual()['id'] ?? (isset($body['usuario_id']) && is_numeric($body['usuario_id']) ? (int)$body['usuario_id'] : null);
        $percepcion_pct = isset($body['percepcion_iibb_porcentaje']) && is_numeric($body['percepcion_iibb_porcentaje']) && $body['percepcion_iibb_porcentaje'] > 0
                          ? (float)$body['percepcion_iibb_porcentaje'] : null;

        if (empty($items)) json(400, ['error' => 'La compra debe tener al menos un ítem']);
        if (!$proveedor_id) json(400, ['error' => 'proveedor_id es requerido']);

        $tiposComprobanteValidos = ['factura_a', 'factura_b', 'remito'];
        if (!in_array($tipo_comprobante, $tiposComprobanteValidos, true)) {
            json(400, ['error' => 'tipo_comprobante inválido. Valores: ' . implode(', ', $tiposComprobanteValidos)]);
        }

        $tiposPagoValidos = [...self::TIPOS_PAGO_SIMPLES, 'mixto'];
        if (!in_array($tipo_pago, $tiposPagoValidos, true)) {
            json(400, ['error' => 'tipo_pago inválido. Valores: ' . implode(', ', $tiposPagoValidos)]);
        }

        $db = DB::get();

        $stmt = $db->prepare("SELECT id, nombre FROM proveedores WHERE id = ?");
        $stmt->execute([$proveedor_id]);
        $proveedor = $stmt->fetch();
        if (!$proveedor) json(404, ['error' => 'Proveedor no encontrado']);

        // Cargar y validar ítems
        $items_data = [];
        $subtotal   = 0.0;
        $iva_total  = 0.0;

        foreach ($items as $i => $item) {
            $producto_id    = (int)($item['producto_id']    ?? 0);
            $cantidad       = (float)($item['cantidad']       ?? 0);
            $costo_unitario = (float)($item['costo_unitario'] ?? 0);

            if (!$producto_id || $cantidad <= 0 || $costo_unitario < 0) {
                json(400, ['error' => "Ítem $i inválido"]);
            }

            $stmt = $db->prepare("SELECT id, nombre, iva_porcentaje FROM productos WHERE id = ?");
            $stmt->execute([$producto_id]);
            $producto = $stmt->fetch();
            if (!$producto) json(404, ['error' => "Producto $producto_id no encontrado"]);

            $iva_porcentaje = isset($item['iva_porcentaje']) && is_numeric($item['iva_porcentaje'])
                              ? (float)$item['iva_porcentaje'] : (float)$producto['iva_porcentaje'];
            if ($iva_porcentaje < 0 || $iva_porcentaje > 100) {
                json(400, ['error' => "iva_porcentaje inválido en ítem $i"]);
            }

            $sub_item  = $cantidad * $costo_unitario;
            $iva_item  = $sub_item * $iva_porcentaje / 100;
            $subtotal += $sub_item;
            $iva_total += $iva_item;

            $items_data[] = [
                'producto_id'    => $producto_id,
                'cantidad'       => $cantidad,
                'costo_unitario' => $costo_unitario,
                'iva_porcentaje' => $iva_porcentaje,
                'iva_monto'      => $iva_item,
                'iva_previo'     => (float)$producto['iva_porcentaje'],
            ];
        }

        $percepcion_monto = $percepcion_pct !== null ? $subtotal * $percepcion_pct / 100 : 0.0;
        $total = $subtotal + $iva_total + $percepcion_monto;

        $pagos_mixto = [];
        if ($tipo_pago === 'mixto') {
            $pagos_mixto = $this->validarPagosMixto($body['pagos'] ?? [], $total);
        }

        $monto_cc = $tipo_pago === 'cc'
            ? $total
            : ($tipo_pago === 'mixto'
                ? array_sum(array_map(fn($p) => $p['tipo'] === 'cc' ? $p['monto'] : 0.0, $pagos_mixto))
                : 0.0);

        // Montos no-CC desglosados por medio (para generar los retiros de caja)
        $montos_no_cc = ['efectivo' => 0.0, 'transferencia' => 0.0, 'tarjeta' => 0.0];
        if ($tipo_pago === 'mixto') {
            foreach ($pagos_mixto as $p) {
                if ($p['tipo'] !== 'cc') $montos_no_cc[$p['tipo']] += $p['monto'];
            }
        } elseif ($tipo_pago !== 'cc') {
            $montos_no_cc[$tipo_pago] = $total;
        }
        $monto_no_cc = array_sum($montos_no_cc);

        $turno_id = null;
        if ($monto_no_cc > 0) {
            if (!$caja_id) json(400, ['error' => 'caja_id es requerido cuando hay un pago en efectivo/transferencia/tarjeta']);
            if (!$usuario_id) json(400, ['error' => 'No se pudo identificar al usuario que registra la compra']);

            $stmt = $db->prepare("SELECT id FROM cajas WHERE id = ?");
            $stmt->execute([$caja_id]);
            if (!$stmt->fetch()) json(404, ['error' => 'Caja no encontrada']);

            $stmt = $db->prepare("SELECT id FROM caja_turnos WHERE caja_id = ? AND estado = 'abierto'");
            $stmt->execute([$caja_id]);
            $turno = $stmt->fetch();
            if (!$turno) json(409, ['error' => 'No hay un turno abierto en esa caja. Abrila antes de registrar el pago.']);
            $turno_id = (int)$turno['id'];
        }

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("
                INSERT INTO compras
                    (fecha, proveedor_id, total, estado, caja_id, usuario_id,
                     tipo_comprobante, numero_comprobante, subtotal, iva_monto,
                     percepcion_iibb_porcentaje, percepcion_iibb_monto, tipo_pago)
                VALUES (?, ?, ?, 'completado', ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $fecha, $proveedor_id, $total, $caja_id, $usuario_id,
                $tipo_comprobante, $numero_comprobante, $subtotal, $iva_total,
                $percepcion_pct, $percepcion_monto, $tipo_pago,
            ]);
            $compra_id = (int)$db->lastInsertId();

            foreach ($items_data as $item) {
                $db->prepare("
                    INSERT INTO compra_items (compra_id, producto_id, cantidad, costo_unitario, iva_porcentaje, iva_monto)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([
                    $compra_id, $item['producto_id'], $item['cantidad'], $item['costo_unitario'],
                    $item['iva_porcentaje'], $item['iva_monto'],
                ]);

                $db->prepare("UPDATE productos SET stock_actual = stock_actual + ?, costo_actual = ? WHERE id = ?")
                   ->execute([$item['cantidad'], $item['costo_unitario'], $item['producto_id']]);

                if (abs($item['iva_porcentaje'] - $item['iva_previo']) > 0.001) {
                    $db->prepare("UPDATE productos SET iva_porcentaje = ? WHERE id = ?")
                       ->execute([$item['iva_porcentaje'], $item['producto_id']]);
                }

                $db->prepare("INSERT INTO movimientos_stock (producto_id, tipo, cantidad, referencia_id, fecha) VALUES (?, 'compra', ?, ?, NOW())")
                   ->execute([$item['producto_id'], $item['cantidad'], $compra_id]);
            }

            if ($tipo_pago === 'mixto') {
                foreach ($pagos_mixto as $p) {
                    $db->prepare("INSERT INTO compra_pagos (compra_id, tipo_pago, monto) VALUES (?, ?, ?)")
                       ->execute([$compra_id, $p['tipo'], $p['monto']]);
                }
            }

            if ($monto_cc > 0) {
                $db->prepare("
                    INSERT INTO cuenta_corriente_movimientos (entidad_tipo, entidad_id, tipo, monto, referencia_id, fecha)
                    VALUES ('proveedor', ?, 'cargo', ?, ?, CURDATE())
                ")->execute([$proveedor_id, $monto_cc, $compra_id]);

                $db->prepare("UPDATE proveedores SET saldo_cuenta_corriente = saldo_cuenta_corriente + ? WHERE id = ?")
                   ->execute([$monto_cc, $proveedor_id]);
            }

            foreach ($montos_no_cc as $medio => $monto) {
                if ($monto <= 0) continue;
                $db->prepare("
                    INSERT INTO caja_movimientos (turno_id, tipo, medio_pago, monto, motivo, usuario_id)
                    VALUES (?, 'retiro', ?, ?, ?, ?)
                ")->execute([$turno_id, $medio, $monto, 'Compra #' . $compra_id . ' - ' . $proveedor['nombre'], $usuario_id]);
            }

            $db->commit();
            json(201, [
                'id'          => $compra_id,
                'numero'      => str_pad($compra_id, 8, '0', STR_PAD_LEFT),
                'total'       => $total,
                'proveedor_id' => $proveedor_id,
            ]);

        } catch (Throwable $e) {
            $db->rollBack();
            json(500, ['error' => $e->getMessage()]);
        }
    }

    // ── Carga rápida por Excel/CSV (3 columnas: código, cantidad, costo) ──
    public function leerExcel(): void {
        if (empty($_FILES['archivo'])) json(400, ['error' => 'No se recibió ningún archivo']);

        $f = $_FILES['archivo'];
        if ($f['error'] !== UPLOAD_ERR_OK) json(400, ['error' => 'Error al subir el archivo']);
        if ($f['size'] > self::MAX_BYTES) json(400, ['error' => 'El archivo supera el límite de 15 MB']);

        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXT_OK, true)) {
            json(400, ['error' => 'Formato no soportado. Subí un archivo .xlsx, .xls o .csv']);
        }

        if (!is_dir(self::DIR_DISCO)) mkdir(self::DIR_DISCO, 0755, true);
        $dest = self::DIR_DISCO . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            json(500, ['error' => 'No se pudo guardar el archivo en el servidor']);
        }

        try {
            $filas = $this->leerFilasArchivo($dest, $ext);
        } catch (\Throwable $e) {
            @unlink($dest);
            json(400, ['error' => 'No se pudo leer el archivo: ' . $e->getMessage()]);
        }
        @unlink($dest);

        $filas = array_values(array_filter($filas, fn($r) => trim((string)($r[0] ?? '')) !== ''));
        if (empty($filas)) json(400, ['error' => 'El archivo no tiene filas con datos']);

        // Si la primera fila no tiene cantidad/costo numéricos, se asume encabezado y se descarta.
        $primeraCant  = $this->parsearNumero($filas[0][1] ?? null);
        $primeraCosto = $this->parsearNumero($filas[0][2] ?? null);
        if ($primeraCant === null || $primeraCosto === null) {
            array_shift($filas);
        }

        $db = DB::get();
        $resueltas = [];
        $errores   = [];

        foreach ($filas as $i => $fila) {
            $numFila = $i + 1;
            $codigo   = trim((string)($fila[0] ?? ''));
            $cantidad = $this->parsearNumero($fila[1] ?? null);
            $costo    = $this->parsearNumero($fila[2] ?? null);

            if ($codigo === '') continue;
            if ($cantidad === null || $cantidad <= 0 || $costo === null || $costo < 0) {
                $errores[] = ['fila' => $numFila, 'codigo' => $codigo, 'mensaje' => 'Cantidad o costo inválido'];
                continue;
            }

            $stmt = $db->prepare("SELECT id, nombre, codigo, iva_porcentaje, activo FROM productos WHERE codigo = ?");
            $stmt->execute([$codigo]);
            $candidatos = $stmt->fetchAll();
            $activos    = array_values(array_filter($candidatos, fn($p) => (int)$p['activo'] === 1));

            $producto = null;
            if (count($activos) > 1) {
                $errores[] = ['fila' => $numFila, 'codigo' => $codigo, 'mensaje' => 'Código ambiguo: hay varios productos activos con este código'];
                continue;
            } elseif (count($activos) === 1) {
                $producto = $activos[0];
            } elseif (count($candidatos) >= 1) {
                $errores[] = ['fila' => $numFila, 'codigo' => $codigo, 'mensaje' => 'No se encontró un producto activo con este código'];
                continue;
            } else {
                $errores[] = ['fila' => $numFila, 'codigo' => $codigo, 'mensaje' => 'Código no encontrado'];
                continue;
            }

            $resueltas[] = [
                'producto_id'    => (int)$producto['id'],
                'producto_nombre' => $producto['nombre'],
                'codigo'         => $producto['codigo'],
                'cantidad'       => $cantidad,
                'costo_unitario' => $costo,
                'iva_porcentaje' => (float)$producto['iva_porcentaje'],
            ];
        }

        json(200, ['filas' => $resueltas, 'errores' => $errores]);
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
