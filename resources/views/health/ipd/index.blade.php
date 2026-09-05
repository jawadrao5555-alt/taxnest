@php
    use App\Models\HealthAdmission;
    use Illuminate\Support\Js;

    $scrub = function ($value) {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', (string) $value);
        return $clean === false ? '' : $clean;
    };

    // Free beds, grouped for the "admit into" picker. Built in PHP so the
    // x-data attribute stays a single short expression.
    $freeBeds = $beds->filter(fn ($b) => $b->status === 'available')
        ->map(fn ($b) => [
            'id' => (int) $b->id,
            'code' => $scrub($b->code),
            'ward' => $scrub($b->ward->name ?? ''),
            'gender_policy' => $b->ward->gender_policy ?? 'any',
            'rate' => number_format($b->resolvedDailyRate(), 2),
        ])->values()->all();

    $statusChip = [
        'available' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        'occupied'  => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
        'reserved'  => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
        'cleaning'  => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
        'blocked'   => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
    ];

    $careChip = [
        'stable'    => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        'improving' => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
        'serious'   => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
        'critical'  => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
    ];

    $bedsByWard = $beds->groupBy('health_ward_id');
@endphp
<x-health-layout>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="{ admitForm: false, freeBeds: {{ Js::from($freeBeds) }} }">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.ipd_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.ipd_subtitle') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if($mayManageWards)
                    <a href="{{ route('health.ipd.facility') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.facility_title') }}</a>
                @endif
                @if(Route::has('health.ipd.reports'))
                    <a href="{{ route('health.ipd.reports') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.nav_reports') }}</a>
                @endif
                @if($mayManage)
                    <button type="button" @click="admitForm = !admitForm"
                            class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                        {{ __('health.adm_new') }}
                    </button>
                @endif
            </div>
        </div>

        {{-- ── occupancy strip ── --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @foreach(['total' => 'health.beds_total', 'occupied' => 'health.bed_status_occupied', 'available' => 'health.bed_status_available', 'reserved' => 'health.bed_status_reserved', 'cleaning' => 'health.bed_status_cleaning', 'blocked' => 'health.bed_status_blocked'] as $key => $label)
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __($label) }}</p>
                    <p class="text-2xl font-black mt-0.5">{{ $occupancy[$key] ?? 0 }}</p>
                    @if($key === 'total')
                        <p class="text-[11px] text-gray-500 mt-0.5">{{ __('health.occupancy_rate', ['rate' => $occupancy['rate']]) }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- ── new admission ── --}}
        @if($mayManage)
            <div x-show="admitForm" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.ipd.store') }}" class="space-y-4">
                    @csrf
                    <h2 class="text-base font-black">{{ __('health.adm_new') }}</h2>

                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <label class="block sm:col-span-2">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.patient_mrn_or_id') }}</span>
                            <input type="number" name="health_patient_id" required min="1"
                                   value="{{ old('health_patient_id', request('patient_id')) }}"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            <span class="text-[11px] text-gray-500">{{ __('health.adm_patient_hint') }}</span>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.adm_type') }}</span>
                            <select name="admission_type" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach(HealthAdmission::TYPES as $type)
                                    <option value="{{ $type }}">{{ __('health.adm_type_' . $type) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.consultant') }}</span>
                            <select name="health_doctor_id" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">—</option>
                                @foreach($doctors as $doctor)
                                    <option value="{{ $doctor->id }}">{{ $doctor->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.branch') }}</span>
                            <select name="branch_id" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">—</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.department') }}</span>
                            <select name="health_department_id" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">—</option>
                                @foreach($departments as $department)
                                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.adm_bed_now') }}</span>
                            <select name="health_bed_id" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">{{ __('health.adm_bed_later') }}</option>
                                <template x-for="b in freeBeds" :key="b.id">
                                    <option :value="b.id" x-text="b.ward + ' — ' + b.code + ' (' + b.rate + ')'"></option>
                                </template>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.adm_deposit_required') }}</span>
                            <input type="number" step="0.01" min="0" name="deposit_required" value="{{ old('deposit_required', 0) }}"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.adm_estimated_days') }}</span>
                            <input type="number" min="0" max="365" name="estimated_days" value="{{ old('estimated_days') }}"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.adm_estimated_cost') }}</span>
                            <input type="number" step="0.01" min="0" name="estimated_cost" value="{{ old('estimated_cost') }}"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.adm_payer_type') }}</span>
                            <select name="payer_type" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach(HealthAdmission::PAYER_TYPES as $payer)
                                    <option value="{{ $payer }}">{{ __('health.payer_' . $payer) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.adm_payer_name') }}</span>
                            <input type="text" name="payer_name" maxlength="150" value="{{ old('payer_name') }}"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.adm_attendant') }}</span>
                            <input type="text" name="attendant_name" maxlength="150" value="{{ old('attendant_name') }}"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.adm_attendant_phone') }}</span>
                            <input type="text" name="attendant_phone" maxlength="32" value="{{ old('attendant_phone') }}"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.adm_attendant_relation') }}</span>
                            <input type="text" name="attendant_relation" maxlength="60" value="{{ old('attendant_relation') }}"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-3">
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.adm_reason') }}</span>
                            <textarea name="reason" rows="2" maxlength="500" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">{{ old('reason') }}</textarea>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.adm_provisional_diagnosis') }}</span>
                            <textarea name="provisional_diagnosis" rows="2" maxlength="500" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">{{ old('provisional_diagnosis') }}</textarea>
                        </label>
                    </div>

                    <div class="flex gap-2">
                        <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.save') }}</button>
                        <button type="button" @click="admitForm = false" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.cancel') }}</button>
                    </div>
                </form>
            </div>
        @endif

        {{-- ── bed board ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
            <div class="px-5 py-4 flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-base font-black">{{ __('health.bed_board') }}</h2>
                <form method="GET" class="flex flex-wrap gap-2">
                    <select name="ward_id" onchange="this.form.submit()" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        <option value="">{{ __('health.all_wards') }}</option>
                        @foreach($wards as $ward)
                            <option value="{{ $ward->id }}" @selected(request('ward_id') == $ward->id)>{{ $ward->name }}</option>
                        @endforeach
                    </select>
                </form>
            </div>

            <div class="p-5 space-y-5">
                @forelse($bedsByWard as $wardId => $wardBeds)
                    <div>
                        <p class="text-xs font-black text-gray-500 dark:text-gray-400 mb-2">
                            {{ $wardBeds->first()->ward->name ?? '—' }}
                        </p>
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                            @foreach($wardBeds as $bed)
                                <div class="rounded-xl border border-gray-200 dark:border-gray-700 p-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-black text-sm">{{ $bed->code }}</span>
                                        <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold {{ $statusChip[$bed->status] ?? '' }}">
                                            {{ __('health.bed_status_' . $bed->status) }}
                                        </span>
                                    </div>
                                    @if($bed->admission && $bed->admission->patient)
                                        <a href="{{ route('health.ipd.show', $bed->admission->id) }}" class="block mt-1">
                                            <p class="text-xs font-bold text-teal-700 dark:text-teal-300">{{ $bed->admission->patient->name }}</p>
                                            <p class="text-[11px] text-gray-500">{{ $bed->admission->admission_no }}</p>
                                        </a>
                                        @if($bed->admission->care_status)
                                            <span class="inline-block mt-1 px-2 py-0.5 rounded-lg text-[10px] font-bold {{ $careChip[$bed->admission->care_status] ?? '' }}">
                                                {{ __('health.care_' . $bed->admission->care_status) }}
                                            </span>
                                        @endif
                                    @elseif($bed->status_note)
                                        <p class="text-[11px] text-gray-500 mt-1">{{ $bed->status_note }}</p>
                                    @endif

                                    @if($mayManage && $bed->status !== 'occupied')
                                        <form method="POST" action="{{ route('health.ipd.bed-status', $bed->id) }}" class="mt-2 flex gap-1">
                                            @csrf
                                            <select name="status" onchange="this.form.submit()" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-[11px] py-1">
                                                @foreach(\App\Models\HealthBed::MANUAL_STATUSES as $manual)
                                                    <option value="{{ $manual }}" @selected($bed->status === $manual)>{{ __('health.bed_status_' . $manual) }}</option>
                                                @endforeach
                                            </select>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('health.no_beds') }}
                        @if($mayManageWards)
                            <a href="{{ route('health.ipd.facility') }}" class="font-bold text-teal-700 dark:text-teal-300">{{ __('health.facility_title') }}</a>
                        @endif
                    </p>
                @endforelse
            </div>
        </div>

        {{-- ── admissions ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-base font-black">{{ __('health.admissions') }}</h2>
                <form method="GET" class="flex flex-wrap gap-2">
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="{{ __('health.search') }}"
                           class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <select name="status" onchange="this.form.submit()" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        <option value="open" @selected($status === 'open')>{{ __('health.adm_filter_open') }}</option>
                        <option value="requested" @selected($status === 'requested')>{{ __('health.adm_status_requested') }}</option>
                        @foreach(HealthAdmission::STATUSES as $s)
                            <option value="{{ $s }}" @selected($status === $s)>{{ __('health.adm_status_' . $s) }}</option>
                        @endforeach
                        <option value="all" @selected($status === 'all')>{{ __('health.all') }}</option>
                    </select>
                    <button type="submit" class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.apply') }}</button>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900 text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.adm_no') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.patient') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.ward') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.consultant') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.adm_admitted_at') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.adm_los') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($admissions as $admission)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('health.ipd.show', $admission->id) }}" class="font-black text-teal-700 dark:text-teal-300">{{ $admission->admission_no }}</a>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="font-bold">{{ $admission->patient->name ?? '—' }}</span>
                                    <span class="block text-xs text-gray-500">{{ $admission->patient->mrn ?? '' }}</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    {{ $admission->ward->name ?? '—' }}
                                    <span class="block text-xs text-gray-500">{{ $admission->bed->code ?? '' }}</span>
                                </td>
                                <td class="px-4 py-2.5">{{ $admission->doctor->name ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-xs">{{ $admission->admitted_at?->format('d M Y H:i') ?? '—' }}</td>
                                <td class="px-4 py-2.5">{{ $admission->lengthOfStayDays() ?: '—' }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="px-2 py-0.5 rounded-lg text-[11px] font-bold bg-gray-100 dark:bg-gray-700">
                                        {{ __('health.adm_status_' . $admission->status) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-10 text-center text-sm text-gray-500">{{ __('health.no_admissions') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($admissions->hasPages())
                <div class="px-5 py-3 border-t border-gray-100 dark:border-gray-700">{{ $admissions->links() }}</div>
            @endif
        </div>
    </div>
</x-health-layout>
