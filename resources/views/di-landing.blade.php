<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <title>Digital Invoice — FBR Compliant Invoicing by TaxNest</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: 'Figtree', sans-serif; }
        .di-gradient { background: linear-gradient(135deg, #064e3b 0%, #059669 40%, #34d399 70%, #a7f3d0 100%); }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="antialiased text-gray-800" style="scroll-behavior: smooth;" x-data="{ showLoginModal: {{ isset($showLogin) && $showLogin ? 'true' : 'false' }} }">

    <div x-show="showLoginModal" x-cloak x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 backdrop-blur-sm px-4" @click.self="showLoginModal = false" @keydown.escape.window="showLoginModal = false">
        <div x-show="showLoginModal" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-95 translate-y-4" x-transition:enter-end="opacity-100 scale-100 translate-y-0" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" class="bg-white rounded-2xl shadow-2xl w-full max-w-md relative overflow-hidden">
            <div class="bg-gradient-to-r from-emerald-600 to-teal-600 px-6 py-5">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-white">Digital Invoice Login</h3>
                            <p class="text-emerald-100 text-xs">FBR Compliance Dashboard</p>
                        </div>
                    </div>
                    <button @click="showLoginModal = false" class="text-white/70 hover:text-white transition p-1">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
            <form method="POST" action="/login" class="p-6 space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email, Phone, Username, CNIC or NTN</label>
                    <input type="text" name="login" required autofocus class="w-full px-4 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition" placeholder="Enter your credential">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input type="password" name="password" required class="w-full px-4 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition" placeholder="Enter your password">
                </div>
                <div class="flex items-center justify-between">
                    <label class="flex items-center text-sm text-gray-600">
                        <input type="checkbox" name="remember" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 mr-2">
                        Remember me
                    </label>
                </div>
                @if($errors->any())
                <div class="bg-red-50 border border-red-200 rounded-lg p-3">
                    @foreach($errors->all() as $error)
                    <p class="text-sm text-red-600">{{ $error }}</p>
                    @endforeach
                </div>
                @endif
                <button type="submit" class="w-full py-2.5 bg-emerald-600 text-white rounded-xl text-sm font-bold hover:bg-emerald-700 transition shadow-sm">
                    Sign In
                </button>
                <p class="text-center text-sm text-gray-500">
                    Don't have an account? <a href="/register" class="text-emerald-600 font-semibold hover:text-emerald-700">Sign Up Free</a>
                </p>
            </form>
        </div>
    </div>

    <nav class="fixed top-0 w-full z-50 bg-white/95 backdrop-blur-2xl border-b border-gray-200/60 shadow-sm" x-data="{ mobileOpen: false }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center space-x-3">
                    <a href="/" class="flex items-center space-x-1.5 text-gray-400 hover:text-emerald-600 transition group" title="Back to TaxNest Home">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        <span class="text-xs font-medium hidden sm:inline">Home</span>
                    </a>
                    <div class="w-px h-6 bg-gray-200"></div>
                    <a href="/digital-invoice" class="flex items-center space-x-2">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-sm">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                        </div>
                        <div>
                            <span class="text-lg font-extrabold text-gray-900 tracking-tight block leading-tight">Digital Invoice</span>
                            <span class="text-[10px] text-gray-400 font-medium leading-none">by TaxNest</span>
                        </div>
                    </a>
                </div>

                <div class="hidden lg:flex items-center">
                    <div class="flex items-center space-x-5 mr-6">
                        <a href="#features" class="text-sm font-medium text-gray-500 hover:text-emerald-600 transition">Features</a>
                        <a href="#pricing" class="text-sm font-medium text-gray-500 hover:text-emerald-600 transition">Pricing</a>
                        <a href="#how-it-works" class="text-sm font-medium text-gray-500 hover:text-emerald-600 transition">How It Works</a>
                    </div>
                    <div class="border-l border-gray-200 pl-6 mr-6">
                        <a href="/pos" class="group flex items-center space-x-2 px-3 py-2 rounded-lg hover:bg-purple-50 transition">
                            <div class="w-7 h-7 rounded-lg bg-purple-100 group-hover:bg-purple-200 flex items-center justify-center transition">
                                <svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            </div>
                            <div>
                                <span class="text-sm font-semibold text-gray-900 block leading-tight">PRA POS</span>
                                <span class="text-[10px] text-purple-600 font-medium">PRA Compliant</span>
                            </div>
                        </a>
                    </div>
                    <div class="flex items-center space-x-2">
                        <button @click="showLoginModal = true" class="inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-700 hover:text-gray-900 transition">Log in</button>
                        <a href="/register" class="inline-flex items-center px-5 py-2.5 bg-emerald-600 text-white rounded-xl text-sm font-semibold hover:bg-emerald-700 transition shadow-sm shadow-emerald-600/20">Sign Up Free</a>
                    </div>
                </div>

                <button @click="mobileOpen = !mobileOpen" class="lg:hidden p-2 rounded-lg hover:bg-gray-100 transition">
                    <svg x-show="!mobileOpen" class="w-6 h-6 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    <svg x-show="mobileOpen" x-cloak class="w-6 h-6 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div x-show="mobileOpen" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="lg:hidden border-t border-gray-100 py-4 space-y-2">
                <a href="/" class="flex items-center space-x-2 px-3 py-2 text-sm font-medium text-gray-500 hover:text-emerald-600 rounded-lg hover:bg-gray-50 transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    <span>TaxNest Home</span>
                </a>
                <div class="border-t border-gray-100 pt-2 mt-1"></div>
                <a href="#features" @click="mobileOpen = false" class="block px-3 py-2 text-sm font-medium text-gray-600 hover:text-emerald-600 rounded-lg hover:bg-gray-50 transition">Features</a>
                <a href="#pricing" @click="mobileOpen = false" class="block px-3 py-2 text-sm font-medium text-gray-600 hover:text-emerald-600 rounded-lg hover:bg-gray-50 transition">Pricing</a>
                <a href="#how-it-works" @click="mobileOpen = false" class="block px-3 py-2 text-sm font-medium text-gray-600 hover:text-emerald-600 rounded-lg hover:bg-gray-50 transition">How It Works</a>
                <div class="border-t border-gray-100 pt-3 mt-2">
                    <a href="/pos" class="flex items-center space-x-3 px-3 py-3 rounded-xl hover:bg-purple-50 transition">
                        <div class="w-9 h-9 rounded-lg bg-purple-100 flex items-center justify-center">
                            <svg class="w-5 h-5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <span class="text-sm font-semibold text-gray-900 block">PRA POS</span>
                            <span class="text-xs text-purple-600">PRA Point of Sale</span>
                        </div>
                    </a>
                </div>
                <div class="border-t border-gray-100 pt-3 mt-2 flex space-x-2 px-3">
                    <button @click="showLoginModal = true; mobileOpen = false" class="flex-1 text-center py-2.5 text-sm font-semibold text-gray-700 border border-gray-300 rounded-xl hover:bg-gray-50 transition">Log in</button>
                    <a href="/register" class="flex-1 text-center py-2.5 text-sm font-semibold text-white bg-emerald-600 rounded-xl hover:bg-emerald-700 transition">Sign Up Free</a>
                </div>
            </div>
        </div>
    </nav>

    <section class="di-gradient pt-32 pb-20 relative overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-20 left-10 w-72 h-72 bg-white rounded-full blur-3xl"></div>
            <div class="absolute bottom-10 right-20 w-96 h-96 bg-emerald-200 rounded-full blur-3xl"></div>
        </div>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="text-center max-w-3xl mx-auto">
                <div class="inline-flex items-center px-4 py-1.5 rounded-full bg-white/20 backdrop-blur-sm border border-white/30 mb-6">
                    <span class="text-sm font-semibold text-white">FBR PRAL API v1.12 Integrated</span>
                </div>
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-white leading-tight">
                    Digital Invoice<br>
                    <span class="text-emerald-100">FBR Tax Compliance</span>
                </h1>
                <p class="mt-6 text-lg text-emerald-100 max-w-2xl mx-auto">
                    Enterprise-grade FBR digital invoicing. Real-time synchronous submission via PRAL API v1.12, HS Intelligence, compliance scoring, risk detection, and immutable audit logs.
                </p>
                <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="/register" class="px-8 py-3.5 bg-white text-emerald-700 rounded-xl text-sm font-bold hover:bg-emerald-50 transition shadow-lg">Start 14-Day Free Trial</a>
                    <button @click="showLoginModal = true" class="px-8 py-3.5 border-2 border-white/40 text-white rounded-xl text-sm font-bold hover:bg-white/10 transition">Login to Dashboard</button>
                </div>
            </div>
        </div>
    </section>

    <section id="features" class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl font-bold text-gray-900">Everything You Need for FBR Compliance</h2>
                <p class="mt-4 text-gray-600 max-w-2xl mx-auto">Purpose-built for Pakistan's Federal Board of Revenue regulations with enterprise-grade infrastructure.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100">
                    <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Real-time FBR Submission</h3>
                    <p class="text-sm text-gray-600">Direct synchronous submission to FBR via PRAL API v1.12. Instant confirmation, automatic invoice locking, and QR code generation.</p>
                </div>
                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100">
                    <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">HS Intelligence Engine</h3>
                    <p class="text-sm text-gray-600">AI-powered HS code suggestions with confidence scoring. Auto-suggests tax rates, SRO numbers, and learns from every submission.</p>
                </div>
                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100">
                    <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Risk Detection Engine</h3>
                    <p class="text-sm text-gray-600">Pre-submission risk analysis with anomaly scoring. Blocks problematic invoices before they reach FBR.</p>
                </div>
                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100">
                    <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Compliance Scoring</h3>
                    <p class="text-sm text-gray-600">Formula-based scoring system rates every invoice before submission. Ensures maximum compliance with FBR regulations.</p>
                </div>
                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100">
                    <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">PDF + QR Generation</h3>
                    <p class="text-sm text-gray-600">FBR-compliant PDF invoices with watermarks, QR codes, and dual invoice numbering (internal + FBR).</p>
                </div>
                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100">
                    <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Immutable Audit Logs</h3>
                    <p class="text-sm text-gray-600">SHA-256 signed audit trail with integrity verification. Tamper-proof activity tracking for compliance.</p>
                </div>
                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100">
                    <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Multi-Branch Support</h3>
                    <p class="text-sm text-gray-600">Manage multiple business branches with centralized invoicing, customer ledger, and role-based access control.</p>
                </div>
                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100">
                    <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Enterprise Analytics</h3>
                    <p class="text-sm text-gray-600">KPIs, compliance metrics, customer ledger analytics, and detailed MIS dashboards for business intelligence.</p>
                </div>
                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100">
                    <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">6 Login Methods</h3>
                    <p class="text-sm text-gray-600">Email, Phone, Username, CNIC, NTN, or FBR Registration number. Maximum flexibility for all business types.</p>
                </div>
            </div>
        </div>
    </section>

    <section id="pricing" class="py-20 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-gray-900">Digital Invoice Plans</h2>
                <p class="mt-4 text-gray-600 max-w-2xl mx-auto">Flexible billing cycles with savings on longer commitments</p>
            </div>

            <div class="flex justify-center mb-8" x-data="{ cycle: 'monthly' }">
                <div class="inline-flex bg-white rounded-xl p-1 border border-gray-200 shadow-sm">
                    <button @click="cycle = 'monthly'" :class="cycle === 'monthly' ? 'bg-emerald-600 text-white shadow-sm' : 'text-gray-600 hover:text-gray-900'" class="px-4 py-2 rounded-lg text-sm font-semibold transition">Monthly</button>
                    <button @click="cycle = 'quarterly'" :class="cycle === 'quarterly' ? 'bg-emerald-600 text-white shadow-sm' : 'text-gray-600 hover:text-gray-900'" class="px-4 py-2 rounded-lg text-sm font-semibold transition">Quarterly <span class="text-[10px] opacity-75">-1%</span></button>
                    <button @click="cycle = 'semi_annual'" :class="cycle === 'semi_annual' ? 'bg-emerald-600 text-white shadow-sm' : 'text-gray-600 hover:text-gray-900'" class="px-4 py-2 rounded-lg text-sm font-semibold transition">Semi-Annual <span class="text-[10px] opacity-75">-3%</span></button>
                    <button @click="cycle = 'annual'" :class="cycle === 'annual' ? 'bg-emerald-600 text-white shadow-sm' : 'text-gray-600 hover:text-gray-900'" class="px-4 py-2 rounded-lg text-sm font-semibold transition">Annual <span class="text-[10px] opacity-75">-6%</span></button>
                </div>

                @if(isset($plans) && $plans->count())
                <div class="hidden">
                    @foreach($plans as $plan)
                    <span id="plan-price-{{ $plan->id }}" data-price="{{ $plan->price }}"></span>
                    @endforeach
                </div>

                <script>
                    document.addEventListener('alpine:init', () => {
                        Alpine.store('diPricing', {
                            plans: {!! $plans->map(fn($p) => ['id' => $p->id, 'price' => floatval($p->price)])->values()->toJson() !!},
                            discounts: { monthly: 0, quarterly: 1, semi_annual: 3, annual: 6 },
                            getPrice(planId, cycle) {
                                const plan = this.plans.find(p => p.id === planId);
                                if (!plan) return 0;
                                const discount = this.discounts[cycle] || 0;
                                return Math.round(plan.price * (1 - discount / 100));
                            },
                            getTotal(planId, cycle) {
                                const months = { monthly: 1, quarterly: 3, semi_annual: 6, annual: 12 };
                                return this.getPrice(planId, cycle) * (months[cycle] || 1);
                            },
                            getSavings(planId, cycle) {
                                const months = { monthly: 1, quarterly: 3, semi_annual: 6, annual: 12 };
                                const plan = this.plans.find(p => p.id === planId);
                                if (!plan) return 0;
                                const full = plan.price * (months[cycle] || 1);
                                return Math.round(full - this.getTotal(planId, cycle));
                            }
                        });
                    });
                </script>
                @endif
            </div>

            @if(isset($plans) && $plans->count())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 max-w-5xl mx-auto" x-data="{ cycle: 'monthly' }" x-init="$watch('cycle', () => {})" @click.window="cycle = $event.target.closest('[x-data]')?.querySelector ? cycle : cycle" x-effect="cycle = document.querySelector('[x-data] button.bg-emerald-600')?.textContent?.includes('Annual') ? 'annual' : document.querySelector('[x-data] button.bg-emerald-600')?.textContent?.includes('Semi') ? 'semi_annual' : document.querySelector('[x-data] button.bg-emerald-600')?.textContent?.includes('Quarterly') ? 'quarterly' : 'monthly'">
                @foreach($plans as $plan)
                @php $isPopular = $plan->name === 'Business'; @endphp
                <div class="relative rounded-2xl overflow-hidden transition duration-300 hover:-translate-y-1 {{ $isPopular ? 'ring-2 ring-emerald-500 shadow-lg' : 'shadow-sm' }}">
                    @if($isPopular)
                    <div class="bg-emerald-600 text-center py-1.5">
                        <span class="text-white text-xs font-bold tracking-wide">BEST VALUE</span>
                    </div>
                    @endif
                    <div class="bg-white border {{ $isPopular ? 'border-emerald-500 border-t-0 rounded-b-2xl' : 'border-gray-200 rounded-2xl' }} p-5">
                        <h3 class="text-lg font-bold text-gray-900">{{ $plan->name }}</h3>
                        <div class="mt-3 mb-1">
                            <span class="text-3xl font-black text-gray-900">Rs. {{ number_format($plan->price, 0) }}</span>
                            <span class="text-gray-400 text-sm">/mo</span>
                        </div>
                        <p class="text-xs text-emerald-600 font-medium">Save up to 6% on annual billing</p>

                        <div class="mt-4 pt-4 border-t border-gray-100 space-y-2 text-sm text-gray-600">
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-emerald-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                {{ $plan->invoice_limit > 0 ? number_format($plan->invoice_limit) . ' invoices/mo' : 'Unlimited invoices' }}
                            </div>
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-emerald-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                {{ ($plan->user_limit ?? 0) > 0 ? $plan->user_limit : (($plan->user_limit ?? 0) == -1 ? 'Unlimited' : ($plan->max_users ?? 'N/A')) }} users
                            </div>
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-emerald-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                FBR real-time submission
                            </div>
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-emerald-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                HS Intelligence + PDF/QR
                            </div>
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-emerald-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Customer ledger + analytics
                            </div>
                        </div>

                        <div class="mt-5">
                            <a href="/register" class="block w-full py-2.5 rounded-lg text-sm font-semibold text-center transition {{ $isPopular ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'bg-gray-900 text-white hover:bg-gray-800' }}">Start Free Trial</a>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            <div class="mt-8 text-center">
                <div class="inline-flex flex-wrap items-center justify-center gap-6 text-xs text-gray-400">
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        FBR compliant
                    </span>
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        14-day free trial
                    </span>
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                        No credit card required
                    </span>
                </div>
            </div>
            @endif
        </div>
    </section>

    <section id="how-it-works" class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-gray-900">How Digital Invoice Works</h2>
                <p class="mt-4 text-gray-600">Simple 5-step process from invoice creation to FBR submission</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-5 gap-6">
                <div class="text-center">
                    <div class="w-14 h-14 rounded-2xl bg-emerald-600 text-white flex items-center justify-center text-xl font-bold mx-auto mb-4">1</div>
                    <h4 class="font-bold text-gray-900 mb-2">Create Invoice</h4>
                    <p class="text-xs text-gray-600">Add buyer details, line items, HS codes, and tax rates using the smart invoice builder</p>
                </div>
                <div class="text-center">
                    <div class="w-14 h-14 rounded-2xl bg-emerald-600 text-white flex items-center justify-center text-xl font-bold mx-auto mb-4">2</div>
                    <h4 class="font-bold text-gray-900 mb-2">AI Validation</h4>
                    <p class="text-xs text-gray-600">HS Intelligence auto-suggests codes, risk engine checks for anomalies, compliance score calculated</p>
                </div>
                <div class="text-center">
                    <div class="w-14 h-14 rounded-2xl bg-emerald-600 text-white flex items-center justify-center text-xl font-bold mx-auto mb-4">3</div>
                    <h4 class="font-bold text-gray-900 mb-2">Submit to FBR</h4>
                    <p class="text-xs text-gray-600">One-click real-time submission to FBR via PRAL API. Idempotency shield prevents duplicates</p>
                </div>
                <div class="text-center">
                    <div class="w-14 h-14 rounded-2xl bg-emerald-600 text-white flex items-center justify-center text-xl font-bold mx-auto mb-4">4</div>
                    <h4 class="font-bold text-gray-900 mb-2">FBR Confirmation</h4>
                    <p class="text-xs text-gray-600">Receive FBR invoice number, QR code, and confirmation. Invoice auto-locked for compliance</p>
                </div>
                <div class="text-center">
                    <div class="w-14 h-14 rounded-2xl bg-emerald-600 text-white flex items-center justify-center text-xl font-bold mx-auto mb-4">5</div>
                    <h4 class="font-bold text-gray-900 mb-2">Download PDF</h4>
                    <p class="text-xs text-gray-600">Generate FBR-compliant PDF with watermarks, QR codes, and dual invoice numbering</p>
                </div>
            </div>
        </div>
    </section>

    <section class="py-16 bg-emerald-600">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h2 class="text-3xl font-bold text-white mb-4">Ready to Get FBR Compliant?</h2>
            <p class="text-emerald-100 mb-8">Start your 14-day free trial. No credit card required.</p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="/register" class="px-8 py-3.5 bg-white text-emerald-700 rounded-xl text-sm font-bold hover:bg-emerald-50 transition shadow-lg">Create Free Account</a>
                <button @click="showLoginModal = true" class="px-8 py-3.5 border-2 border-white/40 text-white rounded-xl text-sm font-bold hover:bg-white/10 transition">Login to Dashboard</button>
            </div>
        </div>
    </section>

    <div class="bg-gray-900 py-4">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-2">
            <p class="text-xs text-gray-500">&copy; {{ date('Y') }} TaxNest. All rights reserved.</p>
            <span class="text-xs text-gray-500 flex items-center"><svg class="w-3.5 h-3.5 mr-1 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/></svg>FBR API v1.12 Integrated</span>
        </div>
    </div>

</body>
</html>
