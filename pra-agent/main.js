const { app, BrowserWindow, Tray, Menu, ipcMain, Notification, nativeImage, shell, dialog } = require('electron');

// Silent printing from a hidden window renders BLANK pages on some Windows
// GPUs/drivers when hardware acceleration is on (live failure: ZFC Pizza
// Point, Jul 2026 — jobs report success, paper comes out empty on every
// queue, while manual visible-window prints work fine). CPU rasterization
// is more than enough for receipt-sized pages. MUST run before app ready.
app.disableHardwareAcceleration();

const path = require('path');
const fs = require('fs');
const os = require('os');
const { spawn } = require('child_process');
const axios = require('axios');
const Store = require('electron-store');
const { startAgent, stopAgent, getStatus, setHeartbeatExtraProvider, setLanBridge, wakeAgent } = require('./src/agent');
const offlineSnapshot = require('./src/offline-snapshot');
const { createLanServer } = require('./src/lan-server');
const { printHtml: printHtmlSilent, getLocalPrinters } = require('./src/printer');
const { openPosWindow, getPosWindowRef, isPosWindowOpen, applyKiosk, openFbrPosWindow } = require('./src/pos-window');

const DOWNLOAD_URL = 'https://github.com/jawadrao5555-alt/nestpos-releases/releases/latest';
const BUILD_TIMESTAMP = '20260819-1';
let updateInfo = { available: false, currentBuild: BUILD_TIMESTAMP };

// ─── Zip-based SELF-UPDATE ──────────────────────────────────────────────────
// The TaxNest server's heartbeat response carries `agent_update`
// = { version, zip_url, zip_size } (server-side cached GitHub latest-release
// info — agents never hit api.github.com directly, so shared-ISP rate limits
// can't break update checks; the zip itself downloads from GitHub's CDN).
// When the server advertises a NEWER version we download the portable zip,
// extract it with PowerShell (no extra deps), then hand off to a detached
// updater .cmd that kills this app, robocopies the new files over the install
// folder and relaunches. No NSIS installer / code-signing needed.
let updateInProgress = false;
// Per-version retry state (Task 1062): a failed download/extract used to be
// blocked for the WHOLE app run (attemptedVersions Set) — and shop agents run
// for weeks, so one bad Wi-Fi moment stranded the shop on the old version
// with a "Click to download" banner. Now each newer version is re-attempted
// on a backoff schedule while the server still advertises it, hard-capped so
// a truly poisoned zip can never download/restart-loop the shop.
const UPDATE_MAX_ATTEMPTS = 6;
const UPDATE_BACKOFF_MS = [2 * 60e3, 5 * 60e3, 15 * 60e3, 30 * 60e3, 60 * 60e3];
const updateAttempts = new Map(); // version -> { count, nextAt }
// Last self-update attempt outcome — piggybacked on the heartbeat so a stuck
// shop is VISIBLE server-side instead of silent.
let lastUpdateAttempt = null; // { target, stage, error, at }

function parseVer(v) {
  const m = String(v || '').trim().replace(/^v/i, '').match(/^(\d+)\.(\d+)\.(\d+)$/);
  return m ? [+m[1], +m[2], +m[3]] : null;
}

function isNewerVersion(remote, local) {
  const r = parseVer(remote);
  const l = parseVer(local);
  if (!r || !l) return false;
  for (let i = 0; i < 3; i++) {
    if (r[i] > l[i]) return true;
    if (r[i] < l[i]) return false;
  }
  return false;
}

