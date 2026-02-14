<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice->invoice_number }} - TaxNest</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="max-w-4xl mx-auto py-8 px-4">
        <div class="text-center mb-6">
            <h1 class="text-2xl font-bold text-gray-900">TaxNest Invoice</h1>
            <p class="text-sm text-gray-500">Shared Invoice Document</p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
            <div class="p-6 border-b border-gray-100">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900">{{ $invoice->company->name ?? 'Company' }}</h2>
                        <p class="text-sm text-gray-500">NTN: {{ $invoice->company->ntn ?? 'N/A' }}</p>
                    </div>
                    <div class="text-right">
                        <span class="inline-flex px-3 py-1 rounded-full text-sm font-medium
                            @if($invoice->status === 'draft') bg-yellow-100 text-yellow-800
                            @elseif($invoice->status === 'submitted') bg-blue-100 text-blue-800
                            @elseif($invoice->status === 'locked') bg-green-100 text-green-800
                            @endif">{{ ucfirst($invoice->status) }}</span>
                        <p class="text-sm text-gray-500 mt-1">#{{ $invoice->invoice_number }}</p>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-gray-50 rounded-lg p-3">
                        <p class="text-xs text-gray-500 uppercase font-medium">Buyer</p>
                        <p class="text-sm font-semibold text-gray-900">{{ $invoice->buyer_name }}</p>
                        <p class="text-sm text-gray-600">NTN: {{ $invoice->buyer_ntn }}</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-3">
                        <p class="text-xs text-gray-500 uppercase font-medium">Date</p>
                        <p class="text-sm font-semibold text-gray-900">{{ $invoice->created_at->format('d M Y') }}</p>
                        @if($invoice->fbr_invoice_id)
                        <p class="text-sm text-green-700 font-medium">FBR: {{ $invoice->fbr_invoice_id }}</p>
                        @endif
                    </div>
                </div>
            </div>

            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
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
                        <td class="px-6 py-3 text-sm text-gray-500">{{ $index + 1 }}</td>
                        <td class="px-6 py-3 text-sm text-gray-700">{{ $item->description }}</td>
                        <td class="px-6 py-3 text-sm text-gray-700 text-right">{{ $item->quantity }}</td>
                        <td class="px-6 py-3 text-sm text-gray-700 text-right">Rs. {{ number_format($item->price, 2) }}</td>
                        <td class="px-6 py-3 text-sm text-gray-700 text-right">Rs. {{ number_format($item->tax, 2) }}</td>
                        <td class="px-6 py-3 text-sm font-semibold text-gray-900 text-right">Rs. {{ number_format(($item->price * $item->quantity) + $item->tax, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50">
                    <tr>
                        <td colspan="5" class="px-6 py-3 text-right text-sm font-bold text-gray-700">Grand Total</td>
                        <td class="px-6 py-3 text-right text-lg font-bold text-emerald-600">Rs. {{ number_format($invoice->total_amount, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        @if($invoice->qr_data)
        @php $qrInfo = json_decode($invoice->qr_data, true); @endphp
        <div class="bg-white rounded-xl shadow-sm border border-green-200 p-6 mb-6">
            <h3 class="text-center text-lg font-bold text-green-800 mb-3">FBR Verified</h3>
            <div class="grid grid-cols-2 gap-2 text-sm">
                <p class="text-gray-500">NTN:</p><p class="font-semibold">{{ $qrInfo['ntn'] ?? '' }}</p>
                <p class="text-gray-500">FBR ID:</p><p class="font-semibold">{{ $qrInfo['fbr_invoice_id'] ?? '' }}</p>
                <p class="text-gray-500">Date:</p><p class="font-semibold">{{ $qrInfo['date'] ?? '' }}</p>
                <p class="text-gray-500">Total:</p><p class="font-semibold">Rs. {{ number_format($qrInfo['total'] ?? 0, 2) }}</p>
            </div>
            @if($invoice->qr_image_url)
            <div class="text-center mt-4">
                <img src="{{ $invoice->qr_image_url }}" alt="QR Code" class="inline-block w-32 h-32">
            </div>
            @endif
        </div>
        @endif

        <div class="flex items-center justify-center space-x-4">
            <button onclick="window.print()" class="inline-flex items-center px-6 py-3 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition">
                <svg class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Print / Save as PDF
            </button>
        </div>

        <p class="text-center text-xs text-gray-400 mt-6">Powered by TaxNest — Tax & Invoice Management System</p>
    </div>
</body>
</html>
