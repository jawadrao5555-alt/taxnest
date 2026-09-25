'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const packageJson = require('../package.json');
const packageLock = require('../package-lock.json');
const { validateUpdateInfo } = require('../src/release-manifest');

const good = {
  product: 'taxnest-pra-agent',
  version: packageJson.version,
  asset_name: 'TaxNest-PRA-Agent-Windows.zip',
  zip_size: 1024,
  zip_sha256: 'a'.repeat(64),
  source_sha: 'b'.repeat(40),
  build_sha: 'b'.repeat(40),
  min_agent_version: '1.3.0',
  max_agent_version: '2.99.99',
};

test('package and lock release identity stay aligned beyond immutable v1.13.6', () => {
  assert.equal(packageJson.version, '1.13.10');
  assert.equal(packageLock.version, packageJson.version);
  assert.equal(packageLock.packages[''].version, packageJson.version);
  assert.notEqual(packageJson.version, '1.13.6');
});

test('accepts a complete compatible canonical release manifest', () => {
  assert.deepEqual(validateUpdateInfo(good, '1.13.5'), { ok: true });
});

test('refuses missing or ambiguous release identity before download', () => {
  for (const patch of [
    { zip_sha256: '' },
    { asset_name: 'someone-elses-largest.zip' },
    { product: 'other-agent' },
    { source_sha: 'not-a-sha' },
    { build_sha: 'c'.repeat(40) },
    { zip_size: 0 },
  ]) {
    assert.equal(validateUpdateInfo({ ...good, ...patch }, '1.13.5').ok, false);
  }
});

test('refuses a release outside the installed agent compatibility range', () => {
  assert.deepEqual(validateUpdateInfo({ ...good, min_agent_version: '1.14.0' }, '1.13.5'),
    { ok: false, code: 'release_manifest_incompatible' });
});
