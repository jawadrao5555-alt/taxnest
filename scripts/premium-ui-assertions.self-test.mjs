import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    assertPremiumVisualAudit,
    auditPremiumCartState,
    auditPremiumVisualPageSource,
    auditPremiumCartStateSource,
    assessPremiumMobilePayBounds,
    classifyPremiumDisabledControl,
    findPremiumVisualAuditFailures,
    hasPremiumDisabledAppearance,
} from './premium-ui-assertions.mjs';
import {
    runPremiumUiMobileRegressionSource,
} from './premium-ui-mobile-regression.mjs';

// Keep the disposable catalogue and the financial expectations in the
// persistent journey aligned; never "repair" a price mismatch in the browser.
const fixtureExtension = readFileSync(
    new URL('./premium-ui-fixture-extension.php', import.meta.url), 'utf8',
);
for (const [name, price] of [
    ['Synthetic Retail Ceramic Mug', 450],
    ['Synthetic Retail Notebook', 250],
    ['Synthetic Retail Gel Pen', 80],
]) {
    assert.match(fixtureExtension, new RegExp(`'name' => '${name}', 'price' => ${price},`));
}
const fbrTemplate = readFileSync(
    new URL('../resources/views/fbr-pos/universal.blade.php', import.meta.url), 'utf8',
);
const paymentGroups = fbrTemplate.slice(
    fbrTemplate.indexOf('class="tn-payment-actions'),
    fbrTemplate.indexOf('<!-- ─── SAVE PROVISIONAL + PAY'),
);
assert.match(paymentGroups, /grid grid-cols-2 grid-cols-2-keep/);
assert.match(paymentGroups, /grid grid-cols-4 grid-cols-4-keep/);
assert.match(fbrTemplate, /Cart auto-restore is intentionally disabled/);
assert.match(runPremiumUiMobileRegressionSource, /after-reload-intentionally-empty/);
assert.match(runPremiumUiMobileRegressionSource, /backupPersisted = true/);

