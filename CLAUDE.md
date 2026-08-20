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
- **`www\app`** (el build de la SPA, `tauri.conf.json` → `"../../app/build": "www/app"`)
  se limpia por completo (`RMDir /r`) en `NSIS_HOOK_PREINSTALL` antes de cada
  instalación/actualización — ver caso real 12/08/2026 más abajo. Si algún
  cambio futuro toca ese hook, **no extender el `RMDir /r` a `www\Logos`** —
  esa carpeta mezcla código de la app (que sí se puede regenerar sin
  problema) con `www\Logos\uploads\` (comprobantes, adjuntos de compras,
  archivos de importación — datos reales del negocio, no build). Borrarla
  de más sería el mismo tipo de incidente que el de `db.local.php`, pero con
  pérdida de datos real en vez de un falso "no instalado".
- **Migraciones en `migrate/*.sql`** corren solas en cada arranque
  (`tauri-shell/src-tauri/src/server_manager.rs::run_migrations()` — Electron
  quedó retirado del canal de updates, `electron/services/server-manager.js`
  es la versión vieja, no la que corre en producción). Si una migración
  nueva falla a mitad de camino, puede dejar el estado de la base inconsistente
  justo antes de que se evalúe si el sistema "ya está instalado". Desde
  12/08/2026 los fallos de migración también quedan en
  `C:\ProgramData\LogosPOS\logs\migrations.log` (antes solo un `println!`
  invisible en un cliente real con la ventana minimizada).
- **Instalaciones nuevas vs. actualizaciones sobre una base existente son
  caminos de código distintos** — un fix o feature puede probarse OK en una
  instalación desde cero y romper igual el camino de actualización (y viceversa).
  Hay que pensar los dos casos por separado, no asumir que probar uno cubre el otro.

### Convención al escribir una migración nueva en `migrate/*.sql`

Caso real (12/08/2026): auditoría completa de `migrate/*.sql` (74 archivos)
tras un incidente de datos de prueba filtrados a producción (ver caso
"admin de fábrica" más abajo) encontró dos problemas de la misma familia,
uno puntual y uno estructural. Ambos ya corregidos — `migrate/75_fix_orden_configuracion.sql`
para el puntual, este apartado documenta cómo evitar que se repitan.

**El orden de ejecución es alfabético simple, no numérico.** El runner
(`server_manager.rs::run_migrations()`) hace un `sort()` de los nombres de
archivo tal cual — `"11_afip_fantasia.sql"` y `"12_color_tema.sql"` ordenan
**antes** que `"12_configuracion.sql"` (que es el archivo que efectivamente
crea la tabla `configuracion`), porque comparando texto `"11_a" < "12_c"` y
`"12_color" < "12_config"`. Antes de nombrar un archivo nuevo que hace
`ALTER TABLE` sobre una tabla creada en otra migración, confirmar que el
nombre nuevo ordena alfabéticamente **después** del archivo que la crea —
no alcanza con que el número sea "razonable", hay que comparar el string
completo. Correr `php tools/check_migraciones_orden.php` antes de cada
publish detecta esto automáticamente (falla con exit code 1 si encuentra un
`ALTER TABLE` que ordena antes que su `CREATE TABLE`).

**Un archivo con varios bloques independientes es tan frágil como su primer
error.** El runner le manda el archivo entero a `mysql` de una sola vez
(`mysql_pipe_input`), y `mysql` aborta el resto del script en el primer
error (confirmado con una prueba real, no es una suposición). Como el
runner sella el archivo completo como "aplicado" ante cualquier fallo —sin
importar en qué statement pasó—, un archivo con N tablas independientes
donde la tabla 3 falla deja las tablas 4, 5, 6... sin su cambio, en
silencio, para siempre (esto ya pasó una vez de verdad, ver
`70_fix_tour_demo.sql`). **Convención:** si una migración nueva toca varias
tablas/entidades que no dependen entre sí, partirla en un archivo por
entidad — así el radio de daño de un fallo queda acotado a esa tabla, no a
todo el archivo. Lo que SÍ tiene que quedar junto en un mismo archivo es una
secuencia inherentemente atómica para una sola tabla (ej. agregar columna
nullable → backfill de filas existentes → `NOT NULL` → trigger, el patrón
que ya usa `54_sync_uuid.sql`) — ahí partirlo no gana nada, la secuencia
depende de sí misma de todos modos.

### Caso real (12/08/2026): admin "de fábrica" (PIN 1234) sembrado en toda instalación nueva

`migrate/12_configuracion.sql` y `migrate/13_usuarios.sql` traían `INSERT`
hardcodeados con datos de una instancia de prueba — negocio "BULFON
GUILLERMO JESUS", cajas "Ferretería/Sanitarios/Compras" y un usuario
"Admin" con PIN `1234` — que corrían en **toda instalación nueva**, no solo
en desarrollo, porque las migraciones corren solas en cada arranque. Un
cliente real vio exactamente esos datos en una PC completamente nueva
durante una demo.

Efecto colateral más grave que el dato de prueba en sí: como el admin
sembrado ya existe al arrancar, `InstalacionController::estado()` calcula
`requiere_admin` en `false` desde el primer arranque (antes de que el
usuario toque una sola pantalla del wizard), y `POST /instalacion/admin`
rechaza con 403 "ya existe un administrador". El wizard interpreta ese 403
como "instalación interrumpida" (lógica agregada el 01/08/2026 para ese
caso legítimo) y reusa el ID del admin sembrado en vez de crear el que el
cliente tipeó — si cualquier paso posterior del commit falla (candidato:
AFIP), el negocio y las cajas de prueba quedan como datos "reales" del
cliente y el PIN de fábrica `1234` como único acceso al sistema.

**Fix para instalaciones nuevas:** se sacaron los `INSERT` de esas dos
migraciones — de acá en adelante el wizard crea admin/negocio/cajas reales
sin pisarse con nada sembrado.

**Fix para instalaciones que ya corrieron con el bug:** no se puede borrar
ni desactivar esa cuenta sin más — puede ser el único acceso real que un
cliente usa hoy (misma regla de "nunca cortar acceso a una instalación que
ya funciona"). En cambio, `migrate/74_fix_admin_semilla.sql` agrega
`usuarios.debe_cambiar_pin` y lo marca en `true` solo para la fila cuyo
`pin_hash` coincide EXACTO con el hash bcrypt sembrado (el salt aleatorio
de bcrypt hace que este match sea inequívoco — si un cliente cambió el PIN
en algún momento, aunque haya vuelto a poner "1234", el hash sería
distinto y no lo toca). El login (`app/src/routes/login/+page.svelte`)
fuerza un paso de "elegí un PIN nuevo" antes de completar la sesión cuando
ve ese flag — no bloquea el acceso, solo exige reemplazar el PIN antes de
entrar. `UsuariosController::actualizar()` limpia el flag en cuanto se
guarda cualquier PIN nuevo (por este flujo o desde Configuración > Usuarios
como admin).

### Caso real (12/08/2026): builds viejas de la SPA acumulándose sin límite en cada instalación

Reporte de Rodrigo tras una demo: en su notebook de pruebas (versión 1.0.9),
tocar "Cierre Parcial"/"Cierre Diario" en Caja no hacía nada, y tampoco
funcionaba la barra de ajuste de precios en POS — sin ningún error visible,
ni por mouse ni por teclado (Tab + Enter sobre el botón enfocado tampoco
reaccionaba). El resto de la app andaba perfecto. Devtools deshabilitados
en el build de release (`Cargo.toml` no tiene el feature `devtools` de
`tauri`), así que no había consola para mirar.

Se pidió un `.rar` de la carpeta de instalación real para inspeccionar
directamente. Confirmado con evidencia concreta (fechas de archivo, no
suposición): `www\app\_app\immutable\nodes` tenía **72 archivos** cuando
un build limpio tiene ~26 — hasta 5 versiones distintas del mismo archivo
lógico coexistiendo (ej. `1.CCrPyIdS.js`, `1.DktBCB27.js`, `1.HAycyUVD.js`,
`1.IzSBu6ND.js`, `1.k4nW5acs.js`), con fechas que abarcaban desde
11/08 20:50 hasta 12/08 13:42 — evidencia de ~5 publicaciones distintas
acumuladas sin limpiar nunca.

Causa: `tauri.conf.json` mapea `"../../app/build": "www/app"` como uno de
los `resources` que el instalador NSIS extrae. Vite (adapter-static) le
pone un hash de contenido a cada archivo de `_app/immutable/`, así que
**cambia el nombre de archivo en cada build**, incluso para chunks sin
cambios reales. NSIS solo tiene instrucciones para extraer/sobreescribir
los archivos que están en el instalador actual — nunca tuvo instrucción de
borrar lo que ya no forma parte del build nuevo, así que cada actualización
sumaba archivos en vez de reemplazarlos. `index.html` (que si tenía la
referencia correcta al build más nuevo) sí se sobreescribe limpio porque es
un solo archivo con nombre fijo — el problema son específicamente los
archivos con hash acumulados alrededor suyo.

Se descartó que fuera caché del navegador (WebView2): los nombres de
archivo son únicos por contenido, cachear una URL específica para siempre
es correcto y no explica el síntoma. Se confirmó por diff que **`api/`,
`pos/`, `migrate/`, `vendor/` no tienen este problema** — son archivos con
nombre fijo (no hasheado), así que NSIS los sobreescribe correctamente
archivo por archivo; el problema es específico del build de la SPA.

**Fix:** `NSIS_HOOK_PREINSTALL` nuevo en `installer-hooks.nsh` —
`RMDir /r "$INSTDIR\www\app"` antes de que arranque la extracción de
archivos, en cada instalación/actualización. Mismo criterio que
`setup-server.ps1` ya usa para el datadir de MariaDB ("vaciar por las
dudas antes de inicializar" en vez de perseguir qué archivo puntual no se
sobreescribió). Ver la nota en "Puntos de falla conocidos" más arriba sobre
por qué este `RMDir /r` **no debe extenderse** a `www\Logos` completo (ahí
vive `uploads/`, con datos reales del negocio).

**Pendiente de confirmar:** que una actualización real (no una instalación
limpia) sobre una instalación con este problema se auto-corrija sola — el
fix limpia la carpeta antes de extraer, así que un update silencioso normal
debería alcanzar, sin necesitar desinstalar. Falta la verificación en una
PC real tras publicar la próxima versión.

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

### Caso real (14/08/2026): logo ausente en los PDFs — mismo patrón que `db.local.php`, pero en Dompdf

Reporte de Rodrigo: el logo se sube bien en el wizard/Configuración (la
preview lo muestra), pero nunca aparece en los comprobantes PDF generados.
Diagnóstico inicial por lectura de código (comparar `comprobante_pdf.php`
contra el patrón "funcionando" de `pedido_pdf.php`) no encontró nada — ambos
calculan `$logo` igual y lo insertan en un `<img>` sin ninguna diferencia
visible. Se confirmó la causa real reproduciendo el render con un script
aislado (`Dompdf\Image\Cache::resolve_url()` llamado directo): Dompdf lo
rechaza en silencio con `"Permission denied. The file could not be found
under the paths specified by Options::chroot."` — sin ese diagnóstico
puntual, el síntoma es indistinguible de "el archivo no existe" (no tira
excepción visible, el PDF simplemente sale sin logo).

Causa: los 4 generadores de PDF (`ComprobanteGenerador.php`,
`ReciboGenerador.php`, `PedidoController.php`, `NotasEnvioController.php`)
configuran `$options->setChroot([realpath(__DIR__ . '/../..')])` — el árbol
de la app únicamente. El logo vive en `DB::dataRoot()`
(`C:\ProgramData\LogosPOS\logo_background.png`, ver "Puntos de falla
conocidos" más arriba sobre por qué vive ahí) — **fuera** de ese chroot.
`Dompdf\Options::validateLocalUri()` exige que la ruta real del archivo
empiece con uno de los directorios permitidos; como no matchea ninguno,
descarta el `<img>` sin lanzar ningún error visible al llamador (queda
como warning interno de Dompdf, no como excepción). Es el mismo patrón que
ya rompió `db.local.php` en agosto: mover un archivo a ProgramData para
protegerlo del reempaquetado de NSIS, sin actualizar todos los lugares que
además restringen el acceso a rutas de archivo por seguridad. `PedidoController.php`
tenía exactamente el mismo bug pese a asumirse como "el caso que sí
funciona" — nunca se había verificado en la práctica, solo por lectura de
código (tenía el guard `file_exists()`, pero el guard nunca hace `false`
porque el archivo sí existe en disco — el rechazo pasa después, adentro de
Dompdf).

**Fix:** agregado `DB::dataRoot()` como segundo directorio permitido en el
`setChroot()` de los 4 generadores. Verificado con un script aislado:
contando `/Image`/`/XObject` en el PDF crudo generado, pasó de 0 referencias
(sin el fix) a 6 (con el fix, PDF de ~230KB vs ~1.6KB antes).

**Convención a partir de ahora:** cualquier `setChroot()` nuevo (o
Dompdf `Options` nuevo) tiene que incluir `DB::dataRoot()` si el HTML que
va a renderizar puede referenciar cualquier archivo fuera del árbol de la
app — no alcanza con que el archivo exista y sea legible por PHP en general,
Dompdf tiene su propia lista de rutas permitidas, independiente del
filesystem.

### Caso real (14/08/2026): `npm run publish` no reconstruye la SPA — el `app/build` empaquetado puede quedar viejo sin que ningún número de versión lo delate

Reporte de Rodrigo en v1.0.16: el botón "Nuevo depósito"/"Editar depósito" en
Configuración no hacía nada, y el indicador nuevo de logo ("✓ Ya tenés un
logo cargado") tampoco aparecía — pese a estar en el código fuente de esa
misma versión. La vista previa del logo SÍ se veía bien (confirmado con
Rodrigo), lo que descartó un problema de backend/Dompdf y aisló el síntoma
al bundle de JS servido.

Causa raíz: `tauri-shell/src-tauri/tauri.conf.json` → `build.beforeBuildCommand`
es `"npm run build"`, que corre en el contexto de `tauri-shell/` (compila
`tauri-shell/dist`, un frontend mínimo propio del shell) — **nunca toca
`app/`**, el árbol real de la SPA SvelteKit. `app/build` (lo que
`bundle.resources` empaqueta como `www/app`, ver "Puntos de falla conocidos")
solo se genera corriendo `npm run build` a mano DENTRO de `app/`, un paso
completamente separado que `npm run publish` (`tauri build && node
scripts/publish.js`) nunca dispara solo. Bumpear la versión en
`Cargo.toml`/`tauri.conf.json` y publicar no garantiza en absoluto que la
SPA empaquetada sea la del código fuente actual — puede quedar congelada en
lo que sea que `app/build` tenía de la última vez que alguien corrió el
build de `app/` a mano, sin importar qué tan reciente sea el resto.

**Fix:** `tauri-shell/package.json` → script `publish` ahora es
`"npm --prefix ../app run build && tauri build && node scripts/publish.js"`
— fuerza un build fresco de la SPA como primer paso, siempre, antes de
empaquetar. Verificado corriendo el comando: resuelve el path correcto
(`C:\xampp\htdocs\Logos\app`) y termina en `Wrote site to "build"` sin error.

**Si en el futuro un cambio de la SPA "no aparece" en una versión ya
publicada** (y no es el caso ya conocido de `pos/*.html` legacy vs SPA, ver
más abajo): sospechar primero de esto — comparar la fecha de modificación de
los archivos en `app/build/_app/immutable/` contra la fecha del último
commit tocado, antes de asumir que el fix en sí está mal.

### Caso real (14/08/2026): modal de "Nuevo depósito"/"Editar depósito" invisible e inclickeable — colisión de clase CSS con `pos/neo.css`

Bug de larga data (no introducido en esta sesión), encontrado al investigar
el reporte de arriba. `pos/neo.css` define un patrón para "modales legacy
activados con JS via classList": `.overlay, .modal-overlay { opacity:0;
visibility:hidden; pointer-events:none; ... }` con `.overlay.abierto,
.modal-overlay.abierto { opacity:1; visibility:visible; pointer-events:auto;
}` — pensado para un elemento SIEMPRE montado en el DOM que un script
externo togglea agregando/sacando la clase `abierto`.

El modal de depósito en `app/src/routes/(app)/configuracion/+page.svelte`
reusa el nombre de clase `modal-overlay` pero sigue el patrón de Svelte
(`{#if modalDeposito}<div class="modal-overlay">...`) — el div solo existe en
el DOM mientras está abierto, nunca necesitó ni agregó la clase `abierto`. El
`<style>` scoped del componente solo pisa `position/display/z-index/etc`,
nunca `opacity`/`visibility`/`pointer-events` — así que esas tres propiedades
caían solas en el valor de `neo.css` (oculto), dejando el modal presente en
el DOM (confirmado con `getComputedStyle`) pero invisible y con `pointer-
events:none`, indistinguible en pantalla de "el botón no hace nada".

**Fix:** el div ahora es `class="modal-overlay abierto"` (fijo, no
condicional) — como el `{#if}` ya controla el montaje/desmontaje, no hace
falta la transición basada en classList que el patrón legacy sí necesita.
Verificado con `getComputedStyle` antes/después (`opacity:0→1`,
`visibility:hidden→visible`, `pointerEvents:none→auto`) y creando un depósito
real de punta a punta contra la API.

**Convención a partir de ahora:** cualquier `<div class="modal-overlay">` (o
`.overlay`) nuevo en la SPA que esté gateado por `{#if}` tiene que incluir
`abierto` en la clase desde el vamos — `neo.css` lo exige y lo oculta en
silencio si no.

### Caso real (14/08/2026): eliminar una sucursal no la sacaba del selector del nav

Reporte de Rodrigo en una segunda PC (v1.0.16, actualizada por auto-update
normal): borró la sucursal "Rodrigo Gaston Gomez" (la que el wizard crea con
el nombre/razón social del negocio) desde Configuración, y desapareció ahí
— pero siguió apareciendo en el selector de sucursal del nav.

Causa: `app/src/routes/(app)/+layout.svelte` arma la lista del nav leyendo
`sesion.sucursales`, y `sesion` sale de `leerSesion()` (`$lib/session.ts`) —
un `onMount` que corre **una sola vez** al montar el layout (que a su vez
solo se monta una vez por sesión de navegación de la SPA, no en cada
cambio de ruta). Esa lista queda fijada en `localStorage` desde el momento
del login. `eliminarSucursal()`/`guardarSucursal()` en
`configuracion/+page.svelte` actualizaban la base y su propia lista local
(vía `cargarSucursales()`), pero nunca tocaban `localStorage.logos_sesion`
— el nav seguía sirviendo la foto vieja hasta el próximo login manual.

**Fix:** `session.ts::actualizarSucursalesSesion()` (nuevo) reescribe
`sesion.sucursales` en localStorage y dispara un `CustomEvent`
(`logos:sesion-actualizada`) en `window`; `+layout.svelte` lo escucha (desde
su mismo `onMount`) y relee la sesión, lo que dispara el `$effect` que ya
existía para recalcular `sucursales` — sin necesitar logout/login.
`cargarSucursales()` en Configuración llama esto cada vez que refresca su
propia lista (crear, editar, eliminar, activar/desactivar), filtrando solo
`activo` (mismo criterio que usa el login para armar esa lista). Verificado
en vivo: borrar una sucursal por API hace desaparecer la opción del
`<select>` del nav sin reload, y localStorage refleja la lista nueva al
toque.

**Alcance del fix:** solo la sesión de quien hizo el cambio (siempre un
admin, `Auth::requireAdmin()` gatea esos endpoints). Otra PC/usuario con
sesión ya abierta en simultáneo sigue con su lista vieja hasta su propio
próximo login — no hay push entre sesiones, y no hacía falta para el caso
reportado.

### Endurecimiento de `localStorage` (14/08/2026) — sync, CSP, auto-logout por inactividad

A raíz de auditar todos los usos de `localStorage` del proyecto, cinco
mejoras de bajo riesgo, sin cambiar ningún comportamiento visible:

- **`$lib/storage.ts`** (nuevo): `leerJSON`/`guardarJSON` genéricos con
  `try/catch` adentro — `session.ts` ya lo usa para `leerSesion`/
  `guardarSesion`. Reduce el boilerplate repetido, no cambia contrato.
- **`+layout.svelte`**: además del `CustomEvent` puntual para sucursales
  (ver caso de arriba), ahora también escucha el evento nativo `storage` —
  cubre cambios a `logos_sesion` hechos desde OTRA pestaña/ventana (el
  evento custom solo cubre la misma pestaña). Si `logos_sesion` desaparece
  desde otra ventana, esta también redirige a `/login`.
- **Limpieza de `*_toast_*` viejos**: `lic_toast_<fecha>`/`afip_toast_<fecha>`/
  `backup_toast_<fecha>` nunca se borraban solos (no hay TTL nativo en
  localStorage). Se limpia cualquiera de más de 2 días al montar el layout.
- **CSP** (`electron/www/router.php`, nuevo `cspHeader()`): agregado en los
  dos puntos donde se sirve un documento HTML real (`index.html` de la SPA
  y `pos/*.html` legacy) — `api/helpers/Seguridad.php` solo cubre respuestas
  JSON de la API, eso no protege nada del lado del documento. Necesita
  `'unsafe-inline'` en `script-src`/`style-src` a propósito: tanto el
  bootstrap de `app.html` como cada página `pos/*.html` dependen de
  `<script>`/`style=""` inline — una CSP estricta rompería el arranque.
  Igual bloquea lo más importante ante un XSS: `connect-src 'self'` impide
  exfiltrar un token robado a un servidor externo, `script-src 'self'`
  impide cargar un script remoto. Verificado sirviendo la app real con
  `php -S` + este router: sin violaciones de CSP en consola, todos los
  recursos cargan 200 OK.
- **Auto-logout por inactividad** (`+layout.svelte`, 15 min): complementa
  el logout forzado en cada arranque real del proceso (01/08) — ese cubre
  "cerraron y reabrieron la app", este cubre "la dejaron abierta y
  desatendida en el mostrador". Cuenta desde el último `mousedown`/
  `keydown`/`touchstart`, sin importar si la ventana tiene foco.

Se evaluó y se descartó a propósito: mover el token de sesión a una cookie
`HttpOnly` (reescribiría el modelo de auth multi-PC entero para un riesgo
que hoy no es explotable — no hay ningún `{@html}`/`innerHTML` con datos de
usuario real en la SPA, confirmado por auditoría) y migrar a `sessionStorage`
o `IndexedDB` en general (perdería la persistencia entre pestañas/recargas
que varias de estas claves necesitan a propósito, o agregaría complejidad
async sin beneficio real dado el tamaño chico de los datos guardados acá).

### `electron/` desapareció del repo (14/08/2026) — quedó consolidada en `tauri-shell/`

La carpeta `electron/` mezclaba dos cosas sin que el nombre lo delatara:
infraestructura que Tauri todavía usaba de verdad (binarios portables de
MariaDB/PHP/nssm.exe, `router.php`) y la app Electron vieja en sí,
retirada del canal de updates hace rato pero nunca borrada — el código
Rust ya la reemplazó pieza por pieza (comentarios tipo "Port a Rust de
electron/main.js" en `lib.rs`/`server_manager.rs`/`role.rs`/`timers.rs`/
`tray.rs` siguen ahí a propósito, como nota histórica de dónde salió cada
función — **si buscás esos archivos y no están, es porque se borraron acá,
no por error**, revisá el historial de git si hace falta el original).

Auditoría exhaustiva de las ~213 ocurrencias de "electron" en todo el repo
confirmó cuáles eran referencias funcionales reales (rompían algo si el
archivo desaparecía) contra cuáles eran solo comentarios. Las funcionales
se movieron, se actualizaron las 3 referencias que las consumían
(`tauri.conf.json`, `lib.rs::resolve_paths()` rama dev-mode,
`tauri-shell/scripts/publish.js`), y se borró el resto.

**Dónde quedó cada cosa:**
- `tauri-shell/src-tauri/resources/{mariadb,php,nssm.exe}` — binarios
  (gitignored, igual que antes en `electron/resources/`).
- `tauri-shell/src-tauri/resources/www/router.php` — el router real que
  corre en producción.
- `tauri-shell/scripts/{serve-update.php,htaccess-logos,upload-handler.php,
  download-deps.js}` — infraestructura de publish. `upload-handler.php` es
  el único que sigue gitignored (tiene el secreto real de producción
  adentro — no estaba en git antes tampoco, solo cambió de carpeta).
- `tauri-shell/src-tauri/resources/{setup-server.ps1,teardown-server.ps1}`
  — **no se tocaron**, ya eran forks propios de Tauri, divergentes de los
  de `electron/resources/` (paths sin el prefijo `resources\`, variables
  `LOGOS_APP_BUILD_DIR`/`LOGOS_DATA_ROOT` que Electron nunca tuvo) — los
  de Electron se borraron directamente, no se pisó nada.

**Confirmado huérfano y borrado sin mover a ningún lado:** el resto de la
app Electron (`main.js`, `preload.js`, `services/`, `renderer/`,
`electron-builder.yml`, tooling de build) y `electron/resources/tailscale/`
(carpeta vacía, feature de acceso remoto que nunca se terminó de
implementar ni se portó a Tauri).

Verificado antes de dar el reorden por terminado: `cargo check` limpio,
`cargo tauri dev` real levantando MariaDB/PHP desde la ubicación nueva, y
un `tauri build` completo (build local, sin publicar) generando el
instalador sin errores de recursos faltantes.

### Caso real (15/08/2026): CSP nueva rompió la vista previa de PDF — "Este contenido está bloqueado"

Reporte de Rodrigo: al tocar "Descargar PDF", la vista previa mostraba el
mensaje genérico de Chrome/Edge "Este contenido está bloqueado. Contactá
con el dueño del sitio para arreglar el problema" en vez del PDF.

Causa: la CSP agregada el día anterior (`tauri-shell/src-tauri/resources/www/router.php::cspHeader()`,
ver caso "Endurecimiento de localStorage") no tenía `frame-src` explícito,
así que caía al fallback de `default-src 'self'`. `PdfViewerModal.svelte`
mete el PDF en un `<iframe src="blob:...">` (`$lib/pdf.ts::abrirPdf()`,
`URL.createObjectURL(blob)`) — y **`'self'` no cubre `blob:`** en
`frame-src`/`default-src`, hay que listarlo aparte explícitamente aunque el
blob se haya creado en el mismo origen. Gotcha conocido de CSP, no específico
de esta app, pero fácil de pisar si se agrega una CSP nueva sin pensar en
todos los mecanismos de carga de contenido que la app ya usa.

**Fix:** agregado `frame-src 'self' blob:;` a la CSP. Verificado sirviendo
la app real vía `php -S` + este router y creando un iframe con un blob PDF
de prueba: sin violaciones de CSP en consola.

**Convención:** cualquier ajuste futuro a la CSP tiene que repasar TODOS los
usos de `URL.createObjectURL()` en `app/src` (hoy: `pdf.ts` para vista
previa vía iframe — los demás son descargas por `<a download>`, que no
pasan por `frame-src`) antes de asumir que una directiva nueva no rompe nada.

### Caso real (15/08/2026): barra de selección del POS "cumple pero no es coherente" — `$state(new Set())` no trackea `.add()`/`.delete()`

Reporte de Rodrigo con capturas: en el carrito del POS, tildar un ítem o
"seleccionar todo" no activaba la barra de selección — pero si después pasaba
cualquier otra cosa (agregar un producto, aplicar un descuento), la barra "se
ponía al día" sola. Y lo más confuso: aplicar un descuento con la barra
visualmente "apagada" **igual funcionaba** sobre los ítems realmente
seleccionados, aunque ningún checkbox se viera tildado.

Causa: `let seleccionados = $state<Set<number>>(new Set())`, y
`toggleItemChk()`/`toggleTodos()` mutaban el Set en el lugar
(`seleccionados.add(pid)`, `.delete(pid)`, `.clear()`) en vez de reasignar
la variable. Svelte 5 solo trackea reactividad de `Set`/`Map` a través de
`SvelteSet`/`SvelteMap` (`svelte/reactivity`) — un `Set` nativo envuelto en
`$state()` sigue siendo un `Set` nativo por dentro; `$state()` no interceptó
sus métodos de mutación. El *dato* (`seleccionados.has(...)`, leído en el
momento de tocar un botón) siempre estuvo bien — por eso "el descuento se
aplicaba igual". Lo que nunca se actualizaba solo era la *UI* (`checked` de
cada checkbox, `.sel-bar.activo`, el conteo, el indeterminate del
"seleccionar todo") — hasta que alguna OTRA reasignación de estado (ej.
`items = [...]`) forzaba a Svelte a recalcular todo el árbol y de paso
"pescaba" la mutación ya vieja del Set.

**Primer intento de fix (incompleto, publicado igual en 1.0.21 sin querer):**
`seleccionados` pasó a `new SvelteSet<number>()` sin envolver en `$state()`,
razonando que "`SvelteSet` ya es reactivo por sí solo" — cierto para las
MUTACIONES (`.add()`/`.delete()`/`.clear()`), pero incompleto: el código
también tiene dos lugares que REASIGNAN la variable entera
(`seleccionados = new SvelteSet()`, al arrancar una venta nueva y al
eliminar seleccionados), y una reasignación de un `let` común — sin
`$state()` — no dispara reactividad en Svelte 5, sin importar de qué esté
hecho el valor nuevo. El compilador SÍ avisó esto con un warning real
(`non_reactive_update`: "`seleccionados` is updated, but is not declared
with `$state(...)`") que apareció en el log de `npm run build` — se pasó
por alto en la revisión inicial porque solo se buscaron errores duros, no
warnings, entre el ruido de accesibilidad (a11y) que ya existía de antes.
Rodrigo llegó a publicar la 1.0.21 con este fix a medias antes de que se
detectara al repasar su log de build completo.

**Fix real:** `let seleccionados = $state(new SvelteSet<number>());` — las
DOS capas hacen falta a la vez: `SvelteSet` trackea mutación,
`$state()` trackea reasignación de la variable. Verificado: el warning
`non_reactive_update` desaparece del build, y en vivo (con esperas reales
de ~200ms — Svelte flushea al DOM en batch, no sincrónico) tildar uno,
"seleccionar todo", destildar uno para ver el indeterminate, Y el camino
de reasignación (botón "Eliminar seleccionados", que dispara justo el
`seleccionados = new SvelteSet()` que el warning señalaba) — los cuatro
casos actualizan la barra al toque.

**Se revisaron los otros 6 usos de `$state(new Set()/new Map())` en la
SPA** (`productos`, `taxonomias`, `importar` ×2, `configuracion::cfgTipos`,
POS `catalogoSel`) — todos ya clonan (`new Set(actual)`), mutan la copia, y
reasignan (`variable = copia`), que es el patrón seguro con `$state()` de
un Set nativo. Solo el carrito del POS mutaba el original directo. No hizo
falta tocar los otros.

**Convención a partir de ahora:**
1. Cualquier `Set`/`Map` nuevo en un componente Svelte tiene que ser
   `$state(new SvelteSet())`/`$state(new SvelteMap())` (ambas capas juntas,
   de `svelte/reactivity`) si en algún lugar se lo va a mutar directo
   (`.add()`/`.delete()`/`.set()`/`.clear()`) **y también** reasignar la
   variable en algún otro lugar — que es el caso más común. Un Set nativo
   en `$state()` con disciplina de "siempre clonar y reasignar, nunca
   mutar" también es válido, pero es más frágil (un solo `.add()` directo
   que se cuele rompe todo) — preferir `$state(new SvelteSet())` para
   código nuevo.
2. **Repasar el log completo de `npm run build`/`vite build` en busca de
   warnings de `vite-plugin-svelte`, no solo errores** — este caso puntual
   (`non_reactive_update`) es exactamente el tipo de aviso que el propio
   compilador da gratis y que se perdió entre ruido de a11y preexistente
   sin relación. Antes de dar un fix de reactividad por verificado, buscar
   el nombre de la variable tocada en el log de build completo.

### Limpieza de warnings de accesibilidad (15/08/2026) — build queda en 0 warnings

Los 34 warnings de a11y que tapaban el `non_reactive_update` de arriba se
arreglaron todos, sin cambiar comportamiento con mouse:

- **Dropdowns del nav** (`+layout.svelte`, Cta. Cte./Contactos/Productos/
  Caja/Reportes): se agregó `onfocusin`/`onfocusout` junto a los
  `onmouseenter`/`onmouseleave` existentes — tabular hasta el link del
  desplegable ahora también lo abre, igual que pasarle el mouse por
  encima. "Caja" y "Reportes" no tienen página propia (`href="#"`, inválido
  para a11y) — pasaron a `<button>` con un reset de estilo nativo
  (`button.nav-link { background:none; border:none; ... }`) para verse
  idénticos a los `<a>`. "Cerrar sesión" (mismo problema de `href="#"`)
  también pasó a `<button>`.
- **Tarjetas clickeables del dashboard** (Ganancia bruta, CC vencida):
  `role="button" tabindex="0"` + `onkeydown` (Enter/Espacio) además del
  `onclick` que ya tenían, más un `:focus-visible` con outline (no existía
  ningún indicador visual de foco antes).
- **Dropdowns de búsqueda con selección por teclado ya implementada**
  (proveedor/producto en `compras-nueva`, cliente en `ventas` al confirmar
  presupuesto): estos YA tenían navegación completa por flechas+Enter en
  el `<input>` (`onProvKeydown`/`onP2Keydown`) — el `onclick` de cada ítem
  del desplegable es solo el atajo de mouse. El compilador igual pedía un
  `onkeydown` en el propio `<div>` del ítem (aunque nunca vaya a recibir
  foco con `tabindex="-1"`) — se agregó reflejando la misma acción del
  `onclick`, honesto con la regla aunque en la práctica ese código nunca
  dispare por esa vía específica.

**Verificado:** `npm run build` queda en **0 warnings** de
`vite-plugin-svelte` (antes: 34 de a11y + el de reactividad ya corregido
arriba). Regresión probada en vivo: el dropdown "Caja" sigue abriendo con
click igual que antes, "Cerrar sesión" sigue sin `href`. Se repasaron
además, a pedido de Rodrigo, los atajos globales del POS
(`onKeydownGlobal`, F2/F3/F4/F5/F10/Shift+1-6/Escape) uno por uno en vivo
— todos funcionan, incluyendo los guardas que los suprimen mientras se
escribe en un input o hay un modal abierto.

### Caso real (14/08/2026): `stock_minimo` en null rompía crear un producto nuevo

Pedido de Rodrigo: poder crear un producto dejando "Stock mínimo" vacío (no
debería ser obligatorio). El frontend (`productos/+page.svelte`) ya mandaba
`stock_minimo: null` cuando el campo queda vacío, y a simple vista el backend
parecía tolerarlo (`ProductosController::crear()` tenía
`$body['stock_minimo'] !== null ? (float)... : null` — un patrón que se ve
"seguro"). El problema: la columna `productos.stock_minimo` es
`decimal(14,4) NOT NULL DEFAULT 0.0000`, y mandar un `NULL` **explícito** en
un INSERT nunca dispara el `DEFAULT` de la columna — el `DEFAULT` solo aplica
cuando la columna se omite del todo de la lista de columnas del INSERT. El
resultado era un 500 silencioso (`Column 'stock_minimo' cannot be null`) que
solo se vio probando la creación real vía la UI, no por lectura de código.

**Fix:** en `crear()` (no en `put()`, que usa `COALESCE(?, stock_minimo)` y
ahí sí es seguro dejarlo en `null` porque significa "no tocar el valor
existente"), el default pasó de `null` a `0` — mismo criterio que ya usaba
`stock_inicial` un par de líneas arriba, por el mismo motivo.

**Convención a partir de ahora:** en cualquier método `crear()`/INSERT nuevo,
un campo opcional que mapea a una columna `NOT NULL DEFAULT x` en el schema
tiene que defaultear a ese mismo valor en PHP cuando el body no lo manda —
nunca a `null`. El patrón `null` para "no tocar" solo es válido en updates
con `COALESCE`, donde ya existe una fila con un valor previo que preservar.

### Caso real (14/08/2026): Notas de Crédito standalone — sobre-acreditar stock y plata si el CAE falla

Al implementar Notas de Crédito A/B/C acreditables por ítems (con reversión
de stock y descuento de caja/cuenta corriente), la validación de "cuánto ya
se acreditó de esta factura" solo contaba NC con `cae IS NOT NULL` — clonando
el criterio que ya usaba la versión anterior de `emitirNc()` (que era
puramente monetaria y sin ningún efecto en stock/caja/CC, así que ahí ese
criterio era inofensivo). Con el nuevo diseño, el stock se restaura y la
plata se mueve **en el mismo commit que crea la fila de la NC, antes de
pedirle el CAE a ARCA** (mismo desacople que el resto del sistema ya usa
entre "crear el comprobante" y "que ARCA lo autorice" — ver la nota sobre
facturación asincrónica). Eso significa que una NC con CAE pendiente o
fallido (comprobado en dev: sin certificado ARCA cargado, cada intento
falla) **ya movió stock y plata de verdad**, aunque no cuente todavía como
"acreditada" para la validación. Se reprodujo en vivo: pedir una NC por 4
unidades de un producto que ya tenía 2 acreditadas por una NC anterior sin
CAE (de una venta de solo 5 unidades) **no fue rechazado** — el stock quedó
sobre-restaurado y la cuenta corriente del cliente sobre-acreditada, muy por
encima del total real de la factura original.

**Fix:** la condición pasó a contar cualquier NC no anulada
(`estado != 'anulado'`), sin filtrar por `cae`, ya que el efecto real
(stock/caja/CC) ocurre al crear la fila, no al autorizarla. El único evento
que libera esa cantidad de vuelta es anular la NC — que a su vez está
bloqueado desde `eliminar()` para filas `NC *` (ver abajo), así que hoy la
única forma de "liberar" una NC trabada sin CAE es reintentar la
autorización sobre la misma fila (`POST /ventas/{id}/facturar`), nunca crear
una NC nueva por los mismos ítems.

**Por qué `eliminar()` (anular venta) rechaza las NC:** la reversión
genérica de esa función asume la dirección de una venta normal (una venta
restó stock al crearse → anular se lo devuelve; una venta con `tipo_pago='cc'`
sumó deuda al cliente → anular se la saca). Una NC hace exactamente lo
opuesto en ambos sentidos (restaura stock y descuenta plata **al crearse**),
así que reutilizar esa misma lógica para "anular" una NC duplicaría el
efecto en vez de deshacerlo — y ni siquiera toca `caja_movimientos`, así que
una NC por caja quedaría con el retiro de caja aplicado para siempre aunque
se la diera por "anulada". Se bloqueó explícitamente en vez de intentar una
reversión correcta con criterio propio, dado el riesgo de introducir un
segundo bug del mismo tipo bajo presión de tiempo — queda documentado acá
como deuda pendiente si en algún momento hace falta poder anular una NC sin
CAE de verdad.

### Caso real (14/08/2026): precio/costo en blanco rompían crear un producto — mismo bug de `stock_minimo`, no detectado por probar con "0" en vez de dejar vacío

El fix de `stock_minimo` (más arriba) se probó creando un producto con ese
campo realmente vacío — y por eso se detectó el 500. Pero el mismo día, al
"confirmar" que precio y costo en 0 ya funcionaban, la prueba en vivo se hizo
escribiendo el valor `"0"` en el campo (vía JS, no dejándolo vacío de
verdad) — un caso que YA andaba bien de antes. Nunca se probó el caso real
que pedía Rodrigo: dejar el campo completamente vacío. Rodrigo lo reprodujo
él mismo con capturas reales (`Column 'precio_venta' cannot be null` y
`Column 'costo_actual' cannot be null`) — exactamente el mismo patrón que
`stock_minimo`: `ProductosController::crear()` mandaba `null` explícito
cuando el campo viene vacío, contra columnas `decimal(14,4) NOT NULL
DEFAULT 0.0000`.

**Fix:** mismo criterio que `stock_minimo`/`stock_inicial` — default a `0`
en vez de `null` cuando el body no manda el campo, en `crear()` únicamente
(no en `put()`, que usa `COALESCE` y ahí `null` sigue significando "no
tocar el valor existente", uso legítimo).

**Lección de método:** "probar creando un producto" no es lo mismo que
"probar dejando el campo vacío como lo haría un usuario real". Para
cualquier campo opcional que mapee a una columna `NOT NULL DEFAULT x`, la
prueba tiene que reproducir el formulario vacío de verdad (campo sin tocar,
no un valor puesto a mano que simule "vacío"), porque son dos caminos de
código distintos en el frontend (`campo !== '' ? parseFloat(campo) : null`)
que solo el segundo ejercita.

### Caso real (15/08/2026): letra del comprobante seguía sin centrar tras el primer intento — line-height centra la línea, no el glifo

El primer intento de fix (`box-sizing: content-box` en `.letra-box`, caso
de arriba en este mismo archivo) no se pudo verificar visualmente en el
momento (sin `pdftoppm` disponible) y quedó pendiente de que Rodrigo lo
confirmara con un comprobante real. Lo hizo, con captura: la letra seguía
visiblemente corrida arriba-a-la-izquierda dentro del recuadro, sin cambios
apreciables.

Causa real: el truco de `line-height: 13mm` (igual al `height` de la caja)
centra la **línea de texto**, no el glifo en sí. Ese cálculo reserva espacio
simétrico arriba y abajo de la línea asumiendo la métrica completa de la
fuente (que incluye espacio para descendentes como la cola de la "g" o la
"y") — pero una letra mayúscula sola (A, B o C, que es siempre lo que va en
ese recuadro) no usa ese espacio inferior, así que visualmente el glifo
queda más arriba que el centro real de la caja. `box-sizing` no tiene nada
que ver con esto — arregla una diferencia de ~1.4px por lado, invisible al
lado de este efecto.

**Fix real:** se reemplazó el truco de `line-height` por `display:table` en
el contenedor + `display:table-cell; vertical-align:middle` en un `<span>`
interno que envuelve la letra. Esta técnica centra según el contenido real
renderizado, no según una asunción sobre la métrica de la fuente — y es la
forma de centrado vertical que Dompdf soporta de manera más madura y
predecible (calca layout de tablas HTML, mucho más probado en Dompdf que su
soporte de flexbox, que es parcial/experimental según la versión).

**Sigue sin verificación visual propia** — mismo motivo que el intento
anterior (sin `pdftoppm`/`gs`/`magick` disponibles en este entorno, y el
panel de navegador tampoco pudo generar una captura del PDF acá). Pendiente
de que Rodrigo lo confirme con un comprobante real de nuevo. Si el problema
persiste después de este segundo intento, el próximo paso sugerido es medir
el offset real superponiendo una grilla sobre el PDF (o pedir una captura
con zoom + regla) en vez de seguir ajustando CSS a ciegas.

### Caso real (18/08/2026): "Body JSON inválido" al modificar el precio de cualquier producto — bug de ruteo preexistente, no de este batch

Reporte de Rodrigo desde una instalación real de cliente (y reproducido en
su propia PC con la 1.0.25): modificar el precio de un producto en
Productos tira "Body JSON inválido". Reproducido en vivo con el navegador
real (no solo curl): el `PUT /productos/{id}` que guarda el precio en sí
**sí funciona** (200 OK, el precio queda guardado) — el error viene de un
SEGUNDO request que `guardarProducto()` dispara siempre después, sin
excepción: `guardarEscalas(id)` (`PUT /productos/{id}/escalas`), pensado
para guardar las escalas de precio por cantidad. Para cualquier producto
SIN escalas cargadas (la inmensa mayoría), ese segundo request manda un
array vacío `[]` — y ESE es el que fallaba, tapando con un error al usuario
un guardado que en el fondo sí había funcionado.

Causa raíz, en `api/index.php` (el parser de URL, no algo tocado en esta
sesión ni en ninguna reciente — bug preexistente): para `/productos/{id}/escalas`,
el segundo segmento (`{id}`, numérico) se guarda en `$id`, y el TERCER
segmento (`escalas`) se guarda en una variable separada, `$subAccion` — no
en `$accion` (esa es para el caso `/productos/{palabra}`, segundo segmento
no numérico). El handler de la ruta `'productos'` nunca capturaba
`$subAccion` en su clausura y comparaba contra `$accion === 'escalas'`, que
para esta URL siempre es `null` — la rama de escalas nunca podía matchear,
y el `match(true)` caía en la rama genérica `PUT $id !== null => put($id)`.
Ese `put()` recibe entonces el array de escalas como si fuera el body de un
producto entero; con el array vacío, `!$body` en PHP es `true` (un array
vacío es "falsy"), disparando exactamente el mismo mensaje genérico "Body
JSON inválido" que usan casi todos los `crear()`/`put()` del proyecto — una
coincidencia de texto que hizo mucho más difícil identificar qué endpoint
realmente estaba fallando.

**Alcance real, más amplio de lo que parecía al principio:** el mismo bug
rompía tanto el guardado (`PUT`) como la LECTURA (`GET`) de escalas — para
`GET /productos/{id}/escalas` la misma condición mal armada hacía que el
`match(true)` cayera en `get($id)` (el producto completo, no sus escalas),
y el frontend (`cargarEscalas()`, `productos/+page.svelte`) intentaba
`data.map(...)` sobre ese objeto — tira una excepción de JS, pero atrapada
en un `catch { /* sin escalas si falla */ }` silencioso, así que nunca se
vio ningún error ahí. Resultado: la función "Escalas de precio por volumen"
estuvo rota (ni cargaba las existentes ni guardaba nuevas) desde que se
implementó, sin que nadie lo notara — el único síntoma visible terminó
siendo este mensaje confuso al editar CUALQUIER precio, sin relación
aparente con escalas.

**Fix:** el closure de la ruta `'productos'` ahora captura `$subAccion` y
las dos condiciones de escalas comparan contra `$subAccion === 'escalas'`
en vez de `$accion`, siguiendo el mismo patrón que ya usan correctamente
`ventas`, `cc`, `notas-envio`, `caja-turnos` y `cheques` para sus propias
rutas `/{recurso}/{id}/{subacción}`. Verificado en vivo con el navegador
real, en los dos casos: producto sin escalas (guarda un array vacío sin
error, modal cierra limpio) y producto con una escala real cargada (se
guarda y se relee correctamente en `escalas_precio`).

**Convención a partir de ahora:** cualquier ruta nueva con forma
`/{recurso}/{id}/{subacción}` tiene que comparar la subacción contra
`$subAccion` (el tercer segmento), nunca contra `$accion` (pensado
específicamente para `/{recurso}/{palabra}`, sin id numérico de por medio)
— y el closure de esa ruta tiene que capturar `$subAccion` en su `use()`
explícitamente, o la comparación contra esa variable ni siquiera tira error,
simplemente nunca es `true`.

### Caso real (18/08/2026): "Aumento de precio" masivo en Productos no hacía nada al tocar "Aplicar"

Reporte de Rodrigo: un cliente cargó una lista con un -38% de descuento del
proveedor y quiso aplicarlo en masa desde Productos → seleccionar ítems →
"Aumento de precio" → -38% → Aplicar. El botón no hacía absolutamente nada,
sin ningún error visible.

Causa: `aplicarBulk()` (`productos/+page.svelte`) arranca con
`const val = bulkVal.trim();`, incondicional para los 5 tipos de cambio
masivo que comparten ese mismo modal. `bulkVal` está declarado
`$state('')` (string), y para proveedor/marca/categoría el input es
`type="text"` — ahí `bind:value` sí mantiene un string, `.trim()` anda
bien. Pero para "Aumento de precio" el input es `type="number"`
(`<input type="number" bind:value={bulkVal}>`), y Svelte 5 coacciona
`bind:value` a un **number** en runtime para ese tipo de input, sin que
TypeScript lo marque (el tipo declarado sigue siendo `string`, el
mismatch es puramente de runtime). Al tipear "-38", `bulkVal` pasaba a
ser el number `-38`, y `(-38).trim` no existe — `TypeError` sin capturar,
que cortaba `aplicarBulk()` ahí mismo, ANTES de llegar al `fetch` — cero
requests de red, cero toast de error, el botón "no hacía nada" tal cual lo
describió Rodrigo. Reproducido en vivo con el navegador real: la consola
mostraba `$.get(...).trim is not a function` (nombre compilado de
`bulkVal` en el bundle de producción) y `read_network_requests` confirmaba
cero llamadas a `/productos/bulk`.

**Bug real, no específico de negativos:** cualquier porcentaje —positivo o
negativo— rompía igual, ya que el `TypeError` ocurre antes de que el signo
importe. Coincidencia que el caso reportado fuera justo un descuento.

**Fix:** `const val = String(bulkVal).trim();` — coacciona a string
explícitamente antes de operar, sin importar si Svelte lo dejó como number
o string. Verificado en vivo: -38% sobre 3 productos seleccionados
(`$20.328,00 → $12.603,36`, `$5.000,00 → $3.100,00`, exactos), confirmado
por request real a `/productos/bulk` (200 OK, `afectados: 3`) y lectura
directa de `productos.precio_venta` en la base.

**Convención a partir de ahora:** cualquier variable `$state('')` que se
liga con `bind:value` a un `<input type="number">` en algún punto del
código NO es confiablemente un string en runtime pese a su tipo declarado
— cualquier método de string (`.trim()`, `.toLowerCase()`, etc.) sobre esa
variable tiene que coaccionar con `String(...)` primero, incluso si el
tipo de TypeScript no se queja.

## Feature (19/08/2026): Cuenta Corriente — "Crear deuda" y "Registrar pago" con factura

Pedido de Rodrigo: poder arrancar una cuenta corriente con saldo a favor o
adeudando (ej. migrar un cliente que ya tenía deuda en el sistema anterior),
y que un cobro por medio bancario pueda facturarse ahí mismo con CAE real.

- **"Crear deuda"** (botón nuevo en Cuenta Corriente): modal liviano
  (fecha, descripción, monto) que llama al endpoint `POST /cc` ya existente
  con `tipo: 'cargo'` — ese endpoint genérico de cargo/pago ya soportaba
  esto de antes, solo faltaba la pantalla. Nunca genera factura ni toca
  caja: es asentar que la cuenta ya debe ese monto, no un cobro real.
- **"Registrar pago"** (ya existía) se extendió: se agregaron tarjeta y
  Mercado Pago como medio de pago (antes solo efectivo/transferencia/
  cheque), y la posibilidad de generar una factura con CAE en el mismo
  paso — **obligatoria** si el medio es bancario (transferencia, tarjeta,
  Mercado Pago o cheque), **opcional** con un checkbox si es efectivo. La
  letra (A/B/C) la elige el usuario a mano, igual que al confirmar una
  venta. La factura queda con un único ítem fijo "Saldo a Cuenta
  Corriente" — se creó un producto de servicio permanente (`codigo =
  'SALDO-CC'`, auto-creado la primera vez que hace falta) porque
  `venta_items.producto_id` es `NOT NULL`, no hay forma de facturar sin un
  producto real detrás; este producto nunca ve movimiento de stock porque
  la venta se inserta directo (no pasa por `VentasController::crear()`).
- **Impacto en caja:** antes solo efectivo/transferencia tocaban
  `caja_movimientos` al cobrar CC; ahora tarjeta/Mercado Pago/cheque
  también (migración 78, agrega `'cheque'` al ENUM de
  `caja_movimientos.medio_pago` — tarjeta y mercado_pago ya estaban ahí
  por otros flujos de caja).
- **CAE reutilizado, no duplicado:** `VentasController::solicitarCae()`
  pasó de `private` a `public` para que `CuentaCorrienteController` lo
  llame directo sobre la venta recién creada — mismo mecanismo de
  reintento asincrónico que el resto de comprobantes electrónicos
  (`POST /ventas/{id}/facturar` si ARCA falla en el momento, no bloquea
  el registro del pago en sí).

Verificado en vivo con el navegador real: "Crear deuda" (saldo sube
exacto), "Registrar pago" en efectivo sin factura (solo mueve CC y caja),
y "Registrar pago" en transferencia (factura obligatoria, `FC B-ELECT`
creada con el ítem correcto, movimiento de caja de `ingreso`, `afip_error`
poblado por falta de certificado en este entorno — comportamiento
esperado) — la factura resultante aparece en el listado de `/ventas`
(mismo endpoint que alimenta Ventas), tal como se pidió.

## Feature (19/08/2026): Crear proveedor/marca/rubro desde el cambio masivo en Productos

Pedido de Rodrigo: al cambiar marca/rubro/proveedor en masa desde
Productos (seleccionar productos → "Cambiar marca/rubro/proveedor"), poder
crear el valor nuevo ahí mismo si no existe, sin salir a otra pantalla —
y que quede preseleccionado listo para aplicar.

- **Marca/Rubro:** reutiliza el mismo quick-add liviano (solo nombre) que
  ya existía en el modal de crear/editar un producto individual — mismo
  patrón `taxQuickOpen`/`taxQuickSave`, con dos variantes nuevas
  (`bulk-marca`/`bulk-categoria`) que asignan el resultado a `bulkVal` en
  vez de a `editForm`/`nuevoForm`.
- **Proveedor:** no tenía ningún quick-add — a diferencia de marca/rubro,
  un proveedor es una entidad real con más campos (CUIT, condición IVA,
  teléfono, etc.), así que en vez de reinventar un formulario reducido,
  llama a `abrirContacto(null, 'proveedores', { onGuardado })` — el modal
  compartido de Contactos (`$lib/ContactModal.svelte`, ya montado
  globalmente en el layout), el mismo que usa la pantalla de Contactos
  para dar de alta un proveedor real. `onGuardado` deja el nombre creado
  preseleccionado en `bulkVal`.

**Bug real encontrado y corregido durante la verificación en vivo, no
relacionado con este cambio:** aplicar el cambio masivo de marca/rubro/
proveedor (con CUALQUIER valor, nuevo o ya existente) tiraba 500
`SQLSTATE[23000]: ... Column 'id' in WHERE is ambiguous`. Causa:
`ProductosController::bulk()` llama a
`ReglasPrecioHelper::recalcularPorFiltro($db, "id IN (...)", ...)` con un
`id` sin calificar, pero esa consulta hace `UPDATE productos p LEFT JOIN
... proveedores pv ...` — como `productos` y `proveedores` tienen columna
`id` los dos, MySQL no puede resolver la referencia. El resto de
`ReglasPrecioHelper` (`recalcularPrecio`, `recalcularPorRegla`,
`recalcularPorGrupo`) ya calificaba correctamente (`p.id`, `r.id`); solo
esta llamada puntual no. Como el `UPDATE` del campo en sí corre ANTES de
esta llamada y sin transacción envolvente, el cambio de marca/rubro/
proveedor **sí se guardaba** pese al 500 — el usuario veía un error pero
el dato había cambiado igual (mismo patrón confuso que el bug de escalas
del 18/08/2026). Nunca se había notado porque, hasta este pedido, cambiar
marca/rubro/proveedor en masa nunca se había probado en vivo de punta a
punta. **Fix:** `"p.id IN (...)"` en vez de `"id IN (...)"`. Verificado en
vivo con marca y proveedor, ambos con valores recién creados vía los
modales nuevos.

## Feature (19/08/2026): checkbox para desactivar el auto-logout por inactividad

El auto-logout de 15 minutos (agregado 15/08/2026, ver "Endurecimiento de
localStorage" más arriba) quedó fijo, sin forma de apagarlo. Pedido de
Rodrigo: hay clientes a los que no les conviene — se agregó un checkbox en
Configuración > Configuración avanzada > "Sesión".

- **Migración 79**: `configuracion.auto_logout_inactividad TINYINT(1) NOT
  NULL DEFAULT 1` — default activado, para no cambiar el comportamiento de
  ninguna instalación que ya lo tiene funcionando.
- **Backend**: `ConfiguracionController::actualizar()` lo persiste igual
  que `backup_auto_cierre`/`ventas_sin_stock`, con una diferencia a
  propósito: si el body no manda el campo, el default en PHP es `1` (no
  `0` como los otros dos) — para que un guardado desde un frontend viejo
  nunca apague en silencio una protección de seguridad que el cliente ya
  tenía activada. En la práctica no debería pasar nunca (frontend y
  backend siempre se publican juntos, ver "npm run publish no reconstruye
  la SPA" más arriba), pero es la opción más segura ante ese campo
  faltante.
- **Frontend**: `+layout.svelte::cargarRazonSocial()` (que ya pegaba a
  `GET /configuracion` para el título de la ventana) se extendió para leer
  también este campo, en vez de agregar un fetch nuevo — arranca en `true`
  por default hasta que resuelve, para que una PC recién abierta sin
  conexión momentánea al backend no quede con el auto-logout apagado por
  accidente.

Verificado en vivo con el navegador real: tildar/destildar el checkbox +
Guardar cambios en Configuración persiste en la base, y `GET
/configuracion` (la misma llamada que ya hacía `+layout.svelte`) refleja
el valor nuevo al recargar cualquier pantalla.

### Caso real (20/08/2026): factura con CAE llegaba al POS para "editarla" por dos caminos distintos — uno sin guardia, otro por una URL muerta

Reporte de Rodrigo: el sistema correctamente no deja editar una factura con
CAE, pero igual la dejaba llegar al POS con los datos cargados — al intentar
guardar, el backend la rechazaba bien, pero después quedaba trabado sin
poder salir de esa pantalla limpiamente. Pedido explícito: mover el bloqueo
a Ventas, que nunca llegue al POS.

Investigando aparecieron **dos bugs distintos**, no uno:

**1. `operaciones/+page.svelte::accionEditar()` no tenía ningún chequeo de
CAE.** Esta pantalla (reporte de operaciones del día, con su propio botón
"Editar" por fila) es un archivo aparte de `ventas/+page.svelte` — el
guardado de CAE que se agregó el 18/08/2026 (`accionModificarRapido()`/
`accionEditarItems()`) solo cubrió Ventas, nunca se replicó acá. Cualquier
factura con CAE se cargaba a `logos_editar_venta` y mandaba al POS sin
ninguna validación. **Fix:** mismo guard (`if (v.cae) { toast_(...); return;
}`) más `disabled`/`title` en el botón, calcado del patrón ya usado en
Ventas.

**2. `ventas/+page.svelte::accionCopiar()` ("Copiar al POS") navegaba a
`/Logos/pos/` — la página LEGACY vieja, no la SPA real.** Confirmado con
`curl` directo contra Apache (no el proxy de Vite): esa URL devuelve 200 y
sirve `pos/index.html`, el HTML pre-SvelteKit con su propio `config.js` y
su propio manejo de sesión — un programa completamente distinto y
desconectado, dejado de lado en la migración (ver "`electron/` desapareció
del repo" y la memoria `pos/*.html legacy vs SPA Svelte` de este mismo
archivo) pero nunca corregido en este único lugar. La función SÍ escribía
bien `logos_copia_venta` en localStorage, y la pantalla POS real
(`+page.svelte::initCopiaVenta()`, ya andando) SÍ sabía leerlo — el único
error era el destino de la navegación.

**Esto explica el reporte completo:** "Copiar al POS" aterrizaba en la
página legacy (que a simple vista también parece una pantalla de POS,
fácil de confundir), y algo en esa página vieja — sesión o estado
distinto, nunca se investigó el detalle porque no hace falta — dejaba algo
inconsistente al volver a la SPA. Reproducido y confirmado en vivo: ANTES
del fix, después de "Copiar al POS" y volver a Ventas, reseleccionar la
misma factura mostraba "Editar" habilitado (bug); DESPUÉS del fix
(navegando a `/` en vez de `/Logos/pos/`), "Editar" se mantiene
deshabilitado siempre, sin importar cuántas veces se vaya y vuelva.

**Fix:** `window.location.href = '/'` en vez de `'/Logos/pos/'` — mismo
destino que ya usa correctamente `accionEditarItems()` al lado. Se
revisaron todas las demás apariciones de `/Logos/pos/` en `app/src`: las
únicas dos restantes apuntan a `instalar.html` (excepción legítima
documentada) y una hoja de estilos compartida (`pos-base.css`, un asset,
no una navegación) — ninguna otra necesitaba corrección.

**Convención a partir de ahora:** cualquier guardia nueva contra editar/
eliminar una factura con CAE tiene que agregarse en **todos** los lugares
que puedan llevar esa venta al POS o a un formulario de edición — hoy son
`ventas/+page.svelte` y `operaciones/+page.svelte` — no alcanza con
agregarla en uno solo. Y cualquier `window.location.href`/`location.href`
que apunte a la app tiene que ir a `/` (SPA real), nunca a `/Logos/pos/*`
salvo el caso explícito de `instalar.html`.

### Caso real (20/08/2026): un código de producto real ("004") daba "no encontrado" al escanearlo en el POS

Reporte de Rodrigo: un cliente creó un producto con código "004"; en
Productos aparece bien, pero escribirlo en el campo "Código / scan" del
POS tira `Código "004" no encontrado`.

Causa: `fetchCodigo()` (POS, búsqueda por código exacto) llama a
`GET /productos?q=004&limit=10...` **sin** `exacto=1`. Sin ese parámetro,
`ProductosController::search()` usa el modo "similar" (`ORDER BY nombre`,
sin ninguna prioridad para el que matchea exacto) en vez del modo exacto
(`ORDER BY CASE WHEN codigo = ? THEN 0 ...`, que sí prioriza el match
exacto a la posición 0). El frontend después busca el match exacto **solo
entre los primeros 10 resultados** que trajo el `LIMIT 10` — si hay 10 o
más productos cuyo nombre o código contienen "004" como substring en
cualquier parte (ej. cualquier código termina en "004", o el nombre
menciona "004" en una medida) y ordenan antes por nombre, el producto real
queda afuera de esos 10 aunque exista.

Reproducido en vivo con datos reales (no una suposición): con la base de
20.001 productos de prueba de este entorno hay **1140 productos** que
contienen "004" en nombre o código — de entrada el producto real
aparecía primero por una coincidencia de collation (los nombres de prueba
`__SEED__...` ordenan después de cualquier letra en `utf8mb4_general_ci`),
así que hubo que crear 11 productos de prueba con nombres que sí ordenan
antes (`AAAA01`...`AAAA11`) para reproducir la falla real — confirmado:
sin `exacto=1` el producto real cae fuera de los primeros 10 y el POS dice
"no encontrado"; con `exacto=1` vuelve a la posición 0, siempre.

**Fix:** agregar `&exacto=1` a la llamada de `fetchCodigo()`. Es un
parámetro que `ProductosController::search()` ya soportaba de antes (existe
en el backend desde hace tiempo, con su propio `ORDER BY` que prioriza el
match exacto), pero **ningún lugar del frontend lo estaba usando** — no
hizo falta tocar el backend en absoluto, solo faltaba que el POS lo
pidiera. Se revisó que no hubiera otro lugar con el mismo patrón
(`.codigo.toLowerCase() === ...` sobre un resultado de búsqueda): el único
otro uso de `/productos?q=` en el POS es `fetchRapido()` (F2, dropdown de
búsqueda por nombre) — ese no necesita `exacto=1` porque el usuario elige
a mano entre los resultados, no depende de que el match correcto esté en
una posición específica.

**Convención a partir de ahora:** cualquier búsqueda que dependa de
encontrar un match EXACTO dentro de un `LIMIT` (código de barras, CUIT,
etc.) tiene que pedir el modo que prioriza esa exactitud en el `ORDER BY`
(`exacto=1` en este endpoint) — nunca confiar en que el orden por defecto
("similar"/alfabético) vaya a traer ese resultado dentro de la ventana del
`LIMIT`, sobre todo con catálogos grandes donde un substring corto como
"004" puede matchear cientos de productos sin relación.
