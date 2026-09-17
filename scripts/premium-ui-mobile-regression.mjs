/**
 * Persistent, credential-free mobile sale journey.
 *
 * The caller owns authentication and navigation.  This module only receives a
 * live Playwright Page, so it is safe to load from a remote test runner
 * without putting credentials in source or environment variables.
 */

export const premiumUiMobileSelectors = Object.freeze({
    saleRoot: '[data-tn-sale-root]',
    productCards: '.tn-product-card.prod-card',
    cartButton: '.tn-left-col .md\\:hidden button',
    cartRail: '.tn-cart-col',
    cartList: '[x-ref="cartList"], .tn-cart-list',
    cartRows: '.tn-cart-line[data-cart-index]',
    menuBack: '.tn-cart-col button.md\\:hidden',
    quantityIncrement: '.tn-qty-increment',
    quantityDecrement: '.tn-qty-decrement',
    paymentButton: '.pay-btn-premium',
    paymentModal: '[x-show="showPayModal"]',
    paymentCancel: '[x-show="showPayModal"] button',
    darkModeToggle: 'button[aria-label*="dark mode" i], button[title*="dark mode" i]',
});

/**
 * Run against a real mobile FBR sale page.
 *
 * Required dependency injection (`auditCartState`, `assertCartState`) keeps
 * `runPremiumUiMobileRegression.toString()` executable by a remote tester.
 * The native runner supplies these from premium-ui-assertions.mjs.
 */
