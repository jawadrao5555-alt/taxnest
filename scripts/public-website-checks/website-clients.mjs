import assert from 'node:assert/strict';

export default async function check(page, baseUrl) {
  assert.equal((await page.goto(baseUrl + '/', {waitUntil: 'networkidle'})).status(), 200);
  const grid = page.getByRole('region', {name: 'Our Clients'});
  await grid.scrollIntoViewIfNeeded();

  const cards = grid.getByRole('listitem');
  assert.equal(await cards.count(), 2);
  assert.match(await grid.innerText(), /Synthetic Retail Studio\s+PRA POS/);
  assert.match(await grid.innerText(), /Synthetic Invoice Studio\s+Digital Invoice/);
  assert.equal(await grid.getByText('Synthetic Unpublished Studio').count(), 0);
  const logo = grid.locator('img');
  await logo.evaluate(image => image.decode());
  const size = await cards.first().boundingBox();
  assert(size && size.x >= 0 && size.x + size.width <= page.viewportSize().width);
  const columns = await grid.getByRole('list').evaluate(list => getComputedStyle(list).gridTemplateColumns.split(' ').length);
  assert.equal(columns, page.viewportSize().width === 390 ? 2 : 6);

  assert.equal((await page.goto(baseUrl + '/digital-invoice', {waitUntil: 'networkidle'})).status(), 200);
  assert.equal(await grid.getByRole('listitem').count(), 1);
  assert.match(await grid.innerText(), /Synthetic Invoice Studio\s+Digital Invoice/);
  assert.equal(await grid.locator('img').count(), 0);
}
