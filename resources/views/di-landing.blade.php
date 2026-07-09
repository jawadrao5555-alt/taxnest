<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#052730">
    <title>Digital Invoice — Calm, Guided FBR Invoicing by TaxNest</title>
    
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

        .glass-dark {
            background-color: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .nav-scrolled {
            background-color: rgba(5, 39, 48, 0.95);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid rgba(255,255,255,0.05);
            box-shadow: 0 1px 3px 0 rgba(0,0,0,0.1);
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
                        <img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" alt="TaxNest" class="h-8 w-auto">
                    </a>
                    <div class="hidden md:flex items-center space-x-8">
                        <a href="/pos" class="text-sm font-medium text-white/80 hover:text-white transition-colors">PRA POS</a>
                        <a href="/fbr-pos-landing" class="text-sm font-medium text-white/80 hover:text-white transition-colors">FBR POS</a>
                        <a href="#features" class="text-sm font-medium text-white/80 hover:text-white transition-colors">Features</a>
                        <a href="#pricing" class="text-sm font-medium text-white/80 hover:text-white transition-colors">Pricing</a>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <button @click="showLoginModal = true" class="text-sm font-medium text-white/80 hover:text-white transition-colors px-3 py-2 hidden sm:inline-block">Log In</button>
                    <a href="/register" class="btn-solid btn-gold hidden sm:inline-flex">Start Free Trial</a>
                    <button @click="mobileOpen = !mobileOpen" class="md:hidden p-2 text-white" aria-label="Menu">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                </div>
            </div>
            <div x-show="mobileOpen" x-cloak @click.away="mobileOpen = false" class="md:hidden border-t border-white/10 py-4 space-y-1 bg-[#052730]">
                <a href="/pos" class="block px-4 py-2.5 text-sm font-medium text-white hover:bg-white/5 rounded">PRA POS</a>
                <a href="/fbr-pos-landing" class="block px-4 py-2.5 text-sm font-medium text-white hover:bg-white/5 rounded">FBR POS</a>
                <a href="#features" class="block px-4 py-2.5 text-sm font-medium text-white hover:bg-white/5 rounded">Features</a>
                <a href="#pricing" class="block px-4 py-2.5 text-sm font-medium text-white hover:bg-white/5 rounded">Pricing</a>
                <button @click="showLoginModal = true; mobileOpen = false" class="block w-full text-left px-4 py-2.5 text-sm font-medium text-white hover:bg-white/5 rounded">Log In</button>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <header class="relative pt-32 pb-24 lg:pt-48 lg:pb-32 overflow-hidden bg-[#052730] border-b border-[#0A4D5C]">
        <div class="absolute inset-0 bg-grid-pattern-dark opacity-50"></div>
        <div class="absolute inset-0 bg-gradient-to-b from-transparent to-[#052730]/90 pointer-events-none"></div>
        <div class="relative max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 z-10">
            <div class="grid lg:grid-cols-2 gap-16 items-center">
                <div class="max-w-2xl reveal-on-scroll">
                    <div class="inline-flex items-center gap-2 px-3 py-1 glass-dark rounded-full text-[#2EA0B3] text-xs font-semibold uppercase tracking-widest mb-8 border border-[#2EA0B3]/30">
                        <span class="w-1.5 h-1.5 rounded-full bg-[#E7BF3B] animate-pulse"></span>
                        PRAL API v1.12 Integration Active
                    </div>
                    <h1 class="text-4xl sm:text-5xl lg:text-6xl font-serif font-bold text-white leading-tight tracking-tight mb-6">
                        A perfectly organized desk for <span class="italic text-[#2EA0B3]">FBR invoicing.</span>
                    </h1>
                    <p class="text-lg text-white/70 mb-10 leading-relaxed font-light">
                        TaxNest Digital Invoice turns stressful government reporting into a calm, guided workflow. Catch errors before submission, suggest HS codes in real-time, and ensure every invoice is accepted.
                    </p>
                    <div class="flex flex-col sm:flex-row items-center gap-4">
                        <a href="/register" class="w-full sm:w-auto px-8 py-3.5 btn-solid btn-gold text-base">
                            Start 3-Day Trial
                        </a>
                        <p class="text-xs text-white/50 tracking-wide uppercase">No credit card required</p>
                    </div>
                </div>
                <div class="relative lg:-mr-12 xl:-mr-24 reveal-on-scroll" style="transition-delay: 0.2s">
                    <div class="glass-dark rounded-xl border border-white/20 p-2 shadow-2xl relative overflow-hidden">
                        <div class="bg-[#052730]/50 border-b border-white/10 px-4 py-3 flex items-center gap-2">
                            <div class="flex gap-1.5">
                                <div class="w-3 h-3 rounded-full bg-white/20"></div>
                                <div class="w-3 h-3 rounded-full bg-white/20"></div>
                                <div class="w-3 h-3 rounded-full bg-white/20"></div>
                            </div>
                            <div class="ml-4 px-3 py-1 bg-black/20 border border-white/5 rounded text-[10px] text-white/50 flex-1 max-w-[200px] truncate tracking-wider">
                                taxnest.com/digital-invoice
                            </div>
                        </div>
                        <img src="{{ asset('images/screenshots/di-dash.jpg') }}" alt="Digital Invoice Dashboard" class="w-full h-auto block rounded-b-lg border-t border-white/5">
                    </div>
                    <!-- Trust Strip pill -->
                    <div class="absolute -bottom-6 -left-6 glass-dark px-5 py-3 rounded-lg shadow-xl text-xs hidden sm:block border border-white/20">
                        <div class="flex items-center gap-2 text-[#2EA0B3] mb-1 font-bold">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            <span>Validation Passed</span>
                        </div>
                        <span class="text-white/60">Payload ready for PRAL API</span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Features Ledger Section -->
    <section id="features" class="py-24 bg-[var(--paper)] border-b border-gray-200 relative">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="mb-16 max-w-3xl reveal-on-scroll">
                <h2 class="text-sm font-bold tracking-widest uppercase text-gray-500 mb-4">The Ledger</h2>
                <h3 class="text-3xl sm:text-4xl font-serif text-[#052730] mb-6">Precision-engineered for compliance.</h3>
                <p class="text-lg text-gray-600 leading-relaxed font-light">Every feature is built around a single goal: ensuring your invoices are structurally perfect before FBR ever sees them.</p>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-2 gap-8">
                <!-- Feature 1 -->
                <div class="card-solid p-8 rounded-lg reveal-on-scroll flex items-start gap-6">
                    <div class="w-12 h-12 bg-[#0F6171]/10 text-[#0A4D5C] flex items-center justify-center rounded flex-shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <div>
                        <h4 class="font-serif text-xl text-[#052730] mb-2">Pre-Submission Validation</h4>
                        <p class="text-gray-600 text-sm leading-relaxed">Tax-schedule validation (ScheduleEngine) and payload checking ensure errors are caught and fixed before FBR sees them, preventing rejected submissions.</p>
                    </div>
                </div>

                <!-- Feature 2 -->
                <div class="card-solid p-8 rounded-lg reveal-on-scroll flex items-start gap-6">
                    <div class="w-12 h-12 bg-[#0F6171]/10 text-[#0A4D5C] flex items-center justify-center rounded flex-shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    </div>
                    <div>
                        <h4 class="font-serif text-xl text-[#052730] mb-2">HS Code Intelligence</h4>
                        <p class="text-gray-600 text-sm leading-relaxed">Receive real-time HS code suggestions while creating your invoice. Never guess a tax rate again; the system guides you to the right classification.</p>
                    </div>
                </div>

                <!-- Feature 3 -->
                <div class="card-solid p-8 rounded-lg reveal-on-scroll flex items-start gap-6">
                    <div class="w-12 h-12 bg-[#0F6171]/10 text-[#0A4D5C] flex items-center justify-center rounded flex-shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path></svg>
                    </div>
                    <div>
                        <h4 class="font-serif text-xl text-[#052730] mb-2">Sandbox & Production</h4>
                        <p class="text-gray-600 text-sm leading-relaxed">Train your staff safely in Sandbox mode without affecting real FBR data. Switch to Production instantly when you're ready to go live.</p>
                    </div>
                </div>

                <!-- Feature 4 -->
                <div class="card-solid p-8 rounded-lg reveal-on-scroll flex items-start gap-6">
                    <div class="w-12 h-12 bg-[#0F6171]/10 text-[#0A4D5C] flex items-center justify-center rounded flex-shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    </div>
                    <div>
                        <h4 class="font-serif text-xl text-[#052730] mb-2">Clean B&W PDFs</h4>
                        <p class="text-gray-600 text-sm leading-relaxed">Generate perfectly formatted, clean black-and-white invoice PDFs featuring dual invoice numbering (internal + FBR) and all required per-item FBR fields.</p>
                    </div>
                </div>

                <!-- Feature 5 -->
                <div class="card-solid p-8 rounded-lg reveal-on-scroll flex items-start gap-6">
                    <div class="w-12 h-12 bg-[#0F6171]/10 text-[#0A4D5C] flex items-center justify-center rounded flex-shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <div>
                        <h4 class="font-serif text-xl text-[#052730] mb-2">Ledger & WHT Manager</h4>
                        <p class="text-gray-600 text-sm leading-relaxed">Integrated customer ledger with automatic debits, alongside a dedicated WHT manager to keep track of withholdings seamlessly.</p>
                    </div>
                </div>

                <!-- Feature 6 -->
                <div class="card-solid p-8 rounded-lg reveal-on-scroll flex items-start gap-6">
                    <div class="w-12 h-12 bg-[#0F6171]/10 text-[#0A4D5C] flex items-center justify-center rounded flex-shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    </div>
                    <div>
                        <h4 class="font-serif text-xl text-[#052730] mb-2">Immutable Audit Logs</h4>
                        <p class="text-gray-600 text-sm leading-relaxed">Every action leaves a permanent trace. MIS reports and immutable audit logs ensure complete transparency across multi-branch setups.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Workflow / UI Showcase -->
    <section class="py-24 bg-white border-b border-gray-200">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col lg:flex-row gap-16 items-center">
                <div class="w-full lg:w-1/2 order-2 lg:order-1 reveal-on-scroll">
                    <div class="bg-gray-50 rounded-lg p-2 border border-gray-200">
                        <img src="{{ asset('images/screenshots/di-create.jpg') }}" alt="Invoice Creation Form" class="w-full h-auto border border-gray-300 rounded shadow-sm">
                    </div>
                </div>
                <div class="w-full lg:w-1/2 order-1 lg:order-2 reveal-on-scroll">
                    <h3 class="text-3xl font-serif text-[#052730] mb-6">A clear interface for complex data.</h3>
                    <p class="text-lg text-gray-600 mb-8 font-light leading-relaxed">No more staring at dense portal screens. We've organized the required FBR data points into a logical, airy layout that feels like a modern spreadsheet.</p>
                    
                    <ul class="space-y-4">
                        <li class="flex items-start gap-3">
                            <span class="text-[#0A4D5C] mt-1 flex-shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            </span>
                            <span class="text-gray-700 text-sm leading-relaxed">Per-item FBR fields clearly exposed and validated.</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="text-[#0A4D5C] mt-1 flex-shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            </span>
                            <span class="text-gray-700 text-sm leading-relaxed">Real-time feedback before you hit submit.</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="text-[#0A4D5C] mt-1 flex-shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            </span>
                            <span class="text-gray-700 text-sm leading-relaxed">Installable PWA for fast desktop access.</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="flex flex-col lg:flex-row gap-16 items-center mt-32">
                <div class="w-full lg:w-1/2 reveal-on-scroll">
                    <h3 class="text-3xl font-serif text-[#052730] mb-6">Track every submission meticulously.</h3>
                    <p class="text-lg text-gray-600 mb-8 font-light leading-relaxed">Maintain a perfect historical record. The invoice list provides immediate status visibility and quick access to clean PDFs.</p>
                    <a href="/register" class="text-[#0A4D5C] font-semibold hover:text-[#063B47] transition flex items-center gap-1 uppercase tracking-wide text-sm">
                        Try the workflow yourself
                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                    </a>
                </div>
                <div class="w-full lg:w-1/2 reveal-on-scroll">
                    <div class="bg-gray-50 rounded-lg p-2 border border-gray-200">
                        <img src="{{ asset('images/screenshots/di-list.jpg') }}" alt="Invoice List" class="w-full h-auto border border-gray-300 rounded shadow-sm">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section id="pricing" class="py-24 bg-[var(--paper)]">
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
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 max-w-[1200px] mx-auto">
                    @foreach($plans as $plan)
                    @php
                        $isPopular = $plan->name === 'Business';
                        $hasOffer = $plan->sale_percent > 0;
                    @endphp
                    <div class="card-solid rounded-lg overflow-hidden flex flex-col {{ $isPopular ? 'border-[#0A4D5C] ring-1 ring-[#0A4D5C]' : '' }}">
                        @if($isPopular)
                        <div class="bg-[#0A4D5C] text-white text-xs font-bold text-center py-2 uppercase tracking-widest">
                            Recommended
                        </div>
                        @endif
                        <div class="p-8 flex-1 flex flex-col">
                            <h4 class="font-serif text-2xl text-[#052730]">{{ $plan->name }}</h4>
                            
                            <div class="mt-6 mb-8">
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
                                    <div class="mt-2 text-xs text-[#0A4D5C] bg-[#0F6171]/10 inline-block px-2 py-1 rounded border border-[#0A4D5C]/10 font-medium">
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
                                        <span class="text-[#0A4D5C] flex-shrink-0 mt-0.5">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                        </span>
                                        {{ $feature }}
                                    </li>
                                    @endforeach
                                @else
                                    <li class="flex items-start gap-3 text-sm text-gray-600">
                                        <span class="text-[#0A4D5C] flex-shrink-0 mt-0.5">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                        </span>
                                        {{ $plan->invoice_limit > 0 ? number_format($plan->invoice_limit) . ' invoices/mo' : 'Unlimited invoices' }}
                                    </li>
                                    <li class="flex items-start gap-3 text-sm text-gray-600">
                                        <span class="text-[#0A4D5C] flex-shrink-0 mt-0.5">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                        </span>
                                        FBR PRAL API Submission
                                    </li>
                                @endif
                            </ul>

                            <a href="/register" class="w-full btn-solid {{ $isPopular ? 'btn-primary' : 'btn-secondary' }}">
                                Start Free Trial
                            </a>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section class="py-24 bg-white border-t border-gray-200">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-sm font-bold tracking-widest uppercase text-gray-500 mb-4">Questions</h2>
                <h3 class="text-3xl font-serif text-[#052730]">Frequently asked questions</h3>
            </div>
            <div class="space-y-4" x-data="{ open: null }">
                <div class="border border-gray-200 rounded-lg bg-white">
                    <button @click="open = (open === 1 ? null : 1)" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="font-bold text-gray-900 text-sm uppercase tracking-wide">Can I test invoices before sending them to FBR?</span>
                        <svg class="w-5 h-5 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 1 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 1" x-collapse class="px-6 pb-6 text-sm text-gray-600 leading-relaxed font-light">Yes. Every invoice is validated against FBR's rules before submission, and a sandbox environment lets you verify the full payload safely before anything touches production.</div>
                </div>
                <div class="border border-gray-200 rounded-lg bg-white">
                    <button @click="open = (open === 2 ? null : 2)" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="font-bold text-gray-900 text-sm uppercase tracking-wide">Which billing cycles are available?</span>
                        <svg class="w-5 h-5 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 2 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 2" x-collapse class="px-6 pb-6 text-sm text-gray-600 leading-relaxed font-light">Digital Invoice is fully flexible: pay Monthly, Quarterly (1% off), Semi-Annual (3% off) or Annual (6% off). You pick the cycle at checkout — no lock-in beyond the period you choose.</div>
                </div>
                <div class="border border-gray-200 rounded-lg bg-white">
                    <button @click="open = (open === 3 ? null : 3)" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="font-bold text-gray-900 text-sm uppercase tracking-wide">I don't know my HS codes. Does the system help?</span>
                        <svg class="w-5 h-5 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 3 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 3" x-collapse class="px-6 pb-6 text-sm text-gray-600 leading-relaxed font-light">Yes. As you type an item, the HS intelligence engine suggests matching codes and checks them against the correct tax schedule — so the right rate is applied before the invoice ever leaves your screen.</div>
                </div>
                <div class="border border-gray-200 rounded-lg bg-white">
                    <button @click="open = (open === 4 ? null : 4)" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="font-bold text-gray-900 text-sm uppercase tracking-wide">What happens if a submission fails?</span>
                        <svg class="w-5 h-5 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 4 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 4" x-collapse class="px-6 pb-6 text-sm text-gray-600 leading-relaxed font-light">Nothing is lost. The invoice stays in your panel with the exact FBR response, you fix the flagged field and resubmit. Duplicate protection makes sure a retry never creates a second invoice.</div>
                </div>
                <div class="border border-gray-200 rounded-lg bg-white">
                    <button @click="open = (open === 5 ? null : 5)" class="w-full flex items-center justify-between p-6 text-left">
                        <span class="font-bold text-gray-900 text-sm uppercase tracking-wide">Do I get printable invoices and customer statements?</span>
                        <svg class="w-5 h-5 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 5 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 5" x-collapse class="px-6 pb-6 text-sm text-gray-600 leading-relaxed font-light">Yes. Every invoice generates a formal print-ready PDF, and each customer has a running ledger of invoices and payments you can review or adjust at any time.</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer CTA & Footer -->
    <footer class="mt-auto border-t border-[#0A4D5C] bg-[#052730]">
        <div class="absolute inset-0 bg-grid-pattern-dark opacity-30 pointer-events-none"></div>
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 py-24 text-center relative z-10">
            <h2 class="text-4xl font-serif font-bold text-white mb-6">Ready for a calmer month-end?</h2>
            <p class="text-lg text-white/70 mb-10 max-w-2xl mx-auto font-light">Get full access to Digital Invoice for 3 days. No credit card required. Experience validation that actually works.</p>
            <a href="/register" class="btn-solid btn-gold text-base px-8 py-4">
                Start Trial Account
            </a>
        </div>
        <div class="border-t border-white/10 py-8 relative z-10">
            <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row items-center justify-between gap-4">
                <div class="flex items-center gap-6">
                    <img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" alt="TaxNest" class="h-6 opacity-50">
                    <p class="text-sm text-white/40 tracking-wider">© {{ date('Y') }} TaxNest. All rights reserved.</p>
                </div>
                <div class="flex items-center gap-8">
                    <a href="/pos" class="text-sm text-white/50 hover:text-white transition tracking-wide">PRA POS</a>
                    <a href="/fbr-pos-landing" class="text-sm text-white/50 hover:text-white transition tracking-wide">FBR POS</a>
                </div>
            </div>
        </div>
    </footer>

    <script>
        // Simple scroll reveal
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