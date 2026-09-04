const assert = require('assert');
const {
  DEFAULT_SERVER_URL,
  canonicalAgentServerUrl,
  migrateAgentConfig,
} = require('../src/server-url');

assert.strictEqual(
  canonicalAgentServerUrl('https://taxnest.com.pk/api/agent'),
  DEFAULT_SERVER_URL
);
assert.strictEqual(
  canonicalAgentServerUrl('https://www.taxnest.com.pk/api/agent/'),
  DEFAULT_SERVER_URL
);
assert.strictEqual(
  canonicalAgentServerUrl('http://taxnest.com.pk/custom/path'),
  DEFAULT_SERVER_URL
);
assert.strictEqual(
  canonicalAgentServerUrl(DEFAULT_SERVER_URL),
  DEFAULT_SERVER_URL
);
assert.strictEqual(
  canonicalAgentServerUrl('https://example.test/api/agent'),
  'https://example.test/api/agent'
);
assert.strictEqual(
  canonicalAgentServerUrl('http://192.168.1.10:5000/api/agent'),
  'http://192.168.1.10:5000/api/agent'
);

const original = {
  serverUrl: 'https://taxnest.com.pk/api/agent',
  apiKey: 'test-key',
  companyId: 23,
};
const migrated = migrateAgentConfig(original);
assert.strictEqual(migrated.changed, true);
assert.deepStrictEqual(migrated.config, {
  ...original,
  serverUrl: DEFAULT_SERVER_URL,
});
assert.strictEqual(original.serverUrl, 'https://taxnest.com.pk/api/agent');

const custom = migrateAgentConfig({
  serverUrl: 'http://localhost:5000/api/agent',
  apiKey: 'test-key',
  companyId: 23,
});
assert.strictEqual(custom.changed, false);

console.log('server-url migration tests passed');