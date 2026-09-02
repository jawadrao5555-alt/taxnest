'use strict';
/* Plain Node tests: node test/local-core.test.js */
const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { EventStore } = require('../src/local-core/event-store');
const { validateEvent, PROTOCOL_VERSION } = require('../src/local-core/protocol');
const { CloudSyncClient } = require('../src/local-core/cloud-sync');

let passed = 0;
async function it(name, fn) {
    try { await fn(); passed++; console.log('  ok  ' + name); }
    catch (e) { process.exitCode = 1; console.error('  FAIL  ' + name + ': ' + e.message); }
}
function event(id) {
    return { v: PROTOCOL_VERSION, id: id, type: 'sale.created', at_ms: 1700000000000, payload: { sale_id: 12 } };
}

(async function () {
    console.log('Local Core tests');
    await it('strictly validates the versioned event contract', function () {
        assert.throws(() => validateEvent({}), /version/);
        assert.throws(() => validateEvent({ ...event('event-0001'), v: 2 }), /version/);
        assert.throws(() => validateEvent({ ...event('event-0001'), type: 'anything' }), /type/);
        assert.deepStrictEqual(validateEvent(event('event-0001')), event('event-0001'));
        const id64 = 'e' + 'a'.repeat(63);
        assert.strictEqual(validateEvent(event(id64)).id, id64, '64-character event id must be accepted');
        assert.throws(() => validateEvent(event('e' + 'a'.repeat(64))), (e) => e.code === 'invalid_event_id',
            '65-character event id must be rejected');
    });

    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-'));
    await it('is durable, append-only, and idempotent across restart', function () {
        const store = new EventStore({ dataDir: dir });
        assert.strictEqual(store.append(event('event-0001')).duplicate, false);
        assert.strictEqual(store.append(event('event-0001')).duplicate, true);
        assert.strictEqual(store.pending().length, 1);
        assert.strictEqual(store.markSent(['event-0001']), 1);
        assert.strictEqual(store.pending().length, 0);
        const restarted = new EventStore({ dataDir: dir });
        assert.strictEqual(restarted.status().event_count, 1);
        assert.strictEqual(restarted.pending().length, 0);
        assert.strictEqual(restarted.append(event('event-0001')).duplicate, true);
    });

    await it('quarantines a corrupt journal and refuses new writes', function () {
        const bad = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-bad-'));
        fs.writeFileSync(path.join(bad, 'events.ndjson'), '{"not":"a signed record"}\n');
        const store = new EventStore({ dataDir: bad });
        assert.strictEqual(store.status().read_only, true);
        assert.ok(store.status().corruption);
        assert.throws(() => store.append(event('event-0002')), (e) => e.code === 'store_read_only');
        assert.ok(fs.readdirSync(bad).some((n) => n.indexOf('events.ndjson.corrupt-') === 0));
        fs.rmSync(bad, { recursive: true, force: true });
    });

    await it('coalesces concurrent cloud drains into one request', async function () {
        const syncDir = fs.mkdtempSync(path.join(os.tmpdir(), 'local-core-sync-'));
        const store = new EventStore({ dataDir: syncDir });
        store.append(event('event-0003'));
        store.append(event('event-0004'));
        let calls = 0;
        let wire = null;
        const client = new CloudSyncClient({
            store,
            deviceUid: 'dev-test-1',
            request: async (body) => {
                calls++;
                await new Promise((resolve) => setTimeout(resolve, 15));
                wire = body;
                return { acknowledged_ids: [body.events[0].event_id, 'not-submitted-ever'] };
            },
        });
        const [a, b] = await Promise.all([client.sync(), client.sync()]);
        assert.strictEqual(calls, 1);
        assert.strictEqual(a.sent, 1);
        assert.strictEqual(b.sent, 1);
        assert.deepStrictEqual(wire, {
            version: 1,
            device_uid: 'dev-test-1',
            events: [
                { event_id: 'event-0003', event_type: 'sale.created', occurred_at: '2023-11-14T22:13:20.000Z', idempotency_key: 'event-0003', payload: { sale_id: 12 } },
                { event_id: 'event-0004', event_type: 'sale.created', occurred_at: '2023-11-14T22:13:20.000Z', idempotency_key: 'event-0004', payload: { sale_id: 12 } },
            ],
        });
        assert.strictEqual(store.status().pending_count, 1, 'unknown ACK must not mark arbitrary entries sent');
        fs.rmSync(syncDir, { recursive: true, force: true });
    });
    fs.rmSync(dir, { recursive: true, force: true });
    console.log(passed + ' passed' + (process.exitCode ? ' — WITH FAILURES' : ''));
    process.exit(process.exitCode || 0);
})();