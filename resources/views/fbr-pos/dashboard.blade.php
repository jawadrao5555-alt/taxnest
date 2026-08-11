<x-fbr-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 py-4">
    <x-pwa-banner color="blue" appName="Nest FBR Pos" />
    <x-pwa-push scope="fbrpos" />

    {{-- ━━━ Stranded-day warning (Task 479 — FBR mirror of PRA Task 466): prior
         day(s) never closed. Compact echo of the day-close page's detailed
         banner — links there when the user may close days; info-only otherwise. ━━━ --}}
    @if(($unclosedPriorDays ?? collect())->isNotEmpty())
    <div class="mb-4 p-3 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-300 dark:border-red-800 flex items-center gap-3">
        <div class="w-8 h-8 rounded-full bg-red-100 dark:bg-red-900/40 flex items-center justify-center flex-shrink-0">
            <svg class="w-4 h-4 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        </div>
        <p class="flex-1 min-w-0 text-sm text-red-800 dark:text-red-300">
            <span class="font-bold">{{ trans_choice('pos.dash_unclosed_days_title', $unclosedPriorDays->count(), ['count' => $unclosedPriorDays->count()]) }}</span>
            <span class="text-red-700 dark:text-red-400">&middot; {{ $unclosedPriorDays->map(fn ($d) => \Carbon\Carbon::parse($d)->format('d M'))->implode(', ') }}</span>
            @unless($canDayClose ?? false)
            <span class="block text-xs text-red-600 dark:text-red-400 mt-0.5">{{ __('pos.dash_unclosed_days_info_only') }}</span>
            @endunless
        </p>
        @if($canDayClose ?? false)
        <a href="{{ route('fbrpos.day-close', ['date' => $unclosedPriorDays->first()]) }}"
           class="flex-shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700 transition">
            {{ __('pos.dash_unclosed_days_action') }}
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </a>
        @endif
    </div>
    @endif

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

    {{-- Task 112: Pending Bills tile (shared with PRA dashboards, Task 109) —
         provisional (local) bills not yet FINAL. isRestaurant=false branch:
         shows only to admin/manager and only when count > 0. Link goes to the
         FBR local-bills surface (transactions?tab=local). --}}
    @include('pos.partials.pending-bills-tile', [
        'isRestaurant' => false,
        'pendingBillsUrl' => route('fbrpos.transactions', ['tab' => 'local']),
    ])

    @include('fbr-pos.dashboard-styles.' . ($dashboardStyle ?? 'default'))
</div>
</x-fbr-pos-layout>
