<#
.SYNOPSIS
    Inicializa MariaDB y registra los servicios Windows de Logos POS via NSSM.
    Es ejecutado por el instalador NSIS con privilegios de Administrador.

.PARAMETER InstallDir
    Directorio de instalacion de Logos POS (p.ej. C:\Program Files\Logos POS)
.PARAMETER DbPort
    Puerto MariaDB. 0 = auto-descubrir (default). Se prueban 3306-3308 y como
    ultimo recurso se solicita un puerto libre al SO (nunca falla por conflicto).
    WebPort (PHP) siempre se auto-descubre: 8080-8082 o puerto libre del SO.
#>
param(
    [Parameter(Mandatory=$true)]
    [string]$InstallDir,
    [int]$DbPort      = 0
)
$ErrorActionPreference = 'Stop'

$dataRoot    = 'C:\ProgramData\LogosPOS'
$dataDir     = "$dataRoot\mysql-data"
$logsDir     = "$dataRoot\logs"
$mariadbBin  = "$InstallDir\resources\mariadb\bin"
$phpBin      = "$InstallDir\resources\php"
$wwwDir      = "$InstallDir\resources\www"
$appDir      = "$InstallDir\resources\www\Logos"
$nssmExe     = "$InstallDir\resources\nssm.exe"
$schemaPath  = "$appDir\install\schema_limpio.sql"
# Fuera de resources/ a proposito: ese arbol lo reempaqueta el instalador NSIS
# en cada actualizacion y puede arrastrar este archivo con el. Ver CLAUDE.md.
$dbLocalPath = "$dataRoot\db.local.php"

$setupLog = 'C:\ProgramData\LogosPOS\setup-log.txt'
New-Item -ItemType Directory -Force -Path 'C:\ProgramData\LogosPOS' | Out-Null
function Log([string]$msg) {
    $line = "$(Get-Date -Format 'HH:mm:ss') [LogosPOS] $msg"
    Write-Host $line
    Add-Content -Path $setupLog -Value $line -Encoding UTF8
}

