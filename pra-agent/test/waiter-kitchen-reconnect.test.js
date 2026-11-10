const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const view = name => readFileSync(path.join(__dirname, '../../resources/views/pos', name), 'utf8');
const waiter = view('waiter.blade.php');
const kitchen = view('restaurant/kds.blade.php');

function extract(source, name) {
  const start = source.search(new RegExp('^        (?:async )?' + name + '\\(', 'm'));
  assert.ok(start >= 0, name);
  const end = source.indexOf('\n        },', start);
  assert.ok(end > start, name);
  return source.slice(start, end + 11);
}

function deferred() {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return { promise, resolve };
}

test('Waiter reconnect refreshes visible lists without joining a stale orders request', async () => {
  const previous = deferred();
  const document = { hidden: true };
  const ui = vm.runInNewContext('({' + extract(waiter, 'refreshLiveLists') + '})', { document });
  const calls = [];
  Object.assign(ui, {
    _myOrdersRequest: previous.promise,
    _buttonsMode: true,
    loadMyOrders() { calls.push('orders'); },
    reloadTablesQuiet() { calls.push('tables'); },
    loadKotAttention() { calls.push('kot'); },
  });

  ui.refreshLiveLists();
  assert.deepEqual(calls, []);
  document.hidden = false;
  const refresh = ui.refreshLiveLists();
  assert.equal(ui.refreshLiveLists(), refresh);
  assert.deepEqual(calls, ['tables', 'kot']);
  previous.resolve();
  await refresh;
  assert.deepEqual(calls, ['tables', 'kot', 'orders']);
});

test('Waiter ignores a table response whose body arrives after the newer response', async () => {
  const ui = vm.runInNewContext('({' + extract(waiter, 'reloadTablesQuiet') + '})');
  const previousBody = deferred();
  let requests = 0;
  ui._tableRequestSerial = 0;
  ui._fetchWithTimeout = async () => {
    const first = ++requests === 1;
    return {
      ok: true,
      headers: { get: () => first ? 'old' : 'new' },
      json: () => first ? previousBody.promise : [{ id: 2 }],
    };
  };
  const previous = ui.reloadTablesQuiet();
  await Promise.resolve();
  await ui.reloadTablesQuiet();
  previousBody.resolve([{ id: 1 }]);
  await previous;
  assert.equal(ui.tables[0].id, 2);
  assert.equal(ui._tableEtag, 'new');
});

test('Kitchen ignores a late snapshot and only auto-prints from the newer applied result', async () => {
  const previousBody = deferred();
  let requests = 0;
  const fetch = async () => {
    const first = ++requests === 1;
    return { ok: true, json: () => first ? previousBody.promise : [{ id: 2, void: true }] };
  };
  const method = extract(kitchen, 'refreshOrders').replace(/\{\{.*?\}\}/, '/kitchen/orders');
  const ui = vm.runInNewContext('({' + method + '})', { fetch });
  Object.assign(ui, { _ordersRequestSerial: 0, _ordersAppliedSerial: 0, orders: [] });
  Object.defineProperty(ui, 'aggregateVoidOrders', { get: () => ui.orders.filter(o => o.void) });
  let prints = 0;
  let beeps = 0;
  ui.checkAutoPrint = () => { prints++; };
  ui.playUrgentBeep = () => { beeps++; };
  const previous = ui.refreshOrders();
  await ui.refreshOrders();
  previousBody.resolve([{ id: 1 }]);
  await previous;
  assert.equal(ui.orders[0].id, 2);
  assert.equal(prints, 1);
  assert.equal(beeps, 1);
});
