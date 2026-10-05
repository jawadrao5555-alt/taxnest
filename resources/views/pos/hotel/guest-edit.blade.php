<x-hotel-layout>
<div class="tn-page tn-hotel-page max-w-xl mx-auto">
    <h1 class="text-2xl font-bold mb-3">{{ __('hotel_guests.edit') }}</h1>
    <p class="mb-4 text-sm text-gray-500">{{ __('hotel_guests.history') }}</p>
    @if($errors->any())<div role="alert" class="mb-3 text-red-700">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('pos.hotel.guests.update', $source->id) }}" class="space-y-4">
        @csrf @method('PATCH')
        <label class="block">{{ __('pos.hotel_guest') }}<input name="guest_name" required maxlength="160" value="{{ old('guest_name', $profile?->guest_name ?? $source->guest_name) }}" class="block w-full rounded border p-2 dark:bg-gray-800"></label>
        <label class="block">{{ __('pos.hotel_phone') }}<input name="guest_phone" maxlength="40" value="{{ old('guest_phone', $profile?->guest_phone ?? $source->guest_phone) }}" class="block w-full rounded border p-2 dark:bg-gray-800"></label>
        <label class="block">{{ __('pos.hotel_cnic_optional') }}<input name="guest_cnic" maxlength="20" value="{{ old('guest_cnic', $profile?->guest_cnic ?? $source->guest_cnic) }}" class="block w-full rounded border p-2 dark:bg-gray-800"></label>
        <button class="rounded bg-teal-700 text-white p-3">{{ __('hotel_guests.save') }}</button>
        <a class="ml-3 underline" href="{{ route('pos.hotel.guests') }}">{{ __('hotel_preview.back') }}</a>
    </form>
</div>
</x-hotel-layout>
