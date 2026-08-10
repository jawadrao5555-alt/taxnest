<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#052730">
    <link rel="icon" type="image/svg+xml" href="/images/brand/taxnest-mark.svg">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <title>Video Tutorials — NestPOS aur FBR POS | TaxNest</title>
    <meta name="description" content="Chhoti chhoti Urdu videos — account banane se le kar bill, customers, products aur reports tak, NestPOS ka har feature aaram se seekhein.">
    @include('partials.meta-og', [
        'ogTitle'       => 'Video Tutorials — NestPOS aur FBR POS | TaxNest',
        'ogDescription' => 'Short Urdu video tutorials for TaxNest — from creating your first bill to managing products, customers, and reports. Learn every feature step by step.',
        'ogUrl'         => 'https://taxnest.com.pk/tutorials',
    ])
    <link rel="canonical" href="https://taxnest.com.pk/tutorials">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link rel="preload" as="style" href="https://fonts.bunny.net/css?family=playfair-display:400,600,700|inter:400,500,600,700,800&display=swap">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=playfair-display:400,600,700|inter:400,500,600,700,800&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.bunny.net/css?family=playfair-display:400,600,700|inter:400,500,600,700,800&display=swap"></noscript>
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
        h1, h2, h3, .font-serif { font-family: 'Playfair Display', serif; }
        [x-cloak] { display: none !important; }
        .tut-video { aspect-ratio: 16 / 9; width: 100%; background: #000; display: block; }
    </style>
</head>
<body>

    <!-- Simple top nav -->
    <nav class="fixed top-0 w-full z-50 bg-white/95 border-b border-gray-200" style="backdrop-filter: blur(8px);">
        <div class="max-w-[1100px] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <a href="/" class="flex-shrink-0">
                    <img src="{{ asset('images/brand/taxnest-logo.svg') }}" alt="TaxNest" class="h-8 w-auto">
                </a>
                <div class="flex items-center gap-3 sm:gap-5">
                    <a href="/" class="text-sm font-medium text-gray-600 hover:text-gray-900">Home</a>
                    <a href="/pos" class="hidden sm:inline text-sm font-medium text-gray-600 hover:text-gray-900">NestPOS</a>
                    <a href="/pos/login" class="text-sm font-medium text-gray-600 hover:text-gray-900">Log In</a>
                    <a href="/pos/register" class="text-sm font-semibold text-white px-4 py-2 rounded-lg" style="background: var(--teal-main);">{{ __('pos.tutorials_pub_cta') }}</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero -->
    <header class="pt-16" style="background: linear-gradient(160deg, var(--teal-dark) 0%, var(--teal-main) 100%);">
        <div class="max-w-[1100px] mx-auto px-4 sm:px-6 lg:px-8 py-14 sm:py-16 text-center">
            <p class="text-xs sm:text-sm font-bold uppercase tracking-widest mb-3" style="color: var(--gold);">Video Tutorials</p>
            <h1 class="text-3xl sm:text-5xl font-bold text-white mb-4">{{ __('pos.tutorials_pub_hero_h1') }}</h1>
            <p class="text-sm sm:text-base text-gray-200 max-w-2xl mx-auto">{{ __('pos.tutorials_pub_hero_sub') }}</p>
        </div>
    </header>

    <!-- Video sections -->
    <main class="max-w-[1100px] mx-auto px-4 sm:px-6 lg:px-8 py-10 sm:py-14">
        @forelse($products as $productKey => $product)
        <!-- Product folder -->
        <section class="mb-14">
            <div class="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white px-5 py-4 shadow-sm mb-8">
                <svg class="w-7 h-7 flex-shrink-0" style="color: var(--gold);" fill="currentColor" viewBox="0 0 24 24"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
                <h2 class="text-xl sm:text-2xl font-bold" style="color: var(--teal-dark);">{{ $product['label'] }}</h2>
                <span class="ml-auto text-xs sm:text-sm font-semibold text-gray-500 bg-gray-100 rounded-full px-3 py-1">{{ $product['count'] }} videos</span>
            </div>

            @foreach($product['groups'] as $group)
            <div class="mb-12 sm:pl-4">
                <div class="flex items-center gap-3 mb-5">
                    <h3 class="text-lg sm:text-xl font-bold font-serif" style="color: var(--teal-dark);">{{ $group['label'] }}</h3>
                    <div class="h-1 w-10 rounded-full" style="background: var(--gold);"></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    @foreach($group['videos'] as $v)
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                        <video class="tut-video" controls preload="metadata" playsinline src="{{ $v->video_url }}"></video>
                        <div class="p-4 sm:p-5">
                            <h4 class="text-base sm:text-lg font-bold text-gray-900" style="font-family: 'Inter', sans-serif;">{{ $v->title }}</h4>
                            @if($v->description)
                            <p class="text-xs sm:text-sm text-gray-500 mt-1.5 leading-relaxed">{{ $v->description }}</p>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endforeach
        </section>
        @empty
        <p class="text-center text-gray-500 py-16">{{ __('pos.tutorials_pub_empty') }}</p>
        @endforelse

        <!-- More coming + help strip -->
        <div class="rounded-2xl border border-gray-200 bg-white p-6 sm:p-8 text-center">
            <p class="text-sm sm:text-base font-semibold text-gray-900 mb-1">{{ __('pos.tutorials_pub_more_title') }}</p>
            <p class="text-xs sm:text-sm text-gray-500 mb-1">{{ __('pos.tutorials_pub_more_sub') }}</p>
            <p class="text-xs sm:text-sm text-gray-500 mb-4">{{ __('pos.tutorials_pub_offline') }}</p>
            <div class="flex flex-wrap items-center justify-center gap-3">
                <a href="/pos/register" class="text-sm font-semibold text-white px-5 py-2.5 rounded-lg" style="background: var(--teal-main);">{{ __('pos.tutorials_pub_cta') }}</a>
                <a href="/contact" class="text-sm font-semibold px-5 py-2.5 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">{{ __('pos.tutorials_pub_ask') }}</a>
            </div>
        </div>
    </main>

    <x-site-footer />
</body>
</html>
