@php
    use App\Services\HealthModuleService;
    use App\Support\HealthPanel;
    use App\Support\NestErps;
@endphp
{{--
    Nest ERPS hub (Task 1568).

    This page introduces the PRODUCT LINE, not one ERP. It is deliberately
    small: the three main products own the pricing tables, and Nest ERPS is sold
    on enquiry until the owner sets public rates — so there is no price grid
    here, only the live vertical and a way to ask for one that does not exist
    yet. A future vertical appears in the list below the moment it registers
    itself in NestErps::VERTICALS; nothing on this page needs editing.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ NestErps::LABEL }} — ERPs built for the way your organisation runs</title>
    <meta name="description" content="{{ NestErps::LABEL }} is TaxNest's ERP line. Healthcare is live today — clinics, hospitals, laboratories and pharmacies. Other ERPs are built on demand.">
    @include('partials.font-css', ['fontFamilies' => 'figtree:400,500,600,700,800'])
    @vite(['resources/css/app.css'])
</head>
<body class="bg-white text-[#0B1B20] antialiased font-sans">

{{-- ══════════════ Header ══════════════ --}}
<header class="sticky top-0 z-40 bg-white/95 backdrop-blur border-b border-gray-200">
    <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
        <a href="/" class="flex items-center gap-2.5">
            <img src="{{ asset('images/brand/taxnest-logo.svg') }}" alt="TaxNest" class="h-7 w-auto"
                 onerror="this.style.display='none'">
            <span class="text-sm font-extrabold tracking-tight text-[#0A4D5C]">{{ NestErps::LABEL }}</span>
        </a>
        <div class="flex items-center gap-2">
            <a href="{{ url('/contact') }}" class="px-4 py-2 text-sm font-semibold text-[#0A4D5C] hover:bg-[#0A4D5C]/5 rounded-lg transition">Talk to us</a>
            <a href="{{ url(NestErps::loginPath(NestErps::HEALTH)) }}" class="px-4 py-2 text-sm font-bold text-white bg-[#0A4D5C] hover:bg-[#083c48] rounded-lg transition">Log In</a>
        </div>
    </div>
</header>

