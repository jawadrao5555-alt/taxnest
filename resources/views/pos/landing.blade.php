<!DOCTYPE html>
<html lang="en" class="overflow-x-hidden">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <title>NestPOS — Enterprise POS System with PRA Integration</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: 'Figtree', sans-serif; }
        .pos-gradient {
            background: linear-gradient(135deg, #1e1b4b 0%, #5b21b6 40%, #7c3aed 70%, #a855f7 100%);
            position: relative;
        }
        .pos-gradient::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.04'/%3E%3C/svg%3E");
            opacity: 0.4;
            pointer-events: none;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px) translateX(0px); }
            25% { transform: translateY(-20px) translateX(10px); }
            50% { transform: translateY(-10px) translateX(-5px); }
            75% { transform: translateY(-25px) translateX(5px); }
        }
        @keyframes float-reverse {
            0%, 100% { transform: translateY(0px) translateX(0px); }
            25% { transform: translateY(15px) translateX(-10px); }
            50% { transform: translateY(5px) translateX(8px); }
            75% { transform: translateY(20px) translateX(-5px); }
        }
        @keyframes shimmer {
            0% { background-position: -200% center; }
            100% { background-position: 200% center; }
        }
        @keyframes pulse-glow {
            0%, 100% { opacity: 0.4; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.05); }
        }
        .orb { position: absolute; border-radius: 9999px; filter: blur(80px); pointer-events: none; }
        .card-accent-top {
            position: relative;
        }
        .card-accent-top::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #7c3aed, #a855f7, #c084fc);
            border-radius: 12px 12px 0 0;
        }
    </style>
