<x-pos-layout>
    @php
        $flagsByCat = \App\Services\PosFeatureService::flagsByCategory();
        $deps = \App\Services\PosFeatureService::dependencies();
        $presets = array_keys(\App\Services\PosFeatureService::PRESET_META);
        $currentCategory = $company->business_category ?: 'retail';
    @endphp

    <div x-data="featureSettings()" class="max-w-6xl mx-auto p-4 sm:p-6">

        {{-- ═══════════ HERO ═══════════ --}}
        <div class="mb-6 rounded-2xl bg-gradient-to-br from-purple-600 via-fuchsia-600 to-pink-600 p-6 sm:p-8 text-white shadow-xl relative overflow-hidden">
            <div class="absolute top-0 right-0 w-72 h-72 bg-white/10 rounded-full blur-3xl -mr-32 -mt-32"></div>
            <div class="relative">
                <div class="flex items-center gap-3 mb-2">
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-white/20 backdrop-blur text-[10px] font-bold uppercase tracking-wider">🚀 Universal POS v2</span>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-emerald-400/30 backdrop-blur text-[10px] font-bold uppercase tracking-wider">✓ Active</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold mb-2">Customize Your POS</h1>
                <p class="text-sm sm:text-base text-white/80 max-w-2xl">Pick an industry preset to start, then fine-tune individual features. Works seamlessly for restaurants, cafes, retail, pharmacy, salons, and more.</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('pos.v2.invoice.create') }}" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white text-purple-700 text-sm font-bold hover:bg-purple-50 transition shadow-lg">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                        Open Universal POS
                    </a>
                    <a href="{{ route('pos.dashboard') }}" class="inline-flex items-center px-4 py-2 rounded-lg bg-white/10 backdrop-blur text-white text-sm font-semibold hover:bg-white/20 transition border border-white/30">
                        Back to Dashboard
                    </a>
                </div>
            </div>
        </div>

        @if(session('success'))
            <div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 text-sm text-emerald-800 dark:text-emerald-200 font-semibold flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                {{ session('success') }}
            </div>
        @endif

        <form method="POST" action="{{ route('pos.features.update') }}" class="space-y-6">
            @csrf
            <input type="hidden" name="business_category" :value="selectedPreset" />
            <input type="hidden" name="use_universal_pos" value="1" />

            {{-- ═══════════ STEP 1 — INDUSTRY PRESETS ═══════════ --}}
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 sm:p-6 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="w-7 h-7 rounded-full bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300 text-xs font-bold inline-flex items-center justify-center">1</span>
                            <h2 class="text-lg font-extrabold text-gray-900 dark:text-white">Choose Your Industry Preset</h2>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 ml-9">Click any preset — it instantly enables the most useful features for that business type. You can fine-tune below.</p>
                    </div>
                    <button type="submit" formaction="{{ route('pos.features.reset') }}" class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 text-[11px] font-bold rounded-lg border border-orange-300 text-orange-700 dark:border-orange-700/50 dark:text-orange-400 hover:bg-orange-50 dark:hover:bg-orange-900/20 transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Reset to Preset Defaults
                    </button>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                    @foreach($presets as $preset)
                        @php $meta = \App\Services\PosFeatureService::presetMeta($preset); @endphp
                        <button type="button" @click="selectPreset('{{ $preset }}')"
                            :class="selectedPreset === '{{ $preset }}' ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20 ring-2 ring-purple-400/40' : 'border-gray-200 dark:border-gray-700 hover:border-purple-300 hover:bg-purple-50/30 dark:hover:bg-purple-900/10'"
                            class="relative p-3 rounded-xl border-2 text-left transition-all duration-150 group">
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

                {{-- Mobile reset button --}}
                <div class="mt-4 sm:hidden">
                    <button type="submit" formaction="{{ route('pos.features.reset') }}" class="w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-bold rounded-lg border border-orange-300 text-orange-700 hover:bg-orange-50">
                        Reset to Preset Defaults
                    </button>
                </div>
            </div>

            {{-- ═══════════ STEP 2 — UI DENSITY ═══════════ --}}
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 sm:p-6 shadow-sm">
                <div class="flex items-center gap-2 mb-3">
                    <span class="w-7 h-7 rounded-full bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 text-xs font-bold inline-flex items-center justify-center">2</span>
                    <h2 class="text-lg font-extrabold text-gray-900 dark:text-white">Cashier Screen Density</h2>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 ml-9 mb-4">How much info per screen. Pick what your cashiers will love.</p>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    @foreach([
                        'simple' => ['Simple', 'Big buttons, minimal info — best for new cashiers', '🟢'],
                        'standard' => ['Standard', 'Balanced — recommended default', '🟡'],
                        'premium' => ['Premium', 'Power-user mode with all info visible', '🟣'],
                    ] as $key => $info)
                        <label class="relative cursor-pointer">
                            <input type="radio" name="pos_ui_density" value="{{ $key }}" {{ ($company->pos_ui_density ?? 'standard') === $key ? 'checked' : '' }} class="peer sr-only">
                            <div class="p-4 rounded-xl border-2 border-gray-200 dark:border-gray-700 peer-checked:border-blue-500 peer-checked:bg-blue-50 dark:peer-checked:bg-blue-900/20 transition">
                                <div class="text-2xl mb-1">{{ $info[2] }}</div>
                                <div class="text-sm font-bold text-gray-900 dark:text-white">{{ $info[0] }}</div>
                                <div class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">{{ $info[1] }}</div>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- ═══════════ STEP 3 — MODULES BY CATEGORY ═══════════ --}}
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 sm:p-6 shadow-sm">
                <div class="flex items-center gap-2 mb-3">
                    <span class="w-7 h-7 rounded-full bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 text-xs font-bold inline-flex items-center justify-center">3</span>
                    <h2 class="text-lg font-extrabold text-gray-900 dark:text-white">Fine-tune Individual Features</h2>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 ml-9 mb-5">Override the preset above. Toggle exactly what your business needs.</p>

                <div class="space-y-6">
                    @foreach($flagsByCat as $cat => $flags)
                        @php $catMeta = \App\Services\PosFeatureService::categoryMeta($cat); @endphp
                        <div>
                            <div class="flex items-center gap-2 mb-3 pb-2 border-b border-gray-200 dark:border-gray-700">
                                <span class="text-xl">{{ $catMeta['icon'] }}</span>
                                <div>
                                    <h3 class="text-sm font-extrabold text-gray-900 dark:text-white">{{ $catMeta['label'] }}</h3>
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400">{{ $catMeta['description'] }}</p>
                                </div>
                            </div>
                            <div class="grid sm:grid-cols-2 gap-2.5">
                                @foreach($flags as $flag)
                                    @php $meta = \App\Services\PosFeatureService::flagMeta($flag); @endphp
                                    <label class="flex items-start gap-3 p-3 rounded-xl border-2 border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 has-[:checked]:border-purple-500 has-[:checked]:bg-purple-50/40 dark:has-[:checked]:bg-purple-900/10 cursor-pointer transition">
                                        <input type="checkbox" name="feature_flags[{{ $flag }}]" value="1" {{ $features->{$flag} ? 'checked' : '' }} class="mt-0.5 w-4 h-4 text-purple-600 rounded">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-1.5 mb-0.5">
                                                <span class="text-base leading-none">{{ $meta['icon'] }}</span>
                                                <span class="text-sm font-bold text-gray-900 dark:text-white">{{ $meta['label'] }}</span>
                                            </div>
                                            <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug">{{ $meta['description'] }}</p>
                                            @if(isset($deps[$flag]))
                                                <div class="mt-1.5 inline-flex items-center gap-1 text-[9px] font-bold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 px-1.5 py-0.5 rounded">
                                                    <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                                                    REQUIRES: {{ implode(', ', array_map(fn($d) => \App\Services\PosFeatureService::flagMeta($d)['label'], $deps[$flag])) }}
                                                </div>
                                            @endif
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- ═══════════ STEP 4 — RECEIPT & KITCHEN ═══════════ --}}
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 sm:p-6 shadow-sm">
                <div class="flex items-center gap-2 mb-3">
                    <span class="w-7 h-7 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 text-xs font-bold inline-flex items-center justify-center">4</span>
                    <h2 class="text-lg font-extrabold text-gray-900 dark:text-white">Receipt &amp; Kitchen</h2>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 ml-9 mb-4">Control what prints on receipts and how kitchen tickets (KOT) behave.</p>

                <div class="grid sm:grid-cols-2 gap-2.5">
                    {{-- Receipt: show tax line --}}
                    <label class="flex items-start gap-3 p-3 rounded-xl border-2 border-gray-200 dark:border-gray-700 hover:border-amber-300 dark:hover:border-amber-700 has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50/40 dark:has-[:checked]:bg-amber-900/10 cursor-pointer transition">
                        <input type="checkbox" name="pos_receipt_show_tax" value="1" {{ ($company->pos_receipt_show_tax ?? true) ? 'checked' : '' }} class="mt-0.5 w-4 h-4 text-amber-600 rounded">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-1.5 mb-0.5">
                                <span class="text-base leading-none">🧾</span>
                                <span class="text-sm font-bold text-gray-900 dark:text-white">Show Tax on Receipt</span>
                            </div>
                            <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug">Print the tax (sales tax) line on customer receipts. Turn off for tax-inclusive pricing.</p>
                            <div class="mt-1.5 inline-flex items-center gap-1 text-[9px] font-bold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 px-1.5 py-0.5 rounded">
                                <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                                Tax is always submitted to PRA regardless of this setting
                            </div>
                        </div>
                    </label>

                    {{-- KOT: auto print --}}
                    <label class="flex items-start gap-3 p-3 rounded-xl border-2 border-gray-200 dark:border-gray-700 hover:border-amber-300 dark:hover:border-amber-700 has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50/40 dark:has-[:checked]:bg-amber-900/10 cursor-pointer transition">
                        <input type="checkbox" name="auto_print_kot" value="1" {{ ($company->auto_print_kot ?? false) ? 'checked' : '' }} class="mt-0.5 w-4 h-4 text-amber-600 rounded">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-1.5 mb-0.5">
                                <span class="text-base leading-none">🖨️</span>
                                <span class="text-sm font-bold text-gray-900 dark:text-white">Auto-Print KOT</span>
                            </div>
                            <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug">Automatically print the kitchen ticket when an order is sent to the kitchen.</p>
                        </div>
                    </label>

                    {{-- KOT: reprint allowed --}}
                    <label class="flex items-start gap-3 p-3 rounded-xl border-2 border-gray-200 dark:border-gray-700 hover:border-amber-300 dark:hover:border-amber-700 has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50/40 dark:has-[:checked]:bg-amber-900/10 cursor-pointer transition">
                        <input type="checkbox" name="kot_reprint_enabled" value="1" {{ ($company->kot_reprint_enabled ?? true) ? 'checked' : '' }} class="mt-0.5 w-4 h-4 text-amber-600 rounded">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-1.5 mb-0.5">
                                <span class="text-base leading-none">🔁</span>
                                <span class="text-sm font-bold text-gray-900 dark:text-white">Allow KOT Reprint</span>
                            </div>
                            <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug">Let cashiers reprint a kitchen ticket from the held-orders screen.</p>
                        </div>
                    </label>

                    {{-- Guided keyboard billing flow (opt-in, universal POS) --}}
                    <label class="flex items-start gap-3 p-3 rounded-xl border-2 border-gray-200 dark:border-gray-700 hover:border-amber-300 dark:hover:border-amber-700 has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50/40 dark:has-[:checked]:bg-amber-900/10 cursor-pointer transition">
                        <input type="checkbox" name="pos_guided_flow_enabled" value="1" {{ ($company->pos_guided_flow_enabled ?? false) ? 'checked' : '' }} class="mt-0.5 w-4 h-4 text-amber-600 rounded">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-1.5 mb-0.5">
                                <span class="text-base leading-none">⌨️</span>
                                <span class="text-sm font-bold text-gray-900 dark:text-white">Guided Keyboard Billing</span>
                            </div>
                            <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-snug">Step-by-step Enter-key flow on the sale screen: Customer → Items → Cart → Bill. All existing shortcuts (F-keys, +/−, arrows) keep working.</p>
                        </div>
                    </label>
                </div>
            </div>

            {{-- ═══════════ ACTIONS ═══════════ --}}
            <div class="sticky bottom-4 z-10 bg-white/90 dark:bg-gray-900/90 backdrop-blur border border-gray-200 dark:border-gray-700 rounded-2xl p-4 shadow-2xl flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between">
                <div class="text-xs text-gray-600 dark:text-gray-400">
                    <span class="font-bold text-purple-700 dark:text-purple-300" x-text="selectedPresetLabel"></span>
                    <span class="mx-2">·</span>
                    <span>Changes apply only to your company</span>
                </div>
                <div class="flex flex-col sm:flex-row gap-2 sm:gap-3">
                    <a href="{{ route('pos.dashboard') }}" class="inline-flex justify-center items-center px-5 py-2.5 text-sm font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">Cancel</a>
                    <button type="submit" class="inline-flex justify-center items-center gap-2 px-6 py-2.5 text-sm font-bold rounded-lg bg-gradient-to-r from-purple-600 to-fuchsia-600 hover:from-purple-700 hover:to-fuchsia-700 text-white shadow-lg shadow-purple-500/30">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Save Settings
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        function featureSettings() {
            return {
                selectedPreset: @json($currentCategory),
                presetLabels: @json(collect(\App\Services\PosFeatureService::PRESET_META)->mapWithKeys(fn($m, $k) => [$k => $m['label']])),
                categoryDefaults: @json(\App\Services\PosFeatureService::CATEGORY_DEFAULTS),
                allFlags: @json(\App\Services\PosFeatureService::ALL_FLAGS),
                get selectedPresetLabel() {
                    return this.presetLabels[this.selectedPreset] || 'Custom';
                },
                selectPreset(preset) {
                    this.selectedPreset = preset;
                    const defaults = this.categoryDefaults[preset] || {};
                    this.allFlags.forEach(flag => {
                        const cb = document.querySelector(`input[type=checkbox][name="feature_flags[${flag}]"]`);
                        if (cb) cb.checked = !!defaults[flag];
                    });
                }
            };
        }
    </script>
</x-pos-layout>
