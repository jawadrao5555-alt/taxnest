'use strict';

const assert = require('node:assert/strict');
const { EventEmitter } = require('node:events');
const Module = require('node:module');
const test = require('node:test');

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
    pendingPrints.push({ owner: this.owner, deviceName: options.deviceName, callback });
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

  const printing = printer.printHtml('<html>default</html>', undefined, 'kot');
  await waitForPending(1);
  assert.notEqual(pendingPrints[0].owner, discoveryWindow);
  assert.equal(pendingPrints[0].deviceName, undefined);
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