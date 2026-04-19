const { app, BrowserWindow, Tray, Menu, ipcMain, Notification, nativeImage, shell, dialog } = require('electron');
const path = require('path');
const axios = require('axios');
const Store = require('electron-store');
const { autoUpdater } = require('electron-updater');
const { startAgent, stopAgent, getStatus } = require('./src/agent');

const DOWNLOAD_URL = 'https://github.com/jawadrao5555-alt/taxnest/releases/latest';
const BUILD_TIMESTAMP = '20260418-6';
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