{{-- ══════════════ Hero ══════════════ --}}
<section class="bg-[#052730] text-white">
    <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8 py-20 lg:py-28">
        <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-[#E7BF3B]">{{ NestErps::LABEL }}</p>
        <h1 class="mt-4 text-3xl sm:text-4xl lg:text-5xl font-extrabold tracking-tight leading-[1.15] max-w-3xl">
            {{ NestErps::TAGLINE }}
        </h1>
        <p class="mt-5 text-base sm:text-lg text-white/70 leading-relaxed max-w-2xl">
            One ERP line with a separate system for each kind of organisation. Healthcare runs on it
            today. The rest are built on request — on the same platform, with the same branch
            boundaries, the same roles and the same three languages as everything else we make.
        </p>
        <div class="mt-8 flex flex-wrap gap-3">
            <a href="#verticals" class="px-6 py-3 rounded-xl bg-[#0A4D5C] hover:bg-[#0d5d70] text-white text-sm font-bold transition">See what is live</a>
            <a href="{{ url('/contact') }}" class="px-6 py-3 rounded-xl border border-white/25 hover:bg-white/10 text-white text-sm font-bold transition">Ask for your ERP</a>
        </div>
    </div>
</section>

{{-- ══════════════ The line ══════════════ --}}
<section id="verticals" class="py-16 lg:py-20">
    <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
        <h2 class="text-2xl font-extrabold tracking-tight">Inside the line</h2>
        <p class="mt-2 text-sm text-gray-600 max-w-2xl">
            Each vertical is its own panel with its own screens — never a general-purpose ERP with a
            different logo on it.
        </p>

        <div class="mt-8 grid md:grid-cols-2 gap-5">
            @foreach(NestErps::verticals() as $key => $vertical)
                <div class="rounded-2xl border-2 border-gray-200 p-6 flex flex-col">
                    <div class="flex items-center gap-2">
                        <p class="text-sm font-extrabold text-[#0A4D5C]">{{ NestErps::LABEL }} — {{ $vertical['label'] }}</p>
                        @if($vertical['live'] ?? false)
                            <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-[#E7BF3B]/20 text-[#8a6d0b]">Live</span>
                        @else
                            <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-gray-100 text-gray-500">On request</span>
                        @endif
                    </div>
                    <p class="mt-2 text-xs text-gray-600 leading-relaxed flex-1">{{ $vertical['blurb'] ?? '' }}</p>
                    @if($vertical['live'] ?? false)
                        <div class="mt-5 flex flex-wrap gap-2">
                            {{-- "Live" here means the vertical is built and deployed, which is
                                 not the same as being open to strangers. While it is pre-pilot
                                 the front door stays shut, so the sign-up call-to-action asks
                                 the same predicate the route guard does — the page can never
                                 offer a door the server will answer with a 404. --}}
                            @if(HealthPanel::registrationOpen())
                                <a href="{{ url(NestErps::registerPath($key)) }}"
                                   class="px-5 py-2.5 rounded-xl bg-[#0A4D5C] hover:bg-[#083c48] text-white text-sm font-bold transition">Start your organisation</a>
                            @endif
                            <a href="{{ url(NestErps::loginPath($key)) }}"
                               class="px-5 py-2.5 rounded-xl border border-gray-300 hover:bg-gray-50 text-[#0A4D5C] text-sm font-bold transition">Log in</a>
                        </div>
                    @endif
                </div>
            @endforeach

            {{-- The point of the line, stated plainly. --}}
            <div class="rounded-2xl border-2 border-dashed border-gray-300 p-6 flex flex-col bg-gray-50">
                <p class="text-sm font-extrabold text-[#0A4D5C]">Your ERP</p>
                <p class="mt-2 text-xs text-gray-600 leading-relaxed flex-1">
                    School, factory, workshop, distribution — if your organisation runs on paper
                    registers today, tell us how it works and we build that ERP into this line. It
                    arrives as its own panel, not as a settings page bolted onto someone else's.
                </p>
                <a href="{{ url('/contact') }}"
                   class="mt-5 inline-block text-center px-5 py-2.5 rounded-xl bg-[#0A4D5C] hover:bg-[#083c48] text-white text-sm font-bold transition">Tell us what you need</a>
            </div>
        </div>
    </div>
</section>

{{-- ══════════════ Live vertical: Healthcare ══════════════ --}}
<section class="py-16 lg:py-20 bg-gray-50 border-y border-gray-200">
    <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
        <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-[#E7BF3B]">{{ NestErps::LABEL }}</p>
        <h2 class="mt-2 text-2xl font-extrabold tracking-tight">{{ NestErps::verticalLabel(NestErps::HEALTH) }}</h2>
        <p class="mt-2 text-sm text-gray-600 max-w-2xl">
            One system for the clinic, the hospital, the lab and the pharmacy. Pick your type at
            sign-up and the right modules are switched on for you — you can change them any time.
        </p>

        <div class="mt-8 grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach(HealthPanel::ORG_TYPES as $type)
                <div class="rounded-2xl bg-white border border-gray-200 p-5">
                    <p class="text-sm font-extrabold text-[#0A4D5C]">{{ ucfirst($type) }}</p>
                    <p class="mt-1.5 text-xs text-gray-600 leading-relaxed">
                        Starts with {{ implode(', ', array_map(
                            fn ($m) => ucfirst($m),
                            HealthModuleService::ORG_TYPE_DEFAULTS[$type] ?? []
                        )) }}.
                    </p>
                </div>
            @endforeach
        </div>

        <h3 class="mt-12 text-lg font-extrabold tracking-tight">Only the modules you run</h3>
        <p class="mt-2 text-sm text-gray-600 max-w-2xl">A module that is switched off does not appear anywhere in the panel — not in the menu, not as an upsell.</p>
        <div class="mt-6 grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($modules as $module)
                <div class="rounded-2xl bg-white border border-gray-200 p-5">
                    <div class="flex items-center gap-2">
                        <span class="text-lg leading-none">{{ $moduleMeta[$module]['icon'] ?? '•' }}</span>
                        <p class="text-sm font-extrabold">{{ __(HealthModuleService::moduleLabelKey($module), [], 'en') }}</p>
                    </div>
                    <p class="mt-1.5 text-xs text-gray-600 leading-relaxed">{{ __(HealthModuleService::moduleDescriptionKey($module), [], 'en') }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ══════════════ Enquiry ══════════════ --}}
<section class="py-16 lg:py-20">
    <div class="max-w-[1200px] mx-auto px-4 sm:px-6 lg:px-8">
        <div class="rounded-2xl bg-[#052730] text-white p-8 sm:p-10">
            <h2 class="text-2xl font-extrabold tracking-tight">Pricing is quoted, not listed</h2>
            <p class="mt-3 text-sm text-white/70 leading-relaxed max-w-2xl">
                An ERP is scoped to the organisation that runs it — how many branches, which
                departments, how many people on it. Tell us how yours works and we quote the line
                and the rate together.
            </p>
            <div class="mt-7 flex flex-wrap gap-3">
                <a href="{{ url('/contact') }}" class="px-6 py-3 rounded-xl bg-[#E7BF3B] hover:bg-[#d8b230] text-[#052730] text-sm font-bold transition">Request a quote</a>
                {{-- The vertical is deployed but still pre-pilot: the code is live and
                     provable, the front door is shut. One predicate decides it, so this
                     call-to-action can never disagree with the route guard. --}}
                @if(HealthPanel::registrationOpen())
                    <a href="{{ url(NestErps::registerPath(NestErps::HEALTH)) }}" class="px-6 py-3 rounded-xl border border-white/25 hover:bg-white/10 text-white text-sm font-bold transition">Try {{ NestErps::verticalLabel(NestErps::HEALTH) }} free</a>
                @endif
            </div>
        </div>
    </div>
</section>

{{-- The footer login link is panel-aware: a visitor on this page belongs to the
     Nest ERPS panel, never the Digital Invoice one. --}}
<x-site-footer :loginUrl="NestErps::loginPath(NestErps::HEALTH)" :loginLabel="NestErps::LABEL . ' Log In'" />
</body>
</html>