async function handleAgentUpdate(info) {
  let updStage = 'check'; // telemetry: which phase a failure happened in
  try {
    if (!info || !info.version || !info.zip_url) return;
    // Host pin: only ever download update zips from our own GitHub releases.
    // (No code-signing available, so a compromised/misconfigured server must
    // not be able to point agents at an arbitrary zip.)
    // Aug 2026: updates are moving to the public releases-only repo
    // (nestpos-releases) so the main source repo can go private. Accept BOTH
    // hosts during the transition — old agents pin only the taxnest host.
    const TRUSTED_UPDATE_HOSTS = [
      'https://github.com/jawadrao5555-alt/taxnest/releases/download/',
      'https://github.com/jawadrao5555-alt/nestpos-releases/releases/download/',
    ];
    if (!TRUSTED_UPDATE_HOSTS.some((h) => String(info.zip_url).startsWith(h))) {
      console.log(`[self-update] REJECTED zip_url outside trusted release host: ${info.zip_url}`);
      return;
    }
    if (process.platform !== 'win32' || !app.isPackaged) return;
    if (updateInProgress) return;
    if (!isNewerVersion(info.version, app.getVersion())) return;
    // Backoff-gated retry (was: one attempt per version per run). This runs
    // on every heartbeat (~30s); the nextAt gate turns that into 2m/5m/15m/
    // 30m/60m re-attempts, capped at UPDATE_MAX_ATTEMPTS per app run.
    const attemptState = updateAttempts.get(info.version) || { count: 0, nextAt: 0 };
    if (attemptState.count >= UPDATE_MAX_ATTEMPTS) {
      // Auto-retry exhausted — NOW surface the manual download banner as the
      // way out (until then it stays quiet while retries are still pending).
      if (!updateInfo.manualRequired) {
        updateInfo = {
          available: true,
          downloading: false,
          latestBuild: info.version,
          currentBuild: BUILD_TIMESTAMP,
          downloadUrl: DOWNLOAD_URL,
          autoRetrying: false,
          manualRequired: true,
          error: updateInfo.error || 'Auto-update failed repeatedly',
        };
        sendUpdateState();
      }
      return;
    }
    if (Date.now() < attemptState.nextAt) return;
    const backoff = UPDATE_BACKOFF_MS[Math.min(attemptState.count, UPDATE_BACKOFF_MS.length - 1)];
    updateAttempts.set(info.version, { count: attemptState.count + 1, nextAt: Date.now() + backoff });
    updateInProgress = true;

    console.log(`[self-update] v${app.getVersion()} -> v${info.version} — downloading ${info.zip_url}`);
    updStage = 'download';
    updateInfo = {
      available: true,
      downloading: true,
      latestBuild: info.version,
      currentBuild: BUILD_TIMESTAMP,
      downloadUrl: DOWNLOAD_URL,
      autoRetrying: false,
      manualRequired: false,
      progress: 0,
    };
    sendUpdateState();
    if (tray) {
      try { tray.setToolTip(`TaxNest PRA Sync Agent — updating to v${info.version}…`); } catch (e) {}
    }

    // UNIQUE per-attempt workDir (v1.9.1). The old fixed name was a trap: the
    // apply-update.cmd relaunched the new exe with CWD *inside* the workDir, so
    // the running agent locked that directory and every later update died at
    // rmSync with EPERM until the PC rebooted (ZFC sat on 1.6.2 for weeks this
    // way). A unique name never needs to delete a possibly-CWD-locked dir.
    const workDir = path.join(os.tmpdir(), `taxnest-agent-update-${Date.now()}`);
    // Best-effort sweep of stale update dirs (old fixed-name one included).
    // A dir that is some process's CWD refuses deletion — skip it silently;
    // it becomes deletable after the next reboot.
    try {
      for (const entry of fs.readdirSync(os.tmpdir())) {
        if (!/^taxnest-agent-update(-\d+)?$/.test(entry)) continue;
        try { fs.rmSync(path.join(os.tmpdir(), entry), { recursive: true, force: true }); } catch (e) {}
      }
    } catch (e) {}
    fs.mkdirSync(workDir, { recursive: true });
    const zipPath = path.join(workDir, 'update.zip');
    const extractDir = path.join(workDir, 'extracted');

    // Download with stall protection (mirrors the FBR IMS downloader below).
    const res = await axios.get(info.zip_url, { responseType: 'stream', timeout: 60000, maxRedirects: 10 });
    const total = parseInt(res.headers['content-length'] || '0', 10) || info.zip_size || 0;
    let done = 0;
    await new Promise((resolve, reject) => {
      const out = fs.createWriteStream(zipPath);
      let idleTimer = null;
      const resetIdle = () => {
        if (idleTimer) clearTimeout(idleTimer);
        idleTimer = setTimeout(() => {
          res.data.destroy(new Error('Update download stalled (no data for 90s)'));
        }, 90000);
      };
      resetIdle();
      res.data.on('data', (chunk) => {
        done += chunk.length;
        resetIdle();
        if (total) {
          const pct = Math.round((done / total) * 100);
          if (pct !== updateInfo.progress) {
            updateInfo = { ...updateInfo, progress: pct };
            sendUpdateState();
          }
        }
      });
      const fail = (err) => { if (idleTimer) clearTimeout(idleTimer); reject(err); };
      res.data.on('error', fail);
      out.on('error', fail);
      out.on('finish', () => { if (idleTimer) clearTimeout(idleTimer); resolve(); });
      res.data.pipe(out);
    });

    updStage = 'verify';
    const gotSize = fs.statSync(zipPath).size;
    if (info.zip_size && gotSize !== info.zip_size) {
      throw new Error(`Downloaded size ${gotSize} != expected ${info.zip_size}`);
    }

    // Extract with PowerShell — zero extra npm dependencies.
    updStage = 'extract';
    await new Promise((resolve, reject) => {
      const psq = (s) => `'${String(s).replace(/'/g, "''")}'`;
      const ps = spawn('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command',
        `Expand-Archive -LiteralPath ${psq(zipPath)} -DestinationPath ${psq(extractDir)} -Force`], { windowsHide: true });
      let err = '';
      ps.stderr.on('data', (d) => { err += d.toString(); });
      ps.on('close', (code) => (code === 0 ? resolve() : reject(new Error(err || `Expand-Archive exited ${code}`))));
      ps.on('error', reject);
    });

    // Locate the folder holding the new exe (zip root folder = TaxNest-PRA-Agent).
    updStage = 'locate';
    const exeName = path.basename(process.execPath);
    let srcDir = null;
    const candidates = [extractDir,
      ...fs.readdirSync(extractDir, { withFileTypes: true })
        .filter((e) => e.isDirectory())
        .map((e) => path.join(extractDir, e.name))];
    for (const dir of candidates) {
      if (fs.existsSync(path.join(dir, exeName))) { srcDir = dir; break; }
    }
    if (!srcDir) throw new Error(`${exeName} not found inside the update zip`);

    updStage = 'handoff';
    const destDir = path.dirname(process.execPath);
    const backupDir = path.join(workDir, 'backup');
    const cmdPath = path.join(workDir, 'apply-update.cmd');
    const exePath = path.join(destDir, exeName);
    // NOTE: no parenthesized if-blocks around %RETRIES% — plain %VAR% expansion
    // inside ( ) reads the stale value (classic batch pitfall).
    // Safe swap (Task 1062): back up the current install FIRST; if the copy
    // still fails after 5 passes, RESTORE the backup — the shop is never left
    // with a half-swapped dead agent (old version relaunches intact instead).
    // If even the backup fails (disk full/locked), skip the swap entirely and
    // just relaunch the current exe.
    const script = [
      '@echo off',
      'timeout /t 3 /nobreak >nul',
      `taskkill /F /IM "${exeName}" >nul 2>&1`,
      'timeout /t 2 /nobreak >nul',
      `robocopy "${destDir}" "${backupDir}" /E /R:2 /W:2 >nul`,
      'if %ERRORLEVEL% GEQ 8 goto launch',
      'set RETRIES=0',
      ':copyloop',
      `robocopy "${srcDir}" "${destDir}" /E /R:5 /W:2 >nul`,
      'if %ERRORLEVEL% LSS 8 goto launch',
      'set /a RETRIES+=1',
      'if %RETRIES% GEQ 5 goto restore',
      'timeout /t 3 /nobreak >nul',
      'goto copyloop',
      ':restore',
      `robocopy "${backupDir}" "${destDir}" /E /R:5 /W:2 >nul`,
      ':launch',
      `if not exist "${exePath}" robocopy "${backupDir}" "${destDir}" /E /R:5 /W:2 >nul`,
      // Leave the temp workDir BEFORE launching: `start` inherits this CWD, and
      // an agent whose CWD sits inside the update dir locks it forever (the
      // EPERM-on-next-update trap this v1.9.1 exists to fix).
      `cd /d "${destDir}"`,
      `start "" "${exePath}"`,
      'exit',
    ].join('\r\n');
    fs.writeFileSync(cmdPath, script);

    console.log('[self-update] handing off to updater script, quitting…');
    lastUpdateAttempt = { target: info.version, stage: 'handoff', error: null, at: new Date().toISOString() };
    updateInfo = { ...updateInfo, downloading: false, downloaded: true, progress: 100 };
    sendUpdateState();

    const child = spawn('cmd.exe', ['/c', cmdPath], { detached: true, stdio: 'ignore', windowsHide: true, cwd: workDir });
    child.unref();
    isQuitting = true;
    stopAgent();
    setTimeout(() => app.quit(), 500);
  } catch (e) {
    console.log('[self-update] failed at stage', updStage, ':', e && e.message);
    const attemptsSoFar = (updateAttempts.get(info && info.version) || { count: 0 }).count;
    const retryPending = attemptsSoFar < UPDATE_MAX_ATTEMPTS;
    // Heartbeat telemetry — the server stores this so a stuck shop is visible.
    lastUpdateAttempt = {
      target: (info && info.version) || null,
      stage: updStage,
      error: (e && e.message) || 'Update failed',
      at: new Date().toISOString(),
    };
    // While auto-retry is still pending, keep the banner QUIET (no "Click to
    // download" button) — staff should never need to act; retries handle it.
    updateInfo = {
      ...updateInfo,
      downloading: false,
      error: (e && e.message) || 'Update failed',
      autoRetrying: retryPending,
      manualRequired: !retryPending,
    };
    sendUpdateState();
    updateInProgress = false;
  }
}

