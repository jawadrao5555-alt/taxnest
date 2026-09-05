{{--
    Pharmacy policy.

    These switches decide what the counter is ALLOWED to do, so each one says
    plainly what happens when it is on — a pharmacist should never have to guess
    whether expired stock can still be sold.
--}}
<x-health-layout>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.ph_settings_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.ph_settings_subtitle') }}</p>
        </div>

        <form method="POST" action="{{ route('health.pharmacy.settings.update') }}"
              class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 space-y-5">
            @csrf

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_set_near_expiry_days') }}</label>
                    <input type="number" name="near_expiry_days" min="1" max="1095" required
                           value="{{ old('near_expiry_days', $settings->near_expiry_days) }}"
                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('health.ph_set_near_expiry_days_help') }}</p>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_set_low_stock') }}</label>
                    <input type="number" name="low_stock_threshold" step="0.001" min="0" required
                           value="{{ old('low_stock_threshold', $settings->low_stock_threshold) }}"
                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('health.ph_set_low_stock_help') }}</p>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_set_tax_rate') }}</label>
                    <input type="number" name="default_tax_rate" step="0.01" min="0" max="100" required
                           value="{{ old('default_tax_rate', $settings->default_tax_rate) }}"
                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('health.ph_set_tax_rate_help') }}</p>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_set_prefix') }}</label>
                    <input type="text" name="sale_prefix" maxlength="8" required
                           value="{{ old('sale_prefix', $settings->sale_prefix) }}"
                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm uppercase">
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('health.ph_set_prefix_help') }}</p>
                </div>
            </div>

            <div class="space-y-3 pt-1">
                @php
                    $switches = [
                        ['block_expired_dispense', 'health.ph_set_block_expired', 'health.ph_set_block_expired_help'],
                        ['warn_short_dated', 'health.ph_set_warn_short', 'health.ph_set_warn_short_help'],
                        ['require_prescription_for_controlled', 'health.ph_set_require_rx', 'health.ph_set_require_rx_help'],
                        ['allow_negative_stock', 'health.ph_set_allow_negative', 'health.ph_set_allow_negative_help'],
                    ];
                @endphp
                @foreach($switches as [$field, $label, $help])
                    <label class="flex items-start gap-3 p-3 rounded-xl border border-gray-200 dark:border-gray-700 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40 transition">
                        <input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $settings->{$field}))
                               class="mt-0.5 rounded border-gray-300 dark:border-gray-600 text-teal-600 focus:ring-teal-500">
                        <span class="min-w-0">
                            <span class="block text-sm font-bold">{{ __($label) }}</span>
                            <span class="block text-[11px] text-gray-500 dark:text-gray-400">{{ __($help) }}</span>
                        </span>
                    </label>
                @endforeach
            </div>

            <div class="flex items-center gap-2 pt-1">
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                    {{ __('health.ph_save') }}
                </button>
                <a href="{{ route('health.pharmacy') }}" class="px-4 py-2.5 rounded-xl text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">
                    {{ __('health.cancel') }}
                </a>
            </div>
        </form>
    </div>
</x-health-layout>
