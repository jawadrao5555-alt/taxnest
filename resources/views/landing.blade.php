<!DOCTYPE html>
<html lang="en" class="scroll-smooth bg-zinc-950">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="stylesheet" href="{{ asset('css/mobile.css?v=2.5') }}">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <title>TaxNest — Pakistan's Premium Tax Compliance Platform</title>
    
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700|playfair-display:400,500,600,700,800i&display=swap" rel="stylesheet">
    
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
    <style>
        body {
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            background-color: #09090b;
            color: #e4e4e7;
        }
        .font-serif {
            font-family: 'Playfair Display', serif;
        }
        
        .solid-card {
            background-color: #18181b;
            border: 1px solid #27272a;
            transition: border-color 0.3s ease, transform 0.3s ease;
        }
        .solid-card:hover {
            border-color: #52525b;
            transform: translateY(-2px);
        }

        .ambient-bg {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 100vh;
            background: radial-gradient(circle at 50% 0%, rgba(217, 119, 6, 0.08) 0%, rgba(9, 9, 11, 0) 70%);
            pointer-events: none;
            z-index: -1;
        }

        .reveal {
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1), transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .reveal.active {
            opacity: 1;
            transform: translateY(0);
        }
        .delay-100 { transition-delay: 100ms; }
        .delay-200 { transition-delay: 200ms; }
        .delay-300 { transition-delay: 300ms; }

        [x-cloak] { display: none !important; }
        
        .noise-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.8' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)' opacity='0.03'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 50;
        }

        .bg-grid {
            background-size: 40px 40px;
            background-image: linear-gradient(to right, rgba(255, 255, 255, 0.02) 1px, transparent 1px),
                              linear-gradient(to bottom, rgba(255, 255, 255, 0.02) 1px, transparent 1px);
            position: absolute;
            inset: 0;
            z-index: -2;
            mask-image: linear-gradient(to bottom, black 40%, transparent 100%);
            -webkit-mask-image: linear-gradient(to bottom, black 40%, transparent 100%);
        }
    </style>
    <noscript><style>.reveal { opacity: 1 !important; transform: none !important; }</style></noscript>
