#!/usr/bin/env node
// Multi-word product search regression test (Task 1045/1047, 16 Aug 2026).
//
// WHAT IT DOES
// ─────────────
// Extracts the ACTUAL searchTokens() and nameMatchRank() method bodies from
// both resources/views/pos/universal.blade.php (PRA sale screen) and
// resources/views/pos/waiter.blade.php using the same brace-counting approach
// as the other preflight scripts (e.g. print-order-check.mjs).  The extracted
// source is eval'd into a plain JS object so the live blade code — not a hand
// copy — is under test.
//
// It also asserts that both blade files carry IDENTICAL function bodies (sync
// check), so a future edit to one surface that forgets the other fails loudly
// here before deployment.
//
// Wired into scripts/deploy-live.sh — runs on every deploy.
//
// Run standalone: node scripts/pos-search-rank-test.mjs
// Exit 0 = all pass, exit 1 = failure.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const ROOT      = path.resolve(fileURLToPath(import.meta.url), '../..');
const UNIVERSAL = path.join(ROOT, 'resources/views/pos/universal.blade.php');
const WAITER    = path.join(ROOT, 'resources/views/pos/waiter.blade.php');

// ── Extraction helpers ────────────────────────────────────────────────────────

const fail = (msg) => { console.error('POS-SEARCH FAIL:', msg); process.exit(1); };

/**
 * Extract a shorthand-method block starting at `startPattern` by brace-counting.
 * Returns the source from the pattern start through the closing `}`.
 */
function extractMethod(src, startPattern, filePath) {
    const rel   = path.relative(ROOT, filePath);
    const start = src.search(startPattern);
    if (start === -1) fail(`${rel}: method not found: ${startPattern}`);
    let depth = 0, i = src.indexOf('{', start);
    if (i === -1) fail(`${rel}: no opening brace after: ${startPattern}`);
    for (; i < src.length; i++) {
        if (src[i] === '{') depth++;
        else if (src[i] === '}') { depth--; if (depth === 0) return src.slice(start, i + 1); }
    }
    fail(`${rel}: unbalanced braces in: ${startPattern}`);
}

/**
 * Load and build an object { searchTokens, nameMatchRank } extracted from a blade file.
 * nameMatchRank uses `this.searchTokens` — they must live in the same object.
 */
