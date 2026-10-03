'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const http = require('node:http');
const crypto = require('node:crypto');
const { createRequire } = require('node:module');

test('builder resolves the reviewed downloader and verifies local artifact checksums', async () => {
  const builderRequire = createRequire(require.resolve('app-builder-lib'));
  const get = builderRequire('@electron/get');
  assert.equal(typeof get.downloadArtifact, 'function');
  assert.equal(get, require('@electron/get'));
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'taxnest-downloader-'));
  const bytes = Buffer.from('fictional Electron artifact, no live downloads');
  const digest = crypto.createHash('sha256').update(bytes).digest('hex');
  const server = http.createServer((_req, res) => { res.end(bytes); });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const details = {
    version: '41.7.1', isGeneric: true, artifactName: 'fixture.zip',
    mirrorOptions: { resolveAssetURL: () => `http://127.0.0.1:${server.address().port}/fixture.zip` },
    cacheRoot: path.join(root, 'cache'), tempDirectory: root,
    checksums: { 'fixture.zip': digest }, downloadOptions: { quiet: true },
  };
  try {
    const downloaded = await get.downloadArtifact(details);
    assert.deepEqual(fs.readFileSync(downloaded), bytes);
    const wrong = { ...details, force: true, cacheRoot: path.join(root, 'bad-cache'),
      checksums: { 'fixture.zip': '0'.repeat(64) } };
    await assert.rejects(get.downloadArtifact(wrong), /checksum|mismatch|hash/i);
  } finally {
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('the reviewed brace fork passes attack and compatibility tests in the verification lane', () => {
  require('node:child_process').execFileSync(process.execPath, ['--stack_size=512', '--test', path.resolve(__dirname, '../../scripts/tests/bounded-braces-security.cjs')], { stdio: 'pipe' });
});
