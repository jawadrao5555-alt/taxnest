'use strict';

/*
 * Pure Node test: node test/local-core-counter-settle.test.js
 *
 * Offline waiter/counter lane, counter side: a waiter tablet holds an order
 * on the shop PC while the net is down (waiter-local-core.js fallback with a
 * recipe-backed product), the counter maps the Local Core `orders` projection
 * into its held-orders list, recalls it and settles cash locally through the
 * SAME client normalizer the sale screen uses. The domain must accept the
 * settlement and the outbox must carry a durable `order.settled` whose sale
 * lines are the frozen held lines VERBATIM (the cloud's held-snapshot match).
 */
const assert = require('assert');
const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');
const vm = require('vm');
const { LocalCoreDomain } = require('../src/local-core/domain');
// Values cross vm realms (Array/Object prototypes differ) — compare by JSON.
const same = (a, b, msg) => assert.strictEqual(JSON.stringify(a), JSON.stringify(b), msg);

const root = path.join(__dirname, '..', '..');
const KEY = crypto.createHash('sha256').update('local-core-counter-settle').digest();
const scope = { company_id: 'co-1', branch_id: 'br-1', device_id: 'dev-1', user_id: 'user-1' };
const authority = { lease_id: 17, token: 'opaque-owner-token', signing_secret: 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
    next_sequence: 1, prev_hash: '0'.repeat(64), scope, owner: true, allowed_actions: ['*'] };

// ── Shop PC domain with a recipe-backed product and a plain one ──────────
const products = [
    { id: 5, revision: 3, name: 'Tea', is_tax_exempt: false },
    { id: 6, revision: 1, name: 'Water', is_tax_exempt: true },
];
const tables = [{ id: 4, revision: 6, table_number: 'T4' }];
const recipes = { '5': [{ stock_id: 'ingredient-9', ingredient_revision: 2, quantity: 1 }] };
const dataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'local-counter-settle-'));
const engine = new LocalCoreDomain({ dataDir, encryptionKey: KEY, authorityScope: scope, authority });
engine.importSnapshot({
    schema: 'local-core.snapshot.v1', revision: 1, scope,
    hash: crypto.createHash('sha256').update('counter-settle-baseline').digest('hex'),
    payload: { catalog: { revision: 1, products, ingredients: [{ id: 'ingredient-9', revision: 2, name: 'Tea leaves' }], tables },
        orders: {}, sales: {}, tables: {}, stock: { 'ingredient-9': 40 }, recipes, customers: {}, cash_days: {}, staff_sessions: {}, settings: {} },
});
let serial = 0;
function run(wire) {
    serial += 1;
    return engine.execute(Object.assign({ v: 1, at_ms: 1757145600000 + serial, scope }, wire, {
        id: wire.id || ('command-' + serial), expected_revision: Number(wire.expected_revision || 0),
    }));
}

// ── 1. Waiter tablet holds offline (production fallback asset) ───────────
const waiterCommands = [];
const waiterState = { catalog: { revision: 1, products, tables }, recipes };
const waiterCtx = { window: { TaxNestLocalCore: {
    query: () => JSON.stringify({ ok: true, result: waiterState }),
    command: (json) => {
        const wire = JSON.parse(json);
        waiterCommands.push(wire);
        const result = run(wire);
        return JSON.stringify({ ok: true, result });
    },
} }, console, Date, JSON, Object, Number, String, Array, Math };
vm.createContext(waiterCtx);
vm.runInContext(fs.readFileSync(path.join(root, 'public/js/waiter-local-core.js'), 'utf8'), waiterCtx);
const held = waiterCtx.window.TaxNestWaiterLocalCore.fallbackOrder({
    hold_uuid: 'waiter-hold-1', order_type: 'dine_in', table_id: 4, tax_rate_basis_points: 1600,
    tax_inclusive: false, tax_menu_rate_basis_points: null, kitchen_notes: 'rush',
    items: [
        { item_id: 5, name: 'Tea', quantity: 2, unit_price: 100, special_notes: 'less sugar' },
        { item_id: 6, name: 'Water', quantity: 1, unit_price: 50, is_tax_exempt: true },
    ],
}, null, { kot: true, waiterName: 'Ali', tableLabel: 'T4' });
assert.strictEqual(held.ok, true, 'offline waiter hold accepted by the shop PC');
assert.strictEqual(waiterCommands.length, 1);
const heldLines = waiterCommands[0].payload.order_snapshot.lines;
same(heldLines[0].tax_snapshot,
    { rate_basis_points: 1600, exempt: false, inclusive: false, menu_rate_basis_points: null },
    'waiter-held lines freeze the canonical tax_snapshot shape');
assert.strictEqual(heldLines[0].has_recipe, true);
assert.strictEqual(heldLines[0].recipe_snapshot[0].stock_id, 'ingredient-9');
assert.strictEqual(heldLines[1].has_recipe, false);
assert.strictEqual(heldLines[1].tax_snapshot.exempt, true);

