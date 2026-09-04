const assert = require('assert');
const fs = require('fs');
const path = require('path');

const main = fs.readFileSync(path.join(__dirname, '..', 'main.js'), 'utf8');
const preload = fs.readFileSync(path.join(__dirname, '..', 'preload.js'), 'utf8');
const page = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');

const getStart = main.indexOf("ipcMain.handle('get-config'");
const saveStart = main.indexOf("ipcMain.handle('save-config'");
const testStart = main.indexOf("ipcMain.handle('test-connection'");

assert(getStart >= 0 && saveStart > getStart && testStart > saveStart);

const getBlock = main.slice(getStart, saveStart);
const saveBlock = main.slice(saveStart, main.indexOf("ipcMain.handle('get-status'", saveStart));
const testBlock = main.slice(testStart);

assert(getBlock.includes('getMigratedAgentConfig()'));
assert(saveBlock.includes('canonicalAgentServerUrl(config.serverUrl || DEFAULT_SERVER_URL)'));
assert(testBlock.includes('canonicalAgentServerUrl(config.serverUrl || DEFAULT_SERVER_URL)'));
assert(main.includes('const normalized = migrateAgentConfig(config).config || config || {}'));
assert(preload.includes('onConfigAutofilled'));
assert(page.includes('window.agentAPI.onConfigAutofilled'));
assert(page.includes('await loadConfig()'));

console.log('config domain boundary tests passed');