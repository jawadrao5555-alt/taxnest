<x-pos-layout>
{{-- DIVERGENCE NOTE (updated 7 Aug 2026): the 24 Jul sale-screen redesign (nav
     sale-tools teleport, compact grid rows, Akhri Bills strip, notes+discount
     one-row, bada total band, one-tap CASH/CARD Alt+1/2) is now ALSO ported to
     the FBR universal (fbr-pos/universal.blade.php) — owner approved via video
     note. Keep the two footers/grids in sync going forward (FBR = blue chrome,
     per-item tax, no method hint, no gridEditMode). --}}
@php
    $isSaaf = ($company->pos_dashboard_style ?? 'default') === 'saaf';
@endphp
@if($isSaaf)<link rel="stylesheet" href="{{ asset('css/pos-saaf.css') }}?v=5">@endif
{{-- Boot splash (customer report, 25 Jul 2026): on slow shop connections this large
     page looked BLANK WHITE after a hard refresh until Alpine parsed and the grid
     painted. Inline-styled overlay shows the moment its bytes stream in and stays
     until restaurantPos.init() reaches its final ready signal. A bounded watchdog
     turns startup failure into an actionable recovery instead of an endless spinner.
     Do NOT move product JSON out of the page (offline billing depends on inline data). --}}
<div id="tn-boot-splash" data-tn-sale-boot-splash
     data-failure-title="{{ __('pos.sale_screen_boot_failed_title') }}"
     data-failure-hint="{{ __('pos.sale_screen_boot_failed_hint') }}"
     role="status" style="position:fixed;inset:0;z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;background:#f9fafb;">
    <style>
        @keyframes tnBootSpin { to { transform: rotate(360deg); } }
        #tn-boot-splash .tn-boot-spinner { width:44px;height:44px;border:4px solid #e5e7eb;border-top-color:#7c3aed;border-radius:50%;animation:tnBootSpin .8s linear infinite; }
        .dark #tn-boot-splash { background:#111827 !important; }
        .dark #tn-boot-splash .tn-boot-title { color:#e5e7eb !important; }
    </style>
    <div class="tn-boot-spinner"></div>
    <div id="tn-boot-title" class="tn-boot-title" style="font-weight:800;color:#374151;font-size:15px;">{{ __('pos.nestpos_loading') }}</div>
    <div id="tn-boot-hint" style="color:#9ca3af;font-size:12px;">{{ __('pos.slow_internet_hint') }}</div>
    <button id="tn-boot-retry" type="button" hidden style="border:0;border-radius:8px;background:#7c3aed;color:#fff;padding:10px 16px;font-weight:700;cursor:pointer;">{{ __('pos.sale_screen_try_again') }}</button>
</div>
{{-- Task 658 (Aug 2026): bake only the TXT.* keys this screen actually uses
     (PosI18n scans this blade — extraction can never go stale). Full __('pos')
     was ~245KB / ~4,400 keys; the used subset is a few hundred. QA: deploy
     preflight runs scripts/pos-i18n-check.php (missing keys in en/rur/ur or
     dynamic non-literal TXT subscript access fail the deploy). --}}
<script type="application/json" id="tn-pos-i18n">{!! json_encode(\App\Support\PosI18n::baked('pos/universal'), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' !!}</script>
<script>window.TXT = (function () { try { return JSON.parse(document.getElementById('tn-pos-i18n').textContent) || {}; } catch (e) { return {}; } })();</script>
<script>
// SALE_CACHE idempotent re-prime. Originally this ran only on the first
// uncontrolled visit after browser-data clear. It now runs after every successful
// document load: the worker exits immediately when a validated copy exists, while
// an invalid-entry eviction, manual cache clear, or watchdog cache-bypass recovery
// gets a guaranteed replacement for the next offline open.
(function () {
    try {
        if (!('serviceWorker' in navigator)) return;
        window.addEventListener('load', function () {
            navigator.serviceWorker.ready.then(function (reg) {
                if (reg.active) reg.active.postMessage({ type: 'TN_PRIME_SALE_CACHE', url: '/pos/invoice/create' });
            }).catch(function () {});
        });
    } catch (e) { /* best-effort */ }
})();
</script>
<script>
    // A valid cached document is not enough if Alpine or its baked boot data fails
    // after parsing. Keep the splash until the component itself reports readiness;
    // report a small support-safe diagnostic and make exactly one fresh, cache-bypass
    // attempt before exposing a manual retry. This deliberately does NOT treat an
    // empty catalogue or a hidden grid as a failure.
    (function () {
        var variant = 'pra', retryKey = 'tn-sale-boot-recovery:' + location.pathname;
        var splash = document.getElementById('tn-boot-splash');
        var title = document.getElementById('tn-boot-title');
        var hint = document.getElementById('tn-boot-hint');
        var retry = document.getElementById('tn-boot-retry');
        var state = { ready: false, failed: false, recovering: false };
        function diagnostic(reason, detail) {
            var payload = {
                variant: variant, reason: String(reason || 'unknown').slice(0, 80),
                message: String(detail || '').slice(0, 180), online: navigator.onLine,
                controlled: !!(navigator.serviceWorker && navigator.serviceWorker.controller),
                path: location.pathname
            };
            console.error('TaxNest sale boot failed', payload);
            try { localStorage.setItem('tn_sale_boot_diagnostic', JSON.stringify(payload)); } catch (_) {}
            try {
                fetch('{{ route('pos.api.boot-diagnostics', [], false) }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    credentials: 'same-origin', body: JSON.stringify(payload)
                }).catch(function () {});
            } catch (_) {}
        }
        function freshAttempt() {
            if (state.recovering) return;
            state.recovering = true;
            try { navigator.serviceWorker && navigator.serviceWorker.controller && navigator.serviceWorker.controller.postMessage({ type: 'TN_DROP_SALE_CACHE' }); } catch (_) {}
            var next = new URL(location.href);
            next.searchParams.set('__tn_sale_recover', '1');
            location.replace(next.pathname + '?' + next.searchParams.toString());
        }
        function fail(reason, detail) {
            if (state.ready || state.failed) return;
            state.failed = true;
            diagnostic(reason, detail);
            if (title) title.textContent = splash.getAttribute('data-failure-title');
            if (hint) hint.textContent = splash.getAttribute('data-failure-hint');
            if (retry) { retry.hidden = false; retry.onclick = freshAttempt; }
            // One cache-bypass navigation only. The query-string route is deliberately
            // outside SALE_CACHE, and the session flag prevents a reload loop.
            try {
                if (navigator.onLine && !new URL(location.href).searchParams.has('__tn_sale_recover') && !sessionStorage.getItem(retryKey)) {
                    sessionStorage.setItem(retryKey, '1');
                    setTimeout(freshAttempt, 700);
                }
            } catch (_) {}
        }
        window.tnSaleBoot = {
            validResponse: async function (response) {
                if (!response || !response.ok || response.redirected || !((response.headers.get('content-type') || '').includes('text/html'))) return false;
                try {
                    var html = await response.clone().text();
                    return html.length > 4096
                        && html.includes('data-tn-sale-document="pra"')
                        && html.includes('data-tn-sale-root')
                        && html.includes('function restaurantPos()')
                        && html.includes('window.tnBootFp');
                } catch (_) { return false; }
            },
            ready: function () {
                if (state.ready) return;
                state.ready = true;
                try { sessionStorage.removeItem(retryKey); } catch (_) {}
                try {
                    var clean = new URL(location.href);
                    if (clean.searchParams.delete('__tn_sale_recover')) history.replaceState({}, '', clean.pathname + (clean.search || '') + clean.hash);
                } catch (_) {}
                if (splash) splash.remove();
            },
            fail: fail
        };
        window.addEventListener('error', function (e) {
            if (!state.ready && (e.error || e.filename)) fail('runtime_error', (e.message || '').slice(0, 180));
        });
        window.addEventListener('unhandledrejection', function (e) {
            if (!state.ready) fail('unhandled_rejection', String(e.reason || '').slice(0, 180));
        });
        setTimeout(function () { fail('startup_timeout', 'sale component did not become ready within 15 seconds'); }, 15000);
    })();
</script>
{{-- One-click Silent Printing prompt (owner mandate, 25 Jul 2026 — "sab ke liye
     solve karo"): shops with a connected Desktop Agent almost never find
     /pos/printer-settings, so first prints stutter via browser printing forever.
     Floating card (fixed — never disturbs the Screen-Fit layout), ADMIN/MANAGER
     ONLY + not pending; server re-validates everything (smartPrinterPick) so
     this block is presentational. Dismiss / manual settings save = never again. --}}
@php
    $__pp = $company->printerSettings();
    $__ppUser = auth('pos')->user();
    $__ppShow = $__ppUser && !$__ppUser->isPosCashier()
        && !$company->isPending()
        && $company->agent_enabled
        && !$__pp['silent_print_enabled']
        && empty($__pp['prompt_dismissed_at'])
        && !empty($__pp['available_printers'])
        && !empty($__pp['printers_reported_at'])
        && \Carbon\Carbon::parse($__pp['printers_reported_at'])->gt(now()->subDays(7));
    $__ppPick = $__ppShow ? \App\Http\Controllers\PosController::smartPrinterPick($__pp['available_printers']) : null;
    // Billing Scope (07 Aug 2026): stream lock for this account — 'both' (default),
    // 'local' (offline billing only) or 'pra' (PRA billing only). Drives which
    // sale-screen entry points render; server guards re-enforce everything.
    // Task 1186: EXPLICIT scope only — the derived default governs visibility,
    // never the sale-screen write affordances (F9/F10 provisional buttons must
    // keep rendering for a derived-'pra' reporting-ON cashier).
    $uBillScope = $__ppUser?->posBillingScopeExplicit() ?? 'both';
    // Delivery Board on the sale screen (Task 431, owner voice note 10 Aug 2026):
    // shopkeeper request — delivery manager ke liye TEESRI alag ID/window na khole.
    // Button shows under the SAME verdict as the nav "Deliveries" link (pos-app
    // layout line ~709): delivery feature ON + riders plan gate + Custom Access
    // tick ($posNavCan-equivalent, incl. no-delivery-manager fallback inside
    // posCustomAllows). The board itself opens in a lazy modal IFRAME pointing at
    // /pos/deliveries — the route's own gates (PosAuth + deliveryGate + stream
    // scope) re-enforce everything server-side; zero boot cost until opened.
    $showDeliveriesBoardBtn = !empty($features->delivery)
        && \App\Services\PosFeatureService::planAllows($company, 'riders_enabled')
        && (($__ppUser?->posCustomAllows('deliveries')) ?? true);
    // Quick Return (Task 681): bill number likh kar seedha return form —
    // button sirf tab dikhta hai jab returnsAllowed (Task 678 single verdict);
    // server /pos/return-lookup + return routes sab kuch re-enforce karte hain.
    $canQuickReturn = \App\Services\PosAccessService::returnsAllowed($__ppUser);
@endphp
@if($__ppShow)
<div id="tn-silent-prompt" class="fixed bottom-4 left-4 z-40 max-w-sm rounded-xl bg-purple-800 text-white px-4 py-3 shadow-sm">
    <div class="flex items-start gap-3">
        <svg class="w-6 h-6 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
        <div class="flex-1">
            <div class="font-bold text-sm">{{ __('pos.direct_printing_available') }}</div>
            <p class="text-xs text-purple-100 mt-0.5">{{ __('pos.silent_prompt_body') }}@if($__ppPick) Printer: <b>{{ $__ppPick }}</b>@endif</p>
            <div class="flex items-center gap-2 mt-2">
                @if($__ppPick)
                <button type="button" onclick="tnSilentPromptAct('enable', this)" class="bg-white text-purple-800 font-bold text-xs px-3 py-1.5 rounded-lg">{{ __('pos.yes_turn_on') }}</button>
                @else
                <a href="{{ route('pos.printer-settings') }}" class="bg-white text-purple-800 font-bold text-xs px-3 py-1.5 rounded-lg">{{ __('pos.choose_printer') }}</a>
                @endif
                <button type="button" onclick="tnSilentPromptAct('dismiss', this)" class="text-purple-200 hover:text-white text-xs underline">{{ __('pos.no_keep_as_is') }}</button>
            </div>
        </div>
    </div>
</div>
<script>
    function tnSilentPromptAct(action, btn) {
        btn.disabled = true;
        fetch('{{ route('pos.api.printer-prompt') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name=csrf-token]') || {}).content || ''
            },
            body: JSON.stringify({ action: action })
        }).then(function (r) { return r.json(); }).then(function (d) {
            var el = document.getElementById('tn-silent-prompt');
            if (action === 'enable' && d && d.success) {
                if (el) el.innerHTML = '<div class="font-bold text-sm text-center"></div>'; el.firstChild.textContent = window.TXT.silent_print_on;
                setTimeout(function () { location.reload(); }, 1200);
            } else if (el) { el.remove(); }
        }).catch(function () {
            var el = document.getElementById('tn-silent-prompt');
            if (el) el.remove();
        });
    }
</script>
@endif
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
@keyframes pulseGlow { 0%, 100% { box-shadow: 0 0 0 0 rgba(124,58,237,0.4); } 50% { box-shadow: 0 0 0 6px rgba(124,58,237,0); } }
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
.prod-card:hover { transform: translateY(-6px) scale(1.02); box-shadow: 0 20px 40px -12px rgba(0,0,0,0.18), 0 0 0 1px rgba(124,58,237,0.1); }
.prod-card:active { transform: translateY(-2px) scale(0.97); transition-duration: 0.1s; }
.prod-card .quick-add { opacity: 0; transform: scale(0.5) rotate(-90deg); transition: all 0.25s cubic-bezier(0.34, 1.56, 0.64, 1); }
.prod-card:hover .quick-add { opacity: 1; transform: scale(1) rotate(0deg); }
.prod-card.stock-out { opacity: 0.5; pointer-events: none; filter: grayscale(0.5); }
.prod-card.stock-out.allow-add { opacity: 0.7; pointer-events: auto; filter: grayscale(0.3); }
.prod-card .cart-qty-badge { animation: floatBadge 2s ease-in-out infinite; }
.letter-A,.letter-B { background: linear-gradient(135deg, #f472b6, #ec4899, #db2777) !important; }
.letter-C,.letter-D { background: linear-gradient(135deg, #a78bfa, #8b5cf6, #7c3aed) !important; }
.letter-E,.letter-F { background: linear-gradient(135deg, #60a5fa, #3b82f6, #2563eb) !important; }
.letter-G,.letter-H { background: linear-gradient(135deg, #34d399, #10b981, #059669) !important; }
.letter-I,.letter-J { background: linear-gradient(135deg, #fbbf24, #f59e0b, #d97706) !important; }
.letter-K,.letter-L { background: linear-gradient(135deg, #f87171, #ef4444, #dc2626) !important; }
.letter-M,.letter-N { background: linear-gradient(135deg, #c084fc, #a855f7, #9333ea) !important; }
.letter-O,.letter-P { background: linear-gradient(135deg, #38bdf8, #0ea5e9, #0284c7) !important; }
.letter-Q,.letter-R { background: linear-gradient(135deg, #fb923c, #f97316, #ea580c) !important; }
.letter-S,.letter-T { background: linear-gradient(135deg, #2dd4bf, #14b8a6, #0d9488) !important; }
.letter-U,.letter-V { background: linear-gradient(135deg, #e879f9, #d946ef, #c026d3) !important; }
.letter-W,.letter-X { background: linear-gradient(135deg, #818cf8, #6366f1, #4f46e5) !important; }
.letter-Y,.letter-Z { background: linear-gradient(135deg, #a3e635, #84cc16, #65a30d) !important; }
.cat-pill { transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); white-space: nowrap; position: relative; overflow: hidden; }
.cat-pill:hover { transform: translateY(-2px) scale(1.05); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.cat-pill.active { background: linear-gradient(135deg, #7c3aed, #a855f7); color: white; box-shadow: 0 1px 2px rgba(0,0,0,.08); transform: scale(1.05); }
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
.price-badge { background: linear-gradient(135deg, rgba(124,58,237,0.08), rgba(124,58,237,0.15)); border: 1px solid rgba(124,58,237,0.15); border-radius: 8px; padding: 2px 8px; }
.dark .price-badge { background: linear-gradient(135deg, rgba(167,139,250,0.1), rgba(167,139,250,0.2)); border-color: rgba(167,139,250,0.2); }
/* ── Cart column full height (ZFC customer feedback, 25 Jul 2026) ──
   The two action-bar rows used to span the FULL width, so the band above the
   cart column sat empty. The bars now live INSIDE the left (products) column
   and the cart column rises to the very top. Custom classes (not Tailwind
   utilities) so no rebuild-dependency for the responsive flip. */
.tn-body-row { display: flex; flex-direction: column; flex: 1 1 0%; min-height: 0; overflow: hidden; }
.tn-left-col { display: flex; flex-direction: column; min-width: 0; overflow: hidden; }
@media (min-width: 768px) {
    .tn-body-row { flex-direction: row; }
    .tn-left-col { flex: 1 1 0%; }
}
@media (max-width: 767px) {
    /* Mobile: bars stack ABOVE the cart exactly like before — cart fills the rest */
    .tn-body-row > .tn-cart-col { flex: 1 1 0%; min-height: 0; }
}

/* ── WIDE-CART layout (owner-approved "Variant A" mockup, 28 Jul 2026) ──
   Products-hidden mode (showProducts=false) par DESKTOP layout flip: top bars
   full-width rehte hain, neeche cart poori width LEFT me phailta hai aur payment/
   totals ka block RIGHT column ban jata hai (product grid area collapse).
   .tn-cart-main / .tn-cart-side wrappers are display:contents by DEFAULT, so the
   normal (grid-ON) layout and mobile are bit-for-bit unchanged. Mobile (<768px)
   kabhi widecart nahi hota. Custom classes — no Tailwind rebuild dependency. */
.tn-cart-main, .tn-cart-side { display: contents; }
/* Legacy fallback (no display:contents): wrappers become plain flex columns that
   reproduce the original stacking — cart list pane flexes, footer pane sizes to
   content — so old WebViews keep a working (normal) cart layout. */
@supports not (display: contents) {
    .tn-cart-main { display: flex; flex-direction: column; flex: 1 1 0%; min-height: 0; }
    .tn-cart-side { display: flex; flex-direction: column; flex-shrink: 0; }
}
@media (min-width: 768px) {
    .tn-widecart { flex-direction: column; }
    /* Widecart: left col shrinks to the bars' height — its overflow:hidden would
       clip the search dropdown to a 1px sliver (owner report, 1 Aug 2026). Let it
       overflow (grid is display:none anyway) and lift it above the cart panes. */
    .tn-widecart .tn-left-col { flex: 0 0 auto; overflow: visible; position: relative; z-index: 30; }
    /* Products area shrinks to just the category strip + Akhri Bills (toggle wapas ON karne ka raasta wahin hai) */
    .tn-widecart .tn-left-col > div.flex-1 { flex: 0 0 auto; }
    .tn-widecart [x-ref="gridContainer"] { display: none; }
    .tn-widecart .tn-cart-col { width: 100% !important; flex: 1 1 0%; min-height: 0; flex-direction: row; border-left: 0; border-top: 1px solid rgba(148,163,184,.28); }
    .tn-widecart .tn-cart-main { display: flex; flex-direction: column; flex: 1 1 0%; min-width: 0; min-height: 0; }
    .tn-widecart .tn-cart-side { display: flex; flex-direction: column; flex: 0 0 400px; width: 400px; min-height: 0; overflow-y: auto; border-left: 1px solid rgba(148,163,184,.28); }
    .tn-widecart .tn-cart-side > div:first-child { border-top: 0; }

    /* ── C-style totals card in widecart (owner pick, 28 Jul 2026) ──
       Variant C ka "Payment" look: white card, halki dark-gray rows label-left /
       value-right, neeche border ke baad Grand Total theme-accent color me bada.
       Sirf DESKTOP widecart me — normal grid-ON band aur mobile band (purple)
       bilkul unchanged. Theme-safe: accent color pos-app ke --accent-* vars se. */
    /* NOTE: body-prefixed + .bg-purple-900 repeated for specificity — pos-app ka
       theme engine `body:not([data-theme=purple]) .bg-purple-900 {...!important}`
       (0,2,1) warna is rule ko beat kar deta hai (red/blue/etc themes par). */
    /* Owner (ZFC 28 Jul 2026): normal-mode totals band must be DARK BLACK — the
       theme engine's bg-purple-900 remap washed it to a light accent shade on
       non-purple themes ("light black"). :not(.tn-widecart) keeps the C-style
       white totals card untouched; extra class hops out-rank the theme's
       body:not([data-theme=purple]) .bg-purple-900 override. */
    body .tn-body-row:not(.tn-widecart) .tn-cart-col .tn-total-band.bg-purple-900 { background: #0b0f14 !important; }
    body .tn-widecart .tn-total-band.bg-purple-900 { background: #fff !important; border-top: 1px solid #e5e7eb; padding: 12px 16px; }
    .tn-widecart .tn-total-band > .flex { flex-direction: column; align-items: stretch; gap: 8px; }
    .tn-widecart .tn-total-band > .flex > .min-w-0 { width: 100%; }
    .tn-widecart .tn-total-band > .flex > .min-w-0 > .flex { justify-content: space-between; font-size: 13px; line-height: 1.5; }
    .tn-widecart .tn-total-band > .flex > .text-right { border-top: 1px solid #e5e7eb; padding-top: 8px; display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
    .tn-widecart .tn-total-band .text-white\/75 { color: #4b5563 !important; }
    .tn-widecart .tn-total-band .text-white\/60 { color: #374151 !important; }
    .tn-widecart .tn-total-band .text-white\/50 { color: #6b7280 !important; }
    .tn-widecart .tn-total-band .text-orange-300 { color: #dc2626 !important; }
    .tn-widecart .tn-total-band .text-green-300 { color: #059669 !important; }
    .tn-widecart .tn-total-band .text-red-300 { color: #dc2626 !important; }
    .tn-widecart .tn-total-band .bg-white\/15 { background: #f3f4f6 !important; color: #4b5563 !important; }
    .tn-widecart .tn-total-band .total-line { color: hsl(var(--accent-h, 263), var(--accent-s, 70%), var(--accent-l, 50%)) !important; }
}

@media (max-width: 767px) {
    /* ── Mobile cart fix (owner iPhone screenshot, 25 Jul 2026) ──
       The sale screen has a FIXED app height, so the tall cart footer (Notes +
       Total band + pay buttons) gets clipped on phones and the sticky pay block
       used to hover OVER Order Notes with a see-through background (background:
       inherit resolved to the parent's translucent bg-gray-50/80). Fix: let the
       whole cart column scroll on mobile and dock the pay block with a SOLID
       background + top hairline so content scrolls cleanly underneath it. */
    .tn-cart-col { overflow-y: auto; }
    .tn-cart-col [x-ref="cartList"] { flex: none; overflow: visible; }
    .mobile-sticky-pay { position: sticky; bottom: 0; z-index: 20; background: #f9fafb; border-top: 1px solid rgba(148,163,184,.28); box-shadow: 0 -10px 24px rgba(0,0,0,.10); padding-bottom: env(safe-area-inset-bottom, 0); }
    .dark .mobile-sticky-pay { background: #111827; }
    .mobile-collapse-header { cursor: pointer; user-select: none; }
    .mobile-collapse-header::after { content: '▾'; float: right; transition: transform 0.2s; font-size: 10px; color: #9ca3af; }
    .mobile-collapse-header.collapsed::after { transform: rotate(-90deg); }
    .prod-card { min-height: 0; }
    .prod-card:hover { transform: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
    .prod-card:active { transform: scale(0.96); }
    .cart-item { padding: 10px 12px !important; }
    .cart-item .qty-btn-mobile { min-width: 44px; min-height: 44px; }

    /* ── Mobile polish (Jul 2026): keyboard hints are meaningless on touch ── */
    .tn-sale-root kbd { display: none !important; }

    /* Toggles strip: wrap neatly instead of clipping PRA Reporting off-screen */
    .tn-toggles-strip { flex-wrap: wrap; justify-content: center; row-gap: 4px; column-gap: 14px; padding: 5px 8px; }
    .tn-toggles-strip .w-px { display: none; }
    .tn-toggles-strip span:first-child { white-space: nowrap; }

    /* Guided-flow coach strip: single tidy line — no chevrons, tighter pills */
    .tn-flow-strip { gap: 3px; padding: 4px 6px; flex-wrap: nowrap; overflow-x: auto; }
    .tn-flow-strip svg { display: none; }
    .tn-flow-strip span[x-text] { padding: 2px 7px; font-size: 10px; white-space: nowrap; }

    /* Action bar (2 rows since Jul 2026): customer full-width on row 1, search full-width
       on row 2; buttons wrap below their row (nothing clipped anymore) */
    .tn-action-bar { flex-wrap: wrap; row-gap: 6px; }
    .tn-action-row1 > div:first-child { min-width: 0 !important; max-width: none !important; flex: 1 1 100%; }
    .tn-action-row2 > .flex-1.relative { flex: 1 1 100%; }
    /* Tidy wrap (25 Jul 2026): stretch the action-bar buttons evenly so wrapped
       rows form a clean grid instead of ragged left-hugging pills. */
    .tn-action-row1 > button, .tn-action-row2 > button { flex: 1 1 auto; justify-content: center; }
    .tn-action-row1 > div.overflow-hidden { flex: 1 1 auto; display: flex; }
    .tn-action-row1 > div.overflow-hidden > button { flex: 1 1 auto; }
    /* F-key chips are meaningless on touch — hide them, show the text labels instead */
    .tn-key-chip { display: none !important; }
    .tn-action-bar > button > span.hidden:not(.font-mono) { display: inline; }

    /* Product grid: tighter cards, always-visible + button (no hover on touch) */
    [x-ref="gridContainer"] { padding: 8px; }
    [x-ref="gridContainer"] .grid { gap: 8px; }
    .prod-card .quick-add { opacity: 1; transform: none; }
    .prod-card .price-badge { font-size: 12px; padding: 1px 6px; }
    .cat-pill { padding-left: 12px; padding-right: 12px; }
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
.search-glow:focus { box-shadow: 0 0 0 3px rgba(124,58,237,0.15), 0 0 20px rgba(124,58,237,0.1) !important; border-color: #7c3aed !important; }
.dark .search-glow:focus { box-shadow: 0 0 0 3px rgba(167,139,250,0.2), 0 0 20px rgba(167,139,250,0.08) !important; }
@keyframes heldBadgePulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.25); } }
.held-badge-pulse { animation: heldBadgePulse 1.5s ease-in-out infinite; }
.cat-pill.active::after { content: ''; position: absolute; bottom: 0; left: 15%; right: 15%; height: 3px; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.8), transparent); border-radius: 2px; }
.total-animate { transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
.confetti-piece { position: absolute; width: 8px; height: 8px; border-radius: 2px; animation: confettiFall 1.5s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards; pointer-events: none; }
.receipt-modal-enter { animation: receiptSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
.success-icon-animate { animation: successPulse 1.5s ease-out 0.3s; }

/* -----------------------------------------------------------
   Phase 6 — PREMIUM POLISH LAYER (v13)
   Pure additive CSS. No HTML/JS structural changes.
   Design tokens, refined hover states, tighter rhythm,
   better numerics, consistent button feel, calmer chrome.
   ----------------------------------------------------------- */

:root {
    --tn-radius:14px;
    --tn-radius-sm:10px;
    --tn-ease: cubic-bezier(0.16, 1, 0.3, 1);
    --tn-dur-fast:.15s;
    --tn-dur:.2s;
    --tn-primary:#6366f1;       /* indigo-500 */
    --tn-primary-strong:#4f46e5;/* indigo-600 */
    --tn-accent:#a855f7;        /* purple-500 */
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
.cart-item:hover { background: linear-gradient(90deg, rgba(124,58,237,.04), transparent); }
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
<script>
window.history.pushState(null, null, window.location.href);
window.addEventListener('popstate', function() {
    window.history.pushState(null, null, window.location.href);
});
</script>

{{-- Screen Fit (Jul 2026): fitStyleStr applies CSS zoom + a /zoom-compensated px height
     so the sale screen renders correctly on ANY display (small shop laptops, low-res
     terminals, big TVs). Auto mode picks the zoom from viewport size; manual % is
     per-device via localStorage 'tn_screen_fit'. Empty string = normal 100% layout. --}}
<div data-tn-sale-document="pra" data-tn-sale-root x-data="restaurantPos()" @wheel="handleGlobalWheel($event)" class="tn-sale-root flex flex-col h-[calc(100vh-48px)] overflow-hidden bg-gray-50 dark:bg-gray-950" :style="fitStyleStr">

    {{-- Task 127: Starter offline-locked notice — persistent (while offline), dismissible.
         Shows ONLY when the shop is offline AND the plan does not allow offline billing,
         so cashiers know this is a package limit, not a bug. Admin/owner get an upgrade
         link to billing; confined cashiers get "ask your admin" text instead.
         Uses amber (warning) tones — not red — since nothing is broken. --}}
    <div x-cloak x-show="syncStatus === 'offline' && !offlineAllowed && !offlineLockDismissed"
         class="flex items-start gap-3 px-4 py-2.5 bg-amber-50 dark:bg-amber-900/30 border-b border-amber-300 dark:border-amber-700 flex-shrink-0">
        <svg class="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
        <div class="flex-1 min-w-0">
            <p class="text-[13px] font-bold text-amber-800 dark:text-amber-200">{{ __('pos.offline_locked_title') }}</p>
            <p class="text-[12px] text-amber-700 dark:text-amber-300">{{ __('pos.offline_locked_body') }}
                @if(auth('pos')->user()?->isPosCashier())
                    <span class="font-semibold">{{ __('pos.offline_locked_ask_admin') }}</span>
                @endif
            </p>
        </div>
        @unless(auth('pos')->user()?->isPosCashier())
        <a href="{{ route('pos.billing') }}" class="flex-shrink-0 px-3 py-1.5 rounded-lg text-[12px] font-bold bg-amber-600 hover:bg-amber-700 text-white transition self-center">{{ __('pos.offline_locked_upgrade') }}</a>
        @endunless
        <button type="button" @click="offlineLockDismissed = true" class="flex-shrink-0 p-1 rounded-lg text-amber-700 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-800/40 transition self-center" title="{{ __('pos.dismiss') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    {{-- ═══════════ NAV SALE TOOLS (Jul 2026 redesign, owner-approved mockup) ═══════════
         Desktop (md+): the utility pills (Local/Failed/Reprint/Held + sync), the "+ New Sale"
         action and a "Switches" dropdown (PRA / Auto-Print / Auto-KOT) live INSIDE the black
         top-nav — teleported into #tn-nav-sale-tools (pos-app.blade.php) via x-teleport so
         they KEEP this restaurantPos() Alpine scope. The old in-page buttons + toggles strip
         below stay as the MOBILE fallback (md:hidden) — same state, same handlers. --}}
    <template x-teleport="#tn-nav-sale-tools">
        {{-- mx-auto: centered while it fits; parent #tn-nav-sale-tools is overflow-x-auto so on
             narrow screens the strip scrolls instead of spilling over the user menu (ZFC bug). --}}
        <div class="flex items-center gap-1.5 mx-auto flex-shrink-0" x-data="{ switchesOpen: false, autoPrintLoading: false, autoKotLoading: false, swTop: 0, swRight: 0 }">

            {{-- + New Sale — replaces the static nav link on this page (action = clear & restart) --}}
            <button @click="newSale()" class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-white bg-purple-600 hover:bg-purple-700 shadow-sm transition flex-shrink-0" title="{{ __('pos.ti_new_sale_clear') }}">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                <span class="hidden lg:inline">{{ __('pos.new_sale') }}</span>
            </button>

            {{-- Local (provisional) bills — F10. Hidden for 'pra'-scoped staff:
                 their provisional list is server-emptied anyway. --}}
            @if($uBillScope !== 'pra')
            <button @click="openLocalBills()" class="relative flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-purple-700 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 hover:bg-purple-100 transition flex-shrink-0" title="Provisional bills (local — not submitted to PRA). Press F10.">
                <span class="tn-key-chip text-[9px] bg-purple-400/30 px-1 rounded">F10</span>
                <span class="hidden lg:inline">Local</span>
                <span x-show="localBills.length > 0" class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 bg-purple-600 text-white text-[9px] rounded-full flex items-center justify-center font-bold" x-text="localBills.length"></span>
            </button>
            @endif

            {{-- Pending Deliveries (Task 114) — today's delivery provisionals, one-click final.
                 Button only appears when there IS something pending (light footprint). --}}
            {{-- Task 524: button purani unassigned par bhi khulta hai (reachability),
                 magar numeric badge sirf FRESH ginti dikhata hai. --}}
            <button x-show="pendingDeliveryBills().length > 0 || staleDeliveryBills().length > 0" x-cloak @click="openPendingDeliveries()" class="relative flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 hover:bg-amber-100 transition flex-shrink-0" title="{{ __('pos.pending_deliveries_hint') }}">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
                <span class="hidden lg:inline">{{ __('pos.pending_deliveries') }}</span>
                <span x-show="pendingDeliveryBills().length > 0" class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 bg-amber-600 text-white text-[9px] rounded-full flex items-center justify-center font-bold" x-text="pendingDeliveryBills().length"></span>
            </button>

            {{-- Delivery Board (Task 431): rider assign / delivered / settle in a modal
                 iframe — delivery manager ko alag ID/window ki zaroorat nahi. --}}
            @if($showDeliveriesBoardBtn)
            <button type="button" onclick="tnOpenDeliveryBoard()" class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 hover:bg-emerald-100 transition flex-shrink-0" title="{{ __('pos.deliveries') }}">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
                <span class="hidden lg:inline">{{ __('pos.deliveries') }}</span>
            </button>
            @endif

            {{-- Failed PRA bills — F11 --}}
            <button @click="openFailedBills()" class="relative flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 hover:bg-red-100 transition flex-shrink-0" title="{{ __('pos.ti_failed_pra_f11') }}">
                <span class="tn-key-chip text-[9px] bg-red-400/30 px-1 rounded">F11</span>
                <span class="hidden lg:inline">{{ __('pos.failed_word_html') }}</span>
                <span x-show="failedBills.length > 0" class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 bg-red-600 text-white text-[9px] rounded-full flex items-center justify-center font-bold animate-pulse" x-text="failedBills.length"></span>
            </button>

            {{-- Reprint today's bills — Alt+R (teal family, no-blue rule) --}}
            <button @click="openReprint()" class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-teal-700 dark:text-teal-400 bg-teal-50 dark:bg-teal-900/20 border border-teal-200 dark:border-teal-800 hover:bg-teal-100 transition flex-shrink-0" title="{{ __('pos.ti_reprint_today') }}">
                <span class="tn-key-chip text-[9px] bg-teal-400/30 px-1 rounded">Alt+R</span>
                <span class="hidden lg:inline">{{ __('pos.reprint') }}</span>
            </button>

            {{-- Quick Return (Task 681) — bill number likho, seedha return form.
                 Sirf returnsAllowed staff (Task 678 verdict). Rose family. --}}
            @if($canQuickReturn)
            <button @click="openQuickReturn()" class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-rose-700 dark:text-rose-400 bg-rose-50 dark:bg-rose-900/20 border border-rose-200 dark:border-rose-800 hover:bg-rose-100 transition flex-shrink-0" title="{{ __('pos.quick_return_hint') }}">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 15v-1a4 4 0 00-4-4H8m0 0l3 3m-3-3l3-3m9 14V5a2 2 0 00-2-2H6a2 2 0 00-2 2v16l4-2 4 2 4-2 4 2z"/></svg>
                <span class="hidden lg:inline">{{ __('pos.quick_return') }}</span>
            </button>
            @endif

            {{-- Held orders — F3 RETIRED (owner, 26 Jul 2026). Table companies:
                 held orders ab TABLE board ke andar hain (tiles + "bina table"
                 chips) — yeh button sirf NON-table companies ke liye bacha hai. --}}
            @unless($features->tables ?? false)
            <button @click="activeHeldIndex = 0; showHeldOrders = !showHeldOrders" class="relative flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 hover:bg-amber-100 transition flex-shrink-0" title="{{ __('pos.ti_held_orders') }}">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span class="hidden lg:inline">{{ __('pos.held') }}</span>
                <span x-show="heldOrders.length > 0" class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 bg-red-500 text-white text-[9px] rounded-full flex items-center justify-center font-bold" x-text="heldOrders.length"></span>
            </button>
            @endunless

            {{-- 🟢/🟡/🔴 Auto-Sync status pill — same logic as the mobile copy --}}
            <button type="button" @click="syncOfflineBills(true)" class="flex items-center gap-1.5 px-2 py-1.5 rounded-lg text-[10px] font-bold border transition flex-shrink-0"
                 :class="syncStatus === 'online' ? 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800' : (syncStatus === 'syncing' ? 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800' : 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 border-red-200 dark:border-red-800')"
                 :title="offlineNeedsLogin ? window.TXT.ti_session_expired_sync : (syncStatus === 'online' ? (window.TXT.ti_auto_sync_online + ((failedBills.length + offlineQueueCount) ? ' · ' + (failedBills.length + offlineQueueCount) + window.TXT.ti_pending_click_sync : '')) : (syncStatus === 'syncing' ? window.TXT.ti_syncing_pending : window.TXT.ti_offline_auto_sync))">
                <span class="w-2 h-2 rounded-full"
                      :class="syncStatus === 'online' ? 'bg-emerald-500' : (syncStatus === 'syncing' ? 'bg-amber-500 animate-pulse' : 'bg-red-500 animate-pulse')"></span>
                <span class="hidden xl:inline" x-text="syncStatus === 'online' ? window.TXT.online : (syncStatus === 'syncing' ? window.TXT.syncing_word : window.TXT.offline)"></span>
                <span x-show="(failedBills.length + offlineQueueCount) > 0" class="px-1.5 rounded-full text-[9px] font-black"
                      :class="syncStatus === 'online' ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white'"
                      x-text="failedBills.length + offlineQueueCount"></span>
            </button>

            {{-- PRA/LOCAL billing-MODE badge (Task 1164, owner 18 Aug 2026) — always-visible
                 stream sign so nobody has to open Switches to know which mode the screen is
                 billing in. Bound to root praEnabled → an admin flip in the Switches dropdown
                 updates it instantly; scope-locked cashiers keep the server-baked static value.
                 DISTINCT from the Auto-Sync pill (that one = network): shield icon + PRA ON /
                 LOCAL wording, no status dot. Hidden for Standalone-edition companies. --}}
            @if(($company->pos_integration_mode ?? 'pra') !== 'standalone')
            <span class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-wide border flex-shrink-0 select-none"
                  :class="praEnabled ? 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800' : 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800'"
                  :title="praEnabled ? window.TXT.ti_pra_mode_on : window.TXT.ti_pra_mode_local">
                <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.031 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                <span x-text="praEnabled ? window.TXT.pra_mode_on : window.TXT.pra_mode_local"></span>
            </span>
            @endif

            {{-- Switches dropdown — PRA Reporting / Auto-Print / Auto-KOT (same handlers
                 as the mobile toggles strip; cashiers see the read-only PRA badge).
                 NOTE: wrapper is intentionally NOT `relative` — the panel is position:fixed
                 (anchored to the button rect on open) so it escapes the overflow-x-auto
                 clipping of #tn-nav-sale-tools and stays attached to its trigger. --}}
            <div class="flex-shrink-0">
                <button type="button" x-ref="swBtn" @click="switchesOpen = !switchesOpen; if (switchesOpen) { var r = $refs.swBtn.getBoundingClientRect(); swTop = r.bottom + 8; swRight = Math.max(8, window.innerWidth - r.right); }" class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-bold text-white bg-white/10 hover:bg-white/20 ring-1 ring-white/15 transition" title="{{ __('pos.ti_switches') }}">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <span class="hidden lg:inline">{{ __('pos.switches') }}</span>
                    <svg class="w-3 h-3 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
                {{-- Panel is position:fixed anchored to the button rect (computed on open) —
                     it must escape the overflow-x-auto clip of #tn-nav-sale-tools AND stay
                     visually attached to its trigger on wide screens (strip is centered). --}}
                <div x-show="switchesOpen" x-cloak @click.outside="switchesOpen = false" x-transition
                     :style="'top:' + swTop + 'px; right:' + swRight + 'px;'"
                     class="fixed bg-white dark:bg-gray-900 rounded-xl shadow-2xl shadow-black/20 border border-gray-200/80 dark:border-gray-700/80 p-3 z-[100] w-64 space-y-3">

                    @if(($company->pos_integration_mode ?? 'pra') !== 'standalone')
                    {{-- Billing Scope: stream-locked staff (incl. managers) get the
                         read-only badge — their reporting flag is welded to the scope. --}}
                    @if(auth('pos')->user()?->isPosCashier() || $uBillScope !== 'both')
                    @php $praAssignedOnNav = (bool) (auth('pos')->user()?->praReportingEnabled($company)); @endphp
                    <div class="flex items-center justify-between gap-2" title="{{ __('pos.ti_pra_admin_set') }}">
                        <span class="text-[10px] uppercase tracking-wider font-extrabold text-purple-700 dark:text-purple-300">{{ __('pos.pra_reporting') }}</span>
                        @if($praAssignedOnNav)
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 text-[10px] font-black uppercase tracking-wide">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> {{ __('pos.online') }}
                        </span>
                        @else
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 text-[10px] font-black uppercase tracking-wide">
                            <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span> {{ __('pos.offline') }}
                        </span>
                        @endif
                    </div>
                    @else
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[10px] uppercase tracking-wider font-extrabold text-purple-700 dark:text-purple-300">{{ __('pos.pra_reporting') }}</span>
                        <div class="flex items-center gap-1.5">
                            <button type="button"
                                @click="praLoading = true; fetch('{{ route('pos.api.toggle-pra') }}', { method:'POST', headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}', 'Content-Type':'application/json', 'Accept':'application/json' } }).then(r => r.json()).then(d => { praEnabled = !!d.enabled; praLoading = false; window.tnNotify && window.tnNotify(window.TXT.pra_reporting, praEnabled ? window.TXT.enabled_word : window.TXT.disabled_word); }).catch(() => { praLoading = false; alert(window.TXT.toggle_failed); })"
                                :disabled="praLoading"
                                :class="praEnabled ? 'bg-purple-600' : 'bg-gray-400 dark:bg-gray-600'"
                                class="relative inline-flex h-5 w-10 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out shadow-inner">
                                <span :class="praEnabled ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                            </button>
                            <span x-text="praEnabled ? 'ON' : 'OFF'" :class="praEnabled ? 'text-purple-700 dark:text-purple-300' : 'text-gray-500 dark:text-gray-400'" class="text-[10px] font-black w-7"></span>
                            <span x-show="praLoading" class="text-[10px] text-purple-500 animate-pulse">…</span>
                        </div>
                    </div>
                    @endif
                    @endif

                    <div class="flex items-center justify-between gap-2" title="{{ __('pos.ti_auto_print_hint') }}">
                        <span class="text-[10px] uppercase tracking-wider font-extrabold text-emerald-700 dark:text-emerald-300">{{ __('pos.auto_print_label') }}</span>
                        <div class="flex items-center gap-1.5">
                            <button type="button"
                                @click="autoPrintLoading = true; fetch('{{ route('pos.api.toggle-auto-print') }}', { method:'POST', headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}', 'Content-Type':'application/json', 'Accept':'application/json' } }).then(r => r.json()).then(d => { autoPrintEnabled = !!d.enabled; kitchenSettings.print_on_pay = autoPrintEnabled; autoPrintLoading = false; window.tnNotify && window.tnNotify(window.TXT.auto_print_receipt, autoPrintEnabled ? window.TXT.enabled_word : window.TXT.disabled_word); }).catch(() => { autoPrintLoading = false; alert(window.TXT.toggle_failed); })"
                                :disabled="autoPrintLoading"
                                :class="autoPrintEnabled ? 'bg-emerald-600' : 'bg-gray-400 dark:bg-gray-600'"
                                class="relative inline-flex h-5 w-10 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out shadow-inner">
                                <span :class="autoPrintEnabled ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                            </button>
                            <span x-text="autoPrintEnabled ? 'ON' : 'OFF'" :class="autoPrintEnabled ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-500 dark:text-gray-400'" class="text-[10px] font-black w-7"></span>
                            <span x-show="autoPrintLoading" class="text-[10px] text-emerald-500 animate-pulse">…</span>
                        </div>
                    </div>

                    @if($features->kot ?? false)
                    <div class="flex items-center justify-between gap-2" title="{{ __('pos.ti_auto_kot_hint') }}">
                        <span class="text-[10px] uppercase tracking-wider font-extrabold text-orange-700 dark:text-orange-300">{{ __('pos.auto_kot_label') }}</span>
                        <div class="flex items-center gap-1.5">
                            <button type="button"
                                @click="autoKotLoading = true; fetch('{{ route('pos.api.toggle-auto-kot') }}', { method:'POST', headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}', 'Content-Type':'application/json', 'Accept':'application/json' } }).then(r => r.json()).then(d => { if (d.success) { autoKotEnabled = !!d.enabled; window.tnNotify && window.tnNotify(window.TXT.auto_kot, autoKotEnabled ? window.TXT.enabled_word : window.TXT.disabled_word); } else { alert(d.message || window.TXT.toggle_failed); } autoKotLoading = false; }).catch(() => { autoKotLoading = false; alert(window.TXT.toggle_failed); })"
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

                    @if($features->restaurant_mode ?? false)
                    {{-- Order Sound (owner request, 1 Aug 2026): chime when a NEW waiter
                         order lands. DEVICE-level pref (localStorage) — sound is a
                         per-counter choice, not a company setting. Default ON. --}}
                    <div class="flex items-center justify-between gap-2" title="{{ __('pos.ti_order_sound_hint') }}">
                        <span class="text-[10px] uppercase tracking-wider font-extrabold text-teal-700 dark:text-teal-300">{{ __('pos.order_sound_label') }}</span>
                        <div class="flex items-center gap-1.5">
                            <button type="button"
                                @click="orderSound = !orderSound; try { localStorage.setItem('pos_order_sound', orderSound ? '1' : '0'); } catch(e) {} if (orderSound) playOrderChime(); window.tnNotify && window.tnNotify(window.TXT.order_sound_label, orderSound ? window.TXT.enabled_word : window.TXT.disabled_word);"
                                :class="orderSound ? 'bg-teal-600' : 'bg-gray-400 dark:bg-gray-600'"
                                class="relative inline-flex h-5 w-10 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out shadow-inner">
                                <span :class="orderSound ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5"></span>
                            </button>
                            <span x-text="orderSound ? 'ON' : 'OFF'" :class="orderSound ? 'text-teal-700 dark:text-teal-300' : 'text-gray-500 dark:text-gray-400'" class="text-[10px] font-black w-7"></span>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </template>

    {{-- PRA Reporting + Auto-Print toggles strip — MOBILE FALLBACK ONLY (md:hidden) since the
         Jul 2026 redesign moved these switches into the top-nav dropdown on desktop.
         autoPrintEnabled lives on the parent restaurantPos() scope (mirrors kitchenSettings.print_on_pay)
         so toggling immediately updates the receipt-iframe URL on the very next sale, no refresh needed. --}}
    <div class="tn-toggles-strip flex md:hidden items-center justify-end gap-4 px-3 py-1.5 bg-purple-50 dark:bg-purple-900/10 border-b border-purple-100 dark:border-purple-900/30 flex-shrink-0"
         x-data="{
            autoPrintLoading: false,
            autoKotLoading: false
         }">

        {{-- COUNTER chip — MOBILE copy (Task 1349): the desktop one lives in the
             cart-column header, which is hidden below md. --}}
        <button type="button" x-show="allTerminals.length > 0" x-cloak @click="showTerminalPicker = true"
                class="flex items-center gap-1 px-2 py-0.5 rounded-full border text-[10px] font-black uppercase tracking-wide mr-auto"
                :class="terminalId ? 'bg-indigo-50 dark:bg-indigo-900/20 text-indigo-700 dark:text-indigo-300 border-indigo-200 dark:border-indigo-800' : 'bg-white dark:bg-gray-800 text-gray-500 dark:text-gray-400 border-dashed border-gray-300 dark:border-gray-600'">
            <svg class="w-3 h-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M4 7l1 12a1 1 0 001 1h12a1 1 0 001-1l1-12M4 7l1.2-2.4A1 1 0 016.1 4h11.8a1 1 0 01.9.6L20 7M9 11h6"/></svg>
            <span x-text="terminalId ? terminalName() : window.TXT.counter_pick_prompt"></span>
        </button>

        {{-- PRA Reporting — hidden entirely for Standalone-edition companies (no
             government integration): flipping it ON would queue every sale for PRA
             submission that can only fail. togglePra also rejects server-side. --}}
        @if(($company->pos_integration_mode ?? 'pra') !== 'standalone')
        @if(auth('pos')->user()?->isPosCashier() || $uBillScope !== 'both')
        {{-- Owner rule (20 Jul 2026): cashiers do NOT get the PRA toggle — the admin
             ASSIGNS each cashier Online/Offline from /pos/team. Read-only badge only;
             togglePra also rejects cashier POSTs server-side. Billing Scope (07 Aug
             2026): scope-locked managers get the same read-only badge. --}}
        @php $praAssignedOn = (bool) (auth('pos')->user()?->praReportingEnabled($company)); @endphp
        <div class="flex items-center gap-2" title="{{ __('pos.ti_pra_admin_set') }}">
            <span class="text-[10px] uppercase tracking-wider font-extrabold text-purple-700 dark:text-purple-300">{{ __('pos.pra_reporting') }}</span>
            @if($praAssignedOn)
            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 text-[10px] font-black uppercase tracking-wide">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> {{ __('pos.online') }}
            </span>
            @else
            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 text-[10px] font-black uppercase tracking-wide">
                <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span> {{ __('pos.offline') }}
            </span>
            @endif
        </div>

        <div class="w-px h-4 bg-purple-200 dark:bg-purple-800/40"></div>
        @else
        <div class="flex items-center gap-2">
            <span class="text-[10px] uppercase tracking-wider font-extrabold text-purple-700 dark:text-purple-300">{{ __('pos.pra_reporting') }}</span>
            <button type="button"
                @click="praLoading = true; fetch('{{ route('pos.api.toggle-pra') }}', { method:'POST', headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}', 'Content-Type':'application/json', 'Accept':'application/json' } }).then(r => r.json()).then(d => { praEnabled = !!d.enabled; praLoading = false; window.tnNotify && window.tnNotify(window.TXT.pra_reporting, praEnabled ? window.TXT.enabled_word : window.TXT.disabled_word); }).catch(() => { praLoading = false; alert(window.TXT.toggle_failed); })"
                :disabled="praLoading"
                :class="praEnabled ? 'bg-purple-600' : 'bg-gray-400 dark:bg-gray-600'"
                class="relative inline-flex h-5 w-10 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out shadow-inner">
                <span :class="praEnabled ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5"></span>
            </button>
            <span x-text="praEnabled ? 'ON' : 'OFF'" :class="praEnabled ? 'text-purple-700 dark:text-purple-300' : 'text-gray-500 dark:text-gray-400'" class="text-[10px] font-black w-7"></span>
            <span x-show="praLoading" class="text-[10px] text-purple-500 animate-pulse">…</span>
        </div>

        <div class="w-px h-4 bg-purple-200 dark:bg-purple-800/40"></div>
        @endif
        @endif

        {{-- Auto-Print on Sale (Phase 4) — bound to parent restaurantPos() scope --}}
        <div class="flex items-center gap-2" title="{{ __('pos.ti_auto_print_hint') }}">
            <span class="text-[10px] uppercase tracking-wider font-extrabold text-emerald-700 dark:text-emerald-300">{{ __('pos.auto_print_label') }}</span>
            <button type="button"
                @click="autoPrintLoading = true; fetch('{{ route('pos.api.toggle-auto-print') }}', { method:'POST', headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}', 'Content-Type':'application/json', 'Accept':'application/json' } }).then(r => r.json()).then(d => { autoPrintEnabled = !!d.enabled; kitchenSettings.print_on_pay = autoPrintEnabled; autoPrintLoading = false; window.tnNotify && window.tnNotify(window.TXT.auto_print_receipt, autoPrintEnabled ? window.TXT.enabled_word : window.TXT.disabled_word); }).catch(() => { autoPrintLoading = false; alert(window.TXT.toggle_failed); })"
                :disabled="autoPrintLoading"
                :class="autoPrintEnabled ? 'bg-emerald-600' : 'bg-gray-400 dark:bg-gray-600'"
                class="relative inline-flex h-5 w-10 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out shadow-inner">
                <span :class="autoPrintEnabled ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5"></span>
            </button>
            <span x-text="autoPrintEnabled ? 'ON' : 'OFF'" :class="autoPrintEnabled ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-500 dark:text-gray-400'" class="text-[10px] font-black w-7"></span>
            <span x-show="autoPrintLoading" class="text-[10px] text-emerald-500 animate-pulse">…</span>
        </div>

        @if($features->kot ?? false)
        <div class="w-px h-4 bg-purple-200 dark:bg-purple-800/40"></div>

        {{-- Auto-KOT (Phase 5+) — when ON, the kitchen ticket print dialog also pops
             open right after a successful payment of a held/restaurant order. --}}
        <div class="flex items-center gap-2" title="{{ __('pos.ti_auto_kot_hint') }}">
            <span class="text-[10px] uppercase tracking-wider font-extrabold text-orange-700 dark:text-orange-300">{{ __('pos.auto_kot_label') }}</span>
            <button type="button"
                @click="autoKotLoading = true; fetch('{{ route('pos.api.toggle-auto-kot') }}', { method:'POST', headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}', 'Content-Type':'application/json', 'Accept':'application/json' } }).then(r => r.json()).then(d => { if (d.success) { autoKotEnabled = !!d.enabled; window.tnNotify && window.tnNotify(window.TXT.auto_kot, autoKotEnabled ? window.TXT.enabled_word : window.TXT.disabled_word); } else { alert(d.message || window.TXT.toggle_failed); } autoKotLoading = false; }).catch(() => { autoKotLoading = false; alert(window.TXT.toggle_failed); })"
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

    {{-- ── BODY ROW (ZFC feedback, 25 Jul 2026): action-bar rows 1+2 moved INSIDE the
         left (products) column so the cart column spans the FULL height — the band
         above the cart used to sit empty. Mobile stacking order unchanged (bars on
         top, cart below via .tn-body-row column direction under 768px). --}}
    <div class="tn-body-row" :class="!showProducts ? 'tn-widecart' : ''">
    <div class="tn-left-col" :class="mobileView === 'menu' ? 'flex-1' : ''">

    {{-- flex-wrap: on narrow displays the action buttons wrap to a second row instead of
         being clipped off-screen (overflow-hidden root swallows anything past the edge).
         ROW 1 (owner, 24 Jul 2026): customer box WIDE + order-context widgets + utility
         buttons; category + full-width search moved to their own ROW 2 below. --}}
    <div class="tn-action-bar tn-action-row1 flex flex-wrap items-center gap-2 px-3 pt-2 pb-1.5 bg-white dark:bg-gray-900 flex-shrink-0">

        {{-- Customer box: FIRST in the action bar for ALL styles — the guided flow
             starts with the customer step, so this box must stay at the start (owner,
             23 Jul 2026: the earlier saaf move to the cart panel broke the sequence
             and put the category dropdown where customer used to be — REVERTED; do
             not relocate this box per-style again). --}}
        @include('pos.partials.sale-customer-box')


        {{-- Top "Table" button REMOVED (owner, 26 Jul 2026) — sirf frontend se.
             Picker ab Dine In pill se khulta hai (selected table pill par dikhta
             hai, waiter-order teal badge bhi wahin shift ho gaya); TABLE board
             neeche cart ke saath maujood hai. openTablePicker/selectedTable ka
             poora backend flow UNTOUCHED. --}}

        {{-- Order-type switcher (Dine In / Takeaway / Delivery): RESTAURANT-category
             companies only (owner rule, Jul 2026). A plain retail/general store has no
             order types — a lone always-on "Takeaway" pill just confuses cashiers, so
             the whole widget is hidden unless a restaurant feature (tables/KOT/kitchen)
             or Delivery is enabled. orderType silently stays 'takeaway' underneath. --}}
        @if(($features->tables ?? false) || ($features->kot ?? false) || ($features->kitchen ?? false) || ($features->delivery ?? false))
        <div class="flex items-center rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden flex-shrink-0" title="{{ __('pos.ti_press_f2_cycle') }}">
            @if($features->tables)
            {{-- Dine In pill (owner, 26 Jul 2026): selected table isi pill mein
                 dikhta hai ("Dine In · T-3"); dobara click = picker (table change).
                 Teal count chip = waiter orders waiting in the picker (Table-se-Bill
                 badge — top Table button ke saath yahan shift hua; INLINE chip,
                 absolute nahi — parent div overflow-hidden badge kaat deta). --}}
            <button @click="setOrderType('dine_in')" class="flex items-center gap-1 px-3.5 py-2 text-xs font-bold transition-all" :class="orderType === 'dine_in' ? 'bg-purple-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 hover:bg-gray-100'">
                <span x-text="selectedTable ? window.TXT.dine_in_t_prefix + selectedTable.table_number : window.TXT.dine_in"></span>
                <span x-show="incomingOrders.length > 0" x-cloak class="min-w-[16px] h-[16px] px-1 bg-teal-600 text-white text-[9px] rounded-full inline-flex items-center justify-center font-bold animate-pulse" x-text="incomingOrders.length"></span>
            </button>
            @endif
            <button @click="setOrderType('takeaway')" class="px-3.5 py-2 text-xs font-bold transition-all border-x border-gray-200 dark:border-gray-700" :class="orderType === 'takeaway' ? 'bg-purple-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 hover:bg-gray-100'">{{ __('pos.takeaway') }}</button>
            @if($features->delivery)
            <button @click="setOrderType('delivery')" class="px-3.5 py-2 text-xs font-bold transition-all" :class="orderType === 'delivery' ? 'bg-purple-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 hover:bg-gray-100'">{{ __('pos.delivery') }}</button>
            @endif
            <span class="tn-key-chip px-1.5 py-1.5 text-[8px] font-mono text-gray-400 bg-gray-50 dark:bg-gray-800 border-l border-gray-200 dark:border-gray-700">F2</span>
        </div>

        {{-- Item #3 (owner, Jul 2026): delivery charges input MOVED to the cart panel's
             Delivery section (customer feedback, 25 Jul 2026) — see "Delivery Charges"
             strip under the Current Order header. Mechanism unchanged: TAX-EXEMPT manual
             cart line via setDeliveryCharge(). --}}
        @endif

        <div class="w-px h-6 bg-gray-200 dark:bg-gray-700 hidden sm:block flex-shrink-0"></div>

        @if($isSaaf)
        {{-- SAAF: "Mazeed" toggle — reveals the secondary toolbar buttons (hidden by
             pos-saaf.css via [data-saaf-secondary]) without removing them from the DOM,
             so ALL features + F-key shortcuts keep working exactly as on the Full look. --}}
        <button type="button" onclick="document.body.classList.toggle('saaf-show-all')" class="saaf-more-btn flex items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition flex-shrink-0" title="{{ __('pos.ti_more_options') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 12.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 18.75a.75.75 0 110-1.5.75.75 0 010 1.5z"/></svg>
            <span>{{ __('pos.more_btn') }}</span>
        </button>
        @endif
        <button @if($isSaaf) data-saaf-secondary="1" @endif @click="priorityOrder = !priorityOrder" class="hidden sm:flex items-center gap-1 px-2.5 py-2 rounded-xl text-xs font-semibold border transition" :class="priorityOrder ? 'bg-red-50 dark:bg-red-900/20 border-red-300 text-red-600' : 'border-gray-200 dark:border-gray-700 text-gray-500 hover:bg-gray-50'">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            <span>{{ __('pos.rush_title') }}</span>
        </button>

        {{-- Screen Fit control (Jul 2026): cashier picks Auto or a fixed % for THIS display; saved per device.
             Visible on ALL sizes including mobile (owner request Jul 2026) — icon-only below lg. --}}
        <div @if($isSaaf) data-saaf-secondary="1" @endif class="relative block flex-shrink-0" @click.away="showFitMenu = false">
            <button @click="showFitMenu = !showFitMenu" class="flex items-center gap-1 px-2 py-2 rounded-xl text-xs font-bold text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 hover:bg-purple-50 hover:text-purple-600 hover:border-purple-300 transition" title="{{ __('pos.ti_screen_fit') }}">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V5a1 1 0 011-1h3m8 0h3a1 1 0 011 1v3m0 8v3a1 1 0 01-1 1h-3m-8 0H5a1 1 0 01-1-1v-3"/></svg>
                <span class="hidden lg:inline" x-text="fitLabel()"></span>
            </button>
            <div x-show="showFitMenu" x-cloak x-transition class="absolute right-0 top-full mt-1 w-48 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl shadow-2xl z-50 overflow-hidden">
                <p class="px-3 pt-2 pb-1 text-[9px] font-bold uppercase tracking-wider text-gray-400">{{ __('pos.screen_fit') }}</p>
                <button @click="setFit('auto')" class="w-full flex items-center justify-between px-3 py-2 text-left text-xs font-semibold hover:bg-purple-50 dark:hover:bg-purple-900/20 transition" :class="screenFit === 'auto' ? 'bg-purple-50 dark:bg-purple-900/20 text-purple-700 dark:text-purple-300' : 'text-gray-700 dark:text-gray-200'"><span>{{ __('pos.fit_auto_recommended') }}</span><span x-show="screenFit === 'auto'" class="text-purple-600 dark:text-purple-400">✓</span></button>
                <button @click="setFit(0.8)" class="w-full flex items-center justify-between px-3 py-2 text-left text-xs font-semibold hover:bg-purple-50 dark:hover:bg-purple-900/20 transition" :class="screenFit === 0.8 ? 'bg-purple-50 dark:bg-purple-900/20 text-purple-700 dark:text-purple-300' : 'text-gray-700 dark:text-gray-200'"><span>{{ __('pos.fit_80_compact') }}</span><span x-show="screenFit === 0.8" class="text-purple-600 dark:text-purple-400">✓</span></button>
                <button @click="setFit(0.9)" class="w-full flex items-center justify-between px-3 py-2 text-left text-xs font-semibold hover:bg-purple-50 dark:hover:bg-purple-900/20 transition" :class="screenFit === 0.9 ? 'bg-purple-50 dark:bg-purple-900/20 text-purple-700 dark:text-purple-300' : 'text-gray-700 dark:text-gray-200'"><span>90%</span><span x-show="screenFit === 0.9" class="text-purple-600 dark:text-purple-400">✓</span></button>
                <button @click="setFit(1)" class="w-full flex items-center justify-between px-3 py-2 text-left text-xs font-semibold hover:bg-purple-50 dark:hover:bg-purple-900/20 transition" :class="screenFit === 1 ? 'bg-purple-50 dark:bg-purple-900/20 text-purple-700 dark:text-purple-300' : 'text-gray-700 dark:text-gray-200'"><span>{{ __('pos.fit_100_standard') }}</span><span x-show="screenFit === 1" class="text-purple-600 dark:text-purple-400">✓</span></button>
                <button @click="setFit(1.1)" class="w-full flex items-center justify-between px-3 py-2 text-left text-xs font-semibold hover:bg-purple-50 dark:hover:bg-purple-900/20 transition" :class="screenFit === 1.1 ? 'bg-purple-50 dark:bg-purple-900/20 text-purple-700 dark:text-purple-300' : 'text-gray-700 dark:text-gray-200'"><span>110%</span><span x-show="screenFit === 1.1" class="text-purple-600 dark:text-purple-400">✓</span></button>
                <button @click="setFit(1.25)" class="w-full flex items-center justify-between px-3 py-2 text-left text-xs font-semibold hover:bg-purple-50 dark:hover:bg-purple-900/20 transition" :class="screenFit === 1.25 ? 'bg-purple-50 dark:bg-purple-900/20 text-purple-700 dark:text-purple-300' : 'text-gray-700 dark:text-gray-200'"><span>{{ __('pos.fit_125_large') }}</span><span x-show="screenFit === 1.25" class="text-purple-600 dark:text-purple-400">✓</span></button>
            </div>
        </div>

        <button @if($isSaaf) data-saaf-secondary="1" @endif @click="showShortcuts = true" class="hidden sm:flex items-center gap-1 px-2 py-2 rounded-xl text-xs font-bold text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 hover:bg-purple-50 hover:text-purple-600 hover:border-purple-300 transition flex-shrink-0" title="{{ __('pos.ti_keyboard_shortcuts_f1') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3C6.5 3 2 6.58 2 11c0 2.24 1.12 4.27 2.94 5.72L4 21l4.28-2.55c1.15.35 2.4.55 3.72.55 5.5 0 10-3.58 10-8s-4.5-8-10-8z"/></svg>
            <span class="hidden lg:inline">{{ __('pos.keys') }}</span>
            <span class="text-[8px] font-mono bg-gray-200 dark:bg-gray-700 px-1 rounded hidden sm:inline">F1</span>
        </button>

        {{-- Quick Type — OPT-IN (Customize POS toggle); hidden server-side when OFF. --}}
        @if($company->pos_quick_type_enabled ?? false)
        <button @if($isSaaf) data-saaf-secondary="1" @endif @click="openQuickType()" class="flex items-center gap-1 px-2 py-2 rounded-xl text-xs font-bold text-sky-700 dark:text-sky-400 bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-800 hover:bg-sky-100 hover:border-sky-300 transition flex-shrink-0" title="{{ __('pos.ti_quick_type_f7') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            <span class="hidden lg:inline">Quick</span>
            <span class="text-[8px] font-mono bg-sky-200 dark:bg-sky-800/50 px-1 rounded hidden sm:inline">F7</span>
        </button>
        @endif

        {{-- Manual Item — only when inventory mode is OFF (Simple Mode).
             Lets the cashier bill an ad-hoc item that isn't in the product list.
             Optional checkbox in the modal also persists it to /pos/products. --}}
        <template x-if="!isInventoryEnabled()">
            <button @click="openManualItem()" class="flex items-center gap-1 px-2 py-2 rounded-xl text-xs font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 hover:bg-emerald-100 hover:border-emerald-300 transition flex-shrink-0" title="{{ __('pos.ti_add_manual_item') }}">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                <span class="hidden lg:inline">{{ __('pos.manual') }}</span>
            </button>
        </template>

        {{-- New Sale — MOBILE ONLY since Jul 2026 redesign (desktop copy teleported into the top-nav) --}}
        <button @click="newSale()" class="flex md:hidden items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 hover:bg-green-100 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            <span class="hidden sm:inline">{{ __('pos.new_word') }}</span>
        </button>

    </div>

    {{-- ── ACTION BAR ROW 2 (owner, 24 Jul 2026): category + a FULL-WIDTH product search
         get their own row so the search box is big and readable on every screen; the
         customer box sits alone (wide) on row 1. Bill-action buttons stay next to search. --}}
    <div class="tn-action-bar tn-action-row2 flex flex-wrap items-center gap-2 px-3 pt-1.5 pb-2 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 flex-shrink-0 shadow-sm">

        {{-- CATEGORY DROPDOWN (optional filter) — same activeCategory as the grid pills, so the two
             stay in sync. Default "All Categories" = old behavior, byte-identical. Unlike the pills
             it is ALWAYS visible (even when the grid is hidden). NOTE (22 Jul 2026): category scopes
             the browsable GRID only — search is always GLOBAL (whole catalog), per customer request.
             Hidden automatically when the company has no categories/services/deals to pick. --}}
        <div class="relative flex-shrink-0" x-show="catOptions().length > 0 || allServices.length > 0 || allDeals.length > 0" x-cloak>
            <select x-model="activeCategory" title="{{ __('pos.ti_category_pra') }}"
                    class="appearance-none pl-3 pr-8 py-2.5 rounded-xl text-xs font-bold border-2 cursor-pointer max-w-[150px] shadow-sm transition focus:ring-2 focus:ring-purple-500 focus:border-purple-400"
                    :class="activeCategory !== 'all' ? 'border-purple-400 bg-purple-50 dark:bg-purple-900/20 text-purple-700 dark:text-purple-300' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300'">
                <option value="all">{{ __('pos.all_categories') }}</option>
                <template x-for="c in catOptions()" :key="c"><option :value="c" x-text="c"></option></template>
                <template x-if="allServices.length > 0"><option value="services">{{ __('pos.services') }}</option></template>
                <template x-if="allDeals.length > 0"><option value="deals">{{ __('pos.deals_label') }}</option></template>
            </select>
            <svg class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </div>

        <div class="flex-1 relative" style="min-width:170px;">
            <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="search" x-ref="searchInput" x-model="searchQuery" @input="onSearchInput()" @keydown.arrow-down.prevent="moveHighlight(1)" @keydown.arrow-up.prevent="moveHighlight(-1)" @keydown.enter.prevent.stop="addHighlightedItem($event)" @keydown.tab="if(flowStep === 'type'){ $event.preventDefault(); } else if(!searchQuery && cart.length > 0){ $event.preventDefault(); enterCartMode('last'); }" @focus="if(searchQuery) showSearchDropdown = true" @click.away="showSearchDropdown = false" placeholder="{{ $isSaaf ? __('pos.search_or_scan_hint') : __('pos.ph_search_products') }}" class="search-glow w-full pl-10 pr-10 py-2.5 rounded-xl text-sm border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-purple-400 transition shadow-sm" autocomplete="one-time-code" name="pos_product_search_nofill" data-lpignore="true" data-form-type="other" role="combobox">
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
                        class="w-full flex items-center gap-3 px-3 py-3 text-left hover:bg-purple-50 dark:hover:bg-purple-900/20 transition group">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center bg-gradient-to-br from-purple-500 to-purple-700 text-white flex-shrink-0 shadow">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.create_q_prefix') }}<span x-text="searchQuery"></span>"</p>
                            <p class="text-[10px] text-gray-400">{{ __('pos.adds_instantly_set_price') }}</p>
                        </div>
                        <span class="text-[9px] font-mono bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300 px-1.5 py-0.5 rounded border border-purple-200 dark:border-purple-800">⏎</span>
                    </button>
                </template>
                <template x-if="isInventoryEnabled()">
                    <div class="px-3 py-3">
                        <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ __('pos.product_not_found') }}</p>
                        <p class="text-[10px] text-gray-400 mb-2">{{ __('pos.inventory_mode_products_hint') }}</p>
                        <a href="{{ route('pos.products') }}" class="inline-flex items-center gap-1.5 text-[11px] font-bold text-purple-600 dark:text-purple-400 hover:text-purple-800 dark:hover:text-purple-300">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            {{ __('pos.open_products') }}
                        </a>
                    </div>
                </template>
            </div>
            <div x-show="quickCreating" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-purple-200 rounded-xl shadow-2xl z-50 px-3 py-3">
                <p class="text-xs text-gray-500 flex items-center gap-2">
                    <svg class="w-4 h-4 animate-spin text-purple-600" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                    {{ __('pos.creating_q_prefix') }}<span x-text="searchQuery" class="font-semibold"></span>"…
                </p>
            </div>
            {{-- Compact search dropdown GLOBAL (customer feedback, 23 Jul 2026 — was Saaf-only):
                 one line per product (no category sub-label), tighter rows, taller list so more
                 results fit without scrolling. Stock dots stay (inline after the name). --}}
            <div x-show="showSearchDropdown && searchSuggestions.length > 0" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-2xl z-50 overflow-y-auto" style="max-height:min(60vh,480px);" x-ref="searchDropdown">
                <template x-for="(s, i) in searchSuggestions" :key="s.id + s.type">
                    <button @click="quickAddItem(s)" @mouseenter="highlightIndex = i"
                        :data-hl="i === highlightIndex ? 'true' : 'false'"
                        class="w-full flex items-center gap-3 px-3 py-1.5 text-left"
                        :style="i === highlightIndex ? 'background:#7c3aed !important; border-radius:10px; margin:2px 4px; width:calc(100% - 8px); box-shadow:0 4px 12px rgba(124,58,237,0.4);' : 'margin:2px 4px; width:calc(100% - 8px);'">
                        <template x-if="s.image">
                            <img :src="s.image" class="w-7 h-7 rounded-lg object-cover flex-shrink-0" :style="i === highlightIndex ? 'outline:2px solid white; outline-offset:1px;' : ''">
                        </template>
                        <template x-if="!s.image">
                            <div class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 bg-purple-100 dark:bg-purple-900/50 text-purple-600 dark:text-purple-300" :style="i === highlightIndex ? 'background:white; color:#7c3aed;' : ''">
                                <span class="text-xs font-bold" x-text="s.name.charAt(0)"></span>
                            </div>
                        </template>
                        <div class="flex-1 min-w-0 flex items-center gap-1.5">
                            <span class="text-sm font-semibold truncate leading-snug text-gray-800 dark:text-gray-100" :style="i === highlightIndex ? 'color:white;' : ''" x-text="s.name"></span>
                            @if($company->inventory_enabled)
                            <template x-if="s.stockStatus && s.stockStatus !== 'available'"><span class="stock-dot flex-shrink-0" :class="'stock-' + s.stockStatus"></span></template>
                            @endif
                        </div>
                        <span class="text-sm font-extrabold flex-shrink-0 text-purple-600 dark:text-purple-300" :style="i === highlightIndex ? 'color:white;' : ''" x-text="'Rs. ' + Number(s.price).toLocaleString()"></span>
                    </button>
                </template>
            </div>
        </div>

        {{-- ── PROVISIONAL BILLS (Local) — header shortcut. Same pattern as Held. ── --}}
        {{-- 🟢/🟡/🔴 Auto-Sync status pill — live network + pending-bill indicator. --}}
        {{-- Offline-first (Jul 2026): badge now ALSO counts device-queued offline bills; click = sync now. --}}
        <button type="button" x-cloak @click="syncOfflineBills(true)" class="flex md:hidden items-center gap-1.5 px-2.5 py-1.5 rounded-xl text-[11px] font-bold border transition"
             :class="syncStatus === 'online' ? 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800' : (syncStatus === 'syncing' ? 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800' : 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 border-red-200 dark:border-red-800')"
             :title="offlineNeedsLogin ? window.TXT.ti_session_expired_sync : (syncStatus === 'online' ? (window.TXT.ti_auto_sync_online + ((failedBills.length + offlineQueueCount) ? ' · ' + (failedBills.length + offlineQueueCount) + window.TXT.ti_pending_click_sync : '')) : (syncStatus === 'syncing' ? window.TXT.ti_syncing_pending : window.TXT.ti_offline_auto_sync))">
            <span class="w-2 h-2 rounded-full"
                  :class="syncStatus === 'online' ? 'bg-emerald-500' : (syncStatus === 'syncing' ? 'bg-amber-500 animate-pulse' : 'bg-red-500 animate-pulse')"></span>
            <span x-text="syncStatus === 'online' ? window.TXT.online : (syncStatus === 'syncing' ? window.TXT.syncing_word : window.TXT.offline)"></span>
            <span x-show="(failedBills.length + offlineQueueCount) > 0" class="ml-0.5 px-1.5 rounded-full text-[9px] font-black"
                  :class="syncStatus === 'online' ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white'"
                  x-text="failedBills.length + offlineQueueCount"></span>
        </button>
        {{-- PRA/LOCAL billing-MODE badge (Task 1164) — mobile copy of the nav badge.
             Same praEnabled binding; distinct from Auto-Sync (network) pill above. --}}
        @if(($company->pos_integration_mode ?? 'pra') !== 'standalone')
        <span x-cloak class="flex md:hidden items-center gap-1 px-2.5 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-wide border select-none"
              :class="praEnabled ? 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800' : 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800'"
              :title="praEnabled ? window.TXT.ti_pra_mode_on : window.TXT.ti_pra_mode_local">
            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.031 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            <span x-text="praEnabled ? window.TXT.pra_mode_on : window.TXT.pra_mode_local"></span>
        </span>
        @endif
        {{-- Click → modal with Edit / Delete / Make Final actions inline. F10 shortcut. --}}
        @if($uBillScope !== 'pra')
        <button @click="openLocalBills()" class="relative flex md:hidden items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-purple-700 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 hover:bg-purple-100 transition" title="Provisional bills (local — not submitted to PRA). Press F10.">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
            <span class="tn-key-chip text-[10px] bg-purple-400/30 px-1 rounded">F10</span>
            <span class="hidden sm:inline">Local</span>
            <span x-show="localBills.length > 0" class="absolute -top-1 -right-1 min-w-[20px] h-5 px-1 bg-purple-600 text-white text-[10px] rounded-full flex items-center justify-center font-bold" x-text="localBills.length"></span>
        </button>
        @endif

        {{-- Pending Deliveries (Task 114) — mobile copy of the nav badge --}}
        <button x-show="pendingDeliveryBills().length > 0 || staleDeliveryBills().length > 0" x-cloak @click="openPendingDeliveries()" class="relative flex md:hidden items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 hover:bg-amber-100 transition" title="{{ __('pos.pending_deliveries_hint') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
            <span class="hidden sm:inline">{{ __('pos.pending_deliveries') }}</span>
            <span x-show="pendingDeliveryBills().length > 0" class="absolute -top-1 -right-1 min-w-[20px] h-5 px-1 bg-amber-600 text-white text-[10px] rounded-full flex items-center justify-center font-bold" x-text="pendingDeliveryBills().length"></span>
        </button>

        {{-- Waiter box RETIRED (Table-se-Bill, Jul 2026): waiter orders now live inside
             the Select-Table picker (purple "Order Tayyar" tables + counter orders).
             The drawer + F6 stay as a hidden fallback (KOT reprint lives there). --}}

        {{-- ── FAILED BILLS — header shortcut. F11. Red theme = needs attention. ── --}}
        {{-- Click → modal with Retry / Edit / Delete actions inline. --}}
        <button @click="openFailedBills()" class="relative flex md:hidden items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 hover:bg-red-100 transition" title="{{ __('pos.ti_failed_pra_f11') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span class="tn-key-chip text-[10px] bg-red-400/30 px-1 rounded">F11</span>
            <span class="hidden sm:inline">{{ __('pos.failed_word_html') }}</span>
            <span x-show="failedBills.length > 0" class="absolute -top-1 -right-1 min-w-[20px] h-5 px-1 bg-red-600 text-white text-[10px] rounded-full flex items-center justify-center font-bold animate-pulse" x-text="failedBills.length"></span>
        </button>

        {{-- Delivery Board (Task 431) — mobile copy of the nav-strip button --}}
        @if($showDeliveriesBoardBtn)
        <button type="button" onclick="tnOpenDeliveryBoard()" class="flex md:hidden items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 hover:bg-emerald-100 transition" title="{{ __('pos.deliveries') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
            <span class="hidden sm:inline">{{ __('pos.deliveries') }}</span>
        </button>
        @endif

        {{-- ── REPRINT — header shortcut. Alt+R. Today's bills, click = instant print. ── --}}
        {{-- Read-only: cashier + admin both allowed. Teal family (no-blue rule).       --}}
        <button @click="openReprint()" class="relative flex md:hidden items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-teal-700 dark:text-teal-400 bg-teal-50 dark:bg-teal-900/20 border border-teal-200 dark:border-teal-800 hover:bg-teal-100 transition" title="{{ __('pos.ti_reprint_today') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            <span class="tn-key-chip text-[10px] bg-teal-400/30 px-1 rounded">Alt+R</span>
            <span class="hidden sm:inline">{{ __('pos.reprint') }}</span>
        </button>

        {{-- Quick Return (Task 681) — mobile copy of the nav-strip button --}}
        @if($canQuickReturn)
        <button @click="openQuickReturn()" class="flex md:hidden items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-rose-700 dark:text-rose-400 bg-rose-50 dark:bg-rose-900/20 border border-rose-200 dark:border-rose-800 hover:bg-rose-100 transition" title="{{ __('pos.quick_return_hint') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 15v-1a4 4 0 00-4-4H8m0 0l3 3m-3-3l3-3m9 14V5a2 2 0 00-2-2H6a2 2 0 00-2 2v16l4-2 4 2 4-2 4 2z"/></svg>
            <span class="hidden sm:inline">{{ __('pos.quick_return') }}</span>
        </button>
        @endif

        {{-- Held pill — table companies: TABLE board hi single surface hai (F3 retired) --}}
        @unless($features->tables ?? false)
        <button @click="activeHeldIndex = 0; showHeldOrders = !showHeldOrders" class="relative flex md:hidden items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 hover:bg-amber-100 transition" title="{{ __('pos.ti_held_orders') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="hidden sm:inline">{{ __('pos.held') }}</span>
            <span x-show="heldOrders.length > 0" class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-[10px] rounded-full flex items-center justify-center font-bold" x-text="heldOrders.length"></span>
        </button>
        @endunless

        {{-- Hold / Send-to-Kitchen / Pay group REMOVED from the action bar (Jul 2026 redesign):
             Hold + Pay already live in the cart footer; Send to Kitchen moved there too
             (next to Provisional + PAY) so ALL bill actions sit in ONE place. --}}
    </div>

    {{-- Old full-width flex-row opener removed (25 Jul 2026): .tn-body-row above is the
         row container now; this inner div is the PRODUCTS area (grid) only. --}}
        <div class="flex-1 flex flex-col overflow-hidden" :class="mobileView === 'menu' ? 'flex' : 'hidden md:flex'">

            {{-- Category pills strip REMOVED globally (customer feedback, 23 Jul 2026 — was
                 Saaf-only declutter): the row ate vertical space; category filtering lives in
                 the always-visible dropdown next to search (same activeCategory). The strip
                 now renders for ALL companies (25 Jul 2026 — hosts the per-user grid edit
                 chip); the master Products toggle stays inventory-OFF only. Do NOT re-add
                 the pills. --}}
            <div class="tn-cat-strip flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-900 border-b border-gray-100 dark:border-gray-800 flex-shrink-0">
                <div class="flex items-center gap-2 overflow-x-auto hide-scrollbar flex-1 min-w-0">
                    <template x-if="!gridEditMode">
                        <div class="flex items-center gap-2 min-w-0">
                            <span x-show="showProducts" class="text-[11px] text-gray-400 dark:text-gray-500 px-1 whitespace-nowrap" x-text="window.TXT.items_colon + (allProducts.filter(p => isItemVisible(p)).length + allServices.filter(s => isItemVisible(s)).length + allDeals.filter(d => isItemVisible(d)).length)"></span>
                            <span x-show="!showProducts" class="text-[11px] text-gray-400 dark:text-gray-500 italic px-1 whitespace-nowrap">{{ __('pos.grid_hidden_hint') }}</span>
                        </div>
                    </template>
                    {{-- Edit-mode banner (Roman Urdu — customer-facing) --}}
                    <template x-if="gridEditMode">
                        <span class="text-[11px] font-semibold text-purple-700 dark:text-purple-300 px-1 whitespace-nowrap">{{ __('pos.tap_item_hide_show') }}</span>
                    </template>
                </div>
                {{-- "Sab Wapas Dikhao" — resets ALL of this user's grid prefs (edit mode only) --}}
                <button type="button" x-show="gridEditMode && hiddenPrefCount > 0" x-cloak @click="resetGridPrefs()" :disabled="gridPrefBusy"
                        class="flex-shrink-0 px-2.5 py-1.5 rounded-full text-[11px] font-bold border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 transition disabled:opacity-50">
                    {{ __('pos.show_all_again') }}
                </button>
                {{-- PER-USER grid edit chip (owner, 25 Jul 2026): ALL roles — each user
                     hides/shows items on their OWN grid only. Search never affected. --}}
                <button type="button" @click="gridEditMode = !gridEditMode; filterProducts(); if (!gridEditMode) syncAutoWidecart()"
                        class="flex-shrink-0 flex items-center gap-1.5 px-2.5 py-1.5 rounded-full text-[11px] font-bold border transition"
                        :class="gridEditMode ? 'bg-purple-600 border-purple-600 text-white' : 'bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300'"
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

                {{-- ═══ COMPACT PRODUCT LIST (Jul 2026 redesign, owner-approved mockup) ═══
                     Big image cards replaced by dense 2-column text rows: tiny thumb (only when a
                     real image exists), name + badges, price, cart-qty badge, + button. Same
                     handleProductClick / gridFocus / stock-out semantics — calcGridCols reads the
                     rendered grid so arrow-key navigation adapts automatically. Class names
                     .prod-card / .price-badge / .cart-qty-badge / .quick-add / .stock-out kept
                     (CSS + tests rely on them). --}}
                <template x-if="!loading">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                        <template x-for="(item, idx) in displayItems" :key="item.id + '-' + item.type">
                            {{-- GRID EDIT MODE (per-user, 25 Jul 2026): tile click toggles THIS user's
                                 visibility pref instead of adding to cart; hidden tiles render dimmed. --}}
                            <div :id="'grid-item-' + idx" class="prod-card flex items-center gap-2.5 px-2.5 py-2 bg-white dark:bg-gray-900 rounded-xl border border-gray-100 dark:border-gray-800 shadow-sm fade-in cursor-pointer hover:border-purple-300 dark:hover:border-purple-700 transition" :class="[gridFocusMode && gridFocusIndex === idx ? 'ring-2 ring-purple-500' : '', gridEditMode ? '' : (item.stockStatus === 'out' && blockOutOfStock ? 'stock-out' : (item.stockStatus === 'out' && !blockOutOfStock ? 'stock-out allow-add' : '')), gridEditMode && !isItemVisible(item) ? 'opacity-40' : '']" @click="gridEditMode ? toggleItemVisibility(item) : handleProductClick(item)">
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
                                    <template x-if="item.type === 'deal' && item.components">
                                        <p class="text-[10px] text-gray-500 dark:text-gray-400 truncate mt-0.5" x-text="item.components" :title="item.components"></p>
                                    </template>
                                </div>
                                <span class="price-badge text-sm font-extrabold text-purple-600 dark:text-purple-400 flex-shrink-0" x-text="'Rs. ' + Number(item.price).toLocaleString()"></span>
                                <template x-if="getCartQty(item) > 0">
                                    <span class="cart-qty-badge text-[10px] bg-gradient-to-br from-purple-500 to-purple-700 text-white w-6 h-6 rounded-full flex items-center justify-center font-bold shadow-sm flex-shrink-0" x-text="getCartQty(item)"></span>
                                </template>
                                <button @click.stop="gridEditMode ? toggleItemVisibility(item) : handleProductClick(item)" class="quick-add w-7 h-7 rounded-full text-white flex items-center justify-center shadow-sm transition-all flex-shrink-0" :class="gridEditMode ? (isItemVisible(item) ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-gray-400 hover:bg-gray-500') : 'bg-purple-600 hover:bg-purple-700'">
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
                        <div class="tn-empty-icon w-28 h-28 rounded-full bg-purple-100 dark:bg-purple-900/40 flex items-center justify-center mb-5">
                            <svg class="w-14 h-14 text-purple-400 dark:text-purple-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/></svg>
                        </div>
                        <p class="text-lg font-bold text-gray-700 dark:text-gray-200" x-text="showProducts ? window.TXT.no_products_match : window.TXT.products_grid_off"></p>
                        <p class="text-sm mt-1.5 text-gray-400 dark:text-gray-500 max-w-[280px]" x-text="showProducts ? window.TXT.try_different_category : window.TXT.products_toggle_off_hint"></p>
                        <button @click="restoreProductGrid()" class="mt-5 px-5 py-2.5 text-sm font-bold text-white bg-purple-600 hover:bg-purple-700 rounded-xl shadow-sm">{{ __('pos.show_all_products') }}</button>
                    </div>
                </template>

                <template x-if="!loading && filteredItems.length > displayCount">
                    <div class="flex justify-center py-4">
                        <button @click="loadMore()" class="px-6 py-2.5 text-sm font-semibold text-purple-600 bg-purple-50 dark:bg-purple-900/20 rounded-xl hover:bg-purple-100 transition border border-purple-200 dark:border-purple-800">
                            {{ __('pos.load_more_prefix') }}<span x-text="filteredItems.length - displayCount"></span> remaining)
                        </button>
                    </div>
                </template>
            </div>

            {{-- ═══ AKHRI BILLS strip (Jul 2026 redesign) — today's last bills as one-click
                 reprint chips. Reuses reprintBills/reprintBill() (Alt+R modal data); list is
                 loaded on init + refreshed after every successful sale. Desktop only. --}}
            <div x-show="reprintBills.length > 0" x-cloak class="hidden md:flex items-center gap-2 px-3 py-1.5 bg-white dark:bg-gray-900 border-t border-gray-100 dark:border-gray-800 flex-shrink-0 overflow-hidden">
                <span class="text-[10px] font-extrabold uppercase tracking-wider text-gray-400 dark:text-gray-500 flex-shrink-0">{{ __('pos.recent_bills') }}</span>
                <div class="flex items-center gap-1.5 overflow-x-auto hide-scrollbar min-w-0 flex-1">
                    <template x-for="bill in reprintBills.slice(0, 8)" :key="'strip-' + bill.id">
                        <button type="button" @click="reprintBill(bill)" :disabled="reprintBusyId === bill.id"
                            class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[10px] font-bold bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-purple-50 hover:border-purple-200 hover:text-purple-700 dark:hover:bg-purple-900/20 transition flex-shrink-0 disabled:opacity-50"
                            :title="'Reprint ' + (bill.pra_invoice_number || bill.invoice_number)">
                            <span x-text="bill.pra_invoice_number || bill.invoice_number"></span>
                            <span class="font-extrabold text-purple-600 dark:text-purple-400" x-text="'Rs.' + Number(bill.total_amount).toLocaleString()"></span>
                        </button>
                    </template>
                </div>
                <button type="button" @click="openReprint()" class="text-[10px] font-bold text-purple-600 dark:text-purple-400 hover:text-purple-800 flex-shrink-0 px-1.5" title="{{ __('pos.ti_all_todays_bills') }}">{{ __('pos.all_arrow') }}</button>
            </div>

            <div class="md:hidden flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800">
                <button @click="mobileView = 'cart'" class="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl bg-purple-600 text-white text-sm font-bold shadow-sm">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                    {{ __('pos.cart') }}
                    <span x-show="cart.length > 0" class="bg-white/20 px-1.5 rounded-full text-xs" x-text="cart.length"></span>
                    <span x-show="cart.length > 0" class="text-xs opacity-80" x-text="'Rs. ' + Number(roundedTotal).toLocaleString()"></span>
                </button>
            </div>

            {{-- Floating "Edit Cart" pill REMOVED (customer feedback, 25 Jul 2026) —
                 it overlapped product tiles and irritated cashiers mid-punching.
                 Cart edit stays reachable via the cart header Edit button, F6,
                 Ctrl+E, Tab-from-search and arrow keys. FBR port untouched (frozen). --}}
        </div>

    {{-- closes .tn-left-col (action bars + products area) --}}
    </div>

        {{-- Cart column widened again (owner, 24 Jul 2026: buttons one-line + compact tiles) 320/380/420 → 340/400/460 --}}
        <div class="tn-cart-col w-full md:w-[340px] lg:w-[400px] xl:w-[460px] bg-white dark:bg-gray-900 border-l border-gray-200 dark:border-gray-800 flex flex-col flex-shrink-0 shadow-xl" :class="mobileView === 'cart' ? 'flex' : 'hidden md:flex'">
            {{-- WIDE-CART wrapper (Variant A, 28 Jul 2026): display:contents by default —
                 zero layout change. In .tn-widecart desktop mode this becomes the LEFT
                 (wide) pane: header + banners + cart list. --}}
            <div class="tn-cart-main">
            <div class="flex items-center gap-2 px-3 py-2.5 border-b border-gray-100 dark:border-gray-800">
                <button @click="mobileView = 'menu'" class="md:hidden p-1.5 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/20 rounded-lg">
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
                {{-- COUNTER chip (Task 1349) — DEVICE-level counter for this billing
                     machine. Lives in the cart header on purpose: the top-nav sale-tools
                     strip is overflow-x-auto and collapses to a few pixels on ~1440px
                     screens, so a chip there would be unreachable. The cart column is a
                     fixed-width, always-visible pane. Only rendered when the shop has
                     actually created counters; unset = billing unchanged. Mobile has its
                     own copy in the md:hidden toggles strip. --}}
                <button type="button" x-show="allTerminals.length > 0" x-cloak @click="showTerminalPicker = true"
                        class="hidden md:flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-bold border flex-shrink-0 transition"
                        :class="terminalId ? 'bg-indigo-50 dark:bg-indigo-900/20 text-indigo-700 dark:text-indigo-300 border-indigo-200 dark:border-indigo-800 hover:bg-indigo-100' : 'bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 border-dashed border-gray-300 dark:border-gray-600 hover:bg-gray-100'"
                        :title="window.TXT.counter_chip_hint">
                    <svg class="w-3 h-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M4 7l1 12a1 1 0 001 1h12a1 1 0 001-1l1-12M4 7l1.2-2.4A1 1 0 016.1 4h11.8a1 1 0 01.9.6L20 7M9 11h6"/></svg>
                    <span class="truncate max-w-[96px] xl:max-w-[140px]" x-text="terminalId ? terminalName() : window.TXT.counter_pick_prompt"></span>
                </button>
                <template x-if="priorityOrder"><span class="text-[10px] bg-red-100 text-red-600 px-2 py-0.5 rounded-full font-bold">URGENT</span></template>
                {{-- Task #643: loaded waiter order ki pehchaan — order number + waiter naam
                     badge, taake cashier ko pata ho kis ka order cart mein khula hai. --}}
                <template x-if="incomingOrderId && incomingOrderInfo">
                    <span class="text-[10px] bg-teal-100 dark:bg-teal-900/30 text-teal-700 dark:text-teal-300 px-2 py-0.5 rounded-full font-bold truncate max-w-[160px]" :title="(incomingOrderInfo.order_number || '') + (incomingOrderInfo.waiter ? ' · ' + incomingOrderInfo.waiter : '')" x-text="(incomingOrderInfo.order_number || '') + (incomingOrderInfo.waiter ? ' · ' + incomingOrderInfo.waiter : '')"></span>
                </template>
                {{-- Task #643: claimed waiter order par Order Cancel — wohi warning modal
                     (items + KOT alert); sirf allowed roles (baked verdict). --}}
                <button x-show="incomingOrderId && canOrderCancel" x-cloak @click="cartCancelIncoming()" class="px-2 py-1 rounded-lg text-[10px] font-bold bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-600 dark:text-red-400 hover:bg-red-100 transition" :title="window.TXT.incoming_cancel_btn || 'Cancel'">{{ __('pos.incoming_cancel_btn') }}</button>
                {{-- Order-type badge: restaurant-category companies only (matches the header widget gate). --}}
                @if(($features->tables ?? false) || ($features->kot ?? false) || ($features->kitchen ?? false) || ($features->delivery ?? false))
                <span class="text-[10px] bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 px-2 py-0.5 rounded-full font-semibold" x-text="orderType.replace('_', ' ').toUpperCase()"></span>
                @endif
                <template x-if="selectedTable">
                    <span class="text-[10px] bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-2 py-0.5 rounded-full font-semibold" x-text="'T-' + selectedTable.table_number"></span>
                </template>
            </div>

            {{-- Item #3 relocation (customer feedback, 25 Jul 2026): Delivery charges input
                 lives HERE in the cart's Delivery section (was: top action bar) — visible
                 whenever order type = Delivery, with or without a selected customer.
                 Mechanism unchanged: setDeliveryCharge() adds/updates the TAX-EXEMPT manual
                 "Delivery Charges" cart line; switching order type removes it. --}}
            @if($features->delivery ?? false)
            <template x-if="orderType === 'delivery'">
                <div class="px-3 py-2 bg-purple-50 dark:bg-purple-900/10 border-b border-purple-100 dark:border-purple-900/20 flex items-center gap-2">
                    <svg class="w-4 h-4 text-purple-600 dark:text-purple-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0zM13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
                    <span class="text-xs font-bold text-purple-700 dark:text-purple-300 flex-1">{{ __('pos.delivery_charges') }}</span>
                    <span class="text-[10px] font-bold text-gray-500 dark:text-gray-400">Rs</span>
                    <input type="number" min="0" step="1" x-model="deliveryChargeInput" @change="setDeliveryCharge()" @keydown.enter.prevent="setDeliveryCharge()" placeholder="0"
                           autocomplete="off" name="pos_delivery_charge_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                           class="w-20 rounded-md border-purple-200 dark:border-purple-800 dark:bg-gray-800 dark:text-white text-xs px-1.5 py-1 focus:ring-purple-500 focus:border-purple-500">
                </div>
            </template>
            @endif

            <template x-if="selectedCustomer">
                <div class="px-3 py-2 bg-blue-50 dark:bg-blue-900/10 border-b border-blue-100 dark:border-blue-900/20 flex items-start gap-2">
                    <div class="w-8 h-8 rounded-full bg-blue-200 dark:bg-blue-800 flex items-center justify-center flex-shrink-0 mt-0.5"><span class="text-xs font-bold text-blue-700 dark:text-blue-300" x-text="selectedCustomer.name.charAt(0)"></span></div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-1.5">
                            <p class="text-xs font-semibold text-blue-800 dark:text-blue-200 truncate" x-text="selectedCustomer.name"></p>
                            <template x-if="customerStats && customerStats.is_frequent"><span class="freq-badge">VIP</span></template>
                        </div>
                        <p class="text-xs text-blue-600 dark:text-blue-400" x-text="selectedCustomer.phone || window.TXT.no_phone"></p>
                        <template x-if="selectedCustomer.address">
                            <p class="text-xs text-blue-500 dark:text-blue-400 truncate" x-text="'📍 ' + selectedCustomer.address"></p>
                        </template>
                        {{-- Item #1 (Jul 2026): delivery-address picker — Delivery orders only.
                             Saved addresses (address #1 + extras) in a dropdown; "+ New" saves an
                             extra address to the customer AND selects it for this bill. --}}
                        <template x-if="orderType === 'delivery'">
                            <div class="mt-1 space-y-1">
                                <div class="flex items-center gap-1">
                                    <select x-model="selectedDeliveryAddress" class="flex-1 min-w-0 text-sm font-medium rounded-md border-blue-200 dark:border-blue-800 dark:bg-gray-800 dark:text-white py-1.5 px-2 focus:ring-blue-500 focus:border-blue-400">
                                        <option value="">{{ __('pos.delivery_address_divider') }}</option>
                                        <template x-for="(a, ai) in customerAddresses" :key="a.id ?? ('t' + ai)">
                                            <option :value="a.address" x-text="(a.label ? a.label + ': ' : '') + a.address"></option>
                                        </template>
                                    </select>
                                    {{-- ZFC (Aug 2026): purana address wahin se DELETE bhi ho sake —
                                         selected address ka ✕. Customers page ka chakkar khatam. --}}
                                    <button x-show="selectedDeliveryAddress && customerAddresses.some(a => a.address === selectedDeliveryAddress)" @click="deleteSelectedAddress()" title="{{ __('pos.ti_delete_address') }}" class="text-xs font-bold text-red-500 dark:text-red-400 px-2 py-1.5 rounded-md border border-red-200 dark:border-red-800 hover:bg-red-50 dark:hover:bg-red-900/30 whitespace-nowrap">✕</button>
                                    <button @click="showAddrNew = !showAddrNew; if (showAddrNew) $nextTick(() => document.getElementById('tnNewAddrInput')?.focus())" class="text-xs font-bold text-blue-600 dark:text-blue-300 px-2 py-1.5 rounded-md border border-blue-200 dark:border-blue-800 hover:bg-blue-100 dark:hover:bg-blue-900/30 whitespace-nowrap">{{ __('pos.new_short') }}</button>
                                </div>
                                <div x-show="showAddrNew" x-cloak class="flex items-center gap-1">
                                    <input id="tnNewAddrLabel" type="text" x-model="newAddrLabel" @keydown.enter.prevent="saveNewAddress()" @keydown.escape.prevent="showAddrNew = false" placeholder="{{ __('pos.ph_addr_label') }}" autocomplete="off" name="pos_new_addr_label_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-24 flex-none text-sm rounded-md border-blue-200 dark:border-blue-800 dark:bg-gray-800 dark:text-white py-1.5 px-2 focus:ring-blue-500 focus:border-blue-400">
                                    <input id="tnNewAddrInput" type="text" x-model="newAddrText" @keydown.enter.prevent="saveNewAddress()" @keydown.escape.prevent="showAddrNew = false" placeholder="{{ __('pos.ph_full_delivery_address') }}" autocomplete="off" name="pos_new_addr_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore class="flex-1 min-w-0 text-sm rounded-md border-blue-200 dark:border-blue-800 dark:bg-gray-800 dark:text-white py-1.5 px-2 focus:ring-blue-500 focus:border-blue-400">
                                    <button @click="saveNewAddress()" class="text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 px-2 py-1.5 rounded-md">{{ __('pos.save_btn') }}</button>
                                </div>
                            </div>
                        </template>
                        <template x-if="customerStats">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 mt-0.5">
                                {{-- Clickable (owner request, 1 Aug 2026 — ZFC): opens the customer history modal --}}
                                <button type="button" @click="if (selectedCustomer?.id) loadCustomerHistory(selectedCustomer.id)" class="text-[10px] font-semibold text-blue-700 dark:text-blue-300 underline decoration-dotted underline-offset-2 hover:text-blue-900 dark:hover:text-blue-100" x-text="(customerStats.total_orders || 0) + window.TXT.sfx_orders" title="{{ __('pos.ti_view_history') }}"></button>
                                <span class="text-[10px] text-gray-400">•</span>
                                <span class="text-[10px] font-semibold text-blue-700 dark:text-blue-300" x-text="'Rs. ' + Number(customerStats.total_spent || 0).toLocaleString() + window.TXT.sfx_spent"></span>
                                <template x-if="customerStats.last_order_date">
                                    <span class="text-[10px] text-gray-400">•</span>
                                </template>
                                <template x-if="customerStats.last_order_date">
                                    <span class="text-[10px] text-blue-600 dark:text-blue-400" x-text="window.TXT.last_colon + customerStats.last_order_date"></span>
                                </template>
                            </div>
                        </template>
                    </div>
                    {{-- Task 1380 (owner video, Aug 2026): attached customer ko YAHIN se
                         hatane ka rasta. Pehle clear ka ✕ sirf products column ke customer
                         search box par tha, is liye call se bana customer is card par
                         chipka reh jata tha (Takeaway/Dine In par switch karne se bhi).
                         Delivery-address wale ✕ se alag pehchan: yeh card ke UPAR-DAAYEN
                         kone par gol bordered button hai (address ka ✕ neeche dropdown ki
                         qatar mein, red) — aur yeh poora customer record hatata hai.
                         Card .tn-cart-main ke andar hai, is liye dono layouts (grid ON
                         aur products-OFF wide cart) mein wahin dikhta hai. --}}
                    {{-- Task 1381: attached customer ko wahin se call back — cashier ko
                         Haaliya calls kholne ki zaroorat nahi. Sirf Caller ID wali shops
                         par, aur sirf jab number mojood ho. --}}
                    @if($company->caller_id_enabled ?? false)
                    <button type="button" x-show="selectedCustomer && selectedCustomer.phone" x-cloak
                            @click="callerDialBack({ phone: selectedCustomer.phone, name: selectedCustomer.name }, { attach: false })" :disabled="callerDialBusy"
                            title="{{ __('pos.ti_call_back') }}" aria-label="{{ __('pos.ti_call_back') }}"
                            class="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-indigo-600 dark:text-indigo-300 bg-white/70 dark:bg-gray-900/40 border border-indigo-200 dark:border-indigo-800 hover:bg-indigo-600 hover:text-white hover:border-indigo-600 disabled:opacity-60 transition">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                    </button>
                    @endif
                    <button type="button" @click="clearAttachedCustomer()" title="{{ __('pos.ti_remove_customer') }}" aria-label="{{ __('pos.ti_remove_customer') }}"
                            class="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-blue-500 dark:text-blue-300 bg-white/70 dark:bg-gray-900/40 border border-blue-200 dark:border-blue-800 hover:bg-red-500 hover:text-white hover:border-red-500 transition">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </template>

            <!-- ─── EDIT MODE BANNER — visible whenever a provisional bill is loaded for editing ─── -->
            <template x-if="editingBillId">
                <div class="px-3 py-2 bg-amber-50 dark:bg-amber-900/20 border-b border-amber-200 dark:border-amber-800 flex items-center gap-2">
                    <svg class="w-4 h-4 text-amber-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-bold text-amber-700 dark:text-amber-400 truncate">{{ __('pos.editing_bill') }} <span x-text="editingBillNumber"></span></p>
                        <p class="text-[10px] text-amber-600/80 dark:text-amber-500/80">{{ __('pos.f9_update_stays_provisional') }}</p>
                    </div>
                    <button @click="cancelEditMode()" class="text-[10px] font-bold px-2 py-1 rounded-lg bg-white dark:bg-gray-800 text-amber-700 dark:text-amber-400 border border-amber-300 dark:border-amber-700 hover:bg-amber-100 transition whitespace-nowrap">{{ __('pos.cancel') }}</button>
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
                {{-- Compact cart rows (customer feedback, 23 Jul 2026): tighter padding + smaller
                     qty stepper so 6-8 items fit before scrolling (was ~4-5). Touch targets on
                     phones still enforced by mobile.css min-height. --}}
                <template x-for="(item, index) in cart" :key="item.cart_uid">
                    <div class="cart-item cart-item-enter px-3 py-1.5 cursor-pointer relative"
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
                                <button @click.stop="updateQty(index, -1)" class="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-white dark:hover:bg-gray-700 text-gray-600 dark:text-gray-400 transition active:scale-90 shadow-sm hover:shadow">
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
                                    class="w-12 h-7 text-center text-base font-extrabold bg-white dark:bg-gray-900 text-gray-900 dark:text-white border-0 rounded-lg focus:ring-2 focus:ring-purple-500 shadow-inner px-1">
                                <button @click.stop="updateQty(index, 1)" class="w-7 h-7 flex items-center justify-center rounded-lg hover:bg-white dark:hover:bg-gray-700 text-gray-600 dark:text-gray-400 transition active:scale-90 shadow-sm hover:shadow">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" d="M12 4v16m8-8H4"/></svg>
                                </button>
                            </div>
                            <div class="text-right min-w-[60px]">
                                <p class="text-sm font-extrabold text-gray-900 dark:text-white" x-text="'Rs.' + getItemTotal(item).toLocaleString()"></p>
                            </div>
                            {{-- Cart rows v3 (owner + ZFC feedback, 26 Jul 2026): per-item TAX + Disc
                                 icon buttons AND the per-item note input REMOVED — bill-level Discount
                                 (footer) + bill-level note are the only surfaces. Data fields
                                 (is_tax_exempt / item_discount_* / special_notes) stay in the cart
                                 model + every payload so recalled/edited bills keep their values.
                                 Keyboard T / Alt+T tax toggle kept (NO TAX badge shows state). --}}
                            {{-- Per-item note SIDE BUTTON (owner, 3 Aug 2026): 26 Jul ki
                                 cart-height complaint wale hamesha-khule inputs wapas NAHI
                                 aaye — note sirf button dabane par khulta hai. KOT template
                                 special_notes pehle se chhapta hai. --}}
                            <button @click.stop="toggleItemNote(index)" title="{{ __('pos.item_note_btn') }}"
                                :class="(item.special_notes || '').length ? 'text-amber-500' : 'text-gray-300 dark:text-gray-600'"
                                class="p-1.5 flex-shrink-0 hover:text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/20 rounded-lg transition active:scale-90">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                            <button @click.stop="removeFromCart(index)" class="p-1.5 flex-shrink-0 text-red-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition active:scale-90">
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
                        {{-- Read-only chips for values carried in from recalled/edited bills or the
                             T/Alt+T tax shortcut (no per-item editors any more, but the cashier must
                             still SEE the state — NO TAX chip is the only exempt indicator now). --}}
                        <div x-show="item.is_tax_exempt || item.is_third_schedule || (item.item_discount_value || 0) > 0 || (item.special_notes || '').length > 0" class="mt-0.5 flex items-center gap-1 flex-wrap">
                            <span x-show="item.is_tax_exempt && !item.is_third_schedule" class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-green-500 text-white">NO TAX</span>
                            <span x-show="item.is_third_schedule" class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-blue-500 text-white">3rd Sch</span>
                            <span x-show="(item.item_discount_value || 0) > 0" class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-orange-100 text-orange-600" x-text="(item.item_discount_type || 'percentage') === 'percentage' ? '-' + item.item_discount_value + '%' : '-Rs.' + item.item_discount_value"></span>
                            <span x-show="(item.special_notes || '').length > 0 && !item._showNote" class="text-[9px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300 truncate max-w-[180px]" x-text="item.special_notes"></span>
                        </div>
                        {{-- Per-item note inline input (side button se khulta hai) --}}
                        <template x-if="item._showNote">
                            <div class="mt-1.5" @click.stop>
                                <input type="text" maxlength="190" x-model="item.special_notes"
                                    :data-item-note="index"
                                    autocomplete="one-time-code" name="item_note_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                                    @keydown.stop
                                    @keydown.enter.prevent="item._showNote = false"
                                    @keydown.escape.prevent="item._showNote = false"
                                    placeholder="{{ __('pos.ph_item_note') }}"
                                    class="w-full text-[11px] rounded-lg border-amber-300 dark:border-amber-700 bg-amber-50/50 dark:bg-amber-900/10 dark:text-white px-2 py-1.5 focus:ring-amber-500 focus:border-amber-500">
                            </div>
                        </template>
                    </div>
                </template>
            </div>
            {{-- closes .tn-cart-main --}}
            </div>

            {{-- WIDE-CART wrapper: RIGHT payment column in widecart mode (totals band +
                 pay buttons + TABLE strip); display:contents otherwise. --}}
            <div class="tn-cart-side">
            <div class="border-t border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/80 backdrop-blur-sm">
                {{-- Order Notes textarea REMOVED (owner, 26 Jul 2026 — cart height). kitchenNotes
                     model + N-shortcut guard stay intact (handler no-ops when the ref is absent);
                     per-item notes (kitchen_notes feature) unaffected. Only the order-level
                     Discount trigger remains, right-aligned on a slim row. --}}
                <div class="px-3 py-1 flex items-center justify-end gap-1.5">
                    {{-- Bill Note toggle (owner, 28 Jul 2026): Dine-In sends the KOT at Hold time —
                         the Pay-modal note comes too late for the kitchen. This opens a one-line
                         input bound to the SAME kitchenNotes model as the Pay-modal field (one
                         note, two surfaces). Collapsed by default: zero cart-height cost. --}}
                    <button @click="showCartNote = !showCartNote; if (showCartNote) $nextTick(() => $refs.cartNoteInput?.focus())" class="shrink-0 text-[10px] font-bold px-2.5 py-1.5 rounded-lg border transition" :class="(kitchenNotes || '').length > 0 ? 'bg-amber-100 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800' : 'bg-gray-100 dark:bg-gray-800 text-gray-500 border-gray-200 dark:border-gray-700 hover:bg-gray-200'">
                        <span x-text="(kitchenNotes || '').length > 0 ? '✎ Note ✓' : '✎ Note'"></span>
                    </button>
                    <button @click="showDiscount = !showDiscount" class="shrink-0 text-[10px] font-bold px-2.5 py-1.5 rounded-lg border transition" :class="discountAmount > 0 ? 'bg-orange-100 dark:bg-orange-900/20 text-orange-600 border-orange-200 dark:border-orange-800' : 'bg-gray-100 dark:bg-gray-800 text-gray-500 border-gray-200 dark:border-gray-700 hover:bg-gray-200'">
                        <span x-text="discountAmount > 0 ? '-Rs. ' + Number(discountAmount).toLocaleString() : window.TXT.pct_discount"></span>
                    </button>
                </div>
                <div class="px-3 pb-1.5" x-show="showCartNote" x-transition x-cloak>
                    {{-- Aug 2026 (restaurant feedback): multiple items need SEPARATE note lines —
                         textarea so Enter makes a new line (each line prints numbered on the KOT). Esc = close. --}}
                    <textarea x-model="kitchenNotes" x-ref="cartNoteInput" data-pay-note rows="2"
                        autocomplete="off" name="pos_cart_note_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                        placeholder="{{ __('pos.ph_bill_note_multi') }}"
                        @keydown.escape.prevent="showCartNote = false"
                        class="w-full text-xs bg-amber-50/60 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-900/30 rounded-lg px-2.5 py-1.5 text-gray-700 dark:text-gray-300 focus:ring-amber-400 placeholder-gray-400 resize-y"></textarea>
                </div>
                <div class="px-3 pb-1.5" x-show="showDiscount" x-transition>
                    <div class="p-2 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl space-y-1.5">
                        <div class="flex items-center gap-1.5">
                            <span class="text-[8px] text-gray-400" x-text="window.TXT.limit_colon + effectiveDiscountLimit + '%'"></span>
                            <button x-show="!managerOverrideActive && hasManagerPin && posRole !== 'pos_admin'" @click="requestManagerOverride()" class="text-[8px] font-bold text-blue-600 hover:text-blue-800 px-1">{{ __('pos.override') }}</button>
                            <span x-show="managerOverrideActive" class="text-[8px] font-bold text-green-600 px-1">{{ __('pos.unlocked') }}</span>
                        </div>
                        <div class="flex gap-1">
                            <button @click="discountType = 'percentage'" class="flex-1 text-[10px] font-bold py-1 rounded-lg transition" :class="discountType === 'percentage' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-500'">%</button>
                            <button @click="discountType = 'amount'" class="flex-1 text-[10px] font-bold py-1 rounded-lg transition" :class="discountType === 'amount' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-500'">Rs.</button>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <input type="number" x-ref="billDiscountInput" x-model.number="discountValue" @input="if(!checkDiscountLimit(discountValue, discountType)) { discountValue = discountType === 'percentage' ? effectiveDiscountLimit : maxAmountDiscount; showToast(window.TXT.discount_capped_at + effectiveDiscountLimit + '%' + (discountType === 'amount' ? ' of bill (Rs. ' + maxAmountDiscount.toLocaleString() + ')' : ''), 'error'); } recalcDiscount()" min="0" :max="discountType === 'percentage' ? effectiveDiscountLimit : maxAmountDiscount" step="any" :placeholder="discountType === 'percentage' ? window.TXT.ph_max_pfx + effectiveDiscountLimit + '%' : window.TXT.ph_direct_amount" class="flex-1 text-xs bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg px-2 py-1.5 text-gray-900 dark:text-white focus:ring-purple-500">
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
                {{-- Jul 2026 redesign: BADA TOTAL BAND (mockup parity) — solid brand band
                     (bg-purple-900 → theme engine remaps per theme), big white total,
                     items·qty pill + method-aware "Card pe" hint. All original rows kept. --}}
                <div class="tn-total-band px-3 py-2 bg-purple-900">
                    <div class="flex items-end justify-between gap-2">
                        <div class="min-w-0 space-y-0.5 text-[11px] leading-tight text-white/75">
                            <div class="flex gap-2"><span>{{ __('pos.subtotal') }}</span><span x-text="'Rs. ' + Number(subtotal).toLocaleString()"></span></div>
                            <div x-show="itemDiscountsTotal > 0" class="flex gap-2 text-orange-300">
                                <span>{{ __('pos.item_disc') }}</span>
                                <span x-text="'-Rs. ' + Number(itemDiscountsTotal).toLocaleString()"></span>
                            </div>
                            <div x-show="discountAmount > 0" class="flex gap-2 text-orange-300">
                                <span x-text="discountType === 'percentage' ? window.TXT.discount_paren + discountValue + '%)' : 'Discount'"></span>
                                <span x-text="'-Rs. ' + Number(discountAmount).toLocaleString()"></span>
                            </div>
                            <div x-show="exemptAmount > 0" class="flex gap-2 text-green-300"><span>{{ __('pos.tax_exempt') }}</span><span x-text="'-Rs. ' + Number(exemptAmount).toLocaleString()"></span></div>
                            <div class="flex gap-2"><span x-text="taxInclusive ? (window.TXT.tax_paren + taxRate + window.TXT.pct_incl) : (window.TXT.tax_paren + taxRate + '%)')"></span><span x-text="'Rs. ' + Number(taxAmount).toLocaleString()"></span></div>
                            <div x-show="Math.abs(roundOff) > 0.001" class="flex gap-2 text-white/60">
                                <span>{{ __('pos.round_off') }}</span>
                                <span x-text="(roundOff >= 0 ? '+ Rs. ' : '− Rs. ') + Math.abs(roundOff).toFixed(2)"></span>
                            </div>
                            <div class="pt-0.5">
                                <span class="inline-flex items-center rounded-full bg-white/15 px-2 py-0.5 text-[9px] font-bold text-white" x-text="cart.length + window.TXT.sfx_items_mid + Number(cartQtyCount.toFixed(2)).toLocaleString() + window.TXT.sfx_qty"></span>
                            </div>
                        </div>
                        <div class="text-right shrink-0">
                            <div class="text-[9px] font-bold tracking-widest text-white/60 uppercase" x-text="cartMethodHint ? window.TXT.total_cash : window.TXT.total_word"></div>
                            <div class="total-animate total-line text-3xl font-black text-white leading-none" x-text="'Rs. ' + Number(roundedTotal).toLocaleString()" :class="cartAnimating ? 'cart-pop' : ''"></div>
                            <div x-show="cartMethodHint" x-cloak class="text-[9px] text-white/60 mt-0.5" x-text="cartMethodHint"></div>
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
                    {{-- Task 287: Delivery Prepaid toggle — customer already paid online.
                         Shown ONLY for delivery orders. One click sets deliveryPrepaid=true
                         which overrides the payment method to qr_payment on every submit path
                         (processPayment, manual, restaurant hold→pay, offline queue).
                         Non-delivery orders never see this; it resets on type change + clear. --}}
                    @if($features->delivery ?? false)
                    <template x-if="orderType === 'delivery'">
                        <button type="button"
                                @click="deliveryPrepaid = !deliveryPrepaid"
                                class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl border-2 transition-all"
                                :class="deliveryPrepaid ? 'bg-teal-50 dark:bg-teal-900/20 border-teal-400 dark:border-teal-600' : 'bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 hover:border-teal-300 dark:hover:border-teal-700'">
                            <span class="flex-shrink-0 w-5 h-5 rounded-full border-2 flex items-center justify-center transition-all"
                                  :class="deliveryPrepaid ? 'bg-teal-500 border-teal-500' : 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700'">
                                <svg x-show="deliveryPrepaid" class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            </span>
                            <span class="flex-1 text-left text-xs font-bold leading-tight"
                                  :class="deliveryPrepaid ? 'text-teal-700 dark:text-teal-300' : 'text-gray-600 dark:text-gray-400'"
                                  x-text="deliveryPrepaid ? (window.TXT.delivery_prepaid_active_hint || 'Online / QR payment') : (window.TXT.delivery_prepaid_toggle || 'Customer already paid online')"></span>
                            <span x-show="deliveryPrepaid" class="flex-shrink-0 text-[10px] font-black text-teal-700 dark:text-teal-300 bg-teal-100 dark:bg-teal-900/50 border border-teal-300 dark:border-teal-700 px-1.5 py-0.5 rounded-full">PREPAID</span>
                        </button>
                    </template>
                    @endif
                    {{-- ═══ Task 781: IN-PANEL TABLE ACTIONS ═══ (direct-open shops only)
                         Jab table ka order cart mein khula ho (recalled ya claimed waiter),
                         board popup ke saare actions yahin milte hain: Proof Bill, FINAL
                         (print-choice modal ke saath), KOT Dobara, Aakhri Add-on KOT,
                         Table Badlein, Order Cancel. Flag OFF = block render hi nahi hota. --}}
                    @if($features->tables ?? false)
                    <div x-show="panelTableActionsVisible()" x-cloak class="rounded-xl border border-purple-200 dark:border-purple-800 bg-purple-50 dark:bg-purple-900/20 p-2 space-y-1.5">
                        <div class="flex items-center justify-between px-1">
                            <span class="text-[10px] font-black uppercase tracking-wide text-purple-700 dark:text-purple-300">{{ __('pos.panel_table_actions_title') }}</span>
                            <span class="text-[10px] font-bold text-gray-500 dark:text-gray-400" x-text="panelTableInfo() ? 'T-' + panelTableInfo().table_number : (panelOrderMeta().order_number || '')"></span>
                        </div>
                        <div class="grid grid-cols-2 gap-1.5">
                            <button @click="panelProofBill()" :disabled="submitting || boardBusy" class="py-2 px-1 rounded-lg text-[11px] font-bold text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 hover:bg-blue-100 disabled:opacity-40 transition">&#128462; {{ __('pos.panel_proof_bill') }}</button>
                            <button @click="panelAskFinal()" :disabled="submitting || boardBusy" class="py-2 px-1 rounded-lg text-[11px] font-extrabold text-white bg-green-600 hover:bg-green-700 disabled:opacity-40 transition" x-text="window.TXT.make_final_rs_prefix + Math.round(roundedTotal).toLocaleString()"></button>
                        </div>
                        {{-- Task 1379: kitchen-ticket reprint gate ($canKotReprint =
                             company switch + per-cashier Custom Access tick). --}}
                        @if((($features->kot ?? false) || ($features->kitchen ?? false)) && $canKotReprint)
                        <div class="grid grid-cols-2 gap-1.5" x-show="panelKotSent()">
                            <button @click="panelResendKot()" :disabled="submitting || boardBusy" title="{{ __('pos.kot_resend_btn') }}" class="py-1.5 px-1 rounded-lg text-[10px] font-bold text-orange-700 dark:text-orange-300 bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 hover:bg-orange-100 disabled:opacity-40 transition">{{ __('pos.resend_short') }}</button>
                            <button @click="panelLastKot()" :disabled="submitting || boardBusy" title="{{ __('pos.ti_kot_last_addon') }}" class="py-1.5 px-1 rounded-lg text-[10px] font-bold text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 hover:bg-amber-100 disabled:opacity-40 transition">{{ __('pos.kot_last_addon_short') }}</button>
                        </div>
                        @endif
                        <div class="grid grid-cols-2 gap-1.5">
                            <button x-show="panelTableInfo()" @click="panelAskShift()" :disabled="submitting || boardBusy" class="py-1.5 px-1 rounded-lg text-[10px] font-bold text-teal-700 dark:text-teal-300 bg-teal-50 dark:bg-teal-900/20 border border-teal-200 dark:border-teal-800 hover:bg-teal-100 disabled:opacity-40 transition">&#8644; {{ __('pos.panel_table_shift') }}</button>
                            <button x-show="canOrderCancel" @click="panelCancelAsk()" :disabled="submitting || boardBusy" class="py-1.5 px-1 rounded-lg text-[10px] font-bold text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 hover:bg-red-100 disabled:opacity-40 transition">{{ __('pos.order_cancel_table_free') }}</button>
                        </div>
                    </div>
                    @endif
                    {{-- ONE-TAP method buttons (owner, 26 Jul 2026): CASH/CARD finalize the
                         bill DIRECTLY with that method — tax rate auto-follows the company's
                         tax module (taxRules/pricing mode), no second 8%/16% choice popup.
                         The PAY (F8) button below keeps the Pay modal (method choice + note).
                         Failures surface via showToast (modal-independent). --}}
                    <div class="grid grid-cols-2 gap-2">
                        <button @click="payingHeldOrderId = null; saveAsProvisional = false; payMethodIndex = 0; payPrintReceipt = billPrintDefault(orderType); processPayment('cash')" :disabled="cart.length === 0 || submitting" class="py-1.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white disabled:opacity-30 shadow-sm transition flex flex-col items-center gap-0.5">
                            <span class="flex items-center gap-1.5 text-xs font-extrabold leading-none"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>CASH</span>
                            <span class="flex items-center gap-1 leading-none"><span class="text-[9px] text-white/75" x-text="cart.length ? 'Rs. ' + Number(cartTotalForMethod('cash')).toLocaleString() : ''"></span><kbd class="text-[8px] bg-white/20 px-1 rounded font-mono">Alt+1</kbd></span>
                        </button>
                        <button @click="payingHeldOrderId = null; saveAsProvisional = false; payMethodIndex = 1; payPrintReceipt = billPrintDefault(orderType); processPayment('card')" :disabled="cart.length === 0 || submitting" class="py-1.5 rounded-xl bg-gray-700 hover:bg-gray-800 dark:bg-gray-600 dark:hover:bg-gray-700 text-white disabled:opacity-30 shadow-sm transition flex flex-col items-center gap-0.5">
                            <span class="flex items-center gap-1.5 text-xs font-extrabold leading-none"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>CARD</span>
                            <span class="flex items-center gap-1 leading-none"><span class="text-[9px] text-white/75" x-text="cart.length ? 'Rs. ' + Number(cartTotalForMethod('card')).toLocaleString() : ''"></span><kbd class="text-[8px] bg-white/20 px-1 rounded font-mono">Alt+2</kbd></span>
                        </button>
                    </div>
                    <div class="grid gap-2 {{ ($features->tables ?? false) ? 'grid-cols-2' : 'grid-cols-3' }}">
                        <button @click="if(cart.length && confirm(window.TXT.clear_entire_cart)) { clearCart(); }" :disabled="cart.length === 0" class="py-2 text-xs font-bold text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 rounded-xl border border-red-200 dark:border-red-800 hover:bg-red-100 disabled:opacity-30 transition flex items-center justify-center gap-0.5">{{ __('pos.clear') }} <kbd class="text-[8px] bg-red-200/50 dark:bg-red-800/30 px-1 rounded font-mono">F4</kbd></button>
                        <button @click="holdOrder()" :disabled="cart.length === 0 || submitting || hasManualItems() || hasDealItems() || !canHold()" :title="!canHold() ? window.TXT.ti_hold_dine_in_only : ((hasManualItems() || hasDealItems()) ? window.TXT.ti_manual_deals_pay_first : '')" class="py-2 text-xs font-bold text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 rounded-xl border border-amber-200 dark:border-amber-800 hover:bg-amber-100 disabled:opacity-30 disabled:cursor-not-allowed transition flex items-center justify-center gap-1">
                            <svg x-show="submitting" class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <span x-text="submitting ? window.TXT.holding_ellipsis : window.TXT.hold_word"></span>
                            <kbd x-show="!submitting" class="text-[8px] bg-amber-200/50 dark:bg-amber-800/30 px-1 rounded ml-0.5 font-mono">F5</kbd>
                        </button>
                        @unless($features->tables ?? false)
                        <button @click="showHeldOrders = !showHeldOrders" class="relative py-2 text-xs font-bold text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/20 rounded-xl border border-purple-200 dark:border-purple-800 hover:bg-purple-100 transition flex items-center justify-center gap-0.5">
                            {{ __('pos.recall') }}
                            <span x-show="heldOrders.length > 0" class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-red-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center held-badge-pulse shadow-sm" x-text="heldOrders.length"></span>
                        </button>
                        @endunless
                    </div>
                    <!-- ─── SAVE PROVISIONAL + PAY — ONE line (owner, 24 Jul 2026): frees a full
                         button-row of cart height; Provisional 2/5, PAY 3/5 (stays dominant).
                         Jul 2026 redesign: Send to Kitchen (KOT companies) joins this row —
                         it was removed from the action bar so all bill actions live here. ─── -->
                    @if($features->kot ?? false)
                    <button @click="sendToKitchen()" :disabled="cart.length === 0 || submitting || hasManualItems() || hasDealItems() || !canHold()" :title="!canHold() ? window.TXT.ti_kitchen_dine_in_only : ((hasManualItems() || hasDealItems()) ? window.TXT.ti_manual_deals_pay_first_cart : window.TXT.ti_kot_saves_no_payment)" class="w-full py-2 rounded-xl text-xs font-bold bg-orange-500 hover:bg-orange-600 text-white disabled:opacity-30 disabled:cursor-not-allowed shadow-sm transition flex items-center justify-center gap-1.5">
                        <span class="text-sm leading-none">🍳</span>
                        <span x-text="submitting ? window.TXT.sending_ellipsis : window.TXT.send_to_kitchen"></span>
                        <kbd class="text-[9px] bg-orange-700/40 px-1.5 py-0.5 rounded font-mono flex-shrink-0">Alt+K</kbd>
                    </button>
                    @endif
                    <div class="grid grid-cols-5 gap-2">
                        @if($uBillScope !== 'pra')
                        <button @click="saveProvisionalDirect()" :disabled="cart.length === 0 || submitting || (!editingBillId && !canProvisional())" :title="(!editingBillId && !canProvisional()) ? window.TXT.ti_provisional_delivery_only : ''" class="col-span-2 min-w-0 py-3 rounded-xl text-xs font-bold text-white bg-amber-500 hover:bg-amber-600 disabled:opacity-30 shadow-sm transition flex items-center justify-center gap-1">
                            <svg x-show="!submitting" class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
                            <svg x-show="submitting" class="w-3.5 h-3.5 flex-shrink-0 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <span class="truncate" x-text="editingBillId ? (window.TXT.update_bill_prefix + editingBillNumber) : window.TXT.provisional_word"></span>
                            <kbd class="text-[9px] bg-amber-700/40 px-1.5 py-0.5 rounded font-mono flex-shrink-0">F9</kbd>
                        </button>
                        @endif
                        <button @click="showPayModal = true" :disabled="cart.length === 0 || submitting" class="pay-btn-premium btn-ripple {{ $uBillScope !== 'pra' ? 'col-span-3' : 'col-span-5' }} min-w-0 py-3 rounded-xl text-sm font-extrabold text-white disabled:opacity-30">
                            <span class="flex items-center justify-center gap-1.5">
                                <svg x-show="submitting" class="w-4 h-4 flex-shrink-0 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <svg x-show="!submitting" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                {{ __('pos.pay_rs') }} <span x-text="Number(roundedTotal).toLocaleString()"></span>
                                <kbd x-show="!submitting" class="text-[9px] bg-green-500/30 px-1.5 rounded font-mono flex-shrink-0">F8</kbd>
                            </span>
                        </button>
                    </div>
                </div>
            </div>

            @if($features->tables ?? false)
            {{-- ═══ TABLE BOARD (customer request ×3, owner-approved Jul 2026;
                 owner 26 Jul 2026: strip cart chhota kar rahi thi → board ab ek
                 "TABLE" BUTTON ke andar MODAL mein khulta hai, cart full-size) ═══
                 Slim one-row button below the cart keeps the live pulse (status
                 counts + chalu raqam); click / Alt+B opens the board modal.
                 Tile click opens an ACTION MENU (never a direct action) — View/
                 Edit, Final-with-confirm, KOT resend, Free table — which kills
                 the "anjaane mein bill final" accidents. Same table-status feed
                 as the Select-Table picker (single source, no duplicate system). --}}
            <div class="border-t-2 border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-950 flex-shrink-0">
                <button type="button" @click="tableBoardOpen = true" class="w-full flex items-center gap-2 px-3 py-1.5 hover:bg-gray-100 dark:hover:bg-gray-900 transition" title="{{ __('pos.ti_open_table_board') }}">
                    <svg class="w-3.5 h-3.5 text-teal-700 dark:text-teal-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M5 10v9m14-9v9M4 5h16a1 1 0 011 1v3H3V6a1 1 0 011-1z"/></svg>
                    <span class="text-[11px] font-black text-gray-700 dark:text-gray-300 tracking-wide">TABLE</span>
                    <span x-show="boardCounts().occupied > 0" class="min-w-[16px] px-1 py-0.5 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-[9px] rounded-full font-black" x-text="boardCounts().occupied"></span>
                    <span x-show="boardCounts().reserved > 0" class="min-w-[16px] px-1 py-0.5 bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 text-[9px] rounded-full font-black" x-text="boardCounts().reserved"></span>
                    <span x-show="boardCounts().waiter > 0" class="min-w-[16px] px-1 py-0.5 bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 text-[9px] rounded-full font-black animate-pulse" x-text="boardCounts().waiter"></span>
                    {{-- 'C' (counter-orders) badge REMOVED (ZFC 5 Aug 2026) — counter
                         orders ab board par nahi dikhte, sirf ghanti panel mein. --}}
                    <span x-show="heldNoTable().length > 0" class="min-w-[16px] px-1 py-0.5 bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 text-[9px] rounded-full font-black" title="{{ __('pos.ti_held_orders_no_table') }}" x-text="'H' + heldNoTable().length"></span>
                    <span class="flex-1"></span>
                    {{-- Chalti hui raqam — sab khule orders (tables + counter) ka live sum --}}
                    <span x-show="boardOpenTotal() > 0" class="text-[9px] font-bold text-gray-500 dark:text-gray-400 whitespace-nowrap" title="{{ __('pos.ti_tables_running_amount') }}" x-text="'Rs ' + boardOpenTotal().toLocaleString() + window.TXT.running_amount_sfx"></span>
                    <kbd class="text-[8px] bg-gray-200 dark:bg-gray-800 text-gray-500 dark:text-gray-400 px-1 py-0.5 rounded font-mono flex-shrink-0">Alt+B</kbd>
                    <svg class="w-3.5 h-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V6a2 2 0 012-2h2m8 0h2a2 2 0 012 2v2m0 8v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2"/></svg>
                </button>
            </div>

            {{-- TABLE BOARD MODAL — z-40 so the tile ACTION MENU / FINAL confirm
                 (both z-50) stack ABOVE it. Tile/chip click closes the modal
                 first (cart or menu becomes the focus). ESC yahan tabhi chalta
                 hai jab menu/confirm khule na hon (unka apna ESC pehle hai). --}}
            <div x-show="tableBoardOpen" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-40 flex items-center justify-center p-4" @click.self="tableBoardOpen = false" @keydown.escape.window="if (tableBoardOpen && !boardMenuTable && !boardConfirm && !boardShift && !showPayModal) tableBoardOpen = false">
                <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[80vh] flex flex-col overflow-hidden" x-transition.scale.90>
                    <div class="p-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-2">
                        <svg class="w-4 h-4 text-teal-700 dark:text-teal-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M5 10v9m14-9v9M4 5h16a1 1 0 011 1v3H3V6a1 1 0 011-1z"/></svg>
                        <h3 class="text-base font-black text-gray-900 dark:text-white">{{ __('pos.table_board') }}</h3>
                        <span x-show="boardCounts().occupied > 0" class="min-w-[18px] px-1.5 py-0.5 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-[10px] rounded-full font-black text-center" x-text="boardCounts().occupied"></span>
                        <span x-show="boardCounts().reserved > 0" class="min-w-[18px] px-1.5 py-0.5 bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 text-[10px] rounded-full font-black text-center" x-text="boardCounts().reserved"></span>
                        <span x-show="boardCounts().waiter > 0" class="min-w-[18px] px-1.5 py-0.5 bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 text-[10px] rounded-full font-black text-center animate-pulse" x-text="boardCounts().waiter"></span>
                        {{-- 'C' badge REMOVED (ZFC 5 Aug 2026) — counter orders sirf ghanti panel mein. --}}
                        <span x-show="heldNoTable().length > 0" class="min-w-[18px] px-1.5 py-0.5 bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 text-[10px] rounded-full font-black text-center" title="{{ __('pos.ti_held_orders_no_table') }}" x-text="'H' + heldNoTable().length"></span>
                        <span class="flex-1"></span>
                        <span x-show="boardOpenTotal() > 0" class="text-[11px] font-bold text-gray-500 dark:text-gray-400 whitespace-nowrap" title="{{ __('pos.ti_tables_running_amount') }}" x-text="'Rs ' + boardOpenTotal().toLocaleString() + window.TXT.running_amount_sfx"></span>
                        <button @click="tableBoardOpen = false" class="text-gray-400 hover:text-gray-600 flex-shrink-0"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                    </div>
                    <div class="p-3 overflow-y-auto">
                        <template x-if="tableFloors.length === 0">
                            <p class="text-xs text-gray-400 text-center py-6" x-text="tablesLoading ? window.TXT.tables_loading_js : window.TXT.no_tables_set"></p>
                        </template>
                        <template x-for="floor in tableFloors" :key="'bf' + floor.name">
                            <div>
                                <p x-show="tableFloors.length > 1" class="text-[10px] font-bold text-gray-400 uppercase mt-1.5 px-1" x-text="floor.name"></p>
                                <div class="grid grid-cols-3 gap-2 mt-1.5">
                                    <template x-for="t in floor.tables" :key="'bt' + t.id">
                                        <button type="button" @click="tableBoardOpen = false; boardTileClick(t)" class="rounded-lg border-2 px-2 py-1.5 text-left transition hover:scale-[1.02]" :class="boardTileClass(t)">
                                            <span class="flex items-center justify-between gap-1">
                                                <span class="text-xs font-black" x-text="'T-' + t.table_number"></span>
                                                <span class="text-[10px] font-bold whitespace-nowrap" :class="boardTileUrgent(t) ? 'animate-pulse' : ''" x-text="(boardTileUrgent(t) ? '⚠ ' : '') + boardTileTime(t)"></span>
                                            </span>
                                            <span class="block text-[10px] truncate font-medium opacity-90" x-text="boardTileSub(t)"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </template>
                        {{-- Counter Orders (bina table) REMOVED from the board (ZFC + owner,
                             5 Aug 2026): dining board sirf TABLES dikhaye — waiter ke
                             takeaway/delivery orders ab SIRF ghanti (incoming) panel mein
                             milte hain (wahan wahi ATOMIC claim → cart-load path chalta hai). --}}
                        {{-- Held Orders (bina table) — F3 window RETIRED (owner 26 Jul 2026):
                             jo held orders kisi table pe NAHIN hain woh yahan amber chips
                             mein dikhte hain. Click = tiles jaisa ACTION MENU (kabhi direct
                             action nahi). Table waale held orders tiles pe occupied hain. --}}
                        <template x-if="heldNoTable().length > 0">
                            <div class="mt-2.5">
                                <p class="text-[10px] font-bold text-amber-600 dark:text-amber-400 uppercase px-1">{{ __('pos.held_orders_no_table') }}</p>
                                <div class="grid grid-cols-3 gap-2 mt-1.5">
                                    <template x-for="o in heldNoTable()" :key="'bh' + o.id">
                                        <button type="button" @click="tableBoardOpen = false; heldMenu = o" class="rounded-lg border-2 px-2 py-1.5 text-left transition hover:scale-[1.02] border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 text-amber-800 dark:text-amber-200">
                                            <span class="flex items-center justify-between gap-1">
                                                <span class="text-[11px] font-black truncate" x-text="o.order_number"></span>
                                                <span class="text-[10px] font-bold whitespace-nowrap" x-text="'Rs ' + Math.round(o.total_amount || 0).toLocaleString()"></span>
                                            </span>
                                            <span class="block text-[10px] truncate font-medium opacity-90" x-text="(o.customer_name ? o.customer_name + ' • ' : '') + ((o.items || []).length) + window.TXT.sfx_items"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
            @endif
            {{-- closes .tn-cart-side --}}
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         PAY MODAL — Final payment ONLY (Cash / Card → PRA submit).
         Provisional save is now a SEPARATE button + F9 shortcut
         in the right sidebar (no modal, no checkbox, no key conflict).
         ═══════════════════════════════════════════════════════════════ -->
    {{-- x-effect close branch: clear payingHeldOrderId whenever the modal is dismissed
         (ESC / click-away / Cancel). Without this a cancelled held-order payment left the
         stale id behind and the NEXT normal cart sale silently routed to payHeldOrderDirect
         for that old held order (processPayment checks payingHeldOrderId first). --}}
    <div x-show="showPayModal" x-cloak x-transition.opacity x-effect="if (showPayModal) { submitting = false; saveAsProvisional = false; payMethodIndex = (payPreselect === 1 ? 1 : 0); payPreselect = null; cashReceived = ''; payPrintReceipt = billPrintDefault(payModalOrderType()); } else if (!submitting) { payingHeldOrderId = null; payPrintReceipt = billPrintDefault(orderType); }" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showPayModal = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden" x-transition.scale.90>
            <div class="p-5 text-center border-b border-gray-100 dark:border-gray-800">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ __('pos.payment') }}</h3>
                {{-- Item #8 (owner, Jul 2026): held/dine-in orders pay with an EMPTY cart, so
                     the cart-based roundedTotal showed Rs. 0 here. payModalTotal switches to a
                     method-aware estimate computed from the held order itself (server total
                     from payOrder stays authoritative on the receipt). --}}
                <p class="text-3xl font-extrabold mt-2 text-purple-600 dark:text-purple-400" x-text="'Rs. ' + Number(payModalTotal).toLocaleString()"></p>
                <p x-show="!payingHeldOrderId && Math.abs(roundOff) > 0.001" class="text-[10px] text-gray-400 mt-0.5" x-text="(roundOff >= 0 ? window.TXT.rounded_up_by : window.TXT.rounded_down_by) + 'Rs. ' + Math.abs(roundOff).toFixed(2)"></p>
                {{-- Card-save mode: live bachat hint — total above is method-aware. --}}
                <p x-show="modalCardSaving > 0" x-cloak class="text-[11px] font-bold text-emerald-600 dark:text-emerald-400 mt-1" x-text="payMethodIndex === 1 ? (window.TXT.card_discount_rs + Number(modalCardSaving).toLocaleString() + window.TXT.savings_suffix) : (window.TXT.card_pay_rs_prefix + Number(modalCardSaving).toLocaleString() + window.TXT.saved_amount_sfx)"></p>
                <p x-show="stockError" class="text-xs text-red-500 mt-2 bg-red-50 dark:bg-red-900/20 p-2 rounded-lg" x-text="stockError"></p>
                <p x-show="submitting" class="text-xs text-purple-500 mt-2">{{ __('pos.processing_payment') }}</p>
                {{-- Task 287: Prepaid delivery banner in the pay modal --}}
                <template x-if="deliveryPrepaid && orderType === 'delivery'">
                    <div class="mt-2 flex items-center justify-center gap-1.5 px-3 py-1.5 bg-teal-50 dark:bg-teal-900/20 border border-teal-300 dark:border-teal-700 rounded-lg">
                        <svg class="w-4 h-4 text-teal-600 dark:text-teal-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span class="text-xs font-bold text-teal-700 dark:text-teal-300" x-text="window.TXT.delivery_prepaid_active_hint || 'Will save as Online / QR payment'"></span>
                    </div>
                </template>
            </div>
            {{-- Delivery Riders: rider picker REMOVED from the pay modal (owner, 20 Jul 2026)
                 — rider assignment now happens ONLY on the /pos/deliveries board after
                 payment; cash bills enter the rider khata the moment a rider is assigned. --}}
            <div class="p-4 grid grid-cols-2 gap-3">
                <button @click="payMethodIndex = 0; processPayment('cash')" :disabled="submitting" :class="payMethodIndex === 0 ? 'ring-2 ring-green-500 ring-offset-2 dark:ring-offset-gray-900 scale-105 shadow-sm border-green-400' : ''" class="py-4 rounded-xl text-center border-2 transition disabled:opacity-50 bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 hover:bg-green-100 hover:border-green-400">
                    <svg x-show="submitting" class="w-8 h-8 mx-auto mb-1 animate-spin text-green-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <svg x-show="!submitting" class="w-8 h-8 mx-auto mb-1 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    <span class="text-sm font-bold text-green-700 dark:text-green-400" x-text="submitting ? window.TXT.processing_ellipsis : window.TXT.cash_title"></span>
                    <span class="block text-[10px] font-semibold mt-0.5 text-green-600/60" x-text="(taxInclusive ? window.TXT.incl_tax_prefix : window.TXT.tax_colon) + (taxRules['cash'] || 16) + '%'"></span>
                    <kbd x-show="!submitting" class="block mt-0.5 text-[9px] font-mono text-green-500/60">{{ __('pos.press_1') }}</kbd>
                </button>
                <button @click="payMethodIndex = 1; processPayment('card')" :disabled="submitting" :class="payMethodIndex === 1 ? 'ring-2 ring-blue-500 ring-offset-2 dark:ring-offset-gray-900 scale-105 shadow-sm border-blue-400' : ''" class="py-4 rounded-xl text-center border-2 transition disabled:opacity-50 bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800 hover:bg-blue-100 hover:border-blue-400">
                    <svg x-show="submitting" class="w-8 h-8 mx-auto mb-1 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <svg x-show="!submitting" class="w-8 h-8 mx-auto mb-1 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    <span class="text-sm font-bold text-blue-700 dark:text-blue-400" x-text="submitting ? window.TXT.processing_ellipsis : window.TXT.card_title"></span>
                    <span class="block text-[10px] font-semibold mt-0.5 text-blue-600/60" x-text="(taxInclusive ? window.TXT.incl_tax_prefix : window.TXT.tax_colon) + (taxRules['debit_card'] || taxRules['card'] || 8) + '%' + (modalCardSaving > 0 ? ' • Save Rs. ' + Number(modalCardSaving).toLocaleString() : '')"></span>
                    <kbd x-show="!submitting" class="block mt-0.5 text-[9px] font-mono text-blue-500/60">{{ __('pos.press_2') }}</kbd>
                </button>
            </div>
            {{-- Cash Received / Wapsi (owner request, Jul 2026): optional input — cashier
                 types the note customer gave; big green "Wapas dein" shows the change.
                 CASH only (hidden when Card highlighted). Soft warning if under-paid —
                 never blocks. data-cash-input keyboard guard: digits type, Enter pays cash.
                 Aug 2026 (owner): per-company OPT-IN via companies.pos_cash_received_enabled
                 (default OFF, switch at POS Customize); column rides posConfigRev so
                 cached offline screens refresh when a company flips it. --}}
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
                <p x-show="parseFloat(cashReceived) - payModalTotal > 0.001" x-cloak class="mt-1.5 text-center text-base font-black text-green-600 dark:text-green-400" x-text="window.TXT.change_rs_prefix + Math.round(parseFloat(cashReceived) - payModalTotal).toLocaleString()"></p>
                <p x-show="cashReceived !== '' && parseFloat(cashReceived) > 0 && payModalTotal - parseFloat(cashReceived) > 0.001" x-cloak class="mt-1.5 text-center text-[11px] font-bold text-amber-600 dark:text-amber-400" x-text="window.TXT.short_by_rs + Math.round(payModalTotal - parseFloat(cashReceived)).toLocaleString() + window.TXT.more_needed_sfx"></p>
            </div>
            @endif
            {{-- Bill note (owner, 26 Jul 2026): per-item note inputs removed from cart rows —
                 THIS is now the single note surface, at final-bill time. Bound to kitchenNotes
                 which already rides every payload (sale/hold/update/offline). data-pay-note
                 keyboard guard in the modal handler stops 1/2/Enter shortcuts while typing. --}}
            <div class="px-4 pb-2" @click.stop>
                <textarea x-model="kitchenNotes" data-pay-note rows="2"
                    autocomplete="off" name="pos_bill_note_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                    placeholder="{{ __('pos.ph_bill_note_multi') }}"
                    class="w-full text-xs bg-amber-50/60 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-900/30 rounded-lg px-2.5 py-1.5 text-gray-700 dark:text-gray-300 focus:ring-amber-400 placeholder-gray-400 resize-y"></textarea>
            </div>
            {{-- Task 514: per-bill receipt auto-print choice (default = company setting;
                 unticked = SIRF is bill ki receipt auto-print skip — KOT/PRA/popup untouched). --}}
            <div class="px-4 pb-1" @click.stop>
                <label class="flex items-center gap-2 text-[11px] text-gray-600 dark:text-gray-300 cursor-pointer select-none">
                    <input type="checkbox" x-model="payPrintReceipt" class="w-4 h-4 rounded border-gray-300 text-purple-600 focus:ring-purple-500">
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

    @if($features->tables)
    <div x-show="showTablePicker" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showTablePicker = false">
        {{-- Pizza Master feedback (Jul 2026): bara "chart" layout — saari tables ek
             nazar mein (max-w-md → max-w-3xl, 3 → up-to-6 columns). --}}
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-3xl max-h-[85vh] overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ __('pos.select_table') }}</h3>
                    <p class="text-[10px] text-gray-400 mt-0.5">&uarr; &darr; &larr; &rarr; select &middot; Enter reserve &middot; Esc close</p>
                </div>
                <button @click="showTablePicker = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            {{-- Select-Table picker (Dine-In, Jul 2026): LIVE floors + tables, refreshed on every open via
                 /pos/restaurant/api/table-status. Green=free, amber=reserved, red=occupied.
                 Selecting a table RESERVES it server-side (race-safe) before it sticks. --}}
            <div class="p-4 max-h-[65vh] overflow-y-auto">
                <template x-if="tablesLoading && tableFloors.length === 0">
                    <p class="text-center text-sm text-gray-400 py-6">{{ __('pos.loading_tables') }}</p>
                </template>
                <template x-if="!tablesLoading && tableFloors.length === 0">
                    <p class="text-center text-sm text-gray-400 py-6">{{ __('pos.no_tables_configured') }}</p>
                </template>
                <template x-for="floor in tableFloors" :key="floor.name">
                    <div class="mb-3">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1.5" x-text="floor.name"></p>
                        <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-2">
                            <template x-for="t in floor.tables" :key="t.id">
                                {{-- Table-se-Bill (Jul 2026): occupied table WITH a waiting waiter
                                     order = clickable purple "Order Tayyar" card (claim + load to
                                     cart). 26 Jul 2026 (owner item 5): occupied WITHOUT waiter order
                                     ab bhi clickable — board ACTION MENU khulta hai (view/final/shift)
                                     — magar sirf jab cart KHALI ho. ZFC (3 Aug 2026): bhara cart ab
                                     DISABLED button NAHI — chup-chaap dead tile se cashier samjha
                                     table hamesha ke liye PHANS gaya; ab click chalta hai aur
                                     selectTable() hint deta hai (view-only rule barqarar). --}}
                                <button @click="selectTable(t)" class="py-3 px-2 rounded-xl text-center border-2 transition"
                                    :class="(incomingForTable(t) ? 'border-purple-400 bg-purple-50 dark:bg-purple-900/20 hover:border-purple-500 hover:scale-105' : (t.status === 'occupied' ? (cart.length > 0 ? 'border-red-300 bg-red-50 dark:bg-red-900/20 cursor-not-allowed' : 'border-red-300 bg-red-50 dark:bg-red-900/20 hover:border-red-500 hover:scale-105') : (t.status === 'reserved' ? 'border-amber-300 bg-amber-50 dark:bg-amber-900/20 hover:border-amber-400 hover:scale-105' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 hover:border-purple-400 hover:scale-105'))) + (tablePickerFlat()[tablePickerIndex]?.id === t.id ? ' ring-2 ring-emerald-500 ring-offset-1 dark:ring-offset-gray-900' : '')">
                                    {{-- Top-view table + chairs diagram (color = status) --}}
                                    <svg viewBox="0 0 48 48" class="w-8 h-8 mx-auto mb-1" :class="incomingForTable(t) ? 'text-purple-500' : (t.status === 'occupied' ? 'text-red-500' : (t.status === 'reserved' ? 'text-amber-500' : 'text-green-500 dark:text-green-400'))" fill="currentColor" aria-hidden="true">
                                        <rect x="17" y="1.5" width="14" height="7" rx="3"/>
                                        <rect x="17" y="39.5" width="14" height="7" rx="3"/>
                                        <rect x="1.5" y="17" width="7" height="14" rx="3"/>
                                        <rect x="39.5" y="17" width="7" height="14" rx="3"/>
                                        <circle cx="24" cy="24" r="13"/>
                                        <circle cx="24" cy="24" r="8.5" fill="#fff" fill-opacity="0.35"/>
                                    </svg>
                                    <p class="text-sm font-bold" :class="incomingForTable(t) ? 'text-purple-700 dark:text-purple-300' : (t.status === 'occupied' ? 'text-red-600' : 'text-gray-900 dark:text-white')" x-text="'T-' + t.table_number"></p>
                                    <template x-if="incomingForTable(t)">
                                        <span>
                                            {{-- ZFC issue #10b: "Order Tayyar" misled — order is only PUNCHED, not ready. --}}
                                            <span class="inline-block text-[9px] font-bold text-white bg-purple-600 rounded-full px-1.5 py-px animate-pulse">{{ __('pos.new_order') }}</span>
                                            <span class="block text-[9px] text-purple-600 dark:text-purple-300 font-medium truncate" x-text="incomingForTable(t).waiter + ' • Rs ' + Math.round(incomingForTable(t).total_amount).toLocaleString()"></span>
                                        </span>
                                    </template>
                                    <template x-if="!incomingForTable(t)">
                                        <span>
                                            <p class="text-[10px] text-gray-400" x-text="t.seats + window.TXT.sfx_seats"></p>
                                            <span x-show="t.status === 'occupied'" class="text-[9px] text-red-500 font-medium" x-text="window.TXT.occupied_word + (elapsedSince(t.occupied_since) ? ' • ' + elapsedSince(t.occupied_since) : '')"></span>
                                            <span x-show="t.status === 'reserved'" class="text-[9px] text-amber-600 font-medium" x-text="window.TXT.reserved_word + (elapsedSince(t.locked_at) ? ' • ' + elapsedSince(t.locked_at) : '')"></span>
                                        </span>
                                    </template>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>
                {{-- Counter Orders (bina table) REMOVED from the picker (ZFC + owner,
                     5 Aug 2026): "Select Table" sirf tables ke liye hai — waiter ke
                     takeaway/delivery orders SIRF ghanti (incoming) panel mein dikhte
                     hain, wahan se wahi atomic claim → cart-load path chalta hai. --}}
            </div>
        </div>
    </div>

    {{-- ═══ TABLE BOARD ACTION MENU (Jul 2026) ═══
         Tile click NEVER acts directly — this menu is the single control hub
         for a table: View/Edit (recall to cart), Final (confirm modal), KOT
         resend, Free/Cancel. Waiter orders go through the ATOMIC claim first
         (invariant) and are never Free-able from here. --}}
    <div x-show="boardMenuTable" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="boardMenuTable = null" @keydown.escape.window="if (boardShift) { boardShift = null; } else if (boardConfirm) { boardConfirm = null; } else if (boardMenuTable) { boardMenuTable = null; }">
        <template x-if="boardMenuTable">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xs overflow-hidden" x-transition.scale.90>
                <div class="p-4 border-b border-gray-100 dark:border-gray-800 flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" :class="boardIsWaiter(boardMenuTable) ? 'bg-purple-100 dark:bg-purple-900/30 text-purple-600' : (boardMenuTable.status === 'occupied' ? 'bg-red-100 dark:bg-red-900/30 text-red-600' : (boardMenuTable.status === 'reserved' ? 'bg-amber-100 dark:bg-amber-900/30 text-amber-600' : 'bg-green-100 dark:bg-green-900/30 text-green-600'))">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M5 10v9m14-9v9M4 5h16a1 1 0 011 1v3H3V6a1 1 0 011-1z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-base font-black text-gray-900 dark:text-white" x-text="'T-' + boardMenuTable.table_number"></p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400 truncate" x-text="boardMenuSummary()"></p>
                    </div>
                    <button @click="boardMenuTable = null" class="text-gray-400 hover:text-gray-600 flex-shrink-0"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <div class="p-3 space-y-2">
                    <template x-if="!boardMenuTable.order && boardMenuTable.status === 'available'">
                        <button @click="boardReserve()" :disabled="boardBusy" class="w-full py-2.5 rounded-xl text-sm font-bold text-white bg-teal-600 hover:bg-teal-700 disabled:opacity-40 transition">{{ __('pos.new_order_reserve_table') }}</button>
                    </template>
                    <template x-if="boardMenuTable.order">
                        <div class="space-y-2">
                            {{-- Items list (Pizza Master feedback, Jul 2026): popup mein dikhna
                                 chahiye ke table par KYA laga hua hai — lazy-fetched on open. --}}
                            <div class="max-h-36 overflow-y-auto rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-100 dark:border-gray-800 px-3 py-2">
                                <template x-if="boardMenuItems === null">
                                    <p class="text-[11px] text-gray-400 text-center py-1">{{ __('pos.items_loading') }}</p>
                                </template>
                                <template x-if="Array.isArray(boardMenuItems) && boardMenuItems.length === 0">
                                    <p class="text-[11px] text-gray-400 text-center py-1">{{ __('pos.no_items_found') }}</p>
                                </template>
                                <template x-for="(it, idx) in (Array.isArray(boardMenuItems) ? boardMenuItems : [])" :key="idx">
                                    <div class="flex justify-between gap-2 py-0.5">
                                        <span class="text-[11px] text-gray-700 dark:text-gray-300 truncate" x-text="(parseFloat(it.quantity) || 1) + ' × ' + it.item_name"></span>
                                        <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 flex-shrink-0" x-text="'Rs ' + Math.round(parseFloat(it.subtotal ?? 0) || ((parseFloat(it.quantity)||1) * (parseFloat(it.unit_price)||0))).toLocaleString()"></span>
                                    </div>
                                </template>
                            </div>
                            <button @click="boardViewEdit()" :disabled="boardBusy" class="w-full py-2.5 rounded-xl text-sm font-bold text-purple-700 dark:text-purple-300 bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 hover:bg-purple-100 disabled:opacity-40 transition" x-text="boardBusy ? window.TXT.loading_generic : window.TXT.open_edit_bill"></button>
                            {{-- Proof Bill (Pizza Master feedback, Jul 2026): customer ko bill
                                 dikhana ho to FINAL kiye BAGHAIR parchi — koi invoice nahi banta. --}}
                            <button @click="boardProofBill()" :disabled="boardBusy" class="w-full py-2.5 rounded-xl text-sm font-bold text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 hover:bg-blue-100 disabled:opacity-40 transition">&#128462; Proof Bill Print (bina final)</button>
                            <button @click="boardAskFinal()" :disabled="boardBusy" class="w-full py-2.5 rounded-xl text-sm font-extrabold text-white bg-green-600 hover:bg-green-700 disabled:opacity-40 transition" x-text="window.TXT.make_final_rs_prefix + Math.round(boardMenuTable.order.total_amount).toLocaleString()"></button>
                            {{-- Task 1379: reprint gate — see $canKotReprint. --}}
                            @if((($features->kot ?? false) || ($features->kitchen ?? false)) && $canKotReprint)
                            <button x-show="boardMenuTable.order.kot_sent_at" @click="boardResendKot()" :disabled="boardBusy" class="w-full py-2 rounded-xl text-xs font-bold text-orange-700 dark:text-orange-300 bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 hover:bg-orange-100 disabled:opacity-40 transition">{{ __('pos.kot_resend_btn') }}</button>
                            {{-- Task 753 MISSED-DELTA RECOVERY: akhri add-on (delta) KOT ka reprint —
                                 physical print fail par slip wapas nikalne ka rasta. --}}
                            <button x-show="boardMenuTable.order.kot_sent_at" @click="boardLastKot()" :disabled="boardBusy" title="{{ __('pos.ti_kot_last_addon') }}" class="w-full py-2 rounded-xl text-xs font-bold text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 hover:bg-amber-100 disabled:opacity-40 transition">{{ __('pos.kot_last_addon_btn') }}</button>
                            @endif
                            {{-- Table Shift (owner batch, 26 Jul 2026): har role, sirf
                                 KHALI table par, timer continue, KOT reprint NAHI. --}}
                            <button @click="boardAskShift()" :disabled="boardBusy" class="w-full py-2 rounded-xl text-xs font-bold text-teal-700 dark:text-teal-300 bg-teal-50 dark:bg-teal-900/20 border border-teal-200 dark:border-teal-800 hover:bg-teal-100 disabled:opacity-40 transition">&#8644; Table Badlein (Shift)</button>
                            {{-- Task #409 (owner, 10 Aug 2026): waiter orders are now cancellable
                                 HERE too — same warning modal + soft-cancel endpoint as cashier
                                 orders. The old "cancel from waiter/admin side only" note lied:
                                 no such cancel existed anywhere. --}}
                            <button x-show="canOrderCancel" @click="boardFree()" :disabled="boardBusy" class="w-full py-2 rounded-xl text-xs font-bold text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 hover:bg-red-100 disabled:opacity-40 transition">{{ __('pos.order_cancel_table_free') }}</button>
                        </div>
                    </template>
                    <template x-if="!boardMenuTable.order && boardMenuTable.status === 'reserved'">
                        <button @click="boardFree()" :disabled="boardBusy" class="w-full py-2.5 rounded-xl text-sm font-bold text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700 hover:bg-amber-100 disabled:opacity-40 transition">{{ __('pos.end_reservation_free_table') }}</button>
                    </template>
                </div>
            </div>
        </template>
    </div>

    {{-- ═══ TABLE BOARD FINAL CONFIRM (Jul 2026) ═══
         The anti-"anjaane mein final" step: table + amount shown big, then an
         explicit CASH/CARD choice. Menu closes before this opens (no overlap). --}}
    <div x-show="boardConfirm" x-cloak x-transition.opacity class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="boardConfirm = null">
        <template x-if="boardConfirm">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden" x-transition.scale.90>
                <div class="p-5 text-center border-b border-gray-100 dark:border-gray-800">
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wide">{{ __('pos.bill_will_be_final') }}</p>
                    <p class="text-2xl font-black text-gray-900 dark:text-white mt-1" x-text="'T-' + boardConfirm.table.table_number"></p>
                    <p class="text-lg font-extrabold text-green-600 mt-0.5" x-text="'Rs ' + Math.round(boardConfirm.table.order.total_amount).toLocaleString()"></p>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.finalize_confirm_choose_payment') }}</p>
                </div>
                <div class="p-4 grid grid-cols-2 gap-3">
                    <button @click="boardFinalPay('cash')" :disabled="boardBusy" class="py-4 rounded-xl text-center border-2 transition disabled:opacity-50 bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 hover:bg-green-100 hover:border-green-400">
                        <p class="text-sm font-black text-green-700 dark:text-green-300">CASH</p>
                    </button>
                    <button @click="boardFinalPay('card')" :disabled="boardBusy" class="py-4 rounded-xl text-center border-2 transition disabled:opacity-50 bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800 hover:bg-blue-100 hover:border-blue-400">
                        <p class="text-sm font-black text-blue-700 dark:text-blue-300">CARD</p>
                    </button>
                </div>
                {{-- Task 514 (Zahid Irfan, 12 Aug 2026): PER-BILL receipt print choice —
                     default company setting se aata hai (dine-in → print_on_pay_dinein),
                     cashier is EK bill ke liye override kar sakta hai. Sirf receipt
                     AUTO-print skip hoti hai; KOT/PRA/popup/manual print untouched. --}}
                <div class="px-4 pb-2">
                    <label class="flex items-center gap-2 text-[11px] text-gray-600 dark:text-gray-300 cursor-pointer select-none">
                        <input type="checkbox" x-model="boardPrintReceipt" class="w-4 h-4 rounded border-gray-300 text-green-600 focus:ring-green-500">
                        <span>{{ __('pos.final_print_receipt') }}</span>
                    </label>
                </div>
                <div class="px-4 pb-4">
                    <button @click="boardConfirm = null" :disabled="boardBusy" class="w-full py-2 rounded-xl text-xs font-bold text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 transition" x-text="boardBusy ? window.TXT.bill_creating : window.TXT.cancel_go_back"></button>
                </div>
            </div>
        </template>
    </div>

    {{-- ═══ UNSENT-CART SWITCH PROMPT (ZFC, Aug 2026) ═══
         Table already selected + unsent items in the cart, and the cashier moves
         to a DIFFERENT table or Takeaway/Delivery: explicit choice — take items
         along, or remove them. Silent carry-over band. z-[60] so it stacks above
         the table picker (picker stays open behind for Esc/cancel). --}}
    <div x-show="tableSwitchPrompt" x-cloak x-transition.opacity class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[60] flex items-center justify-center p-4" @click.self="tableSwitchPrompt = null">
        <template x-if="tableSwitchPrompt">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden" x-transition.scale.90>
                <div class="p-5 text-center border-b border-gray-100 dark:border-gray-800">
                    <div class="w-12 h-12 mx-auto rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center mb-2">
                        <svg class="w-6 h-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.947-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z"/></svg>
                    </div>
                    <p x-show="tableSwitchPrompt?.kind !== 'recall'" class="text-xs font-bold text-gray-400 uppercase tracking-wide">{{ __('pos.unsent_items_in_cart') }}</p>
                    <p x-show="tableSwitchPrompt?.kind === 'recall'" class="text-xs font-bold text-gray-400 uppercase tracking-wide">{{ __('pos.recall_dirty_title') }}</p>
                    <p class="text-2xl font-black text-gray-900 dark:text-white mt-1" x-text="tableSwitchTargetLabel()"></p>
                    <p x-show="tableSwitchPrompt?.kind !== 'recall'" class="text-[12px] text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.unsent_take_or_remove_q') }}</p>
                    <p x-show="tableSwitchPrompt?.kind === 'recall'" class="text-[12px] text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.recall_dirty_q') }}</p>
                    <p x-show="tableSwitchPrompt?.kind === 'recall' && !tableSwitchPrompt?.canSave" class="text-[11px] text-amber-600 dark:text-amber-400 mt-1">{{ __('pos.recall_dirty_save_na_hint') }}</p>
                </div>
                {{-- Task 1028: recall-dirty variant — save-first (jab mumkin) / discard / cancel --}}
                <div x-show="tableSwitchPrompt?.kind !== 'recall'" class="p-4 space-y-2">
                    <button @click="confirmTableSwitch('move')" class="w-full py-3 rounded-xl text-sm font-bold text-white bg-purple-600 hover:bg-purple-700 transition ring-offset-2 dark:ring-offset-gray-900" :class="tableSwitchIndex === 0 ? 'ring-2 ring-purple-500' : ''">1 · {{ __('pos.unsent_take_items_btn') }}</button>
                    <button @click="confirmTableSwitch('discard')" class="w-full py-3 rounded-xl text-sm font-bold text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-900/20 border-2 border-red-200 dark:border-red-800 hover:bg-red-100 hover:border-red-400 transition ring-offset-2 dark:ring-offset-gray-900" :class="tableSwitchIndex === 1 ? 'ring-2 ring-red-500' : ''">2 · {{ __('pos.unsent_remove_items_btn') }}</button>
                </div>
                <div x-show="tableSwitchPrompt?.kind === 'recall'" class="p-4 space-y-2">
                    <button x-show="tableSwitchPrompt?.canSave" @click="confirmTableSwitch('save')" class="w-full py-3 rounded-xl text-sm font-bold text-white bg-purple-600 hover:bg-purple-700 transition ring-offset-2 dark:ring-offset-gray-900" :class="tableSwitchIndex === 0 ? 'ring-2 ring-purple-500' : ''">1 · {{ __('pos.recall_save_switch_btn') }}</button>
                    <button @click="confirmTableSwitch('discard')" class="w-full py-3 rounded-xl text-sm font-bold text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-900/20 border-2 border-red-200 dark:border-red-800 hover:bg-red-100 hover:border-red-400 transition ring-offset-2 dark:ring-offset-gray-900" :class="(tableSwitchPrompt?.canSave ? tableSwitchIndex === 1 : tableSwitchIndex === 0) ? 'ring-2 ring-red-500' : ''"><span x-text="tableSwitchPrompt?.canSave ? '2' : '1'"></span> · {{ __('pos.recall_discard_switch_btn') }}</button>
                </div>
                <div class="px-4 pb-4">
                    <button @click="tableSwitchPrompt = null" class="w-full py-2 rounded-xl text-xs font-bold text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 transition">{{ __('pos.cancel_esc') }}</button>
                </div>
            </div>
        </template>
    </div>

    {{-- ═══ Task 565: PRINT-CONFIRM YES/NO DIALOG (opt-in per company) ═══
         Flag ON: payment success par auto-print chain se pehle FORAN yeh chhota
         in-screen dialog (naya browser popup nahi). Keyboard handleKey ke TOPMOST
         block se chalta hai (Enter=Yes default, Tab=toggle, Esc=No) — yahan sirf
         mouse clicks. z-index inline (arbitrary Tailwind class = Vite rebuild trap);
         receipt popup / board modals (z-50/60) ke UPAR rehna zaroori hai. --}}
    <div x-show="showPrintConfirm" x-cloak x-transition.opacity class="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4" style="display:none;z-index:80;">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xs overflow-hidden" x-transition.scale.90>
            <div class="p-5 text-center">
                <div class="w-12 h-12 mx-auto rounded-full bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center mb-2">
                    <svg class="w-6 h-6 text-purple-600 dark:text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4H7v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                </div>
                <p class="text-lg font-black text-gray-900 dark:text-white">{{ __('pos.print_confirm_q') }}</p>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-1">{{ __('pos.print_confirm_keys_hint') }}</p>
            </div>
            <div class="px-4 pb-4 grid grid-cols-2 gap-2">
                <button type="button" x-ref="printConfirmYes" @click="resolvePrintConfirm(true)" @focus="printConfirmChoice = 'yes'"
                        class="py-3 rounded-xl text-sm font-bold text-white bg-purple-600 hover:bg-purple-700 transition ring-offset-2 dark:ring-offset-gray-900 focus:outline-none"
                        :class="printConfirmChoice === 'yes' ? 'ring-2 ring-purple-500' : ''">{{ __('pos.print_confirm_yes') }}</button>
                <button type="button" x-ref="printConfirmNo" @click="resolvePrintConfirm(false)" @focus="printConfirmChoice = 'no'"
                        class="py-3 rounded-xl text-sm font-bold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 border-2 border-gray-200 dark:border-gray-700 hover:bg-gray-200 dark:hover:bg-gray-700 transition ring-offset-2 dark:ring-offset-gray-900 focus:outline-none"
                        :class="printConfirmChoice === 'no' ? 'ring-2 ring-gray-500 border-gray-400 dark:border-gray-500' : ''">{{ __('pos.print_confirm_no') }}</button>
            </div>
        </div>
    </div>

    {{-- ═══ Task 1349: COUNTER (TERMINAL) PICKER ═══
         Device-level: jo counter yahan chuna jaye wo ISI machine par yaad rehta
         hai (localStorage) aur har bill ke saath jata hai. "Koi nahin" chunne par
         billing bilkul pehle jaisi (counter khali) chalti rehti hai. z-index
         inline — arbitrary Tailwind class = Vite rebuild trap. --}}
    <div x-show="showTerminalPicker" x-cloak x-transition.opacity @click.self="showTerminalPicker = false"
         class="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4" style="display:none;z-index:70;">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden" x-transition.scale.90>
            <div class="px-5 pt-5 pb-3">
                <p class="text-lg font-black text-gray-900 dark:text-white">{{ __('pos.counter_picker_title') }}</p>
                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.counter_picker_hint') }}</p>
            </div>
            <div class="px-4 pb-2 max-h-[50vh] overflow-y-auto space-y-1.5">
                <template x-for="t in allTerminals" :key="t.id">
                    <button type="button" @click="setTerminal(t.id)"
                            class="w-full flex items-center justify-between gap-2 px-3 py-2.5 rounded-xl border text-left transition"
                            :class="terminalId === t.id ? 'bg-indigo-50 dark:bg-indigo-900/20 border-indigo-300 dark:border-indigo-700' : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700'">
                        <span class="min-w-0">
                            <span class="block text-sm font-bold text-gray-900 dark:text-white truncate" x-text="t.name"></span>
                            <span class="block text-[10px] font-mono text-gray-400 truncate" x-text="t.code"></span>
                        </span>
                        <svg x-show="terminalId === t.id" class="w-4 h-4 text-indigo-600 dark:text-indigo-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    </button>
                </template>
            </div>
            <div class="px-4 pb-4 pt-2 grid grid-cols-2 gap-2 border-t border-gray-100 dark:border-gray-800">
                <button type="button" @click="setTerminal(null)"
                        class="py-2.5 rounded-xl text-xs font-bold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 hover:bg-gray-200 dark:hover:bg-gray-700 transition">{{ __('pos.counter_none_option') }}</button>
                <button type="button" @click="showTerminalPicker = false"
                        class="py-2.5 rounded-xl text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition">{{ __('pos.close') }}</button>
            </div>
        </div>
    </div>

    {{-- ═══ CANCEL-ORDER WARNING MODAL (ZFC, 2 Aug 2026) ═══
         Bare confirm() ki jagah: items ki list + KOT-kitchen warning, taake
         bana hua khana anjane mein cancel na ho. --}}
    <div x-show="boardCancelAsk" x-cloak x-transition.opacity class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="if (!boardBusy) boardCancelAsk = null" @keydown.escape.window="if (boardCancelAsk && !boardBusy) boardCancelAsk = null">
        <template x-if="boardCancelAsk">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden" x-transition.scale.90>
                <div class="p-5 text-center border-b border-gray-100 dark:border-gray-800">
                    <div class="w-12 h-12 mx-auto rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center mb-2">
                        <svg class="w-6 h-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.947-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z"/></svg>
                    </div>
                    <p class="text-base font-black text-gray-900 dark:text-white">{{ __('pos.cancel_order_title') }}</p>
                    {{-- Task #643: table optional — claimed waiter takeaway/delivery has none;
                         show the order number instead. --}}
                    <p class="text-xl font-black text-gray-900 dark:text-white mt-0.5" x-text="(boardCancelAsk.table ? 'T-' + boardCancelAsk.table.table_number : (boardCancelAsk.order.order_number || '')) + ' • Rs ' + Math.round(boardCancelAsk.order.total_amount).toLocaleString()"></p>
                    {{-- Task #645: takeaway/delivery cancel — type badge (dine-in shows the table number above instead). --}}
                    <span x-show="!boardCancelAsk.table && boardCancelAsk.order.order_type && boardCancelAsk.order.order_type !== 'dine_in'" x-cloak class="inline-block mt-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300 uppercase tracking-wide" x-text="({ takeaway: '{{ __('pos.takeaway') }}', delivery: '{{ __('pos.delivery') }}' })[boardCancelAsk.order.order_type] || boardCancelAsk.order.order_type"></span>
                </div>
                <div class="px-5 py-3 max-h-48 overflow-y-auto">
                    <template x-if="boardCancelAsk.items === null"><p class="text-xs text-gray-400 text-center py-2">…</p></template>
                    <template x-if="Array.isArray(boardCancelAsk.items)">
                        <div class="space-y-1">
                            {{-- Task #643: noTicks — claimed-cart cancel has no real item ids
                                 (Made/Not-Made marking = Task #645); KOT alert still shows. --}}
                            <p x-show="boardCancelAsk.order.kot_sent_at && boardCancelAsk.items.length && !boardCancelAsk.noTicks" class="text-[10px] font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.cancel_mark_made_hint') }}</p>
                            <template x-for="it in boardCancelAsk.items" :key="it.id">
                                <div class="flex items-center justify-between gap-2 text-xs text-gray-700 dark:text-gray-300">
                                    <span class="flex-1" x-text="it.quantity + ' × ' + it.item_name"></span>
                                    <span class="text-gray-400" x-text="Math.round(it.subtotal).toLocaleString()"></span>
                                    <button x-show="boardCancelAsk.order.kot_sent_at && !boardCancelAsk.noTicks" type="button" @click="boardCancelMade[it.id] = !boardCancelMade[it.id]" class="px-2 py-1 rounded-lg text-[10px] font-bold border transition" :class="boardCancelMade[it.id] ? 'bg-orange-100 dark:bg-orange-900/30 border-orange-400 text-orange-700 dark:text-orange-300' : 'bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-400'" x-text="boardCancelMade[it.id] ? '{{ __('pos.item_made_yes') }}' : '{{ __('pos.item_made_no') }}'"></button>
                                </div>
                            </template>
                        </div>
                    </template>
                    <div x-show="boardCancelAsk.order.kot_sent_at" class="mt-3 px-3 py-2 rounded-lg bg-orange-50 dark:bg-orange-900/20 border border-orange-300 dark:border-orange-700">
                        <p class="text-[11px] font-bold text-orange-700 dark:text-orange-300">&#9888;&#65039; {{ __('pos.cancel_kot_warning') }}</p>
                    </div>
                    <p x-show="!boardCancelAsk.order.kot_sent_at" class="mt-3 text-[11px] text-gray-400 text-center">{{ __('pos.cancel_no_kot_note') }}</p>
                </div>
                <div class="p-4 grid grid-cols-1 gap-2">
                    <button @click="boardCancelAsk = null" :disabled="boardBusy" class="w-full py-2.5 rounded-xl text-sm font-bold text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 transition">{{ __('pos.cancel_keep_order') }}</button>
                    <button @click="boardCancelConfirm()" :disabled="boardBusy" class="w-full py-2.5 rounded-xl text-sm font-extrabold text-white bg-red-600 hover:bg-red-700 disabled:opacity-40 transition"><span x-show="!boardBusy">{{ __('pos.cancel_yes_free') }}</span><span x-show="boardBusy">…</span></button>
                </div>
            </div>
        </template>
    </div>

    {{-- ═══ TABLE SHIFT MODAL (owner batch, 26 Jul 2026) ═══
         Board menu "Table Badlein" → sirf KHALI (green) tables ki grid. Shift
         server-side race-safe hai (lockForUpdate); timer purana chalta rahta
         hai; KOT dobara print NAHI hota. Har POS role ke liye available. --}}
    <div x-show="boardShift" x-cloak x-transition.opacity class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="if (!boardBusy) boardShift = null">
        <template x-if="boardShift">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md max-h-[70vh] overflow-hidden flex flex-col" x-transition.scale.90>
                <div class="p-4 border-b border-gray-100 dark:border-gray-800 flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 bg-teal-100 dark:bg-teal-900/30 text-teal-700 dark:text-teal-300">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-base font-black text-gray-900 dark:text-white" x-text="'T-' + boardShift.table.table_number + window.TXT.shift_order_suffix"></p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.pick_new_table_hint') }}</p>
                    </div>
                    <button @click="boardShift = null" :disabled="boardBusy" class="text-gray-400 hover:text-gray-600 flex-shrink-0"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <div class="p-4 overflow-y-auto">
                    <template x-if="boardShiftFree().length === 0">
                        <p class="text-center text-sm text-gray-400 py-6">{{ __('pos.no_empty_table_hint') }}</p>
                    </template>
                    <template x-for="floor in tableFloors" :key="'sh' + floor.name">
                        <div class="mb-3" x-show="floor.tables.some(t => t.status === 'available' && !t.order)">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-1.5" x-text="floor.name"></p>
                            <div class="grid grid-cols-3 gap-2">
                                <template x-for="t in floor.tables.filter(t => t.status === 'available' && !t.order)" :key="'shift' + t.id">
                                    <button @click="doShiftTable(t)" :disabled="boardBusy" class="py-3 px-2 rounded-xl text-center border-2 border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 hover:border-teal-500 hover:scale-105 disabled:opacity-40 transition">
                                        <p class="text-sm font-bold text-gray-900 dark:text-white" x-text="'T-' + t.table_number"></p>
                                        <p class="text-[10px] text-gray-400" x-text="(t.seats ? t.seats + window.TXT.sfx_seats : window.TXT.empty_word)"></p>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
                <div class="px-4 pb-4">
                    <button @click="boardShift = null" :disabled="boardBusy" class="w-full py-2 rounded-xl text-xs font-bold text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 transition" x-text="boardBusy ? window.TXT.shifting_ellipsis : window.TXT.cancel_go_back"></button>
                </div>
            </div>
        </template>
    </div>

    {{-- ═══ HELD ORDER ACTION MENU (bina-table board chip click, Jul 2026) ═══
         Wahi menu-pattern jaise table tiles: kabhi direct action nahi. Recall /
         PAY (pay-modal) / KOT / Delete — sab yahin; alag F3 window RETIRED. --}}
    <div x-show="heldMenu" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="heldMenu = null" @keydown.escape.window="if (heldMenu) heldMenu = null">
        <template x-if="heldMenu">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-xs overflow-hidden" x-transition.scale.90>
                <div class="p-4 border-b border-gray-100 dark:border-gray-800 flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 bg-amber-100 dark:bg-amber-900/30 text-amber-600">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-base font-black text-gray-900 dark:text-white" x-text="heldMenu.order_number"></p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400 truncate" x-text="(heldMenu.customer_name ? heldMenu.customer_name + ' • ' : '') + ((heldMenu.items || []).length) + ' items • Rs ' + Math.round(heldMenu.total_amount || 0).toLocaleString()"></p>
                    </div>
                    <button @click="heldMenu = null" class="text-gray-400 hover:text-gray-600 flex-shrink-0"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <div class="p-3 space-y-2">
                    <button @click="heldMenuRecall()" class="w-full py-2.5 rounded-xl text-sm font-bold text-purple-700 dark:text-purple-300 bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 hover:bg-purple-100 transition">{{ __('pos.open_edit_bill') }}</button>
                    <button @click="heldMenuPay()" class="w-full py-2.5 rounded-xl text-sm font-extrabold text-white bg-green-600 hover:bg-green-700 transition" x-text="window.TXT.pay_rs_prefix + Math.round(heldMenu.total_amount || 0).toLocaleString()"></button>
                    {{-- Task 1379: reprint gate. Re-send and Last Add-on are reprints
                         by definition → plain canKotReprint. The "KOT dekho" link is
                         NOT: on an order the kitchen has never seen (payment-first
                         hold) it renders the FIRST ticket, so it survives the block
                         exactly like the server does (kot_sent_at = already seen). --}}
                    @if($features->kot ?? false)
                    <div class="grid grid-cols-2 gap-2">
                        <a x-show="canKotReprint || !heldMenu.kot_sent_at" :href="'/pos/restaurant/orders/' + heldMenu.id + '/kitchen-ticket'" target="_blank" class="py-2 rounded-xl text-xs font-bold text-center text-orange-600 dark:text-orange-300 bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 hover:bg-orange-100 transition">{{ __('pos.kot_dekho') }}</a>
                        <button x-show="canKotReprint" @click="heldMenuResend()" class="py-2 rounded-xl text-xs font-bold text-orange-700 dark:text-orange-300 bg-orange-50 dark:bg-orange-900/20 border border-orange-300 dark:border-orange-700 hover:bg-orange-100 transition">{{ __('pos.kot_resend_btn') }}</button>
                    </div>
                    <button x-show="canKotReprint" @click="heldMenuLastKot()" title="{{ __('pos.ti_kot_last_addon') }}" class="w-full py-2 rounded-xl text-xs font-bold text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700 hover:bg-amber-100 transition">{{ __('pos.kot_last_addon_btn') }}</button>
                    @endif
                    <button @click="heldMenuDelete()" class="w-full py-2 rounded-xl text-xs font-bold text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 hover:bg-red-100 transition">{{ __('pos.order_delete_btn') }}</button>
                </div>
            </div>
        </template>
    </div>
    @endif

    {{-- HELD ORDERS window — sirf NON-table companies (owner 26 Jul 2026: table
         companies ke held orders TABLE board mein merge; alag window RETIRED). --}}
    @unless($features->tables ?? false)
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
                    <div class="p-4 border-b border-gray-100 dark:border-gray-800 transition-all" :class="activeHeldIndex === oi ? 'bg-purple-50 dark:bg-purple-900/15 ring-2 ring-purple-400 ring-inset' : ''">
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
                        <template x-if="order.table"><p class="text-[10px] text-purple-600 ml-7" x-text="window.TXT.table_t_colon + order.table.table_number + (elapsedSince(order.table.occupied_since) ? window.TXT.occupied_glue + elapsedSince(order.table.occupied_since) : '')"></p></template>
                        <div class="flex gap-2 mt-2 ml-7">
                            <button @click="recallOrder(order)" class="flex-1 py-2 text-xs font-bold text-purple-600 border border-purple-300 rounded-xl hover:bg-purple-50 transition">{{ __('pos.recall') }}</button>
                            {{-- Task 1379: reprint gate — same split as the held-order
                                 menu. The KOT link can still be a FIRST ticket, so it
                                 only disappears once the kitchen has seen the order. --}}
                            @if($features->kot)
                            <a x-show="canKotReprint || !order.kot_sent_at" :href="'/pos/restaurant/orders/' + order.id + '/kitchen-ticket'" target="_blank" title="{{ __('pos.ti_view_print_kot') }}" class="py-2 px-2 text-xs font-bold text-center text-orange-600 border border-orange-300 rounded-xl hover:bg-orange-50 transition">KOT</a>
                            <button x-show="canKotReprint" @click="resendKitchen(order)" title="Re-send full order ticket to kitchen (marked REPRINT)." class="py-2 px-2 text-xs font-bold text-orange-700 border border-orange-400 rounded-xl bg-orange-50 hover:bg-orange-100 transition">{{ __('pos.resend_short') }}</button>
                            <button x-show="canKotReprint" @click="reprintLastKot(order)" title="{{ __('pos.ti_kot_last_addon') }}" class="py-2 px-2 text-xs font-bold text-amber-700 border border-amber-400 rounded-xl bg-amber-50 hover:bg-amber-100 transition">{{ __('pos.kot_last_addon_short') }}</button>
                            @endif
                            <button @click="payHeldOrder(order.id)" class="flex-1 py-2 text-xs font-bold text-white bg-green-600 rounded-xl hover:bg-green-700 transition">{{ __('pos.pay') }}</button>
                            <button @click="deleteHeldOrder(order.id)" class="py-2 px-3 text-xs font-bold text-red-500 border border-red-300 rounded-xl hover:bg-red-50 transition">{{ __('pos.delete') }}</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
    @endunless

    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    {{-- PROVISIONAL BILLS MODAL — opens from header "Local" button (F10).      --}}
    {{-- Lists all bills with pra_status='local' for current company.           --}}
    {{-- Inline actions: Edit (opens edit page) / Delete / Make Final (PRA).    --}}
    {{-- Keyboard: ↑↓ navigate, Enter=Make Final, E=Edit, D=Delete, Esc=Close.  --}}
    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    <div x-show="showLocalBills" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showLocalBills = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between bg-purple-50 dark:bg-purple-900/20">
                <div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                        <svg class="w-5 h-5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                        {{ __('pos.provisional_bills') }} <span class="text-xs font-medium text-purple-600 ml-1" x-text="'(' + filteredLocalBills().length + (localSearch.trim() ? '/' + localBills.length : '') + ')'"></span>
                    </h3>
                    <p class="text-[10px] text-gray-500 mt-0.5">{{ __('pos.provisional_nav_hint') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="loadLocalBills()" :disabled="localBillsLoading" class="text-xs text-purple-600 hover:text-purple-800 font-semibold px-2 py-1 rounded hover:bg-purple-100 disabled:opacity-50" title="{{ __('pos.ti_refresh_list') }}">
                        <svg class="w-4 h-4" :class="localBillsLoading ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                    <button @click="showLocalBills = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
            </div>
            {{-- SEARCH (owner 1 Aug 2026): find a bill by customer name / phone / bill no.
                 Element-level keydown handlers REQUIRED (same reason as reprint search):
                 the global handleKey input-field gate swallows window-level keys while
                 this input has focus — which is the default (openLocalBills auto-focuses). --}}
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 relative">
                <svg class="w-4 h-4 text-gray-400 absolute left-7 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" x-model="localSearch" @input="activeLocalIndex = 0" x-ref="localSearchInput"
                       @keydown.down.prevent="activeLocalIndex = Math.min(activeLocalIndex + 1, Math.max(0, filteredLocalBills().length - 1))"
                       @keydown.up.prevent="activeLocalIndex = Math.max(activeLocalIndex - 1, 0)"
                       @keydown.enter.prevent="const b = filteredLocalBills()[activeLocalIndex]; if (b) { $el.blur(); askPromoteMethod(b); }"
                       @keydown.escape.prevent="showLocalBills = false"
                       autocomplete="off" name="local_bills_search_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                       placeholder="{{ __('pos.ph_provisional_search') }}"
                       class="w-full pl-9 pr-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-purple-500 placeholder-gray-400">
            </div>
            <div class="max-h-[58vh] overflow-y-auto">
                <template x-if="localBillsLoading && localBills.length === 0">
                    <div class="p-12 text-center text-gray-400">
                        <svg class="w-8 h-8 mx-auto mb-2 animate-spin text-purple-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
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
                    <div class="p-4 border-b border-gray-100 dark:border-gray-800 transition-all" :class="activeLocalIndex === bi ? 'bg-purple-50 dark:bg-purple-900/15 ring-2 ring-purple-400 ring-inset' : ''">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-[10px] font-mono text-gray-400 w-5" x-text="bi + 1"></span>
                                <span class="text-sm font-bold text-gray-900 dark:text-white" x-text="bill.invoice_number"></span>
                                <span class="text-[9px] bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full font-bold uppercase tracking-wide">Local</span>
                                <template x-if="bill.order_type">
                                    <span class="text-[9px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wide"
                                          :class="bill.order_type === 'delivery' ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : (bill.order_type === 'dine_in' ? 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300')"
                                          x-text="bill.order_type === 'dine_in' ? window.TXT.dine_in : (bill.order_type === 'delivery' ? window.TXT.delivery : window.TXT.takeaway)"></span>
                                </template>
                            </div>
                            <span class="text-sm font-bold text-purple-700 dark:text-purple-400" x-text="'Rs. ' + Number(bill.total_amount).toLocaleString()"></span>
                        </div>
                        <template x-if="bill.customer_name || bill.customer_phone">
                            <p class="text-[11px] font-semibold text-gray-700 dark:text-gray-300 ml-7 flex items-center gap-1.5 flex-wrap">
                                <svg class="w-3 h-3 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                <span x-text="bill.customer_name || window.TXT.customer_word"></span>
                                <template x-if="bill.customer_phone">
                                    <span class="font-mono font-medium text-gray-500" x-text="bill.customer_phone"></span>
                                </template>
                            </p>
                        </template>
                        <template x-if="bill.delivery_address">
                            <p class="text-[11px] text-gray-500 ml-7 flex items-start gap-1.5">
                                <svg class="w-3 h-3 mt-0.5 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                <span x-text="bill.delivery_address"></span>
                            </p>
                        </template>
                        <p class="text-[11px] text-gray-500 ml-7 mb-2" x-text="bill.items_count + window.TXT.sfx_item_s_dot + bill.created_human"></p>
                        <div class="flex gap-2 ml-7">
                            <a :href="'{{ route('pos.invoice.create') }}?edit_bill=' + bill.id" class="flex-1 py-2 text-xs font-bold text-blue-700 border border-blue-300 rounded-xl hover:bg-blue-50 transition text-center flex items-center justify-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                {{ __('pos.edit') }}
                            </a>
                            <button x-show="posRole !== 'pos_cashier'" @click="deleteProvisional(bill)" class="py-2 px-3 text-xs font-bold text-red-600 border border-red-300 rounded-xl hover:bg-red-50 transition flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V3a1 1 0 011-1h4a1 1 0 011 1v4"/></svg>
                                {{ __('pos.delete') }}
                            </button>
                            <button x-show="bill.kot_pending" @click="sendProvisionalKot(bill)" title="{{ __('pos.ti_send_kot_now') }}" class="py-2 px-3 text-xs font-bold text-orange-700 border border-orange-300 rounded-xl hover:bg-orange-50 transition flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/></svg>
                                {{ __('pos.send_kot') }} <kbd class="px-1 text-[9px] font-mono text-orange-400 border border-orange-200 rounded">K</kbd>
                            </button>
                            <button @click="askPromoteMethod(bill)" :title="praEnabled ? window.TXT.ti_pay_submit_pra : window.TXT.ti_pay_finalize_local" class="flex-1 py-2 text-xs font-bold text-white bg-purple-600 hover:bg-purple-700 rounded-xl transition shadow-sm flex items-center justify-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                {{ __('pos.make_final') }}
                            </button>
                        </div>
                    </div>
                </template>
            </div>
            <div x-show="localBills.length > 0" class="p-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 text-[11px] text-gray-500">
                <span>{{ __('pos.provisional_tip_pra') }}</span>
            </div>
        </div>
    </div>

    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    {{-- PENDING DELIVERIES panel (Task 114, owner "Rasta A" 2 Aug 2026).       --}}
    {{-- TODAY's (business day) delivery provisionals — payment aate hi ek      --}}
    {{-- click Final (Cash/Card) via the SAME promote path as F10 Make Final.  --}}
    {{-- Receipt print = opt-in checkbox (default NO); rider-khata warning.     --}}
    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    {{-- Task 543: Rider settle amount modal (replaces window.prompt) — deliveries.blade.php
         pattern: default full baqaya, live "Baqaya:" line, over-amount disables confirm.
         Sits ABOVE the pending-deliveries modal (inline z-index — no Tailwind rebuild dep). --}}
    <div x-show="riderSettleBill" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4" style="z-index: 60;" @click.self="riderSettleBill = null">
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700 w-full max-w-sm p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.settle_cash') }} — <span x-text="riderSettleBill ? (riderSettleBill.rider_name || window.TXT.rider_word) : ''"></span></h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3" x-text="riderSettleBill ? txtRiderSettleScope(riderSettleBill) : ''"></p>
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.cash_received_now') }}</label>
            <input type="number" id="rider-settle-amount" x-model="riderSettleAmount" min="1" step="0.01" inputmode="decimal"
                   @keydown.enter.prevent="submitRiderSettle()"
                   {{-- Task 545: global keydown handler ignores keys from form fields, so Escape must be handled on the input itself --}}
                   @keydown.escape.prevent.stop="riderSettleBill = null"
                   class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm font-bold focus:ring-purple-500 focus:border-purple-500">
            <p class="text-[11px] text-gray-400 mt-1">{{ __('pos.settle_partial_hint') }}</p>
            <p class="text-[11px] font-semibold text-red-600 dark:text-red-400 mt-1" x-show="parseFloat(riderSettleAmount || 0) > riderSettleOutstanding + 0.009" x-cloak>{{ __('pos.settle_amount_over_live') }}</p>
            <div class="flex items-center justify-between gap-3 mt-4">
                <div class="text-xs font-bold" :class="(riderSettleOutstanding - (parseFloat(riderSettleAmount) || 0)) > 0.009 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'">{{ __('pos.baqaya_colon') }} Rs. <span x-text="Math.max(0, riderSettleOutstanding - (parseFloat(riderSettleAmount) || 0)).toLocaleString()"></span></div>
                <div class="flex gap-2">
                    <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition" @click="riderSettleBill = null">{{ __('pos.cancel') }}</button>
                    <button type="button" class="px-4 py-2 rounded-lg bg-purple-600 text-white text-sm font-semibold shadow-sm hover:bg-purple-700 transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-1.5"
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
                {{-- x-for cap: server already limits to 100 provisionals; slice keeps the
                     render bounded even if that ever changes (pos-boot-splash-perf rule). --}}
                <template x-for="bill in pendingDeliveryBills().slice(0, 100)" :key="bill.id">
                    <div class="p-4 border-b border-gray-100 dark:border-gray-800">
                        <div class="flex items-center justify-between mb-1.5">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-bold text-gray-900 dark:text-white" x-text="bill.invoice_number"></span>
                                <span class="text-[9px] bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300 px-2 py-0.5 rounded-full font-bold uppercase tracking-wide" x-text="window.TXT.delivery"></span>
                                <template x-if="bill.rider_name">
                                    <span class="text-[9px] bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-300 px-2 py-0.5 rounded-full font-bold" x-text="'{{ __('pos.rider_word') }}: ' + bill.rider_name"></span>
                                </template>
                                <template x-if="bill.is_final">
                                    <span class="text-[9px] bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300 px-2 py-0.5 rounded-full font-bold uppercase tracking-wide">{{ __('pos.final_word') }}</span>
                                </template>
                                {{-- Task 513: unassigned final delivery bill — rider abhi tak nahi laga --}}
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
                        {{-- Rider-khata warning: bill is still on the rider's unsettled khata.
                             Final is ALLOWED (riders never touch invoice_mode/serials — the
                             khata follows rider_id + rider_settlement_id, not the bill mode);
                             the warning tells the cashier the CASH is still with the rider.
                             Task 990: gate to is_final — a provisional bill with a pre-assigned
                             rider hasn't collected cash yet, so the settle warning is misleading
                             (rider is just queued, not carrying money). Khata math untouched. --}}
                        <template x-if="bill.rider_unsettled && bill.is_final">
                            <div class="mb-2 px-3 py-2 rounded-lg bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 text-[11px] font-semibold text-orange-700 dark:text-orange-300 flex items-start gap-1.5">
                                <svg class="w-3.5 h-3.5 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                <div class="flex-1 min-w-0">
                                    <span><span x-text="bill.rider_name || '{{ __('pos.rider_word') }}'"></span> {{ __('pos.rider_unsettled_warn') }}</span>
                                    {{-- Scope line: settle covers the rider's WHOLE khata, not just this bill --}}
                                    <p class="mt-1 font-normal text-orange-600 dark:text-orange-400" x-text="txtRiderSettleScope(bill)"></p>
                                </div>
                                {{-- Task 123: one-click WHOLE-khata settle (reuses POST /pos/riders/{id}/settle) --}}
                                <button @click="settleRider(bill)" :disabled="riderSettleBusyId || deliveryFinalBusyId || promoteSubmitting"
                                        class="shrink-0 self-center px-3 py-1.5 text-[11px] font-bold text-white bg-orange-600 hover:bg-orange-700 rounded-lg transition shadow-sm flex items-center gap-1 disabled:opacity-50">
                                    <template x-if="riderSettleBusyId === bill.rider_id"><svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg></template>
                                    {{ __('pos.rider_settle_btn') }}
                                </button>
                            </div>
                        </template>
                        {{-- Task 513: UNASSIGNED delivery bill — rider dropdown yahin se
                             (POST pos.deliveries.assign, same backend as the Deliveries board).
                             Renders only when the user's deliveries access allows assign
                             (can_assign_rider from the API mirrors PosAccessService verdict).
                             Task 984: PROVISIONAL rows par bhi — assign endpoint sirf
                             settled/delivered/returned block karta hai, provisional allowed,
                             so cashier Final se pehle hi rider chun sakta hai.
                             Task 990: rider_unsettled warning sirf is_final rows par — provisional
                             pe rider pre-assign hota hai (cash abhi nahi mili), isliye settle
                             button confusing hoga; markup gate hi kaafi (khata math untouched). --}}
                        <template x-if="!bill.rider_id && canAssignRider && deliveryRiders.length > 0">
                            <div class="mb-2 flex items-center gap-2">
                                <svg class="w-4 h-4 text-red-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a2 2 0 104 0m-4 0a2 2 0 11-4 0m10 0a2 2 0 104 0"/></svg>
                                <select @change="assignRider(bill, $event.target.value); $event.target.value = ''"
                                        :disabled="riderAssignBusyId"
                                        class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs py-1.5 focus:ring-purple-500 focus:border-purple-500 disabled:opacity-50">
                                    <option value="">{{ __('pos.no_rider_opt') }}</option>
                                    {{-- Task 1132: 🪫 low-battery marker (≤20%, on-duty; NULL = old APK, shows nothing) --}}
                                    <template x-for="r in deliveryRiders" :key="r.id">
                                        <option :value="r.id" x-text="r.name + (r.battery_pct != null && r.battery_pct <= 20 ? ' 🪫 ' + r.battery_pct + '%' : '')"></option>
                                    </template>
                                </select>
                                <template x-if="riderAssignBusyId === bill.id">
                                    <svg class="w-4 h-4 animate-spin text-purple-500 shrink-0" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                </template>
                            </div>
                        </template>
                        {{-- PROVISIONAL bill: Final Cash/Card (promote path). FINAL bills
                             par yeh buttons render hi nahi hote — promote unpar kabhi nahi. --}}
                        <template x-if="!bill.is_final">
                        <div class="flex gap-2">
                            <button @click="finalizeDelivery(bill, 'cash')" :disabled="deliveryFinalBusyId || promoteSubmitting" class="flex-1 py-2.5 text-xs font-bold text-white bg-green-600 hover:bg-green-700 rounded-xl transition shadow-sm flex items-center justify-center gap-1.5 disabled:opacity-50">
                                <template x-if="deliveryFinalBusyId === bill.id"><svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg></template>
                                <template x-if="deliveryFinalBusyId !== bill.id"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg></template>
                                {{ __('pos.final_cash') }}
                            </button>
                            <button @click="finalizeDelivery(bill, 'card')" :disabled="deliveryFinalBusyId || promoteSubmitting" class="flex-1 py-2.5 text-xs font-bold text-white bg-teal-600 hover:bg-teal-700 rounded-xl transition shadow-sm flex items-center justify-center gap-1.5 disabled:opacity-50">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                                {{ __('pos.final_card') }}
                            </button>
                        </div>
                        </template>
                        {{-- Task 984: UNASSIGNED final bill — "Delivered (bina rider)" bhi
                             ab yahin se (Task 774 riderless updateStatus path; delivered_by
                             stamp hota hai, khata/settlement untouched). Purana Task 513
                             rider_id guard hata: backend ab riderless delivered ALLOW karta hai. --}}
                        <template x-if="bill.is_final && !bill.rider_id && !bill.delivery_status">
                            <button @click="markFinalDelivered(bill)" :disabled="deliveryFinalBusyId" class="w-full py-2.5 text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 rounded-xl transition shadow-sm flex items-center justify-center gap-1.5 disabled:opacity-50">
                                <template x-if="deliveryFinalBusyId === bill.id"><svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg></template>
                                <template x-if="deliveryFinalBusyId !== bill.id"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg></template>
                                {{ __('pos.delivered_no_rider_btn') }}
                            </button>
                        </template>
                        {{-- FINAL bill (3 Aug 2026): status chip + Delivered mark. Cash
                             khata settle upar wale orange rider block se hota hai. --}}
                        <template x-if="bill.is_final && bill.rider_id">
                        <div class="flex gap-2 items-stretch">
                            <span class="flex items-center px-2.5 rounded-xl text-[10px] font-bold"
                                  :class="bill.delivery_status === 'delivered' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400' : 'bg-purple-50 text-purple-700 dark:bg-purple-900/20 dark:text-purple-300'"
                                  x-text="bill.delivery_status === 'delivered' ? window.TXT.delivery_status_delivered : (bill.delivery_status === 'dispatched' ? window.TXT.delivery_status_dispatched : window.TXT.delivery_status_assigned)"></span>
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
                     (POST pos.deliveries.assign) chalata hai. --}}
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
                                                        class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs py-1.5 focus:ring-purple-500 focus:border-purple-500 disabled:opacity-50">
                                                    <option value="">{{ __('pos.no_rider_opt') }}</option>
                                                    <template x-for="r in deliveryRiders" :key="'oldr-' + r.id">
                                                        <option :value="r.id" x-text="r.name + (r.battery_pct != null && r.battery_pct <= 20 ? ' 🪫 ' + r.battery_pct + '%' : '')"></option>
                                                    </template>
                                                </select>
                                                <template x-if="riderAssignBusyId === bill.id">
                                                    <svg class="w-4 h-4 animate-spin text-purple-500 shrink-0" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
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
                    <input type="checkbox" x-model="deliveryPrintReceipt" @change="persistDeliveryPrintReceipt()" class="w-4 h-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                    <span>{{ __('pos.delivery_print_receipt') }}</span>
                </label>
            </div>
        </div>
    </div>

    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    {{-- QUICK RETURN MODAL (Task 681) — bill number → return form.             --}}
    {{-- Permission/stream/returnable rules all server-side (return-lookup).    --}}
    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    @if($canQuickReturn)
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
                       placeholder="{{ __('pos.quick_return_placeholder') }}"
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
    @endif

    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    {{-- REPRINT MODAL — opens from header "Reprint" button (Alt+R).            --}}
    {{-- ALL of today's completed bills (PRA / queue / failed / provisional /    --}}
    {{-- local) — click a row = instant print of the ORIGINAL receipt.          --}}
    {{-- Keyboard: type to search, ↑↓ navigate, Enter=Print, Esc=Close.         --}}
    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    <div x-show="showReprint" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showReprint = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 bg-teal-50 dark:bg-teal-900/20 flex-shrink-0">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            <svg class="w-5 h-5 text-teal-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                            {{ __('pos.reprint_todays_bills') }} <span class="text-xs font-medium text-teal-600 ml-1" x-text="'(' + filteredReprintBills().length + ')'"></span>
                        </h3>
                        <p class="text-[10px] text-gray-500 mt-0.5">{{ __('pos.reprint_click_hint') }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="loadReprintBills()" :disabled="reprintLoading" class="text-xs text-teal-600 hover:text-teal-800 font-semibold px-2 py-1 rounded hover:bg-teal-100 disabled:opacity-50" title="{{ __('pos.ti_refresh_list') }}">
                            <svg class="w-4 h-4" :class="reprintLoading ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        </button>
                        <button @click="showReprint = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                    </div>
                </div>
                <div class="mt-3 relative">
                    <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    {{-- Element-level keydown handlers REQUIRED: handleKey's global input-field
                         gate swallows window-level keys while this input has focus (which is the
                         default — openReprint auto-focuses it). Do NOT rely on the window branch. --}}
                    <input type="text" x-model="reprintSearch" @input="activeReprintIndex = 0" x-ref="reprintSearchInput"
                           @keydown.down.prevent="if (!reprintPreviewBill) activeReprintIndex = Math.min(activeReprintIndex + 1, Math.max(0, filteredReprintBills().length - 1))"
                           @keydown.up.prevent="if (!reprintPreviewBill) activeReprintIndex = Math.max(activeReprintIndex - 1, 0)"
                           @keydown.enter.prevent="if (reprintPreviewBill) { const b = reprintPreviewBill; reprintPreviewBill = null; reprintBill(b); } else if (filteredReprintBills()[activeReprintIndex]) { reprintBill(filteredReprintBills()[activeReprintIndex]) }"
                           @keydown.escape.prevent="if (reprintPreviewBill) { reprintPreviewBill = null } else { showReprint = false }"
                           autocomplete="off" name="reprint_search_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                           placeholder="{{ __('pos.ph_reprint_search') }}"
                           class="w-full pl-9 pr-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-teal-500 focus:border-teal-500 placeholder-gray-400">
                </div>
            </div>
            <div class="flex-1 overflow-y-auto">
                <template x-if="reprintLoading && reprintBills.length === 0">
                    <div class="p-12 text-center text-gray-400">
                        <svg class="w-8 h-8 mx-auto mb-2 animate-spin text-teal-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        <p class="text-sm">{{ __('pos.todays_bills_loading') }}</p>
                    </div>
                </template>
                <template x-if="!reprintLoading && filteredReprintBills().length === 0">
                    <div class="p-12 text-center text-gray-400">
                        <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        <p class="text-sm font-medium" x-text="reprintSearch ? window.TXT.no_bill_found : window.TXT.no_bills_today"></p>
                        <p class="text-[11px] text-gray-400 mt-1" x-text="reprintSearch ? window.TXT.change_search_try_again : window.TXT.todays_bills_appear_here"></p>
                    </div>
                </template>
                <template x-for="(bill, bi) in filteredReprintBills()" :key="bill.id">
                    <button type="button" @click="reprintBill(bill)" :disabled="reprintBusyId === bill.id"
                            class="w-full text-left p-4 border-b border-gray-100 dark:border-gray-800 transition-all hover:bg-teal-50/60 dark:hover:bg-teal-900/10 disabled:opacity-60"
                            :class="activeReprintIndex === bi ? 'bg-teal-50 dark:bg-teal-900/15 ring-2 ring-teal-400 ring-inset' : ''">
                        <div class="flex items-center justify-between mb-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-[10px] font-mono text-gray-400 w-5" x-text="bi + 1"></span>
                                <span class="text-sm font-bold text-gray-900 dark:text-white" x-text="bill.pra_invoice_number || bill.invoice_number"></span>
                                <span class="text-[9px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wide"
                                      :class="{
                                          'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300': bill.badge === 'pra',
                                          'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300': bill.badge === 'provisional',
                                          'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300': bill.badge === 'queue',
                                          'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300': bill.badge === 'failed',
                                          'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300': bill.badge === 'exempt',
                                          'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300': bill.badge === 'local'
                                      }"
                                      x-text="bill.badge === 'pra' ? window.TXT.pra_word : (bill.badge === 'provisional' ? window.TXT.provisional_word : (bill.badge === 'queue' ? window.TXT.sync_queue : (bill.badge === 'failed' ? window.TXT.failed_word : (bill.badge === 'exempt' ? window.TXT.exempt_badge : window.TXT.local_word))))"></span>
                                {{-- Order-type badge (ZFC, 30 Jul 2026): Dine-in/Takeaway/Delivery + table --}}
                                <template x-if="bill.order_type">
                                    <span class="text-[9px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wide"
                                          :class="{
                                              'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300': bill.order_type === 'dine_in',
                                              'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300': bill.order_type === 'takeaway',
                                              'bg-pink-100 text-pink-700 dark:bg-pink-900/40 dark:text-pink-300': bill.order_type === 'delivery'
                                          }"
                                          x-text="orderTypeLabel(bill)"></span>
                                </template>
                            </div>
                            <span class="text-sm font-bold text-teal-700 dark:text-teal-400" x-text="'Rs. ' + Number(bill.total_amount).toLocaleString()"></span>
                        </div>
                        <div class="flex items-center justify-between ml-7">
                            <p class="text-[11px] text-gray-500">
                                <span x-text="bill.created_time"></span>
                                <template x-if="bill.customer_name"><span x-text="' • ' + bill.customer_name"></span></template>
                                <template x-if="bill.payment_method"><span class="uppercase" x-text="' • ' + bill.payment_method.replace('_', ' ')"></span></template>
                            </p>
                            {{-- Task 1036: WhatsApp Bill — purana bill dobara bhejna (share link
                                 on-demand mint hota hai). Sirf routable number wale bills par. --}}
                            <template x-if="bill.wa_phone">
                                <span @click.stop="waShareReprint(bill)"
                                      class="text-[10px] font-bold text-green-600 hover:text-green-700 flex items-center gap-1 px-2 py-1 rounded-lg hover:bg-green-100 dark:hover:bg-green-900/30 mr-1"
                                      title="{{ __('pos.ti_wa_bill') }}">
                                    <svg x-show="waReprintBusyId !== bill.id" class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                    <svg x-show="waReprintBusyId === bill.id" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                    WA
                                </span>
                            </template>
                            {{-- Preview eye (ZFC, 30 Jul 2026): dekh kar print — row click = foran
                                 print barqarar. span (not button) — row itself is a <button>. --}}
                            {{-- pointer-only (review 30 Jul 2026): NOT focusable — row <button>
                                 owns keyboard; a nested focusable control = invalid nesting. --}}
                            <span @click.stop="openReprintPreview(bill)"
                                  class="text-[10px] font-bold text-gray-500 hover:text-teal-700 flex items-center gap-1 px-2 py-1 rounded-lg hover:bg-teal-100 dark:hover:bg-teal-900/30 mr-1"
                                  title="{{ __('pos.ti_bill_preview') }}">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                {{ __('pos.preview') }}
                            </span>
                            <span class="text-[10px] font-bold text-teal-600 flex items-center gap-1" x-show="reprintBusyId !== bill.id">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                {{ __('pos.print') }}
                            </span>
                            <span class="text-[10px] font-bold text-teal-600 flex items-center gap-1" x-show="reprintBusyId === bill.id">
                                <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                {{ __('pos.printing') }}
                            </span>
                        </div>
                    </button>
                </template>
            </div>
            <div x-show="filteredReprintBills().length > 0" class="p-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 text-[11px] text-gray-500 flex-shrink-0">
                <span>{{ __('pos.reprint_tip') }}</span>
            </div>
        </div>
    </div>

    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    {{-- REPRINT PREVIEW (ZFC, 30 Jul 2026) — eye button on a reprint row.       --}}
    {{-- Shows the REAL receipt HTML in an iframe (no auto_print), with a Print   --}}
    {{-- button that reuses reprintBill(). Sits ABOVE the reprint modal (z-50)    --}}
    {{-- via inline z-index — NO arbitrary Tailwind class (Vite build trap).      --}}
    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    <div x-show="reprintPreviewBill" x-cloak x-transition.opacity class="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4" style="z-index:60" @click.self="reprintPreviewBill = null" @keydown.escape.window="reprintPreviewBill = null">
        <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] flex flex-col overflow-hidden">
            <div class="px-4 py-3 bg-teal-600 flex items-center justify-between flex-shrink-0">
                <div class="min-w-0">
                    <h3 class="text-white font-bold text-sm truncate" x-text="reprintPreviewBill ? (window.TXT.bill_preview_prefix + (reprintPreviewBill.pra_invoice_number || reprintPreviewBill.invoice_number)) : ''"></h3>
                    <p class="text-teal-100 text-[11px]" x-text="reprintPreviewBill ? [orderTypeLabel(reprintPreviewBill), reprintPreviewBill.customer_name, 'Rs. ' + Number(reprintPreviewBill.total_amount).toLocaleString()].filter(Boolean).join(' • ') : ''"></p>
                </div>
                <button @click="reprintPreviewBill = null" class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/25 text-white flex items-center justify-center transition flex-shrink-0" title="{{ __('pos.ti_close') }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="flex-1 overflow-hidden bg-gray-100 dark:bg-gray-800">
                <template x-if="reprintPreviewBill">
                    <iframe :src="receiptViewUrl(reprintPreviewBill)" class="w-full h-full bg-white" style="min-height:55vh;border:0"></iframe>
                </template>
            </div>
            <div class="p-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 flex items-center justify-end gap-2 flex-shrink-0">
                {{-- Task 1036: WhatsApp Bill from the preview footer too --}}
                <template x-if="reprintPreviewBill && reprintPreviewBill.wa_phone">
                    <button @click="waShareReprint(reprintPreviewBill)" :disabled="waReprintBusyId === reprintPreviewBill.id" class="px-4 py-2 text-xs font-bold text-white bg-green-600 hover:bg-green-700 disabled:opacity-60 rounded-xl transition flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                        {{ __('pos.wa_bill_btn') }}
                    </button>
                </template>
                <button @click="reprintPreviewBill = null" class="px-4 py-2 text-xs font-bold text-gray-600 dark:text-gray-300 rounded-xl border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 transition">{{ __('pos.close_btn_ur') }}</button>
                <button @click="reprintBill(reprintPreviewBill); reprintPreviewBill = null" class="px-5 py-2 text-xs font-bold text-white bg-teal-600 hover:bg-teal-700 rounded-xl transition flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    {{ __('pos.print_btn_ur') }}
                </button>
            </div>
        </div>
    </div>

    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    {{-- P7 (F6): INCOMING WAITER ORDERS MODAL — header "Waiter" button.        --}}
    {{-- Load to Cart → manual billing path → settle on payment. KOT = full     --}}
    {{-- reprint any time; "+ Added" prints ONLY not-yet-printed (delta) rows.   --}}
    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    <div x-show="showIncoming" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="showIncoming = false"></div>
        <div class="relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden">
            <div class="px-5 py-4 bg-teal-600 flex items-center justify-between flex-shrink-0">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    <h3 class="text-white font-bold text-base">{{ __('pos.waiter_orders_awaiting') }}</h3>
                    <span x-show="incomingOrders.length" class="px-2 py-0.5 bg-white/20 text-white text-xs rounded-full font-bold" x-text="incomingOrders.length"></span>
                </div>
                <button @click="showIncoming = false" class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/25 text-white flex items-center justify-center transition" title="{{ __('pos.ti_close') }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto p-4 space-y-3">
                <div x-show="incomingLoading" class="text-center py-8 text-sm text-gray-400">{{ __('pos.loading_ellipsis') }}</div>
                <div x-show="!incomingLoading && incomingOrders.length === 0" class="text-center py-10">
                    <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-700 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">{{ __('pos.no_waiter_orders') }}</p>
                </div>
                <template x-for="o in incomingOrders" :key="o.id">
                    <div class="rounded-xl border border-teal-200 dark:border-teal-800 bg-teal-50/50 dark:bg-teal-900/10 p-3">
                        <div class="flex items-center justify-between gap-2 flex-wrap">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-mono text-sm font-bold text-gray-800 dark:text-gray-100" x-text="o.order_number"></span>
                                <span class="text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full bg-teal-600 text-white" x-text="'by ' + o.waiter"></span>
                                <span x-show="o.table" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300" x-text="'T-' + o.table"></span>
                                <span x-show="o.unprinted_count > 0 && o.items.some(i => i.printed)" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300" x-text="'+' + o.unprinted_count + ' new'"></span>
                            </div>
                            <div class="text-right">
                                <span class="text-sm font-black text-gray-900 dark:text-white" x-text="'Rs ' + Math.round(o.total_amount).toLocaleString()"></span>
                                <span class="block text-[10px] text-gray-400" x-text="o.created_at"></span>
                            </div>
                        </div>
                        <div class="mt-1.5 text-xs text-gray-600 dark:text-gray-300 leading-relaxed">
                            <template x-for="(it, ix) in o.items" :key="ix"><span><span x-text="it.quantity + '× ' + it.name"></span><span x-show="ix < o.items.length - 1"> · </span></span></template>
                        </div>
                        <div x-show="o.customer_name || o.customer_phone" class="mt-1 text-[11px] text-gray-500" x-text="(o.customer_name || '') + (o.customer_phone ? ' · ' + o.customer_phone : '')"></div>
                        <div class="mt-2.5 flex items-center gap-2 flex-wrap">
                            {{-- Route through the atomic claim (Table-se-Bill, Jul 2026): a direct
                                 loadIncomingToCart here would bypass single-winner claiming and two
                                 terminals could finalize the same order twice. --}}
                            <button @click="claimAndLoadIncoming(o)" class="px-4 py-2 rounded-lg bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold transition">{{ __('pos.load_to_cart') }}</button>
                            {{-- Task 1379: the FULL ticket is a reprint only once EVERY
                                 line has already been printed — exactly the server's
                                 rule (KotPrintService::isReprintRender). An order the
                                 kitchen has never seen still prints for a blocked
                                 cashier; the "Added" delta button below always does. --}}
                            <button x-show="canKotReprint || !(o.items || []).every(i => i.printed)" @click="printIncomingKot(o)" class="px-3 py-2 rounded-lg bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-xs font-bold transition" title="{{ __('pos.ti_print_full_kot') }}">KOT</button>
                            <button x-show="o.unprinted_count > 0 && o.items.some(i => i.printed)" @click="printIncomingKot(o, true)" class="px-3 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold transition" title="{{ __('pos.ti_print_only_new') }}">{{ __('pos.added_short') }}</button>
                            {{-- Task #409 (owner, 10 Aug 2026): waiter ke takeaway/delivery orders
                                 SIRF yahan dikhte hain — cancel bhi yahin se (soft-cancel →
                                 Cancelled Orders report, cancelled_by saved). --}}
                            <button x-show="canOrderCancel" @click="cancelIncoming(o)" class="px-3 py-2 rounded-lg bg-red-50 dark:bg-red-900/20 hover:bg-red-100 border border-red-200 dark:border-red-800 text-red-600 dark:text-red-400 text-xs font-bold transition">{{ __('pos.incoming_cancel_btn') }}</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    {{-- PROMOTE METHOD PICKER — F10 "Make Final": pick cash/card before final. --}}
    {{-- Cash vs card carry different PRA tax rates, so the bill is RE-TAXED and --}}
    {{-- given a real POS serial. Keys: ←→/↑↓ move, 1=Cash, 2=Card, Enter=go.    --}}
    {{-- Rendered AFTER the Local modal so it stacks on top at the same z-50.    --}}
    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    <div x-show="showPromoteMethod" x-cloak x-transition.opacity class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="if(!promoteSubmitting){ showPromoteMethod = false; promoteTarget = null; }">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 bg-gradient-to-r from-purple-50 to-purple-50/40 dark:from-purple-900/20 dark:to-gray-900">
                <h3 class="text-base font-black text-gray-900 dark:text-white flex items-center gap-2">
                    <svg class="w-5 h-5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    {{ __('pos.make_final_choose_payment') }}
                </h3>
                <p class="text-[11px] text-gray-500 mt-1">
                    <span class="font-bold text-gray-700 dark:text-gray-300" x-text="promoteTarget ? (promoteTarget.invoice_number || ('#' + promoteTarget.id)) : ''"></span>
                    <span x-show="promoteTarget"> • current Rs. <span x-text="promoteTarget ? Number(promoteTarget.total_amount).toLocaleString() : ''"></span></span>
                </p>
                <p class="text-[10px] text-amber-600 dark:text-amber-400 mt-1" x-text="praEnabled ? window.TXT.pay_edit_hint_pra_on : window.TXT.pay_edit_hint_pra_off"></p>
            </div>
            <div class="p-5 grid grid-cols-2 gap-3">
                <button @click="promoteMethodIndex = 0; promoteProvisional(promoteTarget, 'cash')" :disabled="promoteSubmitting" :class="promoteMethodIndex === 0 ? 'ring-2 ring-green-500 ring-offset-2 dark:ring-offset-gray-900 scale-105 border-green-400' : ''" class="py-4 rounded-xl text-center border-2 transition disabled:opacity-50 bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 hover:bg-green-100 hover:border-green-400">
                    <span class="block text-sm font-black text-green-700 dark:text-green-400">{{ __('pos.cash_title') }}</span>
                    <span class="block text-[10px] font-semibold mt-0.5 text-green-600/60" x-text="(taxInclusive ? window.TXT.incl_tax_prefix : window.TXT.tax_colon) + (taxRules['cash'] || 16) + '%'"></span>
                </button>
                <button @click="promoteMethodIndex = 1; promoteProvisional(promoteTarget, 'card')" :disabled="promoteSubmitting" :class="promoteMethodIndex === 1 ? 'ring-2 ring-blue-500 ring-offset-2 dark:ring-offset-gray-900 scale-105 border-blue-400' : ''" class="py-4 rounded-xl text-center border-2 transition disabled:opacity-50 bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800 hover:bg-blue-100 hover:border-blue-400">
                    <span class="block text-sm font-black text-blue-700 dark:text-blue-400">{{ __('pos.card_title') }}</span>
                    <span class="block text-[10px] font-semibold mt-0.5 text-blue-600/60" x-text="(taxInclusive ? window.TXT.incl_tax_prefix : window.TXT.tax_colon) + (taxRules['debit_card'] || taxRules['card'] || 8) + '%'"></span>
                </button>
            </div>
            <div class="px-5 pb-3">
                <div class="flex items-center gap-2 mb-3">
                    <div class="flex-1 h-px bg-gray-200 dark:bg-gray-700"></div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400">or</span>
                    <div class="flex-1 h-px bg-gray-200 dark:bg-gray-700"></div>
                </div>
                <button @click="promoteProvisional(promoteTarget, null, false)" :disabled="promoteSubmitting" class="w-full py-3 rounded-xl text-center border-2 transition disabled:opacity-50 bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800 hover:bg-amber-100 hover:border-amber-400">
                    <span class="block text-sm font-black text-amber-700 dark:text-amber-400">{{ __('pos.finalize_local_dont_send') }}</span>
                    <span class="block text-[10px] font-semibold mt-0.5 text-amber-600/70">{{ __('pos.amounts_stay_local_only') }}</span>
                </button>
            </div>
            {{-- Aug 2026 (delivery feedback): night bulk-finalizing — customer is not present,
                 receipt would be wasted paper. Checkbox skips the receipt AUTO-print for this
                 promote (KOT release + manual print from the popup still available). Remembered
                 per device. R key toggles. --}}
            <div class="px-5 pb-3">
                <label class="flex items-center gap-2 cursor-pointer select-none py-1">
                    <input type="checkbox" x-model="promoteNoPrint" @change="persistPromoteNoPrint()" class="w-4 h-4 rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">{{ __('pos.promote_no_print') }} <kbd class="ml-1 px-1 text-[9px] font-mono text-gray-400 border border-gray-300 dark:border-gray-600 rounded">R</kbd></span>
                </label>
            </div>
            <div class="px-5 pb-5">
                <button @click="if(!promoteSubmitting){ showPromoteMethod = false; promoteTarget = null; }" :disabled="promoteSubmitting" class="w-full py-2.5 rounded-xl text-xs font-bold text-gray-600 dark:text-gray-300 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-800 transition disabled:opacity-50">{{ __('pos.cancel_esc') }}</button>
            </div>
        </div>
    </div>

    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    {{-- FAILED BILLS MODAL — opens from header "Failed" button (F11).         --}}
    {{-- Lists bills with pra_status IN (failed,offline,pending) needing retry. --}}
    {{-- Inline actions: Retry (re-submit to PRA) / Edit / Delete.              --}}
    {{-- Keyboard: ↑↓ navigate, Enter=Retry, E=Edit, D=Delete, Esc=Close.       --}}
    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    <div x-show="showFailedBills" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showFailedBills = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20">
                <div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                        <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        {{ __('pos.failed_pra_bills') }} <span class="text-xs font-medium text-red-600 ml-1" x-text="'(' + failedBills.length + ')'"></span>
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
                        <p class="text-[11px] text-gray-400 mt-1">{{ __('pos.no_failed_pra') }}</p>
                    </div>
                </template>
                <template x-for="(bill, bi) in failedBills" :key="bill.id">
                    <div class="p-4 border-b border-gray-100 dark:border-gray-800 transition-all" :class="activeFailedIndex === bi ? 'bg-red-50 dark:bg-red-900/15 ring-2 ring-red-400 ring-inset' : ''">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-[10px] font-mono text-gray-400 w-5" x-text="bi + 1"></span>
                                <span class="text-sm font-bold text-gray-900 dark:text-white" x-text="bill.invoice_number"></span>
                                <span class="text-[9px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wide"
                                      :class="bill.pra_status === 'failed' ? 'bg-red-100 text-red-700' : (bill.pra_status === 'offline' ? 'bg-orange-100 text-orange-700' : 'bg-yellow-100 text-yellow-700')"
                                      x-text="bill.pra_status"></span>
                                <template x-if="bill.customer_name">
                                    <span class="text-[10px] bg-blue-50 text-blue-600 px-1.5 py-0.5 rounded-full font-medium" x-text="bill.customer_name"></span>
                                </template>
                            </div>
                            <span class="text-sm font-bold text-red-700 dark:text-red-400" x-text="'Rs. ' + Number(bill.total_amount).toLocaleString()"></span>
                        </div>
                        <p class="text-[11px] text-gray-500 ml-7 mb-1" x-text="bill.items_count + window.TXT.sfx_item_s_dot + bill.created_human"></p>
                        {{-- Task 624: asal wajah (PRA timeout / server error) — human message pehle, warna raw code. --}}
                        <template x-if="bill.error_message">
                            <p class="text-[10px] text-red-600 dark:text-red-400 ml-7 mb-2 leading-snug" x-text="'⚠ ' + bill.error_message"></p>
                        </template>
                        {{-- Raw response code hamesha dikhe (reviewer: generic message ke saath bhi code na chhupe) --}}
                        <template x-if="bill.error_code && (!bill.error_message || !String(bill.error_message).includes(String(bill.error_code)))">
                            <p class="text-[10px] text-red-500 ml-7 mb-2 font-mono truncate" x-text="'⚠ ' + bill.error_code"></p>
                        </template>
                        <div class="flex gap-2 ml-7 mt-2">
                            <a :href="'{{ url('/pos/transaction') }}/' + bill.id + '/edit?from=sale'" class="flex-1 py-2 text-xs font-bold text-blue-700 border border-blue-300 rounded-xl hover:bg-blue-50 transition text-center flex items-center justify-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                {{ __('pos.edit') }}
                            </a>
                            <button @click="deleteFailed(bill)" class="py-2 px-3 text-xs font-bold text-red-600 border border-red-300 rounded-xl hover:bg-red-50 transition flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V3a1 1 0 011-1h4a1 1 0 011 1v4"/></svg>
                                Del
                            </button>
                            <button @click="retryFailed(bill)" :disabled="!praEnabled || bill._retrying" :title="praEnabled ? window.TXT.ti_retry_pra : window.TXT.ti_pra_disabled" class="flex-1 py-2 text-xs font-bold text-white bg-gradient-to-br from-red-600 to-orange-600 hover:from-red-700 hover:to-orange-700 rounded-xl transition shadow-sm disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-1.5">
                                <svg x-show="!bill._retrying" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                <svg x-show="bill._retrying" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                <span x-text="bill._retrying ? window.TXT.retrying_ellipsis : window.TXT.retry_word"></span>
                            </button>
                        </div>
                    </div>
                </template>
            </div>
            <div x-show="failedBills.length > 0" class="p-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 text-[11px] text-gray-500 flex items-center justify-between">
                <span>{{ __('pos.failed_tip_pra') }}</span>
                <a href="{{ route('pos.transactions') }}?tab=failed" class="text-red-600 hover:underline font-semibold">{{ __('pos.open_full_page') }}</a>
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
                <input type="text" x-model="customerSearch" @input="onCustomerPhoneSearch()" placeholder="{{ __('pos.ph_search_name_phone') }}" autocomplete="one-time-code" name="pos_custsearch_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white text-sm px-3 py-2 focus:ring-purple-500">
                <template x-if="customerLookupResult && customerLookupResult.found">
                    <div class="mt-2 p-2.5 bg-green-50 dark:bg-green-900/20 rounded-xl border border-green-200 dark:border-green-800">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-full bg-green-200 dark:bg-green-800 flex items-center justify-center flex-shrink-0"><span class="text-xs font-bold text-green-700" x-text="customerLookupResult.customer.name.charAt(0)"></span></div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-bold text-green-800 dark:text-green-200" x-text="customerLookupResult.customer.name"></p>
                                <p class="text-xs text-green-600" x-text="customerLookupResult.stats.total_orders + window.TXT.sfx_orders_rs + Number(customerLookupResult.stats.total_spent).toLocaleString() + window.TXT.sfx_spent"></p>
                                <template x-if="customerLookupResult.customer.address">
                                    <p class="text-xs text-green-500 truncate" x-text="'📍 ' + customerLookupResult.customer.address"></p>
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
                    <div class="w-full flex items-center gap-3 px-4 py-3 hover:bg-purple-50 dark:hover:bg-purple-900/20 transition border-b border-gray-50 dark:border-gray-800">
                        <button @click="selectCustomerWithStats(c)" class="flex items-center gap-3 flex-1 min-w-0">
                            <div class="w-9 h-9 rounded-full bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center flex-shrink-0"><span class="text-sm font-bold text-purple-600 dark:text-purple-400" x-text="c.name.charAt(0)"></span></div>
                            <div class="text-left min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white truncate" x-text="c.name"></p>
                                <p class="text-xs text-gray-400" x-text="c.phone || window.TXT.no_phone"></p>
                                <template x-if="c.address"><p class="text-xs text-gray-400 truncate" x-text="'📍 ' + c.address"></p></template>
                            </div>
                        </button>
                        <button @click="loadCustomerHistory(c.id)" class="flex-shrink-0 text-[9px] font-bold text-purple-600 hover:text-purple-800 bg-purple-50 dark:bg-purple-900/30 px-2 py-1 rounded-lg transition" title="{{ __('pos.ti_view_history') }}">
                            <svg class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </button>
                    </div>
                </template>
            </div>
            <div class="p-3 border-t border-gray-200 dark:border-gray-700">
                <div x-show="!showQuickAdd">
                    <button @click="showQuickAdd = true" class="w-full py-2.5 text-sm font-bold text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/20 rounded-xl hover:bg-purple-100 transition">{{ __('pos.add_new_customer_btn') }}</button>
                </div>
                <div x-show="showQuickAdd" class="space-y-2">
                    <input type="text" x-model="quickCustomerName" placeholder="{{ __('pos.ph_customer_name_optional') }}" autocomplete="one-time-code" name="pos_quickcust_name_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-purple-500">
                    <input type="text" x-model="quickCustomerPhone" placeholder="{{ __('pos.ph_phone_req') }}" autocomplete="one-time-code" name="pos_quickcust_phone_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-purple-500">
                    @if($features->delivery)
                    <input type="text" x-model="quickCustomerAddress" placeholder="{{ __('pos.ph_address_delivery') }}" autocomplete="one-time-code" name="pos_quickcust_addr_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-purple-500">
                    @endif
                    <div class="flex gap-2">
                        <button @click="showQuickAdd = false" class="flex-1 py-2 text-xs font-semibold text-gray-500 bg-gray-100 dark:bg-gray-800 rounded-xl">{{ __('pos.cancel') }}</button>
                        <button @click="addQuickCustomer()" class="flex-1 py-2 text-xs font-bold text-white bg-purple-600 rounded-xl hover:bg-purple-700">{{ __('pos.save_btn') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div x-show="showShortcuts" x-cloak x-transition.opacity @click.self="showShortcuts = false" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" style="display:none;">
        <div x-show="showShortcuts" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" @click.stop style="max-width:520px; width:100%; max-height:85vh; overflow-y:auto; background:white; border-radius:20px; box-shadow:0 25px 60px rgba(0,0,0,0.3);" class="dark:bg-gray-900">
            <div style="background:linear-gradient(135deg,#7c3aed,#6d28d9); padding:20px 24px; border-radius:20px 20px 0 0; display:flex; align-items:center; justify-content:space-between;">
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
                        <p style="font-size:10px; font-weight:800; color:#7c3aed; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">{{ __('pos.quick_actions') }}</p>
                        <div style="display:flex; flex-direction:column; gap:6px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.shortcuts_panel') }}</span>
                                <kbd style="background:linear-gradient(135deg,#7c3aed,#6d28d9); color:white; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F1</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.order_type_cycle') }}</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F2</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.clear_cart') }}</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F4</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.hold_order') }}</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F5</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.jump_to_cart') }}</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F6</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.customer_select') }}</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Alt+P</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.tables_board') }}</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Alt+B</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.pay_checkout') }}</span>
                                <kbd style="background:linear-gradient(135deg,#16a34a,#15803d); color:white; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F8</kbd>
                            </div>
                        </div>
                    </div>
                    <div>
                        <p style="font-size:10px; font-weight:800; color:#7c3aed; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">{{ __('pos.navigation') }}</p>
                        <div style="display:flex; flex-direction:column; gap:6px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.product_search') }}</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Ctrl+S</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.edit_cart_mode') }}</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Ctrl+E</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.customer_field') }}</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Ctrl+C</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.grid_navigate') }}</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Tab</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.close_back') }}</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Esc</kbd>
                            </div>
                        </div>
                        <p style="font-size:10px; font-weight:800; color:#7c3aed; text-transform:uppercase; letter-spacing:1px; margin:14px 0 8px;">{{ __('pos.cart_edit_mode') }}</p>
                        <div style="display:flex; flex-direction:column; gap:6px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.navigate_items') }}</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">&#8593; &#8595;</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.qty_up_down') }}</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">+ / -</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.set_qty_direct') }}</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">0-9</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">{{ __('pos.remove_item') }}</span>
                                <kbd style="background:#fecaca; color:#dc2626; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Del</kbd>
                            </div>
                        </div>
                    </div>
                </div>
                <div style="margin-top:16px; padding:10px 14px; background:linear-gradient(135deg,#f3e8ff,#ede9fe); border-radius:10px; display:flex; align-items:center; gap:8px;" class="dark:bg-purple-900/20">
                    <svg style="width:14px; height:14px; color:#7c3aed; flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p style="font-size:11px; color:#6b21a8; margin:0; font-weight:500;" class="dark:text-purple-300">{{ __('pos.type_letter_search_hint') }}</p>
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
                        {{ __('pos.qty_tax_adjust_hint_pra') }}
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

    {{-- Receipt Modal — backdrop-click is intentionally NOT bound (no accidental dismiss).         --}}
    {{-- Esc on this popup belongs to the browser print dialog first (closes that, not our popup).  --}}
    {{-- Closes via: X, Close button, "New Sale", OR the auto-close countdown (owner, 23 Jul 2026:  --}}
    {{-- per-company pos_receipt_autoclose_seconds, default 10s, 0 = persistent old behavior).      --}}
    {{-- Hover pauses the countdown; any click/keypress inside cancels it.                          --}}
    <div x-show="showReceipt" x-cloak x-transition.opacity x-effect="if (!showReceipt) { cancelPendingPrints(); cancelReceiptAutoClose(); stopPraPoll(); }" class="fixed inset-0 bg-gradient-to-br from-green-900/80 via-black/70 to-emerald-900/80 backdrop-blur-md z-50 flex items-center justify-center p-4">
        <div class="receipt-modal-enter relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col" style="max-height:92vh;" x-transition.scale.90
             @mouseenter="receiptClosePaused = true" @mouseleave="receiptClosePaused = false" @click="cancelReceiptAutoClose()">
            {{-- Auto-close countdown pill (visible only while the timer runs) --}}
            <button type="button" x-show="receiptCloseLeft > 0" x-cloak @click.stop="cancelReceiptAutoClose()"
                class="absolute top-3 left-3 z-10 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-gray-900/70 hover:bg-gray-900/90 text-white text-[11px] font-bold transition"
                title="{{ __('pos.ti_popup_autoclose') }}">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span x-text="receiptCloseLeft + 's'"></span>
                <span class="opacity-75 font-semibold">{{ __('pos.stop_word') }}</span>
            </button>
            {{-- Top-right cross (primary close action) --}}
            <button @click="closeReceiptPopup()" class="absolute top-3 right-3 z-10 w-8 h-8 rounded-full bg-white/80 dark:bg-gray-800/80 hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white flex items-center justify-center transition shadow-sm" title="{{ __('pos.ti_close_popup') }}">
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
                {{-- PRA fiscal status — the "production" proof the cashier needs to see at a glance --}}
                <div class="relative mt-2.5 flex items-center justify-center">
                    <span class="inline-flex items-center gap-1.5 text-[11px] font-extrabold uppercase tracking-wider px-3 py-1 rounded-full shadow-sm"
                          :class="lastIsOffline ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : (lastPraStatus === 'submitted' ? 'bg-emerald-600 text-white' : (lastPraStatus === 'pending' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' : ((lastPraStatus === 'offline' || lastPraStatus === 'failed') ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300')))">
                        <svg x-show="!lastIsOffline && lastPraStatus === 'submitted'" class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.42l-7.5 7.5a1 1 0 01-1.42 0l-3.5-3.5a1 1 0 111.42-1.42l2.79 2.8 6.79-6.8a1 1 0 011.42 0z" clip-rule="evenodd"/></svg>
                        <svg x-show="!lastIsOffline && lastPraStatus === 'pending'" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        <span x-text="lastIsOffline ? window.TXT.saved_offline_autosync : (lastPraStatus === 'submitted' ? window.TXT.pra_verified : (lastPraStatus === 'pending' ? window.TXT.reporting_to_pra : ((lastPraStatus === 'offline' || lastPraStatus === 'failed') ? window.TXT.saved_will_sync_pra : window.TXT.local_bill)))"></span>
                    </span>
                </div>
                {{-- Big total --}}
                <p class="relative mt-3 text-4xl font-black text-emerald-600 dark:text-emerald-400 tracking-tight" x-text="'Rs. ' + Number(lastTotal).toLocaleString()" style="font-variant-numeric: tabular-nums;"></p>
                {{-- Cash Received / Wapsi — big green change-due line for the cashier. --}}
                <div x-show="lastCashReceived > 0" x-cloak class="relative mt-2 mx-auto max-w-xs py-2 px-3 rounded-xl bg-green-600/10 border border-green-500/30">
                    <p class="text-[11px] font-bold text-gray-600 dark:text-gray-300" x-text="window.TXT.cash_received_rs + Number(lastCashReceived).toLocaleString()"></p>
                    <p x-show="lastCashReceived - lastTotal > 0.001" class="text-xl font-black text-green-600 dark:text-green-400" x-text="window.TXT.change_caps_prefix + Math.round(lastCashReceived - lastTotal).toLocaleString()"></p>
                </div>
                {{-- PRA fiscal invoice number — shown only once PRA returns it (real "production" number) --}}
                <div x-show="lastPraNumber" class="relative mt-3 mx-auto max-w-xs py-2 px-3 rounded-xl bg-emerald-600/10 border border-emerald-500/30">
                    <p class="text-[9px] font-bold uppercase tracking-widest text-emerald-700/70 dark:text-emerald-400/70">{{ __('pos.pra_invoice_number') }}</p>
                    <div class="flex items-center justify-center gap-2 mt-0.5">
                        <p class="text-sm font-extrabold font-mono text-emerald-800 dark:text-emerald-300 break-all" x-text="lastPraNumber"></p>
                        <button type="button"
                            @click="if(navigator.clipboard){navigator.clipboard.writeText(lastPraNumber).then(()=>{ praCopied=true; showToast(window.TXT.pra_number_copied,'success'); setTimeout(()=>praCopied=false,1500); }).catch(()=>showToast(window.TXT.copy_failed,'error'));}else{showToast(window.TXT.copy_not_supported,'error');}"
                            class="shrink-0 w-7 h-7 rounded-lg bg-emerald-600/15 hover:bg-emerald-600/30 text-emerald-700 dark:text-emerald-300 flex items-center justify-center transition" :title="praCopied ? window.TXT.ti_copied : window.TXT.ti_copy_pra">
                            <svg x-show="!praCopied" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            <svg x-show="praCopied" x-cloak class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
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
                {{-- Waiter name (Task 881): shown only when the settled bill was a waiter order --}}
                <div x-show="lastWaiterName" x-cloak class="relative flex items-center justify-center gap-1.5 mt-2">
                    <svg class="w-3.5 h-3.5 text-teal-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    <span class="text-[11px] font-semibold text-teal-700 dark:text-teal-400" x-text="lastWaiterName"></span>
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
                <iframe x-show="!lastIsOffline" x-ref="receiptIframe" class="w-full h-full border-0" :src="lastTransactionId ? (isRestaurantMode ? '/pos/restaurant/receipt/' + lastTransactionId : '/pos/transaction/' + lastTransactionId + '/receipt') : ''" style="min-height:300px;"></iframe>
                {{-- OFFLINE bill (Jul 2026): no server receipt exists yet — render a client-side summary. --}}
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
                {{-- Task 1036: WhatsApp Bill (owner voice note 17 Aug 2026) — sirf jab bill par
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
                    <button @click="lastIsOffline ? printOfflineReceipt() : printReceipt()" :disabled="!lastTransactionId && !lastIsOffline" class="py-3 text-center rounded-xl bg-purple-600 hover:bg-purple-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-bold transition shadow-sm flex items-center justify-center gap-1.5" title="{{ __('pos.ti_print_receipt') }}">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                        {{ __('pos.print') }} <kbd class="text-[8px] bg-purple-500/40 px-1 rounded font-mono">P</kbd>
                    </button>
                    {{-- 2. KOT (K) - shown only when an orderId exists (restaurant flow) --}}
                    {{-- Task 1379: this popup is ALSO the "Payment First, Then KOT"
                         release button — for a bill whose kitchen ticket has not gone
                         out yet (lastKotPending) it is a FIRST send, never a reprint,
                         so a blocked cashier must keep it. Once the ticket has been
                         fired, pressing it again is a reprint and it follows
                         canKotReprint, matching the server's own decision. --}}
                    <button x-show="(lastOrderId || lastTxnKotId) && (canKotReprint || lastKotPending)" @click="lastOrderId ? printKitchenTicket() : printTxnKitchenTicket(lastTxnKotId)" :disabled="!lastOrderId && !lastTxnKotId" class="py-3 text-center rounded-xl bg-gradient-to-br from-orange-500 to-amber-600 hover:from-orange-600 hover:to-amber-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-bold transition shadow-sm flex items-center justify-center gap-1.5" title="{{ __('pos.ti_print_kot') }}">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                        KOT <kbd class="text-[8px] bg-orange-500/40 px-1 rounded font-mono">K</kbd>
                    </button>
                    {{-- Spacer when KOT hidden so grid stays balanced --}}
                    <div x-show="!((lastOrderId || lastTxnKotId) && (canKotReprint || lastKotPending))"></div>
                    {{-- 3. New Sale (Enter) --}}
                    <button @click="startNewAfterPayment()" class="py-3 text-center rounded-xl bg-gradient-to-br from-green-600 to-emerald-700 hover:from-green-700 hover:to-emerald-800 text-white text-sm font-bold transition shadow-sm flex items-center justify-center gap-1.5" title="{{ __('pos.ti_clear_cart_new_sale') }}">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                        {{ __('pos.new_word') }} <kbd class="text-[8px] bg-green-500/40 px-1 rounded font-mono">↵</kbd>
                    </button>
                    {{-- 4. Close popup (mouse only - Esc no longer bound to keep print dialog Esc clean) --}}
                    <button @click="closeReceiptPopup()" class="py-3 text-center rounded-xl bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-600 dark:text-gray-300 text-sm font-semibold transition flex items-center justify-center gap-1.5" title="{{ __('pos.ti_close_popup_no_new_sale') }}">
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
                    <div class="text-center py-8"><div class="w-6 h-6 border-2 border-purple-600 border-t-transparent rounded-full animate-spin mx-auto"></div><p class="text-xs text-gray-400 mt-2">{{ __('pos.loading_dots') }}</p></div>
                </template>
                <template x-if="customerHistory && !loadingCustomerHistory">
                    <div>
                        <div class="flex items-center gap-3 p-3 bg-purple-50 dark:bg-purple-900/20 rounded-xl mb-4">
                            <div class="w-10 h-10 rounded-full bg-purple-200 dark:bg-purple-800 flex items-center justify-center"><span class="text-sm font-bold text-purple-700 dark:text-purple-300" x-text="(customerHistory.customer_name || 'C').charAt(0)"></span></div>
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
                                                {{-- Asal POS bill number (L-xx) jab order bill bana tha; warna ORD- token (owner, 3 Aug 2026) --}}
                                                <span class="text-xs font-bold text-gray-900 dark:text-white" x-text="ord.invoice_number || ord.order_number"></span>
                                                <span class="text-[10px] text-gray-400" x-text="ord.date"></span>
                                            </div>
                                            <div class="text-[10px] text-gray-500 mb-2" x-text="ord.items.map(i => i.qty + 'x ' + i.name).join(', ')"></div>
                                            <div class="flex items-center justify-between">
                                                <span class="text-xs font-bold text-purple-600" x-text="'Rs. ' + Number(ord.total).toLocaleString()"></span>
                                                <div class="flex items-center gap-1.5">
                                                    <template x-if="ord.txn_id">
                                                        <button @click="window.open(receiptViewUrl({ id: ord.txn_id }), '_blank')" class="text-[10px] font-bold text-purple-700 dark:text-purple-300 bg-purple-100 dark:bg-purple-900/30 hover:bg-purple-200 dark:hover:bg-purple-900/50 px-2.5 py-1 rounded-lg transition">{{ __('pos.view_receipt_btn') }}</button>
                                                    </template>
                                                    <button @click="reorderItems(ord)" class="text-[10px] font-bold text-white bg-purple-600 hover:bg-purple-700 px-2.5 py-1 rounded-lg transition">{{ __('pos.reorder') }}</button>
                                                </div>
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

    {{-- Smart Upsell card REMOVED (customer feedback, 25 Jul 2026) — cashiers found
         the "Suggested Add-on" popup irritating mid-punching; it also hijacked
         Enter/Esc. Whole client-side upsell system deleted. Do NOT re-add without
         the owner's explicit go-ahead. --}}

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

    {{-- ── Caller ID popup (Task 1039) — non-blocking incoming-call card.
         Sits below the toast slot; z-[60] like the toast (payment/receipt
         modals never coexist — callerBlocked() defers rings while they're up).
         No NEW arbitrary Tailwind classes (vite-arbitrary-classes.md):
         width via inline style, z-[60]/text-[10px]/text-[11px] already built. --}}
    <div x-show="callerPopup" x-cloak x-transition.opacity class="fixed top-20 right-4 z-[60]" style="width:330px; max-width:92vw;">
        <div class="rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden" style="box-shadow: 0 20px 60px -15px rgba(0,0,0,0.35);">
            <div class="flex items-center gap-2 px-4 py-2.5 text-white" :class="callerPopup && callerPopup.source === 'whatsapp' ? 'bg-green-600' : 'bg-sky-600'">
                <svg class="w-4 h-4 animate-pulse flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                <span class="text-xs font-extrabold" x-text="callerPopup && callerPopup.source === 'whatsapp' ? window.TXT.caller_whatsapp_call : window.TXT.caller_incoming_call"></span>
                <span class="ml-auto text-[10px] opacity-80 flex-shrink-0" x-text="callerPopup ? (callerPopup.at || '') : ''"></span>
                <button type="button" @click="dismissCallerPopup()" class="flex-shrink-0 w-6 h-6 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center transition" title="{{ __('pos.close') }}">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-4">
                <p class="text-base font-extrabold text-gray-900 dark:text-white truncate" x-text="callerPopup ? (((callerPopup.match && callerPopup.match.name) || callerPopup.name || callerPopup.phone) || window.TXT.caller_unknown) : ''"></p>
                <p class="text-xs text-gray-500 dark:text-gray-400" x-show="callerPopup && callerPopup.phone && ((callerPopup.match && callerPopup.match.name) || callerPopup.name)" x-text="callerPopup ? (callerPopup.phone || '') : ''"></p>
                <template x-if="callerPopup && callerPopup.match">
                    <div class="mt-2 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-100 dark:border-emerald-800 px-3 py-2">
                        <p class="text-[10px] font-bold text-amber-600 dark:text-amber-400 mb-1" x-show="callerPopup.match.matched_by === 'name'">{{ __('pos.caller_matched_by_name') }}</p>
                        <div class="flex items-center gap-3 text-[11px] font-bold text-emerald-800 dark:text-emerald-300">
                            <span><span x-text="callerPopup.match.visits"></span> {{ __('pos.caller_visits') }}</span>
                            <span>Rs <span x-text="(callerPopup.match.total_spent || 0).toLocaleString()"></span></span>
                        </div>
                        <p class="text-[10px] text-emerald-700 dark:text-emerald-400 mt-0.5" x-show="callerPopup.match.last_order_at">
                            {{ __('pos.caller_last_order') }}: <span x-text="callerPopup.match.last_order_at"></span>
                            <template x-if="callerPopup.match.last_order_amount"><span> · Rs <span x-text="(callerPopup.match.last_order_amount || 0).toLocaleString()"></span></span></template>
                        </p>
                        {{-- v2 (Task 1101): udhaar line — amber, only when balance > 0 --}}
                        <p class="text-[11px] font-extrabold text-amber-600 dark:text-amber-400 mt-1" x-show="(parseInt(callerPopup.match.khata_balance, 10) || 0) > 0">
                            {{ __('pos.caller_khata_due') }}: Rs <span x-text="(parseInt(callerPopup.match.khata_balance, 10) || 0).toLocaleString()"></span>
                        </p>
                    </div>
                </template>
                <p x-show="callerPopup && !callerPopup.match" class="mt-1 text-[11px] text-gray-400">{{ __('pos.caller_new_customer') }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" @click="callerStartBill()" class="flex-1 px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-extrabold transition">{{ __('pos.caller_make_bill') }}</button>
                    {{-- Task 1381: "Call back" — counter ke paired phone par tap-to-dial
                         request. Customer bill par attach bhi ho jata hai (Bill button
                         jaisa) aur popup band ho jata hai, taake order likhna foran shuru. --}}
                    <button type="button" x-show="callerPopup && callerNumberOf(callerPopup)" @click="callerDialBack(callerPopup, { attach: true, closePopup: true })" :disabled="callerDialBusy"
                            class="flex items-center gap-1 px-3 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white text-xs font-extrabold transition" title="{{ __('pos.ti_call_back') }}">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        <span>{{ __('pos.caller_call_back') }}</span>
                    </button>
                    {{-- v2: repeat last order (matched saved customer only) --}}
                    <button type="button" x-show="callerPopup && callerPopup.match && callerPopup.match.customer_id" @click="callerRepeatOrder(callerPopup)" class="px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-white text-xs font-extrabold transition">{{ __('pos.caller_repeat_order') }}</button>
                    {{-- v2: unknown caller → quick-save with phone/name prefilled --}}
                    <button type="button" x-show="callerPopup && !callerPopup.match && callerPopup.phone" @click="callerSaveCustomer(callerPopup)" class="px-3 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-xs font-extrabold transition">{{ __('pos.caller_save_customer') }}</button>
                    <button type="button" @click="dismissCallerPopup()" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 text-xs font-bold hover:bg-gray-50 dark:hover:bg-gray-800 transition">{{ __('pos.close') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Caller ID v2 (Task 1101): call-log button + queued-rings badge.
         Small fixed pill under the popup slot; only rendered when the feature
         is baked ON. Unseen count = client-side localStorage cursor. --}}
    <template x-if="callerIdOn">
        <div class="fixed top-20 right-4 z-50 flex flex-col items-end gap-1.5" x-show="!callerPopup" x-cloak>
            <button type="button" @click="openCallerLog()" class="relative w-9 h-9 rounded-full bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-md flex items-center justify-center text-sky-600 dark:text-sky-400 hover:bg-sky-50 dark:hover:bg-gray-800 transition" title="{{ __('pos.caller_log_title') }}">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                <span x-show="callerUnseen > 0" x-cloak class="absolute -top-1 -right-1 min-w-[16px] h-[16px] px-1 bg-red-600 text-white text-[9px] rounded-full flex items-center justify-center font-bold" x-text="callerUnseen > 9 ? '9+' : callerUnseen"></span>
            </button>
            <span x-show="callerQueue.length > 0" x-cloak class="px-2 py-0.5 rounded-full bg-sky-600 text-white text-[10px] font-bold shadow animate-pulse" x-text="callerQueue.length + ' ' + window.TXT.caller_queued_calls"></span>
        </div>
    </template>

    {{-- ── Caller ID v2: recent-calls panel (last 24h) --}}
    <div x-show="showCallerLog" x-cloak x-transition.opacity class="fixed top-20 right-4 z-[60]" style="width:340px; max-width:92vw;">
        <div class="rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden" style="box-shadow: 0 20px 60px -15px rgba(0,0,0,0.35);">
            <div class="flex items-center gap-2 px-4 py-2.5 bg-sky-600 text-white">
                <span class="text-xs font-extrabold">{{ __('pos.caller_log_title') }}</span>
                {{-- Task 1380: test + purani calls 24 ghante list mein padi rehti thin.
                     "Sab hatayen" poori list ko cleared mark karta hai (shop-wide). --}}
                <button type="button" x-show="callerLog.length > 0" x-cloak @click="clearAllCallerEvents()" class="ml-auto flex-shrink-0 px-2 py-0.5 rounded-full bg-white/20 hover:bg-white/30 text-[10px] font-bold transition" title="{{ __('pos.caller_clear_all') }}">{{ __('pos.caller_clear_all') }}</button>
                <button type="button" @click="showCallerLog = false" class="ml-auto flex-shrink-0 w-6 h-6 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center transition" title="{{ __('pos.close') }}">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="max-h-96 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800">
                <p x-show="callerLogLoading" class="p-4 text-xs text-gray-400 text-center">…</p>
                <p x-show="!callerLogLoading && callerLog.length === 0" class="p-4 text-xs text-gray-400 text-center">{{ __('pos.caller_log_empty') }}</p>
                <template x-for="ev in callerLog" :key="ev.id">
                    <div class="flex items-center gap-2 px-3 py-2">
                        <span class="w-2 h-2 rounded-full flex-shrink-0" :class="ev.source === 'whatsapp' ? 'bg-green-500' : 'bg-sky-500'"></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-bold text-gray-900 dark:text-white truncate" x-text="((ev.match && ev.match.name) || ev.name || ev.phone) || window.TXT.caller_unknown"></p>
                            <p class="text-[10px] text-gray-500 dark:text-gray-400 truncate">
                                <span x-text="ev.at"></span><template x-if="ev.phone && ((ev.match && ev.match.name) || ev.name)"><span> · <span x-text="ev.phone"></span></span></template><template x-if="ev.match && (parseInt(ev.match.khata_balance, 10) || 0) > 0"><span class="font-bold text-amber-600 dark:text-amber-400"> · Rs <span x-text="(parseInt(ev.match.khata_balance, 10) || 0).toLocaleString()"></span></span></template>
                            </p>
                            {{-- Task 1381: handled ka nishan — kaun si missed call abhi
                                 baqi hai, ek nazar mein. --}}
                            <p x-show="ev.called_back" x-cloak class="text-[10px] font-bold text-emerald-600 dark:text-emerald-400 truncate">
                                ✔ <span x-text="window.TXT.caller_called_back"></span><template x-if="ev.called_back_at"><span> · <span x-text="ev.called_back_at"></span></span></template>
                            </p>
                        </div>
                        {{-- Task 1381: is missed call ka jawab — counter ke phone par
                             tap-to-dial request. Customer bill par attach bhi ho jata hai;
                             list khuli rehti hai taake "call back kiya" ka nishan dikh jaye. --}}
                        <button type="button" x-show="callerNumberOf(ev)" @click="callerDialBack(ev, { attach: true })" :disabled="callerDialBusy" title="{{ __('pos.ti_call_back') }}" aria-label="{{ __('pos.ti_call_back') }}"
                                class="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-300 hover:bg-indigo-600 hover:text-white disabled:opacity-60 transition">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        </button>
                        <button type="button" @click="callerBillFrom(ev); showCallerLog = false" class="flex-shrink-0 px-2.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-[10px] font-extrabold transition">{{ __('pos.caller_make_bill_short') }}</button>
                        {{-- Task 1380: handle ho chuki call ko list se hatao (server par
                             cleared mark — refresh aur doosre counter par bhi saaf). --}}
                        <button type="button" @click="clearCallerEvent(ev)" title="{{ __('pos.caller_clear_call') }}" aria-label="{{ __('pos.caller_clear_call') }}" class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-gray-400 hover:text-white hover:bg-red-500 transition">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- ── Task 1381: call-back ka dead-end tornay wala card.
         Jab koi phone jura hua na ho (app purani ho, ya us par notification
         band ho) to number bara kar ke copy button ke saath dikhta hai —
         cashier phir bhi 2 second mein dial kar leta hai. Sirf buttons, koi
         input nahi: Enter/guided flow bilkul nahi rukta
         (pos-guided-keyboard-flow).
         Wajah ka text server ke `reason` se banta hai, is liye teenon keys
         zabardasti bake karwani hain: --}}
    {{-- @posI18nExtra: caller_dial_no_device caller_dial_old_app caller_dial_notif_off --}}
    <div x-show="callerDialFallback" x-cloak x-transition.opacity class="fixed top-20 right-4 z-[60]" style="width:330px; max-width:92vw;">
        <div class="rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 overflow-hidden" style="box-shadow: 0 20px 60px -15px rgba(0,0,0,0.35);">
            <div class="flex items-center gap-2 px-4 py-2.5 bg-amber-500 text-white">
                <span class="text-xs font-extrabold">{{ __('pos.caller_dial_title') }}</span>
                <button type="button" @click="callerDialFallback = null" class="ml-auto flex-shrink-0 w-6 h-6 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center transition" title="{{ __('pos.close') }}">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-4">
                <p class="text-[11px] text-gray-500 dark:text-gray-400" x-text="callerDialFallback ? (window.TXT['caller_dial_' + callerDialFallback.reason] || window.TXT.caller_dial_no_device) : ''"></p>
                <p class="mt-2 text-2xl font-extrabold tracking-wide text-gray-900 dark:text-white select-all" x-text="callerDialFallback ? callerDialFallback.phone : ''"></p>
                <p class="text-[11px] text-gray-400 truncate" x-show="callerDialFallback && callerDialFallback.name" x-text="callerDialFallback ? (callerDialFallback.name || '') : ''"></p>
                <div class="mt-3 flex gap-2">
                    <button type="button" @click="copyCallerNumber()" class="flex-1 px-3 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-extrabold transition">{{ __('pos.caller_dial_copy') }}</button>
                    <button type="button" @click="callerDialFallback = null" class="px-3 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 text-xs font-bold hover:bg-gray-50 dark:hover:bg-gray-800 transition">{{ __('pos.close') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div x-show="toast.show" class="fixed top-4 right-4 z-[60] max-w-sm" :class="toast.show ? 'toast-enter' : 'toast-exit'">
        {{-- ZFC issue #12 (28 Jul 2026): NEW 'info' toast type — blue, i-icon.
             Info messages (e.g. waiter order loaded) looked like RED errors. --}}
        <div class="flex items-center gap-3 px-4 py-3 rounded-2xl shadow-2xl backdrop-blur-xl border" :class="toast.type === 'success' ? 'bg-green-600/95 text-white border-green-500/30' : (toast.type === 'info' ? 'bg-blue-600/95 text-white border-blue-500/30' : 'bg-red-600/95 text-white border-red-500/30')" style="box-shadow: 0 20px 60px -15px rgba(0,0,0,0.3);">
            <div class="flex-shrink-0 w-7 h-7 rounded-full flex items-center justify-center bg-white/20">
                <svg x-show="toast.type === 'success'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                <svg x-show="toast.type === 'info'" x-cloak class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <svg x-show="toast.type !== 'success' && toast.type !== 'info'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
            </div>
            <span class="text-sm font-semibold" x-text="toast.message"></span>
        </div>
    </div>
</div>

@php
$productsJson = $products->map(function($p) use ($recipeLookup, $stockStatus) {
    return [
        'id' => $p->id, 'type' => 'product', 'name' => $p->name,
        'price' => $p->price ?? 0, 'category' => $p->category,
        'barcode' => $p->barcode ?: null, 'sku' => $p->sku ?: null,
        'show_on_sale' => (bool)($p->show_on_sale ?? true),
        'cost_price' => (float) ($p->cost_price ?? 0),
        'is_tax_exempt' => $p->is_tax_exempt ?? false,
            'is_third_schedule' => $p->is_third_schedule ?? false,
        'hasRecipe' => in_array($p->id, $recipeLookup ?? []),
        'image' => $p->image ? asset('storage/products/' . $p->image) : null,
        'stockStatus' => $stockStatus[$p->id] ?? null,
    ];
})->values();
$servicesJson = $services->map(function($s) {
    return [
        'id' => $s->id, 'type' => 'service', 'name' => $s->name,
        'price' => $s->price ?? 0, 'category' => 'Services',
        'is_tax_exempt' => $s->is_tax_exempt ?? false,
            'is_third_schedule' => false,
        'hasRecipe' => false, 'image' => null, 'stockStatus' => null,
    ];
})->values();
$selectedTableJson = $selectedTable ? ['id' => $selectedTable->id, 'table_number' => $selectedTable->table_number, 'seats' => $selectedTable->seats] : null;
$customersJson = $customers->map(fn($c) => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone])->values();
// Task 644 (payload trim, Aug 2026): held orders were baked as FULL Eloquent
// rows (every column + full table row + full item rows) — dead weight on every
// sale-screen load. Project to exactly what the JS reads (held-list template,
// recallOrder, heldOrderEstimate, payModalOrderType, payHeldOrderDirect,
// autoRecallFromUrl). NOTE: holdOrder's POST response unshifts a FULL row into
// this list at runtime — that shape is a superset, so both coexist. If new JS
// starts reading another held-order field, add it HERE too.
$heldOrdersJson = $heldOrders->map(function ($o) {
    return [
        'id' => $o->id,
        'order_number' => $o->order_number,
        'token_no' => $o->token_no ?? null,
        'status' => $o->status,
        'source' => $o->source,
        'order_type' => $o->order_type,
        'priority' => (bool) ($o->priority ?? false),
        'customer_id' => $o->customer_id,
        'customer_name' => $o->customer_name,
        'customer_phone' => $o->customer_phone,
        'kitchen_notes' => $o->kitchen_notes,
        'delivery_address' => $o->delivery_address ?? null,
        'discount_type' => $o->discount_type,
        'discount_value' => (float) ($o->discount_value ?? 0),
        'discount_amount' => (float) ($o->discount_amount ?? 0),
        'total_amount' => (float) ($o->total_amount ?? 0),
        'kot_sent_at' => $o->kot_sent_at ? $o->kot_sent_at->toJSON() : null,
        'table' => $o->table ? [
            'id' => $o->table->id,
            'table_number' => $o->table->table_number,
            'occupied_since' => optional($o->table->occupied_since)->toJSON(),
        ] : null,
        'items' => $o->items->map(fn ($i) => [
            'id' => $i->id,
            'item_id' => $i->item_id,
            'item_type' => $i->item_type,
            'item_name' => $i->item_name,
            'quantity' => (float) $i->quantity,
            'unit_price' => (float) $i->unit_price,
            'subtotal' => (float) $i->subtotal,
            'special_notes' => $i->special_notes,
            'is_tax_exempt' => (bool) ($i->is_tax_exempt ?? false),
            'item_discount_type' => $i->item_discount_type,
            'item_discount_value' => (float) ($i->item_discount_value ?? 0),
        ])->values(),
    ];
})->values();
$kitchenSettings = [
    'kds_enabled' => (bool)($company->kds_enabled ?? true),
    // KDS Auto-Print (owner, Jul 2026): when the KDS station itself prints
    // tickets, cashier-side AUTO KOT fires are duplicates and get suppressed.
    'kds_auto_print' => (bool)($company->pos_kds_auto_print ?? false),
    'printer_enabled' => (bool)($company->kitchen_printer_enabled ?? false),
    'print_on_hold' => (bool)($company->print_on_hold ?? false),
    'print_on_pay' => (bool)($company->print_on_pay ?? true),
    'dine_in_auto_kot' => (bool)($company->dine_in_auto_kot ?? false),
    // Delivery: payment pehle, KOT baad (1 Aug 2026) — provisional delivery
    // bills par KOT promote tak ruki rehti hai.
    'delivery_kot_after_payment' => (bool)($company->delivery_kot_after_payment ?? false),
    // Task 1356 (owner video, dine-in Table 02): bill final ho aur kuch lines
    // kitchen ne kabhi dekhi hi na hon to unhi lines ki parchi khud chali jaye —
    // dine-in par bhi. DEFAULT ON; missing column bhi ON (prod self-heal).
    'kot_on_final_if_unsent' => ($company->kot_on_final_if_unsent ?? true) !== false,
    // KDS liveness (Jul 2026): baked snapshot — refreshed every 20s via the
    // incoming-orders poll's X-KDS-Alive header. KDS closed → cashier auto-KOT.
    'kds_alive' => (time() - (int)\Illuminate\Support\Facades\Cache::get('kds_seen_' . $company->id, 0)) < 90,
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
// OFFLINE-FIRST BOOT (Jul 2026): server-side fingerprint of everything baked
// into this page (user, company, screen file, catalog, settings). The SW may
// serve this page from SALE_CACHE — bootFpCheck() compares this against
// /pos/api/boot-check ~1.5s after boot and reloads once if stale.
window.tnBootFp = {!! $jsEnc($bootFp ?? null, 'null') !!};
function restaurantPos() {
    return {
        allProducts: {!! $jsEnc($productsJson) !!},
        allServices: {!! $jsEnc($servicesJson) !!},
        // PER-USER grid visibility (owner, 25 Jul 2026): {"product:12":0,"deal:3":1}.
        // User pref OVERRIDES the admin show_on_sale default in BOTH directions —
        // for THIS user's grid only. Search is NEVER filtered by these prefs.
        userGridPrefs: {!! $jsEnc((object) ($userGridPrefs ?? []), '{}') !!},
        gridEditMode: false,
        gridPrefBusy: false,
        // Effective grid visibility: explicit user pref wins; else admin default
        // (products honor show_on_sale, services/deals default visible).
        isItemVisible(i) {
            const key = (i._type || i.type || 'product') + ':' + i.id;
            if (this.userGridPrefs[key] !== undefined) return this.userGridPrefs[key] == 1;
            return !((i._type || i.type) === 'product' && i.show_on_sale === false);
        },
        async toggleItemVisibility(i) {
            const type = i._type || i.type || 'product';
            const key = type + ':' + i.id;
            const newVisible = !this.isItemVisible(i);
            const prev = this.userGridPrefs[key];
            this.userGridPrefs[key] = newVisible ? 1 : 0; // optimistic
            try {
                const res = await fetch('/pos/grid-prefs/toggle', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ item_type: type, item_id: i.id, visible: newVisible })
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                this.filterProducts();
                this.syncAutoWidecart();
            } catch (e) {
                if (prev === undefined) delete this.userGridPrefs[key]; else this.userGridPrefs[key] = prev;
                this.showToast(window.TXT.save_failed_try_again, 'error');
            }
        },
        // Owner request (1 Aug 2026): jab user saare grid items hide kar de to
        // split (widecart) layout KHUD on ho jaye — alag toggle dhoondhne ki
        // zaroorat na pade. Auto-off is tracked so unhiding items restores the
        // grid automatically; a MANUAL toggle press always wins (clears flag).
        visibleGridCount() {
            try {
                return this.allProducts.filter(p => this.isItemVisible(p)).length
                    + this.allServices.filter(s => this.isItemVisible(s)).length
                    + this.allDeals.filter(d => this.isItemVisible(d)).length;
            } catch (e) { return 1; /* never auto-hide on error */ }
        },
        syncAutoWidecart() {
            if (this.gridEditMode) return; // editing needs the grid visible
            const count = this.visibleGridCount();
            let autoFlag = false;
            try { autoFlag = localStorage.getItem('pos_show_products_auto') === '1'; } catch (e) {}
            if (count === 0 && this.showProducts) {
                this.showProducts = false;
                if (this.activeCategory !== 'all') this.activeCategory = 'all'; // match manual-toggle behavior
                this.filterProducts();
                try {
                    localStorage.setItem('pos_show_products', '0');
                    localStorage.setItem('pos_show_products_auto', '1');
                } catch (e) {}
            } else if (count > 0 && !this.showProducts && autoFlag) {
                this.showProducts = true;
                this.filterProducts();
                try {
                    localStorage.setItem('pos_show_products', '1');
                    localStorage.removeItem('pos_show_products_auto');
                } catch (e) {}
            }
        },
        async resetGridPrefs() {
            if (this.gridPrefBusy) return;
            this.gridPrefBusy = true;
            try {
                const res = await fetch('/pos/grid-prefs/reset', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
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
        // Deals (Jul 2026): server-filtered to TODAY's live deals only (weekday +
        // date-range checked in universalCreateInvoice) — never cached client-side,
        // so an off-day deal can never linger past midnight via localStorage.
        allDeals: {!! $jsEnc(collect($dealsForJs ?? [])->values()) !!},
        // EDIT PROVISIONAL IN SALE SCREEN (Jul 2026): ?edit_bill={id} → server ships
        // the bill here; init() loads it into the cart. Update goes through
        // updateTransaction (JSON) — bill stays provisional, keeps its L-serial.
        editingBillData: {!! $jsEnc($editBillForJs ?? null, 'null') !!},
        editingBillId: null,
        editingBillNumber: '',
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
        // Deals are billing-only like manual items: the restaurant hold endpoint
        // validates item_type in:product,service,manual → a deal line would 422.
        // Gate Hold/Send-to-Kitchen AND route Pay through processPaymentManual.
        hasDealItems() { return (this.cart || []).some(i => i && i.item_type === 'deal'); },
        blockOutOfStock: {{ $blockOutOfStock ? 'true' : 'false' }},
        taxRate: {{ (float) ($taxRate ?? 0) }},
        taxRules: {!! $jsEnc($taxRules->mapWithKeys(fn($r) => [$r->payment_method => (float) $r->tax_rate]), '{}') !!},
        // Tax-Inclusive Pricing (Menu-Rate-Final, owner Jul 2026): when true, menu
        // price IS the grand total — tax shown is the INCLUDED portion, total never
        // adds tax on top. Mirrors PosTaxMath backend math.
        taxInclusive: {{ ($company->pos_tax_inclusive ?? false) ? 'true' : 'false' }},
        // Product search mode (owner, 4 Aug 2026): 'any_word' matches the start of
        // ANY word in the name right away ("win" → "5 Piece Hot Wings"); default
        // 'prefix' keeps the strict 24 Jul rule (+ zero-result word rescue).
        // Column is in posConfigRev(), so flipping it refreshes cached screens.
        searchAnyWord: {{ (($company->pos_product_search_mode ?? 'prefix') === 'any_word') ? 'true' : 'false' }},
        // Card-save mode (inclusive_card_save, Jul 2026): menu price is inclusive at
        // the CASH rate; card/digital bills = same base + their OWN (lower) rate, so
        // the customer saves on card. taxMenuRate = cash rate when mode 3, else null.
        taxMenuRate: {{ $company->posTaxPricingMode() === 'inclusive_card_save' ? (float) \App\Models\PosTaxRule::getRateForMethod('cash', $company) : 'null' }},
        cardSaveMode() { return this.taxInclusive && this.taxMenuRate !== null && this.taxMenuRate > 0; },
        // Total for a given rate under card-save: base derived at the MENU (cash)
        // rate, chosen method's rate applied on top. Exempt items pass through.
        cardSaveTotalForRate(rate) {
            const after = Math.max(0, this.r2(this.effectiveSubtotal - this.discountAmount));
            if (Math.abs(this.taxMenuRate - rate) < 0.005) return Math.round(after);
            const tia = this.taxableSubtotal;
            const exemptShare = Math.max(0, this.r2(after - tia));
            return Math.round(exemptShare + tia * (100 + rate) / (100 + this.taxMenuRate));
        },
        get modalCardSaving() {
            if (!this.cardSaveMode()) return 0;
            const cardRate = this.taxRules['debit_card'] || this.taxRules['card'] || 8;
            const cashT = this.payingHeldOrderId ? this.heldOrderEstimate('cash') : this.cardSaveTotalForRate(this.taxMenuRate);
            const cardT = this.payingHeldOrderId ? this.heldOrderEstimate('card') : this.cardSaveTotalForRate(cardRate);
            return Math.max(0, cashT - cardT);
        },
        posRole: '{{ $posRole }}',
        posUserId: {{ (int) auth('pos')->id() }},
        discountLimit: {{ (float) ($discountLimit ?? 0) }},
        hasManagerPin: {{ $hasManagerPin ? 'true' : 'false' }},
        managerOverrideActive: false,
        showManagerPinModal: false,
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
        customerPhoneQuery: '',
        customerPhoneResults: [],
        customerPhoneDropdown: false,
        custHiIndex: 0,
        customerPhoneTimer: null,
        showNewCustomerModal: false, // legacy, kept for backward compat — not used
        showNewCustomerInline: false,
        savingCustomer: false,
        customerSearching: false,
        newCustomerPhone: '',
        newCustomerName: '',
        newCustomerAddress: '',
        highlightIndex: 0,
        // Order Sound (Aug 2026): device-level chime pref for incoming waiter orders.
        orderSound: (function () { try { return localStorage.getItem('pos_order_sound') !== '0'; } catch (e) { return true; } })(),
        // ── Task 1349: COUNTER (terminal) — DEVICE-level choice ─────────────
        // Baked ACTIVE counters (id/name/code); the picked one is remembered on
        // THIS machine (localStorage) and rides on EVERY sale — normal bill,
        // restaurant pay-order and offline-queued bills (it sits inside the
        // payload object, so the queue carries it automatically). Nothing picked
        // = counter-less billing exactly as before (never blocks a cashier).
        // The list is in posBootFingerprint(), so a new counter refreshes cached
        // (offline-first) copies of this screen.
        allTerminals: {!! $jsEnc($terminalsForJs ?? [], '[]') !!},
        terminalId: null,
        showTerminalPicker: false,
        terminalName() {
            const t = (this.allTerminals || []).find(x => x.id === this.terminalId);
            return t ? t.name : '';
        },
        loadTerminalPref() {
            let saved = null;
            try { saved = parseInt(localStorage.getItem('pos_terminal_id') || '0', 10) || null; } catch (e) {}
            // Self-heal: a counter deleted/deactivated since this device picked
            // it must not keep riding on bills (the server drops it anyway) —
            // clear the stale pref so the chip asks for a fresh pick.
            if (saved && !(this.allTerminals || []).some(x => x.id === saved)) {
                saved = null;
                try { localStorage.removeItem('pos_terminal_id'); } catch (e) {}
            }
            this.terminalId = saved;
        },
        setTerminal(id) {
            const next = parseInt(id, 10) || null;
            this.terminalId = (next && (this.allTerminals || []).some(x => x.id === next)) ? next : null;
            try {
                if (this.terminalId) localStorage.setItem('pos_terminal_id', String(this.terminalId));
                else localStorage.removeItem('pos_terminal_id');
            } catch (e) {}
            this.showTerminalPicker = false;
            window.tnNotify && window.tnNotify(window.TXT.counter_word, this.terminalId ? this.terminalName() : window.TXT.counter_not_set);
        },
        // ── Caller ID (Task 1039): incoming-call popup ──────────────────────
        // Baked flag — caller_id_enabled sits in the posConfigRev whitelist so
        // a settings toggle refreshes offline-cached sale screens.
        callerIdOn: {{ ($company->caller_id_enabled ?? false) ? 'true' : 'false' }},
        callerPopup: null,   // the ring event currently on screen
        callerQueue: [],     // rings deferred while a blocking modal is open
        callerLastId: 0,     // server cursor (max event id already fetched)
        // ── v2 (Task 1101) ──
        showCallerLog: false,     // recent-calls (last 24h) panel
        callerLog: [],
        callerLogLoading: false,
        callerUnseen: 0,          // badge count; cursor in localStorage
        callerSeenId: 0,          // max event id the cashier has SEEN (log open)
        _callerBeeped: [],        // event ids already beeped (no re-fire)
        _callerWarnedOffline: false,
        // ── Task 1381 (call back) ──
        callerDialBusy: false,    // double-tap guard on every Call back button
        callerDialFallback: null, // {phone, dial, name, reason} — koi phone nahi mila
        activeCartIndex: -1,
        cartMode: false,
        get mode() { return this.cartMode ? 'cart' : 'search'; },
        activeHeldIndex: 0,
        gridFocusMode: false,
        gridFocusIndex: 0,
        gridCols: 4,
        orderType: '{{ $selectedTable ? "dine_in" : "takeaway" }}',
        // Order-type flow rules (owner, Jul 2026) — apply ONLY when the order-type
        // widget is visible (restaurant-ish company; same condition as the header switcher).
        // Delivery = final + provisional; Dine-In = Hold/KOT/recall procedure only
        // (no provisional); Takeaway = direct final bill only (no hold, no provisional).
        // Plain retail (widget hidden, orderType silently 'takeaway') stays ungated.
        typeFlowGate: {{ (($features->tables ?? false) || ($features->kot ?? false) || ($features->kitchen ?? false) || ($features->delivery ?? false)) ? 'true' : 'false' }},
        deliveryChargeInput: '',
        // Task 287: customer already paid online — one-click prepaid toggle for
        // delivery orders. When true, processPayment() overrides ANY method to
        // qr_payment so the bill never lands in the rider's cash khata and the
        // rider app shows the is_prepaid chip. Resets on type-change + cart clear.
        deliveryPrepaid: false,
        customerAddresses: [],
        selectedDeliveryAddress: '',
        showAddrNew: false,
        newAddrText: '',
        newAddrLabel: '',
        cart: [],
        kitchenNotes: '',
        selectedTable: {!! $jsEnc($selectedTableJson, 'null') !!},
        heldOrders: {!! $jsEnc($heldOrdersJson) !!},
        _heldEtag: null,     // Task 1097: ETag from last held-orders poll (If-None-Match fast-path)
        _incomingEtag: null, // Task 1097: ETag from last incoming-orders poll
        _tableEtag: null,    // Task 1109: ETag from last table-status poll (If-None-Match fast-path)
        // Task 502: Tables page open-order card → boot par isi order ka direct recall.
        bootRecallOrderId: {!! $jsEnc($recallOrderIdForJs ?? null, 'null') !!},
        showTablePicker: false,
        tablePickerIndex: 0,
        // ZFC (Aug 2026): unsent-cart switch prompt — { kind:'table', table } |
        // { kind:'type', type }. Opens when a table is ALREADY selected and the
        // cashier moves to a different table / Takeaway / Delivery with unsent
        // items: explicit move/discard choice, never a silent carry-over.
        tableSwitchPrompt: null,
        tableSwitchIndex: 0, // 0 = take items along, 1 = remove items
        // Dine-In Select-Table picker — live floors/tables (fetched on open).
        tableFloors: [],
        tablesLoading: false,
        // Occupied-timer tick — bumped every 30s so elapsed labels re-render live.
        nowTick: Date.now(),
        showPayModal: false,
        // payMethodIndex — which method is highlighted in the Pay modal (0 = Cash,
        // 1 = Card). Arrow keys move it, Enter confirms the highlighted one, and
        // number keys 1/2 jump + fire directly. Reset to 0 each time the modal opens.
        payMethodIndex: 0,
        // One-tap CASH/CARD buttons (Jul 2026 redesign): the pay-modal x-effect
        // resets payMethodIndex to 0 on every open, so a preselect must ride in
        // via this flag — consumed (and nulled) by the x-effect itself.
        payPreselect: null,
        // Cash Received / Wapsi (owner request, Jul 2026): optional cashier input
        // in the Pay modal (CASH only). Wapsi = cashReceived - payModalTotal.
        // Reset on every modal open; lastCashReceived rides to the success popup.
        cashReceived: '',
        lastCashReceived: 0,
        // PROVISIONAL BILL FLOW — when true, the Pay modal saves the bill with
        // pra_status='local' (no PRA submission). Bill stays editable/deletable
        // and can be promoted to final later via the "Submit to PRA — Make Final"
        // button on transaction-show. Toggle key: P (while pay modal is open).
        saveAsProvisional: false,
        // ── PROMOTE METHOD PICKER (F10 → Make Final) ─────────────────────────
        // When promoting a provisional to a PRA final, the cashier re-picks the
        // settlement method (cash vs card carry different PRA tax rates), so the
        // bill is re-taxed + given a real POS serial server-side. 0=Cash, 1=Card.
        showPromoteMethod: false,
        // Skip receipt auto-print on promote (delivery night-batch) — per-device sticky.
        promoteNoPrint: (function(){ try { return localStorage.getItem('pos_promote_no_print') === '1'; } catch(e) { return false; } })(),
        promoteTarget: null,
        promoteMethodIndex: 0,
        promoteSubmitting: false,
        showHeldOrders: false,
        // ─── Table Board (Jul 2026): "TABLE" button below cart → board modal ───
        tableBoardEnabled: {{ ($features->tables ?? false) ? 'true' : 'false' }},
        // Task 779 — TABLES-FIRST FLOW (video note, 15 Aug 2026): ON = dine-in KOT
        // ke baad + receipt popup band hone par cashier full-screen Tables page par
        // WAPAS jata hai — chhota table-picker baar baar auto-open nahi hota.
        // Default ON (16 Aug 2026: owner ne sab companies ke liye ON kar diya —
        // migration 2026_08_28_130000; opt-out Table Setup toggle se per-company).
        tablesFirstFlow: {{ (($features->tables ?? false) && ($company->tables_first_flow ?? false)) ? 'true' : 'false' }},
        tablesReturnPending: false, // navigation armed — dobara close clicks no-op
        // Task 781 — TABLE CLICK DIRECT OPEN (video note, 15 Aug 2026): opt-in
        // per-company (Table Setup page). ON = occupied table par click karte hi
        // order SEEDHA cart mein edit mode mein khul jata hai (action popup skip)
        // aur popup ke saare actions payment panel ke table-actions block mein
        // milte hain. Default OFF = popup flow bilkul purana.
        tableClickDirectOpen: {{ (($features->tables ?? false) && ($company->table_click_direct_open ?? false)) ? 'true' : 'false' }},
        // Task 781: snapshot of the recalled order's meta (kot_sent_at /
        // order_number) — recallOrder REMOVES the order from heldOrders, so the
        // in-panel KOT buttons + cancel modal need this to gate/label correctly.
        recalledOrderMeta: null,
        // Task #643 (owner 13 Aug 2026): baked Order Cancel verdict — hides board
        // "Order Cancel", bell-panel Cancel AND the claimed-cart Cancel when false.
        // Server (deleteOrder) re-enforces the SAME verdict with a 403.
        canOrderCancel: {{ !empty($canOrderCancel) ? 'true' : 'false' }},
        // Task 1379 (owner voice notes 20 Aug 2026): baked KOT-reprint verdict
        // (company switch AND the per-cashier Custom Access tick). Hides every
        // Reprint / Re-send / Last Add-on button; the kitchen-ticket, resend and
        // print-job endpoints re-enforce the SAME verdict with a 403, so an old
        // tab or a pasted URL cannot reprint either.
        canKotReprint: {{ !empty($canKotReprint) ? 'true' : 'false' }},
        tableBoardOpen: false, // board ab MODAL hai (owner 26 Jul 2026) — load par band, Alt+B / TABLE button se khulta hai
        boardMenuTable: null,   // tile clicked → action menu modal
        boardMenuItems: null,   // lazy-fetched items of the open table's order (null = loading)
        boardConfirm: null,     // { table } → Final CASH/CARD confirm modal
        boardCancelAsk: null,   // { table, order, items|null } → cancel-warning modal (ZFC, 2 Aug 2026)
        boardCancelMade: {},    // item_id → true jab cashier ne "ban gaya" tick kiya (waste audit)
        boardShift: null,       // { table, order } → Table Shift modal (26 Jul 2026)
        boardBusy: false,
        heldMenu: null,         // held (bina-table) chip → action menu modal
        // ── PRA REPORTING TOGGLE (root scope so modals/buttons can read it) ───
        // Mirrors the logged-in user's OWN reporting switch (per-cashier toggle,
        // owner rule Jul 2026). Used by Provisional/Failed bill
        // action buttons (:disabled="!praEnabled"). Was previously defined only in
        // a nested x-data on the toggle strip → caused "praEnabled is not defined"
        // Alpine crashes inside the modals which broke the whole page (incl. Pay).
        praEnabled: {{ (auth('pos')->user()?->praReportingEnabled($company) ?? false) ? 'true' : 'false' }},
        praLoading: false,
        // ── GUIDED KEYBOARD BILLING FLOW (opt-in, default OFF) ───────────────
        // Mirrors $company->pos_guided_flow_enabled. When false EVERY keyboard
        // behaviour below stays byte-identical to the original (no interception).
        // flowStep is a DISPLAY-ONLY indicator; the actual transitions piggyback
        // existing functions (addHighlightedItem, enterCartMode, showPayModal,
        // clearCart). It NEVER rewrites handleKey or changes F-key bindings.
        guidedFlow: {{ ($company->pos_guided_flow_enabled ?? true) ? 'true' : 'false' }},
        // Quick Type Mode is OPT-IN per company (default OFF — owner 22 Jul 2026:
        // customers found the button cluttering; dhaba/food shops enable it on
        // /pos/customize). Gates the toolbar button, F7 shortcut and modal entry.
        quickTypeEnabled: {{ ($company->pos_quick_type_enabled ?? false) ? 'true' : 'false' }},
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
        // Lazy-loaded list of all bills with pra_status='local' for current company.
        // Refreshed on page mount, after every bill save, and after each modal action.
        localBills: [],
        // Open FINAL delivery bills (3 Aug 2026) — Pending Deliveries popup ki
        // ginti ab rider app / khata se milti hai. F10 Local Bills modal inhe
        // KABHI nahi dikhata (woh sirf localBills parhta hai).
        finalDeliveryBills: [],
        showOldDeliveries: false, // Task 524: collapsed "Purani deliveries" group
        // Task 513: active riders + assign permission for the Pending Deliveries
        // popup's rider dropdown on UNASSIGNED bills (same pos.deliveries.assign
        // backend as the board — no new path).
        deliveryRiders: [],
        canAssignRider: false,
        riderAssignBusyId: null,
        showLocalBills: false,
        activeLocalIndex: 0,
        localBillsLoading: false,
        // Search box inside the Provisional Bills modal — owner request (1 Aug 2026):
        // night-time bulk finalizers need to find one customer's bill by name/phone.
        localSearch: '',
        // ── PENDING DELIVERIES panel (Task 114, owner "Rasta A" 2 Aug 2026) ──
        // Quick-final for TODAY's delivery provisionals: payment aate hi cashier
        // ek click mein Final (Cash/Card) — same promote path as F10 Make Final.
        // bizToday = current business day from the provisional-bills API
        // (00:00–05:59 counts in yesterday — PosBusinessDay, never client date).
        showPendingDeliveries: false,
        bizToday: '',
        deliveryFinalBusyId: null,
        riderSettleBusyId: null,
        // Task 543: styled settle-amount modal (replaces window.prompt)
        riderSettleBill: null,
        riderSettleOutstanding: 0,
        riderSettleAmount: '',
        // Receipt print default = NO (delivery customer isn't at the counter).
        // Opt-in checkbox persisted per device.
        deliveryPrintReceipt: (function(){ try { return localStorage.getItem('pos_delivery_final_print') === '1'; } catch(e) { return false; } })(),
        // ── FAILED BILLS (header shortcut, F11) ───────────────────────────────
        // Lazy-loaded list of all bills with pra_status IN (failed,offline,pending)
        // that have NOT received a pra_invoice_number yet. Auto-refresh on mount.
        failedBills: [],
        showFailedBills: false,
        activeFailedIndex: 0,
        failedBillsLoading: false,
        // ── REPRINT — TODAY'S BILLS (header shortcut, Alt+R) ─────────────────
        // Read-only list of ALL today's completed bills (PRA/queue/failed/
        // provisional/local). Click a row = instant print of the ORIGINAL
        // receipt (no COPY label — owner rule 23 Jul 2026). Search filters
        // client-side. reprintBusyId debounces double-clicks per row.
        reprintBills: [],
        showReprint: false,
        activeReprintIndex: 0,
        reprintLoading: false,
        reprintSearch: '',
        reprintBusyId: null,
        reprintPreviewBill: null,   // Preview modal (ZFC 30 Jul 2026): bill being previewed
        // ── QUICK RETURN (Task 681) — bill number → return form ──────────
        quickReturnOpen: false,
        quickReturnQ: '',
        quickReturnBusy: false,
        quickReturnErr: '',
        // ── INCOMING WAITER ORDERS (P7, F6) ───────────────────────────────
        // Orders composed on waiter tablets (source='waiter', status 'held').
        // Cashier loads one into the cart, takes payment via the MANUAL path
        // (the restaurant order already exists — hold endpoint must NOT run),
        // then the linked order is settled server-side (atomic claim).
        incomingOrders: [],
        showIncoming: false,
        incomingLoading: false,
        incomingOrderId: null,
        // Task #643: FULL claim snapshot of the loaded waiter order (waiter name,
        // order_number, kot_sent_at, items) — drives the cart-header badge and
        // the claimed-cart Cancel modal. Cleared with incomingOrderId.
        incomingOrderInfo: null,
        // Table-se-Bill (Jul 2026): auto-load RETIRED — new waiter orders get a
        // one-time toast nudge (per-session dedupe) and wait inside the Select-Table
        // picker as purple "Order Tayyar" cards until a cashier claims them.
        notifiedIncoming: [],
        chimedIncoming: [], // Task 106: chime dedupe is separate — hidden-tab chime must not eat the toast
        _incomingBaselined: false, // ZFC 3 Aug 2026: pehla poll = silent baseline — purane pending orders boot par "NAYA order" ban kar na chillayen
        // ── AUTO-SYNC ENGINE ──────────────────────────────────────────────
        // syncStatus: 'online' | 'syncing' | 'offline'
        // _syncTimer fires every 30 sec; pings count endpoint then silently
        // retries one bill per tick (no PRA hammering on long outages).
        // _autoSyncBusy = re-entrancy guard.
        syncStatus: navigator.onLine ? 'online' : 'offline',
        _syncTimer: null,
        _autoSyncBusy: false,
        // ── OFFLINE-FIRST BILLING (Jul 2026) ──
        // Bills created while the device has NO internet are queued in
        // IndexedDB ('tn_pos_offline' / 'bills', keyed by client UUID) and
        // replayed to pos.invoice.store with offline_uuid (server dedupes).
        // Queue is COMPANY-SCOPED — a shared browser must never post another
        // company's bills into the current session.
        offlineQueueCount: 0,
        offlineSyncing: false,
        offlineNeedsLogin: false,
        // Task 117: offline billing = Business+ plan gate (pricing_plans.offline_enabled).
        // Gates NEW queueing only — syncOfflineBills (replay of already-queued bills)
        // never checks this: queued bills kabhi reject nahi hote.
        offlineAllowed: {{ \App\Services\PosFeatureService::planAllows($company, 'offline_enabled') ? 'true' : 'false' }},
        // Task 127: offline-locked notice dismissal — resets whenever the shop
        // comes back online so the next outage shows the banner again.
        offlineLockDismissed: false,
        _idb: null,
        // Receipt-popup offline variant state: no server transaction yet, so the
        // popup renders a client-side summary + client-printed interim receipt.
        lastIsOffline: false,
        lastOfflineRec: null,
        showReceipt: false,
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
        // Phase 4 — Auto-Print receipt on successful sale (mirrors companies.print_on_pay)
        autoPrintEnabled: {{ ($company->print_on_pay ?? true) ? 'true' : 'false' }},
        // Pizza Master (11 Aug 2026): dine-in FINAL receipt auto-print company toggle —
        // OFF = payment par dine-in ka final bill khud print NAHI hota (proof bill
        // pehle diya ja chuka hota hai). KOT logic + manual print untouched.
        dineinAutoPrint: {{ ($company->print_on_pay_dinein ?? true) ? 'true' : 'false' }},
        // Task 514 (Zahid Irfan, 12 Aug 2026): PER-BILL receipt auto-print choice.
        // Checkbox default = company setting (dine-in → print_on_pay_dinein, warna
        // print_on_pay); cashier ek EK bill ke liye override karta hai. Sirf receipt
        // AUTO-print skip hoti hai — KOT, PRA submission, receipt popup, manual print
        // sab untouched (skipReceipt runAutoPrintChain + offline path dono se guzarta hai).
        boardPrintReceipt: true,   // Table Board FINAL confirm modal ka checkbox
        payPrintReceipt: true,     // Pay modal + one-tap CASH/CARD ka checkbox
        // Phase 5+ — Auto-print kitchen ticket on successful sale (mirrors companies.auto_print_kot)
        autoKotEnabled: {{ ($company->auto_print_kot ?? false) ? 'true' : 'false' }},
        // Silent printer routing via Desktop Sync Agent (companies.pos_printer_settings).
        // Per-type flags: enabled AND the matching printer is chosen. Agent-online is
        // checked SERVER-SIDE at enqueue — any non-2xx falls back to popup/iframe print.
        @php $__ps = $company->printerSettings(); @endphp
        silentBillPrint: {{ ($__ps['silent_print_enabled'] && $__ps['receipt_printer']) ? 'true' : 'false' }},
        silentKotPrint: {{ ($__ps['silent_print_enabled'] && $__ps['kot_printer']) ? 'true' : 'false' }},
        // Task 565 (customer voice note): opt-in "Print se pehle poocho" — payment
        // success par auto-print chain se PEHLE ek fauri Yes/No dialog. Flag OFF
        // (default) = purana behavior byte-identical. Flag posConfigRev/boot
        // fingerprint mein shamil hai (SW-cached copy toggle par refresh hoti hai).
        printConfirmAsk: {{ !empty($__ps['print_confirm_ask']) ? 'true' : 'false' }},
        showPrintConfirm: false,
        printConfirmChoice: 'yes',
        printConfirmAction: null,
        // Task 1025: "No" ka apna pending action — receipt skip par bhi KOT
        // apne mojooda gates se guzar kar fire ho (No sirf CUSTOMER BILL rokta hai).
        printConfirmNoAction: null,
        // Receipt popup auto-close (owner, 23 Jul 2026 — re-enabled after being persistent-only):
        // popup closes itself after N seconds (companies.pos_receipt_autoclose_seconds,
        // NULL = 10s default, 0 = never). Hover PAUSES the countdown; any click/keypress
        // inside the popup CANCELS it — the cashier always stays in control.
        receiptAutoCloseTimer: null,
        receiptAutoCloseSecs: {{ (int) ($company->pos_receipt_autoclose_seconds ?? 10) }},
        receiptCloseLeft: 0,
        receiptClosePaused: false,
        // Task 1036 — WhatsApp Bill (owner voice note 17 Aug 2026): final bill par
        // customer ke number ki wa.me chat, bill message + public receipt link
        // prefilled — cashier sirf Send dabata hai. Dono flags posConfigRev boot
        // fingerprint mein shamil hain (SW-cached copy toggle par refresh hoti hai).
        waBillEnabled: {{ (!empty($company->pos_whatsapp_bill_enabled) && \App\Services\PosFeatureService::planAllows($company, 'whatsapp_enabled')) ? 'true' : 'false' }},
        waBillAutoOpen: {{ !empty($company->pos_whatsapp_bill_auto_open) ? 'true' : 'false' }},
        waShopName: @json($company->name ?? ''),
        lastWaPhone: null,
        lastShareUrl: null,
        waHighlight: false,
        waReprintBusyId: null,
        // Print-chain session tracker — bumping the epoch invalidates in-flight iframe.onload /
        // afterprint callbacks so late-firing browser events (modal closed mid-sequence) cannot
        // enqueue stray prints. Mirrors restaurant POS engine.
        printSessionId: 0,
        pendingPrintTimers: [],
        // Registry of attached postMessage listeners — lets us remove them on cancel
        // so long cashier sessions (100s of bills) don't leak window-level listeners.
        printMessageHandlers: [],
        // Task 779: in-flight print WORK counter (silent enqueue fetches + printReceipt's
        // PRA-grace window) — pendingPrintTimers/printMessageHandlers alone miss these
        // async gaps. Tables-first navigation waits until ALL three are idle so a
        // page change can never cut off a print that's still on its way to the queue.
        printWorkInFlight: 0,
        lastInvoiceNumber: '',
        lastTransactionId: null,
        lastOrderId: null,
        // "Payment pehle, KOT baad": promoted delivery bill ki txn-KOT id — receipt
        // popup ka K button/shortcut is se manual reprint kar sakta hai (recovery path).
        lastTxnKotId: null,
        // Task 1379: is the just-paid bill's kitchen ticket STILL unsent? TRUE = the
        // receipt popup's KOT button is a FIRST send (payment-first release / auto-KOT
        // off), so it survives the reprint block; FALSE = pressing it is a reprint.
        // Set from the server's own kot_pending flag, cleared the moment the auto-print
        // chain actually fires the ticket.
        lastKotPending: false,
        // Task 1025: PAID bill ki order type ka SNAPSHOT (payment-success par set,
        // cart/state reset se pehle — kabhi live widget se na parhna). Tables-first
        // wapsi (returnToTablesAfterReceipt) sirf dine_in snapshot par chalti hai;
        // takeaway/delivery cashier ko sale screen par hi rakhte hain.
        lastOrderType: null,
        lastTotal: 0,
        lastPaymentMethod: '',
        // Success-popup extras: item count + sale timestamp + PRA copy state.
        lastItemsCount: 0,
        lastSaleAt: null,
        praCopied: false,
        // PRA fiscal result for the success popup. lastPraStatus drives the status
        // badge (submitted / pending / offline / local); lastPraNumber shows the
        // actual PRA fiscal invoice number once PRA returns it.
        lastPraNumber: '',
        lastPraStatus: '',
        // Waiter name shown in the success popup (empty for non-waiter bills).
        lastWaiterName: '',
        submitting: false,
        // Task 994: per-sale-attempt idempotency key — minted at the first Pay
        // press, REUSED on every retry of the SAME sale (rides on both the hold
        // and pay POSTs), cleared on success / clearCart. The server replays the
        // original bill for a known uuid instead of creating a duplicate.
        payAttemptUuid: null,
        // Task 1001: per-hold-attempt idempotency key for standalone Hold (F5) /
        // Send-to-Kitchen. Minted on first press, REUSED on every retry of the
        // SAME hold attempt (e.g. after a timeout where the server may have
        // succeeded but the response was lost). Cleared on success / clearCart.
        // Unlike payAttemptUuid this is NOT shared with the billing pass-through
        // (which sends pay_uuid instead), so both guards are independent.
        holdAttemptUuid: null,
        // Same idea for DIRECT held-order pays (held modal / table board): one
        // uuid per order id — a retry after a lost response replays the original
        // success (receipt data included) instead of a dead-end "already paid".
        _payUuidByOrder: {},
        cartAnimating: false,
        stockError: '',
        mobileView: 'menu',
        priorityOrder: false,
        recalledOrderId: null,
        // Task 1028: recall/claim ke waqt cart ka fingerprint snapshot — switch
        // se pehle compare hota hai (dirty = explicit choice, kabhi silent reset nahi).
        _recallCartBaseline: null,
        toast: { show: false, message: '', type: 'success' },
        lastHoldTime: 0,
        lastPayTime: 0,
        showDiscount: false,
        // Cart-level Bill Note toggle (owner, 28 Jul 2026) — same kitchenNotes model
        // as the Pay-modal note; collapsed by default so cart height is untouched.
        showCartNote: false,
        discountType: 'percentage',
        discountValue: 0,
        discountAmount: 0,

        get filteredCustomers() {
            // PERF: cap rendered rows — big shops carry 10k+ customers and an uncapped
            // x-for renders them ALL into the DOM at boot (20MB DOM, 15s splash on weak
            // POS PCs). Search still scans the FULL list; only the visible rows are capped.
            const q = this.customerSearch.toLowerCase();
            // Task 100: server results (full DB) win when available; the baked
            // subset is only the instant/offline fallback on huge shops.
            if (q && this.pickerServerResults) return this.pickerServerResults.slice(0, 50);
            if (!q) return this.allCustomers.slice(0, 50);
            return this.allCustomers.filter(c => c.name.toLowerCase().includes(q) || (c.phone && c.phone.includes(q))).slice(0, 50);
        },

        r2(v) { return Math.round((v + Number.EPSILON) * 100) / 100; },
        _safeQty(q) { const n = Number(q); return Number.isFinite(n) && n > 0 ? n : 1; },
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
            const taxable = this.cart.filter(i => !i.is_tax_exempt).reduce((s, i) => s + this.getItemTotal(i), 0);
            const discountRatio = this.effectiveSubtotal > 0 ? (this.effectiveSubtotal - this.discountAmount) / this.effectiveSubtotal : 1;
            return Math.max(0, this.r2(taxable * Math.max(0, discountRatio)));
        },
        get taxAmount() {
            // Inclusive mode: included portion of the taxable menu money (r/(100+r)).
            // Card-save: base always derives from the MENU (cash) rate, the bill's
            // own rate applies on top — mirrors PosTaxMath backend math.
            if (this.taxInclusive) {
                if (this.cardSaveMode() && Math.abs(this.taxMenuRate - this.taxRate) >= 0.005) {
                    return this.r2(this.taxableSubtotal * this.taxRate / (100 + this.taxMenuRate));
                }
                return this.r2(this.taxableSubtotal * this.taxRate / (100 + this.taxRate));
            }
            return this.r2(this.taxableSubtotal * this.taxRate / 100);
        },
        get totalAmount() {
            // Inclusive mode: menu prices already contain tax — never add it on top.
            // Card-save with a DIFFERENT rate than the menu rate: cheaper total.
            if (this.taxInclusive) {
                if (this.cardSaveMode() && Math.abs(this.taxMenuRate - this.taxRate) >= 0.005) {
                    const after = Math.max(0, this.r2(this.effectiveSubtotal - this.discountAmount));
                    const exemptShare = Math.max(0, this.r2(after - this.taxableSubtotal));
                    return Math.max(0, this.r2(exemptShare + this.taxableSubtotal * (100 + this.taxRate) / (100 + this.taxMenuRate)));
                }
                return Math.max(0, this.r2(this.effectiveSubtotal - this.discountAmount));
            }
            return Math.max(0, this.r2(this.effectiveSubtotal - this.discountAmount + this.taxAmount));
        },
        get roundedTotal() { return Math.round(this.totalAmount); },
        get roundOff() { return this.r2(this.roundedTotal - this.totalAmount); },
        get exemptAmount() { return this.cart.filter(i => i.is_tax_exempt).reduce((s, i) => s + this.getItemTotal(i), 0); },
        // ─── Jul 2026 redesign: method-aware CART totals (estimate only — the
        // backend recomputes tax per method on submit; PRA gets tax in full).
        // Mirrors heldOrderEstimate()/payModalTotal math for the LIVE cart.
        get cartQtyCount() { return this.cart.reduce((s, i) => s + this._safeQty(i.quantity), 0); },
        cartTotalForMethod(method) {
            const rate = method === 'card'
                ? (this.taxRules['debit_card'] || this.taxRules['card'] || 8)
                : (this.taxRules['cash'] ?? this.taxRate);
            if (this.taxInclusive) {
                if (this.cardSaveMode() && Math.abs(this.taxMenuRate - rate) >= 0.005) return this.cardSaveTotalForRate(rate);
                // Plain inclusive: menu total is method-independent.
                return Math.round(Math.max(0, this.r2(this.effectiveSubtotal - this.discountAmount)));
            }
            const tax = this.r2(this.taxableSubtotal * rate / 100);
            return Math.round(Math.max(0, this.r2(this.effectiveSubtotal - this.discountAmount + tax)));
        },
        // "Card/Digital pe: Rs X" hint under the big total — only when the card
        // rate actually differs from cash (exclusive or card-save modes).
        get cartMethodHint() {
            if (!this.cart.length) return '';
            const cashRate = this.taxRules['cash'] ?? this.taxRate;
            const cardRate = this.taxRules['debit_card'] || this.taxRules['card'] || cashRate;
            if (Math.abs(cashRate - cardRate) < 0.005) return '';
            if (this.taxInclusive && !this.cardSaveMode()) return ''; // menu-rate-final: sab same
            return 'Card pe: Rs. ' + Number(this.cartTotalForMethod('card')).toLocaleString() + (this.taxInclusive ? '' : ' (tax ' + cardRate + '%)');
        },
        recalcDiscount() {
            if (!this.discountValue || this.discountValue <= 0) { this.discountAmount = 0; return; }
            if (this.discountType === 'percentage') {
                const pct = Math.min(100, Math.max(0, this.discountValue));
                this.discountAmount = this.r2(this.effectiveSubtotal * pct / 100);
            } else {
                this.discountAmount = this.r2(Math.min(this.effectiveSubtotal, Math.max(0, this.discountValue)));
            }
        },

        // ── Screen Fit (Jul 2026): sale screen adapts to ANY display size ──
        // 'auto' derives a zoom factor from viewport width/height (small shop
        // laptops / low-res terminals scale DOWN, very large TVs scale UP a bit);
        // a manual % choice is saved per device in localStorage 'tn_screen_fit'.
        // The root div gets CSS zoom + a px height divided by the zoom so the
        // layout still fills exactly the space under the 48px top nav (viewport
        // units are NOT reliable inside a zoomed subtree — px are).
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

        persistDeliveryPrintReceipt() {
            try { localStorage.setItem('pos_delivery_final_print', this.deliveryPrintReceipt ? '1' : '0'); } catch (e) {}
        },
        persistPromoteNoPrint() {
            try { localStorage.setItem('pos_promote_no_print', this.promoteNoPrint ? '1' : '0'); } catch (e) {}
        },

        // OFFLINE-FIRST BOOT (Jul 2026): the SW serves this page cache-first, so a
        // cached copy verifies its baked fingerprint against the server shortly
        // after boot. Mismatch → drop SALE_CACHE + ONE-SHOT reload (never yanks a
        // sale in progress — except on user/company switch, which is a security
        // reload). Offline / network fail → silently keep the cached screen
        // (the existing offline queue handles bill submits).
        bootFpCheck() {
            try {
                const cur = window.tnBootFp;
                if (!cur) return;
                fetch('{{ route('pos.api.boot-check') }}', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(r => {
                        // LOGGED-OUT LOOP FIX (ZFC 30 Jul 2026): a dead session must
                        // ALSO drop the cached sale screen, or every attempt to open
                        // POS replays the stale cached copy → splash → bounce to
                        // login → again and again ("bar bar load"). Drop first, then go.
                        const toLogin = () => {
                            try { navigator.serviceWorker?.controller?.postMessage({ type: 'TN_DROP_SALE_CACHE' }); } catch (e) {}
                            setTimeout(() => window.location.replace('{{ route('pos.login') }}'), 250);
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
                        // Task 1347: 'b' (active branch) joins the compared keys —
                        // a branch switch must refresh this cached screen, since the
                        // branch is baked into every offline bill it queues.
                        const same = ['u', 'c', 's', 'cat', 'set', 'b'].every(k => String(cur[k]) === String(fresh[k]));
                        if (same) return;
                        const userChanged = String(cur.u) !== String(fresh.u) || String(cur.c) !== String(fresh.c);
                        // Never yank an in-progress sale for a content update.
                        const busy = (this.cart && this.cart.length > 0) || this.editingBillId || this.showPayModal || this.showReceipt || this.submitting;
                        if (!userChanged && busy) return;
                        // One-shot guard: never reload twice for the same server fingerprint
                        // (protects against a reload loop if the cache update races us).
                        const sig = [fresh.u, fresh.c, fresh.s, fresh.cat, fresh.set, fresh.b].join(':');
                        try {
                            if (!userChanged && sessionStorage.getItem('tnBootFpReloaded') === sig) return;
                        } catch (e) {}
                        // LOOP-PROOF RELOAD (ZFC 28 Jul 2026 — "loading bar bar aata
                        // hai"): the old flow dropped the SW cache via postMessage and
                        // reloaded 400ms later — a race. If the fresh network fetch was
                        // slow/failed, the reload landed back on the SAME stale copy
                        // (or a blank splash) and the cycle repeated. New contract:
                        // fetch a FRESH copy over the network FIRST, put it into the
                        // sale cache OURSELVES, and reload only once the new page is
                        // secured. Network down/flaky → keep the current working screen.
                        (async () => {
                            if (!userChanged) {
                                try {
                                    const resp = await fetch(window.location.pathname, { cache: 'reload', credentials: 'same-origin' });
                                    if (!window.tnSaleBoot || !(await window.tnSaleBoot.validResponse(resp))) return;
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
                            try { sessionStorage.setItem('tnBootFpReloaded', sig); } catch (e) {}
                            window.location.reload();
                        })();
                    })
                    .catch(() => {}); // offline → cached screen keeps working as-is
            } catch (e) {}
        },
        init() {
            if (this._inited) return;
            this._inited = true;
            this.initFit();
            // Task 976: Tables board Takeaway/Delivery quick-start handoff.
            // The tables page sets sessionStorage 'tn_pos_boot_order_type' before
            // navigating here. Consume it immediately (one-shot) so a refresh or
            // back-navigation never re-applies the seed. Only apply when this is a
            // fresh screen — no table_id / recall_order URL param, no editingBillId
            // — so a recalled dine-in order is never silently switched.
            // 'delivery' maps directly; 'takeaway' is already the default but we
            // set it explicitly in case a previous session left a different type.
            try {
                var _bootType = sessionStorage.getItem('tn_pos_boot_order_type');
                if (_bootType === 'takeaway' || _bootType === 'delivery') {
                    sessionStorage.removeItem('tn_pos_boot_order_type');
                    var _urlParams = new URLSearchParams(window.location.search);
                    var _isFresh = !_urlParams.get('table_id') && !_urlParams.get('recall_order') && !this.editingBillId;
                    if (_isFresh) {
                        this.orderType = _bootType;
                    }
                }
            } catch (e) {}
            setTimeout(() => this.bootFpCheck(), 1500);
            // NestPOS Desktop keep-alive (Jul 2026): the desktop shell hides the
            // window on close and calls this hook when it is shown again — so a
            // long-lived screen picks up deploys/product changes with ONE reload.
            // bootFpCheck() has its own busy-guard (never yanks a sale in progress).
            try { window.tnDesktopResumeCheck = () => { try { this.bootFpCheck(); } catch (e) {} }; } catch (e) {}
            // Honor the saved "hide products" preference ONLY in inventory-OFF mode.
            // Inventory mode must always show the catalog (no manual on-the-fly create).
            try { if (localStorage.getItem('pos_show_products') === '0') this.showProducts = false; } catch (e) {}
            // Task 1349: device counter pref (validated against the baked list).
            this.loadTerminalPref();
            this.syncAutoWidecart(); // all items hidden => auto split layout
            this.filterProducts();
            // Task 753 (Pizza Master): "products ghayab" dead-end guards — baked
            // catalog empty ya grid manually OFF ho to khud pakro/batao.
            setTimeout(() => this.gridDeadEndCheck(), 1200);
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
            // Owner (28 Jul 2026): "New Sale pe click karo to 'NestPOS load ho raha
            // hai' kyun aata hai?" — mobile-menu / stray nav links to the plain sale
            // URL were doing a FULL page reload (boot splash) even though we're
            // already ON the sale screen. Intercept exact-match links only (never
            // ?edit_bill= / ?table_id= reloads — those NEED a fresh page).
            document.addEventListener('click', (e) => {
                const a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
                if (!a) return;
                const plain = '{{ route('pos.invoice.create') }}';
                if (a.href === plain || a.href === plain + '/') {
                    e.preventDefault();
                    this.newSale();
                }
            });
            this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); });
            // EDIT MODE (Jul 2026): ?edit_bill= → load the provisional bill into the
            // cart. Also show the "updated" toast after a successful edit-reload.
            this._initEditMode();
            // ZFC (3 Aug 2026): Tables page se occupied table khol kar cashier ko
            // KHALI cart milta tha — lagta tha purana order GHAYAB ho gaya (asal
            // mein woh held-order mein mehfooz tha). ?table_id= boot par us table
            // ka chalta order khud cart mein load ho (waiter order = atomic claim).
            // Task 502 (11 Aug 2026): Tables page open-order card ?recall_order= ke
            // saath aata hai — boot par WOHI order recall ho (table_id fallback se
            // pehle), warna purana table_id-based auto-open chale.
            setTimeout(() => this.autoRecallFromUrl(), 600);
            try {
                const up = new URLSearchParams(window.location.search).get('updated');
                if (up) {
                    history.replaceState({}, '', '{{ route('pos.invoice.create') }}');
                    setTimeout(() => this.showToast(window.TXT.bill_word + up + window.TXT.sfx_updated_f10_final, 'success'), 400);
                }
            } catch (e) {}
            // Task 644 (ZFC, 13 Aug 2026): dashboard Pending-tile ka "counter
            // orders" chip ?open_incoming=1 ke saath aata hai — TABLELESS waiter
            // (counter) orders SIRF bell panel mein dikhte hain (owner rule 5 Aug
            // 2026), is liye boot par wohi panel khud khul jaye (dead-end fix).
            // Claim purane atomic claimAndLoadIncoming raste se hi hota hai —
            // yahan sirf panel khulta hai. Param foran strip: SW SALE_CACHE sirf
            // bina-query URL cache karta hai, agla reload normal cached boot ho.
            try {
                if (this.isRestaurantMode && new URLSearchParams(window.location.search).get('open_incoming')) {
                    // Panel open PEHLE schedule ho — phir URL strip. replaceState
                    // RELATIVE pathname se: route() yahan https-absolute URL deta
                    // hai jo http (dev) par cross-origin SecurityError phenk kar
                    // poora handler maar deta tha (route-absolute-https-fetch trap).
                    setTimeout(() => this.openIncoming(), 900);
                    history.replaceState({}, '', window.location.pathname);
                }
            } catch (e) {}
            // Lazy-load provisional bill list on mount (for header badge count).
            // Failures are silent — badge just won't show until next refresh.
            setTimeout(() => this.loadLocalBills(), 1200);
            setTimeout(() => this.loadFailedBills(), 1500);
            setTimeout(() => this.loadReprintBills(), 1800); // Akhri Bills strip (Jul 2026 redesign)
            // P7: incoming waiter orders — badge poll every 20s (restaurant mode only).
            if (this.isRestaurantMode) {
                setTimeout(() => this.loadIncoming(), 1800);
                // Task 106: poll even when the tab is hidden — the whole point of the
                // chime is alerting a cashier who is looking elsewhere. (Browsers
                // throttle background intervals to ~1/min; that's still fine.)
                setInterval(() => { if (!this.showPayModal) this.loadIncoming(); }, 20000);
                // When the cashier comes back, fire the pending toasts immediately
                // instead of waiting up to 20s for the next poll.
                document.addEventListener('visibilitychange', () => { if (!document.hidden) this.maybeAutoLoadIncoming(); });
                // Occupied-timer tick (table picker + held-orders elapsed labels).
                setInterval(() => { this.nowTick = Date.now(); }, 30000);
                // Table Board (Jul 2026): tiles live below the cart, so the status
                // feed must poll even with the table picker closed. Paused while the
                // tab is hidden or a payment is mid-flight.
                if (this.tableBoardEnabled) {
                    setTimeout(() => this.loadTableStatus(), 2200);
                    setInterval(() => { if (!document.hidden && !this.showPayModal && !this.boardBusy) this.loadTableStatus(); }, 25000);
                }
                // Task 899 / 935: poll held-orders every 25 s for bidirectional cross-terminal
                // sync — a cancel on Tab B disappears here within one cycle, and a NEW hold
                // on Tab B appears here within one cycle (full server list replaces local list).
                // Runs independently of the table board so it works on retail POS too.
                setInterval(() => { if (!document.hidden) this.loadHeldOrders(); }, 25000);
            }
            // 🔄 Auto-Sync — kicks in after 4 sec, then every 30 sec.
            // Live-updates online/offline pill + silently retries pending bills.
            setTimeout(() => this._startAutoSync(), 4000);
            // ── Caller ID (Task 1039): light 7s poll, feature-gated, paused
            // while the tab is hidden. Cursor survives reloads via localStorage
            // so an already-shown ring never re-alerts after a refresh.
            if (this.callerIdOn) {
                try { this.callerLastId = parseInt(localStorage.getItem('tn_caller_last_id') || '0', 10) || 0; } catch (e) {}
                try { this.callerSeenId = parseInt(localStorage.getItem('tn_caller_seen_id') || '0', 10) || 0; } catch (e) {}
                setTimeout(() => this.pollCallerEvents(), 2500);
                setInterval(() => { if (!document.hidden) this.pollCallerEvents(); }, 7000);
                // v2: unseen badge survives reloads — one boot fetch counts the
                // last-24h calls newer than the cashier's seen-cursor.
                setTimeout(() => this.refreshCallerUnseen(), 4000);
                // v2: queued rings must surface the MOMENT the blocking modal
                // closes — not on the next 7s poll tick.
                ['showPayModal', 'showReceipt', 'showManagerPinModal', 'tableSwitchPrompt', 'submitting'].forEach(k => {
                    this.$watch(k, v => { if (!v) this.$nextTick(() => this.maybeShowCallerPopup()); });
                });
            }
            // Signal only after every synchronous watcher/listener/setup step above
            // succeeded. Empty catalogues and intentionally hidden grids are still
            // valid boots; this readiness check is about component initialization.
            setTimeout(() => {
                this.loading = false;
                try { window.tnSaleBoot?.ready(); } catch (e) {}
            }, 300);
        },

        // ─── AUTO-SYNC ENGINE ──────────────────────────────────────────────
        // Browser-side companion to the SyncPosOfflineInvoicesJob (cron).
        // Every 30 sec: refresh online/offline state, count pending bills,
        // and silently retry the OLDEST one. One bill per tick = no PRA flood.
        _startAutoSync() {
            if (this._syncTimer) return;
            window.addEventListener('online', () => { this.syncStatus = 'online'; this.offlineLockDismissed = false; this.syncOfflineBills(); this._autoSyncTick(true); });
            window.addEventListener('offline', () => { this.syncStatus = 'offline'; });
            this.refreshOfflineCount();
            this.syncOfflineBills();
            this._autoSyncTick();
            this._syncTimer = setInterval(() => this._autoSyncTick(), 30000);
            // Coming back to the tab = instant badge reconcile (background cron /
            // Desktop Agent may have synced bills while the tab slept).
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && navigator.onLine) this.loadFailedBills();
            });

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
            // server before failed-bill PRA retries (they're older by definition).
            if (this.offlineQueueCount > 0) await this.syncOfflineBills();
            this._autoSyncBusy = true;
            try {
                // Lightweight refresh of pending count (also serves as ping).
                await this.loadFailedBills();
                if (!this.praEnabled) { this.syncStatus = 'online'; this._autoSyncBusy = false; return; }
                if (this.failedBills.length === 0) { this.syncStatus = 'online'; this._autoSyncBusy = false; return; }
                // Pick OLDEST not-currently-retrying bill and submit silently.
                // _queued bills already sit with the Desktop Agent — re-queueing
                // them every 30 sec adds nothing and made the badge flicker.
                const candidate = [...this.failedBills].reverse().find(b => !b._retrying && !b._queued);
                if (!candidate) { this.syncStatus = 'online'; this._autoSyncBusy = false; return; }
                this.syncStatus = 'syncing';
                candidate._retrying = true;
                const res = await fetch('{{ url('/pos/api/failed-bills') }}/' + candidate.id + '/retry', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                });
                const data = await res.json().catch(() => ({}));
                if (data && data.success && data.queued) {
                    // Desktop-Agent mode: the bill is only RE-QUEUED for the local
                    // agent — it is NOT synced yet. Keep it in the list (badge stays
                    // honest) and mark it so this tick loop doesn't hammer it again.
                    // When the agent actually submits, the next refresh drops it.
                    candidate._retrying = false;
                    candidate._queued = true;
                } else if (data && data.success) {
                    this.failedBills = this.failedBills.filter(b => b.id !== candidate.id);
                    // Mini toast — non-intrusive (existing showToast auto-dismisses).
                    this.showToast(window.TXT.auto_synced_prefix + (candidate.invoice_number || '#' + candidate.id) + ' to PRA', 'success');
                    // Reconcile with server truth right away (background cron/agent
                    // may have synced other bills too) — badge updates without reload.
                    await this.loadFailedBills();
                } else {
                    candidate._retrying = false;
                }
                this.syncStatus = 'online';
            } catch (e) {
                console.warn('autoSyncTick', e);
                this.syncStatus = navigator.onLine ? 'online' : 'offline';
            }
            this._autoSyncBusy = false;
        },

        // ─── OFFLINE-FIRST BILLING ENGINE (Jul 2026) ───────────────────────
        // When the device has NO internet at Pay time, the bill payload is
        // stored in IndexedDB with a client UUID and replayed automatically
        // (online event + every auto-sync tick). Server-side offline_uuid
        // dedupe makes replays idempotent — a lost response never duplicates.
        _offlineCompanyId: {{ (int) (app('currentCompanyId') ?? 0) }},
        idbOpen() {
            return new Promise((resolve, reject) => {
                if (this._idb) return resolve(this._idb);
                if (!window.indexedDB) return reject(new Error('IndexedDB unavailable'));
                const req = indexedDB.open('tn_pos_offline', 1);
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
        _newOfflineUuid() {
            try { if (crypto && crypto.randomUUID) return crypto.randomUUID(); } catch (e) {}
            return 'off-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
        },
        // Task 994: fetch with a HARD timeout — a hung hold/pay request must
        // surface an error within seconds, not after the browser's multi-minute
        // default (owner report 16 Aug 2026: "error aya bohat der baad"). Safe
        // to abort mid-flight: the pay_uuid/offline_uuid replay guards make the
        // retry idempotent server-side (no duplicate bill, no duplicate KOT).
        async fetchWithTimeout(url, opts = {}, ms = 20000) {
            if (typeof AbortController === 'undefined') {
                // Older Android WebViews lack AbortController — use Promise.race
                // so the 20 s hard timeout still fires and _isTimeoutError(e)
                // recognises it (AbortError name), showing the safe-retry toast
                // instead of hanging forever or showing a blank error.
                var _toutP = new Promise(function (_, rej) {
                    setTimeout(function () { var e = new Error('timeout'); e.name = 'AbortError'; rej(e); }, ms);
                });
                return Promise.race([fetch(url, opts), _toutP]);
            }
            const ctrl = new AbortController();
            const timer = setTimeout(() => { try { ctrl.abort(); } catch (_) {} }, ms);
            try { return await fetch(url, { ...opts, signal: ctrl.signal }); }
            finally { clearTimeout(timer); }
        },
        _isTimeoutError(e) { return !!e && (e.name === 'AbortError' || /abort/i.test(String(e.name || ''))); },
        // Queue a bill that could NOT reach the server (no internet). Mirrors the
        // success UX: receipt popup (offline variant) + optional auto-print of a
        // client-rendered interim receipt, cart cleared so billing continues.
        async queueOfflineBill(payload, method, savedTotal, skipReceipt = false) {
            // REUSE the uuid already attached by processPaymentManual (it rode on
            // the failed online attempt too) — minting a fresh one here would
            // reopen the lost-response duplicate window. Fallback only if absent.
            const uuid = payload.offline_uuid || this._newOfflineUuid();
            payload.offline_uuid = uuid;
            // Phase 2: ride the ORIGINAL sale moment + cashier on the payload so a
            // next-morning sync books the bill under the right date & user (server
            // clamps the timestamp and company-checks the user — spoof-safe).
            payload.offline_queued_at = new Date().toISOString();
            payload.offline_queued_by = {{ (int) auth('pos')->id() }};
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
            // Offline bills NEVER settle a waiter order (needs the server) — the
            // order stays in Incoming until an ONLINE final claims it.
            this.lastIsOffline = true;
            this.lastOfflineRec = rec;
            this.lastInvoiceNumber = 'OFFLINE-' + uuid.slice(0, 8).toUpperCase();
            this.lastTransactionId = null;
            this.lastOrderId = null;
            this.lastKotPending = false; // Task 1379: offline bill has no server-side KOT state
            this.lastOrderType = this.orderType || null; // Task 1025: snapshot before clearCart
            this.lastTotal = rec.total;
            this.lastPaymentMethod = method;
            this.lastPraNumber = '';
            this.lastPraStatus = '';
            this.lastWaiterName = (this.incomingOrderInfo && this.incomingOrderInfo.waiter) ? this.incomingOrderInfo.waiter : '';
            this.lastItemsCount = (this.cart || []).reduce((s, i) => s + (parseFloat(i.quantity) || 0), 0);
            this.lastSaleAt = Date.now();
            this.setWaBill(null); // Task 1036: offline bill = no server link yet — button hidden
            this.showReceipt = true;
            this.scheduleReceiptAutoClose();
            this.showToast(window.TXT.offline_bill_saved_will_sync, 'success');
            // Task 514: per-bill checkbox offline path par bhi lago — unticked =
            // is bill ki offline receipt bhi auto-print nahi hoti.
            if (!skipReceipt && this.autoPrintEnabled && !(this.orderType === 'dine_in' && !this.dineinAutoPrint)) {
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
            // Task 217: quota-full 403 with provisional_allowed → ask the cashier ONCE
            // per sync run whether to replay the queued bill(s) as PROVISIONAL (same
            // offline_uuid — dedupe intact; provisionals don't consume the quota until
            // promoted). null = not asked yet, true/false = cashier's answer.
            let provisionalConsent = null;
            const postBill = async (payload) => {
                const res = await fetch('{{ route("pos.invoice.store") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                });
                let data = null;
                try { data = JSON.parse(await res.text()); } catch (_) {}
                return { res, data };
            };
            for (const b of bills.sort((a, z) => a.queued_at - z.queued_at)) {
                // Poison-bill cap: after 50 REJECTED attempts (server said no — not
                // network drops, those `break` before counting) stop retrying so one
                // bad bill can't block/spam the queue forever. It stays on-device
                // (badge + count) for support to inspect.
                if ((b.tries || 0) >= 50) { poisoned++; continue; }
                try {
                    // Cashier already agreed to provisional fallback this run? Replay
                    // remaining non-provisional bills as provisional up front — the
                    // quota 403 would reject them as finals anyway.
                    if (provisionalConsent === true && !b.payload.save_as_provisional) {
                        b.payload.save_as_provisional = true;
                        b.provisional = true;
                    }
                    let { res, data } = await postBill(b.payload);
                    // Quota-full 403 where the backend says a provisional retry would
                    // pass the flow rules → offer the same one-click provisional save
                    // the online path got in Task 216, instead of a stuck queue.
                    if (res.status === 403 && data && data.quota_full && data.provisional_allowed && !b.payload.save_as_provisional) {
                        if (provisionalConsent === null) {
                            provisionalConsent = confirm(window.TXT.quota_provisional_prompt_offline || window.TXT.quota_provisional_prompt || (data.message || ''));
                        }
                        if (provisionalConsent) {
                            b.payload.save_as_provisional = true;
                            b.provisional = true;
                            ({ res, data } = await postBill(b.payload));
                        }
                    }
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
                    // Quota block (403) fails every remaining bill too — stop hammering.
                    // (If the cashier accepted provisional fallback, this 403 was NOT a
                    // plain quota block — it hit even as provisional — so stopping is
                    // still the safe move.)
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
                // Task 1287: Urdu-script users get JNN on the offline interim slip too —
                // product/customer names can be Urdu. The font resolves from the SW cache
                // when offline (fonts are cached on first ur page load); Courier stays the
                // Latin fallback. Empty string for en/rur (no font fetch).
                {!! json_encode(app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT ? "@font-face{font-family:'Jameel Noori Nastaleeq';src:url('" . url('fonts/jameel-noori-nastaleeq.woff2') . "?v=1') format('woff2');font-display:swap;}body{font-family:'Jameel Noori Nastaleeq','Courier New',monospace;line-height:1.9;}" : '') !!} +
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
                // Task 1287: in Urdu mode the slip's @font-face may still be
                // downloading at iframe onload — bounded single-fire wait for
                // JNN before print (8s failsafe, same as the server receipt
                // templates: a Courier fallback slip beats a lost slip).
                // en/rur: no @font-face injected → fonts.load resolves at once.
                let ofrPrinted = false;
                const ofrGo = () => {
                    if (ofrPrinted) return;
                    ofrPrinted = true;
                    try { fr.contentWindow.focus(); fr.contentWindow.print(); } catch (e) {}
                    setTimeout(() => { try { fr.remove(); } catch (e) {} }, 60000);
                };
                let waited = false;
                try {
                    const fd = fr.contentDocument && fr.contentDocument.fonts;
                    if (fd && fd.load) {
                        fd.load("16px 'Jameel Noori Nastaleeq'", '\u0627\u0631\u062F\u0648').then(ofrGo, ofrGo);
                        setTimeout(ofrGo, 8000);
                        waited = true;
                    }
                } catch (e) {}
                if (!waited) ofrGo();
            };
        },

        cacheProductData() {
            try {
                const key = 'rpos_products_cache_{{ app("currentCompanyId") ?? 0 }}';
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

        get storageKey() { return 'rpos_cart_{{ auth("pos")->id() ?? 0 }}_{{ app("currentCompanyId") ?? 0 }}'; },
        get notesKey() { return 'rpos_notes_{{ auth("pos")->id() ?? 0 }}_{{ app("currentCompanyId") ?? 0 }}'; },
        _saveCartTimer: null,
        saveCart() {
            // EDIT MODE: never persist the loaded bill's items over the cashier's own
            // in-progress cart — after the edit (or Cancel) their cart restores intact,
            // and the edited bill's items can never resurrect as a duplicate new sale.
            if (this.editingBillId) return;
            // Debounced localStorage write — avoids hot-path JSON.stringify on every qty keystroke / cart mutation.
            if (this._saveCartTimer) clearTimeout(this._saveCartTimer);
            this._saveCartTimer = setTimeout(() => {
                if (this.editingBillId) return; // re-check at fire time (debounce race on edit-mode entry)
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
            const picked = types[this.flowTypeIndex] || types[0] || 'takeaway';
            // Route through setOrderType so Dine In opens the table picker (and
            // switching away from Dine In releases any reserved table) — assigning
            // orderType directly here silently skipped the picker (owner bug Jul 2026).
            this.setOrderType(picked);
            this.flowStep = 'cart';
            // Dine In with no table yet → picker modal is now open; hold off cart
            // mode until a table is chosen (selectTable resumes the guided chain).
            if (!(picked === 'dine_in' && this.showTablePicker)) this.enterCartMode('last');
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
            // GRID EDIT mode (per-user prefs): Enter toggles visibility, mirrors tile click.
            if (item) this.gridEditMode ? this.toggleItemVisibility(item) : this.handleProductClick(item);
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

        _searchDebounceTimer: null,
        // BARCODE SCAN support: true when the typed query EXACTLY equals a product's
        // barcode or SKU (case-insensitive). Scanners "type" the code then send Enter.
        isExactCodeMatch(it, q) {
            return (it.barcode && String(it.barcode).toLowerCase() === q)
                || (it.sku && String(it.sku).toLowerCase() === q);
        },
        findExactCodeItem(q) {
            if (!q) return null;
            const all = [...this.allProducts, ...this.allServices];
            return all.find(it => it.name && parseFloat(it.price) > 0 && this.isExactCodeMatch(it, q)) || null;
        },
        // ---- MULTI-WORD SEARCH (live restaurant video, 16 Aug 2026) ----
        // A cashier typing "cheese loaded half" must find "Cheese Loaded Fries
        // (Half)". Both modes previously treated the WHOLE query as ONE token, so
        // any query with a space dead-ended. Now the QUERY is tokenized; a hit =
        // every typed token prefix-matches a word of the name. Words are split on
        // NON-alphanumeric runs so parentheses/punctuation never block a match
        // ("(Half)" yields "half"). Non-ASCII chars (Urdu names) count as word
        // chars. BOTH surfaces (dropdown + grid) call nameMatchRank so they can
        // never diverge.
        searchTokens(s) {
            return String(s || '').toLowerCase().split(/[^a-z0-9\u0080-\uffff]+/).filter(Boolean);
        },
        // Rank a product name against the typed query. 0 = no match; higher =
        // better (contiguous/in-order matches sort above scattered-word ones so
        // "(Full)"/"(Half)" pairs order sensibly):
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
                    // GLOBAL SEARCH (customer request, 22 Jul 2026): search ALWAYS covers the
                    // whole catalog — deals + products + services — no matter which category is
                    // selected. The category dropdown/pills only scope the browsable GRID; a
                    // cashier typing a name must never get "not found" because a filter was left
                    // on some other category.
                    const all = [...this.allDeals, ...this.allProducts, ...this.allServices];
                    // FIRST-LETTER PRIORITY (customer suggestion, 21 Jul 2026): names that
                    // START with the typed text rank above other matches — preserved via
                    // nameMatchRank's rank ordering (rank 4/3 = name-start/contiguous sort
                    // above scattered hits). Name hits bucket separately from barcode hits.
                    const pref = [], other = [];
                    // STRICT PREFIX (owner, 24 Jul 2026): the first token matches only from
                    // the very START of the name — "zi" → only names starting with "Zi".
                    // MULTI-WORD (16 Aug 2026): later tokens prefix-match any later word —
                    // "cheese loaded half" finds "Cheese Loaded Fries (Half)".
                    // BARCODE/SKU substring matching stays (scanners type the digits, which
                    // never match a product name) but ONLY when the query contains a digit
                    // or symbol — letters-only typing is a NAME search, otherwise SKUs like
                    // "CHI-001" leak unrelated products into a name search.
                    const codeSearch = /[^a-z\s]/.test(q);
                    // PER-COMPANY SEARCH MODE (owner, 4 Aug 2026): any_word lets EVERY
                    // token (incl. the first) match any word; prefix = strict 24 Jul rule.
                    for (let i = 0; i < all.length; i++) {
                        const it = all[i];
                        if (!it.name || !(parseFloat(it.price) > 0)) continue;
                        const r = this.nameMatchRank(it.name, q, this.searchAnyWord);
                        if (r > 0) {
                            pref.push({ it, r, i });
                        } else if (codeSearch && other.length < 12
                            && ((it.barcode && String(it.barcode).toLowerCase().includes(q))
                            || (it.sku && String(it.sku).toLowerCase().includes(q)))) {
                            other.push(it);
                        }
                    }
                    // WORD-START FALLBACK (owner, Aug 2026): if the strict-first rule finds
                    // NOTHING ("win" vs "5 Piece Hot Wings", "loaded half" typed without the
                    // name's first word), rescan in any-word mode — the dropdown must never
                    // dead-end on real menus. No-op in any_word mode (same predicate ran).
                    if (!pref.length && q) {
                        for (let i = 0; i < all.length; i++) {
                            const it = all[i];
                            if (!it.name || !(parseFloat(it.price) > 0)) continue;
                            const r = this.nameMatchRank(it.name, q, true);
                            if (r > 0) pref.push({ it, r, i });
                        }
                    }
                    // Higher rank first; catalog order within a rank (stable tiebreak).
                    pref.sort((a, b) => (b.r - a.r) || (a.i - b.i));
                    const out = [...pref.slice(0, 12).map(p => p.it), ...other].slice(0, 12);
                    // (Scanner safety: pool is global now, so an exact barcode/SKU match is
                    // always already in scope — the old category-filter rescue is unnecessary.)
                    // Exact barcode/SKU match jumps to the top so the scanner's trailing
                    // Enter always adds the right product (not an accidental name match).
                    out.sort((a, b) => (this.isExactCodeMatch(b, q) ? 1 : 0) - (this.isExactCodeMatch(a, q) ? 1 : 0));
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
            // forward Enter to the prompt's confirm (same pattern as below) —
            // it must never re-run search/guided logic behind the modal.
            if (this.tableSwitchPrompt) { if (!e?.repeat) this.confirmTableSwitch(this.tableSwitchEnterAction()); return; }
            // TABLE PICKER open + focus raced back into the search box: the input's
            // .stop keeps Enter from reaching handleKey's picker branch, so forward
            // it here (same pattern as the type-step forwarding above) — Enter must
            // reserve the highlighted table, never re-run search/guided logic.
            if (this.showTablePicker) { if (!e?.repeat) { const t = this.tablePickerFlat()[this.tablePickerIndex]; if (t) this.selectTable(t); } return; }
            // BARCODE SCAN fast path: scanner's Enter can arrive BEFORE the 60ms search
            // debounce fills the dropdown — an exact barcode/SKU match must add instantly
            // here, or (inventory-OFF) the scan would fall through to quick-CREATE a bogus
            // product named after the barcode digits. Skipped when the cashier has
            // ARROWED to a suggestion (highlightIndex > 0): their explicit pick wins
            // over an accidental short-SKU collision.
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
                const existing = [...this.allProducts, ...this.allServices].find(it => it.name && parseFloat(it.price) > 0 && it.name.trim().toLowerCase() === nameQ);
                if (existing) { this.quickAddItem(existing); return; }
                this.quickCreateProduct(); return;
            }
            // GUIDED FLOW (opt-in): Enter on an EMPTY search box advances the chain.
            // When the company has 2+ order types, it first opens the Order-Type step
            // (dine in / takeaway / delivery) — the owner-specified step between Items and
            // Cart. Single-type companies skip straight to the cart (byte-identical to before).
            if (this.guidedFlow && this.searchQuery.trim().length === 0 && this.cart.length > 0 && !this.showTablePicker) {
                if (this.guidedOrderTypes().length > 1) { this.enterTypeStep(); return; }
                this.flowStep = 'cart';
                this.enterCartMode('last');
            }
        },
        quickAddItem(item) {
            // Kill any in-flight debounced search so it can't repopulate the dropdown
            // under the now-cleared search box after we add the item.
            if (this._searchDebounceTimer) clearTimeout(this._searchDebounceTimer);
            this.handleProductClick(item);
            // GUIDED FLOW (opt-in): first added item moves the indicator off "customer".
            if (this.guidedFlow && this.flowStep === 'customer') this.flowStep = 'items';
            this.searchQuery = ''; this.searchSuggestions = []; this.showSearchDropdown = false;
            this.filterProducts(); this.$nextTick(() => { this.$refs.searchInput?.focus(); });
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
            let items = [...this.allDeals, ...this.allProducts, ...this.allServices];
            items = items.filter(i => parseFloat(i.price) > 0 && i.name && i.name.trim().length > 0);
            // CATEGORY narrows the BROWSABLE grid only (GLOBAL SEARCH, customer request,
            // 22 Jul 2026): while the cashier is typing a search, the grid shows matches from
            // the WHOLE catalog — any category, hidden items included — mirroring the dropdown
            // matcher. The category filter applies only to idle browsing (no search text), and
            // hidden (show_on_sale=false) products stay out of that idle grid too.
            if (!hasSearch) {
                if (this.activeCategory === 'services') { items = this.allServices.filter(s => parseFloat(s.price) > 0 && s.name && s.name.trim().length > 0); }
                else if (this.activeCategory === 'deals') { items = this.allDeals.filter(d => parseFloat(d.price) > 0 && d.name && d.name.trim().length > 0); }
                else if (this.activeCategory !== 'all') { items = this.allProducts.filter(p => p.category === this.activeCategory && parseFloat(p.price) > 0 && p.name && p.name.trim().length > 0); }
                // Effective visibility = per-USER pref ?? admin show_on_sale default
                // (isItemVisible). In GRID EDIT mode, ALL items render (hidden ones
                // greyed) so the user can un-hide them.
                if (!this.gridEditMode) items = items.filter(i => this.isItemVisible(i));
            }
            if (this.searchQuery) {
                const q = this.searchQuery.trim().toLowerCase();
                // STRICT PREFIX (owner, 24 Jul 2026) + MULTI-WORD (16 Aug 2026): same
                // shared matcher as the dropdown (nameMatchRank) — the two surfaces must
                // never diverge. BARCODE/SKU substring matching only when the query has
                // a digit/symbol (letters-only = name search).
                const codeSearch = /[^a-z\s]/.test(q);
                // PER-COMPANY SEARCH MODE (owner, 4 Aug 2026): 'any_word' lets every
                // token match any word; 'prefix' keeps the strict 24 Jul first-token rule.
                const rank = new Map();
                let matches = items.filter(i => {
                    const r = this.nameMatchRank(i.name, q, this.searchAnyWord);
                    if (r > 0) { rank.set(i, r); return true; }
                    return codeSearch && ((i.barcode && String(i.barcode).toLowerCase().includes(q))
                        || (i.sku && String(i.sku).toLowerCase().includes(q)));
                });
                // WORD-START FALLBACK (owner, Aug 2026 — "Win" must find "5 Piece Hot
                // Wings"; menus often lead with sizes/counts): ONLY when the strict-first
                // rule finds NOTHING, rescan in any-word mode. A no-op in any_word mode
                // (same predicate already ran above).
                if (!matches.length) {
                    matches = items.filter(i => {
                        const r = this.nameMatchRank(i.name, q, true);
                        if (r > 0) { rank.set(i, r); return true; }
                        return false;
                    });
                }
                // Name matches float above barcode/SKU-only matches, better (contiguous/
                // in-order) ranks above scattered ones; stable sort keeps the original
                // order within each group.
                matches.sort((a, b) => (rank.get(b) || 0) - (rank.get(a) || 0));
                items = matches;
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
            try {
                localStorage.setItem('pos_show_products', this.showProducts ? '1' : '0');
                localStorage.removeItem('pos_show_products_auto'); // manual choice wins over auto
            } catch (e) {}
            // Grid OFF hides the pills — and on <sm screens the category dropdown is hidden too,
            // so a previously-picked category would become an INVISIBLE search filter. Reset to
            // 'all' (desktop can simply re-pick from the always-visible dropdown).
            if (!this.showProducts && this.activeCategory !== 'all') this.activeCategory = 'all';
            this.filterProducts();
            // Search still works when the grid is hidden — keep suggestions live if a query is active.
            if (this.searchQuery && this.searchQuery.trim().length > 0) { this.onSearchInput(); }
            else { this.searchSuggestions = []; this.showSearchDropdown = false; }
        },

        // Task 753 (Pizza Master, Aug 2026): "products ghayab" DEAD-END GUARDS.
        // (1) Baked catalog EMPTY jabke hum online hain → ghaliban stale offline
        //     SALE_CACHE copy (ya boot payload ka masla). Telemetry beacon chhodo
        //     (live par account-level tashkhees ho sake) aur EK dafa fresh copy
        //     network se le kar sale cache mein daal kar reload karo (session
        //     one-shot guard — kabhi loop nahi; fresh copy bhi empty ho to
        //     reload ka koi faida nahi, isliye skip).
        // (2) Grid manually OFF (localStorage pos_show_products='0') jabke
        //     catalog maujood hai → cashier ko din mein AIK dafa info toast —
        //     deliberate widecart/manual-billing shops roz sirf ek chhota sa
        //     yaad-dahani dekhte hain, magar "products ghayab" wala account
        //     khud ko theek karna seekh leta hai. Auto-off case (sab items
        //     hidden) syncAutoWidecart pehle hi handle/restore karta hai.
        gridDeadEndCheck() {
            try {
                const total = (this.allProducts || []).length + (this.allServices || []).length + (this.allDeals || []).length;
                if (total === 0) {
                    this.printBeacon('empty-catalog-boot', {});
                    if (!navigator.onLine) return;
                    (async () => {
                        try {
                            if (sessionStorage.getItem('tnEmptyCatalogReloaded') === '1') return;
                            const resp = await fetch(window.location.pathname, { cache: 'reload', credentials: 'same-origin' });
                            const ct = (resp && resp.headers.get('content-type')) || '';
                            if (!resp || !resp.ok || resp.redirected || !ct.includes('text/html')) return;
                            const html = await resp.clone().text();
                            if (html.length <= 4096
                                || !html.includes('data-tn-sale-document="pra"')
                                || !html.includes('data-tn-sale-root')
                                || !html.includes('function restaurantPos()')
                                || !html.includes('window.tnBootFp')) return;
                            // Fresh copy bhi khali? (allProducts: [] baked) → reload bekar.
                            if (/allProducts:\s*\[\s*\]/.test(html)) return;
                            if (window.caches) {
                                try {
                                    const names = await caches.keys();
                                    const saleName = names.find(n => n.endsWith('-sale'));
                                    if (saleName) { const c = await caches.open(saleName); await c.put(new Request(window.location.pathname), resp); }
                                } catch (e) {}
                            }
                            sessionStorage.setItem('tnEmptyCatalogReloaded', '1');
                            window.location.reload();
                        } catch (e) { /* offline/flaky — cached screen keeps working */ }
                    })();
                    return;
                }
                if (!this.showProducts && this.visibleGridCount() > 0) {
                    let last = null; try { last = localStorage.getItem('tn_grid_off_notice_day'); } catch (e) {}
                    const day = new Date().toDateString();
                    if (last !== day) {
                        try { localStorage.setItem('tn_grid_off_notice_day', day); } catch (e) {}
                        this.showToast(window.TXT.products_grid_off_notice, 'info');
                    }
                }
            } catch (e) {}
        },

        // Empty-state "Show All Products" rescue — ALSO turns the products grid back ON
        // (persisted), so a cashier who accidentally hit "Products OFF" is never stuck
        // staring at an empty grid with a button that does nothing (Frost & Brew, Jul 2026).
        restoreProductGrid() {
            this.showProducts = true;
            try { localStorage.setItem('pos_show_products', '1'); } catch (e) {}
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
                this.cart.push({ cart_uid: 'c' + Date.now() + '_' + Math.random().toString(36).slice(2,9), item_id: item.id, item_type: item.type, item_name: item.name, quantity: 1, unit_price: parseFloat(item.price), special_notes: '', is_tax_exempt: (item.is_tax_exempt || item.is_third_schedule) || false, is_third_schedule: item.is_third_schedule || false, item_discount_type: 'percentage', item_discount_value: 0, showItemDiscount: false });
                this.activeCartIndex = this.cart.length - 1;
            }
            this.cartAnimating = true; setTimeout(() => this.cartAnimating = false, 300);
            this.scrollToCartItem(this.activeCartIndex);
            // Smart Upsell REMOVED (customer feedback, 25 Jul 2026) — no suggestion fires on add.
        },

        // ──────────────────────────────────────────────────────────────
        // QUICK TYPE MODE — type freeform "chai 2, samosa 1" → cart.
        // Parser supports: "name qty", "qty name", "name" (qty=1).
        // Separators: comma, semicolon, OR newline. Fuzzy product match
        // by case-insensitive substring on this.products[].name.
        // ──────────────────────────────────────────────────────────────
        openQuickType() {
            if (!this.quickTypeEnabled) return; // opt-in feature — OFF companies never open it
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
                    const res = await fetch('{{ route("pos.api.products.quick-create") }}', {
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
            const deals = (this.allDeals || []).filter(d => d && d.name).map(d => ({ ...d, _type: 'deal' }));
            return [...deals, ...products, ...services];
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
                // Grid-hidden items (admin default OR per-user pref) only surface on
                // explicit search — never via the random picker.
                if (!this.isItemVisible(p)) return false;
                if (inv && p.stockStatus === 'out' && this.blockOutOfStock) return false;
                return true;
            });
            if (pool.length === 0) { this.showToast(window.TXT.no_products_available, 'error'); return; }
            const pick = pool[Math.floor(Math.random() * pool.length)];
            this.addToCart({ id: pick.id, type: pick._type || pick.type || 'product', name: pick.name, price: pick.price, is_tax_exempt: pick.is_tax_exempt || pick.is_third_schedule || false, is_third_schedule: pick.is_third_schedule || false });
            this.showToast(window.TXT.random_prefix + pick.name, 'success');
        },

        // ──────────────────────────────────────────────────────────────
        // SMART UPSELL SYSTEM REMOVED (customer feedback, 25 Jul 2026) —
        // cashiers found the popup irritating mid-punching. Do NOT re-add
        // without the owner's explicit go-ahead.
        // ──────────────────────────────────────────────────────────────

        // ──────────────────────────────────────────────────────────────
        // SMART PRODUCT CREATION — Simple POS quick-create + inline price editor.
        // - Only fires when isInventoryEnabled() is FALSE.
        // - Backend route /pos/api/products/quick-create has its own server-side
        //   guard (refuses 422 when inventory is on) — defense in depth.
        // - Inventory ON path shows "Open Products" link instead.
        // ──────────────────────────────────────────────────────────────
        quickCreating: false,
        quickPriceCartUid: null,    // cart_uid of row currently in price-edit mode
        quickPriceValue: '',        // bound to the inline price input
        async quickCreateProduct() {
            if (this.isInventoryEnabled()) return; // belt + suspenders
            const name = (this.searchQuery || '').trim();
            if (!name || this.quickCreating) return;
            this.quickCreating = true;
            try {
                const res = await fetch('{{ route('pos.api.products.quick-create') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ name, price: 0 }),
                });
                const data = await res.json();
                if (!res.ok || !data.ok) {
                    this.showToast(data.error || window.TXT.could_not_create, 'error');
                    return;
                }
                const p = data.product;
                this.allProducts.push(p);
                // Add directly to cart, then mark row + open inline price editor
                this.addToCart({ id: p.id, type: 'product', name: p.name, price: 0, is_tax_exempt: false });
                const cartItem = this.cart[this.cart.length - 1];
                if (cartItem) {
                    cartItem._isQuickCreated = true;
                    cartItem._productId = p.id;
                    this.openQuickPrice(cartItem);
                }
                // GUIDED FLOW (opt-in): first quick-created item moves the indicator off "customer".
                if (this.guidedFlow && this.flowStep === 'customer') this.flowStep = 'items';
                this.searchQuery = '';
                this.searchSuggestions = [];
                this.showSearchDropdown = false;
                this.filterProducts();
            } catch (e) {
                this.showToast(window.TXT.network_error, 'error');
            } finally {
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
                await fetch(`/pos/api/products/${productId}/quick-price`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ price: newPrice }),
                });
            } catch (e) { /* non-fatal — UI already updated */ }
        },
        updateQty(index, delta) {
            if (!this.cart[index]) return;
            let current = Number(this.cart[index].quantity);
            if (!Number.isFinite(current) || current < 1) current = 1;
            let next = Number.isInteger(current)
                ? Math.max(1, current + delta)
                : Math.max(1, Math.round((current + delta) * 100) / 100);
            if (!Number.isFinite(next) || next < 1) next = 1;
            this.cart[index].quantity = next;
            // Force-sync the visible qty box even while it holds focus: some browsers
            // (Safari, touch devices) do NOT move focus onto the +/- button on tap, so
            // the input's x-effect activeElement guard skips the write and the digit
            // on screen looks stale (model/bill were correct, display wasn't).
            this.$nextTick(() => {
                const el = document.querySelector('input[data-qty-row="' + index + '"]');
                if (el) el.value = next;
            });
        },
        setQty(index, val) {
            if (!this.cart[index]) return;
            let v = parseFloat(val);
            if (!Number.isFinite(v) || v < 1) v = 1;
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
            let n = parseFloat(this.cart[index].quantity);
            if (!Number.isFinite(n) || n < 1) n = 1;
            this.cart[index].quantity = Number.isInteger(n) ? n : Math.round(n * 1000) / 1000;
        },

        handleKey(e) {
            // ═══════════════════════════════════════════════════════════════
            // Task 565: PRINT-CONFIRM YES/NO DIALOG — sab se TOPMOST; khula ho
            // to keyboard SIRF isi ka hai. Enter = highlighted choice (Yes
            // default), Tab/arrows = Yes ↔ No, Esc/N = No, Y = Yes. Baqi SAB
            // keys swallow (guided flow / T,D,N shortcuts / search ko kuch na
            // jaye) — band hote hi sab pehle jaisa. stopPropagation zaroori:
            // window-level @keydown.escape.window listeners (receipt popup /
            // board modals) warna Esc par saath hi band ho jate.
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
            // Task 1349: COUNTER PICKER khula ho to keyboard usi ka — Esc/Enter
            // band karein, baqi single keys swallow (guided flow / T,D,N
            // shortcuts / search ko kuch na jaye). Sirf chip click se khulta hai.
            if (this.showTerminalPicker) {
                e.stopPropagation();
                if (e.key === 'Escape' || e.key === 'Enter') { e.preventDefault(); this.showTerminalPicker = false; return; }
                if (/^F\d+$/.test(e.key) || (e.key && e.key.length === 1)) { e.preventDefault(); }
                return;
            }
            // ═══════════════════════════════════════════════════════════════
            // ZFC UNSENT-CART SWITCH PROMPT — TOPMOST modal, owns the keyboard
            // while open (renders above the table picker / board). 1/arrows
            // toggle the highlight, Enter confirms, Esc cancels (old table/
            // type stays exactly as it was; picker stays open behind for the
            // table case). Everything else is swallowed so keys can't leak
            // into the picker/search behind it.
            // ═══════════════════════════════════════════════════════════════
            if (this.tableSwitchPrompt) {
                // Task 1028: recall-dirty prompt bina save-option ke SINGLE action
                // button rakhta hai — arrows/'2' highlight ko us se hata na saken.
                const tswSingle = this.tableSwitchPrompt.kind === 'recall' && !this.tableSwitchPrompt.canSave;
                if (e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'ArrowUp' || e.key === 'ArrowDown' || e.key === 'Tab') { e.preventDefault(); if (!tswSingle) this.tableSwitchIndex = this.tableSwitchIndex === 0 ? 1 : 0; return; }
                if (e.key === '1') { e.preventDefault(); this.tableSwitchIndex = 0; return; }
                if (e.key === '2') { e.preventDefault(); if (!tswSingle) this.tableSwitchIndex = 1; return; }
                if (e.key === 'Enter' && !e.repeat) { e.preventDefault(); this.confirmTableSwitch(this.tableSwitchEnterAction()); return; }
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
                {{-- 10 Aug 2026 (Pizza Master voice note): F2 = "search par le jao" ka
                     universal matlab hai — overlay khula ho tab bhi. Escape jaisa hi
                     exit, warna cashier ko F2 "dead" lagta hai. --}}
                if (e.key === 'F2') { e.preventDefault(); this.flowStep = 'items'; this.enterSearchMode(); return; }
                if (/^F\d+$/.test(e.key) || ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'e'))) { e.preventDefault(); }
                return;
            }
            // ═══════════════════════════════════════════════════════════════
            // DINE-IN TABLE PICKER — owns the keyboard while open (same pattern
            // as the order-type step above). Arrows move the highlight across
            // the flattened floor/table grid, Enter reserves the highlighted
            // table (selectTable resumes the guided chain into cart mode),
            // Esc closes. Everything else is swallowed so keystrokes can't
            // re-open the order-type overlay or type into the search box
            // behind the modal (that stacking is what broke the flow).
            // ═══════════════════════════════════════════════════════════════
            if (this.showTablePicker) {
                const flat = this.tablePickerFlat();
                const n = flat.length;
                if ((e.key === 'ArrowRight' || e.key === 'ArrowDown') && n) { e.preventDefault(); this.tablePickerIndex = (this.tablePickerIndex + 1) % n; return; }
                if ((e.key === 'ArrowLeft' || e.key === 'ArrowUp') && n)  { e.preventDefault(); this.tablePickerIndex = (this.tablePickerIndex - 1 + n) % n; return; }
                if (e.key === 'Enter' && !e.repeat) { e.preventDefault(); const t = flat[this.tablePickerIndex]; if (t) this.selectTable(t); return; }
                if (e.key === 'Escape') { e.preventDefault(); this.showTablePicker = false; return; }
                {{-- 10 Aug 2026 (Pizza Master voice note): Dine-In tab picker khol deta
                     hai aur F2 mar jata tha — cashier pehle items dalna chahta hai.
                     F2 = picker band + seedha search; table baad mein (hold/pay guard
                     picker dobara khol dega). --}}
                if (e.key === 'F2') { e.preventDefault(); this.showTablePicker = false; this.enterSearchMode(); return; }
                if (/^F\d+$/.test(e.key) || ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'e'))) { e.preventDefault(); }
                return;
            }
            // ═══════════════════════════════════════════════════════════════
            // TABLE BOARD MODAL — owns the keyboard while open (same pattern as
            // the table picker above). Alt+B / Esc band karte hain; baqi SAB
            // shortcuts (F4 clear-cart, F8 pay, plain letters → search) board
            // ke peechay LEAK na karein. Agar menu/confirm/pay upar khula ho
            // (z-50) to unke apne handlers chalte hain — yeh block skip.
            // ═══════════════════════════════════════════════════════════════
            if (this.tableBoardOpen && !this.boardMenuTable && !this.boardConfirm && !this.boardShift && !this.showPayModal) {
                if (e.altKey && (e.key === 'b' || e.key === 'B' || e.code === 'KeyB')) { e.preventDefault(); this.tableBoardOpen = false; return; }
                if (e.key === 'Escape') { e.preventDefault(); this.tableBoardOpen = false; return; }
                {{-- 10 Aug 2026: F2 board se bhi seedha search par le jaye (Esc + F2
                     ka shortcut chain torna nahi). --}}
                if (e.key === 'F2') { e.preventDefault(); this.tableBoardOpen = false; this.enterSearchMode(); return; }
                if (/^F\d+$/.test(e.key) || ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'e'))) { e.preventDefault(); }
                return;
            }
            // ═══════════════════════════════════════════════════════════════
            // GLOBAL FUNCTION-KEY SHORTCUTS — fire FIRST, regardless of focus.
            // Without this, search/qty inputs swallow F1-F8 (and F5 would even
            // reload the browser). preventDefault on document-level handler
            // also cancels the browser's native F-key behaviors.
            // ═══════════════════════════════════════════════════════════════
            // Quick Return modal open (Task 681): apna chhota input hai — Esc
            // band karta hai, Enter input ke apne handler par chalta hai; baqi
            // saare global shortcuts (F-keys / Alt chords) is par fire na hon.
            if (this.quickReturnOpen) {
                if (e.key === 'Escape') { e.preventDefault(); this.quickReturnOpen = false; }
                return;
            }
            if (e.key === 'F1') { e.preventDefault(); this.showShortcuts = !this.showShortcuts; return; }
            if (e.key === 'F2') { e.preventDefault(); this.cartMode = false; this.activeCartIndex = -1; this.enterSearchMode(); return; }
            // F3 RETIRED (owner, 26 Jul 2026): held orders TABLE board mein merge
            // ho gaye — koi shortcut window nahi kholta. Key swallow hoti hai
            // (browser find na khule) aur table-companies ko board ka hint milta hai.
            if (e.key === 'F3') { e.preventDefault(); if (this.tableBoardEnabled) { this.showToast(window.TXT.held_orders_on_table_board, 'info'); } return; }
            if (e.key === 'F4') { e.preventDefault(); if (this.cart.length && confirm(window.TXT.clear_entire_cart)) { this.clearCart(); } return; }
            if (e.key === 'F5') { e.preventDefault(); this.holdOrder(); return; }
            if (e.key === 'F6') { e.preventDefault(); if (this.cart.length > 0) { this.enterCartMode('last'); this.mobileView = 'cart'; } return; }
            // F7 → Quick Type (was customer-phone-focus, moved to Alt+P).
            // Opt-in gate: when the company toggle is OFF, F7 is a no-op.
            if (e.key === 'F7') { e.preventDefault(); this.openQuickType(); return; }
            if (e.key === 'F8') { e.preventDefault(); if (this.cart.length) { this.submitting = false; this.showPayModal = true; } return; }
            // F9 → Save Provisional (was Quick Type, moved to F7)
            if (e.key === 'F9') { e.preventDefault(); this.saveProvisionalDirect(); return; }
            // Alt+P → focus customer phone (was F7)
            if (e.altKey && (e.key === 'p' || e.key === 'P')) { e.preventDefault(); this.$refs.customerPhoneInput?.focus(); this.$refs.customerPhoneInput?.select(); return; }
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
            if ((e.key === 't' || e.key === 'T' || e.code === 'KeyT') && !e.ctrlKey && !e.metaKey && !this.showTablePicker && !this.showReprint && !this.boardMenuTable && !this.boardConfirm && !this.boardCancelAsk && !this.boardShift && !this.heldMenu && !this.tableSwitchPrompt) {
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
            // F10 — Open Provisional Bills modal (Local — not yet submitted to PRA).
            // GATED: only fires when no blocking modal is open, otherwise the
            // F10 keystroke would steal focus from Pay/Held/Receipt/etc.
            if (e.key === 'F10') {
                e.preventDefault();
                if (this.showPayModal || this.showReceipt || this.showHeldOrders || this.showQuickType || this.showManualItem || this.showCustomerPicker || this.showShortcuts || this.showManagerPinModal || this.showLocalBills || this.showFailedBills || this.showPendingDeliveries || this.showTablePicker || this.showReprint || this.boardMenuTable || this.boardConfirm || this.boardCancelAsk || this.boardShift || this.heldMenu || this.tableSwitchPrompt) return;
                this.openLocalBills();
                return;
            }
            // F11 — Open Failed Bills modal (PRA submissions that need retry).
            // Same gating as F10. Browser's native F11 = fullscreen toggle is overridden.
            if (e.key === 'F11') {
                e.preventDefault();
                if (this.showPayModal || this.showReceipt || this.showHeldOrders || this.showQuickType || this.showManualItem || this.showCustomerPicker || this.showShortcuts || this.showManagerPinModal || this.showLocalBills || this.showFailedBills || this.showPendingDeliveries || this.showTablePicker || this.showReprint || this.boardMenuTable || this.boardConfirm || this.boardCancelAsk || this.boardShift || this.heldMenu || this.tableSwitchPrompt) return;
                this.openFailedBills();
                return;
            }
            // Alt+B — Toggle the TABLE board MODAL (owner 26 Jul 2026: strip →
            // modal, cart full-size). Sirf tab kholo jab koi doosra overlay
            // (pay/menu/confirm) na khula ho; band karna hamesha chalta hai.
            if (e.altKey && (e.key === 'b' || e.key === 'B' || e.code === 'KeyB') && this.tableBoardEnabled) {
                e.preventDefault();
                if (this.tableBoardOpen) {
                    this.tableBoardOpen = false;
                } else if (!(this.showPayModal || this.showReceipt || this.showHeldOrders || this.showQuickType || this.showManualItem || this.showCustomerPicker || this.showShortcuts || this.showManagerPinModal || this.showLocalBills || this.showFailedBills || this.showPendingDeliveries || this.showTablePicker || this.showReprint || this.boardMenuTable || this.boardConfirm || this.boardCancelAsk || this.boardShift || this.heldMenu || this.tableSwitchPrompt)) {
                    this.tableBoardOpen = true;
                }
                return;
            }
            // Alt+R — Open REPRINT modal (today's bills, click = instant print).
            // Alt-chord (not plain R) so typing names like "Rooh Afza" in the
            // search input is never hijacked. Same modal-gating as F10/F11.
            if (e.altKey && (e.key === 'r' || e.key === 'R' || e.code === 'KeyR')) {
                e.preventDefault();
                if (this.showPayModal || this.showReceipt || this.showHeldOrders || this.showQuickType || this.showManualItem || this.showCustomerPicker || this.showShortcuts || this.showManagerPinModal || this.showLocalBills || this.showFailedBills || this.showPendingDeliveries || this.showTablePicker || this.showReprint || this.boardMenuTable || this.boardConfirm || this.boardCancelAsk || this.boardShift || this.heldMenu || this.tableSwitchPrompt) return;
                this.openReprint();
                return;
            }
            // Alt+1 / Alt+2 — ONE-TAP PAY (owner, 26 Jul 2026): finalize DIRECTLY
            // as CASH / CARD — tax auto-follows the company tax module, no second
            // 8%/16% popup (same as the on-screen CASH/CARD buttons). PAY (F8)
            // keeps the modal. Alt-chord so plain digits keep qty-typing.
            if (e.altKey && (e.code === 'Digit1' || e.code === 'Digit2' || e.key === '1' || e.key === '2')) {
                e.preventDefault();
                if (this.showPayModal || this.showReceipt || this.showHeldOrders || this.showQuickType || this.showManualItem || this.showCustomerPicker || this.showShortcuts || this.showManagerPinModal || this.showLocalBills || this.showFailedBills || this.showPendingDeliveries || this.showTablePicker || this.showReprint || this.boardMenuTable || this.boardConfirm || this.boardCancelAsk || this.boardShift || this.heldMenu || this.tableSwitchPrompt) return;
                if (this.cart.length === 0 || this.submitting) return;
                const oneTapCard = (e.code === 'Digit2' || e.key === '2');
                this.payingHeldOrderId = null;
                this.saveAsProvisional = false;
                this.payMethodIndex = oneTapCard ? 1 : 0;
                // Task 514: one-tap = no checkbox choice — company default, kabhi
                // pichhle (cancel kiye) Pay modal ka stale untick inherit na ho.
                this.payPrintReceipt = this.billPrintDefault(this.orderType);
                this.processPayment(oneTapCard ? 'card' : 'cash');
                return;
            }
            @if($features->kot ?? false)
            // Alt+K — Kitchen mein send karein (owner video, Aug 2026): Alt-chord
            // so plain K keeps typing in search/inputs; same modal-gating as the
            // other Alt chords; guards mirror the button's :disabled exactly.
            // Enter untouched (PAY flow) — kitchen-send ka apna chord hai.
            // Whole block Blade-gated like the button — no KOT feature, no chord.
            if (e.altKey && (e.key === 'k' || e.key === 'K' || e.code === 'KeyK')) {
                e.preventDefault();
                if (this.showPayModal || this.showReceipt || this.showHeldOrders || this.showQuickType || this.showManualItem || this.showCustomerPicker || this.showShortcuts || this.showManagerPinModal || this.showLocalBills || this.showFailedBills || this.showPendingDeliveries || this.showTablePicker || this.showReprint || this.boardMenuTable || this.boardConfirm || this.boardCancelAsk || this.boardShift || this.heldMenu || this.tableSwitchPrompt) return;
                if (this.cart.length === 0 || this.submitting || this.hasManualItems() || this.hasDealItems() || !this.canHold()) return;
                this.sendToKitchen();
                return;
            }
            @endif
            // ═══════════════════════════════════════════════════════════════
            // D / Alt+D — UNIVERSAL DISCOUNT TOGGLE
            // Cart rows v3 (owner, 26 Jul 2026): per-item discount UI removed —
            // D now toggles the BILL-level discount panel (showDiscount) and
            // focuses its input. Same smart routing as T: body / empty search;
            // Alt+D anywhere. SKIPPED when any list-modal is open — those
            // modals own the D key for their delete-row action.
            // ═══════════════════════════════════════════════════════════════
            if ((e.key === 'd' || e.key === 'D' || e.code === 'KeyD') && !e.ctrlKey && !e.metaKey
                && !this.showHeldOrders && !this.showLocalBills && !this.showFailedBills && !this.showPendingDeliveries
                && !this.showPayModal && !this.showReceipt && !this.showQuickType
                && !this.showManualItem && !this.showCustomerPicker && !this.showShortcuts
                && !this.showManagerPinModal && !this.showTablePicker && !this.showReprint && !this.boardMenuTable && !this.boardConfirm && !this.boardCancelAsk && !this.boardShift && !this.heldMenu && !this.tableSwitchPrompt) {
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
                    this.showDiscount = !this.showDiscount;
                    this.showToast(this.showDiscount ? 'Bill discount panel opened' : 'Discount closed', this.showDiscount ? 'info' : 'warning');
                    if (this.showDiscount) {
                        this.$nextTick(() => {
                            const el = this.$refs.billDiscountInput;
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
                && !this.showManagerPinModal && !this.showTablePicker && !this.showReprint && !this.boardMenuTable && !this.boardConfirm && !this.boardCancelAsk && !this.boardShift && !this.heldMenu && !this.tableSwitchPrompt) {
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
                // Bill-note input guard: while typing in the pay modal's note field,
                // ALL modal shortcuts (1/2/arrows/Enter) must type, not fire payments.
                // Enter/Escape blur the field so shortcuts resume after.
                if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-pay-note')) {
                    // Aug 2026: note is a TEXTAREA now — Enter makes a new line (multi-item
                    // notes print numbered on the KOT). Only Esc exits back to shortcuts.
                    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); e.target.blur(); }
                    return;
                }
                // Cash-received input guard: digits must TYPE (not fire 1/2 payments).
                // Enter = confirm CASH payment (keyboard flow stays unbroken); Esc = blur.
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
                // GUIDED FLOW (opt-in): P = save as Provisional (local).
                if (this.guidedFlow && (e.key === 'p' || e.key === 'P')) { e.preventDefault(); e.stopPropagation(); this.saveProvisionalDirect(); return; }
                // Enter confirms the CURRENTLY-HIGHLIGHTED method (Cash by default).
                if (e.key === 'Enter' && !e.repeat) { e.preventDefault(); e.stopPropagation(); this.processPayment(this.payMethodIndex === 1 ? 'card' : 'cash'); return; }
                if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); this.showPayModal = false; return; }
                return;
            }
            if (this.showReceipt) {
                // Any keypress while the popup is up = cashier is interacting — stop the auto-close.
                this.cancelReceiptAutoClose();
                // Esc closes the success popup (per cashier feedback — mouse use was needed).
                // If a browser print dialog is on top, Esc closes that first (native) — second Esc
                // reaches us and dismisses the popup.
                if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); this.closeReceiptPopup(); return; }
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.startNewAfterPayment(); return; }
                if (e.key === 'p' || e.key === 'P') { e.preventDefault(); this.lastIsOffline ? this.printOfflineReceipt() : this.printReceipt(); return; }
                // Task 1379: K mirrors the KOT button exactly — a still-unsent ticket
                // (payment-first release) always fires; only a reprint is refused.
                if ((e.key === 'k' || e.key === 'K') && (this.lastOrderId || this.lastTxnKotId)) { e.preventDefault(); if (!this.canKotReprint && !this.lastKotPending) { this.showToast(window.TXT.kot_reprint_not_allowed, 'error'); return; } this.lastOrderId ? this.printKitchenTicket() : this.printTxnKitchenTicket(this.lastTxnKotId); return; }
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
                else if (e.key === 'd' || e.key === 'D') { e.preventDefault(); const _hkO = this.heldOrders[this.activeHeldIndex]; if (_hkO) { this.boardCancelAsk = this.buildOrderCancelAsk(_hkO); this.boardCancelMade = {}; } }
                else if (e.key === 'Escape') { e.preventDefault(); this.showHeldOrders = false; }
                return;
            }
            // PROMOTE METHOD PICKER — highest priority so keys don't leak to the
            // Local-bills list underneath. ←→/↑↓ move, 1=Cash, 2=Card, Enter=confirm.
            if (this.showPromoteMethod) {
                if (e.key === 'ArrowLeft' || e.key === 'ArrowUp')    { e.preventDefault(); e.stopPropagation(); this.promoteMethodIndex = 0; return; }
                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); e.stopPropagation(); this.promoteMethodIndex = 1; return; }
                if (e.key === '1') { e.preventDefault(); e.stopPropagation(); this.promoteMethodIndex = 0; this.promoteProvisional(this.promoteTarget, 'cash'); return; }
                if (e.key === '2') { e.preventDefault(); e.stopPropagation(); this.promoteMethodIndex = 1; this.promoteProvisional(this.promoteTarget, 'card'); return; }
                if (e.key === '3' || e.key === 'l' || e.key === 'L') { e.preventDefault(); e.stopPropagation(); this.promoteProvisional(this.promoteTarget, null, false); return; }
                if (e.key === 'r' || e.key === 'R') { e.preventDefault(); e.stopPropagation(); this.promoteNoPrint = !this.promoteNoPrint; try{localStorage.setItem('pos_promote_no_print', this.promoteNoPrint ? '1' : '0')}catch(err){} return; }
                if (e.key === 'Enter' && !e.repeat) { e.preventDefault(); e.stopPropagation(); this.promoteProvisional(this.promoteTarget, this.promoteMethodIndex === 1 ? 'card' : 'cash'); return; }
                if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); if (!this.promoteSubmitting) { this.showPromoteMethod = false; this.promoteTarget = null; } return; }
                return;
            }
            // PENDING DELIVERIES panel (Task 114) — Esc closes. Clicks do the
            // work; no list-nav keys so nothing collides with the F10 mappings.
            // Task 543: settle modal sits ABOVE pending-deliveries — Escape closes it FIRST
            if (this.riderSettleBill) {
                if (e.key === 'Escape') { e.preventDefault(); this.riderSettleBill = null; }
                return;
            }
            if (this.showPendingDeliveries) {
                if (e.key === 'Escape') { e.preventDefault(); this.showPendingDeliveries = false; }
                return;
            }
            // PROVISIONAL BILLS modal — keyboard navigation (mirror of held-orders shortcuts)
            // NOTE: always index into filteredLocalBills() (search may be active), never raw localBills.
            if (this.showLocalBills && this.filteredLocalBills().length > 0) {
                const flb = this.filteredLocalBills();
                if (e.key === 'ArrowDown') { e.preventDefault(); this.activeLocalIndex = Math.min(this.activeLocalIndex + 1, flb.length - 1); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); this.activeLocalIndex = Math.max(this.activeLocalIndex - 1, 0); }
                else if (e.key === 'Enter') { e.preventDefault(); if (flb[this.activeLocalIndex]) this.askPromoteMethod(flb[this.activeLocalIndex]); }
                else if ((e.key === 'e' || e.key === 'E') && flb[this.activeLocalIndex]) { e.preventDefault(); window.location.href = '{{ route('pos.invoice.create') }}?edit_bill=' + flb[this.activeLocalIndex].id; }
                else if ((e.key === 'd' || e.key === 'D') && this.posRole !== 'pos_cashier' && flb[this.activeLocalIndex]) { e.preventDefault(); this.deleteProvisional(flb[this.activeLocalIndex]); }
                else if ((e.key === 'k' || e.key === 'K') && flb[this.activeLocalIndex]?.kot_pending) { e.preventDefault(); this.sendProvisionalKot(flb[this.activeLocalIndex]); }
                else if (e.key === 'Escape') { e.preventDefault(); this.showLocalBills = false; }
                return;
            }
            if (this.showLocalBills) {
                if (e.key === 'Escape') { e.preventDefault(); this.showLocalBills = false; }
                return;
            }
            // REPRINT modal — keyboard nav. Typed characters fall through to the
            // search input (no preventDefault on unhandled keys); ↑↓ move the
            // highlight over the FILTERED list, Enter prints it, Esc closes.
            if (this.showReprint) {
                // Preview open (ZFC 30 Jul 2026): Esc = sirf preview band; Enter = print.
                if (this.reprintPreviewBill) {
                    if (e.key === 'Escape') { e.preventDefault(); this.reprintPreviewBill = null; }
                    else if (e.key === 'Enter') { e.preventDefault(); const b = this.reprintPreviewBill; this.reprintPreviewBill = null; this.reprintBill(b); }
                    return;
                }
                const rlist = this.filteredReprintBills();
                if (e.key === 'ArrowDown') { e.preventDefault(); this.activeReprintIndex = Math.min(this.activeReprintIndex + 1, Math.max(0, rlist.length - 1)); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); this.activeReprintIndex = Math.max(this.activeReprintIndex - 1, 0); }
                else if (e.key === 'Enter') { e.preventDefault(); if (rlist[this.activeReprintIndex]) this.reprintBill(rlist[this.activeReprintIndex]); }
                else if (e.key === 'Escape') { e.preventDefault(); this.showReprint = false; }
                return;
            }
            // FAILED BILLS modal — keyboard nav (mirror of provisional + held shortcuts)
            if (this.showFailedBills && this.failedBills.length > 0) {
                if (e.key === 'ArrowDown') { e.preventDefault(); this.activeFailedIndex = Math.min(this.activeFailedIndex + 1, this.failedBills.length - 1); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); this.activeFailedIndex = Math.max(this.activeFailedIndex - 1, 0); }
                else if (e.key === 'Enter') { e.preventDefault(); this.retryFailed(this.failedBills[this.activeFailedIndex]); }
                else if (e.key === 'e' || e.key === 'E') { e.preventDefault(); window.location.href = '{{ url('/pos/transaction') }}/' + this.failedBills[this.activeFailedIndex].id + '/edit?from=sale'; }
                else if (e.key === 'd' || e.key === 'D') { e.preventDefault(); this.deleteFailed(this.failedBills[this.activeFailedIndex]); }
                else if (e.key === 'Escape') { e.preventDefault(); this.showFailedBills = false; }
                return;
            }
            if (this.showFailedBills) {
                if (e.key === 'Escape') { e.preventDefault(); this.showFailedBills = false; }
                return;
            }
            if (this.showManagerPinModal) {
                if (e.key === 'Escape') { e.preventDefault(); this.showManagerPinModal = false; }
                return;
            }

            // (F-keys hoisted to top of handleKey above — kept here as no-op
            //  comment so future readers understand the routing.)

            // (Smart Upsell Enter/Esc priority block removed — 25 Jul 2026.)

            if (e.key === 'Escape') {
                // heldMenu ka apna @keydown.escape.window hai — fallback chain
                // yahan na chale warna search/category bhi saath clear ho jate.
                if (this.heldMenu) { return; }
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

        clearCart() { if (this.selectedTable) this.releaseTable(this.selectedTable.id); this.cart = []; this.payAttemptUuid = null; this.holdAttemptUuid = null; this.kitchenNotes = ''; this.showCartNote = false; this.selectedTable = null; this.orderType = 'takeaway'; this.selectedCustomer = null; this.customerStats = null; this.customerPhoneQuery = ''; this.customerPhoneResults = []; this.customerPhoneDropdown = false; this.stockError = ''; this.priorityOrder = false; this.recalledOrderId = null; this.recalledOrderMeta = null; this.incomingOrderId = null; this.incomingOrderInfo = null; this.discountType = 'percentage'; this.discountValue = 0; this.discountAmount = 0; this.showDiscount = false; this.managerOverrideActive = false; this.activeCartIndex = -1; this.cartMode = false; this.flowStep = 'customer'; this.deliveryChargeInput = ''; this.deliveryPrepaid = false; this.customerAddresses = []; this.selectedDeliveryAddress = ''; this.showAddrNew = false; this.newAddrText = ''; this.newAddrLabel = ''; this._recallCartBaseline = null; this.fixCartIndex(); this.clearCartStorage(); },
        newSale() {
            if (this.cart.length > 0) { if (!confirm(window.TXT.current_order_has + this.cart.length + ' item(s). Discard and start new sale?')) return; }
            this.clearCart(); this.showToast(window.TXT.new_sale_started, 'success');
        },
        voidOrder() {
            if (this.cart.length === 0) return;
            if (!confirm(window.TXT.void_current_order_q)) return;
            this.clearCart(); this.showToast(window.TXT.order_voided, 'success');
        },
        // ── ZFC unsent-cart switch guard (Aug 2026) ───────────────────────────────
        // Live bug (ZFC Pizza Point videos): cashier T-1 par item dalta hai — kuch
        // send/hold NAHI kiya — table ya order-type badalta hai to wahi item chupke
        // se naye context mein baitha milta hai. Fix: explicit choice. Discard SIRF
        // cashier ke choose par hota hai (owner ka "cart kabhi discard na ho" rule
        // silent discard par hai — explicit choice uske andar hai).
        hasUnsentCart() {
            // Edit/recall/waiter-claim carts belong to a REAL stored order — the
            // guard must never fire there (Table Shift covers held orders).
            return this.cart.length > 0 && !this.editingBillId && !this.recalledOrderId && !this.incomingOrderId;
        },
        openTableSwitchPrompt(target) {
            this.tableSwitchPrompt = target;
            this.tableSwitchIndex = 0;
            // Blur so a focused search/qty input can't swallow the prompt's keys.
            try { document.activeElement?.blur(); } catch(_) {}
        },
        tableSwitchTargetLabel() {
            const p = this.tableSwitchPrompt;
            if (!p) return '';
            if (p.kind === 'table' || p.kind === 'recall') return window.TXT.table_t_prefix2 + p.table.table_number;
            return p.type === 'delivery' ? window.TXT.delivery : window.TXT.takeaway;
        },
        // ── Task 1028 (Task 975 hissa 3): recall/claim ke baad cart-edit dirty check ──
        // Recall/claim par cart ka lightweight fingerprint snapshot hota hai
        // (_recallCartBaseline); doosre table par switch se pehle compare — item
        // add/remove, qty, unit price, per-item notes/discount, kitchen notes,
        // priority aur bill-level discount sab pakre jate hain. SIRF wohi fields
        // jo recall/claim SYNC set karta hai — customer/delivery-address nahi
        // (unke async loaders bina-tabdeeli recall par false-positive bana dete).
        cartEditFingerprint() {
            return JSON.stringify({
                items: this.cart.map(i => [i.item_id ?? null, i.item_type || '', i.item_name || '', Number(i.quantity) || 0, Number(i.unit_price) || 0, i.special_notes || '', !!i.is_tax_exempt, i.item_discount_type || 'percentage', Number(i.item_discount_value) || 0]),
                notes: this.kitchenNotes || '',
                priority: !!this.priorityOrder,
                dtype: this.discountType || 'percentage',
                dval: Number(this.discountValue) || 0,
            });
        },
        recalledCartDirty() {
            if (!(this.recalledOrderId || this.incomingOrderId)) return false;
            // Snapshot hi na ho (purane path se aaya state) → pichhle task (1027)
            // ka behaviour: reset+switch. Dirty sirf PROVE hone par.
            if (!this._recallCartBaseline) return false;
            return this.cartEditFingerprint() !== this._recallCartBaseline;
        },
        // Save-first option sirf wahan jahan Hold/F5 sach mein chalega: claimed
        // waiter orders payment par settle hote hain (P7 re-hold guard), manual/
        // deal lines aur non-dine-in types holdOrder khud reject karta hai —
        // un cases mein prompt sirf discard/cancel dikhata hai.
        canSaveRecalledEdits() {
            return !!this.recalledOrderId && !this.incomingOrderId && !this.editingBillId
                && this.canHold() && !this.hasManualItems() && !this.hasDealItems()
                && !(this.tableBoardEnabled && this.orderType === 'dine_in' && !this.selectedTable);
        },
        // Enter → action mapping (document handler + search-input race path dono
        // isi se guzarte hain taake mapping kabhi diverge na ho).
        tableSwitchEnterAction() {
            const p = this.tableSwitchPrompt;
            if (p && p.kind === 'recall') return (p.canSave && this.tableSwitchIndex === 0) ? 'save' : 'discard';
            return this.tableSwitchIndex === 1 ? 'discard' : 'move';
        },
        // Lighter than clearCart(): sirf UNSENT items + unke saath chalne wala
        // state saaf hota hai (kitchen notes, discount, delivery charge input,
        // cart focus) — customer selection aur table release yahan NAHI chhede
        // jaate (caller hi table/type ka faisla karta hai). Persisted cart
        // storage bhi saaf — offline persistence items wapas na le aaye.
        discardUnsentCart() {
            this.cart = [];
            this.kitchenNotes = '';
            this.showCartNote = false;
            this.priorityOrder = false;
            this.stockError = '';
            this.discountType = 'percentage';
            this.discountValue = 0;
            this.discountAmount = 0;
            this.showDiscount = false;
            this.deliveryChargeInput = '';
            this.activeCartIndex = -1;
            this.cartMode = false;
            this.fixCartIndex();
            this.clearCartStorage();
        },
        async confirmTableSwitch(action) {
            const p = this.tableSwitchPrompt;
            if (!p) return;
            this.tableSwitchPrompt = null;
            // Task 1028: recall/claim ke BAAD edited cart + doosre table par click.
            // 'save' = maujooda Hold/F5 flow (recalled order update + KOT delta/void
            // chain wahi ka wahi), phir usi table-click ka normal flow aage chale.
            // 'discard' = held/claimed row DB/table par jyon-ka-tyon — sirf screen
            // ki UNSAVED tabdeeli jati hai (clearCart), phir switch.
            if (p.kind === 'recall') {
                if (action === 'save') {
                    const held = await this.holdOrder({ stayOnScreen: true });
                    // Save fail (network/422): cart + tabdeeli barqarar — cashier
                    // dobara try kar sakta hai; switch cancel ho jata hai.
                    if (!held) return;
                    await this.selectTable(p.table, { skipSwitchPrompt: true });
                    return;
                }
                this.clearCart();
                this.showToast(window.TXT.recall_discard_done, 'info');
                await this.selectTable(p.table, { skipSwitchPrompt: true });
                return;
            }
            if (action === 'discard') this.discardUnsentCart();
            // Re-run the original move with the prompt skipped — "move" then
            // follows today's exact path (reserve + dine_in_auto_kot etc.),
            // "discard" reserves/switches with an empty cart.
            if (p.kind === 'table') { await this.selectTable(p.table, { skipSwitchPrompt: true }); }
            else { this.setOrderType(p.type, { skipSwitchPrompt: true }); }
        },
        // ── Dine-In Select-Table picker (Jul 2026) ────────────────────────────────
        // Dine In pill → picker opens (if no table yet). Selecting a table
        // RESERVES it server-side (race-safe; 409 if another cashier got it).
        // Reservation auto-frees on: bill stored (backend, final+provisional),
        // void/new-sale/clear-cart, or switching to Takeaway/Delivery.
        setOrderType(type, opts) {
            // ZFC (Aug 2026): dine-in table + UNSENT cart → Takeaway/Delivery par
            // jaate hue explicit move/discard choice. Runs FIRST — even
            // removeDeliveryCharge must not touch the cart before the cashier
            // chooses. Cancel/Esc = dine-in + table bilkul jaisa tha.
            if (!(opts && opts.skipSwitchPrompt) && type !== 'dine_in' && this.selectedTable && this.hasUnsentCart()) {
                this.openTableSwitchPrompt({ kind: 'type', type });
                return;
            }
            // ZFC (5 Aug 2026, video): table ka RECALLED/claimed order khula chhor
            // kar Takeaway/Delivery par jao to purana cart chipka rehta tha aur
            // naya order usi mein ghul jata. Ab: order table par mehfooz hai
            // (held/claimed row ko kuch nahi hota) — screen saaf ho kar nayi sale
            // ke liye tayyar. Manual (bina recall) cart upar wale explicit-choice
            // prompt se guzarta hai — wahan silent discard ab bhi nahi hota.
            if (type !== 'dine_in' && (this.recalledOrderId || this.incomingOrderId) && this.cart.length) {
                this.clearCart(); // held order DB/table par jyon-ka-tyon; sirf screen reset
                this.orderType = type;
                if (type === 'delivery' && this.selectedCustomer && !this.customerAddresses.length) this.loadCustomerAddresses();
                this.showToast(window.TXT.type_switch_order_safe, 'info');
                return;
            }
            // Item #3: the delivery-charge line only belongs to Delivery orders —
            // leaving the type removes it so it can never bill on dine-in/takeaway.
            if (type !== 'delivery') this.removeDeliveryCharge();
            // Task 287: prepaid toggle is delivery-only — reset when leaving delivery.
            if (type !== 'delivery') this.deliveryPrepaid = false;
            if (type === 'dine_in') {
                // 26 Jul 2026: table selected ho tab BHI picker khole — pill par
                // dobara click = table change ka raasta (top Table button retire).
                const reopen = this.orderType === 'dine_in' && this.selectedTable;
                this.orderType = 'dine_in';
                if (!this.selectedTable || reopen) this.openTablePicker();
                return;
            }
            if (this.selectedTable) { this.releaseTable(this.selectedTable.id); this.selectedTable = null; }
            this.orderType = type;
            // Item #1: entering Delivery with a customer already picked → pull their
            // saved addresses so the picker is ready without an extra click.
            if (type === 'delivery' && this.selectedCustomer && !this.customerAddresses.length) this.loadCustomerAddresses();
        },

        // ── Order-type flow rules (owner, Jul 2026) ────────────────────────────
        // Gated on typeFlowGate so plain retail (no order-type widget) keeps the
        // old behaviour. Restaurant companies: Hold/KOT = Dine-In procedure only;
        // provisional bills = Delivery only; Takeaway = direct final bill only.
        canHold() { return !this.typeFlowGate || this.orderType === 'dine_in'; },
        canProvisional() { return !this.typeFlowGate || this.orderType === 'delivery'; },

        // ── Item #3: Delivery charges (owner, Jul 2026) ────────────────────────
        // One synthetic MANUAL cart line (item_id=null → _manual:true server-side,
        // no master-product auto-create), TAX-EXEMPT, qty pinned to 1. Manual lines
        // already route Pay through processPaymentManual and are blocked from
        // restaurant hold/KOT — exactly the behaviour we want for a delivery fee.
        setDeliveryCharge() {
            const amt = Math.max(0, Math.round(parseFloat(this.deliveryChargeInput) || 0));
            const idx = this.cart.findIndex(c => c && c._delivery);
            if (amt <= 0) { this.removeDeliveryCharge(); return; }
            this.deliveryChargeInput = amt;
            if (idx >= 0) {
                this.cart[idx].unit_price = amt;
                this.cart[idx].quantity = 1;
            } else {
                this.cart.push({ cart_uid: 'c' + Date.now() + '_' + Math.random().toString(36).slice(2,9), item_id: null, item_type: 'manual', _delivery: true, item_name: 'Delivery Charges', quantity: 1, unit_price: amt, special_notes: '', is_tax_exempt: true, item_discount_type: 'percentage', item_discount_value: 0, showItemDiscount: false });
            }
        },
        removeDeliveryCharge() {
            const idx = this.cart.findIndex(c => c && c._delivery);
            if (idx >= 0) { this.cart.splice(idx, 1); this.fixCartIndex(); }
            this.deliveryChargeInput = '';
        },

        // ── Item #1: Customer multi-address (owner, Jul 2026) ─────────────────
        // pos_customers.address = "address #1"; extras live in pos_customer_addresses.
        // The chosen text is a SNAPSHOT on the bill (pos_transactions.delivery_address)
        // so later address edits never rewrite old receipts. Walk-in customers (no id)
        // can still type a one-off address — it snapshots without being saved.
        async loadCustomerAddresses() {
            this.customerAddresses = []; this.selectedDeliveryAddress = ''; this.showAddrNew = false; this.newAddrText = ''; this.newAddrLabel = '';
            const c = this.selectedCustomer;
            if (!c || !c.id) return;
            try {
                const res = await fetch('/pos/api/customer-addresses?customer_id=' + c.id, { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                this.customerAddresses = Array.isArray(data.addresses) ? data.addresses : [];
                if (this.customerAddresses.length) this.selectedDeliveryAddress = this.customerAddresses[0].address;
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
                const res = await fetch('/pos/api/customer-addresses', {
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
        // ZFC (Aug 2026): delete the SELECTED saved address from the sale screen.
        // id=0 = customer's default address (cleared, not row-deleted); walk-in
        // one-off entries (id=null) are local-only, just dropped from the list.
        async deleteSelectedAddress() {
            const sel = this.selectedDeliveryAddress;
            // Duplicate texts (review catch): if the same address text exists as
            // both the Default and an extra row, delete the EXTRA row first —
            // never silently clear the default when an equivalent copy exists.
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
                const res = await fetch('/pos/api/customer-addresses/delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ customer_id: c.id, id: a.id }),
                });
                const data = await res.json().catch(() => null);
                if (data && data.success) {
                    drop();
                    if (a.id === 0 && this.selectedCustomer) this.selectedCustomer.address = '';
                    this.showToast(window.TXT.address_deleted, 'success');
                } else {
                    this.showToast((data && data.message) || window.TXT.failed_word, 'error');
                }
            } catch (e) { this.showToast(window.TXT.network_error, 'error'); }
        },
        openTablePicker() {
            this.showTablePicker = true;
            this.tablePickerIndex = 0;
            // Blur any focused input so the picker's keyboard branch in handleKey
            // (arrows/Enter/Esc) owns every keystroke — otherwise the search box
            // keeps eating keys behind the modal and the guided chain dead-ends.
            document.activeElement?.blur();
            this.loadTableStatus();
            // Table-se-Bill (Jul 2026): waiter orders render inside THIS picker
            // (purple "Order Tayyar" tables + tableless counter orders) — refresh
            // the incoming list on every open so the cards are current.
            this.loadIncoming();
        },
        // Held waiter order sitting on this table (visibility already enforced
        // server-side: cashiers see own+unassigned, admins all). At most one
        // held waiter order per table — storeOrder rejects occupied tables.
        incomingForTable(t) {
            // Number() dono taraf: live MySQL PDO table_id ko STRING deta hai
            // ("56"), dev int — strict === live par purple tile ko marta tha.
            return this.incomingOrders.find(o => o.table_id != null && Number(o.table_id) === Number(t.id)) || null;
        },
        tablelessIncoming() {
            return this.incomingOrders.filter(o => !o.table_id);
        },
        // Flattened table list in visual order (floor by floor) — drives the
        // keyboard highlight in the picker. Recomputed live so a status refresh
        // can't desync highlight from the rendered grid.
        tablePickerFlat() { return this.tableFloors.flatMap(f => f.tables); },
        // Elapsed label since a timestamp — "3m" / "1h 20m" / "just now".
        // Reads nowTick so labels refresh live on the 30s tick.
        elapsedSince(ts) {
            if (!ts) return '';
            const ms = this.nowTick - new Date(ts).getTime();
            if (isNaN(ms) || ms < 0) return '';
            const mins = Math.floor(ms / 60000);
            if (mins < 1) return 'just now';
            const h = Math.floor(mins / 60), m = mins % 60;
            return h > 0 ? (h + 'h ' + m + 'm') : (m + 'm');
        },
        // Task 1109: If-None-Match ETag fast-path — 304 means floor hasn't
        // changed; skip the body parse and leave tableFloors untouched.
        async loadTableStatus() {
            this.tablesLoading = true;
            try {
                const hdrs = { 'Accept': 'application/json' };
                if (this._tableEtag) hdrs['If-None-Match'] = this._tableEtag;
                const res = await fetch('/pos/restaurant/api/table-status', { headers: hdrs });
                if (res.status === 304) { this.tablesLoading = false; return; }
                if (!res.ok) { this.tablesLoading = false; return; }
                const etag = res.headers.get('ETag');
                if (etag) this._tableEtag = etag;
                const list = await res.json();
                const groups = {};
                (Array.isArray(list) ? list : []).forEach(t => {
                    const f = t.floor || 'Main';
                    (groups[f] = groups[f] || []).push(t);
                });
                this.tableFloors = Object.keys(groups).map(name => ({ name, tables: groups[name] }));
            } catch (e) { console.error('[tables] status load failed', e); }
            this.tablesLoading = false;
        },
        // Task 899 / 935: cross-terminal held-orders sync — refreshes this.heldOrders from
        // the server every 25 s. Works BOTH directions: a cancel on Tab B disappears here
        // within one cycle, AND a new hold on Tab B appears here within one cycle (full
        // server list replaces local list). Only preserves the local copy of a recalled
        // order actively being edited (recalledOrderId) to avoid clobbering work.
        // Task 1097: If-None-Match ETag fast-path — 304 means nothing changed; skip body.
        async loadHeldOrders() {
            try {
                const hdrs = { 'Accept': 'application/json' };
                if (this._heldEtag) hdrs['If-None-Match'] = this._heldEtag;
                const res = await fetch('/pos/restaurant/api/held-orders', { headers: hdrs });
                if (res.status === 304) return;
                if (!res.ok) return;
                const etag = res.headers.get('ETag');
                if (etag) this._heldEtag = etag;
                const fresh = await res.json();
                if (!Array.isArray(fresh)) return;
                // Preserve any recalled order that is actively being edited in the cart
                // so a concurrent server-side update doesn't stomp the cashier's work.
                const recalled = this.recalledOrderId ? Number(this.recalledOrderId) : null;
                if (recalled) {
                    // Keep the local copy of the recalled order; replace everything else.
                    const localRecalled = this.heldOrders.find(o => Number(o.id) === recalled);
                    const merged = fresh.filter(o => Number(o.id) !== recalled);
                    if (localRecalled) merged.push(localRecalled);
                    this.heldOrders = merged;
                } else {
                    this.heldOrders = fresh;
                }
                // Clamp the held-orders modal cursor so it never points past the list end.
                if (this.activeHeldIndex >= this.heldOrders.length) {
                    this.activeHeldIndex = Math.max(0, this.heldOrders.length - 1);
                }
                if (this.heldOrders.length === 0 && this.showHeldOrders) {
                    this.showHeldOrders = false;
                    this.activeHeldIndex = 0;
                }
            } catch (e) { /* silent — stale list is better than a toast flood */ }
        },
        async selectTable(table, opts) {
            // Table-se-Bill (Jul 2026) + ZFC (5 Aug 2026): occupied table WITH a
            // waiting waiter order ab bhi WAHI options menu kholta hai jo desktop
            // wale table ko milta hai (Open/Edit, Proof Bill, Make FINAL, Shift) —
            // pehle seedha cart+settle par utarta tha aur ek ghalat click par
            // FINAL ho jaane ka khatra tha (ZFC video). Board-menu ke saare
            // actions waiter orders ko pehle se atomic-claim karte hain, is liye
            // single-winner invariant barqarar. Agar table-status mein order abhi
            // nahi aya (poll lag) to purana direct-claim path hi chalta hai.
            const inc = this.incomingForTable(table);
            // Task 1026: cart mein USI table ka recalled/claimed order khula ho
            // to us table par click = editing RESUME — sirf picker band, koi
            // warning nahi, dobara claim nahi (order pehle se cart mein hai).
            // Match order-id se (occupied tile ka table.order YA purple tile ka
            // inc), aur fallback table-id se — lekin sirf RECALLED order ke
            // liye (recall hi selectedTable set karta hai; claimAndLoadIncoming
            // selectedTable ko chhoota hi nahi, is liye claimed cart ke saath
            // selectedTable koi PURANI reservation ho sakti hai — us par match
            // karna ghalat "resume" hota aur picker bas band ho jata). Recalled
            // fallback stale table-status poll ko dead-end banne se rokta hai.
            // Number() dono taraf — live PDO ids ko STRING deta hai.
            const cartOrderId = this.recalledOrderId || this.incomingOrderId;
            if (cartOrderId && (
                (table.order && Number(table.order.id) === Number(cartOrderId)) ||
                (inc && Number(inc.id) === Number(cartOrderId)) ||
                (this.recalledOrderId && this.selectedTable && Number(this.selectedTable.id) === Number(table.id))
            )) { this.showTablePicker = false; return; }
            // Task 1027 (Task 975 hissa 2): cart mein kisi AUR table ka
            // recalled/claimed order khula ho aur cashier DOOSRE table par
            // click kare — pehle sirf warning toast milta tha aur cashier
            // phans jata tha (Table 2 ka bill khulta hi nahi tha). Ab
            // setOrderType precedent: pehla order apne table/DB par mehfooz
            // hai (held/claimed row ko kuch nahi hota) — screen reset karo
            // aur usi click ka NORMAL empty-cart flow neeche chalne do
            // (occupied → board menu / direct open, purple → waiter-claim,
            // free → reserve; dine_in_auto_kot + guided flow waise hi jaise
            // khali cart par). Manual (bina recall) bhara cart neeche wale
            // warning/prompt paths se hi guzarta hai — owner ka "cart kabhi
            // silently discard na ho" rule unhi ke liye hai.
            if (cartOrderId && this.cart.length) {
                // Task 1028 (Task 975 hissa 3): recall/claim ke BAAD cart mein
                // tabdeeli hui ho (item add/remove, qty, notes, discount) to
                // seedha reset cashier ki mehnat chupke se kha jata — pehle
                // explicit choice (save-first / discard / cancel, tableSwitchPrompt
                // pattern). Bina tabdeeli wala recall pichhle task jaisa seedha
                // reset+switch hi rehta hai (koi extra prompt nahi).
                if (!(opts && opts.skipSwitchPrompt) && this.recalledCartDirty()) {
                    this.openTableSwitchPrompt({ kind: 'recall', table, canSave: this.canSaveRecalledEdits() });
                    return;
                }
                this.clearCart(); // order DB/table par jyon-ka-tyon; sirf screen reset
                this.showToast(window.TXT.type_switch_order_safe, 'info');
            }
            if (inc) {
                if (table.order) {
                    // Task 867 (Aug 2026): table-status poll stale ho sakta hai — table.order
                    // purana dine-in show kare jabke waiter ka NAYA order bell-panel mein already
                    // aa chuka ho. inc (incomingOrders se, har 20s refresh) HAMESHA fresher hai.
                    // Task 940 (Aug 2026): stale occupied + fresh inc → HAMESHA claimAndLoadIncoming
                    // (tableClickDirectOpen ka check hatao — warna default cashiers ke liye tile
                    // purple dikhta hai lekin click par openBoardMenu khulta tha, claim hota hi
                    // nahi tha). Non-empty cart: warning barqarar (cart kabhi silently discard na ho).
                    if (this.cart.length === 0) { this.showTablePicker = false; await this.claimAndLoadIncoming(inc); return; }
                    this.showToast(window.TXT.table_t_prefix2 + table.table_number + window.TXT.table_occupied_cart_hint, 'warning'); return;
                }
                await this.claimAndLoadIncoming(inc); return;
            }
            if (table.status === 'occupied') {
                // 26 Jul 2026 (owner item 5): khali cart + occupied tile = board
                // ACTION MENU (view/final/KOT/shift) yahin picker se. Bhara cart
                // par warning (view-only rule — cart kabhi discard na ho). ZFC
                // (3 Aug 2026): warning ab AGLA QADAM batati hai — sirf "masroof
                // hai" se cashier samajhta tha ke table kharab/phansa hua hai.
                // Task 781: flag ON = popup skip, order seedha edit mode mein.
                if (this.cart.length === 0) { this.showTablePicker = false; if (this.tableClickDirectOpen) { await this.directOpenTable(table); } else { this.openBoardMenu(table); } return; }
                this.showToast(window.TXT.table_t_prefix2 + table.table_number + window.TXT.table_occupied_cart_hint, 'warning'); return;
            }
            // ZFC (Aug 2026): table ALREADY selected + a DIFFERENT free table +
            // unsent cart → explicit move/discard choice BEFORE reserving (cancel
            // must never leave a zombie reservation on the new table). First-time
            // table pick (no selectedTable) stays prompt-free — normal flow,
            // dine_in_auto_kot and the guided Enter-chain run exactly as before.
            if (!(opts && opts.skipSwitchPrompt) && this.selectedTable && this.selectedTable.id !== table.id && this.hasUnsentCart()) {
                this.openTableSwitchPrompt({ kind: 'table', table });
                return;
            }
            try {
                const res = await fetch('/pos/restaurant/tables/' + table.id + '/reserve', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                let data = null; try { data = await res.json(); } catch(_) {}
                if (!res.ok || !data || !data.success) {
                    this.showToast((data && data.message) || window.TXT.table_unavailable, 'error');
                    this.loadTableStatus(); // refresh — someone else may have taken it
                    return;
                }
            } catch (e) { this.showToast(window.TXT.could_not_reserve_table_conn, 'error'); return; }
            if (this.selectedTable && this.selectedTable.id !== table.id) this.releaseTable(this.selectedTable.id);
            this.selectedTable = { id: table.id, table_number: table.table_number, seats: table.seats };
            this.orderType = 'dine_in';
            this.showTablePicker = false;
            this.showToast(window.TXT.table_t_prefix2 + table.table_number + window.TXT.reserved_suffix, 'success');
            // Dine-In Auto KOT (owner, Jul 2026): with the setting ON and a filled
            // cart, table select is the LAST step — the order auto-holds, the KOT
            // auto-fires, and the bill lands in Recall. Skips when the cart is
            // empty (table picked before products), while editing a bill, when a
            // waiter order is loaded, or with manual/deal lines (billing-only —
            // hold would 422). On failure fall through to the normal flow so the
            // cashier keeps the cart and can press Hold/F5 manually.
            if (this.kitchenSettings.dine_in_auto_kot && this.cart.length > 0 && !this.editingBillId && !this.incomingOrderId && !this.hasManualItems() && !this.hasDealItems()) {
                const held = await this.holdOrder({ forcePrintKot: true, successMessage: 'T-' + table.table_number + ' — KOT sent, bill saved in Recall' });
                if (held) return;
            }
            // Guided keyboard flow paused at the type step waiting for a table —
            // resume the chain into cart mode now that the table is locked in.
            if (this.guidedFlow && this.flowStep === 'cart' && !this.cartMode) this.enterCartMode('last');
        },
        // Table-se-Bill (Jul 2026): atomic claim then cart-load. The claim response
        // carries a FRESH order snapshot (waiter may have appended items after our
        // poll) — always build the cart from that, not the stale polled object.
        async claimAndLoadIncoming(o) {
            if (this._claimBusy) return;
            this._claimBusy = true;
            try {
                const res = await fetch('/pos/api/incoming-orders/' + o.id + '/claim', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                });
                let data = null; try { data = await res.json(); } catch (_) {}
                if (!res.ok || !data || !data.success) {
                    this.showToast((data && data.message) || window.TXT.order_taken_by_other_cashier, 'warning');
                    this.loadIncoming(); this.loadTableStatus();
                    return;
                }
                this.loadIncomingToCart(data.order || o);
                this.showTablePicker = false;
                if (this.tableBoardEnabled) this.loadTableStatus(); // Table Board: tile turns "mine"
                // Guided keyboard flow: resume the Enter-chain into cart mode so
                // Table picker → Enter on the purple card lands on the first cart row
                // (mirrors the reserve branch's enterCartMode resume).
                if (this.guidedFlow && !this.cartMode && this.cart.length > 0) this.enterCartMode(0);
            } catch (e) {
                this.showToast(window.TXT.could_not_load_order_conn, 'error');
            } finally { this._claimBusy = false; }
        },
        // Task #409 (owner, 10 Aug 2026): cancel a waiter order straight from the
        // ghanti (incoming) panel — the ONLY surface where waiter takeaway/delivery
        // orders live. Soft-cancel (deleteOrder) → Cancelled Orders report with
        // cancelled_by; table (if any) freed server-side.
        // Task #645 (owner voice note 13 Aug 2026): plain confirm() ki jagah wohi
        // shared cancel-warning modal — takeaway/delivery cancel par bhi Made/Not-Made
        // toggles, taake kitchen waste report mein aaye. Confirm = boardCancelConfirm
        // (same deleteOrder endpoint + made_item_ids), jo incoming/held lists khud
        // filter karta hai.
        cancelIncoming(o) {
            if (this._claimBusy || !o) return;
            this.boardCancelAsk = this.buildOrderCancelAsk(o);
            this.boardCancelMade = {};
        },
        // Shared builder (bell panel + claimed cart): normalizes an orderJson-style
        // snapshot into the modal's { table, order, items, noTicks } shape. noTicks
        // is DERIVED — stale clients/carts without real item row ids fall back to
        // no-toggles (KOT alert still shows) instead of posting junk ids.
        buildOrderCancelAsk(o) {
            const items = ((o && o.items) || []).map((it, ix) => ({
                id: it.id || ('x' + ix),
                _real: !!it.id,
                quantity: it.quantity,
                item_name: it.name || it.item_name || '',
                subtotal: it.subtotal != null ? it.subtotal : ((parseFloat(it.unit_price) || 0) * (parseFloat(it.quantity) || 0)),
            }));
            return {
                table: null,
                noTicks: !items.some(it => it._real),
                order: {
                    id: o.id,
                    order_number: o.order_number || null,
                    order_type: o.order_type || null,
                    total_amount: o.total_amount || 0,
                    kot_sent_at: o.kot_sent_at || null,
                },
                items: items,
            };
        },
        // Task #643: Order Cancel for the CLAIMED waiter order in the cart —
        // reuses the boardCancelAsk warning modal (items + KOT alert) with a
        // null table; confirm posts the same deleteOrder soft-cancel.
        cartCancelIncoming() {
            const o = this.incomingOrderInfo;
            if (!this.incomingOrderId || !this.canOrderCancel) return;
            // Task #645: claim snapshot now carries real item row ids (orderJson),
            // so Made/Not-Made toggles work here too; buildOrderCancelAsk degrades
            // to noTicks for stale pre-#645 snapshots restored from localStorage.
            this.boardCancelAsk = this.buildOrderCancelAsk(Object.assign({}, o || {}, {
                id: this.incomingOrderId,
                total_amount: (o && o.total_amount) || this.totalAmount || 0,
            }));
            this.boardCancelMade = {};
        },
        // Fire-and-forget: backend only flips status='reserved' → available, so this
        // is harmless after payment (already freed) or on occupied tables (held-order
        // lifecycle owns those).
        releaseTable(id) {
            if (!id) return;
            try {
                fetch('/pos/restaurant/tables/' + id + '/release', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
            } catch (e) {}
        },

        // ═══ TABLE BOARD (customer request ×3, owner-approved Jul 2026) ═══════
        // Always-visible tiles below the cart. Rules: tile click ONLY opens the
        // action menu; Final always passes the confirm modal; waiter orders are
        // ALWAYS claimed atomically before any load/pay (single-winner invariant);
        // purple = waiter order still visible in MY incoming list (own+unassigned).
        boardCounts() {
            const all = this.tableFloors.flatMap(f => f.tables);
            return {
                occupied: all.filter(t => t.status === 'occupied' && !this.boardIsWaiter(t)).length,
                reserved: all.filter(t => !t.order && t.status === 'reserved').length,
                // Purple badge = TABLE waiter orders only; counter/bina-table
                // orders ka apna alag "C" badge hai (double-count na ho).
                waiter: all.filter(t => this.boardIsWaiter(t)).length,
            };
        },
        // Live sum of every OPEN order the BOARD shows (table tiles + bina-table
        // held chips) — header "Rs X chalu" glance. Counter (takeaway/delivery)
        // orders EXCLUDED (ZFC 5 Aug 2026): wo board par nahi dikhte (sirf ghanti
        // panel), to unki raqam yahan ginne se "khali board, phir bhi raqam
        // chalu" wali confusion banti thi.
        boardOpenTotal() {
            const tables = this.tableFloors.flatMap(f => f.tables)
                .reduce((s, t) => s + (t.order ? (parseFloat(t.order.total_amount) || 0) : 0), 0);
            // Bina-table held orders bhi khuli raqam hain (board ki amber chips).
            const held = this.heldNoTable()
                .reduce((s, o) => s + (parseFloat(o.total_amount) || 0), 0);
            return Math.round(tables + held);
        },
        // Minutes elapsed since ts (reads nowTick → refreshes on the 30s tick).
        boardMinsSince(ts) {
            if (!ts) return 0;
            const ms = this.nowTick - new Date(ts).getTime();
            return (isNaN(ms) || ms < 0) ? 0 : Math.floor(ms / 60000);
        },
        // Urgency (customer angle: "table bhool gaye / khana thanda ho gaya"):
        // waiter order 10+ min unclaimed, occupied 30+ min, reserved 15+ min.
        boardTileUrgent(t) {
            if (this.boardIsWaiter(t)) return this.boardMinsSince(t.occupied_since) >= 10;
            if (t.order || t.status === 'occupied') return this.boardMinsSince(t.occupied_since) >= 30;
            if (t.status === 'reserved') return this.boardMinsSince(t.locked_at) >= 15;
            return false;
        },
        boardIsWaiter(t) {
            return !!(t && t.order && t.order.source === 'waiter' && this.incomingForTable(t));
        },
        boardTileClass(t) {
            const urgent = this.boardTileUrgent(t); // solid stronger tint — no glow (design rule)
            if (this.boardIsWaiter(t)) return urgent
                ? 'border-purple-500 dark:border-purple-500 bg-purple-100 dark:bg-purple-900/40 text-purple-900 dark:text-purple-100'
                : 'border-purple-300 dark:border-purple-700 bg-purple-50 dark:bg-purple-900/20 text-purple-800 dark:text-purple-200';
            if (t.status === 'occupied' || t.order) return urgent
                ? 'border-red-500 dark:border-red-500 bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200'
                : 'border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300';
            if (t.status === 'reserved') return urgent
                ? 'border-amber-500 dark:border-amber-500 bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200'
                : 'border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300';
            return 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300';
        },
        boardTileTime(t) {
            if (t.order || t.status === 'occupied') return this.elapsedSince(t.occupied_since);
            if (t.status === 'reserved') return this.elapsedSince(t.locked_at);
            return '';
        },
        boardTileSub(t) {
            if (t.order) {
                const who = t.order.staff_name ? String(t.order.staff_name).split(' ')[0] : '';
                return (who ? who + ' • ' : '') + 'Rs ' + Math.round(t.order.total_amount || 0).toLocaleString();
            }
            if (t.status === 'reserved') return window.TXT.reserved_word;
            return 'Free' + (t.seats ? ' • ' + t.seats + window.TXT.sfx_seats : '');
        },
        boardMenuSummary() {
            const t = this.boardMenuTable;
            if (!t) return '';
            if (t.order) {
                const bits = [];
                // Task #409: show WHO punched it — waiter ka naam bhi (sirf "Waiter
                // order" likhna owner ko na-kaafi laga).
                if (this.boardIsWaiter(t)) bits.push('Waiter' + (t.order.staff_name ? ': ' + String(t.order.staff_name).split(' ')[0] : ' order'));
                else if (t.order.staff_name) bits.push(String(t.order.staff_name).split(' ')[0]);
                if (t.order.order_number) bits.push('#' + t.order.order_number);
                const el = this.boardTileTime(t);
                if (el) bits.push(el);
                return bits.join(' • ');
            }
            if (t.status === 'reserved') return window.TXT.reserved_word + (this.elapsedSince(t.locked_at) ? ' • ' + this.elapsedSince(t.locked_at) : '');
            return (t.floor ? t.floor + ' • ' : '') + 'Free table';
        },
        openBoardMenu(t) {
            if (this.boardBusy) return;
            this.boardMenuTable = t;
            // Items list (Pizza Master feedback, Jul 2026): lazy-fetch so the
            // board endpoint stays light — only the OPEN popup pays this cost.
            this.boardMenuItems = null;
            if (t && t.order) {
                fetch('/pos/restaurant/orders/by-table/' + t.id, { headers: { 'Accept': 'application/json' } })
                    .then(r => r.ok ? r.json() : [])
                    .then(list => {
                        // Popup may have moved to another table meanwhile — guard.
                        if (!this.boardMenuTable || this.boardMenuTable.id !== t.id) return;
                        const arr = Array.isArray(list) ? list : [];
                        // Number() dono taraf — live PDO ids ko STRING deta hai.
                        const ord = arr.find(o => Number(o.id) === Number(t.order.id)) || arr[0];
                        this.boardMenuItems = (ord && Array.isArray(ord.items)) ? ord.items : [];
                    })
                    .catch(() => { if (this.boardMenuTable && this.boardMenuTable.id === t.id) this.boardMenuItems = []; });
            }
        },
        // Proof Bill (Pizza Master, Jul 2026): thermal pre-bill WITHOUT finalizing —
        // no invoice, no serial, order stays open. Print via the same hidden-iframe
        // pipeline as receipts (focus wapas milta hai, shortcuts zinda rehte hain).
        boardProofBill() {
            const t = this.boardMenuTable;
            if (!t || !t.order) return;
            const url = '/pos/restaurant/orders/' + t.order.id + '/proof-bill?auto_print=1';
            // Owner video (Aug 2026): proof ke baad menu WAHIN khula rehta tha —
            // baqi board actions ki tarah menu band karo, cashier table board par
            // wapas. Order khula rehta hai (proof final nahi); print trigger `t`
            // capture par chalta hai, aur _printViaIframe focus wapas deta hai
            // (shortcuts zinda rehte hain).
            this.boardMenuTable = null;
            const fallback = () => this._printViaIframe('print-receipt-frame', url, 'width=400,height=700');
            // Silent-first (ZFC 28 Jul 2026): the iframe path pops the Windows
            // print dialog inside the desktop app — route through the agent
            // queue like receipts; iframe stays as the fallback.
            if (this.silentBillPrint) {
                this.trySilentPrint({ type: 'proof', restaurant_order_id: t.order.id }).then(ok => {
                    if (ok) this.showToast(window.TXT.proof_bill_sent_to_printer, 'success'); else fallback();
                });
                return;
            }
            fallback();
        },
        // View/Edit → load the table's order into the cart. Waiter orders go via
        // the ATOMIC claim (existing path). Foreign cashier-held orders are NOT
        // in this.heldOrders — fetch fresh WITH items from by-table, then reuse
        // the standard recallOrder pipeline.
        async boardViewEdit() {
            const t = this.boardMenuTable;
            if (!t || !t.order || this.boardBusy) return;
            this.boardBusy = true;
            try {
                if (t.order.source === 'waiter') {
                    await this.claimAndLoadIncoming({ id: t.order.id });
                    this.boardMenuTable = null;
                    return;
                }
                const res = await fetch('/pos/restaurant/orders/by-table/' + t.id, { headers: { 'Accept': 'application/json' } });
                const orders = res.ok ? await res.json() : [];
                const list = Array.isArray(orders) ? orders : [];
                const ord = list.find(o => o.id === t.order.id) || list[0];
                if (!ord) {
                    this.showToast(window.TXT.order_not_found_refreshing, 'warning');
                    this.loadTableStatus();
                    this.boardMenuTable = null;
                    return;
                }
                ord.table = ord.table || { id: t.id, table_number: t.table_number, occupied_since: t.occupied_since };
                this.recallOrder(ord);
                this.boardMenuTable = null;
            } catch (e) {
                this.showToast(window.TXT.order_load_failed_conn, 'error');
            } finally { this.boardBusy = false; }
        },
        // ZFC (3 Aug 2026): boot-time twin of boardViewEdit for ?table_id= landings
        // (Tables page tile → sale screen). Agar us table par koi ACTIVE order hai
        // to wohi load karo — warna kuch na karo (naya-order flow jaisa tha waisa).
        // Waiter orders hamesha atomic claim se (single-winner invariant barqarar).
        async autoOpenPreselectedTable() {
            const t = this.selectedTable;
            if (!t || !t.id) return;
            // Kisi aur boot-flow ne cart le liya (edit-bill / restore / claim)? Haath na lagao.
            if (this.cart.length || this.editingBillId || this.recalledOrderId || this.incomingOrderId) return;
            try {
                const res = await fetch('/pos/restaurant/orders/by-table/' + t.id, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;
                const list = await res.json();
                const ord = (Array.isArray(list) && list.length) ? list[0] : null;
                if (!ord) return; // koi chalta order nahi — table sach mein nayi sale ke liye hai
                if (ord.source === 'waiter') { await this.claimAndLoadIncoming({ id: ord.id }); return; }
                // by-table 'table' relation nahi bhejta — baked table info se bharo
                // (recallOrder isi se selectedTable/dine_in set karta hai).
                ord.table = ord.table || { id: t.id, table_number: t.table_number, occupied_since: null };
                this.recallOrder(ord);
            } catch (e) { /* silent — board/Recall raste bahar-haal khule hain */ }
        },
        // Task 502 (11 Aug 2026): ?recall_order= boot handler — Tables page ke
        // open-order card ka WOHI order seedha cart mein aaye. Server ne id ko
        // pehle hi company + status (held/preparing/ready) par validate kiya hai;
        // yahan baked heldOrders (items+table samet) se uthate hain. Waiter-source
        // orders hamesha atomic claim se (single-winner invariant). Order na mile
        // (kisi aur ne abhi-abhi final/delete kar diya) to purana table_id-based
        // auto-open fallback chalta hai.
        async autoRecallFromUrl() {
            const rid = this.bootRecallOrderId;
            if (!rid) return this.autoOpenPreselectedTable();
            // Kisi aur boot-flow ne cart le liya (edit-bill / restore / claim)? Haath na lagao.
            if (this.cart.length || this.editingBillId || this.recalledOrderId || this.incomingOrderId) return;
            const ord = this.heldOrders.find(o => Number(o.id) === Number(rid));
            if (!ord) return this.autoOpenPreselectedTable();
            if (ord.source === 'waiter') {
                try { await this.claimAndLoadIncoming({ id: ord.id }); } catch (e) { /* silent */ }
                return;
            }
            this.recallOrder(ord);
        },
        // FINAL — step 1: close menu, open the explicit confirm (anti "anjaane
        // mein final"). Both modals are z-50; they never overlap.
        boardAskFinal() {
            const t = this.boardMenuTable;
            if (!t || !t.order) return;
            this.boardMenuTable = null;
            // Task 514: per-bill print checkbox ka default company setting se
            // (tile ki apni order_type ke hisaab se) — har bill par fresh reset.
            this.boardPrintReceipt = this.billPrintDefault(t.order.order_type || 'dine_in');
            this.boardConfirm = { table: t };
        },
        // FINAL — step 2 (CASH/CARD chosen): waiter orders claim FIRST, then the
        // shared payHeldOrderDirect pipeline with the tile's own order_type so a
        // dine_in never re-triggers the auto-KOT chain from a foreign terminal.
        async boardFinalPay(method) {
            if (this.boardBusy || !this.boardConfirm) return;
            // Task 781: in-panel FINAL — the order is OPEN IN THE CART (possibly
            // with unsent edits), so it must settle through processPayment (the
            // hold-with-recalled_order_id → pay pipeline) instead of paying the
            // stale server-side order snapshot directly.
            if (this.boardConfirm.fromPanel) { return this.panelFinalPay(method); }
            const t = this.boardConfirm.table;
            this.boardBusy = true;
            try {
                let orderId = t.order.id;
                if (t.order.source === 'waiter') {
                    const res = await fetch('/pos/api/incoming-orders/' + orderId + '/claim', {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    });
                    let data = null; try { data = await res.json(); } catch (_) {}
                    if (!res.ok || !data || !data.success) {
                        this.showToast((data && data.message) || window.TXT.order_taken_by_other_cashier, 'warning');
                        this.boardConfirm = null;
                        this.loadIncoming(); this.loadTableStatus();
                        return;
                    }
                    orderId = (data.order && data.order.id) || orderId;
                }
                await this.payHeldOrderDirect(orderId, method, null, false, t.order.order_type || 'dine_in', !this.boardPrintReceipt);
                this.boardConfirm = null;
                this.loadTableStatus();
                if (this.isRestaurantMode) this.loadIncoming();
            } finally { this.boardBusy = false; }
        },
        boardResendKot() {
            const t = this.boardMenuTable;
            if (!t || !t.order) return;
            this.resendKitchen({ id: t.order.id });
            this.boardMenuTable = null;
        },
        // ── Table Shift (owner batch, 26 Jul 2026) ─────────────────────────
        // Menu band → shift modal (sirf khali tables). Server race-safe hai;
        // timer continue (occupied_since carry) aur KOT dobara NAHI chalta.
        boardAskShift() {
            const t = this.boardMenuTable;
            if (!t || !t.order) return;
            this.boardMenuTable = null;
            this.boardShift = { table: t, order: t.order };
            this.loadTableStatus(); // fresh statuses — stale "khali" tile na dikhe
        },
        boardShiftFree() {
            return this.tableFloors.flatMap(f => f.tables).filter(x => x.status === 'available' && !x.order);
        },
        async doShiftTable(target) {
            if (this.boardBusy || !this.boardShift || !target) return;
            const ord = this.boardShift.order;
            const fromT = this.boardShift.table;
            this.boardBusy = true;
            try {
                const res = await fetch('/pos/restaurant/orders/' + ord.id + '/shift-table', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ table_id: target.id }),
                });
                const data = await res.json().catch(() => null);
                if (data && data.success) {
                    this.showToast(data.message || ('Order T-' + target.table_number + window.TXT.order_shifted_suffix), 'success');
                    this.boardShift = null;
                    // Agar yehi order cart mein khula hai (edit) to selectedTable ko naye
                    // table par le aao — warna Hold dobara PURANE table par parkega.
                    if (this.selectedTable && Number(this.selectedTable.id) === Number(fromT.id)) {
                        this.selectedTable = { id: target.id, table_number: target.table_number, seats: target.seats };
                    }
                    // heldOrders snapshot mein bhi naya table likho (Recall list sahi dikhe).
                    const held = this.heldOrders.find(o => Number(o.id) === Number(ord.id));
                    if (held) held.table = { id: target.id, table_number: target.table_number };
                } else {
                    this.showToast((data && data.message) || window.TXT.shift_failed, 'error');
                }
            } catch (e) {
                this.showToast(window.TXT.shift_failed_conn, 'error');
            } finally {
                this.boardBusy = false;
                this.loadTableStatus();
            }
        },
        // ── Held-order ACTION MENU (bina-table amber chips on the Table Board).
        // Sab actions EXISTING pipelines reuse karte hain (recallOrder /
        // payHeldOrder / resendKitchen / deleteHeldOrder) — menu sirf hub hai.
        // Waiter-source orders EXCLUDED — woh purple "C" counter chips hain
        // (atomic-claim path); yahan bhi dikhte to double-count + claim bypass hota.
        heldNoTable() { return this.heldOrders.filter(o => !o.table && o.source !== 'waiter'); },
        heldMenuRecall() { const o = this.heldMenu; this.heldMenu = null; if (o) this.recallOrder(o); },
        heldMenuPay()    { const o = this.heldMenu; this.heldMenu = null; if (o) this.payHeldOrder(o.id); },
        heldMenuResend() { const o = this.heldMenu; this.heldMenu = null; if (o) this.resendKitchen(o); },
        heldMenuDelete() {
            const o = this.heldMenu;
            this.heldMenu = null;
            if (!o) return;
            // Task #898: ALWAYS use the rich cancel modal (items checklist + KOT
            // warning) instead of plain confirm().  For no-KOT orders the modal
            // shows the items list but skips the "KOT ja chuki hai" alert and
            // made/not-made toggles (buildOrderCancelAsk sets noTicks when item
            // rows have no real DB ids; boardCancelConfirm sends {} body).
            // This ensures made_item_ids is available on EVERY '…' menu delete,
            // not just the KOT path.
            this.boardCancelAsk = this.buildOrderCancelAsk(o);
            this.boardCancelMade = {};
        },
        // Free table: reserved-only → release; any open order (cashier OR waiter,
        // Task #409) → confirm + soft-cancel via the same deleteOrder endpoint.
        async boardFree() {
            const t = this.boardMenuTable;
            if (!t || this.boardBusy) return;
            if (t.order) {
                // ZFC (2 Aug 2026): bare confirm() ki jagah warning modal — items
                // ki list + "KOT kitchen ja chuki hai" ka alert, taake bana hua
                // khana anjane mein cancel na ho.
                this.boardCancelAsk = { table: t, order: t.order, items: null };
                this.boardCancelMade = {};
                try {
                    const res = await fetch('/pos/restaurant/orders/by-table/' + t.id, { headers: { 'Accept': 'application/json' } });
                    const list = res.ok ? await res.json().catch(() => null) : null;
                    const full = Array.isArray(list) ? list.find(o => o.id === t.order.id) : null;
                    if (this.boardCancelAsk && this.boardCancelAsk.order && this.boardCancelAsk.order.id === t.order.id) {
                        this.boardCancelAsk.items = (full && Array.isArray(full.items)) ? full.items : [];
                    }
                } catch (e) { if (this.boardCancelAsk) this.boardCancelAsk.items = []; }
                return;
            } else if (t.status === 'reserved') {
                this.releaseTable(t.id);
                this.showToast('T-' + t.table_number + window.TXT.reserve_ended_suffix, 'success');
            }
            this.boardMenuTable = null;
            setTimeout(() => this.loadTableStatus(), 400);
        },
        async boardCancelConfirm() {
            const ask = this.boardCancelAsk;
            if (!ask || this.boardBusy) return;
            const t = ask.table;
            this.boardBusy = true;
            try {
                const madeIds = Object.keys(this.boardCancelMade).filter(k => this.boardCancelMade[k]).map(Number);
                const res = await fetch('/pos/restaurant/orders/' + ask.order.id + '/delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    // made_item_ids sirf tab bhejo jab KOT gayi thi (warna sawal hi nahi banta)
                    body: JSON.stringify((ask.order.kot_sent_at && !ask.noTicks) ? { made_item_ids: madeIds } : {}),
                });
                const data = res.ok ? await res.json().catch(() => null) : null;
                if (data && data.success) {
                    // Task 928: signal any open KDS tab on this device to refresh immediately.
                    try { localStorage.setItem('pos_kds_void_signal', Date.now()); } catch(e) {}
                    this.heldOrders = this.heldOrders.filter(o => o.id !== ask.order.id);
                    // Held-orders list modal cleanup — match deleteHeldOrder() so the
                    // index doesn't point past the end and the modal auto-closes when empty.
                    if (this.activeHeldIndex >= this.heldOrders.length) this.activeHeldIndex = Math.max(0, this.heldOrders.length - 1);
                    if (this.heldOrders.length === 0) { this.showHeldOrders = false; this.activeHeldIndex = 0; }
                    // Waiter order cancelled from the board → drop it from the ghanti
                    // (incoming) list too, warna badge stale reh jata (Task #409).
                    this.incomingOrders = this.incomingOrders.filter(o => o.id !== ask.order.id);
                    // Task #643: claimed-cart cancel — cart clear + loaded-order link reset.
                    if (this.incomingOrderId && ask.order.id === this.incomingOrderId) {
                        this.clearCart();
                        this.loadIncoming();
                    }
                    // Task 781: in-panel cancel of the RECALLED order in the cart —
                    // same reset (table freed server-side; clearCart drops the link).
                    if (this.recalledOrderId && Number(ask.order.id) === Number(this.recalledOrderId)) {
                        this.clearCart();
                    }
                    this.showToast(t ? (window.TXT.order_cancel_t_prefix + t.table_number + window.TXT.table_freed_suffix) : window.TXT.order_cancelled_toast, 'success');
                    // Task 899: refresh from server so any OTHER open tab also drops this order.
                    this.loadHeldOrders();
                    // Task 840: whole-order cancel void slip — same path as deleteHeldOrder.
                    if (data.kot_void_queued) {
                        this.showToast(window.TXT.kot_void_sent || 'Void slip sent to kitchen', 'success');
                    } else if (data.kot_void_url) {
                        this._printViaIframe('print-kot-void-frame', data.kot_void_url + '&auto_print=1', 'width=380,height=620');
                    }
                } else {
                    this.showToast((data && data.message) || window.TXT.cancel_failed, 'error');
                }
            } catch (e) {
                this.showToast(window.TXT.cancel_failed_conn, 'error');
            } finally { this.boardBusy = false; }
            this.boardCancelAsk = null;
            this.boardMenuTable = null;
            setTimeout(() => this.loadTableStatus(), 400);
        },
        // Free tile → reserve + start a new dine-in (existing selectTable path,
        // including the dine_in_auto_kot chain and guided-flow resume).
        async boardReserve() {
            const t = this.boardMenuTable;
            if (!t) return;
            this.boardMenuTable = null;
            await this.selectTable(t);
        },

        // ═══ Task 781 — TABLE CLICK DIRECT OPEN + IN-PANEL TABLE ACTIONS ═══
        // Opt-in (tableClickDirectOpen). Occupied tile/picker click → the order
        // loads straight into the cart in edit mode (popup skipped); the popup's
        // actions live in the payment panel while the order is open. Flag OFF =
        // every path below is dead code and the popup flow is untouched.
        // Board tile click dispatcher: occupied + empty cart + flag ON = direct
        // open; everything else (free/reserved tiles, filled cart, flag OFF)
        // keeps the existing action-menu popup.
        boardTileClick(t) {
            if (this.tableClickDirectOpen && t && t.order && this.cart.length === 0) { this.directOpenTable(t); return; }
            this.openBoardMenu(t);
        },
        // Direct open — same logic as boardViewEdit but without the popup:
        // waiter orders go through the ATOMIC claim; cashier orders are fetched
        // fresh WITH items from by-table and recalled into the cart. On failure
        // the cashier gets a clear toast and the table board stays intact.
        async directOpenTable(t) {
            if (!t || !t.order || this.boardBusy) return;
            this.boardBusy = true;
            try {
                if (t.order.source === 'waiter') {
                    await this.claimAndLoadIncoming({ id: t.order.id });
                    return;
                }
                const res = await fetch('/pos/restaurant/orders/by-table/' + t.id, { headers: { 'Accept': 'application/json' } });
                const orders = res.ok ? await res.json() : [];
                const list = Array.isArray(orders) ? orders : [];
                // Number() dono taraf — live PDO ids ko STRING deta hai.
                const ord = list.find(o => Number(o.id) === Number(t.order.id)) || list[0];
                if (!ord) {
                    this.showToast(window.TXT.order_not_found_refreshing, 'warning');
                    this.loadTableStatus();
                    return;
                }
                ord.table = ord.table || { id: t.id, table_number: t.table_number, occupied_since: t.occupied_since };
                this.recallOrder(ord);
            } catch (e) {
                this.showToast(window.TXT.order_load_failed_conn, 'error');
            } finally { this.boardBusy = false; }
        },
        // ── In-panel table-actions context ─────────────────────────────────
        // The block shows only when the flag is ON and a dine-in TABLE order is
        // open in the cart: recalled cashier order (selectedTable set) or a
        // claimed waiter order (incomingOrderInfo carries table_id/table).
        panelTableActionsVisible() {
            if (!this.tableClickDirectOpen || this.orderType !== 'dine_in' || this.cart.length === 0) return false;
            if (this.recalledOrderId && this.selectedTable) return true;
            if (this.incomingOrderId && this.incomingOrderInfo) return true;
            return false;
        },
        panelOrderId() { return this.recalledOrderId || this.incomingOrderId || null; },
        panelOrderMeta() {
            if (this.recalledOrderId) return this.recalledOrderMeta || {};
            return this.incomingOrderInfo || {};
        },
        panelTableInfo() {
            if (this.selectedTable) return this.selectedTable;
            const info = this.incomingOrderInfo;
            if (info && info.table_id) return { id: info.table_id, table_number: info.table || '' };
            return null;
        },
        panelKotSent() { return !!(this.panelOrderMeta().kot_sent_at); },
        // Proof Bill — same silent-first / iframe-fallback chain as the popup;
        // prints the SERVER-side order state (same semantics as the popup).
        panelProofBill() {
            const oid = this.panelOrderId();
            if (!oid) return;
            const url = '/pos/restaurant/orders/' + oid + '/proof-bill?auto_print=1';
            const fallback = () => this._printViaIframe('print-receipt-frame', url, 'width=400,height=700');
            if (this.silentBillPrint) {
                this.trySilentPrint({ type: 'proof', restaurant_order_id: oid }).then(ok => {
                    if (ok) this.showToast(window.TXT.proof_bill_sent_to_printer, 'success'); else fallback();
                });
                return;
            }
            fallback();
        },
        // FINAL — reuses the boardConfirm modal (big amount + CASH/CARD + the
        // Task 514 print checkbox, initialized from the company default). The
        // fromPanel flag routes confirm through panelFinalPay (cart-aware).
        panelAskFinal() {
            const oid = this.panelOrderId();
            if (!oid || this.submitting) return;
            this.boardPrintReceipt = this.billPrintDefault(this.orderType || 'dine_in');
            const ti = this.panelTableInfo();
            this.boardConfirm = {
                fromPanel: true,
                table: { table_number: (ti && ti.table_number) || '', order: { id: oid, total_amount: this.roundedTotal, order_type: this.orderType || 'dine_in' } },
            };
        },
        // FINAL confirm (CASH/CARD) — the standard processPayment pipeline:
        // recalled orders re-hold with recalled_order_id (cart edits included),
        // claimed waiter carts settle via processPaymentManual. payPrintReceipt
        // carries the checkbox choice into the auto-print chain; Task 779's
        // tables-first return flow rides the receipt-close exactly as on the
        // normal CASH/CARD buttons.
        async panelFinalPay(method) {
            if (this.submitting) return;
            this.boardConfirm = null;
            this.payingHeldOrderId = null;
            this.saveAsProvisional = false;
            this.payPrintReceipt = this.boardPrintReceipt;
            await this.processPayment(method);
            this.loadTableStatus();
        },
        panelResendKot() {
            const oid = this.panelOrderId();
            if (oid) this.resendKitchen({ id: oid });
        },
        panelLastKot() {
            const oid = this.panelOrderId();
            if (oid) this.reprintLastKot({ id: oid });
        },
        // Table Shift — same modal + doShiftTable pipeline (empty-target-only,
        // timer carries, NO KOT reprint); doShiftTable already re-points
        // selectedTable when the shifted order is the one open in the cart.
        panelAskShift() {
            const oid = this.panelOrderId();
            const ti = this.panelTableInfo();
            if (!oid || !ti) return;
            this.boardShift = { table: { id: ti.id, table_number: ti.table_number }, order: { id: oid } };
            this.loadTableStatus(); // fresh statuses — stale "khali" tile na dikhe
        },
        // Order Cancel + free table — same warning modal (items + KOT alert +
        // Made/Not-Made ticks) and boardCancelConfirm soft-cancel. Claimed
        // waiter carts keep their existing cartCancelIncoming path.
        async panelCancelAsk() {
            if (this.boardBusy) return;
            if (this.incomingOrderId) { this.cartCancelIncoming(); return; }
            const oid = this.recalledOrderId;
            const ti = this.panelTableInfo();
            if (!oid) return;
            const meta = this.recalledOrderMeta || {};
            this.boardCancelAsk = {
                table: ti ? { table_number: ti.table_number } : null,
                order: { id: oid, order_number: meta.order_number || null, order_type: 'dine_in', total_amount: this.roundedTotal, kot_sent_at: meta.kot_sent_at || null },
                items: null,
            };
            this.boardCancelMade = {};
            try {
                const res = ti ? await fetch('/pos/restaurant/orders/by-table/' + ti.id, { headers: { 'Accept': 'application/json' } }) : null;
                const list = (res && res.ok) ? await res.json().catch(() => null) : null;
                const full = Array.isArray(list) ? list.find(o => Number(o.id) === Number(oid)) : null;
                if (this.boardCancelAsk && this.boardCancelAsk.order && Number(this.boardCancelAsk.order.id) === Number(oid)) {
                    this.boardCancelAsk.items = (full && Array.isArray(full.items)) ? full.items : [];
                    if (full) {
                        this.boardCancelAsk.order.kot_sent_at = full.kot_sent_at || this.boardCancelAsk.order.kot_sent_at;
                        this.boardCancelAsk.order.total_amount = full.total_amount || this.boardCancelAsk.order.total_amount;
                    }
                }
            } catch (e) { if (this.boardCancelAsk) this.boardCancelAsk.items = []; }
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
                const res = await fetch('/pos/restaurant/api/customer-search?q=' + encodeURIComponent(q));
                const data = await res.json();
                if (q !== this.customerSearch.trim()) return; // stale-response guard
                this.pickerServerResults = data.customers || [];
            } catch (e) { this.pickerServerResults = null; } // OFFLINE → local baked subset
        },

        async lookupCustomerByPhone(phone) {
            try {
                const res = await fetch('/pos/restaurant/api/customer-lookup?phone=' + encodeURIComponent(phone));
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

        async selectCustomerWithStats(c) {
            this.selectedCustomer = c;
            this.customerStats = null;
            this.customerPhoneQuery = c.name + (c.phone ? " · " + c.phone : "");
            this.showCustomerPicker = false;
            this.showToast(window.TXT.customer_prefix + c.name, 'success');
            if (c.phone) {
                try {
                    const res = await fetch('/pos/restaurant/api/customer-lookup?phone=' + encodeURIComponent(c.phone));
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
            if (q.length === 0) {
                this.customerPhoneResults = [];
                this.customerPhoneDropdown = false;
                return;
            }
            // FILTER-AS-YOU-TYPE: instant local matches from the preloaded customer list
            // from the VERY FIRST character (no server round-trip). The server search
            // below then replaces these with stats-enriched results (orders/spend/VIP).
            const lq = q.toLowerCase();
            const local = (this.allCustomers || [])
                .filter(c => (c.name && c.name.toLowerCase().includes(lq)) || (c.phone && String(c.phone).includes(q)))
                .slice(0, 8);
            this.custHiIndex = 0;
            if (local.length > 0) {
                this.customerPhoneResults = local;
                this.customerPhoneDropdown = true;
            } else if (q.length < 2) {
                this.customerPhoneResults = [];
                this.customerPhoneDropdown = false;
            }
            if (q.length >= 2) {
                this.customerPhoneTimer = setTimeout(() => this.searchCustomerByPhone(q), 150);
            }
        },

        // Item #2 — ↑↓ keyboard navigation over the customer dropdown (wraps around).
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
                    const res = await fetch('/pos/restaurant/api/customer-search?q=' + encodeURIComponent(q));
                    const data = await res.json();
                    // Stale-response guard: with the 150ms debounce a slow earlier fetch can
                    // land AFTER a newer one — never let it clobber fresher results.
                    if (q !== this.customerPhoneQuery.trim()) return;
                    this.customerPhoneResults = data.customers || [];
                    this.custHiIndex = 0;
                    // Always show dropdown so the inline "add new" hint can appear when results === 0
                    this.customerPhoneDropdown = true;
                } catch(e) {
                    // OFFLINE fallback (Task 100): server unreachable → keep local
                    // matches from the baked (possibly partial) list instead of
                    // blanking the dropdown the local pre-filter already opened.
                    const lq = q.toLowerCase();
                    const qd = this.phoneDigits(q);
                    this.customerPhoneResults = (this.allCustomers || [])
                        .filter(c => (c.name && c.name.toLowerCase().includes(lq)) || (c.phone && String(c.phone).includes(q)) || (qd.length >= 4 && c.phone && this.phoneDigits(c.phone).includes(qd)))
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
            // NON-guided mode: empty box + Enter = walk-in too (owner, Jul 2026) — jump
            // straight to product search instead of a dead key. Mirrors the guided branch.
            if (!q) {
                this.customerPhoneDropdown = false;
                this.$nextTick(() => { this.$refs.searchInput?.focus(); });
                return;
            }
            if (this.customerPhoneResults.length > 0) {
                this.selectCustomerFromPhone(this.customerPhoneResults[this.custHiIndex] || this.customerPhoneResults[0]);
            } else if (this.isPhoneLike(q)) {
                this.openInlineNewCustomer();
            } else {
                this.showToast(window.TXT.enter_valid_mobile, 'error');
            }
        },

        // Pizza Master (Aug 2026): cashiers type numbers WITH dashes/spaces (03001-1234567).
        // The digits-only /^\d+$/ gate silently refused to open the new-customer form (so the
        // address field "never appeared"). Accept separator characters, store digits only.
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
            // Item #1: on a Delivery order the address picker should be ready instantly.
            if (this.orderType === 'delivery') this.loadCustomerAddresses();
            else { this.customerAddresses = []; this.selectedDeliveryAddress = ''; }
            this.showToast(window.TXT.customer_prefix + cr.name + (cr.stats && cr.stats.is_frequent ? ' (VIP)' : ''), 'success');
            this.$nextTick(() => { this.$refs.searchInput?.focus(); });
        },

        async saveNewCustomer() {
            if (this.savingCustomer) return;
            // Name is OPTIONAL (owner request, Jul 2026): phone is the real identifier —
            // blank name = backend stores the phone number as the display name.
            const name = this.newCustomerName.trim();
            this.savingCustomer = true;
            try {
                const res = await fetch('{{ route("pos.restaurant.customer-store") }}', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ name: name || null, phone: this.newCustomerPhone, address: this.newCustomerAddress.trim() || null })
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

        // Task 1380: cart card ka ✕ — ek click mein caller/customer ka poora
        // nishan saaf. clearCustomerInput() customer, stats, search text, inline
        // new-customer fields aur saved addresses (+ chuna hua delivery address)
        // le jata hai; yahan uske sath wo delivery state bhi reset hoti hai jo
        // caller flow ne khadi ki thi — delivery-charge line (synthetic manual
        // fee row, koi bika hua item nahi) aur prepaid toggle. Cart ke asli
        // items aur order type ko haath nahi lagta.
        clearAttachedCustomer() {
            const had = !!this.selectedCustomer;
            this.clearCustomerInput();
            this.removeDeliveryCharge();
            this.deliveryPrepaid = false;
            if (had) { this.showToast(window.TXT.customer_removed, 'info'); }
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
            // Item #1: addresses belong to the cleared customer — drop them.
            this.customerAddresses = []; this.selectedDeliveryAddress = ''; this.showAddrNew = false; this.newAddrText = ''; this.newAddrLabel = '';
            this.$refs.customerPhoneInput?.focus();
        },

        async holdOrder(opts) {
            // EDIT MODE: a provisional bill can't be turned into a held order —
            // F9 Update Bill is the only save path while editing.
            if (this.editingBillId) {
                this.showToast(window.TXT.edit_mode_f9_save, 'error');
                return;
            }
            opts = opts || {};
            if (this.cart.length === 0 || this.submitting) return null;
            // Order-type flow rule: Hold / Send-to-Kitchen is the Dine-In procedure
            // ONLY (restaurant companies). Takeaway = direct final; Delivery = final
            // or provisional. Backend enforces the same rule (defence-in-depth).
            if (!this.canHold()) {
                this.showToast(this.orderType === 'takeaway' ? window.TXT.takeaway_billed_directly : window.TXT.hold_dine_in_only, 'error');
                return null;
            }
            // Table-required guard (owner, 9 Aug 2026): dine-in Hold/KOT without a
            // table must not punch — open the table picker instead. Server enforces
            // the same invariant (holdOrder 422s when tables feature is ON).
            if (this.tableBoardEnabled && this.orderType === 'dine_in' && !this.selectedTable) {
                this.showToast(window.TXT.dine_in_table_required, 'error');
                this.openTablePicker();
                return null;
            }
            // Defence-in-depth: backend hold endpoint validates item_id as required|integer
            // and item_type in product,service. Synthetic manual lines (item_id=null,
            // item_type='manual') would 422. Block the action client-side too so the
            // cashier doesn't lose the cart on a server reject.
            if (this.hasManualItems() || this.hasDealItems()) {
                this.showToast(window.TXT.manual_deals_billing_only_hold, 'error');
                return null;
            }
            // P7 guard — an incoming WAITER order already exists as a held restaurant
            // order (KDS sees it). Re-holding would duplicate it; settle via payment.
            if (this.incomingOrderId) {
                this.showToast(window.TXT.waiter_order_loaded_settle, 'info');
                return null;
            }
            const now = Date.now();
            if (now - this.lastHoldTime < 2000) return null;
            this.lastHoldTime = now;
            this.submitting = true;
            // Task 1001: mint once per hold attempt, reuse on every retry so a
            // lost-response retry returns the original order instead of creating
            // a twin. Cleared to null on success (below) / clearCart().
            if (!this.holdAttemptUuid) this.holdAttemptUuid = this._newOfflineUuid();
            let result = null;
            try {
                const res = await fetch('{{ route("pos.restaurant.orders.hold") }}', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ items: this.cart, order_type: this.orderType, table_id: this.selectedTable?.id || null, customer_id: this.selectedCustomer?.id || null, customer_name: this.selectedCustomer?.name || null, customer_phone: this.selectedCustomer?.phone || null, kitchen_notes: this.kitchenNotes, priority: this.priorityOrder, recalled_order_id: this.recalledOrderId, discount_type: this.discountAmount > 0 ? this.discountType : null, discount_value: this.discountAmount > 0 ? this.discountValue : 0, discount_amount: this.discountAmount, delivery_address: this.orderType === 'delivery' ? ((this.selectedDeliveryAddress || '').trim() || null) : null, hold_uuid: this.holdAttemptUuid }),
                });
                const data = await res.json();
                if (data.success) {
                    // KOT delta (owner, Jul 2026): an UPDATED order (recall → re-hold)
                    // must print ONLY the new/changed lines — kitchen already has the
                    // rest. Capture before clearCart() nulls recalledOrderId.
                    const wasRecall = !!this.recalledOrderId;
                    // Pizza Master feedback (Jul 2026): dine-in KOT ke baad cashier
                    // ko WAPAS tables chart pe le aao — agla order wahin se banta hai.
                    const wasDineIn = this.orderType === 'dine_in';
                    const successMsg = opts.successMessage || data.message;
                    // Task 1001: hold succeeded — next hold is a fresh attempt.
                    this.holdAttemptUuid = null;
                    this.showToast(successMsg, 'success'); this.heldOrders.unshift(data.order); this.clearCart();
                    // Task 1028 save-first switch: caller khud agla qadam chalata
                    // hai (clicked table ka normal flow) — yahan ka tables-page/
                    // picker navigation + focus us raste mein fire na ho.
                    if (opts.stayOnScreen) {
                        // no-op — navigation suppressed
                    } else if (wasDineIn && this.tableBoardEnabled) {
                        // Task 779: Tables-first flow ON → chhota picker NAHI, seedha
                        // full-screen Tables page. $nextTick tak neeche wali KOT-print
                        // branch chal chuki hoti hai, is liye navigateToTablesWhenIdle
                        // ka busy-wait chalti KOT print kabhi nahi kaat'ta. Offline par
                        // purana picker flow (tables page SW-cache mein nahi hoti).
                        if (this.tablesFirstFlow) {
                            // Task 819: Tables-first flow — seedha big Tables screen,
                            // online AND offline (SW ka TABLES_CACHE serve karta hai).
                            this.$nextTick(() => this.navigateToTablesWhenIdle());
                        } else {
                            this.$nextTick(() => this.openTablePicker());
                        }
                    } else {
                        this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); });
                    }
                    // Auto-print KOT when print_on_hold is enabled, OR when the caller explicitly asked
                    // (e.g. "Send to Kitchen" button always prints a ticket).
                    // SKIPPED when KDS Auto-Print owns ticket printing (owner, Jul 2026) —
                    // the KDS station fires the same ticket, cashier-side = duplicate.
                    if ((this.kitchenSettings.print_on_hold || opts.forcePrintKot) && !this.kdsHandlesKot()) {
                        this.kotPrintOrPopup(data.order.id, wasRecall);
                    } else if (data.kot_delta_queued) {
                        // Task 753 (Pizza Master): server ne recall+append ki chhoti
                        // (delta) KOT khud queue kar di (KDS zinda ho ya band) —
                        // yahan sirf cashier ko confirm karo, dobara fire nahi.
                        this.showToast(window.TXT.kot_sent_to_printer, 'success');
                    } else if (wasRecall && this.kitchenSettings.kds_enabled && this.kitchenSettings.kds_auto_print
                        && (this.silentKotPrint || !this.kdsHandlesKot())) {
                        // Task 753 APPEND-DELTA GUARANTEE: KDS-auto-print config mein
                        // Print-on-Hold OFF + KDS band/idle = add-on delta kahin se
                        // nahi nikalti thi. Silent-print shops: hamesha fire (server
                        // enqueue + KDS fire 2-min dedupe mein jazb — printer routing
                        // server karta hai); non-silent shops: sirf jab KDS board
                        // band ho, warna KDS apne device par sahi printer se chapta hai.
                        this.kotPrintOrPopup(data.order.id, true);
                    }
                    // Task 794: VOID slip — cashier ne recall mein pehle-se-chhapi
                    // dish hatayi / qty ghatayi. Kitchen ko STOP bolna zaroori hai.
                    // Server ne agent job bana di ho (kot_void_queued) to sirf
                    // confirm toast; warna hidden iframe se void-ticket print.
                    // Normal KOT chain se ALAG frame id — dono aik saath fire ho
                    // sakte hain (delta add + void ek hi re-hold mein mumkin).
                    if (data.kot_void_queued) {
                        // Task 928: partial-dish void — signal any open KDS tab to refresh immediately.
                        try { localStorage.setItem('pos_kds_void_signal', Date.now()); } catch(e) {}
                        this.showToast(window.TXT.kot_void_sent, 'success');
                    } else if (data.kot_void_url) {
                        // Task 928: partial-dish void — signal any open KDS tab to refresh immediately.
                        try { localStorage.setItem('pos_kds_void_signal', Date.now()); } catch(e) {}
                        this._printViaIframe('print-kot-void-frame', data.kot_void_url + '&auto_print=1', 'width=380,height=620');
                    }
                    result = data;
                } else { this.showToast(data.message || window.TXT.failed_word, 'error'); }
            } catch (e) { this.showToast(window.TXT.network_error, 'error'); }
            this.submitting = false;
            if (result && this.tableBoardEnabled) this.loadTableStatus(); // Table Board: held table goes red
            return result;
        },

        // Phase 5 — explicit "Send to Kitchen" action.
        // Same persistence as Hold, but always prints a KOT (no payment is taken).
        async sendToKitchen() {
            if (this.cart.length === 0) return;
            await this.holdOrder({ forcePrintKot: true, successMessage: 'Order sent to kitchen' });
        },

        // Phase 5 — re-send an existing held order. Server bumps kot_print_count
        // so the printed ticket is marked "*** UPDATED ***".
        async resendKitchen(order) {
            if (!order || !order.id) return;
            // Task 1379: same verdict as the (hidden) button — a cached/offline
            // copy of this screen must explain itself instead of failing mutely.
            if (!this.canKotReprint) { this.showToast(window.TXT.kot_reprint_not_allowed, 'error'); return; }
            try {
                const res = await fetch('/pos/restaurant/orders/' + order.id + '/resend-kitchen', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                });
                const data = await res.json();
                if (data.success) {
                    this.showToast(window.TXT.resent_to_kitchen_prefix + data.kot_print_count + ')', 'success');
                    this.kotPrintOrPopup(order.id);
                } else {
                    this.showToast(data.message || window.TXT.resend_failed, 'error');
                }
            } catch (e) {
                this.showToast(window.TXT.network_error, 'error');
            }
        },

        // Task 753 MISSED-DELTA RECOVERY — "Akhri Add-on KOT": akhri chhapi KOT
        // batch (+ still-unprinted rows) ka clean delta-style reprint. Physical
        // print fail (spooler/printer) par stamp lag chuka hota tha aur delta
        // hamesha ke liye gum ho jati thi — yeh button use wapas nikalta hai.
        // Stamping server par whereNull-guarded hai: kabhi re-number nahi hota.
        reprintLastKot(order) {
            if (!order || !order.id) return;
            // Task 1379 — see resendKitchen().
            if (!this.canKotReprint) { this.showToast(window.TXT.kot_reprint_not_allowed, 'error'); return; }
            const url = '/pos/restaurant/orders/' + order.id + '/kitchen-ticket?auto_print=1&batch=last';
            const fallback = () => this._printViaIframe('print-kot-frame', url, 'width=380,height=620');
            if (!this.silentKotPrint) { fallback(); return; }
            this.trySilentPrint({ type: 'kot', restaurant_order_id: order.id, batch: 'last' }).then(ok => {
                if (ok) this.showToast(window.TXT.kot_sent_to_printer, 'success'); else fallback();
            });
        },
        heldMenuLastKot() { const o = this.heldMenu; this.heldMenu = null; if (o) this.reprintLastKot(o); },
        boardLastKot() {
            const t = this.boardMenuTable;
            if (!t || !t.order) return;
            this.reprintLastKot({ id: t.order.id });
            this.boardMenuTable = null;
        },

        // ─── SAVE PROVISIONAL DIRECT — fully isolated from Pay modal ─────
        // Sets provisional flag + uses default 'cash' method, then routes
        // through the existing processPayment pipeline. No modal opens, no
        // keyboard conflict, no checkbox confusion. User can later edit /
        // delete / promote-to-final from F10 (Local) shortcut.
        // ─── EDIT PROVISIONAL IN SALE SCREEN (Jul 2026) ────────────────────
        // ?edit_bill={id} → the server ships the provisional bill (editingBillData);
        // this loads it into the cart. F9 becomes "Update Bill" → PUT updateTransaction
        // (JSON) — the bill STAYS provisional and KEEPS its L-serial. Pay/Hold/KOT are
        // blocked in edit mode: update first, then F10 → Make Final as usual.
        _initEditMode() {
            const eb = this.editingBillData;
            if (!eb || !eb.id) return;
            this.editingBillId = eb.id;
            this.editingBillNumber = eb.invoice_number || '';
            this.cart = (eb.items || []).map(i => ({
                cart_uid: 'c' + Date.now() + '_' + Math.random().toString(36).slice(2,9),
                item_id: i.item_id || null,
                item_type: i.item_type || 'product',
                item_name: i.item_name,
                quantity: parseFloat(i.quantity) || 1,
                unit_price: parseFloat(i.unit_price) || 0,
                special_notes: i.special_notes || '',
                is_tax_exempt: !!i.is_tax_exempt,
                item_discount_type: 'percentage', item_discount_value: 0, showItemDiscount: false,
            }));
            this.orderType = eb.order_type || 'takeaway';
            if (eb.customer_name || eb.customer_phone) {
                this.selectedCustomer = { id: eb.customer_id || null, name: eb.customer_name || window.TXT.customer_word, phone: eb.customer_phone || '' };
            }
            this.discountType = eb.discount_type || 'percentage';
            this.discountValue = parseFloat(eb.discount_value) || 0;
            if (this.discountValue > 0) this.showDiscount = true;
            this.kitchenNotes = eb.notes || '';
            this.recalcDiscount();
            if (this.orderType === 'delivery') {
                // Bill's snapshot address shows instantly; saved address book merges in
                // behind it (loadCustomerAddresses resets the list, so re-pin after).
                const snap = eb.delivery_address || '';
                if (snap) {
                    this.customerAddresses = [{ id: null, label: null, address: snap }];
                    this.selectedDeliveryAddress = snap;
                }
                if (eb.customer_id) {
                    this.loadCustomerAddresses().then(() => {
                        if (snap) {
                            if (!this.customerAddresses.some(a => (a.address || '') === snap)) {
                                this.customerAddresses.unshift({ id: null, label: null, address: snap });
                            }
                            this.selectedDeliveryAddress = snap;
                        }
                    });
                }
            }
        },
        cancelEditMode() {
            window.location.href = '{{ route('pos.invoice.create') }}';
        },
        async updateProvisionalBill() {
            if (this.submitting || !this.editingBillId) return;
            if (this.cart.length === 0) { this.showToast(window.TXT.cart_empty_add_or_delete, 'error'); return; }
            if (!navigator.onLine) { this.showToast(window.TXT.offline_update_online_only, 'error'); return; }
            this.submitting = true;
            try {
                const payload = {
                    items: this.cart.map(c => ({
                        name: c.item_name,
                        quantity: c.quantity,
                        unit_price: c.unit_price,
                        type: c.item_type === 'service' ? 'service' : (c.item_type === 'deal' ? 'deal' : 'product'),
                        item_id: c.item_id || null,
                        is_tax_exempt: !!c.is_tax_exempt,
                        special_notes: c.special_notes || null,
                        _manual: (c.item_type === 'manual' || !c.item_id) ? true : false,
                    })),
                    // Payment method is chosen at Make Final time — keep the bill's own.
                    payment_method: (this.editingBillData && this.editingBillData.payment_method) || 'cash',
                    discount_type: this.discountType || 'percentage',
                    discount_value: this.discountAmount > 0 ? this.discountValue : 0,
                    customer_name: this.selectedCustomer?.name || null,
                    customer_phone: this.selectedCustomer?.phone || null,
                    delivery_address: this.orderType === 'delivery' ? ((this.selectedDeliveryAddress || '').trim() || null) : null,
                    kitchen_notes: this.kitchenNotes,
                    terminal_id: (this.editingBillData && this.editingBillData.terminal_id) || null,
                };
                const res = await fetch('{{ url('/pos/transaction') }}/' + this.editingBillId, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(payload),
                });
                const data = await res.json().catch(() => null);
                if (res.ok && data && data.success) {
                    // Clean reload = guaranteed fresh state; toast rides the ?updated= param.
                    window.location.href = '{{ route('pos.invoice.create') }}?updated=' + encodeURIComponent(data.invoice_number || this.editingBillNumber);
                    return;
                }
                const msg = (data && (data.message || (data.errors && Object.values(data.errors)[0] && Object.values(data.errors)[0][0]))) || window.TXT.update_failed_try_again;
                this.showToast(msg, 'error');
            } catch (e) {
                console.error('[edit-bill] update failed', e);
                this.showToast(window.TXT.network_error_update_not_saved, 'error');
            } finally {
                this.submitting = false;
            }
        },

        async saveProvisionalDirect() {
            if (this.submitting) return;
            if (this.cart.length === 0) { this.showToast(window.TXT.cart_is_empty, 'error'); return; }
            // EDIT MODE: F9 = Update Bill (canProvisional gate skipped — the bill
            // already IS provisional, whatever its order type).
            if (this.editingBillId) return this.updateProvisionalBill();
            // Order-type flow rule: provisional bills are DELIVERY-only on restaurant
            // companies. Dine-In uses Hold/KOT/recall; Takeaway pays direct final.
            // Backend enforces the same rule (defence-in-depth).
            if (!this.canProvisional()) {
                this.showToast(this.orderType === 'dine_in' ? 'Dine-In uses Hold / KOT — provisional bills are for Delivery orders only.' : 'Takeaway is billed directly — provisional bills are for Delivery orders only.', 'error');
                return;
            }
            this.saveAsProvisional = true;
            this.showPayModal = false;
            // Task 514: direct provisional save = no checkbox surface — company
            // default use karo, stale per-bill untick inherit na ho.
            this.payPrintReceipt = this.billPrintDefault(this.orderType);
            await this.processPayment('cash');
        },

        async processPayment(method) {
            if (this.submitting) return;
            // Cash Received / Wapsi: snapshot the entered cash for the success popup
            // (client-side display works for BOTH cart sales and held-order pays).
            this.lastCashReceived = (method === 'cash') ? (parseFloat(this.cashReceived) || 0) : 0;
            // EDIT MODE: payments are blocked — update the bill (F9), then use
            // F10 → Make Final (the promote path owns quota/serial/PRA rules).
            if (this.editingBillId) {
                this.showPayModal = false;
                this.showToast(window.TXT.edit_mode_f9_then_f10, 'error');
                return;
            }
            // Capture provisional flag once at submission start so a stray
            // re-render/checkbox toggle mid-flight cannot flip the path.
            const provisional = !!this.saveAsProvisional;
            // Task 514: per-bill receipt print choice snapshot (checkbox unticked =
            // skip SIRF is bill ki receipt auto-print; KOT/PRA/popup untouched).
            const skipReceipt = !this.payPrintReceipt;

            // Task 287 — Delivery Prepaid: override the method to qr_payment on ALL
            // paths (held-order pay, manual/direct, restaurant hold→pay, offline queue)
            // so the bill is never booked as 'cash' and never enters the rider khata.
            // is_prepaid in the rider app derives from payment_method !== 'cash',
            // so no separate flag is needed (PosRiderTrackingController line ~252).
            if (this.deliveryPrepaid && this.orderType === 'delivery') {
                method = 'qr_payment';
            }

            // Task 1036: reserve the WhatsApp auto-open tab INSIDE this gesture
            // (synchronously, before any await) — provisionals are never shared.
            if (!provisional) this.reserveWaWindow(this.selectedCustomer?.phone);

            if (this.payingHeldOrderId) {
                this.submitting = true; this.stockError = '';
                const paidHeld = await this.payHeldOrderDirect(this.payingHeldOrderId, method, null, provisional, null, skipReceipt);
                this.submitting = false;
                if (!paidHeld) {
                    // Pay failed (stock / already paid / quota) — the order is still
                    // held and payingHeldOrderId stays set, so the pay modal (with
                    // the stockError banner when applicable) remains open for retry
                    // or Escape. Do NOT force-close it here or the banner dies.
                    return;
                }
                this.payingHeldOrderId = null;
                this.showPayModal = false; this.saveAsProvisional = false;
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
            // P7: incoming waiter carts ALWAYS bill via the manual path — their
            // restaurant order already exists; the hold endpoint would duplicate it.
            // Table-required guard (owner, 9 Aug 2026): a Dine-In sale — held OR
            // paid immediately — must not punch without a table when the company
            // manages tables. Incoming waiter orders are exempt (order already
            // exists; legacy tableless ones must stay settleable).
            if (this.tableBoardEnabled && this.orderType === 'dine_in' && !this.selectedTable && !this.incomingOrderId) {
                this.showToast(window.TXT.dine_in_table_required, 'error');
                this.openTablePicker();
                return;
            }
            if (!this.isRestaurantMode || this.hasManualItems() || this.hasDealItems() || this.incomingOrderId) {
                return await this.processPaymentManual(method, provisional, skipReceipt);
            }

            // Task 994: HARD double-submit guard — while a pay request is in
            // flight (button spinner showing), Enter / 1 / 2 / a second tap must
            // be a no-op, not a second request.
            if (this.submitting) return;
            const now = Date.now();
            // Debounce toast (architect, 26 Jul 2026): one-tap CASH/CARD made this
            // 3s guard easily reachable in fast shops — a SILENT return looked like
            // a dead button. Tell the cashier instead of ignoring the tap.
            if (now - this.lastPayTime < 3000) { this.showToast(window.TXT.wait_prev_bill_saved, 'error'); return; }
            this.lastPayTime = now;
            this.submitting = true; this.stockError = '';
            // Task 994: one idempotency uuid per SALE attempt — reused across
            // retries of this same cart so the server can dedupe a retry whose
            // first attempt succeeded but lost the response mid-flight.
            if (!this.payAttemptUuid) this.payAttemptUuid = this._newOfflineUuid();
            try {
                const holdRes = await this.fetchWithTimeout('{{ route("pos.restaurant.orders.hold") }}', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    // billing_flow — this is the INTERNAL hold-then-pay billing pass-through
                    // (normal final sale on restaurant companies), NOT an explicit Hold/KOT
                    // action. Backend skips the dine_in-only flow gate when this flag is set;
                    // the explicit Hold button / F5 (holdOrder fn above) sends no flag and
                    // stays gated client + server.
                    body: JSON.stringify({ items: this.cart, order_type: this.orderType, table_id: this.selectedTable?.id || null, customer_id: this.selectedCustomer?.id || null, customer_name: this.selectedCustomer?.name || null, customer_phone: this.selectedCustomer?.phone || null, kitchen_notes: this.kitchenNotes, priority: this.priorityOrder, recalled_order_id: this.recalledOrderId, discount_type: this.discountAmount > 0 ? this.discountType : null, discount_value: this.discountAmount > 0 ? this.discountValue : 0, discount_amount: this.discountAmount, billing_flow: true, pay_uuid: this.payAttemptUuid, delivery_address: this.orderType === 'delivery' ? ((this.selectedDeliveryAddress || '').trim() || null) : null }),
                });
                if (!holdRes.ok) {
                    const bodyText = await holdRes.text().catch(() => '');
                    console.error('[holdOrder] HTTP', holdRes.status, holdRes.statusText, bodyText.slice(0, 500));
                    // Surface the backend's real reason (invalid table/customer, manual
                    // line, product not found) instead of a generic "Hold HTTP 400".
                    let holdErr = null;
                    try { holdErr = JSON.parse(bodyText); } catch (_) {}
                    throw new Error((holdErr && holdErr.message) || ('Hold HTTP ' + holdRes.status + ' ' + holdRes.statusText));
                }
                const holdData = await holdRes.json();
                if (!holdData.success) { this.showToast(holdData.message || window.TXT.failed_word, 'error'); this.submitting = false; return; }
                const savedTotal = this.totalAmount;
                // Task 994: REPLAY — the previous attempt's pay already succeeded
                // server-side (response was lost); the server returned the ORIGINAL
                // bill instead of re-holding. Jump straight to the pay-success
                // handling: receipt popup + auto-print chain fire now (they never
                // fired on the lost attempt), and NO duplicate bill/KOT exists.
                if (holdData.already_paid) {
                    this.payAttemptUuid = null;
                    this.recalledOrderId = null;
                    this.applyPaySuccess(holdData, holdData.order_id || null, method, savedTotal, this.orderType, skipReceipt, provisional, null);
                    this.clearCart();
                    this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); });
                    this.showPayModal = false; this.submitting = false; this.saveAsProvisional = false;
                    return;
                }
                const paid = await this.payHeldOrderDirect(holdData.order.id, method, savedTotal, provisional, null, skipReceipt, this.payAttemptUuid);
                if (!paid) {
                    // Pay failed — KEEP the cart for instant retry and remember the
                    // freshly-created held order so the next Pay REUSES it via
                    // recalled_order_id (hold endpoint cancels+replaces it) instead
                    // of minting a duplicate 'held' row per attempt (Frost & Brew
                    // live issue accumulated 4 orphan held orders this way).
                    // payAttemptUuid intentionally NOT cleared — the retry must ride
                    // the same uuid so the server can dedupe a lost-response success.
                    this.recalledOrderId = holdData.order.id;
                    // Task 1028: cart is waqt bilkul wahi hai jo abhi hold hua —
                    // baseline yahin le lo taake BAAD ki edits hi dirty ginein.
                    this._recallCartBaseline = this.cartEditFingerprint();
                    this.submitting = false;
                    return;
                }
                this.payAttemptUuid = null; // sale done — next sale mints a fresh key
                this.clearCart();
                // Auto-focus phone input → ready for next sale, NO dead focus.
                this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); });
            } catch (e) {
                console.error('[processPayment] FAIL', e);
                // Task 994: a TIMEOUT gets its own message — cashier must know the
                // retry is safe (same uuid = server replays, never duplicates).
                if (this._isTimeoutError(e)) {
                    this.showToast(window.TXT.pay_timeout_retry, 'error');
                } else {
                    this.showToast(window.TXT.submit_failed_prefix + (e?.message || e?.name || 'unknown') + ' — check console (F12)', 'error');
                }
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
            // Task 994: hard double-submit guard (mirrors processPayment).
            if (this.submitting) return;
            const now = Date.now();
            // Same debounce toast as processPayment — one-tap must never look dead.
            if (now - this.lastPayTime < 3000) { this.showToast(window.TXT.wait_prev_bill_saved, 'error'); return; }
            this.lastPayTime = now;
            this.submitting = true; this.stockError = '';
            const savedTotal = this.totalAmount;
            try {
                // storeInvoice expects: items[].{name, quantity, unit_price, type?, item_id?, is_tax_exempt?, _manual?}
                // and discount_type/value/payment_method at top level.
                const payload = {
                    items: this.cart.map(c => ({
                        name: c.item_name,
                        quantity: c.quantity,
                        unit_price: c.unit_price,
                        type: c.item_type === 'service' ? 'service' : (c.item_type === 'deal' ? 'deal' : 'product'),
                        item_id: c.item_id || null,
                        is_tax_exempt: !!c.is_tax_exempt,
                        special_notes: c.special_notes || null,
                        // Flag manual cart lines so the backend doesn't auto-
                        // create a permanent product for them.
                        _manual: (c.item_type === 'manual' || !c.item_id) ? true : false,
                    })),
                    payment_method: method,
                    // Cash Received / Wapsi — server stores cash_received + change_due
                    // so the printed receipt (agent silent print incl.) carries them.
                    cash_received: (method === 'cash' && parseFloat(this.cashReceived) > 0) ? parseFloat(this.cashReceived) : null,
                    // Order-type flow rules (owner, Jul 2026): backend gates
                    // provisional saves to Delivery-only for restaurant companies.
                    order_type: this.orderType,
                    discount_type: this.discountType || 'percentage',
                    discount_value: this.discountAmount > 0 ? this.discountValue : 0,
                    customer_name: this.selectedCustomer?.name || null,
                    customer_phone: this.selectedCustomer?.phone || null,
                    // Item #1: delivery-address snapshot — only rides on Delivery orders.
                    delivery_address: this.orderType === 'delivery' ? ((this.selectedDeliveryAddress || '').trim() || null) : null,
                    kitchen_notes: this.kitchenNotes,
                    // Dine-In picker — backend auto-frees this reserved table once the
                    // bill is stored (reserved → available; occupied untouched).
                    table_id: this.selectedTable?.id || null,
                    // PROVISIONAL BILL FLOW — when true, storeInvoice forces
                    // pra_status='local' regardless of company.pra_reporting_enabled
                    // and skips PRA submission. Bill stays editable / deletable.
                    save_as_provisional: !!provisional,
                    // Task 646: waiter order loaded in the cart — FINAL bills carry
                    // its id so storeInvoice settles it (links pos_transaction_id)
                    // BEFORE responding: the very first receipt print can then show
                    // the "Waiter:" line. Provisionals never consume the order.
                    incoming_order_id: (!provisional && this.incomingOrderId) ? this.incomingOrderId : null,
                    // OFFLINE-FIRST dedupe key rides on EVERY attempt (online too).
                    // If the response is lost mid-flight (flaky WiFi: server saved
                    // the bill but the reply never arrived), the queued replay
                    // carries the SAME uuid → server's replay guard returns the
                    // existing bill instead of creating a duplicate.
                    offline_uuid: this._newOfflineUuid(),
                    // Task 1349: COUNTER attribution — the device's remembered
                    // counter. Inside the payload on purpose: an offline-queued
                    // bill then replays with its ORIGINAL counter. Server
                    // re-validates (company-scoped + active) and stamps NULL if
                    // the counter is gone — a sale is never blocked over it.
                    terminal_id: this.terminalId || null,
                };
                // OFFLINE-FIRST (Jul 2026): no internet → queue the bill on this
                // device (IndexedDB) and keep billing. Sync engine replays it.
                // Task 117: NEW offline queueing is plan-gated (Business+). Cart
                // stays intact so the cashier can retry when internet returns.
                if (!navigator.onLine && !this.offlineAllowed) {
                    this.showToast(window.TXT.offline_plan_locked, 'error');
                    this.submitting = false;
                    return;
                }
                if (!navigator.onLine) {
                    await this.queueOfflineBill(payload, method, savedTotal, skipReceipt);
                    this.showPayModal = false;
                    this.submitting = false;
                    this.saveAsProvisional = false;
                    return;
                }
                let res;
                try {
                    // Task 994: hard 20s timeout — a HUNG server (reachable but not
                    // answering) must not freeze the pay button for minutes. An
                    // aborted request falls into the same offline-queue path below;
                    // the payload's offline_uuid dedupes server-side if the hung
                    // attempt actually succeeded, so no duplicate bill either way.
                    res = await this.fetchWithTimeout('{{ route("pos.invoice.store") }}', {
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
                    // fetch threw = server unreachable (WiFi says "connected" but
                    // internet is dead) OR timed out. Same offline path — HTTP
                    // errors from a REACHABLE server never land here.
                    console.warn('[storeInvoice] network unreachable/timeout — queueing offline', netErr);
                    if (!this.offlineAllowed) { // Task 117: plan-gated (cart intact for retry)
                        this.showToast(window.TXT.offline_plan_locked, 'error');
                        this.submitting = false;
                        return;
                    }
                    await this.queueOfflineBill(payload, method, savedTotal, skipReceipt);
                    this.showPayModal = false;
                    this.submitting = false;
                    this.saveAsProvisional = false;
                    return;
                }
                let data = null;
                let rawBody = '';
                try { rawBody = await res.text(); data = JSON.parse(rawBody); } catch(_) {}
                if (!res.ok || !data || !data.success) {
                    console.error('[storeInvoice] HTTP', res.status, res.statusText, rawBody.slice(0, 500));
                    const msg = (data && (data.message || data.error)) || ('Failed (HTTP ' + res.status + ') — F12 console');
                    // Task 216: quota-full 403 where the backend says a provisional retry
                    // would pass the flow rules → offer a one-click provisional save
                    // instead of a dead-end error. confirm() cancel = keep the plain error.
                    if (res.status === 403 && data && data.quota_full && data.provisional_allowed && !provisional) {
                        this.submitting = false;
                        if (confirm(window.TXT.quota_provisional_prompt || msg)) {
                            this.lastPayTime = 0; // bypass the 3s double-tap debounce for this deliberate retry
                            return await this.processPaymentManual(method, true, skipReceipt);
                        }
                    }
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
                // P7: waiter-origin sales DO have a restaurant order — keep its id so
                // the receipt popup's KOT button can reprint the full kitchen ticket.
                this.lastOrderId = this.incomingOrderId || null;
                this.lastTxnKotId = null; // fresh sale — purani promoted-KOT id clear
                // Task 1379: server-confirmed "kitchen ne ye lines abhi tak nahi dekhin".
                // Same flag the auto-print chain uses below, so the popup's KOT button
                // knows whether pressing it would be a first send or a reprint.
                this.lastKotPending = !!data.kot_pending;
                // Task 1025: paid bill ki order type ka snapshot — clearCart/reset ke
                // baad tables-first wapsi isi se faisla karti hai (live widget se nahi).
                this.lastOrderType = this.orderType || null;
                this.lastTotal = Math.round(savedTotal || data.total_amount || 0);
                this.lastPaymentMethod = method;
                this.lastPraNumber = data.pra_invoice_number || '';
                this.lastPraStatus = data.pra_status || '';
                this.lastWaiterName = (this.incomingOrderInfo && this.incomingOrderInfo.waiter) ? this.incomingOrderInfo.waiter : '';
                this.lastItemsCount = (this.cart || []).reduce((s, i) => s + (parseFloat(i.quantity) || 0), 0);
                this.lastSaleAt = Date.now();
                this.setWaBill(data); // Task 1036: WhatsApp Bill button/auto-open
                this.showReceipt = true;
                this.scheduleReceiptAutoClose();
                this.startPraPoll(); // Task 655: agent-mode 'pending' → badge + receipt auto-flip
                this.$nextTick(() => { setTimeout(() => this.triggerConfetti(), 300); });
                // Auto-print receipt for manual-cart bills too (parity with held-order pay).
                // DELIVERY bills saved here (provisional rider khata + manual-cart finals)
                // have NO restaurant order — KOT prints from the TRANSACTION (ZFC 28 Jul 2026).
                // "Payment pehle, KOT baad" (1 Aug 2026): toggle ON ho to PROVISIONAL
                // delivery bills (pra_status 'local') par KOT ruk jati hai — promote
                // (payment confirm) par nikalti hai. Final bills = payment ho chuki → KOT abhi.
                const kotHeldForPayment = !!this.kitchenSettings.delivery_kot_after_payment
                    && this.orderType === 'delivery' && (data.pra_status === 'local');
                const txnKotId = (this.isRestaurantMode && this.orderType === 'delivery' && !this.incomingOrderId && !kotHeldForPayment)
                    ? (data.transaction_id || null) : null;
                if (kotHeldForPayment) this.showToast(window.TXT.kot_held_until_payment, 'info');
                // Task 1356: waiter-origin final bills DO have a restaurant order.
                // The server tells us when that order still has never-printed lines
                // (waiter's own KOT never reached a printer) — only THEN do we pass
                // its id so the safety net can fire. A normally-printed waiter order
                // reports kot_pending=false, so settling it prints nothing (unchanged).
                const rescueKotOrderId = (data.kot_pending && data.kot_order_id) ? data.kot_order_id : null;
                this.runAutoPrintChain(rescueKotOrderId, this.orderType, txnKotId, skipReceipt, false, !!data.kot_pending);
                // P7: settle the linked waiter order (atomic server-side claim) —
                // frees the table and clears it from every cashier's Incoming list.
                // FINAL bills only: a provisional is editable/deletable, so it must
                // NOT consume the waiter order — the order stays in Incoming until
                // a final settles it (conscious decision per review).
                if (this.incomingOrderId && data.transaction_id && !provisional) {
                    // Task 880 moved settleWaiterOrder inside the DB transaction, so
                    // storeInvoice always returns waiter_order_settled:true for final
                    // waiter bills. The client-side completeIncomingOrder fallback
                    // (Task 646) is removed. If the flag is ever missing or false, we
                    // surface a visible error immediately — no silent paper-over.
                    if (data.waiter_order_settled !== true) {
                        console.error('[processPaymentManual] waiter_order_settled missing/false', data);
                        this.showToast((window.TXT.waiter_settle_failed || 'Waiter order settle failed — please refresh and check Incoming Orders.'), 'error');
                    }
                    this.loadIncoming();
                }
                this.clearCart();
                this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); });
                // Refresh provisional badge count if this save was provisional.
                if (provisional) { this.loadLocalBills(); }
                // Refresh failed badge — successful sales might leave a previous fail intact.
                this.loadFailedBills();
                this.loadReprintBills(); // Akhri Bills strip stays current
                if (this.tableBoardEnabled) this.loadTableStatus(); // Table Board: settled table frees up
                // This sale reached the server → we're online. Drain any bills
                // still queued from an earlier outage.
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
            if (this.submitting) return;
            this.payingHeldOrderId = orderId;
            this.showHeldOrders = false;
            this.stockError = '';
            this.showPayModal = true;
        },

        // Item #8 — method-aware total estimate for a HELD order shown in the Pay modal.
        // Mirrors RestaurantPosController::payOrder math: subtotal − order discount;
        // tax on non-exempt subtotal scaled by the discount ratio; whole-rupee round.
        // Server total from payOrder JSON remains authoritative for the receipt popup.
        heldOrderEstimate(method) {
            const o = this.heldOrders.find(x => x.id === this.payingHeldOrderId);
            if (!o) return 0;
            const items = o.items || [];
            const sub = items.reduce((s, i) => s + (parseFloat(i.subtotal) || 0), 0);
            const disc = parseFloat(o.discount_amount) || 0;
            const ratio = sub > 0 ? Math.max(0, (sub - disc) / sub) : 1;
            const taxable = items.filter(i => !i.is_tax_exempt).reduce((s, i) => s + (parseFloat(i.subtotal) || 0), 0);
            const rate = method === 'card'
                ? (this.taxRules['debit_card'] || this.taxRules['card'] || 8)
                : (this.taxRules['cash'] || 16);
            // Inclusive mode: menu total is method-independent (tax already inside) —
            // EXCEPT card-save mode, where a non-menu rate makes the total cheaper.
            if (this.taxInclusive) {
                if (this.taxMenuRate !== null && this.taxMenuRate > 0 && Math.abs(this.taxMenuRate - rate) >= 0.005) {
                    const after = Math.max(0, sub - disc);
                    const taxableAfter = taxable * ratio;
                    const exemptShare = Math.max(0, after - taxableAfter);
                    return Math.round(exemptShare + taxableAfter * (100 + rate) / (100 + this.taxMenuRate));
                }
                return Math.round(sub - disc);
            }
            const tax = Math.round(taxable * ratio * rate / 100);
            return Math.round(sub - disc + tax);
        },
        get payModalTotal() {
            if (this.payingHeldOrderId) return this.heldOrderEstimate(this.payMethodIndex === 1 ? 'card' : 'cash');
            // Method-aware for the live cart in EVERY tax mode (Jul 2026 redesign):
            // exclusive = card's own (lower) rate, card-save = cheaper card total,
            // plain inclusive = method-independent menu total. Matches the amounts
            // shown on the one-tap CASH/CARD buttons AND what the backend charges.
            return this.cartTotalForMethod(this.payMethodIndex === 1 ? 'card' : 'cash');
        },

        startNewAfterPayment() {
            // Task 779: Tables-first flow ON → "New" bhi bari Tables screen par le jata hai.
            if (this.returnToTablesAfterReceipt()) return;
            this.showReceipt = false;
            this.clearCart();
            this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); this.$refs.customerPhoneInput?.select(); });
        },

        // ── Task 779: TABLES-FIRST FLOW (return to the big Tables screen) ──────
        // printChainBusy(): TRUE jab tak koi print kaam chal raha hai — queued
        // chain timers, iframe postMessage handlers, ya async print work (silent
        // enqueue fetch / printReceipt ki PRA-grace). Navigation kabhi chalti
        // print nahi kaat'ti; _printViaIframe ka 30s hard ceiling guarantee karta
        // hai ke intezar kabhi hamesha ke liye nahi atakta.
        printChainBusy() {
            return !!((this.pendingPrintTimers && this.pendingPrintTimers.length)
                || (this.printMessageHandlers && this.printMessageHandlers.length)
                || this.printWorkInFlight > 0);
        },
        // Dine-in Hold/KOT ke baad (receipt popup nahi hota) — prints mukammal
        // hote hi full-screen Tables page par chalo.
        navigateToTablesWhenIdle() {
            if (this.tablesReturnPending) return;
            this.tablesReturnPending = true;
            const go = () => { window.location.assign('/pos/restaurant/tables'); };
            if (!this.printChainBusy()) { go(); return; }
            const iv = setInterval(() => {
                if (this.printChainBusy()) return;
                clearInterval(iv);
                go();
            }, 400);
        },
        // Receipt-close gateway: TRUE lautaye to caller kuch na kare (hum popup
        // ko print-chain khatam hone par khud band karke Tables par le jate hain).
        // Task 819: navigator.onLine guard HATA diya — Tables page ab TABLES_CACHE
        // mein hai; offline par bhi cached snapshot milta hai.
        returnToTablesAfterReceipt() {
            if (!this.tablesFirstFlow || !this.tableBoardEnabled) return false;
            // Task 1025 (owner video): Tables par wapsi SIRF dine-in bill ke baad —
            // takeaway/delivery counter sale ka cashier sale screen par hi rehta
            // hai (agla order lena hai, Tables board ka kaam nahi). lastOrderType
            // payment-success par frozen snapshot hai (live widget kabhi nahi).
            // Dine-in Hold/KOT wali navigateToTablesWhenIdle is gate se AZAAD hai.
            if (this.lastOrderType !== 'dine_in') return false;
            if (this.tablesReturnPending) return true; // pehle se raste mein
            this.tablesReturnPending = true;
            const finish = () => { this.showReceipt = false; window.location.assign('/pos/restaurant/tables'); };
            if (!this.printChainBusy()) { finish(); return true; }
            // Prints abhi chal rahi hain → popup khula rehta hai (auto-close ka
            // wahi "wait" usool) — chain drain hote hi band + navigate.
            const iv = setInterval(() => {
                if (this.printChainBusy()) return;
                clearInterval(iv);
                finish();
            }, 400);
            return true;
        },
        // X / Close / Esc — single gateway so tables-first shops return to the board.
        closeReceiptPopup() {
            if (this.returnToTablesAfterReceipt()) return;
            this.showReceipt = false;
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
            let ceilingTimerId = null; // Task 779: 30s hard-fallback timer id (cleared on success)
            const fireOnce = (() => {
                let invoked = false;
                return () => {
                    if (invoked) return;
                    invoked = true;
                    removeHandler();
                    // Task 779: success par 30s ceiling timer bhi saaf karo — warna woh
                    // pendingPrintTimers mein 30s tak para rehta aur receipt auto-close /
                    // tables-first navigation bila wajah "print chal rahi hai" samajh kar rukti.
                    if (ceilingTimerId) {
                        clearTimeout(ceilingTimerId);
                        this.pendingPrintTimers = this.pendingPrintTimers.filter(t => t !== ceilingTimerId);
                        ceilingTimerId = null;
                    }
                    // FOCUS RECOVERY (customer report Jul 2026 — "direct print shortcut
                    // not working properly"): after the print dialog closes, focus can
                    // stay INSIDE the hidden print iframe. Our shortcuts live on the
                    // PARENT document's keydown listener, so P (reprint) / Enter / Esc
                    // all go dead until the cashier clicks the page. Pull focus back.
                    try {
                        const ae = document.activeElement;
                        if (ae && ae.tagName === 'IFRAME') ae.blur();
                        window.focus();
                    } catch (err) {}
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
            ceilingTimerId = this.queuePrintTimer(fireOnce, 30000);
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

        // ── SILENT PRINTING via Desktop Sync Agent ──────────────────────
        // Enqueue a print job on the server; the agent picks it up and prints
        // directly on the configured printer (no dialog, no popup). Resolves
        // true ONLY on a 2xx {success:true} — anything else (agent offline,
        // feature off server-side, network error) returns false and the caller
        // falls back to the classic popup/iframe print path.
        // Print telemetry beacon (Task #63 — 30 Jul vanished-bill case): report
        // WHY a print didn't fire. sendBeacon survives page unload/navigation
        // (the prime suspect for the lost delivery bill); fetch keepalive is the
        // fallback. Fire-and-forget — never blocks or throws into the sale flow.
        printBeacon(stage, info = {}) {
            try {
                const body = JSON.stringify({
                    stage, ...info,
                    online: navigator.onLine,
                    flags: 'auto=' + (this.autoPrintEnabled ? 1 : 0) + ',silentBill=' + (this.silentBillPrint ? 1 : 0) + ',silentKot=' + (this.silentKotPrint ? 1 : 0) + ',kds=' + (this.kdsHandlesKot() ? 1 : 0),
                    at: new Date().toISOString(),
                });
                const url = '/pos/api/print-telemetry';
                if (navigator.sendBeacon && navigator.sendBeacon(url, new Blob([body], { type: 'application/json' }))) return;
                fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body, keepalive: true }).catch(() => {});
            } catch (_) {}
        },

        async trySilentPrint(payload, _retry = true) {
            // Task 779: enqueue fetch in flight = print WORK busy — tables-first
            // navigation intezar karti hai (warna fetch page-change par kat jata).
            this.printWorkInFlight++;
            try {
                return await this._trySilentPrintInner(payload, _retry);
            } finally {
                this.printWorkInFlight--;
            }
        },
        async _trySilentPrintInner(payload, _retry = true) {
            try {
                const res = await fetch('/pos/api/print-jobs', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify(payload),
                });
                if (!res.ok) {
                    // One retry on server hiccups (5xx) — a lost print job means a
                    // bill that never comes out of the printer (30 Jul 2026 incident).
                    if (_retry && res.status >= 500) { await new Promise(r => setTimeout(r, 1200)); return this.trySilentPrint(payload, false); }
                    // 4xx or exhausted retry: the job did NOT reach the queue.
                    this.printBeacon('silent-print-http-fail', { type: payload.type, transaction_id: payload.transaction_id, order_id: payload.restaurant_order_id, http_status: res.status });
                    return false;
                }
                const d = await res.json().catch(() => null);
                // Return the payload (truthy) so callers can read flags like
                // `deduped` (double-press guard) — false keeps the fallback path.
                return (d && d.success) ? d : false;
            } catch (e) {
                // Network blip — retry once before giving up to the popup fallback.
                if (_retry) { await new Promise(r => setTimeout(r, 1200)); return this.trySilentPrint(payload, false); }
                this.printBeacon('silent-print-network-fail', { type: payload.type, transaction_id: payload.transaction_id, order_id: payload.restaurant_order_id, error: (e && (e.name + ': ' + e.message)) || 'unknown' });
                return false;
            }
        },

        // TRUE when the KDS station auto-prints tickets itself — cashier-side
        // AUTOMATIC KOT fires (hold-time + pay-time chain) are duplicates and
        // must be skipped. Explicit reprints (Resend, receipt-popup KOT button)
        // intentionally bypass this.
        // KDS Auto Print only owns ticket printing while a KDS board is actually
        // OPEN (heartbeat within 90s) — otherwise cashier-side auto-KOT resumes.
        // Pizza Master incident (30 Jul 2026): KDS closed + toggle ON = no KOT anywhere.
        kdsHandlesKot() { return !!(this.kitchenSettings.kds_enabled && this.kitchenSettings.kds_auto_print && this.kitchenSettings.kds_alive); },

        // KOT gateway for the popup-window call sites (hold / resend-kitchen):
        // silent first, hidden-iframe fallback. delta=true prints ONLY
        // not-yet-printed rows (updated orders — kitchen has the rest).
        // Aug 2026 (edit-path KOT reliability): fallback was window.open — after
        // the awaited hold/resend fetches the user-gesture is GONE, so popup
        // blockers silently killed the slip. The hidden print-kot-frame iframe
        // (same path as printKitchenTicket) is immune and gives focus back.
        kotPrintOrPopup(orderId, delta = false) {
            const url = '/pos/restaurant/orders/' + orderId + '/kitchen-ticket?auto_print=1' + (delta ? '&delta=1' : '');
            const fallback = () => this._printViaIframe('print-kot-frame', url, 'width=380,height=620');
            if (!this.silentKotPrint) { fallback(); return; }
            this.trySilentPrint({ type: 'kot', restaurant_order_id: orderId, delta: delta }).then(ok => {
                if (ok) this.showToast(window.TXT.kot_sent_to_printer, 'success'); else fallback();
            });
        },

        async printReceipt(onAfterPrint) {
            if (!this.lastTransactionId) { if (typeof onAfterPrint === 'function') onAfterPrint(); return; }
            // Task 779: poore printReceipt ko print-WORK ginti mein rakho — praPrintGrace
            // ke intezar ke doran na timers hote hain na handlers, aur tables-first
            // navigation us khali gap mein page badal kar print kaat sakti thi.
            this.printWorkInFlight++;
            try {
                return await this._printReceiptInner(onAfterPrint);
            } finally {
                this.printWorkInFlight--;
            }
        },
        async _printReceiptInner(onAfterPrint) {
            // Task 655: agent-mode fiscal grace — bill abhi 'pending' hai to chand
            // seconds ka bounded intezar (submit aa jaye to PEHLI slip par hi PRA
            // fiscal number chapta hai), warna jo bhi haalat hai usi par print.
            // Kabhi block nahi hota; manual, auto-chain aur silent print teeno
            // isi raste se guzarte hain.
            await this.praPrintGrace();
            const url = (this.isRestaurantMode ? '/pos/restaurant/receipt/' : '/pos/transaction/') + this.lastTransactionId + (this.isRestaurantMode ? '?auto_print=1' : '/receipt?auto_print=1');
            console.log('[printReceipt] URL=', url, 'isRestaurantMode=', this.isRestaurantMode);
            const txnId = this.lastTransactionId;
            const fallback = () => {
                // Silent path failed → bill now depends on the popup/iframe route
                // (invisible when blocked). Leave a trail (Task #63).
                if (this.silentBillPrint) this.printBeacon('bill-popup-fallback', { type: 'bill', transaction_id: txnId });
                this._printViaIframe('print-receipt-frame', url, 'width=400,height=700', onAfterPrint);
            };
            if (this.silentBillPrint) {
                // Task 655 review fix: AWAIT the enqueue attempt (trySilentPrint never
                // rejects — it retries internally and resolves false on failure) so
                // printReceipt()'s promise settles only after the receipt job has
                // actually reached the queue (or fallen back). runAutoPrintChain's
                // silent fast path awaits this before creating the KOT job —
                // receipt-first → KOT-after holds even under network/agent latency.
                const ok = await this.trySilentPrint({ type: 'bill', transaction_id: this.lastTransactionId });
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
        // delta=true (auto-print chain on recalled orders) prints ONLY unprinted rows.
        printKitchenTicket(orderId, onAfterPrint, delta = false) {
            const id = orderId || this.lastOrderId;
            if (!id) { if (typeof onAfterPrint === 'function') onAfterPrint(); return; }
            const url = '/pos/restaurant/orders/' + id + '/kitchen-ticket?auto_print=1' + (delta ? '&delta=1' : '');
            const fallback = () => this._printViaIframe('print-kot-frame', url, 'width=350,height=600', onAfterPrint);
            if (this.silentKotPrint) {
                this.trySilentPrint({ type: 'kot', restaurant_order_id: id, delta: delta }).then(ok => {
                    if (ok) {
                        this.showToast(window.TXT.kot_sent_to_printer, 'success');
                        if (typeof onAfterPrint === 'function') onAfterPrint();
                    } else { fallback(); }
                });
                return;
            }
            fallback();
        },

        // KOT for ORDER-LESS bills (ZFC 28 Jul 2026): delivery provisionals /
        // manual-cart finals have no restaurant order — the kitchen ticket is
        // rendered from the TRANSACTION. Silent-first, iframe fallback.
        printTxnKitchenTicket(txnId, onAfterPrint) {
            if (!txnId) { if (typeof onAfterPrint === 'function') onAfterPrint(); return; }
            const url = '/pos/transactions/' + txnId + '/kitchen-ticket?auto_print=1';
            const fallback = () => this._printViaIframe('print-kot-frame', url, 'width=350,height=600', onAfterPrint);
            if (this.silentKotPrint) {
                this.trySilentPrint({ type: 'kot', transaction_id: txnId }).then(ok => {
                    if (ok) {
                        this.showToast(window.TXT.kot_sent_to_printer, 'success');
                        if (typeof onAfterPrint === 'function') onAfterPrint();
                    } else { fallback(); }
                });
                return;
            }
            fallback();
        },

        // ── P7 (F6): INCOMING WAITER ORDERS ───────────────────────────
        // Task 1097: If-None-Match ETag fast-path — 304 means list unchanged;
        // still read X-KDS-Alive from the 304 so the KDS flag stays fresh.
        async loadIncoming() {
            if (!this.isRestaurantMode) return;
            try {
                const hdrs = { 'Accept': 'application/json' };
                if (this._incomingEtag) hdrs['If-None-Match'] = this._incomingEtag;
                const res = await fetch('/pos/api/incoming-orders', { headers: hdrs });
                // Always capture KDS liveness — it's present on both 200 and 304.
                const kdsAlive = res.headers.get('X-KDS-Alive');
                if (kdsAlive !== null) this.kitchenSettings.kds_alive = (kdsAlive === '1');
                if (res.status === 304) return;
                if (!res.ok) return;
                const etag = res.headers.get('ETag');
                if (etag) this._incomingEtag = etag;
                this.incomingOrders = await res.json();
                // ZFC (3 Aug 2026): pehli KAMYAB fetch = baseline — jo orders pehle
                // se pending the woh boot par chime/"NAYA order" toast na bajayen
                // (2 din purana order har screen-load par chillata tha); bas EK
                // summary. Baseline khali poll par bhi set ho — warna baad mein
                // aane wala pehla ASLI naya order chupke se absorb ho jata.
                if (!this._incomingBaselined) {
                    this._incomingBaselined = true;
                    this.incomingOrders.forEach(o => {
                        if (!this.chimedIncoming.includes(o.id)) this.chimedIncoming.push(o.id);
                        if (!this.notifiedIncoming.includes(o.id)) this.notifiedIncoming.push(o.id);
                    });
                    if (this.incomingOrders.length && !document.hidden) this.showToast(this.incomingOrders.length + window.TXT.waiter_orders_pending_summary, 'info');
                    return;
                }
                this.maybeAutoLoadIncoming();
            } catch (e) { /* silent — badge just goes stale until next poll */ }
        },
        // Table-se-Bill (Jul 2026): AUTO-LOAD RETIRED — orders no longer land in
        // the cart on their own (owner: auto-appearing carts confused cashiers).
        // The cashier now clicks the purple "Order Tayyar" table in the table picker / TABLE board.
        // This is just the one-time toast nudge per new order.
        maybeAutoLoadIncoming() {
            if (!this.isRestaurantMode || !this.incomingOrders.length) return;
            // ZFC (3 Aug 2026): baseline loadIncoming() ki pehli kamyab fetch par
            // set hota hai. Us se PEHLE (visibilitychange boot se aage nikal jaye)
            // kuch na bajao — warna purane pending orders "NAYA" ban kar chillate.
            if (!this._incomingBaselined) return;
            // Task 106: chime fires even when the tab is HIDDEN — that's the whole
            // point of an audible alert. Separate dedupe list (chimedIncoming) so a
            // hidden-tab chime doesn't swallow the toast shown on return.
            // Order Sound (Aug 2026): ONE chime per poll batch, not per order —
            // three orders at once must not turn the counter into an alarm clock.
            let freshChime = 0;
            this.incomingOrders.forEach(o => {
                if (this.chimedIncoming.includes(o.id)) return;
                this.chimedIncoming.push(o.id);
                freshChime++;
            });
            if (freshChime > 0 && this.orderSound) this.playOrderChime();
            // Toast only when visible (unchanged behavior); pending toasts fire on
            // the visibilitychange hook / next poll once the cashier returns.
            if (document.hidden) return;
            this.incomingOrders.forEach(o => {
                if (this.notifiedIncoming.includes(o.id)) return;
                this.notifiedIncoming.push(o.id);
                this.showToast(window.TXT.new_waiter_order_prefix + o.order_number + (o.table ? ' (T-' + o.table + ')' : '') + window.TXT.sfx_open_table_board, 'success');
            });
        },
        // Two-tone WebAudio chime — no audio file, works offline, ~0.35s. Browsers
        // block audio before the first user gesture; the try/catch swallows that
        // (cashier screens always get a click/keypress long before an order lands).
        playOrderChime() {
            try {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                if (!this._chimeCtx) this._chimeCtx = new Ctx();
                const ctx = this._chimeCtx;
                if (ctx.state === 'suspended') ctx.resume().catch(() => {});
                const t0 = ctx.currentTime;
                [[880, 0], [1174.66, 0.16]].forEach(([freq, dt]) => {
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.type = 'sine'; osc.frequency.value = freq;
                    gain.gain.setValueAtTime(0.0001, t0 + dt);
                    gain.gain.exponentialRampToValueAtTime(0.25, t0 + dt + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.0001, t0 + dt + 0.30);
                    osc.connect(gain); gain.connect(ctx.destination);
                    osc.start(t0 + dt); osc.stop(t0 + dt + 0.32);
                });
            } catch (e) { /* silent — sound is best-effort */ }
        },
        openIncoming() {
            this.showIncoming = true;
            this.incomingLoading = true;
            this.loadIncoming().finally(() => { this.incomingLoading = false; });
        },
        loadIncomingToCart(o) {
            if (this.cart.length && !confirm(window.TXT.replace_cart_with_waiter + o.order_number + '?')) return;
            this.cart = (o.items || []).map(it => ({
                cart_uid: 'inc' + Date.now() + '_' + Math.random().toString(36).slice(2, 9),
                item_id: it.item_id || null,
                item_type: it.item_id ? (it.item_type || 'product') : 'manual',
                item_name: it.name,
                quantity: parseFloat(it.quantity) || 1,
                unit_price: parseFloat(it.unit_price) || 0,
                special_notes: it.special_notes || '',
                is_tax_exempt: !!(it.is_tax_exempt || it.is_third_schedule),
                is_third_schedule: !!it.is_third_schedule,
                item_discount_type: 'percentage', item_discount_value: 0, showItemDiscount: false,
            }));
            this.incomingOrderId = o.id;
            this.incomingOrderInfo = o; // Task #643: waiter/order badge + cart-cancel modal data
            // Table stays attached to the RESTAURANT order — settlement frees it.
            // Carry the waiter order's type onto the bill: pos_transactions.order_type
            // snapshot drives the DINE-IN / TAKE AWAY / DELIVERY receipt badge, so a
            // claimed dine-in must NOT bill as the default 'takeaway'. (Direct final
            // pay is allowed for every type; only Hold/Provisional are type-gated.)
            this.orderType = o.order_type || 'takeaway';
            this.selectedCustomer = (o.customer_name || o.customer_phone)
                ? { id: null, name: o.customer_name || 'Walk-in', phone: o.customer_phone || '' }
                : null;
            this.kitchenNotes = o.kitchen_notes || '';
            // Task 1028: claim ke foran baad ka fingerprint — table-switch par
            // edited claimed carts ko bhi explicit choice (discard/cancel) milti hai.
            this._recallCartBaseline = this.cartEditFingerprint();
            this.showIncoming = false;
            this.activeCartIndex = this.cart.length ? 0 : -1;
            this.flowStep = 'cart';
            this.showToast(window.TXT.waiter_order_prefix + o.order_number + ' loaded — take payment to settle it', 'success');
        },
        // Full KOT reprint (all items) or delta print (only newly-added items).
        printIncomingKot(o, delta = false) {
            // Task 1379: mirrors the button's x-show AND the server's rule — a full
            // ticket is a reprint only once every line is already printed. Delta
            // ("Added") prints are always a first fire for those rows.
            if (!delta && !this.canKotReprint && ((o && o.items) || []).every(i => i.printed)) { this.showToast(window.TXT.kot_reprint_not_allowed, 'error'); return; }
            const url = '/pos/restaurant/orders/' + o.id + '/kitchen-ticket?auto_print=1' + (delta ? '&delta=1' : '');
            const done = () => this.loadIncoming();
            const fallback = () => this._printViaIframe('print-kot-frame', url, 'width=350,height=600', done);
            if (this.silentKotPrint) {
                this.trySilentPrint({ type: 'kot', restaurant_order_id: o.id, delta: delta }).then(ok => {
                    if (ok) { this.showToast(window.TXT.kot_sent_to_printer, 'success'); done(); } else { fallback(); }
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
        // ═══ Task 565: opt-in Yes/No print-confirm dialog ═══
        // openPrintConfirm: dialog foran dikhao (koi artificial delay nahi) aur
        // Yes-action pending rakho. Focus Yes button par setTimeout se jata hai
        // ($nextTick nahi): post-sale code customerPhoneInput ko $nextTick par
        // focus karta hai — dialog ka Yes USKE BAAD jeetna chahiye.
        openPrintConfirm(onYes, onNo = null) {
            this.printConfirmAction = onYes;
            // Task 1025: optional "No" action — auto-print chain isse KOT-only
            // re-entry deta hai (No = sirf customer bill skip, KOT phir bhi chale).
            this.printConfirmNoAction = onNo;
            this.printConfirmChoice = 'yes';
            this.showPrintConfirm = true;
            setTimeout(() => { try { if (this.showPrintConfirm) this.$refs.printConfirmYes?.focus(); } catch (err) {} }, 50);
        },
        // resolvePrintConfirm: Yes → pending action (confirmed auto-print chain /
        // offline receipt) bilkul mojooda timings ke saath. No → sirf CUSTOMER
        // RECEIPT skip: agar caller ne onNo diya hai (auto-print chain deta hai)
        // to wahi chalta hai — KOT apne mojooda gates se phir bhi nikalti hai
        // (Task 1025: takeaway/delivery counter sale par kitchen ko ticket chahiye,
        // khana abhi paka nahi). onNo ke baghair (offline receipt path) No = kuch
        // nahi khulta. Dono surat mein focus sale screen par wapas — shortcuts
        // zinda rahen (iframe focus-recovery pattern).
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
            if (yes && typeof action === 'function') {
                action();
                return;
            }
            if (!yes) {
                if (this.silentBillPrint || this.silentKotPrint) {
                    // Silent-print shops expect paper on every sale — record the
                    // deliberate "No" so a "bill never printed" report is diagnosable.
                    // (KOT may STILL print via noAction below — the beacon means
                    // "cashier declined the customer bill", not "nothing printed".)
                    this.printBeacon('print-confirm-no', { transaction_id: this.lastTransactionId || '', order_id: this.lastOrderId || '' });
                }
                if (typeof noAction === 'function') noAction();
            }
        },
        // Task 514: per-bill checkbox ka default — company setting ka mirror
        // (dine-in par print_on_pay_dinein, warna print_on_pay).
        billPrintDefault(orderType = null) {
            return !!this.autoPrintEnabled && !((orderType === 'dine_in') && !this.dineinAutoPrint);
        },
        // Pay modal ke checkbox ka default order-type: held-order pay par us order
        // ki apni type (widget se nahi), warna current order-type widget.
        payModalOrderType() {
            const held = this.payingHeldOrderId ? this.heldOrders.find(o => o.id === this.payingHeldOrderId) : null;
            return (held && held.order_type) || this.orderType || null;
        },
        // skipReceiptOverride (Task 514): cashier ne per-bill "Receipt print karein"
        // checkbox UNTICK kiya — SIRF is bill ki receipt auto-print skip; KOT gate
        // (wantsKot) aur baaki chain waise hi chalti hai.
        // kotPending (Task 1356): server ne bataya ke is bill ki kuch lines
        // kitchen ne KABHI nahi dekhin (line-level kot_printed_at se — hold ka
        // kot_sent_at jhooth bol sakta hai). Aisi surat mein dine-in ki blanket
        // bandish hat jati hai aur SIRF un-dekhi lines ki delta parchi nikalti hai.
        runAutoPrintChain(orderId, orderType = null, txnKotId = null, skipReceiptOverride = false, askConfirmed = false, kotPending = false) {
            // MASTER GATE — auto-print OFF means NOTHING fires automatically.
            // Telemetry (Task #63): silent-print shops expect paper on every sale —
            // record WHY the chain did nothing so a "bill never printed" report is
            // diagnosable from server logs (beacon is gated on silent print being
            // configured, so non-silent shops add no noise).
            if (!this.autoPrintEnabled) {
                if (this.silentBillPrint) this.printBeacon('auto-chain-off', { transaction_id: this.lastTransactionId, order_id: orderId, type: orderType || '' });
                return;
            }
            const hasReceipt = !!this.lastTransactionId;
            // Pizza Master (11 Aug 2026): dine-in FINAL receipt auto-print is company-
            // optional — proof bill table par pehle diya ja chuka hota hai, final ka
            // auto-print kaghaz zaya karta tha. Receipt popup / manual print bahaal.
            const dineinSkipReceipt = (orderType === 'dine_in') && !this.dineinAutoPrint;
            // KDS Auto-Print owns ticket printing → cashier auto-KOT suppressed
            // (owner, Jul 2026). Manual Resend / receipt-popup KOT button stay.
            // DINE-IN finals NEVER auto-KOT (owner, Jul 2026): the kitchen got its
            // ticket at hold — by final the food is already served, the receipt
            // carries the items. Takeaway/Delivery counter sales keep Auto-KOT
            // (kitchen cooks AFTER payment there).
            // txnKotId (ZFC 28 Jul 2026): order-less delivery bills (provisional
            // rider khata / manual-cart finals) KOT from the TRANSACTION instead.
            //
            // TASK 1356 SAFETY NET (owner video, dine-in Table 02 — cashier pressed
            // CASH without "Send to Kitchen": receipt printed, kitchen got NOTHING).
            // The dine-in blanket block above assumes the ticket already went at
            // hold — true for a held/recalled order, FALSE for a cart settled
            // straight to pay. So the block now yields to a server-confirmed fact:
            // kotPending = this order still has lines the kitchen has NEVER seen
            // (line-level kot_printed_at). Then, and only then, dine-in fires too —
            // as a DELTA, so a fully-printed bill still prints nothing (no second
            // slip on held / waiter settles) and an empty ticket is impossible.
            // Server-side gates (restaurant mode + KOT feature + the shop's
            // kot_on_final_if_unsent switch) mean plain retail never sees this.
            // kdsHandlesKot: a live auto-printing KDS owns the slip — the order is
            // surfaced on the KDS board instead (RestaurantKdsController rescue).
            const kotRescue = !!kotPending
                && !!orderId
                && this.kitchenSettings.kot_on_final_if_unsent !== false
                && !this.kdsHandlesKot();
            const wantsKot = (!!this.autoKotEnabled && (!!orderId || !!txnKotId) && orderType !== 'dine_in' && !this.kdsHandlesKot())
                || kotRescue;
            const wantsReceipt = hasReceipt && !dineinSkipReceipt && !skipReceiptOverride;
            // KOT delta = ALWAYS in the auto chain (owner, Jul 2026): the kitchen
            // already has every line that printed at hold / waiter-send / recall —
            // auto-KOT at pay must fire ONLY still-unprinted rows (fresh takeaway
            // pass-through orders have no stamps, so delta prints the full ticket
            // there; a fully-printed order prints NOTHING — no duplicate KOT when
            // the cashier settles a waiter/held bill).
            const kotDelta = true;
            if (!wantsReceipt && !wantsKot) {
                // Nothing to print at pay-success = the 30 Jul failure signature
                // (lastTransactionId missing → no receipt job was ever attempted).
                // dineinSkipReceipt = intentional feature-off for this company
                // (print_on_pay_dinein false): don't beacon — it would fire on
                // EVERY dine-in pay and bury real print failures in log noise.
                // skipReceiptOverride = cashier deliberately unticked the per-bill
                // receipt checkbox (Task 514): intentional skip, same as dineinSkipReceipt
                // — don't beacon or every manual skip floods the telemetry log.
                if (!dineinSkipReceipt && !skipReceiptOverride && (this.silentBillPrint || this.silentKotPrint)) this.printBeacon('auto-chain-nothing', { order_id: orderId || txnKotId, type: orderType || '' });
                return;
            }
            // Task 565: opt-in Yes/No confirm — kuch print hone WALA hai aur flag
            // ON hai to pehle poocho (dialog foran, koi delay nahi). Yes = YEHI
            // chain confirmed re-entry se chale — silent-first / iframe fallback /
            // 150ms-80ms timings sab waisa ka waisa.
            // Task 1025 (owner video, live shop): sawaal SIRF customer receipt ka
            // hai — "No" par KOT apne mojooda gates (auto-KOT ON, not dine-in,
            // KDS-suppress, order/txn id) se phir bhi fire hoti hai (skip-receipt
            // re-entry), warna takeaway/delivery par kitchen ko ticket hi nahi
            // milta tha. Receipt print honi hi nahi (wantsReceipt false — dine-in
            // suppress / per-bill skip) to poochte bhi nahi: KOT-only chain seedha
            // chalti hai, callbacks/idle signal kabhi stall nahi hote.
            if (this.printConfirmAsk && !askConfirmed && wantsReceipt) {
                this.openPrintConfirm(
                    () => this.runAutoPrintChain(orderId, orderType, txnKotId, skipReceiptOverride, true, kotPending),
                    () => this.runAutoPrintChain(orderId, orderType, txnKotId, true, true, kotPending),
                );
                return;
            }
            // Task 1356: cashier ko batao ke kitchen tak parchi chali gayi — warna
            // usay pata hi nahi chalta ke us ne "Send to Kitchen" dabaya hi nahi
            // tha. Yahan (chain ke faisle par) toast dete hain, printKitchenTicket
            // ke andar nahi: silent aur popup dono raaste cover ho jate hain.
            if (kotRescue) this.showToast(window.TXT.kot_auto_sent_on_final, 'success');
            // Blind-spot beacon (review catch): KOT will print but NO receipt is
            // possible (lastTransactionId missing) — the bill itself vanishes.
            if (wantsKot && !wantsReceipt && (this.silentBillPrint || this.silentKotPrint)) {
                this.printBeacon('kot-without-receipt', { order_id: orderId || txnKotId, type: orderType || '' });
            }
            // Task 1379: the moment the chain sends the ticket, the popup's KOT
            // button stops being a first send and becomes a reprint — so a blocked
            // cashier loses it exactly when the server would start refusing it.
            const fireKot = (cb) => {
                this.lastKotPending = false;
                return orderId
                    ? this.printKitchenTicket(orderId, cb, kotDelta)
                    : this.printTxnKitchenTicket(txnKotId, cb);
            };
            this.$nextTick(() => {
                if (wantsReceipt && wantsKot) {
                    // FAST PATH (ZFC 28 Jul 2026 — "KOT 15-20 sec late"): when BOTH
                    // prints go through the silent agent queue there is no print
                    // dialog to serialize around — enqueue receipt and KOT jobs
                    // IMMEDIATELY (agent prints them in order anyway) instead of
                    // waiting for the receipt roundtrip before creating the KOT job.
                    if (this.silentBillPrint && this.silentKotPrint) {
                        // Task 994 (owner voice note 16 Aug 2026 — "KOT der se
                        // nikli"): the Task 655 receipt-first sequencing made the
                        // KOT job wait behind praPrintGrace() (up to ~5s fiscal
                        // wait) PLUS the receipt-enqueue roundtrip — on a slow
                        // shop link that pushed the kitchen slip a full poll
                        // cycle later. The KOT needs NO fiscal number, so enqueue
                        // it IMMEDIATELY, in parallel: fireKot() first (its job
                        // POST leaves right away), receipt keeps its own bounded
                        // grace. Different printers make ordering moot; on a
                        // shared printer the agent still prints jobs in queue
                        // order. Silent-print stamping/dedupe rules unchanged.
                        this.queuePrintTimer(async () => { fireKot(); await this.printReceipt(); }, 150);
                        return;
                    }
                    this.queuePrintTimer(() => {
                        this.printReceipt(() => {
                            this.queuePrintTimer(() => fireKot(), 80);
                        });
                    }, 150);
                } else if (wantsReceipt) {
                    this.queuePrintTimer(() => this.printReceipt(), 150);
                } else if (wantsKot) {
                    // Pathological case: no transaction (so no receipt possible) but KOT requested.
                    this.queuePrintTimer(() => fireKot(), 150);
                }
            });
        },

        // ─── PROVISIONAL BILLS API helpers ──────────────────────────────────
        // Lightweight fetch + inline action methods. All errors degrade to a
        // toast — modal stays open so cashier doesn't lose context.
        async loadLocalBills() {
            this.localBillsLoading = true;
            try {
                const res = await fetch('{{ route('pos.api.provisional-bills') }}', {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                });
                if (!res.ok) { this.localBillsLoading = false; return; }
                const data = await res.json();
                if (data && data.success) {
                    this.localBills = data.bills || [];
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
        openLocalBills() {
            this.activeLocalIndex = 0;
            this.localSearch = '';
            this.showLocalBills = true;
            this.loadLocalBills();
            this.$nextTick(() => { const el = this.$refs.localSearchInput; if (el) el.focus(); });
        },
        // Filtered view of localBills — matches invoice number, customer name,
        // phone, or delivery address. ALL list rendering + keyboard nav MUST go
        // through this (never raw localBills) or index-based actions hit the wrong bill.
        // "Payment First, Then KOT" v2 (Aug 2026): payment confirm hote hi cashier
        // F10 se KOT bhej deta hai — raat ke Make Final ka intezar nahi. Render
        // par server stamp laga deta hai, is liye promote dobara nahi bhejta.
        // Task 1368: jis provisional ka restaurant order hai (hold → pay wala
        // andaruni raasta) us ki DELTA parchi nikalti hai — sirf wo lines jo
        // kitchen ne dekhi hi nahi, poora reprint kabhi nahi. Order-less bill
        // (manual cart) purani tarah TRANSACTION se chhapti hai.
        sendProvisionalKot(bill) {
            if (!bill || !bill.kot_pending) return;
            bill.kot_pending = false; // optimistic — the render stamps what it printed
            if (bill.kot_order_id) { this.printKitchenTicket(bill.kot_order_id, null, true); return; }
            this.printTxnKitchenTicket(bill.id);
        },
        // Per-item note toggle (owner, 3 Aug 2026). Focus via document.querySelector —
        // x-for row scope mein component-root $refs undefined hote hain (Alpine rule).
        toggleItemNote(index) {
            const item = this.cart[index];
            if (!item) return;
            item._showNote = !item._showNote;
            if (item._showNote) {
                this.$nextTick(() => {
                    const el = document.querySelector('[data-item-note="' + index + '"]');
                    if (el) el.focus();
                });
            }
        },
        // ─── PENDING DELIVERIES panel (Task 114) ────────────────────────────
        // TODAY's business-day delivery provisionals only. Bills without a
        // business_date (pre-migration window) are included rather than hidden.
        pendingDeliveryBills() {
            const isToday = b => (!this.bizToday || !b.business_date || b.business_date === this.bizToday);
            const prov = this.localBills.filter(b => b.order_type === 'delivery' && isToday(b));
            // FINAL delivery bills bhi (3 Aug 2026): jo abhi deliver nahi hue ya
            // cash rider ke khaate par hai — popup ki ginti ab rider app se milti hai.
            // Task 513: UNASSIGNED bills (rider NULL + status NULL) ride the 7-din
            // server window like the Deliveries board — today-filter unpar nahi lagta,
            // warna kal ka bina-rider bill popup se ghayab ho jata.
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
        // One-click final — reuses the EXACT promote path (quota gate, PRA
        // submit/offline fallback, whole-rupee rounding). Receipt print follows
        // the panel's own opt-in checkbox (default NO — delivery customer is
        // not at the counter); KOT release inside promote is never skipped.
        async finalizeDelivery(bill, method) {
            if (!bill || this.deliveryFinalBusyId || this.promoteSubmitting) return;
            this.deliveryFinalBusyId = bill.id;
            try {
                await this.promoteProvisional(bill, method, true, !this.deliveryPrintReceipt);
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
        // FINAL delivery bill ko panel se Delivered mark karna (3 Aug 2026) —
        // reuses POST /pos/deliveries/{id}/status (JSON). Promote yahan kabhi
        // nahi chalta: bill pehle se final hai, sirf delivery status badalta hai.
        async markFinalDelivered(bill) {
            if (!bill || !bill.is_final || this.deliveryFinalBusyId) return;
            this.deliveryFinalBusyId = bill.id;
            try {
                const res = await fetch('{{ url('/pos/deliveries') }}/' + bill.id + '/status', {
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
        // ─── Rider assign from the panel (Task 513) ─────────────────────────
        // UNASSIGNED delivery bill par dropdown se rider chuno — reuses POST
        // /pos/deliveries/{id}/assign (same backend as the Deliveries board;
        // koi naya path nahi). Success = list refresh (bill assigned-section
        // mein chala jata hai, khata rider_id follow karta hai).
        async assignRider(bill, riderId) {
            if (!bill || !riderId || this.riderAssignBusyId) return;
            this.riderAssignBusyId = bill.id;
            try {
                const res = await fetch('{{ url('/pos/deliveries') }}/' + bill.id + '/assign', {
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
        // ─── Rider WHOLE-khata settle from the panel (Task 123) ─────────────
        // Reuses POST /pos/riders/{id}/settle with settle_all — settles EVERY
        // unsettled cash bill on the rider's khata (all dates), not just this
        // bill. Riders never touch invoice_mode/serials; Final stays separate.
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
                const res = await fetch('{{ url('/pos/riders') }}/' + bill.rider_id + '/settle', {
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
        // ─── REPRINT (Alt+R) — today's bills, read-only, click = print ─────────
        // ── QUICK RETURN (Task 681) ───────────────────────────────────────
        // Bill/serial number (POS-2026-00012, L-012, bare digits, PRA fiscal
        // number, ya receipt order code) → server lookup → return form.
        // Permission + stream lock + returnable rules sab SERVER par
        // (PosReturnController::quickLookup) — yeh sirf navigate karta hai.
        openQuickReturn() {
            if (this.showPayModal || this.showReceipt || this.showHeldOrders || this.showQuickType || this.showManualItem || this.showCustomerPicker || this.showShortcuts || this.showManagerPinModal || this.showLocalBills || this.showFailedBills || this.showPendingDeliveries || this.showTablePicker || this.showReprint || this.boardMenuTable || this.boardConfirm || this.boardCancelAsk || this.boardShift || this.heldMenu || this.tableSwitchPrompt) return;
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
            fetch('{{ route('pos.return.lookup', [], false) }}?q=' + encodeURIComponent(q), {
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
        openReprint() {
            this.activeReprintIndex = 0;
            this.reprintSearch = '';
            // Self-heal: _printViaIframe's onAfterPrint is skipped when the print
            // session goes stale (cancelPendingPrints) — never let a stuck busy
            // id dead-lock the whole reprint feature for the session.
            this.reprintBusyId = null;
            this.showReprint = true;
            this.loadReprintBills();
            this.$nextTick(() => { const el = this.$refs.reprintSearchInput; if (el) el.focus(); });
        },
        async loadReprintBills() {
            this.reprintLoading = true;
            try {
                const res = await fetch('{{ url('/pos/api/todays-bills') }}', { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const data = await res.json();
                    if (data && data.success) {
                        this.reprintBills = data.bills || [];
                        if (this.activeReprintIndex >= this.reprintBills.length) {
                            this.activeReprintIndex = Math.max(0, this.reprintBills.length - 1);
                        }
                    }
                }
            } catch (e) { console.warn('loadReprintBills error', e); }
            this.reprintLoading = false;
        },
        filteredReprintBills() {
            const q = (this.reprintSearch || '').toLowerCase().trim();
            if (!q) return this.reprintBills;
            return this.reprintBills.filter(b =>
                (b.invoice_number || '').toLowerCase().includes(q)
                || (b.pra_invoice_number || '').toLowerCase().includes(q)
                || (b.customer_name || '').toLowerCase().includes(q)
                || String(b.total_amount || '').includes(q)
            );
        },
        // Print the ORIGINAL receipt of any of today's bills — no COPY label
        // (owner rule 23 Jul 2026). Mirrors printReceipt() for an arbitrary
        // transaction id: silent print via Desktop Agent first, hidden-iframe
        // fallback. `deduped` = the double-press guard says this bill is
        // ALREADY queued/printing — tell the cashier to wait, no 2nd copy.
        // Order-type label for reprint rows/preview (ZFC 30 Jul 2026):
        // Dine-in + table number when we have it.
        orderTypeLabel(bill) {
            if (!bill || !bill.order_type) return '';
            if (bill.order_type === 'dine_in') return 'Dine-in' + (bill.table_number ? ' • ' + bill.table_number : '');
            if (bill.order_type === 'takeaway') return window.TXT.takeaway;
            if (bill.order_type === 'delivery') return window.TXT.delivery;
            return String(bill.order_type).replace('_', ' ');
        },
        // Receipt VIEW url (no auto_print) — same route family reprintBill() prints.
        receiptViewUrl(bill) {
            if (!bill) return 'about:blank';
            return this.isRestaurantMode ? ('/pos/restaurant/receipt/' + bill.id) : ('/pos/transaction/' + bill.id + '/receipt');
        },
        openReprintPreview(bill) {
            if (!bill) return;
            this.reprintPreviewBill = bill;
        },
        reprintBill(bill) {
            if (!bill || this.reprintBusyId) return;
            this.reprintBusyId = bill.id;
            const url = (this.isRestaurantMode ? '/pos/restaurant/receipt/' : '/pos/transaction/') + bill.id + (this.isRestaurantMode ? '?auto_print=1' : '/receipt?auto_print=1');
            const done = () => { setTimeout(() => { this.reprintBusyId = null; }, 800); };
            const fallback = () => this._printViaIframe('print-receipt-frame', url, 'width=400,height=700', done);
            if (this.silentBillPrint) {
                this.trySilentPrint({ type: 'bill', transaction_id: bill.id }).then(ok => {
                    if (ok) {
                        if (ok.deduped) this.showToast(window.TXT.bill_already_printing, 'info');
                        else this.showToast(window.TXT.receipt_sent_prefix + (bill.pra_invoice_number || bill.invoice_number), 'success');
                        done();
                    } else { fallback(); }
                });
                return;
            }
            fallback();
        },
        async deleteProvisional(bill) {
            if (!bill) return;
            if (!confirm(window.TXT.delete_provisional_bill_q + (bill.invoice_number || '#' + bill.id) + '?\nThis cannot be undone.')) return;
            try {
                const res = await fetch('{{ url('/pos/api/provisional-bills') }}/' + bill.id + '/delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                });
                const data = await res.json();
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
        // Open the cash/card picker for a provisional before finalizing. Cash vs card
        // carry different PRA tax rates, so the method is chosen at promote time and
        // the bill is re-taxed + given a real POS serial server-side.
        askPromoteMethod(bill) {
            if (!bill) return;
            this.promoteTarget = bill;
            // Preselect the method the bill was saved with (card family → index 1).
            this.promoteMethodIndex = (bill.payment_method && bill.payment_method !== 'cash') ? 1 : 0;
            this.showPromoteMethod = true;
        },
        // noPrintOverride (Task 114): Pending Deliveries panel passes its own
        // receipt-print choice; null = honor the F10 promoteNoPrint toggle.
        async promoteProvisional(bill, method, sendToPra = true, noPrintOverride = null) {
            if (!bill) return;
            if (this.promoteSubmitting) return;
            this.promoteSubmitting = true;
            // Task 1036: promote = bill becomes FINAL — reserve the auto-open tab
            // inside this click/keydown gesture (list rows carry customer_phone).
            this.reserveWaWindow(bill.customer_phone);
            try {
                const res = await fetch('{{ url('/pos/api/provisional-bills') }}/' + bill.id + '/promote', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ payment_method: method || bill.payment_method || 'cash', send_to_pra: !!sendToPra }),
                });
                const data = await res.json();
                if (data && data.success) {
                    // Remove from list (no longer provisional) regardless of submitted vs queued.
                    this.localBills = this.localBills.filter(b => b.id !== bill.id);
                    if (this.activeLocalIndex >= this.filteredLocalBills().length) this.activeLocalIndex = Math.max(0, this.filteredLocalBills().length - 1);
                    if (this.localBills.length === 0) { this.activeLocalIndex = 0; }
                    this.showPromoteMethod = false;
                    this.promoteTarget = null;
                    this.showToast(data.message || ('Finalized' + (data.invoice_number ? ' — ' + data.invoice_number : '')), 'success');
                    // Finalized provisional = a completed sale → show the SAME persistent receipt
                    // popup as a normal sale finish. Auto-print honors the header toggle via
                    // runAutoPrintChain (no-op when Auto-Print is OFF).
                    this.showLocalBills = false;
                    this.lastInvoiceNumber = data.invoice_number || bill.invoice_number || '';
                    this.lastTransactionId = data.id || bill.id;
                    // Task 1368: set below with the KOT release — a delivery
                    // provisional CAN have a restaurant order behind it.
                    this.lastOrderId = null;
                    this.lastOrderType = bill.order_type || null; // Task 1025: promote popup bhi bill ki apni type par

                    this.lastTotal = Math.round(parseFloat(data.total_amount ?? bill.total_amount) || 0);
                    this.lastPaymentMethod = method || bill.payment_method || 'cash';
                    this.lastPraNumber = data.pra_number || '';
                    this.lastWaiterName = '';
                    // Task 655: 'submitted' (not 'completed') — the popup badge only
                    // understands submitted/pending/offline/failed; 'completed' fell
                    // through to the grey LOCAL BILL label on a real fiscal submit.
                    this.lastPraStatus = data.submitted ? 'submitted' : (data.queued ? 'pending' : '');
                    this.lastItemsCount = parseFloat(bill.items_count) || 0;
                    this.lastSaleAt = Date.now();
                    this.setWaBill(data); // Task 1036: promoted bill is final — WhatsApp Bill available
                    this.showReceipt = true;
                    this.scheduleReceiptAutoClose();
                    this.startPraPoll(); // Task 655: agent-queued promote → badge + receipt auto-flip
                    // "Payment pehle, KOT baad" (1 Aug 2026): held KOT ab release —
                    // promote = payment confirm, isi waqt kitchen ticket fire hoti hai.
                    // DIRECT fire (review catch): auto-print/auto-KOT master switches se
                    // AZAAD — warna un shops par KOT kabhi na nikalti jahan auto-print OFF hai.
                    // Dono print helpers silent-first hain, warna print popup fallback.
                    // v2: agar cashier ne F10 se pehle hi KOT bhej di thi (kot_pending=false),
                    // promote par dobara NA bheji jaye — kitchen ke paas ticket already hai.
                    // Task 1368: kot_pending ab LINE stamps se banta hai (server —
                    // KotPrintService::deliveryPromoteKot), order-level kot_sent_at se nahi
                    // jo hold par yun hi lag jata hai. Jis bill ka restaurant order hai us
                    // par sirf ANDEKHI lines ki DELTA parchi jati hai (poora reprint kabhi
                    // nahi); order-less bill purani tarah TRANSACTION se chhapti hai.
                    const kotLaneOpen = !!this.kitchenSettings.delivery_kot_after_payment
                        && bill.order_type === 'delivery' && bill.kot_pending !== false;
                    const promoKotOrderId = kotLaneOpen ? (bill.kot_order_id || null) : null;
                    const promoKotId = (kotLaneOpen && !promoKotOrderId) ? (data.id || bill.id) : null;
                    this.lastTxnKotId = promoKotId; // receipt popup ka K button bhi isi se chalega
                    this.lastOrderId = promoKotOrderId; // …ya us bill ke apne order se
                    // Task 1379: either id only exists when the ticket is still UNSENT,
                    // so the popup button is a first send — until the direct fire below
                    // actually sends it (KDS-handled shops skip that fire, so there the
                    // popup stays the first-send release even for a blocked cashier).
                    this.lastKotPending = !!(promoKotId || promoKotOrderId);
                    if ((promoKotId || promoKotOrderId) && !this.kdsHandlesKot()) {
                        if (promoKotOrderId) this.printKitchenTicket(promoKotOrderId, null, true);
                        else this.printTxnKitchenTicket(promoKotId);
                        this.lastKotPending = false;
                    }
                    // "No receipt print" (Aug 2026): skip the receipt auto-print chain when the
                    // cashier ticked the box — delivery customer isn't present, paper saved.
                    // KOT release above is NEVER skipped (kitchen must still cook).
                    const skipReceiptPrint = (noPrintOverride === null) ? this.promoteNoPrint : noPrintOverride;
                    if (!skipReceiptPrint) this.runAutoPrintChain(null, bill.order_type || null);
                } else {
                    // Failed — refresh list so cashier sees current state.
                    this.showToast((data && data.message) || window.TXT.submit_failed, 'error');
                    this.showPromoteMethod = false;
                    this.promoteTarget = null;
                    this.loadLocalBills();
                    // Promote failure usually means the bill is now final-but-offline/failed —
                    // refresh the F11 badge so the cashier sees it immediately.
                    this.loadFailedBills();
                }
            } catch (e) {
                console.error('promoteProvisional', e);
                this.showToast(window.TXT.network_error, 'error');
                this.showPromoteMethod = false;
                this.promoteTarget = null;
                this.loadLocalBills();
                this.loadFailedBills();
            } finally {
                this.promoteSubmitting = false;
            }
        },

        // ─── FAILED BILLS API helpers (F11 modal) ───────────────────────────
        async loadFailedBills() {
            this.failedBillsLoading = true;
            // Sequence guard: overlapping fetches (auto-sync tick + post-sale refresh
            // + manual modal refresh) must never let an OLDER response overwrite a
            // NEWER list — that's how the badge "jumped back" to a stale count.
            const seq = (this._failedBillsSeq = (this._failedBillsSeq || 0) + 1);
            try {
                const res = await fetch('{{ route('pos.api.failed-bills') }}', {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                });
                if (!res.ok) { this.failedBillsLoading = false; return; }
                const data = await res.json();
                if (data && data.success && seq === this._failedBillsSeq) {
                    // Carry over in-flight flags so a refresh mid-retry can't make
                    // the tick loop pick the same bill twice.
                    const prev = {};
                    (this.failedBills || []).forEach(b => { if (b._retrying || b._queued) prev[b.id] = b; });
                    this.failedBills = (data.bills || []).map(b => {
                        const p = prev[b.id];
                        if (p) { b._retrying = p._retrying; b._queued = p._queued; }
                        return b;
                    });
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
            if (!this.praEnabled) { this.showToast(window.TXT.pra_reporting_disabled, 'error'); return; }
            if (bill._retrying) return;
            bill._retrying = true;
            try {
                const res = await fetch('{{ url('/pos/api/failed-bills') }}/' + bill.id + '/retry', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                });
                const data = await res.json();
                if (data && data.success && data.queued) {
                    // Desktop-Agent mode: only RE-QUEUED, not synced — keep the bill
                    // in the list (badge stays honest) and mark it queued.
                    bill._retrying = false;
                    bill._queued = true;
                    this.showToast(data.message || window.TXT.submitted_to_pra, 'success');
                } else if (data && data.success) {
                    this.failedBills = this.failedBills.filter(b => b.id !== bill.id);
                    if (this.activeFailedIndex >= this.failedBills.length) this.activeFailedIndex = Math.max(0, this.failedBills.length - 1);
                    if (this.failedBills.length === 0) { this.showFailedBills = false; this.activeFailedIndex = 0; }
                    this.showToast(data.message || window.TXT.submitted_to_pra, 'success');
                    this.loadFailedBills();
                } else {
                    bill._retrying = false;
                    this.showToast((data && data.message) || window.TXT.retry_failed, 'error');
                    this.loadFailedBills();
                }
            } catch (e) { bill._retrying = false; console.error('retryFailed', e); this.showToast(window.TXT.network_error, 'error'); this.loadFailedBills(); }
        },
        async deleteFailed(bill) {
            if (!bill) return;
            if (!confirm(window.TXT.delete_failed_bill + (bill.invoice_number || '#' + bill.id) + '?\n\nThis will permanently remove it. Use only if the bill should NOT be sent to PRA.')) return;
            // Only 'pending' status can be safely deleted via provisional API after flipping.
            // For 'failed'/'offline' we use the regular delete route which expects form post.
            try {
                const fd = new FormData();
                fd.append('_token', '{{ csrf_token() }}');
                fd.append('_method', 'DELETE');
                const res = await fetch('{{ url('/pos/transaction') }}/' + bill.id, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd,
                });
                if (res.ok || res.status === 302) {
                    this.failedBills = this.failedBills.filter(b => b.id !== bill.id);
                    if (this.activeFailedIndex >= this.failedBills.length) this.activeFailedIndex = Math.max(0, this.failedBills.length - 1);
                    if (this.failedBills.length === 0) { this.showFailedBills = false; this.activeFailedIndex = 0; }
                    this.showToast(window.TXT.failed_bill_deleted, 'success');
                } else {
                    this.showToast(window.TXT.delete_failed_error + res.status + ')', 'error');
                }
            } catch (e) { console.error('deleteFailed', e); this.showToast(window.TXT.network_error, 'error'); }
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
                const res = await fetch(`/pos/restaurant/orders/${orderId}/delete`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' } });
                if (!res.ok) { this.showToast(window.TXT.failed_delete_order_error + res.status + ')', 'error'); return; }
                const data = await res.json();
                if (data.success) {
                    // Task 928: signal any open KDS tab on this device to refresh immediately.
                    try { localStorage.setItem('pos_kds_void_signal', Date.now()); } catch(e) {}
                    this.heldOrders = this.heldOrders.filter(o => o.id !== orderId);
                    if (this.activeHeldIndex >= this.heldOrders.length) this.activeHeldIndex = Math.max(0, this.heldOrders.length - 1);
                    // Auto-close the modal once the list is empty, otherwise the next
                    // Enter keystroke would land on a phantom selection.
                    if (this.heldOrders.length === 0) { this.showHeldOrders = false; this.activeHeldIndex = 0; }
                    this.showToast(window.TXT.order_deleted, 'success');
                    if (this.tableBoardEnabled) this.loadTableStatus(); // Table Board: table freed
                    // Task 899: refresh from server so any OTHER open tab also drops this order.
                    this.loadHeldOrders();
                    // Task 840: whole-order cancel void slip — kitchen must STOP all
                    // printed dishes. Same iframe/agent path as the per-dish void in holdOrder.
                    if (data.kot_void_queued) {
                        this.showToast(window.TXT.kot_void_sent || 'Void slip sent to kitchen', 'success');
                    } else if (data.kot_void_url) {
                        this._printViaIframe('print-kot-void-frame', data.kot_void_url + '&auto_print=1', 'width=380,height=620');
                    }
                } else { this.showToast(data.message || window.TXT.failed_word, 'error'); }
            } catch (e) { console.error('Delete held order error:', e); this.showToast(window.TXT.error_deleting_order, 'error'); }
        },

        // Task 994: shared pay-success handler — used by payHeldOrderDirect AND
        // the hold-time `already_paid` replay branch (retry after a lost pay
        // response). data.order_id (replay responses) overrides orderId so the
        // print chain KOTs the ORIGINAL order, never a cancelled retry-ghost.
        applyPaySuccess(data, orderId, method, savedTotal, payOrderType, skipReceipt, provisional, heldOrd) {
            orderId = (data && data.order_id) || orderId;
            this.heldOrders = this.heldOrders.filter(o => o.id !== orderId);
            this.lastInvoiceNumber = data.invoice_number || ''; this.lastTransactionId = data.transaction_id || null;
            this.lastOrderId = orderId || null;
            // Task 1025: paid bill ki order type ka snapshot (payOrderType pehle hi
            // held-order/override se capture ho chuka) — tables-first wapsi ka gate.
            this.lastOrderType = payOrderType || null;
            this.lastTotal = Math.round(savedTotal || data.total_amount || 0); this.lastPaymentMethod = method;
            this.lastPraNumber = data.pra_invoice_number || ''; this.lastPraStatus = data.pra_status || '';
            this.lastWaiterName = (this.incomingOrderInfo && this.incomingOrderInfo.waiter) ? this.incomingOrderInfo.waiter : ((heldOrd && heldOrd.waiter) ? heldOrd.waiter : '');
            // Task 921: clear the claimed-waiter state once its order is paid so the NEXT
            // non-waiter sale does not inherit a stale incomingOrderInfo.waiter.
            // (payHeldOrderDirect never calls clearCart(), so without this the global
            //  incomingOrderInfo stays set and bleeds the waiter name into the popup.)
            if (this.incomingOrderId && this.incomingOrderId === orderId) {
                this.incomingOrderId = null;
                this.incomingOrderInfo = null;
            }
            this.lastItemsCount = (this.cart || []).reduce((s, i) => s + (parseFloat(i.quantity) || 0), 0);
            this.lastSaleAt = Date.now();
            this.setWaBill(data); // Task 1036: WhatsApp Bill button/auto-open
            this.showReceipt = true;
            this.scheduleReceiptAutoClose();
            this.startPraPoll(); // Task 655: agent-mode 'pending' → badge + receipt auto-flip
            this.$nextTick(() => { setTimeout(() => this.triggerConfetti(), 300); });
            // Print order: INVOICE FIRST → KOT AFTER. Cashier-requested sequence.
            // Uses postMessage-chained engine — KOT never fires before the receipt
            // print dialog is dismissed (was a race in the old setTimeout(200/1800) impl
            // on slow networks where KOT iframe loaded before receipt iframe).
            // Replay edge (no order_id in the response): KOT from the transaction.
            // Task 1356: data.kot_pending = server-confirmed "kitchen ne ye lines
            // dekhi hi nahi" — dine-in finals par safety-net KOT isi se chalti hai.
            // Task 1379: same flag drives the popup's KOT button (first send vs reprint).
            this.lastKotPending = !!(data && data.kot_pending);
            this.runAutoPrintChain(orderId, payOrderType, orderId ? null : (data.transaction_id || null), skipReceipt, false, !!(data && data.kot_pending));
            // Refresh provisional badge count when this save was provisional.
            if (provisional) { this.loadLocalBills(); }
            // Refresh failed badge so cashier sees pending/failed state in real time.
            this.loadFailedBills();
            this.loadReprintBills(); // Akhri Bills strip stays current
            if (this.tableBoardEnabled) this.loadTableStatus(); // Table Board: paid table frees up
            return true;
        },

        // ── Task 1036: WhatsApp Bill (owner voice note 17 Aug 2026) ─────────
        // Success-response hook: stores the wa extras for the receipt popup
        // button and (auto-open mode) tries to open the chat right away.
        // Popup-blocked auto-open = pulse-highlight fallback on the button —
        // an empty wa.me can NEVER open (server sends nulls when unroutable).
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
                // No reserved tab (promote/settle paths without a known phone at
                // click time): best-effort open — popup-block par button pulse
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
            const msg = this.waBillMessage(this.lastPraNumber || this.lastInvoiceNumber, this.lastTotal, this.lastShareUrl);
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
        // Reprint list / preview: purane bill ka share token on-demand mint hota
        // hai. The blank tab opens SYNCHRONOUSLY (inside the click gesture) and
        // navigates after the fetch — window.open after an await would be
        // popup-blocked on Chrome.
        async waShareReprint(bill) {
            if (!bill || !bill.wa_phone || this.waReprintBusyId) return;
            this.waReprintBusyId = bill.id;
            const w = window.open('', '_blank');
            try {
                const res = await fetch('/pos/transaction/' + bill.id + '/share-link', { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' } });
                const data = await res.json();
                if (!data || !data.url) throw new Error('no share url');
                const msg = this.waBillMessage(bill.pra_invoice_number || bill.invoice_number, bill.total_amount, data.url);
                const link = 'https://wa.me/' + bill.wa_phone + '?text=' + encodeURIComponent(msg);
                if (w) { w.location = link; } else { window.open(link, '_blank'); }
            } catch (e) {
                console.error('waShareReprint', e);
                if (w) { try { w.close(); } catch (e2) {} }
                this.showToast(window.TXT.wa_link_failed, 'error');
            }
            this.waReprintBusyId = null;
        },

        async payHeldOrderDirect(orderId, method, savedTotal, provisional = false, orderTypeOverride = null, skipReceipt = false, payUuid = null) {
            // Order type captured NOW (owner, Jul 2026): held-modal pays read it from
            // the heldOrders entry (removed from the list on success below); billing
            // pass-through orders are never in heldOrders → falls back to the current
            // order-type widget. Drives the dine-in no-KOT-at-final rule in the chain.
            // Table Board pays pass an explicit orderTypeOverride (the tile's own
            // order_type) — foreign-terminal orders are NOT in this.heldOrders, and
            // falling back to the widget could mislabel a dine_in as takeaway and
            // wrongly re-trigger the auto-KOT chain.
            const heldOrd = this.heldOrders.find(o => o.id === orderId);
            const payOrderType = orderTypeOverride || (heldOrd && heldOrd.order_type) || this.orderType || null;
            // Task 994: idempotency key — billing pass-through passes the sale-level
            // uuid in; DIRECT held-order pays (held modal / table board) mint one
            // per order id and REUSE it on retry, so a lost-response retry replays
            // the original bill (with receipt data) instead of dead-ending on 409.
            const effPayUuid = payUuid || (this._payUuidByOrder[orderId] = this._payUuidByOrder[orderId] || this._newOfflineUuid());
            try {
                // PROVISIONAL BILL FLOW — when true, RestaurantPosController::payOrder
                // forces pra_status='local' and skips PRA submission. Bill remains
                // editable / deletable until promoted via "Submit to PRA — Make Final".
                const res = await this.fetchWithTimeout(`/pos/restaurant/orders/${orderId}/pay`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }, body: JSON.stringify({ payment_method: method, save_as_provisional: !!provisional, pay_uuid: effPayUuid, cash_received: (method === 'cash' && parseFloat(this.cashReceived) > 0) ? parseFloat(this.cashReceived) : null, delivery_address: payOrderType === 'delivery' ? (((heldOrd && (heldOrd.delivery_address || '').trim()) || (this.selectedDeliveryAddress || '').trim()) || null) : null, terminal_id: this.terminalId || null }) });
                if (!res.ok) {
                    const bodyText = await res.text().catch(() => '');
                    console.error('[payOrder] HTTP', res.status, res.statusText, bodyText.slice(0, 500));
                    // Backend sends the REAL reason (insufficient stock / already paid /
                    // quota) as JSON with a 4xx status — surface data.message instead of
                    // a useless "Pay HTTP 400" (Frost & Brew live issue, Jul 2026). The
                    // held order is untouched on failure, so the cashier can fix stock
                    // and Recall → Pay again.
                    let errData = null;
                    try { errData = JSON.parse(bodyText); } catch (_) {}
                    if (errData && errData.stock_error) { this.stockError = errData.message; this.showPayModal = true; }
                    // Task 216: quota-full 403 where the backend says a provisional retry
                    // would pass the flow rules (delivery-only on restaurant-ish shops) →
                    // offer a one-click provisional settle instead of a dead-end error.
                    if (res.status === 403 && errData && errData.quota_full && errData.provisional_allowed && !provisional) {
                        if (confirm(window.TXT.quota_provisional_prompt || errData.message)) {
                            return await this.payHeldOrderDirect(orderId, method, savedTotal, true, orderTypeOverride, skipReceipt, payUuid);
                        }
                    }
                    this.showToast((errData && errData.message) || ('Payment failed (HTTP ' + res.status + ') — F12 console'), 'error');
                    return false;
                }
                const data = await res.json();
                if (data.success) {
                    // Sale done — retire this order's retry key (next pay of any
                    // other order mints its own). Success body handled centrally.
                    delete this._payUuidByOrder[orderId];
                    return this.applyPaySuccess(data, orderId, method, savedTotal, payOrderType, skipReceipt, provisional, heldOrd);
                } else { if (data.stock_error) { this.stockError = data.message; this.showPayModal = true; } this.showToast(data.message || window.TXT.payment_failed, 'error'); if (res.status === 409 && this.tableBoardEnabled) this.loadTableStatus(); return false; }
            } catch (e) {
                console.error('[payHeldOrderDirect] FAIL', e);
                // Task 994: timeout gets its own message — retry is SAFE (same
                // uuid rides the retry, server replays instead of duplicating).
                if (this._isTimeoutError(e)) {
                    this.showToast(window.TXT.pay_timeout_retry, 'error');
                } else {
                    this.showToast(window.TXT.payment_error_prefix + (e?.message || e?.name || 'unknown') + ' — F12 console', 'error');
                }
                return false;
            }
        },

        // Receipt popup auto-close countdown (owner, 23 Jul 2026). Rules:
        // - 0 / missing setting on old tabs = persistent popup (old behavior).
        // - Hover pauses (receiptClosePaused), any click/keypress cancels outright.
        // - NEVER closes while the popup print chain is still running — closing fires
        //   cancelPendingPrints() via x-effect and would kill a queued KOT print.
        scheduleReceiptAutoClose() {
            this.cancelReceiptAutoClose();
            const secs = parseInt(this.receiptAutoCloseSecs, 10) || 0;
            if (secs <= 0) return; // 0 = never auto-close
            this.receiptCloseLeft = secs;
            this.receiptAutoCloseTimer = setInterval(() => {
                if (!this.showReceipt) { this.cancelReceiptAutoClose(); return; }
                if (this.receiptClosePaused) return; // mouse is on the popup — hold
                if (this.receiptCloseLeft > 1) { this.receiptCloseLeft--; return; }
                // Reached zero — if popup-print iframes/timers are still in flight,
                // wait 2 more seconds instead of cancelling their prints.
                if ((this.pendingPrintTimers && this.pendingPrintTimers.length) || (this.printMessageHandlers && this.printMessageHandlers.length)) {
                    this.receiptCloseLeft = 2;
                    return;
                }
                this.cancelReceiptAutoClose();
                // Task 779: Tables-first flow ON → auto-close bhi Tables screen par
                // wapas le jata hai (print chain yahan tak pehle hi drain ho chuki).
                if (this.returnToTablesAfterReceipt()) return;
                this.showReceipt = false;
            }, 1000);
        },

        // ── Task 655: AGENT-MODE PRA STATUS POLL ────────────────────────────
        // Agent-handled companies (Company::agentHandlesPra) save the bill as
        // pra_status='pending'; the Desktop Agent submits it from the shop PC
        // within seconds. The popup used to sit on "REPORTING TO PRA…" forever.
        // Poll the tiny status endpoint (~2.5s interval, bounded 30s) so the
        // badge flips to PRA VERIFIED + fiscal number and the receipt iframe
        // reloads with the fiscal box + QR. Popup band → stopPraPoll (x-effect).
        praPollTimer: null,
        startPraPoll() {
            this.stopPraPoll();
            if (this.lastPraStatus !== 'pending' || !this.lastTransactionId || this.lastIsOffline) return;
            const txnId = this.lastTransactionId;
            const deadline = Date.now() + 30000;
            let inflight = false;
            this.praPollTimer = setInterval(async () => {
                if (this.lastTransactionId !== txnId || Date.now() > deadline) { this.stopPraPoll(); return; }
                if (inflight) return; // slow response must not stack requests
                inflight = true;
                const st = await this._fetchPraStatus(txnId);
                inflight = false;
                if (!st || this.lastTransactionId !== txnId) return;
                if (st.pra_status && st.pra_status !== 'pending') {
                    this._applyPraStatus(txnId, st);
                    // submitted = terminal. failed/offline: badge update ho chuka,
                    // lekin agent retry kar sakta hai — deadline tak poll jaari.
                    if (st.pra_status === 'submitted') this.stopPraPoll();
                }
            }, 2500);
        },
        stopPraPoll() {
            if (this.praPollTimer) { clearInterval(this.praPollTimer); this.praPollTimer = null; }
        },
        async _fetchPraStatus(txnId) {
            try {
                const res = await fetch('/pos/transaction/' + txnId + '/pra-status', { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return null;
                return await res.json();
            } catch (e) { return null; } // network blip — next tick retries
        },
        // Apply a status flip to the popup's shared state: badge + PRA number +
        // fresh receipt iframe; failed/offline flips refresh the F11 badge too.
        _applyPraStatus(txnId, st) {
            if (this.lastTransactionId !== txnId) return; // a new sale took over
            const prev = this.lastPraStatus;
            this.lastPraStatus = st.pra_status || '';
            if (st.pra_invoice_number) this.lastPraNumber = st.pra_invoice_number;
            if (prev !== this.lastPraStatus) {
                this.refreshReceiptIframe();
                if (this.lastPraStatus === 'failed' || this.lastPraStatus === 'offline') this.loadFailedBills();
            }
        },
        // Receipt iframe reload (cache-bust) — pending→submitted flip ke baad
        // popup ke andar receipt PRA fiscal box + QR ke saath taaza dikhe.
        refreshReceiptIframe() {
            if (!this.showReceipt || this.lastIsOffline || !this.lastTransactionId) return;
            try {
                const el = this.$refs.receiptIframe;
                if (!el) return;
                el.src = (this.isRestaurantMode ? '/pos/restaurant/receipt/' : '/pos/transaction/') + this.lastTransactionId + (this.isRestaurantMode ? '' : '/receipt') + '?_pra=' + Date.now();
            } catch (e) { /* best-effort — popup badge is already correct */ }
        },
        // Bounded pehla-print grace (max ~4.8s): bill abhi 'pending' ho to print
        // se pehle submit ka mauqa do. Status flip milte hi state update ho kar
        // foran wapas; warna timeout par pending slip hi chal padti hai —
        // counter ki raftar kabhi block nahi hoti. Errors silent.
        async praPrintGrace() {
            if (this.lastPraStatus !== 'pending' || !this.lastTransactionId || this.lastIsOffline) return;
            const txnId = this.lastTransactionId;
            for (let i = 0; i < 4; i++) {
                await new Promise(r => setTimeout(r, 1200));
                if (this.lastTransactionId !== txnId) return; // new sale took over
                if (this.lastPraStatus !== 'pending') return; // badge poll flipped it already
                const st = await this._fetchPraStatus(txnId);
                if (st && st.pra_status && st.pra_status !== 'pending') {
                    this._applyPraStatus(txnId, st);
                    return;
                }
            }
        },

        cancelReceiptAutoClose() {
            if (this.receiptAutoCloseTimer) { clearInterval(this.receiptAutoCloseTimer); this.receiptAutoCloseTimer = null; }
            this.receiptCloseLeft = 0;
            this.receiptClosePaused = false;
        },

        recallOrder(order) {
            if (this.cart.length > 0 && !confirm(window.TXT.replace_cart_with_recalled)) return;
            this.cart = order.items.map(i => ({ cart_uid: 'c' + Date.now() + '_' + Math.random().toString(36).slice(2,9), item_id: i.item_id, item_type: i.item_type, item_name: i.item_name, quantity: parseFloat(i.quantity), unit_price: parseFloat(i.unit_price), special_notes: i.special_notes || '', is_tax_exempt: i.is_tax_exempt || false, item_discount_type: i.item_discount_type || 'percentage', item_discount_value: parseFloat(i.item_discount_value) || 0, showItemDiscount: parseFloat(i.item_discount_value) > 0 }));
            this.kitchenNotes = order.kitchen_notes || '';
            this.recalledOrderId = order.id;
            this.priorityOrder = order.priority || false;
            if (order.discount_type && parseFloat(order.discount_value) > 0) { this.discountType = order.discount_type; this.discountValue = parseFloat(order.discount_value) || 0; this.showDiscount = true; } else { this.discountType = 'percentage'; this.discountValue = 0; this.discountAmount = 0; this.showDiscount = false; }
            if (order.table) { this.selectedTable = { id: order.table.id, table_number: order.table.table_number }; this.orderType = 'dine_in'; }
            // Task 183 (FBR Task 170 parity): restore the held order's type too —
            // a held DELIVERY order must not recall as the default 'takeaway'.
            else if (order.order_type) { this.orderType = order.order_type; }
            this.selectedCustomer = order.customer_id ? { id: order.customer_id, name: order.customer_name || window.TXT.customer_word, phone: order.customer_phone || '' } : null;
            this.customerPhoneQuery = this.selectedCustomer ? (this.selectedCustomer.phone || this.selectedCustomer.name) : '';
            // Task 183: restore the delivery-address snapshot. Same pin-then-merge
            // pattern as enterEditMode: the snapshot shows instantly; the saved
            // address book merges in behind it (loadCustomerAddresses resets the
            // list + auto-selects the default, so re-pin after it finishes).
            if (this.orderType === 'delivery') {
                const snap = (order.delivery_address || '').trim();
                if (snap) {
                    this.customerAddresses = [{ id: null, label: null, address: snap }];
                    this.selectedDeliveryAddress = snap;
                }
                if (order.customer_id) {
                    this.loadCustomerAddresses().then(() => {
                        if (snap) {
                            if (!this.customerAddresses.some(a => (a.address || '') === snap)) {
                                this.customerAddresses.unshift({ id: null, label: null, address: snap });
                            }
                            this.selectedDeliveryAddress = snap;
                        }
                    });
                }
            }
            // Task 781: meta snapshot for the in-panel table actions — the order
            // leaves heldOrders on the next line, so KOT gating + the cancel
            // modal would otherwise lose kot_sent_at / order_number.
            this.recalledOrderMeta = { order_number: order.order_number || null, kot_sent_at: order.kot_sent_at || null, source: order.source || null };
            // Task 1028: recall ke foran baad ka fingerprint — table-switch par
            // isi se compare ho kar dirty (edited) carts ko explicit choice milti hai.
            this._recallCartBaseline = this.cartEditFingerprint();
            this.heldOrders = this.heldOrders.filter(o => o.id !== order.id); this.showHeldOrders = false; this.showToast(window.TXT.order_recalled_for_editing, 'success');
        },

        async addQuickCustomer() {
            // Name is OPTIONAL (owner request, Jul 2026) — phone is the identifier.
            if (!this.quickCustomerPhone.trim()) {
                this.showToast(window.TXT.phone_required, 'error'); return;
            }
            try {
                const res = await fetch('{{ route("pos.restaurant.customer-store") }}', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ name: this.quickCustomerName.trim() || null, phone: this.quickCustomerPhone.trim(), address: this.quickCustomerAddress.trim() || null }),
                });
                const data = await res.json();
                if (data.customer || data.success) {
                    const cust = data.customer || { id: Date.now(), name: this.quickCustomerName.trim() || this.quickCustomerPhone.trim(), phone: this.quickCustomerPhone.trim(), address: this.quickCustomerAddress.trim() };
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
        get maxAmountDiscount() {
            // Rs cap for amount-type discounts = limit% of the subtotal (owner rule, Jul 2026:
            // discount capped at the SAME percentage limit on BOTH types). pos_admin has
            // limit=100 → cap equals the full subtotal (unchanged behavior for admins).
            return Math.min(this.effectiveSubtotal, Math.round(this.effectiveSubtotal * this.effectiveDiscountLimit) / 100);
        },
        checkDiscountLimit(val, type) {
            // BOTH discount types respect the role-based cap (owner rule, Jul 2026):
            // percentage capped at limit%, amount capped at limit% of the subtotal.
            // Use 2-dp integer comparison to dodge JS float precision (0.1 + 0.2 = 0.30000…04).
            const valCents = Math.round((Number(val) || 0) * 100);
            if (type === 'percentage' && valCents > Math.round(this.effectiveDiscountLimit * 100)) return false;
            if (type === 'amount' && this.effectiveSubtotal > 0 && valCents > Math.round(this.maxAmountDiscount * 100)) return false;
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
                const res = await fetch(`/pos/restaurant/api/customer-history/${customerId}`);
                if (res.ok) { this.customerHistory = await res.json(); this.showCustomerHistory = true; }
            } catch (e) {}
            this.loadingCustomerHistory = false;
        },
        reorderItems(order) {
            for (const item of order.items) {
                const existing = this.cart.find(c => c.item_id === item.item_id && c.item_type === item.item_type);
                if (existing) { existing.quantity += item.qty; } else {
                    this.cart.push({ cart_uid: 'c' + Date.now() + '_' + Math.random().toString(36).slice(2,9), item_id: item.item_id, item_type: item.item_type, item_name: item.name, quantity: item.qty, unit_price: item.price, special_notes: '', is_tax_exempt: item.is_tax_exempt || false, is_third_schedule: item.is_third_schedule || false, item_discount_type: 'percentage', item_discount_value: 0, showItemDiscount: false });
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
        // ─── Caller ID popup (Task 1039) ───────────────────────────────────
        // Non-blocking: silent while payment / receipt / manager-PIN / table-
        // switch prompts are up — deferred rings surface right after (queue).
        callerBlocked() {
            return this.showPayModal || this.showReceipt || this.showManagerPinModal
                || !!this.tableSwitchPrompt || this.submitting;
        },
        async pollCallerEvents() {
            if (this._callerBusy) { return; }
            this._callerBusy = true;
            try {
                const res = await fetch('/pos/api/caller-events?after=' + (this.callerLastId || 0), { headers: { 'Accept': 'application/json' } });
                if (res.ok) {
                    const data = await res.json();
                    if (data && data.enabled) {
                        if (Array.isArray(data.events) && data.events.length) {
                            this.callerQueue.push(...data.events);
                            // v2: unseen badge — new rings count until the log is opened.
                            data.events.forEach(ev => { if ((parseInt(ev.id, 10) || 0) > this.callerSeenId) this.callerUnseen++; });
                        }
                        const lid = parseInt(data.last_id, 10) || 0; // live-pdo-string-ints
                        if (lid > this.callerLastId) {
                            this.callerLastId = lid;
                            try { localStorage.setItem('tn_caller_last_id', String(lid)); } catch (e) {}
                        }
                        // v2: one-time offline warning — feature ON but no paired
                        // phone has contacted the server recently.
                        if (data.online === false && !this._callerWarnedOffline) {
                            this._callerWarnedOffline = true;
                            this.showToast(window.TXT.caller_phone_offline_warn, 'error');
                        }
                    }
                }
            } catch (e) {}
            this._callerBusy = false;
            this.maybeShowCallerPopup();
        },
        maybeShowCallerPopup() {
            if (this.callerPopup || this.callerQueue.length === 0 || this.callerBlocked()) { return; }
            this.callerPopup = this.callerQueue.shift();
            // v2: soft beep, ONCE per event id (KDS-beep guard pattern —
            // re-polls / requeues must never re-fire the same ring's beep).
            const bid = this.callerPopup && this.callerPopup.id;
            if (bid && !this._callerBeeped.includes(bid)) {
                this._callerBeeped.push(bid);
                if (this._callerBeeped.length > 50) this._callerBeeped.splice(0, 25);
                this.playCallerBeep();
            }
            clearTimeout(this._callerHideTimer);
            this._callerHideTimer = setTimeout(() => { this.callerPopup = null; this.maybeShowCallerPopup(); }, 45000);
        },
        // Single soft high tone (~0.25s) — gentler than the two-tone order chime.
        playCallerBeep() {
            try {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                if (!this._chimeCtx) this._chimeCtx = new Ctx();
                const ctx = this._chimeCtx;
                if (ctx.state === 'suspended') ctx.resume().catch(() => {});
                const t0 = ctx.currentTime;
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine'; osc.frequency.value = 1046.5;
                gain.gain.setValueAtTime(0.0001, t0);
                gain.gain.exponentialRampToValueAtTime(0.18, t0 + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.25);
                osc.connect(gain); gain.connect(ctx.destination);
                osc.start(t0); osc.stop(t0 + 0.27);
            } catch (e) { /* silent — sound is best-effort */ }
        },
        dismissCallerPopup() {
            clearTimeout(this._callerHideTimer);
            this.callerPopup = null;
            this.maybeShowCallerPopup();
        },
        callerStartBill() {
            const ev = this.callerPopup;
            if (!ev) { return; }
            this.callerBillFrom(ev);
            this.dismissCallerPopup();
        },
        // v2 (Task 1101): shared by the popup AND the recent-calls panel rows.
        callerBillFrom(ev) {
            if (!ev) { return; }
            const m = ev.match;
            const name = (m && m.name) || ev.name || '';
            const phone = (m && m.phone) || ev.phone || '';
            if (m && m.customer_id) {
                this.selectCustomerWithStats({ id: m.customer_id, name: name, phone: phone, address: m.address || '' });
                // v2: delivery jump — caller = delivery order 9 times out of 10.
                // Only when this shop actually HAS the Delivery type; setOrderType
                // itself pulls the customer's saved addresses for the prefill.
                if (this.guidedOrderTypes().includes('delivery') && this.orderType !== 'delivery') {
                    this.setOrderType('delivery');
                }
            } else {
                // No saved customer — attach name/phone to the bill as-is (walk-in
                // with phone); cashier can save the customer from the picker later.
                this.selectedCustomer = { id: null, name: name || phone, phone: phone };
                this.customerPhoneQuery = (name ? name : '') + (name && phone ? ' · ' : '') + (phone || '');
                this.showToast(window.TXT.customer_prefix + (name || phone), 'success');
            }
        },
        // ── Task 1381: POS se hi call back ──────────────────────────────────
        // Number nikalne ka ek hi rasta (popup, recent-calls row aur attached
        // customer card teenon isi ko poochte hain).
        callerNumberOf(ev) {
            if (!ev) { return ''; }
            const m = ev.match;
            return String((m && m.phone) || ev.phone || '').trim();
        },
        /**
         * Counter ke paired phone ko tap-to-dial request bhejta hai. Auto-dial
         * kabhi nahi — phone par ek tap hamesha lagta hai (out of scope by design).
         *
         * opts.attach     → wohi customer bill par attach (Bill button jaisa)
         * opts.closePopup → incoming-call popup band kar do
         *
         * Koi phone na mile (ya app purani ho) to dead end nahi: number bara
         * kar ke copy button ke saath dikha dete hain.
         */
        callerDialBack(ev, opts) {
            const o = opts || {};
            const phone = this.callerNumberOf(ev);
            if (!phone) { this.showToast(window.TXT.caller_dial_no_number, 'error'); return; }
            if (this.callerDialBusy) { return; }
            this.callerDialBusy = true;

            const evId = parseInt((ev && ev.id) || 0, 10) || 0;
            const name = (ev && ((ev.match && ev.match.name) || ev.name)) || '';
            // Attach pehle: server jawab ka intezar kiye baghair order likhna
            // shuru ho sake (Bill button jaisa foran).
            if (o.attach) { this.callerBillFrom(ev); }
            if (o.closePopup) { this.dismissCallerPopup(); }

            fetch('{{ route('pos.api.caller-dial', [], false) }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify({ phone: phone, event_id: evId || null, name: name })
            })
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.ok) { this.showToast(window.TXT.caller_dial_failed, 'error'); return; }
                    // "Call back kiya" ka nishan foran list par (server ne bhi stamp kiya).
                    if (evId) {
                        const row = this.callerLog.find(c => (parseInt(c.id, 10) || 0) === evId);
                        if (row) { row.called_back = true; row.called_back_at = data.called_back_at || ''; }
                    }
                    if (data.sent) {
                        this.callerDialFallback = null;
                        this.showToast(window.TXT.caller_dial_sent, 'success');
                    } else {
                        // Log panel band: fallback card usi jagah (top-right) khulta hai.
                        this.showCallerLog = false;
                        this.callerDialFallback = {
                            phone: data.phone || phone,
                            dial: data.dial || phone,
                            name: name,
                            // Sirf wohi reasons jin ke liye text mojood hai —
                            // warna card khali wajah ke saath khul jata.
                            reason: ['old_app', 'notif_off'].includes(data.reason) ? data.reason : 'no_device'
                        };
                    }
                })
                .catch(() => { this.showToast(window.TXT.caller_dial_failed, 'error'); })
                .finally(() => { this.callerDialBusy = false; });
        },
        // Fallback card ka copy button — clipboard API https/localhost ke bahar
        // nahi chalti, is liye purana execCommand rasta bhi rakha hai.
        copyCallerNumber() {
            const num = this.callerDialFallback ? (this.callerDialFallback.dial || this.callerDialFallback.phone || '') : '';
            if (!num) { return; }
            const done = () => this.showToast(window.TXT.caller_dial_copied, 'success');
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(num).then(done).catch(() => this.copyCallerNumberLegacy(num, done));
                    return;
                }
            } catch (e) { /* fall through */ }
            this.copyCallerNumberLegacy(num, done);
        },
        copyCallerNumberLegacy(num, done) {
            try {
                const ta = document.createElement('textarea');
                ta.value = num;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                done();
            } catch (e) { this.showToast(window.TXT.caller_dial_failed, 'error'); }
        },
        // v2: unknown caller → open the existing quick-add customer panel with
        // the phone (and WhatsApp display name) prefilled so the address gets
        // saved right there.
        callerSaveCustomer(ev) {
            if (!ev || !ev.phone) { return; }
            const digits = String(ev.phone).replace(/[^0-9+]/g, '');
            this.newCustomerPhone = digits;
            this.newCustomerName = ev.name || '';
            this.newCustomerAddress = '';
            this.showNewCustomerInline = true;
            this.customerPhoneDropdown = true;
            this.dismissCallerPopup();
            this.$nextTick(() => { const el = this.$refs.newCustomerNameInput; if (el) el.focus(); });
        },
        // v2: repeat last order — fetch the caller's last bill lines, re-add at
        // CURRENT prices via addToCart (baked catalog lookup). Deleted/inactive
        // products (and deal/manual lines the server marks skipped) are skipped
        // with a toast. Quantities stay editable in the cart as usual.
        callerRepeatOrder(ev) {
            const m = ev && ev.match;
            if (!m || !m.customer_id) { return; }
            fetch('{{ route('pos.api.caller-last-order', [], false) }}?customer_id=' + m.customer_id, { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.ok) { this.showToast(window.TXT.caller_repeat_none, 'error'); return; }
                    const skipped = Array.isArray(data.skipped) ? [...data.skipped] : [];
                    let added = 0;
                    (data.items || []).forEach(li => {
                        const pool = li.item_type === 'service' ? this.allServices : this.allProducts;
                        const it = pool.find(p => p.id === (parseInt(li.item_id, 10) || 0) && parseFloat(p.price) > 0);
                        if (!it) { skipped.push(li.name); return; }
                        if (this.isInventoryEnabled() && it.stockStatus === 'out' && this.blockOutOfStock) { skipped.push(li.name); return; }
                        // MERGE, never overwrite: the cashier may already have this
                        // item in the ACTIVE cart (qty 5 + repeat qty 2 = 7, not 2).
                        // addToCart() increments an existing row by 1; correct that
                        // +1 to the historical line qty by ADDING (qty - 1) more.
                        const qty = Math.max(1, parseFloat(li.quantity) || 1);
                        this.addToCart(it);
                        const row = this.cart.find(c => c.item_id === it.id && c.item_type === it.type);
                        if (row) { row.quantity = row.quantity + qty - 1; }
                        added++;
                    });
                    if (added === 0 && skipped.length === 0) { this.showToast(window.TXT.caller_repeat_none, 'info'); }
                    else if (skipped.length) { this.showToast(window.TXT.caller_repeat_skipped + skipped.join(', '), 'info'); }
                    else { this.showToast(window.TXT.caller_repeat_done, 'success'); }
                    this.callerBillFrom(ev);
                    this.dismissCallerPopup();
                    this.showCallerLog = false;
                })
                .catch(() => this.showToast(window.TXT.network_error, 'error'));
        },
        // v2: recent-calls panel — opening it clears the unseen badge (cursor).
        openCallerLog() {
            this.showCallerLog = true;
            this.loadCallerLog();
        },
        loadCallerLog() {
            this.callerLogLoading = true;
            this.callerLog = [];
            fetch('{{ route('pos.api.caller-recent', [], false) }}', { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(data => {
                    this.callerLog = (data && Array.isArray(data.calls)) ? data.calls : [];
                    const maxId = this.callerLog.reduce((mx, c) => Math.max(mx, parseInt(c.id, 10) || 0), this.callerSeenId);
                    this.callerSeenId = Math.max(maxId, this.callerLastId);
                    this.callerUnseen = 0;
                    try { localStorage.setItem('tn_caller_seen_id', String(this.callerSeenId)); } catch (e) {}
                })
                .catch(() => {})
                .finally(() => { this.callerLogLoading = false; });
        },
        // ── Task 1380: handled call ko list se hatana ─────────────────────────
        // Row foran ghayab (optimistic) + server par cleared_at stamp, taake
        // refresh par aur usi shop ke doosre counter par bhi na dikhe. Rings ka
        // poll/cursor waise ka waisa — nai call pehle ki tarah popup + list mein.
        clearCallerEvent(ev) {
            const id = ev && (parseInt(ev.id, 10) || 0);
            if (!id) { return; }
            this.callerLog = this.callerLog.filter(c => (parseInt(c.id, 10) || 0) !== id);
            // Wohi ring agar abhi popup/queue mein hai to wahan se bhi jaye.
            this.callerQueue = this.callerQueue.filter(c => (parseInt(c.id, 10) || 0) !== id);
            if (this.callerPopup && (parseInt(this.callerPopup.id, 10) || 0) === id) { this.dismissCallerPopup(); }
            this.callerUnseen = this.callerLog.filter(c => (parseInt(c.id, 10) || 0) > this.callerSeenId).length;
            this.postCallerClear({ id: id });
        },
        clearAllCallerEvents() {
            if (this.callerLog.length === 0) { return; }
            if (!confirm(window.TXT.caller_clear_all_q)) { return; }
            this.callerLog = [];
            this.callerQueue = [];
            if (this.callerPopup) { this.dismissCallerPopup(); }
            this.callerUnseen = 0;
            this.postCallerClear({ all: true }, true);
        },
        // Server hi sach hai: clear fail ho to list dobara load kar ke asli
        // haalat dikhao (warna cashier ko lagta hai call hat gayi).
        postCallerClear(body, toastOnSuccess) {
            fetch('{{ route('pos.api.caller-clear', [], false) }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify(body),
            })
                .then(r => r.json())
                .then(data => {
                    if (data && data.ok) {
                        if (toastOnSuccess) { this.showToast(window.TXT.caller_cleared, 'success'); }
                        return;
                    }
                    this.showToast(window.TXT.caller_clear_failed, 'error');
                    this.loadCallerLog();
                })
                .catch(() => {
                    this.showToast(window.TXT.caller_clear_failed, 'error');
                    this.loadCallerLog();
                });
        },
        // v2: boot-time unseen count (badge survives reloads without waiting
        // for fresh rings) — one cheap fetch, then the poll increments.
        refreshCallerUnseen() {
            fetch('{{ route('pos.api.caller-recent', [], false) }}', { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(data => {
                    if (!data || !Array.isArray(data.calls)) return;
                    this.callerUnseen = data.calls.filter(c => (parseInt(c.id, 10) || 0) > this.callerSeenId).length;
                })
                .catch(() => {});
        },
        showToast(msg, type) { this.toast = { show: true, message: msg, type }; setTimeout(() => this.toast.show = false, 2500); },
        triggerConfetti() {
            const container = document.getElementById('confettiContainer');
            if (!container) return;
            const colors = ['#22c55e', '#7c3aed', '#f59e0b', '#3b82f6', '#ef4444', '#ec4899', '#14b8a6'];
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
{{-- ── Delivery Board modal (Task 431, 10 Aug 2026) ─────────────────────────
     Full /pos/deliveries board in a LAZY iframe overlay — iframe src is set on
     first open only (zero sale-screen boot cost; pos-boot-splash-perf.md).
     Vanilla JS + inline styles on purpose: outside the restaurantPos() Alpine
     state, and no arbitrary Tailwind classes (vite-arbitrary-classes.md).
     The board page detects window.self !== window.top and hides its own top
     nav + back button (see pos/deliveries.blade.php) — so tab/filter/POST
     navigation INSIDE the iframe keeps the embedded look. All gating stays
     server-side on the route (PosAuth + plan/feature gates + stream scope). --}}
<div id="tn-delivery-board" style="display:none; position:fixed; inset:0; z-index:95;">
    <div onclick="tnCloseDeliveryBoard()" style="position:absolute; inset:0; background:rgba(15,23,42,.55); backdrop-filter:blur(4px);"></div>
    <div style="position:absolute; inset:16px; display:flex; flex-direction:column; background:#f9fafb; border-radius:16px; overflow:hidden; box-shadow:0 24px 64px rgba(0,0,0,.35);" class="dark:bg-gray-900">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:10px 16px; background:#064e3b; color:#fff; flex-shrink:0;">
            <div style="display:flex; align-items:center; gap:8px; min-width:0;">
                <svg style="width:18px;height:18px;flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
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
        frame.setAttribute('src', '{{ route('pos.deliveries', [], false) }}');
    }
    wrap.style.display = 'block';
}
function tnCloseDeliveryBoard() {
    var wrap = document.getElementById('tn-delivery-board');
    if (wrap) wrap.style.display = 'none';
    // Reload the board next open so rider/status changes are always fresh.
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
</x-pos-layout>
