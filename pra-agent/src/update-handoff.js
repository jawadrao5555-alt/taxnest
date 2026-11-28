'use strict';

const { recoveryRegistration, assertRecoveryPaths } = require('./update-recovery');

// The updater replaces only this installation. Never terminate every process
// sharing the Agent executable name: a different shop/profile may use one.
function buildUpdateHandoffScript({ exePath, srcDir, destDir, backupDir, logPath, resultPath, sourcePid, recoveryPath, readyPath }) {
  const quote = (value) => {
    const s = String(value);
    if (!s || /["\r\n%]/.test(s)) throw new Error('invalid updater path');
    return `"${s}"`;
  };
  const target = quote(exePath);
  const source = quote(srcDir);
  const destination = quote(destDir);
  const backup = quote(backupDir);
  const log = logPath ? quote(logPath) : 'nul';
  const result = resultPath ? quote(resultPath) : 'nul';
  const mark = stage => `echo [%DATE% %TIME%] ${stage}>>${log}`;
  const pid = Number(sourcePid);
  if (sourcePid != null && (!Number.isSafeInteger(pid) || pid <= 0)) throw new Error('invalid updater PID');
  if (!!recoveryPath !== !!readyPath) throw new Error('recovery and ready paths must be paired');
  if (recoveryPath) assertRecoveryPaths({exePath,destDir,backupDir,recoveryPath,readyPath,resultPath,logPath});
  const recovery = recoveryPath ? recoveryRegistration(exePath, recoveryPath) : null;
  return [
    '@echo off', 'setlocal DisableDelayedExpansion', mark('Waiting for Agent exit'),
    // Detached cmd.exe has no console input; Windows timeout can fail instantly
    // in that environment. Wait for exactly the Agent instance that handed off
    // instead of racing its open app.asar / executable file locks.
    ...(sourcePid == null ? [] : [
      `powershell.exe -NoProfile -NonInteractive -Command "$p=Get-Process -Id ${pid} -ErrorAction SilentlyContinue; if($p -and -not $p.WaitForExit(15000)){exit 1}; exit 0" >>${log} 2>&1`,
      'if errorlevel 1 goto agent_busy',
    ]),
    ...(sourcePid == null ? [
      'powershell.exe -NoProfile -NonInteractive -Command "Start-Sleep -Seconds 2" >nul 2>&1',
    ] : []),
    `if not exist ${quote(pathJoin(srcDir, 'resources', 'app.asar'))} goto source_missing`,
    // main.js calls stopAgent() then app.quit(). A forced PID kill here could
    // hit an unrelated process if Windows reuses the exited Agent's PID.
    // Locked files keep the existing copy/restore recovery path intact.
    mark('Backing up Agent files'),
    `robocopy ${destination} ${backup} /E /MT:4 /NP /R:2 /W:2 >>${log} 2>&1`,
    'if errorlevel 8 goto backup_failed',
    ...(recovery ? [
      // Register recovery BEFORE the first write to the installation. Denied
      // HKCU registration aborts safely, leaving the old installation intact.
      `${recovery.register} >>${log} 2>&1`,
      'if errorlevel 1 goto recovery_unavailable',
      `fc /b ${quote(pathJoin(destDir, 'resources', 'app.asar'))} ${quote(pathJoin(backupDir, 'resources', 'app.asar'))} >nul 2>&1`,
      'if errorlevel 1 goto backup_failed',
      `echo ready>${quote(readyPath)}`,
      // Never start an in-place copy without a durable, verified backup marker.
      `if not exist ${quote(readyPath)} goto backup_failed`,
      `echo copying>${result}`,
    ] : []),
    'set RETRIES=0',
    ':copyloop',
    mark('Applying update files'),
    `robocopy ${source} ${destination} /E /MT:4 /NP /R:2 /W:2 >>${log} 2>&1`,
    'if not errorlevel 8 goto verify',
    'set /a RETRIES+=1',
    'if %RETRIES% GEQ 2 goto restore',
    'powershell.exe -NoProfile -NonInteractive -Command "Start-Sleep -Seconds 3" >nul 2>&1',
    'goto copyloop',
    ':verify',
    mark('Verifying Agent files'),
    `fc /b ${quote(pathJoin(srcDir, 'resources', 'app.asar'))} ${quote(pathJoin(destDir, 'resources', 'app.asar'))} >nul 2>&1`,
    'if not errorlevel 1 goto installed',
    // Robocopy can skip a changed archive whose length and timestamp match.
    // Force a copy of that archive only, then check the bytes again.
    mark('Recopying Agent archive after verification mismatch'),
    `robocopy ${quote(pathJoin(srcDir, 'resources'))} ${quote(pathJoin(destDir, 'resources'))} app.asar /IS /R:2 /W:2 /NP >>${log} 2>&1`,
    'if errorlevel 8 goto restore',
    `fc /b ${quote(pathJoin(srcDir, 'resources', 'app.asar'))} ${quote(pathJoin(destDir, 'resources', 'app.asar'))} >>${log} 2>&1`,
    'if errorlevel 1 goto restore',
    ':installed',
    `echo installed>${result}`,
    ...(recovery ? [`${recovery.unregister} >>${log} 2>&1`] : []),
    'goto launch',
    ':restore',
    `echo failed_copy_or_verify>${result}`,
    `robocopy ${backup} ${destination} /E /IS /R:5 /W:2 >>${log} 2>&1`,
    `if errorlevel 8 echo failed_restore>${result}`,
    'goto launch',
    ...(recovery ? [':recovery_unavailable', `echo failed_recovery_registration>${result}`, 'goto launch'] : []),
    ':source_missing',
    `echo failed_source_missing>${result}`,
    'goto launch',
    ...(sourcePid == null ? [] : [
      ':agent_busy', mark('Previous Agent did not exit; no files changed'),
      `echo failed_agent_busy>${result}`,
      ...(recovery ? [`${recovery.unregister} >>${log} 2>&1`] : []),
      'exit /b 1',
    ]),
    ':backup_failed',
    `echo failed_backup>${result}`,
    ':launch',
    mark('Relaunching Agent'),
    `if not exist ${target} robocopy ${backup} ${destination} /E /R:5 /W:2 >>${log} 2>&1`,
    `cd /d ${destination}`,
    `start "" ${target}`,
    'exit',
  ].join('\r\n');
}

function pathJoin(...parts) {
  // The updater runs on Windows even when this script is tested on Linux.
  return require('node:path').win32.join(...parts);
}

module.exports = { buildUpdateHandoffScript };
