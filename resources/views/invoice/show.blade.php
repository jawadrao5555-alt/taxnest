<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 leading-tight">Invoice {{ $invoice->invoice_number ?? '#' . $invoice->id }}</h2>
            <div class="flex items-center space-x-3">
                @if($invoice->status === 'draft')
                <a href="/invoice/{{ $invoice->id }}/edit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition">Edit</a>
                <form method="POST" action="/invoice/{{ $invoice->id }}/submit" class="inline">
                    @csrf
                    <button type="submit" onclick="return confirm('Submit this invoice to FBR? Once submitted, it cannot be edited.')" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg text-sm font-medium hover:bg-purple-700 transition">Submit to FBR</button>
                </form>
                @endif
                <a href="/invoice/{{ $invoice->id }}/pdf" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg text-sm font-medium hover:bg-gray-700 transition">Download PDF</a>
                <a href="/invoices" class="text-sm text-gray-600 hover:text-gray-800">Back</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="p-6 border-b border-gray-100">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h3 class="text-2xl font-bold text-gray-900">{{ $invoice->company->name ?? 'Company' }}</h3>
                            <p class="text-sm text-gray-500 mt-1">NTN: {{ $invoice->company->ntn ?? 'N/A' }}</p>
                        </div>
                        <div class="text-right">
                            <span class="inline-flex px-3 py-1 rounded-full text-sm font-medium
                                @if($invoice->status === 'draft') bg-yellow-100 text-yellow-800
                                @elseif($invoice->status === 'submitted') bg-blue-100 text-blue-800
                                @elseif($invoice->status === 'locked') bg-green-100 text-green-800
                                @endif">
                                {{ ucfirst($invoice->status) }}
                            </span>
                            @if($invoice->status === 'locked')
                            <p class="text-xs text-green-600 mt-1">FBR Verified</p>
                            @endif
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">Buyer Details</h4>
                            <p class="text-sm font-semibold text-gray-900">{{ $invoice->buyer_name }}</p>
                            <p class="text-sm text-gray-600">NTN: {{ $invoice->buyer_ntn }}</p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">Invoice Details</h4>
                            <p class="text-sm text-gray-600">Invoice #: <span class="font-semibold text-gray-900">{{ $invoice->invoice_number ?? 'INV-' . $invoice->id }}</span></p>
                            <p class="text-sm text-gray-600">Date: <span class="font-semibold text-gray-900">{{ $invoice->created_at->format('d M Y') }}</span></p>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">HS Code</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Tax</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach($invoice->items as $index => $item)
                            <tr>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $index + 1 }}</td>
                                <td class="px-6 py-4 text-sm font-mono text-gray-700">{{ $item->hs_code }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700">{{ $item->description }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700 text-right">{{ $item->quantity }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700 text-right">Rs. {{ number_format($item->price, 2) }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700 text-right">Rs. {{ number_format($item->tax, 2) }}</td>
                                <td class="px-6 py-4 text-sm font-semibold text-gray-900 text-right">Rs. {{ number_format(($item->price * $item->quantity) + $item->tax, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="6" class="px-6 py-4 text-right text-sm font-bold text-gray-700 uppercase">Grand Total</td>
                                <td class="px-6 py-4 text-right text-lg font-bold text-emerald-600">Rs. {{ number_format($invoice->total_amount, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
