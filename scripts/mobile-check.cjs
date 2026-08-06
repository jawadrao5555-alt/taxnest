#!/usr/bin/env node
// Final mobile-width verification — 390px — with correct tab-bar selector
const { chromium } = require('playwright-core');

const LOCAL = 'http://127.0.0.1:5000';
const CHROMIUM = '/nix/store/qa9cnw4v5xkxyip6mb9kxqfq1z4x2dx1-chromium-138.0.7204.100/bin/chromium';
const VP = { width: 390, height: 844 };

async function measure(page, label) {
    await page.waitForTimeout(700);
    const m = await page.evaluate(() => {
        const vw = window.innerWidth;
        const dw = document.documentElement.scrollWidth;
        // Elements whose right edge exceeds viewport AND are NOT inside an overflow-x container
        const offenders = [];
        document.querySelectorAll('*').forEach(el => {
            const r = el.getBoundingClientRect();
            if (r.right <= vw + 2) return;
            // Walk up: if any ancestor has overflow-x scroll/auto, this is contained — skip
            let p = el.parentElement;
            while (p) {
                const s = getComputedStyle(p).overflowX;
                if (s === 'scroll' || s === 'auto') return;
                p = p.parentElement;
            }
            const cls = (el.className || '').toString().substring(0, 80);
            offenders.push({ tag: el.tagName, cls, right: Math.round(r.right) });
        });
        const seen = new Set();
        const unique = offenders.filter(o => {
            const k = o.tag + o.right; if (seen.has(k)) return false; seen.add(k); return true;
        }).slice(0, 8);
        return { vw, dw, overflow: dw > vw, offenders: unique };
    });
    const icon = m.overflow ? '❌ OVERFLOW (page scrolls)' : '✅ OK';
    console.log(`\n[${label}] viewport=${m.vw}  scrollWidth=${m.dw}  ${icon}`);
    if (m.offenders.length) {
        console.log('  Uncontained offenders (not inside overflow-x wrapper):');
        m.offenders.forEach(o => console.log(`    <${o.tag}> right=${o.right}px  "${o.cls.substring(0,60)}"`));
    } else if (m.overflow) {
        console.log('  (all overflowing elements are inside overflow-x containers)');
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

    // Intercept: rewrite https://127.0.0.1 redirects to http://127.0.0.1
    await page.route('**/*', async route => {
        let response;
        try { response = await route.fetch({ maxRedirects: 0 }); }
        catch { await route.continue(); return; }
        const s = response.status();
        if ([301,302,303,307,308].includes(s)) {
            const loc = response.headers()['location'] || '';
            if (loc && !loc.startsWith(LOCAL)) {
                const rw = loc.startsWith('/') ? LOCAL + loc : loc.replace(/^https?:\/\/[^/]+/, LOCAL);
                await route.fulfill({ status: s, headers: { ...response.headers(), location: rw }, body: '' });
                return;
            }
        }
        await route.fulfill({ response });
    });

    await page.goto(`${LOCAL}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await page.waitForSelector('input[name="email"]', { timeout: 8000 });
    await page.fill('input[name="email"]', 'admin@taxnest.com');
    await page.fill('input[name="password"]', 'TestAdmin99!');
    await page.keyboard.press('Enter');
    await page.waitForURL(`${LOCAL}/admin/**`, { timeout: 25000 });
    console.log('Logged in →', page.url());

    // ── Company detail ─────────────────────────────────────────────────────
    let done = false;
    for (const cid of [7, 2, 9151]) {
        await page.goto(`${LOCAL}/admin/companies/${cid}`, { waitUntil: 'networkidle', timeout: 15000 });
        if (!page.url().includes('/companies/' + cid)) { console.log(`  ${cid} redirected`); continue; }
        const meta = await page.evaluate(() => ({
            name: document.querySelector('h1')?.textContent?.trim(),
            hasVPS: document.body.innerText.includes('VPS / Fiscal'),
            hasTeam: document.body.innerText.includes('Team'),
        }));
        console.log(`\nCompany ${cid}: "${meta.name}" VPS=${meta.hasVPS} Team=${meta.hasTeam}`);
        await measure(page, `company/${cid}`);
        // Verify header button group wraps on mobile
        const hdrInfo = await page.evaluate(() => {
            const btn = document.querySelector('div.w-full.sm\\:w-auto');
            return btn ? { w: btn.offsetWidth, top: Math.round(btn.getBoundingClientRect().top) } : null;
        });
        console.log('  Header button group:', hdrInfo ? `width=${hdrInfo.w}px top=${hdrInfo.top}px` : 'not found (no active company)');
        // Check detail row values visible
        const rowCheck = await page.evaluate(() => {
            const rows = document.querySelectorAll('.space-y-2 .flex.justify-between');
            let allVisible = true, clipped = [];
            rows.forEach(r => {
                const val = r.lastElementChild;
                if (!val) return;
                const rect = val.getBoundingClientRect();
                if (rect.right > window.innerWidth + 2) { allVisible = false; clipped.push(val.textContent.trim().substring(0,30)); }
            });
            return { total: rows.length, allVisible, clipped };
        });
        console.log(`  Detail rows: ${rowCheck.total} rows, all values visible=${rowCheck.allVisible}${rowCheck.clipped.length ? ' clipped:'+JSON.stringify(rowCheck.clipped) : ''}`);
        await page.screenshot({ path: 'scripts/final-co-top.jpg', type: 'jpeg', quality: 82 });
        await page.evaluate(() => window.scrollBy(0, 600));
        await page.waitForTimeout(150);
        await page.screenshot({ path: 'scripts/final-co-mid.jpg', type: 'jpeg', quality: 82 });
        done = true; break;
    }

    // ── Dashboard — tab bar specifically ──────────────────────────────────
    await page.goto(`${LOCAL}/admin/dashboard`, { waitUntil: 'networkidle', timeout: 15000 });
    await measure(page, 'dashboard');
    const tabs = await page.evaluate(() => {
        // Find the company-type tab bar specifically (not the mobile header)
        const allFlexBorder = Array.from(document.querySelectorAll('div.flex.border-b'));
        const tabBar = allFlexBorder.find(el => el.querySelector('button[x-data], button[@click]') ||
            el.children.length >= 3 ||
            (el.scrollWidth > el.offsetWidth));
        // Fallback: find via Alpine context
        const btns = document.querySelectorAll('button[\\@click*="activeTab"]');
        if (!btns.length) return { found: false };
        const bar = btns[0].closest('.flex.border-b') || btns[0].parentElement;
        return {
            found: true,
            scrollWidth: bar.scrollWidth,
            offsetWidth: bar.offsetWidth,
            overflowX: getComputedStyle(bar).overflowX,
            scrollable: bar.scrollWidth > bar.offsetWidth,
            btns: Array.from(btns).map(b => b.textContent.trim().substring(0, 30) + `(${b.offsetWidth}px)`),
        };
    });
    console.log('  Tab bar:', JSON.stringify(tabs, null, 2));
    await page.screenshot({ path: 'scripts/final-dashboard.jpg', type: 'jpeg', quality: 82 });

    // ── Companies list ─────────────────────────────────────────────────────
    await page.goto(`${LOCAL}/admin/companies`, { waitUntil: 'networkidle', timeout: 15000 });
    await measure(page, 'companies list');
    await page.screenshot({ path: 'scripts/final-companies.jpg', type: 'jpeg', quality: 82 });

    // ── Payment proofs ─────────────────────────────────────────────────────
    await page.goto(`${LOCAL}/admin/payment-proofs`, { waitUntil: 'networkidle', timeout: 15000 });
    await measure(page, 'payment proofs');
    await page.screenshot({ path: 'scripts/final-proofs.jpg', type: 'jpeg', quality: 82 });

    await browser.close();
    console.log('\nDone.');
    process.exit(0);
})().catch(e => { console.error('FATAL:', e.message); process.exit(1); });
