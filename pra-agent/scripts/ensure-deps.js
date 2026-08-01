// Preflight for CI builds: the Windows CI npm bug ("Exit handler never called")
// can exit non-zero (or zero) while leaving node_modules incomplete — even
// `npm ci` reproduces it. Strategy: try progressively smaller installs, and
// after each attempt trust the FILESYSTEM, not npm's exit code. Fail loudly
// only if the runtime deps are still physically missing.
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const { prodDepPaths } = require('./dep-tree');

const root = path.join(__dirname, '..');
// FULL transitive production tree from the lockfile — direct deps alone are
// not enough (axios present but form-data missing shipped a broken exe).
const deps = prodDepPaths(root);

function missing() {
  return deps.filter((d) => !fs.existsSync(path.join(root, d, 'package.json')));
}

function attempt(label, cmd) {
  console.log('[ensure-deps] attempt: ' + label + ' → ' + cmd);
  try {
    execSync(cmd, { cwd: root, stdio: 'inherit' });
  } catch (e) {
    console.log('[ensure-deps] ' + label + ' exited non-zero (known npm CI bug) — checking filesystem anyway');
  }
  const bad = missing();
  if (bad.length) {
    console.log('[ensure-deps] still missing after ' + label + ': ' + bad.join(', '));
    return false;
  }
  return true;
}

let bad = missing();
if (bad.length) {
  console.log('[ensure-deps] missing runtime deps: ' + bad.join(', '));
  const ok =
    attempt('prod-only install', 'npm install --omit=dev --ignore-scripts --no-audit --no-fund') ||
    attempt('full npm ci', 'npm ci --no-audit --no-fund') ||
    attempt('clean reinstall', (process.platform === 'win32' ? 'rmdir /s /q node_modules & ' : 'rm -rf node_modules && ') + 'npm install --omit=dev --ignore-scripts --no-audit --no-fund');
  if (!ok) {
    console.error('[ensure-deps] FATAL: runtime deps still missing after all attempts: ' + missing().join(', '));
    process.exit(1);
  }
}
console.log('[ensure-deps] OK: all runtime deps present (' + deps.join(', ') + ')');
