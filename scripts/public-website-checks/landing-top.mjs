import assert from 'node:assert/strict';
import {saveEvidenceScreenshot} from '../lib/local-browser.mjs';

export default async function check(page, baseUrl) {
  const original=page.viewportSize();
  try {
    for(const width of [390,768,1024,1366]) {
      await page.setViewportSize({width,height:900});
      assert.equal((await page.goto(baseUrl,{waitUntil:'networkidle'})).status(),200);
      const nav=page.locator('nav').first();
      const menu=nav.getByRole('button',{name:'Menu',exact:true});
      const action=nav.getByRole('link',{name:'Get Started',exact:true});
      const box=await action.boundingBox();
      assert(box&&box.x>=0&&box.x+box.width<=width,'Get Started clipped at '+width);
      assert.equal(await menu.isVisible(),width<1280);
      assert(await page.locator('body').innerText().then(x=>x.includes('ERP is available on request.')));
      if(width<1280) {
        await menu.click();
        await nav.getByRole('link',{name:'TaxNest ERP',exact:true}).waitFor({state:'visible'});
        assert.equal(await menu.getAttribute('aria-expanded'),'true');
        await saveEvidenceScreenshot(page,'landing-menu-'+width);
        await menu.click();
        assert.equal(await menu.getAttribute('aria-expanded'),'false');
      }
      const vignette=page.locator('[data-hero-vignette]');
      if(await vignette.isVisible()) {
        const image=await vignette.getByRole('img',{name:'TaxNest Digital Invoice dashboard'}).boundingBox();
        const status=await vignette.getByText('Invoice submitted',{exact:true}).boundingBox();
        assert(image&&status&&status.y>=image.y+image.height,'Status card covers screenshot at '+width);
      }
      assert(await page.evaluate(()=>Math.max(document.documentElement.scrollWidth,document.body.scrollWidth))<=width+2,'Landing horizontal overflow at '+width);
      await saveEvidenceScreenshot(page,'landing-hero-'+width);
    }
    await page.setViewportSize({width:768,height:420});
    await page.goto(baseUrl,{waitUntil:'networkidle'});
    const nav=page.locator('nav').first();
    await nav.getByRole('button',{name:'Menu',exact:true}).click();
    const panel=nav.locator('[x-show="mobileOpen"]').last();
    assert.equal(await panel.evaluate(el=>getComputedStyle(el).overflowY),'auto');
    await panel.getByRole('link',{name:'Log In',exact:true}).scrollIntoViewIfNeeded();
    const login=await panel.getByRole('link',{name:'Log In',exact:true}).boundingBox();
    assert(login&&login.y+login.height<=420,'Short tablet menu cannot reach final action');
    await saveEvidenceScreenshot(page,'landing-short-tablet-menu');
  } finally { if(original)await page.setViewportSize(original); }
}
