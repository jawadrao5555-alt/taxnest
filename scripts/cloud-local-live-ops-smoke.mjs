#!/usr/bin/env node
/**
 * Local Chrome smoke for /admin/live-ops (super_admin only).
 * Loopback-only — never production.
 *
 * Credentials: LIVE_OPS_ADMIN_EMAIL / LIVE_OPS_ADMIN_PASSWORD or
 * .local/live-ops-admin.env (gitignored).
 *
 * Usage:
 *   BASE_URL=http://127.0.0.1:8000 node scripts/cloud-local-live-ops-smoke.mjs
 */
import {
    assertLocalOnlyBaseUrl,
    attachDiagnostics,
    launchLocalBrowser,
    parseEnvFile,
    saveEvidenceScreenshot,
    ROOT,
} from './lib/local-browser.mjs';
import path from 'node:path';
import { existsSync, writeFileSync, mkdirSync } from 'node:fs';

const BASE_URL = (() => {
    try {
        return assertLocalOnlyBaseUrl(process.env.BASE_URL || 'http://127.0.0.1:8000');
    } catch (e) {
        console.error(`REFUSED: ${e.message}`);
        process.exit(2);
    }
})();

function loadAdminCreds() {
    const file = parseEnvFile(path.join(ROOT, '.local/live-ops-admin.env'));
    const email = process.env.LIVE_OPS_ADMIN_EMAIL || file.LIVE_OPS_ADMIN_EMAIL || '';
    const password = process.env.LIVE_OPS_ADMIN_PASSWORD || file.LIVE_OPS_ADMIN_PASSWORD || '';
    if (!email || !password) {
        throw new Error('Set LIVE_OPS_ADMIN_EMAIL/PASSWORD or write .local/live-ops-admin.env');
    }
    if (/taxnest\.pk|qa\.fullaudit/i.test(email)) {
        throw new Error(`REFUSED live admin identity: ${email}`);
    }
    return { email, password };
}

let failed = 0;
const ok = (m) => console.log(`    OK: ${m}`);
const bad = (m) => { failed++; console.error(`    FAIL: ${m}`); };
const cannotRun = (m) => { console.error(`could not run: ${m}`); process.exit(2); };

