@props(['title'])
@php($schoolLocale = \App\Support\PosLocale::normalize(session(\App\Support\PosLocale::SESSION_KEY)))
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" @if(app()->getLocale() === 'ur') dir="rtl" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ __('school_erp.title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.urdu-font')
    <style>
        body { margin: 0; background: #f0f7f6; color: #162d2d; font-family: system-ui, sans-serif; line-height: 1.5; }
        header.school-header { background: #153c3c; color: white; }
        .school-wrap { width: min(calc(100% - 2rem), 74rem); margin-inline: auto; }
        .school-header .school-wrap, .school-nav { display: flex; align-items: center; flex-wrap: wrap; gap: .75rem 1.25rem; }
        .school-header .school-wrap { justify-content: space-between; min-height: 4.25rem; padding-block: .65rem; }
        .school-brand, .school-nav a { color: inherit; }
        .school-brand { font-size: 1.2rem; font-weight: 750; text-decoration: none; }
        .school-brand span { font-size: .85rem; margin-inline-start: .5rem; font-weight: 500; color: #bce7e1; }
        main.school-wrap { padding-block: 1.75rem 3rem; }
        .school-card { background: white; border: 1px solid #c9dedb; border-radius: .9rem; padding: clamp(1rem, 3vw, 1.5rem); margin-block: 1rem; min-width: 0; }
        .school-narrow { max-width: 34rem; margin-inline: auto; }
        .school-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 19rem), 1fr)); gap: 1rem; }
        .school-grid > .school-card { margin-block: 0; }
        .school-card h2 { margin: 0 0 .65rem; font-size: 1.2rem; font-weight: 720; }
        .school-title { font-size: clamp(1.55rem, 4vw, 2.2rem); font-weight: 760; margin: 0; }
        .school-form { display: grid; gap: .75rem; }
        .school-form label { display: grid; gap: .25rem; font-size: .925rem; font-weight: 600; }
        .school-form input, .school-form select { width: 100%; box-sizing: border-box; color: #162d2d; background: white; border: 1px solid #8bacaa; border-radius: .4rem; padding: .55rem .65rem; font: inherit; }
        [dir=rtl] .school-form select { background-position: left .5rem center; padding-inline-end: 2rem; }
        .school-button { justify-self: start; background: #12625c; color: white; padding: .5rem .85rem; border-radius: .4rem; border: 1px solid transparent; font-weight: 650; text-decoration: none; }
        .school-button:hover { background: #174a47; }
        .school-header button { color: white; border: 1px solid #67928d; border-radius: .4rem; padding: .2rem .5rem; }
        .school-header button[aria-pressed=true] { background: #e6f7f3; color: #133e38; }
        .school-header button:focus-visible, .school-button:focus-visible { outline: 2px solid #579c92; outline-offset: 3px; }
        .school-note { display: block; margin-block: .75rem; padding: .65rem .8rem; border-radius: .4rem; color: #144a3d; background: #e4f6ed; overflow-wrap: anywhere; }
        .school-error { color: #7a2424; background: #fce9e7; }
        .school-list { margin-block: .75rem; padding-inline-start: 1.25rem; list-style: disc; }
        .school-scroll { overflow-x: auto; }
        .school-scroll table { width: 100%; text-align: start; border-collapse: collapse; }
        .school-scroll td, .school-scroll th { text-align: start; border-bottom: 1px solid #d6e5e3; padding: .65rem .55rem; }
        .school-footer { padding-block: 1rem; font-size: .875rem; color: #355957; }
    </style>
    @stack('head')
</head>
<body>
    <header class="school-header">
        <div class="school-wrap">
            <a class="school-brand" href="{{ auth('schoolerp')->check() ? route('school-erp.dashboard') : route('school-erp.login') }}">
                {{ \App\Support\NestErps::LABEL }} <span>{{ __('school_erp.title') }}</span>
            </a>
            <nav class="school-nav" aria-label="{{ __('school_erp.navigation') }}">
                @auth('schoolerp')
                    <a href="{{ route('school-erp.dashboard') }}">{{ __('school_erp.workspace') }}</a>
                    @if(auth('schoolerp')->user()?->isCompanyAdmin())
                        <a href="{{ route('school-erp.package-checkout') }}">{{ __('school_erp.package') }}</a>
                    @endif
                    <a href="{{ route('two-step.settings', ['panel' => 'schoolerp']) }}">{{ __('school_erp.two_step') }}</a>
                    <a href="{{ route('school-erp.attendance') }}">{{ __('school_erp.attendance') }}</a>
                    <a href="{{ route('school-erp.gradebook') }}">{{ __('school_erp.gradebook') }}</a>
                    @if(Route::has('school-erp.fees'))
                        <a href="{{ route('school-erp.fees') }}">{{ __('school_erp.fees') }}</a>
                    @endif
                    <form method="POST" action="{{ route('school-erp.logout') }}">
                        @csrf
                        <button type="submit">{{ __('school_erp.sign_out') }}</button>
                    </form>
                @endauth
                <form method="POST" action="{{ route('school-erp.language') }}" aria-label="{{ __('school_erp.language') }}">
                    @csrf
                    @foreach(['en' => 'English', 'rur' => 'Roman Urdu', 'ur' => 'اردو'] as $code => $label)
                        <button type="submit" name="language" value="{{ $code }}" aria-pressed="{{ $schoolLocale === $code ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </form>
            </nav>
        </div>
    </header>

    <main class="school-wrap">
        @if(session('status'))
            <p class="school-note" role="status">{{ session('status') }}</p>
        @endif
        @if(session('success'))
            <p class="school-note" role="status">{{ session('success') }}</p>
        @endif
        @if(session('error'))
            <p class="school-note school-error" role="alert">{{ session('error') }}</p>
        @endif
        @if($errors->any())
            <div class="school-note school-error" role="alert">
                <ul class="school-list">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{ $slot }}
    </main>

    <footer class="school-wrap school-footer">
        <a href="{{ route('contact') }}">{{ __('school_erp.support') }}</a>
    </footer>
</body>
</html>