const premiumBrowserRunnerSource = readFileSync(
    new URL('./premium-ui-browser.mjs', import.meta.url),
    'utf8',
);
assert.match(premiumBrowserRunnerSource, /label === ['"]retail-sale['"]/);
assert.doesNotMatch(
    premiumBrowserRunnerSource,
    /runMobileRegression\s*&&\s*label === ['"]retail['"]/,
    'mobile regression dispatch must use the capture label',
);
assert.match(premiumBrowserRunnerSource, /let mobileRegressionCompleted\s*=\s*false/);
assert.match(
    premiumBrowserRunnerSource,
    /if\s*\(runMobileRegression\s*&&\s*!mobileRegressionCompleted\)/,
    'mobile regression must fail closed when it did not complete',
);
assert.match(premiumBrowserRunnerSource, /page\.emulateMedia\(\{\s*colorScheme:\s*theme\s*\}\)/);
assert.match(premiumBrowserRunnerSource, /page\.waitForFunction\(expected\s*=>/);

assert.match(auditPremiumVisualPageSource, /expectedTheme/);
assert.match(auditPremiumVisualPageSource, /shellBackgroundLuminance/);
assert.match(auditPremiumVisualPageSource, /horizontal-overflow/);
assert.match(auditPremiumCartStateSource, /first-row-clipped-at-scroll-top-zero/);
assert.match(auditPremiumCartStateSource, /item\.item_name/);
assert.doesNotMatch(runPremiumUiMobileRegressionSource, /premiumUiMobileSelectors|defaultWait/);

const utilityVariant = classifyPremiumDisabledControl({
    className: 'disabled:opacity-30 rounded-xl',
});
assert.equal(utilityVariant.disabled, false, 'disabled:opacity utility is not a disabled state');
assert.equal(classifyPremiumDisabledControl({ className: 'is-disabled' }).disabled, true);
assert.equal(classifyPremiumDisabledControl({ ariaDisabled: true }).disabled, true);
assert.equal(hasPremiumDisabledAppearance({ opacity: 0.5 }), true);
assert.equal(hasPremiumDisabledAppearance({ opacity: 1, cursor: 'pointer', filter: 'none' }), false);
assert.equal(
    assessPremiumMobilePayBounds(
        { left: 0, top: 800, right: 390, bottom: 850, width: 390, height: 50, opacity: 1 },
        { width: 390, height: 844 },
    ).fullyInsideViewport,
    false,
    'a six-pixel-cut mobile PAY button must fail full visibility',
);
assert.equal(
    assessPremiumMobilePayBounds(
        { left: 0, top: 790, right: 390, bottom: 840, width: 390, height: 50, opacity: 1 },
        { width: 390, height: 844 },
    ).fullyInsideViewport,
    true,
    'a mobile PAY button ending at 840 must pass full visibility',
);

const cleanReport = {
    findings: [],
    header: { found: false },
    categories: {},
};
assert.deepEqual(findPremiumVisualAuditFailures(cleanReport), []);
const requiredCategoryReport = {
    ...cleanReport,
    categories: {
        cartQuantities: {
            category: 'cart-quantities', status: 'checked', records: [{ visible: true }],
        },
    },
};
assert.deepEqual(findPremiumVisualAuditFailures(requiredCategoryReport, {
    requireCategories: ['cart-quantities'],
}), [], 'canonical category names must resolve the report’s camelCase keys');
assert.equal(findPremiumVisualAuditFailures(requiredCategoryReport, {
    requireCategories: ['cart-names'],
}).length, 1, 'a genuinely missing required category must still fail');
assert.equal(findPremiumVisualAuditFailures({
    ...requiredCategoryReport,
    categories: { cartQuantities: { category: 'cart-quantities', status: 'not-visible', records: [] } },
}, { requireCategories: ['cart-quantities'] }).length, 1);
assert.match(auditPremiumVisualPageSource, /const cartQuantities = collectCategory\([\s\S]*?\{ controls: true \}\)/);
assert.doesNotThrow(() => assertPremiumVisualAudit(cleanReport));
const failingReport = {
    ...cleanReport,
    findings: [{ type: 'unresolved-foreground-or-background' }],
};
assert.equal(findPremiumVisualAuditFailures(failingReport).length, 1);
assert.throws(() => assertPremiumVisualAudit(failingReport), /Premium visual audit failed/);

// Exercise the exact in-browser aggregation loop without creating a DOM or
// launching a browser. Keep this extraction coupled to the implementation:
// if the loop changes shape, this self-test should fail rather than testing a
// reimplementation of it.
const aggregationStart = auditPremiumVisualPageSource.indexOf(
    '    for (const category of Object.values(categories)) {',
);
const aggregationEnd = auditPremiumVisualPageSource.indexOf(
    '    for (const key of [\'document\', \'main\']) {',
    aggregationStart,
);
assert.ok(aggregationStart >= 0 && aggregationEnd > aggregationStart, 'aggregation loop is extractable');
const aggregateFromBrowserSource = new Function(
    'categories',
    'report',
    'overflow',
    `${auditPremiumVisualPageSource.slice(aggregationStart, aggregationEnd)}
    report.ok = report.findings.length === 0;
    return report;`,
);
const aggregationReport = aggregateFromBrowserSource(
    {
        good: {
            category: 'header-labels',
            records: [{ pass: true, failure: 'must-not-appear' }],
        },
        contrastFailure: {
            category: 'grand-total',
            records: [{ pass: false, failure: 'contrast-below-4.5', failures: ['contrast-below-4.5'] }],
        },
        simultaneousFailures: {
            category: 'payment-controls',
            records: [{
                pass: false,
                failures: [
                    'unresolved-foreground-or-background',
                    'disabled-control-needs-deliberate-visible-state',
                ],
            }],
        },
    },
    { findings: [], requiredCategoryFailures: [] },
    { document: { horizontalOverflow: false }, main: { horizontalOverflow: false } },
);
assert.deepEqual(
    aggregationReport.findings.map(finding => finding.type),
    ['contrast-below-4.5', 'unresolved-foreground-or-background', 'disabled-control-needs-deliberate-visible-state'],
    'browser aggregation ignores passing records and retains every failure',
);
assert.equal(
    aggregationReport.findings.some(finding => finding.type === 'must-not-appear'),
    false,
);

// A tiny Playwright-shaped stub catches TDZs and accidental module-scope
// dependencies in the exported mobile journey without launching Chromium.
let modalOpen = false;
let initialCartCountRead = false;
let modalCountRead = false;
const stubLocator = selector => ({
    first() { return this; },
    filter() { return this; },
    locator() { return stubLocator('button'); },
    async count() {
        if (selector.includes('[x-show="showPayModal"]')) {
            modalCountRead = true;
            return modalOpen ? 1 : 0;
        }
        if (selector.includes('tn-cart-line')) {
            if (!initialCartCountRead) {
                initialCartCountRead = true;
                return 0;
            }
            return 1;
        }
        return 1;
    },
    async click() {
        if (selector.includes('pay-btn-premium')) modalOpen = true;
        if (modalCountRead && selector === 'button') modalOpen = false;
    },
    async fill() {},
    async evaluate() { return 0; },
});
let pageEvaluateCount = 0;
const stubPage = {
    async evaluate(fn, argument) {
        if (String(fn).includes('Object.keys(localStorage)')) return false;
        if (fn === auditPremiumCartState) return { ok: true, mismatches: [] };
        pageEvaluateCount++;
        if (pageEvaluateCount === 1) return { width: 390, height: 844 };
        if (pageEvaluateCount === 2) return { dark: false, expected: argument };
        return { expected: argument, prefersDark: false, dark: false, colorScheme: 'light' };
    },
    async emulateMedia() {},
    locator(selector) { return stubLocator(selector); },
    async waitForTimeout() {},
    async reload() {},
};
const extractedMobileRegression = new Function(
    `return (${runPremiumUiMobileRegressionSource});`,
)();
const smoke = await extractedMobileRegression(stubPage, {
    auditCartState: auditPremiumCartState,
    assertCartState: report => assert.deepEqual(report.mismatches, []),
    reload: false,
});
assert.equal(smoke.actions.at(-1), 'payment-open-cancel');
assert.equal(smoke.states.length, 7);

console.log('premium-ui assertion self-test passed');