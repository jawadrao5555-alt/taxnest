import assert from 'node:assert/strict';
import { createHash, randomUUID } from 'node:crypto';
import { assertLocalOnlyBaseUrl, launchLocalBrowser } from './lib/local-browser.mjs';

const base = assertLocalOnlyBaseUrl(process.env.BASE_URL);
const pass = process.env.HOSPITAL_DEMO_PASSWORD;
assert(pass && process.env.HOSPITAL_DEMO_ALLOW === '1', 'Disposable hospital opt-in and password required');
const { browser } = await launchLocalBrowser();
const errors = [];

async function completeClinicalOrders(page, fixture, token) {
    const post = async (path, data) => {
        const response = await page.request.post(base + '/health/' + path, {
            data, headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' }, maxRedirects: 0,
        });
        assert.equal(response.status(), 302, path + ' returned ' + response.status());
    };
    await post('clinical/visits/' + fixture.visit + '/triage', {priority:'urgent', presenting_problem:'Fictional triage observation'});
    await post('clinical/visits/' + fixture.visit + '/prescription', {
        issue:true, items:[{medicine_name:'Demo pack',quantity:2,instructions:'Fictional dispensing request'}],
    });
    await post('ipd/admissions/' + fixture.stay + '/delivery', {
        method:'caesarean', operation_id:fixture.operation, delivered_at:fixture.at,
        newborns:[{outcome:'liveborn',weight_grams:3100}],
    });
    for (const service of ['lab','radiology']) {
        const request = {patient_id:fixture.patient,test_id:fixture.tests[service],request_key:randomUUID()};
        await post(service + '/orders', request);
        await post(service + '/orders', request);
        await page.goto(base + '/health/' + service);
        const row = page.locator('section').filter({hasText:'Patient ' + fixture.patient + ' · Ordered'});
        const action = await row.locator('form').getAttribute('action');
        const path = new URL(action).pathname.replace('/health/', '').replace(/\/collect$/, '');
        await post(path + '/collect', {accession:'DEMO-' + service + '-' + fixture.patient});
        await post(path + '/release', {result:'Synthetic ' + service + ' report'});
        const report = await page.request.get(base + '/health/' + path + '/report');
        assert.equal(report.status(), 200);
        const bytes = await report.body();
        assert(bytes.subarray(0,4).equals(Buffer.from('%PDF')));
        if (service === 'lab') {
            const attachment = await page.request.post(base + '/health/clinical/visits/' + fixture.visit + '/attachments', {
                headers: { 'X-CSRF-TOKEN':token, Accept:'application/json' }, maxRedirects:0,
                multipart:{kind:'report',caption:'Fictional signed lab report',file:{name:'demo-lab.pdf',mimeType:'application/pdf',buffer:bytes}},
            });
            assert.equal(attachment.status(),302);
            await page.goto(base + '/health/clinical/visits/' + fixture.visit);
            const href = await page.getByRole('link',{name:'demo-lab.pdf',exact:true}).getAttribute('href');
            const download = await page.request.get(href);
            assert.equal(download.status(),200);
            assert(bytes.equals(await download.body()));
            console.log('PASS private clinical attachment upload and download; SHA-256 ' + createHash('sha256').update(bytes).digest('hex'));
        }
    }
    console.log('PASS same-patient emergency triage, prescription, C-section, lab and imaging worklists and signed PDFs');
}

