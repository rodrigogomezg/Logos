'use strict';
const { contextBridge, ipcRenderer } = require('electron');

// Cada arranque de la app (cierre y reapertura, reinicio tras auto-update,
// crash) tiene que pedir usuario y clave de nuevo — no alcanza con que se
// muera el proceso para que alguien físicamente frente a la PC quede adentro
// del POS sin loguearse. Esto corre sincrónico ANTES de que se ejecute
// cualquier script de la página (auth.js incluido), en la primera pantalla
// que cargue esta sesión de Electron — no afecta la navegación normal
// dentro de la misma sesión ya abierta. Ver CLAUDE.md.
try {
  if (ipcRenderer.sendSync('debe-limpiar-sesion')) {
    localStorage.removeItem('logos_sesion');
  }
} catch (e) { /* no bloquear la carga de la página por esto */ }

contextBridge.exposeInMainWorld('logos', {
  getConfig:         ()    => ipcRenderer.invoke('get-config'),
  getLocalIps:       ()    => ipcRenderer.invoke('get-local-ips'),
  saveServerIp:      (ip)  => ipcRenderer.invoke('save-server-ip', ip),
  retryConnection:   ()    => ipcRenderer.invoke('retry-connection'),
  reconfigureClient: ()    => ipcRenderer.invoke('reconfigure-client'),
  resetConfig:       ()    => ipcRenderer.invoke('reset-config'),
  saveRemoteAccessConfig: (cfg) => ipcRenderer.invoke('save-remote-access-config', cfg),
  saveLicenciaToken:      (tok) => ipcRenderer.invoke('save-licencia-token', tok),
  verificarLicenciaAhora: ()    => ipcRenderer.invoke('verificar-licencia-ahora'),
  getAppVersion:          ()    => ipcRenderer.invoke('get-app-version'),
  restartApp:             ()    => ipcRenderer.invoke('restart-app'),
  onLoadingStatus:   (cb)  => ipcRenderer.on('loading-status',    (_, msg) => cb(msg)),
  onShowError:       (cb)  => ipcRenderer.on('show-error',        (_, d)   => cb(d)),
  onUpdateStart:     (cb)  => ipcRenderer.on('update-start',      (_, v)   => cb(v)),
  onUpdateProgress:  (cb)  => ipcRenderer.on('update-progress',   (_, pct) => cb(pct)),
  onUpdateInstalling:(cb)  => ipcRenderer.on('update-installing', ()       => cb()),
});
