'use strict';
/*!
 * NestPOS LAN Server — the shop's own little server, running inside the agent.
 *
 * WHY THIS EXISTS (owner + Pizza-Master-style shops, Aug 2026):
 * The waiter tablet and the Caller ID phone both talk to the CLOUD today, so a
 * dropped internet line kills order punching and call popups even though every
 * device is sitting on the same WiFi, three feet apart. The shop PC already
 * runs this agent for PRA sync + silent printing, so it becomes a tiny local
 * server: tablets and the phone reach it by IP, the shop keeps working, and
 * everything replays to the cloud the moment the line comes back.
 *
 * DESIGN RULES (do not break these):
 *  1. PURE NODE. No `require('electron')` in this file — main.js injects
 *     everything it needs. That keeps the whole thing testable with plain
 *     `node` in CI (Electron itself cannot boot in the build container).
 *  2. OPT-IN. Nothing listens until the shop turns LAN mode on. Off = the
 *     agent behaves byte-identically to every version before this one.
 *  3. PRIVATE LAN ONLY. We bind 0.0.0.0 so tablets can reach us, but every
 *     request from a non-private address is refused — a shop PC on a public
 *     IP must never expose its kitchen to the internet.
 *  4. PAIRED DEVICES ONLY. Everything except /lan/health needs a device token,
 *     issued once against a 6-digit code the owner reads off the agent window.
 *  5. NEVER A CLOUD REPLACEMENT. While the internet is up the cloud stays the
 *     single source of truth; this server is the fallback lane, not a fork.
 */

const http = require('http');
const fs = require('fs');
const path = require('path');
const os = require('os');
const crypto = require('crypto');

const DEFAULT_PORT = 8531;
const MAX_EVENTS = 200;          // caller ring buffer cap (memory + disk)
const EVENT_TTL_MS = 6 * 60 * 60 * 1000;   // 6h — a stale ring is noise
const PAIR_WINDOW_MS = 10 * 60 * 1000;
const PAIR_MAX_TRIES = 10;       // per IP per window — a 6-digit code must not be brute-forceable
const BODY_LIMIT = 256 * 1024;   // 256KB is plenty for an order payload

/* ---------------------------------------------------------------- helpers */

// 10.x, 172.16–31.x, 192.168.x, 169.254.x and loopback. IPv6-mapped IPv4
// ("::ffff:192.168.1.5") arrives from Node when the socket is dual-stack.
function isPrivateAddress(addr) {
    if (!addr) { return false; }
    let a = String(addr).trim().toLowerCase();
    if (a.startsWith('::ffff:')) { a = a.slice(7); }
    if (a === '::1' || a === '::') { return true; }
    if (a.startsWith('fe80:') || a.startsWith('fc') || a.startsWith('fd')) { return true; }
    const m = a.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/);
    if (!m) { return false; }
    const o = m.slice(1).map(Number);
    if (o.some(function (n) { return n > 255; })) { return false; }
    if (o[0] === 127 || o[0] === 10) { return true; }
    if (o[0] === 192 && o[1] === 168) { return true; }
    if (o[0] === 172 && o[1] >= 16 && o[1] <= 31) { return true; }
    if (o[0] === 169 && o[1] === 254) { return true; }
    return false;
}

// Private IPv4s this PC owns — shown in the agent window so the owner can type
// http://<ip>:8531 into a tablet.
function localAddresses() {
    const out = [];
    let ifaces = {};
    try { ifaces = os.networkInterfaces() || {}; } catch (e) { ifaces = {}; }
    Object.keys(ifaces).forEach(function (name) {
        (ifaces[name] || []).forEach(function (info) {
            if (!info || info.internal) { return; }
            if (info.family !== 'IPv4' && info.family !== 4) { return; }
            if (isPrivateAddress(info.address)) { out.push(info.address); }
        });
    });
    return out;
}

