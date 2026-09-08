'use strict';

const assert = require('node:assert/strict');
const http = require('node:http');
const test = require('node:test');
const { createGateway, secretMatches } = require('../gateway');

function post(port, headers) {
  return new Promise((resolve, reject) => {
    const request = http.request({ host: '127.0.0.1', port, path: '/internal/wake', method: 'POST',
      headers: { 'content-type': 'application/json', ...headers } }, (response) => {
      let text = ''; response.on('data', (part) => { text += part; });
      response.on('end', () => resolve({ status: response.statusCode, body: JSON.parse(text) }));
    });
    request.on('error', reject); request.end(JSON.stringify({ company_id: 1, device_uid: null, job_id: 'j' }));
  });
}

test('secretMatches is exact, length-guarded and rejects unset secrets', () => {
  assert.equal(secretMatches('wake-secret', 'wake-secret'), true);
  assert.equal(secretMatches('wake-secreT', 'wake-secret'), false);
  assert.equal(secretMatches('wake-secret-longer', 'wake-secret'), false);
  assert.equal(secretMatches('wake', 'wake-secret'), false);
  assert.equal(secretMatches('', 'wake-secret'), false);
  assert.equal(secretMatches(undefined, 'wake-secret'), false);
  assert.equal(secretMatches(['wake-secret'], 'wake-secret'), false);
  // An unconfigured gateway must never accept anything, including ''.
  assert.equal(secretMatches('', ''), false);
  assert.equal(secretMatches(undefined, ''), false);
  // Multi-byte input: byte length, not code-point length, drives the guard.
  assert.equal(secretMatches('sécret', 'secret'), false);
  assert.equal(secretMatches('sécret', 'sécret'), true);
});

// node --test runs each file in its own process concurrently, and a falsy
// `port: 0` falls back to the default 6101 that gateway.test.js binds — so
// these servers use their own fixed loopback ports.
test('wake endpoint enforces the secret through the constant-time compare', async (t) => {
  const gateway = createGateway({ port: 6198, wakeSecret: 'wake-secret', authenticate: () => null });
  await gateway.listen();
  const port = gateway.server.address().port;
  t.after(async () => gateway.close());

  assert.equal((await post(port, { 'x-wake-secret': 'wake-secret' })).status, 200);
  assert.equal((await post(port, { 'x-wake-secret': 'wake-secreX' })).status, 401);
  assert.equal((await post(port, { 'x-wake-secret': 'wake-secret-extra' })).status, 401);
  assert.equal((await post(port, { 'x-wake-secret': '' })).status, 401);
  assert.equal((await post(port, {})).status, 401);
});

test('wake endpoint with no configured secret rejects every caller', async (t) => {
  const gateway = createGateway({ port: 6199, wakeSecret: '', authenticate: () => null });
  await gateway.listen();
  const port = gateway.server.address().port;
  t.after(async () => gateway.close());

  assert.equal((await post(port, { 'x-wake-secret': '' })).status, 401);
  assert.equal((await post(port, {})).status, 401);
});
