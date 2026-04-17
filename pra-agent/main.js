const { app, BrowserWindow, Tray, Menu, ipcMain, Notification, nativeImage } = require('electron');
const path = require('path');
const Store = require('electron-store');
const { startAgent, stopAgent, getStatus } = require('./src/agent');

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
