import assert from 'node:assert/strict';
import { assertLocalOnlyBaseUrl, launchLocalBrowser } from './lib/local-browser.mjs';

const base = assertLocalOnlyBaseUrl(process.env.BASE_URL);
const pass = process.env.HOSPITAL_DEMO_PASSWORD;
assert(pass && process.env.HOSPITAL_DEMO_ALLOW === '1', 'Disposable hospital opt-in and password required');
const { browser } = await launchLocalBrowser();
const errors = [];
try {
    for (const [site, modules] of Object.entries({
        a: ['lab', 'radiology', 'pharmacy', 'ipd'], b: ['radiology', 'ipd'], c: ['ipd'], d: [],
        e: ['ipd'], f: ['lab', 'radiology', 'pharmacy', 'ipd'],
    })) {
        const context = await browser.newContext({ serviceWorkers: 'block' });
        await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
        const page = await context.newPage();
        page.on('pageerror', e => errors.push(e.message));
        await page.goto(base + '/health/login');
        await page.locator('input[name=login]').fill(site + '.health_owner@example.test');
        await page.locator('input[name=password]').fill(pass);
        await Promise.all([page.waitForURL('**/health/dashboard'), page.locator('button[type=submit]').first().click()]);
        for (const module of ['lab', 'radiology', 'pharmacy', 'ipd']) {
            const response = await page.goto(base + '/health/' + module);
            const expected = modules.includes(module);
            assert(expected ? response.status() === 200 && new URL(page.url()).pathname === '/health/' + module
                : response.status() === 403 || new URL(page.url()).pathname !== '/health/' + module,
                site + ' ' + module + ' response ' + response.status() + ' ' + page.url());
        }
        console.log('PASS profile ' + site.toUpperCase() + ' authenticated; four service route checks');
        await context.close();
    }
    const context = await browser.newContext({ serviceWorkers: 'block' });
    await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    const page = await context.newPage();
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(base + '/admin/login');
    await page.locator('input[name=email]').fill('demo.saas@example.test');
    await page.locator('input[name=password]').fill(pass);
    await Promise.all([page.waitForURL('**/admin/dashboard'), page.locator('input[name=password]').press('Enter')]);
    await page.getByRole('link', { name: 'TaxNest ERP', exact: true }).first().click();
    assert.equal(new URL(page.url()).searchParams.get('product_type'), 'erps');
    await page.getByText('TaxNest Demo General Hospital', { exact: false }).first().waitFor({ state: 'visible', timeout: 10000 });
    await page.getByText('TaxNest Demo General Hospital', { exact: false }).first().click();
    await page.getByRole('button', { name: 'Save ERP access' }).waitFor({ state: 'visible' });
    console.log('PASS SaaS login, ERP navigation and filtered hospital list');
    assert.deepEqual(errors, []);
} finally {
    await browser.close();
}