function sendUpdateState() {
  if (mainWindow && !mainWindow.isDestroyed()) {
    mainWindow.webContents.send('update-available', updateInfo);
  }
}

const store = new Store();
let mainWindow = null;
let tray = null;
let isQuitting = false;

// ─── NestPOS Desktop (POS screen shell) settings ────────────────────────────
function getPosSettings() {
  const s = store.get('posSettings') || {};
  return { openOnStartup: !!s.openOnStartup, kiosk: !!s.kiosk, offlineMode: !!s.offlineMode };
}

function setPosSettings(next) {
  store.set('posSettings', {
    openOnStartup: !!next.openOnStartup,
    kiosk: !!next.kiosk,
    offlineMode: !!next.offlineMode,
  });
}

// ─── NestPOS LAN Mode (this PC becomes the shop's local server) ─────────────
// Opt-in. OFF = nothing listens and the agent behaves exactly as before.
// ON = waiter tablets and the Caller ID phone can reach this PC by IP, so the
// shop keeps working through an internet cut. See src/lan-server.js.
let lanServer = null;

function getLanSettings() {
  const s = store.get('lanSettings') || {};
  const port = Number(s.port);
  return {
    enabled: !!s.enabled,
    port: Number.isInteger(port) && port > 0 && port <= 65535 ? port : 8531,
  };
}

function setLanSettings(next) {
  const port = Number(next && next.port);
  store.set('lanSettings', {
    enabled: !!(next && next.enabled),
    port: Number.isInteger(port) && port > 0 && port <= 65535 ? port : 8531,
  });
}

function lanInstance() {
  if (!lanServer) {
    lanServer = createLanServer({
      dataDir: app.getPath('userData'),
      port: getLanSettings().port,
      version: app.getVersion(),
      log: (m) => console.log('[lan]', m),
    });
  }
  return lanServer;
}

