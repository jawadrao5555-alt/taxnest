<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#FDFBF7">
    <title>Digital Invoice — FBR Invoicing by TaxNest</title>
    
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=playfair-display:400,600,700|inter:400,500,600,700,800&display=swap" rel="stylesheet">
    
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
    <style>
        :root {
            --teal-dark: #052730;
            --teal-main: #0A4D5C;
            --teal-mid: #0F6171;
            --teal-light: #1B7C8C;
            --teal-icon: #2EA0B3;
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

        .bg-grid-pattern-dark {
            background-image: linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
            background-size: 40px 40px;
        }

        .bg-grid-pattern-light {
            background-image: linear-gradient(rgba(0,0,0,0.03) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(0,0,0,0.03) 1px, transparent 1px);
            background-size: 40px 40px;
        }

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
            border: 1px solid transparent;
        }

        .btn-primary {
            background-color: var(--teal-main);
            color: #FFFFFF;
        }

        .btn-primary:hover {
            background-color: #063B47;
        }

        .btn-gold {
            background-color: var(--gold);
            color: #062A33;
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

        .btn-dark {
            background-color: var(--teal-dark);
            color: #FFFFFF;
        }

        .btn-dark:hover {
            background-color: #03151A;
        }

        .glass-dark {
            background-color: rgba(5, 39, 48, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
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

        [x-cloak] { display: none !important; }

        .reveal-on-scroll {
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1), transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        .reveal-on-scroll.is-visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* Invoice Artifact Styling */
        .invoice-paper {
            background-color: #FFFFFF;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04), 0 0 0 1px rgba(0,0,0,0.05);
            font-family: 'Inter', sans-serif;
            color: #1F2937;
        }
        .invoice-table th {
            background-color: #F9FAFB;
            border-y: 1px solid #E5E7EB;
        }
        .invoice-table td, .invoice-table th {
            padding: 0.5rem 0.75rem;
            font-size: 0.75rem;
            text-align: right;
        }
        .invoice-table td:first-child, .invoice-table th:first-child {
            text-align: left;
        }
    </style>
</head>
<body class="relative min-h-[100dvh] flex flex-col" x-data="{ showLoginModal: {{ isset($showLogin) && $showLogin ? 'true' : 'false' }}, scrolled: false, mobileOpen: false }" @scroll.window="scrolled = (window.pageYOffset > 20)">

    <!-- Login Modal -->
    <div x-show="showLoginModal" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center p-4">
        <div class="fixed inset-0 bg-[#052730]/80 backdrop-blur-sm" @click="showLoginModal = false" x-transition.opacity></div>
        <div class="relative w-full max-w-md bg-[#052730] border border-white/15 rounded-xl shadow-2xl overflow-hidden glass-dark" 
             x-transition:enter="transition ease-out duration-300" 
             x-transition:enter-start="opacity-0 scale-95 translate-y-4" 
             x-transition:enter-end="opacity-100 scale-100 translate-y-0" 
             x-transition:leave="transition ease-in duration-200" 
             x-transition:leave-start="opacity-100 scale-100" 
             x-transition:leave-end="opacity-0 scale-95">
            <div class="px-6 py-5 border-b border-white/10 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-[#0A4D5C] text-[#2EA0B3] flex items-center justify-center rounded-lg shadow-sm border border-white/10">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    </div>
                    <div>
                        <h3 class="text-base font-serif font-bold text-white">Digital Invoice</h3>
                        <p class="text-xs text-white/60 tracking-wider uppercase">FBR Portal Access</p>
                    </div>
                </div>
                <button @click="showLoginModal = false" class="text-white/40 hover:text-white transition p-1">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <form method="POST" action="/login" class="px-6 py-6 space-y-5">
                @csrf
                <div>
                    <label class="block text-xs font-bold tracking-wide text-white/70 uppercase mb-2">Identifier</label>
                    <input type="text" name="login" required autofocus class="w-full px-4 py-2.5 rounded-lg bg-white/5 border border-white/15 text-sm text-white focus:ring-2 focus:ring-[#2EA0B3]/30 focus:border-[#2EA0B3] transition-colors placeholder:text-white/30" placeholder="Email, Phone, Username, CNIC or NTN">
                </div>
                <div>
                    <label class="block text-xs font-bold tracking-wide text-white/70 uppercase mb-2">Password</label>
                    <input type="password" name="password" required autocomplete="current-password" class="w-full px-4 py-2.5 rounded-lg bg-white/5 border border-white/15 text-sm text-white focus:ring-2 focus:ring-[#2EA0B3]/30 focus:border-[#2EA0B3] transition-colors placeholder:text-white/30" placeholder="••••••••">
                </div>
                <div class="flex items-center justify-between">
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" name="remember" class="rounded border-white/20 bg-white/5 text-[#0A4D5C] focus:ring-[#2EA0B3] mr-2 w-4 h-4">
                        <span class="text-sm text-white/70">Remember me</span>
                    </label>
                    <a href="{{ route('password.request') ?? '#' }}" class="text-sm text-[#2EA0B3] hover:text-white font-medium transition">Forgot Password?</a>
                </div>
                @if($errors->any())
                <div class="bg-red-500/10 text-red-300 border border-red-500/20 rounded-lg p-3 text-sm">
                    @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                    @endforeach
                </div>
                @endif
                <button type="submit" class="w-full btn-solid btn-gold font-bold">
                    Sign In to Dashboard
                </button>
                <div class="text-center pt-2">
                    <p class="text-sm text-white/50">
                        No account? <a href="/register" class="font-semibold text-[#2EA0B3] hover:text-white transition">Start 3-Day Trial</a>
                    </p>
                </div>
            </form>
        </div>
    </div>

    <!-- Navigation -->
    <nav :class="scrolled ? 'nav-scrolled' : 'nav-transparent'" class="fixed top-0 w-full z-50 transition-all duration-300">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <div class="flex items-center gap-4 sm:gap-8">
                    <a href="/" class="flex-shrink-0">
                        <img src="{{ asset('images/brand/taxnest-logo.svg') }}" alt="TaxNest" class="h-8 w-auto">
                    </a>
                    <div class="hidden md:flex items-center space-x-8">
                        <a href="/pos" class="text-sm font-medium text-gray-600 hover:text-gray-900 transition-colors">PRA POS</a>
                        <a href="/fbr-pos-landing" class="text-sm font-medium text-gray-600 hover:text-gray-900 transition-colors">FBR POS</a>
                        <a href="#how-it-works" class="text-sm font-medium text-gray-600 hover:text-gray-900 transition-colors">How it Works</a>
                        <a href="#pricing" class="text-sm font-medium text-gray-600 hover:text-gray-900 transition-colors">Pricing</a>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <button @click="showLoginModal = true" class="text-sm font-medium text-gray-600 hover:text-gray-900 transition-colors px-3 py-2 hidden sm:inline-block">Log In</button>
                    <a href="/register" class="btn-solid btn-dark hidden sm:inline-flex">Start Free Trial</a>
                    <button @click="mobileOpen = !mobileOpen" class="md:hidden p-2 text-gray-800" aria-label="Menu">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                </div>
            </div>
            <div x-show="mobileOpen" x-cloak @click.away="mobileOpen = false" class="md:hidden py-4 space-y-1 bg-[var(--paper)] border-t border-gray-200 shadow-lg">
                <a href="/pos" class="block px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded">PRA POS</a>
                <a href="/fbr-pos-landing" class="block px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded">FBR POS</a>
                <a href="#how-it-works" class="block px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded">How it Works</a>
                <a href="#pricing" class="block px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded">Pricing</a>
                <button @click="showLoginModal = true; mobileOpen = false" class="block w-full text-left px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded">Log In</button>
            </div>
        </div>
    </nav>

    <!-- Editorial Hero Section -->
    <header class="relative pt-32 pb-20 lg:pt-40 lg:pb-32 bg-[var(--paper)] overflow-hidden">
        <div class="absolute inset-0 bg-grid-pattern-light opacity-50"></div>
        <div class="max-w-[1000px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10 text-center">
            
            <div class="reveal-on-scroll max-w-3xl mx-auto">
                <p class="text-[#0A4D5C] font-semibold text-sm uppercase tracking-widest mb-6">TaxNest FBR Digital Invoice</p>
                <h1 class="text-4xl sm:text-6xl lg:text-7xl font-serif font-bold text-[#052730] leading-[1.1] mb-8">
                    An accountant’s ledger, <br>built for the PRAL API.
                </h1>
                <p class="text-lg text-gray-600 mb-10 leading-relaxed max-w-2xl mx-auto">
                    Replace manual registers and stressful FBR notices with a meticulous digital workflow. Generate formal invoices, validate HS codes instantly, and submit to FBR with total certainty.
                </p>
                <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="/register" class="w-full sm:w-auto px-8 py-3.5 btn-solid btn-dark text-base shadow-lg">
                        Open the Ledger
                    </a>
                    <a href="#how-it-works" class="text-sm font-medium text-gray-600 hover:text-gray-900 transition underline underline-offset-4">Read the details</a>
                </div>
            </div>

            <!-- HTML Invoice Artifact -->
            <div class="mt-16 sm:mt-24 reveal-on-scroll max-w-4xl mx-auto relative text-left" style="transition-delay: 0.2s">
                <div class="invoice-paper rounded px-6 py-8 sm:p-10 relative">
                    <!-- Gold hairline top border -->
                    <div class="absolute top-0 left-0 right-0 h-1 bg-[#E7BF3B] rounded-t"></div>
                    
                    <div class="flex flex-col sm:flex-row justify-between items-start mb-8 gap-6 border-b border-gray-100 pb-8">
                        <div>
                            <h2 class="text-2xl font-serif font-bold text-[#052730] mb-1">SALES TAX INVOICE</h2>
                            <p class="text-sm text-gray-500">Duplicate (For FBR Records)</p>
                            
                            <div class="mt-4">
                                <p class="text-sm font-bold">Bismillah Kiryana & Traders</p>
                                <p class="text-xs text-gray-600">Main Bazar, Ichra, Lahore</p>
                                <p class="text-xs text-gray-600 mt-1">NTN: 4589214-7</p>
                            </div>
                        </div>
                        
                        <div class="text-right flex flex-col items-end">
                            <div class="bg-gray-50 px-4 py-2 rounded text-xs border border-gray-200 mb-4 text-left">
                                <p class="mb-1"><span class="text-gray-500 w-24 inline-block">Invoice No:</span> <span class="font-bold">INV-2026-00482</span></p>
                                <p class="mb-1"><span class="text-gray-500 w-24 inline-block">Date:</span> <span>{{ date('d-M-Y') }}</span></p>
                                <p><span class="text-gray-500 w-24 inline-block">FBR Invoice Id:</span> <span class="font-mono">3620291786117DI</span></p>
                            </div>
                            <!-- Mock QR Code -->
                            <div class="w-16 h-16 bg-gray-200 border border-gray-300 p-1">
                                <div class="w-full h-full" style="background-image: linear-gradient(45deg, #000 25%, transparent 25%, transparent 75%, #000 75%, #000), linear-gradient(45deg, #000 25%, transparent 25%, transparent 75%, #000 75%, #000); background-size: 8px 8px; background-position: 0 0, 4px 4px;"></div>
                            </div>
                        </div>
                    </div>

                    <table class="w-full invoice-table mb-8 border-collapse">
                        <thead>
                            <tr>
                                <th>Item Description</th>
                                <th>HS Code</th>
                                <th>Qty</th>
                                <th>Rate (PKR)</th>
                                <th>Value</th>
                                <th>ST Rate</th>
                                <th>Tax Amt</th>
                                <th>Total (PKR)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b border-gray-100">
                                <td class="font-medium">Basmati Rice (Super Kernel) 50kg</td>
                                <td class="font-mono text-gray-500">1006.3010</td>
                                <td>15</td>
                                <td>18,500</td>
                                <td>277,500</td>
                                <td>0%</td>
                                <td>0</td>
                                <td class="font-medium">277,500</td>
                            </tr>
                            <tr class="border-b border-gray-100">
                                <td class="font-medium">Woven Cotton Fabric Rolls</td>
                                <td class="font-mono text-gray-500">5208.1100</td>
                                <td>20</td>
                                <td>4,200</td>
                                <td>84,000</td>
                                <td>18%</td>
                                <td>15,120</td>
                                <td class="font-medium">99,120</td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="flex justify-end">
                        <div class="w-full sm:w-1/2 lg:w-1/3 text-sm">
                            <div class="flex justify-between py-1 border-b border-gray-100">
                                <span class="text-gray-600">Total Value Excl. Tax</span>
                                <span>361,500</span>
                            </div>
                            <div class="flex justify-between py-1 border-b border-gray-100">
                                <span class="text-gray-600">Total Sales Tax</span>
                                <span>15,120</span>
                            </div>
                            <div class="flex justify-between py-2 border-b-2 border-gray-800 font-bold text-base mt-1">
                                <span>Total Amount</span>
                                <span>376,620</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Verified Badge floating on artifact -->
                <div class="absolute -right-4 -bottom-4 bg-[#052730] text-white px-4 py-2 rounded shadow-xl text-xs flex items-center gap-2 border border-[#0A4D5C]">
                    <svg class="w-4 h-4 text-[#2EA0B3]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    Accepted by FBR
                </div>
            </div>
            
        </div>
    </header>

    <!-- Large Stat Band -->
    <section class="border-y border-gray-200 bg-white">
        <div class="max-w-[1200px] mx-auto px-4 py-16 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-12 text-center md:text-left divide-y md:divide-y-0 md:divide-x divide-gray-100">
                <div class="reveal-on-scroll">
                    <p class="text-[3.5rem] font-serif font-bold text-[#0A4D5C] leading-none mb-2">100%</p>
                    <p class="text-sm font-semibold text-gray-900 uppercase tracking-widest mb-1">Payloads Validated</p>
                    <p class="text-sm text-gray-500">Every invoice is checked against FBR tax schedules before it is submitted.</p>
                </div>
                <div class="reveal-on-scroll md:pl-12" style="transition-delay: 100ms;">
                    <p class="text-[3.5rem] font-serif font-bold text-[#0A4D5C] leading-none mb-2">4-Step</p>
                    <p class="text-sm font-semibold text-gray-900 uppercase tracking-widest mb-1">Invoice Lifecycle</p>
                    <p class="text-sm text-gray-500">A simplified draft-to-locked flow your staff learns in one afternoon.</p>
                </div>
                <div class="reveal-on-scroll md:pl-12" style="transition-delay: 200ms;">
                    <p class="text-[3.5rem] font-serif font-bold text-[#0A4D5C] leading-none mb-2">24/7</p>
                    <p class="text-sm font-semibold text-gray-900 uppercase tracking-widest mb-1">Token Monitoring</p>
                    <p class="text-sm text-gray-500">Your FBR token's health is watched continuously, so submissions never fail silently.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Editorial How it Works -->
    <section id="how-it-works" class="py-24 bg-[var(--paper)] border-b border-gray-200">
        <div class="max-w-[1000px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-16 reveal-on-scroll">
                <h2 class="text-sm font-bold tracking-widest uppercase text-gray-500 mb-4">The Process</h2>
                <h3 class="text-3xl sm:text-4xl font-serif text-[#052730] mb-6">Designed for Pakistani business realities.</h3>
                <p class="text-lg text-gray-600 font-light max-w-2xl">We abandoned generic layouts to build tools that actually understand load shedding, complex rate lists, and strict compliance schedules.</p>
            </div>

            <div class="space-y-16">
                <!-- Point 01 -->
                <div class="flex flex-col md:flex-row gap-8 reveal-on-scroll items-start">
                    <div class="w-16 flex-shrink-0 pt-1">
                        <span class="font-serif text-4xl text-[#0A4D5C] opacity-30 font-bold">01</span>
                    </div>
                    <div>
                        <h4 class="text-2xl font-serif text-[#052730] mb-3">Pre-Flight Validation</h4>
                        <p class="text-gray-600 leading-relaxed mb-4">Nothing causes more anxiety than a rejected submission. Our engine reviews your invoice against exact tax-schedule rules before hitting the PRAL API. It flags missing NTNs, invalid ST rates, and calculation mismatches while they are still just drafts.</p>
                    </div>
                </div>

                <div class="w-full h-px bg-gray-200"></div>

                <!-- Point 02 -->
                <div class="flex flex-col md:flex-row gap-8 reveal-on-scroll items-start">
                    <div class="w-16 flex-shrink-0 pt-1">
                        <span class="font-serif text-4xl text-[#0A4D5C] opacity-30 font-bold">02</span>
                    </div>
                    <div>
                        <h4 class="text-2xl font-serif text-[#052730] mb-3">HS Code Intelligence</h4>
                        <p class="text-gray-600 leading-relaxed mb-4">Stop guessing tariff classifications. Enter your item, and the system queries a comprehensive HS database to suggest the correct code and its corresponding GST rate. Your rate lists stay clean and compliant automatically.</p>
                    </div>
                </div>

                <div class="w-full h-px bg-gray-200"></div>

                <!-- Point 03 -->
                <div class="flex flex-col md:flex-row gap-8 reveal-on-scroll items-start">
                    <div class="w-16 flex-shrink-0 pt-1">
                        <span class="font-serif text-4xl text-[#0A4D5C] opacity-30 font-bold">03</span>
                    </div>
                    <div>
                        <h4 class="text-2xl font-serif text-[#052730] mb-3">Sandbox to Production</h4>
                        <p class="text-gray-600 leading-relaxed mb-4">Train your accounting staff without risking formal FBR penalties. TaxNest provides a complete Sandbox mode where you can test workflows and verify PDF outputs, then switch to Production with a single click when ready.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Singular Testimonial -->
    <section class="py-24 bg-white border-b border-gray-200">
        <div class="max-w-[800px] mx-auto px-4 text-center reveal-on-scroll">
            <svg class="w-12 h-12 mx-auto text-[#E7BF3B] mb-8" fill="currentColor" viewBox="0 0 24 24"><path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.638-3.996 5.849h4v10h-10z"/></svg>
            <p class="text-2xl md:text-3xl font-serif text-[#052730] leading-relaxed mb-10">
                "We used to maintain massive excel sheets for our textile trading and dread the monthly FBR filings. TaxNest acts like an internal auditor—it simply refuses to let us generate an invoice if the tax calculation is wrong."
            </p>
            <div>
                <p class="font-bold text-gray-900 text-sm uppercase tracking-wide">Tariq Mahmood</p>
                <p class="text-gray-500 text-sm mt-1">Owner, Mahmood Textile Traders • Faisalabad</p>
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section id="pricing" class="py-24 bg-[var(--paper)] border-b border-gray-200">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16 reveal-on-scroll">
                <h2 class="text-sm font-bold tracking-widest uppercase text-gray-500 mb-4">Investment</h2>
                <h3 class="text-3xl sm:text-4xl font-serif text-[#052730] mb-6">Simple, predictable pricing.</h3>
                <p class="text-lg text-gray-600 max-w-2xl mx-auto font-light">Choose the billing cycle that fits your business. All plans include 3-day free trial.</p>
            </div>

            <div x-data="{
                    cycle: 'monthly',
                    discounts: { monthly: 0, quarterly: 1, semi_annual: 3, annual: 6 },
                    months: { monthly: 1, quarterly: 3, semi_annual: 6, annual: 12 },
                    calcPrice(base) {
                        let m = this.months[this.cycle];
                        let d = this.discounts[this.cycle];
                        let total = base * m;
                        return Math.round(total - (total * d / 100));
                    },
                    calcMonthly(base) {
                        return Math.round(this.calcPrice(base) / this.months[this.cycle]);
                    }
                }" class="reveal-on-scroll">
                
                <!-- Billing Toggle -->
                <div class="flex justify-center mb-12">
                    <div class="inline-flex bg-white p-1 rounded-md border border-gray-200 shadow-sm">
                        <button @click="cycle = 'monthly'" :class="cycle === 'monthly' ? 'bg-gray-100 text-gray-900 border-gray-200' : 'border-transparent text-gray-500 hover:text-gray-900'" class="px-5 py-2 rounded text-sm font-semibold transition border">Monthly</button>
                        <button @click="cycle = 'quarterly'" :class="cycle === 'quarterly' ? 'bg-gray-100 text-gray-900 border-gray-200' : 'border-transparent text-gray-500 hover:text-gray-900'" class="px-5 py-2 rounded text-sm font-semibold transition border flex items-center gap-1.5">Quarterly <span class="bg-[#0F6171]/10 text-[#0A4D5C] px-1.5 py-0.5 rounded text-[10px] font-bold tracking-wider leading-none">-1%</span></button>
                        <button @click="cycle = 'semi_annual'" :class="cycle === 'semi_annual' ? 'bg-gray-100 text-gray-900 border-gray-200' : 'border-transparent text-gray-500 hover:text-gray-900'" class="px-5 py-2 rounded text-sm font-semibold transition border flex items-center gap-1.5">Semi-Annual <span class="bg-[#0F6171]/10 text-[#0A4D5C] px-1.5 py-0.5 rounded text-[10px] font-bold tracking-wider leading-none">-3%</span></button>
                        <button @click="cycle = 'annual'" :class="cycle === 'annual' ? 'bg-gray-100 text-gray-900 border-gray-200' : 'border-transparent text-gray-500 hover:text-gray-900'" class="px-5 py-2 rounded text-sm font-semibold transition border flex items-center gap-1.5">Annual <span class="bg-[#0F6171]/10 text-[#0A4D5C] px-1.5 py-0.5 rounded text-[10px] font-bold tracking-wider leading-none">-6%</span></button>
                    </div>
                </div>

                @if(isset($plans) && $plans->count())
                @php
                    // Task 135: Premium is featured on its own wide card below the
                    // grid; if renamed it falls back into the normal grid.
                    $premiumPlan = $plans->firstWhere('name', 'Premium');
                    $gridPlans = $premiumPlan ? $plans->reject(fn ($p) => $p->id === $premiumPlan->id) : $plans;
                @endphp
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 max-w-[1200px] mx-auto">
                    @foreach($gridPlans as $plan)
                    @php
                        $isPopular = $plan->name === 'Business';
                        $hasOffer = $plan->sale_percent > 0;
                    @endphp
                    <div class="card-solid rounded-lg overflow-hidden flex flex-col border border-gray-200 bg-white">
                        <div class="p-8 flex-1 flex flex-col">
                            @if($isPopular)
                                <div class="text-[#0A4D5C] text-xs font-bold uppercase tracking-widest mb-2 flex items-center gap-2">
                                    <span class="w-1.5 h-1.5 rounded-full bg-[#E7BF3B]"></span> Recommended
                                </div>
                            @endif
                            <h4 class="font-serif text-2xl text-[#052730]">{{ $plan->name }}</h4>
                            
                            <div class="mt-6 mb-8 border-b border-gray-100 pb-8">
                                <div x-show="cycle === 'monthly'">
                                    @if($hasOffer)<span class="text-sm text-gray-400 line-through block mb-1">PKR {{ number_format($plan->price, 0) }}</span>@endif
                                    <div class="flex items-baseline gap-1">
                                        <span class="text-4xl font-bold text-gray-900 tracking-tight">PKR {{ number_format($plan->sale_price, 0) }}</span>
                                        <span class="text-gray-500 text-sm font-medium">/mo</span>
                                    </div>
                                </div>
                                <div x-show="cycle !== 'monthly'" style="display:none;">
                                    @if($hasOffer)<span class="text-sm text-gray-400 line-through block mb-1">PKR <span x-text="calcMonthly({{ $plan->price }}).toLocaleString()"></span></span>@endif
                                    <div class="flex items-baseline gap-1">
                                        <span class="text-4xl font-bold text-gray-900 tracking-tight">PKR <span x-text="calcMonthly({{ $plan->sale_price }}).toLocaleString()"></span></span>
                                        <span class="text-gray-500 text-sm font-medium">/mo</span>
                                    </div>
                                    <div class="mt-2 text-xs text-gray-500 font-medium">
                                        Billed PKR <span x-text="calcPrice({{ $plan->sale_price }}).toLocaleString()"></span>
                                    </div>
                                </div>
                            </div>

                            @php
                                $diFeatures = is_array($plan->features) ? $plan->features : (is_string($plan->features) ? json_decode($plan->features, true) : []);
                            @endphp
                            
                            <ul class="space-y-4 mb-8 flex-1">
                                @if(!empty($diFeatures))
                                    @foreach($diFeatures as $feature)
                                    <li class="flex items-start gap-3 text-sm text-gray-600">
                                        <span class="text-gray-400 flex-shrink-0 mt-0.5">—</span>
                                        {{ $feature }}
                                    </li>
                                    @endforeach
                                @else
                                    <li class="flex items-start gap-3 text-sm text-gray-600">
                                        <span class="text-gray-400 flex-shrink-0 mt-0.5">—</span>
                                        {{ $plan->invoice_limit > 0 ? number_format($plan->invoice_limit) . ' invoices/mo' : 'Unlimited invoices' }}
                                    </li>
                                    <li class="flex items-start gap-3 text-sm text-gray-600">
                                        <span class="text-gray-400 flex-shrink-0 mt-0.5">—</span>
                                        FBR PRAL API Submission
                                    </li>
                                @endif
                            </ul>

                            <a href="/register" class="w-full btn-solid {{ $isPopular ? 'btn-dark' : 'btn-secondary border-gray-300' }}">
                                Start Free Trial
                            </a>
                        </div>
                    </div>
                    @endforeach
                </div>

                @if($premiumPlan)
                @php
                    $premiumFeats = $premiumPlan->features;
                    if (is_string($premiumFeats)) $premiumFeats = json_decode($premiumFeats, true);
                    if (is_string($premiumFeats)) $premiumFeats = json_decode($premiumFeats, true);
                    if (!is_array($premiumFeats)) $premiumFeats = [];
                @endphp
                <div class="mt-6 max-w-[1200px] mx-auto rounded-lg overflow-hidden bg-[#052730] relative">
                    <div class="absolute top-0 left-0 right-0 h-1 bg-[#E7BF3B]"></div>
                    <div class="p-8 md:p-10 md:flex md:items-start md:justify-between md:gap-10">
                        <div class="flex-1">
                            <div class="text-[#E7BF3B] text-xs font-bold uppercase tracking-widest mb-2 flex items-center gap-2">
                                <span class="w-1.5 h-1.5 rounded-full bg-[#E7BF3B]"></span> Premium
                            </div>
                            <h4 class="font-serif text-3xl text-white">{{ $premiumPlan->name }}</h4>
                            <p class="mt-2 text-sm text-white/70 font-light max-w-xl">The complete toolkit for firms that bill at scale — white-label branding, API integration, AI-assisted invoicing and automated billing.</p>
                            <ul class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-3">
                                @foreach($premiumFeats as $feature)
                                <li class="flex items-start gap-3 text-sm text-white/80">
                                    <span class="text-[#E7BF3B] flex-shrink-0 mt-0.5">—</span>
                                    {{ $feature }}
                                </li>
                                @endforeach
                            </ul>
                        </div>
                        <div class="mt-8 md:mt-0 md:w-72 flex-shrink-0 md:text-right">
                            <div x-show="cycle === 'monthly'">
                                <div class="flex items-baseline gap-1 md:justify-end">
                                    <span class="text-4xl font-bold text-white tracking-tight">PKR {{ number_format($premiumPlan->sale_price, 0) }}</span>
                                    <span class="text-white/60 text-sm font-medium">/mo</span>
                                </div>
                            </div>
                            <div x-show="cycle !== 'monthly'" style="display:none;">
                                <div class="flex items-baseline gap-1 md:justify-end">
                                    <span class="text-4xl font-bold text-white tracking-tight">PKR <span x-text="calcMonthly({{ $premiumPlan->sale_price }}).toLocaleString()"></span></span>
                                    <span class="text-white/60 text-sm font-medium">/mo</span>
                                </div>
                                <div class="mt-2 text-xs text-white/60 font-medium">
                                    Billed PKR <span x-text="calcPrice({{ $premiumPlan->sale_price }}).toLocaleString()"></span>
                                </div>
                            </div>
                            <p class="text-xs text-white/60 mt-2">Unlimited invoices &middot; users &middot; branches</p>
                            <a href="/register" class="btn-solid btn-gold w-full mt-6 font-bold">Start Free Trial</a>
                        </div>
                    </div>
                </div>
                @endif
                @endif
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section class="py-24 bg-white">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-sm font-bold tracking-widest uppercase text-gray-500 mb-4">Questions</h2>
                <h3 class="text-3xl font-serif text-[#052730]">Frequently asked questions</h3>
            </div>
            <div class="space-y-0 divide-y divide-gray-200 border-y border-gray-200" x-data="{ open: null }">
                <div class="bg-white">
                    <button @click="open = (open === 1 ? null : 1)" class="w-full flex items-center justify-between py-6 text-left hover:bg-gray-50 px-2 transition-colors">
                        <span class="font-bold text-gray-900 text-sm uppercase tracking-wide">Can I test invoices before sending them to FBR?</span>
                        <svg class="w-5 h-5 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 1 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 1" x-collapse class="px-2 pb-6 text-sm text-gray-600 leading-relaxed">Yes. Every invoice is validated against FBR's rules before submission, and a sandbox environment lets you verify the full payload safely before anything touches production.</div>
                </div>
                <div class="bg-white">
                    <button @click="open = (open === 2 ? null : 2)" class="w-full flex items-center justify-between py-6 text-left hover:bg-gray-50 px-2 transition-colors">
                        <span class="font-bold text-gray-900 text-sm uppercase tracking-wide">Which billing cycles are available?</span>
                        <svg class="w-5 h-5 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 2 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 2" x-collapse class="px-2 pb-6 text-sm text-gray-600 leading-relaxed">Digital Invoice is fully flexible: pay Monthly, Quarterly (1% off), Semi-Annual (3% off) or Annual (6% off). You pick the cycle at checkout — no lock-in beyond the period you choose.</div>
                </div>
                <div class="bg-white">
                    <button @click="open = (open === 3 ? null : 3)" class="w-full flex items-center justify-between py-6 text-left hover:bg-gray-50 px-2 transition-colors">
                        <span class="font-bold text-gray-900 text-sm uppercase tracking-wide">I don't know my HS codes. Does the system help?</span>
                        <svg class="w-5 h-5 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 3 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 3" x-collapse class="px-2 pb-6 text-sm text-gray-600 leading-relaxed">Yes. As you type an item, the HS intelligence engine suggests matching codes and checks them against the correct tax schedule — so the right rate is applied before the invoice ever leaves your screen.</div>
                </div>
                <div class="bg-white">
                    <button @click="open = (open === 4 ? null : 4)" class="w-full flex items-center justify-between py-6 text-left hover:bg-gray-50 px-2 transition-colors">
                        <span class="font-bold text-gray-900 text-sm uppercase tracking-wide">What happens if a submission fails?</span>
                        <svg class="w-5 h-5 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 4 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 4" x-collapse class="px-2 pb-6 text-sm text-gray-600 leading-relaxed">Nothing is lost. The invoice stays in your panel with the exact FBR response, you fix the flagged field and resubmit. Duplicate protection makes sure a retry never creates a second invoice.</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer CTA -->
    <section class="bg-[#052730] border-t border-[#0A4D5C]">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 py-24 text-center relative z-10">
            <h2 class="text-4xl font-serif font-bold text-white mb-6">Ready to issue compliant invoices?</h2>
            <p class="text-lg text-white/70 mb-10 max-w-2xl mx-auto font-light">Join the manufacturers and traders trusting TaxNest for their FBR submissions. Create your first formal invoice today.</p>
            <a href="/register" class="btn-solid btn-gold text-[#052730] font-bold text-base px-10 py-4">
                Start Trial Account
            </a>
        </div>
    </section>

    <x-site-footer />

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                    }
                });
            }, { threshold: 0.1 });

            document.querySelectorAll('.reveal-on-scroll').forEach(el => observer.observe(el));
        });
    </script>
    <x-whatsapp-support />
</body>
</html>