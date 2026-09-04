const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const root = path.resolve(__dirname, '..');
const dist = path.join(root, 'dist');
const unpacked = path.join(dist, 'win-unpacked');
const stage = path.join(dist, 'TaxNest-PRA-Agent');
const output = path.join(dist, 'TaxNest-PRA-Agent-Windows.zip');
const keepUnpacked = process.argv.includes('--keep-unpacked');

const asarPath = path.join(unpacked, 'resources', 'app.asar');
if (!fs.existsSync(asarPath) || !fs.statSync(asarPath).isFile()) {
  throw new Error('win-unpacked/resources/app.asar is missing');
}

fs.rmSync(stage, { recursive: true, force: true });
fs.rmSync(output, { force: true });
fs.cpSync(unpacked, stage, { recursive: true });
fs.copyFileSync(path.join(root, 'install.bat'), path.join(stage, 'install.bat'));

let result;
if (process.platform === 'win32') {
  const ps = [
    '$ErrorActionPreference = "Stop"',
    `Compress-Archive -Path '${stage.replace(/'/g, "''")}'`,
    `-DestinationPath '${output.replace(/'/g, "''")}' -Force`,
  ].join('; ');
  result = spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', ps], {
    cwd: dist,
    stdio: 'inherit',
  });
} else {
  result = spawnSync('zip', ['-qr', output, 'TaxNest-PRA-Agent'], {
    cwd: dist,
    stdio: 'inherit',
  });
}

if (result.error) throw result.error;
if (result.status !== 0) throw new Error(`portable ZIP command exited ${result.status}`);
if (!fs.existsSync(output) || !fs.statSync(output).isFile() || fs.statSync(output).size < 1_000_000) {
  throw new Error('portable ZIP was not created or is unexpectedly small');
}

fs.rmSync(stage, { recursive: true, force: true });
if (!keepUnpacked) {
  // The existing GitHub workflow only runs its legacy Compress-Archive step
  // when win-unpacked exists. Removing it protects this canonical top-level
  // ZIP from being overwritten with a flat, self-update-incompatible archive.
  fs.rmSync(unpacked, { recursive: true, force: true });
}

console.log(`[package-portable] OK: ${output} (${fs.statSync(output).size} bytes)`);