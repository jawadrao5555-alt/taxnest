import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { test } from 'node:test';

const source = readFileSync(new URL('../../resources/views/pos/universal.blade.php', import.meta.url), 'utf8');
const strip = source.slice(source.indexOf('AKHRI BILLS strip'), source.indexOf('tn-mobile-cart-bar'));
const click = strip.match(/<button type="button" @click="([^"]+)"/)[1];
const method = (name, context = {}) => {
    const start = source.search(new RegExp('^        (?:async )?' + name + '\\(', 'm'));
    assert.ok(start >= 0, name + ' exists');
    const end = source.indexOf('\n        },', start) + '\n        }'.length;
    return vm.runInNewContext('({' + source.slice(start, end) + '})', {
        setTimeout: fn => fn(), window: { TXT: {} }, ...context,
    })[name];
};

for (const restaurant of [false, true]) for (const silent of [false, true]) {
    test(`recent bill previews without printing; explicit reprint preserved (restaurant=${restaurant}, silent=${silent})`, async () => {
        const attempts = [], bill = { id: 87, invoice_number: 'L087', total_amount: 346 };
        let previewFocused = false;
        const cart = [{ item_name: 'Unfinished current sale', quantity: 2 }];
        const d = {
            isRestaurantMode: restaurant, silentBillPrint: silent, reprintBusyId: null,
            reprintPreviewBill: null, cart, selectedCustomer: { id: 22 },
            $nextTick: fn => fn(),
            $refs: { reprintPreviewDialog: { focus: () => { previewFocused = true; } } },
            openReprintPreview: method('openReprintPreview'),
            reprintBill: method('reprintBill'), receiptViewUrl: method('receiptViewUrl'),
            _newPrintAttemptUuid: () => 'synthetic-explicit-attempt',
            trySilentPrint: async payload => { attempts.push(payload); return true; },
            _printViaIframe: (_frame, url) => attempts.push(url), showToast: () => {},
        };
        // Execute the live chip's actual click expression, not a test-only handler.
        vm.runInNewContext(click, { ...d, bill, openReprintPreview: d.openReprintPreview.bind(d), reprintBill: d.reprintBill.bind(d) });
        assert.equal(d.reprintPreviewBill, bill, 'Recent Bill must open the existing preview');
        assert.equal(previewFocused, true, 'Keyboard focus belongs to the opened preview');
        assert.deepEqual(attempts, [], 'viewing must not submit any print');
        assert.equal(d.cart, cart); assert.equal(d.selectedCustomer.id, 22);
        const viewUrl = d.receiptViewUrl(bill);
        assert.equal(viewUrl, restaurant ? '/pos/restaurant/receipt/87' : '/pos/transaction/87/receipt');
        assert.ok(!viewUrl.includes('auto_print'));
        // Existing popup Print and dedicated Reprint list still use this method.
        d.reprintBill(bill); await Promise.resolve();
        assert.equal(attempts.length, 1);
        if (silent) assert.deepEqual(JSON.parse(JSON.stringify(attempts[0])), {
            type: 'bill', transaction_id: 87, print_attempt_uuid: 'synthetic-explicit-attempt',
        });
        else assert.equal(attempts[0], viewUrl + '?auto_print=1');
    });
}

test('manual receipt and reprint WhatsApp keep the recipient and both requested formats', async () => {
    const opens = [], requests = [], feedback = [];
    const receiptUrl = 'https://fixture.invalid/pos/invoice/share/fictional';
    const phone = '10000000000';
    const context = {
        window: {
            TXT: {
                invoice_word: 'Invoice', total_word: 'Total', walk_in_customer: 'Walk-in Customer',
                wa_msg_receipt: 'Receipt', wa_msg_thanks: 'Thank you', wa_popup_blocked: 'Popup blocked',
            },
            open(url) { opens.push(url); return null; },
        },
        fetch: async (url, opts) => {
            requests.push([url, opts.method]);
            return { json: async () => ({ url: receiptUrl }) };
        },
    };
    const d = {
        lastWaPhone: phone, lastShareUrl: receiptUrl, lastInvoiceNumber: 'SYN-24',
        lastTotal: 325.5, lastWaCustomerName: '  Fictional   Buyer  ', waShopName: 'Fixture Shop',
        showToast: (...args) => feedback.push(args),
    };
    for (const name of ['waBillMessage', 'waBillSummary', 'waBillLinkFromLast', 'waShareReprint']) {
        d[name] = method(name, context);
    }
    const checkLink = (link, expectedText) => {
        const url = new URL(link);
        assert.equal(url.origin, 'https://wa.me');
        assert.equal(url.pathname, '/' + phone);
        assert.equal(url.searchParams.get('text'), expectedText);
    };
    const totalLine = 'Total: Rs ' + Number(d.lastTotal).toLocaleString();
    const full = '*Fixture Shop*\nInvoice: SYN-24\n' + totalLine + '\nReceipt: ' + receiptUrl + '\nThank you';
    checkLink(d.waBillLinkFromLast(), full);
    checkLink(d.waBillLinkFromLast(true), 'Fictional Buyer\n' + totalLine);
    d.lastWaCustomerName = null;
    checkLink(d.waBillLinkFromLast(true), 'Walk-in Customer\n' + totalLine);
    d.lastWaPhone = null;
    assert.equal(d.waBillLinkFromLast(true), null);

    const bill = { id: 24, invoice_number: 'SYN-24', customer_name: null, total_amount: 325.5, wa_phone: phone };
    await d.waShareReprint({ ...bill, wa_phone: null }, true);
    assert.deepEqual(opens, []);
    await d.waShareReprint(bill, true);
    checkLink(opens.shift(), 'Walk-in Customer\n' + totalLine);
    assert.deepEqual(requests, [], 'the short format never requests a public receipt link');
    await d.waShareReprint(bill);
    assert.deepEqual(requests, [['/pos/transaction/24/share-link', 'POST']]);
    assert.equal(opens.shift(), '');
    checkLink(opens.shift(), full);
    assert.deepEqual(feedback, [['Popup blocked', 'error'], ['Popup blocked', 'error']]);
    assert.equal(d.waReprintBusyId, null);
});
