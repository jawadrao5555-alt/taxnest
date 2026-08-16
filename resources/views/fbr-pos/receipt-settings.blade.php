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
            'bizname' => true,
            'footer' => (bool) $rd['show_footer'],
            'footerText' => '',
            'tax' => true,
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
