<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>FBR POS — Login</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            @keyframes float { 0%, 100% { transform: translateY(0px); } 50% { transform: translateY(-8px); } }
            @keyframes pulse-glow { 0%, 100% { opacity: 0.4; } 50% { opacity: 0.7; } }
            .animate-float { animation: float 6s ease-in-out infinite; }
        </style>
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col items-center justify-center relative overflow-hidden" style="background: linear-gradient(135deg, #dbeafe 0%, #93c5fd 25%, #60a5fa 50%, #3b82f6 75%, #2563eb 100%);">
            <div class="absolute inset-0 overflow-hidden">
                <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-blue-500/20 rounded-full blur-3xl" style="animation: pulse-glow 4s ease-in-out infinite;"></div>
                <div class="absolute bottom-1/4 right-1/4 w-80 h-80 bg-sky-400/15 rounded-full blur-3xl" style="animation: pulse-glow 6s ease-in-out infinite 1s;"></div>
                <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-blue-600/10 rounded-full blur-3xl"></div>
            </div>

            <div class="relative z-10">
                <div class="text-center mb-6">
                    <a href="/fbr-pos-landing" class="inline-block">
                        <div class="w-16 h-16 mx-auto rounded-2xl bg-gradient-to-br from-blue-400 to-blue-700 flex items-center justify-center shadow-2xl shadow-blue-500/30 ring-1 ring-white/10">
                            <svg class="h-9 w-9 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                        </div>
                    </a>
                    <h1 class="mt-4 text-2xl font-extrabold text-gray-900 tracking-tight">FBR POS</h1>
                    <p class="text-blue-800/60 text-sm mt-1">FBR Point of Sale System</p>
                </div>

                <div class="w-full max-w-md mx-auto px-4">
                    <div class="rounded-2xl overflow-hidden" style="background: rgba(255,255,255,0.85); backdrop-filter: blur(24px); border: 1px solid rgba(255,255,255,0.6); box-shadow: 0 25px 60px -12px rgba(0,0,0,0.15), 0 0 0 1px rgba(37, 99, 235, 0.08);">
                        @if(session('status'))
                        <div class="px-6 pt-5">
                            <div class="font-medium text-sm text-green-400 bg-green-500/10 border border-green-500/20 rounded-lg px-3 py-2">{{ session('status') }}</div>
                        </div>
                        @endif
                        @if(session('error'))
                        <div class="px-6 pt-5">
                            <div class="font-medium text-sm text-red-400 bg-red-500/10 border border-red-500/20 rounded-lg px-3 py-2">{{ session('error') }}</div>
                        </div>
                        @endif

                        <div class="px-6 pt-6 pb-2 text-center">
                            <h2 class="text-lg font-bold text-gray-900">Welcome Back</h2>
                            <p class="text-sm text-gray-500 mt-1">Sign in to your FBR POS account</p>
                        </div>

                        <form method="POST" action="/fbr-pos/login" class="px-6 pb-6 pt-4 space-y-4">
                            @csrf
                            <div>
                                <label for="login" class="block text-sm font-medium text-gray-700 mb-1.5">Email / Phone / Username / NTN / CNIC</label>
                                <input id="login" type="text" name="login" value="{{ old('login') }}" required autofocus autocomplete="username" placeholder="Enter your credential" class="w-full rounded-xl text-sm text-gray-900 placeholder-gray-400 transition" style="background: rgba(255,255,255,0.7); border: 1px solid rgba(59,130,246,0.2); padding: 11px 14px; outline: none;" onfocus="this.style.borderColor='rgba(59,130,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(59,130,246,0.15)';" onblur="this.style.borderColor='rgba(59,130,246,0.2)'; this.style.boxShadow='none';">
                                @error('login')
                                <p class="text-sm text-red-400 mt-1.5">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="password" class="block text-sm font-medium text-gray-700 mb-1.5">Password</label>
                                <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="Enter your password" class="w-full rounded-xl text-sm text-gray-900 placeholder-gray-400 transition" style="background: rgba(255,255,255,0.7); border: 1px solid rgba(59,130,246,0.2); padding: 11px 14px; outline: none;" onfocus="this.style.borderColor='rgba(59,130,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(59,130,246,0.15)';" onblur="this.style.borderColor='rgba(59,130,246,0.2)'; this.style.boxShadow='none';">
                                @error('password')
                                <p class="text-sm text-red-400 mt-1.5">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="flex items-center justify-between">
                                <label class="flex items-center">
                                    <input id="remember_me" type="checkbox" name="remember" class="rounded border-blue-300 bg-white/50 text-blue-500 focus:ring-blue-500 focus:ring-offset-0 w-4 h-4">
                                    <span class="ml-2 text-sm text-gray-600">Remember me</span>
                                </label>
                            </div>

                            <button type="submit" class="w-full py-3 rounded-xl text-sm font-bold text-white transition-all duration-200" style="background: linear-gradient(135deg, #2563eb, #3b82f6); box-shadow: 0 4px 20px rgba(37, 99, 235, 0.4);" onmouseover="this.style.boxShadow='0 6px 28px rgba(37, 99, 235, 0.55)'; this.style.transform='translateY(-1px)';" onmouseout="this.style.boxShadow='0 4px 20px rgba(37, 99, 235, 0.4)'; this.style.transform='translateY(0)';">
                                Sign In
                            </button>

                            <div class="pt-3 border-t border-gray-200 text-center">
                                <p class="text-sm text-gray-500">
                                    Don't have an account?
                                    <a href="/fbr-pos/register" class="font-semibold text-blue-600 hover:text-blue-800 transition">Sign Up</a>
                                </p>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="mt-5 text-center space-x-4">
                    <a href="/digital-invoice" class="text-xs text-blue-700/50 hover:text-blue-900 transition">Digital Invoice (FBR) Portal</a>
                    <a href="/pos" class="text-xs text-blue-700/50 hover:text-blue-900 transition">PRA POS Portal</a>
                </div>
            </div>
        </div>
    </body>
</html>
