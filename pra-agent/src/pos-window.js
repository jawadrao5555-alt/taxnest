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
const { BrowserWindow, shell, app, Notification } = require('electron');
const path = require('path');
const fs = require('fs');
const offlineSnapshot = require('./offline-snapshot');

// NestPOS icon: packaged builds ship it via extraResources (stable path next
// to app.asar); dev falls back to the repo assets folder.
function nestposIcon(ext) {
  const candidates = [
    path.join(process.resourcesPath || '', 'nestpos.' + ext),
    path.join(__dirname, '..', 'assets', 'nestpos.' + ext),
  ];
  for (const p of candidates) {
    try { if (p && fs.existsSync(p)) return p; } catch (e) {}
  }
  return null;
}

let posWindow = null;
let targetUrl = null;
let onKioskToggleCb = null;
let forceClose = false;          // set only by closePosWindow() / app quit
let hideNoticeShown = false;     // one tray notification per run
let lastResumeCheck = 0;         // throttle for the on-show freshness check

// Keep-alive resume check (v1.5.2): the POS window now HIDES on close instead
// of being destroyed, so "Open POS" is instant (no reload). Every time it
// comes back on screen we ask the page to re-verify its boot fingerprint —
// if we deployed an update while the window was hidden, the sale screen
// reloads ONCE (its own busy-guard never yanks a sale in progress). The hook
// is a no-op on login/offline pages where it doesn't exist.
function runResumeCheck(win) {
  try {
    const now = Date.now();
    if (now - lastResumeCheck < 60 * 1000) return;
    lastResumeCheck = now;
    win.webContents
      .executeJavaScript('window.tnDesktopResumeCheck && window.tnDesktopResumeCheck(); true', true)
      .catch(() => {});
  } catch (e) {}
}

function deriveOrigin(config) {
  try {
    return new URL(config.serverUrl).origin;
  } catch (e) {
    return 'https://taxnest.com.pk';
  }
}

// ─── Silent downloads (Task 1062) ───────────────────────────────────────────
// Neither POS session had a will-download handler, so any export/PDF link
// popped Chromium's default Save dialog in front of the cashier. Instead:
// auto-save into the PC's Downloads folder (collision-safe name), then a
// Roman Urdu notification confirms it — clicking the notification opens the
// file. Registered ONCE per session; child popup windows share the same
// partition, so this single handler covers them too.
const downloadWiredSessions = new WeakSet();

