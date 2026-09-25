'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { retireLegacyElectronLogin } = require('../src/windows-login-item');
const { buildUpdateHandoffScript } = require('../src/update-handoff');

test('only retire an Electron startup entry bound to the same exact executable', async () => {
  const exePath = 'C:\\Users\\QA\\AppData\\Local\\TaxNest PRA Agent\\TaxNest PRA Agent.exe';
  const calls = [];
  const exec = async (_program, args) => {
    calls.push(args);
    return { stdout: `\r\n    Electron    REG_SZ    "${exePath}" --autostart\r\n` };
  };
  assert.equal(await retireLegacyElectronLogin({ platform: 'win32', exePath, exec }), true);
  assert.deepEqual(calls.map((x) => x[0]), ['query', 'delete']);
  assert.deepEqual(calls[1].slice(-3), ['/v', 'Electron', '/f']);

  for (const command of [
    '"C:\\Other\\Electron.exe" --autostart',
    '"C:\\Users\\QA\\AppData\\Local\\TaxNest PRA Agent\\TaxNest PRA Agent.exe.old"',
    '"C:\\Users\\QA\\AppData\\Local\\TaxNest PRA Agent\\TaxNest PRA Agent.exe"junk',
  ]) {
    const attempted = [];
    const other = async (_program, args) => {
      attempted.push(args);
      return { stdout: `Electron REG_SZ ${command}\r\n` };
    };
    assert.equal(await retireLegacyElectronLogin({ platform: 'win32', exePath, exec: other }), false);
    assert.equal(attempted.length, 1);
  }
  assert.equal(await retireLegacyElectronLogin({ platform: 'linux', exePath, exec }), false);
});

test('updater waits for graceful quit and retains rollback and relaunch', () => {
  const script = buildUpdateHandoffScript({
    exePath: 'C:\\Agent\\TaxNest PRA Agent.exe',
    srcDir: 'C:\\Temp\\new',
    destDir: 'C:\\Agent',
    backupDir: 'C:\\Temp\\backup',
  });
  assert.doesNotMatch(script, /taskkill|uninstall/i);
  assert.match(script, /robocopy "C:\\Temp\\backup" "C:\\Agent"/);
  assert.match(script, /start "" "C:\\Agent\\TaxNest PRA Agent.exe"/);
  assert.throws(() => buildUpdateHandoffScript({ exePath: '"bad"' }), /path/);
});
