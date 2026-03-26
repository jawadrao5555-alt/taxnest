<x-guest-layout>
    <div class="mb-6 text-center">
        <div class="flex justify-center mb-3">
            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center shadow-lg shadow-emerald-500/20">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </div>
        </div>
        <h2 class="text-xl font-bold" style="background: linear-gradient(135deg, #0ea5e9, #6366f1); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">Create Your Account</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Register your company and become its admin</p>
    </div>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <div class="mt-4">
            <x-input-label for="company_name" value="Company Name" />
            <x-text-input id="company_name" class="block mt-1 w-full" type="text" name="company_name" :value="old('company_name')" required placeholder="e.g. ABC Trading Pvt Ltd" />
            <x-input-error :messages="$errors->get('company_name')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="company_ntn" value="NTN (National Tax Number)" />
            <x-text-input id="company_ntn" class="block mt-1 w-full" type="text" name="company_ntn" :value="old('company_ntn')" required placeholder="1234567-8" />
            <x-input-error :messages="$errors->get('company_ntn')" class="mt-2" />
        </div>

        <hr class="my-4 border-gray-200 dark:border-gray-700/60">

        <div>
            <x-input-label for="name" :value="__('Your Name')" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="username" value="Username" />
            <x-text-input id="username" class="block mt-1 w-full" type="text" name="username" :value="old('username')" placeholder="e.g. ahmed_trading (optional)" autocomplete="username" />
            <p class="text-xs text-gray-400 mt-1">You can use this to login instead of email</p>
            <x-input-error :messages="$errors->get('username')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="email" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="phone" value="Phone Number" />
            <x-text-input id="phone" class="block mt-1 w-full" type="text" name="phone" :value="old('phone')" placeholder="e.g. 03001234567 (optional)" />
            <p class="text-xs text-gray-400 mt-1">You can use this to login instead of email</p>
            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="mt-6">
            <button type="submit" class="w-full flex justify-center py-2.5 px-4 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-bold rounded-xl shadow-lg shadow-emerald-500/25 hover:shadow-xl hover:shadow-emerald-500/30 hover:from-emerald-600 hover:to-teal-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 transition-all duration-200">
                {{ __('Register') }}
            </button>
        </div>

        <div class="mt-5 text-center">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Already have an account?
                <a href="{{ route('login') }}" class="font-semibold text-emerald-600 hover:text-emerald-700 transition">Log In</a>
            </p>
        </div>
    </form>
</x-guest-layout>