function wireSilentDownloads(ses) {
  try {
    if (!ses || downloadWiredSessions.has(ses)) return;
    downloadWiredSessions.add(ses);
    ses.on('will-download', (event, item) => {
      try {
        const downloadsDir = app.getPath('downloads');
        const rawName = String(item.getFilename() || 'download').replace(/[\\/:*?"<>|]/g, '_');
        const ext = path.extname(rawName);
        const base = path.basename(rawName, ext) || 'download';
        let target = path.join(downloadsDir, base + ext);
        for (let i = 1; i < 200 && fs.existsSync(target); i++) {
          target = path.join(downloadsDir, `${base} (${i})${ext}`);
        }
        // setSavePath BEFORE any await/return = no Save dialog, ever.
        item.setSavePath(target);
        item.once('done', (_e, state) => {
          try {
            if (!Notification.isSupported()) return;
            if (state === 'completed') {
              const n = new Notification({
                title: 'File download ho gayi',
                body: path.basename(target) + ' — Downloads folder mein save ho gayi. Kholne ke liye yahan click karein.',
              });
              n.on('click', () => {
                // Open the file itself; if Windows has no app for it, at least
                // reveal it in the Downloads folder.
                shell.openPath(target).then((err) => {
                  if (err) { try { shell.showItemInFolder(target); } catch (e2) {} }
                }).catch(() => { try { shell.showItemInFolder(target); } catch (e2) {} });
              });
              n.show();
            } else {
              new Notification({
                title: 'Download mukammal nahi hui',
                body: path.basename(target) + ' download nahi ho saki — dobara koshish karein.',
              }).show();
            }
          } catch (e) {}
        });
      } catch (e) {
        // Never block the download pipeline on our own failure — worst case
        // Chromium falls back to its default behavior.
      }
    });
  } catch (e) {}
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
    // Keep-alive path (v1.5.2): the window survived its last "close" as a
    // hidden window — showing it is INSTANT (no page reload). Freshness is
    // handled by the resume check below.
    existing.show();
    existing.focus();
    runResumeCheck(existing);
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
    icon: nestposIcon(process.platform === 'win32' ? 'ico' : 'png') ||
      path.join(__dirname, '..', 'assets', 'icon.png'),
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
      // Keep-alive (v1.5.2): the window keeps living hidden after "close",
      // so timers must NOT be throttled in the background — the sale screen's
      // incoming-orders poll + offline bill queue keep running at full speed.
      backgroundThrottling: false,
    },
  });

  // Keep our own title — the web app rewrites document.title on every page.
  posWindow.on('page-title-updated', (e) => e.preventDefault());

  // SEPARATE app identity on the Windows taskbar: NestPOS gets its own
  // AppUserModelID + icon, so it groups apart from the agent, shows its own
  // icon at the bottom, and can be pinned — pinning relaunches this same exe
  // with --pos (straight into the POS screen).
  try {
    if (process.platform === 'win32') {
      const ico = nestposIcon('ico');
      posWindow.setAppDetails({
        appId: 'com.taxnest.nestpos',
        appIconPath: ico || undefined,
        appIconIndex: 0,
        relaunchCommand: '"' + process.execPath + '" --pos',
        relaunchDisplayName: 'NestPOS',
      });
    }
  } catch (e) {}

  // Tag the shell in the user agent so the server can recognize NestPOS
  // Desktop (e.g. POS login pre-ticks "Remember me" — the persist:pos
  // partition keeps the session across restarts, so remember SHOULD be on).
  try {
    const ua = posWindow.webContents.getUserAgent() || '';
    if (!ua.includes('NestPOSDesktop')) {
      posWindow.webContents.setUserAgent(ua + ' NestPOSDesktop/' + app.getVersion());
    }
  } catch (e) {}

  // Exports/PDFs save silently to Downloads (covers child popups too — same
  // session partition).
  wireSilentDownloads(posWindow.webContents.session);

  // Agent AUTO-CONFIG (v1.5.0): once the cashier is logged in (any authed
  // /pos/ page loaded), let main.js pull the company's agent credentials
  // with the session cookie — zero manual agent setup on shop PCs.
  // onLoggedIn returns a promise resolving true on success; until then we
  // keep retrying on subsequent page loads (e.g. first load was the login
  // page, or the config fetch raced a network drop).
  // v1.5.0 beta4: seeing the LOGIN page again re-arms the check — so after a
  // logout + login as ANOTHER company, main.js re-fetches and the agent swaps
  // to the new company's credentials automatically (owner rule: the agent
  // follows whoever is logged into the POS window).
  const onLoggedIn = typeof opts.onLoggedIn === 'function' ? opts.onLoggedIn : null;
  let autoCfgDone = false;
  let autoCfgInFlight = false;
  let autoCfgGen = 0; // bumped on every login-page sighting — a stale fetch can't clobber a newer re-arm
  posWindow.webContents.on('did-finish-load', () => {
    try {
      if (!onLoggedIn) return;
      const cur = posWindow.webContents.getURL() || '';
      // Login-page re-arm runs BEFORE the inFlight gate so a logout that races
      // an in-flight config fetch still re-arms the check for the next login.
      if (cur.startsWith(origin + '/pos/login')) { autoCfgDone = false; autoCfgGen++; return; }
      if (autoCfgDone || autoCfgInFlight) return;
      if (!cur.startsWith(origin + '/pos/')) return;
      const gen = autoCfgGen;
      autoCfgInFlight = true;
      Promise.resolve(onLoggedIn(posWindow.webContents.session, origin))
        .then((ok) => { if (gen === autoCfgGen) autoCfgDone = !!ok; })
        .catch(() => {})
        .finally(() => { autoCfgInFlight = false; });
    } catch (e) { autoCfgInFlight = false; }
  });

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

  // Keep-alive (v1.5.2): "close" HIDES the window instead of destroying it —
  // the sale screen stays fully loaded (login, screen, data sab qaim), so the
  // next "Open POS" is instant like a real desktop app. A real close happens
  // only on app quit / self-update (forceClose or opts.isQuitting()).
  const isQuittingFn = typeof opts.isQuitting === 'function' ? opts.isQuitting : () => false;
  posWindow.on('close', (e) => {
    if (forceClose || isQuittingFn()) return; // let it really close
    e.preventDefault();
    try { posWindow.hide(); } catch (err) {}
    if (!hideNoticeShown) {
      hideNoticeShown = true;
      try {
        if (Notification.isSupported()) {
          new Notification({
            title: 'NestPOS',
            body: 'NestPOS background mein tayyar hai — dobara kholne par foran khulega (loading nahi hogi).',
          }).show();
        }
      } catch (err) {}
    }
  });

  // Whenever the window comes back on screen, re-verify freshness (throttled).
  posWindow.on('show', () => runResumeCheck(posWindow));
  posWindow.on('restore', () => runResumeCheck(posWindow));

  posWindow.on('closed', () => {
    posWindow = null;
    onKioskToggleCb = null;
    forceClose = false; // reset here (not synchronously) — safe even if a future beforeunload defers 'close'
  });

  posWindow.loadURL(targetUrl).catch(() => loadOfflinePage(posWindow, targetUrl, 'load failed'));

  return posWindow;
}