// Port 0 is legal and means "OS, pick a free one" (tests use it). A bare
// `Number(p) || DEFAULT` would silently turn that 0 into 8531 and put two
// servers on one port, so parse it properly.
function normalizePort(p, fallback) {
    const n = Number(p);
    return Number.isInteger(n) && n >= 0 && n <= 65535 ? n : fallback;
}

function makePairCode() {
    // crypto.randomInt keeps the code unguessable; Math.random would not.
    return String(crypto.randomInt(100000, 1000000));
}

function makeToken() {
    return crypto.randomBytes(24).toString('hex');
}

// Constant-time compare so a paired token cannot be discovered byte by byte.
function safeEqual(a, b) {
    const x = Buffer.from(String(a || ''));
    const y = Buffer.from(String(b || ''));
    if (x.length !== y.length) { return false; }
    try { return crypto.timingSafeEqual(x, y); } catch (e) { return false; }
}

function readJsonFile(file, fallback) {
    try {
        const raw = fs.readFileSync(file, 'utf8');
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : fallback;
    } catch (e) {
        return fallback;
    }
}

// tmp + rename: a half-written devices file must never brick pairing.
function writeJsonFile(file, value) {
    try {
        fs.mkdirSync(path.dirname(file), { recursive: true });
        const tmp = file + '.tmp';
        fs.writeFileSync(tmp, JSON.stringify(value), 'utf8');
        fs.renameSync(tmp, file);
        return true;
    } catch (e) {
        return false;
    }
}

/* ------------------------------------------------------------------ server */

/**
 * @param {object} options
 *   dataDir   {string}   where devices/events are persisted (agent userData)
 *   port      {number}   listen port (default 8531)
 *   log       {function} (message, meta) sink
 *   version   {string}   agent version, reported by /lan/health
 *   shopName  {string}   label shown while pairing
 */
