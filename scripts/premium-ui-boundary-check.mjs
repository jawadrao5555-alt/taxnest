#!/usr/bin/env node
/**
 * Presentation-only boundary check for the Premium UI rollout.
 *
 * It intentionally compares the working tree to the release-candidate
 * revision instead of trusting a list of expected edits.  A class/wrapper
 * change is allowed; an application, deployment, service worker, or billing
 * JavaScript change is not.  Failures print the relevant diff and the exact
 * handler/ref/script entries that changed.
 */

import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const DEFAULT_BASE = '4b7c3193f8bc16ca79f358111831a0fbc61faefc';
const BASE = process.env.PREMIUM_UI_BASE || DEFAULT_BASE;

const ALLOWED_CHANGED = [
    /^resources\/views\/layouts\/(?:admin-app|pos-app|fbr-pos-app|health-app|app)\.blade\.php$/,
    /^resources\/views\/layouts\/pos-navigation\.blade\.php$/,
    /^resources\/views\/partials\/premium-ui\.blade\.php$/,
    /^resources\/views\/(?:pos|fbr-pos)\/universal\.blade\.php$/,
    /^resources\/views\/(?:saas-admin\/dashboard|pos\/dashboard|fbr-pos\/dashboard|health\/dashboard|pos\/restaurant\/dashboard|pos\/inventory\/dashboard|pos\/services)\.blade\.php$/,
    /^resources\/views\/pos\/service-work-orders\/(?:index|create|show)\.blade\.php$/,
    /^resources\/views\/pos\/hotel\/(?:dashboard|rooms|stay-show|guests|folios|reports|stay-create)\.blade\.php$/,
    /^public\/css\/(?:taxnest-premium|premium-native|premium-admin|premium-pos)\.css$/,
    /^scripts\/premium-ui-boundary-check\.mjs$/,
    /^scripts\/premium-ui-gallery\.py$/,
    /^scripts\/premium-ui-assertions(?:\.self-test)?\.mjs$/,
    /^scripts\/premium-ui-(?:browser|fixture-extension)\.(?:mjs|php)$/,
    /^tests\/Unit\/PremiumUiPresentationContractTest\.php$/,
    /^docs\/ui-premium(?:\/|$)/,
];

const FORBIDDEN = [
    /^(?:app|routes|database|config|\.github|deployment|deploy)(?:\/|$)/,
    /(?:^|\/)sw\.js$/,
];

const failMessages = [];

function git(args, { allowFailure = false } = {}) {
    const result = spawnSync('git', args, {
        cwd: ROOT,
        encoding: 'utf8',
        maxBuffer: 32 * 1024 * 1024,
    });
    if (result.error || (!allowFailure && result.status !== 0)) {
        const detail = result.error?.message || result.stderr?.trim() || `exit ${result.status}`;
        throw new Error(`git ${args.join(' ')} failed: ${detail}`);
    }
    return result.stdout || '';
}

function currentFiles() {
    const tracked = git(['diff', '--name-only', BASE, '--'])
        .split(/\r?\n/)
        .map((file) => file.trim())
        .filter(Boolean);
    const untracked = git(['ls-files', '--others', '--exclude-standard'])
        .split(/\r?\n/)
        .map((file) => file.trim())
        .filter(Boolean);
    return [...new Set([...tracked, ...untracked])].sort();
}

function isAllowed(file) {
    return ALLOWED_CHANGED.some((pattern) => pattern.test(file));
}

function isForbidden(file) {
    return FORBIDDEN.some((pattern) => pattern.test(file));
}

function printDiff(file) {
    const baseEntry = spawnSync('git', ['cat-file', '-e', `${BASE}:${file}`], {
        cwd: ROOT,
        encoding: 'utf8',
    });
    const tracked = baseEntry.status === 0;
    let diff;

    if (!tracked) {
        const result = spawnSync('git', ['diff', '--no-index', '--unified=3', '--', '/dev/null', file], {
            cwd: ROOT,
            encoding: 'utf8',
            maxBuffer: 32 * 1024 * 1024,
        });
        diff = result.stdout || result.stderr || '(no readable untracked-file diff)';
    } else {
        diff = git(['diff', '--no-ext-diff', '--unified=3', BASE, '--', file], { allowFailure: true });
    }

    console.error(`\n--- actual diff: ${file} ---\n${diff.trimEnd() || '(file is changed but produced no textual diff)'}`);
}

