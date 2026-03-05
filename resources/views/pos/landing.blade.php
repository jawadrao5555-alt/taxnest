<!DOCTYPE html>
<html lang="en">
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
        .pos-gradient { background: linear-gradient(135deg, #7c3aed 0%, #a855f7 40%, #c084fc 70%, #e9d5ff 100%); }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="antialiased text-gray-800">

    <nav class="fixed top-0 w-full z-50 bg-white/70 backdrop-blur-2xl border-b border-white/30 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between h-16">
            <a href="/pos" class="flex items-center space-x-2">
                <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </div>
                <span class="text-xl font-bold text-gray-900">NestPOS</span>
            </a>
            <div class="hidden md:flex items-center space-x-8">
                <a href="#features" class="text-sm font-medium text-gray-600 hover:text-purple-600 transition">Features</a>
                <a href="#demo" class="text-sm font-medium text-gray-600 hover:text-purple-600 transition">Demo</a>
                <a href="/" class="text-sm font-medium text-gray-600 hover:text-purple-600 transition">Digital Invoice</a>
                <a href="/pos/login" class="inline-flex items-center px-4 py-2 border-2 border-purple-600 text-purple-700 rounded-lg text-sm font-semibold hover:bg-purple-50 transition">POS Login</a>
                <a href="/pos/register" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg text-sm font-semibold hover:bg-purple-700 transition shadow-sm">POS Sign Up</a>
            </div>
            <div class="md:hidden flex items-center space-x-2">
                <a href="/pos/login" class="text-sm font-semibold text-purple-700 border border-purple-600 px-3 py-1.5 rounded-lg hover:bg-purple-50 transition">Login</a>
                <a href="/pos/register" class="text-sm font-semibold text-white bg-purple-600 px-3 py-1.5 rounded-lg hover:bg-purple-700 transition">Sign Up</a>
            </div>
        </div>
    </nav>

    <section class="pos-gradient pt-32 pb-20 relative overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-20 left-10 w-72 h-72 bg-white rounded-full blur-3xl"></div>
            <div class="absolute bottom-10 right-20 w-96 h-96 bg-purple-200 rounded-full blur-3xl"></div>
        </div>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="text-center max-w-3xl mx-auto">
                <div class="inline-flex items-center px-4 py-1.5 rounded-full bg-white/20 backdrop-blur-sm border border-white/30 mb-6">
                    <span class="text-sm font-semibold text-white">PRA Punjab Integrated</span>
                </div>
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-white leading-tight">
                    NestPOS<br>
                    <span class="text-purple-100">Enterprise Point of Sale</span>
                </h1>
                <p class="mt-6 text-lg text-purple-100 max-w-2xl mx-auto">
                    Complete POS billing system with PRA (Punjab Revenue Authority) fiscal device integration.
                    Thermal receipt printing, real-time tax calculations, and full compliance reporting.
                </p>
                <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="/pos/register" class="px-8 py-3.5 bg-white text-purple-700 rounded-xl text-sm font-bold hover:bg-purple-50 transition shadow-lg">
                        Start Free Trial
                    </a>
                    <a href="#demo" class="px-8 py-3.5 border-2 border-white/40 text-white rounded-xl text-sm font-bold hover:bg-white/10 transition">
                        View Demo
                    </a>
                </div>
            </div>
        </div>
    </section>

    <section id="features" class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl font-bold text-gray-900">POS Features</h2>
                <p class="mt-4 text-gray-600 max-w-2xl mx-auto">Everything you need to run your retail or service business with full PRA compliance.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100">
                    <div class="w-12 h-12 rounded-xl bg-purple-100 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Smart Billing</h3>
                    <p class="text-sm text-gray-600">Add products and services, apply discounts (percentage or amount), with dynamic tax calculation based on payment method.</p>
                </div>
                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100">
                    <div class="w-12 h-12 rounded-xl bg-purple-100 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Payment Methods</h3>
                    <p class="text-sm text-gray-600">Cash (16% GST), Card/QR (5% GST). Tax automatically adjusts when payment method changes. Cash, Debit, Credit, QR/Raast supported.</p>
                </div>
                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100">
                    <div class="w-12 h-12 rounded-xl bg-purple-100 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">PRA Integration</h3>
                    <p class="text-sm text-gray-600">Real-time fiscal device reporting to Punjab Revenue Authority via PRAL IMS API v1.2. Sandbox and production environments.</p>
                </div>
                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100">
                    <div class="w-12 h-12 rounded-xl bg-purple-100 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Thermal Receipts</h3>
                    <p class="text-sm text-gray-600">Print-optimized receipts for 80mm and 58mm thermal printers. PRA QR code and fiscal invoice number on every receipt.</p>
                </div>
                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100">
                    <div class="w-12 h-12 rounded-xl bg-purple-100 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">Multi-Terminal</h3>
                    <p class="text-sm text-gray-600">Register and manage multiple POS terminals. Track transactions per terminal with location and status management.</p>
                </div>
                <div class="bg-gray-50 rounded-2xl p-6 border border-gray-100">
                    <div class="w-12 h-12 rounded-xl bg-purple-100 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">POS Reports</h3>
                    <p class="text-sm text-gray-600">Daily sales trends, payment method breakdown, top-selling products, and PRA submission analytics.</p>
                </div>
            </div>
        </div>
    </section>

    <section id="demo" class="py-20 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-gray-900">How NestPOS Works</h2>
                <p class="mt-4 text-gray-600">Simple 4-step billing process with automatic compliance</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <div class="text-center">
                    <div class="w-16 h-16 rounded-2xl bg-purple-600 text-white flex items-center justify-center text-2xl font-bold mx-auto mb-4">1</div>
                    <h4 class="font-bold text-gray-900 mb-2">Add Items</h4>
                    <p class="text-sm text-gray-600">Select products or services from your catalog with quantity and pricing</p>
                </div>
                <div class="text-center">
                    <div class="w-16 h-16 rounded-2xl bg-purple-600 text-white flex items-center justify-center text-2xl font-bold mx-auto mb-4">2</div>
                    <h4 class="font-bold text-gray-900 mb-2">Apply Discount</h4>
                    <p class="text-sm text-gray-600">Choose percentage or flat amount discount for the entire bill</p>
                </div>
                <div class="text-center">
                    <div class="w-16 h-16 rounded-2xl bg-purple-600 text-white flex items-center justify-center text-2xl font-bold mx-auto mb-4">3</div>
                    <h4 class="font-bold text-gray-900 mb-2">Select Payment</h4>
                    <p class="text-sm text-gray-600">Cash, Card, or QR — tax rate adjusts automatically per PRA rules</p>
                </div>
                <div class="text-center">
                    <div class="w-16 h-16 rounded-2xl bg-purple-600 text-white flex items-center justify-center text-2xl font-bold mx-auto mb-4">4</div>
                    <h4 class="font-bold text-gray-900 mb-2">Print Receipt</h4>
                    <p class="text-sm text-gray-600">Invoice submitted to PRA, receipt printed with fiscal number and QR code</p>
                </div>
            </div>
        </div>
    </section>

    <section class="py-16 bg-purple-600">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h2 class="text-3xl font-bold text-white mb-4">Ready to Get Started?</h2>
            <p class="text-purple-100 mb-8">Start your 14-day free trial. No credit card required.</p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="/pos/register" class="px-8 py-3.5 bg-white text-purple-700 rounded-xl text-sm font-bold hover:bg-purple-50 transition shadow-lg">Create POS Account</a>
                <a href="/pos/login" class="px-8 py-3.5 border-2 border-white/40 text-white rounded-xl text-sm font-bold hover:bg-white/10 transition">Login to POS</a>
            </div>
        </div>
    </section>

    <footer class="bg-gray-900 py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row items-center justify-between">
                <div class="flex items-center space-x-2 mb-4 md:mb-0">
                    <div class="w-6 h-6 rounded bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center">
                        <svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </div>
                    <span class="text-sm font-bold text-white">NestPOS</span>
                    <span class="text-xs text-gray-500 ml-2">by TaxNest</span>
                </div>
                <div class="flex items-center space-x-6 text-sm text-gray-400">
                    <a href="/" class="hover:text-white transition">Digital Invoice (FBR)</a>
                    <a href="/pos/login" class="hover:text-white transition">POS Login</a>
                </div>
            </div>
        </div>
    </footer>

</body>
</html>
