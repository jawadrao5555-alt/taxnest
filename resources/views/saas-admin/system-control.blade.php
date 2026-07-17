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
