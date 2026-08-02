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

        .btn-solid {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            font-size: 0.875rem;
            border-radius: 0;
            transition: all 0.2s ease;
        }

        /* Nav Transition */
        .nav-scrolled {
            background-color: rgba(5, 39, 48, 0.85);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .nav-transparent {
            background-color: transparent;
            border-bottom: 1px solid transparent;
        }
        
        .thermal-receipt {
            background: #fff;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3), 0 10px 10px -5px rgba(0, 0, 0, 0.1);
            position: relative;
        }
        .thermal-receipt::before {
            content: "";
            position: absolute;
            top: -5px;
            left: 0;
            right: 0;
            height: 10px;
            background: linear-gradient(-45deg, transparent 5px, #fff 0), linear-gradient(45deg, transparent 5px, #fff 0);
            background-repeat: repeat-x;
            background-size: 10px 10px;
            background-position: left top;
        }
        .thermal-receipt::after {
            content: "";
            position: absolute;
            bottom: -5px;
            left: 0;
            right: 0;
            height: 10px;
            background: linear-gradient(-45deg, transparent 5px, #fff 0), linear-gradient(45deg, transparent 5px, #fff 0);
            background-repeat: repeat-x;
            background-size: 10px 10px;
            background-position: left bottom;
            transform: rotate(180deg);
        }
    </style>
</head>
<body x-data="{ scrolled: false, mobileMenuOpen: false }" @scroll.window="scrolled = (window.pageYOffset > 20)">

    <!-- Navigation -->
    <nav :class="(scrolled || mobileMenuOpen) ? 'nav-scrolled' : 'nav-transparent'" class="fixed top-0 w-full z-50 transition-all duration-300">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <a href="/" class="flex items-center space-x-2 group flex-shrink-0">
                    <img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" alt="TaxNest" class="h-6 w-auto">
                    <div class="h-4 w-px mx-2 bg-white/30"></div>
                    <div>
                        <span class="text-lg font-serif font-bold tracking-tight block leading-none text-white">NestPOS</span>
                        <span class="text-[10px] font-semibold uppercase tracking-widest text-white/70 block leading-none mt-1">PRA POS</span>
                    </div>
                </a>
                
                <div class="hidden md:flex items-center space-x-8">
                    <a href="#features" class="text-sm font-medium text-gray-200 hover:text-white transition-colors">Features</a>
                    <a href="#editions" class="text-sm font-medium text-gray-200 hover:text-white transition-colors">Editions & Pricing</a>
                </div>
                
                <div class="flex items-center space-x-4">
                    <a href="/pos/login" class="hidden sm:inline text-sm font-medium text-gray-200 hover:text-white transition-colors">Log In</a>
                    <a href="/pos/register" class="btn-solid bg-white text-[#052730] hover:bg-gray-100">Start Free Trial</a>
                    <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden p-2 -mr-2" aria-label="Menu">
                        <svg x-show="!mobileMenuOpen" class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        <svg x-show="mobileMenuOpen" x-cloak class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
            
            <div x-show="mobileMenuOpen" x-cloak @click.away="mobileMenuOpen = false" class="md:hidden border-t border-white/10 py-4 space-y-1 bg-[#052730]">
                <a href="#features" @click="mobileMenuOpen = false" class="block px-4 py-2.5 text-sm font-medium text-gray-200 hover:bg-white/5">Features</a>
                <a href="#editions" @click="mobileMenuOpen = false" class="block px-4 py-2.5 text-sm font-medium text-gray-200 hover:bg-white/5">Editions & Pricing</a>
                <a href="/pos/login" class="block px-4 py-2.5 text-sm font-medium text-gray-200 hover:bg-white/5">Log In</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="relative bg-[#052730] pt-32 pb-20 lg:pt-48 lg:pb-32 overflow-hidden border-b border-[#0A4D5C]">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="flex flex-col lg:flex-row gap-12 lg:gap-24 items-start">
                
                <div class="w-full lg:w-6/12 pt-8 lg:pt-16">
                    <div class="inline-block border-l-2 border-purple-700 pl-3 text-purple-400 font-mono text-xs uppercase tracking-widest mb-8">
                        PRA Integrated POS
                    </div>
                    
                    <h1 class="text-5xl sm:text-6xl lg:text-7xl font-serif text-white leading-tight mb-8">
                        The rush hour register.
                    </h1>
                    <p class="text-lg lg:text-xl text-gray-300 leading-relaxed mb-12 font-light max-w-lg">
                        A point of sale built for Punjab's busiest shop counters. Clear the evening queue using only the keyboard. PRA fiscal reporting happens automatically in the background.
                    </p>
                    
                    <div class="flex flex-col sm:flex-row items-start gap-6">
                        <a href="/pos/register" class="btn-solid bg-[#E7BF3B] text-[#062A33] hover:bg-[#F2D06B] px-8 py-4 text-base">
                            Start 3-Day Free Trial
                        </a>
                        <div class="flex items-center h-full pt-4 sm:pt-0">
                            <span class="font-mono text-xs text-white/50">No credit card required.</span>
                        </div>
                    </div>
                </div>

                <div class="w-full lg:w-5/12 relative mt-8 lg:mt-0 lg:ml-auto">
                    <!-- HTML Receipt Artifact -->
                    <div class="thermal-receipt text-gray-800 font-mono text-sm p-8 max-w-sm mx-auto transform rotate-2">
                        <div class="text-center mb-6 border-b-2 border-dashed border-gray-300 pb-6">
                            <h3 class="font-bold text-xl uppercase mb-1">Bismillah Kiryana</h3>
                            <p class="text-xs">Main Bazar, Model Town, Lahore</p>
                            <p class="text-xs">NTN: 4123456-7</p>
                            <p class="text-xs">PNTN: P4123456-7</p>
                        </div>
                        <div class="flex justify-between text-xs mb-4 text-gray-600">
                            <span>Date: {{ date('d/m/Y') }}</span>
                            <span>Time: 19:42</span>
                        </div>
                        <div class="flex justify-between text-xs mb-2 font-bold border-b border-gray-300 pb-2">
                            <span>Item</span>
                            <span>Amount</span>
                        </div>
                        <div class="space-y-2 text-xs mb-4">
                            <div class="flex justify-between">
                                <span>Sugar Premium 1kg</span>
                                <span>165</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Dal Chana 1kg</span>
                                <span>320</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Surf Excel 1kg</span>
                                <span>550</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Lipton Yellow 500g</span>
                                <span>480</span>
                            </div>
                        </div>
                        <div class="border-t border-gray-300 pt-2 space-y-1 text-xs text-gray-600">
                            <div class="flex justify-between">
                                <span>Subtotal</span>
                                <span>1515</span>
                            </div>
                            <div class="flex justify-between">
                                <span>PRA Tax (16%)</span>
                                <span>242</span>
                            </div>
                        </div>
                        <div class="border-t-2 border-gray-800 mt-2 pt-2 mb-6">
                            <div class="flex justify-between font-bold text-base">
                                <span>Total</span>
                                <span>Rs 1757</span>
                            </div>
                        </div>
                        <div class="text-center text-xs space-y-3">
                            <div class="inline-block border border-gray-800 px-3 py-1 text-gray-800 font-bold tracking-widest uppercase">
                                PRA Verified
                            </div>
                            <p class="text-gray-500">Invoice: 1004593</p>
                            <p class="text-gray-500">Thank you for shopping!</p>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>
    </section>

    <!-- Stat Band -->
    <section class="border-b border-[#0A4D5C] bg-[#07333E] py-12">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row justify-between items-center text-center md:text-left gap-8">
                <div class="font-serif text-3xl lg:text-4xl text-white">Faster checkouts.</div>
                <div class="font-serif text-3xl lg:text-4xl text-[#E7BF3B] italic">Zero compliance headaches.</div>
            </div>
        </div>
    </section>

    <!-- Editorial Features -->
    <section id="features" class="py-24 bg-[#FDFBF7]">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl mb-20">
                <h2 class="text-4xl sm:text-5xl font-serif text-[#052730] mb-6">Built for speed and compliance.</h2>
                <p class="text-xl text-gray-600 font-light">Every feature serves one purpose: getting the customer checked out quickly while keeping your business completely compliant with government regulations.</p>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-16 gap-y-20">
                <!-- Feature 1 -->
                <div class="border-t-2 border-[#0A4D5C] pt-6 relative group">
                    <div class="absolute right-0 top-6 text-[#E7BF3B] opacity-20 font-serif text-8xl leading-none transition-transform group-hover:-translate-y-2">1</div>
                    <h3 class="text-2xl font-serif text-[#052730] mb-4 relative z-10">Keyboard billing</h3>
                    <p class="text-gray-600 text-lg leading-relaxed relative z-10">Guided billing walks the cashier from scanning to payment on the Enter key alone. Process entire orders without ever touching a mouse.</p>
                </div>
                
                <!-- Feature 2 -->
                <div class="border-t-2 border-[#0A4D5C] pt-6 relative group">
                    <div class="absolute right-0 top-6 text-[#E7BF3B] opacity-20 font-serif text-8xl leading-none transition-transform group-hover:-translate-y-2">2</div>
                    <h3 class="text-2xl font-serif text-[#052730] mb-4 relative z-10">PRA fiscal integration</h3>
                    <p class="text-gray-600 text-lg leading-relaxed relative z-10">Direct connection to the Punjab Revenue Authority. Choose between cloud submission or desktop fiscal-device mode for immediate, background compliance.</p>
                </div>

                <!-- Feature 3 -->
                <div class="border-t-2 border-[#0A4D5C] pt-6 relative group">
                    <div class="absolute right-0 top-6 text-[#E7BF3B] opacity-20 font-serif text-8xl leading-none transition-transform group-hover:-translate-y-2">3</div>
                    <h3 class="text-2xl font-serif text-[#052730] mb-4 relative z-10">Offline resilience</h3>
                    <p class="text-gray-600 text-lg leading-relaxed relative z-10">Internet outages shouldn't stop your register. Bills print offline and synchronize automatically with the PRA the moment your connection returns.</p>
                </div>

                <!-- Feature 4 -->
                <div class="border-t-2 border-[#0A4D5C] pt-6 relative group">
                    <div class="absolute right-0 top-6 text-[#E7BF3B] opacity-20 font-serif text-8xl leading-none transition-transform group-hover:-translate-y-2">4</div>
                    <h3 class="text-2xl font-serif text-[#052730] mb-4 relative z-10">Restaurant ready</h3>
                    <p class="text-gray-600 text-lg leading-relaxed relative z-10">Kitchen order tickets (KOT), dine-in table board, kitchen display screen, waiter tablets and a scan-to-order QR menu — with cancel protection that records exactly which items were already made, so kitchen waste never hides.</p>
                </div>

                <!-- Feature 5 -->
                <div class="border-t-2 border-[#0A4D5C] pt-6 relative group">
                    <div class="absolute right-0 top-6 text-[#E7BF3B] opacity-20 font-serif text-8xl leading-none transition-transform group-hover:-translate-y-2">5</div>
                    <h3 class="text-2xl font-serif text-[#052730] mb-4 relative z-10">Delivery riders &amp; khata</h3>
                    <p class="text-gray-600 text-lg leading-relaxed relative z-10">Assign every delivery to a rider, track the cash each rider owes, settle bills the moment they return — and a live pending-bills tile on the dashboard so nothing is forgotten at closing time.</p>
                </div>

                <!-- Feature 6 -->
                <div class="border-t-2 border-[#0A4D5C] pt-6 relative group">
                    <div class="absolute right-0 top-6 text-[#E7BF3B] opacity-20 font-serif text-8xl leading-none transition-transform group-hover:-translate-y-2">6</div>
                    <h3 class="text-2xl font-serif text-[#052730] mb-4 relative z-10">The manager's cockpit</h3>
                    <p class="text-gray-600 text-lg leading-relaxed relative z-10">Staff attendance (hazri), cancelled-orders and waste reports, analytics dashboards with PDF export, and a day-close that can auto-finalize leftover bills — the whole day reconciled in one screen.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonial -->
    <section class="py-24 bg-[#052730] text-white border-y border-[#0A4D5C]">
        <div class="max-w-[1000px] mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h2 class="text-3xl sm:text-4xl lg:text-5xl font-serif leading-tight mb-10 text-white/90">"At 7 PM, my counter is packed. I cannot afford a system that freezes or requires five clicks per customer. NestPOS lets my cashier ring up items as fast as he can scan them. The PRA guys are happy, and my line keeps moving."</h2>
            <div class="text-[#E7BF3B] font-mono text-sm tracking-widest uppercase">
                — M. Tariq, Madina Super Mart, Faisalabad
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section id="editions" class="py-24 bg-[#FDFBF7]">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto mb-16">
                <h2 class="text-4xl font-serif text-[#052730] mb-6">Simple pricing.</h2>
                <p class="text-gray-600 font-light text-lg">One edition, full PRA compliance — pick the plan that fits your shop.</p>
            </div>

            <div class="grid grid-cols-1 gap-8 max-w-2xl mx-auto">
                <!-- PRA Integrated Edition -->
                <div class="bg-white border-t-4 border-purple-700 shadow-xl p-8 flex flex-col relative">
                    <div class="inline-block px-3 py-1 bg-purple-50 text-purple-700 font-bold text-xs uppercase tracking-widest mb-6 self-start">
                        PRA Integrated
                    </div>
                    <h4 class="text-2xl font-serif text-[#052730] mb-3">Full PRA Compliance</h4>
                    <p class="text-sm text-gray-600 mb-8">The complete NestPOS with automatic Punjab Revenue Authority fiscal reporting built in.</p>
                    
                    <div class="flex-grow space-y-4">
                        @if(isset($plans) && $plans->count())
                            @foreach($plans as $plan)
                                @php
                                    $perMonth = round($plan->sale_price / 12);
                                    $hasOffer = $plan->sale_percent > 0;
                                    $features = is_array($plan->features) ? $plan->features : (is_string($plan->features) ? json_decode($plan->features, true) : []);
                                    $prevPlan = $loop->index > 0 ? ($plans[$loop->index - 1] ?? null) : null;
                                    $isPopular = $plan->name === 'Business';
                                @endphp
                                <div class="p-5 border {{ $isPopular ? 'border-[#0A4D5C] ring-1 ring-[#0A4D5C]' : 'border-purple-700/20' }} bg-purple-50/30 relative overflow-hidden">
                                    @if($hasOffer)
                                        <div class="absolute top-0 right-0 bg-[#0A4D5C] text-white text-[10px] font-bold px-2 py-0.5">
                                            {{ $plan->sale_badge }}
                                        </div>
                                    @endif
                                    <div class="flex justify-between items-start mb-2">
                                        <div>
                                            <h5 class="font-bold text-gray-900">{{ $plan->name }}</h5>
                                            @if($isPopular)
                                                <span class="inline-block mt-1 px-2 py-0.5 bg-[#0A4D5C] text-white text-[9px] font-bold uppercase tracking-widest">Most Popular</span>
                                            @endif
                                        </div>
                                        <div class="text-right">
                                            @if($hasOffer)
                                                <div class="text-[10px] text-gray-400 line-through mb-0.5">PKR {{ number_format($plan->price) }}</div>
                                            @endif
                                            <div class="font-semibold text-xl text-[#0A4D5C]">PKR {{ number_format($plan->sale_price) }}<span class="text-sm text-gray-500 font-normal">/yr</span></div>
                                        </div>
                                    </div>
                                    <p class="text-xs text-gray-500 mb-3">Effective: PKR {{ number_format($perMonth) }}/mo</p>
                                    <p class="text-xs font-semibold text-gray-800 mb-1">
                                        {{ $plan->getInvoiceLimitDisplay() }} bills/month
                                        · {{ $plan->getUserLimitDisplay() }} team account{{ $plan->user_limit === 1 ? '' : 's' }}
                                        · {{ $plan->getBranchLimitDisplay() }} branch{{ $plan->branch_limit === 1 ? '' : 'es' }}
                                    </p>
                                    @if(!empty($features))
                                        <div class="mt-4 border-t border-purple-700/10 pt-4">
                                            @if($prevPlan)
                                                <p class="text-[10px] font-bold uppercase tracking-widest text-[#0A4D5C] mb-2">Everything in {{ $prevPlan->name }}, plus:</p>
                                            @endif
                                            <ul class="space-y-2">
                                                @foreach($features as $feature)
                                                <li class="flex items-start text-sm text-gray-700">
                                                    <span class="text-[#0A4D5C] mr-2 mt-0.5 text-xs">■</span>
                                                    {{ $feature }}
                                                </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        @else
                            <div class="p-6 border border-gray-200 text-center text-gray-500 text-sm">
                                Plans loading...
                            </div>
                        @endif
                    </div>
                </div>

            </div>
            
            <div class="mt-16 text-center">
                <a href="/pos/register" class="btn-solid bg-[#052730] text-white hover:bg-[#0A4D5C] px-8 py-4 text-base">
                    Start Your Free Trial
                </a>
                <p class="mt-4 text-xs font-medium text-gray-500 font-mono">3-day trial. No credit card.</p>
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section class="py-24 bg-white border-t border-gray-200">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-4xl font-serif text-[#052730]">Common Questions</h2>
            </div>
            <div class="space-y-2" x-data="{ open: null }">
                <div class="border-b border-gray-200">
                    <button @click="open = (open === 1 ? null : 1)" class="w-full flex items-center justify-between py-6 text-left">
                        <span class="font-serif text-lg text-gray-900">How does the PRA connection actually work?</span>
                        <span class="text-gray-400 font-mono text-xl" x-text="open === 1 ? '-' : '+'">+</span>
                    </button>
                    <div x-show="open === 1" x-collapse class="pb-6 text-gray-600 leading-relaxed font-light">Two modes. Cloud mode submits bills straight from our servers to PRA. Fiscal-device mode runs a small desktop agent on your counter PC for shops whose PRA registration requires the local fiscal service — the system queues bills and the agent fiscalizes them automatically.</div>
                </div>
                <div class="border-b border-gray-200">
                    <button @click="open = (open === 2 ? null : 2)" class="w-full flex items-center justify-between py-6 text-left">
                        <span class="font-serif text-lg text-gray-900">Does it work for restaurants?</span>
                        <span class="text-gray-400 font-mono text-xl" x-text="open === 2 ? '-' : '+'">+</span>
                    </button>
                    <div x-show="open === 2" x-collapse class="pb-6 text-gray-600 leading-relaxed font-light">Yes — a full restaurant module: dine-in / takeaway / delivery order types, table management, kitchen order tickets and held orders, all on the same keyboard-fast sale screen.</div>
                </div>
                <div class="border-b border-gray-200">
                    <button @click="open = (open === 3 ? null : 3)" class="w-full flex items-center justify-between py-6 text-left">
                        <span class="font-serif text-lg text-gray-900">What if my internet goes down mid-day?</span>
                        <span class="text-gray-400 font-mono text-xl" x-text="open === 3 ? '-' : '+'">+</span>
                    </button>
                    <div x-show="open === 3" x-collapse class="pb-6 text-gray-600 leading-relaxed font-light">Keep selling. Bills print offline and sync to PRA automatically when the connection returns — no manual re-entry, no lost sales.</div>
                </div>
                <div class="border-b border-gray-200">
                    <button @click="open = (open === 4 ? null : 4)" class="w-full flex items-center justify-between py-6 text-left">
                        <span class="font-serif text-lg text-gray-900">What do the plans include?</span>
                        <span class="text-gray-400 font-mono text-xl" x-text="open === 4 ? '-' : '+'">+</span>
                    </button>
                    <div x-show="open === 4" x-collapse class="pb-6 text-gray-600 leading-relaxed font-light">Starter covers your owner account plus 1 team account with up to 500 PRA bills per month. Business adds up to 5 team accounts and 2,000 bills per month. Pro is the complete POS — full restaurant module, delivery riders & khata, public QR menu, analytics, 10 team accounts, 2 branches and 3,000 bills per month. Pro Max adds Staff Hazri (attendance) with 15 team accounts, 3 branches and 5,000 bills per month. Unlimited is fully unrestricted — Rider Live Tracking on a map, unlimited team accounts, unlimited branches, unlimited billing, every feature (including Team Custom Access) unlocked, with priority support.</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <x-site-footer login-url="/pos/login" login-label="POS Log In" />

    <x-whatsapp-support />

</body>
</html>