<!DOCTYPE html>
@php
    $isDarkMode = auth('pos')->check() && auth('pos')->user()->dark_mode;
    $posUserLayout = auth('pos')->user();
    $isCashierLayout = $posUserLayout && $posUserLayout->isPosCashier();
    $companyLayout = \App\Models\Company::find(app('currentCompanyId'));
    // Restaurant nav features (Tables, KDS, Ingredients, Recipes) gate.
    // Single source of truth = companies.restaurant_mode (toggle in Business Profile).
    // Disable that toggle → restaurant nav items disappear immediately, regardless of business_category or pos_type.
    $isRestaurantLayout = $companyLayout && (bool) $companyLayout->restaurant_mode;
    // Nav visibility for Tables/KDS follows the EFFECTIVE feature flags (Task Jul 2026):
    // matches what the restaurant.only middleware actually allows, regardless of
    // the restaurant_mode toggle. Dashboard routing above stays on restaurant_mode.
    $posFeaturesLayout = \App\Services\PosFeatureService::forCompany($companyLayout);
    // POS Team Custom Access (Task #111): optional per-member feature grants.
    // $posNavCan('feature', $roleDefault) — no custom set → role default
    // (existing behavior, zero change for existing shops); custom set →
    // ONLY ticked features render in the nav (route gate lives in PosAuth).
    $posNavCan = function (string $f, bool $default = true) use ($posUserLayout) {
        return ($posUserLayout ? $posUserLayout->posCustomAllows($f) : null) ?? $default;
    };
    // POS UNIFICATION: every company (restaurant or retail) now bills on the single
    // universal sale screen (pos.universal). The legacy restaurant sale screen and its
    // per-company opt-out were retired — restaurant behavior is driven by feature flags.
    // Per-cashier toggle (owner rule Jul 2026): layout badge shows THIS user's effective state.
    $praEnabledLayout = $companyLayout && $posUserLayout && $posUserLayout->praReportingEnabled($companyLayout);
    $inventoryEnabledLayout = $companyLayout && $companyLayout->inventory_enabled;
    $companyName = $companyLayout->name ?? 'My Business';
    $userName = $posUserLayout->name ?? 'User';
    $userInitial = strtoupper(substr($userName, 0, 1));
    $userRole = $isCashierLayout ? __('pos.role_cashier') : __('pos.role_admin');
    $posTheme = $companyLayout->pos_theme ?? 'purple';
    // "What's New" app updates (popup + bell). Admin-controlled via SystemSetting
    // pos_whats_new_enabled. NEVER break POS pages if the table is missing on prod
    // (schema-drift self-heal convention) — fail silent.
    $whatsNewList = collect(); $whatsNewUnseenCount = 0; $whatsNewPopup = null; $whatsNewSeenIds = []; $whatsNewPopupList = collect();
    try {
        // ADMIN/MANAGER ONLY (owner rule, Jul 2026): "What's New" popup + bell must
        // NEVER show on cashier screens — updates are the admin/manager's job to
        // read and pass on. isPosAdmin() = pos_admin / pos_manager / company_admin;
        // this also keeps confined roles (kitchen/waiter/rider) out (they can't
        // POST /pos/whats-new/seen anyway — dismiss loop).
        // Pending companies: approval middleware blocks POSTs too — skip them as well.
        $wnAllowed = $posUserLayout && $posUserLayout->isPosAdmin();
        $wnPending = ($companyLayout->status ?? null) === 'pending';
        // View-only impersonation (admin "View as Company"): ReadOnlyImpersonation
        // blocks ALL POSTs incl. /pos/whats-new/seen — the popup would re-appear on
        // EVERY page (dismiss loop) and its full-screen z-130 overlay sits on top of
        // everything. Same convention as pending companies: skip it entirely.
        $wnImp = session('impersonation');
        $wnReadonlyImp = is_array($wnImp) && !empty($wnImp['readonly']);
        if ($wnAllowed && !$wnPending && !$wnReadonlyImp
            && \Illuminate\Support\Facades\Schema::hasTable('app_updates')
            && \App\Models\SystemSetting::get('pos_whats_new_enabled', '1') === '1') {
            $whatsNewList = \App\Models\AppUpdate::where('audience', 'pos')->where('is_published', true)
                ->orderByDesc('created_at')->limit(10)->get();
            if ($whatsNewList->isNotEmpty()) {
                $whatsNewSeenIds = \App\Models\AppUpdateSeen::where('user_id', $posUserLayout->id)
                    ->whereIn('app_update_id', $whatsNewList->pluck('id'))->pluck('app_update_id')->all();
                $whatsNewUnseen = $whatsNewList->reject(fn ($u) => in_array($u->id, $whatsNewSeenIds));
                $whatsNewUnseenCount = $whatsNewUnseen->count();
                $whatsNewPopup = $whatsNewUnseen->first();
                // Owner (21 Jul 2026): popup shows ALL unseen updates stacked in one
                // scrollable body (newest first) — not just the latest one.
                $whatsNewPopupList = $whatsNewUnseen->values();
            }
        }
    } catch (\Throwable $e) { /* keep POS pages alive */ }
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $isDarkMode ? 'dark' : '' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
        <meta name="theme-color" content="#7c3aed">
        <link rel="stylesheet" href="{{ asset('css/mobile.css?v=2.6') }}">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="Nest Pra Pos">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="application-name" content="Nest Pra Pos">
        <link rel="manifest" href="/manifest-pos.json?v=2">
        <link rel="apple-touch-icon" href="/icons/nest-pra/icon-192.png">
        <link rel="icon" type="image/png" sizes="192x192" href="/icons/nest-pra/icon-192.png">
        <link rel="icon" type="image/png" sizes="512x512" href="/icons/nest-pra/icon-512.png">
        <title>NestPOS — {{ config('app.name', 'TaxNest') }}</title>
        {{-- ONE font CDN only (perf, Jul 2026): Google Fonts duplicate removed — it
             loaded the SAME Inter family from 2 extra domains (2 extra DNS+TLS
             round-trips per fresh visit, render-blocking). Do not re-add. --}}
        <link rel="preconnect" href="https://fonts.bunny.net">
        {{-- Non-render-blocking font load (customer blank-screen report, 25 Jul 2026):
             media="print" defers CSS apply until onload flips it to all — first paint
             no longer waits on the fonts.bunny.net round-trip (slow PK connections).
             Inter has display=swap so text renders in the system font meanwhile. --}}
        <link href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700,800,900&display=swap" rel="stylesheet" media="print" onload="this.media='all'" />
        <noscript><link href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700,800,900&display=swap" rel="stylesheet" /></noscript>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <script>
            // Alpine CDN fallback (only if the Vite bundle failed). MUST arm AFTER
            // DOMContentLoaded: module scripts always run before DCL, so post-DCL
            // "no Alpine" is definitive. The old blind 1.5s timer fired MID-PARSE on
            // slow POS PCs (big sale-screen HTML still streaming) — CDN Alpine then
            // started before restaurantPos() was even defined → whole screen error
            // flood + stuck splash + a SECOND Alpine boot when the bundle arrived.
            (function(){
                function tnAlpineFallback(){
                    setTimeout(function(){
                        if(!window.Alpine && !window.__alpineStarted && !window.__alpineFallbackLoading){
                            window.__alpineFallbackLoading=true;
                            window.__alpineStarted=true; // block a late bundle from double-starting
                            var c=document.createElement('script');
                            c.src='https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.14.8/dist/cdn.min.js';
                            document.head.appendChild(c);
                            c.onload=function(){
                                var s=document.createElement('script');
                                s.src='https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js';
                                document.head.appendChild(s);
                            };
                        }
                    }, 500);
                }
                if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', tnAlpineFallback); }
                else { tnAlpineFallback(); }
            })();
        </script>
        {{-- Self-hosted Chart.js (perf, Jul 2026): third-party CDN cost an extra
             DNS+TLS connection on every fresh load; .htaccess caches /vendor 30d. --}}
        <script defer src="/vendor/chart.umd.min.js?v=4.4.0"></script>
        <script>if(document.documentElement.classList.contains('dark')){document.documentElement.style.colorScheme='dark';}</script>
        <style>
            *, *::before, *::after { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
            html, body { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; text-rendering: optimizeLegibility; font-feature-settings: 'cv11', 'ss01'; font-variation-settings: 'opsz' 32; }
            body { letter-spacing: -0.011em; }
            h1, h2, h3, h4, h5, h6, .font-bold, .font-extrabold, .font-semibold { text-rendering: geometricPrecision; }
            .dark body { color: #f1f5f9; }
            .dark h1, .dark h2, .dark h3, .dark h4, .dark h5, .dark h6 { color: #f8fafc; }
            .dark .text-gray-400 { color: #cbd5e1 !important; }
            .dark .text-gray-500 { color: #94a3b8 !important; }
            @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
            @keyframes pageSlideIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
            @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
            @keyframes slideDown { from { opacity: 0; transform: translateY(-8px) scale(0.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
            @keyframes skeletonPulse { 0%, 100% { opacity: 0.4; } 50% { opacity: 1; } }
            @keyframes shimmerLoad { 0% { background-position: -200% 0; } 100% { background-position: 200% 0; } }
            @keyframes btnPress { 0% { transform: scale(1); } 50% { transform: scale(0.96); } 100% { transform: scale(1); } }
            .page-fade { animation: pageSlideIn 0.35s cubic-bezier(0.16, 1, 0.3, 1); }
            .btn-loading { position: relative; pointer-events: none; opacity: 0.7; }
            .btn-loading::after { content: ''; position: absolute; right: 8px; top: 50%; width: 14px; height: 14px; margin-top: -7px; border: 2px solid transparent; border-top-color: currentColor; border-radius: 50%; animation: spin 0.6s linear infinite; }
            .btn-press { transition: transform 0.15s ease; }
            .btn-press:active { animation: btnPress 0.2s ease; }
            .skeleton-block { background: linear-gradient(90deg, #e5e7eb 25%, #f3f4f6 50%, #e5e7eb 75%); background-size: 200% 100%; animation: shimmerLoad 1.5s ease-in-out infinite; border-radius: 8px; }
            .dark .skeleton-block { background: linear-gradient(90deg, #1f2937 25%, #374151 50%, #1f2937 75%); background-size: 200% 100%; }
            .skeleton-text { height: 12px; width: 60%; }
            .skeleton-card { border-radius: 12px; height: 120px; }
            .page-loading-overlay { position: fixed; inset: 0; background: rgba(255,255,255,0.7); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.2s; }
            .dark .page-loading-overlay { background: rgba(17,24,39,0.7); }
            .page-loading-overlay.active { opacity: 1; pointer-events: auto; }
            .main-scroll::-webkit-scrollbar { width: 6px; }
            .main-scroll::-webkit-scrollbar-thumb { background: rgba(156,163,175,0.3); border-radius: 4px; }
            .main-scroll::-webkit-scrollbar-track { background: transparent; }

            [data-theme="purple"] { --nav-bg: linear-gradient(135deg, #1e1b4b 0%, #312e81 40%, #4c1d95 100%); --accent-h: 263; --accent-s: 70%; --accent-l: 50%; --avatar-from: #a78bfa; --avatar-to: #7c3aed; --accent-glow: rgba(124,58,237,0.2); --meta-color: #e0d8ff; --status-text: #d4cbfc; --pill-hover: rgba(255,255,255,0.12); --pill-active: rgba(255,255,255,0.18); }
            [data-theme="blue"] { --nav-bg: linear-gradient(135deg, #0c1929 0%, #1e3a5f 40%, #1d4ed8 100%); --accent-h: 217; --accent-s: 91%; --accent-l: 48%; --avatar-from: #60a5fa; --avatar-to: #2563eb; --accent-glow: rgba(37,99,235,0.2); --meta-color: #c5dcff; --status-text: #b8d4fd; --pill-hover: rgba(255,255,255,0.12); --pill-active: rgba(255,255,255,0.18); }
            [data-theme="emerald"] { --nav-bg: linear-gradient(135deg, #022c22 0%, #064e3b 40%, #047857 100%); --accent-h: 160; --accent-s: 84%; --accent-l: 39%; --avatar-from: #34d399; --avatar-to: #059669; --accent-glow: rgba(5,150,105,0.2); --meta-color: #a7f3d0; --status-text: #95eec5; --pill-hover: rgba(255,255,255,0.12); --pill-active: rgba(255,255,255,0.18); }
            [data-theme="orange"] { --nav-bg: linear-gradient(135deg, #431407 0%, #7c2d12 40%, #c2410c 100%); --accent-h: 21; --accent-s: 90%; --accent-l: 48%; --avatar-from: #fb923c; --avatar-to: #ea580c; --accent-glow: rgba(234,88,12,0.2); --meta-color: #fed7aa; --status-text: #fdcf9c; --pill-hover: rgba(255,255,255,0.12); --pill-active: rgba(255,255,255,0.18); }
            [data-theme="midnight"] { --nav-bg: linear-gradient(135deg, #0a0a0a 0%, #171717 40%, #262626 100%); --accent-h: 0; --accent-s: 0%; --accent-l: 45%; --avatar-from: #a3a3a3; --avatar-to: #525252; --accent-glow: rgba(115,115,115,0.2); --meta-color: #d4d4d4; --status-text: #d4d4d4; --pill-hover: rgba(255,255,255,0.1); --pill-active: rgba(255,255,255,0.15); }
            [data-theme="rose"] { --nav-bg: linear-gradient(135deg, #4c0519 0%, #881337 40%, #be123c 100%); --accent-h: 347; --accent-s: 77%; --accent-l: 50%; --avatar-from: #fb7185; --avatar-to: #e11d48; --accent-glow: rgba(225,29,72,0.2); --meta-color: #fecdd3; --status-text: #fecdd3; --pill-hover: rgba(255,255,255,0.12); --pill-active: rgba(255,255,255,0.18); }

            .topnav-bar { background: var(--nav-bg, linear-gradient(135deg, #1e1b4b 0%, #312e81 40%, #4c1d95 100%)); }
            .nav-pill { transition: all 0.15s ease; }
            .nav-pill:hover { background: var(--pill-hover); }
            .nav-pill.active { background: var(--pill-active); box-shadow: 0 0 0 1px rgba(255,255,255,0.1); }
            .profile-dropdown { animation: slideDown 0.15s ease-out; }
            .menu-link { transition: all 0.1s ease; }
            .menu-link:hover { background: hsla(var(--accent-h), var(--accent-s), var(--accent-l), 0.08); }
            .dark .menu-link:hover { background: hsla(var(--accent-h), var(--accent-s), var(--accent-l), 0.15); }
            .avatar-themed { background: linear-gradient(135deg, var(--avatar-from), var(--avatar-to)); }
            .accent-glow { box-shadow: 0 4px 15px var(--accent-glow); }
            .theme-swatch { width: 28px; height: 28px; border-radius: 8px; cursor: pointer; border: 2px solid transparent; transition: all 0.15s ease; }
            .theme-swatch:hover { transform: scale(1.15); }
            .theme-swatch.active-theme { border-color: white; box-shadow: 0 0 0 2px rgba(255,255,255,0.3); }
            [x-cloak] { display: none !important; }

            /* ═══════════════════════════════════════════════════════════════════
               🎨 UNIVERSAL THEME OVERRIDE — applies selected theme to ALL purple
               Tailwind utilities used across POS views (cart, search, badges,
               buttons, modals, etc.). When data-theme=purple, hardcoded purple-X
               classes match naturally. For any OTHER theme, these rules remap
               purple-X → accent HSL derived from --accent-h / --accent-s.
               Single source of truth — no per-view edits needed.
               ═══════════════════════════════════════════════════════════════════ */
            body:not([data-theme="purple"]) .bg-purple-50  { background-color: hsl(var(--accent-h), var(--accent-s), 97%) !important; }
            body:not([data-theme="purple"]) .bg-purple-100 { background-color: hsl(var(--accent-h), var(--accent-s), 94%) !important; }
            body:not([data-theme="purple"]) .bg-purple-200 { background-color: hsl(var(--accent-h), var(--accent-s), 86%) !important; }
            body:not([data-theme="purple"]) .bg-purple-300 { background-color: hsl(var(--accent-h), var(--accent-s), 76%) !important; }
            body:not([data-theme="purple"]) .bg-purple-400 { background-color: hsl(var(--accent-h), var(--accent-s), 65%) !important; }
            body:not([data-theme="purple"]) .bg-purple-500 { background-color: hsl(var(--accent-h), var(--accent-s), 55%) !important; }
            body:not([data-theme="purple"]) .bg-purple-600 { background-color: hsl(var(--accent-h), var(--accent-s), var(--accent-l)) !important; }
            body:not([data-theme="purple"]) .bg-purple-700 { background-color: hsl(var(--accent-h), var(--accent-s), 40%) !important; }
            body:not([data-theme="purple"]) .bg-purple-800 { background-color: hsl(var(--accent-h), var(--accent-s), 32%) !important; }
            body:not([data-theme="purple"]) .bg-purple-900 { background-color: hsl(var(--accent-h), var(--accent-s), 22%) !important; }

            body:not([data-theme="purple"]) .text-purple-300 { color: hsl(var(--accent-h), var(--accent-s), 76%) !important; }
            body:not([data-theme="purple"]) .text-purple-400 { color: hsl(var(--accent-h), var(--accent-s), 65%) !important; }
            body:not([data-theme="purple"]) .text-purple-500 { color: hsl(var(--accent-h), var(--accent-s), 55%) !important; }
            body:not([data-theme="purple"]) .text-purple-600 { color: hsl(var(--accent-h), var(--accent-s), var(--accent-l)) !important; }
            body:not([data-theme="purple"]) .text-purple-700 { color: hsl(var(--accent-h), var(--accent-s), 35%) !important; }
            body:not([data-theme="purple"]) .text-purple-800 { color: hsl(var(--accent-h), var(--accent-s), 28%) !important; }
            body:not([data-theme="purple"]) .text-purple-900 { color: hsl(var(--accent-h), var(--accent-s), 22%) !important; }

            body:not([data-theme="purple"]) .border-purple-100 { border-color: hsl(var(--accent-h), var(--accent-s), 90%) !important; }
            body:not([data-theme="purple"]) .border-purple-200 { border-color: hsl(var(--accent-h), var(--accent-s), 84%) !important; }
            body:not([data-theme="purple"]) .border-purple-300 { border-color: hsl(var(--accent-h), var(--accent-s), 74%) !important; }
            body:not([data-theme="purple"]) .border-purple-400 { border-color: hsl(var(--accent-h), var(--accent-s), 60%) !important; }
            body:not([data-theme="purple"]) .border-purple-500 { border-color: hsl(var(--accent-h), var(--accent-s), 55%) !important; }
            body:not([data-theme="purple"]) .border-purple-600 { border-color: hsl(var(--accent-h), var(--accent-s), var(--accent-l)) !important; }
            body:not([data-theme="purple"]) .border-purple-700 { border-color: hsl(var(--accent-h), var(--accent-s), 40%) !important; }
            body:not([data-theme="purple"]) .border-purple-800 { border-color: hsl(var(--accent-h), var(--accent-s), 32%) !important; }

            body:not([data-theme="purple"]) .ring-purple-200 { --tw-ring-color: hsl(var(--accent-h), var(--accent-s), 84%) !important; }
            body:not([data-theme="purple"]) .ring-purple-300 { --tw-ring-color: hsl(var(--accent-h), var(--accent-s), 74%) !important; }
            body:not([data-theme="purple"]) .ring-purple-400 { --tw-ring-color: hsl(var(--accent-h), var(--accent-s), 60%) !important; }
            body:not([data-theme="purple"]) .ring-purple-500 { --tw-ring-color: hsl(var(--accent-h), var(--accent-s), 55%) !important; }
            body:not([data-theme="purple"]) .focus\:ring-purple-200:focus { --tw-ring-color: hsl(var(--accent-h), var(--accent-s), 84%) !important; }
            body:not([data-theme="purple"]) .focus\:ring-purple-400:focus { --tw-ring-color: hsl(var(--accent-h), var(--accent-s), 60%) !important; }
            body:not([data-theme="purple"]) .focus\:ring-purple-500:focus { --tw-ring-color: hsl(var(--accent-h), var(--accent-s), 55%) !important; }
            body:not([data-theme="purple"]) .focus\:border-purple-400:focus { border-color: hsl(var(--accent-h), var(--accent-s), 60%) !important; }
            body:not([data-theme="purple"]) .focus\:border-purple-500:focus { border-color: hsl(var(--accent-h), var(--accent-s), 55%) !important; }

            /* Hover variants */
            body:not([data-theme="purple"]) .hover\:bg-purple-50:hover  { background-color: hsl(var(--accent-h), var(--accent-s), 97%) !important; }
            body:not([data-theme="purple"]) .hover\:bg-purple-100:hover { background-color: hsl(var(--accent-h), var(--accent-s), 94%) !important; }
            body:not([data-theme="purple"]) .hover\:bg-purple-500:hover { background-color: hsl(var(--accent-h), var(--accent-s), 55%) !important; }
            body:not([data-theme="purple"]) .hover\:bg-purple-600:hover { background-color: hsl(var(--accent-h), var(--accent-s), var(--accent-l)) !important; }
            body:not([data-theme="purple"]) .hover\:bg-purple-700:hover { background-color: hsl(var(--accent-h), var(--accent-s), 40%) !important; }
            body:not([data-theme="purple"]) .hover\:text-purple-300:hover { color: hsl(var(--accent-h), var(--accent-s), 76%) !important; }
            body:not([data-theme="purple"]) .hover\:text-purple-700:hover { color: hsl(var(--accent-h), var(--accent-s), 35%) !important; }
            body:not([data-theme="purple"]) .hover\:text-purple-800:hover { color: hsl(var(--accent-h), var(--accent-s), 28%) !important; }

            /* Gradient stops (from / via / to) */
            body:not([data-theme="purple"]) .from-purple-50  { --tw-gradient-from: hsl(var(--accent-h), var(--accent-s), 97%) var(--tw-gradient-from-position) !important; --tw-gradient-to: hsl(var(--accent-h) var(--accent-s) 97% / 0) var(--tw-gradient-to-position) !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to) !important; }
            body:not([data-theme="purple"]) .from-purple-100 { --tw-gradient-from: hsl(var(--accent-h), var(--accent-s), 94%) var(--tw-gradient-from-position) !important; --tw-gradient-to: hsl(var(--accent-h) var(--accent-s) 94% / 0) var(--tw-gradient-to-position) !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to) !important; }
            body:not([data-theme="purple"]) .from-purple-500 { --tw-gradient-from: hsl(var(--accent-h), var(--accent-s), 55%) var(--tw-gradient-from-position) !important; --tw-gradient-to: hsl(var(--accent-h) var(--accent-s) 55% / 0) var(--tw-gradient-to-position) !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to) !important; }
            body:not([data-theme="purple"]) .from-purple-600 { --tw-gradient-from: hsl(var(--accent-h), var(--accent-s), var(--accent-l)) var(--tw-gradient-from-position) !important; --tw-gradient-to: hsl(var(--accent-h) var(--accent-s) var(--accent-l) / 0) var(--tw-gradient-to-position) !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to) !important; }
            body:not([data-theme="purple"]) .to-purple-500   { --tw-gradient-to: hsl(var(--accent-h), var(--accent-s), 55%) var(--tw-gradient-to-position) !important; }
            body:not([data-theme="purple"]) .to-purple-600   { --tw-gradient-to: hsl(var(--accent-h), var(--accent-s), var(--accent-l)) var(--tw-gradient-to-position) !important; }
            body:not([data-theme="purple"]) .to-purple-700   { --tw-gradient-to: hsl(var(--accent-h), var(--accent-s), 40%) var(--tw-gradient-to-position) !important; }
            body:not([data-theme="purple"]) .via-purple-500  { --tw-gradient-stops: var(--tw-gradient-from), hsl(var(--accent-h), var(--accent-s), 55%) var(--tw-gradient-via-position), var(--tw-gradient-to) !important; }
            body:not([data-theme="purple"]) .via-purple-600  { --tw-gradient-stops: var(--tw-gradient-from), hsl(var(--accent-h), var(--accent-s), var(--accent-l)) var(--tw-gradient-via-position), var(--tw-gradient-to) !important; }

            /* Dark mode variants — softer / desaturated */
            body:not([data-theme="purple"]) .dark .dark\:bg-purple-900 { background-color: hsl(var(--accent-h), var(--accent-s), 22%) !important; }
            body:not([data-theme="purple"]) .dark .dark\:bg-purple-900\/10 { background-color: hsla(var(--accent-h), var(--accent-s), 22%, 0.1) !important; }
            body:not([data-theme="purple"]) .dark .dark\:bg-purple-900\/20 { background-color: hsla(var(--accent-h), var(--accent-s), 22%, 0.2) !important; }
            body:not([data-theme="purple"]) .dark .dark\:bg-purple-900\/30 { background-color: hsla(var(--accent-h), var(--accent-s), 22%, 0.3) !important; }
            body:not([data-theme="purple"]) .dark .dark\:bg-purple-900\/40 { background-color: hsla(var(--accent-h), var(--accent-s), 22%, 0.4) !important; }
            body:not([data-theme="purple"]) .dark .dark\:bg-purple-900\/50 { background-color: hsla(var(--accent-h), var(--accent-s), 22%, 0.5) !important; }
            body:not([data-theme="purple"]) .dark .dark\:text-purple-300 { color: hsl(var(--accent-h), var(--accent-s), 76%) !important; }
            body:not([data-theme="purple"]) .dark .dark\:text-purple-400 { color: hsl(var(--accent-h), var(--accent-s), 65%) !important; }
            body:not([data-theme="purple"]) .dark .dark\:border-purple-700 { border-color: hsl(var(--accent-h), var(--accent-s), 40%) !important; }
            body:not([data-theme="purple"]) .dark .dark\:border-purple-800 { border-color: hsl(var(--accent-h), var(--accent-s), 32%) !important; }
            body:not([data-theme="purple"]) .dark .dark\:border-purple-900\/30 { border-color: hsla(var(--accent-h), var(--accent-s), 22%, 0.3) !important; }
            body:not([data-theme="purple"]) .dark .dark\:hover\:bg-purple-900\/20:hover { background-color: hsla(var(--accent-h), var(--accent-s), 22%, 0.2) !important; }
            body:not([data-theme="purple"]) .dark .dark\:hover\:text-purple-300:hover { color: hsl(var(--accent-h), var(--accent-s), 76%) !important; }

            /* Misc — opacity variants of bg-purple-X used for soft pill backgrounds */
            body:not([data-theme="purple"]) .bg-purple-100\/50 { background-color: hsla(var(--accent-h), var(--accent-s), 94%, 0.5) !important; }
            body:not([data-theme="purple"]) .bg-purple-500\/20 { background-color: hsla(var(--accent-h), var(--accent-s), 55%, 0.2) !important; }
            body:not([data-theme="purple"]) .bg-purple-500\/30 { background-color: hsla(var(--accent-h), var(--accent-s), 55%, 0.3) !important; }
            body:not([data-theme="purple"]) .bg-purple-600\/20 { background-color: hsla(var(--accent-h), var(--accent-s), var(--accent-l), 0.2) !important; }

            /* Desktop-app polish */
            html, body { overscroll-behavior: none; -webkit-tap-highlight-color: transparent; }
            .topnav-bar, .topnav-bar * , .nav-pill, .profile-dropdown, kbd { -webkit-user-select: none; user-select: none; }
            input, textarea, [contenteditable], .allow-select, .allow-select * { -webkit-user-select: text; user-select: text; }
            ::-webkit-scrollbar { width: 8px; height: 8px; }
            ::-webkit-scrollbar-track { background: transparent; }
            ::-webkit-scrollbar-thumb { background: rgba(148,163,184,0.4); border-radius: 8px; border: 2px solid transparent; background-clip: content-box; }
            ::-webkit-scrollbar-thumb:hover { background: rgba(148,163,184,0.7); border: 2px solid transparent; background-clip: content-box; }
            .dark ::-webkit-scrollbar-thumb { background: rgba(71,85,105,0.6); border: 2px solid transparent; background-clip: content-box; }
            html { scrollbar-width: thin; scrollbar-color: rgba(148,163,184,0.5) transparent; }

            /* Fullscreen mode polish */
            body.is-fullscreen { background: #0a0a0a; }
            body.is-fullscreen .topnav-bar { box-shadow: 0 4px 20px rgba(0,0,0,0.4); }

            /* Network/PRA status pulse */
            @keyframes statusPulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.6; transform: scale(0.85); } }
            .status-dot-live { animation: statusPulse 2s ease-in-out infinite; }

            /* Command palette */
            .cmd-palette-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,0.55); backdrop-filter: blur(8px); z-index: 9998; display: flex; align-items: flex-start; justify-content: center; padding-top: 12vh; }
            .dark .cmd-palette-backdrop { background: rgba(0,0,0,0.7); }
            .cmd-palette-card { width: min(640px, 92vw); background: white; border-radius: 16px; box-shadow: 0 25px 60px -10px rgba(0,0,0,0.4), 0 0 0 1px rgba(0,0,0,0.05); overflow: hidden; animation: cmdPaletteIn 0.18s cubic-bezier(0.16, 1, 0.3, 1); }
            .dark .cmd-palette-card { background: #0f172a; box-shadow: 0 25px 60px -10px rgba(0,0,0,0.6), 0 0 0 1px rgba(255,255,255,0.06); }
            @keyframes cmdPaletteIn { from { opacity: 0; transform: translateY(-12px) scale(0.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
            .cmd-item { display: flex; align-items: center; gap: 12px; padding: 10px 16px; cursor: pointer; transition: background 0.1s ease; font-size: 13px; }
            .cmd-item:hover, .cmd-item.cmd-active { background: hsla(var(--accent-h), var(--accent-s), 95%, 0.6); }
            .dark .cmd-item:hover, .dark .cmd-item.cmd-active { background: hsla(var(--accent-h), var(--accent-s), var(--accent-l), 0.18); }
            .cmd-icon { width: 28px; height: 28px; border-radius: 8px; background: hsla(var(--accent-h), var(--accent-s), 92%, 1); color: hsla(var(--accent-h), var(--accent-s), 35%, 1); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
            .dark .cmd-icon { background: hsla(var(--accent-h), var(--accent-s), var(--accent-l), 0.2); color: hsla(var(--accent-h), var(--accent-s), 75%, 1); }
            .cmd-kbd { font-family: 'SF Mono', Menlo, monospace; font-size: 10px; background: rgba(148,163,184,0.18); padding: 2px 6px; border-radius: 4px; color: inherit; }
            /* Sale-screen nav tools strip: hide the scrollbar (it scrolls when narrow);
               align-self stretch = full 48px nav height so button count-badges (-top-1)
               don't get clipped by the overflow container. (Inline CSS, not Tailwind
               self-stretch — that utility isn't in the current Vite build.) */
            #tn-nav-sale-tools { scrollbar-width: none; -ms-overflow-style: none; align-self: stretch; }
            #tn-nav-sale-tools::-webkit-scrollbar { display: none; }
        </style>
        {{-- PWA service worker (NestPOS scope) --}}
        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => {
                    navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' }).catch(()=>{});
                });
            }
        </script>
    </head>
    <body class="pos-layout-root h-screen overflow-hidden antialiased" data-theme="{{ $posTheme }}"@if(($companyLayout->pos_dashboard_style ?? 'default') === 'saaf') data-saaf="1"@endif>
        @include('partials.impersonation-banner')
        <x-pwa-init />
        <div class="flex flex-col h-full" x-data="{ profileOpen: false, mobileMenuOpen: false, themeOpen: false, currentTheme: '{{ $posTheme }}', guidedOn: {{ ($companyLayout->pos_guided_flow_enabled ?? true) ? 'true' : 'false' }} }" @keydown.escape.window="profileOpen = false; mobileMenuOpen = false; themeOpen = false">

            <header class="topnav-bar flex-shrink-0 relative z-50">
                <div class="flex items-center justify-between px-3 sm:px-5 h-12">

                    <div class="flex items-center gap-3 flex-shrink-0">
                        <a href="{{ $isRestaurantLayout ? route('pos.restaurant.dashboard') : route('pos.dashboard') }}" class="flex items-center gap-2 group">
                            <div class="w-7 h-7 rounded-lg bg-white/15 flex items-center justify-center group-hover:bg-white/25 transition">
                                <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            </div>
                            <div class="hidden sm:block">
                                <span class="text-sm font-extrabold text-white tracking-tight">NestPOS</span>
                                <span class="text-[9px] text-white ml-1 hidden lg:inline font-medium">Enterprise</span>
                            </div>
                        </a>

                        <div class="h-5 w-px bg-white/10 hidden md:block"></div>

                        <nav class="hidden md:flex items-center gap-1">
                            {{-- Sale-screen redesign (Jul 2026): on the sale screen itself the static
                                 "New Sale" link is replaced by the teleported action button (newSale())
                                 that lands in #tn-nav-sale-tools below — see universal.blade.php. --}}
                            @unless(request()->routeIs('pos.invoice.create'))
                            <a href="{{ route('pos.invoice.create') }}"
                               class="nav-pill flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-[11px] font-medium text-white">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4v16m8-8H4"/></svg>
                                {{ __('pos.new_sale') }}
                            </a>
                            @endunless
                            @if(($companyLayout->pos_dashboard_style ?? 'default') === 'saaf')
                            {{-- Saaf dashboard (Jul 2026): simplified always-visible nav — 5 core links; everything else stays in the profile menu. --}}
                            @if($posNavCan('dashboard'))
                            <a href="{{ $isRestaurantLayout ? route('pos.restaurant.dashboard') : route('pos.dashboard') }}"
                               class="nav-pill px-2.5 py-1.5 rounded-lg text-[11px] font-medium {{ request()->routeIs('pos.dashboard') || request()->routeIs('pos.restaurant.dashboard') ? 'active text-white' : 'text-white' }}">{{ __('pos.nav_home') }}</a>
                            @endif
                            @if($posNavCan('orders'))
                            <a href="{{ route('pos.transactions') }}"
                               class="nav-pill px-2.5 py-1.5 rounded-lg text-[11px] font-medium {{ request()->routeIs('pos.transactions') ? 'active text-white' : 'text-white' }}">{{ __('pos.nav_bills') }}</a>
                            @endif
                            @if($posNavCan('products'))
                            <a href="{{ route('pos.products') }}"
                               class="nav-pill px-2.5 py-1.5 rounded-lg text-[11px] font-medium {{ request()->routeIs('pos.products') ? 'active text-white' : 'text-white' }}">{{ __('pos.products_word') }}</a>
                            @endif
                            @if($posNavCan('reports'))
                            <a href="{{ route('pos.reports') }}"
                               class="nav-pill px-2.5 py-1.5 rounded-lg text-[11px] font-medium {{ request()->routeIs('pos.reports') ? 'active text-white' : 'text-white' }}">{{ __('pos.reports') }}</a>
                            @endif
                            @if($posNavCan('customize', !$isCashierLayout))
                            <a href="{{ route('pos.customize') }}"
                               class="nav-pill px-2.5 py-1.5 rounded-lg text-[11px] font-medium {{ request()->routeIs('pos.customize') ? 'active text-white' : 'text-white' }}">{{ __('pos.settings') }}</a>
                            @endif
                            @endif
                        </nav>

                        <div class="hidden md:block ml-1">
                            <x-branch-switcher color="purple" />
                        </div>
                    </div>

                    {{-- Sale-screen tools anchor (Jul 2026 redesign): universal.blade.php teleports
                         its utility pills (Local/Failed/Reprint/Held), + New Sale and the switches
                         dropdown here via x-teleport, so they keep restaurantPos() Alpine scope.
                         Empty and harmless on every other POS page. --}}
                    {{-- overflow-x-auto (scrollbar hidden) + mx-auto on the teleported child replace
                         justify-center: centered when it fits, scrollable when narrow — pills must
                         NEVER spill over the right-side user group (ZFC overlap bug, 26 Jul 2026). --}}
                    <div id="tn-nav-sale-tools" class="hidden md:flex items-center gap-1.5 min-w-0 flex-1 px-2 overflow-x-auto"></div>

                    <div class="flex items-center gap-2 flex-shrink-0" x-data="{ isFs: false }"
                         x-init="
                            document.addEventListener('fullscreenchange', () => isFs = !!document.fullscreenElement);
                            isFs = !!document.fullscreenElement;
                         ">
                        {{-- Net/PRA/clock status cluster REMOVED (owner, 26 Jul 2026) — sale screen
                             already has its own Auto-Sync Online/Offline pill; do not re-add. --}}

                        <button @click="$dispatch('open-cmd-palette')" class="hidden md:flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-white hover:bg-white/15 transition group" title="{{ __('pos.ti_quick_command') }}">
                            <svg class="w-3.5 h-3.5 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <span class="text-[10px] font-medium opacity-80">{{ __('pos.search_label') }}</span>
                            <kbd class="text-[9px] font-mono bg-white/10 px-1.5 py-0.5 rounded opacity-90 group-hover:opacity-100">⌘K</kbd>
                        </button>

                        {{-- Prominent nav-level Download App button — native prompt first, instructions fallback, installed state --}}
                        <x-pwa-install-menu-item color="teal" app-name="Nest POS" :label="__('pos.download_app')" item-class="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-[11px] font-bold uppercase tracking-wide text-white bg-white/10 hover:bg-white/20 ring-1 ring-white/20 transition" />
                        <x-pwa-refresh-btn color="purple" />

                        <button @click="if(!document.fullscreenElement){document.documentElement.requestFullscreen().catch(()=>{}); document.body.classList.add('is-fullscreen');} else {document.exitFullscreen(); document.body.classList.remove('is-fullscreen');}"
                                class="p-2 rounded-lg text-white hover:bg-white/15 transition" :title="isFs ? '{{ __('pos.ti_exit_fullscreen') }}' : '{{ __('pos.ti_fullscreen') }}'">
                            <svg x-show="!isFs" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-5h-4m4 0v4m0-4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>
                            <svg x-show="isFs" x-cloak class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 9V5m0 4H5m10 0V5m0 4h4M9 15v4m0-4H5m10 0v4m0-4h4"/></svg>
                        </button>

                        <div class="relative">
                            <button @click="themeOpen = !themeOpen; profileOpen = false" class="p-2 rounded-lg text-white hover:bg-white/15 transition" title="{{ __('pos.ti_change_theme') }}">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                            </button>
                            <div x-show="themeOpen" x-cloak @click.outside="themeOpen = false" x-transition class="absolute right-0 top-full mt-2 bg-white dark:bg-gray-900 rounded-xl shadow-2xl shadow-black/20 border border-gray-200/80 dark:border-gray-700/80 p-3 z-[100] w-48">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-2">{{ __('pos.pos_theme_heading') }}</p>
                                <div class="grid grid-cols-3 gap-2">
                                    <button @click="currentTheme='purple'; document.body.setAttribute('data-theme','purple'); fetch('/pos/settings/theme', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'purple'})}); themeOpen=false" class="theme-swatch" :class="currentTheme==='purple' && 'active-theme'" style="background:linear-gradient(135deg,#312e81,#7c3aed)" title="{{ __('pos.theme_royal_purple') }}"></button>
                                    <button @click="currentTheme='blue'; document.body.setAttribute('data-theme','blue'); fetch('/pos/settings/theme', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'blue'})}); themeOpen=false" class="theme-swatch" :class="currentTheme==='blue' && 'active-theme'" style="background:linear-gradient(135deg,#1e3a5f,#2563eb)" title="{{ __('pos.theme_ocean_blue') }}"></button>
                                    <button @click="currentTheme='emerald'; document.body.setAttribute('data-theme','emerald'); fetch('/pos/settings/theme', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'emerald'})}); themeOpen=false" class="theme-swatch" :class="currentTheme==='emerald' && 'active-theme'" style="background:linear-gradient(135deg,#064e3b,#059669)" title="{{ __('pos.theme_emerald_green') }}"></button>
                                    <button @click="currentTheme='orange'; document.body.setAttribute('data-theme','orange'); fetch('/pos/settings/theme', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'orange'})}); themeOpen=false" class="theme-swatch" :class="currentTheme==='orange' && 'active-theme'" style="background:linear-gradient(135deg,#7c2d12,#ea580c)" title="{{ __('pos.theme_sunset_orange') }}"></button>
                                    <button @click="currentTheme='midnight'; document.body.setAttribute('data-theme','midnight'); fetch('/pos/settings/theme', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'midnight'})}); themeOpen=false" class="theme-swatch" :class="currentTheme==='midnight' && 'active-theme'" style="background:linear-gradient(135deg,#171717,#404040)" title="{{ __('pos.theme_midnight_dark') }}"></button>
                                    <button @click="currentTheme='rose'; document.body.setAttribute('data-theme','rose'); fetch('/pos/settings/theme', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'rose'})}); themeOpen=false" class="theme-swatch" :class="currentTheme==='rose' && 'active-theme'" style="background:linear-gradient(135deg,#881337,#e11d48)" title="{{ __('pos.theme_rose_pink') }}"></button>
                                </div>
                                <div class="mt-2 pt-2 border-t border-gray-100 dark:border-gray-800">
                                    <p class="text-[9px] text-gray-400 text-center" x-text="currentTheme.charAt(0).toUpperCase() + currentTheme.slice(1) + ' Theme'"></p>
                                </div>
                            </div>
                        </div>

                        <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden p-2 rounded-lg text-white hover:bg-white/15 transition">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        </button>

                        @if($praEnabledLayout && $companyLayout)
                            @php
                                // Submission mode pill — Agent Sync vs Direct (agentHandlesPra(),
                                // NOT agent_enabled: Direct shops may keep the agent for printing).
                                $agentOn = $companyLayout->agentHandlesPra();
                                $agentOnline = $agentOn && $companyLayout->agent_last_seen
                                    && \Carbon\Carbon::parse($companyLayout->agent_last_seen)->gt(now()->subMinutes(2));
                            @endphp
                            {{-- Agent page = customize feature; members denied customize see no link (nav stays in sync with the route gate). --}}
                            @if($posNavCan('customize'))
                            <a href="{{ route('pos.agent') }}"
                               title="{{ $agentOn ? ($agentOnline ? __('pos.ti_agent_sync_online') : __('pos.ti_agent_sync_offline')) : __('pos.ti_direct_production') }}"
                               class="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider transition border
                                      {{ $agentOn
                                            ? ($agentOnline ? 'bg-purple-500/20 text-purple-100 border-purple-300/40 hover:bg-purple-500/30' : 'bg-amber-500/20 text-amber-100 border-amber-300/40 hover:bg-amber-500/30 animate-pulse')
                                            : 'bg-teal-500/20 text-teal-100 border-teal-300/40 hover:bg-teal-500/30' }}">
                                @if($agentOn)
                                    <span class="w-1.5 h-1.5 rounded-full {{ $agentOnline ? 'bg-emerald-400' : 'bg-red-400 animate-pulse' }}"></span>
                                    {{ __('pos.agent_badge') }}
                                @else
                                    {{ __('pos.direct_badge') }}
                                @endif
                            </a>
                            @endif
                        @endif

                        @php
                            // ADMIN/MANAGER ONLY (owner rule, Jul 2026): the suggestion box is
                            // the owner's channel — cashier screens never show it. This also
                            // keeps confined roles (kitchen/waiter/rider) out as before.
                            $suggAllowed = $posUserLayout && $posUserLayout->isPosAdmin();
                        @endphp
                        @if($suggAllowed)
                        {{-- Feature Suggestion box — customers tell us what to build next --}}
                        <a href="{{ route('pos.suggestions') }}" title="{{ __('pos.ti_feature_suggestion') }}"
                           class="relative p-2 rounded-xl text-white hover:bg-white/10 transition cursor-pointer {{ request()->is('pos/suggestions') ? 'bg-white/15' : '' }}">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                        </a>
                        @endif

                        @if($whatsNewList->isNotEmpty())
                        {{-- What's New bell — updates history (opening marks all as seen) --}}
                        <div class="relative" x-data="{ bellOpen: false, unseen: {{ (int) $whatsNewUnseenCount }},
                                toggleBell() {
                                    this.bellOpen = !this.bellOpen;
                                    if (this.bellOpen && this.unseen > 0) {
                                        this.unseen = 0;
                                        fetch('/pos/whats-new/seen', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' } }).catch(() => {});
                                    }
                                } }">
                            <button @click="toggleBell()" title="{{ __('pos.ti_app_updates') }}" class="relative p-2 rounded-xl text-white hover:bg-white/10 transition cursor-pointer">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                                <span x-show="unseen > 0" x-cloak x-text="unseen"
                                      class="absolute rounded-full bg-red-500 text-white font-bold flex items-center justify-center"
                                      style="top: 1px; right: 1px; min-width: 16px; height: 16px; padding: 0 4px; font-size: 9px;"></span>
                            </button>

                            <div x-show="bellOpen" x-cloak @click.outside="bellOpen = false"
                                 x-transition:enter="transition ease-out duration-150"
                                 x-transition:enter-start="opacity-0 -translate-y-2 scale-95"
                                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                                 x-transition:leave="transition ease-in duration-100"
                                 x-transition:leave-start="opacity-100"
                                 x-transition:leave-end="opacity-0 scale-95"
                                 class="profile-dropdown absolute right-0 top-full mt-2 w-80 bg-white dark:bg-gray-900 rounded-xl shadow-2xl shadow-black/20 border border-gray-200/80 dark:border-gray-700/80 overflow-hidden z-[100]">
                                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800" style="background: linear-gradient(to right, hsla(var(--accent-h), var(--accent-s), 95%, 1), hsla(var(--accent-h), var(--accent-s), 92%, 1))">
                                    <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.app_updates_heading') }}</p>
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.app_updates_subtitle') }}</p>
                                </div>
                                <div class="max-h-96 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach($whatsNewList as $wnu)
                                        <div class="px-4 py-3">
                                            <div class="flex items-center justify-between gap-2">
                                                <p class="text-[13px] font-semibold text-gray-800 dark:text-gray-100">{{ $wnu->title }}</p>
                                                @if(!in_array($wnu->id, $whatsNewSeenIds))
                                                    <span class="flex-shrink-0 px-1.5 py-0.5 rounded-full bg-red-500 text-white text-[9px] font-bold uppercase">{{ __('pos.new_word') }}</span>
                                                @endif
                                            </div>
                                            <p class="text-[10px] text-gray-400 mt-0.5">{{ $wnu->created_at->format('d M Y') }}</p>
                                            @if($wnu->image_path ?? null)
                                                <img src="{{ asset('storage/' . $wnu->image_path) }}" alt="{{ __('pos.update_image_alt') }}" loading="lazy"
                                                     class="w-full rounded-lg border border-gray-200 dark:border-gray-700 mt-1.5 cursor-zoom-in"
                                                     onclick="window.open(this.src, '_blank')">
                                            @endif
                                            <ul class="mt-1.5 space-y-1">
                                                @foreach(($wnu->points ?? []) as $wnp)
                                                    <li class="flex items-start gap-1.5 text-[12px] text-gray-600 dark:text-gray-300">
                                                        <svg class="w-3 h-3 mt-0.5 flex-shrink-0 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                        <span>{{ $wnp }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                        @endif

                        <div class="relative">
                            <button @click="profileOpen = !profileOpen; themeOpen = false"
                                    class="flex items-center gap-2 px-2 py-1.5 rounded-xl hover:bg-white/10 transition cursor-pointer">
                                <div class="w-7 h-7 rounded-lg avatar-themed flex items-center justify-center text-[11px] font-bold text-white accent-glow">
                                    {{ $userInitial }}
                                </div>
                                <div class="hidden sm:block text-left">
                                    <p class="text-[11px] font-semibold text-white leading-tight">{{ Str::limit($userName, 15) }}</p>
                                    <p class="text-[9px] leading-tight" style="color: var(--status-text)">{{ $userRole }} · {{ Str::limit($companyName, 18) }}</p>
                                </div>
                                <svg class="w-3 h-3 hidden sm:block transition-transform" style="color: var(--meta-color)" :class="profileOpen && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>

                            <div x-show="profileOpen" x-cloak @click.outside="profileOpen = false"
                                 x-transition:enter="transition ease-out duration-150"
                                 x-transition:enter-start="opacity-0 -translate-y-2 scale-95"
                                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                                 x-transition:leave="transition ease-in duration-100"
                                 x-transition:leave-start="opacity-100"
                                 x-transition:leave-end="opacity-0 scale-95"
                                 class="profile-dropdown absolute right-0 top-full mt-2 w-64 bg-white dark:bg-gray-900 rounded-xl shadow-2xl shadow-black/20 border border-gray-200/80 dark:border-gray-700/80 overflow-hidden z-[100]">

                                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800" style="background: linear-gradient(to right, hsla(var(--accent-h), var(--accent-s), 95%, 1), hsla(var(--accent-h), var(--accent-s), 92%, 1))">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-xl avatar-themed flex items-center justify-center text-sm font-bold text-white">{{ $userInitial }}</div>
                                        <div>
                                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ $userName }}</p>
                                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $userRole }} · {{ $companyName }}</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="py-1.5 max-h-[65vh] overflow-y-auto">
                                    <div class="px-3 pt-2 pb-1">
                                        <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-600">{{ __('pos.nav_quick_access') }}</p>
                                    </div>
                                    @if($posNavCan('dashboard'))
                                    <a href="{{ $isRestaurantLayout ? route('pos.restaurant.dashboard') : route('pos.dashboard') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                                        {{ __('pos.dashboard') }}
                                    </a>
                                    @endif
                                    @if($posNavCan('orders'))
                                    <a href="{{ route('pos.transactions') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                        {{ __('pos.nav_orders') }}
                                    </a>
                                    @endif
                                    @if($posNavCan('products'))
                                    <a href="{{ route('pos.products') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                        {{ __('pos.products_word') }}
                                    </a>
                                    @endif
                                    @if($posNavCan('customers'))
                                    <a href="{{ route('pos.customers') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        {{ __('pos.nav_customers') }}
                                    </a>
                                    @endif
                                    @if($posFeaturesLayout->tables && $posNavCan('tables', !$isCashierLayout))
                                    <a href="{{ route('pos.restaurant.tables') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 12h16M4 18h16"/></svg>
                                        {{ __('pos.nav_tables') }}
                                    </a>
                                    @endif
                                    @if($posFeaturesLayout->kitchen && $posNavCan('kitchen', !$isCashierLayout))
                                    <a href="{{ route('pos.restaurant.kds') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                        {{ __('pos.nav_kitchen_display') }}
                                    </a>
                                    @endif
                                    {{-- Delivery Riders (Jul 2026): board visible to cashiers too (they receive rider cash); Riders CRUD admin-only. Plan gate: Pro+ (Aug 2026 matrix). --}}
                                    @if(!empty($posFeaturesLayout->delivery) && \App\Services\PosFeatureService::planAllows($companyLayout, 'riders_enabled') && $posNavCan('deliveries'))
                                    <a href="{{ route('pos.deliveries') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a2 2 0 104 0m-4 0a2 2 0 11-4 0m10 0a2 2 0 104 0"/></svg>
                                        {{ __('pos.nav_deliveries') }}
                                    </a>
                                    @endif
                                    @if(!empty($posFeaturesLayout->delivery) && \App\Services\PosFeatureService::planAllows($companyLayout, 'riders_enabled') && $posNavCan('riders', !$isCashierLayout))
                                    <a href="{{ route('pos.riders') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                        {{ __('pos.nav_riders') }}
                                    </a>
                                    @endif

                                    @if($posNavCan('reports') || $posNavCan('tax_reports') || $posNavCan('day_close') || ($isRestaurantLayout && $posNavCan('reports')))
                                    <div class="px-3 pt-3 pb-1">
                                        <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-600">{{ __('pos.reports') }}</p>
                                    </div>
                                    @endif
                                    @if($posNavCan('reports'))
                                    <a href="{{ route('pos.reports') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                                        {{ __('pos.nav_sales_reports') }}
                                    </a>
                                    @endif
                                    @if($posNavCan('tax_reports'))
                                    <a href="{{ route('pos.tax-reports') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                                        {{ __('pos.nav_tax_reports') }}
                                    </a>
                                    @endif
                                    @if ($isRestaurantLayout && $posNavCan('reports'))
                                    <a href="{{ route('pos.restaurant.cancelled-orders') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        {{ __('pos.cancelled_orders') }}
                                    </a>
                                    @endif
                                    @if($posNavCan('day_close'))
                                    <a href="{{ route('pos.day-close') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        {{ __('pos.nav_day_close') }}
                                    </a>
                                    @endif

                                    {{-- Company admin ALWAYS sees Inventory links (full visibility);
                                         pages redirect to POS Features with a prompt when the module is OFF. --}}
                                    @if($posNavCan('inventory', !$isCashierLayout))
                                    <div class="px-3 pt-3 pb-1">
                                        <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-600">{{ __('pos.nav_inventory') }}
                                            @if(!$inventoryEnabledLayout)<span class="ml-1 normal-case font-medium text-[8px] px-1 py-0.5 rounded bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-400">{{ __('pos.off_badge') }}</span>@endif
                                        </p>
                                    </div>
                                    <a href="{{ route('pos.inventory.dashboard') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                                        {{ __('pos.nav_stock_overview') }}
                                    </a>
                                    <a href="{{ route('pos.inventory.stock') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                        {{ __('pos.nav_stock_levels') }}
                                    </a>
                                    <a href="{{ route('pos.inventory.movements') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
                                        {{ __('pos.nav_movements') }}
                                    </a>
                                    <a href="{{ route('pos.inventory.low-stock') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                                        {{ __('pos.nav_low_stock_alerts') }}
                                    </a>
                                    @endif

                                    @if($isRestaurantLayout && $posNavCan('inventory', !$isCashierLayout))
                                    <div class="px-3 pt-3 pb-1">
                                        <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-600">{{ __('pos.nav_restaurant') }}</p>
                                    </div>
                                    <a href="{{ route('pos.restaurant.ingredients') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                                        {{ __('pos.nav_ingredients') }}
                                    </a>
                                    <a href="{{ route('pos.restaurant.recipes') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                                        {{ __('pos.nav_recipes') }}
                                    </a>
                                    @endif

                                    @if($posNavCan('customize', !$isCashierLayout))
                                    <div class="px-3 pt-3 pb-1">
                                        <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-600">{{ __('pos.settings') }}</p>
                                    </div>
                                    <a href="{{ route('pos.customize') }}" class="menu-link flex items-center gap-2.5 px-4 py-2.5 text-[12px] font-bold text-gray-800 dark:text-gray-100 {{ request()->routeIs('pos.customize') ? 'bg-purple-50 dark:bg-purple-900/20' : '' }}">
                                        <svg class="w-4 h-4 text-purple-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        {{ __('pos.nav_customize_pos') }}
                                        <span class="ml-auto text-[9px] px-1.5 py-0.5 bg-purple-500 text-white rounded font-bold uppercase tracking-wider">{{ __('pos.all_settings_badge') }}</span>
                                    </a>
                                    @endif

                                    @if($isCashierLayout)
                                    <a href="{{ route('pos.user-profile') }}" class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                        {{ __('pos.nav_my_profile') }}
                                    </a>
                                    @endif

                                    {{-- Language picker (30 Jul 2026) — per-user Roman Urdu / English --}}
                                    <div class="px-3 pt-3 pb-1">
                                        <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-600">{{ __('pos.language') }}</p>
                                    </div>
                                    <div class="px-4 py-1.5 flex gap-2">
                                        @php $tnCurLang = app()->getLocale(); @endphp
                                        <form method="POST" action="{{ route('pos.set-language') }}" class="flex-1">
                                            @csrf
                                            <input type="hidden" name="language" value="ur">
                                            <button type="submit" class="w-full px-2 py-1.5 rounded-lg text-[11px] font-bold border transition {{ $tnCurLang === 'ur' ? 'bg-purple-600 text-white border-purple-600' : 'text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800' }}">{{ __('pos.language_roman_urdu') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('pos.set-language') }}" class="flex-1">
                                            @csrf
                                            <input type="hidden" name="language" value="en">
                                            <button type="submit" class="w-full px-2 py-1.5 rounded-lg text-[11px] font-bold border transition {{ $tnCurLang === 'en' ? 'bg-purple-600 text-white border-purple-600' : 'text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800' }}">{{ __('pos.language_english') }}</button>
                                        </form>
                                    </div>

                                    {{-- PWA install — always visible for every POS user --}}
                                    <div class="px-3 pt-3 pb-1">
                                        <p class="text-[9px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-600">{{ __('pos.nav_app') }}</p>
                                    </div>
                                    <x-pwa-install-menu-item color="purple" app-name="Nest POS" :label="__('pos.install_app_device')" item-class="menu-link flex items-center gap-2.5 px-4 py-2 text-[12px] font-medium text-gray-700 dark:text-gray-300" />
                                </div>

                                <div class="border-t border-gray-100 dark:border-gray-800 p-2">
                                    <form method="POST" action="/pos/logout">
                                        @csrf
                                        <button type="submit" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-[12px] font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                            {{ __('pos.sign_out') }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div x-show="mobileMenuOpen" x-cloak
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-100"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0 -translate-y-2"
                     class="md:hidden border-t border-white/10 px-3 py-2 flex flex-wrap gap-1.5" style="background: hsla(var(--accent-h), var(--accent-s), 10%, 0.9)">
                    <a href="{{ route('pos.invoice.create') }}" class="nav-pill px-3 py-1.5 rounded-lg text-[11px] font-medium text-white">{{ __('pos.new_sale') }}</a>
                    @if(($companyLayout->pos_dashboard_style ?? 'default') === 'saaf')
                    @if($posNavCan('dashboard'))
                    <a href="{{ $isRestaurantLayout ? route('pos.restaurant.dashboard') : route('pos.dashboard') }}" class="nav-pill px-3 py-1.5 rounded-lg text-[11px] font-medium text-white">{{ __('pos.nav_home') }}</a>
                    @endif
                    @if($posNavCan('orders'))
                    <a href="{{ route('pos.transactions') }}" class="nav-pill px-3 py-1.5 rounded-lg text-[11px] font-medium text-white">{{ __('pos.nav_bills') }}</a>
                    @endif
                    @if($posNavCan('products'))
                    <a href="{{ route('pos.products') }}" class="nav-pill px-3 py-1.5 rounded-lg text-[11px] font-medium text-white">{{ __('pos.products_word') }}</a>
                    @endif
                    @if($posNavCan('reports'))
                    <a href="{{ route('pos.reports') }}" class="nav-pill px-3 py-1.5 rounded-lg text-[11px] font-medium text-white">{{ __('pos.reports') }}</a>
                    @endif
                    @if($posNavCan('customize', !$isCashierLayout))
                    <a href="{{ route('pos.customize') }}" class="nav-pill px-3 py-1.5 rounded-lg text-[11px] font-medium text-white">{{ __('pos.settings') }}</a>
                    @endif
                    @endif
                    <x-pwa-install-menu-item color="teal" app-name="Nest POS" :label="__('pos.download_app')" item-class="nav-pill inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold text-white bg-white/10 ring-1 ring-white/20" />
                </div>
            </header>

            <main class="flex-1 overflow-y-auto overflow-x-hidden main-scroll bg-slate-50 dark:bg-gray-950 page-fade" style="min-width: 0;">
                <x-trial-reminder-banner />
                <x-payment-status-banner />
                <x-trial-restaurant-notice />
                @if(session('success'))
                    <div class="max-w-7xl mx-auto px-4 sm:px-6 pt-4">
                        <div class="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-3 rounded-xl text-sm">
                            {{ session('success') }}
                        </div>
                    </div>
                @endif
                @if(session('error'))
                    <div class="max-w-7xl mx-auto px-4 sm:px-6 pt-4">
                        <div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-xl text-sm">
                            {{ session('error') }}
                        </div>
                    </div>
                @endif

                <div class="p-4 sm:p-6">
                    {{ $slot }}
                </div>
            </main>
        </div>

        <div id="pageLoadOverlay" class="page-loading-overlay">
            <div class="flex flex-col items-center gap-3">
                <div class="w-8 h-8 border-3 border-purple-600 border-t-transparent rounded-full animate-spin"></div>
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('pos.loading_dots') }}</span>
            </div>
        </div>

        {{-- Command Palette (Ctrl+K / Cmd+K) --}}
        <div x-data="{
                open: false,
                q: '',
                idx: 0,
                items: @js(array_values(array_filter([
                    ['label' => __('pos.new_sale'), 'url' => route('pos.invoice.create'), 'icon' => '+', 'kbd' => ''],
                    $posNavCan('dashboard') ? ['label' => __('pos.dashboard'), 'url' => $isRestaurantLayout ? route('pos.restaurant.dashboard') : route('pos.dashboard'), 'icon' => '◧', 'kbd' => ''] : null,
                    $posNavCan('orders') ? ['label' => __('pos.nav_orders_transactions'), 'url' => route('pos.transactions'), 'icon' => '☰', 'kbd' => ''] : null,
                    $posNavCan('products') ? ['label' => __('pos.products_word'), 'url' => route('pos.products'), 'icon' => '◫', 'kbd' => ''] : null,
                    $posNavCan('customers') ? ['label' => __('pos.nav_customers'), 'url' => route('pos.customers'), 'icon' => '◉', 'kbd' => ''] : null,
                    $posNavCan('reports') ? ['label' => __('pos.reports'), 'url' => route('pos.reports'), 'icon' => '▤', 'kbd' => ''] : null,
                    $posNavCan('day_close') ? ['label' => __('pos.nav_day_close'), 'url' => route('pos.day-close'), 'icon' => '◆', 'kbd' => ''] : null,
                    $posNavCan('customize') ? ['label' => __('pos.nav_billing_plan'), 'url' => route('pos.billing'), 'icon' => '₨', 'kbd' => ''] : null,
                    $posNavCan('customize') ? ['label' => __('pos.nav_business_profile'), 'url' => route('pos.business-profile'), 'icon' => '◎', 'kbd' => ''] : null,
                    ['label' => __('pos.cmd_toggle_fullscreen'), 'action' => 'fullscreen', 'icon' => '⛶', 'kbd' => 'F11'],
                    ['label' => __('pos.cmd_toggle_dark_mode'), 'action' => 'darkmode', 'icon' => '☾', 'kbd' => ''],
                ]))),
                get filtered() { return this.q.trim() === '' ? this.items : this.items.filter(i => i.label.toLowerCase().includes(this.q.toLowerCase())); },
                run(item) {
                    this.open = false; this.q = ''; this.idx = 0;
                    if (item.action === 'fullscreen') {
                        if (!document.fullscreenElement) { document.documentElement.requestFullscreen().catch(()=>{}); document.body.classList.add('is-fullscreen'); }
                        else { document.exitFullscreen(); document.body.classList.remove('is-fullscreen'); }
                    } else if (item.action === 'darkmode') {
                        document.documentElement.classList.toggle('dark');
                    } else if (item.url) {
                        window.location.href = item.url;
                    }
                }
             }"
             @open-cmd-palette.window="open = true; q = ''; idx = 0; $nextTick(() => $refs.cmdInput && $refs.cmdInput.focus())"
             @keydown.window.prevent.ctrl.k="open = true; q = ''; idx = 0; $nextTick(() => $refs.cmdInput && $refs.cmdInput.focus())"
             @keydown.window.prevent.meta.k="open = true; q = ''; idx = 0; $nextTick(() => $refs.cmdInput && $refs.cmdInput.focus())"
             @keydown.window.prevent.f11="if(!document.fullscreenElement){document.documentElement.requestFullscreen().catch(()=>{}); document.body.classList.add('is-fullscreen');} else {document.exitFullscreen(); document.body.classList.remove('is-fullscreen');}">
            <div x-show="open" x-cloak class="cmd-palette-backdrop" @click.self="open = false" @keydown.escape.window="open = false">
                <div class="cmd-palette-card">
                    <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-100 dark:border-gray-800">
                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input x-ref="cmdInput" x-model="q" @input="idx = 0"
                               @keydown.arrow-down.prevent="idx = Math.min(idx + 1, filtered.length - 1)"
                               @keydown.arrow-up.prevent="idx = Math.max(0, idx - 1)"
                               @keydown.enter.prevent="if(filtered[idx]) run(filtered[idx])"
                               type="text" placeholder="{{ __('pos.ph_cmd_search') }}"
                               class="flex-1 bg-transparent border-0 outline-none focus:ring-0 text-sm text-gray-900 dark:text-white placeholder-gray-400">
                        <kbd class="cmd-kbd">ESC</kbd>
                    </div>
                    <div class="max-h-80 overflow-y-auto py-2">
                        <template x-for="(item, i) in filtered" :key="item.label">
                            <div class="cmd-item" :class="i === idx && 'cmd-active'" @click="run(item)" @mouseenter="idx = i">
                                <div class="cmd-icon" x-text="item.icon"></div>
                                <span class="flex-1 text-gray-800 dark:text-gray-200" x-text="item.label"></span>
                                <kbd x-show="item.kbd" class="cmd-kbd" x-text="item.kbd"></kbd>
                            </div>
                        </template>
                        <div x-show="filtered.length === 0" class="px-4 py-8 text-center text-xs text-gray-400">{{ __('pos.no_matches') }}</div>
                    </div>
                    <div class="px-4 py-2 border-t border-gray-100 dark:border-gray-800 flex items-center gap-3 text-[10px] text-gray-400">
                        <span class="flex items-center gap-1"><kbd class="cmd-kbd">↑↓</kbd> {{ __('pos.cmd_navigate') }}</span>
                        <span class="flex items-center gap-1"><kbd class="cmd-kbd">↵</kbd> {{ __('pos.cmd_open') }}</span>
                        <span class="flex items-center gap-1 ml-auto"><kbd class="cmd-kbd">⌘K</kbd> {{ __('pos.cmd_anywhere') }}</span>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Disable browser context menu on chrome (header/nav) to feel native; allow on inputs/textareas everywhere
            document.addEventListener('contextmenu', function(e) {
                const t = e.target;
                if (t.matches('input, textarea, [contenteditable], [contenteditable] *')) return;
                if (t.closest('.topnav-bar') || t.closest('.profile-dropdown') || t.closest('.cmd-palette-backdrop')) {
                    e.preventDefault();
                }
            });
            // Track fullscreen state on body for styling
            document.addEventListener('fullscreenchange', function() {
                document.body.classList.toggle('is-fullscreen', !!document.fullscreenElement);
            });
        </script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('form:not(.no-auto-loading)').forEach(function(form) {
                    form.addEventListener('submit', function() {
                        var btn = form.querySelector('button[type="submit"]');
                        if (btn && !btn.classList.contains('btn-loading')) {
                            btn.classList.add('btn-loading');
                            setTimeout(function() { btn.classList.remove('btn-loading'); }, 5000);
                        }
                    });
                });
                document.querySelectorAll('a[href]:not([target]):not([download]):not([href^="#"]):not([href^="javascript"])').forEach(function(link) {
                    link.addEventListener('click', function(e) {
                        if (e.ctrlKey || e.metaKey || e.shiftKey) return;
                        var href = link.getAttribute('href');
                        if (href && href !== window.location.pathname && !href.startsWith('#')) {
                            var overlay = document.getElementById('pageLoadOverlay');
                            if (overlay) { overlay.classList.add('active'); }
                            setTimeout(function() { if (overlay) overlay.classList.remove('active'); }, 4000);
                        }
                    });
                });
            });
        </script>
        @stack('scripts')
        <script>
        // Smart prefetch of POS sale screen (offline-ready) — skip on slow/save-data connections
        (function(){
            try {
                var c = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
                if (c && (c.saveData || /2g/.test(c.effectiveType || ''))) return;
                if (location.pathname.indexOf('/pos/invoice/create') === 0) return;
                var run = function(){
                    var l = document.createElement('link');
                    l.rel = 'prefetch'; l.href = '/pos/invoice/create'; l.as = 'document';
                    document.head.appendChild(l);
                };
                ('requestIdleCallback' in window) ? requestIdleCallback(run, {timeout: 4000}) : setTimeout(run, 2500);
            } catch(_){}
        })();
        </script>
        @if($whatsNewPopup)
        {{-- One-time "What's New" popup — dismiss marks ALL current updates seen (per user) --}}
        <div x-data="{ wnOpen: true,
                wnDismiss() {
                    this.wnOpen = false;
                    fetch('/pos/whats-new/seen', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' } }).catch(() => {});
                } }"
             x-show="wnOpen" x-cloak
             class="fixed inset-0 flex items-center justify-center p-4"
             style="z-index: 130; background: rgba(15, 10, 40, 0.55); backdrop-filter: blur(4px);">
            <div class="w-full max-w-md bg-white dark:bg-gray-900 rounded-2xl shadow-2xl overflow-hidden"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 scale-90"
                 x-transition:enter-end="opacity-100 scale-100">
                <div class="px-6 py-5 text-center" style="background: linear-gradient(135deg, hsl(var(--accent-h), var(--accent-s), 42%), hsl(var(--accent-h), var(--accent-s), 28%));">
                    <div class="text-4xl mb-1">🎉</div>
                    <h2 class="text-xl font-extrabold text-white">{{ $whatsNewUnseenCount > 1 ? __('pos.whats_new_many', ['count' => $whatsNewUnseenCount]) : __('pos.whats_new_one') }}</h2>
                    @if($whatsNewUnseenCount === 1)
                        <p class="text-[12px] text-white/80 mt-1">{{ $whatsNewPopup->title }} · {{ $whatsNewPopup->created_at->format('d M Y') }}</p>
                    @else
                        <p class="text-[12px] text-white/80 mt-1">{{ __('pos.whats_new_scroll_hint') }}</p>
                    @endif
                </div>
                {{-- Owner (21 Jul 2026): ALL unseen updates stacked (newest first) in one
                     scrollable body — pehle sirf latest dikhta tha. Inline max-height
                     (arbitrary Tailwind classes need a Vite rebuild). --}}
                <div class="px-6 py-5 overflow-y-auto" style="max-height: 62vh;">
                    @foreach($whatsNewPopupList as $wnp)
                    <div class="{{ $loop->first ? '' : 'mt-5 pt-4 border-t border-gray-200 dark:border-gray-700' }}">
                        @if($whatsNewUnseenCount > 1)
                            <p class="text-sm font-extrabold text-gray-900 dark:text-white mb-2">{{ $wnp->title }} <span class="font-normal text-[11px] text-gray-400">· {{ $wnp->created_at->format('d M Y') }}</span></p>
                        @endif
                        @if($wnp->image_path ?? null)
                            <img src="{{ asset('storage/' . $wnp->image_path) }}" alt="{{ __('pos.update_image_alt') }}" loading="lazy"
                                 class="w-full rounded-xl border border-gray-200 dark:border-gray-700 mb-4 cursor-zoom-in"
                                 onclick="window.open(this.src, '_blank')">
                        @endif
                        <ul class="space-y-2.5">
                            @foreach(($wnp->points ?? []) as $wnpt)
                                <li class="flex items-start gap-2.5 text-sm text-gray-700 dark:text-gray-200">
                                    <span class="flex-shrink-0 w-5 h-5 rounded-full bg-purple-100 dark:bg-purple-900/40 flex items-center justify-center mt-0.5">
                                        <svg class="w-3 h-3 text-purple-600 dark:text-purple-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                    </span>
                                    <span>{{ $wnpt }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    @endforeach
                </div>
                <div class="px-6 pb-5">
                    <button @click="wnDismiss()" x-ref="wnBtn" x-init="$nextTick(() => $refs.wnBtn.focus())"
                            class="w-full py-3 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-sm shadow-sm transition cursor-pointer">
                        {{ __('pos.whats_new_got_it') }}
                    </button>
                </div>
            </div>
        </div>
        @endif
        <x-pwa-update color="purple" />
        <x-trial-lock-modal />
        {{-- Madadgar unified support bubble (AI chat + WhatsApp) — replaces the plain WhatsApp bubble on POS (owner, 22 Jul 2026).
             Waiters ko nahi (ZFC, 2 Aug 2026): tablet par bubble search box ke
             upar aata tha — support ka rabta admin/cashier ka kaam hai. --}}
        @if (auth('pos')->user()?->pos_role !== 'pos_waiter')
            <x-madadgar-support />
        @endif
        <script src="{{ asset('js/wheel-scroll.js?v=1') }}" defer></script>
    </body>
</html>
