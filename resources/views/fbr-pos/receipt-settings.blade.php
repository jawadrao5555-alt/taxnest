<x-fbr-pos-layout>
@php
    $ps = $company->posReceiptStyle();
    // Receipt Themes + live preview (Task 712) — same definitions (single
    // truth: PosReceiptThemes) and partials as PRA receipt-settings. FBR's
    // display prefs live on the business-profile page, so the preview reads
    // them STATICALLY from the saved fbrpos set; theme + order-match radios
    // react live. UTF-8-safe encode (footer note is user content).
    $rd = $company->displayPrefs('fbrpos');
    $rcptThemeCfg = json_encode([
        'theme'  => \App\Support\PosReceiptThemes::resolve($ps),
        'themes' => \App\Support\PosReceiptThemes::clientMap(),
        'mode'   => 'fbr',
        'live'   => true,
        'formId' => 'rcptSettingsForm',
        'paper'  => ($company->print_paper_size ?? 'thermal') === 'thermal58' ? '58mm' : '80mm',
        'prefs'  => [
            'address' => (bool) $rd['show_address'],
            'ntn' => (bool) $rd['show_ntn'],
            'phone' => (bool) $rd['show_mobile'],
            'cashier' => (bool) $rd['show_cashier'],
            'bizname' => (bool) ($rd['show_business_name'] ?? true),
            'footer' => (bool) $rd['show_footer'],
            'footerText' => (string) ($rd['footer_text'] ?? ''),
            'tax' => (bool) ($rd['show_tax'] ?? true),
            'logo' => (bool) ($ps['show_logo'] ?? true),
            'logoFinalsOnly' => false,
            'verifyLine' => (bool) ($rd['show_verify_line'] ?? true),
            'orderMatch' => in_array($company->order_match_style ?? 'off', ['off', 'token', 'code'], true) ? ($company->order_match_style ?? 'off') : 'off',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
@endphp
<div class="max-w-2xl lg:max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('fbr-pos.partials.back-link')
    <a href="{{ route('fbrpos.customize') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 transition mb-3">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        {{ __('pos.back_to_customize') }}
    </a>
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-1">🖋️ {{ __('pos.receipt_print_style') }}</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{{ __('pos.fbr_receipt_style_sub') }}</p>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm border border-emerald-200 dark:border-emerald-800">{{ session('success') }}</div>
    @endif

    @if($errors->any())
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm border border-red-200 dark:border-red-800">
        <ul class="list-disc list-inside">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- NOTE: x-data attribute MUST be single-quoted — the config JSON's structural
         double quotes would terminate a double-quoted attribute (JSON_HEX_APOS
         escapes any apostrophes inside string values). --}}
    <div class="lg:grid lg:grid-cols-5 lg:gap-6 lg:items-start" x-data='rcptThemePicker({!! $rcptThemeCfg !!})'>
    <form method="POST" action="{{ route('fbrpos.receipt-settings') }}" class="space-y-5 lg:col-span-3" id="rcptSettingsForm">
        @csrf

        {{-- Receipt Theme cards (Task 712) — replaces the old bold toggle +
             logo select; the save path still accepts legacy rp_style_bold /
             rp_logo_style POSTs from old cached forms. --}}
        @include('pos.partials.receipt-theme-cards', ['accent' => 'blue'])

        {{-- Live preview on small screens (the lg aside is hidden there) --}}
        <div class="lg:hidden">
            @include('pos.partials.receipt-theme-preview', ['company' => $company, 'mode' => 'fbr'])
        </div>

        {{-- Task 769: "Scan with FBR Tax Asaan App" verify-line toggle — mirrors
             PRA's Task 765 control; stored in invoice_display_prefs['fbrpos'].
             Hidden rp_verify_present marker: a stale cached form without this
             card must never silently flip the line OFF on save. --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
            <input type="hidden" name="rp_verify_present" value="1">
            <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg border {{ ($rd['show_verify_line'] ?? true) ? 'border-blue-400 bg-blue-50/40 dark:bg-blue-900/10' : 'border-gray-200 dark:border-gray-700' }} transition">
                <input type="checkbox" name="rp_show_verify_line" value="1" {{ ($rd['show_verify_line'] ?? true) ? 'checked' : '' }}
                       class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                <span class="flex-1 min-w-0">
                    <span class="block text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.show_verify_line_fbr') }}</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.show_verify_line_fbr_hint') }}</span>
                </span>
            </label>
        </div>

        {{-- Task 565: opt-in "Print se pehle poocho (Yes/No)" — shared flag with
             PRA POS (pos_printer_settings). Payment success par auto-print chain
             se pehle fauri Yes/No dialog. Default OFF. --}}
        @php $pconf = $company->printerSettings(); @endphp
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
            <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg border {{ !empty($pconf['print_confirm_ask']) ? 'border-blue-400 bg-blue-50/40 dark:bg-blue-900/10' : 'border-gray-200 dark:border-gray-700' }} transition">
                {{-- rp_print_confirm_present = presence marker so a stale/cached POST
                     can never silently flip this setting OFF (mirrors rp_verify_present). --}}
                <input type="hidden" name="rp_print_confirm_present" value="1">
                <input type="checkbox" name="rp_print_confirm" value="1" {{ !empty($pconf['print_confirm_ask']) ? 'checked' : '' }}
                       class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                <span class="flex-1 min-w-0">
                    <span class="block text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.print_confirm_ask_label') }}</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.print_confirm_ask_hint') }}</span>
                </span>
            </label>
        </div>

        {{-- A shop-wide default replaces the previous browser-only preference.
             Cashiers may still change the checkbox for an individual delivery. --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5 flex items-center justify-between gap-4">
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">🚚 {{ __('pos.delivery_receipt_default_label') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.delivery_receipt_default_hint') }}</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer shrink-0">
                <input type="hidden" name="rp_delivery_receipt_present" value="1">
                <input type="hidden" name="rp_delivery_receipt_on_assign" value="0">
                <input type="checkbox" name="rp_delivery_receipt_on_assign" value="1" {{ ($company->delivery_receipt_print_on_assign ?? false) ? 'checked' : '' }} class="sr-only peer">
                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-blue-600"></div>
            </label>
        </div>
        {{-- Task 1263: PRA-parity receipt display prefs — stored in the fbrpos
             set. rp_fbr_display_present marker: a stale cached form without
             these checkboxes must never silently flip everything OFF on save.
             (Some of these are also on Business Profile — last save wins.) --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
            <input type="hidden" name="rp_fbr_display_present" value="1">
            <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-3">🧾 {{ __('pos.receipt_content_section') }} <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('pos.receipt_content_sub') }}</span></h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-blue-300 dark:hover:border-blue-700 transition">
                    <input type="checkbox" name="rp_show_address" value="1" {{ $rd['show_address'] ? 'checked' : '' }} class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                    <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_address') }}</span>
                </label>
                <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-blue-300 dark:hover:border-blue-700 transition">
                    <input type="checkbox" name="rp_show_ntn" value="1" {{ $rd['show_ntn'] ? 'checked' : '' }} class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                    <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_ntn') }}</span>
                </label>
                <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-blue-300 dark:hover:border-blue-700 transition">
                    <input type="checkbox" name="rp_show_email" value="1" {{ $rd['show_email'] ? 'checked' : '' }} class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                    <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_email') }}</span>
                </label>
                <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-blue-300 dark:hover:border-blue-700 transition">
                    <input type="checkbox" name="rp_show_mobile" value="1" {{ $rd['show_mobile'] ? 'checked' : '' }} class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                    <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_phone_mobile') }}</span>
                </label>
                <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-blue-300 dark:hover:border-blue-700 transition">
                    <input type="checkbox" name="rp_show_cashier" value="1" {{ $rd['show_cashier'] ? 'checked' : '' }} class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                    <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_cashier_details') }}</span>
                </label>
                <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-blue-300 dark:hover:border-blue-700 transition">
                    <input type="checkbox" name="rp_show_business_name" value="1" {{ ($rd['show_business_name'] ?? true) ? 'checked' : '' }} class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                    <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_business_name') }}</span>
                </label>
                <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-blue-300 dark:hover:border-blue-700 transition">
                    <input type="checkbox" name="rp_show_developed_by" value="1" {{ ($rd['show_developed_by'] ?? true) ? 'checked' : '' }} class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                    <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_developed_by_line') }}</span>
                </label>
            </div>
            <div class="mt-3 p-3 rounded-lg border-2 {{ ($rd['show_tax'] ?? true) ? 'border-amber-400 bg-amber-50/40 dark:bg-amber-900/10' : 'border-gray-200 dark:border-gray-700' }}">
                <label class="flex items-start gap-2.5 cursor-pointer">
                    <input type="checkbox" name="rp_show_tax" value="1" {{ ($rd['show_tax'] ?? true) ? 'checked' : '' }} class="mt-0.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500 w-4 h-4">
                    <span class="flex-1 min-w-0">
                        <span class="block text-sm font-bold text-gray-900 dark:text-white">🧾 {{ __('pos.show_tax_on_fbr_receipt') }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.show_tax_fbr_hint') }}</span>
                    </span>
                </label>
            </div>
            <div class="mt-4">
                <label class="flex items-center gap-2.5 cursor-pointer mb-2">
                    <input type="checkbox" name="rp_show_footer" value="1" {{ $rd['show_footer'] ? 'checked' : '' }} class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                    <span class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ __('pos.show_footer_thank_you') }}</span>
                </label>
                <input type="text" name="rp_footer_text" value="{{ $rd['footer_text'] }}" maxlength="150" placeholder="{{ __('pos.ph_thank_you_purchase') }}"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500"
                    autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.leave_blank_default_msg') }}</p>
            </div>
        </div>

        {{-- Task 1263: logo master switch — shared pos_style key (same one the
             thermal receipt already reads). logo_finals_only is ignored on FBR
             (no local/provisional flow), so no sub-option here. --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
            <input type="hidden" name="rp_pos_style_present" value="1">
            <label class="flex items-start gap-2.5 cursor-pointer p-3 rounded-lg border {{ ($ps['show_logo'] ?? true) ? 'border-blue-400 bg-blue-50/40 dark:bg-blue-900/10' : 'border-gray-200 dark:border-gray-700' }} transition">
                <input type="checkbox" name="rp_show_logo" value="1" {{ ($ps['show_logo'] ?? true) ? 'checked' : '' }}
                       class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                <span class="flex-1 min-w-0">
                    <span class="block text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.show_logo_label') }}</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.show_logo_hint') }}</span>
                </span>
            </label>
        </div>

        {{-- Task 1263: paper size — FBR's print_paper_size column (also on
             Business Profile; last save wins). A4 switches the browser receipt
             itself to A4 layout; PDF downloads are full A4 invoices by design. --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
            @php $fbrPaper = $company->print_paper_size ?? 'thermal'; @endphp
            <label class="block text-sm font-medium text-gray-800 dark:text-gray-200 mb-2">🖨️ {{ __('pos.receipt_paper_size') }}</label>
            <select name="rp_printer_size" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500">
                <option value="80mm" {{ $fbrPaper === 'thermal' ? 'selected' : '' }}>{{ __('pos.paper_80mm') }}</option>
                <option value="58mm" {{ $fbrPaper === 'thermal58' ? 'selected' : '' }}>{{ __('pos.paper_58mm') }}</option>
                <option value="a4" {{ $fbrPaper === 'a4' ? 'selected' : '' }}>{{ __('pos.a4_printer_thermal_style') }}</option>
            </select>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.paper_size_hint') }}</p>
        </div>

        {{-- Task 1263: print position — receipt_* columns (decoupled from KOT).
             hasColumn guard mirrors business-profile (PROD schema-drift parity). --}}
        @if(\Illuminate\Support\Facades\Schema::hasColumn('companies', 'receipt_align_center'))
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
            @php
                $rpAlignVal = (bool) ($company->receipt_align_center ?? false);
                $rpMarginVal = (int) ($company->receipt_left_margin_mm ?? 0);
            @endphp
            <label class="block text-sm font-medium text-gray-800 dark:text-gray-200 mb-2">📐 {{ __('pos.print_position') }} <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('pos.applies_receipts_only') }}</span></label>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <select name="rp_align_center" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="0" {{ !$rpAlignVal ? 'selected' : '' }}>{{ __('pos.print_pos_left_edge') }}</option>
                        <option value="1" {{ $rpAlignVal ? 'selected' : '' }}>{{ __('pos.print_pos_center') }}</option>
                    </select>
                    <p class="text-[11px] text-amber-600 dark:text-amber-400 mt-1">{{ __('pos.print_pos_center_warn') }}</p>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('pos.left_margin_mm') }}</label>
                    <input type="number" name="rp_left_margin_mm" min="0" max="30" step="1" value="{{ $rpMarginVal }}"
                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-blue-500 focus:ring-blue-500">
                    <p class="text-[11px] text-gray-400 mt-1">{{ __('pos.left_margin_mm_hint') }}</p>
                </div>
            </div>
        </div>
        @endif

        {{-- Order Matching (Aug 2026) — mirrors PRA receipt-settings placement.
             Applies to receipt AND to KOT (when kitchen_printer_enabled is on). --}}
        @if(\Illuminate\Support\Facades\Schema::hasColumn('companies', 'order_match_style'))
        @php $omPref = in_array($company->order_match_style ?? 'off', ['off','token','code'], true) ? ($company->order_match_style ?? 'off') : 'off'; @endphp
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
            <label class="block text-sm font-medium text-gray-800 dark:text-gray-200 mb-1">🔢 {{ __('pos.order_match_title') }}</label>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('pos.order_match_intro') }}</p>
            <div class="space-y-2">
                <label class="flex items-start gap-2 cursor-pointer">
                    <input type="radio" name="rp_order_match" value="off" {{ $omPref === 'off' ? 'checked' : '' }} class="mt-0.5 text-blue-600 focus:ring-blue-500">
                    <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.order_match_off') }} <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('pos.order_match_off_hint') }}</span></span>
                </label>
                <label class="flex items-start gap-2 cursor-pointer">
                    <input type="radio" name="rp_order_match" value="token" {{ $omPref === 'token' ? 'checked' : '' }} class="mt-0.5 text-blue-600 focus:ring-blue-500">
                    <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.order_match_token') }} <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('pos.order_match_token_hint') }}</span></span>
                </label>
                <label class="flex items-start gap-2 cursor-pointer">
                    <input type="radio" name="rp_order_match" value="code" {{ $omPref === 'code' ? 'checked' : '' }} class="mt-0.5 text-blue-600 focus:ring-blue-500">
                    <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.order_match_code') }} <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('pos.order_match_code_hint') }}</span></span>
                </label>
            </div>
        </div>
        @endif

        <div class="flex items-center justify-between gap-3 pt-1">
            <a href="{{ route('fbrpos.customize') }}"
               class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                {{ __('pos.back_to_customize') }}
            </a>
            <button type="submit"
                    class="px-8 py-2.5 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition shadow-sm">
                {{ __('pos.save_receipt_settings') }}
            </button>
        </div>
    </form>

    {{-- Sticky live preview (desktop) — theme + order-match react instantly;
         FBR display prefs render from the saved fbrpos set (Task 712). --}}
    <div class="hidden lg:block lg:col-span-2 lg:sticky lg:top-4">
        @include('pos.partials.receipt-theme-preview', ['company' => $company, 'mode' => 'fbr'])
    </div>
    </div>
</div>
</x-fbr-pos-layout>