async function receiveDemoStock(page) {
    const response = await page.goto(base + '/health/pharmacy/purchases');
    assert.equal(response.status(), 200);
    await page.locator('button').evaluateAll(buttons => buttons.find(b =>
        b.getAttribute('@click') === 'receiving = !receiving').click());
    const form = page.locator('form[action$="/pharmacy/purchases"]');
    await form.locator('select[name=supplier_id]').selectOption({ label: 'Demo Medical Supplies' });
    const medicine = form.locator('select[name="items[0][medicine_id]"]');
    await medicine.selectOption({ label: 'Demo pack' });
    await form.locator('[name="items[0][quantity]"]').fill('10');
    await form.locator('[name="items[0][cost_price]"]').fill('12');
    await form.locator('[name="items[0][sale_price]"]').fill('20');
    await form.locator('[name="items[0][batch_no]"]').fill('DEMO-BATCH-344');
    await form.locator('[name="items[0][expiry_date]"]').fill('2030-12-31');
    await form.locator('[name=invoice_reference]').fill('DEMO-344-RCV');
    await form.locator('[name=paid_amount]').fill('30');
    await Promise.all([page.waitForNavigation(), form.locator('button[type=submit]').click()]);
    await page.getByText('DEMO-BATCH-344', { exact:false }).first().waitFor();
    await page.locator('button').evaluateAll(buttons => buttons.find(b =>
        (b.getAttribute('@click') || '').includes('payTo(')).click());
    const payment = page.locator('form[action$="/pharmacy/supplier-payments"]');
    await payment.locator('[name=amount]').fill('40');
    await payment.locator('[name=reference]').fill('DEMO-PAY-344');
    await Promise.all([page.waitForNavigation(), payment.locator('button[type=submit]').click()]);
    console.log('PASS authenticated stock receipt and supplier payment forms');
}

try {
    for (const [site, modules] of Object.entries({
        a: ['opd','pharmacy','ipd','lab','accounts','hr','radiology','maternity','emergency'],
        b: ['opd','ipd','accounts','hr','radiology','maternity','emergency'],
        c: ['opd','ipd','accounts','hr'], d: ['opd'], e: ['opd','ipd','accounts','hr','maternity'],
        f: ['opd','pharmacy','ipd','lab','accounts','hr','radiology','maternity','emergency'],
    })) {
        const context = await browser.newContext({ serviceWorkers: 'block' });
        await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
        const page = await context.newPage();
        page.on('pageerror', e => errors.push(e.message));
        await page.goto(base + '/health/login');
        await page.locator('input[name=login]').fill(site + '.health_owner@example.test');
        await page.locator('input[name=password]').fill(pass);
        await Promise.all([page.waitForURL('**/health/dashboard'), page.locator('button[type=submit]').first().click()]);
        const token = await page.locator('meta[name=csrf-token]').getAttribute('content');
        for (const [module, path] of Object.entries({
            opd:'appointments', pharmacy:'pharmacy', ipd:'ipd', lab:'lab', accounts:'billing', hr:'hr', radiology:'radiology',
        })) {
            const response = await page.goto(base + '/health/' + path);
            if (modules.includes(module)) {
                assert.equal(response.status(), 200, site + ' ' + module);
                assert.equal(new URL(page.url()).pathname, '/health/' + path);
            } else {
                const denied = await page.request.get(base + '/health/' + path, {headers:{Accept:'application/json'}});
                assert.equal(denied.status(), 403, site + ' ' + module + ' protected read');
            }
        }
        // Nonexistent fixture IDs distinguish an authorized action reaching record lookup
        // (404) from a module rejecting the action (403). No record is touched.
        for (const [module, path] of Object.entries({
            maternity:'ipd/admissions/2147483646/delivery', emergency:'clinical/visits/2147483646/triage',
        })) {
            const answer = await page.request.post(base + '/health/' + path, {
                headers:{Accept:'application/json', 'X-CSRF-TOKEN':token},
            });
            assert.equal(answer.status(), modules.includes(module) ? 404 : 403, site + ' ' + module);
        }
        if (site === 'a' && process.env.HOSPITAL_DEMO_CLINICAL_IDS) {
            await completeClinicalOrders(page, JSON.parse(process.env.HOSPITAL_DEMO_CLINICAL_IDS), token);
        }
        if (site === 'a' && process.env.HOSPITAL_DEMO_POST_PURCHASE === '1') {
            await receiveDemoStock(page);
        }
        console.log('PASS profile ' + site.toUpperCase() + ' authenticated; nine service route checks');
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
