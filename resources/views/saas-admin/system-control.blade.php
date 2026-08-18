<x-admin-layout>
<div class="p-4 sm:p-6 max-w-3xl mx-auto">
    <h1 class="text-2xl font-bold text-white mb-6">System Control Panel</h1>

    {{-- Scheduler heartbeat: proves the schedule:run cron is alive on prod --}}
    <div class="mb-6 rounded-xl border p-4 flex items-start gap-3 {{ $heartbeatStale ? 'border-red-800/60 bg-red-900/15' : 'border-emerald-800/50 bg-emerald-900/10' }}">
        <span class="mt-0.5 inline-block w-2.5 h-2.5 rounded-full flex-shrink-0 {{ $heartbeatStale ? 'bg-red-500' : 'bg-emerald-500' }}"></span>
        <div>
            <p class="text-sm font-semibold text-white">Background Jobs (Scheduler Heartbeat)</p>
            @if($heartbeatAt)
                <p class="text-xs mt-0.5 {{ $heartbeatStale ? 'text-red-400' : 'text-gray-400' }}">
                    Background jobs last ran: {{ $heartbeatAt->diffForHumans() }} ({{ $heartbeatAt->format('d M Y, h:i A') }})
                </p>
                @if($heartbeatStale)
                    <p class="text-xs text-red-400 mt-1 font-medium">Warning: no scheduler run in over 26 hours — the <code class="text-red-300">schedule:run</code> cron on the live server may have stopped. Trial reminders, trial expiry, FBR token checks and POS auto day-close will not fire until it is restored.</p>
                @endif
            @else
                <p class="text-xs text-red-400 mt-0.5 font-medium">No heartbeat recorded yet — the <code class="text-red-300">schedule:run</code> cron may not be configured on this server.</p>
            @endif
        </div>
    </div>

    {{-- Queue worker health: proves queued jobs are actually being processed --}}
    <div class="mb-6 rounded-xl border p-4 flex items-start gap-3 {{ $queueStale ? 'border-red-800/60 bg-red-900/15' : 'border-emerald-800/50 bg-emerald-900/10' }}">
        <span class="mt-0.5 inline-block w-2.5 h-2.5 rounded-full flex-shrink-0 {{ $queueStale ? 'bg-red-500' : 'bg-emerald-500' }}"></span>
        <div>
            <p class="text-sm font-semibold text-white">Queue Worker (Job Processing)</p>
            @if($queueHeartbeatAt)
                <p class="text-xs mt-0.5 {{ $queueStale ? 'text-red-400' : 'text-gray-400' }}">
                    Queue worker last processed a job: {{ $queueHeartbeatAt->diffForHumans() }} ({{ $queueHeartbeatAt->format('d M Y, h:i A') }})
                </p>
            @else
                <p class="text-xs mt-0.5 {{ $queueStale ? 'text-red-400 font-medium' : 'text-gray-400' }}">No queue heartbeat recorded yet{{ $queueStale ? ' — the queue worker may not be running.' : '.' }}</p>
            @endif
            @if($stuckJobs > 0)
                <p class="text-xs text-red-400 mt-1 font-medium">
                    Warning: {{ $stuckJobs }} queued {{ Str::plural('job', $stuckJobs) }} waiting for over 10 minutes{{ $oldestStuckAt ? ' (oldest queued ' . $oldestStuckAt->diffForHumans() . ')' : '' }} — the <code class="text-red-300">queue:work</code> worker on the live server may have died. FBR/PRA invoice sync, nightly compliance, trial expiry and FBR token checks will pile up unprocessed until it is restarted.
                </p>
            @elseif($queueStale)
                <p class="text-xs text-red-400 mt-1 font-medium">Warning: no queue activity recorded recently — the <code class="text-red-300">queue:work</code> worker on the live server may have died. Queued jobs (invoice sync, compliance, trial expiry) will not run until it is restarted.</p>
            @endif
        </div>
    </div>

    {{-- Logging health: daily logs:health-check probe (LOG_LEVEL + log writability) --}}
    @php $logHealthBad = $logHealthFailure || $logHealthStale; @endphp
    <div class="mb-6 rounded-xl border p-4 flex items-start gap-3 {{ $logHealthBad ? 'border-red-800/60 bg-red-900/15' : 'border-emerald-800/50 bg-emerald-900/10' }}">
        <span class="mt-0.5 inline-block w-2.5 h-2.5 rounded-full flex-shrink-0 {{ $logHealthBad ? 'bg-red-500' : 'bg-emerald-500' }}"></span>
        <div>
            <p class="text-sm font-semibold text-white">Logging Health (Daily Watchdog)</p>
            @if($logHealthLastPassAt)
                <p class="text-xs mt-0.5 {{ $logHealthBad ? 'text-red-400' : 'text-gray-400' }}">
                    Last passed: {{ $logHealthLastPassAt->diffForHumans() }} ({{ $logHealthLastPassAt->format('d M Y, h:i A') }})
                </p>
            @else
                <p class="text-xs mt-0.5 {{ $logHealthBad ? 'text-red-400 font-medium' : 'text-gray-400' }}">Never ran — the daily <code class="{{ $logHealthBad ? 'text-red-300' : 'text-gray-300' }}">logs:health-check</code> job has not recorded a pass yet (check the <code class="{{ $logHealthBad ? 'text-red-300' : 'text-gray-300' }}">schedule:run</code> cron).</p>
            @endif
            @if($logHealthFailure)
                <p class="text-xs text-red-400 mt-1 font-medium">
                    Failing{{ !empty($logHealthFailure['ago']) ? ' since ' . $logHealthFailure['ago'] : '' }} ({{ $logHealthFailure['count'] }} {{ Str::plural('check', $logHealthFailure['count']) }} failed):
                </p>
                @foreach($logHealthFailure['issues'] as $issue)
                    <p class="text-xs text-red-400 mt-0.5">• {{ $issue }}</p>
                @endforeach
            @elseif($logHealthStale && $logHealthLastPassAt)
                <p class="text-xs text-red-400 mt-1 font-medium">Warning: last pass is over a day old — the daily logging watchdog may have stopped running.</p>
            @endif
        </div>
    </div>

    {{-- MySQL connection ratio: Threads_connected / max_connections — auto-refreshes every 30 s.
         Initial band colours are SERVER-rendered (testable + correct before Alpine hydrates);
         the Alpine :class object bindings then toggle the same classes on live refresh. --}}
    @php
        $mysqlBad    = $mysqlPct !== null && $mysqlPct > 70;
        $mysqlWarn   = $mysqlPct !== null && $mysqlPct > 50 && $mysqlPct <= 70;
        $mysqlOk     = $mysqlPct !== null && $mysqlPct <= 50;
        $mysqlBorder = $mysqlBad  ? 'border-red-800/60 bg-red-900/15'
                     : ($mysqlWarn ? 'border-amber-700/60 bg-amber-900/10'
                     : ($mysqlOk   ? 'border-emerald-800/50 bg-emerald-900/10'
                                   : 'border-gray-700/50 bg-gray-900/20'));
        $mysqlDot    = $mysqlBad  ? 'bg-red-500'
                     : ($mysqlWarn ? 'bg-amber-400'
                     : ($mysqlOk   ? 'bg-emerald-500'
                                   : 'bg-gray-500'));
    @endphp
    <div class="mb-6 rounded-xl border p-4 flex items-start gap-3 {{ $mysqlBorder }}" data-testid="mysql-row"
         x-data="mysqlHealth({{ (int)($mysqlThreads ?? 0) }}, {{ (int)($mysqlMaxConn ?? 0) }}, {{ $mysqlPct !== null ? (float)$mysqlPct : 'null' }})"
         :class="borderClass">
        <span class="mt-0.5 inline-block w-2.5 h-2.5 rounded-full flex-shrink-0 {{ $mysqlDot }}" data-testid="mysql-dot" :class="dotClass"></span>
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
                <p class="text-sm font-semibold text-white">MySQL Connections</p>
                <button @click="refresh()"
                        :disabled="loading"
                        class="text-xs px-2 py-0.5 rounded border border-gray-700 text-gray-400 hover:text-white hover:border-gray-500 transition disabled:opacity-40"
                        title="Refresh now">
                    <span x-show="!loading">↻ Refresh</span>
                    <span x-show="loading">…</span>
                </button>
                <span class="text-xs text-gray-600" x-text="lastUpdated"></span>
            </div>
            <template x-if="pct !== null">
                <div>
                    <p class="text-xs mt-0.5" :class="txtClass">
                        Threads_connected: <span class="font-mono font-medium text-white" x-text="threads"></span>
                        / max_connections: <span class="font-mono font-medium text-white" x-text="maxConn"></span>
                        &mdash;
                        <span class="font-semibold" :class="pctClass" x-text="pct + '%'"></span>
                        in use
                    </p>
                    <p x-show="pct > 70" class="text-xs text-red-400 mt-1 font-medium">Warning: connection usage above 70% — shops may receive "Too many connections" errors if usage keeps climbing. Raise <code class="text-red-300">max_connections</code> in WHM or reduce long-lived connections.</p>
                    <p x-show="pct > 50 && pct <= 70" class="text-xs text-amber-400 mt-1">Connection usage is elevated (50–70%). Monitor closely; a sudden spike could saturate the pool.</p>
                </div>
            </template>
            <template x-if="pct === null">
                <p class="text-xs text-gray-500 mt-0.5">Could not read MySQL status — the database user may lack <code class="text-gray-400">PROCESS</code> or <code class="text-gray-400">SUPER</code> privilege to query <code class="text-gray-400">information_schema.GLOBAL_STATUS</code>.</p>
            </template>
        </div>
    </div>

    <script>
    function mysqlHealth(initThreads, initMax, initPct) {
        return {
            threads:     initPct !== null ? initThreads : null,
            maxConn:     initPct !== null ? initMax     : null,
            pct:         initPct,
            loading:     false,
            lastUpdated: '',
            _timer:      null,

            get bad()  { return this.pct !== null && this.pct > 70; },
            get warn() { return this.pct !== null && this.pct > 50 && this.pct <= 70; },
            get ok()   { return this.pct !== null && this.pct <= 50; },

            // Object syntax so Alpine actively REMOVES classes when a band
            // flips on refresh — string returns would leave the server-rendered
            // initial classes stuck on the element.
            get borderClass() {
                return {
                    'border-red-800/60 bg-red-900/15':       this.bad,
                    'border-amber-700/60 bg-amber-900/10':   this.warn,
                    'border-emerald-800/50 bg-emerald-900/10': this.ok,
                    'border-gray-700/50 bg-gray-900/20':     this.pct === null,
                };
            },
            get dotClass() {
                return {
                    'bg-red-500':     this.bad,
                    'bg-amber-400':   this.warn,
                    'bg-emerald-500': this.ok,
                    'bg-gray-500':    this.pct === null,
                };
            },
            get txtClass() {
                return {
                    'text-red-400':   this.bad,
                    'text-amber-400': this.warn,
                    'text-gray-400':  this.ok,
                    'text-gray-500':  this.pct === null,
                };
            },
            get pctClass() {
                return {
                    'text-red-300':     this.bad,
                    'text-amber-300':   this.warn,
                    'text-emerald-300': !this.bad && !this.warn,
                };
            },

            init() {
                this._timer = setInterval(() => this.refresh(), 30000);
            },
            destroy() {
                if (this._timer) clearInterval(this._timer);
            },

            async refresh() {
                if (this.loading) return;
                this.loading = true;
                try {
                    const res  = await fetch('{{ route('saas.admin.system.mysql-health') }}', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    const data = await res.json();
                    this.threads = data.threads;
                    this.maxConn = data.max_connections;
                    this.pct     = data.pct;
                    const now = new Date();
                    this.lastUpdated = 'updated ' + now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                } catch (e) {
                    // silent — keep last known values
                } finally {
                    this.loading = false;
                }
            },
        };
    }
    </script>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-5">Emergency switches to control platform-wide features. Changes take effect immediately.</p>

        <div class="space-y-4">
            @foreach($controls as $control)
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 rounded-lg border {{ $control->value === 'enabled' ? 'border-emerald-800/50 bg-emerald-900/10' : 'border-red-800/50 bg-red-900/10' }}">
                <div>
                    <p class="text-sm font-semibold text-white">{{ ucwords(str_replace('_', ' ', $control->key)) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $control->description }}</p>
                </div>
                <form method="POST" action="{{ route('saas.admin.system.toggle', $control->key) }}">
                    @csrf
                    <button class="w-full sm:w-auto px-4 py-2 rounded-lg text-sm font-medium transition {{ $control->value === 'enabled' ? 'bg-red-600/20 text-red-400 hover:bg-red-600/40 border border-red-700/50' : 'bg-emerald-600/20 text-emerald-400 hover:bg-emerald-600/40 border border-emerald-700/50' }}">
                        {{ $control->value === 'enabled' ? 'Disable' : 'Enable' }}
                    </button>
                </form>
            </div>
            @endforeach
        </div>
    </div>
</div>
</x-admin-layout>
