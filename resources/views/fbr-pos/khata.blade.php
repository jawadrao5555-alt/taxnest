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

    {{-- (Khata upgrade Aug 2026) After a wasooli, offer to print the Wasooli ki
         rasid for the payment just recorded. --}}
    @if(session('wasooli_receipt_id'))
    <div class="mb-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 text-emerald-800 dark:text-emerald-300 rounded-lg px-4 py-3 text-sm flex items-center justify-between gap-3">
        <span>{{ session('success') }}</span>
        <a href="{{ route('fbrpos.khata.wasooli.receipt', session('wasooli_receipt_id')) }}" target="_blank"
           class="shrink-0 px-3 py-1.5 rounded-lg bg-emerald-600 text-white text-xs font-bold hover:bg-emerald-700">{{ __('pos.wasooli_print_rasid') }}</a>
    </div>
    @elseif(session('success'))
    <div class="mb-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 text-emerald-800 dark:text-emerald-300 rounded-lg px-4 py-3 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-800 dark:text-red-300 rounded-lg px-4 py-3 text-sm">{{ session('error') }}</div>
    @endif

    {{-- Stat tiles --}}
    <div class="grid grid-cols-2 gap-3 mb-4">
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

    {{-- (Khata upgrade Aug 2026) UMAR (aging) buckets — clickable filters.
         Each bucket = total outstanding whose OLDEST unpaid udhaar falls in that
         age band (FIFO allocation done server-side). Clicking toggles the row
         filter. --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-6">
        {{-- Static class strings (Tailwind JIT purges interpolated color names —
             tailwind.config.js has only a tiny teal safelist). --}}
        @php
            $ageTiles = [
                '0_15'   => ['label' => __('pos.khata_age_0_15'),   'border' => 'border-green-500',  'text' => 'text-green-600 dark:text-green-400',  'ring' => 'ring-green-500'],
                '16_30'  => ['label' => __('pos.khata_age_16_30'),  'border' => 'border-yellow-500', 'text' => 'text-yellow-600 dark:text-yellow-400', 'ring' => 'ring-yellow-500'],
                '31_60'  => ['label' => __('pos.khata_age_31_60'),  'border' => 'border-orange-500', 'text' => 'text-orange-600 dark:text-orange-400', 'ring' => 'ring-orange-500'],
                '60_plus'=> ['label' => __('pos.khata_age_60_plus'),'border' => 'border-red-500',    'text' => 'text-red-600 dark:text-red-400',      'ring' => 'ring-red-500'],
            ];
        @endphp
        @foreach($ageTiles as $key => $tile)
        <button type="button" @click="toggleBucket('{{ $key }}')"
                :class="bucketFilter === '{{ $key }}' ? 'ring-2 ring-offset-1 {{ $tile['ring'] }}' : ''"
                class="text-left bg-white dark:bg-gray-800 rounded-lg shadow p-3 border-l-4 {{ $tile['border'] }} hover:shadow-md transition">
            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">{{ $tile['label'] }}</p>
            <p class="text-lg font-extrabold {{ $tile['text'] }} mt-0.5">Rs {{ number_format($bucketTotals[$key] ?? 0, 0) }}</p>
        </button>
        @endforeach
    </div>

    {{-- (Khata upgrade Aug 2026) Bulk "Sab ko yaad dehani" panel — every customer
         with a balance, each with its own WhatsApp send button. wa.me can only
         open one chat at a time, so this is a manual tick-off list, NOT a fake
         bulk send. Rows without a routable phone show no dead button. --}}
    @php $waCustomers = $customers->where('khata_balance', '>', 0)->filter(fn($c) => $c->khata_wa_url); @endphp
    @if($waCustomers->isNotEmpty())
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden mb-6">
        <button type="button" @click="showBulk = !showBulk"
                class="w-full px-4 py-3 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between text-left">
            <span class="font-bold text-gray-900 dark:text-white">📣 {{ __('pos.khata_bulk_reminder_title') }}</span>
            <span class="text-xs text-gray-400" x-text="showBulk ? '▲' : '▼'"></span>
        </button>
        <div x-show="showBulk" x-cloak class="divide-y divide-gray-100 dark:divide-gray-700 max-h-80 overflow-y-auto">
            @foreach($waCustomers as $c)
            <div class="flex items-center justify-between gap-3 px-4 py-2.5"
                 :class="sentReminders.includes({{ $c->id }}) ? 'opacity-50' : ''">
                <div class="min-w-0">
                    <p class="font-semibold text-sm text-gray-900 dark:text-white truncate">{{ $c->name }}</p>
                    <p class="text-xs text-red-600 dark:text-red-400">Rs {{ number_format($c->khata_balance, 0) }}</p>
                </div>
                <button type="button" @click="sendReminder({{ $c->id }}, '{{ addslashes($c->khata_wa_url) }}')"
                        class="shrink-0 px-3 py-1.5 rounded-lg bg-green-600 text-white text-xs font-bold hover:bg-green-700 flex items-center gap-1">
                    <span x-show="sentReminders.includes({{ $c->id }})">✓</span>
                    <span x-text="sentReminders.includes({{ $c->id }}) ? '{{ __('pos.khata_reminder_sent_word') }}' : '{{ __('pos.khata_reminder_send_word') }}'"></span>
                </button>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Customer list --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h3 class="font-bold text-gray-900 dark:text-white">Khata Customers</h3>
            <div class="flex items-center gap-2">
                <button type="button" x-show="bucketFilter" @click="bucketFilter = ''" x-cloak
                        class="text-xs text-blue-600 hover:underline">{{ __('pos.khata_clear_filter') }}</button>
                <input type="text" x-model="filter" placeholder="Naam / phone search..." autocomplete="off" name="khata_search_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="border rounded-lg px-3 py-1.5 text-sm w-48 dark:bg-gray-700 dark:text-white dark:border-gray-600">
            </div>
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
                    <th class="px-4 py-2">{{ __('pos.khata_age_col') }}</th>
                    <th class="px-4 py-2 text-right">Balance</th>
                    <th class="px-4 py-2 text-right">Amal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($customers as $c)
                @php
                    // Static class strings — Tailwind JIT can't see interpolated names.
                    $badgeClass = match($c->khata_bucket) {
                        '0_15' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
                        '16_30' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300',
                        '31_60' => 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300',
                        '60_plus' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
                        default => 'bg-gray-100 text-gray-700',
                    };
                @endphp
                <tr class="border-t dark:border-gray-700"
                    x-show="(filter === '' || '{{ strtolower(addslashes($c->name . ' ' . $c->phone)) }}'.includes(filter.toLowerCase())) && (bucketFilter === '' || bucketFilter === '{{ $c->khata_bucket }}')">
                    <td class="px-4 py-2.5 font-semibold text-gray-900 dark:text-white">{{ $c->name }}</td>
                    <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">{{ $c->phone ?: '—' }}</td>
                    <td class="px-4 py-2.5">
                        @if($c->khata_oldest_days !== null)
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $badgeClass }}">{{ __('pos.khata_days_old', ['n' => $c->khata_oldest_days]) }}</span>
                        @else
                        <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-right font-bold {{ $c->khata_balance > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                        Rs {{ number_format($c->khata_balance, 0) }}
                    </td>
                    <td class="px-4 py-2.5 text-right whitespace-nowrap">
                        {{-- (Khata upgrade Aug 2026) WhatsApp reminder — only when the
                             phone is routable ($c->khata_wa_url non-null). No dead button. --}}
                        @if($c->khata_balance > 0 && $c->khata_wa_url)
                        <button @click="sendReminder({{ $c->id }}, '{{ addslashes($c->khata_wa_url) }}')"
                                title="{{ $c->khata_last_reminder_days !== null ? __('pos.khata_last_reminder', ['n' => $c->khata_last_reminder_days]) : '' }}"
                                class="px-3 py-1.5 rounded-lg bg-green-600 text-white text-xs font-bold hover:bg-green-700">WA</button>
                        @endif
                        <button @click="openWasooli({{ $c->id }}, '{{ addslashes($c->name) }}', {{ (float) $c->khata_balance }})"
                                class="px-3 py-1.5 rounded-lg bg-green-600 text-white text-xs font-bold hover:bg-green-700">Wasooli</button>
                        <button @click="openLedger({{ $c->id }})"
                                class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-xs font-bold hover:bg-gray-200 dark:hover:bg-gray-600">Ledger</button>
                        @if($c->khata_last_reminder_days !== null)
                        <div class="text-[10px] text-gray-400 mt-0.5">{{ __('pos.khata_last_reminder', ['n' => $c->khata_last_reminder_days]) }}</div>
                        @endif
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
        bucketFilter: '',
        showBulk: false,
        // (Khata upgrade Aug 2026) ids ticked off after a reminder was opened.
        sentReminders: [],
        showWasooli: false,
        wasooliCustomerId: null,
        wasooliName: '',
        wasooliBalance: 0,
        showLedger: false,
        ledgerLoading: false,
        ledgerCustomer: {},
        ledgerEntries: [],
        toggleBucket(b) {
            this.bucketFilter = this.bucketFilter === b ? '' : b;
        },
        // Opens the customer's WhatsApp chat (wa.me can only open ONE at a time),
        // ticks it off, and records the send server-side so "aakhri yaad dehani"
        // updates — nobody gets pestered twice a day.
        sendReminder(id, url) {
            window.open(url, '_blank');
            if (!this.sentReminders.includes(id)) this.sentReminders.push(id);
            try {
                fetch(`{{ url('/fbr-pos/khata') }}/${id}/reminder-sent`, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                });
            } catch (e) {}
        },
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
