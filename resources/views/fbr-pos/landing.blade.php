<!DOCTYPE html>
<html lang="en" class="overflow-x-hidden">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <title>FBR POS — FBR Integrated Point of Sale by TaxNest</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: 'Figtree', sans-serif; }
        [x-cloak] { display: none !important; }
        .fpos-gradient {
            background: linear-gradient(135deg, #0c1a3a 0%, #1e3a5f 40%, #2563eb 70%, #3b82f6 100%);
            position: relative;
        }
        .fpos-gradient::before {
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
            background: linear-gradient(90deg, #2563eb, #3b82f6, #60a5fa);
            border-radius: 12px 12px 0 0;
        }
        .fpos-step-connector { position: relative; }
        .fpos-step-connector::after {
            content: '';
            position: absolute;
            top: 28px;
            left: calc(50% + 35px);
            width: calc(100% - 70px);
            height: 2px;
            background: linear-gradient(90deg, #2563eb, #bfdbfe);
        }
        .fpos-step-connector:last-child::after { display: none; }
        @media (max-width: 639px) {
            .fpos-step-connector::after { display: none; }
        }
    </style>
</head>
<body class="antialiased text-gray-800 overflow-x-hidden" style="scroll-behavior: smooth;" x-data="{ showLoginModal: false }">

    <div x-show="showLoginModal" x-cloak x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 backdrop-blur-sm px-4" @click.self="showLoginModal = false" @keydown.escape.window="showLoginModal = false">
        <div x-show="showLoginModal" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-95 translate-y-4" x-transition:enter-end="opacity-100 scale-100 translate-y-0" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" class="w-full max-w-md relative overflow-hidden rounded-2xl" style="background: linear-gradient(135deg, #0c1a3a 0%, #1e3a5f 50%, #1e40af 100%); box-shadow: 0 25px 60px -12px rgba(0,0,0,0.5);">
            <div class="absolute inset-0 overflow-hidden">
                <div class="absolute -top-20 -right-20 w-40 h-40 bg-blue-400/10 rounded-full blur-2xl"></div>
                <div class="absolute -bottom-10 -left-10 w-32 h-32 bg-sky-400/10 rounded-full blur-2xl"></div>
            </div>
            <div class="relative px-6 py-5">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-xl bg-white/10 backdrop-blur-sm flex items-center justify-center ring-1 ring-white/10">
                            <svg class="w-5 h-5 text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-white">FBR POS</h3>
                            <p class="text-blue-200/50 text-xs">FBR Point of Sale</p>
                        </div>
                    </div>
                    <button @click="showLoginModal = false" class="text-white/40 hover:text-white transition p-1">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
            <form method="POST" action="/login" class="relative px-6 pb-6 space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-blue-100/70 mb-1.5">Email, Phone, Username, CNIC or NTN</label>
                    <input type="text" name="login" required autofocus class="w-full px-4 py-2.5 rounded-xl text-sm text-white placeholder-blue-300/30 transition" style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); outline: none;" placeholder="Enter your credential" onfocus="this.style.borderColor='rgba(59,130,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(59,130,246,0.12)';" onblur="this.style.borderColor='rgba(255,255,255,0.12)'; this.style.boxShadow='none';">
                </div>
                <div>
                    <label class="block text-sm font-medium text-blue-100/70 mb-1.5">Password</label>
                    <input type="password" name="password" required autocomplete="current-password" class="w-full px-4 py-2.5 rounded-xl text-sm text-white placeholder-blue-300/30 transition" style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); outline: none;" placeholder="Enter your password" onfocus="this.style.borderColor='rgba(59,130,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(59,130,246,0.12)';" onblur="this.style.borderColor='rgba(255,255,255,0.12)'; this.style.boxShadow='none';">
                </div>
                <div class="flex items-center justify-between">
                    <label class="flex items-center">
                        <input type="checkbox" name="remember" class="rounded bg-white/5 border-white/10 text-blue-500 focus:ring-blue-500 focus:ring-offset-0 mr-2 w-4 h-4">
                        <span class="text-sm text-blue-200/40">Remember me</span>
                    </label>
                    <a href="{{ route('password.request') }}" class="text-sm text-blue-300/70 hover:text-blue-200 transition">Forgot Password?</a>
                </div>
                @if($errors->any())
                <div class="bg-red-500/10 border border-red-500/20 rounded-lg p-3">
                    @foreach($errors->all() as $error)
                    <p class="text-sm text-red-400">{{ $error }}</p>
                    @endforeach
                </div>
                @endif
                <button type="submit" class="w-full py-3 rounded-xl text-sm font-bold text-white transition-all duration-200" style="background: linear-gradient(135deg, #2563eb, #3b82f6); box-shadow: 0 4px 20px rgba(37, 99, 235, 0.4);" onmouseover="this.style.boxShadow='0 6px 28px rgba(37, 99, 235, 0.55)'; this.style.transform='translateY(-1px)';" onmouseout="this.style.boxShadow='0 4px 20px rgba(37, 99, 235, 0.4)'; this.style.transform='translateY(0)';">
                    Sign In
                </button>
                <p class="text-center text-sm text-blue-200/40">
                    Don't have an account? <a href="/register" class="font-semibold text-blue-300 hover:text-white transition">Sign Up Free</a>
                </p>
            </form>
        </div>
    </div>

    <nav class="fixed top-0 w-full z-50 bg-white/95 backdrop-blur-2xl border-b border-gray-200/60 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-y-2 py-3">
                <div class="flex items-center space-x-3">
                    <a href="/" class="flex items-center space-x-1.5 text-gray-500 hover:text-blue-600 transition group" title="Back to TaxNest Home">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        <span class="text-xs font-medium hidden sm:inline">Home</span>
                    </a>
                    <div class="w-px h-6 bg-gray-200"></div>
                    <a href="/fbr-pos-landing" class="flex items-center space-x-2">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-500 to-blue-700 flex items-center justify-center shadow-sm">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <span class="text-lg font-extrabold text-gray-900 tracking-tight block leading-tight">FBR POS</span>
                            <span class="text-[10px] text-gray-500 font-medium leading-none">by TaxNest</span>
                        </div>
                    </a>
                </div>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <a href="#features" class="text-sm font-medium text-gray-500 hover:text-blue-600 transition">Features</a>
                    <a href="#pricing" class="text-sm font-medium text-gray-500 hover:text-blue-600 transition">Pricing</a>
                    <a href="#how-it-works" class="text-sm font-medium text-gray-500 hover:text-blue-600 transition">How It Works</a>
                    <a href="/digital-invoice" class="group flex items-center space-x-1.5 text-sm font-semibold text-emerald-600 hover:text-emerald-700 transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                        <span>Digital Invoice</span>
                    </a>
                    <a href="/pos" class="group flex items-center space-x-1.5 text-sm font-semibold text-purple-600 hover:text-purple-700 transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        <span>PRA POS</span>
                    </a>
                    <button @click="showLoginModal = true" class="text-sm font-semibold text-gray-700 hover:text-gray-900 transition">Log in</button>
                    <a href="/register" class="inline-flex items-center px-5 py-2 bg-gradient-to-r from-blue-500 to-blue-700 text-white rounded-xl text-sm font-semibold hover:shadow-lg transition shadow-md">Sign Up Free</a>
                </div>
            </div>
        </div>
    </nav>

    <section class="fpos-gradient pt-32 pb-24 lg:pb-28 relative overflow-hidden">
        <div class="orb w-72 h-72 bg-blue-400/30 top-16 left-10" style="animation: float 8s ease-in-out infinite;"></div>
        <div class="orb w-96 h-96 bg-sky-300/20 bottom-10 right-10" style="animation: float-reverse 10s ease-in-out infinite;"></div>
        <div class="orb w-48 h-48 bg-indigo-400/20 top-1/2 left-1/3" style="animation: float 12s ease-in-out infinite 2s;"></div>
        <div class="orb w-64 h-64 bg-blue-300/15 top-10 right-1/4" style="animation: pulse-glow 6s ease-in-out infinite;"></div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="text-center max-w-3xl mx-auto">
                <div class="inline-flex items-center px-4 py-1.5 rounded-full bg-white/10 backdrop-blur-sm border border-white/20 mb-6">
                    <span class="text-sm font-semibold text-white">FBR API Integrated &bull; Real-time Submission</span>
                </div>
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-white leading-tight" style="text-shadow: 0 2px 20px rgba(0,0,0,0.3);">
                    FBR POS<br>
                    <span class="text-blue-200">Point of Sale System</span>
                </h1>
                <p class="mt-6 text-lg text-blue-100/90 max-w-2xl mx-auto">
                    Complete POS billing system with direct FBR (Federal Board of Revenue) integration.
                    Real-time invoice submission, dual invoice numbering, and full tax compliance reporting.
                </p>
                <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="/register" class="w-full sm:w-auto px-8 py-3.5 bg-white text-blue-700 rounded-xl text-sm font-bold hover:bg-blue-50 transition shadow-2xl hover:shadow-blue-500/25 text-center">
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
                <h2 class="text-3xl font-bold text-gray-900">FBR POS Features</h2>
                <p class="mt-4 text-gray-700 max-w-2xl mx-auto">Everything you need to run your business with full FBR compliance at the point of sale.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <div class="card-accent-top bg-white rounded-xl shadow-md p-6 ring-1 ring-gray-200/50 transition duration-300 hover:-translate-y-1 hover:shadow-xl group">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-400/20 to-sky-400/20 group-hover:from-blue-400/30 group-hover:to-sky-400/30 flex items-center justify-center mb-4 transition">
                        <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Direct FBR Submission</h3>
                    <p class="text-sm text-gray-700">Real-time synchronous invoice submission to FBR. Instant confirmation with FBR invoice number and verification code.</p>
                </div>
                <div class="card-accent-top bg-white rounded-xl shadow-md p-6 ring-1 ring-gray-200/50 transition duration-300 hover:-translate-y-1 hover:shadow-xl group">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-400/20 to-sky-400/20 group-hover:from-blue-400/30 group-hover:to-sky-400/30 flex items-center justify-center mb-4 transition">
                        <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Smart POS Billing</h3>
                    <p class="text-sm text-gray-700">Add products and services, apply discounts, dynamic tax calculation. Fast checkout with automatic GST computation.</p>
                </div>
                <div class="card-accent-top bg-white rounded-xl shadow-md p-6 ring-1 ring-gray-200/50 transition duration-300 hover:-translate-y-1 hover:shadow-xl group">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-400/20 to-sky-400/20 group-hover:from-blue-400/30 group-hover:to-sky-400/30 flex items-center justify-center mb-4 transition">
                        <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Dual Invoice Numbering</h3>
                    <p class="text-sm text-gray-700">Automatic FPOS/FLOCAL prefix system. FBR ON generates FPOS-YYYY-XXXXX, FBR OFF generates FLOCAL-YYYY-XXXXX.</p>
                </div>
                <div class="card-accent-top bg-white rounded-xl shadow-md p-6 ring-1 ring-gray-200/50 transition duration-300 hover:-translate-y-1 hover:shadow-xl group">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-400/20 to-sky-400/20 group-hover:from-blue-400/30 group-hover:to-sky-400/30 flex items-center justify-center mb-4 transition">
                        <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">FBR Reporting Toggle</h3>
                    <p class="text-sm text-gray-700">Admin-controlled ON/OFF toggle. When OFF, invoices save locally without FBR submission. Switch back anytime.</p>
                </div>
                <div class="card-accent-top bg-white rounded-xl shadow-md p-6 ring-1 ring-gray-200/50 transition duration-300 hover:-translate-y-1 hover:shadow-xl group">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-400/20 to-sky-400/20 group-hover:from-blue-400/30 group-hover:to-sky-400/30 flex items-center justify-center mb-4 transition">
                        <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">PIN-Protected Local Data</h3>
                    <p class="text-sm text-gray-700">Confidential PIN system protects local invoice data. Server-side enforced with lockout protection. Only admin can access.</p>
                </div>
                <div class="card-accent-top bg-white rounded-xl shadow-md p-6 ring-1 ring-gray-200/50 transition duration-300 hover:-translate-y-1 hover:shadow-xl group">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-400/20 to-sky-400/20 group-hover:from-blue-400/30 group-hover:to-sky-400/30 flex items-center justify-center mb-4 transition">
                        <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">FBR POS Reports</h3>
                    <p class="text-sm text-gray-700">Daily sales trends, FBR submission analytics, tax collected summary, and separated FBR/Local transaction views.</p>
                </div>
                <div class="card-accent-top bg-white rounded-xl shadow-md p-6 ring-1 ring-gray-200/50 transition duration-300 hover:-translate-y-1 hover:shadow-xl group">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-400/20 to-sky-400/20 group-hover:from-blue-400/30 group-hover:to-sky-400/30 flex items-center justify-center mb-4 transition">
                        <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">FBR Retry System</h3>
                    <p class="text-sm text-gray-700">Failed FBR submissions can be retried with one click. Automatic status tracking and error details for every invoice.</p>
                </div>
                <div class="card-accent-top bg-white rounded-xl shadow-md p-6 ring-1 ring-gray-200/50 transition duration-300 hover:-translate-y-1 hover:shadow-xl group">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-400/20 to-sky-400/20 group-hover:from-blue-400/30 group-hover:to-sky-400/30 flex items-center justify-center mb-4 transition">
                        <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Multiple Payment Methods</h3>
                    <p class="text-sm text-gray-700">Cash, Debit Card, Credit Card, and QR/Raast. Tax rates adjust automatically based on payment method per FBR rules.</p>
                </div>
                <div class="card-accent-top bg-white rounded-xl shadow-md p-6 ring-1 ring-gray-200/50 transition duration-300 hover:-translate-y-1 hover:shadow-xl group">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-400/20 to-sky-400/20 group-hover:from-blue-400/30 group-hover:to-sky-400/30 flex items-center justify-center mb-4 transition">
                        <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Sandbox & Production</h3>
                    <p class="text-sm text-gray-700">Test everything in FBR sandbox mode before going live. Seamless switch between sandbox and production environments.</p>
                </div>
            </div>
        </div>
    </section>

    <section id="pricing" class="py-24 lg:py-28 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-gray-900">FBR POS Plans</h2>
                <p class="mt-4 text-gray-700 max-w-2xl mx-auto">FBR POS is included with your Digital Invoice subscription</p>
            </div>

            @if(isset($plans) && $plans->count())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 max-w-5xl mx-auto">
                @foreach($plans as $plan)
                @php $isPopular = $plan->name === 'Business'; @endphp
                <div class="relative rounded-xl shadow-md overflow-hidden transition duration-300 hover:-translate-y-1 hover:shadow-xl {{ $isPopular ? 'ring-2 ring-blue-400/80 shadow-lg shadow-blue-500/10' : '' }}">
                    @if($isPopular)
                    <div class="bg-gradient-to-r from-blue-500 to-blue-700 text-center py-1.5">
                        <span class="text-white text-xs font-bold tracking-wide">BEST VALUE</span>
                    </div>
                    @endif
                    <div class="{{ $isPopular ? 'bg-gradient-to-b from-blue-50/50 to-white border-blue-400/30 border-t-0 rounded-b-xl' : 'bg-white border-gray-200 rounded-xl' }} border p-5">
                        <h3 class="text-lg font-bold text-gray-900">{{ $plan->name }}</h3>
                        <div class="mt-3 mb-1">
                            <span class="text-3xl font-black text-gray-900">PKR {{ number_format($plan->price, 0) }}</span>
                            <span class="text-gray-400 text-sm">/mo</span>
                        </div>
                        <p class="text-xs text-blue-600 font-medium">Includes FBR POS module</p>

                        @php
                            $planFeatures = is_array($plan->features) ? $plan->features : (is_string($plan->features) ? json_decode($plan->features, true) : []);
                        @endphp
                        <div class="mt-4 pt-4 border-t border-gray-100 space-y-2 text-sm text-gray-600">
                            @if(!empty($planFeatures))
                                @foreach($planFeatures as $feature)
                                <div class="flex items-center gap-2">
                                    <svg class="w-4 h-4 text-blue-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    {{ $feature }}
                                </div>
                                @endforeach
                            @else
                                <div class="flex items-center gap-2">
                                    <svg class="w-4 h-4 text-blue-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    FBR POS billing
                                </div>
                                <div class="flex items-center gap-2">
                                    <svg class="w-4 h-4 text-blue-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    FBR real-time submission
                                </div>
                            @endif
                        </div>

                        <div class="mt-5">
                            <a href="/register" class="block w-full py-2.5 rounded-lg text-sm font-semibold text-center transition shadow-md hover:shadow-lg {{ $isPopular ? 'bg-gradient-to-r from-blue-500 to-blue-700 text-white' : 'bg-gray-900 text-white hover:bg-gray-800' }}">Start Free Trial</a>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            <div class="mt-8 text-center">
                <div class="inline-flex flex-wrap items-center justify-center gap-6 text-xs text-gray-400">
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        FBR compliant
                    </span>
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        14-day free trial
                    </span>
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                        No credit card required
                    </span>
                </div>
            </div>
            @else
            <div class="max-w-lg mx-auto text-center">
                <div class="bg-white rounded-xl shadow-md p-8 border border-gray-200">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-400/20 to-blue-600/20 flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">FBR POS Included Free</h3>
                    <p class="text-gray-600 mb-6">FBR POS module is included with every Digital Invoice subscription. Sign up for Digital Invoice to get started.</p>
                    <a href="/register" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-blue-500 to-blue-700 text-white rounded-xl text-sm font-bold hover:shadow-lg transition shadow-md">Start Free Trial</a>
                </div>
            </div>
            @endif
        </div>
    </section>

    <section id="how-it-works" class="py-24 lg:py-28 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-gray-900">How FBR POS Works</h2>
                <p class="mt-4 text-gray-700">Simple 4-step billing process with automatic FBR compliance</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-6">
                <div class="text-center fpos-step-connector bg-white rounded-xl shadow-md p-6 transition duration-300 hover:-translate-y-1 hover:shadow-xl">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-r from-blue-500 to-blue-700 text-white flex items-center justify-center text-2xl font-bold mx-auto mb-4">1</div>
                    <h4 class="font-bold text-gray-900 mb-2">Add Items</h4>
                    <p class="text-sm text-gray-700">Select products or services from your catalog with quantity, pricing, and tax configuration</p>
                </div>
                <div class="text-center fpos-step-connector bg-white rounded-xl shadow-md p-6 transition duration-300 hover:-translate-y-1 hover:shadow-xl">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-r from-blue-500 to-blue-700 text-white flex items-center justify-center text-2xl font-bold mx-auto mb-4">2</div>
                    <h4 class="font-bold text-gray-900 mb-2">Apply Discount</h4>
                    <p class="text-sm text-gray-700">Choose percentage or flat amount discount for the entire bill with automatic recalculation</p>
                </div>
                <div class="text-center fpos-step-connector bg-white rounded-xl shadow-md p-6 transition duration-300 hover:-translate-y-1 hover:shadow-xl">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-r from-blue-500 to-blue-700 text-white flex items-center justify-center text-2xl font-bold mx-auto mb-4">3</div>
                    <h4 class="font-bold text-gray-900 mb-2">Select Payment</h4>
                    <p class="text-sm text-gray-700">Cash, Card, or QR payment. Tax rate adjusts automatically per FBR regulations</p>
                </div>
                <div class="text-center fpos-step-connector bg-white rounded-xl shadow-md p-6 transition duration-300 hover:-translate-y-1 hover:shadow-xl">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-r from-blue-500 to-blue-700 text-white flex items-center justify-center text-2xl font-bold mx-auto mb-4">4</div>
                    <h4 class="font-bold text-gray-900 mb-2">Submit to FBR</h4>
                    <p class="text-sm text-gray-700">Invoice submitted to FBR in real-time with instant confirmation and FBR invoice number</p>
                </div>
            </div>
        </div>
    </section>

    <section class="py-24 lg:py-28 relative overflow-hidden" style="background: linear-gradient(135deg, #0c1a3a 0%, #1e3a5f 50%, #2563eb 100%);">
        <div class="absolute inset-0">
            <div class="orb w-80 h-80 bg-blue-400/20 top-0 left-1/4" style="animation: float 8s ease-in-out infinite;"></div>
            <div class="orb w-64 h-64 bg-sky-300/15 bottom-0 right-1/4" style="animation: float-reverse 10s ease-in-out infinite;"></div>
        </div>
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_center,rgba(59,130,246,0.3),transparent_70%)]"></div>
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative z-10">
            <h2 class="text-3xl font-bold text-white mb-4" style="text-shadow: 0 2px 15px rgba(0,0,0,0.3);">Ready to Get Started?</h2>
            <p class="text-blue-200 mb-8">Start your 14-day free trial. No credit card required.</p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="/register" class="w-full sm:w-auto px-8 py-3.5 bg-white text-blue-700 rounded-xl text-sm font-bold hover:bg-blue-50 transition shadow-2xl hover:shadow-blue-500/25 text-center">Create Free Account</a>
                <button @click="showLoginModal = true" class="w-full sm:w-auto px-8 py-3.5 border-2 border-white/30 text-white rounded-xl text-sm font-bold hover:bg-white/15 backdrop-blur-sm transition text-center">Login to FBR POS</button>
            </div>
        </div>
    </section>

    <div class="bg-gray-900 py-4" style="border-top: 2px solid transparent; border-image: linear-gradient(90deg, #2563eb, #3b82f6, #60a5fa) 1;">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-2">
            <p class="text-xs text-gray-500">&copy; {{ date('Y') }} TaxNest. All rights reserved.</p>
            <span class="text-xs text-gray-500 flex items-center"><svg class="w-3.5 h-3.5 mr-1 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/></svg>FBR API Integrated</span>
        </div>
    </div>

</body>
</html>
