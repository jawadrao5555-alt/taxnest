<!DOCTYPE html>
<html lang="en" class="overflow-x-hidden">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <title>TaxNest — Pakistan's Most Advanced Tax Compliance Platform</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        [x-cloak] { display: none !important; }
        .fade-up { opacity: 0; transform: translateY(24px); transition: opacity 0.7s ease, transform 0.7s ease; }
        .fade-up.visible { opacity: 1; transform: translateY(0); }
        .hero-glow {
            background:
                radial-gradient(ellipse 80% 60% at 50% -10%, rgba(16,185,129,0.22) 0%, transparent 70%),
                radial-gradient(ellipse 60% 50% at 80% 50%, rgba(139,92,246,0.14) 0%, transparent 60%),
                radial-gradient(ellipse 40% 40% at 20% 80%, rgba(6,182,212,0.10) 0%, transparent 50%);
        }
        .card-hover { transition: transform 0.3s ease, box-shadow 0.3s ease; }
        .card-hover:hover { transform: translateY(-4px); box-shadow: 0 20px 40px -12px rgba(0,0,0,0.12); }
        .btn-glow { transition: all 0.25s ease; }
        .btn-glow:hover { transform: translateY(-1px); box-shadow: 0 8px 24px -4px rgba(16,185,129,0.4); }
        .btn-glow-purple { transition: all 0.25s ease; }
        .btn-glow-purple:hover { transform: translateY(-1px); box-shadow: 0 8px 24px -4px rgba(139,92,246,0.4); }
        .stat-glass { background: rgba(255,255,255,0.06); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); border: 1px solid rgba(255,255,255,0.1); }
        .stat-glass:hover { background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.2); transform: translateY(-2px); }
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
        @keyframes countUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideInLeft { from { opacity: 0; transform: translateX(-30px); } to { opacity: 1; transform: translateX(0); } }
        @keyframes scaleIn { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
        .orb-1 { animation: float 8s ease-in-out infinite; }
        .orb-2 { animation: float-reverse 10s ease-in-out infinite; }
        .orb-3 { animation: float 12s ease-in-out infinite 2s; }
        .shimmer-text {
            background: linear-gradient(90deg, rgba(255,255,255,0) 0%, rgba(255,255,255,0.08) 50%, rgba(255,255,255,0) 100%);
            background-size: 200% 100%;
            animation: shimmer 4s ease-in-out infinite;
        }
        .gradient-border-top { border-top: 4px solid; border-image: linear-gradient(to right, #10b981, #14b8a6) 1; }
        .gradient-border-top-purple { border-top: 4px solid; border-image: linear-gradient(to right, #8b5cf6, #a78bfa) 1; }
        .pricing-glow { box-shadow: 0 0 80px -20px rgba(16,185,129,0.25), 0 0 40px -10px rgba(139,92,246,0.15); }
        .grid-overlay {
            background-image: linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
            background-size: 60px 60px;
        }
        .product-card-glow-emerald { box-shadow: 0 0 0 1px rgba(16,185,129,0.1); }
        .product-card-glow-emerald:hover { box-shadow: 0 0 40px -8px rgba(16,185,129,0.15), 0 20px 40px -12px rgba(0,0,0,0.1); }
        .product-card-glow-purple { box-shadow: 0 0 0 1px rgba(139,92,246,0.1); }
        .product-card-glow-purple:hover { box-shadow: 0 0 40px -8px rgba(139,92,246,0.15), 0 20px 40px -12px rgba(0,0,0,0.1); }
        .product-card-glow-blue { box-shadow: 0 0 0 1px rgba(37,99,235,0.1); }
        .product-card-glow-blue:hover { box-shadow: 0 0 40px -8px rgba(37,99,235,0.15), 0 20px 40px -12px rgba(0,0,0,0.1); }
        .step-connector { position: relative; }
        .step-connector::after { content: ''; position: absolute; top: 50%; right: -24px; width: 48px; height: 2px; background: linear-gradient(90deg, #10b981, #8b5cf6); opacity: 0.3; }
        @media (max-width: 768px) { .step-connector::after { display: none; } }
        .feature-icon-glow { box-shadow: 0 0 20px -4px currentColor; }
    </style>
</head>
<body class="antialiased text-gray-700 scroll-smooth bg-white dark:bg-gray-900 overflow-x-hidden">

    <nav class="fixed top-0 w-full z-50 bg-white/70 backdrop-blur-2xl border-b border-gray-100/80 shadow-[0_1px_3px_rgba(0,0,0,0.04)]">
        <div class="max-w-[1200px] mx-auto px-3 sm:px-5 md:px-8">
            <div class="flex items-center justify-between py-2.5 sm:py-0 sm:h-[60px]">
                <a href="/" class="flex items-center space-x-2 flex-shrink-0">
                    <div class="w-7 h-7 sm:w-8 sm:h-8 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/20">
                        <svg class="w-4 h-4 sm:w-[18px] sm:h-[18px] text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <span class="text-sm sm:text-[17px] font-bold text-gray-900 tracking-tight">TaxNest</span>
                </a>

                <div class="flex items-center flex-wrap justify-end gap-1 sm:gap-2">
                    <a href="/digital-invoice" class="px-2 py-1 sm:px-4 sm:py-2 text-[10px] sm:text-[13px] font-semibold text-white bg-emerald-600 rounded-full hover:bg-emerald-700 transition shadow-sm">Digital Invoice</a>
                    <a href="/pos" class="px-2 py-1 sm:px-4 sm:py-2 text-[10px] sm:text-[13px] font-semibold text-white bg-purple-600 rounded-full hover:bg-purple-700 transition shadow-sm">PRA POS</a>
                    <a href="/fbr-pos-landing" class="px-2 py-1 sm:px-4 sm:py-2 text-[10px] sm:text-[13px] font-semibold text-white bg-blue-600 rounded-full hover:bg-blue-700 transition shadow-sm">FBR POS</a>
                    <a href="#pricing" class="px-1.5 py-1 sm:px-3 sm:py-1.5 text-[10px] sm:text-[13px] font-medium text-gray-500 hover:text-gray-900 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 transition">Pricing</a>
                    <a href="#features" class="px-1.5 py-1 sm:px-3 sm:py-1.5 text-[10px] sm:text-[13px] font-medium text-gray-500 hover:text-gray-900 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 transition">Docs</a>
                    <a href="#contact" class="px-1.5 py-1 sm:px-3 sm:py-1.5 text-[10px] sm:text-[13px] font-medium text-gray-500 hover:text-gray-900 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 transition">Contact</a>
                </div>
            </div>
        </div>
    </nav>

    <section class="relative pt-32 pb-28 sm:pt-40 sm:pb-36 bg-[#0a0f1a] overflow-hidden">
        <div class="hero-glow absolute inset-0"></div>
        <div class="grid-overlay absolute inset-0"></div>
        <div class="absolute inset-0 opacity-[0.03]" style="background-image: url('data:image/svg+xml,%3Csvg width=&quot;60&quot; height=&quot;60&quot; viewBox=&quot;0 0 60 60&quot; xmlns=&quot;http://www.w3.org/2000/svg&quot;%3E%3Cg fill=&quot;none&quot; fill-rule=&quot;evenodd&quot;%3E%3Cg fill=&quot;%23ffffff&quot; fill-opacity=&quot;1&quot;%3E%3Cpath d=&quot;M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z&quot;/%3E%3C/g%3E%3C/g%3E%3C/svg%3E');"></div>

        <div class="orb-1 absolute top-20 left-[10%] w-72 h-72 bg-emerald-500/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="orb-2 absolute bottom-10 right-[15%] w-96 h-96 bg-purple-500/8 rounded-full blur-3xl pointer-events-none"></div>
        <div class="orb-3 absolute top-1/2 left-[60%] w-64 h-64 bg-teal-400/8 rounded-full blur-3xl pointer-events-none"></div>

        <div class="max-w-[1200px] mx-auto px-3 sm:px-5 md:px-8 relative">
            <div class="text-center max-w-3xl mx-auto">
                <div class="inline-flex items-center px-3.5 py-1.5 bg-white/[0.07] rounded-full text-[13px] font-medium text-gray-300 mb-8 border border-white/[0.08] backdrop-blur-sm">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 mr-2 animate-pulse"></span>
                    FBR + PRA Compliant Platform
                </div>

                <h1 class="text-[28px] sm:text-[52px] lg:text-[56px] font-bold text-white leading-[1.1] tracking-tight mb-6">
                    TaxNest — Pakistan's Most Advanced
                    <span class="block mt-1 bg-gradient-to-r from-emerald-400 via-teal-400 to-emerald-300 bg-clip-text text-transparent">Tax Compliance Platform</span>
                </h1>

                <p class="text-[17px] sm:text-lg text-gray-400 leading-relaxed mb-10 max-w-2xl mx-auto">
                    Manage FBR Digital Invoicing, PRA POS, and FBR POS billing in one secure enterprise platform. Three fully isolated products, real-time compliant.
                </p>

                <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                    <a href="#products" class="btn-glow inline-flex items-center px-7 py-3.5 bg-gradient-to-r from-emerald-400 to-teal-500 text-white rounded-[10px] text-[15px] font-semibold hover:from-emerald-500 hover:to-teal-600 w-full sm:w-auto justify-center shadow-lg shadow-emerald-500/25">
                        Start Free Trial
                        <svg class="w-4 h-4 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                    </a>
                    <a href="#products" class="inline-flex items-center px-7 py-3.5 bg-white/[0.06] text-gray-300 border border-white/[0.12] rounded-[10px] text-[15px] font-semibold hover:bg-white/[0.15] hover:text-white hover:border-white/[0.2] transition w-full sm:w-auto justify-center backdrop-blur-sm">
                        Explore Products
                        <svg class="w-4 h-4 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </a>
                </div>

                <p class="text-[13px] text-gray-500 mt-5">14-day free trial &middot; No credit card required</p>
            </div>

            <div class="mt-20 grid grid-cols-2 sm:grid-cols-4 gap-4 max-w-3xl mx-auto">
                <div class="stat-glass rounded-2xl p-5 text-center transition-all duration-300">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/20 flex items-center justify-center mx-auto mb-3">
                        <svg class="w-5 h-5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                    </div>
                    <p class="text-2xl sm:text-3xl font-bold text-white">99.9%</p>
                    <p class="text-xs text-gray-400 mt-1">Uptime SLA</p>
                </div>
                <div class="stat-glass rounded-2xl p-5 text-center transition-all duration-300">
                    <div class="w-10 h-10 rounded-xl bg-teal-500/20 flex items-center justify-center mx-auto mb-3">
                        <svg class="w-5 h-5 text-teal-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                    </div>
                    <p class="text-2xl sm:text-3xl font-bold text-white">{{ isset($stats['total_invoices']) && $stats['total_invoices'] > 0 ? number_format($stats['total_invoices']) : '50k+' }}</p>
                    <p class="text-xs text-gray-400 mt-1">Invoices Processed</p>
                </div>
                <div class="stat-glass rounded-2xl p-5 text-center transition-all duration-300">
                    <div class="w-10 h-10 rounded-xl bg-purple-500/20 flex items-center justify-center mx-auto mb-3">
                        <svg class="w-5 h-5 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    </div>
                    <p class="text-2xl sm:text-3xl font-bold text-white">{{ isset($stats['total_companies']) && $stats['total_companies'] > 0 ? number_format($stats['total_companies']) . '+' : '500+' }}</p>
                    <p class="text-xs text-gray-400 mt-1">Companies Trust Us</p>
                </div>
                <div class="stat-glass rounded-2xl p-5 text-center transition-all duration-300">
                    <div class="w-10 h-10 rounded-xl bg-blue-500/20 flex items-center justify-center mx-auto mb-3">
                        <svg class="w-5 h-5 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                    </div>
                    <p class="text-2xl sm:text-3xl font-bold text-white">3</p>
                    <p class="text-xs text-gray-400 mt-1">Integrated Products</p>
                </div>
            </div>
        </div>
    </section>

    <div class="relative bg-gradient-to-r from-gray-50 via-white to-gray-50 border-b border-gray-100 py-5">
        <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-emerald-300/40 to-transparent"></div>
        <div class="max-w-[1200px] mx-auto px-3 sm:px-5 md:px-8">
            <div class="flex flex-wrap items-center justify-center gap-6 sm:gap-10">
                <div class="flex items-center space-x-2 text-gray-600">
                    <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    <span class="text-[13px] font-medium">FBR API v1.12</span>
                </div>
                <div class="flex items-center space-x-2 text-gray-600">
                    <svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span class="text-[13px] font-medium">PRA IMS v1.2</span>
                </div>
                <div class="flex items-center space-x-2 text-gray-600">
                    <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    <span class="text-[13px] font-medium">SHA-256 Encrypted</span>
                </div>
                <div class="flex items-center space-x-2 text-gray-600">
                    <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    <span class="text-[13px] font-medium">Real-time Sync</span>
                </div>
                <div class="flex items-center space-x-2 text-gray-600">
                    <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    <span class="text-[13px] font-medium">PWA Ready</span>
                </div>
            </div>
        </div>
    </div>

    <section class="py-20 lg:py-24 bg-white dark:bg-gray-900">
        <div class="max-w-[1200px] mx-auto px-3 sm:px-5 md:px-8">
            <div class="text-center mb-14 fade-up">
                <p class="text-[13px] font-semibold text-emerald-600 uppercase tracking-widest mb-3">Get Started in Minutes</p>
                <h2 class="text-[28px] sm:text-[32px] font-bold text-gray-900 tracking-tight">How It Works</h2>
                <p class="mt-4 text-[17px] text-gray-500 max-w-xl mx-auto leading-relaxed">Three simple steps to complete tax compliance</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-4xl mx-auto fade-up">
                <div class="text-center step-connector">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-100 to-teal-50 flex items-center justify-center mx-auto mb-5 shadow-lg shadow-emerald-500/10">
                        <span class="text-2xl font-black text-emerald-600">1</span>
                    </div>
                    <h3 class="text-[16px] font-bold text-gray-900 mb-2">Register & Choose Product</h3>
                    <p class="text-[13px] text-gray-500 leading-relaxed">Sign up in 30 seconds. Pick Digital Invoice, PRA POS, or FBR POS. Get a 14-day free trial instantly.</p>
                </div>
                <div class="text-center step-connector">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-purple-100 to-violet-50 flex items-center justify-center mx-auto mb-5 shadow-lg shadow-purple-500/10">
                        <span class="text-2xl font-black text-purple-600">2</span>
                    </div>
                    <h3 class="text-[16px] font-bold text-gray-900 mb-2">Configure & Connect FBR/PRA</h3>
                    <p class="text-[13px] text-gray-500 leading-relaxed">Enter your NTN, connect to FBR or PRA API, set up your business profile. Takes under 5 minutes.</p>
                </div>
                <div class="text-center">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-100 to-sky-50 flex items-center justify-center mx-auto mb-5 shadow-lg shadow-blue-500/10">
                        <span class="text-2xl font-black text-blue-600">3</span>
                    </div>
                    <h3 class="text-[16px] font-bold text-gray-900 mb-2">Create & Submit Invoices</h3>
                    <p class="text-[13px] text-gray-500 leading-relaxed">Start creating compliant invoices immediately. Real-time submission to FBR/PRA with instant confirmation.</p>
                </div>
            </div>
        </div>
    </section>

    <section id="products" class="py-24 lg:py-28 bg-gray-50 dark:bg-gray-800">
        <div class="max-w-[1200px] mx-auto px-3 sm:px-5 md:px-8">
            <div class="text-center mb-16 fade-up">
                <p class="text-[13px] font-semibold text-emerald-600 uppercase tracking-widest mb-3">Three Products, One Platform</p>
                <h2 class="text-[28px] sm:text-[32px] font-bold text-gray-900 tracking-tight">Choose Your Solution</h2>
                <p class="mt-4 text-[17px] text-gray-500 max-w-xl mx-auto leading-relaxed">All three products are 100% isolated — separate data, separate logins, separate dashboards.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 max-w-6xl mx-auto">
                <div class="relative card-hover bg-white dark:bg-gray-900 rounded-2xl product-card-glow-emerald overflow-hidden hover:-translate-y-2 hover:shadow-xl transition-all duration-300 gradient-border-top">
                    <div class="absolute top-3 right-3">
                        <span class="inline-flex items-center px-2.5 py-1 bg-emerald-100 text-emerald-700 text-[10px] font-bold rounded-full uppercase tracking-wide">Enterprise</span>
                    </div>
                    <div class="p-7">
                        <div class="flex items-center space-x-3 mb-5">
                            <div class="w-14 h-14 rounded-[14px] bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/20">
                                <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-gray-900">Digital Invoice</h3>
                                <p class="text-[12px] text-emerald-600 font-semibold">FBR Compliance System</p>
                            </div>
                        </div>
                        <p class="text-[14px] text-gray-600 leading-relaxed mb-5">Enterprise-grade FBR digital invoicing with PRAL API v1.12 integration, real-time submission, and compliance scoring.</p>
                        <div class="grid grid-cols-2 gap-2.5 mb-6">
                            @foreach(['FBR API v1.12', 'HS Intelligence AI', 'Risk Detection', 'PDF + QR Codes', 'MIS Analytics', 'Multi-Branch'] as $feature)
                            <div class="flex items-center text-[12px] text-gray-700">
                                <svg class="w-3.5 h-3.5 text-emerald-500 mr-1.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                {{ $feature }}
                            </div>
                            @endforeach
                        </div>
                        <a href="/digital-invoice" class="btn-glow block w-full py-3 bg-gradient-to-r from-emerald-500 to-emerald-700 text-white rounded-xl text-[13px] font-semibold hover:from-emerald-600 hover:to-emerald-800 shadow-md hover:shadow-lg text-center">
                            Explore Digital Invoice
                        </a>
                    </div>
                </div>

                <div class="relative card-hover bg-white dark:bg-gray-900 rounded-2xl product-card-glow-purple overflow-hidden hover:-translate-y-2 hover:shadow-xl transition-all duration-300 gradient-border-top-purple ring-2 ring-purple-200/50">
                    <div class="absolute top-3 right-3">
                        <span class="inline-flex items-center px-2.5 py-1 bg-gradient-to-r from-purple-500 to-violet-600 text-white text-[10px] font-bold rounded-full uppercase tracking-wide shadow-lg shadow-purple-500/20">Most Popular</span>
                    </div>
                    <div class="p-7">
                        <div class="flex items-center space-x-3 mb-5">
                            <div class="w-14 h-14 rounded-[14px] bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center shadow-lg shadow-purple-500/20">
                                <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-gray-900">PRA POS</h3>
                                <p class="text-[12px] text-purple-600 font-semibold">PRA Point of Sale</p>
                            </div>
                        </div>
                        <p class="text-[14px] text-gray-600 leading-relaxed mb-5">Complete POS billing with PRA fiscal device integration via PRAL IMS API v1.2, thermal receipts, and real-time tax calculations.</p>
                        <div class="grid grid-cols-2 gap-2.5 mb-6">
                            @foreach(['PRA IMS v1.2', 'Thermal Receipts', 'Multi-Terminal', 'Smart Billing', 'Cash/Card/QR Tax', 'Offline Billing'] as $feature)
                            <div class="flex items-center text-[12px] text-gray-700">
                                <svg class="w-3.5 h-3.5 text-purple-500 mr-1.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                {{ $feature }}
                            </div>
                            @endforeach
                        </div>
                        <a href="/pos" class="btn-glow-purple block w-full py-3 bg-gradient-to-r from-purple-500 to-purple-700 text-white rounded-xl text-[13px] font-semibold hover:from-purple-600 hover:to-purple-800 shadow-lg shadow-purple-500/20 hover:shadow-xl text-center">
                            Explore PRA POS
                        </a>
                    </div>
                </div>

                <div class="relative card-hover bg-white dark:bg-gray-900 rounded-2xl product-card-glow-blue overflow-hidden hover:-translate-y-2 hover:shadow-xl transition-all duration-300" style="border-top: 4px solid; border-image: linear-gradient(to right, #2563eb, #3b82f6) 1;">
                    <div class="absolute top-3 right-3">
                        <span class="inline-flex items-center px-2.5 py-1 bg-blue-100 text-blue-700 text-[10px] font-bold rounded-full uppercase tracking-wide">New</span>
                    </div>
                    <div class="p-7">
                        <div class="flex items-center space-x-3 mb-5">
                            <div class="w-14 h-14 rounded-[14px] bg-gradient-to-br from-blue-500 to-blue-700 flex items-center justify-center shadow-lg shadow-blue-500/20">
                                <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-gray-900">FBR POS</h3>
                                <p class="text-[12px] text-blue-600 font-semibold">FBR Point of Sale</p>
                            </div>
                        </div>
                        <p class="text-[14px] text-gray-600 leading-relaxed mb-5">FBR-integrated POS billing with direct API submission, real-time compliance, automated tax calculation, and comprehensive reports.</p>
                        <div class="grid grid-cols-2 gap-2.5 mb-6">
                            @foreach(['FBR Direct API', 'Smart Billing', 'Tax Compliance', 'Retry System', 'Tax Reports', 'Multi-User'] as $feature)
                            <div class="flex items-center text-[12px] text-gray-700">
                                <svg class="w-3.5 h-3.5 text-blue-500 mr-1.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                {{ $feature }}
                            </div>
                            @endforeach
                        </div>
                        <a href="/fbr-pos-landing" class="block w-full py-3 bg-gradient-to-r from-blue-500 to-blue-700 text-white rounded-xl text-[13px] font-semibold hover:from-blue-600 hover:to-blue-800 shadow-md hover:shadow-lg text-center transition">
                            Explore FBR POS
                        </a>
                    </div>
                </div>
            </div>

            <div class="mt-8 text-center">
                <div class="inline-flex items-center px-5 py-2.5 bg-amber-50 border border-amber-200/60 rounded-xl">
                    <svg class="w-4 h-4 text-amber-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    <span class="text-[13px] font-medium text-amber-800">100% isolated — separate databases, logins, and data. No cross-contamination.</span>
                </div>
            </div>
        </div>
    </section>


    <section id="features" class="py-24 lg:py-28 bg-gray-50">
        <div class="max-w-[1200px] mx-auto px-3 sm:px-5 md:px-8">
            <div class="text-center mb-16 fade-up">
                <p class="text-[13px] font-semibold text-emerald-600 uppercase tracking-widest mb-3">Enterprise Architecture</p>
                <h2 class="text-[28px] sm:text-[32px] font-bold text-gray-900 tracking-tight">Built Different From Day One</h2>
                <p class="mt-4 text-[17px] text-gray-500 max-w-xl mx-auto leading-relaxed">Purpose-built for Pakistan's tax ecosystem with enterprise-grade infrastructure.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 fade-up">
                @php
                $features = [
                    ['Real-time FBR + PRA Sync', 'Direct PRAL API v1.12 for FBR Digital Invoice, IMS API v1.2 for PRA POS, and FBR Direct API for FBR POS. Synchronous submission with instant responses.', 'M13 10V3L4 14h7v7l9-11h-7z', 'emerald'],
                    ['HS Intelligence Engine', '6-factor AI model learns from every submission. Auto-suggests HS codes, SRO numbers, and tax rates.', 'M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z', 'blue'],
                    ['Risk Intelligence Engine', 'Pre-submission risk detection with anomaly scoring, compliance warnings, and automatic blocking.', 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z', 'purple'],
                    ['6-Phase Idempotency Shield', 'Enterprise-grade duplicate prevention. Per-invoice guards with SHA-256 hashing and auto-recovery.', 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z', 'orange'],
                    ['Immutable Audit Logs', 'SHA-256 signed audit trail with integrity verification and tamper-proof activity tracking.', 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z', 'indigo'],
                    ['Multi-Tenant Architecture', 'Complete company isolation with approval workflow, multi-branch invoicing, and role-based access.', 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4', 'cyan'],
                    ['Token Health Monitor', 'Real-time FBR/PRA token status, connectivity checks, expiry alerts, and health diagnostics.', 'M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z', 'rose'],
                    ['6 Login Methods', 'Email, Phone, Username, CNIC, NTN, or FBR Registration. Maximum flexibility for all business types.', 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z', 'amber'],
                    ['PWA + Keyboard Shortcuts', 'Install on any device. Ctrl+S save, Ctrl+Enter submit, offline drafts, and instant transitions.', 'M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z', 'teal'],
                ];
                @endphp
                @foreach($features as $f)
                <div class="group card-hover bg-white dark:bg-gray-900 rounded-xl shadow-md p-6 ring-1 ring-gray-100 hover:-translate-y-1 hover:shadow-xl transition-all duration-300">
                    <div class="w-12 h-12 bg-{{ $f[3] }}-50 group-hover:bg-gradient-to-br group-hover:from-{{ $f[3] }}-50 group-hover:to-{{ $f[3] }}-100 rounded-xl flex items-center justify-center mb-4 shadow-sm transition-all duration-300">
                        <svg class="w-6 h-6 text-{{ $f[3] }}-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $f[2] }}"/></svg>
                    </div>
                    <h3 class="text-[15px] font-bold text-gray-900 mb-2">{{ $f[0] }}</h3>
                    <p class="text-[13px] text-gray-600 leading-relaxed">{{ $f[1] }}</p>
                </div>
                @endforeach
            </div>

            <div class="mt-14 fade-up">
                <div class="relative bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 rounded-2xl shadow-xl overflow-hidden max-w-4xl mx-auto">
                    <div class="absolute inset-0 opacity-[0.04]" style="background-image: url('data:image/svg+xml,%3Csvg width=&quot;40&quot; height=&quot;40&quot; viewBox=&quot;0 0 40 40&quot; xmlns=&quot;http://www.w3.org/2000/svg&quot;%3E%3Cg fill=&quot;%23ffffff&quot; fill-opacity=&quot;1&quot; fill-rule=&quot;evenodd&quot;%3E%3Cpath d=&quot;M0 40L40 0H20L0 20M40 40V20L20 40&quot;/%3E%3C/g%3E%3C/svg%3E');"></div>
                    <div class="absolute top-0 left-0 right-0 h-[2px] bg-gradient-to-r from-emerald-500 via-teal-400 to-purple-500"></div>
                    <div class="relative px-6 py-6 sm:px-8 sm:py-7">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/20 flex-shrink-0">
                                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                                </div>
                                <h3 class="text-[15px] font-bold text-white tracking-tight">Platform Capabilities</h3>
                            </div>
                            <span class="inline-flex items-center px-2.5 py-1 bg-emerald-500/10 border border-emerald-500/20 rounded-full text-[11px] font-semibold text-emerald-400 tracking-wide">BUILT FOR ENTERPRISE</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-2.5">
                            @foreach([
                                'Real-time FBR PRAL API',
                                'PRA + FBR POS fiscal reporting',
                                'Offline billing + auto sync',
                                'Inventory + reporting engine',
                                'Compliance scoring + risk alerts',
                                'SHA-256 immutable audit logs',
                                'Multi-branch invoicing',
                                'Thermal receipt printing'
                            ] as $cap)
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-emerald-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                <span class="text-[13px] text-gray-300 font-medium">{{ $cap }}</span>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-24 lg:py-28 bg-white dark:bg-gray-900">
        <div class="max-w-[1200px] mx-auto px-3 sm:px-5 md:px-8">
            <div class="text-center mb-16 fade-up">
                <p class="text-[13px] font-semibold text-emerald-600 uppercase tracking-widest mb-3">Comparison</p>
                <h2 class="text-[28px] sm:text-[32px] font-bold text-gray-900 tracking-tight">TaxNest vs Traditional Software</h2>
                <p class="mt-4 text-[17px] text-gray-500 max-w-xl mx-auto">See how TaxNest compares to generic accounting tools</p>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-md border border-gray-200 overflow-hidden max-w-4xl mx-auto fade-up">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-gradient-to-r from-gray-900 via-gray-800 to-gray-900">
                                <th class="text-left py-4 px-6 text-[13px] font-medium text-gray-300">Capability</th>
                                <th class="text-center py-4 px-6 text-[13px] font-bold text-emerald-400">TaxNest</th>
                                <th class="text-center py-4 px-6 text-[13px] font-bold text-gray-400">Others</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @php
                            $comparisons = [
                                ['Direct FBR PRAL API v1.12', true, false],
                                ['PRA IMS API v1.2 (POS)', true, false],
                                ['Synchronous Real-time Submission', true, false],
                                ['HS Intelligence Engine (AI)', true, false],
                                ['Risk Detection & Compliance Scoring', true, false],
                                ['6-Phase Idempotency Shield', true, false],
                                ['Auto-Recovery for Stuck Invoices', true, false],
                                ['3rd Schedule Goods Support', true, false],
                                ['6 Login Methods (Email/CNIC/NTN)', true, false],
                                ['SHA-256 Immutable Audit Logs', true, false],
                                ['Multi-Branch + Company Isolation', true, 'partial'],
                                ['FBR + PRA Token Health Monitor', true, false],
                                ['PWA + Keyboard Shortcuts', true, false],
                                ['FBR POS Direct API Submission', true, false],
                                ['Separate DI + PRA POS + FBR POS Isolation', true, false],
                            ];
                            @endphp
                            @foreach($comparisons as $comp)
                            <tr class="hover:bg-gray-50/50 transition">
                                <td class="py-3.5 px-6 text-[13px] font-medium text-gray-700">{{ $comp[0] }}</td>
                                <td class="py-3.5 px-6 text-center">
                                    @if($comp[1] === true)
                                    <svg class="w-5 h-5 text-emerald-500 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    @endif
                                </td>
                                <td class="py-3.5 px-6 text-center">
                                    @if($comp[2] === true)
                                    <svg class="w-5 h-5 text-emerald-500 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    @elseif($comp[2] === 'partial')
                                    <span class="text-[11px] font-medium text-amber-600 bg-amber-50 px-2 py-0.5 rounded-full">Limited</span>
                                    @else
                                    <svg class="w-4 h-4 text-gray-300 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
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

    <section id="pricing" class="py-24 lg:py-28 bg-gray-50">
        <div class="max-w-[1200px] mx-auto px-3 sm:px-5 md:px-8">
            <div class="text-center mb-16 fade-up">
                <p class="text-[13px] font-semibold text-emerald-600 uppercase tracking-widest mb-3">Pricing</p>
                <h2 class="text-[28px] sm:text-[32px] font-bold text-gray-900 tracking-tight">Simple, Transparent Pricing</h2>
                <p class="mt-4 text-[17px] text-gray-500 max-w-xl mx-auto leading-relaxed">Each product has its own plans. Visit the product page for details and start your 14-day free trial.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 max-w-5xl mx-auto fade-up">
                <div class="relative card-hover bg-white dark:bg-gray-900 rounded-xl shadow-md ring-1 ring-gray-200/50 p-8 text-center hover:-translate-y-1 hover:shadow-xl transition-all duration-300">
                    <div class="absolute inset-0 rounded-xl bg-gradient-to-br from-emerald-500/5 to-teal-500/5 pointer-events-none"></div>
                    <div class="relative">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-50 to-teal-50 flex items-center justify-center mx-auto mb-5 shadow-sm">
                            <svg class="w-7 h-7 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 mb-2">Digital Invoice</h3>
                        <p class="text-[14px] text-gray-500 mb-6 leading-relaxed">FBR-compliant invoicing with multiple billing cycles and volume discounts</p>
                        <a href="/digital-invoice#pricing" class="btn-glow inline-flex items-center px-6 py-3 bg-emerald-600 text-white rounded-[10px] text-[14px] font-semibold hover:bg-emerald-700 transition">
                            View DI Plans
                            <svg class="w-4 h-4 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    </div>
                </div>
                <div class="relative card-hover bg-white dark:bg-gray-900 rounded-xl shadow-md ring-1 ring-gray-200/50 p-8 text-center hover:-translate-y-1 hover:shadow-xl transition-all duration-300">
                    <div class="absolute inset-0 rounded-xl bg-gradient-to-br from-purple-500/5 to-violet-500/5 pointer-events-none"></div>
                    <div class="relative">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-purple-50 to-violet-50 flex items-center justify-center mx-auto mb-5 shadow-sm">
                            <svg class="w-7 h-7 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 mb-2">NestPOS</h3>
                        <p class="text-[14px] text-gray-500 mb-6 leading-relaxed">PRA point of sale with annual billing and built-in 6% discount</p>
                        <a href="/pos#pricing" class="btn-glow-purple inline-flex items-center px-6 py-3 bg-purple-600 text-white rounded-[10px] text-[14px] font-semibold hover:bg-purple-700">
                            View POS Plans
                            <svg class="w-4 h-4 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    </div>
                </div>
                <div class="relative card-hover bg-white dark:bg-gray-900 rounded-xl shadow-md ring-1 ring-gray-200/50 p-8 text-center hover:-translate-y-1 hover:shadow-xl transition-all duration-300">
                    <div class="absolute inset-0 rounded-xl bg-gradient-to-br from-blue-500/5 to-sky-500/5 pointer-events-none"></div>
                    <div class="relative">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-blue-50 to-sky-50 flex items-center justify-center mx-auto mb-5 shadow-sm">
                            <svg class="w-7 h-7 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 mb-2">FBR POS</h3>
                        <p class="text-[14px] text-gray-500 mb-6 leading-relaxed">FBR-integrated POS with direct API submission and low-budget billing cycles</p>
                        <a href="/fbr-pos-landing#pricing" class="inline-flex items-center px-6 py-3 bg-blue-600 text-white rounded-[10px] text-[14px] font-semibold hover:bg-blue-700 transition">
                            View FBR POS Plans
                            <svg class="w-4 h-4 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="faq" class="py-24 lg:py-28 bg-white dark:bg-gray-900">
        <div class="max-w-3xl mx-auto px-5 sm:px-8">
            <div class="text-center mb-12 fade-up">
                <p class="text-[13px] font-semibold text-emerald-600 uppercase tracking-widest mb-3">FAQ</p>
                <h2 class="text-[28px] sm:text-[32px] font-bold text-gray-900 tracking-tight">Frequently Asked Questions</h2>
            </div>
            <div class="space-y-3 fade-up" x-data="{ open: null }">
                @php
                $faqs = [
                    ['What is TaxNest?', 'TaxNest is Pakistan\'s most advanced tax compliance platform with three products: <strong>Digital Invoice</strong> for FBR compliance (Federal Board of Revenue), <strong>NestPOS</strong> for PRA compliance (Punjab Revenue Authority), and <strong>FBR POS</strong> for FBR-integrated point of sale billing. All three products are completely isolated with separate databases, logins, and dashboards.'],
                    ['What is the difference between Digital Invoice, NestPOS, and FBR POS?', '<strong>Digital Invoice</strong> is for businesses that need to submit invoices to FBR via PRAL API v1.12. It includes HS Intelligence, compliance scoring, risk detection, and enterprise analytics.<br><br><strong>NestPOS</strong> is a Point of Sale system for retail/service businesses that need PRA fiscal device integration via PRAL IMS API v1.2. It includes thermal receipt printing, multi-terminal support, and real-time tax calculations.<br><br><strong>FBR POS</strong> is a Point of Sale system with direct FBR API submission, designed for businesses that need FBR-compliant POS billing with automated tax calculation and retry system.'],
                    ['Are all three products data separate?', 'Yes, 100%. Digital Invoice, NestPOS, and FBR POS each have separate databases, separate login pages, separate dashboards, and separate user accounts. There is zero cross-contamination of data between any of the three systems.'],
                    ['Is there a free trial?', 'Yes! All three products come with a 14-day free trial. No credit card required. You get full access to all features during the trial period with up to 20 invoices/transactions.'],
                    ['How does FBR/PRA compliance work?', '<strong>FBR (Digital Invoice):</strong> Uses PRAL API v1.12 for real-time synchronous invoice submission. Invoices are validated, scored for compliance, and submitted with HS codes, tax rates, and QR codes.<br><br><strong>NestPOS:</strong> Uses PRAL IMS API v1.2 for fiscal device integration. Each transaction is fiscalized and assigned a PRA fiscal invoice number with QR code.<br><br><strong>FBR POS:</strong> Uses direct FBR API for real-time POS invoice submission with automated tax compliance, retry system, and comprehensive tax reports.'],
                    ['What security measures are in place?', 'TaxNest uses SHA-256 encrypted immutable audit logs, role-based access control, company isolation middleware, 6-phase idempotency shield for duplicate prevention, and HTTPS encryption. All critical operations are logged with tamper-proof hashing.'],
                ];
                @endphp
                @foreach($faqs as $i => $faq)
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200/80 overflow-hidden transition-shadow duration-300" :class="open === {{ $i+1 }} ? 'shadow-lg ring-1 ring-emerald-100' : 'shadow-sm'">
                    <button @click="open = open === {{ $i+1 }} ? null : {{ $i+1 }}" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="text-[14px] font-semibold text-gray-900 pr-4">{{ $faq[0] }}</span>
                        <svg class="w-4 h-4 text-gray-400 transition-transform flex-shrink-0" :class="open === {{ $i+1 }} ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === {{ $i+1 }}" x-collapse class="px-5 pb-5 text-[13px] text-gray-500 leading-relaxed">
                        {!! $faq[1] !!}
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    <section id="contact" class="py-24 lg:py-28 bg-gray-50">
        <div class="max-w-3xl mx-auto px-5 sm:px-8">
            <div class="text-center mb-12 fade-up">
                <p class="text-[13px] font-semibold text-emerald-600 uppercase tracking-widest mb-3">Get in Touch</p>
                <h2 class="text-[28px] sm:text-[32px] font-bold text-gray-900 tracking-tight">Contact Us</h2>
                <p class="mt-4 text-[17px] text-gray-500">Have questions? We're here to help you choose the right solution.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5 fade-up">
                <div class="card-hover bg-white dark:bg-gray-900 rounded-xl shadow-md ring-1 ring-gray-200/50 p-6 text-center hover:-translate-y-1 hover:shadow-xl transition-all duration-300">
                    <div class="w-11 h-11 bg-gradient-to-br from-emerald-400/20 to-teal-400/20 rounded-xl flex items-center justify-center mx-auto mb-4">
                        <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </div>
                    <h3 class="text-[14px] font-semibold text-gray-900 mb-1">Email</h3>
                    <p class="text-[13px] text-gray-500">support@taxnest.com</p>
                </div>
                <div class="card-hover bg-white dark:bg-gray-900 rounded-xl shadow-md ring-1 ring-gray-200/50 p-6 text-center hover:-translate-y-1 hover:shadow-xl transition-all duration-300">
                    <div class="w-11 h-11 bg-gradient-to-br from-blue-400/20 to-indigo-400/20 rounded-xl flex items-center justify-center mx-auto mb-4">
                        <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                    </div>
                    <h3 class="text-[14px] font-semibold text-gray-900 mb-1">Phone</h3>
                    <p class="text-[13px] text-gray-500">+92-XXX-XXXXXXX</p>
                </div>
                <div class="card-hover bg-white dark:bg-gray-900 rounded-xl shadow-md ring-1 ring-gray-200/50 p-6 text-center hover:-translate-y-1 hover:shadow-xl transition-all duration-300">
                    <div class="w-11 h-11 bg-gradient-to-br from-purple-400/20 to-violet-400/20 rounded-xl flex items-center justify-center mx-auto mb-4">
                        <svg class="w-5 h-5 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </div>
                    <h3 class="text-[14px] font-semibold text-gray-900 mb-1">Location</h3>
                    <p class="text-[13px] text-gray-500">Pakistan</p>
                </div>
            </div>
        </div>
    </section>

    <section class="py-20 bg-[#0a0f1a] relative overflow-hidden">
        <div class="hero-glow absolute inset-0 opacity-60"></div>
        <div class="orb-1 absolute top-0 left-[20%] w-48 h-48 bg-emerald-500/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="orb-2 absolute bottom-0 right-[20%] w-56 h-56 bg-purple-500/8 rounded-full blur-3xl pointer-events-none"></div>
        <div class="max-w-3xl mx-auto px-5 sm:px-8 text-center relative">
            <h2 class="text-[28px] sm:text-[32px] font-bold text-white tracking-tight mb-4">Ready to Get Compliant?</h2>
            <p class="text-gray-400 mb-8 text-[17px]">Choose a product to explore features, pricing, and get started.</p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                <a href="/digital-invoice" class="btn-glow px-6 py-3 bg-gradient-to-r from-emerald-400 to-teal-500 text-white rounded-[10px] text-[14px] font-semibold hover:from-emerald-500 hover:to-teal-600 w-full sm:w-auto flex items-center justify-center gap-2 shadow-lg shadow-emerald-500/25">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                    Digital Invoice
                </a>
                <a href="/pos" class="btn-glow-purple px-6 py-3 bg-gradient-to-r from-purple-500 to-violet-600 text-white border border-purple-400/20 rounded-[10px] text-[14px] font-semibold hover:from-purple-600 hover:to-violet-700 transition w-full sm:w-auto flex items-center justify-center gap-2 shadow-lg shadow-purple-500/20">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    PRA POS
                </a>
                <a href="/fbr-pos-landing" class="px-6 py-3 bg-gradient-to-r from-blue-500 to-blue-600 text-white border border-blue-400/20 rounded-[10px] text-[14px] font-semibold hover:from-blue-600 hover:to-blue-700 transition w-full sm:w-auto flex items-center justify-center gap-2 shadow-lg shadow-blue-500/20">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    FBR POS
                </a>
            </div>
        </div>
    </section>

    <footer class="bg-[#0a0f1a] pt-14 pb-8 relative">
        <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-emerald-500/30 to-transparent"></div>
        <div class="max-w-[1200px] mx-auto px-3 sm:px-5 md:px-8">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-8 pb-10 border-b border-white/[0.06]">
                <div>
                    <div class="flex items-center space-x-2 mb-4">
                        <div class="w-7 h-7 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/20">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        </div>
                        <span class="text-[14px] font-bold text-white">TaxNest</span>
                    </div>
                    <p class="text-[12px] text-gray-500 leading-relaxed">Pakistan's most advanced tax compliance platform.</p>
                </div>
                <div>
                    <h4 class="text-[11px] font-semibold text-gray-400 uppercase tracking-widest mb-4">Products</h4>
                    <div class="space-y-2.5">
                        <a href="/digital-invoice" class="block text-[13px] text-gray-500 hover:text-emerald-400 transition">Digital Invoice</a>
                        <a href="/pos" class="block text-[13px] text-gray-500 hover:text-purple-400 transition">PRA POS</a>
                        <a href="/fbr-pos-landing" class="block text-[13px] text-gray-500 hover:text-blue-400 transition">FBR POS</a>
                    </div>
                </div>
                <div>
                    <h4 class="text-[11px] font-semibold text-gray-400 uppercase tracking-widest mb-4">Resources</h4>
                    <div class="space-y-2.5">
                        <a href="#features" class="block text-[13px] text-gray-500 hover:text-white transition">Documentation</a>
                        <a href="#features" class="block text-[13px] text-gray-500 hover:text-white transition">API Guide</a>
                    </div>
                </div>
                <div>
                    <h4 class="text-[11px] font-semibold text-gray-400 uppercase tracking-widest mb-4">Company</h4>
                    <div class="space-y-2.5">
                        <a href="#faq" class="block text-[13px] text-gray-500 hover:text-white transition">About</a>
                        <a href="#contact" class="block text-[13px] text-gray-500 hover:text-white transition">Contact</a>
                    </div>
                </div>
            </div>
            <div class="pt-6 flex flex-col sm:flex-row items-center justify-between gap-3">
                <p class="text-[12px] text-gray-600">&copy; {{ date('Y') }} TaxNest. All rights reserved.</p>
                <div class="flex items-center space-x-5">
                    <span class="flex items-center text-[11px] text-gray-600"><svg class="w-3 h-3 mr-1 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/></svg>FBR API v1.12</span>
                    <span class="flex items-center text-[11px] text-gray-600"><svg class="w-3 h-3 mr-1 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/></svg>PRA IMS v1.2</span>
                    <span class="flex items-center text-[11px] text-gray-600"><svg class="w-3 h-3 mr-1 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/></svg>SHA-256</span>
                </div>
            </div>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                    }
                });
            }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

            document.querySelectorAll('.fade-up').forEach(function(el) {
                observer.observe(el);
            });
        });
    </script>

</body>
</html>