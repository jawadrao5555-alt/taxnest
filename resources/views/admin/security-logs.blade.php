<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 leading-tight">Security Logs</h2>
            <a href="/admin/system-health" class="text-sm text-emerald-600 hover:text-emerald-700 font-medium">System Health</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">User</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">IP Address</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">User Agent</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Details</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse($logs as $log)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium
                                        @if($log->action === 'failed_login') bg-red-100 text-red-700
                                        @elseif($log->action === 'login') bg-green-100 text-green-700
                                        @elseif(str_contains($log->action, 'created')) bg-blue-100 text-blue-700
                                        @elseif(str_contains($log->action, 'changed')) bg-yellow-100 text-yellow-700
                                        @else bg-gray-100 text-gray-700
                                        @endif">
                                        {{ str_replace('_', ' ', $log->action) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700">{{ $log->user->name ?? 'Unknown' }}</td>
                                <td class="px-6 py-4 text-sm font-mono text-gray-500">{{ $log->ip_address }}</td>
                                <td class="px-6 py-4 text-sm text-gray-400 max-w-xs truncate">{{ \Illuminate\Support\Str::limit($log->user_agent, 40) }}</td>
                                <td class="px-6 py-4 text-xs text-gray-400 font-mono">
                                    @if($log->metadata)
                                    {{ json_encode($log->metadata) }}
                                    @else
                                    -
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-400">{{ $log->created_at->format('d M Y, h:i A') }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="6" class="px-6 py-8 text-center text-gray-400">No security logs yet</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($logs->hasPages())
                <div class="px-6 py-4 border-t border-gray-100">
                    {{ $logs->links() }}
                </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
