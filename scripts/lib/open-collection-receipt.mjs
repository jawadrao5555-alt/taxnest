export async function openCollectionReceipt(page, button, receiptUrl) {
  // Cover both browser policies across the desktop and mobile runs.
  if (page.viewportSize().width <= 390) {
    await page.evaluate(() => { window.open = () => null; });
    await Promise.all([page.waitForURL(receiptUrl), button.click()]);
    return;
  }

  const [receipt] = await Promise.all([page.waitForEvent('popup'), button.click()]);
  await receipt.waitForURL(receiptUrl);
  await receipt.locator('article.collection-print-receipt').waitFor();
  const url = receipt.url();
  await receipt.close();
  // Continue the existing reprint and ledger assertions in the fixture's main page.
  await page.goto(url, { waitUntil: 'domcontentloaded' });
}
