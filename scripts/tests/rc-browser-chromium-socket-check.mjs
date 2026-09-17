import assert from 'node:assert/strict';
import { lstatSync } from 'node:fs';
import { chromium, resolveChromiumPath } from '../lib/local-browser.mjs';

const tmpdir = String(process.env.TMPDIR || '');
assert.equal(process.env.RC_SAFE_RUN, '1', 'Chromium regression must run inside rc-safe-run');
assert.ok(process.env.LD_PRELOAD, 'Chromium regression must inherit the egress guard');
assert.match(String(process.env.HOME || ''), /\/safe-runtime\/home$/, 'Chromium regression must use isolated HOME');
assert.equal(process.env.XDG_CACHE_HOME, `${process.env.HOME}/cache`, 'Chromium regression must retain isolated cache');
assert.match(tmpdir, /^\/tmp\/rcpw\.[A-Za-z0-9]+$/, 'safe browser TMPDIR must be the validated short disposable directory');
assert.equal(lstatSync(tmpdir).isDirectory(), true, 'safe browser TMPDIR must remain a real directory');
assert.equal(lstatSync(tmpdir).mode & 0o777, 0o700, 'safe browser TMPDIR must remain mode 0700');

const executablePath = resolveChromiumPath({
    candidates: [],
    playwrightBrowsersPath: process.env.PLAYWRIGHT_BROWSERS_PATH,
    allowSystemRoots: false,
    diagnostics: false,
});
assert.ok(executablePath, 'pinned Chromium executable must resolve from the validated Playwright cache');
const socketPath = `${tmpdir}/org.chromium.Chromium/SingletonSocket`;
assert.ok(Buffer.byteLength(socketPath) < 108, 'pinned Chromium SingletonSocket path must fit AF_UNIX byte limit');

const browser = await chromium.launch({
    executablePath,
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
});
await browser.close();
console.log('PASS: pinned Chromium launches with the validated short SingletonSocket path');