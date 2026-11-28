import assert from 'node:assert/strict';
import {assertControlContrast} from './ui-control-contrast.mjs';

const palettes = ['indigo', 'emerald', 'cyan', 'rose', 'amber', 'purple'];

export async function verifyAdminControlContrast(page, baseUrl, proofCompanyId) {
  const saved = await page.evaluate(() => ({theme: getAdminTheme(), dark: document.documentElement.classList.contains('dark')}));
  const apply = ({theme, dark}) => {
    applyAdminTheme(theme);
    document.documentElement.classList.toggle('dark', dark);
  };
  const check = async (controls, hoverControl = null) => {
    for (const theme of palettes) {
      for (const dark of [false, true]) {
        await page.evaluate(apply, {theme, dark});
        for (const [control, label] of controls) {
          await assertControlContrast(control, `${theme}/${dark ? 'dark' : 'light'} ${label}`);
        }
        if (hoverControl) {
          await hoverControl.hover();
          await assertControlContrast(hoverControl, `${theme}/${dark ? 'dark' : 'light'} hover`);
          await page.mouse.move(1, 1);
        }
      }
    }
  };

  try {
    assert.equal((await page.goto(baseUrl + '/admin/dashboard')).status(), 200);
    const finance = page.getByRole('region', {name: 'ERP invoicing and collections'});
    await check([
      [finance.getByRole('heading', {name: 'ERP invoicing and collections'}), 'ERP collections heading'],
      [finance.getByRole('heading', {name: 'Healthcare company billing'}), 'Healthcare billing heading'],
      [finance.locator('.tn-dashboard-detail-filters label').first(), 'ERP date label'],
      [finance.getByRole('link', {name: 'View ERP company reports'}), 'ERP company reports link'],
    ]);

    assert.equal((await page.goto(baseUrl + '/admin/plans')).status(), 200);
    const tabs = page.locator('.tn-admin-plan-tabs button');
    await tabs.first().waitFor({state: 'visible'});
    assert.equal(await tabs.count(), 4, 'expected four product plan tabs');
    const tabControls = await Promise.all((await tabs.all()).map(async tab => [tab, await tab.innerText()]));
    const edit = page.getByRole('button', {name: 'Edit', exact: true}).first();
    await check([...tabControls, [edit, 'Edit plan']], edit);
    await edit.press('Tab');
    await page.keyboard.press('Shift+Tab');
    await assertControlContrast(edit, 'Edit plan focus', {minimum: 3, focus: true});
    await page.keyboard.press('Enter');
    const cancel = page.getByRole('button', {name: 'Cancel', exact: true}).first();
    await cancel.waitFor({state: 'visible'});
    assert.equal(await cancel.getAttribute('aria-expanded'), 'true');
    await page.keyboard.press('Enter');
    await edit.waitFor({state: 'visible'});
    assert.equal(await edit.getAttribute('aria-expanded'), 'false');

    for (const tab of await tabs.all()) {
      await tab.click();
      assert.equal(await tab.getAttribute('aria-pressed'), 'true');
      await assertControlContrast(tab, 'selected plan tab');
    }

    assert.equal((await page.goto(baseUrl + '/admin/settings')).status(), 200);
    const settingsActions = ['Save Settings', 'Save Email Settings', 'Save WhatsApp Settings', 'Send Test Email']
      .map(label => [page.getByRole('button', {name: label, exact: true}), label]);
    const smtpLabel = page.locator('label').filter({has: page.locator('input[name="smtp_enabled"]')});
    await check([...settingsActions, [smtpLabel, 'SMTP settings label']], settingsActions.at(-1)[0]);

    assert.equal((await page.goto(baseUrl + '/admin/package-requests')).status(), 200);
    await check([[page.getByRole('heading', {name: 'Signup package requests'}), 'Signup package requests heading']]);

    assert.equal((await page.goto(baseUrl + '/admin/subscriptions')).status(), 200);
    const subscriptionFilters = page.locator('form[action$="/admin/subscriptions"]');
    await check([
      [subscriptionFilters.locator('label').first(), 'Subscription product filter label'],
      [subscriptionFilters.getByRole('button', {name: 'Apply filters'}), 'Apply subscription filters'],
    ]);

    assert.equal((await page.goto(baseUrl + '/admin/payment-proofs?status=pending&company_id=' + proofCompanyId)).status(), 200);
    await page.getByRole('button', {name: 'Approve', exact: true}).click();
    const approve = page.getByRole('button', {name: 'Approve & Unlock', exact: true});
    await check([[approve, 'Approve & Unlock']], approve);
    await page.getByRole('button', {name: 'Reject', exact: true}).click();
    const reject = page.getByRole('button', {name: 'Confirm Reject', exact: true});
    await check([[reject, 'Confirm Reject']], reject);
  } finally {
    await page.evaluate(apply, saved);
  }
}
