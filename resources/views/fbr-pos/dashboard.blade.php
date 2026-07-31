<x-fbr-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 py-4">
    <x-pwa-banner color="blue" appName="Nest FBR Pos" />
    <x-pwa-push scope="fbrpos" />

    {{-- ━━━ In-app notifications (mark-read dismissal, 30-day window — mirrors DI dashboard) ━━━ --}}
    @if(isset($notifications) && $notifications->count() > 0)
    <div class="mb-4 space-y-2">
        @foreach($notifications as $notif)
        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl p-3 flex items-center space-x-3">
            <div class="p-1.5 bg-amber-500 rounded-lg shadow-sm">
                <svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>
            </div>
            <p class="flex-1 text-sm text-amber-900 dark:text-amber-100"><span class="font-bold">{{ $notif->title }}</span> &middot; {{ $notif->message }}</p>
            <form method="POST" action="{{ route('fbrpos.notifications.dismiss', $notif->id) }}" class="flex-shrink-0">
                @csrf
                <button type="submit" title="{{ __('pos.dismiss') }}" aria-label="{{ __('pos.dismiss_notification') }}" class="p-1.5 rounded-lg text-amber-500 hover:text-amber-700 hover:bg-amber-100 dark:hover:bg-amber-800/40 transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </form>
        </div>
        @endforeach
        @if($notifications->count() > 1)
        <div class="flex justify-end">
            <form method="POST" action="{{ route('fbrpos.notifications.dismiss-all') }}">
                @csrf
                <button type="submit" class="text-xs font-semibold text-amber-700 dark:text-amber-300 hover:text-amber-900 dark:hover:text-amber-100 transition">{{ __('pos.dismiss_all') }}</button>
            </form>
        </div>
        @endif
    </div>
    @endif

    @include('fbr-pos.dashboard-styles.' . ($dashboardStyle ?? 'default'))
</div>
</x-fbr-pos-layout>
