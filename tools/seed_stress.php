<?php
/**
 * Logos — Stress Test Seeder
 * Inserta 50.000 productos, 50 proveedores y 100 clientes con prefijo __SEED__
 * para poder limpiarlos en cualquier momento sin tocar datos reales.
 *
 * Acceder desde: http://localhost/Logos/tools/seed_stress.php
 */

set_time_limit(600);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/../api/config/db.php';

// ── Constantes ────────────────────────────────────────────────────────────────
const SEED_TAG        = '__SEED__';
const N_PRODUCTOS     = 50000;
const N_PROVEEDORES   = 50;
const N_CLIENTES      = 100;
const BATCH           = 500;

// ── Pools de datos ────────────────────────────────────────────────────────────
$CATEGORIAS = [
    'Ferretería'   => ['Tornillos y fijaciones', 'Cadenas y grilletes', 'Bisagras y herrajes', 'Cerraduras', 'Adhesivos'],
    'Plomería'     => ['Caños PVC', 'Caños hierro', 'Válvulas y llaves', 'Codos y uniones', 'Sifones'],
    'Electricidad' => ['Cables y conductores', 'Disyuntores y llaves', 'Enchufes y tomas', 'Luminarias', 'Transformadores'],
    'Herramientas' => ['Manuales', 'Eléctricas', 'Neumáticas', 'Medición y trazado', 'Corte y desbaste'],
    'Pintura'      => ['Interior látex', 'Exterior látex', 'Esmalte sintético', 'Impermeabilizante', 'Selladores'],
    'Construcción' => ['Cemento y cal', 'Áridos y arenas', 'Ladrillos y bloques', 'Revoque y yeso', 'Mallas'],
    'Jardinería'   => ['Mangueras y riego', 'Macetas y canteros', 'Herramientas jardín', 'Pesticidas', 'Semillas'],
    'Iluminación'  => ['LED interior', 'LED exterior', 'Fluorescente', 'Decorativa', 'Industrial'],
    'Sanitarios'   => ['Inodoros', 'Lavabos y piletas', 'Duchadores', 'Grifería', 'Accesorios baño'],
    'Maderas'      => ['Machimbre', 'Terciado', 'MDF y aglomerado', 'Tablones', 'Listones'],
];

$MARCAS = [
    'Trabex', 'Ferromax', 'Llave Forte', 'Electrix', 'PintTop', 'Construmax',
    'JardinVerde', 'LuzMax', 'SaniTotal', 'MaderPlus', 'Metálica AR', 'PVC Max',
    'AceroPlus', 'VinilTec', 'Corralon Sur', 'HerraFácil', 'ElectroPro',
    'PinturaUno', 'Cañotek', 'IlumAR',
];

$PREFIJOS = [
    'Tornillo', 'Caño', 'Cable', 'Llave', 'Pintura', 'Cemento', 'Manguera',
    'Lámpara', 'Inodoro', 'Tabla', 'Válvula', 'Enchufe', 'Bisagra', 'Tuerca',
    'Brida', 'Perfil', 'Ángulo', 'Codo', 'Unión', 'Abrazadera', 'Grampón',
    'Tarugos', 'Perno', 'Remache', 'Arandela', 'Bulón', 'Sifón', 'Rejilla',
    'Tapa', 'Tapón', 'Manija', 'Gozne', 'Guía', 'Riel', 'Soporte',
];

$SUFIJOS = [
    '20mm', '25mm', '32mm', '40mm', '50mm', '63mm', '75mm', '90mm', '110mm',
    '1/2"', '3/4"', '1"', '1 1/4"', '2"', '3"', '4"',
    '6mt', '3mt', '1,5mt', '0,5mt',
    '220V', '380V', '12V', '24V',
    '1kg', '5kg', '10kg', '20kg', '25kg',
    'x10', 'x25', 'x50', 'x100',
    'blanco', 'negro', 'gris', 'galvanizado', 'inoxidable',
];

