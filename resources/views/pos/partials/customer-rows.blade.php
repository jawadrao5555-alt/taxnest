{{-- Customer rows — shared by the full page render AND the live-search
     refresh (Aug 2026). The search box now queries the SERVER as the shop
     types, and this partial is what comes back, so every row feature (inline
     edit, saved addresses, chips) must live HERE and nowhere else — a row
     built only in customers.blade.php would vanish the moment someone types. --}}
                    @forelse($customers as $customer)
                    {{-- One <tbody> per customer (valid HTML, allowed many times
                         per table): the inline edit row below is a SIBLING of the
                         main row, so an x-data on the row itself never covered it
                         — Edit silently did nothing and Alpine logged "editing is
                         not defined". The scope must sit on a shared parent. --}}
                    <tbody class="cust-tb divide-y divide-gray-100 dark:divide-gray-800 border-t border-gray-100 dark:border-gray-800" x-data="custRow({{ (int) $customer->id }})"
                        data-search="{{ Str::lower(trim(($customer->name ?? '') . ' ' . ($customer->phone ?? '') . ' ' . ($customer->email ?? '') . ' ' . ($customer->address ?? '') . ' ' . ($customer->city ?? '') . ' ' . ($customer->cnic ?? '') . ' ' . ($customer->ntn ?? ''))) }}">
                    <tr class="cust-row {{ $loop->even ? 'bg-gray-50/50 dark:bg-gray-800/20' : '' }} {{ !$customer->is_active ? 'opacity-50' : '' }}">
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
                                    <button type="button" @click="saveAddress()" :disabled="addrSaving || !newAddrText.trim()" class="text-xs font-bold text-white bg-purple-600 hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed px-2.5 py-1.5 rounded-md shrink-0" x-text="addrSaving ? {{ Js::from(__('pos.saving_dots')) }} : {{ Js::from(__('pos.save_btn')) }}"></button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    @endif
                    </tbody>
                    @empty
                    <tbody class="cust-tb-empty">
                    <tr><td colspan="7" class="px-4 py-12 text-center text-gray-500">{{ __('pos.no_customers_yet') }}</td></tr>
                    </tbody>
                    @endforelse
