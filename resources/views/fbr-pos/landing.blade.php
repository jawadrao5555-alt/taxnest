<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>FBR POS — Bank-Grade Point of Sale by TaxNest</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: 'Figtree', sans-serif; }
        [x-cloak] { display: none !important; }
        
        /* Bank-grade aesthetics: Solid, precise, no frills */
        .bg-fbr-blue { background-color: #0b1d3a; }
        .text-fbr-blue { color: #0b1d3a; }
        .border-fbr-blue { border-color: #0b1d3a; }
        
        .bg-fbr-accent { background-color: #1a4073; }
        .text-fbr-accent { color: #1a4073; }
        
        .solid-shadow { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); }
        .heavy-shadow { box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); }
        
        .terminal-grid {
            background-size: 40px 40px;
            background-image: linear-gradient(to right, rgba(255,255,255,0.03) 1px, transparent 1px),
                              linear-gradient(to bottom, rgba(255,255,255,0.03) 1px, transparent 1px);
        }
    </style>
</head>
<body class="antialiased text-slate-800 bg-slate-50" x-data="{ showLoginModal: false, mobileMenuOpen: false }">

    <!-- Login Modal -->
    <div x-show="showLoginModal" x-cloak 
         x-transition.opacity.duration.200ms
         class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm px-4" 
         @click.self="showLoginModal = false" @keydown.escape.window="showLoginModal = false">
        
        <div x-show="showLoginModal" 
             x-transition:enter="transition ease-out duration-200" 
             x-transition:enter-start="opacity-0 scale-95" 
             x-transition:enter-end="opacity-100 scale-100" 
             x-transition:leave="transition ease-in duration-150" 
             x-transition:leave-start="opacity-100 scale-100" 
             x-transition:leave-end="opacity-0 scale-95" 
             class="w-full max-w-md bg-white rounded-lg heavy-shadow overflow-hidden">
            
            <div class="px-6 py-4 bg-slate-50 border-b border-slate-200 flex justify-between items-center">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 rounded bg-fbr-blue flex items-center justify-center">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-slate-900">FBR POS Secure Login</h3>
                    </div>
                </div>
                <button @click="showLoginModal = false" class="text-slate-400 hover:text-slate-600 transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            
            <form method="POST" action="/fbr-pos/login" class="p-6 space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Email, Phone, Username, CNIC or NTN</label>
                    <input type="text" name="login" required autofocus 
                           class="w-full px-4 py-2 border border-slate-300 rounded-md text-sm focus:ring-2 focus:ring-fbr-accent focus:border-fbr-accent outline-none transition-colors" 
                           placeholder="Enter your credential">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Password</label>
                    <input type="password" name="password" required autocomplete="current-password" 
                           class="w-full px-4 py-2 border border-slate-300 rounded-md text-sm focus:ring-2 focus:ring-fbr-accent focus:border-fbr-accent outline-none transition-colors" 
                           placeholder="Enter your password">
                </div>
                
                <div class="flex items-center justify-between pt-2">
                    <label class="flex items-center">
                        <input type="checkbox" name="remember" class="rounded border-slate-300 text-fbr-blue focus:ring-fbr-accent w-4 h-4">
                        <span class="ml-2 text-sm text-slate-600">Remember me</span>
                    </label>
                    <a href="{{ route('password.request') }}" class="text-sm font-medium text-fbr-accent hover:text-fbr-blue transition">Forgot Password?</a>
                </div>
                
                @if($errors->any())
                <div class="bg-red-50 border border-red-200 rounded-md p-3">
                    @foreach($errors->all() as $error)
                    <p class="text-sm font-medium text-red-600">{{ $error }}</p>
                    @endforeach
                </div>
                @endif
                
                <div class="pt-2">
                    <button type="submit" class="w-full py-2.5 bg-fbr-blue hover:bg-fbr-accent text-white rounded-md text-sm font-bold transition-colors">
                        Sign In to POS
                    </button>
                </div>
                
                <div class="text-center pt-2">
                    <span class="text-sm text-slate-500">New retailer?</span>
                    <a href="/fbr-pos/register" class="text-sm font-bold text-fbr-accent hover:text-fbr-blue transition">Start 3-Day Free Trial</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="fixed top-0 w-full z-50 bg-white border-b border-slate-200 shadow-sm transition-all">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center">
                    <a href="/" class="flex items-center">
                        <img src="{{ asset('images/brand/taxnest-logo.svg') }}" alt="TaxNest" class="h-8 w-auto">
                    </a>
                    <div class="hidden sm:block w-px h-6 bg-slate-300 mx-4"></div>
                    <span class="hidden sm:block text-sm font-bold tracking-tight text-slate-800 uppercase">FBR POS TERMINAL</span>
                </div>
                
                <!-- Desktop Nav -->
                <div class="hidden md:flex items-center space-x-6">
                    <a href="#capabilities" class="text-sm font-semibold text-slate-600 hover:text-slate-900 transition">Capabilities</a>
                    <a href="#compliance" class="text-sm font-semibold text-slate-600 hover:text-slate-900 transition">Compliance</a>
                    <a href="#pricing" class="text-sm font-semibold text-slate-600 hover:text-slate-900 transition">Pricing</a>
                    
                    <div class="w-px h-4 bg-slate-300"></div>
                    
                    <button @click="showLoginModal = true" class="text-sm font-bold text-slate-700 hover:text-fbr-blue transition">Sign In</button>
                    <a href="/fbr-pos/register" class="px-4 py-2 bg-fbr-blue text-white rounded text-sm font-bold hover:bg-fbr-accent transition-colors">Start Free Trial</a>
                </div>

                <!-- Mobile Menu Button -->
                <div class="md:hidden flex items-center">
                    <button @click="mobileMenuOpen = !mobileMenuOpen" class="text-slate-600 hover:text-slate-900 focus:outline-none">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                            <path x-show="mobileMenuOpen" x-cloak stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Nav -->
        <div x-show="mobileMenuOpen" x-cloak class="md:hidden border-t border-slate-200 bg-white">
            <div class="px-4 pt-2 pb-4 space-y-1">
                <a href="#capabilities" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-700 hover:text-slate-900 hover:bg-slate-50" @click="mobileMenuOpen = false">Capabilities</a>
                <a href="#compliance" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-700 hover:text-slate-900 hover:bg-slate-50" @click="mobileMenuOpen = false">Compliance</a>
                <a href="#pricing" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-700 hover:text-slate-900 hover:bg-slate-50" @click="mobileMenuOpen = false">Pricing</a>
                <div class="border-t border-slate-200 my-2 pt-2"></div>
                <button @click="showLoginModal = true; mobileMenuOpen = false" class="w-full text-left block px-3 py-2 rounded-md text-base font-bold text-slate-700 hover:text-slate-900 hover:bg-slate-50">Sign In</button>
                <a href="/fbr-pos/register" class="block px-3 py-2 rounded-md text-base font-bold text-white bg-fbr-blue hover:bg-fbr-accent text-center mt-2" @click="mobileMenuOpen = false">Start Free Trial</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="bg-fbr-blue pt-32 pb-20 lg:pt-40 lg:pb-28 relative overflow-hidden terminal-grid">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <div class="max-w-2xl">
                    <div class="inline-flex items-center px-3 py-1 rounded border border-white/20 bg-white/5 backdrop-blur-sm mb-6">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 mr-2"></span>
                        <span class="text-xs font-bold text-white tracking-wider uppercase">Direct FBR API Integration</span>
                    </div>
                    
                    <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-white leading-tight mb-6 tracking-tight">
                        The Bank-Grade <br />
                        <span class="text-blue-300">Point of Sale</span>
                    </h1>
                    
                    <p class="text-lg text-slate-300 mb-8 max-w-xl font-medium">
                        Built for Pakistani retailers registered with the FBR POS integration regime. Every sale is fiscalized and submitted directly from the counter. Sturdy, secure, and precise.
                    </p>
                    
                    <div class="flex flex-col sm:flex-row gap-4">
                        <a href="/fbr-pos/register" class="inline-flex justify-center items-center px-6 py-3 bg-white text-fbr-blue rounded font-bold text-base hover:bg-slate-100 transition-colors solid-shadow">
                            Start 3-Day Free Trial
                        </a>
                        <button @click="showLoginModal = true" class="inline-flex justify-center items-center px-6 py-3 border-2 border-slate-600 text-white rounded font-bold text-base hover:border-slate-400 transition-colors">
                            Sign In to Terminal
                        </button>
                    </div>
                    <p class="mt-4 text-sm text-slate-400 font-medium">No credit card required for trial.</p>
                </div>
                
                <div class="relative lg:h-[600px] flex items-center justify-center">
                    <!-- Browser/Terminal Frame -->
                    <div class="w-full bg-slate-800 rounded-lg heavy-shadow overflow-hidden border border-slate-700">
                        <div class="bg-slate-900 px-4 py-3 flex items-center border-b border-slate-800">
                            <div class="flex space-x-2">
                                <div class="w-3 h-3 rounded-full bg-slate-700"></div>
                                <div class="w-3 h-3 rounded-full bg-slate-700"></div>
                                <div class="w-3 h-3 rounded-full bg-slate-700"></div>
                            </div>
                            <div class="mx-auto text-xs font-mono text-slate-500 tracking-widest">TAXNEST / FBR POS</div>
                        </div>
                        <img src="{{ asset('images/screenshots/fbr-sale.jpg') }}" alt="FBR POS Sale Screen" class="w-full h-auto object-cover object-top border-b border-slate-800">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Capabilities Grid -->
    <section id="capabilities" class="py-20 bg-white border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <h2 class="text-3xl font-extrabold text-slate-900 tracking-tight">Engineered for Precision</h2>
                <p class="mt-4 text-lg text-slate-600">A sturdy billing system that handles compliance complexity silently.</p>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <!-- Feature 1 -->
                <div class="bg-slate-50 border border-slate-200 rounded p-6">
                    <div class="w-10 h-10 bg-fbr-blue rounded flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 mb-2">Direct FBR Submission</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">Real-time invoice fiscalization. Bills are submitted directly to FBR's API from the sale screen the moment payment is confirmed.</p>
                </div>
                
                <!-- Feature 2 -->
                <div class="bg-slate-50 border border-slate-200 rounded p-6">
                    <div class="w-10 h-10 bg-fbr-blue rounded flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 mb-2">Confidential PIN System</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">Sensitive actions are guarded by a secure PIN. Protect voids, discounts, and reporting access from unauthorized counter staff.</p>
                </div>
                
                <!-- Feature 3 -->
                <div class="bg-slate-50 border border-slate-200 rounded p-6">
                    <div class="w-10 h-10 bg-fbr-blue rounded flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 mb-2">Edit & Retry Rejected Bills</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">Never lose a transaction to a network error. FBR-rejected bills drop into a queue where they can be edited, corrected, and resubmitted.</p>
                </div>
                
                <!-- Feature 4 -->
                <div class="bg-slate-50 border border-slate-200 rounded p-6">
                    <div class="w-10 h-10 bg-fbr-blue rounded flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 mb-2">Per-Product Tax Configuration</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">Assign specific tax rates per item (18% default) or manage exemptions automatically without counter-staff intervention.</p>
                </div>
                
                <!-- Feature 5 -->
                <div class="bg-slate-50 border border-slate-200 rounded p-6">
                    <div class="w-10 h-10 bg-fbr-blue rounded flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 mb-2">Keyboard-Only Cart Flow</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">Built for speed. Unified smart input box handles both barcode scanning and text searches seamlessly. Full keyboard navigation available.</p>
                </div>
                
                <!-- Feature 6 -->
                <div class="bg-slate-50 border border-slate-200 rounded p-6">
                    <div class="w-10 h-10 bg-fbr-blue rounded flex items-center justify-center mb-5">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 mb-2">Held Sales & Offline Resilience</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">Park a cart for a waiting customer and recall it later. Continue billing smoothly even during temporary network interruptions.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Compliance deep dive -->
    <section id="compliance" class="py-20 bg-slate-50 border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">
                <div class="order-2 lg:order-1">
                    <div class="bg-white p-2 rounded-lg border border-slate-200 solid-shadow">
                        <img src="{{ asset('images/screenshots/fbr-tx.jpg') }}" alt="FBR Transactions" class="w-full rounded border border-slate-100">
                    </div>
                    <div class="bg-white p-2 rounded-lg border border-slate-200 solid-shadow mt-6 sm:-mt-12 sm:ml-12 relative z-10">
                        <img src="{{ asset('images/screenshots/fbr-dash.jpg') }}" alt="FBR Dashboard" class="w-full rounded border border-slate-100">
                    </div>
                </div>
                
                <div class="order-1 lg:order-2">
                    <h2 class="text-3xl font-extrabold text-slate-900 mb-6 tracking-tight">Zero Drama at Closing Time</h2>
                    <p class="text-lg text-slate-600 mb-8">
                        Every aspect of the POS is designed to ensure strict compliance. We eliminate the guesswork from daily reporting and invoice tracking.
                    </p>
                    
                    <ul class="space-y-6">
                        <li class="flex">
                            <div class="flex-shrink-0 mt-1">
                                <div class="w-6 h-6 rounded-full bg-slate-200 flex items-center justify-center">
                                    <svg class="w-4 h-4 text-fbr-blue" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                </div>
                            </div>
                            <div class="ml-4">
                                <h4 class="text-base font-bold text-slate-900">Provisional Bills & Mandatory Confirmation</h4>
                                <p class="mt-1 text-sm text-slate-600">Draft provisional bills confidently. The payment-confirmation step is mandatory before FBR fiscalization occurs.</p>
                            </div>
                        </li>
                        <li class="flex">
                            <div class="flex-shrink-0 mt-1">
                                <div class="w-6 h-6 rounded-full bg-slate-200 flex items-center justify-center">
                                    <svg class="w-4 h-4 text-fbr-blue" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                </div>
                            </div>
                            <div class="ml-4">
                                <h4 class="text-base font-bold text-slate-900">Day-Close Reports</h4>
                                <p class="mt-1 text-sm text-slate-600">Reconcile cash drawers precisely. Comprehensive day-close reporting summarizes daily revenue against FBR submitted totals.</p>
                            </div>
                        </li>
                        <li class="flex">
                            <div class="flex-shrink-0 mt-1">
                                <div class="w-6 h-6 rounded-full bg-slate-200 flex items-center justify-center">
                                    <svg class="w-4 h-4 text-fbr-blue" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                </div>
                            </div>
                            <div class="ml-4">
                                <h4 class="text-base font-bold text-slate-900">FBR Reporting Toggle</h4>
                                <p class="mt-1 text-sm text-slate-600">Maintain operational flexibility. Toggle FBR reporting strictly when required according to your registration status.</p>
                            </div>
                        </li>
                        <li class="flex">
                            <div class="flex-shrink-0 mt-1">
                                <div class="w-6 h-6 rounded-full bg-slate-200 flex items-center justify-center">
                                    <svg class="w-4 h-4 text-fbr-blue" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                </div>
                            </div>
                            <div class="ml-4">
                                <h4 class="text-base font-bold text-slate-900">Installable PWA & Thermal Printing</h4>
                                <p class="mt-1 text-sm text-slate-600">Install as a desktop app. Print standard thermal receipts instantly upon successful transaction recording.</p>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing -->
    <section id="pricing" class="py-24 bg-white border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <h2 class="text-3xl font-extrabold text-slate-900 tracking-tight">Annual Plans</h2>
                <p class="mt-4 text-lg text-slate-600">Predictable annual pricing. 6% discount baked directly into all plans.</p>
            </div>
            
            @if(isset($plans) && $plans->count())
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 max-w-5xl mx-auto items-start">
                @foreach($plans as $plan)
                @php
                    $isPopular = $plan->name === 'Business';
                    $planFeatures = is_array($plan->features) ? $plan->features : (is_string($plan->features) ? json_decode($plan->features, true) : []);
                    // Plan prices are monthly; annual billing gets the 6% discount (matches in-app billing).
                    $annualSalePrice = round($plan->sale_price * 12 * 0.94);
                    $annualComparePrice = round($plan->price * 12 * 0.94);
                @endphp
                
                <div class="bg-white border {{ $isPopular ? 'border-fbr-blue ring-1 ring-fbr-blue shadow-lg' : 'border-slate-200 solid-shadow' }} rounded flex flex-col h-full relative overflow-hidden">
                    
                    @if($isPopular)
                    <div class="bg-fbr-blue text-white text-xs font-bold uppercase tracking-wider text-center py-2 w-full">
                        Recommended for Retail
                    </div>
                    @endif
                    
                    <div class="p-8 flex-grow flex flex-col">
                        <h3 class="text-xl font-bold text-slate-900 mb-2">{{ $plan->name }}</h3>
                        
                        <div class="mb-6 flex items-baseline">
                            <span class="text-sm font-semibold text-slate-500 mr-1">PKR</span>
                            <span class="text-4xl font-extrabold text-slate-900 tracking-tight">{{ number_format($annualSalePrice) }}</span>
                            <span class="text-sm text-slate-500 ml-1 font-medium">/ year</span>
                        </div>
                        
                        @if($plan->price > $plan->sale_price)
                        <div class="mb-4 text-sm">
                            <span class="line-through text-slate-400 font-medium">PKR {{ number_format($annualComparePrice) }}/yr</span>
                            @if($plan->sale_badge)
                            <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-slate-100 text-slate-800">
                                {{ $plan->sale_badge }}
                            </span>
                            @endif
                        </div>
                        @endif
                        
                        <a href="/fbr-pos/register" class="w-full py-3 px-4 rounded text-sm font-bold text-center transition-colors mb-8 {{ $isPopular ? 'bg-fbr-blue text-white hover:bg-fbr-accent' : 'bg-slate-100 text-slate-900 hover:bg-slate-200' }}">
                            Start Free Trial
                        </a>
                        
                        <ul class="space-y-4 flex-grow">
                            @if(!empty($planFeatures))
                                @foreach($planFeatures as $feature)
                                <li class="flex text-sm text-slate-700">
                                    <svg class="w-5 h-5 text-fbr-blue mr-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <span>{{ $feature }}</span>
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
    <section class="py-20 bg-white border-t border-slate-200">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <p class="text-xs font-bold tracking-widest uppercase text-fbr-blue mb-3">FAQ</p>
                <h2 class="text-3xl font-extrabold text-slate-900 tracking-tight">Frequently asked questions</h2>
            </div>
            <div class="space-y-3" x-data="{ open: null }">
                <div class="bg-white border border-slate-200 rounded-lg">
                    <button @click="open = (open === 1 ? null : 1)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-slate-900 text-sm">Are bills really submitted to FBR in real time?</span>
                        <svg class="w-4 h-4 text-slate-400 transition-transform flex-shrink-0 ml-4" :class="open === 1 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 1" x-collapse class="px-5 pb-5 text-sm text-slate-600 leading-relaxed">Yes. Each completed sale goes straight to FBR's POS service and the fiscal invoice number prints on the receipt. If FBR rejects a bill, it lands in a fail queue where you can fix the data and retry — nothing silently disappears.</div>
                </div>
                <div class="bg-white border border-slate-200 rounded-lg">
                    <button @click="open = (open === 2 ? null : 2)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-slate-900 text-sm">Can I run the whole sale from the keyboard?</span>
                        <svg class="w-4 h-4 text-slate-400 transition-transform flex-shrink-0 ml-4" :class="open === 2 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 2" x-collapse class="px-5 pb-5 text-sm text-slate-600 leading-relaxed">Yes — scan or type in one smart input, adjust quantities, take payment and print, all without touching the mouse. Barcode scanners work out of the box.</div>
                </div>
                <div class="bg-white border border-slate-200 rounded-lg">
                    <button @click="open = (open === 3 ? null : 3)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-slate-900 text-sm">Do I need to install anything?</span>
                        <svg class="w-4 h-4 text-slate-400 transition-transform flex-shrink-0 ml-4" :class="open === 3 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 3" x-collapse class="px-5 pb-5 text-sm text-slate-600 leading-relaxed">No. It runs in the browser and installs as a PWA for a full-screen, desktop-like terminal on any PC or tablet — updates arrive automatically.</div>
                </div>
                <div class="bg-white border border-slate-200 rounded-lg">
                    <button @click="open = (open === 4 ? null : 4)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-slate-900 text-sm">How is billing structured?</span>
                        <svg class="w-4 h-4 text-slate-400 transition-transform flex-shrink-0 ml-4" :class="open === 4 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 4" x-collapse class="px-5 pb-5 text-sm text-slate-600 leading-relaxed">Simple annual billing — one payment a year with the annual discount already applied, starting at PKR 5,629/year. Every account begins with a 3-day free trial.</div>
                </div>
                <div class="bg-white border border-slate-200 rounded-lg">
                    <button @click="open = (open === 5 ? null : 5)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-slate-900 text-sm">Who can approve sensitive actions like provisional bills?</span>
                        <svg class="w-4 h-4 text-slate-400 transition-transform flex-shrink-0 ml-4" :class="open === 5 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 5" x-collapse class="px-5 pb-5 text-sm text-slate-600 leading-relaxed">A confidential owner PIN gates sensitive areas. Cashiers ring up sales; only someone with the PIN can open the provisional-bill list — so control stays with the owner.</div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="bg-fbr-blue py-20 terminal-grid">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h2 class="text-3xl font-extrabold text-white mb-4 tracking-tight">Deploy the Terminal Today</h2>
            <p class="text-lg text-slate-300 mb-8 font-medium">Start your 3-day free trial. No credit card required. No hidden fees.</p>
            <div class="flex flex-col sm:flex-row justify-center gap-4">
                <a href="/fbr-pos/register" class="inline-flex justify-center items-center px-8 py-4 bg-white text-fbr-blue rounded font-bold text-base hover:bg-slate-100 transition-colors solid-shadow">
                    Create Free Account
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-slate-900 py-8 border-t border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row items-center justify-between">
            <div class="flex items-center mb-4 md:mb-0">
                <img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" alt="TaxNest" class="h-6 w-auto opacity-70">
                <span class="ml-3 pl-3 border-l border-slate-700 text-sm font-semibold text-slate-400">FBR POS</span>
            </div>
            <div class="flex items-center space-x-6">
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Secure</span>
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Reliable</span>
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Compliant</span>
            </div>
            <div class="mt-4 md:mt-0 text-sm text-slate-500">
                &copy; {{ date('Y') }} TaxNest. All rights reserved.
            </div>
        </div>
    </footer>

    <x-whatsapp-support />
</body>
</html>
