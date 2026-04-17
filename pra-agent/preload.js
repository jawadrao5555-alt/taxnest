const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('agentAPI', {
  getConfig: () => ipcRenderer.invoke('get-config'),
  saveConfig: (config) => ipcRenderer.invoke('save-config', config),
  getStatus: () => ipcRenderer.invoke('get-status'),
  toggleAgent: (enabled) => ipcRenderer.invoke('toggle-agent', enabled),
  testConnection: (config) => ipcRenderer.invoke('test-connection', config),
  onStatusUpdate: (callback) =>
    ipcRenderer.on('status-update', (event, status) => callback(status)),
});
