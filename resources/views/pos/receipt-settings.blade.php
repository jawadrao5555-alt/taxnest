<x-pos-layout>
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <a href="{{ route('pos.customize') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition mb-3">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        Back to Customize
    </a>
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Receipt Display Options</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Choose which business details appear on printed receipts, and customize the thank-you message at the bottom.</p>

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

    @php $rp = $company->displayPrefs('pos'); @endphp
    <form method="POST" action="{{ route('pos.receipt-settings') }}" class="space-y-6">
        @csrf

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-6">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1 flex items-center gap-2">
                <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2a2 2 0 012-2h2a2 2 0 012 2v2m-6 4h6a2 2 0 002-2V7a2 2 0 00-2-2H9a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                Business Details on Receipt
            </h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">These details print in the receipt header (values come from your Business Profile).</p>
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
            <div class="mt-4 p-3 rounded-lg border-2 {{ ($company->pos_receipt_show_tax ?? true) ? 'border-amber-400 bg-amber-50/40 dark:bg-amber-900/10' : 'border-gray-200 dark:border-gray-700' }}">
                <label class="flex items-start gap-2.5 cursor-pointer">
                    <input type="checkbox" name="rp_show_tax" value="1" {{ ($company->pos_receipt_show_tax ?? true) ? 'checked' : '' }} class="mt-0.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500 w-4 h-4">
                    <span class="flex-1 min-w-0">
                        <span class="block text-sm font-bold text-gray-900 dark:text-white">🧾 Show Tax on Receipt</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">Print Subtotal + Sales-Tax lines. OFF = customer copy shows grand TOTAL only. Tax is always submitted to FBR/PRA in full; details stay visible on QR scan (Sahulat app).</span>
                    </span>
                </label>
            </div>

            {{-- Paper size lives here too (owner request Jul 2026) — same companies.receipt_printer_size
                 column the PRA Settings page writes; whichever page saves last wins. Only the paper
                 TYPE matters (fonts/layout): print width auto-fits the printer's real printable area,
                 so no 55/72mm options are needed. --}}
            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-800 dark:text-gray-200 mb-2">🖨️ Receipt Paper Size</label>
                <select name="rp_printer_size" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-purple-500 focus:ring-purple-500">
                    <option value="80mm" {{ ($company->receipt_printer_size ?? '80mm') === '80mm' ? 'selected' : '' }}>80mm (Standard)</option>
                    <option value="58mm" {{ ($company->receipt_printer_size ?? '80mm') === '58mm' ? 'selected' : '' }}>58mm (Compact)</option>
                </select>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Match your thermal printer's paper roll. The print automatically fits your printer's actual printable width — no fine-tuning needed.</p>
            </div>

            <div class="mt-4">
                <label class="flex items-center gap-2.5 cursor-pointer mb-2">
                    <input type="checkbox" name="rp_show_footer" value="1" {{ $rp['show_footer'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                    <span class="text-sm font-medium text-gray-800 dark:text-gray-200">Show footer message on receipt</span>
                </label>
                <input type="text" name="rp_footer_text" value="{{ $rp['footer_text'] }}" maxlength="150" placeholder="Thank you for your purchase!"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 text-sm focus:border-purple-500 focus:ring-purple-500"
                    autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Leave blank to use the default message.</p>
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
</div>
</x-pos-layout>
