<#
.SYNOPSIS
    Espera a que LogosPOS-PHP este realmente escuchando (no solo "RUNNING" a
    nivel de servicio de Windows) despues de un reinicio silencioso por
    auto-update. Log en C:\ProgramData\LogosPOS\setup-log.txt.

.DESCRIPTION
    "sc start" confirma que NSSM arranco el servicio, pero el proceso php.exe
    viejo (recien detenido por Electron antes de instalar la actualizacion)
    puede tardar un momento en soltar el puerto TCP — el nuevo php.exe puede
    fallar el primer bind y NSSM lo reintenta despues de AppRestartDelay
    (5s). Relanzar la app inmediatamente despues de "sc start" puede pegarle
    a un servidor que todavia no responde. Este script confirma el ping real
    antes de que installer.nsh relance la app.
#>
param(
    [int]$TimeoutSeg = 30
)
$ErrorActionPreference = 'Stop'

$setupLog = 'C:\ProgramData\LogosPOS\setup-log.txt'
function Log([string]$msg) {
    $line = "$(Get-Date -Format 'HH:mm:ss') [UPDATE] $msg"
    Write-Host $line
    Add-Content -Path $setupLog -Value $line -Encoding UTF8
}

$configPath = 'C:\ProgramData\LogosPOS\logos-config.json'
$port = 8080
try {
    $cfg = Get-Content -Path $configPath -Raw | ConvertFrom-Json
    if ($cfg.serverPort) { $port = [int]$cfg.serverPort }
} catch {
    Log "No se pudo leer serverPort de $configPath, uso el default $port. Error: $_"
}

Log "Esperando a que el servidor web responda en el puerto $port (timeout ${TimeoutSeg}s)..."

$deadline = (Get-Date).AddSeconds($TimeoutSeg)
$ok = $false
$intentos = 0
while ((Get-Date) -lt $deadline) {
    $intentos++
    try {
        $resp = Invoke-WebRequest -Uri "http://127.0.0.1:$port/Logos/ping" -UseBasicParsing -TimeoutSec 3
        if ($resp.StatusCode -eq 200) { $ok = $true; break }
    } catch {
        # Todavia no responde — normal en los primeros segundos, seguir intentando.
    }
    Start-Sleep -Milliseconds 700
}

if ($ok) {
    Log "Servidor web respondio OK tras $intentos intento(s)."
    exit 0
} else {
    Log "AVISO: el servidor web no respondio en ${TimeoutSeg}s tras $intentos intento(s). Se va a relanzar la app igual — puede tardar unos segundos mas en quedar lista, o mostrar un error de conexion si el servicio realmente no arranco."
    exit 1
}
