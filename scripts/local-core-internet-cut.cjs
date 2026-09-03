'use strict';

// Single-command Electron release-gate entry point (never a GUI skip).
const childProcess = require('child_process');
const path = require('path');

const test = childProcess.spawn(process.execPath, [
    path.join(__dirname, '..', 'pra-agent', 'test', 'local-core-internet-cut.test.js'),
], { stdio: 'inherit' });

test.on('error', (error) => {
    console.error('Unable to start Local Core harness:', error.message);
    process.exitCode = 1;
});
test.on('exit', (code, signal) => {
    if (signal) {
        console.error('Local Core harness terminated by ' + signal);
        process.exitCode = 1;
    } else {
        process.exitCode = code || 0;
    }
});