<x-pos-layout>
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
.cat-pill.active { background: linear-gradient(135deg, #7c3aed, #a855f7); color: white; box-shadow: 0 4px 15px rgba(124,58,237,0.35); transform: scale(1.05); }
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
.search-glow:focus { box-shadow: 0 0 0 3px rgba(124,58,237,0.15), 0 0 20px rgba(124,58,237,0.1) !important; border-color: #7c3aed !important; }
.dark .search-glow:focus { box-shadow: 0 0 0 3px rgba(167,139,250,0.2), 0 0 20px rgba(167,139,250,0.08) !important; }
@keyframes heldBadgePulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.25); } }
.held-badge-pulse { animation: heldBadgePulse 1.5s ease-in-out infinite; }
.cat-pill.active::after { content: ''; position: absolute; bottom: 0; left: 15%; right: 15%; height: 3px; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.8), transparent); border-radius: 2px; }
.total-animate { transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
.confetti-piece { position: absolute; width: 8px; height: 8px; border-radius: 2px; animation: confettiFall 1.5s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards; pointer-events: none; }
.receipt-modal-enter { animation: receiptSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
.success-icon-animate { animation: successPulse 1.5s ease-out 0.3s; }

/* ============================================================
   Phase 6 — PREMIUM POLISH LAYER (v13)
   Pure additive CSS. No HTML/JS structural changes.
   Design tokens, refined hover states, tighter rhythm,
   better numerics, consistent button feel, calmer chrome.
   ============================================================ */

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
    box-shadow: 0 10px 24px -10px rgba(5,150,105,.55), inset 0 1px 0 rgba(255,255,255,.18);
    letter-spacing: .01em;
}
.pay-btn-premium:hover:not(:disabled) { box-shadow: 0 16px 32px -12px rgba(5,150,105,.65), inset 0 1px 0 rgba(255,255,255,.22); transform: translateY(-1px); }

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

<div x-data="restaurantPos()" @wheel="handleGlobalWheel($event)" class="flex flex-col h-[calc(100vh-48px)] overflow-hidden bg-gray-50 dark:bg-gray-950">
    <div class="bg-gradient-to-r from-purple-600 to-indigo-600 text-white text-[10px] font-bold tracking-wider uppercase px-3 py-1 text-center shadow-sm">
        ⚡ UNIVERSAL POS · Category: {{ $company->business_category ?? 'default' }} · BUILD {{ now()->format('H:i:s') }} · v18-PROFIT-BI
    </div>

    {{-- PRA Reporting + Auto-Print toggles strip (visible to admin + cashier).
         autoPrintEnabled lives on the parent restaurantPos() scope (mirrors kitchenSettings.print_on_pay)
         so toggling immediately updates the receipt-iframe URL on the very next sale, no refresh needed. --}}
    <div class="flex items-center justify-end gap-4 px-3 py-1.5 bg-gradient-to-r from-purple-50 to-white dark:from-purple-900/10 dark:to-gray-900 border-b border-purple-100 dark:border-purple-900/30 flex-shrink-0"
         x-data="{
            autoPrintLoading: false,
            autoKotLoading: false
         }">

        {{-- PRA Reporting --}}
        <div class="flex items-center gap-2">
            <span class="text-[10px] uppercase tracking-wider font-extrabold text-purple-700 dark:text-purple-300">PRA Reporting</span>
            <button type="button"
                @click="praLoading = true; fetch('{{ route('pos.api.toggle-pra') }}', { method:'POST', headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}', 'Content-Type':'application/json', 'Accept':'application/json' } }).then(r => r.json()).then(d => { praEnabled = !!d.enabled; praLoading = false; window.tnNotify && window.tnNotify('PRA Reporting', praEnabled ? 'Enabled' : 'Disabled'); }).catch(() => { praLoading = false; alert('Toggle failed'); })"
                :disabled="praLoading"
                :class="praEnabled ? 'bg-purple-600' : 'bg-gray-400 dark:bg-gray-600'"
                class="relative inline-flex h-5 w-10 flex-shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out shadow-inner">
                <span :class="praEnabled ? 'translate-x-5' : 'translate-x-0.5'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5"></span>
            </button>
            <span x-text="praEnabled ? 'ON' : 'OFF'" :class="praEnabled ? 'text-purple-700 dark:text-purple-300' : 'text-gray-500 dark:text-gray-400'" class="text-[10px] font-black w-7"></span>
            <span x-show="praLoading" class="text-[10px] text-purple-500 animate-pulse">…</span>
        </div>

        <div class="w-px h-4 bg-purple-200 dark:bg-purple-800/40"></div>

        {{-- Auto-Print on Sale (Phase 4) — bound to parent restaurantPos() scope --}}
        <div class="flex items-center gap-2" title="When ON, the receipt print dialog opens automatically right after a successful payment.">
            <span class="text-[10px] uppercase tracking-wider font-extrabold text-emerald-700 dark:text-emerald-300">🖨️ Auto-Print</span>
            <button type="button"
                @click="autoPrintLoading = true; fetch('{{ route('pos.api.toggle-auto-print') }}', { method:'POST', headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}', 'Content-Type':'application/json', 'Accept':'application/json' } }).then(r => r.json()).then(d => { autoPrintEnabled = !!d.enabled; kitchenSettings.print_on_pay = autoPrintEnabled; autoPrintLoading = false; window.tnNotify && window.tnNotify('Auto-Print Receipt', autoPrintEnabled ? 'Enabled' : 'Disabled'); }).catch(() => { autoPrintLoading = false; alert('Toggle failed'); })"
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
        <div class="flex items-center gap-2" title="When ON, the kitchen ticket auto-prints right after payment of a held order — counter prints receipt, kitchen prints KOT.">
            <span class="text-[10px] uppercase tracking-wider font-extrabold text-orange-700 dark:text-orange-300">🍳 Auto-KOT</span>
            <button type="button"
                @click="autoKotLoading = true; fetch('{{ route('pos.api.toggle-auto-kot') }}', { method:'POST', headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}', 'Content-Type':'application/json', 'Accept':'application/json' } }).then(r => r.json()).then(d => { if (d.success) { autoKotEnabled = !!d.enabled; window.tnNotify && window.tnNotify('Auto-KOT', autoKotEnabled ? 'Enabled' : 'Disabled'); } else { alert(d.message || 'Toggle failed'); } autoKotLoading = false; }).catch(() => { autoKotLoading = false; alert('Toggle failed'); })"
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
    <div x-show="guidedFlow" x-cloak class="flex items-center justify-center flex-wrap gap-1.5 px-3 py-1.5 bg-gradient-to-r from-emerald-50 to-blue-50 dark:from-emerald-900/20 dark:to-blue-900/20 border-b border-emerald-200 dark:border-emerald-800 flex-shrink-0 text-[11px] font-bold select-none pointer-events-none">
@if($hasTypeStep)
        <template x-for="(s, i) in [{k:'customer',l:'1 · Customer'},{k:'items',l:'2 · Items'},{k:'type',l:'3 · Type'},{k:'cart',l:'4 · Cart'},{k:'finish',l:'5 · Bill'}]" :key="s.k">
@else
        <template x-for="(s, i) in [{k:'customer',l:'1 · Customer'},{k:'items',l:'2 · Items'},{k:'cart',l:'3 · Cart'},{k:'finish',l:'4 · Bill'}]" :key="s.k">
