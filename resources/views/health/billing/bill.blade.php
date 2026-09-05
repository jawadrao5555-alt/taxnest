@php
    use App\Models\HealthBill;
    use App\Models\HealthCharge;
    use App\Models\HealthPayment;
    use App\Models\HealthTaxCategory;

    $money = fn ($v) => number_format((float) $v, 2);

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

    $treatmentTotals = $bill->treatment_totals ?: [];
@endphp
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="{ payForm: false, refundForm: false }">

        {{-- Header --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <h1 class="text-xl font-black tracking-tight">{{ $bill->bill_no }}</h1>
                        <span class="text-[11px] font-bold px-2 py-1 rounded-lg {{ $billChip[$bill->status] ?? '' }}">
                            {{ __(HealthBill::statusLabelKey($bill->status)) }}
                        </span>
                        <span class="text-[11px] font-bold px-2 py-1 rounded-lg bg-gray-100 dark:bg-gray-700">
                            {{ __($bill->isEstimate() ? 'health.bill_type_estimate' : 'health.bill_type_invoice') }}
                        </span>
                        <span class="text-[11px] font-bold px-2 py-1 rounded-lg bg-gray-100 dark:bg-gray-700">
                            {{ __(HealthBill::scopeLabelKey($bill->scope)) }}
                        </span>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        <a href="{{ route('health.billing.patient', $bill->health_patient_id) }}" class="font-bold text-teal-700 dark:text-teal-300">{{ $bill->patient->name ?? '—' }}</a>
                        · {{ $bill->patient->mrn ?? '' }}
                        · {{ optional($bill->bill_date)->format('d M Y') }}
                        @if($bill->department) · {{ $bill->department->name }} @endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('health.billing.receipt', [$bill->id, 'size' => '80']) }}" target="_blank"
                       class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.rcpt_thermal') }}</a>
                    <a href="{{ route('health.billing.receipt', [$bill->id, 'size' => 'a4']) }}" target="_blank"
                       class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.rcpt_a4') }}</a>
                    <a href="{{ route('health.billing.fbr', $bill->id) }}"
                       class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.fbr') }}</a>
                    @if($mayCharge && $bill->status === HealthBill::STATUS_DRAFT)
                        <form method="POST" action="{{ route('health.billing.bills.finalize', $bill->id) }}">
                            @csrf
                            <button class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.bill_finalize') }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        {{-- What the regulator will and will not be told. This block is the
             "cannot silently switch treatment" promise made visible: the split
             is shown on the bill itself, before and after finalizing. --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-black">{{ __('health.bill_treatment_split') }}</h2>
                @if($bill->isFinal())
                    <span class="text-[11px] font-bold px-2 py-1 rounded-lg bg-gray-100 dark:bg-gray-700">{{ __('health.bill_tax_locked') }}</span>
                @endif
            </div>
            <div class="grid grid-cols-3 gap-3 mt-2 text-sm">
                @foreach(HealthTaxCategory::TREATMENTS as $t)
                    <div class="rounded-xl bg-gray-50 dark:bg-gray-900/40 p-3">
                        <div class="text-[11px] text-gray-500 dark:text-gray-400 font-bold uppercase tracking-wide">{{ __(HealthTaxCategory::treatmentLabelKey($t)) }}</div>
                        <div class="mt-0.5 font-black">{{ $money($treatmentTotals[$t] ?? 0) }}</div>
                    </div>
                @endforeach
            </div>
            @if($bill->fbr_invoice_number)
                <p class="mt-3 text-sm font-bold text-emerald-700 dark:text-emerald-300">
                    {{ __('health.fbr_invoice_number') }}: <span class="font-mono">{{ $bill->fbr_invoice_number }}</span>
                </p>
            @elseif($bill->fbr_eligible)
                <p class="mt-3 text-sm font-bold text-amber-700 dark:text-amber-300">{{ __('health.fbr_eligible_not_filed') }}</p>
            @endif
        </div>

        {{-- Lines. Frozen at finalize; the source stays visible on every one so
             a disputed line can be walked back to the visit or sale behind it. --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2.5 text-start">#</th>
                        <th class="px-3 py-2.5 text-start">{{ __('health.description') }}</th>
                        <th class="px-3 py-2.5 text-start">{{ __('health.led_source') }}</th>
                        <th class="px-3 py-2.5 text-end">{{ __('health.qty') }}</th>
                        <th class="px-3 py-2.5 text-end">{{ __('health.led_unit_price') }}</th>
                        <th class="px-3 py-2.5 text-end">{{ __('health.concession') }}</th>
                        <th class="px-3 py-2.5 text-end">{{ __('health.led_net') }}</th>
                        <th class="px-3 py-2.5 text-end">{{ __('health.tax') }}</th>
                        <th class="px-3 py-2.5 text-end">{{ __('health.total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($bill->lines as $line)
                        <tr>
                            <td class="px-3 py-2.5 text-gray-400">{{ $line->line_no }}</td>
                            <td class="px-3 py-2.5">
                                <div class="font-bold">{{ $line->description }}</div>
                                <div class="text-[11px] text-gray-500 dark:text-gray-400">
                                    {{ __(HealthCharge::categoryLabelKey($line->category)) }}
                                    @if($line->department_name) · {{ $line->department_name }} @endif
                                    <span class="ms-1 font-bold px-1.5 py-0.5 rounded {{ $treatChip[$line->tax_treatment] ?? '' }}">
                                        {{ __(HealthTaxCategory::treatmentLabelKey($line->tax_treatment)) }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ __(HealthCharge::sourceLabelKey($line->source_type)) }}
                                @if($line->source_reference)
                                    <div class="font-mono">{{ $line->source_reference }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-end">{{ rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.') }}</td>
                            <td class="px-3 py-2.5 text-end">{{ $money($line->unit_price) }}</td>
                            <td class="px-3 py-2.5 text-end">{{ $money($line->concession_amount) }}</td>
                            <td class="px-3 py-2.5 text-end">{{ $money($line->net_amount) }}</td>
                            <td class="px-3 py-2.5 text-end">{{ $money($line->tax_amount) }}</td>
                            <td class="px-3 py-2.5 text-end font-bold">{{ $money($line->total_amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Money summary --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-1.5 text-sm">
                @foreach([
                    ['health.bill_gross', $bill->gross_amount, false],
                    ['health.concession', $bill->concession_amount, false],
                    ['health.led_net', $bill->net_amount, false],
                    ['health.tax', $bill->tax_amount, false],
                    ['health.bill_total', $bill->total_amount, true],
                    ['health.bill_insurance', $bill->insurance_amount, false],
                    ['health.bill_corporate', $bill->corporate_amount, false],
                    ['health.bill_patient_payable', $bill->patient_payable, true],
                    ['health.bill_deposit_applied', $bill->deposit_applied, false],
                    ['health.bill_paid', $bill->paid_amount, false],
                    ['health.bill_refunded', $bill->refunded_amount, false],
                    ['health.bill_outstanding', $bill->outstanding_amount, true],
                ] as [$label, $value, $strong])
                    <div class="flex items-center justify-between {{ $strong ? 'font-black border-t border-gray-100 dark:border-gray-700 pt-1.5 mt-1.5' : '' }}">
                        <span class="{{ $strong ? '' : 'text-gray-500 dark:text-gray-400' }}">{{ __($label) }}</span>
                        <span>{{ $money($value) }}</span>
                    </div>
                @endforeach
            </div>

            {{-- Actions --}}
            <div class="space-y-3">
                @if($bill->isFinal())
                    @if($mayCharge && (float) $bill->outstanding_amount > 0)
                        @if((float) ($account['credit'] ?? 0) > 0)
                            <form method="POST" action="{{ route('health.billing.bills.credit', $bill->id) }}"
                                  class="rounded-2xl border border-sky-200 dark:border-sky-800 bg-sky-50 dark:bg-sky-900/20 p-4 flex flex-wrap items-center justify-between gap-3">
                                @csrf
                                <span class="text-sm font-bold">{{ __('health.pay_credit_available', ['amount' => $money($account['credit'])]) }}</span>
                                <button class="px-4 py-2 rounded-xl bg-sky-700 hover:bg-sky-800 text-white text-sm font-bold">{{ __('health.pay_apply_credit') }}</button>
                            </form>
                        @endif

                        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                            <button type="button" @click="payForm = !payForm"
                                    class="w-full px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.pay_take_payment') }}</button>

                            <form method="POST" action="{{ route('health.billing.bills.pay', $bill->id) }}" x-show="payForm" x-cloak class="mt-3 space-y-3">
                                @csrf
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.amount') }}</label>
                                        <input type="number" step="0.01" min="0.01" name="amount" required
                                               value="{{ number_format((float) $bill->outstanding_amount, 2, '.', '') }}"
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
                                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.pay_kind') }}</label>
                                        <select name="kind" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                            @foreach([HealthPayment::KIND_PAYMENT, HealthPayment::KIND_INSURANCE, HealthPayment::KIND_CORPORATE] as $k)
                                                <option value="{{ $k }}">{{ __(HealthPayment::kindLabelKey($k)) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.reference') }}</label>
                                        <input type="text" name="reference" maxlength="120"
                                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                    </div>
                                </div>
                                <button class="w-full px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.save') }}</button>
                            </form>
                        </div>
                    @endif

                    @if($mayManage && (float) $bill->paid_amount > (float) $bill->refunded_amount)
                        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                            <button type="button" @click="refundForm = !refundForm"
                                    class="w-full px-4 py-2.5 rounded-xl bg-rose-100 dark:bg-rose-900/30 text-rose-800 dark:text-rose-200 text-sm font-bold">{{ __('health.pay_refund') }}</button>

                            <form method="POST" action="{{ route('health.billing.bills.refund', $bill->id) }}" x-show="refundForm" x-cloak class="mt-3 space-y-3">
                                @csrf
                                <div class="grid grid-cols-2 gap-3">
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
                                </div>
                                <input type="text" name="note" maxlength="300" placeholder="{{ __('health.reason') }}"
                                       class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <button class="w-full px-4 py-2.5 rounded-xl bg-rose-700 hover:bg-rose-800 text-white text-sm font-bold">{{ __('health.save') }}</button>
                            </form>
                        </div>
                    @endif
                @endif

                @if($mayManage && in_array($bill->status, [HealthBill::STATUS_DRAFT, HealthBill::STATUS_FINALIZED], true))
                    <form method="POST" action="{{ route('health.billing.bills.cancel', $bill->id) }}"
                          class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-2"
                          onsubmit="return confirm('{{ __('health.bill_cancel_confirm') }}')">
                        @csrf
                        <input type="text" name="reason" maxlength="300" placeholder="{{ __('health.reason') }}"
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        <button class="w-full px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.bill_cancel') }}</button>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('health.bill_cancel_hint') }}</p>
                    </form>
                @endif
            </div>
        </div>

        {{-- Receipts against this bill --}}
        @if($bill->payments->isNotEmpty())
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2.5 text-start">{{ __('health.pay_receipt_no') }}</th>
                            <th class="px-4 py-2.5 text-start">{{ __('health.date') }}</th>
                            <th class="px-4 py-2.5 text-start">{{ __('health.pay_kind') }}</th>
                            <th class="px-4 py-2.5 text-start">{{ __('health.pay_method') }}</th>
                            <th class="px-4 py-2.5 text-end">{{ __('health.amount') }}</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach($bill->payments as $p)
                            <tr class="{{ $p->reversed_at ? 'opacity-50 line-through' : '' }}">
                                <td class="px-4 py-2.5 font-mono text-xs">{{ $p->receipt_no }}</td>
                                <td class="px-4 py-2.5">{{ optional($p->received_at)->format('d M Y, h:i A') }}</td>
                                <td class="px-4 py-2.5">{{ __(HealthPayment::kindLabelKey($p->kind)) }}</td>
                                <td class="px-4 py-2.5">{{ __(HealthPayment::methodLabelKey($p->method)) }}</td>
                                <td class="px-4 py-2.5 text-end font-bold">{{ $money($p->amount) }}</td>
                                <td class="px-4 py-2.5 text-end">
                                    @if($mayManage && !$p->reversed_at)
                                        <form method="POST" action="{{ route('health.billing.payments.reverse', $p->id) }}"
                                              onsubmit="return confirm('{{ __('health.pay_reverse_confirm') }}')">
                                            @csrf
                                            <button class="text-xs font-bold text-rose-700 dark:text-rose-300 hover:underline">{{ __('health.pay_reverse') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-health-layout>
