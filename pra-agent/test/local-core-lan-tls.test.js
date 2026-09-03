'use strict';

const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { loadOrCreateTlsIdentity, pins } = require('../src/local-core/lan-tls-identity');
const { createLocalCoreLanTls, waiterCommandAllowed, validWaiterAuthority, authorityMatchesDevice } = require('../src/local-core/lan-tls');
const { LocalCoreDomain } = require('../src/local-core/domain-engine');

const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'core-lan-tls-'));
const protector = {
    protect: (value) => Buffer.concat([Buffer.from('wrapped:'), Buffer.from(value).reverse()]),
    unprotect: (value) => {
        assert.strictEqual(value.subarray(0, 8).toString(), 'wrapped:');
        return Buffer.from(value.subarray(8)).reverse();
    },
};

const first = loadOrCreateTlsIdentity({ dataDir: dir, protector });
const second = loadOrCreateTlsIdentity({ dataDir: dir, protector });
assert.strictEqual(first.cert, second.cert, 'TLS identity must remain stable per install');
assert.strictEqual(first.spki_sha256, pins(first.cert).spki_sha256);
assert.ok(!fs.readFileSync(path.join(dir, 'local-core-lan-tls.bin')).includes(Buffer.from('PRIVATE KEY')),
    'private key must only be stored inside OS-wrapped material');
assert.strictEqual(fs.statSync(path.join(dir, 'local-core-lan-tls.bin')).mode & 0o777, 0o600);

const waiter = {
    lease_id: 9, token: 'waiter-lease', role: 'waiter', company_id: 'co-1',
    branch_id: 'br-1', device_id: 'dev-1', user_id: 'waiter-7',
    permissions: [], expires_at: Date.now() + 60000, revoked: false,
    chain: { signing_secret: 'secret' },
};
assert.strictEqual(validWaiterAuthority(waiter, Date.now()), true);
assert.strictEqual(waiterCommandAllowed(waiter, 'order.hold'), true, 'atomic hold is the only waiter order creation mutation');
assert.strictEqual(waiterCommandAllowed(waiter, 'order.open'), false);
assert.strictEqual(waiterCommandAllowed(waiter, 'order.line.add'), false);
assert.strictEqual(waiterCommandAllowed(waiter, 'order.line.consume'), false);
assert.strictEqual(waiterCommandAllowed(waiter, 'table.claim'), true);
assert.strictEqual(waiterCommandAllowed(waiter, 'table.release'), true);
assert.strictEqual(waiterCommandAllowed(waiter, 'order.settle'), false);
assert.strictEqual(waiterCommandAllowed(waiter, 'order.cancel'), false);
assert.strictEqual(waiterCommandAllowed(Object.assign({}, waiter, { permissions: ['order.settle'] }), 'order.settle'), true);
assert.strictEqual(validWaiterAuthority(Object.assign({}, waiter, { revoked: true }), Date.now()), false);
assert.strictEqual(validWaiterAuthority(Object.assign({}, waiter, { expires_at: Date.now() - 1 }), Date.now()), false);
assert.strictEqual(validWaiterAuthority(Object.assign({}, waiter, { user_id: '' }), Date.now()), false,
    'a lease cannot cross or omit waiter identity');
