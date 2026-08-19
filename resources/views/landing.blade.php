<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#052730">
    <link rel="icon" type="image/svg+xml" href="/images/brand/taxnest-mark.svg">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <title>TaxNest — Tax Compliance Platform for Pakistan</title>
    <meta name="description" content="TaxNest is Pakistan's all-in-one FBR and PRA compliance platform — digital invoicing, POS billing, and audit-ready reporting for businesses of every size.">
    @include('partials.meta-og', [
        'ogTitle'       => 'TaxNest — Tax Compliance Platform for Pakistan',
        'ogDescription' => 'Pakistan\'s all-in-one FBR and PRA compliance platform. Issue FBR Digital Invoices and PRA-integrated POS receipts — all from one account.',
        'ogUrl'         => 'https://taxnest.com.pk/',
    ])
    <link rel="canonical" href="https://taxnest.com.pk/">
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

    <!-- Structured Data -->
    <script type="application/ld+json">
    {
        "@@context": "https://schema.org",
        "@@graph": [
            {
                "@@type": "Organization",
                "@@id": "https://taxnest.com.pk/#organization",
                "name": "TaxNest",
                "url": "https://taxnest.com.pk/",
                "logo": {
                    "@@type": "ImageObject",
                    "url": "https://taxnest.com.pk/images/brand/taxnest-logo.svg"
                },
                "description": "Pakistan's FBR and PRA compliant tax, invoicing, and point-of-sale platform for retailers, restaurants, and wholesalers.",
                "areaServed": "PK",
                "foundingLocation": { "@@type": "Country", "name": "Pakistan" }
            },
            {
                "@@type": "WebSite",
                "@@id": "https://taxnest.com.pk/#website",
                "url": "https://taxnest.com.pk/",
                "name": "TaxNest",
                "publisher": { "@@id": "https://taxnest.com.pk/#organization" }
            },
            {
                "@@type": "SoftwareApplication",
                "@@id": "https://taxnest.com.pk/#software",
                "name": "TaxNest",
                "applicationCategory": "BusinessApplication",
                "operatingSystem": "Web",
                "description": "Pakistan's FBR and PRA compliant tax, invoicing, and point-of-sale platform for retailers, restaurants, and wholesalers.",
                "offers": { "@@type": "Offer", "price": "0", "priceCurrency": "PKR" },
                "url": "https://taxnest.com.pk/",
                "publisher": { "@@id": "https://taxnest.com.pk/#organization" }
            }
        ]
    }
    </script>
    <script type="application/ld+json">
    {
        "@@context": "https://schema.org",
        "@@type": "FAQPage",
        "mainEntity": [
            {
                "@@type": "Question",
                "name": "Is there a free trial?",
                "acceptedAnswer": {
                    "@@type": "Answer",
                    "text": "Yes. Every product starts with a 3-day free trial — no credit card required. Register, get approved, and explore the full system before paying."
                }
            },
            {
                "@@type": "Question",
                "name": "Are the three products connected to each other?",
                "acceptedAnswer": {
                    "@@type": "Answer",
                    "text": "No — by design. Digital Invoice, NestPOS and FBR POS each have their own login, their own data and their own panel. Nothing leaks between products, which keeps every regulator's records clean."
                }
            },
            {
                "@@type": "Question",
                "name": "What happens after I register?",
                "acceptedAnswer": {
                    "@@type": "Answer",
                    "text": "Your company goes to our team for a quick review. While pending you can look around the whole panel; once approved, everything unlocks and your trial begins."
                }
            },
            {
                "@@type": "Question",
                "name": "Does the POS keep working if the internet drops?",
                "acceptedAnswer": {
                    "@@type": "Answer",
                    "text": "Yes. Bills keep printing offline and the system submits them to the authority automatically the moment connectivity returns — no manual re-entry."
                }
            },
            {
                "@@type": "Question",
                "name": "Can I run branches and multiple staff accounts?",
                "acceptedAnswer": {
                    "@@type": "Answer",
                    "text": "Yes. Plans include multiple users and branches with role-based access — cashiers, managers and admins each see exactly what they should, and every action lands in an immutable audit log."
                }
            }
        ]
    }
    </script>
