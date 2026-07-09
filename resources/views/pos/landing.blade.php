<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>NestPOS — Keyboard-Fast POS with PRA Compliance</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800,900|jetbrains-mono:400,700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: 'Figtree', sans-serif; background-color: #f8fafc; color: #0f172a; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        
        .solid-shadow {
            box-shadow: 4px 4px 0px 0px rgba(15, 23, 42, 1);
            transition: all 0.15s ease-out;
        }
        .solid-shadow:hover {
            transform: translate(2px, 2px);
            box-shadow: 2px 2px 0px 0px rgba(15, 23, 42, 1);
        }
        .solid-shadow:active {
            transform: translate(4px, 4px);
            box-shadow: 0px 0px 0px 0px rgba(15, 23, 42, 1);
        }
        
        .card-shadow {
            box-shadow: 8px 8px 0px 0px rgba(15, 23, 42, 0.05);
            border: 2px solid #e2e8f0;
        }
        
        .bg-grid {
            background-size: 40px 40px;
            background-image: linear-gradient(to right, rgba(0, 0, 0, 0.05) 1px, transparent 1px),
                              linear-gradient(to bottom, rgba(0, 0, 0, 0.05) 1px, transparent 1px);
        }
        
        .key-cap {
            background: #ffffff;
            border: 2px solid #cbd5e1;
            border-bottom-width: 4px;
            border-radius: 6px;
            padding: 2px 8px;
            font-size: 0.75rem;
            font-weight: 700;
            color: #475569;
        }
        
        /* Fast snappy animations */
        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .animate-slide-up {
            animation: slideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        .delay-100 { animation-delay: 100ms; }
        .delay-200 { animation-delay: 200ms; }
        .delay-300 { animation-delay: 300ms; }
    </style>
</head>
<body class="antialiased selection:bg-purple-900 selection:text-white" x-data="{ mobileMenuOpen: false }">

    <!-- Navigation -->
    <nav class="fixed top-0 w-full z-50 bg-white border-b-2 border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-20">
                <div class="flex items-center space-x-8">
                    <a href="/" class="flex items-center space-x-2 group">
                        <div class="w-10 h-10 bg-purple-700 rounded flex items-center justify-center border-2 border-purple-900">
                            <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2.5" d="M4 6h16M4 12h16m-7 6h7"/></svg>
                        </div>
                        <div>
                            <span class="text-xl font-black text-slate-900 tracking-tight block leading-none">NestPOS</span>
                            <span class="text-[10px] font-bold text-purple-700 tracking-widest uppercase block mt-0.5">By TaxNest</span>
                        </div>
                    </a>
                </div>

                <div class="hidden md:flex items-center space-x-8">
                    <a href="#features" class="text-sm font-bold text-slate-600 hover:text-purple-700 transition-colors">Features</a>
                    <a href="#editions" class="text-sm font-bold text-slate-600 hover:text-purple-700 transition-colors">Editions & Pricing</a>
                    
                    <div class="flex items-center space-x-4 ml-4">
                        <a href="/pos/login" class="text-sm font-bold text-slate-900 hover:text-purple-700 transition-colors">Log In</a>
                        <a href="/pos/register" class="solid-shadow bg-purple-700 text-white px-6 py-2.5 rounded font-bold text-sm border-2 border-slate-900">
                            Start Free Trial
                        </a>
                    </div>
                </div>

                <div class="md:hidden flex items-center">
                    <button @click="mobileMenuOpen = !mobileMenuOpen" class="text-slate-900 p-2">
                        <svg x-show="!mobileMenuOpen" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                        <svg x-show="mobileMenuOpen" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display: none;"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Menu -->
        <div x-show="mobileMenuOpen" class="md:hidden border-t-2 border-slate-200 bg-white" style="display: none;">
            <div class="px-4 pt-2 pb-6 space-y-1">
                <a href="#features" class="block px-3 py-3 text-base font-bold text-slate-900 border-b border-slate-100">Features</a>
                <a href="#editions" class="block px-3 py-3 text-base font-bold text-slate-900 border-b border-slate-100">Editions & Pricing</a>
                <a href="/pos/login" class="block px-3 py-3 text-base font-bold text-slate-900 border-b border-slate-100">Log In</a>
                <a href="/pos/register" class="block px-3 py-3 mt-4 text-center text-base font-bold text-white bg-purple-700 rounded border-2 border-slate-900">Start Free Trial</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="pt-32 pb-20 lg:pt-48 lg:pb-32 overflow-hidden bg-grid relative">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-8 items-center">
                
                <div class="lg:col-span-5 lg:pr-8 animate-slide-up opacity-0">
                    <div class="inline-flex items-center px-3 py-1 rounded bg-purple-100 border-2 border-purple-900 mb-6">
                        <div class="w-2 h-2 bg-purple-600 rounded-full mr-2 animate-pulse"></div>
                        <span class="text-xs font-bold text-purple-900 uppercase tracking-wide font-mono">Punjab PRA Compliant</span>
                    </div>
                    
                    <h1 class="text-5xl lg:text-7xl font-black text-slate-900 leading-[1.1] tracking-tight mb-6">
                        Ring Up Faster.
                    </h1>
                    <p class="text-lg lg:text-xl text-slate-600 font-medium mb-8 leading-relaxed">
                        A keyboard-fast point of sale built for Punjab's real shop counters. Process a queue of customers at rush hour without lifting your hands from the keys. PRA compliance is built in, so you never think about it.
                    </p>
                    
                    <div class="flex flex-col sm:flex-row gap-4">
                        <a href="/pos/register" class="solid-shadow flex items-center justify-center bg-purple-700 text-white px-8 py-4 rounded font-bold text-base border-2 border-slate-900">
                            Start 3-Day Free Trial
                        </a>
                        <a href="#features" class="solid-shadow flex items-center justify-center bg-white text-slate-900 px-8 py-4 rounded font-bold text-base border-2 border-slate-900">
                            Explore Features
                        </a>
                    </div>
                    
                    <div class="mt-10 flex items-center space-x-4 text-sm font-bold text-slate-500 font-mono">
                        <span class="flex items-center"><svg class="w-4 h-4 mr-1 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="3" d="M5 13l4 4L19 7"/></svg> No Credit Card</span>
                        <span class="flex items-center"><svg class="w-4 h-4 mr-1 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="3" d="M5 13l4 4L19 7"/></svg> Instant Setup</span>
                    </div>
                </div>

                <div class="lg:col-span-7 relative animate-slide-up delay-200 opacity-0">
                    <div class="absolute inset-0 bg-purple-700 transform translate-x-4 translate-y-4 rounded border-2 border-slate-900"></div>
                    <div class="relative bg-white rounded border-2 border-slate-900 overflow-hidden z-10 flex flex-col">
                        <div class="bg-slate-100 border-b-2 border-slate-900 px-4 py-3 flex items-center space-x-2">
                            <div class="w-3 h-3 rounded-full bg-slate-300 border border-slate-400"></div>
                            <div class="w-3 h-3 rounded-full bg-slate-300 border border-slate-400"></div>
                            <div class="w-3 h-3 rounded-full bg-slate-300 border border-slate-400"></div>
                            <div class="ml-4 font-mono text-xs font-bold text-slate-500">nestpos-terminal</div>
                        </div>
                        <img src="{{ asset('images/screenshots/pos-sale.jpg') }}" alt="NestPOS Sale Screen" class="w-full object-cover block">
                    </div>
                    
                    <!-- Floating tactile elements -->
                    <div class="absolute -bottom-6 -left-6 bg-white border-2 border-slate-900 px-4 py-3 rounded solid-shadow z-20 transform -rotate-2">
                        <div class="flex items-center space-x-3">
                            <span class="key-cap">ENTER</span>
                            <span class="text-sm font-bold text-slate-900">to advance</span>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>
    </section>

    <!-- Core Facts Grid -->
    <section id="features" class="py-24 bg-white border-y-2 border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-16 md:flex md:items-end md:justify-between">
                <div class="max-w-2xl">
                    <h2 class="text-4xl font-black text-slate-900 tracking-tight">Built for Rush Hour.</h2>
                    <p class="mt-4 text-xl text-slate-600 font-medium">When the queue is out the door, you need a system that keeps up. Every feature is optimized for speed and resilience.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <!-- Feature 1 -->
                <div class="card-shadow bg-white p-8 rounded relative group">
                    <div class="w-12 h-12 bg-purple-100 border-2 border-purple-900 rounded mb-6 flex items-center justify-center group-hover:bg-purple-700 transition-colors">
                        <svg class="w-6 h-6 text-purple-900 group-hover:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-3">Keyboard-First Flow</h3>
                    <p class="text-slate-600 font-medium">Guided billing flow where <span class="key-cap">Enter</span> advances each step. Process entire orders without touching a mouse.</p>
                </div>

                <!-- Feature 2 -->
                <div class="card-shadow bg-white p-8 rounded relative group">
                    <div class="w-12 h-12 bg-slate-100 border-2 border-slate-900 rounded mb-6 flex items-center justify-center group-hover:bg-slate-900 transition-colors">
                        <svg class="w-6 h-6 text-slate-900 group-hover:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-3">PRA Fiscal Integration</h3>
                    <p class="text-slate-600 font-medium">Direct connection to Punjab Revenue Authority via Cloud Mode or Desktop Sync Agent. Fully compliant.</p>
                </div>

                <!-- Feature 3 -->
                <div class="card-shadow bg-white p-8 rounded relative group">
                    <div class="w-12 h-12 bg-emerald-100 border-2 border-emerald-900 rounded mb-6 flex items-center justify-center group-hover:bg-emerald-600 transition-colors">
                        <svg class="w-6 h-6 text-emerald-900 group-hover:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/></svg>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-3">Offline Resilience</h3>
                    <p class="text-slate-600 font-medium">Internet down? Keep billing. The system automatically syncs provisional bills when the connection returns.</p>
                </div>

                <!-- Feature 4 -->
                <div class="card-shadow bg-white p-8 rounded relative group">
                    <div class="w-12 h-12 bg-blue-100 border-2 border-blue-900 rounded mb-6 flex items-center justify-center group-hover:bg-blue-600 transition-colors">
                        <svg class="w-6 h-6 text-blue-900 group-hover:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-3">Exact-Match Scanning</h3>
                    <p class="text-slate-600 font-medium">Lightning fast Barcode/SKU scanning that exactly matches inventory. No delays or misreads.</p>
                </div>

                <!-- Feature 5 -->
                <div class="card-shadow bg-white p-8 rounded relative group">
                    <div class="w-12 h-12 bg-rose-100 border-2 border-rose-900 rounded mb-6 flex items-center justify-center group-hover:bg-rose-600 transition-colors">
                        <svg class="w-6 h-6 text-rose-900 group-hover:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-3">Restaurant Module</h3>
                    <p class="text-slate-600 font-medium">Kitchen Order Tickets (KOT), Kitchen Displays, order types (Dine-in, Takeaway), and per-item tax toggles.</p>
                </div>

                <!-- Feature 6 -->
                <div class="card-shadow bg-white p-8 rounded relative group">
                    <div class="w-12 h-12 bg-amber-100 border-2 border-amber-900 rounded mb-6 flex items-center justify-center group-hover:bg-amber-500 transition-colors">
                        <svg class="w-6 h-6 text-amber-900 group-hover:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 mb-3">Hardened Thermal Print</h3>
                    <p class="text-slate-600 font-medium">Optimized for cheap thermal printers. Clear layouts, QR codes, and day-close reports that just work.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Deep Dive / Screenshots -->
    <section class="py-24 bg-slate-900 text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            
            <!-- Dashboard UI -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-16 items-center mb-32">
                <div class="order-2 lg:order-1 relative">
                    <div class="absolute inset-0 bg-slate-700 transform -translate-x-3 translate-y-3 rounded border-2 border-slate-900"></div>
                    <img src="{{ asset('images/screenshots/pos-dash.jpg') }}" alt="NestPOS Dashboard" class="relative z-10 w-full border-2 border-slate-900 rounded">
                </div>
                <div class="order-1 lg:order-2">
                    <h2 class="text-3xl font-black mb-4">Complete Store Management</h2>
                    <p class="text-slate-400 font-medium text-lg mb-6">Track profit and business intelligence from anywhere. Manage inventory, view the customer ledger, and handle multi-branch operations from a single dashboard.</p>
                    <ul class="space-y-3 font-mono text-sm">
                        <li class="flex items-center"><svg class="w-5 h-5 mr-3 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Real-time Inventory Management</li>
                        <li class="flex items-center"><svg class="w-5 h-5 mr-3 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Multi-branch Support</li>
                        <li class="flex items-center"><svg class="w-5 h-5 mr-3 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Detailed Day-Close Reports</li>
                    </ul>
                </div>
            </div>

            <!-- Transactions UI -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">
                <div>
                    <h2 class="text-3xl font-black mb-4">Transactions & Ledgers</h2>
                    <p class="text-slate-400 font-medium text-lg mb-6">Review every transaction instantly. Convert provisional local bills to permanent ones, manage refunds, and track the customer ledger accurately.</p>
                    <ul class="space-y-3 font-mono text-sm">
                        <li class="flex items-center"><svg class="w-5 h-5 mr-3 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Installable PWA for quick access</li>
                        <li class="flex items-center"><svg class="w-5 h-5 mr-3 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Customer Ledger tracking</li>
                        <li class="flex items-center"><svg class="w-5 h-5 mr-3 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Multiple visual themes</li>
                    </ul>
                </div>
                <div class="relative">
                    <div class="absolute inset-0 bg-slate-700 transform translate-x-3 translate-y-3 rounded border-2 border-slate-900"></div>
                    <img src="{{ asset('images/screenshots/pos-tx.jpg') }}" alt="NestPOS Transactions" class="relative z-10 w-full border-2 border-slate-900 rounded">
                </div>
            </div>

        </div>
    </section>

    <!-- Pricing Section -->
    <section id="editions" class="py-24 bg-slate-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16 max-w-3xl mx-auto">
                <h2 class="text-4xl font-black text-slate-900">Two Solid Editions.</h2>
                <p class="mt-4 text-xl text-slate-600 font-medium">Choose PRA Integration for compliance, or Standalone for just the tools. Annual billing only, with a built-in 6% discount.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 lg:gap-8 max-w-5xl mx-auto">
                
                <!-- Standalone Edition -->
                <div class="bg-white rounded border-2 border-slate-900 p-8 card-shadow flex flex-col relative">
                    <div class="inline-block px-3 py-1 bg-slate-200 border-2 border-slate-900 text-slate-800 font-bold text-xs uppercase tracking-widest font-mono mb-6 self-start">Standalone Edition</div>
                    <h3 class="text-2xl font-black text-slate-900 mb-2">No Government Integration</h3>
                    <p class="text-slate-600 font-medium mb-8">The complete point of sale system for shops that don't need PRA compliance.</p>
                    
                    <div class="flex-grow space-y-6">
                        @if(isset($standalonePlans) && $standalonePlans->count())
                            @foreach($standalonePlans as $plan)
                                @php
                                    $perMonth = round($plan->sale_price / 12);
                                    $hasOffer = $plan->sale_percent > 0;
                                    $comparePerMonth = $hasOffer ? round($plan->price / 12) : 0;
                                    $features = is_array($plan->features) ? $plan->features : (is_string($plan->features) ? json_decode($plan->features, true) : []);
                                @endphp
                                <div class="p-4 bg-slate-50 border-2 border-slate-200 rounded">
                                    <div class="flex justify-between items-start mb-2">
                                        <h4 class="font-bold text-lg text-slate-900">{{ $plan->name }}</h4>
                                        <div class="text-right">
                                            <div class="font-black text-xl text-slate-900">PKR {{ number_format($plan->sale_price) }}<span class="text-sm text-slate-500 font-medium">/yr</span></div>
                                        </div>
                                    </div>
                                    <p class="text-sm text-slate-500 font-mono mb-4">Effective: PKR {{ number_format($perMonth) }}/mo</p>
                                    @if(!empty($features))
                                        <ul class="space-y-2">
                                            @foreach(array_slice($features, 0, 3) as $feature)
                                            <li class="flex items-start text-sm text-slate-700 font-medium">
                                                <svg class="w-4 h-4 mr-2 text-slate-900 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                {{ $feature }}
                                            </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endforeach
                        @else
                            <div class="p-6 bg-slate-50 border-2 border-slate-200 rounded text-center text-slate-500 font-mono">
                                Plans loading...
                            </div>
                        @endif
                    </div>
                </div>

                <!-- PRA Integrated Edition -->
                <div class="bg-white rounded border-2 border-purple-900 p-8 flex flex-col relative shadow-[8px_8px_0px_0px_rgba(15,23,42,1)]">
                    <div class="absolute top-0 right-8 transform -translate-y-1/2 bg-purple-700 text-white px-4 py-1 border-2 border-purple-900 font-black text-sm uppercase tracking-wider">
                        Most Popular
                    </div>
                    <div class="inline-block px-3 py-1 bg-purple-100 border-2 border-purple-900 text-purple-900 font-bold text-xs uppercase tracking-widest font-mono mb-6 self-start">PRA Integrated</div>
                    <h3 class="text-2xl font-black text-slate-900 mb-2">Full PRA Compliance</h3>
                    <p class="text-slate-600 font-medium mb-8">Includes everything in Standalone, plus automatic Punjab Revenue Authority fiscal integration.</p>
                    
                    <div class="flex-grow space-y-6">
                        @if(isset($plans) && $plans->count())
                            @foreach($plans as $plan)
                                @php
                                    $perMonth = round($plan->sale_price / 12);
                                    $hasOffer = $plan->sale_percent > 0;
                                    $comparePerMonth = $hasOffer ? round($plan->price / 12) : 0;
                                    $features = is_array($plan->features) ? $plan->features : (is_string($plan->features) ? json_decode($plan->features, true) : []);
                                @endphp
                                <div class="p-4 bg-purple-50 border-2 border-purple-200 rounded">
                                    <div class="flex justify-between items-start mb-2">
                                        <h4 class="font-bold text-lg text-purple-900">{{ $plan->name }}</h4>
                                        <div class="text-right">
                                            @if($hasOffer)
                                                <div class="text-xs text-purple-500 line-through mb-0.5">PKR {{ number_format($plan->price) }}</div>
                                            @endif
                                            <div class="font-black text-xl text-purple-900">PKR {{ number_format($plan->sale_price) }}<span class="text-sm text-purple-600 font-medium">/yr</span></div>
                                        </div>
                                    </div>
                                    <div class="flex justify-between items-center mb-4">
                                        <p class="text-sm text-purple-700 font-mono">Effective: PKR {{ number_format($perMonth) }}/mo</p>
                                        @if($hasOffer)
                                            <span class="px-2 py-0.5 bg-rose-100 border border-rose-200 text-rose-700 text-xs font-bold">{{ $plan->sale_badge }}</span>
                                        @endif
                                    </div>
                                    @if(!empty($features))
                                        <ul class="space-y-2">
                                            @foreach(array_slice($features, 0, 4) as $feature)
                                            <li class="flex items-start text-sm text-slate-800 font-medium">
                                                <svg class="w-4 h-4 mr-2 text-purple-700 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                {{ $feature }}
                                            </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endforeach
                        @else
                            <div class="p-6 bg-purple-50 border-2 border-purple-200 rounded text-center text-purple-500 font-mono">
                                Plans loading...
                            </div>
                        @endif
                    </div>
                </div>

            </div>
            
            <div class="mt-16 text-center">
                <a href="/pos/register" class="solid-shadow inline-flex items-center justify-center bg-purple-700 text-white px-10 py-4 rounded font-black text-lg border-2 border-slate-900">
                    Get Started Now
                </a>
                <p class="mt-4 text-sm font-bold text-slate-500 font-mono">3-day free trial. No credit card required.</p>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-white border-t-2 border-slate-900 py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row justify-between items-center">
            <div class="flex items-center space-x-2 mb-4 md:mb-0">
                <img src="{{ asset('images/brand/taxnest-mark.svg') }}" alt="TaxNest" class="w-8 h-8">
                <span class="font-black text-slate-900 text-xl tracking-tight">NestPOS</span>
            </div>
            <div class="text-slate-500 font-bold text-sm">
                &copy; {{ date('Y') }} TaxNest. All rights reserved.
            </div>
            <div class="flex space-x-6 mt-4 md:mt-0 font-bold text-sm text-slate-600">
                <a href="/" class="hover:text-purple-700 transition-colors">Main Site</a>
                <a href="/pos/login" class="hover:text-purple-700 transition-colors">Log In</a>
            </div>
        </div>
    </footer>

    <x-whatsapp-support />
</body>
</html>