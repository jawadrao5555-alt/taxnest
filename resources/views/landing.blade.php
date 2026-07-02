<!DOCTYPE html>
<html lang="en" class="overflow-x-hidden scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="stylesheet" href="{{ asset('css/mobile.css?v=2.5') }}">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <title>TaxNest — Pakistan's Most Delightful Tax Platform</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500..800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
    <style>
        :root {
            --cream: #FFFDF8;
            --emerald-ink: #064E3B;
            --emerald-pop: #10B981;
            --purple-pop: #8B5CF6;
            --blue-pop: #3B82F6;
            --saffron: #F59E0B;
        }

        body { 
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif; 
            background-color: var(--cream);
            color: #1F2937;
        }

        h1, h2, h3, h4, h5, h6, .font-bricolage {
            font-family: 'Bricolage Grotesque', system-ui, sans-serif;
        }

        [x-cloak] { display: none !important; }

        /* Delightful bouncy reveals */
        .reveal { 
            opacity: 0; 
            transform: translateY(30px); 
            transition: opacity 0.8s ease, transform 0.8s cubic-bezier(0.34, 1.56, 0.64, 1); 
        }
        .reveal.active { 
            opacity: 1; 
            transform: translateY(0); 
        }

        /* Tactile, playful solid cards - NO faint glows! */
        .card-tactile {
            background: #ffffff;
            border: 2px solid #F3F4F6;
            border-radius: 1.5rem;
            box-shadow: 0 6px 0 0 #F3F4F6;
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .card-tactile:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 0 0 #E5E7EB;
            border-color: #E5E7EB;
        }

        /* Themed tactile cards */
        .card-emerald { border-color: #A7F3D0; box-shadow: 0 6px 0 0 #D1FAE5; }
        .card-emerald:hover { border-color: #34D399; box-shadow: 0 10px 0 0 #6EE7B7; }

        .card-purple { border-color: #DDD6FE; box-shadow: 0 6px 0 0 #EDE9FE; }
        .card-purple:hover { border-color: #A78BFA; box-shadow: 0 10px 0 0 #C4B5FD; }

        .card-blue { border-color: #BFDBFE; box-shadow: 0 6px 0 0 #DBEAFE; }
        .card-blue:hover { border-color: #60A5FA; box-shadow: 0 10px 0 0 #93C5FD; }

        /* Chunky buttons */
        .btn-chunky {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            border-radius: 1rem;
            transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .btn-chunky:hover {
            transform: translateY(-2px) scale(1.02);
        }
        .btn-chunky:active {
            transform: translateY(2px) scale(0.98);
        }

        /* Rich Background Pattern */
        .bg-pattern-dots {
            background-image: radial-gradient(#E5E7EB 2px, transparent 2px);
            background-size: 32px 32px;
        }

        /* Wavy divider */
        .wavy-divider {
            position: absolute;
            left: 0;
            width: 100%;
            overflow: hidden;
            line-height: 0;
        }
        .wavy-divider svg {
            display: block;
            width: calc(100% + 1.3px);
            height: 40px;
        }

        /* Floating elements animation */
        @keyframes float-bouncy {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-15px) rotate(2deg); }
        }
        .animate-float-bouncy {
            animation: float-bouncy 5s ease-in-out infinite;
        }
        .animate-float-delayed {
            animation: float-bouncy 6s ease-in-out infinite 1s;
        }
        .hide-scrollbar { scrollbar-width: none; -ms-overflow-style: none; }
        .hide-scrollbar::-webkit-scrollbar { display: none; }
    </style>
    <noscript><style>.reveal{opacity:1!important;transform:none!important}</style></noscript>
</head>
<body class="antialiased overflow-x-hidden">

    <!-- Navbar -->
    <nav class="fixed top-0 w-full z-50 bg-white/90 backdrop-blur-md border-b-2 border-gray-100">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-[70px]">
                <a href="/" class="flex items-center gap-2 group">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500 flex items-center justify-center transform transition group-hover:rotate-12 group-hover:scale-110 shadow-[0_4px_0_0_#047857]">
                        <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                    </div>
                    <span class="text-2xl font-bricolage font-extrabold text-gray-900 tracking-tight">TaxNest</span>
                </a>

                <div class="hidden md:flex items-center gap-3">
                    <a href="/digital-invoice" class="px-4 py-2 text-sm font-bold text-emerald-700 bg-emerald-100 rounded-xl hover:bg-emerald-200 hover:-translate-y-1 transition">Digital Invoice</a>
                    <a href="/pos" class="px-4 py-2 text-sm font-bold text-purple-700 bg-purple-100 rounded-xl hover:bg-purple-200 hover:-translate-y-1 transition">PRA POS</a>
                    <a href="/fbr-pos-landing" class="px-4 py-2 text-sm font-bold text-blue-700 bg-blue-100 rounded-xl hover:bg-blue-200 hover:-translate-y-1 transition">FBR POS</a>
                </div>

                <div class="flex items-center gap-3">
                    <a href="/login" class="hidden sm:inline-block px-5 py-2.5 text-sm font-bold text-gray-700 hover:text-gray-900 transition">Log In</a>
                    <a href="/register" class="btn-chunky px-6 py-2.5 text-sm bg-gray-900 text-white shadow-[0_4px_0_0_#111827] hover:shadow-[0_6px_0_0_#111827]">
                        Start Free Trial
                    </a>
                </div>
            </div>
            
            <!-- Mobile nav pill row -->
            <div class="md:hidden flex items-center justify-between pb-3 pt-1 gap-2 overflow-x-auto hide-scrollbar">
                <a href="/digital-invoice" class="flex-none px-3 py-1.5 text-[11px] font-bold text-emerald-700 bg-emerald-100 rounded-lg">Digital Invoice</a>
                <a href="/pos" class="flex-none px-3 py-1.5 text-[11px] font-bold text-purple-700 bg-purple-100 rounded-lg">PRA POS</a>
                <a href="/fbr-pos-landing" class="flex-none px-3 py-1.5 text-[11px] font-bold text-blue-700 bg-blue-100 rounded-lg">FBR POS</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="relative pt-32 sm:pt-44 pb-20 sm:pb-28 bg-pattern-dots border-b-2 border-gray-100 overflow-hidden">
        <!-- Playful Background Blobs (Not faint glows, distinct crisp shapes) -->
        <div class="absolute top-24 left-10 w-32 h-32 bg-emerald-200 rounded-full mix-blend-multiply opacity-50 animate-float-bouncy blur-xl"></div>
        <div class="absolute top-40 right-10 w-40 h-40 bg-amber-300 rounded-full mix-blend-multiply opacity-30 animate-float-delayed blur-xl"></div>
        <div class="absolute bottom-10 left-1/3 w-48 h-48 bg-blue-200 rounded-full mix-blend-multiply opacity-40 animate-float-bouncy blur-xl"></div>

        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10 text-center">
            
            <div class="inline-flex items-center gap-2 px-4 py-2 bg-white border-2 border-emerald-200 rounded-full text-sm font-bold text-emerald-800 mb-8 shadow-[0_4px_0_0_#D1FAE5] reveal">
                <span class="flex h-3 w-3 relative">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500"></span>
                </span>
                FBR & PRA Compliant Engine
            </div>

            <h1 class="text-[40px] sm:text-[64px] lg:text-[76px] font-bricolage font-extrabold text-gray-900 leading-[1.05] tracking-tight mb-8 reveal" style="transition-delay: 100ms;">
                Filing taxes doesn't <br class="hidden sm:block">
                have to be <span class="text-transparent bg-clip-text bg-gradient-to-r from-emerald-500 to-blue-500">boring.</span>
            </h1>

            <p class="text-lg sm:text-xl text-gray-600 font-medium max-w-2xl mx-auto leading-relaxed mb-10 reveal" style="transition-delay: 200ms;">
                Join the bustling community of Pakistani retailers, pharmacies, and restaurants who bill effortlessly every single day. Real-time sync, zero downtime, pure peace of mind.
            </p>

            <div class="flex flex-col sm:flex-row items-center justify-center gap-4 reveal" style="transition-delay: 300ms;">
                <a href="#products" class="btn-chunky w-full sm:w-auto px-8 py-4 text-lg bg-emerald-500 text-white shadow-[0_6px_0_0_#047857] hover:bg-emerald-400">
                    Explore Products
                    <svg class="w-5 h-5 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </a>
                <a href="/register" class="btn-chunky w-full sm:w-auto px-8 py-4 text-lg bg-white text-gray-900 border-2 border-gray-200 shadow-[0_6px_0_0_#E5E7EB] hover:border-gray-300">
                    Start 3-Day Trial
                </a>
            </div>

            <div class="mt-10 flex flex-wrap items-center justify-center gap-6 text-sm font-bold text-gray-500 reveal" style="transition-delay: 400ms;">
                <span class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center">✓</div>
                    No hardware required
                </span>
                <span class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center">✓</div>
                    Works offline
                </span>
                <span class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center">✓</div>
                    Real-time dashboard
                </span>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="py-16 bg-emerald-900 border-b-8 border-emerald-950">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6 text-center">
                <div class="p-6 reveal">
                    <div class="text-4xl sm:text-5xl font-bricolage font-black text-white mb-2">{{ $stats['total_invoices'] > 0 ? number_format($stats['total_invoices']) : '50,000+' }}</div>
                    <div class="text-emerald-300 font-bold text-sm tracking-wide uppercase">Invoices Processed</div>
                </div>
                <div class="p-6 reveal" style="transition-delay: 100ms;">
                    <div class="text-4xl sm:text-5xl font-bricolage font-black text-white mb-2">{{ $stats['total_companies'] > 0 ? number_format($stats['total_companies']) : '500+' }}</div>
                    <div class="text-emerald-300 font-bold text-sm tracking-wide uppercase">Active Companies</div>
                </div>
                <div class="p-6 reveal" style="transition-delay: 200ms;">
                    <div class="text-4xl sm:text-5xl font-bricolage font-black text-white mb-2">99.9%</div>
                    <div class="text-emerald-300 font-bold text-sm tracking-wide uppercase">Uptime SLA</div>
                </div>
                <div class="p-6 reveal" style="transition-delay: 300ms;">
                    <div class="text-4xl sm:text-5xl font-bricolage font-black text-white mb-2">0</div>
                    <div class="text-emerald-300 font-bold text-sm tracking-wide uppercase">Compliance Fines</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Products Section -->
    <section id="products" class="py-24 sm:py-32">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            
            <div class="text-center max-w-3xl mx-auto mb-16 reveal">
                <h2 class="text-3xl sm:text-5xl font-bricolage font-black text-gray-900 mb-6">Three Isolated Products. <br>One Joyful Platform.</h2>
                <p class="text-lg text-gray-600 font-medium">Whether you need bulk digital invoicing for your enterprise or a lightning-fast POS for your retail shop, we have a purpose-built tool for you.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                <!-- Digital Invoice -->
                <div class="card-tactile card-emerald p-8 relative reveal flex flex-col h-full">
                    <div class="w-16 h-16 bg-emerald-100 rounded-2xl flex items-center justify-center mb-6 shadow-inner">
                        <svg class="w-8 h-8 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <span class="absolute top-8 right-8 px-3 py-1 bg-gray-900 text-white text-xs font-bold rounded-lg tracking-wide uppercase">Enterprise</span>
                    
                    <h3 class="text-2xl font-bricolage font-bold text-gray-900 mb-2">Digital Invoice</h3>
                    <p class="text-emerald-600 font-bold text-sm mb-4">FBR API v1.12 Integration</p>
                    <p class="text-gray-600 font-medium mb-8 flex-grow">Designed for traders and distributors. Create invoices, automatically fetch HS codes via our AI engine, and sync with FBR without lifting a finger.</p>
                    
                    <ul class="space-y-3 mb-8">
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-emerald-100 text-emerald-600 rounded flex items-center justify-center mr-3">✓</div> AI HS Code matching
                        </li>
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-emerald-100 text-emerald-600 rounded flex items-center justify-center mr-3">✓</div> Secure QR Code receipts
                        </li>
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-emerald-100 text-emerald-600 rounded flex items-center justify-center mr-3">✓</div> Automated daily reporting
                        </li>
                    </ul>

                    <a href="/digital-invoice" class="btn-chunky w-full py-3.5 bg-emerald-50 text-emerald-700 border-2 border-emerald-200 hover:bg-emerald-100 hover:border-emerald-300 mt-auto">
                        See Details
                    </a>
                </div>

                <!-- PRA POS -->
                <div class="card-tactile card-purple p-8 relative reveal flex flex-col h-full" style="transition-delay: 100ms;">
                    <div class="w-16 h-16 bg-purple-100 rounded-2xl flex items-center justify-center mb-6 shadow-inner">
                        <svg class="w-8 h-8 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    <span class="absolute top-8 right-8 px-3 py-1 bg-purple-600 text-white text-xs font-bold rounded-lg tracking-wide uppercase shadow-[0_3px_0_0_#5B21B6]">Bazaar Favorite</span>
                    
                    <h3 class="text-2xl font-bricolage font-bold text-gray-900 mb-2">PRA POS</h3>
                    <p class="text-purple-600 font-bold text-sm mb-4">Punjab Revenue Authority</p>
                    <p class="text-gray-600 font-medium mb-8 flex-grow">A lightning-fast, highly responsive point of sale for Punjab-based restaurants, salons, and retail shops. Works flawlessly offline.</p>
                    
                    <ul class="space-y-3 mb-8">
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-purple-100 text-purple-600 rounded flex items-center justify-center mr-3">✓</div> IMS API v1.2 Certified
                        </li>
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-purple-100 text-purple-600 rounded flex items-center justify-center mr-3">✓</div> Thermal printer ready
                        </li>
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-purple-100 text-purple-600 rounded flex items-center justify-center mr-3">✓</div> Background sync engine
                        </li>
                    </ul>

                    <a href="/pos" class="btn-chunky w-full py-3.5 bg-purple-50 text-purple-700 border-2 border-purple-200 hover:bg-purple-100 hover:border-purple-300 mt-auto">
                        See Details
                    </a>
                </div>

                <!-- FBR POS -->
                <div class="card-tactile card-blue p-8 relative reveal flex flex-col h-full" style="transition-delay: 200ms;">
                    <div class="w-16 h-16 bg-blue-100 rounded-2xl flex items-center justify-center mb-6 shadow-inner">
                        <svg class="w-8 h-8 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    </div>
                    
                    <h3 class="text-2xl font-bricolage font-bold text-gray-900 mb-2">FBR POS</h3>
                    <p class="text-blue-600 font-bold text-sm mb-4">Tier-1 Retailers</p>
                    <p class="text-gray-600 font-medium mb-8 flex-grow">The ultimate billing terminal for Tier-1 retailers across Pakistan. Multi-branch support, stock tracking, and bulletproof compliance.</p>
                    
                    <ul class="space-y-3 mb-8">
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-blue-100 text-blue-600 rounded flex items-center justify-center mr-3">✓</div> Fiscal device integration
                        </li>
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-blue-100 text-blue-600 rounded flex items-center justify-center mr-3">✓</div> Barcode scanner support
                        </li>
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-blue-100 text-blue-600 rounded flex items-center justify-center mr-3">✓</div> Advanced inventory control
                        </li>
                    </ul>

                    <a href="/fbr-pos-landing" class="btn-chunky w-full py-3.5 bg-blue-50 text-blue-700 border-2 border-blue-200 hover:bg-blue-100 hover:border-blue-300 mt-auto">
                        See Details
                    </a>
                </div>

            </div>
        </div>
    </section>

    <!-- Feature Highlights -->
    <section class="py-20 bg-[#1F2937] text-white overflow-hidden relative">
        <div class="absolute inset-0 bg-pattern-dots opacity-10"></div>
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            
            <div class="flex flex-col md:flex-row items-center justify-between gap-12">
                <div class="w-full md:w-1/2 reveal">
                    <h2 class="text-3xl sm:text-5xl font-bricolage font-black mb-6 leading-tight">Built to handle the heat of the Bazaar.</h2>
                    <p class="text-lg text-gray-300 font-medium mb-8">
                        Whether your internet connection drops during a rush hour, or your thermal printer gets jammed, TaxNest keeps your business moving. 
                    </p>
                    
                    <div class="space-y-6">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 bg-gray-800 rounded-xl flex items-center justify-center flex-shrink-0 border border-gray-700">
                                <svg class="w-6 h-6 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            </div>
                            <div>
                                <h4 class="text-xl font-bold font-bricolage mb-1">Unbreakable Offline Mode</h4>
                                <p class="text-sm text-gray-400 font-medium">Keep billing even when the internet dies. We automatically queue and securely push to FBR/PRA once you're back online.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 bg-gray-800 rounded-xl flex items-center justify-center flex-shrink-0 border border-gray-700">
                                <svg class="w-6 h-6 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            </div>
                            <div>
                                <h4 class="text-xl font-bold font-bricolage mb-1">Always Compliant</h4>
                                <p class="text-sm text-gray-400 font-medium">Tax rules change. We update them instantly on the server side so you never have to worry about calculating the wrong rate.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="w-full md:w-1/2 reveal" style="transition-delay: 200ms;">
                    <!-- A beautiful solid UI representation -->
                    <div class="bg-gray-800 p-6 rounded-[2rem] border-4 border-gray-700 shadow-2xl relative">
                        <div class="absolute -top-4 -right-4 bg-emerald-500 text-white font-bold text-sm px-4 py-2 rounded-full rotate-6 border-2 border-white shadow-lg">
                            Live Sync Active
                        </div>
                        <div class="flex justify-between items-center mb-6 border-b border-gray-700 pb-4">
                            <div class="font-bricolage font-bold text-xl">TaxNest Terminal</div>
                            <div class="px-3 py-1 bg-gray-900 rounded-lg text-xs font-bold text-gray-400 border border-gray-700">POS-01</div>
                        </div>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center p-4 bg-gray-900 rounded-xl border border-gray-700">
                                <div>
                                    <div class="font-bold text-white mb-1">Invoice #INV-2026-904</div>
                                    <div class="text-xs text-gray-400">Total: Rs. 14,500.00</div>
                                </div>
                                <div class="px-3 py-1 bg-emerald-900/50 text-emerald-400 text-xs font-bold rounded flex items-center gap-1 border border-emerald-800">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Synced
                                </div>
                            </div>
                            <div class="flex justify-between items-center p-4 bg-gray-900 rounded-xl border border-gray-700">
                                <div>
                                    <div class="font-bold text-white mb-1">Invoice #INV-2026-905</div>
                                    <div class="text-xs text-gray-400">Total: Rs. 2,150.00</div>
                                </div>
                                <div class="px-3 py-1 bg-amber-500/20 text-amber-600 text-xs font-bold rounded flex items-center gap-1 border border-amber-400/40">
                                    <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Syncing
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- Testimonials / Proof -->
    <section class="py-24 bg-white border-b-2 border-gray-100">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16 reveal">
                <h2 class="text-3xl sm:text-4xl font-bricolage font-black text-gray-900">Loved by Pakistani Shopkeepers</h2>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Testimonial 1 -->
                <div class="card-tactile p-8 reveal">
                    <div class="flex items-center gap-1 mb-6 text-amber-400">
                        <svg class="w-5 h-5 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <svg class="w-5 h-5 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <svg class="w-5 h-5 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <svg class="w-5 h-5 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <svg class="w-5 h-5 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    </div>
                    <p class="text-gray-700 font-medium mb-6 leading-relaxed">"We switched from manual FBR filing to TaxNest and saved 15+ hours per month. The HS Intelligence Engine alone is worth the subscription."</p>
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center font-bold text-emerald-800">A</div>
                        <div>
                            <div class="font-bold text-gray-900">Ahmed K.</div>
                            <div class="text-xs text-gray-500 font-medium">CEO, Textile Exports Ltd</div>
                        </div>
                    </div>
                </div>

                <!-- Testimonial 2 -->
                <div class="card-tactile p-8 reveal" style="transition-delay: 100ms;">
                    <div class="flex items-center gap-1 mb-6 text-amber-400">
                        <svg class="w-5 h-5 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <svg class="w-5 h-5 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <svg class="w-5 h-5 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <svg class="w-5 h-5 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <svg class="w-5 h-5 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    </div>
                    <p class="text-gray-700 font-medium mb-6 leading-relaxed">"NestPOS transformed our retail billing. PRA compliance was a nightmare before — now it's completely automatic. Highly recommended."</p>
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-purple-100 flex items-center justify-center font-bold text-purple-800">F</div>
                        <div>
                            <div class="font-bold text-gray-900">Fatima S.</div>
                            <div class="text-xs text-gray-500 font-medium">Owner, Fashion Hub POS</div>
                        </div>
                    </div>
                </div>

                <!-- Testimonial 3 -->
                <div class="card-tactile p-8 reveal" style="transition-delay: 200ms;">
                    <div class="flex items-center gap-1 mb-6 text-amber-400">
                        <svg class="w-5 h-5 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <svg class="w-5 h-5 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <svg class="w-5 h-5 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <svg class="w-5 h-5 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <svg class="w-5 h-5 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    </div>
                    <p class="text-gray-700 font-medium mb-6 leading-relaxed">"The FBR POS module handles everything from inventory control to tax reporting. Our accountant is finally happy, and so are our floor managers."</p>
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center font-bold text-blue-800">R</div>
                        <div>
                            <div class="font-bold text-gray-900">Rahim M.</div>
                            <div class="text-xs text-gray-500 font-medium">Director, Electronics Store</div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- Final CTA -->
    <section class="py-24 relative overflow-hidden">
        <div class="absolute inset-0 bg-emerald-500"></div>
        <div class="absolute inset-0 bg-pattern-dots mix-blend-multiply opacity-20"></div>
        <div class="max-w-[800px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10 text-center text-white reveal">
            <h2 class="text-4xl sm:text-6xl font-bricolage font-black mb-6">Ready to file the fun way?</h2>
            <p class="text-xl font-medium text-emerald-100 mb-10 max-w-2xl mx-auto">Join the happiest tax compliance platform in Pakistan. Get set up in under 5 minutes with our 3-day free trial.</p>
            <a href="/register" class="btn-chunky px-10 py-5 text-xl bg-white text-emerald-900 shadow-[0_8px_0_0_#10B981] hover:shadow-[0_12px_0_0_#10B981] hover:-translate-y-2">
                Start Your Free Trial
            </a>
            <p class="mt-8 text-sm font-bold text-emerald-200">No credit card required • Cancel anytime</p>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-white border-t-2 border-gray-100 pt-16 pb-8">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-10 mb-12">
                <div class="md:col-span-2">
                    <a href="/" class="flex items-center gap-2 mb-4">
                        <div class="w-8 h-8 rounded-lg bg-emerald-500 flex items-center justify-center shadow-[0_2px_0_0_#047857]">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                        </div>
                        <span class="text-xl font-bricolage font-extrabold text-gray-900 tracking-tight">TaxNest</span>
                    </a>
                    <p class="text-gray-500 font-medium text-sm max-w-xs">Pakistan's most delightful tax compliance platform for FBR and PRA digital invoicing & POS systems.</p>
                </div>
                <div>
                    <h4 class="font-bold text-gray-900 mb-4">Products</h4>
                    <ul class="space-y-3 text-sm font-medium text-gray-500">
                        <li><a href="/digital-invoice" class="hover:text-emerald-600 transition">Digital Invoice</a></li>
                        <li><a href="/pos" class="hover:text-purple-600 transition">PRA POS</a></li>
                        <li><a href="/fbr-pos-landing" class="hover:text-blue-600 transition">FBR POS</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold text-gray-900 mb-4">Company</h4>
                    <ul class="space-y-3 text-sm font-medium text-gray-500">
                        <li><a href="/login" class="hover:text-gray-900 transition">Log In</a></li>
                        <li><a href="/register" class="hover:text-gray-900 transition">Sign Up</a></li>
                        <li><a href="mailto:support@taxnest.com" class="hover:text-gray-900 transition">Contact Support</a></li>
                    </ul>
                </div>
            </div>
            <div class="pt-8 border-t border-gray-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                <p class="text-gray-400 text-xs font-medium">© {{ date('Y') }} TaxNest. All rights reserved.</p>
                <p class="text-gray-400 text-xs font-medium">Lahore, Pakistan</p>
            </div>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (!('IntersectionObserver' in window)) {
                document.querySelectorAll('.reveal').forEach(el => el.classList.add('active'));
                return;
            }
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('active');
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
            
            document.querySelectorAll('.reveal').forEach(el => observer.observe(el));
        });
    </script>
    <x-whatsapp-support />
</body>
</html>