function createLanServer(options) {
    const opts = options || {};
    const dataDir = opts.dataDir || path.join(os.tmpdir(), 'nestpos-lan');
    const devicesFile = path.join(dataDir, 'lan-devices.json');
    const eventsFile = path.join(dataDir, 'lan-caller-events.json');
    const log = typeof opts.log === 'function' ? opts.log : function () {};
    // Extra browser origins the shop is allowed to call us from (self-hosted
    // panel, a test domain). Normalised once so the check stays cheap.
    const extraOrigins = (Array.isArray(opts.allowedOrigins) ? opts.allowedOrigins : [])
        .map(function (o) { return String(o || '').trim().toLowerCase().replace(/\/+$/, ''); })
        .filter(Boolean);

    const state = {
        server: null,
        port: normalizePort(opts.port, DEFAULT_PORT),
        starting: false,
        devices: readJsonFile(devicesFile, {}),        // token -> { name, kind, paired_at, last_seen }
        events: (readJsonFile(eventsFile, { events: [] }).events || []),
        nextEventId: 1,
        pairCode: makePairCode(),
        pairFails: {},                                  // ip -> { count, until }
        lastError: null,
    };
    state.nextEventId = state.events.reduce(function (max, e) {
        return Math.max(max, Number(e && e.id) || 0);
    }, 0) + 1;

    function persistDevices() { writeJsonFile(devicesFile, state.devices); }
    function persistEvents() { writeJsonFile(eventsFile, { events: state.events }); }

    /* -- request plumbing -- */

    function send(res, status, payload, headers) {
        const body = payload === null || payload === undefined ? '' : JSON.stringify(payload);
        const base = {
            'Content-Type': 'application/json; charset=utf-8',
            'Cache-Control': 'no-store',
            'Access-Control-Allow-Headers': 'Authorization, Content-Type, X-Lan-Token',
            'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
            'Access-Control-Max-Age': '600',
        };
        // The POS page is https://taxnest.com.pk but talks to
        // http://127.0.0.1:<port> (browsers treat loopback as a secure origin,
        // so this is not blocked as mixed content). That call only lands if we
        // send CORS back — but a blanket '*' would let ANY site the shop PC
        // happens to visit read the shop's caller list, so we echo the origin
        // only when it is one of ours.
        if (res._lanOrigin) { base['Access-Control-Allow-Origin'] = res._lanOrigin; }
        base['Vary'] = 'Origin';
        Object.keys(headers || {}).forEach(function (k) { base[k] = headers[k]; });
        res.writeHead(status, base);
        res.end(body);
    }

    // Which web pages may read this server from a browser. Anything else gets
    // no CORS header at all: the request still runs, but the page cannot see
    // the answer — the browser's own protection against a hostile site
    // fishing on localhost.
    function allowedOrigin(origin) {
        const o = String(origin || '').trim().toLowerCase().replace(/\/+$/, '');
        if (!o) { return ''; }
        if (extraOrigins.indexOf(o) !== -1) { return origin; }
        if (/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/.test(o)) { return origin; }
        // Our own site (any subdomain) and Replit dev previews of it.
        if (/^https:\/\/([a-z0-9-]+\.)*taxnest\.com\.pk$/.test(o)) { return origin; }
        if (/^https:\/\/[a-z0-9-]+\.replit\.dev$/.test(o)) { return origin; }
        // The shop's own PC reached by LAN IP (tablet browsing to the counter).
        const host = o.replace(/^https?:\/\//, '').split(':')[0];
        if (isPrivateAddress(host)) { return origin; }
        return '';
    }

    function readBody(req) {
        return new Promise(function (resolve, reject) {
            let size = 0;
            const chunks = [];
            req.on('data', function (c) {
                size += c.length;
                if (size > BODY_LIMIT) {
                    reject(new Error('body too large'));
                    req.destroy();
                    return;
                }
                chunks.push(c);
            });
            req.on('end', function () {
                const raw = Buffer.concat(chunks).toString('utf8');
                if (!raw) { resolve({}); return; }
                try { resolve(JSON.parse(raw)); } catch (e) { resolve({ _raw: raw }); }
            });
            req.on('error', reject);
        });
    }

    function tokenFrom(req, url) {
        const auth = req.headers['authorization'] || '';
        if (/^bearer\s+/i.test(auth)) { return auth.replace(/^bearer\s+/i, '').trim(); }
        if (req.headers['x-lan-token']) { return String(req.headers['x-lan-token']).trim(); }
        // Tablets open a plain URL in a WebView; a query token keeps that simple.
        const q = url.searchParams.get('t');
        return q ? String(q).trim() : '';
    }

    function deviceFor(req, url) {
        const token = tokenFrom(req, url);
        if (!token) { return null; }
        // Constant-time match against every known token (a handful at most).
        const hit = Object.keys(state.devices).find(function (t) { return safeEqual(t, token); });
        if (!hit) { return null; }
        const dev = state.devices[hit];
        dev.last_seen = Date.now();
        return { token: hit, device: dev };
    }

    /* -- pairing -- */

    function pairBlocked(ip) {
        const rec = state.pairFails[ip];
        if (!rec) { return false; }
        if (Date.now() > rec.until) { delete state.pairFails[ip]; return false; }
        return rec.count >= PAIR_MAX_TRIES;
    }

    function notePairFail(ip) {
        const rec = state.pairFails[ip] || { count: 0, until: Date.now() + PAIR_WINDOW_MS };
        rec.count += 1;
        if (Date.now() > rec.until) { rec.count = 1; rec.until = Date.now() + PAIR_WINDOW_MS; }
        state.pairFails[ip] = rec;
    }

    function handlePair(req, res, body, ip) {
        if (pairBlocked(ip)) {
            send(res, 429, { ok: false, error: 'too_many_attempts' });
            return;
        }
        const code = String((body && body.code) || '').trim();
        if (!code || !safeEqual(code, state.pairCode)) {
            notePairFail(ip);
            log('LAN pair rejected (wrong code) from ' + ip);
            send(res, 403, { ok: false, error: 'bad_code' });
            return;
        }
        const token = makeToken();
        state.devices[token] = {
            name: String((body && body.device) || 'device').slice(0, 60),
            kind: String((body && body.kind) || 'waiter').slice(0, 20),
            paired_at: Date.now(),
            last_seen: Date.now(),
        };
        persistDevices();
        delete state.pairFails[ip];
        // One code = one device. The next tablet gets a fresh code, so a code
        // seen over someone's shoulder cannot be replayed later.
        state.pairCode = makePairCode();
        log('LAN device paired: ' + state.devices[token].name + ' (' + state.devices[token].kind + ')');
        send(res, 200, { ok: true, token: token, name: state.devices[token].name });
    }

    /* -- caller id ring buffer -- */

    function pruneEvents() {
        const cutoff = Date.now() - EVENT_TTL_MS;
        state.events = state.events.filter(function (e) { return (e.at_ms || 0) >= cutoff; });
        if (state.events.length > MAX_EVENTS) {
            state.events = state.events.slice(state.events.length - MAX_EVENTS);
        }
    }

    function handleRing(res, body) {
        const number = String((body && body.number) || '').replace(/[^\d+]/g, '').slice(0, 20);
        if (!number) { send(res, 422, { ok: false, error: 'number_required' }); return; }
        const uuid = String((body && body.uuid) || '').slice(0, 64);
        if (uuid) {
            // The phone retries a failed POST; the same ring must not pop twice.
            const dupe = state.events.find(function (e) { return e.uuid && e.uuid === uuid; });
            if (dupe) { send(res, 200, { ok: true, id: dupe.id, duplicate: true }); return; }
        }
        const ev = {
            id: state.nextEventId++,
            uuid: uuid || null,
            number: number,
            name: String((body && body.name) || '').slice(0, 80) || null,
            source: (body && body.source) === 'whatsapp' ? 'whatsapp' : 'sim',
            at_ms: Date.now(),
            synced: false,
        };
        state.events.push(ev);
        pruneEvents();
        persistEvents();
        log('LAN ring queued: ' + ev.number + ' (' + ev.source + ')');
        send(res, 200, { ok: true, id: ev.id });
    }

    function handleEvents(res, url) {
        const after = Number(url.searchParams.get('after') || 0) || 0;
        pruneEvents();
        const fresh = state.events
            .filter(function (e) { return e.id > after; })
            .slice(-5)
            .map(function (e) {
                return {
                    id: e.id,
                    number: e.number,
                    name: e.name,
                    source: e.source,
                    at: new Date(e.at_ms).toISOString(),
                };
            });
        send(res, 200, {
            ok: true,
            events: fresh,
            last_id: state.events.length ? state.events[state.events.length - 1].id : after,
        });
    }

    /* -- router -- */

    async function route(req, res) {
        const ip = (req.socket && req.socket.remoteAddress) || '';
        const url = new URL(req.url || '/', 'http://lan.local');
        const route_ = url.pathname.replace(/\/+$/, '') || '/';
        res._lanOrigin = allowedOrigin(req.headers && req.headers.origin);

        if (req.method === 'OPTIONS') { send(res, 204, null); return; }

        // Rule 3: a request from outside the shop's own network never gets in.
        if (!isPrivateAddress(ip)) {
            log('LAN request refused from non-private address ' + ip);
            send(res, 403, { ok: false, error: 'lan_only' });
            return;
        }

        // Discovery ping — deliberately says nothing about the shop.
        if (route_ === '/lan/health' && req.method === 'GET') {
            send(res, 200, {
                ok: true,
                app: 'nestpos-lan',
                api: 1,
                version: String(opts.version || ''),
            });
            return;
        }

        if (route_ === '/lan/pair' && req.method === 'POST') {
            handlePair(req, res, await readBody(req), ip);
            return;
        }

        // READING rings from the shop's OWN PC needs no pairing: the POS page
        // runs on this machine, and anyone at this machine is already logged
        // into the POS. Asking a cashier to pair the counter with itself would
        // buy nothing. Everything that WRITES still needs a paired device, and
        // a browser on another PC still cannot read the answer (CORS above).
        const loopback = /^(::1|::ffff:127\.|127\.)/.test(String(ip));
        if (route_ === '/lan/caller/events' && req.method === 'GET' && loopback) {
            handleEvents(res, url);
            return;
        }

        const auth = deviceFor(req, url);
        if (!auth) { send(res, 401, { ok: false, error: 'pair_required' }); return; }

        if (route_ === '/lan/caller/ring' && req.method === 'POST') {
            handleRing(res, await readBody(req));
            return;
        }
        if (route_ === '/lan/caller/events' && req.method === 'GET') {
            handleEvents(res, url);
            return;
        }
        if (route_ === '/lan/whoami' && req.method === 'GET') {
            send(res, 200, { ok: true, name: auth.device.name, kind: auth.device.kind });
            return;
        }

        send(res, 404, { ok: false, error: 'not_found' });
    }

    /* -- lifecycle -- */

    function start(port) {
        if (state.server || state.starting) { return Promise.resolve(status()); }
        state.starting = true;
        state.port = normalizePort(port, state.port);
        return new Promise(function (resolve) {
            const server = http.createServer(function (req, res) {
                route(req, res).catch(function (e) {
                    log('LAN request failed: ' + (e && e.message));
                    try { send(res, 500, { ok: false, error: 'server_error' }); } catch (ignored) {}
                });
            });
            server.on('error', function (e) {
                state.lastError = (e && e.message) || String(e);
                state.server = null;
                state.starting = false;
                log('LAN server could not start: ' + state.lastError);
                resolve(status());
            });
            server.listen(state.port, '0.0.0.0', function () {
                state.server = server;
                state.starting = false;
                state.lastError = null;
                // port 0 = OS picks one (tests); remember what we actually got.
                try {
                    const addr = server.address();
                    if (addr && addr.port) { state.port = addr.port; }
                } catch (e) { /* keep configured port */ }
                log('LAN server listening on port ' + state.port);
                resolve(status());
            });
        });
    }

    function stop() {
        return new Promise(function (resolve) {
            const server = state.server;
            if (!server) { resolve(); return; }
            state.server = null;
            try { server.close(function () { resolve(); }); } catch (e) { resolve(); }
            // close() waits for keep-alive sockets; the shop should not.
            setTimeout(resolve, 1500);
        });
    }

    function status() {
        return {
            running: !!state.server,
            port: state.port,
            addresses: localAddresses(),
            urls: localAddresses().map(function (ip) { return 'http://' + ip + ':' + state.port; }),
            devices: Object.keys(state.devices).length,
            pending_events: state.events.filter(function (e) { return !e.synced; }).length,
            pair_code: state.pairCode,
            error: state.lastError,
        };
    }

    function forgetDevices() {
        state.devices = {};
        persistDevices();
        state.pairCode = makePairCode();
        log('LAN devices cleared — every tablet must pair again');
    }

    // Cloud sync (Phase 3) drains from here and marks what it managed to push.
    function pendingEvents() {
        return state.events.filter(function (e) { return !e.synced; }).map(function (e) { return Object.assign({}, e); });
    }
    function markSynced(ids) {
        const set = new Set((ids || []).map(Number));
        state.events.forEach(function (e) { if (set.has(e.id)) { e.synced = true; } });
        persistEvents();
    }

    return {
        start: start,
        stop: stop,
        status: status,
        forgetDevices: forgetDevices,
        pendingEvents: pendingEvents,
        markSynced: markSynced,
        get running() { return !!state.server; },
    };
}

module.exports = {
    createLanServer: createLanServer,
    isPrivateAddress: isPrivateAddress,
    localAddresses: localAddresses,
    DEFAULT_PORT: DEFAULT_PORT,
};
