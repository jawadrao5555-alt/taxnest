'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { execFile } = require('node:child_process');
const { promisify } = require('node:util');

const UNINSTALL_KEY = 'HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\TaxNestPRAAgentPortable';

// Only the ZIP/install.bat layout owns this entry. NSIS has its own uninstall
// registration and must never be changed by a portable-install repair.
async function registerPortableUninstall({
  platform = process.platform,
  exePath = process.execPath,
  localAppData = process.env.LOCALAPPDATA,
  version,
  existsSync = fs.existsSync,
  run = promisify(execFile),
} = {}) {
  if (platform !== 'win32' || !localAppData || !/^\d+\.\d+\.\d+$/.test(String(version))) return false;
  const installDir = path.win32.join(localAppData, 'TaxNest PRA Agent');
  const exe = path.win32.join(installDir, 'TaxNest PRA Agent.exe');
  const uninstall = path.win32.join(installDir, 'uninstall.cmd');
  if (path.win32.normalize(exePath).toLowerCase() !== exe.toLowerCase()
    || !existsSync(exe) || !existsSync(uninstall)
    || !existsSync(path.win32.join(installDir, 'uninstall.bat'))) return false;

  const entries = [
    ['UninstallString', `cmd.exe /d /c ""${uninstall}""`, 'REG_SZ'],
    ['InstallLocation', installDir, 'REG_SZ'],
    ['DisplayIcon', exe, 'REG_SZ'],
    ['DisplayVersion', version, 'REG_SZ'],
    ['Publisher', 'TaxNest', 'REG_SZ'],
    ['NoModify', '1', 'REG_DWORD'],
    ['NoRepair', '1', 'REG_DWORD'],
    // DisplayName last: a partially failed registration must not show a
    // misleading Windows Installed Apps entry without an uninstall command.
    ['DisplayName', 'TaxNest PRA Agent', 'REG_SZ'],
  ];
  for (const [name, value, type] of entries) {
    try {
      await run('reg.exe', ['add', UNINSTALL_KEY, '/v', name, '/t', type, '/d', value, '/f'], {
        windowsHide: true,
        timeout: 5000,
      });
    } catch (error) {
      return false; // Registry health never blocks the Agent or printing.
    }
  }
  return true;
}

module.exports = { registerPortableUninstall, UNINSTALL_KEY };
