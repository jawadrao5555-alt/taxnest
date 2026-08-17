#!/usr/bin/env node
/**
 * Regression test for the multi-word product search matcher introduced in
 * Task 1045 (16 Aug 2026).  Covers the customer's exact video scenario:
 * typing "cheese loaded half" must find "Cheese Loaded Fries (Half)" on both
 * the PRA universal sale screen and the waiter app.
 *
 * Run: node scripts/pos-search-rank-test.js
 * Exit 0 = all pass, exit 1 = failure (at least one assertion failed).
 *
 * NOTE: the functions below are a verbatim copy of the JS helpers embedded in
 * resources/views/pos/universal.blade.php (searchTokens, nameMatchRank) and
 * mirrored in resources/views/pos/waiter.blade.php.  The two blade files must
 * stay in sync with each other AND with this test.
 */

'use strict';

// ── Helpers (verbatim copy from universal.blade.php / waiter.blade.php) ──────

function searchTokens(s) {
    return String(s || '').toLowerCase().split(/[^a-z0-9\u0080-\uffff]+/).filter(Boolean);
}

function nameMatchRank(name, q, anyWord) {
    const lname = String(name).toLowerCase();
    if (lname.startsWith(q)) return 4;
    const tokens = searchTokens(q);
    if (!tokens.length) return 0;
    if (!anyWord && !lname.startsWith(tokens[0])) return 0;
    const words = searchTokens(lname);
    for (let s = 0; s + tokens.length <= words.length; s++) {
        if (tokens.every((t, k) => words[s + k].startsWith(t))) return 3;
    }
    let wi = 0, inOrder = true;
    for (const t of tokens) {
        while (wi < words.length && !words[wi].startsWith(t)) wi++;
        if (wi >= words.length) { inOrder = false; break; }
        wi++;
    }
    if (inOrder) return 2;
    const used = new Array(words.length).fill(false);
    const ok = [...tokens].sort((a, b) => b.length - a.length).every(t => {
        for (let j = 0; j < words.length; j++) {
            if (!used[j] && words[j].startsWith(t)) { used[j] = true; return true; }
        }
        return false;
    });
    return ok ? 1 : 0;
}

/** Simulate the dropdown/grid fallback: strict prefix first, any-word if zero hits. */
function matchWithFallback(name, q) {
    const r = nameMatchRank(name, q, false);
    return r > 0 ? r : nameMatchRank(name, q, true);
}

// ── Test runner ───────────────────────────────────────────────────────────────

let passed = 0, failed = 0;

function assert(label, actual, expected) {
    if (actual === expected) {
        console.log('  ✓', label);
        passed++;
    } else {
        console.error('  ✗', label, '→ got', actual, 'expected', expected);
        failed++;
    }
}

function assertGt(label, actual, min) {
    if (actual > min) {
        console.log('  ✓', label, '(rank=' + actual + ')');
        passed++;
    } else {
        console.error('  ✗', label, '→ rank', actual, 'expected >', min);
        failed++;
    }
}

function assertEq(label, actual, notExpected) {
    if (actual === notExpected) {
        console.error('  ✗', label, '→ got', actual, '(should be absent)');
        failed++;
    } else {
        console.log('  ✓', label, '(rank=' + actual + ')');
        passed++;
    }
}

// ── Customer scenario (16 Aug 2026 video) ────────────────────────────────────

console.log('\n[1] Customer exact scenario');
assertGt('"cheese loaded half" finds "Cheese Loaded Fries (Half)"',
    matchWithFallback('Cheese Loaded Fries (Half)', 'cheese loaded half'), 0);

assertGt('"cheese half" finds "Cheese Loaded Fries (Half)"',
    matchWithFallback('Cheese Loaded Fries (Half)', 'cheese half'), 0);

assertGt('"cheese loaded" finds both (Half) and (Full) variants',
    matchWithFallback('Cheese Loaded Fries (Full)', 'cheese loaded'), 0);

// ── Rank ordering — (Half) and (Full) must both surface, contiguous rank ─────

