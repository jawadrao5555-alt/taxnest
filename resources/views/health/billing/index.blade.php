@php
    use App\Models\HealthBill;
    use App\Models\HealthTaxCategory;

    $money = fn ($v) => number_format((float) $v, 2);

    $billChip = [
        HealthBill::STATUS_DRAFT     => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
        HealthBill::STATUS_FINALIZED => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
        HealthBill::STATUS_SETTLED   => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        HealthBill::STATUS_CANCELLED => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
    ];
@endphp
<x-health-layout>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.bill_counter_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.bill_counter_subtitle') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('health.billing.shifts') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.shift_title') }}</a>
                @if(Route::has('health.billing.day-close'))
                    <a href="{{ route('health.billing.day-close') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.dayclose_title') }}</a>
                @endif
                @if($mayManage)
                    <a href="{{ route('health.billing.tax-categories') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.taxcat_title') }}</a>
                @endif
            </div>
        </div>

        {{-- The drawer this person is working out of. Shown first because every
             receipt taken below is attributed to it. --}}
        @if($shift)
            <div class="rounded-2xl border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20 px-4 py-3 text-sm">
                <span class="font-bold">{{ __('health.shift_open_now') }}</span>
                <span class="text-gray-600 dark:text-gray-300">
                    — {{ __('health.shift_opened_at') }}: {{ optional($shift->opened_at)->format('d M Y, h:i A') }}
                </span>
            </div>
        @elseif($mayCharge)
            <form method="POST" action="{{ route('health.billing.shifts.open') }}"
                  class="rounded-2xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 px-4 py-3 flex flex-wrap items-center gap-3">
                @csrf
                <span class="text-sm font-bold">{{ __('health.shift_none_open') }}</span>
                <input type="number" step="0.01" min="0" name="opening_float" value="0"
                       class="w-32 px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm"
                       placeholder="{{ __('health.shift_opening_float') }}">
                <button class="px-4 py-2 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.shift_open_action') }}</button>
            </form>
        @endif

        {{-- Today, from the persisted rows. Billed and collected sit side by side
             because they are different questions and a counter is asked both. --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach([
                ['health.dayclose_billed', $today['billed'], 'text-gray-900 dark:text-white'],
                ['health.dayclose_collected', $today['payments']['in'], 'text-emerald-700 dark:text-emerald-300'],
                ['health.dayclose_refunded', $today['payments']['out'], 'text-rose-700 dark:text-rose-300'],
                ['health.dayclose_outstanding', $today['outstanding'], 'text-amber-700 dark:text-amber-300'],
            ] as [$label, $value, $tone])
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400 font-bold uppercase tracking-wide">{{ __($label) }}</div>
                    <div class="mt-1 text-lg font-black {{ $tone }}">{{ $money($value) }}</div>
                </div>
            @endforeach
        </div>

        {{-- Regulatory split, never merged into one figure. --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400 font-bold uppercase tracking-wide mb-2">{{ __('health.bill_treatment_split') }}</div>
            <div class="grid grid-cols-3 gap-3 text-sm">
                @foreach(HealthTaxCategory::TREATMENTS as $t)
                    <div>
                        <div class="text-gray-500 dark:text-gray-400">{{ __(HealthTaxCategory::treatmentLabelKey($t)) }}</div>
                        <div class="font-black">{{ $money($today['treatment'][$t] ?? 0) }}</div>
                    </div>
                @endforeach
            </div>
            @if(($today['fbr']['pending'] ?? 0) > 0 || ($today['fbr']['failed'] ?? 0) > 0)
                <div class="mt-3 text-xs font-bold text-amber-700 dark:text-amber-300">
                    {{ __('health.fbr_waiting_count', ['pending' => $today['fbr']['pending'], 'failed' => $today['fbr']['failed']]) }}
                </div>
            @endif
        </div>

        {{-- Patient search: the only way onto an account, so it stays on top. --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
            <form method="GET" action="{{ route('health.billing') }}" class="flex flex-wrap gap-2">
                <input type="text" name="q" value="{{ $search }}" autofocus
                       class="flex-1 min-w-[220px] px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm"
                       placeholder="{{ __('health.bill_search_placeholder') }}">
                <button class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.search') }}</button>
            </form>

            @if($search !== '')
                <div class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($patients as $p)
                        <a href="{{ route('health.billing.patient', $p->id) }}"
                           class="flex items-center justify-between py-2.5 hover:bg-gray-50 dark:hover:bg-gray-700/40 rounded-lg px-2 -mx-2">
                            <span class="font-bold text-sm">{{ $p->name }}</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $p->mrn }} · {{ $p->phone }}</span>
                        </a>
                    @empty
                        <p class="py-3 text-sm text-gray-500 dark:text-gray-400">{{ __('health.bill_no_patient_found') }}</p>
                    @endforelse
                </div>
            @endif
        </div>

        {{-- Recent bills --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-4 py-3 flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 dark:border-gray-700">
                <h2 class="font-black">{{ __('health.bill_recent') }}</h2>
                <div class="flex flex-wrap gap-1.5 text-xs">
                    <a href="{{ route('health.billing') }}"
                       class="px-3 py-1.5 rounded-lg font-bold {{ !$status ? 'bg-teal-700 text-white' : 'bg-gray-100 dark:bg-gray-700' }}">{{ __('health.all') }}</a>
                    @foreach([HealthBill::STATUS_DRAFT, HealthBill::STATUS_FINALIZED, HealthBill::STATUS_SETTLED] as $s)
                        <a href="{{ route('health.billing', ['status' => $s]) }}"
                           class="px-3 py-1.5 rounded-lg font-bold {{ $status === $s ? 'bg-teal-700 text-white' : 'bg-gray-100 dark:bg-gray-700' }}">{{ __(HealthBill::statusLabelKey($s)) }}</a>
                    @endforeach
                    <a href="{{ route('health.billing', ['status' => 'fbr_pending']) }}"
                       class="px-3 py-1.5 rounded-lg font-bold {{ $status === 'fbr_pending' ? 'bg-teal-700 text-white' : 'bg-gray-100 dark:bg-gray-700' }}">{{ __('health.fbr_pending_filter') }}</a>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2.5 text-start">{{ __('health.bill_no') }}</th>
                            <th class="px-4 py-2.5 text-start">{{ __('health.patient') }}</th>
                            <th class="px-4 py-2.5 text-start">{{ __('health.date') }}</th>
                            <th class="px-4 py-2.5 text-end">{{ __('health.bill_total') }}</th>
                            <th class="px-4 py-2.5 text-end">{{ __('health.bill_outstanding') }}</th>
                            <th class="px-4 py-2.5 text-start">{{ __('health.status') }}</th>
                            <th class="px-4 py-2.5 text-start">{{ __('health.fbr') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($bills as $bill)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('health.billing.bill', $bill->id) }}" class="font-black text-teal-700 dark:text-teal-300">{{ $bill->bill_no }}</a>
                                    @if($bill->isEstimate())
                                        <span class="ms-1 text-[10px] font-bold px-1.5 py-0.5 rounded bg-gray-200 dark:bg-gray-700">{{ __('health.bill_type_estimate') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5">{{ $bill->patient->name ?? '—' }}</td>
                                <td class="px-4 py-2.5 whitespace-nowrap">{{ optional($bill->bill_date)->format('d M Y') }}</td>
                                <td class="px-4 py-2.5 text-end font-bold">{{ $money($bill->total_amount) }}</td>
                                <td class="px-4 py-2.5 text-end {{ (float) $bill->outstanding_amount > 0 ? 'text-amber-700 dark:text-amber-300 font-bold' : 'text-gray-400' }}">
                                    {{ $money($bill->outstanding_amount) }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="text-[11px] font-bold px-2 py-1 rounded-lg {{ $billChip[$bill->status] ?? 'bg-gray-200 dark:bg-gray-700' }}">
                                        {{ __(HealthBill::statusLabelKey($bill->status)) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-xs">
                                    @if($bill->fbr_invoice_number)
                                        <span class="font-mono text-emerald-700 dark:text-emerald-300">{{ $bill->fbr_invoice_number }}</span>
                                    @elseif($bill->fbr_eligible)
                                        <span class="text-amber-700 dark:text-amber-300 font-bold">{{ __('health.fbr_not_filed') }}</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">{{ __('health.bill_none_yet') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-health-layout>
