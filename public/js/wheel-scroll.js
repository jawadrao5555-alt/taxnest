/**
 * TaxNest Universal Wheel-Scroll Router
 * ------------------------------------
 * Problem: layouts use a fixed-height shell where only <main> scrolls
 * (overflow-y-auto). When the cursor sits over a non-scrollable region
 * (sidebar, fixed header, empty gutters), the mouse wheel does nothing.
 *
 * Fix: a passive window-level wheel listener that:
 *   1. Normalizes deltaY across deltaMode (0=pixel, 1=line, 2=page).
 *   2. Ignores zoom (ctrlKey) and mostly-horizontal gestures.
 *   3. Walks the target's ancestor chain — if ANY scrollable ancestor can
 *      still consume the scroll in that direction, the browser handles it
 *      natively (we do nothing).
 *   4. Otherwise routes the delta to the primary <main> scroll container.
 *
 * Passive:true means we never block native scrolling — we only add scroll
 * where the browser would otherwise do nothing, so double-scroll is impossible.
 */
(function () {
    'use strict';

    function isScrollable(el) {
        if (!el || el === document.documentElement) return false;
        var style = window.getComputedStyle(el);
        var oy = style.overflowY;
        if (oy !== 'auto' && oy !== 'scroll' && !(el === document.body && oy !== 'hidden')) {
            return false;
        }
        return el.scrollHeight > el.clientHeight + 1;
    }

    function canConsume(el, deltaY) {
        if (deltaY < 0) return el.scrollTop > 0;
        return el.scrollTop + el.clientHeight < el.scrollHeight - 1;
    }

    function findMainScroller() {
        var candidates = [
            document.querySelector('main.overflow-y-auto'),
            document.querySelector('main [data-wheel-main]'),
            document.querySelector('[data-wheel-main]'),
            document.querySelector('main'),
        ];
        for (var i = 0; i < candidates.length; i++) {
            var c = candidates[i];
            if (c && isScrollable(c)) return c;
        }
        // Fallback: the tallest scrollable overflow-y-auto container on the page.
        var all = document.querySelectorAll('.overflow-y-auto');
        var best = null;
        var bestArea = 0;
        for (var j = 0; j < all.length; j++) {
            var el = all[j];
            if (!isScrollable(el)) continue;
            var rect = el.getBoundingClientRect();
            var area = rect.width * rect.height;
            if (area > bestArea) { bestArea = area; best = el; }
        }
        return best;
    }

    window.addEventListener('wheel', function (e) {
        if (e.ctrlKey || e.defaultPrevented) return;               // pinch-zoom / handled
        if (Math.abs(e.deltaX) > Math.abs(e.deltaY)) return;       // horizontal gesture
        if (!e.deltaY) return;

        var deltaY = e.deltaY;
        if (e.deltaMode === 1) deltaY *= 33;                       // lines → px
        else if (e.deltaMode === 2) deltaY *= window.innerHeight;  // pages → px

        // If any scrollable ancestor (incl. body) can consume this, let the
        // browser scroll it natively.
        var node = (e.target instanceof Element) ? e.target : null;
        while (node) {
            if (isScrollable(node) && canConsume(node, deltaY)) return;
            node = node.parentElement;
        }
        // Window/document itself scrollable? Browser will handle it.
        var doc = document.documentElement;
        if (doc.scrollHeight > doc.clientHeight + 1) {
            if (canConsume(doc, deltaY)) return;
        }

        var main = findMainScroller();
        if (main && canConsume(main, deltaY)) {
            main.scrollTop += deltaY;
        }
    }, { passive: true });
})();
