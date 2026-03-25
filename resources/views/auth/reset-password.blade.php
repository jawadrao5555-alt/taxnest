<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - TaxNest</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex items-center justify-center" style="background: linear-gradient(135deg, #064e3b 0%, #065f46 30%, #047857 60%, #0d9488 100%);">
    <div class="w-full max-w-md mx-4">
        <div class="text-center mb-8">
            <a href="/" class="inline-flex items-center gap-2">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background: linear-gradient(135deg, #059669, #14b8a6);">
                    <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </div>
                <span class="text-xl font-bold text-white">TaxNest</span>
            </a>
        </div>

        <div class="rounded-2xl p-8" style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); backdrop-filter: blur(20px);">
            <div class="text-center mb-6">
                <div class="w-14 h-14 rounded-full mx-auto mb-4 flex items-center justify-center" style="background: rgba(52,211,153,0.15);">
                    <svg class="w-7 h-7 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </div>
                <h2 class="text-xl font-bold text-white">Set New Password</h2>
                <p class="text-sm text-emerald-200/50 mt-2">Create a strong password for your account</p>
            </div>

            <form method="POST" action="{{ route('password.store') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="token" value="{{ $request->route('token') }}">

                <div>
                    <label class="block text-sm font-medium text-emerald-100/70 mb-1.5">Email Address</label>
                    <input type="email" name="email" value="{{ old('email', $request->email) }}" required autofocus class="w-full px-4 py-2.5 rounded-xl text-sm text-white placeholder-emerald-300/30 transition" style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); outline: none;" onfocus="this.style.borderColor='rgba(52,211,153,0.5)'; this.style.boxShadow='0 0 0 3px rgba(52,211,153,0.12)';" onblur="this.style.borderColor='rgba(255,255,255,0.12)'; this.style.boxShadow='none';">
                    @error('email')
                    <p class="text-sm text-red-400 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-emerald-100/70 mb-1.5">New Password</label>
                    <input type="password" name="password" required autocomplete="new-password" class="w-full px-4 py-2.5 rounded-xl text-sm text-white placeholder-emerald-300/30 transition" style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); outline: none;" placeholder="Min 8 characters" onfocus="this.style.borderColor='rgba(52,211,153,0.5)'; this.style.boxShadow='0 0 0 3px rgba(52,211,153,0.12)';" onblur="this.style.borderColor='rgba(255,255,255,0.12)'; this.style.boxShadow='none';">
                    @error('password')
                    <p class="text-sm text-red-400 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-emerald-100/70 mb-1.5">Confirm Password</label>
                    <input type="password" name="password_confirmation" required autocomplete="new-password" class="w-full px-4 py-2.5 rounded-xl text-sm text-white placeholder-emerald-300/30 transition" style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); outline: none;" placeholder="Re-enter password" onfocus="this.style.borderColor='rgba(52,211,153,0.5)'; this.style.boxShadow='0 0 0 3px rgba(52,211,153,0.12)';" onblur="this.style.borderColor='rgba(255,255,255,0.12)'; this.style.boxShadow='none';">
                    @error('password_confirmation')
                    <p class="text-sm text-red-400 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="w-full py-3 rounded-xl text-sm font-bold text-white transition-all duration-200" style="background: linear-gradient(135deg, #059669, #14b8a6); box-shadow: 0 4px 20px rgba(5, 150, 105, 0.35);" onmouseover="this.style.boxShadow='0 6px 28px rgba(5, 150, 105, 0.5)'; this.style.transform='translateY(-1px)';" onmouseout="this.style.boxShadow='0 4px 20px rgba(5, 150, 105, 0.35)'; this.style.transform='translateY(0)';">
                    Reset Password
                </button>
            </form>

            <div class="mt-6 text-center">
                <a href="{{ route('login') }}" class="text-sm text-emerald-300/70 hover:text-emerald-200 transition">
                    &larr; Back to Login
                </a>
            </div>
        </div>
    </div>
</body>
</html>
