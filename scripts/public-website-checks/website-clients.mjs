import assert from 'node:assert/strict';

const excluded = /Synthetic Unpublished Studio|Synthetic Expired Studio/;

async function assertFitsPage(page, label) {
  assert(await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth) <= innerWidth + 1),
    label + ' overflows the viewport');
}

async function verifyReel(page, baseUrl, dismissal, reducedMotion = false) {
  const fresh = await page.context().newPage();
  try {
    if (reducedMotion) await fresh.emulateMedia({reducedMotion: 'reduce'});
    assert.equal((await fresh.goto(baseUrl + '/', {waitUntil: 'networkidle'})).status(), 200);
    const reel = fresh.locator('[data-client-reel]');
    await reel.waitFor({state: 'visible'});
    const box = await reel.boundingBox();
    const {width, height} = fresh.viewportSize();
    assert(box && Math.abs(box.x) <= 1 && Math.abs(box.x + box.width - width) <= 1,
      'Client reel does not reach both screen edges');
    assert(box.height >= 120 && box.height < height / 2, 'Client reel does not fit within a readable center strip');
    assert(Math.abs(box.y + box.height / 2 - height / 2) <= 2, 'Client reel is not vertically centered');
    await assertFitsPage(fresh, 'Homepage with client reel');
    assert.match(await reel.innerText(), /Synthetic Retail Studio/);
    assert.match(await reel.innerText(), /Synthetic Invoice Studio/);
    assert.doesNotMatch(await reel.innerText(), excluded);
    const retailCard = reel.locator('.client-reel__card').filter({hasText: 'Synthetic Retail Studio'}).first();
    assert.equal(await retailCard.locator('.client-reel__product').innerText(), 'PRA POS');
    const invoiceCard = reel.locator('.client-reel__card').filter({hasText: 'Synthetic Invoice Studio'}).first();
    assert.equal(await invoiceCard.locator('.client-reel__product').innerText(), 'Digital Invoice');
    assert(await retailCard.locator('.client-reel__name').evaluate(name => {
      const style = getComputedStyle(name);
      const bounds = name.closest('.client-reel__card').getBoundingClientRect();
      const text = document.createRange();
      text.selectNodeContents(name);
      return style.overflowY === 'visible' && !(parseInt(style.webkitLineClamp, 10) > 0) &&
        Array.from(text.getClientRects()).every(rect =>
          rect.left >= bounds.left - 1 && rect.right <= bounds.right + 1 &&
          rect.top >= bounds.top - 1 && rect.bottom <= bounds.bottom + 1);
    }), 'Client name is clipped in the reel');
    const approvedLogo = reel.locator('img').first();
    await approvedLogo.evaluate(image => image.decode());
    assert(await approvedLogo.evaluate(image => image.naturalWidth > 0), 'Approved client logo did not load in the reel');

    const track = reel.locator('[data-client-reel-track]');
    if (reducedMotion) {
      assert(await track.evaluate(el => {
        const style = getComputedStyle(el);
        return style.animationName === 'none' || style.animationDuration.split(',').every(value => parseFloat(value) === 0);
      }), 'Reduced-motion preference did not stop the client reel');
    } else {
      const initialX = await track.evaluate(element => new DOMMatrixReadOnly(getComputedStyle(element).transform).m41);
      await fresh.waitForFunction(initial => {
        const track = document.querySelector('[data-client-reel-track]');
        return new DOMMatrixReadOnly(getComputedStyle(track).transform).m41 > initial;
      }, initialX);
    }

    if (dismissal === 'outside') {
      await fresh.mouse.click(1, height - 1);
    } else if (dismissal === 'escape') {
      const pause = reel.locator('[data-client-reel-pause]');
      await pause.click();
      assert.equal(await pause.innerText(), 'Resume');
      assert.equal(await track.evaluate(element => getComputedStyle(element).animationPlayState), 'paused');
      await pause.click();
      assert.equal(await pause.innerText(), 'Pause');
      await fresh.keyboard.press('Escape');
      assert(await fresh.locator('[data-client-directory-link]').evaluate(link => link === document.activeElement),
        'Escape did not return focus from the reel to the directory link');
    } else {
      await reel.locator('[data-client-reel-close]').click();
    }
    await reel.waitFor({state: 'hidden'});
    assert.equal(new URL(fresh.url()).pathname, '/', 'Closing the client reel changed the page');
    if (!reducedMotion) {
      await fresh.reload({waitUntil: 'networkidle'});
      assert.equal(await reel.isVisible(), false, 'The dismissed client reel reappeared in the same tab');
    }
  } finally {
    await fresh.close();
  }
}

