'use strict';

const assert = require('node:assert/strict');
const http = require('node:http');
const test = require('node:test');
const WebSocket = require('ws');
const { createGateway } = require('../gateway');

function post(port, body, secret = 'wake-secret') {
  return new Promise((resolve, reject) => {
    const request = http.request({ host: '127.0.0.1', port, path: '/internal/wake', method: 'POST',
      headers: { 'content-type': 'application/json', 'x-wake-secret': secret } }, (response) => {
      let text = ''; response.on('data', (part) => { text += part; });
      response.on('end', () => resolve({ status: response.statusCode, body: JSON.parse(text) }));
    });
    request.on('error', reject); request.end(JSON.stringify(body));
  });
}
function open(port, device, token = 'test', headers = {}) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(`ws://127.0.0.1:${port}/agent-realtime?device_uid=${device}`, {
      headers: { Authorization: `Bearer ${token}`, ...headers },
    });
    ws.once('open', () => resolve(ws)); ws.once('error', reject);
  });
}

test('authenticates identities and broadcasts only to intended company/device', async (t) => {
  const gateway = createGateway({ port: 0, wakeSecret: 'wake-secret', authenticate: ({ deviceUid }) =>
    deviceUid === 'a' ? { company_id: 1, device_uid: 'a' } : { company_id: 2, device_uid: deviceUid } });
  await gateway.listen();
  const port = gateway.server.address().port;
  t.after(async () => gateway.close());
  const one = await open(port, 'a');
  const two = await open(port, 'b');
  t.after(() => { one.terminate(); two.terminate(); });
  const received = new Promise((resolve) => one.once('message', (value) => resolve(JSON.parse(value))));
  let other = false; two.once('message', () => { other = true; });
  const result = await post(port, { company_id: 1, device_uid: 'a', job_id: 'j1' });
  assert.deepEqual(result.body, { ok: true, delivered: 1 });
  assert.equal((await received).job_id, 'j1');
  await new Promise((resolve) => setTimeout(resolve, 20));
  assert.equal(other, false);
});

test('rejects failed authentication and bad internal secrets', async (t) => {
  const gateway = createGateway({ port: 0, wakeSecret: 'wake-secret', authenticate: () => null });
  await gateway.listen(); const port = gateway.server.address().port;
  t.after(async () => gateway.close());
  await assert.rejects(() => open(port, 'nope'));
  assert.equal((await post(port, { company_id: 1, device_uid: null, job_id: 'j' }, 'bad')).status, 401);
});

test('accepts only canonical safe Laravel company id strings', async (t) => {
  const gateway = createGateway({ port: 0, wakeSecret: 'wake-secret', authenticate: ({ deviceUid }) =>
    ({ company_id: deviceUid === 'one' ? '1' : '01', device_uid: deviceUid }) });
  await gateway.listen(); const port = gateway.server.address().port;
  t.after(async () => gateway.close());
  const accepted = await open(port, 'one');
  t.after(() => accepted.terminate());
  await assert.rejects(() => open(port, 'bad'));
});

test('caps simultaneous pending authentication work', async (t) => {
  let release;
  const blocked = new Promise((resolve) => { release = resolve; });
  const gateway = createGateway({
    port: 0,
    wakeSecret: 'wake-secret',
    maxPendingAuth: 1,
    authenticate: async ({ deviceUid }) => {
      await blocked;
      return { company_id: 1, device_uid: deviceUid };
    },
  });
  await gateway.listen();
  const port = gateway.server.address().port;
  t.after(async () => gateway.close());
  const first = new WebSocket(`ws://127.0.0.1:${port}/agent-realtime?device_uid=a`, {
    headers: { Authorization: 'Bearer test' },
  });
  await new Promise((resolve) => setTimeout(resolve, 10));
  await assert.rejects(() => open(port, 'b'));
  release();
  await new Promise((resolve, reject) => {
    first.once('open', resolve);
    first.once('error', reject);
  });
  first.terminate();
});

test('hard-caps per-IP rate state without per-request full-map cleanup', async (t) => {
  const gateway = createGateway({
    port: 0,
    wakeSecret: 'wake-secret',
    maxRateLimitEntries: 3,
    authenticate: () => null,
  });
  await gateway.listen();
  const port = gateway.server.address().port;
  t.after(async () => gateway.close());
  for (let i = 1; i <= 8; i += 1) {
    await assert.rejects(() => open(port, `d${i}`, 'bad', { 'X-Forwarded-For': `198.51.100.${i}` }));
  }
  assert.equal(gateway.metrics.rateLimitEntries, 3);
  assert.ok(gateway.metrics.rateLimited >= 5);
});