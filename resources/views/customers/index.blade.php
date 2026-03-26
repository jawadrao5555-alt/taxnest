<x-app-layout>
    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-6">
                <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Customer Ledger</h2>
            </div>

            @if(session('success'))
            <div class="mb-4 p-4 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-700">{{ session('success') }}</div>
            @endif

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Customer Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">NTN</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total Invoiced</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total Received</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse($customers as $customer)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 dark:bg-gray-800 dark:hover:bg-gray-800 cursor-pointer transition" onclick="window.location='/customers/{{ $customer->customer_ntn }}/ledger'">
                            <td class="px-6 py-4 text-sm font-medium text-emerald-700 hover:text-emerald-900">
                                <a href="/customers/{{ $customer->customer_ntn }}/ledger">{{ $customer->customer_name }}</a>
                            </td>
                            <td class="px-6 py-4 text-sm font-mono text-gray-600 dark:text-gray-400">{{ $customer->customer_ntn }}</td>
                            <td class="px-6 py-4 text-sm font-semibold text-gray-900 text-right">PKR {{ number_format($customer->total_invoiced, 2) }}</td>
                            <td class="px-6 py-4 text-sm font-semibold text-green-600 text-right">PKR {{ number_format($customer->total_received, 2) }}</td>
                            <td class="px-6 py-4 text-sm font-bold text-right {{ $customer->outstanding > 0 ? 'text-red-600' : 'text-green-600' }}">
                                PKR {{ number_format($customer->outstanding, 2) }}
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="px-6 py-8 text-center text-gray-400">No customer ledger entries yet. Entries are created when invoices are locked after FBR submission.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
