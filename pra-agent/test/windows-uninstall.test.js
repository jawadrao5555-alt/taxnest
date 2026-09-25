'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const { registerPortableUninstall, UNINSTALL_KEY } = require('../src/windows-uninstall');

const base = 'C:\\Users\\Counter 1\\AppData\\Local';
const install = path.win32.join(base, 'TaxNest PRA Agent');
const exe = path.win32.join(install, 'TaxNest PRA Agent.exe');
const wrapper = path.win32.join(install, 'uninstall.cmd');
const oldUninstaller = path.win32.join(install, 'uninstall.bat');

test('an upgraded legacy ZIP install registers its executable and working uninstaller for this user', async () => {
  const calls = [];
  const ok = await registerPortableUninstall({
    platform: 'win32', exePath: exe, localAppData: base, version: '1.13.9',
    existsSync: file => [exe, wrapper, oldUninstaller].includes(file),
    run: async (bin, args, options) => { calls.push({ bin, args, options }); },
  });
  assert.equal(ok, true);
  assert.equal(calls.length, 8);
  assert(calls.every(call => call.bin === 'reg.exe' && call.args[1] === UNINSTALL_KEY
    && call.options.windowsHide === true));
  assert.deepEqual(calls[0].args.slice(2),
    ['/v', 'UninstallString', '/t', 'REG_SZ', '/d', `cmd.exe /d /c ""${wrapper}""`, '/f']);
  assert.equal(calls.at(-1).args[3], 'DisplayName');
  assert.equal(calls[3].args[7], '1.13.9');
});

test('NSIS installs and incomplete portable installs never gain a duplicate or broken entry', async () => {
  const calls = [];
  for (const options of [
    { platform: 'linux', exePath: exe },
    { platform: 'win32', exePath: 'C:\\Program Files\\TaxNest PRA Agent\\TaxNest PRA Agent.exe' },
    { platform: 'win32', exePath: exe, existsSync: file => file !== oldUninstaller },
    { platform: 'win32', exePath: exe, version: 'bad' },
  ]) {
    assert.equal(await registerPortableUninstall({
      platform: 'win32', exePath: exe, localAppData: base, version: '1.13.9',
      existsSync: () => true,
      run: async () => { calls.push(1); },
      ...options,
    }), false);
  }
  assert.equal(calls.length, 0);
});

test('failed registry write does not report success or prevent normal Agent startup', async () => {
  let calls = 0;
  assert.equal(await registerPortableUninstall({
    platform: 'win32', exePath: exe, localAppData: base, version: '1.13.9',
    existsSync: () => true,
    run: async () => { calls++; throw new Error('Access denied'); },
  }), false);
  assert.equal(calls, 1);
});

test('portable ZIP carries wrapper, and uninstall removes only its own registry entry', () => {
  const wrapperSource = fs.readFileSync(path.join(__dirname, '..', 'uninstall.cmd'), 'utf8');
  assert(wrapperSource.includes(`reg delete "${UNINSTALL_KEY}" /f`));
  assert(wrapperSource.includes('call "%~dp0uninstall.bat"'));
  const installer = fs.readFileSync(path.join(__dirname, '..', 'install.bat'), 'utf8');
  assert(installer.includes(`echo reg delete "${UNINSTALL_KEY}" /f`));
  assert(!wrapperSource.includes('taxnest-pra-agent\\config.json'));
});
