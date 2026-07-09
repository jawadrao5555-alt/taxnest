<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#052730">
    <title>TaxNest — Tax Compliance Platform for Pakistan</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=playfair-display:400,600,700|inter:400,500,600,700,800&display=swap" rel="stylesheet">
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
        h1, h2, h3, .font-serif {
            font-family: 'Playfair Display', serif;
        }
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

        /* Product Accents */
        .accent-emerald { color: #059669; }
        .bg-accent-emerald { background-color: #059669; }
        .border-accent-emerald { border-color: #059669; }
        
        .accent-purple { color: #7C3AED; }
        .bg-accent-purple { background-color: #7C3AED; }
        .border-accent-purple { border-color: #7C3AED; }
        
        .accent-blue { color: #2563EB; }
        .bg-accent-blue { background-color: #2563EB; }
        .border-accent-blue { border-color: #2563EB; }

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
    </style>
</head>
<body x-data="{ scrolled: false }" @scroll.window="scrolled = (window.pageYOffset > 20)">

    <!-- Navigation -->
    <nav :class="scrolled ? 'nav-scrolled' : 'nav-transparent'" class="fixed top-0 w-full z-50 transition-all duration-300">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <a href="/" class="flex-shrink-0">
                    <img src="{{ asset('images/brand/taxnest-logo.svg') }}" x-show="scrolled" alt="TaxNest" class="h-8 w-auto">
                    <img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" x-show="!scrolled" alt="TaxNest" class="h-8 w-auto">
                </a>
                <div class="hidden md:flex items-center space-x-8">
                    <a href="/digital-invoice" class="text-sm font-medium transition-colors" :class="scrolled ? 'text-gray-600 hover:text-gray-900' : 'text-gray-200 hover:text-white'">Digital Invoice</a>
                    <a href="/pos" class="text-sm font-medium transition-colors" :class="scrolled ? 'text-gray-600 hover:text-gray-900' : 'text-gray-200 hover:text-white'">NestPOS</a>
                    <a href="/fbr-pos-landing" class="text-sm font-medium transition-colors" :class="scrolled ? 'text-gray-600 hover:text-gray-900' : 'text-gray-200 hover:text-white'">FBR POS</a>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="/login" class="text-sm font-medium transition-colors" :class="scrolled ? 'text-gray-600 hover:text-gray-900' : 'text-gray-200 hover:text-white'">Log In</a>
                    <a href="/register" class="btn-solid" :class="scrolled ? 'btn-primary' : 'bg-white text-[#052730] hover:bg-gray-100'">Get Started</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="relative bg-[#052730] pt-32 pb-20 lg:pt-48 lg:pb-32 overflow-hidden border-b border-[#0A4D5C]">
        <div class="absolute inset-0 bg-grid-pattern-dark opacity-50"></div>
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="max-w-3xl">
                <div class="inline-flex items-center px-3 py-1 bg-white/5 border border-white/10 rounded-full text-xs font-medium text-white/80 tracking-wide uppercase mb-8">
                    <span class="w-1.5 h-1.5 rounded-full bg-[#E7BF3B] mr-2"></span>
                    Tax Compliance Platform
                </div>
                <h1 class="text-4xl sm:text-5xl lg:text-7xl font-serif text-white leading-tight mb-8">
                    Compliance,<br>
                    <span class="text-white/80 italic">Structured for Pakistan.</span>
                </h1>
                <p class="text-lg lg:text-xl text-white/70 leading-relaxed mb-10 max-w-2xl font-light">
                    Three fully isolated products under one roof. FBR Digital Invoicing, PRA Point of Sale, and FBR POS — built by a dedicated team to keep your business compliant with confidence.
                </p>
                <div class="flex flex-col sm:flex-row items-start gap-4">
                    <a href="#products" class="btn-solid bg-[#E7BF3B] text-[#052730] hover:bg-[#F2D06B]">
                        Explore the Platform
                    </a>
                    <div class="text-sm text-white/50 flex flex-col justify-center py-2">
                        <span>3-day free trial.</span>
                        <span>No credit card required.</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    @if(isset($stats['total_invoices']) && $stats['total_invoices'] > 0 || isset($stats['total_companies']) && $stats['total_companies'] > 0)
    <!-- Real Platform Facts -->
    <section class="border-b border-gray-200 bg-white">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="flex flex-wrap items-center justify-between text-sm font-medium text-gray-500 uppercase tracking-widest gap-8">
                @if(isset($stats['total_companies']) && $stats['total_companies'] > 0)
                <div class="flex items-center gap-3">
                    <span class="text-gray-900 font-bold text-lg">{{ $stats['total_companies'] }}</span>
                    <span>Companies Active</span>
                </div>
                @endif
                @if(isset($stats['total_invoices']) && $stats['total_invoices'] > 0)
                <div class="flex items-center gap-3">
                    <span class="text-gray-900 font-bold text-lg">{{ number_format($stats['total_invoices']) }}</span>
                    <span>Invoices Processed</span>
                </div>
                @endif
                <div class="flex items-center gap-3">
                    <span class="w-2 h-2 rounded-full bg-green-500"></span>
                    <span>PRAL API Integrated</span>
                </div>
                <div class="flex items-center gap-3">
                    <span class="w-2 h-2 rounded-full bg-green-500"></span>
                    <span>PRA IMS Integrated</span>
                </div>
            </div>
        </div>
    </section>
    @endif

    <!-- The Products Intro -->
    <section id="products" class="py-24 bg-grid-pattern">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mb-16 fade-in-up">
                <h2 class="text-sm font-bold tracking-widest uppercase text-gray-500 mb-4">The Suite</h2>
                <h3 class="text-3xl sm:text-4xl font-serif text-[#052730] mb-6">Three distinct systems. <br>One standard of engineering.</h3>
                <p class="text-gray-600 leading-relaxed text-lg">
                    TaxNest isn't a generic SaaS tool. It's a suite of compliance products designed specifically for Pakistan's regulatory requirements. Every module operates with complete isolation—ensuring role-based access, multi-branch integrity, and immutable audit logs.
                </p>
            </div>

            <div class="space-y-24">
                
                <!-- Digital Invoice -->
                <div class="flex flex-col lg:flex-row gap-12 items-center fade-in-up">
                    <div class="w-full lg:w-1/2">
                        <div class="bg-gray-100 rounded-lg p-2 border border-gray-200">
                            <img src="{{ asset('images/screenshots/di-dash.jpg') }}" alt="Digital Invoice Dashboard" class="w-full h-auto rounded border border-gray-300 shadow-sm" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIHZpZXdCb3g9IjAgMCA4MDAgNTAwIj48cmVjdCBmaWxsPSIjZjNmMTRiIiB3aWR0aD0iODAwIiBoZWlnaHQ9IjUwMCIvPjx0ZXh0IGZpbGw9IiM5Y2EzYWYiIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBmb250LXNpemU9IjMwIiBkeT0iMTAuNSIgZm9udC13ZWlnaHQ9ImJvbGQiIHg9IjUwJSIgeT0iNTAlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5EaWdpdGFsIEludm9pY2UgU2NyZWVuc2hvdDwvdGV4dD48L3N2Zz4='">
                        </div>
                    </div>
                    <div class="w-full lg:w-1/2 lg:pl-10">
                        <div class="inline-block px-3 py-1 mb-6 border border-gray-200 rounded-full text-xs font-semibold uppercase tracking-widest accent-emerald">
                            FBR Digital Invoicing
                        </div>
                        <h4 class="text-3xl font-serif text-[#052730] mb-4">Digital Invoice</h4>
                        <p class="text-gray-600 mb-6 leading-relaxed">
                            Direct integration with FBR via the PRAL API. Generate, validate, and submit invoices with zero friction. Built for serious volume with an unyielding focus on data integrity.
                        </p>
                        <ul class="space-y-3 mb-8">
                            <li class="flex items-start">
                                <span class="accent-emerald mr-3 text-lg leading-none">•</span>
                                <span class="text-gray-700 text-sm">Sandbox & Production environments</span>
                            </li>
                            <li class="flex items-start">
                                <span class="accent-emerald mr-3 text-lg leading-none">•</span>
                                <span class="text-gray-700 text-sm">HS code intelligence with tax-schedule validation</span>
                            </li>
                            <li class="flex items-start">
                                <span class="accent-emerald mr-3 text-lg leading-none">•</span>
                                <span class="text-gray-700 text-sm">Customer ledgers & formal invoice PDFs</span>
                            </li>
                        </ul>
                        <div class="flex gap-4">
                            <a href="/digital-invoice" class="btn-solid btn-primary">Learn More</a>
                            <a href="/digital-invoice#pricing" class="btn-solid btn-secondary">View Pricing</a>
                        </div>
                    </div>
                </div>

                <hr class="border-gray-200">

                <!-- NestPOS -->
                <div class="flex flex-col lg:flex-row-reverse gap-12 items-center fade-in-up">
                    <div class="w-full lg:w-1/2">
                        <div class="bg-gray-100 rounded-lg p-2 border border-gray-200">
                            <img src="{{ asset('images/screenshots/pos-sale.jpg') }}" alt="NestPOS Sale Screen" class="w-full h-auto rounded border border-gray-300 shadow-sm" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIHZpZXdCb3g9IjAgMCA4MDAgNTAwIj48cmVjdCBmaWxsPSIjZjNmMTRiIiB3aWR0aD0iODAwIiBoZWlnaHQ9IjUwMCIvPjx0ZXh0IGZpbGw9IiM5Y2EzYWYiIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBmb250LXNpemU9IjMwIiBkeT0iMTAuNSIgZm9udC13ZWlnaHQ9ImJvbGQiIHg9IjUwJSIgeT0iNTAlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5OZXN0UE9TIFNjcmVlbnNob3Q8L3RleHQ+PC9zdmc+'">
                        </div>
                    </div>
                    <div class="w-full lg:w-1/2 lg:pr-10">
                        <div class="inline-block px-3 py-1 mb-6 border border-gray-200 rounded-full text-xs font-semibold uppercase tracking-widest accent-purple">
                            Punjab Revenue Authority POS
                        </div>
                        <h4 class="text-3xl font-serif text-[#052730] mb-4">NestPOS</h4>
                        <p class="text-gray-600 mb-6 leading-relaxed">
                            A fast, keyboard-first point of sale built specifically for PRA fiscal integration. Designed for retail and restaurants in Punjab that demand speed at the counter without sacrificing compliance.
                        </p>
                        <ul class="space-y-3 mb-8">
                            <li class="flex items-start">
                                <span class="accent-purple mr-3 text-lg leading-none">•</span>
                                <span class="text-gray-700 text-sm">PRA fiscal integration (cloud & fiscal-device modes)</span>
                            </li>
                            <li class="flex items-start">
                                <span class="accent-purple mr-3 text-lg leading-none">•</span>
                                <span class="text-gray-700 text-sm">Restaurant module with kitchen order tickets</span>
                            </li>
                            <li class="flex items-start">
                                <span class="accent-purple mr-3 text-lg leading-none">•</span>
                                <span class="text-gray-700 text-sm">Barcode scanning, thermal receipt printing, offline auto-sync</span>
                            </li>
                        </ul>
                        <div class="flex gap-4">
                            <a href="/pos" class="btn-solid btn-primary">Learn More</a>
                            <a href="/pos#pricing" class="btn-solid btn-secondary">View Pricing</a>
                        </div>
                    </div>
                </div>

                <hr class="border-gray-200">

                <!-- FBR POS -->
                <div class="flex flex-col lg:flex-row gap-12 items-center fade-in-up">
                    <div class="w-full lg:w-1/2">
                        <div class="bg-gray-100 rounded-lg p-2 border border-gray-200">
                            <img src="{{ asset('images/screenshots/fbr-sale.jpg') }}" alt="FBR POS Sale Screen" class="w-full h-auto rounded border border-gray-300 shadow-sm" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIHZpZXdCb3g9IjAgMCA4MDAgNTAwIj48cmVjdCBmaWxsPSIjZjNmMTRiIiB3aWR0aD0iODAwIiBoZWlnaHQ9IjUwMCIvPjx0ZXh0IGZpbGw9IiM5Y2EzYWYiIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBmb250LXNpemU9IjMwIiBkeT0iMTAuNSIgZm9udC13ZWlnaHQ9ImJvbGQiIHg9IjUwJSIgeT0iNTAlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5GQlIgUE9TIFNjcmVlbnNob3Q8L3RleHQ+PC9zdmc+'">
                        </div>
                    </div>
                    <div class="w-full lg:w-1/2 lg:pl-10">
                        <div class="inline-block px-3 py-1 mb-6 border border-gray-200 rounded-full text-xs font-semibold uppercase tracking-widest accent-blue">
                            FBR Point of Sale
                        </div>
                        <h4 class="text-3xl font-serif text-[#052730] mb-4">FBR POS</h4>
                        <p class="text-gray-600 mb-6 leading-relaxed">
                            A robust retail billing system wired directly to the FBR for real-time submission. Handle high-volume checkouts with an installable PWA designed for uninterrupted operations.
                        </p>
                        <ul class="space-y-3 mb-8">
                            <li class="flex items-start">
                                <span class="accent-blue mr-3 text-lg leading-none">•</span>
                                <span class="text-gray-700 text-sm">Direct FBR POS submission architecture</span>
                            </li>
                            <li class="flex items-start">
                                <span class="accent-blue mr-3 text-lg leading-none">•</span>
                                <span class="text-gray-700 text-sm">Installable PWA for desktop-like performance</span>
                            </li>
                            <li class="flex items-start">
                                <span class="accent-blue mr-3 text-lg leading-none">•</span>
                                <span class="text-gray-700 text-sm">Keyboard-first interface with multi-branch management</span>
                            </li>
                        </ul>
                        <div class="flex gap-4">
                            <a href="/fbr-pos-landing" class="btn-solid btn-primary">Learn More</a>
                            <a href="/fbr-pos-landing#pricing" class="btn-solid btn-secondary">View Pricing</a>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- Architecture & Features -->
    <section class="py-24 bg-white border-t border-gray-200">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto mb-16 fade-in-up">
                <h2 class="text-sm font-bold tracking-widest uppercase text-gray-500 mb-4">Foundation</h2>
                <h3 class="text-3xl font-serif text-[#052730]">Built on immutable standards.</h3>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="card-solid p-8 rounded-lg fade-in-up">
                    <div class="w-10 h-10 bg-gray-100 rounded mb-6 flex items-center justify-center">
                        <svg class="w-5 h-5 text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <h4 class="font-serif text-xl text-[#052730] mb-3">Immutable Audit Logs</h4>
                    <p class="text-sm text-gray-600 leading-relaxed">
                        Every action is recorded and unalterable. From invoice creation to tax rate changes, maintaining a complete paper trail for regulatory scrutiny.
                    </p>
                </div>
                <div class="card-solid p-8 rounded-lg fade-in-up" style="transition-delay: 100ms;">
                    <div class="w-10 h-10 bg-gray-100 rounded mb-6 flex items-center justify-center">
                        <svg class="w-5 h-5 text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    </div>
                    <h4 class="font-serif text-xl text-[#052730] mb-3">Multi-Branch Support</h4>
                    <p class="text-sm text-gray-600 leading-relaxed">
                        Manage a national footprint from a single account. Define branches, assign role-based access, and consolidate reporting across the entire organization.
                    </p>
                </div>
                <div class="card-solid p-8 rounded-lg fade-in-up" style="transition-delay: 200ms;">
                    <div class="w-10 h-10 bg-gray-100 rounded mb-6 flex items-center justify-center">
                        <svg class="w-5 h-5 text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h4 class="font-serif text-xl text-[#052730] mb-3">Offline Resilience</h4>
                    <p class="text-sm text-gray-600 leading-relaxed">
                        Internet drops shouldn't stop your business. Generate bills offline; the system automatically syncs with authorities the moment connectivity returns.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-[#052730] pt-16 pb-8 border-t-4 border-[#0A4D5C]">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-12 mb-16">
                <div class="col-span-1 md:col-span-2">
                    <img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" alt="TaxNest" class="h-8 w-auto mb-6">
                    <p class="text-white/60 text-sm leading-relaxed max-w-sm">
                        Pakistan's definitive tax compliance software. Built by a serious team for real businesses enforcing structural financial integrity.
                    </p>
                </div>
                <div>
                    <h5 class="text-xs font-bold uppercase tracking-widest text-[#E7BF3B] mb-4">Products</h5>
                    <ul class="space-y-3">
                        <li><a href="/digital-invoice" class="text-white/70 hover:text-white text-sm transition">Digital Invoice</a></li>
                        <li><a href="/pos" class="text-white/70 hover:text-white text-sm transition">NestPOS (PRA)</a></li>
                        <li><a href="/fbr-pos-landing" class="text-white/70 hover:text-white text-sm transition">FBR POS</a></li>
                    </ul>
                </div>
                <div>
                    <h5 class="text-xs font-bold uppercase tracking-widest text-[#E7BF3B] mb-4">Platform</h5>
                    <ul class="space-y-3">
                        <li><a href="/login" class="text-white/70 hover:text-white text-sm transition">Sign In</a></li>
                        <li><a href="/register" class="text-white/70 hover:text-white text-sm transition">Create Account</a></li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-white/10 pt-8 flex flex-col md:flex-row items-center justify-between gap-4">
                <p class="text-white/40 text-xs">
                    &copy; {{ date('Y') }} TaxNest. All rights reserved.
                </p>
                <div class="flex items-center space-x-4">
                    <span class="text-white/40 text-xs flex items-center"><span class="w-1.5 h-1.5 rounded-full bg-green-500 mr-2"></span> System Operational</span>
                </div>
            </div>
        </div>
    </footer>

    <script>
        // Intersection Observer for scroll animations
        document.addEventListener('DOMContentLoaded', () => {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        observer.unobserve(entry.target);
                    }
                });
            }, {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            });

            document.querySelectorAll('.fade-in-up').forEach(el => {
                observer.observe(el);
            });
        });
    </script>
    <x-whatsapp-support />
</body>
</html>