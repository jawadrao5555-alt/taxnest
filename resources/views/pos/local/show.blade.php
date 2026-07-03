@php $title = 'Local Bill ' . $bill->invoice_number; @endphp
<x-dynamic-component :component="'pos.local.layout'">
    <div class="flex items-center justify-between mb-5 print:hidden">
        <a href="{{ route('pos.local.index') }}" class="text-sm text-slate-300 hover:text-white">← Back to Local Bills</a>
        <button onclick="window.print()" class="px-4 py-2 text-xs font-semibold rounded-lg accent-bg text-white hover:opacity-90">🖨 Print Receipt</button>
    </div>

    <div class="max-w-md mx-auto bg-white text-gray-900 rounded-xl shadow-2xl p-6 print:shadow-none print:rounded-none" id="receipt">
        <div class="text-center border-b border-dashed border-gray-300 pb-3 mb-3">
            <div class="font-bold text-lg">{{ $bill->company->name ?? 'Store' }}</div>
            @if($bill->company?->address)<div class="text-xs text-gray-600">{{ $bill->company->address }}</div>@endif
            <div class="text-xs font-semibold mt-1 uppercase tracking-wider text-gray-500">Local Bill (Reprint)</div>
        </div>

        <div class="text-xs space-y-1 mb-3">
            <div class="flex justify-between"><span class="text-gray-500">Invoice #</span><span class="font-mono font-semibold">{{ $bill->invoice_number }}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">Date</span><span>{{ $bill->created_at?->format('d M Y, h:i A') }}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">Cashier</span><span>{{ $bill->creator->name ?? 'N/A' }}</span></div>
            @if($bill->customer_name)<div class="flex justify-between"><span class="text-gray-500">Customer</span><span>{{ $bill->customer_name }}</span></div>@endif
            @if($bill->customer_phone)<div class="flex justify-between"><span class="text-gray-500">Phone</span><span>{{ $bill->customer_phone }}</span></div>@endif
        </div>

        <table class="w-full text-xs mb-3">
            <thead>
                <tr class="border-y border-dashed border-gray-300 text-gray-500">
                    <th class="text-left py-1">Item</th>
                    <th class="text-center py-1">Qty</th>
                    <th class="text-right py-1">Price</th>
                    <th class="text-right py-1">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($bill->items as $item)
                <tr>
                    <td class="py-1">{{ $item->item_name }}</td>
                    <td class="text-center py-1">{{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }}</td>
                    <td class="text-right py-1">{{ number_format($item->unit_price, 2) }}</td>
                    <td class="text-right py-1">{{ number_format($item->subtotal, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="text-xs space-y-1 border-t border-dashed border-gray-300 pt-2">
            <div class="flex justify-between"><span class="text-gray-500">Subtotal</span><span>Rs {{ number_format($bill->subtotal, 2) }}</span></div>
            @if($bill->discount_amount > 0)<div class="flex justify-between"><span class="text-gray-500">Discount</span><span>- Rs {{ number_format($bill->discount_amount, 2) }}</span></div>@endif
            @if($bill->tax_amount > 0)<div class="flex justify-between"><span class="text-gray-500">Tax</span><span>Rs {{ number_format($bill->tax_amount, 2) }}</span></div>@endif
            <div class="flex justify-between font-bold text-sm border-t border-gray-300 pt-1 mt-1"><span>TOTAL</span><span>Rs {{ number_format($bill->total_amount, 2) }}</span></div>
            <div class="flex justify-between"><span class="text-gray-500">Payment</span><span class="uppercase">{{ $bill->payment_method }}</span></div>
        </div>

        <div class="text-center text-[10px] text-gray-400 mt-4 border-t border-dashed border-gray-300 pt-2">
            {{ $bill->is_archived ? 'Archived local bill' : 'Live local bill' }} · Reprinted {{ now()->format('d M Y, h:i A') }}
        </div>
    </div>

    <style>
        @media print {
            nav, footer, .print\:hidden { display: none !important; }
            body, .local-grad { background: #fff !important; }
            #receipt { box-shadow: none !important; margin: 0 auto; }
        }
    </style>
</x-dynamic-component>
