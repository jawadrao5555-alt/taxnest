<!DOCTYPE html>
<html lang="en" class="overflow-x-hidden scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="stylesheet" href="{{ asset('css/mobile.css?v=2.5') }}">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <title>TaxNest — Pakistan's FBR/PRA Tax Invoicing & POS</title>
    
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

        /* Receipt zig-zag edge */
        .receipt-edge {
            background: linear-gradient(135deg, transparent 50%, white 50%), linear-gradient(45deg, white 50%, transparent 50%);
            background-size: 10px 10px;
            background-repeat: repeat-x;
            height: 10px;
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
                    <span class="hidden xl:inline-block w-px h-6 bg-gray-200"></span>
                    <a href="#features" class="hidden xl:inline-block px-2 py-2 text-sm font-bold text-gray-600 hover:text-gray-900 transition">Features</a>
                    <a href="#pricing" class="hidden xl:inline-block px-2 py-2 text-sm font-bold text-gray-600 hover:text-gray-900 transition">Pricing</a>
                    <a href="#faq" class="hidden xl:inline-block px-2 py-2 text-sm font-bold text-gray-600 hover:text-gray-900 transition">FAQ</a>
                    <a href="#contact" class="hidden xl:inline-block px-2 py-2 text-sm font-bold text-gray-600 hover:text-gray-900 transition">Contact</a>
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

    <!-- Asymmetric Hero Section -->
    <section class="relative pt-32 sm:pt-44 pb-20 sm:pb-28 bg-pattern-dots border-b-2 border-gray-100 overflow-hidden">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="flex flex-col lg:flex-row items-center gap-16">
                <!-- Text Content -->
                <div class="w-full lg:w-3/5 text-left">
                    <div class="inline-flex items-center gap-2 px-4 py-2 bg-white border-2 border-emerald-200 rounded-full text-sm font-bold text-emerald-800 mb-8 shadow-[0_4px_0_0_#D1FAE5] reveal">
                        <span class="flex h-3 w-3 relative">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500"></span>
                        </span>
                        FBR & PRA Compliant Engine
                    </div>

                    <h1 class="text-[40px] sm:text-[56px] lg:text-[72px] font-bricolage font-extrabold text-gray-900 leading-[1.05] tracking-tight mb-8 reveal" style="transition-delay: 100ms;">
                        Don't let <span class="text-transparent bg-clip-text bg-gradient-to-r from-emerald-500 to-blue-500">tax filing</span> stop your shop.
                    </h1>

                    <p class="text-lg sm:text-xl text-gray-600 font-medium leading-relaxed mb-10 max-w-xl reveal" style="transition-delay: 200ms;">
                        Built for Pakistani retailers, pharmacies, and restaurants. Direct PRAL IMS API integration, automatic FBR invoice numbers, and an offline queue that auto-syncs the moment PTCL decides to work again.
                    </p>

                    <div class="flex flex-col sm:flex-row items-center gap-4 reveal" style="transition-delay: 300ms;">
                        <a href="#products" class="btn-chunky w-full sm:w-auto px-8 py-4 text-lg bg-emerald-500 text-white shadow-[0_6px_0_0_#047857] hover:bg-emerald-400">
                            See The Products
                        </a>
                        <a href="/register" class="btn-chunky w-full sm:w-auto px-8 py-4 text-lg bg-white text-gray-900 border-2 border-gray-200 shadow-[0_6px_0_0_#E5E7EB] hover:border-gray-300">
                            Start 3-Day Trial
                        </a>
                    </div>
                </div>

                <!-- Product Artifact Mockup -->
                <div class="w-full lg:w-2/5 flex justify-center lg:justify-end reveal relative" style="transition-delay: 400ms;">
                    
                    <!-- POS Screen Frame -->
                    <div class="bg-gray-900 rounded-3xl p-4 shadow-[0_20px_0_0_#374151] border-4 border-gray-800 w-full max-w-sm rotate-[-2deg] relative z-10">
                        <div class="bg-white rounded-2xl overflow-hidden shadow-inner flex flex-col h-[400px]">
                            <div class="bg-gray-100 p-3 border-b flex justify-between items-center">
                                <span class="font-bricolage font-bold text-gray-800">Checkout</span>
                                <span class="px-2 py-1 bg-amber-100 text-amber-700 text-[10px] font-bold rounded">Offline Queue: 3</span>
                            </div>
                            <div class="p-4 flex-grow bg-gray-50 flex flex-col gap-2">
                                <div class="bg-white p-3 rounded-xl border shadow-sm flex justify-between">
                                    <div class="flex flex-col">
                                        <span class="font-bold text-sm">Biryani (Mutton)</span>
                                        <span class="text-xs text-gray-500">2 x Rs 600</span>
                                    </div>
                                    <span class="font-bold">Rs 1,200</span>
                                </div>
                                <div class="bg-white p-3 rounded-xl border shadow-sm flex justify-between">
                                    <div class="flex flex-col">
                                        <span class="font-bold text-sm">Raita</span>
                                        <span class="text-xs text-gray-500">2 x Rs 50</span>
                                    </div>
                                    <span class="font-bold">Rs 100</span>
                                </div>
                            </div>
                            <div class="p-4 bg-white border-t border-dashed">
                                <div class="flex justify-between text-sm text-gray-600 mb-1">
                                    <span>Subtotal</span>
                                    <span>Rs 1,300</span>
                                </div>
                                <div class="flex justify-between text-sm text-gray-600 mb-3">
                                    <span>PRA Tax (16% Cash)</span>
                                    <span>Rs 208</span>
                                </div>
                                <div class="flex justify-between font-bold text-xl mb-4 text-gray-900">
                                    <span>Total</span>
                                    <span>Rs 1,508</span>
                                </div>
                                <button class="w-full bg-purple-600 text-white font-bold py-3 rounded-xl">Pay Cash</button>
                            </div>
                        </div>
                    </div>

                    <!-- Thermal Receipt Overlapping -->
                    <div class="absolute -right-4 lg:-right-8 -bottom-10 z-20 rotate-[6deg] drop-shadow-2xl">
                        <div class="w-56 bg-white border-t-2 border-l-2 border-r-2 border-gray-200 pt-6 px-4 pb-2 font-mono text-[10px] text-gray-800 uppercase tracking-tighter">
                            <div class="text-center mb-4 border-b-2 border-dashed border-gray-300 pb-4">
                                <h4 class="font-bold text-sm mb-1 leading-tight">Al-Shifa<br>Pharmacy</h4>
                                <p>Main Blvd, Gujranwala</p>
                                <p>NTN: 1234567-8</p>
                                <p>PRA: 87654321</p>
                            </div>
                            
                            <div class="mb-4">
                                <div class="flex justify-between mb-1">
                                    <span>Panadol Extra 2x</span>
                                    <span>Rs 180</span>
                                </div>
                                <div class="flex justify-between mb-1">
                                    <span>Surbex Z 1x</span>
                                    <span>Rs 850</span>
                                </div>
                                <div class="flex justify-between mb-1">
                                    <span>Disprin 1x</span>
                                    <span>Rs 50</span>
                                </div>
                            </div>
                            
                            <div class="border-t-2 border-dashed border-gray-300 pt-2 mb-2 flex justify-between font-bold">
                                <span>Total</span>
                                <span>Rs 1,080</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Cash</span>
                                <span>Rs 1,100</span>
                            </div>
                            <div class="flex justify-between mb-4">
                                <span>Change</span>
                                <span>Rs 20</span>
                            </div>
                            
                            <div class="flex flex-col items-center border-t-2 border-dashed border-gray-300 pt-4">
                                <div class="w-16 h-16 bg-gray-100 flex items-center justify-center mb-2 border border-gray-300">
                                    <svg class="w-10 h-10 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm14 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
                                </div>
                                <p class="text-[8px] text-center">FBR INV: 1234567891234DI</p>
                                <p class="text-[8px] mt-1">POS-01 | 2026-04-14 18:32</p>
                            </div>
                        </div>
                        <div class="receipt-edge w-full"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Trusted By Strip -->
    <div class="py-6 border-b-2 border-gray-100 bg-white overflow-hidden">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap justify-center items-center gap-6 sm:gap-12">
                <span class="text-sm font-bold text-gray-500 uppercase tracking-widest text-center w-full sm:w-auto mb-2 sm:mb-0">Trusted by businesses across Pakistan</span>
                <div class="flex items-center gap-2 px-4 py-2 bg-emerald-50 rounded-xl border border-emerald-100">
                    <div class="w-3 h-3 rounded-full bg-emerald-500"></div>
                    <span class="font-bold text-emerald-800 text-sm">FBR API v1.12</span>
                </div>
                <div class="flex items-center gap-2 px-4 py-2 bg-purple-50 rounded-xl border border-purple-100">
                    <div class="w-3 h-3 rounded-full bg-purple-500"></div>
                    <span class="font-bold text-purple-800 text-sm">PRA IMS v1.2</span>
                </div>
                <div class="flex items-center gap-2 px-4 py-2 bg-blue-50 rounded-xl border border-blue-100">
                    <div class="w-3 h-3 rounded-full bg-blue-500"></div>
                    <span class="font-bold text-blue-800 text-sm">Real-time Sync</span>
                </div>
            </div>
        </div>
    </div>

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
                    <div class="text-emerald-300 font-bold text-sm tracking-wide uppercase">Platform Uptime</div>
                </div>
                <div class="p-6 reveal" style="transition-delay: 300ms;">
                    <div class="text-4xl sm:text-5xl font-bricolage font-black text-white mb-2">0</div>
                    <div class="text-emerald-300 font-bold text-sm tracking-wide uppercase">Compliance Fines</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Product Isolation Notice -->
    <section class="py-16 bg-gray-50 border-b-2 border-gray-200">
        <div class="max-w-[800px] mx-auto px-4 text-center reveal">
            <span class="inline-block px-4 py-1.5 bg-gray-200 text-gray-800 text-xs font-bold rounded-lg uppercase tracking-wide mb-4 border-2 border-gray-300">Enterprise Architecture</span>
            <h3 class="text-3xl font-bricolage font-extrabold text-gray-900 mb-4">Total Data Isolation. Zero Cross-Mixing.</h3>
            <p class="text-lg text-gray-600 font-medium">TaxNest operates as three completely isolated platforms to protect your shop's privacy. Digital Invoice, PRA POS, and FBR POS each have their own separate registration, separate login, fully partitioned data, and completely independent reporting. Your data is strictly locked to the product you choose.</p>
        </div>
    </section>

    <!-- Products Section - Asymmetric Layout -->
    <section id="products" class="py-24 sm:py-32 overflow-hidden bg-white">
        <div class="max-w-[1000px] mx-auto px-4 sm:px-6 lg:px-8 space-y-24">
            
            <!-- Enterprise Digital Invoice -->
            <div class="flex flex-col md:flex-row items-center gap-12 card-tactile card-emerald p-8 md:p-12 reveal">
                <div class="w-full md:w-1/2">
                    <span class="inline-block px-3 py-1 bg-emerald-100 text-emerald-800 text-xs font-bold rounded-lg tracking-wide uppercase mb-4">Enterprise APIs</span>
                    <h3 class="text-3xl sm:text-4xl font-bricolage font-bold text-gray-900 mb-4">Digital Invoice</h3>
                    <p class="text-emerald-600 font-bold text-sm mb-4">FBR API v1.12 Integration</p>
                    <p class="text-gray-600 font-medium mb-8">For traders and distributors doing high-volume B2B sales. Automate HS code mapping via our internal engine and sync thousands of digital invoices instantly to the FBR backend. Runs completely isolated.</p>
                    
                    <ul class="space-y-3 mb-8">
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-emerald-100 text-emerald-600 rounded flex items-center justify-center mr-3">✓</div> Automated HS Code matching
                        </li>
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-emerald-100 text-emerald-600 rounded flex items-center justify-center mr-3">✓</div> Compliant QR Code generation
                        </li>
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-emerald-100 text-emerald-600 rounded flex items-center justify-center mr-3">✓</div> Risk detection & compliance scoring
                        </li>
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-emerald-100 text-emerald-600 rounded flex items-center justify-center mr-3">✓</div> MIS analytics & multi-branch invoicing
                        </li>
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-emerald-100 text-emerald-600 rounded flex items-center justify-center mr-3">✓</div> Separate account & strict privacy
                        </li>
                    </ul>
                    <a href="/digital-invoice" class="btn-chunky px-6 py-3 bg-emerald-50 text-emerald-700 border-2 border-emerald-200 hover:bg-emerald-100 hover:border-emerald-300">
                        View API Docs & Pricing
                    </a>
                </div>
                <div class="w-full md:w-1/2 flex justify-center">
                    <div class="bg-gray-50 border-2 border-gray-200 p-6 rounded-2xl w-full shadow-inner font-mono text-xs text-emerald-700 overflow-hidden relative">
                        <div class="absolute top-0 left-0 w-full h-8 bg-gray-200 border-b-2 border-gray-300 flex items-center px-4 gap-2">
                            <div class="w-3 h-3 rounded-full bg-red-400"></div>
                            <div class="w-3 h-3 rounded-full bg-amber-400"></div>
                            <div class="w-3 h-3 rounded-full bg-emerald-400"></div>
                        </div>
                        <div class="pt-6">
                            <p>POST /api/v1/fbr/invoices</p>
                            <p class="text-gray-400">{</p>
                            <p class="pl-4">"ntn": "1234567-8",</p>
                            <p class="pl-4">"invoiceNumber": "INV-1004",</p>
                            <p class="pl-4">"items": [ ... ]</p>
                            <p class="text-gray-400">}</p>
                            <br>
                            <p class="text-blue-500">200 OK</p>
                            <p class="text-gray-400">{ "fbrInvoiceNumber": "1234567891234DI" }</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PRA POS -->
            <div class="flex flex-col md:flex-row-reverse items-center gap-12 card-tactile card-purple p-8 md:p-12 reveal">
                <div class="w-full md:w-1/2">
                    <span class="inline-block px-3 py-1 bg-purple-100 text-purple-800 text-xs font-bold rounded-lg tracking-wide uppercase mb-4">Restaurants & Salons</span>
                    <h3 class="text-3xl sm:text-4xl font-bricolage font-bold text-gray-900 mb-4">PRA POS</h3>
                    <p class="text-purple-600 font-bold text-sm mb-4">Punjab Revenue Authority PRAL IMS</p>
                    <p class="text-gray-600 font-medium mb-8">Handles the 8% card / 16% cash tax splits automatically. Push orders directly to kitchen displays. Works on cheap Android tablets and connects to any 80mm thermal printer via Bluetooth or LAN. Fully isolated setup.</p>
                    
                    <ul class="space-y-3 mb-8">
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-purple-100 text-purple-600 rounded flex items-center justify-center mr-3">✓</div> PRAL IMS API v1.2 integration
                        </li>
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-purple-100 text-purple-600 rounded flex items-center justify-center mr-3">✓</div> Split payments (Card/Cash)
                        </li>
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-purple-100 text-purple-600 rounded flex items-center justify-center mr-3">✓</div> Offline billing with auto-sync
                        </li>
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-purple-100 text-purple-600 rounded flex items-center justify-center mr-3">✓</div> 80mm thermal receipts & multi-terminal
                        </li>
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-purple-100 text-purple-600 rounded flex items-center justify-center mr-3">✓</div> Dedicated restaurant environment
                        </li>
                    </ul>
                    <a href="/pos" class="btn-chunky px-6 py-3 bg-purple-50 text-purple-700 border-2 border-purple-200 hover:bg-purple-100 hover:border-purple-300">
                        Explore PRA POS
                    </a>
                </div>
                <div class="w-full md:w-1/2 flex justify-center">
                    <div class="relative w-64 h-80 bg-gray-900 rounded-[2rem] border-8 border-gray-800 shadow-xl overflow-hidden flex flex-col">
                        <div class="bg-purple-600 text-white text-center py-3 font-bold">Table 4</div>
                        <div class="flex-grow p-4 bg-gray-100">
                            <div class="bg-white p-2 rounded mb-2 shadow-sm text-sm font-bold flex justify-between"><span>Chicken Karahi</span><span>Rs 800</span></div>
                            <div class="bg-white p-2 rounded mb-2 shadow-sm text-sm font-bold flex justify-between"><span>Naan x4</span><span>Rs 100</span></div>
                        </div>
                        <div class="bg-white p-4">
                            <button class="w-full py-2 bg-emerald-500 text-white font-bold rounded-lg mb-2">Pay 8% (Card)</button>
                            <button class="w-full py-2 bg-gray-200 text-gray-800 font-bold rounded-lg">Pay 16% (Cash)</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FBR POS -->
            <div class="flex flex-col md:flex-row items-center gap-12 card-tactile card-blue p-8 md:p-12 reveal">
                <div class="w-full md:w-1/2">
                    <span class="inline-block px-3 py-1 bg-blue-100 text-blue-800 text-xs font-bold rounded-lg tracking-wide uppercase mb-4">Tier-1 Retailers</span>
                    <h3 class="text-3xl sm:text-4xl font-bricolage font-bold text-gray-900 mb-4">FBR POS</h3>
                    <p class="text-blue-600 font-bold text-sm mb-4">Multi-Branch Retail Operations</p>
                    <p class="text-gray-600 font-medium mb-8">Full inventory control, barcode scanning, and multi-location syncing. Don't worry about fiscal device integrations — we handle the complexity so your cashiers just scan and bill in their isolated workspace.</p>
                    
                    <ul class="space-y-3 mb-8">
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-blue-100 text-blue-600 rounded flex items-center justify-center mr-3">✓</div> Multi-branch inventory tracking
                        </li>
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-blue-100 text-blue-600 rounded flex items-center justify-center mr-3">✓</div> Barcode scanner & drawer support
                        </li>
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-blue-100 text-blue-600 rounded flex items-center justify-center mr-3">✓</div> Failed-bill edit & retry system
                        </li>
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-blue-100 text-blue-600 rounded flex items-center justify-center mr-3">✓</div> Tax reports & multi-user roles
                        </li>
                        <li class="flex items-center text-sm font-bold text-gray-700">
                            <div class="w-5 h-5 bg-blue-100 text-blue-600 rounded flex items-center justify-center mr-3">✓</div> Fully partitioned retail data
                        </li>
                    </ul>
                    <a href="/fbr-pos-landing" class="btn-chunky px-6 py-3 bg-blue-50 text-blue-700 border-2 border-blue-200 hover:bg-blue-100 hover:border-blue-300">
                        View FBR Capabilities
                    </a>
                </div>
                <div class="w-full md:w-1/2 flex justify-center">
                    <div class="w-full bg-white border-2 border-gray-200 rounded-xl shadow-lg overflow-hidden">
                        <div class="bg-gray-100 p-3 border-b-2 flex justify-between font-bold text-gray-700 text-sm">
                            <span>Scan Item</span>
                            <span>Shift: Morning</span>
                        </div>
                        <div class="p-4 space-y-2">
                            <div class="flex items-center gap-3 border-b pb-2">
                                <div class="w-10 h-10 bg-gray-200 rounded"></div>
                                <div class="flex-grow">
                                    <div class="font-bold">Lays Salted 50g</div>
                                    <div class="text-xs text-gray-500">896400012345</div>
                                </div>
                                <div class="font-bold text-lg">Rs 50</div>
                            </div>
                            <div class="flex items-center gap-3 border-b pb-2">
                                <div class="w-10 h-10 bg-gray-200 rounded"></div>
                                <div class="flex-grow">
                                    <div class="font-bold">Pepsi 1.5L</div>
                                    <div class="text-xs text-gray-500">896400054321</div>
                                </div>
                                <div class="font-bold text-lg">Rs 180</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- How It Works Section -->
    <section class="py-24 bg-pattern-dots border-t-2 border-gray-100">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16 reveal">
                <h2 class="text-3xl sm:text-5xl font-bricolage font-black text-gray-900 mb-4">How It Works</h2>
                <p class="text-lg text-gray-600 font-medium max-w-2xl mx-auto">Three steps to compliant, worry-free billing.</p>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Step 1 -->
                <div class="card-tactile p-8 text-center reveal relative">
                    <div class="absolute -top-6 left-1/2 -translate-x-1/2 w-12 h-12 rounded-full bg-gray-900 text-white font-bricolage font-bold text-xl flex items-center justify-center border-4 border-white shadow-sm">1</div>
                    <h4 class="font-bricolage font-bold text-2xl text-gray-900 mt-4 mb-3">Choose Your Product</h4>
                    <p class="text-gray-600 font-medium">Select Digital Invoice, PRA POS, or FBR POS. Each operates in its own strict, isolated environment to ensure data purity.</p>
                </div>
                <!-- Step 2 -->
                <div class="card-tactile p-8 text-center reveal relative" style="transition-delay: 100ms;">
                    <div class="absolute -top-6 left-1/2 -translate-x-1/2 w-12 h-12 rounded-full bg-gray-900 text-white font-bricolage font-bold text-xl flex items-center justify-center border-4 border-white shadow-sm">2</div>
                    <h4 class="font-bricolage font-bold text-2xl text-gray-900 mt-4 mb-3">Connect Credentials</h4>
                    <p class="text-gray-600 font-medium">Enter your FBR or PRA tokens securely. TaxNest handles the handshake, cryptography, and network tunnels instantly.</p>
                </div>
                <!-- Step 3 -->
                <div class="card-tactile p-8 text-center reveal relative" style="transition-delay: 200ms;">
                    <div class="absolute -top-6 left-1/2 -translate-x-1/2 w-12 h-12 rounded-full bg-gray-900 text-white font-bricolage font-bold text-xl flex items-center justify-center border-4 border-white shadow-sm">3</div>
                    <h4 class="font-bricolage font-bold text-2xl text-gray-900 mt-4 mb-3">Start Billing</h4>
                    <p class="text-gray-600 font-medium">Generate invoices or ring up customers on the POS. Receipts print with official QR codes in under a second.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Hard Truths / Features -->
    <section id="features" class="py-24 bg-[#1F2937] text-white overflow-hidden border-t-8 border-gray-900">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-3xl sm:text-5xl font-bricolage font-black mb-16 text-center">Built Different. Built for Pakistan.</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <div class="bg-gray-800 p-8 rounded-2xl border-2 border-gray-700 reveal shadow-[0_6px_0_0_#374151]">
                    <div class="w-12 h-12 bg-emerald-500/20 text-emerald-400 flex items-center justify-center rounded-xl mb-6 border border-emerald-500/30">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h4 class="text-xl font-bold font-bricolage mb-3 text-gray-100">Offline Auto-Sync Queue</h4>
                    <p class="text-gray-400 font-medium leading-relaxed">Internet drops during a rush? Keep printing receipts. TaxNest securely holds invoices locally and blasts them to the FBR/PRA servers the second your connection returns.</p>
                </div>
                
                <div class="bg-gray-800 p-8 rounded-2xl border-2 border-gray-700 reveal shadow-[0_6px_0_0_#374151]" style="transition-delay: 100ms;">
                    <div class="w-12 h-12 bg-purple-500/20 text-purple-400 flex items-center justify-center rounded-xl mb-6 border border-purple-500/30">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                    <h4 class="text-xl font-bold font-bricolage mb-3 text-gray-100">Runs on Cheap Hardware</h4>
                    <p class="text-gray-400 font-medium leading-relaxed">You don't need $1,000 iPads. Our POS runs blazing fast on affordable Android tablets, standard Windows PCs, and works with basic generic Chinese 80mm thermal printers.</p>
                </div>
                
                <div class="bg-gray-800 p-8 rounded-2xl border-2 border-gray-700 reveal shadow-[0_6px_0_0_#374151]" style="transition-delay: 200ms;">
                    <div class="w-12 h-12 bg-blue-500/20 text-blue-400 flex items-center justify-center rounded-xl mb-6 border border-blue-500/30">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                    </div>
                    <h4 class="text-xl font-bold font-bricolage mb-3 text-gray-100">Urdu-Speaking Support</h4>
                    <p class="text-gray-400 font-medium leading-relaxed">When a tax inspector is standing at your counter, you need answers fast. Call our local support team on WhatsApp and get help in Urdu from engineers who actually understand PRAL.</p>
                </div>

                <div class="bg-gray-800 p-8 rounded-2xl border-2 border-gray-700 reveal shadow-[0_6px_0_0_#374151]">
                    <div class="w-12 h-12 bg-amber-500/20 text-amber-400 flex items-center justify-center rounded-xl mb-6 border border-amber-500/30">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <h4 class="text-xl font-bold font-bricolage mb-3 text-gray-100">Immutable Audit Logs</h4>
                    <p class="text-gray-400 font-medium leading-relaxed">Every transaction, void, and modification is permanently recorded. When the tax department audits you, hand them a clean, unalterable ledger that proves your compliance.</p>
                </div>

                <div class="bg-gray-800 p-8 rounded-2xl border-2 border-gray-700 reveal shadow-[0_6px_0_0_#374151]" style="transition-delay: 100ms;">
                    <div class="w-12 h-12 bg-rose-500/20 text-rose-400 flex items-center justify-center rounded-xl mb-6 border border-rose-500/30">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                    </div>
                    <h4 class="text-xl font-bold font-bricolage mb-3 text-gray-100">Multi-Branch Control</h4>
                    <p class="text-gray-400 font-medium leading-relaxed">Manage inventory, monitor cashiers, and view real-time sales across dozens of locations from a single admin dashboard. No more calling branch managers for EOD reports.</p>
                </div>

                <div class="bg-gray-800 p-8 rounded-2xl border-2 border-gray-700 reveal shadow-[0_6px_0_0_#374151]" style="transition-delay: 200ms;">
                    <div class="w-12 h-12 bg-teal-500/20 text-teal-400 flex items-center justify-center rounded-xl mb-6 border border-teal-500/30">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <h4 class="text-xl font-bold font-bricolage mb-3 text-gray-100">Token Health Monitoring</h4>
                    <p class="text-gray-400 font-medium leading-relaxed">FBR tokens expire. We monitor them 24/7. When a token is about to lapse, we automatically alert you and queue invoices safely until you refresh it. Zero dropped receipts.</p>
                </div>

                <div class="bg-gray-800 p-8 rounded-2xl border-2 border-gray-700 reveal shadow-[0_6px_0_0_#374151]">
                    <div class="w-12 h-12 bg-emerald-500/20 text-emerald-400 flex items-center justify-center rounded-xl mb-6 border border-emerald-500/30">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                    </div>
                    <h4 class="text-xl font-bold font-bricolage mb-3 text-gray-100">HS Intelligence Engine</h4>
                    <p class="text-gray-400 font-medium leading-relaxed">Stop hunting for HS codes in PDF booklets. Our engine learns from every submission and auto-suggests the right HS code, SRO number, and tax rate while you type the product name.</p>
                </div>

                <div class="bg-gray-800 p-8 rounded-2xl border-2 border-gray-700 reveal shadow-[0_6px_0_0_#374151]" style="transition-delay: 100ms;">
                    <div class="w-12 h-12 bg-purple-500/20 text-purple-400 flex items-center justify-center rounded-xl mb-6 border border-purple-500/30">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                    <h4 class="text-xl font-bold font-bricolage mb-3 text-gray-100">Risk & Duplicate Shield</h4>
                    <p class="text-gray-400 font-medium leading-relaxed">Every digital invoice is risk-scored before submission, and a multi-phase idempotency shield guarantees the same bill can never be submitted to FBR or PRA twice — even on double-click.</p>
                </div>

                <div class="bg-gray-800 p-8 rounded-2xl border-2 border-gray-700 reveal shadow-[0_6px_0_0_#374151]" style="transition-delay: 200ms;">
                    <div class="w-12 h-12 bg-blue-500/20 text-blue-400 flex items-center justify-center rounded-xl mb-6 border border-blue-500/30">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                    </div>
                    <h4 class="text-xl font-bold font-bricolage mb-3 text-gray-100">5 Login Methods + PWA</h4>
                    <p class="text-gray-400 font-medium leading-relaxed">Log in with Email, Phone, Username, CNIC, or NTN — whatever you remember. Install TaxNest like an app on any phone or PC, with keyboard shortcuts built for fast cashiers.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Traditional vs TaxNest -->
    <section class="py-24 bg-white border-b-2 border-gray-100">
        <div class="max-w-[1000px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16 reveal">
                <h2 class="text-3xl sm:text-5xl font-bricolage font-black text-gray-900 mb-4">TaxNest vs. The Old Way</h2>
                <p class="text-lg text-gray-600 font-medium max-w-2xl mx-auto">Why switch from legacy desktop software?</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 reveal">
                <div class="card-tactile p-8 bg-gray-50 border-gray-200 shadow-none">
                    <h3 class="font-bricolage font-bold text-2xl text-gray-600 mb-6 pb-4 border-b-2 border-gray-200">Traditional Desktop POS</h3>
                    <ul class="space-y-4">
                        <li class="flex items-start text-gray-600 font-medium">
                            <span class="text-red-500 font-bold mr-3">✗</span> Manual nightly FBR uploads
                        </li>
                        <li class="flex items-start text-gray-600 font-medium">
                            <span class="text-red-500 font-bold mr-3">✗</span> Stuck on one computer in the shop
                        </li>
                        <li class="flex items-start text-gray-600 font-medium">
                            <span class="text-red-500 font-bold mr-3">✗</span> Data lost if hard drive crashes
                        </li>
                        <li class="flex items-start text-gray-600 font-medium">
                            <span class="text-red-500 font-bold mr-3">✗</span> Painful paid upgrades for tax changes
                        </li>
                        <li class="flex items-start text-gray-600 font-medium">
                            <span class="text-red-500 font-bold mr-3">✗</span> Slow, unresponsive support
                        </li>
                        <li class="flex items-start text-gray-600 font-medium">
                            <span class="text-red-500 font-bold mr-3">✗</span> No duplicate protection — double receipts
                        </li>
                        <li class="flex items-start text-gray-600 font-medium">
                            <span class="text-red-500 font-bold mr-3">✗</span> Editable records — audit nightmares
                        </li>
                    </ul>
                </div>
                <div class="card-tactile p-8 border-emerald-200 shadow-[0_6px_0_0_#D1FAE5]">
                    <h3 class="font-bricolage font-bold text-2xl text-emerald-900 mb-6 pb-4 border-b-2 border-emerald-100">TaxNest Cloud POS</h3>
                    <ul class="space-y-4">
                        <li class="flex items-start text-gray-800 font-bold">
                            <span class="text-emerald-500 font-bold mr-3">✓</span> Real-time automated syncing
                        </li>
                        <li class="flex items-start text-gray-800 font-bold">
                            <span class="text-emerald-500 font-bold mr-3">✓</span> Access from anywhere, any device
                        </li>
                        <li class="flex items-start text-gray-800 font-bold">
                            <span class="text-emerald-500 font-bold mr-3">✓</span> Automatic cloud data protection
                        </li>
                        <li class="flex items-start text-gray-800 font-bold">
                            <span class="text-emerald-500 font-bold mr-3">✓</span> Free automatic tax-rate updates
                        </li>
                        <li class="flex items-start text-gray-800 font-bold">
                            <span class="text-emerald-500 font-bold mr-3">✓</span> Dedicated WhatsApp engineer line
                        </li>
                        <li class="flex items-start text-gray-800 font-bold">
                            <span class="text-emerald-500 font-bold mr-3">✓</span> Multi-phase duplicate submission shield
                        </li>
                        <li class="flex items-start text-gray-800 font-bold">
                            <span class="text-emerald-500 font-bold mr-3">✓</span> SHA-256 immutable audit logs
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Realistic Mini Case Studies -->
    <section class="py-24 bg-pattern-dots border-b-2 border-gray-100">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16 reveal">
                <h2 class="text-3xl sm:text-4xl font-bricolage font-black text-gray-900">Operations Running on TaxNest</h2>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Case Study 1 -->
                <div class="card-tactile p-8 reveal border-t-8 border-t-emerald-500">
                    <div class="flex justify-between items-start mb-6">
                        <h4 class="font-bricolage font-bold text-xl text-gray-900">Al-Jalal Pharmacy</h4>
                        <span class="px-2 py-1 bg-emerald-100 text-emerald-800 text-[10px] font-bold rounded uppercase">FBR POS</span>
                    </div>
                    <p class="text-gray-700 font-medium mb-6 leading-relaxed">"We run 3 branches in Gujranwala pushing over 1,200 receipts a day. Since switching, our cashiers haven't had a single slow-down during shift changes, and all FBR syncs happen silently in the background."</p>
                    <div class="border-t border-gray-100 pt-4 mt-auto">
                        <div class="text-sm font-bold text-gray-900">Hassan R.</div>
                        <div class="text-xs text-gray-500">Operations Manager</div>
                    </div>
                </div>

                <!-- Case Study 2 -->
                <div class="card-tactile p-8 reveal border-t-8 border-t-purple-500" style="transition-delay: 100ms;">
                    <div class="flex justify-between items-start mb-6">
                        <h4 class="font-bricolage font-bold text-xl text-gray-900">Spice Route Cafe</h4>
                        <span class="px-2 py-1 bg-purple-100 text-purple-800 text-[10px] font-bold rounded uppercase">PRA POS</span>
                    </div>
                    <p class="text-gray-700 font-medium mb-6 leading-relaxed">"Managing the 8% PRA card rate versus the 16% cash rate used to ruin our daily closing reports. TaxNest handles the split instantly at checkout. We print 400+ receipts a night without a hitch."</p>
                    <div class="border-t border-gray-100 pt-4 mt-auto">
                        <div class="text-sm font-bold text-gray-900">Saad M.</div>
                        <div class="text-xs text-gray-500">Owner, Lahore</div>
                    </div>
                </div>

                <!-- Case Study 3 -->
                <div class="card-tactile p-8 reveal border-t-8 border-t-blue-500" style="transition-delay: 200ms;">
                    <div class="flex justify-between items-start mb-6">
                        <h4 class="font-bricolage font-bold text-xl text-gray-900">Nexus Distributors</h4>
                        <span class="px-2 py-1 bg-blue-100 text-blue-800 text-[10px] font-bold rounded uppercase">API Invoicing</span>
                    </div>
                    <p class="text-gray-700 font-medium mb-6 leading-relaxed">"We generate large B2B invoices containing 50+ line items. Doing this manually on the FBR portal was a nightmare. Now our ERP hits the TaxNest API, and invoices are generated with proper QR codes in seconds."</p>
                    <div class="border-t border-gray-100 pt-4 mt-auto">
                        <div class="text-sm font-bold text-gray-900">Tariq J.</div>
                        <div class="text-xs text-gray-500">Finance Director, Karachi</div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- Pricing Overview -->
    <section id="pricing" class="py-24 bg-white border-b-2 border-gray-100">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16 reveal">
                <h2 class="text-3xl sm:text-5xl font-bricolage font-black text-gray-900 mb-4">Simple, Isolated Pricing</h2>
                <p class="text-lg text-gray-600 font-medium max-w-2xl mx-auto">Choose your dedicated product. 3-day free trial on all plans.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Pricing Card 1 -->
                <div class="card-tactile border-2 border-emerald-200 p-8 flex flex-col reveal text-center">
                    <span class="inline-block px-3 py-1 bg-emerald-100 text-emerald-800 text-xs font-bold rounded-lg uppercase mx-auto mb-4">Enterprise</span>
                    <h3 class="text-2xl font-bricolage font-bold text-gray-900 mb-2">Digital Invoice</h3>
                    <p class="text-gray-600 font-medium text-sm mb-6 pb-6 border-b border-gray-100">High-volume B2B API integrations</p>
                    <a href="/digital-invoice" class="btn-chunky w-full py-3 bg-emerald-50 text-emerald-700 border-2 border-emerald-200 mt-auto hover:bg-emerald-100">See Pricing</a>
                </div>

                <!-- Pricing Card 2 -->
                <div class="card-tactile border-2 border-purple-200 p-8 flex flex-col reveal text-center" style="transition-delay: 100ms;">
                    <span class="inline-block px-3 py-1 bg-purple-100 text-purple-800 text-xs font-bold rounded-lg uppercase mx-auto mb-4">Restaurants</span>
                    <h3 class="text-2xl font-bricolage font-bold text-gray-900 mb-2">PRA POS</h3>
                    <p class="text-gray-600 font-medium text-sm mb-6 pb-6 border-b border-gray-100">Punjab Revenue Authority certified</p>
                    <a href="/pos" class="btn-chunky w-full py-3 bg-purple-50 text-purple-700 border-2 border-purple-200 mt-auto hover:bg-purple-100">See Pricing</a>
                </div>

                <!-- Pricing Card 3 -->
                <div class="card-tactile border-2 border-blue-200 p-8 flex flex-col reveal text-center" style="transition-delay: 200ms;">
                    <span class="inline-block px-3 py-1 bg-blue-100 text-blue-800 text-xs font-bold rounded-lg uppercase mx-auto mb-4">Retailers</span>
                    <h3 class="text-2xl font-bricolage font-bold text-gray-900 mb-2">FBR POS</h3>
                    <p class="text-gray-600 font-medium text-sm mb-6 pb-6 border-b border-gray-100">Multi-branch inventory & scanning</p>
                    <a href="/fbr-pos-landing" class="btn-chunky w-full py-3 bg-blue-50 text-blue-700 border-2 border-blue-200 mt-auto hover:bg-blue-100">See Pricing</a>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ Section (Alpine) -->
    <section id="faq" class="py-24 bg-pattern-dots border-b-2 border-gray-100">
        <div class="max-w-[800px] mx-auto px-4 sm:px-6 lg:px-8" x-data="{ active: null }">
            <div class="text-center mb-16 reveal">
                <h2 class="text-3xl sm:text-5xl font-bricolage font-black text-gray-900 mb-4">Questions Shopkeepers Ask</h2>
            </div>

            <div class="space-y-4 reveal">
                <div class="card-tactile border-2 border-gray-200 bg-white overflow-hidden shadow-sm hover:shadow-none">
                    <button @click="active = active === 1 ? null : 1" class="w-full px-6 py-5 text-left flex justify-between items-center font-bricolage font-bold text-lg text-gray-900 focus:outline-none">
                        Do I need internet all the time?
                        <span x-text="active === 1 ? '-' : '+'" class="text-gray-400 font-mono text-xl"></span>
                    </button>
                    <div x-show="active === 1" x-collapse x-cloak class="px-6 pb-6 text-gray-600 font-medium">
                        No. Our offline auto-sync queue ensures your cashiers can keep billing even when the internet drops. The system holds invoices securely and blasts them to the tax portal the moment you're back online.
                    </div>
                </div>

                <div class="card-tactile border-2 border-gray-200 bg-white overflow-hidden shadow-sm hover:shadow-none">
                    <button @click="active = active === 2 ? null : 2" class="w-full px-6 py-5 text-left flex justify-between items-center font-bricolage font-bold text-lg text-gray-900 focus:outline-none">
                        What happens if the FBR/PRA portal is down?
                        <span x-text="active === 2 ? '-' : '+'" class="text-gray-400 font-mono text-xl"></span>
                    </button>
                    <div x-show="active === 2" x-collapse x-cloak class="px-6 pb-6 text-gray-600 font-medium">
                        You won't even notice. TaxNest monitors portal health. If the government servers fail, we queue your receipts and print them with an offline status. Once the servers recover, we sync everything transparently.
                    </div>
                </div>

                <div class="card-tactile border-2 border-gray-200 bg-white overflow-hidden shadow-sm hover:shadow-none">
                    <button @click="active = active === 3 ? null : 3" class="w-full px-6 py-5 text-left flex justify-between items-center font-bricolage font-bold text-lg text-gray-900 focus:outline-none">
                        Can I use my existing thermal printer?
                        <span x-text="active === 3 ? '-' : '+'" class="text-gray-400 font-mono text-xl"></span>
                    </button>
                    <div x-show="active === 3" x-collapse x-cloak class="px-6 pb-6 text-gray-600 font-medium">
                        Yes! TaxNest runs on cheap, standard hardware. If it's a standard 80mm generic thermal printer connecting via USB, Bluetooth, or LAN, our cloud POS can print compliant receipts to it instantly.
                    </div>
                </div>

                <div class="card-tactile border-2 border-gray-200 bg-white overflow-hidden shadow-sm hover:shadow-none">
                    <button @click="active = active === 4 ? null : 4" class="w-full px-6 py-5 text-left flex justify-between items-center font-bricolage font-bold text-lg text-gray-900 focus:outline-none">
                        Is my data mixed with other companies?
                        <span x-text="active === 4 ? '-' : '+'" class="text-gray-400 font-mono text-xl"></span>
                    </button>
                    <div x-show="active === 4" x-collapse x-cloak class="px-6 pb-6 text-gray-600 font-medium">
                        Absolutely not. Product isolation is sacred here. Each product (Digital Invoice, PRA, FBR) is entirely separate with its own registration, its own login, and fully partitioned data. Zero cross-mixing, ensuring your operational privacy.
                    </div>
                </div>

                <!-- FAQ 5 -->
                <div class="card-tactile overflow-hidden reveal">
                    <button @click="active = active === 5 ? null : 5" class="w-full px-6 py-5 text-left flex justify-between items-center font-bricolage font-bold text-lg text-gray-900 focus:outline-none">
                        How does FBR / PRA compliance actually work?
                        <span x-text="active === 5 ? '-' : '+'" class="text-gray-400 font-mono text-xl"></span>
                    </button>
                    <div x-show="active === 5" x-collapse x-cloak class="px-6 pb-6 text-gray-600 font-medium">
                        Digital Invoice submits through the FBR PRAL API v1.12 in real time — every invoice is validated, gets an official FBR invoice number, and prints with a QR code. PRA POS fiscalizes each sale via the PRAL IMS API v1.2 and receives a PRA fiscal invoice number. FBR POS submits directly to the FBR API with an automatic retry system for rejected bills. You never visit a government portal manually.
                    </div>
                </div>

                <!-- FAQ 6 -->
                <div class="card-tactile overflow-hidden reveal">
                    <button @click="active = active === 6 ? null : 6" class="w-full px-6 py-5 text-left flex justify-between items-center font-bricolage font-bold text-lg text-gray-900 focus:outline-none">
                        Is there a free trial? How do I log in?
                        <span x-text="active === 6 ? '-' : '+'" class="text-gray-400 font-mono text-xl"></span>
                    </button>
                    <div x-show="active === 6" x-collapse x-cloak class="px-6 pb-6 text-gray-600 font-medium">
                        Yes — every product comes with a 3-day free trial, no card required. Register on the product you need, and log in with whatever you remember: Email, Phone, Username, CNIC, or NTN. Each product has its own separate login page.
                    </div>
                </div>

                <!-- FAQ 7 -->
                <div class="card-tactile overflow-hidden reveal">
                    <button @click="active = active === 7 ? null : 7" class="w-full px-6 py-5 text-left flex justify-between items-center font-bricolage font-bold text-lg text-gray-900 focus:outline-none">
                        What security protects my records?
                        <span x-text="active === 7 ? '-' : '+'" class="text-gray-400 font-mono text-xl"></span>
                    </button>
                    <div x-show="active === 7" x-collapse x-cloak class="px-6 pb-6 text-gray-600 font-medium">
                        Everything runs over HTTPS with company-level isolation enforced on every single query. Critical events are written to SHA-256 signed immutable audit logs that cannot be edited — ever. A multi-phase idempotency shield blocks duplicate submissions, and role-based access keeps cashiers, managers, and owners in their own lanes.
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact" class="py-24 bg-white">
        <div class="max-w-[800px] mx-auto px-4 text-center reveal">
            <h2 class="text-3xl sm:text-5xl font-bricolage font-black text-gray-900 mb-6">We're in Lahore. Let's talk.</h2>
            <p class="text-lg text-gray-600 font-medium mb-8">Need custom API integrations or high-volume enterprise pricing? Our engineering team is ready.</p>
            <div class="flex flex-col sm:flex-row justify-center gap-6">
                <div class="flex items-center justify-center gap-3 px-6 py-4 bg-gray-50 border-2 border-gray-200 rounded-2xl">
                    <svg class="w-6 h-6 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <span class="font-bold text-gray-900">support@taxnest.com</span>
                </div>
                <div class="flex items-center justify-center gap-3 px-6 py-4 bg-gray-50 border-2 border-gray-200 rounded-2xl">
                    <svg class="w-6 h-6 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.243-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <span class="font-bold text-gray-900">Lahore, Pakistan</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Final CTA -->
    <section class="py-24 relative overflow-hidden">
        <div class="absolute inset-0 bg-emerald-500"></div>
        <div class="absolute inset-0 bg-pattern-dots mix-blend-multiply opacity-20"></div>
        <div class="max-w-[800px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10 text-center text-white reveal">
            <h2 class="text-4xl sm:text-6xl font-bricolage font-black mb-6">Bring your shop into compliance.</h2>
            <p class="text-xl font-medium text-emerald-100 mb-10 max-w-2xl mx-auto">Get your credentials, log in, and start printing valid FBR/PRA thermal receipts in under 5 minutes.</p>
            <a href="/register" class="btn-chunky px-10 py-5 text-xl bg-white text-emerald-900 shadow-[0_8px_0_0_#064E3B] hover:shadow-[0_12px_0_0_#064E3B] hover:-translate-y-2">
                Start 3-Day Free Trial
            </a>
            <p class="mt-8 text-sm font-bold text-emerald-200">No credit card required. Works on any device.</p>
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
                    <p class="text-gray-500 font-medium text-sm max-w-xs">Pakistan's most dependable tax compliance platform for FBR and PRA digital invoicing & POS systems.</p>
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