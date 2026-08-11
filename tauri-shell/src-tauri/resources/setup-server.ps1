<#
.SYNOPSIS
    Inicializa MariaDB y registra los servicios Windows de Logos POS via NSSM.
    Es ejecutado por el instalador NSIS con privilegios de Administrador.

    Layout de paths distinto al de Electron: el bundle de Tauri no tiene una
    subcarpeta "resources\" (los recursos quedan planos bajo $InstallDir, ver
    tauri.conf.json -> bundle.resources), asi que las rutas de mariadb/php/www
    NO llevan el prefijo "resources\" que si usa el script de Electron.

.PARAMETER InstallDir
    Directorio de instalacion (p.ej. C:\Program Files\Logos POS)
.PARAMETER DbPort
    Puerto MariaDB. 0 = auto-descubrir (default). Se prueban 3306-3308 y como
    ultimo recurso se solicita un puerto libre al SO (nunca falla por
    conflicto). WebPort (PHP) siempre se auto-descubre: 8080-8082 o puerto
    libre del SO.
#>
param(
    [Parameter(Mandatory=$true)]
    [string]$InstallDir,
    [int]$DbPort      = 0
)
$ErrorActionPreference = 'Stop'

$svcDb       = 'LogosPOS-DB'
$svcPhp      = 'LogosPOS-PHP'
$fwRuleName  = 'LogosPOS-Web'
$dataRoot    = 'C:\ProgramData\LogosPOS'
$dataDir     = "$dataRoot\mysql-data"
$logsDir     = "$dataRoot\logs"
$mariadbBin  = "$InstallDir\mariadb\bin"
$phpBin      = "$InstallDir\php"
$wwwDir      = "$InstallDir\www"
$appDir      = "$InstallDir\www\Logos"
$appBuildDir = "$InstallDir\www\app"
$nssmExe     = "$InstallDir\nssm.exe"
$schemaPath  = "$appDir\install\schema_limpio.sql"
# Fuera del arbol que reempaqueta el instalador, mismo criterio que en produccion.
$dbLocalPath = "$dataRoot\db.local.php"

$setupLog = "$dataRoot\setup-log.txt"
New-Item -ItemType Directory -Force -Path $dataRoot | Out-Null
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
# Critico: si el servicio DB sigue corriendo ocupa el puerto y bloquea el
# datadir. Usamos sc.exe para stop/delete (no NSSM) porque es mas confiable
# y no escribe a stderr de formas que conflictuen con $ErrorActionPreference=Stop.
foreach ($svc in @($svcPhp, $svcDb)) {
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
    # falte la carpeta "mysql". Ver CLAUDE.md (mismo fix, mismo motivo: restos
    # sueltos de un desinstalador previo).
    Get-ChildItem -Path $dataDir -Force -ErrorAction SilentlyContinue |
        Remove-Item -Recurse -Force -ErrorAction SilentlyContinue

    $installDbExe = "$mariadbBin\mysql_install_db.exe"
    if (Test-Path $installDbExe) {
        $initOut = & $installDbExe "--datadir=$dataDir" '--password=' 2>&1
        $initOut | ForEach-Object { Log "$_" }
    } else {
        $initOut = & "$mariadbBin\mysqld.exe" '--no-defaults' "--basedir=$mariadbBase" "--datadir=$dataDir" '--initialize-insecure' 2>&1
        $initOut | ForEach-Object { Log "$_" }
    }
    if ($LASTEXITCODE -ne 0) { throw "Inicializacion de MariaDB fallo (exit $LASTEXITCODE)" }
    Log "Datos inicializados."
} else {
    Log "Datos existentes detectados - se omite inicializacion."
}

# --- 4. Iniciar mysqld temporal para importar schema -------------------------
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
    try { $null = & "$mariadbBin\mysqladmin.exe" '--no-defaults' '-h' '127.0.0.1' "-P$DbPort" '-u' 'root' '--connect-timeout=2' 'ping' 2>$null } catch { }
    if ($LASTEXITCODE -eq 0) { $ready = $true; break }
}
if (!$ready) {
    try { $mysqld.Kill() } catch {}
    $proc = Get-Process -Id $mysqld.Id -ErrorAction SilentlyContinue
    $exitMsg = if ($proc) { "mysqld sigue corriendo pero no responde a pings." } else { "mysqld termino inesperadamente (exit: $($mysqld.ExitCode))." }
    $portConflict = (netstat -ano 2>$null) -join "`n" | Select-String ":$DbPort\s"
    $portMsg = if ($portConflict) { "Puerto $DbPort ocupado por otro proceso: $portConflict" } else { "Puerto $DbPort libre (mysqld no logro bindear)." }
    $mysqldLog = if (Test-Path $mysqldTempLog) { (Get-Content $mysqldTempLog -Tail 10) -join "`n" } else { "(sin log)" }
    throw "MariaDB no respondio en 90 segundos.`n$exitMsg`n$portMsg`nLog mysqld:`n$mysqldLog"
}
Log "MariaDB temporal listo."

