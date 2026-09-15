#!/usr/bin/env node
import { mkdirSync, renameSync, existsSync } from 'node:fs';
import path from 'node:path';
import {
    ROOT,
    assertLocalOnlyBaseUrl,
    launchLocalBrowser,
    parseEnvFile,
} from './lib/local-browser.mjs';
import pw from 'playwright-core';

const BASE_URL = assertLocalOnlyBaseUrl(process.env.BASE_URL || 'http://127.0.0.1:8000');
const creds = parseEnvFile(path.join(ROOT, '.local/hotel-qa-creds.env'));
const login = creds.HOTEL_QA_LOGIN;
const password = creds.HOTEL_QA_PASS;
if (!login || !password) {
    console.error('Missing hotel QA creds');
    process.exit(2);
}

const outDir = '/opt/cursor/artifacts';
mkdirSync(outDir, { recursive: true });

const chrome = process.env.CLOUD_LOCAL_QA_CHROME || '/usr/local/bin/google-chrome';
const browser = await pw.chromium.launch({
    executablePath: chrome,
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
});
const context = await browser.newContext({
    viewport: { width: 1280, height: 800 },
    ignoreHTTPSErrors: true,
    recordVideo: { dir: outDir, size: { width: 1280, height: 800 } },
});
const page = await context.newPage();

async function dismiss() {
    for (const re of [/Samajh Gaya|Got it/i, /Baad Mein|Later/i]) {
        const btn = page.getByRole('button', { name: re }).first();
        if (await btn.isVisible().catch(() => false)) {
            await btn.click({ timeout: 3000 }).catch(() => null);
        }
    }
    await page.evaluate(() => {
        document.getElementById('tn-domain-move-notice')?.remove();
        document.querySelectorAll('[data-wn-featured], [x-data*="wnOpen"]').forEach((el) => el.remove());
        document.querySelectorAll('.fixed.inset-0').forEach((el) => {
            const t = el.innerText || '';
            if (/Naye Updates|AAP KI RAYE|Caller ID|Samajh Gaya|Baad Mein|whats.?new|Mashwara/i.test(t)) {
                el.remove();
            }
        });
    }).catch(() => {});
}

await page.goto(`${BASE_URL}/pos/login`, { waitUntil: 'domcontentloaded' });
await page.fill('input[name="login"]', login);
await page.fill('input[name="password"]', password);
await Promise.all([
    page.waitForURL((u) => !String(u).includes('/pos/login'), { timeout: 30000 }).catch(() => null),
    page.getByRole('button', { name: /sign in|Login|لاگ/i }).first().click(),
]);
await dismiss();
await page.goto(`${BASE_URL}/pos/hotel`, { waitUntil: 'domcontentloaded' });
await dismiss();
await page.waitForTimeout(1500);
await page.goto(`${BASE_URL}/pos/hotel/restaurant`, { waitUntil: 'domcontentloaded' });
await dismiss();
await page.waitForTimeout(1800);
await page.goto(`${BASE_URL}/pos/hotel/restaurant/exit`, { waitUntil: 'domcontentloaded' });
await dismiss();
await page.waitForTimeout(1500);

const video = page.video();
await context.close();
await browser.close();
const raw = await video.path();
const target = path.join(outDir, 'hotel-front-desk-outlet-demo.webm');
if (existsSync(raw)) renameSync(raw, target);
console.log('Wrote', target);
