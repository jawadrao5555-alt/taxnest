const { app, BrowserWindow, Tray, Menu, ipcMain, Notification, nativeImage, shell } = require('electron');
const path = require('path');
const axios = require('axios');
const Store = require('electron-store');
const { startAgent, stopAgent, getStatus } = require('./src/agent');

const UPDATE_FEED_URL = 'https://api.github.com/repos/jawadrao5555-alt/taxnest/releases/latest';
const DOWNLOAD_URL = 'https://github.com/jawadrao5555-alt/taxnest/releases/latest';
const BUILD_TIMESTAMP = '20260418-5';
let updateInfo = null;

async function checkForUpdates() {
  try {
    const res = await axios.get(UPDATE_FEED_URL, { timeout: 15000 });
    const remoteTag = res.data?.body || '';
    const match = remoteTag.match(/build:\s*([0-9\-A-Za-z]+)/);
    const remoteBuild = match ? match[1] : null;
    if (remoteBuild && remoteBuild !== BUILD_TIMESTAMP) {
      updateInfo = {
        available: true,
        latestBuild: remoteBuild,
        currentBuild: BUILD_TIMESTAMP,
        downloadUrl: DOWNLOAD_URL,
      };
      if (mainWindow && !mainWindow.isDestroyed()) {
        mainWindow.webContents.send('update-available', updateInfo);
      }
      if (Notification.isSupported()) {
        new Notification({
          title: 'TaxNest Agent: Update Available',
          body: `New version ${remoteBuild} is available. Open the agent window to download.`,
        }).show();
      }
    } else {
      updateInfo = { available: false, currentBuild: BUILD_TIMESTAMP };
    }
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
  setInterval(checkForUpdates, 60 * 60 * 1000);
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
