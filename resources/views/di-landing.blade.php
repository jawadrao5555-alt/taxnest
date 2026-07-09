<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Digital Invoice — Calm, Guided FBR Invoicing by TaxNest</title>
    
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700|ibm-plex-mono:400,500,600&display=swap" rel="stylesheet">
    
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
    <style>
        :root {
            --font-sans: 'Plus Jakarta Sans', sans-serif;
            --font-mono: 'IBM Plex Mono', monospace;
            --emerald-50: #ecfdf5;
            --emerald-100: #d1fae5;
            --emerald-600: #059669;
            --emerald-700: #047857;
            --emerald-800: #065f46;
            --emerald-900: #064e3b;
            --desk-surface: #F8FBF9;
        }
        
        body { 
            font-family: var(--font-sans); 
            background-color: var(--desk-surface);
            color: #0F172A;
            -webkit-font-smoothing: antialiased;
        }

        .font-mono {
            font-family: var(--font-mono);
        }

        .desk-grid {
            background-image: linear-gradient(to right, rgba(5, 150, 105, 0.05) 1px, transparent 1px),
                              linear-gradient(to bottom, rgba(5, 150, 105, 0.05) 1px, transparent 1px);
            background-size: 40px 40px;
        }

        .solid-button {
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            box-shadow: 0 2px 0 0 rgba(0,0,0,0.8);
        }
        .solid-button:hover {
            transform: translateY(1px);
            box-shadow: 0 1px 0 0 rgba(0,0,0,0.8);
        }
        .solid-button:active {
            transform: translateY(2px);
            box-shadow: none;
        }

        .solid-card {
            background: #ffffff;
            border: 1px solid #E2E8F0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            transition: box-shadow 0.3s ease, border-color 0.3s ease;
        }

        .solid-card:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.03);
            border-color: #cbd5e1;
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
<body class="relative min-h-[100dvh] flex flex-col" x-data="{ showLoginModal: {{ isset($showLogin) && $showLogin ? 'true' : 'false' }} }">

    <!-- Login Modal -->
    <div x-show="showLoginModal" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center p-4">
        <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm" @click="showLoginModal = false" x-transition.opacity></div>
        <div class="relative w-full max-w-md bg-white rounded-xl shadow-2xl border border-slate-200 overflow-hidden" 
             x-transition:enter="transition ease-out duration-300" 
             x-transition:enter-start="opacity-0 scale-95 translate-y-4" 
             x-transition:enter-end="opacity-100 scale-100 translate-y-0" 
             x-transition:leave="transition ease-in duration-200" 
             x-transition:leave-start="opacity-100 scale-100" 
             x-transition:leave-end="opacity-0 scale-95">
            <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-emerald-700 text-white flex items-center justify-center rounded-lg shadow-sm">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-slate-900">Digital Invoice</h3>
                        <p class="text-xs text-slate-500 font-mono">FBR Portal Access</p>
                    </div>
                </div>
                <button @click="showLoginModal = false" class="text-slate-400 hover:text-slate-600 transition p-1">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <form method="POST" action="/login" class="px-6 py-6 space-y-5">
                @csrf
                <div>
                    <label class="block text-xs font-bold tracking-wide text-slate-700 uppercase mb-2">Identifier</label>
                    <input type="text" name="login" required autofocus class="w-full px-4 py-2.5 rounded-lg border border-slate-300 text-sm text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-colors" placeholder="Email, Phone, Username, CNIC or NTN">
                </div>
                <div>
                    <label class="block text-xs font-bold tracking-wide text-slate-700 uppercase mb-2">Password</label>
                    <input type="password" name="password" required autocomplete="current-password" class="w-full px-4 py-2.5 rounded-lg border border-slate-300 text-sm text-slate-900 focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition-colors" placeholder="••••••••">
                </div>
                <div class="flex items-center justify-between">
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" name="remember" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 mr-2 w-4 h-4">
                        <span class="text-sm text-slate-600">Remember me</span>
                    </label>
                    <a href="{{ route('password.request') ?? '#' }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium transition">Forgot Password?</a>
                </div>
                @if($errors->any())
                <div class="bg-red-50 text-red-700 border border-red-200 rounded-lg p-3 text-sm">
                    @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                    @endforeach
                </div>
                @endif
                <button type="submit" class="w-full py-3 bg-slate-900 text-white rounded-lg text-sm font-bold solid-button">
                    Sign In to Dashboard
                </button>
                <div class="text-center pt-2">
                    <p class="text-sm text-slate-500">
                        No account? <a href="/register" class="font-semibold text-emerald-700 hover:text-emerald-800 transition">Start 3-Day Trial</a>
                    </p>
                </div>
            </form>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="sticky top-0 w-full z-50 bg-white/90 backdrop-blur-md border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center gap-4 sm:gap-8">
                    <a href="/" class="flex-shrink-0">
                        <img src="{{ asset('images/brand/taxnest-logo.svg') }}" alt="TaxNest" class="h-8">
                    </a>
                    <div class="hidden md:flex items-center gap-6">
                        <a href="/pos" class="text-sm font-medium text-slate-600 hover:text-slate-900">PRA POS</a>
                        <a href="/fbr-pos-landing" class="text-sm font-medium text-slate-600 hover:text-slate-900">FBR POS</a>
                        <a href="#features" class="text-sm font-medium text-slate-600 hover:text-slate-900">Features</a>
                        <a href="#pricing" class="text-sm font-medium text-slate-600 hover:text-slate-900">Pricing</a>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <button @click="showLoginModal = true" class="text-sm font-medium text-slate-700 hover:text-slate-900 px-3 py-2">Log In</button>
                    <a href="/register" class="bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-bold solid-button hidden sm:inline-flex">Start Free Trial</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <header class="relative pt-20 pb-24 lg:pt-32 lg:pb-40 overflow-hidden desk-grid border-b border-slate-200">
        <div class="absolute inset-0 bg-gradient-to-b from-transparent to-white/90 pointer-events-none"></div>
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 z-10">
            <div class="grid lg:grid-cols-2 gap-16 items-center">
                <div class="max-w-2xl reveal-on-scroll">
                    <div class="inline-flex items-center gap-2 px-3 py-1 bg-emerald-50 border border-emerald-200 rounded-md text-emerald-800 text-xs font-mono font-medium mb-8">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        PRAL API v1.12 Integration Active
                    </div>
                    <h1 class="text-4xl sm:text-5xl lg:text-6xl font-bold text-slate-900 leading-[1.1] tracking-tight mb-6">
                        A perfectly organized desk for <span class="text-emerald-700">FBR invoicing.</span>
                    </h1>
                    <p class="text-lg text-slate-600 mb-8 leading-relaxed">
                        TaxNest Digital Invoice turns stressful government reporting into a calm, guided workflow. Catch errors before submission, suggest HS codes in real-time, and ensure every invoice is accepted.
                    </p>
                    <div class="flex flex-col sm:flex-row items-center gap-4">
                        <a href="/register" class="w-full sm:w-auto px-6 py-3.5 bg-emerald-700 text-white rounded-lg text-base font-bold solid-button text-center flex items-center justify-center gap-2">
                            Start 3-Day Trial
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                        </a>
                        <p class="text-xs text-slate-500 font-mono">No credit card required</p>
                    </div>
                </div>
                <div class="relative lg:-mr-12 xl:-mr-24 reveal-on-scroll" style="transition-delay: 0.2s">
                    <div class="bg-white rounded-xl border border-slate-200 shadow-xl overflow-hidden solid-card">
                        <div class="bg-slate-50 border-b border-slate-200 px-4 py-3 flex items-center gap-2">
                            <div class="flex gap-1.5">
                                <div class="w-3 h-3 rounded-full bg-slate-300"></div>
                                <div class="w-3 h-3 rounded-full bg-slate-300"></div>
                                <div class="w-3 h-3 rounded-full bg-slate-300"></div>
                            </div>
                            <div class="ml-4 px-3 py-1 bg-white border border-slate-200 rounded text-[10px] font-mono text-slate-500 flex-1 max-w-[200px] truncate">
                                taxnest.com/digital-invoice
                            </div>
                        </div>
                        <img src="{{ asset('images/screenshots/di-dash.jpg') }}" alt="Digital Invoice Dashboard" class="w-full h-auto block">
                    </div>
                    <!-- Decorative element indicating precision -->
                    <div class="absolute -bottom-6 -left-6 bg-slate-900 text-white px-4 py-3 rounded-lg shadow-lg font-mono text-xs hidden sm:block border border-slate-700">
                        <div class="flex items-center gap-2 text-emerald-400 mb-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            <span>Validation Passed</span>
                        </div>
                        <span class="text-slate-400">Payload ready for PRAL API</span>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Features Ledger Section -->
    <section id="features" class="py-24 bg-white border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-16 max-w-3xl reveal-on-scroll">
                <h2 class="text-3xl font-bold text-slate-900 tracking-tight mb-4">Precision-engineered for compliance.</h2>
                <p class="text-lg text-slate-600">Every feature is built around a single goal: ensuring your invoices are structurally perfect before FBR ever sees them.</p>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Feature 1 -->
                <div class="solid-card p-8 rounded-xl reveal-on-scroll">
                    <div class="w-10 h-10 bg-emerald-50 border border-emerald-100 text-emerald-700 flex items-center justify-center rounded-lg mb-6">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 mb-2">Pre-Submission Validation</h3>
                    <p class="text-slate-600 text-sm leading-relaxed">Tax-schedule validation (ScheduleEngine) and payload checking ensure errors are caught and fixed before FBR sees them, preventing rejected submissions.</p>
                </div>

                <!-- Feature 2 -->
                <div class="solid-card p-8 rounded-xl reveal-on-scroll">
                    <div class="w-10 h-10 bg-slate-50 border border-slate-200 text-slate-700 flex items-center justify-center rounded-lg mb-6">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 mb-2">HS Code Intelligence</h3>
                    <p class="text-slate-600 text-sm leading-relaxed">Receive real-time HS code suggestions while creating your invoice. Never guess a tax rate again; the system guides you to the right classification.</p>
                </div>

                <!-- Feature 3 -->
                <div class="solid-card p-8 rounded-xl reveal-on-scroll">
                    <div class="w-10 h-10 bg-slate-50 border border-slate-200 text-slate-700 flex items-center justify-center rounded-lg mb-6">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path></svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 mb-2">Sandbox & Production Modes</h3>
                    <p class="text-slate-600 text-sm leading-relaxed">Train your staff safely in Sandbox mode without affecting real FBR data. Switch to Production instantly when you're ready to go live.</p>
                </div>

                <!-- Feature 4 -->
                <div class="solid-card p-8 rounded-xl reveal-on-scroll">
                    <div class="w-10 h-10 bg-slate-50 border border-slate-200 text-slate-700 flex items-center justify-center rounded-lg mb-6">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 mb-2">Clean B&W PDFs</h3>
                    <p class="text-slate-600 text-sm leading-relaxed">Generate perfectly formatted, clean black-and-white invoice PDFs featuring dual invoice numbering (internal + FBR) and all required per-item FBR fields.</p>
                </div>

                <!-- Feature 5 -->
                <div class="solid-card p-8 rounded-xl reveal-on-scroll">
                    <div class="w-10 h-10 bg-slate-50 border border-slate-200 text-slate-700 flex items-center justify-center rounded-lg mb-6">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 mb-2">Ledger & WHT Manager</h3>
                    <p class="text-slate-600 text-sm leading-relaxed">Integrated customer ledger with automatic debits, alongside a dedicated WHT manager to keep track of withholdings seamlessly.</p>
                </div>

                <!-- Feature 6 -->
                <div class="solid-card p-8 rounded-xl reveal-on-scroll">
                    <div class="w-10 h-10 bg-slate-50 border border-slate-200 text-slate-700 flex items-center justify-center rounded-lg mb-6">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 mb-2">Immutable Audit Logs</h3>
                    <p class="text-slate-600 text-sm leading-relaxed">Every action leaves a permanent trace. MIS reports and immutable audit logs ensure complete transparency across multi-branch setups.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Workflow / UI Showcase -->
    <section class="py-24 bg-slate-50 border-b border-slate-200 overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-2 gap-16 items-center">
                <div class="order-2 lg:order-1 reveal-on-scroll">
                    <div class="bg-white rounded-xl border border-slate-200 shadow-lg p-2 solid-card">
                        <img src="{{ asset('images/screenshots/di-create.jpg') }}" alt="Invoice Creation Form" class="w-full h-auto rounded border border-slate-100">
                    </div>
                </div>
                <div class="order-1 lg:order-2 reveal-on-scroll">
                    <h2 class="text-3xl font-bold text-slate-900 tracking-tight mb-6">A clear interface for complex data.</h2>
                    <p class="text-lg text-slate-600 mb-8">No more staring at dense portal screens. We've organized the required FBR data points into a logical, airy layout that feels like a modern spreadsheet.</p>
                    
                    <ul class="space-y-4">
                        <li class="flex items-start gap-3">
                            <div class="w-6 h-6 rounded bg-emerald-100 text-emerald-700 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            </div>
                            <span class="text-slate-700 font-medium">Per-item FBR fields clearly exposed and validated.</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <div class="w-6 h-6 rounded bg-emerald-100 text-emerald-700 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            </div>
                            <span class="text-slate-700 font-medium">Real-time feedback before you hit submit.</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <div class="w-6 h-6 rounded bg-emerald-100 text-emerald-700 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            </div>
                            <span class="text-slate-700 font-medium">Installable PWA for fast desktop access.</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="grid lg:grid-cols-2 gap-16 items-center mt-32">
                <div class="reveal-on-scroll">
                    <h2 class="text-3xl font-bold text-slate-900 tracking-tight mb-6">Track every submission meticulously.</h2>
                    <p class="text-lg text-slate-600 mb-8">Maintain a perfect historical record. The invoice list provides immediate status visibility and quick access to clean PDFs.</p>
                    <a href="/register" class="text-emerald-700 font-bold hover:text-emerald-800 transition flex items-center gap-1">
                        Try the workflow yourself
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                    </a>
                </div>
                <div class="reveal-on-scroll">
                    <div class="bg-white rounded-xl border border-slate-200 shadow-lg p-2 solid-card">
                        <img src="{{ asset('images/screenshots/di-list.jpg') }}" alt="Invoice List" class="w-full h-auto rounded border border-slate-100">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section id="pricing" class="py-24 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16 reveal-on-scroll">
                <h2 class="text-3xl font-bold text-slate-900 tracking-tight mb-4">Simple, predictable pricing.</h2>
                <p class="text-lg text-slate-600 max-w-2xl mx-auto">Choose the billing cycle that fits your business. All plans include 3-day free trial.</p>
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
                    <div class="inline-flex bg-slate-100 p-1 rounded-lg border border-slate-200">
                        <button @click="cycle = 'monthly'" :class="cycle === 'monthly' ? 'bg-white shadow-sm border-slate-200 text-slate-900' : 'border-transparent text-slate-600 hover:text-slate-900'" class="px-5 py-2 rounded-md text-sm font-bold transition border">Monthly</button>
                        <button @click="cycle = 'quarterly'" :class="cycle === 'quarterly' ? 'bg-white shadow-sm border-slate-200 text-slate-900' : 'border-transparent text-slate-600 hover:text-slate-900'" class="px-5 py-2 rounded-md text-sm font-bold transition border flex items-center gap-1.5">Quarterly <span class="bg-emerald-100 text-emerald-800 px-1.5 py-0.5 rounded text-[10px] font-mono leading-none">-1%</span></button>
                        <button @click="cycle = 'semi_annual'" :class="cycle === 'semi_annual' ? 'bg-white shadow-sm border-slate-200 text-slate-900' : 'border-transparent text-slate-600 hover:text-slate-900'" class="px-5 py-2 rounded-md text-sm font-bold transition border flex items-center gap-1.5">Semi-Annual <span class="bg-emerald-100 text-emerald-800 px-1.5 py-0.5 rounded text-[10px] font-mono leading-none">-3%</span></button>
                        <button @click="cycle = 'annual'" :class="cycle === 'annual' ? 'bg-white shadow-sm border-slate-200 text-slate-900' : 'border-transparent text-slate-600 hover:text-slate-900'" class="px-5 py-2 rounded-md text-sm font-bold transition border flex items-center gap-1.5">Annual <span class="bg-emerald-100 text-emerald-800 px-1.5 py-0.5 rounded text-[10px] font-mono leading-none">-6%</span></button>
                    </div>
                </div>

                @if(isset($plans) && $plans->count())
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 max-w-6xl mx-auto">
                    @foreach($plans as $plan)
                    @php
                        $isPopular = $plan->name === 'Business';
                        $hasOffer = $plan->sale_percent > 0;
                    @endphp
                    <div class="solid-card rounded-xl overflow-hidden flex flex-col {{ $isPopular ? 'border-emerald-600 ring-1 ring-emerald-600' : '' }}">
                        @if($isPopular)
                        <div class="bg-emerald-600 text-white text-xs font-bold font-mono text-center py-2 uppercase tracking-widest border-b border-emerald-700">
                            Recommended
                        </div>
                        @endif
                        <div class="p-6 flex-1 flex flex-col">
                            <h3 class="text-xl font-bold text-slate-900">{{ $plan->name }}</h3>
                            
                            <div class="mt-6 mb-8">
                                <div x-show="cycle === 'monthly'">
                                    @if($hasOffer)<span class="text-sm text-slate-400 line-through block mb-1">PKR {{ number_format($plan->price, 0) }}</span>@endif
                                    <div class="flex items-baseline gap-1">
                                        <span class="text-3xl font-bold text-slate-900 tracking-tight">PKR {{ number_format($plan->sale_price, 0) }}</span>
                                        <span class="text-slate-500 font-mono text-sm">/mo</span>
                                    </div>
                                </div>
                                <div x-show="cycle !== 'monthly'" style="display:none;">
                                    @if($hasOffer)<span class="text-sm text-slate-400 line-through block mb-1">PKR <span x-text="calcMonthly({{ $plan->price }}).toLocaleString()"></span></span>@endif
                                    <div class="flex items-baseline gap-1">
                                        <span class="text-3xl font-bold text-slate-900 tracking-tight">PKR <span x-text="calcMonthly({{ $plan->sale_price }}).toLocaleString()"></span></span>
                                        <span class="text-slate-500 font-mono text-sm">/mo</span>
                                    </div>
                                    <div class="mt-2 text-xs text-emerald-700 bg-emerald-50 inline-block px-2 py-1 rounded font-mono border border-emerald-100">
                                        Billed PKR <span x-text="calcPrice({{ $plan->sale_price }}).toLocaleString()"></span>
                                    </div>
                                </div>
                            </div>

                            @php
                                $diFeatures = is_array($plan->features) ? $plan->features : (is_string($plan->features) ? json_decode($plan->features, true) : []);
                            @endphp
                            
                            <ul class="space-y-3 mb-8 flex-1">
                                @if(!empty($diFeatures))
                                    @foreach($diFeatures as $feature)
                                    <li class="flex items-start gap-3 text-sm text-slate-600">
                                        <svg class="w-4 h-4 text-slate-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                        {{ $feature }}
                                    </li>
                                    @endforeach
                                @else
                                    <li class="flex items-start gap-3 text-sm text-slate-600">
                                        <svg class="w-4 h-4 text-slate-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                        {{ $plan->invoice_limit > 0 ? number_format($plan->invoice_limit) . ' invoices/mo' : 'Unlimited invoices' }}
                                    </li>
                                    <li class="flex items-start gap-3 text-sm text-slate-600">
                                        <svg class="w-4 h-4 text-slate-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                        FBR PRAL API Submission
                                    </li>
                                @endif
                            </ul>

                            <a href="/register" class="block w-full py-3 rounded-lg text-sm font-bold text-center solid-button {{ $isPopular ? 'bg-emerald-700 text-white' : 'bg-slate-100 text-slate-900 border border-slate-300 hover:bg-slate-200' }}">
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
    <section class="py-20 bg-white border-t border-slate-200">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <p class="text-xs font-bold tracking-widest uppercase text-emerald-700 mb-3">Questions</p>
                <h2 class="text-3xl font-bold text-slate-900">Frequently asked questions</h2>
            </div>
            <div class="space-y-3" x-data="{ open: null }">
                <div class="bg-white border border-slate-200 rounded-lg">
                    <button @click="open = (open === 1 ? null : 1)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-slate-900 text-sm">Can I test invoices before sending them to FBR?</span>
                        <svg class="w-4 h-4 text-slate-400 transition-transform flex-shrink-0 ml-4" :class="open === 1 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 1" x-collapse class="px-5 pb-5 text-sm text-slate-600 leading-relaxed">Yes. Every invoice is validated against FBR's rules before submission, and a sandbox environment lets you verify the full payload safely before anything touches production.</div>
                </div>
                <div class="bg-white border border-slate-200 rounded-lg">
                    <button @click="open = (open === 2 ? null : 2)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-slate-900 text-sm">Which billing cycles are available?</span>
                        <svg class="w-4 h-4 text-slate-400 transition-transform flex-shrink-0 ml-4" :class="open === 2 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 2" x-collapse class="px-5 pb-5 text-sm text-slate-600 leading-relaxed">Digital Invoice is fully flexible: pay Monthly, Quarterly (1% off), Semi-Annual (3% off) or Annual (6% off). You pick the cycle at checkout — no lock-in beyond the period you choose.</div>
                </div>
                <div class="bg-white border border-slate-200 rounded-lg">
                    <button @click="open = (open === 3 ? null : 3)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-slate-900 text-sm">I don't know my HS codes. Does the system help?</span>
                        <svg class="w-4 h-4 text-slate-400 transition-transform flex-shrink-0 ml-4" :class="open === 3 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 3" x-collapse class="px-5 pb-5 text-sm text-slate-600 leading-relaxed">Yes. As you type an item, the HS intelligence engine suggests matching codes and checks them against the correct tax schedule — so the right rate is applied before the invoice ever leaves your screen.</div>
                </div>
                <div class="bg-white border border-slate-200 rounded-lg">
                    <button @click="open = (open === 4 ? null : 4)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-slate-900 text-sm">What happens if a submission fails?</span>
                        <svg class="w-4 h-4 text-slate-400 transition-transform flex-shrink-0 ml-4" :class="open === 4 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 4" x-collapse class="px-5 pb-5 text-sm text-slate-600 leading-relaxed">Nothing is lost. The invoice stays in your panel with the exact FBR response, you fix the flagged field and resubmit. Duplicate protection makes sure a retry never creates a second invoice.</div>
                </div>
                <div class="bg-white border border-slate-200 rounded-lg">
                    <button @click="open = (open === 5 ? null : 5)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-slate-900 text-sm">Do I get printable invoices and customer statements?</span>
                        <svg class="w-4 h-4 text-slate-400 transition-transform flex-shrink-0 ml-4" :class="open === 5 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 5" x-collapse class="px-5 pb-5 text-sm text-slate-600 leading-relaxed">Yes. Every invoice generates a formal print-ready PDF, and each customer has a running ledger of invoices and payments you can review or adjust at any time.</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer CTA & Footer -->
    <footer class="mt-auto border-t border-slate-200 bg-slate-900">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 text-center">
            <h2 class="text-3xl font-bold text-white tracking-tight mb-6">Ready for a calmer month-end?</h2>
            <p class="text-slate-400 mb-8 max-w-xl mx-auto">Get full access to Digital Invoice for 3 days. No credit card required. Experience validation that actually works.</p>
            <a href="/register" class="inline-flex px-8 py-4 bg-emerald-600 text-white rounded-lg text-base font-bold solid-button hover:bg-emerald-500 transition-colors">
                Start Trial Account
            </a>
        </div>
        <div class="border-t border-slate-800 py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row items-center justify-between gap-4">
                <div class="flex items-center gap-6">
                    <img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" alt="TaxNest" class="h-6 opacity-50">
                    <p class="text-sm font-mono text-slate-500">© {{ date('Y') }} TaxNest. All rights reserved.</p>
                </div>
                <div class="flex items-center gap-6">
                    <a href="/pos" class="text-sm font-mono text-slate-500 hover:text-white transition">PRA POS</a>
                    <a href="/fbr-pos-landing" class="text-sm font-mono text-slate-500 hover:text-white transition">FBR POS</a>
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
