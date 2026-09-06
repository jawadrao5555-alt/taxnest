'use strict';

/* Pure Node tests: node test/local-kot.test.js
 * Offline KOT: order.hold(kot_document) → local print_queue → agent silent print. */
const assert = require('assert');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { LocalCoreDomain } = require('../src/local-core/domain');
const { renderKotHtml, planKotPrints, drainLocalKotQueue } = require('../src/local-kot');

const KEY = crypto.createHash('sha256').update('local-kot-tests').digest();
const scope = { company_id: 'co-1', branch_id: 'br-1', device_id: 'dev-1', user_id: 'waiter-7' };
let serial = 0;
function command(type, aggregate, revision, payload) {
    serial++;
    return { v: 1, id: 'command-' + String(serial).padStart(6, '0'), type, aggregate_id: aggregate,
        expected_revision: revision, at_ms: 1700000000000 + serial, scope, payload: payload || {} };
}
function ownerAuthority() {
    return { lease_id: 17, token: 'opaque-owner-token', signing_secret: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
        next_sequence: 1, prev_hash: '0'.repeat(64), scope, owner: true, allowed_actions: ['*'] };
}
let clock = 1700000100000;
function engineWith(settings) {
    const dataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'local-kot-'));
    const engine = new LocalCoreDomain({ dataDir, encryptionKey: KEY, authorityScope: scope, authority: ownerAuthority(), now: () => clock });
    const payload = { catalog: { revision: 1, products: [{ id: 'tea', revision: 4 }], ingredients: [], tables: [{ id: '4', revision: 1, table_number: 'T4' }] },
        orders: {}, sales: {}, tables: {}, stock: {}, recipes: {}, customers: {}, cash_days: {}, staff_sessions: {},
        settings: settings || {} };
    engine.importSnapshot({ schema: 'local-core.snapshot.v1', revision: 1, scope,
        hash: crypto.createHash('sha256').update(JSON.stringify(payload)).digest('hex'), payload });
    return engine;
}
function hold(engine, orderId, orderType, withKot) {
    const line = { line_id: orderId + '-l1', product_id: 'tea', product_revision: 4, name: 'Tea', quantity: 2,
        unit_price_cents: 100, tax_snapshot: { rate_basis_points: 1600 }, recipe_snapshot: [], deal_snapshot: [],
        direct_consumption_snapshot: [] };
    const payload = { order_snapshot: { order_id: orderId, business_date: '2026-09-06', order_type: orderType,
        table_id: orderType === 'dine_in' ? '4' : null, table_revision: orderType === 'dine_in' ? 1 : null,
        lines: [line], totals: { subtotal_cents: 200, tax_cents: 0, discount_cents: 0, total_cents: 200 }, catalog_revision: 1 } };
    if (withKot) payload.kot_document = { kind: 'kot', order_id: orderId, order_type: orderType,
        table_label: orderType === 'dine_in' ? 'T4' : null, order_label: 'L-' + orderId, waiter_name: 'Ali',
        kitchen_notes: 'jaldi', lines: [{ line_id: orderId + '-l1', name: 'Tea <hot>', quantity: 2, special_notes: 'kam cheeni' }] };
    return engine.execute(command('order.hold', orderId, 0, payload));
}

