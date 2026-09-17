import assert from 'node:assert/strict';
import {
    assertPremiumVisualAudit,
    auditPremiumVisualPageSource,
    assessPremiumMobilePayBounds,
    classifyPremiumDisabledControl,
    findPremiumVisualAuditFailures,
    hasPremiumDisabledAppearance,
} from './premium-ui-assertions.mjs';

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

console.log('premium-ui assertion self-test passed');