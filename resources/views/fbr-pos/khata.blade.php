<x-fbr-pos-layout>
<div class="max-w-5xl mx-auto" x-data="khataPage()">
    @include('fbr-pos.partials.back-link')
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Udhaar / Khata</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Customer credit ledger — udhaar bills aur wasooli ka hisaab</p>
        </div>
        <a href="{{ route('fbrpos.create') }}" class="text-sm text-blue-600 hover:underline">← Sale Screen</a>
    </div>

    {{-- Stat tiles --}}
    <div class="grid grid-cols-2 gap-3 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border-l-4 border-red-500">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400">Total Udhaar (Baqaya)</p>
            <p class="text-2xl font-extrabold text-red-600 dark:text-red-400 mt-1">Rs {{ number_format($totalOutstanding, 0) }}</p>
            <p class="text-xs text-gray-400 mt-0.5">{{ $customers->where('khata_balance', '>', 0)->count() }} customers</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border-l-4 border-green-500">
            <p class="text-xs font-bold uppercase tracking-wider text-gray-400">Wasooli (30 din)</p>
            <p class="text-2xl font-extrabold text-green-600 dark:text-green-400 mt-1">Rs {{ number_format($recentWasooli, 0) }}</p>
            <p class="text-xs text-gray-400 mt-0.5">received</p>
        </div>
    </div>

    {{-- Customer list --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h3 class="font-bold text-gray-900 dark:text-white">Khata Customers</h3>
            <input type="text" x-model="filter" placeholder="Naam / phone search..." autocomplete="off" name="khata_search_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                   class="border rounded-lg px-3 py-1.5 text-sm w-48 dark:bg-gray-700 dark:text-white dark:border-gray-600">
        </div>

        @if($customers->isEmpty())
            <div class="p-10 text-center text-gray-400">
                <p class="text-lg font-semibold">{{ __('pos.khata_empty_title') }}</p>
                <p class="text-sm mt-1">{{ __('pos.khata_empty_hint') }}</p>
            </div>
        @else
        <table class="w-full text-sm table-cards">
            <thead class="bg-gray-50 dark:bg-gray-700 text-left">
                <tr>
                    <th class="px-4 py-2">Customer</th>
                    <th class="px-4 py-2">Phone</th>
                    <th class="px-4 py-2 text-right">Balance</th>
                    <th class="px-4 py-2 text-right">Amal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($customers as $c)
                <tr class="border-t dark:border-gray-700"
                    x-show="filter === '' || '{{ strtolower(addslashes($c->name . ' ' . $c->phone)) }}'.includes(filter.toLowerCase())">
                    <td class="px-4 py-2.5 font-semibold text-gray-900 dark:text-white">{{ $c->name }}</td>
                    <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">{{ $c->phone ?: '—' }}</td>
                    <td class="px-4 py-2.5 text-right font-bold {{ $c->khata_balance > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                        Rs {{ number_format($c->khata_balance, 0) }}
                    </td>
                    <td class="px-4 py-2.5 text-right whitespace-nowrap">
                        <button @click="openWasooli({{ $c->id }}, '{{ addslashes($c->name) }}', {{ (float) $c->khata_balance }})"
                                class="px-3 py-1.5 rounded-lg bg-green-600 text-white text-xs font-bold hover:bg-green-700">Wasooli</button>
                        <button @click="openLedger({{ $c->id }})"
                                class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-xs font-bold hover:bg-gray-200 dark:hover:bg-gray-600">Ledger</button>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>

    {{-- Wasooli modal --}}
    <div x-show="showWasooli" x-cloak class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showWasooli = false">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Wasooli — <span x-text="wasooliName"></span></h3>
            <p class="text-sm text-gray-500 mb-4">Baqaya balance: <strong class="text-red-600">Rs <span x-text="wasooliBalance.toLocaleString()"></span></strong></p>
            <form method="POST" action="{{ route('fbrpos.khata.wasooli') }}">
                @csrf
                <input type="hidden" name="customer_id" :value="wasooliCustomerId">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Received amount (Rs)</label>
                <input type="number" name="amount" x-ref="wasooliAmount" step="0.01" min="0.01" required autocomplete="off" data-lpignore="true"
                       class="w-full border rounded-lg px-3 py-2.5 text-lg font-bold dark:bg-gray-700 dark:text-white dark:border-gray-600 mb-3">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Note (optional)</label>
                <input type="text" name="note" maxlength="300" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="w-full border rounded-lg px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600 mb-4">
                <div class="flex gap-2">
                    <button type="button" @click="showWasooli = false" class="flex-1 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 font-semibold">Cancel</button>
                    <button type="submit" class="flex-1 py-2.5 rounded-lg bg-green-600 text-white font-bold hover:bg-green-700">Wasooli Save</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Ledger drawer --}}
    <div x-show="showLedger" x-cloak class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4" @click.self="showLedger = false">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-2xl p-6 max-h-[85vh] flex flex-col">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white" x-text="ledgerCustomer.name || 'Ledger'"></h3>
                    <p class="text-sm text-gray-500">Balance: <strong :class="ledgerCustomer.khata_balance > 0 ? 'text-red-600' : 'text-green-600'">Rs <span x-text="(ledgerCustomer.khata_balance || 0).toLocaleString()"></span></strong></p>
                </div>
                <button @click="showLedger = false" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <div class="overflow-y-auto flex-1">
                <template x-if="ledgerLoading"><p class="text-center text-gray-400 py-8">Loading...</p></template>
                <template x-if="!ledgerLoading && ledgerEntries.length === 0"><p class="text-center text-gray-400 py-8">{{ __('pos.khata_no_entries') }}</p></template>
                <table class="w-full text-sm" x-show="!ledgerLoading && ledgerEntries.length > 0">
                    <thead class="bg-gray-50 dark:bg-gray-700 text-left sticky top-0">
                        <tr><th class="px-3 py-2">Date</th><th class="px-3 py-2">Detail</th><th class="px-3 py-2 text-right">Amount</th><th class="px-3 py-2 text-right">Balance</th></tr>
                    </thead>
                    <tbody>
                        <template x-for="e in ledgerEntries" :key="e.id">
                            <tr class="border-t dark:border-gray-700">
                                <td class="px-3 py-2 text-xs text-gray-500 whitespace-nowrap" x-text="e.date"></td>
                                <td class="px-3 py-2">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                                          :class="e.entry_type === 'udhaar' ? 'bg-red-100 text-red-700' : (e.entry_type === 'wasooli' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700')"
                                          x-text="e.entry_type === 'udhaar' ? 'UDHAAR' : (e.entry_type === 'wasooli' ? 'WASOOLI' : 'RETURN')"></span>
                                    <span class="text-xs text-gray-500 ml-1" x-text="e.note || e.invoice_number || ''"></span>
                                </td>
                                <td class="px-3 py-2 text-right font-semibold" :class="e.amount > 0 ? 'text-red-600' : 'text-green-600'"
                                    x-text="(e.amount > 0 ? '+' : '') + e.amount.toLocaleString()"></td>
                                <td class="px-3 py-2 text-right text-gray-500" x-text="e.balance_after.toLocaleString()"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function khataPage() {
    return {
        filter: '',
        showWasooli: false,
        wasooliCustomerId: null,
        wasooliName: '',
        wasooliBalance: 0,
        showLedger: false,
        ledgerLoading: false,
        ledgerCustomer: {},
        ledgerEntries: [],
        openWasooli(id, name, balance) {
            this.wasooliCustomerId = id;
            this.wasooliName = name;
            this.wasooliBalance = balance;
            this.showWasooli = true;
            this.$nextTick(() => { try { this.$refs.wasooliAmount.focus(); } catch (e) {} });
        },
        async openLedger(id) {
            this.showLedger = true;
            this.ledgerLoading = true;
            this.ledgerCustomer = {};
            this.ledgerEntries = [];
            try {
                const res = await fetch(`{{ url('/fbr-pos/khata') }}/${id}/ledger`, { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (data.success) {
                    this.ledgerCustomer = data.customer;
                    this.ledgerEntries = data.entries;
                }
            } catch (e) {}
            this.ledgerLoading = false;
        },
    };
}
</script>
</x-fbr-pos-layout>
