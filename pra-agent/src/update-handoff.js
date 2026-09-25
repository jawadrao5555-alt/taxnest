'use strict';

// The updater replaces only this installation. Never terminate every process
// sharing the Agent executable name: a different shop/profile may use one.
function buildUpdateHandoffScript({ exePath, srcDir, destDir, backupDir }) {
  const quote = (value) => {
    const s = String(value);
    if (!s || /["\r\n%]/.test(s)) throw new Error('invalid updater path');
    return `"${s}"`;
  };
  const target = quote(exePath);
  const source = quote(srcDir);
  const destination = quote(destDir);
  const backup = quote(backupDir);
  return [
    '@echo off',
    'timeout /t 3 /nobreak >nul',
    // main.js calls stopAgent() then app.quit(). A forced PID kill here could
    // hit an unrelated process if Windows reuses the exited Agent's PID.
    // Locked files keep the existing copy/restore recovery path intact.
    `robocopy ${destination} ${backup} /E /R:2 /W:2 >nul`,
    'if %ERRORLEVEL% GEQ 8 goto launch',
    'set RETRIES=0',
    ':copyloop',
    `robocopy ${source} ${destination} /E /R:5 /W:2 >nul`,
    'if %ERRORLEVEL% LSS 8 goto launch',
    'set /a RETRIES+=1',
    'if %RETRIES% GEQ 5 goto restore',
    'timeout /t 3 /nobreak >nul',
    'goto copyloop',
    ':restore',
    `robocopy ${backup} ${destination} /E /R:5 /W:2 >nul`,
    ':launch',
    `if not exist ${target} robocopy ${backup} ${destination} /E /R:5 /W:2 >nul`,
    `cd /d ${destination}`,
    `start "" ${target}`,
    'exit',
  ].join('\r\n');
}

module.exports = { buildUpdateHandoffScript };
