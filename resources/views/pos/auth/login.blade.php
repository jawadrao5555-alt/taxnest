<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>NestPOS — Login</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            @keyframes float {
                0%, 100% { transform: translateY(0px); }
                50% { transform: translateY(-6px); }
            }
            .animate-float { animation: float 6s ease-in-out infinite; }
        </style>
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gradient-to-br from-purple-50 via-white to-violet-50">
            <div class="relative">
                <div class="absolute -inset-4 bg-gradient-to-r from-purple-400/20 to-violet-400/20 rounded-full blur-xl"></div>
                <a href="/pos" class="relative">
                    <div class="h-16 w-16 rounded-2xl bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center shadow-xl shadow-purple-500/20">
                        <svg class="h-9 w-9 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                    </div>
                </a>
            </div>
            <p class="mt-3 text-lg font-bold text-gray-800 tracking-tight">NestPOS</p>

            <div class="w-full sm:max-w-md mt-6 px-6 py-6 bg-white/80 backdrop-blur-xl shadow-2xl shadow-purple-500/10 overflow-hidden rounded-2xl border border-white/50 animate-float">
                @if(session('status'))
                <div class="mb-4 font-medium text-sm text-green-600">{{ session('status') }}</div>
                @endif

                <div class="mb-6 text-center">
                    <h2 class="text-xl font-bold" style="background: linear-gradient(135deg, #7c3aed, #a855f7); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Welcome Back</h2>
                    <p class="text-sm text-gray-500 mt-1">Login to your NestPOS account</p>
                </div>

                <form method="POST" action="/pos/login" id="posLoginForm">
                    @csrf
                    <div>
                        <label for="login" class="block font-medium text-sm text-gray-700">Email / Phone / Username / NTN</label>
                        <input id="login" class="block mt-1 w-full border-gray-300 focus:border-purple-500 focus:ring-purple-500 rounded-md shadow-sm" type="text" name="login" value="{{ old('login') }}" required autofocus autocomplete="username" placeholder="Enter email, phone, username or NTN">
                        @error('login')
                        <p class="text-sm text-red-600 mt-2">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="mt-4">
                        <label for="password" class="block font-medium text-sm text-gray-700">Password</label>
                        <input id="password" class="block mt-1 w-full border-gray-300 focus:border-purple-500 focus:ring-purple-500 rounded-md shadow-sm" type="password" name="password" required autocomplete="current-password">
                        @error('password')
                        <p class="text-sm text-red-600 mt-2">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-center justify-between mt-4">
                        <label for="remember_me" class="inline-flex items-center cursor-pointer group">
                            <input id="remember_me" type="checkbox" class="rounded-md border-gray-300 text-purple-600 shadow-sm focus:ring-purple-500 focus:ring-offset-0 w-4 h-4" name="remember">
                            <span class="ms-2 text-sm text-gray-600 group-hover:text-gray-800 transition">Remember me</span>
                        </label>
                    </div>

                    <div class="mt-6">
                        <button type="submit" class="w-full flex justify-center py-2.5 px-4 bg-gradient-to-r from-purple-500 to-violet-600 text-white font-bold rounded-xl shadow-lg shadow-purple-500/25 hover:shadow-xl hover:shadow-purple-500/30 hover:from-purple-600 hover:to-violet-700 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 transition-all duration-200">
                            Log in
                        </button>
                    </div>

                    <div class="mt-5 pt-5 border-t border-gray-200/60 text-center">
                        <p class="text-sm text-gray-500">
                            Don't have an account?
                            <a href="/pos/register" class="font-semibold text-purple-600 hover:text-purple-700 transition">Sign Up</a>
                        </p>
                    </div>
                </form>
            </div>
        </div>
    </body>
</html>
