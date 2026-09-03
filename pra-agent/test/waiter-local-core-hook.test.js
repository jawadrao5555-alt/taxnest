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
assert.ok(layout.includes("window.TaxNestWaiterLocalCore.fallbackOrder(body, this.appendOrderId)"));
assert.ok(layout.indexOf("this._fetchWithTimeout(url") <
    layout.indexOf("window.TaxNestWaiterLocalCore.fallbackOrder(body, this.appendOrderId)"),
    'cloud request remains first');
const fallbackAt = layout.indexOf('window.TaxNestWaiterLocalCore.fallbackOrder(body, this.appendOrderId)');
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
const beforeAppend = commands.length;
assert.strictEqual(context.window.TaxNestWaiterLocalCore.fallbackOrder(body, 'existing').ok, false);
assert.strictEqual(commands.length, beforeAppend, 'offline append cannot partially mutate an order');
console.log('waiter atomic Local Core production hook tests passed');