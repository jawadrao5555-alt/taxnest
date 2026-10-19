import assert from 'node:assert/strict';
export default async function check(page, baseUrl) {
  for (const path of ['/', '/pos', '/digital-invoice', '/fbr-pos-landing', '/privacy']) {
    const response = await page.goto(baseUrl + path, {waitUntil:'networkidle'});
    assert.equal(response.status(), 200, path);
    const body = await page.locator('body').innerText();
    assert(!body.includes('14,999 / year'), 'obsolete PRA headline');
    assert(!body.includes('600 KB') && !body.includes('600\u00a0KB'), 'obsolete APK size');
    assert(!body.includes('from Pro upward') && !body.includes('Premium plan'), 'retired package name');
    if (path === '/pos') { assert(body.includes('Bismillah Restaurant')); assert(!body.includes('Sugar Premium')); }
    if (path === '/privacy') assert(body.includes('does not upload your address book'));
    if (path === '/digital-invoice') assert(!body.includes('watched continuously'));
  }
}
