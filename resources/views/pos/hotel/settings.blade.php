<x-hotel-layout>
    @php
        $initial = [
            'theme' => $company->pos_theme ?? 'purple',
            'taxMode' => $company->posTaxPricingMode(),
            'whatsapp' => (bool) ($company->pos_whatsapp_bill_enabled ?? true),
            'whatsappAuto' => (bool) ($company->pos_whatsapp_bill_auto_open ?? false),
            'caller' => (bool) ($company->caller_id_enabled ?? false),
            'receiptSeconds' => (int) ($company->pos_receipt_autoclose_seconds ?? 10),
            'guided' => (bool) ($company->pos_guided_flow_enabled ?? true),
            'quick' => (bool) ($company->pos_quick_type_enabled ?? false),
            'kds' => (bool) ($company->pos_kds_auto_print ?? false),
            'waiterCancel' => (bool) ($company->pos_waiter_cancel_enabled ?? false),
            'waiterTakeaway' => (bool) ($company->pos_waiter_takeaway_enabled ?? true),
        ];
        $themes = ['purple' => '#7c3aed', 'blue' => '#2563eb', 'emerald' => '#059669', 'orange' => '#ea580c', 'midnight' => '#171717', 'rose' => '#e11d48'];
    @endphp
    <script src="{{ asset('js/hotel-settings.js') }}?v={{ filemtime(public_path('js/hotel-settings.js')) }}"></script>
    <div data-hotel-settings="1" x-data="TnHotelSettings({{ Js::from($initial) }}, {{ Js::from(['saving' => __('pos.saving_ellipsis'), 'saved' => __('pos.hotel_settings_saved'), 'failed' => __('pos.setting_save_failed')]) }})" class="space-y-4">
        <header>
            <h1 class="text-2xl font-extrabold dark:text-white">{{ __('pos.hotel_settings_title') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ __('pos.hotel_settings_intro') }}</p>
        </header>
        @if(session('success'))
            <p role="status" class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</p>
        @endif
        @if(session('error') || $errors->any())
            <p role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ session('error') ?? $errors->first() }}</p>
        @endif
        <div aria-live="polite" class="sticky top-2 z-10">
            <p x-show="status" x-cloak x-text="status" class="rounded-xl border bg-white dark:bg-gray-900 p-3 text-sm dark:text-white"></p>
            <p x-show="error" x-cloak x-text="error" role="alert" class="rounded-xl border border-red-300 bg-red-50 p-3 text-sm text-red-800"></p>
        </div>

        <div class="grid sm:grid-cols-2 gap-3 items-start">
            <details data-hotel-settings-card="rooms" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
                <summary class="cursor-pointer font-bold dark:text-white">{{ __('pos.hotel_settings_rooms') }}<span class="block mt-1 text-xs font-normal text-gray-500">{{ __('pos.hotel_settings_rooms_hint') }}</span></summary>
                <div class="mt-4 space-y-3 text-sm dark:text-gray-200">
                    <a href="{{ route('pos.hotel.rooms') }}" class="block underline">{{ __('pos.nav_hotel_rooms') }}</a>
                    <a href="{{ route('pos.hotel.housekeeping') }}" class="block underline">{{ __('pos.nav_hotel_housekeeping') }}</a>
                    <p class="text-xs text-gray-500">{{ __('pos.hotel_settings_agreed_rate') }}</p>
                </div>
            </details>
            <details data-hotel-settings-card="payment" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
                <summary class="cursor-pointer font-bold dark:text-white">{{ __('pos.hotel_settings_payment') }}<span class="block mt-1 text-xs font-normal text-gray-500">{{ __('pos.hotel_settings_payment_hint') }}</span></summary>
                <div class="mt-4 space-y-4 text-sm dark:text-gray-200">
                    <p class="text-xs text-gray-500">{{ __('pos.hotel_settings_payment_flow') }}</p>
                    <a href="{{ route('pos.hotel.folios') }}" class="block underline">{{ __('pos.hotel_menu_bills') }}</a>
                    <form method="POST" action="{{ route('pos.hotel.checkout-policy') }}" class="space-y-2">
                        @csrf
                        <label for="hotel-checkout-policy" class="block font-semibold">{{ __('pos.hotel_checkout_policy') }}</label>
                        <select id="hotel-checkout-policy" name="hotel_checkout_outstanding" class="w-full rounded-lg border-gray-300 bg-white dark:bg-gray-800 text-sm">
                            <option value="allow" @selected(($company->hotel_checkout_outstanding ?? 'allow') === 'allow')>{{ __('pos.hotel_checkout_policy_allow') }}</option>
                            <option value="block" @selected($company->hotel_checkout_outstanding === 'block')>{{ __('pos.hotel_checkout_policy_block') }}</option>
                        </select>
                        <button type="submit" class="rounded-lg border px-3 py-2 font-semibold">{{ __('pos.hotel_checkout_policy_save') }}</button>
                    </form>
                </div>
            </details>
            <details data-hotel-settings-card="printing" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
                <summary class="cursor-pointer font-bold dark:text-white">{{ __('pos.hotel_settings_printing') }}<span class="block mt-1 text-xs font-normal text-gray-500">{{ __('pos.hotel_settings_printing_hint') }}</span></summary>
                <div class="mt-4 space-y-3 text-sm dark:text-gray-200">
                    <form method="POST" action="{{ route('pos.hotel.receipt-paper') }}" data-hotel-receipt-settings class="space-y-2">
                        @csrf
                        <label class="block font-semibold">{{ __('hotel_bill.paper_size') }}</label>
                        <select name="paper" class="w-full rounded-lg border p-2">
                            @foreach(['80mm' => '80mm', '58mm' => '58mm', 'a4' => 'A4'] as $value => $label)
                            <option value="{{ $value }}" @selected((($company->feature_flags['hotel_receipt_a4'] ?? false) ? 'a4' : ($company->receipt_printer_size === '58mm' ? '58mm' : '80mm')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-500">{{ __('hotel_bill.saved_print_hint') }}</p>
                        <button class="rounded-lg border px-3 py-2">{{ __('pos.hotel_checkout_policy_save') }}</button>
                    </form>
                    <p data-hotel-extension-status data-present="{{ __('hotel_bill.extension_present') }}" data-absent="{{ __('hotel_bill.extension_absent') }}" class="text-xs"></p>
                    <a href="{{ route('pos.printer-settings') }}" class="block underline">{{ __('pos.printer_settings') }}</a>
                    <a href="{{ route('pos.receipt-settings') }}" class="block underline">{{ __('pos.card_receipt_display') }}</a>
                    <p class="text-xs text-gray-500">{{ __('pos.hotel_settings_printer_scope') }}</p>
                    @if($hotelSettings['whatsapp'])
                        @foreach([['whatsapp', 'enabled', 'wa_bill_toggle'], ['whatsappAuto', 'auto_open', 'wa_bill_auto_open']] as [$key, $payloadKey, $label])
                            <div class="flex items-center justify-between gap-3">
                                <span>{{ __('pos.'.$label) }}</span>
                                <button type="button" role="switch" :aria-checked="values.{{ $key }} ? 'true' : 'false'" :disabled="busy.{{ $key }}" @click="save('{{ $key }}', '{{ route('pos.settings.whatsapp-bill-toggle', [], false) }}', { {{ $payloadKey }}: !values.{{ $key }} }, !values.{{ $key }})" class="rounded-lg border px-3 py-2 disabled:opacity-50" x-text="values.{{ $key }} ? {{ Js::from(__('pos.hotel_settings_on')) }} : {{ Js::from(__('pos.hotel_settings_off')) }}"></button>
                            </div>
                        @endforeach
                    @endif
                </div>
            </details>
            <details data-hotel-settings-card="staff" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
                <summary class="cursor-pointer font-bold dark:text-white">{{ __('pos.hotel_settings_staff') }}<span class="block mt-1 text-xs font-normal text-gray-500">{{ __('pos.hotel_settings_staff_hint') }}</span></summary>
                <div class="mt-4 space-y-3 text-sm dark:text-gray-200">
                    <a href="{{ route('pos.team') }}" class="block underline">{{ __('pos.card_team') }}</a>
                    <p class="text-xs text-gray-500">{{ __('pos.hotel_settings_staff_scope') }}</p>
                </div>
            </details>
            <details data-hotel-settings-card="appearance" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
                <summary class="cursor-pointer font-bold dark:text-white">{{ __('pos.hotel_settings_appearance') }}<span class="block mt-1 text-xs font-normal text-gray-500">{{ __('pos.hotel_settings_appearance_hint') }}</span></summary>
                <div class="mt-4 space-y-4 text-sm dark:text-gray-200">
                    <div class="flex flex-wrap gap-2">
                        @foreach($themes as $theme => $color)
                            <button type="button" data-hotel-theme="{{ $theme }}" :disabled="busy.theme" @click="save('theme', '{{ route('pos.settings.theme', [], false) }}', {theme: '{{ $theme }}'}, '{{ $theme }}')" :aria-pressed="values.theme === '{{ $theme }}' ? 'true' : 'false'" aria-label="{{ ucfirst($theme) }}" class="h-10 w-10 rounded-lg border-2 disabled:opacity-50" :class="values.theme === '{{ $theme }}' ? 'ring-2 ring-offset-2 ring-gray-500' : ''" style="background: {{ $color }}"></button>
                        @endforeach
                    </div>
                    <form method="POST" action="{{ route('pos.settings.default-language') }}" class="space-y-2">
                        @csrf
                        <label for="hotel-default-language" class="block font-semibold">{{ __('pos.company_default_language') }}</label>
                        <select id="hotel-default-language" name="default_language" class="w-full rounded-lg border-gray-300 bg-white dark:bg-gray-800">
                            @foreach(['rur' => 'language_roman_urdu', 'en' => 'language_english', 'ur' => 'language_urdu_script'] as $locale => $label)
                                <option value="{{ $locale }}" @selected(\App\Support\PosLocale::normalize($company->default_language) === $locale)>{{ __('pos.'.$label) }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="rounded-lg border px-3 py-2 font-semibold">{{ __('pos.save') }}</button>
                    </form>
                </div>
            </details>
            <details data-hotel-settings-card="account" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
                <summary class="cursor-pointer font-bold dark:text-white">{{ __('pos.hotel_settings_account') }}<span class="block mt-1 text-xs font-normal text-gray-500">{{ __('pos.hotel_settings_account_hint') }}</span></summary>
                <div class="mt-4 space-y-3 text-sm dark:text-gray-200">
                    <a href="{{ route('pos.business-profile') }}" class="block underline">{{ __('pos.business_profile') }}</a>
                    @if($hotelSettings['accountAdmin'])
                        <a href="{{ route('pos.billing') }}" class="block underline">{{ __('pos.card_billing_plan') }}</a>
                        <a href="{{ route('pos.branches') }}" data-hotel-account-link="branches" class="block underline">{{ __('pos.card_branches') }}</a>
                        <p class="text-xs text-gray-500">{{ __('pos.hotel_settings_branch_scope') }}</p>
                    @endif
                    <a href="{{ route('pos.terminals') }}" class="block underline">{{ __('pos.card_terminals') }}</a>
                    <a href="{{ route('pos.agent') }}" class="block underline">{{ __('pos.hotel_settings_agent') }}</a>
                    <a href="{{ route('pos.user-profile') }}" class="block underline">{{ __('pos.my_profile') }}</a>
                </div>
            </details>
        </div>

        <details data-hotel-settings-advanced="1" class="rounded-xl border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-900">
            <summary class="cursor-pointer font-semibold dark:text-white">{{ __('pos.hotel_settings_advanced') }}</summary>
            <div class="mt-4 space-y-4 text-sm dark:text-gray-200">
                <p class="text-xs text-gray-500">{{ __('pos.hotel_settings_advanced_hint') }}</p>
                <a href="{{ route('pos.features') }}" class="block underline">{{ __('pos.card_modules_features') }}</a>
                @if($hotelSettings['caller'])
                <details class="rounded-lg border p-3">
                    <summary class="cursor-pointer font-semibold">{{ __('pos.caller_id_title') }}</summary>
                    @include('pos.hotel._caller-settings')
                </details>
                @endif
                <a href="{{ route('pos.pra-settings') }}" class="block underline">{{ __('pos.hotel_settings_tax_compliance') }}</a>
                <p>{{ __('pos.hotel_settings_configured_tax', ['cash' => \App\Models\PosTaxRule::getRateForMethod('cash', $company), 'card' => \App\Models\PosTaxRule::getRateForMethod('card', $company)]) }}</p>
                @php
                    $sample = 1000;
                    $sampleCashRate = (float) \App\Models\PosTaxRule::getRateForMethod('cash', $company);
                    $sampleCardRate = (float) \App\Models\PosTaxRule::getRateForMethod('card', $company);
                    $taxCards = [
                        'exclusive' => ['label' => __('pos.hotel_settings_tax_exclusive'), 'cash' => $sample * (1 + $sampleCashRate / 100), 'card' => $sample * (1 + $sampleCardRate / 100)],
                        'inclusive' => ['label' => __('pos.hotel_settings_tax_inclusive'), 'cash' => $sample, 'card' => $sample],
                        'inclusive_card_save' => ['label' => __('pos.hotel_settings_tax_card_save'), 'cash' => $sample, 'card' => $sample / (1 + $sampleCashRate / 100) * (1 + $sampleCardRate / 100)],
                    ];
                @endphp
                <fieldset data-hotel-tax-cards="1">
                    <legend class="font-semibold">{{ __('pos.hotel_settings_tax_price') }}</legend>
                    <p class="mb-2 text-xs text-gray-500">{{ __('hotel_reporting.example') }}</p>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" role="radiogroup" aria-label="{{ __('pos.hotel_settings_tax_price') }}">
                        @foreach($taxCards as $mode => $card)
                        <button type="button" role="radio" data-hotel-tax-mode="{{ $mode }}" :aria-checked="(values.taxMode === @js($mode)).toString()"
                            :disabled="busy.taxMode || values.taxMode === @js($mode) || @js((bool) data_get(session('impersonation'), 'readonly', false))"
                            @click="save('taxMode', '{{ route('pos.settings.tax-pricing-mode', [], false) }}', {mode: @js($mode)}, @js($mode))"
                            class="rounded-xl border-2 p-3 text-left dark:bg-gray-800 disabled:cursor-default"
                            :class="values.taxMode === @js($mode) ? 'border-blue-600 bg-blue-50 dark:border-blue-400' : 'border-gray-200 dark:border-gray-700'">
                            <span class="block font-semibold">{{ $card['label'] }} <span x-show="values.taxMode === @js($mode)" aria-hidden="true">✓</span></span>
                            <span class="mt-2 block text-xs">{{ __('hotel_reporting.sample_cash', ['amount' => number_format($card['cash'], 2)]) }}</span>
                            <span class="mt-1 block text-xs">{{ __('hotel_reporting.sample_card', ['amount' => number_format($card['card'], 2)]) }}</span>
                        </button>
                        @endforeach
                    </div>
                </fieldset>
                <p class="text-xs text-gray-500">{{ __('pos.hotel_settings_tax_history') }}</p>
                @if($hotelSettings['outlet'])
                <label for="hotel-receipt-seconds" class="block font-semibold">{{ __('pos.receipt_popup_autoclose') }}</label>
                <select id="hotel-receipt-seconds" :value="values.receiptSeconds" :disabled="busy.receiptSeconds" @change="const chosen = Number($event.target.value); $event.target.value = values.receiptSeconds; save('receiptSeconds', '{{ route('pos.settings.receipt-autoclose', [], false) }}', {seconds: chosen}, chosen).then(() => $el.value = values.receiptSeconds)" class="w-full rounded-lg border-gray-300 bg-white dark:bg-gray-800 text-sm">
                    @foreach([0, 5, 10, 15, 20, 30] as $seconds)
                        <option value="{{ $seconds }}">{{ $seconds === 0 ? __('pos.hotel_settings_never') : $seconds.' sec' }}</option>
                    @endforeach
                </select>
                @endif
            </div>
        </details>

        @if($hotelSettings['outlet'])
        <details data-hotel-settings-outlet="1" class="rounded-xl border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-900">
            <summary class="cursor-pointer font-semibold dark:text-white">{{ __('pos.nav_hotel_restaurant_outlet') }}</summary>
            <div class="mt-4 space-y-3 text-sm dark:text-gray-200">
                @if($hotelSettings['kitchen'])
                    <a href="{{ route('pos.restaurant.kitchen-settings') }}" class="block underline">{{ __('pos.card_kitchen_kot') }}</a>
                @endif
                @if(\App\Services\PosFeatureService::moduleAvailable($company, 'deals_enabled'))
                    <a href="{{ route('pos.deals') }}" class="block underline">{{ __('pos.card_deals') }}</a>
                @endif
                @foreach([
                    ['guided', 'pos.settings.guided-flow', 'guided_keyboard_billing', ['enabled']],
                    ['quick', 'pos.settings.quick-type', 'quick_type_mode', ['enabled']],
                    ['kds', 'pos.settings.kds-auto-print', 'kds_auto_print_kot', ['enabled']],
                    ['waiterCancel', 'pos.settings.waiter-permission', 'waiter_cancel_toggle', ['enabled', 'permission' => 'cancel']],
                    ['waiterTakeaway', 'pos.settings.waiter-permission', 'waiter_takeaway_toggle', ['enabled', 'permission' => 'takeaway']],
                ] as [$key, $route, $label, $payload])
                    @continue($key === 'kds' && !$hotelSettings['kdsPrint'])
                    <div class="flex items-center justify-between gap-3">
                        <span>{{ __('pos.'.$label) }}</span>
                        <button type="button" role="switch" :aria-checked="values.{{ $key }} ? 'true' : 'false'" :disabled="busy.{{ $key }}" @click="save('{{ $key }}', '{{ route($route, [], false) }}', {enabled: !values.{{ $key }} @if(isset($payload['permission'])), permission: '{{ $payload['permission'] }}' @endif}, !values.{{ $key }})" class="rounded-lg border px-3 py-2 disabled:opacity-50" x-text="values.{{ $key }} ? {{ Js::from(__('pos.hotel_settings_on')) }} : {{ Js::from(__('pos.hotel_settings_off')) }}"></button>
                    </div>
                @endforeach
            </div>
        </details>
        @endif
    </div>
</x-hotel-layout>
