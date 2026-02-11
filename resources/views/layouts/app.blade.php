<!DOCTYPE html>
@php
    $isDarkMode = auth()->check() && auth()->user()->dark_mode;
    $isInternal = auth()->check() && auth()->user()->company_id && optional(\App\Models\Company::find(auth()->user()->company_id))->is_internal_account;
    if ($isInternal && is_null(auth()->user()->dark_mode)) {
        auth()->user()->update(['dark_mode' => true]);
        $isDarkMode = true;
    }
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $isDarkMode ? 'dark' : '' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">

        <title>{{ config('app.name', 'TaxNest') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <script>if(document.documentElement.classList.contains('dark')){document.documentElement.style.colorScheme='dark';}</script>
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-50 dark:bg-gray-900 transition-colors duration-200">
            @include('layouts.navigation')

            @if(auth()->check() && auth()->user()->company_id)
                @php $currentCompany = \App\Models\Company::find(auth()->user()->company_id); @endphp
                @if($currentCompany && $currentCompany->company_status === 'pending')
                <div class="bg-amber-500 text-white text-center py-2 px-4 text-sm font-medium">
                    Your company registration is pending approval. Some features may be limited.
                </div>
                @endif
                @if($currentCompany && $currentCompany->company_status === 'suspended')
                <div class="bg-red-600 text-white text-center py-2 px-4 text-sm font-medium">
                    Your company has been suspended. Please contact support.
                </div>
                @endif
            @endif

            @isset($header)
                <header class="bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700">
                    <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            @if(session('success'))
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
                    <div class="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-3 rounded-lg">
                        {{ session('success') }}
                    </div>
                </div>
            @endif

            @if(session('error'))
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
                    <div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg">
                        {{ session('error') }}
                    </div>
                </div>
            @endif

            <main>
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
