<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#052730">
    <title>FBR POS — Bank-Grade Point of Sale by TaxNest</title>
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
            --fbr-blue: #1d4ed8; /* Blue-700 for FBR POS */
            --fbr-blue-hover: #1e40af;
        }
        body { 
            font-family: 'Inter', system-ui, -apple-system, sans-serif; 
            background-color: var(--paper);
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
        }
        h1, h2, h3, h4, h5, h6, .font-serif {
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
        .btn-gold {
            background-color: var(--gold);
            color: #062A33;
            border: 1px solid transparent;
        }
        .btn-gold:hover {
            background-color: #F2D06B;
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
        .btn-fbr {
            background-color: var(--fbr-blue);
            color: #FFFFFF;
            border: 1px solid transparent;
        }
        .btn-fbr:hover {
            background-color: var(--fbr-blue-hover);
        }

        .accent-fbr { color: var(--fbr-blue); }
        .bg-accent-fbr { background-color: var(--fbr-blue); }
        .border-accent-fbr { border-color: var(--fbr-blue); }

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
<body x-data="{ scrolled: false, mobileOpen: false, showLoginModal: false }" @scroll.window="scrolled = (window.pageYOffset > 20)">

    <!-- Login Modal -->
    <div x-show="showLoginModal" x-cloak 
         x-transition.opacity.duration.200ms
         class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm px-4" 
         @click.self="showLoginModal = false" @keydown.escape.window="showLoginModal = false">
        
        <div x-show="showLoginModal" 
             x-transition:enter="transition ease-out duration-200" 
             x-transition:enter-start="opacity-0 scale-95" 
             x-transition:enter-end="opacity-100 scale-100" 
             x-transition:leave="transition ease-in duration-150" 
             x-transition:leave-start="opacity-100 scale-100" 
             x-transition:leave-end="opacity-0 scale-95" 
             class="w-full max-w-md bg-[#FDFBF7] border border-gray-200 rounded-lg shadow-xl overflow-hidden">
            
            <div class="px-6 py-5 border-b border-gray-200 flex justify-between items-center bg-white">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 rounded bg-[#0A4D5C] flex items-center justify-center">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-serif text-[#052730] font-bold">FBR POS Login</h3>
                    </div>
                </div>
                <button @click="showLoginModal = false" class="text-gray-400 hover:text-gray-600 transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            
            <form method="POST" action="/fbr-pos/login" class="p-6 space-y-5 bg-white">
                @csrf
                <div>
                    <label class="block text-sm font-semibold text-[#052730] mb-1.5">Email, Phone, Username, CNIC or NTN</label>
                    <input type="text" name="login" required autofocus 
                           class="w-full px-4 py-2 border border-gray-300 rounded-md text-sm focus:ring-1 focus:ring-[#0A4D5C] focus:border-[#0A4D5C] outline-none transition-colors" 
                           placeholder="Enter your credential">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-[#052730] mb-1.5">Password</label>
                    <input type="password" name="password" required autocomplete="current-password" 
                           class="w-full px-4 py-2 border border-gray-300 rounded-md text-sm focus:ring-1 focus:ring-[#0A4D5C] focus:border-[#0A4D5C] outline-none transition-colors" 
                           placeholder="Enter your password">
                </div>
                
                <div class="flex items-center justify-between pt-1">
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" name="remember" class="rounded border-gray-300 text-[#0A4D5C] focus:ring-[#0A4D5C] w-4 h-4">
                        <span class="ml-2 text-sm text-gray-600 font-medium">Remember me</span>
                    </label>
                    <a href="{{ route('password.request') }}" class="text-sm font-semibold text-[#0A4D5C] hover:text-[#052730] transition">Forgot Password?</a>
                </div>
                
                @if($errors->any())
                <div class="bg-red-50 border border-red-200 rounded-md p-3">
                    @foreach($errors->all() as $error)
                    <p class="text-sm font-medium text-red-600">{{ $error }}</p>
                    @endforeach
                </div>
                @endif
                
                <div class="pt-2">
                    <button type="submit" class="w-full py-2.5 bg-[#052730] hover:bg-[#0A4D5C] text-white rounded-md text-sm font-bold transition-colors">
                        Sign In to POS
                    </button>
                </div>
                
                <div class="text-center pt-3 border-t border-gray-100 mt-5">
                    <span class="text-sm text-gray-500">New retailer?</span>
                    <a href="/fbr-pos/register" class="text-sm font-bold text-[#0A4D5C] hover:text-[#052730] transition ml-1">Start 3-Day Free Trial</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Navigation -->
    <nav :class="(scrolled || mobileOpen) ? 'nav-scrolled' : 'nav-transparent'" class="fixed top-0 w-full z-50 transition-all duration-300">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <div class="flex items-center">
                    <a href="/" class="flex-shrink-0 flex items-center">
                        <img src="{{ asset('images/brand/taxnest-logo.svg') }}" x-show="scrolled || mobileOpen" alt="TaxNest" class="h-8 w-auto">
                        <img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" x-show="!scrolled && !mobileOpen" alt="TaxNest" class="h-8 w-auto">
                        <div class="hidden sm:block w-px h-5 mx-4" :class="scrolled ? 'bg-gray-300' : 'bg-white/20'"></div>
                        <span class="hidden sm:block text-xs font-bold tracking-widest uppercase" :class="scrolled ? 'text-gray-500' : 'text-white/70'">FBR POS</span>
                    </a>
                </div>
                
                <!-- Desktop Nav -->
                <div class="hidden md:flex items-center space-x-8">
                    <a href="#capabilities" class="text-sm font-medium transition-colors" :class="scrolled ? 'text-gray-600 hover:text-gray-900' : 'text-gray-200 hover:text-white'">Capabilities</a>
                    <a href="#compliance" class="text-sm font-medium transition-colors" :class="scrolled ? 'text-gray-600 hover:text-gray-900' : 'text-gray-200 hover:text-white'">Compliance</a>
                    <a href="#pricing" class="text-sm font-medium transition-colors" :class="scrolled ? 'text-gray-600 hover:text-gray-900' : 'text-gray-200 hover:text-white'">Pricing</a>
                    
                    <div class="w-px h-5" :class="scrolled ? 'bg-gray-300' : 'bg-white/20'"></div>
                    
                    <button @click="showLoginModal = true" class="text-sm font-semibold transition-colors" :class="scrolled ? 'text-gray-800 hover:text-[#0A4D5C]' : 'text-white hover:text-gray-200'">Sign In</button>
                    <a href="/fbr-pos/register" class="btn-solid" :class="scrolled ? 'bg-[#052730] text-white hover:bg-[#0A4D5C]' : 'bg-white text-[#052730] hover:bg-gray-100'">Start Free Trial</a>
                </div>

                <!-- Mobile Menu Button -->
                <div class="flex items-center md:hidden">
                    <button @click="mobileOpen = !mobileOpen" class="p-2 -mr-2 focus:outline-none" aria-label="Menu">
                        <svg x-show="!mobileOpen" class="w-6 h-6" :class="scrolled ? 'text-gray-800' : 'text-white'" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                        <svg x-show="mobileOpen" x-cloak class="w-6 h-6 text-gray-800" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Nav -->
        <div x-show="mobileOpen" x-cloak @click.away="mobileOpen = false" class="md:hidden border-t border-gray-200 bg-[#FDFBF7]">
            <div class="px-4 py-4 space-y-1">
                <a href="#capabilities" class="block px-3 py-2.5 rounded text-sm font-semibold text-gray-700 hover:bg-gray-100" @click="mobileOpen = false">Capabilities</a>
                <a href="#compliance" class="block px-3 py-2.5 rounded text-sm font-semibold text-gray-700 hover:bg-gray-100" @click="mobileOpen = false">Compliance</a>
                <a href="#pricing" class="block px-3 py-2.5 rounded text-sm font-semibold text-gray-700 hover:bg-gray-100" @click="mobileOpen = false">Pricing</a>
                <div class="border-t border-gray-200 my-2 pt-2"></div>
                <button @click="showLoginModal = true; mobileOpen = false" class="w-full text-left block px-3 py-2.5 rounded text-sm font-semibold text-gray-700 hover:bg-gray-100">Sign In to POS</button>
                <a href="/fbr-pos/register" class="block px-3 py-2.5 mt-2 rounded text-sm font-semibold text-white bg-[#052730] text-center" @click="mobileOpen = false">Start Free Trial</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="relative bg-[#052730] pt-32 pb-20 lg:pt-48 lg:pb-32 overflow-hidden border-b border-[#0A4D5C]">
        <div class="absolute inset-0 bg-grid-pattern-dark opacity-40"></div>
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 lg:gap-16 items-center">
                <div class="max-w-2xl fade-in-up">
                    <div class="inline-flex items-center px-3 py-1 bg-white/10 border border-white/15 backdrop-blur rounded-full text-xs font-semibold tracking-widest text-white uppercase mb-8">
                        <span class="w-2 h-2 rounded-full bg-accent-fbr mr-2"></span>
                        Direct FBR API Integration
                    </div>
                    
                    <h1 class="text-4xl sm:text-5xl lg:text-6xl font-serif text-white leading-tight mb-6">
                        The Bank-Grade <br>
                        <span class="text-white/80 italic">Point of Sale.</span>
                    </h1>
                    
                    <p class="text-lg text-white/70 mb-10 max-w-xl font-light leading-relaxed">
                        Built for Pakistani retailers registered with the FBR POS integration regime. Every sale is fiscalized and submitted directly from the counter. Sturdy, secure, and precise.
                    </p>
                    
                    <div class="flex flex-col sm:flex-row gap-4 items-start">
                        <a href="/fbr-pos/register" class="btn-solid btn-gold">
                            Start 3-Day Free Trial
                        </a>
                        <button @click="showLoginModal = true" class="btn-solid bg-white/10 text-white border border-white/20 hover:bg-white/20 backdrop-blur">
                            Sign In to Terminal
                        </button>
                    </div>
                    <p class="mt-5 text-sm text-white/50 font-medium">No credit card required for trial.</p>
                </div>
                
                <div class="relative lg:h-[600px] flex items-center justify-center fade-in-up" style="transition-delay: 100ms;">
                    <div class="w-full bg-[#FDFBF7] rounded-lg border border-white/20 shadow-2xl overflow-hidden p-1 backdrop-blur bg-white/10">
                        <div class="bg-[#07333E] rounded border border-white/10 overflow-hidden">
                            <div class="px-4 py-3 flex items-center border-b border-white/10 bg-[#052730]">
                                <div class="flex space-x-2">
                                    <div class="w-2.5 h-2.5 rounded-full bg-white/20"></div>
                                    <div class="w-2.5 h-2.5 rounded-full bg-white/20"></div>
                                    <div class="w-2.5 h-2.5 rounded-full bg-white/20"></div>
                                </div>
                                <div class="mx-auto text-[10px] font-mono text-white/40 tracking-widest uppercase">TAXNEST / FBR POS</div>
                            </div>
                            <img src="{{ asset('images/screenshots/fbr-sale.jpg') }}" alt="FBR POS Sale Screen" class="w-full h-auto object-cover object-top opacity-90" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIHZpZXdCb3g9IjAgMCA4MDAgNTAwIj48cmVjdCBmaWxsPSIjZjNmMTRiIiB3aWR0aD0iODAwIiBoZWlnaHQ9IjUwMCIvPjx0ZXh0IGZpbGw9IiM5Y2EzYWYiIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBmb250LXNpemU9IjMwIiBkeT0iMTAuNSIgZm9udC13ZWlnaHQ9ImJvbGQiIHg9IjUwJSIgeT0iNTAlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5GQlIgUE9TIFNjcmVlbnNob3Q8L3RleHQ+PC9zdmc+'">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Trust Strip -->
    <div class="bg-[#063B47] py-6 border-b border-[#052730]">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap justify-center sm:justify-between items-center gap-6">
                <div class="flex items-center gap-3 bg-white/5 border border-white/10 px-4 py-2 rounded-full backdrop-blur">
                    <span class="w-1.5 h-1.5 rounded-full bg-[#2EA0B3]"></span>
                    <span class="text-xs font-semibold text-white/80 uppercase tracking-widest">FBR Verified API</span>
                </div>
                <div class="flex items-center gap-3 bg-white/5 border border-white/10 px-4 py-2 rounded-full backdrop-blur">
                    <span class="w-1.5 h-1.5 rounded-full bg-[#2EA0B3]"></span>
                    <span class="text-xs font-semibold text-white/80 uppercase tracking-widest">End-to-End Encryption</span>
                </div>
                <div class="flex items-center gap-3 bg-white/5 border border-white/10 px-4 py-2 rounded-full backdrop-blur hidden md:flex">
                    <span class="w-1.5 h-1.5 rounded-full bg-[#2EA0B3]"></span>
                    <span class="text-xs font-semibold text-white/80 uppercase tracking-widest">Offline Auto-Sync</span>
                </div>
                <div class="flex items-center gap-3 bg-white/5 border border-white/10 px-4 py-2 rounded-full backdrop-blur hidden lg:flex">
                    <span class="w-1.5 h-1.5 rounded-full bg-[#2EA0B3]"></span>
                    <span class="text-xs font-semibold text-white/80 uppercase tracking-widest">Bank-Grade Audit Trail</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Editorial Capabilities -->
    <section id="capabilities" class="py-24 bg-grid-pattern border-b border-gray-200">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mb-20 fade-in-up">
                <h2 class="text-sm font-bold tracking-widest uppercase text-gray-500 mb-4">Precision Engineered</h2>
                <h3 class="text-3xl sm:text-4xl font-serif text-[#052730] mb-6">A sturdy billing system.<br>Handles complexity silently.</h3>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-16">
                <!-- Direct FBR -->
                <div class="fade-in-up">
                    <div class="w-12 h-12 bg-[#0F6171]/10 rounded flex items-center justify-center mb-6">
                        <svg class="w-6 h-6 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h4 class="text-xl font-serif font-bold text-[#052730] mb-3">Direct FBR Submission</h4>
                    <p class="text-gray-600 leading-relaxed text-base">
                        Real-time invoice fiscalization. Bills are submitted directly to FBR's API from the sale screen the moment payment is confirmed. No middleman delays.
                    </p>
                </div>
                
                <!-- Offline Resilience -->
                <div class="fade-in-up" style="transition-delay: 100ms;">
                    <div class="w-12 h-12 bg-[#0F6171]/10 rounded flex items-center justify-center mb-6">
                        <svg class="w-6 h-6 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
                    </div>
                    <h4 class="text-xl font-serif font-bold text-[#052730] mb-3">Held Sales & Offline Resilience</h4>
                    <p class="text-gray-600 leading-relaxed text-base">
                        Park a cart for a waiting customer and recall it later. Continue billing smoothly even during temporary network interruptions with intelligent auto-sync.
                    </p>
                </div>
                
                <!-- PIN System -->
                <div class="fade-in-up border-t border-gray-100 pt-16 md:pt-0 md:border-none">
                    <div class="w-12 h-12 bg-[#0F6171]/10 rounded flex items-center justify-center mb-6">
                        <svg class="w-6 h-6 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <h4 class="text-xl font-serif font-bold text-[#052730] mb-3">Confidential PIN System</h4>
                    <p class="text-gray-600 leading-relaxed text-base">
                        Sensitive actions are guarded by a secure PIN. Protect voids, discounts, and reporting access from unauthorized counter staff to maintain financial integrity.
                    </p>
                </div>
                
                <!-- Retry Queue -->
                <div class="fade-in-up border-t border-gray-100 pt-16 md:pt-0 md:border-none">
                    <div class="w-12 h-12 bg-[#0F6171]/10 rounded flex items-center justify-center mb-6">
                        <svg class="w-6 h-6 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </div>
                    <h4 class="text-xl font-serif font-bold text-[#052730] mb-3">Edit & Retry Rejected Bills</h4>
                    <p class="text-gray-600 leading-relaxed text-base">
                        Never lose a transaction to a network error. FBR-rejected bills drop into a specialized queue where they can be reviewed, corrected, and safely resubmitted.
                    </p>
                </div>

                <!-- Keyboard Cart -->
                <div class="fade-in-up border-t border-gray-100 pt-16">
                    <div class="w-12 h-12 bg-[#0F6171]/10 rounded flex items-center justify-center mb-6">
                        <svg class="w-6 h-6 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    </div>
                    <h4 class="text-xl font-serif font-bold text-[#052730] mb-3">Keyboard-Only Cart Flow</h4>
                    <p class="text-gray-600 leading-relaxed text-base">
                        Built for velocity. A unified smart input box handles barcode scanning and text searches seamlessly. The entire checkout flow is fully keyboard navigable.
                    </p>
                </div>
                
                <!-- Tax Config -->
                <div class="fade-in-up border-t border-gray-100 pt-16">
                    <div class="w-12 h-12 bg-[#0F6171]/10 rounded flex items-center justify-center mb-6">
                        <svg class="w-6 h-6 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <h4 class="text-xl font-serif font-bold text-[#052730] mb-3">Per-Product Tax Configuration</h4>
                    <p class="text-gray-600 leading-relaxed text-base">
                        Assign specific tax rates per item or manage exemptions automatically. The compliance engine enforces correct rates without counter-staff intervention.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Compliance Deep Dive (Dark Section) -->
    <section id="compliance" class="py-24 bg-[#052730] border-b border-[#0A4D5C] relative overflow-hidden">
        <div class="absolute inset-0 bg-grid-pattern-dark opacity-10"></div>
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">
                
                <div class="order-1 lg:order-1 fade-in-up">
                    <h2 class="text-sm font-bold tracking-widest uppercase text-white/50 mb-4">Reconciliation</h2>
                    <h3 class="text-3xl sm:text-4xl font-serif text-white mb-6">Zero drama at <br><span class="text-[#E7BF3B] italic">closing time.</span></h3>
                    <p class="text-lg text-white/70 mb-10 font-light leading-relaxed">
                        Every aspect of the POS is designed to ensure strict compliance. We eliminate the guesswork from daily reporting, cash drawer reconciliation, and invoice tracking.
                    </p>
                    
                    <ul class="space-y-8">
                        <li class="flex items-start">
                            <div class="flex-shrink-0 mt-1">
                                <svg class="w-5 h-5 text-[#2EA0B3]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            </div>
                            <div class="ml-4">
                                <h4 class="text-base font-bold text-white mb-1">Provisional Bills & Mandatory Confirmation</h4>
                                <p class="text-sm text-white/60 leading-relaxed">Draft provisional bills confidently. The payment-confirmation step is mandatory before FBR fiscalization occurs.</p>
                            </div>
                        </li>
                        <li class="flex items-start">
                            <div class="flex-shrink-0 mt-1">
                                <svg class="w-5 h-5 text-[#2EA0B3]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            </div>
                            <div class="ml-4">
                                <h4 class="text-base font-bold text-white mb-1">Day-Close Reports</h4>
                                <p class="text-sm text-white/60 leading-relaxed">Reconcile cash drawers precisely. Comprehensive reporting summarizes daily revenue against FBR submitted totals.</p>
                            </div>
                        </li>
                        <li class="flex items-start">
                            <div class="flex-shrink-0 mt-1">
                                <svg class="w-5 h-5 text-[#2EA0B3]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            </div>
                            <div class="ml-4">
                                <h4 class="text-base font-bold text-white mb-1">Installable PWA & Thermal Printing</h4>
                                <p class="text-sm text-white/60 leading-relaxed">Install as a desktop app. Print standard thermal receipts instantly upon successful transaction recording.</p>
                            </div>
                        </li>
                    </ul>
                </div>

                <div class="order-2 lg:order-2 fade-in-up relative">
                    <div class="bg-white/10 p-2 rounded-lg border border-white/15 backdrop-blur">
                        <img src="{{ asset('images/screenshots/fbr-tx.jpg') }}" alt="FBR Transactions" class="w-full rounded border border-[#07333E] opacity-90" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIHZpZXdCb3g9IjAgMCA4MDAgNTAwIj48cmVjdCBmaWxsPSIjZjNmMTRiIiB3aWR0aD0iODAwIiBoZWlnaHQ9IjUwMCIvPjx0ZXh0IGZpbGw9IiM5Y2EzYWYiIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBmb250LXNpemU9IjMwIiBkeT0iMTAuNSIgZm9udC13ZWlnaHQ9ImJvbGQiIHg9IjUwJSIgeT0iNTAlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5GQlIgVHJhbnNhY3Rpb25zPC90ZXh0Pjwvc3ZnPg=='">
                    </div>
                    <div class="bg-white/10 p-2 rounded-lg border border-white/15 backdrop-blur mt-6 sm:-mt-16 sm:-ml-12 relative z-10 hidden sm:block">
                        <img src="{{ asset('images/screenshots/fbr-dash.jpg') }}" alt="FBR Dashboard" class="w-full rounded border border-[#07333E] opacity-90" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIHZpZXdCb3g9IjAgMCA4MDAgNTAwIj48cmVjdCBmaWxsPSIjZjNmMTRiIiB3aWR0aD0iODAwIiBoZWlnaHQ9IjUwMCIvPjx0ZXh0IGZpbGw9IiM5Y2EzYWYiIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBmb250LXNpemU9IjMwIiBkeT0iMTAuNSIgZm9udC13ZWlnaHQ9ImJvbGQiIHg9IjUwJSIgeT0iNTAlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5GQlIgRGFzaGJvYXJkPC90ZXh0Pjwvc3ZnPg=='">
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- Pricing -->
    <section id="pricing" class="py-24 bg-white border-b border-gray-200">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto mb-16 fade-in-up">
                <h2 class="text-sm font-bold tracking-widest uppercase text-gray-500 mb-4">Investment</h2>
                <h3 class="text-3xl font-serif text-[#052730] mb-4">Annual Plans</h3>
                <p class="text-gray-600 text-lg">Predictable annual pricing. 6% discount baked directly into all plans.</p>
            </div>
            
            @if(isset($plans) && $plans->count())
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 max-w-5xl mx-auto items-start">
                @foreach($plans as $plan)
                @php
                    $isPopular = $plan->name === 'Business';
                    $planFeatures = is_array($plan->features) ? $plan->features : (is_string($plan->features) ? json_decode($plan->features, true) : []);
                    $annualSalePrice = round($plan->sale_price * 12 * 0.94);
                    $annualComparePrice = round($plan->price * 12 * 0.94);
                @endphp
                
                <div class="card-solid rounded-lg flex flex-col h-full relative overflow-hidden fade-in-up {{ $isPopular ? 'border-[#0A4D5C] ring-1 ring-[#0A4D5C]' : '' }}">
                    
                    @if($isPopular)
                    <div class="bg-accent-fbr text-white text-xs font-bold uppercase tracking-wider text-center py-2 w-full">
                        Recommended for Retail
                    </div>
                    @endif
                    
                    <div class="p-8 flex-grow flex flex-col">
                        <h4 class="text-xl font-serif font-bold text-[#052730] mb-2">{{ $plan->name }}</h4>
                        
                        <div class="mb-6 flex items-baseline">
                            <span class="text-sm font-semibold text-gray-500 mr-1">PKR</span>
                            <span class="text-4xl font-bold text-gray-900 tracking-tight">{{ number_format($annualSalePrice) }}</span>
                            <span class="text-sm text-gray-500 ml-1 font-medium">/ year</span>
                        </div>
                        
                        @if($plan->price > $plan->sale_price)
                        <div class="mb-4 text-sm flex items-center">
                            <span class="line-through text-gray-400 font-medium">PKR {{ number_format($annualComparePrice) }}/yr</span>
                            @if($plan->sale_badge)
                            <span class="ml-3 inline-block px-2 py-0.5 rounded text-xs font-bold bg-[#E7BF3B]/20 text-[#062A33] border border-[#E7BF3B]/30">
                                {{ $plan->sale_badge }}
                            </span>
                            @endif
                        </div>
                        @endif
                        
                        <a href="/fbr-pos/register" class="btn-solid w-full mb-8 {{ $isPopular ? 'btn-fbr' : 'btn-secondary' }}">
                            Start Free Trial
                        </a>
                        
                        <ul class="space-y-4 flex-grow">
                            @if(!empty($planFeatures))
                                @foreach($planFeatures as $feature)
                                <li class="flex text-sm text-gray-700">
                                    <svg class="w-5 h-5 text-[#0A4D5C] mr-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <span class="leading-relaxed">{{ $feature }}</span>
                                </li>
                                @endforeach
                            @endif
                        </ul>
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </section>

    <!-- FAQ -->
    <section class="py-24 bg-[#FDFBF7] border-b border-gray-200">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16 fade-in-up">
                <h2 class="text-sm font-bold tracking-widest uppercase text-gray-500 mb-4">FAQ</h2>
                <h3 class="text-3xl font-serif text-[#052730]">Frequently Asked Questions</h3>
            </div>
            <div class="space-y-4" x-data="{ open: null }">
                <div class="card-solid rounded-lg fade-in-up">
                    <button @click="open = (open === 1 ? null : 1)" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="font-bold text-[#052730]">Are bills really submitted to FBR in real time?</span>
                        <svg class="w-5 h-5 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 1 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 1" x-collapse class="px-6 pb-6 text-gray-600 leading-relaxed border-t border-gray-100 pt-4 mt-2">Yes. Each completed sale goes straight to FBR's POS service and the fiscal invoice number prints on the receipt. If FBR rejects a bill, it lands in a fail queue where you can fix the data and retry — nothing silently disappears.</div>
                </div>
                <div class="card-solid rounded-lg fade-in-up" style="transition-delay: 50ms;">
                    <button @click="open = (open === 2 ? null : 2)" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="font-bold text-[#052730]">Can I run the whole sale from the keyboard?</span>
                        <svg class="w-5 h-5 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 2 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 2" x-collapse class="px-6 pb-6 text-gray-600 leading-relaxed border-t border-gray-100 pt-4 mt-2">Yes — scan or type in one smart input, adjust quantities, take payment and print, all without touching the mouse. Barcode scanners work out of the box.</div>
                </div>
                <div class="card-solid rounded-lg fade-in-up" style="transition-delay: 100ms;">
                    <button @click="open = (open === 3 ? null : 3)" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="font-bold text-[#052730]">Do I need to install anything?</span>
                        <svg class="w-5 h-5 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 3 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 3" x-collapse class="px-6 pb-6 text-gray-600 leading-relaxed border-t border-gray-100 pt-4 mt-2">No. It runs in the browser and installs as a PWA for a full-screen, desktop-like terminal on any PC or tablet — updates arrive automatically.</div>
                </div>
                <div class="card-solid rounded-lg fade-in-up" style="transition-delay: 150ms;">
                    <button @click="open = (open === 4 ? null : 4)" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="font-bold text-[#052730]">How is billing structured?</span>
                        <svg class="w-5 h-5 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 4 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 4" x-collapse class="px-6 pb-6 text-gray-600 leading-relaxed border-t border-gray-100 pt-4 mt-2">Simple annual billing — one payment a year with the annual discount already applied, starting at PKR 5,629/year. Every account begins with a 3-day free trial.</div>
                </div>
                <div class="card-solid rounded-lg fade-in-up" style="transition-delay: 200ms;">
                    <button @click="open = (open === 5 ? null : 5)" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="font-bold text-[#052730]">Who can approve sensitive actions like provisional bills?</span>
                        <svg class="w-5 h-5 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 5 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 5" x-collapse class="px-6 pb-6 text-gray-600 leading-relaxed border-t border-gray-100 pt-4 mt-2">A confidential owner PIN gates sensitive areas. Cashiers ring up sales; only someone with the PIN can open the provisional-bill list — so control stays with the owner.</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Final CTA -->
    <section class="bg-[#052730] py-24 relative overflow-hidden">
        <div class="absolute inset-0 bg-grid-pattern-dark opacity-10"></div>
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative z-10 fade-in-up">
            <h2 class="text-4xl font-serif text-white mb-6">Deploy the terminal today.</h2>
            <p class="text-xl text-white/70 mb-10 font-light max-w-2xl mx-auto">
                Start your 3-day free trial. No credit card required. No hidden fees. Experience seamless FBR integration.
            </p>
            <div class="flex flex-col sm:flex-row justify-center gap-4">
                <a href="/fbr-pos/register" class="btn-solid btn-gold px-8 py-4 text-base">
                    Create Free Account
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-[#052730] py-10 border-t border-[#0A4D5C]">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row items-center justify-between">
            <div class="flex items-center mb-6 md:mb-0">
                <img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" alt="TaxNest" class="h-6 w-auto opacity-70">
                <span class="ml-4 pl-4 border-l border-white/20 text-sm font-semibold tracking-widest uppercase text-white/50">FBR POS</span>
            </div>
            <div class="flex items-center space-x-8">
                <span class="text-xs font-semibold text-white/40 uppercase tracking-widest">Secure</span>
                <span class="text-xs font-semibold text-white/40 uppercase tracking-widest">Reliable</span>
                <span class="text-xs font-semibold text-white/40 uppercase tracking-widest">Compliant</span>
            </div>
            <div class="mt-6 md:mt-0 text-sm text-white/40 font-medium">
                &copy; {{ date('Y') }} TaxNest. All rights reserved.
            </div>
        </div>
    </footer>

    <x-whatsapp-support />

    <script>
        // Intersection Observer for scroll animations
        document.addEventListener('DOMContentLoaded', () => {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                    }
                });
            }, {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            });

            document.querySelectorAll('.fade-in-up').forEach((el) => {
                observer.observe(el);
            });
        });
    </script>
</body>
</html>