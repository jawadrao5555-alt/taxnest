'use strict';
// Launch only the acceptance harness, never production main.js or an installed Agent.
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const root = path.resolve(process.argv[2] || path.join(__dirname, '..'));
// Plain Node cannot traverse app.asar. Electron below must require the actual
// coordinator from the archive; a missing packaged module still fails there.
if (!fs.existsSync(root) || (!root.endsWith('.asar') && !fs.existsSync(path.join(root, 'src/company-configurator.js')))) {
  throw Error('Missing tested package/source');
}
const harness = process.argv[3] === 'downloads' ? 'native-download-harness.js' : 'native-pairing-harness.js';
if (process.argv[3] && process.argv[3] !== 'downloads') throw Error('Unknown native harness');
const executable = require('electron');
const env = { ...process.env }; delete env.ELECTRON_RUN_AS_NODE;
const r = spawnSync(executable, [path.join(__dirname, harness), root], { env, stdio: 'inherit', timeout: 180000 });
if (r.error) throw r.error;
if (r.status !== 0) process.exit(r.status || 1);
