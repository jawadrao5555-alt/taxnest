<x-hotel-layout>
@php
    $config = ['url' => route('pos.hotel.checkout-quote', $stay->id), 'quote' => $summary, 'amount' => old('amount', $summary['balance']), 'method' => old('payment_method', 'cash'), 'failure' => __('pos.hotel_quote_failed')];
@endphp
<div class="max-w-3xl mx-auto">
    <a href="{{ route('pos.hotel.stays.show', $stay->id) }}" class="text-sm text-teal-700">← {{ __('pos.hotel_desk_stay_details') }}</a>
    <h1 class="text-2xl font-bold mt-3 dark:text-white">{{ __('pos.hotel_check_out_btn') }} · {{ $stay->guest_name }}</h1>
    <p class="text-sm text-gray-500 mb-5">{{ __('pos.hotel_room') }} {{ $stay->room?->room_number }} · {{ $stay->check_in_date->format('d M') }} – {{ $stay->check_out_date->format('d M Y') }}</p>
    @if(session('error'))<p role="alert" class="mb-4 rounded-lg bg-red-50 text-red-700 p-3">{{ session('error') }}</p>@endif
    @if($errors->any())<p role="alert" class="mb-4 text-red-700">{{ $errors->first() }}</p>@endif
    <form x-data="hotelCheckoutForm(@js($config))" x-init="refresh()" method="POST" action="{{ route('pos.hotel.checkout.complete', $stay->id) }}" @submit="if (busy || error) { $event.preventDefault(); } else { submitting = true; }" class="rounded-xl border bg-white dark:bg-gray-900 p-5 space-y-5">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) Illuminate\Support\Str::uuid()) }}">
        <div class="grid grid-cols-2 gap-3 text-sm" aria-live="polite">
            <span>{{ __('pos.hotel_room_charges') }}</span><strong class="text-right" x-text="'Rs ' + money(quote.room_gross)"></strong>
            <span>{{ __('pos.hotel_all_discounts') }}</span><strong class="text-right" x-text="'Rs ' + money(quote.discount)"></strong>
            <span>{{ __('pos.hotel_extras') }}</span><strong class="text-right" x-text="'Rs ' + money(quote.extras)"></strong>
            <span>{{ __('pos.hotel_tax') }} <span x-show="quote.tax_inclusive">({{ __('pos.hotel_included') }})</span></span><strong class="text-right" x-text="'Rs ' + money(quote.tax)"></strong>
            <span>{{ __('pos.hotel_open_bill_total') }}</span><strong class="text-right" x-text="'Rs ' + money(quote.total)"></strong>
            <span>{{ __('pos.hotel_available_advance') }}</span><strong class="text-right" x-text="'Rs ' + money(quote.available)"></strong>
            <span class="text-lg font-bold">{{ __('pos.hotel_folio_due') }}</span><strong class="text-right text-lg text-teal-700" x-text="'Rs ' + money(quote.balance)"></strong>
        </div>
        <p x-show="quote.deposit > 0" class="text-sm text-amber-700">{{ __('pos.hotel_folio_deposit') }}: <strong x-text="'Rs ' + money(quote.deposit)"></strong> · {{ __('pos.hotel_deposit_not_revenue') }}</p>
        <p x-show="quote.credit > 0" class="text-sm text-teal-700">{{ __('pos.hotel_folio_advance_credit') }}: <strong x-text="'Rs ' + money(quote.credit)"></strong></p>
        <div class="grid sm:grid-cols-2 gap-4">
            <label class="text-sm">{{ __('pos.hotel_payment_method') }}<select name="payment_method" x-model="method" @change="refresh()" class="block mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-800">@include('pos.hotel._payment-methods')</select></label>
            <label class="text-sm">{{ __('pos.hotel_collect_now') }}<input name="amount" x-model="amount" required type="number" min="0" :max="quote.balance" step="0.01" class="block mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-800"></label>
        </div>
        <p class="text-sm text-slate-600">{{ __('hotel_simplify.method_changes_tax') }}</p>
        <div class="flex justify-between rounded-lg bg-teal-50 px-3 py-3 text-sm font-semibold" aria-live="polite"><span>{{ __('hotel_simplify.remaining_after_collection') }}</span><strong x-text="'Rs ' + money(Math.max(0, Number(quote.balance || 0) - Number(amount || 0)))"></strong></div>
        @if($allowBalance)
        <label class="flex gap-2 text-sm"><input type="checkbox" name="leave_balance" value="1" class="rounded" @checked(old('leave_balance'))>{{ __('pos.hotel_leave_balance') }}</label>
        @endif
        <p x-show="busy" class="text-sm">{{ __('pos.hotel_calculating') }}</p>
        <p x-show="error" x-text="error" class="text-sm text-red-700" role="alert"></p>
        <p class="text-xs text-gray-500">{{ __('pos.hotel_checkout_summary_hint') }}</p>
        <button :disabled="busy || !!error || submitting" class="w-full sm:w-auto rounded-lg bg-teal-700 text-white px-5 py-3 font-semibold disabled:opacity-50">{{ __('pos.hotel_complete_checkout') }}</button>
    </form>
</div>
</x-hotel-layout>
