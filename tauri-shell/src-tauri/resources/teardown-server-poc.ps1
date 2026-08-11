<#
.SYNOPSIS
    Version POC (Tauri) de teardown-server.ps1 (electron/resources/teardown-server.ps1).
    Detiene y remueve los servicios Windows del POC de Tauri. Ejecutado por el
    desinstalador NSIS con privilegios de Administrador.
    Los datos en C:\ProgramData\LogosPOS-TauriPOC\ NO se eliminan (son datos de prueba).

    Nombres TauriPOC-suffixed unicamente: nunca toca LogosPOS-DB/-PHP reales
    ni la regla de firewall real, aunque corra en una PC que tambien tenga la
    instalacion real de Electron.

.PARAMETER InstallDir
    Directorio de instalacion del POC (para localizar nssm.exe — sin el prefijo
    "resources\" que usa Electron, el bundle de Tauri lo deja plano bajo $InstallDir)
#>
param(
    [Parameter(Mandatory=$true)]
    [string]$InstallDir
)
$ErrorActionPreference = 'SilentlyContinue'

$svcDb      = 'LogosPOS-TauriPOC-DB'
$svcPhp     = 'LogosPOS-TauriPOC-PHP'
$fwRuleName = 'LogosPOS-TauriPOC-Web'
$nssmExe    = "$InstallDir\nssm.exe"

function Log([string]$msg) { Write-Host "[LogosPOS-TauriPOC] $msg" }

# Detener en orden inverso: primero PHP, luego DB
foreach ($svc in @($svcPhp, $svcDb)) {
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

Log "Servicios del POC de Tauri removidos."
Log "Nota: Los datos en C:\ProgramData\LogosPOS-TauriPOC\ se conservan."

# Eliminar regla de firewall creada por el instalador del POC
Remove-NetFirewallRule -DisplayName $fwRuleName -ErrorAction SilentlyContinue
Log "Regla de firewall '$fwRuleName' eliminada."
