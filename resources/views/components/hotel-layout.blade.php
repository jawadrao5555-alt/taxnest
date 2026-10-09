<x-pos-layout>
<style>
/* Explicit HTML visibility beats display utilities in production's compiled CSS. */
.tn-hotel-shell [hidden] { display: none !important; }
</style>
<script src="{{ asset('js/hotel-desk.js') }}?v={{ filemtime(public_path('js/hotel-desk.js')) }}"></script>
<div class="tn-hotel-shell mx-auto px-3 sm:px-6 py-5">
    <div class="tn-hotel-shell-grid">
        <aside class="rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-gray-900 p-4 lg:sticky lg:top-5" data-hotel-desk-menu="1">
            <p class="text-xs font-bold uppercase tracking-widest text-teal-700 dark:text-teal-300">{{ __('pos.nav_hotel_front_desk') }}</p>
            <p class="mt-1 mb-4 text-sm font-semibold dark:text-white">{{ auth('pos')->user()?->company?->name }}</p>
            @include('pos.hotel._nav')
        </aside>
        <main class="min-w-0">
            @include('pos.hotel._pra-reporting')
            {{ $slot }}
            @include('pos.hotel._bill-preview')
            @include('pos.hotel._desk-popups')
        </main>
    </div>
</div>
</x-pos-layout>

