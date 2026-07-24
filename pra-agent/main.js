const { app, BrowserWindow, Tray, Menu, ipcMain, Notification, nativeImage, shell, dialog } = require('electron');
const path = require('path');
const fs = require('fs');
const os = require('os');
const { spawn } = require('child_process');
const axios = require('axios');
const Store = require('electron-store');
const { startAgent, stopAgent, getStatus } = require('./src/agent');
const { printHtml: printHtmlSilent } = require('./src/printer');
const { openPosWindow, getPosWindowRef, isPosWindowOpen, applyKiosk } = require('./src/pos-window');

const DOWNLOAD_URL = 'https://github.com/jawadrao5555-alt/taxnest/releases/latest';
const BUILD_TIMESTAMP = '20260724-2';
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
const attemptedVersions = new Set();

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
  try {
    if (!info || !info.version || !info.zip_url) return;
    // Host pin: only ever download update zips from our own GitHub releases.
    // (No code-signing available, so a compromised/misconfigured server must
    // not be able to point agents at an arbitrary zip.)
    if (!String(info.zip_url).startsWith('https://github.com/jawadrao5555-alt/taxnest/releases/download/')) {
      console.log(`[self-update] REJECTED zip_url outside trusted release host: ${info.zip_url}`);
      return;
    }
    if (process.platform !== 'win32' || !app.isPackaged) return;
    if (updateInProgress) return;
    if (!isNewerVersion(info.version, app.getVersion())) return;
    // One attempt per version per app run — a bad zip can never cause a
    // download/restart loop.
    if (attemptedVersions.has(info.version)) return;
    attemptedVersions.add(info.version);
    updateInProgress = true;

    console.log(`[self-update] v${app.getVersion()} -> v${info.version} — downloading ${info.zip_url}`);
    updateInfo = {
      available: true,
      downloading: true,
      latestBuild: info.version,
      currentBuild: BUILD_TIMESTAMP,
      downloadUrl: DOWNLOAD_URL,
      progress: 0,
    };
    sendUpdateState();
    if (tray) {
      try { tray.setToolTip(`TaxNest PRA Sync Agent — updating to v${info.version}…`); } catch (e) {}
    }

    const workDir = path.join(os.tmpdir(), 'taxnest-agent-update');
    fs.rmSync(workDir, { recursive: true, force: true });
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

    const gotSize = fs.statSync(zipPath).size;
    if (info.zip_size && gotSize !== info.zip_size) {
      throw new Error(`Downloaded size ${gotSize} != expected ${info.zip_size}`);
    }

    // Extract with PowerShell — zero extra npm dependencies.
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

    const destDir = path.dirname(process.execPath);
    const cmdPath = path.join(workDir, 'apply-update.cmd');
    // NOTE: no parenthesized if-blocks around %RETRIES% — plain %VAR% expansion
    // inside ( ) reads the stale value (classic batch pitfall).
    const script = [
      '@echo off',
      'timeout /t 3 /nobreak >nul',
      `taskkill /F /IM "${exeName}" >nul 2>&1`,
      'timeout /t 2 /nobreak >nul',
      'set RETRIES=0',
      ':copyloop',
      `robocopy "${srcDir}" "${destDir}" /E /R:5 /W:2 >nul`,
      'if %ERRORLEVEL% LSS 8 goto copydone',
      'set /a RETRIES+=1',
      'if %RETRIES% GEQ 5 goto copydone',
      'timeout /t 3 /nobreak >nul',
      'goto copyloop',
      ':copydone',
      `start "" "${path.join(destDir, exeName)}"`,
      'exit',
    ].join('\r\n');
    fs.writeFileSync(cmdPath, script);

    console.log('[self-update] handing off to updater script, quitting…');
    updateInfo = { ...updateInfo, downloading: false, downloaded: true, progress: 100 };
    sendUpdateState();

    const child = spawn('cmd.exe', ['/c', cmdPath], { detached: true, stdio: 'ignore', windowsHide: true, cwd: workDir });
    child.unref();
    isQuitting = true;
    stopAgent();
    setTimeout(() => app.quit(), 500);
  } catch (e) {
    console.log('[self-update] failed:', e && e.message);
    updateInfo = { ...updateInfo, downloading: false, error: (e && e.message) || 'Update failed' };
    sendUpdateState();
    // Allow a FUTURE version to retry; this same version stays blocked for
    // this run via attemptedVersions.
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

function openPos() {
  try {
    const config = store.get('config');
    if (!config || !config.serverUrl) {
      // Not configured yet — show the agent settings window instead.
      if (mainWindow) mainWindow.show();
      return false;
    }
    const s = getPosSettings();
    openPosWindow(config, {
      kiosk: s.kiosk,
      isOfflineEnabled: () => getPosSettings().offlineMode,
      onKioskToggle: (kioskNow) => {
        setPosSettings({ ...getPosSettings(), kiosk: kioskNow });
        buildTrayMenu();
      },
    });
    return true;
  } catch (e) {
    console.log('[pos-window] open failed:', e && e.message);
    return false;
  }
}

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 720,
    height: 720,
    minWidth: 600,
    minHeight: 600,
    title: 'TaxNest PRA Sync Agent',
    icon: path.join(__dirname, 'assets', 'icon.png'),
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

app.whenReady().then(() => {
  createWindow();
  createTray();

  app.setLoginItemSettings({
    openAtLogin: true,
    openAsHidden: true,
  });

  const config = store.get('config');
  if (config && config.serverUrl && config.apiKey && config.companyId) {
    startAgent(withAppMeta(config), sendStatusUpdate, handleAgentUpdate);
  }

  // NestPOS Desktop: optionally open the POS screen on startup (shop PCs).
  // try/catch + additive design — a shell failure must never touch the agent.
  try {
    if (getPosSettings().openOnStartup) {
      const opened = openPos();
      // Keep the shop PC clean: POS front and center, agent window in tray.
      if (opened && mainWindow) mainWindow.hide();
    }
  } catch (e) {
    console.log('[pos-window] startup open failed:', e && e.message);
  }
});

// Attach the real app version/build so heartbeats report them to the server
// (the server piggybacks `agent_update` info on the heartbeat response).
function withAppMeta(config) {
  return { ...config, appVersion: app.getVersion(), appBuild: BUILD_TIMESTAMP };
}

app.on('window-all-closed', (e) => {
  e.preventDefault();
});

app.on('before-quit', () => {
  isQuitting = true;
  stopAgent();
});

function sendStatusUpdate(status) {
  if (mainWindow && !mainWindow.isDestroyed()) {
    mainWindow.webContents.send('status-update', status);
  }
}

ipcMain.handle('get-config', () => {
  return store.get('config') || {};
});

ipcMain.handle('save-config', (event, config) => {
  store.set('config', config);
  stopAgent();
  startAgent(withAppMeta(config), sendStatusUpdate, handleAgentUpdate);
  return { ok: true };
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