// Restart the listener to match the saved settings. Always stop first so a
// port change actually moves the server instead of leaving the old one up.
async function applyLanSettings() {
  const s = getLanSettings();
  const srv = lanInstance();
  try { await srv.stop(); } catch (e) {}
  if (s.enabled) {
    try { await srv.start(s.port); } catch (e) { console.log('[lan] start failed:', e && e.message); }
  }
  return srv.status();
}

// Zero-config default (v1.5.0): the POS window can open BEFORE the agent is
// configured — it just loads the live POS on the default server. After login,
// autoConfigureAgent() below feeds the agent credentials automatically.
const DEFAULT_SERVER_URL = 'https://taxnest.com.pk/api/agent';

function openPos() {
  try {
    const config = store.get('config');
    const posConfig =
      config && config.serverUrl ? config : { serverUrl: DEFAULT_SERVER_URL };
    const s = getPosSettings();
    openPosWindow(posConfig, {
      kiosk: s.kiosk,
      isOfflineEnabled: () => getPosSettings().offlineMode,
      onKioskToggle: (kioskNow) => {
        setPosSettings({ ...getPosSettings(), kiosk: kioskNow });
        buildTrayMenu();
      },
      onLoggedIn: autoConfigureAgent,
      // Keep-alive (v1.5.2): POS window hides on close and must only REALLY
      // close when the whole app is quitting (tray Quit / self-update).
      isQuitting: () => isQuitting,
    });
    // First open "installs" NestPOS as its own app: Desktop + Start Menu
    // shortcuts with the NestPOS icon (relaunch straight into the POS).
    try {
      if (process.platform === 'win32' && !store.get('posShortcutCreated')) {
        createNestposShortcuts(true);
      }
    } catch (e) {}
    return true;
  } catch (e) {
    console.log('[pos-window] open failed:', e && e.message);
    return false;
  }
}

// FBR POS window (v1.6.0): simpler second shell window for the FBR panel.
// Failure here must never touch the agent or the PRA POS window.
function openFbrPos() {
  try {
    const config = store.get('config');
    const posConfig =
      config && config.serverUrl ? config : { serverUrl: DEFAULT_SERVER_URL };
    openFbrPosWindow(posConfig, { isQuitting: () => isQuitting });
    return true;
  } catch (e) {
    console.log('[fbr-window] open failed:', e && e.message);
    return false;
  }
}

// ─── NestPOS as a SEPARATE app (Desktop icon + own taskbar identity) ────────
// The POS window already carries its own Windows AppUserModelID
// ('com.taxnest.nestpos', set in pos-window.js) so it groups separately on
// the taskbar with its own icon and can be pinned. These shortcuts complete
// the picture: a "NestPOS" icon on the Desktop + Start Menu that launches
// this same exe with --pos, which opens the POS screen directly (agent stays
// in the tray). Exe name/path untouched — self-update keeps shortcuts valid.
function nestposIconPath() {
  const candidates = [
    path.join(process.resourcesPath || '', 'nestpos.ico'),
    path.join(__dirname, 'assets', 'nestpos.ico'),
  ];
  for (const p of candidates) {
    try { if (p && fs.existsSync(p)) return p; } catch (e) {}
  }
  return null;
}

function createNestposShortcuts(showNote) {
  if (process.platform !== 'win32') return false;
  try {
    const shortcutOpts = {
      target: process.execPath,
      args: '--pos',
      description: 'NestPOS — POS sale screen (billing, printing, PRA sync)',
      appUserModelId: 'com.taxnest.nestpos',
    };
    const ico = nestposIconPath();
    if (ico) {
      shortcutOpts.icon = ico;
      shortcutOpts.iconIndex = 0;
    }
    const targets = [
      path.join(app.getPath('desktop'), 'NestPOS.lnk'),
      path.join(
        app.getPath('appData'),
        'Microsoft', 'Windows', 'Start Menu', 'Programs', 'NestPOS.lnk'
      ),
    ];
    let ok = false;
    for (const lnk of targets) {
      try {
        if (shell.writeShortcutLink(lnk, 'create', shortcutOpts)) ok = true;
      } catch (e) {
        console.log('[nestpos-shortcut] write failed:', lnk, e && e.message);
      }
    }
    if (ok) {
      store.set('posShortcutCreated', true);
      if (showNote && Notification.isSupported()) {
        new Notification({
          title: 'NestPOS installed',
          body: 'NestPOS icon has been added to your Desktop — next time open the POS straight from there.',
        }).show();
      }
    }
    return ok;
  } catch (e) {
    console.log('[nestpos-shortcut] failed:', e && e.message);
    return false;
  }
}

