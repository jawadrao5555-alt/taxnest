<x-pos-layout>
<div x-data="tableView()" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Tables Overview</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Real-time table status</p>
        </div>
        <div class="flex items-center gap-2">
            @php $tvUser = auth('pos')->user(); @endphp
            @if($tvUser && !$tvUser->isPosCashier())
            <a href="{{ route('pos.restaurant.table-management') }}" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm rounded-lg border border-purple-300 dark:border-purple-700 text-purple-700 dark:text-purple-300 hover:bg-purple-50 dark:hover:bg-purple-900/20 font-medium">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Table Setup
            </a>
            @endif
            <button @click="refreshStatus()" class="px-4 py-2 text-sm rounded-lg bg-purple-600 text-white hover:bg-purple-700 font-medium">Refresh</button>
        </div>
    </div>

    @forelse($floors as $floor)
    <div class="mb-8">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-3">{{ $floor->name }}</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
            @foreach($floor->tables as $table)
            <a href="{{ route('pos.invoice.create', ['table_id' => $table->id]) }}" class="block bg-white dark:bg-gray-800 rounded-xl border-2 p-4 text-center transition-all hover:shadow-lg {{ $table->status === 'available' ? 'border-green-300 dark:border-green-700 hover:border-green-500' : ($table->status === 'occupied' ? 'border-red-300 dark:border-red-700 hover:border-red-500' : 'border-amber-300 dark:border-amber-700 hover:border-amber-500') }}">
                @php
                    $tblColor = $table->status === 'available' ? 'text-green-500 dark:text-green-400' : ($table->status === 'occupied' ? 'text-red-500 dark:text-red-400' : 'text-amber-500 dark:text-amber-400');
                @endphp
                <div class="mb-1.5">
                    {{-- Top-view table + chairs diagram (color = status) --}}
                    <svg viewBox="0 0 48 48" class="w-11 h-11 mx-auto {{ $tblColor }}" fill="currentColor" aria-hidden="true">
                        <rect x="17" y="1.5" width="14" height="7" rx="3"/>
                        <rect x="17" y="39.5" width="14" height="7" rx="3"/>
                        <rect x="1.5" y="17" width="7" height="14" rx="3"/>
                        <rect x="39.5" y="17" width="7" height="14" rx="3"/>
                        <circle cx="24" cy="24" r="13"/>
                        <circle cx="24" cy="24" r="8.5" fill="#fff" fill-opacity="0.35"/>
                    </svg>
                </div>
                <div class="text-lg font-bold text-gray-900 dark:text-white">{{ $table->table_number }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $table->seats }} seats</div>
                <div class="mt-1 text-xs font-medium {{ $table->status === 'available' ? 'text-green-600 dark:text-green-400' : ($table->status === 'occupied' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400') }}">
                    {{ ucfirst($table->status) }}
                </div>
                @php
                    // Occupied timer (owner, Jul 2026): occupied → occupied_since; reserved → locked_at.
                    $sinceTs = $table->status === 'occupied' ? $table->occupied_since : ($table->status === 'reserved' ? $table->locked_at : null);
                @endphp
                @if($sinceTs)
                {{-- Pizza Master feedback (Jul 2026): timer ab LIVE tick karta hai
                     (data-since + JS interval), refresh ka intezar nahi. --}}
                <div class="text-[10px] font-semibold tabular-nums {{ $table->status === 'occupied' ? 'text-red-500 dark:text-red-400' : 'text-amber-500 dark:text-amber-400' }}"
                     data-since="{{ $sinceTs->toIso8601String() }}">
                    {{ $sinceTs->diffForHumans(null, true) }}
                </div>
                @endif
            </a>
            @endforeach
        </div>
    </div>
    @empty
    <div class="text-center py-16 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 12h16M4 18h16"/></svg>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">No Tables Configured</h3>
        <p class="text-gray-500 dark:text-gray-400 mb-4">Set up your restaurant floor and tables first.</p>
        @if($tvUser && !$tvUser->isPosCashier())
        <a href="{{ route('pos.restaurant.table-management') }}" class="inline-flex items-center px-4 py-2 rounded-lg bg-purple-600 text-white hover:bg-purple-700 text-sm font-medium">Go to Table Setup</a>
        @else
        <p class="text-sm text-gray-400">Ask your POS admin to configure tables.</p>
        @endif
    </div>
    @endforelse
</div>

<script>
function tableView() {
    return {
        async refreshStatus() {
            try {
                const res = await fetch('{{ route("pos.restaurant.table-status") }}');
                if (res.ok) location.reload();
            } catch (e) {}
        },
    };
}

// Live ticking occupied/reserved timers (Pizza Master feedback, Jul 2026).
// Elapsed labels recompute from data-since every 30s — no page refresh needed.
(function () {
    function fmt(ms) {
        if (isNaN(ms) || ms < 0) ms = 0;
        var mins = Math.floor(ms / 60000);
        var h = Math.floor(mins / 60), m = mins % 60;
        return h > 0 ? (h + 'h ' + m + 'm') : (m + 'm');
    }
    function tick() {
        var now = Date.now();
        document.querySelectorAll('[data-since]').forEach(function (el) {
            var t = new Date(el.getAttribute('data-since')).getTime();
            el.textContent = fmt(now - t);
        });
    }
    tick();
    setInterval(tick, 30000);
})();
</script>
</x-pos-layout>
