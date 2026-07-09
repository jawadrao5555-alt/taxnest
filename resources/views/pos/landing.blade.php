<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#052730">
    <title>NestPOS — Keyboard-Fast POS with PRA Compliance</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=playfair-display:400,600,700,900|inter:400,500,600,700,800|jetbrains-mono:400,700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root {
            --teal-dark: #052730;
            --teal-main: #0A4D5C;
            --teal-light: #1B7C8C;
            --gold: #E7BF3B;
            --paper: #FDFBF7;
            --ink: #1F2937;
        }
        body { 
            font-family: 'Inter', system-ui, -apple-system, sans-serif; 
            background-color: var(--paper);
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
        }
        h1, h2, h3, h4, .font-serif {
            font-family: 'Playfair Display', serif;
        }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        
        [x-cloak] { display: none !important; }
        
        .fade-in-up {
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.8s ease-out, transform 0.8s ease-out;
        }
        .fade-in-up.is-visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* Solid components, neutral shadows */
        .card-solid {
            background: #FFFFFF;
            border: 1px solid rgba(0,0,0,0.08);
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .card-solid:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.08), 0 4px 6px -2px rgba(0,0,0,0.04);
        }

        .btn-solid {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            font-size: 0.875rem;
            border-radius: 0.375rem;
            transition: all 0.2s ease;
        }
        .btn-primary {
            background-color: var(--teal-dark);
            color: #FFFFFF;
            border: 1px solid transparent;
        }
        .btn-primary:hover {
            background-color: var(--teal-main);
        }
        .btn-secondary {
            background-color: #FFFFFF;
            color: var(--teal-dark);
            border: 1px solid rgba(0,0,0,0.15);
            box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);
        }
        .btn-secondary:hover {
            background-color: #F9FAFB;
            border-color: rgba(0,0,0,0.25);
        }
        
        /* NestPOS Accent */
        .accent-product { color: #7C3AED; }
        .bg-accent-product { background-color: #7C3AED; }
        .border-accent-product { border-color: #7C3AED; }

        /* Grid Backgrounds */
        .bg-grid-pattern {
            background-image: linear-gradient(rgba(0,0,0,0.03) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(0,0,0,0.03) 1px, transparent 1px);
            background-size: 40px 40px;
        }
        .bg-grid-pattern-dark {
            background-image: linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
            background-size: 40px 40px;
        }

        /* Nav Transition */
        .nav-scrolled {
            background-color: rgba(253, 251, 247, 0.95);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid rgba(0,0,0,0.05);
            box-shadow: 0 1px 3px 0 rgba(0,0,0,0.05);
        }
        .nav-transparent {
            background-color: transparent;
            border-bottom: 1px solid transparent;
        }
        
        .key-cap {
            background: #ffffff;
            border: 1px solid #d1d5db;
            border-bottom-width: 3px;
            border-radius: 4px;
            padding: 2px 6px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #4b5563;
        }
    </style>
</head>
<body x-data="{ scrolled: false, mobileMenuOpen: false }" @scroll.window="scrolled = (window.pageYOffset > 20)">

    <!-- Navigation -->
    <nav :class="(scrolled || mobileMenuOpen) ? 'nav-scrolled' : 'nav-transparent'" class="fixed top-0 w-full z-50 transition-all duration-300">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <a href="/" class="flex items-center space-x-2 group flex-shrink-0">
                    <img src="{{ asset('images/brand/taxnest-logo.svg') }}" x-show="scrolled || mobileMenuOpen" alt="TaxNest" class="h-6 w-auto">
                    <img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" x-show="!scrolled && !mobileMenuOpen" alt="TaxNest" class="h-6 w-auto">
                    <div class="h-4 w-px mx-2" :class="(scrolled || mobileMenuOpen) ? 'bg-gray-300' : 'bg-white/30'"></div>
                    <div>
                        <span class="text-lg font-serif font-bold tracking-tight block leading-none" :class="(scrolled || mobileMenuOpen) ? 'text-[#052730]' : 'text-white'">NestPOS</span>
                    </div>
                </a>
                
                <div class="hidden md:flex items-center space-x-8">
                    <a href="#features" class="text-sm font-medium transition-colors" :class="scrolled ? 'text-gray-600 hover:text-gray-900' : 'text-gray-200 hover:text-white'">Features</a>
                    <a href="#editions" class="text-sm font-medium transition-colors" :class="scrolled ? 'text-gray-600 hover:text-gray-900' : 'text-gray-200 hover:text-white'">Editions & Pricing</a>
                </div>
                
                <div class="flex items-center space-x-4">
                    <a href="/pos/login" class="hidden sm:inline text-sm font-medium transition-colors" :class="scrolled ? 'text-gray-600 hover:text-gray-900' : 'text-gray-200 hover:text-white'">Log In</a>
                    <a href="/pos/register" class="btn-solid" :class="scrolled ? 'btn-primary' : 'bg-white text-[#052730] hover:bg-gray-100'">Start Free Trial</a>
                    <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden p-2 -mr-2" aria-label="Menu">
                        <svg x-show="!mobileMenuOpen" class="w-6 h-6" :class="scrolled ? 'text-gray-800' : 'text-white'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        <svg x-show="mobileMenuOpen" x-cloak class="w-6 h-6 text-gray-800" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
            
            <div x-show="mobileMenuOpen" x-cloak @click.away="mobileMenuOpen = false" class="md:hidden border-t border-gray-200 py-4 space-y-1 bg-[#FDFBF7]">
                <a href="#features" @click="mobileMenuOpen = false" class="block px-2 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded">Features</a>
                <a href="#editions" @click="mobileMenuOpen = false" class="block px-2 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded">Editions & Pricing</a>
                <a href="/pos/login" class="block px-2 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded">Log In</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="relative bg-[#052730] pt-32 pb-20 lg:pt-48 lg:pb-32 overflow-hidden border-b border-[#0A4D5C]">
        <div class="absolute inset-0 bg-grid-pattern-dark opacity-50"></div>
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-8 items-center">
                
                <div class="lg:col-span-6 lg:pr-8 fade-in-up">
                    <div class="inline-flex items-center px-3 py-1 bg-white/10 border border-white/20 backdrop-blur rounded-full text-xs font-medium text-white/90 tracking-wide uppercase mb-8">
                        <span class="w-1.5 h-1.5 rounded-full bg-accent-product mr-2"></span>
                        Punjab PRA Compliant
                    </div>
                    
                    <h1 class="text-4xl sm:text-5xl lg:text-7xl font-serif text-white leading-tight mb-8">
                        Ring Up <span class="italic text-[#E7BF3B]">Faster.</span>
                    </h1>
                    <p class="text-lg lg:text-xl text-white/70 leading-relaxed mb-10 font-light">
                        A keyboard-fast point of sale built for Punjab's real shop counters. Process a queue of customers at rush hour without lifting your hands from the keys. PRA compliance is built in, so you never think about it.
                    </p>
                    
                    <div class="flex flex-col sm:flex-row items-start gap-4">
                        <a href="/pos/register" class="btn-solid bg-[#E7BF3B] text-[#062A33] hover:bg-[#F2D06B]">
                            Start 3-Day Free Trial
                        </a>
                        <a href="#features" class="btn-solid bg-white/10 border border-white/20 text-white hover:bg-white/20 backdrop-blur">
                            Explore Features
                        </a>
                    </div>
                    
                    <div class="mt-8 flex items-center space-x-6 text-sm text-white/50">
                        <span class="flex items-center"><svg class="w-4 h-4 mr-2 text-[#2EA0B3]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> No Credit Card</span>
                        <span class="flex items-center"><svg class="w-4 h-4 mr-2 text-[#2EA0B3]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Instant Setup</span>
                    </div>
                </div>

                <div class="lg:col-span-6 relative fade-in-up" style="transition-delay: 200ms;">
                    <div class="absolute inset-0 bg-[#063B47] transform translate-x-4 translate-y-4 rounded-lg border border-white/10"></div>
                    <div class="relative bg-[#052730] rounded-lg border border-white/15 overflow-hidden z-10 flex flex-col shadow-2xl">
                        <div class="bg-white/5 border-b border-white/10 px-4 py-3 flex items-center space-x-2 backdrop-blur">
                            <div class="w-3 h-3 rounded-full bg-white/20"></div>
                            <div class="w-3 h-3 rounded-full bg-white/20"></div>
                            <div class="w-3 h-3 rounded-full bg-white/20"></div>
                            <div class="ml-4 font-mono text-xs text-white/40">nestpos-terminal</div>
                        </div>
                        <img src="{{ asset('images/screenshots/pos-sale.jpg') }}" alt="NestPOS Sale Screen" class="w-full object-cover block border-t border-white/5" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIHZpZXdCb3g9IjAgMCA4MDAgNTAwIj48cmVjdCBmaWxsPSIjZjNmMTRiIiB3aWR0aD0iODAwIiBoZWlnaHQ9IjUwMCIvPjx0ZXh0IGZpbGw9IiM5Y2EzYWYiIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBmb250LXNpemU9IjMwIiBkeT0iMTAuNSIgZm9udC13ZWlnaHQ9ImJvbGQiIHg9IjUwJSIgeT0iNTAlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5OZXN0UE9TIFNjcmVlbnNob3Q8L3RleHQ+PC9zdmc+'">
                    </div>
                    
                    <!-- Floating tactile elements -->
                    <div class="absolute -bottom-5 -left-5 bg-white/10 border border-white/20 backdrop-blur px-4 py-3 rounded-lg z-20 transform -rotate-2 shadow-lg">
                        <div class="flex items-center space-x-3">
                            <span class="key-cap text-slate-800 bg-white border-slate-300">ENTER</span>
                            <span class="text-sm font-medium text-white shadow-sm">to advance</span>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>
    </section>

    <!-- Core Facts Grid -->
    <section id="features" class="py-24 bg-grid-pattern">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-16 max-w-2xl fade-in-up">
                <h2 class="text-sm font-bold tracking-widest uppercase text-gray-500 mb-4">Architecture</h2>
                <h3 class="text-3xl sm:text-4xl font-serif text-[#052730] mb-6">Built for rush hour.</h3>
                <p class="text-gray-600 leading-relaxed text-lg">
                    When the queue is out the door, you need a system that keeps up. Every feature is optimized for speed, precision, and absolute compliance.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <!-- Feature 1 -->
                <div class="card-solid p-8 rounded-lg fade-in-up">
                    <div class="w-10 h-10 bg-[#0F6171]/10 rounded mb-6 flex items-center justify-center">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h4 class="font-serif text-xl text-[#052730] mb-3">Keyboard-First Flow</h4>
                    <p class="text-sm text-gray-600 leading-relaxed">
                        Guided billing flow where <span class="key-cap">Enter</span> advances each step. Process entire orders without touching a mouse.
                    </p>
                </div>

                <!-- Feature 2 -->
                <div class="card-solid p-8 rounded-lg fade-in-up" style="transition-delay: 100ms;">
                    <div class="w-10 h-10 bg-[#0F6171]/10 rounded mb-6 flex items-center justify-center">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <h4 class="font-serif text-xl text-[#052730] mb-3">PRA Fiscal Integration</h4>
                    <p class="text-sm text-gray-600 leading-relaxed">
                        Direct connection to Punjab Revenue Authority via Cloud Mode or Desktop Sync Agent. Fully compliant.
                    </p>
                </div>

                <!-- Feature 3 -->
                <div class="card-solid p-8 rounded-lg fade-in-up" style="transition-delay: 200ms;">
                    <div class="w-10 h-10 bg-[#0F6171]/10 rounded mb-6 flex items-center justify-center">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/></svg>
                    </div>
                    <h4 class="font-serif text-xl text-[#052730] mb-3">Offline Resilience</h4>
                    <p class="text-sm text-gray-600 leading-relaxed">
                        Internet down? Keep billing. The system automatically syncs provisional bills when the connection returns.
                    </p>
                </div>

                <!-- Feature 4 -->
                <div class="card-solid p-8 rounded-lg fade-in-up">
                    <div class="w-10 h-10 bg-[#0F6171]/10 rounded mb-6 flex items-center justify-center">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
                    </div>
                    <h4 class="font-serif text-xl text-[#052730] mb-3">Exact-Match Scanning</h4>
                    <p class="text-sm text-gray-600 leading-relaxed">
                        Lightning fast Barcode/SKU scanning that exactly matches inventory. No delays or misreads.
                    </p>
                </div>

                <!-- Feature 5 -->
                <div class="card-solid p-8 rounded-lg fade-in-up" style="transition-delay: 100ms;">
                    <div class="w-10 h-10 bg-[#0F6171]/10 rounded mb-6 flex items-center justify-center">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </div>
                    <h4 class="font-serif text-xl text-[#052730] mb-3">Restaurant Module</h4>
                    <p class="text-sm text-gray-600 leading-relaxed">
                        Kitchen Order Tickets (KOT), Kitchen Displays, order types (Dine-in, Takeaway), and per-item tax toggles.
                    </p>
                </div>

                <!-- Feature 6 -->
                <div class="card-solid p-8 rounded-lg fade-in-up" style="transition-delay: 200ms;">
                    <div class="w-10 h-10 bg-[#0F6171]/10 rounded mb-6 flex items-center justify-center">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="square" stroke-linejoin="miter" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    </div>
                    <h4 class="font-serif text-xl text-[#052730] mb-3">Hardened Thermal Print</h4>
                    <p class="text-sm text-gray-600 leading-relaxed">
                        Optimized for cheap thermal printers. Clear layouts, QR codes, and day-close reports that just work.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Deep Dive / Screenshots -->
    <section class="py-24 bg-[#07333E] text-white border-y border-[#052730]">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            
            <!-- Dashboard UI -->
            <div class="flex flex-col lg:flex-row gap-16 items-center mb-32 fade-in-up">
                <div class="w-full lg:w-1/2 relative">
                    <div class="absolute inset-0 bg-[#052730] transform -translate-x-3 translate-y-3 rounded-lg border border-white/10"></div>
                    <img src="{{ asset('images/screenshots/pos-dash.jpg') }}" alt="NestPOS Dashboard" class="relative z-10 w-full border border-white/15 rounded-lg shadow-xl" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIHZpZXdCb3g9IjAgMCA4MDAgNTAwIj48cmVjdCBmaWxsPSIjZjNmMTRiIiB3aWR0aD0iODAwIiBoZWlnaHQ9IjUwMCIvPjx0ZXh0IGZpbGw9IiM5Y2EzYWYiIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBmb250LXNpemU9IjMwIiBkeT0iMTAuNSIgZm9udC13ZWlnaHQ9ImJvbGQiIHg9IjUwJSIgeT0iNTAlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5OZXN0UE9TIERhc2hib2FyZDwvdGV4dD48L3N2Zz4='">
                </div>
                <div class="w-full lg:w-1/2">
                    <h3 class="text-3xl font-serif mb-6 text-white">Complete Store Management</h3>
                    <p class="text-white/70 leading-relaxed mb-8">Track profit and business intelligence from anywhere. Manage inventory, view the customer ledger, and handle multi-branch operations from a single dashboard.</p>
                    <ul class="space-y-4">
                        <li class="flex items-start">
                            <svg class="w-5 h-5 mr-3 text-[#2EA0B3] mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span class="text-sm text-white/80">Real-time Inventory Management</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 mr-3 text-[#2EA0B3] mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span class="text-sm text-white/80">Multi-branch Support</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 mr-3 text-[#2EA0B3] mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span class="text-sm text-white/80">Detailed Day-Close Reports</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Transactions UI -->
            <div class="flex flex-col lg:flex-row-reverse gap-16 items-center fade-in-up">
                <div class="w-full lg:w-1/2 relative">
                    <div class="absolute inset-0 bg-[#052730] transform translate-x-3 translate-y-3 rounded-lg border border-white/10"></div>
                    <img src="{{ asset('images/screenshots/pos-tx.jpg') }}" alt="NestPOS Transactions" class="relative z-10 w-full border border-white/15 rounded-lg shadow-xl" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIHZpZXdCb3g9IjAgMCA4MDAgNTAwIj48cmVjdCBmaWxsPSIjZjNmMTRiIiB3aWR0aD0iODAwIiBoZWlnaHQ9IjUwMCIvPjx0ZXh0IGZpbGw9IiM5Y2EzYWYiIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBmb250LXNpemU9IjMwIiBkeT0iMTAuNSIgZm9udC13ZWlnaHQ9ImJvbGQiIHg9IjUwJSIgeT0iNTAlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5OZXN0UE9TIFRyYW5zYWN0aW9uczwvdGV4dD48L3N2Zz4='">
                </div>
                <div class="w-full lg:w-1/2">
                    <h3 class="text-3xl font-serif mb-6 text-white">Transactions & Ledgers</h3>
                    <p class="text-white/70 leading-relaxed mb-8">Review every transaction instantly. Convert provisional local bills to permanent ones, manage refunds, and track the customer ledger accurately.</p>
                    <ul class="space-y-4">
                        <li class="flex items-start">
                            <svg class="w-5 h-5 mr-3 text-[#2EA0B3] mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span class="text-sm text-white/80">Installable PWA for quick access</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 mr-3 text-[#2EA0B3] mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span class="text-sm text-white/80">Customer Ledger tracking</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 mr-3 text-[#2EA0B3] mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span class="text-sm text-white/80">Multiple visual themes</span>
                        </li>
                    </ul>
                </div>
            </div>

        </div>
    </section>

    <!-- Pricing Section -->
    <section id="editions" class="py-24 bg-white border-b border-gray-200">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-16 fade-in-up">
                <h2 class="text-sm font-bold tracking-widest uppercase text-gray-500 mb-4">Pricing</h2>
                <h3 class="text-3xl sm:text-4xl font-serif text-[#052730] mb-6">Two solid editions.</h3>
                <p class="text-gray-600 leading-relaxed text-lg">Choose PRA Integration for compliance, or Standalone for just the tools. Annual billing only, with a built-in discount.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 max-w-5xl mx-auto">
                
                <!-- Standalone Edition -->
                <div class="card-solid p-8 rounded-lg flex flex-col relative fade-in-up">
                    <div class="inline-block px-3 py-1 bg-gray-100 border border-gray-200 text-gray-700 font-semibold text-xs uppercase tracking-widest mb-6 self-start rounded-full">
                        Standalone Edition
                    </div>
                    <h4 class="text-2xl font-serif text-[#052730] mb-3">No Government Integration</h4>
                    <p class="text-sm text-gray-600 mb-8">The complete point of sale system for shops that don't need PRA compliance.</p>
                    
                    <div class="flex-grow space-y-4">
                        @if(isset($standalonePlans) && $standalonePlans->count())
                            @foreach($standalonePlans as $plan)
                                @php
                                    $perMonth = round($plan->sale_price / 12);
                                    $hasOffer = $plan->sale_percent > 0;
                                    $comparePerMonth = $hasOffer ? round($plan->price / 12) : 0;
                                    $features = is_array($plan->features) ? $plan->features : (is_string($plan->features) ? json_decode($plan->features, true) : []);
                                @endphp
                                <div class="p-5 bg-gray-50 border border-gray-200 rounded-lg">
                                    <div class="flex justify-between items-start mb-2">
                                        <h5 class="font-bold text-gray-900">{{ $plan->name }}</h5>
                                        <div class="text-right">
                                            <div class="font-semibold text-xl text-[#052730]">PKR {{ number_format($plan->sale_price) }}<span class="text-sm text-gray-500 font-normal">/yr</span></div>
                                        </div>
                                    </div>
                                    <p class="text-xs text-gray-500 mb-4">Effective: PKR {{ number_format($perMonth) }}/mo</p>
                                    @if(!empty($features))
                                        <ul class="space-y-2.5 mt-4 border-t border-gray-200 pt-4">
                                            @foreach(array_slice($features, 0, 3) as $feature)
                                            <li class="flex items-start text-sm text-gray-700">
                                                <svg class="w-4 h-4 mr-2 text-[#0A4D5C] mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                {{ $feature }}
                                            </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endforeach
                        @else
                            <div class="p-6 bg-gray-50 border border-gray-200 rounded-lg text-center text-gray-500 text-sm">
                                Plans loading...
                            </div>
                        @endif
                    </div>
                </div>

                <!-- PRA Integrated Edition -->
                <div class="bg-white border border-gray-200 shadow-xl rounded-lg p-8 flex flex-col relative fade-in-up" style="transition-delay: 100ms;">
                    <div class="absolute -top-3 left-1/2 transform -translate-x-1/2 bg-accent-product text-white px-4 py-1 rounded-full text-xs font-semibold uppercase tracking-wider shadow-sm">
                        Most Popular
                    </div>
                    <div class="inline-block px-3 py-1 bg-purple-50 border border-purple-200 text-accent-product font-semibold text-xs uppercase tracking-widest mb-6 self-start rounded-full">
                        PRA Integrated
                    </div>
                    <h4 class="text-2xl font-serif text-[#052730] mb-3">Full PRA Compliance</h4>
                    <p class="text-sm text-gray-600 mb-8">Includes everything in Standalone, plus automatic Punjab Revenue Authority fiscal integration.</p>
                    
                    <div class="flex-grow space-y-4">
                        @if(isset($plans) && $plans->count())
                            @foreach($plans as $plan)
                                @php
                                    $perMonth = round($plan->sale_price / 12);
                                    $hasOffer = $plan->sale_percent > 0;
                                    $comparePerMonth = $hasOffer ? round($plan->price / 12) : 0;
                                    $features = is_array($plan->features) ? $plan->features : (is_string($plan->features) ? json_decode($plan->features, true) : []);
                                @endphp
                                <div class="p-5 bg-white border border-[#0A4D5C]/20 rounded-lg shadow-sm relative overflow-hidden">
                                    @if($hasOffer)
                                        <div class="absolute top-0 right-0 bg-[#0A4D5C] text-white text-[10px] font-bold px-2 py-0.5 rounded-bl">
                                            {{ $plan->sale_badge }}
                                        </div>
                                    @endif
                                    <div class="flex justify-between items-start mb-2">
                                        <h5 class="font-bold text-gray-900">{{ $plan->name }}</h5>
                                        <div class="text-right">
                                            @if($hasOffer)
                                                <div class="text-[10px] text-gray-400 line-through mb-0.5">PKR {{ number_format($plan->price) }}</div>
                                            @endif
                                            <div class="font-semibold text-xl text-[#0A4D5C]">PKR {{ number_format($plan->sale_price) }}<span class="text-sm text-gray-500 font-normal">/yr</span></div>
                                        </div>
                                    </div>
                                    <p class="text-xs text-gray-500 mb-4">Effective: PKR {{ number_format($perMonth) }}/mo</p>
                                    @if(!empty($features))
                                        <ul class="space-y-2.5 mt-4 border-t border-gray-100 pt-4">
                                            @foreach(array_slice($features, 0, 4) as $feature)
                                            <li class="flex items-start text-sm text-gray-700">
                                                <svg class="w-4 h-4 mr-2 text-[#0A4D5C] mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                {{ $feature }}
                                            </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endforeach
                        @else
                            <div class="p-6 bg-gray-50 border border-gray-200 rounded-lg text-center text-gray-500 text-sm">
                                Plans loading...
                            </div>
                        @endif
                    </div>
                </div>

            </div>
            
            <div class="mt-16 text-center fade-in-up" style="transition-delay: 200ms;">
                <a href="/pos/register" class="btn-solid bg-[#052730] text-white hover:bg-[#0A4D5C] px-8 py-4 text-base">
                    Start Your Free Trial
                </a>
                <p class="mt-4 text-xs font-medium text-gray-500">3-day free trial. No credit card required.</p>
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section class="py-24 bg-[#FDFBF7]">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16 fade-in-up">
                <h2 class="text-sm font-bold tracking-widest uppercase text-gray-500 mb-4">FAQ</h2>
                <h3 class="text-3xl font-serif text-[#052730]">Straight answers</h3>
            </div>
            <div class="space-y-4 fade-in-up" x-data="{ open: null }">
                <div class="card-solid rounded-lg">
                    <button @click="open = (open === 1 ? null : 1)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-gray-900 text-sm">How does the PRA connection actually work?</span>
                        <svg class="w-4 h-4 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 1 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 1" x-collapse class="px-5 pb-5 text-sm text-gray-600 leading-relaxed border-t border-gray-100 pt-4 mt-2">Two modes. Cloud mode submits bills straight from our servers to PRA. Fiscal-device mode runs a small desktop agent on your counter PC for shops whose PRA registration requires the local fiscal service — the system queues bills and the agent fiscalizes them automatically.</div>
                </div>
                <div class="card-solid rounded-lg">
                    <button @click="open = (open === 2 ? null : 2)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-gray-900 text-sm">Does it work for restaurants?</span>
                        <svg class="w-4 h-4 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 2 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 2" x-collapse class="px-5 pb-5 text-sm text-gray-600 leading-relaxed border-t border-gray-100 pt-4 mt-2">Yes — a full restaurant module: dine-in / takeaway / delivery order types, table management, kitchen order tickets and held orders, all on the same keyboard-fast sale screen.</div>
                </div>
                <div class="card-solid rounded-lg">
                    <button @click="open = (open === 3 ? null : 3)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-gray-900 text-sm">What if my internet goes down mid-day?</span>
                        <svg class="w-4 h-4 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 3 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 3" x-collapse class="px-5 pb-5 text-sm text-gray-600 leading-relaxed border-t border-gray-100 pt-4 mt-2">Keep selling. Bills print offline and sync to PRA automatically when the connection returns — no manual re-entry, no lost sales.</div>
                </div>
                <div class="card-solid rounded-lg">
                    <button @click="open = (open === 4 ? null : 4)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-gray-900 text-sm">Can cashiers work without touching the mouse?</span>
                        <svg class="w-4 h-4 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 4 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 4" x-collapse class="px-5 pb-5 text-sm text-gray-600 leading-relaxed border-t border-gray-100 pt-4 mt-2">That's the whole point. Guided keyboard billing walks the cashier from customer to items to payment on Enter alone, with barcode scanning, quick manual entry and shortcut keys for tax and discounts.</div>
                </div>
                <div class="card-solid rounded-lg">
                    <button @click="open = (open === 5 ? null : 5)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-gray-900 text-sm">Is there a version without PRA integration?</span>
                        <svg class="w-4 h-4 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 5 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 5" x-collapse class="px-5 pb-5 text-sm text-gray-600 leading-relaxed border-t border-gray-100 pt-4 mt-2">Yes — the Standalone edition. Same POS, same speed, zero government integration, at a lower annual price. You can upgrade a standalone shop to full PRA mode later; the switch is one-way.</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-[#052730] border-t border-[#0A4D5C] py-12">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row justify-between items-center">
            <div class="flex items-center space-x-2 mb-4 md:mb-0">
                <img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" alt="TaxNest" class="h-6 w-auto opacity-50">
            </div>
            <div class="text-white/40 text-sm font-medium">
                &copy; {{ date('Y') }} TaxNest. All rights reserved.
            </div>
            <div class="flex space-x-6 mt-4 md:mt-0 text-sm font-medium text-white/40">
                <a href="/" class="hover:text-white transition-colors">Main Site</a>
                <a href="/pos/login" class="hover:text-white transition-colors">Log In</a>
            </div>
        </div>
    </footer>

    <x-whatsapp-support />

    <!-- Intersection Observer for fade-in animations -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1 });

            document.querySelectorAll('.fade-in-up').forEach(el => {
                observer.observe(el);
            });
            
            // Trigger initial visible elements immediately
            setTimeout(() => {
                document.querySelectorAll('.fade-in-up').forEach(el => {
                    const rect = el.getBoundingClientRect();
                    if (rect.top < window.innerHeight) {
                        el.classList.add('is-visible');
                    }
                });
            }, 100);
        });
    </script>
</body>
</html>