</head>
<body x-data="{ scrolled: false, mobileOpen: false }" @scroll.window="scrolled = (window.pageYOffset > 20)">
    <div id="tn-boot" aria-hidden="true"><span>Tax<b>Nest</b></span></div>

    <!-- Navigation -->
    <nav :class="(scrolled || mobileOpen) ? 'nav-scrolled' : 'nav-transparent'" class="fixed top-0 w-full z-50 transition-all duration-300">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <a href="/" class="flex-shrink-0">
                    <img src="{{ asset('images/brand/taxnest-logo.svg') }}" x-show="scrolled || mobileOpen" alt="TaxNest" class="h-8 w-auto">
                    <img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" x-show="!scrolled && !mobileOpen" alt="TaxNest" class="h-8 w-auto">
                </a>
                <div class="hidden md:flex items-center space-x-8">
                    <a href="/digital-invoice" class="text-sm font-medium transition-colors" :class="scrolled ? 'text-gray-600 hover:text-gray-900' : 'text-gray-200 hover:text-white'">Digital Invoice</a>
                    <a href="/pos" class="text-sm font-medium transition-colors" :class="scrolled ? 'text-gray-600 hover:text-gray-900' : 'text-gray-200 hover:text-white'">NestPOS (PRA)</a>
                    <a href="/fbr-pos-landing" class="text-sm font-medium transition-colors" :class="scrolled ? 'text-gray-600 hover:text-gray-900' : 'text-gray-200 hover:text-white'">FBR POS</a>
                    <a href="#compare" class="text-sm font-medium transition-colors" :class="scrolled ? 'text-gray-600 hover:text-gray-900' : 'text-gray-200 hover:text-white'">Compare</a>
                    <a href="/download" class="text-sm font-medium transition-colors" :class="scrolled ? 'text-gray-600 hover:text-gray-900' : 'text-gray-200 hover:text-white'">Downloads</a>
                    <a href="/tutorials" class="text-sm font-medium transition-colors" :class="scrolled ? 'text-gray-600 hover:text-gray-900' : 'text-gray-200 hover:text-white'">Tutorials</a>
                    <a href="/contact" class="text-sm font-medium transition-colors" :class="scrolled ? 'text-gray-600 hover:text-gray-900' : 'text-gray-200 hover:text-white'">Contact</a>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="/login" class="hidden sm:inline text-sm font-medium transition-colors" :class="scrolled ? 'text-gray-600 hover:text-gray-900' : 'text-gray-200 hover:text-white'">Log In</a>
                    <a href="/register" class="btn-solid" :class="scrolled ? 'btn-primary' : 'bg-white text-[#052730] hover:bg-gray-100'">Get Started</a>
                    <button @click="mobileOpen = !mobileOpen" class="md:hidden p-2 -mr-2" aria-label="Menu">
                        <svg x-show="!mobileOpen" class="w-6 h-6" :class="scrolled ? 'text-gray-800' : 'text-white'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        <svg x-show="mobileOpen" x-cloak class="w-6 h-6 text-gray-800" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
            <div x-show="mobileOpen" x-cloak @click.away="mobileOpen = false" class="md:hidden border-t border-gray-200 py-4 space-y-1 bg-[#FDFBF7]">
                <a href="/digital-invoice" class="block px-2 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded">Digital Invoice</a>
                <a href="/pos" class="block px-2 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded">NestPOS (PRA POS)</a>
                <a href="/fbr-pos-landing" class="block px-2 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded">FBR POS</a>
                <a href="#compare" @click="mobileOpen = false" class="block px-2 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded">Compare Products</a>
                <a href="/tutorials" class="block px-2 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded">Tutorials</a>
                <a href="/download" class="block px-2 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded">Downloads</a>
                <a href="/contact" class="block px-2 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded">Contact</a>
                <a href="/login" class="block px-2 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded">Log In</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="relative bg-[#052730] pt-32 pb-20 lg:pt-40 lg:pb-28 overflow-hidden border-b border-[#0A4D5C]">
        <div class="absolute inset-0 bg-grid-pattern-dark opacity-50"></div>
        <div class="absolute -top-32 -right-24 w-[520px] h-[520px] rounded-full pointer-events-none" style="background: radial-gradient(circle, rgba(231,191,59,0.10), transparent 62%);"></div>
        <div class="absolute top-1/3 -left-40 w-[460px] h-[460px] rounded-full pointer-events-none" style="background: radial-gradient(circle, rgba(27,124,140,0.20), transparent 62%);"></div>

        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="grid lg:grid-cols-12 gap-12 lg:gap-10 items-center">

                <!-- Copy -->
                <div class="lg:col-span-6">
                    <div class="inline-flex items-center px-3 py-1 bg-white/5 border border-white/10 rounded-full text-xs font-medium text-white/80 tracking-wide uppercase mb-8">
                        <span class="w-1.5 h-1.5 rounded-full bg-[#E7BF3B] mr-2"></span>
                        Tax Compliance Platform · Pakistan
                    </div>
                    <h1 class="text-4xl sm:text-5xl lg:text-6xl font-serif text-white leading-[1.1] mb-8">
                        Compliance,<br>
                        <span class="text-white/80 italic">structured for Pakistan.</span>
                    </h1>
                    <p class="text-lg text-white/70 leading-relaxed mb-10 max-w-xl font-light">
                        Three fully isolated products under one roof — FBR Digital Invoicing, PRA Point of Sale, and FBR POS. Built by a dedicated team to keep your business compliant with confidence.
                    </p>
                    <div class="flex flex-col sm:flex-row items-start gap-4 mb-10">
                        <a href="#products" class="btn-solid bg-[#E7BF3B] text-[#052730] hover:bg-[#F2D06B]">
                            Explore the Platform
                        </a>
                        <a href="/register" class="btn-solid border border-white/25 text-white hover:bg-white/5">
                            Start Free Trial
                        </a>
                    </div>
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-xs text-white/50">
                        <span class="flex items-center gap-1.5"><svg class="w-4 h-4 text-[#E7BF3B]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> 3-day free trial</span>
                        <span class="flex items-center gap-1.5"><svg class="w-4 h-4 text-[#E7BF3B]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> No credit card</span>
                        <span class="flex items-center gap-1.5"><svg class="w-4 h-4 text-[#E7BF3B]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> FBR &amp; PRA integrated</span>
                    </div>
                </div>

                <!-- Product vignette -->
                <div data-hero-vignette class="lg:col-span-6 relative hidden lg:block">
                    <div class="relative mx-auto max-w-lg">
                        <div class="absolute -top-6 -right-4 w-full h-full rounded-xl bg-white/5 border border-white/10 rotate-3"></div>
                        <div class="relative rounded-xl bg-white shadow-2xl border border-white/10 overflow-hidden">
                            <div class="flex items-center gap-1.5 px-4 py-3 bg-gray-100 border-b border-gray-200">
                                <span class="w-2.5 h-2.5 rounded-full bg-red-400"></span>
                                <span class="w-2.5 h-2.5 rounded-full bg-yellow-400"></span>
                                <span class="w-2.5 h-2.5 rounded-full bg-green-400"></span>
                                <span class="ml-3 text-[11px] text-gray-400 font-medium">app.taxnest — Digital Invoice</span>
                            </div>
                            <img src="{{ asset('images/screenshots/di-dash.jpg') }}" alt="TaxNest Digital Invoice dashboard" class="w-full h-auto" onerror="this.closest('[data-hero-vignette]').style.display='none'">
                        </div>
                        <div class="absolute -bottom-5 -left-5 bg-white rounded-lg shadow-xl border border-gray-100 px-4 py-3 flex items-center gap-3">
                            <span class="w-8 h-8 rounded-full bg-emerald-100 flex items-center justify-center flex-shrink-0">
                                <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            </span>
                            <div>
                                <p class="text-xs font-bold text-gray-900 leading-none">Invoice submitted</p>
                                <p class="text-[11px] text-gray-400 mt-1">Accepted by FBR · PRAL</p>
                            </div>
                        </div>
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
                        <div class="rounded-xl bg-white p-2 border border-gray-200 shadow-xl transition-transform duration-300 hover:-translate-y-1">
                            <img src="{{ asset('images/screenshots/di-dash.jpg') }}" alt="Digital Invoice Dashboard" class="w-full h-auto rounded border border-gray-300 shadow-sm" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIHZpZXdCb3g9IjAgMCA4MDAgNTAwIj48cmVjdCBmaWxsPSIjZjNmMTRiIiB3aWR0aD0iODAwIiBoZWlnaHQ9IjUwMCIvPjx0ZXh0IGZpbGw9IiM5Y2EzYWYiIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBmb250LXNpemU9IjMwIiBkeT0iMTAuNSIgZm9udC13ZWlnaHQ9ImJvbGQiIHg9IjUwJSIgeT0iNTAlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5EaWdpdGFsIEludm9pY2UgU2NyZWVuc2hvdDwvdGV4dD48L3N2Zz4='">
                        </div>
                    </div>
                    <div class="w-full lg:w-1/2 lg:pl-10">
                        <div class="inline-block px-3 py-1 mb-6 bg-emerald-50 border border-emerald-100 rounded-full text-xs font-semibold uppercase tracking-widest accent-emerald">
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
                            <li class="flex items-start">
                                <span class="accent-emerald mr-3 text-lg leading-none">•</span>
                                <span class="text-gray-700 text-sm">One-click FBR Audit Pack — 6-year audit-ready archive</span>
                            </li>
                            <li class="flex items-start">
                                <span class="accent-emerald mr-3 text-lg leading-none">•</span>
                                <span class="text-gray-700 text-sm">Bulk Excel invoice import &amp; AI-powered invoice parsing</span>
                            </li>
                        </ul>
                        <div class="flex gap-4">
                            <a href="/digital-invoice" class="btn-solid btn-primary">Learn More</a>
                            <a href="/digital-invoice#pricing" class="btn-solid btn-secondary">View Pricing</a>
                        </div>
                    </div>
                </div>

                <div class="h-px bg-gradient-to-r from-transparent via-gray-300 to-transparent"></div>

                <!-- NestPOS -->
                <div class="flex flex-col lg:flex-row-reverse gap-12 items-center fade-in-up">
                    <div class="w-full lg:w-1/2">
                        <div class="rounded-xl bg-white p-2 border border-gray-200 shadow-xl transition-transform duration-300 hover:-translate-y-1">
                            <img src="{{ asset('images/screenshots/pos-sale.jpg') }}" alt="NestPOS Sale Screen" class="w-full h-auto rounded border border-gray-300 shadow-sm" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIHZpZXdCb3g9IjAgMCA4MDAgNTAwIj48cmVjdCBmaWxsPSIjZjNmMTRiIiB3aWR0aD0iODAwIiBoZWlnaHQ9IjUwMCIvPjx0ZXh0IGZpbGw9IiM5Y2EzYWYiIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBmb250LXNpemU9IjMwIiBkeT0iMTAuNSIgZm9udC13ZWlnaHQ9ImJvbGQiIHg9IjUwJSIgeT0iNTAlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5OZXN0UE9TIFNjcmVlbnNob3Q8L3RleHQ+PC9zdmc+'">
                        </div>
                    </div>
                    <div class="w-full lg:w-1/2 lg:pr-10">
                        <div class="inline-block px-3 py-1 mb-6 bg-purple-50 border border-purple-100 rounded-full text-xs font-semibold uppercase tracking-widest accent-purple">
                            Punjab Revenue Authority POS
                        </div>
                        <h4 class="text-3xl font-serif text-[#052730] mb-4">NestPOS <span class="text-xl text-gray-500 font-sans font-medium">— PRA POS</span></h4>
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
                                <span class="text-gray-700 text-sm">Full restaurant suite — tables, kitchen display, waiter tablets &amp; QR menu</span>
                            </li>
                            <li class="flex items-start">
                                <span class="accent-purple mr-3 text-lg leading-none">•</span>
                                <span class="text-gray-700 text-sm">Delivery riders with live map tracking, staff hazri &amp; deals</span>
                            </li>
                            <li class="flex items-start">
                                <span class="accent-purple mr-3 text-lg leading-none">•</span>
                                <span class="text-gray-700 text-sm">Customer khata (credit ledger), receipt themes &amp; Urdu / Roman Urdu interface</span>
                            </li>
                            <li class="flex items-start">
                                <span class="accent-purple mr-3 text-lg leading-none">•</span>
                                <span class="text-gray-700 text-sm">Desktop &amp; mobile apps, offline auto-sync, Madadgar AI support</span>
                            </li>
                        </ul>
                        <div class="flex gap-4">
                            <a href="/pos" class="btn-solid btn-primary">Learn More</a>
                            <a href="/pos#pricing" class="btn-solid btn-secondary">View Pricing</a>
                        </div>
                    </div>
                </div>

                <!-- NestPOS Promo Video -->
                <div class="fade-in-up" x-data="{ playing: false }">
                    <div class="max-w-3xl mx-auto text-center mb-8">
                        <div class="inline-block px-3 py-1 mb-4 bg-purple-50 border border-purple-100 rounded-full text-xs font-semibold uppercase tracking-widest accent-purple">
                            90-Second Overview
                        </div>
                        <h4 class="text-2xl sm:text-3xl font-serif text-[#052730]">See TaxNest in action &mdash; POS, FBR POS &amp; Digital Invoicing</h4>
                    </div>
                    <div class="max-w-4xl mx-auto">
                        <div class="rounded-xl bg-white p-2 border border-gray-200 shadow-xl">
                            <div class="relative rounded overflow-hidden border border-gray-300" style="aspect-ratio: 16/9; background: #052730;">
                                <video x-ref="promoVideo" class="w-full h-full" controls playsinline preload="none"
                                       poster="{{ asset('images/promo-poster.jpg') }}"
                                       x-show="playing" x-cloak>
                                    <source src="{{ asset('videos/taxnest-overview.mp4') }}?v=2" type="video/mp4">
                                </video>
                                <button x-show="!playing" type="button" aria-label="Play video"
                                        @click="playing = true; $nextTick(() => { $refs.promoVideo.play(); })"
                                        class="absolute inset-0 w-full h-full group cursor-pointer">
                                    <img src="{{ asset('images/promo-poster.jpg') }}" alt="NestPOS promo video" loading="lazy" class="absolute inset-0 w-full h-full object-cover">
                                    <span class="absolute inset-0 bg-black/20 group-hover:bg-black/30 transition-colors"></span>
                                    <span class="absolute inset-0 flex items-center justify-center">
                                        <span class="w-16 h-16 sm:w-20 sm:h-20 rounded-full bg-white/95 shadow-xl flex items-center justify-center group-hover:scale-105 transition-transform">
                                            <svg class="w-7 h-7 sm:w-8 sm:h-8 text-[#052730] ml-1" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                        </span>
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="h-px bg-gradient-to-r from-transparent via-gray-300 to-transparent"></div>

                <!-- FBR POS -->
                <div id="fbr-pos" class="flex flex-col lg:flex-row gap-12 items-center fade-in-up">
                    <div class="w-full lg:w-1/2">
                        <div class="rounded-xl bg-white p-2 border border-gray-200 shadow-xl transition-transform duration-300 hover:-translate-y-1">
                            <img src="{{ asset('images/screenshots/fbr-sale.jpg') }}" alt="FBR POS Sale Screen" class="w-full h-auto rounded border border-gray-300 shadow-sm" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAlIiBoZWlnaHQ9IjEwMCUiIHZpZXdCb3g9IjAgMCA4MDAgNTAwIj48cmVjdCBmaWxsPSIjZjNmMTRiIiB3aWR0aD0iODAwIiBoZWlnaHQ9IjUwMCIvPjx0ZXh0IGZpbGw9IiM5Y2EzYWYiIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBmb250LXNpemU9IjMwIiBkeT0iMTAuNSIgZm9udC13ZWlnaHQ9ImJvbGQiIHg9IjUwJSIgeT0iNTAlIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5GQlIgUE9TIFNjcmVlbnNob3Q8L3RleHQ+PC9zdmc+'">
                            <div class="grid grid-cols-3 gap-2 mt-2">
                                <img src="{{ asset('images/screenshots/fbr-reports.jpg') }}?v=1" alt="FBR POS sales reports and analytics" loading="lazy" class="w-full h-auto rounded border border-gray-300 shadow-sm">
                                <img src="{{ asset('images/screenshots/fbr-products.jpg') }}?v=1" alt="FBR POS products page with per-product tax setup" loading="lazy" class="w-full h-auto rounded border border-gray-300 shadow-sm">
                                <img src="{{ asset('images/screenshots/fbr-stock.jpg') }}?v=1" alt="FBR POS stock and purchase page" loading="lazy" class="w-full h-auto rounded border border-gray-300 shadow-sm">
                            </div>
                        </div>
                    </div>
                    <div class="w-full lg:w-1/2 lg:pl-10">
                        <div class="inline-block px-3 py-1 mb-6 bg-blue-50 border border-blue-100 rounded-full text-xs font-semibold uppercase tracking-widest accent-blue">
                            FBR Point of Sale
                        </div>
                        <h4 class="text-3xl font-serif text-[#052730] mb-4">FBR POS</h4>
                        <p class="text-gray-600 mb-6 leading-relaxed">
                            A complete retail &amp; restaurant billing system wired directly to the FBR — cloud submission or fiscal-device local mode. Everything a real shop runs daily, on the same terminal that stays compliant.
                        </p>
                        <ul class="space-y-3 mb-8">
                            <li class="flex items-start">
                                <span class="accent-blue mr-3 text-lg leading-none">•</span>
                                <span class="text-gray-700 text-sm">Direct FBR submission — cloud API or fiscal-device local mode</span>
                            </li>
                            <li class="flex items-start">
                                <span class="accent-blue mr-3 text-lg leading-none">•</span>
                                <span class="text-gray-700 text-sm">Restaurant tools — KOT, kitchen display, dine-in tables &amp; QR menu</span>
                            </li>
                            <li class="flex items-start">
                                <span class="accent-blue mr-3 text-lg leading-none">•</span>
                                <span class="text-gray-700 text-sm">Inventory &amp; stock, product Excel import/export, deals &amp; promotions</span>
                            </li>
                            <li class="flex items-start">
                                <span class="accent-blue mr-3 text-lg leading-none">•</span>
                                <span class="text-gray-700 text-sm">Offline billing with auto-sync, khata, loyalty &amp; delivery riders</span>
                            </li>
                            <li class="flex items-start">
                                <span class="accent-blue mr-3 text-lg leading-none">•</span>
                                <span class="text-gray-700 text-sm">Analytics, Z-reports &amp; shifts, multi-branch, Android mobile app</span>
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

    <!-- Product Comparison -->
    <section id="compare" class="py-24 bg-grid-pattern border-t border-gray-200">
        <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto mb-16 fade-in-up">
                <h2 class="text-sm font-bold tracking-widest uppercase text-gray-500 mb-4">Compare</h2>
                <h3 class="text-3xl font-serif text-[#052730]">Which product fits your business?</h3>
            </div>
            <div class="overflow-x-auto fade-in-up">
                <table class="w-full min-w-[640px] bg-white border border-gray-200 rounded-lg text-sm">
                    <thead>
                        <tr class="border-b border-gray-200">
                            <th class="text-left p-5 font-semibold text-gray-500 uppercase tracking-wider text-xs w-1/4"></th>
                            <th class="text-left p-5">
                                <span class="block font-serif text-lg text-[#052730]">Digital Invoice</span>
                                <span class="text-xs font-medium accent-emerald uppercase tracking-wider">FBR e-Invoicing</span>
                            </th>
                            <th class="text-left p-5">
                                <span class="block font-serif text-lg text-[#052730]">NestPOS</span>
                                <span class="text-xs font-medium accent-purple uppercase tracking-wider">PRA Point of Sale</span>
                            </th>
                            <th class="text-left p-5">
                                <span class="block font-serif text-lg text-[#052730]">FBR POS</span>
                                <span class="text-xs font-medium accent-blue uppercase tracking-wider">FBR Point of Sale</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-700">
                        <tr class="border-b border-gray-100">
                            <td class="p-5 font-semibold text-gray-500 text-xs uppercase tracking-wider">Regulator</td>
                            <td class="p-5">FBR — via PRAL API</td>
                            <td class="p-5">Punjab Revenue Authority</td>
                            <td class="p-5">FBR — direct submission</td>
                        </tr>
                        <tr class="border-b border-gray-100">
                            <td class="p-5 font-semibold text-gray-500 text-xs uppercase tracking-wider">Built for</td>
                            <td class="p-5">Businesses issuing sales tax invoices</td>
                            <td class="p-5">Retail &amp; restaurants in Punjab</td>
                            <td class="p-5">FBR-registered retail counters</td>
                        </tr>
                        <tr class="border-b border-gray-100">
                            <td class="p-5 font-semibold text-gray-500 text-xs uppercase tracking-wider">Billing</td>
                            <td class="p-5">Monthly, Quarterly, Semi-Annual or Annual</td>
                            <td class="p-5">Annual or quarterly billing</td>
                            <td class="p-5">Simple monthly billing</td>
                        </tr>
                        <tr class="border-b border-gray-100">
                            <td class="p-5 font-semibold text-gray-500 text-xs uppercase tracking-wider">Starting at</td>
                            <td class="p-5 font-bold text-[#052730]">PKR 499 / month</td>
                            <td class="p-5 font-bold text-[#052730]">PKR 14,999 / year</td>
                            <td class="p-5 font-bold text-[#052730]">PKR 999 / month</td>
                        </tr>
                        <tr class="border-b border-gray-100">
                            <td class="p-5 font-semibold text-gray-500 text-xs uppercase tracking-wider">Standout features</td>
                            <td class="p-5">HS code intelligence, AI invoice parsing, buyer delivery via WhatsApp &amp; email, consultant console</td>
                            <td class="p-5">Restaurant suite, delivery riders with live tracking, khata, Urdu interface, desktop &amp; mobile apps</td>
                            <td class="p-5">Restaurant KOT &amp; kitchen display, inventory, offline billing, khata &amp; loyalty, delivery riders</td>
                        </tr>
                        <tr>
                            <td class="p-5"></td>
                            <td class="p-5"><a href="/digital-invoice" class="btn-solid btn-primary w-full">Explore DI</a></td>
                            <td class="p-5"><a href="/pos" class="btn-solid btn-primary w-full">Explore NestPOS</a></td>
                            <td class="p-5"><a href="/fbr-pos-landing" class="btn-solid btn-primary w-full">Explore FBR POS</a></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-center text-sm text-gray-500 mt-6 fade-in-up">Every product includes a 3-day free trial. No credit card required.</p>
        </div>
    </section>

    <!-- FAQ -->
    <section class="py-24 bg-white border-t border-gray-200">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12 fade-in-up">
                <h2 class="text-sm font-bold tracking-widest uppercase text-gray-500 mb-4">Questions</h2>
                <h3 class="text-3xl font-serif text-[#052730]">Frequently asked questions</h3>
            </div>
            <div class="space-y-3 fade-in-up" x-data="{ open: null }">
                <div class="card-solid rounded-lg">
                    <button @click="open = (open === 1 ? null : 1)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-[#052730]">Is there a free trial?</span>
                        <svg class="w-4 h-4 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 1 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 1" x-collapse class="px-5 pb-5 text-sm text-gray-600 leading-relaxed">Yes. Every product starts with a 3-day free trial — no credit card required. Register, get approved, and explore the full system before paying.</div>
                </div>
                <div class="card-solid rounded-lg">
                    <button @click="open = (open === 2 ? null : 2)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-[#052730]">Are the three products connected to each other?</span>
                        <svg class="w-4 h-4 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 2 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 2" x-collapse class="px-5 pb-5 text-sm text-gray-600 leading-relaxed">No — by design. Digital Invoice, NestPOS and FBR POS each have their own login, their own data and their own panel. Nothing leaks between products, which keeps every regulator's records clean.</div>
                </div>
                <div class="card-solid rounded-lg">
                    <button @click="open = (open === 3 ? null : 3)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-[#052730]">What happens after I register?</span>
                        <svg class="w-4 h-4 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 3 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 3" x-collapse class="px-5 pb-5 text-sm text-gray-600 leading-relaxed">Your company goes to our team for a quick review. While pending you can look around the whole panel; once approved, everything unlocks and your trial begins.</div>
                </div>
                <div class="card-solid rounded-lg">
                    <button @click="open = (open === 4 ? null : 4)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-[#052730]">Does the POS keep working if the internet drops?</span>
                        <svg class="w-4 h-4 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 4 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 4" x-collapse class="px-5 pb-5 text-sm text-gray-600 leading-relaxed">Yes. Bills keep printing offline and the system submits them to the authority automatically the moment connectivity returns — no manual re-entry.</div>
                </div>
                <div class="card-solid rounded-lg">
                    <button @click="open = (open === 5 ? null : 5)" class="w-full flex items-center justify-between p-5 text-left">
                        <span class="font-semibold text-[#052730]">Can I run branches and multiple staff accounts?</span>
                        <svg class="w-4 h-4 text-gray-400 transition-transform flex-shrink-0 ml-4" :class="open === 5 ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open === 5" x-collapse class="px-5 pb-5 text-sm text-gray-600 leading-relaxed">Yes. Plans include multiple users and branches with role-based access — cashiers, managers and admins each see exactly what they should, and every action lands in an immutable audit log.</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <x-site-footer />

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