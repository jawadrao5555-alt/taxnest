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
// A nested lockfile entry (a/node_modules/b) may legitimately be hoisted to
// top-level node_modules/b inside the asar — accept either location.
const bad = deps.filter((d) => {
  if (norm.has('/' + d + '/package.json')) return false;
  const name = d.replace(/^.*node_modules\//, '');
  return !norm.has('/node_modules/' + name + '/package.json');
});
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

// Verify the actual packed date/version, not a date fabricated when the app starts.
const { parseBuildInfo } = require('../src/build-info');
const version = require('../package.json').version;
const expected = parseBuildInfo(fs.readFileSync(path.join(root, 'build-info.json'), 'utf8'), version);
const temporary = fs.mkdtempSync(path.join(require('os').tmpdir(), 'taxnest-build-info-'));
try {
  if (!norm.has('/build-info.json') || !expected.builtAt) throw new Error('Valid build-info.json missing');
  execSync('npx --yes @electron/asar@3 extract-file "' + asar + '" build-info.json', {
    cwd: temporary, encoding: 'utf8', maxBuffer: 1024 * 1024,
  });
  const packed = parseBuildInfo(fs.readFileSync(path.join(temporary, 'build-info.json'), 'utf8'), version);
  if (!packed.builtAt || packed.builtAt !== expected.builtAt) throw new Error('Packed build identity does not match this build');
  console.log('[verify-asar] OK: packed Agent version and build date match this build');
} finally { fs.rmSync(temporary, { recursive: true, force: true }); }
