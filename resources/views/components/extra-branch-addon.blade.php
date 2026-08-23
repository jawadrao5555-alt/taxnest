@props(['addon', 'bank', 'action', 'accent' => 'purple'])
{{-- ── Paid extra-branch add-on (Rs 10,000/branch/year, owner-approved Aug 2026) ──
     Shared by the PRA POS and FBR POS branch pages so both panels quote and
     submit EXACTLY the same thing. Package ki shamil branches MUFT hain; us se
     ooper har branch paid hai. Qeemat server (BranchAddonService) se aati hai —
     page kabhi apna hisaab nahi lagata, taake approve hone par wahi raqam
     charge ho.
     Accent classes are written out in full (never "bg-{$accent}-600"), otherwise
     Tailwind's scanner never sees them and the buttons render unstyled. --}}
@php
    $btn = $accent === 'blue'
        ? 'bg-blue-600 hover:bg-blue-700'
        : 'bg-purple-600 hover:bg-purple-700';
    $file = $accent === 'blue'
        ? 'file:bg-blue-600 hover:file:bg-blue-500'
        : 'file:bg-purple-600 hover:file:bg-purple-500';

    $ebEligible = $addon['eligibility']['allowed'] ?? false;
    $ebExhausted = $addon['limit'] !== null && $addon['used'] >= $addon['limit'];
    $ebForceOpen = session('payment_proof') || $errors->has('proof') || $errors->has('extra_branch_qty');
@endphp
@if($addon['pending'])
<div class="mb-5 rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4">
    <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">{{ __('pos.eb_pending_title') }}</p>
    <p class="text-xs text-amber-700 dark:text-amber-400 mt-1">{{ __('pos.eb_pending_desc') }}</p>
</div>
@elseif($ebEligible)
<div class="mb-5 rounded-xl border {{ $ebExhausted ? 'border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900' }} shadow-md p-5"
     x-data="{ open: {{ $ebForceOpen ? 'true' : 'false' }}, qty: {{ (int) old('extra_branch_qty', 1) }}, quotes: {{ \Illuminate\Support\Js::from($addon['quotes']) }},
               prorataTpl: {{ \Illuminate\Support\Js::from(__('pos.eb_prorata_note')) }},
               get quote() { return this.quotes[this.qty] ?? null; } }">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                {{ $ebExhausted ? __('pos.eb_exhausted_title') : __('pos.eb_more_title') }}
            </h2>
            <p class="text-xs mt-1 {{ $ebExhausted ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500 dark:text-gray-400' }}">
                {{ __('pos.eb_price_line', ['price' => number_format($addon['per_year'])]) }}
            </p>
        </div>
        <button type="button" @click="open = !open"
                class="{{ $btn }} text-white rounded-lg px-4 py-2 text-sm font-semibold">
            {{ __('pos.eb_buy_cta') }}
        </button>
    </div>

    <div x-show="open" x-cloak class="mt-4 border-t border-gray-200 dark:border-gray-700 pt-4">
        <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <input type="hidden" name="request_type" value="extra_branch">

            <div class="grid sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('pos.eb_qty_label') }}</label>
                    <select name="extra_branch_qty" x-model.number="qty" required
                            class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                        @foreach($addon['quotes'] as $qKey => $q)
                            <option value="{{ $qKey }}" @selected((int) old('extra_branch_qty', 1) === (int) $qKey)>{{ $qKey }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 px-3 py-2 self-end">
                    <p class="text-sm text-emerald-700 dark:text-emerald-300">
                        {{ __('pos.eb_payable_now') }}
                        <span class="font-bold">PKR <span x-text="quote ? Number(quote.price).toLocaleString() : ''"></span></span>
                    </p>
                    <template x-if="quote && quote.prorated">
                        <p class="text-[11px] text-emerald-600 dark:text-emerald-400 mt-0.5"
                           x-text="prorataTpl.replace(':months', quote.months).replace(':full', Number(quote.full_price).toLocaleString())"></p>
                    </template>
                    <template x-if="quote && !quote.prorated">
                        <p class="text-[11px] text-emerald-600 dark:text-emerald-400 mt-0.5">{{ __('pos.eb_full_year_note') }}</p>
                    </template>
                </div>
            </div>
            @error('extra_branch_qty')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.eb_renewal_note', ['price' => number_format($addon['per_year'])]) }}</p>

            @if($bank['bank_name'] || $bank['account_number'] || $bank['iban'])
            <div class="rounded-lg bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 px-3 py-2 text-xs text-gray-600 dark:text-gray-300 space-y-0.5">
                <p class="font-semibold text-gray-700 dark:text-gray-200">{{ __('pos.pp_bank_details') }}</p>
                @if($bank['bank_name'])<p>{{ $bank['bank_name'] }}</p>@endif
                @if($bank['account_title'])<p>{{ $bank['account_title'] }}</p>@endif
                @if($bank['account_number'])<p class="font-mono">{{ $bank['account_number'] }}</p>@endif
                @if($bank['iban'])<p class="font-mono">{{ $bank['iban'] }}</p>@endif
                @if($bank['instructions'])<p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $bank['instructions'] }}</p>@endif
            </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <input type="number" step="0.01" min="0" name="amount" value="{{ old('amount') }}" placeholder="{{ __('pos.pp_amount_paid') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                <select name="payment_method"
                        class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                    <option value="">{{ __('pos.pp_payment_method') }}</option>
                    <option value="bank" @selected(old('payment_method') === 'bank')>{{ __('pos.pp_method_bank') }}</option>
                    <option value="jazzcash" @selected(old('payment_method') === 'jazzcash')>JazzCash</option>
                    <option value="easypaisa" @selected(old('payment_method') === 'easypaisa')>EasyPaisa</option>
                    <option value="other" @selected(old('payment_method') === 'other')>{{ __('pos.pp_method_other') }}</option>
                </select>
            </div>
            <input type="text" name="reference" value="{{ old('reference') }}" maxlength="120" placeholder="{{ __('pos.pp_reference') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                   class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
            <input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" required
                   class="w-full text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold {{ $file }} file:text-white">
            @error('proof')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
            <p class="text-[11px] text-gray-400">{{ __('pos.pp_accepted_formats') }}</p>

            <button type="submit" class="w-full sm:w-auto {{ $btn }} text-white rounded-lg px-5 py-2.5 text-sm font-semibold">
                {{ __('pos.eb_submit_cta') }}
            </button>
        </form>
    </div>
</div>
@elseif($ebExhausted && ($addon['eligibility']['reason_key'] ?? null))
<div class="mb-5 rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4">
    <p class="text-sm text-amber-800 dark:text-amber-300">{{ __($addon['eligibility']['reason_key']) }}</p>
</div>
@endif
