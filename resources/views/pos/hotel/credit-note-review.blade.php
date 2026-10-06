<x-hotel-layout>
<div class="max-w-2xl mx-auto space-y-4" data-hotel-credit-review="1">
<h1 class="text-xl font-bold">{{ __('hotel_credit.review') }} · {{ $plan['original_usin'] }}</h1>
<p>{{ __('hotel_credit.no_refund') }}</p>
@foreach($plan['lines'] as $line)
<p>{{ $line['name'] }} · {{ $line['quantity'] }} · Rs {{ number_format($line['original_subtotal_share'], 2) }} · {{ __('hotel_bill.line_tax') }} Rs {{ number_format($line['original_tax_share'], 2) }}</p>
@endforeach
<p data-credit-header-tax="{{ $plan['estimated_tax'] }}">{{ __('hotel_bill.credit_tax') }}: Rs {{ number_format($plan['estimated_tax'], 2) }}</p>
<p class="font-bold">{{ __('hotel_credit.estimate') }}: Rs {{ number_format($plan['estimated_total'], 2) }}</p>
@if($plan['issuance_enabled'])
<form method="POST" action="{{ route('pos.hotel.credit-notes.issue', [$stay->id, $plan['bill_id']]) }}" class="space-y-3">
@csrf<input type="hidden" name="fingerprint" value="{{ $plan['fingerprint'] }}"><input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
<input type="hidden" name="mode" value="{{ $selection === null ? 'full' : 'partial' }}">
@foreach($selection ?? [] as $item => $qty)<input type="hidden" name="quantities[{{ $item }}]" value="{{ $qty }}">@endforeach
<label class="block">{{ __('hotel_correction.reason') }}<textarea class="border rounded w-full p-2" name="reason" minlength="5" maxlength="255" required>{{ old('reason') }}</textarea></label>
<label class="block"><input type="checkbox" name="confirmed" value="1" required> {{ __('hotel_credit.confirm') }}</label>
<button class="border rounded px-4 py-2">{{ __('hotel_credit.issue') }}</button>
</form>
@else
<p role="alert" class="border rounded p-3">{{ __('hotel_credit.verification') }}</p>
@endif
<a class="underline" href="{{ route('pos.hotel.credit-notes', $stay->id) }}">{{ __('hotel_correction.back') }}</a>
</div>
</x-hotel-layout>

