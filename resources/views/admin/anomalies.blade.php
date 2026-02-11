<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-xl text-gray-800 leading-tight">Anomaly Logs</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Company</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Severity</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($anomalies as $anomaly)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $anomaly->company->name ?? 'N/A' }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium
                                    {{ $anomaly->type === 'invoice_spike' ? 'bg-orange-100 text-orange-800' : 'bg-red-100 text-red-800' }}">
                                    {{ str_replace('_', ' ', ucfirst($anomaly->type)) }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium
                                    {{ $anomaly->severity === 'alert' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800' }}">
                                    {{ ucfirst($anomaly->severity) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 max-w-xs truncate">{{ $anomaly->description }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $anomaly->resolved ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                                    {{ $anomaly->resolved ? 'Resolved' : 'Open' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500">{{ $anomaly->created_at->diffForHumans() }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No anomalies detected</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $anomalies->links() }}</div>
        </div>
    </div>
</x-app-layout>
