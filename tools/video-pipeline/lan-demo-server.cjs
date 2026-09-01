'use strict';
/*!
 * LAN Mode recording harness (tutorial video only — never shipped to shops).
 *
 * WHY THIS EXISTS
 * The LAN Mode tutorial has to show two screens that the normal recorder
 * cannot reach:
 *   1. the AGENT WINDOW (pra-agent/index.html), which is an Electron page and
 *      dies instantly in a plain browser because `window.agentAPI` (the
 *      preload IPC bridge) does not exist there; and
 *   2. the PAIRING PAGE a waiter tablet / Caller ID phone actually opens.
 *
 * Rather than mock up look-alike screens — which would teach shops a UI that
 * does not exist — this harness runs the REAL `src/lan-server.js` in-process
 * (it is deliberately pure Node, no Electron) and serves the REAL agent
 * index.html with a thin `agentAPI` shim whose LAN methods are wired straight
 * to that live server. So the pairing code on the "agent window" is the code
 * the server actually minted, pairing from the "tablet" really pairs, and the
 * device then really appears in the agent window's device list.
 *
 * Everything non-LAN in the shim (sync counters, printers, config) is static
 * demo data for a fictional shop — those cards are only ever on screen as
 * background while we scroll to the LAN card.
 *
 * Usage:  node tools/video-pipeline/lan-demo-server.cjs [agentPort] [lanPort]
 *         → agent window at http://127.0.0.1:8600/agent
 *         → LAN pairing page at http://127.0.0.1:8531/
 */

const http = require('http');
const fs = require('fs');
const path = require('path');
const { createLanServer } = require('../../pra-agent/src/lan-server');

const AGENT_PORT = parseInt(process.argv[2], 10) || 8600;
const LAN_PORT = parseInt(process.argv[3], 10) || 8531;
const AGENT_HTML = path.join(__dirname, '..', '..', 'pra-agent', 'index.html');
const DATA_DIR = path.join(__dirname, 'out', '.landemo');

// A recording must always start from "no device has ever paired here".
fs.rmSync(DATA_DIR, { recursive: true, force: true });
fs.mkdirSync(DATA_DIR, { recursive: true });

let lanEnabled = false;
let lanPort = LAN_PORT;

const lan = createLanServer({
    dataDir: DATA_DIR,
    port: LAN_PORT,
    version: require('../../pra-agent/package.json').version,
    // The recorded POS runs behind the pipeline's TLS proxy; the counter's
    // loopback caller lane has to be allowed to read from it exactly as it
    // would on a real shop PC.
    allowedOrigins: ['https://127.0.0.1:5443', 'http://127.0.0.1:5000'],
    isEnabled: () => lanEnabled,
    log: (m) => console.log('[lan]', m),
});

/* ------------------------------------------------------------ agent shim */

