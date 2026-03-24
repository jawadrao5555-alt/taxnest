<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'TaxNest') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            @keyframes float {
                0%, 100% { transform: translateY(0px); }
                50% { transform: translateY(-6px); }
            }
        </style>
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gradient-to-br from-emerald-50 via-white to-sky-50">
            <div class="relative">
                <div class="absolute -inset-4 bg-gradient-to-r from-emerald-400/20 to-teal-400/20 rounded-full blur-xl"></div>
                <a href="/" class="relative">
                    <div class="h-16 w-16 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-xl shadow-emerald-500/20">
                        <svg class="h-9 w-9 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z" />
                        </svg>
                    </div>
                </a>
            </div>
            <p class="mt-3 text-lg font-bold text-gray-800 tracking-tight">TaxNest</p>

            <div class="w-full sm:max-w-md mt-6 px-6 py-6 bg-white/80 backdrop-blur-xl shadow-2xl shadow-emerald-500/10 overflow-hidden rounded-2xl border border-white/50 ">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>