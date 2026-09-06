'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..', '..');
const asset = fs.readFileSync(path.join(root, 'public/js/waiter-local-core.js'), 'utf8');
const layout = fs.readFileSync(path.join(root, 'resources/views/pos/waiter.blade.php'), 'utf8');
assert.ok(asset.includes('TAXNEST_LOCAL_CORE_WAITER_FALLBACK_V2_ATOMIC_HOLD'));
assert.ok(!/type:\s*['"]order\.(open|line\.add|line\.consume)['"]/.test(asset),
    'production waiter fallback must not retain sequential order mutations');
assert.ok(layout.includes("window.TaxNestWaiterLocalCore.fallbackOrder(body, this.appendOrderId, {"));
assert.ok(layout.indexOf("this._fetchWithTimeout(url") <
    layout.indexOf("window.TaxNestWaiterLocalCore.fallbackOrder(body, this.appendOrderId, {"),
    'cloud request remains first');
const fallbackAt = layout.indexOf('window.TaxNestWaiterLocalCore.fallbackOrder(body, this.appendOrderId, {');
assert.ok(layout.indexOf('if (!local || !local.ok)', fallbackAt) <
    layout.indexOf('this.cart = [];', fallbackAt), 'cart clears only after durable acceptance');

let commands = [];
const state = { catalog: {
    revision: 8,
    products: [{ id: 5, revision: 3, name: 'Tea', tax_snapshot: { rate_basis_points: 1600 },
        recipe_snapshot: [{ stock_id: 9, ingredient_revision: 2, quantity: 1 }],
        deal_snapshot: [], direct_consumption_snapshot: [] }],
    tables: [{ id: 4, revision: 6, table_number: 'T4' }],
} };
const context = { window: { TaxNestLocalCore: {
    query: () => JSON.stringify({ ok: true, result: state }),
    command: (json) => { commands.push(JSON.parse(json)); return JSON.stringify({ ok: true, result: { event: { id: 'e1' } } }); },
} }, console, Date, JSON, Object, Number, String, Array, Math };
vm.createContext(context); vm.runInContext(asset, context);
const body = { hold_uuid: 'hold-1', order_type: 'dine_in', table_id: 4, tax_rate_basis_points: 1600,
    items: [{ item_id: 5, name: 'Tea', quantity: 2, unit_price: 100, special_notes: 'less sugar' }] };
const accepted = context.window.TaxNestWaiterLocalCore.fallbackOrder(body, null);
assert.strictEqual(accepted.ok, true);
assert.strictEqual(commands.length, 1, 'one cart produces exactly one durable command');
assert.strictEqual(commands[0].type, 'order.hold');
const snapshot = commands[0].payload.order_snapshot;
assert.strictEqual(snapshot.order_id, 'hold-1');
assert.strictEqual(snapshot.catalog_revision, 8);
assert.strictEqual(snapshot.table_revision, 6);
assert.strictEqual(snapshot.table_snapshot.table_number, 'T4');
assert.strictEqual(snapshot.lines[0].tax_snapshot.rate_basis_points, 1600);
assert.strictEqual(snapshot.lines[0].recipe_snapshot[0].stock_id, '9');
assert.deepStrictEqual(snapshot.lines[0].deal_snapshot, []);
assert.deepStrictEqual(snapshot.lines[0].direct_consumption_snapshot, []);
assert.strictEqual(snapshot.lines[0].special_notes, 'less sugar');
assert.strictEqual(commands[0].payload.kot_document, undefined, 'no silent KOT configured → no local slip');
const beforeAppend = commands.length;
assert.strictEqual(context.window.TaxNestWaiterLocalCore.fallbackOrder(body, 'existing').ok, false);
assert.strictEqual(commands.length, beforeAppend, 'offline append cannot partially mutate an order');

// ── Offline KOT rides inside the hold (Sep 2026) ─────────────────────────
commands = [];
const withKot = context.window.TaxNestWaiterLocalCore.fallbackOrder(
    Object.assign({}, body, { hold_uuid: 'hold-2', kitchen_notes: 'rush' }), null,
    { kot: true, waiterName: 'Ali', tableLabel: 'T4' });
assert.strictEqual(withKot.ok, true);
assert.strictEqual(withKot.kot_queued, true);
assert.strictEqual(commands.length, 1, 'KOT never becomes a second command (no print.enqueue)');
const kot = commands[0].payload.kot_document;
assert.strictEqual(kot.kind, 'kot');
assert.strictEqual(kot.order_id, 'hold-2');
assert.strictEqual(kot.table_label, 'T4');
assert.strictEqual(kot.waiter_name, 'Ali');
assert.strictEqual(kot.kitchen_notes, 'rush');
assert.deepStrictEqual(kot.lines.map(l => [l.line_id, l.name, l.quantity, l.special_notes]),
    [[commands[0].payload.order_snapshot.lines[0].line_id, 'Tea', 2, 'less sugar']]);

// ── Recipe parts come from the Local Core `recipes` projection when the
// cloud-baked catalog row carries none (the domain compares against it). ──
state.catalog.products.push({ id: 6, updated_at: '2026-09-06 10:00:00', name: 'Karahi' });
state.recipes = { '6': [{ stock_id: 'ingredient-4', quantity: 0.25, version: 1 }] };
commands = [];
const recipeHold = context.window.TaxNestWaiterLocalCore.fallbackOrder(
    { hold_uuid: 'hold-3', order_type: 'takeaway', tax_rate_basis_points: 1600,
        items: [{ item_id: 6, name: 'Karahi', quantity: 1, unit_price: 900 }] }, null);
assert.strictEqual(recipeHold.ok, true);
const recipeLine = commands[0].payload.order_snapshot.lines[0];
assert.deepStrictEqual(JSON.parse(JSON.stringify(recipeLine.recipe_snapshot)), [{ stock_id: 'ingredient-4', quantity: 0.25 }]);
assert.strictEqual(recipeLine.recipe_revision, undefined, 'no recipe revision claimed → domain skips the revision check');
assert.strictEqual(recipeLine.product_revision, '2026-09-06 10:00:00');

// ── Offline readers: tables + "meray orders" in the cloud API shapes ─────
state.catalog.floors = [{ id: 1, name: 'Hall' }];
state.catalog.tables = [
    { id: 4, revision: 6, table_number: 'T4', floor_id: 1, seats: 4, status: 'available' },
    { id: 5, revision: 1, table_number: 'T5', floor_id: 1, seats: 2, status: 'available' },
    { id: 7, revision: 1, table_number: 'T7', floor_id: 1, seats: 2, status: 'occupied' },
];
state.tables = { '7': { order_id: null, claimed_by: null, claimed_at_ms: 1000 } };
state.orders = {
    // local offline hold by waiter 10 on T4
    'hold-9': { id: 'hold-9', order_id: 'hold-9', status: 'open', held_at_ms: 1788691000000, held_by_user_id: '10',
        order_type: 'dine_in', table_id: '4', table_snapshot: { id: 4, table_number: 'T4' }, kot_job_id: 'kot:hold-9',
        customer_name: null, kitchen_notes: 'rush',
        lines: [{ line_id: 'l1', product_id: '5', name: 'Tea', quantity: 2, unit_price_cents: 10000, special_notes: null }],
        totals: { subtotal_cents: 20000, tax_cents: 3200, discount_cents: 0, total_cents: 23200 } },
    // cloud-projected held order by another waiter (11), no table (parcel)
    '300': { id: 300, order_number: 'W-300', status: 'held', created_by: 11, order_type: 'takeaway', table_id: null,
        total_amount: 50, subtotal: 50, created_at: '2026-09-06 09:00:00',
        lines: [{ id: 1, item_name: 'Roti', quantity: 5, unit_price: 10, subtotal: 50, kot_printed_at: '2026-09-06 09:00:05' }] },
    // cloud-projected held order by waiter 10 on T5
    '301': { id: 301, order_number: 'W-301', status: 'held', created_by: 10, order_type: 'dine_in', table_id: 5,
        total_amount: 120, subtotal: 120, created_at: '2026-09-06 09:30:00',
        lines: [{ id: 2, item_name: 'Chai', quantity: 1, unit_price: 120, subtotal: 120, kot_printed_at: null }] },
    // settled cloud order must never show
    '302': { id: 302, order_number: 'W-302', status: 'completed', created_by: 10, order_type: 'dine_in', table_id: 5, total_amount: 1, lines: [] },
    'held:12': { id: 12, cart: [] },
};
const tables = JSON.parse(JSON.stringify(context.window.TaxNestWaiterLocalCore.listTables()));
assert.deepStrictEqual(tables.map(t => [t.id, t.status, t.active_orders, t.floor, t.local_source]),
    [[4, 'occupied', 1, 'Hall', 'shop_pc'], [5, 'occupied', 1, 'Hall', 'shop_pc'], [7, 'occupied', 0, 'Hall', 'shop_pc']]);
assert.strictEqual(tables[0].order_id, 'hold-9');
assert.strictEqual(tables[0].held_orders[0].items_count, 1);
assert.strictEqual(tables[0].held_orders[0].total_amount, 232);
assert.deepStrictEqual(tables[0].held_orders[0].items, [{ name: 'Tea', quantity: 2 }]);
assert.strictEqual(tables[1].order_number, 'W-301');
assert.strictEqual(tables[2].occupied_since, new Date(1000).toISOString());
const mine = JSON.parse(JSON.stringify(context.window.TaxNestWaiterLocalCore.myOrders(10)));
assert.deepStrictEqual(mine.map(o => [o.id, o.order_number, o.table, o.total_amount, o.local]),
    [['hold-9', 'L-OLD9', 'T4', 232, true], [301, 'W-301', 'T5', 120, false]],
    'own local + own cloud-projected open orders, newest first, other waiters and settled orders excluded');
assert.strictEqual(mine[0].kitchen_notes, 'rush');
assert.ok(mine[0].kot_sent_at, 'a local order with a queued KOT job reports the slip as sent');
assert.deepStrictEqual(mine[0].items.map(i => [i.name, i.quantity, i.unit_price, i.subtotal]), [['Tea', 2, 100, 200]]);
assert.strictEqual(mine[1].unprinted_count, 1);
assert.strictEqual(mine[1].items[0].printed, false);
assert.strictEqual(context.window.TaxNestWaiterLocalCore.myOrders(11).length, 1);
// ── Consecutive offline orders never inherit the previous order's fields ──
// Both acceptance paths (cloud + shop-PC fallback) must run the ONE reset.
const sendAt = layout.indexOf('async send() {');
const sendEnd = layout.indexOf('_afterOrderSent() {', sendAt);
const sendSrc = layout.slice(sendAt, sendEnd);
assert.strictEqual((sendSrc.match(/this\._afterOrderSent\(\)/g) || []).length, 2,
    'cloud success and Local Core fallback success both call the shared reset');
assert.ok(!/this\.customerName\s*=/.test(sendSrc) && !/this\.cart\s*=\s*\[\]/.test(sendSrc),
    'no branch-specific partial resets remain inside sendOrder');
// Execute the blade's own reset + body construction on a simulated tablet.
function bladeMethod(name) {
    const at = layout.indexOf(name + '() {');
    assert.ok(at > 0, name + ' must exist');
    let depth = 0, i = layout.indexOf('{', at);
    for (; i < layout.length; i++) {
        if (layout[i] === '{') depth++;
        else if (layout[i] === '}' && --depth === 0) break;
    }
    return new Function(layout.slice(layout.indexOf('{', at) + 1, i));
}
const bodyStart = sendSrc.indexOf('const items = this.cart.map(');
const bodyEnd = sendSrc.indexOf('};', sendSrc.indexOf('hold_uuid: this.holdAttemptUuid')) + 2;
// Blade-baked literals ({{ ... }}) become null for this runtime check.
const buildBody = new Function(sendSrc.slice(bodyStart, bodyEnd).replace(/\{\{[\s\S]*?\}\}/g, 'null') + ' return body;');
const tablet = {
    cart: [{ name: 'Tea', quantity: 1, unit_price: 100, item_id: 5 }], cashierId: 3, orderType: 'takeaway',
    selectedTable: null, customerName: 'Ahmed', customerPhone: '03001234567', kitchenNotes: 'no onion',
    priority: true, cashTaxRate: 16, holdAttemptUuid: 'hold-first', appendOrderId: null, appendAttemptUuid: null,
    appendOrderNumber: '', appendTableLabel: '', appendCustomerLabel: '', moreOpen: true, _buttonsMode: false,
    loadMyOrders() { this.reloaded = (this.reloaded || 0) + 1; }, reloadTablesQuiet() {},
};
commands = [];
const firstBody = buildBody.call(tablet);
assert.strictEqual(firstBody.customer_name, 'Ahmed');
assert.strictEqual(context.window.TaxNestWaiterLocalCore.fallbackOrder(firstBody, null).ok, true);
assert.strictEqual(commands[0].payload.order_snapshot.customer_name, 'Ahmed');
assert.strictEqual(commands[0].payload.order_snapshot.kitchen_notes, 'no onion');
assert.strictEqual(commands[0].payload.order_snapshot.priority, true);
bladeMethod('_afterOrderSent').call(tablet);            // what the fallback-success branch runs
assert.deepStrictEqual([tablet.cart, tablet.customerName, tablet.customerPhone, tablet.kitchenNotes, tablet.priority,
    tablet.selectedTable, tablet.holdAttemptUuid, tablet.appendAttemptUuid, tablet.appendOrderId, tablet.orderType, tablet.moreOpen],
    [[], '', '', '', false, null, null, null, null, 'dine_in', false], 'every order-scoped field is cleared after a shop-PC order');
assert.strictEqual(tablet.reloaded, 1);
tablet.cart = [{ name: 'Water', quantity: 1, unit_price: 50, item_id: 5 }];
tablet.holdAttemptUuid = 'hold-second';
const secondBody = buildBody.call(tablet);
assert.strictEqual(secondBody.customer_name, null);
assert.strictEqual(secondBody.customer_phone, null);
assert.strictEqual(secondBody.kitchen_notes, null);
assert.strictEqual(secondBody.priority, false);
assert.strictEqual(secondBody.order_type, 'dine_in');
assert.strictEqual(context.window.TaxNestWaiterLocalCore.fallbackOrder(secondBody, null).ok, true);
const second = commands[1].payload.order_snapshot;
assert.strictEqual(second.order_id, 'hold-second');
assert.strictEqual(second.customer_name, null, 'second offline order carries no previous customer');
assert.strictEqual(second.customer_phone, null);
assert.strictEqual(second.kitchen_notes, null, 'second offline order carries no previous kitchen note');
assert.strictEqual(second.priority, false, 'urgent flag does not leak into the next order');
console.log('waiter atomic Local Core production hook tests passed');