@php $title = 'Archived Local Bills'; @endphp
<x-dynamic-component :component="'pos.archive.layout'">
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-slate-900/60 border border-slate-800 rounded-xl p-4">
            <div class="text-[11px] uppercase tracking-wider text-slate-400">Total Archived Bills</div>
            <div class="text-2xl font-bold gold mt-1">{{ number_format($stats['total']) }}</div>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-xl p-4">
            <div class="text-[11px] uppercase tracking-wider text-slate-400">Total Amount</div>
            <div class="text-2xl font-bold text-white mt-1">Rs {{ number_format($stats['sum'], 2) }}</div>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-xl p-4">
            <div class="text-[11px] uppercase tracking-wider text-slate-400">Available Day-Close Reports</div>
            <div class="text-2xl font-bold text-white mt-1">{{ count($reports) }}</div>
        </div>
    </div>

    <form method="GET" class="bg-slate-900/60 border border-slate-800 rounded-xl p-4 mb-6 grid grid-cols-2 sm:grid-cols-5 gap-3">
        <input type="text" name="q" value="{{ request('q') }}" placeholder="Search invoice / customer / phone" class="col-span-2 sm:col-span-2 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
        <input type="date" name="from" value="{{ request('from') }}" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
        <input type="date" name="to" value="{{ request('to') }}" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
        <select name="cashier" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
            <option value="">All Cashiers</option>
            @foreach($cashiers as $c)
                <option value="{{ $c->id }}" @selected(request('cashier') == $c->id)>{{ $c->name }}</option>
            @endforeach
        </select>
        <select name="report" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
            <option value="">All Day-Closes</option>
            @foreach($reports as $r)
                <option value="{{ $r->id }}" @selected(request('report') == $r->id)>{{ $r->report_number }} — {{ \Carbon\Carbon::parse($r->report_date)->format('d M Y') }}</option>
            @endforeach
        </select>
        <div class="col-span-2 sm:col-span-5 flex gap-2 justify-end">
            <a href="{{ route('pos.archive.index') }}" class="px-4 py-2 text-xs rounded-lg bg-slate-800 text-slate-300 hover:bg-slate-700">Clear</a>
            <button type="submit" class="px-5 py-2 text-xs font-semibold rounded-lg gold-bg text-slate-900 hover:opacity-90">Filter</button>
            <a href="{{ route('pos.archive.export', request()->query()) }}" class="px-4 py-2 text-xs rounded-lg bg-emerald-900/40 text-emerald-300 border border-emerald-700/40 hover:bg-emerald-900/60">⬇ CSV</a>
        </div>
    </form>

    <div class="bg-slate-900/60 border border-slate-800 rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-900 border-b border-slate-800">
                    <tr class="text-left text-[11px] uppercase tracking-wider text-slate-400">
                        <th class="px-4 py-3">Invoice #</th>
                        <th class="px-4 py-3">Date / Time</th>
                        <th class="px-4 py-3">Cashier</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3 text-right">Items</th>
                        <th class="px-4 py-3 text-right">Total</th>
                        <th class="px-4 py-3">Archived</th>
                        <th class="px-4 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    @forelse($bills as $b)
                    <tr class="hover:bg-slate-800/40">
                        <td class="px-4 py-3 font-mono text-amber-300">{{ $b->invoice_number }}</td>
                        <td class="px-4 py-3 text-slate-300 text-xs">{{ $b->created_at?->format('d M Y, h:i A') }}</td>
                        <td class="px-4 py-3 text-slate-300">{{ $b->creator->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-300">
                            <div>{{ $b->customer_name ?: '—' }}</div>
                            <div class="text-[11px] text-slate-500">{{ $b->customer_phone }}</div>
                        </td>
                        <td class="px-4 py-3 text-right text-slate-400">{{ $b->items->count() }}</td>
                        <td class="px-4 py-3 text-right font-bold text-white">Rs {{ number_format($b->total_amount, 0) }}</td>
                        <td class="px-4 py-3 text-xs text-slate-400">{{ $b->archived_at?->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('pos.archive.show', $b->id) }}" class="text-xs px-3 py-1 rounded-md bg-slate-800 hover:bg-slate-700 text-amber-300">View</a>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="px-4 py-12 text-center text-slate-500">
                        <div class="text-4xl mb-2">📭</div>
                        <div>No archived bills found.</div>
                        <div class="text-xs text-slate-600 mt-1">Bills appear here after admin runs day-close with "Move to Archive" option.</div>
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3 border-t border-slate-800">{{ $bills->links() }}</div>
    </div>
</x-dynamic-component>
