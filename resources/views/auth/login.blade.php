<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <div class="mb-6 text-center">
        <div class="flex justify-center mb-3">
            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/20">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </div>
        </div>
        <h2 class="text-xl font-bold" style="background: linear-gradient(135deg, #0ea5e9, #6366f1); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Welcome Back</h2>
        <p class="text-sm text-gray-500 mt-1">Login to your TaxNest account</p>
    </div>

    <form method="POST" action="{{ route('login') }}" id="loginForm">
        @csrf

        <div>
            <x-input-label for="login" value="Email / Phone / Username / CNIC / NTN / FBR Reg" />
            <x-text-input id="login" class="block mt-1 w-full" type="text" name="login" :value="old('login')" required autofocus autocomplete="username" placeholder="Enter email, phone, username, CNIC, NTN or FBR Reg" />
            <x-input-error :messages="$errors->get('login')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between mt-4">
            <label for="remember_me" class="inline-flex items-center cursor-pointer group">
                <input id="remember_me" type="checkbox" class="rounded-md border-gray-300 text-emerald-600 shadow-sm focus:ring-emerald-500 focus:ring-offset-0 w-4 h-4" name="remember">
                <span class="ms-2 text-sm text-gray-600 group-hover:text-gray-800 transition">{{ __('Remember me') }}</span>
            </label>
            @if (Route::has('password.request'))
                <a class="text-sm font-medium text-emerald-600 hover:text-emerald-700 transition" href="{{ route('password.request') }}">
                    {{ __('Forgot password?') }}
                </a>
            @endif
        </div>

        <div class="mt-6">
            <button type="submit" class="w-full flex justify-center py-2.5 px-4 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-bold rounded-xl shadow-lg shadow-emerald-500/25 hover:shadow-xl hover:shadow-emerald-500/30 hover:from-emerald-600 hover:to-teal-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 transition-all duration-200">
                {{ __('Log in') }}
            </button>
        </div>

        <div class="mt-5 pt-5 border-t border-gray-200/60 text-center">
            <p class="text-xs text-gray-400 mb-2.5">You can use any of these to login:</p>
            <div class="flex justify-center gap-2 flex-wrap">
                <span class="inline-flex items-center gap-1 text-xs text-gray-500 px-2.5 py-1 rounded-full bg-gray-50/80 backdrop-blur-sm border border-gray-200/40">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    Email
                </span>
                <span class="inline-flex items-center gap-1 text-xs text-gray-500 px-2.5 py-1 rounded-full bg-gray-50/80 backdrop-blur-sm border border-gray-200/40">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                    Phone
                </span>
                <span class="inline-flex items-center gap-1 text-xs text-gray-500 px-2.5 py-1 rounded-full bg-gray-50/80 backdrop-blur-sm border border-gray-200/40">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    Username
                </span>
                <span class="inline-flex items-center gap-1 text-xs text-gray-500 px-2.5 py-1 rounded-full bg-gray-50/80 backdrop-blur-sm border border-gray-200/40">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0"/></svg>
                    CNIC / NTN
                </span>
                <span class="inline-flex items-center gap-1 text-xs text-gray-500 px-2.5 py-1 rounded-full bg-gray-50/80 backdrop-blur-sm border border-gray-200/40">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    FBR Registration
                </span>
            </div>
        </div>

        <div class="mt-5 text-center">
            <p class="text-sm text-gray-500">
                Don't have an account?
                <a href="{{ route('register') }}" class="font-semibold text-emerald-600 hover:text-emerald-700 transition">Sign Up Free</a>
            </p>
        </div>
    </form>
</x-guest-layout>