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

### Si una actualización "no llega" — revisar la descarga antes que nada

Caso real (02/08/2026): la 1.0.11 nunca se instaló porque la descarga fallaba
en loop con `ERR_QUIC_PROTOCOL_ERROR` (visible en `update.log`, en
`%APPDATA%\logos-pos\update.log` o donde apunte `app.getPath('userData')`) —
la red de esa PC no dejaba pasar QUIC (HTTP/3 sobre UDP) aunque el HTTPS
normal funcionara bien. Mientras la descarga no complete, el cliente sigue
en la versión vieja **sin ninguno de los fixes nuevos**, así que cualquier
bug ya arreglado en el código sigue reproduciéndose ahí — es fácil confundir
esto con "el fix no funcionó". Se agregó `app.commandLine.appendSwitch('disable-quic')`
en `main.js` (tiene que llamarse antes de `app.whenReady()`). Antes de asumir
que un fix no sirvió, revisar `update.log` para confirmar que la versión
realmente terminó de descargarse e instalarse.

### Puntos de falla conocidos a revisar en cada versión

- **`api/controllers/InstalacionController.php::estado()`** — los flags
  `requiere_conexion` / `requiere_schema` / `requiere_admin` / `requiere_negocio`
  / `requiere_caja` determinan si `electron/main.js` manda a `instalar.html`
  en vez de `index.html` al arrancar. Un falso positivo acá saca a un cliente
  ya andando de su sistema funcionando.
- **`db.local.php`** vive en `C:\ProgramData\LogosPOS\db.local.php` (desde
  02/08/2026 — ver caso real más abajo). Si desaparece o deja de ser legible,
  `DB::estaConfigurado()` devuelve `false` y el sistema entero se cree "sin
  instalar" aunque la base esté perfecta. **Nunca** debe volver a vivir dentro
  de `resources/` (el árbol que empaqueta `electron-builder.yml` bajo
  `www/Logos/`) — ese árbol lo reempaqueta el instalador NSIS en cada
  actualización.
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

**Caso real (02/08/2026): "cerrar y reabrir" no es lo mismo que "reiniciar el proceso".**
El fix de arriba solo cubre un reinicio real del proceso de Electron (flag
de un solo uso en `main.js`, consumido en `preload.js`). Pero el botón X de
la ventana no cierra el proceso — `mainWindow.on('close', ...)` hace
`e.preventDefault()` y minimiza a la bandeja a propósito, para que PHP/MariaDB
sigan sirviendo a otras PCs cliente. Reabrir desde la bandeja (click, doble
click, o abrir el acceso directo de nuevo mientras ya está corriendo →
`second-instance`) solo hace `mainWindow.show()` — nunca navega de nuevo, así
que la sesión en `localStorage` seguía intacta y el flag de un solo uso ya
estaba consumido desde el arranque real. Fix: al minimizar a la bandeja
(`close` con `preventDefault`), se limpia `logos_sesion` y se recarga la
página — `auth.js` redirige solo a `login.html` al no encontrar sesión
válida. Cualquier futuro cambio al flujo de ventana/bandeja tiene que
preservar esto: "la ventana se ve cerrada" (aunque el proceso siga vivo)
siempre tiene que terminar en pantalla de login al volver a abrirla.

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
y el primer llamado a `checkInstallState()` en `electron/main.js`. En
`startServerRole()` se agregó además un doble chequeo defensivo (si el primer
`checkInstallState()` da `requiere_*` en `true`, esperar 4s y volver a
consultar antes de mandar al wizard) — reduce el riesgo pero no reemplaza
encontrar la causa real.

### No agregar esperas invisibles dentro del instalador silencioso (NSIS `/S`)

Caso real (02/08/2026): para atacar la carrera entre "el servicio Windows
arrancó" (`sc start`, que NSIS puede confirmar) y "PHP ya está escuchando en
su puerto" (que puede tardar un instante más), se agregó un script
(`wait-after-update.ps1`) que hacía polling contra el servidor antes de
reabrir la app tras una actualización silenciosa. Esto empeoró el problema
que intentaba resolver: el instalador en modo `/S` (el que usa
`electron-updater` para autoactualizar) **no tiene ninguna ventana visible**,
así que cualquier espera ahí adentro es tiempo muerto totalmente invisible
para quien está mirando la pantalla — el cliente veía "casi un minuto sin
que pase nada" y no tenía forma de saber si la actualización seguía en curso
o se había colgado. Se revirtió: el instalador silencioso vuelve a reabrir
la app inmediatamente después de `sc start` (`installer.nsh`), y la espera
real (con feedback visible tipo "Verificando servicios...") queda a cargo
de `startServerRole()` en `electron/main.js`, que sí tiene una ventana en
pantalla para mostrar progreso mientras reintenta.

**Regla:** cualquier lógica de espera/reintento relacionada con el arranque
de servicios tiene que vivir del lado de Electron (que puede mostrar
feedback), nunca dentro de una macro NSIS que puede correr en modo silencioso.

### Caso real (02/08/2026): causa raíz confirmada del wizard recurrente — `db.local.php` vivía dentro de `resources/`

