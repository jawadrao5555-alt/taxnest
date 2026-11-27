import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const blade = readFileSync(new URL('../../resources/views/pos/universal.blade.php', import.meta.url), 'utf8');
const methods = [
    'boardAskFinal', 'boardPaymentAwaitingOnline', 'loadBoardCashQuote', 'boardCashDue',
    'boardCashTender', 'boardCashDifference', 'boardCashDifferenceText', 'boardFinalPay',
    'panelAskFinal', 'panelFinalPay', 'payHeldOrderDirect',
].map(name => {
    const match = blade.match(new RegExp('^ {8}(?:async )?' + name + '\\([^\\n]*', 'm'));
    assert.ok(match, name);
    const end = blade.indexOf('\n        },', match.index);
    assert.ok(end > match.index, name);
    return blade.slice(match.index, end + 11).replace(/{{[^}]*}}/g, 'fixture');
});

function fixture() {
    const requests = [];
    const ui = runInNewContext('({' + methods.join('\n') + '})', {
        window: { TXT: { change_rs_prefix: 'Change Rs ', short_by_rs: 'Short Rs ', more_needed_sfx: '' } },
    });
    Object.assign(ui, {
        cashReceivedEnabled: true,
        r2: amount => Math.round(amount * 100) / 100,
        billPrintDefault: () => true,
        payingOrderAwaitingOnline: () => true,
        cashReceived: '5000',
        lastCashReceived: 5000,
        deliveryCollectionMode: 'counter',
        heldOrders: [],
        _payUuidByOrder: {},
        _onlineOkByOrder: {},
        _newOfflineUuid: () => 'attempt-1',
        loadTableStatus() {},
        loadIncoming() {},
        applyPaySuccess(data) { this.paid = data; return true; },
        fetchWithTimeout(url, options) {
            return new Promise(resolve => requests.push({
                url,
                options,
                respond: data => resolve({ ok: true, json: async () => data }),
            }));
        },
    });
    return { ui, requests };
}

function table(id, online = false) {
    return { id, table_number: String(id), order: { id, order_type: 'dine_in', online_payment_awaited_at: online } };
}

function cashQuote(orderId, counterDue = 116) {
    return { success: true, order_id: orderId, payment_method: 'cash', total_amount: 116, counter_cash_due: counterDue, delivery_collection_mode: 'rider' };
}

async function flushQuote() {
    await new Promise(resolve => setImmediate(resolve));
}

test('Table Final shows cash change for the order quote and sends the isolated tender once', async () => {
    const { ui, requests } = fixture();
    ui.boardMenuTable = table(4);
    ui.boardAskFinal();
    assert.match(requests[0].url, /\/4\/payment-quote\?payment_method=cash$/);
    assert.equal(requests[0].options.cache, 'no-store');
    requests[0].respond(cashQuote(4, 60));
    await flushQuote();

    ui.boardConfirm.cashReceived = '50';
    assert.equal(ui.boardCashDifferenceText(), 'Short Rs 10');
    ui.boardConfirm.cashReceived = '100.50';
    assert.equal(ui.boardCashDifferenceText(), 'Change Rs 40.5');
    ui.boardPrintReceipt = false;
    const payment = ui.boardFinalPay('cash');
    await ui.boardFinalPay('card');

    assert.equal(requests.length, 2);
    assert.match(requests[1].url, /\/4\/pay$/);
    const payload = JSON.parse(requests[1].options.body);
    assert.equal(payload.cash_received, 100.50);
    assert.equal(payload.delivery_collection_mode, 'rider');
    assert.equal(payload.pay_uuid, 'attempt-1');
    requests[1].respond({ success: true, payment_method: 'cash' });
    await payment;
    assert.equal(ui.lastCashReceived, 100.50);
    assert.equal(ui.cashReceived, '5000');
    assert.equal(ui.boardConfirm, null);
});

test('A stale quote cannot populate another table and opt-out never reuses cart cash', async () => {
    const { ui, requests } = fixture();
    ui.boardMenuTable = table(4);
    ui.boardAskFinal();
    ui.boardMenuTable = table(5);
    ui.boardAskFinal();
    requests[0].respond(cashQuote(4));
    await flushQuote();
    assert.equal(ui.boardCashDue(), null);
    requests[1].respond(cashQuote(5, 80));
    await flushQuote();
    assert.equal(ui.boardCashDue(), 80);

    ui.cashReceivedEnabled = false;
    ui.boardMenuTable = table(7);
    ui.boardAskFinal();
    assert.equal(requests.length, 2);
    const payment = ui.boardFinalPay('cash');
    assert.equal(JSON.parse(requests[2].options.body).cash_received, null);
    assert.equal(JSON.parse(requests[2].options.body).delivery_collection_mode, null);
    requests[2].respond({ success: true, payment_method: 'cash' });
    await payment;
    assert.equal(ui.lastCashReceived, 0);
});

test('Open cart Final carries the tender through the cart pipeline and clears it afterwards', async () => {
    const { ui, requests } = fixture();
    Object.assign(ui, {
        payingOrderAwaitingOnline: () => false,
        panelOrderId: () => 9,
        panelTableInfo: () => ({ table_number: '9' }),
        roundedTotal: 90,
        payModalCounterCash: 85,
        async processPayment(method) {
            this.settledCart = { method, tender: this.cashReceived, payingHeldOrderId: this.payingHeldOrderId };
        },
        payingHeldOrderId: 10,
        payMethodIndex: 1,
    });
    ui.panelAskFinal();
    assert.equal(requests.length, 0);
    assert.equal(ui.payMethodIndex, 0);
    ui.boardConfirm.cashReceived = '120';
    assert.equal(ui.boardCashDifferenceText(), 'Change Rs 35');
    await ui.boardFinalPay('cash');
    assert.equal(ui.settledCart.method, 'cash');
    assert.equal(ui.settledCart.tender, '120');
    assert.equal(ui.settledCart.payingHeldOrderId, null);
    assert.equal(ui.cashReceived, '');
});