// ── 2. Counter maps the `orders` projection into its held list ───────────
const desktopCalls = [];
const counterWindow = {
    nestposDesktop: { localCore: {
        version: 1,
        // Electron IPC structured-clones the wire; the vm realm boundary here
        // needs the same round-trip or plain-object checks see a foreign realm.
        command(wire) { wire = JSON.parse(JSON.stringify(wire)); desktopCalls.push(wire); try { return Promise.resolve(Object.assign({ ok: true, success: true, state: 'pending' }, run(wire))); }
            catch (e) { return Promise.resolve({ ok: false, success: false, state: 'rejected', error: e.code || e.message }); } },
        query(q) {
            const snap = engine.snapshot();
            if (q.projection === 'revisions') return Promise.resolve({ ok: true, state: 'local', data: engine.revision ? engine.revision(q.id) : (snap.revisions || {})[q.id] });
            return Promise.resolve({ ok: true, state: 'local', data: snap[q.projection] });
        },
    } },
    addEventListener() {}, dispatchEvent() {},
};
const counterCtx = {
    window: counterWindow,
    document: { documentElement: { setAttribute() {} }, body: { appendChild() {} }, createElement() { return { style: {}, setAttribute() {} }; }, addEventListener() {} },
    CustomEvent: function (name, init) { this.type = name; this.detail = init.detail; },
    AbortController, setTimeout, clearTimeout,
    fetch() { return Promise.reject(new TypeError('network down')); },
};
vm.runInNewContext(fs.readFileSync(path.join(root, 'public/js/nestpos-local-core.js'), 'utf8'), counterCtx);
const NestPosLocal = counterWindow.NestPosLocal;
assert.ok(NestPosLocal && NestPosLocal.offlineHeld, 'counter client exposes the offline held-order mappers');

const projection = engine.snapshot().orders['waiter-hold-1'];
assert.ok(projection && projection.status === 'open');
assert.ok(projection.kot_job_id, 'offline KOT job recorded on the order');
const row = NestPosLocal.offlineHeld.rowFromProjection(projection, {
    revision: 1, table_number: (id) => 'T' + id, staff_fallback: 'Shop PC',
});
assert.strictEqual(row.local, true);
assert.strictEqual(row.id, 'waiter-hold-1');
assert.strictEqual(row.table.table_number, 'T4');
assert.strictEqual(row.total_amount, 282, '2×100 @16% + 50 exempt = 282');
assert.strictEqual(row.items.length, 2);
assert.strictEqual(row.items[0].has_recipe, true, 'recipe applicability survives the counter mapping');
same(row.items[0].recipe_snapshot, [{ stock_id: 'ingredient-9', ingredient_revision: 2, quantity: 1 }]);
assert.strictEqual(row.items[0].deal_snapshot, null, 'non-deal lines carry no deal snapshot');
assert.strictEqual(row.items[1].is_tax_exempt, true);
assert.ok(row.items[0].kot_printed_at, 'counter sees the slip as already queued on the shop PC');
same(row.items[0].local_line, projection.lines[0], 'the complete immutable held line rides on the item');

// ── 3. Counter settles cash from the frozen lines ────────────────────────
const settlement = NestPosLocal.offlineHeld.settlementFromRow(row, { discount_cents: 0 });
assert.ok(settlement, 'settlement facts derive from the frozen lines');
assert.strictEqual(settlement.totals.subtotal, 250);
assert.strictEqual(settlement.totals.tax_amount, 32);
assert.strictEqual(settlement.totals.total_amount, 282);
assert.strictEqual(settlement.items[0].has_recipe, true);
assert.strictEqual(settlement.items[1].has_recipe, false);
same(settlement.items[1].recipe_snapshot, []);
for (const key of ['line_id', 'product_id', 'quantity', 'unit_price_cents']) {
    assert.strictEqual(String(settlement.items[0][key]), String(heldLines[0][key]), key + ' matches the held line');
}
for (const key of ['tax_snapshot', 'recipe_snapshot', 'direct_consumption_snapshot']) {
    same(settlement.items[0][key], heldLines[0][key], key + ' is carried verbatim');
}
// A card settlement keeps the hold-time rate: the cloud compares tax_snapshot
// with the frozen hold, so re-rating would strand the sale.
const asCard = NestPosLocal.offlineHeld.settlementFromRow(row, {});
assert.strictEqual(asCard.tax_pricing.rate_basis_points, 1600);

const sale = {
    offline_uuid: 'pay-1', business_date: '2026-09-06', payment_method: 'cash',
    incoming_order_id: null, recalled_order_id: null, table_id: 4, online_payment_confirmed: false,
    order_ref: { id: 'waiter-hold-1', order_number: row.order_number, order_type: 'dine_in', table_id: 4 },
    customer_ref: { id: null, name: null, phone: null }, customer_id: null, delivery_address: null,
    discount_type: 'amount', discount_value: 0, cash_received: 300, terminal_id: null,
    items: settlement.items, totals: settlement.totals, tax_pricing: settlement.tax_pricing,
};
const normalized = NestPosLocal.normalizeHeldSaleSnapshot('waiter-hold-1', sale);
assert.strictEqual(normalized.totals.total_cents, 28200);
assert.strictEqual(normalized.items[0].has_recipe, true);

