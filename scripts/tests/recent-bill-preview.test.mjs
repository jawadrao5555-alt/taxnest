import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { test } from 'node:test';

const source = readFileSync(new URL('../../resources/views/pos/universal.blade.php', import.meta.url), 'utf8');
const strip = source.slice(source.indexOf('AKHRI BILLS strip'), source.indexOf('tn-mobile-cart-bar'));
const click = strip.match(/<button type="button" @click="([^"]+)"/)[1];
const method = name => {
    const start = source.indexOf('        ' + name + '(');
    assert.ok(start >= 0, name + ' exists');
    const end = source.indexOf('\n        },', start) + '\n        }'.length;
    return vm.runInNewContext('({' + source.slice(start, end) + '})', {
        setTimeout: fn => fn(), window: { TXT: {} },
    })[name];
};

for (const restaurant of [false, true]) for (const silent of [false, true]) {
    test(`recent bill previews without printing; explicit reprint preserved (restaurant=${restaurant}, silent=${silent})`, async () => {
        const attempts = [], bill = { id: 87, invoice_number: 'L087', total_amount: 346 };
        const cart = [{ item_name: 'Unfinished current sale', quantity: 2 }];
        const d = {
            isRestaurantMode: restaurant, silentBillPrint: silent, reprintBusyId: null,
            reprintPreviewBill: null, cart, selectedCustomer: { id: 22 },
            openReprintPreview: method('openReprintPreview'),
            reprintBill: method('reprintBill'), receiptViewUrl: method('receiptViewUrl'),
            _newPrintAttemptUuid: () => 'synthetic-explicit-attempt',
            trySilentPrint: async payload => { attempts.push(payload); return true; },
            _printViaIframe: (_frame, url) => attempts.push(url), showToast: () => {},
        };
        // Execute the live chip's actual click expression, not a test-only handler.
        vm.runInNewContext(click, { ...d, bill, openReprintPreview: d.openReprintPreview.bind(d), reprintBill: d.reprintBill.bind(d) });
        assert.equal(d.reprintPreviewBill, bill, 'Recent Bill must open the existing preview');
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
