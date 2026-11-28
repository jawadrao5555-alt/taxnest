<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#092a33">
    <meta name="description" content="Browse TaxNest clients by Digital Invoice, PRA POS, FBR POS and ERP.">
    <link rel="icon" type="image/svg+xml" href="/images/brand/taxnest-mark.svg">
    <title>Our Clients | TaxNest</title>
    @include('partials.font-css', ['fontFamilies' => 'playfair-display:600|inter:400,500,600,700'])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .directory {
            --directory-petrol: #092a33;
            --directory-ivory: #f7f8f5;
            --directory-line: #dce5e1;
            --directory-teal: #0d6259;
            background: var(--directory-ivory);
            color: var(--directory-petrol);
            font-family: Inter, system-ui, sans-serif;
        }
        .directory a:focus-visible { outline: 2px solid #075952; outline-offset: 4px; }
        .directory :is(header, footer) a:focus-visible { outline-color: #4fd6bd; }
        .directory footer :is(h4, p, ul) { color: #b8cfcb; }
        .directory-wrap { width: min(100% - 2rem, 74rem); margin-inline: auto; }
        .directory-header { background: var(--directory-petrol); color: #eef6f3; }
        .directory-nav { display: flex; align-items: center; justify-content: space-between; gap: 1rem; min-height: 4.75rem; }
        .directory-nav img { width: 8.25rem; height: auto; }
        .directory-home { padding-block: .65rem; font-size: .875rem; font-weight: 600; }
        .directory-home:hover { color: #9de8d7; }
        .directory-main { padding-block: clamp(2.2rem, 5vw, 4rem); }
        .directory-title, .directory-group h2 { font-family: 'Playfair Display', Georgia, serif; font-weight: 600; }
        .directory-title { margin: 0 0 1.35rem; font-size: clamp(2.45rem, 5vw, 4.4rem); line-height: 1.08; letter-spacing: -.045em; }
        .directory-filter { display: flex; flex-wrap: wrap; gap: .55rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--directory-line); }
        .directory-filter a, .directory-page-link {
            display: inline-flex;
            align-items: center;
            min-height: 2.75rem;
            padding: .45rem .8rem;
            border: 1px solid #b7ccc4;
            border-radius: .45rem;
            background: #fff;
            color: var(--directory-teal);
            font-size: .875rem;
            font-weight: 700;
        }
        .directory-filter a:hover, .directory-page-link:hover { background: #e5f3ee; }
        .directory-filter a[aria-current] { border-color: var(--directory-teal); background: var(--directory-teal); color: #fff; }
        .directory-group { margin-top: 1.75rem; }
        .directory-group h2 { margin: 0 0 .85rem; font-size: clamp(1.45rem, 3vw, 2.05rem); }
        .directory-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .65rem; }
        .directory-card {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: .65rem;
            min-height: 7.4rem;
            padding: clamp(.8rem, 2vw, 1.2rem);
            border: 1px solid var(--directory-line);
            border-radius: .7rem;
            background: #fff;
            overflow-wrap: anywhere;
        }
        .directory-card img { width: 7rem; max-width: 100%; height: 2.5rem; object-fit: contain; object-position: left center; }
        .directory-card-name { font-size: .9rem; line-height: 1.38; font-weight: 700; }
        .directory-card-product { margin-top: .2rem; color: #526769; font-size: .78rem; }
        .directory-pages { display: flex; flex-wrap: wrap; align-items: center; gap: .65rem; margin-top: 1.85rem; }
        .directory-empty { margin-top: 1.75rem; color: #526769; }
        @media (min-width: 44rem) { .directory-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (min-width: 63rem) { .directory-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
    </style>
</head>
<body class="directory">
    <header class="directory-header">
        <nav class="directory-wrap directory-nav" aria-label="Main navigation">
            <a href="{{ url('/') }}"><img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" alt="TaxNest home" width="132" height="32"></a>
            <a class="directory-home" href="{{ url('/') }}">Back to homepage</a>
        </nav>
    </header>

    <main class="directory-wrap directory-main">
        <h1 class="directory-title">Our Clients</h1>
        <nav class="directory-filter" aria-label="Filter clients by product">
            <a href="{{ route('website-clients.index') }}" @if($product === null) aria-current="page" @endif>All products</a>
            @foreach($products as $key => $label)
                <a href="{{ route('website-clients.index', ['product' => $key]) }}" @if($product === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>

        @forelse($groups as $key => $group)
            <section class="directory-group" aria-labelledby="directory-group-{{ $key }}">
                <h2 id="directory-group-{{ $key }}">{{ $products[$key] }}</h2>
                <ul class="directory-grid">
                    @foreach($group as $client)
                        <li class="directory-card">
                            @if(\App\Services\WebsiteClientLogo::hasPublicLogo($client))
                                <img src="{{ route('website-clients.logo', [$client->id, $client->revision]) }}" alt="" width="112" height="40" loading="lazy">
                            @endif
                            <div>
                                <p class="directory-card-name">{{ $client->display_name }}</p>
                                @if($client->product === 'standalone')
                                    <p class="directory-card-product">{{ \App\Models\WebsiteClient::PRODUCTS[$client->product] }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <p class="directory-empty">No clients to show{{ $product !== null ? ' for '.$products[$product] : '' }}{{ $clients->currentPage() > 1 ? ' on this page' : ' yet' }}.</p>
        @endforelse

        @if($clients->hasPages())
            <nav class="directory-pages" aria-label="Client directory pages">
                @unless($clients->onFirstPage())
                    <a class="directory-page-link" href="{{ $clients->previousPageUrl() }}" rel="prev">Previous</a>
                @endunless
                <span>Page {{ $clients->currentPage() }}</span>
                @if($clients->hasMorePages())
                    <a class="directory-page-link" href="{{ $clients->nextPageUrl() }}" rel="next">Next</a>
                @endif
            </nav>
        @endif
    </main>

    <x-site-footer />
    <x-whatsapp-support />
</body>
</html>
