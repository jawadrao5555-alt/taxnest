/**
 * Browser-side presentation checks for the premium shell.
 *
 * This module deliberately does not launch a browser.  A Playwright caller can
 * use the main check as:
 *
 *   const report = await page.evaluate(auditPremiumVisualPage);
 *   assertPremiumVisualAudit(report);
 *
 * `auditPremiumVisualPage` contains all of its browser code in the function
 * body, so passing the function itself to page.evaluate is safe (there are no
 * module-scope helper dependencies).
 */

/**
 * Inspect the currently rendered page without changing it.
 *
 * @param {{ requireCategories?: string[] }} [options]
 * @returns {object}
 */
export const auditPremiumVisualPage = function auditPremiumVisualPage(options = {}) {
    const startedAt = Date.now();
    const viewport = {
        width: window.innerWidth,
        height: window.innerHeight,
        devicePixelRatio: window.devicePixelRatio || 1,
    };
    const viewportClass = viewport.width <= 600
        ? 'mobile'
        : viewport.width <= 1024
            ? 'tablet'
            : 'desktop';
    const requiredCategories = Array.isArray(options.requireCategories)
        ? options.requireCategories
        : [];

    const clamp = value => Math.max(0, Math.min(1, value));
    const finite = (value, fallback = 0) => Number.isFinite(value) ? value : fallback;
    const round = value => Math.round(finite(value) * 1000) / 1000;
    const cssEscape = value => {
        try {
            return CSS.escape(String(value));
        } catch {
            return String(value).replace(/[^a-z0-9_-]/gi, '');
        }
    };
    const parseColor = (raw) => {
        const value = String(raw || '').trim().toLowerCase();
        if (!value) return null;
        if (value === 'transparent') return [0, 0, 0, 0];
        if (value.startsWith('#')) {
            const hex = value.slice(1);
            if (/^[0-9a-f]{3,4}$/i.test(hex)) {
                const expanded = hex.split('').map(char => char + char).join('');
                return [
                    parseInt(expanded.slice(0, 2), 16) / 255,
                    parseInt(expanded.slice(2, 4), 16) / 255,
                    parseInt(expanded.slice(4, 6), 16) / 255,
                    hex.length === 4 ? parseInt(expanded.slice(6, 8), 16) / 255 : 1,
                ];
            }
            if (/^[0-9a-f]{6,8}$/i.test(hex)) {
                return [
                    parseInt(hex.slice(0, 2), 16) / 255,
                    parseInt(hex.slice(2, 4), 16) / 255,
                    parseInt(hex.slice(4, 6), 16) / 255,
                    hex.length === 8 ? parseInt(hex.slice(6, 8), 16) / 255 : 1,
                ];
            }
        }
        const match = value.match(/^rgba?\(([^)]+)\)$/);
        if (match) {
            const channels = match[1].replace(/\//g, ',').split(/[\s,]+/).filter(Boolean);
            const channel = item => item?.endsWith('%')
                ? parseFloat(item) / 100
                : parseFloat(item) / 255;
            const rgb = channels.slice(0, 3).map(channel);
            const alpha = channels[3] == null
                ? 1
                : channels[3].endsWith('%') ? parseFloat(channels[3]) / 100 : parseFloat(channels[3]);
            if (rgb.length !== 3 || rgb.some(item => !Number.isFinite(item)) || !Number.isFinite(alpha)) return null;
            return [
                clamp(rgb[0]),
                clamp(rgb[1]),
                clamp(rgb[2]),
                clamp(alpha),
            ];
        }
        // Chromium serializes color-mix() results as color(srgb ...), even
        // though the source stylesheet may contain a different color space.
        // The channels in color(srgb) use the normalized CSS sRGB range.
        const srgb = value.match(/^color\(\s*srgb\s+(.+?)\s*\)$/);
        if (srgb) {
            const channels = srgb[1].replace(/\//g, ' / ').split(/\s+/).filter(Boolean);
            const slash = channels.indexOf('/');
            const rgb = slash === -1 ? channels : channels.slice(0, slash);
            const alpha = slash === -1 ? null : channels[slash + 1];
            const component = channel => channel?.endsWith('%') ? parseFloat(channel) / 100 : parseFloat(channel);
            if (rgb.length !== 3 || rgb.some(channel => channel === 'none' || !Number.isFinite(component(channel)))) return null;
            const parsedAlpha = alpha == null ? 1 : alpha.endsWith('%') ? parseFloat(alpha) / 100 : parseFloat(alpha);
            if (!Number.isFinite(parsedAlpha)) return null;
            return [
                clamp(component(rgb[0])),
                clamp(component(rgb[1])),
                clamp(component(rgb[2])),
                clamp(parsedAlpha),
            ];
        }
        return null;
    };
    const splitCssList = value => {
        const result = [];
        let depth = 0;
        let start = 0;
        for (let index = 0; index < value.length; index++) {
            if (value[index] === '(') depth++;
            else if (value[index] === ')') depth = Math.max(0, depth - 1);
            else if (value[index] === ',' && depth === 0) {
                result.push(value.slice(start, index).trim());
                start = index + 1;
            }
        }
        result.push(value.slice(start).trim());
        return result.filter(Boolean);
    };
    const readColorToken = value => {
        const source = String(value || '').trim();
        if (!source) return null;
        if (/^(?:transparent|black|white|red|green|blue)\b/i.test(source)) {
            const name = source.match(/^[a-z]+/i)[0].toLowerCase();
            const named = {
                transparent: [0, 0, 0, 0],
                black: [0, 0, 0, 1],
                white: [1, 1, 1, 1],
                red: [1, 0, 0, 1],
                green: [0, 0.5019608, 0, 1],
                blue: [0, 0, 1, 1],
            };
            return named[name] ? { color: named[name], remainder: source.slice(name.length).trim() } : null;
        }
        if (source[0] === '#') {
            const match = source.match(/^#[0-9a-f]{3,8}\b/i);
            if (!match) return null;
            return { color: parseColor(match[0]), remainder: source.slice(match[0].length).trim() };
        }
        const functionStart = source.match(/^(?:rgba?|color)\(/i);
        if (!functionStart) return null;
        let depth = 0;
        let end = -1;
        for (let index = functionStart[0].length - 1; index < source.length; index++) {
            if (source[index] === '(') depth++;
            else if (source[index] === ')') {
                depth--;
                if (depth === 0) {
                    end = index + 1;
                    break;
                }
            }
        }
        if (end < 0) return null;
        return { color: parseColor(source.slice(0, end)), remainder: source.slice(end).trim() };
    };
    const mixColors = (first, second, amount) => [
        first[0] + (second[0] - first[0]) * amount,
        first[1] + (second[1] - first[1]) * amount,
        first[2] + (second[2] - first[2]) * amount,
        first[3] + (second[3] - first[3]) * amount,
    ];
    const gradientColors = image => {
        const match = String(image || '').trim().match(/^linear-gradient\((.*)\)$/i);
        if (!match) return null;
        const parts = splitCssList(match[1]);
        const stops = [];
        for (const part of parts) {
            const token = readColorToken(part);
            if (token?.color) stops.push(token.color);
        }
        // A direction/angle is allowed as the first part. Two actual colour
        // stops are the minimum needed to make a useful conservative sample.
        if (stops.length < 2 || stops.some(color => !color)) return null;
        const samples = [];
        for (let index = 0; index < stops.length - 1; index++) {
            for (let sample = 0; sample <= 8; sample++) {
                const amount = sample / 8;
                samples.push(mixColors(stops[index], stops[index + 1], amount));
            }
        }
        return samples;
    };
    const blend = (foreground, background) => {
        const alpha = clamp(finite(foreground[3], 1));
        const inverse = 1 - alpha;
        return [
            foreground[0] * alpha + background[0] * inverse,
            foreground[1] * alpha + background[1] * inverse,
            foreground[2] * alpha + background[2] * inverse,
            alpha + background[3] * inverse,
        ];
    };
    const opaque = color => [clamp(color[0]), clamp(color[1]), clamp(color[2]), 1];
    const luminance = color => {
        const channel = value => value <= 0.03928 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4);
        return 0.2126 * channel(color[0]) + 0.7152 * channel(color[1]) + 0.0722 * channel(color[2]);
    };
    const contrast = (foreground, background) => {
        const lighter = Math.max(luminance(foreground), luminance(background));
        const darker = Math.min(luminance(foreground), luminance(background));
        return round((lighter + 0.05) / (darker + 0.05));
    };
    const isVisible = element => {
        return isStyleVisible(element) && isIntersecting(element);
    };
    const isStyleVisible = element => {
        if (!element || !(element instanceof Element)) return false;
        const style = getComputedStyle(element);
        if (style.display === 'none' || style.visibility === 'hidden' || parseFloat(style.opacity || '1') <= 0) return false;
        const rect = element.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0;
    };
    const isIntersecting = element => {
        if (!element || !(element instanceof Element)) return false;
        const rect = element.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0 && rect.bottom > 0 && rect.right > 0
            && rect.left < viewport.width && rect.top < viewport.height;
    };
    const firstMatching = selectors => {
        for (const selector of selectors) {
            try {
                const found = document.querySelector(selector);
                if (found) return found;
            } catch { /* a caller-supplied selector cannot break the audit */ }
        }
        return null;
    };
    const allMatching = selectors => {
        const result = [];
        const seen = new Set();
        for (const selector of selectors) {
            let matches = [];
            try { matches = [...document.querySelectorAll(selector)]; } catch { /* ignore invalid selector */ }
            for (const match of matches) {
                if (!seen.has(match)) {
                    seen.add(match);
                    result.push(match);
                }
            }
        }
        return result;
    };
    const containsAny = (value, words) => words.some(word => String(value || '').toLowerCase().includes(word));
    const hasExactClassToken = (element, tokens) => String(element?.className || '')
        .split(/\s+/)
        .some(token => tokens.includes(token));
    const describe = element => {
        const text = (element.innerText || element.textContent || '').replace(/\s+/g, ' ').trim();
        const aria = element.getAttribute('aria-label') || element.getAttribute('title') || '';
        const id = element.id ? `#${cssEscape(element.id)}` : '';
        const classes = typeof element.className === 'string'
            ? element.className.trim().split(/\s+/).filter(Boolean).slice(0, 2).map(cssEscape).join('.')
            : '';
        return {
            tag: element.tagName.toLowerCase(),
            selector: `${element.tagName.toLowerCase()}${id}${classes ? `.${classes}` : ''}`,
            text: text.slice(0, 160),
            ariaLabel: String(aria).slice(0, 160),
        };
    };
    const nativeDisabled = element => Boolean(element.disabled === true || element.hasAttribute('disabled'));
    const disabledLike = element => nativeDisabled(element)
        || element.getAttribute('aria-disabled') === 'true'
        || element.matches(':disabled')
        || hasExactClassToken(element, ['disabled', 'is-disabled']);
    const deliberateDisabledState = element => {
        const style = getComputedStyle(element);
        return parseFloat(style.opacity || '1') < 0.99
            || style.cursor === 'not-allowed'
            || style.filter !== 'none';
    };
    const effectiveBackground = element => {
        // Start with the browser canvas (white if the page has no opaque root
        // background), then paint transparent ancestors from root to target.
        let variants = [{ color: [1, 1, 1, 1], transparentAncestors: 0 }];
        const stack = [];
        let node = element;
        const unresolved = [];
        while (node && node instanceof Element) {
            stack.push(node);
            node = node.parentElement;
        }
        for (const ancestor of stack.reverse()) {
            const style = getComputedStyle(ancestor);
            let layers = [];
            if (style.backgroundImage && style.backgroundImage !== 'none') {
                for (const image of splitCssList(style.backgroundImage)) {
                    const samples = gradientColors(image);
                    if (!samples) {
                        unresolved.push(`unsupported-background-image:${image.slice(0, 120)}`);
                    } else {
                        layers.push(samples);
                    }
                }
            }
            const color = parseColor(style.backgroundColor);
            if (!color) {
                unresolved.push(`unparseable-background-color:${String(style.backgroundColor || '').slice(0, 120)}`);
            }
            const opacity = clamp(parseFloat(style.opacity || '1'));
            // An opaque child background fully covers every ancestor paint.
            // Do not let an off-screen/hidden-underneath gradient make a
            // visible foreground unresolved when this element has a known
            // opaque background of its own.
            if (style.backgroundImage === 'none' && color && color[3] >= 0.999 && opacity >= 0.999) {
                unresolved.length = 0;
            }
            const next = [];
            for (const variant of variants) {
                const baseTransparentAncestors = variant.transparentAncestors + (color && color[3] < 0.999 ? 1 : 0);
                if (!layers.length) {
                    next.push({
                        color: color
                            ? blend([color[0], color[1], color[2], color[3] * opacity], variant.color)
                            : variant.color,
                        transparentAncestors: baseTransparentAncestors,
                    });
                    continue;
                }
                // The first CSS image is the top layer. Every sampled
                // linear-gradient stop is kept so the minimum contrast is
                // never hidden by averaging a gradient into one flat colour.
                let painted = [{ color: color || [0, 0, 0, 0] }];
                for (let layerIndex = layers.length - 1; layerIndex >= 0; layerIndex--) {
                    const layerPainted = [];
                    for (const existing of painted) {
                        for (const sample of layers[layerIndex]) {
                            layerPainted.push({
                                color: blend(sample, existing.color),
                            });
                        }
                    }
                    if (layerPainted.length > 256) unresolved.push('too-many-background-samples');
                    painted = layerPainted.slice(0, 256);
                }
                next.push(...painted.map(existing => ({
                    color: blend([existing.color[0], existing.color[1], existing.color[2], existing.color[3] * opacity], variant.color),
                    transparentAncestors: baseTransparentAncestors,
                })));
            }
            variants = next.slice(0, 256);
        }
        return { variants: variants.map(variant => ({
            color: opaque(variant.color),
            transparentAncestors: variant.transparentAncestors,
        })), unresolved };
    };
    const foregroundFor = (element, icon = false) => {
        const style = getComputedStyle(element);
        let raw = style.color;
        if (icon && element instanceof SVGElement) {
            const fill = style.fill;
            const stroke = style.stroke;
            if (fill && fill !== 'none' && fill !== 'currentcolor') raw = fill;
            else if (stroke && stroke !== 'none' && stroke !== 'currentcolor') raw = stroke;
        }
        const color = parseColor(raw);
        return color ? { color, unresolved: [] } : {
            color: null,
            unresolved: [`unparseable-foreground-color:${String(raw || '').slice(0, 120)}`],
        };
    };
    const auditOne = (element, category, kind = 'text') => {
        const background = effectiveBackground(element);
        const foregroundResult = foregroundFor(element, kind === 'icon');
        const foreground = foregroundResult.color;
        const ancestors = [];
        let ancestorOpacity = 1;
        let ancestor = element;
        while (ancestor && ancestor instanceof Element) {
            const opacity = parseFloat(getComputedStyle(ancestor).opacity || '1');
            ancestorOpacity *= clamp(opacity);
            if (opacity < 0.999) ancestors.push(round(opacity));
            ancestor = ancestor.parentElement;
        }
        const unresolved = [...background.unresolved, ...foregroundResult.unresolved];
        const backgroundVariants = background.variants || [];
        const ratios = [];
        const renderedForegrounds = [];
        if (foreground && backgroundVariants.length) {
            for (const variant of backgroundVariants) {
                const compositedForeground = [
                    foreground[0],
                    foreground[1],
                    foreground[2],
                    foreground[3] * ancestorOpacity,
                ];
                const renderedForeground = opaque(blend(compositedForeground, variant.color));
                renderedForegrounds.push({ foreground: renderedForeground, background: variant.color });
                ratios.push(contrast(renderedForeground, variant.color));
            }
        }
        const minimumIndex = ratios.length ? ratios.indexOf(Math.min(...ratios)) : -1;
        const selected = minimumIndex >= 0
            ? renderedForegrounds[minimumIndex]
            : { foreground: null, background: null };
        const ratio = minimumIndex >= 0 ? ratios[minimumIndex] : null;
        const selectedVariant = minimumIndex >= 0 ? backgroundVariants[minimumIndex] : null;
        const disabled = disabledLike(element);
        const threshold = kind === 'icon' ? 3 : 4.5;
        const failures = [];
        if (unresolved.length) failures.push('unresolved-foreground-or-background');
        if (disabled && !nativeDisabled(element)) failures.push('disabled-control-must-have-native-disabled-attribute');
        if (disabled && !deliberateDisabledState(element)) failures.push('disabled-control-needs-deliberate-visible-state');
        if (!disabled && ratio != null && ratio < threshold) failures.push(`contrast-below-${threshold}`);
        const record = {
            ...describe(element),
            category,
            kind,
            visible: isVisible(element),
            contrastRatio: ratio,
            contrastRatios: ratios.map(round),
            requiredContrastRatio: threshold,
            foreground: selected.foreground ? selected.foreground.slice(0, 3).map(round) : null,
            background: selected.background ? selected.background.slice(0, 3).map(round) : null,
            sourceForeground: foreground ? foreground.map(round) : null,
            transparentAncestors: selectedVariant?.transparentAncestors || 0,
            opacityAncestors: ancestors.length,
            explicitPaymentControl: element.matches
                && element.matches('[data-payment], [data-payment-method], [data-checkout], [name*="payment" i], button[type="submit"]'),
            unresolved,
            unsupportedBackgroundImage: unresolved.some(item => item.startsWith('unsupported-background-image:')),
            disabled,
            nativeDisabledAttribute: nativeDisabled(element),
            deliberateDisabledState: disabled ? deliberateDisabledState(element) : false,
            pass: !unresolved.length && (disabled ? nativeDisabled(element) && deliberateDisabledState(element) : ratio >= threshold),
            failures,
        };
        record.failure = failures[0] || null;
        return record;
    };
    const textLeaves = elements => {
        const result = [];
        const seen = new Set();
        for (const element of elements) {
            if (!isVisible(element)) continue;
            const descendants = [...element.querySelectorAll('*')].filter(child => isVisible(child));
            const leaves = descendants.filter(child => {
                const text = (child.childNodes ? [...child.childNodes]
                    .filter(node => node.nodeType === Node.TEXT_NODE)
                    .map(node => node.textContent).join('') : '').trim();
                return text || (child.children.length === 0 && (child.textContent || '').trim());
            });
            const candidates = leaves.length ? leaves : [element];
            for (const candidate of candidates) {
                if (!seen.has(candidate) && ((candidate.innerText || candidate.textContent || '').trim() || candidate.getAttribute('aria-label'))) {
                    seen.add(candidate);
                    result.push(candidate);
                }
            }
        }
        return result;
    };
    const iconCandidates = elements => {
        const result = [];
        const seen = new Set();
        for (const element of elements) {
            for (const candidate of [element, ...element.querySelectorAll('svg, [role="img"], img')]) {
                if (!seen.has(candidate) && isVisible(candidate)) {
                    seen.add(candidate);
                    result.push(candidate);
                }
            }
        }
        return result;
    };
    const collectCategory = (category, selectors, { icons = false, controls = false } = {}) => {
        const roots = allMatching(selectors);
        const elements = icons ? iconCandidates(roots) : controls ? roots.filter(isVisible) : textLeaves(roots);
        const records = elements.slice(0, 100).map(element => auditOne(element, category, icons ? 'icon' : 'text'));
        return {
            category,
            candidateCount: roots.length,
            visibleCount: elements.length,
            records,
            pass: records.every(record => record.pass),
            status: roots.length ? (records.length ? 'checked' : 'not-visible') : 'not-found',
        };
    };

    const headerRoots = allMatching(['header', '[role="banner"]', '[data-premium-header]', '.tn-premium-header']);
    const header = headerRoots.find(isVisible) || null;
    const headerLabels = collectCategory('header-labels', ['header', '[role="banner"]', '[data-premium-header]', '.tn-premium-header']);
    const headerIcons = collectCategory('header-icons', ['header svg', '[role="banner"] svg', '[data-premium-header] svg', 'header [role="img"]'], { icons: true });
    const grandTotal = collectCategory('grand-total', [
        '[data-grand-total]', '[data-cart-total]', '[data-total]', '.grand-total', '.cart-total',
        '[class*="grand-total"]', '[class*="cart-total"]',
    ]);
    const productNames = collectCategory('product-names', [
        '.tn-product-card [x-text="item.name"]', '.tn-cart-line [x-text="item.item_name"]',
        '[data-product-name]', '.product-name', '[class*="product-name"]', '[data-cart-item] [data-name]',
    ]);
    const quantityControls = collectCategory('quantity-controls', [
        '.tn-qty-value', '.tn-qty-decrement', '.tn-qty-increment',
        '[data-quantity]', 'input[type="number"]', 'button[aria-label*="quantity" i]',
        'button[aria-label*="increment" i]', 'button[aria-label*="decrement" i]',
    ], { controls: true });
    const paymentControls = collectCategory('payment-controls', [
        '[data-payment]', '[data-payment-method]', '[data-checkout]', '[name*="payment" i]',
        'button[type="submit"]', 'button',
    ], { controls: true });
    // Buttons are broad by design, but only payment-looking buttons are
    // included unless the page has explicit payment markers.
    paymentControls.records = paymentControls.records.filter(record => {
        const explicit = record.explicitPaymentControl;
        return explicit || containsAny(`${record.text} ${record.ariaLabel}`, ['pay', 'checkout', 'place order', 'cash', 'card', 'submit', 'confirm']);
    });
    paymentControls.visibleCount = paymentControls.records.length;
    paymentControls.pass = paymentControls.records.every(record => record.pass);
    paymentControls.status = paymentControls.records.length ? 'checked' : paymentControls.candidateCount ? 'not-payment-looking' : 'not-found';

    const categories = { headerLabels, headerIcons, grandTotal, productNames, quantityControls, paymentControls };
    const paymentElements = allMatching([
        '[data-payment]', '[data-payment-method]', '[data-checkout]',
        'button[type="submit"]', 'button',
    ]).filter(element => {
        const text = `${element.innerText || ''} ${element.getAttribute('aria-label') || ''}`;
        return element.matches('[data-payment], [data-payment-method], [data-checkout], button[type="submit"]')
            || containsAny(text, ['pay', 'checkout', 'place order', 'cash', 'card', 'submit', 'confirm']);
    });
    const paymentVisibility = paymentElements.map(element => {
        const style = getComputedStyle(element);
        const rect = element.getBoundingClientRect();
        return {
            ...describe(element),
            visible: isVisible(element),
            styleVisible: isStyleVisible(element),
            intersectingViewport: isIntersecting(element),
            position: style.position,
            stickyOrFixed: style.position === 'sticky' || style.position === 'fixed',
            rect: { left: round(rect.left), top: round(rect.top), width: round(rect.width), height: round(rect.height) },
            disabled: disabledLike(element),
        };
    });
    const primaryPayElements = allMatching([
        '.pay-btn-premium', '[data-primary-pay]', '[data-video="open-payment"]',
    ]);
    const primaryPayBounds = primaryPayElements.map(element => {
        const rect = element.getBoundingClientRect();
        const styleVisible = isStyleVisible(element);
        return {
            ...describe(element),
            styleVisible,
            visible: isVisible(element),
            rect: { left: round(rect.left), top: round(rect.top), right: round(rect.right), bottom: round(rect.bottom), width: round(rect.width), height: round(rect.height) },
            fullyInsideViewport: styleVisible
                && rect.left >= -2
                && rect.top >= -2
                && rect.right <= viewport.width + 2
                && rect.bottom <= viewport.height + 2,
        };
    });
    const paymentDialogOpen = allMatching([
        '[data-payment-dialog]', '[x-show="showPayModal"]', '[x-show*="showPayModal"]',
        '[role="dialog"][data-payment]', '[role="dialog"][aria-label*="payment" i]',
    ]).some(isVisible);
    const visibleCart = allMatching([
        '.tn-cart-col', '.tn-cart-rail', '[data-cart-root]', '[data-cart]',
    ]).some(isVisible);
    const visiblePrimaryPay = primaryPayBounds.filter(item => item.styleVisible);
    const mobilePayCheckApplies = viewportClass === 'mobile'
        && visibleCart
        && visiblePrimaryPay.length > 0
        && !paymentDialogOpen;
    const mobilePayOutsideViewport = mobilePayCheckApplies
        && visiblePrimaryPay.some(item => !item.fullyInsideViewport);
    const visiblePayment = paymentVisibility.filter(item => item.visible);
    const stickyPayment = visiblePayment.filter(item => item.stickyOrFixed);
    const root = document.documentElement;
    const body = document.body;
    const main = firstMatching(['main', '[role="main"]']);
    const isIntentionalScrollable = element => {
        if (!element) return false;
        const style = getComputedStyle(element);
        return style.overflow === 'auto' || style.overflow === 'scroll'
            || style.overflowX === 'auto' || style.overflowX === 'scroll'
            || style.overflowY === 'auto' || style.overflowY === 'scroll'
            || element.matches('[data-scrollable], [data-scroll-container], .overflow-auto, .overflow-x-auto, .overflow-y-auto, [role="table"]');
    };
    const overflowRecord = (element, name) => {
        if (!element) return { name, present: false, checked: false, intentionalScrollable: false };
        const scrollWidth = Math.max(element.scrollWidth || 0, element.clientWidth || 0);
        const scrollHeight = Math.max(element.scrollHeight || 0, element.clientHeight || 0);
        const widthLimit = name === 'document' ? viewport.width : element.clientWidth;
        return {
            name,
            present: true,
            checked: !isIntentionalScrollable(element),
            intentionalScrollable: isIntentionalScrollable(element),
            scrollWidth,
            clientWidth: element.clientWidth,
            horizontalOverflow: scrollWidth > widthLimit + 2 && !isIntentionalScrollable(element),
            scrollHeight,
            clientHeight: element.clientHeight,
            verticalOverflow: name === 'document' ? scrollHeight > viewport.height + 2 : scrollHeight > element.clientHeight + 2,
        };
    };
    const overflow = {
        document: overflowRecord(root, 'document'),
        body: overflowRecord(body, 'body'),
        main: overflowRecord(main, 'main'),
        intentionallyScrollableContainers: [...document.querySelectorAll('[data-scrollable], [data-scroll-container], .overflow-auto, .overflow-x-auto, .overflow-y-auto, [role="table"]')].length,
    };
    const headerRect = header?.getBoundingClientRect();
    const headerStyle = header ? getComputedStyle(header) : null;
    const darkClass = document.documentElement.classList.contains('dark')
        || document.body?.classList.contains('dark');
    const theme = {
        darkClass,
        colorScheme: getComputedStyle(document.documentElement).colorScheme || 'normal',
        dataTheme: document.documentElement.getAttribute('data-theme')
            || document.body?.getAttribute('data-theme')
            || null,
        prefersDark: window.matchMedia?.('(prefers-color-scheme: dark)').matches || false,
    };
    const report = {
        ok: Object.values(categories).every(category => category.pass),
        generatedAt: new Date().toISOString(),
        durationMs: Date.now() - startedAt,
        viewport,
        viewportClass,
        responsive: {
            current: viewportClass,
            metrics: {
                viewportWidth: viewport.width,
                viewportHeight: viewport.height,
                headerWidth: headerRect ? round(headerRect.width) : null,
                headerHeight: headerRect ? round(headerRect.height) : null,
                headerVisible: Boolean(header && isVisible(header)),
                headerStickyOrFixed: Boolean(headerStyle && (headerStyle.position === 'sticky' || headerStyle.position === 'fixed')),
                paymentVisible: visiblePayment.length > 0,
                paymentIntersecting: visiblePayment.some(item => item.intersectingViewport),
            },
            breakpoints: {
                mobile: { maxWidth: 600 },
                tablet: { minWidth: 601, maxWidth: 1024 },
                desktop: { minWidth: 1025 },
            },
        },
        theme,
        darkMode: theme,
        header: {
            found: Boolean(header),
            visible: Boolean(header && isVisible(header)),
            dimensions: headerRect
                ? { left: round(headerRect.left), top: round(headerRect.top), width: round(headerRect.width), height: round(headerRect.height) }
                : null,
        },
        categories,
        contrast: categories,
        overflow,
        stickyPayment: {
            candidateCount: paymentVisibility.length,
            visibleCount: visiblePayment.length,
            intersectingCount: visiblePayment.filter(item => item.intersectingViewport).length,
            stickyOrFixedCount: stickyPayment.length,
            visible: viewportClass === 'mobile' ? visiblePayment.length > 0 : undefined,
            intersecting: viewportClass === 'mobile' ? visiblePayment.some(item => item.intersectingViewport) : undefined,
            controls: paymentVisibility,
        },
        primaryPay: {
            candidateCount: primaryPayBounds.length,
            styleVisibleCount: visiblePrimaryPay.length,
            cartVisible: visibleCart,
            paymentDialogOpen,
            mobileCheckApplies: mobilePayCheckApplies,
            mobilePayOutsideViewport,
            controls: primaryPayBounds,
        },
        requiredCategoryFailures: requiredCategories.filter(category => !categories[category] || categories[category].status === 'not-found'),
        findings: [],
    };
    for (const category of Object.values(categories)) {
        for (const record of category.records) {
            if (record.pass) continue;
            const failures = record.failures?.length ? record.failures : [record.failure || 'contrast'];
            for (const failure of failures) {
                report.findings.push({ type: failure, category: category.category, record });
            }
        }
    }
    for (const key of ['document', 'main']) {
        if (overflow[key].horizontalOverflow) report.findings.push({ type: 'horizontal-overflow', target: key });
    }
    if (mobilePayOutsideViewport) {
        report.findings.push({
            type: 'mobile-pay-outside-viewport',
            primaryPay: primaryPayBounds.filter(item => item.styleVisible && !item.fullyInsideViewport),
        });
    }
    if (report.requiredCategoryFailures.length) {
        report.findings.push({ type: 'required-category-missing', categories: report.requiredCategoryFailures });
    }
    report.ok = report.findings.length === 0;
    return report;
};

/**
 * Return assertion details without coupling the browser audit to a test
 * runner.  Set throwOnFailure=false when a caller wants a normal report.
 */
export function findPremiumVisualAuditFailures(report, options = {}) {
    const failures = [...(report?.findings || [])];
    if (options.requireHeader !== false && report?.header?.found && !report.header.visible) {
        failures.push({ type: 'header-not-visible' });
    }
    if (options.requireMobilePayment && report?.viewportClass === 'mobile'
        && (!report.stickyPayment?.visible || !report.stickyPayment?.intersecting)) {
        failures.push({ type: 'mobile-payment-not-visible-or-intersecting' });
    }
    if (options.requireCategories) {
        for (const category of options.requireCategories) {
            if (!report?.categories?.[category] || report.categories[category].status === 'not-found') {
                failures.push({ type: 'required-category-missing', category });
            }
        }
    }
    return failures;
}

export function assertPremiumVisualAudit(report, options = {}) {
    const failures = findPremiumVisualAuditFailures(report, options);
    if (failures.length) {
        const summary = failures.slice(0, 8).map(failure => `${failure.type}${failure.category ? `:${failure.category}` : ''}`).join(', ');
        throw new Error(`Premium visual audit failed (${failures.length}): ${summary}`);
    }
    return report;
}

/**
 * Pure counterparts used by the no-browser self-test.  The page audit keeps
 * its own copies because page.evaluate serializes only the function body.
 */
export function classifyPremiumDisabledControl(input = {}) {
    const classTokens = String(input.className || '').split(/\s+/).filter(Boolean);
    const nativeDisabled = Boolean(input.nativeDisabled || input.disabledProperty);
    const disabled = nativeDisabled
        || input.ariaDisabled === true
        || input.ariaDisabled === 'true'
        || Boolean(input.matchesDisabled)
        || classTokens.includes('disabled')
        || classTokens.includes('is-disabled');
    return { disabled, nativeDisabled };
}

export function hasPremiumDisabledAppearance(input = {}) {
    const opacity = Number.isFinite(Number(input.opacity)) ? Number(input.opacity) : 1;
    return opacity < 0.99
        || input.cursor === 'not-allowed'
        || (input.filter != null && input.filter !== 'none');
}

export function assessPremiumMobilePayBounds(rect, viewport, tolerance = 2) {
    const styleVisible = Number(rect?.width) > 0 && Number(rect?.height) > 0
        && rect?.display !== 'none'
        && rect?.visibility !== 'hidden'
        && Number(rect?.opacity ?? 1) > 0;
    const fullyInsideViewport = styleVisible
        && Number(rect.left) >= -tolerance
        && Number(rect.top) >= -tolerance
        && Number(rect.right) <= Number(viewport.width) + tolerance
        && Number(rect.bottom) <= Number(viewport.height) + tolerance;
    return { styleVisible, fullyInsideViewport, outsideViewport: styleVisible && !fullyInsideViewport };
}

/**
 * Optional browser-action check for the shell navigation.  It only clicks
 * navigation toggles and their close controls; it never clicks payment,
 * checkout, order, or submit controls.
 */
export async function verifyPremiumNavigation(page, options = {}) {
    const toggleSelectors = options.toggleSelectors || [
        '[data-nav-toggle]', '[data-mobile-nav-toggle]', 'button[aria-controls*="nav" i]',
        'button[aria-label*="menu" i]', 'button[aria-label*="navigation" i]',
    ];
    const closeSelectors = options.closeSelectors || [
        '[data-nav-close]', 'button[aria-label*="close" i]',
        'button[aria-label*="dismiss" i]',
    ];
    const navSelectors = options.navSelectors || [
        'nav', '[role="navigation"]', '[data-mobile-nav]', '[data-nav-menu]',
    ];
    const visible = async selector => page.locator(selector).filter({ visible: true }).count().catch(() => 0);
    const result = { attempted: false, opened: false, closed: false, checks: [], skipped: false };
    const toggle = page.locator(toggleSelectors.join(',')).first();
    if (!await toggle.count()) {
        result.skipped = true;
        result.reason = 'navigation-toggle-not-found';
        return result;
    }
    result.attempted = true;
    const before = await Promise.all(navSelectors.map(selector => visible(selector)));
    await toggle.click();
    await page.waitForTimeout(options.waitMs ?? 100);
    const afterOpen = await Promise.all(navSelectors.map(selector => visible(selector)));
    result.opened = afterOpen.some((count, index) => count > before[index]);
    result.checks.push({ action: 'open', before, after: afterOpen, pass: result.opened });
    const close = page.locator(closeSelectors.join(',')).filter({ visible: true }).first();
    if (await close.count()) {
        await close.click();
    } else {
        await toggle.click();
    }
    await page.waitForTimeout(options.waitMs ?? 100);
    const afterClose = await Promise.all(navSelectors.map(selector => visible(selector)));
    result.closed = afterClose.every((count, index) => count <= before[index]);
    result.checks.push({ action: 'close', before, after: afterClose, pass: result.closed });
    if (options.assert !== false && (!result.opened || !result.closed)) {
        throw new Error(`Premium navigation verification failed: open=${result.opened} close=${result.closed}`);
    }
    return result;
}

export const auditPremiumVisualPageSource = auditPremiumVisualPage.toString();