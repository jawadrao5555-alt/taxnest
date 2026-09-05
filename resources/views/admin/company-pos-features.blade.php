<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <a href="/admin/company/{{ $company->id }}" class="text-gray-400 hover:text-gray-600 dark:text-gray-400 transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </a>
                <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">POS Features — {{ $company->name }}</h2>
                <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300">Admin Override</span>
            </div>
        </div>
    </x-slot>

    @php
        $flagsByCat = \App\Services\PosFeatureService::flagsByCategory();
        $deps = \App\Services\PosFeatureService::dependencies();
        // Panel-aware: a PRA company is offered the Punjab SERVICE categories, an
        // FBR company the federal GOODS ones (plus restaurant/salon, which are a
        // federal case in ICT), and a company still sitting on an off-panel
        // category keeps seeing its own card. This page is the ONLY place a
        // category may be changed — the shop's Customize page shows it read-only.
        $currentCategory = \App\Services\PosFeatureService::resolveCategory($company);
        $presets = \App\Services\PosFeatureService::categoriesForCompany($company);
        // The amber "this belongs on the other panel" notice fires only when the
        // category is genuinely the OTHER regulator's; a catch-all like
        // 'general' belongs to nobody and must not raise it.
        $onLegacyCategory = \App\Services\PosFeatureService::belongsToOtherPanel($company);
    @endphp

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8" x-data="adminFeatureSettings()">

            {{-- Hero --}}
            <div class="mb-6 rounded-2xl bg-gradient-to-br from-indigo-700 via-purple-700 to-fuchsia-700 p-6 text-white shadow-xl relative overflow-hidden">
                <div class="relative">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-white/20 backdrop-blur text-[10px] font-bold uppercase tracking-wider">🛡️ Admin Panel</span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-amber-400/30 backdrop-blur text-[10px] font-bold uppercase tracking-wider">Override Mode</span>
                    </div>
                    <h1 class="text-2xl font-extrabold mb-1">{{ $company->name }} — POS Configuration</h1>
                    <p class="text-sm text-white/80">NTN: {{ $company->ntn ?: '—' }} · Owner: {{ $company->owner_name ?: '—' }} · Status: <span class="font-bold uppercase">{{ $company->company_status }}</span></p>
                    <p class="text-xs text-white/70 mt-2">Changes you save here override the company's own settings. The company will see the new configuration immediately.</p>
                </div>
            </div>

            @if(session('success'))
                <div class="mb-4 p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-sm text-emerald-800 font-semibold flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    {{ session('success') }}
                </div>
            @endif

            <form method="POST" action="/admin/company/{{ $company->id }}/pos-features" class="space-y-5">
                @csrf
                @method('PUT')
                {{-- Stale-form presence marker (Task 1393): proves THIS request
                     really carried the feature block, so an outdated admin tab
                     replayed across a deploy can't silently wipe the company's
                     modules. Mirrors fs_present on the shop wizard. --}}
                <input type="hidden" name="fs_present" value="1" />
                {{-- Universal sale screen: rides a hidden input so a fresh save
                     keeps it ON (its historical force-write) while the controller
                     no longer flips it blind on a marker-less POST. --}}
                <input type="hidden" name="use_universal_pos" value="1" />
                <input type="hidden" name="business_category" :value="selectedPreset" />

                {{-- Industry Preset --}}
                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 shadow-sm">
                    <h3 class="text-base font-extrabold text-gray-900 dark:text-white mb-1">Industry Preset</h3>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mb-3">PRA taxes services only, so only service businesses are offered here. Goods categories live on the FBR panel.</p>
                    @if($onLegacyCategory)
                        <div class="mb-3 rounded-xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 px-3 py-2.5">
                            <p class="text-xs font-bold text-amber-900 dark:text-amber-200">This company is on a retired goods category</p>
                            <p class="mt-0.5 text-[11px] leading-relaxed text-amber-800 dark:text-amber-300/90">
                                It signed up before the services/goods split. Nothing is switched off — its card is pinned below so it stays saveable. A goods business belongs on the FBR POS panel.
                            </p>
                        </div>
                    @endif
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2.5">
                        @foreach($presets as $preset)
                            @php $meta = \App\Services\PosFeatureService::presetMeta($preset); @endphp
                            <button type="button" @click="selectPreset('{{ $preset }}')"
                                :class="selectedPreset === '{{ $preset }}' ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20 ring-2 ring-purple-400/40' : 'border-gray-200 dark:border-gray-700 hover:border-purple-300'"
                                class="relative p-2.5 rounded-xl border-2 text-left transition group">
                                <div class="text-2xl mb-1">{{ $meta['icon'] }}</div>
                                <div class="text-xs font-bold text-gray-900 dark:text-white leading-tight">{{ $meta['label'] }}</div>
                                <div x-show="selectedPreset === '{{ $preset }}'" class="absolute top-1 right-1 w-4 h-4 rounded-full bg-purple-600 flex items-center justify-center">
                                    <svg class="w-2.5 h-2.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                </div>
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- UI Density --}}
                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 shadow-sm">
                    <h3 class="text-base font-extrabold text-gray-900 dark:text-white mb-3">UI Density</h3>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach(['simple','standard','premium'] as $d)
                            <label class="relative cursor-pointer">
                                <input type="radio" name="pos_ui_density" value="{{ $d }}" {{ ($company->pos_ui_density ?? 'standard') === $d ? 'checked' : '' }} class="peer sr-only">
                                <div class="p-3 rounded-lg border-2 border-gray-200 dark:border-gray-700 peer-checked:border-purple-500 peer-checked:bg-purple-50 dark:peer-checked:bg-purple-900/20 text-center text-sm font-bold capitalize">{{ $d }}</div>
                            </label>
                        @endforeach
                    </div>
                </div>

                {{-- Modules --}}
                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-5 shadow-sm">
                    <h3 class="text-base font-extrabold text-gray-900 dark:text-white mb-4">Modules</h3>
                    <div class="space-y-5">
                        @foreach($flagsByCat as $cat => $flags)
                            @php $catMeta = \App\Services\PosFeatureService::categoryMeta($cat); @endphp
                            <div>
                                <div class="flex items-center gap-2 mb-2 pb-1.5 border-b border-gray-200 dark:border-gray-700">
                                    <span class="text-base">{{ $catMeta['icon'] }}</span>
                                    <h4 class="text-xs font-extrabold uppercase tracking-wider text-gray-700 dark:text-gray-300">{{ $catMeta['label'] }}</h4>
                                </div>
                                <div class="grid sm:grid-cols-2 gap-2">
                                    @foreach($flags as $flag)
                                        @php $meta = \App\Services\PosFeatureService::flagMeta($flag); @endphp
                                        <label class="flex items-start gap-2.5 p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 has-[:checked]:border-purple-500 has-[:checked]:bg-purple-50/40 dark:has-[:checked]:bg-purple-900/10 cursor-pointer transition">
                                            <input type="checkbox" name="feature_flags[{{ $flag }}]" value="1" {{ $features->{$flag} ? 'checked' : '' }} class="mt-0.5 w-4 h-4 text-purple-600 rounded">
                                            <div class="flex-1 min-w-0">
                                                <div class="text-sm font-bold text-gray-900 dark:text-white flex items-center gap-1">{{ $meta['icon'] }} {{ $meta['label'] }}</div>
                                                <p class="text-[10px] text-gray-500 dark:text-gray-400 leading-snug">{{ $meta['description'] }}</p>
                                                @if(isset($deps[$flag]))
                                                    <div class="mt-1 inline-flex items-center gap-1 text-[8px] font-bold text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 px-1.5 py-0.5 rounded">
                                                        REQ: {{ implode(', ', $deps[$flag]) }}
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

                {{-- Actions --}}
                <div class="sticky bottom-4 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-2xl p-4 shadow-2xl flex justify-between items-center">
                    <a href="/admin/company/{{ $company->id }}" class="px-4 py-2 text-sm font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">Cancel</a>
                    <button type="submit" class="px-6 py-2.5 text-sm font-bold rounded-lg bg-gradient-to-r from-purple-600 to-fuchsia-600 hover:from-purple-700 hover:to-fuchsia-700 text-white shadow-lg shadow-purple-500/30 inline-flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Save Override
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function adminFeatureSettings() {
            return {
                selectedPreset: @json($currentCategory),
                categoryDefaults: @json(\App\Services\PosFeatureService::allCategoryDefaults()),
                allFlags: @json(\App\Services\PosFeatureService::ALL_FLAGS),
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
</x-admin-layout>
