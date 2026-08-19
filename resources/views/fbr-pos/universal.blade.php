<x-fbr-pos-layout>
@php
    // ═══════════════════ FBR UNIVERSAL SHIM ═══════════════════
    // This view is a PORT of pos/universal.blade.php (PRA). FBR POS has no
    // restaurant / inventory / recipes / tables modules, so every one of those
    // inputs is pinned OFF here. The markup they gate stays in the file
    // (compiles fine, never renders) so future diffs against the PRA source
    // remain reviewable. DO NOT delete these pins — undefined vars = 500.
    // DIVERGENCE NOTE (updated 7 Aug 2026): the 24 Jul PRA sale-screen redesign
    // (compact grid rows, notes+discount one-row chips, bada total band, one-tap
    // CASH/CARD Alt+1/2) is NOW PORTED here — owner approved via video note.
    // FBR differences kept on purpose: blue chrome (theme engine remaps blue-*),
    // per-item tax (no cash/card method hint), Fit menu uses fixed-position
    // anchoring (nav overflow clipping). Task 1271: gridEditMode / per-user grid
    // prefs, WhatsApp Bill share, cart drafts (fbr_pos_drafts) and search-mode
    // pref are NOW PORTED here (products-only prefs — FBR has no deals in grid).
    // STORE terminology (Task 1285): the FBR panel calls the whole KOT family
    // "Store" — Store Printer / Store Slip / Auto Store Slip — because in a
    // retail shop the slip goes to the godown/packing STORE, not a kitchen.
    // Only LABELS diverge (fbr_* lang keys); internal names (kot feature flag,
    // autoKotEnabled, sendToKitchen, fbr_kot job type, kitchen-ticket view)
    // stay identical to the PRA source so diffs remain reviewable. PRA keeps
    // its KOT wording — never point the fbr_* keys at PRA views.
    $features = (object) [
        'tables' => false, 'delivery' => false,
        // Order Matching (Aug 2026): unpin kot — gate on kitchen_printer_enabled so
        // FBR restaurant companies can use the KOT + Order Matching flow.
        // D1 decision: reuse kitchen_printer_enabled (already loaded, no new column).
        // Strict plan binding (Aug 2026): AND with the plan's kot_enabled gate —
        // server-side fbrPlanGate('kot_enabled') blocks the ticket routes too.
        'kot' => (bool)($company->kitchen_printer_enabled ?? false)
                 && \App\Services\PosFeatureService::planAllows($company, 'kot_enabled'),
        'kitchen' => false, 'recipes' => false, 'inventory' => false,
        'kitchen_notes' => false,
    ];
    // Services (Task 1272): UNPINNED — create() bakes active PosService rows so
    // service items (repairs etc.) sell here as product_id-NULL lines carrying
    // their own tax_rate/is_tax_exempt. The ?? keeps stray renders 500-free.
    $services = $services ?? collect();
    $categories = collect();
    $tables = collect();
    $selectedTable = null;
    $heldOrders = collect();
    $recipeLookup = [];
    $stockStatus = [];
    $ingredientCosts = [];
    $lowStockAlerts = collect();
    $inventoryEnabled = false;
    $blockOutOfStock = false;
    // Manager PIN override uses a PRA-only endpoint — disabled in FBR POS.
    // Over-limit discounts stay blocked for cashiers; admin cap = 100%.
    $hasManagerPin = false;
    $fbrUser = auth('fbrpos')->user();
    // View logic checks posRole === 'pos_admin' — map FBR's company_admin onto it.
    $posRole = ($fbrUser->role ?? '') === 'company_admin' ? 'pos_admin' : 'pos_cashier';
    $discountLimit = $posRole === 'pos_admin'
        ? (float) ($company->manager_discount_limit ?? 50)
        : (float) ($company->cashier_discount_limit ?? 50);
    // FBR tax is PER-ITEM (18% default / exempt) — payment-method taxRules do not apply.
    $taxRate = 0;
    $taxRules = collect();
    $customers = $customers ?? collect();
    // Delivery Board button (Aug 2026): separate from $features->delivery (pinned false for
    // restaurant-markup gate) — use PosFeatureService directly so delivery shops get the board
    // without unpinning the restaurant delivery markup which must stay off for FBR.
    $_fbrAllFeatures = \App\Services\PosFeatureService::forCompany($company);
    $showDeliveriesBoardBtn = !empty($_fbrAllFeatures->delivery)
        && \App\Services\PosFeatureService::planAllows($company, 'riders_enabled');
@endphp
<style>
*, *::before, *::after { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
@keyframes cartPop { 0% { transform: scale(1); } 50% { transform: scale(1.12); } 100% { transform: scale(1); } }
@keyframes slideIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
@keyframes slideOut { from { opacity: 1; transform: translateX(0); max-height: 120px; } to { opacity: 0; transform: translateX(60px); max-height: 0; padding-top:0; padding-bottom:0; margin:0; } }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes fadeInUp { from { opacity: 0; transform: translateY(8px) scale(0.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
@keyframes shimmer { 0% { background-position: -200% 0; } 100% { background-position: 200% 0; } }
@keyframes pulse-ring { 0% { transform: scale(0.8); opacity: 1; } 100% { transform: scale(1.8); opacity: 0; } }
@keyframes scaleIn { 0% { transform: scale(0); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
@keyframes qtyPop { 0% { transform: scale(1); } 40% { transform: scale(1.2); } 100% { transform: scale(1); } }
@keyframes cartItemAdd { 0% { opacity: 0; transform: translateX(-20px) scale(0.95); } 100% { opacity: 1; transform: translateX(0) scale(1); } }
@keyframes ripple { to { transform: scale(4); opacity: 0; } }
@keyframes toastSlide { from { transform: translateX(120%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
@keyframes toastSlideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(120%); opacity: 0; } }
@keyframes floatBadge { 0% { transform: translateY(0) scale(1); } 50% { transform: translateY(-3px) scale(1.05); } 100% { transform: translateY(0) scale(1); } }
@keyframes cardEnter { from { opacity: 0; transform: translateY(16px) scale(0.92); } to { opacity: 1; transform: translateY(0) scale(1); } }
@keyframes pulseGlow { 0%, 100% { box-shadow: 0 0 0 0 rgba(37,99,235,0.4); } 50% { box-shadow: 0 0 0 6px rgba(37,99,235,0); } }
.cart-pop { animation: cartPop 0.25s cubic-bezier(0.34, 1.56, 0.64, 1); }
.qty-pop { animation: qtyPop 0.2s cubic-bezier(0.34, 1.56, 0.64, 1); }
.slide-in { animation: slideIn 0.25s cubic-bezier(0.16, 1, 0.3, 1); }
.fade-in { animation: fadeInUp 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.cart-item-enter { animation: cartItemAdd 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.cart-item-exit { animation: slideOut 0.25s ease forwards; overflow: hidden; }
.skeleton { background: linear-gradient(90deg, #e5e7eb 25%, #f3f4f6 50%, #e5e7eb 75%); background-size: 200% 100%; animation: shimmer 1.5s ease-in-out infinite; }
.dark .skeleton { background: linear-gradient(90deg, #1f2937 25%, #374151 50%, #1f2937 75%); background-size: 200% 100%; }
.prod-card { transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); cursor: pointer; position: relative; animation: cardEnter 0.35s cubic-bezier(0.16, 1, 0.3, 1) both; }
.prod-card:nth-child(2) { animation-delay: 0.02s; } .prod-card:nth-child(3) { animation-delay: 0.04s; } .prod-card:nth-child(4) { animation-delay: 0.06s; } .prod-card:nth-child(5) { animation-delay: 0.08s; } .prod-card:nth-child(6) { animation-delay: 0.1s; }
.prod-card:hover { transform: translateY(-6px) scale(1.02); box-shadow: 0 20px 40px -12px rgba(0,0,0,0.18), 0 0 0 1px rgba(37,99,235,0.1); }
.prod-card:active { transform: translateY(-2px) scale(0.97); transition-duration: 0.1s; }
.prod-card .quick-add { opacity: 0; transform: scale(0.5) rotate(-90deg); transition: all 0.25s cubic-bezier(0.34, 1.56, 0.64, 1); }
.prod-card:hover .quick-add { opacity: 1; transform: scale(1) rotate(0deg); }
.prod-card.stock-out { opacity: 0.5; pointer-events: none; filter: grayscale(0.5); }
.prod-card.stock-out.allow-add { opacity: 0.7; pointer-events: auto; filter: grayscale(0.3); }
.prod-card .cart-qty-badge { animation: floatBadge 2s ease-in-out infinite; }
.letter-A,.letter-B { background: linear-gradient(135deg, #f472b6, #ec4899, #db2777) !important; }
.letter-C,.letter-D { background: linear-gradient(135deg, #a78bfa, #8b5cf6, #2563eb) !important; }
.letter-E,.letter-F { background: linear-gradient(135deg, #60a5fa, #3b82f6, #2563eb) !important; }
.letter-G,.letter-H { background: linear-gradient(135deg, #34d399, #10b981, #059669) !important; }
.letter-I,.letter-J { background: linear-gradient(135deg, #fbbf24, #f59e0b, #d97706) !important; }
.letter-K,.letter-L { background: linear-gradient(135deg, #f87171, #ef4444, #dc2626) !important; }
.letter-M,.letter-N { background: linear-gradient(135deg, #c084fc, #a855f7, #1d4ed8) !important; }
.letter-O,.letter-P { background: linear-gradient(135deg, #38bdf8, #0ea5e9, #0284c7) !important; }
.letter-Q,.letter-R { background: linear-gradient(135deg, #fb923c, #f97316, #ea580c) !important; }
.letter-S,.letter-T { background: linear-gradient(135deg, #2dd4bf, #14b8a6, #0d9488) !important; }
.letter-U,.letter-V { background: linear-gradient(135deg, #e879f9, #d946ef, #c026d3) !important; }
.letter-W,.letter-X { background: linear-gradient(135deg, #818cf8, #6366f1, #4f46e5) !important; }
.letter-Y,.letter-Z { background: linear-gradient(135deg, #a3e635, #84cc16, #65a30d) !important; }
.cat-pill { transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); white-space: nowrap; position: relative; overflow: hidden; }
.cat-pill:hover { transform: translateY(-2px) scale(1.05); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.cat-pill.active { background: linear-gradient(135deg, #2563eb, #a855f7); color: white; box-shadow: 0 1px 2px rgba(0,0,0,.08); transform: scale(1.05); }
.cart-item { transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); }
.cart-item:hover { background: rgba(139,92,246,0.06); }
.stock-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.stock-available { background: #22c55e; box-shadow: 0 0 6px rgba(34,197,94,0.4); }
.stock-low { background: #f59e0b; box-shadow: 0 0 6px rgba(245,158,11,0.5); animation: pulseGlow 2s ease-in-out infinite; }
.stock-low { --tw-shadow-color: rgba(245,158,11,0.4); }
.stock-out { background: #ef4444; box-shadow: 0 0 4px rgba(239,68,68,0.3); }
.btn-ripple { position: relative; overflow: hidden; }
.btn-ripple::after { content: ''; position: absolute; width: 100%; padding-top: 100%; border-radius: 50%; background: rgba(255,255,255,0.2); top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0); opacity: 1; transition: none; }
.btn-ripple:active::after { animation: ripple 0.5s ease-out; }
.toast-enter { animation: toastSlide 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
.toast-exit { animation: toastSlideOut 0.3s ease forwards; }
.price-badge { background: linear-gradient(135deg, rgba(37,99,235,0.08), rgba(37,99,235,0.15)); border: 1px solid rgba(37,99,235,0.15); border-radius: 8px; padding: 2px 8px; }
.dark .price-badge { background: linear-gradient(135deg, rgba(167,139,250,0.1), rgba(167,139,250,0.2)); border-color: rgba(167,139,250,0.2); }
@media (max-width: 767px) {
    .mobile-sticky-pay { position: sticky; bottom: 0; z-index: 20; background: inherit; padding-bottom: env(safe-area-inset-bottom, 0); }
    .mobile-collapse-header { cursor: pointer; user-select: none; }
    .mobile-collapse-header::after { content: '▾'; float: right; transition: transform 0.2s; font-size: 10px; color: #9ca3af; }
    .mobile-collapse-header.collapsed::after { transform: rotate(-90deg); }
    .prod-card { min-height: 0; }
    .prod-card:hover { transform: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
    .prod-card:active { transform: scale(0.96); }
    .cart-item { padding: 10px 12px !important; }
    .cart-item .qty-btn-mobile { min-width: 44px; min-height: 44px; }
}
.priority-badge { position: relative; }
.priority-badge::after { content: ''; position: absolute; top: -1px; right: -1px; width: 8px; height: 8px; background: #ef4444; border-radius: 50%; }
::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
.dark ::-webkit-scrollbar-thumb { background: #4b5563; }
.hide-scrollbar::-webkit-scrollbar { display: none; }
.hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
.freq-badge { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; font-size: 9px; padding: 1px 6px; border-radius: 999px; font-weight: 700; }
.swipe-hint { position: absolute; right: 0; top: 0; bottom: 0; width: 60px; background: linear-gradient(90deg, transparent, rgba(239,68,68,0.1)); display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; pointer-events: none; }
.cart-item:hover .swipe-hint { opacity: 1; }
@keyframes confettiFall { 0% { transform: translateY(-20px) rotate(0deg) scale(0); opacity: 1; } 50% { opacity: 1; } 100% { transform: translateY(200px) rotate(720deg) scale(1); opacity: 0; } }
@keyframes successPulse { 0% { box-shadow: 0 0 0 0 rgba(34,197,94,0.5); } 70% { box-shadow: 0 0 0 20px rgba(34,197,94,0); } 100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); } }
@keyframes checkDraw { 0% { stroke-dashoffset: 24; } 100% { stroke-dashoffset: 0; } }
@keyframes receiptSlideUp { from { opacity: 0; transform: translateY(30px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
.search-glow:focus { box-shadow: 0 0 0 3px rgba(37,99,235,0.15), 0 0 20px rgba(37,99,235,0.1) !important; border-color: #2563eb !important; }
.dark .search-glow:focus { box-shadow: 0 0 0 3px rgba(167,139,250,0.2), 0 0 20px rgba(167,139,250,0.08) !important; }
@keyframes heldBadgePulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.25); } }
.held-badge-pulse { animation: heldBadgePulse 1.5s ease-in-out infinite; }

/* ── Wide-cart (Products OFF) — port from PRA universal (30 Jul 2026) ──
   .tn-cart-main is display:contents by DEFAULT so normal (grid-ON) layout is
   byte-identical; only .tn-widecart (desktop) activates the split. */
.tn-cart-main { display: contents; }
@supports not (display: contents) {
    .tn-cart-main { display: flex; flex-direction: column; flex: 1 1 0%; min-height: 0; }
}
@media (min-width: 768px) {
    .tn-widecart { flex-direction: column; }
    /* Widecart: left col shrinks to the bars' height — its overflow:hidden would
       clip the search dropdown to a 1px sliver (owner report, 1 Aug 2026). Let it
       overflow (grid is display:none anyway) and lift it above the cart panes. */
    .tn-widecart .tn-left-col { flex: 0 0 auto; overflow: visible !important; position: relative; z-index: 30; }
    .tn-widecart .tn-left-col [x-ref="gridContainer"] { display: none; }
    .tn-widecart .tn-cart-col { width: 100% !important; flex: 1 1 0%; min-height: 0; flex-direction: row; border-left: 0; border-top: 1px solid rgba(148,163,184,.28); }
    .tn-widecart .tn-cart-main { display: flex; flex-direction: column; flex: 1 1 0%; min-width: 0; min-height: 0; }
    .tn-widecart .tn-cart-side { flex: 0 0 400px; width: 400px; min-height: 0; overflow-y: auto; border-left: 1px solid rgba(148,163,184,.28); border-top: 0; }
}
.cat-pill.active::after { content: ''; position: absolute; bottom: 0; left: 15%; right: 15%; height: 3px; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.8), transparent); border-radius: 2px; }
.total-animate { transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
.confetti-piece { position: absolute; width: 8px; height: 8px; border-radius: 2px; animation: confettiFall 1.5s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards; pointer-events: none; }
.receipt-modal-enter { animation: receiptSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
.success-icon-animate { animation: successPulse 1.5s ease-out 0.3s; }

/* ────────────────────────────────────────────────────────────
   Phase 6 — PREMIUM POLISH LAYER (v13)
   Pure additive CSS. No HTML/JS structural changes.
   Design tokens, refined hover states, tighter rhythm,
   better numerics, consistent button feel, calmer chrome.
   ──────────────────────────────────────────────────────────── */

:root {
    --tn-radius:14px;
    --tn-radius-sm:10px;
    --tn-ease: cubic-bezier(0.16, 1, 0.3, 1);
    --tn-dur-fast:.15s;
    --tn-dur:.2s;
    --tn-primary:#6366f1;       /* indigo-500 */
    --tn-primary-strong:#4f46e5;/* indigo-600 */
    --tn-accent:#a855f7;        /* blue-500 */
    --tn-success:#16a34a;
    --tn-success-strong:#15803d;
    --tn-warning:#f59e0b;
    --tn-danger:#ef4444;
    --tn-surface:#ffffff;
    --tn-ink:#0f172a;
    --tn-mute:#64748b;
}
.dark :root, .dark { --tn-surface:#0b0f1a; --tn-ink:#f1f5f9; }

/* Tabular numerics for ALL prices/totals — zero shift between digits */
[x-text*="toLocaleString"], .tn-num { font-variant-numeric: tabular-nums; font-feature-settings: "tnum"; }

/* Universal button-press micro-interaction (additive — doesn't override per-button colors) */
button { transition: transform var(--tn-dur-fast) var(--tn-ease), box-shadow var(--tn-dur) var(--tn-ease), background-color var(--tn-dur) var(--tn-ease), color var(--tn-dur) var(--tn-ease), opacity var(--tn-dur) var(--tn-ease); }
button:not(:disabled):active { transform: translateY(1px) scale(0.98); }

/* Cart row — softer hover + active accent stripe on the left */
.cart-item { position: relative; border-radius: 10px; margin: 0 4px; border-bottom: none !important; }
.cart-item + .cart-item { border-top: 1px solid rgba(148,163,184,.12); }
.cart-item:hover { background: linear-gradient(90deg, rgba(37,99,235,.04), transparent); }
.cart-item::before { content:""; position:absolute; left:0; top:8px; bottom:8px; width:3px; border-radius:3px; background: transparent; transition: background var(--tn-dur) var(--tn-ease); }
.cart-item.cart-row-active::before { background: linear-gradient(180deg, var(--tn-accent), var(--tn-primary)); }
.cart-item.cart-row-active { background: linear-gradient(90deg, rgba(168,85,247,.10), rgba(99,102,241,.04)) !important; outline: none !important; }

/* Cart qty input — clearer & more touch-friendly */
[data-qty-input] { font-variant-numeric: tabular-nums; letter-spacing: .02em; }
[data-qty-input]:focus { box-shadow: 0 0 0 3px rgba(99,102,241,.18) !important; }

/* Larger, smoother total */
.total-line { font-variant-numeric: tabular-nums; letter-spacing: -.01em; }

/* Pay button — premium primary action */
.pay-btn-premium {
    background: linear-gradient(135deg, #16a34a 0%, #059669 60%, #047857 100%);
    box-shadow: 0 1px 2px rgba(0,0,0,.08);
    letter-spacing: .01em;
}
.pay-btn-premium:hover:not(:disabled) { box-shadow: 0 2px 4px rgba(0,0,0,.10); transform: translateY(-1px); }

/* Section dividers — calmer, hairline, dark-mode aware */
.tn-hairline { border-color: rgba(148,163,184,.16) !important; }
.dark .tn-hairline { border-color: rgba(148,163,184,.12) !important; }

/* Empty-state container — gentler tone, soft float */
@keyframes tnFloat { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-4px); } }
.tn-empty { animation: fadeIn .35s var(--tn-ease) both; }
.tn-empty-icon { animation: tnFloat 3s ease-in-out infinite; }

/* Touch-friendly tap targets on tablets (>= sm, < lg) */
@media (min-width: 640px) and (max-width: 1023px) {
    .cart-item button, .cat-pill, .prod-card { min-height: 40px; }
}

/* Reduce visual clutter — strip default focus outlines on click, keep keyboard a11y */
button:focus:not(:focus-visible) { outline: none; }
input:focus:not(:focus-visible) { outline: none; }

/* Modal backdrop — calmer, less heavy */
.tn-modal-backdrop { background: rgba(15,23,42,.55); backdrop-filter: blur(8px); }

/* Honor reduced-motion */
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; }
}
</style>
{{-- Task 658 (Aug 2026): bake only the TXT.* keys this screen actually uses —
     see pos/universal.blade.php twin note. QA: scripts/pos-i18n-check.php in
     the deploy preflight. --}}
<script type="application/json" id="tn-pos-i18n">{!! json_encode(\App\Support\PosI18n::baked('fbr-pos/universal'), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' !!}</script>
<script>window.TXT = (function () { try { return JSON.parse(document.getElementById('tn-pos-i18n').textContent) || {}; } catch (e) { return {}; } })();</script>
<script>
// Task 644 (ZFC, Aug 2026): SALE_CACHE re-prime after a browser-data clear —
// FBR twin of the PRA sale-screen snippet (see pos/universal.blade.php). First
// visit after a clear = no controlling SW, so SALE_CACHE stayed empty and the
// SECOND open was still a full network fetch. Ask the fresh SW to fetch+cache
// this screen once in the background.
(function () {
    try {
        if (!('serviceWorker' in navigator) || navigator.serviceWorker.controller) return;
        window.addEventListener('load', function () {
            navigator.serviceWorker.ready.then(function (reg) {
                if (reg.active) reg.active.postMessage({ type: 'TN_PRIME_SALE_CACHE', url: '/fbr-pos/create' });
            }).catch(function () {});
        });
    } catch (e) { /* best-effort */ }
})();
</script>
<script>
window.history.pushState(null, null, window.location.href);
window.addEventListener('popstate', function() {
    window.history.pushState(null, null, window.location.href);
});
</script>

{{-- Screen Fit (Jul 2026, ported from PRA universal): fitStyleStr applies CSS zoom +
     a /zoom-compensated px height so the sale screen renders correctly on ANY display.
     Auto mode picks the zoom from viewport size; manual % saved per device. --}}
<div x-data="restaurantPos()" @wheel="handleGlobalWheel($event)" class="flex flex-col h-[calc(100vh-48px)] overflow-hidden bg-gray-50 dark:bg-gray-950" :style="fitStyleStr">
    {{-- ═══════════ NAV SWITCHES (Aug 2026, PRA parity — owner request) ═══════════
         Desktop (md+): FBR Reporting / Auto-Print / Auto-KOT live INSIDE the blue top-nav
         as a "Switches" dropdown — teleported into #tn-nav-sale-tools (fbr-pos-app.blade.php)
         via x-teleport so they KEEP this restaurantPos() Alpine scope. The old in-page
         toggles strip below stays as the MOBILE fallback (md:hidden) — same state, same handlers. --}}
    <template x-teleport="#tn-nav-sale-tools">
        <div class="flex items-center gap-1.5 mx-auto flex-shrink-0" x-data="{ switchesOpen: false, swTop: 0, swRight: 0 }">

            {{-- + New Sale — replaces the static nav link on this page (action = clear & restart) --}}
            <button @click="newSale()" class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-white bg-emerald-600/90 hover:bg-emerald-600 ring-1 ring-emerald-300/40 shadow-sm transition flex-shrink-0">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                <span class="hidden lg:inline">{{ __('pos.new_word') }}</span>
            </button>

            {{-- Local (provisional) bills — F10 (page modal: Edit / Delete / Make Final inline) --}}
            <button @click="openLocalBills()" class="relative flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-white bg-white/10 hover:bg-white/20 ring-1 ring-white/15 transition flex-shrink-0" title="{{ __('pos.ti_provisional_f10_fbr') }}">
                <span class="text-[9px] bg-blue-400/30 px-1 rounded">F10</span>
                <span class="hidden lg:inline">{{ __('pos.local_word') }}</span>
                <span x-show="localBills.length > 0" class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 bg-blue-500 text-white text-[9px] rounded-full flex items-center justify-center font-bold" x-text="localBills.length"></span>
            </button>

            {{-- Pending Deliveries (Task 122) — appears only when something IS pending --}}
            {{-- Task 524: button purani unassigned par bhi khulta hai (reachability),
                 magar numeric badge sirf FRESH ginti dikhata hai. --}}
            <button x-show="pendingDeliveryBills().length > 0 || staleDeliveryBills().length > 0" x-cloak @click="openPendingDeliveries()" class="relative flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-white bg-amber-500/85 hover:bg-amber-500 ring-1 ring-amber-300/40 transition flex-shrink-0" title="{{ __('pos.pending_deliveries_hint') }}">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
                <span class="hidden lg:inline">{{ __('pos.pending_deliveries') }}</span>
                <span x-show="pendingDeliveryBills().length > 0" class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 bg-amber-600 text-white text-[9px] rounded-full flex items-center justify-center font-bold" x-text="pendingDeliveryBills().length"></span>
            </button>

            {{-- Delivery Board (Aug 2026) — lazy iframe modal; only shown when delivery feature + riders plan gate are both ON. --}}
            @if($showDeliveriesBoardBtn)
            <button type="button" onclick="tnOpenDeliveryBoard()" class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-white bg-emerald-600/85 hover:bg-emerald-600 ring-1 ring-emerald-300/40 transition flex-shrink-0" title="{{ __('pos.deliveries') }}">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
                <span class="hidden lg:inline">{{ __('pos.deliveries') }}</span>
            </button>
            @endif

            {{-- Failed FBR bills — F11 (page modal: Retry / Edit / Delete inline) --}}
            <button @click="openFailedBills()" class="relative flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-white bg-red-600/85 hover:bg-red-600 ring-1 ring-red-300/40 transition flex-shrink-0" title="{{ __('pos.ti_failed_fbr_f11') }}">
                <span class="text-[9px] bg-red-400/40 px-1 rounded">F11</span>
                <span class="hidden lg:inline">{{ __('pos.failed_word_html') }}</span>
                <span x-show="failedBills.length > 0" class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 bg-red-700 text-white text-[9px] rounded-full flex items-center justify-center font-bold animate-pulse" x-text="failedBills.length"></span>
            </button>

            {{-- Reprint last bill — Alt+R. Hidden until a bill exists this session. --}}
            <button x-show="recentBills.length > 0 || lastTransactionId" x-cloak
                    @click="const last = recentBills[0]; if(last) { _printViaIframe('print-receipt-frame', '/fbr-pos/transaction/' + last.id + '/receipt?auto_print=1', 'width=400,height=700'); showToast('Reprinting #' + last.invoice_number, 'info'); } else if(lastTransactionId) { printReceipt(); }"
                    class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-white bg-white/10 hover:bg-white/20 ring-1 ring-white/15 transition flex-shrink-0" title="Reprint last bill (Alt+R)">
                <span class="text-[9px] bg-teal-400/30 px-1 rounded">Alt+R</span>
                <span class="hidden lg:inline">{{ __('pos.reprint') }}</span>
            </button>

            {{-- Held orders — F3 --}}
            <button @click="activeHeldIndex = 0; showHeldOrders = !showHeldOrders" class="relative flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-white bg-white/10 hover:bg-white/20 ring-1 ring-white/15 transition flex-shrink-0" title="{{ __('pos.held') }} (F3)">
                <span class="text-[9px] bg-amber-400/30 px-1 rounded">F3</span>
                <span class="hidden lg:inline">{{ __('pos.held') }}</span>
                <span x-show="heldOrders.length > 0" class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 bg-red-500 text-white text-[9px] rounded-full flex items-center justify-center font-bold" x-text="heldOrders.length"></span>
            </button>

            {{-- 🟢/🟡/🔴 Auto-Sync status pill — same logic as the mobile copy.
                 Click = manual offline-queue sync (Aug 2026 offline billing). --}}
            <button type="button" @click="syncOfflineBills(true)" class="flex items-center gap-1.5 px-2 py-1.5 rounded-lg text-[10px] font-bold border transition flex-shrink-0"
                 :class="syncStatus === 'online' ? 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800' : (syncStatus === 'syncing' ? 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800' : 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 border-red-200 dark:border-red-800')"
                 :title="offlineNeedsLogin ? window.TXT.ti_session_expired_sync : (syncStatus === 'online' ? (window.TXT.ti_auto_sync_online + ((failedBills.length + offlineQueueCount) ? ' · ' + (failedBills.length + offlineQueueCount) + window.TXT.ti_pending_click_sync : '')) : (syncStatus === 'syncing' ? window.TXT.ti_syncing_pending_fbr : window.TXT.ti_offline_auto_sync_fbr))">
                <span class="w-2 h-2 rounded-full"
                      :class="syncStatus === 'online' ? 'bg-emerald-500' : (syncStatus === 'syncing' ? 'bg-amber-500 animate-pulse' : 'bg-red-500 animate-pulse')"></span>
                <span class="hidden xl:inline" x-text="syncStatus === 'online' ? window.TXT.online : (syncStatus === 'syncing' ? window.TXT.syncing_word : window.TXT.offline)"></span>
                <span x-show="(failedBills.length + offlineQueueCount) > 0" class="px-1.5 rounded-full text-[9px] font-black"
                      :class="syncStatus === 'online' ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white'"
                      x-text="failedBills.length + offlineQueueCount"></span>
            </button>

            {{-- Screen Fit (moved from toolbar Row 2, owner 6 Aug 2026) --}}
            {{-- NOTE (7 Aug 2026, owner video complaint "boxes neeche chhup rahe hain"): panel
                 is position:fixed (anchored to the button rect on open, same pattern as the
                 Switches dropdown below) so it escapes the overflow-x-auto clipping of
                 #tn-nav-sale-tools. --}}
            <div class="flex-shrink-0" @click.away="showFitMenu = false" x-data="{ fitTop: 0, fitRight: 0 }">
                <button type="button" x-ref="fitBtn" @click="showFitMenu = !showFitMenu; if (showFitMenu) { var r = $refs.fitBtn.getBoundingClientRect(); fitTop = r.bottom + 8; fitRight = Math.max(8, window.innerWidth - r.right); }" class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-white bg-white/10 hover:bg-white/20 ring-1 ring-white/15 transition" title="{{ __('pos.ti_screen_fit') }}">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V5a1 1 0 011-1h3m8 0h3a1 1 0 011 1v3m0 8v3a1 1 0 01-1 1h-3m-8 0H5a1 1 0 01-1-1v-3"/></svg>
                    <span class="hidden xl:inline" x-text="fitLabel()"></span>
                </button>
                <div x-show="showFitMenu" x-cloak x-transition :style="'top:' + fitTop + 'px; right:' + fitRight + 'px;'" class="fixed w-48 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-2xl z-[100] overflow-hidden">
                    <p class="px-3 pt-2 pb-1 text-[9px] font-bold uppercase tracking-wider text-gray-400">{{ __('pos.screen_fit') }}</p>
                    <button @click="setFit('auto')" class="w-full flex items-center justify-between px-3 py-2 text-left text-xs font-semibold hover:bg-purple-50 dark:hover:bg-purple-900/20 transition" :class="screenFit === 'auto' ? 'bg-purple-50 dark:bg-purple-900/20 text-purple-700' : 'text-gray-700 dark:text-gray-200'"><span>{{ __('pos.fit_auto_recommended') }}</span><span x-show="screenFit === 'auto'" class="text-purple-600">✓</span></button>
                    <button @click="setFit(0.8)" class="w-full flex items-center justify-between px-3 py-2 text-left text-xs font-semibold hover:bg-purple-50 dark:hover:bg-purple-900/20 transition" :class="screenFit === 0.8 ? 'bg-purple-50 text-purple-700' : 'text-gray-700 dark:text-gray-200'"><span>{{ __('pos.fit_80_compact') }}</span><span x-show="screenFit === 0.8" class="text-purple-600">✓</span></button>
                    <button @click="setFit(0.9)" class="w-full flex items-center justify-between px-3 py-2 text-left text-xs font-semibold hover:bg-purple-50 dark:hover:bg-purple-900/20 transition" :class="screenFit === 0.9 ? 'bg-purple-50 text-purple-700' : 'text-gray-700 dark:text-gray-200'"><span>90%</span><span x-show="screenFit === 0.9" class="text-purple-600">✓</span></button>
                    <button @click="setFit(1)" class="w-full flex items-center justify-between px-3 py-2 text-left text-xs font-semibold hover:bg-purple-50 dark:hover:bg-purple-900/20 transition" :class="screenFit === 1 ? 'bg-purple-50 text-purple-700' : 'text-gray-700 dark:text-gray-200'"><span>{{ __('pos.fit_100_standard') }}</span><span x-show="screenFit === 1" class="text-purple-600">✓</span></button>
                    <button @click="setFit(1.1)" class="w-full flex items-center justify-between px-3 py-2 text-left text-xs font-semibold hover:bg-purple-50 dark:hover:bg-purple-900/20 transition" :class="screenFit === 1.1 ? 'bg-purple-50 text-purple-700' : 'text-gray-700 dark:text-gray-200'"><span>110%</span><span x-show="screenFit === 1.1" class="text-purple-600">✓</span></button>
                    <button @click="setFit(1.25)" class="w-full flex items-center justify-between px-3 py-2 text-left text-xs font-semibold hover:bg-purple-50 dark:hover:bg-purple-900/20 transition" :class="screenFit === 1.25 ? 'bg-purple-50 text-purple-700' : 'text-gray-700 dark:text-gray-200'"><span>{{ __('pos.fit_125_large') }}</span><span x-show="screenFit === 1.25" class="text-purple-600">✓</span></button>
                </div>
            </div>

            {{-- Quick Return (Task 685) — bill number likho, seedha return form.
                 FBR convention: return routes par koi per-staff gate nahi — sab ko dikhta hai. Rose family. --}}
            <button @click="openQuickReturn()" class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-white bg-rose-600/85 hover:bg-rose-600 ring-1 ring-rose-300/40 transition flex-shrink-0" title="{{ __('pos.quick_return_hint') }}">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 15v-1a4 4 0 00-4-4H8m0 0l3 3m-3-3l3-3m9 14V5a2 2 0 00-2-2H6a2 2 0 00-2 2v16l4-2 4 2 4-2 4 2z"/></svg>
                <span class="hidden lg:inline">{{ __('pos.quick_return') }}</span>
            </button>

            {{-- Keys F1 (moved from toolbar Row 2, owner 6 Aug 2026) --}}
            <button @click="showShortcuts = true" class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-white bg-white/10 hover:bg-white/20 ring-1 ring-white/15 transition flex-shrink-0" title="{{ __('pos.ti_keyboard_shortcuts_f1') }}">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3C6.5 3 2 6.58 2 11c0 2.24 1.12 4.27 2.94 5.72L4 21l4.28-2.55c1.15.35 2.4.55 3.72.55 5.5 0 10-3.58 10-8s-4.5-8-10-8z"/></svg>
                <span class="hidden lg:inline">{{ __('pos.keys') }}</span>
                <span class="text-[9px] font-mono bg-white/20 px-1 rounded">F1</span>
            </button>

            {{-- Quick F7 (moved from toolbar Row 2, owner 6 Aug 2026) --}}
            <button @click="openQuickType()" class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-white bg-sky-500/70 hover:bg-sky-500/90 ring-1 ring-sky-300/40 transition flex-shrink-0" title="{{ __('pos.ti_quick_type_f7') }}">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <span class="hidden lg:inline">Quick</span>
                <span class="text-[9px] font-mono bg-white/20 px-1 rounded">F7</span>
            </button>

            {{-- Manual item (moved from Row 1, owner 6 Aug 2026) — Simple Mode only --}}
            <template x-if="!isInventoryEnabled()">
                <button @click="openManualItem()" class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-white bg-emerald-500/80 hover:bg-emerald-500 ring-1 ring-emerald-300/40 transition flex-shrink-0" title="{{ __('pos.ti_add_manual_item') }}">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    <span class="hidden lg:inline">{{ __('pos.manual') }}</span>
                </button>
            </template>

            {{-- Switches dropdown trigger. NOTE: panel is position:fixed (anchored to the
                 button rect on open) so it escapes the overflow-x-auto clipping of
                 #tn-nav-sale-tools and stays attached to its trigger. --}}
            <div class="flex-shrink-0">
                <button type="button" x-ref="swBtn" @click="switchesOpen = !switchesOpen; if (switchesOpen) { var r = $refs.swBtn.getBoundingClientRect(); swTop = r.bottom + 8; swRight = Math.max(8, window.innerWidth - r.right); }" class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-white bg-white/10 hover:bg-white/20 ring-1 ring-white/15 transition" title="{{ __('pos.switches') }}">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <span class="hidden lg:inline">{{ __('pos.switches') }}</span>
                    <svg class="w-3 h-3 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div x-show="switchesOpen" x-cloak @click.outside="switchesOpen = false" x-transition
                     :style="'top:' + swTop + 'px; right:' + swRight + 'px;'"
                     class="fixed bg-white dark:bg-gray-900 rounded-xl shadow-2xl shadow-black/20 border border-gray-200/80 dark:border-gray-700/80 p-3 z-[100] w-64 space-y-3">

                    {{-- FBR Reporting — same handler as the mobile strip (root fbrEnabled/fbrLoading) --}}
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[10px] uppercase tracking-wider font-extrabold text-blue-700 dark:text-blue-300">{{ __('pos.fbr_reporting') }}</span>
                        <div class="flex items-center gap-1.5">
                            <button type="button"
                                @click="fbrLoading = true; fetch('{{ route('fbrpos.api.toggle-fbr-reporting') }}', { method:'POST', headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}', 'Content-Type':'application/json', 'Accept':'application/json' } }).then(r => r.json()).then(d => { fbrEnabled = !!d.enabled; fbrLoading = false; window.tnNotify && window.tnNotify(window.TXT.fbr_reporting, fbrEnabled ? window.TXT.enabled_word : window.TXT.disabled_word); }).catch(() => { fbrLoading = false; alert(window.TXT.toggle_failed); })"
                                :disabled="fbrLoading"
                                :class="fbrEnabled ? 'bg-blue-600' : 'bg-gray-400 dark:bg-gray-600'"
                                class="relative inline-flex h-5 w-10 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out shadow-inner">
                                <span :class="fbrEnabled ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                            </button>
                            <span x-text="fbrEnabled ? 'ON' : 'OFF'" :class="fbrEnabled ? 'text-blue-700 dark:text-blue-300' : 'text-gray-500 dark:text-gray-400'" class="text-[10px] font-black w-7"></span>
                            <span x-show="fbrLoading" class="text-[10px] text-blue-500 animate-pulse">…</span>
                        </div>
                    </div>

                    {{-- Auto-Print — device-level localStorage pref, same handler as the mobile strip --}}
                    <div class="flex items-center justify-between gap-2" title="{{ __('pos.ti_auto_print_hint') }}">
                        <span class="text-[10px] uppercase tracking-wider font-extrabold text-emerald-700 dark:text-emerald-300">{{ __('pos.auto_print_label') }}</span>
                        <div class="flex items-center gap-1.5">
                            <button type="button"
                                @click="autoPrintEnabled = !autoPrintEnabled; kitchenSettings.print_on_pay = autoPrintEnabled; try { localStorage.setItem('fbrpos_auto_print', autoPrintEnabled ? '1' : '0'); } catch(e) {} window.tnNotify && window.tnNotify(window.TXT.auto_print_receipt, autoPrintEnabled ? window.TXT.enabled_word : window.TXT.disabled_word)"
                                :class="autoPrintEnabled ? 'bg-emerald-600' : 'bg-gray-400 dark:bg-gray-600'"
                                class="relative inline-flex h-5 w-10 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out shadow-inner">
                                <span :class="autoPrintEnabled ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                            </button>
                            <span x-text="autoPrintEnabled ? 'ON' : 'OFF'" :class="autoPrintEnabled ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-500 dark:text-gray-400'" class="text-[10px] font-black w-7"></span>
                        </div>
                    </div>

                    @if($features->kot ?? false)
                    {{-- Auto-KOT — same handler as the mobile strip (root autoKotEnabled) --}}
                    <div class="flex items-center justify-between gap-2" title="{{ __('pos.fbr_ti_auto_store_slip_hint') }}" x-data="{ autoKotLoading: false }">
                        <span class="text-[10px] uppercase tracking-wider font-extrabold text-orange-700 dark:text-orange-300">{{ __('pos.fbr_auto_store_slip_label') }}</span>
                        <div class="flex items-center gap-1.5">
                            <button type="button"
                                @click="autoKotLoading = true; fetch('{{ route('fbrpos.api.toggle-auto-kot') }}', { method:'POST', headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}', 'Content-Type':'application/json', 'Accept':'application/json' } }).then(r => r.json()).then(d => { if (d.success) { autoKotEnabled = !!d.enabled; window.tnNotify && window.tnNotify(window.TXT.fbr_auto_store_slip, autoKotEnabled ? window.TXT.enabled_word : window.TXT.disabled_word); } else { alert(d.message || window.TXT.toggle_failed); } autoKotLoading = false; }).catch(() => { autoKotLoading = false; alert(window.TXT.toggle_failed); })"
                                :disabled="autoKotLoading"
                                :class="autoKotEnabled ? 'bg-orange-600' : 'bg-gray-400 dark:bg-gray-600'"
                                class="relative inline-flex h-5 w-10 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out shadow-inner">
                                <span :class="autoKotEnabled ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                            </button>
                            <span x-text="autoKotEnabled ? 'ON' : 'OFF'" :class="autoKotEnabled ? 'text-orange-700 dark:text-orange-300' : 'text-gray-500 dark:text-gray-400'" class="text-[10px] font-black w-7"></span>
                            <span x-show="autoKotLoading" class="text-[10px] text-orange-500 animate-pulse">…</span>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </template>

    {{-- FBR Reporting + Auto-Print toggles strip — MOBILE FALLBACK ONLY (md:hidden) since the
         Aug 2026 PRA-parity redesign moved these switches into the top-nav dropdown on desktop.
         autoPrintEnabled lives on the parent restaurantPos() scope (mirrors kitchenSettings.print_on_pay)
         so toggling immediately updates the receipt-iframe URL on the very next sale, no refresh needed. --}}
    {{-- ⚠️ OFFLINE-LOCKED NOTICE (Aug 2026 — PRA port): shows ONLY when the shop is
         offline AND the plan does not allow offline billing, so cashiers know this is
         a package limit, not a bug. Admin/owner get an upgrade link to billing;
         cashiers get "ask your admin" text instead. Amber (warning), not red. --}}
    <div x-cloak x-show="syncStatus === 'offline' && !offlineAllowed && !offlineLockDismissed"
         class="flex items-start gap-3 px-4 py-2.5 bg-amber-50 dark:bg-amber-900/30 border-b border-amber-300 dark:border-amber-700 flex-shrink-0">
        <svg class="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
        <div class="flex-1 min-w-0">
            <p class="text-[13px] font-bold text-amber-800 dark:text-amber-200">{{ __('pos.offline_locked_title') }}</p>
            <p class="text-[12px] text-amber-700 dark:text-amber-300">{{ __('pos.offline_locked_body') }}
                @if(auth('fbrpos')->user()?->isPosCashier())
                    <span class="font-semibold">{{ __('pos.offline_locked_ask_admin') }}</span>
                @endif
            </p>
        </div>
        @unless(auth('fbrpos')->user()?->isPosCashier())
        <a href="{{ route('fbrpos.billing') }}" class="flex-shrink-0 px-3 py-1.5 rounded-lg text-[12px] font-bold bg-amber-600 hover:bg-amber-700 text-white transition self-center">{{ __('pos.offline_locked_upgrade') }}</a>
        @endunless
        <button type="button" @click="offlineLockDismissed = true" class="flex-shrink-0 p-1 rounded-lg text-amber-700 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-800/40 transition self-center" title="{{ __('pos.dismiss') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <div class="flex md:hidden items-center justify-end gap-4 px-3 py-1.5 bg-blue-50 dark:bg-blue-900/10 border-b border-blue-100 dark:border-blue-900/30 flex-shrink-0"
         x-data="{
            autoPrintLoading: false,
            autoKotLoading: false
         }">

        {{-- FBR Reporting --}}
        <div class="flex items-center gap-2">
            <span class="text-[10px] uppercase tracking-wider font-extrabold text-blue-700 dark:text-blue-300">{{ __('pos.fbr_reporting') }}</span>
            <button type="button"
                @click="fbrLoading = true; fetch('{{ route('fbrpos.api.toggle-fbr-reporting') }}', { method:'POST', headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}', 'Content-Type':'application/json', 'Accept':'application/json' } }).then(r => r.json()).then(d => { fbrEnabled = !!d.enabled; fbrLoading = false; window.tnNotify && window.tnNotify(window.TXT.fbr_reporting, fbrEnabled ? window.TXT.enabled_word : window.TXT.disabled_word); }).catch(() => { fbrLoading = false; alert(window.TXT.toggle_failed); })"
                :disabled="fbrLoading"
                :class="fbrEnabled ? 'bg-blue-600' : 'bg-gray-400 dark:bg-gray-600'"
                class="relative inline-flex h-5 w-10 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out shadow-inner">
                <span :class="fbrEnabled ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5"></span>
            </button>
            <span x-text="fbrEnabled ? 'ON' : 'OFF'" :class="fbrEnabled ? 'text-blue-700 dark:text-blue-300' : 'text-gray-500 dark:text-gray-400'" class="text-[10px] font-black w-7"></span>
            <span x-show="fbrLoading" class="text-[10px] text-blue-500 animate-pulse">…</span>
        </div>

        <div class="w-px h-4 bg-blue-200 dark:bg-blue-800/40"></div>

        {{-- Auto-Print on Sale (Phase 4) — bound to parent restaurantPos() scope --}}
        <div class="flex items-center gap-2" title="{{ __('pos.ti_auto_print_hint') }}">
            <span class="text-[10px] uppercase tracking-wider font-extrabold text-emerald-700 dark:text-emerald-300">{{ __('pos.auto_print_label') }}</span>
            <button type="button"
                @click="autoPrintEnabled = !autoPrintEnabled; kitchenSettings.print_on_pay = autoPrintEnabled; try { localStorage.setItem('fbrpos_auto_print', autoPrintEnabled ? '1' : '0'); } catch(e) {} window.tnNotify && window.tnNotify(window.TXT.auto_print_receipt, autoPrintEnabled ? window.TXT.enabled_word : window.TXT.disabled_word)"
                :disabled="autoPrintLoading"
                :class="autoPrintEnabled ? 'bg-emerald-600' : 'bg-gray-400 dark:bg-gray-600'"
                class="relative inline-flex h-5 w-10 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out shadow-inner">
                <span :class="autoPrintEnabled ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5"></span>
            </button>
            <span x-text="autoPrintEnabled ? 'ON' : 'OFF'" :class="autoPrintEnabled ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-500 dark:text-gray-400'" class="text-[10px] font-black w-7"></span>
            <span x-show="autoPrintLoading" class="text-[10px] text-emerald-500 animate-pulse">…</span>
        </div>

        @if($features->kot ?? false)
        <div class="w-px h-4 bg-blue-200 dark:bg-blue-800/40"></div>

        {{-- Auto-KOT (Phase 5+) — when ON, the kitchen ticket print dialog also pops
             open right after a successful payment of a held/restaurant order. --}}
        <div class="flex items-center gap-2" title="{{ __('pos.fbr_ti_auto_store_slip_hint') }}">
            <span class="text-[10px] uppercase tracking-wider font-extrabold text-orange-700 dark:text-orange-300">{{ __('pos.fbr_auto_store_slip_label') }}</span>
            <button type="button"
                @click="autoKotLoading = true; fetch('{{ route('fbrpos.api.toggle-auto-kot') }}', { method:'POST', headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}', 'Content-Type':'application/json', 'Accept':'application/json' } }).then(r => r.json()).then(d => { if (d.success) { autoKotEnabled = !!d.enabled; window.tnNotify && window.tnNotify(window.TXT.fbr_auto_store_slip, autoKotEnabled ? window.TXT.enabled_word : window.TXT.disabled_word); } else { alert(d.message || window.TXT.toggle_failed); } autoKotLoading = false; }).catch(() => { autoKotLoading = false; alert(window.TXT.toggle_failed); })"
                :disabled="autoKotLoading"
                :class="autoKotEnabled ? 'bg-orange-600' : 'bg-gray-400 dark:bg-gray-600'"
                class="relative inline-flex h-5 w-10 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out shadow-inner">
                <span :class="autoKotEnabled ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5"></span>
            </button>
            <span x-text="autoKotEnabled ? 'ON' : 'OFF'" :class="autoKotEnabled ? 'text-orange-700 dark:text-orange-300' : 'text-gray-500 dark:text-gray-400'" class="text-[10px] font-black w-7"></span>
            <span x-show="autoKotLoading" class="text-[10px] text-orange-500 animate-pulse">…</span>
        </div>
        @endif
    </div>

    {{-- ═══════════ GUIDED FLOW STEP INDICATOR (opt-in, default OFF) ═══════════ --}}
    {{-- Display-only coach strip. Highlights the current flowStep. Never blocks clicks. --}}
    @php
        // Order types available for THIS company — mirrors the header Dine In / Takeaway /
        // Delivery buttons (dine_in gated on tables, delivery gated on delivery, takeaway
        // always). Drives the guided Order-Type step: shown only when 2+ types exist, so
        // pure single-type retail stays byte-identical (no pointless one-option step).
        $guidedTypes = [];
        if ($features->tables) $guidedTypes[] = 'dine_in';
        $guidedTypes[] = 'takeaway';
        if ($features->delivery) $guidedTypes[] = 'delivery';
        $hasTypeStep = count($guidedTypes) > 1;
    @endphp
    {{-- Coach strip REMOVED (owner, 30 Jul 2026): payment-flow bar ki zaroorat nahi, screen bara ho.
         Guided Enter-chain behavior itself is untouched; the hasTypeStep block above still feeds the Order-Type overlay below. --}}

    {{-- ═══════════ GUIDED FLOW: ORDER-TYPE STEP (opt-in) ═══════════ --}}
    {{-- Owner-specified keyboard step BETWEEN Items and Cart. Reached by pressing Enter on an
         empty search box (cart already has items) when 2+ order types exist. Arrow keys move the
         highlight (handled in handleKey), Enter confirms + drops into cart, Esc returns to search. --}}
    @if($hasTypeStep)
    <div x-cloak x-show="guidedFlow && flowStep === 'type'" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md p-6 border border-gray-100 dark:border-gray-800">
            <div class="text-center mb-5">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ __('pos.order_type') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">&uarr; &darr; select &middot; Enter confirm &middot; Esc back</p>
            </div>
            <div class="space-y-2">
                <template x-for="(k, i) in guidedOrderTypes()" :key="k">
                    <button type="button" @click="flowTypeIndex = i; confirmGuidedType()"
                        :class="flowTypeIndex === i ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/30 ring-2 ring-emerald-500 text-emerald-800 dark:text-emerald-200' : 'border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800'"
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-xl border-2 text-left font-bold transition">
                        <span :class="flowTypeIndex === i ? 'bg-emerald-600 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400'" class="w-7 h-7 flex items-center justify-center rounded-full text-sm flex-shrink-0" x-text="i + 1"></span>
                        <span x-text="guidedTypeLabel(k)"></span>
                        <span x-show="flowTypeIndex === i" class="ml-auto text-emerald-600 dark:text-emerald-400 text-xs">Enter</span>
                    </button>
                </template>
            </div>
        </div>
    </div>
    @endif

    {{-- 2-ROW ACTION BAR (Retail Fast Billing — Aug 2026)
         Row 1: Customer · order type · utils · nav shortcuts
         Row 2: Category dropdown + WIDE barcode/scan search + Hold F5 --}}
    <div class="flex flex-col bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 flex-shrink-0 shadow-sm">
    {{-- ── ROW 1 ── --}}
    <div class="flex flex-wrap items-center gap-2 px-3 py-2">

        <div class="relative flex-1" style="min-width:300px;">
            <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
            <input type="search" x-ref="customerPhoneInput" x-model="customerPhoneQuery" @input="onCustomerPhoneInput()" @keydown.enter.prevent="if(!$event.repeat) onCustomerPhoneEnter()" @keydown.down.prevent="custNav(1)" @keydown.up.prevent="custNav(-1)" @keydown.escape.prevent="customerPhoneDropdown = false" @keydown.tab.prevent="$refs.searchInput?.focus()" @click.away="customerPhoneDropdown = false" placeholder="{{ __('pos.ph_customer_name_mobile') }}" class="w-full pl-9 pr-7 py-2.5 rounded-xl text-sm border-2 transition shadow-sm" :class="selectedCustomer ? 'font-bold border-blue-400 dark:border-blue-600 bg-blue-50 dark:bg-blue-900/20 text-blue-800 dark:text-blue-200' : 'font-medium border-blue-200 dark:border-blue-800 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-400'" autocomplete="one-time-code" name="pos_customer_phone_nofill" data-lpignore="true" data-form-type="other">
            <kbd x-show="!customerPhoneQuery && !selectedCustomer && !customerSearching" class="absolute right-2 top-1/2 -translate-y-1/2 text-[8px] text-gray-400 bg-gray-100 dark:bg-gray-700 px-1 py-0.5 rounded font-mono">Alt+P</kbd>
            {{-- Inline search spinner --}}
            <svg x-show="customerSearching && !selectedCustomer" x-cloak class="absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 text-blue-500 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <button x-show="(customerPhoneQuery || selectedCustomer) && !customerSearching" @click="clearCustomerInput()" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-red-500 transition">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div x-show="customerPhoneDropdown && customerPhoneResults.length > 0 && !showNewCustomerInline" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-2xl z-50 max-h-52 overflow-y-auto" style="min-width:280px;">
                <template x-for="(cr, ci) in customerPhoneResults" :key="cr.id">
                    {{-- Item #2 mirror (owner, Jul 2026): ↑↓ arrow-key navigation, Enter picks
                         the highlighted row (custHiIndex), same as the PRA universal screen. --}}
                    <button @click="selectCustomerFromPhone(cr)" @mouseenter="custHiIndex = ci" :data-cust-row="ci" class="w-full flex items-center gap-2 px-3 py-2.5 text-left hover:bg-blue-50 dark:hover:bg-blue-900/20 transition border-b border-gray-50 dark:border-gray-800" :class="ci === custHiIndex ? 'bg-blue-100 dark:bg-blue-900/30' : ''">
                        <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0"><span class="text-xs font-bold text-blue-600" x-text="cr.name.charAt(0)"></span></div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-semibold text-gray-900 dark:text-white truncate" x-text="cr.name"></p>
                            <p class="text-[10px] text-gray-400" x-text="cr.phone + (cr.stats ? ' • ' + cr.stats.total_orders + window.TXT.sfx_orders_rs2 + Number(cr.stats.total_spent).toLocaleString() : '')"></p>
                            <template x-if="cr.address"><p class="text-[9px] text-gray-400 truncate" x-text="cr.address"></p></template>
                        </div>
                        <template x-if="cr.stats && cr.stats.is_frequent"><span class="freq-badge">VIP</span></template>
                    </button>
                </template>
            </div>

            {{-- Inline "no match → quick add" hint (NO popup, INLINE only) --}}
            <div x-show="customerPhoneDropdown && !showNewCustomerInline && customerPhoneResults.length === 0 && isPhoneLike(customerPhoneQuery) && !customerSearching" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-blue-200 dark:border-blue-800 rounded-xl shadow-2xl z-50 overflow-hidden" style="min-width:280px;">
                <button @click="openInlineNewCustomer()" class="w-full flex items-center gap-2 px-3 py-2.5 text-left hover:bg-blue-50 dark:hover:bg-blue-900/20 transition">
                    <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-bold text-blue-700 dark:text-blue-300">{{ __('pos.add_new_customer') }}</p>
                        <p class="text-[10px] text-gray-500" x-text="customerPhoneQuery + window.TXT.press_enter_sfx"></p>
                    </div>
                </button>
            </div>

            {{-- Inline new-customer quick form (NO popup) --}}
            <div x-show="showNewCustomerInline" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-900 border-2 border-blue-400 dark:border-blue-600 rounded-xl shadow-2xl z-50 p-3 space-y-2" style="min-width:300px;" @keydown.escape.prevent="cancelInlineNewCustomer()">
                <div class="flex items-center justify-between">
                    <p class="text-[10px] font-bold text-blue-600 dark:text-blue-400 uppercase tracking-wider">{{ __('pos.new_customer_btn') }}</p>
                    <button type="button" @click="cancelInlineNewCustomer()" class="text-gray-400 hover:text-red-500 text-[10px] font-semibold">{{ __('pos.cancel') }}</button>
                </div>
                <div class="text-[10px] font-semibold text-gray-600 dark:text-gray-400 px-2 py-1.5 bg-gray-100 dark:bg-gray-800 rounded-lg">
                    <span class="text-gray-400">{{ __('pos.mobile_label') }}</span> <span class="text-gray-900 dark:text-white font-bold" x-text="newCustomerPhone"></span>
                </div>
                <input type="text" x-ref="newCustomerNameInput" x-model="newCustomerName"
                    autocomplete="one-time-code" name="pos_newcust_name_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                    @keydown.enter.prevent="$refs.newCustomerAddressInput?.focus()"
                    placeholder="Customer name *"
                    class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-400">
                <input type="text" x-ref="newCustomerAddressInput" x-model="newCustomerAddress"
                    autocomplete="one-time-code" name="pos_newcust_addr_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                    @keydown.enter.prevent="saveNewCustomer()"
                    placeholder="Address (optional)"
                    class="w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-400">
                <button type="button" @click="saveNewCustomer()" :disabled="savingCustomer" class="w-full py-2 text-xs font-bold text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-60 transition">
                    <span x-show="!savingCustomer">{{ __('pos.save_select_enter') }}</span>
                    <span x-show="savingCustomer">{{ __('pos.saving_ellipsis') }}</span>
                </button>
            </div>
        </div>

        {{-- Delivery-only order-type toggle (6 Aug 2026): TAKEAWAY is the default/silent mode;
             only show a badge + toggle when delivery feature is on OR when delivery is active.
             Manual item button lives here too (moved from Row 2). --}}
        @if($features->delivery ?? false)
        <div class="flex items-center gap-1 flex-shrink-0">
            <button @click="orderType = (orderType === 'delivery' ? 'takeaway' : 'delivery')"
                    class="flex items-center gap-1 px-2.5 py-2 rounded-xl text-xs font-bold border transition"
                    :class="orderType === 'delivery' ? 'bg-purple-100 dark:bg-purple-900/30 border-purple-300 dark:border-purple-700 text-purple-700 dark:text-purple-300' : 'border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800'"
                    title="{{ __('pos.delivery') }} / {{ __('pos.takeaway') }} (F2)">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
                <span x-text="orderType === 'delivery' ? '{{ __('pos.delivery') }}' : '{{ __('pos.takeaway') }}'"></span>
            </button>
        </div>
        @endif
        {{-- Manual button moved to top-nav teleport (owner, 6 Aug 2026) — Row 1 = customer (full width, matches Row 2 scan box) + delivery toggle only --}}

    </div>{{-- /ROW 1 --}}

    {{-- ── ROW 2: Category + WIDE scan search + Hold ── --}}
    <div class="flex items-center gap-2 px-3 pb-2 pt-0">

        {{-- CATEGORY DROPDOWN (optional filter) — same activeCategory as the grid pills, so the two
             stay in sync. Default "All Categories" = old behavior, byte-identical. Unlike the pills
             it is ALWAYS visible (even when the grid is hidden), so a chosen category is never an
             invisible/stale filter — search deliberately narrows to it. Hidden automatically when
             the company has no categories/services to pick (FBR products currently ship category=null,
             so this stays hidden until categories exist — kept for PRA-port diffability). --}}
        <div class="relative flex-shrink-0 hidden sm:block" x-show="catOptions().length > 0 || allServices.length > 0" x-cloak>
            <select x-model="activeCategory" title="{{ __('pos.ti_category_fbr') }}"
                    class="appearance-none pl-3 pr-8 py-2.5 rounded-xl text-xs font-bold border-2 cursor-pointer max-w-[150px] shadow-sm transition focus:ring-2 focus:ring-blue-500 focus:border-blue-400"
                    :class="activeCategory !== 'all' ? 'border-blue-400 bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300'">
                <option value="all">{{ __('pos.all_categories') }}</option>
                <template x-for="c in catOptions()" :key="c"><option :value="c" x-text="c"></option></template>
                <template x-if="allServices.length > 0"><option value="services">{{ __('pos.services') }}</option></template>
            </select>
            <svg class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </div>

        <div class="flex-1 relative" style="min-width:170px;">
            {{-- Barcode/scan icon (retail fast-billing Aug 2026) --}}
            <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h1v12H4zm3 0h1v12H7zm3 0h2v12h-2zm4 0h1v12h-1zm3 0h1v12h-1zM2 4h20v2H2zm0 14h20v2H2z"/></svg>
            <input type="search" x-ref="searchInput" x-model="searchQuery" @input="onSearchInput()" @keydown.arrow-down.prevent="moveHighlight(1)" @keydown.arrow-up.prevent="moveHighlight(-1)" @keydown.enter.prevent.stop="addHighlightedItem($event)" @keydown.tab="if(flowStep === 'type'){ $event.preventDefault(); } else if(!searchQuery && cart.length > 0){ $event.preventDefault(); enterCartMode('last'); }" @focus="if(searchQuery) showSearchDropdown = true" @click.away="showSearchDropdown = false" placeholder="{{ __('pos.ph_scan_or_first_letter') }}" class="search-glow w-full pl-10 pr-10 py-2.5 rounded-xl text-sm font-medium border-2 border-blue-400 dark:border-blue-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-400 transition shadow-sm" autocomplete="one-time-code" name="pos_product_search_nofill" data-lpignore="true" data-form-type="other" role="combobox">
            <kbd x-show="!searchQuery" class="absolute right-3 top-1/2 -translate-y-1/2 text-[9px] text-gray-400 bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5 rounded border border-gray-200 dark:border-gray-600 font-mono">Ctrl+S</kbd>
            <button x-show="searchQuery" @click="searchQuery = ''; showSearchDropdown = false; filterProducts(); $refs.searchInput.focus()" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            {{-- Smart Product Creation — empty-state.
                 SIMPLE MODE (inventory OFF): inline "+ Create '<name>' (Enter)" creates product on the fly.
                 INVENTORY MODE (inventory ON): "Open Products" button — never auto-creates. --}}
            <div x-show="searchQuery.trim().length > 0 && searchSuggestions.length === 0 && !quickCreating" x-transition
                 class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-2xl z-50 overflow-hidden">
                <template x-if="!isInventoryEnabled()">
                    <button type="button" @click="quickCreateProduct()"
                        class="w-full flex items-center gap-3 px-3 py-3 text-left hover:bg-blue-50 dark:hover:bg-blue-900/20 transition group">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center bg-gradient-to-br from-blue-500 to-blue-700 text-white flex-shrink-0 shadow">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.create_q_prefix') }}<span x-text="searchQuery"></span>"</p>
                            <p class="text-[10px] text-gray-400">{{ __('pos.qc_fill_details_hint') }}</p>
                        </div>
                        <span class="text-[9px] font-mono bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 px-1.5 py-0.5 rounded border border-blue-200 dark:border-blue-800">⏎</span>
                    </button>
                </template>
                <template x-if="isInventoryEnabled()">
                    <div class="px-3 py-3">
                        <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ __('pos.product_not_found') }}</p>
                        <p class="text-[10px] text-gray-400 mb-2">{{ __('pos.inventory_mode_products_hint') }}</p>
                        <a href="{{ route('fbrpos.products') }}" class="inline-flex items-center gap-1.5 text-[11px] font-bold text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            {{ __('pos.open_products') }}
                        </a>
                    </div>
                </template>
            </div>
            <div x-show="quickCreating" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-blue-200 rounded-xl shadow-2xl z-50 px-3 py-3">
                <p class="text-xs text-gray-500 flex items-center gap-2">
                    <svg class="w-4 h-4 animate-spin text-blue-600" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                    {{ __('pos.creating_q_prefix') }}<span x-text="searchQuery" class="font-semibold"></span>"…
                </p>
            </div>
            <div x-show="showSearchDropdown && searchSuggestions.length > 0" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-2xl z-50 max-h-64 overflow-y-auto" x-ref="searchDropdown">
                <template x-for="(s, i) in searchSuggestions" :key="s.id + s.type">
                    <button @click="quickAddItem(s)" @mouseenter="highlightIndex = i"
                        :data-hl="i === highlightIndex ? 'true' : 'false'"
                        class="w-full flex items-center gap-3 px-3 py-2.5 text-left"
                        :style="i === highlightIndex ? 'background:#2563eb !important; border-radius:10px; margin:2px 4px; width:calc(100% - 8px); box-shadow:0 4px 12px rgba(37,99,235,0.4);' : 'margin:2px 4px; width:calc(100% - 8px);'">
                        <template x-if="s.image">
                            <img :src="s.image" class="w-8 h-8 rounded-lg object-cover flex-shrink-0" :style="i === highlightIndex ? 'outline:2px solid white; outline-offset:1px;' : ''">
                        </template>
                        <template x-if="!s.image">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
                                :style="i === highlightIndex ? 'background:white; color:#2563eb;' : 'background:linear-gradient(135deg,#dbeafe,#e0e7ff); color:#2563eb;'">
                                <span class="text-xs font-bold" x-text="s.name.charAt(0)"></span>
                            </div>
                        </template>
                        <div class="flex-1 min-w-0">
                            <span class="text-sm font-semibold truncate block" :style="i === highlightIndex ? 'color:white;' : 'color:#1f2937;'" x-text="s.name"></span>
                            <div class="flex items-center gap-1.5">
                                <span class="text-[10px]" :style="i === highlightIndex ? 'color:rgba(255,255,255,0.7);' : 'color:#9ca3af;'" x-text="s.type === 'service' ? window.TXT.service_word : s.category"></span>
                                @if($company->inventory_enabled)
                                <template x-if="s.stockStatus && s.stockStatus !== 'available'"><span class="stock-dot" :class="'stock-' + s.stockStatus"></span></template>
                                @endif
                            </div>
                        </div>
                        <span class="text-sm font-extrabold" :style="i === highlightIndex ? 'color:white;' : 'color:#1d4ed8;'" x-text="'Rs. ' + Number(s.price).toLocaleString()"></span>
                    </button>
                </template>
            </div>
        </div>

        @if($features->tables)
        <button @click="showTablePicker = true" class="flex items-center gap-1.5 px-2.5 py-2 rounded-lg text-xs font-semibold border transition flex-shrink-0" :class="selectedTable ? 'bg-blue-50 dark:bg-blue-900/20 border-blue-300 dark:border-blue-700 text-blue-700 dark:text-blue-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800'">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            <span x-text="selectedTable ? 'T-' + selectedTable.table_number : 'Table'"></span>
        </button>
        @endif

        {{-- Order-type cluster removed from Row 2 (owner, 6 Aug 2026): delivery toggle in Row 1.
             Fit, Keys F1, Quick F7 moved to top-nav teleport.
             Urgent (rush) removed. Manual moved to Row 1. Order-type cluster removed. --}}

        <button @click="newSale()" class="flex md:hidden items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 hover:bg-green-100 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            <span class="hidden sm:inline">{{ __('pos.new_word') }}</span>
        </button>

        {{-- REPRINT Alt+R — reprints the last finalized bill instantly (no modal).
             Stays hidden until a bill has been processed in this session. --}}
        <button x-show="recentBills.length > 0 || lastTransactionId" x-cloak
                @click="const last = recentBills[0]; if(last) { _printViaIframe('print-receipt-frame', '/fbr-pos/transaction/' + last.id + '/receipt?auto_print=1', 'width=400,height=700'); showToast('Reprinting #' + last.invoice_number, 'info'); } else if(lastTransactionId) { printReceipt(); }"
                class="flex md:hidden items-center gap-1 px-2.5 py-2 rounded-xl text-xs font-bold text-gray-600 dark:text-gray-300 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 hover:bg-blue-50 hover:text-blue-700 hover:border-blue-300 transition flex-shrink-0"
                title="Reprint last bill (Alt+R)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            <span class="hidden lg:inline">Reprint</span>
            <kbd class="text-[8px] font-mono bg-gray-200 dark:bg-gray-700 px-1 rounded hidden sm:inline">Alt+R</kbd>
        </button>

        {{-- ── PROVISIONAL BILLS (Local) — header shortcut. Same pattern as Held. ── --}}
        {{-- 🟢/🟡/🔴 Auto-Sync status pill — live network + pending-bill indicator.
             Click = manual offline-queue sync (Aug 2026 offline billing). --}}
        <button type="button" @click="syncOfflineBills(true)" class="flex md:hidden items-center gap-1.5 px-2.5 py-1.5 rounded-xl text-[11px] font-bold border transition"
             :class="syncStatus === 'online' ? 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800' : (syncStatus === 'syncing' ? 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800' : 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 border-red-200 dark:border-red-800')"
             :title="offlineNeedsLogin ? window.TXT.ti_session_expired_sync : (syncStatus === 'online' ? (window.TXT.ti_auto_sync_online + ((failedBills.length + offlineQueueCount) ? ' · ' + (failedBills.length + offlineQueueCount) + window.TXT.ti_pending_click_sync : '')) : (syncStatus === 'syncing' ? window.TXT.ti_syncing_pending_fbr : window.TXT.ti_offline_auto_sync_fbr))">
            <span class="w-2 h-2 rounded-full"
                  :class="syncStatus === 'online' ? 'bg-emerald-500' : (syncStatus === 'syncing' ? 'bg-amber-500 animate-pulse' : 'bg-red-500 animate-pulse')"></span>
            <span x-text="syncStatus === 'online' ? window.TXT.online : (syncStatus === 'syncing' ? window.TXT.syncing_word : window.TXT.offline)"></span>
            <span x-show="(failedBills.length + offlineQueueCount) > 0" class="ml-0.5 px-1.5 rounded-full text-[9px] font-black"
                  :class="syncStatus === 'online' ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white'"
                  x-text="failedBills.length + offlineQueueCount"></span>
        </button>
        {{-- Click → modal with Edit / Delete / Make Final actions inline. F10 shortcut. --}}
        <button @click="openLocalBills()" class="relative flex md:hidden items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-blue-700 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 hover:bg-blue-100 transition" title="{{ __('pos.ti_provisional_f10_fbr') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
            <span class="text-[10px] bg-blue-400/30 px-1 rounded">F10</span>
            <span class="hidden sm:inline">Local</span>
            <span x-show="localBills.length > 0" class="absolute -top-1 -right-1 min-w-[20px] h-5 px-1 bg-blue-600 text-white text-[10px] rounded-full flex items-center justify-center font-bold" x-text="localBills.length"></span>
        </button>

        {{-- Pending Deliveries (Task 122, FBR port of PRA Task 114) — today's delivery
             provisionals, one-click Final (Cash/Card). Badge auto-hides when empty. --}}
        <button x-show="pendingDeliveryBills().length > 0 || staleDeliveryBills().length > 0" x-cloak @click="openPendingDeliveries()" class="relative flex md:hidden items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 hover:bg-amber-100 transition" title="{{ __('pos.pending_deliveries_hint') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
            <span class="hidden sm:inline">{{ __('pos.pending_deliveries') }}</span>
            <span x-show="pendingDeliveryBills().length > 0" class="absolute -top-1 -right-1 min-w-[20px] h-5 px-1 bg-amber-600 text-white text-[10px] rounded-full flex items-center justify-center font-bold" x-text="pendingDeliveryBills().length"></span>
        </button>

        {{-- Delivery Board — mobile button (Aug 2026) --}}
        @if($showDeliveriesBoardBtn)
        <button type="button" onclick="tnOpenDeliveryBoard()" class="flex md:hidden items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 hover:bg-emerald-100 transition" title="{{ __('pos.deliveries') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
            <span class="hidden sm:inline">{{ __('pos.deliveries') }}</span>
        </button>
        @endif

        {{-- Quick Return (Task 685) — mobile copy of the nav-strip button --}}
        <button @click="openQuickReturn()" class="flex md:hidden items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-rose-700 dark:text-rose-400 bg-rose-50 dark:bg-rose-900/20 border border-rose-200 dark:border-rose-800 hover:bg-rose-100 transition" title="{{ __('pos.quick_return_hint') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 15v-1a4 4 0 00-4-4H8m0 0l3 3m-3-3l3-3m9 14V5a2 2 0 00-2-2H6a2 2 0 00-2 2v16l4-2 4 2 4-2 4 2z"/></svg>
            <span class="hidden sm:inline">{{ __('pos.quick_return') }}</span>
        </button>

        {{-- ── FAILED BILLS — header shortcut. F11. Red theme = needs attention. ── --}}
        {{-- Click → modal with Retry / Edit / Delete actions inline. --}}
        <button @click="openFailedBills()" class="relative flex md:hidden items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 hover:bg-red-100 transition" title="{{ __('pos.ti_failed_fbr_f11') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span class="text-[10px] bg-red-400/30 px-1 rounded">F11</span>
            <span class="hidden sm:inline">{{ __('pos.failed_word_html') }}</span>
            <span x-show="failedBills.length > 0" class="absolute -top-1 -right-1 min-w-[20px] h-5 px-1 bg-red-600 text-white text-[10px] rounded-full flex items-center justify-center font-bold animate-pulse" x-text="failedBills.length"></span>
        </button>

        <button @click="activeHeldIndex = 0; showHeldOrders = !showHeldOrders" class="relative flex md:hidden items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 hover:bg-amber-100 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="text-[10px] bg-amber-400/30 px-1 rounded">F3</span>
            <span class="hidden sm:inline">{{ __('pos.held') }}</span>
            <span x-show="heldOrders.length > 0" class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-[10px] rounded-full flex items-center justify-center font-bold" x-text="heldOrders.length"></span>
        </button>

        {{-- Toolbar Hold F5 / Pay F8 buttons REMOVED (owner, 6 Aug 2026): cart footer
             already has Hold F5 + PAY F8 — top copies were duplicates. F5/F8 keyboard
             shortcuts unchanged (global keydown handler). Send to Kitchen stays (KOT). --}}
        @if($features->kot ?? false)
        <div class="hidden md:flex items-center gap-1.5">
            <button @click="sendToKitchen()" :disabled="cart.length === 0 || submitting || hasManualItems()" :title="hasManualItems() ? window.TXT.ti_manual_pay_first_cart : window.TXT.fbr_ti_store_saves_no_payment" class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold bg-orange-500 hover:bg-orange-600 text-white disabled:opacity-40 disabled:cursor-not-allowed shadow-sm transition">
                <span class="text-base leading-none">🍳</span>
                <span x-text="submitting ? window.TXT.sending_ellipsis : window.TXT.fbr_send_to_store"></span>
                <kbd class="text-[9px] bg-orange-700/40 px-1.5 py-0.5 rounded font-mono flex-shrink-0">Alt+K</kbd>
            </button>
        </div>
        @endif
    </div>{{-- /ROW 2 --}}
    </div>{{-- /flex-col action bar --}}

    {{-- Wide-cart (Variant A) port from PRA screen (owner, 30 Jul 2026): Products OFF
         + desktop = body row flips to column, grid hides, cart goes wide LEFT with a
         400px payment column RIGHT (.tn-cart-side = the existing footer block). --}}
    <div class="tn-body-row flex flex-1 overflow-hidden" :class="!showProducts ? 'tn-widecart' : ''">

        <div class="tn-left-col flex-1 flex flex-col overflow-hidden" :class="mobileView === 'menu' ? 'flex' : 'hidden md:flex'">

            <div class="flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-900 border-b border-gray-100 dark:border-gray-800 flex-shrink-0">
                <div class="flex items-center gap-2 overflow-x-auto hide-scrollbar flex-1 min-w-0">
                    <button @click="activeCategory = 'all'; filterProducts()" x-show="showProducts" class="cat-pill px-4 py-1.5 rounded-full text-xs font-semibold border" :class="activeCategory === 'all' ? 'active border-transparent' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-800'">
                        {{ __('pos.all_word') }} <span class="ml-1 text-[10px] opacity-70" x-text="'(' + (allProducts.filter(p => isItemVisible(p)).length + allServices.length) + ')'"></span>
                    </button>
                    {{-- Task 1271: grid-edit banner (Roman Urdu — customer-facing) --}}
                    <template x-if="gridEditMode">
                        <span class="text-[11px] font-semibold text-blue-700 dark:text-blue-300 px-1 whitespace-nowrap">{{ __('pos.tap_item_hide_show') }}</span>
                    </template>
                    @foreach($categories as $cat)
                    <button @click="activeCategory = '{{ $cat }}'; filterProducts()" x-show="showProducts" class="cat-pill px-4 py-1.5 rounded-full text-xs font-semibold border" :class="activeCategory === '{{ $cat }}' ? 'active border-transparent' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-800'">{{ $cat }}</button>
                    @endforeach
                    <button @click="activeCategory = 'services'; filterProducts()" x-show="showProducts" class="cat-pill px-4 py-1.5 rounded-full text-xs font-semibold border" :class="activeCategory === 'services' ? 'active border-transparent' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-800'">{{ __('pos.services') }}</button>
                    <span x-show="!showProducts" class="text-[11px] text-gray-400 dark:text-gray-500 italic px-1 whitespace-nowrap">{{ __('pos.grid_hidden_hint') }}</span>
                </div>
                {{-- Task 1271: "Sab Wapas Dikhao" — resets ALL of this user's grid prefs (edit mode only) --}}
                <button type="button" x-show="gridEditMode && hiddenPrefCount > 0" x-cloak @click="resetGridPrefs()" :disabled="gridPrefBusy"
                        class="flex-shrink-0 px-2.5 py-1.5 rounded-full text-[11px] font-bold border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 transition disabled:opacity-50">
                    {{ __('pos.show_all_again') }}
                </button>
                {{-- Task 1271: PER-USER grid edit chip (PRA port) — ALL roles; each user
                     hides/shows PRODUCTS on their OWN grid only. Search never affected. --}}
                <button type="button" @click="gridEditMode = !gridEditMode; filterProducts(); if (!gridEditMode) syncAutoWidecart()"
                        class="flex-shrink-0 flex items-center gap-1.5 px-2.5 py-1.5 rounded-full text-[11px] font-bold border transition"
                        :class="gridEditMode ? 'bg-blue-600 border-blue-600 text-white' : 'bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300'"
                        :title="gridEditMode ? window.TXT.ti_grid_edit_done : window.TXT.ti_grid_edit_start">
                    <svg x-show="!gridEditMode" class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    <svg x-show="gridEditMode" x-cloak class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    <span x-text="gridEditMode ? window.TXT.done_word : window.TXT.grid_arrange" class="whitespace-nowrap"></span>
                </button>
                {{-- MASTER products toggle — ab inventory mode mein BHI (owner, 30 Jul 2026):
                     Products OFF sirf grid chhupata hai, search se catalog items add hote rehte
                     hain, is liye billing brick nahi hoti. Wide-cart split isi par chalta hai. --}}
                <button type="button" @click="toggleShowProducts()" role="switch" :aria-checked="showProducts ? 'true' : 'false'"
                        class="flex-shrink-0 flex items-center gap-1.5 px-2.5 py-1.5 rounded-full text-[11px] font-bold border transition"
                        :class="showProducts ? 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300' : 'bg-gray-100 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400'"
                        :title="showProducts ? window.TXT.ti_show_products_on : window.TXT.ti_show_products_off">
                    <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    <span x-text="showProducts ? window.TXT.products_word : window.TXT.products_off" class="whitespace-nowrap"></span>
                    <span class="relative inline-flex h-4 w-7 items-center rounded-full transition flex-shrink-0" :class="showProducts ? 'bg-emerald-600' : 'bg-gray-400 dark:bg-gray-600'">
                        <span class="inline-block h-3 w-3 transform rounded-full bg-white transition" :class="showProducts ? 'translate-x-3.5' : 'translate-x-0.5'"></span>
                    </span>
                </button>
                
            </div>

            <div x-ref="gridContainer" tabindex="0" @keydown.arrow-right.prevent="moveGridFocus(1)" @keydown.arrow-left.prevent="moveGridFocus(-1)" @keydown.arrow-down.prevent="moveGridFocus(gridCols)" @keydown.arrow-up.prevent="moveGridFocus(-gridCols)" @keydown.enter.prevent="addGridFocusedItem()" class="flex-1 overflow-y-auto p-3 outline-none">

                <template x-if="loading">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                        <template x-for="i in 12"><div class="rounded-xl overflow-hidden flex items-center gap-2 px-2.5 py-2.5 bg-white dark:bg-gray-900 border border-gray-100 dark:border-gray-800"><div class="skeleton w-8 h-8 rounded-lg flex-shrink-0"></div><div class="flex-1 space-y-1.5"><div class="skeleton h-3 rounded w-3/4"></div><div class="skeleton h-2.5 rounded w-1/3"></div></div></div></template>
                    </div>
                </template>

                {{-- ═══ COMPACT PRODUCT LIST (7 Aug 2026 — PRA universal redesign port, owner
                     approved via video note: "cards naye apply nahi hue... update karo") ═══
                     Big image cards replaced by dense 2-column text rows: tiny thumb (only when a
                     real image exists), name + badges, price, cart-qty badge, + button. Same
                     handleProductClick / gridFocus / stock-out semantics — calcGridCols reads the
                     rendered grid so arrow-key navigation adapts automatically. Class names
                     .prod-card / .price-badge / .cart-qty-badge / .quick-add / .stock-out kept
                     (CSS + tests rely on them). Task 1271: gridEditMode ported (per-user grid prefs). --}}
                <template x-if="!loading">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                        <template x-for="(item, idx) in displayItems" :key="item.id + '-' + item.type">
                            {{-- Task 1271: gridEditMode (PRA port) — click toggles THIS user's visibility pref; hidden items dim to 40%. --}}
                            <div :id="'grid-item-' + idx" class="prod-card flex items-center gap-2.5 px-2.5 py-2 bg-white dark:bg-gray-900 rounded-xl border border-gray-100 dark:border-gray-800 shadow-sm fade-in cursor-pointer hover:border-blue-300 dark:hover:border-blue-700 transition" :class="[gridFocusMode && gridFocusIndex === idx ? 'ring-2 ring-blue-500' : '', gridEditMode ? '' : (item.stockStatus === 'out' && blockOutOfStock ? 'stock-out' : (item.stockStatus === 'out' && !blockOutOfStock ? 'stock-out allow-add' : '')), gridEditMode && !isItemVisible(item) ? 'opacity-40' : '']" @click="gridEditMode ? toggleItemVisibility(item) : handleProductClick(item)">
                                <template x-if="item.image">
                                    <img :src="item.image" :alt="item.name" class="w-9 h-9 rounded-lg object-cover flex-shrink-0" loading="lazy" onerror="this.style.display='none';">
                                </template>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-1.5 min-w-0">
                                        <p class="text-sm font-bold text-gray-900 dark:text-white truncate leading-tight" x-text="item.name"></p>
                                        @if($company->inventory_enabled)
                                        <template x-if="item.stockStatus === 'low'"><span class="stock-dot stock-low flex-shrink-0" title="{{ __('pos.ti_low_stock') }}"></span></template>
                                        <template x-if="item.stockStatus === 'out'"><span class="px-1.5 py-0.5 bg-red-500/90 text-white text-[8px] font-bold rounded-md flex-shrink-0">OUT</span></template>
                                        <template x-if="item.hasRecipe"><span class="text-[10px] flex-shrink-0" title="Recipe">&#x1F373;</span></template>
                                        @endif
                                        <template x-if="item.is_tax_exempt && !item.is_third_schedule"><span class="px-1.5 py-0.5 bg-green-500/90 text-white text-[8px] font-bold rounded-md flex-shrink-0">NO TAX</span></template>
                                        <template x-if="item.is_third_schedule"><span class="px-1.5 py-0.5 bg-blue-500/90 text-white text-[8px] font-bold rounded-md flex-shrink-0">3rd Sch</span></template>
                                    </div>
                                </div>
                                <span class="price-badge text-sm font-extrabold text-blue-600 dark:text-blue-400 flex-shrink-0" x-text="'Rs. ' + Number(item.price).toLocaleString()"></span>
                                <template x-if="getCartQty(item) > 0">
                                    <span class="cart-qty-badge text-[10px] bg-gradient-to-br from-blue-500 to-blue-700 text-white w-6 h-6 rounded-full flex items-center justify-center font-bold shadow-sm flex-shrink-0" x-text="getCartQty(item)"></span>
                                </template>
                                <button @click.stop="gridEditMode ? toggleItemVisibility(item) : handleProductClick(item)" class="quick-add w-7 h-7 rounded-full text-white flex items-center justify-center shadow-sm transition-all flex-shrink-0" :class="gridEditMode ? (isItemVisible(item) ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-gray-400 hover:bg-gray-500') : 'bg-blue-600 hover:bg-blue-700'">
                                    <svg x-show="!gridEditMode" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                                    {{-- Edit mode: open eye = visible, slashed eye = hidden (this user only) --}}
                                    <svg x-show="gridEditMode && isItemVisible(item)" x-cloak class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    <svg x-show="gridEditMode && !isItemVisible(item)" x-cloak class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                                </button>
                            </div>
                        </template>
                    </div>
                </template>

                <template x-if="!loading && displayItems.length === 0">
                    <div class="tn-empty flex flex-col items-center justify-center py-24 px-6 text-gray-400 text-center">
                        <div class="tn-empty-icon w-28 h-28 rounded-full bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center mb-5">
                            <svg class="w-14 h-14 text-blue-400 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/></svg>
                        </div>
                        <p class="text-lg font-bold text-gray-700 dark:text-gray-200" x-text="showProducts ? window.TXT.no_products_match : window.TXT.products_grid_off"></p>
                        <p class="text-sm mt-1.5 text-gray-400 dark:text-gray-500 max-w-[280px]" x-text="showProducts ? window.TXT.try_different_category : window.TXT.products_toggle_off_hint"></p>
                        <button @click="restoreProductGrid()" class="mt-5 px-5 py-2.5 text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-xl shadow-sm">{{ __('pos.show_all_products') }}</button>
                    </div>
                </template>

                <template x-if="!loading && filteredItems.length > displayCount">
                    <div class="flex justify-center py-4">
                        <button @click="loadMore()" class="px-6 py-2.5 text-sm font-semibold text-blue-600 bg-blue-50 dark:bg-blue-900/20 rounded-xl hover:bg-blue-100 transition border border-blue-200 dark:border-blue-800">
                            {{ __('pos.load_more_prefix') }}<span x-text="filteredItems.length - displayCount"></span> remaining)
                        </button>
                    </div>
                </template>
            </div>

            {{-- ── AKHRI BILLS STRIP (Aug 2026 — Retail Fast Billing) ─────────────────────
                 One-click reprint chips for the last 5 finalized bills in this session.
                 Hidden until at least one bill is done. Alt+R always reprints recentBills[0]. --}}
            <div x-show="recentBills.length > 0" x-cloak class="hidden md:flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-900 border-t border-gray-100 dark:border-gray-800 flex-shrink-0">
                <span class="text-[9px] font-black uppercase tracking-wider text-gray-300 dark:text-gray-600 whitespace-nowrap flex-shrink-0">AKHRI BILLS</span>
                <div class="flex items-center gap-1.5 overflow-x-auto hide-scrollbar">
                    <template x-for="(b, bi) in recentBills" :key="b.id">
                        <button @click="_printViaIframe('print-receipt-frame', '/fbr-pos/transaction/' + b.id + '/receipt?auto_print=1', 'width=400,height=700'); showToast('Reprinting #' + b.invoice_number, 'info')"
                                class="flex-shrink-0 flex items-center gap-1.5 h-7 px-2.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 hover:bg-blue-50 dark:hover:bg-blue-900/20 hover:border-blue-300 dark:hover:border-blue-700 transition"
                                :title="'Reprint ' + b.invoice_number + ' — Rs. ' + Number(b.total).toLocaleString()">
                            <svg class="w-3 h-3 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                            <span class="text-[10px] font-semibold text-gray-700 dark:text-gray-300" x-text="b.invoice_number"></span>
                            <span class="text-[9px] text-gray-400" x-text="'Rs.' + Number(b.total).toLocaleString()"></span>
                            <span x-show="bi === 0" class="text-[8px] font-bold text-blue-500 ml-0.5">Alt+R</span>
                        </button>
                    </template>
                </div>
            </div>

            <div class="md:hidden flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800">
                {{-- Pending Deliveries badge — mobile (Task 122) --}}
                <button x-show="pendingDeliveryBills().length > 0 || staleDeliveryBills().length > 0" x-cloak @click="openPendingDeliveries()" class="relative flex items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 hover:bg-amber-100 transition" title="{{ __('pos.pending_deliveries_hint') }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
                    <span x-show="pendingDeliveryBills().length > 0" class="absolute -top-1 -right-1 min-w-[20px] h-5 px-1 bg-amber-600 text-white text-[10px] rounded-full flex items-center justify-center font-bold" x-text="pendingDeliveryBills().length"></span>
                </button>
                <button @click="mobileView = 'cart'" class="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl bg-blue-600 text-white text-sm font-bold shadow-sm">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                    {{ __('pos.cart') }}
                    <span x-show="cart.length > 0" class="bg-white/20 px-1.5 rounded-full text-xs" x-text="cart.length"></span>
                    <span x-show="cart.length > 0" class="text-xs opacity-80" x-text="'Rs. ' + Number(roundedTotal).toLocaleString()"></span>
                </button>
            </div>

            <button x-show="cart.length > 0 && !cartMode" @click="enterCartMode(); mobileView = 'cart';"
                class="pos-edit-cart-floating-btn"
                style="position:fixed; bottom:24px; right:400px; z-index:60; background:linear-gradient(135deg,#2563eb,#6d28d9); color:white; border:none; border-radius:16px; padding:10px 20px; font-size:13px; font-weight:700; cursor:pointer; box-shadow:0 8px 24px rgba(37,99,235,0.4), 0 2px 8px rgba(0,0,0,0.15); display:flex; align-items:center; gap:8px; transition:all 0.2s;"
                x-transition
                title="{{ __('pos.ti_jump_cart_f6') }}"
                @mouseenter="$el.style.transform='scale(1.05)'; $el.style.boxShadow='0 12px 32px rgba(37,99,235,0.5)'"
                @mouseleave="$el.style.transform='scale(1)'; $el.style.boxShadow='0 8px 24px rgba(37,99,235,0.4)'">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                <span>{{ __('pos.edit_cart') }}</span>
                <span style="background:rgba(255,255,255,0.25); padding:2px 8px; border-radius:8px; font-size:11px; font-weight:800;" x-text="cart.length"></span>
                <span style="font-size:10px; opacity:0.7; margin-left:2px;" x-text="'Rs.' + Number(roundedTotal).toLocaleString()"></span>
                <span style="background:rgba(255,255,255,0.15); padding:2px 6px; border-radius:6px; font-size:9px; font-weight:700; letter-spacing:0.5px; border:1px solid rgba(255,255,255,0.25);">F6</span>
            </button>
        </div>

        <div class="tn-cart-col w-full md:w-[300px] lg:w-[340px] xl:w-[380px] bg-white dark:bg-gray-900 border-l border-gray-200 dark:border-gray-800 flex flex-col flex-shrink-0 shadow-xl" :class="mobileView === 'cart' ? 'flex' : 'hidden md:flex'">
            <div class="tn-cart-main">
            <div class="flex items-center gap-2 px-3 py-2.5 border-b border-gray-100 dark:border-gray-800">
                <button @click="mobileView = 'menu'" class="md:hidden p-1.5 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                <span class="text-sm font-bold text-gray-900 dark:text-white flex-1">{{ __('pos.current_order') }}</span>
                <button x-show="cart.length > 0" @click="enterCartMode()" class="flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-bold transition-all"
                    :style="cartMode ? 'background:#7c3aed; color:white; box-shadow:0 2px 8px rgba(124,58,237,0.3);' : 'background:#f3e8ff; color:#7c3aed;'"
                    :title="cartMode ? window.TXT.ti_cart_mode_on : window.TXT.ti_enter_cart_mode">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    <span x-text="cartMode ? window.TXT.editing_word : window.TXT.edit"></span>
                </button>
                <template x-if="priorityOrder"><span class="text-[10px] bg-red-100 text-red-600 px-2 py-0.5 rounded-full font-bold">URGENT</span></template>
                <span class="text-[10px] bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 px-2 py-0.5 rounded-full font-semibold" x-text="orderType.replace('_', ' ').toUpperCase()"></span>
                <template x-if="selectedTable">
                    <span class="text-[10px] bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 px-2 py-0.5 rounded-full font-semibold" x-text="'T-' + selectedTable.table_number"></span>
                </template>
            </div>

            <template x-if="selectedCustomer">
                <div class="px-3 py-2 bg-purple-50 dark:bg-purple-900/10 border-b border-purple-100 dark:border-purple-900/20 flex items-start gap-2">
                    <div class="w-8 h-8 rounded-full bg-purple-200 dark:bg-purple-800 flex items-center justify-center flex-shrink-0 mt-0.5"><span class="text-xs font-bold text-purple-700 dark:text-purple-300" x-text="selectedCustomer.name.charAt(0)"></span></div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-1.5">
                            <p class="text-xs font-semibold text-purple-800 dark:text-purple-200 truncate" x-text="selectedCustomer.name"></p>
                            <template x-if="customerStats && customerStats.is_frequent"><span class="freq-badge">VIP</span></template>
                        </div>
                        <p class="text-[10px] text-purple-600 dark:text-purple-400" x-text="selectedCustomer.phone || window.TXT.no_phone"></p>
                        {{-- Retail Core (Aug 2026): udhaar balance badge — cashier sees baqaya at a glance --}}
                        <p x-show="(selectedCustomer.khata_balance || 0) > 0" x-cloak class="text-[10px] font-black text-red-600 dark:text-red-400">
                            Udhaar: Rs <span x-text="Number(selectedCustomer.khata_balance || 0).toLocaleString()"></span>
                        </p>
                        <template x-if="selectedCustomer.address">
                            <p class="text-[10px] text-purple-500 dark:text-purple-400 truncate" x-text="'📍 ' + selectedCustomer.address"></p>
                        </template>
                        {{-- Task 163 (PRA parity): delivery-address picker — Delivery orders only.
                             Saved addresses (address #1 + extras) in a dropdown; "+ New" saves an
                             extra address to the customer AND selects it for this bill. --}}
                        <template x-if="orderType === 'delivery'">
                            <div class="mt-1 space-y-1">
                                <div class="flex items-center gap-1">
                                    <select x-model="selectedDeliveryAddress" class="flex-1 min-w-0 text-sm font-medium rounded-md border-purple-200 dark:border-purple-800 dark:bg-gray-800 dark:text-white py-1.5 px-2 focus:ring-purple-500 focus:border-purple-400">
                                        <option value="">{{ __('pos.delivery_address_divider') }}</option>
                                        <template x-for="(a, ai) in customerAddresses" :key="a.id ?? ('t' + ai)">
                                            <option :value="a.address" x-text="(a.label ? a.label + ': ' : '') + a.address"></option>
                                        </template>
                                    </select>
                                    <button x-show="selectedDeliveryAddress && customerAddresses.some(a => a.address === selectedDeliveryAddress)" @click="deleteSelectedAddress()" title="{{ __('pos.ti_delete_address') }}" class="text-xs font-bold text-red-500 dark:text-red-400 px-2 py-1.5 rounded-md border border-red-200 dark:border-red-800 hover:bg-red-50 dark:hover:bg-red-900/30 whitespace-nowrap">✕</button>
                                    <button @click="showAddrNew = !showAddrNew; if (showAddrNew) $nextTick(() => document.getElementById('tnNewAddrInput')?.focus())" class="text-xs font-bold text-purple-600 dark:text-purple-300 px-2 py-1.5 rounded-md border border-purple-200 dark:border-purple-800 hover:bg-purple-100 dark:hover:bg-purple-900/30 whitespace-nowrap">{{ __('pos.new_short') }}</button>
                                </div>
                                <div x-show="showAddrNew" x-cloak class="flex items-center gap-1">
                                    <input id="tnNewAddrLabel" type="text" x-model="newAddrLabel" @keydown.enter.prevent="saveNewAddress()" @keydown.escape.prevent="showAddrNew = false" placeholder="{{ __('pos.ph_addr_label') }}" autocomplete="off" name="pos_new_addr_label_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-24 flex-none text-sm rounded-md border-purple-200 dark:border-purple-800 dark:bg-gray-800 dark:text-white py-1.5 px-2 focus:ring-purple-500 focus:border-purple-400">
                                    <input id="tnNewAddrInput" type="text" x-model="newAddrText" @keydown.enter.prevent="saveNewAddress()" @keydown.escape.prevent="showAddrNew = false" placeholder="{{ __('pos.ph_full_delivery_address') }}" autocomplete="off" name="pos_new_addr_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore class="flex-1 min-w-0 text-sm rounded-md border-purple-200 dark:border-purple-800 dark:bg-gray-800 dark:text-white py-1.5 px-2 focus:ring-purple-500 focus:border-purple-400">
                                    <button @click="saveNewAddress()" class="text-xs font-bold text-white bg-purple-600 hover:bg-purple-700 px-2 py-1.5 rounded-md">{{ __('pos.save_btn') }}</button>
                                </div>
                            </div>
                        </template>
                        <template x-if="customerStats">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 mt-0.5">
                                {{-- Clickable (owner request, 1 Aug 2026 — matches PRA universal): opens the customer history modal --}}
                                <button type="button" @click="if (selectedCustomer?.id) loadCustomerHistory(selectedCustomer.id)" class="text-[10px] font-semibold text-purple-700 dark:text-purple-300 underline decoration-dotted underline-offset-2 hover:text-purple-900 dark:hover:text-purple-100" x-text="(customerStats.total_orders || 0) + window.TXT.sfx_orders" title="{{ __('pos.ti_view_history') }}"></button>
                                <span class="text-[10px] text-gray-400">•</span>
                                <span class="text-[10px] font-semibold text-purple-700 dark:text-purple-300" x-text="'Rs. ' + Number(customerStats.total_spent || 0).toLocaleString() + window.TXT.sfx_spent"></span>
                                <template x-if="customerStats.last_order_date">
                                    <span class="text-[10px] text-gray-400">•</span>
                                </template>
                                <template x-if="customerStats.last_order_date">
                                    <span class="text-[10px] text-purple-600 dark:text-purple-400" x-text="window.TXT.last_colon + customerStats.last_order_date"></span>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            {{-- Task 163: walk-in delivery (no selected customer) — plain one-off
                 address input; snapshots on the bill without saving anywhere. --}}
            <template x-if="orderType === 'delivery' && !selectedCustomer">
                <div class="px-3 py-2 bg-purple-50 dark:bg-purple-900/10 border-b border-purple-100 dark:border-purple-900/20 flex items-center gap-2">
                    <span class="text-xs flex-shrink-0">📍</span>
                    <input type="text" x-model="selectedDeliveryAddress" placeholder="{{ __('pos.ph_full_delivery_address') }}" autocomplete="off" name="pos_walkin_addr_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore class="flex-1 min-w-0 text-sm rounded-md border-purple-200 dark:border-purple-800 dark:bg-gray-800 dark:text-white py-1.5 px-2 focus:ring-purple-500 focus:border-purple-400">
                </div>
            </template>

            <div class="flex-1 min-h-0 overflow-y-auto" x-ref="cartList">
                <template x-if="cart.length === 0">
                    <div class="tn-empty flex flex-col items-center justify-center h-full text-gray-400 py-16 px-6 text-center">
                        <div class="tn-empty-icon w-24 h-24 rounded-full bg-purple-100 dark:bg-purple-900/40 flex items-center justify-center mb-5">
                            <svg class="w-12 h-12 text-purple-400 dark:text-purple-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                        </div>
                        <p class="text-base font-bold text-gray-700 dark:text-gray-200">{{ __('pos.your_cart_is_empty') }}</p>
                        <p class="text-xs mt-1.5 text-gray-400 dark:text-gray-500 max-w-[220px]">{{ __('pos.tap_product_hint') }}</p>
                    </div>
                </template>
                <template x-if="cartMode && cart.length > 0">
                    <div style="background:linear-gradient(90deg,#7c3aed,#6d28d9); padding:6px 12px; display:flex; align-items:center; gap:8px;">
                        <svg class="w-3.5 h-3.5" style="color:white; flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        <span style="color:rgba(255,255,255,0.9); font-size:10px; font-weight:600;">{{ __('pos.cart_keys_hint') }}</span>
                    </div>
                </template>
                <template x-for="(item, index) in cart" :key="item.cart_uid">
                    <div class="cart-item cart-item-enter px-3 py-2.5 cursor-pointer relative"
                        :class="activeCartIndex === index ? 'cart-row-active' : ''"
                        @click="selectCartRow(index)" :data-cart-index="index">
                        <div class="flex items-center gap-2.5">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-gray-900 dark:text-white truncate flex items-center gap-1.5">
                                    <span class="truncate" x-text="item.item_name"></span>
                                    <template x-if="item._isQuickCreated">
                                        <span class="flex-shrink-0 whitespace-nowrap text-[8px] font-bold uppercase tracking-wider text-purple-700 bg-purple-100 dark:bg-purple-900/30 dark:text-purple-300 px-1.5 py-0.5 rounded">{{ __('pos.no_recipe') }}</span>
                                    </template>
                                </p>
                                {{-- Inline price editor moved OUT of this narrow column to a full-width
                                     panel below the flex row (Aug 2026 overlap fix) — see below. --}}
                                <template x-if="quickPriceCartUid !== item.cart_uid">
                                    <p class="text-[11px] text-gray-400 mt-0.5 truncate">
                                        <span x-text="'Rs. ' + Number(item.unit_price).toLocaleString() + window.TXT.per_unit_sfx"></span>
                                        <template x-if="item._isQuickCreated && Number(item.unit_price) === 0">
                                            <button @click.stop="openQuickPrice(item)" class="ml-1 text-purple-600 hover:underline font-semibold">{{ __('pos.set_price') }}</button>
                                        </template>
                                    </p>
                                </template>
                            </div>
                            <div class="flex items-center gap-0.5 bg-gray-100 dark:bg-gray-800 rounded-xl p-0.5">
                                <button @click.stop="updateQty(index, -1)" class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-white dark:hover:bg-gray-700 text-gray-600 dark:text-gray-400 transition active:scale-90 shadow-sm hover:shadow">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" d="M20 12H4"/></svg>
                                </button>
                                <input type="text" inputmode="decimal" autocomplete="off"
                                    data-qty-input
                                    :data-qty-row="index"
                                    x-init="$el.value = item.quantity"
                                    x-effect="if (document.activeElement !== $el) { $el.value = item.quantity; }"
                                    @click.stop="onQtyFocus(index, $event)"
                                    @mousedown.stop
                                    @focus.stop="onQtyFocus(index, $event)"
                                    @keydown="onQtyKeydown(index, $event)"
                                    @input.stop="onQtyInput(index, $event)"
                                    @blur="onQtyBlur(index, $event)"
                                    class="w-16 h-10 text-center text-lg font-extrabold bg-white dark:bg-gray-900 text-gray-900 dark:text-white border-0 rounded-lg focus:ring-2 focus:ring-purple-500 shadow-inner px-1">
                                <button @click.stop="updateQty(index, 1)" class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-white dark:hover:bg-gray-700 text-gray-600 dark:text-gray-400 transition active:scale-90 shadow-sm hover:shadow">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" d="M12 4v16m8-8H4"/></svg>
                                </button>
                                {{-- Retail Core (Aug 2026): unit chip — weight/measure units (KG/LTR/MTR...)
                                     visible so the cashier knows the qty box takes decimals (0.5, 1.25). --}}
                                <span x-show="item.uom && item.uom !== 'U' && item.uom !== 'PCS'" x-cloak
                                      class="text-[9px] font-black uppercase px-1 py-0.5 rounded bg-indigo-50 dark:bg-indigo-900/30 text-indigo-500 dark:text-indigo-300"
                                      x-text="item.uom"></span>
                            </div>
                            <div class="text-right min-w-[60px]">
                                <p class="text-sm font-extrabold text-gray-900 dark:text-white" x-text="'Rs.' + getItemTotal(item).toLocaleString()"></p>
                            </div>
                            <button @click.stop="removeFromCart(index)" class="p-1.5 text-red-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition active:scale-90">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                        {{-- Inline price editor (quick-created / zero-price row) — FULL-WIDTH panel (Aug 2026):
                             inside the narrow name column the Rs. input + hint collided with qty/total on
                             small widths & zoomed screens ("words mix ho rahe"). Same handlers/refs as before. --}}
                        <template x-if="quickPriceCartUid === item.cart_uid">
                            <div class="flex items-center gap-2 mt-1.5 px-2 py-1.5 rounded-lg bg-purple-50 dark:bg-purple-900/20" @click.stop>
                                <span class="text-[11px] font-bold text-purple-700 dark:text-purple-300 whitespace-nowrap">Rs.</span>
                                <input type="number" min="0" step="any" x-ref="quickPriceInput" data-quick-price-input
                                    x-model.number="quickPriceValue"
                                    @keydown.enter.prevent="saveQuickPrice(index, true)"
                                    @keydown.escape.prevent="cancelQuickPrice()"
                                    @blur="saveQuickPrice(index)"
                                    placeholder="{{ __('pos.ph_enter_price') }}"
                                    class="w-24 flex-shrink-0 text-sm font-bold bg-white dark:bg-gray-800 border-2 border-purple-300 dark:border-purple-700 rounded-md px-2 py-1 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:outline-none">
                                <span class="text-[10px] text-gray-400 truncate min-w-0">{{ __('pos.save_esc_hint') }}</span>
                            </div>
                        </template>
                        {{-- Cart rows v3 (Aug 2026): TAX/Disc/FBR only visible on active row — cart stays clean --}}
                        <div class="flex items-center gap-1.5 mt-1.5 justify-end" x-show="activeCartIndex === index || item.is_tax_exempt || (item.item_discount_value || 0) > 0 || item.showFbrPanel || item.hs_code">
                            <button @click.stop="item.is_tax_exempt = !item.is_tax_exempt" class="text-[11px] font-extrabold px-2 py-1 rounded-md transition whitespace-nowrap ring-1" :class="item.is_tax_exempt ? 'bg-green-500 text-white ring-green-600 shadow-sm' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 ring-gray-300 dark:ring-gray-600 hover:ring-green-500 hover:text-green-600'" :title="item.is_tax_exempt ? window.TXT.ti_tax_exempt_toggle : window.TXT.ti_tax_toggle_hint" x-text="item.is_tax_exempt ? window.TXT.no_tax_t : window.TXT.tax_t"></button>
                            <button @click.stop="item.showItemDiscount = !item.showItemDiscount" class="text-[9px] font-bold px-1.5 py-1 rounded-md transition whitespace-nowrap" :class="(item.item_discount_value || 0) > 0 ? 'bg-orange-100 text-orange-600' : 'bg-gray-100 dark:bg-gray-700 text-gray-400 hover:text-orange-500'" x-text="(item.item_discount_value || 0) > 0 ? ((item.item_discount_type || 'percentage') === 'percentage' ? '-' + item.item_discount_value + '%' : '-Rs.' + item.item_discount_value) : 'Disc'"></button>
                            <button @click.stop="item.showFbrPanel = !item.showFbrPanel" title="{{ __('pos.ti_fbr_compliance') }}" class="text-[9px] font-bold px-1.5 py-1 rounded-md transition whitespace-nowrap" :class="item.showFbrPanel ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' : ((item.hs_code || (item.uom && item.uom !== 'U')) ? 'bg-blue-50 text-blue-500 dark:bg-blue-900/20 dark:text-blue-400' : 'bg-gray-100 dark:bg-gray-700 text-gray-400 hover:text-blue-500')">FBR</button>
                        </div>
                        <div x-show="item.showItemDiscount" x-transition class="mt-1 flex items-center gap-1">
                            <button @click.stop="item.item_discount_type = 'percentage'" class="text-[9px] font-bold px-1.5 py-0.5 rounded transition" :class="(item.item_discount_type || 'percentage') === 'percentage' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-400'">%</button>
                            <button @click.stop="item.item_discount_type = 'amount'" class="text-[9px] font-bold px-1.5 py-0.5 rounded transition" :class="item.item_discount_type === 'amount' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-400'">Rs</button>
                            <input type="number" x-model.lazy.number="item.item_discount_value" :data-discount-input="index"
                                @click.stop
                                @keydown.enter.prevent.stop="$event.target.blur()"
                                @keydown.escape.prevent.stop="$event.target.blur()"
                                min="0" step="any" placeholder="0" class="dense-input w-14 text-[10px] bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded px-1.5 py-0.5 text-gray-900 dark:text-white focus:ring-purple-500">
                            <button @click.stop="item.item_discount_value = 0; item.showItemDiscount = false" class="text-[9px] text-red-400 hover:text-red-600 px-1">X</button>
                        </div>
                        {{-- 🧾 FBR compliance drawer — HS code / UoM / tax % per line (store() field names) --}}
                        <div x-show="item.showFbrPanel" x-transition class="mt-1 p-1.5 rounded-lg bg-blue-50/60 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-900/30 flex flex-wrap items-center gap-1" @click.stop>
                            <input type="text" x-model="item.hs_code" placeholder="{{ __('pos.ph_hs_code') }}" maxlength="20"
                                autocomplete="off" name="pos_hs_code_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                                @keydown.enter.prevent.stop="$event.target.blur()" @keydown.escape.prevent.stop="$event.target.blur()"
                                class="dense-input w-20 text-[10px] font-mono bg-white dark:bg-gray-900 border border-blue-200 dark:border-blue-800 rounded px-1.5 py-0.5 text-gray-900 dark:text-white focus:ring-blue-500">
                            <select x-model="item.uom" @click.stop title="{{ __('pos.ti_uom') }}" class="dense-input text-[10px] bg-white dark:bg-gray-900 border border-blue-200 dark:border-blue-800 rounded px-1 py-0.5 text-gray-900 dark:text-white focus:ring-blue-500">
                                <template x-for="u in uomOptions" :key="u"><option :value="u" x-text="u"></option></template>
                            </select>
                            <div class="flex items-center gap-0.5" title="{{ __('pos.ti_tax_rate_pct') }}">
                                <input type="number" x-model.number="item.tax_rate" min="0" max="100" step="any" placeholder="18"
                                    :disabled="item.is_tax_exempt"
                                    @keydown.enter.prevent.stop="$event.target.blur()" @keydown.escape.prevent.stop="$event.target.blur()"
                                    class="dense-input w-12 text-[10px] bg-white dark:bg-gray-900 border border-blue-200 dark:border-blue-800 rounded px-1.5 py-0.5 text-gray-900 dark:text-white focus:ring-blue-500 disabled:opacity-40">
                                <span class="text-[9px] text-blue-400 font-bold">%</span>
                            </div>
                            <span x-show="item.is_tax_exempt && !item.is_third_schedule" class="text-[8px] text-green-600 font-bold uppercase">{{ __('pos.exempt') }}</span>
                            <span x-show="item.is_third_schedule" class="text-[8px] text-blue-600 font-bold uppercase">3rd Sch</span>
                        </div>
                        @if($features->kitchen_notes)
                        {{-- Per-item kitchen note (e.g. "no onions") — parity with restaurant screen, gated by kitchen_notes feature --}}
                        <div class="mt-1" @click.stop>
                            <input type="text" x-model="item.special_notes"
                                autocomplete="one-time-code" name="pos_item_note_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                                @keydown.enter.prevent.stop="$event.target.blur()"
                                @keydown.escape.prevent.stop="$event.target.blur()"
                                placeholder="{{ __('pos.ph_item_note') }}"
                                class="dense-input w-full text-[10px] bg-amber-50/60 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-900/30 rounded-md px-2 py-1 text-gray-600 dark:text-gray-300 focus:ring-amber-400 placeholder-gray-300">
                        </div>
                        @endif
                    </div>
                </template>
            </div>
            </div>{{-- closes .tn-cart-main --}}

            <div class="tn-cart-side border-t border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/80 backdrop-blur-sm">
                {{-- 7 Aug 2026 — PRA universal redesign port (owner video note): always-open
                     notes textarea + inline discount strip replaced by one slim Note/Discount
                     chip row; both panels collapsible. kitchenNotes model unchanged. --}}
                <div class="px-3 py-1 flex items-center justify-end gap-1.5">
                    <button @click="showCartNote = !showCartNote; if (showCartNote) $nextTick(() => { const el = $refs.orderNotesInput; if (el) el.focus(); })" class="shrink-0 text-[10px] font-bold px-2.5 py-1.5 rounded-lg border transition" :class="(kitchenNotes || '').length > 0 ? 'bg-amber-100 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800' : 'bg-gray-100 dark:bg-gray-800 text-gray-500 border-gray-200 dark:border-gray-700 hover:bg-gray-200'">
                        <span x-text="(kitchenNotes || '').length > 0 ? '\u270E Note \u2713' : '\u270E Note'"></span>
                    </button>
                    <button @click="showDiscount = !showDiscount" class="shrink-0 text-[10px] font-bold px-2.5 py-1.5 rounded-lg border transition" :class="discountAmount > 0 ? 'bg-orange-100 dark:bg-orange-900/20 text-orange-600 border-orange-200 dark:border-orange-800' : 'bg-gray-100 dark:bg-gray-800 text-gray-500 border-gray-200 dark:border-gray-700 hover:bg-gray-200'">
                        <span x-text="discountAmount > 0 ? window.TXT.discount_minus_rs + Number(discountAmount).toLocaleString() : window.TXT.plus_discount"></span>
                    </button>
                    <span class="text-[8px] text-gray-400" x-text="window.TXT.limit_colon + effectiveDiscountLimit + '%'"></span>
                    <button x-show="!managerOverrideActive && hasManagerPin && posRole !== 'pos_admin'" @click="requestManagerOverride()" class="text-[8px] font-bold text-blue-600 hover:text-blue-800 px-1">{{ __('pos.override') }}</button>
                    <span x-show="managerOverrideActive" class="text-[8px] font-bold text-green-600 px-1">{{ __('pos.unlocked') }}</span>
                </div>
                <div class="px-3 pb-1.5" x-show="showCartNote" x-transition x-cloak>
                    <textarea x-model="kitchenNotes" x-ref="orderNotesInput" rows="2"
                        autocomplete="one-time-code" name="pos_order_notes_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                        @keydown.enter.prevent.stop="$event.target.blur()"
                        @keydown.escape.prevent.stop="showCartNote = false"
                        placeholder="{{ __('pos.ph_order_notes_n') }}"
                        class="w-full text-xs bg-amber-50/60 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-900/30 rounded-lg px-2.5 py-1.5 text-gray-700 dark:text-gray-300 focus:ring-amber-400 focus:border-amber-400 resize-y placeholder-gray-400"></textarea>
                </div>
                <div class="px-3 pb-1.5">
                    <div x-show="showDiscount" x-transition class="p-2 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl space-y-1.5">
                        <div class="flex gap-1">
                            <button @click="discountType = 'percentage'" class="flex-1 text-[10px] font-bold py-1 rounded-lg transition" :class="discountType === 'percentage' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-500'">%</button>
                            <button @click="discountType = 'amount'" class="flex-1 text-[10px] font-bold py-1 rounded-lg transition" :class="discountType === 'amount' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-500'">Rs.</button>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <input type="number" x-model.number="discountValue" @input="if(!checkDiscountLimit(discountValue, discountType)) { discountValue = discountType === 'percentage' ? effectiveDiscountLimit : effectiveSubtotal; showToast(discountType === 'percentage' ? window.TXT.discount_capped_at + effectiveDiscountLimit + '%' : 'Discount cannot exceed subtotal', 'error'); } recalcDiscount()" min="0" :max="discountType === 'percentage' ? effectiveDiscountLimit : effectiveSubtotal" step="any" :placeholder="discountType === 'percentage' ? window.TXT.ph_max_pfx + effectiveDiscountLimit + '%' : window.TXT.ph_direct_amount" class="flex-1 text-xs bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg px-2 py-1.5 text-gray-900 dark:text-white focus:ring-purple-500">
                            <button @click="discountValue = 0; recalcDiscount(); showDiscount = false" class="text-[10px] text-red-500 hover:text-red-700 px-1.5">{{ __('pos.clear') }}</button>
                        </div>
                        <div x-show="discountType === 'percentage'" class="flex gap-1 flex-wrap">
                            <template x-for="q in [5, 10, 15, 20, 25, 30, 40, 50].filter(v => v <= effectiveDiscountLimit)" :key="'pct-' + q">
                                <button @click="discountType = 'percentage'; discountValue = q; recalcDiscount()" class="text-[10px] font-bold px-2.5 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-md hover:bg-purple-100 hover:text-purple-700 transition" x-text="q + '%'"></button>
                            </template>
                        </div>
                        <div x-show="discountType === 'amount'" class="flex gap-1 flex-wrap">
                            <template x-for="q in [50, 100, 200, 500, 1000].filter(v => v <= effectiveSubtotal)" :key="'amt-' + q">
                                <button @click="discountType = 'amount'; discountValue = q; recalcDiscount()" class="text-[10px] font-bold px-2.5 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-md hover:bg-purple-100 hover:text-purple-700 transition" x-text="'Rs.' + q"></button>
                            </template>
                        </div>
                    </div>
                </div>
                {{-- 7 Aug 2026 PRA redesign port: BADA TOTAL BAND — solid brand band (bg-blue-900;
                     the FBR theme engine remaps blue-* per company theme), big white total,
                     items·qty pill. All original rows kept. FBR = per-item tax (method-independent),
                     so no cash/card method hint — the band total IS the charge for either button. --}}
                <div class="tn-total-band px-3 py-2 bg-blue-900">
                    <div class="flex items-end justify-between gap-2">
                        <div class="min-w-0 space-y-0.5 text-[11px] leading-tight text-white/75">
                            <div class="flex gap-2"><span>{{ __('pos.subtotal') }}</span><span x-text="'Rs. ' + Number(subtotal).toLocaleString()"></span></div>
                            <div x-show="itemDiscountsTotal > 0" class="flex gap-2 text-orange-300">
                                <span>{{ __('pos.item_discounts') }}</span>
                                <span x-text="'-Rs. ' + Number(itemDiscountsTotal).toLocaleString()"></span>
                            </div>
                            <div x-show="discountAmount > 0" class="flex gap-2 text-orange-300">
                                <span x-text="discountType === 'percentage' ? window.TXT.order_discount_paren + discountValue + '%)' : 'Order Discount'"></span>
                                <span x-text="'-Rs. ' + Number(discountAmount).toLocaleString()"></span>
                            </div>
                            <div x-show="exemptAmount > 0" class="flex gap-2 text-green-300"><span>{{ __('pos.tax_exempt') }}</span><span x-text="'-Rs. ' + Number(exemptAmount).toLocaleString()"></span></div>
                            <div class="flex gap-2"><span x-text="window.TXT.tax_paren + taxRate + '%)'"></span><span x-text="'Rs. ' + Number(taxAmount).toLocaleString()"></span></div>
                            <div x-show="Math.abs(roundOff) > 0.001" class="flex gap-2 text-white/60">
                                <span>{{ __('pos.round_off') }}</span>
                                <span x-text="(roundOff >= 0 ? '+ Rs. ' : '− Rs. ') + Math.abs(roundOff).toFixed(2)"></span>
                            </div>
                            <div class="pt-0.5">
                                <span class="inline-flex items-center rounded-full bg-white/15 px-2 py-0.5 text-[9px] font-bold text-white" x-text="cart.length + window.TXT.sfx_items_mid + Number(cartQtyCount.toFixed(2)).toLocaleString() + window.TXT.sfx_qty"></span>
                            </div>
                        </div>
                        <div class="text-right shrink-0">
                            <div class="text-[9px] font-bold tracking-widest text-white/60 uppercase">{{ __('pos.total_word') }}</div>
                            <div class="total-animate total-line text-3xl font-black text-white leading-none" x-text="'Rs. ' + Number(roundedTotal).toLocaleString()" :class="cartAnimating ? 'cart-pop' : ''"></div>
                        </div>
                    </div>
                    <div x-show="posRole === 'pos_admin' && getCartCost() > 0" class="flex justify-between text-[10px] text-white/50 pt-1">
                        <span>{{ __('pos.est_cost') }}</span><span x-text="'Rs. ' + r2(getCartCost()).toLocaleString()"></span>
                    </div>
                    <div x-show="posRole === 'pos_admin' && getCartCost() > 0" class="flex justify-between text-[10px] font-semibold" :class="(totalAmount - getCartCost()) >= 0 ? 'text-green-300' : 'text-red-300'">
                        <span>{{ __('pos.est_profit') }}</span><span x-text="'Rs. ' + r2(totalAmount - getCartCost()).toLocaleString()"></span>
                    </div>
                </div>
                <div class="px-3 pb-3 pt-2 space-y-2 mobile-sticky-pay">
                    {{-- ONE-TAP method buttons (PRA parity, 7 Aug 2026): CASH/CARD finalize
                         DIRECTLY with that method — same guards + sequence as the existing
                         Alt+1/Alt+2 keyboard shortcut handler. FBR tax is per-item, so the
                         charge equals the band total for either method. PAY (F8) keeps the
                         modal (method choice + buyer NTN etc.). --}}
                    <div class="grid grid-cols-2 gap-2">
                        <button @click="payingHeldOrderId = null; saveAsProvisional = false; payMethodIndex = 0; payPrintReceipt = billPrintDefault(); processPayment('cash')" :disabled="cart.length === 0 || submitting" class="py-1.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white disabled:opacity-30 shadow-sm transition flex flex-col items-center gap-0.5">
                            <span class="flex items-center gap-1.5 text-xs font-extrabold leading-none"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>CASH</span>
                            <span class="flex items-center gap-1 leading-none"><span class="text-[9px] text-white/75" x-text="cart.length ? 'Rs. ' + Number(roundedTotal).toLocaleString() : ''"></span><kbd class="text-[8px] bg-white/20 px-1 rounded font-mono">Alt+1</kbd></span>
                        </button>
                        <button @click="payingHeldOrderId = null; saveAsProvisional = false; payMethodIndex = 1; payPrintReceipt = billPrintDefault(); processPayment('card')" :disabled="cart.length === 0 || submitting" class="py-1.5 rounded-xl bg-gray-700 hover:bg-gray-800 dark:bg-gray-600 dark:hover:bg-gray-700 text-white disabled:opacity-30 shadow-sm transition flex flex-col items-center gap-0.5">
                            <span class="flex items-center gap-1.5 text-xs font-extrabold leading-none"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>CARD</span>
                            <span class="flex items-center gap-1 leading-none"><span class="text-[9px] text-white/75" x-text="cart.length ? 'Rs. ' + Number(roundedTotal).toLocaleString() : ''"></span><kbd class="text-[8px] bg-white/20 px-1 rounded font-mono">Alt+2</kbd></span>
                        </button>
                    </div>
                    <div class="grid grid-cols-4 gap-2">
                        <button @click="if(cart.length && confirm(window.TXT.clear_entire_cart)) { clearCart(); }" :disabled="cart.length === 0" class="py-2 text-xs font-bold text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 rounded-xl border border-red-200 dark:border-red-800 hover:bg-red-100 disabled:opacity-30 transition flex items-center justify-center gap-0.5">{{ __('pos.clear') }} <kbd class="text-[8px] bg-red-200/50 dark:bg-red-800/30 px-1 rounded font-mono">F4</kbd></button>
                        {{-- Task 1271: Cart drafts — modal has "save current cart" + recall/delete list.
                             Drafts are JSON rows (fbr_pos_drafts), never held sales / FBR serials. --}}
                        <button @click="openDrafts()" class="relative py-2 text-xs font-bold text-sky-600 dark:text-sky-400 bg-sky-50 dark:bg-sky-900/20 rounded-xl border border-sky-200 dark:border-sky-800 hover:bg-sky-100 transition flex items-center justify-center gap-0.5">
                            {{ __('pos.draft_word') }}
                            <span x-show="activeDraftId" x-cloak class="absolute -top-1.5 -right-1.5 w-3 h-3 bg-sky-500 rounded-full shadow-sm" title="{{ __('pos.draft_active_dot') }}"></span>
                        </button>
                        <button @click="holdOrder()" :disabled="cart.length === 0 || submitting || hasManualItems()" :title="hasManualItems() ? window.TXT.ti_manual_pay_first : ''" class="py-2 text-xs font-bold text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 rounded-xl border border-amber-200 dark:border-amber-800 hover:bg-amber-100 disabled:opacity-30 disabled:cursor-not-allowed transition flex items-center justify-center gap-1">
                            <svg x-show="submitting" class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <span x-text="submitting ? window.TXT.holding_ellipsis : window.TXT.hold_word"></span>
                            <kbd x-show="!submitting" class="text-[8px] bg-amber-200/50 dark:bg-amber-800/30 px-1 rounded ml-0.5 font-mono">F5</kbd>
                        </button>
                        <button @click="showHeldOrders = !showHeldOrders" class="relative py-2 text-xs font-bold text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/20 rounded-xl border border-purple-200 dark:border-purple-800 hover:bg-purple-100 transition flex items-center justify-center gap-0.5">
                            {{ __('pos.recall') }} <kbd class="text-[8px] bg-purple-200/50 dark:bg-purple-800/30 px-1 rounded font-mono">F3</kbd>
                            <span x-show="heldOrders.length > 0" class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-red-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center held-badge-pulse shadow-sm" x-text="heldOrders.length"></span>
                        </button>
                    </div>
                    <!-- ─── SAVE PROVISIONAL + PAY on ONE line (PRA redesign port) ─── -->
                    <div class="grid grid-cols-5 gap-2">
                        <button @click="saveProvisionalDirect()" :disabled="cart.length === 0 || submitting" class="col-span-2 py-3 rounded-xl text-xs font-bold text-white bg-amber-500 hover:bg-amber-600 disabled:opacity-30 shadow-sm transition flex items-center justify-center gap-1.5">
                            <svg x-show="!submitting" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
                            <svg x-show="submitting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <span>{{ __('pos.save_provisional') }}</span>
                            <kbd class="text-[8px] bg-amber-700/40 px-1 py-0.5 rounded font-mono">F9</kbd>
                        </button>
                        <button @click="showPayModal = true" :disabled="cart.length === 0 || submitting" class="pay-btn-premium btn-ripple col-span-3 py-3 rounded-xl text-sm font-extrabold text-white disabled:opacity-30">
                            <span class="flex items-center justify-center gap-1.5">
                                <svg x-show="submitting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <svg x-show="!submitting" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                {{ __('pos.pay_rs') }} <span x-text="Number(roundedTotal).toLocaleString()"></span>
                                <kbd x-show="!submitting" class="text-[9px] bg-green-500/30 px-1.5 rounded font-mono">F8</kbd>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         PAY MODAL — Final payment ONLY (Cash / Card → FBR submit).
         Provisional save is now a SEPARATE button + F9 shortcut
         in the right sidebar (no modal, no checkbox, no key conflict).
         ═══════════════════════════════════════════════════════════════ -->
    <div x-show="showPayModal" x-cloak x-transition.opacity x-effect="if (showPayModal) { submitting = false; saveAsProvisional = false; payMethodIndex = 0; cashReceived = ''; payPrintReceipt = billPrintDefault(); } else if (!submitting) { payPrintReceipt = billPrintDefault(); }" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showPayModal = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden" x-transition.scale.90>
            <div class="p-5 text-center border-b border-gray-100 dark:border-gray-800">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ __('pos.payment') }}</h3>
                <p class="text-3xl font-extrabold mt-2 text-purple-600 dark:text-purple-400" x-text="'Rs. ' + Number(roundedTotal).toLocaleString()"></p>
                <p x-show="Math.abs(roundOff) > 0.001" class="text-[10px] text-gray-400 mt-0.5" x-text="(roundOff >= 0 ? window.TXT.rounded_up_by : window.TXT.rounded_down_by) + 'Rs. ' + Math.abs(roundOff).toFixed(2)"></p>
                <p x-show="stockError" class="text-xs text-red-500 mt-2 bg-red-50 dark:bg-red-900/20 p-2 rounded-lg" x-text="stockError"></p>
                <p x-show="submitting" class="text-xs text-purple-500 mt-2">{{ __('pos.processing_payment') }}</p>
                {{-- 🧾 Buyer NTN (optional — FBR B2B compliance). @keydown.stop keeps digits
                     from triggering the modal's 1/2 payment hotkeys while typing. --}}
                <div class="mt-3 text-left" @click.stop>
                    <label class="block text-[9px] font-black uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-0.5">{{ __('pos.buyer_ntn') }} <span class="font-medium normal-case text-gray-300 dark:text-gray-600">{{ __('pos.optional_b2b') }}</span></label>
                    <input type="text" x-model="customerNtn" maxlength="30" placeholder="{{ __('pos.ph_ntn_eg') }}"
                        autocomplete="one-time-code" name="pos_buyer_ntn_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                        @keydown.stop @keydown.enter.prevent.stop="$event.target.blur()" @keydown.escape.prevent.stop="$event.target.blur()"
                        class="w-full text-xs rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-2.5 py-1.5 text-gray-800 dark:text-gray-200 focus:ring-purple-500 focus:border-purple-400 font-mono">
                </div>
            </div>
            <div class="p-4 grid grid-cols-2 gap-3">
                <button @click="payMethodIndex = 0; processPayment('cash')" :disabled="submitting" :class="payMethodIndex === 0 ? 'ring-2 ring-green-500 ring-offset-2 dark:ring-offset-gray-900 scale-105 shadow-sm border-green-400' : ''" class="py-4 rounded-xl text-center border-2 transition disabled:opacity-50 bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 hover:bg-green-100 hover:border-green-400">
                    <svg x-show="submitting" class="w-8 h-8 mx-auto mb-1 animate-spin text-green-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <svg x-show="!submitting" class="w-8 h-8 mx-auto mb-1 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    <span class="text-sm font-bold text-green-700 dark:text-green-400" x-text="submitting ? window.TXT.processing_ellipsis : window.TXT.cash_title"></span>
                    <span class="block text-[10px] font-semibold mt-0.5 text-green-600/60" x-text="window.TXT.tax_rs_prefix + taxAmount.toFixed(2) + window.TXT.per_item_sfx"></span>
                    <kbd x-show="!submitting" class="block mt-0.5 text-[9px] font-mono text-green-500/60">{{ __('pos.press_1') }}</kbd>
                </button>
                <button @click="payMethodIndex = 1; processPayment('card')" :disabled="submitting" :class="payMethodIndex === 1 ? 'ring-2 ring-blue-500 ring-offset-2 dark:ring-offset-gray-900 scale-105 shadow-sm border-blue-400' : ''" class="py-4 rounded-xl text-center border-2 transition disabled:opacity-50 bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800 hover:bg-blue-100 hover:border-blue-400">
                    <svg x-show="submitting" class="w-8 h-8 mx-auto mb-1 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <svg x-show="!submitting" class="w-8 h-8 mx-auto mb-1 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    <span class="text-sm font-bold text-blue-700 dark:text-blue-400" x-text="submitting ? window.TXT.processing_ellipsis : window.TXT.card_title"></span>
                    <span class="block text-[10px] font-semibold mt-0.5 text-blue-600/60" x-text="window.TXT.tax_rs_prefix + taxAmount.toFixed(2) + window.TXT.per_item_sfx"></span>
                    <kbd x-show="!submitting" class="block mt-0.5 text-[9px] font-mono text-blue-500/60">{{ __('pos.press_2') }}</kbd>
                </button>
                {{-- UDHAAR / KHATA (Aug 2026 — Retail Core): credit sale — needs a saved
                     customer (server blocks it too). Amount lands in the customer's khata.
                     Strict plan binding: hidden when the plan lacks khata_enabled
                     (store() rejects khata payments server-side too). --}}
                @if(\App\Services\PosFeatureService::planAllows($company, 'khata_enabled'))
                <button @click="payUdhaar()" :disabled="submitting"
                        :class="[payMethodIndex === 2 ? 'ring-2 ring-amber-500 ring-offset-2 dark:ring-offset-gray-900 scale-[1.02] shadow-sm border-amber-400' : '', !selectedCustomer ? 'opacity-50' : '']"
                        class="col-span-2 py-3 rounded-xl text-center border-2 transition disabled:opacity-50 bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800 hover:bg-amber-100 hover:border-amber-400">
                    <div class="flex items-center justify-center gap-2">
                        <svg class="w-6 h-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                        <span class="text-sm font-bold text-amber-700 dark:text-amber-400">{{ __('pos.udhaar_khata_btn') }}</span>
                        <kbd x-show="!submitting" class="text-[9px] font-mono text-amber-500/60">3</kbd>
                    </div>
                    <span class="block text-[10px] font-semibold mt-0.5" :class="selectedCustomer ? 'text-amber-600/70' : 'text-red-500'"
                          x-text="selectedCustomer ? (selectedCustomer.name + window.TXT.udhaar_on_khata_sfx) : window.TXT.udhaar_pick_customer"></span>
                </button>
                @endif
            </div>
            {{-- Cash Received / Wapsi (owner request, Jul 2026): optional input — CASH only.
                 data-cash-input keyboard guard: digits type, Enter pays cash. Under-payment
                 shows a soft warning; the FBR server cash-guard would 422 it, so the payload
                 only sends the entered amount when it covers the total.
                 HIDDEN 30 Jul 2026 (owner): UI abhi nahi chahiye, backend rehne do — the if(false) below
                 flip to true to re-impose. --}}
            {{-- Aug 2026: per-company OPT-IN — same companies.pos_cash_received_enabled
                 switch as the PRA screen (default OFF; flipped from POS Customize / admin). --}}
            @if(!empty($company->pos_cash_received_enabled))
            <div x-show="payMethodIndex === 0" class="px-4 pb-2" @click.stop>
                <div class="flex items-center gap-2">
                    <input type="text" inputmode="decimal" x-model="cashReceived" data-cash-input
                        autocomplete="off" name="pos_cash_received_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                        placeholder="{{ __('pos.ph_cash_received') }}"
                        class="flex-1 min-w-0 text-sm font-bold bg-green-50/60 dark:bg-green-900/10 border border-green-200 dark:border-green-900/40 rounded-lg px-2.5 py-2 text-gray-800 dark:text-gray-200 focus:ring-green-400 placeholder-gray-400">
                    <template x-for="amt in [500, 1000, 5000]" :key="amt">
                        <button type="button" @click="cashReceived = String(amt)" class="px-2.5 py-2 rounded-lg text-xs font-bold bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-green-100 dark:hover:bg-green-900/30 transition" x-text="amt >= 1000 ? (amt/1000) + 'k' : amt"></button>
                    </template>
                </div>
                <p x-show="parseFloat(cashReceived) - roundedTotal > 0.001" x-cloak class="mt-1.5 text-center text-base font-black text-green-600 dark:text-green-400" x-text="window.TXT.change_rs_prefix + Math.round(parseFloat(cashReceived) - roundedTotal).toLocaleString()"></p>
                <p x-show="cashReceived !== '' && parseFloat(cashReceived) > 0 && roundedTotal - parseFloat(cashReceived) > 0.001" x-cloak class="mt-1.5 text-center text-[11px] font-bold text-amber-600 dark:text-amber-400" x-text="window.TXT.short_by_rs + Math.round(roundedTotal - parseFloat(cashReceived)).toLocaleString() + window.TXT.more_needed_sfx"></p>
            </div>
            @endif
            {{-- Task 520 (port of Task 514): per-bill receipt auto-print choice (default =
                 company auto-print setting; unticked = SIRF is bill ki receipt auto-print
                 skip — KOT/FBR submission/receipt popup untouched). --}}
            <div class="px-4 pb-1" @click.stop>
                <label class="flex items-center gap-2 text-[11px] text-gray-600 dark:text-gray-300 cursor-pointer select-none">
                    <input type="checkbox" x-model="payPrintReceipt" class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span>{{ __('pos.final_print_receipt') }}</span>
                </label>
            </div>
            <div class="px-4 pb-0.5">
                <p class="text-center text-[10px] text-gray-400 dark:text-gray-500 font-medium">{{ __('pos.use_word') }} <kbd class="px-1 font-mono text-gray-500 dark:text-gray-400">&larr;</kbd> <kbd class="px-1 font-mono text-gray-500 dark:text-gray-400">&rarr;</kbd> to choose &middot; <kbd class="px-1 font-mono text-gray-500 dark:text-gray-400">Enter</kbd> to confirm</p>
            </div>
            <div class="p-4 pt-2">
                <button @click="showPayModal = false" :disabled="submitting" class="w-full py-2.5 rounded-xl text-sm font-semibold text-gray-500 hover:text-gray-700 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 transition disabled:opacity-50">{{ __('pos.cancel') }} <span class="text-[9px] text-gray-400 font-mono ml-1">ESC</span></button>
            </div>
        </div>
    </div>

    {{-- ─────────── QUICK-CREATE PRODUCT modal (Aug 2026, owner request) ───────────
         Unknown search/scan now asks for FULL details (name, price, UoM, tax, HS code,
         barcode) instead of instantly creating a Rs.0 product. A scanned NUMERIC code
         pre-fills only the BARCODE field — digits can never become a product name. --}}
    {{-- STICKY modal (owner, Aug 2026): click OUTSIDE must NOT dismiss — the form stands
         until Cancel/Esc or Save ("side par click karne se band na ho"). No @click.self. --}}
    <div x-show="qcModal" x-cloak x-transition.opacity class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" @keydown.escape.prevent.stop="qcCancel()">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
            <div class="flex items-center justify-between px-4 py-3 bg-blue-600">
                <h3 class="text-sm font-bold text-white">{{ __('pos.qc_modal_title') }}</h3>
                <button type="button" @click="qcCancel()" class="text-white hover:bg-blue-700 rounded-lg p-1" title="{{ __('pos.cancel') }} (Esc)">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-4 space-y-3" style="max-height:70vh; overflow-y:auto;">
                <p x-show="qcFromScan" class="text-xs text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-900/30 rounded-lg px-3 py-2 font-medium">{{ __('pos.qc_scanned_hint') }}</p>
                <p x-show="!qcFromScan && !qcExistingId" class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.qc_typed_hint') }}</p>
                <p x-show="qcExistingId" x-cloak class="text-xs text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/30 rounded-lg px-3 py-2 font-medium">{{ __('pos.qc_existing_hint') }}</p>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">{{ __('pos.qc_name_label') }} <span class="text-red-500">*</span></label>
                    <input type="text" id="qc-name-input" x-model="qcName"
                        @keydown.enter.prevent.stop="document.getElementById('qc-price-input').focus()"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500"
                        autocomplete="one-time-code" name="qc_product_name_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">{{ __('pos.qc_price_label') }} <span class="text-red-500">*</span></label>
                        <input type="number" id="qc-price-input" x-model="qcPrice" min="0" step="0.01" inputmode="decimal"
                            @keydown.enter.prevent.stop="qcSave()"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500"
                            autocomplete="off" name="qc_price_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">{{ __('pos.uom_label') }}</label>
                        <select x-model="qcUom" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            @php
                                // KEEP IN SYNC with product-form.blade.php $uomList
                                $qcUomList = ['U'=>'Units','PCS'=>'Pieces','KG'=>'Kilogram','GM'=>'Gram','LTR'=>'Liter','ML'=>'Milliliter','MTR'=>'Meter','SQM'=>'Square Meter','FT'=>'Feet','IN'=>'Inch','YDS'=>'Yards','PKT'=>'Packet','DOZ'=>'Dozen','BOX'=>'Box','CTN'=>'Carton','BAG'=>'Bag','BTL'=>'Bottle','TIN'=>'Tin','CAN'=>'Can','BUN'=>'Bundle','ROL'=>'Roll','SET'=>'Set'];
                            @endphp
                            @foreach($qcUomList as $code => $label)
                                <option value="{{ $code }}">{{ $code }} — {{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">{{ __('pos.qc_tax_label') }}</label>
                        <select x-model="qcTaxMode" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            {{-- Task 1262: Exempt first + default (matches product form) --}}
                            <option value="exempt">{{ __('pos.tax_exempt_tax_free') }}</option>
                            <option value="standard">{{ __('pos.qc_tax_standard') }}</option>
                            <option value="custom">{{ __('pos.qc_tax_custom') }}</option>
                        </select>
                    </div>
                    <div x-show="qcTaxMode === 'custom'">
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">%</label>
                        <input type="number" x-model="qcTaxRate" min="0" max="100" step="0.01" inputmode="decimal"
                            @keydown.enter.prevent.stop="qcSave()"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500"
                            autocomplete="off" name="qc_tax_rate_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">{{ __('pos.barcode_label') }} <span class="text-gray-400 font-normal">({{ __('pos.optional_lc') }})</span></label>
                        <input type="text" x-model="qcBarcode" @keydown.enter.prevent.stop="qcSave()"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500 font-mono"
                            autocomplete="one-time-code" name="qc_barcode_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">{{ __('pos.hs_code_col') }} <span class="text-gray-400 font-normal">({{ __('pos.optional_lc') }})</span></label>
                        <input type="text" x-model="qcHsCode" @keydown.enter.prevent.stop="qcSave()" placeholder="{{ __('pos.ph_hs_code') }}"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500"
                            autocomplete="one-time-code" name="qc_hs_code_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore>
                    </div>
                </div>
            </div>
            <div class="flex gap-2 px-4 py-3 bg-gray-50 dark:bg-gray-900/40 border-t border-gray-100 dark:border-gray-700">
                <button type="button" @click="qcCancel()" class="flex-1 py-2 rounded-lg text-sm font-semibold text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 transition">{{ __('pos.cancel') }}</button>
                <button type="button" @click="qcSave()" :disabled="qcSaving" class="flex-1 py-2 rounded-lg text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-60 transition">
                    <span x-show="!qcSaving">{{ __('pos.qc_save_btn') }}</span>
                    <span x-show="qcSaving">{{ __('pos.creating_q_prefix') }}<span x-text="qcName"></span>"…</span>
                </button>
            </div>
        </div>
    </div>

    @if($features->tables)
    <div x-show="showTablePicker" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showTablePicker = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md max-h-[70vh] overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ __('pos.select_table') }}</h3>
                <button @click="showTablePicker = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <div class="p-4 max-h-[50vh] overflow-y-auto grid grid-cols-3 gap-2">
                @foreach($tables as $t)
                <button @click="selectTable({ id: {{ $t->id }}, table_number: '{{ $t->table_number }}', seats: {{ $t->seats }} })" class="py-3 px-2 rounded-xl text-center border-2 transition hover:scale-105 {{ $t->status === 'occupied' ? 'border-red-300 bg-red-50 dark:bg-red-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 hover:border-blue-400' }}">
                    {{-- Top-view table + chairs diagram (color = status) --}}
                    <svg viewBox="0 0 48 48" class="w-8 h-8 mx-auto mb-1 {{ $t->status === 'occupied' ? 'text-red-500' : 'text-green-500 dark:text-green-400' }}" fill="currentColor" aria-hidden="true">
                        <rect x="17" y="1.5" width="14" height="7" rx="3"/>
                        <rect x="17" y="39.5" width="14" height="7" rx="3"/>
                        <rect x="1.5" y="17" width="7" height="14" rx="3"/>
                        <rect x="39.5" y="17" width="7" height="14" rx="3"/>
                        <circle cx="24" cy="24" r="13"/>
                        <circle cx="24" cy="24" r="8.5" fill="#fff" fill-opacity="0.35"/>
                    </svg>
                    <p class="text-sm font-bold {{ $t->status === 'occupied' ? 'text-red-600' : 'text-gray-900 dark:text-white' }}">T-{{ $t->table_number }}</p>
                    <p class="text-[10px] text-gray-400">{{ $t->seats }} seats</p>
                    @if($t->status === 'occupied')<span class="text-[9px] text-red-500 font-medium">Occupied{{ ($t->occupied_since ?? null) ? ' • ' . $t->occupied_since->diffForHumans(null, true) : '' }}</span>@endif
                </button>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    {{-- ═══ UNSENT-CART TABLE-SWITCH PROMPT (ZFC, Aug 2026 — mirror of PRA universal) ═══
         Table already selected + unsent items in the cart, and the cashier picks a
         DIFFERENT table: explicit choice — take items along, or remove them.
         z-[60] so it stacks above the table picker (picker stays open behind). --}}
    <div x-show="tableSwitchPrompt" x-cloak x-transition.opacity class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[60] flex items-center justify-center p-4" @click.self="tableSwitchPrompt = null">
        <template x-if="tableSwitchPrompt">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden" x-transition.scale.90>
                <div class="p-5 text-center border-b border-gray-100 dark:border-gray-800">
                    <div class="w-12 h-12 mx-auto rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center mb-2">
                        <svg class="w-6 h-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.947-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z"/></svg>
                    </div>
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wide">{{ __('pos.unsent_items_in_cart') }}</p>
                    <p class="text-2xl font-black text-gray-900 dark:text-white mt-1" x-text="tableSwitchTargetLabel()"></p>
                    <p class="text-[12px] text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.unsent_take_or_remove_q') }}</p>
                </div>
                <div class="p-4 space-y-2">
                    <button @click="confirmTableSwitch('move')" class="w-full py-3 rounded-xl text-sm font-bold text-white bg-purple-600 hover:bg-purple-700 transition ring-offset-2 dark:ring-offset-gray-900" :class="tableSwitchIndex === 0 ? 'ring-2 ring-purple-500' : ''">1 · {{ __('pos.unsent_take_items_btn') }}</button>
                    <button @click="confirmTableSwitch('discard')" class="w-full py-3 rounded-xl text-sm font-bold text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-900/20 border-2 border-red-200 dark:border-red-800 hover:bg-red-100 hover:border-red-400 transition ring-offset-2 dark:ring-offset-gray-900" :class="tableSwitchIndex === 1 ? 'ring-2 ring-red-500' : ''">2 · {{ __('pos.unsent_remove_items_btn') }}</button>
                </div>
                <div class="px-4 pb-4">
                    <button @click="tableSwitchPrompt = null" class="w-full py-2 rounded-xl text-xs font-bold text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 transition">{{ __('pos.cancel_esc') }}</button>
                </div>
            </div>
        </template>
    </div>

    {{-- ═══ Task 565: PRINT-CONFIRM YES/NO DIALOG (opt-in per company, PRA port) ═══
         Flag ON: payment success par auto-print chain se pehle FORAN yeh chhota
         in-screen dialog (naya browser popup nahi). Keyboard handleKey ke TOPMOST
         block se chalta hai (Enter=Yes default, Tab=toggle, Esc=No) — yahan sirf
         mouse clicks. z-index inline (arbitrary Tailwind class = Vite rebuild trap). --}}
    <div x-show="showPrintConfirm" x-cloak x-transition.opacity class="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4" style="display:none;z-index:80;">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xs overflow-hidden" x-transition.scale.90>
            <div class="p-5 text-center">
                <div class="w-12 h-12 mx-auto rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center mb-2">
                    <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4H7v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                </div>
                <p class="text-lg font-black text-gray-900 dark:text-white">{{ __('pos.print_confirm_q') }}</p>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">{{ __('pos.print_confirm_keys_hint') }}</p>
            </div>
            <div class="px-4 pb-4 grid grid-cols-2 gap-2">
                <button type="button" x-ref="printConfirmYes" @click="resolvePrintConfirm(true)" @focus="printConfirmChoice = 'yes'"
                        class="py-3 rounded-xl text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 transition ring-offset-2 dark:ring-offset-gray-900 focus:outline-none"
                        :class="printConfirmChoice === 'yes' ? 'ring-2 ring-blue-500' : ''">{{ __('pos.print_confirm_yes') }}</button>
                <button type="button" x-ref="printConfirmNo" @click="resolvePrintConfirm(false)" @focus="printConfirmChoice = 'no'"
                        class="py-3 rounded-xl text-sm font-bold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 border-2 border-gray-200 dark:border-gray-700 hover:bg-gray-200 dark:hover:bg-gray-700 transition ring-offset-2 dark:ring-offset-gray-900 focus:outline-none"
                        :class="printConfirmChoice === 'no' ? 'ring-2 ring-gray-500 border-gray-400 dark:border-gray-500' : ''">{{ __('pos.print_confirm_no') }}</button>
            </div>
        </div>
    </div>

    <div x-show="showHeldOrders" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showHeldOrders = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[80vh] overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ __('pos.held_orders') }}</h3>
                    <p class="text-[10px] text-gray-400 mt-0.5">{{ __('pos.recall_nav_hint') }}</p>
                </div>
                <button @click="showHeldOrders = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <div class="max-h-[60vh] overflow-y-auto">
                <template x-if="heldOrders.length === 0">
                    <div class="p-8 text-center text-gray-400"><p class="text-sm">{{ __('pos.no_held_orders') }}</p></div>
                </template>
                <template x-for="(order, oi) in heldOrders" :key="order.id">
                    <div class="p-4 border-b border-gray-100 dark:border-gray-800 transition-all" :class="activeHeldIndex === oi ? 'bg-blue-50 dark:bg-blue-900/15 ring-2 ring-blue-400 ring-inset' : ''">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] font-mono text-gray-400 w-5" x-text="oi + 1"></span>
                                <span class="text-sm font-bold text-gray-900 dark:text-white" x-text="order.order_number"></span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <template x-if="order.customer_name"><span class="text-[9px] bg-blue-50 text-blue-600 px-1.5 py-0.5 rounded-full font-medium" x-text="order.customer_name"></span></template>
                                <template x-if="order.priority"><span class="text-[9px] bg-red-100 text-red-600 px-1.5 py-0.5 rounded-full font-bold">URGENT</span></template>
                                <span class="text-xs px-2 py-0.5 rounded-full font-medium" :class="{'bg-amber-100 text-amber-700': order.status==='held', 'bg-blue-100 text-blue-700': order.status==='preparing', 'bg-green-100 text-green-700': order.status==='ready'}" x-text="order.status"></span>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mb-1 ml-7" x-text="'Rs. ' + Number(order.total_amount).toLocaleString() + ' • ' + order.items.length + window.TXT.sfx_item_s"></p>
                        <template x-if="order.table"><p class="text-[10px] text-blue-600 ml-7" x-text="window.TXT.table_t_colon + order.table.table_number"></p></template>
                        <div class="flex gap-2 mt-2 ml-7">
                            <button @click="recallOrder(order)" class="flex-1 py-2 text-xs font-bold text-blue-600 border border-blue-300 rounded-xl hover:bg-blue-50 transition">{{ __('pos.recall') }}</button>
                            @if($features->kot)
                            {{-- Order Matching (Aug 2026): FBR held sales live in fbr_pos_held_sales,
                                 NOT pos_restaurant_orders. Use FBR KOT endpoints — never the PRA
                                 /pos/restaurant/... routes which 404 for all FBR companies. --}}
                            <a :href="'/fbr-pos/held/' + order.id + '/kitchen-ticket'" target="_blank" title="{{ __('pos.fbr_ti_view_print_store_slip') }}" class="py-2 px-2 text-xs font-bold text-center text-orange-600 border border-orange-300 rounded-xl hover:bg-orange-50 transition">{{ __('pos.fbr_store_slip_word') }}</a>
                            <button @click="resendKitchen(order)" title="{{ __('pos.fbr_ti_resend_store') }}" class="py-2 px-2 text-xs font-bold text-orange-700 border border-orange-400 rounded-xl bg-orange-50 hover:bg-orange-100 transition">{{ __('pos.resend_short') }}</button>
                            @endif
                            <button @click="payHeldOrder(order.id)" class="flex-1 py-2 text-xs font-bold text-white bg-green-600 rounded-xl hover:bg-green-700 transition">{{ __('pos.pay') }}</button>
                            <button @click="deleteHeldOrder(order.id)" class="py-2 px-3 text-xs font-bold text-red-500 border border-red-300 rounded-xl hover:bg-red-50 transition">{{ __('pos.delete') }}</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    {{-- Task 1271: CART DRAFTS MODAL — save current cart / recall / delete.     --}}
    {{-- Locked drafts (another cashier editing, 5-min expiry) show a lock badge --}}
    {{-- and their Recall button is disabled — server re-checks with a race-safe --}}
    {{-- conditional-UPDATE claim (423 on loss).                                 --}}
    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    <div x-show="showDrafts" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showDrafts = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[80vh] overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ __('pos.drafts_word') }}</h3>
                    <p class="text-[10px] text-gray-400 mt-0.5">{{ __('pos.drafts_hint') }}</p>
                </div>
                <button @click="showDrafts = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <div class="p-4 border-b border-gray-100 dark:border-gray-800">
                <button @click="saveDraftCart()" :disabled="cart.length === 0 || draftBusy"
                        class="w-full py-2.5 rounded-xl text-sm font-bold text-white bg-sky-600 hover:bg-sky-700 disabled:opacity-30 disabled:cursor-not-allowed transition flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
                    <span x-text="activeDraftId ? window.TXT.draft_update_btn : window.TXT.draft_save_btn"></span>
                </button>
            </div>
            <div class="max-h-[50vh] overflow-y-auto">
                <template x-if="drafts.length === 0">
                    <div class="p-8 text-center text-gray-400"><p class="text-sm">{{ __('pos.no_drafts') }}</p></div>
                </template>
                <template x-for="d in drafts" :key="d.id">
                    <div class="p-4 border-b border-gray-100 dark:border-gray-800" :class="activeDraftId === d.id ? 'bg-sky-50 dark:bg-sky-900/15' : ''">
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-bold text-gray-900 dark:text-white truncate" x-text="d.customer_name || window.TXT.walk_in"></p>
                                <p class="text-xs text-gray-500" x-text="'Rs. ' + Number(d.total_amount).toLocaleString() + ' • ' + d.items_count + window.TXT.sfx_item_s"></p>
                            </div>
                            {{-- Locked by another cashier — recall disabled until the lock expires --}}
                            <span x-show="d.locked" x-cloak class="flex items-center gap-1 px-2 py-1 rounded-full bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-[10px] font-bold text-red-600 dark:text-red-400 flex-shrink-0">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                <span x-text="d.locked_by_name || ''"></span>
                            </span>
                        </div>
                        <div class="flex gap-2 mt-2">
                            <button @click="recallDraft(d)" :disabled="d.locked || draftBusy" class="flex-1 py-2 text-xs font-bold text-sky-600 border border-sky-300 rounded-xl hover:bg-sky-50 disabled:opacity-30 disabled:cursor-not-allowed transition">{{ __('pos.recall') }}</button>
                            <button @click="deleteDraft(d.id)" :disabled="d.locked" class="py-2 px-3 text-xs font-bold text-red-500 border border-red-300 rounded-xl hover:bg-red-50 disabled:opacity-30 disabled:cursor-not-allowed transition">{{ __('pos.delete') }}</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    {{-- PROVISIONAL BILLS MODAL — opens from header "Local" button (F10).      --}}
    {{-- Lists all bills with fbr_status='local' for current company.           --}}
    {{-- Inline actions: Edit (opens edit page) / Delete / Make Final (FBR).    --}}
    {{-- Keyboard: ↑↓ navigate, Enter=Make Final, E=Edit, D=Delete, Esc=Close.  --}}
    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    <div x-show="showLocalBills" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showLocalBills = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between bg-blue-50 dark:bg-blue-900/20">
                <div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                        <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                        {{ __('pos.provisional_bills') }} <span class="text-xs font-medium text-blue-600 ml-1" x-text="'(' + filteredLocalBills().length + (localSearch.trim() ? '/' + localBills.length : '') + ')'"></span>
                    </h3>
                    <p class="text-[10px] text-gray-500 mt-0.5">{{ __('pos.provisional_nav_hint_fbr') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="loadLocalBills()" :disabled="localBillsLoading" class="text-xs text-blue-600 hover:text-blue-800 font-semibold px-2 py-1 rounded hover:bg-blue-100 disabled:opacity-50" title="{{ __('pos.ti_refresh_list') }}">
                        <svg class="w-4 h-4" :class="localBillsLoading ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                    <button @click="showLocalBills = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
            </div>
            {{-- 🔒 PIN GATE — company has a confidential PIN & this session isn't verified yet.
                 Server (apiProvisionalBills) returned 403 pin_required; unlock via verify-pin. --}}
            <div x-show="localPinRequired" x-cloak class="p-8 text-center">
                <svg class="w-10 h-10 mx-auto mb-3 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                <p class="text-sm font-bold text-gray-800 dark:text-gray-100">{{ __('pos.pin_required') }}</p>
                <p class="text-[11px] text-gray-400 mt-1">{{ __('pos.provisional_pin_hint') }}</p>
                <form @submit.prevent="verifyLocalPin()" class="mt-4 flex items-center justify-center gap-2">
                    <input type="password" inputmode="numeric" x-model="localPinInput" maxlength="6" placeholder="••••"
                        autocomplete="one-time-code" name="pos_local_pin_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                        @keydown.stop @keydown.escape.prevent.stop="showLocalBills = false"
                        class="w-28 text-center text-lg font-mono tracking-widest rounded-xl border-2 border-blue-200 dark:border-blue-800 bg-white dark:bg-gray-800 px-3 py-2 text-gray-900 dark:text-white focus:ring-blue-500 focus:border-blue-400">
                    <button type="submit" :disabled="localPinVerifying || localPinInput.length < 4" class="px-4 py-2.5 rounded-xl text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-40 transition" x-text="localPinVerifying ? window.TXT.checking_ellipsis : window.TXT.unlock_word"></button>
                </form>
                <p x-show="localPinError" x-cloak class="text-xs text-red-500 mt-2 font-semibold" x-text="localPinError"></p>
                <p class="text-[10px] text-gray-400 mt-3">{{ __('pos.pin_lockout_hint') }}</p>
            </div>
            {{-- SEARCH (owner 1 Aug 2026): find a bill by customer name / phone / bill no.
                 Element-level keydown handlers REQUIRED: the global handleKey input-field
                 gate swallows window-level keys while this input has focus — which is the
                 default (openLocalBills auto-focuses). Hidden while the PIN gate is up. --}}
            <div x-show="!localPinRequired" class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 relative">
                <svg class="w-4 h-4 text-gray-400 absolute left-7 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" x-model="localSearch" @input="activeLocalIndex = 0" x-ref="localSearchInput"
                       @keydown.down.prevent="activeLocalIndex = Math.min(activeLocalIndex + 1, Math.max(0, filteredLocalBills().length - 1))"
                       @keydown.up.prevent="activeLocalIndex = Math.max(activeLocalIndex - 1, 0)"
                       @keydown.enter.prevent="const b = filteredLocalBills()[activeLocalIndex]; if (b) { $el.blur(); promoteProvisional(b); }"
                       @keydown.escape.prevent="showLocalBills = false"
                       autocomplete="off" name="local_bills_search_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                       placeholder="{{ __('pos.ph_provisional_search') }}"
                       class="w-full pl-9 pr-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 placeholder-gray-400">
            </div>
            <div x-show="!localPinRequired" class="max-h-[58vh] overflow-y-auto">
                <template x-if="localBillsLoading && localBills.length === 0">
                    <div class="p-12 text-center text-gray-400">
                        <svg class="w-8 h-8 mx-auto mb-2 animate-spin text-blue-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        <p class="text-sm">{{ __('pos.loading_provisional_bills') }}</p>
                    </div>
                </template>
                <template x-if="!localBillsLoading && localBills.length === 0">
                    <div class="p-12 text-center text-gray-400">
                        <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <p class="text-sm font-medium">{{ __('pos.no_provisional_bills') }}</p>
                        <p class="text-[11px] text-gray-400 mt-1">{{ __('pos.provisional_saved_here_hint') }}</p>
                    </div>
                </template>
                <template x-if="!localBillsLoading && localBills.length > 0 && filteredLocalBills().length === 0">
                    <div class="p-10 text-center text-gray-400">
                        <svg class="w-10 h-10 mx-auto mb-2 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <p class="text-sm font-medium">{{ __('pos.provisional_search_no_match') }}</p>
                    </div>
                </template>
                <template x-for="(bill, bi) in filteredLocalBills()" :key="bill.id">
                    <div class="p-4 border-b border-gray-100 dark:border-gray-800 transition-all" :class="activeLocalIndex === bi ? 'bg-blue-50 dark:bg-blue-900/15 ring-2 ring-blue-400 ring-inset' : ''">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-[10px] font-mono text-gray-400 w-5" x-text="bi + 1"></span>
                                <span class="text-sm font-bold text-gray-900 dark:text-white" x-text="bill.invoice_number"></span>
                                <span class="text-[9px] bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-bold uppercase tracking-wide">Local</span>
                                <template x-if="bill.customer_name">
                                    <span class="text-[10px] bg-blue-50 text-blue-600 px-1.5 py-0.5 rounded-full font-medium" x-text="bill.customer_name"></span>
                                </template>
                            </div>
                            <span class="text-sm font-bold text-blue-700 dark:text-blue-400" x-text="'Rs. ' + Number(bill.total_amount).toLocaleString()"></span>
                        </div>
                        <p class="text-[11px] text-gray-500 ml-7 mb-2" x-text="bill.items_count + window.TXT.sfx_item_s_dot + bill.created_human"></p>
                        <div class="flex gap-2 ml-7">
                            <a :href="'{{ url('/fbr-pos/transactions') }}/' + bill.id + '/edit-failed'" class="flex-1 py-2 text-xs font-bold text-blue-700 border border-blue-300 rounded-xl hover:bg-blue-50 transition text-center flex items-center justify-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                {{ __('pos.edit') }}
                            </a>
                            <button @click="deleteProvisional(bill)" class="py-2 px-3 text-xs font-bold text-red-600 border border-red-300 rounded-xl hover:bg-red-50 transition flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V3a1 1 0 011-1h4a1 1 0 011 1v4"/></svg>
                                {{ __('pos.delete') }}
                            </button>
                            <button @click="promoteProvisional(bill)" :disabled="!fbrEnabled" :title="fbrEnabled ? window.TXT.ti_submit_fbr_final : window.TXT.fbr_reporting_disabled_enable" class="flex-1 py-2 text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-xl transition shadow-sm disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                {{ __('pos.make_final') }}
                            </button>
                        </div>
                    </div>
                </template>
            </div>
            <div x-show="localBills.length > 0" class="p-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 text-[11px] text-gray-500">
                <span>{{ __('pos.provisional_tip_fbr') }}</span>
            </div>
        </div>
    </div>

    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    {{-- FAILED BILLS MODAL — opens from header "Failed" button (F11).         --}}
    {{-- Lists bills with fbr_status IN (failed,offline,pending) needing retry. --}}
    {{-- Inline actions: Retry (re-submit to FBR) / Edit / Delete.              --}}
    {{-- Keyboard: ↑↓ navigate, Enter=Retry, E=Edit, D=Delete, Esc=Close.       --}}
    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    <div x-show="showFailedBills" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showFailedBills = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20">
                <div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                        <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        {{ __('pos.failed_fbr_bills') }} <span class="text-xs font-medium text-red-600 ml-1" x-text="'(' + failedBills.length + ')'"></span>
                    </h3>
                    <p class="text-[10px] text-gray-500 mt-0.5">{{ __('pos.failed_retry_nav_hint') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="loadFailedBills()" :disabled="failedBillsLoading" class="text-xs text-red-600 hover:text-red-800 font-semibold px-2 py-1 rounded hover:bg-red-100 disabled:opacity-50" title="{{ __('pos.ti_refresh_list') }}">
                        <svg class="w-4 h-4" :class="failedBillsLoading ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                    <button @click="showFailedBills = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
            </div>
            <div class="max-h-[65vh] overflow-y-auto">
                <template x-if="failedBillsLoading && failedBills.length === 0">
                    <div class="p-12 text-center text-gray-400">
                        <svg class="w-8 h-8 mx-auto mb-2 animate-spin text-red-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        <p class="text-sm">{{ __('pos.loading_failed_bills') }}</p>
                    </div>
                </template>
                <template x-if="!failedBillsLoading && failedBills.length === 0">
                    <div class="p-12 text-center text-gray-400">
                        <svg class="w-12 h-12 mx-auto mb-3 text-green-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <p class="text-sm font-medium text-green-600">{{ __('pos.all_bills_synced_party') }}</p>
                        <p class="text-[11px] text-gray-400 mt-1">{{ __('pos.no_failed_fbr') }}</p>
                    </div>
                </template>
                <template x-for="(bill, bi) in failedBills" :key="bill.id">
                    <div class="p-4 border-b border-gray-100 dark:border-gray-800 transition-all" :class="activeFailedIndex === bi ? 'bg-red-50 dark:bg-red-900/15 ring-2 ring-red-400 ring-inset' : ''">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-[10px] font-mono text-gray-400 w-5" x-text="bi + 1"></span>
                                <span class="text-sm font-bold text-gray-900 dark:text-white" x-text="bill.invoice_number"></span>
                                <span class="text-[9px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wide"
                                      :class="bill.fbr_status === 'failed' ? 'bg-red-100 text-red-700' : (bill.fbr_status === 'offline' ? 'bg-orange-100 text-orange-700' : 'bg-yellow-100 text-yellow-700')"
                                      x-text="bill.fbr_status"></span>
                                <template x-if="bill.customer_name">
                                    <span class="text-[10px] bg-blue-50 text-blue-600 px-1.5 py-0.5 rounded-full font-medium" x-text="bill.customer_name"></span>
                                </template>
                            </div>
                            <span class="text-sm font-bold text-red-700 dark:text-red-400" x-text="'Rs. ' + Number(bill.total_amount).toLocaleString()"></span>
                        </div>
                        <p class="text-[11px] text-gray-500 ml-7 mb-1" x-text="bill.items_count + window.TXT.sfx_item_s_dot + bill.created_human"></p>
                        {{-- Task 627: asal wajah (FBR timeout / server error) — human message pehle, warna raw code. --}}
                        <template x-if="bill.error_message">
                            <p class="text-[10px] text-red-600 dark:text-red-400 ml-7 mb-2 leading-snug" x-text="'⚠ ' + bill.error_message"></p>
                        </template>
                        {{-- Raw response code hamesha dikhe (reviewer: generic message ke saath bhi code na chhupe) --}}
                        <template x-if="bill.error_code && (!bill.error_message || !String(bill.error_message).includes(String(bill.error_code)))">
                            <p class="text-[10px] text-red-500 ml-7 mb-2 font-mono truncate" x-text="'⚠ ' + bill.error_code"></p>
                        </template>
                        <div class="flex gap-2 ml-7 mt-2">
                            <a :href="'{{ url('/fbr-pos/transactions') }}/' + bill.id + '/edit-failed'" class="flex-1 py-2 text-xs font-bold text-blue-700 border border-blue-300 rounded-xl hover:bg-blue-50 transition text-center flex items-center justify-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                {{ __('pos.edit') }}
                            </a>
                            {{-- NO DELETE for FBR failed bills — FBR POS has no failed-bill
                                 delete endpoint by design (audit trail: every saved bill must
                                 reach FBR via Retry or be fixed via Edit; never silently vanish). --}}
                            <button @click="retryFailed(bill)" :disabled="!fbrEnabled || bill._retrying" :title="fbrEnabled ? window.TXT.ti_retry_fbr : window.TXT.ti_fbr_disabled_short" class="flex-1 py-2 text-xs font-bold text-white bg-gradient-to-br from-red-600 to-orange-600 hover:from-red-700 hover:to-orange-700 rounded-xl transition shadow-sm disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-1.5">
                                <svg x-show="!bill._retrying" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                <svg x-show="bill._retrying" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                <span x-text="bill._retrying ? window.TXT.retrying_ellipsis : window.TXT.retry_word"></span>
                            </button>
                        </div>
                    </div>
                </template>
            </div>
            {{-- Config-error bills section — shown below the retryable list --}}
            <template x-if="configErrorBills.length > 0">
                <div class="border-t-2 border-orange-200 dark:border-orange-800 bg-orange-50/60 dark:bg-orange-900/10">
                    <div class="px-4 pt-3 pb-1 flex items-center gap-2">
                        <svg class="w-4 h-4 text-orange-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <span class="text-xs font-bold text-orange-700 dark:text-orange-400">{{ __('pos.config_error_autoretry_off') }}</span>
                        <span class="ml-auto text-[10px] text-orange-600 dark:text-orange-500 font-semibold" x-text="'(' + configErrorBills.length + ' bill)'"></span>
                    </div>
                    <p class="text-[10px] text-orange-600 dark:text-orange-500 px-4 pb-2">{{ __('pos.fq_set_then_retry') }}</p>
                    <template x-for="bill in configErrorBills" :key="'ce_' + bill.id">
                        <div class="px-4 py-2 border-b border-orange-100 dark:border-orange-900/30 flex items-center justify-between gap-2">
                            <div>
                                <span class="text-xs font-bold text-gray-800 dark:text-gray-200" x-text="bill.invoice_number"></span>
                                <span class="ml-1 text-[10px] text-gray-500" x-text="'Rs. ' + Number(bill.total_amount).toLocaleString()"></span>
                                <span class="ml-1 text-[9px] px-1.5 py-0.5 rounded-full bg-orange-100 text-orange-700 font-bold">Settings Error</span>
                                {{-- Task 627: asal wajah (kaun si setting missing hai) --}}
                                <template x-if="bill.error_message">
                                    <p class="text-[10px] text-orange-600 dark:text-orange-400 leading-snug" x-text="'⚠ ' + bill.error_message"></p>
                                </template>
                            </div>
                            <button @click="retryFailed(bill)" :disabled="bill._retrying" title="{{ __('pos.retry_after_fix_title') }}"
                                class="shrink-0 px-2.5 py-1 text-[10px] font-bold text-white bg-orange-500 hover:bg-orange-600 rounded-lg transition disabled:opacity-40 flex items-center gap-1">
                                <svg x-show="!bill._retrying" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                <svg x-show="bill._retrying" class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                <span x-text="bill._retrying ? '...' : 'Retry'"></span>
                            </button>
                        </div>
                    </template>
                    <div class="px-4 py-2">
                        <a href="{{ route('fbrpos.settings') }}" class="text-[10px] text-orange-600 hover:underline font-bold">→ {{ __('pos.open_fbr_settings') }}</a>
                    </div>
                </div>
            </template>
            <div x-show="failedBills.length > 0 || configErrorBills.length > 0" class="p-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 text-[11px] text-gray-500 flex items-center justify-between">
                <span>{{ __('pos.failed_tip_fbr') }}</span>
                <a href="{{ route('fbrpos.failQueue') }}" class="text-red-600 hover:underline font-semibold">{{ __('pos.open_full_page') }}</a>
            </div>
        </div>
    </div>

    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    {{-- PENDING DELIVERIES panel (Task 122 — FBR port of PRA Task 114).        --}}
    {{-- TODAY's (business day) delivery provisionals — payment aate hi ek      --}}
    {{-- click Final (Cash/Card) via the SAME promote path as F10 Make Final.  --}}
    {{-- Receipt print = opt-in checkbox (default NO). Task 517: UNASSIGNED    --}}
    {{-- final delivery bills bhi listed with an inline rider dropdown (POST   --}}
    {{-- fbrpos.deliveries.assign). Task 521 (PRA parity): assigned/dispatched --}}
    {{-- + delivered-cash-unsettled finals bhi — Delivered mark (POST          --}}
    {{-- fbrpos.deliveries.status) + whole-khata Settle (fbrpos.riders.settle).--}}
    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    {{-- Task 543: Rider settle amount modal (replaces window.prompt) — deliveries.blade.php
         pattern: default full baqaya, live "Baqaya:" line, over-amount disables confirm.
         Sits ABOVE the pending-deliveries modal (inline z-index — no Tailwind rebuild dep). --}}
    <div x-show="riderSettleBill" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4" style="z-index: 60;" @click.self="riderSettleBill = null">
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700 w-full max-w-sm p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.settle_cash') }} — <span x-text="riderSettleBill ? (riderSettleBill.rider_name || @json(__('pos.rider_word'))) : ''"></span></h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3" x-text="riderSettleBill ? txtRiderSettleScope(riderSettleBill) : ''"></p>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.cash_received_now') }}</label>
            <input type="number" id="rider-settle-amount" x-model="riderSettleAmount" min="1" step="0.01" inputmode="decimal"
                   @keydown.enter.prevent="submitRiderSettle()"
                   {{-- Task 545: global keydown handler ignores keys from form fields, so Escape must be handled on the input itself --}}
                   @keydown.escape.prevent.stop="riderSettleBill = null"
                   class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm font-bold focus:ring-teal-500 focus:border-teal-500">
            <p class="text-[11px] text-gray-400 mt-1">{{ __('pos.settle_partial_hint') }}</p>
            <p class="text-[11px] font-semibold text-red-600 dark:text-red-400 mt-1" x-show="parseFloat(riderSettleAmount || 0) > riderSettleOutstanding + 0.009" x-cloak>{{ __('pos.settle_amount_over_live') }}</p>
            <div class="flex items-center justify-between gap-3 mt-4">
                <div class="text-xs font-bold" :class="(riderSettleOutstanding - (parseFloat(riderSettleAmount) || 0)) > 0.009 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'">{{ __('pos.baqaya_colon') }} Rs. <span x-text="Math.max(0, riderSettleOutstanding - (parseFloat(riderSettleAmount) || 0)).toLocaleString()"></span></div>
                <div class="flex gap-2">
                    <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition" @click="riderSettleBill = null">{{ __('pos.cancel') }}</button>
                    <button type="button" class="px-4 py-2 rounded-lg bg-teal-600 text-white text-sm font-semibold shadow-sm hover:bg-teal-700 transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-1.5"
                            :disabled="riderSettleBusyId || !(parseFloat(riderSettleAmount) > 0) || parseFloat(riderSettleAmount) > riderSettleOutstanding + 0.009"
                            @click="submitRiderSettle()">
                        <template x-if="riderSettleBusyId"><svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg></template>
                        {{ __('pos.confirm_settlement') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
    <div x-show="showPendingDeliveries" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showPendingDeliveries = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between bg-amber-50 dark:bg-amber-900/20 flex-shrink-0">
                <div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                        <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
                        {{ __('pos.pending_deliveries_title') }} <span class="text-xs font-medium text-amber-600 ml-1" x-text="'(' + pendingDeliveryBills().length + ')'"></span>
                    </h3>
                    <p class="text-[10px] text-gray-500 mt-0.5">{{ __('pos.pending_deliveries_hint') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="loadLocalBills()" :disabled="localBillsLoading" class="text-xs text-amber-600 hover:text-amber-800 font-semibold px-2 py-1 rounded hover:bg-amber-100 disabled:opacity-50" title="{{ __('pos.ti_refresh_list') }}">
                        <svg class="w-4 h-4" :class="localBillsLoading ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                    <button @click="showPendingDeliveries = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
            </div>
            <div class="flex-1 overflow-y-auto">
                <template x-if="localBillsLoading && pendingDeliveryBills().length === 0">
                    <div class="p-12 text-center text-gray-400">
                        <svg class="w-8 h-8 mx-auto mb-2 animate-spin text-amber-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        <p class="text-sm">{{ __('pos.loading_provisional_bills') }}</p>
                    </div>
                </template>
                <template x-if="!localBillsLoading && pendingDeliveryBills().length === 0 && staleDeliveryBills().length === 0">
                    <div class="p-12 text-center text-gray-400">
                        <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <p class="text-sm font-medium">{{ __('pos.no_pending_deliveries') }}</p>
                    </div>
                </template>
                {{-- x-for cap: server already limits to 50 provisionals; slice keeps the
                     render bounded even if that ever changes (pos-boot-splash-perf rule). --}}
                <template x-for="bill in pendingDeliveryBills().slice(0, 100)" :key="bill.id">
                    <div class="p-4 border-b border-gray-100 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-1.5">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-bold text-gray-900 dark:text-white" x-text="bill.invoice_number"></span>
                                <template x-if="bill.order_type === 'delivery'">
                                    <span class="text-[9px] bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300 px-2 py-0.5 rounded-full font-bold uppercase tracking-wide" x-text="window.TXT.delivery"></span>
                                </template>
                                <template x-if="bill.rider_name">
                                    <span class="text-[9px] bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-300 px-2 py-0.5 rounded-full font-bold" x-text="'{{ __('pos.rider_word') }}: ' + bill.rider_name"></span>
                                </template>
                                <template x-if="bill.is_final">
                                    <span class="text-[9px] bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300 px-2 py-0.5 rounded-full font-bold uppercase tracking-wide">{{ __('pos.final_word') }}</span>
                                </template>
                                {{-- Task 517: unassigned final delivery bill — rider abhi tak nahi laga --}}
                                <template x-if="bill.is_final && !bill.rider_id">
                                    <span class="text-[9px] bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300 px-2 py-0.5 rounded-full font-bold">{{ __('pos.del_status_unassigned') }}</span>
                                </template>
                            </div>
                            <span class="text-sm font-bold text-amber-700 dark:text-amber-400" x-text="'Rs. ' + Number(bill.total_amount).toLocaleString()"></span>
                        </div>
                        <template x-if="bill.customer_name || bill.customer_phone">
                            <p class="text-[11px] font-semibold text-gray-700 dark:text-gray-300 flex items-center gap-1.5 flex-wrap">
                                <svg class="w-3 h-3 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                <span x-text="bill.customer_name || window.TXT.customer_word"></span>
                                <template x-if="bill.customer_phone">
                                    <span class="font-mono font-medium text-gray-500" x-text="bill.customer_phone"></span>
                                </template>
                            </p>
                        </template>
                        <template x-if="bill.delivery_address">
                            <p class="text-[11px] text-gray-500 flex items-start gap-1.5">
                                <svg class="w-3 h-3 mt-0.5 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                <span x-text="bill.delivery_address"></span>
                            </p>
                        </template>
                        <p class="text-[11px] text-gray-500 mb-2" x-text="bill.items_count + window.TXT.sfx_item_s_dot + (bill.created_time || bill.created_human)"></p>
                        {{-- Task 521: rider-khata warning — bill is still on the rider's
                             unsettled khata (cash rider ke paas). Settle button covers the
                             rider's WHOLE khata (FbrPosRiderController::settle settle_all).
                             Task 990: gate to is_final — provisional rider is pre-assigned only
                             (cash nahi aayi abhi), settle confusing hoga; khata math untouched. --}}
                        <template x-if="bill.rider_unsettled && bill.is_final">
                            <div class="mb-2 px-3 py-2 rounded-lg bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 text-[11px] font-semibold text-orange-700 dark:text-orange-300 flex items-start gap-1.5">
                                <svg class="w-3.5 h-3.5 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                <div class="flex-1 min-w-0">
                                    <span><span x-text="bill.rider_name || '{{ __('pos.rider_word') }}'"></span> {{ __('pos.rider_unsettled_warn') }}</span>
                                    {{-- Scope line: settle covers the rider's WHOLE khata, not just this bill --}}
                                    <p class="mt-1 font-normal text-orange-600 dark:text-orange-400" x-text="txtRiderSettleScope(bill)"></p>
                                </div>
                                {{-- One-click WHOLE-khata settle (reuses POST /fbr-pos/riders/{id}/settle) --}}
                                <button @click="settleRider(bill)" :disabled="riderSettleBusyId || deliveryFinalBusyId"
                                        class="shrink-0 self-center px-3 py-1.5 text-[11px] font-bold text-white bg-orange-600 hover:bg-orange-700 rounded-lg transition shadow-sm flex items-center gap-1 disabled:opacity-50">
                                    <template x-if="riderSettleBusyId === bill.rider_id"><svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg></template>
                                    {{ __('pos.rider_settle_btn') }}
                                </button>
                            </div>
                        </template>
                        {{-- Task 517: UNASSIGNED delivery bill — rider dropdown yahin se
                             (POST fbrpos.deliveries.assign, same backend as the Deliveries board).
                             Renders only when the API's can_assign_rider allows (plan gate +
                             Delivery feature + custom-access verdict, mirrored server-side).
                             Task 984: PROVISIONAL rows par bhi — assign endpoint sirf
                             settled/delivered/returned block karta hai, provisional allowed,
                             so cashier Final se pehle hi rider chun sakta hai. --}}
                        <template x-if="!bill.rider_id && canAssignRider && deliveryRiders.length > 0">
                            <div class="mb-2 flex items-center gap-2">
                                <svg class="w-4 h-4 text-red-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a2 2 0 104 0m-4 0a2 2 0 11-4 0m10 0a2 2 0 104 0"/></svg>
                                <select @change="assignRider(bill, $event.target.value); $event.target.value = ''"
                                        :disabled="riderAssignBusyId"
                                        class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs py-1.5 focus:ring-teal-500 focus:border-teal-500 disabled:opacity-50">
                                    <option value="">{{ __('pos.no_rider_opt') }}</option>
                                    {{-- Task 1132: 🪫 low-battery marker (≤20%, on-duty; NULL = old APK, shows nothing) --}}
                                    <template x-for="r in deliveryRiders" :key="r.id">
                                        <option :value="r.id" x-text="r.name + (r.battery_pct != null && r.battery_pct <= 20 ? ' 🪫 ' + r.battery_pct + '%' : '')"></option>
                                    </template>
                                </select>
                                <template x-if="riderAssignBusyId === bill.id">
                                    <svg class="w-4 h-4 animate-spin text-teal-500 shrink-0" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                </template>
                            </div>
                        </template>
                        {{-- PROVISIONAL bill: Final Cash/Card (promote path). FINAL bills
                             par yeh buttons render hi nahi hote — promote unpar kabhi nahi. --}}
                        <template x-if="!bill.is_final">
                        <div class="flex gap-2">
                            <button @click="finalizeDelivery(bill, 'cash')" :disabled="deliveryFinalBusyId" class="flex-1 py-2.5 text-xs font-bold text-white bg-green-600 hover:bg-green-700 rounded-xl transition shadow-sm flex items-center justify-center gap-1.5 disabled:opacity-50">
                                <template x-if="deliveryFinalBusyId === bill.id"><svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg></template>
                                <template x-if="deliveryFinalBusyId !== bill.id"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg></template>
                                {{ __('pos.final_cash') }}
                            </button>
                            <button @click="finalizeDelivery(bill, 'card')" :disabled="deliveryFinalBusyId" class="flex-1 py-2.5 text-xs font-bold text-white bg-teal-600 hover:bg-teal-700 rounded-xl transition shadow-sm flex items-center justify-center gap-1.5 disabled:opacity-50">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                                {{ __('pos.final_card') }}
                            </button>
                        </div>
                        </template>
                        {{-- Task 984: UNASSIGNED final bill — "Delivered (bina rider)" bhi
                             ab yahin se (Task 774 riderless updateStatus path; delivered_by
                             stamp hota hai, khata/settlement untouched). Purana Task 521
                             rider_id guard hata: backend ab riderless delivered ALLOW karta hai. --}}
                        <template x-if="bill.is_final && !bill.rider_id && !bill.delivery_status">
                            <button @click="markFinalDelivered(bill)" :disabled="deliveryFinalBusyId" class="w-full py-2.5 text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 rounded-xl transition shadow-sm flex items-center justify-center gap-1.5 disabled:opacity-50">
                                <template x-if="deliveryFinalBusyId === bill.id"><svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg></template>
                                <template x-if="deliveryFinalBusyId !== bill.id"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg></template>
                                {{ __('pos.delivered_no_rider_btn') }}
                            </button>
                        </template>
                        {{-- Task 521: FINAL bill — status chip + Delivered mark (PRA parity).
                             Cash khata settle upar wale orange rider block se hota hai. --}}
                        <template x-if="bill.is_final && bill.rider_id">
                        <div class="flex gap-2 items-stretch">
                            <span class="flex items-center px-2.5 rounded-xl text-[10px] font-bold"
                                  :class="bill.delivery_status === 'delivered' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400' : 'bg-teal-50 text-teal-700 dark:bg-teal-900/20 dark:text-teal-300'"
                                  x-text="bill.delivery_status === 'delivered' ? @json(__('pos.delivery_status_delivered')) : (bill.delivery_status === 'dispatched' ? @json(__('pos.delivery_status_dispatched')) : @json(__('pos.delivery_status_assigned')))"></span>
                            <template x-if="bill.delivery_status !== 'delivered'">
                                <button @click="markFinalDelivered(bill)" :disabled="deliveryFinalBusyId" class="flex-1 py-2.5 text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 rounded-xl transition shadow-sm flex items-center justify-center gap-1.5 disabled:opacity-50">
                                    <template x-if="deliveryFinalBusyId === bill.id"><svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg></template>
                                    <template x-if="deliveryFinalBusyId !== bill.id"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg></template>
                                    {{ __('pos.delivered_word') }}
                                </button>
                            </template>
                        </div>
                        </template>
                    </div>
                </template>
                {{-- Task 524: purane (pichhle business days ke) UNASSIGNED delivery
                     bills — alag collapsed "Purani deliveries" group, badge/ginti
                     mein shamil NAHIN. Assign dropdown wahi maujooda assignRider
                     (POST fbr-pos/deliveries assign) chalata hai. --}}
                <template x-if="staleDeliveryBills().length > 0">
                    <div class="border-t-4 border-gray-100 dark:border-gray-800">
                        <button type="button" @click="showOldDeliveries = !showOldDeliveries"
                                class="w-full flex items-center justify-between px-4 py-3 text-left bg-gray-50 dark:bg-gray-800/40 hover:bg-gray-100 dark:hover:bg-gray-800 transition">
                            <span class="flex items-center gap-2 text-xs font-bold text-gray-600 dark:text-gray-300">
                                <svg class="w-3.5 h-3.5 text-gray-400 transition-transform" :class="showOldDeliveries ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                {{ __('pos.old_del_section') }}
                                <span class="px-1.5 py-0.5 rounded-full text-[10px] font-extrabold bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300" x-text="staleDeliveryBills().length"></span>
                            </span>
                        </button>
                        <template x-if="showOldDeliveries">
                            <div>
                                <p class="px-4 pt-2 text-[10px] text-gray-400">{{ __('pos.old_del_hint') }}</p>
                                <template x-for="bill in staleDeliveryBills().slice(0, 50)" :key="'old-' + bill.id">
                                    <div class="p-4 border-b border-gray-100 dark:border-gray-800">
                                        <div class="flex items-center justify-between mb-1.5">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="text-sm font-bold text-gray-700 dark:text-gray-300" x-text="bill.invoice_number"></span>
                                                {{-- Halka (gray) chip — purana bill koi RED demand nahi (Task 524) --}}
                                                <span class="text-[9px] bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400 px-2 py-0.5 rounded-full font-bold">{{ __('pos.del_status_unassigned') }}</span>
                                            </div>
                                            <span class="text-sm font-bold text-gray-700 dark:text-gray-300" x-text="'Rs. ' + Number(bill.total_amount).toLocaleString()"></span>
                                        </div>
                                        <template x-if="bill.customer_name || bill.customer_phone">
                                            <p class="text-[11px] font-semibold text-gray-600 dark:text-gray-400" x-text="(bill.customer_name || window.TXT.customer_word) + (bill.customer_phone ? ' · ' + bill.customer_phone : '')"></p>
                                        </template>
                                        <template x-if="bill.delivery_address">
                                            <p class="text-[11px] text-gray-500" x-text="bill.delivery_address"></p>
                                        </template>
                                        <p class="text-[11px] text-gray-400 mb-2" x-text="(bill.business_date ? bill.business_date + ' · ' : '') + (bill.created_time || '') + (bill.created_human ? ' (' + bill.created_human + ')' : '')"></p>
                                        <template x-if="canAssignRider && deliveryRiders.length > 0">
                                            <div class="flex items-center gap-2">
                                                <select @change="assignRider(bill, $event.target.value); $event.target.value = ''"
                                                        :disabled="riderAssignBusyId"
                                                        class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs py-1.5 focus:ring-teal-500 focus:border-teal-500 disabled:opacity-50">
                                                    <option value="">{{ __('pos.no_rider_opt') }}</option>
                                                    <template x-for="r in deliveryRiders" :key="'oldr-' + r.id">
                                                        <option :value="r.id" x-text="r.name + (r.battery_pct != null && r.battery_pct <= 20 ? ' 🪫 ' + r.battery_pct + '%' : '')"></option>
                                                    </template>
                                                </select>
                                                <template x-if="riderAssignBusyId === bill.id">
                                                    <svg class="w-4 h-4 animate-spin text-teal-500 shrink-0" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
            <div class="p-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 flex-shrink-0">
                <label class="flex items-center gap-2 text-[11px] text-gray-600 dark:text-gray-300 cursor-pointer select-none">
                    <input type="checkbox" x-model="deliveryPrintReceipt" @change="try{localStorage.setItem('fbrpos_delivery_final_print', deliveryPrintReceipt ? '1' : '0')}catch(e){}" class="w-4 h-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                    <span>{{ __('pos.delivery_print_receipt') }}</span>
                </label>
            </div>
        </div>
    </div>

    {{-- Legacy popup new-customer modal removed — replaced by inline quick-add form below the phone input (Phase 2 spec: NO popups, INLINE only). --}}

    <div x-show="showCustomerPicker" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showCustomerPicker = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md max-h-[80vh] overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ __('pos.select_customer') }}</h3>
                <button @click="showCustomerPicker = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <div class="p-3 border-b border-gray-100 dark:border-gray-800">
                <input type="text" x-model="customerSearch" @input="onCustomerPhoneSearch()" placeholder="{{ __('pos.ph_search_name_phone') }}" autocomplete="one-time-code" name="pos_custsearch_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white text-sm px-3 py-2 focus:ring-blue-500">
                <template x-if="customerLookupResult && customerLookupResult.found">
                    <div class="mt-2 p-2.5 bg-green-50 dark:bg-green-900/20 rounded-xl border border-green-200 dark:border-green-800">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-full bg-green-200 dark:bg-green-800 flex items-center justify-center flex-shrink-0"><span class="text-xs font-bold text-green-700" x-text="customerLookupResult.customer.name.charAt(0)"></span></div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-bold text-green-800 dark:text-green-200" x-text="customerLookupResult.customer.name"></p>
                                <p class="text-[10px] text-green-600" x-text="customerLookupResult.stats.total_orders + window.TXT.sfx_orders_rs + Number(customerLookupResult.stats.total_spent).toLocaleString() + window.TXT.sfx_spent"></p>
                                <template x-if="customerLookupResult.customer.address">
                                    <p class="text-[10px] text-green-500 truncate" x-text="'📍 ' + customerLookupResult.customer.address"></p>
                                </template>
                            </div>
                            <template x-if="customerLookupResult.stats.is_frequent"><span class="freq-badge">VIP</span></template>
                            <button @click="selectLookedUpCustomer()" class="px-3 py-1 text-xs font-bold text-white bg-green-600 rounded-lg flex-shrink-0">{{ __('pos.select') }}</button>
                        </div>
                    </div>
                </template>
            </div>
            <div class="max-h-[40vh] overflow-y-auto">
                <button @click="selectedCustomer = null; customerStats = null; showCustomerPicker = false" class="w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition border-b border-gray-100 dark:border-gray-800">
                    <div class="w-9 h-9 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center"><svg class="w-5 h-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg></div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">{{ __('pos.walk_in_customer') }}</span>
                </button>
                <template x-for="c in filteredCustomers" :key="c.id">
                    <div class="w-full flex items-center gap-3 px-4 py-3 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition border-b border-gray-50 dark:border-gray-800">
                        <button @click="selectCustomerWithStats(c)" class="flex items-center gap-3 flex-1 min-w-0">
                            <div class="w-9 h-9 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0"><span class="text-sm font-bold text-blue-600 dark:text-blue-400" x-text="c.name.charAt(0)"></span></div>
                            <div class="text-left min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white truncate" x-text="c.name"></p>
                                <p class="text-xs text-gray-400" x-text="c.phone || window.TXT.no_phone"></p>
                                <template x-if="c.address"><p class="text-[10px] text-gray-400 truncate" x-text="'📍 ' + c.address"></p></template>
                            </div>
                        </button>
                        <button @click="loadCustomerHistory(c.id)" class="flex-shrink-0 text-[9px] font-bold text-blue-600 hover:text-blue-800 bg-blue-50 dark:bg-blue-900/30 px-2 py-1 rounded-lg transition" title="{{ __('pos.ti_view_history') }}">
                            <svg class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </button>
                    </div>
                </template>
            </div>
            <div class="p-3 border-t border-gray-200 dark:border-gray-700">
                <div x-show="!showQuickAdd">
                    <button @click="showQuickAdd = true" class="w-full py-2.5 text-sm font-bold text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/20 rounded-xl hover:bg-blue-100 transition">{{ __('pos.add_new_customer_btn') }}</button>
                </div>
                <div x-show="showQuickAdd" class="space-y-2">
                    <input type="text" x-model="quickCustomerName" placeholder="Customer name *" autocomplete="one-time-code" name="pos_quickcust_name_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-blue-500">
                    <input type="text" x-model="quickCustomerPhone" placeholder="{{ __('pos.ph_phone_req') }}" autocomplete="one-time-code" name="pos_quickcust_phone_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-blue-500">
                    @if($features->delivery)
                    <input type="text" x-model="quickCustomerAddress" placeholder="{{ __('pos.ph_address_delivery') }}" autocomplete="one-time-code" name="pos_quickcust_addr_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-blue-500">
                    @endif
                    <div class="flex gap-2">
                        <button @click="showQuickAdd = false" class="flex-1 py-2 text-xs font-semibold text-gray-500 bg-gray-100 dark:bg-gray-800 rounded-xl">{{ __('pos.cancel') }}</button>
                        <button @click="addQuickCustomer()" class="flex-1 py-2 text-xs font-bold text-white bg-blue-600 rounded-xl hover:bg-blue-700">{{ __('pos.save_btn') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    {{-- QUICK RETURN MODAL (Task 685) — bill number → existing FBR return form. --}}
    {{-- Lookup + return rules all server-side (fbrpos.phase2.return.lookup).    --}}
    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    <div x-show="quickReturnOpen" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="quickReturnOpen = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md flex flex-col overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 bg-rose-50 dark:bg-rose-900/20 flex-shrink-0">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            <svg class="w-5 h-5 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 15v-1a4 4 0 00-4-4H8m0 0l3 3m-3-3l3-3m9 14V5a2 2 0 00-2-2H6a2 2 0 00-2 2v16l4-2 4 2 4-2 4 2z"/></svg>
                            {{ __('pos.quick_return_title') }}
                        </h3>
                        <p class="text-[10px] text-gray-500 mt-0.5">{{ __('pos.quick_return_hint') }}</p>
                    </div>
                    <button @click="quickReturnOpen = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
            </div>
            <div class="p-4 space-y-3">
                {{-- nofill guards (memory: pos-sale-screen-autofill-guards) --}}
                <input type="text" id="tn-quick-return-input" x-model="quickReturnQ"
                       @keydown.enter.prevent="submitQuickReturn()"
                       @keydown.escape.prevent="quickReturnOpen = false"
                       autocomplete="off" name="quick_return_q_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                       placeholder="{{ __('pos.quick_return_placeholder_fbr') }}"
                       class="w-full px-4 py-3 text-base font-bold rounded-xl border-2 border-rose-200 dark:border-rose-800 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:border-rose-500 focus:outline-none tn-num">
                <p x-show="quickReturnErr" x-cloak class="text-xs font-bold text-red-600 dark:text-red-400" x-text="quickReturnErr"></p>
                <button @click="submitQuickReturn()" :disabled="quickReturnBusy || !(quickReturnQ || '').trim()"
                        class="w-full py-3 rounded-xl font-bold text-white bg-rose-600 hover:bg-rose-700 disabled:opacity-50 disabled:cursor-not-allowed transition">
                    <span x-show="!quickReturnBusy">{{ __('pos.quick_return_open_btn') }}</span>
                    <span x-show="quickReturnBusy" x-cloak>{{ __('pos.searching_word') }}</span>
                </button>
            </div>
        </div>
    </div>

    <div x-show="showShortcuts" x-cloak x-transition.opacity @click.self="showShortcuts = false" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" style="display:none;">
        <div x-show="showShortcuts" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" @click.stop style="max-width:520px; width:100%; max-height:85vh; overflow-y:auto; background:white; border-radius:20px; box-shadow:0 25px 60px rgba(0,0,0,0.3);" class="dark:bg-gray-900">
            <div style="background:linear-gradient(135deg,#2563eb,#6d28d9); padding:20px 24px; border-radius:20px 20px 0 0; display:flex; align-items:center; justify-content:space-between;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div style="width:36px; height:36px; background:rgba(255,255,255,0.2); border-radius:10px; display:flex; align-items:center; justify-content:center;">
                        <svg style="width:20px; height:20px; color:white;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3C6.5 3 2 6.58 2 11c0 2.24 1.12 4.27 2.94 5.72L4 21l4.28-2.55c1.15.35 2.4.55 3.72.55 5.5 0 10-3.58 10-8s-4.5-8-10-8z"/></svg>
                    </div>
                    <div>
                        <h3 style="color:white; font-size:16px; font-weight:800; margin:0;">{{ __('pos.keyboard_shortcuts') }}</h3>
                        <p style="color:rgba(255,255,255,0.7); font-size:11px; margin:0;">{{ __('pos.press_f1_hint') }}</p>
                    </div>
                </div>
                <button @click="showShortcuts = false" style="width:28px; height:28px; background:rgba(255,255,255,0.15); border:none; border-radius:8px; color:white; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                    <svg style="width:16px; height:16px;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div style="padding:16px 24px 24px;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div>
                        <p style="font-size:10px; font-weight:800; color:#2563eb; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">{{ __('pos.quick_actions') }}</p>
                        <div style="display:flex; flex-direction:column; gap:6px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.shortcuts_panel') }}</span>
                                <kbd style="background:linear-gradient(135deg,#2563eb,#6d28d9); color:white; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F1</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.order_type_cycle') }}</span>
                                <kbd style="background:#e9d5ff; color:#2563eb; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F2</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.held_orders') }}</span>
                                <kbd style="background:#e9d5ff; color:#2563eb; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F3</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.clear_cart') }}</span>
                                <kbd style="background:#e9d5ff; color:#2563eb; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F4</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.hold_order') }}</span>
                                <kbd style="background:#e9d5ff; color:#2563eb; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F5</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.jump_to_cart') }}</span>
                                <kbd style="background:#e9d5ff; color:#2563eb; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F6</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.customer_select') }}</span>
                                <kbd style="background:#e9d5ff; color:#2563eb; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Alt+P</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.pay_checkout') }}</span>
                                <kbd style="background:linear-gradient(135deg,#16a34a,#15803d); color:white; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F8</kbd>
                            </div>
                        </div>
                    </div>
                    <div>
                        <p style="font-size:10px; font-weight:800; color:#2563eb; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">{{ __('pos.navigation') }}</p>
                        <div style="display:flex; flex-direction:column; gap:6px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.product_search') }}</span>
                                <kbd style="background:#e9d5ff; color:#2563eb; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Ctrl+S</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.edit_cart_mode') }}</span>
                                <kbd style="background:#e9d5ff; color:#2563eb; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Ctrl+E</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.customer_field') }}</span>
                                <kbd style="background:#e9d5ff; color:#2563eb; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Ctrl+C</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.grid_navigate') }}</span>
                                <kbd style="background:#e9d5ff; color:#2563eb; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Tab</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.close_back') }}</span>
                                <kbd style="background:#e9d5ff; color:#2563eb; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Esc</kbd>
                            </div>
                        </div>
                        <p style="font-size:10px; font-weight:800; color:#2563eb; text-transform:uppercase; letter-spacing:1px; margin:14px 0 8px;">{{ __('pos.cart_edit_mode') }}</p>
                        <div style="display:flex; flex-direction:column; gap:6px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.navigate_items') }}</span>
                                <kbd style="background:#e9d5ff; color:#2563eb; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">&#8593; &#8595;</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.qty_up_down') }}</span>
                                <kbd style="background:#e9d5ff; color:#2563eb; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">+ / -</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.set_qty_direct') }}</span>
                                <kbd style="background:#e9d5ff; color:#2563eb; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">0-9</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.remove_item') }}</span>
                                <kbd style="background:#fecaca; color:#dc2626; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Del</kbd>
                            </div>
                        </div>
                    </div>
                </div>
                <div style="margin-top:16px; padding:10px 14px; background:linear-gradient(135deg,#dbeafe,#e0e7ff); border-radius:10px; display:flex; align-items:center; gap:8px;" class="dark:bg-blue-900/20">
                    <svg style="width:14px; height:14px; color:#2563eb; flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p style="font-size:11px; color:#6b21a8; margin:0; font-weight:500;" class="dark:text-blue-300">{{ __('pos.type_letter_search_hint') }}</p>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         QUICK TYPE MODE — type free-form lines like "chai 2, samosa 1"
         and the parser fuzzy-matches each entry against the product list,
         then bulk-adds to cart. Plus an "Add Random Product" button for
         lightning-fast demo / stress-testing.
         Open: F7 or toolbar "Quick" button. Close: Esc.
         ═══════════════════════════════════════════════════════════════ --}}
    <div x-show="showQuickType" x-cloak x-transition.opacity @click.self="showQuickType = false" @keydown.escape.window="if(showQuickType) showQuickType = false" class="fixed inset-0 bg-gradient-to-br from-sky-950/70 via-black/70 to-blue-950/70 backdrop-blur-md z-50 flex items-center justify-center p-4" style="display:none;">
        <div x-show="showQuickType" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-90 translate-y-4" x-transition:enter-end="opacity-100 scale-100 translate-y-0" @click.stop class="bg-white dark:bg-gray-900 rounded-3xl shadow-2xl w-full max-w-2xl overflow-hidden ring-1 ring-sky-200/50 dark:ring-sky-800/50" style="box-shadow: 0 25px 80px -20px rgba(2, 132, 199, 0.55);">
            {{-- Header — sky/blue gradient with subtle glow --}}
            <div class="relative px-6 py-5 flex items-center justify-between" style="background:linear-gradient(135deg,#0284c7 0%,#0369a1 50%,#1e40af 100%);">
                <div class="relative flex items-center gap-3">
                    <div class="w-11 h-11 bg-white/25 backdrop-blur-sm rounded-2xl flex items-center justify-center shadow-lg ring-1 ring-white/30">
                        <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <div>
                        <h3 class="text-white text-lg font-extrabold m-0 tracking-tight flex items-center gap-2">{{ __('pos.quick_type') }} <span class="text-[9px] font-bold bg-white/20 px-1.5 py-0.5 rounded-md ring-1 ring-white/30 uppercase tracking-wider">F7</span></h3>
                        <p class="text-white/75 text-[11px] m-0 font-medium">{{ __('pos.quick_type_blurb') }}</p>
                    </div>
                </div>
                <button @click="showQuickType = false" class="relative w-8 h-8 bg-white/15 hover:bg-white/30 rounded-xl text-white flex items-center justify-center transition-all hover:rotate-90 ring-1 ring-white/20" title="{{ __('pos.ti_close_esc') }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-6 space-y-4 bg-gradient-to-b from-white to-sky-50/30 dark:from-gray-900 dark:to-sky-950/20">
                {{-- Textarea --}}
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-[10px] font-extrabold uppercase tracking-[0.15em] text-sky-700 dark:text-sky-400 flex items-center gap-1.5">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/></svg>
                            {{ __('pos.items') }}
                        </label>
                        <span class="text-[10px] text-gray-400 dark:text-gray-500 font-mono" x-show="quickTypeText.length > 0" x-text="(quickTypeText.split(/[,;\n]+/).filter(s=>s.trim()).length) + window.TXT.sfx_lines"></span>
                    </div>
                    <div class="relative">
                        <textarea x-model="quickTypeText" autocomplete="off" name="pos_quicktype_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore @input="parseQuickTypeText()" @keydown.ctrl.enter.prevent="applyQuickType()" @keydown.meta.enter.prevent="applyQuickType()" x-init="$nextTick(() => $el.focus())" rows="5" placeholder="chai 2&#10;samosa 1&#10;paratha 3&#10;&#10;(or comma-separated: chai 2, samosa 1)" class="w-full text-sm rounded-2xl border-2 border-sky-200 dark:border-sky-800 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-4 py-3 focus:ring-4 focus:ring-sky-500/20 focus:border-sky-500 font-mono leading-relaxed transition-all shadow-sm hover:shadow-md"></textarea>
                    </div>
                    <div class="flex items-center justify-between mt-2 px-1">
                        <p class="text-[10px] text-gray-500 dark:text-gray-400">
                            {{ __('pos.format_label') }} <code class="bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-300 px-1.5 py-0.5 rounded font-semibold">name qty</code> &middot; <code class="bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-300 px-1.5 py-0.5 rounded font-semibold">qty name</code>
                        </p>
                        <p class="text-[10px] text-gray-500 dark:text-gray-400 hidden sm:block">
                            <kbd class="bg-gradient-to-b from-gray-100 to-gray-200 dark:from-gray-700 dark:to-gray-800 text-gray-700 dark:text-gray-200 px-1.5 py-0.5 rounded text-[10px] font-bold border border-gray-300 dark:border-gray-600 shadow-sm">Ctrl</kbd>
                            +
                            <kbd class="bg-gradient-to-b from-gray-100 to-gray-200 dark:from-gray-700 dark:to-gray-800 text-gray-700 dark:text-gray-200 px-1.5 py-0.5 rounded text-[10px] font-bold border border-gray-300 dark:border-gray-600 shadow-sm">Enter</kbd>
                            to add
                        </p>
                    </div>
                </div>

                {{-- Empty-state hint when textarea is blank --}}
                <template x-if="quickTypeParsed.length === 0">
                    <div class="rounded-2xl border-2 border-dashed border-sky-200 dark:border-sky-800 bg-sky-50/50 dark:bg-sky-900/10 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-sky-100 dark:bg-sky-900/40 flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-sky-600 dark:text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div class="text-[11px] text-sky-700 dark:text-sky-300 leading-snug">
                            <span class="font-bold block">{{ __('pos.tip') }}</span>
                            {{ __('pos.quick_type_parser_hint') }}
                            <template x-if="!isInventoryEnabled()">
                                <span class="block mt-1 text-amber-700 dark:text-amber-400">{{ __('pos.quick_type_unmatched_hint') }}</span>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Preview list with check / x icons + subtotal --}}
                <template x-if="quickTypeParsed.length > 0">
                    <div class="rounded-2xl border border-sky-200 dark:border-sky-800 bg-gradient-to-br from-sky-50 to-blue-50/50 dark:from-sky-950/30 dark:to-blue-950/20 overflow-hidden shadow-sm">
                        <div class="flex items-center justify-between px-4 py-2.5 bg-white/60 dark:bg-black/20 border-b border-sky-200/60 dark:border-sky-800/60">
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] font-extrabold uppercase tracking-[0.15em] text-sky-700 dark:text-sky-400">{{ __('pos.preview') }}</span>
                                <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300">
                                    <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                    <span x-text="quickTypeParsed.filter(p => p.match).length"></span> matched
                                </span>
                                {{-- Inventory ON → unmatched stays as red "not found" (cannot be added). --}}
                                <template x-if="quickTypeParsed.filter(p => !p.match).length > 0 && isInventoryEnabled()">
                                    <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300">
                                        <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                                        <span x-text="quickTypeParsed.filter(p => !p.match).length"></span> not found
                                    </span>
                                </template>
                                {{-- Inventory OFF → unmatched becomes amber "manual entry" — cashier fills price inline. --}}
                                <template x-if="quickTypeParsed.filter(p => !p.match).length > 0 && !isInventoryEnabled()">
                                    <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300" :title="window.TXT.ti_type_price_unmatched">
                                        <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                                        <span x-text="quickTypeParsed.filter(p => !p.match).length"></span> manual
                                    </span>
                                </template>
                            </div>
                            <span class="text-[11px] font-bold text-sky-700 dark:text-sky-300" x-text="'Rs. ' + Number(quickTypeParsed.reduce((s, p) => p.match ? s + parseFloat(p.match.price) * p.qty : (!isInventoryEnabled() && parseFloat(p.manualPrice) > 0 ? s + parseFloat(p.manualPrice) * p.qty : s), 0)).toLocaleString()"></span>
                        </div>
                        <div class="divide-y divide-sky-100 dark:divide-sky-900/40 max-h-52 overflow-y-auto">
                            <template x-for="(p, idx) in quickTypeParsed" :key="idx">
                                <div class="flex items-center gap-3 px-4 py-2 hover:bg-white/60 dark:hover:bg-black/20 transition" :class="(p.match || (!isInventoryEnabled() && parseFloat(p.manualPrice) > 0)) ? '' : 'opacity-70'">
                                    {{-- Status icon --}}
                                    <template x-if="p.match">
                                        <div class="w-6 h-6 rounded-full bg-gradient-to-br from-emerald-400 to-green-500 flex items-center justify-center flex-shrink-0 shadow-sm">
                                            <svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                        </div>
                                    </template>
                                    {{-- Unmatched icon: amber "+" when inventory OFF (manual entry possible), red "×" when inventory ON. --}}
                                    <template x-if="!p.match && !isInventoryEnabled()">
                                        <div class="w-6 h-6 rounded-full bg-amber-100 dark:bg-amber-900/40 flex items-center justify-center flex-shrink-0" title="{{ __('pos.ti_manual_entry_price') }}">
                                            <svg class="w-3.5 h-3.5 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                                        </div>
                                    </template>
                                    <template x-if="!p.match && isInventoryEnabled()">
                                        <div class="w-6 h-6 rounded-full bg-red-100 dark:bg-red-900/40 flex items-center justify-center flex-shrink-0">
                                            <svg class="w-3.5 h-3.5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </div>
                                    </template>
                                    {{-- Qty pill: active style when matched OR (inventory-OFF AND manualPrice typed). --}}
                                    <span class="font-mono text-[11px] font-extrabold w-9 text-center px-1.5 py-0.5 rounded-md flex-shrink-0" :class="(p.match || (!isInventoryEnabled() && parseFloat(p.manualPrice) > 0)) ? 'bg-sky-200/70 dark:bg-sky-800/60 text-sky-800 dark:text-sky-200' : 'bg-gray-200 dark:bg-gray-700 text-gray-500 line-through'" x-text="p.qty + '×'"></span>
                                    {{-- Name --}}
                                    <span class="flex-1 text-xs font-semibold text-gray-800 dark:text-gray-200 truncate" x-text="p.match ? p.match.name : p.raw"></span>
                                    {{-- Right-side: matched price OR inline manual price input OR "not found" italic. --}}
                                    <template x-if="p.match">
                                        <span class="text-[11px] font-bold font-mono text-sky-700 dark:text-sky-300 flex-shrink-0" x-text="'Rs. ' + Number(parseFloat(p.match.price) * p.qty).toLocaleString()"></span>
                                    </template>
                                    <template x-if="!p.match && !isInventoryEnabled()">
                                        <div class="flex items-center gap-1 flex-shrink-0">
                                            <span class="text-[10px] text-gray-400 dark:text-gray-500 font-mono">Rs.</span>
                                            <input type="number" x-model="p.manualPrice" min="0" step="any" placeholder="{{ __('pos.ph_price') }}" class="w-20 text-[11px] font-mono font-bold text-right rounded-md border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200 px-2 py-0.5 focus:ring-2 focus:ring-amber-400 focus:border-amber-500 outline-none" @keydown.enter.prevent="$event.target.blur()" @click.stop />
                                        </div>
                                    </template>
                                    <template x-if="!p.match && isInventoryEnabled()">
                                        <span class="text-[10px] italic text-red-500 dark:text-red-400 flex-shrink-0">not found</span>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Action buttons --}}
                <div class="flex flex-wrap gap-2 pt-1">
                    <button @click="addRandomProduct()" :disabled="(!allProducts || allProducts.length === 0) && (!allServices || allServices.length === 0)" class="group flex-1 min-w-[160px] px-4 py-3 rounded-2xl bg-gradient-to-br from-amber-400 via-orange-500 to-orange-600 hover:from-amber-500 hover:via-orange-600 hover:to-orange-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-bold transition-all flex items-center justify-center gap-2 shadow-sm hover:-translate-y-0.5 active:translate-y-0">
                        <svg class="w-4 h-4 transition-transform group-hover:rotate-180 duration-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        {{ __('pos.random_product') }}
                    </button>
                    <button @click="applyQuickType()" :disabled="quickTypeParsed.filter(p => p.match).length === 0 && (isInventoryEnabled() || quickTypeParsed.filter(p => !p.match && parseFloat(p.manualPrice) > 0).length === 0)" class="flex-1 min-w-[160px] px-4 py-3 rounded-2xl bg-gradient-to-br from-sky-500 via-sky-600 to-blue-700 hover:from-sky-600 hover:via-sky-700 hover:to-blue-800 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-bold transition-all flex items-center justify-center gap-2 shadow-sm hover:-translate-y-0.5 active:translate-y-0">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        {{ __('pos.add_to_cart') }}
                        <kbd class="text-[9px] bg-white/25 backdrop-blur-sm px-1.5 py-0.5 rounded font-mono ring-1 ring-white/20">⌃↵</kbd>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         MANUAL ITEM MODAL — ad-hoc cart entry (inventory-OFF only).
         Cashier types Name + Price → optional "save to products" toggle.
         If save: persists via apiQuickCreate then adds to cart.
         Else: pushes a synthetic cart line (item_id=null) — billable.
         Open: toolbar "+ Manual" button. Close: Esc.
         ═══════════════════════════════════════════════════════════════ --}}
    <div x-show="showManualItem" x-cloak x-transition.opacity @click.self="showManualItem = false" @keydown.escape.window="if(showManualItem) showManualItem = false" class="fixed inset-0 bg-gradient-to-br from-emerald-950/70 via-black/70 to-teal-950/70 backdrop-blur-md z-50 flex items-center justify-center p-4" style="display:none;">
        <div x-show="showManualItem" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-90 translate-y-4" x-transition:enter-end="opacity-100 scale-100 translate-y-0" @click.stop class="bg-white dark:bg-gray-900 rounded-3xl shadow-2xl w-full max-w-md overflow-hidden ring-1 ring-emerald-200/50 dark:ring-emerald-800/50" style="box-shadow: 0 25px 80px -20px rgba(5, 150, 105, 0.55);">
            {{-- Header --}}
            <div class="relative px-6 py-5 flex items-center justify-between" style="background:linear-gradient(135deg,#059669 0%,#0d9488 50%,#0f766e 100%);">
                <div class="relative flex items-center gap-3">
                    <div class="w-11 h-11 bg-white/25 backdrop-blur-sm rounded-2xl flex items-center justify-center shadow-lg ring-1 ring-white/30">
                        <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    </div>
                    <div>
                        <h3 class="text-white text-lg font-extrabold m-0 tracking-tight">{{ __('pos.manual_item') }}</h3>
                        <p class="text-white/75 text-[11px] m-0 font-medium">{{ __('pos.adhoc_product_hint') }}</p>
                    </div>
                </div>
                <button @click="showManualItem = false" class="relative w-8 h-8 bg-white/15 hover:bg-white/30 rounded-xl text-white flex items-center justify-center transition-all hover:rotate-90 ring-1 ring-white/20" title="{{ __('pos.ti_close_esc') }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form @submit.prevent="addManualItem()" class="p-6 space-y-4 bg-gradient-to-b from-white to-emerald-50/30 dark:from-gray-900 dark:to-emerald-950/20">
                {{-- Name --}}
                <div>
                    <label for="manualItemNameInput" class="text-[10px] font-extrabold uppercase tracking-[0.15em] text-emerald-700 dark:text-emerald-400 flex items-center gap-1.5 mb-2">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        {{ __('pos.item_name') }}
                    </label>
                    <input id="manualItemNameInput" x-model="manualItemName" type="text" required maxlength="255" placeholder="{{ __('pos.ph_manual_item_eg') }}" autocomplete="off" name="pos_manualitem_name_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                        class="w-full text-sm rounded-2xl border-2 border-emerald-200 dark:border-emerald-800 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-4 py-2.5 focus:outline-none focus:ring-4 focus:ring-emerald-500/20 focus:border-emerald-500 transition-all shadow-sm hover:shadow-md">
                </div>

                {{-- Price --}}
                <div>
                    <label for="manualItemPriceInput" class="text-[10px] font-extrabold uppercase tracking-[0.15em] text-emerald-700 dark:text-emerald-400 flex items-center gap-1.5 mb-2">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/></svg>
                        {{ __('pos.unit_price_rs') }}
                    </label>
                    <input id="manualItemPriceInput" x-model="manualItemPrice" type="number" required min="0" step="0.01" placeholder="0.00"
                        class="w-full text-sm rounded-2xl border-2 border-emerald-200 dark:border-emerald-800 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-4 py-2.5 focus:outline-none focus:ring-4 focus:ring-emerald-500/20 focus:border-emerald-500 font-mono font-bold transition-all shadow-sm hover:shadow-md">
                    <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1.5 px-1 italic">
                        {{ __('pos.qty_tax_adjust_hint_fbr') }}
                    </p>
                </div>

                {{-- Save-permanent toggle --}}
                <label class="flex items-start gap-3 p-3 rounded-2xl border-2 cursor-pointer transition-all" :class="manualItemSavePermanent ? 'border-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 shadow-sm' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/40 hover:border-emerald-300'">
                    <input type="checkbox" x-model="manualItemSavePermanent" class="sr-only peer">
                    <div class="flex-shrink-0 mt-0.5 w-5 h-5 rounded-md border-2 flex items-center justify-center transition-all peer-focus-visible:ring-2 peer-focus-visible:ring-emerald-500 peer-focus-visible:ring-offset-1" :class="manualItemSavePermanent ? 'bg-emerald-600 border-emerald-600' : 'bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600'">
                        <svg x-show="manualItemSavePermanent" class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <div class="flex-1">
                        <div class="text-xs font-bold text-gray-900 dark:text-white flex items-center gap-1.5">
                            {{ __('pos.save_products_future') }}
                            <span class="text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300">{{ __('pos.optional') }}</span>
                        </div>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5 leading-snug">
                            {{ __('pos.quick_save_tick_pfx') }} <span class="font-semibold text-emerald-700 dark:text-emerald-400">"Quick"</span> {{ __('pos.quick_save_tick_sfx') }}
                        </p>
                    </div>
                </label>

                {{-- Actions --}}
                <div class="flex flex-wrap gap-2 pt-1">
                    <button type="button" @click="showManualItem = false" :disabled="manualItemSubmitting" class="px-4 py-3 rounded-2xl text-xs font-bold text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition disabled:opacity-50">
                        {{ __('pos.cancel') }}
                    </button>
                    <button type="submit" :disabled="manualItemSubmitting || !manualItemName.trim() || manualItemPrice === '' || parseFloat(manualItemPrice) < 0" class="flex-1 px-4 py-3 rounded-2xl bg-gradient-to-br from-emerald-500 via-emerald-600 to-teal-700 hover:from-emerald-600 hover:via-emerald-700 hover:to-teal-800 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-bold transition-all flex items-center justify-center gap-2 shadow-sm hover:-translate-y-0.5 active:translate-y-0">
                        <svg x-show="manualItemSubmitting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        <svg x-show="!manualItemSubmitting" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        <span x-text="manualItemSubmitting ? window.TXT.adding_ellipsis : window.TXT.add_to_cart"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Persistent Receipt Modal — Esc + backdrop-click are intentionally NOT bound here so       --}}
    {{-- the cashier doesn't dismiss the popup by accident while reading totals or printing.        --}}
    {{-- Esc on this popup belongs to the browser print dialog (closes that, not our popup).        --}}
    {{-- Popup closes ONLY via: X (top-right cross), Close button, or "New Sale" button.            --}}
    <div x-show="showReceipt" x-cloak x-transition.opacity x-effect="if (!showReceipt) { cancelPendingPrints(); stopFbrPoll(); }" class="fixed inset-0 bg-gradient-to-br from-green-900/80 via-black/70 to-emerald-900/80 backdrop-blur-md z-50 flex items-center justify-center p-4">
        <div class="receipt-modal-enter relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col" style="max-height:92vh;" x-transition.scale.90>
            {{-- Top-right cross (primary close action) --}}
            <button @click="showReceipt = false" class="absolute top-3 right-3 z-10 w-8 h-8 rounded-full bg-white/80 dark:bg-gray-800/80 hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white flex items-center justify-center transition shadow-sm" title="{{ __('pos.ti_close_popup') }}">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>

            <div class="relative px-6 pt-7 pb-6 text-center overflow-hidden bg-gradient-to-b from-emerald-50 via-green-50 to-white dark:from-emerald-900/30 dark:via-green-900/10 dark:to-gray-900 flex-shrink-0" id="confettiContainer">
                {{-- soft glow behind the success icon --}}
                {{-- Animated success ring with a pulsing halo --}}
                <div class="relative w-20 h-20 mx-auto mb-3">
                    <span class="absolute inset-0 rounded-full bg-emerald-400/30 animate-ping"></span>
                    <div class="relative w-20 h-20 rounded-full bg-gradient-to-br from-green-400 to-emerald-600 flex items-center justify-center shadow-sm success-icon-animate" style="animation: scaleIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1)">
                        <svg class="w-10 h-10 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-dasharray="24" stroke-dashoffset="0" style="animation: checkDraw 0.5s ease 0.3s both;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    </div>
                </div>
                <h3 class="relative text-2xl font-black text-gray-900 dark:text-white tracking-tight">{{ __('pos.payment_complete') }}</h3>
                {{-- FBR fiscal status — the "production" proof the cashier needs to see at a glance --}}
                <div class="relative mt-2.5 flex items-center justify-center">
                    <span class="inline-flex items-center gap-1.5 text-[11px] font-extrabold uppercase tracking-wider px-3 py-1 rounded-full shadow-sm"
                          :class="lastIsOffline ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : (lastFbrStatus === 'submitted' ? 'bg-emerald-600 text-white' : (lastFbrStatus === 'pending' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' : ((lastFbrStatus === 'offline' || lastFbrStatus === 'failed') ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300')))">
                        <svg x-show="!lastIsOffline && lastFbrStatus === 'submitted'" class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.42l-7.5 7.5a1 1 0 01-1.42 0l-3.5-3.5a1 1 0 111.42-1.42l2.79 2.8 6.79-6.8a1 1 0 011.42 0z" clip-rule="evenodd"/></svg>
                        <svg x-show="!lastIsOffline && lastFbrStatus === 'pending'" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        <span x-text="lastIsOffline ? window.TXT.saved_offline_autosync : (lastFbrStatus === 'submitted' ? window.TXT.fbr_verified : (lastFbrStatus === 'pending' ? window.TXT.reporting_to_fbr : ((lastFbrStatus === 'offline' || lastFbrStatus === 'failed') ? window.TXT.saved_will_sync_fbr : window.TXT.local_bill)))"></span>
                    </span>
                </div>
                {{-- Big total --}}
                <p class="relative mt-3 text-4xl font-black text-emerald-600 dark:text-emerald-400 tracking-tight" x-text="'Rs. ' + Number(lastTotal).toLocaleString()" style="font-variant-numeric: tabular-nums;"></p>
                {{-- Cash Received / Wapsi — big green change-due line for the cashier. --}}
                <div x-show="lastCashReceived > 0" x-cloak class="relative mt-2 mx-auto max-w-xs py-2 px-3 rounded-xl bg-green-600/10 border border-green-500/30">
                    <p class="text-[11px] font-bold text-gray-600 dark:text-gray-300" x-text="window.TXT.cash_received_rs + Number(lastCashReceived).toLocaleString()"></p>
                    <p x-show="lastCashReceived - lastTotal > 0.001" class="text-xl font-black text-green-600 dark:text-green-400" x-text="window.TXT.change_caps_prefix + Math.round(lastCashReceived - lastTotal).toLocaleString()"></p>
                </div>
                {{-- FBR fiscal invoice number — shown only once FBR returns it (real "production" number) --}}
                <div x-show="lastFbrNumber" class="relative mt-3 mx-auto max-w-xs py-2 px-3 rounded-xl bg-emerald-600/10 border border-emerald-500/30">
                    <p class="text-[9px] font-bold uppercase tracking-widest text-emerald-700/70 dark:text-emerald-400/70">{{ __('pos.fbr_invoice_number') }}</p>
                    <div class="flex items-center justify-center gap-2 mt-0.5">
                        <p class="text-sm font-extrabold font-mono text-emerald-800 dark:text-emerald-300 break-all" x-text="lastFbrNumber"></p>
                        <button type="button"
                            @click="if(navigator.clipboard){navigator.clipboard.writeText(lastFbrNumber).then(()=>{ fbrCopied=true; showToast(window.TXT.fbr_number_copied,'success'); setTimeout(()=>fbrCopied=false,1500); }).catch(()=>showToast(window.TXT.copy_failed,'error'));}else{showToast(window.TXT.copy_not_supported,'error');}"
                            class="shrink-0 w-7 h-7 rounded-lg bg-emerald-600/15 hover:bg-emerald-600/30 text-emerald-700 dark:text-emerald-300 flex items-center justify-center transition" :title="fbrCopied ? window.TXT.ti_copied : window.TXT.ti_copy_fbr">
                            <svg x-show="!fbrCopied" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            <svg x-show="fbrCopied" x-cloak class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        </button>
                    </div>
                </div>
                {{-- Internal invoice # + payment method (secondary) --}}
                <div class="relative flex items-center justify-center gap-3 mt-3">
                    <span class="text-[11px] font-mono text-gray-400 dark:text-gray-500" x-text="lastInvoiceNumber"></span>
                    <span class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full" :class="lastPaymentMethod === 'cash' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'">
                        <span class="w-1.5 h-1.5 rounded-full" :class="lastPaymentMethod === 'cash' ? 'bg-green-500' : 'bg-blue-500'"></span>
                        <span x-text="lastPaymentMethod"></span>
                    </span>
                </div>
                {{-- Sale meta: time + item count (item count auto-hides when unknown) --}}
                <div class="relative flex items-center justify-center gap-2.5 mt-2 text-[10px] font-semibold text-gray-400 dark:text-gray-500">
                    <span class="inline-flex items-center gap-1" x-show="lastSaleAt">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span x-text="lastSaleAt ? new Date(lastSaleAt).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) : ''"></span>
                    </span>
                    <span x-show="lastSaleAt && lastItemsCount > 0" class="w-1 h-1 rounded-full bg-gray-300 dark:bg-gray-600"></span>
                    <span class="inline-flex items-center gap-1" x-show="lastItemsCount > 0">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                        <span x-text="lastItemsCount + (lastItemsCount === 1 ? window.TXT.sfx_item : window.TXT.sfx_items)"></span>
                    </span>
                </div>
            </div>
            <div class="flex-1 overflow-hidden bg-gray-50 dark:bg-gray-800/50 min-h-0" style="max-height: 45vh;">
                <iframe x-show="!lastIsOffline" x-ref="receiptIframe" class="w-full h-full border-0" :src="lastTransactionId ? ('/fbr-pos/transaction/' + lastTransactionId + '/receipt') : ''" style="min-height:300px;"></iframe>
                {{-- OFFLINE bill (Aug 2026): no server receipt exists yet — render a client-side summary. --}}
                <div x-show="lastIsOffline" x-cloak class="h-full overflow-y-auto p-4" style="min-height:300px;">
                    <div class="max-w-xs mx-auto text-xs font-mono text-gray-800 dark:text-gray-200">
                        <template x-for="(i, idx) in (lastOfflineRec ? lastOfflineRec.items : [])" :key="idx">
                            <div class="flex justify-between gap-2 py-1 border-b border-dashed border-gray-200 dark:border-gray-700">
                                <span x-text="i.name + '  × ' + i.qty"></span>
                                <span class="whitespace-nowrap" x-text="Number(r2(i.qty * i.price)).toLocaleString()"></span>
                            </div>
                        </template>
                        <div class="flex justify-between mt-2 pt-2 border-t-2 border-gray-800 dark:border-gray-200 font-black text-sm">
                            <span>TOTAL</span>
                            <span x-text="'Rs. ' + Number(lastTotal).toLocaleString()"></span>
                        </div>
                        <p class="mt-3 text-center text-[10px] font-bold text-amber-700 dark:text-amber-400 border border-dashed border-amber-400 rounded-lg p-2 leading-relaxed">
                            {{ __('pos.offline_bill_saved_device') }}<br>
                            It will auto-sync and get its invoice number when internet returns.
                        </p>
                    </div>
                </div>
            </div>
            {{-- Persistent action bar: Print | KOT | New Sale | Close. Print/KOT fire prints      --}}
            {{-- but popup STAYS OPEN so cashier can verify, reprint, or take other actions.       --}}
            <div class="p-3 bg-white dark:bg-gray-900 border-t border-gray-100 dark:border-gray-800 flex-shrink-0">
                {{-- Task 1271: WhatsApp Bill (PRA Task 1036 port) — sirf jab bill par
                     routable customer number + share link ho (warna chhupa; khali wa.me kabhi nahi).
                     Auto-open popup-block ho to yehi button pulse-highlight fallback ban jata hai. --}}
                <button x-show="waBillEnabled && lastWaPhone && lastShareUrl && !lastIsOffline" x-cloak
                    @click="openWaBill()"
                    class="w-full mb-2 py-3 text-center rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold transition shadow-sm flex items-center justify-center gap-2"
                    :class="waHighlight ? 'ring-4 ring-green-300 animate-pulse' : ''"
                    title="{{ __('pos.ti_wa_bill') }}">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                    {{ __('pos.wa_bill_btn') }}
                </button>
                <div class="grid grid-cols-4 gap-2">
                    {{-- 1. Print Receipt (P) --}}
                    <button @click="lastIsOffline ? printOfflineReceipt() : printReceipt()" :disabled="!lastTransactionId && !lastIsOffline" class="py-3 text-center rounded-xl bg-blue-600 hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-bold transition shadow-sm flex items-center justify-center gap-1.5" title="{{ __('pos.ti_print_receipt') }}">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                        {{ __('pos.print') }} <kbd class="text-[8px] bg-blue-500/40 px-1 rounded font-mono">P</kbd>
                    </button>
                    {{-- 2. KOT (K) - shown only when an orderId exists (restaurant flow) + admin allows reprint --}}
                    @if(($company->kot_reprint_enabled ?? true))
                    <button x-show="lastOrderId" @click="printKitchenTicket()" :disabled="!lastOrderId" class="py-3 text-center rounded-xl bg-gradient-to-br from-orange-500 to-amber-600 hover:from-orange-600 hover:to-amber-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-bold transition shadow-sm flex items-center justify-center gap-1.5" title="{{ __('pos.fbr_ti_print_store_slip') }}">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                        {{ __('pos.fbr_store_slip_word') }} <kbd class="text-[8px] bg-orange-500/40 px-1 rounded font-mono">K</kbd>
                    </button>
                    {{-- Spacer when KOT hidden so grid stays balanced --}}
                    <div x-show="!lastOrderId"></div>
                    @else
                    {{-- Reprint disabled by admin — keep grid cell balanced --}}
                    <div></div>
                    @endif
                    {{-- 3. New Sale (Enter) --}}
                    <button @click="startNewAfterPayment()" class="py-3 text-center rounded-xl bg-gradient-to-br from-green-600 to-emerald-700 hover:from-green-700 hover:to-emerald-800 text-white text-sm font-bold transition shadow-sm flex items-center justify-center gap-1.5" title="{{ __('pos.ti_clear_cart_new_sale') }}">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                        {{ __('pos.new_word') }} <kbd class="text-[8px] bg-green-500/40 px-1 rounded font-mono">↵</kbd>
                    </button>
                    {{-- 4. Close popup (mouse only - Esc no longer bound to keep print dialog Esc clean) --}}
                    <button @click="showReceipt = false" class="py-3 text-center rounded-xl bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-600 dark:text-gray-300 text-sm font-semibold transition flex items-center justify-center gap-1.5" title="{{ __('pos.ti_close_popup_no_new_sale') }}">
                        {{ __('pos.close') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Manager PIN Modal --}}
    <div x-show="showManagerPinModal" x-cloak x-transition.opacity class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xs overflow-hidden" @click.outside="showManagerPinModal = false">
            <div class="p-5 text-center">
                <div class="w-12 h-12 mx-auto rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center mb-3">
                    <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </div>
                <h3 class="text-lg font-extrabold text-gray-900 dark:text-white">{{ __('pos.manager_override') }}</h3>
                <p class="text-xs text-gray-500 mt-1">{{ __('pos.enter_manager_pin_hint') }}</p>
            </div>
            <div class="px-5 pb-5 space-y-3">
                <input type="password" x-model="managerPin" autocomplete="one-time-code" name="pos_managerpin_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore @keydown.enter="submitManagerPin()" maxlength="6" placeholder="{{ __('pos.ph_enter_pin') }}" class="w-full text-center text-2xl tracking-[0.5em] bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 text-gray-900 dark:text-white focus:ring-blue-500 focus:border-blue-500" autofocus>
                <p x-show="managerPinError" class="text-xs text-red-500 text-center" x-text="managerPinError"></p>
                <div class="flex gap-2">
                    <button @click="showManagerPinModal = false" class="flex-1 py-2.5 text-sm font-semibold text-gray-600 bg-gray-100 dark:bg-gray-800 dark:text-gray-400 rounded-xl hover:bg-gray-200 transition">{{ __('pos.cancel') }}</button>
                    <button @click="submitManagerPin()" class="flex-1 py-2.5 text-sm font-bold text-white bg-blue-600 rounded-xl hover:bg-blue-700 transition">{{ __('pos.verify') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Customer History Modal --}}
    <div x-show="showCustomerHistory" x-cloak x-transition.opacity class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md max-h-[80vh] overflow-hidden flex flex-col" @click.outside="showCustomerHistory = false">
            <div class="p-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-extrabold text-gray-900 dark:text-white">{{ __('pos.customer_history') }}</h3>
                    <p class="text-xs text-gray-500" x-text="customerHistory?.customer_name || ''"></p>
                </div>
                <button @click="showCustomerHistory = false" class="p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto p-4 space-y-4">
                <template x-if="loadingCustomerHistory">
                    <div class="text-center py-8"><div class="w-6 h-6 border-2 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto"></div><p class="text-xs text-gray-400 mt-2">{{ __('pos.loading_dots') }}</p></div>
                </template>
                <template x-if="customerHistory && !loadingCustomerHistory">
                    <div>
                        <div class="flex items-center gap-3 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-xl mb-4">
                            <div class="w-10 h-10 rounded-full bg-blue-200 dark:bg-blue-800 flex items-center justify-center"><span class="text-sm font-bold text-blue-700 dark:text-blue-300" x-text="(customerHistory.customer_name || 'C').charAt(0)"></span></div>
                            <div>
                                <p class="text-sm font-bold text-gray-900 dark:text-white" x-text="customerHistory.customer_name"></p>
                                <p class="text-[10px] text-gray-500"><span x-text="customerHistory.total_orders"></span> orders &bull; Rs. <span x-text="Number(customerHistory.total_spent || 0).toLocaleString()"></span> spent</p>
                            </div>
                            <span x-show="customerHistory.total_orders >= 5" class="ml-auto text-[9px] font-bold text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full">VIP</span>
                        </div>

                        <template x-if="customerHistory.favorites && customerHistory.favorites.length > 0">
                            <div class="mb-4">
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">{{ __('pos.favorites') }}</p>
                                <div class="flex flex-wrap gap-1.5">
                                    <template x-for="fav in customerHistory.favorites" :key="fav.name">
                                        <span class="text-[10px] px-2 py-1 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 rounded-lg font-medium" x-text="fav.name + ' (' + fav.count + 'x)'"></span>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <template x-if="customerHistory.recent_orders && customerHistory.recent_orders.length > 0">
                            <div>
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">{{ __('pos.recent_orders') }}</p>
                                <div class="space-y-2">
                                    <template x-for="ord in customerHistory.recent_orders" :key="ord.id">
                                        <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-xl">
                                            <div class="flex items-center justify-between mb-1.5">
                                                <span class="text-xs font-bold text-gray-900 dark:text-white" x-text="ord.order_number"></span>
                                                <span class="text-[10px] text-gray-400" x-text="ord.date"></span>
                                            </div>
                                            <div class="text-[10px] text-gray-500 mb-2" x-text="ord.items.map(i => i.qty + 'x ' + i.name).join(', ')"></div>
                                            <div class="flex items-center justify-between">
                                                <span class="text-xs font-bold text-blue-600" x-text="'Rs. ' + Number(ord.total).toLocaleString()"></span>
                                                <button @click="reorderItems(ord)" class="text-[10px] font-bold text-white bg-blue-600 hover:bg-blue-700 px-2.5 py-1 rounded-lg transition">{{ __('pos.reorder') }}</button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- Smart Upsell — DISABLED for retail FBR POS (Aug 2026: plain retail, distraction removed).
         Logic functions kept intact (triggerUpsell/acceptUpsell/dismissUpsell) for future opt-in. --}}

    {{-- Low Stock Alert Popup — strictly gated by isInventoryEnabled().
         Even if some downstream code flips showLowStockPopup, this guard keeps it hidden. --}}
    <div x-show="isInventoryEnabled() && showLowStockPopup && lowStockAlerts.length > 0" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden" @click.outside="showLowStockPopup = false">
            <div class="p-4 bg-amber-50 dark:bg-amber-900/20 border-b border-amber-200 dark:border-amber-800 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-amber-900 dark:text-amber-200">{{ __('pos.low_stock_warning') }}</h3>
                    <p class="text-[10px] text-amber-700 dark:text-amber-400" x-text="lowStockAlerts.length + window.TXT.ingredients_low_sfx"></p>
                </div>
            </div>
            <div class="max-h-[40vh] overflow-y-auto p-3 space-y-1.5">
                <template x-for="alert in lowStockAlerts" :key="alert.name">
                    <div class="flex items-center justify-between px-3 py-2 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <div>
                            <p class="text-xs font-semibold text-gray-900 dark:text-white" x-text="alert.name"></p>
                            <p class="text-[10px] text-gray-400" x-text="window.TXT.min_colon + alert.min_stock_level + ' ' + alert.unit"></p>
                        </div>
                        <span class="text-xs font-bold" :class="parseFloat(alert.current_stock) <= 0 ? 'text-red-600' : 'text-amber-600'" x-text="alert.current_stock + ' ' + alert.unit"></span>
                    </div>
                </template>
            </div>
            <div class="p-3 border-t border-gray-100 dark:border-gray-800">
                <button @click="showLowStockPopup = false" class="w-full py-2.5 text-sm font-bold text-amber-700 bg-amber-50 dark:bg-amber-900/20 dark:text-amber-400 rounded-xl hover:bg-amber-100 transition">{{ __('pos.dismiss') }}</button>
            </div>
        </div>
    </div>

    <div x-show="toast.show" class="fixed top-4 right-4 z-[60] max-w-sm" :class="toast.show ? 'toast-enter' : 'toast-exit'">
        <div class="flex items-center gap-3 px-4 py-3 rounded-2xl shadow-2xl backdrop-blur-xl border" :class="toast.type === 'success' ? 'bg-green-600/95 text-white border-green-500/30' : 'bg-red-600/95 text-white border-red-500/30'" style="box-shadow: 0 20px 60px -15px rgba(0,0,0,0.3);">
            <div class="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center" :class="toast.type === 'success' ? 'bg-white/20' : 'bg-white/20'">
                <svg x-show="toast.type === 'success'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                <svg x-show="toast.type !== 'success'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
            </div>
            <span class="text-sm font-semibold" x-text="toast.message"></span>
        </div>
    </div>
</div>

@php
// FBR Product model: default_price (NOT price), no category/image columns.
// Per-item FBR fields (hs_code, uom, tax_rate, exemption, price lock) ride
// along so the cart can build a compliant store() payload without lookups.
$productsJson = $products->map(function($p) {
    return [
        'id' => $p->id, 'type' => 'product', 'name' => $p->name,
        'price' => (float) ($p->default_price ?? 0), 'category' => null,
        'show_on_sale' => (bool) ($p->show_on_sale ?? true),
        'cost_price' => 0.0,
        'is_tax_exempt' => ($p->tax_type ?? 'standard') === 'exempt',
            'is_third_schedule' => $p->is_third_schedule ?? false,
        'tax_rate' => (float) ($p->default_tax_rate ?? 18),
        'hs_code' => $p->hs_code,
        'uom' => $p->uom ?: 'U',
        'barcode' => $p->barcode,
        'is_price_editable' => (bool) ($p->is_price_editable ?? true),
        'hasRecipe' => false,
        'image' => null,
        'stockStatus' => null,
    ];
})->values();
// Services (Task 1272 — PRA port + FBR per-item tax fields): no hs_code/barcode,
// UoM 'U'; tax comes from the service's own tax_rate / is_tax_exempt so the
// store() payload stays item-level compliant (product_id NULL lines).
$servicesJson = $services->map(function($s) {
    return [
        'id' => $s->id, 'type' => 'service', 'name' => $s->name,
        'price' => (float) ($s->price ?? 0), 'category' => 'Services',
        'show_on_sale' => true,
        'cost_price' => 0.0,
        'is_tax_exempt' => (bool) ($s->is_tax_exempt ?? false),
        'is_third_schedule' => false,
        'tax_rate' => (float) ($s->tax_rate ?? 0),
        'hs_code' => null,
        'uom' => 'U',
        'barcode' => null,
        'is_price_editable' => true,
        'hasRecipe' => false,
        'image' => null,
        'stockStatus' => null,
    ];
})->values();
$selectedTableJson = $selectedTable ? ['id' => $selectedTable->id, 'table_number' => $selectedTable->table_number, 'seats' => $selectedTable->seats] : null;
$customersJson = $customers->map(fn($c) => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone])->values();
$kitchenSettings = [
    'kds_enabled' => (bool)($company->kds_enabled ?? true),
    'printer_enabled' => (bool)($company->kitchen_printer_enabled ?? false),
    'print_on_hold' => (bool)($company->print_on_hold ?? false),
    'print_on_pay' => (bool)($company->print_on_pay ?? true),
];
// UTF-8-SAFE JSON for x-data: a single product/customer/order with a broken byte
// sequence makes json_encode() return false → @json emits NOTHING → "allProducts: ,"
// is invalid JS → the WHOLE Alpine component fails to init (dead order-type buttons,
// dead cart, dead keyboard flow). JSON_INVALID_UTF8_SUBSTITUTE swaps bad bytes for
// U+FFFD; the ?: fallback guarantees valid JS even if encoding still fails for any
// other reason. NEVER use bare @json for DB free-text fields in this x-data block.
$jsEnc = function ($value, $fallback = '[]') {
    $json = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    return $json === false ? $fallback : $json;
};
@endphp
<script>
// OFFLINE-FIRST BOOT (Aug 2026 — PRA port): the service worker may serve this
// page from SALE_CACHE — bootFpCheck() compares this against
// /fbr-pos/api/boot-check shortly after boot and self-refreshes if stale.
window.tnBootFp = {!! $jsEnc($bootFp ?? null, 'null') !!};
function restaurantPos() {
    return {
        allProducts: {!! $jsEnc($productsJson) !!},
        allServices: {!! $jsEnc($servicesJson) !!},
        allCustomers: {!! $jsEnc($customersJson) !!},
        // Task 100: TRUE when the shop has more customers than the bake cap —
        // allCustomers is only the most-recently-active subset. Server search is
        // the source of truth; the baked subset is the OFFLINE fallback.
        customersBakedPartial: {{ !empty($customersTruncated) ? 'true' : 'false' }},
        pickerServerResults: null,
        pickerSearchTimer: null,
        kitchenSettings: @json($kitchenSettings),
        // Inventory master switch — single source of truth.
        // When false, ALL stock UI/logic is suppressed (badges, popup, blocking).
        // Use isInventoryEnabled() helper everywhere — never reference this directly.
        inventoryEnabled: {{ ($inventoryEnabled ?? false) ? 'true' : 'false' }},
        isInventoryEnabled() { return this.inventoryEnabled === true; },
        // True when the cart contains at least one synthetic Manual Item line
        // (item_type='manual', item_id=null). Such lines bill cleanly through
        // the standard Pay flow (storeInvoice has lax per-item validation),
        // but the restaurant Hold/Send-to-Kitchen endpoints require a real
        // item_id, so we gate those actions while a manual line is in cart.
        hasManualItems() { return (this.cart || []).some(i => i && i.item_type === 'manual'); },
        blockOutOfStock: {{ $blockOutOfStock ? 'true' : 'false' }},
        taxRate: {{ (float) ($taxRate ?? 0) }},
        taxRules: {!! $jsEnc($taxRules->mapWithKeys(fn($r) => [$r->payment_method => (float) $r->tax_rate]), '{}') !!},
        posRole: '{{ $posRole }}',
        discountLimit: {{ (float) ($discountLimit ?? 0) }},
        hasManagerPin: {{ $hasManagerPin ? 'true' : 'false' }},
        managerOverrideActive: false,
        showManagerPinModal: false,
        // ── QUICK RETURN (Task 685) — bill number → return form ─────────────
        quickReturnOpen: false,
        quickReturnQ: '',
        quickReturnBusy: false,
        quickReturnErr: '',
        managerPin: '',
        managerPinError: '',
        ingredientCosts: {!! $jsEnc($ingredientCosts ?? [], '{}') !!},
        lowStockAlerts: {!! $jsEnc($lowStockAlerts ?? []) !!},
        // Popup auto-open only when inventory is enabled AND there are real alerts.
        showLowStockPopup: {{ (($inventoryEnabled ?? false) && ($lowStockAlerts ?? collect())->count() > 0) ? 'true' : 'false' }},
        customerHistory: null,
        showCustomerHistory: false,
        loadingCustomerHistory: false,
        filteredItems: [],
        displayItems: [],
        displayCount: 60,
        showProducts: true,
        loading: true,
        activeCategory: 'all',
        searchQuery: '',
        searchSuggestions: [],
        showSearchDropdown: false,
        showCustomerPicker: false,
        customerSearch: '',
        customerLookupResult: null,
        customerLookupTimer: null,
        customerStats: null,
        showQuickAdd: false,
        quickCustomerName: '',
        quickCustomerPhone: '',
        quickCustomerAddress: '',
        selectedCustomer: null,
        // Task 163 (PRA parity): cashier-editable delivery address. The chosen text
        // is a SNAPSHOT on the bill (fbr_pos_transactions.delivery_address).
        customerAddresses: [],
        selectedDeliveryAddress: '',
        showAddrNew: false,
        newAddrText: '',
        newAddrLabel: '',
        customerPhoneQuery: '',
        customerPhoneResults: [],
        custHiIndex: 0,
        customerPhoneDropdown: false,
        customerPhoneTimer: null,
        showNewCustomerModal: false, // legacy, kept for backward compat — not used
        showNewCustomerInline: false,
        savingCustomer: false,
        customerSearching: false,
        newCustomerPhone: '',
        newCustomerName: '',
        newCustomerAddress: '',
        highlightIndex: 0,
        activeCartIndex: -1,
        cartMode: false,
        get mode() { return this.cartMode ? 'cart' : 'search'; },
        activeHeldIndex: 0,
        gridFocusMode: false,
        gridFocusIndex: 0,
        gridCols: 4,
        orderType: '{{ $selectedTable ? "dine_in" : "takeaway" }}',
        cart: [],
        kitchenNotes: '',
        selectedTable: {!! $jsEnc($selectedTableJson, 'null') !!},
        heldOrders: {!! $jsEnc($heldOrders) !!},
        showTablePicker: false,
        // ZFC guard mirror (Aug 2026, ported from PRA universal): unsent-cart
        // table-switch prompt — { kind:'table', table }. FBR has no setOrderType
        // (type buttons assign directly) so only the table-switch case exists here.
        tableSwitchPrompt: null,
        tableSwitchIndex: 0, // 0 = take items along, 1 = remove items
        showPayModal: false,
        // payMethodIndex — which method is highlighted in the Pay modal (0 = Cash,
        // 1 = Card). Arrow keys move it, Enter confirms the highlighted one, and
        // number keys 1/2 jump + fire directly. Reset to 0 each time the modal opens.
        payMethodIndex: 0,
        // Cash Received / Wapsi (owner request, Jul 2026): optional cashier input in the
        // Pay modal (CASH only). Server cash-guard blocks under-payment, so the payload
        // only carries the entered amount when it covers the total.
        cashReceived: '',
        lastCashReceived: 0,
        // PROVISIONAL BILL FLOW — when true, the Pay modal saves the bill with
        // fbr_status='local' (no FBR submission). Bill stays editable/deletable
        // and can be promoted to final later via the "Submit to FBR — Make Final"
        // button on transaction-show. Toggle key: P (while pay modal is open).
        saveAsProvisional: false,
        showHeldOrders: false,
        // ── Task 1271: WhatsApp Bill share (PRA port — FBR PDF + Tax Asaan QR) ──
        // waBillEnabled bakes the admin toggle AND the plan gate; server re-checks both.
        waBillEnabled: {{ (!empty($company->pos_whatsapp_bill_enabled) && \App\Services\PosFeatureService::planAllows($company, 'whatsapp_enabled')) ? 'true' : 'false' }},
        waBillAutoOpen: {{ !empty($company->pos_whatsapp_bill_auto_open) ? 'true' : 'false' }},
        waShopName: {!! $jsEnc($company->name ?? '', "''") !!},
        lastWaPhone: null,
        lastShareUrl: null,
        waHighlight: false,
        // ── Task 1271: per-user grid prefs (PRA port — FBR: PRODUCTS only; services
        // have no pref row and are always visible). Search is NEVER filtered by prefs.
        userGridPrefs: {!! $jsEnc((object) ($userGridPrefs ?? []), '{}') !!},
        gridEditMode: false,
        gridPrefBusy: false,
        // ── Task 1271: product search mode (admin pref on FBR products page) ──
        searchAnyWord: {{ (($company->pos_product_search_mode ?? 'prefix') === 'any_word') ? 'true' : 'false' }},
        // ── Task 1271: cart drafts (fbr_pos_drafts JSON rows — never FBR serials) ──
        drafts: [],
        showDrafts: false,
        draftBusy: false,
        activeDraftId: null,
        draftLockTimer: null,
        // ── FBR REPORTING TOGGLE (root scope so modals/buttons can read it) ───
        // Mirrors $company->fbr_reporting_enabled. Used by Provisional/Failed bill
        // action buttons (:disabled="!fbrEnabled"). Was previously defined only in
        // a nested x-data on the toggle strip → caused "fbrEnabled is not defined"
        // Alpine crashes inside the modals which broke the whole page (incl. Pay).
        fbrEnabled: {{ ($company->fbr_reporting_enabled ?? false) ? 'true' : 'false' }},
        // Task 1277: TRUE only when the FBR integration is actually set up (POSID +
        // usable token, or fiscal-device agent). Gates the Rs 1 fee client-side so
        // the payable total the cashier sees matches what the server stores. Joined
        // to posConfigRev — a settings save refreshes cached offline-first screens.
        fbrConfigured: {{ $company->fbrPosIntegrationConfigured() ? 'true' : 'false' }},
        fbrLoading: false,
        // ── GUIDED KEYBOARD BILLING FLOW (opt-in, default OFF) ───────────────
        // Mirrors $company->pos_guided_flow_enabled. When false EVERY keyboard
        // behaviour below stays byte-identical to the original (no interception).
        // flowStep is a DISPLAY-ONLY indicator; the actual transitions piggyback
        // existing functions (addHighlightedItem, enterCartMode, showPayModal,
        // clearCart). It NEVER rewrites handleKey or changes F-key bindings.
        guidedFlow: {{ ($company->pos_guided_flow_enabled ?? true) ? 'true' : 'false' }},
        flowStep: 'customer',
        // flowTypeIndex — highlighted choice in the guided Order-Type step (0-based into
        // guidedOrderTypes()). Seeded from the current orderType when the step opens.
        flowTypeIndex: 0,
        // ── RESTAURANT MODE FLAG (gates hold/pay route selection) ────────────
        // Restaurant endpoints (pos.restaurant.orders.hold + /pay) are blocked
        // by RestaurantOnly middleware for retail POS companies (HTTP 403
        // "Restaurant module not enabled"). For retail companies we route
        // EVERY processPayment call through processPaymentManual which uses
        // pos.invoice.store — a universal endpoint with no restaurant guard.
        isRestaurantMode: {{ (($features->tables ?? false) || ($features->kot ?? false) || ($features->kitchen ?? false)) ? 'true' : 'false' }},
        // ── PROVISIONAL BILLS (header shortcut, F10) ──────────────────────────
        // Lazy-loaded list of all bills with fbr_status='local' for current company.
        // Refreshed on page mount, after every bill save, and after each modal action.
        localBills: [],
        showLocalBills: false,
        activeLocalIndex: 0,
        localBillsLoading: false,
        // Search box inside the Provisional Bills modal — owner request (1 Aug 2026),
        // ported from PRA universal: find one bill by customer name/phone/bill no.
        localSearch: '',
        // 🔒 PIN gate (confidential PIN) — F10 provisional list is PIN-protected
        // when company has a confidential_pin set; server returns 403 pin_required.
        localPinRequired: false,
        localPinInput: '',
        localPinError: '',
        localPinVerifying: false,
        // ── PENDING DELIVERIES panel (Task 122 — FBR port of PRA Task 114) ──
        // Quick-final for TODAY's delivery provisionals: payment aate hi cashier
        // ek click mein Final (Cash/Card) — same promote path as F10 Make Final.
        // bizToday = current business day from the provisional-bills API
        // (00:00–05:59 counts in yesterday — PosBusinessDay, never client date).
        showPendingDeliveries: false,
        bizToday: '',
        deliveryFinalBusyId: null,
        // Task 517 (FBR port of PRA Task 513): UNASSIGNED final delivery bills
        // + rider dropdown right in the popup (POST fbrpos.deliveries.assign).
        finalDeliveryBills: [],
        showOldDeliveries: false, // Task 524: collapsed "Purani deliveries" group
        deliveryRiders: [],
        canAssignRider: false,
        riderAssignBusyId: null,
        riderSettleBusyId: null,
        // Task 543: styled settle-amount modal (replaces window.prompt)
        riderSettleBill: null,
        riderSettleOutstanding: 0,
        riderSettleAmount: '',
        // Receipt print default = NO (delivery customer isn't at the counter).
        // Opt-in checkbox persisted per device.
        deliveryPrintReceipt: (function(){ try { return localStorage.getItem('fbrpos_delivery_final_print') === '1'; } catch(e) { return false; } })(),
        // 🧾 FBR compliance — buyer NTN (optional, B2B) + UoM list (mirrors store() validation)
        customerNtn: '',
        uomOptions: ['U','KG','GM','LTR','ML','MTR','SQM','PCS','PKT','DOZ','BOX','SET','BAG','BTL','CTN','ROL','FT','IN','YDS','TIN','CAN','BUN'],
        // ── FAILED BILLS (header shortcut, F11) ───────────────────────────────
        // Lazy-loaded list of all bills with fbr_status IN (failed,offline,pending)
        // that have NOT received a fbr_invoice_number yet. Auto-refresh on mount.
        failedBills: [],
        // config_error bills: POSID/token missing — shown separately in F11 panel,
        // never touched by the auto-sync loop. Manually retryable after fixing settings.
        configErrorBills: [],
        showFailedBills: false,
        activeFailedIndex: 0,
        failedBillsLoading: false,
        // ── AUTO-SYNC ENGINE ──────────────────────────────────────────────
        // syncStatus: 'online' | 'syncing' | 'offline'
        // _syncTimer fires every 30 sec; pings count endpoint then silently
        // retries one bill per tick (no FBR hammering on long outages).
        // _autoSyncBusy = re-entrancy guard.
        // _autoSyncStrikes: Map<billId, count> — 3-strike session cap so a
        // bill that keeps failing (e.g. FBR API down) doesn't loop forever.
        syncStatus: navigator.onLine ? 'online' : 'offline',
        _syncTimer: null,
        _autoSyncBusy: false,
        _autoSyncStrikes: new Map(),
        // ── OFFLINE-FIRST BILLING (Aug 2026 — PRA port) ──
        // Bills created while the device has NO internet are queued in
        // IndexedDB ('tn_fbrpos_offline' / 'bills', keyed by client UUID) and
        // replayed to fbrpos.store with offline_uuid (server dedupes).
        // Queue is COMPANY-SCOPED — a shared browser must never post another
        // company's bills into the current session.
        offlineQueueCount: 0,
        offlineSyncing: false,
        offlineNeedsLogin: false,
        // Plan gate (pricing_plans.offline_enabled) — gates NEW queueing only;
        // syncOfflineBills (replay of already-queued bills) never checks this:
        // queued bills kabhi reject nahi hote.
        offlineAllowed: {{ !empty($offlineAllowed) ? 'true' : 'false' }},
        // Offline-locked notice dismissal — resets whenever the shop comes
        // back online so the next outage shows the banner again.
        offlineLockDismissed: false,
        _idb: null,
        // Receipt-popup offline variant state: no server transaction yet, so the
        // popup renders a client-side summary + client-printed interim receipt.
        lastIsOffline: false,
        lastOfflineRec: null,
        showReceipt: false,
        // ── AKHRI BILLS strip (Aug 2026 — Retail Fast Billing) ───────────────
        // Last 5 finalized bills pushed here on every successful payment.
        // Shown as one-click reprint chips below the product grid.
        recentBills: [],
        showShortcuts: false,
        // Quick Type Mode — type free-form lines like "chai 2, samosa 1" → cart
        showQuickType: false,
        quickTypeText: '',
        quickTypeParsed: [],
        // Manual Item Modal — ad-hoc cart entry for inventory-OFF companies.
        // Optional "save to products" checkbox persists via apiQuickCreate.
        showManualItem: false,
        manualItemName: '',
        manualItemPrice: '',
        manualItemSavePermanent: false,
        manualItemSubmitting: false,
        // Auto-Print receipt on successful sale — FBR POS persists this per-browser
        // (localStorage 'fbrpos_auto_print'), NOT via a server column. Default ON.
        autoPrintEnabled: (function(){ try { return localStorage.getItem('fbrpos_auto_print') !== '0'; } catch(e) { return true; } })(),
        // Task 565 (port of PRA universal): opt-in "Print se pehle poocho" —
        // payment success par auto-print chain se PEHLE fauri Yes/No dialog.
        // Per-company flag pos_printer_settings mein (PRA ke saath shared);
        // posConfigRev → boot fingerprint mein shamil. Default OFF.
        @php $__fps = $company->printerSettings(); @endphp
        printConfirmAsk: {{ !empty($__fps['print_confirm_ask']) ? 'true' : 'false' }},
        // Task 1263: silent printing via the shared Desktop Agent (fiscal_device
        // shops already run it). Baked from the shared pos_printer_settings JSON —
        // posConfigRev fingerprint covers it, so cached sale screens self-refresh.
        silentBillPrint: {{ (!empty($__fps['silent_print_enabled']) && !empty($__fps['receipt_printer'])) ? 'true' : 'false' }},
        silentKotPrint: {{ (!empty($__fps['silent_print_enabled']) && !empty($__fps['kot_printer'])) ? 'true' : 'false' }},
        // Task 1263: receipt popup auto-close seconds (0 = never). Server column
        // shared with PRA; hasColumn guard keeps prod alive pre-migration.
        receiptAutoCloseSecs: {{ \Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_receipt_autoclose_seconds') ? (int) ($company->pos_receipt_autoclose_seconds ?? 10) : 10 }},
        showPrintConfirm: false,
        printConfirmChoice: 'yes',
        printConfirmAction: null,
        // Task 1025 (port): "No" ka apna pending action — receipt skip par bhi
        // KOT apne mojooda gates se fire ho (No sirf CUSTOMER BILL rokta hai).
        printConfirmNoAction: null,
        // Task 520 (port of Task 514): Pay modal ka per-bill "Receipt print karein"
        // checkbox — default = billPrintDefault() (auto-print master ka mirror).
        payPrintReceipt: true,
        // Kitchen ticket auto-print — persisted server-side in companies.auto_print_kot.
        // Gated on kitchen_printer_enabled; read at page boot so the toggle survives
        // a refresh. hasColumn guard keeps the site alive if migration has not run yet.
        autoKotEnabled: {{ (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'auto_print_kot') && ($company->kitchen_printer_enabled ?? false) && ($company->auto_print_kot ?? false)) ? 'true' : 'false' }},
        // Phase 5+ — auto-dismiss timer for the success modal so cashiers can chain sales hands-free
        receiptAutoCloseTimer: null,
        // Print-chain session tracker — bumping the epoch invalidates in-flight iframe.onload /
        // afterprint callbacks so late-firing browser events (modal closed mid-sequence) cannot
        // enqueue stray prints. Mirrors restaurant POS engine.
        printSessionId: 0,
        pendingPrintTimers: [],
        // Registry of attached postMessage listeners — lets us remove them on cancel
        // so long cashier sessions (100s of bills) don't leak window-level listeners.
        printMessageHandlers: [],
        lastInvoiceNumber: '',
        lastTransactionId: null,
        lastOrderId: null,
        // Order Matching (Aug 2026) — FBR: token/code that lives with the current
        // cart. Set at first holdOrder() from server response; preserved on re-hold
        // by embedding in cartData before the POST (same-token invariant).
        // Cleared on clearCart() / newSale(). Written into billing payload so the
        // FBR transaction row carries the same identifier as the KOT.
        currentTokenNo: null,
        currentOrderCode: null,
        // ID of the most-recently created FbrPosHeldSale row — used to build the
        // FBR KOT URL after sendToKitchen() (held row must still exist at print time).
        lastHeldId: null,
        lastTotal: 0,
        lastPaymentMethod: '',
        // Success-popup extras: item count + sale timestamp + FBR copy state.
        lastItemsCount: 0,
        lastSaleAt: null,
        fbrCopied: false,
        // FBR fiscal result for the success popup. lastFbrStatus drives the status
        // badge (submitted / pending / offline / local); lastFbrNumber shows the
        // actual FBR fiscal invoice number once FBR returns it.
        lastFbrNumber: '',
        lastFbrStatus: '',
        submitting: false,
        cartAnimating: false,
        stockError: '',
        // Idempotency key — one UUID per bill attempt, reused across retries of the
        // SAME bill (same cart, response lost), regenerated only after confirmed
        // success / clearCart(). Server replay-guard uses this to return the existing
        // bill instead of creating a duplicate on a double-submit or network retry.
        billUuid: null,
        mobileView: 'menu',
        priorityOrder: false,
        recalledOrderId: null,
        // Task 170: held-order delivery address awaiting restore after recall —
        // survives the selectedCustomer watcher wipe + async address reload.
        pendingAddrRestore: null,
        toast: { show: false, message: '', type: 'success' },
        lastHoldTime: 0,
        lastPayTime: 0,
        showDiscount: false,
        showCartNote: false,
        discountType: 'percentage',
        discountValue: 0,
        discountAmount: 0,

        get filteredCustomers() {
            // PERF: cap rendered rows — an uncapped x-for over thousands of
            // customers renders them ALL into the DOM (boot freeze on weak PCs).
            const q = this.customerSearch.toLowerCase();
            // Task 100: server results (full DB) win when available; the baked
            // subset is only the instant/offline fallback on huge shops.
            if (q && this.pickerServerResults) return this.pickerServerResults.slice(0, 50);
            if (!q) return this.allCustomers.slice(0, 50);
            return this.allCustomers.filter(c => c.name.toLowerCase().includes(q) || (c.phone && c.phone.includes(q))).slice(0, 50);
        },

        r2(v) { return Math.round((v + Number.EPSILON) * 100) / 100; },
        _safeQty(q) { const n = Number(q); return Number.isFinite(n) && n > 0 ? n : 1; },
        // Total cart quantity for the items·qty pill in the total band (PRA redesign port).
        get cartQtyCount() { return this.cart.reduce((s, i) => s + this._safeQty(i.quantity), 0); },
        // Retail Core (Aug 2026): weight/measure units sell in fractions (0.5 KG,
        // 1.25 LTR, 2.5 MTR) — min qty 0.001 for these; count units stay min 1.
        _isFractionalUom(item) { return ['KG','GM','LTR','ML','MTR','SQM','FT','IN','YDS'].includes(String(item?.uom || '').toUpperCase()); },
        _qtyMin(item) { return this._isFractionalUom(item) ? 0.001 : 1; },
        getItemDiscount(item) {
            const lineTotal = this.r2(this._safeQty(item.quantity) * item.unit_price);
            const dv = parseFloat(item.item_discount_value) || 0;
            if (dv <= 0) return 0;
            if ((item.item_discount_type || 'percentage') === 'percentage') return this.r2(lineTotal * Math.min(100, dv) / 100);
            return this.r2(Math.min(lineTotal, dv));
        },
        getItemTotal(item) { return Math.max(0, this.r2(this._safeQty(item.quantity) * item.unit_price - this.getItemDiscount(item))); },
        get itemDiscountsTotal() { return this.r2(this.cart.reduce((s, i) => s + this.getItemDiscount(i), 0)); },
        get subtotal() { return this.r2(this.cart.reduce((s, i) => s + (this._safeQty(i.quantity) * i.unit_price), 0)); },
        get effectiveSubtotal() { return Math.max(0, this.r2(this.subtotal - this.itemDiscountsTotal)); },
        get taxableSubtotal() {
            // FBR: order-level discount does NOT reduce the tax base (mirrors store()).
            return Math.max(0, this.r2(this.cart.filter(i => !i.is_tax_exempt).reduce((s, i) => s + this.getItemTotal(i), 0)));
        },
        // FBR PER-ITEM TAX — mirrors FbrPosController::store() exactly:
        // lineTax = r2(lineSubtotal × rate/100); rate = exempt ? 0 : (item.tax_rate ?? 18).
        // Order discount is applied AFTER tax (does not shrink the tax base).
        _itemRate(i) { return i.is_tax_exempt ? 0 : ((i.tax_rate === 0 || i.tax_rate) ? parseFloat(i.tax_rate) : 18); },
        get taxAmount() {
            return this.r2(this.cart.reduce((s, i) => s + this.r2(this.getItemTotal(i) * this._itemRate(i) / 100), 0));
        },
        // Rs 1 FBR POS service charge — added by store() whenever the bill goes to FBR
        // (fbr mode). Provisional saves + FBR-OFF companies bill in local mode (Rs 0).
        get fbrServiceCharge() { return (this.fbrEnabled && this.fbrConfigured && !this.saveAsProvisional && this.cart.length > 0) ? 1 : 0; },
        get totalAmount() { return Math.max(0, this.r2(this.effectiveSubtotal - this.discountAmount + this.taxAmount + this.fbrServiceCharge)); },
        // FBR keeps DECIMALS (no PRA whole-rupee rounding) — store() rounds to 2dp only.
        get roundedTotal() { return this.totalAmount; },
        get roundOff() { return 0; },
        get exemptAmount() { return this.cart.filter(i => i.is_tax_exempt).reduce((s, i) => s + this.getItemTotal(i), 0); },
        recalcDiscount() {
            if (!this.discountValue || this.discountValue <= 0) { this.discountAmount = 0; return; }
            if (this.discountType === 'percentage') {
                const pct = Math.min(100, Math.max(0, this.discountValue));
                this.discountAmount = this.r2(this.effectiveSubtotal * pct / 100);
            } else {
                this.discountAmount = this.r2(Math.min(this.effectiveSubtotal, Math.max(0, this.discountValue)));
            }
        },

        // ── Screen Fit (Jul 2026, ported from PRA universal): sale screen adapts
        // to ANY display size. 'auto' derives a zoom factor from viewport size;
        // manual % saved per device in localStorage 'tn_screen_fit'. Root div gets
        // CSS zoom + a px height divided by the zoom (viewport units are NOT
        // reliable inside a zoomed subtree — px are).
        screenFit: 'auto',
        fitZoom: 1,
        fitStyleStr: '',
        showFitMenu: false,
        initFit() {
            try {
                const s = localStorage.getItem('tn_screen_fit');
                if (s) this.screenFit = (s === 'auto') ? 'auto' : (parseFloat(s) || 'auto');
            } catch (e) {}
            this.applyFit();
            window.addEventListener('resize', () => this.applyFit());
        },
        computeAutoFit() {
            const w = window.innerWidth, h = window.innerHeight;
            if (w < 768) return 1; // <md uses the dedicated mobile menu/cart layout
            let f = 1;
            if (w < 1360) f = 0.9;
            if (w < 1150) f = 0.85;
            if (w < 1000) f = 0.8;
            if (h < 700) f = Math.min(f, 0.9);
            if (h < 600) f = Math.min(f, 0.85);
            if (h < 520) f = Math.min(f, 0.8);
            if (w >= 2300 && h >= 1100) f = 1.15; // very large displays: don't look microscopic
            return f;
        },
        applyFit() {
            const f = this.screenFit === 'auto'
                ? this.computeAutoFit()
                : Math.min(1.5, Math.max(0.6, parseFloat(this.screenFit) || 1));
            this.fitZoom = f;
            // Guard: if the browser lacks CSS zoom, applying only the px height would
            // make the root taller than the viewport and clip the Pay button — worse
            // than no scaling at all. In that case keep the plain 100% layout.
            const zoomOk = (typeof CSS !== 'undefined' && CSS.supports && CSS.supports('zoom', '0.9'));
            this.fitStyleStr = (f === 1 || !zoomOk) ? '' : ('zoom:' + f + ';height:' + Math.round((window.innerHeight - 48) / f) + 'px');
        },
        setFit(v) {
            this.screenFit = v;
            try { localStorage.setItem('tn_screen_fit', String(v)); } catch (e) {}
            this.applyFit();
            this.showFitMenu = false;
        },
        fitLabel() { return this.screenFit === 'auto' ? 'Fit' : Math.round(this.screenFit * 100) + '%'; },

        // Generate a fresh idempotency UUID for a new bill. Falls back to a
        // timestamp+random string on older browsers that lack crypto.randomUUID.
        _newBillUuid() {
            try {
                if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
            } catch (e) {}
            return 'fbr-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 11);
        },

        init() {
            if (this._inited) return;
            this._inited = true;
            // Seed the first bill UUID — regenerated on every clearCart() so each
            // new bill gets a fresh key while retries of the SAME bill reuse it.
            this.billUuid = this._newBillUuid();
            // Task 1271: navigating away with a recalled draft attached → best-
            // effort lock release (beacon survives the page teardown).
            window.addEventListener('pagehide', () => this.releaseDraftLockOnExit());
            this.initFit();
            // Honor the saved "hide products" preference ONLY in inventory-OFF mode.
            // Inventory mode must always show the catalog (no manual on-the-fly create).
            try { if (localStorage.getItem('fbr_show_products') === '0') this.showProducts = false; } catch (e) {}
            this.filterProducts();
            setTimeout(() => { this.loading = false; }, 300);
            this.$watch('activeCategory', () => { this.filterProducts(); this.gridFocusIndex = 0; if (this.searchQuery.trim().length > 0) this.onSearchInput(); });
            this.calcGridCols();
            window.addEventListener('resize', () => this.calcGridCols());
            // Cart auto-restore is intentionally disabled — every page load starts with an EMPTY cart.
            // Saved cart is written to localStorage as a safety net only (debounced 400ms — see saveCart).
            // Do NOT call this.restoreCart() here without explicit product approval.
            this.$watch('cart', () => { this.saveCart(); this.recalcDiscount(); }, { deep: true });
            this.$watch('kitchenNotes', () => { this.saveCart(); });
            setTimeout(() => this.cacheProductData(), 800);
            document.addEventListener('keydown', (e) => this.handleKey(e));
            // Task 163: keep the delivery-address picker in sync — covers EVERY
            // customer select/clear path + order-type switch without patching each.
            this.$watch('selectedCustomer', (c) => {
                this.customerAddresses = []; this.selectedDeliveryAddress = ''; this.showAddrNew = false; this.newAddrText = ''; this.newAddrLabel = '';
                if (c && this.orderType === 'delivery') this.loadCustomerAddresses();
                else if (this.pendingAddrRestore && this.orderType === 'delivery') {
                    // Task 170: walk-in delivery recall — no address reload runs,
                    // so re-apply the held one-off address here after the wipe above.
                    this.selectedDeliveryAddress = this.pendingAddrRestore; this.pendingAddrRestore = null;
                } else this.pendingAddrRestore = null;
            });
            this.$watch('orderType', (t) => {
                if (t === 'delivery' && this.selectedCustomer && !this.customerAddresses.length) this.loadCustomerAddresses();
            });
            this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); });
            // Lazy-load provisional bill list on mount (for header badge count).
            // Failures are silent — badge just won't show until next refresh.
            setTimeout(() => this.loadLocalBills(), 1200);
            setTimeout(() => this.loadFailedBills(), 1500);
            setTimeout(() => this.loadHeldOrders(), 1000);
            // 🔄 Auto-Sync — kicks in after 4 sec, then every 30 sec.
            // Live-updates online/offline pill + silently retries pending bills.
            setTimeout(() => this._startAutoSync(), 4000);
            // OFFLINE-FIRST BOOT: verify the (possibly SW-cached) page is fresh.
            setTimeout(() => this.bootFpCheck(), 1500);
            // Desktop shell resume-check hook (PRA parity) — NestPOS Desktop calls
            // this when the window wakes so a days-old cached screen re-verifies.
            try { window.tnDesktopResumeCheck = () => { try { this.bootFpCheck(); } catch (e) {} }; } catch (e) {}
        },

        // ─── AUTO-SYNC ENGINE ──────────────────────────────────────────────
        // Browser-side companion to the SyncPosOfflineInvoicesJob (cron).
        // Every 30 sec: refresh online/offline state, count pending bills,
        // and silently retry the OLDEST one. One bill per tick = no FBR flood.
        _startAutoSync() {
            if (this._syncTimer) return;
            window.addEventListener('online', () => { this.syncStatus = 'online'; this.offlineLockDismissed = false; this.syncOfflineBills(); this._autoSyncTick(true); });
            window.addEventListener('offline', () => { this.syncStatus = 'offline'; });
            this.refreshOfflineCount();
            this.syncOfflineBills();
            this._autoSyncTick();
            this._syncTimer = setInterval(() => this._autoSyncTick(), 30000);

            // PWA auto-update guard: hold the auto-reload while a sale is mid-flight
            // (items in cart, pay modal open, submitting, or the persistent receipt
            // popup still on screen) — pwa-update toast retries afterwards.
            window.tnPwaUpdateHold = () => (this.cart && this.cart.length > 0) || this.showPayModal || this.showReceipt || this.submitting;
        },
        async _autoSyncTick(force = false) {
            if (this._autoSyncBusy) return;
            // Respect navigator.onLine but don't trust it 100% — we still try
            // a lightweight count fetch which doubles as a connectivity probe.
            if (!navigator.onLine) { this.syncStatus = 'offline'; return; }
            // Offline-first queue drains FIRST — device-local bills must reach the
            // server before failed-bill FBR retries (they're older by definition).
            if (this.offlineQueueCount > 0) await this.syncOfflineBills();
            this._autoSyncBusy = true;
            try {
                // Lightweight refresh of pending count (also serves as ping).
                await this.loadFailedBills();
                if (!this.fbrEnabled) { this.syncStatus = 'online'; this._autoSyncBusy = false; return; }
                if (this.failedBills.length === 0) { this.syncStatus = 'online'; this._autoSyncBusy = false; return; }
                // Pick OLDEST not-currently-retrying bill that has fewer than 3 strikes
                // this session. The 3-strike cap prevents an infinite loop when a bill
                // permanently fails (e.g. FBR API down or a config error that somehow
                // still has fbr_status='failed'). config_error bills are excluded from
                // failedBills by the server and never reach this path.
                const candidate = [...this.failedBills].reverse().find(b =>
                    !b._retrying && (this._autoSyncStrikes.get(b.id) || 0) < 3
                );
                if (!candidate) { this.syncStatus = 'online'; this._autoSyncBusy = false; return; }
                this.syncStatus = 'syncing';
                candidate._retrying = true;
                const res = await fetch('{{ url('/fbr-pos/api/failed-bills') }}/' + candidate.id + '/retry', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                });
                const data = await res.json().catch(() => ({}));
                if (data && data.success) {
                    this.failedBills = this.failedBills.filter(b => b.id !== candidate.id);
                    this._autoSyncStrikes.delete(candidate.id); // clean up on success
                    // Mini toast — non-intrusive (existing showToast auto-dismisses).
                    this.showToast(window.TXT.auto_synced_prefix + (candidate.invoice_number || '#' + candidate.id) + ' to FBR', 'success');
                } else {
                    // Increment strike count. After 3 failures this session the bill is
                    // skipped by the auto-sync but remains manually retryable in F11.
                    this._autoSyncStrikes.set(candidate.id, (this._autoSyncStrikes.get(candidate.id) || 0) + 1);
                    candidate._retrying = false;
                }
                this.syncStatus = 'online';
            } catch (e) {
                console.warn('autoSyncTick', e);
                this.syncStatus = navigator.onLine ? 'online' : 'offline';
            }
            this._autoSyncBusy = false;
        },

        // ─── OFFLINE-FIRST BILLING ENGINE (Aug 2026 — PRA port) ───────────────
        // When the device has NO internet at Pay time, the bill payload is
        // stored in IndexedDB with a client UUID and replayed automatically
        // (online event + every auto-sync tick). Server-side offline_uuid
        // dedupe makes replays idempotent — a lost response never duplicates.
        _offlineCompanyId: {{ (int) (app('currentCompanyId') ?? 0) }},
        idbOpen() {
            return new Promise((resolve, reject) => {
                if (this._idb) return resolve(this._idb);
                if (!window.indexedDB) return reject(new Error('IndexedDB unavailable'));
                const req = indexedDB.open('tn_fbrpos_offline', 1);
                req.onupgradeneeded = () => {
                    const db = req.result;
                    if (!db.objectStoreNames.contains('bills')) db.createObjectStore('bills', { keyPath: 'uuid' });
                };
                req.onsuccess = () => { this._idb = req.result; resolve(this._idb); };
                req.onerror = () => reject(req.error);
            });
        },
        async idbPut(rec) {
            const db = await this.idbOpen();
            return new Promise((res, rej) => {
                const tx = db.transaction('bills', 'readwrite');
                tx.objectStore('bills').put(rec);
                tx.oncomplete = res; tx.onerror = () => rej(tx.error);
            });
        },
        async idbAllMine() {
            const db = await this.idbOpen();
            return new Promise((res, rej) => {
                const rq = db.transaction('bills').objectStore('bills').getAll();
                rq.onsuccess = () => res((rq.result || []).filter(b => b.company_id === this._offlineCompanyId));
                rq.onerror = () => rej(rq.error);
            });
        },
        async idbDelete(uuid) {
            const db = await this.idbOpen();
            return new Promise((res, rej) => {
                const tx = db.transaction('bills', 'readwrite');
                tx.objectStore('bills').delete(uuid);
                tx.oncomplete = res; tx.onerror = () => rej(tx.error);
            });
        },
        async refreshOfflineCount() {
            try { this.offlineQueueCount = (await this.idbAllMine()).length; } catch (e) {}
        },
        // Queue a bill that could NOT reach the server (no internet). Mirrors the
        // success UX: receipt popup (offline variant) + optional auto-print of a
        // client-rendered interim receipt, cart cleared so billing continues.
        async queueOfflineBill(payload, method, savedTotal, skipReceipt = false) {
            // REUSE the uuid already attached by processPaymentManual (it rode on
            // the failed online attempt too) — minting a fresh one here would
            // reopen the lost-response duplicate window. Fallback only if absent.
            const uuid = payload.offline_uuid || this._newBillUuid();
            payload.offline_uuid = uuid;
            // Ride the ORIGINAL sale moment + cashier on the payload so a
            // next-morning sync books the bill under the right date & user (server
            // clamps the timestamp and company-checks the user — spoof-safe).
            payload.offline_queued_at = new Date().toISOString();
            payload.offline_queued_by = {{ (int) auth('fbrpos')->id() }};
            // Multi-branch fidelity: snapshot the branch this screen was rendered
            // for, so a bill queued on branch A and synced later (possibly after a
            // different login) still books under branch A. Server company-checks it.
            payload.offline_branch_id = {{ (int) (app()->bound('currentBranchId') ? (app('currentBranchId') ?? 0) : 0) }};
            const rec = {
                uuid,
                company_id: this._offlineCompanyId,
                payload,
                method,
                provisional: !!payload.save_as_provisional,
                total: Math.round(savedTotal || 0),
                customer: this.selectedCustomer?.name || '',
                // Frozen display snapshot for the popup + interim receipt (as-entered prices).
                items: this.cart.map(c => ({
                    name: c.item_name,
                    qty: this._safeQty(c.quantity),
                    price: c.unit_price,
                })),
                queued_at: Date.now(),
                tries: 0,
                last_error: '',
            };
            await this.idbPut(rec);
            await this.refreshOfflineCount();
            this.lastIsOffline = true;
            this.lastOfflineRec = rec;
            this.lastInvoiceNumber = 'OFFLINE-' + uuid.slice(0, 8).toUpperCase();
            this.lastTransactionId = null;
            this.lastOrderId = null;
            this.lastTotal = rec.total;
            this.lastPaymentMethod = method;
            this.lastFbrNumber = '';
            this.lastFbrStatus = '';
            this.lastCashReceived = method === 'cash' ? (parseFloat(this.cashReceived) || 0) : 0;
            this.lastItemsCount = (this.cart || []).reduce((s, i) => s + (parseFloat(i.quantity) || 0), 0);
            this.lastSaleAt = Date.now();
            this.setWaBill(null); // Task 1271: offline bill = no server link yet — WA button hidden
            this.showReceipt = true;
            this.scheduleReceiptAutoClose();
            this.showToast(window.TXT.offline_bill_saved_will_sync, 'success');
            // Task 520: per-bill untick = interim offline receipt bhi auto-print skip
            // (popup + queue + sync untouched).
            if (!skipReceipt && this.autoPrintEnabled) {
                // Task 565: offline bill bhi "bill complete" hai — flag ON ho to
                // wahi Yes/No gate; Yes par mojooda 400ms timing waisi hi.
                if (this.printConfirmAsk) {
                    this.openPrintConfirm(() => setTimeout(() => this.printOfflineReceipt(), 400));
                } else {
                    setTimeout(() => this.printOfflineReceipt(), 400);
                }
            }
            this.clearCart();
            this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); });
        },
        // Replay queued offline bills, OLDEST first. Stops on network failure
        // (still offline) or auth loss (419/401 — session expired; bills stay
        // safe until the cashier logs in again and the page reloads).
        // NOTE (FBR vs PRA): no provisional-consent fallback here — FBR monthly
        // quota counts provisionals too, so replaying as provisional would not
        // bypass a quota 403. A 403 simply stops the run (bills stay queued).
        async syncOfflineBills(manual = false) {
            if (this.offlineSyncing) return;
            if (!navigator.onLine) {
                if (manual) this.showToast(window.TXT.still_offline_will_sync, 'error');
                return;
            }
            let bills = [];
            try { bills = await this.idbAllMine(); } catch (e) { return; }
            if (!bills.length) {
                if (manual) this.showToast(window.TXT.all_bills_synced, 'success');
                return;
            }
            this.offlineSyncing = true;
            this.syncStatus = 'syncing';
            let ok = 0, failed = 0, authStop = false, poisoned = 0;
            for (const b of bills.sort((a, z) => a.queued_at - z.queued_at)) {
                // Poison-bill cap: after 50 REJECTED attempts (server said no — not
                // network drops, those `break` before counting) stop retrying so one
                // bad bill can't block/spam the queue forever. It stays on-device
                // (badge + count) for support to inspect.
                if ((b.tries || 0) >= 50) { poisoned++; continue; }
                try {
                    const res = await fetch('{{ route("fbrpos.store") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify(b.payload),
                    });
                    let data = null;
                    try { data = JSON.parse(await res.text()); } catch (_) {}
                    if (res.ok && data && data.success) {
                        await this.idbDelete(b.uuid);
                        ok++;
                        continue;
                    }
                    if (res.status === 419 || res.status === 401) { this.offlineNeedsLogin = true; authStop = true; break; }
                    b.tries = (b.tries || 0) + 1;
                    b.last_error = (data && (data.message || data.error)) || ('HTTP ' + res.status);
                    await this.idbPut(b);
                    failed++;
                    // Quota/plan block (403) fails every remaining bill too — stop hammering.
                    if (res.status === 403) break;
                } catch (e) {
                    // Network dropped again mid-sync — keep the rest queued.
                    break;
                }
            }
            this.offlineSyncing = false;
            this.syncStatus = navigator.onLine ? 'online' : 'offline';
            await this.refreshOfflineCount();
            if (ok > 0) {
                this.showToast(ok + ' offline bill' + (ok === 1 ? '' : 's') + ' synced ✓', 'success');
                this.loadLocalBills();
                this.loadFailedBills();
            }
            if (authStop) this.showToast(window.TXT.session_expired_offline_safe, 'error');
            else if (failed > 0 && manual) this.showToast(failed + ' bill(s) could not sync — see pending badge', 'error');
            else if (poisoned > 0 && manual) this.showToast(poisoned + window.TXT.bills_blocked_support, 'error');
        },
        // Client-rendered interim receipt for an OFFLINE bill (no server template
        // reachable). Grand TOTAL only — never prints subtotal/tax lines, which
        // also satisfies the strictest "Show Tax on Receipt = OFF" policy.
        printOfflineReceipt() {
            const r = this.lastOfflineRec;
            if (!r) return;
            const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const rows = (r.items || []).map(i =>
                '<tr><td>' + esc(i.name) + '<br><span class="m">' + esc(i.qty) + ' x ' + Number(i.price).toLocaleString() + '</span></td>' +
                '<td class="r">' + Number(this.r2(i.qty * i.price)).toLocaleString() + '</td></tr>'
            ).join('');
            const html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Offline Receipt</title><style>' +
                '@page{margin:2mm;}body{font-family:"Courier New",monospace;font-size:12px;color:#000;margin:0;padding:4px;}' +
                'h1{font-size:14px;text-align:center;margin:0 0 2px;}p{margin:2px 0;text-align:center;}' +
                'table{width:100%;border-collapse:collapse;margin-top:4px;}td{padding:2px 0;vertical-align:top;}' +
                '.r{text-align:right;white-space:nowrap;}.m{color:#000;font-size:11px;}' +
                '.tot{border-top:2px solid #000;border-bottom:2px solid #000;font-weight:bold;font-size:14px;}' +
                '.note{border:1px dashed #000;padding:3px;margin-top:6px;text-align:center;font-weight:bold;font-size:11px;}' +
                '</style></head><body>' +
                '<h1>' + esc(@json($company->name ?? 'NestPOS')) + '</h1>' +
                '<p>' + new Date(r.queued_at).toLocaleString() + '</p>' +
                (r.customer ? '<p>Customer: ' + esc(r.customer) + '</p>' : '') +
                '<p>Ref: ' + esc('OFFLINE-' + r.uuid.slice(0, 8).toUpperCase()) + ' · ' + esc(r.method) + '</p>' +
                '<table>' + rows +
                '<tr class="tot"><td>TOTAL</td><td class="r">Rs. ' + Number(r.total).toLocaleString() + '</td></tr></table>' +
                '<div class="note">OFFLINE PROVISIONAL RECEIPT<br>Final invoice number issues after sync</div>' +
                '</body></html>';
            const fr = document.createElement('iframe');
            fr.style.cssText = 'position:fixed;width:0;height:0;border:0;visibility:hidden;';
            document.body.appendChild(fr);
            fr.srcdoc = html;
            fr.onload = () => {
                try { fr.contentWindow.focus(); fr.contentWindow.print(); } catch (e) {}
                setTimeout(() => { try { fr.remove(); } catch (e) {} }, 60000);
            };
        },

        // OFFLINE-FIRST BOOT (Aug 2026 — PRA port): the SW serves this page
        // cache-first, so a cached copy verifies its baked fingerprint against
        // the server shortly after boot. Mismatch → refresh the sale cache with
        // a FRESH network copy + ONE-SHOT reload (never yanks a sale in progress
        // — except on user/company switch, which is a security reload).
        // Offline / network fail → silently keep the cached screen.
        bootFpCheck() {
            try {
                const cur = window.tnBootFp;
                if (!cur) return;
                fetch('{{ route('fbrpos.api.boot-check') }}', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(r => {
                        // Dead session must ALSO drop the cached sale screen, or every
                        // attempt to open POS replays the stale cached copy → splash →
                        // bounce to login → again and again. Drop first, then go.
                        const toLogin = () => {
                            try { navigator.serviceWorker?.controller?.postMessage({ type: 'TN_DROP_SALE_CACHE' }); } catch (e) {}
                            setTimeout(() => window.location.replace('{{ route('fbrpos.login') }}'), 250);
                        };
                        if (r.redirected || r.status === 401 || r.status === 419) { toLogin(); return null; }
                        if (!r.ok) return null;
                        const ct = r.headers.get('content-type') || '';
                        if (!ct.includes('json')) { toLogin(); return null; }
                        return r.json();
                    })
                    .then(d => {
                        if (!d || !d.ok || !d.fp) return;
                        const fresh = d.fp;
                        const same = ['u', 'c', 's', 'cat', 'set'].every(k => String(cur[k]) === String(fresh[k]));
                        if (same) return;
                        const userChanged = String(cur.u) !== String(fresh.u) || String(cur.c) !== String(fresh.c);
                        // Never yank an in-progress sale for a content update.
                        const busy = (this.cart && this.cart.length > 0) || this.showPayModal || this.showReceipt || this.submitting;
                        if (!userChanged && busy) return;
                        // One-shot guard: never reload twice for the same server fingerprint
                        // (protects against a reload loop if the cache update races us).
                        const sig = [fresh.u, fresh.c, fresh.s, fresh.cat, fresh.set].join(':');
                        try {
                            if (!userChanged && sessionStorage.getItem('tnFbrBootFpReloaded') === sig) return;
                        } catch (e) {}
                        // LOOP-PROOF RELOAD (PRA lesson): fetch a FRESH copy over the
                        // network FIRST, put it into the sale cache OURSELVES, and reload
                        // only once the new page is secured. Network down/flaky → keep
                        // the current working screen.
                        (async () => {
                            if (!userChanged) {
                                try {
                                    const resp = await fetch(window.location.pathname, { cache: 'reload', credentials: 'same-origin' });
                                    const ct = (resp && resp.headers.get('content-type')) || '';
                                    if (!resp || !resp.ok || resp.redirected || !ct.includes('text/html')) return;
                                    if (window.caches) {
                                        try {
                                            // Never hardcode the versioned cache name — find the
                                            // live '-sale' cache (sw.js bumps CACHE_VERSION often).
                                            const names = await caches.keys();
                                            const saleName = names.find(n => n.endsWith('-sale'));
                                            if (saleName) {
                                                const c = await caches.open(saleName);
                                                await c.put(new Request(window.location.pathname), resp.clone());
                                            }
                                        } catch (e) {}
                                    }
                                } catch (e) { return; } // offline — cached screen keeps working
                            } else {
                                // Different user/company baked in — the cached copy is
                                // WRONG for this session; drop it and force network.
                                try { navigator.serviceWorker?.controller?.postMessage({ type: 'TN_DROP_SALE_CACHE' }); } catch (e) {}
                                await new Promise(r => setTimeout(r, 300));
                            }
                            try { sessionStorage.setItem('tnFbrBootFpReloaded', sig); } catch (e) {}
                            window.location.reload();
                        })();
                    })
                    .catch(() => {}); // offline → cached screen keeps working as-is
            } catch (e) {}
        },

        cacheProductData() {
            try {
                const key = 'fbrpos_products_cache_{{ app("currentCompanyId") ?? 0 }}';
                const cached = localStorage.getItem(key);
                if (cached) {
                    const data = JSON.parse(cached);
                    if (Date.now() - data.ts < 300000 && data.products.length > 0) {
                        return;
                    }
                }
                localStorage.setItem(key, JSON.stringify({ ts: Date.now(), products: this.allProducts, services: this.allServices }));
            } catch(e) {}
        },

        get storageKey() { return 'fbrpos_cart_{{ auth("fbrpos")->id() ?? 0 }}_{{ app("currentCompanyId") ?? 0 }}'; },
        get notesKey() { return 'fbrpos_notes_{{ auth("fbrpos")->id() ?? 0 }}_{{ app("currentCompanyId") ?? 0 }}'; },
        _saveCartTimer: null,
        saveCart() {
            // Debounced localStorage write — avoids hot-path JSON.stringify on every qty keystroke / cart mutation.
            if (this._saveCartTimer) clearTimeout(this._saveCartTimer);
            this._saveCartTimer = setTimeout(() => {
                try { localStorage.setItem(this.storageKey, JSON.stringify(this.cart)); localStorage.setItem(this.notesKey, this.kitchenNotes); } catch(e) {}
            }, 400);
        },
        restoreCart() {
            try {
                const saved = localStorage.getItem(this.storageKey);
                if (saved) { const parsed = JSON.parse(saved); if (Array.isArray(parsed) && parsed.length > 0) this.cart = parsed.map(i => ({ ...i, cart_uid: i.cart_uid || ('c' + Date.now() + '_' + Math.random().toString(36).slice(2,9)) })); }
                const notes = localStorage.getItem(this.notesKey);
                if (notes) this.kitchenNotes = notes;
            } catch(e) {}
        },
        clearCartStorage() {
            try { localStorage.removeItem(this.storageKey); localStorage.removeItem(this.notesKey); } catch(e) {}
        },

        calcGridCols() {
            this.$nextTick(() => {
                const grid = this.$refs.gridContainer?.querySelector('.grid');
                if (grid) {
                    const cs = window.getComputedStyle(grid);
                    const cols = (cs.getPropertyValue('grid-template-columns') || '').split(' ').filter(Boolean).length;
                    if (cols > 0) { this.gridCols = cols; return; }
                }
                const w = this.$refs.gridContainer?.offsetWidth || 800;
                if (w >= 1280) this.gridCols = 5;
                else if (w >= 1024) this.gridCols = 4;
                else if (w >= 640) this.gridCols = 3;
                else this.gridCols = 2;
            });
        },

        enterSearchMode() { this.gridFocusMode = false; this.$refs.searchInput?.focus(); },
        // ── GUIDED FLOW: Order-Type step (dine in / takeaway / delivery) ──────
        // Owner-specified step BETWEEN Items and Cart. guidedOrderTypes() returns only the
        // types enabled for this company (mirrors the header buttons). enterTypeStep() seeds
        // the highlight from the current orderType + blurs so handleKey owns arrows/Enter;
        // the overlay (x-show flowStep==='type') renders the choices; confirmGuidedType()
        // commits the pick and drops into the cart. All gated on guidedFlow.
        guidedOrderTypes() { return @json($guidedTypes); },
        guidedTypeLabel(k) { return ({ dine_in: window.TXT.dine_in, takeaway: window.TXT.takeaway, delivery: window.TXT.delivery })[k] || k; },
        enterTypeStep() {
            this.flowStep = 'type';
            const i = this.guidedOrderTypes().indexOf(this.orderType);
            this.flowTypeIndex = i >= 0 ? i : 0;
            this.showSearchDropdown = false;
            document.activeElement?.blur();
        },
        confirmGuidedType() {
            const types = this.guidedOrderTypes();
            this.orderType = types[this.flowTypeIndex] || types[0] || 'takeaway';
            this.flowStep = 'cart';
            this.enterCartMode('last');
        },
        enterGridMode() {
            if (this.displayItems.length === 0) return;
            this.gridFocusMode = true; this.gridFocusIndex = 0; this.showSearchDropdown = false;
            this.$refs.gridContainer?.focus(); this.scrollGridItemIntoView(0);
        },
        moveGridFocus(delta) {
            if (!this.gridFocusMode) { this.enterGridMode(); return; }
            const n = this.displayItems.length;
            if (n === 0) return;
            // ZFC (5 Aug 2026): wrap-around — pehle item par Up dabao to seedha
            // AAKHRI item par pahuncho (aur aakhri se Down wapas pehle par);
            // neeche wale option tak bar-bar Down dabane ki zaroorat nahi.
            let newIdx = this.gridFocusIndex + delta;
            if (newIdx < 0) newIdx = n - 1;
            else if (newIdx >= n) newIdx = 0;
            this.gridFocusIndex = newIdx; this.scrollGridItemIntoView(newIdx);
        },
        scrollGridItemIntoView(idx) { this.$nextTick(() => { document.getElementById('grid-item-' + idx)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }); },
        addGridFocusedItem() {
            if (!this.gridFocusMode || this.displayItems.length === 0) return;
            const item = this.displayItems[this.gridFocusIndex];
            if (item) this.handleProductClick(item);
        },

        handleProductClick(item) {
            // Stock blocking is the ONLY gate on add-to-cart, and it is itself gated
            // by isInventoryEnabled() — when inventory is OFF, every product is addable.
            if (this.isInventoryEnabled() && item.stockStatus === 'out' && this.blockOutOfStock) {
                this.showToast(item.name + ' is out of stock', 'error');
                return;
            }
            this.addToCart(item);
            this.showToast(window.TXT.added_prefix + item.name, 'success');
        },

        getCartQty(item) {
            const found = this.cart.find(c => c.item_id === item.id && c.item_type === item.type);
            return found ? found.quantity : 0;
        },

        // BARCODE SCAN support (ported from PRA universal, Aug 2026): true when the typed
        // query EXACTLY equals a product's barcode or SKU (case-insensitive). Scanners
        // "type" the code then send Enter — often faster than the 60ms search debounce.
        isExactCodeMatch(it, q) {
            return (it.barcode && String(it.barcode).toLowerCase() === q)
                || (it.sku && String(it.sku).toLowerCase() === q);
        },
        findExactCodeItem(q) {
            if (!q) return null;
            const all = [...this.allProducts, ...this.allServices];
            return all.find(it => it.name && parseFloat(it.price) > 0 && this.isExactCodeMatch(it, q)) || null;
        },
        _searchDebounceTimer: null,
        onSearchInput() {
            // Toggle dropdown synchronously so empty-state hides instantly (no flicker).
            const q = this.searchQuery.trim().toLowerCase();
            if (q.length === 0) { this.searchSuggestions = []; this.showSearchDropdown = false; }
            // Debounce the actual filter work (60ms) — fast enough to feel instant, prevents thrash on long pastes.
            if (this._searchDebounceTimer) clearTimeout(this._searchDebounceTimer);
            this._searchDebounceTimer = setTimeout(() => {
                this.filterProducts();
                // Search works in BOTH modes: even when the product grid is hidden
                // (showProducts OFF), typing still surfaces matching catalog items so the
                // cashier can search a saved product and add it to the cart. No catalog
                // match falls through to the inline "Create" prompt (inventory-OFF only).
                if (q.length > 0) {
                    // ── BARCODE / ARTICLE # EXACT MATCH (Aug 2026 — Retail Fast Billing) ──
                    // If the query is an exact (case-insensitive) match of a product's barcode
                    // or sku, add it directly and clear the search — no dropdown, no Enter needed.
                    // Strict-prefix guard: only fire when query has no spaces (raw scanner input).
                    if (!q.includes(' ') && q.length >= 3) {
                        const barcodeHit = this.allProducts.find(p =>
                            p.barcode && p.barcode.toString().toLowerCase() === q
                        ) || this.allProducts.find(p =>
                            p.sku && p.sku.toString().toLowerCase() === q
                        );
                        if (barcodeHit) {
                            this.searchQuery = '';
                            this.showSearchDropdown = false;
                            this.filterProducts();
                            this.quickAddItem(barcodeHit);
                            return;
                        }
                    }
                    // CATEGORY DROPDOWN: a chosen category narrows the suggestion pool to it.
                    // "all" = whole catalog (old behavior, byte-identical).
                    let all;
                    if (this.activeCategory === 'services') all = [...this.allServices];
                    else if (this.activeCategory !== 'all') all = this.allProducts.filter(p => p.category === this.activeCategory);
                    else all = [...this.allProducts, ...this.allServices];
                    // Task 1271: rank-based matching via nameMatchRank (shared with
                    // filterProducts — the two surfaces must never diverge). anyWord
                    // honors the admin search-mode pref; prefix hits still sort first.
                    const ranked = [];
                    for (let i = 0; i < all.length; i++) {
                        const it = all[i];
                        if (!it.name) continue;
                        // Unpriced PRODUCTS stay visible on inventory-OFF companies: picking
                        // one opens the full details popup (central routing in quickAddItem,
                        // owner Aug 2026) instead of dropping a Rs.0 row. Unpriced services
                        // and inventory-ON rows stay hidden (old behavior).
                        if (!(parseFloat(it.price) > 0) && ((it.type || 'product') !== 'product' || this.isInventoryEnabled())) continue;
                        const r = this.nameMatchRank(it.name, q, this.searchAnyWord);
                        if (r > 0) ranked.push({ it, r });
                    }
                    ranked.sort((a, b) => b.r - a.r);
                    const out = ranked.slice(0, 12).map(x => x.it);
                    this.searchSuggestions = out;
                    this.highlightIndex = 0;
                    this.showSearchDropdown = true;
                }
            }, 60);
        },
        moveHighlight(dir) {
            if (!this.showSearchDropdown || this.searchSuggestions.length === 0) return;
            this.highlightIndex = Math.max(0, Math.min(this.searchSuggestions.length - 1, this.highlightIndex + dir));
            this.$nextTick(() => {
                const dd = this.$refs.searchDropdown;
                if (dd) {
                    const active = dd.querySelector('[data-hl="true"]');
                    if (active) active.scrollIntoView({ block: 'nearest' });
                }
            });
        },
        addHighlightedItem(e) {
            // GUIDED FLOW: if the Order-Type overlay is ALREADY open but focus somehow
            // returned to the search box (e.g. quick-price's delayed refocus fires after
            // enterTypeStep's blur), the input's Enter (.prevent.stop) must CONFIRM the
            // highlighted type — never re-enter/re-seed the step. Without this the cashier
            // is stuck: arrows bubble to handleKey and move the highlight, but every Enter
            // re-seeds the highlight back to the current orderType and never confirms.
            // !e?.repeat mirrors the document-path held-Enter guard in handleKey.
            if (this.guidedFlow && this.flowStep === 'type') { if (!e?.repeat) this.confirmGuidedType(); return; }
            // ZFC SWITCH PROMPT open + focus raced back into the search box:
            // forward Enter to the prompt's confirm — never re-run search logic
            // behind the modal (same forwarding pattern as the type step above).
            if (this.tableSwitchPrompt) { if (!e?.repeat) this.confirmTableSwitch(this.tableSwitchIndex === 1 ? 'discard' : 'move'); return; }
            // BARCODE SCAN fast path (ported from PRA universal — Aug 2026 scanner bug):
            // scanner's Enter can arrive BEFORE the 60ms search debounce fills the dropdown —
            // an exact barcode/SKU match must add instantly here, or (inventory-OFF) the scan
            // falls through to quick-CREATE a bogus product named after the barcode digits.
            // Skipped when the cashier has ARROWED to a suggestion (highlightIndex > 0):
            // their explicit pick wins over an accidental short-SKU collision.
            const scanQ = this.searchQuery.trim().toLowerCase();
            if (scanQ.length > 0 && this.highlightIndex === 0) {
                const exact = this.findExactCodeItem(scanQ);
                if (exact) {
                    if (this._searchDebounceTimer) clearTimeout(this._searchDebounceTimer);
                    this.quickAddItem(exact);
                    return;
                }
            }
            if (this.showSearchDropdown && this.searchSuggestions.length > 0) { this.quickAddItem(this.searchSuggestions[this.highlightIndex]); return; }
            // No catalog match: in SIMPLE (inventory-OFF) mode, Enter creates the typed item on the fly.
            if (!this.isInventoryEnabled() && this.searchQuery.trim().length > 0 && !this.quickCreating) {
                // DUPLICATE GUARD: before quick-creating, check the WHOLE catalog (any category,
                // hidden included) for an exact NAME match — an active category filter must never
                // cause a second 'Quick' copy of a product that already exists elsewhere.
                const nameQ = this.searchQuery.trim().toLowerCase();
                // ANY price matches here (no price>0 gate): a zero-price product (quick-created,
                // price never set) must still be found, or every scan/Enter of the same text
                // re-creates the same product forever (Aug 2026 scanner bug).
                const existing = [...this.allProducts, ...this.allServices].find(it => it.name && it.name.trim().toLowerCase() === nameQ);
                if (existing) {
                    // Unpriced PRODUCT match: open the FULL popup prefilled (owner, Aug 2026 —
                    // "cart mein jaakar nahi"); it joins the cart only after Save. Priced rows
                    // and services keep the instant-add (+ inline editor for unpriced services).
                    if (!(parseFloat(existing.price) > 0) && (existing.type || 'product') === 'product') {
                        this.qcOpenForExisting(existing);
                        return;
                    }
                    this.quickAddItem(existing);
                    if (!(parseFloat(existing.price) > 0)) {
                        const row = [...this.cart].reverse().find(c => c.item_id === existing.id && c.item_type === (existing.type || 'product'));
                        if (row) this.openQuickPrice(row);
                    }
                    return;
                }
                this.quickCreateProduct(); return;
            }
            // GUIDED FLOW (opt-in): Enter on an EMPTY search box advances the chain.
            // When the company has 2+ order types, it first opens the Order-Type step
            // (dine in / takeaway / delivery) — the owner-specified step between Items and
            // Cart. Single-type companies skip straight to the cart (byte-identical to before).
            if (this.guidedFlow && this.searchQuery.trim().length === 0 && this.cart.length > 0) {
                if (this.guidedOrderTypes().length > 1) { this.enterTypeStep(); return; }
                this.flowStep = 'cart';
                this.enterCartMode('last');
            }
        },
        quickAddItem(item) {
            // Kill any in-flight debounced search so it can't repopulate the dropdown
            // under the now-cleared search box after we add the item.
            if (this._searchDebounceTimer) clearTimeout(this._searchDebounceTimer);
            // CENTRAL unpriced-product routing (owner, Aug 2026): EVERY selection path —
            // suggestion click, dropdown Enter, exact-match fast path, barcode auto-add —
            // funnels through here, so an UNPRICED product always opens the full details
            // popup instead of dropping a Rs.0 row in the cart. Inventory-OFF only (the
            // popup's endpoint 403s on inventory-ON); services keep instant-add.
            if (!(parseFloat(item.price) > 0) && (item.type || 'product') === 'product' && !this.isInventoryEnabled()) {
                this.qcOpenForExisting(item);
                return;
            }
            this.handleProductClick(item);
            // GUIDED FLOW (opt-in): first added item moves the indicator off "customer".
            if (this.guidedFlow && this.flowStep === 'customer') this.flowStep = 'items';
            this.searchQuery = ''; this.searchSuggestions = []; this.showSearchDropdown = false;
            this.filterProducts(); this.$nextTick(() => { this.$refs.searchInput?.focus(); });
        },

        // Task 1271 (PRA port): word-aware search matcher — tokens split on
        // non-alphanumerics so "cheese(half)" still tokenizes; \u0080-\uffff keeps
        // Urdu/Unicode chars. BOTH surfaces (dropdown + grid) call nameMatchRank
        // so they can never diverge.
        searchTokens(s) {
            return String(s || '').toLowerCase().split(/[^a-z0-9\u0080-\uffff]+/).filter(Boolean);
        },
        // Rank a product name against the typed query. 0 = no match; higher =
        // better (contiguous/in-order matches sort above scattered-word ones):
        //   4 = name starts with the raw query (exactly the old startsWith rule)
        //   3 = tokens match CONSECUTIVE name words in order
        //   2 = tokens match name words in order with gaps ("cheese half")
        //   1 = every token matches some word, but out of order
        // anyWord=false keeps the STRICT PREFIX rule (owner, 24 Jul 2026 — do NOT
        // loosen): the FIRST token must still match the very START of the name;
        // only the LATER tokens are free to prefix-match any later word. Single-
        // word queries therefore behave exactly as before in both modes.
        nameMatchRank(name, q, anyWord) {
            const lname = String(name).toLowerCase();
            if (lname.startsWith(q)) return 4;
            const tokens = this.searchTokens(q);
            if (!tokens.length) return 0;
            if (!anyWord && !lname.startsWith(tokens[0])) return 0;
            const words = this.searchTokens(lname);
            for (let s = 0; s + tokens.length <= words.length; s++) {
                if (tokens.every((t, k) => words[s + k].startsWith(t))) return 3;
            }
            let wi = 0, inOrder = true;
            for (const t of tokens) {
                while (wi < words.length && !words[wi].startsWith(t)) wi++;
                if (wi >= words.length) { inOrder = false; break; }
                wi++;
            }
            if (inOrder) return 2;
            // Scattered: every token prefix-matches a DISTINCT word (longest tokens
            // claim first) — two tokens must never both count the same word, or
            // "chicken ch" would drag "Chicken Roll" into the results.
            const used = new Array(words.length).fill(false);
            const ok = [...tokens].sort((a, b) => b.length - a.length).every(t => {
                for (let j = 0; j < words.length; j++) {
                    if (!used[j] && words[j].startsWith(t)) { used[j] = true; return true; }
                }
                return false;
            });
            return ok ? 1 : 0;
        },
        filterProducts() {
            // MASTER "show saved products" toggle — when OFF, the catalog grid is hidden so
            // cashiers bill via manual entry only (type a name → "Create X" quick-add).
            // EXCEPTION: when the cashier is actively searching, still surface matching saved
            // products IN THE GRID — the grid stays hidden by default, but a search query
            // reveals matches so they can be tapped/added. Only stay empty when the grid is
            // OFF *and* the search box is empty.
            const hasSearch = !!(this.searchQuery && this.searchQuery.trim().length > 0);
            if (!this.showProducts && !hasSearch) {
                this.filteredItems = []; this.displayCount = 60; this.updateDisplayItems(); return;
            }
            let items = [...this.allProducts, ...this.allServices];
            items = items.filter(i => parseFloat(i.price) > 0 && i.name && i.name.trim().length > 0);
            // CATEGORY: the dropdown next to the search box is ALWAYS visible (unlike the pills),
            // so a chosen category is never an invisible/stale filter — search now deliberately
            // narrows to it ("All Categories" = whole catalog, old behavior). Search still includes
            // products marked "Hidden from sale screen" (show_on_sale=false) within that scope —
            // the hidden flag ONLY declutters the browsable grid, it must never stop a cashier
            // from finding a saved product by name.
            if (this.activeCategory !== 'all' && this.activeCategory !== 'services') { items = this.allProducts.filter(p => p.category === this.activeCategory && parseFloat(p.price) > 0 && p.name && p.name.trim().length > 0); }
            else if (this.activeCategory === 'services') { items = this.allServices.filter(s => parseFloat(s.price) > 0 && s.name && s.name.trim().length > 0); }
            // Task 1271 (PRA port): hidden items stay OUT of the browsable grid via the
            // per-user pref map (isItemVisible — user pref overrides admin show_on_sale in
            // BOTH directions). In GRID EDIT mode, ALL items render (hidden ones dimmed)
            // so the user can re-show them. Search is NEVER filtered by prefs.
            if (!hasSearch) {
                if (!this.gridEditMode) items = items.filter(i => this.isItemVisible(i));
            }
            if (this.searchQuery) {
                const q = this.searchQuery.toLowerCase().trim();
                // Task 1271: shared matcher with the dropdown (nameMatchRank) — the two
                // surfaces must never diverge. anyWord honors the admin search-mode pref.
                items = items
                    .map(i => ({ i, r: this.nameMatchRank(i.name, q, this.searchAnyWord) }))
                    .filter(x => x.r > 0)
                    .sort((a, b) => b.r - a.r)
                    .map(x => x.i);
            }
            this.filteredItems = items;
            this.displayCount = 60;
            this.updateDisplayItems();
        },

        updateDisplayItems() {
            this.displayItems = this.filteredItems.slice(0, this.displayCount);
        },

        // Distinct product categories for the header dropdown — client-side so quick-created
        // products ("Quick" category) appear without a reload. Mirrors the server pills list.
        catOptions() {
            return [...new Set(this.allProducts.map(p => p.category).filter(Boolean))].sort();
        },

        // MASTER toggle — show/hide the saved products catalog on the sale screen.
        // Persisted per-browser (localStorage). When OFF, cashiers bill via manual entry only.
        toggleShowProducts() {
            // Inventory mode requires the catalog — toggle is a no-op (force ON) there.
            this.showProducts = !this.showProducts;
            try { localStorage.setItem('fbr_show_products', this.showProducts ? '1' : '0'); } catch (e) {}
            // Grid OFF hides the pills — and on <sm screens the category dropdown is hidden too,
            // so a previously-picked category would become an INVISIBLE search filter. Reset to
            // 'all' (desktop can simply re-pick from the always-visible dropdown).
            if (!this.showProducts && this.activeCategory !== 'all') this.activeCategory = 'all';
            this.filterProducts();
            // Search still works when the grid is hidden — keep suggestions live if a query is active.
            if (this.searchQuery && this.searchQuery.trim().length > 0) { this.onSearchInput(); }
            else { this.searchSuggestions = []; this.showSearchDropdown = false; }
        },

        // Empty-state "Show All Products" rescue — ALSO turns the products grid back ON
        // (persisted), so a cashier who accidentally hit "Products OFF" is never stuck
        // staring at an empty grid with a button that does nothing (Frost & Brew, Jul 2026).
        restoreProductGrid() {
            this.showProducts = true;
            try { localStorage.setItem('fbr_show_products', '1'); } catch (e) {}
            this.activeCategory = 'all';
            this.searchQuery = '';
            this.filterProducts();
        },

        loadMore() {
            this.displayCount += 40;
            this.updateDisplayItems();
        },

        addToCart(item) {
            const existing = this.cart.find(c => c.item_id === item.id && c.item_type === item.type);
            if (existing) {
                existing.quantity++;
                this.activeCartIndex = this.cart.indexOf(existing);
                this.animateQty(this.activeCartIndex);
            } else {
                // FBR: hydrate compliance fields (hs_code / uom / tax_rate / exemption) from
                // the master catalog record — some callers (Quick Type, Random, grid picks)
                // pass minimal {id,type,name,price} objects. Services hydrate from allServices
                // (Task 1272): a 5% service must NEVER fall back to the 18% product default.
                const src = (item.type === 'product' && item.id) ? this.allProducts.find(p => p.id === item.id)
                    : ((item.type === 'service' && item.id) ? this.allServices.find(s => s.id === item.id) : null);
                const srcExempt = (item.is_tax_exempt !== undefined && item.is_tax_exempt !== null) ? !!item.is_tax_exempt : !!(src && src.is_tax_exempt);
                const rate = (item.tax_rate === 0 || item.tax_rate) ? parseFloat(item.tax_rate)
                    : ((src && (src.tax_rate === 0 || src.tax_rate)) ? parseFloat(src.tax_rate) : 18);
                this.cart.push({ cart_uid: 'c' + Date.now() + '_' + Math.random().toString(36).slice(2,9), item_id: item.id, item_type: item.type, item_name: item.name, quantity: 1, unit_price: parseFloat(item.price), special_notes: '', is_tax_exempt: (srcExempt || item.is_third_schedule) || false, is_third_schedule: item.is_third_schedule || false, hs_code: item.hs_code ?? (src ? src.hs_code : null) ?? null, uom: item.uom ?? (src ? src.uom : null) ?? 'U', tax_rate: (item.is_third_schedule || srcExempt) ? 0 : rate, item_discount_type: 'percentage', item_discount_value: 0, showItemDiscount: false, showFbrPanel: false });
                this.activeCartIndex = this.cart.length - 1;
            }
            this.cartAnimating = true; setTimeout(() => this.cartAnimating = false, 300);
            this.scrollToCartItem(this.activeCartIndex);
            // Smart Upsell — fire-and-forget; never blocks add flow.
            try { this.triggerUpsell(item); } catch (e) { /* upsell must never break add */ }
        },

        // ──────────────────────────────────────────────────────────────
        // QUICK TYPE MODE — type freeform "chai 2, samosa 1" → cart.
        // Parser supports: "name qty", "qty name", "name" (qty=1).
        // Separators: comma, semicolon, OR newline. Fuzzy product match
        // by case-insensitive substring on this.products[].name.
        // ──────────────────────────────────────────────────────────────
        openQuickType() {
            this.showQuickType = true;
            this.parseQuickTypeText();
        },

        // ──────────────────────────────────────────────────────────────
        // MANUAL ITEM — Simple-Mode (inventory-OFF) ad-hoc cart entry.
        // Backend storeInvoice() only validates name/qty/unit_price per
        // item, so a synthetic line with item_id=null bills cleanly.
        // If "save permanently" is ticked, we POST to apiQuickCreate
        // first, then add the freshly-created product to allProducts +
        // cart (so it appears in next searches too).
        // Inventory-ON companies: button is hidden in toolbar AND server
        // also blocks apiQuickCreate (returns 422). Defence in depth.
        // ──────────────────────────────────────────────────────────────
        openManualItem() {
            if (this.isInventoryEnabled()) {
                window.tnNotify && window.tnNotify(window.TXT.manual_item, window.TXT.not_allowed_inventory_mode);
                return;
            }
            this.manualItemName = '';
            this.manualItemPrice = '';
            this.manualItemSavePermanent = false;
            this.manualItemSubmitting = false;
            this.showManualItem = true;
            this.$nextTick(() => {
                const el = document.getElementById('manualItemNameInput');
                if (el) el.focus();
            });
        },
        async addManualItem() {
            const name = (this.manualItemName || '').trim();
            const priceRaw = (this.manualItemPrice || '').toString().trim();
            const price = parseFloat(priceRaw);
            if (!name) {
                window.tnNotify && window.tnNotify(window.TXT.manual_item, window.TXT.name_required_dot);
                return;
            }
            if (priceRaw === '' || isNaN(price) || price < 0) {
                window.tnNotify && window.tnNotify(window.TXT.manual_item, window.TXT.valid_price_dot);
                return;
            }
            if (this.manualItemSubmitting) return;
            this.manualItemSubmitting = true;
            try {
                if (this.manualItemSavePermanent) {
                    const res = await fetch('{{ route("fbrpos.api.products.quick-create", [], false) }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ name, price }),
                    });
                    const json = await res.json().catch(() => ({}));
                    if (!res.ok || !json.ok) {
                        throw new Error(json.error || ('Save failed (' + res.status + ')'));
                    }
                    const p = json.product || {};
                    // Mirror the existing allProducts shape so search/grid/Quick Type pick it up.
                    this.allProducts.push({
                        id: p.id, name: p.name, price: parseFloat(p.price) || 0,
                        category: p.category || 'Quick', type: 'product', image: null,
                        is_tax_exempt: false, is_third_schedule: false, hasRecipe: false, stockStatus: null,
                    });
                    this.addToCart({
                        id: p.id, type: 'product', name: p.name,
                        price: parseFloat(p.price) || 0, is_tax_exempt: false, is_third_schedule: false,
                    });
                    window.tnNotify && window.tnNotify(window.TXT.saved_added, p.name);
                } else {
                    // One-time line — no DB write. Backend accepts item_id=null.
                    this.cart.push({
                        cart_uid: 'm' + Date.now() + '_' + Math.random().toString(36).slice(2,9),
                        item_id: null,
                        item_type: 'manual',
                        item_name: name,
                        quantity: 1,
                        unit_price: price,
                        special_notes: '',
                        is_tax_exempt: false,
                        is_third_schedule: false,
                        item_discount_type: 'percentage',
                        item_discount_value: 0,
                        showItemDiscount: false,
                    });
                    window.tnNotify && window.tnNotify(window.TXT.manual_added, name + ' — Rs. ' + price.toLocaleString());
                }
                this.showManualItem = false;
                this.manualItemName = '';
                this.manualItemPrice = '';
                this.manualItemSavePermanent = false;
            } catch (e) {
                window.tnNotify && window.tnNotify(window.TXT.error_word, e.message || window.TXT.save_failed);
            } finally {
                this.manualItemSubmitting = false;
            }
        },
        // Build a flat searchable pool: products + services, each tagged with its type.
        // Cached per-call (cheap) so we always reflect newly-added master items.
        quickTypePool() {
            const products = (this.allProducts || []).filter(p => p && p.name).map(p => ({ ...p, _type: 'product' }));
            const services = (this.allServices || []).filter(s => s && s.name).map(s => ({ ...s, _type: 'service' }));
            return [...products, ...services];
        },
        parseQuickTypeText() {
            // Preserve any manual prices the cashier already typed for unmatched
            // entries so re-parsing on every keystroke doesn't wipe their input.
            // Keyed by LINE INDEX (not raw text) — this is duplicate-safe: two
            // unmatched lines with the same name keep distinct prices.
            const prevManual = (this.quickTypeParsed || []).map(p =>
                (!p.match && p.manualPrice != null && p.manualPrice !== '') ? p.manualPrice : ''
            );
            const lines = (this.quickTypeText || '').split(/[,;\n]+/).map(s => s.trim()).filter(Boolean);
            const pool = this.quickTypePool();
            this.quickTypeParsed = lines.map((raw, idx) => {
                // Pull out the qty: number at start OR end. Default 1.
                let qty = 1;
                let name = raw;
                const mEnd = raw.match(/^(.*?)\s+(\d{1,3})$/);
                const mStart = raw.match(/^(\d{1,3})\s+(.+)$/);
                if (mEnd) { name = mEnd[1].trim(); qty = parseInt(mEnd[2], 10); }
                else if (mStart) { qty = parseInt(mStart[1], 10); name = mStart[2].trim(); }
                if (!Number.isFinite(qty) || qty < 1) qty = 1;
                if (qty > 999) qty = 999;
                // Fuzzy match against pool: exact > startsWith > includes > word-prefix on any token
                const needle = name.toLowerCase();
                let match = pool.find(p => p.name.toLowerCase() === needle)
                         || pool.find(p => p.name.toLowerCase().startsWith(needle))
                         || pool.find(p => p.name.toLowerCase().includes(needle))
                         || pool.find(p => p.name.toLowerCase().split(/\s+/).some(t => t.startsWith(needle)));
                const entry = { raw: name, qty, match: match || null, manualPrice: '' };
                if (!entry.match && prevManual[idx] !== undefined && prevManual[idx] !== '') {
                    entry.manualPrice = prevManual[idx];
                }
                return entry;
            });
        },
        applyQuickType() {
            const inv = this.isInventoryEnabled();
            const matched = this.quickTypeParsed.filter(p => p.match);
            // Unmatched lines with a typed price become manual cart lines —
            // inventory-OFF only, mirroring the "+ Manual" button restriction.
            const manualEntries = !inv
                ? this.quickTypeParsed.filter(p => !p.match && parseFloat(p.manualPrice) > 0)
                : [];
            if (matched.length === 0 && manualEntries.length === 0) {
                this.showToast(inv ? 'No items matched — check spelling' : window.TXT.quick_type_fill_prices, 'error');
                return;
            }
            let added = 0, skipped = 0, manualAdded = 0;
            matched.forEach(p => {
                // Honour the same stock-out gate as handleProductClick — Quick Type
                // must NOT bypass blockOutOfStock on inventory-enabled companies.
                if (inv && p.match.stockStatus === 'out' && this.blockOutOfStock) { skipped++; return; }
                const item = { id: p.match.id, type: p.match._type || p.match.type || 'product', name: p.match.name, price: p.match.price, is_tax_exempt: (p.match.is_tax_exempt || p.match.is_third_schedule) || false, is_third_schedule: p.match.is_third_schedule || false };
                for (let i = 0; i < p.qty; i++) { this.addToCart(item); added++; }
            });
            // Push synthetic manual lines (no DB write — backend storeInvoice()
            // accepts item_id=null lines as long as name/qty/unit_price are set).
            // ONE line per parsed entry — qty goes into the line's quantity field
            // so "Burger 3" becomes a single line with qty 3 (NOT three lines
            // of qty 1). Matches the cashier's mental model and the cart's
            // line-grouping for matched products.
            manualEntries.forEach(p => {
                const price = parseFloat(p.manualPrice);
                this.cart.push({
                    cart_uid: 'm' + Date.now() + '_' + Math.random().toString(36).slice(2,9),
                    item_id: null,
                    item_type: 'manual',
                    item_name: p.raw,
                    quantity: p.qty,
                    unit_price: price,
                    special_notes: '',
                    is_tax_exempt: false,
                    item_discount_type: 'percentage',
                    item_discount_value: 0,
                    showItemDiscount: false,
                });
                manualAdded++;
                this.activeCartIndex = this.cart.length - 1;
            });
            const total = added + manualAdded;
            if (total > 0) {
                let msg = `Added ${total} item(s)`;
                if (manualAdded > 0) msg += ` (${manualAdded} manual)`;
                if (skipped) msg += `, skipped ${skipped} out-of-stock`;
                this.showToast(msg, 'success');
                this.cartAnimating = true; setTimeout(() => this.cartAnimating = false, 300);
            } else {
                this.showToast(window.TXT.all_matched_out_of_stock, 'error');
            }
            this.showQuickType = false;
            this.quickTypeText = '';
            this.quickTypeParsed = [];
        },
        addRandomProduct() {
            const inv = this.isInventoryEnabled();
            // Filter out: inactive, zero-price, AND (when inventory blocking is on) out-of-stock items.
            const pool = this.quickTypePool().filter(p => {
                if (p.is_active === false) return false;
                if (!(parseFloat(p.price) > 0)) return false;
                // "Hidden from sale screen" products only surface on explicit search — never via the random picker.
                if ((p._type || p.type) === 'product' && p.show_on_sale === false) return false;
                if (inv && p.stockStatus === 'out' && this.blockOutOfStock) return false;
                return true;
            });
            if (pool.length === 0) { this.showToast(window.TXT.no_products_available, 'error'); return; }
            const pick = pool[Math.floor(Math.random() * pool.length)];
            this.addToCart({ id: pick.id, type: pick._type || pick.type || 'product', name: pick.name, price: pick.price, is_tax_exempt: pick.is_tax_exempt || pick.is_third_schedule || false, is_third_schedule: pick.is_third_schedule || false });
            this.showToast(window.TXT.random_prefix + pick.name, 'success');
        },

        // ──────────────────────────────────────────────────────────────
        // SMART UPSELL SYSTEM — purely client-side, zero backend impact.
        // - Keyword-based mapping (Burger → Fries/Drink, etc.)
        // - One suggestion at a time (no spam)
        // - Session memory: dismissed pairs don't re-show until page reload
        // - Enter = accept, Esc = dismiss (handled in keyboard router)
        // ──────────────────────────────────────────────────────────────
        upsellRules: {
            // keyword in product name → candidate keywords to suggest
            'burger':   ['fries', 'cola', 'drink', 'pepsi', 'coke'],
            'pizza':    ['garlic', 'cola', 'drink', 'pepsi'],
            'biryani':  ['raita', 'salad', 'drink', 'cola'],
            'karahi':   ['naan', 'roti', 'salad', 'drink'],
            'shawarma': ['fries', 'drink', 'cola'],
            'sandwich': ['fries', 'drink', 'chips'],
            'roll':     ['fries', 'drink', 'chips'],
            'broast':   ['fries', 'drink', 'cola'],
            'chicken':  ['fries', 'drink', 'roti', 'naan'],
            'steak':    ['fries', 'drink', 'sauce'],
            'pasta':    ['drink', 'garlic', 'salad'],
            'coffee':   ['cookie', 'cake', 'muffin'],
            'tea':      ['biscuit', 'cookie', 'cake'],
            'fries':    ['cola', 'drink', 'pepsi', 'coke'],
            'cake':     ['coffee', 'tea', 'drink'],
        },
        currentUpsell: null,           // { trigger:{id,name}, suggest:{id,name,price,...} }
        dismissedUpsells: [],          // ['triggerId:suggestId', ...] — session-only
        triggerUpsell(triggerItem) {
            if (!triggerItem || !triggerItem.name) return;
            const tname = String(triggerItem.name).toLowerCase();
            // Find first matching rule key
            const ruleKey = Object.keys(this.upsellRules).find(k => tname.includes(k));
            if (!ruleKey) return;
            const candidates = this.upsellRules[ruleKey];
            // Search allProducts pool for first candidate not already in cart and not dismissed
            for (const kw of candidates) {
                const match = (this.allProducts || []).find(p => {
                    if (!p || !p.name) return false;
                    if (!p.name.toLowerCase().includes(kw)) return false;
                    // Skip if same product as trigger
                    if (p.id === triggerItem.id && (p.type || 'product') === (triggerItem.type || 'product')) return false;
                    // Skip if already in cart
                    const inCart = this.cart.some(c => c.item_id === p.id && c.item_type === (p.type || 'product'));
                    if (inCart) return false;
                    // Skip if previously dismissed for this trigger this session
                    const pairKey = triggerItem.id + ':' + p.id;
                    if (this.dismissedUpsells.includes(pairKey)) return false;
                    return true;
                });
                if (match) {
                    this.currentUpsell = {
                        trigger: { id: triggerItem.id, name: triggerItem.name },
                        suggest: { id: match.id, type: match.type || 'product', name: match.name, price: match.price, is_tax_exempt: (match.is_tax_exempt || match.is_third_schedule) || false, is_third_schedule: match.is_third_schedule || false }
                    };
                    // Auto-dismiss after 8s if cashier ignores it (no spam build-up)
                    if (this._upsellTimer) clearTimeout(this._upsellTimer);
                    this._upsellTimer = setTimeout(() => { this.dismissUpsell(false); }, 8000);
                    return;
                }
            }
        },
        acceptUpsell() {
            if (!this.currentUpsell) return;
            const s = this.currentUpsell.suggest;
            // Mark as accepted (still record so it won't re-fire same pair)
            this.dismissedUpsells.push(this.currentUpsell.trigger.id + ':' + s.id);
            this.currentUpsell = null;
            if (this._upsellTimer) { clearTimeout(this._upsellTimer); this._upsellTimer = null; }
            // Add the suggested item — reuse existing addToCart (will skip its own upsell since item already in cart)
            this.addToCart({ id: s.id, type: s.type, name: s.name, price: s.price, is_tax_exempt: s.is_tax_exempt || s.is_third_schedule || false, is_third_schedule: s.is_third_schedule || false });
            this.showToast(window.TXT.added_prefix + s.name, 'success');
        },
        dismissUpsell(record = true) {
            if (!this.currentUpsell) return;
            if (record) {
                this.dismissedUpsells.push(this.currentUpsell.trigger.id + ':' + this.currentUpsell.suggest.id);
            }
            this.currentUpsell = null;
            if (this._upsellTimer) { clearTimeout(this._upsellTimer); this._upsellTimer = null; }
        },
        _upsellTimer: null,

        // ──────────────────────────────────────────────────────────────
        // SMART PRODUCT CREATION — Simple POS quick-create + inline price editor.
        // - Only fires when isInventoryEnabled() is FALSE.
        // - Backend route /pos/api/products/quick-create has its own server-side
        //   guard (refuses 422 when inventory is on) — defense in depth.
        // - Inventory ON path shows "Open Products" link instead.
        // ──────────────────────────────────────────────────────────────
        quickCreating: false,       // spinner flag while the create POST is in flight
        // QUICK-CREATE MODAL (Aug 2026, owner request): unknown items now ask for FULL
        // details (name, price, UoM, tax, HS code, barcode) instead of instantly creating
        // a Rs.0 product. A scanned NUMERIC code pre-fills only the BARCODE field — the
        // digits can never become a product name.
        qcModal: false,
        qcSaving: false,
        qcFromScan: false,
        qcExistingId: null,   // set = popup is EDITING an existing unpriced product (update, not create)
        qcName: '', qcBarcode: '', qcPrice: '', qcUom: 'U', qcTaxMode: 'exempt', qcTaxRate: '', qcHsCode: '',
        quickPriceCartUid: null,    // cart_uid of row currently in price-edit mode
        quickPriceValue: '',        // bound to the inline price input
        quickCreateProduct() {
            if (this.isInventoryEnabled()) return; // belt + suspenders
            const typed = (this.searchQuery || '').trim();
            if (!typed || this.qcModal || this.qcSaving) return;
            // Kill any in-flight debounced search NOW: searchQuery stays set while the modal
            // is open, so the pending 60ms callback would otherwise fire and (via its exact-
            // barcode auto-add) add an item behind the modal (Aug 2026 double-add family).
            if (this._searchDebounceTimer) clearTimeout(this._searchDebounceTimer);
            // ZERO-PRICE BARCODE RESCUE: the Enter fast path only takes price>0 matches,
            // so an exact barcode/SKU hit at ANY price must never fall through to the
            // create-fresh path. Priced hit = add instantly. Unpriced PRODUCT hit = open the
            // FULL popup prefilled (owner, Aug 2026: "cart mein jaakar nahi" — details modal,
            // not the in-cart editor); item joins the cart only after Save. Services keep the
            // old inline-editor path (quick-create update covers products only).
            const codeHit = [...this.allProducts, ...this.allServices].find(it => this.isExactCodeMatch(it, typed.toLowerCase()));
            if (codeHit) {
                if (parseFloat(codeHit.price) > 0 || (codeHit.type || 'product') !== 'product') {
                    this.quickAddItem(codeHit);
                    if (!(parseFloat(codeHit.price) > 0)) {
                        const row = [...this.cart].reverse().find(c => c.item_id === codeHit.id && c.item_type === (codeHit.type || 'product'));
                        if (row) this.openQuickPrice(row);
                    }
                    return;
                }
                this.qcOpenForExisting(codeHit);
                return;
            }
            this.qcExistingId = null;
            // A numeric code (6+ digits) is a BARCODE, never a product name.
            this.qcFromScan = /^[0-9]{6,}$/.test(typed);
            this.qcName = this.qcFromScan ? '' : typed;
            this.qcBarcode = this.qcFromScan ? typed : '';
            this.qcPrice = ''; this.qcUom = 'U'; this.qcTaxMode = 'exempt'; this.qcTaxRate = ''; this.qcHsCode = '';
            this.showSearchDropdown = false;
            this.qcModal = true;
            // Focus the missing piece: scanned code needs a NAME first, typed name needs a PRICE.
            // Root-scope $refs don't reach x-if/x-for subtrees reliably — use getElementById.
            this.$nextTick(() => {
                const el = document.getElementById(this.qcFromScan ? 'qc-name-input' : 'qc-price-input');
                if (el) el.focus();
            });
        },
        // Open the SAME full-details popup for an EXISTING unpriced catalog product —
        // prefilled with everything we already know; Save UPDATES the product server-side
        // (price + missing details) and only then adds it to the cart. Cancel = no cart change.
        qcOpenForExisting(prod) {
            if (this.qcModal || this.qcSaving) return;
            if (this._searchDebounceTimer) clearTimeout(this._searchDebounceTimer);
            this.qcExistingId = prod.id;
            this.qcFromScan = false;
            this.qcName = prod.name || '';
            this.qcBarcode = prod.barcode || '';
            this.qcPrice = parseFloat(prod.price) > 0 ? prod.price : '';
            this.qcUom = prod.uom || 'U';
            const tr = parseFloat(prod.tax_rate);
            this.qcTaxMode = prod.is_tax_exempt ? 'exempt' : ((isNaN(tr) || tr === 18) ? 'standard' : 'custom');
            this.qcTaxRate = this.qcTaxMode === 'custom' ? tr : '';
            this.qcHsCode = prod.hs_code || '';
            this.showSearchDropdown = false;
            this.qcModal = true;
            this.$nextTick(() => { const el = document.getElementById('qc-price-input'); if (el) el.focus(); });
        },
        qcCancel() {
            this.qcModal = false;
            this.qcExistingId = null;
            this.searchQuery = '';
            this.searchSuggestions = [];
            this.showSearchDropdown = false;
            this.filterProducts();
            this.$nextTick(() => { const s = document.querySelector('input[name=pos_product_search_nofill]'); if (s) s.focus(); });
        },
        async qcSave() {
            if (this.qcSaving) return;
            const name = (this.qcName || '').trim();
            if (!name) {
                this.showToast(window.TXT.qc_name_required, 'error');
                const el = document.getElementById('qc-name-input'); if (el) el.focus();
                return;
            }
            const priceNum = parseFloat(this.qcPrice);
            if (this.qcPrice === '' || isNaN(priceNum) || priceNum < 0) {
                this.showToast(window.TXT.qc_price_required, 'error');
                const el = document.getElementById('qc-price-input'); if (el) el.focus();
                return;
            }
            this.qcSaving = true;
            this.quickCreating = true;
            try {
                // Relative URL on purpose: route() emits an absolute https URL (forceScheme)
                // which breaks in plain-http dev/test browsing; same-origin relative works everywhere.
                const res = await fetch('{{ route('fbrpos.api.products.quick-create', [], false) }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({
                        name,
                        price: priceNum,
                        barcode: (this.qcBarcode || '').trim() || null,
                        uom: this.qcUom || 'U',
                        tax_mode: this.qcTaxMode,
                        tax_rate: this.qcTaxMode === 'custom' ? (parseFloat(this.qcTaxRate) || 0) : null,
                        hs_code: (this.qcHsCode || '').trim() || null,
                        existing_id: this.qcExistingId,
                    }),
                });
                const data = await res.json();
                if (!res.ok || !data.ok) {
                    this.showToast(data.error || window.TXT.could_not_create, 'error');
                    return;
                }
                const p = data.product;
                // Server may DEDUPE (same name OR same barcode already exists) — never push a
                // twin entry into the local catalog or the duplicate guard stops finding it.
                // Existing entry: MERGE the returned fields (price may have just been set via
                // the edit-mode popup — a stale Rs.0 here would reopen the popup on every scan).
                const qcIdx = this.allProducts.findIndex(x => x.id === p.id);
                if (qcIdx === -1) this.allProducts.push(p);
                else this.allProducts[qcIdx] = { ...this.allProducts[qcIdx], ...p };
                const pPrice = parseFloat(p.price) || 0;
                this.addToCart({ id: p.id, type: 'product', name: p.name, price: pPrice, is_tax_exempt: !!(p.is_tax_exempt || p.is_third_schedule), is_third_schedule: !!p.is_third_schedule, tax_rate: p.is_third_schedule ? 0 : p.tax_rate, hs_code: p.hs_code, uom: p.uom });
                // Zero-price row (deliberate Rs.0, or dedupe returned an unpriced product):
                // still offer the inline price editor on the actual cart row (dedupe of an
                // item already in cart increments qty — the LAST row may not be it).
                if (pPrice <= 0) {
                    const row = [...this.cart].reverse().find(c => c.item_id === p.id && c.item_type === 'product');
                    if (row) {
                        row._isQuickCreated = true;
                        row._productId = p.id;
                        this.openQuickPrice(row);
                    }
                }
                // GUIDED FLOW (opt-in): first quick-created item moves the indicator off "customer".
                if (this.guidedFlow && this.flowStep === 'customer') this.flowStep = 'items';
                this.qcModal = false;
                this.qcExistingId = null;
                this.searchQuery = '';
                this.searchSuggestions = [];
                this.showSearchDropdown = false;
                this.filterProducts();
                this.$nextTick(() => { const s = document.querySelector('input[name=pos_product_search_nofill]'); if (s) s.focus(); });
            } catch (e) {
                this.showToast(window.TXT.network_error, 'error');
            } finally {
                this.qcSaving = false;
                this.quickCreating = false;
            }
        },
        openQuickPrice(cartItem) {
            this.quickPriceCartUid = cartItem.cart_uid;
            this.quickPriceValue = cartItem.unit_price > 0 ? cartItem.unit_price : '';
            // CRITICAL: the inline price <input> lives inside the cart x-for ROW, so its
            // x-ref is NOT reachable from this component-root scope (quickCreateProduct
            // calls this from root) — see alpine-xfor-refs-scope.md. A this.$refs focus
            // here silently no-ops, leaving focus on the search box: the cashier types the
            // price into search and the whole keyboard chain dies at the first item.
            // Focus the live node by attribute instead, with timed retries to win the
            // x-if render race (the input only mounts once quickPriceCartUid is set).
            const focusPrice = () => {
                const el = document.querySelector('[data-quick-price-input]');
                if (el) { el.focus(); el.select && el.select(); }
            };
            this.$nextTick(focusPrice);
            setTimeout(focusPrice, 0);
            setTimeout(focusPrice, 60);
        },
        cancelQuickPrice() {
            this.quickPriceCartUid = null;
            this.quickPriceValue = '';
        },
        async saveQuickPrice(cartIndex, refocusSearch = false) {
            // Guard: only save when this row is the one being edited, prevents double-fire on blur.
            const item = this.cart[cartIndex];
            if (!item || this.quickPriceCartUid !== item.cart_uid) return;
            const newPrice = parseFloat(this.quickPriceValue);
            if (!Number.isFinite(newPrice) || newPrice < 0) {
                this.cancelQuickPrice();
                return;
            }
            // Optimistic local update
            item.unit_price = newPrice;
            const productId = item._productId || item.item_id;
            const masterIdx = this.allProducts.findIndex(p => p.id === productId);
            if (masterIdx >= 0) this.allProducts[masterIdx].price = newPrice;
            this.cancelQuickPrice();
            // GUIDED FLOW (opt-in): committing the price via Enter returns focus to the
            // search box so the cashier can immediately type the next item (or press Enter
            // on the empty box to drop into the cart). Without this the keyboard chain
            // stalls after every quick-created item in inventory-OFF mode.
            //
            // IMPORTANT — two traps handled here:
            //  1. saveQuickPrice is invoked from the cart x-for ROW scope (the inline price
            //     input's @keydown.enter). In Alpine, $refs read through a row-scope proxy
            //     only sees that row's refs (quickPriceInput) — the component-root
            //     searchInput is NOT reachable via this.$refs here. So we locate the live
            //     search node directly in the DOM by its name attribute.
            //  2. Clearing quickPriceCartUid removes the focused inline price <input> via
            //     x-if, which natively blurs to <body>. A couple of timed attempts ensure
            //     our focus wins that teardown race.
            if (refocusSearch && this.guidedFlow) {
                const refocusSearchBox = () => {
                    // Never steal focus while the Order-Type overlay is open — a delayed
                    // refocus landing under the overlay traps Enter inside the search box.
                    if (this.flowStep === 'type') return;
                    const el = document.querySelector('input[name="pos_product_search_nofill"]');
                    if (el) el.focus();
                };
                this.$nextTick(refocusSearchBox);
                setTimeout(refocusSearchBox, 0);
                setTimeout(refocusSearchBox, 60);
            }
            // Persist to backend (silent — already reflected in UI)
            try {
                await fetch(`/fbr-pos/api/products/${productId}/quick-price`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ price: newPrice }),
                });
            } catch (e) { /* non-fatal — UI already updated */ }
        },
        updateQty(index, delta) {
            if (!this.cart[index]) return;
            const min = this._qtyMin(this.cart[index]);
            let current = Number(this.cart[index].quantity);
            if (!Number.isFinite(current) || current < min) current = min;
            let next = Number.isInteger(current)
                ? Math.max(min, current + delta)
                : Math.max(min, Math.round((current + delta) * 100) / 100);
            if (!Number.isFinite(next) || next < min) next = min;
            this.cart[index].quantity = next;
        },
        setQty(index, val) {
            if (!this.cart[index]) return;
            const min = this._qtyMin(this.cart[index]);
            let v = parseFloat(val);
            if (!Number.isFinite(v) || v < min) v = min;
            this.cart[index].quantity = v;
        },
        removeFromCart(index) {
            if (index < 0 || index >= this.cart.length) return;
            this.cart.splice(index, 1);
            this.fixCartIndex();
        },

        enterCartMode(startAt) {
            if (this.cart.length === 0) return;
            this.cartMode = true;
            this.gridFocusMode = false;
            if (startAt === 'last') this.activeCartIndex = this.cart.length - 1;
            else if (typeof startAt === 'number') this.activeCartIndex = Math.max(0, Math.min(startAt, this.cart.length - 1));
            else this.activeCartIndex = 0;
            this.scrollToCartItem(this.activeCartIndex);
            this.focusActiveQty();
        },

        exitCartMode() {
            this.cartMode = false;
            this.activeCartIndex = -1;
            this.$nextTick(() => { this.$refs.searchInput?.focus(); });
        },

        moveCartSelection(dir) {
            if (this.cart.length === 0) { this.activeCartIndex = -1; this.cartMode = false; return; }
            let next = (this.activeCartIndex < 0 ? 0 : this.activeCartIndex) + dir;
            next = Math.max(0, Math.min(next, this.cart.length - 1));
            if (next === this.activeCartIndex) {
                this.focusActiveQty();
                return;
            }
            this.activeCartIndex = next;
            this.scrollToCartItem(next);
            this.focusActiveQty();
        },

        focusActiveQty() {
            this.$nextTick(() => {
                const el = this.$refs.cartList?.querySelector(`[data-cart-index="${this.activeCartIndex}"] [data-qty-input]`);
                if (el) { el.focus(); el.select(); }
            });
        },

        fixCartIndex() {
            if (this.cart.length === 0) { this.activeCartIndex = -1; this.cartMode = false; return; }
            if (this.activeCartIndex < 0) this.activeCartIndex = 0;
            if (this.activeCartIndex >= this.cart.length) this.activeCartIndex = this.cart.length - 1;
        },

        // T-key tax toggle — flips is_tax_exempt on the cart row at `index`.
        // Wired to: (1) qty input field, (2) cart-mode global, (3) search mode (toggles last row).
        // Shows a small toast so the cashier confirms the change happened off-screen.
        toggleItemTax(index) {
            if (index < 0 || index >= this.cart.length) return;
            const item = this.cart[index];
            item.is_tax_exempt = !item.is_tax_exempt;
            this.showToast(item.is_tax_exempt ? `NO TAX — ${item.item_name || item.name || 'item'}` : `TAX ON — ${item.item_name || item.name || 'item'}`, item.is_tax_exempt ? 'success' : 'info');
            this.animateQty(index);
        },

        // ═══════════════════════════════════════════════════════════════════
        // QTY INPUT — explicit method handlers (replaces inline @keydown/@input
        // expressions). Inline expression form was unreliable: Alpine 3 silently
        // dropped subsequent attribute handlers when one expression had a parse
        // edge case (e.g. unguarded `return;`). Method calls have zero parsing
        // risk and let us reach the right cart row via index lookup.
        // Pair this with x-effect (skips re-render while $el is focused) so the
        // reactive :value binding doesn't fight the user's keystrokes.
        // ═══════════════════════════════════════════════════════════════════
        selectCartRow(index) {
            if (index < 0 || index >= this.cart.length) return;
            this.activeCartIndex = index;
            this.cartMode = true;
        },
        onQtyFocus(index, e) {
            this.activeCartIndex = index;
            this.cartMode = true;
            const t = e.target;
            if (t) {
                t.dataset._fresh = '1';
                try { t.select(); } catch(_){}
            }
        },
        onQtyKeydown(index, e) {
            // T / t — toggle tax on this row
            if ((e.key === 't' || e.key === 'T') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                e.stopPropagation();
                this.toggleItemTax(index);
                return;
            }
            // First digit after focus — replace existing value (fresh-digit pattern)
            if (/^[0-9]$/.test(e.key) && !e.ctrlKey && !e.metaKey && !e.altKey && !e.shiftKey) {
                const t = e.target;
                if (t && t.dataset._fresh === '1') {
                    e.preventDefault();
                    t.value = e.key;
                    if (this.cart[index]) this.cart[index].quantity = e.key;
                    t.dataset._fresh = '0';
                    try { t.setSelectionRange(1, 1); } catch(_){}
                }
            }
        },
        onQtyInput(index, e) {
            const t = e.target;
            if (!t || !this.cart[index]) return;
            t.dataset._fresh = '0';
            let v = (t.value || '').replace(/[^0-9.]/g, '');
            const dot = v.indexOf('.');
            if (dot !== -1) v = v.slice(0, dot + 1) + v.slice(dot + 1).replace(/\./g, '');
            t.value = v;
            this.cart[index].quantity = v;
        },
        onQtyBlur(index, e) {
            const t = e.target;
            if (t) t.dataset._fresh = '0';
            if (!this.cart[index]) return;
            const min = this._qtyMin(this.cart[index]);
            let n = parseFloat(this.cart[index].quantity);
            if (!Number.isFinite(n) || n < min) n = min;
            this.cart[index].quantity = Number.isInteger(n) ? n : Math.round(n * 1000) / 1000;
        },

        handleKey(e) {
            // ═══════════════════════════════════════════════════════════════
            // Task 565 (mirror of PRA universal): PRINT-CONFIRM YES/NO DIALOG —
            // sab se TOPMOST; khula ho to keyboard SIRF isi ka hai. Enter =
            // highlighted choice (Yes default), Tab/arrows = Yes ↔ No, Esc/N =
            // No, Y = Yes. Baqi SAB keys swallow — band hote hi sab pehle jaisa.
            // stopPropagation: window-level escape listeners saath band na hon.
            // ═══════════════════════════════════════════════════════════════
            if (this.showPrintConfirm) {
                e.stopPropagation();
                if (e.key === 'Tab' || e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    this.printConfirmChoice = this.printConfirmChoice === 'yes' ? 'no' : 'yes';
                    try { (this.printConfirmChoice === 'yes' ? this.$refs.printConfirmYes : this.$refs.printConfirmNo)?.focus(); } catch (err) {}
                    return;
                }
                if (e.key === 'Enter' && !e.repeat) { e.preventDefault(); this.resolvePrintConfirm(this.printConfirmChoice === 'yes'); return; }
                if (e.key === 'Escape') { e.preventDefault(); this.resolvePrintConfirm(false); return; }
                if (e.key === 'y' || e.key === 'Y') { e.preventDefault(); this.resolvePrintConfirm(true); return; }
                if (e.key === 'n' || e.key === 'N') { e.preventDefault(); this.resolvePrintConfirm(false); return; }
                if (/^F\d+$/.test(e.key) || (e.key && e.key.length === 1) || ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'e'))) { e.preventDefault(); }
                return;
            }
            // ═══════════════════════════════════════════════════════════════
            // ZFC UNSENT-CART SWITCH PROMPT (mirror of PRA universal) — TOPMOST
            // modal, owns the keyboard while open. 1/2/arrows toggle the
            // highlight, Enter confirms, Esc cancels (old table stays; picker
            // stays open behind). Everything else is swallowed.
            // ═══════════════════════════════════════════════════════════════
            if (this.tableSwitchPrompt) {
                if (e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'ArrowUp' || e.key === 'ArrowDown' || e.key === 'Tab') { e.preventDefault(); this.tableSwitchIndex = this.tableSwitchIndex === 0 ? 1 : 0; return; }
                if (e.key === '1') { e.preventDefault(); this.tableSwitchIndex = 0; return; }
                if (e.key === '2') { e.preventDefault(); this.tableSwitchIndex = 1; return; }
                if (e.key === 'Enter' && !e.repeat) { e.preventDefault(); this.confirmTableSwitch(this.tableSwitchIndex === 1 ? 'discard' : 'move'); return; }
                if (e.key === 'Escape') { e.preventDefault(); this.tableSwitchPrompt = null; return; }
                if (/^F\d+$/.test(e.key) || ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'e'))) { e.preventDefault(); }
                return;
            }
            // ═══════════════════════════════════════════════════════════════
            // GUIDED FLOW: Order-Type step capture (dine in / takeaway / delivery).
            // Owner-specified step BETWEEN Items and Cart. Runs FIRST so the overlay
            // fully owns the keyboard: arrows/1-3 move the highlight, Enter confirms +
            // drops into the cart, Esc returns to product search. Returns unconditionally
            // (swallows every other key incl. F-keys) so nothing leaks to the screen
            // behind it. Gated on guidedFlow + flowStep so plain mode is byte-identical.
            // ═══════════════════════════════════════════════════════════════
            if (this.guidedFlow && this.flowStep === 'type') {
                const tlen = this.guidedOrderTypes().length || 1;
                if (e.key === 'ArrowDown' || e.key === 'ArrowRight') { e.preventDefault(); this.flowTypeIndex = (this.flowTypeIndex + 1) % tlen; return; }
                if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') { e.preventDefault(); this.flowTypeIndex = (this.flowTypeIndex - 1 + tlen) % tlen; return; }
                if (e.key === '1' && tlen >= 1) { e.preventDefault(); this.flowTypeIndex = 0; return; }
                if (e.key === '2' && tlen >= 2) { e.preventDefault(); this.flowTypeIndex = 1; return; }
                if (e.key === '3' && tlen >= 3) { e.preventDefault(); this.flowTypeIndex = 2; return; }
                if (e.key === 'Enter' && !e.repeat) { e.preventDefault(); this.confirmGuidedType(); return; }
                if (e.key === 'Escape') { e.preventDefault(); this.flowStep = 'items'; this.enterSearchMode(); return; }
                if (/^F\d+$/.test(e.key) || ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'e'))) { e.preventDefault(); }
                return;
            }
            // ═══════════════════════════════════════════════════════════════
            // GLOBAL FUNCTION-KEY SHORTCUTS — fire FIRST, regardless of focus.
            // Without this, search/qty inputs swallow F1-F8 (and F5 would even
            // reload the browser). preventDefault on document-level handler
            // also cancels the browser's native F-key behaviors.
            // ═══════════════════════════════════════════════════════════════
            // Quick Return modal open (Task 685): apna chhota input hai — Esc
            // band karta hai, Enter input ke apne handler par chalta hai; baqi
            // saare global shortcuts (F-keys / Alt chords) is par fire na hon.
            if (this.quickReturnOpen) {
                if (e.key === 'Escape') { e.preventDefault(); this.quickReturnOpen = false; }
                return;
            }
            if (e.key === 'F1') { e.preventDefault(); this.showShortcuts = !this.showShortcuts; return; }
            if (e.key === 'F2') { e.preventDefault(); this.cartMode = false; this.activeCartIndex = -1; this.enterSearchMode(); return; }
            if (e.key === 'F3') { e.preventDefault(); this.activeHeldIndex = 0; this.showHeldOrders = true; return; }
            if (e.key === 'F4') { e.preventDefault(); if (this.cart.length && confirm(window.TXT.clear_entire_cart)) { this.clearCart(); } return; }
            if (e.key === 'F5') { e.preventDefault(); this.holdOrder(); return; }
            if (e.key === 'F6') { e.preventDefault(); if (this.cart.length > 0) { this.enterCartMode('last'); this.mobileView = 'cart'; } return; }
            // F7 → Quick Type (was customer-phone-focus, moved to Alt+P)
            if (e.key === 'F7') { e.preventDefault(); this.openQuickType(); return; }
            if (e.key === 'F8') { e.preventDefault(); if (this.cart.length) { this.submitting = false; this.showPayModal = true; } return; }
            // F9 → Save Provisional (was Quick Type, moved to F7)
            if (e.key === 'F9') { e.preventDefault(); this.saveProvisionalDirect(); return; }
            // Alt+P → focus customer phone (was F7)
            if (e.altKey && (e.key === 'p' || e.key === 'P')) { e.preventDefault(); this.$refs.customerPhoneInput?.focus(); this.$refs.customerPhoneInput?.select(); return; }
            // ── RETAIL FAST BILLING shortcuts (Aug 2026) ──────────────────────────
            // Alt+1 / Alt+2 — one-tap CASH / CARD: skip modal entirely when cart has items.
            // Mirrors the mock-up (PRA-aligned): no confirmation step for simple retail sales.
            if (e.altKey && e.key === '1') { e.preventDefault(); if (this.cart.length > 0 && !this.submitting && !this.showPayModal) { this.submitting = false; this.saveAsProvisional = false; this.payPrintReceipt = this.billPrintDefault(); this.processPayment('cash'); } return; }
            if (e.altKey && e.key === '2') { e.preventDefault(); if (this.cart.length > 0 && !this.submitting && !this.showPayModal) { this.submitting = false; this.saveAsProvisional = false; this.payPrintReceipt = this.billPrintDefault(); this.processPayment('card'); } return; }
            // Alt+3 — instant UDHAAR (khata) sale; needs a selected customer (payUdhaar guards).
            if (e.altKey && e.key === '3') { e.preventDefault(); if (this.cart.length > 0 && !this.submitting && !this.showPayModal) { this.submitting = false; this.saveAsProvisional = false; this.payPrintReceipt = this.billPrintDefault(); this.payUdhaar(); } return; }
            @if($features->kot ?? false)
            // Alt+K — Kitchen mein send karein (owner video, Aug 2026): mirrors
            // the PRA screen; guards match the button's :disabled (no deals/
            // canHold on FBR). Blocking modals gate the chord like F10/F11.
            // Whole block Blade-gated like the button — no KOT feature, no chord.
            if (e.altKey && (e.key === 'k' || e.key === 'K' || e.code === 'KeyK')) {
                e.preventDefault();
                if (this.showPayModal || this.showReceipt || this.showHeldOrders || this.showQuickType || this.showManualItem || this.showCustomerPicker || this.showShortcuts || this.showManagerPinModal || this.showLocalBills || this.showFailedBills || this.showPendingDeliveries || this.tableSwitchPrompt) return;
                if (this.cart.length === 0 || this.submitting || this.hasManualItems()) return;
                this.sendToKitchen();
                return;
            }
            @endif
            // Alt+R — Reprint last bill (Akhri Bills top entry).
            if (e.altKey && (e.key === 'r' || e.key === 'R')) { e.preventDefault(); const last = this.recentBills[0]; if (last) { this._printViaIframe('print-receipt-frame', '/fbr-pos/transaction/' + last.id + '/receipt?auto_print=1', 'width=400,height=700'); this.showToast('Reprinting #' + last.invoice_number, 'info'); } else if (this.lastTransactionId) { this.printReceipt(); this.showToast('Reprinting last bill...', 'info'); } else { this.showToast(window.TXT.no_bill_reprint, 'warning'); } return; }
            if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); this.enterSearchMode(); return; }
            if ((e.ctrlKey || e.metaKey) && e.key === 'e') { e.preventDefault(); if (this.cart.length > 0) { this.enterCartMode(); this.mobileView = 'cart'; } return; }
            // ═══════════════════════════════════════════════════════════════
            // T / Alt+T — UNIVERSAL TAX TOGGLE (must run BEFORE input-field gate)
            // Smart routing:
            //   • Alt+T — ALWAYS toggles (no matter what's focused / typed)
            //   • Plain T — toggles when:
            //       (a) target is body / non-input element, OR
            //       (b) target is qty input (handled at element level too)
            //   Inside the SEARCH input plain T always TYPES (even when empty) —
            //   the old empty-search shortcut ate the first letter of "Tapal"/"tea".
            // Always operates on activeCartIndex if valid, else on the LAST cart row.
            // ═══════════════════════════════════════════════════════════════
            if ((e.key === 't' || e.key === 'T' || e.code === 'KeyT') && !e.ctrlKey && !e.metaKey && !this.tableSwitchPrompt) {
                const tgt = e.target;
                const isSearchInput = tgt && tgt === this.$refs.searchInput;
                const isCustPhone   = tgt && tgt === this.$refs.customerPhoneInput;
                const isQtyInput    = tgt && tgt.matches && tgt.matches('[data-qty-input]');
                const isOtherInput  = tgt && tgt.closest && tgt.closest('input, textarea, select') && !isSearchInput && !isCustPhone && !isQtyInput;

                let shouldToggle = false;
                if (e.altKey) {
                    shouldToggle = true; // Alt+T — always
                } else if (isQtyInput) {
                    shouldToggle = true; // qty input — element-level handler also catches, but safety here
                } else if (!isSearchInput && !isCustPhone && !isOtherInput) {
                    shouldToggle = true; // body / non-input
                }
                // NOTE: plain T inside the search input is NEVER a shortcut anymore —
                // even when empty. It swallowed the FIRST letter of product names
                // ("Tapal", "tea") during quick-create typing. Use Alt+T there.

                if (shouldToggle) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (this.cart.length === 0) { this.showToast(window.TXT.cart_is_empty, 'warning'); return; }
                    const idx = (this.activeCartIndex >= 0 && this.activeCartIndex < this.cart.length) ? this.activeCartIndex : this.cart.length - 1;
                    this.toggleItemTax(idx);
                    return;
                }
            }
            // (F7 / F9 hoisted above — kept here as no-op so future readers see the new mapping)
            // F10 — Open Provisional Bills modal (Local — not yet submitted to FBR).
            // GATED: only fires when no blocking modal is open, otherwise the
            // F10 keystroke would steal focus from Pay/Held/Receipt/etc.
            if (e.key === 'F10') {
                e.preventDefault();
                if (this.showPayModal || this.showReceipt || this.showHeldOrders || this.showQuickType || this.showManualItem || this.showCustomerPicker || this.showShortcuts || this.showManagerPinModal || this.showLocalBills || this.showFailedBills || this.showPendingDeliveries || this.tableSwitchPrompt) return;
                this.openLocalBills();
                return;
            }
            // F11 — Open Failed Bills modal (FBR submissions that need retry).
            // Same gating as F10. Browser's native F11 = fullscreen toggle is overridden.
            if (e.key === 'F11') {
                e.preventDefault();
                if (this.showPayModal || this.showReceipt || this.showHeldOrders || this.showQuickType || this.showManualItem || this.showCustomerPicker || this.showShortcuts || this.showManagerPinModal || this.showLocalBills || this.showFailedBills || this.showPendingDeliveries || this.tableSwitchPrompt) return;
                this.openFailedBills();
                return;
            }
            // ═══════════════════════════════════════════════════════════════
            // D / Alt+D — UNIVERSAL DISCOUNT TOGGLE
            // Toggles `item.showItemDiscount` on the active cart row (or last row).
            // Same smart routing as T: works in body / empty search; Alt+D anywhere.
            // After toggling ON, focuses the discount input via $nextTick.
            // SKIPPED when any list-modal is open — those modals own the D key
            // for their delete-row action (held/local/failed).
            // ═══════════════════════════════════════════════════════════════
            if ((e.key === 'd' || e.key === 'D' || e.code === 'KeyD') && !e.ctrlKey && !e.metaKey
                && !this.showHeldOrders && !this.showLocalBills && !this.showFailedBills && !this.showPendingDeliveries
                && !this.showPayModal && !this.showReceipt && !this.showQuickType
                && !this.showManualItem && !this.showCustomerPicker && !this.showShortcuts
                && !this.showManagerPinModal && !this.tableSwitchPrompt) {
                const tgt = e.target;
                const isSearchInput = tgt && tgt === this.$refs.searchInput;
                const isCustPhone   = tgt && tgt === this.$refs.customerPhoneInput;
                const isOtherInput  = tgt && tgt.closest && tgt.closest('input, textarea, select') && !isSearchInput && !isCustPhone;
                let shouldToggle = false;
                if (e.altKey) shouldToggle = true;
                else if (!isSearchInput && !isCustPhone && !isOtherInput) shouldToggle = true;
                // Plain D inside the search input (even empty) must TYPE, not toggle —
                // it was eating the first letter of names like "Dahi". Alt+D still works.
                if (shouldToggle) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (this.cart.length === 0) { this.showToast(window.TXT.cart_is_empty, 'warning'); return; }
                    const idx = (this.activeCartIndex >= 0 && this.activeCartIndex < this.cart.length) ? this.activeCartIndex : this.cart.length - 1;
                    const item = this.cart[idx];
                    item.showItemDiscount = !item.showItemDiscount;
                    this.showToast(item.showItemDiscount ? `Discount panel opened — ${item.item_name || 'item'}` : `Discount closed`, item.showItemDiscount ? 'info' : 'warning');
                    if (item.showItemDiscount) {
                        this.$nextTick(() => {
                            const el = document.querySelector(`[data-discount-input="${idx}"]`);
                            if (el) { el.focus(); el.select && el.select(); }
                        });
                    }
                    return;
                }
            }
            // ═══════════════════════════════════════════════════════════════
            // N / Alt+N — UNIVERSAL NOTE FOCUS
            // Focuses the note input on active cart row (or last row).
            // Same smart routing as T/D. Skipped when any modal is open so
            // future modal "N" shortcuts have a clear path.
            // ═══════════════════════════════════════════════════════════════
            if ((e.key === 'n' || e.key === 'N' || e.code === 'KeyN') && !e.ctrlKey && !e.metaKey
                && !this.showHeldOrders && !this.showLocalBills && !this.showFailedBills && !this.showPendingDeliveries
                && !this.showPayModal && !this.showReceipt && !this.showQuickType
                && !this.showManualItem && !this.showCustomerPicker && !this.showShortcuts
                && !this.showManagerPinModal && !this.tableSwitchPrompt) {
                const tgt = e.target;
                const isSearchInput = tgt && tgt === this.$refs.searchInput;
                const isCustPhone   = tgt && tgt === this.$refs.customerPhoneInput;
                const isQtyInput    = tgt && tgt.closest && tgt.closest('[data-qty-row]');
                const isOtherInput  = tgt && tgt.closest && tgt.closest('input, textarea, select') && !isSearchInput && !isCustPhone && !isQtyInput;
                let shouldFocus = false;
                if (e.altKey) shouldFocus = true;
                else if (isQtyInput) shouldFocus = true;
                else if (!isSearchInput && !isCustPhone && !isOtherInput) shouldFocus = true;
                // Plain N inside the search input (even empty) must TYPE, not jump to
                // notes — it was eating the first letter of names like "Naan". Alt+N works.
                if (shouldFocus) {
                    e.preventDefault();
                    e.stopPropagation();
                    this.showCartNote = true; // note panel is collapsible since the Aug 2026 redesign port
                    this.$nextTick(() => {
                        const el = this.$refs.orderNotesInput;
                        if (el) {
                            el.focus();
                            el.select && el.select();
                            this.showToast(window.TXT.ph_order_notes_enter, 'info');
                        }
                    });
                    return;
                }
            }

            // ═══════════════════════════════════════════════════════════════
            // MODAL-AWARE HANDLERS — these MUST run BEFORE the qty-input gate
            // AND the form-field gate. When Pay modal opens, focus often stays
            // on a background input (search, qty, customer phone) or jumps to
            // the modal's own checkbox / cash-received field. Without this
            // hoist, "1" / "2" would either type into the focused input OR get
            // swallowed by the qty-input block below.
            // ═══════════════════════════════════════════════════════════════
            if (this.showPayModal) {
                // Cash-received input guard: digits must TYPE (not fire 1/2 payments).
                // Enter = confirm CASH payment; Esc = blur.
                if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-cash-input')) {
                    if (e.key === 'Enter' && !e.repeat) { e.preventDefault(); e.stopPropagation(); e.target.blur(); this.payMethodIndex = 0; this.processPayment('cash'); return; }
                    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); e.target.blur(); }
                    return;
                }
                // Arrow keys move the payment-method highlight (Cash <-> Card); Enter confirms it.
                if (e.key === 'ArrowLeft' || e.key === 'ArrowUp')   { e.preventDefault(); e.stopPropagation(); this.payMethodIndex = 0; return; }
                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); e.stopPropagation(); this.payMethodIndex = 1; return; }
                // Number keys jump straight to that method AND fire it (fast path for power cashiers).
                if (e.key === '1') { e.preventDefault(); e.stopPropagation(); this.payMethodIndex = 0; this.processPayment('cash'); return; }
                if (e.key === '2') { e.preventDefault(); e.stopPropagation(); this.payMethodIndex = 1; this.processPayment('card'); return; }
                // 3 = Udhaar/Khata (Retail Core) — payUdhaar guards on selected customer.
                if (e.key === '3') { e.preventDefault(); e.stopPropagation(); this.payMethodIndex = 2; this.payUdhaar(); return; }
                // GUIDED FLOW (opt-in): P = save as Provisional (local).
                if (this.guidedFlow && (e.key === 'p' || e.key === 'P')) { e.preventDefault(); e.stopPropagation(); this.saveProvisionalDirect(); return; }
                // Enter confirms the CURRENTLY-HIGHLIGHTED method (Cash by default).
                if (e.key === 'Enter' && !e.repeat) { e.preventDefault(); e.stopPropagation(); this.processPayment(this.payMethodIndex === 1 ? 'card' : 'cash'); return; }
                if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); this.showPayModal = false; return; }
                return;
            }
            if (this.showReceipt) {
                // Esc closes the success popup (per cashier feedback — mouse use was needed).
                // If a browser print dialog is on top, Esc closes that first (native) — second Esc
                // reaches us and dismisses the popup.
                if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); this.showReceipt = false; return; }
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.startNewAfterPayment(); return; }
                if (e.key === 'p' || e.key === 'P') { e.preventDefault(); this.lastIsOffline ? this.printOfflineReceipt() : this.printReceipt(); return; }
                if ((e.key === 'k' || e.key === 'K') && this.lastOrderId) { e.preventDefault(); this.printKitchenTicket(); return; }
                return;
            }
            // CART QTY INPUT: special-case so arrow keys ALWAYS navigate cart rows
            // (single source of truth — eliminates double-firing skip bug 1→3→5).
            // All other keys (digits, dots, backspace) pass through to native input.
            const isQtyInput = e.target.matches && e.target.matches('[data-qty-input]');
            if (isQtyInput) {
                const ci = this.activeCartIndex;
                if (e.key === 'ArrowDown') { e.preventDefault(); this.moveCartSelection(1); return; }
                if (e.key === 'ArrowUp')   { e.preventDefault(); this.moveCartSelection(-1); return; }
                if (e.key === 'Tab')       { e.preventDefault(); this.moveCartSelection(e.shiftKey ? -1 : 1); return; }
                if (e.key === 'Enter')     {
                    e.preventDefault();
                    e.target.blur();
                    // GUIDED FLOW (opt-in): Enter from the qty input advances Cart → Bill.
                    // Without this, Enter only blurs and the guided chain stalls at the cart.
                    if (this.guidedFlow && !e.repeat && this.cart.length) { this.flowStep = 'finish'; this.showPayModal = true; }
                    return;
                }
                // Cart shortcuts still work while a qty input has focus.
                if ((e.key === '+' || e.key === '=') && ci >= 0) { e.preventDefault(); this.updateQty(ci, 1); if (this.cart[ci]) e.target.value = this.cart[ci].quantity; this.animateQty(ci); return; }
                if (e.key === '-' && ci >= 0)                    { e.preventDefault(); this.updateQty(ci, -1); if (this.cart[ci]) e.target.value = this.cart[ci].quantity; this.animateQty(ci); return; }
                if ((e.key === 't' || e.key === 'T') && ci >= 0) { e.preventDefault(); this.toggleItemTax(ci); return; }
                if (e.key === 'Escape')                          { e.preventDefault(); this.exitCartMode(); return; }
                return;
            }
            // HARD SAFETY: any other keystroke originating from a form field exits immediately.
            if (e.target.closest('input, textarea, select')) {
                return;
            }

            if (this.showHeldOrders && this.heldOrders.length > 0) {
                if (e.key === 'ArrowDown') { e.preventDefault(); this.activeHeldIndex = Math.min(this.activeHeldIndex + 1, this.heldOrders.length - 1); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); this.activeHeldIndex = Math.max(this.activeHeldIndex - 1, 0); }
                else if (e.key === 'Enter') { e.preventDefault(); this.recallOrder(this.heldOrders[this.activeHeldIndex]); }
                else if (e.key === 'p' || e.key === 'P') { e.preventDefault(); this.payHeldOrder(this.heldOrders[this.activeHeldIndex].id); }
                else if (e.key === 'd' || e.key === 'D') { e.preventDefault(); this.deleteHeldOrder(this.heldOrders[this.activeHeldIndex].id); }
                else if (e.key === 'Escape') { e.preventDefault(); this.showHeldOrders = false; }
                return;
            }
            // PROVISIONAL BILLS modal — keyboard navigation (mirror of held-orders shortcuts)
            // NOTE: always index into filteredLocalBills() (search may be active), never raw localBills.
            if (this.showLocalBills && this.filteredLocalBills().length > 0) {
                const flb = this.filteredLocalBills();
                if (e.key === 'ArrowDown') { e.preventDefault(); this.activeLocalIndex = Math.min(this.activeLocalIndex + 1, flb.length - 1); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); this.activeLocalIndex = Math.max(this.activeLocalIndex - 1, 0); }
                else if (e.key === 'Enter') { e.preventDefault(); if (flb[this.activeLocalIndex]) this.promoteProvisional(flb[this.activeLocalIndex]); }
                else if ((e.key === 'e' || e.key === 'E') && flb[this.activeLocalIndex]) { e.preventDefault(); window.location.href = '{{ url('/fbr-pos/transactions') }}/' + flb[this.activeLocalIndex].id + '/edit-failed'; }
                else if ((e.key === 'd' || e.key === 'D') && flb[this.activeLocalIndex]) { e.preventDefault(); this.deleteProvisional(flb[this.activeLocalIndex]); }
                else if (e.key === 'Escape') { e.preventDefault(); this.showLocalBills = false; }
                return;
            }
            if (this.showLocalBills) {
                if (e.key === 'Escape') { e.preventDefault(); this.showLocalBills = false; }
                return;
            }
            // FAILED BILLS modal — keyboard nav (mirror of provisional + held shortcuts)
            if (this.showFailedBills && this.failedBills.length > 0) {
                if (e.key === 'ArrowDown') { e.preventDefault(); this.activeFailedIndex = Math.min(this.activeFailedIndex + 1, this.failedBills.length - 1); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); this.activeFailedIndex = Math.max(this.activeFailedIndex - 1, 0); }
                else if (e.key === 'Enter') { e.preventDefault(); this.retryFailed(this.failedBills[this.activeFailedIndex]); }
                else if (e.key === 'e' || e.key === 'E') { e.preventDefault(); window.location.href = '{{ url('/fbr-pos/transactions') }}/' + this.failedBills[this.activeFailedIndex].id + '/edit-failed'; }
                {{-- D (delete) removed — FBR failed bills cannot be deleted (audit trail). --}}
                else if (e.key === 'Escape') { e.preventDefault(); this.showFailedBills = false; }
                return;
            }
            if (this.showFailedBills) {
                if (e.key === 'Escape') { e.preventDefault(); this.showFailedBills = false; }
                return;
            }
            // Pending Deliveries panel (Task 122) — Escape closes; other keys inert.
            // Task 543: settle modal sits ABOVE pending-deliveries — Escape closes it FIRST
            if (this.riderSettleBill) {
                if (e.key === 'Escape') { e.preventDefault(); this.riderSettleBill = null; }
                return;
            }
            if (this.showPendingDeliveries) {
                if (e.key === 'Escape') { e.preventDefault(); this.showPendingDeliveries = false; }
                return;
            }
            if (this.showManagerPinModal) {
                if (e.key === 'Escape') { e.preventDefault(); this.showManagerPinModal = false; }
                return;
            }

            // (F-keys hoisted to top of handleKey above — kept here as no-op
            //  comment so future readers understand the routing.)

            // Smart Upsell takes highest priority for Enter/Esc when visible
            if (this.currentUpsell) {
                if (e.key === 'Enter') { e.preventDefault(); this.acceptUpsell(); return; }
                if (e.key === 'Escape') { e.preventDefault(); this.dismissUpsell(true); return; }
            }

            if (e.key === 'Escape') {
                if (this.showShortcuts) { this.showShortcuts = false; return; }
                if (this.showNewCustomerModal) { this.showNewCustomerModal = false; return; }
                if (this.showLowStockPopup) { this.showLowStockPopup = false; return; }
                if (this.showTablePicker) { this.showTablePicker = false; return; }
                if (this.showCustomerPicker) { this.showCustomerPicker = false; return; }
                if (this.showCustomerHistory) { this.showCustomerHistory = false; return; }
                if (this.customerPhoneDropdown) { this.customerPhoneDropdown = false; return; }
                if (this.cartMode) { this.cartMode = false; this.activeCartIndex = -1; return; }
                if (this.gridFocusMode) { this.enterSearchMode(); return; }
                if (this.searchQuery) { this.searchQuery = ''; this.showSearchDropdown = false; this.filterProducts(); return; }
                if (this.activeCategory !== 'all') { this.activeCategory = 'all'; this.filterProducts(); return; }
                return;
            }

            // Prevent native page scroll on arrow keys (when not in input)
            if (this.cart.length > 0 && (e.key === 'ArrowUp' || e.key === 'ArrowDown' || e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
                e.preventDefault();
            }

            // Mode-specific routing — only stay in cart if focus is actually in the cart.
            // (Sticky cartMode + BODY focus would otherwise hijack ArrowUp/Down navigation
            //  and prevent ArrowUp-from-outside from entering the cart at the BOTTOM row.)
            const focusInCart = !!(document.activeElement?.closest?.('[data-cart-index]'));
            if (this.cartMode && this.cart.length > 0 && focusInCart) { this.handleCartKeys(e); return; }
            this.handleSearchKeys(e);
        },

        handleCartKeys(e) {
            const ci = this.activeCartIndex;
            if (e.key === 'ArrowDown') { this.moveCartSelection(1); return; }
            if (e.key === 'ArrowUp')   { this.moveCartSelection(-1); return; }
            if ((e.key === '+' || e.key === '=') && ci >= 0) { this.updateQty(ci, 1); this.animateQty(ci); return; }
            if (e.key === '-' && ci >= 0) { this.updateQty(ci, -1); this.animateQty(ci); return; }
            if (e.key === 'Delete' && ci >= 0) { this.removeFromCart(ci); this.fixCartIndex(); return; }
            if ((e.key === 't' || e.key === 'T') && ci >= 0) { this.toggleItemTax(ci); return; }
            if (e.key === 'Enter' && this.cart.length) { if (this.guidedFlow) this.flowStep = 'finish'; this.showPayModal = true; return; }
            if (/^[a-zA-Z]$/.test(e.key) && !e.ctrlKey && !e.metaKey) {
                this.cartMode = false; this.activeCartIndex = -1;
                this.searchQuery += e.key; this.$refs.searchInput?.focus();
                this.$nextTick(() => this.onSearchInput());
            }
        },

        handleSearchKeys(e) {
            // Natural pattern: ArrowDown enters cart at TOP, ArrowUp enters at BOTTOM.
            if (e.key === 'ArrowDown' && this.cart.length > 0 && !this.gridFocusMode) { this.enterCartMode(0); return; }
            if (e.key === 'ArrowUp' && this.cart.length > 0 && !this.gridFocusMode) { this.enterCartMode('last'); return; }
            if ((e.key === '+' || e.key === '=') && this.cart.length > 0) { this.updateQty(this.cart.length - 1, 1); this.animateQty(this.cart.length - 1); return; }
            if (e.key === '-' && this.cart.length > 0) { this.updateQty(this.cart.length - 1, -1); this.animateQty(this.cart.length - 1); return; }
            if (e.key === 'Delete' && this.cart.length > 0) { this.removeFromCart(this.cart.length - 1); this.fixCartIndex(); return; }
            // T — toggle tax on LAST cart row (no need to enter cart mode for quick toggle of last-added item)
            if ((e.key === 't' || e.key === 'T') && this.cart.length > 0 && !e.ctrlKey && !e.metaKey) { e.preventDefault(); this.toggleItemTax(this.cart.length - 1); return; }
            if (e.key === 'Tab' && !e.shiftKey && !this.gridFocusMode) { e.preventDefault(); this.enterGridMode(); return; }
            if (e.key.length === 1 && /[a-zA-Z]/.test(e.key) && !e.ctrlKey && !e.metaKey && !e.altKey && !this.gridFocusMode) {
                this.searchQuery += e.key;
                this.$refs.searchInput?.focus();
                this.$nextTick(() => this.onSearchInput());
            }
        },

        scrollToCartItem(index) {
            this.$nextTick(() => {
                const el = this.$refs.cartList?.querySelector(`[data-cart-index="${index}"]`);
                if (el) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            });
        },

        animateQty(index) {
            this.$nextTick(() => {
                const el = this.$refs.cartList?.querySelector(`[data-cart-index="${index}"] [data-qty-input]`);
                if (el) { el.classList.remove('qty-pop'); void el.offsetWidth; el.classList.add('qty-pop'); }
            });
        },

        clearCart() { this.cart = []; this.kitchenNotes = ''; this.selectedTable = null; this.selectedCustomer = null; this.customerStats = null; this.customerPhoneQuery = ''; this.customerPhoneResults = []; this.customerPhoneDropdown = false; this.customerNtn = ''; this.customerAddresses = []; this.selectedDeliveryAddress = ''; this.pendingAddrRestore = null; this.showAddrNew = false; this.newAddrText = ''; this.newAddrLabel = ''; this.stockError = ''; this.priorityOrder = false; this.recalledOrderId = null; this.discountType = 'percentage'; this.discountValue = 0; this.discountAmount = 0; this.showDiscount = false; this.managerOverrideActive = false; this.activeCartIndex = -1; this.cartMode = false; this.flowStep = 'customer'; this.fixCartIndex(); this.clearCartStorage(); this.billUuid = this._newBillUuid();
            // Order Matching (Aug 2026): reset token/code so a brand-new sale
            // never inherits an identifier from the previous order.
            this.currentTokenNo = null; this.currentOrderCode = null; this.lastHeldId = null;
            // Task 1271: discarding a cart that held a recalled draft → detach and
            // release the edit lock so the counter partner can pick it up now.
            // (Save/pay paths clear activeDraftId BEFORE calling clearCart, so this
            // only fires on genuine discards — newSale / voidOrder.)
            if (this.activeDraftId) {
                const did = this.activeDraftId;
                this.activeDraftId = null;
                this.stopDraftLockRenewal();
                fetch('/fbr-pos/api/drafts/' + did + '/unlock', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
                }).catch(() => {});
            } },
        newSale() {
            if (this.cart.length > 0) { if (!confirm(window.TXT.current_order_has + this.cart.length + ' item(s). Discard and start new sale?')) return; }
            this.clearCart(); this.showToast(window.TXT.new_sale_started, 'success');
        },
        voidOrder() {
            if (this.cart.length === 0) return;
            if (!confirm(window.TXT.void_current_order_q)) return;
            this.clearCart(); this.showToast(window.TXT.order_voided, 'success');
        },
        selectTable(table, opts) {
            // ZFC guard mirror (Aug 2026, ported from PRA universal): table ALREADY
            // selected + a DIFFERENT table + unsent cart → explicit move/discard
            // choice, never a silent carry-over. Recalled-order carts belong to a
            // real stored order — guard skips them. First-time pick stays prompt-free.
            if (!(opts && opts.skipSwitchPrompt) && this.selectedTable && this.selectedTable.id !== table.id && this.hasUnsentCart()) {
                this.openTableSwitchPrompt({ kind: 'table', table });
                return;
            }
            this.selectedTable = table; this.orderType = 'dine_in'; this.showTablePicker = false;
        },
        // ── ZFC unsent-cart switch guard (Aug 2026, mirror of PRA universal) ──────
        hasUnsentCart() { return this.cart.length > 0 && !this.recalledOrderId; },
        openTableSwitchPrompt(target) {
            this.tableSwitchPrompt = target;
            this.tableSwitchIndex = 0;
            // Blur so a focused search/qty input can't swallow the prompt's keys.
            try { document.activeElement?.blur(); } catch(_) {}
        },
        tableSwitchTargetLabel() {
            const p = this.tableSwitchPrompt;
            if (!p) return '';
            return window.TXT.table_t_prefix2 + p.table.table_number;
        },
        // Lighter than clearCart(): only the UNSENT items + their riding state are
        // cleared (notes, discount, cart focus) — customer/table stay with the
        // caller's decision. Persisted cart storage bhi saaf — offline persistence
        // items wapas na le aaye.
        discardUnsentCart() {
            this.cart = [];
            this.kitchenNotes = '';
            this.stockError = '';
            this.priorityOrder = false;
            this.discountType = 'percentage';
            this.discountValue = 0;
            this.discountAmount = 0;
            this.showDiscount = false;
            this.activeCartIndex = -1;
            this.cartMode = false;
            this.fixCartIndex();
            this.clearCartStorage();
        },
        confirmTableSwitch(action) {
            const p = this.tableSwitchPrompt;
            if (!p) return;
            this.tableSwitchPrompt = null;
            if (action === 'discard') this.discardUnsentCart();
            this.selectTable(p.table, { skipSwitchPrompt: true });
        },

        // ── Global mouse-wheel forwarding ──────────────────────────────────────
        // The sale screen is a fixed-height app shell (body never scrolls), so a
        // wheel spin over "dead" areas (header, customer bar, empty gaps) used to
        // do NOTHING. Forward those wheel events to the product grid. Native
        // scrolling still wins wherever the cursor is over an element that can
        // actually scroll (grid, cart list, dropdowns, modal bodies).
        handleGlobalWheel(e) {
            if (!e.deltaY) return;
            // Never hijack the wheel inside fixed overlays (modals, drawers).
            if (e.target && e.target.closest && e.target.closest('.fixed')) return;
            let el = e.target;
            while (el && el !== document.body) {
                if (el.scrollHeight > el.clientHeight + 1) {
                    const oy = window.getComputedStyle(el).overflowY;
                    if (oy === 'auto' || oy === 'scroll') {
                        const down = e.deltaY > 0;
                        const canScroll = down ? (el.scrollTop + el.clientHeight < el.scrollHeight - 1) : (el.scrollTop > 0);
                        if (canScroll) return; // native scroll handles it
                    }
                }
                el = el.parentElement;
            }
            const grid = this.$refs.gridContainer;
            if (grid) grid.scrollTop += e.deltaY;
        },

        onCustomerPhoneSearch() {
            if (this.customerLookupTimer) clearTimeout(this.customerLookupTimer);
            if (this.pickerSearchTimer) clearTimeout(this.pickerSearchTimer);
            const phone = this.customerSearch.trim();
            if (this.isPhoneLike(phone)) {
                this.customerLookupTimer = setTimeout(() => this.lookupCustomerByPhone(phone), 400);
            } else {
                this.customerLookupResult = null;
            }
            // Task 100: baked list is PARTIAL on huge shops — picker search must
            // hit the server so EVERY customer is findable, not just the baked
            // recent subset. Offline → falls back to the baked subset.
            if (this.customersBakedPartial && phone.length >= 2) {
                this.pickerSearchTimer = setTimeout(() => this.pickerServerSearch(phone), 300);
            } else {
                this.pickerServerResults = null;
            }
        },

        async pickerServerSearch(q) {
            try {
                const res = await fetch('/fbr-pos/api/customer-search?q=' + encodeURIComponent(q));
                const data = await res.json();
                if (q !== this.customerSearch.trim()) return; // stale-response guard
                this.pickerServerResults = data.customers || [];
            } catch (e) { this.pickerServerResults = null; } // OFFLINE → local baked subset
        },

        async lookupCustomerByPhone(phone) {
            try {
                const res = await fetch('/fbr-pos/api/customer-lookup?phone=' + encodeURIComponent(phone));
                this.customerLookupResult = await res.json();
            } catch(e) { this.customerLookupResult = null; }
        },

        selectLookedUpCustomer() {
            if (!this.customerLookupResult || !this.customerLookupResult.found) return;
            const c = this.customerLookupResult.customer;
            this.selectedCustomer = c;
            this.customerStats = this.customerLookupResult.stats;
            this.customerPhoneQuery = c.name + (c.phone ? " · " + c.phone : "");
            this.showCustomerPicker = false;
            this.customerLookupResult = null;
            this.showToast(window.TXT.customer_prefix + c.name + (this.customerStats.is_frequent ? ' (VIP)' : ''), 'success');
        },

        // ── Task 163: customer delivery addresses (PRA universal parity) ──────
        // pos_customers.address = "address #1"; extras live in pos_customer_addresses.
        // The chosen text is a SNAPSHOT on the bill (fbr_pos_transactions.delivery_address)
        // so later address edits never rewrite old bills. Walk-in customers (no id)
        // can still type a one-off address — it snapshots without being saved.
        async loadCustomerAddresses() {
            // Task 170: pendingAddrRestore = held-order address being restored on
            // recall; it must beat the saved-default auto-select below.
            const pending = this.pendingAddrRestore;
            this.customerAddresses = []; this.selectedDeliveryAddress = pending || ''; this.showAddrNew = false; this.newAddrText = ''; this.newAddrLabel = '';
            const c = this.selectedCustomer;
            if (!c || !c.id) return;
            try {
                const res = await fetch('/fbr-pos/api/customer-addresses?customer_id=' + c.id, { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                this.customerAddresses = Array.isArray(data.addresses) ? data.addresses : [];
                if (pending) {
                    // One-off typed addresses aren't in the saved list — add so the
                    // <select> can actually show them.
                    if (!this.customerAddresses.some(a => a.address === pending)) this.customerAddresses.push({ id: null, label: null, address: pending });
                    this.selectedDeliveryAddress = pending;
                    this.pendingAddrRestore = null;
                } else if (this.customerAddresses.length && !this.selectedDeliveryAddress) this.selectedDeliveryAddress = this.customerAddresses[0].address;
            } catch (e) { console.error('[addresses] load failed', e); }
        },
        async saveNewAddress() {
            const text = (this.newAddrText || '').trim();
            if (!text) return;
            const label = (this.newAddrLabel || '').trim();
            const c = this.selectedCustomer;
            if (!c || !c.id) {
                // Walk-in: one-off snapshot only, nothing to persist against.
                this.customerAddresses.push({ id: null, label: label || null, address: text });
                this.selectedDeliveryAddress = text;
                this.showAddrNew = false; this.newAddrText = ''; this.newAddrLabel = '';
                return;
            }
            try {
                const res = await fetch('/fbr-pos/api/customer-addresses', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ customer_id: c.id, address: text, label: label || null }),
                });
                const data = await res.json().catch(() => null);
                if (data && data.success && data.address) {
                    this.customerAddresses.push(data.address);
                    this.selectedDeliveryAddress = data.address.address;
                    this.showAddrNew = false; this.newAddrText = ''; this.newAddrLabel = '';
                    this.showToast(window.TXT.address_saved, 'success');
                } else {
                    this.showToast((data && data.message) || window.TXT.could_not_save_address, 'error');
                }
            } catch (e) { this.showToast(window.TXT.could_not_save_address_conn, 'error'); }
        },
        // Delete the SELECTED saved address from the sale screen (PRA parity).
        // id=0 = customer's default address (cleared, not row-deleted); walk-in
        // one-off entries (id=null) are local-only, just dropped from the list.
        async deleteSelectedAddress() {
            const sel = this.selectedDeliveryAddress;
            // Duplicate texts: if the same address text exists as both the Default
            // and an extra row, delete the EXTRA row first — never silently clear
            // the default when an equivalent copy exists.
            const matches = this.customerAddresses.filter(x => x.address === sel);
            const a = matches.find(x => x.id !== 0 && x.id !== null) || matches.find(x => x.id !== 0) || matches[0];
            if (!a) return;
            if (!confirm(window.TXT.confirm_delete_address + '\n' + (a.label ? a.label + ': ' : '') + a.address)) return;
            const drop = () => {
                this.customerAddresses = this.customerAddresses.filter(x => x !== a);
                this.selectedDeliveryAddress = '';
            };
            const c = this.selectedCustomer;
            if (a.id === null || !c || !c.id) { drop(); return; }
            try {
                const res = await fetch('/fbr-pos/api/customer-addresses/delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ customer_id: c.id, id: a.id }),
                });
                const data = await res.json().catch(() => null);
                if (data && data.success) {
                    drop();
                    if (a.id === 0 && this.selectedCustomer) this.selectedCustomer.address = null;
                    this.showToast(window.TXT.address_deleted || 'Deleted', 'success');
                } else {
                    this.showToast((data && data.message) || window.TXT.could_not_save_address, 'error');
                }
            } catch (e) { this.showToast(window.TXT.could_not_save_address_conn, 'error'); }
        },

        async selectCustomerWithStats(c) {
            this.selectedCustomer = c;
            this.customerStats = null;
            this.customerPhoneQuery = c.name + (c.phone ? " · " + c.phone : "");
            this.showCustomerPicker = false;
            this.showToast(window.TXT.customer_prefix + c.name, 'success');
            if (c.phone) {
                try {
                    const res = await fetch('/fbr-pos/api/customer-lookup?phone=' + encodeURIComponent(c.phone));
                    const data = await res.json();
                    if (data.found) {
                        this.customerStats = data.stats;
                        if (data.customer.address && !this.selectedCustomer.address) {
                            this.selectedCustomer.address = data.customer.address;
                        }
                    }
                } catch(e) {}
            }
        },

        onCustomerPhoneInput() {
            if (this.customerPhoneTimer) clearTimeout(this.customerPhoneTimer);
            const q = this.customerPhoneQuery.trim();
            if (this.selectedCustomer) {
                this.selectedCustomer = null;
                this.customerStats = null;
            }
            this.custHiIndex = 0;
            if (q.length >= 3) {
                this.customerPhoneTimer = setTimeout(() => this.searchCustomerByPhone(q), 300);
            } else {
                this.customerPhoneResults = [];
                this.customerPhoneDropdown = false;
            }
        },

        // Item #2 mirror — ↑↓ keyboard navigation over the customer dropdown (wraps around).
        custNav(dir) {
            if (!this.customerPhoneDropdown || this.customerPhoneResults.length === 0) return;
            const n = this.customerPhoneResults.length;
            this.custHiIndex = ((this.custHiIndex + dir) % n + n) % n;
            this.$nextTick(() => {
                document.querySelector('[data-cust-row="' + this.custHiIndex + '"]')?.scrollIntoView({ block: 'nearest' });
            });
        },

        searchCustomerByPhone(q) {
            // Expose the in-flight fetch as a promise so onCustomerPhoneEnter() can AWAIT the
            // SAME search instead of dead-ending when the cashier presses Enter mid-search.
            // This is what makes the keyboard "add new customer" path reliable (no mouse).
            this._custSearchPromise = (async () => {
                this.customerSearching = true;
                try {
                    const res = await fetch('/fbr-pos/api/customer-search?q=' + encodeURIComponent(q));
                    const data = await res.json();
                    this.customerPhoneResults = data.customers || [];
                    this.custHiIndex = 0;
                    // Always show dropdown so the inline "add new" hint can appear when results === 0
                    this.customerPhoneDropdown = true;
                } catch(e) {
                    // OFFLINE fallback (Task 100): server unreachable → keep local
                    // matches from the baked (possibly partial) list instead of
                    // blanking the dropdown the local pre-filter already opened.
                    const lq = q.toLowerCase();
                    this.customerPhoneResults = (this.allCustomers || [])
                        .filter(c => (c.name && c.name.toLowerCase().includes(lq)) || (c.phone && String(c.phone).includes(q)))
                        .slice(0, 8);
                    this.customerPhoneDropdown = this.customerPhoneResults.length > 0;
                }
                finally { this.customerSearching = false; }
            })();
            return this._custSearchPromise;
        },

        async onCustomerPhoneEnter() {
            const q = this.customerPhoneQuery.trim();
            if (this.showNewCustomerInline) { this.saveNewCustomer(); return; }
            // GUIDED FLOW: the customer step stays OPTIONAL (empty Enter = walk-in, moves on).
            // But when the cashier HAS typed a valid mobile number:
            //   - match found → attach customer, move to items
            //   - no match   → open the inline new-customer form (full keyboard: name → Enter
            //     → address → Enter saves & moves to items). No mouse needed. Esc = skip.
            if (this.guidedFlow) {
                const validPhone = this.isPhoneLike(q);
                // Enter must ALWAYS resolve to a decision (attach existing OR open the new-
                // customer form) — never a silent no-op. Previously an in-flight search made
                // Enter `return` and do nothing, forcing the cashier to grab the mouse.
                // Instead: SETTLE the search first. If one is already running, AWAIT that same
                // fetch (no duplicate); otherwise kick off a fresh one for the current number.
                if (validPhone) {
                    if (this.customerPhoneTimer) { clearTimeout(this.customerPhoneTimer); this.customerPhoneTimer = null; }
                    if (this.customerSearching && this._custSearchPromise) {
                        try { await this._custSearchPromise; } catch (e) {}
                    } else if (this.customerPhoneResults.length === 0) {
                        await this.searchCustomerByPhone(q);
                    }
                }
                if (this.customerPhoneResults.length > 0) {
                    this.selectCustomerFromPhone(this.customerPhoneResults[this.custHiIndex] || this.customerPhoneResults[0]);
                    this.customerPhoneDropdown = false;
                    this.flowStep = 'items';
                    this.$nextTick(() => { this.$refs.searchInput?.focus(); });
                    return;
                }
                if (validPhone) { this.openInlineNewCustomer(); return; }
                this.customerPhoneDropdown = false;
                this.flowStep = 'items';
                this.$nextTick(() => { this.$refs.searchInput?.focus(); });
                return;
            }
            if (!q) return;
            if (this.customerPhoneResults.length > 0) {
                this.selectCustomerFromPhone(this.customerPhoneResults[this.custHiIndex] || this.customerPhoneResults[0]);
            } else if (this.isPhoneLike(q)) {
                this.openInlineNewCustomer();
            } else {
                this.showToast(window.TXT.enter_valid_mobile, 'error');
            }
        },

        // Pizza Master (Aug 2026): accept dashes/spaces in typed mobile numbers (03001-1234567);
        // digits-only gate used to silently block the new-customer (address) form.
        phoneDigits(q) { return String(q || '').replace(/\D/g, ''); },
        isPhoneLike(q) {
            const s = String(q || '').trim();
            return s !== '' && /^[0-9+()\s-]+$/.test(s) && this.phoneDigits(s).length >= 4;
        },

        openInlineNewCustomer() {
            const q = this.customerPhoneQuery.trim();
            if (!this.isPhoneLike(q)) { this.showToast(window.TXT.enter_valid_mobile, 'error'); return; }
            this.newCustomerPhone = this.phoneDigits(q);
            this.newCustomerName = '';
            this.newCustomerAddress = '';
            this.showNewCustomerInline = true;
            this.customerPhoneDropdown = true;
            // Land the cursor in the name field so the cashier types immediately — no mouse.
            // $nextTick handles Alpine's DOM flush; the short timeout is a fallback for the
            // x-show/x-transition paint race that can otherwise swallow the first focus.
            this.$nextTick(() => this.$refs.newCustomerNameInput?.focus());
            setTimeout(() => { if (this.showNewCustomerInline) this.$refs.newCustomerNameInput?.focus(); }, 80);
        },

        cancelInlineNewCustomer() {
            this.showNewCustomerInline = false;
            this.newCustomerName = '';
            this.newCustomerAddress = '';
            this.$nextTick(() => this.$refs.customerPhoneInput?.focus());
        },

        selectCustomerFromPhone(cr) {
            this.selectedCustomer = { id: cr.id, name: cr.name, phone: cr.phone, address: cr.address };
            this.customerStats = cr.stats || null;
            this.customerPhoneQuery = cr.name + (cr.phone ? ' · ' + cr.phone : '');
            this.customerPhoneDropdown = false;
            this.customerPhoneResults = [];
            this.showToast(window.TXT.customer_prefix + cr.name + (cr.stats && cr.stats.is_frequent ? ' (VIP)' : ''), 'success');
            this.$nextTick(() => { this.$refs.searchInput?.focus(); });
        },

        async saveNewCustomer() {
            if (this.savingCustomer) return;
            const name = this.newCustomerName.trim();
            if (!name) { this.showToast(window.TXT.customer_name_required, 'error'); this.$refs.newCustomerNameInput?.focus(); return; }
            this.savingCustomer = true;
            try {
                const res = await fetch('{{ route("fbrpos.api.customer-store") }}', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ name: name, phone: this.newCustomerPhone, address: this.newCustomerAddress.trim() || null })
                });
                const data = await res.json();
                if (data.success) {
                    this.selectedCustomer = { id: data.customer.id, name: data.customer.name, phone: data.customer.phone, address: data.customer.address };
                    this.customerStats = { total_orders: 0, total_spent: 0, is_frequent: false, last_order_date: null };
                    this.customerPhoneQuery = data.customer.name + (data.customer.phone ? ' · ' + data.customer.phone : '');
                    if (this.guidedFlow) this.flowStep = 'items';
                    this.showNewCustomerInline = false;
                    this.showNewCustomerModal = false;
                    this.customerPhoneDropdown = false;
                    this.customerPhoneResults = [];
                    if (this.allCustomers) this.allCustomers.push(data.customer);
                    this.showToast(window.TXT.new_customer_prefix + data.customer.name, 'success');
                    this.$nextTick(() => { this.$refs.searchInput?.focus(); });
                } else { this.showToast(data.message || window.TXT.failed_save_customer, 'error'); }
            } catch(e) { this.showToast(window.TXT.network_error, 'error'); }
            finally { this.savingCustomer = false; }
        },

        clearCustomerInput() {
            this.customerPhoneQuery = '';
            this.customerPhoneResults = [];
            this.customerPhoneDropdown = false;
            this.showNewCustomerInline = false;
            this.newCustomerName = '';
            this.newCustomerAddress = '';
            this.newCustomerPhone = '';
            this.selectedCustomer = null;
            this.customerStats = null;
            this.$refs.customerPhoneInput?.focus();
        },

        async holdOrder(opts) {
            opts = opts || {};
            if (this.cart.length === 0 || this.submitting) return null;
            // Defence-in-depth: backend hold endpoint validates item_id as required|integer
            // and item_type in product,service. Synthetic manual lines (item_id=null,
            // item_type='manual') would 422. Block the action client-side too so the
            // cashier doesn't lose the cart on a server reject.
            if (this.hasManualItems()) {
                this.showToast(window.TXT.manual_items_billing_only_hold, 'error');
                return null;
            }
            const now = Date.now();
            if (now - this.lastHoldTime < 2000) return null;
            this.lastHoldTime = now;
            this.submitting = true;
            let result = null;
            try {
                // FBR POS held sales — parked carts stored as opaque JSON via
                // FbrPosPhase2Controller::holdSale (NOT restaurant orders — FBR
                // has no restaurant module). Recall restores the full cart incl.
                // hs_code / uom / tax_rate / buyer NTN.
                const holdName = ((this.selectedCustomer?.name || window.TXT.hold_word) + ' ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })).slice(0, 100);
                const cartData = {
                    items: this.cart.map(i => ({ ...i })),
                    discount_type: this.discountAmount > 0 ? this.discountType : null,
                    discount_value: this.discountAmount > 0 ? this.discountValue : 0,
                    customer_id: this.selectedCustomer?.id || null,
                    customer_phone: this.selectedCustomer?.phone || null,
                    customer_ntn: this.customerNtn || null,
                    // Task 641: persist the order note so the KOT print shows it and
                    // the server-side identity-autofill discard can run on it.
                    kitchen_notes: this.kitchenNotes || null,
                    // Task 170: snapshot order type + delivery address so a recalled
                    // hold restores a typed/one-off address (same expression as the
                    // final-bill payload builder — falls back to customer default).
                    order_type: this.orderType || null,
                    delivery_address: this.orderType === 'delivery' ? ((this.selectedDeliveryAddress || '').trim() || (this.selectedCustomer?.address || '').trim() || null) : null,
                    // Snapshot of the FINAL payable total (discounts + per-item tax
                    // + Rs1 FBR charge) so the F3 held list shows the real figure.
                    total_amount: this.totalAmount,
                    // Order Matching (Aug 2026) — same-token invariant: embed the
                    // current token/code so a re-hold sends them back to the server,
                    // which recognises the existing identifier and does NOT call
                    // OrderTokenService::nextToken() again. A fresh hold (null) lets
                    // the server assign the very first token.
                    token_no: this.currentTokenNo || null,
                    order_code: this.currentOrderCode || null,
                };
                const res = await fetch('{{ route("fbrpos.phase2.hold") }}', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ hold_name: holdName, customer_name: this.selectedCustomer?.name || null, customer_phone: this.selectedCustomer?.phone || null, cart_data: cartData }),
                });
                const data = await res.json();
                if (res.ok && data.success) {
                    // Order Matching: capture the server-assigned token/code (first hold
                    // assigns it; re-holds echo back the same value).
                    if (data.token_no != null)   this.currentTokenNo   = data.token_no;
                    if (data.order_code != null)  this.currentOrderCode = data.order_code;
                    this.lastHeldId = data.id;
                    this.showToast(opts.successMessage || window.TXT.order_held_recall_f3, 'success');
                    this.heldOrders.unshift({ id: data.id, order_number: holdName, customer_name: this.selectedCustomer?.name || null, status: 'held', total_amount: this.totalAmount, items: cartData.items, cart_data: cartData });
                    // Order Matching: forcePrintKot = "Send to Kitchen" — print KOT
                    // immediately using the FBR KOT endpoint (held row still alive).
                    if (opts.forcePrintKot && data.id) {
                        this.printKitchenTicket(data.id, null, true /* isFbrHeld */);
                    }
                    this.clearCart();
                    this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); });
                    result = data;
                } else { this.showToast(data.message || window.TXT.hold_failed, 'error'); }
            } catch (e) { this.showToast(window.TXT.network_error, 'error'); }
            this.submitting = false;
            return result;
        },

        // Load parked carts from FbrPosHeldSale on mount (header badge + F3 modal).
        async loadHeldOrders() {
            try {
                const res = await fetch('{{ route("fbrpos.phase2.held.list") }}', { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;
                const rows = await res.json();
                if (!Array.isArray(rows)) return;
                this.heldOrders = rows.map(r => {
                    const cd = r.cart_data || {};
                    const items = Array.isArray(cd.items) ? cd.items : (Array.isArray(cd) ? cd : []);
                    // Prefer the exact total snapshot saved at hold time; the
                    // subtotal fallback (pre-discount/tax) only covers legacy rows.
                    const total = (cd.total_amount || cd.total_amount === 0) ? parseFloat(cd.total_amount)
                        : items.reduce((s, i) => s + ((parseFloat(i.quantity) || 0) * (parseFloat(i.unit_price) || 0)), 0);
                    return { id: r.id, order_number: r.hold_name || ('#' + r.id), customer_name: r.customer_name || null, status: 'held', total_amount: total, items, cart_data: cd };
                });
            } catch (e) { /* silent — badge just won't show */ }
        },

        // Phase 5 — explicit "Send to Store" action (FBR Store branding, Task 1285:
        // the slip goes to the shop's godown/packing store, not a kitchen).
        // Same persistence as Hold, but always prints a store slip (no payment is taken).
        async sendToKitchen() {
            if (this.cart.length === 0) return;
            await this.holdOrder({ forcePrintKot: true, successMessage: window.TXT.fbr_sent_to_store || 'Sent to store' });
        },

        // Phase 5 — re-send an existing held order's KOT to the kitchen.
        //
        // FBR held sales live in fbr_pos_held_sales (JSON carts) — they have no
        // pos_restaurant_orders record and no kot_print_count counter.
        // Re-sending is simply re-printing via the FBR KOT endpoint:
        //   GET /fbr-pos/held/{id}/kitchen-ticket
        //
        // The PRA path (pos.restaurant.orders.resend-kitchen POST) must NOT be
        // used here — it 404s for every FBR company because the order id is from
        // fbr_pos_held_sales, not pos_restaurant_orders.
        async resendKitchen(order) {
            if (!order || !order.id) return;
            // Re-print the FBR KOT. No POST needed — no print-count tracking on FBR held carts.
            this.printKitchenTicket(order.id, null, /* isFbrHeld */ true);
            this.showToast(window.TXT.fbr_resent_to_store_prefix ? window.TXT.fbr_resent_to_store_prefix + '1)' : 'Store slip re-sent', 'success');
        },

        // ─── SAVE PROVISIONAL DIRECT — fully isolated from Pay modal ─────
        // Sets provisional flag + uses default 'cash' method, then routes
        // through the existing processPayment pipeline. No modal opens, no
        // keyboard conflict, no checkbox confusion. User can later edit /
        // delete / promote-to-final from F10 (Local) shortcut.
        async saveProvisionalDirect() {
            if (this.submitting) return;
            if (this.cart.length === 0) { this.showToast(window.TXT.cart_is_empty, 'error'); return; }
            this.saveAsProvisional = true;
            this.showPayModal = false;
            // Task 520: direct provisional save = no checkbox surface — company
            // default use karo, stale per-bill untick inherit na ho.
            this.payPrintReceipt = this.billPrintDefault();
            await this.processPayment('cash');
        },

        // ═══ UDHAAR / KHATA SALE (Aug 2026 — Retail Core) ═══
        // Credit sale — the bill amount lands in the selected customer's khata
        // (fbr_customer_ledgers + pos_customers.khata_balance, server-side).
        // A saved customer is MANDATORY; the server rejects credit without one.
        payUdhaar() {
            if (this.submitting) return;
            if (!this.selectedCustomer?.id) {
                this.showToast(window.TXT.udhaar_need_customer_toast, 'warning');
                this.showPayModal = false;
                this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); });
                return;
            }
            this.processPayment('credit');
        },

        async processPayment(method) {
            if (this.submitting) return;
            // Cash Received / Wapsi: snapshot for the success popup.
            this.lastCashReceived = (method === 'cash') ? (parseFloat(this.cashReceived) || 0) : 0;
            // Capture provisional flag once at submission start so a stray
            // re-render/checkbox toggle mid-flight cannot flip the path.
            const provisional = !!this.saveAsProvisional;
            // Task 520 (port of Task 514): per-bill receipt print choice snapshot
            // (checkbox unticked = skip SIRF is bill ki receipt auto-print;
            // KOT/FBR submission/receipt popup untouched).
            const skipReceipt = !this.payPrintReceipt;
            // Task 1271: WA auto-open tab must be reserved INSIDE this gesture
            // (provisionals are never WhatsApp-able — no blank-tab flash).
            if (!provisional) this.reserveWaWindow(this.selectedCustomer?.phone);

            if (this.payingHeldOrderId) {
                this.submitting = true; this.stockError = '';
                await this.payHeldOrderDirect(this.payingHeldOrderId, method, null, provisional, skipReceipt);
                this.payingHeldOrderId = null;
                this.showPayModal = false; this.submitting = false; this.saveAsProvisional = false;
                return;
            }

            if (this.cart.length === 0) return;

            // Manual-cart bypass — when cart contains "+ Manual" or Quick Type
            // manual entries (item_id=null, item_type='manual'), the restaurant
            // hold endpoint rejects them (validates item_id required|integer).
            // Route directly to pos.invoice.store which has lax per-item
            // validation and supports manual lines end-to-end. This path skips
            // restaurant_orders/KOT entirely — manual items are billing-only by
            // design and the "Send to Kitchen" button is already disabled when
            // hasManualItems() is true (see Pay button area).
            // Retail POS (non-restaurant) companies: restaurant hold endpoint
            // returns 403. Route ALL payments through processPaymentManual
            // which posts directly to pos.invoice.store (universal endpoint).
            // ── FBR DIVERGENCE (Task 1272) ── ALWAYS bill via processPaymentManual
            // (fbrpos.store). The PRA restaurant hold/pay endpoints below are
            // guard-blocked for fbrpos sessions (PosAuth 302s to /pos/login), so
            // this branch must short-circuit even when isRestaurantMode=true
            // (KOT companies since the Aug 2026 kot unpin). FBR KOT printing runs
            // off the TRANSACTION via runAutoPrintChain — never restaurant orders.
            // The PRA branch below is kept for port-diffability only (dead code).
            const FBR_ALWAYS_MANUAL = true;
            if (FBR_ALWAYS_MANUAL || !this.isRestaurantMode || this.hasManualItems()) {
                return await this.processPaymentManual(method, provisional, skipReceipt);
            }

            const now = Date.now();
            if (now - this.lastPayTime < 3000) return;
            this.lastPayTime = now;
            this.submitting = true; this.stockError = '';
            try {
                const holdRes = await fetch('{{ route("pos.restaurant.orders.hold") }}', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ items: this.cart, order_type: this.orderType, table_id: this.selectedTable?.id || null, customer_id: this.selectedCustomer?.id || null, customer_name: this.selectedCustomer?.name || null, customer_phone: this.selectedCustomer?.phone || null, kitchen_notes: this.kitchenNotes, priority: this.priorityOrder, recalled_order_id: this.recalledOrderId, discount_type: this.discountAmount > 0 ? this.discountType : null, discount_value: this.discountAmount > 0 ? this.discountValue : 0, discount_amount: this.discountAmount }),
                });
                if (!holdRes.ok) {
                    const bodyText = await holdRes.text().catch(() => '');
                    console.error('[holdOrder] HTTP', holdRes.status, holdRes.statusText, bodyText.slice(0, 500));
                    throw new Error('Hold HTTP ' + holdRes.status + ' ' + holdRes.statusText);
                }
                const holdData = await holdRes.json();
                if (!holdData.success) { this.showToast(holdData.message || window.TXT.failed_word, 'error'); this.submitting = false; return; }
                const savedTotal = this.totalAmount;
                await this.payHeldOrderDirect(holdData.order.id, method, savedTotal, provisional, skipReceipt);
                this.clearCart();
                // Auto-focus phone input → ready for next sale, NO dead focus.
                this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); });
            } catch (e) {
                console.error('[processPayment] FAIL', e);
                this.showToast(window.TXT.submit_failed_prefix + (e?.message || e?.name || 'unknown') + ' — check console (F12)', 'error');
            }
            this.showPayModal = false; this.submitting = false; this.saveAsProvisional = false;
        },

        // Manual-cart payment path — POSTs cart directly to pos.invoice.store
        // (PosController::storeInvoice). That endpoint has lax per-item
        // validation (only name/qty/unit_price required) and supports manual
        // lines via the `_manual: true` flag (which suppresses auto-create-as-
        // master-product in resolveItemExemptions). Returns JSON when
        // wantsJson() — same shape used by payHeldOrderDirect for receipt
        // modal rendering.
        async processPaymentManual(method, provisional = false, skipReceipt = false) {
            const now = Date.now();
            if (now - this.lastPayTime < 3000) return;
            this.lastPayTime = now;
            this.submitting = true; this.stockError = '';
            const savedTotal = this.totalAmount;
            try {
                // FbrPosController::store() expects: items[].{item_name, quantity, unit_price,
                // product_id?, hs_code?, uom?, tax_rate?, is_tax_exempt?, item_discount(Rs)?}
                // + payment_method in cash/card/bank_transfer/online, cash_received (server
                // cash guard blocks cash sales where received < total), tax_inclusive:false.
                const payload = {
                    items: this.cart.map(c => ({
                        item_name: c.item_name,
                        quantity: c.quantity,
                        unit_price: c.unit_price,
                        product_id: (c.item_type === 'product' && c.item_id) ? c.item_id : null,
                        // Services (Task 1272): ride the service id so store() resolves the
                        // AUTHORITATIVE tax_rate/is_tax_exempt from pos_services server-side
                        // (client values are only a display hint — never trusted for tax).
                        service_id: (c.item_type === 'service' && c.item_id) ? c.item_id : null,
                        hs_code: c.hs_code || null,
                        uom: c.uom || 'U',
                        tax_rate: this._itemRate(c),
                        is_tax_exempt: !!c.is_tax_exempt,
                        // store() takes ABSOLUTE Rs per-line discount (caps at line gross).
                        item_discount: this.getItemDiscount(c),
                    })),
                    payment_method: method,
                    discount_type: this.discountType || 'percentage',
                    discount_value: this.discountAmount > 0 ? this.discountValue : 0,
                    customer_id: this.selectedCustomer?.id || null,
                    customer_name: this.selectedCustomer?.name || null,
                    customer_phone: this.selectedCustomer?.phone || null,
                    // 🧾 Buyer NTN (optional B2B) — typed in the Pay modal, max 30 chars server-side.
                    customer_ntn: (this.customerNtn || '').trim() || null,
                    // Order Matching (Aug 2026): ride the token/code on the billing
                    // payload so fbr_pos_transactions.token_no / order_code are
                    // populated even when the cashier bills directly after recall
                    // (never re-held, so the token only lives in currentTokenNo).
                    token_no: this.currentTokenNo || null,
                    order_code: this.currentOrderCode || null,
                    // Cash Received / Wapsi (Jul 2026): send the cashier's entered amount
                    // when it covers the total (server stores change_due for the receipt);
                    // otherwise fall back to exact total so the server cash-guard passes.
                    cash_received: method === 'cash' ? ((parseFloat(this.cashReceived) || 0) >= savedTotal ? parseFloat(this.cashReceived) : savedTotal) : 0,
                    tax_inclusive: false,
                    // PROVISIONAL BILL FLOW — when true, store() forces invoice_mode='local'
                    // + fbr_status='local' and skips FBR submission. Promote later via F10.
                    save_as_provisional: !!provisional,
                    // Task 156: order-type + delivery-address snapshot — Pending
                    // Deliveries panel filters provisionals to order_type='delivery'.
                    // Address comes from the selected customer's saved address
                    // (frozen on the bill server-side; delivery orders only).
                    order_type: this.orderType || null,
                    // Task 163: cashier-picked/typed address snapshot; falls back to the
                    // customer's saved address if the picker was never touched/loaded.
                    delivery_address: this.orderType === 'delivery' ? ((this.selectedDeliveryAddress || '').trim() || (this.selectedCustomer?.address || '').trim() || null) : null,
                    // Idempotency key — same UUID reused on every retry of this bill;
                    // regenerated only after clearCart() (confirmed success or void).
                    // Server replay-guard returns the existing bill if UUID matches.
                    offline_uuid: this.billUuid || (this.billUuid = this._newBillUuid()),
                    // Task 1271: settling a recalled draft — the server re-claims
                    // and consumes the draft ATOMICALLY (409 draft_conflict if a
                    // second cashier took it after our lock lapsed).
                    draft_id: this.activeDraftId || null,
                };
                // ── OFFLINE-FIRST: no internet at Pay time → queue locally ──
                // (plan-gated; the queued bill replays via auto-sync when net returns)
                if (!navigator.onLine) {
                    // Task 1271: a recalled draft must NEVER settle offline — the
                    // server-side one-winner consume can't run, so a queued bill
                    // could later 409 against a competing settlement AFTER the
                    // customer already walked away with a receipt. Cart + draft
                    // stay attached; the cashier retries when the net returns.
                    if (this.activeDraftId) {
                        this.showToast(window.TXT.draft_offline_block, 'error');
                        this.showPayModal = false; this.submitting = false;
                        return;
                    }
                    if (!this.offlineAllowed) {
                        this.showToast(window.TXT.offline_plan_locked, 'error');
                        this.showPayModal = false; this.submitting = false;
                        return;
                    }
                    await this.queueOfflineBill(payload, method, savedTotal, skipReceipt);
                    this.showPayModal = false; this.submitting = false; this.saveAsProvisional = false;
                    return;
                }
                let res;
                try {
                    res = await fetch('{{ route("fbrpos.store") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify(payload),
                    });
                } catch (netErr) {
                    // Server unreachable (wifi up, internet down) — same offline path.
                    // Task 1271: recalled drafts never queue offline (see above).
                    if (this.activeDraftId) {
                        this.showToast(window.TXT.draft_offline_block, 'error');
                        this.showPayModal = false; this.submitting = false;
                        return;
                    }
                    if (!this.offlineAllowed) {
                        this.showToast(window.TXT.offline_plan_locked, 'error');
                        this.showPayModal = false; this.submitting = false;
                        return;
                    }
                    await this.queueOfflineBill(payload, method, savedTotal, skipReceipt);
                    this.showPayModal = false; this.submitting = false; this.saveAsProvisional = false;
                    return;
                }
                let data = null;
                let rawBody = '';
                try { rawBody = await res.text(); data = JSON.parse(rawBody); } catch(_) {}
                if (!res.ok || !data || !data.success) {
                    console.error('[storeInvoice] HTTP', res.status, res.statusText, rawBody.slice(0, 500));
                    // Task 1271: draft claim lost (409) — another cashier recalled/
                    // billed this draft. Detach it (cart stays for review) so a
                    // retry can't double-bill; cashier re-recalls deliberately.
                    if (data && data.draft_conflict) {
                        this.activeDraftId = null;
                        this.stopDraftLockRenewal();
                        await this.loadDrafts().catch(() => {});
                    }
                    const msg = (data && (data.message || data.error)) || ('Failed (HTTP ' + res.status + ') — F12 console');
                    this.showToast(msg, 'error');
                    this.submitting = false;
                    return;
                }
                // Mirror payHeldOrderDirect success path so receipt modal works.
                console.log('[storeInvoice] OK response:', data);
                console.log('[storeInvoice] isRestaurantMode=', this.isRestaurantMode, 'transaction_id=', data.transaction_id);
                this.lastIsOffline = false;
                this.lastOfflineRec = null;
                this.lastInvoiceNumber = data.invoice_number || '';
                this.lastTransactionId = data.transaction_id || null;
                // Order Matching (Aug 2026): set lastOrderId to the transaction id so
                // the post-pay KOT button (K key) can trigger printKitchenTicket →
                // /fbr-pos/transaction/{id}/kot-reprint. isFbrHeld=false path used.
                this.lastOrderId = data.transaction_id || null;
                this.lastTotal = savedTotal || data.total_amount || 0;
                this.lastPaymentMethod = method;
                this.lastFbrNumber = data.fbr_invoice_number || '';
                this.lastFbrStatus = data.fbr_status || '';
                this.lastItemsCount = (this.cart || []).reduce((s, i) => s + (parseFloat(i.quantity) || 0), 0);
                this.lastSaleAt = Date.now();
                this.setWaBill(data); // Task 1271: WhatsApp Bill button/auto-open
                this.consumeActiveDraft(); // recalled draft is now billed — drop the draft row
                // ── Push to Akhri Bills strip (keep last 5) ──────────────────
                if (data.transaction_id && data.invoice_number) {
                    this.recentBills = [{ id: data.transaction_id, invoice_number: data.invoice_number, total: savedTotal, method }].concat(this.recentBills).slice(0, 5);
                }
                // store() returns a `warning` when the sale saved but FBR submission is
                // pending/queued (auto-retry engine) — surface it without blocking the flow.
                if (data.warning) { this.showToast(data.warning, 'error'); }
                // Layout header badges (pending FBR count) listen for this event.
                window.dispatchEvent(new CustomEvent('fbr-bills-refresh'));
                this.showReceipt = true;
                this.scheduleReceiptAutoClose();
                this.startFbrPoll(); // Task 655: fiscal_device 'pending' → badge + receipt auto-flip
                this.$nextTick(() => { setTimeout(() => this.triggerConfetti(), 300); });
                // Auto-print receipt + KOT for FBR bills.
                // Held row is deleted on recall, so post-pay KOT uses the TRANSACTION
                // reprint endpoint (isFbrHeld=false). Passing the transaction_id (not null)
                // lets autoKotEnabled=true fire when kitchen_printer is on.
                this.runAutoPrintChain(data.transaction_id || null, /* isFbrHeld= */ false, skipReceipt);
                this.clearCart();
                this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); });
                // Refresh provisional badge count if this save was provisional.
                if (provisional) { this.loadLocalBills(); }
                // Refresh failed badge — successful sales might leave a previous fail intact.
                this.loadFailedBills();
                // Net wapis hai aur queue mein bills parhe hain? Drain now.
                if (this.offlineQueueCount > 0) this.syncOfflineBills();
            } catch (e) {
                console.error('[processPaymentManual] FAIL', e);
                this.showToast(window.TXT.manual_pay_failed + (e?.message || e?.name || 'unknown') + ' — F12 console', 'error');
            }
            this.showPayModal = false;
            this.submitting = false;
            this.saveAsProvisional = false;
        },

        payingHeldOrderId: null,

        async payHeldOrder(orderId) {
            // FBR flow: recall the parked cart back into the live cart, then open
            // the normal Pay modal — payment goes through processPaymentManual →
            // fbrpos.store (no restaurant pay-order endpoint in FBR POS).
            if (this.submitting) return;
            const order = this.heldOrders.find(o => o.id === orderId);
            if (!order) return;
            const ok = await this.recallOrder(order);
            if (!ok) return;
            this.stockError = '';
            this.payMethodIndex = 0;
            this.showPayModal = true;
        },

        startNewAfterPayment() {
            this.showReceipt = false;
            this.clearCart();
            this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); this.$refs.customerPhoneInput?.select(); });
        },

        // Cancelable timer registry — prevents stray prints firing after the cashier closes
        // the receipt modal mid-sequence. Mirrors restaurant POS engine.
        queuePrintTimer(fn, delay) {
            const id = setTimeout(() => {
                this.pendingPrintTimers = this.pendingPrintTimers.filter(t => t !== id);
                fn();
            }, delay);
            this.pendingPrintTimers.push(id);
            return id;
        },
        cancelPendingPrints() {
            // Bumping the session epoch invalidates any in-flight iframe.onload / afterprint
            // callbacks captured under the prior epoch.
            this.printSessionId++;
            this.pendingPrintTimers.forEach(id => clearTimeout(id));
            this.pendingPrintTimers = [];
            // Remove any postMessage listeners attached by _printViaIframe — prevents
            // long-session listener accumulation across many sales.
            this.printMessageHandlers.forEach(h => {
                try { window.removeEventListener('message', h); } catch (e) {}
            });
            this.printMessageHandlers = [];
            ['print-receipt-frame', 'print-kot-frame'].forEach(id => {
                const fr = document.getElementById(id);
                if (fr) { fr.onload = null; }
            });
        },

        // Hidden-iframe print engine — postMessage-based chain.
        // Receipt template (`/pos/restaurant/receipt/...?auto_print=1&_signal=...`) calls
        // window.print() in its own onload then postMessage('pos_print_done') to parent.
        // Parent waits for the signal before chaining the next print, so KOT NEVER fires
        // before the receipt dialog is dismissed (Chrome blocks parent JS during print).
        _printViaIframe(frameId, url, popupSpec, onAfterPrint) {
            const sessionAtCall = this.printSessionId;
            const isStale = () => sessionAtCall !== this.printSessionId;
            const signal = frameId + '_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
            let frame = document.getElementById(frameId);
            if (!frame) {
                frame = document.createElement('iframe');
                frame.id = frameId;
                frame.style.cssText = 'position:fixed;width:0;height:0;border:none;left:-9999px;top:-9999px;';
                document.body.appendChild(frame);
            }
            const removeHandler = () => {
                window.removeEventListener('message', messageHandler);
                this.printMessageHandlers = this.printMessageHandlers.filter(h => h !== messageHandler);
            };
            const fireOnce = (() => {
                let invoked = false;
                return () => {
                    if (invoked) return;
                    invoked = true;
                    removeHandler();
                    if (isStale()) return;
                    if (typeof onAfterPrint === 'function') onAfterPrint();
                };
            })();
            const messageHandler = (e) => {
                if (!e.data || e.data.type !== 'pos_print_done' || e.data.signal !== signal) return;
                if (isStale()) { removeHandler(); return; }
                this.queuePrintTimer(fireOnce, 50);
            };
            window.addEventListener('message', messageHandler);
            this.printMessageHandlers.push(messageHandler);
            // Hard ceiling — if iframe never signals (load failure / exotic printer driver),
            // advance the chain after 30s so the cashier isn't stuck.
            this.queuePrintTimer(fireOnce, 30000);
            const cacheBustedUrl = url
                + (url.includes('?') ? '&' : '?')
                + '_t=' + Date.now()
                + '&_signal=' + encodeURIComponent(signal);
            frame.onload = () => {
                if (isStale()) return;
                // Iframe's own window.onload fires print() + posts the done signal.
            };
            frame.src = cacheBustedUrl;
        },

        // ═══ Task 1263: silent print enqueue (twin of PRA trySilentPrint) ═══
        // POSTs a PosPrintJob to the FBR panel endpoint; one retry on 5xx /
        // network blips. Resolves the response payload (truthy) on success so
        // callers can read flags like `deduped`; false keeps the iframe fallback.
        async trySilentPrint(payload, _retry = true) {
            try {
                const res = await fetch('/fbr-pos/api/print-jobs', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify(payload),
                });
                if (!res.ok) {
                    // One retry on server hiccups (5xx) — a lost print job means a
                    // bill that never comes out of the printer.
                    if (_retry && res.status >= 500) { await new Promise(r => setTimeout(r, 1200)); return this.trySilentPrint(payload, false); }
                    return false;
                }
                const d = await res.json().catch(() => null);
                return (d && d.success) ? d : false;
            } catch (e) {
                if (_retry) { await new Promise(r => setTimeout(r, 1200)); return this.trySilentPrint(payload, false); }
                return false;
            }
        },

        async printReceipt(onAfterPrint) {
            if (!this.lastTransactionId) { if (typeof onAfterPrint === 'function') onAfterPrint(); return; }
            // Task 655: fiscal_device grace — bill abhi 'pending' hai to chand
            // seconds ka bounded intezar (submit aa jaye to PEHLI slip par hi FBR
            // fiscal number chapta hai), warna jo bhi haalat hai usi par print.
            await this.fbrPrintGrace();
            const url = '/fbr-pos/transaction/' + this.lastTransactionId + '/receipt?auto_print=1';
            console.log('[printReceipt] URL=', url, 'isRestaurantMode=', this.isRestaurantMode);
            const fallback = () => this._printViaIframe('print-receipt-frame', url, 'width=400,height=700', onAfterPrint);
            // Task 1263: silent-first — enqueue a Desktop Agent print job; the
            // iframe/popup path stays the fallback when the queue is unreachable.
            if (this.silentBillPrint) {
                const ok = await this.trySilentPrint({ type: 'fbr_bill', transaction_id: this.lastTransactionId });
                if (ok) {
                    // deduped = this bill is ALREADY on its way to the printer
                    // (double-press guard) — tell the cashier to wait, no 2nd copy.
                    if (ok.deduped) this.showToast(window.TXT.receipt_already_printing, 'info');
                    else this.showToast(window.TXT.receipt_sent_to_printer, 'success');
                    if (typeof onAfterPrint === 'function') onAfterPrint();
                } else { fallback(); }
                return;
            }
            fallback();
        },

        // Silent KOT print via hidden iframe — no popup window blocks the cashier screen.
        // isFbrHeld=true  → orderId is an FbrPosHeldSale id → use FBR held-KOT URL.
        // isFbrHeld=false + lastOrderId set → post-pay reprint from transaction.
        // Falls back to PRA /pos/restaurant/... URL only for PRA restaurant companies
        // (not reached in FBR POS since isRestaurantMode is always false there).
        printKitchenTicket(orderId, onAfterPrint, isFbrHeld) {
            if (isFbrHeld) {
                // FBR held-sale KOT (Send to Kitchen / F5 → hold → print).
                const id = orderId;
                if (!id) { if (typeof onAfterPrint === 'function') onAfterPrint(); return; }
                const url = '/fbr-pos/held/' + id + '/kitchen-ticket?auto_print=1';
                const fallback = () => this._printViaIframe('print-kot-frame', url, 'width=350,height=600', onAfterPrint);
                // Task 1263: silent-first via the Desktop Agent, iframe fallback.
                if (this.silentKotPrint) {
                    this.trySilentPrint({ type: 'fbr_kot', restaurant_order_id: id }).then(ok => {
                        if (ok) {
                            this.showToast(window.TXT.fbr_store_slip_sent_to_printer, 'success');
                            if (typeof onAfterPrint === 'function') onAfterPrint();
                        } else { fallback(); }
                    });
                    return;
                }
                fallback();
                return;
            }
            const id = orderId || this.lastOrderId;
            if (!id) { if (typeof onAfterPrint === 'function') onAfterPrint(); return; }
            // FBR post-payment KOT reprint (K key / post-pay button): lastOrderId is
            // set to the transaction id after billing (see processPaymentManual).
            // Use /fbr-pos/transaction/{id}/kot-reprint when in FBR context.
            const isFbrReprint = !this.isRestaurantMode;
            const url = isFbrReprint
                ? '/fbr-pos/transaction/' + id + '/kot-reprint?auto_print=1'
                : '/pos/restaurant/orders/' + id + '/kitchen-ticket?auto_print=1';
            const fallback = () => this._printViaIframe('print-kot-frame', url, 'width=350,height=600', onAfterPrint);
            // Task 1263: silent-first for the FBR reprint path only (the PRA
            // restaurant URL is never reached from the FBR sale screen).
            if (isFbrReprint && this.silentKotPrint) {
                this.trySilentPrint({ type: 'fbr_kot', transaction_id: id }).then(ok => {
                    if (ok) {
                        this.showToast(window.TXT.fbr_store_slip_sent_to_printer, 'success');
                        if (typeof onAfterPrint === 'function') onAfterPrint();
                    } else { fallback(); }
                });
                return;
            }
            fallback();
        },

        // Print invoice → KOT in strict order. Used by auto-print on successful pay.
        // Receipt ALWAYS prints first; KOT chains after receipt's print dialog closes.
        //
        // ✅ FIX (May-07): MASTER SWITCH — `autoPrintEnabled` now gates EVERYTHING.
        // Previously KOT could auto-print even when receipt auto-print was OFF (because
        // `autoKotEnabled` was an independent gate). Cashier complaint: turned auto-print
        // off, KOT still fired. Now both require master `autoPrintEnabled = true`.
        //
        // ✅ FIX (May-07): Tightened gap between receipt-finish → KOT-start (300ms → 80ms)
        // and initial chain start (400ms → 150ms) to feel snappier on thermal printers.
        // ═══ Task 565 (port of PRA universal): opt-in Yes/No print-confirm ═══
        // openPrintConfirm: dialog foran (koi artificial delay nahi), Yes-action
        // pending. Focus setTimeout se ($nextTick nahi — post-sale code
        // customerPhoneInput ko $nextTick par focus karta hai, Yes baad mein jeete).
        openPrintConfirm(onYes, onNo = null) {
            this.printConfirmAction = onYes;
            // Task 1025 (port): optional "No" action — auto-print chain isse
            // KOT-only re-entry deta hai (No = sirf customer bill skip).
            this.printConfirmNoAction = onNo;
            this.printConfirmChoice = 'yes';
            this.showPrintConfirm = true;
            setTimeout(() => { try { if (this.showPrintConfirm) this.$refs.printConfirmYes?.focus(); } catch (err) {} }, 50);
        },
        // resolvePrintConfirm: Yes → pending action (confirmed chain / offline
        // receipt) mojooda timings ke saath. No → sirf CUSTOMER RECEIPT skip:
        // caller ka onNo (auto-print chain deta hai) chalta hai — KOT apne
        // mojooda gates se phir bhi nikalti hai (Task 1025 port: counter sale
        // par kitchen ko ticket chahiye). onNo ke baghair (offline receipt path)
        // No = kuch nahi khulta. Focus wapas sale screen par taake shortcuts
        // zinda rahen.
        resolvePrintConfirm(yes) {
            if (!this.showPrintConfirm) return;
            const action = this.printConfirmAction;
            const noAction = this.printConfirmNoAction;
            this.showPrintConfirm = false;
            this.printConfirmAction = null;
            this.printConfirmNoAction = null;
            this.$nextTick(() => {
                try {
                    const ae = document.activeElement;
                    if (ae && typeof ae.blur === 'function') ae.blur();
                    window.focus();
                    this.$refs.customerPhoneInput?.focus();
                } catch (err) {}
            });
            if (yes && typeof action === 'function') { action(); return; }
            if (!yes && typeof noAction === 'function') noAction();
        },
        // Task 520 (port of Task 514): per-bill checkbox ka default — FBR POS par
        // auto-print master switch ka mirror (koi dine-in variant nahi).
        billPrintDefault() {
            return !!this.autoPrintEnabled;
        },
        // skipReceiptOverride (Task 520, port of Task 514): cashier ne per-bill
        // "Receipt print karein" checkbox UNTICK kiya — SIRF is bill ki receipt
        // auto-print skip; KOT gate / FBR submission / receipt popup sab untouched.
        runAutoPrintChain(orderId, isFbrHeld, skipReceiptOverride = false, askConfirmed = false) {
            // MASTER GATE — auto-print OFF means NOTHING fires automatically.
            if (!this.autoPrintEnabled) return;
            const hasReceipt = !!this.lastTransactionId;
            const wantsKot = !!this.autoKotEnabled && !!orderId;
            const wantsReceipt = hasReceipt && !skipReceiptOverride;
            if (!wantsReceipt && !wantsKot) return;
            // Task 565: opt-in Yes/No confirm — kuch print hone WALA hai aur flag
            // ON hai to pehle poocho (foran, koi delay nahi). Yes = YEHI chain
            // confirmed re-entry se (FBR par silent branch nahi — Yes seedha
            // iframe chain, 150ms/80ms timings waisi hi).
            // Task 1025 (port): sawaal SIRF customer receipt ka hai — "No" par
            // KOT apne mojooda gates se phir bhi fire hoti hai (skip-receipt
            // re-entry). Receipt banti hi nahi (wantsReceipt false) to poochte
            // bhi nahi — KOT-only chain seedha chalti hai.
            if (this.printConfirmAsk && !askConfirmed && wantsReceipt) {
                this.openPrintConfirm(
                    () => this.runAutoPrintChain(orderId, isFbrHeld, skipReceiptOverride, true),
                    () => this.runAutoPrintChain(orderId, isFbrHeld, true, true),
                );
                return;
            }
            // isFbrHeld distinguishes held-sale KOT (uses /fbr-pos/held/{id}/kitchen-ticket)
            // from completed-transaction reprint (uses /fbr-pos/transaction/{id}/kot-reprint).
            // Always pass explicitly so printKitchenTicket never guesses the ID type.
            this.$nextTick(() => {
                if (wantsReceipt && wantsKot) {
                    this.queuePrintTimer(() => {
                        this.printReceipt(() => {
                            this.queuePrintTimer(() => this.printKitchenTicket(orderId, null, isFbrHeld), 80);
                        });
                    }, 150);
                } else if (wantsReceipt) {
                    this.queuePrintTimer(() => this.printReceipt(), 150);
                } else if (wantsKot) {
                    // Pathological case: no transaction (so no receipt possible) but KOT requested.
                    this.queuePrintTimer(() => this.printKitchenTicket(orderId, null, isFbrHeld), 150);
                }
            });
        },

        // ─── PROVISIONAL BILLS API helpers ──────────────────────────────────
        // Lightweight fetch + inline action methods. All errors degrade to a
        // toast — modal stays open so cashier doesn't lose context.
        async loadLocalBills() {
            this.localBillsLoading = true;
            try {
                const res = await fetch('{{ route('fbrpos.api.provisional-bills') }}', {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                });
                // 🔒 403 pin_required → show PIN gate inside the F10 modal.
                if (res.status === 403) {
                    const d = await res.json().catch(() => null);
                    if (d && d.pin_required) {
                        this.localPinRequired = true;
                        this.localBills = [];
                        if (this.showLocalBills) {
                            setTimeout(() => document.querySelector('input[name="pos_local_pin_nofill"]')?.focus(), 150);
                        }
                    }
                    this.localBillsLoading = false; return;
                }
                if (!res.ok) { this.localBillsLoading = false; return; }
                const data = await res.json();
                if (data && data.success) {
                    this.localPinRequired = false;
                    this.localBills = data.bills || [];
                    // Task 517: unassigned final deliveries + riders for the popup dropdown.
                    this.finalDeliveryBills = data.final_deliveries || [];
                    this.deliveryRiders = data.riders || [];
                    this.canAssignRider = !!data.can_assign_rider;
                    if (data.business_today) this.bizToday = data.business_today;
                    if (this.activeLocalIndex >= this.filteredLocalBills().length) {
                        this.activeLocalIndex = Math.max(0, this.filteredLocalBills().length - 1);
                    }
                }
            } catch (e) { console.warn('loadLocalBills error', e); }
            this.localBillsLoading = false;
        },
        // 🔒 Verify confidential PIN (30-min session window server-side).
        async verifyLocalPin() {
            if (this.localPinVerifying) return;
            this.localPinVerifying = true; this.localPinError = '';
            try {
                const res = await fetch('{{ route('fbrpos.api.verify-pin') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ pin: this.localPinInput }),
                });
                const data = await res.json().catch(() => null);
                if (data && data.success) {
                    this.localPinRequired = false; this.localPinInput = ''; this.localPinError = '';
                    this.showToast(window.TXT.pin_verified_unlocked, 'success');
                    this.loadLocalBills();
                } else {
                    this.localPinError = (data && data.message) || 'Incorrect PIN.';
                    this.localPinInput = '';
                }
            } catch (e) { this.localPinError = 'Network error — try again.'; }
            this.localPinVerifying = false;
        },
        // ── QUICK RETURN (Task 685) — FBR twin of PRA universal ─────────────
        // Bill/serial number (FPOS-2026-00012, bare digits, ya FBR fiscal
        // number) → server lookup → existing FBR return form. Sab rules
        // SERVER par (FbrPosPhase2Controller) — yeh sirf navigate karta hai.
        openQuickReturn() {
            if (this.showPayModal || this.showReceipt || this.showHeldOrders || this.showQuickType || this.showManualItem || this.showCustomerPicker || this.showShortcuts || this.showManagerPinModal || this.showLocalBills || this.showFailedBills || this.showPendingDeliveries || this.showTablePicker || this.tableSwitchPrompt) return;
            this.quickReturnQ = '';
            this.quickReturnErr = '';
            this.quickReturnBusy = false;
            this.quickReturnOpen = true;
            this.$nextTick(() => { document.getElementById('tn-quick-return-input')?.focus(); });
        },
        submitQuickReturn() {
            const q = (this.quickReturnQ || '').trim();
            if (!q || this.quickReturnBusy) return;
            this.quickReturnBusy = true;
            this.quickReturnErr = '';
            // Relative URL (memory: route absolute-https trap on plain-http dev).
            fetch('{{ route('fbrpos.phase2.return.lookup', [], false) }}?q=' + encodeURIComponent(q), {
                headers: { 'Accept': 'application/json' }
            }).then(r => r.json().then(d => ({ ok: r.ok, d })))
              .then(({ ok, d }) => {
                if (ok && d && d.url) {
                    window.location.href = d.url;
                    return; // busy stays true — page is navigating
                }
                this.quickReturnBusy = false;
                this.quickReturnErr = (d && d.error) ? d.error : window.TXT.network_error;
            }).catch(() => {
                this.quickReturnBusy = false;
                this.quickReturnErr = window.TXT.network_error;
            });
        },
        openLocalBills() {
            this.activeLocalIndex = 0;
            this.localSearch = '';
            this.showLocalBills = true;
            this.loadLocalBills();
            this.$nextTick(() => { const el = this.$refs.localSearchInput; if (el) el.focus(); });
        },
        // ─── PENDING DELIVERIES panel (Task 122 — FBR port of PRA Task 114) ──
        // TODAY's business-day delivery provisionals only. fbr_pos_transactions
        // now stores order_type (Task 156) — new provisionals carry their type,
        // so the filter self-tightens to real deliveries; legacy null-typed
        // bills stay included until they clear. Date scope: API falls back to
        // created_at's date when business_date is missing, so old confidential
        // provisionals never flood the badge.
        pendingDeliveryBills() {
            const isToday = b => (!this.bizToday || !b.business_date || b.business_date === this.bizToday);
            const prov = this.localBills.filter(b => (b.order_type == null || b.order_type === 'delivery') && isToday(b));
            // Task 521 (PRA parity): assigned/dispatched + delivered-cash-unsettled
            // finals show for TODAY; UNASSIGNED bills (rider NULL + status NULL)
            // ride the 7-din server window like the Deliveries board — today-filter
            // unpar nahi lagta, warna kal ka bina-rider bill popup se ghayab ho jata.
            // Task 524: purani (pichhle business days ki) unassigned bills MAIN
            // list/badge se bahar — woh neeche staleDeliveryBills() ke collapsed
            // group mein hain. Flag SERVER par banta hai (business_date < aaj ka
            // business day); yahan sirf parha jata hai.
            const finals = (this.finalDeliveryBills || []).filter(b => !b.is_stale_unassigned && (isToday(b) || (!b.rider_id && !b.delivery_status)));
            return [...prov, ...finals];
        },
        // Task 524: purane (day-close ho chuke dinon ke) UNASSIGNED delivery
        // bills — popup mein alag collapsed "Purani deliveries" group, badge ki
        // ginti mein KABHI shamil nahi.
        staleDeliveryBills() {
            return (this.finalDeliveryBills || []).filter(b => b.is_stale_unassigned);
        },
        openPendingDeliveries() {
            this.showPendingDeliveries = true;
            this.loadLocalBills();
        },
        // ─── Rider assign from the panel (Task 517 — FBR port of PRA Task 513) ─
        // UNASSIGNED final delivery bill par dropdown se rider chuno — reuses
        // POST /fbr-pos/deliveries/{id}/assign (same backend as the Deliveries
        // board; koi naya path nahi). Success = list refresh.
        async assignRider(bill, riderId) {
            if (!bill || !riderId || this.riderAssignBusyId) return;
            this.riderAssignBusyId = bill.id;
            try {
                const res = await fetch('{{ url('/fbr-pos/deliveries') }}/' + bill.id + '/assign', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ rider_id: riderId }),
                });
                const data = await res.json().catch(() => null);
                if (res.ok && data && data.success) {
                    this.showToast(@json(__('pos.rider_assign_ok')), 'success');
                    this.loadLocalBills();
                } else {
                    this.showToast((data && data.message) || @json(__('pos.rider_assign_failed')), 'error');
                }
            } catch (e) {
                this.showToast(window.TXT.network_error, 'error');
            }
            this.riderAssignBusyId = null;
        },
        // ─── Delivered mark from the panel (Task 521 — PRA parity) ──────────
        // FINAL delivery bill ko panel se Delivered mark karna — reuses POST
        // /fbr-pos/deliveries/{id}/status (JSON). Promote yahan kabhi nahi
        // chalta: bill pehle se final hai, sirf delivery status badalta hai.
        async markFinalDelivered(bill) {
            if (!bill || !bill.is_final || this.deliveryFinalBusyId) return;
            this.deliveryFinalBusyId = bill.id;
            try {
                const res = await fetch('{{ url('/fbr-pos/deliveries') }}/' + bill.id + '/status', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ delivery_status: 'delivered' }),
                });
                const data = await res.json().catch(() => null);
                if (res.ok && data && data.success) {
                    bill.delivery_status = 'delivered';
                    this.showToast(@json(__('pos.marked_delivered_ok')), 'success');
                    // Card bill delivered = khata par nahi → refresh par list se nikal jata hai.
                    this.loadLocalBills();
                } else {
                    this.showToast((data && data.message) || @json(__('pos.status_update_failed')), 'error');
                }
            } catch (e) {
                this.showToast(window.TXT.network_error, 'error');
            }
            this.deliveryFinalBusyId = null;
        },
        // ─── Rider WHOLE-khata settle from the panel (Task 521 — PRA parity) ─
        // Reuses POST /fbr-pos/riders/{id}/settle with settle_all — settles EVERY
        // unsettled cash bill on the rider's khata (all dates), not just this
        // bill. Riders never touch invoice_mode/serials.
        txtRiderSettleScope(bill) {
            if (!bill || !bill.rider_open_count) return '';
            return @json(__('pos.rider_settle_scope'))
                .replace(':count', bill.rider_open_count)
                .replace(':amount', Number(bill.rider_open_amount || 0).toLocaleString());
        },
        settleRider(bill) {
            if (!bill || !bill.rider_id || this.riderSettleBusyId) return;
            // Task 543 (upgrades Task 532's window.prompt): styled inline modal —
            // deliveries.blade.php parity: default = whole baqaya, live "Baqaya:"
            // line, over-amount disables confirm. Backend re-validates everything.
            this.riderSettleOutstanding = Math.round(Number(bill.rider_open_amount || 0) * 100) / 100;
            this.riderSettleAmount = this.riderSettleOutstanding > 0 ? String(this.riderSettleOutstanding) : '';
            this.riderSettleBill = bill;
            // Root-level modal input — focus via document (x-for row $refs scope trap)
            this.$nextTick(() => { const el = document.getElementById('rider-settle-amount'); if (el) { el.focus(); el.select(); } });
        },
        async submitRiderSettle() {
            const bill = this.riderSettleBill;
            if (!bill || this.riderSettleBusyId) return;
            const outstanding = this.riderSettleOutstanding;
            const received = parseFloat(String(this.riderSettleAmount).replace(/,/g, ''));
            if (!isFinite(received) || received <= 0) {
                this.showToast(@json(__('pos.settle_amount_min_err')), 'error');
                return;
            }
            if (received > outstanding + 0.009) {
                this.showToast(@json(__('pos.settle_amount_over_err')).replace(':max', outstanding.toLocaleString()), 'error');
                return;
            }
            this.riderSettleBusyId = bill.rider_id;
            try {
                const res = await fetch('{{ url('/fbr-pos/riders') }}/' + bill.rider_id + '/settle', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify({ settle_all: 1, received_amount: received }),
                });
                const data = await res.json().catch(() => null);
                if (res.ok && data && data.success) {
                    this.showToast(data.message || @json(__('pos.rider_settled_ok')), 'success');
                    this.riderSettleBill = null; // close the settle modal
                    this.loadLocalBills(); // warning disappears on refresh
                } else {
                    this.showToast((data && data.message) || @json(__('pos.rider_settle_failed')), 'error');
                }
            } catch (e) {
                this.showToast(@json(__('pos.rider_settle_failed')), 'error');
            }
            this.riderSettleBusyId = null;
        },
        // One-click final — reuses the EXACT promote path (race-safe claim,
        // reporting-OFF invariant, PIN gate). Receipt print follows the panel's
        // own opt-in checkbox (default NO).
        async finalizeDelivery(bill, method) {
            if (!bill || this.deliveryFinalBusyId) return;
            this.deliveryFinalBusyId = bill.id;
            try {
                await this.promoteProvisional(bill, method, true);
            } finally {
                this.deliveryFinalBusyId = null;
            }
            // Task 984: promote localBills se bill hata deta hai par lists refresh
            // NAHI karta tha — freshly-final unassigned bill kabhi apne rider
            // dropdown / "Delivered (bina rider)" ke saath wapas nahi aata tha
            // aur popup chupchaap band ho jata tha. Pehle refresh, PHIR empty-check.
            await this.loadLocalBills();
            if (this.pendingDeliveryBills().length === 0 && this.staleDeliveryBills().length === 0) this.showPendingDeliveries = false;
        },
        // Filtered view of localBills — matches invoice number, customer name,
        // phone, or delivery address. ALL list rendering + keyboard nav MUST go
        // through this (never raw localBills) or index-based actions hit the wrong bill.
        filteredLocalBills() {
            const q = (this.localSearch || '').toLowerCase().trim();
            if (!q) return this.localBills;
            return this.localBills.filter(b =>
                (b.invoice_number || '').toLowerCase().includes(q) ||
                (b.customer_name || '').toLowerCase().includes(q) ||
                (b.customer_phone || '').toLowerCase().includes(q) ||
                (b.delivery_address || '').toLowerCase().includes(q)
            );
        },
        async deleteProvisional(bill) {
            if (!bill) return;
            if (!confirm(window.TXT.delete_provisional_bill_q + (bill.invoice_number || '#' + bill.id) + '?\nThis cannot be undone.')) return;
            try {
                const res = await fetch('{{ url('/fbr-pos/api/provisional-bills') }}/' + bill.id + '/delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                });
                const data = await res.json();
                if (res.status === 403 && data && data.pin_required) {
                    // 🔒 PIN session expired mid-modal — show the gate again.
                    this.localPinRequired = true;
                    setTimeout(() => document.querySelector('input[name="pos_local_pin_nofill"]')?.focus(), 150);
                    return;
                }
                if (data && data.success) {
                    this.localBills = this.localBills.filter(b => b.id !== bill.id);
                    if (this.activeLocalIndex >= this.filteredLocalBills().length) this.activeLocalIndex = Math.max(0, this.filteredLocalBills().length - 1);
                    if (this.localBills.length === 0) { this.showLocalBills = false; this.activeLocalIndex = 0; }
                    this.showToast(window.TXT.provisional_bill_deleted, 'success');
                } else {
                    this.showToast((data && data.message) || window.TXT.delete_failed, 'error');
                }
            } catch (e) { console.error('deleteProvisional', e); this.showToast(window.TXT.network_error, 'error'); }
        },
        // Task 122: optional `method` (cash/card) + `quick` flag for the Pending
        // Deliveries one-click Final — quick path skips the confirm() prompt and
        // the fbrEnabled JS gate (server handles reporting-OFF correctly: bill
        // becomes a fbr/NULL FINAL, never a stuck 'pending').
        async promoteProvisional(bill, method = null, quick = false) {
            if (!bill) return;
            if (!quick) {
                if (!this.fbrEnabled) { this.showToast(window.TXT.fbr_reporting_disabled_enable, 'error'); return; }
                if (!confirm(window.TXT.submit_bill_q_prefix + (bill.invoice_number || '#' + bill.id) + ' to FBR as a FINAL invoice?\n\nOnce reported, the bill will be locked — no more edit or delete. Continue?')) return;
            }
            try {
                const res = await fetch('{{ url('/fbr-pos/api/provisional-bills') }}/' + bill.id + '/promote', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify(method ? { payment_method: method } : {}),
                });
                const data = await res.json();
                if (res.status === 403 && data && data.pin_required) {
                    // 🔒 PIN session expired mid-modal — show the gate again.
                    // The PIN input lives inside the F10 Local modal; quick path
                    // (Pending Deliveries panel) must swap modals to reach it.
                    if (quick) { this.showPendingDeliveries = false; this.showLocalBills = true; }
                    this.localPinRequired = true;
                    setTimeout(() => document.querySelector('input[name="pos_local_pin_nofill"]')?.focus(), 150);
                    return;
                }
                if (data && data.success) {
                    // Remove from list (no longer provisional) regardless of submitted vs queued.
                    this.localBills = this.localBills.filter(b => b.id !== bill.id);
                    if (this.activeLocalIndex >= this.filteredLocalBills().length) this.activeLocalIndex = Math.max(0, this.filteredLocalBills().length - 1);
                    if (this.localBills.length === 0) { this.showLocalBills = false; this.activeLocalIndex = 0; }
                    this.showToast(data.message || window.TXT.submitted_to_fbr, 'success');
                    // Task 122: Pending Deliveries opt-in receipt print (default NO —
                    // delivery customer is not at the counter, paper saved).
                    if (quick && this.deliveryPrintReceipt) {
                        this._printViaIframe('print-receipt-frame', '/fbr-pos/transaction/' + bill.id + '/receipt?auto_print=1', 'width=400,height=700');
                    }
                } else {
                    // Failed — refresh list so cashier sees current state.
                    this.showToast((data && data.message) || window.TXT.submit_failed, 'error');
                    this.loadLocalBills();
                }
            } catch (e) { console.error('promoteProvisional', e); this.showToast(window.TXT.network_error, 'error'); this.loadLocalBills(); }
        },

        // ─── FAILED BILLS API helpers (F11 modal) ───────────────────────────
        async loadFailedBills() {
            this.failedBillsLoading = true;
            try {
                const res = await fetch('{{ route('fbrpos.api.failed-bills') }}', {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                });
                if (!res.ok) { this.failedBillsLoading = false; return; }
                const data = await res.json();
                if (data && data.success) {
                    this.failedBills = data.bills || [];
                    // config_error bills returned separately — shown in F11 panel with
                    // "Fix FBR Settings" note; never touched by auto-sync loop.
                    this.configErrorBills = data.config_error_bills || [];
                    if (this.activeFailedIndex >= this.failedBills.length) {
                        this.activeFailedIndex = Math.max(0, this.failedBills.length - 1);
                    }
                }
            } catch (e) { console.warn('loadFailedBills error', e); }
            this.failedBillsLoading = false;
        },
        openFailedBills() {
            this.activeFailedIndex = 0;
            this.showFailedBills = true;
            this.loadFailedBills();
        },
        async retryFailed(bill) {
            if (!bill) return;
            if (!this.fbrEnabled) { this.showToast(window.TXT.fbr_reporting_disabled, 'error'); return; }
            if (bill._retrying) return;
            bill._retrying = true;
            try {
                // manual:true signals the server this is a human-initiated retry:
                //  - resets fbr_auto_retry_count to 0 (no cap check)
                //  - counter does NOT increment on failure for this call
                const res = await fetch('{{ url('/fbr-pos/api/failed-bills') }}/' + bill.id + '/retry', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ manual: true }),
                });
                const data = await res.json();
                if (data && data.success) {
                    this.failedBills = this.failedBills.filter(b => b.id !== bill.id);
                    if (this.activeFailedIndex >= this.failedBills.length) this.activeFailedIndex = Math.max(0, this.failedBills.length - 1);
                    if (this.failedBills.length === 0) { this.showFailedBills = false; this.activeFailedIndex = 0; }
                    this.showToast(data.message || window.TXT.submitted_to_fbr, 'success');
                } else {
                    bill._retrying = false;
                    this.showToast((data && data.message) || window.TXT.retry_failed, 'error');
                    this.loadFailedBills();
                }
            } catch (e) { bill._retrying = false; console.error('retryFailed', e); this.showToast(window.TXT.network_error, 'error'); this.loadFailedBills(); }
        },
        // FBR POS: failed bills CANNOT be deleted (no endpoint by design — audit
        // trail). Kept as a guarded stub so any stray caller degrades gracefully.
        deleteFailed() {
            this.showToast(window.TXT.fbr_bills_cannot_delete, 'error');
        },

        async deleteHeldOrder(orderId) {
            // Find order for friendlier confirm prompt
            const ord = this.heldOrders.find(o => o.id === orderId);
            const label = ord ? (ord.order_number || '#' + orderId) : '#' + orderId;
            // SAFETY: prevent accidental clicks / stray "D" key from blowing away a held order.
            // Without this, after delete the modal stayed open and the next Enter would recall
            // the neighbouring order — looked exactly like "delete pe order aa gaya".
            if (!confirm(window.TXT.delete_held_order_q + label + '?\nThis cannot be undone.')) return;
            try {
                const res = await fetch('{{ url("/fbr-pos/api/held") }}/' + orderId, { method: 'DELETE', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' } });
                if (!res.ok) { this.showToast(window.TXT.failed_delete_order_error + res.status + ')', 'error'); return; }
                const data = await res.json();
                if (data.success) {
                    this.heldOrders = this.heldOrders.filter(o => o.id !== orderId);
                    if (this.activeHeldIndex >= this.heldOrders.length) this.activeHeldIndex = Math.max(0, this.heldOrders.length - 1);
                    // Auto-close the modal once the list is empty, otherwise the next
                    // Enter keystroke would land on a phantom selection.
                    if (this.heldOrders.length === 0) { this.showHeldOrders = false; this.activeHeldIndex = 0; }
                    this.showToast(window.TXT.order_deleted, 'success');
                } else { this.showToast(data.message || window.TXT.failed_word, 'error'); }
            } catch (e) { console.error('Delete held order error:', e); this.showToast(window.TXT.error_deleting_order, 'error'); }
        },

        // ═══ Task 1271: WhatsApp Bill share (PRA Task 1036 port). Shared PDF is
        // the FBR invoice PDF (FBR number + Tax Asaan QR) — never PRA branding. ═══
        setWaBill(data) {
            this.lastWaPhone = (data && data.wa_phone) || null;
            this.lastShareUrl = (data && data.share_url) || null;
            this.waHighlight = false;
            // Consume the gesture-reserved tab (see reserveWaWindow). Kept on
            // window (NOT Alpine state) — proxying a Window object is unsafe.
            clearTimeout(window.__waReserveTimer);
            const w = (window.__waReservedWin && !window.__waReservedWin.closed) ? window.__waReservedWin : null;
            window.__waReservedWin = null;
            if (this.waBillEnabled && this.waBillAutoOpen && this.lastWaPhone && this.lastShareUrl) {
                if (w) {
                    // Reserved inside the pay click/Enter gesture — navigating it
                    // is never popup-blocked, so auto-open reliably works here.
                    try { w.location = this.waBillLinkFromLast(); return; } catch (e) { try { w.close(); } catch (e2) {} }
                }
                // No reserved tab — best-effort open; popup-block par button pulse
                // fallback (customize sub-label says exactly this).
                setTimeout(() => this.openWaBill(true), 150);
            } else if (w) {
                // Bill turned out not WhatsApp-able (no phone / provisional /
                // feature off server-side) — never leave a stray blank tab.
                try { w.close(); } catch (e) {}
            }
        },
        // Auto-open must be BORN inside the cashier's pay click/Enter gesture or
        // the browser popup-blocks it (a fetch callback is not a gesture): reserve
        // a blank tab now; setWaBill() navigates it to wa.me when the server
        // confirms the extras, or closes it otherwise. A watchdog closes it on
        // pay failure paths that never reach setWaBill.
        reserveWaWindow(phone) {
            if (!this.waBillEnabled || !this.waBillAutoOpen) return;
            if (window.__waReservedWin && !window.__waReservedWin.closed) { try { window.__waReservedWin.close(); } catch (e) {} }
            window.__waReservedWin = null;
            const digits = String(phone || '').replace(/\D/g, '');
            if (digits.length < 10) return; // no routable-looking number → no blank-tab flash
            try { window.__waReservedWin = window.open('about:blank', '_blank'); } catch (e) { window.__waReservedWin = null; }
            if (window.__waReservedWin) {
                clearTimeout(window.__waReserveTimer);
                window.__waReserveTimer = setTimeout(() => {
                    if (window.__waReservedWin && !window.__waReservedWin.closed) { try { window.__waReservedWin.close(); } catch (e) {} }
                    window.__waReservedWin = null;
                }, 12000);
            }
        },
        waBillMessage(number, total, url) {
            return '*' + (this.waShopName || '') + '*\n'
                + window.TXT.invoice_word + ': ' + (number || '') + '\n'
                + window.TXT.total_word + ': Rs ' + Number(total || 0).toLocaleString() + '\n'
                + window.TXT.wa_msg_receipt + ': ' + url + '\n'
                + window.TXT.wa_msg_thanks;
        },
        waBillLinkFromLast() {
            if (!this.lastWaPhone || !this.lastShareUrl) return null;
            const msg = this.waBillMessage(this.lastFbrNumber || this.lastInvoiceNumber, this.lastTotal, this.lastShareUrl);
            return 'https://wa.me/' + this.lastWaPhone + '?text=' + encodeURIComponent(msg);
        },
        openWaBill(auto = false) {
            const link = this.waBillLinkFromLast();
            if (!link) return;
            const w = window.open(link, '_blank');
            if (!w) {
                // Popup blocked (auto-open from a fetch callback usually is) —
                // highlight the button; the cashier's own click always works.
                this.waHighlight = true;
                if (!auto) this.showToast(window.TXT.wa_popup_blocked, 'error');
                return;
            }
            this.waHighlight = false;
        },

        // ═══ Task 1271: PER-USER grid visibility (PRA Task 1207 port — FBR:
        // PRODUCTS only; services have no pref rows and stay visible). User pref
        // OVERRIDES admin show_on_sale in BOTH directions, for THIS user's grid
        // only. Search is NEVER filtered by these prefs. Keys: "product:12". ═══
        isItemVisible(i) {
            const type = i._type || i.type || 'product';
            if (type !== 'product') return true; // FBR: services always visible
            const key = 'product:' + i.id;
            if (this.userGridPrefs[key] !== undefined) return this.userGridPrefs[key] == 1;
            return i.show_on_sale !== false;
        },
        async toggleItemVisibility(i) {
            const type = i._type || i.type || 'product';
            if (type !== 'product') return; // pref rows exist for products only
            const key = 'product:' + i.id;
            const newVisible = !this.isItemVisible(i);
            const prev = this.userGridPrefs[key];
            this.userGridPrefs[key] = newVisible ? 1 : 0; // optimistic
            try {
                const res = await fetch('/fbr-pos/grid-prefs/toggle', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ item_type: 'product', item_id: i.id, visible: newVisible })
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                this.filterProducts();
                this.syncAutoWidecart();
            } catch (e) {
                if (prev === undefined) delete this.userGridPrefs[key]; else this.userGridPrefs[key] = prev;
                this.showToast(window.TXT.save_failed_try_again, 'error');
            }
        },
        visibleGridCount() {
            try {
                return this.allProducts.filter(p => this.isItemVisible(p)).length
                    + this.allServices.length;
            } catch (e) { return 1; /* never auto-hide on error */ }
        },
        // PRA parity: jab user saare grid items hide kar de to wide-cart layout
        // KHUD on ho jaye; unhiding restores the grid (auto flag tracked so a
        // MANUAL toggle press always wins).
        syncAutoWidecart() {
            if (this.gridEditMode) return; // editing needs the grid visible
            const count = this.visibleGridCount();
            let autoFlag = false;
            try { autoFlag = localStorage.getItem('fbr_show_products_auto') === '1'; } catch (e) {}
            if (count === 0 && this.showProducts) {
                this.showProducts = false;
                if (this.activeCategory !== 'all') this.activeCategory = 'all';
                this.filterProducts();
                try {
                    localStorage.setItem('fbr_show_products', '0');
                    localStorage.setItem('fbr_show_products_auto', '1');
                } catch (e) {}
            } else if (count > 0 && !this.showProducts && autoFlag) {
                this.showProducts = true;
                this.filterProducts();
                try {
                    localStorage.setItem('fbr_show_products', '1');
                    localStorage.removeItem('fbr_show_products_auto');
                } catch (e) {}
            }
        },
        async resetGridPrefs() {
            if (this.gridPrefBusy) return;
            this.gridPrefBusy = true;
            try {
                const res = await fetch('/fbr-pos/grid-prefs/reset', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                this.userGridPrefs = {};
                this.filterProducts();
                this.syncAutoWidecart();
                this.showToast(window.TXT.all_items_visible_again, 'success');
            } catch (e) {
                this.showToast(window.TXT.reset_failed_try_again, 'error');
            } finally {
                this.gridPrefBusy = false;
            }
        },
        get hiddenPrefCount() {
            return Object.values(this.userGridPrefs).filter(v => v == 0).length;
        },

        // ═══ Task 1271: CART DRAFTS — fbr_pos_drafts JSON rows (never
        // FbrPosTransaction rows: those consume FBR serials). Drafts carry the
        // selected customer reference; recall is lock-guarded (5-min expiry) so
        // two cashiers can't edit the same draft simultaneously. ═══
        async openDrafts() {
            this.showDrafts = true;
            await this.loadDrafts();
        },
        async loadDrafts() {
            try {
                const r = await fetch('/fbr-pos/api/drafts', { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                const d = r.ok ? await r.json() : [];
                this.drafts = Array.isArray(d) ? d : [];
            } catch (e) { this.drafts = []; }
        },
        async saveDraftCart() {
            if (this.cart.length === 0) { this.showToast(window.TXT.draft_cart_empty, 'error'); return; }
            if (this.draftBusy) return;
            this.draftBusy = true;
            try {
                const payload = {
                    draft_id: this.activeDraftId,
                    cart_data: {
                        items: this.cart,
                        total_amount: this.roundedTotal,
                        order_type: this.orderType,
                        kitchen_notes: this.kitchenNotes,
                        discount_type: this.discountType,
                        discount_value: this.discountValue,
                        show_discount: this.showDiscount,
                    },
                    customer_id: this.selectedCustomer?.id || null,
                    customer_name: this.selectedCustomer?.name || null,
                    customer_phone: this.selectedCustomer?.phone || null,
                };
                const res = await fetch('/fbr-pos/api/drafts', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json().catch(() => null);
                if (!res.ok || !data || !data.success) {
                    this.showToast((data && data.message) || window.TXT.draft_save_failed, 'error');
                    return;
                }
                this.activeDraftId = null;
                this.stopDraftLockRenewal(); // saved = parked (server released the lock)
                this.clearCart();
                this.showToast(window.TXT.draft_saved, 'success');
                await this.loadDrafts();
            } catch (e) {
                this.showToast(window.TXT.draft_save_failed, 'error');
            } finally { this.draftBusy = false; }
        },
        async recallDraft(d) {
            if (this.draftBusy) return;
            if (this.cart.length > 0 && !confirm(window.TXT.draft_recall_replaces_cart)) return;
            this.draftBusy = true;
            try {
                const res = await fetch('/fbr-pos/api/drafts/' + d.id + '/recall', { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json().catch(() => null);
                if (!res.ok || !data || !data.success) {
                    // 423 = locked by another cashier — message carries the holder name.
                    this.showToast((data && data.message) || window.TXT.draft_not_found, 'error');
                    await this.loadDrafts();
                    return;
                }
                this.clearCart();
                const cd = data.cart || {};
                this.cart = Array.isArray(cd.items) ? cd.items : [];
                if (cd.order_type) this.orderType = cd.order_type;
                this.kitchenNotes = cd.kitchen_notes || '';
                if (cd.discount_type) this.discountType = cd.discount_type;
                this.discountValue = cd.discount_value || 0;
                this.showDiscount = !!cd.show_discount;
                // Customer restore: prefer the LIVE baked row (fresh khata/phone);
                // fall back to the draft's snapshot so the reference survives even
                // when the customer left the baked most-recent subset.
                if (data.customer_id) {
                    const live = (this.allCustomers || []).find(c => c.id == data.customer_id);
                    this.selectedCustomer = live || { id: data.customer_id, name: data.customer_name || window.TXT.customer_word, phone: data.customer_phone || '' };
                    this.customerPhoneQuery = this.selectedCustomer.phone || this.selectedCustomer.name || '';
                }
                this.activeDraftId = d.id;
                this.startDraftLockRenewal(); // hold the edit lock while the cart is open
                this.showDrafts = false;
                this.fixCartIndex();
                this.showToast(window.TXT.draft_recalled, 'success');
            } catch (e) {
                this.showToast(window.TXT.draft_not_found, 'error');
            } finally { this.draftBusy = false; }
        },
        async deleteDraft(id, skipConfirm = false) {
            if (!skipConfirm && !confirm(window.TXT.draft_delete_confirm)) return;
            try {
                const res = await fetch('/fbr-pos/api/drafts/' + id, {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
                });
                const data = await res.json().catch(() => null);
                if (data && data.success === false) {
                    this.showToast(data.message || window.TXT.draft_not_found, 'error');
                } else {
                    this.drafts = this.drafts.filter(x => x.id !== id);
                    if (this.activeDraftId === id) { this.activeDraftId = null; this.stopDraftLockRenewal(); }
                }
            } catch (e) {}
        },
        // Billed a recalled draft → remove the draft row silently (its sale is
        // now a real transaction; leaving it would double-bill on re-recall).
        consumeActiveDraft() {
            if (!this.activeDraftId) return;
            const did = this.activeDraftId;
            this.activeDraftId = null;
            this.stopDraftLockRenewal();
            fetch('/fbr-pos/api/drafts/' + did, {
                method: 'DELETE',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
            }).catch(() => {});
        },
        // ── Task 1271: ACTIVE-RECALL LOCK RENEWAL ────────────────────────────
        // The server lock expires after 5 minutes; while a recalled draft sits
        // in the cart we re-assert it every 2 minutes so a second cashier can
        // never grab the draft mid-edit. A failed renewal (lock stolen after a
        // laptop-sleep gap, or draft deleted elsewhere) detaches the draft and
        // warns — the final settlement claim in store() stays the hard gate.
        startDraftLockRenewal() {
            this.stopDraftLockRenewal();
            this.draftLockTimer = setInterval(() => this.renewDraftLock(), 120000);
        },
        stopDraftLockRenewal() {
            if (this.draftLockTimer) { clearInterval(this.draftLockTimer); this.draftLockTimer = null; }
        },
        async renewDraftLock() {
            const did = this.activeDraftId;
            if (!did) { this.stopDraftLockRenewal(); return; }
            try {
                const res = await fetch('/fbr-pos/api/drafts/' + did + '/lock', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
                });
                if (res.status === 423 || res.status === 404) {
                    // Lock stolen or draft gone — detach so Pay can't double-bill.
                    if (this.activeDraftId === did) {
                        this.activeDraftId = null;
                        this.stopDraftLockRenewal();
                        this.showToast(window.TXT.draft_lock_lost, 'error');
                    }
                }
            } catch (e) { /* offline blip — keep the timer, next tick retries */ }
        },
        // Leaving the sale screen with a recalled draft still attached → best-
        // effort lock release so the counter partner isn't stuck for 5 minutes.
        releaseDraftLockOnExit() {
            const did = this.activeDraftId;
            if (!did || !navigator.sendBeacon) return;
            const fd = new FormData();
            fd.append('_token', '{{ csrf_token() }}');
            navigator.sendBeacon('/fbr-pos/api/drafts/' + did + '/unlock', fd);
        },

        async payHeldOrderDirect(orderId, method, savedTotal, provisional = false, skipReceipt = false) {
            try {
                // PROVISIONAL BILL FLOW — when true, RestaurantPosController::payOrder
                // forces fbr_status='local' and skips FBR submission. Bill remains
                // editable / deletable until promoted via "Submit to FBR — Make Final".
                const res = await fetch(`/pos/restaurant/orders/${orderId}/pay`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ payment_method: method, save_as_provisional: !!provisional }) });
                if (!res.ok) {
                    const bodyText = await res.text().catch(() => '');
                    console.error('[payOrder] HTTP', res.status, res.statusText, bodyText.slice(0, 500));
                    throw new Error('Pay HTTP ' + res.status + ' ' + res.statusText);
                }
                const data = await res.json();
                if (data.success) {
                    this.heldOrders = this.heldOrders.filter(o => o.id !== orderId);
                    this.lastInvoiceNumber = data.invoice_number || ''; this.lastTransactionId = data.transaction_id || null;
                    this.lastOrderId = orderId || null;
                    this.lastTotal = savedTotal || data.total_amount || 0; this.lastPaymentMethod = method;
                    this.lastFbrNumber = data.fbr_invoice_number || ''; this.lastFbrStatus = data.fbr_status || '';
                    this.lastItemsCount = (this.cart || []).reduce((s, i) => s + (parseFloat(i.quantity) || 0), 0);
                    this.lastSaleAt = Date.now();
                    this.setWaBill(data); // Task 1271: WA extras ride payOrder's JSON when routable
                    // ── Push to Akhri Bills strip ────────────────────────────────────────
                    if (data.transaction_id && data.invoice_number) {
                        this.recentBills = [{ id: data.transaction_id, invoice_number: data.invoice_number, total: savedTotal, method }].concat(this.recentBills).slice(0, 5);
                    }
                    this.showReceipt = true;
                    this.scheduleReceiptAutoClose();
                    this.startFbrPoll(); // Task 655: fiscal_device 'pending' → badge + receipt auto-flip
                    this.$nextTick(() => { setTimeout(() => this.triggerConfetti(), 300); });
                    // Print order: INVOICE FIRST → KOT AFTER. Cashier-requested sequence.
                    // Uses postMessage-chained engine — KOT never fires before the receipt
                    // print dialog is dismissed (was a race in the old setTimeout(200/1800) impl
                    // on slow networks where KOT iframe loaded before receipt iframe).
                    this.runAutoPrintChain(orderId, /* isFbrHeld= */ false, skipReceipt); // PRA restaurant order ID
                    // Refresh provisional badge count when this save was provisional.
                    if (provisional) { this.loadLocalBills(); }
                    // Refresh failed badge so cashier sees pending/failed state in real time.
                    this.loadFailedBills();
                } else { if (data.stock_error) { this.stockError = data.message; this.showPayModal = true; } this.showToast(data.message || window.TXT.payment_failed, 'error'); }
            } catch (e) {
                console.error('[payHeldOrderDirect] FAIL', e);
                this.showToast(window.TXT.payment_error_prefix + (e?.message || e?.name || 'unknown') + ' — F12 console', 'error');
            }
        },

        // Receipt auto-close (Aug 2026 — Retail Fast Billing): 10-second countdown then
        // startNewAfterPayment. Cashier can close manually anytime via Esc / Close / Enter.
        // receiptAutoCloseTimer and _receiptAutoCloseSecs both kept for cancel support.
        scheduleReceiptAutoClose() {
            if (this.receiptAutoCloseTimer) { clearTimeout(this.receiptAutoCloseTimer); this.receiptAutoCloseTimer = null; }
            // Task 1263: honor the company setting (Customize → Receipt popup
            // auto-close). 0 = never auto-close; default 10s matches old behavior.
            const secs = parseInt(this.receiptAutoCloseSecs, 10);
            if (!secs || secs <= 0) return;
            this.receiptAutoCloseTimer = setTimeout(() => {
                if (this.showReceipt) { this.startNewAfterPayment(); }
                this.receiptAutoCloseTimer = null;
            }, secs * 1000);
        },

        cancelReceiptAutoClose() {
            if (this.receiptAutoCloseTimer) { clearTimeout(this.receiptAutoCloseTimer); this.receiptAutoCloseTimer = null; }
        },

        // ── Task 655: FISCAL-DEVICE FBR STATUS POLL (twin of the PRA poll) ──
        // fiscal_device companies save the bill as fbr_status='pending'; the
        // Desktop Agent submits it from the shop PC within seconds. Poll the
        // tiny status endpoint (~2.5s, bounded 30s) so the popup badge flips
        // to FBR VERIFIED + fiscal number and the receipt iframe reloads.
        fbrPollTimer: null,
        startFbrPoll() {
            this.stopFbrPoll();
            if (this.lastFbrStatus !== 'pending' || !this.lastTransactionId || this.lastIsOffline) return;
            const txnId = this.lastTransactionId;
            const deadline = Date.now() + 30000;
            let inflight = false;
            this.fbrPollTimer = setInterval(async () => {
                if (this.lastTransactionId !== txnId || Date.now() > deadline) { this.stopFbrPoll(); return; }
                if (inflight) return; // slow response must not stack requests
                inflight = true;
                const st = await this._fetchFbrStatus(txnId);
                inflight = false;
                if (!st || this.lastTransactionId !== txnId) return;
                if (st.fbr_status && st.fbr_status !== 'pending') {
                    this._applyFbrStatus(txnId, st);
                    // submitted = terminal. failed/offline: badge update ho chuka,
                    // lekin agent retry kar sakta hai — deadline tak poll jaari.
                    if (st.fbr_status === 'submitted') this.stopFbrPoll();
                }
            }, 2500);
        },
        stopFbrPoll() {
            if (this.fbrPollTimer) { clearInterval(this.fbrPollTimer); this.fbrPollTimer = null; }
        },
        async _fetchFbrStatus(txnId) {
            try {
                const res = await fetch('/fbr-pos/transaction/' + txnId + '/fbr-status', { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return null;
                return await res.json();
            } catch (e) { return null; } // network blip — next tick retries
        },
        _applyFbrStatus(txnId, st) {
            if (this.lastTransactionId !== txnId) return; // a new sale took over
            const prev = this.lastFbrStatus;
            this.lastFbrStatus = st.fbr_status || '';
            if (st.fbr_invoice_number) this.lastFbrNumber = st.fbr_invoice_number;
            if (prev !== this.lastFbrStatus) {
                this.refreshReceiptIframe();
                if (this.lastFbrStatus === 'failed' || this.lastFbrStatus === 'offline') this.loadFailedBills();
            }
        },
        // Receipt iframe reload (cache-bust) — pending→submitted flip ke baad
        // popup ke andar receipt FBR fiscal box + QR ke saath taaza dikhe.
        refreshReceiptIframe() {
            if (!this.showReceipt || this.lastIsOffline || !this.lastTransactionId) return;
            try {
                const el = this.$refs.receiptIframe;
                if (!el) return;
                el.src = '/fbr-pos/transaction/' + this.lastTransactionId + '/receipt?_fbr=' + Date.now();
            } catch (e) { /* best-effort — popup badge is already correct */ }
        },
        // Bounded pehla-print grace (max ~4.8s): bill abhi 'pending' ho to print
        // se pehle submit ka mauqa do; timeout par pending slip hi chal padti hai.
        async fbrPrintGrace() {
            if (this.lastFbrStatus !== 'pending' || !this.lastTransactionId || this.lastIsOffline) return;
            const txnId = this.lastTransactionId;
            for (let i = 0; i < 4; i++) {
                await new Promise(r => setTimeout(r, 1200));
                if (this.lastTransactionId !== txnId) return; // new sale took over
                if (this.lastFbrStatus !== 'pending') return; // badge poll flipped it already
                const st = await this._fetchFbrStatus(txnId);
                if (st && st.fbr_status && st.fbr_status !== 'pending') {
                    this._applyFbrStatus(txnId, st);
                    return;
                }
            }
        },

        async recallOrder(order) {
            if (!order || !order.id) return false;
            if (this.cart.length > 0 && !confirm(window.TXT.replace_cart_with_recalled)) return false;
            try {
                // Server-side recall DELETES the held row via conditional-delete
                // claim — if another terminal already recalled it we get 409 and
                // refresh the list instead of duplicating the cart.
                const res = await fetch('{{ url("/fbr-pos/api/held") }}/' + order.id + '/recall', { headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' } });
                const data = await res.json();
                if (!res.ok || !data.success) { this.showToast((data && data.message) || window.TXT.recall_failed, 'error'); this.loadHeldOrders(); return false; }
                const cd = data.cart || {};
                const items = Array.isArray(cd.items) ? cd.items : (Array.isArray(cd) ? cd : []);
                this.cart = items.map(i => ({ cart_uid: 'c' + Date.now() + '_' + Math.random().toString(36).slice(2,9), item_id: i.item_id ?? null, item_type: i.item_type || 'product', item_name: i.item_name, quantity: parseFloat(i.quantity) || 1, unit_price: parseFloat(i.unit_price) || 0, special_notes: i.special_notes || '', is_tax_exempt: !!i.is_tax_exempt, hs_code: i.hs_code ?? null, uom: i.uom || 'U', tax_rate: (i.tax_rate === 0 || i.tax_rate) ? parseFloat(i.tax_rate) : 18, item_discount_type: i.item_discount_type || 'percentage', item_discount_value: parseFloat(i.item_discount_value) || 0, showItemDiscount: (parseFloat(i.item_discount_value) || 0) > 0, showFbrPanel: false }));
                if (cd.discount_type && parseFloat(cd.discount_value) > 0) { this.discountType = cd.discount_type; this.discountValue = parseFloat(cd.discount_value) || 0; this.showDiscount = true; } else { this.discountType = 'percentage'; this.discountValue = 0; this.discountAmount = 0; this.showDiscount = false; }
                this.customerNtn = cd.customer_ntn || '';
                this.kitchenNotes = cd.kitchen_notes || ''; // Task 641: restore order note on recall
                this.recalledOrderId = null;
                this.selectedCustomer = cd.customer_id ? { id: cd.customer_id, name: order.customer_name || window.TXT.customer_word, phone: cd.customer_phone || '' } : null;
                this.customerPhoneQuery = this.selectedCustomer ? (this.selectedCustomer.phone || this.selectedCustomer.name) : '';
                // Task 170: restore order type + delivery address. The
                // selectedCustomer watcher fires on the NEXT tick and wipes
                // selectedDeliveryAddress, then loadCustomerAddresses() would
                // async-overwrite it with the saved default — pendingAddrRestore
                // survives both and wins (see watcher + loadCustomerAddresses).
                if (cd.order_type) this.orderType = cd.order_type;
                const heldAddr = (cd.delivery_address || '').trim();
                if (cd.order_type === 'delivery' && heldAddr) { this.pendingAddrRestore = heldAddr; this.selectedDeliveryAddress = heldAddr; }
                // Order Matching (Aug 2026) — restore token/code from recalled cart_data.
                // A recalled cart billed directly (never re-held) must still write the
                // token to fbr_pos_transactions via processPaymentManual's payload.
                this.currentTokenNo   = cd.token_no   ? Number(cd.token_no)         : null;
                this.currentOrderCode = cd.order_code ? String(cd.order_code).toUpperCase() : null;
                this.heldOrders = this.heldOrders.filter(o => o.id !== order.id); this.showHeldOrders = false; this.showToast(window.TXT.order_recalled_for_editing, 'success');
                return true;
            } catch (e) { console.error('recallOrder', e); this.showToast(window.TXT.network_error, 'error'); return false; }
        },

        async addQuickCustomer() {
            if (!this.quickCustomerName.trim() || !this.quickCustomerPhone.trim()) {
                this.showToast(window.TXT.name_phone_required, 'error'); return;
            }
            try {
                const res = await fetch('{{ route("fbrpos.api.customer-store") }}', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ name: this.quickCustomerName.trim(), phone: this.quickCustomerPhone.trim(), address: this.quickCustomerAddress.trim() || null }),
                });
                const data = await res.json();
                if (data.customer || data.success) {
                    const cust = data.customer || { id: Date.now(), name: this.quickCustomerName.trim(), phone: this.quickCustomerPhone.trim(), address: this.quickCustomerAddress.trim() };
                    if (!data.existing) this.allCustomers.push(cust);
                    this.selectedCustomer = cust; this.showQuickAdd = false;
                    this.customerPhoneQuery = cust.phone || cust.name;
                    this.quickCustomerName = ''; this.quickCustomerPhone = ''; this.quickCustomerAddress = ''; this.showCustomerPicker = false;
                    this.showToast(data.existing ? window.TXT.customer_found_prefix + cust.name : window.TXT.customer_added_prefix + cust.name, 'success');
                } else { this.showToast(data.message || window.TXT.failed_word, 'error'); }
            } catch (e) { this.showToast(window.TXT.error_adding_customer, 'error'); }
        },

        get effectiveDiscountLimit() {
            if (this.posRole === 'pos_admin') return 100;
            return this.managerOverrideActive ? {{ (float) ($hasManagerPin ? ($company->manager_discount_limit ?? 50) : 100) }} : this.discountLimit;
        },
        checkDiscountLimit(val, type) {
            // Percentage discounts respect the role-based cap (cashier vs manager-override).
            // Amount discounts allow ANY value up to the subtotal — cashier can give a Rs-based
            // discount of any size as long as it's not larger than the order itself.
            // Use 2-dp integer comparison to dodge JS float precision (0.1 + 0.2 = 0.30000…04).
            const valCents = Math.round((Number(val) || 0) * 100);
            if (type === 'percentage' && valCents > Math.round(this.effectiveDiscountLimit * 100)) return false;
            if (type === 'amount' && this.effectiveSubtotal > 0 && valCents > Math.round(this.effectiveSubtotal * 100)) return false;
            return true;
        },
        async requestManagerOverride() {
            if (!this.hasManagerPin) { this.showToast(window.TXT.manager_pin_not_configured, 'error'); return; }
            this.showManagerPinModal = true; this.managerPin = ''; this.managerPinError = '';
        },
        async submitManagerPin() {
            try {
                const res = await fetch('{{ route("pos.restaurant.verify-manager-pin") }}', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ pin: this.managerPin }),
                });
                const data = await res.json();
                if (data.success) {
                    this.managerOverrideActive = true; this.showManagerPinModal = false;
                    this.showToast(window.TXT.manager_override_granted, 'success');
                } else { this.managerPinError = data.message || 'Invalid PIN'; }
            } catch (e) { this.managerPinError = 'Connection error'; }
        },
        async loadCustomerHistory(customerId) {
            this.loadingCustomerHistory = true; this.customerHistory = null;
            try {
                const res = await fetch(`/fbr-pos/api/customer-history/${customerId}`);
                if (res.ok) { this.customerHistory = await res.json(); this.showCustomerHistory = true; }
            } catch (e) {}
            this.loadingCustomerHistory = false;
        },
        reorderItems(order) {
            for (const item of order.items) {
                const existing = this.cart.find(c => c.item_id === item.item_id && c.item_type === item.item_type);
                if (existing) { existing.quantity += item.qty; } else {
                    this.cart.push({ cart_uid: 'c' + Date.now() + '_' + Math.random().toString(36).slice(2,9), item_id: item.item_id, item_type: item.item_type, item_name: item.name, quantity: item.qty, unit_price: item.price, special_notes: '', is_tax_exempt: item.is_tax_exempt || false, is_third_schedule: item.is_third_schedule || false, item_discount_type: 'percentage', item_discount_value: 0, showItemDiscount: false, showFbrPanel: false });
                }
            }
            this.showCustomerHistory = false; this.showToast(window.TXT.items_added_to_cart, 'success');
        },
        getCartCost() {
            // Profit engine: prefer recipe-based ingredient cost (most accurate for kitchens),
            // fall back to product.cost_price for simple/retail items. Services have no cost.
            return this.cart.reduce((s, i) => {
                if (i.item_type === 'service') return s;
                let cost = this.ingredientCosts[i.item_id] || 0;
                if (cost === 0) {
                    const p = (this.allProducts || []).find(x => x.id === i.item_id);
                    if (p && Number(p.cost_price) > 0) cost = Number(p.cost_price);
                }
                return s + (cost * i.quantity);
            }, 0);
        },
        showToast(msg, type) { this.toast = { show: true, message: msg, type }; setTimeout(() => this.toast.show = false, 2500); },
        triggerConfetti() {
            const container = document.getElementById('confettiContainer');
            if (!container) return;
            const colors = ['#22c55e', '#2563eb', '#f59e0b', '#3b82f6', '#ef4444', '#ec4899', '#14b8a6'];
            for (let i = 0; i < 30; i++) {
                const piece = document.createElement('div');
                piece.className = 'confetti-piece';
                piece.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                piece.style.left = Math.random() * 100 + '%';
                piece.style.top = '-10px';
                piece.style.animationDelay = Math.random() * 0.5 + 's';
                piece.style.animationDuration = (1 + Math.random() * 1) + 's';
                if (Math.random() > 0.5) { piece.style.borderRadius = '50%'; piece.style.width = '6px'; piece.style.height = '6px'; }
                container.appendChild(piece);
                setTimeout(() => piece.remove(), 2000);
            }
        },
    };
}
</script>

@if($showDeliveriesBoardBtn)
{{-- ── Delivery Board modal (Aug 2026) ─────────────────────────────────────────
     Full /fbr-pos/deliveries board in a LAZY iframe overlay — iframe src is set
     on first open only (zero sale-screen boot cost; pos-boot-splash-perf.md).
     Vanilla JS + inline styles — outside Alpine state, no arbitrary Tailwind
     classes (vite-arbitrary-classes.md). Board page detects window.self !==
     window.top and hides its own nav + back button. All gating stays server-side
     on the route (fbrpos.auth + deliveryGate + plan gate). --}}
<div id="tn-delivery-board" style="display:none; position:fixed; inset:0; z-index:95;">
    <div onclick="tnCloseDeliveryBoard()" style="position:absolute; inset:0; background:rgba(15,23,42,.55); backdrop-filter:blur(4px);"></div>
    <div style="position:absolute; inset:16px; display:flex; flex-direction:column; background:#f9fafb; border-radius:16px; overflow:hidden; box-shadow:0 24px 64px rgba(0,0,0,.35);" class="dark:bg-gray-900">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:10px 16px; background:#065f46; color:#fff; flex-shrink:0;">
            <div style="display:flex; align-items:center; gap:8px; min-width:0;">
                <svg style="width:18px;height:18px;flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
                <span style="font-weight:800; font-size:14px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ __('pos.deliveries') }}</span>
            </div>
            <button type="button" onclick="tnCloseDeliveryBoard()" style="display:flex; align-items:center; gap:6px; padding:6px 14px; border-radius:10px; background:rgba(255,255,255,.14); color:#fff; font-weight:800; font-size:12px; border:1px solid rgba(255,255,255,.25); cursor:pointer;">
                {{ __('pos.close') }}
                <svg style="width:14px;height:14px;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <iframe id="tn-delivery-board-frame" title="{{ __('pos.deliveries') }}" style="flex:1 1 0%; width:100%; border:0; background:#f9fafb;"></iframe>
    </div>
</div>
<script>
function tnOpenDeliveryBoard() {
    var wrap = document.getElementById('tn-delivery-board');
    var frame = document.getElementById('tn-delivery-board-frame');
    if (!wrap || !frame) return;
    if (!frame.getAttribute('src')) {
        frame.setAttribute('src', '{{ route('fbrpos.deliveries', [], false) }}');
    }
    wrap.style.display = 'block';
}
function tnCloseDeliveryBoard() {
    var wrap = document.getElementById('tn-delivery-board');
    if (wrap) wrap.style.display = 'none';
    // Reload board on next open so rider/status changes are always fresh.
    var frame = document.getElementById('tn-delivery-board-frame');
    if (frame) frame.removeAttribute('src');
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        var wrap = document.getElementById('tn-delivery-board');
        if (wrap && wrap.style.display !== 'none') { tnCloseDeliveryBoard(); e.stopPropagation(); }
    }
}, true);
</script>
@endif
</x-fbr-pos-layout>
