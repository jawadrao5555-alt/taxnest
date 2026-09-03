'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const fixture = JSON.parse(fs.readFileSync(require.resolve('../../tests/Fixtures/local-core-held-settlement.json'), 'utf8'));
const holdFixture = JSON.parse(fs.readFileSync(require.resolve('../../tests/Fixtures/local-core-held-order.json'), 'utf8'));

let invoked = [];
let rejectSettlement = false;
let rejectHold = false;
let immediateSaleCalls = 0;
const listeners = {};
const document = {
    documentElement: { setAttribute() {} },
    body: { appendChild() {} },
    createElement() { return { style: {}, setAttribute() {} }; },
    addEventListener(name, fn) { listeners[name] = fn; },
};
const window = {
    nestposDesktop: {
      acceptImmediateSale(value) {
        immediateSaleCalls += 1;
        return Promise.resolve({ ok: true, event_id: 'sale-event-1', receipt: { local_bill_number: 'DL-1' } });
      },
      localCore: {
        version: 1,
        command(value) {
            invoked.push(['command', value]);
            if (rejectSettlement && value.type === 'order.settle') {
                return Promise.resolve({ ok: false, success: false, state: 'rejected', error: 'revision_conflict' });
            }
            if (rejectHold && value.type === 'order.hold') {
                return Promise.resolve({ ok: false, success: false, state: 'rejected', error: 'hold_conflict' });
            }
            return Promise.resolve({ ok: true, success: true, state: 'pending' });
        },
        query(value) { invoked.push(['query', value]); return Promise.resolve({ ok: true, state: 'local', data: {} }); },
      }
    },
    addEventListener() {},
    dispatchEvent() {},
};
const context = {
    window, document, CustomEvent: function (name, init) { this.type = name; this.detail = init.detail; },
    AbortController, setTimeout, clearTimeout,
    fetch() { return Promise.reject(new TypeError('network down')); },
};
vm.runInNewContext(fs.readFileSync(require.resolve('../../public/js/nestpos-local-core.js'), 'utf8'), context);

