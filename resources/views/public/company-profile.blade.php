<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/svg+xml" href="/images/brand/taxnest-mark.svg">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <title>{{ $company->name }} — {{ __('pos.qr_title_suffix') }}</title>
    @vite(['resources/css/app.css'])
    <style>
        :root { --brand: #0A4D5C; --brand-dark: #073844; --gold: #E7BF3B; }
        body { -webkit-tap-highlight-color: transparent; }
    </style>
    {{-- QR menu can render in 'ur' (company default locale) — Urdu-script font (Task 1287). --}}
    @include('partials.urdu-font')
</head>
<body class="bg-gray-50 min-h-screen antialiased" style="font-family: 'Figtree', ui-sans-serif, system-ui, sans-serif;">

    <!-- Header -->
    <header class="text-white" style="background: var(--brand);">
        <div class="max-w-lg mx-auto px-5 pt-8 pb-6 text-center">
            @php
                $logoUrl = $company->logo_path ? asset('storage/' . $company->logo_path) : null;
            @endphp
            @if($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $company->name }}" class="w-20 h-20 rounded-2xl object-contain bg-white p-1.5 mx-auto mb-3 shadow-sm">
            @else
                <div class="w-20 h-20 rounded-2xl bg-white/10 border border-white/20 mx-auto mb-3 flex items-center justify-center">
                    <span class="text-3xl font-extrabold" style="color: var(--gold);">{{ strtoupper(mb_substr($company->name, 0, 1)) }}</span>
                </div>
            @endif
            <h1 class="text-2xl font-extrabold tracking-tight">{{ $company->name }}</h1>
            @if($settings['about_text'] !== '')
                <p class="mt-2 text-sm text-white/85 leading-relaxed">{{ $settings['about_text'] }}</p>
            @endif
        </div>
    </header>

    <main class="max-w-lg mx-auto px-4 pb-12 -mt-3">

        <!-- Contact / details card -->
        @php
            $rows = [];
            if (($settings['show_phone'] ?? false) && $company->phone) $rows[] = [__('pos.qr_phone'), $company->phone, 'tel:' . preg_replace('/\s+/', '', $company->phone)];
            if (($settings['show_mobile'] ?? false) && $company->mobile) $rows[] = [__('pos.qr_mobile'), $company->mobile, 'tel:' . preg_replace('/\s+/', '', $company->mobile)];
            if (($settings['show_email'] ?? false) && $company->email) $rows[] = [__('pos.qr_email'), $company->email, 'mailto:' . $company->email];
            if (($settings['show_address'] ?? false) && $company->address) $rows[] = [__('pos.qr_address'), $company->address . ($company->city ? ', ' . $company->city : ''), null];
            if (($settings['show_ntn'] ?? false) && $company->ntn) $rows[] = [__('pos.qr_ntn'), $company->ntn, null];
            // Only http(s) URLs may become clickable links — anything else (javascript:,
            // scheme-less, etc.) renders as plain text so tenant input can't inject a link.
            if (($settings['show_website'] ?? false) && $company->website) $rows[] = [__('pos.qr_website'), $company->website, preg_match('/^https?:\/\//i', $company->website) ? $company->website : null];
            if (($settings['show_hours'] ?? false) && $settings['hours_text'] !== '') $rows[] = [__('pos.qr_hours'), $settings['hours_text'], null];
        @endphp
        @if(count($rows))
        <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <ul class="divide-y divide-gray-100">
                @foreach($rows as [$label, $value, $href])
                <li class="flex items-start gap-3 px-4 py-3">
                    <span class="shrink-0 text-[11px] font-bold uppercase tracking-wide text-teal-800 bg-teal-50 rounded-md px-2 py-1 mt-0.5 w-20 text-center">{{ $label }}</span>
                    @if($href)
                        <a href="{{ $href }}" class="text-sm font-semibold text-teal-800 break-all underline decoration-teal-300 underline-offset-2">{{ $value }}</a>
                    @else
                        <span class="text-sm font-medium text-gray-800 break-words">{{ $value }}</span>
                    @endif
                </li>
                @endforeach
            </ul>
        </section>
        @endif

        <!-- Menu -->
        @if(($settings['show_menu'] ?? true) && $menuItems->count())
        <section class="mt-6">
            <div class="flex items-center gap-2 mb-3 px-1">
                <h2 class="text-lg font-extrabold text-gray-900">{{ __('pos.qr_menu') }}</h2>
                <span class="text-[11px] font-bold text-white rounded-full px-2 py-0.5" style="background: var(--brand);">{{ __('pos.qr_items_count', ['count' => $menuItems->count()]) }}</span>
            </div>
            @php
                $grouped = $menuItems->groupBy(fn ($mi) => trim((string) ($mi->product->category ?? '')) !== '' ? $mi->product->category : __('pos.qr_default_category'));
            @endphp
            <div class="space-y-5">
                @foreach($grouped as $category => $items)
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-4 py-2.5 border-b border-gray-100 flex items-center gap-2" style="background: var(--brand);">
                        <span class="text-sm font-bold text-white">{{ $category }}</span>
                    </div>
                    <ul class="divide-y divide-gray-100">
                        @foreach($items as $mi)
                        <li class="px-4 py-3 flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-900 break-words">{{ $mi->product->name }}</p>
                                @if($mi->product->description)
                                    <p class="text-xs text-gray-500 mt-0.5 line-clamp-2">{{ $mi->product->description }}</p>
                                @endif
                            </div>
                            <span class="shrink-0 text-sm font-extrabold text-teal-800">Rs {{ number_format((float) $mi->product->price, ((float) $mi->product->price == (int) $mi->product->price) ? 0 : 2) }}</span>
                        </li>
                        @endforeach
                    </ul>
                </div>
                @endforeach
            </div>
        </section>
        @endif

        <footer class="mt-10 text-center">
            <p class="text-[11px] text-gray-400 font-medium">{{ __('pos.qr_powered_by') }} <span class="font-bold" style="color: var(--brand);">NestPOS</span></p>
        </footer>
    </main>
</body>
</html>
