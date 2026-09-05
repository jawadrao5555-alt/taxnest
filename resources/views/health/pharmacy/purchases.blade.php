{{--
    Medicine purchasing.

    Receiving is a single act: the form saves and the goods are on the shelf,
    because that is what happened at the delivery door. Each LINE becomes its
    own lot — batch number, expiry, cost, sale price — which is what makes a
    recall or an expiry sweep possible later.
--}}
<x-health-layout>
    @php
        $medicineList = $medicines->map(fn ($m) => [
            'id' => (int) $m->id,
            'label' => mb_convert_encoding(trim($m->name . ' ' . ($m->strength ?? '')), 'UTF-8', 'UTF-8'),
            'cost' => (float) $m->purchase_price,
            'price' => (float) $m->sale_price,
        ])->values();
    @endphp

    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="pharmacyPurchase({{ \Illuminate\Support\Js::from($medicineList) }})">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.ph_purchases_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.ph_purchases_subtitle') }}</p>
            </div>
            @if($canManage)
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" @click="receiving = !receiving"
                            class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                        {{ __('health.ph_receive_stock') }}
                    </button>
                    <button type="button" @click="supplierForm = !supplierForm"
                            class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        {{ __('health.ph_supplier_add') }}
                    </button>
                </div>
            @endif
        </div>

        {{-- ── New supplier ── --}}
        @if($canManage)
            <div x-show="supplierForm" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.pharmacy.suppliers.store') }}" class="space-y-4">
                    @csrf
                    <h2 class="text-base font-black">{{ __('health.ph_supplier_add') }}</h2>
                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        @foreach([
                            ['name', 'health.ph_sup_name', true],
                            ['phone', 'health.ph_sup_phone', false],
                            ['contact_person', 'health.ph_sup_contact', false],
                            ['ntn', 'health.ph_sup_ntn', false],
                            ['email', 'health.ph_sup_email', false],
                            ['city', 'health.ph_sup_city', false],
                            ['address', 'health.ph_sup_address', false],
                            ['notes', 'health.ph_f_notes', false],
                        ] as [$field, $label, $required])
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __($label) }}</label>
                                <input type="text" name="{{ $field }}" @if($required) required @endif
                                       class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            </div>
                        @endforeach
                    </div>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                        {{ __('health.ph_save') }}
                    </button>
                </form>
            </div>
        @endif

        {{-- ── Receive delivery ── --}}
        @if($canManage)
            <div x-show="receiving" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.pharmacy.purchases.store') }}" class="space-y-4">
                    @csrf
                    <h2 class="text-base font-black">{{ __('health.ph_receive_stock') }}</h2>

                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_sup_name') }}</label>
                            <select name="supplier_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                <option value="">{{ __('health.ph_no_supplier') }}</option>
                                @foreach($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if($isMultiBranch)
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_branch') }}</label>
                                <select name="branch_id" required class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                    <option value="">{{ __('health.ph_pick_branch_option') }}</option>
                                    @foreach($branches as $branch)
                                        <option value="{{ $branch->id }}" @selected($viewBranchId === (int) $branch->id)>{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_invoice_ref') }}</label>
                            <input type="text" name="invoice_reference" maxlength="64"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_purchase_date') }}</label>
                            <input type="date" name="order_date" value="{{ now()->toDateString() }}"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                    </div>

                    {{-- Lines ── each becomes a lot --}}
                    <div class="space-y-2">
                        <template x-for="(line, index) in lines" :key="line.key">
                            <div class="grid sm:grid-cols-2 lg:grid-cols-7 gap-2 items-end p-3 rounded-xl border border-gray-200 dark:border-gray-700">
                                <div class="lg:col-span-2">
                                    <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_medicine') }}</label>
                                    <select :name="'items[' + index + '][medicine_id]'" x-model.number="line.medicine_id"
                                            @change="applyDefaults(index)" required
                                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                        <option value="">—</option>
                                        <template x-for="m in medicines" :key="m.id">
                                            <option :value="m.id" x-text="m.label"></option>
                                        </template>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_batch_no') }}</label>
                                    <input type="text" :name="'items[' + index + '][batch_no]'" x-model="line.batch_no" maxlength="64"
                                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_expiry') }}</label>
                                    <input type="date" :name="'items[' + index + '][expiry_date]'" x-model="line.expiry_date"
                                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_qty') }}</label>
                                    <input type="number" step="0.001" min="0.001" required
                                           :name="'items[' + index + '][quantity]'" x-model.number="line.quantity"
                                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_cost') }}</label>
                                    <input type="number" step="0.01" min="0" required
                                           :name="'items[' + index + '][cost_price]'" x-model.number="line.cost_price"
                                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                </div>
                                <div class="flex items-end gap-2">
                                    <div class="flex-1">
                                        <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_f_sale_price') }}</label>
                                        <input type="number" step="0.01" min="0"
                                               :name="'items[' + index + '][sale_price]'" x-model.number="line.sale_price"
                                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                    </div>
                                    <button type="button" @click="removeLine(index)" x-show="lines.length > 1"
                                            class="px-2.5 py-2 rounded-lg border border-red-200 dark:border-red-800 text-red-600 dark:text-red-300 text-xs font-bold">✕</button>
                                </div>
                            </div>
                        </template>

                        <button type="button" @click="addLine()"
                                class="px-4 py-2 rounded-xl border border-dashed border-gray-300 dark:border-gray-600 text-xs font-bold text-gray-600 dark:text-gray-300 hover:border-teal-400 transition">
                            + {{ __('health.ph_add_line') }}
                        </button>
                    </div>

                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_paid_now') }}</label>
                            <input type="number" step="0.01" min="0" name="paid_amount"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_pay_method') }}</label>
                            <select name="payment_method" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                @foreach(\App\Models\HealthSupplierPayment::METHODS as $method)
                                    <option value="{{ $method }}">{{ __('health.pay_' . $method) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="lg:col-span-2 text-end">
                            <p class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.ph_purchase_total') }}</p>
                            <p class="text-2xl font-black text-teal-700 dark:text-teal-300" x-text="total().toFixed(2)"></p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                            {{ __('health.ph_receive_save') }}
                        </button>
                        <button type="button" @click="receiving = false" class="px-4 py-2.5 rounded-xl text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">
                            {{ __('health.cancel') }}
                        </button>
                    </div>
                </form>
            </div>
        @endif

        {{-- ── Supplier balances ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-sm font-black">{{ __('health.ph_supplier_balances') }}</h2>
            </div>
            @if($balances->isEmpty())
                <p class="px-4 py-6 text-center text-xs text-gray-500 dark:text-gray-400">{{ __('health.ph_none') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/40 text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-2 text-start font-black">{{ __('health.ph_sup_name') }}</th>
                                <th class="px-4 py-2 text-end font-black">{{ __('health.ph_billed') }}</th>
                                <th class="px-4 py-2 text-end font-black">{{ __('health.ph_paid') }}</th>
                                <th class="px-4 py-2 text-end font-black">{{ __('health.ph_balance') }}</th>
                                @if($canManage)<th class="px-4 py-2"></th>@endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($balances as $row)
                                <tr>
                                    <td class="px-4 py-2.5 font-bold">{{ $row->name }}</td>
                                    <td class="px-4 py-2.5 text-end">{{ number_format($row->billed, 2) }}</td>
                                    <td class="px-4 py-2.5 text-end">{{ number_format($row->paid, 2) }}</td>
                                    <td class="px-4 py-2.5 text-end font-black {{ $row->balance > 0 ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300' }}">
                                        {{ number_format($row->balance, 2) }}
                                    </td>
                                    @if($canManage)
                                        <td class="px-4 py-2.5 text-end">
                                            <button type="button" @click="payTo({{ (int) $row->supplier_id }}, {{ $row->balance }})"
                                                    class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-600 text-xs font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                                {{ __('health.ph_pay') }}
                                            </button>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- ── Supplier payment ── --}}
        @if($canManage)
            <div x-show="paying" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.pharmacy.supplier-payments.store') }}" class="space-y-4">
                    @csrf
                    <h2 class="text-base font-black">{{ __('health.ph_payment_title') }}</h2>
                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_sup_name') }}</label>
                            <select name="supplier_id" x-model.number="payment.supplier_id" required
                                    class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                @foreach($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_amount') }}</label>
                            <input type="number" step="0.01" min="0.01" name="amount" x-model.number="payment.amount" required
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_pay_method') }}</label>
                            <select name="method" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                @foreach(\App\Models\HealthSupplierPayment::METHODS as $method)
                                    <option value="{{ $method }}">{{ __('health.pay_' . $method) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_pay_date') }}</label>
                            <input type="date" name="paid_on" value="{{ now()->toDateString() }}"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_reference') }}</label>
                            <input type="text" name="reference" maxlength="64"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.ph_f_notes') }}</label>
                            <input type="text" name="notes" maxlength="500"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">{{ __('health.ph_save') }}</button>
                        <button type="button" @click="paying = false" class="px-4 py-2.5 rounded-xl text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">{{ __('health.cancel') }}</button>
                    </div>
                </form>
            </div>
        @endif

        {{-- ── Received purchases ── --}}
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_from') }}</label>
                <input type="date" name="from" value="{{ $from }}" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_to') }}</label>
                <input type="date" name="to" value="{{ $to }}" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <button type="submit" class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                {{ __('health.ph_apply') }}
            </button>
        </form>

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($purchases->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.ph_purchases_none') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($purchases as $purchase)
                        @php
                            $lines = $batchMap[$purchase->id] ?? collect();
                            $paidHere = (float) ($paidMap[$purchase->id] ?? 0);
                        @endphp
                        <div class="px-5 py-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p class="text-sm font-black">{{ $purchase->po_number }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                        {{ $purchase->supplier?->name ?? __('health.ph_no_supplier') }}
                                        &middot; {{ \Illuminate\Support\Carbon::parse($purchase->order_date)->format('d-m-Y') }}
                                        @if($purchase->notes) &middot; {{ $purchase->notes }} @endif
                                    </p>
                                </div>
                                <div class="text-end">
                                    <p class="text-sm font-black">{{ number_format((float) $purchase->total_amount, 2) }}</p>
                                    <p class="text-[11px] {{ $paidHere >= (float) $purchase->total_amount ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300' }}">
                                        {{ __('health.ph_paid') }}: {{ number_format($paidHere, 2) }}
                                    </p>
                                </div>
                            </div>

                            @if($lines->isNotEmpty())
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    @foreach($lines as $batch)
                                        <span class="text-[11px] px-2 py-1 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200">
                                            {{ $batch->medicine?->display_name ?? '—' }}
                                            &middot; {{ $batch->batch_no ?: __('health.ph_no_batch') }}
                                            @if($batch->expiry_date) &middot; {{ $batch->expiry_date->format('m/Y') }} @endif
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <script>
        function pharmacyPurchase(medicines) {
            let counter = 0;
            const blank = () => ({ key: ++counter, medicine_id: '', batch_no: '', expiry_date: '', quantity: '', cost_price: '', sale_price: '' });

            return {
                medicines: medicines,
                receiving: false,
                supplierForm: false,
                paying: false,
                payment: { supplier_id: '', amount: '' },
                lines: [blank()],
                addLine() { this.lines.push(blank()); },
                removeLine(index) { this.lines.splice(index, 1); if (!this.lines.length) this.lines.push(blank()); },
                // Last known rates are a starting point only — the delivery note
                // wins, so the fields stay editable.
                applyDefaults(index) {
                    const line = this.lines[index];
                    const found = this.medicines.find((m) => m.id === Number(line.medicine_id));
                    if (!found) return;
                    if (!line.cost_price) line.cost_price = found.cost;
                    if (!line.sale_price) line.sale_price = found.price;
                },
                total() {
                    return this.lines.reduce((sum, l) => sum + (Number(l.quantity) || 0) * (Number(l.cost_price) || 0), 0);
                },
                payTo(supplierId, balance) {
                    this.payment.supplier_id = supplierId;
                    this.payment.amount = balance > 0 ? balance : '';
                    this.paying = true;
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                },
            };
        }
    </script>
</x-health-layout>
