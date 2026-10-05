<x-hotel-layout>
@php
    $formConfig = [
        'url' => route('pos.hotel.quote'), 'room' => (string) old('room_id', $selectedRoomId),
        'arrival' => old('check_in_date', now()->toDateString()), 'departure' => old('check_out_date', now()->addDay()->toDateString()),
        'rate' => old('rate_amount', (float) ($rooms->firstWhere('id', $selectedRoomId)?->rate_amount ?? 0) > 0 ? $rooms->firstWhere('id', $selectedRoomId)->rate_amount : ''),
        'discountType' => old('discount_type', 'amount'), 'discountValue' => old('discount_value', 0),
        'advance' => old('advance_amount', 0), 'method' => old('payment_method', 'cash'), 'walkIn' => (bool) $walkIn,
        'rooms' => $rooms->mapWithKeys(fn ($room) => [$room->id => ['rate' => $room->rate_amount]]),
        'customers' => collect($customers)->mapWithKeys(fn ($c) => [$c->id => ['name' => $c->name, 'phone' => $c->phone]]),
        'recentGuests' => collect($recentGuests)->mapWithKeys(fn ($g) => [$g->id => ['name' => $g->guest_name, 'phone' => $g->guest_phone, 'cnic' => $g->guest_cnic]]),
        'failure' => __('pos.hotel_quote_failed'), 'unavailable' => __('pos.hotel_room_unavailable'), 'dirtyMessage' => __('pos.hotel_room_needs_clean'),
    ];
