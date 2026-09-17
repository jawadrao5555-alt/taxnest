import { accessSync, constants, lstatSync, readFileSync, readdirSync, realpathSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const EXECUTABLES = [
    { family: 'chromium', parts: ['chrome-linux64', 'chrome'] },
    { family: 'chromium', parts: ['chrome-linux', 'chrome'] },
    { family: 'chromium_headless_shell', parts: ['chrome-headless-shell-linux64', 'chrome-headless-shell'] },
    { family: 'chromium_headless_shell', parts: ['chrome-linux64', 'chrome-headless-shell'] },
    { family: 'chromium_headless_shell', parts: ['chrome-linux', 'headless_shell'] },
    { family: 'ffmpeg', parts: ['ffmpeg-linux'] },
];

function packageRoot() {
    let root = path.dirname(require.resolve('playwright-core'));
    for (let i = 0; i < 4 && !lstatSync(path.join(root, 'browsers.json'), { throwIfNoEntry: false }); i++) {
        root = path.dirname(root);
    }
    return root;
}

export function expectedChromiumRevision() {
    const browsers = JSON.parse(readFileSync(path.join(packageRoot(), 'browsers.json'), 'utf8'));
    const revision = browsers.browsers.find((browser) => browser.name === 'chromium')?.revision;
    if (!revision || !/^\d+$/.test(String(revision))) throw new Error('Playwright Chromium package revision is unavailable');
    return String(revision);
}

export function expectedFfmpegRevision() {
    const browsers = JSON.parse(readFileSync(path.join(packageRoot(), 'browsers.json'), 'utf8'));
    const revision = browsers.browsers.find((browser) => browser.name === 'ffmpeg')?.revision;
    if (!revision || !/^\d+$/.test(String(revision))) throw new Error('Playwright FFmpeg package revision is unavailable');
    return String(revision);
}

function inside(root, candidate) {
    const relative = path.relative(root, candidate);
    return relative === '' || (relative !== '..' && !relative.startsWith(`..${path.sep}`) && !path.isAbsolute(relative));
}

function assertExecutable(root, candidate, family, revision) {
    if (!inside(root, candidate) || candidate.split(/[\\/]+/).includes('..')) {
        throw new Error(`Playwright executable path escapes the dedicated cache: ${candidate}`);
    }
    const lexicalRevision = path.relative(root, candidate).split(path.sep)[0];
    if (lexicalRevision !== `${family}-${revision}`) {
        throw new Error(`Playwright executable has an unexpected revision: ${lexicalRevision || '<root>'}`);
    }
    const linkStat = lstatSync(candidate, { throwIfNoEntry: false });
    if (!linkStat) throw new Error(`Playwright executable is missing: ${candidate}`);
    let target = candidate;
    if (linkStat.isSymbolicLink()) {
        target = realpathSync(candidate);
        if (!inside(root, target)) throw new Error('Playwright executable symlink escapes the dedicated cache');
    }
    const targetStat = statSync(target);
    if (!targetStat.isFile() || (process.platform !== 'win32' && (targetStat.mode & 0o111) === 0)) {
        throw new Error(`Playwright executable is not a regular executable: ${candidate}`);
    }
    accessSync(target, constants.X_OK);
    const canonicalRelative = path.relative(root, target).split(path.sep)[0];
    if (canonicalRelative !== lexicalRevision) {
        throw new Error('Playwright executable symlink does not remain in its expected revision');
    }
}

function assertNoUnexpectedSymlinks(directory, allowedSymlinks) {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
        const entryPath = path.join(directory, entry.name);
        if (entry.isSymbolicLink()) {
            if (!allowedSymlinks.has(entryPath)) {
                throw new Error(`unexpected Playwright cache symlink: ${entryPath}`);
            }
            continue;
        }
        if (entry.isDirectory()) assertNoUnexpectedSymlinks(entryPath, allowedSymlinks);
    }
}