# Helper: ejecutar exe nativo descartando stdout/stderr, sin propagar errores al trap externo.
# IMPORTANTE: NO usar $Args como nombre de parametro — es una variable automatica de PS
# y @Args splattea la automatica (vacia) en lugar del parametro formal, silenciando todos los args.
function RunExe {
    param([string]$Exe, [string[]]$ArgList)
    try {
        # $null = captura stdout sin pipeline (evita el bug de $LASTEXITCODE con | Out-Null en PS5.1)
        # 2>$null descarta stderr antes del pipeline de PS (evita que ErrorActionPreference=Stop lo capture)
        $null = & $Exe @ArgList 2>$null
    } catch { <# ignorar: fire-and-forget, no chequeamos exit code en estos comandos #> }
}

# Capturar errores no manejados en el log antes de que el script termine
trap {
    $errLine = "$(Get-Date -Format 'HH:mm:ss') [ERROR] $_  -- StackTrace: $($_.ScriptStackTrace)"
    Write-Host $errLine
    Add-Content -Path $setupLog -Value $errLine -Encoding UTF8
    throw $_
}

# Prueba los candidatos en orden con un bind real en loopback (mas confiable que
# netstat porque detecta puertos ocupados por servicios que bindean 0.0.0.0).
# Si ninguno esta libre pide un puerto efimero al SO — esto practicamente nunca
# falla (requeriria que TODOS los puertos efimeros estuvieran ocupados a la vez).
function Find-FreePort {
    param(
        [int[]]$Candidatos,
        [string]$Etiqueta
    )
    foreach ($candidate in $Candidatos) {
        try {
            $listener = [System.Net.Sockets.TcpListener]::new(
                [System.Net.IPAddress]::Loopback, $candidate)
            $listener.Start()
            $listener.Stop()
            Log "[$Etiqueta] Puerto $candidate disponible. Seleccionado."
            return $candidate
        } catch {
            Log "[$Etiqueta] Puerto $candidate ocupado. Probando siguiente..."
        }
    }
    Log "[$Etiqueta] Ningun puerto preferido disponible. Solicitando puerto libre al sistema operativo..."
    $listener = [System.Net.Sockets.TcpListener]::new(
        [System.Net.IPAddress]::Loopback, 0)
    $listener.Start()
    $asignado = $listener.LocalEndpoint.Port
    $listener.Stop()
    Log "[$Etiqueta] Sistema operativo asigno el puerto $asignado."
    return $asignado
}

# --- 0. Descubrir puertos libres para MariaDB y PHP ---------------------------
if ($DbPort -eq 0) {
    $DbPort = Find-FreePort -Candidatos @(3306, 3307, 3308) -Etiqueta "MariaDB"
} else {
    Log "Puerto MariaDB especificado manualmente: $DbPort"
}
$WebPort = Find-FreePort -Candidatos @(8080, 8081, 8082) -Etiqueta "PHP"

# --- 1. Directorios ----------------------------------------------------------
Log "Creando directorios de datos en $dataRoot ..."
New-Item -ItemType Directory -Force -Path $dataDir, $logsDir | Out-Null

# --- 2. Detener y remover servicios existentes ANTES de iniciar mysqld temporal
# Critico: si LogosPOS-DB sigue corriendo ocupa el puerto y bloquea el datadir.
# Usamos sc.exe para stop/delete (no NSSM) porque es mas confiable y no escribe
# a stderr de formas que conflictuen con $ErrorActionPreference=Stop.
foreach ($svc in @('LogosPOS-PHP', 'LogosPOS-DB')) {
    $null = sc.exe query $svc 2>$null
    if ($LASTEXITCODE -eq 0) {
        Log "Deteniendo servicio previo: $svc ..."
        sc.exe stop $svc 2>$null | Out-Null
        # Esperar hasta 20s — funciona en Windows español (ESTADO) e inglés (STATE)
        for ($w = 0; $w -lt 20; $w++) {
            Start-Sleep -Seconds 1
            $queryOut = (sc.exe query $svc 2>$null) -join ' '
            if ($queryOut -match '1  STOPPED') { break }
        }
        Log "Servicio $svc detenido. Eliminando..."
        sc.exe delete $svc 2>$null | Out-Null
        Log "Servicio previo $svc eliminado."
    } else {
        Log "Servicio $svc no encontrado (instalacion limpia)."
    }
}
Start-Sleep -Seconds 2

# $mariadbBase se define aqui y se reutiliza en pasos 3, 4 y 7
$mariadbBase = Split-Path $mariadbBin

# --- 3. Inicializar MariaDB (solo si es primera instalacion) ------------------
if (!(Test-Path "$dataDir\mysql")) {
    Log "Inicializando base de datos..."

    # mysql_install_db.exe exige un datadir COMPLETAMENTE vacio, no solo que
    # falte la carpeta "mysql". Un desinstalador previo puede haber dejado
    # restos sueltos (.pid/.err de un mysqld que no llego a cerrar limpio, un
    # archivo que estaba bloqueado justo en el momento del borrado) — en vez
    # de confiar en que la desinstalacion haya dejado todo perfecto, lo
    # garantizamos aca mismo, justo antes de inicializar. Caso real 02/08/2026:
    # "Data directory ... is not empty" con la carpeta "mysql" ya ausente.
    Get-ChildItem -Path $dataDir -Force -ErrorAction SilentlyContinue |
        Remove-Item -Recurse -Force -ErrorAction SilentlyContinue

    $installDbExe = "$mariadbBin\mysql_install_db.exe"
    if (Test-Path $installDbExe) {
        # mysql_install_db.exe auto-detecta basedir desde su propio path — NO pasar --basedir.
        # Con el operador &, PowerShell maneja el quoting de rutas con espacios automaticamente
        # (sin comillas embebidas adicionales — esas solo se necesitan en Start-Process -ArgumentList).
        $initOut = & $installDbExe "--datadir=$dataDir" '--password=' 2>&1
        $initOut | ForEach-Object { Log "$_" }
    } else {
        # mysqld --initialize-insecure necesita --basedir para encontrar share/errmsg.sys.
        # El operador & quoted correctamente los args con espacios sin comillas embebidas.
        $initOut = & "$mariadbBin\mysqld.exe" '--no-defaults' "--basedir=$mariadbBase" "--datadir=$dataDir" '--initialize-insecure' 2>&1
        $initOut | ForEach-Object { Log "$_" }
    }
    if ($LASTEXITCODE -ne 0) { throw "Inicializacion de MariaDB fallo (exit $LASTEXITCODE)" }
    Log "Datos inicializados."
} else {
    Log "Datos existentes detectados - se omite inicializacion."
}

# --- 4. Iniciar mysqld temporal para importar schema -------------------------
# --basedir es critico en Windows: sin el, mysqld no encuentra share/errmsg.sys
# ni los plugins y aborta la inicializacion de red sin matar el proceso.
# WorkingDirectory = mariadbBin para que las rutas relativas internas funcionen.
$mysqldTempLog = "$logsDir\mysqld-setup.log"
Log "Iniciando mysqld temporal (basedir=$mariadbBase)..."
$mysqld = Start-Process -FilePath "$mariadbBin\mysqld.exe" `
    -ArgumentList @('--no-defaults', "--basedir=`"$mariadbBase`"", "--datadir=`"$dataDir`"", "--port=$DbPort", '--bind-address=127.0.0.1', '--skip-networking=OFF', '--console') `
    -PassThru -NoNewWindow `
    -RedirectStandardError $mysqldTempLog `
    -WorkingDirectory $mariadbBin

$ready = $false
for ($i = 0; $i -lt 90; $i++) {
    Start-Sleep -Seconds 1
    # try/catch necesario: con $ErrorActionPreference=Stop, el stderr de mysqladmin
    # (cuando el servidor aun no esta listo) se convierte en terminating error aunque
    # usemos 2>$null. Sin try/catch, el primer ping fallido mata el script.
    try { $null = & "$mariadbBin\mysqladmin.exe" '--no-defaults' '-h' '127.0.0.1' "-P$DbPort" '-u' 'root' '--connect-timeout=2' 'ping' 2>$null } catch { }
    if ($LASTEXITCODE -eq 0) { $ready = $true; break }
}
if (!$ready) {
    try { $mysqld.Kill() } catch {}
    $proc = Get-Process -Id $mysqld.Id -ErrorAction SilentlyContinue
    $exitMsg = if ($proc) { "mysqld sigue corriendo pero no responde a pings." } else { "mysqld termino inesperadamente (exit: $($mysqld.ExitCode))." }
    $portConflict = (netstat -ano 2>$null) -join "`n" | Select-String ":$DbPort\s"
    $portMsg = if ($portConflict) { "Puerto $DbPort ocupado por otro proceso: $portConflict" } else { "Puerto $DbPort libre (mysqld no logro bindear)." }
    # Incluir primeras lineas del log de mysqld en el error para diagnostico inmediato
    $mysqldLog = if (Test-Path $mysqldTempLog) { (Get-Content $mysqldTempLog -Tail 10) -join "`n" } else { "(sin log)" }
    throw "MariaDB no respondio en 90 segundos.`n$exitMsg`n$portMsg`nLog mysqld:`n$mysqldLog"
}
Log "MariaDB temporal listo."

