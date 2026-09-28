'use strict';

// The updater replaces only this installation. Never terminate every process
// sharing the Agent executable name: a different shop/profile may use one.
function buildUpdateHandoffScript({ exePath, srcDir, destDir, backupDir, logPath, resultPath, sourcePid }) {
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
  const pid = Number(sourcePid);
  if (sourcePid != null && (!Number.isSafeInteger(pid) || pid <= 0)) throw new Error('invalid updater PID');
  return [
    '@echo off',
    // Detached cmd.exe has no console input; Windows timeout can fail instantly
    // in that environment. Wait for exactly the Agent instance that handed off
    // instead of racing its open app.asar / executable file locks.
    ...(sourcePid == null ? [] : [
      `powershell.exe -NoProfile -NonInteractive -Command "try { Wait-Process -Id ${pid} -Timeout 45 -ErrorAction Stop } catch {}" >>${log} 2>&1`,
    ]),
    'powershell.exe -NoProfile -NonInteractive -Command "Start-Sleep -Seconds 2" >nul 2>&1',
    `if not exist ${quote(pathJoin(srcDir, 'resources', 'app.asar'))} goto source_missing`,
    // main.js calls stopAgent() then app.quit(). A forced PID kill here could
    // hit an unrelated process if Windows reuses the exited Agent's PID.
    // Locked files keep the existing copy/restore recovery path intact.
    `robocopy ${destination} ${backup} /E /R:2 /W:2 >${log} 2>&1`,
    'if errorlevel 8 goto backup_failed',
    'set RETRIES=0',
    ':copyloop',
    `robocopy ${source} ${destination} /E /R:5 /W:2 >>${log} 2>&1`,
    'if not errorlevel 8 goto verify',
    'set /a RETRIES+=1',
    'if %RETRIES% GEQ 5 goto restore',
    'powershell.exe -NoProfile -NonInteractive -Command "Start-Sleep -Seconds 3" >nul 2>&1',
    'goto copyloop',
    ':verify',
    `fc /b ${quote(pathJoin(srcDir, 'resources', 'app.asar'))} ${quote(pathJoin(destDir, 'resources', 'app.asar'))} >nul 2>&1`,
    'if errorlevel 1 goto restore',
    `echo installed>${result}`,
    'goto launch',
    ':restore',
    `echo failed_copy_or_verify>${result}`,
    `robocopy ${backup} ${destination} /E /R:5 /W:2 >>${log} 2>&1`,
    `if errorlevel 8 echo failed_restore>${result}`,
    'goto launch',
    ':source_missing',
    `echo failed_source_missing>${result}`,
    'goto launch',
    ':backup_failed',
    `echo failed_backup>${result}`,
    ':launch',
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
