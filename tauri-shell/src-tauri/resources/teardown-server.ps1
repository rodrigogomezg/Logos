<#
.SYNOPSIS
    Detiene y remueve los servicios Windows de Logos POS. Ejecutado por el
    desinstalador NSIS con privilegios de Administrador.
    Los datos en C:\ProgramData\LogosPOS\ NO se eliminan salvo borrado completo
    explícito (ver installer-hooks.nsh, NSIS_HOOK_PREUNINSTALL).

.PARAMETER InstallDir
    Directorio de instalacion (para localizar nssm.exe — sin el prefijo
    "resources\" que usa Electron, el bundle de Tauri lo deja plano bajo $InstallDir)
#>
param(
    [Parameter(Mandatory=$true)]
    [string]$InstallDir
)
$ErrorActionPreference = 'SilentlyContinue'

$svcDb      = 'LogosPOS-DB'
$svcPhp     = 'LogosPOS-PHP'
$fwRuleName = 'LogosPOS-Web'
$nssmExe    = "$InstallDir\nssm.exe"

function Log([string]$msg) { Write-Host "[LogosPOS] $msg" }

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

Log "Servicios de Logos POS removidos."
Log "Nota: Los datos en C:\ProgramData\LogosPOS\ se conservan."

# Eliminar regla de firewall creada por el instalador
Remove-NetFirewallRule -DisplayName $fwRuleName -ErrorAction SilentlyContinue
Log "Regla de firewall '$fwRuleName' eliminada."
