@php
    $comparisonPage = $comparisonPage ?? false;
    $title = $comparisonPage ? 'Compare TaxNest products' : 'TaxNest | Invoicing, POS and ERP for Pakistan';
    $description = $comparisonPage
        ? 'Find the right TaxNest product for FBR Digital Invoicing, PRA POS, FBR POS or Healthcare ERP.'
        : 'TaxNest provides FBR Digital Invoicing, PRA POS, FBR POS and ERP workspaces for businesses in Pakistan.';
    $canonical = $comparisonPage ? route('public.compare') : url('/');
    $products = [
        [
            'name' => 'TaxNest Digital Invoice', 'shortName' => 'Digital Invoice', 'url' => route('di.landing'),
            'summary' => 'Issue and track FBR digital invoices.',
            'audience' => 'Businesses that issue sales tax invoices.',
            'workflow' => 'Create or import invoice drafts, review FBR references, submit invoices and track acknowledgement.',
            'pricing' => route('di.landing').'#pricing',
        ],
        [
            'name' => 'TaxNest PRA POS', 'shortName' => 'PRA POS', 'url' => route('pos.landing'),
            'summary' => 'Run retail or restaurant counters with PRA reporting.',
            'audience' => 'Retailers and restaurants using PRA-integrated billing.',
            'workflow' => 'Record counter sales, manage restaurant orders and check fiscal submission and offline sync status.',
            'pricing' => route('pos.landing').'#pricing',
        ],
        [
            'name' => 'TaxNest FBR POS', 'shortName' => 'FBR POS', 'url' => route('fbrpos.landing'),
            'summary' => 'Track counter sales, stock and FBR submissions.',
            'audience' => 'Retailers using FBR POS and dedicated collection desks.',
            'workflow' => 'Record sales and returns, track stock and review fiscal acknowledgements through the configured route.',
            'pricing' => route('fbrpos.landing').'#pricing',
        ],
        [
            'name' => \App\Support\NestErps::LABEL, 'shortName' => 'ERP', 'url' => route('erps.landing'),
            'summary' => 'Explore Healthcare ERP and request an ERP for your industry.',
            'audience' => 'Clinics, hospitals, laboratories and pharmacies.',
            'workflow' => 'See the current Healthcare workspace, including patient, pharmacy and accounts workflows. Other sectors are scoped on request.',
            'pricing' => route('erps.landing'),
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#092A33">
    <link rel="icon" type="image/svg+xml" href="/images/brand/taxnest-mark.svg">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical }}">
    @unless($comparisonPage)<link rel="stylesheet" href="{{ asset('css/client-reel.css') }}?v=2">@endunless
    @include('partials.meta-og', ['ogTitle' => $title, 'ogDescription' => $description, 'ogUrl' => $canonical])
    @include('partials.font-css', ['fontFamilies' => 'playfair-display:400,600,700|inter:400,500,600,700'])
    @include('partials.fast-first-paint', ['bootBg' => '#092A33'])
    @include('partials.urdu-font')
    <style>
        :root {
            --petrol: #092a33;
            --paper: #f7f8f5;
            --teal: #117d73;
            --muted: #526769;
            --line: #dce5e1;
            --focus-ring: #075952;
        }
        body {
            font-family: Inter, system-ui, sans-serif;
            color: var(--petrol);
            background: var(--paper);
            -webkit-font-smoothing: antialiased;
        }
        h1, h2 { font-family: 'Playfair Display', Georgia, serif; }
        [x-cloak] { display: none !important; }
        :is(.public-nav, .public-hero, footer) { --focus-ring: #4fd6bd; }
        :is(a, button):focus-visible { outline: 2px solid var(--focus-ring); outline-offset: 3px; }
        .public-overview footer :is(h4, p) { color: #b8cfcb; }
        .public-width { width: min(100% - 2rem, 74rem); margin-inline: auto; }
        .public-nav { background: var(--petrol); border-bottom: 1px solid #ffffff1f; color: #eef6f3; }
        .public-nav-bar { display: flex; align-items: center; justify-content: space-between; gap: .8rem; min-height: 4.75rem; }
        .public-nav-links { align-items: center; gap: clamp(.75rem, 1.5vw, 1.5rem); font-size: .875rem; }
        .public-nav-links a { min-height: 2.75rem; display: flex; align-items: center; }
        .public-nav-links a:hover, .public-nav-links a[aria-current] { color: #9de8d7; }
        .public-actions { display: flex; align-items: center; gap: .75rem; }
        .public-menu { max-height: calc(100dvh - 4.75rem); overflow-y: auto; padding: .5rem 0 .75rem; border-top: 1px solid #ffffff1f; }
        .public-menu a { display: block; padding: .7rem .6rem; border-radius: .4rem; font-size: .9rem; }
        .public-menu a:hover { background: #ffffff12; }
        .public-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 2.75rem;
            padding: .65rem 1rem;
            border: 1px solid transparent;
            border-radius: .55rem;
            font-size: .875rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .public-button-primary { background: var(--teal); color: #fff; }
        .public-button-primary:hover { background: #0d695f; }
        .public-hero { background: radial-gradient(ellipse at 85% 40%, #175359 0, var(--petrol) 65%); color: #fff; }
        .public-hero-inner {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            align-items: center;
            gap: clamp(1.75rem, 5vw, 4rem);
            padding-block: clamp(2.4rem, 7vw, 5.75rem);
        }
        .public-hero h1, .public-compare-intro h1 { margin: 0; font-size: clamp(2.4rem, 5.6vw, 4.65rem); line-height: 1.08; letter-spacing: -.045em; font-weight: 600; }
        .public-hero p { margin: 1.2rem 0 1.45rem; max-width: 42ch; color: #dfefea; font-size: clamp(1rem, 2vw, 1.13rem); line-height: 1.65; }
        .public-hero .public-button { background: var(--paper); color: var(--petrol); }
        .public-hero .public-button:hover { background: #d7eee5; }
        .public-preview { margin: 0; padding: .4rem; background: #ffffff1f; border: 1px solid #ffffff36; border-radius: .9rem; box-shadow: 0 22px 55px #041a2366; }
        .public-preview img { display: block; width: 100%; height: auto; border-radius: .55rem; }
        .public-preview figcaption { padding: .4rem .45rem .15rem; color: #dceee8; font-size: .76rem; }
        .public-products { padding-block: clamp(2.2rem, 5vw, 3.65rem); }
        .public-products h2 { margin: 0 0 1.1rem; font-size: clamp(1.7rem, 3vw, 2.55rem); line-height: 1.2; font-weight: 600; }
        .public-cards { display: grid; grid-template-columns: minmax(0, 1fr); gap: .85rem; }
        .public-card {
            display: flex;
            flex-direction: column;
            gap: .45rem;
            min-width: 0;
            padding: 1.12rem;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: .8rem;
        }
        a.public-card:hover { background: #edf6f0; border-color: #88b9ac; }
        .public-card strong { display: flex; align-items: center; justify-content: space-between; gap: .5rem; font-size: 1rem; }
        .public-card strong span { color: var(--teal); }
        .public-card p, .public-card dd { margin: 0; color: var(--muted); font-size: .91rem; line-height: 1.55; }
        .public-details { display: flex; align-items: baseline; flex-wrap: wrap; gap: .65rem 1.2rem; margin-top: 1.2rem; }
        .public-details a { padding: .35rem 0; color: #0a625c; font-size: .9rem; font-weight: 700; text-decoration: underline; text-underline-offset: 4px; }
        #products, #compare { scroll-margin-top: 6rem; }
        .public-compare-intro { padding-top: clamp(2.2rem, 6vw, 4.5rem); }
        .public-compare-intro h1 { max-width: 20ch; font-size: clamp(2.2rem, 5vw, 3.75rem); }
        .public-compare-intro p { margin: .8rem 0 0; max-width: 56ch; color: var(--muted); line-height: 1.6; }
        .public-compare h2 { margin-bottom: .35rem; font-size: 1.4rem; }
        .public-compare .public-card dl { display: grid; gap: .3rem; margin: 0 0 .65rem; }
        .public-compare .public-card dt { margin-top: .35rem; font-weight: 700; font-size: .78rem; }
        .public-compare .public-details { margin-top: auto; }
        @media (min-width: 38rem) {
            .public-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (min-width: 60rem) {
            .public-hero-inner { grid-template-columns: minmax(0, .98fr) minmax(0, 1.02fr); }
            .public-cards { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        @media (min-width: 1280px) {
            .public-nav .public-menu-toggle { display: none; }
        }
    </style>

    @unless($comparisonPage)
    <!-- Structured Data -->
    <script type="application/ld+json">
    {
        "@@context": "https://schema.org",
        "@@graph": [
            {
                "@@type": "Organization",
                "@@id": "https://taxnest.pk/#organization",
                "name": "TaxNest",
                "url": "https://taxnest.pk/",
                "logo": {
                    "@@type": "ImageObject",
                    "url": "https://taxnest.pk/images/brand/taxnest-logo.svg"
                },
                "description": "Digital invoicing, point of sale and ERP software for businesses in Pakistan.",
                "areaServed": "PK",
                "foundingLocation": { "@@type": "Country", "name": "Pakistan" }
            },
            {
                "@@type": "WebSite",
                "@@id": "https://taxnest.pk/#website",
                "url": "https://taxnest.pk/",
                "name": "TaxNest",
                "publisher": { "@@id": "https://taxnest.pk/#organization" }
            },
            {
                "@@type": "SoftwareApplication",
                "@@id": "https://taxnest.pk/#software",
                "name": "TaxNest",
                "applicationCategory": "BusinessApplication",
                "operatingSystem": "Web",
                "description": "Digital invoicing, point of sale and ERP software for businesses in Pakistan.",
                "url": "https://taxnest.pk/",
                "publisher": { "@@id": "https://taxnest.pk/#organization" }
            }
        ]
    }
    </script>
    @endunless

</head>
<body class="public-overview">
    <div id="tn-boot" aria-hidden="true"><span>Tax<b>Nest</b></span></div>
    <header class="public-nav sticky top-0 z-50"
        x-data="{
            mobileOpen: false,
            closeMenu() {
                if (!this.mobileOpen) return;
                this.mobileOpen = false;
                this.$refs.menuToggle.focus();
            }
        }" @keydown.escape.window="closeMenu()">
        <nav class="public-width" aria-label="Main navigation">
            <div class="public-nav-bar">
                <a href="{{ url('/') }}" class="flex-shrink-0">
                    <img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" width="92" height="32" alt="TaxNest" class="h-8 w-auto">
                </a>
                <div class="public-nav-links hidden xl:flex">
                    @foreach($products as $product)
                        <a href="{{ $product['url'] }}">{{ $product['shortName'] }}</a>
                    @endforeach
                    <a href="{{ route('public.compare') }}" @if($comparisonPage) aria-current="page" @endif>Compare</a>
                    <a href="/tutorials">Tutorials</a>
                    <a href="/download">Downloads</a>
                    <a href="/contact">Contact</a>
                </div>
                <div class="public-actions">
                    <a href="/login" class="hidden text-sm font-semibold sm:inline">Log In</a>
                    <a href="/register" class="public-button public-button-primary">Get Started</a>
                    <button type="button" x-ref="menuToggle" @click="mobileOpen = !mobileOpen" class="xl:hidden public-button public-menu-toggle" aria-label="Menu" aria-controls="public-mobile-menu" :aria-expanded="mobileOpen">
                        <span aria-hidden="true" x-text="mobileOpen ? 'Close' : 'Menu'">Menu</span>
                    </button>
                </div>
            </div>
            <div id="public-mobile-menu" x-show="mobileOpen" x-cloak class="xl:hidden public-menu">
                @foreach($products as $product)
                    <a href="{{ $product['url'] }}">{{ $product['name'] }}</a>
                @endforeach
                <a href="{{ route('public.compare') }}" @if($comparisonPage) aria-current="page" @endif>Compare Products</a>
                <a href="/tutorials">Tutorials</a>
                <a href="/download">Downloads</a>
                <a href="/contact">Contact</a>
                <a href="/login">Log In</a>
            </div>
        </nav>
    </header>
    <main>
        @if($comparisonPage)
            <section class="public-width public-compare-intro" aria-labelledby="compare-title">
                <h1 id="compare-title">Which TaxNest product fits your business?</h1>
                <p>Compare who each product is built for and what it helps you do each day.</p>
            </section>
            <section class="public-width public-products public-compare" aria-label="Product comparison">
                <div class="public-cards">
                    @foreach($products as $product)
                        <article class="public-card">
                            <h2>{{ $product['name'] }}</h2>
                            <dl>
                                <dt>Built for</dt>
                                <dd>{{ $product['audience'] }}</dd>
                                <dt>How it works</dt>
                                <dd>{{ $product['workflow'] }}</dd>
                            </dl>
                            <div class="public-details">
                                @if($product['pricing'] === $product['url'])
                                    <a href="{{ $product['url'] }}">Explore ERP and packages</a>
                                @else
                                    <a href="{{ $product['url'] }}" aria-label="Explore {{ $product['name'] }}">Explore</a>
                                    <a href="{{ $product['pricing'] }}" aria-label="View {{ $product['name'] }} pricing">View pricing</a>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
                <div class="public-details"><a href="{{ route('product-guide') }}">Read the product guide</a></div>
            </section>
        @else
            <section class="public-hero" aria-labelledby="home-title">
                <div class="public-width public-hero-inner">
                    <div>
                        <h1 id="home-title">Invoicing, POS and ERP for Pakistan</h1>
                        <p>Issue FBR digital invoices, run your counters and manage your operations in the TaxNest workspace built for your business.</p>
                        <a href="#products" class="public-button">Explore products</a>
                    </div>
                    <figure class="public-preview" data-hero-vignette>
                        <img src="{{ asset('images/screenshots/di-dash.jpg') }}" width="1280" height="720" fetchpriority="high" alt="TaxNest Digital Invoice dashboard">
                        <figcaption>Inside TaxNest Digital Invoice</figcaption>
                    </figure>
                </div>
            </section>

            <section id="products" class="public-width public-products" aria-labelledby="products-title">
                <h2 id="products-title">Choose a TaxNest product</h2>
                <div class="public-cards">
                    @foreach($products as $product)
                        <a href="{{ $product['url'] }}" class="public-card">
                            <strong>{{ $product['name'] }} <span aria-hidden="true">→</span></strong>
                            <p>{{ $product['summary'] }}</p>
                        </a>
                    @endforeach
                </div>
                <div id="compare" class="public-details">
                    <a href="{{ route('public.compare') }}">Compare products</a>
                    <a href="{{ route('product-guide') }}">Read the product guide</a>
                </div>
            </section>

            <x-website-client-home />
        @endif
    </main>

    <x-site-footer />
    <x-whatsapp-support />
</body>
</html>
