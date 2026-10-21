# Recalled table cart: scroll the new bottom items

Owner video ac551156-e58b-4e8e-b4a0-e8b48f0e6cda.mov shows newly-added items below the visible recalled order with wheel scrolling the page instead. Responsive short/tablet/mobile rules explicitly disable the cart list's own scrolling (`flex:none; overflow:visible`); the global wheel fallback can also redirect an exhausted/non-scrollable cart into the product grid.

Keep a bounded, independently scrollable item list in those layouts, preserve outer cart-rail scrolling so notes/totals/payment remain reachable, and contain rail overscroll instead of chaining to the page. Wheels within the cart remain owned by native cart scrolling; existing outside-cart grid fallback and fixed-overlay behavior remain unchanged. Desktop full-height and products-hidden/wide-cart routing remain supported. No cart content, prices, recall/save/payment logic or settings change.

Local actual wheel-method regressions: 3/3 pass (cart/both edges, native children/fixed overlays, existing outside fallback). Syntax/whitespace/workflow contract pass. Real Chrome table claim/recall journey appends 24 fictional cart rows, exercises actual mouse wheel, asserts list movement without page/product-grid movement, reaches the last new item, captures desktop short-height/390px evidence, restores the original cart and continues existing table/KOT regressions. No sale/print is submitted by this added probe. Supported local app/browser unavailable, so actual visual/full CI pending; no independently live-verified root-cause claim.

## Overnight CI follow-up

Latest desktop browser probe passed; 390px revealed public/css/premium-pos.css overrides Blade with overflow:visible!important. Correct that actual loaded PRA-only phone rule to bounded native overflow-y:auto!important, keeping the normal-flow outer footer rail and FBR rules. Real wheel/page/grid/last-item checks all retained; fresh browser acceptance required.

Local environment recheck: loopback binding is available in this run, but supported PHP 8.4 is absent and Chrome installation failed (truncated/non-zip download). Local pure JS/syntax checks are limited evidence; supported real browser/database/full PHP acceptance remains GitHub CI, not customer production acceptance.