// Agent AUTO-CONFIG (v1.5.0): pull the company's agent credentials from the
// server using the POS window's logged-in session cookie. Zero manual setup —
// silent printing + PRA sync start working right after the first POS login.
// v1.5.0 beta4 (owner rule): the agent FOLLOWS the POS login — if a DIFFERENT
// company logs into the POS window, the agent swaps to that company's key
// automatically (no manual copy-paste, ever). Same company + same key = no-op;
// same company with a regenerated key also self-heals.
async function autoConfigureAgent(ses, origin) {
  try {
    const existing = store.get('config');
    const res = await ses.fetch(origin + '/pos/desktop/agent-config', {
      headers: { Accept: 'application/json' },
    });
    if (!res.ok) return false;
    const data = await res.json();
    if (!data || !data.success || !data.api_key || !data.company_id) return false;
    if (
      existing && existing.serverUrl && existing.apiKey && existing.companyId &&
      String(existing.companyId) === String(data.company_id) &&
      existing.apiKey === data.api_key
    ) {
      return true; // same company, same key — already configured
    }
    const switching = !!(
      existing && existing.companyId &&
      String(existing.companyId) !== String(data.company_id)
    );
    const config = {
      serverUrl: data.server_url || origin + '/api/agent',
      apiKey: data.api_key,
      companyId: data.company_id,
    };
    store.set('config', config);
    stopAgent();
    startAgent(withAppMeta(config), sendStatusUpdate, handleAgentUpdate);
    console.log(
      '[auto-config] ' +
      (switching ? 'company switch — agent reconfigured' : 'agent configured') +
      ' from POS login (company ' + data.company_id + ')'
    );
    // Company switch is a big deal on fiscal_device shops (the agent is the ONLY
    // PRA submission path) — surface it as an OS notification so staff notice
    // that sync + printing now run for the newly logged-in company.
    try {
      if (switching && Notification.isSupported()) {
        new Notification({
          title: 'TaxNest Agent',
          body: 'Agent ab "' + (data.company_name || ('company ' + data.company_id)) + '" ke liye chal raha hai (PRA sync + printing).',
        }).show();
      }
    } catch (e) {}
    try {
      if (mainWindow && !mainWindow.isDestroyed()) {
        mainWindow.webContents.send('config-autofilled', { companyId: data.company_id });
      }
    } catch (e) {}
    return true;
  } catch (e) {
    console.log('[auto-config] failed:', e && e.message);
    return false;
  }
}

function createWindow(startHidden) {
  mainWindow = new BrowserWindow({
    width: 720,
    height: 720,
    minWidth: 600,
    minHeight: 600,
    title: 'TaxNest PRA Sync Agent',
    icon: path.join(__dirname, 'assets', 'icon.png'),
    show: !startHidden,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
    },
    autoHideMenuBar: true,
  });

  mainWindow.loadFile('index.html');

  mainWindow.on('close', (e) => {
    if (!isQuitting) {
      e.preventDefault();
      mainWindow.hide();
      if (Notification.isSupported()) {
        new Notification({
          title: 'TaxNest Agent',
          body: 'Agent is still running in the system tray. Right-click the icon to quit.',
        }).show();
      }
      return false;
    }
  });
}

function createTray() {
  const iconPath = path.join(__dirname, 'assets', 'tray.png');
  let icon;
  try {
    icon = nativeImage.createFromPath(iconPath);
    if (icon.isEmpty()) icon = nativeImage.createEmpty();
  } catch (e) {
    icon = nativeImage.createEmpty();
  }

  tray = new Tray(icon);
  tray.setToolTip('NestPOS Desktop — TaxNest PRA Sync Agent');

  buildTrayMenu();
  tray.on('click', () => mainWindow && mainWindow.show());
}

function buildTrayMenu() {
  if (!tray) return;
  const posSettings = getPosSettings();
  const contextMenu = Menu.buildFromTemplate([
    {
      label: '🖥️ Open POS Screen',
      click: () => openPos(),
    },
    {
      label: 'Open FBR POS Screen',
      click: () => openFbrPos(),
    },
    {
      label: 'Add NestPOS Icon to Desktop',
      click: () => createNestposShortcuts(true),
    },
    {
      label: 'Kiosk Mode (POS full-screen)',
      type: 'checkbox',
      checked: posSettings.kiosk,
      click: (item) => {
        setPosSettings({ ...getPosSettings(), kiosk: item.checked });
        applyKiosk(item.checked);
      },
    },
    { type: 'separator' },
    {
      label: 'Agent Settings / Status',
      click: () => mainWindow && mainWindow.show(),
    },
    { type: 'separator' },
    {
      label: 'Quit Agent',
      click: () => {
        isQuitting = true;
        stopAgent();
        app.quit();
      },
    },
  ]);

  tray.setContextMenu(contextMenu);
}

