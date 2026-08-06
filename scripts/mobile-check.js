#!/usr/bin/env node
// Headless mobile-width check for admin pages at 390px viewport
const { chromium } = require('playwright-core');

const BASE = 'http://127.0.0.1:5000';
const CHROMIUM = '/nix/store/qa9cnw4v5xkxyip6mb9kxqfq1z4x2dx1-chromium-138.0.7204.100/bin/chromium';
const VP = { width: 390, height: 844 };

async function measurePage(page, label) {
    await page.waitForTimeout(600);
    const m = await page.evaluate(() => {
        const vw = window.innerWidth;
        const dw = document.documentElement.scrollWidth;
        // Find elements wider than viewport
        const offenders = [];
        document.querySelectorAll('*').forEach(el => {
            const r = el.getBoundingClientRect();
            if (r.right > vw + 2) { // 2px tolerance
                const cls = (el.className || '').toString().substring(0, 80);
                offenders.push({ tag: el.tagName, cls, right: Math.round(r.right), w: Math.round(r.width) });
            }
        });
        // Deduplicate by keeping only the widest unique right edge per tag+cls combo
        const seen = new Set();
        const unique = offenders.filter(o => {
            const key = o.tag + o.right;
            if (seen.has(key)) return false;
            seen.add(key); return true;
        }).slice(0, 8);
        return { vw, dw, overflow: dw > vw, offenders: unique };
    });
    const icon = m.overflow ? '❌ OVERFLOW' : '✅ OK';
    console.log(`\n[${label}] viewport=${m.vw}  scrollWidth=${m.dw}  ${icon}`);
    if (m.offenders.length) {
        console.log('  Offending elements:');
        m.offenders.forEach(o => console.log(`    <${o.tag}> right=${o.right}px w=${o.w}px  .${o.cls}`));
    }
    return m;
}

(async () => {
    const browser = await chromium.launch({
        executablePath: CHROMIUM,
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
        headless: true,
    });
    const ctx = await browser.newContext({ viewport: VP });
    const page = await ctx.newPage();

    // ── Login ──────────────────────────────────────────────────────────────
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle', timeout: 20000 });
    await page.fill('input[name="email"]', 'admin@taxnest.com');
    await page.fill('input[name="password"]', 'TestAdmin99!');
    await page.click('button[type="submit"]');
    await page.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 });
    console.log('Logged in →', page.url());

    // ── Company detail (try several IDs) ───────────────────────────────────
    let companyChecked = false;
    for (const cid of [7, 2, 9151, 1, 3]) {
        await page.goto(`${BASE}/admin/companies/${cid}`, { waitUntil: 'networkidle', timeout: 15000 });
        if (page.url().includes('/companies/' + cid)) {
            // get section visibility
            const sections = await page.evaluate(() => ({
                hasVPS: !!document.querySelector('[x-data*="vpsSetup"]') || document.body.innerHTML.includes('VPS'),
                hasTeam: document.body.innerHTML.includes('Team &amp; Last Logins') || document.body.innerHTML.includes('Team & Last'),
                companyName: document.querySelector('h1')?.textContent?.trim() || '?',
            }));
            console.log(`Company ${cid}: "${sections.companyName}" VPS=${sections.hasVPS} Team=${sections.hasTeam}`);
            await measurePage(page, `company/${cid} detail`);

            // Screenshot for visual confirmation
            await page.screenshot({ path: `scripts/ss-company-${cid}.jpg`, type: 'jpeg', quality: 85, fullPage: false });
            console.log(`  Screenshot: scripts/ss-company-${cid}.jpg`);
            companyChecked = true;
            break;
        }
        console.log(`  ${cid} redirected to ${page.url()}`);
    }
    if (!companyChecked) console.log('WARNING: could not load any company detail page');

    // ── Dashboard ──────────────────────────────────────────────────────────
    await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'networkidle', timeout: 15000 });
    const dash = await measurePage(page, 'dashboard');
    // Specifically check the tab bar
    const tabBarW = await page.evaluate(() => {
        const tb = document.querySelector('.flex.border-b');
        return tb ? tb.scrollWidth : null;
    });
    console.log(`  Tab bar scrollWidth: ${tabBarW}px (viewport=390)`);
    await page.screenshot({ path: 'scripts/ss-dashboard.jpg', type: 'jpeg', quality: 85, fullPage: false });

    // ── Companies list ─────────────────────────────────────────────────────
    await page.goto(`${BASE}/admin/companies`, { waitUntil: 'networkidle', timeout: 15000 });
    await measurePage(page, 'companies list');
    await page.screenshot({ path: 'scripts/ss-companies.jpg', type: 'jpeg', quality: 85, fullPage: false });

    // ── Payment proofs ─────────────────────────────────────────────────────
    await page.goto(`${BASE}/admin/payment-proofs`, { waitUntil: 'networkidle', timeout: 15000 });
    await measurePage(page, 'payment proofs');
    await page.screenshot({ path: 'scripts/ss-proofs.jpg', type: 'jpeg', quality: 85, fullPage: false });

    await browser.close();
    console.log('\nDone.');
    process.exit(0);
})().catch(e => { console.error('FATAL:', e); process.exit(1); });
