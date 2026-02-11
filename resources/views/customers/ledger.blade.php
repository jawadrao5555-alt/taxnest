<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center space-x-3">
            <a href="/customers" class="text-gray-400 hover:text-gray-600 transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <h2 class="font-bold text-xl text-gray-800 leading-tight">{{ $summary['customer_name'] }} - Ledger</h2>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            @if(session('success'))
            <div class="mb-4 p-4 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-700">{{ session('success') }}</div>
            @endif
            @if(session('error'))
            <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">{{ session('error') }}</div>
            @endif

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">{{ $summary['customer_name'] }}</h3>
                        <p class="text-sm text-gray-500 font-mono">NTN: {{ $summary['customer_ntn'] }}</p>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="bg-blue-50 rounded-xl p-4 text-center">
                        <p class="text-2xl font-extrabold text-blue-700">Rs. {{ number_format($summary['total_invoiced'], 2) }}</p>
                        <p class="text-xs font-medium text-blue-600 mt-1">Total Invoiced</p>
                    </div>
                    <div class="bg-green-50 rounded-xl p-4 text-center">
                        <p class="text-2xl font-extrabold text-green-700">Rs. {{ number_format($summary['total_received'], 2) }}</p>
                        <p class="text-xs font-medium text-green-600 mt-1">Total Received</p>
                    </div>
                    <div class="rounded-xl p-4 text-center {{ $summary['outstanding'] > 0 ? 'bg-red-50' : 'bg-emerald-50' }}">
                        <p class="text-2xl font-extrabold {{ $summary['outstanding'] > 0 ? 'text-red-700' : 'text-emerald-700' }}">Rs. {{ number_format($summary['outstanding'], 2) }}</p>
                        <p class="text-xs font-medium {{ $summary['outstanding'] > 0 ? 'text-red-600' : 'text-emerald-600' }} mt-1">Outstanding Balance</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-800">Ledger Entries</h3>
                </div>
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice #</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Debit</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Credit</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($entries as $entry)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm text-gray-600">{{ $entry->created_at->format('d M Y H:i') }}</td>
                            <td class="px-6 py-4">
                                <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-bold
                                    @if($entry->type === 'invoice') bg-blue-100 text-blue-800
                                    @elseif($entry->type === 'payment') bg-green-100 text-green-800
                                    @else bg-amber-100 text-amber-800
                                    @endif">{{ ucfirst($entry->type) }}</span>
                            </td>
                            <td class="px-6 py-4 text-sm font-mono text-gray-600">
                                @if($entry->invoice)
                                <a href="/invoice/{{ $entry->invoice_id }}" class="text-emerald-600 hover:text-emerald-800">{{ $entry->invoice->invoice_number }}</a>
                                @else
                                -
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm font-semibold text-right {{ $entry->debit > 0 ? 'text-red-600' : 'text-gray-400' }}">
                                {{ $entry->debit > 0 ? 'Rs. ' . number_format($entry->debit, 2) : '-' }}
                            </td>
                            <td class="px-6 py-4 text-sm font-semibold text-right {{ $entry->credit > 0 ? 'text-green-600' : 'text-gray-400' }}">
                                {{ $entry->credit > 0 ? 'Rs. ' . number_format($entry->credit, 2) : '-' }}
                            </td>
                            <td class="px-6 py-4 text-sm font-bold text-right {{ $entry->balance_after > 0 ? 'text-red-600' : 'text-green-600' }}">
                                Rs. {{ number_format($entry->balance_after, 2) }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $entry->notes ?? '-' }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="px-6 py-8 text-center text-gray-400">No ledger entries found</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h4 class="text-sm font-bold text-gray-800 mb-4">Record Payment</h4>
                    <form method="POST" action="/customers/payment">
                        @csrf
                        <input type="hidden" name="customer_ntn" value="{{ $summary['customer_ntn'] }}">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Amount (Rs.)</label>
                                <input type="number" name="amount" step="0.01" min="0.01" required class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 text-sm" placeholder="0.00">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                                <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 text-sm" placeholder="Payment reference, cheque number, etc."></textarea>
                            </div>
                            <button type="submit" class="w-full px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 transition">Record Payment</button>
                        </div>
                    </form>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h4 class="text-sm font-bold text-gray-800 mb-4">Record Adjustment</h4>
                    <form method="POST" action="/customers/adjustment">
                        @csrf
                        <input type="hidden" name="customer_ntn" value="{{ $summary['customer_ntn'] }}">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                <select name="adjustment_type" required class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 text-sm">
                                    <option value="debit">Debit (increase balance)</option>
                                    <option value="credit">Credit (decrease balance)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Amount (Rs.)</label>
                                <input type="number" name="amount" step="0.01" min="0.01" required class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 text-sm" placeholder="0.00">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                                <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 text-sm" placeholder="Reason for adjustment"></textarea>
                            </div>
                            <button type="submit" class="w-full px-4 py-2 bg-amber-600 text-white rounded-lg text-sm font-medium hover:bg-amber-700 transition">Record Adjustment</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
