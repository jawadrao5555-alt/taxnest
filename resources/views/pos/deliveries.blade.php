<x-pos-layout>
{{-- Deliveries board (Jul 2026) — open to admins, managers AND cashiers
     (the cashier is who receives the rider's cash). Rider CRUD lives on
     /pos/riders (admin-only). --}}
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6"
     x-data="{ settleRider: null, settleTotal: 0, recalcSettle(form) {
         let t = 0;
         form.querySelectorAll('input[name=\'bill_ids[]\']:checked').forEach(cb => t += parseFloat(cb.dataset.amount || 0));
         this.settleTotal = t;
     } }">

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-2">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Deliveries</h1>
        <form method="GET" action="{{ route('pos.deliveries') }}" class="flex items-center gap-2">
            <input type="date" name="date" value="{{ $day->format('Y-m-d') }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
            <button type="submit" class="px-3 py-2 rounded-lg bg-purple-600 text-white text-xs font-semibold shadow-sm hover:bg-purple-700 transition">Go</button>
        </form>
    </div>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Assign riders on delivery bills, track dispatch → delivered, and settle cash when the rider hands it over. Cash bills stay on the rider's khata until settled.</p>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">{{ session('error') }}</div>
    @endif
    @if($errors->any())
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">
        <ul class="list-disc pl-4">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
    @endif

    {{-- Rider khata cards --}}
    @if($riders->count())
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        @foreach($riders as $rider)
        @php
            $open = $khataBills[$rider->id] ?? collect();
            $owed = (float) $open->sum('total_amount');
        @endphp
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-4">
            <div class="flex items-center justify-between mb-1">
                <div class="font-semibold text-gray-900 dark:text-white text-sm truncate">
                    {{ $rider->name }}
                    @unless($rider->is_active)<span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400 align-middle">Inactive</span>@endunless
                </div>
                @if($rider->phone)<div class="text-[11px] text-gray-400">{{ $rider->phone }}</div>@endif
            </div>
            @if($owed > 0)
                <div class="text-lg font-bold text-amber-600 dark:text-amber-400">Rs. {{ number_format($owed) }}</div>
                <div class="text-[11px] text-gray-400 mb-3">{{ $open->count() }} unsettled cash {{ $open->count() === 1 ? 'bill' : 'bills' }}</div>
                <button type="button" class="w-full px-3 py-1.5 rounded-lg bg-purple-600 text-white text-xs font-semibold shadow-sm hover:bg-purple-700 transition"
                        @click="settleRider = {{ $rider->id }}; settleTotal = {{ $owed }}">Settle Cash</button>
            @else
                <div class="text-lg font-bold text-emerald-600 dark:text-emerald-400">Clear</div>
                <div class="text-[11px] text-gray-400">No cash pending</div>
            @endif
        </div>

        {{-- Settle modal (one per rider with open bills) --}}
        @if($owed > 0)
        <template x-teleport="body">
            <div x-show="settleRider === {{ $rider->id }}" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/50" @click="settleRider = null"></div>
                <div class="relative bg-white dark:bg-gray-900 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700 w-full max-w-lg p-5 max-h-[85vh] overflow-y-auto">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1">Settle Cash — {{ $rider->name }}</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Tick the bills the rider is paying for right now. Untick any bill he'll pay later (partial settlement).</p>
                    <form method="POST" action="{{ route('pos.riders.settle', $rider->id) }}" x-init="recalcSettle($el)" @change="recalcSettle($event.target.closest('form'))">
                        @csrf
                        <div class="space-y-1.5 mb-4">
                            @foreach($open as $b)
                            <label class="flex items-center gap-3 p-2.5 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/60 cursor-pointer">
                                <input type="checkbox" name="bill_ids[]" value="{{ $b->id }}" data-amount="{{ (float) $b->total_amount }}" checked class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                <span class="flex-1 min-w-0">
                                    <span class="block text-xs font-semibold text-gray-900 dark:text-white truncate">{{ $b->invoice_number ?: ('#' . $b->id) }}</span>
                                    <span class="block text-[11px] text-gray-400">{{ $b->created_at->format('d/m h:i A') }}@if($b->customer_name) · {{ $b->customer_name }}@endif</span>
                                </span>
                                <span class="text-xs font-bold text-gray-900 dark:text-white">Rs. {{ number_format((float) $b->total_amount) }}</span>
                            </label>
                            @endforeach
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Notes (optional)</label>
                            <input type="text" name="notes" maxlength="500" placeholder="e.g. evening handover" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                        </div>
                        <div class="flex items-center justify-between mt-5">
                            <div class="text-sm font-bold text-gray-900 dark:text-white">Receiving: Rs. <span x-text="settleTotal.toLocaleString()"></span></div>
                            <div class="flex gap-2">
                                <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition" @click="settleRider = null">Cancel</button>
                                <button type="submit" class="px-4 py-2 rounded-lg bg-purple-600 text-white text-sm font-semibold shadow-sm hover:bg-purple-700 transition">Confirm Settlement</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </template>
        @endif
        @endforeach
    </div>
    @else
    <div class="mb-6 p-4 rounded-xl border border-dashed border-gray-300 dark:border-gray-700 text-sm text-gray-500 dark:text-gray-400">
        No active riders yet. <a href="{{ route('pos.riders') }}" class="font-semibold text-purple-600 dark:text-purple-400 underline">Add riders</a> to start assigning deliveries.
    </div>
    @endif

    {{-- Day's delivery bills --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Delivery Bills — {{ $day->format('d M Y') }}</h3>
            <span class="text-[11px] text-gray-400">{{ $bills->count() }} bills</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800/60">
                    <tr class="text-left text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3">Bill</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Amount</th>
                        <th class="px-4 py-3">Payment</th>
                        <th class="px-4 py-3">Rider</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Update</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($bills as $b)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-semibold text-gray-900 dark:text-white">{{ $b->invoice_number ?: ('#' . $b->id) }}</div>
                            <div class="text-[11px] text-gray-400">{{ $b->created_at->format('h:i A') }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="text-gray-700 dark:text-gray-300">{{ $b->customer_name ?: 'Walk-in' }}</div>
                            @if($b->delivery_address)<div class="text-[11px] text-gray-400 max-w-[200px] truncate">{{ $b->delivery_address }}</div>@endif
                        </td>
                        <td class="px-4 py-3 font-semibold text-gray-900 dark:text-white">Rs. {{ number_format((float) $b->total_amount) }}</td>
                        <td class="px-4 py-3">
                            @if($b->payment_method === 'cash')
                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400">Cash</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300">{{ ucwords(str_replace('_',' ', $b->payment_method)) }}</span>
                            @endif
                            @if($b->rider_settlement_id)
                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400">Settled</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            {{-- Rider LOCKS once settled OR delivered/returned (terminal states) —
                                 reassign stays open only while assigned/dispatched so a rider who
                                 suddenly leaves can be swapped (khata follows rider_id). --}}
                            @if($b->rider_settlement_id || in_array($b->delivery_status, ['delivered', 'returned']))
                                <span class="text-xs text-gray-600 dark:text-gray-300">{{ $b->rider->name ?? '—' }}</span>
                            @else
                            <form method="POST" action="{{ route('pos.deliveries.assign', $b->id) }}">
                                @csrf
                                <select name="rider_id" onchange="this.form.submit()" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-xs py-1 focus:ring-purple-500 focus:border-purple-500">
                                    <option value="">— no rider —</option>
                                    @foreach($riders as $r)
                                    @if($r->is_active || $b->rider_id === $r->id)
                                    <option value="{{ $r->id }}" {{ $b->rider_id === $r->id ? 'selected' : '' }}>{{ $r->name }}{{ $r->is_active ? '' : ' (inactive)' }}</option>
                                    @endif
                                    @endforeach
                                </select>
                            </form>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $st = $b->delivery_status;
                                $stClass = [
                                    'assigned' => 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300',
                                    'dispatched' => 'bg-purple-50 dark:bg-purple-900/20 text-purple-700 dark:text-purple-300',
                                    'delivered' => 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400',
                                    'returned' => 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400',
                                ][$st] ?? 'bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400';
                            @endphp
                            <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $stClass }}">{{ $st ? ucfirst($st) : '—' }}</span>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if($b->rider_id && !$b->rider_settlement_id)
                                @if(in_array($st, ['assigned']))
                                <form method="POST" action="{{ route('pos.deliveries.status', $b->id) }}" class="inline">
                                    @csrf<input type="hidden" name="delivery_status" value="dispatched">
                                    <button type="submit" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-purple-700 dark:text-purple-300 bg-purple-50 dark:bg-purple-900/20 hover:bg-purple-100 dark:hover:bg-purple-900/40 transition">Dispatch</button>
                                </form>
                                @endif
                                @if(in_array($st, ['assigned', 'dispatched']))
                                <form method="POST" action="{{ route('pos.deliveries.status', $b->id) }}" class="inline">
                                    @csrf<input type="hidden" name="delivery_status" value="delivered">
                                    <button type="submit" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 transition">Delivered</button>
                                </form>
                                @endif
                                @if($st !== 'returned')
                                <form method="POST" action="{{ route('pos.deliveries.status', $b->id) }}" class="inline" onsubmit="return confirm('Mark this delivery as RETURNED? The bill stays recorded — it only comes off the rider\'s cash khata.');">
                                    @csrf<input type="hidden" name="delivery_status" value="returned">
                                    <button type="submit" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40 transition">Returned</button>
                                </form>
                                @endif
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400">No delivery bills on this day.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
</x-pos-layout>
