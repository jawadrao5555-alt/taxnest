<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>NestPOS — Sign Up</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            @keyframes float { 0%, 100% { transform: translateY(0px); } 50% { transform: translateY(-6px); } }
            @keyframes pulse-glow { 0%, 100% { opacity: 0.4; } 50% { opacity: 0.7; } }
            .animate-float { animation: float 6s ease-in-out infinite; }
        </style>
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col items-center justify-center relative overflow-hidden py-8" style="background: linear-gradient(135deg, #1e1b4b 0%, #312e81 25%, #4c1d95 50%, #581c87 75%, #3b0764 100%);">
            <div class="absolute inset-0 overflow-hidden">
                <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-purple-500/20 rounded-full blur-3xl" style="animation: pulse-glow 4s ease-in-out infinite;"></div>
                <div class="absolute bottom-1/4 right-1/4 w-80 h-80 bg-violet-400/15 rounded-full blur-3xl" style="animation: pulse-glow 6s ease-in-out infinite 1s;"></div>
                <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-purple-600/10 rounded-full blur-3xl"></div>
            </div>

            <div class="relative z-10 w-full max-w-md px-4">
                <div class="text-center mb-6">
                    <a href="/pos" class="inline-block">
                        <div class="w-16 h-16 mx-auto rounded-2xl bg-gradient-to-br from-purple-400 to-violet-600 flex items-center justify-center shadow-2xl shadow-purple-500/30 ring-1 ring-white/10">
                            <svg class="h-9 w-9 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                        </div>
                    </a>
                    <h1 class="mt-4 text-2xl font-extrabold text-white tracking-tight">NestPOS</h1>
                    <p class="text-purple-200/60 text-sm mt-1">Enterprise Point of Sale</p>
                </div>

                <div class="rounded-2xl overflow-hidden" style="background: rgba(255,255,255,0.07); backdrop-filter: blur(24px); border: 1px solid rgba(255,255,255,0.12); box-shadow: 0 25px 60px -12px rgba(0,0,0,0.5), 0 0 0 1px rgba(139, 92, 246, 0.1);">
                    @if($errors->any())
                    <div class="px-6 pt-5">
                        <div class="font-medium text-sm text-red-400 bg-red-500/10 border border-red-500/20 rounded-lg px-3 py-2">
                            @foreach($errors->all() as $error)
                                <p>{{ $error }}</p>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    <div class="px-6 pt-6 pb-2 text-center">
                        <h2 class="text-lg font-bold text-white">Create POS Account</h2>
                        <p class="text-sm text-purple-200/50 mt-1">Register your business for NestPOS</p>
                    </div>

                    <form method="POST" action="/pos/register" class="px-6 pb-6 pt-4 space-y-4">
                        @csrf

                        <div class="pt-1 pb-2">
                            <p class="text-xs font-semibold text-purple-300/50 uppercase tracking-wider">Business Information</p>
                        </div>

                        <div>
                            <label for="company_name" class="block text-sm font-medium text-purple-100/70 mb-1.5">Business Name</label>
                            <input id="company_name" type="text" name="company_name" value="{{ old('company_name') }}" required placeholder="e.g. ABC Restaurant" class="w-full rounded-xl text-sm text-white placeholder-purple-300/30 transition" style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); padding: 11px 14px; outline: none;" onfocus="this.style.borderColor='rgba(139,92,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(139,92,246,0.15)';" onblur="this.style.borderColor='rgba(255,255,255,0.12)'; this.style.boxShadow='none';">
                        </div>

                        <div>
                            <label for="company_ntn" class="block text-sm font-medium text-purple-100/70 mb-1.5">NTN (National Tax Number)</label>
                            <input id="company_ntn" type="text" name="company_ntn" value="{{ old('company_ntn') }}" required placeholder="1234567-8" class="w-full rounded-xl text-sm text-white placeholder-purple-300/30 transition" style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); padding: 11px 14px; outline: none;" onfocus="this.style.borderColor='rgba(139,92,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(139,92,246,0.15)';" onblur="this.style.borderColor='rgba(255,255,255,0.12)'; this.style.boxShadow='none';">
                        </div>

                        <div style="border-top: 1px solid rgba(255,255,255,0.08); margin-top: 8px; padding-top: 12px;">
                            <p class="text-xs font-semibold text-purple-300/50 uppercase tracking-wider">Admin Account</p>
                        </div>

                        <div>
                            <label for="name" class="block text-sm font-medium text-purple-100/70 mb-1.5">Your Name</label>
                            <input id="name" type="text" name="name" value="{{ old('name') }}" required placeholder="Full name" class="w-full rounded-xl text-sm text-white placeholder-purple-300/30 transition" style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); padding: 11px 14px; outline: none;" onfocus="this.style.borderColor='rgba(139,92,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(139,92,246,0.15)';" onblur="this.style.borderColor='rgba(255,255,255,0.12)'; this.style.boxShadow='none';">
                        </div>

                        <div>
                            <label for="email" class="block text-sm font-medium text-purple-100/70 mb-1.5">Email</label>
                            <input id="email" type="email" name="email" value="{{ old('email') }}" required placeholder="you@example.com" class="w-full rounded-xl text-sm text-white placeholder-purple-300/30 transition" style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); padding: 11px 14px; outline: none;" onfocus="this.style.borderColor='rgba(139,92,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(139,92,246,0.15)';" onblur="this.style.borderColor='rgba(255,255,255,0.12)'; this.style.boxShadow='none';">
                        </div>

                        <div>
                            <label for="phone" class="block text-sm font-medium text-purple-100/70 mb-1.5">Phone <span class="text-purple-300/30">(Optional)</span></label>
                            <input id="phone" type="text" name="phone" value="{{ old('phone') }}" placeholder="03001234567" class="w-full rounded-xl text-sm text-white placeholder-purple-300/30 transition" style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); padding: 11px 14px; outline: none;" onfocus="this.style.borderColor='rgba(139,92,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(139,92,246,0.15)';" onblur="this.style.borderColor='rgba(255,255,255,0.12)'; this.style.boxShadow='none';">
                        </div>

                        <div>
                            <label for="password" class="block text-sm font-medium text-purple-100/70 mb-1.5">Password</label>
                            <input id="password" type="password" name="password" required autocomplete="new-password" placeholder="Min 8 characters" class="w-full rounded-xl text-sm text-white placeholder-purple-300/30 transition" style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); padding: 11px 14px; outline: none;" onfocus="this.style.borderColor='rgba(139,92,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(139,92,246,0.15)';" onblur="this.style.borderColor='rgba(255,255,255,0.12)'; this.style.boxShadow='none';">
                        </div>

                        <div>
                            <label for="password_confirmation" class="block text-sm font-medium text-purple-100/70 mb-1.5">Confirm Password</label>
                            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" placeholder="Re-enter password" class="w-full rounded-xl text-sm text-white placeholder-purple-300/30 transition" style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); padding: 11px 14px; outline: none;" onfocus="this.style.borderColor='rgba(139,92,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(139,92,246,0.15)';" onblur="this.style.borderColor='rgba(255,255,255,0.12)'; this.style.boxShadow='none';">
                        </div>

                        <button type="submit" class="w-full py-3 rounded-xl text-sm font-bold text-white transition-all duration-200" style="background: linear-gradient(135deg, #7c3aed, #a855f7); box-shadow: 0 4px 20px rgba(124, 58, 237, 0.4);" onmouseover="this.style.boxShadow='0 6px 28px rgba(124, 58, 237, 0.55)'; this.style.transform='translateY(-1px)';" onmouseout="this.style.boxShadow='0 4px 20px rgba(124, 58, 237, 0.4)'; this.style.transform='translateY(0)';">
                            Create Account
                        </button>

                        <div class="pt-3 border-t border-white/10 text-center">
                            <p class="text-sm text-purple-200/40">
                                Already have an account?
                                <a href="/pos/login" class="font-semibold text-purple-300 hover:text-white transition">Log in</a>
                            </p>
                        </div>
                    </form>
                </div>

                <div class="mt-5 text-center">
                    <a href="/pos" class="text-xs text-purple-300/30 hover:text-purple-200/60 transition">&larr; Back to NestPOS</a>
                </div>
            </div>
        </div>
    </body>
</html>