function reportFileBoundary(file) {
    const reason = isForbidden(file)
        ? 'forbidden application/deployment/service-worker path'
        : file.startsWith('scripts/') && !file.startsWith('scripts/premium-ui-')
            ? 'existing script/guard path'
            : 'outside the presentation-only allowlist';
    failMessages.push(`${file}: ${reason}`);
    printDiff(file);
}

function readAtBase(file) {
    return git(['show', `${BASE}:${file}`]);
}

function readCurrent(file) {
    const absolute = path.join(ROOT, file);
    if (!existsSync(absolute)) {
        throw new Error(`required current file is missing: ${file}`);
    }
    return readFileSync(absolute, 'utf8');
}

function executableScripts(source) {
    const blocks = [];
    const pattern = /<script\b([^>]*)>([\s\S]*?)<\/script\s*>/gi;
    let match;

    while ((match = pattern.exec(source)) !== null) {
        const attributes = match[1] || '';
        if (/\bsrc\s*=/i.test(attributes)) continue;
        const type = attributes.match(/\btype\s*=\s*(['"])(.*?)\1/i)?.[2]?.toLowerCase();
        if (type && !['text/javascript', 'application/javascript', 'module'].includes(type)) continue;
        const body = match[2].replace(/\r\n?/g, '\n').trim();
        if (body) blocks.push(body);
    }

    return blocks;
}

function multiset(items) {
    const counts = new Map();
    for (const item of items) counts.set(item, (counts.get(item) || 0) + 1);
    return counts;
}

function multisetDelta(before, after) {
    const oldCounts = multiset(before);
    const newCounts = multiset(after);
    const removed = [];
    const added = [];
    const keys = new Set([...oldCounts.keys(), ...newCounts.keys()]);

    for (const key of keys) {
        const delta = (newCounts.get(key) || 0) - (oldCounts.get(key) || 0);
        if (delta < 0) removed.push(...Array(-delta).fill(key));
        if (delta > 0) added.push(...Array(delta).fill(key));
    }

    return { removed, added };
}

function attributeContract(source) {
    const entries = [];
    /*
     * Keep modifiers (x-model.number, @keydown.enter.prevent, ...): those
     * modifiers are part of the behaviour contract, not presentation.
     */
    const attributes = /(?:^|[\s<])(x-model(?:\.[\w-]+)*|@click(?:\.[\w-]+)*|@keydown(?:\.[\w-]+)*|x-ref)\s*=\s*(["'])([\s\S]*?)\2/gi;
    let match;
    while ((match = attributes.exec(source)) !== null) {
        entries.push(`${match[1]}="${match[3].replace(/\s+/g, ' ').trim()}"`);
    }

    const refs = /\$refs(?:\.([A-Za-z_$][\w$]*)|\[['"]([^'"]+)['"]\])/g;
    while ((match = refs.exec(source)) !== null) {
        entries.push(`$refs.${match[1] || match[2]}`);
    }
    return entries;
}

function reportDelta(label, delta) {
    if (!delta.removed.length && !delta.added.length) return;
    failMessages.push(`${label}: executable/markup contract changed`);
    for (const item of delta.removed) {
        console.error(`\n- ${label}\n${item}`);
    }
    for (const item of delta.added) {
        console.error(`\n+ ${label}\n${item}`);
    }
}

function comparePresentationTemplates() {
    for (const file of ['resources/views/pos/universal.blade.php', 'resources/views/fbr-pos/universal.blade.php']) {
        const original = readAtBase(file);
        const current = readCurrent(file);
        const oldScripts = executableScripts(original);
        const newScripts = executableScripts(current);
        reportDelta(`${file} inline executable script block`, multisetDelta(oldScripts, newScripts));
        reportDelta(
            `${file} x-model/@click/@keydown/x-ref/$refs`,
            multisetDelta(attributeContract(original), attributeContract(current)),
        );
    }
}

function main() {
    try {
        git(['cat-file', '-e', `${BASE}^{commit}`]);
        const files = currentFiles();
        for (const file of files) {
            if (!isAllowed(file) || isForbidden(file)) reportFileBoundary(file);
        }
        comparePresentationTemplates();
    } catch (error) {
        console.error(`PREMIUM UI BOUNDARY: could not run — ${error.message}`);
        process.exit(2);
    }

    if (failMessages.length) {
        console.error('\nPREMIUM UI BOUNDARY: FAILED');
        for (const message of failMessages) console.error(`  ${message}`);
        process.exit(1);
    }
    console.log(`PREMIUM UI BOUNDARY: PASS — ${BASE} to working tree is presentation-only.`);
}

main();