<?php

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

// El negocio opera en Argentina. Sin esto PHP hereda el timezone del php.ini
// (Europe/Berlin en XAMPP) y las ventas nocturnas quedarían fechadas al día
// siguiente, incluso ante AFIP.
date_default_timezone_set('America/Argentina/Buenos_Aires');

// La app es same-origin (el POS y la API viven en el mismo servidor).
// No se emiten headers CORS a propósito: así ninguna página web ajena
// puede hacer requests a la API desde un navegador de la red.
header('Content-Type: application/json; charset=utf-8');

// Helper global para responder JSON y terminar
function json(int $status, mixed $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Parsear URI: /Logos/api/productos/123 → ['productos', '123']
$base = '/Logos/api';
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
require_once __DIR__ . '/helpers/Seguridad.php';
Seguridad::agregarHeadersSeguridad();

// ── Gate global de autenticación ──────────────────────────────────
// Todo requiere sesión válida salvo lo que necesita la pantalla de login
// y el asistente de instalación. Auth::modoInstalacion() abre el paso solo
// mientras el sistema no está completamente configurado.
$esRutaPublica =
    $recurso === 'instalacion' ||
    ($recurso === 'usuarios'      && $metodo === 'POST' && $accion === 'login') ||
    ($recurso === 'usuarios'      && $metodo === 'GET'  && $id === null && $accion === null) ||
    ($recurso === 'configuracion' && $metodo === 'GET'  && $accion === null) ||
    ($recurso === 'mercadopago'   && $metodo === 'POST' && $sub === 'webhook') ||
    ($recurso === 'licencia'      && $metodo === 'POST' && $accion === 'verificar') ||
    ($recurso === 'backup'        && $metodo === 'POST' && $accion === 'programado') ||
    ($recurso === 'configuracion' && $metodo === 'GET'  && $accion === 'logo');

try {
    if (!$esRutaPublica && Auth::usuarioActual() === null && !Auth::modoInstalacion()) {
        json(401, ['error' => 'Sesión inválida o vencida. Iniciá sesión de nuevo.']);
    }
} catch (PDOException $e) {
    json(500, ['error' => 'Error de base de datos al validar la sesión.']);
}

try {
    match ($recurso) {
        'instalacion' => (function () use ($metodo, $accion) {
            require_once __DIR__ . '/controllers/InstalacionController.php';
            $ctrl = new InstalacionController();
            match (true) {
                $metodo === 'GET'  && $accion === 'estado'           => $ctrl->estado(),
                $metodo === 'GET'  && $accion === 'info'             => $ctrl->info(),
                $metodo === 'GET'  && $accion === 'admin-id'         => $ctrl->adminId(),
                $metodo === 'POST' && $accion === 'probar-conexion'   => $ctrl->probarConexion(),
                $metodo === 'POST' && $accion === 'instalar'          => $ctrl->instalar(),
                $metodo === 'POST' && $accion === 'admin'             => $ctrl->crearAdmin(),
                $metodo === 'POST' && $accion === 'afip-csr'          => $ctrl->generarCsrAfip(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'productos' => (function () use ($metodo, $id, $accion) {
            require_once __DIR__ . '/controllers/ProductosController.php';
            $ctrl = new ProductosController();
            if ($metodo === 'PUT' || $metodo === 'DELETE' ||
                ($metodo === 'POST' && in_array($accion, ['bulk', 'eliminar-bulk'], true)) ||
                ($metodo === 'POST' && $id === null && $accion === null)) {
                Auth::requirePermiso('productos_editar');
            }
            match (true) {
                $metodo === 'GET'    && $id !== null && $accion === 'escalas' => $ctrl->getEscalas($id),
                $metodo === 'PUT'    && $id !== null && $accion === 'escalas' => $ctrl->saveEscalas($id),
                $metodo === 'GET'    && $id !== null              => $ctrl->get($id),
                $metodo === 'GET'    && isset($_GET['page'])      => $ctrl->listar(),
                $metodo === 'GET'                                 => $ctrl->search(),
                $metodo === 'POST'   && $id === null && $accion === null => $ctrl->post(),
                $metodo === 'PUT'    && $id !== null              => $ctrl->put($id),
                $metodo === 'DELETE' && $id !== null              => $ctrl->eliminar($id),
                $metodo === 'POST'   && $accion === 'bulk'        => $ctrl->bulk(),
                $metodo === 'POST'   && $accion === 'eliminar-bulk' => $ctrl->eliminarBulk(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'productos-import' => (function () use ($metodo, $id, $accion) {
            require_once __DIR__ . '/controllers/ProductosImportController.php';
            Auth::requirePermiso('importar');
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

        'clientes' => (function () use ($metodo, $id, $accion) {
            require_once __DIR__ . '/controllers/ClientesController.php';
            $ctrl = new ClientesController();
            if ($metodo === 'DELETE') Auth::requireAdmin();
            if ($metodo === 'POST' && $accion === 'importar') Auth::requirePermiso('importar');
            match (true) {
                $metodo === 'GET'  && $id !== null                      => $ctrl->get($id),
                $metodo === 'GET'  && isset($_GET['page'])              => $ctrl->listar(),
                $metodo === 'GET'  && $accion === 'plantilla-csv'       => $ctrl->plantillaCSV(),
                $metodo === 'GET'                                        => $ctrl->search(),
                $metodo === 'POST' && $accion === 'importar'            => $ctrl->importar(),
                $metodo === 'POST' && $id === null                      => $ctrl->crear(),
                $metodo === 'PUT'  && $id !== null                      => $ctrl->put($id),
                $metodo === 'DELETE' && $id !== null                    => $ctrl->eliminar($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'proveedores' => (function () use ($metodo, $id, $accion) {
            require_once __DIR__ . '/controllers/ProveedoresController.php';
            $ctrl = new ProveedoresController();
            if ($metodo === 'DELETE') Auth::requireAdmin();
            if ($metodo === 'POST' && $accion === 'importar') Auth::requirePermiso('importar');
            match (true) {
                $metodo === 'GET'  && $id !== null                      => $ctrl->get($id),
                $metodo === 'GET'  && isset($_GET['page'])              => $ctrl->listar(),
                $metodo === 'GET'  && $accion === 'plantilla-csv'       => $ctrl->plantillaCSV(),
                $metodo === 'GET'                                        => $ctrl->search(),
                $metodo === 'POST' && $accion === 'importar'            => $ctrl->importar(),
                $metodo === 'POST' && $id === null                      => $ctrl->crear(),
                $metodo === 'PUT'  && $id !== null                      => $ctrl->put($id),
                $metodo === 'DELETE' && $id !== null                    => $ctrl->eliminar($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'listas-precio' => (function () use ($metodo, $id) {
            if ($metodo === 'POST' || $metodo === 'PUT' || $metodo === 'DELETE') {
                Auth::requirePermiso('productos_editar');
            }
            require_once __DIR__ . '/controllers/ListasPrecioController.php';
            $ctrl = new ListasPrecioController();
            match (true) {
                $metodo === 'GET'                    => $ctrl->listar(),
                $metodo === 'POST'   && $id === null => $ctrl->crear(),
                $metodo === 'PUT'    && $id !== null => $ctrl->actualizar($id),
                $metodo === 'DELETE' && $id !== null => $ctrl->eliminar($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'reglas-precio' => (function () use ($metodo, $id) {
            if ($metodo === 'POST' || $metodo === 'PUT' || $metodo === 'DELETE') {
                Auth::requireAdmin();
            }
            require_once __DIR__ . '/controllers/ReglasPrecioController.php';
            $ctrl = new ReglasPrecioController();
            match (true) {
                $metodo === 'GET'                    => $ctrl->listar(),
                $metodo === 'POST'   && $id === null => $ctrl->crear(),
                $metodo === 'PUT'    && $id !== null => $ctrl->actualizar($id),
                $metodo === 'DELETE' && $id !== null => $ctrl->eliminar($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'ventas' => (function () use ($metodo, $id, $accion, $subAccion) {
            require_once __DIR__ . '/controllers/VentasController.php';
            $ctrl = new VentasController();
            // DELETE no se gatea acá: el controller decide (permiso 'anular',
            // o clave de autorización para quien no lo tiene).
            if ($metodo === 'GET' && $accion === 'dashboard') {
                Auth::requirePermiso('reportes');
            }
            match (true) {
                $metodo === 'GET'    && $id !== null && $subAccion === 'comprobante'   => $ctrl->comprobante($id),
                $metodo === 'POST'   && $id !== null && $subAccion === 'imprimir'     => $ctrl->imprimir($id),
                $metodo === 'POST'   && $id !== null && $subAccion === 'facturar'             => $ctrl->facturar($id),
                $metodo === 'POST'   && $id !== null && $subAccion === 'confirmar-presupuesto' => $ctrl->confirmarPresupuesto($id),
                $metodo === 'POST'   && $id !== null && $subAccion === 'nota-credito' => $ctrl->emitirNc($id),
                $metodo === 'POST'   && $id !== null && $subAccion === 'recuperar'    => $ctrl->recuperar($id),
                $metodo === 'GET'    && $id !== null && $subAccion === 'afip-estado'  => $ctrl->consultarAfip($id),
                $metodo === 'GET'    && $accion === 'export'    => $ctrl->exportar(),
                $metodo === 'GET'    && $accion === 'dashboard' => $ctrl->dashboard(),
                $metodo === 'GET'    && $id !== null            => $ctrl->get($id),
                $metodo === 'GET'                               => $ctrl->listar(),
                $metodo === 'POST'   && $accion === 'unificar'  => $ctrl->unificar(),
                $metodo === 'POST'   && $id === null            => $ctrl->crear(),
                $metodo === 'PUT'    && $id !== null            => $ctrl->actualizar($id),
                $metodo === 'DELETE' && $id !== null            => $ctrl->eliminar($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'stock' => (function () use ($metodo, $accion) {
            require_once __DIR__ . '/controllers/StockController.php';
            $ctrl = new StockController();
            if ($metodo === 'POST') {
                Auth::requirePermiso('productos_editar');
            }
            match (true) {
                $metodo === 'GET'  && $accion === 'alertas'      => $ctrl->alertas(),
                $metodo === 'GET'  && isset($_GET['historial'])   => $ctrl->historial(),
                $metodo === 'GET'                                  => $ctrl->listarProductos(),
                $metodo === 'POST'                                 => $ctrl->ajustar(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'compras' => (function () use ($metodo, $id, $accion) {
            Auth::requirePermiso('compras');
            require_once __DIR__ . '/controllers/ComprasController.php';
            $ctrl = new ComprasController();
            match (true) {
                $metodo === 'GET'  && $id !== null            => $ctrl->get($id),
                $metodo === 'GET'                              => $ctrl->listar(),
                $metodo === 'POST'   && $accion === 'leer-excel' => $ctrl->leerExcel(),
                $metodo === 'POST'   && $id === null            => $ctrl->crear(),
                $metodo === 'PUT'    && $id !== null            => $ctrl->editar($id),
                $metodo === 'DELETE' && $id !== null            => $ctrl->anular($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'cc' => (function () use ($metodo, $id, $accion, $subAccion) {
            require_once __DIR__ . '/controllers/CuentaCorrienteController.php';
            $ctrl = new CuentaCorrienteController();
            if ($metodo === 'GET')    Auth::requirePermiso('cc_ver');
            if ($metodo === 'POST')   Auth::requirePermiso('cc_cobrar');
            if ($metodo === 'DELETE') Auth::requireAdmin();
            match (true) {
                $metodo === 'GET' && $id !== null && $subAccion === 'recibo-pdf' => $ctrl->reciboPdf($id),
                $metodo === 'GET' && $accion === 'aging'   => $ctrl->aging(),
                $metodo === 'GET'                          => $ctrl->listar(),
                $metodo === 'POST'                         => $ctrl->registrar(),
                $metodo === 'DELETE' && $id !== null       => $ctrl->eliminar($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'reservas' => (function () use ($metodo, $id) {
            require_once __DIR__ . '/controllers/ReservasController.php';
            $ctrl = new ReservasController();
            match (true) {
                $metodo === 'POST'                    => $ctrl->sincronizar(),
                $metodo === 'DELETE' && $id !== null  => $ctrl->liberar((string)$id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'devoluciones' => (function () use ($metodo, $id) {
            require_once __DIR__ . '/controllers/DevolucionesController.php';
            $ctrl = new DevolucionesController();
            match (true) {
                $metodo === 'GET'  && $id !== null             => $ctrl->listarUno($id),
                $metodo === 'GET'  && isset($_GET['venta_id']) => $ctrl->listarPorVenta(),
                $metodo === 'POST'                             => $ctrl->crear(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'notas-envio' => (function () use ($metodo, $id, $subAccion) {
            require_once __DIR__ . '/controllers/NotasEnvioController.php';
            $ctrl = new NotasEnvioController();
            match (true) {
                $metodo === 'GET'  && $id !== null && $subAccion === 'pdf' => $ctrl->pdf($id),
                $metodo === 'GET'  && isset($_GET['venta_id'])             => $ctrl->listarPorVenta((int)$_GET['venta_id']),
                $metodo === 'POST' && $id === null                         => $ctrl->crear(),
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
                $metodo === 'GET'  && $accion === 'logo'            => $ctrl->logo(),
                $metodo === 'GET'  && $accion === 'puntos-venta-afip' => $ctrl->puntosVentaAfip(),
                $metodo === 'GET'                                    => $ctrl->get(),
                $metodo === 'PUT'                                    => $ctrl->actualizar(),
                $metodo === 'POST' && $accion === 'cert-afip'         => $ctrl->subirCertAfip(),
                $metodo === 'POST' && $accion === 'afip-csr'          => $ctrl->generarCsrAfip(),
                $metodo === 'POST' && $accion === 'logo'             => $ctrl->subirLogo(),
                $metodo === 'POST' && $accion === 'probar-impresion' => $ctrl->probarImpresion(),
                $metodo === 'POST' && $accion === 'backup'           => $ctrl->backupAhora(),
                $metodo === 'POST' && $accion === 'reset-fabrica'    => $ctrl->resetFabrica(),
                $metodo === 'POST' && $accion === 'smtp'              => $ctrl->guardarSmtp(),
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
                $metodo === 'POST'   && $accion === 'login'  => $ctrl->login(),
                $metodo === 'POST'   && $accion === 'logout' => $ctrl->logout(),
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
                $metodo === 'GET'    && $accion === 'actual'                              => $ctrl->actual(),
                $metodo === 'GET'    && $id !== null && $subAccion === 'periodo'          => $ctrl->periodo($id),
                $metodo === 'GET'    && $id !== null                                      => $ctrl->get($id),
                $metodo === 'GET'                                                         => $ctrl->listar(),
                $metodo === 'POST'   && $id === null                                      => $ctrl->abrir(),
                $metodo === 'POST'   && $id !== null && $subAccion === 'cierre-parcial'  => $ctrl->cierreParcial($id),
                $metodo === 'POST'   && $id !== null && $subAccion === 'cerrar'           => $ctrl->cerrar($id),
                $metodo === 'DELETE' && $id !== null                                      => $ctrl->eliminar($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'caja-movimientos' => (function () use ($metodo, $id) {
            require_once __DIR__ . '/controllers/CajaMovimientosController.php';
            $ctrl = new CajaMovimientosController();
            if ($metodo === 'DELETE') {
                Auth::requirePermiso('anular');
            }
            match (true) {
                $metodo === 'GET'    => $ctrl->listar(),
                $metodo === 'POST'   => $ctrl->crear(),
                $metodo === 'DELETE' && $id !== null => $ctrl->eliminar($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'log-acciones' => (function () use ($metodo) {
            Auth::requirePermiso('log');
            require_once __DIR__ . '/controllers/LogAccionesController.php';
            match ($metodo) {
                'GET'   => (new LogAccionesController())->listar(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'iva' => (function () use ($metodo, $accion) {
            Auth::requirePermiso('reportes');
            require_once __DIR__ . '/controllers/IvaController.php';
            $ctrl = new IvaController();
            match (true) {
                $metodo === 'GET' && $accion === 'ventas'  => $ctrl->ventas(),
                $metodo === 'GET' && $accion === 'compras' => $ctrl->compras(),
                $metodo === 'GET' && $accion === 'export'  => $ctrl->exportar(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'rentabilidad' => (function () use ($metodo, $accion) {
            Auth::requirePermiso('costos');
            require_once __DIR__ . '/controllers/RentabilidadController.php';
            $ctrl = new RentabilidadController();
            match (true) {
                $metodo === 'GET' && $accion === 'resumen'        => $ctrl->resumen(),
                $metodo === 'GET' && $accion === 'tendencia'      => $ctrl->tendencia(),
                $metodo === 'GET' && $accion === 'abc'            => $ctrl->abc(),
                $metodo === 'GET' && $accion === 'margen-critico' => $ctrl->margenCritico(),
                $metodo === 'GET' && $accion === 'clientes'       => $ctrl->clientes(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'reportes' => (function () use ($metodo, $accion) {
            Auth::requirePermiso('reportes');
            require_once __DIR__ . '/controllers/ReportesController.php';
            $ctrl = new ReportesController();
            match (true) {
                $metodo === 'GET' && $accion === 'resumen-ejecutivo'   => $ctrl->resumenEjecutivo(),
                $metodo === 'GET' && $accion === 'rotacion-inventario' => $ctrl->rotacionInventario(),
                $metodo === 'GET' && $accion === 'compras-proveedor'   => $ctrl->comprasPorProveedor(),
                $metodo === 'GET' && $accion === 'flujo-caja'          => $ctrl->flujoCaja(),
                $metodo === 'GET' && $accion === 'exportar'            => $ctrl->exportar($_GET['tipo'] ?? ''),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'sucursales' => (function () use ($metodo, $accion, $id, $subAccion) {
            require_once __DIR__ . '/controllers/SucursalesController.php';
            $ctrl    = new SucursalesController();
            $depId   = is_numeric($subAccion) ? (int)$subAccion : 0;
            match (true) {
                $metodo === 'GET'  && $accion === 'depositos'      => $ctrl->depositos(),
                $metodo === 'GET'  && $accion === 'stock-deposito' => $ctrl->stockDeposito(),
                $metodo === 'GET'                                   => $ctrl->listar(),
                $metodo === 'POST' && $accion === 'deposito'       => $ctrl->crearDeposito(),
                $metodo === 'POST'                                  => $ctrl->crear(),
                $metodo === 'PUT'  && $accion === 'deposito'       => $ctrl->actualizarDeposito($depId),
                $metodo === 'PUT'                                   => $ctrl->actualizar($id ?? 0),
                $metodo === 'DELETE'                                => $ctrl->eliminar($id ?? 0),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'mercadopago' => (function () use ($metodo, $sub) {
            require_once __DIR__ . '/controllers/MercadoPagoController.php';
            $ctrl = new MercadoPagoController();
            match (true) {
                $metodo === 'POST' && $sub === 'preferencia'      => $ctrl->preferencia(),
                $metodo === 'POST' && $sub === 'confirmar-manual' => $ctrl->confirmarManual(),
                $metodo === 'POST' && $sub === 'webhook'          => $ctrl->webhook(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'whatsapp' => (function () use ($metodo, $sub) {
            require_once __DIR__ . '/controllers/WhatsAppController.php';
            $ctrl = new WhatsAppController();
            match (true) {
                $metodo === 'POST' && $sub === 'enviar' => $ctrl->enviar(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'mail' => (function () use ($metodo, $sub) {
            require_once __DIR__ . '/controllers/MailController.php';
            $ctrl = new MailController();
            match (true) {
                $metodo === 'POST' && $sub === 'enviar' => $ctrl->enviar(),
                $metodo === 'POST' && $sub === 'probar' => $ctrl->probar(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'pedido' => (function () use ($metodo, $sub) {
            require_once __DIR__ . '/controllers/PedidoController.php';
            $ctrl = new PedidoController();
            match (true) {
                $metodo === 'POST' && $sub === 'pdf' => $ctrl->pdf(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'vendedores' => (function () use ($metodo, $id, $accion, $subAccion) {
            require_once __DIR__ . '/controllers/VendedoresController.php';
            $ctrl = new VendedoresController();
            match (true) {
                // Comisiones: /vendedores/comisiones, /vendedores/comisiones/cerrar, /vendedores/comisiones/exportar
                $metodo === 'GET'  && $accion === 'comisiones' && $subAccion === 'exportar' => $ctrl->exportarCSV(),
                $metodo === 'GET'  && $accion === 'comisiones'                              => $ctrl->reporteComisiones(),
                $metodo === 'POST' && $accion === 'comisiones' && $subAccion === 'cerrar'   => $ctrl->cerrarMes(),
                // Reportes: /vendedores/reporte/ventas  y  /vendedores/:id/reporte
                $metodo === 'GET'    && $accion === 'reporte' && $subAccion === 'ventas'    => $ctrl->reporteVentas(),
                $metodo === 'GET'    && $id !== null && $subAccion === 'reporte'            => $ctrl->reporteIndividual($id),
                $metodo === 'GET'    && $id !== null          => $ctrl->get($id),
                $metodo === 'GET'                             => $ctrl->listar(),
                $metodo === 'POST'   && $id === null          => $ctrl->crear(),
                $metodo === 'PUT'    && $id !== null          => $ctrl->actualizar($id),
                $metodo === 'DELETE' && $id !== null          => $ctrl->desactivar($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'taxonomias' => (function () use ($metodo, $id, $accion) {
            require_once __DIR__ . '/controllers/TaxonomiasController.php';
            if ($metodo !== 'GET') Auth::requirePermiso('productos_editar');
            $ctrl = new TaxonomiasController();
            match (true) {
                $metodo === 'GET'                                        => $ctrl->listar(),
                $metodo === 'POST'   && $accion === 'bulk'               => $ctrl->bulkCrear(),
                $metodo === 'POST'   && $accion === 'bulk-eliminar'      => $ctrl->bulkEliminar(),
                $metodo === 'POST'   && $id === null                     => $ctrl->crear(),
                $metodo === 'PUT'    && $id !== null                     => $ctrl->renombrar($id),
                $metodo === 'DELETE' && $id !== null                     => $ctrl->eliminar($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'cheques' => (function () use ($metodo, $id, $accion, $subAccion) {
            require_once __DIR__ . '/controllers/ChequesController.php';
            if ($metodo !== 'GET') Auth::requirePermiso('caja');
            $ctrl = new ChequesController();
            match (true) {
                $metodo === 'GET'  && $accion === 'resumen'              => $ctrl->resumen(),
                $metodo === 'GET'                                         => $ctrl->listar(),
                $metodo === 'POST' && $id === null && $accion === null    => $ctrl->crear(),
                $metodo === 'POST' && $id !== null && $subAccion === 'depositar' => $ctrl->depositar($id),
                $metodo === 'POST' && $id !== null && $subAccion === 'endosar'   => $ctrl->endosar($id),
                $metodo === 'POST' && $id !== null && $subAccion === 'rechazar'  => $ctrl->rechazar($id),
                $metodo === 'POST' && $id !== null && $subAccion === 'debitado'  => $ctrl->marcarDebitado($id),
                $metodo === 'POST' && $id !== null && $subAccion === 'reactivar' => $ctrl->reactivar($id),
                $metodo === 'PATCH' && $id !== null                      => $ctrl->actualizarNotas($id),
                $metodo === 'DELETE' && $id !== null                     => $ctrl->eliminar($id),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'licencia' => (function () use ($metodo, $accion) {
            require_once __DIR__ . '/controllers/LicenciaController.php';
            $ctrl = new LicenciaController();
            match (true) {
                $metodo === 'POST' && $accion === 'verificar' => $ctrl->verificar(),
                $metodo === 'GET'  && $accion === 'estado'    => $ctrl->estado(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        'backup' => (function () use ($metodo, $accion) {
            require_once __DIR__ . '/controllers/BackupController.php';
            $ctrl = new BackupController();
            match (true) {
                $metodo === 'POST' && $accion === 'programado' => $ctrl->programado(),
                $metodo === 'GET'  && $accion === 'estado'      => $ctrl->estado(),
                $metodo === 'GET'  && $accion === 'listar'      => $ctrl->listar(),
                $metodo === 'POST' && $accion === 'restaurar'   => $ctrl->restaurar(),
                default => json(405, ['error' => 'Método no permitido']),
            };
        })(),

        default => json(404, ['error' => "Recurso '$recurso' no existe"]),
    };
} catch (PDOException $e) {
    $msg     = sprintf("[%s] %s %s — PDO: %s\n", date('Y-m-d H:i:s'), $metodo, $uri, $e->getMessage());
    $logFile = __DIR__ . '/logs/error.log';
    @mkdir(dirname($logFile), 0755, true);
    error_log($msg, 3, $logFile); // log específico de la app
    error_log($msg);              // log general de PHP (C:\ProgramData\LogosPOS\logs\php-error.log en producción)
    json(500, ['error' => 'Error de base de datos. Revisá el log del servidor.']);
} catch (Throwable $e) {
    $msg     = sprintf("[%s] %s %s — %s: %s\n", date('Y-m-d H:i:s'), $metodo, $uri, get_class($e), $e->getMessage());
    $logFile = __DIR__ . '/logs/error.log';
    @mkdir(dirname($logFile), 0755, true);
    error_log($msg, 3, $logFile);
    error_log($msg);
    json(500, ['error' => 'Error interno del servidor. Revisá el log.']);
}
