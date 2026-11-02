import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {launchLocalBrowser} from '../lib/local-browser.mjs';
const roll = readFileSync(new URL('../../resources/views/pos/partials/day-close-roll.blade.php', import.meta.url), 'utf8').replace(/{{--[\s\S]*?--}}/g, '');
test('thermal report renders a readable continuous roll at desktop/mobile without auto-print', async () => {
  const {browser} = await launchLocalBrowser();
  try {
    for (const width of [1366,390]) for (const count of [4,300]) {
      const page = await browser.newPage({viewport:{width,height:844}});
      await page.setContent(`<html lang="en"><head><style>*{box-sizing:border-box;margin:0;padding:0}table{width:100%;border-collapse:collapse}.ct{text-align:center}.r{text-align:right;white-space:nowrap}</style>${roll}</head><body><div class="toolbar">Print</div><div class="receipt"><h2>PRA sales</h2><table>${Array.from({length:count},(_,i)=>`<tr><td>Complete long fictional product name ${i}</td><td class="ct">12</td><td class="r">1,234.00</td></tr>`).join('')}</table><h2>Local sales</h2><p>END OF REPORT</p></div></body></html>`);
      await page.evaluate(async()=>{await document.fonts.ready; window.dispatchEvent(new Event('beforeprint'));});
      assert.equal(await page.locator('td').first().evaluate(e=>getComputedStyle(e).fontSize),'13px');
      assert.equal(await page.locator('.receipt').evaluate(e=>e.scrollWidth<=e.clientWidth+1),true);
      assert.match(await page.locator('#day-close-roll-size').textContent(), /size: 80mm \d+mm/);
      const pdf = await page.pdf({preferCSSPageSize:true,printBackground:true});
      assert.equal((pdf.toString('latin1').match(/\/Type\s*\/Page\b/g)||[]).length,1);
      assert.equal(await page.locator('.receipt').innerText().then(t=>t.endsWith('END OF REPORT')),true);
      await page.close();
    }
  } finally { await browser.close(); }
});
