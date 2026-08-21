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
            <button type="submit" class="px-4 py-2 text-sm font-bold text-white bg-purple-600 hover:bg-purple-700 rounded-lg">{{ __('pos.search_btn') }}</button>
            @if(($q ?? '') !== '')
            <a href="{{ route('pos.customers') }}" class="px-3 py-2 text-sm font-bold text-gray-600 dark:text-gray-300 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800">{{ __('pos.clear_btn') }}</a>
            @endif
        </form>
        <p class="text-xs text-gray-400 mt-1">
            @if(($q ?? '') !== '')
                {{ __('pos.customers_found_of_total', ['found' => $customers->total(), 'total' => $totalCount ?? $customers->total()]) }}
            @else
                {{ __('pos.customers_total_line', ['total' => $totalCount ?? $customers->total()]) }}
            @endif
        </p>
        <p id="custSearchCount" class="text-xs text-gray-400 mt-1 hidden"></p>
    </div>

    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm table-cards">
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
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($customers as $customer)
                    <tr class="cust-row {{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }} {{ !$customer->is_active ? 'opacity-50' : '' }}" x-data="custRow({{ (int) $customer->id }})"
                        data-search="{{ Str::lower(trim(($customer->name ?? '') . ' ' . ($customer->phone ?? '') . ' ' . ($customer->email ?? '') . ' ' . ($customer->address ?? '') . ' ' . ($customer->city ?? '') . ' ' . ($customer->cnic ?? '') . ' ' . ($customer->ntn ?? ''))) }}">
                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">
                            {{ $customer->name }}
                            {{-- Task 1161: khamosh-repeat chip — same PosRepeatCustomerAlert service as the dashboard card. --}}
                            @php $icRow = ($inactiveMap ?? [])[$customer->id] ?? null; @endphp
                            @if($icRow)
                            <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300 whitespace-nowrap align-middle" title="{{ __('pos.inactive_orders_count', ['count' => $icRow['orders']]) }} · {{ __('pos.inactive_last_order_days', ['days' => $icRow['days']]) }}">{{ __('pos.inactive_chip', ['days' => $icRow['days']]) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-500 hidden sm:table-cell">{{ $customer->phone ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-500 text-xs hidden lg:table-cell">{{ $customer->email ?? '—' }}</td>
                        @php
                            $addrTxt = trim((string) ($customer->address ?? ''));
                            $cityTxt = trim((string) ($customer->city ?? ''));
                        @endphp
                        <td class="px-4 py-3 text-gray-500 hidden md:table-cell">
                            @if($addrTxt !== '')
                                {{-- inline max-width: arbitrary Tailwind classes need a fresh build --}}
                                <div class="truncate" style="max-width:240px" title="{{ $addrTxt }}">{{ $addrTxt }}</div>
                            @endif
                            @if($cityTxt !== '')
                                <div class="text-xs text-gray-400">{{ $cityTxt }}</div>
                            @endif
                            @if($addrTxt === '' && $cityTxt === ''){{ '—' }}@endif
                        </td>
                        <td class="px-4 py-3 text-center hidden sm:table-cell">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $customer->type === 'registered' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' }}">{{ $customer->type === 'registered' ? __('pos.registered') : __('pos.unregistered') }}</span>
                        </td>
                        <td class="px-4 py-3 text-center hidden sm:table-cell">
                            @if(!($isCashier ?? false))
                            <form method="POST" action="{{ route('pos.customers.toggle', $customer->id) }}" class="inline">
                                @csrf
                                <button type="submit" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $customer->is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' }}">
                                    {{ $customer->is_active ? __('pos.active_word') : __('pos.inactive_word') }}
                                </button>
                            </form>
                            @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $customer->is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' }}">
                                {{ $customer->is_active ? __('pos.active_word') : __('pos.inactive_word') }}
                            </span>
                            @endif
                        </td>
                        @if(!($isCashier ?? false))
                        <td class="px-4 py-3 text-center">
                            <div class="flex items-center justify-center gap-1">
                                <a href="{{ route('pos.customers.history', $customer->id) }}" class="text-xs text-emerald-600 hover:text-emerald-700 px-2 py-1">{{ __('pos.history_word') }}</a>
                                <button @click="editing = !editing" class="text-xs text-purple-600 hover:text-purple-700 px-2 py-1">{{ __('pos.edit') }}</button>
                                <form method="POST" action="{{ route('pos.customers.delete', $customer->id) }}" onsubmit="return confirm({{ Js::from(__('pos.confirm_delete_customer')) }})" class="inline">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-xs text-red-500 hover:text-red-600 px-2 py-1">{{ __('pos.delete') }}</button>
                                </form>
                            </div>
                        </td>
                        @endif
                    </tr>
                    @if(!($isCashier ?? false))
                    <tr x-show="editing" class="bg-purple-50/50 dark:bg-purple-900/10">
                        <td colspan="7" class="px-4 py-3">
                            <form method="POST" action="{{ route('pos.customers.update', $customer->id) }}" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2 items-end">
                                @csrf @method('PUT')
                                <input type="text" name="name" value="{{ $customer->name }}" required placeholder="{{ __('pos.name_label') }}" class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-2 py-1.5 w-full">
                                <input type="text" name="phone" value="{{ $customer->phone }}" placeholder="{{ __('pos.phone_label') }}" class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-2 py-1.5 w-full">
                                <input type="email" name="email" value="{{ $customer->email }}" placeholder="{{ __('pos.email_label') }}" class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-2 py-1.5 w-full">
                                <input type="text" name="city" value="{{ $customer->city }}" placeholder="{{ __('pos.city_label') }}" class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-2 py-1.5 w-full">
                                <input type="text" name="cnic" value="{{ $customer->cnic }}" placeholder="{{ __('pos.cnic_label') }}" class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-2 py-1.5 w-full">
                                <input type="text" name="ntn" value="{{ $customer->ntn }}" placeholder="{{ __('pos.ntn_label') }}" class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-2 py-1.5 w-full">
                                <select name="type" required class="text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-2 py-1.5 w-full">
                                    <option value="unregistered" {{ $customer->type === 'unregistered' ? 'selected' : '' }}>{{ __('pos.unregistered') }}</option>
                                    <option value="registered" {{ $customer->type === 'registered' ? 'selected' : '' }}>{{ __('pos.registered') }}</option>
                                </select>
                                <div class="flex gap-2 col-span-2 sm:col-span-1">
                                    <button type="submit" class="text-xs font-semibold text-white px-3 py-1.5 rounded-lg bg-purple-600 hover:bg-purple-700 transition">{{ __('pos.save_btn') }}</button>
                                    <button type="button" @click="editing = false" class="text-xs text-gray-500 px-3 py-1.5">{{ __('pos.cancel') }}</button>
                                </div>
                            </form>
                            {{-- Task: saved delivery addresses (pos_customer_addresses) — view/delete
                                 from the Customers page too, via the same company-scoped endpoints
                                 the sale screen uses. Loaded lazily when the edit row opens. --}}
                            <div class="mt-3 pt-3 border-t border-purple-100 dark:border-purple-900/30">
                                <p class="text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1.5">{{ __('pos.saved_delivery_addresses') }}</p>
                                <template x-if="addrLoading"><p class="text-xs text-gray-400">...</p></template>
                                <template x-if="!addrLoading && addresses.length === 0"><p class="text-xs text-gray-400">{{ __('pos.no_saved_addresses') }}</p></template>
                                <ul class="space-y-1">
                                    <template x-for="a in addresses" :key="a.id">
                                        <li class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-300">
                                            <span x-show="a.id === 0" class="inline-flex px-1.5 py-0.5 rounded bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300 font-medium shrink-0">{{ __('pos.default_addr_label') }}</span>
                                            <span x-show="a.id !== 0 && a.label" class="inline-flex px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400 font-medium shrink-0" x-text="a.label"></span>
                                            <span x-text="a.address" class="truncate"></span>
                                            <button type="button" @click="deleteAddress(a)" title="{{ __('pos.ti_delete_address') }}" class="text-red-500 hover:text-red-600 font-bold px-1.5 shrink-0">&times;</button>
                                        </li>
                                    </template>
                                </ul>
                                <p x-show="addrError" x-text="addrError" class="text-xs text-red-500 mt-1"></p>
                                {{-- Task 103: add a NEW delivery address right from the Customers page
                                     (POST /pos/api/customer-addresses — duplicate + 15-limit guards server-side). --}}
                                <div class="flex items-center gap-1.5 mt-2">
                                    <input type="text" x-model="newAddrLabel" maxlength="50" placeholder="{{ __('pos.ph_addr_label') }}" autocomplete="off" name="pos_cust_addr_label_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore class="w-28 shrink-0 text-xs rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-white py-1.5 px-2 focus:ring-purple-500 focus:border-purple-400">
                                    <input type="text" x-model="newAddrText" maxlength="500" @keydown.enter.prevent="saveAddress()" placeholder="{{ __('pos.ph_full_delivery_address') }}" autocomplete="off" name="pos_cust_addr_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore class="flex-1 min-w-0 text-xs rounded-md border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-white py-1.5 px-2 focus:ring-purple-500 focus:border-purple-400">
                                    <button type="button" @click="saveAddress()" :disabled="addrSaving || !newAddrText.trim()" class="text-xs font-bold text-white bg-purple-600 hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed px-2.5 py-1.5 rounded-md shrink-0" x-text="addrSaving ? @json(__('pos.saving_dots')) : @json(__('pos.save_btn'))"></button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    @endif
                    @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-gray-500">{{ __('pos.no_customers_yet') }}</td></tr>
                    @endforelse
                </tbody>
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
    // Customer search (owner request, 1 Aug 2026): client-side filter over the
    // rendered rows — matches name/phone/email/city/CNIC/NTN via data-search.
    (function () {
        var input = document.getElementById('custSearchInput');
        var count = document.getElementById('custSearchCount');
        if (!input) return;
        var label = @json(__('pos.search_customers_found'));
        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase();
            var rows = document.querySelectorAll('tr.cust-row');
            var shown = 0;
            rows.forEach(function (row) {
                var hit = !q || (row.getAttribute('data-search') || '').indexOf(q) !== -1;
                row.style.display = hit ? '' : 'none';
                if (hit) shown++;
                // Keep the inline edit row in sync with its parent row.
                // Only force-HIDE on non-match; on match, remove our own inline
                // display so Alpine's x-show keeps controlling it (never clear
                // Alpine's display:none, or collapsed edit rows would pop open).
                var next = row.nextElementSibling;
                if (next && !next.classList.contains('cust-row')) {
                    if (hit) {
                        if (next.dataset.searchHidden === '1') {
                            next.style.display = ('prevDisplay' in next.dataset) ? next.dataset.prevDisplay : 'none';
                            delete next.dataset.searchHidden; delete next.dataset.prevDisplay;
                        }
                    } else if (next.dataset.searchHidden !== '1') {
                        next.dataset.prevDisplay = next.style.display;
                        next.style.display = 'none'; next.dataset.searchHidden = '1';
                    }
                }
            });
            if (q) { count.textContent = shown + ' ' + label; count.classList.remove('hidden'); }
            else { count.classList.add('hidden'); }
        });
    })();
    </script>

    @if($customers->hasPages())
    <div class="mt-4 px-1">{{ $customers->links() }}</div>
    @endif
</div>
</x-pos-layout>
