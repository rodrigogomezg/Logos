# Logos POS — reglas críticas del proyecto

## Las actualizaciones NUNCA deben cortar el acceso a una instalación que ya funciona

Hay clientes reales usando este sistema para facturar y cobrar en caja. Una
actualización que interrumpa el arranque normal — por ejemplo, mandar a una
instalación ya configurada de vuelta al wizard de instalación, o romper la
detección de "el sistema ya está instalado" — le corta el acceso al negocio
en medio de una jornada de venta. Es de los peores escenarios posibles y hay
que tratarlo como tal.

**Regla dura:** antes de publicar cualquier versión nueva (`npm run publish`),
hay que verificar explícitamente que una instalación **ya configurada**
(con admin, caja y datos de negocio cargados) arranca directo al POS después
de actualizar, sin pasar por `instalar.html`. Probar solo una instalación
nueva/limpia NO alcanza — el camino que hay que probar es el de actualización
sobre una base de datos real y ya en uso.

### Puntos de falla conocidos a revisar en cada versión

- **`api/controllers/InstalacionController.php::estado()`** — los flags
  `requiere_conexion` / `requiere_schema` / `requiere_admin` / `requiere_negocio`
  / `requiere_caja` determinan si `electron/main.js` manda a `instalar.html`
  en vez de `index.html` al arrancar. Un falso positivo acá saca a un cliente
  ya andando de su sistema funcionando.
- **`api/config/db.local.php`** — nunca se empaqueta en el instalador
  (está explícitamente excluido en `electron-builder.yml` con
  `!api/config/db.local.php`), así que tiene que sobrevivir intacto a una
  actualización silenciosa. Si desaparece o deja de ser legible,
  `DB::estaConfigurado()` devuelve `false` y el sistema entero se cree "sin
  instalar" aunque la base esté perfecta.
- **Migraciones en `migrate/*.sql`** corren solas en cada arranque
  (`electron/services/server-manager.js::_runMigrations()`). Si una migración
  nueva falla a mitad de camino, puede dejar el estado de la base inconsistente
  justo antes de que se evalúe si el sistema "ya está instalado".
- **Instalaciones nuevas vs. actualizaciones sobre una base existente son
  caminos de código distintos** — un fix o feature puede probarse OK en una
  instalación desde cero y romper igual el camino de actualización (y viceversa).
  Hay que pensar los dos casos por separado, no asumir que probar uno cubre el otro.

### Cada arranque del programa pide usuario y clave de nuevo

Decisión explícita (01/08/2026): cerrar y reabrir Logos POS (o que se reinicie
solo, ej. tras un auto-update) tiene que volver a pedir login siempre — antes
la sesión persistía en `localStorage` (`logos_sesion`) y sobrevivía el
reinicio del proceso, así que cualquiera con acceso físico a la PC quedaba
adentro del POS sin autenticarse. Implementado en `electron/preload.js` +
`electron/main.js` (IPC sincrónico `debe-limpiar-sesion`, se consume una sola
vez por arranque del proceso, en la primera pantalla que cargue).

Esto **no contradice** la regla de arriba: forzar el login sigue dejando al
cliente entrar a su sistema con su PIN de siempre — es distinto de romper la
detección de "ya instalado" y mandarlo al wizard sin salida.

### Caso real (01/08/2026): actualización 1.0.8/1.0.9 mandó a un cliente ya andando al wizard

Después de actualizar, una instalación ya configurada volvió a mostrar el
wizard de instalación completo. Al llegar al último paso (crear admin), como
el admin ya existía, tiraba un error sin salida ("Ya existe un administrador.
Hacé un reset de fábrica o contactá al soporte") — el cliente quedaba
atrapado, sin poder volver a su sistema funcionando ni completar el wizard.

Causa: `estado()` devolvió al menos un flag `requiere_*` en `true` de forma
transitoria justo al arrancar (probablemente una carrera con los servicios
recién reiniciados tras la actualización silenciosa), y `pos/instalar.html`
solo chequea `estado()` **una vez, al cargar la página** — una vez adentro
del wizard, nada vuelve a verificar si el sistema en realidad ya está
configurado, hasta chocar contra el error final.

Fix aplicado en `pos/instalar.html`: si al intentar crear el admin ya existe
uno Y `admin-id` también rechaza (señal inequívoca de que negocio+caja
también están configurados, o sea que el sistema está 100% andando), en vez
de tirar el error se redirige directo a `login.html`. También se agregó un
segundo chequeo de `estado()` al arrancar el commit final del wizard, no solo
al cargar la página. **Pendiente de raíz:** todavía no se identificó con
certeza por qué `estado()` devolvió un flag en `true` de forma transitoria en
el arranque — si vuelve a pasar, revisar el timing entre `waitForDatabase()`
y el primer llamado a `checkInstallState()` en `electron/main.js`.
