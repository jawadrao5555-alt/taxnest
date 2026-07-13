<x-pos-layout>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
            {{ ($tab ?? 'pra') === 'local' ? 'Local Reports' : 'POS Reports' }}
        </h1>
        <a href="{{ route('pos.reports.csv', ['tab' => $tab, 'cashier' => $selectedCashier]) }}" class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-700 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            Download CSV
        </a>
    </div>

    @include('pos.partials.mode-tabs', ['baseUrl' => route('pos.reports')])

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-4 mb-6">
        <form method="GET" action="{{ route('pos.reports') }}" class="flex flex-col sm:flex-row items-start sm:items-end gap-3">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="w-full sm:w-auto">
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">View Sales By</label>
                <select name="cashier" onchange="this.form.submit()" class="w-full sm:w-56 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">
                    <option value="all" {{ $selectedCashier === 'all' ? 'selected' : '' }}>All Company Sales</option>
                    @if($isCashier)
                    <option value="{{ $user->id }}" {{ $selectedCashier == $user->id ? 'selected' : '' }}>My Sales Only</option>
                    @else
                    @foreach($teamMembers as $member)
                    <option value="{{ $member->id }}" {{ $selectedCashier == $member->id ? 'selected' : '' }}>
                        {{ $member->name }} ({{ $member->pos_role === 'pos_admin' ? 'Admin' : 'Cashier' }})
                    </option>
                    @endforeach
                    @endif
                </select>
            </div>
            @if($selectedCashier !== 'all')
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300">
                    @if($isCashier && $selectedCashier == $user->id)
                        Showing: My Sales
                    @else
                        Showing: {{ $teamMembers->firstWhere('id', $selectedCashier)?->name ?? 'Staff' }}
                    @endif
                </span>
                <a href="{{ route('pos.reports', ['tab' => $tab, 'cashier' => 'all']) }}" class="text-xs text-gray-500 hover:text-purple-600 underline">Clear</a>
            </div>
            @endif
        </form>
    </div>

    @if(($tab ?? 'pra') === 'local' && isset($localBills))
    {{-- ── Local Invoices list (admin-only tab) ──
         Current-month bills can be promoted to PRA (gets a real POS serial +
         counts toward the monthly bill quota). Previous months are CLOSED —
         view/report only, the promote option disappears. --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Local Invoices</h3>
            <span class="text-xs text-gray-500">Sirf current month ke bills PRA par submit ho sakte hain</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                        <th class="pb-2">Date</th>
                        <th class="pb-2">Invoice #</th>
                        <th class="pb-2">Customer</th>
                        <th class="pb-2">Method</th>
                        <th class="pb-2 text-right">Total</th>
                        <th class="pb-2">Cashier</th>
                        <th class="pb-2 text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($localBills as $bill)
                    <tr class="border-b border-gray-50 dark:border-gray-800">
                        <td class="py-2.5 text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ $bill->created_at->format('d M Y H:i') }}</td>
                        <td class="py-2.5 font-medium text-gray-900 dark:text-white">{{ $bill->invoice_number }}</td>
                        <td class="py-2.5 text-gray-700 dark:text-gray-300">{{ $bill->customer_name ?: 'Walk-in' }}</td>
                        <td class="py-2.5 text-gray-700 dark:text-gray-300">{{ ucwords(str_replace('_', ' ', $bill->payment_method)) }}</td>
                        <td class="py-2.5 text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($bill->total_amount) }}</td>
                        <td class="py-2.5 text-gray-700 dark:text-gray-300">{{ $bill->creator?->name ?? '-' }}</td>
                        <td class="py-2.5 text-right whitespace-nowrap">
                            @if($bill->invoice_mode === 'local')
                                @if($bill->created_at->gte($monthStart))
                                <button type="button"
                                    onclick="promoteLocalBill(this, {{ $bill->id }}, '{{ $bill->invoice_number }}')"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-purple-600 text-white hover:bg-purple-700 transition">
                                    Submit to PRA
                                </button>
                                @else
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-medium bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">Month closed</span>
                                @endif
                            @else
                            {{-- Reporting-OFF final: already FINAL with a POS serial, PRA was
                                 never involved (no fiscal) — nothing to submit. --}}
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-medium bg-teal-50 text-teal-700 dark:bg-teal-900/30 dark:text-teal-300 border border-teal-200 dark:border-teal-800">Final — reporting OFF</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="py-6 text-center text-gray-400">No local invoices</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($localBills->hasPages())
        <div class="mt-4">{{ $localBills->links() }}</div>
        @endif
    </div>
    <script>
        function promoteLocalBill(btn, id, number) {
            if (!confirm('Bill ' + number + ' ko PRA par submit karein?\n\n• Ye bill FINAL ho jayega aur naya POS serial number milega\n• Monthly bill quota mein count hoga\n• Ye action wapis nahi ho sakta')) return;
            btn.disabled = true; btn.textContent = 'Submitting…';
            fetch('{{ url('/pos/api/provisional-bills') }}/' + id + '/promote', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ send_to_pra: true })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                alert(data.message || (data.success ? 'Done' : 'Failed'));
                if (data.success) { window.location.reload(); }
                else { btn.disabled = false; btn.textContent = 'Submit to PRA'; }
            })
            .catch(function () {
                alert('Network error — dobara koshish karein.');
                btn.disabled = false; btn.textContent = 'Submit to PRA';
            });
        }
    </script>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Payment Method Summary (This Month)</h3>
            <div class="overflow-x-auto -mx-5 px-5">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                        <th class="pb-2">Method</th>
                        <th class="pb-2 text-right">Count</th>
                        <th class="pb-2 text-right">Revenue</th>
                        <th class="pb-2 text-right">Tax Collected</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($paymentSummary as $ps)
                    <tr class="border-b border-gray-50 dark:border-gray-800">
                        <td class="py-2.5">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $ps->payment_method === 'cash' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400' }}">
                                {{ ucwords(str_replace('_', ' ', $ps->payment_method)) }}
                            </span>
                        </td>
                        <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $ps->count }}</td>
                        <td class="py-2.5 text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($ps->total) }}</td>
                        <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">PKR {{ number_format($ps->tax) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="py-6 text-center text-gray-400">No data this month</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Top Selling Items (This Month)</h3>
            <div class="overflow-x-auto -mx-5 px-5">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                        <th class="pb-2">#</th>
                        <th class="pb-2">Item</th>
                        <th class="pb-2 text-right">Qty Sold</th>
                        <th class="pb-2 text-right">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($topItems as $i => $item)
                    <tr class="border-b border-gray-50 dark:border-gray-800">
                        <td class="py-2.5 text-gray-400">{{ $i + 1 }}</td>
                        <td class="py-2.5 text-gray-900 dark:text-white font-medium">{{ $item->item_name }}</td>
                        <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">{{ number_format($item->total_qty) }}</td>
                        <td class="py-2.5 text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($item->total_revenue) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="py-6 text-center text-gray-400">No data this month</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Daily Sales (Last 30 Days)</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                        <th class="pb-2">Date</th>
                        <th class="pb-2 text-right">Transactions</th>
                        <th class="pb-2 text-right">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($dailySales as $day)
                    <tr class="border-b border-gray-50 dark:border-gray-800 {{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }}">
                        <td class="py-2.5 text-gray-900 dark:text-white">{{ \Carbon\Carbon::parse($day->date)->format('d M Y (D)') }}</td>
                        <td class="py-2.5 text-right text-gray-700 dark:text-gray-300">{{ $day->count }}</td>
                        <td class="py-2.5 text-right font-medium text-gray-900 dark:text-white">PKR {{ number_format($day->revenue) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="3" class="py-6 text-center text-gray-400">No sales data</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Monthly Trend (Last 6 Months)</h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @foreach($monthlyTrend as $mt)
            <div class="text-center p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                <p class="text-xs text-gray-500 mb-1">{{ \Carbon\Carbon::parse($mt->month . '-01')->format('M Y') }}</p>
                <p class="text-lg font-bold text-gray-900 dark:text-white">{{ $mt->count }}</p>
                <p class="text-xs text-emerald-600 font-medium">PKR {{ number_format($mt->revenue / 1000, 1) }}K</p>
            </div>
            @endforeach
        </div>
    </div>
</div>
@if($hasPinSet ?? false)
@include('pos.partials.pin-modal')
@endif
</x-pos-layout>