(async () => {
    const result = await NestPosLocal.heldOrder.settleWithSale('waiter-hold-1', sale, { payment_method: 'cash' });
    assert.strictEqual(result.ok, true, 'local cash settlement accepted: ' + JSON.stringify(result));
    const after = engine.snapshot().orders['waiter-hold-1'];
    assert.strictEqual(after.status, 'settled');
    assert.strictEqual(engine.snapshot().stock['ingredient-9'], 38, 'recipe consumption applied on the shop PC (2 teas)');
    const pending = engine.eventStore.pending(50).map((e) => Object.assign({ type: e.type }, e.payload));
    const types = pending.filter((p) => p.aggregate_id === 'waiter-hold-1').map((p) => p.type);
    same(types, ['order.held', 'order.settled'],
        'outbox carries the hold and exactly one durable settlement for the cloud');
    const settled = pending.find((p) => p.type === 'order.settled');
    const saleItems = (settled.data.sale_snapshot || settled.data.sale).items;
    assert.strictEqual(saleItems.length, heldLines.length);
    saleItems.forEach((item, i) => {
        assert.strictEqual(String(item.line_id), String(heldLines[i].line_id));
        same(item.tax_snapshot, heldLines[i].tax_snapshot);
        same(item.recipe_snapshot, heldLines[i].recipe_snapshot);
        assert.strictEqual(item.has_recipe, heldLines[i].has_recipe);
    });
    assert.strictEqual(pending.some((p) => p.aggregate_id === 'kot:waiter-hold-1'), false,
        'the local KOT slip never enters the cloud outbox');
    // Settling twice is a no-op on the domain (already closed) — no second event.
    const again = await NestPosLocal.heldOrder.settleWithSale('waiter-hold-1', sale, { payment_method: 'cash' });
    assert.strictEqual(again.ok, false);
    assert.strictEqual(engine.eventStore.pending(50).filter((e) => e.type === 'order.settled').length, 1);
    fs.rmSync(dataDir, { recursive: true, force: true });

    // ── 4. Shared cloud fixture: the exact settlement the counter client emits
    // for the shared held-order fixture (deal with a recipe-backed component).
    // The PHP projector test replays this file against the cloud's
    // held-snapshot match + immutable-sale validation, so a change here MUST
    // be regenerated (WRITE_FIXTURE=1) and re-proven on the PHP side.
    const holdFixture = JSON.parse(fs.readFileSync(path.join(root, 'tests/Fixtures/local-core-held-order.json'), 'utf8'));
    const fixtureRow = NestPosLocal.offlineHeld.rowFromProjection(Object.assign({}, holdFixture.snapshot, {
        status: 'open', held_at_ms: 1788474600000, kot_job_id: 'kot:' + holdFixture.aggregate_id,
    }), { revision: 1, table_number: (id) => 'T' + id, staff_fallback: 'Shop PC' });
    const fixtureFacts = NestPosLocal.offlineHeld.settlementFromRow(fixtureRow, { discount_cents: 0 });
    assert.strictEqual(fixtureFacts.totals.total_amount, 18.56);
    const fixtureSale = NestPosLocal.normalizeHeldSaleSnapshot(holdFixture.aggregate_id, {
        offline_uuid: 'counter-pay-fixture-0001', business_date: holdFixture.snapshot.business_date,
        payment_method: 'cash', incoming_order_id: null, recalled_order_id: null,
        table_id: fixtureRow.table_id, online_payment_confirmed: false,
        order_ref: { id: fixtureRow.id, order_number: fixtureRow.order_number, order_type: fixtureRow.order_type, table_id: fixtureRow.table_id },
        customer_ref: { id: fixtureRow.customer_id, name: fixtureRow.customer_name, phone: fixtureRow.customer_phone },
        customer_id: fixtureRow.customer_id, delivery_address: null, discount_type: 'amount', discount_value: 0,
        cash_received: 20, terminal_id: 7,
        items: fixtureFacts.items, totals: fixtureFacts.totals, tax_pricing: fixtureFacts.tax_pricing,
    });
    const fixturePath = path.join(root, 'tests/Fixtures/local-core-counter-settlement.json');
    const generated = JSON.stringify({ aggregate_id: holdFixture.aggregate_id, sale_snapshot: JSON.parse(JSON.stringify(fixtureSale)) }, null, 2) + '\n';
    if (process.env.WRITE_FIXTURE === '1' || !fs.existsSync(fixturePath)) fs.writeFileSync(fixturePath, generated);
    assert.strictEqual(fs.readFileSync(fixturePath, 'utf8'), generated,
        'tests/Fixtures/local-core-counter-settlement.json is stale — regenerate with WRITE_FIXTURE=1 and re-run the PHP projector test');
    console.log('local-core-counter-settle: ok');
})().catch((e) => { console.error(e); process.exit(1); });
