<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Code - TaxNest</title>
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
                    <svg class="w-7 h-7 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </div>
                <h2 class="text-xl font-bold text-white">Check Your Email</h2>
                <p class="text-sm text-emerald-200/50 mt-2">We sent a 6-digit code and reset link to<br><span class="text-emerald-300 font-medium">{{ $email }}</span></p>
            </div>

            @if (session('status'))
            <div class="mb-4 p-3 rounded-lg" style="background: rgba(52,211,153,0.1); border: 1px solid rgba(52,211,153,0.2);">
                <p class="text-sm text-emerald-300">{{ session('status') }}</p>
            </div>
            @endif

            <div class="mb-4 p-3 rounded-lg" style="background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08);">
                <p class="text-xs text-emerald-200/60 text-center">You can either <strong class="text-emerald-300">click the link</strong> in the email or enter the <strong class="text-emerald-300">6-digit code</strong> below</p>
            </div>

            <form method="POST" action="{{ route('password.verify.otp.submit') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="email" value="{{ $email }}">

                <div>
                    <label class="block text-sm font-medium text-emerald-100/70 mb-1.5">Enter 6-Digit Code</label>
                    <input type="text" name="otp" required autofocus maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" class="w-full px-4 py-4 rounded-xl text-2xl text-center font-bold tracking-[0.5em] text-white placeholder-emerald-300/30 transition" style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); outline: none; letter-spacing: 0.5em;" placeholder="000000" onfocus="this.style.borderColor='rgba(52,211,153,0.5)'; this.style.boxShadow='0 0 0 3px rgba(52,211,153,0.12)';" onblur="this.style.borderColor='rgba(255,255,255,0.12)'; this.style.boxShadow='none';">
                    @error('otp')
                    <p class="text-sm text-red-400 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="w-full py-3 rounded-xl text-sm font-bold text-white transition-all duration-200" style="background: linear-gradient(135deg, #059669, #14b8a6); box-shadow: 0 4px 20px rgba(5, 150, 105, 0.35);" onmouseover="this.style.boxShadow='0 6px 28px rgba(5, 150, 105, 0.5)'; this.style.transform='translateY(-1px)';" onmouseout="this.style.boxShadow='0 4px 20px rgba(5, 150, 105, 0.35)'; this.style.transform='translateY(0)';">
                    Verify Code
                </button>
            </form>

            <div class="mt-6 flex items-center justify-between">
                <a href="{{ route('password.request') }}" class="text-sm text-emerald-300/70 hover:text-emerald-200 transition">
                    &larr; Try different email
                </a>
                <form method="POST" action="{{ route('password.email') }}" class="inline">
                    @csrf
                    <input type="hidden" name="email" value="{{ $email }}">
                    <button type="submit" class="text-sm text-emerald-300/70 hover:text-emerald-200 transition">
                        Resend Code
                    </button>
                </form>
            </div>

            <div class="mt-4 text-center">
                <p class="text-xs text-emerald-200/30">Code and link expire in 15 minutes</p>
            </div>
        </div>
    </div>
</body>
</html>
