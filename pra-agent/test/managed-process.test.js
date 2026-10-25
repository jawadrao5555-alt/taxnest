'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const { once } = require('node:events');
const { managedProcess, stopManagedProcess } = require('./helpers/managed-process');
function launch(code, group = false) {
  const child = spawn(process.execPath, ['-e', code], { detached: group, stdio: ['ignore', 'pipe', 'pipe'] });
  return managedProcess(child, group);
}
test('direct child cleanup closes inherited pipes and is safely repeatable', { timeout: 10000 }, async () => {
  const fixture = launch('process.stdout.write("ready");setInterval(()=>{},1000)');
  await once(fixture.child.stdout, 'data');
  await stopManagedProcess(fixture);
  assert.equal(fixture.closed, true);
  await stopManagedProcess(fixture);
});
test('POSIX wrapper cleanup includes its grandchild and leaves unrelated processes alive', {
  timeout: 10000, skip: process.platform === 'win32' ? 'POSIX process-group semantics' : false,
}, async () => {
  const unrelated = launch('process.stdout.write("ready");setInterval(()=>{},1000)');
  const fixture = launch('const {spawn}=require("node:child_process");const c=spawn(process.execPath,["-e","process.stdout.write(\\"grandchild ready\\");setInterval(()=>{},1000)"],{stdio:["ignore","inherit","inherit"]});setInterval(()=>{},1000)', true);
  try {
    await Promise.all([once(unrelated.child.stdout, 'data'), once(fixture.child.stdout, 'data')]);
    await stopManagedProcess(fixture);
    assert.equal(fixture.closed, true, 'wait for inherited grandchild pipes to close');
    assert.equal(unrelated.closed, false, 'do not signal unrelated fixtures');
    assert.equal(unrelated.child.exitCode, null);
  } finally { await stopManagedProcess(fixture); await stopManagedProcess(unrelated); }
});
