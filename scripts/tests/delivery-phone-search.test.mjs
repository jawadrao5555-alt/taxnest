import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../resources/views/pos/universal.blade.php', import.meta.url), 'utf8');
function method(name) {
    const start = source.search(new RegExp('^        ' + name + '\\(', 'm'));
    assert(start >= 0);
    return source.slice(start, source.indexOf('\n        },', start) + 11).trim();
}
function fixture() {
    const ui = vm.runInNewContext('({' + ['pdFiltered', 'heldWindowList'].map(method).join('\n') + '})');
    const bills = [
        {id: 1, customer_name: 'Amina', customer_phone: '0300-123 4567', invoice_number: 'L0042', delivery_address: 'Garden Road', rider_name: 'Rider One'},
        {id: 2, customer_name: 'Bilal', customer_phone: '0311 987-6543', invoice_number: 'L0043'},
        {id: 3, customer_name: 'No phone', invoice_number: 'L0044'},
    ];
    Object.assign(ui, {pdSearch: '', heldSearch: '', heldOrders: bills, tableBoardEnabled: false});
    return {ui, bills};
}
for (const query of ['03001234567', '0300 1234567', '0300-123-4567', '1234567']) {
    test('delivery popup finds formatted phone with ' + query, () => {
        const {ui, bills} = fixture();
        ui.pdSearch = query;
        assert.deepEqual(ui.pdFiltered(bills).map(b => b.id), [1]);
    });
}
test('existing name, bill, address and rider searches remain available', () => {
    const {ui, bills} = fixture();
    for (const query of ['AMINA', 'L0042', 'garden', 'rider one']) {
        ui.pdSearch = query;
        assert.deepEqual(ui.pdFiltered(bills).map(b => b.id), [1]);
    }
    ui.pdSearch = 'customer 1234567';
    assert.equal(ui.pdFiltered(bills).length, 0);
    ui.pdSearch = '---';
    assert.equal(ui.pdFiltered(bills).length, 0);
    ui.pdSearch = '';
    assert.equal(ui.pdFiltered(bills), bills);
    assert.equal(bills.length, 3);
});
test('held orders retain contact search and table/waiter visibility boundary', () => {
    const {ui, bills} = fixture();
    ui.heldSearch = '03001234567';
    assert.deepEqual(ui.heldWindowList().map(b => b.id), [1]);
    ui.tableBoardEnabled = true;
    ui.heldNoTable = () => bills.slice(1);
    assert.equal(ui.heldWindowList().length, 0);
});
test('delivery phone filtering only searches its supplied scoped collection', () => {
    const {ui, bills} = fixture();
    ui.pdSearch = '03001234567';
    assert.equal(ui.pdFiltered(bills.slice(1)).length, 0);
    assert.equal(bills[0].customer_phone, '0300-123 4567');
});
test('delivery board filters current and old rows by contact without changing totals', () => {
    const board = fs.readFileSync(new URL('../../resources/views/pos/deliveries.blade.php', import.meta.url), 'utf8');
    const script = board.match(/\(function \(\) \{\n    var inp = document.getElementById\('del-search'\);[\s\S]*?\n\}\)\(\);/)[0];
    const row = (phone, text) => ({style: {}, getAttribute: key => key === 'data-phone' ? phone : text});
    const rows = [row('0300-123 4567', 'amina l0042'), row('', 'bilal l0043')];
    const oldRows = [row('0300 123-4567', 'old delivery')];
    let listener;
    const input = {value: '', addEventListener: (event, fn) => { listener = fn; }};
    const elements = {'del-search': input, 'del-count': {}, 'del-search-empty': {style: {}}, 'del-old-hits': {style: {}}, 'del-old-search-empty': {style: {}}};
    const events = [];
    vm.runInNewContext(script, {
        document: {getElementById: id => elements[id], querySelectorAll: selector => selector.includes('data-delrow') ? rows : oldRows},
        window: {dispatchEvent: event => events.push(event.type)}, CustomEvent: class {constructor(type) {this.type = type;}},
    });
    input.value = '03001234567'; listener();
    assert.equal(rows[0].style.display, '');
    assert.equal(rows[1].style.display, 'none');
    assert.equal(oldRows[0].style.display, '');
    assert.equal(elements['del-count'].textContent, 1);
    assert.deepEqual(events, ['tn-open-olddel']);
    input.value = 'BILAL'; listener();
    assert.equal(rows[1].style.display, '');
    assert.equal(oldRows[0].style.display, 'none');
    input.value = 'name 1234567'; listener();
    assert.equal(elements['del-count'].textContent, 0);
    input.value = ''; listener();
    assert.equal(elements['del-count'].textContent, 2);
    assert.equal(oldRows[0].style.display, '');
    assert.equal((board.match(/data-phone="\{\{ \$b->customer_phone/g) || []).length, 2);
});
