// Shared: full PRODUCTION dependency tree from package-lock.json.
// Direct deps are not enough — 1 Aug 2026 the exe shipped with axios present
// but its transitive dep 'form-data' missing ("Cannot find module 'form-data'").
const fs = require('fs');
const path = require('path');

function prodDepPaths(root) {
  const lock = JSON.parse(fs.readFileSync(path.join(root, 'package-lock.json'), 'utf8'));
  const pkgs = lock.packages || {};
  const out = [];
  for (const [key, meta] of Object.entries(pkgs)) {
    if (!key || !key.includes('node_modules/')) continue;
    if (meta.dev || meta.optional === true || meta.devOptional) continue;
    out.push(key); // e.g. "node_modules/form-data" or nested "node_modules/a/node_modules/b"
  }
  return out;
}

module.exports = { prodDepPaths };
