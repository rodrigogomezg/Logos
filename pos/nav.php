<?php
// Header compartido por todas las páginas de pos/.
// $nav_activo: 'pos'|'ventas'|'cuentacorriente'|'contactos'|'productos'|'stock'|'importar'|'compras'|'caja'|'movimientos'|'operaciones'|'cierres'|'dashboard'|'rentabilidad'|'iva'|'configuracion'|'log'
$nav_activo = $nav_activo ?? '';
function nav_clase(string $clave, string $actual): string {
    return $clave === $actual ? ' activo' : '';
}
$caja_activo      = in_array($nav_activo, ['caja', 'movimientos', 'operaciones', 'cierres', 'cheques'], true);
$vendedores_activo = $nav_activo === 'vendedores';
$reportes_activo  = in_array($nav_activo, ['dashboard', 'rentabilidad', 'iva', 'reportes'], true);
$productos_activo = in_array($nav_activo, ['productos', 'stock', 'importar', 'taxonomias', 'reglas-precio'], true);
$cc_tipo   = $nav_activo === 'cuentacorriente' && ($_GET['tipo'] ?? 'cliente') === 'proveedor' ? 'proveedor' : 'cliente';
$cont_tipo = $nav_activo === 'contactos'       && ($_GET['tipo'] ?? 'cliente') === 'proveedor' ? 'proveedor' : 'cliente';
?>
<nav class="app-nav">
  <a href="/Logos/pos/" class="nav-brand"><img src="/Logos/logos_logo.png?v=<?= @filemtime(__DIR__ . '/../logos_logo.png') ?: 0 ?>" alt="Logos"></a>
  <a href="/Logos/pos/" class="nav-link<?= nav_clase('pos', $nav_activo) ?>">POS</a>
  <div class="nav-sep"></div>
  <a href="/Logos/pos/ventas.html" class="nav-link<?= nav_clase('ventas', $nav_activo) ?>">Ventas</a>
  <div class="nav-drop" id="nav-cc-drop">
    <a href="/Logos/pos/cuentacorriente.html" class="nav-link nav-drop-toggle<?= nav_clase('cuentacorriente', $nav_activo) ?>">Cta. Cte.</a>
    <div class="nav-drop-menu">
      <a href="/Logos/pos/cuentacorriente.html?tipo=cliente"   class="<?= $nav_activo === 'cuentacorriente' && $cc_tipo === 'cliente'   ? 'activo' : '' ?>">Clientes</a>
      <a href="/Logos/pos/cuentacorriente.html?tipo=proveedor" class="<?= $nav_activo === 'cuentacorriente' && $cc_tipo === 'proveedor' ? 'activo' : '' ?>">Proveedores</a>
    </div>
  </div>
  <div class="nav-drop">
    <a href="/Logos/pos/contactos.html" class="nav-link nav-drop-toggle<?= ($nav_activo === 'contactos' || $vendedores_activo) ? ' activo' : '' ?>">Contactos</a>
    <div class="nav-drop-menu">
      <a href="/Logos/pos/contactos.html?tipo=cliente"   class="<?= $nav_activo === 'contactos' && $cont_tipo === 'cliente'   ? 'activo' : '' ?>">Clientes</a>
      <a href="/Logos/pos/contactos.html?tipo=proveedor" class="<?= $nav_activo === 'contactos' && $cont_tipo === 'proveedor' ? 'activo' : '' ?>">Proveedores</a>
      <a href="/Logos/pos/vendedores.html" id="nav-vendedores-link" class="<?= $vendedores_activo ? 'activo' : '' ?>">Vendedores</a>
    </div>
  </div>
  <div class="nav-drop">
    <a href="/Logos/pos/productos.html" class="nav-link nav-drop-toggle<?= $productos_activo ? ' activo' : '' ?>" id="nav-prod-link">Productos</a>
    <div class="nav-drop-menu">
      <a href="/Logos/pos/productos.html" class="<?= trim(nav_clase('productos', $nav_activo)) ?>">Productos</a>
      <a href="/Logos/pos/importar.html" id="nav-importar-link" class="<?= trim(nav_clase('importar',  $nav_activo)) ?>">Importar</a>
      <a href="/Logos/pos/taxonomias.html?tipo=rubros" class="<?= $nav_activo === 'taxonomias' && ($_GET['tipo'] ?? '') === 'rubros' ? 'activo' : '' ?>">Rubros</a>
      <a href="/Logos/pos/taxonomias.html?tipo=marcas" class="<?= $nav_activo === 'taxonomias' && ($_GET['tipo'] ?? '') === 'marcas' ? 'activo' : '' ?>">Marcas</a>
      <a href="/Logos/pos/reglas-precio.html" class="<?= trim(nav_clase('reglas-precio', $nav_activo)) ?>">Reglas de precio</a>
    </div>
  </div>
  <a href="/Logos/pos/compras.html" id="nav-compras-link" class="nav-link<?= nav_clase('compras', $nav_activo) ?>">Compras</a>
  <div class="nav-drop">
    <a href="#" class="nav-link nav-drop-toggle<?= $caja_activo ? ' activo' : '' ?>">Caja</a>
    <div class="nav-drop-menu">
      <a href="/Logos/pos/caja.html"        class="<?= trim(nav_clase('caja',        $nav_activo)) ?>">Abrir / Cerrar Caja</a>
      <a href="/Logos/pos/movimientos.html"  class="<?= trim(nav_clase('movimientos', $nav_activo)) ?>">Movimientos</a>
      <a href="/Logos/pos/operaciones.html"  class="<?= trim(nav_clase('operaciones', $nav_activo)) ?>">Operaciones</a>
      <a href="/Logos/pos/cierres.html"      class="<?= trim(nav_clase('cierres',     $nav_activo)) ?>">Cierres Históricos</a>
      <a href="/Logos/pos/cheques.html"     class="<?= trim(nav_clase('cheques',     $nav_activo)) ?>">Cheques</a>
    </div>
  </div>
  <div class="nav-drop" id="nav-reportes-drop">
    <a href="#" class="nav-link nav-drop-toggle<?= $reportes_activo ? ' activo' : '' ?>">Reportes</a>
    <div class="nav-drop-menu">
      <a href="/Logos/pos/dashboard.html"    id="nav-dashboard-link"    class="<?= trim(nav_clase('dashboard',    $nav_activo)) ?>">Dashboard</a>
      <a href="/Logos/pos/rentabilidad.html" id="nav-rentabilidad-link" class="<?= trim(nav_clase('rentabilidad', $nav_activo)) ?>">Rentabilidad</a>
      <a href="/Logos/pos/iva.html"          id="nav-iva-link"          class="<?= trim(nav_clase('iva',          $nav_activo)) ?>">Libro IVA</a>
      <a href="/Logos/pos/reportes.html"   id="nav-reportes-page-link" class="<?= trim(nav_clase('reportes',      $nav_activo)) ?>">Reportes</a>
    </div>
  </div>
  <div class="nav-spacer"></div>
  <select id="nav-sucursal-op" class="nav-caja-select" style="display:none;" title="Sucursal activa"></select>
  <select id="nav-caja-op" class="nav-caja-select" style="display:none;" title="Caja en la que estás operando"></select>
  <div class="nav-sep"></div>
  <a href="/Logos/pos/log.html" id="nav-log-link" class="nav-link nav-icon-link<?= nav_clase('log', $nav_activo) ?>" title="Bitácora de acciones" style="display:none">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
    <span class="nav-icon-label">Log</span>
  </a>
  <a href="/Logos/pos/configuracion.html" id="nav-config-link" class="nav-link nav-icon-link<?= nav_clase('configuracion', $nav_activo) ?>" title="Configuración">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    <span class="nav-icon-label">Config</span>
  </a>
  <button id="nav-tour-btn" class="nav-tour-btn" title="Tour guiado — conocé esta pantalla" aria-label="Tour guiado">?</button>
  <div class="nav-sep"></div>
  <div class="nav-fecha" id="fecha-hora"></div>
  <div class="nav-sep"></div>
  <div class="nav-usuario" id="nav-usuario"></div>
  <div class="nav-sep"></div>
  <a href="#" id="nav-logout" class="nav-link nav-icon-link" title="Cerrar sesión">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    <span class="nav-icon-label">Salir</span>
  </a>
</nav>
<script src="/Logos/pos/auth.js?v=<?= @filemtime(__DIR__ . '/auth.js') ?: 0 ?>"></script>
<script src="/Logos/pos/tour.js?v=<?= @filemtime(__DIR__ . '/tour.js') ?: 0 ?>"></script>
<script src="/Logos/pos/licencia.js?v=<?= @filemtime(__DIR__ . '/licencia.js') ?: 0 ?>"></script>
