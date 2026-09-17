import assert from 'node:assert/strict';
import { chmodSync, mkdtempSync, mkdirSync, symlinkSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import pw from 'playwright-core';
import {
    chromiumResolutionDiagnostics,
    formatChromiumResolutionDiagnostics,
    resolveChromiumPath,
} from '../lib/local-browser.mjs';

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
        diagnostics: false,
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
        diagnostics: false,
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
        diagnostics: false,
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
        diagnostics: false,
    }),
    headless,
    'Playwright headless-shell layout is discovered',
);

const diagnosticState = chromiumResolutionDiagnostics({
    candidates: [regular, symlink, nonExecutable, outside, path.join(outsideRoot, 'secret-looking', 'chrome')],
    playwrightExecutablePath: path.join(playwrightRoot, publicApiInstall, 'chrome-linux64', 'missing-chrome'),
    trustedRoots: [root, playwrightRoot],
    allowSystemRoots: false,
});
assert.equal(diagnosticState.expectedPackageRevision, publicApiInstall.replace(/^chromium-/, ''), 'diagnostic reports package Chromium revision');
assert.ok(diagnosticState.revisionDirectories.includes(publicApiInstall), 'diagnostic reports revision directory names');
assert.ok(diagnosticState.candidates.every((candidate) => Object.hasOwn(candidate, 'exists')), 'diagnostic reports existence per candidate');
assert.ok(diagnosticState.candidates.every((candidate) => Object.hasOwn(candidate, 'regular')), 'diagnostic reports regular-file state per candidate');
assert.ok(diagnosticState.candidates.every((candidate) => Object.hasOwn(candidate, 'executable')), 'diagnostic reports executable state per candidate');
assert.ok(diagnosticState.candidates.every((candidate) => Object.hasOwn(candidate, 'symlink')), 'diagnostic reports symlink state per candidate');
assert.ok(diagnosticState.candidates.every((candidate) => Object.hasOwn(candidate, 'trustedRoot')), 'diagnostic reports trust-root state per candidate');
assert.equal(diagnosticState.candidates[0].path, 'regular/chrome', 'diagnostic candidate path is relative/basename-only');
assert.deepEqual(
    diagnosticState.candidates.slice(0, 4).map(({ exists, regular: isRegular, executable, symlink: isSymlink, trustedRoot }) => ({
        exists, regular: isRegular, executable, symlink: isSymlink, trustedRoot,
    })),
    [
        { exists: true, regular: true, executable: true, symlink: false, trustedRoot: true },
        { exists: true, regular: false, executable: true, symlink: true, trustedRoot: true },
        { exists: true, regular: true, executable: false, symlink: false, trustedRoot: true },
        { exists: true, regular: true, executable: true, symlink: false, trustedRoot: false },
    ],
    'diagnostic reports fact-backed candidate states',
);
const diagnosticText = formatChromiumResolutionDiagnostics(diagnosticState);
assert.match(diagnosticText, /CHROMIUM RESOLUTION DIAGNOSTICS \(bounded\)/);
assert.match(diagnosticText, /expected_package_revision=/);
assert.match(diagnosticText, /HOME=/);
assert.match(diagnosticText, /PLAYWRIGHT_BROWSERS_PATH=/);
assert.match(diagnosticText, /exists=false regular=false executable=false symlink=false trusted_root=false/);
assert.ok(!diagnosticText.includes(outsideRoot), 'diagnostic does not print an absolute candidate path');

let failedResolverOutput = '';
const originalConsoleError = console.error;
console.error = (message) => { failedResolverOutput += `${message}\n`; };
try {
    assert.equal(
        resolveChromiumPath({
            candidates: [],
            playwrightExecutablePath: path.join(playwrightRoot, 'chromium-9999', 'chrome-linux64', 'missing-chrome'),
            trustedRoots: [playwrightRoot],
            allowSystemRoots: false,
        }),
        null,
        'failed resolver returns null after diagnostics',
    );
} finally {
    console.error = originalConsoleError;
}
assert.match(failedResolverOutput, /CHROMIUM RESOLUTION DIAGNOSTICS \(bounded\)/, 'failed resolver emits bounded diagnostics');

console.log('local-browser-discovery-check: ALL PASS');