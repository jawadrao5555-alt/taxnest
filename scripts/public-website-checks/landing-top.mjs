import assert from 'node:assert/strict';
import {saveEvidenceScreenshot} from '../lib/local-browser.mjs';

async function assertContrast(page, locator, {keyboardFocus = false} = {}) {
  if (keyboardFocus) {
    await locator.focus();
    await page.keyboard.press('Tab');
    await page.keyboard.press('Shift+Tab');
  }

  const contrast = await locator.evaluate((element, keyboardFocus) => {
    const style = getComputedStyle(element);
    if (keyboardFocus && (!element.matches(':focus-visible') || style.outlineStyle === 'none' || parseFloat(style.outlineWidth) < 2)) return 0;

    const channels = color => color.match(/[\d.]+/g).map(Number);
    const luminance = rgb => rgb.slice(0, 3)
      .map(value => value / 255)
      .map(value => value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4)
      .reduce((total, value, index) => total + value * [.2126, .7152, .0722][index], 0);

    // The outline is outside the element, on the first opaque ancestor surface.
    const color = channels(keyboardFocus ? style.outlineColor : style.color);
    const alpha = color[3] ?? 1;
    for (let parent = keyboardFocus ? element.parentElement : element; parent; parent = parent.parentElement) {
      const background = channels(getComputedStyle(parent).backgroundColor);
      if ((background[3] ?? 1) < 1) continue;
      const foreground = luminance(color.slice(0, 3).map((value, index) => value * alpha + background[index] * (1 - alpha)));
      const surface = luminance(background);
      return (Math.max(foreground, surface) + .05) / (Math.min(foreground, surface) + .05);
    }
    return 0;
  }, keyboardFocus);
  const minimum = keyboardFocus ? 3 : 4.5;
  const state = keyboardFocus ? 'Keyboard focus' : 'Text';
  assert(contrast >= minimum, `${state} contrast ${contrast.toFixed(2)}:1 is too low for ${await locator.innerText()}`);
}

export default async function check(page, baseUrl) {
  const original = page.viewportSize();
  try {
    for (const width of [390, 768, 1024, 1366]) {
      await page.setViewportSize({width, height: 900});
      assert.equal((await page.goto(baseUrl, {waitUntil: 'networkidle'})).status(), 200);
      const reel = page.locator('[data-client-reel]');
      if (await reel.isVisible()) await reel.locator('[data-client-reel-close]').click();
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
      if (width === 390 || width === 1366) {
        await assertContrast(page, action, {keyboardFocus: true});
        await assertContrast(page, products.locator('a.public-card').first(), {keyboardFocus: true});
        await assertContrast(page, page.getByRole('contentinfo').getByText('FBR & PRA Compliant · Built in Pakistan'));
      }
      if (width < 1280) {
        await menu.focus();
        await page.keyboard.press('Enter');
        const erp = nav.getByRole('link', {name: 'TaxNest ERP', exact: true});
        await erp.waitFor({state: 'visible'});
        assert.equal(await menu.getAttribute('aria-expanded'), 'true');
        await erp.focus();
        await saveEvidenceScreenshot(page, 'landing-menu-' + width);
        await page.keyboard.press('Escape');
        assert.equal(await menu.getAttribute('aria-expanded'), 'false');
        assert(await menu.evaluate(button => button === document.activeElement), 'Escape did not restore keyboard focus to Menu');
      }
      await page.evaluate(() => window.scrollTo({top: 0, behavior: 'instant'}));
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
    await assertContrast(page, comparison.getByRole('link', {name: 'Explore TaxNest Digital Invoice', exact: true}), {keyboardFocus: true});
    await page.setViewportSize({width: 1366, height: 900});
    await assertContrast(page, comparison.getByRole('link', {name: 'View TaxNest Digital Invoice pricing', exact: true}), {keyboardFocus: true});
  } finally {
    if (original) await page.setViewportSize(original);
  }
}
