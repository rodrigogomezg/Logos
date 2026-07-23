<#
.SYNOPSIS
    Detiene y remueve los servicios Windows de Logos POS.
    Ejecutado por el desinstalador NSIS con privilegios de Administrador.
    Los datos en C:\ProgramData\LogosPOS\ NO se eliminan (son datos del cliente).

.PARAMETER InstallDir
    Directorio de instalacion de Logos POS (para localizar nssm.exe)
#>
param(
    [Parameter(Mandatory=$true)]
    [string]$InstallDir
)
$ErrorActionPreference = 'SilentlyContinue'

$nssmExe = "$InstallDir\resources\nssm.exe"

function Log([string]$msg) { Write-Host "[LogosPOS] $msg" }

# Detener en orden inverso: primero PHP, luego DB
foreach ($svc in @('LogosPOS-PHP', 'LogosPOS-DB')) {
    sc.exe query $svc 2>&1 | Out-Null
    if ($LASTEXITCODE -eq 0) {
        Log "Deteniendo servicio: $svc"
        if (Test-Path $nssmExe) {
            & $nssmExe stop $svc 2>&1 | Out-Null
        } else {
            sc.exe stop $svc 2>&1 | Out-Null
        }
        Start-Sleep -Seconds 3

        Log "Removiendo servicio: $svc"
        if (Test-Path $nssmExe) {
            & $nssmExe remove $svc confirm 2>&1 | Out-Null
        } else {
            sc.exe delete $svc 2>&1 | Out-Null
        }
    } else {
        Log "Servicio no encontrado (ya removido): $svc"
    }
}

Log "Servicios de Logos POS removidos."
Log "Nota: Los datos en C:\ProgramData\LogosPOS\ se conservan."