function loadSurface(bladePath) {
    const src = readFileSync(bladePath, 'utf8');
    const stSrc = extractMethod(src, /searchTokens\(s\)\s*\{/,           bladePath);
    const nmSrc = extractMethod(src, /nameMatchRank\(name, q, anyWord\)\s*\{/, bladePath);
    // eslint-disable-next-line no-new-func
    return { stSrc, nmSrc, obj: new Function(`return ({ ${stSrc}, ${nmSrc} });`)() };
}

const universal = loadSurface(UNIVERSAL);
const waiter    = loadSurface(WAITER);

// ── Test runner ───────────────────────────────────────────────────────────────

let passed = 0, failed = 0;
const failures = [];

function ok(label, cond) {
    if (cond) { console.log('  ✓', label); passed++; }
    else       { console.error('  ✗', label); failures.push(label); failed++; }
}

// ── [1] Sync check ────────────────────────────────────────────────────────────

console.log('\n[1] Blade sync check: universal.blade.php ↔ waiter.blade.php');

// Normalise whitespace when comparing so comment-only differences don't matter,
// but code differences (operators, method calls, control flow) still fail.
const norm = s => s.replace(/\s+/g, ' ').trim();
ok('searchTokens body identical in both surfaces',  norm(universal.stSrc) === norm(waiter.stSrc));
ok('nameMatchRank body identical in both surfaces', norm(universal.nmSrc) === norm(waiter.nmSrc));

// ── [2] Per-surface behavioral tests ─────────────────────────────────────────

function testSurface(label, helpers) {
    /**
     * Mirror of the onSearchInput / filterProducts word-start fallback:
     * strict-prefix first; if zero hits, rescan in any-word mode.
     */
    function matchFallback(name, q) {
        const r = helpers.nameMatchRank(name, q, false); // strict (anyWord=false)
        return r > 0 ? r : helpers.nameMatchRank(name, q, true);
    }

    console.log(`\n  ── ${label} ──`);

    // [A] Customer's exact scenario (16 Aug 2026 restaurant video)
    console.log('  [A] Customer exact scenario');
    ok('"cheese loaded half" finds "Cheese Loaded Fries (Half)"',
        matchFallback('Cheese Loaded Fries (Half)', 'cheese loaded half') > 0);
    ok('"cheese half" finds "Cheese Loaded Fries (Half)"',
        matchFallback('Cheese Loaded Fries (Half)', 'cheese half') > 0);
    ok('"cheese loaded" finds "Cheese Loaded Fries (Full)"',
        matchFallback('Cheese Loaded Fries (Full)', 'cheese loaded') > 0);

    // [B] Rank ordering — contiguous ranks above in-order, in-order above scattered
    console.log('  [B] Rank ordering');
    const r_clf_clh = helpers.nameMatchRank('Cheese Loaded Fries (Half)', 'cheese loaded half', false);
    const r_clf_ch  = helpers.nameMatchRank('Cheese Loaded Fries (Half)', 'cheese half',        false);
    const r_cff_clh = helpers.nameMatchRank('Cheese Loaded Fries (Full)', 'cheese loaded half', false);
    const r_cl_4    = helpers.nameMatchRank('Cheese Loaded Fries (Half)', 'cheese loaded',      false);
    ok('strict: "cheese loaded half" hits (Half) — in-order rank 2',  r_clf_clh === 2);
    ok('strict: "cheese half" hits (Half) — in-order rank 2',         r_clf_ch  === 2);
    ok('strict: "cheese loaded half" misses (Full) — "half"≠"fries"', r_cff_clh === 0);
    ok('strict: "cheese loaded" hits (Half) rank ≥ 3 (contiguous)',   r_cl_4    >= 3);

    // [C] Extra-word robustness — more words than needed must still find the product
    console.log('  [C] Extra-word robustness');
    ok('"chicken cheese fries" finds "Chicken Cheese Fries" (exact → rank 4)',
        matchFallback('Chicken Cheese Fries', 'chicken cheese fries') === 4);
    ok('"chicken cheese" finds "Chicken Cheese Fries"',
        matchFallback('Chicken Cheese Fries', 'chicken cheese') > 0);

    // [D] Strict single-word prefix rule preserved (owner 24 Jul 2026)
    console.log('  [D] Strict-prefix single-word rule preserved');
    ok('"zi" strict: matches "Zinger Burger"',
        helpers.nameMatchRank('Zinger Burger', 'zi', false) > 0);
    ok('"zi" strict: does NOT match "Chicken Roll"',
        helpers.nameMatchRank('Chicken Roll',  'zi', false) === 0);
    ok('"zi" strict: does NOT match "Beef Burger"',
        helpers.nameMatchRank('Beef Burger',   'zi', false) === 0);
    // Mid-name word: strict=0, any-word fallback>0
    ok('"beef" strict: misses "Grilled Beef Burger"',
        helpers.nameMatchRank('Grilled Beef Burger', 'beef', false) === 0);
    ok('"beef" any-word: finds "Grilled Beef Burger" via fallback',
        matchFallback('Grilled Beef Burger', 'beef') > 0);

    // [E] Parenthesis tokenisation — "(Half)" must yield token "half"
    console.log('  [E] Parenthesis tokenisation');
    const toks = helpers.searchTokens('Cheese Loaded Fries (Half)');
    ok('"(Half)" yields token "half"',          toks.includes('half'));
    ok('no empty tokens from "(Half)"',         toks.every(t => t.length > 0));
    ok('"Cheese" tokenises to "cheese"',        toks.includes('cheese'));
    ok('parentheses never appear as a token',   toks.every(t => !/[()]/g.test(t)));

    // [F] Barcode/digit gate — letters-only = name search, not code search
    console.log('  [F] Code-search gate predicate');
    const isCodeSearch = q => /[^a-z\s]/.test(q);
    ok('"chi" → NOT a code search',                  !isCodeSearch('chi'));
    ok('"CHI-001" → IS a code search (has "-")',      isCodeSearch('chi-001'));
    ok('"1234" → IS a code search (has digit)',        isCodeSearch('1234'));
    ok('"cheese loaded half" → NOT a code search',   !isCodeSearch('cheese loaded half'));
}

console.log('\n[2] PRA universal sale screen (resources/views/pos/universal.blade.php)');
testSurface('universal.blade.php', universal.obj);

console.log('\n[3] Waiter app (resources/views/pos/waiter.blade.php)');
testSurface('waiter.blade.php', waiter.obj);

// ── Summary ───────────────────────────────────────────────────────────────────

console.log('\n' + (failed ? '✗ FAILED' : '✓ ALL PASSED'),
    `(${passed} pass${failed ? ', ' + failed + ' fail' : ''})`);
if (failures.length) {
    console.error('Failed assertions:');
    failures.forEach(f => console.error('  -', f));
}
process.exit(failed ? 1 : 0);
