import assert from 'node:assert/strict';
export default async function check(page, baseUrl) {
  assert.equal((await page.goto(baseUrl+'/pos',{waitUntil:'networkidle'})).status(),200);
  const picker=page.locator('[data-public-addon-quote]');
  await picker.scrollIntoViewIfNeeded();
  await page.waitForFunction(()=>document.querySelector('[data-public-addon-quote]')?._x_dataStack?.length);
  const read=()=>picker.evaluate(el=>{const d=window.Alpine.$data(el);return {package:d.packagePrice(),addons:d.total(),total:d.grandTotal(),picked:d.picked,better:d.betterPlan()};});
  let quote=await read(); assert.equal(quote.addons,0);assert.equal(quote.total,quote.package);
  const boxes=picker.locator('input[type=checkbox]:enabled');
  if(await boxes.count()!==2)throw new Error('synthetic Business package must offer two add-ons');
  await boxes.nth(0).check();await boxes.nth(1).check();
  quote=await read();assert.equal(quote.picked.length,2);assert.equal(quote.total,quote.package+quote.addons);assert(quote.addons>0);
  const text=await picker.locator('[data-quote-total]').innerText();assert.equal(text,quote.total.toLocaleString('en-US'));
  assert(quote.better && quote.better.price<quote.total,'lower-cost included package should be offered');
  assert.equal(quote.better.name,'Unlimited');
  const upgrade=picker.locator('[data-quote-better] a');
  const cheaper=new URL(await upgrade.getAttribute('href'),baseUrl);assert.equal(cheaper.searchParams.get('plan'),'Unlimited');assert.equal(cheaper.searchParams.getAll('addons[]').length,0);
  const next=picker.locator('.tn-addons__continue');
  const url=new URL(await next.getAttribute('href'),baseUrl);assert.equal(url.searchParams.get('plan'),'Business');assert.equal(url.searchParams.getAll('addons[]').length,2);
  await next.click();await page.waitForURL('**/pos/register?**');
  await page.waitForFunction(()=>document.querySelector('[data-signup-quote-total] span')?.textContent?.trim());
  const total=page.locator('[data-signup-quote-total]');assert((await total.innerText()).includes(quote.total.toLocaleString('en-US')),'registration changed quote');
  await page.goto(cheaper.href,{waitUntil:'networkidle'});
  await page.waitForFunction(()=>document.querySelector('[data-signup-quote-total] span')?.textContent?.trim());
  assert((await page.locator('[data-signup-quote-total]').innerText()).includes(quote.better.price.toLocaleString('en-US')),'included features were charged again');
}
