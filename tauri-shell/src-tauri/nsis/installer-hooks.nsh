; installer-hooks.nsh -- Logos POS, port a Tauri v2 de electron/build/installer.nsh
; Spike Fase 1B (ver plan de migración / project_migracion_sveltekit_tauri en
; memoria) — portar tal cual la lógica existente, no rediseñarla.
;
; Diferencias mecánicas frente al original (electron-builder), no de
; comportamiento:
;   - Nombres de hook: Tauri v2 usa NSIS_HOOK_{PREINSTALL,POSTINSTALL,
;     PREUNINSTALL,POSTUNINSTALL} en vez de custom{Init,Install,UnInstall}
;     de electron-builder. postinstall es el equivalente de customInstall
;     (corre después de copiar archivos); preuninstall el de customUnInstall
;     (antes de borrar archivos).
;   - Tauri v2 no soporta `!include` de archivos .nsh locales dentro de estos
;     hooks (investigado en la fase de research del plan) — por eso acá NO
;     hay `!include StdUtils.nsh`.
;   - Reapertura no-elevada tras update silencioso: el original usa
;     `StdUtils::ExecShellAsUser` (plugin DLL de terceros). Se intentó portar
;     tal cual y falló en un build real: el NSIS que descarga Tauri para
;     bundlear (aislado del NSIS del sistema) no trae ese plugin, y
;     descargarlo/copiarlo a mano significa meter un binario de terceros sin
;     poder auditarlo — cruza una línea que no se cruza aunque el plugin sea
;     legítimo y muy usado. Se reemplazó por `schtasks`, que ya viene con
;     Windows: crea una tarea one-shot que corre como el usuario interactivo
;     actual (no como el instalador elevado), la ejecuta, y la borra. Mismo
;     resultado ("ShellExecute como usuario NO elevado desde un instalador
;     elevado"), sin depender de ningún binario externo — todo el mecanismo
;     queda en este script, auditable.
;   - customInit (chequeo de admin) no se portó: Tauri ya fuerza
;     `RequestExecutionLevel admin` en windows.wix/nsis cuando el bundle está
;     configurado igual que electron-builder (perMachine), así que la
;     redundancia del chequeo manual no es necesaria acá. Si esto avanza más
;     allá del spike, confirmar que la config de Tauri realmente fuerza UAC
;     antes de asumir que este chequeo sigue siendo innecesario.
;
; NO VERIFICADO con una actualización silenciosa real todavía — ver plan
; Fase 1B, tarea de verificación manual (PC/VM Windows real). El build con
; NSIS real SÍ compila con este hook (ver historial de esta sesión); lo que
; falta confirmar es que schtasks realmente relanza en la sesión interactiva
; correcta en un ciclo de auto-update real, no solo que el script no rompe
; la compilación.
;
; --- Página custom de selección de rol (Fase 3.1) ----------------------------
; Reemplaza el MessageBox nativo de Windows por una página propia del wizard,
; con la marca de Logos POS (vía headerImage/sidebarImage, configurados en
; tauri.conf.json — no en este archivo). Investigado antes de escribir esto
; (ver plan Fase 3.1, memoria project_migracion_sveltekit_tauri): a diferencia
; de StdUtils.nsh (plugin de terceros que el NSIS aislado de Tauri no trae),
; MUI2.nsh y nsDialogs.nsh son headers ESTÁNDAR de NSIS — Tauri ya incluye
; MUI2.nsh un renglón antes de este archivo, así que incluirlos acá funciona
; igual. Deliberadamente SIN logo embebido dentro de la página en sí (un
; control de imagen de nsDialogs necesita extraer el .bmp a $PLUGINSDIR vía
; Function .onInit, y Tauri ya declara su propio .onInit en el script
; generado — declarar uno acá también sería una función duplicada, error de
; compilación). El logo del header/sidebar ya cubre la marca en todas las
; páginas, incluida esta.
;
; Importante sobre el ORDEN: este archivo entero se pega vía !include ANTES
; de que Tauri declare sus propias páginas (MUI_PAGE_WELCOME, etc.) en el
; .nsi generado — confirmado inspeccionando target/release/nsis/x64/installer.nsi
; de un build real. Como NSIS ordena las páginas por dónde aparece cada
; sentencia Page en el script final, esta página custom queda ANTES de
; Welcome, no después. Aceptado a propósito (ver plan) en vez de pelear con
; el orden de generación de Tauri.
!include nsDialogs.nsh

Var LogosRolElegido
Var LogosRoleDialog
Var LogosRoleServidorRadio
Var LogosRoleClienteRadio
Var LogosRoleTmp

Function LogosRolePageCreate
  nsDialogs::Create 1018
  Pop $LogosRoleDialog
  ${If} $LogosRoleDialog == error
    Abort
  ${EndIf}

  ${NSD_CreateLabel} 0 0u 100% 24u "Logos POS — configuración de instalación."
  Pop $LogosRoleTmp

  ${NSD_CreateLabel} 0 30u 100% 20u "¿Esta computadora va a funcionar como SERVIDOR?"
  Pop $LogosRoleTmp

  ${NSD_CreateRadioButton} 10u 58u 100% 12u "Servidor"
  Pop $LogosRoleServidorRadio
  ${NSD_Check} $LogosRoleServidorRadio
  ${NSD_CreateLabel} 20u 72u 90% 24u "Instala base de datos + servidor web en esta PC. Otras PCs de la red se conectan a este equipo."
  Pop $LogosRoleTmp

  ${NSD_CreateRadioButton} 10u 104u 100% 12u "Cliente"
  Pop $LogosRoleClienteRadio
  ${NSD_CreateLabel} 20u 118u 90% 24u "Abre una ventana que se conecta al servidor. Se pedirá la IP del servidor al abrir la aplicación."
  Pop $LogosRoleTmp

  nsDialogs::Show
FunctionEnd

Function LogosRolePageLeave
  ${NSD_GetState} $LogosRoleClienteRadio $LogosRoleTmp
  ${If} $LogosRoleTmp <> 0
    StrCpy $LogosRolElegido "cliente"
  ${Else}
    StrCpy $LogosRolElegido "servidor"
  ${EndIf}
FunctionEnd

Page custom LogosRolePageCreate LogosRolePageLeave

!macro NSIS_HOOK_POSTINSTALL

  SetDetailsPrint both

  ; --- Visual C++ Redistributable ----------------------------------------------
  ; Caso real (ver conversación con Rodrigo, 2da PC de pruebas): el PHP
  ; portable empaquetado (build oficial de windows.php.net) no trae sus
  ; propias vcruntime140.dll/msvcp140.dll — depende de que el sistema ya
  ; tenga el redistribuible de Visual C++ instalado. En una PC realmente
  ; limpia no está, y php.exe muere al arrancar con 0xc0000005 (access
  ; violation) sin ningún mensaje propio — recién se vio a través del cartel
  ; de error agregado en server_manager.rs. /quiet /norestart: si ya está
  ; instalado (caso normal en una actualización silenciosa), esto es un
  ; no-op rápido, no reinstala ni reinicia nada.
  DetailPrint "Verificando Visual C++ Redistributable..."
  nsExec::ExecToLog '"$INSTDIR\vc_redist.x64.exe" /install /quiet /norestart'
  Pop $R0
  DetailPrint "Visual C++ Redistributable: código $R0"

  ; --- Auto-actualización (modo silencioso) -----------------------------------
  ${If} ${Silent}
    DetailPrint "Modo actualización automática — omitiendo selección de rol."

    DetailPrint "Reiniciando servicios LogosPOS si corresponde..."
    nsExec::ExecToStack 'sc start LogosPOS-DB'
    Pop $R0
    Pop $R1
    nsExec::ExecToStack 'sc start LogosPOS-PHP'
    Pop $R0
    Pop $R1
    DetailPrint "Servicios reiniciados (código DB=$R0). Reabriendo Logos POS..."

    ; Reapertura no-elevada vía schtasks (ver nota al principio del archivo)
    ; — necesario porque este instalador corre elevado (UAC) y un ExecShell
    ; plano heredaría ese contexto, sin llegar a la sesión interactiva del
    ; usuario logueado. /IT fuerza que la tarea corra en la sesión interactiva
    ; (no en una sesión de servicio oculta); /RU "%USERNAME%" apunta al mismo
    ; usuario que está corriendo este instalador (el caso normal de UAC: se
    ; eleva el mismo usuario, no se cambia de cuenta).
    DetailPrint "Programando reapertura no elevada..."
    nsExec::ExecToStack 'schtasks /create /tn "LogosPOSRelaunch" /tr "\"$INSTDIR\Logos POS.exe\"" /sc once /st 23:59 /ru "%USERNAME%" /it /f'
    Pop $R0
    Pop $R1
    nsExec::ExecToStack 'schtasks /run /tn "LogosPOSRelaunch"'
    Pop $R0
    Pop $R1
    nsExec::ExecToStack 'schtasks /delete /tn "LogosPOSRelaunch" /f'
    Pop $R0
    Pop $R1
    DetailPrint "Reapertura solicitada (código: $R0)."

    Goto logos_role_done
  ${EndIf}

  ; --- Instalación interactiva (primera vez o reinstalación manual) -----------
  DetailPrint "Preparando carpeta de datos..."
  CreateDirectory "C:\ProgramData\LogosPOS"
  nsExec::ExecToLog 'icacls "C:\ProgramData\LogosPOS" /grant *S-1-5-32-545:(OI)(CI)M /T'
  Pop $R0

  ; Rol ya elegido en la página custom (LogosRolePageCreate/Leave, ver
  ; arriba) — reemplaza el MessageBox nativo que tenía esto antes.
  ${If} $LogosRolElegido == "cliente"
    Goto logos_client_role
  ${EndIf}

  ; --- ROL SERVIDOR -----------------------------------------------------------
  DetailPrint "Configurando rol Servidor..."
  DetailPrint "Inicializando base de datos y registrando servicios de Windows..."

  DetailPrint "Buscando puertos disponibles e iniciando servicios..."
  nsExec::ExecToLog 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$INSTDIR\setup-server.ps1" -InstallDir "$INSTDIR"'
  Pop $R0

  IntCmp $R0 0 logos_setup_ok logos_setup_ok logos_setup_error
  logos_setup_error:
    DetailPrint "Error en setup-server.ps1 (código $R0)"
    MessageBox MB_ICONSTOP "Error al configurar el servidor de Logos POS.$\n$\nCódigo: $R0$\n$\nRevisá los logs en C:\ProgramData\LogosPOS\setup-log.txt$\nSoporte: drilogs.com.ar$\n$\nLa aplicación quedó instalada pero los servicios no están activos."
    Goto logos_setup_done
  logos_setup_ok:
    DetailPrint "Servicios de Windows registrados correctamente."
  logos_setup_done:

  DetailPrint "Rol Servidor configurado."
  Goto logos_role_done

  ; --- ROL CLIENTE ------------------------------------------------------------
  logos_client_role:
  DetailPrint "Configurando rol Cliente..."

  FileOpen  $R9 "C:\ProgramData\LogosPOS\logos-config.json" w
  FileWrite $R9 '{"role":"client","serverPort":8080,"dbPort":3306,"serverIp":null}'
  FileClose $R9

  DetailPrint "Rol Cliente configurado (se pedirá la IP del servidor al abrir la aplicación)."

  logos_role_done:

!macroend

!macro NSIS_HOOK_PREUNINSTALL

  IfFileExists "$INSTDIR\teardown-server.ps1" logos_do_teardown logos_no_teardown
  logos_do_teardown:
    DetailPrint "Deteniendo servicios de Logos POS..."
    nsExec::ExecToStack 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$INSTDIR\teardown-server.ps1" -InstallDir "$INSTDIR"'
    Pop $R0
    Pop $R1
    DetailPrint "Teardown completado (código: $R0)"
  logos_no_teardown:

  ${IfNot} ${Silent}
    MessageBox MB_YESNO|MB_ICONQUESTION "Se va a desinstalar Logos POS.$\n$\nQuerés borrar también la base de datos y toda la configuración (C:\ProgramData\LogosPOS)?$\n$\nSÍ = borrado completo, sin dejar rastro. Esta acción NO se puede deshacer.$\nNO = se desinstala solo el programa. Tus datos quedan intactos por si volvés a instalar Logos POS más adelante." IDYES logos_borrado_completo
    Goto logos_uninstall_done

    logos_borrado_completo:
      DetailPrint "Borrando base de datos y configuración..."
      RMDir /r "C:\ProgramData\LogosPOS"
      RMDir /r "$APPDATA\logos-pos"
      DetailPrint "Borrado completo."
  ${EndIf}

  logos_uninstall_done:

!macroend