# --- 5. Importar schema (idempotente — independiente del check del datadir) ---
# Crear la base si no existe (fatal: sin esto el sistema no puede funcionar)
try {
    $null = & "$mariadbBin\mysql.exe" '--no-defaults' '-h' '127.0.0.1' "-P$DbPort" '-u' 'root' `
        '-e' 'CREATE DATABASE IF NOT EXISTS logos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;' 2>$null
    if ($LASTEXITCODE -ne 0) { throw "mysql.exe salio con codigo $LASTEXITCODE" }
    Log "Base de datos logos verificada/creada."
} catch {
    throw "No se pudo crear la base de datos logos: $_"
}

# Importar schema solo si la tabla 'usuarios' no existe aun.
# Esto cubre el caso donde mysql-data/mysql existia de un install previo fallido
# pero logos nunca llego a tener esquema.
$tieneEsquema = $false
try {
    $check = & "$mariadbBin\mysql.exe" '--no-defaults' '-h' '127.0.0.1' "-P$DbPort" '-u' 'root' 'logos' `
        '--skip-column-names' '-e' "SHOW TABLES LIKE 'usuarios';" 2>$null
    $tieneEsquema = "$check" -match 'usuarios'
} catch {}

if (!$tieneEsquema) {
    if (Test-Path $schemaPath) {
        Log "Importando schema..."
        try {
            $schema = [System.IO.File]::ReadAllText($schemaPath, [System.Text.Encoding]::UTF8)
            $schema | & "$mariadbBin\mysql.exe" '--no-defaults' '-h' '127.0.0.1' "-P$DbPort" '-u' 'root' 'logos' 2>$null | Out-Null
            if ($LASTEXITCODE -ne 0) { throw "mysql.exe salio con codigo $LASTEXITCODE al importar el schema" }
            Log "Schema importado correctamente."
        } catch {
            throw "Error al importar schema: $_"
        }
    } else {
        Log "AVISO: schema_limpio.sql no encontrado en $schemaPath"
    }
} else {
    Log "Schema ya instalado — omitiendo importacion."
}

