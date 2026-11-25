// The secondary POS session can print without taking over the paired Agent.
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('nestposDesktop', {
  desktop: true,
  printHtml: (html, printer) => ipcRenderer.invoke('pos-print-html', html, printer || null),
  getVersion: () => ipcRenderer.invoke('get-version'),
});
