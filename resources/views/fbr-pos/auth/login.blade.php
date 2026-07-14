<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="stylesheet" href="{{ asset('css/mobile.css?v=2.6') }}">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>FBR POS — Login</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            @keyframes float { 0%, 100% { transform: translateY(0px); } 50% { transform: translateY(-8px); } }
            @keyframes pulse-glow { 0%, 100% { opacity: 0.35; transform: scale(1); } 50% { opacity: 0.75; transform: scale(1.06); } }
            @keyframes shimmer { 0% { background-position: -200% center; } 100% { background-position: 200% center; } }
            @keyframes orbit { 0% { transform: rotate(0deg) translateX(0); } 100% { transform: rotate(360deg) translateX(0); } }
            .animate-float { animation: float 6s ease-in-out infinite; }
            .premium-shine { background: linear-gradient(110deg, transparent 30%, rgba(251,191,36,0.55) 50%, transparent 70%); background-size: 200% 100%; -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; animation: shimmer 3.5s ease-in-out infinite; }
            .glass-card { background: rgba(255,255,255,0.92); backdrop-filter: blur(28px) saturate(180%); -webkit-backdrop-filter: blur(28px) saturate(180%); border: 1px solid rgba(255,255,255,0.65); box-shadow: 0 25px 70px -12px rgba(2,8,30,0.45), 0 0 0 1px rgba(96,165,250,0.18), inset 0 1px 0 rgba(255,255,255,0.6); }
            .input-premium { background: rgba(255,255,255,0.85); border: 1px solid rgba(59,130,246,0.18); padding: 12px 14px; outline: none; transition: all .15s ease; box-shadow: inset 0 1px 2px rgba(0,0,0,0.04); }
            .input-premium:focus { border-color: rgba(37,99,235,0.55); box-shadow: 0 0 0 4px rgba(59,130,246,0.16), inset 0 1px 2px rgba(0,0,0,0.04); background: #fff; }
            .btn-premium { background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 50%, #1e40af 100%); box-shadow: 0 6px 22px -4px rgba(29,78,216,0.55), inset 0 1px 0 rgba(255,255,255,0.22), inset 0 -2px 0 rgba(0,0,0,0.18); position: relative; overflow: hidden; }
            .btn-premium::before { content: ''; position: absolute; inset: 0; background: linear-gradient(110deg, transparent 35%, rgba(255,255,255,0.25) 50%, transparent 65%); background-size: 200% 100%; animation: shimmer 3.5s ease-in-out infinite; pointer-events: none; }
            .btn-premium:hover { transform: translateY(-1px); box-shadow: 0 10px 30px -4px rgba(29,78,216,0.65), inset 0 1px 0 rgba(255,255,255,0.3); }
            .gold-pill { background: linear-gradient(135deg, rgba(251,191,36,0.20), rgba(245,158,11,0.10)); border: 1px solid rgba(251,191,36,0.45); color: #92400e; box-shadow: 0 4px 12px -4px rgba(251,191,36,0.55); }
        </style>
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col items-center justify-center relative overflow-hidden p-4" style="background: radial-gradient(ellipse 1200px 800px at 20% -10%, #1e3a8a 0%, transparent 55%), radial-gradient(ellipse 1000px 700px at 90% 110%, #0c1929 0%, transparent 50%), linear-gradient(135deg, #050b18 0%, #0b1d3d 35%, #1e3a8a 75%, #1d4ed8 100%);">
            {{-- decorative orbs --}}
            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div class="absolute top-[15%] left-[12%] w-96 h-96 rounded-full blur-3xl" style="background: radial-gradient(circle, rgba(96,165,250,0.35), transparent 70%); animation: pulse-glow 5s ease-in-out infinite;"></div>
                <div class="absolute bottom-[10%] right-[10%] w-80 h-80 rounded-full blur-3xl" style="background: radial-gradient(circle, rgba(251,191,36,0.18), transparent 65%); animation: pulse-glow 6s ease-in-out infinite 1.2s;"></div>
                <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[700px] h-[700px] rounded-full blur-3xl" style="background: radial-gradient(circle, rgba(59,130,246,0.10), transparent 60%);"></div>
                {{-- subtle grid pattern --}}
                <div class="absolute inset-0 opacity-[0.04]" style="background-image: linear-gradient(rgba(255,255,255,0.5) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.5) 1px, transparent 1px); background-size: 48px 48px;"></div>
            </div>

            <div class="relative z-10 w-full max-w-md">
                {{-- Brand mark --}}
                <div class="text-center mb-6">
                    <a href="/fbr-pos-landing" class="inline-block relative animate-float">
                        <div class="absolute inset-0 rounded-2xl blur-2xl" style="background: linear-gradient(135deg, #fbbf24, #60a5fa); opacity: 0.55;"></div>
                        <img src="/icons/nest-fbr/icon-192.png" alt="Nest FBR Pos" class="relative w-20 h-20 mx-auto rounded-2xl shadow-2xl ring-2 ring-white/30">
                    </a>
                    <h1 class="mt-5 text-3xl font-black text-white tracking-tight" style="text-shadow: 0 2px 18px rgba(0,0,0,0.45);">
                        Nest <span style="background: linear-gradient(135deg, #fcd34d, #fbbf24); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;">FBR</span> Pos
                    </h1>
                    <p class="text-blue-100/85 text-sm mt-1.5 font-medium">FBR-Integrated Mall-Grade Point of Sale</p>
                    <div class="mt-3 flex justify-center gap-2 flex-wrap">
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black tracking-wider gold-pill">
                            <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 16.8l-6.2 4.5 2.4-7.4L2 9.4h7.6z"/></svg>
                            FBR CERTIFIED
                        </span>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-black tracking-wider bg-white/15 text-white border border-white/25 backdrop-blur">PREMIUM EDITION</span>
                    </div>
                    <div class="mt-4 flex justify-center">
                        <x-pwa-install color="blue" label="Install Nest FBR Pos" />
                    </div>
                </div>

                {{-- Glass card --}}
                <div class="rounded-2xl overflow-hidden glass-card">
                    @if(session('status'))
                    <div class="px-6 pt-5">
                        <div class="text-sm text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg px-3 py-2 font-medium">{{ session('status') }}</div>
                    </div>
                    @endif
                    @if(session('error'))
                    <div class="px-6 pt-5">
                        <div class="text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg px-3 py-2 font-medium">{{ session('error') }}</div>
                    </div>
                    @endif

                    <div class="px-7 pt-7 pb-2 text-center">
                        <h2 class="text-xl font-extrabold text-gray-900 tracking-tight">Welcome Back</h2>
                        <p class="text-[13px] text-gray-500 mt-1">Sign in to your FBR POS account</p>
                    </div>

                    <form method="POST" action="/fbr-pos/login" class="px-7 pb-7 pt-4 space-y-4">
                        @csrf
                        <div>
                            <label for="login" class="block text-[12px] font-bold text-gray-700 mb-1.5 uppercase tracking-wide">Email / Phone / Username / NTN / CNIC</label>
                            <input id="login" type="text" name="login" value="{{ old('login') }}" required autofocus autocomplete="username" placeholder="Enter your credential" class="w-full rounded-xl text-sm text-gray-900 placeholder-gray-400 input-premium">
                            @error('login')<p class="text-xs text-red-600 mt-1.5 font-medium">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="password" class="block text-[12px] font-bold text-gray-700 mb-1.5 uppercase tracking-wide">Password</label>
                            <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="Enter your password" class="w-full rounded-xl text-sm text-gray-900 placeholder-gray-400 input-premium">
                            @error('password')<p class="text-xs text-red-600 mt-1.5 font-medium">{{ $message }}</p>@enderror
                        </div>

                        <div class="flex items-center justify-between">
                            <label class="flex items-center cursor-pointer group">
                                <input id="remember_me" type="checkbox" name="remember" class="rounded border-blue-300 bg-white text-blue-600 focus:ring-blue-500 focus:ring-offset-0 w-4 h-4">
                                <span class="ml-2 text-sm text-gray-600 group-hover:text-gray-900 transition">Remember me</span>
                            </label>
                        </div>

                        <button type="submit" class="btn-premium w-full py-3 rounded-xl text-sm font-black text-white tracking-wide transition-all duration-200">
                            <span class="relative z-10">Sign In Securely</span>
                        </button>

                        <div class="pt-3 border-t border-gray-200 text-center">
                            <p class="text-sm text-gray-500">
                                Don't have an account?
                                <a href="/fbr-pos/register" class="font-bold text-blue-700 hover:text-blue-900 transition">Sign Up</a>
                            </p>
                        </div>
                    </form>
                </div>

                {{-- Trust strip --}}
                <div class="mt-5 grid grid-cols-3 gap-2 text-center">
                    <div class="px-2 py-2 rounded-lg bg-white/8 border border-white/15 backdrop-blur">
                        <div class="text-[10px] font-black text-amber-300">FBR LIVE</div>
                        <div class="text-[9px] text-blue-100/70 mt-0.5">Real-time submit</div>
                    </div>
                    <div class="px-2 py-2 rounded-lg bg-white/8 border border-white/15 backdrop-blur">
                        <div class="text-[10px] font-black text-emerald-300">256-BIT SSL</div>
                        <div class="text-[9px] text-blue-100/70 mt-0.5">Bank-grade</div>
                    </div>
                    <div class="px-2 py-2 rounded-lg bg-white/8 border border-white/15 backdrop-blur">
                        <div class="text-[10px] font-black text-sky-300">PWA READY</div>
                        <div class="text-[9px] text-blue-100/70 mt-0.5">Works offline</div>
                    </div>
                </div>

                <div class="mt-5 text-center space-x-4">
                    <a href="/digital-invoice" class="text-xs text-blue-200/70 hover:text-white transition">Digital Invoice (FBR) Portal</a>
                    <span class="text-blue-200/30">|</span>
                    <a href="/pos" class="text-xs text-blue-200/70 hover:text-white transition">PRA POS Portal</a>
                </div>
            </div>
        </div>
        <x-whatsapp-support />
    </body>
</html>
