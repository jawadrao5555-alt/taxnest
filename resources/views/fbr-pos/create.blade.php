<x-fbr-pos-layout>
<style>
/* ═══════════════════════════════════════════════
   🌌 PREMIUM AMBIENT BACKGROUND
   ═══════════════════════════════════════════════ */
.fbr-pos-stage {
    position: relative;
    isolation: isolate;
}
.fbr-pos-stage::before {
    content: '';
    position: fixed;
    inset: -10%;
    z-index: -2;
    background:
        radial-gradient(ellipse 60% 40% at 12% 8%, rgba(59,130,246,0.10), transparent 60%),
        radial-gradient(ellipse 50% 35% at 88% 12%, rgba(139,92,246,0.09), transparent 60%),
        radial-gradient(ellipse 70% 45% at 50% 100%, rgba(16,185,129,0.07), transparent 60%);
    pointer-events: none;
    animation: ambientShift 18s ease-in-out infinite alternate;
}
.dark .fbr-pos-stage::before {
    background:
        radial-gradient(ellipse 60% 40% at 12% 8%, rgba(59,130,246,0.18), transparent 60%),
        radial-gradient(ellipse 50% 35% at 88% 12%, rgba(139,92,246,0.16), transparent 60%),
        radial-gradient(ellipse 70% 45% at 50% 100%, rgba(16,185,129,0.12), transparent 60%);
}
.fbr-pos-stage::after {
    content: '';
    position: fixed;
    inset: 0;
    z-index: -1;
    background-image:
        linear-gradient(rgba(148,163,184,0.06) 1px, transparent 1px),
        linear-gradient(90deg, rgba(148,163,184,0.06) 1px, transparent 1px);
    background-size: 32px 32px;
    mask-image: radial-gradient(ellipse 80% 60% at 50% 30%, black, transparent 90%);
    -webkit-mask-image: radial-gradient(ellipse 80% 60% at 50% 30%, black, transparent 90%);
    pointer-events: none;
}
@keyframes ambientShift {
    0%   { transform: translate(0,0) rotate(0deg); }
    50%  { transform: translate(-2%, 1%) rotate(0.5deg); }
    100% { transform: translate(2%, -1%) rotate(-0.5deg); }
}

/* ═══════════════════════════════════════════════
   🎨 ANIMATED STICKY BANNER
   ═══════════════════════════════════════════════ */
.sticky-banner {
    will-change: transform;
    backface-visibility: hidden;
    background-size: 200% 200% !important;
    animation: bannerHueShift 14s ease-in-out infinite;
    position: relative;
    overflow: hidden;
}
.sticky-banner::before {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(115deg, transparent 30%, rgba(255,255,255,0.08) 50%, transparent 70%);
    background-size: 200% 100%;
    animation: bannerSheen 6s ease-in-out infinite;
    pointer-events: none;
}
@keyframes bannerHueShift {
    0%,100% { background-position: 0% 50%; }
    50%     { background-position: 100% 50%; }
}
@keyframes bannerSheen {
    0%,100% { background-position: -100% 0; opacity: 0; }
    40%     { opacity: 1; }
    60%     { opacity: 1; }
    100%    { background-position: 200% 0; opacity: 0; }
}