(async function () {
    assert.strictEqual(window.NestPosLocal.version, 1);
    const result = await window.NestPosLocal.request({
        url: '/pos/restaurant/orders',
        command: { type: 'order.open', aggregate_id: 'order-1', payload: { business_date: '2026-09-03' } },
    });
    assert.strictEqual(result.state, 'pending');
    assert.strictEqual(invoked[0][0], 'query');
    assert.strictEqual(invoked[1][1].type, 'order.open');
    await window.NestPosLocal.query('orders');
    assert.strictEqual(invoked[2][1].projection, 'orders');
    const external = await window.NestPosLocal.external('/pra/submit', {});
    assert.strictEqual(external.error, 'external_call_not_queued');
    assert.strictEqual(external.success, false);
    assert.strictEqual(external.pending, false);

    invoked = [];
    await window.NestPosLocal.table.shift('table-1', 'table-2', 'order-1');
    assert.strictEqual(invoked[0][1].projection, 'revisions');
    assert.strictEqual(invoked[0][1].id, 'order-1', 'shift revision belongs to the order aggregate');
    assert.strictEqual(invoked[1][1].type, 'table.shift');
    assert.strictEqual(invoked[1][1].aggregate_id, 'order-1');
    assert.strictEqual(invoked.filter(x => x[1] && ['table.release', 'table.claim'].includes(x[1].type)).length, 0);

    const sale = fixture.universal_input;
    const normalized = window.NestPosLocal.normalizeHeldSaleSnapshot(fixture.aggregate_id, sale);
    assert.strictEqual(normalized.order_id, fixture.normalized.order_id);
    assert.strictEqual(normalized.business_date, fixture.normalized.business_date);
    assert.strictEqual(normalized.payment.method, fixture.normalized.payment_method);
    assert.strictEqual(normalized.totals.subtotal_cents, fixture.normalized.subtotal_cents);
    assert.strictEqual(normalized.totals.tax_cents, fixture.normalized.tax_cents);
    assert.strictEqual(normalized.totals.discount_cents, fixture.normalized.discount_cents);
    assert.strictEqual(normalized.totals.total_cents, fixture.normalized.total_cents);
    assert.deepStrictEqual(JSON.parse(JSON.stringify(normalized.items[0].tax_snapshot)), fixture.normalized.line.tax_snapshot);
    assert.deepStrictEqual(JSON.parse(JSON.stringify(normalized.items[0].recipe_snapshot)), fixture.normalized.line.recipe_snapshot);
    assert.deepStrictEqual(JSON.parse(JSON.stringify(normalized.items[1].deal_snapshot)), fixture.normalized.deal_line.deal_snapshot);
    invoked = [];
    const settled = await window.NestPosLocal.heldOrder.settleWithSale(fixture.aggregate_id, sale, { total_cents: fixture.normalized.total_cents });
    assert.strictEqual(settled.success, true);
    assert.strictEqual(immediateSaleCalls, 0, 'settlement must not append a preliminary sale');
    assert.strictEqual(invoked[0][1].projection, 'revisions');
    assert.strictEqual(invoked[1][1].type, 'order.settle');
    assert.strictEqual(invoked[1][1].payload.sale_snapshot.order_id, fixture.aggregate_id);
    assert.strictEqual(invoked[1][1].payload.sale_snapshot.offline_uuid, sale.offline_uuid);
    assert.strictEqual(invoked[1][1].id, 'order-settle:' + sale.offline_uuid);
    assert.strictEqual(invoked.filter(x => x[0] === 'command').length, 1,
        'atomic settlement must issue exactly one command/event call');

    const inconsistent = JSON.parse(JSON.stringify(sale));
    inconsistent.totals.total_amount = 999;
    invoked = [];
    const invalid = await window.NestPosLocal.heldOrder.settleWithSale(fixture.aggregate_id, inconsistent, {});
    assert.strictEqual(invalid.error, 'invalid_sale_snapshot');
    assert.strictEqual(invoked.filter(x => x[0] === 'command').length, 0);
    const wrongOrder = JSON.parse(JSON.stringify(sale));
    wrongOrder.order_id = 'another-order';
    const wrongOrderResult = await window.NestPosLocal.heldOrder.settleWithSale(fixture.aggregate_id, wrongOrder, {});
    assert.strictEqual(wrongOrderResult.error, 'invalid_sale_snapshot');
    const missingDeal = JSON.parse(JSON.stringify(sale));
    delete missingDeal.items[1].deal_snapshot;
    const missingDealResult = await window.NestPosLocal.heldOrder.settleWithSale(fixture.aggregate_id, missingDeal, {});
    assert.strictEqual(missingDealResult.error, 'invalid_sale_snapshot');

    rejectSettlement = true;
    invoked = [];
    const heldOrders = ['order-2'];
    const rejected = await window.NestPosLocal.heldOrder.settleWithSale('order-2', sale, { total_cents: 100 });
    if (rejected.success) heldOrders.splice(heldOrders.indexOf('order-2'), 1);
    assert.strictEqual(rejected.success, false);
    assert.strictEqual(rejected.pending, false);
    assert.strictEqual(rejected.state, 'rejected');
    assert.deepStrictEqual(heldOrders, ['order-2'], 'rejected settlement must retain the order');
    assert.strictEqual(invoked.filter(x => x[0] === 'command').length, 1,
        'interrupted/rejected settlement must still issue only one command');
    assert.strictEqual(immediateSaleCalls, 0, 'rejection must not leave a standalone sale event');

    rejectSettlement = false;
    invoked = [];
    await window.NestPosLocal.heldOrder.settleWithSale('order-2', sale, { total_cents: 100 });
    assert.strictEqual(invoked[1][1].id, 'order-settle:' + sale.offline_uuid,
        'retry must reuse the settlement idempotency key');

    invoked = [];
    const held = await window.NestPosLocal.heldOrder.hold(
        holdFixture.aggregate_id, holdFixture.snapshot, holdFixture.revision);
    assert.strictEqual(held.success, true);
    assert.strictEqual(invoked.length, 1, 'atomic hold must make exactly one IPC call');
    assert.strictEqual(invoked[0][1].type, 'order.hold');
    assert.deepStrictEqual(JSON.parse(JSON.stringify(invoked[0][1].payload.order_snapshot)), holdFixture.snapshot);
    assert.strictEqual(invoked[0][1].id, 'order-hold:' + holdFixture.snapshot.idempotency_key);

    rejectHold = true;
    invoked = [];
    const cart = holdFixture.snapshot.lines.slice();
    const rejectedHold = await window.NestPosLocal.heldOrder.hold(
        holdFixture.aggregate_id, holdFixture.snapshot, holdFixture.revision);
    if (rejectedHold.success) cart.length = 0;
    assert.strictEqual(rejectedHold.success, false);
    assert.strictEqual(rejectedHold.pending, false);
    assert.strictEqual(invoked.length, 1, 'rejected hold must not create partial line/table commands');
    assert.strictEqual(invoked[0][1].type, 'order.hold');
    assert.strictEqual(cart.length, holdFixture.snapshot.lines.length, 'hold rejection must retain the cart');
    console.log('pos local core client tests passed');
}()).catch((error) => { console.error(error); process.exitCode = 1; });