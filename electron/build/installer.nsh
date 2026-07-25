; installer.nsh -- Logos POS custom NSIS macros
; Incluido por electron-builder via nsis.include en electron-builder.yml.
;
; customInstall   ejecutado DESPUES de copiar archivos (dentro de Section Install)
; customUnInstall ejecutado ANTES de borrar archivos   (dentro de Section Uninstall)

; =============================================================================
; INSTALACION
; =============================================================================
!macro customInstall

  ; --- Auto-actualizacion (modo silencioso) -----------------------------------
  ; Cuando electron-updater invoca el instalador, lo hace con el flag /S (silent).
  ; En ese caso saltamos el dialogo de rol y la inicializacion de servicios —
  ; el rol ya esta configurado y los servicios los reiniciamos nosotros.
  ${If} ${Silent}
    DetailPrint "Modo actualizacion automatica — omitiendo seleccion de rol."

    ; Si los servicios NSSM existen (rol Servidor), reiniciarlos.
    ; Fueron detenidos por Electron antes de llamar a quitAndInstall().
    ; Si no existen (rol Cliente), sc start falla silenciosamente — no hay problema.
    ; sc start bloquea hasta que el servicio alcanza estado RUNNING — no hace
    ; falta Sleep adicional. La dependencia LogosPOS-PHP→LogosPOS-DB garantiza
    ; que el orden sea correcto incluso si DB tarda en estar listo.
    DetailPrint "Reiniciando servicios LogosPOS si corresponde..."
    nsExec::ExecToStack 'sc start LogosPOS-DB'
    Pop $R0
    Pop $R1
    nsExec::ExecToStack 'sc start LogosPOS-PHP'
    Pop $R0
    Pop $R1
    DetailPrint "Servicios reiniciados (codigo DB=$R0)."

    ; Reabrir inmediatamente — los servicios ya estan en RUNNING por sc start.
    ExecShell "open" "$INSTDIR\Logos POS.exe"

    Goto logos_role_done
  ${EndIf}

  ; --- Instalacion interactiva (primera vez o reinstalacion manual) -----------

  ; --- Seleccion de rol -------------------------------------------------------
  MessageBox MB_YESNO|MB_ICONQUESTION "Esta computadora va a funcionar como SERVIDOR?$\n$\nSi  = Servidor: instala base de datos + servidor web en esta PC.$\n       Otras PCs de la red se conectan a este equipo.$\n$\nNo  = Cliente: abre una ventana que se conecta al servidor.$\n       (se pedira la IP del servidor al abrir la aplicacion)" IDNO logos_client_role

  ; --- ROL SERVIDOR -----------------------------------------------------------
  DetailPrint "Configurando rol Servidor..."
  DetailPrint "Inicializando base de datos y registrando servicios de Windows..."

  ; Ejecutar setup-server.ps1 con admin (NSIS ya corre elevado por UAC)
  nsExec::ExecToStack 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$INSTDIR\resources\setup-server.ps1" -InstallDir "$INSTDIR"'
  Pop $R0  ; exit code  (0 = OK)
  Pop $R1  ; output text (truncado a ~1 KB por nsExec)

  IntCmp $R0 0 logos_setup_ok logos_setup_ok logos_setup_error
  logos_setup_error:
    DetailPrint "Error en setup-server.ps1 (codigo $R0)"
    MessageBox MB_ICONSTOP "Error al configurar el servidor Logos POS.$\n$\nCodigo: $R0$\n$\nRevisa los logs en C:\ProgramData\LogosPOS\logs\ y contacta a soporte.$\n$\nLa aplicacion quedo instalada pero los servicios no estan activos."
    Goto logos_setup_done
  logos_setup_ok:
    DetailPrint "Servicios de Windows registrados correctamente."
  logos_setup_done:

  ; Escribir logos-config.json en AppData del usuario instalador.
  ; Electron lo lee al abrir: activa modo supervisor y salta el wizard de setup.
  CreateDirectory "$APPDATA\logos-pos"
  FileOpen  $R9 "$APPDATA\logos-pos\logos-config.json" w
  FileWrite $R9 '{"firstRun":false,"role":"server","serverPort":8080,"dbPort":3306,"serverIp":null}'
  FileClose $R9

  DetailPrint "Rol Servidor configurado."
  Goto logos_role_done

  ; --- ROL CLIENTE ------------------------------------------------------------
  logos_client_role:
  DetailPrint "Configurando rol Cliente..."

  ; No se registran servicios. Electron mostrara la pantalla de IP al abrir.
  CreateDirectory "$APPDATA\logos-pos"
  FileOpen  $R9 "$APPDATA\logos-pos\logos-config.json" w
  FileWrite $R9 '{"firstRun":false,"role":"client","serverPort":8080,"dbPort":3306,"serverIp":null}'
  FileClose $R9

  DetailPrint "Rol Cliente configurado (se pedira la IP del servidor al abrir la aplicacion)."

  logos_role_done:

!macroend


; =============================================================================
; DESINSTALACION
; =============================================================================
!macro customUnInstall

  ; Detener y remover servicios NSSM si existen
  IfFileExists "$INSTDIR\resources\teardown-server.ps1" logos_do_teardown logos_no_teardown
  logos_do_teardown:
    DetailPrint "Deteniendo servicios de Logos POS..."
    nsExec::ExecToStack 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$INSTDIR\resources\teardown-server.ps1" -InstallDir "$INSTDIR"'
    Pop $R0
    Pop $R1
    DetailPrint "Teardown completado (codigo: $R0)"
  logos_no_teardown:

!macroend
