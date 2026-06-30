<?php
// Header compartido por todas las páginas de pos/.
// Definir $nav_activo antes del include: 'pos' | 'ventas' | 'cuentacorriente' | 'productos' | 'compras' | 'configuracion' | 'caja' | 'movimientos' | 'operaciones' | 'cierres' | 'importar'
$nav_activo = $nav_activo ?? '';
function nav_clase(string $clave, string $actual): string {
    return $clave === $actual ? ' activo' : '';
}
$contabilidad_activo = in_array($nav_activo, ['caja', 'movimientos', 'operaciones', 'cierres'], true);
$cc_tipo = $nav_activo === 'cuentacorriente' && ($_GET['tipo'] ?? 'cliente') === 'proveedor' ? 'proveedor' : 'cliente';
?>
<nav class="app-nav">
  <a href="/Logos/pos/" class="nav-brand"><img src="/Logos/logo_background.png?v=<?= @filemtime(__DIR__ . '/../logo_background.png') ?: 0 ?>" alt="Logos"></a>
  <a href="/Logos/pos/" class="nav-link<?= nav_clase('pos', $nav_activo) ?>">POS</a>
  <div class="nav-sep"></div>
  <a href="/Logos/pos/ventas.html" class="nav-link<?= nav_clase('ventas', $nav_activo) ?>">Ventas</a>
  <div class="nav-drop">
    <a href="/Logos/pos/cuentacorriente.html" class="nav-link nav-drop-toggle<?= nav_clase('cuentacorriente', $nav_activo) ?>">Cta. Cte.</a>
    <div class="nav-drop-menu">
      <a href="/Logos/pos/cuentacorriente.html?tipo=cliente" class="<?= $nav_activo === 'cuentacorriente' && $cc_tipo === 'cliente' ? 'activo' : '' ?>">Clientes</a>
      <a href="/Logos/pos/cuentacorriente.html?tipo=proveedor" class="<?= $nav_activo === 'cuentacorriente' && $cc_tipo === 'proveedor' ? 'activo' : '' ?>">Proveedores</a>
    </div>
  </div>
  <a href="/Logos/pos/productos.html" class="nav-link<?= nav_clase('productos', $nav_activo) ?>">Productos</a>
  <a href="/Logos/pos/compras.html" id="nav-compras-link" class="nav-link<?= nav_clase('compras', $nav_activo) ?>">Compras</a>
  <div class="nav-drop">
    <a href="#" class="nav-link nav-drop-toggle<?= $contabilidad_activo ? ' activo' : '' ?>">Contabilidad</a>
    <div class="nav-drop-menu">
      <a href="/Logos/pos/caja.html" class="<?= trim(nav_clase('caja', $nav_activo)) ?>">Caja</a>
      <a href="/Logos/pos/movimientos.html" class="<?= trim(nav_clase('movimientos', $nav_activo)) ?>">Movimientos</a>
      <a href="/Logos/pos/operaciones.html" class="<?= trim(nav_clase('operaciones', $nav_activo)) ?>">Operaciones</a>
      <a href="/Logos/pos/cierres.html" class="<?= trim(nav_clase('cierres', $nav_activo)) ?>">Cierres Históricos</a>
    </div>
  </div>
  <div class="nav-spacer"></div>
  <select id="nav-caja-op" class="nav-caja-select" style="display:none;" title="Caja en la que estás operando"></select>
  <a href="/Logos/pos/configuracion.html" id="nav-config-link" class="nav-link nav-config<?= nav_clase('configuracion', $nav_activo) ?>" title="Configuración">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
  </a>
  <div class="nav-fecha" id="fecha-hora"></div>
  <div class="nav-sep"></div>
  <div class="nav-usuario" id="nav-usuario"></div>
  <a href="#" id="nav-logout" class="nav-link" title="Cerrar sesión">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
  </a>
</nav>
<script src="/Logos/pos/auth.js"></script>
