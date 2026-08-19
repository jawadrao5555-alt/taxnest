<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#052730">
    <link rel="icon" type="image/svg+xml" href="/images/brand/taxnest-mark.svg">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <title>FBR POS — Bank-Grade Point of Sale by TaxNest</title>
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link rel="preload" as="style" href="https://fonts.bunny.net/css?family=playfair-display:400,600,700|inter:400,500,600,700,800&display=swap">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=playfair-display:400,600,700|inter:400,500,600,700,800&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.bunny.net/css?family=playfair-display:400,600,700|inter:400,500,600,700,800&display=swap"></noscript>
    @include('partials.fast-first-paint')
    <style>
        :root {
            --teal-dark: #052730;
            --teal-main: #0A4D5C;
            --teal-light: #1B7C8C;
            --gold: #E7BF3B;
            --paper: #FDFBF7;
            --ink: #1F2937;
            --fbr-blue: #1d4ed8;
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
        .btn-fbr {
            background-color: var(--fbr-blue);
            color: #FFFFFF;
            border: 1px solid transparent;
        }
        .btn-fbr:hover {
            background-color: var(--fbr-blue-hover);
        }

        .bg-grid-pattern {
            background-image: linear-gradient(rgba(0,0,0,0.03) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(0,0,0,0.03) 1px, transparent 1px);
            background-size: 40px 40px;
        }
        
        .receipt-edge-top {
            background-image: radial-gradient(circle at 10px 0, transparent 10px, #ffffff 11px);
            background-size: 20px 20px;
            background-repeat: repeat-x;
            height: 20px;
            width: 100%;
            margin-top: -20px;
        }

        .receipt-edge-bottom {
            background-image: radial-gradient(circle at 10px 20px, transparent 10px, #ffffff 11px);
            background-size: 20px 20px;
            background-repeat: repeat-x;
            height: 20px;
            width: 100%;
            margin-bottom: -20px;
        }

        .nav-scrolled {
            background-color: rgba(253, 251, 247, 0.98);
            border-bottom: 1px solid rgba(0,0,0,0.08);
        }
        .nav-transparent {
            background-color: transparent;
            border-bottom: 1px solid transparent;
        }
    </style>
    {{-- Urdu-script font (Task 1287) — inert unless the app locale is ever 'ur'. --}}
    @include('partials.urdu-font')
</head>
<body x-data="{ scrolled: false, mobileOpen: false, showLoginModal: {{ ($errors->any() || old('login')) ? 'true' : 'false' }} }" @scroll.window="scrolled = (window.pageYOffset > 20)">
    <div id="tn-boot" aria-hidden="true"><span>FBR&nbsp;<b>POS</b></span></div>

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
    <nav :class="(scrolled || mobileOpen) ? 'nav-scrolled' : 'nav-transparent'" class="fixed top-0 w-full z-50 transition-colors duration-200">
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
                
                <div class="hidden md:flex items-center space-x-8">
                    <a href="#architecture" class="text-sm font-semibold transition-colors" :class="scrolled ? 'text-[#052730] hover:text-[#0A4D5C]' : 'text-white/90 hover:text-white'">Architecture</a>
                    <a href="#features" class="text-sm font-semibold transition-colors" :class="scrolled ? 'text-[#052730] hover:text-[#0A4D5C]' : 'text-white/90 hover:text-white'">Features</a>
                    <a href="#pricing" class="text-sm font-semibold transition-colors" :class="scrolled ? 'text-[#052730] hover:text-[#0A4D5C]' : 'text-white/90 hover:text-white'">Pricing</a>
                    
                    <div class="w-px h-5" :class="scrolled ? 'bg-gray-300' : 'bg-white/20'"></div>
                    
                    <button @click="showLoginModal = true" class="text-sm font-semibold transition-colors" :class="scrolled ? 'text-[#052730] hover:text-[#0A4D5C]' : 'text-white hover:text-white/80'">Sign In</button>
                    <a href="/fbr-pos/register" class="btn-solid" :class="scrolled ? 'bg-[#052730] text-white hover:bg-[#0A4D5C]' : 'bg-white text-[#052730] hover:bg-gray-100'">Start Free Trial</a>
                </div>

                <div class="flex items-center md:hidden">
                    <button @click="mobileOpen = !mobileOpen" class="p-2 -mr-2 focus:outline-none" aria-label="Menu">
                        <svg x-show="!mobileOpen" class="w-6 h-6" :class="scrolled ? 'text-gray-800' : 'text-white'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        <svg x-show="mobileOpen" x-cloak class="w-6 h-6 text-gray-800" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
        </div>

        <div x-show="mobileOpen" x-cloak @click.away="mobileOpen = false" class="md:hidden border-t border-gray-200 bg-[#FDFBF7]">
            <div class="px-4 py-4 space-y-1">
                <a href="#architecture" class="block px-3 py-2.5 rounded text-sm font-semibold text-gray-700 hover:bg-gray-100" @click="mobileOpen = false">Architecture</a>
                <a href="#features" class="block px-3 py-2.5 rounded text-sm font-semibold text-gray-700 hover:bg-gray-100" @click="mobileOpen = false">Features</a>
                <a href="#pricing" class="block px-3 py-2.5 rounded text-sm font-semibold text-gray-700 hover:bg-gray-100" @click="mobileOpen = false">Pricing</a>
                <div class="border-t border-gray-200 my-2 pt-2"></div>
                <button @click="showLoginModal = true; mobileOpen = false" class="w-full text-left block px-3 py-2.5 rounded text-sm font-semibold text-gray-700 hover:bg-gray-100">Sign In to POS</button>
                <a href="/fbr-pos/register" class="block px-3 py-2.5 mt-2 rounded text-sm font-semibold text-white bg-[#052730] text-center" @click="mobileOpen = false">Start Free Trial</a>
            </div>
        </div>
    </nav>

    <!-- Hero: Editorial Center -->
    <section class="relative bg-[#052730] pt-32 pb-48 lg:pt-48 lg:pb-64 overflow-hidden border-b border-[#0A4D5C]">
        <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI0IiBoZWlnaHQ9IjQiPjxyZWN0IHdpZHRoPSI0IiBoZWlnaHQ9IjQiIGZpbGw9IiNmZmZmZmYiIGZpbGwtb3BhY2l0eT0iMC4wNSIvPjwvc3ZnPg==')]"></div>
        <div class="max-w-[1000px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10 text-center">
            
            <div class="fade-in-up">
                <h1 class="text-5xl sm:text-6xl lg:text-7xl font-serif text-white leading-[1.1] mb-8">
                    A fiscal register that<br>never blinks.
                </h1>
                
                <p class="text-xl text-white/80 mb-12 max-w-2xl mx-auto font-light leading-relaxed">
                    Built exclusively for Tier-1 Pakistani retailers navigating the FBR POS regime. Bank-grade reporting, strict PIN controls, and a counter flow that holds up during Friday rush hour.
                </p>
                
                <div class="flex flex-col sm:flex-row justify-center gap-4">
                    <a href="/fbr-pos/register" class="btn-solid btn-gold px-8 py-4 text-base">
                        Start Your Free Trial
                    </a>
                    <button @click="showLoginModal = true" class="btn-solid bg-[#07333E] text-white hover:bg-[#0A4D5C] px-8 py-4 text-base border border-white/10">
                        Sign In
                    </button>
                </div>
            </div>
            
        </div>
    </section>

    <!-- HTML Artifact Overlapping Hero -->
    <div class="relative z-20 max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 -mt-32 lg:-mt-48 mb-24 flex justify-center fade-in-up" style="transition-delay: 200ms;">
        <div class="w-full max-w-sm bg-white shadow-2xl relative border border-gray-200 transform rotate-1">
            <div class="receipt-edge-top"></div>
            <div class="p-6 font-mono text-xs text-gray-900 leading-snug">
                <!-- Receipt Header -->
                <div class="text-center mb-6">
                    <h2 class="font-bold text-lg leading-none mb-1">BISMILLAH SUPER STORE</h2>
                    <div class="text-gray-600">Main Boulevard, Gulberg III, Lahore</div>
                    <div class="mt-2 font-bold">NTN: 8943211-8</div>
                    <div class="font-bold">STRN: 3277876113944</div>
                </div>

                <div class="border-b border-dashed border-gray-400 pb-3 mb-3 flex justify-between">
                    <div>
                        <div>Date: 14-Aug-2026</div>
                        <div>Time: 18:45:22</div>
                    </div>
                    <div class="text-right">
                        <div>Till: 03</div>
                        <div>User: Usman</div>
                    </div>
                </div>
                
                <table class="w-full mb-3">
                    <thead class="border-b border-dashed border-gray-400">
                        <tr>
                            <th class="text-left font-normal py-1">Item</th>
                            <th class="text-right font-normal py-1">Qty</th>
                            <th class="text-right font-normal py-1">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="border-b border-dashed border-gray-400">
                        <tr>
                            <td class="py-1">Nestle Milkpak 1L</td>
                            <td class="text-right py-1">2</td>
                            <td class="text-right py-1">560.00</td>
                        </tr>
                        <tr>
                            <td class="py-1">Lipton Yellow 390g</td>
                            <td class="text-right py-1">1</td>
                            <td class="text-right py-1">1,450.00</td>
                        </tr>
                        <tr>
                            <td class="py-1">Tapal Danedar 400g</td>
                            <td class="text-right py-1">1</td>
                            <td class="text-right py-1">1,380.00</td>
                        </tr>
                        <tr>
                            <td class="py-2" colspan="3"></td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="flex justify-between mb-1">
                    <span>Subtotal:</span>
                    <span>3,390.00</span>
                </div>
                <div class="flex justify-between mb-2">
                    <span>Sales Tax (18%):</span>
                    <span>610.20</span>
                </div>
                <div class="flex justify-between font-bold text-sm pt-2 border-t border-dashed border-gray-400 mb-6">
                    <span>TOTAL PKR:</span>
                    <span>4,000.20</span>
                </div>
                
                <div class="bg-gray-50 border border-gray-200 p-4 text-center">
                    <div class="text-[10px] uppercase font-bold tracking-widest text-[#1d4ed8] mb-3">FBR Fiscal Invoice</div>
                    
                    <!-- CSS QR Code Placeholder -->
                    <div class="inline-block p-1 bg-white border border-gray-300 mb-3">
                        <div class="w-20 h-20 bg-grid-pattern opacity-60"></div>
                    </div>
                    
                    <div class="font-bold text-sm tracking-wider">FBR-INV-2026-0034</div>
                    <div class="text-[10px] mt-1 text-gray-500">Verify via Tax Asaan App</div>
                </div>
            </div>
            <div class="receipt-edge-bottom"></div>
        </div>
    </div>

    <!-- Editorial Variety: Wide Stat Band -->
    <section class="py-16 bg-[#FDFBF7] border-y border-gray-200">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-12 text-center md:text-left divide-y md:divide-y-0 md:divide-x divide-gray-200">
                <div class="fade-in-up py-4 md:py-0 md:pr-12">
                    <div class="text-5xl font-serif font-bold text-[#052730] mb-2"><span class="text-[#E7BF3B] text-2xl align-top">PKR</span> 0.00</div>
                    <div class="text-gray-600 font-medium">Fine exposure for missing FBR deadlines. Auto-sync handles submission securely in the background.</div>
                </div>
                <div class="fade-in-up py-4 md:py-0 md:px-12" style="transition-delay: 100ms;">
                    <div class="text-5xl font-serif font-bold text-[#052730] mb-2">100%</div>
                    <div class="text-gray-600 font-medium">Keyboard navigable checkout. Scan, adjust, and tender without reaching for the mouse.</div>
                </div>
                <div class="fade-in-up py-4 md:py-0 md:pl-12" style="transition-delay: 200ms;">
                    <div class="text-5xl font-serif font-bold text-[#052730] mb-2">Multi</div>
                    <div class="text-gray-600 font-medium">Branch architecture. Manage dozens of locations, cashiers, and tax configurations from a single portal.</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Concrete Features: Asymmetrical Left-Aligned -->
    <section id="architecture" class="py-24 bg-white">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-16 max-w-3xl fade-in-up">
                <h2 class="text-4xl font-serif text-[#052730] mb-6">Built for the reality of Pakistani retail.</h2>
                <p class="text-xl text-gray-600 leading-relaxed">
                    Counter staff don't have time to deal with sync errors when five customers are waiting. We built a terminal that absorbs network drops, enforces permissions, and prints receipts instantly.
                </p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-12">
                <!-- Main Feature -->
                <div class="lg:col-span-7 bg-[#FDFBF7] border border-gray-200 p-10 fade-in-up">
                    <h3 class="text-2xl font-serif font-bold text-[#052730] mb-4">Direct API Transmission</h3>
                    <p class="text-gray-700 leading-relaxed mb-6">
                        No middleman middleware delaying your transactions. The moment a cashier confirms payment, the payload goes directly to FBR. If the connection fails, the sale is queued, the receipt prints normally, and the system securely retries in the background until successful.
                    </p>
                    <ul class="space-y-3">
                        <li class="flex items-center text-sm font-medium text-gray-800">
                            <span class="w-1.5 h-1.5 bg-[#0A4D5C] rounded-full mr-3"></span> Background retry logic
                        </li>
                        <li class="flex items-center text-sm font-medium text-gray-800">
                            <span class="w-1.5 h-1.5 bg-[#0A4D5C] rounded-full mr-3"></span> FBR fail-queue for manual correction
                        </li>
                        <li class="flex items-center text-sm font-medium text-gray-800">
                            <span class="w-1.5 h-1.5 bg-[#0A4D5C] rounded-full mr-3"></span> Uninterrupted thermal printing
                        </li>
                    </ul>
                </div>

                <!-- Secondary Features -->
                <div class="lg:col-span-5 space-y-12">
                    <div class="fade-in-up" style="transition-delay: 100ms;">
                        <div class="w-10 h-1px bg-gray-300 mb-4"></div>
                        <h4 class="text-lg font-bold text-[#052730] mb-2">Manager PIN Overrides</h4>
                        <p class="text-gray-600 text-sm leading-relaxed">
                            Cashiers cannot void items, open held carts, or view closing reports without authorization. Secure operations with a 4-digit manager PIN that locks down sensitive actions.
                        </p>
                    </div>

                    <div class="fade-in-up" style="transition-delay: 200ms;">
                        <div class="w-10 h-1px bg-gray-300 mb-4"></div>
                        <h4 class="text-lg font-bold text-[#052730] mb-2">Provisional Bills</h4>
                        <p class="text-gray-600 text-sm leading-relaxed">
                            Print a draft bill for customer review before committing it to FBR. A critical feature for restaurants or large hardware orders where adjustments happen before final payment.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Full Feature Grid -->
    <section id="features" class="py-24 bg-[#FDFBF7] border-t border-gray-200">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-16 max-w-3xl fade-in-up">
                <span class="text-xs font-bold tracking-widest uppercase text-[#0A4D5C]">Everything at the counter</span>
                <h2 class="text-4xl font-serif text-[#052730] mt-3 mb-6">A complete retail toolkit, <span class="text-[#B8951F]">not just a fiscal printer.</span></h2>
                <p class="text-xl text-gray-600 leading-relaxed">
                    Returns, loyalty, khata, promotions, inventory, restaurant orders, offline billing and day-close — the workflows a real Pakistani shop runs every day, built into the same terminal that talks to FBR.
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Returns / Credit Notes -->
                <div class="bg-white border border-gray-200 p-8 fade-in-up">
                    <div class="w-10 h-10 rounded bg-[#0F6171]/10 flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 15v-1a4 4 0 00-4-4H8m0 0l3 3m-3-3l3-3m9 14V5a2 2 0 00-2-2H6a2 2 0 00-2 2v16l4-2 4 2 4-2 4 2z"/></svg>
                    </div>
                    <h4 class="text-lg font-bold text-[#052730] mb-2">Returns &amp; Credit Notes</h4>
                    <p class="text-gray-600 text-sm leading-relaxed">Pull up any receipt and process a return within the 15-day window. Refunds, stock restore and the FBR credit note are handled in one flow — no manual adjustments.</p>
                </div>

                <!-- Loyalty -->
                <div class="bg-white border border-gray-200 p-8 fade-in-up" style="transition-delay: 50ms;">
                    <div class="w-10 h-10 rounded bg-[#0F6171]/10 flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.783-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                    </div>
                    <h4 class="text-lg font-bold text-[#052730] mb-2">Loyalty Points</h4>
                    <p class="text-gray-600 text-sm leading-relaxed">Set your own earn and redeem rates. Regular customers collect points on every bill and cash them in at the counter — the full points ledger stays auditable per customer. Pro plan.</p>
                </div>

                <!-- Promotions -->
                <div class="bg-white border border-gray-200 p-8 fade-in-up" style="transition-delay: 100ms;">
                    <div class="w-10 h-10 rounded bg-[#0F6171]/10 flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    </div>
                    <h4 class="text-lg font-bold text-[#052730] mb-2">Promotions &amp; Discount Codes</h4>
                    <p class="text-gray-600 text-sm leading-relaxed">Run time-boxed promotions, deals and discount codes that validate live at billing. Prices are enforced server-side, so cashiers can't hand out discounts you didn't approve. Pro plan.</p>
                </div>

                <!-- Khata -->
                <div class="bg-white border border-gray-200 p-8 fade-in-up">
                    <div class="w-10 h-10 rounded bg-[#0F6171]/10 flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    </div>
                    <h4 class="text-lg font-bold text-[#052730] mb-2">Customer Khata Ledger</h4>
                    <p class="text-gray-600 text-sm leading-relaxed">Sell on credit the way the market actually works. Every udhaar bill and wasooli posts to a per-customer ledger with a running balance — no side registers. Business plan onwards.</p>
                </div>

                <!-- Day-close / shifts -->
                <div class="bg-white border border-gray-200 p-8 fade-in-up" style="transition-delay: 50ms;">
                    <div class="w-10 h-10 rounded bg-[#0F6171]/10 flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <h4 class="text-lg font-bold text-[#052730] mb-2">Z-Reports, Shifts &amp; Terminals</h4>
                    <p class="text-gray-600 text-sm leading-relaxed">Open a shift with counted opening cash, run multiple terminals, and close the day with a Z-report — on screen, PDF or thermal — that reconciles cash, card and khata.</p>
                </div>

                <!-- Fiscal Device local mode -->
                <div class="bg-white border border-gray-200 p-8 fade-in-up" style="transition-delay: 100ms;">
                    <div class="w-10 h-10 rounded bg-[#0F6171]/10 flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </div>
                    <h4 class="text-lg font-bold text-[#052730] mb-2">Fiscal Device Local Mode</h4>
                    <p class="text-gray-600 text-sm leading-relaxed">For POS IDs on FBR's fiscal-device regime, our Windows Desktop Agent fiscalizes bills through the local IMS service on your counter PC — the route FBR now requires for new registrations.</p>
                </div>

                <!-- Delivery riders -->
                <div class="bg-white border border-gray-200 p-8 fade-in-up">
                    <div class="w-10 h-10 rounded bg-[#0F6171]/10 flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0 2 2 0 00-4 0z"/></svg>
                    </div>
                    <h4 class="text-lg font-bold text-[#052730] mb-2">Delivery Riders</h4>
                    <p class="text-gray-600 text-sm leading-relaxed">Assign home-delivery bills to riders, track what's out for delivery, and settle each rider's cash at day end so nothing goes missing between the shop and the doorstep.</p>
                </div>

                <!-- Receipt themes -->
                <div class="bg-white border border-gray-200 p-8 fade-in-up" style="transition-delay: 50ms;">
                    <div class="w-10 h-10 rounded bg-[#0F6171]/10 flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                    </div>
                    <h4 class="text-lg font-bold text-[#052730] mb-2">Receipt Themes</h4>
                    <p class="text-gray-600 text-sm leading-relaxed">Pick the receipt style that fits your shop — layout, logo and formatting options for both 80mm and 58mm thermal printers, with the FBR fiscal block always intact.</p>
                </div>

                <!-- Urdu -->
                <div class="bg-white border border-gray-200 p-8 fade-in-up" style="transition-delay: 100ms;">
                    <div class="w-10 h-10 rounded bg-[#0F6171]/10 flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"/></svg>
                    </div>
                    <h4 class="text-lg font-bold text-[#052730] mb-2">Urdu &amp; Roman Urdu</h4>
                    <p class="text-gray-600 text-sm leading-relaxed">Switch the whole panel to Urdu or Roman Urdu so every cashier is comfortable from day one — and print customer invoices with proper Urdu text where needed.</p>
                </div>

                <!-- Restaurant module -->
                <div class="bg-white border border-gray-200 p-8 fade-in-up">
                    <div class="w-10 h-10 rounded bg-[#0F6171]/10 flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h18v4H3V3zm2 8h14m-9 4h4m-7 4h10a2 2 0 002-2v-6H5v6a2 2 0 002 2z"/></svg>
                    </div>
                    <h4 class="text-lg font-bold text-[#052730] mb-2">Restaurant Orders &amp; Kitchen</h4>
                    <p class="text-gray-600 text-sm leading-relaxed">Run dine-in tables, print kitchen order tickets by station, and put a kitchen display screen in front of the chef — plus a scan-to-order public QR menu for your customers. Kitchen printing is on the Pro plan.</p>
                </div>

                <!-- Inventory -->
                <div class="bg-white border border-gray-200 p-8 fade-in-up" style="transition-delay: 50ms;">
                    <div class="w-10 h-10 rounded bg-[#0F6171]/10 flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    </div>
                    <h4 class="text-lg font-bold text-[#052730] mb-2">Inventory &amp; Stock</h4>
                    <p class="text-gray-600 text-sm leading-relaxed">Every sale deducts stock automatically. Record purchases and suppliers, adjust counts, set minimum levels for low-stock alerts, and see stock movements and profit per product.</p>
                </div>

                <!-- Excel import/export -->
                <div class="bg-white border border-gray-200 p-8 fade-in-up" style="transition-delay: 100ms;">
                    <div class="w-10 h-10 rounded bg-[#0F6171]/10 flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <h4 class="text-lg font-bold text-[#052730] mb-2">Product Excel Import / Export</h4>
                    <p class="text-gray-600 text-sm leading-relaxed">Bring a whole catalog in from Excel in minutes — barcodes, prices and tax rates intact — and export it back out the same way. Available from the Business plan onwards.</p>
                </div>

                <!-- Offline billing -->
                <div class="bg-white border border-gray-200 p-8 fade-in-up">
                    <div class="w-10 h-10 rounded bg-[#0F6171]/10 flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h4 class="text-lg font-bold text-[#052730] mb-2">Offline Billing with Auto-Sync</h4>
                    <p class="text-gray-600 text-sm leading-relaxed">Load shedding doesn't close your counter. Bills keep printing when the internet drops and sync to FBR automatically every couple of minutes once you're back online. Business plan onwards.</p>
                </div>

                <!-- Analytics -->
                <div class="bg-white border border-gray-200 p-8 fade-in-up" style="transition-delay: 50ms;">
                    <div class="w-10 h-10 rounded bg-[#0F6171]/10 flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V5a2 2 0 00-2-2H6a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                    <h4 class="text-lg font-bold text-[#052730] mb-2">Reports &amp; Analytics</h4>
                    <p class="text-gray-600 text-sm leading-relaxed">Sales and tax reports with PDF export from the Business plan, and a full analytics dashboard — trends, top products, busiest hours — on the Pro plan. Know your shop by the numbers.</p>
                </div>

                <!-- Mobile app -->
                <div class="bg-white border border-gray-200 p-8 fade-in-up" style="transition-delay: 100ms;">
                    <div class="w-10 h-10 rounded bg-[#0F6171]/10 flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-[#0A4D5C]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                    <h4 class="text-lg font-bold text-[#052730] mb-2">Android Mobile App</h4>
                    <p class="text-gray-600 text-sm leading-relaxed">A lightweight Android app puts the full FBR POS in your pocket — check sales from home, bill from a phone at an exhibition stall, and get updates automatically.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Real product screenshots -->
    <section class="py-24 bg-white border-t border-gray-200">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-16 max-w-3xl fade-in-up">
                <span class="text-xs font-bold tracking-widest uppercase text-[#0A4D5C]">Straight from the terminal</span>
                <h2 class="text-4xl font-serif text-[#052730] mt-3 mb-6">This is the actual software, <span class="text-[#B8951F]">not a mock-up.</span></h2>
                <p class="text-xl text-gray-600 leading-relaxed">
                    The product catalog your manager maintains, the stock page they check every morning, and the analytics your accountant signs off — captured from the running product.
                </p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <div class="lg:col-span-2 fade-in-up">
                    <div class="bg-[#FDFBF7] border border-gray-200 p-3 sm:p-4">
                        <img src="{{ asset('images/screenshots/fbr-reports.jpg') }}?v=1" alt="FBR POS sales reports and analytics dashboard — revenue, tax, FBR submitted counts and profit estimate" loading="lazy" class="w-full h-auto border border-gray-300 shadow-sm">
                    </div>
                    <div class="flex items-baseline justify-between mt-3 px-1">
                        <div class="text-sm font-bold text-[#052730]">Reports &amp; Analytics</div>
                        <div class="text-xs text-gray-500">Revenue, tax, FBR submission counts and profit margin in one view.</div>
                    </div>
                </div>

                <div class="fade-in-up" style="transition-delay: 100ms;">
                    <div class="bg-[#FDFBF7] border border-gray-200 p-3 sm:p-4">
                        <img src="{{ asset('images/screenshots/fbr-products.jpg') }}?v=1" alt="FBR POS products page with tax configuration per product and bulk Excel import" loading="lazy" class="w-full h-auto border border-gray-300 shadow-sm">
                    </div>
                    <div class="flex items-baseline justify-between mt-3 px-1 gap-4">
                        <div class="text-sm font-bold text-[#052730] whitespace-nowrap">Products &amp; Excel Import</div>
                        <div class="text-xs text-gray-500 text-right">Tax rate per product, bulk import from Excel in minutes.</div>
                    </div>
                </div>

                <div class="fade-in-up" style="transition-delay: 200ms;">
                    <div class="bg-[#FDFBF7] border border-gray-200 p-3 sm:p-4">
                        <img src="{{ asset('images/screenshots/fbr-stock.jpg') }}?v=1" alt="Stock and purchase page with low-stock alerts, supplier entry and stock list" loading="lazy" class="w-full h-auto border border-gray-300 shadow-sm">
                    </div>
                    <div class="flex items-baseline justify-between mt-3 px-1 gap-4">
                        <div class="text-sm font-bold text-[#052730] whitespace-nowrap">Stock &amp; Purchase</div>
                        <div class="text-xs text-gray-500 text-right">Low-stock alerts, suppliers and purchase entry beside the counter.</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonial -->
    <section class="py-24 bg-[#052730] border-y border-[#0A4D5C]">
        <div class="max-w-[1000px] mx-auto px-4 sm:px-6 lg:px-8 text-center fade-in-up">
            <svg class="w-12 h-12 text-[#E7BF3B] mx-auto mb-8 opacity-80" fill="currentColor" viewBox="0 0 24 24"><path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.638-3.996 5.849h3.983v10h-9.983z"/></svg>
            <p class="text-2xl md:text-3xl font-serif text-white leading-relaxed mb-8">
                "We used to dread evening reconciliation. Now, the shift closes, the cash matches the FBR portal, and we go home. It's built like a tank and never slows down the counter."
            </p>
            <div>
                <div class="font-bold text-white tracking-wide">Tariq Mehmood</div>
                <div class="text-white/60 text-sm mt-1">Chief Accountant, Al-Madina Electronics, Lahore</div>
            </div>
        </div>
    </section>

    <!-- Pricing -->
    <section id="pricing" class="py-24 bg-white border-b border-gray-200">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto mb-16 fade-in-up">
                <h2 class="text-4xl font-serif text-[#052730] mb-4">Straightforward Licensing</h2>
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
                
                <div class="bg-white border {{ $isPopular ? 'border-[#0A4D5C] shadow-xl' : 'border-gray-200 shadow-sm' }} p-8 flex flex-col h-full relative fade-in-up">
                    
                    @if($isPopular)
                    <div class="absolute top-0 inset-x-0 h-1 bg-[#1d4ed8]"></div>
                    @endif
                    
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
                        <span class="ml-3 inline-block px-2 py-0.5 text-xs font-bold text-[#052730] bg-gray-100">
                            {{ $plan->sale_badge }}
                        </span>
                        @endif
                    </div>
                    @endif
                    
                    <a href="/fbr-pos/register" class="btn-solid w-full mb-8 {{ $isPopular ? 'btn-primary' : 'bg-white border border-gray-300 text-[#052730] hover:bg-gray-50' }}">
                        Start Free Trial
                    </a>
                    
                    <ul class="space-y-4 flex-grow border-t border-gray-100 pt-6">
                        @if(!empty($planFeatures))
                            @foreach($planFeatures as $feature)
                            <li class="flex text-sm text-gray-700 items-start">
                                <span class="text-[#0A4D5C] mr-3 mt-0.5 text-xs">■</span>
                                <span class="leading-relaxed">{{ $feature }}</span>
                            </li>
                            @endforeach
                        @endif
                    </ul>
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
                <h2 class="text-3xl font-serif text-[#052730]">Frequently Asked Questions</h2>
            </div>
            <div class="space-y-4" x-data="{ open: null }">
                <div class="bg-white border border-gray-200 fade-in-up">
                    <button @click="open = (open === 1 ? null : 1)" class="w-full flex items-center justify-between p-6 text-left hover:bg-gray-50 transition-colors">
                        <span class="font-bold text-[#052730]">Are bills really submitted to FBR in real time?</span>
                        <span class="text-gray-400 text-xl font-light" x-text="open === 1 ? '−' : '+'"></span>
                    </button>
                    <div x-show="open === 1" x-collapse class="px-6 pb-6 text-gray-600 leading-relaxed text-sm">Yes. Each completed sale goes straight to FBR's POS service and the fiscal invoice number prints on the receipt. If FBR rejects a bill, it lands in a fail queue where you can fix the data and retry — nothing silently disappears.</div>
                </div>
                <div class="bg-white border border-gray-200 fade-in-up" style="transition-delay: 50ms;">
                    <button @click="open = (open === 2 ? null : 2)" class="w-full flex items-center justify-between p-6 text-left hover:bg-gray-50 transition-colors">
                        <span class="font-bold text-[#052730]">Can I run the whole sale from the keyboard?</span>
                        <span class="text-gray-400 text-xl font-light" x-text="open === 2 ? '−' : '+'"></span>
                    </button>
                    <div x-show="open === 2" x-collapse class="px-6 pb-6 text-gray-600 leading-relaxed text-sm">Yes. Scan or type in one smart input, adjust quantities, take payment and print, all without touching the mouse. Barcode scanners work out of the box.</div>
                </div>
                <div class="bg-white border border-gray-200 fade-in-up" style="transition-delay: 100ms;">
                    <button @click="open = (open === 3 ? null : 3)" class="w-full flex items-center justify-between p-6 text-left hover:bg-gray-50 transition-colors">
                        <span class="font-bold text-[#052730]">Do I need to install anything?</span>
                        <span class="text-gray-400 text-xl font-light" x-text="open === 3 ? '−' : '+'"></span>
                    </button>
                    <div x-show="open === 3" x-collapse class="px-6 pb-6 text-gray-600 leading-relaxed text-sm">No complex server setups. It runs in the browser and installs as a PWA for a full-screen, desktop-like terminal on any PC or tablet. Updates are seamless.</div>
                </div>
                <div class="bg-white border border-gray-200 fade-in-up" style="transition-delay: 150ms;">
                    <button @click="open = (open === 4 ? null : 4)" class="w-full flex items-center justify-between p-6 text-left hover:bg-gray-50 transition-colors">
                        <span class="font-bold text-[#052730]">Who controls sensitive actions?</span>
                        <span class="text-gray-400 text-xl font-light" x-text="open === 4 ? '−' : '+'"></span>
                    </button>
                    <div x-show="open === 4" x-collapse class="px-6 pb-6 text-gray-600 leading-relaxed text-sm">A strict PIN gates sensitive areas. Cashiers ring up sales; only an authorized manager with the PIN can open the provisional-bill list or view day-end reports.</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Final CTA -->
    <section class="bg-[#052730] py-24 relative overflow-hidden">
        <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI0IiBoZWlnaHQ9IjQiPjxyZWN0IHdpZHRoPSI0IiBoZWlnaHQ9IjQiIGZpbGw9IiNmZmZmZmYiIGZpbGwtb3BhY2l0eT0iMC4wNSIvPjwvc3ZnPg==')]"></div>
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative z-10 fade-in-up">
            <h2 class="text-4xl lg:text-5xl font-serif text-white mb-6">Install the terminal today.</h2>
            <p class="text-xl text-white/70 mb-10 font-light max-w-2xl mx-auto">
                No credit card required. Experience strict, secure FBR compliance immediately.
            </p>
            <div class="flex flex-col sm:flex-row justify-center gap-4">
                <a href="/fbr-pos/register" class="btn-solid btn-gold px-8 py-4 text-base">
                    Create Free Account
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <x-site-footer login-url="/fbr-pos/login" login-label="FBR POS Log In" />

    <x-whatsapp-support />

    <script>
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