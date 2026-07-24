// NestPOS Desktop — POS screen shell (v1.4.0, "Rasta B").
//
// A BrowserWindow that loads the LIVE TaxNest POS web app so shops get a
// desktop POS without a browser: features keep deploying server-side, this
// shell rarely changes. Purely ADDITIVE to the sync agent — if this window
// crashes or never opens, PRA sync + silent printing keep running untouched.
//
// Key decisions (architect-reviewed):
// - Session lives in a persistent partition ('persist:pos') so the cashier's
//   login survives restarts. Cashier should tick "remember" on the login form.
// - Offline: the sale screen already has an in-page IndexedDB bill queue that
//   keeps billing while the window stays open. We deliberately do NOT cache
//   the sale screen locally (it is excluded from SW caching on purpose).
//   Cold-start with no internet shows offline.html which auto-retries.
// - Kiosk mode: optional, off by default. Ctrl+Alt+K toggles it from inside
//   the POS window (and the tray menu mirrors it).
// - window.open: same-origin popups open as child windows in the same
//   partition; everything else (WhatsApp links etc.) goes to the system browser.
const { BrowserWindow, shell } = require('electron');
const path = require('path');
const offlineSnapshot = require('./offline-snapshot');

let posWindow = null;
let targetUrl = null;
let onKioskToggleCb = null;

function deriveOrigin(config) {
  try {
    return new URL(config.serverUrl).origin;
  } catch (e) {
    return 'https://taxnest.com.pk';
  }
}

function getPosWindowRef() {
  return posWindow && !posWindow.isDestroyed() ? posWindow : null;
}

function isPosWindowOpen() {
  return !!getPosWindowRef();
}

function applyKiosk(kiosk) {
  const win = getPosWindowRef();
  if (!win) return;
  try {
    win.setKiosk(!!kiosk);
    win.setFullScreen(!!kiosk);
    if (kiosk) { win.show(); win.focus(); }
  } catch (e) {}
}

function loadOfflinePage(win, failedUrl, errorDescription) {
  if (!win || win.isDestroyed()) return;
  win.loadFile(path.join(__dirname, '..', 'offline.html'), {
    query: {
      target: targetUrl || failedUrl || '',
      err: String(errorDescription || ''),
    },
  }).catch(() => {});
}

function openPosWindow(config, opts = {}) {
  const existing = getPosWindowRef();
  if (existing) {
    existing.show();
    existing.focus();
    return existing;
  }

  const origin = deriveOrigin(config);
  // Land on the sale screen; if not logged in, Laravel redirects to the POS
  // login and (via redirect()->intended) returns here after login.
  targetUrl = origin + '/pos/invoice/create';
  onKioskToggleCb = typeof opts.onKioskToggle === 'function' ? opts.onKioskToggle : null;
  let kioskOn = !!opts.kiosk;
  const isOfflineEnabled =
    typeof opts.isOfflineEnabled === 'function' ? opts.isOfflineEnabled : () => false;

  posWindow = new BrowserWindow({
    width: 1366,
    height: 820,
    minWidth: 900,
    minHeight: 600,
    title: 'NestPOS Desktop',
    icon: path.join(__dirname, '..', 'assets', 'icon.png'),
    fullscreen: kioskOn,
    kiosk: kioskOn,
    autoHideMenuBar: true,
    backgroundColor: '#0A4D5C',
    webPreferences: {
      partition: 'persist:pos',
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      preload: path.join(__dirname, 'pos-preload.js'),
    },
  });

  // Keep our own title — the web app rewrites document.title on every page.
  posWindow.on('page-title-updated', (e) => e.preventDefault());

  // Offline Mode (Beta): register the passthrough-first https interception on
  // the persist:pos partition BEFORE the first load, and snapshot the sale
  // screen after each successful online load. Registration only happens when
  // the setting is ON at window-open time (OFF = zero behavior change);
  // isOfflineEnabled() is also re-checked before ever serving a snapshot.
  try {
    if (isOfflineEnabled()) {
      offlineSnapshot.registerOfflineInterception(
        posWindow.webContents.session, origin, isOfflineEnabled
      );
    }
    posWindow.webContents.on('did-finish-load', () => {
      try {
        if (!isOfflineEnabled()) return;
        const cur = posWindow.webContents.getURL() || '';
        if (cur.startsWith(origin + '/pos/invoice/create')) {
          offlineSnapshot.scheduleCapture(posWindow.webContents.session, origin, cur);
        }
      } catch (e) {}
    });
  } catch (e) {}

  // Ctrl+Alt+K toggles kiosk from inside the POS window (staff escape hatch).
  posWindow.webContents.on('before-input-event', (event, input) => {
    if (
      input.type === 'keyDown' && input.control && input.alt &&
      String(input.key || '').toLowerCase() === 'k'
    ) {
      event.preventDefault();
      kioskOn = !kioskOn;
      applyKiosk(kioskOn);
      if (onKioskToggleCb) { try { onKioskToggleCb(kioskOn); } catch (e) {} }
    }
  });

  // Offline / load-failure fallback. -3 = ERR_ABORTED (normal navigations
  // being superseded) — never treat that as a failure.
  posWindow.webContents.on('did-fail-load', (e, errorCode, errorDescription, validatedURL, isMainFrame) => {
    if (!isMainFrame || errorCode === -3) return;
    loadOfflinePage(posWindow, validatedURL, errorDescription);
  });

  // Same-origin popups (receipt/report windows) stay inside the app + same
  // session partition; external links open in the system browser.
  posWindow.webContents.setWindowOpenHandler(({ url }) => {
    try {
      if (new URL(url).origin === origin) {
        return {
          action: 'allow',
          overrideBrowserWindowOptions: {
            autoHideMenuBar: true,
            webPreferences: {
              partition: 'persist:pos',
              contextIsolation: true,
              nodeIntegration: false,
              sandbox: true,
            },
          },
        };
      }
    } catch (err) {}
    shell.openExternal(url).catch(() => {});
    return { action: 'deny' };
  });

  posWindow.on('closed', () => {
    posWindow = null;
    onKioskToggleCb = null;
  });

  posWindow.loadURL(targetUrl).catch(() => loadOfflinePage(posWindow, targetUrl, 'load failed'));

  return posWindow;
}

function closePosWindow() {
  const win = getPosWindowRef();
  if (win) { try { win.close(); } catch (e) {} }
}

module.exports = { openPosWindow, getPosWindowRef, isPosWindowOpen, applyKiosk, closePosWindow };
