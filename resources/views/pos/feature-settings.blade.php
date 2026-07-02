<x-pos-layout>
    @php
        $currentCategory = $company->business_category ?: ($company->restaurant_mode ? 'restaurant' : 'retail');
        $presetKeys = array_keys(\App\Services\PosFeatureService::PRESET_META);
        // Current flag state (resolved) → seed the wizard so re-editing shows live config.
        $flagState = [];
        foreach (\App\Services\PosFeatureService::ALL_FLAGS as $f) { $flagState[$f] = (bool) $features->{$f}; }
        $isFirstTime = $isFirstTime ?? false;
    @endphp

    <style>[x-cloak]{display:none!important}</style>

    <div x-data="posWizard()" x-cloak class="max-w-5xl mx-auto p-4 sm:p-6">

        {{-- ═══════════ WELCOME (first-time only) ═══════════ --}}
        @if($isFirstTime)
        <div class="mb-5 rounded-2xl bg-gradient-to-r from-purple-600 to-fuchsia-600 p-5 sm:p-6 text-white shadow-xl">
            <div class="flex items-start gap-3">
                <div class="text-3xl">👋</div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-extrabold mb-1">Welcome — let's set up your POS</h1>
                    <p class="text-sm text-white/85 max-w-2xl">Just 3 quick steps: pick your business type, choose the features you need, and start billing. You can change all of this anytime from <span class="font-bold">Customize POS</span>.</p>
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
            <input type="hidden" name="business_category" :value="selectedPreset">
            <input type="hidden" name="use_universal_pos" value="1">

            {{-- ════════════════════ STEP 1 — BUSINESS TYPE ════════════════════ --}}
            <div x-show="step === 1" x-transition.opacity>
                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 sm:p-6 shadow-sm">
                    <h2 class="text-lg font-extrabold text-gray-900 dark:text-white">What type of business is this?</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-5">Pick the closest match — we'll switch on the features most businesses like yours use. You can fine-tune everything in the next step.</p>

                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                        @foreach($presetKeys as $preset)
                            @php $meta = \App\Services\PosFeatureService::presetMeta($preset); @endphp
                            <button type="button" @click="selectPreset('{{ $preset }}')"
                                :class="selectedPreset === '{{ $preset }}' ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20 ring-2 ring-purple-400/40' : 'border-gray-200 dark:border-gray-700 hover:border-purple-300 hover:bg-purple-50/30 dark:hover:bg-purple-900/10'"
                                class="relative p-3 rounded-xl border-2 text-left transition-all duration-150">
                                @if($meta['badge'])
                                    <span class="absolute top-1.5 right-1.5 inline-flex items-center px-1.5 py-0.5 rounded-full text-[8px] font-extrabold uppercase tracking-wider
                                        @if($meta['badge'] === 'Most Popular') bg-emerald-500 text-white
                                        @elseif($meta['badge'] === 'New') bg-pink-500 text-white
                                        @else bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300 @endif">
                                        {{ $meta['badge'] }}
                                    </span>
                                @endif
                                <div class="text-3xl mb-1.5">{{ $meta['icon'] }}</div>
                                <div class="text-sm font-bold text-gray-900 dark:text-white leading-tight mb-1">{{ $meta['label'] }}</div>
                                <div class="text-[10px] text-gray-500 dark:text-gray-400 leading-snug">{{ $meta['description'] }}</div>
                                <div x-show="selectedPreset === '{{ $preset }}'" class="absolute top-1.5 left-1.5 w-5 h-5 rounded-full bg-purple-600 flex items-center justify-center">
                                    <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                </div>
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between">
                    <a href="{{ route('pos.customize') }}" class="inline-flex items-center px-4 py-2.5 text-sm font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">Cancel</a>
                    <button type="button" @click="next()" class="inline-flex items-center gap-2 px-6 py-2.5 text-sm font-bold rounded-lg bg-gradient-to-r from-purple-600 to-fuchsia-600 hover:from-purple-700 hover:to-fuchsia-700 text-white shadow-lg shadow-purple-500/30">
                        Next — Choose Features
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </button>
                </div>
            </div>

            {{-- ════════════════════ STEP 2 — FEATURES ════════════════════ --}}
            <div x-show="step === 2" x-transition.opacity>
                <div class="mb-3 flex items-center gap-2 text-sm">
                    <span class="text-2xl" x-text="selectedPresetMeta.icon"></span>
                    <div>
                        <p class="font-extrabold text-gray-900 dark:text-white">Features for <span class="text-purple-700 dark:text-purple-300" x-text="selectedPresetMeta.label"></span></p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">Recommended features are already on. Add or remove anything — nothing is locked.</p>
                    </div>
                </div>

                {{-- RECOMMENDED --}}
                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 shadow-sm mb-4" x-show="recommendedFlags.length">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 text-[10px] font-extrabold uppercase tracking-wider">✓ Recommended</span>
                        <span class="text-[11px] text-gray-500 dark:text-gray-400">Pre-selected for your business type</span>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-2.5">
                        <template x-for="f in recommendedFlags" :key="'rec-'+f">
                            <label class="flex items-start gap-3 p-3 rounded-xl border-2 cursor-pointer transition"
                                :class="flags[f] ? 'border-purple-500 bg-purple-50/50 dark:bg-purple-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-purple-300'">
                                <input type="checkbox" :name="'feature_flags['+f+']'" value="1" x-model="flags[f]" @change="onFlagChange()" class="mt-0.5 w-4 h-4 text-purple-600 rounded">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-1.5 mb-0.5">
                                        <span class="text-base leading-none" x-text="flagIcon(f)"></span>
                                        <span class="text-sm font-bold text-gray-900 dark:text-white" x-text="flagLabel(f)"></span>
                                    </div>
                                    <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug" x-text="flagDesc(f)"></p>
                                    <template x-if="flagDeps(f).length">
                                        <span class="mt-1.5 inline-flex items-center gap-1 text-[9px] font-bold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 px-1.5 py-0.5 rounded" x-text="'Auto-includes: ' + flagDeps(f).map(flagLabel).join(', ')"></span>
                                    </template>
                                </div>
                            </label>
                        </template>
                    </div>
                </div>

                {{-- EXTRA --}}
                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 shadow-sm mb-4" x-show="extraFlags.length">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 text-[10px] font-extrabold uppercase tracking-wider">+ Extra</span>
                        <span class="text-[11px] text-gray-500 dark:text-gray-400">Optional — switch on only if you need them</span>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-2.5">
                        <template x-for="f in extraFlags" :key="'extra-'+f">
                            <label class="flex items-start gap-3 p-3 rounded-xl border-2 cursor-pointer transition"
                                :class="flags[f] ? 'border-purple-500 bg-purple-50/50 dark:bg-purple-900/10' : 'border-gray-200 dark:border-gray-700 hover:border-purple-300'">
                                <input type="checkbox" :name="'feature_flags['+f+']'" value="1" x-model="flags[f]" @change="onFlagChange()" class="mt-0.5 w-4 h-4 text-purple-600 rounded">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-1.5 mb-0.5">
                                        <span class="text-base leading-none" x-text="flagIcon(f)"></span>
                                        <span class="text-sm font-bold text-gray-900 dark:text-white" x-text="flagLabel(f)"></span>
                                    </div>
                                    <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug" x-text="flagDesc(f)"></p>
                                    <template x-if="flagDeps(f).length">
                                        <span class="mt-1.5 inline-flex items-center gap-1 text-[9px] font-bold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 px-1.5 py-0.5 rounded" x-text="'Auto-includes: ' + flagDeps(f).map(flagLabel).join(', ')"></span>
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
                        <h3 class="text-sm font-extrabold text-gray-900 dark:text-white">Cashier &amp; Receipt Preferences</h3>
                    </div>

                    <p class="text-[11px] font-bold text-gray-500 dark:text-gray-400 mb-2">Cashier screen density</p>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 mb-4">
                        @foreach([
                            'simple' => ['Simple', 'Big buttons, minimal info', '🟢'],
                            'standard' => ['Standard', 'Balanced — recommended', '🟡'],
                            'premium' => ['Premium', 'All info visible', '🟣'],
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
                        <label class="flex items-start gap-3 p-3 rounded-xl border-2 cursor-pointer transition" :class="receiptShowTax ? 'border-amber-500 bg-amber-50/40 dark:bg-amber-900/10' : 'border-gray-200 dark:border-gray-700'">
                            <input type="checkbox" name="pos_receipt_show_tax" value="1" x-model="receiptShowTax" class="mt-0.5 w-4 h-4 text-amber-600 rounded">
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-bold text-gray-900 dark:text-white">🧾 Show Tax on Receipt</div>
                                <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug">Print the sales-tax line on receipts. (Tax is always submitted to FBR/PRA regardless.)</p>
                            </div>
                        </label>
                        <label class="flex items-start gap-3 p-3 rounded-xl border-2 cursor-pointer transition" :class="guidedFlow ? 'border-amber-500 bg-amber-50/40 dark:bg-amber-900/10' : 'border-gray-200 dark:border-gray-700'">
                            <input type="checkbox" name="pos_guided_flow_enabled" value="1" x-model="guidedFlow" class="mt-0.5 w-4 h-4 text-amber-600 rounded">
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-bold text-gray-900 dark:text-white">⌨️ Guided Keyboard Billing</div>
                                <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug">Step-by-step Enter-key flow: Customer → Items → Cart → Bill. All shortcuts keep working.</p>
                            </div>
                        </label>
                        <label class="flex items-start gap-3 p-3 rounded-xl border-2 cursor-pointer transition" :class="autoPrintKot ? 'border-amber-500 bg-amber-50/40 dark:bg-amber-900/10' : 'border-gray-200 dark:border-gray-700'" x-show="flags.kot">
                            <input type="checkbox" name="auto_print_kot" value="1" x-model="autoPrintKot" class="mt-0.5 w-4 h-4 text-amber-600 rounded">
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-bold text-gray-900 dark:text-white">🖨️ Auto-Print KOT</div>
                                <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug">Automatically print the kitchen ticket when an order is sent to the kitchen.</p>
                            </div>
                        </label>
                        <label class="flex items-start gap-3 p-3 rounded-xl border-2 cursor-pointer transition" :class="kotReprint ? 'border-amber-500 bg-amber-50/40 dark:bg-amber-900/10' : 'border-gray-200 dark:border-gray-700'" x-show="flags.kot">
                            <input type="checkbox" name="kot_reprint_enabled" value="1" x-model="kotReprint" class="mt-0.5 w-4 h-4 text-amber-600 rounded">
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-bold text-gray-900 dark:text-white">🔁 Allow KOT Reprint</div>
                                <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug">Let cashiers reprint a kitchen ticket from the held-orders screen.</p>
                            </div>
                        </label>
                    </div>
                </div>

                {{-- SALES TAX RATES (PRA) --}}
                <div class="mt-4 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 shadow-sm">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-lg">🧾</span>
                        <h3 class="text-sm font-extrabold text-gray-900 dark:text-white">Sales Tax Rates (PRA)</h3>
                    </div>
                    <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug mb-3">
                        Set your company's tax rates for POS billing. Leave a field blank to use the system default.
                        Current defaults: <strong>{{ rtrim(rtrim(number_format($globalTaxRates['cash'], 2, '.', ''), '0'), '.') }}%</strong> cash,
                        <strong>{{ rtrim(rtrim(number_format($globalTaxRates['card'], 2, '.', ''), '0'), '.') }}%</strong> card / digital payments.
                    </p>
                    <div class="grid sm:grid-cols-2 gap-2.5">
                        <label class="block p-3 rounded-xl border-2 border-gray-200 dark:border-gray-700">
                            <div class="text-sm font-bold text-gray-900 dark:text-white mb-0.5">💵 Cash Rate (%)</div>
                            <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug mb-2">Applied when the customer pays by cash.</p>
                            <input type="number" name="pos_tax_rate_cash" step="0.01" min="0" max="100" inputmode="decimal"
                                value="{{ $company->pos_tax_rate_cash !== null ? rtrim(rtrim(number_format((float) $company->pos_tax_rate_cash, 2, '.', ''), '0'), '.') : '' }}"
                                placeholder="Default: {{ rtrim(rtrim(number_format($globalTaxRates['cash'], 2, '.', ''), '0'), '.') }}"
                                autocomplete="off" data-lpignore="true"
                                class="w-full px-3 py-2 text-sm font-bold rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                        </label>
                        <label class="block p-3 rounded-xl border-2 border-gray-200 dark:border-gray-700">
                            <div class="text-sm font-bold text-gray-900 dark:text-white mb-0.5">💳 Card / Digital Rate (%)</div>
                            <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug mb-2">Applied to debit/credit card, QR and other digital-channel payments.</p>
                            <input type="number" name="pos_tax_rate_card" step="0.01" min="0" max="100" inputmode="decimal"
                                value="{{ $company->pos_tax_rate_card !== null ? rtrim(rtrim(number_format((float) $company->pos_tax_rate_card, 2, '.', ''), '0'), '.') : '' }}"
                                placeholder="Default: {{ rtrim(rtrim(number_format($globalTaxRates['card'], 2, '.', ''), '0'), '.') }}"
                                autocomplete="off" data-lpignore="true"
                                class="w-full px-3 py-2 text-sm font-bold rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                        </label>
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between">
                    <button type="button" @click="back()" class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                        Back
                    </button>
                    <button type="button" @click="next()" class="inline-flex items-center gap-2 px-6 py-2.5 text-sm font-bold rounded-lg bg-gradient-to-r from-purple-600 to-fuchsia-600 hover:from-purple-700 hover:to-fuchsia-700 text-white shadow-lg shadow-purple-500/30">
                        Next — Review
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </button>
                </div>
            </div>

            {{-- ════════════════════ STEP 3 — SUMMARY ════════════════════ --}}
            <div x-show="step === 3" x-transition.opacity>
                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 sm:p-6 shadow-sm">
                    <h2 class="text-lg font-extrabold text-gray-900 dark:text-white mb-1">Review &amp; start</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-5">Here's how your POS will work. You can come back and change this anytime.</p>

                    <div class="rounded-xl border-2 border-purple-200 dark:border-purple-800 bg-purple-50/40 dark:bg-purple-900/10 p-4 mb-5 flex items-center gap-3">
                        <span class="text-3xl" x-text="selectedPresetMeta.icon"></span>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-wider text-purple-600 dark:text-purple-400">Business type</p>
                            <p class="text-base font-extrabold text-gray-900 dark:text-white" x-text="selectedPresetMeta.label"></p>
                        </div>
                    </div>

                    <p class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Features turned ON (<span x-text="enabledFlags.length"></span>)</p>
                    <div class="grid sm:grid-cols-2 gap-2 mb-5">
                        <template x-for="f in enabledFlags" :key="'sum-'+f">
                            <div class="flex items-center gap-2 p-2 rounded-lg bg-emerald-50 dark:bg-emerald-900/15 border border-emerald-100 dark:border-emerald-900/40">
                                <svg class="w-4 h-4 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                <span class="text-sm font-semibold text-gray-800 dark:text-gray-100" x-text="flagIcon(f) + ' ' + flagLabel(f)"></span>
                            </div>
                        </template>
                        <div x-show="enabledFlags.length === 0" class="text-sm text-gray-400 italic p-2">Basic billing only — no extra modules. Perfect for a simple counter.</div>
                    </div>

                    <p class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Preferences</p>
                    <div class="flex flex-wrap gap-2">
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-gray-100 dark:bg-gray-800 text-xs font-semibold text-gray-700 dark:text-gray-200">Screen: <span class="capitalize" x-text="density"></span></span>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-gray-100 dark:bg-gray-800 text-xs font-semibold text-gray-700 dark:text-gray-200" x-text="guidedFlow ? '⌨️ Guided billing ON' : 'Guided billing off'"></span>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-gray-100 dark:bg-gray-800 text-xs font-semibold text-gray-700 dark:text-gray-200" x-text="receiptShowTax ? '🧾 Tax on receipt' : 'No tax line on receipt'"></span>
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between">
                    <button type="button" @click="back()" class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                        Back
                    </button>
                    <button type="submit" class="inline-flex items-center gap-2 px-7 py-3 text-sm font-extrabold rounded-lg bg-gradient-to-r from-emerald-500 to-green-600 hover:from-emerald-600 hover:to-green-700 text-white shadow-lg shadow-emerald-500/30">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Start Using POS
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
                    { n: 1, label: 'Business' },
                    { n: 2, label: 'Features' },
                    { n: 3, label: 'Review' },
                ],
                selectedPreset: @json($currentCategory),
                flags: @json($flagState),
                density: @json($company->pos_ui_density ?? 'standard'),
                receiptShowTax: @json((bool)($company->pos_receipt_show_tax ?? true)),
                autoPrintKot: @json((bool)($company->auto_print_kot ?? false)),
                kotReprint: @json((bool)($company->kot_reprint_enabled ?? true)),
                guidedFlow: @json((bool)($company->pos_guided_flow_enabled ?? true)),

                presetMeta: @json(\App\Services\PosFeatureService::PRESET_META),
                flagMeta: @json(\App\Services\PosFeatureService::FLAG_META),
                categoryDefaults: @json(\App\Services\PosFeatureService::CATEGORY_DEFAULTS),
                dependencies: @json(\App\Services\PosFeatureService::DEPENDENCIES),
                allFlags: @json(\App\Services\PosFeatureService::ALL_FLAGS),

                get selectedPresetMeta() {
                    return this.presetMeta[this.selectedPreset] || { label: 'Custom', icon: '⚙️', description: '' };
                },
                get recommendedFlags() {
                    const d = this.categoryDefaults[this.selectedPreset] || {};
                    return this.allFlags.filter(f => d[f]);
                },
                get extraFlags() {
                    const d = this.categoryDefaults[this.selectedPreset] || {};
                    return this.allFlags.filter(f => !d[f]);
                },
                get enabledFlags() {
                    return this.allFlags.filter(f => this.flags[f]);
                },

                flagLabel(f) { return (this.flagMeta[f] || {}).label || f; },
                flagDesc(f) { return (this.flagMeta[f] || {}).description || ''; },
                flagIcon(f) { return (this.flagMeta[f] || {}).icon || '•'; },
                flagDeps(f) { return this.dependencies[f] || []; },

                selectPreset(preset) {
                    this.selectedPreset = preset;
                    const defaults = this.categoryDefaults[preset] || {};
                    this.allFlags.forEach(f => { this.flags[f] = !!defaults[f]; });
                    this.resolveDeps();
                },
                onFlagChange() { this.resolveDeps(); },
                resolveDeps() {
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
