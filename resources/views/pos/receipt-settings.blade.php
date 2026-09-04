<x-pos-layout>
<div class="max-w-3xl lg:max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <a href="{{ route('pos.customize') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition mb-3">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        {{ __('pos.back_to_customize') }}
    </a>
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">{{ __('pos.receipt_display_options') }}</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{!! __('pos.receipt_display_sub_html', ['own' => '<span class="font-semibold text-gray-700 dark:text-gray-200">' . e(__('pos.own_word')) . '</span>']) !!}</p>

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

    {{-- Owner (Jul 2026): PRA and Local receipts each get a FULL independent display
         set. PRA set = legacy invoice_display_prefs['pos'] + pos_receipt_show_tax
         column; Local set = invoice_display_prefs['pos_local'] (mirrors PRA until
         first customized). Both tab panels stay in the DOM (x-show) so ONE save
         submits BOTH sets. Paper size stays global (it's the printer, not the bill). --}}
    @php
        $rp = $company->posReceiptPrefs('pra');
        $lp = $company->posReceiptPrefs('local');
        $ps = $company->posReceiptStyle();
        // Receipt Themes + live preview (Task 712): Alpine state shared by the
        // form (tab switcher + theme cards) AND the sticky preview aside — so
        // x-data moves from the <form> to the wrapper div below. Config is
        // UTF-8-safe encoded (footer text is user content; a bad byte must
        // never kill the whole page's Alpine scope — see replit.md pitfalls).
        $rcptThemeCfg = json_encode([
            'theme'  => \App\Support\PosReceiptThemes::resolve($ps),
            'themes' => \App\Support\PosReceiptThemes::clientMap(),
            'mode'   => 'pra',
            'live'   => true,
            'formId' => 'rcptSettingsForm',
            'paper'  => ($company->receipt_printer_size ?? '80mm') === '58mm' ? '58mm' : '80mm',
            'prefs'  => [
                'address' => (bool) $rp['show_address'],
                'ntn' => (bool) $rp['show_ntn'],
                'email' => (bool) $rp['show_email'],
                'phone' => (bool) $rp['show_mobile'],
                'cashier' => (bool) $rp['show_cashier'],
                'bizname' => (bool) ($rp['show_business_name'] ?? true),
                'devby' => (bool) ($rp['show_developed_by'] ?? true),
                'footer' => (bool) $rp['show_footer'],
                'footerText' => (string) ($rp['footer_text'] ?? ''),
                'tax' => (bool) $rp['show_tax'],
                'logo' => (bool) ($ps['show_logo'] ?? true),
                'logoFinalsOnly' => (bool) ($ps['logo_finals_only'] ?? false),
                'menuQr' => (bool) ($ps['show_menu_qr'] ?? true),
                'orderMatch' => in_array($company->order_match_style ?? 'off', ['off', 'token', 'code'], true) ? ($company->order_match_style ?? 'off') : 'off',
                // show_verify_line (Aug 2026): drives the preview partial's QR caption.
                'verifyLine' => (bool) ($rp['show_verify_line'] ?? true),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    @endphp
    {{-- NOTE: x-data attribute MUST be single-quoted — the config JSON's structural
         double quotes would terminate a double-quoted attribute (JSON_HEX_APOS
         escapes any apostrophes inside string values). --}}
    <div class="lg:grid lg:grid-cols-5 lg:gap-6 lg:items-start" x-data='rcptThemePicker({!! $rcptThemeCfg !!})'>
    <form method="POST" action="{{ route('pos.receipt-settings') }}" class="space-y-6 lg:col-span-3" id="rcptSettingsForm">
        @csrf
        {{-- Marker: tells the handler this form was freshly rendered, so it may safely
             write checkbox-based pos_style keys (show_logo, logo_finals_only,
             show_menu_qr, pdf_paper). A stale cached form that lacks this marker
             leaves those keys untouched — mirrors rp_verify_present on the FBR page. --}}
        <input type="hidden" name="rp_pos_style_present" value="1">
        {{-- Task 1377: same idea, one marker per DISPLAY SET. Each block is a
             wholesale rewrite from checkbox presence, so a stale cached copy of
             this page (it used to be runtime-cached by the service worker) wiped
             every toggle it did not carry — that is how a company's whole Local
             set went false and its local bills stopped printing the tax line.
             No marker + no fields of that set = leave the stored set untouched. --}}
        <input type="hidden" name="rp_present" value="1">
        <input type="hidden" name="lp_present" value="1">

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
            {{-- Tab switcher --}}
            <div class="flex border-b border-gray-200 dark:border-gray-700">
                <button type="button" @click="tab = 'pra'"
                    :class="tab === 'pra' ? 'bg-purple-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'"
                    class="flex-1 px-4 py-3 text-sm font-semibold transition flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    {{ __('pos.pra_receipt') }}
                </button>
                <button type="button" @click="tab = 'local'"
                    :class="tab === 'local' ? 'bg-purple-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'"
                    class="flex-1 px-4 py-3 text-sm font-semibold transition flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2a2 2 0 012-2h2a2 2 0 012 2v2m-6 4h6a2 2 0 002-2V7a2 2 0 00-2-2H9a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    {{ __('pos.local_receipt') }}
                </button>
            </div>

            {{-- ============ PRA (fiscal) receipt panel ============ --}}
            <div x-show="tab === 'pra'" class="p-6">
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{!! __('pos.pra_panel_note_html', ['reported' => '<span class="font-semibold">' . e(__('pos.reported_to_pra')) . '</span>', 'series' => '<span class="font-mono">POS-</span>']) !!}</p>
                {{-- Task 654 (ZFC): stream clarity — reporting-OFF finals + exempt bills follow the LOCAL tab. --}}
                <p class="text-[11px] text-amber-700 dark:text-amber-400 mb-4">{{ __('pos.pra_panel_stream_hint') }}</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="rp_show_address" value="1" {{ $rp['show_address'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_address') }}</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="rp_show_ntn" value="1" {{ $rp['show_ntn'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_ntn') }}</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="rp_show_email" value="1" {{ $rp['show_email'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_email') }}</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="rp_show_mobile" value="1" {{ $rp['show_mobile'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_phone_mobile') }}</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="rp_show_cashier" value="1" {{ $rp['show_cashier'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_cashier_details') }}</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="rp_show_business_name" value="1" {{ ($rp['show_business_name'] ?? true) ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_business_name') }}</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="rp_show_developed_by" value="1" {{ ($rp['show_developed_by'] ?? true) ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_developed_by_line') }}</span>
                    </label>
                </div>
                <div class="mt-3">
                    <label class="flex items-start gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="rp_show_verify_line" value="1" {{ ($rp['show_verify_line'] ?? true) ? 'checked' : '' }} class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="flex-1 min-w-0">
                            <span class="block text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_verify_line_pra') }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.show_verify_line_pra_hint') }}</span>
                        </span>
                    </label>
                </div>
                <div class="mt-3 p-3 rounded-lg border-2 {{ $rp['show_tax'] ? 'border-amber-400 bg-amber-50/40 dark:bg-amber-900/10' : 'border-gray-200 dark:border-gray-700' }}">
                    <label class="flex items-start gap-2.5 cursor-pointer">
                        <input type="checkbox" name="rp_show_tax" value="1" {{ $rp['show_tax'] ? 'checked' : '' }} class="mt-0.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500 w-4 h-4">
                        <span class="flex-1 min-w-0">
                            <span class="block text-sm font-bold text-gray-900 dark:text-white">🧾 {{ __('pos.show_tax_on_pra_receipt') }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.show_tax_pra_hint') }}</span>
                        </span>
                    </label>
                </div>
                <div class="mt-4">
                    <label class="flex items-center gap-2.5 cursor-pointer mb-2">
                        <input type="checkbox" name="rp_show_footer" value="1" {{ $rp['show_footer'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ __('pos.show_footer_pra') }}</span>
                    </label>
                    <input type="text" name="rp_footer_text" value="{{ $rp['footer_text'] }}" maxlength="150" placeholder="{{ __('pos.ph_thank_you_purchase') }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-purple-500 focus:ring-purple-500"
                        autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.leave_blank_default_msg') }}</p>
                </div>
            </div>

            {{-- ============ Local receipt panel ============ --}}
            <div x-show="tab === 'local'" class="p-6" style="display:none;">
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{!! __('pos.local_panel_note_html', ['local' => '<span class="font-semibold">' . e(__('pos.local_bills')) . '</span>', 'series' => '<span class="font-mono">L</span>']) !!}</p>
                {{-- Task 654 (ZFC): stream clarity — reporting-OFF finals + exempt bills land HERE. --}}
                <p class="text-[11px] text-amber-700 dark:text-amber-400 mb-4">{{ __('pos.local_panel_stream_hint') }}</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="lp_show_address" value="1" {{ $lp['show_address'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_address') }}</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="lp_show_ntn" value="1" {{ $lp['show_ntn'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_ntn') }}</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="lp_show_email" value="1" {{ $lp['show_email'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_email') }}</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="lp_show_mobile" value="1" {{ $lp['show_mobile'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_phone_mobile') }}</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="lp_show_cashier" value="1" {{ $lp['show_cashier'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_cashier_details') }}</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="lp_show_business_name" value="1" {{ ($lp['show_business_name'] ?? true) ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_business_name') }}</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="lp_show_developed_by" value="1" {{ ($lp['show_developed_by'] ?? true) ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.show_developed_by_line') }}</span>
                    </label>
                </div>
                <div class="mt-4 p-3 rounded-lg border-2 {{ $lp['show_tax'] ? 'border-amber-400 bg-amber-50/40 dark:bg-amber-900/10' : 'border-gray-200 dark:border-gray-700' }}">
                    <label class="flex items-start gap-2.5 cursor-pointer">
                        <input type="checkbox" name="lp_show_tax" value="1" {{ $lp['show_tax'] ? 'checked' : '' }} class="mt-0.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500 w-4 h-4">
                        <span class="flex-1 min-w-0">
                            <span class="block text-sm font-bold text-gray-900 dark:text-white">🧾 {{ __('pos.show_tax_on_local_receipt') }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.show_tax_local_hint') }}</span>
                        </span>
                    </label>
                </div>
                <div class="mt-4">
                    <label class="flex items-center gap-2.5 cursor-pointer mb-2">
                        <input type="checkbox" name="lp_show_footer" value="1" {{ $lp['show_footer'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ __('pos.show_footer_local') }}</span>
                    </label>
                    <input type="text" name="lp_footer_text" value="{{ $lp['footer_text'] }}" maxlength="150" placeholder="{{ __('pos.ph_thank_you_purchase') }}"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-purple-500 focus:ring-purple-500"
                        autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.leave_blank_default_msg') }}</p>
                </div>
            </div>
        </div>

        {{-- Paper size is GLOBAL — it's a printer property, not a bill-type setting. --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6">
            <label class="block text-sm font-medium text-gray-800 dark:text-gray-200 mb-2">🖨️ {{ __('pos.receipt_paper_size') }} <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('pos.applies_both_receipt_types') }}</span></label>
            <select name="rp_printer_size" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-purple-500 focus:ring-purple-500">
                <option value="80mm" {{ ($company->receipt_printer_size ?? '80mm') === '80mm' ? 'selected' : '' }}>{{ __('pos.paper_80mm') }}</option>
                <option value="58mm" {{ ($company->receipt_printer_size ?? '80mm') === '58mm' ? 'selected' : '' }}>{{ __('pos.paper_58mm') }}</option>
            </select>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.paper_size_hint') }}</p>
        </div>

        {{-- Print Position + Left Margin (Pizza Master, 11 Aug 2026): ab receipts ke
             APNE columns hain (receipt_align_center / receipt_left_margin_mm) — KOT
             se alag, taake ek printer theek karne par doosre ki parchi na kat jaye.
             NULL = purane shared kot_* par fallback (purani shops jaisi thi waisi).
             KOT ki apni position neeche "KOT Print Style" card mein hai. --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6">
            @php
                $rpAlignVal = (bool) ($company->receipt_align_center ?? $company->kot_align_center ?? false);
                $rpMarginVal = (int) ($company->receipt_left_margin_mm ?? $company->kot_left_margin_mm ?? 0);
            @endphp
            <label class="block text-sm font-medium text-gray-800 dark:text-gray-200 mb-2">📐 {{ __('pos.print_position') }} <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('pos.applies_receipts_only') }}</span></label>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <select name="rp_align_center" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-purple-500 focus:ring-purple-500">
                        <option value="0" {{ !$rpAlignVal ? 'selected' : '' }}>{{ __('pos.print_pos_left_edge') }}</option>
                        <option value="1" {{ $rpAlignVal ? 'selected' : '' }}>{{ __('pos.print_pos_center') }}</option>
                    </select>
                    <p class="text-[11px] text-amber-600 dark:text-amber-400 mt-1">{{ __('pos.print_pos_center_warn') }}</p>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('pos.left_margin_mm') }}</label>
                    <input type="number" name="rp_left_margin_mm" min="0" max="30" step="1" value="{{ $rpMarginVal }}"
                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-purple-500 focus:ring-purple-500">
                    <p class="text-[11px] text-gray-400 mt-1">{{ __('pos.left_margin_mm_hint') }}</p>
                </div>
            </div>
        </div>

        {{-- Dine-in FINAL bill auto-print (Pizza Master, 11 Aug 2026 — owner-approved
             for ALL PRA POS): proof bill table par pehle diya ja chuka hota hai,
             final ka auto-print kaghaz zaya karta tha. Sirf restaurant-mode shops
             ko dikhta hai (retail par dine-in hota hi nahi). --}}
        @if($company->restaurant_mode ?? false)
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">🍽️ {{ __('pos.dinein_autoprint_label') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.dinein_autoprint_hint') }}</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="hidden" name="rp_dinein_autoprint" value="0">
                <input type="checkbox" name="rp_dinein_autoprint" value="1" {{ ($company->print_on_pay_dinein ?? true) ? 'checked' : '' }} class="sr-only peer">
                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
            </label>
        </div>
        @endif

        {{-- Delivery receipt default is shared by every counter in this shop.
             The sale screen still lets a cashier change the choice for one bill. --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 flex items-center justify-between gap-4">
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">🚚 {{ __('pos.delivery_receipt_default_label') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.delivery_receipt_default_hint') }}</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer shrink-0">
                <input type="hidden" name="rp_delivery_receipt_present" value="1">
                <input type="hidden" name="rp_delivery_receipt_on_assign" value="0">
                <input type="checkbox" name="rp_delivery_receipt_on_assign" value="1" {{ ($company->delivery_receipt_print_on_assign ?? false) ? 'checked' : '' }} class="sr-only peer">
                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
            </label>
        </div>
        @include('pos.partials.rider-bill-preview-settings', ['company' => $company])

        {{-- KOT Print Style (Aug 2026): same toggles as Kitchen Settings — exposed
             here too so shops without the kitchen module can control their KOT layout.
             Uses rp_kot_* prefix; PosController saves them to the same company columns. --}}
        {{-- KOT theme cards (Task 716): the old Compact toggle + align select are
             replaced by named preset cards mapping onto the SAME kot_compact /
             kot_align_center columns via App\Support\PosKotThemes (no template
             fork; kitchen-settings keeps writing the raw columns — last save
             wins, and resolve() pre-selects the right card either way). --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md"
             {{-- Task 757: pass the RAW nullable align — NULL now resolves to 'khula'
                  (left, matching actual print behaviour). Never `?? false` it
                  here; alignBool() handles the NULL→false mapping. --}}
             x-data='{ kotTheme: @json(\App\Support\PosKotThemes::resolve(['compact' => $company->kot_compact ?? false, 'align' => $company->kot_align_center])) }'>
            <div class="p-5 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white">🎫 {{ __('pos.kot_print_style') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.kot_print_style_hint') }}</p>
            </div>
            <div class="p-5 border-b border-gray-200 dark:border-gray-700">
                <label class="block text-sm font-semibold text-gray-900 dark:text-white mb-0.5">{{ __('pos.kot_theme_pick') }}</label>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('pos.kot_theme_pick_hint') }}</p>
                <input type="hidden" name="rp_kot_theme" :value="kotTheme">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    @foreach(\App\Support\PosKotThemes::THEMES as $ktKey => $ktDef)
                    <button type="button" @click="kotTheme = '{{ $ktKey }}'"
                            :class="kotTheme === '{{ $ktKey }}' ? 'border-purple-500 bg-purple-50/60 dark:bg-purple-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700'"
                            class="relative w-full rounded-xl border-2 p-3 text-left transition">
                        <span x-show="kotTheme === '{{ $ktKey }}'" x-cloak
                              class="absolute top-2 right-2 w-5 h-5 rounded-full bg-purple-600 text-white flex items-center justify-center">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                        </span>

                        {{-- Mini KOT sample — pure inline styles (no build needed). The
                             outer strip is the PAPER; the inner block is the ticket, so
                             the left/center position is visible at a glance. --}}
                        <span class="block" style="background:#f3f4f6;border:1px solid #d1d5db;border-radius:4px;padding:6px;width:96px;margin:0 auto;">
                            @if($ktKey === 'center')
                            <span style="display:block;width:72%;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:3px;padding:5px 6px;">
                                <span style="display:block;height:5px;background:#111827;border-radius:2px;width:80%;margin:0 auto 3px;"></span>
                                <span style="display:block;border-top:1px dashed #9ca3af;margin:3px 0;"></span>
                                <span style="display:block;height:3px;background:#374151;border-radius:2px;width:100%;margin-bottom:3px;"></span>
                                <span style="display:block;height:3px;background:#374151;border-radius:2px;width:100%;margin-bottom:3px;"></span>
                                <span style="display:block;height:3px;background:#374151;border-radius:2px;width:85%;margin:0 auto;"></span>
                            </span>
                            @elseif($ktKey === 'compact')
                            <span style="display:block;width:72%;margin-right:auto;background:#fff;border:1px solid #e5e7eb;border-radius:3px;padding:4px 5px;">
                                <span style="display:block;height:4px;background:#111827;border-radius:2px;width:70%;margin-bottom:2px;"></span>
                                <span style="display:block;border-top:1px dashed #9ca3af;margin:2px 0;"></span>
                                <span style="display:block;height:2px;background:#374151;border-radius:2px;width:100%;margin-bottom:2px;"></span>
                                <span style="display:block;height:2px;background:#374151;border-radius:2px;width:100%;margin-bottom:2px;"></span>
                                <span style="display:block;height:2px;background:#374151;border-radius:2px;width:100%;margin-bottom:2px;"></span>
                                <span style="display:block;height:2px;background:#374151;border-radius:2px;width:90%;"></span>
                            </span>
                            @else
                            <span style="display:block;width:72%;margin-right:auto;background:#fff;border:1px solid #e5e7eb;border-radius:3px;padding:5px 6px;">
                                <span style="display:block;height:5px;background:#111827;border-radius:2px;width:80%;margin-bottom:3px;"></span>
                                <span style="display:block;border-top:1px dashed #9ca3af;margin:3px 0;"></span>
                                <span style="display:block;height:3px;background:#374151;border-radius:2px;width:100%;margin-bottom:3px;"></span>
                                <span style="display:block;height:3px;background:#374151;border-radius:2px;width:100%;margin-bottom:3px;"></span>
                                <span style="display:block;height:3px;background:#374151;border-radius:2px;width:85%;"></span>
                            </span>
                            @endif
                        </span>

                        <span class="block text-sm font-bold text-gray-900 dark:text-white mt-2.5 text-center">{{ __($ktDef['label']) }}</span>
                        <span class="block text-[11px] text-gray-500 dark:text-gray-400 mt-0.5 text-center leading-snug">{{ __($ktDef['hint']) }}</span>
                    </button>
                    @endforeach
                </div>
            </div>
            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach([
                    ['rp_kot_show_customer',        'pos.show_customer_name',            'pos.show_customer_name_hint',           $company->kot_show_customer ?? true],
                    ['rp_kot_show_orderby',         'pos.show_order_by_item_count',      'pos.show_order_by_item_count_hint',     $company->kot_show_orderby ?? true],
                    ['rp_kot_show_barcode',         'pos.show_barcode',                  'pos.show_barcode_hint',                 $company->kot_show_barcode ?? true],
                    ['rp_kot_show_footer',          'pos.show_business_name_bottom',     'pos.show_business_name_bottom_hint',    $company->kot_show_footer ?? true],
                    ['rp_kot_show_kitchen_notes',   'pos.show_kitchen_notes_box',        'pos.show_kitchen_notes_box_hint',       $company->kot_show_kitchen_notes ?? false],
                ] as [$fieldName, $labelKey, $hintKey, $checked])
                <div class="p-5 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __($labelKey) }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __($hintKey) }}</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="hidden" name="{{ $fieldName }}" value="0">
                        <input type="checkbox" name="{{ $fieldName }}" value="1" {{ $checked ? 'checked' : '' }} class="sr-only peer">
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-500 peer-checked:bg-purple-600"></div>
                    </label>
                </div>
                @endforeach
                {{-- KOT ki APNI print position (Pizza Master, 11 Aug 2026): receipts
                     wale margin se bilkul alag — kitchen-settings wale hi kot_*
                     columns likhta hai (aakhri save jeet-ti hai). Task 716: left/center
                     ab upar wale theme cards se aata hai; yahan sirf fine-tune margin. --}}
                <div class="p-5">
                    <label class="block text-sm font-semibold text-gray-900 dark:text-white mb-0.5">📐 {{ __('pos.kot_print_position') }}</label>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('pos.kot_print_position_hint') }}</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('pos.left_margin_mm') }}</label>
                            <input type="number" name="rp_kot_left_margin_mm" min="0" max="30" step="1" value="{{ (int) ($company->kot_left_margin_mm ?? 0) }}"
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-purple-500 focus:ring-purple-500">
                            <p class="text-[11px] text-gray-400 mt-1">{{ __('pos.left_margin_mm_hint') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Order Matching (owner request 06 Aug 2026, customer voice notes): the
             sale receipt and the kitchen KOT carried DIFFERENT numbers (L-107 vs
             ORD-…), so counter staff couldn't pair a ready order with a bill.
             Per-company choice: Daily Token (easy to call out) or Unique Code
             (random — outsiders can't trace daily order volume). --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6">
            @php $omPref = in_array($company->order_match_style ?? 'off', ['off','token','code'], true) ? ($company->order_match_style ?? 'off') : 'off'; @endphp
            <label class="block text-sm font-medium text-gray-800 dark:text-gray-200 mb-1">🔢 {{ __('pos.order_match_title') }} <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('pos.order_match_scope') }}</span></label>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('pos.order_match_intro') }}</p>
            <div class="space-y-2">
                <label class="flex items-start gap-2 cursor-pointer">
                    <input type="radio" name="rp_order_match" value="off" {{ $omPref === 'off' ? 'checked' : '' }} class="mt-0.5 text-purple-600 focus:ring-purple-500">
                    <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.order_match_off') }} <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('pos.order_match_off_hint') }}</span></span>
                </label>
                <label class="flex items-start gap-2 cursor-pointer">
                    <input type="radio" name="rp_order_match" value="token" {{ $omPref === 'token' ? 'checked' : '' }} class="mt-0.5 text-purple-600 focus:ring-purple-500">
                    <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.order_match_token') }} <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('pos.order_match_token_hint') }}</span></span>
                </label>
                <label class="flex items-start gap-2 cursor-pointer">
                    <input type="radio" name="rp_order_match" value="code" {{ $omPref === 'code' ? 'checked' : '' }} class="mt-0.5 text-purple-600 focus:ring-purple-500">
                    <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('pos.order_match_code') }} <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('pos.order_match_code_hint') }}</span></span>
                </label>
            </div>
        </div>

        {{-- Bill Number Style (07 Aug 2026, 2-3 companies ki tajweez): har stream
             (PRA billing / Offline billing) apna numaya receipt number chunta hai —
             chalti serial ya roz ka token (subah 6 baje reset). Serial andar se
             hamesha chalta rahta hai (khata / search / return / PRA reporting). --}}
        <div id="bill-number-style" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6">
            @php
                $praNumPref = ($company->pra_number_style ?? 'serial') === 'token' ? 'token' : 'serial';
                $localNumPref = in_array(($company->local_number_style ?? 'serial'), ['serial', 'token', 'daily'], true)
                    ? $company->local_number_style
                    : 'serial';
            @endphp
            <label class="block text-sm font-medium text-gray-800 dark:text-gray-200 mb-1">🎫 {{ __('pos.number_style_title') }} <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('pos.number_style_scope') }}</span></label>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('pos.number_style_intro') }}</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.number_style_pra_label') }}</label>
                    <select name="rp_pra_number_style" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-purple-500 focus:ring-purple-500">
                        <option value="serial" {{ $praNumPref === 'serial' ? 'selected' : '' }}>{{ __('pos.number_style_serial') }}</option>
                        <option value="token" {{ $praNumPref === 'token' ? 'selected' : '' }}>{{ __('pos.number_style_token') }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.number_style_local_label') }}</label>
                    <select name="rp_local_number_style" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-purple-500 focus:ring-purple-500">
                        <option value="serial" {{ $localNumPref === 'serial' ? 'selected' : '' }}>{{ __('pos.number_style_serial') }}</option>
                        <option value="token" {{ $localNumPref === 'token' ? 'selected' : '' }}>{{ __('pos.number_style_token') }}</option>
                        {{-- ZFC, 1 Sep 2026: "L series roz L001 se shuru ho." Asal serial
                             roz reset nahi ho sakti (archived bill apna number rokay
                             rakhte hain), is liye CHHAPNE wala number roz ka hai aur
                             asal serial neechay salamat. --}}
                        <option value="daily" {{ $localNumPref === 'daily' ? 'selected' : '' }}>{{ __('pos.number_style_daily') }}</option>
                    </select>
                </div>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">{{ __('pos.number_style_hint') }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.number_style_daily_hint') }} <x-new-badge feature="local_daily_number" class="ml-1" /></p>
        </div>

        {{-- PDF Download Paper (customer video Jul 2026): downloaded PDFs printed on
             regular office printers came out shifted to the right edge and clipped —
             PDF viewers center the narrow thermal page on the driver's A4 canvas. --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6">
            @php $pdfPaperPref = ($company->invoice_display_prefs['pos_style']['pdf_paper'] ?? 'thermal') === 'a4' ? 'a4' : 'thermal'; @endphp
            <label class="block text-sm font-medium text-gray-800 dark:text-gray-200 mb-2">📄 {{ __('pos.pdf_download_paper') }} <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('pos.pdf_paper_scope') }}</span></label>
            <select name="rp_pdf_paper" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-purple-500 focus:ring-purple-500">
                <option value="thermal" {{ $pdfPaperPref === 'thermal' ? 'selected' : '' }}>{{ __('pos.pdf_paper_thermal') }}</option>
                <option value="a4" {{ $pdfPaperPref === 'a4' ? 'selected' : '' }}>{{ __('pos.pdf_paper_a4') }}</option>
            </select>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.pdf_paper_hint') }}</p>
        </div>

        {{-- Receipt Content Checklist (Task #292): simple on/off per optional element.
             Global (like paper size) — applies to both PRA and Local receipts.
             Tick = prints, untick = NEVER prints on ANY receipt path. --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6 space-y-4"
             x-data="{ showLogo: {{ ($ps['show_logo'] ?? true) ? 'true' : 'false' }} }">
            <h3 class="text-sm font-bold text-gray-900 dark:text-white">🖼️ {{ __('pos.receipt_content_section') }} <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('pos.receipt_content_sub') }}</span></h3>

            {{-- ── Logo ── --}}
            <div>
                <label class="flex items-start gap-2.5 cursor-pointer p-3 rounded-lg border {{ ($ps['show_logo'] ?? true) ? 'border-purple-400 bg-purple-50/40 dark:bg-purple-900/10' : 'border-gray-200 dark:border-gray-700' }} transition"
                       :class="showLogo ? 'border-purple-400 bg-purple-50/40 dark:bg-purple-900/10' : 'border-gray-200 dark:border-gray-700'">
                    <input type="checkbox" name="rp_show_logo" value="1"
                           {{ ($ps['show_logo'] ?? true) ? 'checked' : '' }}
                           x-model="showLogo"
                           class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                    <span class="flex-1 min-w-0">
                        <span class="block text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.show_logo_label') }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.show_logo_hint') }}</span>
                    </span>
                </label>

                {{-- Sub-option: sirf final bill par (logo_finals_only). Visible + enabled
                     only when the Logo master tick is ON. Disabled visually when off. --}}
                <div x-show="showLogo" x-transition class="mt-2 ml-6 pl-3 border-l-2 border-purple-200 dark:border-purple-800">
                    <label class="flex items-start gap-2.5 cursor-pointer p-2.5 rounded-lg border {{ ($ps['logo_finals_only'] ?? false) ? 'border-purple-300 bg-purple-50/30 dark:bg-purple-900/10' : 'border-gray-200 dark:border-gray-700' }} transition">
                        <input type="checkbox" name="rp_logo_finals_only" value="1"
                               {{ ($ps['logo_finals_only'] ?? false) ? 'checked' : '' }}
                               class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="flex-1 min-w-0">
                            <span class="block text-sm font-semibold text-gray-800 dark:text-gray-200">{{ __('pos.logo_on_final_only') }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.logo_finals_only_hint') }}</span>
                        </span>
                    </label>
                </div>
            </div>

            {{-- ── Menu QR ── --}}
            <label class="flex items-start gap-2.5 cursor-pointer p-3 rounded-lg border {{ ($ps['show_menu_qr'] ?? true) ? 'border-purple-400 bg-purple-50/40 dark:bg-purple-900/10' : 'border-gray-200 dark:border-gray-700' }} transition">
                <input type="checkbox" name="rp_show_menu_qr" value="1"
                       {{ ($ps['show_menu_qr'] ?? true) ? 'checked' : '' }}
                       class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                <span class="flex-1 min-w-0">
                    <span class="block text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.show_menu_qr_label') }}</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.show_menu_qr_hint') }}</span>
                </span>
            </label>
        </div>

        {{-- Receipt Theme (Task 712): named bundles over the same pos_style
             bold/logo keys the old bold-toggle + logo-select (rp_style_bold /
             rp_logo_style) wrote — the save path still accepts those legacy
             fields from old cached forms. GLOBAL like paper size — applies to
             both PRA and Local receipts. Cards + live preview aside share the
             wrapper's rcptThemePicker() Alpine scope. --}}
        @include('pos.partials.receipt-theme-cards', ['accent' => 'purple'])

        {{-- Live preview on small screens (the lg aside is hidden there) --}}
        <div class="lg:hidden">
            @include('pos.partials.receipt-theme-preview', ['company' => $company, 'mode' => 'pra'])
        </div>

        <div class="flex items-center justify-between gap-3">
            <a href="{{ route('pos.customize') }}" class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                {{ __('pos.back_to_customize') }}
            </a>
            <button type="submit" class="px-8 py-2.5 bg-purple-600 text-white text-sm font-semibold rounded-lg hover:bg-purple-700 transition shadow-sm">
                {{ __('pos.save_receipt_settings') }}
            </button>
        </div>
    </form>

    {{-- Sticky live preview (desktop) — updates instantly on theme select AND
         on every print toggle above, before save (Task 712). --}}
    <div class="hidden lg:block lg:col-span-2 lg:sticky lg:top-4">
        @include('pos.partials.receipt-theme-preview', ['company' => $company, 'mode' => 'pra'])
    </div>
    </div>

    {{-- Direct Print guide (owner request Jul 2026): browsers ALWAYS show a print
         dialog from JavaScript — the ONLY reliable no-dialog path is the browser's
         own kiosk-printing mode. This card teaches the one-time shortcut setup;
         paired with the sale screen's Auto-Print toggle the receipt then prints
         instantly with zero clicks. Informational only — no server state. --}}
    <div class="mt-6 bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-700 p-6 shadow-sm">
        <h2 class="text-base font-bold text-gray-900 dark:text-white flex items-center gap-2">⚡ {{ __('pos.direct_print_title') }}</h2>
        <p class="text-sm text-gray-600 dark:text-gray-300 mt-2">
            {!! __('pos.direct_print_intro_html', ['instantly' => '<span class="font-semibold">' . e(__('pos.direct_print_instantly')) . '</span>']) !!}
        </p>
        <ol class="list-decimal list-inside text-sm text-gray-700 dark:text-gray-200 mt-3 space-y-2">
            <li>{!! __('pos.direct_print_step1_html', ['bold' => '<span class="font-semibold">' . e(__('pos.direct_print_step1_bold')) . '</span>']) !!}</li>
            <li>{!! __('pos.direct_print_step2_html', ['bold' => '<span class="font-semibold">' . e(__('pos.direct_print_step2_bold')) . '</span>']) !!}
                <code class="block mt-1.5 mb-1 px-3 py-2 rounded-lg bg-gray-100 dark:bg-gray-800 text-[12px] text-gray-800 dark:text-gray-100 select-all overflow-x-auto">"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk-printing</code>
                {!! __('pos.direct_print_step2_tail_html', ['name' => '<span class="font-semibold">"POS Direct Print"</span>', 'edge' => '<code class="text-[11px]">msedge.exe</code>', 'flag' => '<code class="text-[11px]">--kiosk-printing</code>']) !!}
            </li>
            <li>{!! __('pos.direct_print_step3_html', ['bold' => '<span class="font-semibold">' . e(__('pos.direct_print_step3_bold')) . '</span>']) !!}</li>
            <li>{!! __('pos.direct_print_step4_html', ['bold' => '<span class="font-semibold">' . e(__('pos.direct_print_step4_bold')) . '</span>']) !!}</li>
        </ol>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-3">{{ __('pos.direct_print_note') }}</p>
    </div>
</div>
</x-pos-layout>
