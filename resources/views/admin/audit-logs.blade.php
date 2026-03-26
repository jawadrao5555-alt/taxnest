<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 leading-tight">Audit Logs</h2>
            <a href="{{ route('admin.audit.export') }}" class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-lg font-medium hover:bg-emerald-700 transition">Export CSV</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-4 mb-6">
                <form method="GET" action="{{ route('admin.audit-logs') }}" class="flex items-center gap-4">
                    <div>
                        <label class="text-xs font-medium text-gray-500 uppercase tracking-wider">Filter by Action</label>
                        <select name="action" class="mt-1 block w-full rounded-lg border-gray-300 text-sm">
                            <option value="">All Actions</option>
                            @foreach($actionTypes as $type)
                            <option value="{{ $type }}" {{ request('action') === $type ? 'selected' : '' }}>{{ str_replace('_', ' ', ucfirst($type)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mt-5">
                        <button type="submit" class="px-4 py-2 bg-gray-800 text-white text-sm rounded-lg font-medium hover:bg-gray-900 transition">Filter</button>
                        @if(request()->hasAny(['action']))
                        <a href="{{ route('admin.audit-logs') }}" class="ml-2 text-sm text-gray-500 hover:text-gray-700">Clear</a>
                        @endif
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Company</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">User</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Entity Type</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Entity ID</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">SHA256 Hash</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($logs as $log)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap">{{ $log->created_at?->format('d M Y H:i') ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ $log->company->name ?? 'System' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700">{{ $log->user->name ?? 'System' }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-bold
                                    @if(str_contains($log->action, 'created') || str_contains($log->action, 'approved')) bg-green-100 text-green-800
                                    @elseif(str_contains($log->action, 'deleted') || str_contains($log->action, 'rejected') || str_contains($log->action, 'suspended')) bg-red-100 text-red-800
                                    @elseif(str_contains($log->action, 'edited') || str_contains($log->action, 'changed')) bg-blue-100 text-blue-800
                                    @else bg-gray-100 text-gray-700
                                    @endif">{{ str_replace('_', ' ', ucfirst($log->action)) }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ $log->entity_type ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm font-mono text-gray-600">{{ $log->entity_id ?? '-' }}</td>
                            <td class="px-4 py-3 text-xs font-mono text-gray-400 truncate max-w-[120px]" title="{{ $log->sha256_hash }}">{{ $log->sha256_hash ? substr($log->sha256_hash, 0, 16) . '...' : '-' }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="px-6 py-8 text-center text-gray-400">No audit logs found</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>

                @if($logs->hasPages())
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-800">
                    {{ $logs->links() }}
                </div>
                @endif
            </div>
        </div>
    </div>
</x-admin-layout>
