const { app, BrowserWindow, Tray, Menu, ipcMain, Notification, nativeImage, shell, dialog } = require('electron');
const path = require('path');
const axios = require('axios');
const Store = require('electron-store');
const { autoUpdater } = require('electron-updater');
const { startAgent, stopAgent, getStatus } = require('./src/agent');

const DOWNLOAD_URL = 'https://github.com/jawadrao5555-alt/taxnest/releases/latest';
const BUILD_TIMESTAMP = '20260717-1';
let updateInfo = { available: false, currentBuild: BUILD_TIMESTAMP };

autoUpdater.autoDownload = true;
autoUpdater.autoInstallOnAppQuit = true;
autoUpdater.allowPrerelease = false;
autoUpdater.logger = { info: (m) => console.log('[updater]', m), warn: (m) => console.log('[updater warn]', m), error: (m) => console.log('[updater err]', m), debug: () => {} };

autoUpdater.on('checking-for-update', () => {
  updateInfo = { ...updateInfo, checking: true };
  sendUpdateState();
});

autoUpdater.on('update-available', (info) => {
  updateInfo = {
    available: true,
    checking: false,
    downloading: true,
    latestBuild: info.version,
    currentBuild: BUILD_TIMESTAMP,
    downloadUrl: DOWNLOAD_URL,
    progress: 0,
  };
  sendUpdateState();
  console.log('[updater] Update found, downloading silently in background:', info.version);
});

autoUpdater.on('update-not-available', () => {
  updateInfo = { available: false, checking: false, currentBuild: BUILD_TIMESTAMP };
  sendUpdateState();
});

autoUpdater.on('download-progress', (p) => {
  updateInfo = { ...updateInfo, downloading: true, progress: Math.round(p.percent || 0) };
  sendUpdateState();
});

autoUpdater.on('update-downloaded', (info) => {
  updateInfo = {
    available: true,
    checking: false,
    downloading: false,
    downloaded: true,
    latestBuild: info.version,
    currentBuild: BUILD_TIMESTAMP,
    progress: 100,
  };
  sendUpdateState();
  console.log('[updater] Update downloaded silently. Will install on next app quit:', info.version);
  if (tray) {
    try { tray.setToolTip(`TaxNest PRA Sync Agent — v${info.version} ready (auto-installs on next start)`); } catch (e) {}
  }
});

autoUpdater.on('error', (err) => {
  console.log('[updater] error:', err && err.message);
  updateInfo = { ...updateInfo, checking: false, downloading: false, error: (err && err.message) || 'Update failed' };
  sendUpdateState();
});

function sendUpdateState() {
  if (mainWindow && !mainWindow.isDestroyed()) {
    mainWindow.webContents.send('update-available', updateInfo);
  }
}

async function checkForUpdates() {
  try {
    if (!app.isPackaged) {
      console.log('[updater] skipped: not packaged');
      return;
    }
    await autoUpdater.checkForUpdates();
  } catch (e) {
    console.log('Update check failed:', e.message);
  }
}

const store = new Store();
let mainWindow = null;
let tray = null;
let isQuitting = false;

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
  tray.setToolTip('TaxNest PRA Sync Agent');

  const contextMenu = Menu.buildFromTemplate([
    {
      label: 'Show Window',
      click: () => mainWindow && mainWindow.show(),
    },
    {
      label: 'Status',
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
  tray.on('click', () => mainWindow && mainWindow.show());
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
    startAgent(config, sendStatusUpdate);
  }

  setTimeout(checkForUpdates, 5000);
  setInterval(checkForUpdates, 30 * 60 * 1000);
});

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
  startAgent(config, sendStatusUpdate);
  return { ok: true };
});

ipcMain.handle('get-status', () => {
  return getStatus();
});

ipcMain.handle('toggle-agent', (event, enabled) => {
  if (enabled) {
    const config = store.get('config');
    if (config && config.serverUrl && config.apiKey && config.companyId) {
      startAgent(config, sendStatusUpdate);
      return { ok: true, running: true };
    }
    return { ok: false, error: 'Configure agent first' };
  } else {
    stopAgent();
    return { ok: true, running: false };
  }
});

ipcMain.handle('check-update', async () => {
  await checkForUpdates();
  return updateInfo || { available: false, currentBuild: BUILD_TIMESTAMP };
});

ipcMain.handle('open-download', async () => {
  await shell.openExternal(DOWNLOAD_URL);
  return { ok: true };
});

ipcMain.handle('install-update-now', async () => {
  if (updateInfo && updateInfo.downloaded) {
    isQuitting = true;
    stopAgent();
    autoUpdater.quitAndInstall(false, true);
    return { ok: true };
  }
  return { ok: false, error: 'No update downloaded yet' };
});

ipcMain.handle('get-version', () => ({ build: BUILD_TIMESTAMP, version: app.getVersion() }));

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