# --- 5. Escribir db.local.php ------------------------------------------------
$dbLocalContent = "<?php`r`n// Auto-generado por el instalador de Logos POS.`r`nreturn [`r`n    'host'   => '127.0.0.1',`r`n    'port'   => $DbPort,`r`n    'dbname' => 'logos',`r`n    'user'   => 'root',`r`n    'pass'   => '',`r`n];`r`n"
try {
    $dbLocalDir = Split-Path $dbLocalPath
    if (!(Test-Path $dbLocalDir)) { New-Item -ItemType Directory -Force $dbLocalDir | Out-Null }
    [System.IO.File]::WriteAllText($dbLocalPath, $dbLocalContent, [System.Text.UTF8Encoding]::new($false))
    Log "db.local.php escrito."
} catch {
    Log "AVISO: No se pudo escribir db.local.php: $_"
}

# --- 6. Detener mysqld temporal -----------------------------------------------
Log "Deteniendo mysqld temporal..."
try {
    RunExe "$mariadbBin\mysqladmin.exe" @('--no-defaults', '-h', '127.0.0.1', "-P$DbPort", '-u', 'root', '--connect-timeout=3', 'shutdown')
    $mysqld.WaitForExit(6000) | Out-Null
} catch {
    try { $mysqld.Kill() } catch {}
}
Start-Sleep -Seconds 1

