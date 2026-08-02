'use strict';
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('logos', {
  getConfig:         ()    => ipcRenderer.invoke('get-config'),
  getLocalIps:       ()    => ipcRenderer.invoke('get-local-ips'),
  saveServerIp:      (ip)  => ipcRenderer.invoke('save-server-ip', ip),
  retryConnection:   ()    => ipcRenderer.invoke('retry-connection'),
  reconfigureClient: ()    => ipcRenderer.invoke('reconfigure-client'),
  resetConfig:       ()    => ipcRenderer.invoke('reset-config'),
  saveRemoteAccessConfig: (cfg) => ipcRenderer.invoke('save-remote-access-config', cfg),
  saveLicenciaToken:      (tok) => ipcRenderer.invoke('save-licencia-token', tok),
  getAppVersion:          ()    => ipcRenderer.invoke('get-app-version'),
  onLoadingStatus:   (cb)  => ipcRenderer.on('loading-status',    (_, msg) => cb(msg)),
  onShowError:       (cb)  => ipcRenderer.on('show-error',        (_, d)   => cb(d)),
  onUpdateStart:     (cb)  => ipcRenderer.on('update-start',      (_, v)   => cb(v)),
  onUpdateProgress:  (cb)  => ipcRenderer.on('update-progress',   (_, pct) => cb(pct)),
  onUpdateInstalling:(cb)  => ipcRenderer.on('update-installing', ()       => cb()),
});
