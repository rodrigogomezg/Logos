<#
.SYNOPSIS
    Inicializa MariaDB y registra los servicios Windows de Logos POS via NSSM.
    Es ejecutado por el instalador NSIS con privilegios de Administrador.

.PARAMETER InstallDir
    Directorio de instalacion de Logos POS (p.ej. C:\Program Files\Logos POS)
.PARAMETER DbPort
    Puerto MariaDB (default 3306)
.PARAMETER WebPort
    Puerto servidor PHP (default 8080)
#>
param(
    [Parameter(Mandatory=$true)]
    [string]$InstallDir,
    [int]$DbPort  = 3306,
    [int]$WebPort = 8080
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
$dbLocalPath = "$appDir\api\config\db.local.php"

function Log([string]$msg) { Write-Host "[LogosPOS] $msg" }

# --- 1. Directorios ----------------------------------------------------------
Log "Creando directorios de datos en $dataRoot ..."
New-Item -ItemType Directory -Force -Path $dataDir, $logsDir | Out-Null

# --- 2. Inicializar MariaDB (solo si es primera instalacion) ------------------
if (!(Test-Path "$dataDir\mysql")) {
    Log "Inicializando base de datos..."
    $installDbExe = "$mariadbBin\mysql_install_db.exe"
    if (Test-Path $installDbExe) {
        & $installDbExe "--datadir=$dataDir" '--password=' 2>&1 | ForEach-Object { Log $_ }
    } else {
        & "$mariadbBin\mysqld.exe" '--no-defaults' "--datadir=$dataDir" '--initialize-insecure' 2>&1 | ForEach-Object { Log $_ }
    }
    if ($LASTEXITCODE -ne 0) { throw "Inicializacion de MariaDB fallo (exit $LASTEXITCODE)" }
    Log "Datos inicializados."
} else {
    Log "Datos existentes detectados - se omite inicializacion."
}

# --- 3. Iniciar mysqld temporal para importar schema -------------------------
Log "Iniciando mysqld temporal..."
$mysqld = Start-Process -FilePath "$mariadbBin\mysqld.exe" `
    -ArgumentList @('--no-defaults', "--datadir=$dataDir", "--port=$DbPort", '--bind-address=127.0.0.1', '--skip-networking=OFF') `
    -PassThru -WindowStyle Hidden

$ready = $false
for ($i = 0; $i -lt 30; $i++) {
    Start-Sleep -Seconds 1
    try {
        & "$mariadbBin\mysqladmin.exe" '--no-defaults' '-h' '127.0.0.1' "-P$DbPort" '-u' 'root' '--connect-timeout=2' 'ping' 2>&1 | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
    } catch {}
}
if (!$ready) {
    try { $mysqld.Kill() } catch {}
    throw "MariaDB no respondio en 30 segundos durante inicializacion"
}
Log "MariaDB temporal listo."

# --- 4. Importar schema ------------------------------------------------------
try {
    Log "Importando schema..."
    & "$mariadbBin\mysql.exe" '--no-defaults' '-h' '127.0.0.1' "-P$DbPort" '-u' 'root' `
        '-e' 'CREATE DATABASE IF NOT EXISTS logos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;' 2>&1 | Out-Null

    if (Test-Path $schemaPath) {
        $schema = [System.IO.File]::ReadAllText($schemaPath, [System.Text.Encoding]::UTF8)
        $schema | & "$mariadbBin\mysql.exe" '--no-defaults' '-h' '127.0.0.1' "-P$DbPort" '-u' 'root' 'logos' 2>&1 | Out-Null
        Log "Schema importado correctamente."
    } else {
        Log "AVISO: schema_limpio.sql no encontrado en $schemaPath"
    }
} catch {
    Log "Error al importar schema (no fatal): $_"
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
    & "$mariadbBin\mysqladmin.exe" '--no-defaults' '-h' '127.0.0.1' "-P$DbPort" '-u' 'root' '--connect-timeout=3' 'shutdown' 2>&1 | Out-Null
    $mysqld.WaitForExit(6000) | Out-Null
} catch {
    try { $mysqld.Kill() } catch {}
}
Start-Sleep -Seconds 1

# --- 7. Remover servicios existentes ------------------------------------------
foreach ($svc in @('LogosPOS-PHP', 'LogosPOS-DB')) {
    sc.exe query $svc 2>&1 | Out-Null
    if ($LASTEXITCODE -eq 0) {
        Log "Removiendo servicio previo: $svc"
        & $nssmExe stop $svc 2>&1 | Out-Null
        Start-Sleep -Seconds 2
        & $nssmExe remove $svc confirm 2>&1 | Out-Null
    }
}

# --- 8. Registrar LogosPOS-DB -------------------------------------------------
Log "Registrando servicio LogosPOS-DB..."
& $nssmExe install  LogosPOS-DB "$mariadbBin\mysqld.exe" 2>&1 | Out-Null
& $nssmExe set LogosPOS-DB AppParameters   "--no-defaults --datadir=$dataDir --port=$DbPort --bind-address=127.0.0.1 --skip-networking=OFF" 2>&1 | Out-Null
& $nssmExe set LogosPOS-DB AppDirectory    $mariadbBin 2>&1 | Out-Null
& $nssmExe set LogosPOS-DB DisplayName     'Logos POS - Base de datos' 2>&1 | Out-Null
& $nssmExe set LogosPOS-DB Start           SERVICE_AUTO_START 2>&1 | Out-Null
& $nssmExe set LogosPOS-DB AppRestartDelay 5000 2>&1 | Out-Null
& $nssmExe set LogosPOS-DB AppNoConsole    1 2>&1 | Out-Null
& $nssmExe set LogosPOS-DB AppStdout       "$logsDir\mariadb.log" 2>&1 | Out-Null
& $nssmExe set LogosPOS-DB AppStderr       "$logsDir\mariadb-err.log" 2>&1 | Out-Null
& $nssmExe set LogosPOS-DB AppRotateFiles  1 2>&1 | Out-Null
& $nssmExe set LogosPOS-DB AppRotateBytes  10485760 2>&1 | Out-Null
& $nssmExe set LogosPOS-DB ObjectName      LocalSystem '' 2>&1 | Out-Null

# --- 9. Registrar LogosPOS-PHP ------------------------------------------------
Log "Registrando servicio LogosPOS-PHP..."
& $nssmExe install  LogosPOS-PHP "$phpBin\php.exe" 2>&1 | Out-Null
# Write AppParameters directly to the registry to avoid PowerShell quoting issues
# with embedded double-quotes passed to native executables (affects paths with spaces).
$phpParams = "-c `"$phpBin\php.ini`" -d `"error_log=$logsDir\php-error.log`" -S 0.0.0.0:$WebPort -t `"$wwwDir`" `"$wwwDir\router.php`""
Set-ItemProperty -Path "HKLM:\SYSTEM\CurrentControlSet\Services\LogosPOS-PHP\Parameters" `
    -Name "AppParameters" -Value $phpParams -Type String 2>&1 | Out-Null
& $nssmExe set LogosPOS-PHP AppDirectory    $phpBin 2>&1 | Out-Null
& $nssmExe set LogosPOS-PHP DisplayName     'Logos POS - Servidor web' 2>&1 | Out-Null
& $nssmExe set LogosPOS-PHP Start           SERVICE_AUTO_START 2>&1 | Out-Null
& $nssmExe set LogosPOS-PHP AppRestartDelay 5000 2>&1 | Out-Null
& $nssmExe set LogosPOS-PHP AppNoConsole    1 2>&1 | Out-Null
& $nssmExe set LogosPOS-PHP DependOnService LogosPOS-DB 2>&1 | Out-Null
& $nssmExe set LogosPOS-PHP AppStdout       "$logsDir\php.log" 2>&1 | Out-Null
& $nssmExe set LogosPOS-PHP AppStderr       "$logsDir\php-err.log" 2>&1 | Out-Null
& $nssmExe set LogosPOS-PHP AppRotateFiles  1 2>&1 | Out-Null
& $nssmExe set LogosPOS-PHP AppRotateBytes  10485760 2>&1 | Out-Null
& $nssmExe set LogosPOS-PHP ObjectName      LocalSystem '' 2>&1 | Out-Null
& $nssmExe set LogosPOS-PHP AppEnvironmentExtra `
    "PATH=$phpBin;$mariadbBin;$env:SystemRoot\System32" `
    "LOGOS_APP_DIR=$appDir" `
    "LOGOS_DB_HOST=127.0.0.1" `
    "LOGOS_DB_PORT=$DbPort" `
    "LOGOS_DB_NAME=logos" `
    "LOGOS_DB_USER=root" `
    "LOGOS_DB_PASS=" `
    "PHPRC=$phpBin" 2>&1 | Out-Null

# --- 10. Iniciar servicios ----------------------------------------------------
Log "Iniciando LogosPOS-DB..."
& $nssmExe start LogosPOS-DB 2>&1 | Out-Null

$ready = $false
for ($i = 0; $i -lt 30; $i++) {
    Start-Sleep -Seconds 1
    & "$mariadbBin\mysqladmin.exe" '--no-defaults' '-h' '127.0.0.1' "-P$DbPort" '-u' 'root' '--connect-timeout=2' 'ping' 2>&1 | Out-Null
    if ($LASTEXITCODE -eq 0) { $ready = $true; break }
}
if (!$ready) { throw "LogosPOS-DB no respondio tras iniciar el servicio NSSM" }
Log "LogosPOS-DB activo."

Log "Iniciando LogosPOS-PHP..."
& $nssmExe start LogosPOS-PHP 2>&1 | Out-Null
Start-Sleep -Seconds 3

Log ""
Log "Configuracion completada exitosamente."
Log "  Base de datos: 127.0.0.1:$DbPort (solo loopback)"
Log "  Servidor web:  0.0.0.0:$WebPort  (accesible en LAN)"
Log "  Datos:         $dataRoot"
Log ""
