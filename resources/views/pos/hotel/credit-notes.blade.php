<x-hotel-layout>
<div class="max-w-3xl mx-auto space-y-5" data-hotel-credit-notes="1">
<h1 class="text-xl font-bold">{{ __('hotel_credit.title') }} · {{ $stay->stay_number }}</h1>
<p>{{ __('hotel_credit.help') }}</p>
@if(!config('hotel_credit_notes.enabled'))<p role="alert" class="border rounded p-3">{{ __('hotel_credit.verification') }}</p>@endif
@foreach($bills as $bill)
<form id="bill-{{ $bill->id }}" method="POST" action="{{ route('pos.hotel.credit-notes.review', [$stay->id, $bill->id]) }}" class="border rounded p-4 space-y-3 scroll-mt-4">
@csrf
<p class="font-semibold">{{ $bill->invoice_number }} · Rs {{ number_format($bill->total_amount, 2) }}</p>
<p>{{ $bill->pra_invoice_number }} · {{ $bill->pra_status }}</p>
<label class="block">{{ __('hotel_credit.mode') }} <select name="mode" class="border rounded p-2"><option value="partial">{{ __('hotel_credit.partial') }}</option><option value="full">{{ __('hotel_credit.full') }}</option></select></label>
@foreach($bill->items as $item)
<label class="block">{{ $item->item_name }} · {{ __('hotel_credit.remaining') }}: {{ max(0, (float) $item->quantity - (float) $item->returned_quantity) }}
<input class="border rounded p-2 w-24" aria-label="{{ $item->item_name }}" type="number" name="quantities[{{ $item->id }}]" min="0" step="0.001" max="{{ max(0, (float) $item->quantity - (float) $item->returned_quantity) }}" value="0"></label>
@endforeach
<button class="border rounded px-4 py-2">{{ __('hotel_credit.review') }}</button>
</form>
@endforeach
@foreach($notes as $note)
@php($credit = $credits->get($note->credit_transaction_id))
@if($credit)
<div class="border rounded p-4 space-y-2"><p>{{ $credit->invoice_number }} · Rs {{ number_format($credit->total_amount, 2) }} · {{ $credit->pra_status }} · {{ $credit->pra_invoice_number }}</p><p>{{ $note->reason }}</p><a class="underline" href="{{ route('pos.hotel.bill-receipt', [$stay->id, $credit->id]) }}">{{ $credit->invoice_number }}</a>
@if(config('hotel_credit_notes.enabled') && $credit->pra_status === 'submitted' && $credit->pra_invoice_number)
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
</x-hotel-layout>
