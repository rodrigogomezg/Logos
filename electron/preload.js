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
  onLoadingStatus:   (cb)  => ipcRenderer.on('loading-status', (_, msg) => cb(msg)),
  onShowError:       (cb)  => ipcRenderer.on('show-error',     (_, d)   => cb(d)),
});
