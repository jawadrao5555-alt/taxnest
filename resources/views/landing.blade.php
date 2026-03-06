<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <title>TaxNest — Pakistan's Most Advanced Tax Compliance Platform</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: 'Figtree', sans-serif; }
        .glass-card { background: rgba(255,255,255,0.15); backdrop-filter: blur(16px) saturate(180%); border: 1px solid rgba(255,255,255,0.25); transition: all 0.3s ease; }
        .glass-card:hover { background: rgba(255,255,255,0.25); transform: translateY(-2px); }
        .hero-gradient { background: linear-gradient(135deg, #0f172a 0%, #1e293b 30%, #064e3b 60%, #059669 100%); }
        [x-cloak] { display: none !important; }
        .scroll-smooth { scroll-behavior: smooth; }
    </style>
</head>
<body class="antialiased text-gray-800 scroll-smooth">

    <nav class="fixed top-0 w-full z-50 bg-white/80 backdrop-blur-2xl border-b border-gray-200/50 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between h-16">
            <div class="flex items-center space-x-2">
                <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </div>
                <span class="text-xl font-bold text-gray-900">TaxNest</span>
            </div>
            <div class="hidden md:flex items-center space-x-6">
                <a href="#products" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition">Products</a>
                <a href="#features" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition">Features</a>
                <a href="#faq" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition">FAQ</a>
                <div class="flex items-center space-x-2 ml-2">
                    <a href="/login" class="inline-flex items-center px-4 py-2 border-2 border-emerald-600 text-emerald-700 rounded-lg text-sm font-semibold hover:bg-emerald-50 transition">DI Login</a>
                    <a href="/pos/login" class="inline-flex items-center px-4 py-2 border-2 border-purple-600 text-purple-700 rounded-lg text-sm font-semibold hover:bg-purple-50 transition">POS Login</a>
                    <a href="/register" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-semibold hover:bg-emerald-700 transition shadow-sm">Sign Up Free</a>
                </div>
            </div>
            <div class="md:hidden flex items-center space-x-2">
                <a href="/login" class="text-xs font-semibold text-emerald-700 border border-emerald-600 px-2.5 py-1.5 rounded-lg">DI</a>
                <a href="/pos/login" class="text-xs font-semibold text-purple-700 border border-purple-600 px-2.5 py-1.5 rounded-lg">POS</a>
                <a href="/register" class="text-xs font-semibold text-white bg-emerald-600 px-2.5 py-1.5 rounded-lg">Sign Up</a>
            </div>
        </div>
    </nav>

    <section class="hero-gradient pt-28 pb-20 relative overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-20 left-10 w-72 h-72 bg-emerald-400 rounded-full blur-3xl"></div>
            <div class="absolute bottom-10 right-20 w-96 h-96 bg-purple-400 rounded-full blur-3xl"></div>
            <div class="absolute top-40 right-40 w-48 h-48 bg-teal-300 rounded-full blur-3xl"></div>
        </div>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="text-center max-w-4xl mx-auto">
                <div class="inline-flex items-center px-4 py-1.5 bg-white/10 backdrop-blur-sm rounded-full text-sm font-medium text-white mb-6 border border-white/20">
                    <svg class="w-4 h-4 mr-2 text-emerald-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    FBR + PRA Compliant &bull; Enterprise Grade &bull; 14-Day Free Trial
                </div>
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-white leading-tight mb-6">
                    Pakistan's Most Advanced<br>
                    <span class="bg-clip-text text-transparent bg-gradient-to-r from-emerald-400 to-teal-300">Tax Compliance Platform</span>
                </h1>
                <p class="text-xl text-gray-300 mb-4 max-w-3xl mx-auto leading-relaxed">
                    Two powerful products. One platform. Complete FBR Digital Invoicing + PRA Point of Sale — fully isolated, enterprise-grade, built for Pakistan.
                </p>
                <p class="text-lg text-emerald-300 mb-10 font-semibold">
                    14-Day Free Trial &bull; No Credit Card Required
                </p>
                <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="/register" class="inline-flex items-center px-8 py-4 bg-emerald-500 text-white rounded-xl text-lg font-bold hover:bg-emerald-600 transition shadow-lg shadow-emerald-900/30 w-full sm:w-auto justify-center">
                        Start 14-Day Free Trial
                        <svg class="w-5 h-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                    </a>
                    <a href="#products" class="inline-flex items-center px-8 py-4 bg-transparent text-white rounded-xl text-lg font-bold hover:bg-white/10 transition border-2 border-white/30 w-full sm:w-auto justify-center">
                        Explore Products
                    </a>
                </div>
            </div>

            <div class="mt-16 grid grid-cols-2 sm:grid-cols-4 gap-4 max-w-4xl mx-auto">
                <div class="glass-card rounded-xl p-4 text-center">
                    <p class="text-3xl font-extrabold text-white">99.9%</p>
                    <p class="text-sm text-gray-300 mt-1">Uptime SLA</p>
                </div>
                <div class="glass-card rounded-xl p-4 text-center">
                    <p class="text-3xl font-extrabold text-white">50k+</p>
                    <p class="text-sm text-gray-300 mt-1">Invoices Processed</p>
                </div>
                <div class="glass-card rounded-xl p-4 text-center">
                    <p class="text-3xl font-extrabold text-white">500+</p>
                    <p class="text-sm text-gray-300 mt-1">Companies Trust Us</p>
                </div>
                <div class="glass-card rounded-xl p-4 text-center">
                    <p class="text-3xl font-extrabold text-white">2</p>
                    <p class="text-sm text-gray-300 mt-1">Integrated Products</p>
                </div>
            </div>
        </div>
    </section>

    <div class="bg-white border-b border-gray-100 py-5">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-center gap-6 sm:gap-10">
                <div class="flex items-center space-x-2 text-gray-500">
                    <svg class="w-5 h-5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    <span class="text-sm font-medium">FBR API v1.12</span>
                </div>
                <div class="flex items-center space-x-2 text-gray-500">
                    <svg class="w-5 h-5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span class="text-sm font-medium">PRA IMS v1.2</span>
                </div>
                <div class="flex items-center space-x-2 text-gray-500">
                    <svg class="w-5 h-5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    <span class="text-sm font-medium">SHA-256 Encrypted</span>
                </div>
                <div class="flex items-center space-x-2 text-gray-500">
                    <svg class="w-5 h-5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    <span class="text-sm font-medium">Real-time Sync</span>
                </div>
                <div class="flex items-center space-x-2 text-gray-500">
                    <svg class="w-5 h-5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    <span class="text-sm font-medium">PWA Ready</span>
                </div>
            </div>
        </div>
    </div>

    <section id="products" class="py-20 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <p class="text-sm font-semibold text-emerald-600 uppercase tracking-wider mb-2">Two Products, One Platform</p>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900">Choose Your Solution</h2>
                <p class="mt-4 text-lg text-gray-500 max-w-2xl mx-auto">Both products are 100% isolated — separate data, separate logins, separate dashboards. Use one or both.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <div class="bg-white rounded-2xl shadow-lg border-2 border-emerald-200 overflow-hidden hover:shadow-xl transition-shadow duration-300">
                    <div class="bg-gradient-to-r from-emerald-600 to-teal-600 p-6">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 rounded-xl bg-white/20 flex items-center justify-center">
                                    <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                                </div>
                                <div>
                                    <h3 class="text-2xl font-bold text-white">Digital Invoice</h3>
                                    <p class="text-emerald-100 text-sm">FBR Compliance System</p>
                                </div>
                            </div>
                            <span class="px-3 py-1 bg-white/20 rounded-full text-xs font-bold text-white">FBR</span>
                        </div>
                    </div>
                    <div class="p-6">
                        <p class="text-gray-600 mb-6">Enterprise-grade FBR digital invoicing with PRAL API v1.12 integration, real-time synchronous submission, and intelligent compliance scoring.</p>
                        <div class="grid grid-cols-2 gap-3 mb-6">
                            <div class="flex items-center text-sm text-gray-700">
                                <svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                FBR API v1.12
                            </div>
                            <div class="flex items-center text-sm text-gray-700">
                                <svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                HS Intelligence AI
                            </div>
                            <div class="flex items-center text-sm text-gray-700">
                                <svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Risk Detection Engine
                            </div>
                            <div class="flex items-center text-sm text-gray-700">
                                <svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Idempotency Shield
                            </div>
                            <div class="flex items-center text-sm text-gray-700">
                                <svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                PDF + QR Codes
                            </div>
                            <div class="flex items-center text-sm text-gray-700">
                                <svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                MIS Analytics
                            </div>
                            <div class="flex items-center text-sm text-gray-700">
                                <svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Customer Ledger
                            </div>
                            <div class="flex items-center text-sm text-gray-700">
                                <svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Multi-Branch
                            </div>
                        </div>
                        <div class="flex items-center justify-end pt-4 border-t border-gray-100">
                            <div class="flex space-x-2">
                                <a href="/login" class="px-4 py-2 border-2 border-emerald-600 text-emerald-700 rounded-lg text-sm font-semibold hover:bg-emerald-50 transition">Login</a>
                                <a href="/register" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-semibold hover:bg-emerald-700 transition shadow-sm">Sign Up</a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-lg border-2 border-purple-200 overflow-hidden hover:shadow-xl transition-shadow duration-300">
                    <div class="bg-gradient-to-r from-purple-600 to-violet-600 p-6">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 rounded-xl bg-white/20 flex items-center justify-center">
                                    <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                </div>
                                <div>
                                    <h3 class="text-2xl font-bold text-white">PRA POS</h3>
                                    <p class="text-purple-100 text-sm">Point of Sale System</p>
                                </div>
                            </div>
                            <span class="px-3 py-1 bg-white/20 rounded-full text-xs font-bold text-white">PRA</span>
                        </div>
                    </div>
                    <div class="p-6">
                        <p class="text-gray-600 mb-6">Complete POS billing system with PRA (Punjab Revenue Authority) fiscal device integration via PRAL IMS API v1.2, thermal receipts, and real-time tax calculations.</p>
                        <div class="grid grid-cols-2 gap-3 mb-6">
                            <div class="flex items-center text-sm text-gray-700">
                                <svg class="w-4 h-4 text-purple-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                PRA IMS v1.2
                            </div>
                            <div class="flex items-center text-sm text-gray-700">
                                <svg class="w-4 h-4 text-purple-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Thermal Receipts
                            </div>
                            <div class="flex items-center text-sm text-gray-700">
                                <svg class="w-4 h-4 text-purple-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Multi-Terminal
                            </div>
                            <div class="flex items-center text-sm text-gray-700">
                                <svg class="w-4 h-4 text-purple-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Smart Billing
                            </div>
                            <div class="flex items-center text-sm text-gray-700">
                                <svg class="w-4 h-4 text-purple-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Cash/Card/QR Tax
                            </div>
                            <div class="flex items-center text-sm text-gray-700">
                                <svg class="w-4 h-4 text-purple-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                POS Reports
                            </div>
                            <div class="flex items-center text-sm text-gray-700">
                                <svg class="w-4 h-4 text-purple-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Fiscal QR Codes
                            </div>
                            <div class="flex items-center text-sm text-gray-700">
                                <svg class="w-4 h-4 text-purple-500 mr-2 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Offline Billing
                            </div>
                        </div>
                        <div class="flex items-center justify-end pt-4 border-t border-gray-100">
                            <div class="flex space-x-2">
                                <a href="/pos/login" class="px-4 py-2 border-2 border-purple-600 text-purple-700 rounded-lg text-sm font-semibold hover:bg-purple-50 transition">POS Login</a>
                                <a href="/pos/register" class="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm font-semibold hover:bg-purple-700 transition shadow-sm">POS Sign Up</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-10 text-center">
                <div class="inline-flex items-center px-6 py-3 bg-amber-50 border border-amber-200 rounded-xl">
                    <svg class="w-5 h-5 text-amber-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    <span class="text-sm font-medium text-amber-800">Both products are 100% isolated — separate databases, separate logins, separate data. No cross-contamination.</span>
                </div>
            </div>
        </div>
    </section>

    <section id="features" class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <p class="text-sm font-semibold text-emerald-600 uppercase tracking-wider mb-2">Enterprise Architecture</p>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900">Built Different From Day One</h2>
                <p class="mt-4 text-lg text-gray-500 max-w-2xl mx-auto">No generic accounting software. TaxNest is purpose-built for Pakistan's tax ecosystem with enterprise-grade infrastructure.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100 hover:-translate-y-1 transition-all duration-300 group">
                    <div class="w-12 h-12 bg-emerald-100 rounded-xl flex items-center justify-center mb-4 group-hover:bg-emerald-200 transition">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Real-time FBR + PRA Sync</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">Direct PRAL API v1.12 for FBR and IMS API v1.2 for PRA. Synchronous submission with instant responses and invoice locking.</p>
                </div>

                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100 hover:-translate-y-1 transition-all duration-300 group">
                    <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center mb-4 group-hover:bg-blue-200 transition">
                        <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">HS Intelligence Engine</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">6-factor AI model learns from every submission. Auto-suggests HS codes, SRO numbers, and tax rates with confidence scoring.</p>
                </div>

                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100 hover:-translate-y-1 transition-all duration-300 group">
                    <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center mb-4 group-hover:bg-purple-200 transition">
                        <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Risk Intelligence Engine</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">Pre-submission risk detection with anomaly scoring, compliance warnings, and automatic blocking of problematic invoices.</p>
                </div>

                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100 hover:-translate-y-1 transition-all duration-300 group">
                    <div class="w-12 h-12 bg-orange-100 rounded-xl flex items-center justify-center mb-4 group-hover:bg-orange-200 transition">
                        <svg class="w-6 h-6 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">6-Phase Idempotency Shield</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">Enterprise-grade protection prevents duplicate submissions. Per-invoice guards with SHA-256 hashing and auto-recovery.</p>
                </div>

                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100 hover:-translate-y-1 transition-all duration-300 group">
                    <div class="w-12 h-12 bg-indigo-100 rounded-xl flex items-center justify-center mb-4 group-hover:bg-indigo-200 transition">
                        <svg class="w-6 h-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Immutable Audit Logs</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">SHA-256 signed audit trail with integrity verification, compliance certificates, and tamper-proof activity tracking.</p>
                </div>

                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100 hover:-translate-y-1 transition-all duration-300 group">
                    <div class="w-12 h-12 bg-cyan-100 rounded-xl flex items-center justify-center mb-4 group-hover:bg-cyan-200 transition">
                        <svg class="w-6 h-6 text-cyan-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Multi-Tenant Architecture</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">Complete company isolation with approval workflow, multi-branch invoicing, role-based access, and centralized admin oversight.</p>
                </div>

                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100 hover:-translate-y-1 transition-all duration-300 group">
                    <div class="w-12 h-12 bg-rose-100 rounded-xl flex items-center justify-center mb-4 group-hover:bg-rose-200 transition">
                        <svg class="w-6 h-6 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Token Health Monitor</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">Real-time FBR/PRA token status tracking, connectivity checks, expiry alerts, and automatic health diagnostics.</p>
                </div>

                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100 hover:-translate-y-1 transition-all duration-300 group">
                    <div class="w-12 h-12 bg-amber-100 rounded-xl flex items-center justify-center mb-4 group-hover:bg-amber-200 transition">
                        <svg class="w-6 h-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">6 Login Methods</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">Email, Phone, Username, CNIC, NTN, or FBR Registration number. Maximum flexibility for all business types in Pakistan.</p>
                </div>

                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100 hover:-translate-y-1 transition-all duration-300 group">
                    <div class="w-12 h-12 bg-teal-100 rounded-xl flex items-center justify-center mb-4 group-hover:bg-teal-200 transition">
                        <svg class="w-6 h-6 text-teal-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">PWA + Keyboard Shortcuts</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">Install on any device. Ctrl+S save, Ctrl+Enter submit, smart autofocus, offline drafts, and instant page transitions.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="py-20 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <p class="text-sm font-semibold text-emerald-600 uppercase tracking-wider mb-2">Comparison</p>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900">TaxNest vs Traditional Software</h2>
                <p class="mt-4 text-lg text-gray-500 max-w-2xl mx-auto">See how TaxNest compares to generic accounting tools</p>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden max-w-4xl mx-auto">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="text-left py-4 px-6 text-sm font-medium text-gray-500">Capability</th>
                                <th class="text-center py-4 px-6 text-sm font-bold text-emerald-700">TaxNest</th>
                                <th class="text-center py-4 px-6 text-sm font-bold text-gray-500">Others</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
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
                                ['Separate DI + POS Isolation', true, false],
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

    <section id="faq" class="py-20 bg-gray-50">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <p class="text-sm font-semibold text-emerald-600 uppercase tracking-wider mb-2">FAQ</p>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900">Frequently Asked Questions</h2>
            </div>
            <div class="space-y-4" x-data="{ open: null }">
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                    <button @click="open = open === 1 ? null : 1" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="text-base font-semibold text-gray-900">What is TaxNest?</span>
                        <svg class="w-5 h-5 text-gray-500 transition-transform" :class="open === 1 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 1" x-collapse class="px-6 pb-6 text-gray-600 text-sm leading-relaxed">
                        TaxNest is Pakistan's most advanced tax compliance platform with two products: <strong>Digital Invoice</strong> for FBR compliance (Federal Board of Revenue) and <strong>NestPOS</strong> for PRA compliance (Punjab Revenue Authority). Both products are completely isolated with separate databases, logins, and dashboards.
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                    <button @click="open = open === 2 ? null : 2" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="text-base font-semibold text-gray-900">What is the difference between Digital Invoice and NestPOS?</span>
                        <svg class="w-5 h-5 text-gray-500 transition-transform" :class="open === 2 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 2" x-collapse class="px-6 pb-6 text-gray-600 text-sm leading-relaxed">
                        <strong>Digital Invoice</strong> is for businesses that need to submit invoices to FBR (Federal Board of Revenue) via PRAL API v1.12. It includes HS Intelligence, compliance scoring, risk detection, and enterprise analytics.<br><br>
                        <strong>NestPOS</strong> is a Point of Sale system for retail/service businesses that need PRA (Punjab Revenue Authority) fiscal device integration via PRAL IMS API v1.2. It includes thermal receipt printing, multi-terminal support, and real-time tax calculations based on payment method.
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                    <button @click="open = open === 3 ? null : 3" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="text-base font-semibold text-gray-900">Are Digital Invoice and NestPOS data separate?</span>
                        <svg class="w-5 h-5 text-gray-500 transition-transform" :class="open === 3 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 3" x-collapse class="px-6 pb-6 text-gray-600 text-sm leading-relaxed">
                        Yes, 100%. Digital Invoice and NestPOS are completely isolated products. They have separate databases, separate login pages, separate dashboards, and separate user accounts. There is zero cross-contamination of data between the two systems.
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                    <button @click="open = open === 4 ? null : 4" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="text-base font-semibold text-gray-900">Is there a free trial?</span>
                        <svg class="w-5 h-5 text-gray-500 transition-transform" :class="open === 4 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 4" x-collapse class="px-6 pb-6 text-gray-600 text-sm leading-relaxed">
                        Yes! Both Digital Invoice and NestPOS come with a 14-day free trial. No credit card required. You get full access to all features during the trial period with up to 20 invoices/transactions.
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                    <button @click="open = open === 5 ? null : 5" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="text-base font-semibold text-gray-900">How does FBR/PRA compliance work?</span>
                        <svg class="w-5 h-5 text-gray-500 transition-transform" :class="open === 5 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 5" x-collapse class="px-6 pb-6 text-gray-600 text-sm leading-relaxed">
                        <strong>FBR (Digital Invoice):</strong> Uses PRAL API v1.12 for real-time synchronous invoice submission to FBR. Invoices are validated, scored for compliance, and submitted with HS codes, tax rates, and QR codes.<br><br>
                        <strong>NestPOS:</strong> Uses PRAL IMS API v1.2 for fiscal device integration. Each transaction is fiscalized and assigned a PRA fiscal invoice number with QR code for receipt printing.
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                    <button @click="open = open === 6 ? null : 6" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="text-base font-semibold text-gray-900">What security measures are in place?</span>
                        <svg class="w-5 h-5 text-gray-500 transition-transform" :class="open === 6 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 6" x-collapse class="px-6 pb-6 text-gray-600 text-sm leading-relaxed">
                        TaxNest uses SHA-256 encrypted immutable audit logs, role-based access control, company isolation middleware, 6-phase idempotency shield for duplicate prevention, and HTTPS encryption. All critical operations are logged with tamper-proof hashing.
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-16 bg-gradient-to-r from-emerald-600 via-teal-600 to-purple-600">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h2 class="text-3xl font-bold text-white mb-4">Ready to Get Compliant?</h2>
            <p class="text-white/80 mb-8 text-lg">Start your 14-day free trial. No credit card required. Choose the product that fits your business.</p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="/register" class="px-8 py-4 bg-white text-emerald-700 rounded-xl text-sm font-bold hover:bg-emerald-50 transition shadow-lg w-full sm:w-auto flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                    Start Digital Invoice
                </a>
                <a href="/pos/register" class="px-8 py-4 bg-white/20 text-white border-2 border-white/40 rounded-xl text-sm font-bold hover:bg-white/30 transition w-full sm:w-auto flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    Start NestPOS
                </a>
            </div>
        </div>
    </section>

    <footer class="bg-gray-900 py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <div>
                    <div class="flex items-center space-x-2 mb-4">
                        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        </div>
                        <span class="text-lg font-bold text-white">TaxNest</span>
                    </div>
                    <p class="text-sm text-gray-400 leading-relaxed">Pakistan's most advanced tax compliance platform. FBR + PRA integrated.</p>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-white uppercase tracking-wider mb-4">Digital Invoice</h4>
                    <div class="space-y-2">
                        <a href="/login" class="block text-sm text-gray-400 hover:text-emerald-400 transition">DI Login</a>
                        <a href="/register" class="block text-sm text-gray-400 hover:text-emerald-400 transition">DI Sign Up</a>
                        <a href="#features" class="block text-sm text-gray-400 hover:text-emerald-400 transition">Features</a>
                    </div>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-white uppercase tracking-wider mb-4">NestPOS</h4>
                    <div class="space-y-2">
                        <a href="/pos/login" class="block text-sm text-gray-400 hover:text-purple-400 transition">POS Login</a>
                        <a href="/pos/register" class="block text-sm text-gray-400 hover:text-purple-400 transition">POS Sign Up</a>
                        <a href="/pos" class="block text-sm text-gray-400 hover:text-purple-400 transition">POS Landing</a>
                    </div>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-white uppercase tracking-wider mb-4">Platform</h4>
                    <div class="space-y-2">
                        <a href="#features" class="block text-sm text-gray-400 hover:text-white transition">Features</a>
                        <a href="#faq" class="block text-sm text-gray-400 hover:text-white transition">FAQ</a>
                        <a href="/admin/login" class="block text-sm text-gray-400 hover:text-white transition">Admin Login</a>
                    </div>
                </div>
            </div>
            <div class="mt-8 pt-8 border-t border-gray-800 flex flex-col md:flex-row items-center justify-between">
                <p class="text-sm text-gray-500">&copy; {{ date('Y') }} TaxNest. All rights reserved.</p>
                <div class="flex items-center space-x-4 mt-4 md:mt-0">
                    <span class="text-xs text-gray-500 flex items-center"><svg class="w-3.5 h-3.5 mr-1 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/></svg>FBR API v1.12</span>
                    <span class="text-xs text-gray-500 flex items-center"><svg class="w-3.5 h-3.5 mr-1 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/></svg>PRA IMS v1.2</span>
                    <span class="text-xs text-gray-500 flex items-center"><svg class="w-3.5 h-3.5 mr-1 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/></svg>SHA-256</span>
                </div>
            </div>
        </div>
    </footer>

</body>
</html>
