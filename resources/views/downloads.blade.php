@php
    // Agent release info (cached 10 min; safe fallback when GitHub unreachable).
    $release = \App\Http\Controllers\AgentManagementController::latestReleaseInfo();
    $agentTag = $release['tag'] ?? null;
    $exeAsset = collect($release['assets'] ?? [])->filter(fn($a) => str_ends_with(strtolower($a['name']), '.exe'))->sortByDesc('size')->first();
    $zipAsset = collect($release['assets'] ?? [])->filter(fn($a) => str_ends_with(strtolower($a['name']), '.zip'))->sortByDesc('size')->first();
    $fmtMb = fn($bytes) => $bytes ? number_format($bytes / 1048576, 1) . ' MB' : null;

    $posApkPath = public_path('downloads/taxnest-pos.apk');
    $riderApkPath = public_path('downloads/taxnest-rider.apk');
    $diApkPath = public_path('downloads/taxnest-di.apk');
    $posApkSize = is_file($posApkPath) ? $fmtMb(filesize($posApkPath)) : null;
    $riderApkSize = is_file($riderApkPath) ? $fmtMb(filesize($riderApkPath)) : null;
    // DI card: only show when both the APK file exists AND di_app_latest_version is set.
    // This lets us scp the file and owner-test before anything is customer-visible.
    $diApkVersion = trim((string) \App\Models\SystemSetting::get('di_app_latest_version', ''));
    $diApkSize = ($diApkVersion !== '' && is_file($diApkPath)) ? $fmtMb(filesize($diApkPath)) : null;
    $diApkVisible = $diApkVersion !== '' && is_file($diApkPath);
@endphp
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#052730">
    <link rel="icon" type="image/svg+xml" href="/images/brand/taxnest-mark.svg">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <title>Downloads — TaxNest</title>
    <meta name="description" content="Download the NestPOS Desktop Agent for Windows, the TaxNest POS app for Android, and the TaxNest Rider app — everything you need to run TaxNest on your devices.">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link rel="preload" as="style" href="https://fonts.bunny.net/css?family=playfair-display:400,600,700|inter:400,500,600,700,800&display=swap">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=playfair-display:400,600,700|inter:400,500,600,700,800&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.bunny.net/css?family=playfair-display:400,600,700|inter:400,500,600,700,800&display=swap"></noscript>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root { --teal-dark:#052730; --teal-main:#0A4D5C; --gold:#E7BF3B; --paper:#FDFBF7; --ink:#1F2937; }
        body { font-family:'Inter',system-ui,-apple-system,sans-serif; background:var(--paper); color:var(--ink); -webkit-font-smoothing:antialiased; }
        h1,h2,h3,.font-serif { font-family:'Playfair Display',serif; }
        [x-cloak]{display:none!important;}
        .grid-dark { background-image:linear-gradient(rgba(255,255,255,0.03) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,0.03) 1px,transparent 1px); background-size:40px 40px; }
    </style>
