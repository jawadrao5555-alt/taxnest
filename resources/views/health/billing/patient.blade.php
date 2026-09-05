@php
    use App\Models\HealthBill;
    use App\Models\HealthCharge;
    use App\Models\HealthPayment;
    use App\Models\HealthTaxCategory;

    $money = fn ($v) => number_format((float) $v, 2);
    $t = $account['unbilled_totals'];

    $treatChip = [
        HealthTaxCategory::TREATMENT_LOCAL  => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
        HealthTaxCategory::TREATMENT_EXEMPT => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
        HealthTaxCategory::TREATMENT_FBR    => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    ];

    $billChip = [
        HealthBill::STATUS_DRAFT     => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
        HealthBill::STATUS_FINALIZED => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
        HealthBill::STATUS_SETTLED   => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        HealthBill::STATUS_CANCELLED => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
    ];
@endphp
<x-health-layout>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="{ tab: 'charges', chargeForm: false, depositForm: false, billForm: false }">

        {{-- Who, and what they owe. --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-xl font-black tracking-tight">{{ $patient->name }}</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                        {{ $patient->mrn }}
                        @if($patient->phone) · {{ $patient->phone }} @endif
                        @if($patient->age_years) · {{ $patient->age_years }} {{ __('health.years') }} @endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('health.billing.statement', $patient->id) }}" target="_blank"
                       class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.stmt_title') }}</a>
                    @if($mayCharge)
                        <form method="POST" action="{{ route('health.billing.sync', $patient->id) }}">
                            @csrf
                            <button class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.led_sync') }}</button>
                        </form>
                        <button type="button" @click="depositForm = !depositForm"
                                class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.pay_take_deposit') }}</button>
                        <button type="button" @click="chargeForm = !chargeForm"
                                class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.led_add_charge') }}</button>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mt-4">
                @foreach([
                    ['health.acct_billed', $account['billed'], ''],
                    ['health.acct_collected', $account['collected'], 'text-emerald-700 dark:text-emerald-300'],
                    ['health.acct_refunded', $account['refunded'], 'text-rose-700 dark:text-rose-300'],
                    ['health.acct_credit', $account['credit'], 'text-sky-700 dark:text-sky-300'],
                    ['health.acct_due_now', $account['due_now'], 'text-amber-700 dark:text-amber-300'],
                ] as [$label, $value, $tone])
                    <div class="rounded-xl bg-gray-50 dark:bg-gray-900/40 p-3">
                        <div class="text-[11px] text-gray-500 dark:text-gray-400 font-bold uppercase tracking-wide">{{ __($label) }}</div>
                        <div class="mt-0.5 text-base font-black {{ $tone }}">{{ $money($value) }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Take an advance with no bill behind it yet. --}}
        @if($mayCharge)
            <form method="POST" action="{{ route('health.billing.deposit', $patient->id) }}" x-show="depositForm" x-cloak
                  class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                @csrf
                <h2 class="font-black">{{ __('health.pay_take_deposit') }}</h2>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.amount') }}</label>
                        <input type="number" step="0.01" min="0.01" name="amount" required
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.pay_method') }}</label>
                        <select name="method" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            @foreach(HealthPayment::METHODS as $m)
                                <option value="{{ $m }}">{{ __(HealthPayment::methodLabelKey($m)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.reference') }}</label>
                        <input type="text" name="reference" maxlength="120"
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <div class="flex items-end">
                        <button class="w-full px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.save') }}</button>
                    </div>
                </div>
                @unless($shift)
                    <p class="text-xs text-amber-700 dark:text-amber-300 font-bold">{{ __('health.shift_none_open_hint') }}</p>
                @endunless
            </form>

            {{-- Hand-posted charge. --}}
            <form method="POST" action="{{ route('health.billing.charges.store', $patient->id) }}" x-show="chargeForm" x-cloak
                  class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                @csrf
                <h2 class="font-black">{{ __('health.led_add_charge') }}</h2>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.led_category') }}</label>
                        <select name="category" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            @foreach(HealthCharge::MANUAL_CATEGORIES as $c)
                                <option value="{{ $c }}">{{ __(HealthCharge::categoryLabelKey($c)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.description') }}</label>
                        <input type="text" name="description" required maxlength="300"
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.department') }}</label>
                        <select name="health_department_id" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            <option value="">—</option>
                            @foreach($departments as $d)
                                <option value="{{ $d->id }}">{{ $d->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.led_unit_price') }}</label>
                        <input type="number" step="0.01" min="0" name="unit_price" value="0" required
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.qty') }}</label>
                        <input type="number" step="0.001" min="0.001" name="quantity" value="1" required
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.concession') }}</label>
                        <input type="number" step="0.01" min="0" name="concession_amount" value="0"
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.taxcat_rule') }}</label>
                        <select name="health_tax_category_id" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            <option value="">{{ __('health.taxcat_auto') }}</option>
                            @foreach($taxRules as $r)
                                @if($r->is_active)
                                    <option value="{{ $r->id }}">{{ $r->name }} — {{ __(HealthTaxCategory::treatmentLabelKey($r->treatment)) }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="flex justify-end">
                    <button class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.save') }}</button>
                </div>
            </form>
        @endif

        {{-- Discharge settlement shortcuts: one press sweeps a whole stay. --}}
        @if($mayCharge && $admissions->isNotEmpty())
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                <h2 class="font-black mb-2">{{ __('health.bill_settle_admission') }}</h2>
                <div class="flex flex-wrap gap-2">
                    @foreach($admissions as $adm)
                        <form method="POST" action="{{ route('health.billing.settle', [$patient->id, $adm->id]) }}">
                            @csrf
                            <button class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-xs font-bold">
                                {{ $adm->admission_no }} · {{ optional($adm->admitted_at)->format('d M Y') }}
                            </button>
                        </form>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Tabs: unbilled ledger / bills / receipts --}}
        <div class="flex flex-wrap gap-1.5 text-sm">
            @foreach([
                ['charges', 'health.led_unbilled', $account['unbilled']->count()],
                ['bills', 'health.bill_bills', $account['bills']->count()],
                ['payments', 'health.pay_receipts', $account['payments']->count()],
            ] as [$key, $label, $count])
                <button type="button" @click="tab = '{{ $key }}'"
                        :class="tab === '{{ $key }}' ? 'bg-teal-700 text-white' : 'bg-gray-100 dark:bg-gray-700'"
                        class="px-4 py-2 rounded-xl font-bold">{{ __($label) }} ({{ $count }})</button>
            @endforeach
        </div>

        {{-- ── Unbilled ledger. Ticking lines here is how a bill is raised. ── --}}
        <div x-show="tab === 'charges'" x-cloak
             class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <form method="POST" action="{{ route('health.billing.bills.store', $patient->id) }}">
                @csrf
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-3 py-2.5 w-10"></th>
                                <th class="px-3 py-2.5 text-start">{{ __('health.led_charge_no') }}</th>
                                <th class="px-3 py-2.5 text-start">{{ __('health.description') }}</th>
                                <th class="px-3 py-2.5 text-start">{{ __('health.led_source') }}</th>
                                <th class="px-3 py-2.5 text-end">{{ __('health.led_net') }}</th>
                                <th class="px-3 py-2.5 text-end">{{ __('health.tax') }}</th>
                                <th class="px-3 py-2.5 text-end">{{ __('health.total') }}</th>
                                <th class="px-3 py-2.5 text-start">{{ __('health.tax_treatment') }}</th>
                                <th class="px-3 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($account['unbilled'] as $c)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                    <td class="px-3 py-2.5">
                                        @if($mayCharge)
                                            <input type="checkbox" name="charge_ids[]" value="{{ $c->id }}" checked
                                                   class="w-4 h-4 rounded border-gray-300 text-teal-700">
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 font-mono text-xs">{{ $c->charge_no }}</td>
                                    <td class="px-3 py-2.5">
                                        <div class="font-bold">{{ $c->description }}</div>
                                        <div class="text-[11px] text-gray-500 dark:text-gray-400">
                                            {{ __(HealthCharge::categoryLabelKey($c->category)) }}
                                            @if($c->department_name ?? $c->department?->name) · {{ $c->department->name }} @endif
                                            · {{ optional($c->charge_date)->format('d M Y') }}
                                        </div>
                                    </td>
                                    <td class="px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400">
                                        {{ __(HealthCharge::sourceLabelKey($c->source_type)) }}
                                        @if($c->source_reference)
                                            <div class="font-mono">{{ $c->source_reference }}</div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 text-end">{{ $money($c->net_amount) }}</td>
                                    <td class="px-3 py-2.5 text-end">{{ $money($c->tax_amount) }}</td>
                                    <td class="px-3 py-2.5 text-end font-bold">{{ $money($c->total_amount) }}</td>
                                    <td class="px-3 py-2.5">
                                        <span class="text-[11px] font-bold px-2 py-1 rounded-lg {{ $treatChip[$c->tax_treatment] ?? '' }}">
                                            {{ __(HealthTaxCategory::treatmentLabelKey($c->tax_treatment)) }}
                                            @if((float) $c->tax_rate > 0) {{ rtrim(rtrim(number_format((float) $c->tax_rate, 2), '0'), '.') }}% @endif
                                        </span>
                                    </td>
                                    <td class="px-3 py-2.5 text-end whitespace-nowrap">
                                        @if($mayCharge)
                                            <button type="submit" form="reverse-{{ $c->id }}"
                                                    class="text-xs font-bold text-rose-700 dark:text-rose-300 hover:underline">{{ __('health.led_reverse') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">{{ __('health.led_nothing_unbilled') }}</td></tr>
                            @endforelse
                        </tbody>
                        @if($account['unbilled']->isNotEmpty())
                            <tfoot class="bg-gray-50 dark:bg-gray-900/40 font-black">
                                <tr>
                                    <td colspan="4" class="px-3 py-2.5 text-end">{{ __('health.total') }}</td>
                                    <td class="px-3 py-2.5 text-end">{{ $money($t['net']) }}</td>
                                    <td class="px-3 py-2.5 text-end">{{ $money($t['tax']) }}</td>
                                    <td class="px-3 py-2.5 text-end">{{ $money($t['total']) }}</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>

                @if($mayCharge && $account['unbilled']->isNotEmpty())
                    <div class="border-t border-gray-100 dark:border-gray-700 p-4 grid grid-cols-1 md:grid-cols-5 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.bill_doc_type') }}</label>
                            <select name="doc_type" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="{{ HealthBill::TYPE_INVOICE }}">{{ __('health.bill_type_invoice') }}</option>
                                <option value="{{ HealthBill::TYPE_ESTIMATE }}">{{ __('health.bill_type_estimate') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.bill_scope') }}</label>
                            <select name="scope" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach(HealthBill::SCOPES as $s)
                                    <option value="{{ $s }}" @selected($s === HealthBill::SCOPE_COMBINED)>{{ __(HealthBill::scopeLabelKey($s)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.bill_payer') }}</label>
                            <select name="payer_type" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach(HealthBill::PAYER_TYPES as $p)
                                    <option value="{{ $p }}">{{ __('health.bill_payer_' . $p) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.bill_third_party') }}</label>
                            <input type="number" step="0.01" min="0" name="insurance_amount" value="0"
                                   class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div class="flex items-end">
                            <button class="w-full px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.bill_raise') }}</button>
                        </div>
                    </div>
                @endif
            </form>
        </div>

        {{-- Reversal forms live outside the bill form so a nested form is never
             needed — a nested <form> is dropped by the browser and the button
             would silently do nothing. --}}
        @if($mayCharge)
            @foreach($account['unbilled'] as $c)
                <form id="reverse-{{ $c->id }}" method="POST" action="{{ route('health.billing.charges.reverse', $c->id) }}" class="hidden">
                    @csrf
                </form>
            @endforeach
        @endif

        {{-- ── Bills ── --}}
        <div x-show="tab === 'bills'" x-cloak
             class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-2.5 text-start">{{ __('health.bill_no') }}</th>
                        <th class="px-4 py-2.5 text-start">{{ __('health.date') }}</th>
                        <th class="px-4 py-2.5 text-end">{{ __('health.bill_total') }}</th>
                        <th class="px-4 py-2.5 text-end">{{ __('health.bill_paid') }}</th>
                        <th class="px-4 py-2.5 text-end">{{ __('health.bill_outstanding') }}</th>
                        <th class="px-4 py-2.5 text-start">{{ __('health.status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($account['bills'] as $bill)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td class="px-4 py-2.5">
                                <a href="{{ route('health.billing.bill', $bill->id) }}" class="font-black text-teal-700 dark:text-teal-300">{{ $bill->bill_no }}</a>
                                @if($bill->isEstimate())
                                    <span class="ms-1 text-[10px] font-bold px-1.5 py-0.5 rounded bg-gray-200 dark:bg-gray-700">{{ __('health.bill_type_estimate') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">{{ optional($bill->bill_date)->format('d M Y') }}</td>
                            <td class="px-4 py-2.5 text-end font-bold">{{ $money($bill->total_amount) }}</td>
                            <td class="px-4 py-2.5 text-end">{{ $money($bill->paid_amount) }}</td>
                            <td class="px-4 py-2.5 text-end">{{ $money($bill->outstanding_amount) }}</td>
                            <td class="px-4 py-2.5">
                                <span class="text-[11px] font-bold px-2 py-1 rounded-lg {{ $billChip[$bill->status] ?? '' }}">
                                    {{ __(HealthBill::statusLabelKey($bill->status)) }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">{{ __('health.bill_none_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- ── Receipts ── --}}
        <div x-show="tab === 'payments'" x-cloak
             class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-2.5 text-start">{{ __('health.pay_receipt_no') }}</th>
                        <th class="px-4 py-2.5 text-start">{{ __('health.date') }}</th>
                        <th class="px-4 py-2.5 text-start">{{ __('health.pay_kind') }}</th>
                        <th class="px-4 py-2.5 text-start">{{ __('health.pay_method') }}</th>
                        <th class="px-4 py-2.5 text-end">{{ __('health.amount') }}</th>
                        <th class="px-4 py-2.5 text-start">{{ __('health.bill_no') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($account['payments'] as $p)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td class="px-4 py-2.5 font-mono text-xs">{{ $p->receipt_no }}</td>
                            <td class="px-4 py-2.5">{{ optional($p->received_at)->format('d M Y, h:i A') }}</td>
                            <td class="px-4 py-2.5">{{ __(HealthPayment::kindLabelKey($p->kind)) }}</td>
                            <td class="px-4 py-2.5">{{ __(HealthPayment::methodLabelKey($p->method)) }}</td>
                            <td class="px-4 py-2.5 text-end font-bold {{ $p->isInflow() ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
                                {{ $p->isInflow() ? '+' : '−' }}{{ $money($p->amount) }}
                            </td>
                            <td class="px-4 py-2.5 text-xs">
                                @if($p->health_bill_id)
                                    <a href="{{ route('health.billing.bill', $p->health_bill_id) }}" class="text-teal-700 dark:text-teal-300 font-bold">#{{ $p->health_bill_id }}</a>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">{{ __('health.pay_none_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-health-layout>