</head>
<body class="antialiased overflow-x-hidden selection:bg-amber-600 selection:text-white">
    <div class="noise-overlay"></div>
    <div class="ambient-bg"></div>
    <div class="bg-grid"></div>

    <nav class="fixed top-0 w-full z-40 bg-zinc-950/80 backdrop-blur-md border-b border-zinc-800/50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <a href="/" class="flex items-center gap-3 flex-shrink-0 group">
                    <div class="w-10 h-10 bg-zinc-900 border border-zinc-700 rounded flex items-center justify-center group-hover:border-amber-600 transition-colors">
                        <svg class="w-5 h-5 text-amber-600" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                    </div>
                    <span class="text-xl font-bold tracking-tight text-white uppercase">TaxNest</span>
                </a>

                <div class="hidden md:flex items-center gap-8">
                    <a href="/digital-invoice" class="text-sm font-medium text-zinc-400 hover:text-amber-500 transition-colors">Digital Invoice</a>
                    <a href="/pos" class="text-sm font-medium text-zinc-400 hover:text-amber-500 transition-colors">PRA POS</a>
                    <a href="/fbr-pos-landing" class="text-sm font-medium text-zinc-400 hover:text-amber-500 transition-colors">FBR POS</a>
                </div>

                <div class="flex items-center gap-4">
                    <a href="/login" class="hidden sm:inline-flex text-sm font-medium text-zinc-300 hover:text-white transition-colors">Client Portal</a>
                    <a href="/register" class="inline-flex items-center justify-center px-5 py-2.5 text-sm font-bold text-white bg-amber-600 hover:bg-amber-500 rounded transition-colors">
                        Start Compliance
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <section class="relative pt-40 pb-20 lg:pt-52 lg:pb-32 overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 text-center">
            <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full border border-zinc-800 bg-zinc-900/80 text-xs font-medium text-zinc-300 mb-8 reveal shadow-sm shadow-black">
                <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                Pakistan's Federal & Provincial Tax Backbone
            </div>
            
            <h1 class="font-serif text-5xl sm:text-6xl md:text-7xl lg:text-8xl text-white tracking-tight leading-[1.1] mb-8 max-w-5xl mx-auto reveal delay-100">
                The compliance infrastructure of <span class="text-amber-600 italic">serious business.</span>
            </h1>
            
            <p class="text-lg sm:text-xl text-zinc-400 max-w-2xl mx-auto leading-relaxed mb-12 reveal delay-200">
                TaxNest operates silently in the background, executing real-time FBR and PRA fiscalization for Pakistan's top retailers and distributors. Zero downtime. Zero penalties.
            </p>
            
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4 reveal delay-300">
                <a href="#products" class="w-full sm:w-auto inline-flex items-center justify-center px-8 py-4 text-base font-bold text-white bg-amber-600 hover:bg-amber-500 rounded transition-colors shadow-lg shadow-amber-900/20">
                    Explore Tax Systems
                </a>
                <a href="/register" class="w-full sm:w-auto inline-flex items-center justify-center px-8 py-4 text-base font-medium text-white bg-zinc-900 border border-zinc-700 hover:border-zinc-500 rounded transition-colors">
                    Deploy Now
                </a>
            </div>
        </div>
    </section>

    <section class="border-y border-zinc-800/80 bg-zinc-950 relative z-20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-12 text-center divide-y md:divide-y-0 md:divide-x divide-zinc-800/80">
                <div class="reveal">
                    <p class="font-serif text-5xl lg:text-6xl text-white mb-2">{{ $stats['total_invoices'] > 0 ? number_format($stats['total_invoices']) : '50,000+' }}</p>
                    <p class="text-sm font-semibold text-zinc-500 uppercase tracking-widest">Invoices Fiscalized</p>
                </div>
                <div class="reveal delay-100 pt-12 md:pt-0">
                    <p class="font-serif text-5xl lg:text-6xl text-white mb-2">{{ $stats['total_companies'] > 0 ? number_format($stats['total_companies']) : '500+' }}</p>
                    <p class="text-sm font-semibold text-zinc-500 uppercase tracking-widest">Businesses Onboard</p>
                </div>
                <div class="reveal delay-200 pt-12 md:pt-0">
                    <p class="font-serif text-5xl lg:text-6xl text-white mb-2">99.9%</p>
                    <p class="text-sm font-semibold text-zinc-500 uppercase tracking-widest">Platform Uptime</p>
                </div>
            </div>
        </div>
    </section>

    <section id="products" class="py-24 lg:py-32 relative">
        <div class="absolute right-0 top-1/4 w-1/3 h-1/2 bg-amber-600/5 blur-[120px] rounded-full pointer-events-none"></div>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="mb-16 md:mb-24 reveal">
                <h2 class="font-serif text-4xl md:text-5xl text-white tracking-tight mb-4">Enterprise Product Suite</h2>
                <p class="text-xl text-zinc-400 max-w-2xl">Three strictly isolated, purpose-built systems. Architected for rigorous local tax regulations.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <a href="/digital-invoice" class="group block solid-card rounded-xl p-8 md:p-10 reveal">
                    <div class="w-14 h-14 bg-zinc-900 border border-zinc-700/50 rounded flex items-center justify-center mb-8 group-hover:border-amber-600/50 transition-colors">
                        <svg class="w-6 h-6 text-zinc-300 group-hover:text-amber-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    </div>
                    <h3 class="font-serif text-3xl text-white mb-4">Digital Invoice</h3>
                    <p class="text-zinc-400 mb-8 leading-relaxed min-h-[100px]">Direct B2B FBR Integration. Features instantaneous Annex-C reporting, complete supplier management, and SHA-256 encrypted ledger immutability.</p>
                    <div class="flex items-center text-sm font-bold text-amber-500 uppercase tracking-wide">
                        View Specification <svg class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                    </div>
                </a>

                <a href="/pos" class="group block solid-card rounded-xl p-8 md:p-10 reveal delay-100">
                    <div class="w-14 h-14 bg-zinc-900 border border-zinc-700/50 rounded flex items-center justify-center mb-8 group-hover:border-amber-600/50 transition-colors">
                        <svg class="w-6 h-6 text-zinc-300 group-hover:text-amber-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 1.189a3 3 0 01-.621 4.72m-13.5 8.65h3.75a.75.75 0 00.75-.75V13.5a.75.75 0 00-.75-.75H6.75a.75.75 0 00-.75.75v3.75c0 .415.336.75.75.75z"></path></svg>
                    </div>
                    <h3 class="font-serif text-3xl text-white mb-4">PRA POS</h3>
                    <p class="text-zinc-400 mb-8 leading-relaxed min-h-[100px]">Punjab Revenue Authority IMS v1.2 compliance. Built for high-volume restaurants and services with unified multi-branch menu syncing.</p>
                    <div class="flex items-center text-sm font-bold text-amber-500 uppercase tracking-wide">
                        View Specification <svg class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                    </div>
                </a>

                <a href="/fbr-pos-landing" class="group block solid-card rounded-xl p-8 md:p-10 reveal delay-200">
                    <div class="w-14 h-14 bg-zinc-900 border border-zinc-700/50 rounded flex items-center justify-center mb-8 group-hover:border-amber-600/50 transition-colors">
                        <svg class="w-6 h-6 text-zinc-300 group-hover:text-amber-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z"></path></svg>
                    </div>
                    <h3 class="font-serif text-3xl text-white mb-4">FBR POS</h3>
                    <p class="text-zinc-400 mb-8 leading-relaxed min-h-[100px]">Tier-1 Retail Backbone. Handles extreme transaction throughput with robust offline queueing and automated thermal receipt generation.</p>
                    <div class="flex items-center text-sm font-bold text-amber-500 uppercase tracking-wide">
                        View Specification <svg class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                    </div>
                </a>
            </div>
        </div>
    </section>

    <section class="py-24 border-t border-zinc-800 bg-zinc-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">
                <div class="reveal">
                    <h2 class="font-serif text-4xl md:text-5xl text-white tracking-tight mb-6">Engineered for the realities of local commerce.</h2>
                    <p class="text-lg text-zinc-400 mb-8 leading-relaxed">
                        We don't rely on perfect internet connections. TaxNest is built to absorb network failures, power outages, and high traffic spikes without dropping a single invoice.
                    </p>
                    <ul class="space-y-8">
                        <li class="flex items-start">
                            <div class="flex-shrink-0 w-8 h-8 rounded border border-amber-600/30 bg-amber-600/10 flex items-center justify-center mt-1">
                                <svg class="w-4 h-4 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>
                            </div>
                            <div class="ml-5">
                                <h4 class="text-lg font-bold text-white mb-2">Bulletproof Offline Sync</h4>
                                <p class="text-zinc-400 leading-relaxed">Continue billing during outages. The system queues fiscal invoices locally and synchronizes seamlessly when connectivity returns.</p>
                            </div>
                        </li>
                        <li class="flex items-start">
                            <div class="flex-shrink-0 w-8 h-8 rounded border border-amber-600/30 bg-amber-600/10 flex items-center justify-center mt-1">
                                <svg class="w-4 h-4 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>
                            </div>
                            <div class="ml-5">
                                <h4 class="text-lg font-bold text-white mb-2">Native Thermal Printing</h4>
                                <p class="text-zinc-400 leading-relaxed">Instant ESC/POS formatted receipts tailored exactly to FBR and PRA specifications with embedded verifiability QR codes.</p>
                            </div>
                        </li>
                        <li class="flex items-start">
                            <div class="flex-shrink-0 w-8 h-8 rounded border border-amber-600/30 bg-amber-600/10 flex items-center justify-center mt-1">
                                <svg class="w-4 h-4 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>
                            </div>
                            <div class="ml-5">
                                <h4 class="text-lg font-bold text-white mb-2">Centralized Multi-Branch</h4>
                                <p class="text-zinc-400 leading-relaxed">Manage pricing, taxation rules, and inventory universally across hundreds of stores from a single executive dashboard.</p>
                            </div>
                        </li>
                    </ul>
                </div>
                <div class="relative reveal delay-200 lg:pl-10">
                    <div class="absolute inset-0 bg-amber-600/5 blur-[80px] rounded-full"></div>
                    <div class="solid-card rounded-xl p-3 relative z-10 overflow-hidden bg-zinc-900 border-zinc-800">
                        <div class="bg-zinc-950 rounded-lg border border-zinc-800 p-6 shadow-inner">
                            <div class="flex items-center justify-between mb-6 border-b border-zinc-800 pb-4">
                                <div class="flex items-center gap-2">
                                    <div class="w-3 h-3 rounded-full bg-zinc-700"></div>
                                    <div class="w-3 h-3 rounded-full bg-zinc-700"></div>
                                    <div class="w-3 h-3 rounded-full bg-zinc-700"></div>
                                </div>
                                <div class="text-xs font-mono text-zinc-500 tracking-wider">SYSTEM_LOG</div>
                            </div>
                            <div class="font-mono text-sm space-y-4">
                                <div class="flex gap-4"><span class="text-zinc-600">01</span><p class="text-zinc-300"><span class="text-emerald-500">[2024-10-12 14:02]</span> INIT_FBR_PAYLOAD</p></div>
                                <div class="flex gap-4"><span class="text-zinc-600">02</span><p class="text-zinc-300"><span class="text-amber-500">[WARN]</span> NETWORK_LATENCY_DETECTED</p></div>
                                <div class="flex gap-4"><span class="text-zinc-600">03</span><p class="text-zinc-300"><span class="text-blue-400">[INFO]</span> SWITCHING_TO_OFFLINE_QUEUE</p></div>
                                <div class="flex gap-4"><span class="text-zinc-600">04</span><p class="text-zinc-500 pl-4">-> Local Ledger Updated</p></div>
                                <div class="flex gap-4"><span class="text-zinc-600">05</span><p class="text-zinc-500 pl-4">-> Receipt Printed (Offline Mode)</p></div>
                                <div class="flex gap-4 pt-2"><span class="text-zinc-600">06</span><p class="text-zinc-300"><span class="text-emerald-500">[2024-10-12 14:08]</span> CONNECTION_RESTORED</p></div>
                                <div class="flex gap-4"><span class="text-zinc-600">07</span><p class="text-zinc-300"><span class="text-emerald-500">[SUCCESS]</span> BATCH_SYNC_COMPLETE: 47</p></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <section class="py-24 border-t border-zinc-800 bg-zinc-900/30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16 reveal">
                <h2 class="font-serif text-3xl md:text-4xl text-white mb-4">Trusted by the industry.</h2>
                <p class="text-zinc-400">Over 500 businesses trust TaxNest to handle their compliance.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="solid-card p-8 rounded-xl reveal">
                    <p class="text-zinc-300 leading-relaxed mb-6">"TaxNest completely automated our FBR reporting. We no longer worry about manual entries or compliance audits at the end of the month. It just works."</p>
                    <div>
                        <p class="text-white font-bold">Usman Ali</p>
                        <p class="text-sm text-zinc-500">Director, Apex Retail</p>
                    </div>
                </div>
                <div class="solid-card p-8 rounded-xl reveal delay-100">
                    <p class="text-zinc-300 leading-relaxed mb-6">"The PRA POS integration is flawless. We run 12 branches and the centralized management saves us countless hours. Unmatched reliability."</p>
                    <div>
                        <p class="text-white font-bold">Saad Tariq</p>
                        <p class="text-sm text-zinc-500">Owner, Lahore Dining</p>
                    </div>
                </div>
                <div class="solid-card p-8 rounded-xl reveal delay-200">
                    <p class="text-zinc-300 leading-relaxed mb-6">"Offline sync is a lifesaver. Internet goes down, but our billing never stops. Highly recommend this for any serious pharmacy setup."</p>
                    <div>
                        <p class="text-white font-bold">Dr. Ayesha</p>
                        <p class="text-sm text-zinc-500">CEO, Care Pharmacies</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="border-y border-zinc-800 bg-zinc-950 relative overflow-hidden">
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_center,rgba(217,119,6,0.05)_0%,transparent_50%)]"></div>
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-28 text-center reveal relative z-10">
            <h2 class="font-serif text-4xl md:text-5xl text-white mb-6">Stop gambling with compliance.</h2>
            <p class="text-xl text-zinc-400 mb-10">Deploy the enterprise standard for Pakistani retail today.</p>
            <a href="/register" class="inline-flex items-center justify-center px-10 py-5 text-lg font-bold text-white bg-amber-600 hover:bg-amber-500 rounded transition-colors shadow-lg shadow-amber-900/20">
                Initialize Deployment
            </a>
        </div>
    </section>

    <footer class="bg-zinc-950 pt-20 pb-10 border-t border-zinc-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-12 mb-16">
                <div class="md:col-span-2">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-8 h-8 bg-zinc-900 border border-zinc-700 rounded flex items-center justify-center">
                            <svg class="w-4 h-4 text-amber-600" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                        </div>
                        <span class="text-xl font-bold tracking-tight text-white uppercase">TaxNest</span>
                    </div>
                    <p class="text-zinc-500 max-w-sm leading-relaxed">
                        The serious, battle-tested compliance backbone. Protecting businesses from penalties through uncompromising software architecture.
                    </p>
                </div>
                <div>
                    <h4 class="text-white font-bold mb-4 uppercase tracking-wider text-sm">Products</h4>
                    <ul class="space-y-3 text-sm">
                        <li><a href="/digital-invoice" class="text-zinc-400 hover:text-amber-500 transition-colors">Digital Invoice</a></li>
                        <li><a href="/pos" class="text-zinc-400 hover:text-amber-500 transition-colors">PRA POS System</a></li>
                        <li><a href="/fbr-pos-landing" class="text-zinc-400 hover:text-amber-500 transition-colors">FBR POS System</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white font-bold mb-4 uppercase tracking-wider text-sm">Portal</h4>
                    <ul class="space-y-3 text-sm">
                        <li><a href="/login" class="text-zinc-400 hover:text-amber-500 transition-colors">Client Login</a></li>
                        <li><a href="/register" class="text-zinc-400 hover:text-amber-500 transition-colors">New Deployment</a></li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-zinc-800 pt-8 flex flex-col md:flex-row items-center justify-between gap-4">
                <p class="text-zinc-600 text-sm">© {{ date('Y') }} TaxNest. All rights reserved. Strictly compliant.</p>
                <div class="flex items-center gap-6">
                    <span class="text-zinc-600 text-sm">Lahore, Pakistan</span>
                </div>
            </div>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (!('IntersectionObserver' in window)) {
                document.querySelectorAll('.reveal').forEach((el) => el.classList.add('active'));
                return;
            }
            const observerOptions = {
                root: null,
                rootMargin: '0px',
                threshold: 0.1
            };

            const observer = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('active');
                        observer.unobserve(entry.target);
                    }
                });
            }, observerOptions);

            document.querySelectorAll('.reveal').forEach((el) => {
                observer.observe(el);
            });
        });
    </script>
</body>
</html>