const SHIM = `<script>
(function () {
  var j = function (u, o) { return fetch(u, o).then(function (r) { return r.json(); }); };
  var post = function (u, b) {
    return j(u, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b || {}) });
  };
  var STATUS = {
    running: true, connected: true,
    pendingCount: 0, submittedCount: 24, failedCount: 0,
    lastSync: Date.now(),
    serverInfo: { name: 'Al-Noor General Store', pra_pos_id: '900312', pra_environment: 'production' },
    lastError: null,
  };
  window.agentAPI = {
    getConfig: function () {
      return Promise.resolve({
        serverUrl: 'https://taxnest.pk', companyId: '9904',
        apiKey: '****************', pcName: 'COUNTER-PC',
        receiptPrinter: 'EPSON TM-T82 Receipt',
      });
    },
    saveConfig: function () { return Promise.resolve({ ok: true }); },
    getStatus: function () { return Promise.resolve(STATUS); },
    toggleAgent: function () { return Promise.resolve(STATUS); },
    testConnection: function () { return Promise.resolve({ success: true, company: 'Al-Noor General Store' }); },
    onStatusUpdate: function () {},
    checkUpdate: function () { return Promise.resolve({ available: false }); },
    openDownload: function () { return Promise.resolve(); },
    getVersion: function () { return Promise.resolve('__VERSION__'); },
    onUpdateAvailable: function () {},
    checkImsService: function () { return Promise.resolve({ running: false, checked: true }); },
    installFbrIms: function () { return Promise.resolve({ ok: false }); },
    onImsProgress: function () {},
    getPosSettings: function () { return Promise.resolve({ enabled: true, offline: true, kiosk: false }); },
    savePosSettings: function () { return Promise.resolve({ ok: true }); },
    openPosWindow: function () { return Promise.resolve(); },
    getPrinters: function () {
      return Promise.resolve({ printers: [
        { name: 'EPSON TM-T82 Receipt', displayName: 'EPSON TM-T82 Receipt', isDefault: true },
        { name: 'Microsoft Print to PDF', displayName: 'Microsoft Print to PDF' },
      ] });
    },
    /* --- LAN: wired to the real in-process server, nothing faked --- */
    getLanSettings: function () { return j('/demo/lan/settings'); },
    saveLanSettings: function (s) { return post('/demo/lan/settings', s); },
    getLanStatus: function () { return j('/demo/lan/status'); },
    lanForgetDevices: function () { return post('/demo/lan/forget'); },
    lanListDevices: function () { return j('/demo/lan/devices'); },
    lanRemoveDevice: function (id) { return post('/demo/lan/remove', { id: id }); },
  };
})();
</script>`;

function agentPage() {
    const html = fs.readFileSync(AGENT_HTML, 'utf8');
    const shim = SHIM.replace('__VERSION__', require('../../pra-agent/package.json').version);
    // The page's own script is the last thing in <body>, so a <head> injection
    // is guaranteed to define agentAPI before anything touches it.
    return html.replace('<head>', '<head>\n' + shim);
}

/* ------------------------------------------------------------ control API */

function json(res, code, body) {
    const s = JSON.stringify(body);
    res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
    res.end(s);
}

function readBody(req) {
    return new Promise((resolve) => {
        const chunks = [];
        req.on('data', (c) => chunks.push(c));
        req.on('end', () => {
            const raw = Buffer.concat(chunks).toString('utf8');
            if (!raw) { resolve({}); return; }
            try { resolve(JSON.parse(raw)); } catch (e) { resolve({}); }
        });
    });
}

const server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1');
    const p = url.pathname;

    if (p === '/agent' || p === '/' || p === '/index.html') {
        const html = agentPage();
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        res.end(html);
        return;
    }
    if (p === '/demo/lan/settings' && req.method === 'GET') {
        json(res, 200, { enabled: lanEnabled, port: lanPort });
        return;
    }
    if (p === '/demo/lan/settings' && req.method === 'POST') {
        const b = await readBody(req);
        lanEnabled = !!b.enabled;
        lanPort = parseInt(b.port, 10) || lanPort;
        if (lanEnabled) { await lan.start(lanPort); } else { await lan.stop(); }
        json(res, 200, Object.assign({ enabled: lanEnabled }, lan.status()));
        return;
    }
    if (p === '/demo/lan/status') {
        json(res, 200, Object.assign({ enabled: lanEnabled }, lan.status()));
        return;
    }
    if (p === '/demo/lan/devices') {
        json(res, 200, lan.listDevices());
        return;
    }
    if (p === '/demo/lan/forget' && req.method === 'POST') {
        lan.forgetDevices();
        json(res, 200, Object.assign({ enabled: lanEnabled }, lan.status()));
        return;
    }
    if (p === '/demo/lan/remove' && req.method === 'POST') {
        const b = await readBody(req);
        const removed = lan.removeDevice(b.id);
        json(res, 200, { ok: removed, devices: lan.listDevices(), status: lan.status() });
        return;
    }
    res.writeHead(404, { 'Content-Type': 'text/plain' });
    res.end('not found');
});

server.listen(AGENT_PORT, '127.0.0.1', () => {
    console.log('[demo] agent window  http://127.0.0.1:' + AGENT_PORT + '/agent');
    console.log('[demo] LAN port      ' + LAN_PORT + ' (starts when the switch is ticked on camera)');
});