const pairedDevice = { scope: { company_id: 'co-1', branch_id: 'br-1', user_id: 'waiter-7' } };
assert.strictEqual(authorityMatchesDevice(waiter, pairedDevice), true);
assert.strictEqual(authorityMatchesDevice(Object.assign({}, waiter, { user_id: 'waiter-8' }), pairedDevice), false);
assert.strictEqual(authorityMatchesDevice(Object.assign({}, waiter, { branch_id: 'br-2' }), pairedDevice), false);
(async function realPairSymmetry() {
    const serverDir = fs.mkdtempSync(path.join(os.tmpdir(), 'core-lan-pair-'));
    const identity = loadOrCreateTlsIdentity({ dataDir: serverDir, protector });
    const signingSecret = Buffer.alloc(32, 11).toString('base64url');
    const authority = Object.assign({}, waiter, { token: 'opaque-waiter-lease-proof',
        chain: { signing_secret: signingSecret, next_sequence: 1, prev_hash: '0'.repeat(64) } });
    let registered = false;
    const rootScope = { company_id: 'co-1', branch_id: 'br-1', device_id: 'desktop', user_id: 'owner' };
    const domain = new LocalCoreDomain({
        dataDir: path.join(serverDir, 'domain'), encryptionKey: Buffer.alloc(32, 12),
        authorityScope: rootScope, authority: {
            lease_id: 1, token: 'opaque-owner-authority-token', signing_secret: signingSecret,
            next_sequence: 1, prev_hash: '0'.repeat(64), allowed_actions: ['*'],
            expires_at_ms: Date.now() + 60000, owner: true, scope: rootScope,
        },
    });
    domain.importSnapshot({
        schema: 'local-core.snapshot.v1', revision: 1, scope: rootScope,
        payload: { catalog: { revision: 1, products: [{ id: 'tea', revision: 2, name: 'Tea' }],
            ingredients: [], tables: [{ id: '4', revision: 3 }] }, orders: {}, tables: {}, stock: {},
        recipes: {}, customers: {}, cash_days: {}, staff_sessions: {}, settings: {} },
        hash: 'a'.repeat(64),
    });
    const server = createLocalCoreLanTls({
        dataDir: serverDir, port: 0, identity,
        pairingAuthorityProvider: () => authority,
        waiterAuthorityProvider: (token) => token === authority.token ? authority : null,
        actorRegistrar: (scope) => {
            domain.registerActorSession({
                lease_id: authority.lease_id, token: authority.token, signing_secret: signingSecret,
                next_sequence: 1, prev_hash: '0'.repeat(64), allowed_actions: ['order.hold'],
                permissions: ['order.hold'], role: 'waiter', expires_at_ms: authority.expires_at,
                owner: false, scope, allow_rotation: true,
            });
            registered = true; return true;
        },
        actorRevoker: () => true,
        domainProvider: () => domain,
    });
    await server.start();
    try {
        const offer = server.pairingPayload();
        assert.strictEqual(JSON.parse(offer.encoded).waiter_lease, authority.token, 'QR and pair proof must be symmetric');
        const payload = JSON.stringify({
            code: offer.payload.code, nonce: offer.payload.nonce,
            waiter_lease: offer.payload.waiter_lease, device: 'Waiter test',
        });
        const response = await new Promise((resolve, reject) => {
            const req = require('https').request({
                hostname: '127.0.0.1', port: server.status().port, path: '/pair', method: 'POST',
                rejectUnauthorized: false, headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload) },
            }, (res) => {
                let raw = ''; res.on('data', (chunk) => { raw += chunk; });
                res.on('end', () => resolve({ status: res.statusCode, body: JSON.parse(raw) }));
            });
            req.on('error', reject); req.end(payload);
        });
        assert.strictEqual(response.status, 200);
        assert.ok(response.body.device_token && registered, 'real pair must issue credential and register actor');
        const hold = JSON.stringify({ command: {
            v: 1, id: 'waiter-hold-command-1', type: 'order.hold', aggregate_id: 'waiter-hold-1',
            expected_revision: 0, at_ms: Date.now(), payload: { order_snapshot: {
                order_id: 'waiter-hold-1', business_date: '2026-09-03', order_type: 'dine_in',
                catalog_revision: 1, table_id: '4', table_revision: 3, table_snapshot: { id: '4', revision: 3 },
                lines: [{ line_id: 'line-1', product_id: 'tea', product_revision: 2, name: 'Tea',
                    quantity: 1, unit_price_cents: 100, tax_snapshot: {}, recipe_snapshot: [],
                    deal_snapshot: [], direct_consumption_snapshot: [] }],
                totals: { subtotal_cents: 100, tax_cents: 0, discount_cents: 0, total_cents: 100 },
            } },
        } });
        const held = await new Promise((resolve, reject) => {
            const req = require('https').request({
                hostname: '127.0.0.1', port: server.status().port, path: '/command', method: 'POST',
                rejectUnauthorized: false, headers: { Authorization: 'Bearer ' + response.body.device_token,
                    'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(hold) },
            }, (res) => {
                let raw = ''; res.on('data', (chunk) => { raw += chunk; });
                res.on('end', () => resolve({ status: res.statusCode, body: JSON.parse(raw) }));
            });
            req.on('error', reject); req.end(hold);
        });
        assert.strictEqual(held.status, 200, JSON.stringify(held.body));
        assert.strictEqual(domain.snapshot().orders['waiter-hold-1'].lines[0].consumed, true,
            'real paired waiter atomically holds the order in the shared domain');
        assert.strictEqual(domain.snapshot().tables['4'].order_id, 'waiter-hold-1');
    } finally {
        await server.stop();
        domain.close();
        fs.rmSync(serverDir, { recursive: true, force: true });
    }
    console.log('local-core LAN TLS identity/pairing tests passed');
})().catch((error) => { console.error(error); process.exitCode = 1; });