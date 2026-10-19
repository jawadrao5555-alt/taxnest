const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { writeBuildInfo } = require('../scripts/write-build-info');
const { createBuildInfo, parseBuildInfo, loadBuildInfo } = require('../src/build-info');
const { mount } = require('../src/build-info-view');

test('packaging writes immutable date; runtime reads it without resetting to startup time', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'agent-build-date-test-'));
  try {
    fs.writeFileSync(path.join(root, 'package.json'), JSON.stringify({ version: '1.13.20' }));
    const now = new Date('2026-10-17T14:05:06.000Z');
    writeBuildInfo(root, now);
    now.setUTCFullYear(2030);
    const info = loadBuildInfo('1.13.20', path.join(root, 'build-info.json'));
    assert.equal(info.builtAt, '2026-10-17T14:05:06.000Z');
    assert.equal(info.build, '20261017-140506');
    assert.equal(info.version, '1.13.20');
    assert.equal(loadBuildInfo('1.13.19', path.join(root, 'build-info.json')).builtAt, null);
  } finally { fs.rmSync(root, { recursive: true, force: true }); }
});

test('missing, malformed, stale-version and invalid date metadata never invent a build date', () => {
  const valid = createBuildInfo('1.13.20', new Date('2026-10-17T14:05:06.000Z'));
  for (const raw of [null, 'broken', {}, { ...valid, version: '1.13.19' }, { ...valid, built_at: 'invalid' }, { ...valid, built_at: '2026-02-31T00:00:00.000Z' }]) {
    assert.deepEqual(parseBuildInfo(raw, '1.13.20'), { version: '1.13.20', build: 'unknown', builtAt: null });
  }
  assert.equal(loadBuildInfo('1.13.20', path.join(os.tmpdir(), 'missing-agent-build-info.json')).builtAt, null);
  assert.throws(() => createBuildInfo('invalid', new Date()));
});

function documentFixture() {
  const nodes = Object.fromEntries(['agentVersion', 'buildVer'].map(id => [id, { textContent: '', removeAttribute(key) { delete this[key === 'datetime' ? 'dateTime' : key]; } }]));
  return { nodes, getElementById: id => nodes[id] };
}

test('real display module renders IPC version and date, and preserves explicit unknown date', async () => {
  const document = documentFixture();
  const info = parseBuildInfo(createBuildInfo('1.13.20', new Date('2026-10-17T14:05:06.000Z')), '1.13.20');
  await mount(document, { getVersion: async () => info });
  assert.equal(document.nodes.agentVersion.textContent, 'v1.13.20');
  assert.equal(document.nodes.buildVer.dateTime, info.builtAt);
  assert.match(document.nodes.buildVer.textContent, /2026/);
  await mount(document, { getVersion: async () => ({ version: '1.13.20', builtAt: null }) });
  assert.equal(document.nodes.agentVersion.textContent, 'v1.13.20');
  assert.equal(document.nodes.buildVer.textContent, 'Not available');
  assert.equal(document.nodes.buildVer.dateTime, undefined);
});

test('failed identity IPC cannot leave blank loading labels or crash other settings', async () => {
  const document = documentFixture();
  await mount(document, { getVersion: async () => { throw new Error('IPC unavailable'); } });
  assert.equal(document.nodes.agentVersion.textContent, 'Unavailable');
  assert.equal(document.nodes.buildVer.textContent, 'Not available');
});
