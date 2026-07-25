'use strict';
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('logos', {
  getConfig:         ()    => ipcRenderer.invoke('get-config'),
  getLocalIps:       ()    => ipcRenderer.invoke('get-local-ips'),
  saveRole:          (r)   => ipcRenderer.invoke('save-role', r),
  saveServerIp:      (ip)  => ipcRenderer.invoke('save-server-ip', ip),
  retryConnection:   ()    => ipcRenderer.invoke('retry-connection'),
  reconfigureClient: ()    => ipcRenderer.invoke('reconfigure-client'),
  resetConfig:       ()    => ipcRenderer.invoke('reset-config'),
  onLoadingStatus:   (cb)  => ipcRenderer.on('loading-status',    (_, msg) => cb(msg)),
  onShowError:       (cb)  => ipcRenderer.on('show-error',        (_, d)   => cb(d)),
  onUpdateStart:     (cb)  => ipcRenderer.on('update-start',      (_, v)   => cb(v)),
  onUpdateProgress:  (cb)  => ipcRenderer.on('update-progress',   (_, pct) => cb(pct)),
  onUpdateInstalling:(cb)  => ipcRenderer.on('update-installing', ()       => cb()),
});
