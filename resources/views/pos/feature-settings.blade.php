<x-pos-layout>
    @php
        // The business type is chosen at REGISTRATION and is read-only here: it
        // decides which regulator the shop files under, so only a SaaS admin may
        // change it. This step shows what the shop is, not a picker.
        $currentCategory = \App\Services\PosFeatureService::resolveCategory($company);
        $currentMeta = \App\Services\PosFeatureService::presetMeta($currentCategory);
        // ONLY this shop's own preset is shipped into the page (Task 1559). The
        // whole catalogue used to ride along in the wizard's x-data, so every
        // other business type — its name, icon, description and default module
        // set — was readable in Customize even though the card is read-only.
        // A shop sitting on a retired/unknown category still gets a working
        // page: presetMeta() falls back to a real card and the flag map falls
        // back to empty (everything simply lands under "Extra").
        $currentDefaults = \App\Services\PosFeatureService::categoryFlagMap($currentCategory);
        $currentMetaLite = [
            'label' => $currentMeta['label'],
            'description' => $currentMeta['description'],
            'icon' => $currentMeta['icon'],
        ];
        // Flag meta for the wizard, WITHOUT the internal grouping key — that
        // value ('restaurant', 'inventory', ...) is a flag section, not a
        // business type, and the wizard never reads it.
        $flagMetaLite = [];
        foreach (\App\Services\PosFeatureService::ALL_FLAGS as $f) {
            $m = \App\Services\PosFeatureService::flagMeta($f);
            $flagMetaLite[$f] = [
                'label' => $m['label'] ?? $f,
                'description' => $m['description'] ?? '',
                'icon' => $m['icon'] ?? '•',
            ];
        }
        // The amber "this belongs on the other panel" notice fires only when the
        // category is genuinely the OTHER regulator's; a catch-all like
        // 'general' belongs to nobody and must not raise it.
        $onLegacyCategory = \App\Services\PosFeatureService::belongsToOtherPanel($company);
        // Current flag state (resolved) → seed the wizard so re-editing shows live config.
        $flagState = [];
        foreach (\App\Services\PosFeatureService::ALL_FLAGS as $f) { $flagState[$f] = (bool) $features->{$f}; }
        $isFirstTime = $isFirstTime ?? false;
    @endphp

    <style>[x-cloak]{display:none!important}</style>

    <div x-data="posWizard()" x-cloak class="max-w-5xl mx-auto p-4 sm:p-6">

        {{-- ═══════════ WELCOME (first-time only) ═══════════ --}}
        @if($isFirstTime)
        <div class="mb-5 rounded-2xl bg-purple-600 p-5 sm:p-6 text-white shadow-xl">
            <div class="flex items-start gap-3">
                <div class="text-3xl">👋</div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-extrabold mb-1">{{ __('pos.wizard_welcome_title') }}</h1>
                    <p class="text-sm text-white/85 max-w-2xl">{!! __('pos.wizard_welcome_sub_html', ['customize' => '<span class="font-bold">' . e(__('pos.customize_pos')) . '</span>']) !!}</p>
                </div>
            </div>
        </div>
        @endif

        @if(session('success'))
            <div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 text-sm text-emerald-800 dark:text-emerald-200 font-semibold flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                {{ session('success') }}
            </div>
        @endif

        {{-- ═══════════ PROGRESS STRIP ═══════════ --}}
        <div class="mb-6 flex items-center gap-2 sm:gap-3">
            <template x-for="(s, i) in steps" :key="s.n">
                <div class="flex items-center gap-2 sm:gap-3 flex-1 last:flex-none">
                    <button type="button" @click="goTo(s.n)"
                        class="flex items-center gap-2 group focus:outline-none"
                        :class="step >= s.n ? '' : 'opacity-50'">
                        <span class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-extrabold border-2 transition flex-shrink-0"
                            :class="step > s.n ? 'bg-emerald-500 border-emerald-500 text-white' : (step === s.n ? 'bg-purple-600 border-purple-600 text-white' : 'border-gray-300 dark:border-gray-600 text-gray-400')">
                            <template x-if="step > s.n"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg></template>
                            <span x-show="step <= s.n" x-text="s.n"></span>
                        </span>
                        <span class="text-xs sm:text-sm font-bold hidden sm:inline"
                            :class="step === s.n ? 'text-purple-700 dark:text-purple-300' : 'text-gray-500 dark:text-gray-400'"
                            x-text="s.label"></span>
                    </button>
                    <div x-show="i < steps.length - 1" class="flex-1 h-0.5 rounded-full transition" :class="step > s.n ? 'bg-emerald-400' : 'bg-gray-200 dark:bg-gray-700'"></div>
                </div>
            </template>
        </div>

        <form method="POST" action="{{ route('pos.features.update') }}">
            @csrf
            {{-- Task 1393 marker: proves this form was FRESHLY rendered, so the handler
                 may safely rebuild the feature-flag map and the kitchen checkboxes from
                 checkbox presence. A stale cached copy of this page lacks the marker and
                 leaves those blocks untouched — an outdated form and a form with
                 everything unticked are otherwise identical on the wire. --}}
            <input type="hidden" name="fs_present" value="1">
            <input type="hidden" name="use_universal_pos" value="1">

            {{-- ════════════════════ STEP 1 — BUSINESS TYPE (read-only) ════════════════════ --}}
            <div x-show="step === 1" x-transition.opacity>
                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 sm:p-6 shadow-sm">
                    <h2 class="text-lg font-extrabold text-gray-900 dark:text-white">{{ __('pos.business_type') }}</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('pos.business_type_locked_note') }}</p>

                    <div class="rounded-xl border-2 border-purple-200 dark:border-purple-800 bg-purple-50/40 dark:bg-purple-900/10 p-4 flex items-start gap-3">
                        <span class="text-3xl leading-none">{{ $currentMeta['icon'] }}</span>
                        <div>
                            <p class="text-base font-extrabold text-gray-900 dark:text-white">{{ $currentMeta['label'] }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400 leading-snug">{{ $currentMeta['description'] }}</p>
                        </div>
                    </div>

                    @if($onLegacyCategory)
                        {{-- This shop signed up on a goods category before the services/goods
                             split. Nothing is switched off for it — it is simply told where
                             that kind of business actually belongs. --}}
                        <div class="mt-4 rounded-xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 px-3.5 py-3">
                            <p class="text-xs font-bold text-amber-900 dark:text-amber-200">{{ __('pos.legacy_goods_category_title') }}</p>
                            <p class="mt-1 text-[11px] leading-relaxed text-amber-800 dark:text-amber-300/90">{{ __('pos.legacy_goods_category_body') }}</p>
                        </div>
                    @endif

                    <p class="mt-4 text-[11px] leading-relaxed text-gray-500 dark:text-gray-400">{{ __('pos.business_type_features_hint') }}</p>
                </div>

                <div class="mt-4 flex items-center justify-between">
                    <a href="{{ route('pos.customize') }}" class="inline-flex items-center px-4 py-2.5 text-sm font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">{{ __('pos.cancel') }}</a>
                    <button type="button" @click="next()" class="inline-flex items-center gap-2 px-6 py-2.5 text-sm font-bold rounded-lg bg-purple-600 hover:bg-purple-700 text-white shadow-sm">
                        {{ __('pos.next_choose_features') }}
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </button>
                </div>
            </div>

            {{-- ════════════════════ STEP 2 — FEATURES ════════════════════ --}}
            <div x-show="step === 2" x-transition.opacity>
                <div class="mb-3 flex items-center gap-2 text-sm">
                    <span class="text-2xl" x-text="selectedPresetMeta.icon"></span>
                    <div>
                        <p class="font-extrabold text-gray-900 dark:text-white">{{ __('pos.features_for') }} <span class="text-purple-700 dark:text-purple-300" x-text="selectedPresetMeta.label"></span></p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.recommended_already_on') }}</p>
                    </div>
                </div>

                {{-- Restaurant module plan-lock notice (Business+ since Aug 2026) --}}
                <div x-show="restaurantLocked" x-cloak class="mb-4 p-3.5 rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 flex items-start gap-2.5">
                    <span class="text-lg leading-none">🔒</span>
                    <div>
                        @if(!empty($restaurantTrialEnded))
                            <p class="text-sm font-bold text-amber-900 dark:text-amber-200">{{ __('pos.trial_ended_restaurant_off') }}</p>
                            <p class="text-[11px] text-amber-800 dark:text-amber-300/90 leading-snug">{!! __('pos.trial_ended_restaurant_body_html', ['link' => '<a href="' . e(route('pos.billing')) . '" class="font-bold underline hover:no-underline">' . e(__('pos.view_plans_arrow')) . '</a>']) !!}</p>
                        @else
                            <p class="text-sm font-bold text-amber-900 dark:text-amber-200">{{ __('pos.restaurant_needs_pro') }}</p>
                            <p class="text-[11px] text-amber-800 dark:text-amber-300/90 leading-snug">{!! __('pos.restaurant_needs_pro_body_html', ['link' => '<a href="' . e(route('pos.billing')) . '" class="font-bold underline hover:no-underline">' . e(__('pos.upgrade_your_plan')) . '</a>']) !!}</p>
                        @endif
                    </div>
                </div>

                {{-- Trial-restaurant heads-up: features work now, lock on expiry --}}
                @if(($restaurantAccessSource ?? null) === 'trial')
                <div class="mb-4 p-3.5 rounded-xl border border-orange-200 dark:border-orange-800 bg-orange-50 dark:bg-orange-900/20 flex items-start gap-2.5">
                    <span class="text-lg leading-none">🍽️</span>
                    <div>
                        <p class="text-sm font-bold text-orange-900 dark:text-orange-200">{{ __('pos.restaurant_part_of_trial') }}</p>
                        <p class="text-[11px] text-orange-800 dark:text-orange-300/90 leading-snug">{!! __('pos.restaurant_trial_body_html', ['link' => '<a href="' . e(route('pos.billing')) . '" class="font-bold underline hover:no-underline">' . e(__('pos.view_plans_arrow')) . '</a>']) !!}</p>
                    </div>
                </div>
                @endif

                {{-- RECOMMENDED --}}
                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 shadow-sm mb-4" x-show="recommendedFlags.length">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 text-[10px] font-extrabold uppercase tracking-wider">✓ {{ __('pos.recommended_word') }}</span>
                        <span class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.preselected_for_type') }}</span>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-2.5">
                        <template x-for="f in recommendedFlags" :key="'rec-'+f">
                            <label class="flex items-start gap-3 p-3 rounded-xl border-2 transition"
                                :class="isLocked(f) ? 'border-gray-200 dark:border-gray-700 opacity-60 cursor-not-allowed' : (flags[f] ? 'border-purple-500 bg-purple-50/50 dark:bg-purple-900/10 cursor-pointer' : 'border-gray-200 dark:border-gray-700 hover:border-purple-300 cursor-pointer')">
                                <input type="checkbox" :name="'feature_flags['+f+']'" value="1" x-model="flags[f]" @change="onFlagChange()" :disabled="isLocked(f)" class="mt-0.5 w-4 h-4 text-purple-600 rounded disabled:opacity-50">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-1.5 mb-0.5">
                                        <span class="text-base leading-none" x-text="flagIcon(f)"></span>
                                        <span class="text-sm font-bold text-gray-900 dark:text-white" x-text="flagLabel(f)"></span>
                                    </div>
                                    <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug" x-text="flagDesc(f)"></p>
                                    <template x-if="isLocked(f)">
                                        <span class="mt-1.5 inline-flex items-center gap-1 text-[9px] font-extrabold uppercase tracking-wider text-amber-800 dark:text-amber-300 bg-amber-100 dark:bg-amber-900/40 px-1.5 py-0.5 rounded">🔒 {{ __('pos.pro_unlimited_plan') }}</span>
                                    </template>
                                    <template x-if="!isLocked(f) && flagDeps(f).length">
                                        <span class="mt-1.5 inline-flex items-center gap-1 text-[9px] font-bold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 px-1.5 py-0.5 rounded" x-text="{{ Js::from(__('pos.auto_includes_prefix') . ' ') }} + flagDeps(f).map(flagLabel).join(', ')"></span>
                                    </template>
                                </div>
                            </label>
                        </template>
                    </div>
                </div>

                {{-- EXTRA --}}
                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 shadow-sm mb-4" x-show="extraFlags.length">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 text-[10px] font-extrabold uppercase tracking-wider">+ {{ __('pos.extra_word') }}</span>
                        <span class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.optional_switch_on') }}</span>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-2.5">
                        <template x-for="f in extraFlags" :key="'extra-'+f">
                            <label class="flex items-start gap-3 p-3 rounded-xl border-2 transition"
                                :class="isLocked(f) ? 'border-gray-200 dark:border-gray-700 opacity-60 cursor-not-allowed' : (flags[f] ? 'border-purple-500 bg-purple-50/50 dark:bg-purple-900/10 cursor-pointer' : 'border-gray-200 dark:border-gray-700 hover:border-purple-300 cursor-pointer')">
                                <input type="checkbox" :name="'feature_flags['+f+']'" value="1" x-model="flags[f]" @change="onFlagChange()" :disabled="isLocked(f)" class="mt-0.5 w-4 h-4 text-purple-600 rounded disabled:opacity-50">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-1.5 mb-0.5">
                                        <span class="text-base leading-none" x-text="flagIcon(f)"></span>
                                        <span class="text-sm font-bold text-gray-900 dark:text-white" x-text="flagLabel(f)"></span>
                                    </div>
                                    <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug" x-text="flagDesc(f)"></p>
                                    <template x-if="isLocked(f)">
                                        <span class="mt-1.5 inline-flex items-center gap-1 text-[9px] font-extrabold uppercase tracking-wider text-amber-800 dark:text-amber-300 bg-amber-100 dark:bg-amber-900/40 px-1.5 py-0.5 rounded">🔒 {{ __('pos.pro_unlimited_plan') }}</span>
                                    </template>
                                    <template x-if="!isLocked(f) && flagDeps(f).length">
                                        <span class="mt-1.5 inline-flex items-center gap-1 text-[9px] font-bold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 px-1.5 py-0.5 rounded" x-text="{{ Js::from(__('pos.auto_includes_prefix') . ' ') }} + flagDeps(f).map(flagLabel).join(', ')"></span>
                                    </template>
                                </div>
                            </label>
                        </template>
                    </div>
                </div>

                {{-- CASHIER & RECEIPT PREFERENCES --}}
                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 shadow-sm">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="text-lg">⚙️</span>
                        <h3 class="text-sm font-extrabold text-gray-900 dark:text-white">{{ __('pos.cashier_receipt_prefs') }}</h3>
                    </div>

                    <p class="text-[11px] font-bold text-gray-500 dark:text-gray-400 mb-2">{{ __('pos.cashier_screen_density') }}</p>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 mb-4">
                        @foreach([
                            'simple' => [__('pos.density_simple'), __('pos.density_simple_desc'), '🟢'],
                            'standard' => [__('pos.density_standard'), __('pos.density_standard_desc'), '🟡'],
                            'premium' => [__('pos.density_premium'), __('pos.density_premium_desc'), '🟣'],
                        ] as $key => $info)
                            <label class="cursor-pointer">
                                <input type="radio" name="pos_ui_density" value="{{ $key }}" x-model="density" class="peer sr-only">
                                <div class="p-3 rounded-xl border-2 border-gray-200 dark:border-gray-700 peer-checked:border-blue-500 peer-checked:bg-blue-50 dark:peer-checked:bg-blue-900/20 transition">
                                    <div class="text-xl mb-0.5">{{ $info[2] }}</div>
                                    <div class="text-sm font-bold text-gray-900 dark:text-white">{{ $info[0] }}</div>
                                    <div class="text-[10px] text-gray-500 dark:text-gray-400">{{ $info[1] }}</div>
                                </div>
                            </label>
                        @endforeach
                    </div>

                    <div class="grid sm:grid-cols-2 gap-2.5">
                        <label class="flex items-start gap-3 p-3 rounded-xl border-2 cursor-pointer transition" :class="guidedFlow ? 'border-amber-500 bg-amber-50/40 dark:bg-amber-900/10' : 'border-gray-200 dark:border-gray-700'">
                            <input type="checkbox" name="pos_guided_flow_enabled" value="1" x-model="guidedFlow" class="mt-0.5 w-4 h-4 text-amber-600 rounded">
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-bold text-gray-900 dark:text-white">⌨️ {{ __('pos.guided_keyboard_billing') }}</div>
                                <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug">{{ __('pos.guided_keyboard_billing_desc') }}</p>
                            </div>
                        </label>
                        <label class="flex items-start gap-3 p-3 rounded-xl border-2 cursor-pointer transition" :class="autoPrintKot ? 'border-amber-500 bg-amber-50/40 dark:bg-amber-900/10' : 'border-gray-200 dark:border-gray-700'" x-show="flags.kot">
                            <input type="checkbox" name="auto_print_kot" value="1" x-model="autoPrintKot" class="mt-0.5 w-4 h-4 text-amber-600 rounded">
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-bold text-gray-900 dark:text-white">🖨️ {{ __('pos.auto_print_kot') }}</div>
                                <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug">{{ __('pos.auto_print_kot_desc') }}</p>
                            </div>
                        </label>
                        <label class="flex items-start gap-3 p-3 rounded-xl border-2 cursor-pointer transition" :class="kotReprint ? 'border-amber-500 bg-amber-50/40 dark:bg-amber-900/10' : 'border-gray-200 dark:border-gray-700'" x-show="flags.kot">
                            <input type="checkbox" name="kot_reprint_enabled" value="1" x-model="kotReprint" class="mt-0.5 w-4 h-4 text-amber-600 rounded">
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-bold text-gray-900 dark:text-white">🔁 {{ __('pos.allow_kot_reprint') }} <x-new-badge feature="kot_reprint_switch" class="ml-1" /></div>
                                <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug">{{ __('pos.allow_kot_reprint_desc') }}</p>
                            </div>
                        </label>
                        {{-- Owner 25 Aug 2026: pehle upar wala EK switch dono buttons band karta
                             tha. Shop ki takleef sirf poore Re-send se hai (kitchen ko pata nahi
                             chalta parcha naya hai ya purana → cheez DOBARA pak jati hai);
                             "Aakhri Add-on" sirf naye items ka parcha hai aur rozana ka jaiz kaam.
                             Ab dono alag hain, taake khatarnak wala band ho aur jaiz wala chale. --}}
                        <label class="flex items-start gap-3 p-3 rounded-xl border-2 cursor-pointer transition" :class="kotLastAddon ? 'border-amber-500 bg-amber-50/40 dark:bg-amber-900/10' : 'border-gray-200 dark:border-gray-700'" x-show="flags.kot">
                            <input type="checkbox" name="kot_last_addon_enabled" value="1" x-model="kotLastAddon" class="mt-0.5 w-4 h-4 text-amber-600 rounded">
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-bold text-gray-900 dark:text-white">➕ {{ __('pos.allow_kot_last_addon') }} <x-new-badge feature="kot_last_addon_switch" class="ml-1" /></div>
                                <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug">{{ __('pos.allow_kot_last_addon_desc') }}</p>
                            </div>
                        </label>
                    </div>
                </div>

                {{-- SALES TAX RATES (PRA) --}}
                <div class="mt-4 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 shadow-sm">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-lg">🧾</span>
                        <h3 class="text-sm font-extrabold text-gray-900 dark:text-white">{{ __('pos.sales_tax_rates_pra') }}</h3>
                    </div>
                    <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug mb-3">
                        {{ __('pos.tax_rates_intro') }}
                        {!! __('pos.tax_rates_defaults_html', [
                            'cash' => '<strong>' . e(rtrim(rtrim(number_format($globalTaxRates['cash'], 2, '.', ''), '0'), '.')) . '%</strong>',
                            'card' => '<strong>' . e(rtrim(rtrim(number_format($globalTaxRates['card'], 2, '.', ''), '0'), '.')) . '%</strong>',
                        ]) !!}
                    </p>
                    <div class="grid sm:grid-cols-2 gap-2.5">
                        <label class="block p-3 rounded-xl border-2 border-gray-200 dark:border-gray-700">
                            <div class="text-sm font-bold text-gray-900 dark:text-white mb-0.5">💵 {{ __('pos.cash_rate_pct') }}</div>
                            <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug mb-2">{{ __('pos.cash_rate_desc') }}</p>
                            <input type="number" name="pos_tax_rate_cash" step="0.01" min="0" max="100" inputmode="decimal"
                                value="{{ $company->pos_tax_rate_cash !== null ? rtrim(rtrim(number_format((float) $company->pos_tax_rate_cash, 2, '.', ''), '0'), '.') : '' }}"
                                placeholder="{{ __('pos.ph_default_value', ['value' => rtrim(rtrim(number_format($globalTaxRates['cash'], 2, '.', ''), '0'), '.')]) }}"
                                autocomplete="off" data-lpignore="true"
                                class="w-full px-3 py-2 text-sm font-bold rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                        </label>
                        <label class="block p-3 rounded-xl border-2 border-gray-200 dark:border-gray-700">
                            <div class="text-sm font-bold text-gray-900 dark:text-white mb-0.5">💳 {{ __('pos.card_digital_rate_pct') }}</div>
                            <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug mb-2">{{ __('pos.card_rate_desc') }}</p>
                            <input type="number" name="pos_tax_rate_card" step="0.01" min="0" max="100" inputmode="decimal"
                                value="{{ $company->pos_tax_rate_card !== null ? rtrim(rtrim(number_format((float) $company->pos_tax_rate_card, 2, '.', ''), '0'), '.') : '' }}"
                                placeholder="{{ __('pos.ph_default_value', ['value' => rtrim(rtrim(number_format($globalTaxRates['card'], 2, '.', ''), '0'), '.')]) }}"
                                autocomplete="off" data-lpignore="true"
                                class="w-full px-3 py-2 text-sm font-bold rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                        </label>
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between">
                    <button type="button" @click="back()" class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                        {{ __('pos.back_word') }}
                    </button>
                    <button type="button" @click="next()" class="inline-flex items-center gap-2 px-6 py-2.5 text-sm font-bold rounded-lg bg-purple-600 hover:bg-purple-700 text-white shadow-sm">
                        {{ __('pos.next_review') }}
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </button>
                </div>
            </div>

            {{-- ════════════════════ STEP 3 — SUMMARY ════════════════════ --}}
            <div x-show="step === 3" x-transition.opacity>
                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 sm:p-6 shadow-sm">
                    <h2 class="text-lg font-extrabold text-gray-900 dark:text-white mb-1">{{ __('pos.review_and_start') }}</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-5">{{ __('pos.review_sub') }}</p>

                    <div class="rounded-xl border-2 border-purple-200 dark:border-purple-800 bg-purple-50/40 dark:bg-purple-900/10 p-4 mb-5 flex items-center gap-3">
                        <span class="text-3xl" x-text="selectedPresetMeta.icon"></span>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-wider text-purple-600 dark:text-purple-400">{{ __('pos.business_type') }}</p>
                            <p class="text-base font-extrabold text-gray-900 dark:text-white" x-text="selectedPresetMeta.label"></p>
                        </div>
                    </div>

                    <p class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('pos.features_turned_on') }} (<span x-text="enabledFlags.length"></span>)</p>
                    <div class="grid sm:grid-cols-2 gap-2 mb-5">
                        <template x-for="f in enabledFlags" :key="'sum-'+f">
                            <div class="flex items-center gap-2 p-2 rounded-lg bg-emerald-50 dark:bg-emerald-900/15 border border-emerald-100 dark:border-emerald-900/40">
                                <svg class="w-4 h-4 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                <span class="text-sm font-semibold text-gray-800 dark:text-gray-100" x-text="flagIcon(f) + ' ' + flagLabel(f)"></span>
                            </div>
                        </template>
                        <div x-show="enabledFlags.length === 0" class="text-sm text-gray-400 italic p-2">{{ __('pos.basic_billing_only') }}</div>
                    </div>

                    <p class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('pos.preferences_word') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-gray-100 dark:bg-gray-800 text-xs font-semibold text-gray-700 dark:text-gray-200">{{ __('pos.screen_colon') }} <span class="capitalize" x-text="density"></span></span>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-gray-100 dark:bg-gray-800 text-xs font-semibold text-gray-700 dark:text-gray-200" x-text="guidedFlow ? {{ Js::from('⌨️ ' . __('pos.guided_billing_on')) }} : {{ Js::from(__('pos.guided_billing_off')) }}"></span>
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between">
                    <button type="button" @click="back()" class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                        {{ __('pos.back_word') }}
                    </button>
                    <button type="submit" class="inline-flex items-center gap-2 px-7 py-3 text-sm font-extrabold rounded-lg bg-gradient-to-r from-emerald-500 to-green-600 hover:from-emerald-600 hover:to-green-700 text-white shadow-lg shadow-emerald-500/30">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        {{ __('pos.start_using_pos') }}
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        function posWizard() {
            return {
                step: 1,
                steps: [
                    { n: 1, label: @js(__('pos.step_business')) },
                    { n: 2, label: @js(__('pos.step_features')) },
                    { n: 3, label: @js(__('pos.step_review')) },
                ],
                flags: @json($flagState),
                density: @json($company->pos_ui_density ?? 'standard'),
                autoPrintKot: @json((bool)($company->auto_print_kot ?? false)),
                kotReprint: @json((bool)($company->kot_reprint_enabled ?? true)),
                kotLastAddon: @json((bool)($company->kot_last_addon_enabled ?? true)),
                guidedFlow: @json((bool)($company->pos_guided_flow_enabled ?? true)),

                {{-- This shop's OWN business type only — the category is admin-only,
                     so no other type's name, icon, description or default module
                     set may be shipped into the page. --}}
                selectedPresetMeta: @json($currentMetaLite),
                {{-- The shop's own preset flag map (recommended vs extra split). --}}
                categoryDefaults: @json($currentDefaults),
                flagMeta: @json($flagMetaLite),
                dependencies: @json(\App\Services\PosFeatureService::DEPENDENCIES),
                allFlags: @json(\App\Services\PosFeatureService::ALL_FLAGS),
                restaurantLocked: @json(!($restaurantAllowed ?? true)),
                restaurantFlags: @json(\App\Services\PosFeatureService::RESTAURANT_FLAGS),

                get recommendedFlags() {
                    const d = this.categoryDefaults || {};
                    return this.allFlags.filter(f => d[f]);
                },
                get extraFlags() {
                    const d = this.categoryDefaults || {};
                    return this.allFlags.filter(f => !d[f]);
                },
                get enabledFlags() {
                    return this.allFlags.filter(f => this.flags[f]);
                },

                flagLabel(f) { return (this.flagMeta[f] || {}).label || f; },
                flagDesc(f) { return (this.flagMeta[f] || {}).description || ''; },
                flagIcon(f) { return (this.flagMeta[f] || {}).icon || '•'; },
                flagDeps(f) { return this.dependencies[f] || []; },
                isLocked(f) { return this.restaurantLocked && this.restaurantFlags.includes(f); },

                onFlagChange() { this.resolveDeps(); },
                resolveDeps() {
                    // Plan-locked restaurant flags can never switch ON client-side
                    // (server enforces the same rule on save).
                    if (this.restaurantLocked) {
                        this.restaurantFlags.forEach(f => { this.flags[f] = false; });
                    }
                    // Enable required parents of any enabled child.
                    for (const child in this.dependencies) {
                        if (this.flags[child]) {
                            this.dependencies[child].forEach(p => { this.flags[p] = true; });
                        }
                    }
                    // Disable any child whose required parent is off (iterate to a fixed point).
                    let changed = true;
                    while (changed) {
                        changed = false;
                        for (const child in this.dependencies) {
                            if (this.flags[child] && this.dependencies[child].some(p => !this.flags[p])) {
                                this.flags[child] = false;
                                changed = true;
                            }
                        }
                    }
                },
                goTo(n) { if (n <= this.step) { this.step = n; this.scrollTop(); } },
                next() { if (this.step < 3) { this.step++; this.scrollTop(); } },
                back() { if (this.step > 1) { this.step--; this.scrollTop(); } },
                scrollTop() { window.scrollTo({ top: 0, behavior: 'smooth' }); },
            };
        }
    </script>
</x-pos-layout>
