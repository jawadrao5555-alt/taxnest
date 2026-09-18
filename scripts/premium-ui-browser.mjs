import { lstatSync, mkdirSync, readFileSync, realpathSync, renameSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { assertLocalOnlyBaseUrl, attachDiagnostics, launchLocalBrowser } from './lib/local-browser.mjs';
import {
    auditPremiumCartState,
    auditPremiumVisualPage,
    assertPremiumCartState,
    assertPremiumVisualAudit,
} from './premium-ui-assertions.mjs';
import { runPremiumUiMobileRegression } from './premium-ui-mobile-regression.mjs';

const baseUrl = assertLocalOnlyBaseUrl(process.env.BASE_URL || 'http://localhost:5911');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixturePath = process.env.RC_BROWSER_FIXTURE;
if (!fixturePath || !/^\/tmp\/taxnest-rc-browser-[0-9]+(?:-[A-Za-z0-9_.-]+)?\/safe-runtime\/browser-state\/fixture\.json$/.test(fixturePath)) {
    throw new Error('RC_BROWSER_FIXTURE must be generated under exact isolated /tmp state');
}
const fixtureStat = lstatSync(fixturePath);
if (!fixtureStat.isFile() || realpathSync(fixturePath) !== fixturePath) {
    throw new Error('RC_BROWSER_FIXTURE must be a non-symlink regular file at its exact isolated target');
}
const fixture = JSON.parse(readFileSync(fixturePath, 'utf8'));
if (!fixture.synthetic || !Array.isArray(fixture.readOnlyJourneys)) {
    throw new Error('synthetic browser fixture is required');
}

const phase = String(process.env.RC_PREMIUM_PHASE || 'before').replace(/[^a-z0-9_-]/gi, '-');
const evidenceDir = path.resolve(process.cwd(), 'docs/ui-premium/evidence');
mkdirSync(evidenceDir, { recursive: true, mode: 0o700 });
const requested = String(process.env.RC_PREMIUM_ONLY || '').split(',').map(value => value.trim()).filter(Boolean);
const expectedTheme = process.env.RC_PREMIUM_EXPECTED_THEME === 'dark'
    || process.env.RC_PREMIUM_EXPECTED_THEME === 'light'
    ? process.env.RC_PREMIUM_EXPECTED_THEME
    : null;
const runMobileRegression = process.env.RC_PREMIUM_MOBILE_REGRESSION === '1';
const requestedZoom = Number(process.env.RC_PREMIUM_ZOOM || 100);
if (![80, 100, 125].includes(requestedZoom)) {
    throw new Error('RC_PREMIUM_ZOOM must be 80, 100 or 125');
}
const views = [
    ['desktop', { width: 1440, height: 960 }],
    ['tablet', { width: 834, height: 960 }],
    ['mobile', { width: 390, height: 844 }],
];
const loopback = host => ['127.0.0.1', 'localhost', '::1', '[::1]'].includes(host);
const journeyByName = new Map(fixture.readOnlyJourneys.map(journey => [journey.name, journey]));
const required = {
    admin: 'hotel-admin-manage-as',
    praRestaurant: 'category-pra-restaurant',
    retail: 'fiscal',
    hotel: 'hotel-owner',
    services: 'service-work-orders-manager',
    health: 'health',
};
const labels = Object.values(required).map((_, index) => Object.keys(required)[index]);
const aliases = {
    'admin-dashboard': 'admin',
    'pra-restaurant-sale': 'praRestaurant',
    'retail-sale': 'retail',
    'hotel-dashboard': 'hotel',
    'services-dashboard': 'services',
    'health-dashboard': 'health',
};
const selectedLabels = requested.length ? requested.map(label => aliases[label] || label) : labels;
const unknownLabels = selectedLabels.filter(label => !labels.includes(label));
if (unknownLabels.length) throw new Error(`unknown RC_PREMIUM_ONLY surface(s): ${unknownLabels.join(', ')}`);
if (runMobileRegression && !selectedLabels.includes('retail')) {
    throw new Error('RC_PREMIUM_MOBILE_REGRESSION=1 requires the retail surface to be selected');
}
for (const [label, name] of Object.entries(required)) {
    if (!journeyByName.has(name)) throw new Error(`fixture journey missing: ${label}/${name}`);
}

const manifest = JSON.parse(readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
const cssAsset = manifest['resources/css/app.css']?.file;
if (!cssAsset) throw new Error('local Vite CSS asset is missing from public/build/manifest.json');
const localTailwindCss = readFileSync(path.join(root, 'public/build', cssAsset), 'utf8');
const localAlpine = readFileSync(path.join(root, 'node_modules/alpinejs/dist/cdn.min.js'), 'utf8');
const localAlpineCollapse = readFileSync(path.join(root, 'node_modules/@alpinejs/collapse/dist/cdn.min.js'), 'utf8');
const localTailwindRuntime = `(() => { const style = document.createElement('style'); style.dataset.rcLocalTailwind = '1'; style.textContent = ${JSON.stringify(localTailwindCss)}; document.head.appendChild(style); })();`;

function bodyText(page) {
    return page.locator('body').innerText().catch(() => '');
}

async function dismissKnownOverlays(page) {
    await page.waitForTimeout(450);
    for (let attempt = 0; attempt < 8; attempt++) {
        const overlays = [
            ['[data-fbr-decision-card]:visible', 'button'],
            ['[x-show="wnOpen"]:visible', 'button'],
            ['[data-pra-elaan-popup]:visible', 'button'],
            ['[data-pos-survey]:visible', 'button'],
        ];
        let dismissed = false;
        for (const [selector, buttonSelector] of overlays) {
            const overlay = page.locator(selector).first();
            if (!await overlay.count() || !await overlay.isVisible().catch(() => false)) continue;
            const button = overlay.locator(buttonSelector).last();
            if (await button.count()) {
                await button.click({ timeout: 4000 }).catch(() => {});
                await page.waitForTimeout(300);
                dismissed = true;
                break;
            }
        }
        if (!dismissed) break;
    }
}

async function login(page, journey) {
    const response = await page.goto(`${baseUrl}${journey.loginPath}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    if (!response || response.status() >= 400) throw new Error(`${journey.name}: login page HTTP ${response?.status() || 'no-response'}`);
    const loginField = page.locator('input[name="login"],input[name="email"],input#login').first();
    await loginField.fill(journey.login);
    const password = page.locator('input[name="password"]').first();
    await password.fill(journey.password);
    await Promise.all([
        page.waitForURL(url => !url.pathname.endsWith(journey.loginPath), { timeout: 30000 }).catch(() => null),
        password.press('Enter'),
    ]);
    if (new URL(page.url()).pathname === journey.loginPath) throw new Error(`${journey.name}: authentication stayed on login`);
}

async function waitForSurface(page) {
    await page.waitForFunction(() => !/NestPOS is loading/i.test(document.body?.innerText || ''), null, { timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(350);
    await dismissKnownOverlays(page);
}

async function activateExpectedTheme(page, theme) {
    if (!theme) return;
    await page.emulateMedia({ colorScheme: theme });
    const matchesExpectedTheme = state => state.dark === (state.expected === 'dark')
        && new RegExp(`\\b${state.expected}\\b`, 'i').test(state.colorScheme || '');
    let state = await page.evaluate(expected => {
        const root = document.documentElement;
        return {
            expected,
            dark: root.classList.contains('dark'),
            colorScheme: getComputedStyle(root).colorScheme || '',
        };
    }, theme);
    if (!matchesExpectedTheme(state)) {
        const toggle = page.locator(
            'button[aria-label*="dark mode" i]:visible, button[title*="dark mode" i]:visible',
        ).first();
        // PRA exposes its real persisted toggle through the command palette,
        // not the separate colour-palette picker (Midnight is not dark mode).
        let command = null;
        if (!await toggle.count()) {
            await page.keyboard.press('Control+k');
            const search = page.locator('input[x-ref="cmdInput"]:visible');
            await search.fill('dark');
            command = page.locator('.cmd-item:visible').filter({ hasText: /dark mode/i }).first();
            if (!await command.count()) throw new Error(`expected ${theme} command is not visible`);
        }
        const persistedModeResponse = page.waitForResponse(response => (
            response.request().method() === 'POST' && /dark-mode|set-dark-mode/i.test(response.url())
        ), { timeout: 8000 });
        await (command || toggle).click();
        const response = await persistedModeResponse;
        const saved = await response.json();
        if (!response.ok() || saved.success !== true || Boolean(saved.dark) !== (theme === 'dark')) {
            throw new Error(`expected ${theme} mode was not saved`);
        }
        await page.reload({ waitUntil: 'domcontentloaded' });
        await waitForSurface(page);
    }
    await page.waitForFunction(expected => {
        const root = document.documentElement;
        const colorScheme = getComputedStyle(root).colorScheme || '';
        return root.classList.contains('dark') === (expected === 'dark')
            && new RegExp(`\\b${expected}\\b`, 'i').test(colorScheme);
    }, theme, { timeout: 15000 });
    state = await page.evaluate(expected => ({
        expected,
        dark: document.documentElement.classList.contains('dark'),
        colorScheme: getComputedStyle(document.documentElement).colorScheme || '',
    }), theme);
    if (!matchesExpectedTheme(state)) throw new Error(`expected ${theme} mode did not activate`);
}

async function activateBrowserZoom(page, percent, physicalViewport) {
    const scale = percent / 100;
    const session = await page.context().newCDPSession(page);
    await session.send('Emulation.setDeviceMetricsOverride', {
        width: Math.round(physicalViewport.width / scale),
        height: Math.round(physicalViewport.height / scale),
        screenWidth: physicalViewport.width,
        screenHeight: physicalViewport.height,
        deviceScaleFactor: scale,
        mobile: false,
    });
    await page.waitForTimeout(150);
    const zoom = await page.evaluate(() => ({
        devicePixelRatio: window.devicePixelRatio,
        layoutWidth: document.documentElement.clientWidth,
        visualScale: window.visualViewport?.scale || 1,
    }));
    const expectedRatio = percent / 100;
    if (Math.abs(zoom.devicePixelRatio - expectedRatio) > 0.06) {
        throw new Error(`browser zoom ${percent}% did not activate (devicePixelRatio=${zoom.devicePixelRatio})`);
    }
    return zoom;
}

let mobileRegressionCompleted = false;

async function capture(browser, label, journey, pathName, viewport) {
    // Compare server-rendered versions, not a previously cached sale shell.
    const context = await browser.newContext({ viewport, serviceWorkers: 'block' });
    await context.addInitScript(() => {
        try {
            localStorage.setItem('pos_show_products', '1');
        } catch {}
    });
    await context.route('**/*', route => {
        const target = new URL(route.request().url());
        if (loopback(target.hostname)) return route.continue();
        if (target.hostname === 'cdn.tailwindcss.com' && target.pathname === '/') {
            return route.fulfill({ status: 200, contentType: 'text/javascript', body: localTailwindRuntime });
        }
        if (target.hostname === 'cdn.jsdelivr.net' && target.pathname.includes('/alpinejs@')) {
            return route.fulfill({ status: 200, contentType: 'text/javascript', body: localAlpine });
        }
        if (target.hostname === 'cdn.jsdelivr.net' && target.pathname.includes('@alpinejs/collapse')) {
            return route.fulfill({ status: 200, contentType: 'text/javascript', body: localAlpineCollapse });
        }
        return route.abort('blockedbyclient');
    });
    const page = await context.newPage();
    const diagnostics = attachDiagnostics(page);
    try {
        await login(page, journey);
        const response = await page.goto(`${baseUrl}${pathName}`, { waitUntil: 'domcontentloaded', timeout: 45000 });
        await waitForSurface(page);
        const status = response?.status() || 0;
        if (!response || status >= 400) throw new Error(`${label}: target HTTP ${status || 'no-response'}`);
        if (page.url().includes('/login')) throw new Error(`${label}: redirected to login`);
        if (new URL(page.url()).pathname !== pathName) throw new Error(`${label}: final path ${new URL(page.url()).pathname}, expected ${pathName}`);
        if (/sale screen needs a fresh copy/i.test(await bodyText(page))) {
            throw new Error(`${label}: recovery page is not valid visual evidence`);
        }
        if (label === 'admin-dashboard') {
            // The baseline migration has a non-synthetic-looking DI bootstrap
            // row; publish only the explicitly synthetic PRA company tab.
            await page.getByRole('button', { name: /PRA POS/ }).click();
        }
        // Selecting a below-fold tab must not turn dashboard evidence into
        // a scrolled company-table screenshot.
        await page.evaluate(() => {
            window.scrollTo(0, 0);
            for (const element of document.querySelectorAll('main, [class*="overflow"]')) {
                element.scrollTop = 0;
            }
        });
        await activateExpectedTheme(page, expectedTheme);
        const zoom = await activateBrowserZoom(page, requestedZoom, viewport);
        let audit = null;
        if (phase !== 'before') {
            audit = await page.evaluate(auditPremiumVisualPage, expectedTheme ? {
                expectedTheme,
            } : {});
            writeFileSync(
                path.join(evidenceDir, `${phase}-${label}-${viewport.width}-audit.json`),
                JSON.stringify(audit, null, 2) + '\n',
            );
            assertPremiumVisualAudit(audit);
        }
        const layoutMetrics = audit ? {
            viewportWidth: audit.viewport.width,
            scrollWidth: Math.max(audit.overflow.document.scrollWidth, audit.overflow.body.scrollWidth),
            overflow: audit.overflow.document.horizontalOverflow
                || audit.overflow.body.horizontalOverflow
                || audit.overflow.offenders.length > 0,
        } : await page.evaluate(() => ({
            viewportWidth: window.innerWidth,
            scrollWidth: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
            overflow: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) > window.innerWidth + 2,
        }));
        if (Math.abs(layoutMetrics.viewportWidth - zoom.layoutWidth) > 2) {
            throw new Error(`${label}: zoom layout width changed before capture (${layoutMetrics.viewportWidth} vs ${zoom.layoutWidth})`);
        }
        if (runMobileRegression && label === 'retail-sale' && viewport.width === 390) {
            const regression = await runPremiumUiMobileRegression(page, {
                auditCartState: auditPremiumCartState,
                assertCartState: assertPremiumCartState,
                auditVisualPage: auditPremiumVisualPage,
                assertVisualAudit: assertPremiumVisualAudit,
                expectedTheme: expectedTheme || 'light',
            });
            const regressionPath = path.join(evidenceDir, `${phase}-mobile-regression.json`);
            writeFileSync(regressionPath, JSON.stringify(regression, null, 2) + '\n', { mode: 0o600 });
            console.log(`MOBILE_REGRESSION ${regressionPath}`);
            mobileRegressionCompleted = true;
        }
        const screenshot = path.join(evidenceDir, `${phase}-${label}-${viewport.width}-z${requestedZoom}.png`);
        await page.screenshot({ path: screenshot, fullPage: false });
        console.log(`SCREENSHOT ${screenshot}`);
        const text = await bodyText(page);
        console.log(`SURFACE ${label}/${viewport.width}: ${new URL(page.url()).pathname} ${text.slice(0, 90).replace(/\s+/g, ' ')}`);
        overflow.push({
            phase,
            surface: label,
            viewport: viewport.width,
            scrollWidth: layoutMetrics.scrollWidth,
            layoutWidth: layoutMetrics.viewportWidth,
            zoom: requestedZoom,
            overflow: layoutMetrics.overflow,
            path: new URL(page.url()).pathname,
        });
        const browserErrors = [
            ...diagnostics.pageErrors,
            ...diagnostics.consoleErrors.filter(error => !error.includes('ERR_BLOCKED_BY_CLIENT')),
            ...diagnostics.failedRequests.filter(error => !error.includes('ERR_BLOCKED_BY_CLIENT')),
            ...diagnostics.httpErrors.filter(error => !String(error.url).includes('blockedbyclient')),
        ];
        if (browserErrors.length) throw new Error(`DIAGNOSTICS ${label}/${viewport.width}: ${browserErrors[0]}`);
    } finally {
        await context.close();
    }
}

const { browser } = await launchLocalBrowser();
const overflow = [];
try {
    for (const [viewportName, viewport] of views) {
        if (selectedLabels.includes('admin')) await capture(browser, 'admin-dashboard', journeyByName.get(required.admin), '/admin/dashboard', viewport);
        if (selectedLabels.includes('praRestaurant')) await capture(browser, 'pra-restaurant-sale', journeyByName.get(required.praRestaurant), '/pos/invoice/create', viewport);
        if (selectedLabels.includes('retail')) await capture(browser, 'retail-sale', journeyByName.get(required.retail), '/fbr-pos/create', viewport);
        if (selectedLabels.includes('hotel')) await capture(browser, 'hotel-dashboard', journeyByName.get(required.hotel), '/pos/hotel', viewport);
        if (selectedLabels.includes('services')) await capture(browser, 'services-dashboard', journeyByName.get(required.services), '/pos/work-orders', viewport);
        if (selectedLabels.includes('health')) await capture(browser, 'health-dashboard', journeyByName.get(required.health), '/health/dashboard', viewport);
    }
} finally {
    await browser.close();
    const overflowPath = path.join(evidenceDir, `${phase}-overflow.json`);
    const overflowReport = { phase, generatedAt: new Date().toISOString(), surfaces: overflow };
    const temporary = `${overflowPath}.tmp`;
    writeFileSync(temporary, `${JSON.stringify(overflowReport, null, 2)}\n`, { mode: 0o600 });
    renameSync(temporary, overflowPath);
    console.log(`OVERFLOW_REPORT ${overflowPath}`);
}
if (runMobileRegression && !mobileRegressionCompleted) {
    throw new Error('RC_PREMIUM_MOBILE_REGRESSION=1 did not complete the retail mobile regression');
}
console.log(`PREMIUM UI ${phase.toUpperCase()} SCREENSHOTS COMPLETE: ${evidenceDir}`);