'use strict';

const crypto = require('node:crypto');
const path = require('node:path');

function quote(value) {
  const text = String(value || '');
  if (!text || /["\r\n%]/.test(text)) throw new Error('invalid recovery path');
  return `"${text}"`;
}
function psCommand(script) {
  return 'powershell.exe -NoProfile -NonInteractive -EncodedCommand ' + Buffer.from(script, 'utf16le').toString('base64');
}
function literal(value) { return "'" + String(value).replace(/'/g, "''") + "'"; }
function recoveryRegistration(exePath, recoveryPath) {
  quote(exePath); quote(recoveryPath);
  const name = 'TaxNestUpdateRecovery-' + crypto.createHash('sha256').update(exePath.toLowerCase()).digest('hex').slice(0, 20);
  const command = `"${process.env.SystemRoot || 'C:\\Windows'}\\System32\\cmd.exe" /d /v:off /c ""${recoveryPath}""`;
  const open = "$k=[Microsoft.Win32.Registry]::CurrentUser.CreateSubKey('Software\\Microsoft\\Windows\\CurrentVersion\\RunOnce');";
  return {
    name,
    register: psCommand(`$ErrorActionPreference='Stop';try{${open}$k.SetValue(${literal(name)},${literal(command)},[Microsoft.Win32.RegistryValueKind]::String);$k.Close();exit 0}catch{exit 1}`),
    unregister: psCommand(`$ErrorActionPreference='Stop';try{${open}$k.DeleteValue(${literal(name)},$false);$k.Close();exit 0}catch{exit 1}`),
  };
}

function registerRecovery(exePath, recoveryPath, execFile = require('node:child_process').execFile) {
  const command = recoveryRegistration(exePath, recoveryPath).register;
  const encoded = command.split(' ').at(-1);
  return new Promise((resolve, reject) => execFile('powershell.exe',
    ['-NoProfile','-NonInteractive','-EncodedCommand',encoded],
    {windowsHide:true,timeout:30000}, error => error ? reject(error) : resolve()));
}

function assertRecoveryPaths({exePath,destDir,backupDir,recoveryPath,readyPath,resultPath,logPath}) {
  for (const value of [exePath,destDir,backupDir,recoveryPath,readyPath,resultPath,logPath]) quote(value);
  const resolve = value => path.win32.resolve(value).toLowerCase();
  const dest = resolve(destDir), backup = resolve(backupDir);
  const contains = (parent,child) => {
    const relative=path.win32.relative(parent,child);
    return relative==='' || (!relative.startsWith('..\\') && relative!=='..' && !path.win32.isAbsolute(relative));
  };
  if (dest===path.win32.parse(dest).root || resolve(path.win32.dirname(exePath))!==dest)
    throw new Error('recovery must target only this installation');
  if (contains(dest,backup)||contains(backup,dest))throw new Error('backup overlaps installation');
  for(const file of [recoveryPath,readyPath,resultPath,logPath])
    if(contains(dest,resolve(file)))throw new Error('recovery state must be outside installation');
}

// Independent of Electron/app.asar: Windows can run this after an interrupted
// in-place copy even when the Agent executable cannot start. Paths are supplied
// only by the local updater, never by the release manifest or a web renderer.
function buildRecoveryScript({exePath, destDir, backupDir, readyPath, resultPath, logPath, recoveryPath}, {registration} = {}) {
  assertRecoveryPaths({exePath,destDir,backupDir,recoveryPath,readyPath,resultPath,logPath});
  const reg = registration || recoveryRegistration(exePath, recoveryPath);
  const exe = quote(exePath), backup = quote(backupDir), dest = quote(destDir);
  const ready = quote(readyPath), result = quote(resultPath), log = quote(logPath);
  return [
    '@echo off', 'setlocal DisableDelayedExpansion',
    // FINDSTR /x can miss a complete marker without trailing CRLF. Compare
    // exact trimmed content so a finished install is never rolled back merely
    // because the final newline was not persisted.
    `${psCommand(`try {if ([IO.File]::ReadAllText(${literal(resultPath)}).Trim() -ceq 'installed') {exit 0}} catch {};exit 1`)} >>${log} 2>&1`,
    'if not errorlevel 1 goto launch', // a completed copy must not be rolled back
    `if not exist ${ready} goto untouched`, // copy has not started; old install is intact
    // A normal login may also start the Agent. Never copy over an active
    // installation or kill its work; wait briefly, then retain explicit repair.
    `${psCommand(`$ErrorActionPreference='Stop';$p=@(Get-Process -ErrorAction SilentlyContinue | Where-Object {try{$_.Path -eq ${literal(exePath)}}catch{$false}});foreach($a in $p){if(-not $a.WaitForExit(15000)){exit 1}};exit 0`)} >>${log} 2>&1`,
    'if errorlevel 1 goto busy',
    `robocopy ${backup} ${dest} /E /R:5 /W:2 >>${log} 2>&1`,
    'if errorlevel 8 goto failed',
    `fc /b ${quote(path.win32.join(backupDir,'resources','app.asar'))} ${quote(path.win32.join(destDir,'resources','app.asar'))} >nul 2>&1`,
    'if errorlevel 1 goto failed',
    `echo recovered_old_version>${result}`,
    'goto launch',
    ':untouched', `echo recovered_before_copy>${result}`,
    ':launch',
    `if not exist ${exe} goto failed`,
    `${reg.unregister} >>${log} 2>&1`,
    // Keep backup and this repair script, even after successful recovery.
    `cd /d ${dest}`, `start "" ${exe}`, 'exit /b 0',
    ':busy', `echo recovery_waiting_for_agent>${result}`, 'goto retry',
    ':failed', `echo failed_restore>${result}`,
    ':retry',
    // At most three automatic recovery logins; then retain the backup/script
    // for explicit repair rather than retrying forever under a broken policy.
    `if exist ${quote(resultPath + '.retry2')} exit /b 1`,
    `if exist ${quote(resultPath + '.retry1')} (echo retry>${quote(resultPath + '.retry2')}) else (echo retry>${quote(resultPath + '.retry1')})`,
    `${reg.register} >>${log} 2>&1`,
    'exit /b 1',
  ].join('\r\n');
}
module.exports = {buildRecoveryScript, recoveryRegistration, registerRecovery, assertRecoveryPaths};
