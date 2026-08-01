// Post-build gate: fail the build if the packed app.asar is missing runtime
// node_modules (the exact failure that shipped a broken setup.exe on 1 Aug 2026:
// installed app crashed "Cannot find module 'axios'").
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const { prodDepPaths } = require('./dep-tree');

const root = path.join(__dirname, '..');
// FULL transitive production tree — axios alone passed while form-data was
// missing, shipping a second broken exe on 1 Aug 2026.
const deps = prodDepPaths(root);
const asar = path.join(root, 'dist', 'win-unpacked', 'resources', 'app.asar');

if (!fs.existsSync(asar)) {
  console.error('[verify-asar] FATAL: ' + asar + ' not found — build did not produce win-unpacked output');
  process.exit(1);
}

const listing = execSync('npx --yes @electron/asar@3 list "' + asar + '"', {
  cwd: root,
  encoding: 'utf8',
  maxBuffer: 64 * 1024 * 1024,
});
const lines = new Set(listing.split(/\r?\n/));

const norm = new Set([...lines].map((l) => l.replace(/\\/g, '/')));
const bad = deps.filter((d) => !norm.has('/' + d + '/package.json'));
if (bad.length) {
  console.error('[verify-asar] FATAL: app.asar is missing runtime deps: ' + bad.join(', '));
  console.error('[verify-asar] Do NOT ship this build.');
  process.exit(1);
}
if (![...lines].some((l) => l.replace(/\\/g, '/') === '/main.js')) {
  console.error('[verify-asar] FATAL: app.asar missing /main.js');
  process.exit(1);
}
console.log('[verify-asar] OK: app.asar contains main.js + node_modules for: ' + deps.join(', '));
