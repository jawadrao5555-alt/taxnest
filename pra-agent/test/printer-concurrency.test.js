'use strict';

const assert = require('node:assert/strict');
const { EventEmitter } = require('node:events');
const Module = require('node:module');
const test = require('node:test');
const { processCloudPrintJob } = require('../src/printer-job');

const windows = [];
const pendingPrints = [];

class FakeWebContents extends EventEmitter {
  constructor(owner) {
    super();
    this.owner = owner;
  }

  async getPrintersAsync() {
    return [];
  }

  async executeJavaScript() {
    return 'fonts-ok';
  }

  print(options, callback) {
    pendingPrints.push({ owner: this.owner, deviceName: options.deviceName, options, callback });
  }
}

class FakeBrowserWindow extends EventEmitter {
  constructor() {
    super();
    this.destroyed = false;
    this.webContents = new FakeWebContents(this);
    windows.push(this);
  }

  isDestroyed() {
    return this.destroyed;
  }

  loadFile() {
    setImmediate(() => this.webContents.emit('did-finish-load'));
    return Promise.resolve();
  }

  destroy() {
    if (this.destroyed) return;
    this.destroyed = true;
    this.emit('closed');
  }
}

const originalLoad = Module._load;
Module._load = function mockedLoad(request, parent, isMain) {
  if (request === 'electron') return { BrowserWindow: FakeBrowserWindow };
  return originalLoad.call(this, request, parent, isMain);
};
const printer = require('../src/printer');
Module._load = originalLoad;

async function waitForPending(count) {
  const deadline = Date.now() + 1500;
  while (pendingPrints.length < count && Date.now() < deadline) {
    await new Promise((resolve) => setTimeout(resolve, 10));
  }
  assert.equal(pendingPrints.length, count);
}

test.afterEach(() => {
  while (pendingPrints.length) pendingPrints.shift().callback(true);
});

// This exercises the real cloud-job -> printHtml -> Electron boundary. The
// Electron window is mocked; it does not claim a physical printer passed.
test('cloud tickets and bills request the selected printer paper defaults', async () => {
  const cases = [
    ['kot', 'Kitchen 58mm'],
    ['kot', 'Kitchen 80mm'],
    ['kot_void', 'Kitchen 58mm'],
    ['bill', 'Counter 80mm'],
    ['proof', 'Counter 58mm'],
    ['test', 'Kitchen 80mm'],
  ];
  for (const [type, deviceName] of cases) {
    const reports = [];
    const job = { id: 1, type, target_printer: deviceName };
    const result = processCloudPrintJob(job, {
      fetchContent: async () => ({ status: 200, data: '<html><body>Item 1</body></html>' }),
      printHtml: printer.printHtml,
      report: async (_, evidence) => reports.push(evidence),
    });
    await waitForPending(1);
    const pending = pendingPrints.shift();
    try {
      assert.equal(pending.options.usePrinterDefaultPageSize, true, `${type}: use the selected queue's paper, not Electron's A4 fallback`);
      assert.equal(pending.options.deviceName, deviceName);
      assert.equal(pending.options.silent, true);
      assert.equal(pending.options.printBackground, true);
      assert.deepEqual(pending.options.margins, { marginType: 'none' });
      assert.equal(Object.hasOwn(pending.options, 'pageSize'), false, 'do not force 58/80mm or override the saved driver paper');
    } finally {
      pending.callback(true);
      await result;
    }
    assert.equal(reports.length, 1);
    assert.equal(reports[0].outcome, 'spool_accepted');
    assert.equal(pendingPrints.length, 0, 'one cloud job must not produce a second print');
  }
});

test('different printers use different windows and can spool concurrently', async () => {
  const first = printer.printHtml('<html>one</html>', 'p1', 'kot');
  const second = printer.printHtml('<html>two</html>', '92', 'kot');

  await waitForPending(2);
  assert.deepEqual(new Set(pendingPrints.map((p) => p.deviceName)), new Set(['p1', '92']));
  assert.notEqual(pendingPrints[0].owner, pendingPrints[1].owner);

  pendingPrints.shift().callback(true);
  pendingPrints.shift().callback(true);
  assert.deepEqual(await Promise.all([first, second]), [
    { success: true, error: null },
    { success: true, error: null },
  ]);
});

test('same printer waits for its prior spool callback', async () => {
  const first = printer.printHtml('<html>one</html>', 'p3', 'kot');
  const second = printer.printHtml('<html>two</html>', 'p3', 'kot');

  await waitForPending(1);
  assert.equal(pendingPrints[0].deviceName, 'p3');
  pendingPrints.shift().callback(true);

  await waitForPending(1);
  assert.equal(pendingPrints[0].deviceName, 'p3');
  pendingPrints.shift().callback(true);
  await Promise.all([first, second]);
});

test('default printer lane never shares the discovery window', async () => {
  const before = windows.length;
  await printer.getLocalPrinters();
  const discoveryWindow = windows[before];

  // The Desktop IPC bridge also calls printHtml without a job type.
  const printing = printer.printHtml('<html>default</html>', undefined);
  await waitForPending(1);
  assert.notEqual(pendingPrints[0].owner, discoveryWindow);
  assert.equal(pendingPrints[0].deviceName, undefined);
  assert.equal(pendingPrints[0].options.usePrinterDefaultPageSize, true);
  pendingPrints.shift().callback(true);
  await printing;
});

test('stop and restart does not clear an in-flight printer lane', async () => {
  const first = printer.printHtml('<html>old</html>', 'restart-printer', 'kot');
  await waitForPending(1);
  const firstWindow = pendingPrints[0].owner;

  printer.stopPrinting();
  const second = printer.printHtml('<html>new</html>', 'restart-printer', 'kot');
  await new Promise((resolve) => setTimeout(resolve, 150));
  assert.equal(pendingPrints.length, 1);

  pendingPrints.shift().callback(true);
  await waitForPending(1);
  assert.equal(pendingPrints[0].owner, firstWindow);
  pendingPrints.shift().callback(true);
  await Promise.all([first, second]);
});
