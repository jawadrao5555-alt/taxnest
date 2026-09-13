'use strict';

/*
 * Deterministic Local Core internet-cut / reconnect KOT integration harness.
 *
 * This is the project-runnable equivalent of local-core-internet-cut.test.js
 * when Electron is not installed by repository setup. It drives the same
 * production modules — LocalCoreDomain, EventStore outbox, CloudSyncClient,
 * local print_queue, drainLocalKotQueue — against a real HTTP cloud mock
 * that can cut the socket, lose an acknowledgement, and reconnect.
 *
 * Not a fake/unit-only substitute: every hold, outbox event, print claim,
 * cloud handoff clock, and print.complete / print.fail ack is the live
 * Local Core path.
 */
const assert = require('assert');
const crypto = require('crypto');
const fs = require('fs');
const http = require('http');
const os = require('os');
const path = require('path');
const { LocalCoreDomain } = require('../src/local-core/domain');
const { CloudSyncClient } = require('../src/local-core/cloud-sync');
const { laravelJson } = require('../src/local-core/lease-chain');
const { drainLocalKotQueue } = require('../src/local-kot');
const holdFixture = require('../../tests/Fixtures/local-core-held-order.json');

const KEY = crypto.createHash('sha256').update('local-core-internet-cut-harness').digest();
const scope = { company_id: '41', branch_id: '7', device_id: 'harness-device-01', user_id: '19' };
const lease = {
    lease_id: 7001,
    token: 'L'.repeat(80),
    expires_at: '2099-01-01T00:00:00.000Z',
    signing_secret: Buffer.alloc(32, 0x41).toString('base64url'),
    next_sequence: 1,
    prev_hash: '0'.repeat(64),
    allowed_actions: [
        'order.hold', 'order.claim', 'order.cancel', 'order.settle',
        'print.enqueue', 'print.claim', 'print.complete', 'print.fail',
    ],
    scope,
    owner: true,
};
const kotDocument = {
    kind: 'kot', order_id: holdFixture.aggregate_id, order_type: 'dine_in',
    table_label: 'T-70', token_label: null, order_label: 'L-FX01',
    waiter_name: 'Harness Waiter', customer_name: null,
    kitchen_notes: 'no onions', priority: false,
    lines: holdFixture.snapshot.lines.map((line) => ({
        line_id: line.line_id, name: line.name, quantity: line.quantity, special_notes: null,
    })),
};

let clock = 1700000000000;
let serial = 0;
function command(type, aggregate, revision, payload) {
    serial += 1;
    return {
        v: 1, id: 'harness-' + String(serial).padStart(6, '0'), type,
        aggregate_id: aggregate, expected_revision: revision, at_ms: clock + serial,
        scope, payload: payload || {},
    };
}
function filesUnder(dir) {
    if (!fs.existsSync(dir)) return [];
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const target = path.join(dir, entry.name);
        return entry.isDirectory() ? filesUnder(target) : [target];
    });
}

