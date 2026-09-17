import assert from 'node:assert/strict';
import { chmodSync, mkdtempSync, mkdirSync, symlinkSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import pw from 'playwright-core';
import { resolveChromiumPath } from '../lib/local-browser.mjs';

const { chromium } = pw;
const root = mkdtempSync(path.join(os.tmpdir(), 'taxnest-local-browser-'));
const chromePath = (name) => path.join(root, name, 'chrome');
const executable = (filePath, mode = 0o755) => {
    mkdirSync(path.dirname(filePath), { recursive: true });
    writeFileSync(filePath, '#!/bin/sh\nexit 0\n');
    chmodSync(filePath, mode);
    return filePath;
};
const discover = (candidate, trustedRoot = root) =>
    resolveChromiumPath({
        candidates: [candidate],
        trustedRoots: [trustedRoot],
        playwrightExecutablePath: null,
        allowSystemRoots: false,
    });

const regular = executable(chromePath('regular'));
assert.equal(discover(regular), regular, 'regular executable is discovered');
assert.equal(discover(chromePath('missing')), null, 'missing executable is ignored');

const nonExecutable = executable(chromePath('nonexec'), 0o644);
assert.equal(discover(nonExecutable), null, 'non-executable file is ignored');

const target = executable(chromePath('symlink-target'));
const symlink = chromePath('symlink');
mkdirSync(path.dirname(symlink), { recursive: true });
symlinkSync(target, symlink);
assert.equal(discover(symlink), null, 'symlink executable is rejected');

const outsideRoot = path.join(os.tmpdir(), `taxnest-local-browser-outside-${process.pid}`);
const outside = executable(path.join(outsideRoot, 'chrome'));
assert.equal(discover(outside), null, 'executable outside trusted root is rejected');
assert.equal(discover(`${root}/../${path.basename(outsideRoot)}/chrome`), null, 'path escape is rejected');

const directory = path.join(root, 'directory', 'chrome');
mkdirSync(directory, { recursive: true });
assert.equal(discover(directory), null, 'directory named chrome is rejected');

const parentSymlink = path.join(root, 'parent-link');
symlinkSync(outsideRoot, parentSymlink);
assert.equal(discover(`${parentSymlink}/chrome`), null, 'parent symlink escape is rejected');

const playwrightRoot = path.join(root, 'playwright-cache');
const publicApiPath = chromium.executablePath();
const publicApiInstall = publicApiPath.split(/[\\/]/).find((part) => /^chromium-\d+$/.test(part));
assert.ok(publicApiInstall, 'public Playwright API exposes a chromium revision path');
const playwrightChrome = path.join(playwrightRoot, publicApiInstall, 'chrome-linux64', 'chrome');
executable(playwrightChrome);
const headless = executable(path.join(
    playwrightRoot,
    publicApiInstall.replace(/^chromium-/, 'chromium_headless_shell-'),
    'chrome-headless-shell-linux64',
    'chrome-headless-shell',
));
executable(path.join(
    playwrightRoot,
    'chromium_headless_shell-1',
    'chrome-headless-shell-linux64',
    'chrome-headless-shell',
));
assert.equal(
    resolveChromiumPath({
        candidates: [],
        playwrightExecutablePath: playwrightChrome,
        trustedRoots: [playwrightRoot],
        allowSystemRoots: false,
    }),
    playwrightChrome,
    'public Playwright API Chromium path is discovered',
);
assert.equal(
    resolveChromiumPath({
        candidates: [],
        playwrightExecutablePath: path.join(playwrightRoot, 'chromium-9999', 'chrome-linux64', 'chrome'),
        trustedRoots: [playwrightRoot],
        allowSystemRoots: false,
    }),
    null,
    'stale headless-shell revision is not selected',
);
assert.equal(
    resolveChromiumPath({
        candidates: [],
        playwrightExecutablePath: path.join(playwrightRoot, publicApiInstall, 'chrome-linux64', 'missing-chrome'),
        trustedRoots: [playwrightRoot],
        allowSystemRoots: false,
    }),
    headless,
    'Playwright headless-shell layout is discovered',
);

console.log('local-browser-discovery-check: ALL PASS');