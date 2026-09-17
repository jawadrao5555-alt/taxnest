/**
 * Shared fail-closed helpers for Cloud Agent / local Chrome UI smoke tests.
 *
 * Rules:
 *   - BASE_URL must be loopback only (127.0.0.1 / localhost / ::1)
 *   - Never allow taxnest.pk, production IPs, or remote hosts
 *   - Credentials come from env or untracked .local/qa-creds.env — never hardcoded
 *   - Prefer google-chrome / CHROMIUM_BIN already on the VM
 */

import { accessSync, constants, existsSync, lstatSync, mkdirSync, readFileSync, readdirSync, realpathSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import pw from 'playwright-core';

const { chromium } = pw;
const require = createRequire(import.meta.url);

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

/** Hostnames / host patterns that must never be targeted by local browser QA. */
const BLOCKED_HOST_RE = /^(?:.*\.)?taxnest\.pk$|115\.186\.164\.126|nayatel|cpanel|production/i;

/**
 * Parse KEY=VALUE lines from an untracked env file (e.g. .local/qa-creds.env).
 * @param {string} filePath
 * @returns {Record<string, string>}
 */
export function parseEnvFile(filePath) {
    const out = {};
    if (!existsSync(filePath)) return out;
    for (const line of readFileSync(filePath, 'utf8').split('\n')) {
        const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
        if (!m) continue;
        out[m[1]] = m[2].replace(/^["']|["']$/g, '');
    }
    return out;
}

/**
 * Fail closed: refuse any non-loopback BASE_URL.
 * @param {string} rawUrl
 * @returns {string} normalized base URL without trailing slash
 */
export function assertLocalOnlyBaseUrl(rawUrl) {
    const input = String(rawUrl || '').trim();
    if (!input) {
        throw new Error('BASE_URL is empty — set BASE_URL=http://127.0.0.1:8000');
    }
    let u;
    try {
        u = new URL(input);
    } catch {
        throw new Error(`BASE_URL is not a valid URL: ${input}`);
    }
    if (u.protocol !== 'http:' && u.protocol !== 'https:') {
        throw new Error(`BASE_URL protocol must be http(s), got ${u.protocol}`);
    }
    const host = u.hostname.toLowerCase();
    const loopback = host === '127.0.0.1' || host === 'localhost' || host === '::1' || host === '[::1]';
    if (!loopback) {
        throw new Error(
            `REFUSED: BASE_URL host '${u.hostname}' is not loopback. ` +
            'Cloud/local browser QA may only target 127.0.0.1 / localhost / ::1.'
        );
    }
    if (BLOCKED_HOST_RE.test(host) || BLOCKED_HOST_RE.test(u.host)) {
        throw new Error(`REFUSED: BASE_URL looks like production (${u.href})`);
    }
    // Extra belt: refuse if the string itself mentions production hosts even via weird URLs
    if (/taxnest\.pk|115\.186\.164\.126/i.test(input)) {
        throw new Error(`REFUSED: BASE_URL mentions a production host (${input})`);
    }
    return u.href.replace(/\/+$/, '');
}

const CHROMIUM_EXECUTABLE_NAMES = new Set([
    'chrome',
    'chrome-headless-shell',
    'chromium',
    'chromium-browser',
    'google-chrome',
    'headless_shell',
]);

function hasParentPathEscape(filePath) {
    return String(filePath).split(/[\\/]+/).includes('..');
}

function isPathInside(root, filePath) {
    const relative = path.relative(root, filePath);
    return relative === '' || (relative !== '..' && !relative.startsWith(`..${path.sep}`) && !path.isAbsolute(relative));
}

function isRegularExecutable(filePath, trustedRoots) {
    if (!filePath || !path.isAbsolute(filePath) || hasParentPathEscape(filePath)) return false;
    if (!CHROMIUM_EXECUTABLE_NAMES.has(path.basename(filePath))) return false;
    const roots = trustedRoots.filter((root) => root && path.isAbsolute(root) && !hasParentPathEscape(root));
    if (!roots.some((root) => isPathInside(root, filePath))) return false;
    try {
        // lstat is deliberate: a browser executable must not be a symlink.
        const stat = lstatSync(filePath);
        if (!stat.isFile()) return false;
        if (process.platform !== 'win32' && (stat.mode & 0o111) === 0) return false;
        accessSync(filePath, constants.X_OK);
        // Also reject a parent symlink escaping the allowlisted install root.
        const actual = realpathSync(filePath);
        return roots.some((root) => {
            try { return isPathInside(realpathSync(root), actual); } catch { return false; }
        });
    } catch {
        return false;
    }
}

function playwrightInstallDescriptor(executablePath) {
    if (!executablePath || !path.isAbsolute(executablePath) || hasParentPathEscape(executablePath)) return null;
    let dir = path.dirname(executablePath);
    for (let i = 0; i < 4; i++) {
        const match = path.basename(dir).match(/^(chromium|chromium_headless_shell|chromium-tip-of-tree|chromium-tip-of-tree-headless-shell)-(\d+)$/);
        if (match) return { root: path.dirname(dir), name: match[1], revision: match[2] };
        dir = path.dirname(dir);
    }
    return null;
}

/**
 * Add the Chrome Headless Shell installed by Playwright. In the official
 * MariaDB Playwright image its x64 executable is:
 *   chromium_headless_shell-<revision>/chrome-headless-shell-linux64/chrome-headless-shell
 * (older/arm64 bundles use chrome-linux/headless_shell).
 */
function playwrightHeadlessShellCandidates(executablePath) {
    const descriptor = playwrightInstallDescriptor(executablePath);
    const root = descriptor?.root;
    if (!root || !existsSync(root)) return { root: null, paths: [] };
    try {
        const shellName = descriptor.name === 'chromium-tip-of-tree'
            ? 'chromium-tip-of-tree-headless-shell'
            : 'chromium_headless_shell';
        const base = path.join(root, `${shellName}-${descriptor.revision}`);
        const paths = [
            path.join(base, 'chrome-headless-shell-linux64', 'chrome-headless-shell'),
            path.join(base, 'chrome-linux64', 'chrome-headless-shell'),
            path.join(base, 'chrome-linux', 'headless_shell'),
        ];
        return { root, paths };
    } catch {
        return { root, paths: [] };
    }
}

const DIAGNOSTIC_CANDIDATE_LIMIT = 64;
const DIAGNOSTIC_REVISION_LIMIT = 64;

function safeChromiumExecutablePath() {
    try { return chromium.executablePath(); } catch { return null; }
}

function expectedChromiumPackageRevision() {
    try {
        let packageRoot = path.dirname(require.resolve('playwright-core'));
        for (let i = 0; i < 4 && !existsSync(path.join(packageRoot, 'browsers.json')); i++) {
            packageRoot = path.dirname(packageRoot);
        }
        const browsers = JSON.parse(readFileSync(path.join(packageRoot, 'browsers.json'), 'utf8'));
        return String(browsers.browsers.find((browser) => browser.name === 'chromium')?.revision || 'unknown');
    } catch {
        return 'unknown';
    }
}

/**
 * Keep diagnostics useful for identifying an isolated HOME mismatch without
 * printing arbitrary environment values or unbounded paths.
 */
function sanitizeDiagnosticPath(value) {
    if (value == null || value === '') return '<unset>';
    const text = String(value).replace(/[\u0000-\u001f\u007f]/g, '?');
    if (text.length <= 240) return text;
    return `${text.slice(0, 96)}.../${path.basename(text).slice(-96)}`;
}

function diagnosticRelativePath(filePath, roots) {
    const value = String(filePath || '');
    if (!path.isAbsolute(value)) return sanitizeDiagnosticPath(value);
    for (const root of roots) {
        if (!root || !path.isAbsolute(root)) continue;
        const relative = path.relative(root, value);
        if (relative === '' || (relative !== '..' && !relative.startsWith(`..${path.sep}`) && !path.isAbsolute(relative))) {
            return sanitizeDiagnosticPath(relative || '.');
        }
    }
    // A candidate outside every trust root is represented by its basename only.
    return sanitizeDiagnosticPath(path.basename(value));
}

function diagnosticCandidate(candidate, trustedRoots) {
    const value = String(candidate || '');
    const record = {
        path: diagnosticRelativePath(value, trustedRoots),
        exists: false,
        regular: false,
        executable: false,
        symlink: false,
        trustedRoot: false,
        allowedName: CHROMIUM_EXECUTABLE_NAMES.has(path.basename(value)),
    };
    if (!path.isAbsolute(value) || hasParentPathEscape(value)) return record;

    let stat;
    try {
        stat = lstatSync(value);
        record.exists = true;
        record.regular = stat.isFile();
        record.symlink = stat.isSymbolicLink();
        try {
            accessSync(value, constants.X_OK);
            record.executable = true;
        } catch { /* not executable */ }
    } catch {
        return record;
    }

    if (!trustedRoots.some((root) => root && path.isAbsolute(root) && isPathInside(root, value))) return record;
    try {
        const actual = realpathSync(value);
        record.trustedRoot = trustedRoots.some((root) => {
            try { return isPathInside(realpathSync(root), actual); } catch { return false; }
        });
    } catch { /* missing target or inaccessible root */ }
    return record;
}

function revisionDirectoryNames(root) {
    if (!root || !path.isAbsolute(root)) return [];
    try {
        return readdirSync(root)
            .filter((name) => /^(?:chromium|chromium_headless_shell|chromium-tip-of-tree|chromium-tip-of-tree-headless-shell)-\d+$/.test(name))
            .sort()
            .slice(0, DIAGNOSTIC_REVISION_LIMIT);
    } catch {
        return [];
    }
}

function diagnosticTrustedRoots(options, playwrightPath) {
    const headless = playwrightHeadlessShellCandidates(playwrightPath);
    const descriptor = playwrightInstallDescriptor(playwrightPath);
    return [
        ...(options.allowSystemRoots === false ? [] : ['/usr/local/bin', '/usr/bin', '/nix/store', '/tmp/cursor-sandbox-cache']),
        headless.root,
        descriptor?.root,
        ...(options.trustedRoots || []),
    ].filter(Boolean).slice(0, DIAGNOSTIC_REVISION_LIMIT);
}

/**
 * Collect bounded, redacted state for a failed Chromium resolution. Candidate
 * paths are relative/basename-only; only HOME and PLAYWRIGHT_BROWSERS_PATH
 * are included from the process environment.
 */
export function chromiumResolutionDiagnostics(options = {}) {
    const playwrightPath = options.playwrightExecutablePath === undefined
        ? safeChromiumExecutablePath()
        : options.playwrightExecutablePath;
    const trustedRoots = diagnosticTrustedRoots(options, playwrightPath);
    const tries = options.candidates ? [...options.candidates] : [];
    if (!options.candidates) {
        if (process.env.CHROMIUM_BIN) tries.push(process.env.CHROMIUM_BIN);
        if (process.env.GOOGLE_CHROME_BIN) tries.push(process.env.GOOGLE_CHROME_BIN);
        tries.push('/usr/local/bin/google-chrome', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser');
    }
    if (playwrightPath) tries.push(playwrightPath);
    const headless = playwrightHeadlessShellCandidates(playwrightPath);
    tries.push(...headless.paths);
    const candidates = [];
    const seen = new Set();
    for (const candidate of tries) {
        const key = String(candidate);
        if (seen.has(key)) continue;
        seen.add(key);
        candidates.push(diagnosticCandidate(candidate, trustedRoots));
        if (candidates.length >= DIAGNOSTIC_CANDIDATE_LIMIT) break;
    }
    const roots = new Set(trustedRoots);
    if (headless.root) roots.add(headless.root);
    const descriptor = playwrightInstallDescriptor(playwrightPath);
    if (descriptor?.root) roots.add(descriptor.root);
    return {
        executablePath: sanitizeDiagnosticPath(playwrightPath),
        expectedPackageRevision: expectedChromiumPackageRevision(),
        env: {
            HOME: sanitizeDiagnosticPath(process.env.HOME),
            PLAYWRIGHT_BROWSERS_PATH: sanitizeDiagnosticPath(process.env.PLAYWRIGHT_BROWSERS_PATH),
        },
        revisionDirectories: [...roots].flatMap((root) => revisionDirectoryNames(root)).filter((name, index, all) => all.indexOf(name) === index).sort().slice(0, DIAGNOSTIC_REVISION_LIMIT),
        candidates,
    };
}

export function formatChromiumResolutionDiagnostics(diagnostics) {
    const d = diagnostics || {};
    const lines = [
        'CHROMIUM RESOLUTION DIAGNOSTICS (bounded)',
        `chromium.executablePath=${d.executablePath || '<unset>'}`,
        `expected_package_revision=${d.expectedPackageRevision || 'unknown'}`,
        `HOME=${d.env?.HOME || '<unset>'}`,
        `PLAYWRIGHT_BROWSERS_PATH=${d.env?.PLAYWRIGHT_BROWSERS_PATH || '<unset>'}`,
        `revision_directories=${(d.revisionDirectories || []).join(',') || '<none>'}`,
    ];
    for (const [index, candidate] of (d.candidates || []).entries()) {
        lines.push(
            `candidate[${index}] path=${candidate.path} exists=${Boolean(candidate.exists)} ` +
            `regular=${Boolean(candidate.regular)} executable=${Boolean(candidate.executable)} ` +
            `symlink=${Boolean(candidate.symlink)} trusted_root=${Boolean(candidate.trustedRoot)} ` +
            `allowed_name=${Boolean(candidate.allowedName)}`
        );
    }
    return lines.join('\n');
}

/**
 * Resolve Chrome/Chromium binary for Playwright connect.
 * @param {{ candidates?: string[], playwrightExecutablePath?: string, trustedRoots?: string[], allowSystemRoots?: boolean }} [options]
 * @returns {string|null}
 */
export function resolveChromiumPath(options = {}) {
    const env = process.env;
    const tries = options.candidates ? [...options.candidates] : [];
    if (!options.candidates) {
        if (env.CHROMIUM_BIN) tries.push(env.CHROMIUM_BIN);
        if (env.GOOGLE_CHROME_BIN) tries.push(env.GOOGLE_CHROME_BIN);
        tries.push('/usr/local/bin/google-chrome', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser');
    }

    let playwrightPath = options.playwrightExecutablePath;
    if (playwrightPath === undefined) {
        try { playwrightPath = chromium.executablePath(); } catch { /* browsers not downloaded */ }
    }
    if (playwrightPath) tries.push(playwrightPath);

    const headless = playwrightHeadlessShellCandidates(playwrightPath);
    tries.push(...headless.paths);
    const trustedRoots = [
        ...(options.allowSystemRoots === false ? [] : ['/usr/local/bin', '/usr/bin', '/nix/store', '/tmp/cursor-sandbox-cache']),
        headless.root,
        ...(options.trustedRoots || []),
    ].filter(Boolean);

    for (const candidate of tries) {
        if (isRegularExecutable(candidate, trustedRoots)) return candidate;
    }
    if (options.allowSystemRoots === false) {
        if (options.diagnostics !== false) {
            console.error(formatChromiumResolutionDiagnostics(chromiumResolutionDiagnostics(options)));
        }
        return null;
    }
    try {
        const hits = readdirSync('/nix/store')
            .filter((d) => /-chromium-\d/.test(d))
            .map((d) => `/nix/store/${d}/bin/chromium`)
            .filter((candidate) => isRegularExecutable(candidate, trustedRoots))
            .sort();
        if (hits.length) return hits[hits.length - 1];
    } catch { /* ignore */ }
    // Cursor/WSL agent sandboxes often cache Playwright Chromium under /tmp.
    try {
        const cacheRoot = '/tmp/cursor-sandbox-cache';
        if (existsSync(cacheRoot)) {
            const found = [];
            for (const hash of readdirSync(cacheRoot)) {
                const base = path.join(cacheRoot, hash, 'playwright');
                if (!existsSync(base)) continue;
                for (const dir of readdirSync(base)) {
                    if (!dir.startsWith('chromium-')) continue;
                    found.push(path.join(base, dir, 'chrome-linux64', 'chrome'));
                }
            }
            found.sort();
            for (const candidate of found) {
                if (isRegularExecutable(candidate, trustedRoots)) return candidate;
            }
        }
    } catch { /* ignore */ }
    if (options.diagnostics !== false) {
        console.error(formatChromiumResolutionDiagnostics(chromiumResolutionDiagnostics(options)));
    }
    return null;
}

/**
 * Local NestPOS QA credentials. Never hardcode; never read production live QA.
 * Priority: CLOUD_LOCAL_QA_* env → POS_CHECK_* → VIDEO_DEMO_* → .local/qa-creds.env
 * @returns {{ login: string, password: string, source: string }}
 */
export function loadLocalQaCreds() {
    const fromFile = parseEnvFile(path.join(ROOT, '.local/qa-creds.env'));
    const login =
        process.env.CLOUD_LOCAL_QA_LOGIN ||
        process.env.POS_CHECK_LOGIN ||
        process.env.VIDEO_DEMO_LOGIN ||
        fromFile.CLOUD_LOCAL_QA_LOGIN ||
        fromFile.DEV_POS_LOGIN ||
        fromFile.VIDEO_DEMO_LOGIN ||
        'videodemo@nestpos.pk';
    const password =
        process.env.CLOUD_LOCAL_QA_PASSWORD ||
        process.env.POS_CHECK_PASSWORD ||
        process.env.VIDEO_DEMO_PASS ||
        fromFile.CLOUD_LOCAL_QA_PASSWORD ||
        fromFile.DEV_POS_PASS ||
        fromFile.VIDEO_DEMO_PASS ||
        '';
    // Refuse known live QA identity so Cloud Agents cannot accidentally reuse production accounts.
    const blockedLogins = [
        'qa.fullaudit@taxnest.com.pk',
        'qa@taxnest.pk',
    ];
    if (blockedLogins.includes(String(login).toLowerCase())) {
        throw new Error(
            `REFUSED: login '${login}' is a live/production QA identity. ` +
            'Use the fictional local demo account (videodemo@nestpos.pk) only.'
        );
    }
    if (!password) {
        throw new Error(
            'No local QA password. Run: bash scripts/cloud-local-qa-seed.sh ' +
            '(writes VIDEO_DEMO_PASS into untracked .local/qa-creds.env)'
        );
    }
    return { login, password, source: process.env.CLOUD_LOCAL_QA_PASSWORD ? 'env' : (fromFile.VIDEO_DEMO_PASS ? '.local/qa-creds.env' : 'env-or-file') };
}

/**
 * Attach console + pageerror + failed request collectors to a Playwright page.
 * Also records HTTP ≥400 responses with URL/resourceType so bare Chrome
 * "Failed to load resource: 403" console lines can be correlated to sources.
 * @param {import('playwright-core').Page} page
 * @returns {{ consoleErrors: string[], pageErrors: string[], failedRequests: string[], httpErrors: object[], summary: () => string }}
 */
export function attachDiagnostics(page) {
    const consoleErrors = [];
    const pageErrors = [];
    const failedRequests = [];
    const httpErrors = [];

    page.on('console', (msg) => {
        if (msg.type() === 'error') {
            const loc = msg.location();
            const locStr = loc?.url ? `${loc.url}${loc.lineNumber != null ? ':' + loc.lineNumber : ''}` : '';
            consoleErrors.push(locStr ? `${msg.text()} @ ${locStr}` : msg.text());
        }
    });
    page.on('pageerror', (err) => {
        pageErrors.push(String(err?.message || err));
    });
    page.on('requestfailed', (req) => {
        const failure = req.failure();
        // Ignore aborted navigations / cancelled assets; keep real failures.
        const errText = failure?.errorText || '';
        if (/NS_BINDING_ABORTED|net::ERR_ABORTED/i.test(errText)) return;
        failedRequests.push(`${req.method()} ${req.url()} — ${errText}`);
    });
    page.on('response', (res) => {
        const status = res.status();
        if (status < 400) return;
        const url = res.url();
        if (/\/favicon\.ico($|\?)/i.test(url)) return;
        const req = res.request();
        httpErrors.push({
            status,
            method: req.method(),
            url,
            resourceType: req.resourceType(),
            fromPage: page.url(),
        });
    });

    return {
        consoleErrors,
        pageErrors,
        failedRequests,
        httpErrors,
        summary() {
            const parts = [];
            if (pageErrors.length) parts.push(`pageerrors=${pageErrors.length}`);
            if (consoleErrors.length) parts.push(`console_errors=${consoleErrors.length}`);
            if (failedRequests.length) parts.push(`failed_requests=${failedRequests.length}`);
            if (httpErrors.length) parts.push(`http_errors=${httpErrors.length}`);
            return parts.length ? parts.join(', ') : 'no browser errors collected';
        },
    };
}

/**
 * Launch headed/headless Chrome for local smoke tests.
 * @param {{ headless?: boolean }} [opts]
 */
export async function launchLocalBrowser(opts = {}) {
    const exe = resolveChromiumPath();
    if (!exe) {
        throw new Error('No Chrome/Chromium binary found (set CHROMIUM_BIN or install google-chrome)');
    }
    const headless = opts.headless !== false && process.env.CLOUD_LOCAL_QA_HEADED !== '1';
    const browser = await chromium.launch({
        executablePath: exe,
        headless,
        args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
    });
    return { browser, executablePath: exe, headless };
}

/**
 * Write a screenshot under .local/browser-evidence/ (gitignored).
 * @param {import('playwright-core').Page} page
 * @param {string} name
 * @returns {string} absolute path
 */
export async function saveEvidenceScreenshot(page, name) {
    const dir = path.join(ROOT, '.local/browser-evidence');
    mkdirSync(dir, { recursive: true });
    const safe = String(name).replace(/[^a-zA-Z0-9._-]+/g, '_');
    const file = path.join(dir, `${safe}.png`);
    await page.screenshot({ path: file, fullPage: true });
    writeFileSync(path.join(dir, `${safe}.txt`), `screenshot=${file}\ntime=${new Date().toISOString()}\n`);
    return file;
}

export { chromium };
