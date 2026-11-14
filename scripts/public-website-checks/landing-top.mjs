import assert from 'node:assert/strict';

export default async function check(page, baseUrl) {
  const original = page.viewportSize();
  try {
    for (const width of [390, 768, 1024, 1366]) {
      await page.setViewportSize({ width, height: 900 });
      assert.equal((await page.goto(baseUrl, { waitUntil: 'networkidle' })).status(), 200);

      const nav = page.locator('nav');
      const menu = nav.getByRole('button', { name: 'Menu', exact: true });
      const action = nav.getByRole('link', { name: 'Get Started', exact: true });
      const actionBox = await action.boundingBox();
      assert(actionBox && actionBox.x >= 0 && actionBox.x + actionBox.width <= width, `Get Started clipped at ${width}px`);
      assert.equal(await menu.isVisible(), width < 1280);

      if (width < 1280) {
        await menu.click();
        await nav.getByRole('link', { name: 'TaxNest ERP', exact: true }).waitFor({ state: 'visible' });
        await menu.click();
      }

      const vignette = page.locator('[data-hero-vignette]');
      if (await vignette.isVisible()) {
        const screenshot = await vignette.getByRole('img', { name: 'TaxNest Digital Invoice dashboard' }).boundingBox();
        const status = await vignette.getByText('Invoice submitted', { exact: true }).boundingBox();
        assert(screenshot && status && status.y >= screenshot.y + screenshot.height, `Status card covers the dashboard at ${width}px`);
      }
    }
  } finally {
    if (original) await page.setViewportSize(original);
  }
}
