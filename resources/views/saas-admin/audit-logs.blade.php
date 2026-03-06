<x-admin-layout>
<div class="p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold text-white mb-6">Admin Audit Logs</h1>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-4 mb-6">
        <form method="GET" class="flex flex-col sm:flex-row gap-3">
            <input type="text" name="action" value="{{ request('action') }}" placeholder="Search by action..." class="flex-1 bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500">
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Filter</button>
        </form>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-800 bg-gray-800/50">
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Admin</th>
                        <th class="px-4 py-3">Action</th>
                        <th class="px-4 py-3">Target</th>
                        <th class="px-4 py-3">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @forelse($logs as $log)
                    <tr class="hover:bg-gray-800/50 {{ $loop->even ? 'bg-gray-800/20' : '' }}">
                        <td class="px-4 py-3 text-gray-400 text-xs whitespace-nowrap">{{ $log->created_at->format('d M Y H:i') }}</td>
                        <td class="px-4 py-3 text-gray-300">{{ $log->admin->name ?? 'System' }}</td>
                        <td class="px-4 py-3 text-white font-medium">{{ $log->action }}</td>
                        <td class="px-4 py-3 text-gray-400 text-xs">{{ $log->target_type ? $log->target_type . ' #' . $log->target_id : '—' }}</td>
                        <td class="px-4 py-3 text-gray-500 text-xs max-w-[200px] truncate">
                            @if($log->metadata)
                            {{ collect($log->metadata)->map(fn($v, $k) => "$k: $v")->implode(', ') }}
                            @else — @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="px-4 py-12 text-center text-gray-500">No audit logs found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($logs->hasPages())<div class="mt-4">{{ $logs->links() }}</div>@endif
</div>
</x-admin-layout>
