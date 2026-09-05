@php
    use App\Models\HealthAdmission;
    use App\Models\HealthBed;

    $money = fn ($v) => number_format((float) $v, 0);

    $ranges = ['today', 'yesterday', 'week', 'month', 'last_month', 'custom'];
@endphp
<x-health-layout>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.ipd_reports_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.ipd_reports_subtitle') }}</p>
            </div>
            <a href="{{ route('health.ipd') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.nav_ipd') }}</a>
        </div>

        {{-- ── range ── --}}
        <form method="GET" action="{{ route('health.ipd.reports') }}"
              class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.range') }}</label>
                <select name="range" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    @foreach($ranges as $option)
                        <option value="{{ $option }}" @selected($range === $option)>{{ __('health.range_' . $option) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.from') }}</label>
                <input type="date" name="from" value="{{ $from->toDateString() }}"
                       class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.to') }}</label>
                <input type="date" name="to" value="{{ $to->toDateString() }}"
                       class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">{{ __('health.apply') }}</button>
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $from->format('d M Y') }} — {{ $to->format('d M Y') }}</span>
        </form>

        @if($lockedToOwn)
            <p class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.report_own_doctor_only') }}</p>
        @endif

        {{-- ── live occupancy ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-base font-black">{{ __('health.rep_live_occupancy') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.rep_live_occupancy_hint') }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900 text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.ward') }}</th>
                            @foreach(HealthBed::STATUSES as $status)
                                <th class="text-end px-4 py-2 font-bold">{{ __('health.bed_status_' . $status) }}</th>
                            @endforeach
                            <th class="text-end px-4 py-2 font-bold">{{ __('health.beds_total') }}</th>
                            <th class="text-end px-4 py-2 font-bold">{{ __('health.rep_occupancy') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($live['wards'] as $ward)
                            <tr>
                                <td class="px-4 py-2.5 font-bold">{{ $ward['name'] }}</td>
                                @foreach(HealthBed::STATUSES as $status)
                                    <td class="px-4 py-2.5 text-end">{{ $ward[$status] ?? 0 }}</td>
                                @endforeach
                                <td class="px-4 py-2.5 text-end font-bold">{{ $ward['total'] }}</td>
                                <td class="px-4 py-2.5 text-end font-bold">{{ $ward['rate'] }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ count(HealthBed::STATUSES) + 3 }}" class="px-4 py-8 text-center text-sm text-gray-500">{{ __('health.no_beds') }}</td></tr>
                        @endforelse
                    </tbody>
                    @if($live['totals']['total'] > 0)
                        <tfoot class="bg-gray-50 dark:bg-gray-900 font-black">
                            <tr>
                                <td class="px-4 py-2.5">{{ __('health.total') }}</td>
                                @foreach(HealthBed::STATUSES as $status)
                                    <td class="px-4 py-2.5 text-end">{{ $live['totals'][$status] ?? 0 }}</td>
                                @endforeach
                                <td class="px-4 py-2.5 text-end">{{ $live['totals']['total'] }}</td>
                                <td class="px-4 py-2.5 text-end">{{ $live['totals']['rate'] }}%</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ── movement ── --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @foreach([
                ['health.rep_requested', $movement['requested']],
                ['health.rep_admitted', $movement['admitted']],
                ['health.rep_discharged', $movement['discharged']],
                ['health.rep_cancelled', $movement['cancelled']],
                ['health.rep_still_in', $movement['still_in']],
                ['health.rep_bed_days', $wardUsage['bed_days']],
            ] as [$label, $value])
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __($label) }}</p>
                    <p class="text-2xl font-black mt-0.5">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        <div class="grid lg:grid-cols-2 gap-5">
            {{-- ── by admission type ── --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <h2 class="text-base font-black">{{ __('health.rep_by_admission_type') }}</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse($movement['by_type'] as $type => $count)
                        <li class="flex justify-between gap-3">
                            <span>{{ __('health.adm_type_' . $type) }}</span>
                            <span class="font-bold">{{ $count }}</span>
                        </li>
                    @empty
                        <li class="text-gray-500">{{ __('health.no_data') }}</li>
                    @endforelse
                </ul>
            </div>

            {{-- ── by discharge type ── --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <h2 class="text-base font-black">{{ __('health.rep_by_discharge_type') }}</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse($movement['by_discharge_type'] as $type => $count)
                        <li class="flex justify-between gap-3">
                            <span>{{ $type ? __('health.discharge_type_' . $type) : '—' }}</span>
                            <span class="font-bold">{{ $count }}</span>
                        </li>
                    @empty
                        <li class="text-gray-500">{{ __('health.no_data') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>

        {{-- ── length of stay ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-black">{{ __('health.rep_length_of_stay') }}</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.rep_los_hint') }}</p>
                </div>
                <p class="text-sm">
                    <span class="font-black">{{ $stays['avg_days'] }}</span> {{ __('health.rep_avg_days') }}
                    · <span class="font-black">{{ $stays['longest'] }}</span> {{ __('health.rep_longest') }}
                    · <span class="font-black">{{ $stays['total_days'] }}</span> {{ __('health.rep_total_days') }}
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900 text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.adm_no') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.patient') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.ward') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.consultant') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.adm_discharged_at') }}</th>
                            <th class="text-end px-4 py-2 font-bold">{{ __('health.adm_los') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($stays['rows'] as $stay)
                            <tr>
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('health.ipd.show', $stay->id) }}" class="font-bold text-teal-700 dark:text-teal-300">{{ $stay->admission_no }}</a>
                                </td>
                                <td class="px-4 py-2.5">{{ $stay->patient->name ?? '—' }}</td>
                                <td class="px-4 py-2.5">{{ $stay->ward->name ?? '—' }}</td>
                                <td class="px-4 py-2.5">{{ $stay->doctor->name ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-xs">{{ $stay->discharged_at?->format('d M Y H:i') }}</td>
                                <td class="px-4 py-2.5 text-end font-bold">{{ $stay->lengthOfStayDays() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">{{ __('health.no_data') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid lg:grid-cols-2 gap-5">
            {{-- ── charge breakdown ── --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h2 class="text-base font-black">{{ __('health.rep_charges') }}</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900 text-xs text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="text-start px-4 py-2 font-bold">{{ __('health.charge_category') }}</th>
                                <th class="text-end px-4 py-2 font-bold">{{ __('health.rep_lines') }}</th>
                                <th class="text-end px-4 py-2 font-bold">{{ __('health.gross') }}</th>
                                <th class="text-end px-4 py-2 font-bold">{{ __('health.concession') }}</th>
                                <th class="text-end px-4 py-2 font-bold">{{ __('health.net') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($charges['by_category'] as $category => $row)
                                <tr>
                                    <td class="px-4 py-2.5">{{ __('health.charge_cat_' . $category) }}</td>
                                    <td class="px-4 py-2.5 text-end">{{ $row['lines'] }}</td>
                                    <td class="px-4 py-2.5 text-end">{{ $money($row['gross']) }}</td>
                                    <td class="px-4 py-2.5 text-end">{{ $money($row['concession']) }}</td>
                                    <td class="px-4 py-2.5 text-end font-bold">{{ $money($row['net']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">{{ __('health.no_data') }}</td></tr>
                            @endforelse
                        </tbody>
                        @if($charges['totals']['lines'] > 0)
                            <tfoot class="bg-gray-50 dark:bg-gray-900 font-black">
                                <tr>
                                    <td class="px-4 py-2.5">{{ __('health.total') }}</td>
                                    <td class="px-4 py-2.5 text-end">{{ $charges['totals']['lines'] }}</td>
                                    <td class="px-4 py-2.5 text-end">{{ $money($charges['totals']['gross']) }}</td>
                                    <td class="px-4 py-2.5 text-end">{{ $money($charges['totals']['concession']) }}</td>
                                    <td class="px-4 py-2.5 text-end">{{ $money($charges['totals']['net']) }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
                @if($charges['reversed_lines'] > 0)
                    <p class="px-5 py-3 text-xs text-rose-700 dark:text-rose-300 border-t border-gray-100 dark:border-gray-700">
                        {{ __('health.rep_reversed', ['lines' => $charges['reversed_lines'], 'amount' => $money($charges['reversed'])]) }}
                    </p>
                @endif
            </div>

            {{-- ── collection ── --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <h2 class="text-base font-black">{{ __('health.rep_collection') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.rep_collection_hint') }}</p>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse($collection['by_method'] as $method => $amount)
                        <li class="flex justify-between gap-3">
                            <span>{{ __('health.pay_method_' . $method) }}</span>
                            <span class="font-bold">{{ $money($amount) }}</span>
                        </li>
                    @empty
                        <li class="text-gray-500">{{ __('health.no_data') }}</li>
                    @endforelse
                </ul>
                <dl class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-700 space-y-1.5 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('health.advances') }}</dt>
                        <dd class="font-bold">{{ $money($collection['advances']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('health.refunds') }}</dt>
                        <dd class="font-bold">{{ $money($collection['refunds']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3 pt-2 border-t border-gray-100 dark:border-gray-700">
                        <dt class="font-black">{{ __('health.rep_net_collected') }}</dt>
                        <dd class="font-black">{{ $money($collection['net']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('health.rep_room_revenue') }}</dt>
                        <dd class="font-bold">{{ $money($wardUsage['room_revenue']) }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        {{-- ── procedure register ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-base font-black">{{ __('health.rep_procedure_register') }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900 text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.procedure') }}</th>
                            <th class="text-end px-4 py-2 font-bold">{{ __('health.rep_performed') }}</th>
                            <th class="text-end px-4 py-2 font-bold">{{ __('health.gross') }}</th>
                            <th class="text-end px-4 py-2 font-bold">{{ __('health.concession') }}</th>
                            <th class="text-end px-4 py-2 font-bold">{{ __('health.net') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($procedures as $row)
                            <tr>
                                <td class="px-4 py-2.5">{{ $row['name'] ?? __('health.op_unlisted') }}</td>
                                <td class="px-4 py-2.5 text-end">{{ $row['total'] }}</td>
                                <td class="px-4 py-2.5 text-end">{{ $money($row['gross']) }}</td>
                                <td class="px-4 py-2.5 text-end">{{ $money($row['concession']) }}</td>
                                <td class="px-4 py-2.5 text-end font-bold">{{ $money($row['net']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">{{ __('health.no_data') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid lg:grid-cols-2 gap-5">
            {{-- ── surgeon activity ── --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h2 class="text-base font-black">{{ __('health.rep_surgeon_activity') }}</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900 text-xs text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="text-start px-4 py-2 font-bold">{{ __('health.op_surgeon') }}</th>
                                <th class="text-end px-4 py-2 font-bold">{{ __('health.rep_booked') }}</th>
                                <th class="text-end px-4 py-2 font-bold">{{ __('health.op_status_completed') }}</th>
                                <th class="text-end px-4 py-2 font-bold">{{ __('health.op_status_cancelled') }}</th>
                                <th class="text-end px-4 py-2 font-bold">{{ __('health.op_outcome_complications') }}</th>
                                <th class="text-end px-4 py-2 font-bold">{{ __('health.net') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($surgeons as $row)
                                <tr>
                                    <td class="px-4 py-2.5">{{ $row['name'] ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-end">{{ $row['booked'] }}</td>
                                    <td class="px-4 py-2.5 text-end">{{ $row['completed'] }}</td>
                                    <td class="px-4 py-2.5 text-end">{{ $row['cancelled'] + $row['postponed'] }}</td>
                                    <td class="px-4 py-2.5 text-end">{{ $row['complications'] }}</td>
                                    <td class="px-4 py-2.5 text-end font-bold">{{ $money($row['net']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">{{ __('health.no_data') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- ── consultant inpatient load ── --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h2 class="text-base font-black">{{ __('health.rep_doctor_activity') }}</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900 text-xs text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="text-start px-4 py-2 font-bold">{{ __('health.consultant') }}</th>
                                <th class="text-end px-4 py-2 font-bold">{{ __('health.rep_admitted') }}</th>
                                <th class="text-end px-4 py-2 font-bold">{{ __('health.rep_discharged') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($doctorActivity as $row)
                                <tr>
                                    <td class="px-4 py-2.5">{{ $row['name'] ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-end">{{ $row['admissions'] }}</td>
                                    <td class="px-4 py-2.5 text-end">{{ $row['discharged'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-4 py-8 text-center text-sm text-gray-500">{{ __('health.no_data') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ── cancellations ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-base font-black">{{ __('health.rep_cancellations') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.rep_cancellations_hint') }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900 text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.op_no') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.patient') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.procedure') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.op_surgeon') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.status') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.reason') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($cancellations as $row)
                            <tr>
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('health.operations.show', $row->id) }}" class="font-bold text-teal-700 dark:text-teal-300">{{ $row->operation_no }}</a>
                                </td>
                                <td class="px-4 py-2.5">{{ $row->patient->name ?? '—' }}</td>
                                <td class="px-4 py-2.5">{{ $row->procedure->name ?? $row->title }}</td>
                                <td class="px-4 py-2.5">{{ $row->surgeon->name ?? '—' }}</td>
                                <td class="px-4 py-2.5">{{ __('health.op_status_' . $row->status) }}</td>
                                <td class="px-4 py-2.5 text-xs">{{ $row->cancel_reason }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">{{ __('health.no_data') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-health-layout>
