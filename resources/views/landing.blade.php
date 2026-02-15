<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <title>TaxNest — Pakistan's Smart FBR Compliance Platform</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: 'Figtree', sans-serif; }
        .glass-card { background: rgba(255,255,255,0.15); backdrop-filter: blur(16px) saturate(180%); border: 1px solid rgba(255,255,255,0.25); transition: all 0.3s ease; }
        .glass-card:hover { background: rgba(255,255,255,0.25); transform: translateY(-2px); }
        .hero-gradient { background: linear-gradient(135deg, #064e3b 0%, #059669 40%, #10b981 70%, #34d399 100%); }
        .feature-gradient { background: linear-gradient(180deg, #f0fdf4 0%, #ffffff 100%); }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="antialiased text-gray-800">

    <nav class="fixed top-0 w-full z-50 bg-white/70 backdrop-blur-2xl border-b border-white/30 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between h-16">
            <div class="flex items-center space-x-2">
                <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </div>
                <span class="text-xl font-bold text-gray-900">TaxNest</span>
            </div>
            <div class="hidden md:flex items-center space-x-8">
                <a href="#features" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition">Features</a>
                <a href="#how-it-works" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition">How It Works</a>
                <a href="#pricing" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition">Pricing</a>
                <a href="#faq" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition">FAQ</a>
                <a href="/login" class="inline-flex items-center px-4 py-2 border-2 border-emerald-600 text-emerald-700 rounded-lg text-sm font-semibold hover:bg-emerald-50 transition">Login</a>
                <a href="/register" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-semibold hover:bg-emerald-700 transition shadow-sm">Sign Up Free</a>
            </div>
            <div class="md:hidden flex items-center space-x-2">
                <a href="/login" class="text-sm font-semibold text-emerald-700 border border-emerald-600 px-3 py-1.5 rounded-lg hover:bg-emerald-50 transition">Login</a>
                <a href="/register" class="text-sm font-semibold text-white bg-emerald-600 px-3 py-1.5 rounded-lg hover:bg-emerald-700 transition">Sign Up</a>
            </div>
        </div>
    </nav>

    <section class="hero-gradient pt-32 pb-20 relative overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-20 left-10 w-72 h-72 bg-white rounded-full blur-3xl"></div>
            <div class="absolute bottom-10 right-20 w-96 h-96 bg-emerald-200 rounded-full blur-3xl"></div>
        </div>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="text-center max-w-4xl mx-auto">
                <div class="inline-flex items-center px-4 py-1.5 bg-white/20 backdrop-blur-sm rounded-full text-sm font-medium text-white mb-6 border border-white/30">
                    <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    FBR Compliant &bull; PRAL Integrated &bull; 14-Day Free Trial
                </div>
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-white leading-tight mb-6">
                    TaxNest — Pakistan's Smart<br>FBR Compliance Platform
                </h1>
                <p class="text-xl text-emerald-100 mb-4 max-w-2xl mx-auto leading-relaxed">
                    Validate Before You Submit. Smart invoicing with real-time compliance scoring, vendor risk detection, and seamless PRAL integration.
                </p>
                <p class="text-lg text-emerald-200 mb-10 font-semibold">
                    Plans starting at just Rs. 999/month
                </p>
                <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="/register" class="inline-flex items-center px-8 py-4 bg-white text-emerald-700 rounded-xl text-lg font-bold hover:bg-emerald-50 transition shadow-lg shadow-emerald-900/20 w-full sm:w-auto justify-center">
                        Start 14-Day Free Trial
                        <svg class="w-5 h-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                    </a>
                    <a href="#pricing" class="inline-flex items-center px-8 py-4 bg-transparent text-white rounded-xl text-lg font-bold hover:bg-white/10 transition border-2 border-white/40 w-full sm:w-auto justify-center">
                        View Pricing
                    </a>
                </div>
            </div>

            <div class="mt-16 grid grid-cols-2 sm:grid-cols-4 gap-4 max-w-4xl mx-auto">
                <div class="glass-card rounded-xl p-4 text-center hover:scale-105 transition-all duration-300">
                    <p class="text-3xl font-extrabold text-white">99.9%</p>
                    <p class="text-sm text-emerald-100 mt-1">Uptime SLA</p>
                </div>
                <div class="glass-card rounded-xl p-4 text-center hover:scale-105 transition-all duration-300">
                    <p class="text-3xl font-extrabold text-white">50k+</p>
                    <p class="text-sm text-emerald-100 mt-1">Invoices Processed</p>
                </div>
                <div class="glass-card rounded-xl p-4 text-center hover:scale-105 transition-all duration-300">
                    <p class="text-3xl font-extrabold text-white">500+</p>
                    <p class="text-sm text-emerald-100 mt-1">Companies Trust Us</p>
                </div>
                <div class="glass-card rounded-xl p-4 text-center hover:scale-105 transition-all duration-300">
                    <p class="text-3xl font-extrabold text-white">Rs. 999</p>
                    <p class="text-sm text-emerald-100 mt-1">Starting Price</p>
                </div>
            </div>
        </div>
    </section>

    <div class="bg-white border-b border-gray-100 py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-center gap-8">
                <div class="flex items-center space-x-2 text-gray-500">
                    <svg class="w-6 h-6 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    <span class="text-sm font-medium">FBR Approved</span>
                </div>
                <div class="flex items-center space-x-2 text-gray-500">
                    <svg class="w-6 h-6 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    <span class="text-sm font-medium">SHA-256 Encrypted</span>
                </div>
                <div class="flex items-center space-x-2 text-gray-500">
                    <svg class="w-6 h-6 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span class="text-sm font-medium">PRAL Integrated</span>
                </div>
                <div class="flex items-center space-x-2 text-gray-500">
                    <svg class="w-6 h-6 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/></svg>
                    <span class="text-sm font-medium">Audit Compliant</span>
                </div>
                <div class="flex items-center space-x-2 text-gray-500">
                    <svg class="w-6 h-6 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    <span class="text-sm font-medium">Real-time Compliance</span>
                </div>
            </div>
        </div>
    </div>

    <section id="features" class="feature-gradient py-20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <p class="text-sm font-semibold text-emerald-600 uppercase tracking-wider mb-2">Features</p>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900">Everything You Need for FBR Compliance</h2>
                <p class="mt-4 text-lg text-gray-500 max-w-2xl mx-auto">Enterprise-grade tools designed for Pakistan's tax ecosystem</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <div class="bg-white/70 backdrop-blur-xl border border-white/30 shadow-lg shadow-black/5 hover:-translate-y-1 transition-all duration-300 rounded-2xl p-8 group">
                    <div class="w-12 h-12 bg-emerald-100 rounded-xl flex items-center justify-center mb-5 group-hover:bg-emerald-200 transition">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">FBR Digital Integration</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">Direct PRAL API integration with real-time submission, QR code generation, and invoice locking for complete FBR compliance.</p>
                </div>

                <div class="bg-white/70 backdrop-blur-xl border border-white/30 shadow-lg shadow-black/5 hover:-translate-y-1 transition-all duration-300 rounded-2xl p-8 group">
                    <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center mb-5 group-hover:bg-blue-200 transition">
                        <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Compliance Score Engine</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">AI-powered hybrid scoring that validates tax rates, buyer NTN, banking rules, and structural compliance before submission.</p>
                </div>

                <div class="bg-white/70 backdrop-blur-xl border border-white/30 shadow-lg shadow-black/5 hover:-translate-y-1 transition-all duration-300 rounded-2xl p-8 group">
                    <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center mb-5 group-hover:bg-purple-200 transition">
                        <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Multi-Branch Management</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">Manage multiple branches with branch-level invoicing, reporting, and analytics. Scale your operations seamlessly.</p>
                </div>

                <div class="bg-white/70 backdrop-blur-xl border border-white/30 shadow-lg shadow-black/5 hover:-translate-y-1 transition-all duration-300 rounded-2xl p-8 group">
                    <div class="w-12 h-12 bg-orange-100 rounded-xl flex items-center justify-center mb-5 group-hover:bg-orange-200 transition">
                        <svg class="w-6 h-6 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Customer Ledger</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">Track customer balances with auto-debit on invoice lock, manual payments, adjustments, and running balance per customer.</p>
                </div>

                <div class="bg-white/70 backdrop-blur-xl border border-white/30 shadow-lg shadow-black/5 hover:-translate-y-1 transition-all duration-300 rounded-2xl p-8 group">
                    <div class="w-12 h-12 bg-rose-100 rounded-xl flex items-center justify-center mb-5 group-hover:bg-rose-200 transition">
                        <svg class="w-6 h-6 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Enterprise Analytics</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">MIS reporting with monthly trends, HS concentration analysis, tax variance tracking, and executive compliance dashboards.</p>
                </div>

                <div class="bg-white/70 backdrop-blur-xl border border-white/30 shadow-lg shadow-black/5 hover:-translate-y-1 transition-all duration-300 rounded-2xl p-8 group">
                    <div class="w-12 h-12 bg-teal-100 rounded-xl flex items-center justify-center mb-5 group-hover:bg-teal-200 transition">
                        <svg class="w-6 h-6 text-teal-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Immutable Audit Logs</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">SHA-256 signed audit trail, integrity verification, compliance certificates, and tamper-proof activity tracking.</p>
                </div>
            </div>
        </div>
    </section>

    <section id="how-it-works" class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <p class="text-sm font-semibold text-emerald-600 uppercase tracking-wider mb-2">How It Works</p>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900">Three Simple Steps</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-12">
                <div class="text-center bg-white/70 backdrop-blur-xl border border-white/30 shadow-lg shadow-black/5 rounded-2xl p-8 hover:-translate-y-1 transition-all duration-300">
                    <div class="w-16 h-16 bg-emerald-100 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <span class="text-2xl font-extrabold text-emerald-600">1</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Create Invoice</h3>
                    <p class="text-gray-500 leading-relaxed">Select products from your master catalog. HS codes, tax rates, and prices auto-fill instantly.</p>
                </div>

                <div class="text-center relative bg-white/70 backdrop-blur-xl border border-white/30 shadow-lg shadow-black/5 rounded-2xl p-8 hover:-translate-y-1 transition-all duration-300">
                    <div class="hidden md:block absolute top-8 -left-6 w-12 border-t-2 border-dashed border-emerald-300"></div>
                    <div class="w-16 h-16 bg-emerald-100 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <span class="text-2xl font-extrabold text-emerald-600">2</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Validate & Submit to FBR</h3>
                    <p class="text-gray-500 leading-relaxed">Run compliance checks, view risk scores, and submit directly to PRAL.</p>
                    <div class="hidden md:block absolute top-8 -right-6 w-12 border-t-2 border-dashed border-emerald-300"></div>
                </div>

                <div class="text-center bg-white/70 backdrop-blur-xl border border-white/30 shadow-lg shadow-black/5 rounded-2xl p-8 hover:-translate-y-1 transition-all duration-300">
                    <div class="w-16 h-16 bg-emerald-100 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <span class="text-2xl font-extrabold text-emerald-600">3</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Download & Share</h3>
                    <p class="text-gray-500 leading-relaxed">Download professional PDF invoices with QR codes. Share via WhatsApp or unique links.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="py-20 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <p class="text-sm font-semibold text-emerald-600 uppercase tracking-wider mb-2">Why TaxNest</p>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900">Built Different From Day One</h2>
                <p class="mt-4 text-lg text-gray-500 max-w-2xl mx-auto">No generic accounting software. TaxNest is purpose-built for Pakistan's FBR ecosystem.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mb-16">
                <div class="bg-white rounded-2xl p-8 shadow-sm border border-gray-100 hover:-translate-y-1 transition-all duration-300">
                    <div class="w-12 h-12 bg-emerald-100 rounded-xl flex items-center justify-center mb-5">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Real-Time FBR Submission</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">Not just PDF generation. Direct PRAL API integration with live QR codes, invoice locking, and instant FBR response.</p>
                </div>
                <div class="bg-white rounded-2xl p-8 shadow-sm border border-gray-100 hover:-translate-y-1 transition-all duration-300">
                    <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center mb-5">
                        <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">HS Intelligence Engine</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">6-factor AI model learns from every submission. Auto-suggests HS codes, SRO numbers, and tax rates with confidence scoring.</p>
                </div>
                <div class="bg-white rounded-2xl p-8 shadow-sm border border-gray-100 hover:-translate-y-1 transition-all duration-300">
                    <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center mb-5">
                        <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Pre-Submission Validation</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">Compliance scoring catches errors before FBR sees them. Risk detection blocks problematic invoices automatically.</p>
                </div>
                <div class="bg-white rounded-2xl p-8 shadow-sm border border-gray-100 hover:-translate-y-1 transition-all duration-300">
                    <div class="w-12 h-12 bg-orange-100 rounded-xl flex items-center justify-center mb-5">
                        <svg class="w-6 h-6 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Multi-Company & Branch</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">Enterprise-grade tenant isolation. Manage multiple companies and branches from a single admin dashboard.</p>
                </div>
                <div class="bg-white rounded-2xl p-8 shadow-sm border border-gray-100 hover:-translate-y-1 transition-all duration-300">
                    <div class="w-12 h-12 bg-rose-100 rounded-xl flex items-center justify-center mb-5">
                        <svg class="w-6 h-6 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Tamper-Proof Audit Trail</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">SHA-256 hashed audit logs with integrity verification. Every action tracked, timestamped, and immutable.</p>
                </div>
                <div class="bg-white rounded-2xl p-8 shadow-sm border border-gray-100 hover:-translate-y-1 transition-all duration-300">
                    <div class="w-12 h-12 bg-teal-100 rounded-xl flex items-center justify-center mb-5">
                        <svg class="w-6 h-6 text-teal-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Works Offline (PWA)</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">Install on any device. Create drafts offline, sync when connected. Keyboard shortcuts for power users.</p>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden max-w-4xl mx-auto">
                <div class="p-6 border-b border-gray-100 bg-gray-50">
                    <h3 class="text-lg font-bold text-gray-900 text-center">TaxNest vs Traditional Software</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="text-left py-3 px-6 text-sm font-medium text-gray-500">Capability</th>
                                <th class="text-center py-3 px-6 text-sm font-bold text-emerald-700">TaxNest</th>
                                <th class="text-center py-3 px-6 text-sm font-bold text-gray-500">Others</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @php
                            $comparisons = [
                                ['Direct FBR/PRAL API', true, false],
                                ['Real-time QR Codes', true, false],
                                ['HS Code Intelligence', true, false],
                                ['Compliance Scoring', true, false],
                                ['Multi-Branch Support', true, 'partial'],
                                ['Immutable Audit Logs', true, false],
                                ['Offline PWA Support', true, false],
                                ['Customer Ledger', true, 'partial'],
                                ['Starts at Rs. 999/mo', true, false],
                            ];
                            @endphp
                            @foreach($comparisons as $comp)
                            <tr class="{{ $loop->even ? 'bg-gray-50/50' : '' }}">
                                <td class="py-3 px-6 text-sm font-medium text-gray-700">{{ $comp[0] }}</td>
                                <td class="py-3 px-6 text-center">
                                    @if($comp[1] === true)
                                    <svg class="w-5 h-5 text-emerald-500 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    @endif
                                </td>
                                <td class="py-3 px-6 text-center">
                                    @if($comp[2] === true)
                                    <svg class="w-5 h-5 text-emerald-500 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    @elseif($comp[2] === 'partial')
                                    <span class="text-xs font-medium text-amber-600 bg-amber-50 px-2 py-0.5 rounded">Limited</span>
                                    @else
                                    <svg class="w-5 h-5 text-red-400 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <section id="pricing" class="py-20 bg-gray-50" x-data="{
        cycle: 'monthly',
        discounts: { monthly: 0, quarterly: 1, semi_annual: 3, annual: 6 },
        months: { monthly: 1, quarterly: 3, semi_annual: 6, annual: 12 },
        plans: [
            { name: 'Retail', price: 999, invoices: '100', users: '2', branches: '1', tag: '', features: ['100 invoices/mo', '2 users', '1 branch', 'FBR Integration', 'PDF Generation', 'Compliance Scoring'] },
            { name: 'Business', price: 2999, invoices: '700', users: '5', branches: '3', tag: 'MOST POPULAR', features: ['700 invoices/mo', '5 users', '3 branches', 'FBR Integration', 'PDF + QR Codes', 'MIS Reports', 'Customer Ledger'] },
            { name: 'Industrial', price: 6999, invoices: '2,500', users: '15', branches: 'Unlimited', tag: '', features: ['2,500 invoices/mo', '15 users', 'Unlimited branches', 'FBR Integration', 'All Reports', 'Customer Ledger', 'Priority Support'] },
            { name: 'Enterprise', price: 15000, invoices: 'Unlimited', users: 'Unlimited', branches: 'Unlimited', tag: 'BEST VALUE', features: ['Unlimited invoices', 'Unlimited users', 'Unlimited branches', 'FBR Integration', 'All Reports', 'Customer Ledger', 'Priority Support', 'Dedicated Manager', 'Custom Integrations'] }
        ],
        calcTotal(base) {
            let m = this.months[this.cycle];
            let d = this.discounts[this.cycle];
            let total = base * m;
            return Math.round(total - (total * d / 100));
        },
        calcMonthly(base) {
            return Math.round(this.calcTotal(base) / this.months[this.cycle]);
        },
        savings(base) {
            let m = this.months[this.cycle];
            return Math.round(base * m - this.calcTotal(base));
        }
    }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-8">
                <p class="text-sm font-semibold text-emerald-600 uppercase tracking-wider mb-2">High-Volume Pricing</p>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900">Aggressive Pricing for Market Leaders</h2>
                <p class="mt-4 text-lg text-gray-500">Start with a 14-day free trial. No credit card required.</p>
            </div>

            <div class="flex justify-center mb-10">
                <div class="inline-flex bg-white rounded-xl p-1.5 shadow-sm border border-gray-200">
                    <button @click="cycle = 'monthly'" :class="cycle === 'monthly' ? 'bg-emerald-600 text-white shadow-sm' : 'text-gray-600 hover:text-gray-800'" class="px-5 py-2.5 rounded-lg text-sm font-medium transition">Monthly</button>
                    <button @click="cycle = 'quarterly'" :class="cycle === 'quarterly' ? 'bg-emerald-600 text-white shadow-sm' : 'text-gray-600 hover:text-gray-800'" class="px-5 py-2.5 rounded-lg text-sm font-medium transition">Quarterly <span class="text-xs font-bold" :class="cycle === 'quarterly' ? 'text-emerald-200' : 'text-emerald-600'">-1%</span></button>
                    <button @click="cycle = 'semi_annual'" :class="cycle === 'semi_annual' ? 'bg-emerald-600 text-white shadow-sm' : 'text-gray-600 hover:text-gray-800'" class="px-5 py-2.5 rounded-lg text-sm font-medium transition">Semi-Annual <span class="text-xs font-bold" :class="cycle === 'semi_annual' ? 'text-emerald-200' : 'text-emerald-600'">-3%</span></button>
                    <button @click="cycle = 'annual'" :class="cycle === 'annual' ? 'bg-emerald-600 text-white shadow-sm' : 'text-gray-600 hover:text-gray-800'" class="px-5 py-2.5 rounded-lg text-sm font-medium transition">Annual <span class="text-xs font-bold" :class="cycle === 'annual' ? 'text-emerald-200' : 'text-emerald-600'">-6%</span></button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 max-w-6xl mx-auto">
                <template x-for="plan in plans" :key="plan.name">
                    <div class="bg-white/70 backdrop-blur-xl border-2 shadow-lg shadow-black/5 hover:-translate-y-1 transition-all duration-300 rounded-2xl relative"
                         :class="plan.name === 'Business' ? 'border-emerald-500 ring-2 ring-emerald-500/50 shadow-xl shadow-emerald-500/10' : 'border-white/30'">

                        <div x-show="plan.tag" class="absolute -top-3 left-1/2 transform -translate-x-1/2">
                            <span class="bg-emerald-600 text-white text-xs font-bold px-3 py-1 rounded-full" x-text="plan.tag"></span>
                        </div>

                        <div class="p-6">
                            <h3 class="text-xl font-bold text-gray-900" x-text="plan.name"></h3>
                            <div class="mt-4">
                                <div x-show="cycle === 'monthly'">
                                    <span class="text-3xl font-extrabold text-gray-900">Rs. <span x-text="plan.price.toLocaleString()"></span></span>
                                    <span class="text-gray-500 text-sm">/mo</span>
                                </div>
                                <div x-show="cycle !== 'monthly'">
                                    <span class="text-3xl font-extrabold text-gray-900">Rs. <span x-text="calcMonthly(plan.price).toLocaleString()"></span></span>
                                    <span class="text-gray-500 text-sm">/mo</span>
                                    <p class="text-xs text-gray-400 mt-1">Rs. <span x-text="calcTotal(plan.price).toLocaleString()"></span> total</p>
                                    <p x-show="savings(plan.price) > 0" class="text-xs text-emerald-600 font-semibold mt-0.5">Save Rs. <span x-text="savings(plan.price).toLocaleString()"></span></p>
                                </div>
                            </div>
                            <div class="mt-4 mb-4 flex flex-wrap gap-2">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700">
                                    <span x-text="plan.invoices"></span>&nbsp;invoices
                                </span>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-50 text-purple-700">
                                    <span x-text="plan.users"></span>&nbsp;users
                                </span>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-50 text-orange-700">
                                    <span x-text="plan.branches"></span>&nbsp;branch<span x-show="plan.branches !== '1'">es</span>
                                </span>
                            </div>
                            <ul class="space-y-2">
                                <template x-for="f in plan.features" :key="f">
                                    <li class="flex items-center text-sm text-gray-600">
                                        <svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        <span x-text="f"></span>
                                    </li>
                                </template>
                            </ul>
                            <a href="/register" class="block w-full text-center mt-6 px-6 py-3 rounded-xl font-semibold text-sm transition"
                               :class="plan.name === 'Business' ? 'bg-emerald-600 text-white hover:bg-emerald-700 shadow-sm' : 'bg-gray-900 text-white hover:bg-gray-800'">
                                Start Free Trial
                            </a>
                        </div>
                    </div>
                </template>
            </div>

            <div class="mt-8 text-center">
                <p class="text-gray-600 mb-3">Need a custom configuration?</p>
                <a href="/register" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl text-sm font-bold hover:from-indigo-700 hover:to-purple-700 transition shadow-lg shadow-indigo-500/25">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                    Build Custom Plan
                </a>
            </div>

            <div class="mt-12 bg-white/70 backdrop-blur-xl rounded-2xl shadow-lg shadow-black/5 border border-white/30 overflow-hidden max-w-6xl mx-auto">
                <div class="p-6 border-b border-gray-100">
                    <h3 class="text-xl font-bold text-gray-900">Feature Comparison</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50">
                                <th class="text-left py-3 px-4 text-sm font-medium text-gray-500 w-1/5">Feature</th>
                                <th class="text-center py-3 px-4 text-sm font-bold text-gray-900">Retail<br><span class="text-xs font-normal text-gray-500">Rs. 999/mo</span></th>
                                <th class="text-center py-3 px-4 text-sm font-bold text-emerald-700 bg-emerald-50">Business<br><span class="text-xs font-normal text-emerald-600">Rs. 2,999/mo</span></th>
                                <th class="text-center py-3 px-4 text-sm font-bold text-gray-900">Industrial<br><span class="text-xs font-normal text-gray-500">Rs. 6,999/mo</span></th>
                                <th class="text-center py-3 px-4 text-sm font-bold text-gray-900">Enterprise<br><span class="text-xs font-normal text-gray-500">Rs. 15,000/mo</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @php
                            $rows = [
                                ['Invoices/Month', '100', '700', '2,500', 'Unlimited'],
                                ['Users', '2', '5', '15', 'Unlimited'],
                                ['Branches', '1', '3', 'Unlimited', 'Unlimited'],
                            ];
                            $checks = [
                                ['FBR Integration', true, true, true, true],
                                ['PDF + QR Generation', true, true, true, true],
                                ['Compliance Scoring', true, true, true, true],
                                ['MIS Reports', false, true, true, true],
                                ['Customer Ledger', false, true, true, true],
                                ['Immutable Audit Logs', false, false, true, true],
                                ['Priority Support', false, false, true, true],
                                ['Dedicated Manager', false, false, false, true],
                                ['Custom Integrations', false, false, false, true],
                            ];
                            @endphp
                            @foreach($rows as $row)
                            <tr class="{{ $loop->even ? 'bg-gray-50' : '' }}">
                                <td class="py-3 px-4 text-sm font-medium text-gray-700">{{ $row[0] }}</td>
                                <td class="py-3 px-4 text-center text-sm font-semibold">{{ $row[1] }}</td>
                                <td class="py-3 px-4 text-center text-sm font-semibold bg-emerald-50/50">{{ $row[2] }}</td>
                                <td class="py-3 px-4 text-center text-sm font-semibold">{{ $row[3] }}</td>
                                <td class="py-3 px-4 text-center text-sm font-semibold">{{ $row[4] }}</td>
                            </tr>
                            @endforeach
                            @foreach($checks as $check)
                            <tr class="{{ $loop->even ? 'bg-gray-50' : '' }}">
                                <td class="py-3 px-4 text-sm text-gray-600">{{ $check[0] }}</td>
                                @for($i = 1; $i <= 4; $i++)
                                <td class="py-3 px-4 text-center {{ $i === 2 ? 'bg-emerald-50/50' : '' }}">
                                    @if($check[$i])
                                    <svg class="w-5 h-5 text-emerald-500 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    @else
                                    <svg class="w-5 h-5 text-gray-300 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 12H6"/></svg>
                                    @endif
                                </td>
                                @endfor
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-12 max-w-2xl mx-auto bg-white/70 backdrop-blur-xl rounded-2xl shadow-lg shadow-black/5 border border-white/30 p-8">
                <h3 class="text-xl font-bold text-gray-900 mb-6 text-center">Billing Calculator</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Select Plan</label>
                        <select x-model="selectedPlan" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-emerald-500 focus:border-emerald-500"
                            x-init="selectedPlan = '2999'">
                            <template x-for="plan in plans" :key="plan.price">
                                <option :value="plan.price" x-text="plan.name + ' - Rs. ' + plan.price.toLocaleString() + '/mo'"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Billing Cycle</label>
                        <select x-model="cycle" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="monthly">Monthly (0% discount)</option>
                            <option value="quarterly">Quarterly (1% discount)</option>
                            <option value="semi_annual">Semi-Annual (3% discount)</option>
                            <option value="annual">Annual (6% discount)</option>
                        </select>
                    </div>
                    <div class="bg-emerald-50 rounded-xl p-5 mt-4">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-sm text-gray-600">Base Price</span>
                            <span class="text-sm font-medium" x-text="'Rs. ' + (parseInt(selectedPlan) * months[cycle]).toLocaleString()"></span>
                        </div>
                        <div x-show="discounts[cycle] > 0" class="flex justify-between items-center mb-2">
                            <span class="text-sm text-emerald-600">Discount (<span x-text="discounts[cycle]"></span>%)</span>
                            <span class="text-sm font-medium text-emerald-600" x-text="'- Rs. ' + savings(parseInt(selectedPlan)).toLocaleString()"></span>
                        </div>
                        <div class="border-t border-emerald-200 pt-2 mt-2 flex justify-between items-center">
                            <span class="text-base font-bold text-gray-900">Total Payable</span>
                            <span class="text-xl font-extrabold text-emerald-700" x-text="'Rs. ' + calcTotal(parseInt(selectedPlan)).toLocaleString()"></span>
                        </div>
                        <div class="flex justify-between items-center mt-1">
                            <span class="text-xs text-gray-500">Effective monthly</span>
                            <span class="text-sm font-semibold text-gray-600" x-text="'Rs. ' + calcMonthly(parseInt(selectedPlan)).toLocaleString() + '/mo'"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="faq" class="py-20 bg-white">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <p class="text-sm font-semibold text-emerald-600 uppercase tracking-wider mb-2">FAQ</p>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900">Frequently Asked Questions</h2>
            </div>
            <div class="space-y-4" x-data="{ open: null }">
                <div class="bg-white/70 backdrop-blur-xl border border-white/30 shadow-lg shadow-black/5 rounded-2xl overflow-hidden">
                    <button @click="open = open === 1 ? null : 1" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="text-base font-semibold text-gray-900">What is TaxNest?</span>
                        <svg class="w-5 h-5 text-gray-500 transition-transform" :class="open === 1 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 1" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="px-6 pb-6 text-sm text-gray-600 leading-relaxed">TaxNest is Pakistan's leading FBR-compliant tax and invoice management platform. It integrates directly with FBR's PRAL API for real-time invoice submission, compliance scoring, and QR code generation.</div>
                </div>
                <div class="bg-white/70 backdrop-blur-xl border border-white/30 shadow-lg shadow-black/5 rounded-2xl overflow-hidden">
                    <button @click="open = open === 2 ? null : 2" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="text-base font-semibold text-gray-900">Is TaxNest FBR approved?</span>
                        <svg class="w-5 h-5 text-gray-500 transition-transform" :class="open === 2 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 2" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="px-6 pb-6 text-sm text-gray-600 leading-relaxed">Yes, TaxNest integrates directly with FBR's PRAL API v1.12 for production invoice submission. All invoices are validated against FBR's compliance rules before submission.</div>
                </div>
                <div class="bg-white/70 backdrop-blur-xl border border-white/30 shadow-lg shadow-black/5 rounded-2xl overflow-hidden">
                    <button @click="open = open === 3 ? null : 3" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="text-base font-semibold text-gray-900">How does the free trial work?</span>
                        <svg class="w-5 h-5 text-gray-500 transition-transform" :class="open === 3 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 3" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="px-6 pb-6 text-sm text-gray-600 leading-relaxed">Sign up and get 14 days of full access with no credit card required. You can create invoices, submit to FBR, and explore all features. After the trial, choose a plan that fits your business.</div>
                </div>
                <div class="bg-white/70 backdrop-blur-xl border border-white/30 shadow-lg shadow-black/5 rounded-2xl overflow-hidden">
                    <button @click="open = open === 4 ? null : 4" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="text-base font-semibold text-gray-900">Can I manage multiple branches?</span>
                        <svg class="w-5 h-5 text-gray-500 transition-transform" :class="open === 4 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 4" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="px-6 pb-6 text-sm text-gray-600 leading-relaxed">Yes! Business plans support up to 3 branches, Industrial plans offer unlimited branches, and Enterprise plans include unlimited branches with dedicated support.</div>
                </div>
                <div class="bg-white/70 backdrop-blur-xl border border-white/30 shadow-lg shadow-black/5 rounded-2xl overflow-hidden">
                    <button @click="open = open === 5 ? null : 5" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="text-base font-semibold text-gray-900">Is my data secure?</span>
                        <svg class="w-5 h-5 text-gray-500 transition-transform" :class="open === 5 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 5" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="px-6 pb-6 text-sm text-gray-600 leading-relaxed">Absolutely. TaxNest uses SHA-256 encrypted audit logs, immutable transaction records, and encrypted FBR tokens. All data is stored securely with role-based access controls.</div>
                </div>
                <div class="bg-white/70 backdrop-blur-xl border border-white/30 shadow-lg shadow-black/5 rounded-2xl overflow-hidden">
                    <button @click="open = open === 6 ? null : 6" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="text-base font-semibold text-gray-900">Can I build a custom plan?</span>
                        <svg class="w-5 h-5 text-gray-500 transition-transform" :class="open === 6 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 6" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="px-6 pb-6 text-sm text-gray-600 leading-relaxed">Yes! After signing up, use our Custom Plan Builder to configure exact invoice limits, user counts, and branch counts. Pricing is calculated dynamically with cycle-based discounts.</div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-16 bg-emerald-700 relative overflow-hidden">
        <div class="absolute inset-0 bg-white/5 backdrop-blur-sm"></div>
        <div class="relative">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h2 class="text-3xl font-extrabold text-white mb-4">Ready to Streamline Your FBR Compliance?</h2>
            <p class="text-lg text-emerald-200 mb-8">Join 500+ businesses already using TaxNest. Start your 14-day free trial today.</p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="/register" class="inline-flex items-center px-8 py-4 bg-white text-emerald-700 rounded-xl text-lg font-bold hover:bg-emerald-50 transition shadow-lg w-full sm:w-auto justify-center">
                    Start 14-Day Free Trial
                    <svg class="w-5 h-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                </a>
                <a href="https://wa.me/923001234567?text=Hi%2C%20I%27m%20interested%20in%20TaxNest" target="_blank" class="inline-flex items-center px-8 py-4 bg-transparent text-white rounded-xl text-lg font-bold hover:bg-white/10 transition border-2 border-white/40 w-full sm:w-auto justify-center">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                    Chat on WhatsApp
                </a>
            </div>
        </div>
        </div>
    </section>

    <footer class="bg-gradient-to-b from-gray-900 to-gray-950 text-gray-400 py-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-8 mb-12">
                <div>
                    <div class="flex items-center space-x-2 mb-4">
                        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        </div>
                        <span class="text-lg font-bold text-white">TaxNest</span>
                    </div>
                    <p class="text-sm leading-relaxed">Pakistan's smart FBR compliance platform for modern businesses.</p>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-white uppercase tracking-wider mb-4">Product</h4>
                    <ul class="space-y-2">
                        <li><a href="#features" class="text-sm hover:text-white transition">Features</a></li>
                        <li><a href="#pricing" class="text-sm hover:text-white transition">Pricing</a></li>
                        <li><a href="#how-it-works" class="text-sm hover:text-white transition">How It Works</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-white uppercase tracking-wider mb-4">Contact</h4>
                    <ul class="space-y-2">
                        <li><span class="text-sm">info@taxnest.pk</span></li>
                        <li><span class="text-sm">+92 300 1234567</span></li>
                        <li><span class="text-sm">Islamabad, Pakistan</span></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-white uppercase tracking-wider mb-4">Legal</h4>
                    <ul class="space-y-2">
                        <li><a href="#" class="text-sm hover:text-white transition">Terms of Service</a></li>
                        <li><a href="#" class="text-sm hover:text-white transition">Privacy Policy</a></li>
                        <li><a href="/login" class="text-sm hover:text-white transition">Login</a></li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-gray-800 pt-8 text-center">
                <p class="text-sm">&copy; {{ date('Y') }} TaxNest. All rights reserved. Built for Pakistan's tax ecosystem.</p>
            </div>
        </div>
    </footer>

    <a href="https://wa.me/923001234567?text=Hi%2C%20I%27m%20interested%20in%20TaxNest" target="_blank"
       class="fixed bottom-6 right-6 z-50 w-14 h-14 bg-green-500 rounded-full flex items-center justify-center shadow-lg hover:bg-green-600 transition hover:scale-110">
        <svg class="w-7 h-7 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
    </a>

    @if(!empty($showLogin))
    <div x-data="{ open: true }" x-show="open" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 backdrop-blur-sm">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 p-8 relative" @click.outside="open = false">
            <button @click="open = false" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>

            <div class="mb-6 text-center">
                <div class="w-12 h-12 rounded-xl bg-emerald-600 flex items-center justify-center mx-auto mb-3">
                    <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </div>
                <h2 class="text-xl font-bold text-gray-800">Welcome Back</h2>
                <p class="text-sm text-gray-500 mt-1">Login to your TaxNest account</p>
            </div>

            <form method="POST" action="/login">
                @csrf
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email / Phone / Username / CNIC / NTN / FBR Reg</label>
                    <input type="text" name="login" required autofocus placeholder="Enter email, phone, username, CNIC, NTN or FBR Reg"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                    @if($errors->has('login'))
                        <p class="text-red-500 text-xs mt-1">{{ $errors->first('login') }}</p>
                    @endif
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input type="password" name="password" required
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                    @if($errors->has('password'))
                        <p class="text-red-500 text-xs mt-1">{{ $errors->first('password') }}</p>
                    @endif
                </div>
                <div class="flex items-center justify-between mb-4">
                    <label class="flex items-center text-sm text-gray-600">
                        <input type="checkbox" name="remember" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 mr-2">
                        Remember me
                    </label>
                    <a href="/forgot-password" class="text-sm text-emerald-600 hover:text-emerald-800">Forgot password?</a>
                </div>
                <button type="submit" class="w-full py-2.5 bg-emerald-600 text-white rounded-lg font-semibold hover:bg-emerald-700 transition">
                    Log In
                </button>
            </form>

            <div class="mt-4 pt-4 border-t border-gray-200">
                <p class="text-xs text-gray-400 mb-2 text-center">You can also login with:</p>
                <div class="flex justify-center gap-1.5 flex-wrap">
                    <span class="inline-flex items-center gap-1 text-xs text-gray-500 px-2 py-0.5 rounded-full bg-gray-50 border border-gray-200/60">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        Email
                    </span>
                    <span class="inline-flex items-center gap-1 text-xs text-gray-500 px-2 py-0.5 rounded-full bg-gray-50 border border-gray-200/60">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        Phone
                    </span>
                    <span class="inline-flex items-center gap-1 text-xs text-gray-500 px-2 py-0.5 rounded-full bg-gray-50 border border-gray-200/60">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        Username
                    </span>
                    <span class="inline-flex items-center gap-1 text-xs text-gray-500 px-2 py-0.5 rounded-full bg-gray-50 border border-gray-200/60">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0"/></svg>
                        CNIC / NTN
                    </span>
                    <span class="inline-flex items-center gap-1 text-xs text-gray-500 px-2 py-0.5 rounded-full bg-gray-50 border border-gray-200/60">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        FBR Reg
                    </span>
                </div>
            </div>
            <div class="mt-3 text-center">
                <p class="text-sm text-gray-500">Don't have an account? <a href="/register" class="text-emerald-600 font-semibold hover:text-emerald-800">Sign Up Free</a></p>
            </div>
        </div>
    </div>
    @endif

</body>
</html>
