<x-hotel-layout>
<div class="max-w-3xl mx-auto space-y-5" data-hotel-credit-notes="1">
<div class="flex flex-wrap items-center justify-between gap-3">
<h1 class="text-xl font-bold">{{ __('hotel_credit.title') }} · {{ $stay->stay_number }}</h1>
<a class="underline text-sm" href="{{ route('pos.hotel.credit-notes', ['id' => $stay->id, 'settings' => 1]) }}">{{ __('hotel_credit.activation') }}</a>
</div>
<p>{{ __('hotel_credit.help') }}</p>
@php
    $issuanceEnabled = \App\Services\HotelCreditNoteActivation::issuance((int) $stay->company_id);
    $refundsEnabled = \App\Services\HotelCreditNoteActivation::refunds((int) $stay->company_id);
@endphp
@if(!$issuanceEnabled)<p role="alert" class="border rounded p-3">{{ __('hotel_credit.verification') }}</p>@endif
@foreach($bills as $bill)
@php
    $existingNote = $notes->firstWhere('original_transaction_id', $bill->id);
    $plan = $plans[$bill->id] ?? null;
@endphp
<div id="bill-{{ $bill->id }}" class="border rounded p-4 space-y-3 scroll-mt-4">
<p class="font-semibold">{{ $bill->invoice_number }} · Rs {{ number_format($bill->total_amount, 2) }}</p>
<p>{{ $bill->pra_invoice_number }} · {{ $bill->pra_status }}</p>
@if($existingNote)
<button type="button" data-credit-open="credit-result-{{ $existingNote->id }}" class="border rounded px-4 py-2">{{ __('hotel_credit.view') }}</button>
@else
<button type="button" data-credit-open="credit-invoice-{{ $bill->id }}" class="border rounded px-4 py-2">{{ __('hotel_credit.entry') }}</button>
<dialog id="credit-invoice-{{ $bill->id }}" data-hotel-credit-dialog data-credit-bill="{{ $bill->id }}" class="tn-credit-dialog rounded-2xl p-5">
<button type="button" data-credit-close class="float-right border rounded px-3 py-1" aria-label="{{ __('hotel_credit.close') }}">×</button>
<h2 class="text-xl font-bold">{{ __('hotel_credit.full_invoice') }} · {{ $bill->invoice_number }}</h2>
<p class="my-3">{{ $bill->pra_invoice_number }}</p>
@foreach($bill->items as $item)
<p>{{ $item->item_name }} · {{ $item->quantity }} · Rs {{ number_format($item->subtotal, 2) }}</p>
@endforeach
<p class="mt-3" data-credit-header-tax="{{ $bill->tax_amount }}">{{ __('hotel_bill.credit_tax') }}: Rs {{ number_format($bill->tax_amount, 2) }}</p>
<p class="font-bold my-3">{{ __('hotel_credit.estimate') }}: Rs {{ number_format($bill->total_amount, 2) }}</p>
<p class="my-3">{{ __('hotel_credit.no_refund') }}</p>
@if($plan && $plan['issuance_enabled'])
<form data-credit-simple="1" method="POST" action="{{ route('pos.hotel.credit-notes.issue', [$stay->id, $bill->id]) }}" class="mt-4">
@csrf
<input type="hidden" name="simple_full" value="1">
<input type="hidden" name="mode" value="full">
<input type="hidden" name="confirmed" value="1">
<input type="hidden" name="fingerprint" value="{{ $plan['fingerprint'] }}">
<input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
<p class="mb-3">{{ __('hotel_credit.full_confirm') }}</p>
<button type="submit" class="border rounded px-4 py-2 bg-teal-700 text-white">{{ __('hotel_credit.confirm_create') }}</button>
</form>
@else
<p role="alert" class="border rounded p-3">{{ $blocked[$bill->id] ?? ($issuanceEnabled ? __('hotel_credit.manual_review') : __('hotel_credit.verification')) }}</p>
@endif
</dialog>
@endif
</div>
@endforeach
@foreach($notes as $note)
@php($credit = $credits->get($note->credit_transaction_id))
@if($credit)
<div id="note-{{ $note->id }}" class="border rounded p-4 space-y-2 scroll-mt-4">
<button type="button" data-credit-open="credit-result-{{ $note->id }}" class="border rounded px-4 py-2">{{ __('hotel_credit.view') }}</button>
<dialog id="credit-result-{{ $note->id }}" data-hotel-credit-dialog class="tn-credit-dialog rounded-2xl p-5">
<button type="button" data-credit-close class="float-right border rounded px-3 py-1" aria-label="{{ __('hotel_credit.close') }}">×</button>
<h2 class="text-lg font-bold">{{ __('hotel_credit.title') }} · {{ $credit->invoice_number }}</h2>
<p class="my-3">Rs {{ number_format($credit->total_amount, 2) }} · {{ $credit->pra_status }} · {{ $credit->pra_invoice_number }}</p>
<a class="border rounded px-4 py-2 inline-block" href="{{ route('pos.hotel.bill-receipt', [$stay->id, $credit->id]) }}" target="_blank" rel="noopener">{{ __('hotel_credit.print') }}</a>
<p class="my-3">{{ __('hotel_credit.no_refund') }}</p>
</dialog><p>{{ $credit->invoice_number }} · Rs {{ number_format($credit->total_amount, 2) }} · {{ $credit->pra_status }} · {{ $credit->pra_invoice_number }}</p><p>{{ $note->reason }}</p><a class="underline" href="{{ route('pos.hotel.bill-receipt', [$stay->id, $credit->id]) }}">{{ $credit->invoice_number }}</a>
@if($refundsEnabled && $credit->pra_status === 'submitted' && $credit->pra_invoice_number)
<form method="POST" action="{{ route('pos.hotel.credit-notes.refund', [$stay->id, $note->id]) }}" class="space-y-2">
@csrf<input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
<label class="block">{{ __('hotel_credit.refund') }} <input type="number" name="amount" min="0.01" step="0.01" max="{{ $credit->total_amount }}" required class="border rounded p-2"></label>
<label class="block">{{ __('hotel_credit.method') }} <select name="method" class="border rounded p-2"><option value="cash">{{ __('hotel_correction.cash') }}</option><option value="card">{{ __('hotel_correction.card') }}</option></select></label>
<label class="block">{{ __('hotel_credit.drawer') }} <select name="terminal_id" class="border rounded p-2"><option value="0">{{ __('pos.counter_not_set') }}</option>@foreach($terminals as $terminal)<option value="{{ $terminal->id }}">{{ $terminal->terminal_name }}</option>@endforeach</select></label>
<label class="block"><input type="checkbox" name="confirmed" value="1" required> {{ __('hotel_credit.refund_confirm') }}</label>
<button class="border rounded px-4 py-2">{{ __('hotel_credit.refund') }}</button>
</form>
@endif
</div>
@endif
@endforeach

<a class="underline" href="{{ route('pos.hotel.stays.show', $stay->id) }}">{{ __('hotel_correction.back') }}</a>
</div>
<style>
.tn-credit-dialog { width: min(38rem, calc(100vw - 2rem)); max-height: calc(100dvh - 2rem); overflow-y: auto; }
.tn-credit-dialog::backdrop { background: rgb(15 23 42 / .55); }
</style>
<script src="{{ asset('js/hotel-credit-notes.js') }}?v={{ filemtime(public_path('js/hotel-credit-notes.js')) }}" defer></script>
</x-hotel-layout>
