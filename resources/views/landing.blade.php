<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <title>TaxNest — Pakistan's Smart FBR Compliance Platform</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Figtree', sans-serif; }
        .glass-card { background: rgba(255,255,255,0.7); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.3); }
        .hero-gradient { background: linear-gradient(135deg, #064e3b 0%, #059669 40%, #10b981 70%, #34d399 100%); }
        .feature-gradient { background: linear-gradient(180deg, #f0fdf4 0%, #ffffff 100%); }
    </style>
</head>
<body class="antialiased text-gray-800">

    <nav class="fixed top-0 w-full z-50 bg-white/80 backdrop-blur-lg border-b border-gray-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between h-16">
            <div class="flex items-center space-x-2">
                <div class="w-8 h-8 rounded-lg bg-emerald-600 flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </div>
                <span class="text-xl font-bold text-gray-900">TaxNest</span>
            </div>
            <div class="hidden md:flex items-center space-x-8">
                <a href="#features" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition">Features</a>
                <a href="#how-it-works" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition">How It Works</a>
                <a href="#pricing" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition">Pricing</a>
                <a href="/login" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition">Login</a>
                <a href="/register" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-semibold hover:bg-emerald-700 transition shadow-sm">Start Free Trial</a>
            </div>
            <div class="md:hidden">
                <a href="/login" class="text-sm font-medium text-emerald-600">Login</a>
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
                    FBR Compliant &bull; PRAL Integrated
                </div>
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-white leading-tight mb-6">
                    TaxNest — Pakistan's Smart<br>FBR Compliance Platform
                </h1>
                <p class="text-xl text-emerald-100 mb-10 max-w-2xl mx-auto leading-relaxed">
                    Validate Before You Submit. Smart invoicing with real-time compliance scoring, vendor risk detection, and seamless PRAL integration.
                </p>
                <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="/register" class="inline-flex items-center px-8 py-4 bg-white text-emerald-700 rounded-xl text-lg font-bold hover:bg-emerald-50 transition shadow-lg shadow-emerald-900/20 w-full sm:w-auto justify-center">
                        Start Free Trial
                        <svg class="w-5 h-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                    </a>
                    <a href="/login" class="inline-flex items-center px-8 py-4 bg-transparent text-white rounded-xl text-lg font-bold hover:bg-white/10 transition border-2 border-white/40 w-full sm:w-auto justify-center">
                        Book Demo
                    </a>
                </div>
            </div>

            <div class="mt-16 grid grid-cols-3 gap-6 max-w-3xl mx-auto">
                <div class="glass-card rounded-xl p-4 text-center">
                    <p class="text-3xl font-extrabold text-white">99.9%</p>
                    <p class="text-sm text-emerald-100 mt-1">Uptime SLA</p>
                </div>
                <div class="glass-card rounded-xl p-4 text-center">
                    <p class="text-3xl font-extrabold text-white">50k+</p>
                    <p class="text-sm text-emerald-100 mt-1">Invoices Processed</p>
                </div>
                <div class="glass-card rounded-xl p-4 text-center">
                    <p class="text-3xl font-extrabold text-white">500+</p>
                    <p class="text-sm text-emerald-100 mt-1">Companies Trust Us</p>
                </div>
            </div>
        </div>
    </section>

    <section id="features" class="feature-gradient py-20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <p class="text-sm font-semibold text-emerald-600 uppercase tracking-wider mb-2">Features</p>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900">Everything You Need for FBR Compliance</h2>
                <p class="mt-4 text-lg text-gray-500 max-w-2xl mx-auto">Enterprise-grade tools designed for Pakistan's tax ecosystem</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <div class="glass-card rounded-2xl p-8 hover:shadow-lg transition group">
                    <div class="w-12 h-12 bg-emerald-100 rounded-xl flex items-center justify-center mb-5 group-hover:bg-emerald-200 transition">
                        <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">FBR Digital Integration</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">Direct PRAL API integration with real-time submission, QR code generation, and invoice locking for complete FBR compliance.</p>
                </div>

                <div class="glass-card rounded-2xl p-8 hover:shadow-lg transition group">
                    <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center mb-5 group-hover:bg-blue-200 transition">
                        <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Compliance Score Engine</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">AI-powered hybrid scoring that validates tax rates, buyer NTN, banking rules, and structural compliance before submission.</p>
                </div>

                <div class="glass-card rounded-2xl p-8 hover:shadow-lg transition group">
                    <div class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center mb-5 group-hover:bg-purple-200 transition">
                        <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Smart Withholding Module</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">Automatic WHT calculations based on Section 153 rules with configurable tax brackets for goods, services, and contracts.</p>
                </div>

                <div class="glass-card rounded-2xl p-8 hover:shadow-lg transition group">
                    <div class="w-12 h-12 bg-orange-100 rounded-xl flex items-center justify-center mb-5 group-hover:bg-orange-200 transition">
                        <svg class="w-6 h-6 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Vendor Risk Detection</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">Automated vendor scoring with NTN verification, rejection tracking, tax mismatch alerts, and anomaly-based risk profiles.</p>
                </div>

                <div class="glass-card rounded-2xl p-8 hover:shadow-lg transition group">
                    <div class="w-12 h-12 bg-rose-100 rounded-xl flex items-center justify-center mb-5 group-hover:bg-rose-200 transition">
                        <svg class="w-6 h-6 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Enterprise Analytics</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">MIS reporting with monthly trends, HS concentration analysis, tax variance tracking, and executive compliance dashboards.</p>
                </div>

                <div class="glass-card rounded-2xl p-8 hover:shadow-lg transition group">
                    <div class="w-12 h-12 bg-teal-100 rounded-xl flex items-center justify-center mb-5 group-hover:bg-teal-200 transition">
                        <svg class="w-6 h-6 text-teal-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Audit Defense</h3>
                    <p class="text-gray-500 text-sm leading-relaxed">SHA-256 integrity hashing, immutable activity logs, compliance certificates, and real-time audit probability scoring.</p>
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
                <div class="text-center">
                    <div class="w-16 h-16 bg-emerald-100 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <span class="text-2xl font-extrabold text-emerald-600">1</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Create Invoice</h3>
                    <p class="text-gray-500 leading-relaxed">Select products from your master catalog. HS codes, tax rates, and prices auto-fill instantly. Add line items and buyer details.</p>
                </div>

                <div class="text-center relative">
                    <div class="hidden md:block absolute top-8 -left-6 w-12 border-t-2 border-dashed border-emerald-300"></div>
                    <div class="w-16 h-16 bg-emerald-100 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <span class="text-2xl font-extrabold text-emerald-600">2</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Validate & Submit to FBR</h3>
                    <p class="text-gray-500 leading-relaxed">Run compliance checks, view risk scores, and submit directly to PRAL. Critical issues are blocked automatically.</p>
                    <div class="hidden md:block absolute top-8 -right-6 w-12 border-t-2 border-dashed border-emerald-300"></div>
                </div>

                <div class="text-center">
                    <div class="w-16 h-16 bg-emerald-100 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <span class="text-2xl font-extrabold text-emerald-600">3</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Download & Share</h3>
                    <p class="text-gray-500 leading-relaxed">Download professional PDF invoices with QR codes. Share via WhatsApp or unique links. Everything is audit-ready.</p>
                </div>
            </div>
        </div>
    </section>

    <section id="pricing" class="py-20 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <p class="text-sm font-semibold text-emerald-600 uppercase tracking-wider mb-2">Pricing</p>
                <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900">Plans for Every Business</h2>
                <p class="mt-4 text-lg text-gray-500">Start free. Scale as you grow.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-5xl mx-auto">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-8 hover:shadow-md transition">
                    <h3 class="text-lg font-bold text-gray-900 mb-1">Basic</h3>
                    <p class="text-sm text-gray-500 mb-6">For small businesses</p>
                    <div class="mb-6">
                        <span class="text-4xl font-extrabold text-gray-900">Free</span>
                        <span class="text-gray-500 text-sm ml-1">/ 14-day trial</span>
                    </div>
                    <ul class="space-y-3 mb-8">
                        <li class="flex items-center text-sm text-gray-600"><svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>50 Invoices/month</li>
                        <li class="flex items-center text-sm text-gray-600"><svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>FBR Integration</li>
                        <li class="flex items-center text-sm text-gray-600"><svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Basic Compliance</li>
                        <li class="flex items-center text-sm text-gray-600"><svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>PDF Downloads</li>
                    </ul>
                    <a href="/register" class="block w-full text-center px-6 py-3 border-2 border-emerald-600 text-emerald-600 rounded-xl font-semibold hover:bg-emerald-50 transition">Get Started</a>
                </div>

                <div class="bg-white rounded-2xl shadow-lg border-2 border-emerald-500 p-8 relative">
                    <div class="absolute -top-4 left-1/2 transform -translate-x-1/2">
                        <span class="inline-flex px-4 py-1 bg-emerald-600 text-white text-xs font-bold rounded-full uppercase tracking-wider">Most Popular</span>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-1">Professional</h3>
                    <p class="text-sm text-gray-500 mb-6">For growing companies</p>
                    <div class="mb-6">
                        <span class="text-4xl font-extrabold text-gray-900">Rs. 4,999</span>
                        <span class="text-gray-500 text-sm ml-1">/ month</span>
                    </div>
                    <ul class="space-y-3 mb-8">
                        <li class="flex items-center text-sm text-gray-600"><svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>500 Invoices/month</li>
                        <li class="flex items-center text-sm text-gray-600"><svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Smart Compliance</li>
                        <li class="flex items-center text-sm text-gray-600"><svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Vendor Risk Alerts</li>
                        <li class="flex items-center text-sm text-gray-600"><svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>MIS Reports + CSV</li>
                        <li class="flex items-center text-sm text-gray-600"><svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Priority Support</li>
                    </ul>
                    <a href="/register" class="block w-full text-center px-6 py-3 bg-emerald-600 text-white rounded-xl font-semibold hover:bg-emerald-700 transition shadow-sm">Start Free Trial</a>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-8 hover:shadow-md transition">
                    <h3 class="text-lg font-bold text-gray-900 mb-1">Enterprise</h3>
                    <p class="text-sm text-gray-500 mb-6">For large organizations</p>
                    <div class="mb-6">
                        <span class="text-4xl font-extrabold text-gray-900">Custom</span>
                    </div>
                    <ul class="space-y-3 mb-8">
                        <li class="flex items-center text-sm text-gray-600"><svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Unlimited Invoices</li>
                        <li class="flex items-center text-sm text-gray-600"><svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Enterprise API</li>
                        <li class="flex items-center text-sm text-gray-600"><svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Multi-Company</li>
                        <li class="flex items-center text-sm text-gray-600"><svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Custom Integrations</li>
                        <li class="flex items-center text-sm text-gray-600"><svg class="w-4 h-4 text-emerald-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Dedicated Support</li>
                    </ul>
                    <a href="/login" class="block w-full text-center px-6 py-3 border-2 border-gray-300 text-gray-700 rounded-xl font-semibold hover:bg-gray-50 transition">Contact Sales</a>
                </div>
            </div>
        </div>
    </section>

    <footer class="bg-gray-900 text-gray-400 py-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-8 mb-12">
                <div>
                    <div class="flex items-center space-x-2 mb-4">
                        <div class="w-8 h-8 rounded-lg bg-emerald-600 flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        </div>
                        <span class="text-lg font-bold text-white">TaxNest</span>
                    </div>
                    <p class="text-sm leading-relaxed">Pakistan's smart FBR compliance platform for modern businesses.</p>
                </div>
                <div>
                    <h4 class="text-sm font-semibold text-white uppercase tracking-wider mb-4">About</h4>
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

</body>
</html>
