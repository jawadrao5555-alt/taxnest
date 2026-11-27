import assert from 'node:assert/strict';
import {assertControlContrast} from '../lib/ui-control-contrast.mjs';

export default async function check(page, baseUrl) {
  const mobile = page.viewportSize().width < 768;
  for (const [path, faqCount, loginLabel] of [
    ['/digital-invoice', 5, 'Log In'],
    ['/pos', 5, null],
    ['/fbr-pos-landing', 4, mobile ? 'Sign In to POS' : 'Sign In'],
  ]) {
    assert.equal((await page.goto(baseUrl + path, {waitUntil: 'load'})).status(), 200);
    const questions = page.locator('.tn-public-control[aria-controls]');
    assert.equal(await questions.count(), faqCount, path + ': missing FAQ controls');
    for (const question of await questions.all()) {
      await question.scrollIntoViewIfNeeded();
      const label = path + ' FAQ ' + await question.getAttribute('aria-controls');
      await assertControlContrast(question.locator('.tn-disclosure-indicator'), label + ' indicator', {minimum: 3});
      assert.equal(await question.getAttribute('aria-expanded'), 'false', label);
      await question.focus();
      await assertControlContrast(question, label + ' focus', {minimum: 3, focus: true});
      const answer = page.locator('#' + await question.getAttribute('aria-controls'));
      await page.keyboard.press('Enter');
      await answer.waitFor({state: 'visible'});
      assert.equal(await question.getAttribute('aria-expanded'), 'true', label);
      await page.keyboard.press('Space');
      await answer.waitFor({state: 'hidden'});
      assert.equal(await question.getAttribute('aria-expanded'), 'false', label);
    }

    if (loginLabel) {
      await page.evaluate(() => window.scrollTo({top: 0, behavior: 'instant'}));
      const nav = page.locator('nav').first();
      if (mobile) await nav.getByRole('button', {name: 'Menu', exact: true}).click();
      await nav.getByRole('button', {name: loginLabel, exact: true}).click();
      const close = page.getByRole('button', {name: 'Close login', exact: true});
      await assertControlContrast(close, path + ' login close', {minimum: 3});
      for (const field of await page.locator('.tn-public-login input[placeholder]').all()) {
        await assertControlContrast(field, path + ' login placeholder', {placeholder: true});
      }
      await close.press('Tab');
      await page.keyboard.press('Shift+Tab');
      await assertControlContrast(close, path + ' login focus', {minimum: 3, focus: true});
      await page.keyboard.press('Enter');
      await close.waitFor({state: 'hidden'});
    }
  }
}