$APELLIDOS = [
    'García', 'Fernández', 'López', 'Martínez', 'González', 'Rodríguez',
    'Sánchez', 'Pérez', 'Gómez', 'Díaz', 'Torres', 'Ramírez', 'Flores',
    'Medina', 'Romero', 'Vargas', 'Reyes', 'Castro', 'Morales', 'Suárez',
    'Acosta', 'Herrera', 'Ríos', 'Ortega', 'Delgado', 'Nuñez', 'Ramos',
    'Ibáñez', 'Molina', 'Guerrero', 'Cabrera', 'Silva', 'Benítez', 'Vera',
    'Figueroa', 'Mendoza', 'Luna', 'Campos', 'Pizarro', 'Giménez',
];

$NOMBRES = [
    'Juan', 'María', 'Carlos', 'Ana', 'Luis', 'Laura', 'Pedro', 'Sofía',
    'Miguel', 'Paula', 'Diego', 'Valentina', 'Ricardo', 'Camila', 'Jorge',
    'Florencia', 'Pablo', 'Lucía', 'Fernando', 'Martina', 'Sebastián',
    'Agustina', 'Nicolás', 'Micaela', 'Andrés', 'Romina', 'Ezequiel', 'Natalia',
];

$CIUDADES = [
    'Buenos Aires', 'Córdoba', 'Rosario', 'Mendoza', 'La Plata', 'Mar del Plata',
    'San Miguel de Tucumán', 'Salta', 'Santa Fe', 'San Juan',
];

// ── Helpers ───────────────────────────────────────────────────────────────────
function rnd(array $arr): string {
    return $arr[array_rand($arr)];
}

function precio(int $min, int $max): float {
    return round(mt_rand($min * 100, $max * 100) / 100, 2);
}

function cuit_fake(int $seed): string {
    $base = str_pad($seed, 8, '0', STR_PAD_LEFT);
    return "20-{$base}-9";
}

function ya_hay_seed(PDO $db): bool {
    $n = (int)$db->query("SELECT COUNT(*) FROM productos WHERE codigo LIKE '__SEED__%'")->fetchColumn();
    return $n > 0;
}

function contar_seed(PDO $db): array {
    return [
        'productos'   => (int)$db->query("SELECT COUNT(*) FROM productos   WHERE codigo LIKE '__SEED__%'")->fetchColumn(),
        'clientes'    => (int)$db->query("SELECT COUNT(*) FROM clientes    WHERE nombre LIKE '__SEED__%'")->fetchColumn(),
        'proveedores' => (int)$db->query("SELECT COUNT(*) FROM proveedores WHERE nombre LIKE '__SEED__%'")->fetchColumn(),
    ];
}