async function main() {
    let creds;
    try { creds = loadAdminCreds(); } catch (e) { cannotRun(e.message); }

    const companyId = process.env.LIVE_OPS_COMPANY_ID || '2';

    let browser; let page; let diag;
    try {
        const launched = await launchLocalBrowser();
        browser = launched.browser;
        const context = await browser.newContext({ viewport: { width: 1400, height: 900 } });
        page = await context.newPage();
        diag = attachDiagnostics(page);
    } catch (e) {
        cannotRun(e.message || String(e));
    }

    try {
        console.log(`\n  Open ${BASE_URL}/admin/login`);
        const loginResp = await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        if (!loginResp || loginResp.status() >= 500) cannotRun(`admin login HTTP ${loginResp?.status()}`);
        await page.fill('input[name="email"]', creds.email);
        await page.fill('input[name="password"]', creds.password);
        await Promise.all([
            page.waitForURL((u) => !String(u).includes('/admin/login'), { timeout: 30000 }).catch(() => null),
            page.locator('input[name="password"]').press('Enter'),
        ]);
        if (String(page.url()).includes('/admin/login')) {
            bad('admin login failed — still on login');
        } else {
            ok('admin authenticated');
        }
        await saveEvidenceScreenshot(page, 'liveops-01-dashboard');

        console.log('\n  Open /admin/live-ops');
        const lo = await page.goto(`${BASE_URL}/admin/live-ops`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        if (!lo || lo.status() >= 400) bad(`/admin/live-ops HTTP ${lo?.status()}`);
        else ok('live-ops page HTTP ok');
        const body = await page.content();
        if (!/Live Ops/i.test(body)) bad('missing Live Ops heading');
        else ok('Live Ops heading visible');
        if (!/Run diagnostic/i.test(body)) bad('missing diagnostic form');
        else ok('diagnostic form visible');
        if (!/OWNER_APPROVES_LIVE_OPS_FIX/.test(body)) bad('missing approval phrase hint');
        else ok('approval phrase documented on page');
        await saveEvidenceScreenshot(page, 'liveops-02-index');

        // Run COMPANY_DIAGNOSTIC
        console.log('\n  Run COMPANY_DIAGNOSTIC');
        await page.selectOption('select[name="operation"]', 'COMPANY_DIAGNOSTIC');
        await page.fill('input[name="company_id"]', String(companyId));
        await Promise.all([
            page.waitForURL((u) => String(u).includes('/admin/live-ops') || String(u).includes('report') || true, { timeout: 30000 }).catch(() => null),
            page.click('form[action*="diagnose"] button[type="submit"], form[action*="diagnose"] button'),
        ]);
        // diagnose returns a report view
        await page.waitForTimeout(500);
        const reportHtml = await page.content();
        if (/Diagnostic report/i.test(reportHtml) || /report_id|artifact_digest|likely_root_cause/i.test(reportHtml)) {
            ok('diagnostic report rendered');
        } else if (/error|Invalid|not found/i.test(reportHtml) && !/Live Ops/i.test(await page.locator('h1').first().textContent().catch(() => ''))) {
            bad('diagnostic did not render report');
        } else {
            // May redirect back with flash — still check for success path
            if (/Diagnostic report/i.test(reportHtml)) ok('diagnostic report rendered');
            else bad('diagnostic report not found after submit');
        }
        await saveEvidenceScreenshot(page, 'liveops-03-report');

        // Back to index — propose high-risk should be impossible via UI select (not listed)
        await page.goto(`${BASE_URL}/admin/live-ops`, { waitUntil: 'networkidle', timeout: 30000 });
        const actionOptions = await page.$$eval('select[name="action"] option', (opts) => opts.map((o) => o.value));
        if (actionOptions.includes('REGENERATE_AGENT_API_KEY') || actionOptions.includes('ARBITRARY_SQL')) {
            bad('high-risk actions present in UI select');
        } else {
            ok('high-risk actions absent from remediation dropdown');
        }

        // Propose low-risk refresh
        console.log('\n  Propose REFRESH_OPERATIONAL_STATE');
        const proposeForm = page.locator('form[action*="propose"]');
        await proposeForm.locator('select[name="action"]').selectOption('REFRESH_OPERATIONAL_STATE');
        await proposeForm.locator('input[name="company_id"]').fill(String(companyId));
        await proposeForm.locator('input[name="proposal"]').fill('browser qa refresh');
        const idem = proposeForm.locator('input[name="idempotency_key"]');
        if (await idem.count()) await idem.fill(`browser-qa-${Date.now()}`);
        await Promise.all([
            page.waitForURL(/\/admin\/live-ops\/remediation\/[A-Za-z0-9]+/, { timeout: 30000 }),
            proposeForm.locator('button').click({ force: true }),
        ]);
        if (/\/admin\/live-ops\/remediation\//.test(page.url())) {
            ok('remediation detail page opened');
        } else {
            bad(`propose did not land on remediation page (${page.url()})`);
        }
        await saveEvidenceScreenshot(page, 'liveops-04-remediation');

        // Wrong approval phrase
        console.log('\n  Reject wrong approval phrase');
        await page.fill('input[name="owner_approval_phrase"]', 'please-fix');
        await Promise.all([
            page.waitForLoadState('domcontentloaded'),
            page.locator('form[action*="approve"] button').click({ force: true }),
        ]);
        await page.waitForTimeout(400);
        const afterWrong = await page.content();
        if (/approval phrase|required|Cannot|error|Explicit owner|Invalid/i.test(afterWrong)) {
            ok('wrong phrase rejected');
        } else {
            bad('wrong phrase did not show error');
        }

        console.log('\n  Approve with correct phrase + execute');
        await page.fill('input[name="owner_approval_phrase"]', 'OWNER_APPROVES_LIVE_OPS_FIX');
        const execBox = await page.$('input[name="execute_now"]');
        if (execBox) await execBox.check();
        await Promise.all([
            page.waitForLoadState('domcontentloaded'),
            page.locator('form[action*="approve"] button').click({ force: true }),
        ]);
        await page.waitForTimeout(800);
        const afterOk = await page.content();
        if (/executed|Execution|verification/i.test(afterOk)) ok('approved remediation executed');
        else bad('execution evidence missing');
        await saveEvidenceScreenshot(page, 'liveops-05-executed');


        // Audit trail on index
        await page.goto(`${BASE_URL}/admin/live-ops`, { waitUntil: 'domcontentloaded' });
        const idx = await page.content();
        if (/Audit trail|diagnostic\.request|remediation\./i.test(idx)) ok('audit trail section present with events');
        else bad('audit trail empty or missing');

        // Diagnostics printer/PRA forms present
        if (/PRINTER_HEALTH|PRA_HEALTH|COMPANY_DIAGNOSTIC/.test(idx)) ok('printer/PRA diagnostic ops listed');
        else bad('printer/PRA ops missing');

        const hard = (diag?.pageErrors || []).filter((e) => !/favicon/i.test(e));
        if (hard.length) {
            bad(`page errors: ${hard.slice(0, 3).join(' | ')}`);
        } else {
            ok('no hard page errors');
        }
    } finally {
        try { await browser.close(); } catch {}
    }

    if (failed) {
        console.error(`\nLIVE OPS BROWSER SMOKE: ${failed} FAIL(s)`);
        process.exit(1);
    }
    console.log('\nLIVE OPS BROWSER SMOKE: PASS');
}

main().catch((e) => {
    console.error(e);
    process.exit(2);
});
