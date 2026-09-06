'use strict';

const https = require('https');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const os = require('os');
const { isPrivateAddress } = require('../lan-server');

const BODY_LIMIT = 256 * 1024;
const PAIR_TTL_MS = 5 * 60 * 1000;
const TOKEN_TTL_MS = 90 * 24 * 60 * 60 * 1000;
const MAX_DEVICES = 25;
const WAITER_COMMANDS = new Set([
    'order.hold', 'order.claim',
    'table.claim', 'table.release', 'table.shift',
]);
const SENSITIVE_WAITER_COMMANDS = new Set(['order.cancel', 'order.settle']);

function waiterCommandAllowed(authority, type) {
    const permissions = Array.isArray(authority && authority.permissions) ? authority.permissions :
        (Array.isArray(authority && authority.allowed_actions) ? authority.allowed_actions : []);
    return WAITER_COMMANDS.has(type) || (SENSITIVE_WAITER_COMMANDS.has(type) && permissions.includes(type));
}

function validWaiterAuthority(value, now) {
    const a = value || {};
    const expiry = Number.isFinite(Number(a.expires_at)) ? Number(a.expires_at) : Date.parse(String(a.expires_at || ''));
    return !!(a.lease_id && a.token && a.role === 'waiter' && a.chain && a.chain.signing_secret &&
        String(a.company_id || '') && String(a.branch_id || '') && String(a.user_id || '') &&
        (Array.isArray(a.permissions) || Array.isArray(a.allowed_actions)) && expiry > now && a.revoked !== true);
}
function authorityMatchesDevice(authority, device) {
    return !!(authority && device && device.scope &&
        String(authority.user_id) === String(device.scope.user_id) &&
        String(authority.company_id) === String(device.scope.company_id) &&
        String(authority.branch_id) === String(device.scope.branch_id));
}

function hash(value) {
    return crypto.createHash('sha256').update(String(value || '')).digest('hex');
}
function equal(a, b) {
    const x = Buffer.from(String(a || ''));
    const y = Buffer.from(String(b || ''));
    return x.length === y.length && crypto.timingSafeEqual(x, y);
}
function addresses() {
    const out = [];
    Object.values(os.networkInterfaces() || {}).forEach((list) => (list || []).forEach((i) => {
        if (!i.internal && (i.family === 'IPv4' || i.family === 4) && isPrivateAddress(i.address)) out.push(i.address);
    }));
    return out;
}
function atomicJson(file, value) {
    fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
    const tmp = file + '.tmp';
    fs.writeFileSync(tmp, JSON.stringify(value), { mode: 0o600 });
    fs.renameSync(tmp, file);
    try { fs.chmodSync(file, 0o600); } catch (e) {}
}
function readJson(file) {
    if (!fs.existsSync(file)) return {};
    try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch (e) { throw new Error('LAN TLS credential store is corrupt'); }
}
function body(req) {
    return new Promise((resolve, reject) => {
        let n = 0; const chunks = [];
        req.on('data', (c) => {
            n += c.length;
            if (n > BODY_LIMIT) { reject(Object.assign(new Error('too large'), { status: 413 })); req.destroy(); }
            else chunks.push(c);
        });
        req.on('end', () => {
            try { resolve(chunks.length ? JSON.parse(Buffer.concat(chunks).toString('utf8')) : {}); }
            catch (e) { reject(Object.assign(new Error('bad json'), { status: 400 })); }
        });
        req.on('error', reject);
    });
}

