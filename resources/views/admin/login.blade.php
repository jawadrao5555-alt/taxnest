<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login - TaxNest</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @keyframes pulse-glow { 0%, 100% { opacity: 0.3; } 50% { opacity: 0.6; } }
        @keyframes float { 0%, 100% { transform: translateY(0px); } 50% { transform: translateY(-6px); } }
    </style>
</head>
<body class="h-full font-sans antialiased">
    <div class="min-h-screen flex items-center justify-center relative overflow-hidden" style="background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 30%, #1e293b 60%, #0f172a 100%);">
        <div class="absolute inset-0 overflow-hidden">
            <div class="absolute top-1/3 left-1/3 w-96 h-96 bg-indigo-500/10 rounded-full blur-3xl" style="animation: pulse-glow 5s ease-in-out infinite;"></div>
            <div class="absolute bottom-1/3 right-1/3 w-80 h-80 bg-cyan-500/8 rounded-full blur-3xl" style="animation: pulse-glow 7s ease-in-out infinite 2s;"></div>
        </div>

        <div class="relative z-10 w-full max-w-md px-4">
            <div class="text-center mb-6">
                <div class="w-14 h-14 mx-auto rounded-2xl bg-gradient-to-br from-indigo-500 to-cyan-500 flex items-center justify-center shadow-2xl shadow-indigo-500/20 ring-1 ring-white/10">
                    <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                </div>
                <h1 class="mt-4 text-2xl font-extrabold text-white tracking-tight">TaxNest</h1>
                <p class="text-indigo-200/40 text-sm mt-1">Super Admin Panel</p>
            </div>

            <div style="background: rgba(255,255,255,0.04); backdrop-filter: blur(24px); border: 1px solid rgba(255,255,255,0.08); box-shadow: 0 25px 60px -12px rgba(0,0,0,0.5); animation: float 6s ease-in-out infinite;" class="rounded-2xl overflow-hidden">
                <div class="px-7 pt-7 pb-2">
                    <h2 class="text-lg font-bold text-white">Admin Login</h2>
                    <p class="text-sm text-indigo-200/40 mt-1">Secure access to management panel</p>
                </div>

                @if($errors->any())
                <div class="mx-7 mt-3">
                    <div class="bg-red-500/10 border border-red-500/20 text-red-400 rounded-lg px-4 py-2.5 text-sm">
                        {{ $errors->first() }}
                    </div>
                </div>
                @endif

                <form method="POST" action="/admin/login" class="px-7 pb-7 pt-4 space-y-4">
                    @csrf
                    <div>
                        <label class="block text-sm font-medium text-indigo-100/60 mb-1.5">Email</label>
                        <input type="email" name="email" value="{{ old('email') }}" required autofocus placeholder="admin@taxnest.com" class="w-full rounded-xl text-sm text-white placeholder-indigo-300/20 transition" style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); padding: 11px 14px; outline: none;" onfocus="this.style.borderColor='rgba(99,102,241,0.5)'; this.style.boxShadow='0 0 0 3px rgba(99,102,241,0.12)';" onblur="this.style.borderColor='rgba(255,255,255,0.1)'; this.style.boxShadow='none';">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-indigo-100/60 mb-1.5">Password</label>
                        <input type="password" name="password" required placeholder="Enter password" autocomplete="current-password" class="w-full rounded-xl text-sm text-white placeholder-indigo-300/20 transition" style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); padding: 11px 14px; outline: none;" onfocus="this.style.borderColor='rgba(99,102,241,0.5)'; this.style.boxShadow='0 0 0 3px rgba(99,102,241,0.12)';" onblur="this.style.borderColor='rgba(255,255,255,0.1)'; this.style.boxShadow='none';">
                    </div>
                    <div class="flex items-center">
                        <input type="checkbox" name="remember" id="remember" class="rounded bg-white/5 border-white/10 text-indigo-500 focus:ring-indigo-500 focus:ring-offset-0 w-4 h-4">
                        <label for="remember" class="ml-2 text-sm text-indigo-200/40">Remember me</label>
                    </div>
                    <button type="submit" class="w-full py-3 rounded-xl text-sm font-bold text-white transition-all duration-200" style="background: linear-gradient(135deg, #6366f1, #06b6d4); box-shadow: 0 4px 20px rgba(99, 102, 241, 0.35);" onmouseover="this.style.boxShadow='0 6px 28px rgba(99, 102, 241, 0.5)'; this.style.transform='translateY(-1px)';" onmouseout="this.style.boxShadow='0 4px 20px rgba(99, 102, 241, 0.35)'; this.style.transform='translateY(0)';">
                        Sign In
                    </button>
                </form>
            </div>

            <div class="mt-5 text-center">
                <a href="/" class="text-xs text-indigo-300/25 hover:text-indigo-200/50 transition">Back to TaxNest</a>
            </div>
        </div>
    </div>
</body>
</html>
