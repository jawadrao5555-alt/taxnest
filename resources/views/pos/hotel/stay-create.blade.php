<x-pos-layout>
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <a href="{{ route('pos.hotel.stays.index') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 hover:text-teal-700 mb-3">{{ __('pos.hotel_back_stays') }}</a>
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">{{ __('pos.hotel_new_stay') }}</h1>
    <p class="text-sm text-gray-500 mb-5">{{ __('pos.hotel_charging_rule_note') }}</p>
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-700 text-sm">{{ session('error') }}</div>
    @endif
    <form method="POST" action="{{ route('pos.hotel.stays.store') }}" class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
        <div class="sm:col-span-2">
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_room') }}</label>
            <select name="room_id" required class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
                <option value="">{{ __('pos.hotel_select_room') }}</option>
                @foreach($rooms as $room)
                <option value="{{ $room->id }}">{{ $room->room_number }} · {{ $room->room_type }} · {{ $room->capacity }} · Rs {{ number_format($room->rate_amount) }}/{{ \App\Services\PosUnitCatalog::label($room->rate_unit) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_check_in') }}</label>
            <input type="date" name="check_in_date" value="{{ old('check_in_date', now()->toDateString()) }}" required class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_check_out') }}</label>
            <input type="date" name="check_out_date" value="{{ old('check_out_date', now()->addDay()->toDateString()) }}" required class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_guest') }}</label>
            <input name="guest_name" value="{{ old('guest_name') }}" required class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_phone') }}</label>
            <input name="guest_phone" value="{{ old('guest_phone') }}" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_cnic_optional') }}</label>
            <input name="guest_cnic" value="{{ old('guest_cnic') }}" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
            <p class="text-[10px] text-gray-400 mt-1">{{ __('pos.hotel_cnic_staff_only') }}</p>
        </div>
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_existing_customer') }}</label>
            <select name="guest_customer_id" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
                <option value="">{{ __('pos.optional') }}</option>
                @foreach($customers as $c)
                <option value="{{ $c->id }}">{{ $c->name }} {{ $c->phone ? '(' . $c->phone . ')' : '' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_corporate_payer') }}</label>
            <select name="payer_customer_id" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
                <option value="">{{ __('pos.optional') }}</option>
                @foreach($customers as $c)
                <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_adults') }}</label>
            <input type="number" name="adult_count" value="{{ old('adult_count', 1) }}" min="1" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1">{{ __('pos.hotel_children') }}</label>
            <input type="number" name="child_count" value="{{ old('child_count', 0) }}" min="0" class="w-full rounded-lg border-gray-300 dark:bg-gray-800 dark:text-white text-sm">
        </div>
        <div class="sm:col-span-2">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="walk_in" value="1" class="rounded border-gray-300">
                {{ __('pos.hotel_walk_in') }}
            </label>
        </div>
        <div class="sm:col-span-2">
            <button class="px-5 py-2 bg-teal-700 hover:bg-teal-800 text-white text-sm rounded-lg font-semibold">{{ __('pos.hotel_save_stay') }}</button>
        </div>
    </form>
</div>
</x-pos-layout>
