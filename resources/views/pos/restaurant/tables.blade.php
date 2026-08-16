<x-pos-layout>
{{-- Task 819 (Aug 2026): Offline banner — tab dikhta hai jab SW cached snapshot
     serve kare (net nahi). Wapas online par auto page-reload ho jata hai. --}}
{{-- Task 865 (Aug 2026): Snapshot staleness — offline bar mein relative timestamp
     dikhta hai ("aakhri table status: 12 minute pehle") taake staff ghante purani
     status par action na le.

     Approach: server-render timestamp PHP se bake hota hai page HTML mein.
     SW network-first cache karta hai POORI response (HTML + baked timestamp).
     Jab SW cache serve kare, baked timestamp WOHI waqt hai jab page server se
     aaya tha — navigator.onLine ki zaroorat nahi (jo sirf NIC check karta hai,
     server reach nahi). Age label har 30s mein tick karta hai jab offline ho. --}}
@php $tvSnapshotMs = now()->timestamp * 1000; $tvCompanyId = auth('pos')->user()?->company_id ?? 0; @endphp
<div id="tn-tables-offline-bar" style="display:none" class="w-full bg-amber-500 text-white text-sm font-semibold text-center py-2 px-4">
    📡 {{ __('pos.offline_cached_snapshot') }}<span id="tn-tables-snapshot-age" class="font-semibold"></span>
    <span class="font-normal opacity-80 ml-1">{{ __('pos.offline_auto_refresh_hint') }}</span>
