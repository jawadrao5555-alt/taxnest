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
<body class="antialiased text-gray-800" style="scroll-behavior: smooth;">

    <nav class="fixed top-0 w-full z-50 bg-white/95 backdrop-blur-2xl border-b border-gray-200/60 shadow-sm" x-data="{ mobileOpen: false }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <a href="/" class="flex items-center space-x-2.5">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center shadow-sm">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </div>
                    <div>
                        <span class="text-lg font-extrabold text-gray-900 tracking-tight block leading-tight">PRA POS</span>
                        <span class="text-[10px] text-gray-400 font-medium leading-none">by TaxNest</span>
                    </div>
                </a>

                <div class="hidden lg:flex items-center">
                    <div class="flex items-center space-x-5 mr-6">
                        <a href="#features" class="text-sm font-medium text-gray-500 hover:text-purple-600 transition">Features</a>
                        <a href="#pricing" class="text-sm font-medium text-gray-500 hover:text-purple-600 transition">Pricing</a>
                        <a href="#how-it-works" class="text-sm font-medium text-gray-500 hover:text-purple-600 transition">How It Works</a>
                    </div>
                    <div class="border-l border-gray-200 pl-6 mr-6">
                        <a href="/di" class="group flex items-center space-x-2 px-3 py-2 rounded-lg hover:bg-emerald-50 transition">
                            <div class="w-7 h-7 rounded-lg bg-emerald-100 group-hover:bg-emerald-200 flex items-center justify-center transition">
                                <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                            </div>
                            <div>
                                <span class="text-sm font-semibold text-gray-900 block leading-tight">Digital Invoice</span>
                                <span class="text-[10px] text-emerald-600 font-medium">FBR Compliant</span>
                            </div>
                        </a>
                    </div>
                    <div class="flex items-center space-x-2">
                        <a href="/pos/login" class="inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-700 hover:text-gray-900 transition">Log in</a>
                        <a href="/pos/register" class="inline-flex items-center px-5 py-2.5 bg-purple-600 text-white rounded-xl text-sm font-semibold hover:bg-purple-700 transition shadow-sm shadow-purple-600/20">POS Sign Up</a>
                    </div>
                </div>

                <button @click="mobileOpen = !mobileOpen" class="lg:hidden p-2 rounded-lg hover:bg-gray-100 transition">
                    <svg x-show="!mobileOpen" class="w-6 h-6 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    <svg x-show="mobileOpen" x-cloak class="w-6 h-6 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div x-show="mobileOpen" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="lg:hidden border-t border-gray-100 py-4 space-y-2">
                <a href="#features" @click="mobileOpen = false" class="block px-3 py-2 text-sm font-medium text-gray-600 hover:text-purple-600 rounded-lg hover:bg-gray-50 transition">Features</a>
                <a href="#pricing" @click="mobileOpen = false" class="block px-3 py-2 text-sm font-medium text-gray-600 hover:text-purple-600 rounded-lg hover:bg-gray-50 transition">Pricing</a>
                <a href="#how-it-works" @click="mobileOpen = false" class="block px-3 py-2 text-sm font-medium text-gray-600 hover:text-purple-600 rounded-lg hover:bg-gray-50 transition">How It Works</a>
                <div class="border-t border-gray-100 pt-3 mt-2">
                    <a href="/di" class="flex items-center space-x-3 px-3 py-3 rounded-xl hover:bg-emerald-50 transition">
                        <div class="w-9 h-9 rounded-lg bg-emerald-100 flex items-center justify-center">
                            <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
                        </div>
                        <div>
                            <span class="text-sm font-semibold text-gray-900 block">Digital Invoice</span>
                            <span class="text-xs text-emerald-600">FBR Tax Compliance</span>
                        </div>
                    </a>
                </div>
                <div class="border-t border-gray-100 pt-3 mt-2 flex space-x-2 px-3">
                    <a href="/pos/login" class="flex-1 text-center py-2.5 text-sm font-semibold text-gray-700 border border-gray-300 rounded-xl hover:bg-gray-50 transition">Log in</a>
                    <a href="/pos/register" class="flex-1 text-center py-2.5 text-sm font-semibold text-white bg-purple-600 rounded-xl hover:bg-purple-700 transition">POS Sign Up</a>
                </div>
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
                    <span class="text-sm font-semibold text-white">PRA Punjab Integrated &bull; PRAL IMS API v1.2</span>
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
                    <a href="#how-it-works" class="px-8 py-3.5 border-2 border-white/40 text-white rounded-xl text-sm font-bold hover:bg-white/10 transition">
                        See How It Works
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
                    <p class="text-sm text-gray-600">Daily sales trends, payment method breakdown, top-selling products, and PRA submission analytics with CSV/PDF export.</p>
                </div>
            </div>
        </div>
    </section>

    <section id="pricing" class="py-20 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-gray-900">NestPOS Plans</h2>
                <p class="mt-4 text-gray-600 max-w-2xl mx-auto">Simple annual billing with built-in 6% savings</p>
                <div class="inline-flex items-center mt-4 px-4 py-2 bg-purple-50 border border-purple-200 rounded-lg">
                    <svg class="w-4 h-4 text-purple-600 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span class="text-sm font-semibold text-purple-700">Annual billing only — 6% savings included</span>
                </div>
            </div>

            @php $annualDiscount = 6; @endphp

            @if(isset($plans) && $plans->count())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 max-w-5xl mx-auto">
                @foreach($plans as $plan)
                @php
                    $yearlyTotal = round($plan->price * 12 * (1 - $annualDiscount / 100));
                    $perMonth = round($yearlyTotal / 12);
                    $saved = round($plan->price * 12 * $annualDiscount / 100);
                    $isPopular = $plan->name === 'Business';
                @endphp
                <div class="relative rounded-2xl overflow-hidden transition duration-300 hover:-translate-y-1 {{ $isPopular ? 'ring-2 ring-purple-500 shadow-lg shadow-purple-500/10' : 'shadow-sm' }}">
                    @if($isPopular)
                    <div class="bg-purple-600 text-center py-1.5">
                        <span class="text-white text-xs font-bold tracking-wide">MOST POPULAR</span>
                    </div>
                    @endif
                    <div class="bg-white border {{ $isPopular ? 'border-purple-500 border-t-0 rounded-b-2xl' : 'border-gray-200 rounded-2xl' }} p-5">
                        <h3 class="text-lg font-bold text-gray-900">{{ $plan->name }}</h3>
                        <div class="mt-3 mb-1">
                            <span class="text-3xl font-black text-gray-900">Rs. {{ number_format($yearlyTotal) }}</span>
                            <span class="text-gray-400 text-sm">/year</span>
                        </div>
                        <p class="text-xs text-gray-400">Rs. {{ number_format($perMonth) }}/mo effective</p>
                        <p class="text-xs text-purple-600 font-medium mt-0.5">Save Rs. {{ number_format($saved) }}</p>

                        <div class="mt-4 pt-4 border-t border-gray-100 space-y-2 text-sm text-gray-600">
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-purple-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                {{ $plan->getInvoiceLimitDisplay() }} transactions/mo
                            </div>
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-purple-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                {{ $plan->getUserLimitDisplay() }} terminals
                            </div>
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-purple-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                PRA fiscal receipts
                            </div>
                            @if($plan->name !== 'Retail')
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-purple-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Inventory management
                            </div>
                            @endif
                            @if(in_array($plan->name, ['Industrial', 'Enterprise']))
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-purple-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Offline mode + auto-sync
                            </div>
                            @endif
                            @if($plan->name === 'Enterprise')
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-purple-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Priority support
                            </div>
                            @endif
                        </div>

                        <div class="mt-5">
                            <a href="/pos/register" class="block w-full py-2.5 rounded-lg text-sm font-semibold text-center transition {{ $isPopular ? 'bg-purple-600 text-white hover:bg-purple-700 shadow-sm' : 'bg-gray-900 text-white hover:bg-gray-800' }}">
                                Get {{ $plan->name }}
                            </a>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            <div class="mt-8 text-center">
                <div class="inline-flex flex-wrap items-center justify-center gap-6 text-xs text-gray-400">
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        PRA compliant
                    </span>
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        14-day free trial
                    </span>
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        6% annual savings
                    </span>
                </div>
            </div>
            @endif
        </div>
    </section>

    <section id="how-it-works" class="py-20 bg-white">
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
                    <a href="/" class="hover:text-white transition">TaxNest Home</a>
                    <a href="/di" class="hover:text-white transition">Digital Invoice (FBR)</a>
                    <a href="/pos/login" class="hover:text-white transition">POS Login</a>
                </div>
            </div>
            <div class="mt-6 pt-6 border-t border-gray-800 text-center">
                <p class="text-xs text-gray-500">&copy; {{ date('Y') }} TaxNest. All rights reserved. PRA IMS v1.2 Integrated.</p>
            </div>
        </div>
    </footer>

</body>
</html>
