@php $title = 'Local Bills'; @endphp
<x-dynamic-component :component="'pos.local.layout'">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="bg-slate-900/60 border border-slate-800 rounded-xl p-4">
            <div class="text-[11px] uppercase tracking-wider text-slate-400">Today's Local Bills</div>
            <div class="text-2xl font-bold accent mt-1">{{ number_format($stats['today']) }}</div>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-xl p-4">
            <div class="text-[11px] uppercase tracking-wider text-slate-400">Today's Amount</div>
            <div class="text-2xl font-bold text-white mt-1">Rs {{ number_format($stats['today_sum'], 2) }}</div>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-xl p-4">
            <div class="text-[11px] uppercase tracking-wider text-slate-400">Total Bills (filtered)</div>
            <div class="text-2xl font-bold accent mt-1">{{ number_format($stats['total']) }}</div>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-xl p-4">
            <div class="text-[11px] uppercase tracking-wider text-slate-400">Total Amount (filtered)</div>
            <div class="text-2xl font-bold text-white mt-1">Rs {{ number_format($stats['sum'], 2) }}</div>
        </div>
    </div>

    <form method="GET" class="bg-slate-900/60 border border-slate-800 rounded-xl p-4 mb-6 grid grid-cols-2 sm:grid-cols-5 gap-3">
        <input type="text" name="q" value="{{ request('q') }}" placeholder="Search invoice / customer / phone" class="col-span-2 sm:col-span-2 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-violet-500 focus:ring-1 focus:ring-violet-500">
        <input type="date" name="from" value="{{ request('from') }}" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
        <input type="date" name="to" value="{{ request('to') }}" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
        <select name="cashier" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
            <option value="">All Cashiers</option>
            @foreach($cashiers as $c)
                <option value="{{ $c->id }}" @selected(request('cashier') == $c->id)>{{ $c->name }}</option>
            @endforeach
        </select>
        <div class="col-span-2 sm:col-span-5 flex gap-2 justify-end">
            <a href="{{ route('pos.local.index') }}" class="px-4 py-2 text-xs rounded-lg bg-slate-800 text-slate-300 hover:bg-slate-700">Clear</a>
            <button type="submit" class="px-5 py-2 text-xs font-semibold rounded-lg accent-bg text-white hover:opacity-90">Filter</button>
            <a href="{{ route('pos.local.export', request()->query()) }}" class="px-4 py-2 text-xs rounded-lg bg-emerald-900/40 text-emerald-300 border border-emerald-700/40 hover:bg-emerald-900/60">⬇ CSV</a>
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
                        <th class="px-4 py-3 text-center">Items</th>
                        <th class="px-4 py-3 text-right">Total</th>
                        <th class="px-4 py-3 text-center">Payment</th>
                        <th class="px-4 py-3 text-center">State</th>
                        <th class="px-4 py-3 text-right">View</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($bills as $b)
                    <tr class="hover:bg-slate-800/40 transition">
                        <td class="px-4 py-3 font-mono text-xs accent">{{ $b->invoice_number }}</td>
                        <td class="px-4 py-3 text-xs text-slate-300">{{ $b->created_at?->format('d M Y, h:i A') }}</td>
                        <td class="px-4 py-3 text-xs text-slate-300">{{ $b->creator->name ?? 'N/A' }}</td>
                        <td class="px-4 py-3 text-xs text-slate-300">
                            {{-- Task 791: dine-in bill with no customer → "Dine-in", not "Walk-in" --}}
                            {{ $b->customer_name ?: ($b->order_type === 'dine_in' ? __('pos.dine_in') : __('pos.walk_in')) }}
                            @if($b->order_type && in_array($b->order_type, ['dine_in', 'takeaway', 'delivery'], true))
                                <span class="inline-flex mt-0.5 px-1.5 py-0.5 rounded text-xs font-semibold uppercase tracking-wide
                                    {{ $b->order_type === 'dine_in' ? 'bg-teal-900/40 text-teal-300' : ($b->order_type === 'delivery' ? 'bg-orange-900/40 text-orange-300' : 'bg-blue-900/40 text-blue-300') }}">
                                    {{ __('pos.ot_' . $b->order_type) }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center text-xs text-slate-400">{{ $b->items->count() }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-white">Rs {{ number_format($b->total_amount, 2) }}</td>
                        <td class="px-4 py-3 text-center"><span class="text-[10px] uppercase px-2 py-0.5 rounded bg-slate-800 text-slate-300">{{ $b->payment_method }}</span></td>
                        <td class="px-4 py-3 text-center">
                            @if($b->is_archived)
                                {{-- Task 507 (11 Aug 2026): "Archived" ka matlab wazeh —
                                     day-close par mehfooz bill hai, koi pending action nahi. --}}
                                <span class="text-[10px] uppercase px-2 py-0.5 rounded bg-slate-800 text-slate-400 cursor-help" title="{{ __('pos.local_archived_explain') }}">Archived ✓</span>
                            @else
                                <span class="text-[10px] uppercase px-2 py-0.5 rounded bg-violet-900/40 text-violet-300">Live</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('pos.local.show', $b->id) }}" class="text-xs px-3 py-1 rounded-md bg-slate-800 text-slate-200 hover:bg-slate-700">Open</a>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-slate-500 text-sm">No local bills found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($bills->hasPages())
        <div class="px-4 py-3 border-t border-slate-800">{{ $bills->links() }}</div>
        @endif
        @if($bills->contains(fn ($b) => $b->is_archived))
        {{-- Task 507: archived-state legend — owner ne archived bill ko pending samjha tha. --}}
        <div class="px-4 py-2.5 border-t border-slate-800 text-[11px] text-slate-500">
            ℹ <span class="uppercase text-slate-400">Archived</span> = {{ __('pos.local_archived_explain') }}
        </div>
        @endif
    </div>
</x-dynamic-component>