</head>
<body class="min-h-screen flex flex-col">

    <!-- Nav -->
    <nav class="bg-[#052730] border-b border-[#0A4D5C]">
        <div class="max-w-[1100px] mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between h-20">
            <a href="/"><img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" alt="TaxNest" class="h-8 w-auto"></a>
            <div class="flex items-center gap-6 text-sm font-medium text-white/70">
                <a href="/digital-invoice" class="hover:text-white transition-colors hidden sm:inline">Digital Invoice</a>
                <a href="/pos" class="hover:text-white transition-colors hidden sm:inline">NestPOS</a>
                <a href="/fbr-pos-landing" class="hover:text-white transition-colors hidden md:inline">FBR POS</a>
                <a href="/contact" class="hover:text-white transition-colors hidden md:inline">Contact</a>
                <a href="/login" class="text-white bg-white/10 hover:bg-white/20 px-4 py-2 rounded-md transition-colors">Log In</a>
            </div>
        </div>
    </nav>

    <!-- Header -->
    <header class="bg-[#052730] border-b border-[#0A4D5C] relative overflow-hidden">
        <div class="absolute inset-0 grid-dark opacity-60"></div>
        <div class="max-w-2xl mx-auto px-4 text-center py-20 relative z-10">
            <div class="inline-flex items-center px-3 py-1 bg-white/5 border border-white/10 rounded-full text-xs font-medium text-white/80 tracking-wide uppercase mb-6">
                <span class="w-1.5 h-1.5 rounded-full bg-[#E7BF3B] mr-2"></span> Apps &amp; Tools
            </div>
            <h1 class="text-4xl sm:text-5xl font-serif text-white mb-4">Downloads</h1>
            <p class="text-white/70 text-lg font-light">Everything you need to run TaxNest on your counter, in your pocket, and on the road — free with your subscription.</p>
        </div>
    </header>

    <main class="flex-1">
        <section class="max-w-[1100px] mx-auto px-4 sm:px-6 lg:px-8 -mt-10 relative z-20 pb-20">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">

                <!-- Desktop Agent (Windows) -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 flex flex-col">
                    <div class="w-11 h-11 rounded-lg bg-[#0A4D5C]/10 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </div>
                    <div class="flex items-center gap-2 mb-1">
                        <h3 class="font-serif text-lg text-[#052730]">NestPOS Desktop Agent</h3>
                    </div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-3">Windows 10 / 11{{ $agentTag ? ' · ' . $agentTag : '' }}</p>
                    <p class="text-sm text-gray-500 leading-relaxed flex-1">Silent thermal receipt &amp; KOT printing, offline billing, PRA fiscal-device support and the full-screen NestPOS desktop shell — install once on your counter PC.</p>
                    <div class="mt-5 space-y-2">
                        <a href="{{ route('public.agent.download', ['type' => 'exe']) }}" class="block text-center bg-[#0A4D5C] hover:bg-[#083D49] text-white text-sm font-semibold px-4 py-3 rounded-lg transition-colors">
                            Download for Windows{{ $exeAsset ? ' (' . $fmtMb($exeAsset['size']) . ')' : '' }}
                        </a>
                        @if($zipAsset)
                        <a href="{{ route('public.agent.download', ['type' => 'zip']) }}" class="block text-center text-xs text-gray-500 hover:text-[#0A4D5C] transition-colors">ZIP version ({{ $fmtMb($zipAsset['size']) }})</a>
                        @endif
                    </div>
                </div>

                <!-- TaxNest POS App (Android) -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 flex flex-col">
                    <div class="w-11 h-11 rounded-lg bg-[#E7BF3B]/20 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-[#B8912A]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                    <h3 class="font-serif text-lg text-[#052730] mb-1">TaxNest POS App</h3>
                    <p class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-3">Android 7+ · APK{{ $posApkSize ? ' · ' . $posApkSize : '' }}</p>
                    <p class="text-sm text-gray-500 leading-relaxed flex-1">Your complete POS panel on mobile — every team member signs in with their normal login (owner, admin, cashier, waiter, rider) and sees exactly their own screens. Always up to date automatically.</p>
                    <div class="mt-5">
                        <a href="{{ url('downloads/taxnest-pos.apk') }}" class="block text-center bg-[#0A4D5C] hover:bg-[#083D49] text-white text-sm font-semibold px-4 py-3 rounded-lg transition-colors">Download APK</a>
                    </div>
                </div>

                <!-- TaxNest DI App (Android) — only shown when di_app_latest_version is set in admin settings -->
                @if($diApkVisible)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 flex flex-col">
                    <div class="w-11 h-11 rounded-lg bg-emerald-50 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <h3 class="font-serif text-lg text-[#052730] mb-1">TaxNest DI App</h3>
                    <p class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-3">Android 7+ · APK{{ $diApkSize ? ' · ' . $diApkSize : '' }}{{ $diApkVersion ? ' · v' . $diApkVersion : '' }}</p>
                    <p class="text-sm text-gray-500 leading-relaxed flex-1">Your complete Digital Invoicing panel on mobile — create FBR invoices, track compliance, manage customers and download PDFs, all from your phone. Always up to date automatically.</p>
                    <div class="mt-5">
                        <a href="{{ url('downloads/taxnest-di.apk') }}" class="block text-center bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold px-4 py-3 rounded-lg transition-colors">Download APK</a>
                    </div>
                </div>
                @endif

                <!-- Rider App (Android) -->
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 flex flex-col">
                    <div class="w-11 h-11 rounded-lg bg-[#0A4D5C]/10 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </div>
                    <h3 class="font-serif text-lg text-[#052730] mb-1">TaxNest Rider App</h3>
                    <p class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-3">Android 8+ · APK{{ $riderApkSize ? ' · ' . $riderApkSize : '' }}</p>
                    <p class="text-sm text-gray-500 leading-relaxed flex-1">For delivery riders — duty on/off, assigned orders and live location sharing while on duty, so the shop can track deliveries on the map in real time.</p>
                    <div class="mt-5">
                        <a href="{{ url('downloads/taxnest-rider.apk') }}" class="block text-center bg-[#0A4D5C] hover:bg-[#083D49] text-white text-sm font-semibold px-4 py-3 rounded-lg transition-colors">Download APK</a>
                    </div>
                </div>
            </div>

            <!-- Install help -->
            <div class="mt-8 bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <h4 class="font-serif text-lg text-[#052730] mb-3">Installing an APK on Android</h4>
                <ol class="list-decimal pl-5 space-y-1.5 text-sm text-gray-600 leading-relaxed">
                    <li>Download the APK on your phone and tap the file in your notifications or Downloads folder.</li>
                    <li>If Android asks about "unknown apps", tap <strong>Settings → Allow from this source</strong>, then go back and tap <strong>Install</strong>. This appears because the app is installed directly from our website instead of the Play Store — it is signed by TaxNest and safe.</li>
                    <li>Open the app and sign in with the same login you use on the website.</li>
                </ol>
                <p class="text-xs text-gray-500 mt-4">The Desktop Agent pairs with your shop from inside your POS panel (Settings → Desktop App). Apps are free — features follow your TaxNest package.</p>
                <p class="text-sm text-gray-600 mt-3 font-medium">Koi bhi issue aaye — install, login ya printing ka — to <a href="/contact" class="text-[#0A4D5C] underline hover:no-underline">support ko message bhejein</a>, hum foran madad karenge.</p>
            </div>
        </section>
    </main>

    <x-site-footer />
    <x-whatsapp-support />
</body>
</html>
