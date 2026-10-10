<x-pos-layout>
<script src="{{ asset('js/hotel-desk.js') }}"></script>
<div class="max-w-3xl mx-auto p-4" data-hotel-draft-editor="1">
<h1 class="text-xl font-bold">{{ __('hotel_billing.edit_draft') }} · {{ $stay->stay_number }}</h1>
@if(session('error'))<p role="alert" class="text-red-700">{{ session('error') }}</p>@endif
@if(session('success'))<p role="status" class="text-emerald-700">{{ session('success') }}</p>@endif
@if($errors->any())<p role="alert" class="text-red-700">{{ $errors->first() }}</p>@endif
    @php
        $changeConfig = ['url' => route('pos.hotel.change-quote', $stay->id), 'rate' => (float) $stay->rate_amount, 'date' => $stay->check_out_date->toDateString(), 'room' => '', 'failure' => __('pos.hotel_quote_failed')];
    @endphp
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">
        <form x-data="hotelChangeForm(@js($changeConfig + ['kind' => 'extend']))" @submit="if (!quote || busy || error) $event.preventDefault()" method="POST" action="{{ route('pos.hotel.stays.extend', $stay->id) }}" class="bg-white dark:bg-gray-900 rounded-xl border p-4 space-y-2">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
            <h3 class="text-sm font-semibold">{{ __('pos.hotel_extend') }}</h3>
            <input x-model="date" type="date" name="check_out_date" value="{{ $stay->check_out_date->toDateString() }}" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
            <label class="block text-xs">{{ __('pos.hotel_agreed_rate') }}<input x-model="rate" name="rate_amount" type="number" min="0" max="10000000" step="0.01" class="block mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-800"></label>
            <button type="button" @click="refresh()" class="text-sm text-teal-700 underline">{{ __('pos.hotel_preview_change') }}</button>
            <p x-show="error" x-text="error" class="text-xs text-red-700" role="alert"></p>
            <p x-show="quote" class="text-sm">{{ __('pos.hotel_change_amount') }}: <strong x-text="'Rs ' + money(quote?.change)"></strong> · {{ __('pos.hotel_open_bill_total') }}: <strong x-text="'Rs ' + money(quote?.total)"></strong></p>
            <p class="text-xs text-gray-500">{{ __('pos.hotel_change_hint') }}</p>
            <button :disabled="!quote || busy || !!error" class="disabled:opacity-50 px-3 py-2 bg-teal-700 text-white text-xs rounded-lg font-semibold">{{ __('pos.hotel_extend_btn') }}</button>
        </form>
        <form x-data="hotelChangeForm(@js(array_merge($changeConfig, ['kind' => 'move', 'room' => (string) $stay->room_id])))" @submit="if (!quote || busy || error) $event.preventDefault()" method="POST" action="{{ route('pos.hotel.stays.move', $stay->id) }}" class="bg-white dark:bg-gray-900 rounded-xl border p-4 space-y-2">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
            <h3 class="text-sm font-semibold">{{ __('pos.hotel_room') }} / {{ __('pos.hotel_agreed_rate') }}</h3>
            <select x-model="room" name="room_id" required class="w-full rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                <option value="" selected disabled>{{ __('pos.hotel_select_room') }}</option>
                @foreach($rooms as $room)
                <option value="{{ $room->id }}" @selected($room->id === $stay->room_id)>{{ $room->room_number }} · {{ $room->room_type }}</option>
                @endforeach
            </select>
            <label class="block text-xs">{{ __('pos.hotel_agreed_rate') }}<input x-model="rate" name="rate_amount" type="number" min="0" max="10000000" step="0.01" class="block mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-800"></label>
            <button type="button" @click="refresh()" class="text-sm text-teal-700 underline">{{ __('pos.hotel_preview_change') }}</button>
            <p x-show="error" x-text="error" class="text-xs text-red-700" role="alert"></p>
            <p x-show="quote" class="text-sm">{{ __('pos.hotel_change_amount') }}: <strong x-text="'Rs ' + money(quote?.change)"></strong> · {{ __('pos.hotel_open_bill_total') }}: <strong x-text="'Rs ' + money(quote?.total)"></strong></p>
            <p class="text-xs text-gray-500">{{ __('pos.hotel_change_hint') }}</p>
            <button :disabled="!quote || busy || !!error" class="disabled:opacity-50 px-3 py-2 bg-teal-700 text-white text-xs rounded-lg font-semibold">{{ __('pos.hotel_move_btn') }}</button>
        </form>
    </div>
    @if($stay->status === 'reserved')
    <div class="flex gap-3 mt-4">
        <form method="POST" action="{{ route('pos.hotel.stays.cancel', $stay->id) }}">@csrf<button class="text-xs font-semibold text-gray-600 underline">{{ __('pos.hotel_cancel_btn') }}</button></form>
        <form method="POST" action="{{ route('pos.hotel.stays.no-show', $stay->id) }}">@csrf<button class="text-xs font-semibold text-gray-600 underline">{{ __('pos.hotel_no_show_btn') }}</button></form>
    </div>
    @endif
    <form method="POST" action="{{ route('pos.hotel.stays.discount', $stay->id) }}" class="mt-4 flex flex-wrap gap-2 items-end">
        @csrf
        <label class="text-xs">{{ __('pos.hotel_room_discount') }}<input name="discount_value" value="{{ $stay->discount_value ?? 0 }}" type="number" min="0" max="10000000" step="0.01" required class="block mt-1 rounded-lg border-gray-300 dark:bg-gray-800"></label>
        <select name="discount_type" class="rounded-lg border-gray-300 dark:bg-gray-800 text-sm"><option value="amount" @selected($stay->discount_type !== 'percentage')>Rs</option><option value="percentage" @selected($stay->discount_type === 'percentage')>%</option></select>
        <button class="rounded-lg border px-3 py-2 text-sm">{{ __('pos.save_btn') }}</button>
        <p class="w-full text-xs text-gray-500">{{ __('pos.hotel_pricing_invoiced') }}</p>
    </form>

</div>
</x-pos-layout>

