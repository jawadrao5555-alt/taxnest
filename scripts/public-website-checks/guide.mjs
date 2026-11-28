import assert from 'node:assert/strict';
export default async function check(page, baseUrl) {
  for (const [path, key] of [['/digital-invoice','di'],['/pos','pra'],['/fbr-pos-landing','fbr'],['/nest-erps','erps']]) {
    assert.equal((await page.goto(baseUrl+path,{waitUntil:'networkidle'})).status(),200,path);
    const link=page.locator(`a[href="/product-guide#${key}"]`);
    await link.scrollIntoViewIfNeeded();await link.click();await page.waitForURL('**/product-guide#'+key);
    assert(await page.locator(`[data-product-guide="${key}"]`).isVisible());
    assert.equal(await page.locator('[data-product-guide]').count(),4);
  }
  for (const [product, key, name] of [
    ['fbr', 'school-standalone', 'School Fees POS'],
    ['fbr', 'hospital-standalone', 'Hospital Collections POS'],
    ['erps', 'hospital-erp', 'Hospital ERP'],
    ['erps', 'lab-erp', 'Lab ERP'],
    ['erps', 'school-erp', 'School ERP'],
  ]) {
    const group = page.locator(`[data-product-guide="${product}"]`);
    await group.getByRole('link', {name, exact: true}).click();
    await page.waitForURL('**/product-guide#' + key);
    const workspace = group.locator('#' + key);
    assert(await workspace.getByRole('heading', {name, exact: true}).isVisible(), `Missing guide section ${key}`);
    assert(await workspace.evaluate(el => el.getBoundingClientRect().top >= 0 && el.getBoundingClientRect().top < innerHeight),
      `Guide anchor ${key} did not scroll to its workspace`);
    if (product === 'erps') {
      assert.equal(await workspace.getByRole('link', {name: `Explore ${name}`, exact: true}).getAttribute('href'), '/nest-erps#' + key);
      const login = key === 'school-erp' ? '/school-erp/login' : '/health/login';
      assert.equal(await workspace.locator('a').last().getAttribute('href'), login);
    }
  }
  const fbr = page.locator('[data-product-guide="fbr"]');
  assert.equal(await fbr.getByRole('link', {name: 'FBR POS login', exact: true}).getAttribute('href'), '/fbr-pos/login');
  assert.match(await fbr.innerText(), /local collection records\. Fiscal submission is blocked/);
  const erps = page.locator('[data-product-guide="erps"]');
  assert.equal(await erps.getByRole('link', {name: 'Check ERP packages and signup', exact: true}).getAttribute('href'), '/nest-erps#pricing');
  assert.equal(await erps.getByRole('link', {name: 'Request an ERP quote', exact: true}).getAttribute('href'), '/contact');
  for (const img of await page.locator('main img').all()) {
    await img.scrollIntoViewIfNeeded();
    await img.evaluate(el=>el.decode());
    assert(await img.evaluate(el=>el.naturalWidth>0));
  }
  const support = page.locator('details').filter({has: page.getByText('Madadgar by product', {exact: true})});
  await support.locator('summary').click();
  assert.match(await support.innerText(), /PRA POS and FBR POS each have Madadgar chat in their signed-in panels/);
  assert.match(await support.innerText(), /Digital Invoice company admins can open the read-only Madadgar reports/);
  const faq=page.getByText('Human support and tutorials',{exact:true});
  await faq.scrollIntoViewIfNeeded();await faq.click();
  assert(await page.locator('details[open] a[href="/tutorials"]').isVisible());
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'product guide overflows viewport');
  await page.locator('details[open] a[href="/tutorials"]').click();await page.waitForURL('**/tutorials');
  const back=page.locator('a[href="/product-guide"]');await back.scrollIntoViewIfNeeded();await back.click();await page.waitForURL('**/product-guide');
}