# --- 7. Registrar LogosPOS-DB -------------------------------------------------
Log "Registrando servicio LogosPOS-DB..."
RunExe $nssmExe @('install', 'LogosPOS-DB', "$mariadbBin\mysqld.exe")
Start-Sleep -Milliseconds 500  # dar tiempo a NSSM para que cree las claves de registro
# AppParameters via Set-ItemProperty (cmdlet PS, no exe nativo): evita el bug de PS 5.1
# donde strings con espacios Y comillas embebidas no se pasan correctamente a exes nativos.
# nssm set AppParameters falla silenciosamente con este tipo de strings en PS 5.1.
$dbRegPath = "HKLM:\SYSTEM\CurrentControlSet\Services\LogosPOS-DB\Parameters"
if (!(Test-Path $dbRegPath)) { New-Item -Path $dbRegPath -Force | Out-Null }
try {
    Set-ItemProperty -Path $dbRegPath -Name 'AppParameters' -Value "--no-defaults --basedir=`"$mariadbBase`" --datadir=`"$dataDir`" --port=$DbPort --bind-address=127.0.0.1 --skip-networking=OFF" -Type String -ErrorAction Stop
    Log "AppParameters LogosPOS-DB escritos."
} catch {
    Log "AVISO: No se pudo escribir AppParameters DB via registry: $_"
}
RunExe $nssmExe @('set', 'LogosPOS-DB', 'AppDirectory',    $mariadbBin)
RunExe $nssmExe @('set', 'LogosPOS-DB', 'DisplayName',     'Logos POS - Base de datos')
RunExe $nssmExe @('set', 'LogosPOS-DB', 'Start',           'SERVICE_AUTO_START')
RunExe $nssmExe @('set', 'LogosPOS-DB', 'AppRestartDelay', '5000')
RunExe $nssmExe @('set', 'LogosPOS-DB', 'AppNoConsole',    '1')
RunExe $nssmExe @('set', 'LogosPOS-DB', 'AppStdout',       "$logsDir\mariadb.log")
RunExe $nssmExe @('set', 'LogosPOS-DB', 'AppStderr',       "$logsDir\mariadb-err.log")
RunExe $nssmExe @('set', 'LogosPOS-DB', 'AppRotateFiles',  '1')
RunExe $nssmExe @('set', 'LogosPOS-DB', 'AppRotateBytes',  '10485760')
RunExe $nssmExe @('set', 'LogosPOS-DB', 'ObjectName',      'LocalSystem', '')
sc.exe config LogosPOS-DB start= auto 2>$null | Out-Null

