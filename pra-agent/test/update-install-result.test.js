'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { failedHandoff } = require('../src/update-install-result');

const newer = (remote, local) => remote !== local;

test('failed file replacement reports install stage after old Agent restarts', () => {
  const state = { target: '1.13.15', count: 1, resultPath: 'C:\\Temp\\apply-update.result' };
  const result = failedHandoff(state, '1.13.14', newer, () => 'failed_copy_or_verify\n');
  assert.equal(result.target, '1.13.15');
  assert.equal(result.stage, 'install');
  assert.match(result.error, /restore attempted/);
  assert.match(failedHandoff(state, '1.13.14', newer, () => 'failed_restore').error, /Manual installation is required/);
  assert.equal(failedHandoff(state, '1.13.15', newer, () => { throw Error('should not read'); }), null);
});

test('missing result and copied files with old version never masquerade as success', () => {
  const state = { target: '1.13.15', resultPath: 'C:\\Temp\\apply-update.result' };
  assert.match(failedHandoff(state, '1.13.14', newer, () => { throw Error('missing'); }).error, /did not report/);
  assert.match(failedHandoff(state, '1.13.14', newer, () => 'installed').error, /old Agent version/);
});