// ── Acción: seed ──────────────────────────────────────────────────────────────
function ejecutar_seed(PDO $db, array $CATEGORIAS, array $MARCAS, array $PREFIJOS, array $SUFIJOS, array $APELLIDOS, array $NOMBRES, array $CIUDADES): array {
    $t0  = microtime(true);
    $log = [];

    // 1. Proveedores en tabla proveedores
    $provNombres = [];
    $stmtProv = $db->prepare("INSERT IGNORE INTO proveedores (nombre, cuit, condicion_iva, activo) VALUES (?,?,?,1)");
    for ($i = 1; $i <= N_PROVEEDORES; $i++) {
        $nombre = SEED_TAG . 'Proveedor ' . str_pad($i, 2, '0', STR_PAD_LEFT);
        $provNombres[] = $nombre;
        $stmtProv->execute([$nombre, cuit_fake($i + 10000), 'Responsable Inscripto']);
    }
    $log[] = N_PROVEEDORES . ' proveedores insertados';

    // 2. Clientes
    $stmtCli = $db->prepare("INSERT IGNORE INTO clientes (nombre, cuit, condicion_iva, activo) VALUES (?,?,?,1)");
    for ($i = 1; $i <= N_CLIENTES; $i++) {
        $nombre = SEED_TAG . rnd($NOMBRES) . ' ' . rnd($APELLIDOS) . ' #' . $i;
        $stmtCli->execute([$nombre, cuit_fake($i + 20000), 'Consumidor Final']);
    }
    $log[] = N_CLIENTES . ' clientes insertados';

    // 3. Productos — bulk insert por lotes
    $catKeys   = array_keys($CATEGORIAS);
    $inserted  = 0;
    $lote      = [];

    $placeholders = implode(',', array_fill(0, BATCH, '(?,?,?,?,?,?,?,?,0,?,1)'));
    $stmtBulk = $db->prepare(
        "INSERT INTO productos (codigo, nombre, marca, categoria, subcategoria, proveedor, precio_venta, costo_actual, stock_actual, stock_minimo, activo)
         VALUES $placeholders"
    );

    // Placeholders para el último lote (tamaño variable)
    $flush = function (array &$lote, PDO $db) use (&$inserted): void {
        if (empty($lote)) return;
        $n    = count($lote) / 9; // 9 columnas por fila
        $ph   = implode(',', array_fill(0, (int)$n, '(?,?,?,?,?,?,?,?,0,?,1)'));
        $db->prepare(
            "INSERT INTO productos (codigo, nombre, marca, categoria, subcategoria, proveedor, precio_venta, costo_actual, stock_actual, stock_minimo, activo)
             VALUES $ph"
        )->execute($lote);
        $inserted += (int)$n;
        $lote = [];
    };

    $db->beginTransaction();
    try {
        for ($i = 1; $i <= N_PRODUCTOS; $i++) {
            $cat    = rnd($catKeys);
            $subcats = $CATEGORIAS[$cat];
            $subcat = $subcats[array_rand($subcats)];
            $prov   = $provNombres[($i - 1) % N_PROVEEDORES];
            $marca  = rnd($MARCAS);
            $prefijo = rnd($PREFIJOS);
            $sufijo  = rnd($SUFIJOS);
            $nombre  = SEED_TAG . "{$prefijo} {$sufijo} {$marca}";
            $codigo  = SEED_TAG . str_pad($i, 6, '0', STR_PAD_LEFT);
            $costo   = precio(100, 20000);
            $pventa  = round($costo * (1 + mt_rand(15, 60) / 100), 2);
            $stk_min = mt_rand(0, 5);

            array_push($lote, $codigo, $nombre, $marca, $cat, $subcat, $prov, $pventa, $costo, $stk_min);

            if (count($lote) / 9 === BATCH) {
                $stmtBulk->execute($lote);
                $inserted += BATCH;
                $lote = [];
            }
        }
        $flush($lote, $db);
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $log[] = $inserted . ' productos insertados';
    $log[] = 'Tiempo total: ' . round(microtime(true) - $t0, 2) . 's';
    return $log;
}

// ── Acción: cleanup ───────────────────────────────────────────────────────────
function ejecutar_cleanup(PDO $db): array {
    $t0  = microtime(true);
    $log = [];

    $p = $db->exec("DELETE FROM productos   WHERE codigo LIKE '__SEED__%'");
    $log[] = "$p productos eliminados";

    $c = $db->exec("DELETE FROM clientes    WHERE nombre LIKE '__SEED__%'");
    $log[] = "$c clientes eliminados";

    $v = $db->exec("DELETE FROM proveedores WHERE nombre LIKE '__SEED__%'");
    $log[] = "$v proveedores eliminados";

    $log[] = 'Tiempo total: ' . round(microtime(true) - $t0, 2) . 's';
    return $log;
}

// ── Router ────────────────────────────────────────────────────────────────────
$resultado = null;
$error     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = DB::get();
        if ($_POST['action'] === 'seed') {
            if (ya_hay_seed($db)) {
                $error = 'Ya hay datos seed cargados. Limpiá primero antes de volver a seedear.';
            } else {
                $resultado = ejecutar_seed($db, $CATEGORIAS, $MARCAS, $PREFIJOS, $SUFIJOS, $APELLIDOS, $NOMBRES, $CIUDADES);
            }
        } elseif ($_POST['action'] === 'cleanup') {
            $resultado = ejecutar_cleanup($db);
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// Contadores actuales
try {
    $db      = DB::get();
    $conteos = contar_seed($db);
    $totalReal = [
        'productos'   => (int)$db->query("SELECT COUNT(*) FROM productos")->fetchColumn(),
        'clientes'    => (int)$db->query("SELECT COUNT(*) FROM clientes")->fetchColumn(),
        'proveedores' => (int)$db->query("SELECT COUNT(*) FROM proveedores")->fetchColumn(),
    ];
    $haySeed = $conteos['productos'] > 0 || $conteos['clientes'] > 0 || $conteos['proveedores'] > 0;
} catch (\Throwable $e) {
    $conteos   = ['productos' => '?', 'clientes' => '?', 'proveedores' => '?'];
    $totalReal = $conteos;
    $haySeed   = false;
    $error     = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Logos — Seed stress test</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: system-ui, sans-serif; background: #F5F4F1; color: #1A1916; min-height: 100vh; padding: 40px 20px; }
.wrap { max-width: 680px; margin: 0 auto; }
h1 { font-size: 22px; font-weight: 800; margin-bottom: 4px; }
.sub { font-size: 13px; color: #7C7872; margin-bottom: 32px; }
.card { background: #fff; border: 1px solid #D5D0CA; padding: 24px; margin-bottom: 16px; }
.card h2 { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #7C7872; margin-bottom: 16px; }
.stats { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; margin-bottom: 16px; }
.stat { background: #F5F4F1; padding: 14px 16px; text-align: center; }
.stat .n { font-size: 28px; font-weight: 800; font-variant-numeric: tabular-nums; }
.stat .n.seed { color: #E67E22; }
.stat .lbl { font-size: 11px; color: #7C7872; text-transform: uppercase; letter-spacing: .4px; margin-top: 2px; }
.stat .sub-lbl { font-size: 11px; color: #9B9590; margin-top: 2px; }
.btn { display: inline-block; padding: 11px 24px; border: none; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; cursor: pointer; font-family: inherit; transition: opacity .15s; }
.btn:disabled { opacity: .4; cursor: not-allowed; }
.btn-seed    { background: #2ECC71; color: #fff; }
.btn-seed:hover:not(:disabled) { background: #27AE60; }
.btn-cleanup { background: #fff; color: #C0392B; border: 1.5px solid #C0392B; }
.btn-cleanup:hover:not(:disabled) { background: #FDF0EE; }
.btn-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.warn { background: #FEF9E7; border: 1px solid #F9E79F; padding: 10px 14px; font-size: 12px; color: #7D6608; margin-bottom: 16px; }
.ok   { background: #EAFAF1; border: 1px solid #A9DFBF; padding: 10px 14px; font-size: 12px; color: #145A32; margin-bottom: 16px; }
.err  { background: #FDEDEC; border: 1px solid #F5B7B1; padding: 10px 14px; font-size: 12px; color: #922B21; margin-bottom: 16px; }
.log  { background: #F5F4F1; padding: 12px 14px; font-size: 12px; font-family: monospace; line-height: 1.8; }
.log li { list-style: none; }
.log li::before { content: '✓ '; color: #27AE60; font-weight: 700; }
.spec { font-size: 12px; color: #7C7872; line-height: 1.8; }
.spec strong { color: #1A1916; }
.tag { display: inline-block; background: #FEF9E7; border: 1px solid #F9E79F; color: #7D6608; font-size: 11px; font-weight: 700; padding: 2px 8px; font-family: monospace; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Stress Test Seeder</h1>
  <p class="sub">Genera datos de prueba masivos para medir performance. No toca datos reales.</p>

  <!-- Estado actual -->
  <div class="card">
    <h2>Estado actual de la base</h2>
    <div class="stats">
      <div class="stat">
        <div class="n <?= $conteos['productos'] > 0 ? 'seed' : '' ?>"><?= number_format($totalReal['productos']) ?></div>
        <div class="lbl">Productos</div>
        <?php if ($conteos['productos'] > 0): ?>
          <div class="sub-lbl"><?= number_format($conteos['productos']) ?> son seed</div>
        <?php endif; ?>
      </div>
      <div class="stat">
        <div class="n <?= $conteos['clientes'] > 0 ? 'seed' : '' ?>"><?= number_format($totalReal['clientes']) ?></div>
        <div class="lbl">Clientes</div>
        <?php if ($conteos['clientes'] > 0): ?>
          <div class="sub-lbl"><?= number_format($conteos['clientes']) ?> son seed</div>
        <?php endif; ?>
      </div>
      <div class="stat">
        <div class="n <?= $conteos['proveedores'] > 0 ? 'seed' : '' ?>"><?= number_format($totalReal['proveedores']) ?></div>
        <div class="lbl">Proveedores</div>
        <?php if ($conteos['proveedores'] > 0): ?>
          <div class="sub-lbl"><?= number_format($conteos['proveedores']) ?> son seed</div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($haySeed): ?>
      <div class="warn">Hay datos seed cargados. Los números en naranja son registros de prueba.</div>
    <?php endif; ?>
  </div>

  <!-- Resultado de la última operación -->
  <?php if ($error): ?>
    <div class="err"><strong>Error:</strong> <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($resultado): ?>
    <div class="ok">
      <ul class="log">
        <?php foreach ($resultado as $linea): ?>
          <li><?= htmlspecialchars($linea) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <!-- Acciones -->
  <div class="card">
    <h2>Cargar datos de prueba</h2>
    <p class="spec" style="margin-bottom:16px">
      Inserta <strong>50.000 productos</strong> en <strong>10 categorías</strong> distribuidos entre
      <strong>50 proveedores ficticios</strong>, más <strong>100 clientes</strong>.<br>
      Todos los registros llevan el prefijo <span class="tag">__SEED__</span> para identificarlos.
      El proceso tarda ~30–60 segundos.
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="seed">
      <div class="btn-row">
        <button class="btn btn-seed" <?= $haySeed ? 'disabled' : '' ?>>
          Cargar 50.000 productos
        </button>
        <?php if ($haySeed): ?>
          <span class="spec">Ya hay seed cargado — limpiá antes de volver a insertar.</span>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Limpiar datos de prueba</h2>
    <p class="spec" style="margin-bottom:16px">
      Elimina todos los registros con prefijo <span class="tag">__SEED__</span>.
      No afecta ningún dato real.
    </p>
    <form method="POST" onsubmit="return confirm('¿Eliminar todos los datos seed?')">
      <input type="hidden" name="action" value="cleanup">
      <button class="btn btn-cleanup" <?= !$haySeed ? 'disabled' : '' ?>>
        Limpiar datos seed
      </button>
    </form>
  </div>

  <!-- Info técnica -->
  <div class="card">
    <h2>Qué medir después de cargar</h2>
    <p class="spec">
      <strong>Búsqueda en dropdown (F2):</strong> escribí un término corto ("tor", "cab") y observá la demora en el Network tab. La query hace LIKE '%%' sin índice de prefijo — el caso más exigente.<br><br>
      <strong>Modal F3:</strong> abrilo y buscá, filtrá por categoría/proveedor. Observá cuánto tarda cada request.<br><br>
      <strong>Filtros en productos.html:</strong> cargá la lista con paginación y aplicá filtros encadenados.<br><br>
      <strong>FiltrosController (DISTINCT proveedor):</strong> abrí cualquier pantalla que cargue filtros. Con 50k productos + 50 proveedores text este query se va a notar si hay cuello de botella.<br><br>
      <strong>Importación:</strong> intentá importar un CSV de 200 filas con proveedores seed. El paso de discontinuados hace un full scan sin índice en la columna <code>proveedor</code>.
    </p>
  </div>
</div>
</body>
</html>
