import assert from 'node:assert/strict';
import { chmodSync, existsSync, mkdtempSync, mkdirSync, symlinkSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import pw from 'playwright-core';
import {
    chromiumResolutionDiagnostics,
    formatChromiumResolutionDiagnostics,
    resolveChromiumPath,
} from '../lib/local-browser.mjs';
import { expectedChromiumRevision, expectedFfmpegRevision, validatePlaywrightCache } from '../lib/playwright-cache.mjs';

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
const chromiumRevision = expectedChromiumRevision();
const ffmpegRevision = expectedFfmpegRevision();
assert.equal(publicApiInstall, `chromium-${chromiumRevision}`, 'public Playwright API matches package Chromium revision');
const dryRunCache = path.join(root, 'dry-run-cache');
const dryRun = spawnSync(path.resolve('node_modules/.bin/playwright'), ['install', '--dry-run', 'chromium'], {
    cwd: process.cwd(),
    encoding: 'utf8',
    env: { ...process.env, PLAYWRIGHT_BROWSERS_PATH: dryRunCache },
});
assert.equal(dryRun.status, 0, `installed Playwright dry-run failed: ${dryRun.stderr}`);
const dryRunOutput = `${dryRun.stdout}\n${dryRun.stderr}`;
for (const entry of [`chromium-${chromiumRevision}`, `chromium_headless_shell-${chromiumRevision}`, `ffmpeg-${ffmpegRevision}`]) {
    assert.match(dryRunOutput, new RegExp(entry.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `dry-run declares ${entry}`);
}
assert.equal(existsSync(dryRunCache), false, 'Playwright dry-run did not create/download a cache');
console.log(`PASS: installed Playwright dry-run declares exact Chromium/headless/FFmpeg revisions (${chromiumRevision}/${ffmpegRevision}) without downloads`);
const playwrightChrome = path.join(playwrightRoot, publicApiInstall, 'chrome-linux64', 'chrome');
executable(playwrightChrome);
const playwrightFfmpeg = path.join(playwrightRoot, `ffmpeg-${ffmpegRevision}`, 'ffmpeg-linux');
executable(playwrightFfmpeg);
assert.deepEqual(
    validatePlaywrightCache(playwrightRoot).revision,
    publicApiInstall.replace(/^chromium-/, ''),
    'dedicated cache validates the exact package revision',
);
const legitimateTarget = executable(path.join(playwrightRoot, publicApiInstall, 'chrome-linux64', 'chrome-real'));
const legitimateSymlink = path.join(playwrightRoot, publicApiInstall, 'chrome-linux', 'chrome');
mkdirSync(path.dirname(legitimateSymlink), { recursive: true });
symlinkSync(legitimateTarget, legitimateSymlink);
assert.equal(
    resolveChromiumPath({
        candidates: [legitimateSymlink],
        playwrightExecutablePath: null,
        playwrightBrowsersPath: playwrightRoot,
        trustedRoots: [playwrightRoot],
        allowSystemRoots: false,
        diagnostics: false,
    }),
    legitimateSymlink,
    'in-revision executable symlink is accepted only inside the dedicated cache',
);
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

const staleCacheEntry = path.join(playwrightRoot, 'chromium-9999');
mkdirSync(staleCacheEntry, { recursive: true });
assert.throws(() => validatePlaywrightCache(playwrightRoot), /unexpected Playwright cache entry/);

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