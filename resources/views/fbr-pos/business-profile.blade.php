<x-fbr-pos-layout>
@php
    // Live sample-receipt preview (Task 717) — the FBR display toggles are edited
    // HERE (rd_* + receipt_footer_note + print_paper_size), so this page's preview
    // is live; fieldMap points the shared partial at this form's field names and
    // null = "no such field here" (theme cards / rp_* live on receipt-settings).
    $ps = $company->posReceiptStyle();
    $rd = $company->displayPrefs('fbrpos');
    $rcptThemeCfg = json_encode([
        'theme'  => \App\Support\PosReceiptThemes::resolve($ps),
        'themes' => \App\Support\PosReceiptThemes::clientMap(),
        'mode'   => 'fbr',
        'live'   => true,
        'formId' => 'fbrBizProfileForm',
        'paper'  => ($company->print_paper_size ?? 'thermal') === 'thermal58' ? '58mm' : '80mm',
        'fieldMap' => [
            'orderMatch' => null,
            'address' => 'rd_show_address',
            'phone' => 'rd_show_phone',
            'ntn' => 'rd_show_ntn',
            'cashier' => 'rd_show_cashier',
            'footer' => 'rd_show_footer',
            'footerText' => 'receipt_footer_note',
            'paper' => 'print_paper_size',
            'email' => null, 'bizname' => null, 'devby' => null, 'tax' => null,
            'logo' => null, 'logoFinalsOnly' => null, 'menuQr' => null,
        ],
        'textMap' => [
            'name' => ['name'],
            'address' => ['address'],
            'phone' => ['phone'],
            'ntn' => ['ntn'],
        ],
        'prefs'  => [
            'address' => (bool) $rd['show_address'],
            'ntn' => (bool) $rd['show_ntn'],
            'phone' => (bool) $rd['show_mobile'],
            'cashier' => (bool) $rd['show_cashier'],
            'bizname' => true,
            'footer' => (bool) $rd['show_footer'],
            'footerText' => (string) ($company->receipt_footer_note ?? ''),
            'tax' => true,
            'logo' => (bool) ($ps['show_logo'] ?? true),
            'logoFinalsOnly' => false,
            'orderMatch' => in_array($company->order_match_style ?? 'off', ['off', 'token', 'code'], true) ? ($company->order_match_style ?? 'off') : 'off',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
@endphp
<div class="max-w-3xl lg:max-w-6xl mx-auto">
    @include('fbr-pos.partials.back-link')
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.business_profile') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.business_profile_desc') }}</p>
    </div>

    @if(session('success'))
        <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300 text-sm font-medium">
            ✓ {{ session('success') }}
        </div>
    @endif

    {{-- x-data MUST be single-quoted (config JSON carries structural double quotes). --}}
    <div class="lg:grid lg:grid-cols-5 lg:gap-6 lg:items-start" x-data='rcptThemePicker({!! $rcptThemeCfg !!})'>
    <form method="POST" action="{{ route('fbrpos.business-profile') }}" enctype="multipart/form-data" id="fbrBizProfileForm" class="lg:col-span-3">
        @csrf

        {{-- Live preview on small screens (the lg aside is hidden there) --}}
        <div class="lg:hidden mb-5">
            @include('pos.partials.receipt-theme-preview', ['company' => $company, 'mode' => 'fbr'])
        </div>

        {{-- Business details --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6 space-y-5 mb-5">
            <h2 class="text-base font-bold text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-2">📝 {{ __('pos.business_details') }}</h2>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.business_name_req') }}</label>
                <input type="text" name="name" value="{{ old('name', $company->name) }}" required
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.address_label') }}</label>
                <textarea name="address" rows="2"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">{{ old('address', $company->address) }}</textarea>
                @error('address') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.phone_label') }}</label>
                    <input type="text" name="phone" value="{{ old('phone', $company->phone) }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                    @error('phone') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.email_label') }}</label>
                    <input type="email" name="email" value="{{ old('email', $company->email) }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                    @error('email') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.ntn_label') }}</label>
                <input type="text" name="ntn" value="{{ old('ntn', $company->ntn) }}"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                @error('ntn') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.owner_cnic_label') }}</label>
                <input type="text" name="cnic" value="{{ old('cnic', $company->cnic) }}" placeholder="35299-1234567-1"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.cnic_login_hint') }}</p>
                @error('cnic') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Logo --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6 space-y-4 mb-5">
            <h2 class="text-base font-bold text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-2">🖼️ {{ __('pos.business_logo') }}</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.business_logo_hint') }}</p>

            @if($company->logo_path)
                <div class="flex items-start gap-4 p-3 bg-gray-50 dark:bg-gray-800/50 rounded-lg border border-gray-200 dark:border-gray-700">
                    <img src="{{ asset('storage/' . $company->logo_path) }}" alt="{{ __('pos.current_logo_alt') }}" class="h-20 w-auto max-w-[200px] object-contain bg-white p-1 rounded border">
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ __('pos.current_logo') }}</p>
                        <p class="text-xs text-gray-500 mt-1">{{ __('pos.upload_new_logo_hint') }}</p>
                        <label class="mt-2 inline-flex items-center gap-2 text-xs text-red-600 dark:text-red-400 cursor-pointer">
                            <input type="checkbox" name="remove_logo" value="1" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                            {{ __('pos.remove_current_logo') }}
                        </label>
                    </div>
                </div>
            @endif

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ $company->logo_path ? __('pos.replace_logo') : __('pos.upload_logo') }}</label>
                <input type="file" name="logo" accept="image/png,image/jpeg,image/webp"
                    class="w-full text-sm text-gray-700 dark:text-gray-300 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-bold file:bg-blue-600 file:text-white hover:file:bg-blue-700 file:cursor-pointer cursor-pointer">
                @error('logo') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Print Settings --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6 space-y-4 mb-5">
            <h2 class="text-base font-bold text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-2">🖨️ {{ __('pos.print_settings') }}</h2>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('pos.receipt_paper_size') }}</label>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    @php $current = old('print_paper_size', $company->print_paper_size ?? 'thermal'); @endphp

                    <label class="relative flex cursor-pointer rounded-lg border-2 p-4 transition
                                 {{ $current === 'thermal' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30 ring-2 ring-blue-300' : 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 hover:border-blue-400' }}">
                        <input type="radio" name="print_paper_size" value="thermal" class="sr-only" {{ $current === 'thermal' ? 'checked' : '' }}>
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">🧾</span>
                            <div>
                                <div class="font-bold text-sm text-gray-900 dark:text-white">{{ __('pos.thermal_printer_80mm') }}</div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.thermal_printer_80mm_desc') }}</p>
                            </div>
                        </div>
                    </label>

                    <label class="relative flex cursor-pointer rounded-lg border-2 p-4 transition
                                 {{ $current === 'thermal58' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30 ring-2 ring-blue-300' : 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 hover:border-blue-400' }}">
                        <input type="radio" name="print_paper_size" value="thermal58" class="sr-only" {{ $current === 'thermal58' ? 'checked' : '' }}>
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">🧾</span>
                            <div>
                                <div class="font-bold text-sm text-gray-900 dark:text-white">{{ __('pos.small_thermal_58mm') }}</div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.small_thermal_58mm_desc') }}</p>
                            </div>
                        </div>
                    </label>

                    <label class="relative flex cursor-pointer rounded-lg border-2 p-4 transition
                                 {{ $current === 'a4' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30 ring-2 ring-blue-300' : 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 hover:border-blue-400' }}">
                        <input type="radio" name="print_paper_size" value="a4" class="sr-only" {{ $current === 'a4' ? 'checked' : '' }}>
                        <div class="flex items-start gap-3">
                            <span class="text-3xl">📄</span>
                            <div>
                                <div class="font-bold text-sm text-gray-900 dark:text-white">{{ __('pos.a4_printer_thermal_style') }}</div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.a4_printer_desc') }} <span class="text-emerald-700 dark:text-emerald-400 font-semibold">{{ __('pos.no_cutting_required') }}</span></p>
                            </div>
                        </div>
                    </label>
                </div>
                @error('print_paper_size') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- Print position (31 Jul 2026 — mirrors PRA slips): opt-in per-company
                 center / left-margin correction for printer-driver offsets. --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.print_position') }}</label>
                    <select name="kot_align_center" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                        <option value="0" {{ !($company->kot_align_center ?? false) ? 'selected' : '' }}>{{ __('pos.print_pos_left_edge') }}</option>
                        <option value="1" {{ ($company->kot_align_center ?? false) ? 'selected' : '' }}>{{ __('pos.print_pos_center') }}</option>
                    </select>
                    <p class="text-[11px] text-amber-600 dark:text-amber-400 mt-1">{{ __('pos.print_pos_center_warn') }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.left_margin_mm') }}</label>
                    <input type="number" name="kot_left_margin_mm" min="0" max="30" step="1" value="{{ (int) ($company->kot_left_margin_mm ?? 0) }}"
                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm">
                    <p class="text-[11px] text-gray-400 mt-1">{{ __('pos.left_margin_mm_hint') }}</p>
                </div>
            </div>

            {{-- Receipt Display toggles (owner, 22 Jul 2026 — mirrors PRA /pos/receipt-settings) --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('pos.receipt_display_label') }}</label>
                @php $rd = $company->displayPrefs('fbrpos'); @endphp
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    @foreach([
                        'rd_show_address' => [__('pos.show_address'), $rd['show_address']],
                        'rd_show_phone' => [__('pos.show_phone'), $rd['show_mobile']],
                        'rd_show_ntn' => [__('pos.show_ntn'), $rd['show_ntn']],
                        'rd_show_cashier' => [__('pos.show_cashier'), $rd['show_cashier']],
                        'rd_show_footer' => [__('pos.show_footer_thank_you'), $rd['show_footer']],
                    ] as $name => [$label, $checked])
                    <label class="flex items-center gap-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 cursor-pointer text-sm text-gray-800 dark:text-gray-200">
                        <input type="checkbox" name="{{ $name }}" value="1" {{ $checked ? 'checked' : '' }} class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span>{{ $label }}</span>
                    </label>
                    @endforeach
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.fbr_badge_always_on_receipt') }}</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.receipt_footer_note') }} <span class="text-gray-400 font-normal">({{ __('pos.optional_lc') }})</span></label>
                <input type="text" name="receipt_footer_note" maxlength="255" value="{{ old('receipt_footer_note', $company->receipt_footer_note) }}"
                    placeholder="{{ __('pos.ph_goods_once_sold') }}"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm shadow-sm focus:ring-blue-500 focus:border-blue-500">
                @error('receipt_footer_note') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('fbrpos.dashboard') }}" class="px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 hover:underline">{{ __('pos.cancel') }}</a>
            <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-md transition text-sm">
                {{ __('pos.save_changes') }}
            </button>
        </div>
    </form>

    {{-- Sticky live preview (desktop) — reads this form's toggles live (Task 717) --}}
    <div class="hidden lg:block lg:col-span-2 lg:sticky lg:top-4">
        @include('pos.partials.receipt-theme-preview', ['company' => $company, 'mode' => 'fbr'])
    </div>
    </div>
</div>
</x-fbr-pos-layout>