function closePosWindow() {
  const win = getPosWindowRef();
  if (win) {
    forceClose = true;
    try { win.close(); } catch (e) { forceClose = false; }
  }
  closeFbrWindow();
}

// ─── FBR POS window (v1.6.0) ────────────────────────────────────────────────
// A second, SIMPLER shell window for the FBR POS panel (/fbr-pos/). Own
// persistent partition ('persist:fbrpos' — separate guard/login from PRA POS).
// Deliberately minimal: no agent auto-config (that is PRA-panel-only), no
// offline snapshot, no kiosk coupling — just a clean desktop window with
// keep-alive hide, offline fallback and the same popup rules.
let fbrWindow = null;
let fbrForceClose = false;
let fbrTargetUrl = null;

function getFbrWindowRef() {
  return fbrWindow && !fbrWindow.isDestroyed() ? fbrWindow : null;
}

function openFbrPosWindow(config, opts = {}) {
  const existing = getFbrWindowRef();
  if (existing) {
    existing.show();
    existing.focus();
    return existing;
  }

  const origin = deriveOrigin(config);
  fbrTargetUrl = origin + '/fbr-pos/invoice/create';
  const isQuittingFn = typeof opts.isQuitting === 'function' ? opts.isQuitting : () => false;

  fbrWindow = new BrowserWindow({
    width: 1366,
    height: 820,
    minWidth: 900,
    minHeight: 600,
    title: 'NestPOS Desktop — FBR POS',
    icon: nestposIcon(process.platform === 'win32' ? 'ico' : 'png') ||
      path.join(__dirname, '..', 'assets', 'icon.png'),
    autoHideMenuBar: true,
    backgroundColor: '#0A4D5C',
    webPreferences: {
      partition: 'persist:fbrpos',
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      backgroundThrottling: false,
    },
  });

  fbrWindow.on('page-title-updated', (e) => e.preventDefault());

  try {
    const ua = fbrWindow.webContents.getUserAgent() || '';
    if (!ua.includes('NestPOSDesktop')) {
      fbrWindow.webContents.setUserAgent(ua + ' NestPOSDesktop/' + app.getVersion());
    }
  } catch (e) {}

  // Identical silent-download behavior for the FBR window (persist:fbrpos).
  wireSilentDownloads(fbrWindow.webContents.session);

  fbrWindow.webContents.on('did-fail-load', (e, errorCode, errorDescription, validatedURL, isMainFrame) => {
    if (!isMainFrame || errorCode === -3) return;
    if (!fbrWindow || fbrWindow.isDestroyed()) return;
    fbrWindow.loadFile(path.join(__dirname, '..', 'offline.html'), {
      query: { target: fbrTargetUrl || validatedURL || '', err: String(errorDescription || '') },
    }).catch(() => {});
  });

  fbrWindow.webContents.setWindowOpenHandler(({ url }) => {
    try {
      if (new URL(url).origin === origin) {
        return {
          action: 'allow',
          overrideBrowserWindowOptions: {
            autoHideMenuBar: true,
            webPreferences: {
              partition: 'persist:fbrpos',
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

  // Keep-alive hide on close (same rule as the PRA POS window).
  fbrWindow.on('close', (e) => {
    if (fbrForceClose || isQuittingFn()) return;
    e.preventDefault();
    try { fbrWindow.hide(); } catch (err) {}
  });

  fbrWindow.on('closed', () => {
    fbrWindow = null;
    fbrForceClose = false;
  });

  fbrWindow.loadURL(fbrTargetUrl).catch(() => {
    if (!fbrWindow || fbrWindow.isDestroyed()) return;
    fbrWindow.loadFile(path.join(__dirname, '..', 'offline.html'), {
      query: { target: fbrTargetUrl, err: 'load failed' },
    }).catch(() => {});
  });

  return fbrWindow;
}

function closeFbrWindow() {
  const win = getFbrWindowRef();
  if (win) {
    fbrForceClose = true;
    try { win.close(); } catch (e) { fbrForceClose = false; }
  }
}

module.exports = { openPosWindow, getPosWindowRef, isPosWindowOpen, applyKiosk, closePosWindow, openFbrPosWindow, closeFbrWindow };
