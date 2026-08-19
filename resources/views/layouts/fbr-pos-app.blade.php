<!DOCTYPE html>
@php
    $isDarkMode = Auth::guard('fbrpos')->check() && Auth::guard('fbrpos')->user()->dark_mode;
    $fbrUser = Auth::guard('fbrpos')->user();
    $fbrCompany = \App\Models\Company::find($fbrUser->company_id ?? null);
    $companyName = $fbrCompany->name ?? 'My Business';
    $userName = $fbrUser->name ?? 'User';
    $userInitial = strtoupper(substr($userName, 0, 1));
    $dashboardStyle = $fbrCompany->pos_dashboard_style ?? 'square-classic';
    $fbrTheme = $fbrCompany->pos_theme ?? 'blue';
    // "What's New" app updates (popup + bell) — same conventions as PRA POS layout:
    // admin/manager only, skip pending companies + read-only impersonation, fail
    // silent if table missing on prod, master switch pos_whats_new_enabled.
    $whatsNewList = collect(); $whatsNewUnseenCount = 0; $whatsNewPopup = null; $whatsNewSeenIds = []; $whatsNewPopupList = collect(); $whatsNewFeatured = null;
    try {
        $wnAllowed = $fbrUser && $fbrUser->isPosAdmin();
        $wnPending = ($fbrCompany->status ?? null) === 'pending';
        $wnImp = session('impersonation');
        $wnReadonlyImp = is_array($wnImp) && !empty($wnImp['readonly']);
        if ($wnAllowed && !$wnPending && !$wnReadonlyImp
            && \Illuminate\Support\Facades\Schema::hasTable('app_updates')
            && \App\Models\SystemSetting::get('pos_whats_new_enabled', '1') === '1') {
            // Task 1286: 7-day live window — updates auto-disappear from the
            // bell + popup 7 days after publish (read-time filter, no cron).
            $whatsNewList = \App\Models\AppUpdate::whereIn('audience', ['fbr_pos', 'all'])->where('is_published', true)
                ->where('created_at', '>=', now()->subDays(\App\Models\AppUpdate::LIVE_DAYS))
                ->orderByDesc('created_at')->limit(10)->get();
            if ($whatsNewList->isNotEmpty()) {
                $whatsNewSeenIds = \App\Models\AppUpdateSeen::where('user_id', $fbrUser->id)
                    ->whereIn('app_update_id', $whatsNewList->pluck('id'))->pluck('app_update_id')->all();
                $whatsNewUnseen = $whatsNewList->reject(fn ($u) => in_array($u->id, $whatsNewSeenIds));
                $whatsNewUnseenCount = $whatsNewUnseen->count();
                $whatsNewPopup = $whatsNewUnseen->first();
                $whatsNewPopupList = $whatsNewUnseen->values();
                // Featured "bara elaan" (Task 722): if ANY unseen update is flagged,
                // the popup renders in celebratory hero style with that update on top.
                // ?? false: column may not exist yet mid-deploy (missing attr = null).
                $whatsNewFeatured = $whatsNewPopupList->first(fn ($u) => (bool) ($u->is_featured ?? false));
            }
        }
    } catch (\Throwable $e) { /* keep FBR POS pages alive */ }
    // Unmapped biometric PIN alerts — admin/manager only (FBR port, Aug 2026).
    // Same gating as the PRA pos-app layout: Schema::hasTable guard + try/catch
    // so prod schema drift never breaks the layout; pending companies and
    // confined roles excluded via isPosAdmin().
    $bioAlerts = collect();
    try {
        $bioAllowed = $fbrUser && $fbrUser->isPosAdmin();
        $bioPending = ($fbrCompany->status ?? null) === 'pending';
        if ($bioAllowed && !$bioPending
            && \Illuminate\Support\Facades\Schema::hasTable('pos_bio_pin_alerts')) {
            $bioAlerts = \App\Models\PosUnmappedPinAlert::where('company_id', app('currentCompanyId'))
                ->whereNull('dismissed_at')
                ->whereNull('mapped_at')
                ->orderBy('first_seen_at')
                ->get(['id', 'device_pin', 'first_seen_at']);
        }
    } catch (\Throwable $e) { $bioAlerts = collect(); }
    // Strict plan-feature binding (Aug 2026): hide nav for features the plan
    // lacks — server-side fbrPlanGate() 403s them too. planAllows handles
    // trial-unlock / override / internal bypass / expired-lock centrally.
    $fbrPlanKhata   = \App\Services\PosFeatureService::planAllows($fbrCompany, 'khata_enabled');
    $fbrPlanDeals   = \App\Services\PosFeatureService::planAllows($fbrCompany, 'deals_enabled');
    $fbrPlanLoyalty = \App\Services\PosFeatureService::planAllows($fbrCompany, 'loyalty_enabled');
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $isDarkMode ? 'dark' : '' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
        <meta name="theme-color" content="#1e3a5f">
        <link rel="stylesheet" href="{{ asset('css/mobile.css?v=2.6') }}">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="Nest FBR Pos">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="application-name" content="Nest FBR Pos">
        <link rel="manifest" href="/manifest-fbrpos.json">
        <link rel="apple-touch-icon" href="/icons/nest-fbr/icon-192.png">
        <link rel="icon" type="image/png" sizes="192x192" href="/icons/nest-fbr/icon-192.png">
        <link rel="icon" type="image/png" sizes="512x512" href="/icons/nest-fbr/icon-512.png">
        <title>FBR POS — {{ config('app.name', 'TaxNest') }}</title>
        {{-- ONE font CDN only (perf, Jul 2026): Google Fonts duplicate removed — mirrors pos-app. --}}
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700,800,900&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        {{-- Alpine CDN fallback — only fires if Vite bundle failed (5s grace) --}}
        <script>
            setTimeout(function(){
                if(!window.__alpineStarted){
                    window.__alpineStarted=true;
                    var c=document.createElement('script');
                    c.src='https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.14.8/dist/cdn.min.js';
                    document.head.appendChild(c);
                    c.onload=function(){
                        var s=document.createElement('script');
                        s.src='https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js';
                        document.head.appendChild(s);
                    };
                }
            }, 5000);
        </script>
        <script src="/vendor/chart.umd.min.js?v=4.4.0" defer></script>
        <script>if(document.documentElement.classList.contains('dark')){document.documentElement.style.colorScheme='dark';}</script>
        <style>
            *, *::before, *::after { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
            html, body { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; text-rendering: optimizeLegibility; font-feature-settings: 'cv11', 'ss01'; font-variation-settings: 'opsz' 32; }
            body { letter-spacing: -0.011em; }
            h1, h2, h3, h4, h5, h6, .font-bold, .font-extrabold, .font-semibold { text-rendering: geometricPrecision; }
            .dark body { color: #f1f5f9; }
            .dark .text-gray-400 { color: #cbd5e1 !important; }
            .dark .text-gray-500 { color: #94a3b8 !important; }
            @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
            @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
            @keyframes slideDown { from { opacity: 0; transform: translateY(-8px) scale(0.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
            .page-fade { animation: fadeIn 0.15s ease-out; }
            .btn-loading { position: relative; pointer-events: none; opacity: 0.7; }
            .btn-loading::after { content: ''; position: absolute; right: 8px; top: 50%; width: 14px; height: 14px; margin-top: -7px; border: 2px solid transparent; border-top-color: currentColor; border-radius: 50%; animation: spin 0.6s linear infinite; }
            .main-scroll::-webkit-scrollbar { width: 6px; }
            .main-scroll::-webkit-scrollbar-thumb { background: rgba(156,163,175,0.3); border-radius: 4px; }
            .main-scroll::-webkit-scrollbar-track { background: transparent; }
            /* ═══════════════════════════════════════════════════════════════════
               🎨 FBR POS THEME ENGINE — same 6 themes as PRA POS for consistency
               ═══════════════════════════════════════════════════════════════════ */
            [data-theme="purple"]   { --nav-bg: linear-gradient(135deg, #1e1b4b 0%, #312e81 40%, #4c1d95 100%); --accent-h: 263; --accent-s: 70%; --accent-l: 50%; }
            [data-theme="blue"]     { --nav-bg: linear-gradient(135deg, #050b18 0%, #0b1d3d 28%, #1e3a8a 62%, #1d4ed8 100%); --accent-h: 217; --accent-s: 91%; --accent-l: 48%; }
            [data-theme="emerald"]  { --nav-bg: linear-gradient(135deg, #022c22 0%, #064e3b 40%, #047857 100%); --accent-h: 160; --accent-s: 84%; --accent-l: 39%; }
            [data-theme="orange"]   { --nav-bg: linear-gradient(135deg, #431407 0%, #7c2d12 40%, #c2410c 100%); --accent-h: 21;  --accent-s: 90%; --accent-l: 48%; }
            [data-theme="midnight"] { --nav-bg: linear-gradient(135deg, #0a0a0a 0%, #171717 40%, #262626 100%); --accent-h: 0;   --accent-s: 0%;  --accent-l: 45%; }
            [data-theme="rose"]     { --nav-bg: linear-gradient(135deg, #4c0519 0%, #881337 40%, #be123c 100%); --accent-h: 347; --accent-s: 77%; --accent-l: 50%; }

            .theme-swatch { width: 28px; height: 28px; border-radius: 8px; cursor: pointer; border: 2px solid transparent; transition: all 0.15s ease; }
            .theme-swatch:hover { transform: scale(1.15); }
            .theme-swatch.active-theme { border-color: white; box-shadow: 0 0 0 2px rgba(255,255,255,0.3); }

            /* === FBR POS Premium Header === */
            .topnav-bar {
                background: var(--nav-bg,
                    linear-gradient(135deg, #050b18 0%, #0b1d3d 28%, #1e3a8a 62%, #1d4ed8 100%));
                box-shadow:
                    0 1px 0 rgba(255,255,255,0.08) inset,
                    0 8px 24px -10px rgba(0,0,0,0.55),
                    0 0 0 1px rgba(255,255,255,0.04);
                position: relative;
            }
            .topnav-bar::after {
                content: '';
                position: absolute; left: 0; right: 0; bottom: 0; height: 1px;
                background: linear-gradient(90deg, transparent 0%, rgba(96,165,250,0.55) 25%, rgba(251,191,36,0.45) 55%, rgba(96,165,250,0.55) 80%, transparent 100%);
                opacity: 0.55;
            }
            .nav-pill { transition: all 0.18s cubic-bezier(.4,0,.2,1); position: relative; }
            .nav-pill:hover { background: rgba(255,255,255,0.14); transform: translateY(-1px); }
            .nav-pill.active {
                background: linear-gradient(135deg, rgba(96,165,250,0.28), rgba(59,130,246,0.18));
                box-shadow: 0 0 0 1px rgba(147,197,253,0.35) inset, 0 4px 14px -4px rgba(59,130,246,0.45);
            }
            .nav-pill.active::after {
                content: ''; position: absolute; left: 14%; right: 14%; bottom: -2px; height: 2px;
                background: linear-gradient(90deg, transparent, #fbbf24, transparent);
                border-radius: 2px;
            }
            /* Premium gold-accented FBR badge */
            .fbr-badge-premium {
                background: linear-gradient(135deg, rgba(251,191,36,0.18) 0%, rgba(245,158,11,0.10) 100%);
                color: #fcd34d;
                border: 1px solid rgba(251,191,36,0.35);
                box-shadow: 0 0 0 1px rgba(0,0,0,0.18) inset, 0 4px 10px -4px rgba(251,191,36,0.5);
                text-shadow: 0 1px 0 rgba(0,0,0,0.25);
                letter-spacing: 0.06em;
            }
            /* Brand mark with subtle inner glow */
            .brand-tile-fbr {
                background: linear-gradient(135deg, rgba(255,255,255,0.20) 0%, rgba(96,165,250,0.18) 50%, rgba(251,191,36,0.10) 100%);
                box-shadow: 0 0 0 1px rgba(255,255,255,0.18) inset, 0 4px 12px -4px rgba(59,130,246,0.45);
            }
            /* Profile avatar ring */
            .avatar-ring-fbr {
                background: linear-gradient(135deg, #fbbf24 0%, #60a5fa 50%, #1d4ed8 100%);
                box-shadow: 0 4px 14px -4px rgba(251,191,36,0.55), 0 0 0 1px rgba(255,255,255,0.18) inset;
            }
            .profile-dropdown {
                animation: slideDown 0.18s cubic-bezier(.4,0,.2,1);
                box-shadow: 0 25px 60px -15px rgba(15,23,42,0.55), 0 0 0 1px rgba(15,23,42,0.06);
            }
            .menu-link { transition: all 0.12s ease; border-left: 2px solid transparent; }
            .menu-link:hover { background: linear-gradient(90deg, rgba(37,99,235,0.10), transparent 70%); border-left-color: #2563eb; padding-left: calc(1rem + 2px); }
            .dark .menu-link:hover { background: linear-gradient(90deg, rgba(59,130,246,0.18), transparent 70%); border-left-color: #60a5fa; }
            /* Premium page background — clean flat navy/blue wash (no corner gradients) */
            .fbr-page-bg {
                background: linear-gradient(180deg, #f5f8ff 0%, #f1f5fb 100%);
            }
            .dark .fbr-page-bg {
                background: linear-gradient(180deg, #030712 0%, #0a1124 100%);
            }
            /* Premium session toast banners */
            .fbr-banner-success {
                background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
                border: 1px solid #6ee7b7;
                box-shadow: 0 6px 18px -8px rgba(16,185,129,0.35), 0 0 0 1px rgba(16,185,129,0.10) inset;
            }
            .dark .fbr-banner-success { background: linear-gradient(135deg, rgba(6,78,59,0.40), rgba(6,95,70,0.30)); border-color: rgba(16,185,129,0.45); }
            [x-cloak] { display: none !important; }

            /* ═══════════════════════════════════════════════════════════════════
               🎨 UNIVERSAL THEME OVERRIDE — remaps hardcoded blue-X classes used
               throughout FBR POS views to the selected theme's accent HSL.
               When data-theme=blue (default), hardcoded blue matches naturally.
               For any OTHER theme, these rules remap blue-X → accent HSL.
               ═══════════════════════════════════════════════════════════════════ */
            body:not([data-theme="blue"]) .bg-blue-50  { background-color: hsl(var(--accent-h), var(--accent-s), 97%) !important; }
            body:not([data-theme="blue"]) .bg-blue-100 { background-color: hsl(var(--accent-h), var(--accent-s), 94%) !important; }
            body:not([data-theme="blue"]) .bg-blue-200 { background-color: hsl(var(--accent-h), var(--accent-s), 86%) !important; }
            body:not([data-theme="blue"]) .bg-blue-300 { background-color: hsl(var(--accent-h), var(--accent-s), 76%) !important; }
            body:not([data-theme="blue"]) .bg-blue-400 { background-color: hsl(var(--accent-h), var(--accent-s), 65%) !important; }
            body:not([data-theme="blue"]) .bg-blue-500 { background-color: hsl(var(--accent-h), var(--accent-s), 55%) !important; }
            body:not([data-theme="blue"]) .bg-blue-600 { background-color: hsl(var(--accent-h), var(--accent-s), var(--accent-l)) !important; }
            body:not([data-theme="blue"]) .bg-blue-700 { background-color: hsl(var(--accent-h), var(--accent-s), 40%) !important; }
            body:not([data-theme="blue"]) .bg-blue-800 { background-color: hsl(var(--accent-h), var(--accent-s), 32%) !important; }
            body:not([data-theme="blue"]) .bg-blue-900 { background-color: hsl(var(--accent-h), var(--accent-s), 22%) !important; }

            body:not([data-theme="blue"]) .text-blue-300 { color: hsl(var(--accent-h), var(--accent-s), 76%) !important; }
            body:not([data-theme="blue"]) .text-blue-400 { color: hsl(var(--accent-h), var(--accent-s), 65%) !important; }
            body:not([data-theme="blue"]) .text-blue-500 { color: hsl(var(--accent-h), var(--accent-s), 55%) !important; }
            body:not([data-theme="blue"]) .text-blue-600 { color: hsl(var(--accent-h), var(--accent-s), var(--accent-l)) !important; }
            body:not([data-theme="blue"]) .text-blue-700 { color: hsl(var(--accent-h), var(--accent-s), 35%) !important; }
            body:not([data-theme="blue"]) .text-blue-800 { color: hsl(var(--accent-h), var(--accent-s), 28%) !important; }
            body:not([data-theme="blue"]) .text-blue-900 { color: hsl(var(--accent-h), var(--accent-s), 22%) !important; }

            body:not([data-theme="blue"]) .border-blue-100 { border-color: hsl(var(--accent-h), var(--accent-s), 90%) !important; }
            body:not([data-theme="blue"]) .border-blue-200 { border-color: hsl(var(--accent-h), var(--accent-s), 84%) !important; }
            body:not([data-theme="blue"]) .border-blue-300 { border-color: hsl(var(--accent-h), var(--accent-s), 74%) !important; }
            body:not([data-theme="blue"]) .border-blue-400 { border-color: hsl(var(--accent-h), var(--accent-s), 60%) !important; }
            body:not([data-theme="blue"]) .border-blue-500 { border-color: hsl(var(--accent-h), var(--accent-s), 55%) !important; }
            body:not([data-theme="blue"]) .border-blue-600 { border-color: hsl(var(--accent-h), var(--accent-s), var(--accent-l)) !important; }
            body:not([data-theme="blue"]) .border-blue-700 { border-color: hsl(var(--accent-h), var(--accent-s), 40%) !important; }
            body:not([data-theme="blue"]) .border-blue-800 { border-color: hsl(var(--accent-h), var(--accent-s), 32%) !important; }

            body:not([data-theme="blue"]) .ring-blue-200 { --tw-ring-color: hsl(var(--accent-h), var(--accent-s), 84%) !important; }
            body:not([data-theme="blue"]) .ring-blue-300 { --tw-ring-color: hsl(var(--accent-h), var(--accent-s), 74%) !important; }
            body:not([data-theme="blue"]) .ring-blue-400 { --tw-ring-color: hsl(var(--accent-h), var(--accent-s), 60%) !important; }
            body:not([data-theme="blue"]) .ring-blue-500 { --tw-ring-color: hsl(var(--accent-h), var(--accent-s), 55%) !important; }
            body:not([data-theme="blue"]) .focus\:ring-blue-200:focus { --tw-ring-color: hsl(var(--accent-h), var(--accent-s), 84%) !important; }
            body:not([data-theme="blue"]) .focus\:ring-blue-400:focus { --tw-ring-color: hsl(var(--accent-h), var(--accent-s), 60%) !important; }
            body:not([data-theme="blue"]) .focus\:ring-blue-500:focus { --tw-ring-color: hsl(var(--accent-h), var(--accent-s), 55%) !important; }
            body:not([data-theme="blue"]) .focus\:border-blue-400:focus { border-color: hsl(var(--accent-h), var(--accent-s), 60%) !important; }
            body:not([data-theme="blue"]) .focus\:border-blue-500:focus { border-color: hsl(var(--accent-h), var(--accent-s), 55%) !important; }

            body:not([data-theme="blue"]) .hover\:bg-blue-50:hover  { background-color: hsl(var(--accent-h), var(--accent-s), 97%) !important; }
            body:not([data-theme="blue"]) .hover\:bg-blue-100:hover { background-color: hsl(var(--accent-h), var(--accent-s), 94%) !important; }
            body:not([data-theme="blue"]) .hover\:bg-blue-500:hover { background-color: hsl(var(--accent-h), var(--accent-s), 55%) !important; }
            body:not([data-theme="blue"]) .hover\:bg-blue-600:hover { background-color: hsl(var(--accent-h), var(--accent-s), var(--accent-l)) !important; }
            body:not([data-theme="blue"]) .hover\:bg-blue-700:hover { background-color: hsl(var(--accent-h), var(--accent-s), 40%) !important; }
            body:not([data-theme="blue"]) .hover\:text-blue-300:hover { color: hsl(var(--accent-h), var(--accent-s), 76%) !important; }
            body:not([data-theme="blue"]) .hover\:text-blue-700:hover { color: hsl(var(--accent-h), var(--accent-s), 35%) !important; }
            body:not([data-theme="blue"]) .hover\:text-blue-800:hover { color: hsl(var(--accent-h), var(--accent-s), 28%) !important; }

            body:not([data-theme="blue"]) .from-blue-50  { --tw-gradient-from: hsl(var(--accent-h), var(--accent-s), 97%) var(--tw-gradient-from-position) !important; --tw-gradient-to: hsl(var(--accent-h) var(--accent-s) 97% / 0) var(--tw-gradient-to-position) !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to) !important; }
            body:not([data-theme="blue"]) .from-blue-100 { --tw-gradient-from: hsl(var(--accent-h), var(--accent-s), 94%) var(--tw-gradient-from-position) !important; --tw-gradient-to: hsl(var(--accent-h) var(--accent-s) 94% / 0) var(--tw-gradient-to-position) !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to) !important; }
            body:not([data-theme="blue"]) .from-blue-500 { --tw-gradient-from: hsl(var(--accent-h), var(--accent-s), 55%) var(--tw-gradient-from-position) !important; --tw-gradient-to: hsl(var(--accent-h) var(--accent-s) 55% / 0) var(--tw-gradient-to-position) !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to) !important; }
            body:not([data-theme="blue"]) .from-blue-600 { --tw-gradient-from: hsl(var(--accent-h), var(--accent-s), var(--accent-l)) var(--tw-gradient-from-position) !important; --tw-gradient-to: hsl(var(--accent-h) var(--accent-s) var(--accent-l) / 0) var(--tw-gradient-to-position) !important; --tw-gradient-stops: var(--tw-gradient-from), var(--tw-gradient-to) !important; }
            body:not([data-theme="blue"]) .to-blue-500   { --tw-gradient-to: hsl(var(--accent-h), var(--accent-s), 55%) var(--tw-gradient-to-position) !important; }
            body:not([data-theme="blue"]) .to-blue-600   { --tw-gradient-to: hsl(var(--accent-h), var(--accent-s), var(--accent-l)) var(--tw-gradient-to-position) !important; }
            body:not([data-theme="blue"]) .to-blue-700   { --tw-gradient-to: hsl(var(--accent-h), var(--accent-s), 40%) var(--tw-gradient-to-position) !important; }
            body:not([data-theme="blue"]) .via-blue-500  { --tw-gradient-stops: var(--tw-gradient-from), hsl(var(--accent-h), var(--accent-s), 55%) var(--tw-gradient-via-position), var(--tw-gradient-to) !important; }
            body:not([data-theme="blue"]) .via-blue-600  { --tw-gradient-stops: var(--tw-gradient-from), hsl(var(--accent-h), var(--accent-s), var(--accent-l)) var(--tw-gradient-via-position), var(--tw-gradient-to) !important; }

            body:not([data-theme="blue"]) .dark .dark\:bg-blue-900 { background-color: hsl(var(--accent-h), var(--accent-s), 22%) !important; }
            body:not([data-theme="blue"]) .dark .dark\:bg-blue-900\/10 { background-color: hsla(var(--accent-h), var(--accent-s), 22%, 0.1) !important; }
            body:not([data-theme="blue"]) .dark .dark\:bg-blue-900\/20 { background-color: hsla(var(--accent-h), var(--accent-s), 22%, 0.2) !important; }
            body:not([data-theme="blue"]) .dark .dark\:bg-blue-900\/30 { background-color: hsla(var(--accent-h), var(--accent-s), 22%, 0.3) !important; }
            body:not([data-theme="blue"]) .dark .dark\:text-blue-300 { color: hsl(var(--accent-h), var(--accent-s), 76%) !important; }
            body:not([data-theme="blue"]) .dark .dark\:text-blue-400 { color: hsl(var(--accent-h), var(--accent-s), 65%) !important; }
            body:not([data-theme="blue"]) .dark .dark\:border-blue-700 { border-color: hsl(var(--accent-h), var(--accent-s), 40%) !important; }
            body:not([data-theme="blue"]) .dark .dark\:border-blue-800 { border-color: hsl(var(--accent-h), var(--accent-s), 32%) !important; }

            body:not([data-theme="blue"]) .bg-blue-100\/50 { background-color: hsla(var(--accent-h), var(--accent-s), 94%, 0.5) !important; }
            body:not([data-theme="blue"]) .bg-blue-500\/20 { background-color: hsla(var(--accent-h), var(--accent-s), 55%, 0.2) !important; }
            body:not([data-theme="blue"]) .bg-blue-600\/20 { background-color: hsla(var(--accent-h), var(--accent-s), var(--accent-l), 0.2) !important; }
            body:not([data-theme="blue"]) .bg-blue-600\/30 { background-color: hsla(var(--accent-h), var(--accent-s), var(--accent-l), 0.3) !important; }
            /* Sale-screen nav tools anchor (Aug 2026, PRA parity): universal.blade.php
               teleports its Switches dropdown here — scrollable, never spills. */
            #tn-nav-sale-tools { scrollbar-width: none; -ms-overflow-style: none; align-self: stretch; }
            #tn-nav-sale-tools::-webkit-scrollbar { display: none; }
        </style>
        {{-- PWA service worker (FBR POS scope) --}}
        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => {
                    navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' }).catch(()=>{});
                });
            }
        </script>
    </head>
    <body class="h-screen overflow-hidden antialiased" data-theme="{{ $fbrTheme }}">
        @include('partials.impersonation-banner')
        <x-pwa-init />
        <div class="flex flex-col h-full" x-data="fbrPosHeader('{{ $fbrTheme }}')" x-init="init()" @keydown.escape.window="profileOpen = false; mobileMenuOpen = false; themeOpen = false; localOpen = false; failedOpen = false; sidebarOpen = false">

            <header class="topnav-bar flex-shrink-0 relative z-50">
                <div class="flex items-center justify-between px-3 sm:px-5 h-12">

                    <div class="flex items-center gap-3">
                        {{-- ☰ Sidebar Drawer Toggle (Ctrl+M) --}}
                        <button @click="sidebarOpen = !sidebarOpen" type="button" class="p-2 rounded-lg text-white hover:bg-white/15 transition" title="{{ __('pos.ti_menu_ctrl_m') }}">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        </button>

                        <a href="{{ route('fbrpos.dashboard') }}" class="flex items-center gap-2.5 group">
                            <div class="brand-tile-fbr w-8 h-8 rounded-xl flex items-center justify-center transition group-hover:scale-105">
                                <svg class="w-4 h-4 text-white drop-shadow" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                            </div>
                            <div class="hidden sm:flex items-center gap-1.5">
                                <span class="text-sm font-extrabold text-white tracking-tight">FBR POS</span>
                                <span class="hidden lg:inline-flex items-center px-1.5 py-0.5 rounded-md text-[8.5px] font-black tracking-widest fbr-badge-premium">PREMIUM</span>
                                <span class="text-[9px] text-white/60 ml-0.5 hidden xl:inline">by TaxNest</span>
                            </div>
                        </a>

                        <div class="h-5 w-px bg-white/10 hidden md:block"></div>

                        @php
                            // Universal sale screen (Aug 2026 PRA-parity redesign): its own
                            // teleported pills (+New / F10 Local / F11 Failed / Reprint / Held /
                            // sync / Switches) land in #tn-nav-sale-tools — hide the layout's
                            // duplicate New Sale link + Local/Failed buttons on THAT page only.
                            // Classic create screen (universal OFF) keeps the layout buttons.
                            // Classic create screen retired Aug 2026 — fbrpos.create ALWAYS renders the universal screen now.
                            $tnOnUniversalSale = request()->routeIs('fbrpos.create');
                        @endphp
                        <nav class="hidden md:flex items-center gap-1">
                            @unless($tnOnUniversalSale)
                            <a href="{{ route('fbrpos.create') }}"
                               class="nav-pill px-3 py-1.5 rounded-lg text-xs font-semibold {{ request()->routeIs('fbrpos.create') ? 'active text-white' : 'text-white/90' }}">
                                {{ __('pos.new_sale') }}
                            </a>
                            @endunless
                        </nav>

                        <div class="hidden md:block ml-1">
                            <x-branch-switcher color="blue" />
                        </div>
                    </div>

                    {{-- Sale-screen tools anchor (Aug 2026, PRA parity): fbr-pos/universal.blade.php
                         teleports its Switches dropdown (FBR Reporting / Auto-Print / Auto-KOT) here
                         via x-teleport so it keeps the restaurantPos() Alpine scope.
                         Empty and harmless on every other FBR POS page. --}}
                    <div id="tn-nav-sale-tools" class="hidden md:flex items-center gap-1.5 min-w-0 flex-1 px-2 overflow-x-auto"></div>

                    <div class="flex items-center gap-2">
                        {{-- Prominent nav-level Download App button — native prompt first, instructions fallback, installed state --}}
                        <x-pwa-install-menu-item color="blue" app-name="Nest FBR POS" :label="__('pos.download_app')" item-class="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-[11px] font-bold uppercase tracking-wide text-white bg-white/10 hover:bg-white/20 ring-1 ring-white/20 transition" />
                        <x-pwa-refresh-btn color="blue" />

                        {{-- 🟦 Local Bills (F10) — Provisional / Saved bills.
                             Hidden on the universal sale screen (its own teleported F10 pill lives there). --}}
                        <button @click="openLocal()" type="button" class="relative {{ ($tnOnUniversalSale ?? false) ? 'hidden' : 'hidden sm:inline-flex' }} items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-[11px] font-bold uppercase tracking-wide text-white bg-white/10 hover:bg-white/20 ring-1 ring-white/20 transition" title="{{ __('pos.ti_local_bills_f10') }}">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                            <span>{{ __('pos.local_word') }}</span>
                            <span x-show="localCount > 0" x-cloak x-text="localCount" class="ml-0.5 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-amber-400 text-amber-950 text-[10px] font-black"></span>
                            <span class="hidden md:inline text-[9px] opacity-70 ml-1">F10</span>
                        </button>

                        {{-- 🟥 Failed Bills (Shift+F11) — F11 plain stays for browser fullscreen.
                             Hidden on the universal sale screen (its own teleported F11 pill lives there). --}}
                        <button @click="openFailed()" type="button" class="relative {{ ($tnOnUniversalSale ?? false) ? 'hidden' : 'hidden sm:inline-flex' }} items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-[11px] font-bold uppercase tracking-wide text-white bg-red-600/85 hover:bg-red-600 ring-1 ring-red-300/40 transition" title="{{ __('pos.ti_failed_bills_f11') }}">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0L3.16 16.25A2 2 0 005 19z"/></svg>
                            <span>{{ __('pos.failed_word') }}</span>
                            <span x-show="failedCount > 0" x-cloak x-text="failedCount" class="ml-0.5 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-white text-red-700 text-[10px] font-black animate-pulse"></span>
                            <span class="hidden md:inline text-[9px] opacity-70 ml-1">⇧F11</span>
                        </button>

                        @if($whatsNewList->isNotEmpty())
                        {{-- What's New bell — updates history (opening marks all as seen) --}}
                        <div class="relative" x-data="{ bellOpen: false, unseen: {{ (int) $whatsNewUnseenCount }},
                                toggleBell() {
                                    this.bellOpen = !this.bellOpen;
                                    if (this.bellOpen && this.unseen > 0) {
                                        this.unseen = 0;
                                        fetch('/fbr-pos/whats-new/seen', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' } }).catch(() => {});
                                    }
                                } }">
                            <button @click="toggleBell()" title="{{ __('pos.ti_app_updates') }}" class="relative p-2 rounded-lg text-white hover:bg-white/15 transition cursor-pointer">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
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
                                 class="absolute right-0 top-full mt-2 w-80 bg-white dark:bg-gray-900 rounded-xl shadow-2xl shadow-black/20 border border-gray-200/80 dark:border-gray-700/80 overflow-hidden z-[100]">
                                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800" style="background: linear-gradient(to right, hsla(var(--accent-h), var(--accent-s), 95%, 1), hsla(var(--accent-h), var(--accent-s), 92%, 1))">
                                    <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.app_updates_heading') }}</p>
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.app_updates_subtitle') }}</p>
                                </div>
                                <div class="max-h-96 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach($whatsNewList as $wnu)
                                        <div class="px-4 py-3">
                                            <div class="flex items-center justify-between gap-2">
                                                <p class="text-[13px] font-semibold text-gray-800 dark:text-gray-100">{{ $wnu->title }} <x-wn-type-badge :update="$wnu" /></p>
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
                                                        <svg class="w-3 h-3 mt-0.5 flex-shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
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

                        {{-- 🎨 Theme Switcher (Customize) --}}
                        <div class="relative">
                            <button @click="themeOpen = !themeOpen; profileOpen = false" class="p-2 rounded-lg text-white hover:bg-white/15 transition" title="{{ __('pos.ti_customize_theme') }}">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                            </button>
                            <div x-show="themeOpen" x-cloak @click.outside="themeOpen = false" x-transition class="absolute right-0 top-full mt-2 bg-white dark:bg-gray-900 rounded-xl shadow-2xl shadow-black/20 border border-gray-200/80 dark:border-gray-700/80 p-3 z-[100] w-48">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-2">{{ __('pos.fbr_pos_theme_heading') }}</p>
                                <div class="grid grid-cols-3 gap-2">
                                    <button @click="currentTheme='purple'; document.body.setAttribute('data-theme','purple'); fetch('{{ route('fbrpos.settings.theme') }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'purple'})}); themeOpen=false" class="theme-swatch" :class="currentTheme==='purple' && 'active-theme'" style="background:linear-gradient(135deg,#312e81,#7c3aed)" title="{{ __('pos.theme_royal_purple') }}"></button>
                                    <button @click="currentTheme='blue'; document.body.setAttribute('data-theme','blue'); fetch('{{ route('fbrpos.settings.theme') }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'blue'})}); themeOpen=false" class="theme-swatch" :class="currentTheme==='blue' && 'active-theme'" style="background:linear-gradient(135deg,#1e3a5f,#2563eb)" title="{{ __('pos.theme_ocean_blue') }}"></button>
                                    <button @click="currentTheme='emerald'; document.body.setAttribute('data-theme','emerald'); fetch('{{ route('fbrpos.settings.theme') }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'emerald'})}); themeOpen=false" class="theme-swatch" :class="currentTheme==='emerald' && 'active-theme'" style="background:linear-gradient(135deg,#064e3b,#059669)" title="{{ __('pos.theme_emerald_green') }}"></button>
                                    <button @click="currentTheme='orange'; document.body.setAttribute('data-theme','orange'); fetch('{{ route('fbrpos.settings.theme') }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'orange'})}); themeOpen=false" class="theme-swatch" :class="currentTheme==='orange' && 'active-theme'" style="background:linear-gradient(135deg,#7c2d12,#ea580c)" title="{{ __('pos.theme_sunset_orange') }}"></button>
                                    <button @click="currentTheme='midnight'; document.body.setAttribute('data-theme','midnight'); fetch('{{ route('fbrpos.settings.theme') }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'midnight'})}); themeOpen=false" class="theme-swatch" :class="currentTheme==='midnight' && 'active-theme'" style="background:linear-gradient(135deg,#171717,#404040)" title="{{ __('pos.theme_midnight_dark') }}"></button>
                                    <button @click="currentTheme='rose'; document.body.setAttribute('data-theme','rose'); fetch('{{ route('fbrpos.settings.theme') }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'rose'})}); themeOpen=false" class="theme-swatch" :class="currentTheme==='rose' && 'active-theme'" style="background:linear-gradient(135deg,#881337,#e11d48)" title="{{ __('pos.theme_rose_pink') }}"></button>
                                </div>
                                <div class="mt-2 pt-2 border-t border-gray-100 dark:border-gray-800">
                                    <p class="text-[9px] text-gray-400 text-center" x-text="currentTheme.charAt(0).toUpperCase() + currentTheme.slice(1) + ' Theme'"></p>
                                </div>
                            </div>
                        </div>

                        <span class="hidden lg:inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black tracking-wider fbr-badge-premium">
                            <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 16.8l-6.2 4.5 2.4-7.4L2 9.4h7.6z"/></svg>
                            {{ __('pos.fbr_certified') }}
                        </span>

                        <div class="relative">
                            <button @click="profileOpen = !profileOpen" class="flex items-center gap-2 px-2 py-1 rounded-lg hover:bg-white/10 transition group">
                                <div class="avatar-ring-fbr w-8 h-8 rounded-full p-[1.5px] flex items-center justify-center transition group-hover:scale-105">
                                    <div class="w-full h-full rounded-full bg-gradient-to-br from-blue-500 to-indigo-700 flex items-center justify-center text-white text-xs font-black">{{ $userInitial }}</div>
                                </div>
                                <div class="hidden sm:block text-left">
                                    <p class="text-xs font-semibold text-white leading-tight truncate max-w-[100px]">{{ $userName }}</p>
                                    <p class="text-[9px] text-blue-200/90 leading-tight">{{ $companyName }}</p>
                                </div>
                                <svg class="w-3 h-3 text-white/70 hidden sm:block" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>

                            <div x-show="profileOpen" @click.away="profileOpen = false" x-cloak
                                 x-transition:enter="transition ease-out duration-150"
                                 x-transition:enter-start="opacity-0 scale-95 -translate-y-2"
                                 x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                 x-transition:leave="transition ease-in duration-100"
                                 x-transition:leave-start="opacity-100"
                                 x-transition:leave-end="opacity-0 scale-95"
                                 class="profile-dropdown absolute right-0 mt-2 w-56 bg-white dark:bg-gray-800 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700 overflow-hidden z-50">

                                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700">
                                    <p class="text-sm font-bold text-gray-900 dark:text-white">{{ $userName }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $companyName }}</p>
                                </div>

                                {{-- SCROLLABLE middle (Aug 2026): the menu is taller than short/zoomed screens —
                                     without max-h + overflow the bottom links (FBR Settings etc.) were unreachable.
                                     Mirrors pos-app.blade.php's max-h-[65vh] pattern. Logout stays pinned below. --}}
                                <div class="max-h-[65vh] overflow-y-auto">
                                <div class="py-1">
                                    <p class="px-4 pt-2 pb-1 text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">{{ __('pos.navigation') }}</p>
                                    <a href="{{ route('fbrpos.dashboard') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                                        {{ __('pos.dashboard') }}
                                    </a>
                                    <a href="{{ route('fbrpos.transactions') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                        {{ __('pos.nav_orders') }}
                                    </a>
                                    @php
                                        $failQueueCount = 0;
                                        try {
                                            $cid = app('currentCompanyId');
                                            $failQueueCount = \App\Models\FbrPosTransaction::where('company_id', $cid)
                                                ->whereIn('fbr_status', ['failed', 'pending'])
                                                ->where(function ($q) { $q->where('invoice_mode', 'fbr')->orWhereNull('invoice_mode'); })
                                                ->count();
                                        } catch (\Throwable $e) {}
                                    @endphp
                                    <a href="{{ route('fbrpos.failQueue') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 {{ $failQueueCount > 0 ? 'text-red-500' : 'text-gray-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.74-2.991l-7-12a2 2 0 00-3.48 0l-7 12A2 2 0 005 19z"/></svg>
                                        <span class="flex-1">{{ __('pos.nav_fail_queue') }}</span>
                                        @if($failQueueCount > 0)
                                            <span class="px-2 py-0.5 rounded-full bg-red-500 text-white text-[10px] font-bold">{{ $failQueueCount }}</span>
                                        @endif
                                    </a>
                                    <a href="{{ route('fbrpos.products') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                        {{ __('pos.products_word') }}
                                    </a>
                                    {{-- Task 1260: Customers page — every role may view/add (mirrors PRA);
                                         manage actions are cashier-blocked in the controller. --}}
                                    <a href="{{ route('fbrpos.customers') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        {{ __('pos.nav_customers') }}
                                    </a>
                                    @if (!in_array(auth('fbrpos')->user()->pos_role ?? '', ['pos_cashier', 'local_viewer'], true))
                                    <a href="{{ route('fbrpos.stock') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                                        {{ __('pos.nav_stock_purchase') }}
                                    </a>
                                    @if($fbrPlanKhata)
                                    <a href="{{ route('fbrpos.khata') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                                        {{ __('pos.dc_udhaar') }}
                                    </a>
                                    @endif
                                    <a href="{{ route('fbrpos.munafa') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                                        {{ __('pos.munafa_report') }}
                                    </a>
                                    @endif
                                    <a href="{{ route('fbrpos.reports') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                                        {{ __('pos.reports') }}
                                    </a>
                                    <a href="{{ route('fbrpos.tax-reports') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                                        {{ __('pos.nav_tax_reports') }}
                                    </a>
                                    <a href="{{ route('fbrpos.day-close') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        {{ __('pos.nav_day_close_z') }}
                                    </a>
                                </div>

                                <div class="border-t border-gray-100 dark:border-gray-700 py-1">
                                    <p class="px-4 pt-2 pb-1 text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">{{ __('pos.settings') }}</p>
                                    @if (auth('fbrpos')->user()->role === 'company_admin' || (auth('fbrpos')->user()->pos_role ?? '') === 'pos_admin')
                                    <a href="{{ route('fbrpos.team') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        {{ __('pos.team_management') }}
                                    </a>
                                    <a href="{{ route('fbrpos.branches') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21h18M3 7v14m18-14v14M6 11h.01M6 15h.01M10 11h.01M10 15h.01M14 11h.01M14 15h.01M18 11h.01M18 15h.01M4 7l8-4 8 4H4z"/></svg>
                                        {{ __('pos.branches_title') }}
                                    </a>
                                    @endif
                                    {{-- Task 579: business profile now edits the company CNIC (a login
                                         identifier) — controller 403s non-admins, so hide the link too. --}}
                                    @if (auth('fbrpos')->user()->isPosAdmin())
                                    <a href="{{ route('fbrpos.business-profile') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                        {{ __('pos.nav_business_profile') }}
                                    </a>
                                    @endif
                                    <a href="{{ route('fbrpos.settings') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        {{ __('pos.nav_fbr_settings') }}
                                    </a>
                                    <a href="{{ route('fbrpos.billing') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                                        {{ __('pos.nav_billing') }}
                                    </a>
                                    <a href="{{ route('fbrpos.my-profile') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                        {{ __('pos.nav_my_profile') }}
                                    </a>
                                    <a href="{{ route('fbrpos.tutorials') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                        {{ __('pos.nav_tutorials') }}
                                    </a>
                                    @if (auth('fbrpos')->user()->isPosAdmin())
                                    <a href="{{ route('fbrpos.suggestions') }}" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                                        {{ __('pos.feature_suggestion_box') }}
                                    </a>
                                    @endif
                                    {{-- Language picker (2 Aug 2026) — per-user Roman Urdu / English / Urdu script --}}
                                    <p class="px-4 pt-2 pb-1 text-[10px] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">{{ __('pos.language') }}</p>
                                    <div class="px-4 py-1.5 flex gap-2">
                                        @php $tnCurLang = app()->getLocale(); @endphp
                                        <form method="POST" action="{{ route('fbrpos.set-language') }}" class="flex-1">
                                            @csrf
                                            <input type="hidden" name="language" value="rur">
                                            <button type="submit" class="w-full px-2 py-1.5 rounded-lg text-[11px] font-bold border transition {{ $tnCurLang === 'rur' ? 'bg-blue-600 text-white border-blue-600' : 'text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800' }}">{{ __('pos.language_roman_urdu') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('fbrpos.set-language') }}" class="flex-1">
                                            @csrf
                                            <input type="hidden" name="language" value="en">
                                            <button type="submit" class="w-full px-2 py-1.5 rounded-lg text-[11px] font-bold border transition {{ $tnCurLang === 'en' ? 'bg-blue-600 text-white border-blue-600' : 'text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800' }}">{{ __('pos.language_english') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('fbrpos.set-language') }}" class="flex-1">
                                            @csrf
                                            <input type="hidden" name="language" value="ur">
                                            <button type="submit" class="w-full px-2 py-1.5 rounded-lg text-[11px] font-bold border transition {{ $tnCurLang === 'ur' ? 'bg-blue-600 text-white border-blue-600' : 'text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800' }}">{{ __('pos.language_urdu_script') }}</button>
                                        </form>
                                    </div>
                                    {{-- PWA install — always visible --}}
                                    <x-pwa-install-menu-item color="blue" app-name="Nest FBR POS" :label="__('pos.install_app_device')" item-class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-gray-700 dark:text-gray-300" />
                                </div>
                                </div>{{-- /scrollable middle --}}

                                <div class="border-t border-gray-100 dark:border-gray-700 py-1">
                                    <form method="POST" action="{{ route('fbrpos.logout') }}">
                                        @csrf
                                        <button type="submit" class="menu-link flex items-center gap-3 px-4 py-2 text-sm text-red-600 dark:text-red-400 w-full">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                            {{ __('pos.sign_out') }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden p-1.5 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition">
                            <svg x-show="!mobileMenuOpen" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                            <svg x-show="mobileMenuOpen" x-cloak class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>

                <div x-show="mobileMenuOpen" x-cloak
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-100"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0 -translate-y-2"
                     class="md:hidden border-t border-white/10 px-3 py-2 flex flex-wrap gap-1.5" style="background: rgba(12,25,41,0.9)">
                    <a href="{{ route('fbrpos.create') }}" class="nav-pill px-3 py-1.5 rounded-lg text-[11px] font-medium {{ request()->routeIs('fbrpos.create') ? 'active text-white' : 'text-white/90' }}">{{ __('pos.new_sale') }}</a>
                    <x-pwa-install-menu-item color="blue" app-name="Nest FBR POS" :label="__('pos.download_app')" item-class="nav-pill inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-bold text-white bg-white/10 ring-1 ring-white/20" />
                </div>
            </header>

            {{-- ═══════════════════════════════════════════════════════════════════
                 🗂️  PREMIUM SIDEBAR DRAWER (Ctrl+M / Hamburger toggle)
                 Slide-in from left · Full menu · Themed · POS cart width safe
                 ═══════════════════════════════════════════════════════════════════ --}}
            {{-- Backdrop --}}
            <div x-show="sidebarOpen" x-cloak
                 x-transition:enter="transition-opacity ease-out duration-200"
                 x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition-opacity ease-in duration-150"
                 x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 @click="sidebarOpen = false"
                 class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[150]"></div>

            {{-- Drawer --}}
            <aside x-show="sidebarOpen" x-cloak
                   x-transition:enter="transition ease-out duration-250"
                   x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
                   x-transition:leave="transition ease-in duration-200"
                   x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
                   class="fixed top-0 left-0 h-full w-[280px] z-[160] shadow-2xl flex flex-col"
                   style="background: linear-gradient(180deg, hsl(var(--accent-h, 217), var(--accent-s, 91%), 12%) 0%, hsl(var(--accent-h, 217), var(--accent-s, 91%), 8%) 100%); color: #fff;">

                {{-- Drawer Header --}}
                <div class="px-4 py-3 border-b border-white/10 flex items-center justify-between flex-shrink-0">
                    <div class="flex items-center gap-2.5">
                        <div class="w-9 h-9 rounded-xl flex items-center justify-center" style="background: rgba(255,255,255,0.12)">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <p class="text-sm font-extrabold tracking-tight leading-tight">FBR POS</p>
                            <p class="text-[10px] text-white/60 leading-tight">{{ $companyName }}</p>
                        </div>
                    </div>
                    <button @click="sidebarOpen = false" class="p-1.5 rounded-lg text-white/70 hover:text-white hover:bg-white/10 transition" title="{{ __('pos.ti_close_esc') }}">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                {{-- Scrollable Nav --}}
                <nav class="flex-1 overflow-y-auto px-2 py-3 space-y-4 main-scroll">

                    {{-- POS Section --}}
                    <div>
                        <p class="px-3 mb-1.5 text-[9px] font-black uppercase tracking-[0.15em] text-white/40">POS</p>
                        @php
                            $sidebarBase = 'group flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition';
                            $sidebarInactive = 'text-white/85 hover:bg-white/10 hover:text-white';
                            $sidebarActive = 'bg-white/15 text-white ring-1 ring-white/20 shadow-inner';
                        @endphp
                        <a href="{{ route('fbrpos.create') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.create') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            <span class="flex-1">{{ __('pos.new_sale') }}</span>
                            <span class="text-[9px] font-bold opacity-50">F2</span>
                        </a>
                        <a href="{{ route('fbrpos.dashboard') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.dashboard') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                            {{ __('pos.dashboard') }}
                        </a>
                        <a href="{{ route('fbrpos.transactions') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.transactions') || request()->routeIs('fbrpos.show') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                            {{ __('pos.nav_orders_transactions') }}
                        </a>
                        <button @click="sidebarOpen=false; openLocal()" type="button" class="{{ $sidebarBase }} {{ $sidebarInactive }} w-full text-left">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                            <span class="flex-1">{{ __('pos.nav_local_provisional') }}</span>
                            <span x-show="localCount > 0" x-text="localCount" class="inline-flex items-center justify-center min-w-[20px] h-[20px] px-1.5 rounded-full bg-amber-400 text-amber-950 text-[10px] font-black"></span>
                            <span class="text-[9px] font-bold opacity-50 ml-1">F10</span>
                        </button>
                        <button @click="sidebarOpen=false; openFailed()" type="button" class="{{ $sidebarBase }} {{ $sidebarInactive }} w-full text-left">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0L3.16 16.25A2 2 0 005 19z"/></svg>
                            <span class="flex-1">{{ __('pos.nav_failed_bills') }}</span>
                            <span x-show="failedCount > 0" x-text="failedCount" class="inline-flex items-center justify-center min-w-[20px] h-[20px] px-1.5 rounded-full bg-red-500 text-white text-[10px] font-black animate-pulse"></span>
                            <span class="text-[9px] font-bold opacity-50 ml-1">⇧F11</span>
                        </button>
                        <a href="{{ route('fbrpos.failQueue') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.failQueue') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span class="flex-1">{{ __('pos.nav_fail_queue_sync') }}</span>
                        </a>
                    </div>

                    {{-- Inventory Section --}}
                    <div>
                        <p class="px-3 mb-1.5 text-[9px] font-black uppercase tracking-[0.15em] text-white/40">{{ __('pos.nav_inventory') }}</p>
                        <a href="{{ route('fbrpos.products') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.products') && !request()->routeIs('fbrpos.products.create') && !request()->routeIs('fbrpos.products.edit') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                            {{ __('pos.products_word') }}
                        </a>
                        <a href="{{ route('fbrpos.products.create') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.products.create') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            {{ __('pos.nav_add_product') }}
                        </a>
                        {{-- Task 1260: Customers page — every role may view/add (mirrors PRA). --}}
                        <a href="{{ route('fbrpos.customers') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.customers') || request()->routeIs('fbrpos.customers.history') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            {{ __('pos.nav_customers') }}
                        </a>
                        @if (!in_array(auth('fbrpos')->user()->pos_role ?? '', ['pos_cashier', 'local_viewer'], true))
                        <a href="{{ route('fbrpos.stock') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.stock') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                            {{ __('pos.nav_stock_purchase') }}
                        </a>
                        @if($fbrPlanKhata)
                        <a href="{{ route('fbrpos.khata') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.khata') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                            {{ __('pos.dc_udhaar') }}
                        </a>
                        @endif
                        <a href="{{ route('fbrpos.munafa') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.munafa') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                            {{ __('pos.munafa_report') }}
                        </a>
                        @endif
                        @if (auth('fbrpos')->user()->role === 'company_admin' || (auth('fbrpos')->user()->pos_role ?? '') === 'pos_admin')
                        <a href="{{ route('fbrpos.team') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.team') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            {{ __('pos.team_management') }}
                        </a>
                        <a href="{{ route('fbrpos.branches') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.branches') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21h18M3 7v14m18-14v14M6 11h.01M6 15h.01M10 11h.01M10 15h.01M14 11h.01M14 15h.01M18 11h.01M18 15h.01M4 7l8-4 8 4H4z"/></svg>
                            {{ __('pos.branches_title') }}
                        </a>
                        @endif
                    </div>

                    {{-- Reports Section --}}
                    <div>
                        <p class="px-3 mb-1.5 text-[9px] font-black uppercase tracking-[0.15em] text-white/40">{{ __('pos.reports') }}</p>
                        <a href="{{ route('fbrpos.day-close') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.day-close') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <span class="flex-1">{{ __('pos.nav_day_close_z') }}</span>
                        </a>
                        <a href="{{ route('fbrpos.reports') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.reports') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                            {{ __('pos.nav_sales_reports') }}
                        </a>
                        <a href="{{ route('fbrpos.tax-reports') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.tax-reports') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                            {{ __('pos.nav_tax_reports') }}
                        </a>
                    </div>

                    {{-- Operations Section (Phase 2 features) --}}
                    <div>
                        <p class="px-3 mb-1.5 text-[9px] font-black uppercase tracking-[0.15em] text-white/40">{{ __('pos.nav_operations') }}</p>
                        <a href="{{ route('fbrpos.phase2.shifts') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.phase2.shifts') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            {{ __('pos.nav_shifts_cash_drawer') }}
                        </a>
                        @if($fbrPlanDeals)
                        <a href="{{ route('fbrpos.phase2.promotions') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.phase2.promotions') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                            {{ __('pos.nav_promotions') }}
                        </a>
                        @endif
                        @if($fbrPlanLoyalty)
                        <a href="{{ route('fbrpos.phase2.loyalty') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.phase2.loyalty') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                            {{ __('pos.nav_loyalty_program') }}
                        </a>
                        @endif
                    </div>

                    {{-- Setup Section --}}
                    <div>
                        <p class="px-3 mb-1.5 text-[9px] font-black uppercase tracking-[0.15em] text-white/40">{{ __('pos.nav_setup') }}</p>
                        <a href="{{ route('fbrpos.customize') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.customize') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                            {{ __('pos.nav_customize_pos') }}
                        </a>
                        {{-- Task 579: admin-only (edits company CNIC login identifier). --}}
                        @if (auth('fbrpos')->user()->isPosAdmin())
                        <a href="{{ route('fbrpos.business-profile') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.business-profile') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                            {{ __('pos.nav_business_profile') }}
                        </a>
                        @endif
                        <a href="{{ route('fbrpos.settings') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.settings') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            {{ __('pos.nav_fbr_settings') }}
                        </a>
                        <a href="{{ route('fbrpos.billing') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.billing') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                            {{ __('pos.nav_billing_subscription') }}
                        </a>
                        <a href="{{ route('fbrpos.my-profile') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.my-profile') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            {{ __('pos.nav_my_profile') }}
                        </a>
                        <a href="{{ route('fbrpos.tutorials') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.tutorials') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            {{ __('pos.nav_tutorials') }}
                        </a>
                        @if (auth('fbrpos')->user()->isPosAdmin())
                        <a href="{{ route('fbrpos.suggestions') }}" class="{{ $sidebarBase }} {{ request()->routeIs('fbrpos.suggestions') ? $sidebarActive : $sidebarInactive }}">
                            <svg class="w-4 h-4 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                            {{ __('pos.feature_suggestion_box') }}
                        </a>
                        @endif
                        {{-- PWA install — always visible --}}
                        <x-pwa-install-menu-item color="blue" app-name="Nest FBR POS" :label="__('pos.install_app_device')" :item-class="$sidebarBase . ' ' . $sidebarInactive" />
                    </div>
                </nav>

                {{-- Drawer Footer: Sign Out --}}
                <div class="border-t border-white/10 p-2 flex-shrink-0">
                    <form method="POST" action="{{ route('fbrpos.logout') }}">
                        @csrf
                        <button type="submit" class="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-semibold text-red-200 hover:bg-red-500/20 hover:text-white transition">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                            {{ __('pos.sign_out') }}
                        </button>
                    </form>
                    <p class="text-center text-[9px] text-white/30 mt-1.5 pt-1.5 border-t border-white/5">{{ __('pos.sidebar_footer_hint') }}</p>
                </div>
            </aside>

            <main class="flex-1 overflow-y-auto overflow-x-hidden main-scroll page-fade fbr-page-bg" style="min-width: 0;">
                <x-trial-reminder-banner />
                <x-payment-status-banner />
                <x-bio-unmapped-pin-banner :alerts="$bioAlerts"
                    :dismiss-route="route('fbrpos.bio-sync.dismiss-pin-alert')"
                    :setup-route="route('fbrpos.bio-sync.setup')" />
                @if(session('success'))
                    <div class="max-w-7xl mx-auto mb-4 px-4 sm:px-6 pt-4">
                        <div class="bg-emerald-50 dark:bg-emerald-900/30 border-2 border-emerald-300 dark:border-emerald-700 text-emerald-800 dark:text-emerald-200 px-4 py-3 rounded-lg font-semibold shadow-sm">
                            {{ session('success') }}
                        </div>
                    </div>
                @endif
                @if(session('warning'))
                    <div class="max-w-7xl mx-auto mb-4 px-4 sm:px-6 pt-2">
                        <div class="bg-amber-50 dark:bg-amber-900/30 border border-amber-300 dark:border-amber-700 text-amber-800 dark:text-amber-200 px-4 py-3 rounded-lg flex items-start gap-3">
                            <span class="text-xl leading-none">⚠️</span>
                            <div class="flex-1">{{ session('warning') }}
                                @if(str_contains(strtolower(session('warning')), 'token'))
                                    <a href="{{ route('fbrpos.settings') }}" class="ml-2 inline-flex items-center px-2.5 py-1 rounded-md bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold">{{ __('pos.configure_fbr_token') }}</a>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
                @if(session('error'))
                    <div class="max-w-7xl mx-auto mb-4 px-4 sm:px-6 pt-4">
                        <div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg">
                            {{ session('error') }}
                        </div>
                    </div>
                @endif

                <div class="max-w-7xl mx-auto px-4 sm:px-6 pt-3">
                    <x-pwa-banner color="blue" appName="Nest FBR Pos" />
                </div>

                {{ $slot }}
            </main>
            {{-- NOTE: outer fbrPosHeader x-data div closes AFTER modals (~line 610) so localOpen/failedOpen + handlers stay in scope --}}
        <x-pwa-push scope="fbrpos" />

        @php
            $toastMessages = [];
            if(session('success')) $toastMessages[] = ['msg' => session('success'), 'type' => 'success'];
            if(session('warning')) $toastMessages[] = ['msg' => session('warning'), 'type' => 'warning'];
            if(session('error')) $toastMessages[] = ['msg' => session('error'), 'type' => 'error'];
        @endphp
        <div x-data="{ toasts: [], init() { const msgs = JSON.parse(this.$el.dataset.messages || '[]'); msgs.forEach(m => this.addToast(m.msg, m.type)); }, addToast(msg, type) { let id = Date.now() + Math.random(); this.toasts.push({id, msg, type}); setTimeout(() => this.toasts = this.toasts.filter(t => t.id !== id), 6000); } }" data-messages="{{ json_encode($toastMessages) }}" class="fixed top-4 right-4 z-50 space-y-2" style="pointer-events: none;">
            <template x-for="toast in toasts" :key="toast.id">
                <div x-transition class="px-4 py-3 rounded-xl shadow-lg border-2 text-sm font-semibold max-w-sm" style="pointer-events: auto;"
                    :class="toast.type === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' :
                            toast.type === 'warning' ? 'bg-amber-50 border-amber-300 text-amber-800' :
                            'bg-red-50 border-red-200 text-red-800'">
                    <span x-text="toast.msg"></span>
                </div>
            </template>
        </div>
        <script>
        // Smart prefetch of FBR POS sale screen — skip on slow/save-data connections
        (function(){
            try {
                var c = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
                if (c && (c.saveData || /2g/.test(c.effectiveType || ''))) return;
                if (location.pathname.indexOf('/fbr-pos/create') === 0) return;
                var run = function(){
                    var l = document.createElement('link');
                    l.rel = 'prefetch'; l.href = '/fbr-pos/create'; l.as = 'document';
                    document.head.appendChild(l);
                };
                ('requestIdleCallback' in window) ? requestIdleCallback(run, {timeout: 4000}) : setTimeout(run, 2500);
            } catch(_){}
        })();
        </script>
        <x-pwa-update color="blue" />


        {{-- ═══════════════════════════════════════════════════════════════════
             🎯 UNIVERSAL HEADER MODALS — Local Bills (F10) + Failed Bills (F11)
             Always-on. Available on every FBR POS page via fbrPosHeader() x-data.
             ═══════════════════════════════════════════════════════════════════ --}}

        {{-- LOCAL BILLS MODAL (inside fbrPosHeader x-data scope) --}}
        <div x-show="localOpen" x-cloak @keydown.window="if(localOpen) handleLocalKeys($event)"
             class="fixed inset-0 z-[200] flex items-start justify-center pt-16 px-4 bg-black/50 backdrop-blur-sm"
             @click.self="localOpen = false">
            <div class="w-full max-w-2xl bg-white dark:bg-gray-900 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 overflow-hidden" x-transition>
                <div class="px-5 py-3 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between bg-gradient-to-r from-amber-50 to-amber-100 dark:from-amber-950/30 dark:to-amber-900/20">
                    <div>
                        <h3 class="text-base font-black text-amber-900 dark:text-amber-200 flex items-center gap-2">
                            <span>🟦 {{ __('pos.local_provisional_bills') }}</span>
                            <span x-text="localCount + @js(__('pos.total_suffix'))" class="px-2 py-0.5 rounded-full bg-amber-200 dark:bg-amber-800 text-[10px] font-bold"></span>
                        </h3>
                        <p class="text-[11px] text-amber-700 dark:text-amber-300 mt-0.5">{{ __('pos.local_modal_nav_hint') }}</p>
                    </div>
                    <button @click="localOpen = false" class="p-1.5 rounded-lg hover:bg-amber-200/50 dark:hover:bg-amber-800/40"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <div class="max-h-[60vh] overflow-y-auto">
                    <template x-if="localBills.length === 0">
                        <div class="p-10 text-center text-gray-400 text-sm">{{ __('pos.no_local_bills_hint') }}</div>
                    </template>
                    <template x-for="(bill, idx) in localBills" :key="bill.id">
                        <div :class="idx === localSelectedIdx ? 'bg-amber-50 dark:bg-amber-900/20 border-l-4 border-amber-500' : 'border-l-4 border-transparent'"
                             class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-800/40 cursor-pointer"
                             @click="localSelectedIdx = idx">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono font-bold text-sm text-gray-800 dark:text-gray-200" x-text="bill.invoice_number"></span>
                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300 font-bold uppercase tracking-wide">{{ __('pos.local_word') }}</span>
                                </div>
                                <div class="text-[11px] text-gray-500 mt-0.5" x-text="bill.customer_name + ' · ' + bill.created_at"></div>
                            </div>
                            <div class="text-right ml-3">
                                <div class="font-black text-base text-gray-900 dark:text-white">Rs <span x-text="Number(bill.total_amount).toLocaleString()"></span></div>
                                <div class="flex gap-1 mt-1">
                                    <button @click.stop="promoteLocal(bill.id)" class="text-[10px] px-2 py-0.5 rounded bg-emerald-600 hover:bg-emerald-700 text-white font-bold">{{ __('pos.promote_btn') }}</button>
                                    <button @click.stop="deleteLocal(bill.id)" class="text-[10px] px-2 py-0.5 rounded bg-red-600 hover:bg-red-700 text-white font-bold">{{ __('pos.delete') }}</button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- FAILED BILLS MODAL (inside fbrPosHeader x-data scope) --}}
        <div x-show="failedOpen" x-cloak @keydown.window="if(failedOpen) handleFailedKeys($event)"
             class="fixed inset-0 z-[200] flex items-start justify-center pt-16 px-4 bg-black/50 backdrop-blur-sm"
             @click.self="failedOpen = false">
            <div class="w-full max-w-2xl bg-white dark:bg-gray-900 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 overflow-hidden" x-transition>
                <div class="px-5 py-3 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between bg-gradient-to-r from-red-50 to-red-100 dark:from-red-950/30 dark:to-red-900/20">
                    <div>
                        <h3 class="text-base font-black text-red-900 dark:text-red-200 flex items-center gap-2">
                            <span>🟥 {{ __('pos.failed_fbr_bills') }}</span>
                            <span x-text="failedCount + @js(__('pos.total_suffix'))" class="px-2 py-0.5 rounded-full bg-red-200 dark:bg-red-800 text-[10px] font-bold"></span>
                        </h3>
                        <p class="text-[11px] text-red-700 dark:text-red-300 mt-0.5">{{ __('pos.failed_modal_nav_hint') }}</p>
                    </div>
                    <button @click="failedOpen = false" class="p-1.5 rounded-lg hover:bg-red-200/50 dark:hover:bg-red-800/40"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <div class="max-h-[60vh] overflow-y-auto">
                    <template x-if="failedBills.length === 0">
                        <div class="p-10 text-center text-gray-400 text-sm">{{ __('pos.no_failed_bills_hint') }}</div>
                    </template>
                    <template x-for="(bill, idx) in failedBills" :key="bill.id">
                        <div :class="idx === failedSelectedIdx ? 'bg-red-50 dark:bg-red-900/20 border-l-4 border-red-500' : 'border-l-4 border-transparent'"
                             class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-800/40 cursor-pointer"
                             @click="failedSelectedIdx = idx">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono font-bold text-sm text-gray-800 dark:text-gray-200" x-text="bill.invoice_number"></span>
                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-300 font-bold uppercase tracking-wide" x-text="bill.fbr_status || 'failed'"></span>
                                </div>
                                <div class="text-[11px] text-gray-500 mt-0.5 truncate" x-text="(bill.customer_name || @js(__('pos.walk_in'))) + ' · ' + (bill.created_at || '')"></div>
                                <template x-if="bill.error_message">
                                    <div class="text-[10px] text-red-600 dark:text-red-400 mt-0.5 truncate" x-text="bill.error_message"></div>
                                </template>
                            </div>
                            <div class="text-right ml-3">
                                <div class="font-black text-base text-gray-900 dark:text-white">Rs <span x-text="Number(bill.total_amount).toLocaleString()"></span></div>
                                <div class="flex gap-1 mt-1">
                                    <button @click.stop="retryFailed(bill.id)" :disabled="bill._retrying" class="text-[10px] px-2 py-0.5 rounded bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white font-bold">↻ <span x-text="bill._retrying ? @js(__('pos.retrying_word')) : @js(__('pos.retry_word'))"></span></button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
        </div>{{-- /fbrPosHeader x-data root --}}

        <script>
            function fbrPosHeader(initialTheme) {
                return {
                    profileOpen: false,
                    mobileMenuOpen: false,
                    themeOpen: false,
                    sidebarOpen: false,
                    currentTheme: initialTheme || 'blue',
                    localOpen: false,
                    failedOpen: false,
                    localBills: [],
                    failedBills: [],
                    localCount: 0,
                    failedCount: 0,
                    localSelectedIdx: 0,
                    failedSelectedIdx: 0,
                    _csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',
                    init() {
                        this.refreshCounts();
                        // 🔋 Visibility-aware polling — only refresh when tab visible. 2-min interval (was 45s) to reduce DB load
                        setInterval(() => {
                            if (document.visibilityState === 'visible') this.refreshCounts();
                        }, 120000);
                        document.addEventListener('visibilitychange', () => {
                            if (document.visibilityState === 'visible') this.refreshCounts();
                        });
                        // 🌐 Universal sale screen dispatches this after every save/promote/delete
                        // so the header Local/Failed badges stay live without waiting for the 2-min poll.
                        window.addEventListener('fbr-bills-refresh', () => this.refreshCounts());
                        // 🎹 F10 = Local, Shift+F11 = Failed, Ctrl+M = Sidebar Menu
                        window.addEventListener('keydown', (e) => {
                            if (e.key === 'F10' && !e.shiftKey && !e.ctrlKey && !e.altKey) {
                                e.preventDefault();
                                e.stopImmediatePropagation();
                                this.openLocal();
                            } else if (e.key === 'F11' && e.shiftKey) {
                                e.preventDefault();
                                e.stopImmediatePropagation();
                                this.openFailed();
                            } else if ((e.key === 'm' || e.key === 'M') && e.ctrlKey && !e.shiftKey && !e.altKey) {
                                e.preventDefault();
                                e.stopImmediatePropagation();
                                this.sidebarOpen = !this.sidebarOpen;
                            }
                        }, true); // capture phase — runs before page handlers
                    },
                    async refreshCounts() {
                        try {
                            const [lr, fr] = await Promise.all([
                                fetch('{{ route('fbrpos.api.provisional-bills') }}', {credentials: 'same-origin'}).then(r => r.json()).catch(() => ({count: 0})),
                                fetch('{{ route('fbrpos.api.failed-bills') }}', {credentials: 'same-origin'}).then(r => r.json()).catch(() => ({count: 0})),
                            ]);
                            this.localCount = lr.count || (lr.bills || []).length || 0;
                            this.failedCount = fr.count || (fr.bills || []).length || 0;
                        } catch (e) { /* silent */ }
                    },
                    async openLocal() {
                        this.failedOpen = false;
                        this.localOpen = true;
                        this.localSelectedIdx = 0;
                        try {
                            const r = await fetch('{{ route('fbrpos.api.provisional-bills') }}', {credentials: 'same-origin'}).then(r => r.json());
                            this.localBills = r.bills || [];
                            this.localCount = this.localBills.length;
                        } catch (e) { this.localBills = []; }
                    },
                    async openFailed() {
                        this.localOpen = false;
                        this.failedOpen = true;
                        this.failedSelectedIdx = 0;
                        try {
                            const r = await fetch('{{ route('fbrpos.api.failed-bills') }}', {credentials: 'same-origin'}).then(r => r.json());
                            this.failedBills = (r.bills || []).map(b => ({...b, _retrying: false}));
                            this.failedCount = this.failedBills.length;
                        } catch (e) { this.failedBills = []; }
                    },
                    handleLocalKeys(e) {
                        const tag = (e.target?.tagName || '').toUpperCase();
                        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) return;
                        if (e.key === 'ArrowDown') { e.preventDefault(); this.localSelectedIdx = Math.min(this.localSelectedIdx + 1, this.localBills.length - 1); }
                        else if (e.key === 'ArrowUp') { e.preventDefault(); this.localSelectedIdx = Math.max(this.localSelectedIdx - 1, 0); }
                        else if (e.key === 'Enter') { e.preventDefault(); const b = this.localBills[this.localSelectedIdx]; if (b) this.promoteLocal(b.id); }
                        else if (e.key === 'd' || e.key === 'D') { e.preventDefault(); const b = this.localBills[this.localSelectedIdx]; if (b) this.deleteLocal(b.id); }
                    },
                    handleFailedKeys(e) {
                        const tag = (e.target?.tagName || '').toUpperCase();
                        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) return;
                        if (e.key === 'ArrowDown') { e.preventDefault(); this.failedSelectedIdx = Math.min(this.failedSelectedIdx + 1, this.failedBills.length - 1); }
                        else if (e.key === 'ArrowUp') { e.preventDefault(); this.failedSelectedIdx = Math.max(this.failedSelectedIdx - 1, 0); }
                        else if (e.key === 'Enter') { e.preventDefault(); const b = this.failedBills[this.failedSelectedIdx]; if (b) this.retryFailed(b.id); }
                    },
                    async deleteLocal(id) {
                        if (!confirm(@js(__('pos.delete_local_bill_confirm')))) return;
                        const r = await fetch(`/fbr-pos/api/provisional-bills/${id}/delete`, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': this._csrf},
                            credentials: 'same-origin'
                        }).then(r => r.json());
                        if (r.success) {
                            this.localBills = this.localBills.filter(b => b.id !== id);
                            this.localCount = this.localBills.length;
                            if (this.localSelectedIdx >= this.localBills.length) this.localSelectedIdx = Math.max(0, this.localBills.length - 1);
                        } else { alert(r.message || @js(__('pos.failed_to_delete'))); }
                    },
                    async promoteLocal(id) {
                        const r = await fetch(`/fbr-pos/api/provisional-bills/${id}/promote`, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': this._csrf},
                            credentials: 'same-origin'
                        }).then(r => r.json()).catch(() => ({success: false, message: @js(__('pos.network_error'))}));
                        if (r.success) {
                            // Remove from local list in place — no redirect to avoid cart loss on /create
                            this.localBills = this.localBills.filter(b => b.id !== id);
                            this.localCount = this.localBills.length;
                            if (this.localSelectedIdx >= this.localBills.length) this.localSelectedIdx = Math.max(0, this.localBills.length - 1);
                            // Refresh failed count (promoted bill now sits in fail queue as pending)
                            this.refreshCounts();
                            // Only redirect if user is on a "safe" page (NOT /fbr-pos/create where cart would be lost)
                            const onCreate = location.pathname.indexOf('/fbr-pos/create') === 0;
                            if (!onCreate && this.localBills.length === 0) {
                                this.localOpen = false;
                                window.location.href = r.redirect || '{{ route('fbrpos.failQueue') }}';
                            }
                        } else { alert(r.message || @js(__('pos.promote_failed'))); }
                    },
                    async retryFailed(id) {
                        const bill = this.failedBills.find(b => b.id === id);
                        if (!bill || bill._retrying) return;
                        bill._retrying = true;
                        try {
                            const r = await fetch(`/fbr-pos/api/failed-bills/${id}/retry`, {
                                method: 'POST',
                                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': this._csrf},
                                credentials: 'same-origin'
                            }).then(r => r.json());
                            if (r.success) {
                                this.failedBills = this.failedBills.filter(b => b.id !== id);
                                this.failedCount = this.failedBills.length;
                                if (this.failedSelectedIdx >= this.failedBills.length) this.failedSelectedIdx = Math.max(0, this.failedBills.length - 1);
                            } else {
                                bill._retrying = false;
                                alert(r.message || @js(__('pos.retry_failed')));
                            }
                        } catch (e) { bill._retrying = false; alert(@js(__('pos.network_error'))); }
                    },
                };
            }
        </script>
        @if($whatsNewPopup)
        @if($whatsNewFeatured)
        {{-- ⭐ FEATURED "bara elaan" popup (Task 722) — celebratory hero style for big
             features. Same gating (admin/manager, not pending, not readonly-imp) and
             same seen flow as the normal popup below. Inline <style> ON PURPOSE:
             new arbitrary Tailwind classes are invisible without a Vite rebuild. --}}
        <style>
            @keyframes wnfPop { 0% { opacity: 0; transform: scale(0.85) translateY(16px); } 100% { opacity: 1; transform: scale(1) translateY(0); } }
            @keyframes wnfBadge { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.07); } }
            @keyframes wnfSparkle { 0%, 100% { opacity: 0.35; transform: translateY(0) scale(1); } 50% { opacity: 1; transform: translateY(-6px) scale(1.3); } }
            @keyframes wnfSheen { 0% { transform: translateX(-160%) skewX(-18deg); } 60%, 100% { transform: translateX(320%) skewX(-18deg); } }
            .wnf-card { animation: wnfPop 0.3s cubic-bezier(0.34, 1.4, 0.64, 1); }
            .wnf-badge { animation: wnfBadge 1.7s ease-in-out infinite; }
            .wnf-sparkle { position: absolute; pointer-events: none; animation: wnfSparkle 2.2s ease-in-out infinite; }
            .wnf-cta { position: relative; overflow: hidden; }
            .wnf-cta::after { content: ''; position: absolute; top: 0; bottom: 0; left: 0; width: 45%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.35), transparent); animation: wnfSheen 2.6s ease-in-out infinite; }
        </style>
        <div x-data="{ wnOpen: true,
                wnDismiss() {
                    this.wnOpen = false;
                    fetch('/fbr-pos/whats-new/seen', { method: 'POST', keepalive: true, headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' } }).catch(() => {});
                },
                wnTry(url) { this.wnDismiss(); window.location.href = url; } }"
             x-show="wnOpen" x-cloak data-wn-featured="1"
             class="fixed inset-0 flex items-center justify-center p-4"
             style="z-index: 130; background: rgba(5, 15, 40, 0.62); backdrop-filter: blur(5px);">
            <div class="wnf-card w-full max-w-lg bg-white dark:bg-gray-900 rounded-2xl overflow-hidden"
                 style="box-shadow: 0 30px 80px -20px rgba(0,0,0,0.6), 0 0 0 1px rgba(255,255,255,0.08);">
                <div class="relative px-6 pt-6 pb-5 text-center overflow-hidden"
                     style="background: linear-gradient(135deg, hsl(var(--accent-h), var(--accent-s), 18%) 0%, hsl(var(--accent-h), var(--accent-s), 40%) 55%, hsl(var(--accent-h), var(--accent-s), 28%) 100%);">
                    <span class="wnf-sparkle text-lg" style="top: 12%; left: 7%;">✨</span>
                    <span class="wnf-sparkle text-sm" style="top: 58%; left: 15%; animation-delay: 0.6s;">✨</span>
                    <span class="wnf-sparkle text-base" style="top: 18%; right: 9%; animation-delay: 1.1s;">✨</span>
                    <span class="wnf-sparkle text-sm" style="top: 62%; right: 17%; animation-delay: 1.6s;">⭐</span>
                    <div class="wnf-badge inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-[12px] font-black uppercase tracking-wide"
                         style="background: linear-gradient(135deg, #fbbf24, #f59e0b); color: #451a03; box-shadow: 0 6px 18px -6px rgba(245,158,11,0.7);">
                        🎉 {{ __('pos.wn_featured_badge') }}
                    </div>
                    <h2 class="mt-3 text-2xl font-extrabold text-white leading-snug" style="text-shadow: 0 2px 10px rgba(0,0,0,0.25);">{{ $whatsNewFeatured->title }}</h2>
                    <p class="text-[12px] text-white/75 mt-1.5"><x-wn-type-badge :update="$whatsNewFeatured" :light="true" /> · {{ $whatsNewFeatured->created_at->format('d M Y') }}</p>
                </div>
                <div class="px-6 py-5 overflow-y-auto" style="max-height: 52vh;">
                    @if($whatsNewFeatured->image_path ?? null)
                        <img src="{{ asset('storage/' . $whatsNewFeatured->image_path) }}" alt="{{ __('pos.update_image_alt') }}" loading="lazy"
                             class="w-full rounded-xl mb-4 cursor-zoom-in"
                             style="box-shadow: 0 10px 30px -12px rgba(0,0,0,0.35), 0 0 0 1px rgba(0,0,0,0.06);"
                             onclick="window.open(this.src, '_blank')">
                    @endif
                    <ul class="space-y-2.5">
                        @foreach(($whatsNewFeatured->points ?? []) as $wnpt)
                            <li class="flex items-start gap-2.5 text-sm text-gray-700 dark:text-gray-200">
                                <span class="flex-shrink-0 w-5 h-5 rounded-full flex items-center justify-center mt-0.5" style="background: linear-gradient(135deg, #fef3c7, #fde68a);">
                                    <svg class="w-3 h-3" style="color: #b45309;" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.958a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.368 2.447a1 1 0 00-.363 1.118l1.287 3.957c.3.922-.755 1.688-1.539 1.118l-3.367-2.446a1 1 0 00-1.175 0l-3.367 2.446c-.784.57-1.838-.196-1.539-1.118l1.287-3.957a1 1 0 00-.363-1.118L2.063 9.385c-.783-.57-.38-1.81.588-1.81h4.162a1 1 0 00.95-.69l1.286-3.958z"/></svg>
                                </span>
                                <span>{{ $wnpt }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if($whatsNewPopupList->count() > 1)
                        <div class="mt-5 pt-4 border-t border-gray-200 dark:border-gray-700">
                            <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-3">{{ __('pos.wn_featured_more') }}</p>
                            @foreach($whatsNewPopupList->reject(fn ($u) => $u->id === $whatsNewFeatured->id) as $wnp)
                                <div class="{{ $loop->first ? '' : 'mt-4 pt-3 border-t border-gray-100 dark:border-gray-800' }}">
                                    <p class="text-sm font-extrabold text-gray-900 dark:text-white mb-1.5">{{ $wnp->title }} <x-wn-type-badge :update="$wnp" /> <span class="font-normal text-[11px] text-gray-400">· {{ $wnp->created_at->format('d M Y') }}</span></p>
                                    <ul class="space-y-1.5">
                                        @foreach(($wnp->points ?? []) as $wnpt)
                                            <li class="flex items-start gap-2 text-[13px] text-gray-600 dark:text-gray-300">
                                                <svg class="flex-shrink-0 w-3.5 h-3.5 text-blue-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                <span>{{ $wnpt }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="px-6 pb-5 flex items-center gap-2.5">
                    {{-- route(..., false) = RELATIVE path — absolute https:// URLs break on plain-http dev browsing (forceScheme trap) --}}
                    <button @click="wnTry('{{ route('fbrpos.transactions', [], false) }}')" x-ref="wnfCta" x-init="$nextTick(() => $refs.wnfCta.focus())"
                            class="wnf-cta flex-1 py-3.5 rounded-xl text-white font-extrabold text-sm transition cursor-pointer"
                            style="background: linear-gradient(135deg, hsl(var(--accent-h), var(--accent-s), 48%), hsl(var(--accent-h), var(--accent-s), 32%)); box-shadow: 0 10px 24px -8px hsla(var(--accent-h), var(--accent-s), 40%, 0.6);">
                        {{ __('pos.wn_featured_try_now') }} →
                    </button>
                    <button @click="wnDismiss()"
                            class="px-4 py-3.5 rounded-xl text-sm font-bold text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition cursor-pointer">
                        {{ __('pos.whats_new_got_it') }}
                    </button>
                </div>
            </div>
        </div>
        @else
        {{-- One-time "What's New" popup — dismiss marks ALL current updates seen (per user) --}}
        <div x-data="{ wnOpen: true,
                wnDismiss() {
                    this.wnOpen = false;
                    fetch('/fbr-pos/whats-new/seen', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' } }).catch(() => {});
                } }"
             x-show="wnOpen" x-cloak
             class="fixed inset-0 flex items-center justify-center p-4"
             style="z-index: 130; background: rgba(5, 15, 40, 0.55); backdrop-filter: blur(4px);">
            <div class="w-full max-w-md bg-white dark:bg-gray-900 rounded-2xl shadow-2xl overflow-hidden"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 scale-90"
                 x-transition:enter-end="opacity-100 scale-100">
                <div class="px-6 py-5 text-center" style="background: linear-gradient(135deg, hsl(var(--accent-h), var(--accent-s), 42%), hsl(var(--accent-h), var(--accent-s), 28%));">
                    <div class="text-4xl mb-1">🎉</div>
                    <h2 class="text-xl font-extrabold text-white">{{ $whatsNewUnseenCount > 1 ? __('pos.whats_new_many', ['count' => $whatsNewUnseenCount]) : __('pos.whats_new_one') }}</h2>
                    @if($whatsNewUnseenCount === 1)
                        <p class="text-[12px] text-white/80 mt-1">{{ $whatsNewPopup->title }} <x-wn-type-badge :update="$whatsNewPopup" :light="true" /> · {{ $whatsNewPopup->created_at->format('d M Y') }}</p>
                    @else
                        <p class="text-[12px] text-white/80 mt-1">{{ __('pos.whats_new_scroll_hint') }}</p>
                    @endif
                </div>
                <div class="px-6 py-5 overflow-y-auto" style="max-height: 62vh;">
                    @foreach($whatsNewPopupList as $wnp)
                    <div class="{{ $loop->first ? '' : 'mt-5 pt-4 border-t border-gray-200 dark:border-gray-700' }}">
                        @if($whatsNewUnseenCount > 1)
                            <p class="text-sm font-extrabold text-gray-900 dark:text-white mb-2">{{ $wnp->title }} <x-wn-type-badge :update="$wnp" /> <span class="font-normal text-[11px] text-gray-400">· {{ $wnp->created_at->format('d M Y') }}</span></p>
                        @endif
                        @if($wnp->image_path ?? null)
                            <img src="{{ asset('storage/' . $wnp->image_path) }}" alt="{{ __('pos.update_image_alt') }}" loading="lazy"
                                 class="w-full rounded-xl border border-gray-200 dark:border-gray-700 mb-4 cursor-zoom-in"
                                 onclick="window.open(this.src, '_blank')">
                        @endif
                        <ul class="space-y-2.5">
                            @foreach(($wnp->points ?? []) as $wnpt)
                                <li class="flex items-start gap-2.5 text-sm text-gray-700 dark:text-gray-200">
                                    <span class="flex-shrink-0 w-5 h-5 rounded-full bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center mt-0.5">
                                        <svg class="w-3 h-3 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
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
                            class="w-full py-3 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm shadow-sm transition cursor-pointer">
                        {{ __('pos.whats_new_got_it') }}
                    </button>
                </div>
            </div>
        </div>
        @endif
        @endif
        <x-trial-lock-modal />
        <x-subscription-expiry-popup />
        {{-- Task 1275: Madadgar support bubble (AI chat + WhatsApp) — FBR flavor --}}
        <x-madadgar-support product="fbrpos" />
        <script src="{{ asset('js/wheel-scroll.js?v=1') }}" defer></script>
    </body>
</html>
