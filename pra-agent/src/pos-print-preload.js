// The secondary POS session can print without taking over the paired Agent.
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('nestposDesktop', {
  desktop: true,
  printHtml: (html, printer) => ipcRenderer.invoke('pos-print-html', html, printer || null),
  acceptImmediateSale: (sale) => ipcRenderer.invoke('local-core-accept-immediate-sale', sale),
  getVersion: () => ipcRenderer.invoke('get-version'),
});
