'use strict';

/* Pure Node tests: node test/local-core-snapshot-sync.test.js */
const assert = require('assert');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { LocalCoreDomain } = require('../src/local-core/domain');
const { SnapshotSyncClient, canonicalJson } = require('../src/local-core/snapshot-sync');

const KEY = crypto.createHash('sha256').update('snapshot-sync-tests').digest();
const scope = { company_id: '1', branch_id: '2', device_id: 'DEV-1', user_id: '3' };
const authority = { lease_id: 9, token: 'lease-token',
    signing_secret: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', next_sequence: 1,
    prev_hash: '0'.repeat(64), scope, owner: true, allowed_actions: ['*'] };
const temp = () => fs.mkdtempSync(path.join(os.tmpdir(), 'core-snapshot-'));
function envelope(revision, payload, overrideScope) {
    const signed = { schema: 'local-core.snapshot.v1', revision,
        scope: overrideScope || scope, payload: Object.assign({
            catalog: null, stock: {}, recipes: {}, customers: {}, tables: {}, orders: {},
            cash_days: {}, staff_sessions: {}, settings: {},
        }, payload || {}) };
    return Object.assign({}, signed, { hash_algorithm: 'sha256',
        hash: crypto.createHash('sha256').update(canonicalJson(signed)).digest('hex') });
}
function domain(dir, extra) {
    return new LocalCoreDomain(Object.assign({ dataDir: dir, encryptionKey: KEY,
        authorityScope: scope, authority }, extra || {}));
}
async function refused(client, code) {
    try { await client.sync(); assert.fail('expected refusal'); } catch (e) { assert.strictEqual(e.code, code); }
}

(async () => {
    // Cross-tenant response is refused before import.
    let dir = temp();
    try {
        const d = domain(dir);
        const cross = envelope(1, {}, Object.assign({}, scope, { company_id: 'other' }));
        const client = new SnapshotSyncClient({ domain: d, request: async () => cross,
            scopeProvider: () => scope, leaseProvider: () => authority,
            heartbeatProvider: () => ({ positive: true, at_ms: Date.now() }) });
        await refused(client, 'scope_mismatch');
    } finally { fs.rmSync(dir, { recursive: true, force: true }); }

    // Hash tampering is detected using the canonical envelope.
    dir = temp();
    try {
        const d = domain(dir);
        const tampered = envelope(1, { stock: { flour: 4 } });
        tampered.payload.stock.flour = 900;
        const client = new SnapshotSyncClient({ domain: d, request: async () => tampered,
            scopeProvider: () => scope, leaseProvider: () => authority,
            heartbeatProvider: () => ({ positive: true, at_ms: Date.now() }) });
        await refused(client, 'snapshot_hash_mismatch');
    } finally { fs.rmSync(dir, { recursive: true, force: true }); }

    // The configured heartbeat UID is propagated to POST even though the
    // established domain authority field is still named device_id.
    dir = temp();
    try {
        const d = domain(dir);
        let posted;
        const response = envelope(2, { stock: { flour: 2 } });
        const client = new SnapshotSyncClient({ domain: d, deviceUid: 'DEV-1',
            request: async (body) => { posted = body; return response; },
            scopeProvider: () => scope, leaseProvider: () => authority,
            heartbeatProvider: () => ({ positive: true, at_ms: Date.now() }) });
        await client.sync();
        assert.strictEqual(posted.device_uid, 'DEV-1');
        assert.ok(posted.device_uid, 'snapshot POST must never lose the configured device UID');
    } finally { fs.rmSync(dir, { recursive: true, force: true }); }

    // Conflicting modern/legacy names are not normalized by guessing.
    dir = temp();
    try {
        const d = domain(dir);
        const client = new SnapshotSyncClient({ domain: d, deviceUid: 'DEV-1',
            request: async () => envelope(3), scopeProvider: () => Object.assign({}, scope, { device_uid: 'OTHER' }),
            leaseProvider: () => authority,
            heartbeatProvider: () => ({ positive: true, at_ms: Date.now() }) });
        await refused(client, 'scope_mismatch');
    } finally { fs.rmSync(dir, { recursive: true, force: true }); }

    // Older baselines never replace a newer installed revision.
    dir = temp();
    try {
        const d = domain(dir);
        d.importSnapshot(envelope(20, { stock: { flour: 20 } }));
        assert.throws(() => d.importSnapshot(envelope(19, { stock: { flour: 19 } })),
            (e) => e.code === 'stale_snapshot');
        assert.strictEqual(d.snapshot().stock.flour, 20);
    } finally { fs.rmSync(dir, { recursive: true, force: true }); }

    // A queued local aggregate wins a safe full-refresh merge.
    dir = temp();
    try {
        const d = domain(dir);
        d.execute({ v: 1, id: 'local-stock-1', type: 'stock.set', aggregate_id: 'flour',
            expected_revision: 0, at_ms: 1700000000000, scope, payload: { quantity: 7 } });
        const result = d.importSnapshot(envelope(30, { stock: { flour: 2, sugar: 3 } }));
        assert.strictEqual(result.pending_preserved, 1);
        assert.deepStrictEqual(d.snapshot().stock, { flour: 7, sugar: 3 });
    } finally { fs.rmSync(dir, { recursive: true, force: true }); }

    // Encrypted transaction marker completes an interrupted snapshot commit.
    dir = temp();
    try {
        const broken = domain(dir, { fault: (stage) => {
            if (stage === 'after_snapshot_marker') throw new Error('simulated crash');
        } });
        assert.throws(() => broken.importSnapshot(envelope(40, { stock: { flour: 11 } })));
        const recovered = domain(dir);
        assert.strictEqual(recovered.snapshot().bootstrap.revision, 40);
        assert.strictEqual(recovered.snapshot().stock.flour, 11);
        assert.ok(!fs.existsSync(path.join(dir, 'domain-transaction.bin')));
    } finally { fs.rmSync(dir, { recursive: true, force: true }); }

    console.log('local-core snapshot sync tests passed');
})().catch((error) => { console.error(error); process.exitCode = 1; });