function cloud() {
    const state = {
        cut: false, loseResponse: false, attempts: 0, syncRequests: 0,
        projected: new Map(), chainHead: lease.prev_hash, chainSequence: 0,
        eventTypes: [],
    };
    function verifyEvent(event) {
        assert.deepStrictEqual(event.scope, scope, 'event scope must match the issued lease');
        if (!String(event.payload && event.payload.schema || '').startsWith('local-core.')) return;
        const chain = event.lease_chain;
        assert.ok(chain, 'Local Core domain event requires a signed lease chain');
        const unsigned = {
            event_id: event.event_id, event_type: event.event_type, occurred_at: event.occurred_at,
            idempotency_key: event.idempotency_key, scope: event.scope, payload: event.payload,
            lease_id: lease.lease_id, sequence: Number(chain.sequence), prev_hash: chain.prev_hash,
        };
        const signature = crypto.createHmac('sha256', Buffer.from(lease.signing_secret))
            .update(laravelJson(unsigned)).digest('hex');
        assert.strictEqual(chain.signature, signature, 'lease-chain HMAC must verify');
        const nextHash = crypto.createHash('sha256')
            .update(laravelJson(unsigned) + ':' + signature).digest('hex');
        if (!state.projected.has(event.event_id)) {
            assert.strictEqual(Number(chain.sequence), state.chainSequence + 1, 'lease chain sequence gap');
            assert.strictEqual(chain.prev_hash, state.chainHead, 'lease chain previous hash mismatch');
            state.chainSequence = Number(chain.sequence);
            state.chainHead = nextHash;
        }
    }
    const server = http.createServer((req, res) => {
        const body = [];
        req.on('data', (c) => body.push(c));
        req.on('end', () => {
            if (req.url !== '/v2/events') { res.writeHead(404).end(); return; }
            state.syncRequests += 1;
            if (state.cut) { req.socket.destroy(); return; }
            const payload = body.length ? JSON.parse(Buffer.concat(body).toString()) : {};
            assert.strictEqual(payload.device_uid, scope.device_id);
            assert.strictEqual(payload.version, 1);
            state.attempts += 1;
            (payload.events || []).forEach((event) => {
                verifyEvent(event);
                state.eventTypes.push(event.event_type);
                if (event.event_type === 'order.held') {
                    const lines = event.payload && event.payload.data && event.payload.data.kot_document
                        && event.payload.data.kot_document.lines;
                    assert.ok(Array.isArray(lines) && lines.length === kotDocument.lines.length,
                        'cloud order.held must carry the KOT the shop PC is printing');
                }
                if (!state.projected.has(event.event_id)) {
                    state.projected.set(event.event_id, {
                        event_id: event.event_id, status: 'projected',
                        event_type: event.event_type,
                    });
                }
            });
            if (state.loseResponse) { state.loseResponse = false; req.socket.destroy(); return; }
            res.setHeader('content-type', 'application/json');
            res.end(JSON.stringify({
                acknowledged_ids: payload.events.map((e) => e.event_id),
                results: payload.events.map((e) => state.projected.get(e.event_id)),
            }));
        });
    });
    return { server, state };
}

function engineAt(dataDir, printSettings) {
    const engine = new LocalCoreDomain({
        dataDir, encryptionKey: KEY, authorityScope: scope, authority: lease, now: () => clock,
    });
    const payload = {
        catalog: {
            revision: 1,
            products: [{ id: '51', revision: 4 }, { id: '77', revision: 5 }, { id: '50', revision: 3 }],
            ingredients: [{ id: '51', revision: 1 }, { id: 'ingredient-4', revision: 8 }],
            tables: [{ id: '70', revision: 2, table_number: 'T70' }],
        },
        stock: { '51': 5, 'ingredient-4': 5 },
        recipes: { '50': [{ stock_id: 'ingredient-4', quantity: 1 }] },
        customers: {}, tables: {}, orders: {}, sales: {}, cash_days: {}, staff_sessions: {},
        settings: { print: printSettings || { kot_printer: 'Kitchen-80', silent_print_enabled: true } },
    };
    engine.importSnapshot({
        schema: 'local-core.snapshot.v1', revision: 1, scope,
        hash: crypto.createHash('sha256').update(JSON.stringify(payload)).digest('hex'),
        payload,
    });
    return engine;
}

function holdOnce(engine, orderId, snapshot, kot) {
    const payload = {
        order_snapshot: Object.assign({}, snapshot, { order_id: orderId }),
        kot_document: kot,
    };
    const cmd = command('order.hold', orderId, 0, payload);
    cmd.id = 'order-hold:' + String((snapshot && snapshot.idempotency_key) || orderId);
    return engine.execute(cmd);
}

