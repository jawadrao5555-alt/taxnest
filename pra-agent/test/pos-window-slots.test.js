'use strict';
const assert = require('node:assert/strict');
const { EventEmitter } = require('node:events');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

test('POS windows retain independent sessions and reopen without affecting the other slot', () => {
  const sessions = new Map();

  class Window extends EventEmitter {
    constructor(options) {
      super();
      this.options = options;
      const partition = options.webPreferences.partition;
      if (!sessions.has(partition)) sessions.set(partition, new EventEmitter());
      this.webContents = Object.assign(new EventEmitter(), {
        session: sessions.get(partition),
        getURL: () => this.url,
        setWindowOpenHandler: (handler) => { this.popup = handler; },
      });
    }

    loadURL(url) { this.url = url; return Promise.resolve(); }
    isDestroyed() { return !!this.destroyed; }
    isMinimized() { return false; }
    show() { this.hidden = false; }
    hide() { this.hidden = true; }
    focus() {}
    close() {
      let prevented = false;
      this.emit('close', { preventDefault: () => { prevented = true; } });
      if (!prevented) {
        this.destroyed = true;
        this.emit('closed');
      }
    }
  }

  const filename = path.join(__dirname, '../src/pos-window.js');
  const context = {
    __dirname: path.dirname(filename),
    module: { exports: {} },
    process,
    URL,
    require(name) {
      if (name === 'electron') return { BrowserWindow: Window, Notification: { isSupported: () => false } };
      if (name === './company-login-hook') return { wireCompanyLogin() {} };
      if (name === './offline-snapshot') return {};
      return require(name);
    },
  };
  vm.runInNewContext(fs.readFileSync(filename, 'utf8'), context, { filename });
  const { openPosWindow, getPosWindowRef, closePosWindow, isSecondaryPosPrintSender } = context.module.exports;
  const config = { serverUrl: 'http://127.0.0.1:8080' };
  assert.throws(() => openPosWindow(config, { slotNumber: 3 }), /Invalid POS/);
  const first = openPosWindow(config);
  const second = openPosWindow(config, { slotNumber: 2 });
  assert.equal(getPosWindowRef(), first);
  assert.equal(getPosWindowRef(2), second);
  assert.notEqual(first.webContents.session, second.webContents.session);
  assert.equal(first.options.webPreferences.partition, 'persist:pos');
  assert.equal(second.options.webPreferences.partition, 'persist:pos-2');
  assert.equal(path.basename(second.options.webPreferences.preload), 'pos-print-preload.js');
  const frame = { url: config.serverUrl + '/pos/invoice/create' };
  second.webContents.mainFrame = frame;
  assert.equal(isSecondaryPosPrintSender({ sender: second.webContents, senderFrame: frame }), true);
  assert.equal(isSecondaryPosPrintSender({ sender: second.webContents, senderFrame: { ...frame } }), false);
  assert.equal(isSecondaryPosPrintSender({ sender: first.webContents, senderFrame: frame }), false);
  frame.url = config.serverUrl + '/pos/login';
  assert.equal(isSecondaryPosPrintSender({ sender: second.webContents, senderFrame: frame }), false);
  frame.url = 'https://example.invalid/pos/invoice/create';
  assert.equal(isSecondaryPosPrintSender({ sender: second.webContents, senderFrame: frame }), false);
  assert.equal(second.popup({ url: config.serverUrl + '/pos/receipt' })
    .overrideBrowserWindowOptions.webPreferences.partition, 'persist:pos-2');
  second.close();
  assert.equal(second.hidden, true);
  assert.equal(first.hidden, undefined);
  assert.equal(openPosWindow(config, { slotNumber: 2 }), second);
  assert.equal(second.hidden, false);
  closePosWindow();
  assert.equal(getPosWindowRef(), null);
  assert.equal(getPosWindowRef(2), null);
});
