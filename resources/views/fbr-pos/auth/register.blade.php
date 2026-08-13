<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="stylesheet" href="{{ asset('css/mobile.css?v=2.6') }}">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>FBR POS — {{ __('pos.auth_signup_title') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            @keyframes float { 0%, 100% { transform: translateY(0px); } 50% { transform: translateY(-6px); } }
            @keyframes pulse-glow { 0%, 100% { opacity: 0.4; } 50% { opacity: 0.7; } }
            .animate-float { animation: float 6s ease-in-out infinite; }
            .cat-card { background: rgba(255,255,255,0.7); border: 1px solid rgba(59,130,246,0.15); }
            .cat-card:hover { background: rgba(255,255,255,0.9); border-color: rgba(59,130,246,0.3); }
            .cat-active { background: rgba(59,130,246,0.12) !important; border-color: rgba(59,130,246,0.5) !important; box-shadow: 0 0 12px rgba(59,130,246,0.2); }
        </style>
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col items-center justify-center relative overflow-hidden py-8" style="background: linear-gradient(135deg, #dbeafe 0%, #93c5fd 25%, #60a5fa 50%, #3b82f6 75%, #2563eb 100%);">
            <div class="absolute inset-0 overflow-hidden">
                <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-blue-500/20 rounded-full blur-3xl" style="animation: pulse-glow 4s ease-in-out infinite;"></div>
                <div class="absolute bottom-1/4 right-1/4 w-80 h-80 bg-sky-400/15 rounded-full blur-3xl" style="animation: pulse-glow 6s ease-in-out infinite 1s;"></div>
                <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-blue-600/10 rounded-full blur-3xl"></div>
            </div>

            <div class="relative z-10 w-full max-w-lg px-4">
                <div class="text-center mb-6">
                    <a href="/fbr-pos-landing" class="inline-block">
                        <div class="w-16 h-16 mx-auto rounded-2xl bg-gradient-to-br from-blue-400 to-blue-700 flex items-center justify-center shadow-2xl shadow-blue-500/30 ring-1 ring-white/20">
                            <svg class="h-9 w-9 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                        </div>
                    </a>
                    <h1 class="mt-4 text-2xl font-extrabold text-gray-900 tracking-tight">FBR POS</h1>
                    <p class="text-blue-800/60 text-sm mt-1">{{ __('pos.auth_fbr_pos_system') }}</p>
                    <div class="mt-3">
                        <x-guest-language-picker :action="route('fbrpos.guest-language')" theme="light" />
                    </div>
                </div>

                <div class="rounded-2xl overflow-hidden" style="background: rgba(255,255,255,0.85); backdrop-filter: blur(24px); border: 1px solid rgba(255,255,255,0.6); box-shadow: 0 25px 60px -12px rgba(0,0,0,0.15), 0 0 0 1px rgba(37, 99, 235, 0.08);">
                    @if($errors->any())
                    <div class="px-6 pt-5">
                        <div class="font-medium text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2">
                            @foreach($errors->all() as $error)
                                <p>{{ $error }}</p>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    <div class="px-6 pt-6 pb-2 text-center">
                        <h2 class="text-lg font-bold text-gray-900">{{ __('pos.auth_create_fbr_account') }}</h2>
                        <p class="text-sm text-gray-500 mt-1">{{ __('pos.auth_register_business_fbr') }}</p>
                    </div>

                    <form method="POST" action="/fbr-pos/register" class="px-6 pb-6 pt-4 space-y-4" x-data="{ posType: '{{ old('pos_type', 'retail') }}' }">
                        @csrf

                        <div class="pt-1 pb-2">
                            <p class="text-xs font-semibold text-blue-600/60 uppercase tracking-wider">{{ __('pos.auth_select_business_type') }}</p>
                        </div>

                        <input type="hidden" name="pos_type" :value="posType">

                        <div class="grid grid-cols-5 gap-2">
                            <label class="relative cursor-pointer" @click="posType = 'retail'">
                                <div class="flex flex-col items-center gap-1 py-2.5 px-1 rounded-xl text-center transition-all cat-card" :class="posType === 'retail' ? 'cat-active' : ''">
                                    <span class="text-xl">🛒</span>
                                    <span class="text-[10px] font-semibold text-gray-700 leading-tight">{{ __('pos.auth_bt_retail') }}</span>
                                </div>
                            </label>
                            <label class="relative cursor-pointer" @click="posType = 'restaurant'">
                                <div class="flex flex-col items-center gap-1 py-2.5 px-1 rounded-xl text-center transition-all cat-card" :class="posType === 'restaurant' ? 'cat-active' : ''">
                                    <span class="text-xl">🍽️</span>
                                    <span class="text-[10px] font-semibold text-gray-700 leading-tight">{{ __('pos.auth_bt_restaurant') }}</span>
                                </div>
                            </label>
                            <label class="relative cursor-pointer" @click="posType = 'pharmacy'">
                                <div class="flex flex-col items-center gap-1 py-2.5 px-1 rounded-xl text-center transition-all cat-card" :class="posType === 'pharmacy' ? 'cat-active' : ''">
                                    <span class="text-xl">💊</span>
                                    <span class="text-[10px] font-semibold text-gray-700 leading-tight">{{ __('pos.auth_bt_pharmacy') }}</span>
                                </div>
                            </label>
                            <label class="relative cursor-pointer" @click="posType = 'grocery'">
                                <div class="flex flex-col items-center gap-1 py-2.5 px-1 rounded-xl text-center transition-all cat-card" :class="posType === 'grocery' ? 'cat-active' : ''">
                                    <span class="text-xl">🏪</span>
                                    <span class="text-[10px] font-semibold text-gray-700 leading-tight">{{ __('pos.auth_bt_grocery') }}</span>
                                </div>
                            </label>
                            <label class="relative cursor-pointer" @click="posType = 'clothing'">
                                <div class="flex flex-col items-center gap-1 py-2.5 px-1 rounded-xl text-center transition-all cat-card" :class="posType === 'clothing' ? 'cat-active' : ''">
                                    <span class="text-xl">👔</span>
                                    <span class="text-[10px] font-semibold text-gray-700 leading-tight">{{ __('pos.auth_bt_clothing') }}</span>
                                </div>
                            </label>
                            <label class="relative cursor-pointer" @click="posType = 'electronics'">
                                <div class="flex flex-col items-center gap-1 py-2.5 px-1 rounded-xl text-center transition-all cat-card" :class="posType === 'electronics' ? 'cat-active' : ''">
                                    <span class="text-xl">📱</span>
                                    <span class="text-[10px] font-semibold text-gray-700 leading-tight">{{ __('pos.auth_bt_electronics') }}</span>
                                </div>
                            </label>
                            <label class="relative cursor-pointer" @click="posType = 'hardware'">
                                <div class="flex flex-col items-center gap-1 py-2.5 px-1 rounded-xl text-center transition-all cat-card" :class="posType === 'hardware' ? 'cat-active' : ''">
                                    <span class="text-xl">🔧</span>
                                    <span class="text-[10px] font-semibold text-gray-700 leading-tight">{{ __('pos.auth_bt_hardware') }}</span>
                                </div>
                            </label>
                            <label class="relative cursor-pointer" @click="posType = 'salon'">
                                <div class="flex flex-col items-center gap-1 py-2.5 px-1 rounded-xl text-center transition-all cat-card" :class="posType === 'salon' ? 'cat-active' : ''">
                                    <span class="text-xl">💇</span>
                                    <span class="text-[10px] font-semibold text-gray-700 leading-tight">{{ __('pos.auth_bt_salon') }}</span>
                                </div>
                            </label>
                            <label class="relative cursor-pointer" @click="posType = 'autoparts'">
                                <div class="flex flex-col items-center gap-1 py-2.5 px-1 rounded-xl text-center transition-all cat-card" :class="posType === 'autoparts' ? 'cat-active' : ''">
                                    <span class="text-xl">🚗</span>
                                    <span class="text-[10px] font-semibold text-gray-700 leading-tight">{{ __('pos.auth_bt_autoparts') }}</span>
                                </div>
                            </label>
                            <label class="relative cursor-pointer" @click="posType = 'bakery'">
                                <div class="flex flex-col items-center gap-1 py-2.5 px-1 rounded-xl text-center transition-all cat-card" :class="posType === 'bakery' ? 'cat-active' : ''">
                                    <span class="text-xl">🧁</span>
                                    <span class="text-[10px] font-semibold text-gray-700 leading-tight">{{ __('pos.auth_bt_bakery') }}</span>
                                </div>
                            </label>
                        </div>

                        <div class="pt-1 pb-1" style="border-top: 1px solid rgba(59,130,246,0.1);">
                            <p class="text-xs font-semibold text-blue-600/60 uppercase tracking-wider">{{ __('pos.auth_business_info') }}</p>
                        </div>

                        <div>
                            <label for="company_name" class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('pos.auth_business_name') }}</label>
                            <input id="company_name" type="text" name="company_name" value="{{ old('company_name') }}" required placeholder="{{ __('pos.auth_ph_business_name_fbr') }}" class="w-full rounded-xl text-sm text-gray-900 placeholder-gray-400 transition" style="background: rgba(255,255,255,0.7); border: 1px solid rgba(59,130,246,0.2); padding: 11px 14px; outline: none;" onfocus="this.style.borderColor='rgba(59,130,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(59,130,246,0.15)';" onblur="this.style.borderColor='rgba(59,130,246,0.2)'; this.style.boxShadow='none';">
                        </div>

                        <div>
                            <label for="company_ntn" class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('pos.auth_ntn_label') }}</label>
                            <input id="company_ntn" type="text" name="company_ntn" value="{{ old('company_ntn') }}" required placeholder="1234567-8" class="w-full rounded-xl text-sm text-gray-900 placeholder-gray-400 transition" style="background: rgba(255,255,255,0.7); border: 1px solid rgba(59,130,246,0.2); padding: 11px 14px; outline: none;" onfocus="this.style.borderColor='rgba(59,130,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(59,130,246,0.15)';" onblur="this.style.borderColor='rgba(59,130,246,0.2)'; this.style.boxShadow='none';">
                        </div>

                        <div>
                            <label for="company_cnic" class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('pos.auth_cnic_label') }} <span class="text-gray-400 font-normal">{{ __('pos.auth_optional_dash') }}</span></label>
                            <input id="company_cnic" type="text" name="company_cnic" value="{{ old('company_cnic') }}" placeholder="{{ __('pos.auth_ph_cnic_optional') }}" class="w-full rounded-xl text-sm text-gray-900 placeholder-gray-400 transition" style="background: rgba(255,255,255,0.7); border: 1px solid rgba(59,130,246,0.2); padding: 11px 14px; outline: none;" onfocus="this.style.borderColor='rgba(59,130,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(59,130,246,0.15)';" onblur="this.style.borderColor='rgba(59,130,246,0.2)'; this.style.boxShadow='none';">
                            @error('company_cnic')
                            <p class="text-xs text-red-600 mt-1.5">{{ $message }}</p>
                            @enderror
                        </div>

                        <div style="border-top: 1px solid rgba(59,130,246,0.1); margin-top: 8px; padding-top: 12px;">
                            <p class="text-xs font-semibold text-blue-600/60 uppercase tracking-wider">{{ __('pos.auth_admin_account') }}</p>
                        </div>

                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('pos.auth_your_name') }}</label>
                            <input id="name" type="text" name="name" value="{{ old('name') }}" required placeholder="{{ __('pos.auth_ph_full_name') }}" class="w-full rounded-xl text-sm text-gray-900 placeholder-gray-400 transition" style="background: rgba(255,255,255,0.7); border: 1px solid rgba(59,130,246,0.2); padding: 11px 14px; outline: none;" onfocus="this.style.borderColor='rgba(59,130,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(59,130,246,0.15)';" onblur="this.style.borderColor='rgba(59,130,246,0.2)'; this.style.boxShadow='none';">
                        </div>

                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('pos.auth_email') }}</label>
                            <input id="email" type="email" name="email" value="{{ old('email') }}" required placeholder="you@example.com" class="w-full rounded-xl text-sm text-gray-900 placeholder-gray-400 transition" style="background: rgba(255,255,255,0.7); border: 1px solid rgba(59,130,246,0.2); padding: 11px 14px; outline: none;" onfocus="this.style.borderColor='rgba(59,130,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(59,130,246,0.15)';" onblur="this.style.borderColor='rgba(59,130,246,0.2)'; this.style.boxShadow='none';">
                        </div>

                        <div>
                            <label for="phone" class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('pos.auth_phone') }} <span class="text-gray-400">{{ __('pos.auth_optional_paren') }}</span></label>
                            <input id="phone" type="text" name="phone" value="{{ old('phone') }}" placeholder="03001234567" class="w-full rounded-xl text-sm text-gray-900 placeholder-gray-400 transition" style="background: rgba(255,255,255,0.7); border: 1px solid rgba(59,130,246,0.2); padding: 11px 14px; outline: none;" onfocus="this.style.borderColor='rgba(59,130,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(59,130,246,0.15)';" onblur="this.style.borderColor='rgba(59,130,246,0.2)'; this.style.boxShadow='none';">
                        </div>

                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('pos.auth_password') }}</label>
                            <input id="password" type="password" name="password" required autocomplete="new-password" placeholder="{{ __('pos.auth_ph_min8') }}" class="w-full rounded-xl text-sm text-gray-900 placeholder-gray-400 transition" style="background: rgba(255,255,255,0.7); border: 1px solid rgba(59,130,246,0.2); padding: 11px 14px; outline: none;" onfocus="this.style.borderColor='rgba(59,130,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(59,130,246,0.15)';" onblur="this.style.borderColor='rgba(59,130,246,0.2)'; this.style.boxShadow='none';">
                        </div>

                        <div>
                            <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1.5">{{ __('pos.auth_confirm_password') }}</label>
                            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" placeholder="{{ __('pos.auth_ph_reenter') }}" class="w-full rounded-xl text-sm text-gray-900 placeholder-gray-400 transition" style="background: rgba(255,255,255,0.7); border: 1px solid rgba(59,130,246,0.2); padding: 11px 14px; outline: none;" onfocus="this.style.borderColor='rgba(59,130,246,0.5)'; this.style.boxShadow='0 0 0 3px rgba(59,130,246,0.15)';" onblur="this.style.borderColor='rgba(59,130,246,0.2)'; this.style.boxShadow='none';">
                        </div>

                        <button type="submit" class="w-full py-3 rounded-xl text-sm font-bold text-white transition-all duration-200" style="background: linear-gradient(135deg, #2563eb, #3b82f6); box-shadow: 0 4px 20px rgba(37, 99, 235, 0.4);" onmouseover="this.style.boxShadow='0 6px 28px rgba(37, 99, 235, 0.55)'; this.style.transform='translateY(-1px)';" onmouseout="this.style.boxShadow='0 4px 20px rgba(37, 99, 235, 0.4)'; this.style.transform='translateY(0)';">
                            {{ __('pos.auth_create_account') }}
                        </button>

                        <div class="pt-3 border-t border-gray-200 text-center">
                            <p class="text-sm text-gray-500">
                                {{ __('pos.auth_have_account') }}
                                <a href="/fbr-pos/login" class="font-semibold text-blue-600 hover:text-blue-800 transition">{{ __('pos.auth_log_in') }}</a>
                            </p>
                        </div>
                    </form>
                </div>

                <div class="mt-5 text-center">
                    <a href="/fbr-pos-landing" class="text-xs text-blue-700/50 hover:text-blue-900 transition">&larr; {{ __('pos.auth_back_to_fbr') }}</a>
                </div>
            </div>
        </div>
        <x-whatsapp-support />
    </body>
</html>