// ONE instance only — launching the NestPOS desktop icon (or the agent again)
// while the agent is already running must NOT start a twin agent (two agents
// = double heartbeats + double silent prints). The second launch forwards its
// argv here and exits; we open/focus the POS window or the agent window.
const gotInstanceLock = app.requestSingleInstanceLock();
if (!gotInstanceLock) {
  // Losing instance: quit immediately. ALL startup below lives inside the
  // else-branch so a losing instance can never briefly run a twin agent
  // (createWindow/createTray/startAgent) before quit lands.
  app.quit();
} else {
  app.on('second-instance', (_event, argv) => {
    // whenReady guard: survives the boot race (login-item auto-start + user
    // double-clicks the NestPOS icon before this instance is ready).
    app.whenReady().then(() => {
      try {
        if ((argv || []).includes('--pos')) {
          openPos();
          return;
        }
        if (mainWindow && !mainWindow.isDestroyed()) {
          mainWindow.show();
          mainWindow.focus();
        }
      } catch (e) {}
    });
  });

  app.whenReady().then(() => {
    // Agent windows keep the agent identity; the POS window overrides its own
    // AppUserModelID in pos-window.js so NestPOS groups separately on the taskbar.
    try { app.setAppUserModelId('com.taxnest.pra-agent'); } catch (e) {}

    // Belt & braces for the CWD-lock trap: whatever directory we were launched
    // from (an older updater script used to start us inside its temp workDir),
    // move to the install dir so this process never holds a temp dir hostage.
    try { process.chdir(path.dirname(process.execPath)); } catch (e) {}

    // Offline Mode telemetry: every heartbeat reports whether NestPOS Desktop
    // Offline Mode is ON and when the sale-screen snapshot was last captured,
    // so admins can spot stale snapshots remotely. Read lazily each beat (the
    // toggle can change at runtime); any failure = fields simply omitted.
    setHeartbeatExtraProvider(() => {
      const out = { offline_mode: getPosSettings().offlineMode ? 1 : 0 };
      try {
        const info = offlineSnapshot.snapshotInfo();
        out.snapshot_saved_at = info && info.savedAt ? info.savedAt : null;
      } catch (e) {
        out.snapshot_saved_at = null;
      }
      // Self-update telemetry (Task 1062): only sent AFTER an attempt happened
      // this run — absent fields must never wipe server-stored values.
      if (lastUpdateAttempt && lastUpdateAttempt.target) {
        out.update_target = lastUpdateAttempt.target;
        out.update_stage = lastUpdateAttempt.stage || null;
        out.update_error = lastUpdateAttempt.error || null;
        out.update_attempted_at = lastUpdateAttempt.at || null;
      }
      return out;
    });

    // LAN Mode: hand the agent a way to reach the local server, so rings the
    // Caller ID phone could only deliver here (internet down) are forwarded to
    // the cloud on the first beat that gets through. Lazy — creating the
    // instance does NOT start a listener; LAN mode still has to be switched on.
    setLanBridge(() => (lanServer ? lanServer : null));

    // Wake triggers (Task 1062): after PC sleep/resume or a Wi-Fi drop the
    // agent used to sit "Offline" until the next timer tick happened to
    // succeed. Fire an immediate beat + sync on power resume, screen unlock
    // and network reconnect so the badge recovers within seconds. powerMonitor
    // is only usable after app ready. Failures here must never touch the agent.
    try {
      const { powerMonitor, net } = require('electron');
      powerMonitor.on('resume', () => { try { wakeAgent('power-resume'); } catch (e) {} });
      powerMonitor.on('unlock-screen', () => { try { wakeAgent('screen-unlock'); } catch (e) {} });
      // No network 'online' event in the main process — poll the OS
      // connectivity flag and fire on the offline→online transition.
      let wasOnline = true;
      try { wasOnline = net.isOnline(); } catch (e) {}
      setInterval(() => {
        let onlineNow;
        try { onlineNow = net.isOnline(); } catch (e) { return; }
        if (onlineNow && !wasOnline) {
          try { wakeAgent('network-reconnect'); } catch (e) {}
        }
        wasOnline = onlineNow;
      }, 10000);
    } catch (e) {
      console.log('[wake-triggers] setup failed:', e && e.message);
    }

    // Launched via the NestPOS desktop icon (--pos): go straight to the POS
    // screen, keep the agent window hidden in the tray.
    const posLaunch = process.argv.includes('--pos');

    createWindow(posLaunch);
    createTray();

    app.setLoginItemSettings({
      openAtLogin: true,
      openAsHidden: true,
    });

    const config = store.get('config');
    if (config && config.serverUrl && config.apiKey && config.companyId) {
      startAgent(withAppMeta(config), sendStatusUpdate, handleAgentUpdate);
    }

    // NestPOS Desktop: open the POS screen when launched with --pos (desktop
    // icon) or when "open on startup" is ticked (shop PCs).
    // try/catch + additive design — a shell failure must never touch the agent.
    try {
      if (posLaunch || getPosSettings().openOnStartup) {
        const opened = openPos();
        // Keep the shop PC clean: POS front and center, agent window in tray.
        if (opened && mainWindow) mainWindow.hide();
      }
    } catch (e) {
      console.log('[pos-window] startup open failed:', e && e.message);
    }

    // LAN Mode: bring the shop's local server up if the owner switched it on.
    // Additive + guarded — a LAN failure must never disturb PRA sync.
    try {
      if (getLanSettings().enabled) {
        applyLanSettings().then((st) => {
          console.log('[lan] ' + (st.running ? 'ready on ' + st.urls.join(', ') : 'NOT running: ' + (st.error || 'unknown')));
        });
      }
    } catch (e) {
      console.log('[lan] startup failed:', e && e.message);
    }
  });
}

// Per-counter printer routing (v1.9.0, Task 1166): every install identifies
// itself with a persistent random device UID (generated once, stored in the
// same electron-store as the config) + the PC hostname. Multi-counter shops
// run the SAME company key on several PCs — the UID is what tells the server
// which counter is which, so each cashier's bills print on their own counter.
function getDeviceUid() {
  let uid = store.get('deviceUid');
  if (!uid || typeof uid !== 'string') {
    const rand = require('crypto').randomBytes(12).toString('hex');
    uid = 'dev-' + rand;
    store.set('deviceUid', uid);
    console.log('[device-id] generated persistent device UID:', uid);
  }
  return uid;
}

// Attach the real app version/build so heartbeats report them to the server
// (the server piggybacks `agent_update` info on the heartbeat response).
function withAppMeta(config) {
  return {
    ...config,
    appVersion: app.getVersion(),
    appBuild: BUILD_TIMESTAMP,
    deviceUid: getDeviceUid(),
    hostname: (() => { try { return os.hostname(); } catch (e) { return null; } })(),
    // PC Name (v1.9.0): shopkeeper-entered friendly label; empty = not set.
    pcName: (config && config.pcName) ? String(config.pcName).trim() : '',
  };
}

