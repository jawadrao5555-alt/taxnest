<!DOCTYPE html>
<html lang="en" class="overflow-x-hidden">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="stylesheet" href="{{ asset('css/mobile.css?v=2.5') }}">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <title>TaxNest — Pakistan ka apna FBR & PRA Compliance Platform</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,500,600,700,800,900|inter:400,500,600,700|noto-nastaliq-urdu:400,600,700|jetbrains-mono:500,600,700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root {
            --ink: #1A1410;
            --ink-soft: #3B322B;
            --paper: #FAF6EE;
            --paper-2: #F1EADA;
            --paper-3: #E8DFC9;
            --pak-green: #01411C;
            --pak-green-2: #0F5132;
            --pak-green-3: #1F7A47;
            --maroon: #7B1F2B;
            --maroon-soft: #A0394A;
            --gold: #C9A227;
            --gold-2: #E7BF3B;
            --ochre: #B07A2F;
            --ocean: #1F3A5F;
        }
        html, body { background: var(--paper); color: var(--ink); }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            font-feature-settings: "ss01", "cv11";
            background-image:
                radial-gradient(rgba(26,20,16,0.045) 1px, transparent 1px),
                radial-gradient(rgba(26,20,16,0.025) 1px, transparent 1px);
            background-size: 32px 32px, 7px 7px;
            background-position: 0 0, 16px 16px;
        }
        .font-display { font-family: 'Fraunces', 'Times New Roman', serif; font-feature-settings: "ss01"; letter-spacing: -0.018em; }
        .font-urdu { font-family: 'Noto Nastaliq Urdu', serif; }
        .font-mono { font-family: 'JetBrains Mono', ui-monospace, monospace; }
        [x-cloak] { display: none !important; }
        .ink-border { border: 2px solid var(--ink); }
        .ink-border-thin { border: 1.5px solid var(--ink); }
        .ink-shadow { box-shadow: 4px 4px 0 var(--ink); }
        .ink-shadow-lg { box-shadow: 6px 6px 0 var(--ink); }
        .ink-shadow-sm { box-shadow: 3px 3px 0 var(--ink); }
        .gold-shadow { box-shadow: 4px 4px 0 var(--gold); }
        .green-shadow { box-shadow: 4px 4px 0 var(--pak-green); }
        .maroon-shadow { box-shadow: 4px 4px 0 var(--maroon); }
        .paper-card { background: var(--paper); }
        .paper-2 { background: var(--paper-2); }
        .paper-3 { background: var(--paper-3); }

        /* Hand-drawn squiggly underline */
        .scribble-under { position: relative; display: inline-block; }
        .scribble-under svg { position: absolute; left: -6px; right: -6px; bottom: -10px; width: calc(100% + 12px); height: 14px; }

        /* Tilted stamp */
        .stamp {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px;
            font-family: 'Fraunces', serif;
            font-weight: 700; font-size: 11px; letter-spacing: 0.14em; text-transform: uppercase;
            border: 2.5px solid currentColor; border-radius: 4px;
            transform: rotate(-3deg);
            background: rgba(255,255,255,0.4);
        }
        .stamp-tilt-right { transform: rotate(2.5deg); }
        .stamp-tilt-left { transform: rotate(-3.5deg); }
        .stamp::before, .stamp::after { content: '★'; opacity: 0.6; font-size: 10px; }

        /* Sticker with chunky shadow */
        .sticker {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 11px;
            border: 2px solid var(--ink);
            background: var(--paper);
            font-weight: 600; font-size: 11.5px;
            box-shadow: 2px 2px 0 var(--ink);
            border-radius: 999px;
        }

        /* Fade up reveal */
        .fade-up { opacity: 0; transform: translateY(20px); transition: opacity 0.65s ease, transform 0.65s ease; }
        .fade-up.visible { opacity: 1; transform: translateY(0); }

        /* Button: ink-on-paper chunky */
        .btn-ink {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 13px 22px;
            font-weight: 700; font-size: 14px;
            background: var(--ink); color: var(--paper);
            border: 2px solid var(--ink);
            box-shadow: 4px 4px 0 var(--gold);
            transition: transform 0.12s ease, box-shadow 0.12s ease;
        }
        .btn-ink:hover { transform: translate(-2px,-2px); box-shadow: 6px 6px 0 var(--gold); }
        .btn-ghost {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 13px 22px;
            font-weight: 700; font-size: 14px;
            background: var(--paper); color: var(--ink);
            border: 2px solid var(--ink);
            box-shadow: 4px 4px 0 var(--ink);
            transition: transform 0.12s ease, box-shadow 0.12s ease;
        }
        .btn-ghost:hover { transform: translate(-2px,-2px); box-shadow: 6px 6px 0 var(--ink); }
        .btn-green {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 11px 18px;
            font-weight: 700; font-size: 13px;
            background: var(--pak-green); color: var(--paper);
            border: 2px solid var(--ink);
            box-shadow: 3px 3px 0 var(--ink);
            transition: transform 0.12s ease, box-shadow 0.12s ease;
        }
        .btn-green:hover { transform: translate(-2px,-2px); box-shadow: 5px 5px 0 var(--ink); }
        .btn-maroon { background: var(--maroon); color: var(--paper); }
        .btn-ocean { background: var(--ocean); color: var(--paper); }

        /* Section divider line */
        .deco-line {
            background-image: repeating-linear-gradient(90deg, var(--ink) 0 8px, transparent 8px 16px);
            height: 2px;
            border: 0;
        }

        /* Paper edge — torn / receipt feel */
        .torn-bottom { mask-image: linear-gradient(180deg, #000 calc(100% - 10px), transparent); -webkit-mask-image: linear-gradient(180deg, #000 calc(100% - 10px), transparent); }

        /* Hero kicker rotated */
        .rotate-tag { transform: rotate(-1.5deg); display: inline-block; }

        /* Big quote mark */
        .quote-mark { font-family: 'Fraunces', serif; font-weight: 900; font-size: 96px; line-height: 0.6; color: var(--gold); }

        /* Drop cap */
        .drop-cap::first-letter {
            font-family: 'Fraunces', serif;
            font-weight: 900;
            font-size: 4.4em;
            float: left;
            line-height: 0.85;
            padding-right: 10px;
            padding-top: 6px;
            color: var(--pak-green);
        }

        /* FBR/PRA stamp circular */
        .ring-stamp {
            display: inline-flex; align-items: center; justify-content: center;
            width: 84px; height: 84px;
            border: 3px solid var(--maroon);
            color: var(--maroon);
            border-radius: 50%;
            font-family: 'Fraunces', serif;
            font-weight: 700; font-size: 10.5px; letter-spacing: 0.16em;
            text-align: center; line-height: 1.1;
            transform: rotate(-12deg);
            background: rgba(123,31,43,0.04);
            text-transform: uppercase;
        }
        .ring-stamp.green { border-color: var(--pak-green); color: var(--pak-green); background: rgba(1,65,28,0.04); transform: rotate(8deg); }

        /* Hover lift for ink-shadow cards */
        .lift-card { transition: transform 0.15s ease, box-shadow 0.15s ease; }
        .lift-card:hover { transform: translate(-3px,-3px); box-shadow: 7px 7px 0 var(--ink); }

        /* Marquee */
        @keyframes marquee { 0% { transform: translateX(0); } 100% { transform: translateX(-50%); } }
        .marquee-track { animation: marquee 38s linear infinite; }

        /* Section heading wrapper */
        .eyebrow {
            display: inline-flex; align-items: center; gap: 8px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11.5px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.22em;
            color: var(--maroon);
        }
        .eyebrow::before { content: ''; width: 28px; height: 2px; background: var(--maroon); }

        /* Numeric ticket-style table header */
        .ticket-row { border-bottom: 1.5px dashed rgba(26,20,16,0.18); }

        /* Hand-tag (price card corner) */
        .hand-tag {
            position: absolute; top: -14px; right: -14px;
            background: var(--gold); color: var(--ink);
            font-family: 'Fraunces', serif; font-weight: 800; font-size: 11px;
            padding: 6px 12px; letter-spacing: 0.1em; text-transform: uppercase;
            border: 2px solid var(--ink); box-shadow: 3px 3px 0 var(--ink);
            transform: rotate(8deg);
        }

        @media (max-width: 768px) {
            .drop-cap::first-letter { font-size: 3.4em; }
            .ring-stamp { width: 66px; height: 66px; font-size: 9px; }
        }
    </style>
</head>
<body class="antialiased scroll-smooth">

    {{-- ════════════════════════════════════════════════════════════════
         TOP RIBBON — running announcement bar (ink on green)
         ════════════════════════════════════════════════════════════════ --}}
    <div class="bg-[color:var(--pak-green)] text-[color:var(--paper)] overflow-hidden border-b-2 border-[color:var(--ink)]">
        <div class="py-2 whitespace-nowrap flex">
            <div class="marquee-track flex shrink-0 items-center gap-10 text-[12px] font-medium tracking-wide pr-10">
                @for ($i = 0; $i < 2; $i++)
                    <span class="flex items-center gap-2"><span class="text-[color:var(--gold-2)] text-[14px]">★</span> Pakistan ka apna FBR + PRA Compliance Platform</span>
                    <span class="opacity-50">/</span>
                    <span>14-din free trial — koi credit card nahi</span>
                    <span class="opacity-50">/</span>
                    <span class="flex items-center gap-2"><span class="text-[color:var(--gold-2)]">●</span> Live PRAL API v1.12 connected</span>
                    <span class="opacity-50">/</span>
                    <span>Lahore · Karachi · Islamabad · Faisalabad</span>
                    <span class="opacity-50">/</span>
                    <span class="font-urdu text-[15px]">ٹیکس نَسٹ — آپ کا اپنا ساتھی</span>
                    <span class="opacity-50">/</span>
                @endfor
            </div>
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════
         NAV — paper, chunky, no glassmorph
         ════════════════════════════════════════════════════════════════ --}}
    <nav class="sticky top-0 z-50 bg-[color:var(--paper)] border-b-2 border-[color:var(--ink)]">
        <div class="max-w-[1240px] mx-auto px-4 sm:px-6 lg:px-10">
            <div class="flex items-center justify-between h-[64px]">
                <a href="/" class="flex items-center gap-2.5 shrink-0">
                    <div class="w-9 h-9 bg-[color:var(--pak-green)] border-2 border-[color:var(--ink)] flex items-center justify-center" style="box-shadow: 2px 2px 0 var(--ink);">
                        <span class="font-display font-black text-[color:var(--gold-2)] text-[16px]">T</span>
                    </div>
                    <div class="flex flex-col leading-none">
                        <span class="font-display font-extrabold text-[19px] text-[color:var(--ink)] tracking-tight">TaxNest</span>
                        <span class="font-mono text-[9px] text-[color:var(--maroon)] tracking-[0.2em] mt-0.5">EST · KARACHI</span>
                    </div>
                </a>

                <div class="hidden md:flex items-center gap-1 text-[13.5px] font-medium">
                    <a href="#products" class="px-3 py-2 hover:text-[color:var(--maroon)] transition">Products</a>
                    <a href="#features" class="px-3 py-2 hover:text-[color:var(--maroon)] transition">Features</a>
                    <a href="#pricing" class="px-3 py-2 hover:text-[color:var(--maroon)] transition">Pricing</a>
                    <a href="#faq" class="px-3 py-2 hover:text-[color:var(--maroon)] transition">FAQ</a>
                    <a href="#contact" class="px-3 py-2 hover:text-[color:var(--maroon)] transition">Contact</a>
                </div>

                <div class="flex items-center gap-2">
                    <a href="/digital-invoice" class="hidden sm:inline-flex items-center text-[12.5px] font-semibold text-[color:var(--ink)] hover:underline underline-offset-4 decoration-[color:var(--gold)] decoration-2">Sign in</a>
                    <a href="#products" class="btn-green !py-2 !px-3.5 !text-[12px]">
                        Free Trial
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    {{-- ════════════════════════════════════════════════════════════════
         HERO — asymmetric editorial, paper, no gradient soup
         ════════════════════════════════════════════════════════════════ --}}
    <section class="relative pt-14 pb-20 sm:pt-20 sm:pb-28 overflow-hidden">
        <div class="absolute inset-0 pointer-events-none opacity-[0.5]" aria-hidden="true">
            <div class="absolute top-20 -left-10 w-40 h-40 rounded-full" style="background: radial-gradient(circle, var(--gold) 0%, transparent 65%); opacity: 0.18;"></div>
            <div class="absolute bottom-10 right-0 w-56 h-56 rounded-full" style="background: radial-gradient(circle, var(--pak-green) 0%, transparent 65%); opacity: 0.12;"></div>
        </div>

        <div class="max-w-[1240px] mx-auto px-4 sm:px-6 lg:px-10 relative">
            <div class="grid lg:grid-cols-12 gap-10 lg:gap-14 items-start">

                {{-- Left: editorial column --}}
                <div class="lg:col-span-7">
                    <div class="rotate-tag mb-7">
                        <span class="sticker">
                            <span class="w-2 h-2 rounded-full bg-[color:var(--pak-green)]"></span>
                            <span class="font-mono text-[10.5px] tracking-[0.16em]">FBR · PRAL · PRA · LIVE</span>
                        </span>
                    </div>

                    <h1 class="font-display text-[40px] sm:text-[60px] lg:text-[74px] font-black text-[color:var(--ink)] leading-[0.96]">
                        Pakistan ka
                        <span class="scribble-under text-[color:var(--maroon)]">
                            apna
                            <svg viewBox="0 0 200 14" preserveAspectRatio="none" aria-hidden="true">
                                <path d="M2 8 Q 30 2, 60 7 T 120 6 T 198 8" fill="none" stroke="#C9A227" stroke-width="3.5" stroke-linecap="round"/>
                            </svg>
                        </span>
                        <br>
                        tax compliance,
                        <em class="not-italic text-[color:var(--pak-green)]">imandaarana</em> banaya.
                    </h1>

                    <p class="font-urdu text-[20px] sm:text-[24px] text-[color:var(--ink-soft)] mt-5 leading-[1.7] tracking-wide" dir="rtl">
                        ایف بی آر، پی آر اے، اور پی او ایس — ایک ہی پلیٹ فارم پر، آپ کے کاروبار کے لیے۔
                    </p>

                    <p class="text-[16px] sm:text-[17px] text-[color:var(--ink-soft)] mt-6 leading-[1.75] max-w-[640px] drop-cap">
                        Hum chaar saal se Karachi, Lahore aur Faisalabad ke 500+ businesses ke saath kaam kar rahe hain — chhote dukandaaron se le kar textile exporters tak. TaxNest sirf software nahi, ye aap ki team ka ek aur member hai jo FBR ke saath roz baat karta hai taa-keh aap ka kaaroobar saaf, paak aur compliant rahe.
                    </p>

                    <div class="mt-9 flex flex-wrap items-center gap-3">
                        <a href="#products" class="btn-ink">
                            14-din ka free trial shuru karein
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </a>
                        <a href="#contact" class="btn-ghost">
                            Hum se baat karein
                        </a>
                    </div>

                    <div class="mt-8 flex flex-wrap items-center gap-x-5 gap-y-2 text-[12px] text-[color:var(--ink-soft)]">
                        <span class="flex items-center gap-1.5"><svg class="w-3.5 h-3.5 text-[color:var(--pak-green)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg> Koi credit card zaroori nahi</span>
                        <span class="flex items-center gap-1.5"><svg class="w-3.5 h-3.5 text-[color:var(--pak-green)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg> Jab chahein cancel karein</span>
                        <span class="flex items-center gap-1.5"><svg class="w-3.5 h-3.5 text-[color:var(--pak-green)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg> Urdu + English support</span>
                    </div>
                </div>

                {{-- Right: hand-collaged preview card with stamps --}}
                <div class="lg:col-span-5 relative">
                    <div class="absolute -top-6 -left-6 z-20 hidden sm:block">
                        <div class="ring-stamp">FBR<br>Approved<br>★ 2026 ★</div>
                    </div>
                    <div class="absolute -bottom-8 -right-4 z-20 hidden sm:block">
                        <div class="ring-stamp green">PRA<br>Verified<br>IMS v1.2</div>
                    </div>

                    <div class="relative ink-border bg-[color:var(--paper)] ink-shadow-lg p-1.5">
                        <div class="paper-2 ink-border-thin">
                            {{-- Receipt header --}}
                            <div class="px-5 pt-5 pb-3 border-b-2 border-dashed border-[color:var(--ink)]">
                                <div class="flex items-center justify-between text-[10.5px] font-mono tracking-widest text-[color:var(--ink-soft)]">
                                    <span>INVOICE · #DI-2026-04891</span>
                                    <span class="text-[color:var(--pak-green)]">● LIVE</span>
                                </div>
                                <div class="mt-3 flex items-end justify-between gap-3">
                                    <div>
                                        <p class="font-display font-black text-[22px] text-[color:var(--ink)] leading-tight">Karachi Textiles Co.</p>
                                        <p class="text-[11px] text-[color:var(--ink-soft)] mt-0.5">NTN 3620291786117 · STRN 1234567</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-[10px] font-mono uppercase tracking-wider text-[color:var(--maroon)]">FBR IRN</p>
                                        <p class="font-mono text-[11px] text-[color:var(--ink)] font-bold">7F3A·91C2·44</p>
                                    </div>
                                </div>
                            </div>

                            {{-- Line items --}}
                            <div class="px-5 py-4 space-y-2.5">
                                @foreach([
                                    ['Cotton Yarn 30/1 (HS 5205.4200)', '85 kg', '78,200'],
                                    ['Polyester Blend Fabric', '120 m', '36,000'],
                                    ['Dyeing & Finishing', '1 lot', '12,500'],
                                ] as $line)
                                <div class="flex items-baseline justify-between gap-3 text-[13px] ticket-row pb-2.5">
                                    <div class="flex-1">
                                        <p class="font-medium text-[color:var(--ink)]">{{ $line[0] }}</p>
                                        <p class="text-[10.5px] text-[color:var(--ink-soft)] font-mono">{{ $line[1] }}</p>
                                    </div>
                                    <span class="font-mono font-semibold text-[color:var(--ink)]">Rs {{ $line[2] }}</span>
                                </div>
                                @endforeach
                            </div>

                            {{-- Total --}}
                            <div class="px-5 py-3 bg-[color:var(--paper-3)] border-t-2 border-[color:var(--ink)] flex items-center justify-between">
                                <span class="font-display font-bold text-[14px]">Total + 17% GST</span>
                                <span class="font-display font-black text-[20px] text-[color:var(--pak-green)]">Rs 148,239</span>
                            </div>

                            {{-- Status row --}}
                            <div class="px-5 py-3 flex items-center justify-between text-[11px] border-t-2 border-dashed border-[color:var(--ink)]">
                                <span class="flex items-center gap-1.5 text-[color:var(--pak-green)] font-mono font-bold">
                                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                    SUBMITTED TO FBR · 2.4s
                                </span>
                                <span class="font-mono text-[color:var(--ink-soft)]">19 May 2026</span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 text-center">
                        <p class="font-display italic text-[13px] text-[color:var(--ink-soft)]">— Live sample from a real customer's dashboard —</p>
                    </div>
                </div>
            </div>

            {{-- Stat strip --}}
            <div id="heroStats" class="mt-16 grid grid-cols-2 lg:grid-cols-4 gap-4">
                @php
                $invoiceTarget = isset($stats['total_invoices']) && $stats['total_invoices'] > 0 ? $stats['total_invoices'] : 50000;
                $compTarget = isset($stats['total_companies']) && $stats['total_companies'] > 0 ? $stats['total_companies'] : 500;
                $statBlocks = [
                    ['99.9', '%',   'Uptime',        'Saal bhar chalta hai',         'var(--pak-green)'],
                    [$invoiceTarget, '+', 'Invoices', 'FBR/PRA ko submit ho chuke',  'var(--maroon)'],
                    [$compTarget,    '+', 'Companies', 'Pakistan bhar mein',           'var(--ocean)'],
                    ['3',             '',  'Products', 'Ek hi platform par',           'var(--ochre)'],
                ];
                @endphp
                @foreach($statBlocks as $idx => $s)
                <div class="paper-card ink-border p-5 lift-card ink-shadow-sm relative">
                    <div class="absolute top-2 right-3 font-mono text-[9.5px] text-[color:var(--ink-soft)] tracking-widest">0{{ $idx + 1 }}</div>
                    <p class="font-display font-black text-[36px] sm:text-[42px] leading-none" style="color: {{ $s[4] }};">
                        <span class="counter-val" data-target="{{ $s[0] }}" data-decimal="{{ str_contains($s[0],'.') ? 1 : 0 }}" data-suffix="{{ $s[1] }}">0</span>
                    </p>
                    <p class="font-display font-bold text-[14px] mt-2 text-[color:var(--ink)]">{{ $s[2] }}</p>
                    <p class="text-[11.5px] text-[color:var(--ink-soft)] mt-0.5">{{ $s[3] }}</p>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ════════════════════════════════════════════════════════════════
         TRUST RIBBON — chunky tags
         ════════════════════════════════════════════════════════════════ --}}
    <div class="py-7 border-y-2 border-[color:var(--ink)] bg-[color:var(--paper-2)]">
        <div class="max-w-[1240px] mx-auto px-4 sm:px-6 lg:px-10">
            <div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-3">
                <span class="eyebrow !text-[color:var(--ink-soft)] mr-3">Verified by</span>
                <span class="sticker"><span class="w-1.5 h-1.5 rounded-full bg-[color:var(--pak-green)]"></span> FBR PRAL API v1.12</span>
                <span class="sticker"><span class="w-1.5 h-1.5 rounded-full bg-[color:var(--maroon)]"></span> PRA IMS API v1.2</span>
                <span class="sticker"><span class="w-1.5 h-1.5 rounded-full bg-[color:var(--ocean)]"></span> SHA-256 audit logs</span>
                <span class="sticker"><span class="w-1.5 h-1.5 rounded-full bg-[color:var(--ochre)]"></span> Real-time sync</span>
                <span class="sticker"><span class="w-1.5 h-1.5 rounded-full bg-[color:var(--pak-green)]"></span> PWA · offline-ready</span>
            </div>
        </div>
    </div>

    {{-- ════════════════════════════════════════════════════════════════
         TESTIMONIALS — editorial paper quote cards
         ════════════════════════════════════════════════════════════════ --}}
    <section class="py-20 lg:py-24">
        <div class="max-w-[1240px] mx-auto px-4 sm:px-6 lg:px-10">
            <div class="max-w-2xl mb-12 fade-up">
                <span class="eyebrow">Yeh hain humare log</span>
                <h2 class="font-display text-[36px] sm:text-[46px] font-black leading-[1.02] text-[color:var(--ink)] mt-4">
                    Pakistan bhar ke kaarobaari humein
                    <span class="text-[color:var(--maroon)] italic">apna kehte hain.</span>
                </h2>
            </div>

            @php
            $testimonials = [
                ['Pehle hum FBR par manually file karte the — har mahine 15 ghante zaaya hote the. TaxNest ke baad sab kuch chand minutes mein ho jata hai. HS Intelligence engine to commaal hi hai.', 'Ahmed Khalid', 'CEO, Textile Exports Ltd, Faisalabad', 'AK', 'var(--pak-green)'],
                ['NestPOS hamare boutique ka rang badal diya hai. PRA compliance ek dard tha — ab automatic hai. Cashier sirf bill banata hai, baaki TaxNest sambhal leta hai.', 'Fatima Sajjad', 'Owner, Fashion Hub POS, Lahore', 'FS', 'var(--maroon)'],
                ['Hamari electronics shop mein FBR POS lagaye hue 8 mahine ho gaye. Ek bhi invoice fail nahi hua. Accountant sahab ab khush hain, ye apne aap mein mojza hai.', 'Rahim Mehmood', 'Director, Electronics Store, Karachi', 'RM', 'var(--ocean)'],
            ];
            @endphp

            <div class="grid md:grid-cols-3 gap-6 fade-up">
                @foreach($testimonials as $idx => $t)
                <article class="relative paper-card ink-border p-7 lift-card ink-shadow {{ $idx % 2 === 1 ? 'md:mt-8' : '' }}">
                    <span class="quote-mark absolute -top-2 left-5" aria-hidden="true">"</span>
                    <p class="font-display text-[17px] leading-[1.55] text-[color:var(--ink)] mt-7 mb-6">{{ $t[0] }}</p>
                    <div class="flex items-center gap-3 pt-4 border-t-2 border-dashed border-[color:var(--ink)]">
                        <div class="w-11 h-11 flex items-center justify-center font-display font-black text-[15px] text-[color:var(--paper)] border-2 border-[color:var(--ink)]" style="background: {{ $t[4] }};">{{ $t[3] }}</div>
                        <div>
                            <p class="font-bold text-[14px] text-[color:var(--ink)]">{{ $t[1] }}</p>
                            <p class="text-[11.5px] text-[color:var(--ink-soft)]">{{ $t[2] }}</p>
                        </div>
                    </div>
                </article>
                @endforeach
            </div>
        </div>
    </section>

    <hr class="deco-line max-w-[1240px] mx-auto">

    {{-- ════════════════════════════════════════════════════════════════
         HOW IT WORKS — three numbered cards, slightly off-grid
         ════════════════════════════════════════════════════════════════ --}}
    <section class="py-20 lg:py-24 paper-2">
        <div class="max-w-[1240px] mx-auto px-4 sm:px-6 lg:px-10">
            <div class="text-center max-w-2xl mx-auto mb-14 fade-up">
                <span class="eyebrow !justify-center" style="display:inline-flex;">Bas teen qadam</span>
                <h2 class="font-display text-[36px] sm:text-[46px] font-black mt-4 leading-[1.02]">Kaise shuru karein?</h2>
                <p class="mt-4 text-[16px] text-[color:var(--ink-soft)] leading-relaxed">Account banayein, FBR/PRA jod lein, invoice banana shuru — sab kuch 10 minute mein.</p>
            </div>

            <div class="grid md:grid-cols-3 gap-8 fade-up">
                @php $steps = [
                    ['01', 'Account banayein', 'Sirf 30 second mein sign up. Apna pasandeeda product chunein — Digital Invoice, PRA POS, ya FBR POS. Pehle 14 din bilkul free.', 'var(--pak-green)'],
                    ['02', 'FBR ya PRA se connect karein', 'Apna NTN daalein, sandbox token connect karein, business profile complete karein. 5 minute se zyada nahi lagega.', 'var(--maroon)'],
                    ['03', 'Bill banana shuru', 'Pehla invoice banayein. Real-time mein FBR ya PRA ko submit ho jayega. Bas — aap compliant hain.', 'var(--ocean)'],
                ]; @endphp
                @foreach($steps as $i => $step)
                <div class="paper-card ink-border p-7 lift-card ink-shadow {{ $i === 1 ? 'md:mt-10' : '' }} {{ $i === 2 ? 'md:mt-5' : '' }} relative">
                    <div class="absolute -top-5 -left-3 px-3 py-1 ink-border font-display font-black text-[14px] bg-[color:var(--gold-2)]" style="box-shadow: 2px 2px 0 var(--ink); transform: rotate(-3deg);">
                        STEP {{ $step[0] }}
                    </div>
                    <p class="font-mono text-[40px] font-bold opacity-20 mt-1" style="color: {{ $step[3] }};">{{ $step[0] }}</p>
                    <h3 class="font-display font-black text-[22px] mt-2 leading-tight">{{ $step[1] }}</h3>
                    <p class="text-[14px] text-[color:var(--ink-soft)] mt-3 leading-relaxed">{{ $step[2] }}</p>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ════════════════════════════════════════════════════════════════
         PRODUCTS — three offset cards with rotating badges
         ════════════════════════════════════════════════════════════════ --}}
    <section id="products" class="py-20 lg:py-28">
        <div class="max-w-[1240px] mx-auto px-4 sm:px-6 lg:px-10">
            <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-6 mb-14 fade-up">
                <div class="max-w-xl">
                    <span class="eyebrow">Hamari teen products</span>
                    <h2 class="font-display text-[36px] sm:text-[46px] font-black mt-4 leading-[1.02]">
                        Ek platform —
                        <span class="text-[color:var(--maroon)]">teen alag</span> solutions.
                    </h2>
                </div>
                <p class="text-[15px] text-[color:var(--ink-soft)] leading-relaxed max-w-md">Teeno products 100% isolated hain — alag database, alag login, alag dashboard. Aap jo chahein chunein, ya teeno ek saath use karein.</p>
            </div>

            <div class="grid lg:grid-cols-3 gap-7 fade-up">
                @php $products = [
                    [
                        'href' => '/digital-invoice', 'badge' => 'Enterprise', 'badge_bg' => 'var(--pak-green)',
                        'title' => 'Digital Invoice', 'sub' => 'FBR Compliance System',
                        'desc' => 'Enterprise-grade FBR digital invoicing PRAL API v1.12 ke saath. Real-time submission, compliance scoring, aur HS Intelligence AI built-in.',
                        'features' => ['FBR API v1.12', 'HS Intelligence AI', 'Risk Detection', 'PDF + QR codes', 'MIS Analytics', 'Multi-Branch'],
                        'accent' => 'var(--pak-green)', 'mt' => '',
                        'tag' => 'For FBR filers',
                    ],
                    [
                        'href' => '/pos', 'badge' => 'Most Popular', 'badge_bg' => 'var(--maroon)',
                        'title' => 'NestPOS', 'sub' => 'PRA Point-of-Sale',
                        'desc' => 'Punjab Revenue Authority ke liye complete POS solution. Thermal printer, offline billing, auto-sync — sab built-in.',
                        'features' => ['PRA IMS v1.2', 'Thermal receipts', 'Offline + sync', 'Restaurant + retail', 'Inventory engine', 'Keyboard-only flow'],
                        'accent' => 'var(--maroon)', 'mt' => 'lg:mt-10',
                        'tag' => 'For dukandaar & retail',
                    ],
                    [
                        'href' => '/fbr-pos-landing', 'badge' => 'Naya', 'badge_bg' => 'var(--ocean)',
                        'title' => 'FBR POS', 'sub' => 'FBR-Integrated POS',
                        'desc' => 'FBR direct API ke saath POS billing. Automated tax compliance, retry system, aur comprehensive reporting — chhote budget mein.',
                        'features' => ['Direct FBR API', 'Auto tax calc', 'Retry system', 'Tax reports', 'Confidential PIN', 'Smart scanning'],
                        'accent' => 'var(--ocean)', 'mt' => 'lg:mt-5',
                        'tag' => 'For FBR POS shops',
                    ],
                ]; @endphp

                @foreach($products as $idx => $p)
                <article class="relative paper-card ink-border lift-card ink-shadow-lg {{ $p['mt'] }}">
                    <div class="absolute -top-3 -right-3 z-10">
                        <span class="inline-block px-3 py-1.5 ink-border font-display font-black text-[11px] uppercase tracking-widest text-[color:var(--paper)]" style="background: {{ $p['badge_bg'] }}; box-shadow: 2.5px 2.5px 0 var(--ink); transform: rotate({{ $idx === 1 ? '4' : ($idx === 0 ? '-3' : '5') }}deg);">{{ $p['badge'] }}</span>
                    </div>

                    <div class="px-6 py-5 border-b-2 border-[color:var(--ink)]" style="background: {{ $p['accent'] }};">
                        <p class="font-mono text-[10.5px] uppercase tracking-[0.2em] text-[color:var(--paper)] opacity-80">{{ $p['tag'] }}</p>
                        <h3 class="font-display font-black text-[28px] text-[color:var(--paper)] leading-tight mt-1">{{ $p['title'] }}</h3>
                        <p class="font-display italic text-[14px] text-[color:var(--paper)] opacity-90 mt-0.5">{{ $p['sub'] }}</p>
                    </div>

                    <div class="p-6">
                        <p class="text-[14px] text-[color:var(--ink-soft)] leading-relaxed mb-5">{{ $p['desc'] }}</p>

                        <ul class="grid grid-cols-2 gap-2 mb-6">
                            @foreach($p['features'] as $f)
                            <li class="flex items-center text-[12.5px] text-[color:var(--ink)]">
                                <svg class="w-3.5 h-3.5 mr-1.5 shrink-0" style="color: {{ $p['accent'] }};" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                {{ $f }}
                            </li>
                            @endforeach
                        </ul>

                        <a href="{{ $p['href'] }}" class="inline-flex items-center gap-2 w-full justify-center py-3 ink-border font-display font-bold text-[14px] text-[color:var(--paper)]" style="background: {{ $p['accent'] }}; box-shadow: 3px 3px 0 var(--ink);">
                            Explore {{ $p['title'] }}
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </a>
                    </div>
                </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ════════════════════════════════════════════════════════════════
         FEATURES — capabilities band on green
         ════════════════════════════════════════════════════════════════ --}}
    <section id="features" class="py-20 lg:py-24 border-y-2 border-[color:var(--ink)]" style="background: var(--pak-green); color: var(--paper);">
        <div class="max-w-[1240px] mx-auto px-4 sm:px-6 lg:px-10">
            <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-6 mb-12 fade-up">
                <div class="max-w-xl">
                    <span class="eyebrow !text-[color:var(--gold-2)]">Platform capabilities</span>
                    <h2 class="font-display text-[36px] sm:text-[46px] font-black mt-4 leading-[1.02] text-[color:var(--paper)]">
                        Enterprise-grade engine,
                        <span class="text-[color:var(--gold-2)] italic">desi</span> simplicity.
                    </h2>
                </div>
                <p class="text-[15px] opacity-85 leading-relaxed max-w-md">Yeh saari cheezein har plan mein shaamil hain — chhote plan se enterprise tak. Koi hidden charge nahi.</p>
            </div>

            @php $caps = [
                ['Real-time PRAL API submission', 'FBR ko bill foran chala jata hai, 2 second se kam mein.'],
                ['PRA + FBR POS fiscal reporting', 'Daily, monthly, aur custom date range mein automatic reports.'],
                ['Offline billing + auto sync', 'Internet band ho? Koi mas\'la nahi. Wapas aate hi sab sync ho jayega.'],
                ['Inventory + reporting engine', 'Stock movement, COGS, low-stock alerts — sab automatic.'],
                ['Compliance scoring + risk alerts', 'Aap ka FBR risk score live track hota hai, alerts milte hain.'],
                ['SHA-256 immutable audit logs', 'Har action tamper-proof hash ke saath log hota hai.'],
                ['Multi-branch invoicing', 'Ek company, multiple branches — har branch ka apna numbering.'],
                ['Thermal receipt printing', '58mm aur 80mm thermal printer fully support karte hain.'],
            ]; @endphp

            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-x-8 gap-y-7 fade-up">
                @foreach($caps as $i => $c)
                <div class="relative">
                    <p class="font-mono text-[11px] text-[color:var(--gold-2)] tracking-[0.18em] mb-1.5">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }} —</p>
                    <h3 class="font-display font-bold text-[16px] leading-snug">{{ $c[0] }}</h3>
                    <p class="text-[12.5px] opacity-75 mt-2 leading-relaxed">{{ $c[1] }}</p>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ════════════════════════════════════════════════════════════════
         COMPARISON — paper table, hand-stamped checks
         ════════════════════════════════════════════════════════════════ --}}
    <section class="py-20 lg:py-24">
        <div class="max-w-[1100px] mx-auto px-4 sm:px-6 lg:px-10">
            <div class="text-center max-w-xl mx-auto mb-12 fade-up">
                <span class="eyebrow !justify-center" style="display:inline-flex;">Aap ka faisla aasaan</span>
                <h2 class="font-display text-[36px] sm:text-[44px] font-black mt-4 leading-[1.02]">
                    TaxNest vs <span class="line-through opacity-50 decoration-[color:var(--maroon)] decoration-[3px]">purana software</span>
                </h2>
                <p class="mt-4 text-[15px] text-[color:var(--ink-soft)]">Generic accounting tools aur TaxNest mein bunyaadi farq dekhein.</p>
            </div>

            <div class="paper-card ink-border ink-shadow overflow-hidden fade-up">
                <table class="w-full">
                    <thead>
                        <tr style="background: var(--ink); color: var(--paper);">
                            <th class="text-left py-4 px-5 font-display font-bold text-[14px]">Capability</th>
                            <th class="text-center py-4 px-5 font-display font-bold text-[14px] w-[130px]" style="color: var(--gold-2);">TaxNest</th>
                            <th class="text-center py-4 px-5 font-display font-bold text-[14px] w-[130px] opacity-70">Doosre</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                        $comparisons = [
                            ['Direct FBR PRAL API v1.12', true, false],
                            ['PRA IMS API v1.2 (POS)', true, false],
                            ['Synchronous Real-time Submission', true, false],
                            ['HS Intelligence Engine (AI)', true, false],
                            ['Risk Detection & Compliance Scoring', true, false],
                            ['6-Phase Idempotency Shield', true, false],
                            ['Auto-Recovery for Stuck Invoices', true, false],
                            ['3rd Schedule Goods Support', true, false],
                            ['6 Login Methods (Email/CNIC/NTN)', true, false],
                            ['SHA-256 Immutable Audit Logs', true, false],
                            ['Multi-Branch + Company Isolation', true, 'partial'],
                            ['FBR + PRA Token Health Monitor', true, false],
                            ['PWA + Keyboard Shortcuts', true, false],
                            ['FBR POS Direct API Submission', true, false],
                            ['DI + PRA POS + FBR POS Isolation', true, false],
                        ];
                        @endphp
                        @foreach($comparisons as $i => $c)
                        <tr class="{{ $i % 2 === 0 ? 'paper-card' : 'paper-2' }} border-b border-dashed border-[color:var(--ink)]" style="border-color: rgba(26,20,16,0.18);">
                            <td class="py-3 px-5 text-[13.5px] font-medium text-[color:var(--ink)]">{{ $c[0] }}</td>
                            <td class="py-3 px-5 text-center">
                                <span class="inline-flex items-center justify-center w-7 h-7 ink-border" style="background: var(--pak-green); box-shadow: 1.5px 1.5px 0 var(--ink);">
                                    <svg class="w-3.5 h-3.5 text-[color:var(--paper)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                </span>
                            </td>
                            <td class="py-3 px-5 text-center">
                                @if($c[2] === 'partial')
                                    <span class="font-mono text-[10.5px] font-bold uppercase px-2 py-1 ink-border-thin text-[color:var(--ochre)]" style="background: rgba(176,122,47,0.12);">Limited</span>
                                @else
                                    <span class="font-mono text-[18px] font-bold text-[color:var(--maroon)] opacity-60">—</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- ════════════════════════════════════════════════════════════════
         PRICING — 3 hand-tagged cards
         ════════════════════════════════════════════════════════════════ --}}
    <section id="pricing" class="py-20 lg:py-24 paper-2 border-y-2 border-[color:var(--ink)]">
        <div class="max-w-[1240px] mx-auto px-4 sm:px-6 lg:px-10">
            <div class="text-center max-w-2xl mx-auto mb-14 fade-up">
                <span class="eyebrow !justify-center" style="display:inline-flex;">Pricing</span>
                <h2 class="font-display text-[36px] sm:text-[46px] font-black mt-4 leading-[1.02]">Saadi, saaf, koi chhupi baat nahi.</h2>
                <p class="mt-4 text-[16px] text-[color:var(--ink-soft)] leading-relaxed">Har product ka apna plan hai. Apna pasandeeda chunein, 14 din free trial use karein, aur pasand aaye to continue karein.</p>
            </div>

            <div class="grid md:grid-cols-3 gap-7 max-w-5xl mx-auto fade-up">
                @php $priceCards = [
                    [
                        'href' => '/digital-invoice#pricing', 'title' => 'Digital Invoice', 'sub' => 'FBR-compliant invoicing',
                        'desc' => 'Multiple billing cycles (Monthly, Quarterly, Semi-Annual, Annual) with volume discounts up to 6%.',
                        'cta' => 'DI plans dekhein', 'accent' => 'var(--pak-green)', 'tag' => 'Starts free',
                    ],
                    [
                        'href' => '/pos#pricing', 'title' => 'NestPOS', 'sub' => 'PRA-integrated POS',
                        'desc' => 'Annual billing only with 6% discount baked in. Restaurant + retail variants included.',
                        'cta' => 'POS plans dekhein', 'accent' => 'var(--maroon)', 'tag' => 'Pasandeeda',
                    ],
                    [
                        'href' => '/fbr-pos-landing#pricing', 'title' => 'FBR POS', 'sub' => 'FBR-integrated POS',
                        'desc' => 'Low-budget billing cycles with direct FBR API submission and automated tax compliance.',
                        'cta' => 'FBR POS plans', 'accent' => 'var(--ocean)', 'tag' => 'Budget friendly',
                    ],
                ]; @endphp

                @foreach($priceCards as $i => $pc)
                <div class="relative paper-card ink-border ink-shadow-lg lift-card p-7 {{ $i === 1 ? 'md:-mt-4' : '' }}">
                    @if($i === 1)<div class="hand-tag">{{ $pc['tag'] }}</div>@endif
                    <p class="font-mono text-[10.5px] uppercase tracking-[0.2em]" style="color: {{ $pc['accent'] }};">{{ $pc['sub'] }}</p>
                    <h3 class="font-display font-black text-[26px] mt-1.5 text-[color:var(--ink)]">{{ $pc['title'] }}</h3>
                    <p class="text-[13.5px] text-[color:var(--ink-soft)] mt-3 leading-relaxed mb-7 min-h-[60px]">{{ $pc['desc'] }}</p>
                    <a href="{{ $pc['href'] }}" class="w-full inline-flex items-center justify-center gap-2 py-3 font-display font-bold text-[14px] ink-border text-[color:var(--paper)]" style="background: {{ $pc['accent'] }}; box-shadow: 3px 3px 0 var(--ink);">
                        {{ $pc['cta'] }}
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </a>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ════════════════════════════════════════════════════════════════
         FAQ — paper accordion
         ════════════════════════════════════════════════════════════════ --}}
    <section id="faq" class="py-20 lg:py-24">
        <div class="max-w-[920px] mx-auto px-4 sm:px-6 lg:px-10">
            <div class="text-center mb-12 fade-up">
                <span class="eyebrow !justify-center" style="display:inline-flex;">FAQ</span>
                <h2 class="font-display text-[36px] sm:text-[46px] font-black mt-4 leading-[1.02]">Aap ke sawaal —
                    <span class="italic text-[color:var(--maroon)]">hamare jawaab.</span>
                </h2>
            </div>

            @php
            $faqs = [
                ['TaxNest kya hai?', 'TaxNest Pakistan ka sab se advanced tax compliance platform hai jis mein <strong>3 alag products</strong> hain — <strong>Digital Invoice</strong> FBR compliance ke liye (Federal Board of Revenue), <strong>NestPOS</strong> PRA compliance ke liye (Punjab Revenue Authority), aur <strong>FBR POS</strong> jo FBR-integrated point of sale billing ke liye hai. Teeno products bilkul alag-alag database, login aur dashboard rakhte hain.'],
                ['Digital Invoice, NestPOS, aur FBR POS mein farq kya hai?', '<strong>Digital Invoice</strong> un businesses ke liye hai jo FBR ko PRAL API v1.12 ke zariye invoices submit karte hain. HS Intelligence, compliance scoring, risk detection, aur enterprise analytics shaamil hain.<br><br><strong>NestPOS</strong> retail/service businesses ke liye PRA fiscal device integration ke saath POS hai (PRAL IMS API v1.2). Thermal receipt printing, multi-terminal support, aur real-time tax calculations.<br><br><strong>FBR POS</strong> direct FBR API submission ke saath POS hai — FBR-compliant POS billing jis mein automated tax calculation aur retry system hai.'],
                ['Kya teeno products ka data alag hai?', 'Bilkul, 100%. Digital Invoice, NestPOS, aur FBR POS — har ek ka apna database, apna login page, apna dashboard, aur apne user accounts hain. Teeno systems ke darmiyan zero cross-contamination hai.'],
                ['Free trial milta hai?', 'Ji haan! Teeno products mein 14-din free trial milta hai. Koi credit card zaroori nahi. Trial ke daur mein saari features tak full access hota hai, 20 invoices/transactions tak.'],
                ['FBR/PRA compliance kaise kaam karta hai?', '<strong>FBR (Digital Invoice):</strong> PRAL API v1.12 ka real-time synchronous submission. Invoices validate hote hain, compliance ke liye score milte hain, aur HS codes, tax rates aur QR codes ke saath submit hote hain.<br><br><strong>NestPOS:</strong> PRAL IMS API v1.2 fiscal device integration. Har transaction fiscalize ho kar PRA fiscal invoice number + QR code paata hai.<br><br><strong>FBR POS:</strong> Direct FBR API real-time POS invoice submission ke saath automated tax compliance aur retry system.'],
                ['Security ka kya nizaam hai?', 'TaxNest SHA-256 encrypted immutable audit logs use karta hai, role-based access control, company isolation middleware, 6-phase idempotency shield (duplicate prevention ke liye), aur HTTPS encryption. Har critical operation tamper-proof hashing ke saath log hota hai.'],
            ];
            @endphp

            <div class="space-y-3 fade-up" x-data="{ open: null }">
                @foreach($faqs as $i => $f)
                <div class="paper-card ink-border transition-shadow" :class="open === {{ $i+1 }} ? 'ink-shadow' : ''">
                    <button @click="open = open === {{ $i+1 }} ? null : {{ $i+1 }}" class="w-full flex items-center justify-between p-5 text-left gap-4">
                        <div class="flex items-center gap-3">
                            <span class="font-mono font-bold text-[12px] text-[color:var(--maroon)] tracking-[0.16em] shrink-0">Q.{{ str_pad($i+1, 2, '0', STR_PAD_LEFT) }}</span>
                            <span class="font-display font-bold text-[16.5px] text-[color:var(--ink)]">{{ $f[0] }}</span>
                        </div>
                        <span class="w-7 h-7 ink-border flex items-center justify-center shrink-0 font-display font-black text-[16px]" :class="open === {{ $i+1 }} ? 'bg-[color:var(--ink)] text-[color:var(--paper)]' : 'bg-[color:var(--paper-2)]'" x-text="open === {{ $i+1 }} ? '−' : '+'"></span>
                    </button>
                    <div x-show="open === {{ $i+1 }}" x-collapse class="px-5 pb-5 pl-[68px] text-[14px] text-[color:var(--ink-soft)] leading-[1.75]">{!! $f[1] !!}</div>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ════════════════════════════════════════════════════════════════
         CONTACT — paper cards on cream
         ════════════════════════════════════════════════════════════════ --}}
    <section id="contact" class="py-20 lg:py-24 paper-2 border-t-2 border-[color:var(--ink)]">
        <div class="max-w-[1100px] mx-auto px-4 sm:px-6 lg:px-10">
            <div class="text-center mb-12 fade-up">
                <span class="eyebrow !justify-center" style="display:inline-flex;">Hum se baat karein</span>
                <h2 class="font-display text-[36px] sm:text-[44px] font-black mt-4 leading-[1.02]">Sawaalat? Hum sun rahe hain.</h2>
                <p class="mt-3 text-[15px] text-[color:var(--ink-soft)]">Email, phone ya WhatsApp — jo aap ko pasand ho.</p>
            </div>

            <div class="grid md:grid-cols-3 gap-5 fade-up">
                @php $contacts = [
                    ['Email', 'support@taxnest.com', '24 ghante ke andar reply', 'var(--pak-green)'],
                    ['Phone & WhatsApp', '+92-XXX-XXXXXXX', 'Mon-Sat · 10am - 7pm PKT', 'var(--maroon)'],
                    ['Office', 'Karachi, Pakistan', 'Lahore + Islamabad branches', 'var(--ocean)'],
                ]; @endphp
                @foreach($contacts as $i => $c)
                <div class="paper-card ink-border p-6 lift-card ink-shadow text-center {{ $i === 1 ? 'md:-mt-3' : '' }}">
                    <p class="font-mono text-[10.5px] uppercase tracking-[0.2em]" style="color: {{ $c[3] }};">{{ $c[0] }}</p>
                    <p class="font-display font-black text-[20px] text-[color:var(--ink)] mt-2.5">{{ $c[1] }}</p>
                    <p class="text-[12.5px] text-[color:var(--ink-soft)] mt-1.5">{{ $c[2] }}</p>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ════════════════════════════════════════════════════════════════
         FINAL CTA — poster on ink
         ════════════════════════════════════════════════════════════════ --}}
    <section class="py-20 lg:py-24 relative border-y-2 border-[color:var(--ink)]" style="background: var(--ink); color: var(--paper);">
        <div class="absolute inset-0 pointer-events-none opacity-[0.07]"
             style="background-image: radial-gradient(var(--gold-2) 1.5px, transparent 1.5px); background-size: 26px 26px;"></div>
        <div class="max-w-[1100px] mx-auto px-4 sm:px-6 lg:px-10 relative">
            <div class="grid lg:grid-cols-12 gap-10 items-center">
                <div class="lg:col-span-7">
                    <span class="eyebrow !text-[color:var(--gold-2)]">Aakhri qadam</span>
                    <h2 class="font-display text-[42px] sm:text-[56px] font-black leading-[1.0] mt-4">
                        Aaj se hi
                        <span class="text-[color:var(--gold-2)] italic">compliant</span><br>
                        kaarobar shuru karein.
                    </h2>
                    <p class="text-[16px] mt-5 opacity-80 leading-relaxed max-w-lg">Koi setup fee nahi, koi credit card nahi. Apna pasandeeda product chunein, 30 second mein account banayein, aur pehla invoice abhi banayein.</p>
                </div>
                <div class="lg:col-span-5 space-y-3">
                    <a href="/digital-invoice" class="w-full flex items-center justify-between gap-3 px-5 py-4 ink-border" style="background: var(--paper); color: var(--ink); box-shadow: 4px 4px 0 var(--gold-2);">
                        <div>
                            <p class="font-display font-black text-[16px]">Digital Invoice</p>
                            <p class="font-mono text-[10.5px] tracking-[0.16em] uppercase opacity-70">FBR compliance</p>
                        </div>
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </a>
                    <a href="/pos" class="w-full flex items-center justify-between gap-3 px-5 py-4 ink-border" style="background: var(--paper); color: var(--ink); box-shadow: 4px 4px 0 var(--gold-2);">
                        <div>
                            <p class="font-display font-black text-[16px]">NestPOS</p>
                            <p class="font-mono text-[10.5px] tracking-[0.16em] uppercase opacity-70">PRA POS</p>
                        </div>
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </a>
                    <a href="/fbr-pos-landing" class="w-full flex items-center justify-between gap-3 px-5 py-4 ink-border" style="background: var(--paper); color: var(--ink); box-shadow: 4px 4px 0 var(--gold-2);">
                        <div>
                            <p class="font-display font-black text-[16px]">FBR POS</p>
                            <p class="font-mono text-[10.5px] tracking-[0.16em] uppercase opacity-70">FBR-integrated POS</p>
                        </div>
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- ════════════════════════════════════════════════════════════════
         FOOTER — paper, signed
         ════════════════════════════════════════════════════════════════ --}}
    <footer class="py-14 paper-2">
        <div class="max-w-[1240px] mx-auto px-4 sm:px-6 lg:px-10">
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-8 pb-10 border-b-2 border-dashed border-[color:var(--ink)]">
                <div>
                    <div class="flex items-center gap-2.5 mb-3">
                        <div class="w-9 h-9 bg-[color:var(--pak-green)] border-2 border-[color:var(--ink)] flex items-center justify-center" style="box-shadow: 2px 2px 0 var(--ink);">
                            <span class="font-display font-black text-[color:var(--gold-2)] text-[16px]">T</span>
                        </div>
                        <span class="font-display font-extrabold text-[18px]">TaxNest</span>
                    </div>
                    <p class="text-[13px] text-[color:var(--ink-soft)] leading-relaxed max-w-[240px]">Pakistan ka apna FBR aur PRA compliance platform. Banaya gaya Karachi mein, soch ke saath.</p>
                    <p class="font-urdu text-[15px] text-[color:var(--maroon)] mt-3" dir="rtl">آپ کا اپنا ساتھی</p>
                </div>

                <div>
                    <h4 class="font-mono text-[10.5px] uppercase tracking-[0.2em] text-[color:var(--maroon)] mb-4">Products</h4>
                    <ul class="space-y-2.5 text-[13.5px]">
                        <li><a href="/digital-invoice" class="hover:underline underline-offset-4 decoration-[color:var(--gold)] decoration-2">Digital Invoice</a></li>
                        <li><a href="/pos" class="hover:underline underline-offset-4 decoration-[color:var(--gold)] decoration-2">NestPOS (PRA)</a></li>
                        <li><a href="/fbr-pos-landing" class="hover:underline underline-offset-4 decoration-[color:var(--gold)] decoration-2">FBR POS</a></li>
                    </ul>
                </div>

                <div>
                    <h4 class="font-mono text-[10.5px] uppercase tracking-[0.2em] text-[color:var(--maroon)] mb-4">Company</h4>
                    <ul class="space-y-2.5 text-[13.5px]">
                        <li><a href="#features" class="hover:underline underline-offset-4 decoration-[color:var(--gold)] decoration-2">Features</a></li>
                        <li><a href="#pricing" class="hover:underline underline-offset-4 decoration-[color:var(--gold)] decoration-2">Pricing</a></li>
                        <li><a href="#faq" class="hover:underline underline-offset-4 decoration-[color:var(--gold)] decoration-2">FAQ</a></li>
                        <li><a href="#contact" class="hover:underline underline-offset-4 decoration-[color:var(--gold)] decoration-2">Contact</a></li>
                    </ul>
                </div>

                <div>
                    <h4 class="font-mono text-[10.5px] uppercase tracking-[0.2em] text-[color:var(--maroon)] mb-4">Legal</h4>
                    <ul class="space-y-2.5 text-[13.5px]">
                        <li><a href="#" class="hover:underline underline-offset-4 decoration-[color:var(--gold)] decoration-2">Privacy Policy</a></li>
                        <li><a href="#" class="hover:underline underline-offset-4 decoration-[color:var(--gold)] decoration-2">Terms of Service</a></li>
                        <li><a href="#" class="hover:underline underline-offset-4 decoration-[color:var(--gold)] decoration-2">Refund Policy</a></li>
                    </ul>
                </div>
            </div>

            <div class="pt-7 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-[12px] text-[color:var(--ink-soft)]">
                <p>© {{ date('Y') }} TaxNest. Made in Karachi <span class="text-[color:var(--maroon)]">★</span> with chai, code aur compliance.</p>
                <p class="font-mono tracking-[0.16em] uppercase">v · {{ date('Y.m') }} · live</p>
            </div>
        </div>
    </footer>

    <script>
    // Fade-up reveal
    (function() {
        const obs = new IntersectionObserver((entries) => {
            entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); } });
        }, { threshold: 0.1 });
        document.querySelectorAll('.fade-up').forEach(el => obs.observe(el));
    })();

    // Counter animation
    (function() {
        const counters = document.querySelectorAll('.counter-val');
        const animate = (el) => {
            const target = parseFloat(el.dataset.target || '0');
            const decimal = parseInt(el.dataset.decimal || '0');
            const suffix = el.dataset.suffix || '';
            const duration = 1400;
            const start = performance.now();
            const step = (now) => {
                const p = Math.min((now - start) / duration, 1);
                const eased = 1 - Math.pow(1 - p, 3);
                const val = target * eased;
                let display;
                if (target >= 10000) {
                    display = Math.floor(val).toLocaleString('en-US');
                } else if (decimal > 0) {
                    display = val.toFixed(decimal);
                } else {
                    display = Math.floor(val).toString();
                }
                el.textContent = display + suffix;
                if (p < 1) requestAnimationFrame(step);
            };
            requestAnimationFrame(step);
        };
        const obs = new IntersectionObserver((entries) => {
            entries.forEach(e => { if (e.isIntersecting) { animate(e.target); obs.unobserve(e.target); } });
        }, { threshold: 0.4 });
        counters.forEach(c => obs.observe(c));
    })();
    </script>
</body>
</html>
