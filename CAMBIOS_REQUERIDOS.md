# Estado de correcciones — cerrado 2026-07-16

Este archivo era el checklist de los "24 errores críticos". **Todos los ítems
fueron aplicados** (la mayoría en commits previos de esta branch; el resto en
la ronda de integridad/robustez del 16/07/2026). Se conserva como registro.

## Resuelto en rondas anteriores
- Helpers `SystemPaths`, `Validadores`, `Seguridad` creados e integrados
- `config.js` / `helpers.js` / `cierre-modal.js` cargados en todas las páginas
- Gates de admin centralizados en el router (`api/index.php`)
- Validación de fechas con `Validadores` en Ventas/Stock/Caja/Compras
- `InstalacionController`: `escapeshellarg` + `SystemPaths::findMysqlBin`
- `ProductosImportController`: whitelist `CAMPOS_TODOS` en `procesarArchivo`
- `UploadsController`: MIME por contenido + nombre aleatorio + carpeta fija
- `auth.js`: rutas via `window.APP_CONFIG`

## Resuelto en la ronda de integridad/robustez (16/07/2026)
- **Doble anulación** de ventas (faltaba `estado` en el SELECT de `eliminar()`)
- **Anulación con CAE bloqueada** (server 422 + UI): el camino es Nota de Crédito
- **IVA multi-alícuota** en `FECAESolicitar` (desglose por ítem 21/10.5/0)
- **Idempotencia MercadoPago**: tabla `mp_pagos` (migración 40), firma de
  webhook obligatoria con secret configurado
- **Backups**: `escapeshellarg` en mysqldump, ruta por `SystemPaths`, carpeta
  secundaria off-site (migración 41 + campo en Configuración)
- **`FOR UPDATE`** en la verificación de límite de crédito (crear/editar venta)
- **XSS**: `esc()` aplicado en `auth.js`, `dashboard.html` e `index.html`
  (el resto de las páginas ya escapaba; barrido completo verificado)
- **`install/schema_limpio.sql` regenerado** desde la base migrada (20 → 30
  tablas). OJO: el instalador ejecuta SOLO ese archivo — regenerarlo tras cada
  migración: `mysqldump --no-data --skip-add-drop-table --skip-comments logos`
  + convertir `CREATE TABLE` a `CREATE TABLE IF NOT EXISTS`.

## Tests (nuevos)
- `php tools/test_integracion.php` — suite de integración: levanta la API real
  (server embebido PHP) contra una base `logos_test` recreada desde
  `schema_limpio.sql`. Cubre auth, ciclo de venta, límite CC, anulación,
  bloqueo por CAE e idempotencia MP. 19 tests.
- `php tools/test_desglose_iva.php` — test unitario del desglose de IVA
  multi-alícuota (6 casos, incluye redondeos).

Correr ambos antes de commitear cambios en ventas, caja, CC o AFIP.
