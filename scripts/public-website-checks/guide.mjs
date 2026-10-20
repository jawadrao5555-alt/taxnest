import assert from 'node:assert/strict';
export default async function check(page, baseUrl) {
  for (const [path, key] of [['/digital-invoice','di'],['/pos','pra'],['/fbr-pos-landing','fbr'],['/nest-erps','erps']]) {
    assert.equal((await page.goto(baseUrl+path,{waitUntil:'networkidle'})).status(),200,path);
    const link=page.locator(`a[href="/product-guide#${key}"]`);
    await link.scrollIntoViewIfNeeded();await link.click();await page.waitForURL('**/product-guide#'+key);
    assert(await page.locator(`[data-product-guide="${key}"]`).isVisible());
    assert.equal(await page.locator('[data-product-guide]').count(),4);
  }
  for (const img of await page.locator('main img').all()) {
    await img.scrollIntoViewIfNeeded();
    await img.evaluate(el=>el.decode());
    assert(await img.evaluate(el=>el.naturalWidth>0));
  }
  const faq=page.getByText('Human support and tutorials',{exact:true});
  await faq.scrollIntoViewIfNeeded();await faq.click();
  assert(await page.locator('details[open] a[href="/tutorials"]').isVisible());
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'product guide overflows viewport');
  await page.locator('details[open] a[href="/tutorials"]').click();await page.waitForURL('**/tutorials');
  const back=page.locator('a[href="/product-guide"]');await back.scrollIntoViewIfNeeded();await back.click();await page.waitForURL('**/product-guide');
}
