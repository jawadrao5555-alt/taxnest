'use strict';

// Local Core internet-cut / reconnect KOT release-gate.
// Always runs the deterministic production-module harness. If the project
// Electron binary is already present (authorized by a prior pra-agent
// install), also runs the real Electron BrowserWindow test.
const childProcess = require('child_process');
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const harness = path.join(root, 'pra-agent', 'test', 'local-core-internet-cut-harness.test.js');
const electronTest = path.join(root, 'pra-agent', 'test', 'local-core-internet-cut.test.js');
const electronBin = path.join(root, 'pra-agent', 'node_modules', 'electron', 'dist', 'electron');

function run(file) {
    return new Promise((resolve) => {
        const child = childProcess.spawn(process.execPath, [file], { stdio: 'inherit' });
        child.on('error', (error) => {
            console.error('Unable to start Local Core harness:', error.message);
            resolve(1);
        });
        child.on('exit', (code, signal) => {
            if (signal) {
                console.error('Local Core harness terminated by ' + signal);
                resolve(1);
            } else {
                resolve(code || 0);
            }
        });
    });
}

(async () => {
    const harnessCode = await run(harness);
    if (harnessCode !== 0) {
        process.exitCode = harnessCode;
        return;
    }
    if (!fs.existsSync(electronBin)) {
        console.log('Electron binary not present in repository setup; deterministic Local Core harness is the release-gate.');
        process.exitCode = 0;
        return;
    }
    process.env.LOCAL_CORE_RELEASE_GATE = '1';
    process.exitCode = await run(electronTest);
})();
