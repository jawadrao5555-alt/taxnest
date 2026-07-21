<x-pos-layout>
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <a href="{{ route('pos.customize') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition mb-3">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        Back to Customize
    </a>
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Receipt Display Options</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">PRA (fiscal) bills and Local bills each have their <span class="font-semibold text-gray-700 dark:text-gray-200">own</span> receipt settings — choose separately what appears on each type.</p>

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
    @endphp
    <form method="POST" action="{{ route('pos.receipt-settings') }}" class="space-y-6" x-data="{ tab: 'pra' }">
        @csrf

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
            {{-- Tab switcher --}}
            <div class="flex border-b border-gray-200 dark:border-gray-700">
                <button type="button" @click="tab = 'pra'"
                    :class="tab === 'pra' ? 'bg-purple-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'"
                    class="flex-1 px-4 py-3 text-sm font-semibold transition flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    PRA Receipt
                </button>
                <button type="button" @click="tab = 'local'"
                    :class="tab === 'local' ? 'bg-purple-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'"
                    class="flex-1 px-4 py-3 text-sm font-semibold transition flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2a2 2 0 012-2h2a2 2 0 012 2v2m-6 4h6a2 2 0 002-2V7a2 2 0 00-2-2H9a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    Local Receipt
                </button>
            </div>

            {{-- ============ PRA (fiscal) receipt panel ============ --}}
            <div x-show="tab === 'pra'" class="p-6">
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">These settings apply to bills <span class="font-semibold">reported to PRA</span> (fiscal <span class="font-mono">POS-</span> serial receipts).</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="rp_show_address" value="1" {{ $rp['show_address'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">Show Address</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="rp_show_ntn" value="1" {{ $rp['show_ntn'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">Show NTN</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="rp_show_email" value="1" {{ $rp['show_email'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">Show Email</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="rp_show_mobile" value="1" {{ $rp['show_mobile'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">Show Phone / Mobile</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="rp_show_cashier" value="1" {{ $rp['show_cashier'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">Show Cashier Details</span>
                    </label>
                </div>
                <div class="mt-4 p-3 rounded-lg border-2 {{ $rp['show_tax'] ? 'border-amber-400 bg-amber-50/40 dark:bg-amber-900/10' : 'border-gray-200 dark:border-gray-700' }}">
                    <label class="flex items-start gap-2.5 cursor-pointer">
                        <input type="checkbox" name="rp_show_tax" value="1" {{ $rp['show_tax'] ? 'checked' : '' }} class="mt-0.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500 w-4 h-4">
                        <span class="flex-1 min-w-0">
                            <span class="block text-sm font-bold text-gray-900 dark:text-white">🧾 Show Tax on PRA Receipt</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">Print Subtotal + Sales-Tax lines. OFF = customer copy shows grand TOTAL only. Tax is always submitted to PRA in full; details stay visible on QR scan (Sahulat app).</span>
                        </span>
                    </label>
                </div>
                <div class="mt-4">
                    <label class="flex items-center gap-2.5 cursor-pointer mb-2">
                        <input type="checkbox" name="rp_show_footer" value="1" {{ $rp['show_footer'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm font-medium text-gray-800 dark:text-gray-200">Show footer message on PRA receipt</span>
                    </label>
                    <input type="text" name="rp_footer_text" value="{{ $rp['footer_text'] }}" maxlength="150" placeholder="Thank you for your purchase!"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-purple-500 focus:ring-purple-500"
                        autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Leave blank to use the default message.</p>
                </div>
            </div>

            {{-- ============ Local receipt panel ============ --}}
            <div x-show="tab === 'local'" class="p-6" style="display:none;">
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">These settings apply to <span class="font-semibold">Local bills</span> (<span class="font-mono">L-</span>series receipts that are not reported to PRA).</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="lp_show_address" value="1" {{ $lp['show_address'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">Show Address</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="lp_show_ntn" value="1" {{ $lp['show_ntn'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">Show NTN</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="lp_show_email" value="1" {{ $lp['show_email'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">Show Email</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="lp_show_mobile" value="1" {{ $lp['show_mobile'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">Show Phone / Mobile</span>
                    </label>
                    <label class="flex items-center gap-2.5 cursor-pointer p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-purple-300 dark:hover:border-purple-700 transition">
                        <input type="checkbox" name="lp_show_cashier" value="1" {{ $lp['show_cashier'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm text-gray-800 dark:text-gray-200">Show Cashier Details</span>
                    </label>
                </div>
                <div class="mt-4 p-3 rounded-lg border-2 {{ $lp['show_tax'] ? 'border-amber-400 bg-amber-50/40 dark:bg-amber-900/10' : 'border-gray-200 dark:border-gray-700' }}">
                    <label class="flex items-start gap-2.5 cursor-pointer">
                        <input type="checkbox" name="lp_show_tax" value="1" {{ $lp['show_tax'] ? 'checked' : '' }} class="mt-0.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500 w-4 h-4">
                        <span class="flex-1 min-w-0">
                            <span class="block text-sm font-bold text-gray-900 dark:text-white">🧾 Show Tax on Local Receipt</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">Print Subtotal + Sales-Tax lines on Local (L-series) bills. OFF = customer copy shows grand TOTAL only.</span>
                        </span>
                    </label>
                </div>
                <div class="mt-4">
                    <label class="flex items-center gap-2.5 cursor-pointer mb-2">
                        <input type="checkbox" name="lp_show_footer" value="1" {{ $lp['show_footer'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                        <span class="text-sm font-medium text-gray-800 dark:text-gray-200">Show footer message on Local receipt</span>
                    </label>
                    <input type="text" name="lp_footer_text" value="{{ $lp['footer_text'] }}" maxlength="150" placeholder="Thank you for your purchase!"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-purple-500 focus:ring-purple-500"
                        autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Leave blank to use the default message.</p>
                </div>
            </div>
        </div>

        {{-- Paper size is GLOBAL — it's a printer property, not a bill-type setting. --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6">
            <label class="block text-sm font-medium text-gray-800 dark:text-gray-200 mb-2">🖨️ Receipt Paper Size <span class="text-xs font-normal text-gray-500 dark:text-gray-400">(applies to both receipt types)</span></label>
            <select name="rp_printer_size" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-purple-500 focus:ring-purple-500">
                <option value="80mm" {{ ($company->receipt_printer_size ?? '80mm') === '80mm' ? 'selected' : '' }}>80mm (Standard)</option>
                <option value="58mm" {{ ($company->receipt_printer_size ?? '80mm') === '58mm' ? 'selected' : '' }}>58mm (Compact)</option>
            </select>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Match your thermal printer's paper roll. The print automatically fits your printer's actual printable width — no fine-tuning needed.</p>
        </div>

        {{-- Print Style (customer feedback Jul 2026 — Pizza Master): GLOBAL like
             paper size — it's the printer/brand look, not a bill-type setting.
             Applies to both PRA and Local receipts. --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6 space-y-5">
            <h3 class="text-sm font-bold text-gray-900 dark:text-white">🖋️ Receipt Print Style <span class="text-xs font-normal text-gray-500 dark:text-gray-400">(applies to both receipt types)</span></h3>
            <label class="flex items-start gap-2.5 cursor-pointer p-3 rounded-lg border {{ $ps['bold'] ? 'border-purple-400 bg-purple-50/40 dark:bg-purple-900/10' : 'border-gray-200 dark:border-gray-700' }} transition">
                <input type="checkbox" name="rp_style_bold" value="1" {{ $ps['bold'] ? 'checked' : '' }} class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                <span class="flex-1 min-w-0">
                    <span class="block text-sm font-bold text-gray-900 dark:text-white">Bold Receipt Print</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">Print the whole bill in a bold, dark font (like the kitchen ticket). Recommended if your thermal printer prints too light or thin.</span>
                </span>
            </label>
            <div>
                <label class="block text-sm font-medium text-gray-800 dark:text-gray-200 mb-2">Logo Style on Receipt</label>
                <select name="rp_logo_style" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-purple-500 focus:ring-purple-500">
                    <option value="side" {{ $ps['logo'] === 'side' ? 'selected' : '' }}>Compact — small logo beside the business name (default)</option>
                    <option value="center" {{ $ps['logo'] === 'center' ? 'selected' : '' }}>Large — big centered logo at the top of the bill</option>
                </select>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Upload your logo on the Business Profile page. "Large" prints it big and centered like classic printed bills.</p>
            </div>
        </div>

        <div class="flex items-center justify-between gap-3">
            <a href="{{ route('pos.customize') }}" class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Back to Customize
            </a>
            <button type="submit" class="px-8 py-2.5 bg-purple-600 text-white text-sm font-semibold rounded-lg hover:bg-purple-700 transition shadow-sm">
                Save Receipt Settings
            </button>
        </div>
    </form>

    {{-- Direct Print guide (owner request Jul 2026): browsers ALWAYS show a print
         dialog from JavaScript — the ONLY reliable no-dialog path is the browser's
         own kiosk-printing mode. This card teaches the one-time shortcut setup;
         paired with the sale screen's Auto-Print toggle the receipt then prints
         instantly with zero clicks. Informational only — no server state. --}}
    <div class="mt-6 bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-700 p-6 shadow-sm">
        <h2 class="text-base font-bold text-gray-900 dark:text-white flex items-center gap-2">⚡ Direct Print — skip the print dialog</h2>
        <p class="text-sm text-gray-600 dark:text-gray-300 mt-2">
            By default every browser opens a print dialog before printing. To make receipts print <span class="font-semibold">instantly on your thermal printer with no popup</span>, set up your billing computer once:
        </p>
        <ol class="list-decimal list-inside text-sm text-gray-700 dark:text-gray-200 mt-3 space-y-2">
            <li><span class="font-semibold">Set the thermal printer as the Default Printer</span> in Windows — Settings → Bluetooth &amp; devices → Printers → your thermal printer → "Set as default".</li>
            <li><span class="font-semibold">Make a Direct-Print shortcut for Chrome:</span> right-click the Desktop → New → Shortcut, and paste:
                <code class="block mt-1.5 mb-1 px-3 py-2 rounded-lg bg-gray-100 dark:bg-gray-800 text-[12px] text-gray-800 dark:text-gray-100 select-all overflow-x-auto">"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk-printing</code>
                Name it <span class="font-semibold">"POS Direct Print"</span>. (Microsoft Edge: replace the path with <code class="text-[11px]">msedge.exe</code> — same <code class="text-[11px]">--kiosk-printing</code> flag.)
            </li>
            <li><span class="font-semibold">Always open the POS from that shortcut.</span> Close all other Chrome windows first — the flag only works when Chrome starts fresh.</li>
            <li>On the sale screen, keep <span class="font-semibold">Auto-Print Receipt = ON</span>. Now every finished bill goes straight to the printer — no dialog, no clicks.</li>
        </ol>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-3">Note: this is a one-time setup per billing computer. Without it, the browser's print dialog cannot be skipped — that's a browser security rule, not a TaxNest setting.</p>
    </div>
</div>
</x-pos-layout>