# --- 8. Registrar LogosPOS-PHP ------------------------------------------------
Log "Registrando servicio LogosPOS-PHP..."
RunExe $nssmExe @('install', 'LogosPOS-PHP', "$phpBin\php.exe")
Start-Sleep -Milliseconds 500
$phpParams = "-c `"$phpBin\php.ini`" -d `"error_log=$logsDir\php-error.log`" -S 0.0.0.0:$WebPort -t `"$wwwDir`" `"$wwwDir\router.php`""
$phpRegPath = "HKLM:\SYSTEM\CurrentControlSet\Services\LogosPOS-PHP\Parameters"
if (!(Test-Path $phpRegPath)) { New-Item -Path $phpRegPath -Force | Out-Null }
try {
    Set-ItemProperty -Path $phpRegPath -Name 'AppParameters' -Value $phpParams -Type String -ErrorAction Stop
    Log "AppParameters LogosPOS-PHP escritos."
} catch {
    Log "AVISO: No se pudo escribir AppParameters PHP via registry: $_"
}
RunExe $nssmExe @('set', 'LogosPOS-PHP', 'AppDirectory',    $phpBin)
RunExe $nssmExe @('set', 'LogosPOS-PHP', 'DisplayName',     'Logos POS - Servidor web')
RunExe $nssmExe @('set', 'LogosPOS-PHP', 'Start',           'SERVICE_AUTO_START')
RunExe $nssmExe @('set', 'LogosPOS-PHP', 'AppRestartDelay', '5000')
RunExe $nssmExe @('set', 'LogosPOS-PHP', 'AppNoConsole',    '1')
RunExe $nssmExe @('set', 'LogosPOS-PHP', 'DependOnService', 'LogosPOS-DB')
RunExe $nssmExe @('set', 'LogosPOS-PHP', 'AppStdout',       "$logsDir\php.log")
RunExe $nssmExe @('set', 'LogosPOS-PHP', 'AppStderr',       "$logsDir\php-err.log")
RunExe $nssmExe @('set', 'LogosPOS-PHP', 'AppRotateFiles',  '1')
RunExe $nssmExe @('set', 'LogosPOS-PHP', 'AppRotateBytes',  '10485760')
RunExe $nssmExe @('set', 'LogosPOS-PHP', 'ObjectName',      'LocalSystem', '')
RunExe $nssmExe @('set', 'LogosPOS-PHP', 'AppEnvironmentExtra',
    "PATH=$phpBin;$mariadbBin;$env:SystemRoot\System32",
    "LOGOS_APP_DIR=$appDir",
    "LOGOS_DB_HOST=127.0.0.1",
    "LOGOS_DB_PORT=$DbPort",
    "LOGOS_DB_NAME=logos",
    "LOGOS_DB_USER=root",
    "LOGOS_DB_PASS=",
    "PHPRC=$phpBin")
sc.exe config LogosPOS-PHP start= auto 2>$null | Out-Null

# --- 9. Regla de firewall de entrada ------------------------------------------
Log "Configurando regla de firewall para puerto $WebPort..."
Remove-NetFirewallRule -DisplayName 'LogosPOS-Web' -ErrorAction SilentlyContinue
try {
    New-NetFirewallRule -DisplayName 'LogosPOS-Web' `
        -Direction Inbound -Protocol TCP -LocalPort $WebPort -Action Allow `
        -Description "Acceso LAN al servidor web de Logos POS (puerto $WebPort)" | Out-Null
    Log "Regla 'LogosPOS-Web' creada para puerto TCP $WebPort."
} catch {
    Log "AVISO: No se pudo crear regla de firewall (no fatal): $_"
}

# --- 10. Iniciar servicios ----------------------------------------------------
Log "Iniciando LogosPOS-DB..."
RunExe $nssmExe @('start', 'LogosPOS-DB')
Start-Sleep -Seconds 3

$ready = $false
for ($i = 0; $i -lt 60; $i++) {
    Start-Sleep -Seconds 1
    try { $null = & "$mariadbBin\mysqladmin.exe" '--no-defaults' '-h' '127.0.0.1' "-P$DbPort" '-u' 'root' '--connect-timeout=2' 'ping' 2>$null } catch { }
    if ($LASTEXITCODE -eq 0) { $ready = $true; break }
}
if (!$ready) {
    # Loguear estado del servicio y log de error de mysqld antes de lanzar excepcion
    $svcStatus = (sc.exe query LogosPOS-DB 2>$null) -join ' '
    Log "Estado LogosPOS-DB: $svcStatus"
    if (Test-Path "$logsDir\mariadb-err.log") {
        $errLines = (Get-Content "$logsDir\mariadb-err.log" -Tail 20) -join "`n"
        Log "Log error MariaDB:`n$errLines"
    }
    throw "LogosPOS-DB no respondio en 60s tras iniciar el servicio NSSM. Ver C:\ProgramData\LogosPOS\setup-log.txt"
}
Log "LogosPOS-DB activo."

Log "Iniciando LogosPOS-PHP..."
RunExe $nssmExe @('start', 'LogosPOS-PHP')
Start-Sleep -Seconds 3

Log ""
Log "Configuracion completada exitosamente."
Log "  Base de datos: 127.0.0.1:$DbPort (solo loopback)"
Log "  Servidor web:  0.0.0.0:$WebPort  (accesible en LAN)"
Log "  Datos:         $dataRoot"
Log ""

# --- 11. Escribir logos-config.json --------------------------------------------
# Se escribe aca (y no en NSIS) porque solo el script sabe el puerto real
# descubierto en el paso 0. $dataRoot es la misma carpeta que usa Electron
# para leer su configuracion (ver DATA_ROOT en main.js) — machine-wide, no
# depende de que usuario de Windows haya corrido el instalador.
$configPath = "$dataRoot\logos-config.json"
$configJson = "{`"role`":`"server`",`"serverPort`":$WebPort,`"dbPort`":$DbPort,`"serverIp`":null}"
[System.IO.File]::WriteAllText($configPath, $configJson, [System.Text.UTF8Encoding]::new($false))
Log "logos-config.json escrito en $configPath (dbPort=$DbPort, webPort=$WebPort)"