# --- 5. Importar schema (idempotente — independiente del check del datadir) ---
try {
    $null = & "$mariadbBin\mysql.exe" '--no-defaults' '-h' '127.0.0.1' "-P$DbPort" '-u' 'root' `
        '-e' 'CREATE DATABASE IF NOT EXISTS logos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;' 2>$null
    if ($LASTEXITCODE -ne 0) { throw "mysql.exe salio con codigo $LASTEXITCODE" }
    Log "Base de datos logos verificada/creada."
} catch {
    throw "No se pudo crear la base de datos logos: $_"
}

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

# --- 7. Registrar servicio DB -------------------------------------------------
Log "Registrando servicio $svcDb..."
RunExe $nssmExe @('install', $svcDb, "$mariadbBin\mysqld.exe")
Start-Sleep -Milliseconds 500  # dar tiempo a NSSM para que cree las claves de registro
$dbRegPath = "HKLM:\SYSTEM\CurrentControlSet\Services\$svcDb\Parameters"
if (!(Test-Path $dbRegPath)) { New-Item -Path $dbRegPath -Force | Out-Null }
try {
    Set-ItemProperty -Path $dbRegPath -Name 'AppParameters' -Value "--no-defaults --basedir=`"$mariadbBase`" --datadir=`"$dataDir`" --port=$DbPort --bind-address=127.0.0.1 --skip-networking=OFF" -Type String -ErrorAction Stop
    Log "AppParameters $svcDb escritos."
} catch {
    Log "AVISO: No se pudo escribir AppParameters DB via registry: $_"
}
RunExe $nssmExe @('set', $svcDb, 'AppDirectory',    $mariadbBin)
RunExe $nssmExe @('set', $svcDb, 'DisplayName',     'Logos POS - Base de datos')
RunExe $nssmExe @('set', $svcDb, 'Start',           'SERVICE_AUTO_START')
RunExe $nssmExe @('set', $svcDb, 'AppRestartDelay', '5000')
RunExe $nssmExe @('set', $svcDb, 'AppNoConsole',    '1')
RunExe $nssmExe @('set', $svcDb, 'AppStdout',       "$logsDir\mariadb.log")
RunExe $nssmExe @('set', $svcDb, 'AppStderr',       "$logsDir\mariadb-err.log")
RunExe $nssmExe @('set', $svcDb, 'AppRotateFiles',  '1')
RunExe $nssmExe @('set', $svcDb, 'AppRotateBytes',  '10485760')
RunExe $nssmExe @('set', $svcDb, 'ObjectName',      'LocalSystem', '')
sc.exe config $svcDb start= auto 2>$null | Out-Null