</div>
<script>
(function () {
    var bar    = document.getElementById('tn-tables-offline-bar');
    var ageEl  = document.getElementById('tn-tables-snapshot-age');
    var locale = '{{ app()->getLocale() }}';
    // Company-scoped key — isolates shops sharing a browser.
    var LS_KEY = 'tn_tables_snapshot_at_{{ $tvCompanyId }}';

    // Baked server-render timestamp. SW caches the full HTML including this value.
    // Online load  → value is current (fresh server response).
    // Cached load  → value is from when the page was last fetched from the server.
    // Either way: always persist it — it is the authoritative "snapshot freshness" marker.
    var renderedAt = {{ $tvSnapshotMs }};
    try { localStorage.setItem(LS_KEY, renderedAt.toString()); } catch (e) {}

    function fmtAge(ms) {
        if (isNaN(ms) || ms < 0) ms = 0;
        var mins = Math.round(ms / 60000);
        if (mins < 1) {
            return locale === 'ur' ? 'ابھی ابھی' : (locale === 'rur' ? 'abhi abhi' : 'just now');
        }
        if (mins < 60) {
            if (locale === 'ur') return mins + ' منٹ پہلے';
            if (locale === 'rur') return mins + ' minute pehle';
            return mins + (mins === 1 ? ' minute ago' : ' minutes ago');
        }
        var h = Math.round(mins / 60);
        if (h < 24) {
            if (locale === 'ur') return h + ' گھنٹے پہلے';
            if (locale === 'rur') return h + ' ghante pehle';
            return h + (h === 1 ? ' hour ago' : ' hours ago');
        }
        var d = Math.round(h / 24);
        if (locale === 'ur') return d + ' دن پہلے';
        if (locale === 'rur') return d + ' din pehle';
        return d + (d === 1 ? ' day ago' : ' days ago');
    }

    function syncAge() {
        if (!ageEl) return;
        try {
            var ts = parseInt(localStorage.getItem(LS_KEY) || '0', 10);
            if (ts > 0) {
                var label = locale === 'ur'  ? ' — آخری ٹیبل اسٹیٹس: '
                          : locale === 'rur' ? ' — aakhri table status: '
                          : ' — last table status: ';
                ageEl.textContent = label + fmtAge(Date.now() - ts);
            } else {
                ageEl.textContent = '';
            }
        } catch (e) { ageEl.textContent = ''; }
    }

    // Tick the age label every 30 s while bar is shown so "just now" never freezes.
    var ageInterval = null;
    function showBar() {
        syncAge();
        if (!ageInterval) ageInterval = setInterval(syncAge, 30000);
        if (bar) bar.style.display = '';
    }
    function hideBar() {
        if (ageInterval) { clearInterval(ageInterval); ageInterval = null; }
        if (ageEl) ageEl.textContent = '';
        if (bar) bar.style.display = 'none';
    }

    // servedFromCache is set async after reading the SW meta-cache flag.
    // Handles "server down but navigator.onLine still true" — SW served
    // TABLES_CACHE but the NIC is connected so 'offline' event never fires.
    var servedFromCache = false;

    function sync() {
        if (!navigator.onLine || servedFromCache) {
            showBar();
        } else {
            hideBar();
        }
    }

    // Two-step serve-mode check — handles "server down, navigator.onLine still true":
    // Step 1: ask the SW "what is my client ID?" via MessageChannel echo.
    //         Listener is registered BEFORE the message is sent — no receive race.
    //         SW just echoes e.source.id (no stored state needed; survives termination).
    // Step 2: use that clientId to read the per-client flag written durably to the
    //         'tn-tables-meta' Cache API by the SW before returning the cached response.
    //         Delete the entry after reading (one-shot, avoids stale reads on reload).
    // This survives SW termination between navigate and query because the flag lives
    // in the Cache API, not in-memory. Keyed by clientId — no cross-tab interference.
    function checkServeMode() {
        if (!('serviceWorker' in navigator) || !('caches' in window)) return;
        navigator.serviceWorker.ready.then(function (reg) {
            if (!reg.active) return;
            var mc = new MessageChannel();
            mc.port1.onmessage = function (evt) {
                if (!evt.data || evt.data.type !== 'TN_TABLES_CLIENT_ID_RESP') return;
                var clientId = evt.data.clientId;
                if (!clientId) return;
                var metaKey = location.origin + '/__tn_tables_meta_' + clientId;
                caches.open('tn-tables-meta').then(function (metaCache) {
                    return metaCache.match(metaKey).then(function (r) {
                        if (!r) return;
                        metaCache.delete(metaKey); // consume — one-shot
                        servedFromCache = true;
                        sync(); // re-evaluate: show bar even though navigator.onLine is true
                    });
                }).catch(function () {});
            };
            reg.active.postMessage({ type: 'TN_TABLES_QUERY_CLIENT_ID' }, [mc.port2]);
        }).catch(function () {});
    }

    sync();           // immediate decision based on navigator.onLine
    checkServeMode(); // async, client-scoped correction via SW MessageChannel
    window.addEventListener('offline', sync);
    // Back online → reload to get fresh table statuses from server.
    window.addEventListener('online', function () { location.reload(); });
})();
</script>
<script>
// Task 823 (Aug 2026): TABLES_CACHE re-prime after a browser-data clear.
// First visit after a clear = no controlling SW (it registers post-load), so
// TABLES_CACHE stayed empty and a Tables-first shop going offline right after
// a reset hit the offline splash instead of the cached board.
// No controller yet → once the fresh SW activates, ask it to fetch+cache this
// board in the background, so the very next open is offline-ready again.
// (Same pattern as TN_PRIME_SALE_CACHE on the sale screen.)
(function () {
    try {
        if (!('serviceWorker' in navigator) || navigator.serviceWorker.controller) return;
        window.addEventListener('load', function () {
            navigator.serviceWorker.ready.then(function (reg) {
                if (reg.active) reg.active.postMessage({ type: 'TN_PRIME_TABLES_CACHE' });
            }).catch(function () {});
        });
    } catch (e) { /* best-effort */ }
})();
</script>
<div x-data="tableView()" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.tables_overview') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('pos.realtime_table_status') }}</p>
        </div>
        <div class="flex items-center gap-2">
            @php $tvUser = auth('pos')->user(); @endphp
            @if($tvUser && !$tvUser->isPosCashier())
            <a href="{{ route('pos.restaurant.table-management') }}" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm rounded-lg border border-purple-300 dark:border-purple-700 text-purple-700 dark:text-purple-300 hover:bg-purple-50 dark:hover:bg-purple-900/20 font-medium">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                {{ __('pos.table_setup') }}
            </a>
            @endif
            <button @click="refreshStatus()" class="px-4 py-2 text-sm rounded-lg bg-purple-600 text-white hover:bg-purple-700 font-medium">{{ __('pos.refresh_btn') }}</button>
        </div>
    </div>

    {{-- ZFC (11 Aug 2026): dashboard ka "Open orders" tile is page par lata hai,
         lekin bina-table held orders (misal: held delivery) yahan dikhte hi nahi
         the — "1 pending" click kiya to kuch nahi mila. Yeh section HAR khula
         (held/preparing/ready) order dikhata hai: table wale table number ke
         saath, bina-table wale H1/H2 chip ke saath (sale screen jaisi zubaan). --}}
    @php $tvOpenOrders = $openOrders ?? collect(); @endphp
    @if($tvOpenOrders->isNotEmpty())
    <div class="mb-8 rounded-xl border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-4">
        <h2 class="text-sm font-bold text-amber-900 dark:text-amber-200">
            {{ __('pos.pending_open_tables') }}
            <span class="ml-1 inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-amber-500 text-white text-[11px] font-extrabold">{{ $tvOpenOrders->count() }}</span>
        </h2>
        <p class="text-[11px] mt-0.5 mb-3 text-amber-700 dark:text-amber-300">{{ __('pos.pending_open_tables_sub') }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            @php $tvHeldIdx = 0; @endphp
            @foreach($tvOpenOrders as $oo)
            {{-- Task 502 (11 Aug 2026): card click par WOHI order sale screen mein
                 recall ho — ?recall_order= sale screen boot par isi order ko cart
                 mein load karta hai (table_id sirf fallback context ke liye). --}}
            <a href="{{ route('pos.invoice.create', array_merge(['recall_order' => $oo->id], $oo->table_id ? ['table_id' => $oo->table_id] : [])) }}"
               class="block bg-white dark:bg-gray-800 rounded-lg border border-amber-200 dark:border-amber-700 p-3 hover:shadow-lg transition">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2 min-w-0">
                        @if($oo->table)
                            <span class="px-1.5 py-0.5 rounded-full bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-[10px] font-black whitespace-nowrap">{{ $oo->table->table_number }}</span>
                        @else
                            @php $tvHeldIdx++; @endphp
                            <span class="px-1.5 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 text-[10px] font-black whitespace-nowrap" title="{{ __('pos.held_orders_no_table') }}">H{{ $tvHeldIdx }}</span>
                        @endif
                        <span class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-[10px] font-bold whitespace-nowrap">{{ Lang::has('pos.ot_' . $oo->order_type) ? __('pos.ot_' . $oo->order_type) : strtoupper(str_replace('_', ' ', $oo->order_type)) }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $oo->customer_name ?: __('pos.other_word') }}</span>
                    </div>
                    <span class="text-sm font-extrabold text-gray-900 dark:text-white whitespace-nowrap">Rs {{ number_format((float) $oo->total_amount) }}</span>
                </div>
                <div class="mt-1 flex items-center justify-between text-[10px] text-gray-400">
                    <span class="font-mono">{{ $oo->order_number }}</span>
                    <span class="flex items-center gap-1.5">
                        {{-- Task 507 (11 Aug 2026): purane khule orders par saaf tareekh —
                             "10 Aug se khula" — take ghost order foran pehchana jaye. --}}
                        @if($oo->created_at->lt(now()->startOfDay()))
                            <span class="px-1.5 py-0.5 rounded bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 font-black whitespace-nowrap">{{ __('pos.open_since_date', ['date' => $oo->created_at->format('d M')]) }}</span>
                        @endif
                        <span data-since="{{ $oo->created_at->toIso8601String() }}">{{ $oo->created_at->diffForHumans(null, true) }}</span>
                    </span>
                </div>
            </a>
            @endforeach
        </div>
    </div>
    @endif

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
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $table->seats }}{{ __('pos.sfx_seats') }}</div>
                <div class="mt-1 text-xs font-medium {{ $table->status === 'available' ? 'text-green-600 dark:text-green-400' : ($table->status === 'occupied' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400') }}">
                    {{ Lang::has('pos.table_status_' . $table->status) ? __('pos.table_status_' . $table->status) : ucfirst($table->status) }}
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
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">{{ __('pos.no_tables_configured') }}</h3>
        <p class="text-gray-500 dark:text-gray-400 mb-4">{{ __('pos.no_tables_configured_hint') }}</p>
        @if($tvUser && !$tvUser->isPosCashier())
        <a href="{{ route('pos.restaurant.table-management') }}" class="inline-flex items-center px-4 py-2 rounded-lg bg-purple-600 text-white hover:bg-purple-700 text-sm font-medium">{{ __('pos.go_to_table_setup') }}</a>
        @else
        <p class="text-sm text-gray-400">{{ __('pos.ask_admin_configure_tables') }}</p>
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
        // Task 507 (11 Aug 2026): multi-day stale orders used to render as
        // "51h 22m" — show days so "kab se khula" is obvious at a glance.
        if (h >= 24) { var d = Math.floor(h / 24); return d + 'd ' + (h % 24) + 'h'; }
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