app.on('window-all-closed', (e) => {
  e.preventDefault();
});

app.on('before-quit', () => {
  isQuitting = true;
  stopAgent();
  // Free the LAN port so a restart (or an update swap) can bind it again.
  try { if (lanServer) lanServer.stop(); } catch (e) {}
});

function sendStatusUpdate(status) {
  if (mainWindow && !mainWindow.isDestroyed()) {
    mainWindow.webContents.send('status-update', status);
  }
}

ipcMain.handle('get-config', () => {
  const cfg = store.get('config') || {};
  // Always surface pcName and receiptPrinter so the UI fields load correctly.
  return { ...cfg, pcName: cfg.pcName || '', receiptPrinter: cfg.receiptPrinter || '' };
});

ipcMain.handle('save-config', async (event, config) => {
  // Persist pcName + receiptPrinter alongside the other config fields.
  const toStore = {
    ...config,
    pcName: (config.pcName || '').trim(),
    receiptPrinter: (config.receiptPrinter || '').trim(),
  };
  store.set('config', toStore);
  stopAgent();
  startAgent(withAppMeta(toStore), sendStatusUpdate, handleAgentUpdate);

  // Task 1187: if the shopkeeper explicitly chose a real (non-virtual) printer,
  // activate silent receipt printing on the server right now so the very next
  // bill prints silently — no extra step on the Printer Settings page required.
  // blank/virtual = printerExplicit is false, so we skip and never touch the
  // server's existing setting (precedence rule: only a deliberate new pick wins).
  let printerActivated = false;
  if (config.printerExplicit && toStore.receiptPrinter && toStore.serverUrl && toStore.apiKey) {
    try {
      const resp = await axios.post(
        `${toStore.serverUrl}/device-printer`,
        {
          receipt_printer: toStore.receiptPrinter,
          explicit: true,
          device_uid: getDeviceUid(),
          hostname: (() => { try { return os.hostname(); } catch (e) { return null; } })(),
        },
        { headers: { Authorization: `Bearer ${toStore.apiKey}` }, timeout: 10000 }
      );
      printerActivated = !!(resp.data && resp.data.silent_print_enabled);
    } catch (e) {
      console.log('[save-config] printer activation failed (non-fatal):', e && e.message);
    }
  }

  return { ok: true, printerActivated };
});

// Task 1187: enumerate this PC's installed printers for the setup-form
// Receipt Printer dropdown. Called on form load + manual refresh.
ipcMain.handle('get-printers', async () => {
  try {
    const printers = await getLocalPrinters();
    return { ok: true, printers };
  } catch (e) {
    return { ok: false, printers: [], error: e && e.message };
  }
});

ipcMain.handle('get-status', () => {
  return getStatus();
});

ipcMain.handle('toggle-agent', (event, enabled) => {
  if (enabled) {
    const config = store.get('config');
    if (config && config.serverUrl && config.apiKey && config.companyId) {
      startAgent(withAppMeta(config), sendStatusUpdate, handleAgentUpdate);
      return { ok: true, running: true };
    }
    return { ok: false, error: 'Configure agent first' };
  } else {
    stopAgent();
    return { ok: true, running: false };
  }
});

ipcMain.handle('check-update', async () => {
  // Updates are server-driven now: every heartbeat (30s) carries the latest
  // release info and a newer version installs itself automatically.
  return updateInfo || { available: false, currentBuild: BUILD_TIMESTAMP };
});

ipcMain.handle('open-download', async () => {
  await shell.openExternal(DOWNLOAD_URL);
  return { ok: true };
});

ipcMain.handle('install-update-now', async () => {
  // Self-update installs automatically as soon as it is downloaded.
  return { ok: false, error: 'Updates now install automatically — nothing to do.' };
});

ipcMain.handle('get-version', () => ({ build: BUILD_TIMESTAMP, version: app.getVersion() }));

// ─── NestPOS Desktop (POS screen shell) IPC ─────────────────────────────────
ipcMain.handle('get-pos-settings', () => getPosSettings());

ipcMain.handle('save-pos-settings', (event, s) => {
  setPosSettings(s || {});
  applyKiosk(!!(s && s.kiosk));
  buildTrayMenu();
  return { ok: true };
});

ipcMain.handle('open-pos-window', () => ({ ok: openPos() }));

// ─── NestPOS LAN Mode IPC ───────────────────────────────────────────────────
ipcMain.handle('get-lan-settings', () => getLanSettings());

ipcMain.handle('save-lan-settings', async (event, s) => {
  setLanSettings(s || {});
  return await applyLanSettings();
});

ipcMain.handle('get-lan-status', () => {
  const st = lanInstance().status();
  return { ...st, enabled: getLanSettings().enabled };
});

ipcMain.handle('lan-forget-devices', () => {
  lanInstance().forgetDevices();
  return lanInstance().status();
});