(async function run() {
    const mock = cloud();
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'pra-core-cut-harness-'));
    let engine;
    try {
        await new Promise((resolve, reject) => mock.server.listen(0, '127.0.0.1', (e) => e ? reject(e) : resolve()));
        const origin = 'http://127.0.0.1:' + mock.server.address().port;
        engine = engineAt(root);
        const printSettings = ((engine.snapshot().settings || {}).print) || {};
        assert.strictEqual(printSettings.kot_printer, 'Kitchen-80');

        const snapshot = Object.assign({ catalog_revision: 1 }, holdFixture.snapshot);
        const first = holdOnce(engine, holdFixture.aggregate_id, snapshot, kotDocument);
        const replay = holdOnce(engine, holdFixture.aggregate_id, snapshot, kotDocument);
        assert.strictEqual(first.duplicate, false, 'first hold must commit: ' + JSON.stringify(first));
        assert.strictEqual(replay.duplicate, true, 'replayed hold must not invent a second kitchen slip');
        const kotJobs = Object.entries(engine.snapshot().print_queue || {}).filter(([, job]) => job && job.kind === 'kot');
        assert.strictEqual(kotJobs.length, 1, 'exactly one local KOT job');
        assert.strictEqual(kotJobs[0][0], 'kot:' + holdFixture.aggregate_id);
        assert.strictEqual(kotJobs[0][1].local_only, true);
        assert.strictEqual(engine.eventStore.pending(20).some((e) => String(e.payload.aggregate_id).startsWith('kot:')), false,
            'the local print job itself never becomes an outbox event');

        const client = new CloudSyncClient({
            store: engine.eventStore,
            deviceUid: scope.device_id,
            request: async (wire) => {
                const res = await fetch(origin + '/v2/events', {
                    method: 'POST',
                    headers: { 'content-type': 'application/json' },
                    body: JSON.stringify(wire),
                });
                if (!res.ok) throw new Error('cloud_sync_http_' + res.status);
                return res.json();
            },
        });

        mock.state.cut = true;
        const cut = await client.sync();
        assert.strictEqual(cut.ok, false, 'cut lane must fail closed');
        assert.ok(engine.eventStore.pending(20).some((e) => e.type === 'order.held'), 'hold stays in the outbox while cut');
        const cutAttempts = mock.state.syncRequests;
        assert.ok(cutAttempts >= 1, 'cut cloud submit was attempted');

        let prints = 0;
        const drainDeps = {
            printHtml: async () => { prints += 1; return { success: true }; },
            deviceId: scope.device_id, now: () => clock, log: () => {}, scope,
        };
        let drained = await drainLocalKotQueue(engine, drainDeps);
        assert.strictEqual(drained.printed, 1, 'offline shop PC prints the kitchen slip itself');
        assert.strictEqual(drained.acked, 1, 'print.complete is queued for cloud handoff close');
        assert.strictEqual(prints, 1);
        const ackPending = engine.eventStore.pending(50).filter((e) => String(e.payload.aggregate_id).startsWith('kot:'));
        assert.strictEqual(ackPending.length, 1);
        assert.strictEqual(ackPending[0].payload.command_type, 'print.complete');

        mock.state.cut = false;
        mock.state.loseResponse = true;
        const lost = await client.sync();
        assert.strictEqual(lost.ok, false, 'lost acknowledgement must not mark the outbox sent');
        const retry = await client.sync();
        assert.strictEqual(retry.ok, true);
        assert.ok(retry.sent >= 2, 'reconnect delivers the hold and the print ack');
        assert.strictEqual(mock.state.projected.size, 2, 'exactly one hold + one print.complete after reconnect');
        assert.deepStrictEqual(
            Array.from(mock.state.projected.values()).map((r) => r.event_type).sort(),
            ['order.held', 'print.completed'].sort(),
        );
        drained = await drainLocalKotQueue(engine, drainDeps);
        assert.deepStrictEqual([drained.printed, drained.acked, prints], [0, 0, 1], 'completed slip never reprints');

        for (const file of filesUnder(root)) {
            const bytes = fs.readFileSync(file);
            assert.strictEqual(bytes.includes(Buffer.from('Burger Combo')), false, 'plaintext held order on disk');
            assert.strictEqual(bytes.includes(Buffer.from(holdFixture.aggregate_id)), false, 'plaintext identity on disk');
        }

        // Printer unavailable after the cloud accepted the hold → instant hand-back.
        clock += 1000;
        const failDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pra-core-cut-fail-'));
        const failEngine = engineAt(failDir);
        holdOnce(failEngine, 'fail-order-1', Object.assign({}, holdFixture.snapshot, {
            order_id: 'fail-order-1', table_id: '70',
            lines: holdFixture.snapshot.lines.map((line) => Object.assign({}, line, {
                line_id: line.line_id + '-f',
            })),
        }), Object.assign({}, kotDocument, {
            order_id: 'fail-order-1',
            lines: kotDocument.lines.map((line) => Object.assign({}, line, { line_id: line.line_id + '-f' })),
        }));
        failEngine.eventStore.now = () => clock;
        const held = failEngine.eventStore.pending(20).find((e) => e.type === 'order.held');
        failEngine.eventStore.markSent([held.id], {});
        clock += 6000;
        let failPrints = 0;
        const failResult = await drainLocalKotQueue(failEngine, {
            printHtml: async () => { failPrints += 1; return { success: false, error: 'Printer offline' }; },
            deviceId: scope.device_id, now: () => clock, log: () => {}, scope,
        });
        assert.deepStrictEqual([failResult.handed_back, failPrints], [1, 1], 'synced + local fail: instant cloud failover');
        const failAck = failEngine.eventStore.pending(20).find((e) => e.payload && e.payload.command_type === 'print.fail');
        assert.ok(failAck, 'terminal print.fail rides the outbox so the cloud can print');
        assert.strictEqual(failAck.payload.data.terminal, true);
        failEngine.close();
        fs.rmSync(failDir, { recursive: true, force: true });

        // Agent crash after cloud accept, before drain: slip stays queued, no second job, no invented ack.
        clock += 1000;
        const crashDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pra-core-cut-crash-'));
        let crash = engineAt(crashDir);
        holdOnce(crash, 'crash-order-1', Object.assign({}, holdFixture.snapshot, {
            order_id: 'crash-order-1',
            lines: holdFixture.snapshot.lines.map((line) => Object.assign({}, line, { line_id: line.line_id + '-c' })),
        }), Object.assign({}, kotDocument, {
            order_id: 'crash-order-1',
            lines: kotDocument.lines.map((line) => Object.assign({}, line, { line_id: line.line_id + '-c' })),
        }));
        const crashHeld = crash.eventStore.pending(20).find((e) => e.type === 'order.held');
        crash.eventStore.markSent([crashHeld.id], {});
        crash.close();
        crash = new LocalCoreDomain({
            dataDir: crashDir, encryptionKey: KEY, authorityScope: scope, authority: lease, now: () => clock,
        });
        const crashJob = crash.snapshot().print_queue['kot:crash-order-1'];
        assert.strictEqual(crashJob.status, 'queued', 'crashed agent keeps the local slip');
        assert.strictEqual(crash.eventStore.pending(20).some((e) => String(e.payload.aggregate_id) === 'kot:crash-order-1'), false,
            'a crash before drain must not invent print.complete');
        crash.close();
        fs.rmSync(crashDir, { recursive: true, force: true });

        // Concurrent 8-order burst: one local KOT each, replay does not duplicate.
        clock += 1000;
        const burstDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pra-core-cut-burst-'));
        const burst = engineAt(burstDir);
        const burstIds = [];
        for (let n = 1; n <= 8; n++) {
            const orderId = 'burst-' + n;
            const snap = Object.assign({}, holdFixture.snapshot, {
                order_id: orderId, table_id: null,
                lines: [{
                    line_id: orderId + '-l1', product_id: '51', product_revision: 4, name: 'Chai',
                    quantity: 1, unit_price_cents: 100, has_recipe: false, recipe_snapshot: [],
                    deal_snapshot: [], direct_consumption_snapshot: [],
                    tax_snapshot: { rate_basis_points: 1600 },
                }],
                totals: { subtotal_cents: 100, tax_cents: 16, discount_cents: 0, total_cents: 116 },
            });
            const kot = {
                kind: 'kot', order_id: orderId, order_type: 'takeaway', order_label: 'B-' + n,
                lines: [{ line_id: orderId + '-l1', name: 'Chai', quantity: 1 }],
            };
            const holdCmd = command('order.hold', orderId, 0, { order_snapshot: snap, kot_document: kot });
            holdCmd.id = 'order-hold:' + orderId;
            burst.execute(holdCmd);
            burst.execute(holdCmd);
            burstIds.push(orderId);
        }
        const burstKots = Object.values(burst.snapshot().print_queue).filter((j) => j && j.kind === 'kot');
        assert.strictEqual(burstKots.length, 8, 'one local KOT per burst order');
        burst.close();
        fs.rmSync(burstDir, { recursive: true, force: true });

        console.log('PASS Local Core internet-cut/reconnect KOT integration harness (outbox, print queue, cloud handoff, reconnect, ack, crash, burst, duplicate guard)');
        console.log(JSON.stringify({
            cut_sync_requests: cutAttempts,
            reconnect_attempts: mock.state.attempts,
            projected: mock.state.projected.size,
            local_prints: prints,
        }));
    } catch (error) {
        console.error('Local Core internet-cut harness failed:', error && error.stack ? error.stack : error);
        process.exitCode = 1;
    } finally {
        if (engine) try { engine.close(); } catch (e) {}
        if (typeof mock.server.closeAllConnections === 'function') mock.server.closeAllConnections();
        await new Promise((resolve) => mock.server.close(resolve));
        fs.rmSync(root, { recursive: true, force: true, maxRetries: 10, retryDelay: 100 });
    }
})();
