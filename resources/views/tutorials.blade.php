<!DOCTYPE html>
<html lang="ur" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#052730">
    <title>Video Tutorials — NestPOS chalana seekhein | TaxNest</title>
    <meta name="description" content="Chhoti chhoti Urdu videos — account banane se le kar bill, customers, products aur reports tak, NestPOS ka har feature aaram se seekhein.">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=playfair-display:400,600,700|inter:400,500,600,700,800&display=swap" rel="stylesheet">
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
                    <a href="/pos/register" class="text-sm font-semibold text-white px-4 py-2 rounded-lg" style="background: var(--teal-main);">Muft Trial</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero -->
    <header class="pt-16" style="background: linear-gradient(160deg, var(--teal-dark) 0%, var(--teal-main) 100%);">
        <div class="max-w-[1100px] mx-auto px-4 sm:px-6 lg:px-8 py-14 sm:py-16 text-center">
            <p class="text-xs sm:text-sm font-bold uppercase tracking-widest mb-3" style="color: var(--gold);">Video Tutorials</p>
            <h1 class="text-3xl sm:text-5xl font-bold text-white mb-4">NestPOS chalana seekhein</h1>
            <p class="text-sm sm:text-base text-gray-200 max-w-2xl mx-auto">Chhoti chhoti Urdu videos — account banane se le kar bill, customers aur reports tak. Har feature aaram se, tasalli se seekhein.</p>
        </div>
    </header>

    <!-- Video sections -->
    <main class="max-w-[1100px] mx-auto px-4 sm:px-6 lg:px-8 py-10 sm:py-14">
        @forelse($groups as $key => $group)
        <section class="mb-12">
            <div class="flex items-center gap-3 mb-5">
                <h2 class="text-xl sm:text-2xl font-bold" style="color: var(--teal-dark);">{{ $group['label'] }}</h2>
                <div class="h-1 w-10 rounded-full" style="background: var(--gold);"></div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                @foreach($group['videos'] as $v)
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <video class="tut-video" controls preload="metadata" playsinline src="{{ $v->video_url }}"></video>
                    <div class="p-4 sm:p-5">
                        <h3 class="text-base sm:text-lg font-bold text-gray-900" style="font-family: 'Inter', sans-serif;">{{ $v->title }}</h3>
                        @if($v->description)
                        <p class="text-xs sm:text-sm text-gray-500 mt-1.5 leading-relaxed">{{ $v->description }}</p>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
        </section>
        @empty
        <p class="text-center text-gray-500 py-16">Videos jald aa rahi hain — thodi der mein dobara dekhein.</p>
        @endforelse

        <!-- More coming + help strip -->
        <div class="rounded-2xl border border-gray-200 bg-white p-6 sm:p-8 text-center">
            <p class="text-sm sm:text-base font-semibold text-gray-900 mb-1">Aur videos jald aa rahi hain</p>
            <p class="text-xs sm:text-sm text-gray-500 mb-4">Products, inventory, reports, restaurant aur day close — har feature ki video tayyar ho rahi hai.</p>
            <div class="flex flex-wrap items-center justify-center gap-3">
                <a href="/pos/register" class="text-sm font-semibold text-white px-5 py-2.5 rounded-lg" style="background: var(--teal-main);">Aaj hi muft trial shuru karein</a>
                <a href="/contact" class="text-sm font-semibold px-5 py-2.5 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">Sawal poochein</a>
            </div>
        </div>
    </main>

    <x-site-footer />
</body>
</html>