@endphp
<div class="tn-page tn-hotel-page max-w-4xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">{{ !empty($walkIn) ? __('pos.hotel_action_walkin') : __('pos.hotel_action_booking') }}</h1>
    <p class="text-sm text-gray-500 mb-5">{{ __('pos.hotel_desk_enter_guest') }}</p>
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-700 text-sm">{{ session('error') }}</div>
    @endif
    @if($errors->any())
    <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-700 text-sm" role="alert">
        <ul class="list-disc ms-5">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    </div>
    @endif
    <form x-data="hotelBookingForm(@js($formConfig))" @submit="if (busy || error || !quote) { $event.preventDefault(); } else { submitting = true; }" method="POST" action="{{ route('pos.hotel.stays.store') }}" class="tn-panel bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
        <div class="sm:col-span-2">
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_room') }}</label>
            <select x-model="room" @change="selectRoom()" name="room_id" required class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
                <option value="">{{ __('pos.hotel_select_room') }}</option>
                @foreach($rooms as $room)
                <option value="{{ $room->id }}" @selected((int) old('room_id', $selectedRoomId) === (int) $room->id)>{{ $room->room_number }} · {{ $room->room_type }} · {{ $room->capacity }} · {{ (float) $room->rate_amount > 0 ? 'Rs '.number_format($room->rate_amount).'/'.\App\Services\PosUnitCatalog::label($room->rate_unit) : __('hotel_rooms_manage.hotel_rate_at_checkin') }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_check_in') }}</label>
            <input type="date" x-model="arrival" name="check_in_date" value="{{ old('check_in_date', now()->toDateString()) }}" required class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_check_out') }}</label>
            <input type="date" x-model="departure" name="check_out_date" value="{{ old('check_out_date', now()->addDay()->toDateString()) }}" required class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_guest') }}</label>
            <input x-ref="guest" name="guest_name" value="{{ old('guest_name') }}" required class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_phone') }}</label>
            <input x-ref="phone" name="guest_phone" value="{{ old('guest_phone') }}" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_agreed_rate') }}</label>
            <input x-model="rate" name="rate_amount" type="number" min="0" max="10000000" step="0.01" required class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
            <p class="text-xs text-gray-500 mt-1">{{ __('pos.hotel_stay_rate_hint') }}</p>
        </div>
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_room_discount') }}</label>
            <div class="flex gap-2">
                <select name="discount_type" x-model="discountType" class="rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm"><option value="amount">Rs</option><option value="percentage">%</option></select>
                <input name="discount_value" x-model="discountValue" type="number" min="0" step="0.01" required :max="discountType === 'percentage' ? 100 : 10000000" class="min-w-0 w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
            </div>
            <p class="text-xs text-gray-500 mt-1">{{ __('pos.hotel_discount_scope') }}</p>
        </div>
        <details class="sm:col-span-2 rounded-lg border border-gray-200 dark:border-gray-700 p-3" @if($errors->any()) open @endif>
            <summary class="cursor-pointer text-sm font-semibold text-gray-700 dark:text-gray-200">{{ __('pos.hotel_desk_more_guest') }}</summary>
            <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-4">
        <label class="sm:col-span-2 text-xs font-medium">{{ __('pos.hotel_returning_guest') }}
            <select @change="selectRecentGuest($event)" class="block w-full mt-1 rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
                <option value="">{{ __('pos.optional') }}</option>
                @foreach($recentGuests as $guest)<option value="{{ $guest->id }}">{{ $guest->guest_name }} · {{ $guest->guest_phone }}</option>@endforeach
            </select>
        </label>
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_cnic_optional') }}</label>
            <input x-ref="cnic" name="guest_cnic" value="{{ old('guest_cnic') }}" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
            <p class="text-[10px] text-gray-400 mt-1">{{ __('pos.hotel_cnic_staff_only') }}</p>
        </div>
        <details data-hotel-account-billing class="sm:col-span-2 rounded-lg border p-3" @if(old('payer_customer_id') || old('guest_customer_id')) open @endif>
            <summary class="cursor-pointer text-sm font-semibold">{{ __('hotel_guests.account_billing') }}</summary>
            <p class="mt-2 text-xs text-gray-500">{{ __('hotel_guests.account_hint') }}</p>
            <div class="mt-3 grid sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_existing_customer') }}</label>
            <select @change="selectGuest($event)" name="guest_customer_id" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
                <option value="">{{ __('pos.optional') }}</option>
                @foreach($customers as $c)
                <option value="{{ $c->id }}" @selected(old('guest_customer_id') == $c->id)>{{ $c->name }} {{ $c->phone ? '(' . $c->phone . ')' : '' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_corporate_payer') }}</label>
            <select name="payer_customer_id" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
                <option value="">{{ __('pos.optional') }}</option>
                @foreach($customers as $c)
                <option value="{{ $c->id }}" @selected(old('payer_customer_id') == $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
            </div>
        </details>
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_adults') }}</label>
            <input type="number" name="adult_count" value="{{ old('adult_count', 1) }}" min="1" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_children') }}</label>
            <input type="number" name="child_count" value="{{ old('child_count', 0) }}" min="0" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
        </div>
            </div>
            <label class="block mt-3 text-xs font-medium">{{ __('pos.hotel_guest_notes') }}<textarea name="notes" maxlength="500" rows="2" class="block w-full mt-1 rounded-lg border-gray-300 dark:bg-gray-800">{{ old('notes') }}</textarea></label>
        </details>
        <details class="sm:col-span-2 rounded-lg border p-3" @if(old('advance_amount', 0) > 0) open @endif>
            <summary class="cursor-pointer text-sm font-semibold">{{ __('pos.hotel_payment_now') }}</summary>
            <div class="grid sm:grid-cols-2 gap-3 mt-3">
                <label class="text-xs">{{ __('pos.hotel_amount') }}<input x-model="advance" type="number" name="advance_amount" min="0" max="10000000" step="0.01" class="block w-full mt-1 rounded-lg border-gray-300 dark:bg-gray-800"></label>
                <label class="text-xs">{{ __('pos.hotel_payment_method') }}<select x-model="method" name="payment_method" class="block w-full mt-1 rounded-lg border-gray-300 dark:bg-gray-800">@include('pos.hotel._payment-methods')</select></label>
            </div>
        </details>
        <section class="sm:col-span-2 rounded-xl bg-teal-50 dark:bg-teal-950/30 p-4" aria-live="polite" data-hotel-booking-quote="1">
            <p x-show="busy" class="text-sm">{{ __('pos.hotel_calculating') }}</p>
            <p x-show="error" x-text="error" class="text-sm text-red-700" role="alert"></p>
            <div x-show="quote && !busy && !error" class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
                <p>{{ __('pos.hotel_nights') }} <strong class="block" x-text="quote?.nights"></strong></p>
                <p>{{ __('pos.hotel_room_charges') }} <strong class="block" x-text="'Rs ' + money(quote?.gross)"></strong></p>
                <p>{{ __('pos.hotel_room_discount') }} <strong class="block" x-text="'Rs ' + money(quote?.discount)"></strong></p>
                <p>{{ __('pos.hotel_tax') }} <span x-show="quote?.tax_inclusive">({{ __('pos.hotel_included') }})</span><strong class="block" x-text="'Rs ' + money(quote?.tax)"></strong></p>
                <p>{{ __('pos.hotel_total') }} <strong class="block" x-text="'Rs ' + money(quote?.total)"></strong></p>
                <p>{{ __('pos.hotel_folio_due') }} <strong class="block" x-text="'Rs ' + money(Math.max(0, (quote?.total || 0) - Number(advance || 0)))"></strong></p>
            </div>
            <p x-show="quote && Number(advance) > quote.total" class="text-xs mt-2">{{ __('pos.hotel_advance_credit_hint') }}</p>
        </section>
        <div class="sm:col-span-2 flex flex-wrap items-center gap-3">
            <input type="hidden" name="walk_in" value="{{ old('walk_in', !empty($walkIn) ? 1 : 0) ? 1 : 0 }}">
            <button :disabled="busy || !!error || !quote || submitting" class="disabled:opacity-50 px-5 py-2 bg-teal-700 hover:bg-teal-800 text-white text-sm rounded-lg font-semibold">{{ !empty($walkIn) ? __('pos.hotel_check_in_btn') : __('pos.hotel_action_booking') }}</button>
            <a href="{{ route('pos.hotel.dashboard') }}" class="text-sm font-semibold text-gray-500 hover:text-teal-700">{{ __('pos.hotel_front_desk') }}</a>
        </div>
    </form>
</div>
</x-hotel-layout>