// Silent-print bridge for the POS window (window.nestposDesktop.printHtml).
// Accepts calls ONLY from the POS window itself — a stray/child page can
// never feed paper to the shop printer.
ipcMain.handle('pos-print-html', async (event, html, deviceName) => {
  const pw = getPosWindowRef();
  if (!pw || event.sender !== pw.webContents) {
    return { success: false, error: 'unauthorized' };
  }
  if (!html || typeof html !== 'string') {
    return { success: false, error: 'empty html' };
  }
  try {
    return await printHtmlSilent(html, deviceName || undefined);
  } catch (e) {
    return { success: false, error: (e && e.message) || 'print failed' };
  }
});

// ─── FBR IMS Fiscal Service helper ──────────────────────────────────────────
// FBRIMS is FBR's OWN software (runs as a separate service on localhost:8524).
// We cannot merge it into this app, but we CAN detect it, download it from
// FBR's official server, and launch its installer — one-stop setup for shops.
const IMS_DOWNLOAD_URL = 'https://download.fbr.gov.pk/IMS_Setup/FBRIMS.zip';

function sendImsProgress(payload) {
  if (mainWindow && !mainWindow.isDestroyed()) {
    mainWindow.webContents.send('ims-progress', payload);
  }
}

ipcMain.handle('check-ims-service', async () => {
  try {
    // Any HTTP response (even 404) means the IMS service is listening.
    await axios.get('http://localhost:8524/', { timeout: 3000, validateStatus: () => true });
    return { running: true };
  } catch (e) {
    return { running: false, error: e.code || e.message };
  }
});

ipcMain.handle('install-fbr-ims', async () => {
  if (process.platform !== 'win32') {
    return { ok: false, error: 'FBR IMS software only runs on Windows. Use the shop\'s Windows PC.' };
  }
  const os = require('os');
  const fs = require('fs');
  const { spawn } = require('child_process');
  const tmpDir = path.join(os.tmpdir(), 'taxnest-fbrims');
  const zipPath = path.join(tmpDir, 'FBRIMS.zip');
  const extractDir = path.join(tmpDir, 'extracted');
  try {
    fs.mkdirSync(tmpDir, { recursive: true });
    sendImsProgress({ stage: 'download', percent: 0, message: 'Downloading FBRIMS.zip from FBR...' });
    // timeout here = connection/response-start timeout; stalled streams are aborted below.
    const res = await axios.get(IMS_DOWNLOAD_URL, { responseType: 'stream', timeout: 60000 });
    const total = parseInt(res.headers['content-length'] || '0', 10);
    let done = 0;
    await new Promise((resolve, reject) => {
      const out = fs.createWriteStream(zipPath);
      let idleTimer = null;
      const resetIdle = () => {
        if (idleTimer) clearTimeout(idleTimer);
        idleTimer = setTimeout(() => {
          res.data.destroy(new Error('Download stalled (no data for 60s). Check the internet connection and try again.'));
        }, 60000);
      };
      resetIdle();
      res.data.on('data', (chunk) => {
        done += chunk.length;
        resetIdle();
        if (total) sendImsProgress({ stage: 'download', percent: Math.round((done / total) * 100) });
      });
      const fail = (err) => { if (idleTimer) clearTimeout(idleTimer); reject(err); };
      res.data.on('error', fail);
      out.on('error', fail);
      out.on('finish', () => { if (idleTimer) clearTimeout(idleTimer); resolve(); });
      res.data.pipe(out);
    });
    sendImsProgress({ stage: 'extract', message: 'Extracting FBR installer...' });
    fs.rmSync(extractDir, { recursive: true, force: true });
    fs.mkdirSync(extractDir, { recursive: true });
    await new Promise((resolve, reject) => {
      // Single-quoted PowerShell strings = no $-expansion; ' escaped by doubling.
      const psq = (s) => `'${String(s).replace(/'/g, "''")}'`;
      const ps = spawn('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command',
        `Expand-Archive -LiteralPath ${psq(zipPath)} -DestinationPath ${psq(extractDir)} -Force`], { windowsHide: true });
      let err = '';
      ps.stderr.on('data', (d) => { err += d.toString(); });
      ps.on('close', (code) => (code === 0 ? resolve() : reject(new Error(err || `Expand-Archive exited ${code}`))));
      ps.on('error', reject);
    });
    const found = [];
    const walk = (dir, depth) => {
      if (depth > 3) return;
      for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(p, depth + 1);
        else if (/\.(exe|msi)$/i.test(entry.name)) found.push(p);
      }
    };
    walk(extractDir, 0);
    const setup = found.find((f) => /setup|install/i.test(path.basename(f))) || found[0] || null;
    sendImsProgress({ stage: 'launch', message: setup ? 'Launching FBR installer...' : 'Opening extracted folder...' });
    if (setup) {
      await shell.openPath(setup);
    }
    shell.openPath(extractDir);
    return { ok: true, installer: setup, folder: extractDir };
  } catch (e) {
    sendImsProgress({ stage: 'error', message: e.message });
    return { ok: false, error: e.message };
  }
});

ipcMain.handle('test-connection', async (event, config) => {
  const axios = require('axios');
  try {
    const res = await axios.post(
      `${config.serverUrl}/heartbeat`,
      { version: app.getVersion(), company_id: config.companyId },
      {
        headers: { Authorization: `Bearer ${config.apiKey}` },
        timeout: 10000,
      }
    );
    return { ok: true, data: res.data };
  } catch (e) {
    return {
      ok: false,
      error: e.response?.data?.error || e.message,
      status: e.response?.status,
    };
  }
});
