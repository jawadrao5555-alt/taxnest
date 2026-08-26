@php $title = 'Bill ' . $bill->invoice_number; @endphp
<x-dynamic-component :component="'pos.archive.layout'">
    <div class="mb-4">
        <a href="{{ route('pos.archive.index') }}" class="text-sm text-slate-400 hover:text-white">&larr; Back to Archive</a>
    </div>

    <div class="bg-slate-900/60 border border-slate-800 rounded-xl p-6 mb-6">
        <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
            <div>
                <div class="text-[11px] uppercase tracking-widest text-slate-400">Archived Local Bill</div>
                <div class="text-2xl font-bold gold mt-1">{{ $bill->invoice_number }}</div>
                <div class="text-xs text-slate-500 mt-1">Created: {{ $bill->created_at->format('d M Y, h:i A') }} · Archived: {{ $bill->archived_at?->format('d M Y') ?? '—' }}</div>
            </div>
            <div class="text-right">
                <div class="text-[11px] uppercase tracking-widest text-slate-400">Total</div>
                <div class="text-3xl font-bold text-white">Rs {{ number_format($bill->total_amount, 2) }}</div>
                <div class="text-xs text-slate-500 mt-1">PRA Status: <span class="text-amber-400">{{ $bill->pra_status ?? 'local' }}</span></div>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6 text-sm">
            <div><div class="text-[11px] text-slate-400 uppercase">Cashier</div><div class="text-white">{{ $bill->creator->name ?? '—' }}</div></div>
            <div><div class="text-[11px] text-slate-400 uppercase">Customer</div><div class="text-white">{{ $bill->customer_name ?: '—' }}</div></div>
            <div><div class="text-[11px] text-slate-400 uppercase">Phone</div><div class="text-white">{{ $bill->customer_phone ?: '—' }}</div></div>
            <div><div class="text-[11px] text-slate-400 uppercase">Payment</div><div class="text-white">{{ \App\Support\PosPaymentLabels::label($bill->payment_method) }}</div></div>
        </div>

        <div class="border border-slate-800 rounded-lg overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-900">
                    <tr class="text-left text-[11px] uppercase tracking-wider text-slate-400">
                        <th class="px-4 py-2">Item</th>
                        <th class="px-4 py-2 text-right">Qty</th>
                        <th class="px-4 py-2 text-right">Unit Price</th>
                        <th class="px-4 py-2 text-right">Subtotal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    @foreach($bill->items as $it)
                    <tr>
                        <td class="px-4 py-2 text-white">{{ $it->item_name }}</td>
                        <td class="px-4 py-2 text-right text-slate-300">{{ $it->quantity }}</td>
                        <td class="px-4 py-2 text-right text-slate-300">Rs {{ number_format($it->unit_price, 2) }}</td>
                        <td class="px-4 py-2 text-right text-white font-medium">Rs {{ number_format($it->subtotal, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
            <div><div class="text-[11px] text-slate-400 uppercase">Subtotal</div><div class="text-white">Rs {{ number_format($bill->subtotal, 2) }}</div></div>
            <div><div class="text-[11px] text-slate-400 uppercase">Discount</div><div class="text-white">Rs {{ number_format($bill->discount_amount, 2) }}</div></div>
            <div><div class="text-[11px] text-slate-400 uppercase">Tax</div><div class="text-white">Rs {{ number_format($bill->tax_amount, 2) }}</div></div>
            <div><div class="text-[11px] text-slate-400 uppercase">Total</div><div class="text-amber-300 font-bold">Rs {{ number_format($bill->total_amount, 2) }}</div></div>
        </div>
    </div>

    <div class="text-xs text-slate-500 text-center">
        Read-only audit view. To restore or modify, contact SaaS administrator.
    </div>
</x-dynamic-component>
