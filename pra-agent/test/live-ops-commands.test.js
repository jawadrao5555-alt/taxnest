'use strict';

const assert = require('assert');
const { ALLOWED, redactLogLine, processPendingCommands } = require('../src/live-ops-commands');

assert.ok(ALLOWED.has('RESYNC'));
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
  await processPendingCommands({
    config: { serverUrl: 'https://example.test/api/agent', apiKey: 'k' },
    commands: [
      { command_id: 'c1', type: 'RESYNC' },
      { command_id: 'bad', type: 'SHELL' },
    ],
    axios: fakeAxios,
    syncOnce: async () => { synced = true; },
    log: () => {},
  });

  assert.strictEqual(synced, true);
  assert.ok(posts.some((p) => p.body.command_id === 'c1' && p.body.acked));
  assert.ok(posts.some((p) => p.body.command_id === 'c1' && p.body.ok === true));
  assert.ok(!posts.some((p) => p.body.command_id === 'bad'));

  console.log('live-ops-commands.test.js OK');
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
