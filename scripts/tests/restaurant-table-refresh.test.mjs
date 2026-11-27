import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../resources/views/pos/universal.blade.php', import.meta.url), 'utf8');
const start = source.indexOf('        async loadTableStatus() {');
const method = source.slice(start, source.indexOf('\n        },', start) + 11);
function fixture(waitMs = 12000) {
    const requests = [];
    const ui = vm.runInNewContext('({' + method + '})', {
        TypeError, Date, AbortController, clearTimeout,
        setTimeout: (callback, delay) => setTimeout(callback, Math.min(delay, waitMs)),
        fetch: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })),
    });
    Object.assign(ui, {
        _tableRequestSerial: 0, _tableEtag: null,
        tableFloors: [{ name: 'Main', tables: [{ id: 1, total: 100 }] }],
        tablesLoading: false, tablesError: false, tablesErrorKind: '', tablesCheckedAt: null,
        cart: [{ id: 9, quantity: 2 }],
    });
    return { ui, requests };
}
const response = (list = [], etag = 'fresh') => ({
    status: 200, ok: true, redirected: false, headers: { get: () => etag }, json: async () => list,
});

test('a late failure cannot put an error over a newer accepted table board', async () => {
    const { ui, requests } = fixture();
    const cart = ui.cart;
    const old = ui.loadTableStatus(), current = ui.loadTableStatus();
    requests[1].resolve(response([{ id: 7, floor: 'Main', total: 250 }]));
    await current;
    const checkedAt = ui.tablesCheckedAt;
    requests[0].reject(new TypeError('old connection failed'));
    await old;
    assert.equal(ui.tablesError, false);
    assert.equal(ui.tableFloors[0].tables[0].id, 7);
    assert.equal(ui._tableEtag, 'fresh');
    assert.equal(ui.tablesCheckedAt, checkedAt);
    assert.equal(ui.cart, cart);
});

test('an obsolete body cannot overwrite the board or ETag after a newer response', async () => {
    const { ui, requests } = fixture();
    let finishBody;
    let bodyStarted;
    const entered = new Promise(resolve => { bodyStarted = resolve; });
    const old = ui.loadTableStatus();
    requests[0].resolve({ ...response(), json: () => new Promise(resolve => { finishBody = resolve; bodyStarted(); }) });
    await entered;
    const current = ui.loadTableStatus();
    requests[1].resolve(response([{ id: 7, floor: 'Upstairs' }], 'new'));
    await current;
    finishBody([{ id: 1, floor: 'Main' }]);
    await old;
    assert.equal(ui.tableFloors[0].tables[0].id, 7);
    assert.equal(ui._tableEtag, 'new');
});

test('a completed obsolete request cannot stop the current loading state', async () => {
    const { ui, requests } = fixture();
    const old = ui.loadTableStatus(), current = ui.loadTableStatus();
    requests[0].resolve(response([{ id: 2 }]));
    await old;
    assert.equal(ui.tablesLoading, true);
    requests[1].resolve(response([{ id: 3 }]));
    await current;
    assert.equal(ui.tablesLoading, false);
});

for (const status of [401, 403, 419, 500, 503]) {
    test(`HTTP ${status} retains last board and classifies access/server failure accurately`, async () => {
        const { ui, requests } = fixture();
        const board = ui.tableFloors;
        const load = ui.loadTableStatus();
        requests[0].resolve({ status, ok: false });
        await load;
        assert.equal(ui.tableFloors, board);
        assert.equal(ui.tablesError, true);
        assert.equal(ui.tablesErrorKind, status < 500 ? 'auth' : 'server');
        assert.equal(ui.tablesLoading, false);
    });
}

test('304 revalidates the accepted cached board after a transient failure', async () => {
    const { ui, requests } = fixture();
    ui._tableEtag = 'accepted';
    ui.tablesError = true;
    const board = ui.tableFloors;
    const load = ui.loadTableStatus();
    assert.equal(requests[0].options.headers['If-None-Match'], 'accepted');
    requests[0].resolve({ status: 304 });
    await load;
    assert.equal(ui.tableFloors, board);
    assert.equal(ui.tablesError, false);
    assert.ok(ui.tablesCheckedAt > 0);
});

for (const invalid of [{}, null, [{ wrong: true }]]) {
    test(`malformed JSON payload ${JSON.stringify(invalid)} does not erase the board or poison its ETag`, async () => {
        const { ui, requests } = fixture();
        const board = ui.tableFloors;
        ui._tableEtag = 'accepted';
        const load = ui.loadTableStatus();
        requests[0].resolve(response(invalid, 'invalid'));
        await load;
        assert.equal(ui.tableFloors, board);
        assert.equal(ui._tableEtag, 'accepted');
        assert.equal(ui.tablesErrorKind, 'server');
    });
}

test('network failure/timeout is recoverable and preserves the cart and previous board', async () => {
    const { ui, requests } = fixture();
    const board = ui.tableFloors, cart = ui.cart;
    const failed = ui.loadTableStatus();
    requests[0].reject(Object.assign(new Error('timeout'), { name: 'AbortError' }));
    await failed;
    assert.equal(ui.tablesErrorKind, 'network');
    assert.equal(ui.tableFloors, board);
    assert.equal(ui.cart, cart);
    const retry = ui.loadTableStatus();
    requests[1].resolve(response([{ id: 3, floor: '__proto__' }]));
    await retry;
    assert.equal(ui.tablesError, false);
    assert.equal(ui.tableFloors[0].name, '__proto__');
});

test('stalled JSON after response headers times out and preserves the last valid board', { timeout: 1000 }, async () => {
    const { ui, requests } = fixture(25);
    const board = ui.tableFloors;
    const cart = ui.cart;
    const checkedAt = ui.tablesCheckedAt = 123;
    ui._tableEtag = 'accepted';

    const pending = ui.loadTableStatus();
    const { signal } = requests[0].options;
    requests[0].resolve({
        ...response([], 'unaccepted'),
        json: () => new Promise((_, reject) => signal.addEventListener('abort', () => reject(signal.reason), { once: true })),
    });
    await pending;

    assert.equal(ui.tablesLoading, false);
    assert.equal(ui.tablesErrorKind, 'network');
    assert.equal(ui.tableFloors, board);
    assert.equal(ui._tableEtag, 'accepted');
    assert.equal(ui.tablesCheckedAt, checkedAt);
    assert.equal(ui.cart, cart);
    const retry = ui.loadTableStatus();
    requests[1].resolve({ status: 304 });
    await retry;
    assert.equal(ui.tablesError, false);
});

test('an expired-login redirect is reported as access loss without accepting HTML as a board', async () => {
    const { ui, requests } = fixture();
    const board = ui.tableFloors;
    const load = ui.loadTableStatus();
    requests[0].resolve({ ...response(), redirected: true });
    await load;
    assert.equal(ui.tableFloors, board);
    assert.equal(ui.tablesErrorKind, 'auth');
});