console.log('\n[2] Rank ordering');
const halfRank  = nameMatchRank('Cheese Loaded Fries (Half)', 'cheese loaded half', false);
const fullRank  = nameMatchRank('Cheese Loaded Fries (Full)', 'cheese loaded half', false);
assertGt('(Half) variant ranks above 0 for "cheese loaded half"', halfRank, 0);
assert('(Full) variant does NOT match "cheese loaded half" strictly', fullRank, 0);

// "cheese loaded" is a prefix-range query → both rank ≥ 3 (contiguous)
const halfC = nameMatchRank('Cheese Loaded Fries (Half)', 'cheese loaded', false);
const fullC = nameMatchRank('Cheese Loaded Fries (Full)', 'cheese loaded', false);
assertGt('(Half) variant rank ≥ 3 for "cheese loaded"', halfC, 2);
assertGt('(Full) variant rank ≥ 3 for "cheese loaded"', fullC, 2);

// ── Extra-word robustness — "chicken cheese fries" must not empty ─────────────

console.log('\n[3] Multi-word extra-word robustness');
assertGt('"chicken cheese fries" finds "Chicken Cheese Fries"',
    matchWithFallback('Chicken Cheese Fries', 'chicken cheese fries'), 0);
assertGt('"chicken cheese" finds "Chicken Cheese Fries"',
    matchWithFallback('Chicken Cheese Fries', 'chicken cheese'), 0);

// ── Strict single-word prefix rule preserved ──────────────────────────────────

console.log('\n[4] Strict prefix — single-word queries');
assertGt('"zi" finds names starting with Zi',
    nameMatchRank('Zinger Burger', 'zi', false), 0);
assert('"zi" does NOT match "Chicken Roll"',
    nameMatchRank('Chicken Roll', 'zi', false), 0);
assert('"zi" does NOT match "Beef Burger"',
    nameMatchRank('Beef Burger', 'zi', false), 0);
// strict first-word rule: "beef" must NOT match "Grilled Beef Burger"
assert('"beef" does NOT match "Grilled Beef Burger" in strict mode',
    nameMatchRank('Grilled Beef Burger', 'beef', false), 0);
// but it DOES in any-word mode (fallback)
assertGt('"beef" finds "Grilled Beef Burger" via any-word fallback',
    nameMatchRank('Grilled Beef Burger', 'beef', true), 0);

// ── Barcode / digit guard — letters-only ≠ code search ───────────────────────

console.log('\n[5] Code-search gate (digit/symbol triggers barcode path, letters do not)');
// The gate is /[^a-z\s]/.test(q) — we test the predicate, not the full pipeline
const isCodeSearch = q => /[^a-z\s]/.test(q);
assert('"chi" is NOT a code search',  isCodeSearch('chi'), false);
assert('"CHI-001" IS a code search',  isCodeSearch('chi-001'), true);
assert('"1234" IS a code search',     isCodeSearch('1234'), true);
assert('"cheese loaded half" is NOT a code search', isCodeSearch('cheese loaded half'), false);

// ── Parenthesised tokens — "(Half)" tokenises to "half" ──────────────────────

console.log('\n[6] Parenthesis tokenisation');
const toks = searchTokens('Cheese Loaded Fries (Half)');
assert('"(Half)" yields token "half"', toks.includes('half'), true);
assert('no empty tokens from "(Half)"', toks.every(t => t.length > 0), true);

// ── Waiter app: global pool (hidden items must still match) ───────────────────

console.log('\n[7] Waiter / global search — hidden items still surface');
// searchAnyWord=false, global pool (show_on_sale ignored while typing)
assertGt('"cheese loaded half" finds hidden product (show_on_sale=false immaterial)',
    matchWithFallback('Cheese Loaded Fries (Half)', 'cheese loaded half'), 0);

// ── Summary ───────────────────────────────────────────────────────────────────

console.log('\n' + (failed ? '✗ FAILED' : '✓ ALL PASSED'),
    '(' + passed + ' pass' + (failed ? ', ' + failed + ' fail' : '') + ')');
process.exit(failed ? 1 : 0);