export async function runPremiumUiMobileRegression(page, options = {}) {
    const defaults = {
        productCards: '.tn-product-card.prod-card',
        cartButton: '.tn-left-col .md\\:hidden button',
        cartList: '[x-ref="cartList"], .tn-cart-list',
        cartRows: '.tn-cart-line[data-cart-index]',
        menuBack: '.tn-cart-col button.md\\:hidden',
        quantityIncrement: '.tn-qty-increment',
        quantityDecrement: '.tn-qty-decrement',
        paymentButton: '.pay-btn-premium',
        paymentModal: '[x-show="showPayModal"]',
        darkModeToggle: 'button[aria-label*="dark mode" i], button[title*="dark mode" i]',
    };
    const EXPECTED_BASE_TOTAL = 780;
    const EXPECTED_MUG_PLUS_TOTAL = 1230;
    const {
        auditCartState,
        assertCartState,
        auditVisualPage,
        assertVisualAudit,
        expectedTheme = 'light',
        selectors = defaults,
        wait = (targetPage) => targetPage.waitForTimeout(120),
        onCheckpoint = async () => {},
        reload = true,
        products = [
            { key: 'mug', itemName: 'Synthetic Retail Ceramic Mug', match: /synthetic\s+retail\s+ceramic\s+mug/i, price: 450 },
            { key: 'notebook', itemName: 'Synthetic Retail Notebook', match: /synthetic\s+retail\s+notebook/i, price: 250 },
            { key: 'pen', itemName: 'Synthetic Retail Gel Pen', match: /synthetic\s+retail\s+gel\s+pen/i, price: 80 },
        ],
    } = options;
    if (typeof auditCartState !== 'function' || typeof assertCartState !== 'function') {
        throw new TypeError('mobile regression requires injected cart audit functions');
    }
    if (expectedTheme !== 'light' && expectedTheme !== 'dark') {
        throw new Error(`unsupported mobile regression theme: ${expectedTheme}`);
    }

    const result = {
        viewport: await page.evaluate(() => ({
            width: window.innerWidth,
            height: window.innerHeight,
        })),
        theme: null,
        states: [],
        visualAudits: [],
        actions: [],
        reloaded: false,
        persisted: false,
        reloadPolicy: 'empty-cart-with-safety-backup',
    };
    if (result.viewport.width !== 390 || result.viewport.height !== 844) {
        throw new Error(`mobile regression requires 390x844, got ${result.viewport.width}x${result.viewport.height}`);
    }

    // Playwright's media preference is genuine browser state; the class and
    // computed style below must still be produced by the application's mode
    // control, never by injected CSS or DOM.
    await page.emulateMedia({ colorScheme: expectedTheme });
    const currentTheme = await page.evaluate(expected => ({
        dark: document.documentElement.classList.contains('dark'),
        expected,
    }), expectedTheme);
    if ((expectedTheme === 'dark') !== currentTheme.dark) {
        const toggle = page.locator(selectors.darkModeToggle).first();
        if (!await toggle.count()) throw new Error('dark-mode toggle is not present');
        await toggle.click();
        await wait(page);
    }
    result.theme = await page.evaluate(expected => {
        const style = getComputedStyle(document.documentElement);
        const dark = document.documentElement.classList.contains('dark');
        return {
            expected,
            prefersDark: matchMedia('(prefers-color-scheme: dark)').matches,
            dark,
            colorScheme: style.colorScheme,
        };
    }, expectedTheme);
    if (result.theme.prefersDark !== (expectedTheme === 'dark')
        || result.theme.dark !== (expectedTheme === 'dark')
        || !new RegExp(`\\b${expectedTheme}\\b`, 'i').test(result.theme.colorScheme)) {
        throw new Error(`theme activation failed: ${JSON.stringify(result.theme)}`);
    }

    const inspect = async (label, expected) => {
        const state = await page.evaluate(auditCartState, expected);
        assertCartState(state);
        result.states.push({ label, state });
        if (auditVisualPage && assertVisualAudit && expected.requireVisible !== false) {
            const visual = await page.evaluate(auditVisualPage, {
                expectedTheme,
                requireCategories: [
                    'header-labels', 'header-icons', 'payment-controls',
                    ...(expected.items?.length ? ['cart-names', 'cart-quantities', 'cart-line-totals'] : []),
                ],
            });
            assertVisualAudit(visual, { expectedTheme });
            result.visualAudits.push({ label, audit: visual });
        }
        await onCheckpoint(label, { cart: state });
        return state;
    };
    const rowsFor = (item) => page.locator(selectors.cartRows).filter({ hasText: item.match });
    const expected = quantity => ({
        items: products.map(item => ({
            item_name: item.itemName,
            quantity: quantity[item.key],
            total: item.price * quantity[item.key],
        })),
        total: products.reduce((sum, item) => sum + item.price * quantity[item.key], 0),
    });
    const base = { mug: 1, notebook: 1, pen: 1 };
    if (expected(base).total !== EXPECTED_BASE_TOTAL
        || expected({ ...base, mug: 2 }).total !== EXPECTED_MUG_PLUS_TOTAL) {
        throw new Error('mobile regression price contract is inconsistent');
    }
    const add = async item => {
        let card = page.locator(`${selectors.productCards}:visible`).filter({ hasText: item.match }).first();
        if (!await card.count()) {
            // The product grid may be hidden or paginated; use the template's
            // real search input rather than manufacturing a DOM node.
            const search = page.locator('input[x-ref="searchInput"]').first();
            if (!await search.count()) throw new Error(`product card/search not found: ${item.itemName}`);
            await search.fill(item.itemName);
            await wait(page);
            card = page.locator(`${selectors.productCards}:visible`).filter({ hasText: item.match }).first();
        }
        if (!await card.count()) throw new Error(`product card not found: ${item.itemName}`);
        await card.click();
        await wait(page);
        result.actions.push(`add:${item.itemName}`);
    };

    const initialRows = await page.locator(selectors.cartRows).count();
    if (initialRows !== 0) {
        throw new Error(`mobile regression requires an initially empty cart; found ${initialRows} rendered rows`);
    }
    // Product cards are the live x-for output, not an API or fixture shortcut.
    for (const item of products) await add(item);
    await inspect('menu-after-add', { ...expected(base), requireVisible: false, includeHidden: true });

    const cartButton = page.locator(`${selectors.cartButton}:visible`).first();
    if (!await cartButton.count()) throw new Error('mobile cart button is not visible');
    await cartButton.click();
    await wait(page);
    await inspect('cart-after-open', expected(base));
    const list = page.locator(selectors.cartList).first();
    if (!await list.count()) throw new Error('rendered cart list is not present');
    const scrollTop = await list.evaluate(element => element.scrollTop);
    if (scrollTop !== 0) throw new Error(`cart did not start at scrollTop 0: ${scrollTop}`);

    const back = page.locator(`${selectors.menuBack}:visible`).first();
    if (!await back.count()) throw new Error('mobile cart back-to-menu control is not visible');
    await back.click();
    await wait(page);
    await inspect('menu-after-cart', { ...expected(base), requireVisible: false, includeHidden: true });
    await page.locator(`${selectors.cartButton}:visible`).first().click();
    await wait(page);
    await inspect('cart-reopened', expected(base));

    const mug = rowsFor(products.find(item => item.key === 'mug')).first();
    if (!await mug.count()) throw new Error('rendered Mug row is not present');
    await mug.locator(selectors.quantityIncrement).click();
    await wait(page);
    await inspect('mug-plus', expected({ ...base, mug: 2 }));
    await mug.locator(selectors.quantityDecrement).click();
    await wait(page);
    await inspect('mug-minus', expected(base));
    await mug.locator(selectors.quantityIncrement).click();
    await wait(page);
    await inspect('mug-plus-again', expected({ ...base, mug: 2 }));

    // Payment is opened only to prove reachability/readability.  Never click
    // any cash, card, credit, submit, or confirm control.
    const pay = page.locator(`${selectors.paymentButton}:visible`).first();
    if (!await pay.count()) throw new Error('PAY control is not visible');
    await pay.click();
    await wait(page);
    const payment = page.locator(`${selectors.paymentModal}:visible`).first();
    if (!await payment.count()) throw new Error('payment modal did not open');
    if (auditVisualPage && assertVisualAudit) {
        const paymentVisual = await page.evaluate(auditVisualPage, {
            expectedTheme,
            requireCategories: ['header-labels', 'header-icons', 'payment-controls'],
        });
        assertVisualAudit(paymentVisual, { expectedTheme });
        result.visualAudits.push({ label: 'payment-dialog-open', audit: paymentVisual });
    }
    await onCheckpoint('payment-dialog-open');
    const cancel = payment.locator('button').filter({ hasText: /cancel/i }).first();
    if (!await cancel.count()) throw new Error('payment cancel control is not present');
    await cancel.click();
    await wait(page);
    if (await page.locator(`${selectors.paymentModal}:visible`).count()) {
        throw new Error('payment modal remained open after cancel');
    }
    result.actions.push('payment-open-cancel');

    if (reload) {
        // FBR explicitly starts EMPTY on reload; restoreCart() is deliberately
        // not called by init(). Verify its 400ms-debounced safety backup without
        // introducing auto-restore behavior into this presentation-only change.
        const finalItems = expected({ ...base, mug: 2 }).items;
        await page.waitForFunction(items => {
            const root = document.querySelector('[data-tn-sale-root]');
            const data = window.Alpine?.$data?.(root);
            const saved = JSON.parse(localStorage.getItem(data?.storageKey) || 'null');
            return Array.isArray(saved) && saved.length === items.length
                && items.every((item, index) => saved[index]?.item_name === item.item_name
                    && Number(saved[index]?.quantity) === item.quantity);
        }, finalItems, { timeout: 5000 });
        result.persisted = true;
        result.backupPersisted = true;
        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForFunction(() => {
            const root = document.querySelector('[data-tn-sale-root]');
            return Boolean(root && root._x_dataStack?.length);
        }, undefined, { timeout: 15000 });
        await wait(page);
        result.reloaded = true;
        const reloadedTheme = await page.evaluate(() => {
            const style = getComputedStyle(document.documentElement);
            return {
                prefersDark: matchMedia('(prefers-color-scheme: dark)').matches,
                dark: document.documentElement.classList.contains('dark'),
                colorScheme: style.colorScheme,
            };
        });
        if (reloadedTheme.prefersDark !== (expectedTheme === 'dark')
            || reloadedTheme.dark !== (expectedTheme === 'dark')
            || !new RegExp(`\\b${expectedTheme}\\b`, 'i').test(reloadedTheme.colorScheme)) {
            throw new Error(`theme did not persist after reload: ${JSON.stringify(reloadedTheme)}`);
        }
        result.reloadedTheme = reloadedTheme;
        const reloadedCartButton = page.locator(`${selectors.cartButton}:visible`).first();
        if (!await reloadedCartButton.count()) throw new Error('mobile cart button did not return after reload');
        await reloadedCartButton.click();
        await wait(page);
        await inspect('after-reload-intentionally-empty', { items: [], total: 0 });
        if (await page.locator(selectors.cartRows).count() !== 0) {
            throw new Error('FBR reload must start with an empty cart, not auto-restore the safety backup');
        }
    }
    return result;
}

export const runPremiumUiMobileRegressionSource = runPremiumUiMobileRegression.toString();