/**
 * Validate the disposable Playwright cache before a browser process uses it.
 * The cache is intentionally strict: only the Chromium and FFmpeg packages at
 * the exact revisions declared by this checkout may exist at its top level.
 */
export function validatePlaywrightCache(cacheRoot, { requireExecutable = true } = {}) {
    if (!cacheRoot || !path.isAbsolute(cacheRoot) || cacheRoot.split(/[\\/]+/).includes('..')) {
        throw new Error('PLAYWRIGHT_BROWSERS_PATH must be an absolute, non-escaping path');
    }
    const rootStat = lstatSync(cacheRoot, { throwIfNoEntry: false });
    if (!rootStat?.isDirectory() || rootStat.isSymbolicLink()) {
        throw new Error('PLAYWRIGHT_BROWSERS_PATH must be a non-symlink directory');
    }
    const root = realpathSync(cacheRoot);
    if (root !== cacheRoot) throw new Error('PLAYWRIGHT_BROWSERS_PATH must remain canonical');
    const revision = expectedChromiumRevision();
    const ffmpegRevision = expectedFfmpegRevision();
    const allowed = new Set([`chromium-${revision}`, `chromium_headless_shell-${revision}`, `ffmpeg-${ffmpegRevision}`]);
    const candidates = EXECUTABLES.map(({ family, parts }) => {
        const packageRevision = family === 'ffmpeg' ? ffmpegRevision : revision;
        return { family, path: path.join(root, `${family}-${packageRevision}`, ...parts) };
    });
    const allowedSymlinks = new Set(candidates.map(({ path: candidate }) => candidate));
    for (const entry of readdirSync(root)) {
        if (entry === '.links') {
            const linksRoot = path.join(root, entry);
            const linksStat = lstatSync(linksRoot);
            if (!linksStat.isDirectory() || linksStat.isSymbolicLink()) {
                throw new Error('Playwright cache links directory is unsafe');
            }
            const packagePath = realpathSync(packageRoot());
            for (const link of readdirSync(linksRoot)) {
                if (!/^[a-f0-9]{40}$/.test(link)) throw new Error(`unexpected Playwright cache link: ${link}`);
                const linkPath = path.join(linksRoot, link);
                const linkStat = lstatSync(linkPath);
                if (!linkStat.isFile() || linkStat.isSymbolicLink() || realpathSync(readFileSync(linkPath, 'utf8').trim()) !== packagePath) {
                    throw new Error(`unsafe Playwright cache link: ${link}`);
                }
            }
            continue;
        }
        if (!allowed.has(entry)) {
            throw new Error(`unexpected Playwright cache entry: ${entry}`);
        }
        const stat = lstatSync(path.join(root, entry));
        if (!stat.isDirectory() || stat.isSymbolicLink()) {
            throw new Error(`Playwright cache revision entry is unsafe: ${entry}`);
        }
        assertNoUnexpectedSymlinks(path.join(root, entry), allowedSymlinks);
    }
    const present = candidates.filter(({ path: candidate }) => lstatSync(candidate, { throwIfNoEntry: false }));
    const browserPresent = present.filter(({ family }) => family !== 'ffmpeg');
    if (requireExecutable && !browserPresent.length) {
        throw new Error(`no exact Playwright Chromium ${revision} executable was installed`);
    }
    for (const { family, path: candidate } of present) {
        assertExecutable(root, candidate, family, family === 'ffmpeg' ? ffmpegRevision : revision);
    }
    return { root, revision, ffmpegRevision, executable: browserPresent[0]?.path || null };
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const cacheRoot = process.argv[2];
    try {
        const result = validatePlaywrightCache(cacheRoot);
        process.stdout.write(`PLAYWRIGHT_CACHE_VALIDATION_PASS revision=${result.revision}\n`);
    } catch (error) {
        process.stderr.write(`PLAYWRIGHT_CACHE_VALIDATION_FAIL: ${error.message}\n`);
        process.exitCode = 1;
    }
}