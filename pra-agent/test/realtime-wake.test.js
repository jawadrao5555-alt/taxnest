'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { EventEmitter } = require('node:events');
const { RealtimeWakeClient, realtimeUrl } = require('../src/realtime-wake');

class FakeSocket extends EventEmitter {
  close() { this.emit('close'); }
  removeAllListeners() { return super.removeAllListeners(); }
}

test('uses upgrade headers, wakes only on wake messages, and reconnects', async () => {
  const made = [];
  class FakeWebSocket extends FakeSocket {
    constructor(url, options) { super(); this.url = url; this.options = options; made.push(this); }
  }
  let wakeCount = 0;
  const client = new RealtimeWakeClient({
    WebSocket: FakeWebSocket, url: 'wss://example.test/agent-realtime?device_uid=d',
    apiKey: 'secret', baseDelay: 1, random: () => 0, onWake: () => { wakeCount += 1; },
  });
  client.start();
  assert.equal(made[0].options.headers.Authorization, 'Bearer secret');
  assert.ok(!made[0].url.includes('secret'));
  made[0].emit('open');
  made[0].emit('message', Buffer.from('{"type":"wake"}'));
  made[0].emit('message', Buffer.from('{"type":"ignored"}'));
  assert.equal(wakeCount, 1);
  made[0].emit('close');
  await new Promise((resolve) => setTimeout(resolve, 5));
  assert.equal(made.length, 2);
  client.stop();
});

test('builds websocket URL without credentials', () => {
  assert.equal(realtimeUrl('https://shop.test/api', 'a b'), 'wss://shop.test/agent-realtime?device_uid=a+b');
});