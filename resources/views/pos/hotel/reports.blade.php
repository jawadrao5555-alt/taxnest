<x-pos-layout>
<div class="tn-page tn-hotel-page max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('pos.hotel._nav')
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">{{ __('pos.nav_hotel_reports') }}</h1>
    <p class="text-sm text-gray-500 mb-5">{{ __('pos.hotel_reports_hint') }}</p>
    @include('pos.hotel._occupancy-strip', ['occupancy' => $occupancy])
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-4 mb-6">
        <div class="rounded-xl bg-white dark:bg-gray-900 border p-3">
            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">{{ __('pos.hotel_stat_collections') }}</p>
            <p class="text-xl font-extrabold mt-1">Rs {{ number_format($money['collections'] ?? 0) }}</p>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-900 border p-3">
            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">{{ __('pos.hotel_stat_charges_today') }}</p>
            <p class="text-xl font-extrabold mt-1">Rs {{ number_format($money['charges'] ?? 0) }}</p>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-900 border p-3">
            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">{{ __('pos.hotel_stat_invoiced_today') }}</p>
            <p class="text-xl font-extrabold mt-1">Rs {{ number_format($money['invoiced'] ?? 0) }}</p>
        </div>
    </div>
    <div class="tn-panel bg-white dark:bg-gray-900 rounded-xl border p-4">
        <h2 class="text-sm font-bold uppercase tracking-wide mb-3">{{ __('pos.hotel_pending_balances') }}</h2>
        @forelse($pending as $stay)
        <a href="{{ route('pos.hotel.stays.show', $stay->id) }}" class="flex items-center justify-between py-2 border-b last:border-0">
            <span class="text-sm font-semibold">{{ $stay->guest_name }} · {{ $stay->stay_number }}</span>
            <span class="text-sm font-bold">Rs {{ number_format($dues[$stay->id] ?? 0) }}</span>
        </a>
        @empty
        <p class="text-sm text-gray-500">{{ __('pos.hotel_no_pending') }}</p>
        @endforelse
    </div>
</div>
</x-pos-layout>