Después de varios incidentes (01/08 y antes) donde una instalación ya
configurada volvía al wizard tras actualizar, `licencia-debug.log` finalmente
dio evidencia directa: el log mostró `requiere_conexion:true` tanto en el
primer chequeo como 4 segundos después en el segundo (el doble chequeo con
espera, agregado como mitigación el 01/08, no alcanzaba porque el archivo
realmente no estaba, no era una carrera de milisegundos).

`requiere_conexion:true` sale de una sola condición:
`DB::estaConfigurado()` → `is_file(__DIR__ . '/db.local.php')` devolvió
`false`. Y `db.local.php` vivía en
`$INSTDIR\resources\www\Logos\api\config\db.local.php` — **dentro** del árbol
`resources/` que el instalador NSIS reempaqueta en cada actualización. Estaba
excluido del paquete nuevo (`!api/config/db.local.php` en
`electron-builder.yml`), pero eso solo garantiza que el instalador no lo
sobrescribe con un archivo nuevo — no garantiza que el proceso de actualización
de NSIS no limpie/recree ese subárbol y se lo lleve puesto de paso.

**Fix:** `db.local.php` se movió a `C:\ProgramData\LogosPOS\db.local.php` —
la misma carpeta donde ya viven `logos-config.json`, los datos de MariaDB y
los logs, fuera de cualquier cosa que el instalador reempaquete. Cambiado en:
`api/config/db.php` (`DB::CONFIG_PATH`, con migración automática best-effort
desde la ubicación vieja si todavía existe ahí, para no romper instalaciones
que actualicen desde una versión anterior a este fix), `electron/services/server-manager.js`
(`_writeDbLocal()`), `electron/resources/setup-server.ps1` (rol Servidor,
paso de instalación), `api/controllers/InstalacionController.php` (rol
Cliente, wizard web).

**Esto no queda 100% verificado hasta que Rodrigo confirme** que, tras
actualizar a la versión con este fix una vez (necesita sobrevivir una
actualización más desde la ubicación vieja para migrar), las próximas
actualizaciones ya no vuelven a mandar a una instalación configurada al
wizard.

### Caso real (02/08/2026): migraciones selladas como "aplicadas" sin correr nunca, en una base vieja reinstalada

Tras reinstalar sobre un `mysql-data` viejo (conservado a propósito en
`C:\ProgramData\LogosPOS`, mismo diseño que protege la base de un cliente
real), la app abrió directo al POS pero productos/rubros/marcas tiraban
500. `php-error.log` mostró `Unknown column 't.regla_precio_id'` y
`Unknown column 'activo'` — columnas de migraciones que nunca se aplicaron.

Causa: `_runMigrations()` en `electron/services/server-manager.js` asumía
que "cero filas en `_schema_migrations`" significaba instalación fresca (y
por lo tanto `schema_limpio.sql` ya cubre todo) → sellaba TODOS los archivos
de `migrate/*.sql` como aplicados sin ejecutarlos. Una base vieja reutilizada,
sin la tabla `_schema_migrations` (porque es de antes de que existiera ese
mecanismo), cae en el mismo caso "cero filas" — y terminó con migraciones
reales nunca aplicadas, de forma silenciosa y permanente.

**Fix:** se eliminó el atajo. Ahora toda migración pasa siempre por el loop
normal (intenta correrla, si falla con "ya existe" la sella igual). En una
instalación realmente fresca el resultado es el mismo de antes (todo
"falla" benignamente con "ya existe" y queda sellado); en una base vieja,
la que falte se aplica de verdad. Instalaciones que ya tenían
`_schema_migrations` con filas no cambian de comportamiento.

**Recuperación manual si ya pasó:** las filas falsas quedan en
`_schema_migrations` y no se autocorrigen solas — hay que limpiar la tabla
(`DELETE FROM _schema_migrations;` con el `mysql.exe` de
`resources\mariadb\bin`) y reabrir la app para que las migraciones reales
corran esta vez. No hay pérdida de datos: solo agrega las columnas/tablas
que faltaban sobre las tablas existentes.

### Caso real (02/08/2026): desinstalador con borrado completo opcional + `mysql_install_db` exige datadir 100% vacío

A pedido de Rodrigo se agregó una opción de borrado completo al
desinstalador (`installer.nsh::customUnInstall`): pregunta con `MessageBox
MB_YESNO` (nunca en modo silencioso — ver regla de esperas invisibles más
arriba, mismo principio: nada irreversible sin confirmación humana) si
borrar también `C:\ProgramData\LogosPOS` y `%APPDATA%\logos-pos`. Default
ante cualquier cosa que no sea un "Sí" explícito es no borrar nada.

Al reinstalar después de un borrado completo, `setup-server.ps1` tiró
`ERROR: Data directory ... is not empty` aun con la carpeta `mysql` (la
que el script usa para decidir "hace falta inicializar") ya ausente —
quedó algún resto suelto (`.pid`/`.err` de un `mysqld` que no llegó a
cerrar del todo limpio antes de que `RMDir /r` corriera). `mysql_install_db.exe`
exige el datadir completamente vacío, no solo sin la carpeta `mysql`.

**Fix:** en vez de perseguir la carrera exacta del desinstalador (nunca se
puede garantizar al 100% contra archivos bloqueados/antivirus escaneando),
`setup-server.ps1` ahora vacía `$dataDir` por las dudas, justo antes de
inicializar, cada vez que decide que hace falta una inicialización fresca —
autocorrige cualquier resto sin importar de dónde vino.
