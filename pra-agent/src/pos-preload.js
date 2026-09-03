// Preload for the NestPOS Desktop POS window.
//
// Exposes a tiny, safe bridge so the LIVE web app can detect it is running
// inside the desktop shell and (in a future web-side deploy) hand receipt
// HTML over for true silent printing. Shipping the bridge NOW means older
// desktop installs already support the hook by the time the web side uses it.
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('nestposDesktop', {
  desktop: true,
  // Silent-print raw receipt/KOT HTML on the shop printer (no dialog).
  // printer omitted/empty = Windows default printer.
  printHtml: (html, printer) => ipcRenderer.invoke('pos-print-html', html, printer || null),
  // Typed immediate-sale command. Main injects/validates its exact authority
  // scope; arbitrary browser-authored Core events are deliberately not exposed.
  acceptImmediateSale: (sale) => ipcRenderer.invoke('local-core-accept-immediate-sale', sale),
  getVersion: () => ipcRenderer.invoke('get-version'),
});
