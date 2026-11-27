import assert from 'node:assert/strict';
import {saveEvidenceScreenshot} from '../lib/local-browser.mjs';

export default async function check(page, baseUrl) {
  const original = page.viewportSize();
  try {
    for (const width of [390, 768, 1024, 1366]) {
      await page.setViewportSize({width, height: 900});
      assert.equal((await page.goto(baseUrl, {waitUntil: 'networkidle'})).status(), 200);
      const nav = page.locator('nav').first();
      const menu = nav.getByRole('button', {name: 'Menu', exact: true});
      const action = nav.getByRole('link', {name: 'Get Started', exact: true});
      const main = page.getByRole('main');
      const box = await action.boundingBox();

      assert(box && box.x >= 0 && box.x + box.width <= width, 'Get Started clipped at ' + width);
      assert.equal(await menu.isVisible(), width < 1280);
      assert.equal(await main.getByRole('heading', {level: 1}).innerText(), 'Invoicing, POS and ERP for Pakistan');
      const products = main.getByRole('region', {name: 'Choose a TaxNest product'});
      assert.equal(await products.locator('a.public-card').count(), 4);
      assert.equal(await main.getByRole('table').count(), 0);
      assert(await main.getByRole('img', {name: 'TaxNest Digital Invoice dashboard'}).evaluate(
        img => img.complete && img.naturalWidth > 0
      ), 'Hero screenshot failed to load at ' + width);
      if (width < 1280) {
        await menu.click();
        await nav.getByRole('link', {name: 'TaxNest ERP', exact: true}).waitFor({state: 'visible'});
        assert.equal(await menu.getAttribute('aria-expanded'), 'true');
        await saveEvidenceScreenshot(page, 'landing-menu-' + width);
        await page.keyboard.press('Escape');
        assert.equal(await menu.getAttribute('aria-expanded'), 'false');
      }
      assert(await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth)) <= width + 2,
        'Landing horizontal overflow at ' + width);
      await saveEvidenceScreenshot(page, 'landing-hero-' + width);
    }

    await page.setViewportSize({width: 768, height: 420});
    await page.goto(baseUrl, {waitUntil: 'networkidle'});
    const nav = page.locator('nav').first();
    await nav.getByRole('button', {name: 'Menu', exact: true}).click();
    const panel = nav.locator('[x-show="mobileOpen"]');
    assert.equal(await panel.evaluate(el => getComputedStyle(el).overflowY), 'auto');
    await panel.getByRole('link', {name: 'Log In', exact: true}).scrollIntoViewIfNeeded();
    const login = await panel.getByRole('link', {name: 'Log In', exact: true}).boundingBox();
    assert(login && login.y + login.height <= 420, 'Short tablet menu cannot reach final action');
    await saveEvidenceScreenshot(page, 'landing-short-tablet-menu');

    await page.setViewportSize({width: 390, height: 900});
    await page.goto(baseUrl + '/#compare', {waitUntil: 'networkidle'});
    const oldComparison = page.locator('#compare');
    await oldComparison.getByRole('link', {name: 'Compare products', exact: true}).click();
    await page.waitForURL('**/compare');
    const comparison = page.getByRole('region', {name: 'Product comparison'});
    assert.equal(await comparison.getByRole('article').count(), 4);
    assert.match(await comparison.innerText(), /Clinics, hospitals, laboratories and pharmacies/);
    assert(await page.evaluate(() => document.documentElement.scrollWidth) <= 392,
      'Product comparison has horizontal overflow at 390px');
  } finally {
    if (original) await page.setViewportSize(original);
  }
}