function createLocalCoreLanTls(options) {
    const opts = options || {};
    if (!opts.identity || !opts.identity.key || !opts.identity.cert) throw new Error('TLS identity is required');
    if (typeof opts.domainProvider !== 'function' || typeof opts.waiterAuthorityProvider !== 'function') {
        throw new Error('domain and waiter authority providers are required');
    }
    const file = path.join(opts.dataDir, 'local-core-lan-devices.json');
    let devices = readJson(file);
    let server = null;
    let port = Number.isInteger(opts.port) ? opts.port : 8532;
    let offer = null;
    const failures = new Map();
    const traffic = new Map();
    const maxDevices = Number.isInteger(opts.maxDevices) ? opts.maxDevices : MAX_DEVICES;

    function save() { atomicJson(file, devices); }
    function send(res, status, payload) {
        res.writeHead(status, {
            'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store',
            'X-Content-Type-Options': 'nosniff', 'Referrer-Policy': 'no-referrer',
        });
        res.end(JSON.stringify(payload));
    }
    function issueOffer() {
        const ips = addresses();
        if (!ips.length) throw new Error('No private LAN address is available');
        const now = Date.now();
        const waiterAuthority = typeof opts.pairingAuthorityProvider === 'function'
            ? opts.pairingAuthorityProvider() : null;
        if (!validWaiterAuthority(waiterAuthority, now)) throw new Error('Authenticated waiter session is required');
        offer = {
            code: String(crypto.randomInt(100000, 1000000)),
            nonce: crypto.randomBytes(18).toString('base64url'),
            waiter_lease: waiterAuthority.token,
            expires_at: now + PAIR_TTL_MS,
        };
        const payload = {
            v: 1, url: 'https://' + ips[0] + ':' + port,
            spki_sha256: opts.identity.spki_sha256,
            cert_sha256: opts.identity.cert_sha256,
            code: offer.code, nonce: offer.nonce, waiter_lease: offer.waiter_lease,
            expires_at: Math.min(offer.expires_at,
                Number.isFinite(Number(waiterAuthority.expires_at)) ? Number(waiterAuthority.expires_at) :
                    Date.parse(String(waiterAuthority.expires_at))),
        };
        return { payload, encoded: JSON.stringify(payload) };
    }
    function authenticated(req) {
        const auth = String(req.headers.authorization || '').replace(/^Bearer\s+/i, '');
        if (!auth) return null;
        const h = hash(auth);
        const record = devices[h];
        if (!record || record.revoked_at || !equal(h, hash(auth))) return null;
        if (record.expires_at <= Date.now()) {
            if (typeof opts.actorRevoker === 'function') opts.actorRevoker(record.scope);
            record.revoked_at = Date.now(); save(); return null;
        }
        record.last_seen = Date.now();
        return { hash: h, record };
    }
    function rotateCredential(auth) {
        const token = crypto.randomBytes(32).toString('base64url');
        delete devices[auth.hash];
        devices[hash(token)] = auth.record;
        auth.hash = hash(token);
        save();
        return token;
    }
    function limited(ip) {
        const now = Date.now();
        const r = failures.get(ip);
        if (!r || r.until < now) return false;
        return r.count >= 8;
    }
    function failPair(ip) {
        const now = Date.now();
        const old = failures.get(ip);
        failures.set(ip, !old || old.until < now ? { count: 1, until: now + 10 * 60 * 1000 } :
            { count: old.count + 1, until: old.until });
    }
    function requestLimited(ip) {
        const now = Date.now();
        const old = traffic.get(ip);
        const next = !old || old.until < now ? { count: 1, until: now + 60 * 1000 } :
            { count: old.count + 1, until: old.until };
        traffic.set(ip, next);
        return next.count > 240;
    }
    async function route(req, res) {
        const ip = req.socket.remoteAddress || '';
        if (!isPrivateAddress(ip)) return send(res, 403, { ok: false, error: 'lan_only' });
        const url = new URL(req.url, 'https://local.invalid');
        if (url.pathname === '/health' && req.method === 'GET') return send(res, 200, { ok: true });
        if (url.pathname === '/pair' && req.method === 'POST') {
            if (limited(ip)) return send(res, 429, { ok: false, error: 'too_many_attempts' });
            const input = await body(req);
            if (!offer || offer.expires_at <= Date.now() || !equal(input.code, offer.code) || !equal(input.nonce, offer.nonce)) {
                failPair(ip); return send(res, 403, { ok: false, error: 'bad_or_expired_code' });
            }
            if (Object.keys(devices).filter((k) => !devices[k].revoked_at).length >= maxDevices) {
                return send(res, 409, { ok: false, error: 'device_limit' });
            }
            if (!equal(input.waiter_lease, offer.waiter_lease)) {
                failPair(ip); return send(res, 403, { ok: false, error: 'waiter_session_required' });
            }
            const authority = opts.waiterAuthorityProvider(String(offer.waiter_lease || ''));
            if (!validWaiterAuthority(authority, Date.now())) {
                return send(res, 403, { ok: false, error: 'waiter_session_required' });
            }
            const authorityPermissions = Array.isArray(authority.permissions) ? authority.permissions : authority.allowed_actions;
            const authorityExpiry = Number.isFinite(Number(authority.expires_at))
                ? Number(authority.expires_at) : Date.parse(String(authority.expires_at));
            const scope = {
                company_id: String(authority.company_id), branch_id: String(authority.branch_id),
                device_id: String(authority.device_id), user_id: String(authority.user_id),
            };
            const token = crypto.randomBytes(32).toString('base64url');
            devices[hash(token)] = {
                id: crypto.randomBytes(12).toString('hex'),
                name: String(input.device || 'Waiter').slice(0, 60),
                kind: 'waiter',
                scope, authority: {
                    lease_id: authority.lease_id, token: authority.token, role: 'waiter',
                    permissions: authorityPermissions.slice(), expires_at: authorityExpiry,
                }, paired_at: Date.now(), last_seen: Date.now(),
                expires_at: Math.min(Date.now() + TOKEN_TTL_MS, authorityExpiry),
            };
            if (typeof opts.actorRegistrar === 'function' && opts.actorRegistrar(scope, authority) !== true) {
                delete devices[hash(token)];
                return send(res, 503, { ok: false, error: 'core_unavailable' });
            }
            save(); offer = null; failures.delete(ip);
            return send(res, 200, { ok: true, device_token: token,
                expires_at: devices[hash(token)].expires_at, user_id: scope.user_id,
                role: 'waiter', permissions: authorityPermissions.slice() });
        }
        if (requestLimited(ip)) return send(res, 429, { ok: false, error: 'rate_limited' });
        const auth = authenticated(req);
        if (!auth) return send(res, 401, { ok: false, error: 'device_credential_required' });
        const device = auth.record;
        const currentAuthority = opts.waiterAuthorityProvider(device.authority && device.authority.token);
        if (!validWaiterAuthority(currentAuthority, Date.now()) ||
            !authorityMatchesDevice(currentAuthority, device)) {
            if (typeof opts.actorRevoker === 'function') opts.actorRevoker(device.scope);
            return send(res, 401, { ok: false, error: 'waiter_session_revoked' });
        }
        device.authority.permissions = (currentAuthority.permissions || currentAuthority.allowed_actions).slice();
        const domain = opts.domainProvider(device.scope);
        if (!domain) return send(res, 503, { ok: false, error: 'core_unavailable' });
        if (url.pathname === '/command' && req.method === 'POST') {
            const input = await body(req);
            const command = Object.assign({}, input.command, { scope: device.scope });
            if (device.kind === 'waiter' && !waiterCommandAllowed(currentAuthority, command.type)) {
                return send(res, 403, { ok: false, error: 'command_not_allowed' });
            }
            try {
                const result = domain.execute(command);
                return send(res, 200, { ok: true, result, next_device_token: rotateCredential(auth) });
            }
            catch (e) { return send(res, 409, { ok: false, error: e.code || 'command_rejected' }); }
        }
        if (url.pathname === '/query' && req.method === 'POST') {
            const input = await body(req);
            if (input.query === 'events') {
                let result = domain.events(Number(input.after) || 0, Math.min(Number(input.limit) || 100, 500));
                if (device.kind === 'waiter') result = result.filter((event) =>
                    String(event.type || '').startsWith('order.') || String(event.type || '').startsWith('table.'));
                return send(res, 200, { ok: true, result, next_device_token: rotateCredential(auth) });
            }
            if (input.query === 'snapshot') {
                let result = domain.snapshot();
                if (device.kind === 'waiter') {
                    const revisions = {};
                    Object.keys(Object.assign({}, result.orders, result.tables)).forEach((id) => {
                        if (Object.prototype.hasOwnProperty.call(result.revisions || {}, id)) revisions[id] = result.revisions[id];
                    });
                    // recipes ride along (Sep 2026): the waiter freezes recipe
                    // parts from this projection because cloud-baked catalog
                    // rows carry none — without it every recipe product would
                    // hold offline with [] parts and hit recipe_conflict.
                    result = { sequence: result.sequence, catalog: result.catalog, orders: result.orders,
                        tables: result.tables, recipes: result.recipes || {}, revisions };
                }
                return send(res, 200, { ok: true, result, next_device_token: rotateCredential(auth) });
            }
            return send(res, 422, { ok: false, error: 'unknown_query' });
        }
        send(res, 404, { ok: false, error: 'not_found' });
    }
    return {
        start() {
            if (server) return Promise.resolve(this.status());
            return new Promise((resolve, reject) => {
                const next = https.createServer({ key: opts.identity.key, cert: opts.identity.cert }, (req, res) => {
                    route(req, res).catch((e) => send(res, e.status || 500, { ok: false, error: 'request_failed' }));
                });
                next.once('error', reject);
                next.listen(port, '0.0.0.0', () => {
                    server = next;
                    port = next.address().port;
                    resolve(this.status());
                });
            });
        },
        stop() {
            return new Promise((resolve) => {
                if (!server) return resolve();
                const old = server; server = null; old.close(resolve);
            });
        },
        pairingPayload: issueOffer,
        revoke(id) {
            const key = Object.keys(devices).find((k) => devices[k].id === id);
            if (!key) return false;
            if (typeof opts.actorRevoker === 'function') opts.actorRevoker(devices[key].scope);
            devices[key].revoked_at = Date.now(); save(); return true;
        },
        listDevices() {
            return Object.values(devices).map((d) => ({
                id: d.id, name: d.name, kind: d.kind, paired_at: d.paired_at,
                last_seen: d.last_seen, revoked: !!d.revoked_at,
            }));
        },
        status() {
            return { running: !!server, port, urls: addresses().map((ip) => 'https://' + ip + ':' + port),
                devices: Object.values(devices).filter((d) => !d.revoked_at).length,
                spki_sha256: opts.identity.spki_sha256 };
        },
    };
}

module.exports = { createLocalCoreLanTls, waiterCommandAllowed, validWaiterAuthority, authorityMatchesDevice };