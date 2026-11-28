<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#092a33">
    <meta name="description" content="See upcoming TaxNest products and choose which previews and release updates you want to receive.">
    <link rel="canonical" href="{{ route('upcoming-products.index') }}">
    <link rel="icon" href="{{ asset('images/brand/taxnest-mark.svg') }}" type="image/svg+xml">
    <title>Upcoming Products | TaxNest</title>
    @include('partials.font-css', ['fontFamilies' => 'playfair-display:600|inter:400,500,600,700'])
    @vite('resources/css/app.css')
    <link rel="stylesheet" href="{{ asset('css/upcoming-products.css') }}">
</head>
<body class="tn-roadmap">
    <header class="tn-roadmap-header">
        <nav class="tn-roadmap-wrap tn-roadmap-nav" aria-label="Main navigation">
            <a href="{{ url('/') }}"><img src="{{ asset('images/brand/taxnest-logo-white.svg') }}" width="132" height="32" alt="TaxNest home"></a>
            <a href="{{ route('erps.landing') }}">Current ERP products</a>
        </nav>
    </header>
    <main class="tn-roadmap-wrap tn-roadmap-main">
        <h1>Upcoming Products</h1>
        <p class="tn-roadmap-intro">See what TaxNest is working on and choose which product updates you would like to receive.</p>

        @if(session('interest_status'))
            <p class="tn-roadmap-notice" role="status">{{ session('interest_status') }}</p>
        @endif
        @if($errors->any())
            <p class="tn-roadmap-notice tn-roadmap-error" role="alert">{{ $errors->first() }}</p>
        @endif

        <div class="tn-roadmap-grid">
            @forelse($products as $product)
                <article id="{{ $product->slug }}" class="tn-roadmap-card" aria-labelledby="{{ $product->slug }}-title">
                    @include('public.upcoming-products.concept', ['concept' => $product->concept])
                    <div class="tn-roadmap-copy">
                        <p class="tn-roadmap-status">{{ \App\Models\UpcomingProduct::STATUSES[$product->status] }}</p>
                        <h2 id="{{ $product->slug }}-title">{{ $product->name }}</h2>
                        <p>{{ $product->introduction }}</p>

                        @if($product->existing_product_path)
                            <p><a class="tn-roadmap-link" href="{{ $product->existing_product_path }}">View the existing product</a></p>
                        @endif

                        <dl class="tn-roadmap-details">
                            <div>
                                <dt>Benefits</dt>
                                <dd><ul>@foreach($product->benefits as $item)<li>{{ $item }}</li>@endforeach</ul></dd>
                            </div>
                            <div>
                                <dt>Proposed features</dt>
                                <dd><ul>@foreach($product->features as $item)<li>{{ $item }}</li>@endforeach</ul></dd>
                            </div>
                            <div>
                                <dt>Target industries</dt>
                                <dd>{{ implode(' · ', $product->industries) }}</dd>
                            </div>
                        </dl>

                        @unless($product->status === 'temporarily_unavailable')
                            <details class="tn-roadmap-interest">
                                <summary>Choose how to stay informed</summary>
                                <form method="POST" action="{{ route('upcoming-products.interest', $product->slug) }}">
                                    @csrf
                                    <fieldset class="tn-roadmap-purposes">
                                        <legend>What would you like?</legend>
                                        @foreach(\App\Models\UpcomingProductInterest::PURPOSES as $purpose => $label)
                                            <label><input type="radio" name="purpose" value="{{ $purpose }}" required> {{ $label }}</label>
                                        @endforeach
                                    </fieldset>
                                    <label class="tn-roadmap-email">Email address
                                        <input type="email" name="email" autocomplete="email" maxlength="254" required>
                                    </label>
                                    <label class="tn-roadmap-consent">
                                        <input type="checkbox" name="consent" value="1" required>
                                        <span>I agree to receive email for the option I selected. I can withdraw using the link in the confirmation email. <a href="{{ route('privacy') }}">Privacy policy</a></span>
                                    </label>
                                    <button type="submit">Send confirmation link</button>
                                </form>
                            </details>
                        @endunless
                    </div>
                </article>
            @empty
                <p>No upcoming products are currently listed.</p>
            @endforelse
        </div>
    </main>
    <x-site-footer />
</body>
</html>
