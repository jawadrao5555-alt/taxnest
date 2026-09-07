'use strict';

const assert = require('assert');
const { ALLOWED, redactLogLine, processPendingCommands } = require('../src/live-ops-commands');

assert.ok(ALLOWED.has('RESYNC'));
assert.ok(ALLOWED.has('PRINTER_REFRESH'));
assert.ok(ALLOWED.has('UPLOAD_REDACTED_LOGS'));
assert.ok(ALLOWED.has('SAFE_AGENT_RESTART'));
assert.ok(ALLOWED.has('TEST_PRINT'));
assert.ok(ALLOWED.has('STATUS_REFRESH'));
assert.ok(!ALLOWED.has('SHELL'));
assert.ok(!ALLOWED.has('rm -rf'));

assert.strictEqual(redactLogLine('Bearer abc.def.ghi'), 'Bearer [REDACTED]');
assert.ok(redactLogLine('password=secret123').includes('[REDACTED]'));

(async () => {
  const posts = [];
  const fakeAxios = {
    post: async (url, body) => {
      posts.push({ url, body });
      return { data: { ok: true } };
    },
  };

  let synced = false;
  let printers = 0;
  let nudged = false;
  let restarted = 0;
  const logs = [];

  await processPendingCommands({
    config: { serverUrl: 'https://example.test/api/agent', apiKey: 'k' },
    commands: [
      { command_id: 'c1', type: 'RESYNC' },
      { command_id: 'c2', type: 'PRINTER_REFRESH' },
      { command_id: 'c3', type: 'UPLOAD_REDACTED_LOGS' },
      { command_id: 'c4', type: 'TEST_PRINT' },
      { command_id: 'c5', type: 'SAFE_AGENT_RESTART' },
      { command_id: 'c6', type: 'STATUS_REFRESH' },
      { command_id: 'bad', type: 'SHELL' },
    ],
    axios: fakeAxios,
    syncOnce: async () => { synced = true; },
    refreshPrinters: async () => { printers += 1; return { reported: true }; },
    getRecentLogs: () => ['Bearer tokensecret', 'ok line'],
    enqueueTestPrint: async () => { nudged = true; return { poll_nudged: true, pipeline: 'pos_print_jobs' }; },
    requestRestart: () => { restarted += 1; return true; },
    getStatus: () => ({ running: true, connected: true }),
    log: (m) => logs.push(String(m)),
  });

  // Allow deferred SAFE_AGENT_RESTART timer
  await new Promise((r) => setTimeout(r, 1600));

  assert.strictEqual(synced, true);
  assert.strictEqual(printers, 1);
  assert.strictEqual(nudged, true);
  assert.strictEqual(restarted, 1);
  assert.ok(posts.some((p) => p.body.command_id === 'c1' && p.body.acked));
  assert.ok(posts.some((p) => p.body.command_id === 'c1' && p.body.ok === true));
  assert.ok(posts.some((p) => p.body.command_id === 'c3' && p.body.ok === true
    && Array.isArray(p.body.result.logs)
    && p.body.result.logs.some((l) => String(l).includes('[REDACTED]'))));
  assert.ok(posts.some((p) => p.body.command_id === 'c4' && p.body.ok === true
    && p.body.result.test_print && p.body.result.test_print.pipeline === 'pos_print_jobs'));
  assert.ok(posts.some((p) => p.body.command_id === 'c5' && p.body.ok === true
    && p.body.result.restart_scheduled === true));
  assert.ok(!posts.some((p) => p.body.command_id === 'bad'));

  // Missing restart handler fails cleanly
  posts.length = 0;
  await processPendingCommands({
    config: { serverUrl: 'https://example.test/api/agent', apiKey: 'k' },
    commands: [{ command_id: 'c7', type: 'SAFE_AGENT_RESTART' }],
    axios: fakeAxios,
    log: () => {},
  });
  assert.ok(posts.some((p) => p.body.command_id === 'c7' && p.body.ok === false));

  console.log('live-ops-commands.test.js OK');
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
