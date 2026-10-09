<x-dynamic-component :component="request()->boolean('modal') ? 'pos-layout' : 'hotel-layout'">
<div class="tn-page tn-hotel-page max-w-2xl mx-auto space-y-5" data-hotel-correction="1">
    <h1 class="text-2xl font-bold">{{ __('hotel_correction.title') }} · {{ $stay->stay_number }}</h1>
    <p>{{ __('hotel_correction.help') }}</p>
    <div class="rounded-xl border p-4 space-y-2">
        <p>{{ __('pos.hotel_guest') }}: {{ $stay->guest_name }}</p>
        <p>{{ __('pos.hotel_room') }}: {{ $stay->room?->room_number }}</p>
        <p>{{ __('hotel_correction.charges') }}: Rs {{ number_format($plan['totals']['charges'], 2) }}</p>
        <p>{{ __('hotel_correction.money') }}: Rs {{ number_format($plan['totals']['payments'] - $plan['totals']['refunds'] + $plan['totals']['deposit_held'], 2) }}</p>
        @foreach($plan['bills'] as $bill)
        <p>{{ $bill->invoice_number }} · Rs {{ number_format($bill->total_amount, 2) }}</p>
        @endforeach
    </div>
    @if($plan['blocked'])
    <p role="alert" class="rounded-xl border border-amber-300 p-4">{{ $plan['blocked'] }}</p>
    @else
    <form method="POST" action="{{ route('pos.hotel.stays.correct', $stay->id) }}" class="space-y-4">
        @csrf
        <input type="hidden" name="fingerprint" value="{{ $plan['fingerprint'] }}">
        <label class="block">{{ __('hotel_correction.reason') }}
            <textarea name="reason" required minlength="5" maxlength="255" class="block w-full rounded-lg border mt-1 p-2">{{ old('reason') }}</textarea>
        </label>
        <label class="block">{{ __('hotel_correction.method') }}
            <select name="payment_method" class="block w-full rounded-lg border mt-1 p-2">
                <option value="cash">{{ __('hotel_correction.cash') }}</option>
                <option value="card">{{ __('hotel_correction.card') }}</option>
            </select>
        </label>
        <label class="flex gap-2 items-start"><input type="checkbox" name="confirmed" value="1" required class="mt-1"><span>{{ __('hotel_correction.confirm') }}</span></label>
        <button class="rounded-lg bg-red-700 text-white px-4 py-2">{{ __('hotel_correction.submit') }}</button>
    </form>
    @endif
    <a href="{{ route('pos.hotel.stays.show', $stay->id) }}" class="block underline">{{ __('hotel_correction.back') }}</a>
</div>
</x-dynamic-component>