(async function run() {
    // Renderer: escapes, shows the offline marker, table, notes, qty column.
    const html = renderKotHtml({ kind: 'kot', order_type: 'dine_in', table_label: 'T4', order_label: 'L-9',
        kitchen_notes: 'jaldi', lines: [{ line_id: 'a', name: 'Tea <hot>', quantity: 2, special_notes: 'kam cheeni' }] },
        { settings: { kot_left_margin_mm: 4 }, printedAtMs: 1700000000000 });
    assert.ok(html.includes('Tea &lt;hot&gt;'), 'item names are HTML-escaped');
    assert.ok(html.includes('TABLE T4') && html.includes('DINE-IN') && html.includes('kam cheeni') && html.includes('NOTE: jaldi'));
    assert.ok(html.includes('Offline slip'), 'kitchen sees it was printed by the shop PC');
    assert.ok(html.includes('margin-left: 4mm'), 'left-margin setting honoured');

    // Routing plan: no printer → nothing; counter copy only for dine-in when enabled.
    assert.deepStrictEqual(planKotPrints({ document: { order_type: 'dine_in' } }, {}), []);
    assert.deepStrictEqual(planKotPrints({ document: { order_type: 'dine_in' } }, { kot_printer: 'K1', silent_print_enabled: false }), []);
    assert.deepStrictEqual(planKotPrints({ document: { order_type: 'takeaway' } },
        { kot_printer: 'K1', counter_kot_enabled: true, counter_kot_printer: 'C1' }).map((p) => p.printer), ['K1']);
    assert.deepStrictEqual(planKotPrints({ document: { order_type: 'dine_in' } },
        { kot_printer: 'K1', counter_kot_enabled: true, counter_kot_printer: 'C1' }).map((p) => p.printer), ['K1', 'C1']);

    // Drain: one kitchen slip per offline hold, counter copy for dine-in, never twice.
    const engine = engineWith({ print: { kot_printer: 'Kitchen-80', counter_kot_enabled: true, counter_kot_printer: 'Counter-80', silent_print_enabled: true } });
    hold(engine, 'o-dine', 'dine_in', true);
    hold(engine, 'o-take', 'takeaway', true);
    hold(engine, 'o-nokot', 'takeaway', false);
    const prints = [];
    let failNext = 1;
    const printHtml = async (h, printer, type) => {
        prints.push({ printer, type, offline: h.includes('Offline slip'), copy: h.includes('COUNTER COPY') });
        if (failNext > 0) { failNext--; return { success: false, error: 'Printer offline' }; }
        return { success: true };
    };
    const deps = { printHtml, deviceId: 'dev-1', now: () => clock, log: () => {} };
    let result = await drainLocalKotQueue(engine, deps);
    assert.strictEqual(prints.filter((p) => p.type === 'kot').length, prints.length, 'every slip prints as jobType kot');
    // First job failed once (printer offline) → requeued; second printed.
    assert.strictEqual(result.printed + result.failed, 2, 'only the two holds WITH a kot document are print jobs');
    assert.strictEqual(result.failed, 1);
    assert.strictEqual(result.printed, 1);
    const q = engine.snapshot().print_queue;
    const failedId = Object.keys(q).find((id) => q[id].status === 'queued');
    assert.ok(failedId, 'failed slip stays queued');
    assert.strictEqual(q[failedId].last_error, 'Printer offline');
    assert.ok(!q['kot:o-nokot'], 'a hold without a kot document never creates a slip');
    // Same tick again: the failed one is inside its backoff → nothing happens.
    result = await drainLocalKotQueue(engine, deps);
    assert.deepStrictEqual([result.printed, result.failed], [0, 0]);
    // Backoff over: it prints, and the completed one is NOT printed again.
    clock += 10 * 60 * 1000;
    const before = prints.length;
    result = await drainLocalKotQueue(engine, deps);
    assert.strictEqual(result.printed, 1);
    assert.strictEqual(Object.values(engine.snapshot().print_queue).filter((j) => j.status === 'completed').length, 2);
    const dineCopies = prints.slice(before).filter((p) => p.copy);
    const kitchenSlips = prints.filter((p) => p.printer === 'Kitchen-80');
    assert.strictEqual(prints.filter((p) => p.printer === 'Counter-80').length, 1, 'dine-in gets exactly one counter copy');
    assert.strictEqual(dineCopies.length <= 1, true);
    assert.strictEqual(kitchenSlips.length, 3, 'kitchen: dine (fail+retry) + takeaway = 3 attempts');
    assert.strictEqual(prints.length, 4, 'a failed kitchen slip never prints its counter copy early');
    result = await drainLocalKotQueue(engine, deps);
    assert.deepStrictEqual([result.printed, result.failed, prints.length], [0, 0, 4], 'completed slips never re-print');
    assert.strictEqual(engine.eventStore.pending(100).filter((e) => String(e.payload.aggregate_id).startsWith('kot:')).length, 0,
        'local slips never enter the cloud outbox');

    // No printer settings yet (snapshot not fetched): slip is kept, not dropped.
    const bare = engineWith({});
    hold(bare, 'o-bare', 'takeaway', true);
    const bareDeps = { printHtml: async () => ({ success: true }), deviceId: 'dev-1', now: () => clock, log: () => {} };
    result = await drainLocalKotQueue(bare, bareDeps);
    assert.deepStrictEqual([result.printed, result.failed], [0, 1]);
    assert.strictEqual(bare.snapshot().print_queue['kot:o-bare'].last_error, 'kot_printer_not_configured');
    assert.strictEqual(bare.snapshot().print_queue['kot:o-bare'].status, 'queued');
    engine.close(); bare.close();

    // ── Durable ack + bounded handoff (cloud stamps only after a REAL print) ──
    const kotEvents = (eng) => eng.eventStore.pending(100).filter((e) => String(e.payload.aggregate_id).startsWith('kot:'));
    const heldEvent = (eng, orderId) => eng.eventStore.pending(100).find((e) => e.type === 'order.held' && String(e.payload.aggregate_id) === orderId);
    const printSettings = { print: { kot_printer: 'Kitchen-80', silent_print_enabled: true } };

    // 1) Successful print → ONE print.complete (kind kot, order_id, printed_at_ms) rides the outbox; never twice.
    const ackEng = engineWith(printSettings);
    hold(ackEng, 'o-ack', 'takeaway', true);
    let ackPrints = 0;
    const ackDeps = { printHtml: async () => { ackPrints++; return { success: true }; }, deviceId: 'dev-1', now: () => clock, log: () => {}, scope };
    result = await drainLocalKotQueue(ackEng, ackDeps);
    assert.deepStrictEqual([result.printed, result.acked, result.handed_back, ackPrints], [1, 1, 0, 1]);
    let acks = kotEvents(ackEng);
    assert.strictEqual(acks.length, 1, 'exactly one durable ack for one printed slip');
    assert.strictEqual(acks[0].type, 'print.completed');
    assert.strictEqual(acks[0].payload.command_type, 'print.complete');
    assert.strictEqual(acks[0].payload.aggregate_id, 'kot:o-ack');
    assert.deepStrictEqual([acks[0].payload.data.kind, acks[0].payload.data.order_id], ['kot', 'o-ack']);
    assert.strictEqual(acks[0].payload.data.printed_at_ms, clock);
    assert.strictEqual(ackEng.snapshot().print_queue['kot:o-ack'].status, 'completed');
    result = await drainLocalKotQueue(ackEng, ackDeps);
    assert.deepStrictEqual([result.printed, result.acked, ackPrints, kotEvents(ackEng).length], [0, 0, 1, 1], 'completed slip: no reprint, no second ack');
    ackEng.close();

    // 2) Hold already ACCEPTED by the cloud for longer than the handoff window
    //    and still unprinted (printer dead) → hand back WITHOUT printing:
    //    print.fail{terminal:true}, job failed for good, cloud prints it.
    const backEng = engineWith(printSettings);
    hold(backEng, 'o-back', 'takeaway', true);
    let backPrints = 0;
    const backDeps = { printHtml: async () => { backPrints++; return { success: false, error: 'Printer offline' }; }, deviceId: 'dev-1', now: () => clock, log: () => {}, scope };
    result = await drainLocalKotQueue(backEng, backDeps);
    assert.deepStrictEqual([result.failed, result.handed_back, backPrints], [1, 0, 1], 'unsynced hold: keeps trying the local printer');
    backEng.eventStore.now = () => clock; // outbox stamps use the same wall clock as the drain
    backEng.eventStore.markSent([heldEvent(backEng, 'o-back').id], {});
    const syncedAt = clock;
    clock += 2 * 60 * 1000;
    result = await drainLocalKotQueue(backEng, backDeps);
    assert.deepStrictEqual([result.failed, result.handed_back, backPrints], [1, 0, 2], 'inside the window: still ours to print');
    clock = syncedAt + 3 * 60 * 1000 + 10 * 60 * 1000; // window over (and past retry backoff)
    result = await drainLocalKotQueue(backEng, backDeps);
    assert.deepStrictEqual([result.failed, result.handed_back, backPrints], [0, 1, 2], 'window over: handed back, printer NOT tried again');
    const backJob = backEng.snapshot().print_queue['kot:o-back'];
    assert.strictEqual(backJob.status, 'failed');
    assert.strictEqual(backJob.last_error, 'local_kot_handoff_timeout');
    acks = kotEvents(backEng);
    assert.strictEqual(acks.length, 1);
    assert.strictEqual(acks[0].payload.command_type, 'print.fail');
    assert.strictEqual(acks[0].payload.data.terminal, true);
    assert.deepStrictEqual([acks[0].payload.data.kind, acks[0].payload.data.order_id], ['kot', 'o-back']);
    result = await drainLocalKotQueue(backEng, backDeps);
    assert.deepStrictEqual([result.failed, result.handed_back, backPrints, kotEvents(backEng).length], [0, 0, 2, 1], 'a handed-back slip is never retried locally');
    backEng.close();

    // 3) Internet still down (hold never accepted): no handoff clock runs —
    //    the shop PC is the only printer there is, so it keeps retrying.
    const downEng = engineWith(printSettings);
    hold(downEng, 'o-down', 'takeaway', true);
    let downPrints = 0;
    const downDeps = { printHtml: async () => { downPrints++; return { success: downPrints >= 3, error: 'Printer offline' }; }, deviceId: 'dev-1', now: () => clock, log: () => {}, scope };
    for (let i = 0; i < 3; i++) { clock += 60 * 60 * 1000; result = await drainLocalKotQueue(downEng, downDeps); }
    assert.strictEqual(downPrints, 3, 'hours after an unsynced hold it still tries locally');
    assert.strictEqual(result.printed, 1);
    assert.strictEqual(downEng.snapshot().print_queue['kot:o-down'].status, 'completed');
    assert.strictEqual(kotEvents(downEng).filter((e) => e.payload.command_type === 'print.complete').length, 1);
    downEng.close();

    // 4) Agent restart between hold and drain: the slip survives in the
    //    journal and prints exactly once after the restart (one ack).
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'local-kot-restart-'));
    const mk = () => {
        const eng = new LocalCoreDomain({ dataDir: dir, encryptionKey: KEY, authorityScope: scope, authority: ownerAuthority(), now: () => clock });
        return eng;
    };
    let first = mk();
    const payload = { catalog: { revision: 1, products: [{ id: 'tea', revision: 4 }], ingredients: [], tables: [] },
        orders: {}, sales: {}, tables: {}, stock: {}, recipes: {}, customers: {}, cash_days: {}, staff_sessions: {}, settings: printSettings };
    first.importSnapshot({ schema: 'local-core.snapshot.v1', revision: 1, scope,
        hash: crypto.createHash('sha256').update(JSON.stringify(payload)).digest('hex'), payload });
    hold(first, 'o-restart', 'takeaway', true);
    first.close();
    const second = mk();
    assert.strictEqual(second.snapshot().print_queue['kot:o-restart'].status, 'queued', 'slip survives the restart');
    let restartPrints = 0;
    const restartDeps = { printHtml: async () => { restartPrints++; return { success: true }; }, deviceId: 'dev-1', now: () => clock, log: () => {}, scope };
    result = await drainLocalKotQueue(second, restartDeps);
    assert.deepStrictEqual([result.printed, result.acked, restartPrints], [1, 1, 1]);
    assert.strictEqual(kotEvents(second).length, 1);
    second.close();
    console.log('local-kot tests passed');
})().catch((e) => { console.error(e); process.exit(1); });
