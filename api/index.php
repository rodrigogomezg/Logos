<?php

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Usuario-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Helper global para responder JSON y terminar
function json(int $status, mixed $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Parsear URI: /BRON/api/productos/123 → ['productos', '123']
$base = '/BRON/api';
$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = substr($uri, strlen($base));
$path = trim($path, '/');
$partes = $path !== '' ? explode('/', $path) : [];

$recurso = $partes[0] ?? '';
$sub     = $partes[1] ?? '';
$id      = is_numeric($sub) ? (int)$sub : null;
$accion  = (!is_numeric($sub) && $sub !== '') ? $sub : null;
$subAccion = $partes[2] ?? null;
$metodo  = $_SERVER['REQUEST_METHOD'];

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/helpers/Auth.php';

try {
    match ($recurso) {
        'instalacion' => (function () use ($metodo, $accion) {
            require_once __DIR__ . '/controllers/InstalacionController.php';
            $ctrl = new InstalacionController();
            match (true) {
                $metodo === 'GET'  && $accion === 'estado'           => $ctrl->estado(),
                $metodo === 'POST' && $accion === 'probar-conexion'   => $ctrl->probarConexion(),
                $metodo === 'POST' && $accion === 'instalar'          => $ctrl->instalar(),
                $metodo === 'POST' && $accion === 'admin'             => $ctrl->crearAdmin(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'productos' => (function () use ($metodo, $id, $accion) {
            require_once __DIR__ . '/controllers/ProductosController.php';
            $ctrl = new ProductosController();
            if ($metodo === 'PUT' || $metodo === 'DELETE' || ($metodo === 'POST' && in_array($accion, ['bulk', 'eliminar-bulk'], true))) {
                Auth::requireAdmin();
            }
            match (true) {
                $metodo === 'GET'    && $id !== null              => $ctrl->get($id),
                $metodo === 'GET'    && isset($_GET['page'])      => $ctrl->listar(),
                $metodo === 'GET'                                 => $ctrl->search(),
                $metodo === 'PUT'    && $id !== null              => $ctrl->put($id),
                $metodo === 'DELETE' && $id !== null              => $ctrl->eliminar($id),
                $metodo === 'POST'   && $accion === 'bulk'        => $ctrl->bulk(),
                $metodo === 'POST'   && $accion === 'eliminar-bulk' => $ctrl->eliminarBulk(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'productos-import' => (function () use ($metodo, $id, $accion) {
            require_once __DIR__ . '/controllers/ProductosImportController.php';
            Auth::requireAdmin();
            $ctrl = new ProductosImportController();
            match (true) {
                $metodo === 'POST' && $accion === 'leer'     => $ctrl->leer(),
                $metodo === 'POST' && $accion === 'preview'  => $ctrl->preview(),
                $metodo === 'POST' && $accion === 'confirmar' => $ctrl->confirmar(),
                $metodo === 'GET'  && $accion === 'plantilla' => $ctrl->plantilla(trim($_GET['proveedor'] ?? '')),
                $metodo === 'GET'  && $id !== null            => $ctrl->loteDetalle($id),
                $metodo === 'GET'                             => $ctrl->lotes(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'clientes' => (function () use ($metodo, $id) {
            require_once __DIR__ . '/controllers/ClientesController.php';
            $ctrl = new ClientesController();
            match (true) {
                $metodo === 'GET'  && $id !== null => $ctrl->get($id),
                $metodo === 'GET'                  => $ctrl->search(),
                $metodo === 'POST' && $id === null => $ctrl->crear(),
                $metodo === 'PUT'  && $id !== null => $ctrl->put($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'proveedores' => (function () use ($metodo, $id) {
            require_once __DIR__ . '/controllers/ProveedoresController.php';
            $ctrl = new ProveedoresController();
            match (true) {
                $metodo === 'GET'  && $id !== null => $ctrl->get($id),
                $metodo === 'GET'                  => $ctrl->search(),
                $metodo === 'POST' && $id === null => $ctrl->crear(),
                $metodo === 'PUT'  && $id !== null => $ctrl->put($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'ventas' => (function () use ($metodo, $id, $accion, $subAccion) {
            require_once __DIR__ . '/controllers/VentasController.php';
            $ctrl = new VentasController();
            match (true) {
                $metodo === 'GET'    && $id !== null && $subAccion === 'comprobante' => $ctrl->comprobante($id),
                $metodo === 'POST'   && $id !== null && $subAccion === 'imprimir'    => $ctrl->imprimir($id),
                $metodo === 'GET'    && $id !== null        => $ctrl->get($id),
                $metodo === 'GET'                           => $ctrl->listar(),
                $metodo === 'POST'   && $accion === 'unificar' => $ctrl->unificar(),
                $metodo === 'POST'   && $id === null        => $ctrl->crear(),
                $metodo === 'PUT'    && $id !== null        => $ctrl->actualizar($id),
                $metodo === 'DELETE' && $id !== null        => $ctrl->eliminar($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'stock' => (function () use ($metodo) {
            require_once __DIR__ . '/controllers/StockController.php';
            $ctrl = new StockController();
            if ($metodo === 'POST') {
                Auth::requireAdmin();
            }
            match (true) {
                $metodo === 'GET'  && isset($_GET['historial']) => $ctrl->historial(),
                $metodo === 'GET'                               => $ctrl->listarProductos(),
                $metodo === 'POST'                              => $ctrl->ajustar(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'compras' => (function () use ($metodo, $id, $accion) {
            Auth::requireAdmin();
            require_once __DIR__ . '/controllers/ComprasController.php';
            $ctrl = new ComprasController();
            match (true) {
                $metodo === 'GET'  && $id !== null            => $ctrl->get($id),
                $metodo === 'GET'                              => $ctrl->listar(),
                $metodo === 'POST' && $accion === 'leer-excel' => $ctrl->leerExcel(),
                $metodo === 'POST' && $id === null             => $ctrl->crear(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'cc' => (function () use ($metodo, $id) {
            require_once __DIR__ . '/controllers/CuentaCorrienteController.php';
            $ctrl = new CuentaCorrienteController();
            match (true) {
                $metodo === 'GET'                          => $ctrl->listar(),
                $metodo === 'POST'                         => $ctrl->registrar(),
                $metodo === 'DELETE' && $id !== null       => $ctrl->eliminar($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'filtros' => (function () use ($metodo) {
            require_once __DIR__ . '/controllers/FiltrosController.php';
            match ($metodo) {
                'GET'   => (new FiltrosController())->get(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'uploads' => (function () use ($metodo) {
            require_once __DIR__ . '/controllers/UploadsController.php';
            match ($metodo) {
                'POST'  => (new UploadsController())->subir(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'configuracion' => (function () use ($metodo, $accion) {
            require_once __DIR__ . '/controllers/ConfiguracionController.php';
            $ctrl = new ConfiguracionController();
            if ($metodo === 'PUT' || ($metodo === 'POST' && $accion !== null)) {
                Auth::requireAdmin();
            }
            match (true) {
                $metodo === 'GET'  && $accion === 'impresoras'      => $ctrl->listarImpresoras(),
                $metodo === 'GET'                                    => $ctrl->get(),
                $metodo === 'PUT'                                    => $ctrl->actualizar(),
                $metodo === 'POST' && $accion === 'logo'             => $ctrl->subirLogo(),
                $metodo === 'POST' && $accion === 'probar-impresion' => $ctrl->probarImpresion(),
                $metodo === 'POST' && $accion === 'backup'           => $ctrl->backupAhora(),
                $metodo === 'POST' && $accion === 'reset-fabrica'    => $ctrl->resetFabrica(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'usuarios' => (function () use ($metodo, $id, $accion) {
            require_once __DIR__ . '/controllers/UsuariosController.php';
            $ctrl = new UsuariosController();
            $hayUsuarios = (int)DB::get()->query("SELECT COUNT(*) FROM usuarios")->fetchColumn() > 0;
            if ($metodo === 'PUT' || $metodo === 'DELETE' || ($metodo === 'POST' && $id === null && $accion === null && $hayUsuarios)) {
                Auth::requireAdmin();
            }
            match (true) {
                $metodo === 'POST'   && $accion === 'login' => $ctrl->login(),
                $metodo === 'GET'    && $accion === 'todos' => $ctrl->listarTodos(),
                $metodo === 'GET'    && $id === null        => $ctrl->listar(),
                $metodo === 'POST'   && $id === null        => $ctrl->crear(),
                $metodo === 'PUT'    && $id !== null        => $ctrl->actualizar($id),
                $metodo === 'DELETE' && $id !== null        => $ctrl->eliminar($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'cajas' => (function () use ($metodo, $id) {
            require_once __DIR__ . '/controllers/CajasController.php';
            $ctrl = new CajasController();
            if ($metodo === 'POST' || $metodo === 'PUT' || $metodo === 'DELETE') {
                Auth::requireAdmin();
            }
            match (true) {
                $metodo === 'GET'                    => $ctrl->listar(),
                $metodo === 'POST'   && $id === null  => $ctrl->crear(),
                $metodo === 'PUT'    && $id !== null  => $ctrl->actualizar($id),
                $metodo === 'DELETE' && $id !== null  => $ctrl->eliminar($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'caja-turnos' => (function () use ($metodo, $id, $accion, $subAccion) {
            require_once __DIR__ . '/controllers/CajaTurnosController.php';
            $ctrl = new CajaTurnosController();
            match (true) {
                $metodo === 'GET'    && $accion === 'actual'                     => $ctrl->actual(),
                $metodo === 'GET'    && $id !== null                             => $ctrl->get($id),
                $metodo === 'GET'                                                => $ctrl->listar(),
                $metodo === 'POST'   && $id === null                            => $ctrl->abrir(),
                $metodo === 'POST'   && $id !== null && $subAccion === 'cerrar' => $ctrl->cerrar($id),
                $metodo === 'DELETE' && $id !== null                            => $ctrl->eliminar($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'caja-movimientos' => (function () use ($metodo, $id) {
            require_once __DIR__ . '/controllers/CajaMovimientosController.php';
            $ctrl = new CajaMovimientosController();
            match (true) {
                $metodo === 'GET'    => $ctrl->listar(),
                $metodo === 'POST'   => $ctrl->crear(),
                $metodo === 'DELETE' && $id !== null => $ctrl->eliminar($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        default => json(404, ['error' => "Recurso '$recurso' no existe"]),
    };
} catch (PDOException $e) {
    json(500, ['error' => 'Error de base de datos: ' . $e->getMessage()]);
} catch (Throwable $e) {
    json(500, ['error' => $e->getMessage()]);
}
