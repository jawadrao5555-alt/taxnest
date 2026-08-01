// Preflight for CI builds: the Windows CI npm bug ("Exit handler never called")
// can exit 0 while leaving node_modules incomplete. Verify runtime deps exist
// and reinstall if anything is missing. Fails loudly if still broken.
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const pkg = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));
const deps = Object.keys(pkg.dependencies || {});

function missing() {
  return deps.filter((d) => !fs.existsSync(path.join(root, 'node_modules', d, 'package.json')));
}

let bad = missing();
if (bad.length) {
  console.log('[ensure-deps] missing runtime deps: ' + bad.join(', ') + ' — reinstalling via npm ci');
  execSync('npm ci --no-audit --no-fund', { cwd: root, stdio: 'inherit' });
  bad = missing();
}
if (bad.length) {
  console.error('[ensure-deps] FATAL: runtime deps still missing after npm ci: ' + bad.join(', '));
  process.exit(1);
}
console.log('[ensure-deps] OK: all runtime deps present (' + deps.join(', ') + ')');
