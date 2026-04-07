<x-app-layout>
    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-6">
                <nav class="flex items-center text-xs text-gray-500 dark:text-gray-400 mb-1.5">
                    <a href="{{ route('dashboard') }}" class="hover:text-emerald-600 dark:hover:text-emerald-400 transition font-medium">Dashboard</a>
                    <svg class="w-3.5 h-3.5 mx-1.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    <span class="text-gray-800 dark:text-gray-200 font-semibold">Customer Ledger</span>
                </nav>
                <h2 class="font-extrabold text-2xl text-gray-900 dark:text-white leading-tight tracking-tight">Customer Ledger</h2>
            </div>

            @if(session('success'))
            <div class="mb-4 p-4 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-700">{{ session('success') }}</div>
            @endif

            <div class="premium-card overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full premium-table">
                    <thead class="bg-gradient-to-r from-gray-50 to-gray-100/80 dark:from-gray-800 dark:to-gray-800/80">
                        <tr>
                            <th class="text-left">Customer Name</th>
                            <th class="text-left">NTN</th>
                            <th class="text-right">Total Invoiced</th>
                            <th class="text-right">Total Received</th>
                            <th class="text-right">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse($customers as $customer)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 cursor-pointer transition" onclick="window.location='/customers/{{ urlencode($customer->customer_ntn) }}/ledger'">
                            <td class="px-6 py-4 text-sm font-medium text-emerald-700 hover:text-emerald-900">
                                <a href="/customers/{{ urlencode($customer->customer_ntn) }}/ledger">{{ $customer->customer_name }}</a>
                            </td>
                            <td class="px-6 py-4 text-sm font-mono text-gray-600 dark:text-gray-400">
                                @if(str_starts_with($customer->customer_ntn, 'WALK-IN-'))
                                    <span class="premium-badge bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400 text-[10px]">Walk-in</span>
                                @else
                                    {{ $customer->customer_ntn }}
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm font-semibold text-gray-900 dark:text-gray-100 text-right">PKR {{ number_format($customer->total_invoiced, 2) }}</td>
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