@endif
            <div class="flex items-center gap-1.5">
                <span :class="flowStep === s.k ? 'bg-emerald-600 text-white shadow-sm' : 'bg-white/70 dark:bg-gray-800/70 text-gray-500 dark:text-gray-400'" class="px-2.5 py-0.5 rounded-full transition" x-text="s.l"></span>
                <svg x-show="i < {{ $hasTypeStep ? 4 : 3 }}" class="w-3 h-3 text-gray-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </div>
        </template>
        <span class="ml-2 text-[10px] font-medium text-gray-500 dark:text-gray-400 hidden md:inline">Enter = add / next · empty Enter = @if($hasTypeStep)type → @endif cart · P = provisional</span>
    </div>

    {{-- ═══════════ GUIDED FLOW: ORDER-TYPE STEP (opt-in) ═══════════ --}}
    {{-- Owner-specified keyboard step BETWEEN Items and Cart. Reached by pressing Enter on an
         empty search box (cart already has items) when 2+ order types exist. Arrow keys move the
         highlight (handled in handleKey), Enter confirms + drops into cart, Esc returns to search. --}}
    @if($hasTypeStep)
    <div x-cloak x-show="guidedFlow && flowStep === 'type'" x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md p-6 border border-gray-100 dark:border-gray-800">
            <div class="text-center mb-5">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Order Type</h3>
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

    <div class="flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 flex-shrink-0 shadow-sm">

        <div class="relative flex-shrink-0" style="min-width:180px;max-width:220px;">
            <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
            <input type="search" x-ref="customerPhoneInput" x-model="customerPhoneQuery" @input="onCustomerPhoneInput()" @keydown.enter.prevent="onCustomerPhoneEnter()" @keydown.escape.prevent="customerPhoneDropdown = false" @keydown.tab.prevent="$refs.searchInput?.focus()" @click.away="customerPhoneDropdown = false" placeholder="Customer name or mobile..." class="w-full pl-9 pr-7 py-2.5 rounded-xl text-sm border-2 transition shadow-sm" :class="selectedCustomer ? 'font-bold border-blue-400 dark:border-blue-600 bg-blue-50 dark:bg-blue-900/20 text-blue-800 dark:text-blue-200' : 'font-medium border-blue-200 dark:border-blue-800 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-400'" autocomplete="one-time-code" name="pos_customer_phone_nofill" data-lpignore="true" data-form-type="other">
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
                    <button @click="selectCustomerFromPhone(cr)" class="w-full flex items-center gap-2 px-3 py-2.5 text-left hover:bg-blue-50 dark:hover:bg-blue-900/20 transition border-b border-gray-50 dark:border-gray-800" :class="ci === 0 ? 'bg-blue-50/50 dark:bg-blue-900/10' : ''">
                        <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0"><span class="text-xs font-bold text-blue-600" x-text="cr.name.charAt(0)"></span></div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-semibold text-gray-900 dark:text-white truncate" x-text="cr.name"></p>
                            <p class="text-[10px] text-gray-400" x-text="cr.phone + (cr.stats ? ' • ' + cr.stats.total_orders + ' orders • Rs.' + Number(cr.stats.total_spent).toLocaleString() : '')"></p>
                            <template x-if="cr.address"><p class="text-[9px] text-gray-400 truncate" x-text="cr.address"></p></template>
                        </div>
                        <template x-if="cr.stats && cr.stats.is_frequent"><span class="freq-badge">VIP</span></template>
                    </button>
                </template>
            </div>

            {{-- Inline "no match → quick add" hint (NO popup, INLINE only) --}}
            <div x-show="customerPhoneDropdown && !showNewCustomerInline && customerPhoneResults.length === 0 && customerPhoneQuery.length >= 4 && /^[0-9]+$/.test(customerPhoneQuery.trim()) && !customerSearching" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-blue-200 dark:border-blue-800 rounded-xl shadow-2xl z-50 overflow-hidden" style="min-width:280px;">
                <button @click="openInlineNewCustomer()" class="w-full flex items-center gap-2 px-3 py-2.5 text-left hover:bg-blue-50 dark:hover:bg-blue-900/20 transition">
                    <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-bold text-blue-700 dark:text-blue-300">Add new customer</p>
                        <p class="text-[10px] text-gray-500" x-text="customerPhoneQuery + ' · press Enter'"></p>
                    </div>
                </button>
            </div>

            {{-- Inline new-customer quick form (NO popup) --}}
            <div x-show="showNewCustomerInline" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-900 border-2 border-blue-400 dark:border-blue-600 rounded-xl shadow-2xl z-50 p-3 space-y-2" style="min-width:300px;" @keydown.escape.prevent="cancelInlineNewCustomer()">
                <div class="flex items-center justify-between">
                    <p class="text-[10px] font-bold text-blue-600 dark:text-blue-400 uppercase tracking-wider">+ New Customer</p>
                    <button type="button" @click="cancelInlineNewCustomer()" class="text-gray-400 hover:text-red-500 text-[10px] font-semibold">Cancel</button>
                </div>
                <div class="text-[10px] font-semibold text-gray-600 dark:text-gray-400 px-2 py-1.5 bg-gray-100 dark:bg-gray-800 rounded-lg">
                    <span class="text-gray-400">Mobile:</span> <span class="text-gray-900 dark:text-white font-bold" x-text="newCustomerPhone"></span>
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
                    <span x-show="!savingCustomer">Save & Select (Enter)</span>
                    <span x-show="savingCustomer">Saving…</span>
                </button>
            </div>
        </div>

        <div class="w-px h-6 bg-gray-200 dark:bg-gray-700 hidden sm:block flex-shrink-0"></div>

        <div class="flex-1 relative">
            <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="search" x-ref="searchInput" x-model="searchQuery" @input="onSearchInput()" @keydown.arrow-down.prevent="moveHighlight(1)" @keydown.arrow-up.prevent="moveHighlight(-1)" @keydown.enter.prevent.stop="addHighlightedItem($event)" @keydown.tab="if(flowStep === 'type'){ $event.preventDefault(); } else if(!searchQuery && cart.length > 0){ $event.preventDefault(); enterCartMode('last'); }" @focus="if(searchQuery) showSearchDropdown = true" @click.away="showSearchDropdown = false" placeholder="Search products... (type to filter, Enter to add, Tab → cart)" class="search-glow w-full pl-10 pr-10 py-2.5 rounded-xl text-sm border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-purple-400 transition shadow-sm" autocomplete="one-time-code" name="pos_product_search_nofill" data-lpignore="true" data-form-type="other" role="combobox">
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
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center bg-gradient-to-br from-purple-500 to-indigo-600 text-white flex-shrink-0 shadow">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">Create "<span x-text="searchQuery"></span>"</p>
                            <p class="text-[10px] text-gray-400">Adds to cart instantly · set price after</p>
                        </div>
                        <span class="text-[9px] font-mono bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300 px-1.5 py-0.5 rounded border border-purple-200 dark:border-purple-800">⏎</span>
                    </button>
                </template>
                <template x-if="isInventoryEnabled()">
                    <div class="px-3 py-3">
                        <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">Product not found</p>
                        <p class="text-[10px] text-gray-400 mb-2">Inventory mode requires you to add products from Product Management.</p>
                        <a href="{{ route('pos.products') }}" class="inline-flex items-center gap-1.5 text-[11px] font-bold text-purple-600 dark:text-purple-400 hover:text-purple-800 dark:hover:text-purple-300">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            Open Products
                        </a>
                    </div>
                </template>
            </div>
            <div x-show="quickCreating" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-purple-200 rounded-xl shadow-2xl z-50 px-3 py-3">
                <p class="text-xs text-gray-500 flex items-center gap-2">
                    <svg class="w-4 h-4 animate-spin text-purple-600" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                    Creating "<span x-text="searchQuery" class="font-semibold"></span>"…
                </p>
            </div>
            <div x-show="showSearchDropdown && searchSuggestions.length > 0" x-transition class="absolute top-full left-0 right-0 mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-2xl z-50 max-h-64 overflow-y-auto" x-ref="searchDropdown">
                <template x-for="(s, i) in searchSuggestions" :key="s.id + s.type">
                    <button @click="quickAddItem(s)" @mouseenter="highlightIndex = i"
                        :data-hl="i === highlightIndex ? 'true' : 'false'"
                        class="w-full flex items-center gap-3 px-3 py-2.5 text-left"
                        :style="i === highlightIndex ? 'background:#7c3aed !important; border-radius:10px; margin:2px 4px; width:calc(100% - 8px); box-shadow:0 4px 12px rgba(124,58,237,0.4);' : 'margin:2px 4px; width:calc(100% - 8px);'">
                        <template x-if="s.image">
                            <img :src="s.image" class="w-8 h-8 rounded-lg object-cover flex-shrink-0" :style="i === highlightIndex ? 'outline:2px solid white; outline-offset:1px;' : ''">
                        </template>
                        <template x-if="!s.image">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
                                :style="i === highlightIndex ? 'background:white; color:#7c3aed;' : 'background:linear-gradient(135deg,#f3e8ff,#ede9fe); color:#7c3aed;'">
                                <span class="text-xs font-bold" x-text="s.name.charAt(0)"></span>
                            </div>
                        </template>
                        <div class="flex-1 min-w-0">
                            <span class="text-sm font-semibold truncate block" :style="i === highlightIndex ? 'color:white;' : 'color:#1f2937;'" x-text="s.name"></span>
                            <div class="flex items-center gap-1.5">
                                <span class="text-[10px]" :style="i === highlightIndex ? 'color:rgba(255,255,255,0.7);' : 'color:#9ca3af;'" x-text="s.type === 'service' ? 'Service' : s.category"></span>
                                @if($company->inventory_enabled)
                                <template x-if="s.stockStatus && s.stockStatus !== 'available'"><span class="stock-dot" :class="'stock-' + s.stockStatus"></span></template>
                                @endif
                            </div>
                        </div>
                        <span class="text-sm font-extrabold" :style="i === highlightIndex ? 'color:white;' : 'color:#9333ea;'" x-text="'Rs. ' + Number(s.price).toLocaleString()"></span>
                    </button>
                </template>
            </div>
        </div>

        @if($features->tables)
        <button @click="showTablePicker = true" class="flex items-center gap-1.5 px-2.5 py-2 rounded-lg text-xs font-semibold border transition flex-shrink-0" :class="selectedTable ? 'bg-purple-50 dark:bg-purple-900/20 border-purple-300 dark:border-purple-700 text-purple-700 dark:text-purple-300' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800'">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            <span x-text="selectedTable ? 'T-' + selectedTable.table_number : 'Table'"></span>
        </button>
        @endif

        <div class="flex items-center rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden flex-shrink-0" title="Press F2 to cycle">
            @if($features->tables)
            <button @click="orderType = 'dine_in'" class="px-2 py-1.5 text-[10px] font-bold transition-all" :class="orderType === 'dine_in' ? 'bg-purple-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 hover:bg-gray-100'">Dine In</button>
            @endif
            <button @click="orderType = 'takeaway'" class="px-2 py-1.5 text-[10px] font-bold transition-all border-x border-gray-200 dark:border-gray-700" :class="orderType === 'takeaway' ? 'bg-purple-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 hover:bg-gray-100'">Takeaway</button>
            @if($features->delivery)
            <button @click="orderType = 'delivery'" class="px-2 py-1.5 text-[10px] font-bold transition-all" :class="orderType === 'delivery' ? 'bg-purple-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 hover:bg-gray-100'">Delivery</button>
            @endif
            <span class="px-1.5 py-1.5 text-[8px] font-mono text-gray-400 bg-gray-50 dark:bg-gray-800 border-l border-gray-200 dark:border-gray-700">F2</span>
        </div>

        <div class="w-px h-6 bg-gray-200 dark:bg-gray-700 hidden sm:block flex-shrink-0"></div>

        <button @click="priorityOrder = !priorityOrder" class="hidden sm:flex items-center gap-1 px-2.5 py-2 rounded-xl text-xs font-semibold border transition" :class="priorityOrder ? 'bg-red-50 dark:bg-red-900/20 border-red-300 text-red-600' : 'border-gray-200 dark:border-gray-700 text-gray-500 hover:bg-gray-50'">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            <span>Rush</span>
        </button>

        <button @click="showShortcuts = true" class="flex items-center gap-1 px-2 py-2 rounded-xl text-xs font-bold text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 hover:bg-purple-50 hover:text-purple-600 hover:border-purple-300 transition flex-shrink-0" title="Keyboard Shortcuts (F1)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3C6.5 3 2 6.58 2 11c0 2.24 1.12 4.27 2.94 5.72L4 21l4.28-2.55c1.15.35 2.4.55 3.72.55 5.5 0 10-3.58 10-8s-4.5-8-10-8z"/></svg>
            <span class="hidden lg:inline">Keys</span>
            <span class="text-[8px] font-mono bg-gray-200 dark:bg-gray-700 px-1 rounded hidden sm:inline">F1</span>
        </button>

        <button @click="openQuickType()" class="flex items-center gap-1 px-2 py-2 rounded-xl text-xs font-bold text-sky-700 dark:text-sky-400 bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-800 hover:bg-sky-100 hover:border-sky-300 transition flex-shrink-0" title="Quick Type Mode (F7) — type 'chai 2, samosa 1' or pick random product">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            <span class="hidden lg:inline">Quick</span>
            <span class="text-[8px] font-mono bg-sky-200 dark:bg-sky-800/50 px-1 rounded hidden sm:inline">F7</span>
        </button>

        {{-- Manual Item — only when inventory mode is OFF (Simple Mode).
             Lets the cashier bill an ad-hoc item that isn't in the product list.
             Optional checkbox in the modal also persists it to /pos/products. --}}
        <template x-if="!isInventoryEnabled()">
            <button @click="openManualItem()" class="flex items-center gap-1 px-2 py-2 rounded-xl text-xs font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 hover:bg-emerald-100 hover:border-emerald-300 transition flex-shrink-0" title="Add a manual item to the bill (not in product list)">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                <span class="hidden lg:inline">Manual</span>
            </button>
        </template>

        <button @click="newSale()" class="flex items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 hover:bg-green-100 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            <span class="hidden sm:inline">New</span>
        </button>

        {{-- ── PROVISIONAL BILLS (Local) — header shortcut. Same pattern as Held. ── --}}
        {{-- 🟢/🟡/🔴 Auto-Sync status pill — live network + pending-bill indicator --}}
        <div class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl text-[11px] font-bold border transition"
             :class="syncStatus === 'online' ? 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800' : (syncStatus === 'syncing' ? 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800' : 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 border-red-200 dark:border-red-800')"
             :title="syncStatus === 'online' ? ('Auto-Sync Online' + (failedBills.length ? ' · ' + failedBills.length + ' pending' : '')) : (syncStatus === 'syncing' ? 'Syncing pending bills to PRA…' : 'Offline — bills will auto-sync when network returns')">
            <span class="w-2 h-2 rounded-full"
                  :class="syncStatus === 'online' ? 'bg-emerald-500' : (syncStatus === 'syncing' ? 'bg-amber-500 animate-pulse' : 'bg-red-500 animate-pulse')"></span>
            <span x-text="syncStatus === 'online' ? 'Online' : (syncStatus === 'syncing' ? 'Syncing' : 'Offline')"></span>
            <span x-show="failedBills.length > 0" class="ml-0.5 px-1.5 rounded-full text-[9px] font-black"
                  :class="syncStatus === 'online' ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white'"
                  x-text="failedBills.length"></span>
        </div>
        {{-- Click → modal with Edit / Delete / Make Final actions inline. F10 shortcut. --}}
        <button @click="openLocalBills()" class="relative flex items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-purple-700 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 hover:bg-purple-100 transition" title="Provisional bills (local — not submitted to PRA). Press F10.">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
            <span class="text-[10px] bg-purple-400/30 px-1 rounded">F10</span>
            <span class="hidden sm:inline">Local</span>
            <span x-show="localBills.length > 0" class="absolute -top-1 -right-1 min-w-[20px] h-5 px-1 bg-purple-600 text-white text-[10px] rounded-full flex items-center justify-center font-bold" x-text="localBills.length"></span>
        </button>

        {{-- ── FAILED BILLS — header shortcut. F11. Red theme = needs attention. ── --}}
        {{-- Click → modal with Retry / Edit / Delete actions inline. --}}
        <button @click="openFailedBills()" class="relative flex items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 hover:bg-red-100 transition" title="Failed PRA submissions — needs retry. Press F11.">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span class="text-[10px] bg-red-400/30 px-1 rounded">F11</span>
            <span class="hidden sm:inline">Failed</span>
            <span x-show="failedBills.length > 0" class="absolute -top-1 -right-1 min-w-[20px] h-5 px-1 bg-red-600 text-white text-[10px] rounded-full flex items-center justify-center font-bold animate-pulse" x-text="failedBills.length"></span>
        </button>

        <button @click="activeHeldIndex = 0; showHeldOrders = !showHeldOrders" class="relative flex items-center gap-1 px-3 py-2 rounded-xl text-xs font-bold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 hover:bg-amber-100 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="text-[10px] bg-amber-400/30 px-1 rounded">F3</span>
            <span class="hidden sm:inline">Held</span>
            <span x-show="heldOrders.length > 0" class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-[10px] rounded-full flex items-center justify-center font-bold" x-text="heldOrders.length"></span>
        </button>

        <div class="hidden md:flex items-center gap-1.5">
            <button @click="holdOrder()" :disabled="cart.length === 0 || submitting || hasManualItems()" :title="hasManualItems() ? 'Manual items billing-only — pay first or remove from cart to hold' : 'Hold this order'" class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold bg-amber-500 hover:bg-amber-600 text-white disabled:opacity-40 disabled:cursor-not-allowed shadow-sm transition">
                <svg x-show="submitting" class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                <span x-show="!submitting" class="text-[10px] bg-amber-400/30 px-1 rounded">F5</span> <span x-text="submitting ? 'Holding...' : 'Hold'"></span>
            </button>

            {{-- Phase 5 — Send to Kitchen (visible only when feature.kot is on) --}}
            @if($features->kot ?? false)
            <button @click="sendToKitchen()" :disabled="cart.length === 0 || submitting || hasManualItems()" :title="hasManualItems() ? 'Manual items billing-only — pay first or remove from cart' : 'Saves the order and prints the kitchen ticket without taking payment.'" class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold bg-orange-500 hover:bg-orange-600 text-white disabled:opacity-40 disabled:cursor-not-allowed shadow-sm transition">
                <span class="text-base leading-none">🍳</span>
                <span x-text="submitting ? 'Sending...' : 'Send to Kitchen'"></span>
            </button>
            @endif

            <button @click="showPayModal = true" :disabled="cart.length === 0 || submitting" class="flex items-center gap-1.5 px-5 py-2 rounded-xl text-xs font-bold bg-green-600 hover:bg-green-700 text-white disabled:opacity-40 shadow-lg shadow-green-600/20 transition">
                <span x-show="!submitting" class="text-[10px] bg-green-500/30 px-1 rounded">F8</span> Pay
            </button>
        </div>
    </div>

    <div class="flex flex-1 overflow-hidden">

        <div class="flex-1 flex flex-col overflow-hidden" :class="mobileView === 'menu' ? 'flex' : 'hidden md:flex'">

            <div class="flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-900 border-b border-gray-100 dark:border-gray-800 flex-shrink-0">
                <div class="flex items-center gap-2 overflow-x-auto hide-scrollbar flex-1 min-w-0">
                    <button @click="activeCategory = 'all'; filterProducts()" x-show="showProducts" class="cat-pill px-4 py-1.5 rounded-full text-xs font-semibold border" :class="activeCategory === 'all' ? 'active border-transparent' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-800'">
                        All <span class="ml-1 text-[10px] opacity-70" x-text="'(' + (allProducts.filter(p => p.show_on_sale !== false).length + allServices.length) + ')'"></span>
                    </button>
                    @foreach($categories as $cat)
                    <button @click="activeCategory = '{{ $cat }}'; filterProducts()" x-show="showProducts" class="cat-pill px-4 py-1.5 rounded-full text-xs font-semibold border" :class="activeCategory === '{{ $cat }}' ? 'active border-transparent' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-800'">{{ $cat }}</button>
                    @endforeach
                    <button @click="activeCategory = 'services'; filterProducts()" x-show="showProducts" class="cat-pill px-4 py-1.5 rounded-full text-xs font-semibold border" :class="activeCategory === 'services' ? 'active border-transparent' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-800'">Services</button>
                    <span x-show="!showProducts" class="text-[11px] text-gray-400 dark:text-gray-500 italic px-1 whitespace-nowrap">Grid hidden — search to add, or type to create</span>
                </div>
                {{-- MASTER products toggle — inventory-OFF (Simple) mode ONLY. In inventory mode the
                     catalog is mandatory (no on-the-fly manual create), so hiding it would brick billing. --}}
                @if(!($inventoryEnabled ?? false))
                <button type="button" @click="toggleShowProducts()" role="switch" :aria-checked="showProducts ? 'true' : 'false'"
                        class="flex-shrink-0 flex items-center gap-1.5 px-2.5 py-1.5 rounded-full text-[11px] font-bold border transition"
                        :class="showProducts ? 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300' : 'bg-gray-100 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400'"
                        :title="showProducts ? 'Saved products billing par dikh rahe hain — chhupane ke liye click karein' : 'Grid hidden — search se saved product add karein ya naya type karein. Grid dikhane ke liye click karein.'">
                    <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    <span x-text="showProducts ? 'Products' : 'Products OFF'" class="whitespace-nowrap"></span>
                    <span class="relative inline-flex h-4 w-7 items-center rounded-full transition flex-shrink-0" :class="showProducts ? 'bg-emerald-600' : 'bg-gray-400 dark:bg-gray-600'">
                        <span class="inline-block h-3 w-3 transform rounded-full bg-white transition" :class="showProducts ? 'translate-x-3.5' : 'translate-x-0.5'"></span>
                    </span>
                </button>
                @endif
            </div>

            <div x-ref="gridContainer" tabindex="0" @keydown.arrow-right.prevent="moveGridFocus(1)" @keydown.arrow-left.prevent="moveGridFocus(-1)" @keydown.arrow-down.prevent="moveGridFocus(gridCols)" @keydown.arrow-up.prevent="moveGridFocus(-gridCols)" @keydown.enter.prevent="addGridFocusedItem()" class="flex-1 overflow-y-auto p-3 outline-none">

                <template x-if="loading">
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                        <template x-for="i in 12"><div class="rounded-2xl overflow-hidden"><div class="skeleton aspect-square"></div><div class="p-2.5 space-y-2"><div class="skeleton h-3 rounded w-3/4"></div><div class="skeleton h-4 rounded w-1/2"></div></div></div></template>
                    </div>
                </template>

                <template x-if="!loading">
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                        <template x-for="(item, idx) in displayItems" :key="item.id + '-' + item.type">
                            <div :id="'grid-item-' + idx" class="prod-card bg-white dark:bg-gray-900 rounded-2xl overflow-hidden border border-gray-100 dark:border-gray-800 shadow-sm fade-in" :class="[gridFocusMode && gridFocusIndex === idx ? 'ring-2 ring-purple-500 shadow-purple-200 dark:shadow-purple-900' : '', item.stockStatus === 'out' && blockOutOfStock ? 'stock-out' : (item.stockStatus === 'out' && !blockOutOfStock ? 'stock-out allow-add' : '')]" @click="handleProductClick(item)">
                                {{-- IMAGE CARD: only render the big image area when a real uploaded image exists. --}}
                                <template x-if="item.image">
                                    <div class="relative aspect-[4/3] bg-gradient-to-br from-gray-100 to-gray-50 dark:from-gray-800 dark:to-gray-900 flex items-center justify-center overflow-hidden">
                                        <img :src="item.image" :alt="item.name" class="w-full h-full object-cover" loading="lazy" onerror="this.style.display='none';">
                                        @if($company->inventory_enabled)
                                        <div class="absolute top-1.5 left-1.5 flex flex-col gap-1">
                                            <template x-if="item.stockStatus === 'low'"><span class="stock-dot stock-low" title="Low stock"></span></template>
                                            <template x-if="item.stockStatus === 'out'"><span class="px-1.5 py-0.5 bg-red-500/90 text-white text-[8px] font-bold rounded-md">OUT</span></template>
                                        </div>
                                        @endif
                                        <div class="absolute top-1.5 right-1.5 flex flex-col gap-1">
                                            @if($company->inventory_enabled)
                                            <template x-if="item.hasRecipe"><span class="px-1.5 py-0.5 bg-orange-500/90 text-white text-[8px] font-bold rounded-md flex items-center gap-0.5"><span class="text-[9px]">&#x1F373;</span> Recipe</span></template>
                                            @endif
                                            <template x-if="item.is_tax_exempt"><span class="px-1.5 py-0.5 bg-green-500/90 text-white text-[8px] font-bold rounded-md">NO TAX</span></template>
                                        </div>
                                        <button @click.stop="handleProductClick(item)" class="quick-add absolute bottom-2 right-2 w-9 h-9 rounded-full bg-purple-600 hover:bg-purple-700 text-white shadow-lg shadow-purple-600/30 flex items-center justify-center transition-all">
                                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                                        </button>
                                    </div>
                                </template>
                                {{-- TEXT-ONLY ROW: when no image, render a compact name+price list row — no placeholder, no letter badge. --}}
                                <template x-if="!item.image">
                                    <div class="relative flex items-center justify-end gap-1 px-3 pt-2.5 min-h-[26px]">
                                        @if($company->inventory_enabled)
                                        <template x-if="item.stockStatus === 'low'"><span class="stock-dot stock-low" title="Low stock"></span></template>
                                        <template x-if="item.stockStatus === 'out'"><span class="px-1.5 py-0.5 bg-red-500/90 text-white text-[8px] font-bold rounded-md">OUT</span></template>
                                        <template x-if="item.hasRecipe"><span class="px-1.5 py-0.5 bg-orange-500/90 text-white text-[8px] font-bold rounded-md flex items-center gap-0.5"><span class="text-[9px]">&#x1F373;</span> Recipe</span></template>
                                        @endif
                                        <template x-if="item.is_tax_exempt"><span class="px-1.5 py-0.5 bg-green-500/90 text-white text-[8px] font-bold rounded-md">NO TAX</span></template>
                                    </div>
                                </template>
                                <div class="px-3 py-2.5">
                                    <p class="font-bold text-gray-900 dark:text-white truncate leading-tight" :class="item.image ? 'text-xs' : 'text-sm'" x-text="item.name"></p>
                                    <div class="flex items-center justify-between mt-1.5 gap-2">
                                        <span class="price-badge text-sm font-extrabold text-purple-600 dark:text-purple-400" x-text="'Rs. ' + Number(item.price).toLocaleString()"></span>
                                        <div class="flex items-center gap-2">
                                            <template x-if="getCartQty(item) > 0">
                                                <span class="cart-qty-badge text-[10px] bg-gradient-to-br from-purple-500 to-purple-700 text-white w-6 h-6 rounded-full flex items-center justify-center font-bold shadow-lg shadow-purple-500/30" x-text="getCartQty(item)"></span>
                                            </template>
                                            {{-- Inline + button for the no-image text row (image cards already have the floating quick-add). --}}
                                            <template x-if="!item.image">
                                                <button @click.stop="handleProductClick(item)" class="w-8 h-8 rounded-full bg-purple-600 hover:bg-purple-700 text-white flex items-center justify-center shadow-md shadow-purple-600/30 transition-all">
                                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                <template x-if="!loading && displayItems.length === 0">
                    <div class="tn-empty flex flex-col items-center justify-center py-24 px-6 text-gray-400 text-center">
                        <div class="tn-empty-icon w-28 h-28 rounded-full bg-purple-100 dark:bg-purple-900/40 flex items-center justify-center mb-5">
                            <svg class="w-14 h-14 text-purple-400 dark:text-purple-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/></svg>
                        </div>
                        <p class="text-lg font-bold text-gray-700 dark:text-gray-200">No products match</p>
                        <p class="text-sm mt-1.5 text-gray-400 dark:text-gray-500 max-w-[280px]">Try a different category or clear your search to see everything</p>
                        <button @click="activeCategory = 'all'; searchQuery = ''; filterProducts()" class="mt-5 px-5 py-2.5 text-sm font-bold text-white bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 rounded-xl shadow-md shadow-purple-600/20">Show All Products</button>
                    </div>
                </template>

                <template x-if="!loading && filteredItems.length > displayCount">
                    <div class="flex justify-center py-4">
                        <button @click="loadMore()" class="px-6 py-2.5 text-sm font-semibold text-purple-600 bg-purple-50 dark:bg-purple-900/20 rounded-xl hover:bg-purple-100 transition border border-purple-200 dark:border-purple-800">
                            Load More (<span x-text="filteredItems.length - displayCount"></span> remaining)
                        </button>
                    </div>
                </template>
            </div>

            <div class="md:hidden flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800">
                <button @click="mobileView = 'cart'" class="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl bg-purple-600 text-white text-sm font-bold shadow-lg shadow-purple-600/20">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                    Cart
                    <span x-show="cart.length > 0" class="bg-white/20 px-1.5 rounded-full text-xs" x-text="cart.length"></span>
                    <span x-show="cart.length > 0" class="text-xs opacity-80" x-text="'Rs. ' + Number(roundedTotal).toLocaleString()"></span>
                </button>
            </div>

            <button x-show="cart.length > 0 && !cartMode" @click="enterCartMode(); mobileView = 'cart';"
                class="pos-edit-cart-floating-btn"
                style="position:fixed; bottom:24px; right:400px; z-index:60; background:linear-gradient(135deg,#7c3aed,#6d28d9); color:white; border:none; border-radius:16px; padding:10px 20px; font-size:13px; font-weight:700; cursor:pointer; box-shadow:0 8px 24px rgba(124,58,237,0.4), 0 2px 8px rgba(0,0,0,0.15); display:flex; align-items:center; gap:8px; transition:all 0.2s;"
                x-transition
                title="Jump to Cart & Edit (F6)"
                @mouseenter="$el.style.transform='scale(1.05)'; $el.style.boxShadow='0 12px 32px rgba(124,58,237,0.5)'"
                @mouseleave="$el.style.transform='scale(1)'; $el.style.boxShadow='0 8px 24px rgba(124,58,237,0.4)'">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                <span>Edit Cart</span>
                <span style="background:rgba(255,255,255,0.25); padding:2px 8px; border-radius:8px; font-size:11px; font-weight:800;" x-text="cart.length"></span>
                <span style="font-size:10px; opacity:0.7; margin-left:2px;" x-text="'Rs.' + Number(roundedTotal).toLocaleString()"></span>
                <span style="background:rgba(255,255,255,0.15); padding:2px 6px; border-radius:6px; font-size:9px; font-weight:700; letter-spacing:0.5px; border:1px solid rgba(255,255,255,0.25);">F6</span>
            </button>
        </div>

        <div class="w-full md:w-[340px] lg:w-[380px] bg-white dark:bg-gray-900 border-l border-gray-200 dark:border-gray-800 flex flex-col flex-shrink-0 shadow-xl" :class="mobileView === 'cart' ? 'flex' : 'hidden md:flex'">
            <div class="flex items-center gap-2 px-3 py-2.5 border-b border-gray-100 dark:border-gray-800">
                <button @click="mobileView = 'menu'" class="md:hidden p-1.5 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/20 rounded-lg">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                <span class="text-sm font-bold text-gray-900 dark:text-white flex-1">Current Order</span>
                <button x-show="cart.length > 0" @click="enterCartMode()" class="flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-bold transition-all"
                    :style="cartMode ? 'background:#7c3aed; color:white; box-shadow:0 2px 8px rgba(124,58,237,0.3);' : 'background:#f3e8ff; color:#7c3aed;'"
                    :title="cartMode ? 'Cart Edit Mode ON — ↑↓ navigate, +/- qty, T tax toggle, Del remove, Esc exit' : 'Enter Cart Edit Mode'">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    <span x-text="cartMode ? 'Editing' : 'Edit'"></span>
                </button>
                <template x-if="priorityOrder"><span class="text-[10px] bg-red-100 text-red-600 px-2 py-0.5 rounded-full font-bold">RUSH</span></template>
                <span class="text-[10px] bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 px-2 py-0.5 rounded-full font-semibold" x-text="orderType.replace('_', ' ').toUpperCase()"></span>
                <template x-if="selectedTable">
                    <span class="text-[10px] bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-2 py-0.5 rounded-full font-semibold" x-text="'T-' + selectedTable.table_number"></span>
                </template>
            </div>

            <template x-if="selectedCustomer">
                <div class="px-3 py-2 bg-blue-50 dark:bg-blue-900/10 border-b border-blue-100 dark:border-blue-900/20 flex items-start gap-2">
                    <div class="w-8 h-8 rounded-full bg-blue-200 dark:bg-blue-800 flex items-center justify-center flex-shrink-0 mt-0.5"><span class="text-xs font-bold text-blue-700 dark:text-blue-300" x-text="selectedCustomer.name.charAt(0)"></span></div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-1.5">
                            <p class="text-xs font-semibold text-blue-800 dark:text-blue-200 truncate" x-text="selectedCustomer.name"></p>
                            <template x-if="customerStats && customerStats.is_frequent"><span class="freq-badge">VIP</span></template>
                        </div>
                        <p class="text-[10px] text-blue-600 dark:text-blue-400" x-text="selectedCustomer.phone || 'No phone'"></p>
                        <template x-if="selectedCustomer.address">
                            <p class="text-[10px] text-blue-500 dark:text-blue-400 truncate" x-text="'📍 ' + selectedCustomer.address"></p>
                        </template>
                        <template x-if="customerStats">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 mt-0.5">
                                <span class="text-[10px] font-semibold text-blue-700 dark:text-blue-300" x-text="(customerStats.total_orders || 0) + ' orders'"></span>
                                <span class="text-[10px] text-gray-400">•</span>
                                <span class="text-[10px] font-semibold text-blue-700 dark:text-blue-300" x-text="'Rs. ' + Number(customerStats.total_spent || 0).toLocaleString() + ' spent'"></span>
                                <template x-if="customerStats.last_order_date">
                                    <span class="text-[10px] text-gray-400">•</span>
                                </template>
                                <template x-if="customerStats.last_order_date">
                                    <span class="text-[10px] text-blue-600 dark:text-blue-400" x-text="'Last: ' + customerStats.last_order_date"></span>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            <div class="flex-1 min-h-0 overflow-y-auto" x-ref="cartList">
                <template x-if="cart.length === 0">
                    <div class="tn-empty flex flex-col items-center justify-center h-full text-gray-400 py-16 px-6 text-center">
                        <div class="tn-empty-icon w-24 h-24 rounded-full bg-purple-100 dark:bg-purple-900/40 flex items-center justify-center mb-5">
                            <svg class="w-12 h-12 text-purple-400 dark:text-purple-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                        </div>
                        <p class="text-base font-bold text-gray-700 dark:text-gray-200">Your cart is empty</p>
                        <p class="text-xs mt-1.5 text-gray-400 dark:text-gray-500 max-w-[220px]">Tap a product on the left, or scan a barcode to start a new sale</p>
                    </div>
                </template>
                <template x-if="cartMode && cart.length > 0">
                    <div style="background:linear-gradient(90deg,#7c3aed,#6d28d9); padding:6px 12px; display:flex; align-items:center; gap:8px;">
                        <svg class="w-3.5 h-3.5" style="color:white; flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        <span style="color:rgba(255,255,255,0.9); font-size:10px; font-weight:600;">↑↓ Navigate &nbsp; +/− Qty &nbsp; 0-9 Set Qty &nbsp; Del Remove &nbsp; Esc Exit</span>
                    </div>
                </template>
                <template x-for="(item, index) in cart" :key="item.cart_uid">
                    <div class="cart-item cart-item-enter px-3 py-2.5 cursor-pointer relative"
                        :class="activeCartIndex === index ? 'cart-row-active' : ''"
                        @click="selectCartRow(index)" :data-cart-index="index">
                        <div class="flex items-center gap-2.5">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-gray-900 dark:text-white truncate flex items-center gap-1.5">
                                    <span x-text="item.item_name"></span>
                                    <template x-if="item._isQuickCreated">
                                        <span class="text-[8px] font-bold uppercase tracking-wider text-purple-700 bg-purple-100 dark:bg-purple-900/30 dark:text-purple-300 px-1.5 py-0.5 rounded">No Recipe</span>
                                    </template>
                                </p>
                                {{-- Inline price editor — only shown when this row needs a price set (quick-created or zero-price).
                                     Enter / blur saves to backend, updates cart unit_price + master allProducts. --}}
                                <template x-if="quickPriceCartUid === item.cart_uid">
                                    <div class="flex items-center gap-1.5 mt-1" @click.stop>
                                        <span class="text-[10px] text-gray-500">Rs.</span>
                                        <input type="number" min="0" step="any" x-ref="quickPriceInput" data-quick-price-input
                                            x-model.number="quickPriceValue"
                                            @keydown.enter.prevent="saveQuickPrice(index, true)"
                                            @keydown.escape.prevent="cancelQuickPrice()"
                                            @blur="saveQuickPrice(index)"
                                            placeholder="Enter price"
                                            class="w-24 text-xs font-bold bg-purple-50 dark:bg-purple-900/20 border-2 border-purple-300 dark:border-purple-700 rounded-md px-2 py-1 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:outline-none">
                                        <span class="text-[9px] text-gray-400">⏎ Save · Esc Cancel</span>
                                    </div>
                                </template>
                                <template x-if="quickPriceCartUid !== item.cart_uid">
                                    <p class="text-[11px] text-gray-400 mt-0.5">
                                        <span x-text="'Rs. ' + Number(item.unit_price).toLocaleString() + '/unit'"></span>
                                        <template x-if="item._isQuickCreated && Number(item.unit_price) === 0">
                                            <button @click.stop="openQuickPrice(item)" class="ml-1 text-purple-600 hover:underline font-semibold">Set price</button>
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
                            </div>
                            <div class="text-right min-w-[60px]">
                                <p class="text-sm font-extrabold text-gray-900 dark:text-white" x-text="'Rs.' + getItemTotal(item).toLocaleString()"></p>
                            </div>
                            <button @click.stop="removeFromCart(index)" class="p-1.5 text-red-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition active:scale-90">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                        <div class="flex items-center gap-1.5 mt-1.5 justify-end">
                            <button @click.stop="item.is_tax_exempt = !item.is_tax_exempt" class="text-[11px] font-extrabold px-2 py-1 rounded-md transition whitespace-nowrap ring-1" :class="item.is_tax_exempt ? 'bg-green-500 text-white ring-green-600 shadow-sm' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 ring-gray-300 dark:ring-gray-600 hover:ring-green-500 hover:text-green-600'" :title="item.is_tax_exempt ? 'Tax exempt — click or press T to apply tax' : 'Press T (when search empty) or Alt+T (anywhere) to toggle tax'" x-text="item.is_tax_exempt ? 'NO TAX (T)' : 'TAX (T)'"></button>
                            <button @click.stop="item.showItemDiscount = !item.showItemDiscount" class="text-[9px] font-bold px-1.5 py-1 rounded-md transition whitespace-nowrap" :class="(item.item_discount_value || 0) > 0 ? 'bg-orange-100 text-orange-600' : 'bg-gray-100 dark:bg-gray-700 text-gray-400 hover:text-orange-500'" x-text="(item.item_discount_value || 0) > 0 ? ((item.item_discount_type || 'percentage') === 'percentage' ? '-' + item.item_discount_value + '%' : '-Rs.' + item.item_discount_value) : 'Disc'"></button>
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
                        @if($features->kitchen_notes)
                        {{-- Per-item kitchen note (e.g. "no onions") — parity with restaurant screen, gated by kitchen_notes feature --}}
                        <div class="mt-1" @click.stop>
                            <input type="text" x-model="item.special_notes"
                                autocomplete="off" name="pos_item_note_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                                @keydown.enter.prevent.stop="$event.target.blur()"
                                @keydown.escape.prevent.stop="$event.target.blur()"
                                placeholder="Item note (e.g. no onions, extra spicy)..."
                                class="dense-input w-full text-[10px] bg-amber-50/60 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-900/30 rounded-md px-2 py-1 text-gray-600 dark:text-gray-300 focus:ring-amber-400 placeholder-gray-300">
                        </div>
                        @endif
                    </div>
                </template>
            </div>

            <div class="border-t border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-900/80 backdrop-blur-sm">
                <div class="px-3 py-1.5">
                    <textarea x-model="kitchenNotes" x-ref="orderNotesInput" rows="1"
                        autocomplete="off" name="pos_order_notes_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                        @keydown.enter.prevent.stop="$event.target.blur()"
                        @keydown.escape.prevent.stop="$event.target.blur()"
                        placeholder="Order Notes... (press N to focus, ⏎/Esc to exit)"
                        class="w-full text-xs bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-2.5 py-1.5 text-gray-700 dark:text-gray-300 focus:ring-purple-500 focus:border-purple-500 resize-none placeholder-gray-400"></textarea>
                </div>
                <div class="px-3 py-1.5">
                    <div class="flex items-center gap-1.5">
                        <button @click="showDiscount = !showDiscount" class="text-[10px] font-semibold px-2 py-0.5 rounded-lg transition" :class="discountAmount > 0 ? 'bg-orange-100 dark:bg-orange-900/20 text-orange-600' : 'bg-gray-100 dark:bg-gray-800 text-gray-500 hover:bg-gray-200'">
                            <span x-text="discountAmount > 0 ? 'Discount: -Rs. ' + Number(discountAmount).toLocaleString() : '+ Discount'"></span>
                        </button>
                        <span class="text-[8px] text-gray-400" x-text="'Limit: ' + effectiveDiscountLimit + '%'"></span>
                        <button x-show="!managerOverrideActive && hasManagerPin && posRole !== 'pos_admin'" @click="requestManagerOverride()" class="text-[8px] font-bold text-blue-600 hover:text-blue-800 px-1">Override</button>
                        <span x-show="managerOverrideActive" class="text-[8px] font-bold text-green-600 px-1">Unlocked</span>
                    </div>
                    <div x-show="showDiscount" x-transition class="mt-1.5 p-2 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl space-y-1.5">
                        <div class="flex gap-1">
                            <button @click="discountType = 'percentage'" class="flex-1 text-[10px] font-bold py-1 rounded-lg transition" :class="discountType === 'percentage' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-500'">%</button>
                            <button @click="discountType = 'amount'" class="flex-1 text-[10px] font-bold py-1 rounded-lg transition" :class="discountType === 'amount' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-500'">Rs.</button>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <input type="number" x-model.number="discountValue" @input="if(!checkDiscountLimit(discountValue, discountType)) { discountValue = discountType === 'percentage' ? effectiveDiscountLimit : effectiveSubtotal; showToast(discountType === 'percentage' ? 'Discount capped at ' + effectiveDiscountLimit + '%' : 'Discount cannot exceed subtotal', 'error'); } recalcDiscount()" min="0" :max="discountType === 'percentage' ? effectiveDiscountLimit : effectiveSubtotal" step="any" :placeholder="discountType === 'percentage' ? 'Max ' + effectiveDiscountLimit + '%' : 'Direct amount Rs.'" class="flex-1 text-xs bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg px-2 py-1.5 text-gray-900 dark:text-white focus:ring-purple-500">
                            <button @click="discountValue = 0; recalcDiscount(); showDiscount = false" class="text-[10px] text-red-500 hover:text-red-700 px-1.5">Clear</button>
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
                <div class="px-3 py-2 space-y-1">
                    <div class="flex justify-between text-xs text-gray-500"><span>Subtotal</span><span x-text="'Rs. ' + Number(subtotal).toLocaleString()"></span></div>
                    <div x-show="itemDiscountsTotal > 0" class="flex justify-between text-xs text-orange-500">
                        <span>Item Discounts</span>
                        <span x-text="'-Rs. ' + Number(itemDiscountsTotal).toLocaleString()"></span>
                    </div>
                    <div x-show="discountAmount > 0" class="flex justify-between text-xs text-orange-600 dark:text-orange-400">
                        <span x-text="discountType === 'percentage' ? 'Order Discount (' + discountValue + '%)' : 'Order Discount'"></span>
                        <span x-text="'-Rs. ' + Number(discountAmount).toLocaleString()"></span>
                    </div>
                    <div x-show="exemptAmount > 0" class="flex justify-between text-xs text-green-600 dark:text-green-400"><span>Tax-Exempt</span><span x-text="'-Rs. ' + Number(exemptAmount).toLocaleString()"></span></div>
                    <div class="flex justify-between text-xs text-gray-500"><span x-text="'Tax (' + taxRate + '%)'"></span><span x-text="'Rs. ' + Number(taxAmount).toLocaleString()"></span></div>
                    <div x-show="Math.abs(roundOff) > 0.001" class="flex justify-between text-xs text-blue-500 dark:text-blue-400">
                        <span>Round Off</span>
                        <span x-text="(roundOff >= 0 ? '+ Rs. ' : '− Rs. ') + Math.abs(roundOff).toFixed(2)"></span>
                    </div>
                    <div class="flex items-baseline justify-between pt-2 mt-1 border-t tn-hairline">
                        <span class="text-sm font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Total</span>
                        <span class="total-animate total-line text-2xl font-black text-gray-900 dark:text-white" x-text="'Rs. ' + Number(roundedTotal).toLocaleString()" :class="cartAnimating ? 'cart-pop' : ''" :style="roundedTotal > 0 ? 'color: #059669' : ''"></span>
                    </div>
                    <div x-show="posRole === 'pos_admin' && getCartCost() > 0" class="flex justify-between text-[10px] text-gray-400 pt-0.5">
                        <span>Est. Cost</span><span x-text="'Rs. ' + r2(getCartCost()).toLocaleString()"></span>
                    </div>
                    <div x-show="posRole === 'pos_admin' && getCartCost() > 0" class="flex justify-between text-[10px] font-semibold" :class="(totalAmount - getCartCost()) >= 0 ? 'text-green-600' : 'text-red-500'">
                        <span>Est. Profit</span><span x-text="'Rs. ' + r2(totalAmount - getCartCost()).toLocaleString()"></span>
                    </div>
                </div>
                <div class="px-3 pb-3 space-y-2 mobile-sticky-pay">
                    <div class="grid grid-cols-3 gap-2">
                        <button @click="if(cart.length && confirm('Clear entire cart?')) { clearCart(); }" :disabled="cart.length === 0" class="py-2 text-xs font-bold text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 rounded-xl border border-red-200 dark:border-red-800 hover:bg-red-100 disabled:opacity-30 transition flex items-center justify-center gap-0.5">Clear <kbd class="text-[8px] bg-red-200/50 dark:bg-red-800/30 px-1 rounded font-mono">F4</kbd></button>
                        <button @click="holdOrder()" :disabled="cart.length === 0 || submitting || hasManualItems()" :title="hasManualItems() ? 'Manual items billing-only — pay first or remove' : ''" class="py-2 text-xs font-bold text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 rounded-xl border border-amber-200 dark:border-amber-800 hover:bg-amber-100 disabled:opacity-30 disabled:cursor-not-allowed transition flex items-center justify-center gap-1">
                            <svg x-show="submitting" class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <span x-text="submitting ? 'Holding...' : 'Hold'"></span>
                            <kbd x-show="!submitting" class="text-[8px] bg-amber-200/50 dark:bg-amber-800/30 px-1 rounded ml-0.5 font-mono">F5</kbd>
                        </button>
                        <button @click="showHeldOrders = !showHeldOrders" class="relative py-2 text-xs font-bold text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/20 rounded-xl border border-purple-200 dark:border-purple-800 hover:bg-purple-100 transition flex items-center justify-center gap-0.5">
                            Recall <kbd class="text-[8px] bg-purple-200/50 dark:bg-purple-800/30 px-1 rounded font-mono">F3</kbd>
                            <span x-show="heldOrders.length > 0" class="absolute -top-1.5 -right-1.5 w-5 h-5 bg-red-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center held-badge-pulse shadow-sm shadow-red-500/50" x-text="heldOrders.length"></span>
                        </button>
                    </div>
                    <!-- ─── SAVE PROVISIONAL — separate from Pay (no modal, no payment) ─── -->
                    <button @click="saveProvisionalDirect()" :disabled="cart.length === 0 || submitting" class="w-full py-2.5 mb-2 rounded-xl text-sm font-bold text-white bg-amber-500 hover:bg-amber-600 disabled:opacity-30 shadow-md shadow-amber-500/20 transition flex items-center justify-center gap-2">
                        <svg x-show="!submitting" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
                        <svg x-show="submitting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        <span>Save Provisional</span>
                        <kbd class="text-[9px] bg-amber-700/40 px-1.5 py-0.5 rounded font-mono">F9</kbd>
                    </button>
                    <button @click="showPayModal = true" :disabled="cart.length === 0 || submitting" class="pay-btn-premium btn-ripple w-full py-4 rounded-2xl text-base font-extrabold text-white disabled:opacity-30">
                        <span class="flex items-center justify-center gap-2">
                            <svg x-show="submitting" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <svg x-show="!submitting" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            PAY Rs. <span x-text="Number(roundedTotal).toLocaleString()"></span>
                            <kbd x-show="!submitting" class="text-[9px] bg-green-500/30 px-1.5 rounded font-mono">F8</kbd>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         PAY MODAL — Final payment ONLY (Cash / Card → PRA submit).
         Provisional save is now a SEPARATE button + F9 shortcut
         in the right sidebar (no modal, no checkbox, no key conflict).
         ═══════════════════════════════════════════════════════════════ -->
    <div x-show="showPayModal" x-cloak x-transition.opacity x-effect="if (showPayModal) { submitting = false; saveAsProvisional = false; payMethodIndex = 0; }" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showPayModal = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden" x-transition.scale.90>
            <div class="p-5 text-center border-b border-gray-100 dark:border-gray-800">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Payment</h3>
                <p class="text-3xl font-extrabold mt-2 text-purple-600 dark:text-purple-400" x-text="'Rs. ' + Number(roundedTotal).toLocaleString()"></p>
                <p x-show="Math.abs(roundOff) > 0.001" class="text-[10px] text-gray-400 mt-0.5" x-text="(roundOff >= 0 ? 'rounded up by ' : 'rounded down by ') + 'Rs. ' + Math.abs(roundOff).toFixed(2)"></p>
                <p x-show="stockError" class="text-xs text-red-500 mt-2 bg-red-50 dark:bg-red-900/20 p-2 rounded-lg" x-text="stockError"></p>
                <p x-show="submitting" class="text-xs text-purple-500 mt-2">Processing payment...</p>
            </div>
            <div class="p-4 grid grid-cols-2 gap-3">
                <button @click="payMethodIndex = 0; processPayment('cash')" :disabled="submitting" :class="payMethodIndex === 0 ? 'ring-2 ring-green-500 ring-offset-2 dark:ring-offset-gray-900 scale-105 shadow-lg shadow-green-600/20 border-green-400' : ''" class="py-4 rounded-xl text-center border-2 transition disabled:opacity-50 bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 hover:bg-green-100 hover:border-green-400">
                    <svg x-show="submitting" class="w-8 h-8 mx-auto mb-1 animate-spin text-green-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <svg x-show="!submitting" class="w-8 h-8 mx-auto mb-1 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    <span class="text-sm font-bold text-green-700 dark:text-green-400" x-text="submitting ? 'Processing...' : 'Cash'"></span>
                    <span class="block text-[10px] font-semibold mt-0.5 text-green-600/60" x-text="'Tax: ' + (taxRules['cash'] || 16) + '%'"></span>
                    <kbd x-show="!submitting" class="block mt-0.5 text-[9px] font-mono text-green-500/60">Press 1</kbd>
                </button>
                <button @click="payMethodIndex = 1; processPayment('card')" :disabled="submitting" :class="payMethodIndex === 1 ? 'ring-2 ring-blue-500 ring-offset-2 dark:ring-offset-gray-900 scale-105 shadow-lg shadow-blue-600/20 border-blue-400' : ''" class="py-4 rounded-xl text-center border-2 transition disabled:opacity-50 bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800 hover:bg-blue-100 hover:border-blue-400">
                    <svg x-show="submitting" class="w-8 h-8 mx-auto mb-1 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <svg x-show="!submitting" class="w-8 h-8 mx-auto mb-1 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    <span class="text-sm font-bold text-blue-700 dark:text-blue-400" x-text="submitting ? 'Processing...' : 'Card'"></span>
                    <span class="block text-[10px] font-semibold mt-0.5 text-blue-600/60" x-text="'Tax: ' + (taxRules['debit_card'] || taxRules['card'] || 8) + '%'"></span>
                    <kbd x-show="!submitting" class="block mt-0.5 text-[9px] font-mono text-blue-500/60">Press 2</kbd>
                </button>
            </div>
            <div class="px-4 pb-0.5">
                <p class="text-center text-[10px] text-gray-400 dark:text-gray-500 font-medium">Use <kbd class="px-1 font-mono text-gray-500 dark:text-gray-400">&larr;</kbd> <kbd class="px-1 font-mono text-gray-500 dark:text-gray-400">&rarr;</kbd> to choose &middot; <kbd class="px-1 font-mono text-gray-500 dark:text-gray-400">Enter</kbd> to confirm</p>
            </div>
            <div class="p-4 pt-2">
                <button @click="showPayModal = false" :disabled="submitting" class="w-full py-2.5 rounded-xl text-sm font-semibold text-gray-500 hover:text-gray-700 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 transition disabled:opacity-50">Cancel <span class="text-[9px] text-gray-400 font-mono ml-1">ESC</span></button>
            </div>
        </div>
    </div>

    @if($features->tables)
    <div x-show="showTablePicker" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showTablePicker = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md max-h-[70vh] overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Select Table</h3>
                <button @click="showTablePicker = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <div class="p-4 max-h-[50vh] overflow-y-auto grid grid-cols-3 gap-2">
                @foreach($tables as $t)
                <button @click="selectTable({ id: {{ $t->id }}, table_number: '{{ $t->table_number }}', seats: {{ $t->seats }} })" class="py-3 px-2 rounded-xl text-center border-2 transition hover:scale-105 {{ $t->status === 'occupied' ? 'border-red-300 bg-red-50 dark:bg-red-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 hover:border-purple-400' }}">
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
                    @if($t->status === 'occupied')<span class="text-[9px] text-red-500 font-medium">Occupied</span>@endif
                </button>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    <div x-show="showHeldOrders" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showHeldOrders = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[80vh] overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">Held Orders</h3>
                    <p class="text-[10px] text-gray-400 mt-0.5">Arrow keys to navigate • Enter=Recall • P=Pay • D=Delete • ESC=Close</p>
                </div>
                <button @click="showHeldOrders = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <div class="max-h-[60vh] overflow-y-auto">
                <template x-if="heldOrders.length === 0">
                    <div class="p-8 text-center text-gray-400"><p class="text-sm">No held orders</p></div>
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
                                <template x-if="order.priority"><span class="text-[9px] bg-red-100 text-red-600 px-1.5 py-0.5 rounded-full font-bold">RUSH</span></template>
                                <span class="text-xs px-2 py-0.5 rounded-full font-medium" :class="{'bg-amber-100 text-amber-700': order.status==='held', 'bg-blue-100 text-blue-700': order.status==='preparing', 'bg-green-100 text-green-700': order.status==='ready'}" x-text="order.status"></span>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mb-1 ml-7" x-text="'Rs. ' + Number(order.total_amount).toLocaleString() + ' • ' + order.items.length + ' item(s)'"></p>
                        <template x-if="order.table"><p class="text-[10px] text-purple-600 ml-7" x-text="'Table: T-' + order.table.table_number"></p></template>
                        <div class="flex gap-2 mt-2 ml-7">
                            <button @click="recallOrder(order)" class="flex-1 py-2 text-xs font-bold text-purple-600 border border-purple-300 rounded-xl hover:bg-purple-50 transition">Recall</button>
                            @if($features->kot)
                            <a :href="'/pos/restaurant/orders/' + order.id + '/kitchen-ticket'" target="_blank" title="View / print kitchen ticket" class="py-2 px-2 text-xs font-bold text-center text-orange-600 border border-orange-300 rounded-xl hover:bg-orange-50 transition">KOT</a>
                            <button @click="resendKitchen(order)" title="Re-send order to kitchen — the new ticket will be marked UPDATED." class="py-2 px-2 text-xs font-bold text-orange-700 border border-orange-400 rounded-xl bg-orange-50 hover:bg-orange-100 transition">↻ Re-send</button>
                            @endif
                            <button @click="payHeldOrder(order.id)" class="flex-1 py-2 text-xs font-bold text-white bg-green-600 rounded-xl hover:bg-green-700 transition">Pay</button>
                            <button @click="deleteHeldOrder(order.id)" class="py-2 px-3 text-xs font-bold text-red-500 border border-red-300 rounded-xl hover:bg-red-50 transition">Delete</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    {{-- PROVISIONAL BILLS MODAL — opens from header "Local" button (F10).      --}}
    {{-- Lists all bills with pra_status='local' for current company.           --}}
    {{-- Inline actions: Edit (opens edit page) / Delete / Make Final (PRA).    --}}
    {{-- Keyboard: ↑↓ navigate, Enter=Make Final, E=Edit, D=Delete, Esc=Close.  --}}
    {{-- ─────────────────────────────────────────────────────────────────────── --}}
    <div x-show="showLocalBills" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showLocalBills = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between bg-gradient-to-r from-purple-50 to-indigo-50 dark:from-purple-900/20 dark:to-indigo-900/20">
                <div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                        <svg class="w-5 h-5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                        Provisional Bills <span class="text-xs font-medium text-purple-600 ml-1" x-text="'(' + localBills.length + ')'"></span>
                    </h3>
                    <p class="text-[10px] text-gray-500 mt-0.5">Not submitted to PRA yet • ↑↓ navigate • Enter=Make Final • E=Edit • D=Delete • Esc=Close</p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="loadLocalBills()" :disabled="localBillsLoading" class="text-xs text-purple-600 hover:text-purple-800 font-semibold px-2 py-1 rounded hover:bg-purple-100 disabled:opacity-50" title="Refresh list">
                        <svg class="w-4 h-4" :class="localBillsLoading ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                    <button @click="showLocalBills = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
            </div>
            <div class="max-h-[65vh] overflow-y-auto">
                <template x-if="localBillsLoading && localBills.length === 0">
                    <div class="p-12 text-center text-gray-400">
                        <svg class="w-8 h-8 mx-auto mb-2 animate-spin text-purple-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        <p class="text-sm">Loading provisional bills...</p>
                    </div>
                </template>
                <template x-if="!localBillsLoading && localBills.length === 0">
                    <div class="p-12 text-center text-gray-400">
                        <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <p class="text-sm font-medium">No provisional bills</p>
                        <p class="text-[11px] text-gray-400 mt-1">Bills saved as "Provisional" from the Pay modal will appear here.</p>
                    </div>
                </template>
                <template x-for="(bill, bi) in localBills" :key="bill.id">
                    <div class="p-4 border-b border-gray-100 dark:border-gray-800 transition-all" :class="activeLocalIndex === bi ? 'bg-purple-50 dark:bg-purple-900/15 ring-2 ring-purple-400 ring-inset' : ''">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-[10px] font-mono text-gray-400 w-5" x-text="bi + 1"></span>
                                <span class="text-sm font-bold text-gray-900 dark:text-white" x-text="bill.invoice_number"></span>
                                <span class="text-[9px] bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full font-bold uppercase tracking-wide">Local</span>
                                <template x-if="bill.customer_name">
                                    <span class="text-[10px] bg-blue-50 text-blue-600 px-1.5 py-0.5 rounded-full font-medium" x-text="bill.customer_name"></span>
                                </template>
                            </div>
                            <span class="text-sm font-bold text-purple-700 dark:text-purple-400" x-text="'Rs. ' + Number(bill.total_amount).toLocaleString()"></span>
                        </div>
                        <p class="text-[11px] text-gray-500 ml-7 mb-2" x-text="bill.items_count + ' item(s) • ' + bill.created_human"></p>
                        <div class="flex gap-2 ml-7">
                            <a :href="'{{ url('/pos/transaction') }}/' + bill.id + '/edit'" class="flex-1 py-2 text-xs font-bold text-blue-700 border border-blue-300 rounded-xl hover:bg-blue-50 transition text-center flex items-center justify-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                Edit
                            </a>
                            <button @click="deleteProvisional(bill)" class="py-2 px-3 text-xs font-bold text-red-600 border border-red-300 rounded-xl hover:bg-red-50 transition flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V3a1 1 0 011-1h4a1 1 0 011 1v4"/></svg>
                                Delete
                            </button>
                            <button @click="promoteProvisional(bill)" :disabled="!praEnabled" :title="praEnabled ? 'Submit this bill to PRA as final invoice' : 'PRA reporting is disabled — enable from settings'" class="flex-1 py-2 text-xs font-bold text-white bg-gradient-to-br from-purple-600 to-indigo-700 hover:from-purple-700 hover:to-indigo-800 rounded-xl transition shadow-md shadow-purple-600/20 disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                Make Final
                            </button>
                        </div>
                    </div>
                </template>
            </div>
            <div x-show="localBills.length > 0" class="p-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 text-[11px] text-gray-500">
                <span>💡 Provisional bills NOT reported to PRA — edit/delete anytime, or "Make Final" to lock & submit.</span>
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
                        Failed PRA Bills <span class="text-xs font-medium text-red-600 ml-1" x-text="'(' + failedBills.length + ')'"></span>
                    </h3>
                    <p class="text-[10px] text-gray-500 mt-0.5">Need retry • ↑↓ navigate • Enter=Retry • E=Edit • D=Delete • Esc=Close</p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="loadFailedBills()" :disabled="failedBillsLoading" class="text-xs text-red-600 hover:text-red-800 font-semibold px-2 py-1 rounded hover:bg-red-100 disabled:opacity-50" title="Refresh list">
                        <svg class="w-4 h-4" :class="failedBillsLoading ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                    <button @click="showFailedBills = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
            </div>
            <div class="max-h-[65vh] overflow-y-auto">
                <template x-if="failedBillsLoading && failedBills.length === 0">
                    <div class="p-12 text-center text-gray-400">
                        <svg class="w-8 h-8 mx-auto mb-2 animate-spin text-red-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        <p class="text-sm">Loading failed bills...</p>
                    </div>
                </template>
                <template x-if="!failedBillsLoading && failedBills.length === 0">
                    <div class="p-12 text-center text-gray-400">
                        <svg class="w-12 h-12 mx-auto mb-3 text-green-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <p class="text-sm font-medium text-green-600">All bills synced! 🎉</p>
                        <p class="text-[11px] text-gray-400 mt-1">No failed PRA submissions.</p>
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
                        <p class="text-[11px] text-gray-500 ml-7 mb-1" x-text="bill.items_count + ' item(s) • ' + bill.created_human"></p>
                        <template x-if="bill.error_code">
                            <p class="text-[10px] text-red-500 ml-7 mb-2 font-mono truncate" x-text="'⚠ ' + bill.error_code"></p>
                        </template>
                        <div class="flex gap-2 ml-7 mt-2">
                            <a :href="'{{ url('/pos/transaction') }}/' + bill.id + '/edit'" class="flex-1 py-2 text-xs font-bold text-blue-700 border border-blue-300 rounded-xl hover:bg-blue-50 transition text-center flex items-center justify-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                Edit
                            </a>
                            <button @click="deleteFailed(bill)" class="py-2 px-3 text-xs font-bold text-red-600 border border-red-300 rounded-xl hover:bg-red-50 transition flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V3a1 1 0 011-1h4a1 1 0 011 1v4"/></svg>
                                Del
                            </button>
                            <button @click="retryFailed(bill)" :disabled="!praEnabled || bill._retrying" :title="praEnabled ? 'Retry PRA submission' : 'PRA reporting disabled'" class="flex-1 py-2 text-xs font-bold text-white bg-gradient-to-br from-red-600 to-orange-600 hover:from-red-700 hover:to-orange-700 rounded-xl transition shadow-md shadow-red-600/20 disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-1.5">
                                <svg x-show="!bill._retrying" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                <svg x-show="bill._retrying" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                <span x-text="bill._retrying ? 'Retrying...' : 'Retry'"></span>
                            </button>
                        </div>
                    </div>
                </template>
            </div>
            <div x-show="failedBills.length > 0" class="p-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 text-[11px] text-gray-500 flex items-center justify-between">
                <span>💡 These bills haven't reached PRA — fix issues then Retry, or Delete if no longer needed.</span>
                <a href="{{ route('pos.transactions') }}?tab=failed" class="text-red-600 hover:underline font-semibold">Open full page →</a>
            </div>
        </div>
    </div>

    {{-- Legacy popup new-customer modal removed — replaced by inline quick-add form below the phone input (Phase 2 spec: NO popups, INLINE only). --}}

    <div x-show="showCustomerPicker" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showCustomerPicker = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md max-h-[80vh] overflow-hidden" x-transition.scale.90>
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Select Customer</h3>
                <button @click="showCustomerPicker = false" class="text-gray-400 hover:text-gray-600"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <div class="p-3 border-b border-gray-100 dark:border-gray-800">
                <input type="text" x-model="customerSearch" @input="onCustomerPhoneSearch()" placeholder="Search by name or phone..." autocomplete="one-time-code" name="pos_custsearch_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white text-sm px-3 py-2 focus:ring-purple-500">
                <template x-if="customerLookupResult && customerLookupResult.found">
                    <div class="mt-2 p-2.5 bg-green-50 dark:bg-green-900/20 rounded-xl border border-green-200 dark:border-green-800">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-full bg-green-200 dark:bg-green-800 flex items-center justify-center flex-shrink-0"><span class="text-xs font-bold text-green-700" x-text="customerLookupResult.customer.name.charAt(0)"></span></div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-bold text-green-800 dark:text-green-200" x-text="customerLookupResult.customer.name"></p>
                                <p class="text-[10px] text-green-600" x-text="customerLookupResult.stats.total_orders + ' orders • Rs. ' + Number(customerLookupResult.stats.total_spent).toLocaleString() + ' spent'"></p>
                                <template x-if="customerLookupResult.customer.address">
                                    <p class="text-[10px] text-green-500 truncate" x-text="'📍 ' + customerLookupResult.customer.address"></p>
                                </template>
                            </div>
                            <template x-if="customerLookupResult.stats.is_frequent"><span class="freq-badge">VIP</span></template>
                            <button @click="selectLookedUpCustomer()" class="px-3 py-1 text-xs font-bold text-white bg-green-600 rounded-lg flex-shrink-0">Select</button>
                        </div>
                    </div>
                </template>
            </div>
            <div class="max-h-[40vh] overflow-y-auto">
                <button @click="selectedCustomer = null; customerStats = null; showCustomerPicker = false" class="w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition border-b border-gray-100 dark:border-gray-800">
                    <div class="w-9 h-9 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center"><svg class="w-5 h-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg></div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Walk-in Customer</span>
                </button>
                <template x-for="c in filteredCustomers" :key="c.id">
                    <div class="w-full flex items-center gap-3 px-4 py-3 hover:bg-purple-50 dark:hover:bg-purple-900/20 transition border-b border-gray-50 dark:border-gray-800">
                        <button @click="selectCustomerWithStats(c)" class="flex items-center gap-3 flex-1 min-w-0">
                            <div class="w-9 h-9 rounded-full bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center flex-shrink-0"><span class="text-sm font-bold text-purple-600 dark:text-purple-400" x-text="c.name.charAt(0)"></span></div>
                            <div class="text-left min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white truncate" x-text="c.name"></p>
                                <p class="text-xs text-gray-400" x-text="c.phone || 'No phone'"></p>
                                <template x-if="c.address"><p class="text-[10px] text-gray-400 truncate" x-text="'📍 ' + c.address"></p></template>
                            </div>
                        </button>
                        <button @click="loadCustomerHistory(c.id)" class="flex-shrink-0 text-[9px] font-bold text-purple-600 hover:text-purple-800 bg-purple-50 dark:bg-purple-900/30 px-2 py-1 rounded-lg transition" title="View history">
                            <svg class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </button>
                    </div>
                </template>
            </div>
            <div class="p-3 border-t border-gray-200 dark:border-gray-700">
                <div x-show="!showQuickAdd">
                    <button @click="showQuickAdd = true" class="w-full py-2.5 text-sm font-bold text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/20 rounded-xl hover:bg-purple-100 transition">+ Add New Customer</button>
                </div>
                <div x-show="showQuickAdd" class="space-y-2">
                    <input type="text" x-model="quickCustomerName" placeholder="Customer name *" autocomplete="one-time-code" name="pos_quickcust_name_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-purple-500">
                    <input type="text" x-model="quickCustomerPhone" placeholder="Phone *" autocomplete="one-time-code" name="pos_quickcust_phone_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-purple-500">
                    @if($features->delivery)
                    <input type="text" x-model="quickCustomerAddress" placeholder="Address (for delivery)" autocomplete="one-time-code" name="pos_quickcust_addr_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-full rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm px-3 py-2 text-gray-900 dark:text-white focus:ring-purple-500">
                    @endif
                    <div class="flex gap-2">
                        <button @click="showQuickAdd = false" class="flex-1 py-2 text-xs font-semibold text-gray-500 bg-gray-100 dark:bg-gray-800 rounded-xl">Cancel</button>
                        <button @click="addQuickCustomer()" class="flex-1 py-2 text-xs font-bold text-white bg-purple-600 rounded-xl hover:bg-purple-700">Save</button>
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
                        <h3 style="color:white; font-size:16px; font-weight:800; margin:0;">Keyboard Shortcuts</h3>
                        <p style="color:rgba(255,255,255,0.7); font-size:11px; margin:0;">Press F1 anytime to toggle this panel</p>
                    </div>
                </div>
                <button @click="showShortcuts = false" style="width:28px; height:28px; background:rgba(255,255,255,0.15); border:none; border-radius:8px; color:white; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                    <svg style="width:16px; height:16px;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div style="padding:16px 24px 24px;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div>
                        <p style="font-size:10px; font-weight:800; color:#7c3aed; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">Quick Actions</p>
                        <div style="display:flex; flex-direction:column; gap:6px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">Shortcuts Panel</span>
                                <kbd style="background:linear-gradient(135deg,#7c3aed,#6d28d9); color:white; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F1</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">Order Type Cycle</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F2</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">Held Orders</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F3</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">Clear Cart</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F4</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">Hold Order</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F5</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">Jump to Cart</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F6</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">Customer Select</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Alt+P</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">Pay / Checkout</span>
                                <kbd style="background:linear-gradient(135deg,#16a34a,#15803d); color:white; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">F8</kbd>
                            </div>
                        </div>
                    </div>
                    <div>
                        <p style="font-size:10px; font-weight:800; color:#7c3aed; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">Navigation</p>
                        <div style="display:flex; flex-direction:column; gap:6px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">Product Search</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Ctrl+S</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">Edit Cart Mode</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Ctrl+E</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">Customer Field</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Ctrl+C</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">Grid Navigate</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Tab</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">Close / Back</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Esc</kbd>
                            </div>
                        </div>
                        <p style="font-size:10px; font-weight:800; color:#7c3aed; text-transform:uppercase; letter-spacing:1px; margin:14px 0 8px;">Cart Edit Mode</p>
                        <div style="display:flex; flex-direction:column; gap:6px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">Navigate Items</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">&#8593; &#8595;</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">Qty Up / Down</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">+ / -</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">Set Qty Direct</span>
                                <kbd style="background:#e9d5ff; color:#7c3aed; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">0-9</kbd>
                            </div>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 10px; background:#f9fafb; border-radius:8px;" class="dark:bg-gray-800">
                                <span style="font-size:12px; font-weight:600; color:#374151;" class="dark:text-gray-300">Remove Item</span>
                                <kbd style="background:#fecaca; color:#dc2626; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700;">Del</kbd>
                            </div>
                        </div>
                    </div>
                </div>
                <div style="margin-top:16px; padding:10px 14px; background:linear-gradient(135deg,#f3e8ff,#ede9fe); border-radius:10px; display:flex; align-items:center; gap:8px;" class="dark:bg-purple-900/20">
                    <svg style="width:14px; height:14px; color:#7c3aed; flex-shrink:0;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p style="font-size:11px; color:#6b21a8; margin:0; font-weight:500;" class="dark:text-purple-300">Type any letter to start searching products instantly. Payment modal: Press 1 for Cash, 2 for Card.</p>
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
                        <h3 class="text-white text-lg font-extrabold m-0 tracking-tight flex items-center gap-2">Quick Type <span class="text-[9px] font-bold bg-white/20 px-1.5 py-0.5 rounded-md ring-1 ring-white/30 uppercase tracking-wider">F7</span></h3>
                        <p class="text-white/75 text-[11px] m-0 font-medium">Lightning-fast multi-add &mdash; type, parse, drop into cart.</p>
                    </div>
                </div>
                <button @click="showQuickType = false" class="relative w-8 h-8 bg-white/15 hover:bg-white/30 rounded-xl text-white flex items-center justify-center transition-all hover:rotate-90 ring-1 ring-white/20" title="Close (Esc)">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-6 space-y-4 bg-gradient-to-b from-white to-sky-50/30 dark:from-gray-900 dark:to-sky-950/20">
                {{-- Textarea --}}
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-[10px] font-extrabold uppercase tracking-[0.15em] text-sky-700 dark:text-sky-400 flex items-center gap-1.5">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/></svg>
                            Items
                        </label>
                        <span class="text-[10px] text-gray-400 dark:text-gray-500 font-mono" x-show="quickTypeText.length > 0" x-text="(quickTypeText.split(/[,;\n]+/).filter(s=>s.trim()).length) + ' line(s)'"></span>
                    </div>
                    <div class="relative">
                        <textarea x-model="quickTypeText" autocomplete="off" name="pos_quicktype_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore @input="parseQuickTypeText()" @keydown.ctrl.enter.prevent="applyQuickType()" @keydown.meta.enter.prevent="applyQuickType()" x-init="$nextTick(() => $el.focus())" rows="5" placeholder="chai 2&#10;samosa 1&#10;paratha 3&#10;&#10;(or comma-separated: chai 2, samosa 1)" class="w-full text-sm rounded-2xl border-2 border-sky-200 dark:border-sky-800 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-4 py-3 focus:ring-4 focus:ring-sky-500/20 focus:border-sky-500 font-mono leading-relaxed transition-all shadow-sm hover:shadow-md"></textarea>
                    </div>
                    <div class="flex items-center justify-between mt-2 px-1">
                        <p class="text-[10px] text-gray-500 dark:text-gray-400">
                            Format: <code class="bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-300 px-1.5 py-0.5 rounded font-semibold">name qty</code> &middot; <code class="bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-300 px-1.5 py-0.5 rounded font-semibold">qty name</code>
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
                            <span class="font-bold block">Tip</span>
                            Start typing item names &mdash; the parser will fuzzy-match against your products in real time. No qty? Defaults to 1.
                            <template x-if="!isInventoryEnabled()">
                                <span class="block mt-1 text-amber-700 dark:text-amber-400">Unmatched items? Inline price input dega &mdash; type Rs. and add as a manual line.</span>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Preview list with check / x icons + subtotal --}}
                <template x-if="quickTypeParsed.length > 0">
                    <div class="rounded-2xl border border-sky-200 dark:border-sky-800 bg-gradient-to-br from-sky-50 to-blue-50/50 dark:from-sky-950/30 dark:to-blue-950/20 overflow-hidden shadow-sm">
                        <div class="flex items-center justify-between px-4 py-2.5 bg-white/60 dark:bg-black/20 border-b border-sky-200/60 dark:border-sky-800/60">
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] font-extrabold uppercase tracking-[0.15em] text-sky-700 dark:text-sky-400">Preview</span>
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
                                    <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300" :title="'Type a price for each unmatched line to add as a manual item'">
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
                                        <div class="w-6 h-6 rounded-full bg-gradient-to-br from-emerald-400 to-green-500 flex items-center justify-center flex-shrink-0 shadow-sm shadow-emerald-500/30">
                                            <svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                        </div>
                                    </template>
                                    {{-- Unmatched icon: amber "+" when inventory OFF (manual entry possible), red "×" when inventory ON. --}}
                                    <template x-if="!p.match && !isInventoryEnabled()">
                                        <div class="w-6 h-6 rounded-full bg-amber-100 dark:bg-amber-900/40 flex items-center justify-center flex-shrink-0" title="Manual entry — type a price">
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
                                            <input type="number" x-model="p.manualPrice" min="0" step="any" placeholder="price" class="w-20 text-[11px] font-mono font-bold text-right rounded-md border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200 px-2 py-0.5 focus:ring-2 focus:ring-amber-400 focus:border-amber-500 outline-none" @keydown.enter.prevent="$event.target.blur()" @click.stop />
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
                    <button @click="addRandomProduct()" :disabled="(!allProducts || allProducts.length === 0) && (!allServices || allServices.length === 0)" class="group flex-1 min-w-[160px] px-4 py-3 rounded-2xl bg-gradient-to-br from-amber-400 via-orange-500 to-orange-600 hover:from-amber-500 hover:via-orange-600 hover:to-orange-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-bold transition-all flex items-center justify-center gap-2 shadow-lg shadow-orange-500/30 hover:shadow-xl hover:shadow-orange-500/40 hover:-translate-y-0.5 active:translate-y-0">
                        <svg class="w-4 h-4 transition-transform group-hover:rotate-180 duration-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Random Product
                    </button>
                    <button @click="applyQuickType()" :disabled="quickTypeParsed.filter(p => p.match).length === 0 && (isInventoryEnabled() || quickTypeParsed.filter(p => !p.match && parseFloat(p.manualPrice) > 0).length === 0)" class="flex-1 min-w-[160px] px-4 py-3 rounded-2xl bg-gradient-to-br from-sky-500 via-sky-600 to-blue-700 hover:from-sky-600 hover:via-sky-700 hover:to-blue-800 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-bold transition-all flex items-center justify-center gap-2 shadow-lg shadow-sky-500/30 hover:shadow-xl hover:shadow-sky-500/40 hover:-translate-y-0.5 active:translate-y-0">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        Add to Cart
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
                        <h3 class="text-white text-lg font-extrabold m-0 tracking-tight">Manual Item</h3>
                        <p class="text-white/75 text-[11px] m-0 font-medium">Bill ke liye ad-hoc product add karein.</p>
                    </div>
                </div>
                <button @click="showManualItem = false" class="relative w-8 h-8 bg-white/15 hover:bg-white/30 rounded-xl text-white flex items-center justify-center transition-all hover:rotate-90 ring-1 ring-white/20" title="Close (Esc)">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form @submit.prevent="addManualItem()" class="p-6 space-y-4 bg-gradient-to-b from-white to-emerald-50/30 dark:from-gray-900 dark:to-emerald-950/20">
                {{-- Name --}}
                <div>
                    <label for="manualItemNameInput" class="text-[10px] font-extrabold uppercase tracking-[0.15em] text-emerald-700 dark:text-emerald-400 flex items-center gap-1.5 mb-2">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        Item Name
                    </label>
                    <input id="manualItemNameInput" x-model="manualItemName" type="text" required maxlength="255" placeholder="e.g. Special Order, Custom Service" autocomplete="off" name="pos_manualitem_name_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                        class="w-full text-sm rounded-2xl border-2 border-emerald-200 dark:border-emerald-800 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-4 py-2.5 focus:outline-none focus:ring-4 focus:ring-emerald-500/20 focus:border-emerald-500 transition-all shadow-sm hover:shadow-md">
                </div>

                {{-- Price --}}
                <div>
                    <label for="manualItemPriceInput" class="text-[10px] font-extrabold uppercase tracking-[0.15em] text-emerald-700 dark:text-emerald-400 flex items-center gap-1.5 mb-2">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/></svg>
                        Unit Price (Rs.)
                    </label>
                    <input id="manualItemPriceInput" x-model="manualItemPrice" type="number" required min="0" step="0.01" placeholder="0.00"
                        class="w-full text-sm rounded-2xl border-2 border-emerald-200 dark:border-emerald-800 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-4 py-2.5 focus:outline-none focus:ring-4 focus:ring-emerald-500/20 focus:border-emerald-500 font-mono font-bold transition-all shadow-sm hover:shadow-md">
                    <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1.5 px-1 italic">
                        Quantity aur tax cart se adjust kar sakte ho. Tax payment-method per auto (5% Card / 16% Cash / Exempt).
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
                            Future ke liye Products mein bhi save karein
                            <span class="text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300">Optional</span>
                        </div>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5 leading-snug">
                            Tick karne se yeh item permanently <span class="font-semibold text-emerald-700 dark:text-emerald-400">"Quick"</span> category mein /pos/products mein save ho jaaye ga &mdash; agli baar search mein bhi mile ga.
                        </p>
                    </div>
                </label>

                {{-- Actions --}}
                <div class="flex flex-wrap gap-2 pt-1">
                    <button type="button" @click="showManualItem = false" :disabled="manualItemSubmitting" class="px-4 py-3 rounded-2xl text-xs font-bold text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition disabled:opacity-50">
                        Cancel
                    </button>
                    <button type="submit" :disabled="manualItemSubmitting || !manualItemName.trim() || manualItemPrice === '' || parseFloat(manualItemPrice) < 0" class="flex-1 px-4 py-3 rounded-2xl bg-gradient-to-br from-emerald-500 via-emerald-600 to-teal-700 hover:from-emerald-600 hover:via-emerald-700 hover:to-teal-800 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-bold transition-all flex items-center justify-center gap-2 shadow-lg shadow-emerald-500/30 hover:shadow-xl hover:shadow-emerald-500/40 hover:-translate-y-0.5 active:translate-y-0">
                        <svg x-show="manualItemSubmitting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        <svg x-show="!manualItemSubmitting" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        <span x-text="manualItemSubmitting ? 'Adding...' : 'Add to Cart'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Persistent Receipt Modal — Esc + backdrop-click are intentionally NOT bound here so       --}}
    {{-- the cashier doesn't dismiss the popup by accident while reading totals or printing.        --}}
    {{-- Esc on this popup belongs to the browser print dialog (closes that, not our popup).        --}}
    {{-- Popup closes ONLY via: X (top-right cross), Close button, or "New Sale" button.            --}}
    <div x-show="showReceipt" x-cloak x-transition.opacity x-effect="if (!showReceipt) cancelPendingPrints()" class="fixed inset-0 bg-gradient-to-br from-green-900/80 via-black/70 to-emerald-900/80 backdrop-blur-md z-50 flex items-center justify-center p-4">
        <div class="receipt-modal-enter relative bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col" style="max-height:92vh;" x-transition.scale.90>
            {{-- Top-right cross (primary close action) --}}
            <button @click="showReceipt = false" class="absolute top-3 right-3 z-10 w-8 h-8 rounded-full bg-white/80 dark:bg-gray-800/80 hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white flex items-center justify-center transition shadow-sm" title="Close popup">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>

            <div class="relative px-6 pt-7 pb-6 text-center overflow-hidden bg-gradient-to-b from-emerald-50 via-green-50 to-white dark:from-emerald-900/30 dark:via-green-900/10 dark:to-gray-900 flex-shrink-0" id="confettiContainer">
                {{-- soft glow behind the success icon --}}
                {{-- Animated success ring with a pulsing halo --}}
                <div class="relative w-20 h-20 mx-auto mb-3">
                    <span class="absolute inset-0 rounded-full bg-emerald-400/30 animate-ping"></span>
                    <div class="relative w-20 h-20 rounded-full bg-gradient-to-br from-green-400 to-emerald-600 flex items-center justify-center shadow-xl shadow-emerald-600/40 success-icon-animate" style="animation: scaleIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1)">
                        <svg class="w-10 h-10 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-dasharray="24" stroke-dashoffset="0" style="animation: checkDraw 0.5s ease 0.3s both;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    </div>
                </div>
                <h3 class="relative text-2xl font-black text-gray-900 dark:text-white tracking-tight">Payment Complete!</h3>
                {{-- PRA fiscal status — the "production" proof the cashier needs to see at a glance --}}
                <div class="relative mt-2.5 flex items-center justify-center">
                    <span class="inline-flex items-center gap-1.5 text-[11px] font-extrabold uppercase tracking-wider px-3 py-1 rounded-full shadow-sm"
                          :class="lastPraStatus === 'submitted' ? 'bg-emerald-600 text-white shadow-emerald-600/30' : (lastPraStatus === 'pending' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' : ((lastPraStatus === 'offline' || lastPraStatus === 'failed') ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'))">
                        <svg x-show="lastPraStatus === 'submitted'" class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.42l-7.5 7.5a1 1 0 01-1.42 0l-3.5-3.5a1 1 0 111.42-1.42l2.79 2.8 6.79-6.8a1 1 0 011.42 0z" clip-rule="evenodd"/></svg>
                        <svg x-show="lastPraStatus === 'pending'" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        <span x-text="lastPraStatus === 'submitted' ? 'PRA Verified' : (lastPraStatus === 'pending' ? 'Reporting to PRA' : ((lastPraStatus === 'offline' || lastPraStatus === 'failed') ? 'Saved · will sync to PRA' : 'Local Bill'))"></span>
                    </span>
                </div>
                {{-- Big total --}}
                <p class="relative mt-3 text-4xl font-black text-emerald-600 dark:text-emerald-400 tracking-tight" x-text="'Rs. ' + Number(lastTotal).toLocaleString()" style="font-variant-numeric: tabular-nums;"></p>
                {{-- PRA fiscal invoice number — shown only once PRA returns it (real "production" number) --}}
                <div x-show="lastPraNumber" class="relative mt-3 mx-auto max-w-xs py-2 px-3 rounded-xl bg-emerald-600/10 border border-emerald-500/30">
                    <p class="text-[9px] font-bold uppercase tracking-widest text-emerald-700/70 dark:text-emerald-400/70">PRA Invoice Number</p>
                    <div class="flex items-center justify-center gap-2 mt-0.5">
                        <p class="text-sm font-extrabold font-mono text-emerald-800 dark:text-emerald-300 break-all" x-text="lastPraNumber"></p>
                        <button type="button"
                            @click="if(navigator.clipboard){navigator.clipboard.writeText(lastPraNumber).then(()=>{ praCopied=true; showToast('PRA number copied','success'); setTimeout(()=>praCopied=false,1500); }).catch(()=>showToast('Copy failed','error'));}else{showToast('Copy not supported on this device','error');}"
                            class="shrink-0 w-7 h-7 rounded-lg bg-emerald-600/15 hover:bg-emerald-600/30 text-emerald-700 dark:text-emerald-300 flex items-center justify-center transition" :title="praCopied ? 'Copied!' : 'Copy PRA number'">
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
                {{-- Sale meta: time + item count (item count auto-hides when unknown) --}}
                <div class="relative flex items-center justify-center gap-2.5 mt-2 text-[10px] font-semibold text-gray-400 dark:text-gray-500">
                    <span class="inline-flex items-center gap-1" x-show="lastSaleAt">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span x-text="lastSaleAt ? new Date(lastSaleAt).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) : ''"></span>
                    </span>
                    <span x-show="lastSaleAt && lastItemsCount > 0" class="w-1 h-1 rounded-full bg-gray-300 dark:bg-gray-600"></span>
                    <span class="inline-flex items-center gap-1" x-show="lastItemsCount > 0">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                        <span x-text="lastItemsCount + (lastItemsCount === 1 ? ' item' : ' items')"></span>
                    </span>
                </div>
            </div>
            <div class="flex-1 overflow-hidden bg-gray-50 dark:bg-gray-800/50 min-h-0" style="max-height: 45vh;">
                <iframe x-ref="receiptIframe" class="w-full h-full border-0" :src="lastTransactionId ? (isRestaurantMode ? '/pos/restaurant/receipt/' + lastTransactionId : '/pos/transaction/' + lastTransactionId + '/receipt') : ''" style="min-height:300px;"></iframe>
            </div>
            {{-- Persistent action bar: Print | KOT | New Sale | Close. Print/KOT fire prints      --}}
            {{-- but popup STAYS OPEN so cashier can verify, reprint, or take other actions.       --}}
            <div class="p-3 bg-white dark:bg-gray-900 border-t border-gray-100 dark:border-gray-800 flex-shrink-0">
                <div class="grid grid-cols-4 gap-2">
                    {{-- 1. Print Receipt (P) --}}
                    <button @click="printReceipt()" :disabled="!lastTransactionId" class="py-3 text-center rounded-xl bg-gradient-to-br from-purple-600 to-violet-700 hover:from-purple-700 hover:to-violet-800 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-bold transition shadow-md shadow-purple-600/20 flex items-center justify-center gap-1.5" title="Print customer receipt">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                        Print <kbd class="text-[8px] bg-purple-500/40 px-1 rounded font-mono">P</kbd>
                    </button>
                    {{-- 2. KOT (K) - shown only when an orderId exists (restaurant flow) + admin allows reprint --}}
                    @if(($company->kot_reprint_enabled ?? true))
                    <button x-show="lastOrderId" @click="printKitchenTicket()" :disabled="!lastOrderId" class="py-3 text-center rounded-xl bg-gradient-to-br from-orange-500 to-amber-600 hover:from-orange-600 hover:to-amber-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-bold transition shadow-md shadow-orange-500/20 flex items-center justify-center gap-1.5" title="Print Kitchen Order Ticket">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                        KOT <kbd class="text-[8px] bg-orange-500/40 px-1 rounded font-mono">K</kbd>
                    </button>
                    {{-- Spacer when KOT hidden so grid stays balanced --}}
                    <div x-show="!lastOrderId"></div>
                    @else
                    {{-- Reprint disabled by admin — keep grid cell balanced --}}
                    <div></div>
                    @endif
                    {{-- 3. New Sale (Enter) --}}
                    <button @click="startNewAfterPayment()" class="py-3 text-center rounded-xl bg-gradient-to-br from-green-600 to-emerald-700 hover:from-green-700 hover:to-emerald-800 text-white text-sm font-bold transition shadow-md shadow-green-600/20 flex items-center justify-center gap-1.5" title="Clear cart & start a new sale">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                        New <kbd class="text-[8px] bg-green-500/40 px-1 rounded font-mono">↵</kbd>
                    </button>
                    {{-- 4. Close popup (mouse only - Esc no longer bound to keep print dialog Esc clean) --}}
                    <button @click="showReceipt = false" class="py-3 text-center rounded-xl bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-600 dark:text-gray-300 text-sm font-semibold transition flex items-center justify-center gap-1.5" title="Close this popup (does not start new sale)">
                        Close
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
                <h3 class="text-lg font-extrabold text-gray-900 dark:text-white">Manager Override</h3>
                <p class="text-xs text-gray-500 mt-1">Enter manager PIN to unlock full discount</p>
            </div>
            <div class="px-5 pb-5 space-y-3">
                <input type="password" x-model="managerPin" autocomplete="one-time-code" name="pos_managerpin_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore @keydown.enter="submitManagerPin()" maxlength="6" placeholder="Enter PIN" class="w-full text-center text-2xl tracking-[0.5em] bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 text-gray-900 dark:text-white focus:ring-blue-500 focus:border-blue-500" autofocus>
                <p x-show="managerPinError" class="text-xs text-red-500 text-center" x-text="managerPinError"></p>
                <div class="flex gap-2">
                    <button @click="showManagerPinModal = false" class="flex-1 py-2.5 text-sm font-semibold text-gray-600 bg-gray-100 dark:bg-gray-800 dark:text-gray-400 rounded-xl hover:bg-gray-200 transition">Cancel</button>
                    <button @click="submitManagerPin()" class="flex-1 py-2.5 text-sm font-bold text-white bg-blue-600 rounded-xl hover:bg-blue-700 transition">Verify</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Customer History Modal --}}
    <div x-show="showCustomerHistory" x-cloak x-transition.opacity class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md max-h-[80vh] overflow-hidden flex flex-col" @click.outside="showCustomerHistory = false">
            <div class="p-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-extrabold text-gray-900 dark:text-white">Customer History</h3>
                    <p class="text-xs text-gray-500" x-text="customerHistory?.customer_name || ''"></p>
                </div>
                <button @click="showCustomerHistory = false" class="p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto p-4 space-y-4">
                <template x-if="loadingCustomerHistory">
                    <div class="text-center py-8"><div class="w-6 h-6 border-2 border-purple-600 border-t-transparent rounded-full animate-spin mx-auto"></div><p class="text-xs text-gray-400 mt-2">Loading...</p></div>
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
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">Favorites</p>
                                <div class="flex flex-wrap gap-1.5">
                                    <template x-for="fav in customerHistory.favorites" :key="fav.name">
                                        <span class="text-[10px] px-2 py-1 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 rounded-lg font-medium" x-text="fav.name + ' (' + fav.count + 'x)'"></span>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <template x-if="customerHistory.recent_orders && customerHistory.recent_orders.length > 0">
                            <div>
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-2">Recent Orders</p>
                                <div class="space-y-2">
                                    <template x-for="ord in customerHistory.recent_orders" :key="ord.id">
                                        <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-xl">
                                            <div class="flex items-center justify-between mb-1.5">
                                                <span class="text-xs font-bold text-gray-900 dark:text-white" x-text="ord.order_number"></span>
                                                <span class="text-[10px] text-gray-400" x-text="ord.date"></span>
                                            </div>
                                            <div class="text-[10px] text-gray-500 mb-2" x-text="ord.items.map(i => i.qty + 'x ' + i.name).join(', ')"></div>
                                            <div class="flex items-center justify-between">
                                                <span class="text-xs font-bold text-purple-600" x-text="'Rs. ' + Number(ord.total).toLocaleString()"></span>
                                                <button @click="reorderItems(ord)" class="text-[10px] font-bold text-white bg-purple-600 hover:bg-purple-700 px-2.5 py-1 rounded-lg transition">Reorder</button>
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

    {{-- Smart Upsell — non-blocking floating card, bottom-right.
         Enter = accept · Esc = skip · auto-dismiss after 8s · session memory --}}
    <div x-show="currentUpsell" x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-4"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-4"
         class="fixed bottom-4 right-4 z-40 w-[300px]" style="display:none">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl border border-purple-200 dark:border-purple-800 overflow-hidden ring-2 ring-purple-500/20">
            <div class="px-3 py-2 bg-gradient-to-r from-purple-600 to-indigo-600 text-white flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <span class="text-[11px] font-bold uppercase tracking-wider">Suggested Add-on</span>
                <button @click="dismissUpsell(true)" class="ml-auto text-white/80 hover:text-white" aria-label="Skip">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-3">
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">
                    Add <span class="font-semibold text-purple-600 dark:text-purple-400" x-text="currentUpsell?.suggest?.name"></span> with this?
                </p>
                <p class="text-[10px] text-gray-400 dark:text-gray-500 mb-3">
                    Goes great with <span x-text="currentUpsell?.trigger?.name"></span>
                </p>
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-bold tabular-nums text-gray-900 dark:text-white">
                        Rs. <span x-text="Number(currentUpsell?.suggest?.price || 0).toLocaleString()"></span>
                    </span>
                    <div class="flex items-center gap-1.5">
                        <button @click="dismissUpsell(true)" class="px-3 py-1.5 text-[11px] font-semibold text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 rounded-lg transition">
                            Skip <span class="text-[9px] opacity-60 ml-0.5">Esc</span>
                        </button>
                        <button @click="acceptUpsell()" class="px-3 py-1.5 text-[11px] font-bold text-white bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 rounded-lg transition shadow-md shadow-purple-500/30">
                            + Add <span class="text-[9px] opacity-80 ml-0.5">⏎</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Low Stock Alert Popup — strictly gated by isInventoryEnabled().
         Even if some downstream code flips showLowStockPopup, this guard keeps it hidden. --}}
    <div x-show="isInventoryEnabled() && showLowStockPopup && lowStockAlerts.length > 0" x-cloak x-transition.opacity class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden" @click.outside="showLowStockPopup = false">
            <div class="p-4 bg-amber-50 dark:bg-amber-900/20 border-b border-amber-200 dark:border-amber-800 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-amber-900 dark:text-amber-200">Low Stock Warning</h3>
                    <p class="text-[10px] text-amber-700 dark:text-amber-400" x-text="lowStockAlerts.length + ' ingredient(s) running low'"></p>
                </div>
            </div>
            <div class="max-h-[40vh] overflow-y-auto p-3 space-y-1.5">
                <template x-for="alert in lowStockAlerts" :key="alert.name">
                    <div class="flex items-center justify-between px-3 py-2 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <div>
                            <p class="text-xs font-semibold text-gray-900 dark:text-white" x-text="alert.name"></p>
                            <p class="text-[10px] text-gray-400" x-text="'Min: ' + alert.min_stock_level + ' ' + alert.unit"></p>
                        </div>
                        <span class="text-xs font-bold" :class="parseFloat(alert.current_stock) <= 0 ? 'text-red-600' : 'text-amber-600'" x-text="alert.current_stock + ' ' + alert.unit"></span>
                    </div>
                </template>
            </div>
            <div class="p-3 border-t border-gray-100 dark:border-gray-800">
                <button @click="showLowStockPopup = false" class="w-full py-2.5 text-sm font-bold text-amber-700 bg-amber-50 dark:bg-amber-900/20 dark:text-amber-400 rounded-xl hover:bg-amber-100 transition">Dismiss</button>
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
$productsJson = $products->map(function($p) use ($recipeLookup, $stockStatus) {
    return [
        'id' => $p->id, 'type' => 'product', 'name' => $p->name,
        'price' => $p->price ?? 0, 'category' => $p->category,
        'show_on_sale' => (bool)($p->show_on_sale ?? true),
        'cost_price' => (float) ($p->cost_price ?? 0),
        'is_tax_exempt' => $p->is_tax_exempt ?? false,
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
        'hasRecipe' => false, 'image' => null, 'stockStatus' => null,
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
function restaurantPos() {
    return {
        allProducts: {!! $jsEnc($productsJson) !!},
        allServices: {!! $jsEnc($servicesJson) !!},
        allCustomers: {!! $jsEnc($customersJson) !!},
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
        showPayModal: false,
        // payMethodIndex — which method is highlighted in the Pay modal (0 = Cash,
        // 1 = Card). Arrow keys move it, Enter confirms the highlighted one, and
        // number keys 1/2 jump + fire directly. Reset to 0 each time the modal opens.
        payMethodIndex: 0,
        // PROVISIONAL BILL FLOW — when true, the Pay modal saves the bill with
        // pra_status='local' (no PRA submission). Bill stays editable/deletable
        // and can be promoted to final later via the "Submit to PRA — Make Final"
        // button on transaction-show. Toggle key: P (while pay modal is open).
        saveAsProvisional: false,
        showHeldOrders: false,
        // ── PRA REPORTING TOGGLE (root scope so modals/buttons can read it) ───
        // Mirrors $company->pra_reporting_enabled. Used by Provisional/Failed bill
        // action buttons (:disabled="!praEnabled"). Was previously defined only in
        // a nested x-data on the toggle strip → caused "praEnabled is not defined"
        // Alpine crashes inside the modals which broke the whole page (incl. Pay).
        praEnabled: {{ ($company->pra_reporting_enabled ?? false) ? 'true' : 'false' }},
        praLoading: false,
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
        // Lazy-loaded list of all bills with pra_status='local' for current company.
        // Refreshed on page mount, after every bill save, and after each modal action.
        localBills: [],
        showLocalBills: false,
        activeLocalIndex: 0,
        localBillsLoading: false,
        // ── FAILED BILLS (header shortcut, F11) ───────────────────────────────
        // Lazy-loaded list of all bills with pra_status IN (failed,offline,pending)
        // that have NOT received a pra_invoice_number yet. Auto-refresh on mount.
        failedBills: [],
        showFailedBills: false,
        activeFailedIndex: 0,
        failedBillsLoading: false,
        // ── AUTO-SYNC ENGINE ──────────────────────────────────────────────
        // syncStatus: 'online' | 'syncing' | 'offline'
        // _syncTimer fires every 30 sec; pings count endpoint then silently
        // retries one bill per tick (no PRA hammering on long outages).
        // _autoSyncBusy = re-entrancy guard.
        syncStatus: navigator.onLine ? 'online' : 'offline',
        _syncTimer: null,
        _autoSyncBusy: false,
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
        // Phase 5+ — Auto-print kitchen ticket on successful sale (mirrors companies.auto_print_kot)
        autoKotEnabled: {{ ($company->auto_print_kot ?? false) ? 'true' : 'false' }},
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
        submitting: false,
        cartAnimating: false,
        stockError: '',
        mobileView: 'menu',
        priorityOrder: false,
        recalledOrderId: null,
        toast: { show: false, message: '', type: 'success' },
        lastHoldTime: 0,
        lastPayTime: 0,
        showDiscount: false,
        discountType: 'percentage',
        discountValue: 0,
        discountAmount: 0,

        get filteredCustomers() {
            const q = this.customerSearch.toLowerCase();
            if (!q) return this.allCustomers;
            return this.allCustomers.filter(c => c.name.toLowerCase().includes(q) || (c.phone && c.phone.includes(q)));
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
        get taxAmount() { return this.r2(this.taxableSubtotal * this.taxRate / 100); },
        get totalAmount() { return Math.max(0, this.r2(this.effectiveSubtotal - this.discountAmount + this.taxAmount)); },
        get roundedTotal() { return Math.round(this.totalAmount); },
        get roundOff() { return this.r2(this.roundedTotal - this.totalAmount); },
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

        init() {
            if (this._inited) return;
            this._inited = true;
            // Honor the saved "hide products" preference ONLY in inventory-OFF mode.
            // Inventory mode must always show the catalog (no manual on-the-fly create).
            try { if (!this.isInventoryEnabled() && localStorage.getItem('pos_show_products') === '0') this.showProducts = false; } catch (e) {}
            this.filterProducts();
            setTimeout(() => { this.loading = false; }, 300);
            this.$watch('activeCategory', () => { this.filterProducts(); this.gridFocusIndex = 0; });
            this.calcGridCols();
            window.addEventListener('resize', () => this.calcGridCols());
            // Cart auto-restore is intentionally disabled — every page load starts with an EMPTY cart.
            // Saved cart is written to localStorage as a safety net only (debounced 400ms — see saveCart).
            // Do NOT call this.restoreCart() here without explicit product approval.
            this.$watch('cart', () => { this.saveCart(); this.recalcDiscount(); }, { deep: true });
            this.$watch('kitchenNotes', () => { this.saveCart(); });
            setTimeout(() => this.cacheProductData(), 800);
            document.addEventListener('keydown', (e) => this.handleKey(e));
            this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); });
            // Lazy-load provisional bill list on mount (for header badge count).
            // Failures are silent — badge just won't show until next refresh.
            setTimeout(() => this.loadLocalBills(), 1200);
            setTimeout(() => this.loadFailedBills(), 1500);
            // 🔄 Auto-Sync — kicks in after 4 sec, then every 30 sec.
            // Live-updates online/offline pill + silently retries pending bills.
            setTimeout(() => this._startAutoSync(), 4000);
        },

        // ─── AUTO-SYNC ENGINE ──────────────────────────────────────────────
        // Browser-side companion to the SyncPosOfflineInvoicesJob (cron).
        // Every 30 sec: refresh online/offline state, count pending bills,
        // and silently retry the OLDEST one. One bill per tick = no PRA flood.
        _startAutoSync() {
            if (this._syncTimer) return;
            window.addEventListener('online', () => { this.syncStatus = 'online'; this._autoSyncTick(true); });
            window.addEventListener('offline', () => { this.syncStatus = 'offline'; });
            this._autoSyncTick();
            this._syncTimer = setInterval(() => this._autoSyncTick(), 30000);
        },
        async _autoSyncTick(force = false) {
            if (this._autoSyncBusy) return;
            // Respect navigator.onLine but don't trust it 100% — we still try
            // a lightweight count fetch which doubles as a connectivity probe.
            if (!navigator.onLine) { this.syncStatus = 'offline'; return; }
            this._autoSyncBusy = true;
            try {
                // Lightweight refresh of pending count (also serves as ping).
                await this.loadFailedBills();
                if (!this.praEnabled) { this.syncStatus = 'online'; this._autoSyncBusy = false; return; }
                if (this.failedBills.length === 0) { this.syncStatus = 'online'; this._autoSyncBusy = false; return; }
                // Pick OLDEST not-currently-retrying bill and submit silently.
                const candidate = [...this.failedBills].reverse().find(b => !b._retrying);
                if (!candidate) { this.syncStatus = 'online'; this._autoSyncBusy = false; return; }
                this.syncStatus = 'syncing';
                candidate._retrying = true;
                const res = await fetch('{{ url('/pos/api/failed-bills') }}/' + candidate.id + '/retry', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                });
                const data = await res.json().catch(() => ({}));
                if (data && data.success) {
                    this.failedBills = this.failedBills.filter(b => b.id !== candidate.id);
                    // Mini toast — non-intrusive (existing showToast auto-dismisses).
                    this.showToast('🔄 Auto-synced ' + (candidate.invoice_number || '#' + candidate.id) + ' to PRA', 'success');
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
        guidedTypeLabel(k) { return ({ dine_in: 'Dine In', takeaway: 'Takeaway', delivery: 'Delivery' })[k] || k; },
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
            const newIdx = this.gridFocusIndex + delta;
            if (newIdx >= 0 && newIdx < this.displayItems.length) { this.gridFocusIndex = newIdx; this.scrollGridItemIntoView(newIdx); }
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
            this.showToast('Added: ' + item.name, 'success');
        },

        getCartQty(item) {
            const found = this.cart.find(c => c.item_id === item.id && c.item_type === item.type);
            return found ? found.quantity : 0;
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
                    const all = [...this.allProducts, ...this.allServices];
                    const out = [];
                    for (let i = 0; i < all.length && out.length < 12; i++) {
                        const it = all[i];
                        if (!it.name || !(parseFloat(it.price) > 0)) continue;
                        if (it.name.toLowerCase().includes(q)) out.push(it);
                    }
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
            if (this.showSearchDropdown && this.searchSuggestions.length > 0) { this.quickAddItem(this.searchSuggestions[this.highlightIndex]); return; }
            // No catalog match: in SIMPLE (inventory-OFF) mode, Enter creates the typed item on the fly.
            if (!this.isInventoryEnabled() && this.searchQuery.trim().length > 0 && !this.quickCreating) { this.quickCreateProduct(); return; }
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
            let items = [...this.allProducts, ...this.allServices];
            items = items.filter(i => parseFloat(i.price) > 0 && i.name && i.name.trim().length > 0);
            // A SEARCH query always spans the WHOLE catalog: it ignores the active category
            // (the pills may be hidden, or a stale category chosen earlier would hide a match in
            // another category) AND it includes products marked "Hidden from sale screen"
            // (show_on_sale=false). The hidden flag ONLY declutters the browsable grid — it must
            // never stop a cashier from finding a saved product by name and adding it to the cart.
            const ignoreCategory = hasSearch;
            if (!ignoreCategory) {
                if (this.activeCategory !== 'all' && this.activeCategory !== 'services') { items = this.allProducts.filter(p => p.category === this.activeCategory && parseFloat(p.price) > 0 && p.name && p.name.trim().length > 0); }
                else if (this.activeCategory === 'services') { items = this.allServices.filter(s => parseFloat(s.price) > 0 && s.name && s.name.trim().length > 0); }
            }
            // Hidden products stay OUT of the browsable grid (when NOT searching) but remain fully
            // searchable above — so only drop show_on_sale=false items when there is no search.
            if (!hasSearch) { items = items.filter(i => i.show_on_sale !== false); }
            if (this.searchQuery) { const q = this.searchQuery.toLowerCase(); items = items.filter(i => i.name.toLowerCase().includes(q)); }
            this.filteredItems = items;
            this.displayCount = 60;
            this.updateDisplayItems();
        },

        updateDisplayItems() {
            this.displayItems = this.filteredItems.slice(0, this.displayCount);
        },

        // MASTER toggle — show/hide the saved products catalog on the sale screen.
        // Persisted per-browser (localStorage). When OFF, cashiers bill via manual entry only.
        toggleShowProducts() {
            // Inventory mode requires the catalog — toggle is a no-op (force ON) there.
            if (this.isInventoryEnabled()) { this.showProducts = true; return; }
            this.showProducts = !this.showProducts;
            try { localStorage.setItem('pos_show_products', this.showProducts ? '1' : '0'); } catch (e) {}
            this.filterProducts();
            // Search still works when the grid is hidden — keep suggestions live if a query is active.
            if (this.searchQuery && this.searchQuery.trim().length > 0) { this.onSearchInput(); }
            else { this.searchSuggestions = []; this.showSearchDropdown = false; }
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
                this.cart.push({ cart_uid: 'c' + Date.now() + '_' + Math.random().toString(36).slice(2,9), item_id: item.id, item_type: item.type, item_name: item.name, quantity: 1, unit_price: parseFloat(item.price), special_notes: '', is_tax_exempt: item.is_tax_exempt || false, item_discount_type: 'percentage', item_discount_value: 0, showItemDiscount: false });
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
                window.tnNotify && window.tnNotify('Manual Item', 'Inventory mode mein allowed nahi.');
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
                window.tnNotify && window.tnNotify('Manual Item', 'Naam zaroori hai.');
                return;
            }
            if (priceRaw === '' || isNaN(price) || price < 0) {
                window.tnNotify && window.tnNotify('Manual Item', 'Sahi price likhein.');
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
                        is_tax_exempt: false, hasRecipe: false, stockStatus: null,
                    });
                    this.addToCart({
                        id: p.id, type: 'product', name: p.name,
                        price: parseFloat(p.price) || 0, is_tax_exempt: false,
                    });
                    window.tnNotify && window.tnNotify('Saved & Added', p.name);
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
                        item_discount_type: 'percentage',
                        item_discount_value: 0,
                        showItemDiscount: false,
                    });
                    window.tnNotify && window.tnNotify('Manual Added', name + ' — Rs. ' + price.toLocaleString());
                }
                this.showManualItem = false;
                this.manualItemName = '';
                this.manualItemPrice = '';
                this.manualItemSavePermanent = false;
            } catch (e) {
                window.tnNotify && window.tnNotify('Error', e.message || 'Save failed');
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
                this.showToast(inv ? 'No items matched — check spelling' : 'Type names ya unmatched lines ke prices fill karein', 'error');
                return;
            }
            let added = 0, skipped = 0, manualAdded = 0;
            matched.forEach(p => {
                // Honour the same stock-out gate as handleProductClick — Quick Type
                // must NOT bypass blockOutOfStock on inventory-enabled companies.
                if (inv && p.match.stockStatus === 'out' && this.blockOutOfStock) { skipped++; return; }
                const item = { id: p.match.id, type: p.match._type || p.match.type || 'product', name: p.match.name, price: p.match.price, is_tax_exempt: p.match.is_tax_exempt };
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
                this.showToast('All matched items are out of stock', 'error');
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
            if (pool.length === 0) { this.showToast('No products available', 'error'); return; }
            const pick = pool[Math.floor(Math.random() * pool.length)];
            this.addToCart({ id: pick.id, type: pick._type || pick.type || 'product', name: pick.name, price: pick.price, is_tax_exempt: pick.is_tax_exempt });
            this.showToast('Random: ' + pick.name, 'success');
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
                        suggest: { id: match.id, type: match.type || 'product', name: match.name, price: match.price, is_tax_exempt: match.is_tax_exempt || false }
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
            this.addToCart({ id: s.id, type: s.type, name: s.name, price: s.price, is_tax_exempt: s.is_tax_exempt });
            this.showToast('Added: ' + s.name, 'success');
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
                    this.showToast(data.error || 'Could not create', 'error');
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
                this.showToast('Network error', 'error');
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
            if (e.key === 'F1') { e.preventDefault(); this.showShortcuts = !this.showShortcuts; return; }
            if (e.key === 'F2') { e.preventDefault(); this.cartMode = false; this.activeCartIndex = -1; this.enterSearchMode(); return; }
            if (e.key === 'F3') { e.preventDefault(); this.activeHeldIndex = 0; this.showHeldOrders = true; return; }
            if (e.key === 'F4') { e.preventDefault(); if (this.cart.length && confirm('Clear entire cart?')) { this.clearCart(); } return; }
            if (e.key === 'F5') { e.preventDefault(); this.holdOrder(); return; }
            if (e.key === 'F6') { e.preventDefault(); if (this.cart.length > 0) { this.enterCartMode('last'); this.mobileView = 'cart'; } return; }
            // F7 → Quick Type (was customer-phone-focus, moved to Alt+P)
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
            //       (b) target is search input AND searchQuery is empty, OR
            //       (c) target is qty input (handled at element level too)
            //   This way "tea", "tomato" etc still work when user has started typing.
            // Always operates on activeCartIndex if valid, else on the LAST cart row.
            // ═══════════════════════════════════════════════════════════════
            if ((e.key === 't' || e.key === 'T' || e.code === 'KeyT') && !e.ctrlKey && !e.metaKey) {
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
                } else if (isSearchInput && !this.searchQuery) {
                    shouldToggle = true; // search empty — T is free for shortcut
                } else if (!isSearchInput && !isCustPhone && !isOtherInput) {
                    shouldToggle = true; // body / non-input
                }

                if (shouldToggle) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (this.cart.length === 0) { this.showToast('Cart is empty', 'warning'); return; }
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
                if (this.showPayModal || this.showReceipt || this.showHeldOrders || this.showQuickType || this.showManualItem || this.showCustomerPicker || this.showShortcuts || this.showManagerPinModal || this.showLocalBills || this.showFailedBills) return;
                this.openLocalBills();
                return;
            }
            // F11 — Open Failed Bills modal (PRA submissions that need retry).
            // Same gating as F10. Browser's native F11 = fullscreen toggle is overridden.
            if (e.key === 'F11') {
                e.preventDefault();
                if (this.showPayModal || this.showReceipt || this.showHeldOrders || this.showQuickType || this.showManualItem || this.showCustomerPicker || this.showShortcuts || this.showManagerPinModal || this.showLocalBills || this.showFailedBills) return;
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
                && !this.showHeldOrders && !this.showLocalBills && !this.showFailedBills
                && !this.showPayModal && !this.showReceipt && !this.showQuickType
                && !this.showManualItem && !this.showCustomerPicker && !this.showShortcuts
                && !this.showManagerPinModal) {
                const tgt = e.target;
                const isSearchInput = tgt && tgt === this.$refs.searchInput;
                const isCustPhone   = tgt && tgt === this.$refs.customerPhoneInput;
                const isOtherInput  = tgt && tgt.closest && tgt.closest('input, textarea, select') && !isSearchInput && !isCustPhone;
                let shouldToggle = false;
                if (e.altKey) shouldToggle = true;
                else if (isSearchInput && !this.searchQuery) shouldToggle = true;
                else if (!isSearchInput && !isCustPhone && !isOtherInput) shouldToggle = true;
                if (shouldToggle) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (this.cart.length === 0) { this.showToast('Cart is empty', 'warning'); return; }
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
                && !this.showHeldOrders && !this.showLocalBills && !this.showFailedBills
                && !this.showPayModal && !this.showReceipt && !this.showQuickType
                && !this.showManualItem && !this.showCustomerPicker && !this.showShortcuts
                && !this.showManagerPinModal) {
                const tgt = e.target;
                const isSearchInput = tgt && tgt === this.$refs.searchInput;
                const isCustPhone   = tgt && tgt === this.$refs.customerPhoneInput;
                const isQtyInput    = tgt && tgt.closest && tgt.closest('[data-qty-row]');
                const isOtherInput  = tgt && tgt.closest && tgt.closest('input, textarea, select') && !isSearchInput && !isCustPhone && !isQtyInput;
                let shouldFocus = false;
                if (e.altKey) shouldFocus = true;
                else if (isSearchInput && !this.searchQuery) shouldFocus = true;
                else if (isQtyInput) shouldFocus = true;
                else if (!isSearchInput && !isCustPhone && !isOtherInput) shouldFocus = true;
                if (shouldFocus) {
                    e.preventDefault();
                    e.stopPropagation();
                    this.$nextTick(() => {
                        const el = this.$refs.orderNotesInput;
                        if (el) {
                            el.focus();
                            el.select && el.select();
                            this.showToast('Order notes — type & press Enter', 'info');
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
                // Esc closes the success popup (per cashier feedback — mouse use was needed).
                // If a browser print dialog is on top, Esc closes that first (native) — second Esc
                // reaches us and dismisses the popup.
                if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); this.showReceipt = false; return; }
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.startNewAfterPayment(); return; }
                if (e.key === 'p' || e.key === 'P') { e.preventDefault(); this.printReceipt(); return; }
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
            if (this.showLocalBills && this.localBills.length > 0) {
                if (e.key === 'ArrowDown') { e.preventDefault(); this.activeLocalIndex = Math.min(this.activeLocalIndex + 1, this.localBills.length - 1); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); this.activeLocalIndex = Math.max(this.activeLocalIndex - 1, 0); }
                else if (e.key === 'Enter') { e.preventDefault(); this.promoteProvisional(this.localBills[this.activeLocalIndex]); }
                else if (e.key === 'e' || e.key === 'E') { e.preventDefault(); window.location.href = '{{ url('/pos/transaction') }}/' + this.localBills[this.activeLocalIndex].id + '/edit'; }
                else if (e.key === 'd' || e.key === 'D') { e.preventDefault(); this.deleteProvisional(this.localBills[this.activeLocalIndex]); }
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
                else if (e.key === 'e' || e.key === 'E') { e.preventDefault(); window.location.href = '{{ url('/pos/transaction') }}/' + this.failedBills[this.activeFailedIndex].id + '/edit'; }
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

        clearCart() { this.cart = []; this.kitchenNotes = ''; this.selectedTable = null; this.selectedCustomer = null; this.customerStats = null; this.customerPhoneQuery = ''; this.customerPhoneResults = []; this.customerPhoneDropdown = false; this.stockError = ''; this.priorityOrder = false; this.recalledOrderId = null; this.discountType = 'percentage'; this.discountValue = 0; this.discountAmount = 0; this.showDiscount = false; this.managerOverrideActive = false; this.activeCartIndex = -1; this.cartMode = false; this.flowStep = 'customer'; this.fixCartIndex(); this.clearCartStorage(); },
        newSale() {
            if (this.cart.length > 0) { if (!confirm('Current order has ' + this.cart.length + ' item(s). Discard and start new sale?')) return; }
            this.clearCart(); this.showToast('New sale started', 'success');
        },
        voidOrder() {
            if (this.cart.length === 0) return;
            if (!confirm('Void current order? All items will be removed.')) return;
            this.clearCart(); this.showToast('Order voided', 'success');
        },
        selectTable(table) { this.selectedTable = table; this.orderType = 'dine_in'; this.showTablePicker = false; },

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
            const phone = this.customerSearch.trim();
            if (phone.length >= 4 && /^\d+$/.test(phone)) {
                this.customerLookupTimer = setTimeout(() => this.lookupCustomerByPhone(phone), 400);
            } else {
                this.customerLookupResult = null;
            }
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
            this.showToast('Customer: ' + c.name + (this.customerStats.is_frequent ? ' (VIP)' : ''), 'success');
        },

        async selectCustomerWithStats(c) {
            this.selectedCustomer = c;
            this.customerStats = null;
            this.customerPhoneQuery = c.name + (c.phone ? " · " + c.phone : "");
            this.showCustomerPicker = false;
            this.showToast('Customer: ' + c.name, 'success');
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
            if (q.length >= 3) {
                this.customerPhoneTimer = setTimeout(() => this.searchCustomerByPhone(q), 300);
            } else {
                this.customerPhoneResults = [];
                this.customerPhoneDropdown = false;
            }
        },

        async searchCustomerByPhone(q) {
            this.customerSearching = true;
            try {
                const res = await fetch('/pos/restaurant/api/customer-search?q=' + encodeURIComponent(q));
                const data = await res.json();
                this.customerPhoneResults = data.customers || [];
                // Always show dropdown so the inline "add new" hint can appear when results === 0
                this.customerPhoneDropdown = true;
            } catch(e) { this.customerPhoneResults = []; this.customerPhoneDropdown = false; }
            finally { this.customerSearching = false; }
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
                // Re-entrancy guard: a second Enter while the search is in flight would
                // start a duplicate fetch and re-open (and wipe) the new-customer form.
                if (this.customerSearching) return;
                const validPhone = q.length >= 4 && /^\d+$/.test(q);
                if (validPhone && this.customerPhoneResults.length === 0) {
                    // Debounced search may still be in flight — resolve it NOW so we don't
                    // open the new-customer form for a customer who actually exists.
                    if (this.customerPhoneTimer) { clearTimeout(this.customerPhoneTimer); this.customerPhoneTimer = null; }
                    await this.searchCustomerByPhone(q);
                }
                if (this.customerPhoneResults.length > 0) {
                    this.selectCustomerFromPhone(this.customerPhoneResults[0]);
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
                this.selectCustomerFromPhone(this.customerPhoneResults[0]);
            } else if (q.length >= 4 && /^\d+$/.test(q)) {
                this.openInlineNewCustomer();
            } else {
                this.showToast('Enter a valid mobile number', 'error');
            }
        },

        openInlineNewCustomer() {
            const q = this.customerPhoneQuery.trim();
            if (q.length < 4 || !/^\d+$/.test(q)) { this.showToast('Enter a valid mobile number', 'error'); return; }
            this.newCustomerPhone = q;
            this.newCustomerName = '';
            this.newCustomerAddress = '';
            this.showNewCustomerInline = true;
            this.customerPhoneDropdown = true;
            this.$nextTick(() => this.$refs.newCustomerNameInput?.focus());
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
            this.showToast('Customer: ' + cr.name + (cr.stats && cr.stats.is_frequent ? ' (VIP)' : ''), 'success');
            this.$nextTick(() => { this.$refs.searchInput?.focus(); });
        },

        async saveNewCustomer() {
            if (this.savingCustomer) return;
            const name = this.newCustomerName.trim();
            if (!name) { this.showToast('Customer name is required', 'error'); this.$refs.newCustomerNameInput?.focus(); return; }
            this.savingCustomer = true;
            try {
                const res = await fetch('{{ route("pos.restaurant.customer-store") }}', {
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
                    this.showToast('New customer: ' + data.customer.name, 'success');
                    this.$nextTick(() => { this.$refs.searchInput?.focus(); });
                } else { this.showToast(data.message || 'Failed to save customer', 'error'); }
            } catch(e) { this.showToast('Network error', 'error'); }
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
                this.showToast('Manual items billing-only — pay first or remove them to hold.', 'error');
                return null;
            }
            const now = Date.now();
            if (now - this.lastHoldTime < 2000) return null;
            this.lastHoldTime = now;
            this.submitting = true;
            let result = null;
            try {
                const res = await fetch('{{ route("pos.restaurant.orders.hold") }}', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ items: this.cart, order_type: this.orderType, table_id: this.selectedTable?.id || null, customer_id: this.selectedCustomer?.id || null, customer_name: this.selectedCustomer?.name || null, customer_phone: this.selectedCustomer?.phone || null, kitchen_notes: this.kitchenNotes, priority: this.priorityOrder, recalled_order_id: this.recalledOrderId, discount_type: this.discountAmount > 0 ? this.discountType : null, discount_value: this.discountAmount > 0 ? this.discountValue : 0, discount_amount: this.discountAmount }),
                });
                const data = await res.json();
                if (data.success) {
                    const successMsg = opts.successMessage || data.message;
                    this.showToast(successMsg, 'success'); this.heldOrders.unshift(data.order); this.clearCart();
                    this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); });
                    // Auto-print KOT when print_on_hold is enabled, OR when the caller explicitly asked
                    // (e.g. "Send to Kitchen" button always prints a ticket).
                    if (this.kitchenSettings.print_on_hold || opts.forcePrintKot) {
                        window.open('/pos/restaurant/orders/' + data.order.id + '/kitchen-ticket?auto_print=1', '_blank', 'width=380,height=620');
                    }
                    result = data;
                } else { this.showToast(data.message || 'Failed', 'error'); }
            } catch (e) { this.showToast('Network error', 'error'); }
            this.submitting = false;
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
            try {
                const res = await fetch('/pos/restaurant/orders/' + order.id + '/resend-kitchen', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                });
                const data = await res.json();
                if (data.success) {
                    this.showToast('Re-sent to kitchen (#' + data.kot_print_count + ')', 'success');
                    window.open('/pos/restaurant/orders/' + order.id + '/kitchen-ticket?auto_print=1', '_blank', 'width=380,height=620');
                } else {
                    this.showToast(data.message || 'Re-send failed', 'error');
                }
            } catch (e) {
                this.showToast('Network error', 'error');
            }
        },

        // ─── SAVE PROVISIONAL DIRECT — fully isolated from Pay modal ─────
        // Sets provisional flag + uses default 'cash' method, then routes
        // through the existing processPayment pipeline. No modal opens, no
        // keyboard conflict, no checkbox confusion. User can later edit /
        // delete / promote-to-final from F10 (Local) shortcut.
        async saveProvisionalDirect() {
            if (this.submitting) return;
            if (this.cart.length === 0) { this.showToast('Cart is empty', 'error'); return; }
            this.saveAsProvisional = true;
            this.showPayModal = false;
            await this.processPayment('cash');
        },

        async processPayment(method) {
            if (this.submitting) return;
            // Capture provisional flag once at submission start so a stray
            // re-render/checkbox toggle mid-flight cannot flip the path.
            const provisional = !!this.saveAsProvisional;

            if (this.payingHeldOrderId) {
                this.submitting = true; this.stockError = '';
                await this.payHeldOrderDirect(this.payingHeldOrderId, method, null, provisional);
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
            if (!this.isRestaurantMode || this.hasManualItems()) {
                return await this.processPaymentManual(method, provisional);
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
                if (!holdData.success) { this.showToast(holdData.message || 'Failed', 'error'); this.submitting = false; return; }
                const savedTotal = this.totalAmount;
                await this.payHeldOrderDirect(holdData.order.id, method, savedTotal, provisional);
                this.clearCart();
                // Auto-focus phone input → ready for next sale, NO dead focus.
                this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); });
            } catch (e) {
                console.error('[processPayment] FAIL', e);
                this.showToast('Submit failed: ' + (e?.message || e?.name || 'unknown') + ' — check console (F12)', 'error');
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
        async processPaymentManual(method, provisional = false) {
            const now = Date.now();
            if (now - this.lastPayTime < 3000) return;
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
                        type: c.item_type === 'service' ? 'service' : 'product',
                        item_id: c.item_id || null,
                        is_tax_exempt: !!c.is_tax_exempt,
                        special_notes: c.special_notes || null,
                        // Flag manual cart lines so the backend doesn't auto-
                        // create a permanent product for them.
                        _manual: (c.item_type === 'manual' || !c.item_id) ? true : false,
                    })),
                    payment_method: method,
                    discount_type: this.discountType || 'percentage',
                    discount_value: this.discountAmount > 0 ? this.discountValue : 0,
                    customer_name: this.selectedCustomer?.name || null,
                    customer_phone: this.selectedCustomer?.phone || null,
                    kitchen_notes: this.kitchenNotes,
                    // PROVISIONAL BILL FLOW — when true, storeInvoice forces
                    // pra_status='local' regardless of company.pra_reporting_enabled
                    // and skips PRA submission. Bill stays editable / deletable.
                    save_as_provisional: !!provisional,
                };
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
                let rawBody = '';
                try { rawBody = await res.text(); data = JSON.parse(rawBody); } catch(_) {}
                if (!res.ok || !data || !data.success) {
                    console.error('[storeInvoice] HTTP', res.status, res.statusText, rawBody.slice(0, 500));
                    const msg = (data && (data.message || data.error)) || ('Failed (HTTP ' + res.status + ') — F12 console');
                    this.showToast(msg, 'error');
                    this.submitting = false;
                    return;
                }
                // Mirror payHeldOrderDirect success path so receipt modal works.
                console.log('[storeInvoice] OK response:', data);
                console.log('[storeInvoice] isRestaurantMode=', this.isRestaurantMode, 'transaction_id=', data.transaction_id);
                this.lastInvoiceNumber = data.invoice_number || '';
                this.lastTransactionId = data.transaction_id || null;
                this.lastOrderId = null; // no restaurant order for manual carts
                this.lastTotal = savedTotal || data.total_amount || 0;
                this.lastPaymentMethod = method;
                this.lastPraNumber = data.pra_invoice_number || '';
                this.lastPraStatus = data.pra_status || '';
                this.lastItemsCount = (this.cart || []).reduce((s, i) => s + (parseFloat(i.quantity) || 0), 0);
                this.lastSaleAt = Date.now();
                this.showReceipt = true;
                this.scheduleReceiptAutoClose();
                this.$nextTick(() => { setTimeout(() => this.triggerConfetti(), 300); });
                // Auto-print receipt for manual-cart bills too (parity with held-order pay).
                // Manual carts don't have a restaurant order so KOT is a no-op — receipt only.
                this.runAutoPrintChain(null);
                this.clearCart();
                this.$nextTick(() => { this.$refs.customerPhoneInput?.focus(); });
                // Refresh provisional badge count if this save was provisional.
                if (provisional) { this.loadLocalBills(); }
                // Refresh failed badge — successful sales might leave a previous fail intact.
                this.loadFailedBills();
            } catch (e) {
                console.error('[processPaymentManual] FAIL', e);
                this.showToast('Manual pay failed: ' + (e?.message || e?.name || 'unknown') + ' — F12 console', 'error');
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

        printReceipt(onAfterPrint) {
            if (!this.lastTransactionId) { if (typeof onAfterPrint === 'function') onAfterPrint(); return; }
            const url = (this.isRestaurantMode ? '/pos/restaurant/receipt/' : '/pos/transaction/') + this.lastTransactionId + (this.isRestaurantMode ? '?auto_print=1' : '/receipt?auto_print=1');
            console.log('[printReceipt] URL=', url, 'isRestaurantMode=', this.isRestaurantMode);
            this._printViaIframe('print-receipt-frame', url, 'width=400,height=700', onAfterPrint);
        },

        // Silent KOT print via hidden iframe — no popup window blocks the cashier screen.
        printKitchenTicket(orderId, onAfterPrint) {
            const id = orderId || this.lastOrderId;
            if (!id) { if (typeof onAfterPrint === 'function') onAfterPrint(); return; }
            const url = '/pos/restaurant/orders/' + id + '/kitchen-ticket?auto_print=1';
            this._printViaIframe('print-kot-frame', url, 'width=350,height=600', onAfterPrint);
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
        runAutoPrintChain(orderId) {
            // MASTER GATE — auto-print OFF means NOTHING fires automatically.
            if (!this.autoPrintEnabled) return;
            const hasReceipt = !!this.lastTransactionId;
            const wantsKot = !!this.autoKotEnabled && !!orderId;
            const wantsReceipt = hasReceipt;
            if (!wantsReceipt && !wantsKot) return;
            this.$nextTick(() => {
                if (wantsReceipt && wantsKot) {
                    this.queuePrintTimer(() => {
                        this.printReceipt(() => {
                            this.queuePrintTimer(() => this.printKitchenTicket(orderId), 80);
                        });
                    }, 150);
                } else if (wantsReceipt) {
                    this.queuePrintTimer(() => this.printReceipt(), 150);
                } else if (wantsKot) {
                    // Pathological case: no transaction (so no receipt possible) but KOT requested.
                    this.queuePrintTimer(() => this.printKitchenTicket(orderId), 150);
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
                    if (this.activeLocalIndex >= this.localBills.length) {
                        this.activeLocalIndex = Math.max(0, this.localBills.length - 1);
                    }
                }
            } catch (e) { console.warn('loadLocalBills error', e); }
            this.localBillsLoading = false;
        },
        openLocalBills() {
            this.activeLocalIndex = 0;
            this.showLocalBills = true;
            this.loadLocalBills();
        },
        async deleteProvisional(bill) {
            if (!bill) return;
            if (!confirm('Delete provisional bill ' + (bill.invoice_number || '#' + bill.id) + '?\nThis cannot be undone.')) return;
            try {
                const res = await fetch('{{ url('/pos/api/provisional-bills') }}/' + bill.id + '/delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                });
                const data = await res.json();
                if (data && data.success) {
                    this.localBills = this.localBills.filter(b => b.id !== bill.id);
                    if (this.activeLocalIndex >= this.localBills.length) this.activeLocalIndex = Math.max(0, this.localBills.length - 1);
                    if (this.localBills.length === 0) { this.showLocalBills = false; this.activeLocalIndex = 0; }
                    this.showToast('Provisional bill deleted', 'success');
                } else {
                    this.showToast((data && data.message) || 'Delete failed', 'error');
                }
            } catch (e) { console.error('deleteProvisional', e); this.showToast('Network error', 'error'); }
        },
        async promoteProvisional(bill) {
            if (!bill) return;
            if (!this.praEnabled) { this.showToast('PRA reporting is disabled — enable from settings', 'error'); return; }
            if (!confirm('Submit bill ' + (bill.invoice_number || '#' + bill.id) + ' to PRA as a FINAL invoice?\n\nOnce reported, the bill will be locked — no more edit or delete. Continue?')) return;
            try {
                const res = await fetch('{{ url('/pos/api/provisional-bills') }}/' + bill.id + '/promote', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                });
                const data = await res.json();
                if (data && data.success) {
                    // Remove from list (no longer provisional) regardless of submitted vs queued.
                    this.localBills = this.localBills.filter(b => b.id !== bill.id);
                    if (this.activeLocalIndex >= this.localBills.length) this.activeLocalIndex = Math.max(0, this.localBills.length - 1);
                    if (this.localBills.length === 0) { this.showLocalBills = false; this.activeLocalIndex = 0; }
                    this.showToast(data.message || 'Submitted to PRA', 'success');
                } else {
                    // Failed — refresh list so cashier sees current state.
                    this.showToast((data && data.message) || 'Submit failed', 'error');
                    this.loadLocalBills();
                }
            } catch (e) { console.error('promoteProvisional', e); this.showToast('Network error', 'error'); this.loadLocalBills(); }
        },

        // ─── FAILED BILLS API helpers (F11 modal) ───────────────────────────
        async loadFailedBills() {
            this.failedBillsLoading = true;
            try {
                const res = await fetch('{{ route('pos.api.failed-bills') }}', {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                });
                if (!res.ok) { this.failedBillsLoading = false; return; }
                const data = await res.json();
                if (data && data.success) {
                    this.failedBills = data.bills || [];
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
            if (!this.praEnabled) { this.showToast('PRA reporting is disabled', 'error'); return; }
            if (bill._retrying) return;
            bill._retrying = true;
            try {
                const res = await fetch('{{ url('/pos/api/failed-bills') }}/' + bill.id + '/retry', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                });
                const data = await res.json();
                if (data && data.success) {
                    this.failedBills = this.failedBills.filter(b => b.id !== bill.id);
                    if (this.activeFailedIndex >= this.failedBills.length) this.activeFailedIndex = Math.max(0, this.failedBills.length - 1);
                    if (this.failedBills.length === 0) { this.showFailedBills = false; this.activeFailedIndex = 0; }
                    this.showToast(data.message || 'Submitted to PRA', 'success');
                } else {
                    bill._retrying = false;
                    this.showToast((data && data.message) || 'Retry failed', 'error');
                    this.loadFailedBills();
                }
            } catch (e) { bill._retrying = false; console.error('retryFailed', e); this.showToast('Network error', 'error'); this.loadFailedBills(); }
        },
        async deleteFailed(bill) {
            if (!bill) return;
            if (!confirm('Delete failed bill ' + (bill.invoice_number || '#' + bill.id) + '?\n\nThis will permanently remove it. Use only if the bill should NOT be sent to PRA.')) return;
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
                    this.showToast('Failed bill deleted', 'success');
                } else {
                    this.showToast('Delete failed (Error ' + res.status + ')', 'error');
                }
            } catch (e) { console.error('deleteFailed', e); this.showToast('Network error', 'error'); }
        },

        async deleteHeldOrder(orderId) {
            // Find order for friendlier confirm prompt
            const ord = this.heldOrders.find(o => o.id === orderId);
            const label = ord ? (ord.order_number || '#' + orderId) : '#' + orderId;
            // SAFETY: prevent accidental clicks / stray "D" key from blowing away a held order.
            // Without this, after delete the modal stayed open and the next Enter would recall
            // the neighbouring order — looked exactly like "delete pe order aa gaya".
            if (!confirm('Delete held order ' + label + '?\nThis cannot be undone.')) return;
            try {
                const res = await fetch(`/pos/restaurant/orders/${orderId}/delete`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' } });
                if (!res.ok) { this.showToast('Failed to delete order (Error ' + res.status + ')', 'error'); return; }
                const data = await res.json();
                if (data.success) {
                    this.heldOrders = this.heldOrders.filter(o => o.id !== orderId);
                    if (this.activeHeldIndex >= this.heldOrders.length) this.activeHeldIndex = Math.max(0, this.heldOrders.length - 1);
                    // Auto-close the modal once the list is empty, otherwise the next
                    // Enter keystroke would land on a phantom selection.
                    if (this.heldOrders.length === 0) { this.showHeldOrders = false; this.activeHeldIndex = 0; }
                    this.showToast('Order deleted', 'success');
                } else { this.showToast(data.message || 'Failed', 'error'); }
            } catch (e) { console.error('Delete held order error:', e); this.showToast('Error deleting order', 'error'); }
        },

        async payHeldOrderDirect(orderId, method, savedTotal, provisional = false) {
            try {
                // PROVISIONAL BILL FLOW — when true, RestaurantPosController::payOrder
                // forces pra_status='local' and skips PRA submission. Bill remains
                // editable / deletable until promoted via "Submit to PRA — Make Final".
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
                    this.lastPraNumber = data.pra_invoice_number || ''; this.lastPraStatus = data.pra_status || '';
                    this.lastItemsCount = (this.cart || []).reduce((s, i) => s + (parseFloat(i.quantity) || 0), 0);
                    this.lastSaleAt = Date.now();
                    this.showReceipt = true;
                    this.scheduleReceiptAutoClose();
                    this.$nextTick(() => { setTimeout(() => this.triggerConfetti(), 300); });
                    // Print order: INVOICE FIRST → KOT AFTER. Cashier-requested sequence.
                    // Uses postMessage-chained engine — KOT never fires before the receipt
                    // print dialog is dismissed (was a race in the old setTimeout(200/1800) impl
                    // on slow networks where KOT iframe loaded before receipt iframe).
                    this.runAutoPrintChain(orderId);
                    // Refresh provisional badge count when this save was provisional.
                    if (provisional) { this.loadLocalBills(); }
                    // Refresh failed badge so cashier sees pending/failed state in real time.
                    this.loadFailedBills();
                } else { if (data.stock_error) { this.stockError = data.message; this.showPayModal = true; } this.showToast(data.message || 'Payment failed', 'error'); }
            } catch (e) {
                console.error('[payHeldOrderDirect] FAIL', e);
                this.showToast('Payment error: ' + (e?.message || e?.name || 'unknown') + ' — F12 console', 'error');
            }
        },

        // Persistent receipt popup — auto-dismiss disabled. Popup stays open until the cashier
        // explicitly closes via X / Close / New Sale buttons. Functions kept as no-ops so any
        // legacy call-sites continue to work without throwing.
        scheduleReceiptAutoClose() {
            if (this.receiptAutoCloseTimer) { clearTimeout(this.receiptAutoCloseTimer); this.receiptAutoCloseTimer = null; }
        },

        cancelReceiptAutoClose() {
            if (this.receiptAutoCloseTimer) { clearTimeout(this.receiptAutoCloseTimer); this.receiptAutoCloseTimer = null; }
        },

        recallOrder(order) {
            if (this.cart.length > 0 && !confirm('Current cart has items. Replace with recalled order?')) return;
            this.cart = order.items.map(i => ({ cart_uid: 'c' + Date.now() + '_' + Math.random().toString(36).slice(2,9), item_id: i.item_id, item_type: i.item_type, item_name: i.item_name, quantity: parseFloat(i.quantity), unit_price: parseFloat(i.unit_price), special_notes: i.special_notes || '', is_tax_exempt: i.is_tax_exempt || false, item_discount_type: i.item_discount_type || 'percentage', item_discount_value: parseFloat(i.item_discount_value) || 0, showItemDiscount: parseFloat(i.item_discount_value) > 0 }));
            this.kitchenNotes = order.kitchen_notes || '';
            this.recalledOrderId = order.id;
            this.priorityOrder = order.priority || false;
            if (order.discount_type && parseFloat(order.discount_value) > 0) { this.discountType = order.discount_type; this.discountValue = parseFloat(order.discount_value) || 0; this.showDiscount = true; } else { this.discountType = 'percentage'; this.discountValue = 0; this.discountAmount = 0; this.showDiscount = false; }
            if (order.table) { this.selectedTable = { id: order.table.id, table_number: order.table.table_number }; this.orderType = 'dine_in'; }
            this.selectedCustomer = order.customer_id ? { id: order.customer_id, name: order.customer_name || 'Customer', phone: order.customer_phone || '' } : null;
            this.customerPhoneQuery = this.selectedCustomer ? (this.selectedCustomer.phone || this.selectedCustomer.name) : '';
            this.heldOrders = this.heldOrders.filter(o => o.id !== order.id); this.showHeldOrders = false; this.showToast('Order recalled for editing', 'success');
        },

        async addQuickCustomer() {
            if (!this.quickCustomerName.trim() || !this.quickCustomerPhone.trim()) {
                this.showToast('Name and phone are required', 'error'); return;
            }
            try {
                const res = await fetch('{{ route("pos.restaurant.customer-store") }}', {
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
                    this.showToast(data.existing ? 'Customer found: ' + cust.name : 'Customer added: ' + cust.name, 'success');
                } else { this.showToast(data.message || 'Failed', 'error'); }
            } catch (e) { this.showToast('Error adding customer', 'error'); }
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
            if (!this.hasManagerPin) { this.showToast('Manager PIN not configured', 'error'); return; }
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
                    this.showToast('Manager override granted', 'success');
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
                    this.cart.push({ cart_uid: 'c' + Date.now() + '_' + Math.random().toString(36).slice(2,9), item_id: item.item_id, item_type: item.item_type, item_name: item.name, quantity: item.qty, unit_price: item.price, special_notes: '', is_tax_exempt: false, item_discount_type: 'percentage', item_discount_value: 0, showItemDiscount: false });
                }
            }
            this.showCustomerHistory = false; this.showToast('Items added to cart', 'success');
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
</x-pos-layout>
