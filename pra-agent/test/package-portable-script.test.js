const assert = require('assert');
const fs = require('fs');
const path = require('path');

const script = fs.readFileSync(
  path.join(__dirname, '..', 'scripts', 'package-portable.js'),
  'utf8'
);
const pkg = require('../package.json');

assert(pkg.scripts['build:win'].includes('node scripts/package-portable.js'));
assert(script.includes("path.join(dist, 'TaxNest-PRA-Agent')"));
assert(script.includes("path.join(dist, 'TaxNest-PRA-Agent-Windows.zip')"));
assert(script.includes("fs.copyFileSync(path.join(root, 'install.bat')"));
assert(script.includes("fs.rmSync(unpacked, { recursive: true, force: true })"));
assert(script.includes("'$ErrorActionPreference = \"Stop\";'"));
assert(script.includes("].join(' ')"));

console.log('portable packaging guard tests passed');