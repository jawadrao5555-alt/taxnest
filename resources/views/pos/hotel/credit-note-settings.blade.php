<x-hotel-layout>
<div class="max-w-3xl mx-auto space-y-4">
<h1 class="text-xl font-bold">{{ __('hotel_credit.activation') }}</h1>
@php
    $issuanceEnabled = \App\Services\HotelCreditNoteActivation::issuance((int) $stay->company_id);
    $refundsEnabled = \App\Services\HotelCreditNoteActivation::refunds((int) $stay->company_id);
@endphp
@if(config('hotel_credit_notes.enabled', false))
<form data-credit-activation="1" method="POST" action="{{ route('pos.hotel.credit-notes.activation', $stay->id) }}" class="border rounded p-4 space-y-3">
@csrf
<h2 class="font-semibold">{{ __('hotel_credit.activation') }}</h2>
<p>{{ __('hotel_credit.activation_help') }}</p>
<label class="block">{{ __('hotel_credit.issuance_setting') }} <select name="issuance" class="border rounded p-2"><option value="0" @selected(!$issuanceEnabled)>OFF</option><option value="1" @selected($issuanceEnabled)>ON</option></select></label>
<label class="block">{{ __('hotel_credit.refunds_setting') }} <select name="refunds" class="border rounded p-2"><option value="0" @selected(!$refundsEnabled)>OFF</option><option value="1" @selected($refundsEnabled)>ON</option></select></label>
<label class="block"><input type="checkbox" name="confirmed" value="1" required> {{ __('hotel_credit.activation_confirm') }}</label>
<button class="border rounded px-4 py-2">{{ __('hotel_credit.activation_save') }}</button>
</form>
@endif

<a class="underline" href="{{ route('pos.hotel.credit-notes', $stay->id) }}">{{ __('hotel_correction.back') }}</a>
</div>
</x-hotel-layout>
