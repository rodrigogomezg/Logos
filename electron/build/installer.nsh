; installer.nsh -- Logos POS custom NSIS macros
; Incluido por electron-builder via nsis.include en electron-builder.yml.
;
; customInit      ejecutado al inicio de .onInit, antes de cualquier pagina
; customInstall   ejecutado DESPUES de copiar archivos (dentro de Section Install)
; customUnInstall ejecutado ANTES de borrar archivos   (dentro de Section Uninstall)

; =============================================================================
; INIT — red de seguridad: abortar si no hay privilegios de administrador
; =============================================================================
; perMachine:true en electron-builder.yml ya declara RequestExecutionLevel admin,
; lo que fuerza UAC al arranque. Esta macro es una segunda linea de defensa para
; el caso en que alguna politica de grupo o wrapper externo permita sortear el
; manifiesto y el proceso llegue sin elevacion real.
!macro customInit
  UserInfo::GetAccountType
  Pop $0
  ${If} $0 != "admin"
    MessageBox MB_ICONSTOP "Este instalador requiere permisos de administrador.$\n$\nVolvé a ejecutarlo con 'Ejecutar como administrador', o instalalo con un usuario que tenga privilegios de administrador en esta PC."
    Quit
  ${EndIf}
!macroend

; =============================================================================
; INSTALACION
; =============================================================================
!macro customInstall

  ; Asegura que el panel de detalles muestre cada linea a medida que ocurre.
  ; Sin esto, algun tramo previo del template de electron-builder puede haber
  ; dejado el nivel de impresion en "none" y todo lo de aca abajo corre en
  ; silencio hasta el final.
  SetDetailsPrint both

  ; --- Auto-actualizacion (modo silencioso) -----------------------------------
  ; Cuando electron-updater invoca el instalador, lo hace con el flag /S (silent).
  ; En ese caso saltamos el dialogo de rol y la inicializacion de servicios —
  ; el rol ya esta configurado y los servicios los reiniciamos nosotros.
  ${If} ${Silent}
    DetailPrint "Modo actualizacion automatica — omitiendo seleccion de rol."

    ; Si los servicios NSSM existen (rol Servidor), reiniciarlos.
    ; Fueron detenidos por Electron antes de llamar a quitAndInstall().
    ; Si no existen (rol Cliente), sc start falla silenciosamente — no hay problema.
    ; "sc start" solo confirma que NSSM arranco el servicio de Windows — NO
    ; garantiza que php.exe ya este escuchando en su puerto (el proceso viejo
    ; puede tardar un instante en soltarlo). A proposito NO se espera/verifica
    ; nada aca: el instalador silencioso no tiene ninguna ventana visible, asi
    ; que cualquier espera aca es tiempo muerto sin feedback para quien esta
    ; mirando la pantalla. Reabrir de una y dejar que startServerRole() en
    ; main.js maneje la espera real — esa parte SI le muestra al usuario
    ; "Verificando servicios..." en pantalla mientras reintenta.
    DetailPrint "Reiniciando servicios LogosPOS si corresponde..."
    nsExec::ExecToStack 'sc start LogosPOS-DB'
    Pop $R0
    Pop $R1
    nsExec::ExecToStack 'sc start LogosPOS-PHP'
    Pop $R0
    Pop $R1
    DetailPrint "Servicios reiniciados (codigo DB=$R0). Reabriendo Logos POS..."

    ExecShell "open" "$INSTDIR\Logos POS.exe"

    Goto logos_role_done
  ${EndIf}

  ; --- Instalacion interactiva (primera vez o reinstalacion manual) -----------

  ; Carpeta compartida donde vive TODO el estado de esta instalacion: config
  ; (logos-config.json, leida por Electron sin importar que usuario de Windows
  ; abra la app), datos de MariaDB y logs. Se crea aca (antes de saber el rol)
  ; porque tanto Servidor (via setup-server.ps1) como Cliente (mas abajo) la
  ; necesitan. El grant a Usuarios es necesario porque la app corre sin
  ; elevacion en el uso normal, pero esta carpeta la crea el instalador elevado.
  DetailPrint "Preparando carpeta de datos compartida..."
  CreateDirectory "C:\ProgramData\LogosPOS"
  nsExec::ExecToLog 'icacls "C:\ProgramData\LogosPOS" /grant *S-1-5-32-545:(OI)(CI)M /T'
  Pop $R0

  ; --- Seleccion de rol -------------------------------------------------------
  MessageBox MB_YESNO|MB_ICONQUESTION "Logos POS — Drilogs (drilogs.com.ar)$\n$\nEsta computadora va a funcionar como SERVIDOR?$\n$\nSi  = Servidor: instala base de datos + servidor web en esta PC.$\n       Otras PCs de la red se conectan a este equipo.$\n$\nNo  = Cliente: abre una ventana que se conecta al servidor.$\n       (se pedira la IP del servidor al abrir la aplicacion)" IDNO logos_client_role

  ; --- ROL SERVIDOR -----------------------------------------------------------
  DetailPrint "Configurando rol Servidor..."
  DetailPrint "Inicializando base de datos y registrando servicios de Windows..."

  ; Ejecutar setup-server.ps1 con admin (NSIS ya corre elevado por UAC).
  ; ExecToLog canaliza el stdout de PowerShell al panel de detalles del instalador
  ; en tiempo real — el usuario ve cada linea de progreso (puertos probados,
  ; servicios registrados, etc.) a medida que ocurren. El script escribe
  ; logos-config.json el mismo (conoce el puerto real descubierto).
  DetailPrint "Buscando puertos disponibles e iniciando servicios..."
  nsExec::ExecToLog 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$INSTDIR\resources\setup-server.ps1" -InstallDir "$INSTDIR"'
  Pop $R0  ; exit code  (0 = OK)

  IntCmp $R0 0 logos_setup_ok logos_setup_ok logos_setup_error
  logos_setup_error:
    DetailPrint "Error en setup-server.ps1 (codigo $R0)"
    MessageBox MB_ICONSTOP "Error al configurar el servidor Logos POS.$\n$\nCodigo: $R0$\n$\nRevisa los logs en C:\ProgramData\LogosPOS\setup-log.txt$\nSoporte: drilogs.com.ar$\n$\nLa aplicacion quedo instalada pero los servicios no estan activos."
    Goto logos_setup_done
  logos_setup_ok:
    DetailPrint "Servicios de Windows registrados correctamente."
  logos_setup_done:
  ; logos-config.json fue escrito por setup-server.ps1 con el puerto correcto.

  DetailPrint "Rol Servidor configurado."
  Goto logos_role_done

  ; --- ROL CLIENTE ------------------------------------------------------------
  logos_client_role:
  DetailPrint "Configurando rol Cliente..."

  ; No se registran servicios. Electron mostrara la pantalla de IP al abrir.
  FileOpen  $R9 "C:\ProgramData\LogosPOS\logos-config.json" w
  FileWrite $R9 '{"role":"client","serverPort":8080,"dbPort":3306,"serverIp":null}'
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

  ; --- Borrado completo opcional (base de datos + config) ----------------------
  ; Nunca en modo silencioso: un borrado irreversible de la base de un cliente
  ; real no puede depender de un flag /S que nadie confirmo a proposito en ese
  ; momento. Solo se ofrece en desinstalacion interactiva, y el default ante
  ; cualquier cosa que no sea un "Si" explicito (Esc, cerrar el dialogo, No)
  ; es NO BORRAR NADA.
  ${IfNot} ${Silent}
    MessageBox MB_YESNO|MB_ICONQUESTION "Se va a desinstalar Logos POS.$\n$\nQuerés borrar también la base de datos y toda la configuración de esta instalación (C:\ProgramData\LogosPOS)?$\n$\nSÍ = borrado completo, sin dejar rastro. Esta acción NO se puede deshacer.$\nNO = se desinstala solo el programa. Tus datos quedan intactos por si volvés a instalar Logos POS más adelante." IDYES logos_borrado_completo
    Goto logos_uninstall_done

    logos_borrado_completo:
      DetailPrint "Borrando base de datos y configuracion..."
      RMDir /r "C:\ProgramData\LogosPOS"
      RMDir /r "$APPDATA\logos-pos"
      DetailPrint "Borrado completo."
  ${EndIf}

  logos_uninstall_done:

!macroend
