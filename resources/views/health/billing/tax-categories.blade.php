@php
    use App\Models\HealthCharge;
    use App\Models\HealthTaxCategory;

    $treatChip = [
        HealthTaxCategory::TREATMENT_LOCAL  => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
        HealthTaxCategory::TREATMENT_EXEMPT => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
        HealthTaxCategory::TREATMENT_FBR    => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    ];
@endphp
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5" x-data="{ adding: false, editing: null }">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ __('health.taxcat_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.taxcat_subtitle') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if($rules->isEmpty())
                    <form method="POST" action="{{ route('health.billing.tax-categories.seed') }}">
                        @csrf
                        <button class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.taxcat_seed') }}</button>
                    </form>
                @endif
                <button type="button" @click="adding = !adding"
                        class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.taxcat_add') }}</button>
            </div>
        </div>

        {{-- The whole point of this screen: a hospital's accountant decides what
             is local, what is exempt and what FBR is told. The software never
             makes that call on its own, and an unclassified charge falls back to
             local at 0% — wrong-but-correctable, never filed by accident. --}}
        <div class="rounded-2xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 px-4 py-3 text-sm">
            {{ __('health.taxcat_authority_note') }}
        </div>

        {{-- Add --}}
        <form method="POST" action="{{ route('health.billing.tax-categories.store') }}" x-show="adding" x-cloak
              class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-3">
            @csrf
            @include('health.billing.partials.tax-category-fields', ['rule' => null])
            <div class="flex justify-end">
                <button class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.save') }}</button>
            </div>
        </form>

        {{-- Rules --}}
        <div class="space-y-3">
            @forelse($rules as $rule)
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 {{ $rule->is_active ? '' : 'opacity-60' }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-black">{{ $rule->name }}</span>
                                @if($rule->code)
                                    <span class="text-xs font-mono text-gray-500 dark:text-gray-400">{{ $rule->code }}</span>
                                @endif
                                <span class="text-[11px] font-bold px-2 py-1 rounded-lg {{ $treatChip[$rule->treatment] ?? '' }}">
                                    {{ __(HealthTaxCategory::treatmentLabelKey($rule->treatment)) }}
                                    @if($rule->treatment === HealthTaxCategory::TREATMENT_FBR)
                                        {{ rtrim(rtrim(number_format((float) $rule->tax_rate, 2), '0'), '.') }}%
                                    @endif
                                </span>
                                @if($rule->is_default)
                                    <span class="text-[11px] font-bold px-2 py-1 rounded-lg bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-200">{{ __('health.taxcat_default') }}</span>
                                @endif
                                @unless($rule->is_active)
                                    <span class="text-[11px] font-bold px-2 py-1 rounded-lg bg-gray-200 dark:bg-gray-700">{{ __('health.inactive') }}</span>
                                @endunless
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                @if(!empty($rule->applies_to))
                                    {{ __('health.taxcat_applies_to') }}:
                                    {{ collect($rule->applies_to)->map(fn ($c) => __(HealthCharge::categoryLabelKey($c)))->implode(', ') }}
                                @else
                                    {{ __('health.taxcat_applies_all') }}
                                @endif
                                @if($rule->pct_code) · {{ __('health.pct_code') }}: {{ $rule->pct_code }} @endif
                                @if($rule->sro_reference) · {{ $rule->sro_reference }} @endif
                            </div>
                            @if($rule->notes)
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $rule->notes }}</p>
                            @endif
                        </div>
                        <div class="flex gap-2">
                            <button type="button" @click="editing = (editing === {{ $rule->id }} ? null : {{ $rule->id }})"
                                    class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-xs font-bold">{{ __('health.edit') }}</button>
                            <form method="POST" action="{{ route('health.billing.tax-categories.toggle', $rule->id) }}">
                                @csrf
                                <button class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-xs font-bold">
                                    {{ __($rule->is_active ? 'health.deactivate' : 'health.activate') }}
                                </button>
                            </form>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('health.billing.tax-categories.update', $rule->id) }}"
                          x-show="editing === {{ $rule->id }}" x-cloak
                          class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700 space-y-3">
                        @csrf
                        @method('PUT')
                        @include('health.billing.partials.tax-category-fields', ['rule' => $rule])
                        <div class="flex justify-end">
                            <button class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.save') }}</button>
                        </div>
                    </form>
                </div>
            @empty
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    {{ __('health.taxcat_none_yet') }}
                </div>
            @endforelse
        </div>
    </div>
</x-health-layout>
