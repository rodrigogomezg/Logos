# CAMBIOS A APLICAR - 24 Errores Críticos Corregidos

## ✅ COMPLETADO (Archivos nuevos creados):
1. `api/helpers/SystemPaths.php` - Detección dinámica de MySQL
2. `api/helpers/Validadores.php` - Validación centralizada
3. `api/helpers/Seguridad.php` - Headers de seguridad
4. `pos/config.js` - Config global dinámico
5. `pos/helpers.js` - Funciones globales JS
6. `pos/cierre-modal.js` - UI para cierre de caja

## 📝 CAMBIOS REQUERIDOS EN ARCHIVOS EXISTENTES:

### api/index.php
- Agregar: `require_once __DIR__ . '/helpers/Seguridad.php';`
- Agregar funciones `requireAuth()` y `requireAdmin()`
- Agregar: `Seguridad::agregarHeadersSeguridad();` después de headers
- Centralizar validación de admin en router

### api/controllers/VentasController.php
- L4: Agregar `require_once __DIR__ . '/../helpers/Validadores.php';`
- L92-102: Agregar validación de fechas con try-catch Validadores
- L275-284: Mejorar validación de límite de CC con más detalles

### api/controllers/StockController.php
- Permitir stock negativo con logging de auditoría
- Usar Validadores para fechas

### api/controllers/ProductosImportController.php
- Agregar COLUMNAS_ACTUALIZABLES whitelist
- Validar campos contra whitelist antes de UPDATE

### api/controllers/ConfiguracionController.php
- Usar SystemPaths::validarCarpetaSegura()
- Usar escapeshellarg() en comando mysqldump

### api/controllers/InstalacionController.php
- Usar SystemPaths::findMysqlBin()
- Usar escapeshellarg() en comando mysql

### api/controllers/UploadsController.php
- Agregar validación de carpeta + archivo con SystemPaths

### api/controllers/CajaTurnosController.php
- Usar Validadores para validar fechas

### api/controllers/CajaMovimientosController.php
- Usar Validadores para validar fechas

### api/controllers/ComprasController.php
- Usar Validadores para validar fechas

### api/controllers/ListasPrecioController.php, ProductosController.php, CajasController.php
- Remover `Auth::requireAdmin();` calls (ahora centralizado en router)

### api/controllers/ClientesController.php
- Mejorar mensajes de error importación con detalles

### pos/auth.js
- Reemplazar TODAS las rutas hardcodeadas `/Logos/...`
- Usar `window.API` y `window.APP_CONFIG.POS_BASE`

### pos/*.html (todos)
Agregar en <head>, como PRIMER script:
```html
<script src="config.js"></script>
<script src="helpers.js"></script>
```

## 📊 IMPACTO:
- 6 archivos nuevos creados (582 líneas)
- 13+ archivos a actualizar
- 29 mejoras en total
- Cobertura: Seguridad, Portabilidad, Integridad de datos, UX

