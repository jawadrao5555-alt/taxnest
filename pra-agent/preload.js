const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('agentAPI', {
  getConfig: () => ipcRenderer.invoke('get-config'),
  saveConfig: (config) => ipcRenderer.invoke('save-config', config),
  getStatus: () => ipcRenderer.invoke('get-status'),
  toggleAgent: (enabled) => ipcRenderer.invoke('toggle-agent', enabled),
  testConnection: (config) => ipcRenderer.invoke('test-connection', config),
  onStatusUpdate: (callback) =>
    ipcRenderer.on('status-update', (event, status) => callback(status)),
  checkUpdate: () => ipcRenderer.invoke('check-update'),
  openDownload: () => ipcRenderer.invoke('open-download'),
  getVersion: () => ipcRenderer.invoke('get-version'),
  onUpdateAvailable: (callback) =>
    ipcRenderer.on('update-available', (event, info) => callback(info)),
  checkImsService: () => ipcRenderer.invoke('check-ims-service'),
  installFbrIms: () => ipcRenderer.invoke('install-fbr-ims'),
  onImsProgress: (callback) =>
    ipcRenderer.on('ims-progress', (event, p) => callback(p)),
  getPosSettings: () => ipcRenderer.invoke('get-pos-settings'),
  savePosSettings: (s) => ipcRenderer.invoke('save-pos-settings', s),
  openPosWindow: () => ipcRenderer.invoke('open-pos-window'),
});