/* Grand total — shimmer text */
.sticky-banner .text-emerald-300 {
    background: linear-gradient(90deg, #6ee7b7, #34d399, #a7f3d0, #34d399, #6ee7b7);
    background-size: 200% 100%;
    -webkit-background-clip: text; background-clip: text;
    -webkit-text-fill-color: transparent;
    animation: totalShimmer 4s linear infinite;
    text-shadow: 0 0 24px rgba(52,211,153,0.35);
}
@keyframes totalShimmer {
    0%   { background-position: 0% 50%; }
    100% { background-position: 200% 50%; }
}

/* ═══════════════════════════════════════════════
   ✨ ANIMATIONS
   ═══════════════════════════════════════════════ */
@keyframes scanPulse { 0%,100% { box-shadow: 0 0 0 0 rgba(59,130,246,0.5); } 50% { box-shadow: 0 0 0 8px rgba(59,130,246,0); } }
.scan-pulse { animation: scanPulse 1.5s ease-in-out infinite; }
@keyframes toastIn { from { transform: translateX(20px) scale(0.95); opacity: 0; } to { transform: translateX(0) scale(1); opacity: 1; } }
.toast-in { animation: toastIn 0.22s cubic-bezier(0.34, 1.56, 0.64, 1); }
@keyframes rowIn { from { transform: translateY(-8px) scale(0.98); opacity:0; } to { transform: translateY(0) scale(1); opacity:1; } }
.row-in { animation: rowIn 0.28s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes badgePop { 0% { transform: scale(0.5); opacity: 0; } 60% { transform: scale(1.15); } 100% { transform: scale(1); opacity: 1; } }
.item-num-badge { animation: badgePop 0.35s cubic-bezier(0.34, 1.56, 0.64, 1); background: linear-gradient(135deg,#3b82f6,#6366f1); color: white; box-shadow: 0 4px 12px -2px rgba(59,130,246,0.5), inset 0 1px 0 rgba(255,255,255,0.25); }
@keyframes glowPulse { 0%,100% { box-shadow: 0 0 0 2px rgba(59,130,246,0.55), 0 12px 28px -10px rgba(59,130,246,0.5); } 50% { box-shadow: 0 0 0 2px rgba(99,102,241,0.65), 0 14px 32px -10px rgba(99,102,241,0.55); } }

/* ═══ Item card polish ═══ */
.item-card { transition: box-shadow 0.22s ease, transform 0.22s ease, border-color 0.22s ease; position: relative; overflow: hidden; contain: layout style; }
.item-card:hover:not(.is-active) { border-color: rgb(147 197 253); box-shadow: 0 4px 14px -6px rgba(59,130,246,0.18); }
.dark .item-card:hover:not(.is-active) { border-color: rgb(59 130 246 / 0.6); }
.item-card.is-active { animation: glowPulse 2.4s ease-in-out infinite; transform: translateY(-1px); border-color: transparent !important; }
.item-card.is-active::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: linear-gradient(180deg,#3b82f6,#6366f1,#8b5cf6); }
.item-card.is-active::after { content: ''; position: absolute; right: -40px; top: -40px; width: 120px; height: 120px; background: radial-gradient(circle, rgba(99,102,241,0.08) 0%, transparent 70%); pointer-events: none; }

/* ═══ Premium inputs & focus ═══ */
.sticky-banner { will-change: transform; backface-visibility: hidden; }
input[type="text"], input[type="number"], input[type="email"], input[type="tel"], select, textarea {
    transition: border-color 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
}
input:focus-visible, select:focus-visible, textarea:focus-visible {
    outline: none;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.18), 0 1px 2px rgba(0,0,0,0.04) !important;
    border-color: rgb(59 130 246) !important;
}
.dark input:focus-visible, .dark select:focus-visible, .dark textarea:focus-visible {
    box-shadow: 0 0 0 3px rgba(96,165,250,0.25), 0 1px 2px rgba(0,0,0,0.3) !important;
    border-color: rgb(96 165 250) !important;
}
input[type="number"]::-webkit-outer-spin-button, input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
input[type="number"] { -moz-appearance: textfield; }

/* ═══ Buttons ═══ */
button { transition: transform 0.12s ease, background-color 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease; }
button:active:not(:disabled) { transform: scale(0.96); }
button:focus-visible { outline: 2px solid rgba(59,130,246,0.55); outline-offset: 2px; }

/* Quantity stepper polish */
.item-card .flex.items-stretch button {
    transition: background-color 0.12s ease, color 0.12s ease, transform 0.12s ease;
    user-select: none;
    min-width: 32px;
}
.item-card .flex.items-stretch button:hover { background: linear-gradient(180deg,#dbeafe,#bfdbfe); color: #1d4ed8; }
.dark .item-card .flex.items-stretch button:hover { background: linear-gradient(180deg,#1e40af,#1e3a8a); color: #dbeafe; }

/* ═══ KBD chips ═══ */
kbd {
    background: linear-gradient(180deg,#334155,#1e293b);
    color: #fff; padding: 2px 7px; border-radius: 5px;
    font-size: 10px; font-family: ui-monospace,SFMono-Regular,Menlo,monospace;
    font-weight: 700; letter-spacing: 0.02em;
    box-shadow: 0 1px 0 rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.08);
    border: 1px solid #475569;
}
.dark kbd { background: linear-gradient(180deg,#64748b,#475569); border-color: #64748b; }

/* ═══════════════════════════════════════════════
   🚀 ADD PRODUCT CTA — PREMIUM
   ═══════════════════════════════════════════════ */
.add-cta { background: rgba(255,255,255,0.4); backdrop-filter: blur(6px); }
.dark .add-cta { background: rgba(15,23,42,0.4); }
.add-cta:hover { box-shadow: 0 14px 40px -12px rgba(99,102,241,0.55); transform: translateY(-1px); }
.add-cta-bg {
    background: linear-gradient(120deg, #2563eb 0%, #6366f1 50%, #8b5cf6 100%);
    background-size: 200% 200%;
    animation: ctaGradient 4s ease-in-out infinite;
}
@keyframes ctaGradient {
    0%,100% { background-position: 0% 50%; }
    50%     { background-position: 100% 50%; }
}
.add-cta-shine::before {
    content: ''; position: absolute; top: 0; left: -75%;
    width: 50%; height: 100%;
    background: linear-gradient(115deg, transparent, rgba(255,255,255,0.45), transparent);
    transform: skewX(-20deg);
    transition: left 0.6s cubic-bezier(0.16,1,0.3,1);
}
.add-cta:hover .add-cta-shine::before { left: 130%; }

/* ═══════════════════════════════════════════════
   🥃 GLASSMORPHISM PANELS
   ═══════════════════════════════════════════════ */
.fbr-pos-stage .bg-white,
.fbr-pos-stage .dark\:bg-gray-900 {
    /* leave inputs alone — only target large panels via item-card / specific containers */
}
.item-card:not(.is-active) {
    background: linear-gradient(180deg, rgba(255,255,255,0.85), rgba(255,255,255,0.65)) !important;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(226,232,240,0.9);
}
.dark .item-card:not(.is-active) {
    background: linear-gradient(180deg, rgba(17,24,39,0.85), rgba(17,24,39,0.6)) !important;
    border: 1px solid rgba(51,65,85,0.6);
}
.item-card.is-active {
    background: linear-gradient(180deg, rgba(239,246,255,0.95), rgba(238,242,255,0.85)) !important;
    backdrop-filter: blur(12px);
}
.dark .item-card.is-active {
    background: linear-gradient(180deg, rgba(30,41,59,0.92), rgba(30,27,75,0.85)) !important;
}

/* ═══════════════════════════════════════════════
   🎯 ITEM NUMBER BADGE — UPGRADED
   ═══════════════════════════════════════════════ */
.item-num-badge { position: relative; }
.item-num-badge::after {
    content: ''; position: absolute; inset: -3px;
    border-radius: 9999px;
    background: linear-gradient(135deg, #3b82f6, #6366f1, #8b5cf6);
    z-index: -1; opacity: 0; filter: blur(8px);
    transition: opacity 0.3s ease;
}
.item-card.is-active .item-num-badge::after { opacity: 0.7; animation: badgeRingPulse 2s ease-in-out infinite; }
@keyframes badgeRingPulse {
    0%,100% { opacity: 0.5; filter: blur(8px); }
    50%     { opacity: 0.85; filter: blur(12px); }
}

/* ═══════════════════════════════════════════════
   💎 LINE TOTAL — PREMIUM CHIP
   ═══════════════════════════════════════════════ */
.item-card .text-sm.font-semibold[x-text*="lineTotal"] {
    /* Tailwind classes can't be matched in CSS — handled via .line-total-chip if added */
}
.line-total-chip {
    display: inline-flex; align-items: center;
    padding: 4px 12px; border-radius: 8px;
    background: linear-gradient(135deg, rgba(16,185,129,0.12), rgba(59,130,246,0.12));
    border: 1px solid rgba(16,185,129,0.25);
    font-weight: 800; color: #047857;
    font-variant-numeric: tabular-nums;
    transition: transform 0.2s ease, background 0.2s ease;
}
.dark .line-total-chip {
    background: linear-gradient(135deg, rgba(16,185,129,0.18), rgba(59,130,246,0.18));
    border-color: rgba(52,211,153,0.4); color: #6ee7b7;
}
.item-card.is-active .line-total-chip { transform: scale(1.06); background: linear-gradient(135deg, rgba(16,185,129,0.22), rgba(99,102,241,0.22)); }

/* ═══ Sticky bottom bar — mobile ═══ */
@supports (backdrop-filter: blur(12px)) {
    .lg\:hidden.fixed.bottom-0 { backdrop-filter: blur(12px); background-color: rgba(255,255,255,0.92); }
    .dark .lg\:hidden.fixed.bottom-0 { background-color: rgba(17,24,39,0.92); }
}

/* ═══ Scrollbar polish ═══ */
.main-scroll::-webkit-scrollbar { width: 10px; }
.main-scroll::-webkit-scrollbar-thumb { background: linear-gradient(180deg,#cbd5e1,#94a3b8); border-radius: 6px; border: 2px solid transparent; background-clip: padding-box; }
.main-scroll::-webkit-scrollbar-thumb:hover { background: linear-gradient(180deg,#94a3b8,#64748b); background-clip: padding-box; border: 2px solid transparent; }
.dark .main-scroll::-webkit-scrollbar-thumb { background: linear-gradient(180deg,#475569,#334155); background-clip: padding-box; border: 2px solid transparent; }

/* ═══ Empty state for first row when blank ═══ */
.item-card[data-item-index="0"] .row-in { }

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; }
    .item-card.is-active { animation: none; }
}
</style>
<div class="fbr-pos-stage max-w-7xl mx-auto pb-32 px-3 sm:px-4" x-data="fbrPosInvoice()" @click="userActivity()"
     x-init="window.addEventListener('online', () => isOnline = true); window.addEventListener('offline', () => isOnline = false);">
    {{-- 🌐 Offline Banner — visible only when no internet --}}
    <div x-show="!isOnline" x-cloak
         class="sticky top-0 z-50 -mx-3 sm:-mx-4 px-4 py-2.5 mb-2 bg-gradient-to-r from-red-600 to-red-700 text-white shadow-lg flex items-center justify-center gap-3 font-bold text-sm">
        <svg class="w-5 h-5 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 010 12.728m-2.829-9.9a5 5 0 010 7.072M9 12h.01M3 3l18 18"/></svg>
        Internet required for FBR submission. Bill saving is disabled until you reconnect.
    </div>
    {{-- 🎯 Sticky Premium Total Banner --}}
    <div class="sticky-banner sticky top-0 z-40 -mx-3 sm:-mx-4 px-3 sm:px-5 py-2.5 mb-3 bg-gradient-to-r from-slate-900 via-blue-900 to-indigo-900 text-white shadow-xl flex items-center justify-between gap-3 backdrop-blur supports-[backdrop-filter]:bg-slate-900/85 border-b border-white/10">
        <div class="flex items-center gap-3 sm:gap-5 min-w-0">
            <div class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                <div>
                    <div class="text-[9px] uppercase tracking-wider text-blue-200/80 leading-tight">Items</div>
                    <div class="text-lg sm:text-xl font-black tabular-nums leading-tight" x-text="items.filter(i => parseFloat(i.unit_price)>0).length"></div>
                </div>
            </div>
            <div class="hidden sm:block w-px h-8 bg-white/15"></div>
            <div>
                <div class="text-[9px] uppercase tracking-wider text-blue-200/80 leading-tight">Qty</div>
                <div class="text-lg sm:text-xl font-black tabular-nums leading-tight" x-text="totalQty()"></div>
            </div>
        </div>
        <div class="text-center px-3 py-1 rounded-lg bg-white/5 border border-emerald-400/20 min-w-0">
            <div class="text-[9px] uppercase tracking-wider text-emerald-200/90 leading-tight">Grand Total</div>
            <div class="text-xl sm:text-2xl md:text-3xl font-black tabular-nums text-emerald-300 leading-tight truncate" x-text="'Rs ' + formatNum(calcTotal())"></div>
        </div>
        <div class="flex items-center gap-1 sm:gap-1.5">
            <button type="button" @click="numpadOpen = true" title="On-screen numpad (F3)" class="w-9 h-9 sm:w-10 sm:h-10 flex items-center justify-center bg-white/10 hover:bg-white/20 active:scale-95 rounded-lg text-white text-base transition">⌨</button>
            <button type="button" @click="reprintLast()" title="Reprint last receipt (F12)" class="w-9 h-9 sm:w-10 sm:h-10 flex items-center justify-center bg-white/10 hover:bg-white/20 active:scale-95 rounded-lg text-white text-base transition">🖨</button>
            <button type="button" @click="toggleFullscreen()" title="Fullscreen (F11)" class="hidden sm:flex w-9 h-9 sm:w-10 sm:h-10 items-center justify-center bg-white/10 hover:bg-white/20 active:scale-95 rounded-lg text-white text-base transition">⛶</button>
            <button type="button" @click="soundOn = !soundOn; toast(soundOn ? 'Sound ON' : 'Sound OFF', 'info')" title="Toggle sound" class="w-9 h-9 sm:w-10 sm:h-10 flex items-center justify-center bg-white/10 hover:bg-white/20 active:scale-95 rounded-lg text-white text-base transition" x-text="soundOn ? '🔊' : '🔇'"></button>
        </div>
    </div>

    {{-- 📱 Sticky Bottom Mobile Pay Bar (visible only on mobile/tablet) --}}
    <div class="lg:hidden fixed bottom-0 left-0 right-0 z-40 bg-white dark:bg-gray-900 border-t-2 border-blue-200 dark:border-blue-800 px-3 py-2.5 shadow-2xl flex items-center gap-2">
        <div class="flex-1 min-w-0">
            <div class="text-[9px] uppercase tracking-wider font-bold text-slate-600 dark:text-slate-300 leading-tight">Total</div>
            <div class="text-lg font-black tabular-nums text-emerald-600 dark:text-emerald-400 leading-tight truncate" x-text="'Rs ' + formatNum(calcTotal())"></div>
        </div>
        <button type="button" @click="$refs.completeBtn && $refs.completeBtn.click()"
            class="flex-shrink-0 px-5 py-3 bg-gradient-to-r from-emerald-600 to-blue-600 hover:from-emerald-700 hover:to-blue-700 text-white font-black rounded-xl shadow-lg active:scale-95 transition text-sm">
            ✓ COMPLETE
        </button>
    </div>

    {{-- Toast Container --}}
    <div class="fixed top-20 right-4 z-50 space-y-2" style="pointer-events:none;">
        <template x-for="t in toasts" :key="t.id">
            <div class="toast-in px-4 py-3 rounded-lg shadow-2xl text-sm font-semibold text-white min-w-[200px]"
                :class="{ 'bg-emerald-600': t.type==='success', 'bg-red-600': t.type==='error', 'bg-blue-600': t.type==='info', 'bg-amber-600': t.type==='warn' }"
                x-text="t.msg"></div>
        </template>
    </div>

    {{-- 🎹 Floating Numpad Modal --}}
    <div x-show="numpadOpen" x-cloak @click.self="numpadOpen = false" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-5 w-80">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold dark:text-white text-lg">Numpad → Cash</h3>
                <button @click="numpadOpen = false" class="text-slate-500 dark:text-slate-300 hover:text-red-600 text-xl font-bold">✕</button>
            </div>
            <input type="text" readonly :value="'Rs ' + formatNum(cashReceived || 0)" class="w-full mb-3 text-right text-3xl font-black bg-gray-100 dark:bg-gray-900 dark:text-white rounded-lg px-3 py-3 tabular-nums">
            <div class="grid grid-cols-3 gap-2">
                <template x-for="k in ['7','8','9','4','5','6','1','2','3','0','00','.']" :key="k">
                    <button type="button" @click="numpadKey(k)" class="py-4 bg-gray-100 hover:bg-blue-100 dark:bg-gray-700 dark:hover:bg-blue-800 dark:text-white rounded-lg font-bold text-xl active:scale-95 transition" x-text="k"></button>
                </template>
            </div>
            <div class="grid grid-cols-2 gap-2 mt-2">
                <button type="button" @click="cashReceived = 0" class="py-3 bg-red-100 hover:bg-red-200 text-red-700 dark:bg-red-900/40 dark:text-red-300 rounded-lg font-bold">Clear</button>
                <button type="button" @click="numpadOpen = false; $refs.completeBtn && $refs.completeBtn.click()" class="py-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-bold">✓ Pay</button>
            </div>
        </div>
    </div>

    {{-- 💳 Payment Method Picker Modal (F8) --}}
    <div x-show="paymentModalOpen" x-cloak
         @keydown.window.escape="paymentModalOpen = false"
         @keydown.window.arrow-down.prevent="if(paymentModalOpen){ paymentChoiceIdx = (paymentChoiceIdx + 1) % paymentMethods.length; }"
         @keydown.window.arrow-up.prevent="if(paymentModalOpen){ paymentChoiceIdx = (paymentChoiceIdx - 1 + paymentMethods.length) % paymentMethods.length; }"
         @keydown.window.enter.prevent="if(paymentModalOpen){ confirmPaymentAndSubmit(); }"
         @keydown.window="if(paymentModalOpen && ['1','2','3','4'].includes($event.key)){ $event.preventDefault(); paymentChoiceIdx = parseInt($event.key) - 1; confirmPaymentAndSubmit(); }"
         class="fixed inset-0 z-[100] bg-black/70 backdrop-blur-sm flex items-center justify-center p-4"
         @click.self="paymentModalOpen = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl border-2 border-emerald-500 w-full max-w-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-xl font-black dark:text-white flex items-center gap-2">
                    <span class="inline-flex w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-500 to-blue-600 text-white items-center justify-center font-black text-sm">F8</span>
                    Choose Payment Method
                </h3>
                <button type="button" @click="paymentModalOpen = false" class="text-slate-500 dark:text-slate-300 hover:text-red-600 text-2xl leading-none font-bold">✕</button>
            </div>
            <div class="text-xs font-semibold text-slate-600 dark:text-slate-300 mb-3">Use <kbd class="px-1.5 py-0.5 bg-gray-200 dark:bg-gray-700 rounded">1-4</kbd> · <kbd class="px-1.5 py-0.5 bg-gray-200 dark:bg-gray-700 rounded">↑↓</kbd> + <kbd class="px-1.5 py-0.5 bg-gray-200 dark:bg-gray-700 rounded">Enter</kbd> · <kbd class="px-1.5 py-0.5 bg-gray-200 dark:bg-gray-700 rounded">Esc</kbd> to cancel</div>

            <div class="grid grid-cols-2 gap-3">
                <template x-for="(m, i) in paymentMethods" :key="m.value">
                    <button type="button"
                            @click="paymentChoiceIdx = i; confirmPaymentAndSubmit()"
                            :class="paymentChoiceIdx === i
                                ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/30 ring-2 ring-emerald-400 scale-[1.02]'
                                : 'border-gray-300 dark:border-gray-700 hover:border-emerald-400 bg-white dark:bg-gray-800'"
                            class="p-4 rounded-xl border-2 text-left transition-all">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-3xl" x-text="m.icon"></span>
                            <span class="inline-flex w-7 h-7 rounded-md bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 items-center justify-center font-black text-sm" x-text="i + 1"></span>
                        </div>
                        <div class="font-bold text-base dark:text-white" x-text="m.label"></div>
                        <div class="text-[10px] font-bold text-slate-600 dark:text-slate-300 mt-0.5" x-text="m.hint"></div>
                    </button>
                </template>
            </div>

            <div class="mt-4 flex items-center justify-between text-sm">
                <div class="text-gray-600 dark:text-gray-300">
                    Total: <span class="font-black text-emerald-700 dark:text-emerald-400" x-text="'Rs ' + formatNum(calcTotal())"></span>
                </div>
                <button type="button" @click="confirmPaymentAndSubmit()"
                        class="px-5 py-2.5 bg-gradient-to-r from-emerald-600 to-blue-600 hover:from-emerald-700 hover:to-blue-700 text-white font-bold rounded-lg shadow-lg">
                    ✓ Confirm &amp; Complete Sale
                </button>
            </div>
        </div>
    </div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white tracking-tight flex items-center gap-2">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-indigo-600 text-white text-base shadow-md">⚡</span>
                New FBR POS Sale
            </h1>
            <p class="text-sm font-semibold text-slate-700 dark:text-slate-300 mt-1.5">Premium point-of-sale · FBR-compliant · Real-time submission</p>
        </div>
        <div class="flex items-center gap-2">
            @if(!$fbrReportingEnabled)
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                LOCAL MODE
            </span>
            @endif
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {{ $company->fbr_pos_environment === 'production' ? 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' }}">
                {{ strtoupper($company->fbr_pos_environment ?? 'sandbox') }}
            </span>
        </div>
    </div>

    @if(!$fbrReportingEnabled)
    <div class="mb-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-300 px-4 py-3 rounded-lg">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
            <span class="text-sm font-medium">FBR Reporting is OFF — this invoice will be saved locally as <strong>FLOCAL-xxxx</strong> and will NOT be submitted to FBR.</span>
        </div>
    </div>
    @endif

    {{-- ============ Phase 2 Top Action Bar — PREMIUM ============ --}}
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 bg-slate-50 dark:bg-slate-900/60 border border-slate-200 dark:border-slate-700 rounded-2xl p-3 shadow-sm">
        <div class="flex items-center gap-3 flex-wrap">
            <div class="flex items-center gap-2 bg-white/70 dark:bg-slate-900/70 px-3 py-1.5 rounded-xl border border-blue-300 dark:border-blue-700 shadow-sm backdrop-blur">
                <label class="text-xs font-black text-slate-800 dark:text-slate-100 tracking-wide uppercase">Counter</label>
                <select x-model="terminalId" name="terminal_id" class="rounded-lg border-2 border-blue-300 dark:border-blue-700 dark:bg-slate-800 dark:text-white text-xs font-bold px-2 py-1 shadow-inner focus:ring-2 focus:ring-blue-500">
                    <option value="">-- Select --</option>
                    @foreach($terminals as $t)
                        <option value="{{ $t->id }}">{{ $t->terminal_name }}</option>
                    @endforeach
                </select>
                <a href="{{ route('fbrpos.phase2.terminals') }}" class="text-xs font-black text-blue-700 dark:text-blue-300 hover:text-blue-900 dark:hover:text-blue-100 hover:underline">+ Add</a>
            </div>
            @if($currentShift)
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 text-white rounded-xl text-xs font-black shadow-sm">
                    <span class="w-2 h-2 rounded-full bg-white animate-pulse"></span>
                    SHIFT #{{ $currentShift->id }} OPEN · Rs {{ number_format($currentShift->opening_cash, 0) }}
                </span>
            @else
                <a href="{{ route('fbrpos.phase2.shifts') }}" class="inline-flex items-center px-3 py-1.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-black shadow-sm">⚠ NO SHIFT — Open Now</a>
            @endif
        </div>
        <div class="flex items-center gap-2">
            <button type="button" @click="holdSale()" class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-black shadow-sm transition">⏸ Hold</button>
            <button type="button" @click="openRecall()" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-black shadow-sm transition">⏵ Recall <span x-show="heldList.length > 0" class="ml-1 bg-white text-indigo-700 rounded-full px-1.5 text-[10px] font-black" x-text="heldList.length"></span></button>
            <a href="{{ route('fbrpos.phase2.shifts') }}" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-black shadow-sm transition">$ Drawer</a>
        </div>
    </div>

    {{-- Recall Modal --}}
    <div x-show="recallOpen" x-cloak @click.self="recallOpen = false" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-lg w-full p-5 max-h-[80vh] overflow-y-auto">
            <div class="flex items-center justify-between mb-3"><h3 class="font-bold dark:text-white">Held Sales</h3><button type="button" @click="recallOpen = false" class="text-slate-500 dark:text-slate-300 hover:text-red-600 text-xl font-bold">✕</button></div>
            <template x-if="heldList.length === 0"><p class="text-sm font-semibold text-slate-600 dark:text-slate-300 py-6 text-center">No held sales</p></template>
            <template x-for="h in heldList" :key="h.id">
                <div class="border dark:border-gray-700 rounded-lg p-3 mb-2 flex items-center justify-between">
                    <div>
                        <div class="font-semibold dark:text-white" x-text="h.hold_name"></div>
                        <div class="text-xs font-semibold text-slate-600 dark:text-slate-300" x-text="(h.customer_name || 'Walk-in') + ' · ' + new Date(h.created_at).toLocaleString()"></div>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" @click="recallSale(h.id)" class="px-3 py-1 bg-emerald-600 text-white rounded text-xs font-bold">Recall</button>
                        <button type="button" @click="deleteHeld(h.id)" class="px-3 py-1 bg-red-600 text-white rounded text-xs">Delete</button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    @if($errors->any())
    <div class="mb-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg">
        <ul class="list-disc list-inside text-sm">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ route('fbrpos.store') }}" x-ref="saleForm" novalidate
          @submit.prevent="finalizeAndSubmit($event)"
          @keydown.enter="
              /* Block stray Enter from submitting the bill — Enter is for adding products only.
                 Use F9 (or the Complete button) to finalize. Per-input handlers (barcode,
                 search, item rows) still run because they fire first during bubble. */
              if ($event.target.tagName !== 'TEXTAREA' && $event.target.type !== 'submit' && $event.target.type !== 'button') {
                  $event.preventDefault();
              }
          ">
        @csrf

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 lg:gap-6 items-start">
            {{-- ═══ LEFT COLUMN — Frequently Sold Products quick-add tiles ═══
                 Sticky on desktop so cashiers always see their high-velocity items
                 (last 30-day top sellers). Click a tile → addProductItem(). --}}
            <aside class="lg:col-span-1 lg:order-1 space-y-3 lg:sticky lg:top-16 lg:self-start">
                {{-- 🎯 SCAN / SEARCH INPUT — moved here on user request so cart-build controls
                     stay on the LEFT and the actual cart fills the RIGHT column. --}}
                <div class="bg-white dark:bg-slate-900 rounded-2xl border-2 border-indigo-300 dark:border-indigo-700 shadow-md p-3 relative" @click.outside="searchOpen = false">
                    <div class="flex items-center gap-2 bg-slate-50 dark:bg-slate-800/60 border border-indigo-200 dark:border-indigo-800 rounded-xl p-2 shadow-sm scan-pulse focus-within:border-indigo-500 focus-within:ring-4 focus-within:ring-indigo-500/20 transition">
                        <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-indigo-600 text-white shadow-sm flex-shrink-0">
                            <svg class="w-5 h-5 animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10m4-10v10m4-10v10m4-10v10m-12 0h16"/></svg>
                        </span>
                        <input type="text" x-ref="barcodeInput" x-model="barcodeBuffer"
                            @input.debounce.250ms="
                                const q = (barcodeBuffer || '').trim();
                                if (q.length >= 2) {
                                    fetch('{{ route('fbrpos.api.products.search') }}?q=' + encodeURIComponent(q))
                                        .then(r => r.json())
                                        .then(data => { searchResults = data; searchHi = 0; searchOpen = data.length > 0; })
                                } else { searchResults = []; searchOpen = false; }
                            "
                            @keydown="handleScanInputShortcut($event)"
                            @keydown.arrow-down.prevent="if (searchOpen && searchResults.length) { searchHi = (searchHi + 1) % searchResults.length; }"
                            @keydown.arrow-up.prevent="if (searchOpen && searchResults.length) { searchHi = (searchHi - 1 + searchResults.length) % searchResults.length; }"
                            @keydown.escape.prevent="if (qtyMultiplier > 1) { qtyMultiplier = 1; toast('Multiplier cleared', 'info'); } searchOpen = false; searchResults = []; searchHi = 0;"
                            @keydown.enter.prevent="
                                if (searchOpen && searchResults.length > 0) {
                                    addProductItem(searchResults[searchHi]);
                                    barcodeBuffer = ''; searchResults = []; searchOpen = false; searchHi = 0;
                                    $nextTick(() => $refs.barcodeInput && $refs.barcodeInput.focus());
                                } else {
                                    scanBarcode();
                                }
                            "
                            autocomplete="off"
                            class="flex-1 min-w-0 bg-transparent border-0 focus:ring-0 text-sm font-mono font-bold text-slate-900 dark:text-white placeholder:text-slate-400 dark:placeholder:text-slate-500 placeholder:font-medium placeholder:text-xs placeholder:font-sans"
                            placeholder="🔎 Name · SKU · Barcode · HS · scan">
                        <span x-show="qtyMultiplier > 1"
                              class="text-[10px] font-black px-2 py-0.5 bg-amber-500 text-white rounded-md shadow-sm tracking-wider animate-pulse"
                              x-text="'× ' + qtyMultiplier"
                              title="Quantity multiplier active — Esc to cancel"></span>
                        <span class="text-[9px] font-bold px-1.5 py-0.5 bg-emerald-600 text-white rounded shadow-sm tracking-wider">●</span>
                    </div>
                    <div x-show="scanStatus" :class="scanStatus.ok ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'" class="text-[10px] font-bold mt-1 px-1" x-text="scanStatus && scanStatus.msg"></div>

                    {{-- Autocomplete dropdown — appears under input as cashier types --}}
                    <div x-show="searchOpen && searchResults.length > 0" x-cloak
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0 -translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         class="absolute left-2 right-2 top-full mt-1.5 bg-white dark:bg-slate-900 rounded-xl shadow-2xl border-2 border-indigo-300 dark:border-indigo-700 z-40 max-h-80 overflow-y-auto">
                        <div class="px-3 py-2 bg-indigo-50 dark:bg-indigo-950/40 border-b border-indigo-200 dark:border-indigo-800 flex items-center justify-between">
                            <div class="text-[10px] font-bold uppercase tracking-wider text-indigo-700 dark:text-indigo-300">
                                <span x-text="searchResults.length"></span> match<span x-show="searchResults.length !== 1">es</span>
                            </div>
                            <div class="text-[10px] font-semibold text-slate-500 dark:text-slate-400">↑↓ Enter Esc</div>
                        </div>
                        <div class="p-2 space-y-1">
                            <template x-for="(p, pi) in searchResults" :key="p.id">
                                <button type="button" @click="addProductItem(p); barcodeBuffer = ''; searchResults = []; searchOpen = false; searchHi = 0; $nextTick(() => $refs.barcodeInput && $refs.barcodeInput.focus());"
                                    :class="searchHi === pi ? 'bg-indigo-100 dark:bg-indigo-900/40 ring-2 ring-indigo-500' : 'hover:bg-slate-100 dark:hover:bg-slate-800/60'"
                                    class="w-full text-left px-3 py-2 rounded-lg transition flex items-center justify-between">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <p class="text-sm font-bold text-slate-900 dark:text-white truncate" x-text="(p.name && p.name.trim()) ? p.name : (p.barcode || p.sku || ('Product #' + p.id))"></p>
                                        </div>
                                        <div class="text-xs font-semibold text-slate-700 dark:text-slate-300 mt-0.5 flex items-center gap-2 flex-wrap">
                                            <span class="text-emerald-700 dark:text-emerald-400 font-bold" x-text="'Rs ' + Number(p.default_price).toFixed(2)"></span>
                                            <span class="text-slate-400">·</span>
                                            <span x-text="'HS ' + (p.hs_code || '—')"></span>
                                        </div>
                                    </div>
                                    <span class="text-xs font-bold px-2 py-0.5 rounded ml-2 shrink-0"
                                        :class="p.tax_type === 'exempt' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'"
                                        x-text="p.tax_type === 'exempt' ? 'EXEMPT' : (p.default_tax_rate + '%')"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="bg-gradient-to-br from-amber-50 to-orange-50 dark:from-amber-950/40 dark:to-orange-950/40 rounded-2xl border-2 border-amber-300 dark:border-amber-700 shadow-md p-3">
                    <div class="flex items-center justify-between mb-2 px-1">
                        <h3 class="text-sm font-black text-amber-900 dark:text-amber-200 tracking-tight flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-md bg-amber-500 text-white text-xs shadow-sm">🔥</span>
                            Quick Add
                        </h3>
                        <span class="text-[9px] font-bold uppercase tracking-wider text-amber-700 dark:text-amber-300">Top 30-day</span>
                    </div>
                    @if($frequentProducts->isEmpty())
                        <p class="text-xs text-amber-700 dark:text-amber-300 text-center py-6 px-2 leading-relaxed">No sales yet — your routine top-sellers will appear here automatically.</p>
                    @else
                        <div class="grid grid-cols-2 gap-1.5 max-h-[calc(100vh-12rem)] overflow-y-auto pr-1">
                            @foreach($frequentProducts as $fp)
                                @php
                                    $fpPayload = [
                                        'id' => $fp->id,
                                        'name' => $fp->name,
                                        'default_price' => (float) $fp->default_price,
                                        'default_tax_rate' => (float) ($fp->default_tax_rate ?? 18),
                                        'tax_type' => $fp->tax_type ?? 'standard',
                                        'hs_code' => $fp->hs_code,
                                        'sku' => $fp->sku,
                                        'barcode' => $fp->barcode,
                                        'default_uom' => $fp->default_uom ?? 'U',
                                        'is_price_editable' => (bool) ($fp->is_price_editable ?? true),
                                    ];
                                    // Use JSON_HEX_APOS so single quotes get escaped → safe to embed in @click="..."
                                    $fpJson = json_encode($fpPayload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
                                @endphp
                                <button type="button"
                                    @click="addProductItem({{ $fpJson }})"
                                    class="group bg-white dark:bg-slate-900 hover:bg-amber-100 dark:hover:bg-amber-900/40 active:scale-95 border border-amber-200 dark:border-amber-800 rounded-lg p-2 text-left transition shadow-sm hover:shadow-md">
                                    <p class="text-[11px] font-bold text-slate-900 dark:text-white leading-tight line-clamp-2 group-hover:text-amber-900 dark:group-hover:text-amber-100" title="{{ $fp->name }}">{{ $fp->name }}</p>
                                    <p class="text-[10px] font-black text-emerald-700 dark:text-emerald-400 mt-0.5 tabular-nums">Rs {{ number_format((float) $fp->default_price, 0) }}</p>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- ⌨️ Keyboard Shortcuts — moved to LEFT column on user request.
                     Collapsible by default so it doesn't crowd the cart-build column. --}}
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-md" x-data="{ shortcutsOpen: false }">
                    <button type="button" @click="shortcutsOpen = !shortcutsOpen"
                            class="w-full flex items-center justify-between px-3 py-2.5 text-left hover:bg-slate-50 dark:hover:bg-slate-800/40 rounded-2xl transition">
                        <h3 class="text-sm font-black text-slate-900 dark:text-white tracking-tight flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-md bg-slate-700 dark:bg-slate-600 text-white text-xs shadow-sm">⌨️</span>
                            Keyboard Shortcuts
                        </h3>
                        <svg class="w-4 h-4 text-slate-500 transition-transform" :class="shortcutsOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="shortcutsOpen" x-collapse class="px-3 pb-3">
                        <div class="flex flex-col gap-1.5 text-[10px] font-semibold text-slate-600 dark:text-slate-300">
                            <span><kbd>Ctrl</kbd>+<kbd>K</kbd> Search → <kbd>↓</kbd><kbd>↑</kbd><kbd>Enter</kbd> add</span>
                            <span class="px-1.5 py-0.5 rounded bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 font-bold"><kbd>Enter</kbd> = Add Product / Next Row</span>
                            <span><kbd>Ctrl</kbd>+<kbd>D</kbd> Duplicate · <kbd>Ctrl</kbd>+<kbd>Del</kbd> Remove</span>
                            <span class="px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300 font-bold"><kbd>F8</kbd>/<kbd>F9</kbd>/<kbd>Ctrl</kbd>+<kbd>B</kbd> = PAYMENT</span>
                            <span class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200 font-bold mt-1">Quantity tricks:</span>
                            <span><kbd>3</kbd>+<kbd>*</kbd> in scan → next adds qty 3</span>
                            <span><kbd>*</kbd> alone → jump to last row qty</span>
                            <span><kbd>+</kbd>/<kbd>-</kbd> in scan → bump last qty ±1</span>
                            <span><kbd>↑</kbd>/<kbd>↓</kbd> in qty/value → ±1</span>
                            <span><kbd>Ctrl</kbd>+<kbd>↑</kbd>/<kbd>↓</kbd> anywhere → last qty</span>
                            <span><kbd>Alt</kbd>+<kbd>Q</kbd> → focus last qty</span>
                            <span><kbd>Esc</kbd> in qty/value → back to scan</span>
                            <span><kbd>F2</kbd> Cash · <kbd>F3</kbd> Numpad · <kbd>F4</kbd> Hold · <kbd>F5</kbd> Recall · <kbd>F12</kbd> Reprint</span>
                        </div>
                    </div>
                </div>
            </aside>

            <div class="lg:col-span-2 lg:order-2 space-y-4">
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-md p-4 sm:p-5">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-base font-black text-slate-900 dark:text-white tracking-tight flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-indigo-600 text-white text-sm shadow-sm">🛒</span>
                            Cart Items
                            <span x-show="items.length > 0" class="text-[10px] font-black px-2 py-0.5 rounded-full bg-indigo-600 text-white tracking-wider" x-text="items.length + (items.length === 1 ? ' ITEM' : ' ITEMS')"></span>
                        </h3>
                        <button type="button" @click="addItem()" class="text-sm text-indigo-700 dark:text-indigo-300 hover:text-indigo-900 dark:hover:text-indigo-100 font-bold border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 rounded-lg px-3 py-1.5 shadow-sm transition">+ Manual Row</button>
                    </div>

                    {{-- ✨ Scan/search input MOVED to LEFT column above Quick Add tiles --}}

                    <template x-for="(item, index) in items" :key="item._uid">
                        <div class="item-card row-in border rounded-xl p-4 mb-3"
                             :data-item-index="index"
                             :class="[
                                activeItemIndex === index ? 'is-active' : '',
                                item.is_tax_exempt ? 'border-green-300 dark:border-green-700 bg-green-50/30 dark:bg-green-900/10' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900'
                             ]"
                             @focusin="activeItemIndex = index">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-2.5">
                                    <span class="item-num-badge inline-flex items-center justify-center w-7 h-7 rounded-full text-xs font-black tabular-nums" x-text="index + 1"></span>
                                    <span class="text-sm font-bold text-slate-900 dark:text-white" x-text="item.item_name || (item.product_id ? ('Product #' + item.product_id) : 'New Item')"></span>
                                    <span x-show="item.is_tax_exempt"
                                        class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300">EXEMPT</span>
                                    <span x-show="!item.is_tax_exempt && item.tax_rate != 18"
                                        class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300"
                                        x-text="item.tax_rate + '% TAX'"></span>
                                    <span x-show="!item.is_tax_exempt && item.tax_rate == 18"
                                        class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">18% GST</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button" @click="duplicateItem(index)" title="Duplicate row" class="text-blue-600 hover:text-blue-800 text-xs font-semibold">⎘ Duplicate</button>
                                    <button type="button" @click="removeItem(index)" x-show="items.length > 1" class="text-red-500 hover:text-red-700 text-xs font-semibold">✕ Remove</button>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-12 gap-3">
                                <input type="hidden" :name="'items['+index+'][product_id]'" :value="item.product_id || ''">
                                {{-- 🎯 VALUE MODE — hidden field carries Rs amount to backend (only when in VAL mode) --}}
                                <input type="hidden" :name="'items['+index+'][value_input]'"
                                       :value="(item.mode || 'qty') === 'value' && parseFloat(item._valueInput) > 0 ? item._valueInput : ''">
                                <div class="sm:col-span-3">
                                    <label class="block text-[11px] font-black text-slate-700 dark:text-slate-200 mb-1 tracking-wide uppercase">Item Name *</label>
                                    <input type="text" :name="'items['+index+'][item_name]'" x-model="item.item_name" required
                                        @keydown.enter.prevent="if(item.item_name && parseFloat(item.unit_price) > 0){ addItem(); focusLastRowName(); }"
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                        placeholder="Product name">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[11px] font-black text-slate-700 dark:text-slate-200 mb-1 tracking-wide uppercase">HS Code <span class="text-slate-500 dark:text-slate-400 font-bold normal-case">(Opt.)</span></label>
                                    <input type="text" :name="'items['+index+'][hs_code]'" x-model="item.hs_code"
                                        @keydown.enter.prevent="if(item.item_name && parseFloat(item.unit_price) > 0){ addItem(); focusLastRowName(); }"
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                        placeholder="00000000">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[11px] font-black text-slate-700 dark:text-slate-200 mb-1 tracking-wide uppercase">UoM</label>
                                    <select :name="'items['+index+'][uom]'" x-model="item.uom"
                                        @change="if ((item.mode || 'qty') === 'value' && !canUseValueMode(item)) { setMode(item, 'qty'); toast('Switched to QTY — UoM not value-compatible', 'info'); } truncateQtyOnUomChange(item);"
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500 font-semibold">
                                        <template x-for="u in uomOptions" :key="u">
                                            <option :value="u" x-text="u"></option>
                                        </template>
                                    </select>
                                </div>
                                <div class="sm:col-span-2">
                                    <div class="flex items-center justify-between mb-1 gap-1">
                                        <label class="block text-[11px] font-black text-slate-700 dark:text-slate-200 tracking-wide uppercase" x-text="(item.mode || 'qty') === 'value' ? 'Value (Rs) *' : 'Qty *'"></label>
                                        <div class="inline-flex rounded-md overflow-hidden border border-gray-300 dark:border-gray-600 text-[9px] font-bold leading-none">
                                            <button type="button" tabindex="-1"
                                                @click="setMode(item, 'qty')"
                                                :class="(item.mode || 'qty') === 'qty' ? 'bg-blue-600 text-white' : 'bg-gray-50 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-600'"
                                                class="px-1.5 py-0.5 transition" title="Quantity mode (Q)">QTY</button>
                                            <button type="button" tabindex="-1"
                                                x-show="item.is_price_editable !== false"
                                                @click="setMode(item, 'value')"
                                                :disabled="!canUseValueMode(item)"
                                                :class="(item.mode || 'qty') === 'value' ? 'bg-emerald-600 text-white' : 'bg-gray-50 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-600'"
                                                :title="canUseValueMode(item) ? 'Value (Rs) mode — qty auto-derives from value' : (parseFloat(item.unit_price) <= 0 ? 'Set unit price first' : 'Value mode only for KG/GM/LTR/ML/MTR/SQM')"
                                                class="px-1.5 py-0.5 transition disabled:opacity-40 disabled:cursor-not-allowed">VAL</button>
                                        </div>
                                    </div>
                                    {{-- QTY MODE — bigger tactile +/- stepper, no mousedown intercept, direct typing always works --}}
                                    <div x-show="(item.mode || 'qty') === 'qty'" class="flex items-stretch shadow-sm rounded-lg overflow-hidden ring-1 ring-blue-200 dark:ring-blue-800">
                                        <button type="button" tabindex="-1" @click="decQty(item)"
                                            class="px-3 sm:px-3.5 border-r border-blue-200 dark:border-blue-800 bg-gradient-to-b from-blue-50 to-blue-100 dark:from-blue-900/30 dark:to-blue-900/50 text-blue-700 dark:text-blue-300 text-base font-black hover:from-blue-100 hover:to-blue-200 dark:hover:from-blue-900/50 dark:hover:to-blue-900/70 active:scale-95 transition-all select-none">−</button>
                                        <input type="text" inputmode="decimal" autocomplete="off" maxlength="10"
                                            :data-qty-row="index"
                                            :name="'items['+index+'][quantity]'"
                                            x-model="item.quantity"
                                            :inputmode="isQtyDecimalAllowed(item) ? 'decimal' : 'numeric'"
                                            @input="item.quantity = sanitizeQty($event.target.value, item); syncValueFromQty(item)"
                                            @keydown="if (!isQtyDecimalAllowed(item) && ($event.key === '.' || $event.key === ',')) $event.preventDefault()"
                                            @focus="$event.target.select()"
                                            @blur="if(!item.quantity || parseFloat(item.quantity) <= 0){ item.quantity = 1; } syncValueFromQty(item);"
                                            @keydown.arrow-up.prevent="incQty(item); $event.target.select();"
                                            @keydown.arrow-down.prevent="decQty(item); $event.target.select();"
                                            @keydown.escape.prevent="$refs.barcodeInput && $refs.barcodeInput.focus()"
                                            @keydown.enter.prevent="
                                                if (item.product_id) {
                                                    /* Scanned item — go back to scan input for next item */
                                                    $refs.barcodeInput && $refs.barcodeInput.focus();
                                                } else if (item.item_name && parseFloat(item.unit_price) > 0) {
                                                    /* Manual row — add another manual row */
                                                    addItem(); focusLastRowName();
                                                } else {
                                                    $refs.barcodeInput && $refs.barcodeInput.focus();
                                                }
                                            "
                                            required
                                            class="w-full min-w-0 border-0 dark:bg-gray-800 dark:text-white text-base font-bold tabular-nums shadow-inner focus:ring-2 focus:ring-blue-500 focus:outline-none text-center px-1"
                                            placeholder="1"
                                            title="↑/↓ +1/-1 · Esc back to scan · Enter back to scan">
                                        <button type="button" tabindex="-1" @click="incQty(item)"
                                            class="px-3 sm:px-3.5 border-l border-blue-200 dark:border-blue-800 bg-gradient-to-b from-blue-50 to-blue-100 dark:from-blue-900/30 dark:to-blue-900/50 text-blue-700 dark:text-blue-300 text-base font-black hover:from-blue-100 hover:to-blue-200 dark:hover:from-blue-900/50 dark:hover:to-blue-900/70 active:scale-95 transition-all select-none">+</button>
                                    </div>
                                    {{-- ⚡ INLINE REVERSE CALC — type Rs amount, qty auto-calculates (no mode toggle needed)
                                         Hidden for fixed-price products since cashier shouldn't dictate Rs amount. --}}
                                    <div x-show="(item.mode || 'qty') === 'qty' && parseFloat(item.unit_price) > 0 && item.is_price_editable !== false"
                                         class="mt-1.5 flex items-center gap-1 bg-emerald-50 dark:bg-emerald-900/20 rounded-md px-1.5 py-1 ring-1 ring-emerald-200 dark:ring-emerald-800">
                                        <span class="text-[10px] font-black text-emerald-700 dark:text-emerald-300 leading-none whitespace-nowrap">Or Rs</span>
                                        <input type="text" inputmode="decimal" autocomplete="off" maxlength="10"
                                            x-model="item._amountInput"
                                            @focus="$event.target.select(); item._amountInput = item.line_value > 0 ? String(item.line_value) : ''"
                                            @input="item._amountInput = sanitizeQty($event.target.value); reverseCalcFromAmount(item, item._amountInput)"
                                            @blur="item._amountInput = ''"
                                            @keydown.enter.prevent="item._amountInput = ''; if(item.item_name && parseFloat(item.unit_price) > 0){ addItem(); focusLastRowName(); }"
                                            class="flex-1 min-w-0 border-0 bg-white dark:bg-gray-800 dark:text-white text-xs font-bold tabular-nums shadow-inner rounded px-1 py-0.5 focus:ring-1 focus:ring-emerald-500 focus:outline-none text-right"
                                            :placeholder="'e.g. ' + (parseFloat(item.unit_price) * 2).toFixed(0)"
                                            title="Type Rs amount → quantity auto-calculates from unit price">
                                        <span x-show="parseFloat(item.quantity) > 0 && item.line_value > 0"
                                              class="text-[9px] font-bold text-emerald-700 dark:text-emerald-400 leading-none whitespace-nowrap"
                                              x-text="'≈ ' + item.quantity + ' ' + item.uom"></span>
                                    </div>
                                    {{-- VALUE MODE --}}
                                    <div x-show="(item.mode || 'qty') === 'value'" class="flex items-stretch">
                                        <span class="px-2 inline-flex items-center rounded-l-lg border border-r-0 border-emerald-300 dark:border-emerald-700 bg-emerald-50 dark:bg-emerald-900/30 text-[11px] font-bold text-emerald-700 dark:text-emerald-300">Rs</span>
                                        <input type="text" inputmode="decimal" autocomplete="off" maxlength="12"
                                            :data-value-row="index"
                                            x-model="item._valueInput"
                                            @input="item._valueInput = sanitizeQty($event.target.value); applyValueInput(item)"
                                            @focus="$nextTick(() => $event.target.select())"
                                            @mousedown="if(document.activeElement !== $event.target){ $event.preventDefault(); $event.target.focus(); $event.target.select(); }"
                                            @blur="commitValueInput(item)"
                                            @keydown.arrow-up.prevent="
                                                /* +1 unit-price worth */
                                                const cur = parseFloat(item._valueInput) || 0;
                                                const step = parseFloat(item.unit_price) || 1;
                                                item._valueInput = (cur + step).toFixed(2);
                                                applyValueInput(item);
                                                $event.target.select();
                                            "
                                            @keydown.arrow-down.prevent="
                                                const cur = parseFloat(item._valueInput) || 0;
                                                const step = parseFloat(item.unit_price) || 1;
                                                const next = Math.max(0, cur - step);
                                                item._valueInput = next.toFixed(2);
                                                applyValueInput(item);
                                                $event.target.select();
                                            "
                                            @keydown.enter.prevent="
                                                commitValueInput(item);
                                                if (item.product_id) {
                                                    $refs.barcodeInput && $refs.barcodeInput.focus();
                                                } else if (item.item_name && parseFloat(item.unit_price) > 0) {
                                                    addItem(); focusLastRowName();
                                                } else {
                                                    $refs.barcodeInput && $refs.barcodeInput.focus();
                                                }
                                            "
                                            @keydown.escape.prevent="commitValueInput(item); $refs.barcodeInput && $refs.barcodeInput.focus();"
                                            class="w-full min-w-0 rounded-r-lg border border-emerald-300 dark:border-emerald-700 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-emerald-500 focus:border-emerald-500 text-center font-semibold px-1"
                                            placeholder="0.00"
                                            title="↑/↓ ±1 unit-price worth · Esc back to scan · Enter back to scan">
                                    </div>
                                    {{-- Derived qty subscript (visible in value mode) --}}
                                    <div x-show="(item.mode || 'qty') === 'value' && parseFloat(item.quantity) > 0" class="text-[10px] text-emerald-700 dark:text-emerald-400 mt-0.5 text-center font-semibold">
                                        ≈ <span x-text="item.quantity"></span> <span x-text="item.uom"></span>
                                    </div>
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[11px] font-black text-slate-700 dark:text-slate-200 mb-1 tracking-wide uppercase flex items-center gap-1">
                                        <span>Unit Price *</span>
                                        <span x-show="item.is_price_editable === false" class="px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 text-[9px] font-black tracking-wider" title="Product is configured as fixed-price — cashier cannot edit">🔒 FIXED</span>
                                    </label>
                                    <input type="number" :name="'items['+index+'][unit_price]'" x-model.number="item.unit_price" min="0.01" step="0.01" required
                                        :readonly="item.is_price_editable === false"
                                        :tabindex="item.is_price_editable === false ? -1 : 0"
                                        @focus="$event.target.select()"
                                        @input="syncValueFromQty(item)"
                                        @keydown.enter.prevent="if(item.item_name && parseFloat(item.unit_price) > 0){ addItem(); focusLastRowName(); }"
                                        :class="item.is_price_editable === false ? 'bg-amber-50 dark:bg-amber-900/20 cursor-not-allowed text-amber-900 dark:text-amber-200 font-bold' : 'dark:bg-gray-800 dark:text-white'"
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                        placeholder="0.00">
                                </div>
                                <div class="sm:col-span-1">
                                    <label class="block text-[11px] font-black text-slate-700 dark:text-slate-200 mb-1 tracking-wide uppercase">Tax %</label>
                                    <input type="number" :name="'items['+index+'][tax_rate]'" x-model.number="item.tax_rate" min="0" max="100" step="0.01"
                                        :disabled="item.is_tax_exempt"
                                        @focus="$event.target.select()"
                                        @keydown.tab="if(!$event.shiftKey && index === items.length - 1 && item.item_name && parseFloat(item.unit_price) > 0){ $event.preventDefault(); addItem(); }"
                                        @keydown.enter.prevent="if(item.item_name && parseFloat(item.unit_price) > 0){ addItem(); focusLastRowName(); }"
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500 disabled:opacity-50 px-1"
                                        placeholder="18">
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center justify-between mt-2 gap-3">
                                <div class="flex items-center gap-3">
                                    <label class="flex items-center gap-2 text-xs font-bold text-slate-700 dark:text-slate-200 cursor-pointer">
                                        <input type="checkbox" :name="'items['+index+'][is_tax_exempt]'" x-model="item.is_tax_exempt" value="1"
                                            class="rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500 w-3.5 h-3.5">
                                        Tax Exempt
                                    </label>
                                    <label class="flex items-center gap-1.5 text-xs font-bold text-slate-700 dark:text-slate-200">
                                        <span>Item Discount (PKR):</span>
                                        <input type="number" :name="'items['+index+'][item_discount]'" x-model.number="item.item_discount" min="0" step="0.01"
                                            @focus="$event.target.select()"
                                            class="w-24 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                            placeholder="0.00">
                                    </label>
                                </div>
                                <span class="line-total-chip text-sm" x-text="'PKR ' + formatNum(lineTotal(item))"></span>
                            </div>
                        </div>
                    </template>

                    {{-- ============ Premium "Add Next Item" CTA ============ --}}
                    <button type="button" @click="addItem()"
                        class="add-cta group relative w-full mt-2 py-4 rounded-2xl border-2 border-dashed border-blue-300 dark:border-blue-700 hover:border-transparent transition-all flex items-center justify-center gap-3 overflow-hidden">
                        <span class="add-cta-bg absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></span>
                        <span class="add-cta-shine absolute inset-0 pointer-events-none"></span>
                        <span class="relative z-10 w-10 h-10 rounded-full bg-indigo-600 text-white flex items-center justify-center text-xl font-black shadow-md group-hover:scale-110 group-hover:rotate-90 transition-all duration-300">+</span>
                        <span class="relative z-10 text-blue-700 dark:text-blue-300 group-hover:text-white font-bold text-base tracking-wide transition-colors">Add Another Product</span>
                        <span class="relative z-10 hidden sm:inline-flex items-center gap-1 text-xs font-semibold text-slate-600 dark:text-slate-300 group-hover:text-white/80 ml-2 transition-colors">
                            press <kbd>Ctrl</kbd>+<kbd>Enter</kbd> or <kbd>F6</kbd>
                        </span>
                    </button>

                    {{-- ✨ Keyboard hints strip MOVED to LEFT column (collapsible card under Quick Add) --}}
                </div>

                {{-- ═══ Customer · Promo · Payment now stacked BELOW cart in the SAME RIGHT column ═══
                     (Was previously a separate sticky right sidebar — merged on user request so
                     cashier sees Cart → Customer → Payment in one vertical flow on the right.) --}}
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                    <h3 class="text-base font-black text-slate-900 dark:text-white mb-4 flex items-center gap-2 tracking-tight">
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-emerald-600 text-white text-sm shadow-sm">👤</span>
                        Customer <span class="text-[10px] font-bold text-slate-500 dark:text-slate-400">(Optional)</span>
                    </h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-[11px] font-black text-slate-700 dark:text-slate-200 mb-1 tracking-wide uppercase">Name</label>
                            <input type="text" name="customer_name" x-model="customerName" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="Walk-in Customer">
                        </div>
                        <div>
                            <label class="block text-[11px] font-black text-slate-700 dark:text-slate-200 mb-1 tracking-wide uppercase">Phone <span class="text-emerald-600 text-[10px]" x-show="loyaltyEnabled">(loyalty lookup)</span></label>
                            <div class="flex gap-1">
                                <input type="text" name="customer_phone" x-model="customerPhone" @blur="lookupCustomer()" class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="0300-1234567">
                                <button type="button" @click="lookupCustomer()" class="px-3 bg-blue-600 text-white rounded-lg text-sm">Find</button>
                            </div>
                            <input type="hidden" name="customer_id" x-model="customerId">
                            <div x-show="customerPoints !== null" class="mt-2 bg-emerald-50 dark:bg-emerald-900/30 p-2 rounded text-xs">
                                <strong x-text="customerName + ': ' + customerPoints + ' pts'"></strong>
                                <template x-if="customerPoints >= loyaltyMinRedeem">
                                    <div class="mt-1 flex items-center gap-1">
                                        <input type="number" name="loyalty_points_redeemed" x-model.number="loyaltyRedeem" :max="customerPoints" min="0" class="w-20 border rounded px-1 py-0.5 text-xs" placeholder="Pts">
                                        <span x-text="'= Rs ' + (loyaltyRedeem * loyaltyPointValue).toFixed(0)"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[11px] font-black text-slate-700 dark:text-slate-200 mb-1 tracking-wide uppercase">NTN</label>
                            <input type="text" name="customer_ntn" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="Optional">
                        </div>
                    </div>
                </div>

                {{-- Promo Code --}}
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                    <h3 class="text-base font-black text-slate-900 dark:text-white mb-3 flex items-center gap-2 tracking-tight">
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-pink-600 text-white text-sm shadow-sm">🎁</span>
                        Promo Code
                    </h3>
                    <div class="flex gap-2">
                        <input type="text" x-model="promoCode" placeholder="Enter code" class="flex-1 uppercase rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm">
                        <button type="button" @click="applyPromo()" class="px-3 bg-emerald-600 text-white rounded-lg text-sm font-bold">Apply</button>
                    </div>
                    <input type="hidden" name="promotion_id" x-model="promotionId">
                    <input type="hidden" name="promotion_code" x-model="promoCode">
                    <div x-show="promoMessage" :class="promoOk ? 'text-emerald-700' : 'text-red-700'" class="text-xs mt-2 font-semibold" x-text="promoMessage"></div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
                    <h3 class="text-base font-black text-slate-900 dark:text-white mb-4 flex items-center gap-2 tracking-tight">
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-indigo-600 text-white text-sm shadow-sm">💳</span>
                        Payment &amp; Discount
                    </h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-[11px] font-black text-slate-700 dark:text-slate-200 mb-1 tracking-wide uppercase">Method *</label>
                            <select name="payment_method" x-ref="paymentSelect" x-model="paymentMethod" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="online">Online</option>
                            </select>
                            <p class="text-[11px] font-semibold text-slate-600 dark:text-slate-300 mt-1">Press <kbd class="px-1 bg-gray-200 dark:bg-gray-700 rounded text-[10px]">F8</kbd> to pick payment method &amp; complete sale</p>
                        </div>
                        <div>
                            <label class="block text-[11px] font-black text-slate-700 dark:text-slate-200 mb-1 tracking-wide uppercase">Discount Type</label>
                            <select name="discount_type" x-model="discountType" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <option value="">None</option>
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed Amount (PKR)</option>
                            </select>
                        </div>
                        <div x-show="discountType">
                            <label class="block text-[11px] font-black text-slate-700 dark:text-slate-200 mb-1 tracking-wide uppercase">Discount Value</label>
                            <input type="number" name="discount_value" x-model.number="discountValue" min="0" step="0.01"
                                @focus="$event.target.select()"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>
                </div>

                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl border border-blue-200 dark:border-blue-800 p-5">
                    {{-- 🎯 CART-LEVEL TAX INCLUSIVE TOGGLE — when ON, all unit prices are treated as
                         INCLUSIVE of GST (e.g. "150 ka rice" → bill total = 150, NOT 177).
                         Backend reverse-calculates net = price / (1 + rate/100). --}}
                    <label class="mb-3 flex items-start gap-2 cursor-pointer p-2.5 rounded-lg border-2 transition"
                        :class="taxInclusive ? 'border-emerald-400 bg-emerald-50 dark:border-emerald-600 dark:bg-emerald-900/20' : 'border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40'">
                        <input type="checkbox" x-model="taxInclusive" class="mt-0.5 rounded border-gray-400 text-emerald-600 focus:ring-emerald-500">
                        <div class="flex-1 leading-tight">
                            <span class="text-xs font-black text-slate-800 dark:text-slate-100" x-text="taxInclusive ? '✓ PRICES INCLUDE TAX' : 'Prices EXCLUDE tax (default)'"></span>
                            <p class="text-[11px] text-slate-600 dark:text-slate-400 mt-0.5"
                               x-text="taxInclusive ? 'e.g. Rs 150 item with 18% GST → bill total stays 150 (net 127.12 + tax 22.88).' : 'Tax is added on top of unit prices (e.g. 150 + 18% = 177).'"></p>
                        </div>
                    </label>
                    <input type="hidden" name="tax_inclusive" :value="taxInclusive ? '1' : '0'">
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between text-slate-700 dark:text-slate-200 font-semibold">
                            <span x-text="taxInclusive ? 'Net (Tax Excl.)' : 'Subtotal'"></span>
                            <span x-text="'PKR ' + formatNum(calcSubtotal())"></span>
                        </div>
                        <div class="flex justify-between text-slate-700 dark:text-slate-200 font-semibold" x-show="calcDiscount() > 0">
                            <span>Discount</span>
                            <span class="text-red-600" x-text="'-PKR ' + formatNum(calcDiscount())"></span>
                        </div>
                        <div class="flex justify-between text-slate-700 dark:text-slate-200 font-semibold" x-show="promoDiscount > 0">
                            <span>Promo <span class="text-xs" x-text="'(' + promoCode + ')'"></span></span>
                            <span class="text-emerald-600" x-text="'-PKR ' + formatNum(promoDiscount)"></span>
                        </div>
                        <div class="flex justify-between text-slate-700 dark:text-slate-200 font-semibold">
                            <span>Tax</span>
                            <span x-text="'PKR ' + formatNum(calcTax())"></span>
                        </div>
                        @if($fbrReportingEnabled)
                        <div class="flex justify-between text-slate-700 dark:text-slate-200 font-semibold">
                            <span>FBR POS Fee <span class="text-xs">(SRO 1279/2021)</span></span>
                            <span>PKR 1.00</span>
                        </div>
                        @endif
                        <div class="flex justify-between text-slate-700 dark:text-slate-200 font-semibold" x-show="loyaltyRedeem > 0">
                            <span>Loyalty Redeemed <span class="text-xs" x-text="'(' + loyaltyRedeem + ' pts)'"></span></span>
                            <span class="text-emerald-600" x-text="'-PKR ' + formatNum(loyaltyRedeem * loyaltyPointValue)"></span>
                        </div>
                        <div class="flex justify-between font-bold text-lg text-blue-800 dark:text-blue-300 pt-2 border-t border-blue-200 dark:border-blue-700">
                            <span>Total</span>
                            <span x-text="'PKR ' + formatNum(calcTotal())"></span>
                        </div>
                    </div>

                    {{-- ⚡ Fast Payment Section --}}
                    <div class="mt-3 pt-3 border-t border-blue-200 dark:border-blue-700 space-y-2">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1 flex items-center justify-between">
                                <span>💵 Cash Received</span>
                                <button type="button" @click="cashReceived = calcTotal(); $nextTick(() => $refs.cashInput && $refs.cashInput.focus())" class="text-emerald-600 hover:text-emerald-800 text-xs font-bold underline">EXACT</button>
                            </label>
                            <input type="number" name="cash_received" x-model.number="cashReceived" x-ref="cashInput"
                                @focus="$event.target.select()"
                                @keydown.enter.prevent=""
                                step="0.01" min="0" placeholder="Tendered amount (F9 / Ctrl+B = pay)"
                                class="w-full rounded-lg border-2 border-emerald-400 dark:border-emerald-600 dark:bg-gray-800 dark:text-white text-xl font-bold py-3 px-3 shadow-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        {{-- Quick tender buttons --}}
                        <div class="grid grid-cols-4 gap-1">
                            <button type="button" @click="addTender(100)" class="py-2 bg-gray-200 hover:bg-emerald-200 dark:bg-gray-700 dark:hover:bg-emerald-800 text-gray-900 dark:text-white rounded font-bold text-xs">+100</button>
                            <button type="button" @click="addTender(500)" class="py-2 bg-gray-200 hover:bg-emerald-200 dark:bg-gray-700 dark:hover:bg-emerald-800 text-gray-900 dark:text-white rounded font-bold text-xs">+500</button>
                            <button type="button" @click="addTender(1000)" class="py-2 bg-gray-200 hover:bg-emerald-200 dark:bg-gray-700 dark:hover:bg-emerald-800 text-gray-900 dark:text-white rounded font-bold text-xs">+1K</button>
                            <button type="button" @click="addTender(5000)" class="py-2 bg-gray-200 hover:bg-emerald-200 dark:bg-gray-700 dark:hover:bg-emerald-800 text-gray-900 dark:text-white rounded font-bold text-xs">+5K</button>
                        </div>
                        <div class="grid grid-cols-2 gap-1 mt-1">
                            <button type="button" @click="setNote(500)" class="py-1.5 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 dark:hover:bg-blue-900/40 text-blue-700 dark:text-blue-300 rounded font-bold text-xs">500 note</button>
                            <button type="button" @click="setNote(1000)" class="py-1.5 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 dark:hover:bg-blue-900/40 text-blue-700 dark:text-blue-300 rounded font-bold text-xs">1000 note</button>
                            <button type="button" @click="setNote(5000)" class="py-1.5 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 dark:hover:bg-blue-900/40 text-blue-700 dark:text-blue-300 rounded font-bold text-xs">5000 note</button>
                            <button type="button" @click="cashReceived = 0" class="py-1.5 bg-red-50 hover:bg-red-100 dark:bg-red-900/20 dark:hover:bg-red-900/40 text-red-700 dark:text-red-300 rounded font-bold text-xs">Clear</button>
                        </div>
                        {{-- HUGE Change Due display --}}
                        <div class="mt-2 p-3 rounded-lg text-center"
                            :class="cashReceived <= 0 ? 'bg-gray-100 dark:bg-gray-800' : (changeDue() >= 0 ? 'bg-emerald-100 dark:bg-emerald-900/40' : 'bg-red-100 dark:bg-red-900/40')">
                            <div class="text-xs font-semibold uppercase" :class="changeDue() >= 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'"
                                x-text="cashReceived <= 0 ? 'CHANGE DUE' : (changeDue() >= 0 ? 'CHANGE TO RETURN' : 'STILL OWED')"></div>
                            <div class="text-3xl font-black tabular-nums tracking-tight"
                                :class="cashReceived <= 0 ? 'text-gray-400' : (changeDue() >= 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300')"
                                x-text="'Rs ' + formatNum(Math.abs(changeDue()))"></div>
                        </div>
                    </div>
                </div>

                {{-- ✅ NEW (Apr-26): Button is now type="button" — opens Payment Confirm picker.
                     The actual form submission (DB row + FBR submission) ONLY happens after the
                     cashier presses "Confirm & Complete Sale" inside the picker modal.
                     This means the bill stays PROVISIONAL (just JS state) until payment is confirmed. --}}
                <button type="button" x-ref="completeBtn"
                    @click="openPaymentPicker()"
                    :disabled="!isOnline || submitting"
                    :class="(isOnline && !submitting) ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-gray-400 text-gray-200 cursor-not-allowed'"
                    class="w-full py-5 font-black rounded-xl transition text-lg shadow-xl tracking-wide flex items-center justify-center gap-2">
                    <svg x-show="submitting" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <span x-show="isOnline && !submitting">✓ CONFIRM PAYMENT &amp; SUBMIT <span class="opacity-70 text-xs font-normal">(F8 / F9)</span></span>
                    <span x-show="submitting" x-cloak>SUBMITTING TO FBR...</span>
                    <span x-show="!isOnline && !submitting" x-cloak>⚠ OFFLINE — RECONNECT TO SUBMIT</span>
                </button>
                {{-- Tiny clarifier under the button --}}
                <p class="mt-2 text-[11px] text-center font-semibold text-slate-600 dark:text-slate-300 leading-snug">
                    📝 Bill stays <span class="font-bold text-amber-600 dark:text-amber-400">PROVISIONAL</span> — edit/delete items above until you confirm payment.
                    <br>FBR submission happens <span class="font-bold">only</span> after you press "Confirm &amp; Complete" in the modal.
                </p>
            </div>
            {{-- /lg:col-span-2 RIGHT column (cart + customer + payment merged) --}}
        </div>
    </form>

    {{-- 🟢 Premium Bottom Status Bar --}}
    <div class="fixed bottom-0 left-0 right-0 z-30 bg-slate-900 text-white border-t border-slate-700 px-4 py-1.5 flex items-center justify-between text-xs">
        <div class="flex items-center gap-4">
            <span class="flex items-center gap-1.5">
                <span class="w-2 h-2 bg-emerald-400 rounded-full animate-pulse"></span>
                <span class="font-semibold">{{ auth('fbrpos')->user()->name ?? 'Cashier' }}</span>
            </span>
            <span class="text-slate-400">|</span>
            <span class="text-slate-300">{{ $company->company_name ?? 'POS' }}</span>
            @if($currentShift)
                <span class="text-slate-400">|</span>
                <span class="text-emerald-300">Shift #{{ $currentShift->id }}</span>
            @endif
        </div>
        <div class="flex items-center gap-3">
            <span class="text-slate-400">F2 Cash · F3 Pad · F4 Hold · F5 Recall · F6 +Row · F7 Search · <span class="text-amber-300 font-bold">F8/F9 Confirm Payment</span> · F12 Reprint</span>
            <span class="text-slate-400">|</span>
            <span x-text="new Date().toLocaleTimeString()" x-init="setInterval(() => $el.textContent = new Date().toLocaleTimeString(), 1000)" class="font-mono font-bold text-emerald-300"></span>
        </div>
    </div>
</div>

<script>
function fbrPosInvoice() {
    return {
        uomOptions: ['U','PCS','KG','GM','LTR','ML','MTR','SQM','FT','IN','YDS','PKT','DOZ','BOX','CTN','BAG','BTL','TIN','CAN','BUN','ROL','SET'],
        items: [{ _uid: 'r' + Date.now() + '_' + Math.random().toString(36).slice(2,7), item_name: '', hs_code: '', uom: 'U', quantity: 1, unit_price: 0, tax_rate: 18, is_tax_exempt: false, item_discount: 0, is_price_editable: true, mode: 'qty', _valueInput: '', _amountInput: '', line_value: 0 }],
        taxInclusive: false,
        activeItemIndex: 0,
        // 🎯 Unified search dropdown state (merged with barcode/scan input)
        searchOpen: false,
        searchHi: 0,
        searchResults: [],
        matchType(p, q) {
            if (!q) return null;
            const ql = String(q).toLowerCase();
            if ((p.name||'').toLowerCase().includes(ql)) return 'name';
            if ((p.sku||'').toLowerCase().includes(ql)) return 'sku';
            if ((p.barcode||'').toLowerCase().includes(ql)) return 'barcode';
            if ((p.hs_code||'').toLowerCase().includes(ql)) return 'hs';
            return null;
        },
        submitting: false, // PHASE 4 — double-submit guard + spinner state
        discountType: '',
        discountValue: 0,
        barcodeBuffer: '',
        scanStatus: null,
        // 🔢 Quantity multiplier — type "3*" then scan/select → next add gets qty 3 (auto-resets)
        qtyMultiplier: 1,
        // 🎯 Track last added row so * / Ctrl+Up / Ctrl+Down know which row to edit
        lastAddedIndex: -1,
        // Phase 2 state
        terminalId: @json($terminals->first()?->id ?? ''),
        customerId: '',
        customerName: '',
        customerPhone: '',
        customerPoints: null,
        loyaltyEnabled: @json((bool) $loyaltySettings->is_enabled),
        loyaltyPointValue: @json((float) $loyaltySettings->point_value),
        loyaltyMinRedeem: @json((int) $loyaltySettings->min_redeem_points),
        loyaltyRedeem: 0,
        promoCode: '',
        promotionId: '',
        promoDiscount: 0,
        promoMessage: '',
        promoOk: false,
        cashReceived: 0,
        cardAmount: 0,
        splitPayment: false,
        // Payment method picker (F8)
        paymentMethod: 'cash',
        paymentModalOpen: false,
        paymentChoiceIdx: 0,
        paymentMethods: [
            { value: 'cash',          label: 'Cash',          icon: '💵', hint: 'Press 1' },
            { value: 'card',          label: 'Card',          icon: '💳', hint: 'Press 2' },
            { value: 'bank_transfer', label: 'Bank Transfer', icon: '🏦', hint: 'Press 3' },
            { value: 'online',        label: 'Online',        icon: '🌐', hint: 'Press 4' },
        ],
        recallOpen: false,
        heldList: [],
        // Premium UI state
        numpadOpen: false,
        toasts: [],
        isOnline: navigator.onLine,
        toastSeq: 0,
        soundOn: localStorage.getItem('fbrpos_sound') !== '0',
        lastSaleId: localStorage.getItem('fbrpos_last_sale') || '',
        audioCtx: null,
        // 📡 Background scanner buffer (typing-mode friendly)
        _scanBuf: '',
        _scanLastTs: 0,
        _scanResetTimer: null,
        init() {
            // Phase 4: backfill stable _uid for every items[] row — required by
            // :key="item._uid" to prevent Alpine DOM-reuse delete-wrong-item bug.
            this.items = this.items.map(i => ({ ...i, _uid: i._uid || ('r' + Date.now() + '_' + Math.random().toString(36).slice(2,7)) }));
            // 🎯 Default focus: first product's Item Name (typing-friendly)
            // Scanner still works in background — see initBackgroundScanner()
            this.$nextTick(() => { this.focusLastRowName(); });
            this.initBackgroundScanner();
            this.loadHeld();
            // Global keyboard shortcuts
            window.addEventListener('keydown', (e) => {
                // ✅ NEW (Apr-26): When ANY modal is open, defer ALL global shortcuts to the
                // modal's own @keydown handlers (Alpine bindings). Prevents accidental row-add /
                // F8 picker re-open / search-modal toggle while user is confirming payment, etc.
                if (this.paymentModalOpen || this.recallOpen || this.searchOpen) {
                    // Allow Escape to bubble through Alpine modal handlers; allow F11 fullscreen always.
                    if (e.key === 'F11') { e.preventDefault(); this.toggleFullscreen(); }
                    return;
                }
                // Always-active combos (work even inside inputs)
                // Plain Enter (no modifiers) anywhere outside form inputs → add new product row
                if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey && !e.shiftKey && !e.altKey) {
                    const tag = (e.target && e.target.tagName) || '';
                    if (tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'SELECT' && tag !== 'BUTTON') {
                        e.preventDefault(); this.addItem(); this.focusLastRowName();
                        return;
                    }
                }
                // Ctrl+Enter still works as alias (for power users) — but plain Enter on item fields is the primary path
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); this.addItem(); this.focusLastRowName(); return; }
                // ✅ NEW (Apr-26): Ctrl+B opens payment picker (not direct submit).
                if ((e.ctrlKey || e.metaKey) && (e.key === 'b' || e.key === 'B')) { e.preventDefault(); this.openPaymentPicker(); return; }
                if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); this.$refs.barcodeInput && this.$refs.barcodeInput.focus(); return; }
                if ((e.ctrlKey || e.metaKey) && (e.key === 'd' || e.key === 'D')) {
                    if (this.activeItemIndex >= 0 && this.activeItemIndex < this.items.length) { e.preventDefault(); this.duplicateItem(this.activeItemIndex); }
                    return;
                }
                if ((e.ctrlKey || e.metaKey) && e.key === 'Delete') {
                    if (this.items.length > 1 && this.activeItemIndex >= 0) { e.preventDefault(); this.removeItem(this.activeItemIndex); }
                    return;
                }
                // ⌨️ NEW: Ctrl+Up / Ctrl+Down — bump last-added row qty ±1 (works ANYWHERE, even inside other inputs)
                if ((e.ctrlKey || e.metaKey) && e.key === 'ArrowUp') {
                    e.preventDefault(); this.bumpLastQty(1); return;
                }
                if ((e.ctrlKey || e.metaKey) && e.key === 'ArrowDown') {
                    e.preventDefault(); this.bumpLastQty(-1); return;
                }
                // ⌨️ NEW: Alt+Q (anywhere) — jump to last-added row's qty input
                if (e.altKey && (e.key === 'q' || e.key === 'Q')) {
                    e.preventDefault(); this.focusLastRowQty(); return;
                }
                // ═══ PHASE 2 — V/Q mode toggle (only when no input focused) ═══
                if ((e.key === 'v' || e.key === 'V' || e.key === 'q' || e.key === 'Q') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                    const tag = (e.target && e.target.tagName) || '';
                    if (tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'SELECT') {
                        if (this.activeItemIndex >= 0 && this.activeItemIndex < this.items.length) {
                            e.preventDefault();
                            const targetMode = (e.key === 'v' || e.key === 'V') ? 'value' : 'qty';
                            this.setMode(this.items[this.activeItemIndex], targetMode);
                            return;
                        }
                    }
                }
                // ✅ FIX (Apr-26): All F-keys must work even when an input has focus.
                // Previous whitelist only included F2/F3/F4/F5/F8/F9/F11 → F6/F7/F10/F12 silently failed
                // because the cashier almost always has the barcode/qty/amount field focused.
                const FBR_F_KEYS = ['F2','F3','F4','F5','F6','F7','F8','F9','F10','F11','F12'];
                if (e.target.tagName === 'INPUT' && FBR_F_KEYS.indexOf(e.key) === -1) return;
                // ✅ NEW (Apr-26): F9 / Enter / Ctrl+B all open the Payment Confirm picker.
                // No more "direct submit" — bill stays PROVISIONAL until cashier confirms payment in the modal.
                if (e.key === 'F9') { e.preventDefault(); this.openPaymentPicker(); }
                else if (e.key === 'F2') { e.preventDefault(); this.cashReceived = this.calcTotal(); this.$refs.cashInput && this.$refs.cashInput.focus(); }
                else if (e.key === 'F3') { e.preventDefault(); this.numpadOpen = !this.numpadOpen; }
                else if (e.key === 'F4') { e.preventDefault(); this.holdSale(); }
                else if (e.key === 'F5') { e.preventDefault(); this.openRecall(); }
                else if (e.key === 'F6') { e.preventDefault(); this.addItem(); this.focusLastRowName(); }
                else if (e.key === 'F7') { e.preventDefault(); this.$refs.barcodeInput && this.$refs.barcodeInput.focus(); }
                else if (e.key === 'F8') { e.preventDefault(); this.openPaymentPicker(); }
                else if (e.key === 'F10') { e.preventDefault(); this.openPaymentPicker(); } // alt confirm
                else if (e.key === 'F11') { e.preventDefault(); this.toggleFullscreen(); }
                else if (e.key === 'F12') { e.preventDefault(); this.reprintLast(); }
            });
            this.toast('POS Ready · Scanner active', 'success');
        },
        // ====== Premium helpers ======
        _lastActivity: 0,
        userActivity() { /* No-op — typing-friendly mode. Background scanner handles auto-capture.
                            We deliberately do NOT refocus the barcode input on user clicks anymore,
                            because that was stealing focus while users typed product details. */ },
        totalQty() { return this.items.reduce((s,i) => s + (parseFloat(i.quantity)||0), 0); },
        toast(msg, type) {
            const id = ++this.toastSeq;
            this.toasts.push({ id, msg, type: type || 'info' });
            setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id); }, 2500);
        },
        beep(freq, dur) {
            if (!this.soundOn) return;
            try {
                if (!this.audioCtx) this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = this.audioCtx.createOscillator();
                const gain = this.audioCtx.createGain();
                osc.connect(gain); gain.connect(this.audioCtx.destination);
                osc.type = 'sine'; osc.frequency.value = freq || 880;
                gain.gain.setValueAtTime(0.15, this.audioCtx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, this.audioCtx.currentTime + (dur||0.1));
                osc.start(); osc.stop(this.audioCtx.currentTime + (dur||0.1));
            } catch(e) {}
            try { localStorage.setItem('fbrpos_sound', this.soundOn ? '1' : '0'); } catch(e) {}
        },
        chime() { this.beep(660,0.08); setTimeout(()=>this.beep(990,0.12),90); setTimeout(()=>this.beep(1320,0.15),200); },
        numpadKey(k) {
            const cur = String(this.cashReceived || 0);
            if (k === '.' && cur.indexOf('.') >= 0) return;
            const next = (cur === '0' && k !== '.') ? k : (cur + k);
            this.cashReceived = parseFloat(next) || 0;
            this.beep(1200, 0.04);
        },
        toggleFullscreen() {
            if (!document.fullscreenElement) document.documentElement.requestFullscreen?.();
            else document.exitFullscreen?.();
        },
        reprintLast() {
            if (!this.lastSaleId) { this.toast('No previous sale found', 'warn'); return; }
            window.open('/fbr-pos/' + this.lastSaleId + '/receipt', '_blank');
        },
        // 📡 Full-Auto Background Scanner
        // Detects hardware barcode scanner input ANYWHERE on the page (no need to click barcode field).
        // Scanners type characters very fast (<30ms apart) and end with Enter. We buffer keys, and
        // when Enter arrives after a fast burst, we strip those chars from whatever input received
        // them and route the buffer to scanBarcode().
        initBackgroundScanner() {
            const SCAN_KEY_GAP = 35;          // ms — max gap between scanner keys
            const SCAN_MIN_LEN  = 4;          // min chars to qualify as a scan
            const IDLE_RESET    = 250;        // ms — clear buffer if user stops typing

            window.addEventListener('keydown', (e) => {
                // Skip when modals/overlays own the keyboard
                if (this.paymentModalOpen || this.numpadOpen || this.recallOpen) return;
                // Ignore modifier-key combos (shortcuts handled elsewhere)
                if (e.ctrlKey || e.metaKey || e.altKey) { this._scanBuf = ''; return; }

                const now = performance.now();
                const gap = now - this._scanLastTs;
                this._scanLastTs = now;

                if (e.key === 'Enter') {
                    // Did this Enter close a fast burst → treat as barcode scan
                    if (this._scanBuf.length >= SCAN_MIN_LEN && gap <= SCAN_KEY_GAP * 4) {
                        const code = this._scanBuf;
                        this._scanBuf = '';
                        // Strip the typed code from whatever input received it
                        const tgt = e.target;
                        if (tgt && (tgt.tagName === 'INPUT' || tgt.tagName === 'TEXTAREA')) {
                            const v = String(tgt.value || '');
                            if (v.endsWith(code)) {
                                tgt.value = v.slice(0, -code.length);
                                tgt.dispatchEvent(new Event('input', { bubbles: true }));
                            }
                        }
                        e.preventDefault();
                        e.stopPropagation();
                        this.barcodeBuffer = code;
                        this.scanBarcode();
                        return;
                    }
                    this._scanBuf = '';
                    return;
                }

                // Buffer only single printable chars during a fast burst
                if (e.key.length === 1) {
                    if (gap > SCAN_KEY_GAP) this._scanBuf = '';   // human typing → reset
                    this._scanBuf += e.key;
                }

                // Auto-reset buffer after idle
                clearTimeout(this._scanResetTimer);
                this._scanResetTimer = setTimeout(() => { this._scanBuf = ''; }, IDLE_RESET);
            }, true); // capture phase — runs before per-input handlers
        },
        // 🧹 Strip out any empty/half-filled rows (no name OR price <= 0 OR qty <= 0)
        cleanEmptyItems() {
            const before = this.items.length;
            this.items = this.items.filter(i =>
                (i.item_name && String(i.item_name).trim() !== '')
                && parseFloat(i.unit_price) > 0
                && parseFloat(i.quantity) > 0
            );
            return before - this.items.length; // # removed
        },
        // 📤 Final submit pipeline — used by F8/F9/Ctrl+B/button click
        finalizeAndSubmit(ev) {
            // PHASE 4 — block double-submit
            if (this.submitting) return;
            // Offline guard (mirrors button-level check)
            if (!this.isOnline) {
                this.toast('Internet required for FBR submission. Please reconnect.', 'error');
                return;
            }
            // 💵 CASH GUARD — if cash payment, received must be >= total (no shortage allowed)
            if (this.paymentMethod === 'cash') {
                const total = this.calcTotal();
                const received = parseFloat(this.cashReceived || 0);
                if (received < total) {
                    const shortBy = (total - received).toFixed(2);
                    this.toast('❌ Cash received Rs ' + received.toFixed(2) + ' is LESS than total Rs ' + total.toFixed(2) + ' (short by Rs ' + shortBy + '). Sale BLOCKED.', 'error');
                    this.beep && this.beep(220, 0.45);
                    this.$nextTick(() => { this.$refs.cashInput && this.$refs.cashInput.focus(); this.$refs.cashInput && this.$refs.cashInput.select && this.$refs.cashInput.select(); });
                    return;
                }
            }
            const removed = this.cleanEmptyItems();
            if (this.items.length === 0) {
                this.toast('No valid products in cart. Please add at least one product with name, qty & price.', 'error');
                this.beep && this.beep(220, 0.25);
                this.$nextTick(() => { this.$refs.barcodeInput && this.$refs.barcodeInput.focus(); });
                return;
            }
            if (removed > 0) {
                this.toast('Removed ' + removed + ' empty row' + (removed > 1 ? 's' : '') + ' before submitting', 'info');
            }
            // PHASE 4 — set submitting BEFORE native submit so spinner+disabled paint immediately
            this.submitting = true;
            // Wait for Alpine to re-render the cleaned items[] before native submit
            this.$nextTick(() => {
                // Native submit bypasses Alpine @submit listener (no recursion)
                this.$refs.saleForm.submit();
            });
        },
        // 🎯 Focus the Item Name field of the newest (last) item row
        focusLastRowName() {
            this.$nextTick(() => {
                const rows = document.querySelectorAll('.item-card');
                if (!rows.length) return;
                const lastRow = rows[rows.length - 1];
                const nameInput = lastRow.querySelector('input[name$="[item_name]"]');
                if (nameInput) { nameInput.focus(); nameInput.select && nameInput.select(); }
            });
        },
        // 💳 F8 Payment Picker
        openPaymentPicker() {
            // Guard: must have at least one valid item
            const hasValidItem = this.items.some(i => i.item_name && parseFloat(i.unit_price) > 0 && parseFloat(i.quantity) > 0);
            if (!hasValidItem) {
                this.toast('Add at least one product before payment', 'error');
                this.beep && this.beep(220, 0.2);
                return;
            }
            // Sync current selection
            const currentIdx = this.paymentMethods.findIndex(m => m.value === this.paymentMethod);
            this.paymentChoiceIdx = currentIdx >= 0 ? currentIdx : 0;
            this.paymentModalOpen = true;
            this.beep && this.beep(880, 0.06);
        },
        confirmPaymentAndSubmit() {
            if (!this.paymentModalOpen) return;
            const chosen = this.paymentMethods[this.paymentChoiceIdx];
            if (!chosen) return;
            this.paymentMethod = chosen.value; // x-model syncs the select
            this.paymentModalOpen = false;
            this.toast('Payment: ' + chosen.label + ' · Submitting to FBR...', 'success');
            // ✅ NEW (Apr-26): Call finalizeAndSubmit() directly — DO NOT click completeBtn
            // because completeBtn now opens the picker (would create infinite loop).
            // finalizeAndSubmit() does the cleanup + native form submit (DB + FBR production).
            this.$nextTick(() => {
                this.finalizeAndSubmit();
            });
        },
        async loadHeld() {
            try { const r = await fetch("{{ route('fbrpos.phase2.held.list') }}"); this.heldList = await r.json(); } catch(e) {}
        },
        openRecall() { this.loadHeld(); this.recallOpen = true; },
        async holdSale() {
            const name = prompt('Hold name (e.g. "Customer at Counter")', this.customerName || ('Hold ' + new Date().toLocaleTimeString()));
            if (!name) return;
            const cart = { items: this.items, discountType: this.discountType, discountValue: this.discountValue,
                customer_name: this.customerName, customer_phone: this.customerPhone };
            const fd = new FormData();
            fd.append('hold_name', name);
            fd.append('customer_name', this.customerName || '');
            fd.append('customer_phone', this.customerPhone || '');
            fd.append('terminal_id', this.terminalId || '');
            fd.append('cart_data', JSON.stringify(cart));
            // also send as nested keys
            Object.keys(cart).forEach(k => { if (k !== 'items') fd.append('cart_data[' + k + ']', cart[k] || ''); });
            cart.items.forEach((it, i) => {
                Object.keys(it).forEach(k => fd.append('cart_data[items][' + i + '][' + k + ']', it[k] ?? ''));
            });
            try {
                const r = await fetch("{{ route('fbrpos.phase2.hold') }}", { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }, body: fd });
                if (r.ok) { alert('Sale held: ' + name); this.resetCart(); this.loadHeld(); }
            } catch(e) { alert('Failed to hold: ' + e.message); }
        },
        async recallSale(id) {
            try {
                const r = await fetch("/fbr-pos/api/held/" + id + "/recall");
                const data = await r.json();
                if (data.success && data.cart) {
                    this.items = (data.cart.items || this.items).map(i => ({ ...i, _uid: i._uid || ('r' + Date.now() + '_' + Math.random().toString(36).slice(2,7)) }));
                    this.discountType = data.cart.discountType || '';
                    this.discountValue = data.cart.discountValue || 0;
                    this.customerName = data.cart.customer_name || '';
                    this.customerPhone = data.cart.customer_phone || '';
                    this.recallOpen = false;
                    this.lastAddedIndex = this.items.length - 1;
                    this.qtyMultiplier = 1;
                    this.loadHeld();
                    if (this.customerPhone) this.lookupCustomer();
                }
            } catch(e) {}
        },
        async deleteHeld(id) {
            if (!confirm('Delete held sale?')) return;
            await fetch("/fbr-pos/api/held/" + id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' } });
            this.loadHeld();
        },
        resetCart() {
            this.items = [{ _uid: 'r' + Date.now() + '_' + Math.random().toString(36).slice(2,7), item_name: '', hs_code: '', uom: 'U', quantity: 1, unit_price: 0, tax_rate: 18, is_tax_exempt: false, item_discount: 0, mode: 'qty', _valueInput: '', _amountInput: '', line_value: 0 }];
            this.discountType = ''; this.discountValue = 0;
            this.customerName = ''; this.customerPhone = ''; this.customerId = ''; this.customerPoints = null;
            this.promoCode = ''; this.promotionId = ''; this.promoDiscount = 0; this.promoMessage = '';
            this.loyaltyRedeem = 0; this.cashReceived = 0;
            this.lastAddedIndex = 0;
            this.qtyMultiplier = 1;
            this.activeItemIndex = 0;
        },
        async lookupCustomer() {
            if (!this.customerPhone || this.customerPhone.length < 4) { this.customerPoints = null; this.customerId = ''; return; }
            try {
                const r = await fetch("/fbr-pos/api/customer/" + encodeURIComponent(this.customerPhone) + "/points");
                const d = await r.json();
                if (d.ok) {
                    this.customerId = d.id;
                    this.customerName = this.customerName || d.name;
                    this.customerPoints = d.points;
                    this.loyaltyEnabled = d.enabled;
                    this.loyaltyPointValue = d.point_value;
                    this.loyaltyMinRedeem = d.min_redeem;
                } else { this.customerPoints = null; this.customerId = ''; }
            } catch(e) {}
        },
        async applyPromo() {
            if (!this.promoCode) { this.promoMessage = 'Enter promo code'; this.promoOk = false; return; }
            const fd = new FormData();
            fd.append('code', this.promoCode);
            fd.append('subtotal', this.calcSubtotal());
            try {
                const r = await fetch("{{ route('fbrpos.phase2.promo.validate') }}", { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }, body: fd });
                const d = await r.json();
                if (d.ok) {
                    this.promotionId = d.promotion_id;
                    this.promoDiscount = d.discount;
                    this.promoMessage = '✓ ' + d.promotion_name + ' applied: -Rs ' + d.discount;
                    this.promoOk = true;
                } else {
                    this.promotionId = ''; this.promoDiscount = 0;
                    this.promoMessage = '✗ ' + (d.msg || 'Invalid'); this.promoOk = false;
                }
            } catch(e) { this.promoMessage = 'Error'; this.promoOk = false; }
        },
        changeDue() {
            return Math.round((parseFloat(this.cashReceived || 0) - this.calcTotal()) * 100) / 100;
        },
        addTender(amount) {
            const cur = parseFloat(this.cashReceived || 0);
            this.cashReceived = Math.round((cur + amount) * 100) / 100;
            this.$nextTick(() => this.$refs.cashInput && this.$refs.cashInput.focus());
        },
        setNote(amount) {
            const total = this.calcTotal();
            const notes = Math.ceil(total / amount);
            this.cashReceived = notes * amount;
            this.$nextTick(() => this.$refs.cashInput && this.$refs.cashInput.focus());
        },
        // 🚫 Decimal qty NOT allowed for unit-based UoMs (PCS/U/BOX/PKT/...).
        // Mirrors FbrPosController::VALUE_MODE_UOMS allow-list: only KG/GM/LTR/ML/MTR/SQM
        // accept fractional quantities — everything else is whole-number-only at the UI
        // layer so cashiers can't even TYPE a decimal that the backend will reject later.
        isQtyDecimalAllowed(item) {
            const u = (item && item.uom || '').toString().toUpperCase();
            return ['KG', 'GM', 'LTR', 'ML', 'MTR', 'SQM'].includes(u);
        },
        sanitizeQty(v, item) {
            if (v === '' || v === null || v === undefined) return '';
            const allowDecimal = item ? this.isQtyDecimalAllowed(item) : true;
            if (!allowDecimal) {
                // Strip dots entirely → whole-number-only input for unit-based UoMs.
                return String(v).replace(/[^0-9]/g, '');
            }
            let s = String(v).replace(/[^0-9.]/g, '');
            const parts = s.split('.');
            if (parts.length > 2) s = parts[0] + '.' + parts.slice(1).join('');
            return s;
        },
        // When the cashier switches UoM (e.g. KG → PCS), truncate any fractional qty
        // already typed so the row immediately complies with the new UoM's rules.
        truncateQtyOnUomChange(item) {
            if (!item) return;
            if (!this.isQtyDecimalAllowed(item)) {
                const intQty = Math.max(1, Math.floor(parseFloat(item.quantity) || 1));
                item.quantity = String(intQty);
                this.syncValueFromQty(item);
            }
        },
        incQty(item) {
            let cur = parseFloat(item.quantity) || 0;
            item.quantity = (cur + 1).toString();
            this.syncValueFromQty(item);
        },
        decQty(item) {
            let cur = parseFloat(item.quantity) || 0;
            let next = cur - 1;
            if (next < 1) next = 1;
            item.quantity = next.toString();
            this.syncValueFromQty(item);
        },
        // ═══ PHASE 2 — VALUE MODE engine (FBR POS) ═══
        getBaseFactor(uom) {
            const u = (uom || '').toString().toUpperCase();
            if (u === 'KG' || u === 'LTR') return 1000;
            return 1;
        },
        canUseValueMode(item) {
            // 🎯 VALUE MODE — only measure-based UoMs (matches FbrPosController::VALUE_MODE_UOMS)
            // Also requires the product's price to be EDITABLE — fixed-price items can't use value mode.
            const u = (item.uom || '').toString().toUpperCase();
            const allowed = ['KG', 'GM', 'LTR', 'ML', 'MTR', 'SQM'];
            const editable = item.is_price_editable !== false;
            return editable && allowed.includes(u) && parseFloat(item.unit_price) > 0;
        },
        setMode(item, mode) {
            if (mode !== 'qty' && mode !== 'value') return;
            if (mode === 'value' && !this.canUseValueMode(item)) return;
            item.mode = mode;
            const price = parseFloat(item.unit_price) || 0;
            const qty = parseFloat(item.quantity) || 0;
            item.line_value = Math.round(qty * price * 100) / 100;
            if (mode === 'value') {
                item._valueInput = item.line_value > 0 ? String(item.line_value) : '';
            }
            const self = this;
            this.$nextTick(() => {
                const idx = self.items.indexOf(item);
                if (idx < 0) return;
                const sel = mode === 'value' ? '[data-value-row="' + idx + '"]' : '[data-qty-row="' + idx + '"]';
                const el = document.querySelector(sel);
                if (el) { el.focus(); try { el.select(); } catch(e){} }
            });
        },
        applyValueInput(item) {
            const raw = (item._valueInput || '').toString().trim();
            if (raw === '') return;
            const parsed = parseFloat(raw);
            if (!isFinite(parsed) || parsed < 0) return;
            const price = parseFloat(item.unit_price) || 0;
            if (price <= 0) return;
            // 🎯 4-decimal precision (matches backend round(qty, 4) and FBR PRAL spec).
            // VALUE MODE is gated to KG/GM/LTR/ML/MTR/SQM, so decimal qty is always valid here.
            const qty = Math.round((parsed / price) * 10000) / 10000;
            item.quantity = qty;
            item.line_value = Math.round(qty * price * 100) / 100;
        },
        commitValueInput(item) {
            const raw = (item._valueInput || '').toString().trim();
            if (raw === '') {
                item._valueInput = item.line_value > 0 ? String(item.line_value) : '';
                return;
            }
            this.applyValueInput(item);
            item._valueInput = item.line_value > 0 ? String(item.line_value) : '';
        },
        syncValueFromQty(item) {
            const qty = parseFloat(item.quantity) || 0;
            const price = parseFloat(item.unit_price) || 0;
            item.line_value = Math.round(qty * price * 100) / 100;
            if (item.mode === 'value') {
                item._valueInput = item.line_value > 0 ? String(item.line_value) : '';
            }
        },
        // ⚡ Inline reverse-calc: type Rs amount → quantity auto-derives from unit price
        // Works in QTY mode (no need to switch to VAL mode). For KG/LTR allows 3-decimal precision.
        reverseCalcFromAmount(item, amountStr) {
            const raw = (amountStr || '').toString().trim();
            if (raw === '') return; // empty = user is backspacing; don't clobber qty
            const parsed = parseFloat(raw);
            if (!isFinite(parsed) || parsed <= 0) return;
            const price = parseFloat(item.unit_price) || 0;
            if (price <= 0) return;
            const factor = this.getBaseFactor(item.uom); // KG/LTR=1000 (gm precision), else=1
            let qty;
            if (factor === 1) {
                // Whole-unit items (PCS, BOX, etc.) — round to nearest whole, min 1
                qty = Math.max(1, Math.round(parsed / price));
            } else {
                // Weight/volume — 3-decimal precision (≈1g for KG, ≈1ml for LTR)
                qty = Math.round((parsed / price) * 1000) / 1000;
                if (qty < 0.001) qty = 0.001;
            }
            item.quantity = String(qty);
            item.line_value = Math.round(qty * price * 100) / 100;
        },
        addItem() {
            this.items.push({ _uid: 'r' + Date.now() + '_' + Math.random().toString(36).slice(2,7), item_name: '', hs_code: '', uom: 'U', quantity: 1, unit_price: 0, tax_rate: 18, is_tax_exempt: false, item_discount: 0, is_price_editable: true, mode: 'qty', _valueInput: '', _amountInput: '', line_value: 0 });
            const newIdx = this.items.length - 1;
            this.activeItemIndex = newIdx;
            this.lastAddedIndex = newIdx;
            this.beep(600, 0.05);
            this.focusItemName(newIdx);
        },
        duplicateItem(index) {
            const src = this.items[index];
            if (!src) return;
            const copy = JSON.parse(JSON.stringify(src));
            // Phase 5: regenerate _uid so duplicated row gets unique :key (avoid Alpine
            // duplicate-key warnings + DOM-reuse bugs that brought us here in the first place).
            copy._uid = 'r' + Date.now() + '_' + Math.random().toString(36).slice(2,7);
            this.items.splice(index + 1, 0, copy);
            const newIdx = index + 1;
            this.activeItemIndex = newIdx;
            this.lastAddedIndex = newIdx;
            // If lastAddedIndex pointed to a row at/after the splice point, the splice
            // pushed it down by 1 already (we just overwrote with newIdx anyway).
            this.toast('Row duplicated', 'success');
            this.beep(880, 0.05);
            this.focusItemName(newIdx);
        },
        focusItemName(index) {
            this.$nextTick(() => {
                const card = document.querySelector(`[data-item-index="${index}"]`);
                if (card) {
                    card.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    const inp = card.querySelector('input[type="text"]');
                    if (inp) { inp.focus(); inp.select(); }
                }
            });
        },
        openProductSearch() {
            // 🎯 Compat shim — search is now MERGED into the barcode input.
            // Just focus the unified input; user types to see autocomplete dropdown.
            this.$refs.barcodeInput && this.$refs.barcodeInput.focus();
        },
        addProductItem(p) {
            let isExempt = p.tax_type === 'exempt';
            let taxRate = isExempt ? 0 : (parseFloat(p.default_tax_rate) || 18);
            const price = parseFloat(p.default_price) || 0;
            // 🔢 Apply pending qty multiplier (e.g. cashier typed "3*" before scan)
            const mult = Math.max(1, parseInt(this.qtyMultiplier, 10) || 1);
            // Resilient name fallback (in case backend returned a product with empty/null name)
            const displayName = (p.name && String(p.name).trim() !== '')
                ? String(p.name).trim()
                : (p.barcode || p.sku || ('Product #' + p.id));
            this.beep(880, 0.06);
            this.toast('+ ' + displayName + (mult > 1 ? ' × ' + mult : ''), 'success');
            // If same product already in cart, just bump qty by multiplier
            const existing = this.items.find(it => it.product_id && p.id && it.product_id === p.id);
            if (existing) {
                existing.quantity = (parseFloat(existing.quantity) || 0) + mult;
                this.syncValueFromQty(existing);
                this.lastAddedIndex = this.items.indexOf(existing);
                this.qtyMultiplier = 1;
                return;
            }
            const payload = {
                item_name: displayName,
                hs_code: p.hs_code || '',
                uom: p.uom || 'U',
                quantity: mult,
                unit_price: price,
                tax_rate: taxRate,
                is_tax_exempt: isExempt,
                item_discount: 0,
                product_id: p.id,
                is_price_editable: (p.is_price_editable === undefined || p.is_price_editable === null) ? true : !!p.is_price_editable,
                mode: 'qty',
                _valueInput: '',
                _amountInput: '',
                line_value: price * mult
            };
            // If first row is empty, mutate it in place (Alpine-reactivity safe)
            if (this.items.length === 1 && !this.items[0].item_name && !this.items[0].product_id) {
                Object.assign(this.items[0], payload);
                this.activeItemIndex = 0;
                this.lastAddedIndex = 0;
                this.qtyMultiplier = 1;
                return;
            }
            payload._uid = 'r' + Date.now() + '_' + Math.random().toString(36).slice(2,7);
            this.items.push(payload);
            this.activeItemIndex = this.items.length - 1;
            this.lastAddedIndex = this.items.length - 1;
            this.qtyMultiplier = 1;
        },
        // ⌨️ Scan input keyboard shortcuts — quantity multiplier + jump-to-qty
        // Hardware-scanner safe: standalone +/-/* are deferred 100ms — if MORE chars
        // arrive in that window (scanner fast-burst), we treat the symbol as part of
        // the barcode and inject it back. Human typing pauses are >100ms so shortcut fires.
        _symbolDeferTimer: null,
        _lastKeyTs: 0,
        handleScanInputShortcut(e) {
            const k = e.key;
            const cur = (this.barcodeBuffer || '').trim();
            const now = Date.now();
            const sinceLast = now - (this._lastKeyTs || 0);
            this._lastKeyTs = now;

            // 1) "<digits>*" pattern — only fire if input has been idle >150ms (rules out scanner mid-burst)
            if (k === '*' && /^\d+$/.test(cur) && sinceLast > 150) {
                e.preventDefault();
                const n = Math.min(999, Math.max(1, parseInt(cur, 10)));
                this.qtyMultiplier = n;
                this.barcodeBuffer = '';
                this.toast('× ' + n + ' multiplier active — next item will be qty ' + n, 'info');
                this.beep(660, 0.05);
                return;
            }

            // 2/3/4) Standalone "*" / "+" / "-" → DEFER 100ms. If more chars come, treat as scanner.
            if (cur === '' && (k === '*' || k === '+' || k === '-')) {
                e.preventDefault();
                const sym = k;
                if (this._symbolDeferTimer) clearTimeout(this._symbolDeferTimer);
                const self = this;
                this._symbolDeferTimer = setTimeout(() => {
                    self._symbolDeferTimer = null;
                    // If scan input still empty after 100ms → it was a deliberate shortcut
                    if ((self.barcodeBuffer || '').trim() === '') {
                        if (sym === '*') self.focusLastRowQty();
                        else if (sym === '+') self.bumpLastQty(1);
                        else if (sym === '-') self.bumpLastQty(-1);
                    } else {
                        // Scanner burst ate the symbol slot — inject it back as first char
                        self.barcodeBuffer = sym + self.barcodeBuffer;
                    }
                }, 100);
                return;
            }
        },
        // 🎯 Focus + select qty input of the last-added row (or last row in cart)
        focusLastRowQty() {
            let idx = this.lastAddedIndex;
            if (idx < 0 || idx >= this.items.length) idx = this.items.length - 1;
            if (idx < 0) { this.toast('No items in cart', 'error'); return; }
            const item = this.items[idx];
            // Switch to QTY mode if currently in VALUE mode (so we land on the right input)
            if (item.mode === 'value') item.mode = 'qty';
            this.activeItemIndex = idx;
            this.$nextTick(() => {
                const el = document.querySelector('[data-qty-row="' + idx + '"]');
                if (el) {
                    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
                    el.focus();
                    try { el.select(); } catch(e) {}
                }
            });
        },
        // ➕➖ Bump qty of last-added row by delta (clamped to >= 1), refocus scan input
        bumpLastQty(delta) {
            let idx = this.lastAddedIndex;
            if (idx < 0 || idx >= this.items.length) idx = this.items.length - 1;
            if (idx < 0) { this.toast('No items in cart', 'error'); return; }
            const item = this.items[idx];
            const cur = parseFloat(item.quantity) || 0;
            const next = Math.max(1, cur + delta);
            item.quantity = String(next);
            this.syncValueFromQty(item);
            this.activeItemIndex = idx;
            this.beep(delta > 0 ? 880 : 440, 0.04);
            this.toast('Row ' + (idx + 1) + ' qty: ' + next, 'info');
            this.$nextTick(() => { this.$refs.barcodeInput && this.$refs.barcodeInput.focus(); });
        },
        async scanBarcode() {
            const code = (this.barcodeBuffer || '').trim();
            if (!code) return;
            try {
                const res = await fetch('{{ route('fbrpos.api.products.barcode') }}?code=' + encodeURIComponent(code));
                const data = await res.json();
                if (data.found) {
                    this.addProductItem(data.product);
                    this.scanStatus = { ok: true, msg: '✓ ' + data.product.name };
                } else {
                    this.scanStatus = { ok: false, msg: 'Not found: ' + code };
                    this.beep(220, 0.25); this.toast('Barcode not found: ' + code, 'error');
                }
            } catch (e) {
                this.scanStatus = { ok: false, msg: 'Lookup failed' };
            }
            this.barcodeBuffer = '';
            setTimeout(() => { this.scanStatus = null; }, 2500);
            this.$nextTick(() => { this.$refs.barcodeInput && this.$refs.barcodeInput.focus(); });
        },
        removeItem(index) {
            this.items.splice(index, 1);
            // Keep lastAddedIndex pointing at a valid row (or recompute on next access)
            if (this.lastAddedIndex === index) {
                this.lastAddedIndex = Math.min(index, this.items.length - 1);
            } else if (this.lastAddedIndex > index) {
                this.lastAddedIndex--;
            }
            if (this.items.length === 0) this.addItem();
        },
        lineGross(item) {
            return (parseFloat(item.quantity) || 0) * (parseFloat(item.unit_price) || 0);
        },
        lineNet(item) {
            const gross = this.lineGross(item);
            const disc = Math.min(parseFloat(item.item_discount) || 0, gross);
            return gross - disc;
        },
        lineTotal(item) {
            const net = this.lineNet(item);
            const taxRate = item.is_tax_exempt ? 0 : (parseFloat(item.tax_rate) || 0);
            // 🎯 Tax-INCLUSIVE: unit_price already includes tax → row total = net (gross-after-disc).
            // Tax-EXCLUSIVE (default): add tax on top.
            if (this.taxInclusive && taxRate > 0) return net;
            return net + (net * taxRate / 100);
        },
        calcSubtotal() {
            // 🎯 In tax-INCLUSIVE mode the per-line "subtotal" is the back-derived NET
            // (gross / (1+rate/100)) so that subtotal + tax = gross (e.g. 150 stays 150).
            return this.items.reduce((sum, item) => sum + this._lineNetForTotals(item), 0);
        },
        _lineNetForTotals(item) {
            const net = this.lineNet(item);
            const taxRate = item.is_tax_exempt ? 0 : (parseFloat(item.tax_rate) || 0);
            if (this.taxInclusive && taxRate > 0) {
                return Math.round((net / (1 + taxRate / 100)) * 100) / 100;
            }
            return net;
        },
        calcDiscount() {
            let sub = this.calcSubtotal();
            if (this.discountType === 'percentage') return sub * (this.discountValue || 0) / 100;
            if (this.discountType === 'fixed') return Math.min(this.discountValue || 0, sub);
            return 0;
        },
        calcTax() {
            return this.items.reduce((sum, item) => {
                const net = this.lineNet(item);
                const taxRate = item.is_tax_exempt ? 0 : (parseFloat(item.tax_rate) || 0);
                if (this.taxInclusive && taxRate > 0) {
                    // gross-of-tax → reverse: tax = gross - gross/(1+r/100)
                    const baseNet = net / (1 + taxRate / 100);
                    return sum + (net - baseNet);
                }
                return sum + (net * taxRate / 100);
            }, 0);
        },
        calcTotal() {
            var fbrCharge = {{ $fbrReportingEnabled ? '1' : '0' }};
            return this.calcSubtotal() - this.calcDiscount() + this.calcTax() + fbrCharge;
        },
        formatNum(n) {
            return Number(n).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    };
}
</script>
</x-fbr-pos-layout>
