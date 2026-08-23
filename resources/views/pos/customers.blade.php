<x-pos-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    @include('pos.partials.back-link')
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <h1 class="text-xl font-bold text-gray-900 dark:text-white">{{ __('pos.pos_customers') }}</h1>
        <div class="flex flex-wrap items-center gap-2">
            @if(!($isCashier ?? false))
            <a href="{{ route('pos.customers.export') }}" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded-lg text-sm font-semibold shadow-md transition">{{ __('pos.export_csv') }}</a>
            <button onclick="document.getElementById('importCustomerForm').classList.toggle('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg text-sm font-semibold shadow-md transition">{{ __('pos.import_csv') }}</button>
            @endif
            <button onclick="document.getElementById('addCustomerForm').classList.toggle('hidden')" class="bg-gradient-to-r from-purple-500 to-purple-700 text-white px-4 py-2 rounded-lg text-sm font-semibold shadow-md hover:shadow-lg transition">{{ __('pos.add_customer_btn') }}</button>
        </div>
    </div>

    @if(session('success'))
    <div class="mb-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 text-emerald-800 dark:text-emerald-300 rounded-lg px-4 py-3 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-800 dark:text-red-300 rounded-lg px-4 py-3 text-sm">{{ session('error') }}</div>
    @endif
    @if($errors->any())
    <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-800 dark:text-red-300 rounded-lg px-4 py-3 text-sm">{{ $errors->first() }}</div>
    @endif
    @if(session('import_errors') && count(session('import_errors')))
    <div class="mb-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 text-amber-800 dark:text-amber-300 rounded-lg px-4 py-3 text-xs">
        <p class="font-semibold mb-1">{{ __('pos.some_rows_skipped') }}</p>
        <ul class="list-disc list-inside space-y-0.5">
            @foreach(session('import_errors') as $err)
            <li>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    @if(!($isCashier ?? false))
    <div id="importCustomerForm" class="hidden mb-6 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-md p-5">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.import_customers_csv') }}</h3>
        <p class="text-xs text-gray-500 mb-4">{{ __('pos.customer_import_columns_hint') }}</p>
        <form method="POST" action="{{ route('pos.customers.import') }}" enctype="multipart/form-data" class="flex flex-col sm:flex-row sm:items-center gap-3">
            @csrf
            <input type="file" name="file" accept=".csv,text/csv" required class="text-sm text-gray-700 dark:text-gray-300 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-purple-100 file:text-purple-700 hover:file:bg-purple-200">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg text-sm font-semibold shadow-md transition">{{ __('pos.upload_and_import') }}</button>
            <a href="{{ route('pos.customers.template') }}" class="text-xs text-purple-600 hover:text-purple-700 underline">{{ __('pos.download_template') }}</a>
        </form>
    </div>
    @endif

    <div id="addCustomerForm" class="hidden mb-6 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-md p-5">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.add_new_customer_title') }}</h3>
        <form method="POST" action="{{ route('pos.customers.store') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            @csrf
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.customer_name_label') }}</label>
                <input type="text" name="name" placeholder="{{ __('pos.ph_full_name_optional') }}" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.phone_label') }}</label>
                <input type="text" name="phone" placeholder="03XX-XXXXXXX" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.email_label') }}</label>
                <input type="email" name="email" placeholder="email@example.com" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.type_label') }} *</label>
                <select name="type" required class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
                    <option value="unregistered">{{ __('pos.unregistered') }}</option>
                    <option value="registered">{{ __('pos.registered') }}</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.cnic_label') }}</label>
                <input type="text" name="cnic" placeholder="XXXXX-XXXXXXX-X" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.ntn_label') }}</label>
                <input type="text" name="ntn" placeholder="{{ __('pos.ph_ntn_number') }}" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.city_label') }}</label>
                <input type="text" name="city" placeholder="{{ __('pos.city_label') }}" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full bg-gradient-to-r from-purple-500 to-purple-700 text-white px-5 py-2 rounded-lg text-sm font-semibold shadow-md hover:shadow-lg transition">{{ __('pos.save_customer') }}</button>
            </div>
            <div class="sm:col-span-2 lg:col-span-4">
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.address_label') }}</label>
                <input type="text" name="address" placeholder="{{ __('pos.ph_full_address') }}" class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500">
            </div>
        </form>
    </div>

    {{-- Customer search — SERVER-SIDE (Aug 2026, ZFC 10k+ customers): the page is
         paginated now, so the search must hit the DB (kisi bhi page se milta hai).
         The old client-side row filter stays as an instant filter WITHIN the page. --}}
    <div class="mb-3">
        <form method="GET" action="{{ route('pos.customers') }}" class="flex items-center gap-2 max-w-xl">
            <div class="relative flex-1">
                <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                <input type="text" id="custSearchInput" name="q" value="{{ $q ?? '' }}" placeholder="{{ __('pos.search_customer_placeholder') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white pl-9 pr-3 py-2 focus:ring-2 focus:ring-purple-500">
            </div>
            {{-- The Search button stays: without JS (or mid-load) the form still
                 works exactly as before — the live search is an enhancement. --}}
            <button type="submit" class="px-4 py-2 text-sm font-bold text-white bg-purple-600 hover:bg-purple-700 rounded-lg">{{ __('pos.search_btn') }}</button>
            <a id="custClearBtn" href="{{ route('pos.customers') }}" class="px-3 py-2 text-sm font-bold text-gray-600 dark:text-gray-300 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 {{ ($q ?? '') !== '' ? '' : 'hidden' }}">{{ __('pos.clear_btn') }}</a>
        </form>
        <p id="custCountLine" class="text-xs text-gray-400 mt-1">
            @if(($q ?? '') !== '')
                {{ __('pos.customers_found_of_total', ['found' => $customers->total(), 'total' => $totalCount ?? $customers->total()]) }}
            @else
                {{ __('pos.customers_total_line', ['total' => $totalCount ?? $customers->total()]) }}
            @endif
        </p>
    </div>

    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table id="custTable" class="w-full text-sm table-cards">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                        <th class="px-4 py-3">{{ __('pos.customer_word') }}</th>
                        <th class="px-4 py-3 hidden sm:table-cell">{{ __('pos.phone_label') }}</th>
                        <th class="px-4 py-3 hidden lg:table-cell">{{ __('pos.email_label') }}</th>
                        {{-- Owner (21 Aug 2026): naam aur phone to the, pata nahi tha.
                             City ka column ab PATA dikhata hai, sheher uske neeche
                             chhoti line mein — column ginti wahi rehti hai. --}}
                        <th class="px-4 py-3 hidden md:table-cell">{{ __('pos.address_label') }}</th>
                        <th class="px-4 py-3 text-center hidden sm:table-cell">{{ __('pos.type_label') }}</th>
                        <th class="px-4 py-3 text-center hidden sm:table-cell">{{ __('pos.status_col') }}</th>
                        @if(!($isCashier ?? false))
                        <th class="px-4 py-3 text-center">{{ __('pos.actions_col') }}</th>
                        @endif
                    </tr>
                </thead>
                {{-- The partial emits one <tbody> per customer (Alpine scope) — the
                     live search swaps every tbody in this table, thead stays. --}}
                @include('pos.partials.customer-rows', ['customers' => $customers, 'isCashier' => $isCashier ?? false, 'inactiveMap' => $inactiveMap ?? []])
            </table>
        </div>
    </div>

    <div class="mt-4 text-xs text-gray-400 text-center">
        {{ __('pos.customers_exclusive_note') }}
    </div>

    <script>
    // Per-row Alpine component: inline edit toggle + saved delivery addresses
    // (pos_customer_addresses) — same company-scoped endpoints as the sale screen.
    function custRow(customerId) {
        return {
            editing: false,
            addresses: [],
            addrLoading: false,
            addrLoaded: false,
            addrError: '',
            newAddrLabel: '',
            newAddrText: '',
            addrSaving: false,
            init() {
                this.$watch('editing', (open) => {
                    if (open && !this.addrLoaded) this.loadAddresses();
                });
            },
            async loadAddresses() {
                this.addrLoading = true; this.addrError = '';
                try {
                    const res = await fetch('/pos/api/customer-addresses?customer_id=' + customerId, { headers: { 'Accept': 'application/json' } });
                    const data = await res.json();
                    this.addresses = Array.isArray(data.addresses) ? data.addresses : [];
                    this.addrLoaded = true;
                } catch (e) {
                    this.addrError = @json(__('pos.network_error'));
                }
                this.addrLoading = false;
            },
            // Task 103: add a new address from this page too. Server enforces the
            // duplicate + 15-address guards; surface its message on 422.
            async saveAddress() {
                const text = this.newAddrText.trim();
                if (!text || this.addrSaving) return;
                this.addrSaving = true; this.addrError = '';
                try {
                    const res = await fetch('/pos/api/customer-addresses', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                        body: JSON.stringify({ customer_id: customerId, address: text, label: this.newAddrLabel.trim() || null }),
                    });
                    const data = await res.json().catch(() => null);
                    if (data && data.success && data.address) {
                        this.addresses.push(data.address);
                        this.newAddrLabel = ''; this.newAddrText = '';
                    } else {
                        this.addrError = (data && data.message) || @json(__('pos.could_not_save_address'));
                    }
                } catch (e) {
                    this.addrError = @json(__('pos.network_error'));
                }
                this.addrSaving = false;
            },
            async deleteAddress(a) {
                if (!confirm(@json(__('pos.confirm_delete_address')) + '\n' + (a.label ? a.label + ': ' : '') + a.address)) return;
                this.addrError = '';
                try {
                    const res = await fetch('/pos/api/customer-addresses/delete', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                        body: JSON.stringify({ customer_id: customerId, id: a.id }),
                    });
                    const data = await res.json().catch(() => null);
                    if (data && data.success) {
                        this.addresses = this.addresses.filter(x => x.id !== a.id);
                    } else {
                        this.addrError = (data && data.message) || @json(__('pos.failed_word'));
                    }
                } catch (e) {
                    this.addrError = @json(__('pos.network_error'));
                }
            },
        };
    }
    // LIVE customer search (owner request, 23 Aug 2026).
    //
    // Before: typing only filtered the 100 rows already on screen, so a shop
    // with 11k customers searched a phone number, saw "0 customers found" and
    // had to press Enter for the real (server) search. Now every keystroke
    // asks the server after a short pause and swaps in the real result rows.
    //
    // The instant within-page filter is kept as the FIRST reaction (zero
    // latency) — the server answer then replaces the rows outright.
    (function () {
        var input = document.getElementById('custSearchInput');
        var table = document.getElementById('custTable');
        var countLine = document.getElementById('custCountLine');
        var pager = document.getElementById('custPager');
        var clearBtn = document.getElementById('custClearBtn');
        if (!input || !table) return;

        var foundTpl = @json(__('pos.customers_found_of_total', ['found' => '__F__', 'total' => '__T__']));
        var totalTpl = @json(__('pos.customers_total_line', ['total' => '__T__']));
        var searchingTxt = @json(__('pos.searching_dots'));
        var failedTxt = @json(__('pos.network_error'));
        var baseUrl = @json(route('pos.customers', [], false));

        var timer = null, controller = null, lastSent = null;

        // Instant feedback while the server answer is on its way. Hiding the
        // whole <tbody> takes the inline edit row with it automatically.
        function instantFilter(q) {
            var needle = q.toLowerCase();
            table.querySelectorAll('tbody.cust-tb').forEach(function (tb) {
                var hit = !needle || (tb.getAttribute('data-search') || '').indexOf(needle) !== -1;
                tb.style.display = hit ? '' : 'none';
            });
        }

        function swapRows(html) {
            table.querySelectorAll('tbody').forEach(function (tb) { tb.remove(); });
            table.insertAdjacentHTML('beforeend', html);
        }

        function runSearch(q) {
            if (q === lastSent) return;
            lastSent = q;
            if (controller) controller.abort();
            controller = new AbortController();
            countLine.textContent = searchingTxt;
            var url = baseUrl + '?rows=1' + (q ? '&q=' + encodeURIComponent(q) : '');
            fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
                .then(function (data) {
                    if (!data || !data.success) throw new Error('bad payload');
                    // Alpine initialises the injected tbodies itself (it watches
                    // the DOM), so custRow() state comes back with them.
                    swapRows(data.html);
                    if (pager) pager.innerHTML = data.pagination || '';
                    countLine.textContent = q
                        ? foundTpl.replace('__F__', data.found).replace('__T__', data.total)
                        : totalTpl.replace('__T__', data.total);
                    if (clearBtn) clearBtn.classList.toggle('hidden', !q);
                    // Keep the address bar honest: a refresh repeats the search.
                    try {
                        window.history.replaceState({}, '', q ? baseUrl + '?q=' + encodeURIComponent(q) : baseUrl);
                    } catch (e) { /* older browsers: not worth failing over */ }
                })
                .catch(function (e) {
                    if (e && e.name === 'AbortError') return;
                    lastSent = null;
                    countLine.textContent = failedTxt;
                });
        }

        input.addEventListener('input', function () {
            var q = input.value.trim();
            instantFilter(q);
            clearTimeout(timer);
            timer = setTimeout(function () { runSearch(q); }, 350);
        });

        // Enter must not reload the page when the live search already answers.
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(timer);
                runSearch(input.value.trim());
            }
        });
    })();
    </script>

    {{-- Container always rendered: the live search swaps its contents. --}}
    <div id="custPager" class="mt-4 px-1">@if($customers->hasPages()){{ $customers->links() }}@endif</div>
</div>
</x-pos-layout>
