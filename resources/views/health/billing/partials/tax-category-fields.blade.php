@php
    use App\Models\HealthCharge;
    use App\Models\HealthTaxCategory;

    $applies = (array) ($rule->applies_to ?? []);
@endphp

<div class="grid grid-cols-1 md:grid-cols-3 gap-3">
    <div>
        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.taxcat_name') }}</label>
        <input type="text" name="name" maxlength="120" required value="{{ $rule->name ?? '' }}"
               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
    </div>
    <div>
        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.taxcat_code') }}</label>
        <input type="text" name="code" maxlength="40" value="{{ $rule->code ?? '' }}"
               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
    </div>
    <div>
        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.tax_treatment') }}</label>
        <select name="treatment" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            @foreach(HealthTaxCategory::TREATMENTS as $t)
                <option value="{{ $t }}" @selected(($rule->treatment ?? HealthTaxCategory::TREATMENT_LOCAL) === $t)>
                    {{ __(HealthTaxCategory::treatmentLabelKey($t)) }}
                </option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.taxcat_rate') }}</label>
        <input type="number" step="0.01" min="0" max="100" name="tax_rate" value="{{ $rule->tax_rate ?? 0 }}"
               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
    </div>
    <div>
        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.pct_code') }}</label>
        <input type="text" name="pct_code" maxlength="40" value="{{ $rule->pct_code ?? '' }}"
               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
    </div>
    <div>
        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.taxcat_sro') }}</label>
        <input type="text" name="sro_reference" maxlength="120" value="{{ $rule->sro_reference ?? '' }}"
               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
    </div>
</div>

<div>
    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.taxcat_applies_to') }}</label>
    <div class="flex flex-wrap gap-2">
        @foreach(HealthCharge::CATEGORIES as $cat)
            <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-xs font-bold cursor-pointer">
                <input type="checkbox" name="applies_to[]" value="{{ $cat }}" @checked(in_array($cat, $applies, true))
                       class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                {{ __(HealthCharge::categoryLabelKey($cat)) }}
            </label>
        @endforeach
    </div>
    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('health.taxcat_applies_hint') }}</p>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-3">
    <div>
        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.note') }}</label>
        <input type="text" name="notes" maxlength="300" value="{{ $rule->notes ?? '' }}"
               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
    </div>
    <div class="flex items-end">
        <label class="inline-flex items-center gap-2 text-sm font-bold cursor-pointer">
            <input type="checkbox" name="is_default" value="1" @checked($rule->is_default ?? false)
                   class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
            {{ __('health.taxcat_make_default') }}
        </label>
    </div>
</div>