export default async function check(page, baseUrl) {
  await verifyReel(page, baseUrl, 'outside');
  await verifyReel(page, baseUrl, 'escape');
  await verifyReel(page, baseUrl, 'button', true);

  assert.equal((await page.goto(baseUrl + '/', {waitUntil: 'networkidle'})).status(), 200);
  const homepage = page.locator('[data-home-clients]');
  const reel = page.locator('[data-client-reel]');
  if (await reel.isVisible()) await reel.locator('[data-client-reel-close]').click();
  assert.equal(await homepage.getByRole('listitem').count(), 0, 'Homepage still builds a growing grid of clients');
  const directoryLink = homepage.locator('a[data-client-directory-link]');
  assert.equal(new URL(await directoryLink.getAttribute('href'), baseUrl).href, baseUrl + '/our-clients');
  await directoryLink.click();
  await page.waitForURL('**/our-clients');
  const directory = page.getByRole('main');
  await directory.getByRole('heading', {name: 'Our Clients', exact: true}).waitFor();
  assert.match(await directory.innerText(), /Synthetic Retail Studio/);
  assert.match(await directory.innerText(), /Synthetic Invoice Studio/);
  assert.doesNotMatch(await directory.innerText(), excluded);
  await directory.locator('img[src*="/website-clients/"]').first().evaluate(image => image.decode());
  await assertFitsPage(page, 'Client directory');

  const filters = directory.getByRole('navigation', {name: 'Filter clients by product'});
  for (const product of ['All products', 'Digital Invoice', 'PRA POS', 'FBR POS (IMS)', 'TaxNest ERP']) {
    assert(await filters.getByRole('link', {name: product, exact: true}).isVisible(), `Missing directory filter for ${product}`);
  }
  const digitalInvoice = filters.getByRole('link', {name: 'Digital Invoice', exact: true});
  await digitalInvoice.click();
  await page.waitForURL('**/our-clients?product=di');
  assert.match(await directory.innerText(), /Synthetic Invoice Studio/);
  assert.doesNotMatch(await directory.innerText(), /Synthetic Retail Studio/);
  assert.equal(await directory.locator('img[src*="/website-clients/"]').count(), 0, 'Name-only client unexpectedly has an image');
  assert.equal(await directory.locator('.directory-card-monogram').count(), 1, 'Name-only client is missing its visual fallback');
  assert.equal(await directory.locator('.directory-card-product').innerText(), 'Digital Invoice');
  assert.equal(await digitalInvoice.getAttribute('aria-current'), 'page');
  const pra = filters.getByRole('link', {name: 'PRA POS', exact: true});
  await pra.click();
  await page.waitForURL('**/our-clients?product=pos');
  assert.match(await directory.innerText(), /Synthetic Retail Studio/);
  assert.doesNotMatch(await directory.innerText(), /Synthetic Invoice Studio/);
  assert.doesNotMatch(await directory.innerText(), excluded);
  assert.equal(await pra.getAttribute('aria-current'), 'page');
  await assertFitsPage(page, 'Filtered client directory');

  assert.equal((await page.goto(baseUrl + '/digital-invoice', {waitUntil: 'networkidle'})).status(), 200);
  const productClients = page.getByRole('region', {name: 'Our Clients'});
  assert.equal(await productClients.getByRole('listitem').count(), 1);
  assert.match(await productClients.innerText(), /Synthetic Invoice Studio\s+Digital Invoice/);
  assert.equal(await productClients.locator('img').count(), 0);
}
