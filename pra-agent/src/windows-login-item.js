'use strict';

const { execFile } = require('node:child_process');
const { promisify } = require('node:util');
const run = promisify(execFile);

// Clean up only the old Electron startup entry when it launches THIS exact
// executable. An unrelated Electron app or an older installation is retained.
async function retireLegacyElectronLogin({ platform = process.platform, exePath = process.execPath, exec = run } = {}) {
  if (platform !== 'win32' || !exePath) return false;
  const key = 'HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Run';
  let output;
  try {
    ({ stdout: output } = await exec('reg.exe', ['query', key, '/v', 'Electron'], { windowsHide: true }));
  } catch (_) { return false; }
  const line = String(output).split(/\r?\n/).find((s) => /^\s*Electron\s+REG_(?:SZ|EXPAND_SZ)\s+/i.test(s));
  if (!line) return false;
  const command = line.replace(/^\s*Electron\s+REG_(?:SZ|EXPAND_SZ)\s+/i, '').trim();
  const actual = command.startsWith('"') ? command.match(/^"([^"]+)"(?:\s|$)/)?.[1] : command.match(/^([^\s]+)(?:\s|$)/)?.[1];
  if (!actual || actual.toLowerCase() !== exePath.toLowerCase()) return false;
  await exec('reg.exe', ['delete', key, '/v', 'Electron', '/f'], { windowsHide: true });
  return true;
}

module.exports = { retireLegacyElectronLogin };
