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

            <div class="w-full sm:max-w-md mt-6 px-6 py-6 overflow-hidden rounded-2xl animate-float" style="background: rgba(255,255,255,0.92); backdrop-filter: blur(20px); box-shadow: 0 25px 50px -12px rgba(124, 58, 237, 0.15), 0 0 0 1px rgba(139, 92, 246, 0.15); border: 1px solid rgba(139, 92, 246, 0.2);">
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
                        <input id="login" class="block mt-1 w-full border-gray-300 focus:border-purple-500 focus:ring-purple-500 rounded-md shadow-sm" type="text" name="login" value="{{ old('login') }}" required autofocus autocomplete="username" placeholder="Enter email, phone, username or NTN" style="border: 1px solid #d1d5db; border-radius: 8px; padding: 10px 14px; width: 100%; font-size: 14px; box-sizing: border-box;">
                        @error('login')
                        <p class="text-sm text-red-600 mt-2">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="mt-4">
                        <label for="password" class="block font-medium text-sm text-gray-700">Password</label>
                        <input id="password" class="block mt-1 w-full border-gray-300 focus:border-purple-500 focus:ring-purple-500 rounded-md shadow-sm" type="password" name="password" required autocomplete="current-password" style="border: 1px solid #d1d5db; border-radius: 8px; padding: 10px 14px; width: 100%; font-size: 14px; box-sizing: border-box;">
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
                        <button type="submit" style="background: linear-gradient(135deg, #7c3aed, #8b5cf6); color: #ffffff; font-weight: 700; width: 100%; padding: 12px 16px; border-radius: 12px; border: none; font-size: 15px; cursor: pointer; box-shadow: 0 4px 14px rgba(124, 58, 237, 0.35); letter-spacing: 0.3px; transition: all 0.2s;" onmouseover="this.style.boxShadow='0 6px 20px rgba(124, 58, 237, 0.45)'; this.style.background='linear-gradient(135deg, #6d28d9, #7c3aed)';" onmouseout="this.style.boxShadow='0 4px 14px rgba(124, 58, 237, 0.35)'; this.style.background='linear-gradient(135deg, #7c3aed, #8b5cf6)';">
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

            <div class="w-full sm:max-w-md mt-4 px-6">
                <a href="/admin/login" style="display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 10px 16px; border-radius: 10px; border: 1px solid rgba(99, 102, 241, 0.3); background: rgba(99, 102, 241, 0.06); color: #6366f1; font-size: 13px; font-weight: 600; text-decoration: none; transition: all 0.2s;" onmouseover="this.style.background='rgba(99, 102, 241, 0.12)'; this.style.borderColor='rgba(99, 102, 241, 0.5)';" onmouseout="this.style.background='rgba(99, 102, 241, 0.06)'; this.style.borderColor='rgba(99, 102, 241, 0.3)';">
                    <svg style="width: 16px; height: 16px;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    SaaS Admin Login
                </a>
            </div>

            <div class="mt-3 text-center">
                <a href="/" class="text-xs text-gray-400 hover:text-gray-600 transition">Digital Invoice (FBR) Portal</a>
            </div>
        </div>
    </body>
</html>