# --- 8. Registrar servicio PHP ------------------------------------------------
Log "Registrando servicio $svcPhp..."
RunExe $nssmExe @('install', $svcPhp, "$phpBin\php.exe")
Start-Sleep -Milliseconds 500
$phpParams = "-c `"$phpBin\php.ini`" -d `"error_log=$logsDir\php-error.log`" -S 0.0.0.0:$WebPort -t `"$wwwDir`" `"$wwwDir\router.php`""
$phpRegPath = "HKLM:\SYSTEM\CurrentControlSet\Services\$svcPhp\Parameters"
if (!(Test-Path $phpRegPath)) { New-Item -Path $phpRegPath -Force | Out-Null }
try {
    Set-ItemProperty -Path $phpRegPath -Name 'AppParameters' -Value $phpParams -Type String -ErrorAction Stop
    Log "AppParameters $svcPhp escritos."
} catch {
    Log "AVISO: No se pudo escribir AppParameters PHP via registry: $_"
}
RunExe $nssmExe @('set', $svcPhp, 'AppDirectory',    $phpBin)
RunExe $nssmExe @('set', $svcPhp, 'DisplayName',     'Logos POS - Servidor web')
RunExe $nssmExe @('set', $svcPhp, 'Start',           'SERVICE_AUTO_START')
RunExe $nssmExe @('set', $svcPhp, 'AppRestartDelay', '5000')
RunExe $nssmExe @('set', $svcPhp, 'AppNoConsole',    '1')
RunExe $nssmExe @('set', $svcPhp, 'DependOnService', $svcDb)
RunExe $nssmExe @('set', $svcPhp, 'AppStdout',       "$logsDir\php.log")
RunExe $nssmExe @('set', $svcPhp, 'AppStderr',       "$logsDir\php-err.log")
RunExe $nssmExe @('set', $svcPhp, 'AppRotateFiles',  '1')
RunExe $nssmExe @('set', $svcPhp, 'AppRotateBytes',  '10485760')
RunExe $nssmExe @('set', $svcPhp, 'ObjectName',      'LocalSystem', '')
RunExe $nssmExe @('set', $svcPhp, 'AppEnvironmentExtra',
    "PATH=$phpBin;$mariadbBin;$env:SystemRoot\System32",
    "LOGOS_APP_DIR=$appDir",
    "LOGOS_APP_BUILD_DIR=$appBuildDir",
    "LOGOS_DATA_ROOT=$dataRoot",
    "LOGOS_DB_HOST=127.0.0.1",
    "LOGOS_DB_PORT=$DbPort",
    "LOGOS_DB_NAME=logos",
    "LOGOS_DB_USER=root",
    "LOGOS_DB_PASS=",
    "PHPRC=$phpBin")
sc.exe config $svcPhp start= auto 2>$null | Out-Null

# --- 9. Regla de firewall de entrada ------------------------------------------
Log "Configurando regla de firewall para puerto $WebPort..."
Remove-NetFirewallRule -DisplayName $fwRuleName -ErrorAction SilentlyContinue
try {
    New-NetFirewallRule -DisplayName $fwRuleName `
        -Direction Inbound -Protocol TCP -LocalPort $WebPort -Action Allow `
        -Description "Acceso LAN al servidor web de Logos POS (puerto $WebPort)" | Out-Null
    Log "Regla '$fwRuleName' creada para puerto TCP $WebPort."
} catch {
    Log "AVISO: No se pudo crear regla de firewall (no fatal): $_"
}

# --- 10. Iniciar servicios ----------------------------------------------------
Log "Iniciando $svcDb..."
RunExe $nssmExe @('start', $svcDb)
Start-Sleep -Seconds 3

$ready = $false
for ($i = 0; $i -lt 60; $i++) {
    Start-Sleep -Seconds 1
    try { $null = & "$mariadbBin\mysqladmin.exe" '--no-defaults' '-h' '127.0.0.1' "-P$DbPort" '-u' 'root' '--connect-timeout=2' 'ping' 2>$null } catch { }
    if ($LASTEXITCODE -eq 0) { $ready = $true; break }
}
if (!$ready) {
    $svcStatus = (sc.exe query $svcDb 2>$null) -join ' '
    Log "Estado $svcDb : $svcStatus"
    if (Test-Path "$logsDir\mariadb-err.log") {
        $errLines = (Get-Content "$logsDir\mariadb-err.log" -Tail 20) -join "`n"
        Log "Log error MariaDB:`n$errLines"
    }
    throw "$svcDb no respondio en 60s tras iniciar el servicio NSSM. Ver $dataRoot\setup-log.txt"
}
Log "$svcDb activo."

Log "Iniciando $svcPhp..."
RunExe $nssmExe @('start', $svcPhp)
Start-Sleep -Seconds 3

Log ""
Log "Configuracion completada exitosamente."
Log "  Base de datos: 127.0.0.1:$DbPort (solo loopback)"
Log "  Servidor web:  0.0.0.0:$WebPort  (accesible en LAN)"
Log "  Datos:         $dataRoot"
Log ""

# --- 11. Escribir logos-config.json --------------------------------------------
$configPath = "$dataRoot\logos-config.json"
$configJson = "{`"role`":`"server`",`"serverPort`":$WebPort,`"dbPort`":$DbPort,`"serverIp`":null}"
[System.IO.File]::WriteAllText($configPath, $configJson, [System.Text.UTF8Encoding]::new($false))
Log "logos-config.json escrito en $configPath (dbPort=$DbPort, webPort=$WebPort)"