</head>
<body class="antialiased text-gray-800 overflow-x-hidden" style="scroll-behavior: smooth;" x-data="{ showLoginModal: false }">

    <div x-show="showLoginModal" x-cloak x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 backdrop-blur-sm px-4" @click.self="showLoginModal = false" @keydown.escape.window="showLoginModal = false">
        <div x-show="showLoginModal" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-95 translate-y-4" x-transition:enter-end="opacity-100 scale-100 translate-y-0" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" class="w-full max-w-md relative overflow-hidden rounded-2xl" style="background: linear-gradient(135deg, #1e1b4b 0%, #4c1d95 50%, #581c87 100%); box-shadow: 0 25px 60px -12px rgba(0,0,0,0.5);">
            <div class="absolute inset-0 overflow-hidden">
                <div class="absolute -top-20 -right-20 w-40 h-40 bg-purple-400/10 rounded-full blur-2xl"></div>
                <div class="absolute -bottom-10 -left-10 w-32 h-32 bg-violet-400/10 rounded-full blur-2xl"></div>
            </div>
            <div class="relative px-6 py-5">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-xl bg-white/10 backdrop-blur-sm flex items-center justify-center ring-1 ring-white/10">
                            <svg class="w-5 h-5 text-purple-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-white">NestPOS</h3>
                            <p class="text-purple-200/50 text-xs">PRA Point of Sale</p>
                        </div>
                    </div>
                    <button @click="showLoginModal = false" class="text-white/40 hover:text-white transition p-1">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
            <form method="POST" action="/pos/login" class="relative px-6 pb-6 space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-purple-100/70 mb-1.5">Email / Phone / Username / NTN</label>
                    <input type="text" name="login" required autofocus class="w-full px-4 py-2.5 rounded-xl text-sm text-white placeholder-purple-300/30 transition" style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); outline: none;" placeholder="Enter your credential" onfocus="this.style.borderColor='rgba(139,92,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(139,92,246,0.12)';" onblur="this.style.borderColor='rgba(255,255,255,0.12)'; this.style.boxShadow='none';">
                </div>
                <div>
                    <label class="block text-sm font-medium text-purple-100/70 mb-1.5">Password</label>
                    <input type="password" name="password" required autocomplete="current-password" class="w-full px-4 py-2.5 rounded-xl text-sm text-white placeholder-purple-300/30 transition" style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); outline: none;" placeholder="Enter your password" onfocus="this.style.borderColor='rgba(139,92,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(139,92,246,0.12)';" onblur="this.style.borderColor='rgba(255,255,255,0.12)'; this.style.boxShadow='none';">
                </div>
                <div class="flex items-center">
                    <input type="checkbox" name="remember" class="rounded bg-white/5 border-white/10 text-purple-500 focus:ring-purple-500 focus:ring-offset-0 mr-2 w-4 h-4">
                    <span class="text-sm text-purple-200/40">Remember me</span>
                </div>
                @if($errors->any())
                <div class="bg-red-500/10 border border-red-500/20 rounded-lg p-3">
                    @foreach($errors->all() as $error)
                    <p class="text-sm text-red-400">{{ $error }}</p>
                    @endforeach
                </div>
                @endif
                <button type="submit" class="w-full py-3 rounded-xl text-sm font-bold text-white transition-all duration-200" style="background: linear-gradient(135deg, #7c3aed, #a855f7); box-shadow: 0 4px 20px rgba(124, 58, 237, 0.4);" onmouseover="this.style.boxShadow='0 6px 28px rgba(124, 58, 237, 0.55)'; this.style.transform='translateY(-1px)';" onmouseout="this.style.boxShadow='0 4px 20px rgba(124, 58, 237, 0.4)'; this.style.transform='translateY(0)';">
                    Sign In
                </button>
                <p class="text-center text-sm text-purple-200/40">
                    Don't have an account? <a href="/pos/register" class="font-semibold text-purple-300 hover:text-white transition">POS Sign Up</a>
                </p>
            </form>
        </div>
    </div>

    <nav class="fixed top-0 w-full z-50 bg-white/95 backdrop-blur-2xl border-b border-gray-200/60 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-y-2 py-3">
                <div class="flex items-center space-x-3">
                    <a href="/" class="flex items-center space-x-1.5 text-gray-500 hover:text-purple-600 transition group" title="Back to TaxNest Home">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        <span class="text-xs font-medium hidden sm:inline">Home</span>
                    </a>
                    <div class="w-px h-6 bg-gray-200"></div>
                    <a href="/pos" class="flex items-center space-x-2">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center shadow-sm">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <span class="text-lg font-extrabold text-gray-900 tracking-tight block leading-tight">PRA POS</span>
                            <span class="text-[10px] text-gray-500 font-medium leading-none">by TaxNest</span>
                        </div>
                    </a>
                </div>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <a href="#features" class="text-sm font-medium text-gray-500 hover:text-purple-600 transition">Features</a>
                    <a href="#pricing" class="text-sm font-medium text-gray-500 hover:text-purple-600 transition">Pricing</a>
                    <a href="#how-it-works" class="text-sm font-medium text-gray-500 hover:text-purple-600 transition">How It Works</a>
                    <a href="/digital-invoice" class="group flex items-center space-x-1.5 text-sm font-semibold text-emerald-600 hover:text-emerald-700 transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                        <span>Digital Invoice</span>
                    </a>
                    <button @click="showLoginModal = true" class="text-sm font-semibold text-gray-700 hover:text-gray-900 transition">Log in</button>
                    <a href="/pos/register" class="inline-flex items-center px-5 py-2 bg-gradient-to-r from-purple-500 to-purple-700 text-white rounded-xl text-sm font-semibold hover:shadow-lg transition shadow-md">POS Sign Up</a>
                </div>
            </div>
        </div>
    </nav>

    <section class="pos-gradient pt-32 pb-24 lg:pb-28 relative overflow-hidden">
        <div class="orb w-72 h-72 bg-purple-400/30 top-16 left-10" style="animation: float 8s ease-in-out infinite;"></div>
        <div class="orb w-96 h-96 bg-violet-300/20 bottom-10 right-10" style="animation: float-reverse 10s ease-in-out infinite;"></div>
        <div class="orb w-48 h-48 bg-fuchsia-400/20 top-1/2 left-1/3" style="animation: float 12s ease-in-out infinite 2s;"></div>
        <div class="orb w-64 h-64 bg-indigo-400/15 top-10 right-1/4" style="animation: pulse-glow 6s ease-in-out infinite;"></div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="text-center max-w-3xl mx-auto">
                <div class="inline-flex items-center px-4 py-1.5 rounded-full bg-white/10 backdrop-blur-sm border border-white/20 mb-6">
                    <span class="text-sm font-semibold text-white">PRA Punjab Integrated &bull; PRAL IMS API v1.2</span>
                </div>
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-white leading-tight" style="text-shadow: 0 2px 20px rgba(0,0,0,0.3);">
                    NestPOS<br>
                    <span class="text-purple-200">Enterprise Point of Sale</span>
                </h1>
                <p class="mt-6 text-lg text-purple-100/90 max-w-2xl mx-auto">
                    Complete POS billing system with PRA (Punjab Revenue Authority) fiscal device integration.
                    Thermal receipt printing, real-time tax calculations, and full compliance reporting.
                </p>
                <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="/pos/register" class="w-full sm:w-auto px-8 py-3.5 bg-white text-purple-700 rounded-xl text-sm font-bold hover:bg-purple-50 transition shadow-2xl hover:shadow-purple-500/25 text-center">
                        Start Free Trial
                    </a>
                    <a href="#how-it-works" class="w-full sm:w-auto px-8 py-3.5 border-2 border-white/30 text-white rounded-xl text-sm font-bold hover:bg-white/15 backdrop-blur-sm transition text-center">
                        See How It Works
                    </a>
                </div>
            </div>
        </div>
    </section>

    <section id="features" class="py-24 lg:py-28 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl font-bold text-gray-900">POS Features</h2>
                <p class="mt-4 text-gray-700 max-w-2xl mx-auto">Everything you need to run your retail or service business with full PRA compliance.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <div class="card-accent-top bg-white rounded-xl shadow-md p-6 ring-1 ring-gray-200/50 transition duration-300 hover:-translate-y-1 hover:shadow-xl group">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-purple-400/20 to-violet-400/20 group-hover:from-purple-400/30 group-hover:to-violet-400/30 flex items-center justify-center mb-4 transition">
                        <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Smart Billing</h3>
                    <p class="text-sm text-gray-700">Add products and services, apply discounts (percentage or amount), with dynamic tax calculation based on payment method.</p>
                </div>
                <div class="card-accent-top bg-white rounded-xl shadow-md p-6 ring-1 ring-gray-200/50 transition duration-300 hover:-translate-y-1 hover:shadow-xl group">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-purple-400/20 to-violet-400/20 group-hover:from-purple-400/30 group-hover:to-violet-400/30 flex items-center justify-center mb-4 transition">
                        <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Payment Methods</h3>
                    <p class="text-sm text-gray-700">Cash (16% GST), Card/QR (5% GST). Tax automatically adjusts when payment method changes. Cash, Debit, Credit, QR/Raast supported.</p>
                </div>
                <div class="card-accent-top bg-white rounded-xl shadow-md p-6 ring-1 ring-gray-200/50 transition duration-300 hover:-translate-y-1 hover:shadow-xl group">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-purple-400/20 to-violet-400/20 group-hover:from-purple-400/30 group-hover:to-violet-400/30 flex items-center justify-center mb-4 transition">
                        <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">PRA Integration</h3>
                    <p class="text-sm text-gray-700">Real-time fiscal device reporting to Punjab Revenue Authority via PRAL IMS API v1.2. Sandbox and production environments.</p>
                </div>
                <div class="card-accent-top bg-white rounded-xl shadow-md p-6 ring-1 ring-gray-200/50 transition duration-300 hover:-translate-y-1 hover:shadow-xl group">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-purple-400/20 to-violet-400/20 group-hover:from-purple-400/30 group-hover:to-violet-400/30 flex items-center justify-center mb-4 transition">
                        <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Thermal Receipts</h3>
                    <p class="text-sm text-gray-700">Print-optimized receipts for 80mm and 58mm thermal printers. PRA QR code and fiscal invoice number on every receipt.</p>
                </div>
                <div class="card-accent-top bg-white rounded-xl shadow-md p-6 ring-1 ring-gray-200/50 transition duration-300 hover:-translate-y-1 hover:shadow-xl group">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-purple-400/20 to-violet-400/20 group-hover:from-purple-400/30 group-hover:to-violet-400/30 flex items-center justify-center mb-4 transition">
                        <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Multi-Terminal</h3>
                    <p class="text-sm text-gray-700">Register and manage multiple POS terminals. Track transactions per terminal with location and status management.</p>
                </div>
                <div class="card-accent-top bg-white rounded-xl shadow-md p-6 ring-1 ring-gray-200/50 transition duration-300 hover:-translate-y-1 hover:shadow-xl group">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-purple-400/20 to-violet-400/20 group-hover:from-purple-400/30 group-hover:to-violet-400/30 flex items-center justify-center mb-4 transition">
                        <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">POS Reports</h3>
                    <p class="text-sm text-gray-700">Daily sales trends, payment method breakdown, top-selling products, and PRA submission analytics with CSV/PDF export.</p>
                </div>
            </div>
        </div>
    </section>

    <section id="pricing" class="py-24 lg:py-28 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-gray-900">NestPOS Plans</h2>
                <p class="mt-4 text-gray-700 max-w-2xl mx-auto">Simple annual billing with built-in 6% savings</p>
                <div class="inline-flex items-center mt-4 px-4 py-2 bg-purple-50 border border-purple-200 rounded-lg">
                    <svg class="w-4 h-4 text-purple-600 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span class="text-sm font-semibold text-purple-700">Annual billing only — 6% savings included</span>
                </div>
            </div>

            @if(isset($plans) && $plans->count())
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-4xl mx-auto">
                @foreach($plans as $plan)
                @php
                    $isPopular = $plan->name === 'Business';
                    $perMonth = round($plan->price / 12);
                    $features = is_array($plan->features) ? $plan->features : (is_string($plan->features) ? json_decode($plan->features, true) : []);
                @endphp
                <div class="relative rounded-xl overflow-hidden transition duration-300 hover:-translate-y-1 shadow-md hover:shadow-xl {{ $isPopular ? 'ring-2 ring-purple-500' : '' }}">
                    @if($isPopular)
                    <div class="absolute -inset-1 bg-gradient-to-r from-purple-500 via-violet-500 to-fuchsia-500 rounded-xl blur-lg opacity-30" style="animation: pulse-glow 4s ease-in-out infinite;"></div>
                    <div class="relative">
                    <div class="bg-gradient-to-r from-purple-500 to-purple-700 text-center py-1.5">
                        <span class="text-white text-xs font-bold tracking-wide">MOST POPULAR</span>
                    </div>
                    @endif
                    <div class="bg-white border {{ $isPopular ? 'border-purple-500 border-t-0 rounded-b-xl backdrop-blur-xl' : 'border-gray-200 rounded-xl' }} p-6 relative">
                        <h3 class="text-xl font-bold text-gray-900">{{ $plan->name }}</h3>
                        <div class="mt-4 mb-1">
                            <span class="text-3xl font-black text-gray-900">PKR {{ number_format($plan->price) }}</span>
                            <span class="text-gray-600 text-sm font-medium">/year</span>
                        </div>
                        <p class="text-sm text-gray-600">PKR {{ number_format($perMonth) }}/mo effective</p>

                        <div class="mt-5 pt-5 border-t border-gray-100 space-y-3">
                            @if(!empty($features))
                                @foreach($features as $feature)
                                <div class="flex items-center gap-2.5">
                                    <svg class="w-4 h-4 text-purple-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                    <span class="text-sm text-gray-800 font-medium">{{ $feature }}</span>
                                </div>
                                @endforeach
                            @else
                                <div class="flex items-center gap-2.5">
                                    <svg class="w-4 h-4 text-purple-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                    <span class="text-sm text-gray-800 font-medium">POS Billing</span>
                                </div>
                                <div class="flex items-center gap-2.5">
                                    <svg class="w-4 h-4 text-purple-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                    <span class="text-sm text-gray-800 font-medium">PRA fiscal receipts</span>
                                </div>
                            @endif
                        </div>

                        <div class="mt-6">
                            <a href="/pos/register" class="block w-full py-3 rounded-xl text-sm font-bold text-center transition shadow-md hover:shadow-lg {{ $isPopular ? 'bg-gradient-to-r from-purple-500 to-purple-700 text-white' : 'bg-gray-900 text-white hover:bg-gray-800' }}">
                                Get {{ $plan->name }}
                            </a>
                        </div>
                    </div>
                    @if($isPopular)
                    </div>
                    @endif
                </div>
                @endforeach
            </div>

            <div class="mt-10 text-center">
                <div class="inline-flex flex-wrap items-center justify-center gap-6 text-sm text-gray-600">
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        PRA compliant
                    </span>
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        14-day free trial
                    </span>
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        6% annual savings
                    </span>
                </div>
            </div>
            @endif
        </div>
    </section>

    <section id="how-it-works" class="py-24 lg:py-28 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-gray-900">How NestPOS Works</h2>
                <p class="mt-4 text-gray-700">Simple 4-step billing process with automatic compliance</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-6">
                <div class="text-center bg-white rounded-xl shadow-md p-6 transition duration-300 hover:-translate-y-1 hover:shadow-xl">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-r from-purple-500 to-purple-700 text-white flex items-center justify-center text-2xl font-bold mx-auto mb-4">1</div>
                    <h4 class="font-bold text-gray-900 mb-2">Add Items</h4>
                    <p class="text-sm text-gray-700">Select products or services from your catalog with quantity and pricing</p>
                </div>
                <div class="text-center bg-white rounded-xl shadow-md p-6 transition duration-300 hover:-translate-y-1 hover:shadow-xl">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-r from-purple-500 to-purple-700 text-white flex items-center justify-center text-2xl font-bold mx-auto mb-4">2</div>
                    <h4 class="font-bold text-gray-900 mb-2">Apply Discount</h4>
                    <p class="text-sm text-gray-700">Choose percentage or flat amount discount for the entire bill</p>
                </div>
                <div class="text-center bg-white rounded-xl shadow-md p-6 transition duration-300 hover:-translate-y-1 hover:shadow-xl">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-r from-purple-500 to-purple-700 text-white flex items-center justify-center text-2xl font-bold mx-auto mb-4">3</div>
                    <h4 class="font-bold text-gray-900 mb-2">Select Payment</h4>
                    <p class="text-sm text-gray-700">Cash, Card, or QR — tax rate adjusts automatically per PRA rules</p>
                </div>
                <div class="text-center bg-white rounded-xl shadow-md p-6 transition duration-300 hover:-translate-y-1 hover:shadow-xl">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-r from-purple-500 to-purple-700 text-white flex items-center justify-center text-2xl font-bold mx-auto mb-4">4</div>
                    <h4 class="font-bold text-gray-900 mb-2">Print Receipt</h4>
                    <p class="text-sm text-gray-700">Invoice submitted to PRA, receipt printed with fiscal number and QR code</p>
                </div>
            </div>
        </div>
    </section>

    <section class="py-24 lg:py-28 relative overflow-hidden" style="background: linear-gradient(135deg, #1e1b4b 0%, #5b21b6 50%, #7c3aed 100%);">
        <div class="absolute inset-0">
            <div class="orb w-80 h-80 bg-purple-400/20 top-0 left-1/4" style="animation: float 8s ease-in-out infinite;"></div>
            <div class="orb w-64 h-64 bg-violet-300/15 bottom-0 right-1/4" style="animation: float-reverse 10s ease-in-out infinite;"></div>
        </div>
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_center,rgba(139,92,246,0.3),transparent_70%)]"></div>
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative z-10">
            <h2 class="text-3xl font-bold text-white mb-4" style="text-shadow: 0 2px 15px rgba(0,0,0,0.3);">Ready to Get Started?</h2>
            <p class="text-purple-200 mb-8">Start your 14-day free trial. No credit card required.</p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="/pos/register" class="w-full sm:w-auto px-8 py-3.5 bg-white text-purple-700 rounded-xl text-sm font-bold hover:bg-purple-50 transition shadow-2xl hover:shadow-purple-500/25 text-center">Create POS Account</a>
                <button @click="showLoginModal = true" class="w-full sm:w-auto px-8 py-3.5 border-2 border-white/30 text-white rounded-xl text-sm font-bold hover:bg-white/15 backdrop-blur-sm transition text-center">Login to POS</button>
            </div>
        </div>
    </section>

    <div class="bg-gray-900 py-4" style="border-top: 2px solid transparent; border-image: linear-gradient(90deg, #7c3aed, #a855f7, #c084fc) 1;">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-2">
            <p class="text-xs text-gray-500">&copy; {{ date('Y') }} TaxNest. All rights reserved.</p>
            <span class="text-xs text-gray-500 flex items-center"><svg class="w-3.5 h-3.5 mr-1 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/></svg>PRA IMS v1.2 Integrated</span>
        </div>
    </div>

</body>
</html>