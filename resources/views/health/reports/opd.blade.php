@php
    use App\Models\HealthAppointment;
    use Illuminate\Support\Carbon;

    $money = fn ($v) => number_format((float) $v, 0);
@endphp
<x-health-layout>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.reports_opd_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.reports_opd_subtitle') }}</p>
        </div>

        @unless($opdOn)
            <div class="rounded-2xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 px-5 py-4">
                <p class="text-sm font-bold text-amber-800 dark:text-amber-200">{{ __('health.reports_opd_module_off') }}</p>
            </div>
        @endunless

        {{-- ── Range ── --}}
        <form method="GET" action="{{ route('health.reports') }}"
              class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.from') }}</label>
                <input type="date" name="from" value="{{ $from }}"
                       class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.to') }}</label>
                <input type="date" name="to" value="{{ $to }}"
                       class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.doctor') }}</label>
                <select name="doctor_id" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    <option value="">{{ __('health.all') }}</option>
                    @foreach($doctors as $doctor)
                        <option value="{{ $doctor->id }}" @selected($doctorFilter === (int) $doctor->id)>{{ $doctor->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">{{ __('health.apply') }}</button>
            <a href="{{ route('health.reports') }}" class="px-3 py-2.5 text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">{{ __('health.today') }}</a>
        </form>

        @if(!empty($lockedToOwnDoctor))
            <p class="mt-3 text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.report_own_doctor_only') }}</p>
        @endif

        {{-- ── Totals ── --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @foreach([
                ['health.rep_visits', $totals['visits'], false],
                ['health.rep_completed', $totals['completed'], false],
                ['health.rep_gross', $totals['gross'], true],
                ['health.rep_concession', $totals['concession'], true],
                ['health.rep_collected', $totals['collected'], true],
                ['health.rep_outstanding', $totals['outstanding'], true],
            ] as $tile)
                <div class="health-tile rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 px-4 py-3">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400">{{ __($tile[0]) }}</p>
                    <p class="text-2xl font-black mt-0.5">{{ $tile[2] ? $money($tile[1]) : $tile[1] }}</p>
                </div>
            @endforeach
        </div>

        {{-- ── Doctor workload ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-base font-black">{{ __('health.rep_doctor_workload') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.rep_money_hint') }}</p>
            </div>
            @if($workload->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.rep_no_rows') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[780px] text-sm">
                        <thead>
                            <tr class="text-[10px] uppercase tracking-wide text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                <th class="text-start px-5 py-2.5">{{ __('health.doctor') }}</th>
                                <th class="text-end px-3 py-2.5">{{ __('health.rep_visits') }}</th>
                                <th class="text-end px-3 py-2.5">{{ __('health.rep_completed') }}</th>
                                <th class="text-end px-3 py-2.5">{{ __('health.visit_type_new') }}</th>
                                <th class="text-end px-3 py-2.5">{{ __('health.visit_type_follow_up') }}</th>
                                <th class="text-end px-3 py-2.5">{{ __('health.rep_gross') }}</th>
                                <th class="text-end px-3 py-2.5">{{ __('health.rep_concession') }}</th>
                                <th class="text-end px-3 py-2.5">{{ __('health.rep_collected') }}</th>
                                <th class="text-end px-5 py-2.5">{{ __('health.rep_outstanding') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($workload as $row)
                                <tr>
                                    <td class="px-5 py-2.5 font-bold">{{ $doctorNames[$row->health_doctor_id] ?? '—' }}</td>
                                    <td class="px-3 py-2.5 text-end font-black">{{ $row->visits }}</td>
                                    <td class="px-3 py-2.5 text-end">{{ $row->completed }}</td>
                                    <td class="px-3 py-2.5 text-end">{{ $row->new_cases }}</td>
                                    <td class="px-3 py-2.5 text-end">{{ $row->follow_ups }}</td>
                                    <td class="px-3 py-2.5 text-end">{{ $money($row->gross) }}</td>
                                    <td class="px-3 py-2.5 text-end text-amber-700 dark:text-amber-300">{{ $money($row->concession) }}</td>
                                    <td class="px-3 py-2.5 text-end text-emerald-700 dark:text-emerald-300 font-black">{{ $money($row->collected) }}</td>
                                    <td class="px-5 py-2.5 text-end text-red-700 dark:text-red-300">{{ $money($row->outstanding) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="grid lg:grid-cols-2 gap-5">
            {{-- ── Appointment outcomes ── --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <h2 class="text-base font-black mb-1">{{ __('health.rep_appt_outcomes') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                    {{ __('health.rep_no_show_rate') }}: <span class="font-black text-gray-700 dark:text-gray-200">{{ $noShowRate }}%</span>
                </p>
                <div class="space-y-2">
                    @foreach(HealthAppointment::STATUSES as $status)
                        <div class="flex items-center justify-between text-sm">
                            <span>{{ __('health.appt_status_' . $status) }}</span>
                            <span class="font-black">{{ $outcomes[$status] ?? 0 }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700 space-y-2">
                    @foreach(HealthAppointment::KINDS as $kind)
                        <div class="flex items-center justify-between text-sm">
                            <span>{{ __('health.appt_kind_' . $kind) }}</span>
                            <span class="font-black">{{ $kinds[$kind] ?? 0 }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- ── Daily summary ── --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-base font-black">{{ __('health.rep_daily') }}</h2>
                </div>
                @if($daily->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.rep_no_rows') }}</p>
                @else
                    <div class="overflow-x-auto max-h-80 overflow-y-auto">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-white dark:bg-gray-800">
                                <tr class="text-[10px] uppercase tracking-wide text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                    <th class="text-start px-5 py-2.5">{{ __('health.date') }}</th>
                                    <th class="text-end px-3 py-2.5">{{ __('health.rep_visits') }}</th>
                                    <th class="text-end px-3 py-2.5">{{ __('health.rep_completed') }}</th>
                                    <th class="text-end px-5 py-2.5">{{ __('health.rep_collected') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach($daily as $day)
                                    <tr>
                                        <td class="px-5 py-2.5 font-bold">{{ Carbon::parse($day->visit_date)->format('d M Y') }}</td>
                                        <td class="px-3 py-2.5 text-end">{{ $day->visits }}</td>
                                        <td class="px-3 py-2.5 text-end">{{ $day->completed }}</td>
                                        <td class="px-5 py-2.5 text-end font-black">{{ $money($day->collected) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- ── No-shows ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-base font-black">{{ __('health.rep_no_shows') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.rep_no_shows_hint') }}</p>
            </div>
            @if($noShows->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.rep_no_rows') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($noShows as $miss)
                        <div class="px-5 py-3 flex flex-wrap items-center gap-3 text-sm">
                            <span class="font-bold">{{ Carbon::parse($miss->appointment_date)->format('d M Y') }}</span>
                            <a href="{{ route('health.patients.show', $miss->health_patient_id) }}" class="font-black hover:underline">{{ $miss->patient?->name ?? '—' }}</a>
                            <span class="font-mono text-[11px] text-teal-700 dark:text-teal-300">{{ $miss->patient?->mrn }}</span>
                            <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $miss->patient?->phone }}</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400 ms-auto">{{ $miss->doctor?->name }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-health-layout>
