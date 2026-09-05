@php
    use App\Models\HealthPrescriptionItem;
    use App\Models\HealthVisit;
    use App\Models\HealthVisitAttachment;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Js;

    $scrub = function ($value) {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', (string) $value);
        return $clean === false ? '' : $clean;
    };

    $prescription = $visit->prescriptions->sortByDesc('id')->first();

    // The prescription grid boots from whatever is already saved; a fresh
    // encounter starts with one blank line so the doctor can just type.
    $lines = $prescription
        ? $prescription->items->sortBy('line_no')->map(fn ($i) => [
            'medicine_name' => $scrub($i->medicine_name),
            'generic_name' => $scrub($i->generic_name),
            'strength' => $scrub($i->strength),
            'form' => $scrub($i->form),
            'dose' => $scrub($i->dose),
            'route' => $scrub($i->route),
            'frequency' => $scrub($i->frequency),
            'duration_days' => $i->duration_days !== null ? (int) $i->duration_days : '',
            'quantity' => $i->quantity !== null ? (string) $i->quantity : '',
            'instructions' => $scrub($i->instructions),
        ])->values()->all()
        : [];

    if (empty($lines)) {
        $lines = [[
            'medicine_name' => '', 'generic_name' => '', 'strength' => '', 'form' => '',
            'dose' => '', 'route' => '', 'frequency' => '', 'duration_days' => '',
            'quantity' => '', 'instructions' => '',
        ]];
    }

    $blankLine = [
        'medicine_name' => '', 'generic_name' => '', 'strength' => '', 'form' => '',
        'dose' => '', 'route' => '', 'frequency' => '', 'duration_days' => '',
        'quantity' => '', 'instructions' => '',
    ];

    $readOnly = !$canWrite || $visit->status === HealthVisit::STATUS_CANCELLED;
@endphp
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <a href="{{ route('health.clinical', ['date' => Carbon::parse($visit->visit_date)->toDateString()]) }}"
               class="text-xs font-bold text-teal-700 dark:text-teal-300 hover:underline">&larr; {{ __('health.clinical_title') }}</a>
        </div>

        {{-- ── Patient header ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl font-black tracking-tight">
                            <a href="{{ route('health.patients.show', $patient->id) }}" class="hover:underline">{{ $patient->name }}</a>
                        </h1>
                        <span class="font-mono text-sm font-black text-teal-700 dark:text-teal-300">{{ $patient->mrn }}</span>
                        @if($patient->is_confidential)
                            <span class="text-[10px] font-black uppercase px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300">{{ __('health.patient_confidential_tag') }}</span>
                        @endif
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        {{ $patient->age_label ?: '—' }}
                        @if($patient->gender) &middot; {{ __('health.gender_' . $patient->gender) }} @endif
                        @if($patient->blood_group) &middot; {{ $patient->blood_group }} @endif
                        @if($patient->phone) &middot; <span class="font-mono">{{ $patient->phone }}</span> @endif
                    </p>
                </div>
                <div class="text-end">
                    <p class="font-mono text-sm font-black">{{ $visit->visit_no }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ Carbon::parse($visit->visit_date)->format('d M Y') }} &middot; {{ $visit->doctor?->name }}
                    </p>
                    <div class="flex flex-wrap items-center justify-end gap-1.5 mt-1.5">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide {{ \App\Models\HealthVisit::statusClasses($visit->status) }}">
                            {{ __(\App\Models\HealthVisit::statusLabelKey($visit->status)) }}
                        </span>
                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                            {{ __(\App\Models\HealthVisit::typeLabelKey($visit->visit_type)) }}
                        </span>
                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded
                            {{ $visit->fee_status === 'paid' ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300' : 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300' }}">
                            {{ __('health.fee') }} {{ number_format((float) $visit->net_fee, 0) }} &middot; {{ __('health.fee_status_' . $visit->fee_status) }}
                        </span>
                    </div>
                </div>
            </div>

            @if($patient->allergies)
                <div class="mt-4 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3">
                    <p class="text-xs font-black uppercase tracking-wide text-red-700 dark:text-red-300">{{ __('health.patient_allergies') }}</p>
                    <p class="text-sm text-red-800 dark:text-red-200 mt-0.5">{{ $patient->allergies }}</p>
                </div>
            @endif
            @if($patient->chronic_conditions)
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                    <span class="font-bold">{{ __('health.patient_chronic') }}:</span> {{ $patient->chronic_conditions }}
                </p>
            @endif

            <div class="flex flex-wrap items-center gap-2 mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                @if($canWrite && $visit->status === HealthVisit::STATUS_WAITING)
                    <form method="POST" action="{{ route('health.clinical.start', $visit->id) }}">
                        @csrf
                        <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">{{ __('health.visit_start') }}</button>
                    </form>
                @endif
                @if($canWrite && $visit->status === HealthVisit::STATUS_COMPLETED)
                    <form method="POST" action="{{ route('health.clinical.reopen', $visit->id) }}">
                        @csrf
                        <button type="submit" class="px-4 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">{{ __('health.visit_reopen') }}</button>
                    </form>
                @endif
                @if($prescription)
                    <a href="{{ route('health.clinical.prescription.print', $prescription->id) }}" target="_blank" rel="noopener"
                       class="px-4 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        {{ __('health.presc_print') }} {{ $prescription->prescription_no }}
                    </a>
                @endif
            </div>
        </div>

        @if($readOnly)
            <div class="rounded-2xl bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 px-5 py-3">
                <p class="text-sm font-bold text-gray-600 dark:text-gray-300">
                    {{ $visit->status === HealthVisit::STATUS_CANCELLED ? __('health.visit_closed_readonly') : __('health.clinical_read_only') }}
                </p>
            </div>
        @endif

        {{-- ── Vitals ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                <h2 class="text-base font-black">{{ __('health.vitals') }}</h2>
                @if($visit->vitals_recorded_at)
                    <p class="text-[11px] text-gray-400">{{ __('health.vitals_recorded_at', ['at' => $visit->vitals_recorded_at->format('d M Y, h:i A')]) }}</p>
                @endif
            </div>

            @if($canRecordVitals && $visit->status !== HealthVisit::STATUS_CANCELLED)
                <form method="POST" action="{{ route('health.clinical.vitals', $visit->id) }}" class="space-y-4">
                    @csrf
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                        @foreach([
                            'temperature_c' => ['health.vital_temperature', '0.1'],
                            'pulse_bpm' => ['health.vital_pulse', '1'],
                            'respiratory_rate' => ['health.vital_respiration', '1'],
                            'bp_systolic' => ['health.vital_bp_systolic', '1'],
                            'bp_diastolic' => ['health.vital_bp_diastolic', '1'],
                            'spo2' => ['health.vital_spo2', '1'],
                            'weight_kg' => ['health.vital_weight', '0.1'],
                            'height_cm' => ['health.vital_height', '0.1'],
                            'blood_sugar' => ['health.vital_blood_sugar', '0.1'],
                        ] as $field => $meta)
                            <div>
                                <label class="block text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __($meta[0]) }}</label>
                                <input type="number" step="{{ $meta[1] }}" name="{{ $field }}"
                                       value="{{ old($field, $visit->{$field}) }}"
                                       class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            </div>
                        @endforeach
                        <div>
                            <label class="block text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.vital_bmi') }}</label>
                            <p class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-900 text-sm font-black">{{ $visit->bmi ?? '—' }}</p>
                        </div>
                    </div>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">{{ __('health.vitals_save') }}</button>
                </form>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 text-sm">
                    @foreach([
                        'temperature_c' => 'health.vital_temperature',
                        'pulse_bpm' => 'health.vital_pulse',
                        'respiratory_rate' => 'health.vital_respiration',
                        'bp_systolic' => 'health.vital_bp_systolic',
                        'bp_diastolic' => 'health.vital_bp_diastolic',
                        'spo2' => 'health.vital_spo2',
                        'weight_kg' => 'health.vital_weight',
                        'height_cm' => 'health.vital_height',
                        'blood_sugar' => 'health.vital_blood_sugar',
                    ] as $field => $label)
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400">{{ __($label) }}</p>
                            <p class="font-black">{{ $visit->{$field} ?? '—' }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ── Clinical notes ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
            <h2 class="text-base font-black mb-4">{{ __('health.clinical_record') }}</h2>

            @if($readOnly)
                <div class="space-y-3 text-sm">
                    @foreach([
                        'chief_complaint' => 'health.visit_complaint',
                        'history' => 'health.visit_history',
                        'examination' => 'health.visit_examination',
                        'diagnosis' => 'health.visit_diagnosis',
                        'procedures' => 'health.visit_procedures',
                        'advice' => 'health.visit_advice',
                        'clinical_notes' => 'health.visit_notes',
                    ] as $field => $label)
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400">{{ __($label) }}</p>
                            <p class="whitespace-pre-line">{{ $visit->{$field} ?: '—' }}</p>
                        </div>
                    @endforeach
                    @if($visit->follow_up_date)
                        <p class="text-sm text-teal-700 dark:text-teal-300 font-bold">
                            {{ __('health.visit_follow_up') }}: {{ Carbon::parse($visit->follow_up_date)->format('d M Y') }}
                        </p>
                    @endif
                </div>
            @else
                <form method="POST" action="{{ route('health.clinical.notes', $visit->id) }}" class="space-y-4"
                      x-data="{ complete: false }">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.visit_type') }}</label>
                        <select name="visit_type" class="w-full sm:w-64 rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            @foreach(HealthVisit::TYPES as $t)
                                <option value="{{ $t }}" @selected(old('visit_type', $visit->visit_type) === $t)>{{ __('health.visit_type_' . $t) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid lg:grid-cols-2 gap-4">
                        @foreach([
                            'chief_complaint' => ['health.visit_complaint', 2],
                            'history' => ['health.visit_history', 3],
                            'examination' => ['health.visit_examination', 3],
                            'diagnosis' => ['health.visit_diagnosis', 2],
                            'procedures' => ['health.visit_procedures', 2],
                            'advice' => ['health.visit_advice', 3],
                        ] as $field => $meta)
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __($meta[0]) }}</label>
                                <textarea name="{{ $field }}" rows="{{ $meta[1] }}"
                                          class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">{{ old($field, $visit->{$field}) }}</textarea>
                            </div>
                        @endforeach
                        <div class="lg:col-span-2">
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.visit_notes') }}</label>
                            <textarea name="clinical_notes" rows="3"
                                      class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">{{ old('clinical_notes', $visit->clinical_notes) }}</textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.visit_follow_up') }}</label>
                            <input type="date" name="follow_up_date" min="{{ now()->toDateString() }}"
                                   value="{{ old('follow_up_date', $visit->follow_up_date ? Carbon::parse($visit->follow_up_date)->toDateString() : '') }}"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.visit_follow_up_notes') }}</label>
                            <input type="text" name="follow_up_notes" maxlength="500"
                                   value="{{ old('follow_up_notes', $visit->follow_up_notes) }}"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                    </div>

                    <input type="hidden" name="complete" :value="complete ? 1 : 0">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit" @click="complete = false"
                                class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">{{ __('health.save') }}</button>
                        <button type="submit" @click="complete = true"
                                class="px-5 py-2.5 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white text-sm font-black transition">{{ __('health.visit_save_complete') }}</button>
                    </div>
                </form>
            @endif
        </div>

        {{-- ── Prescription ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                <h2 class="text-base font-black">{{ __('health.prescription') }}</h2>
                @if($prescription)
                    <span class="text-xs font-bold px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                        {{ $prescription->prescription_no }} &middot; {{ __(\App\Models\HealthPrescription::statusLabelKey($prescription->status)) }}
                    </span>
                @endif
            </div>

            @if($readOnly)
                @if($prescription && $prescription->items->isNotEmpty())
                    <div class="space-y-2 text-sm">
                        @foreach($prescription->items->sortBy('line_no') as $item)
                            <div class="flex flex-wrap items-baseline gap-2">
                                <span class="font-black">{{ $item->line_no }}. {{ $item->display_name }}</span>
                                <span class="text-gray-500 dark:text-gray-400">
                                    {{ $item->dose }}
                                    @if($item->route) &middot; {{ __(HealthPrescriptionItem::routeLabelKey($item->route)) }} @endif
                                    @if($item->frequency) &middot; {{ $item->frequency }} @endif
                                    @if($item->duration_days) &middot; {{ __('health.presc_days', ['days' => $item->duration_days]) }} @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('health.presc_none') }}</p>
                @endif
            @else
                <form method="POST" action="{{ route('health.clinical.prescription', $visit->id) }}"
                      x-data="{
                          rows: {{ Js::from($lines) }},
                          blank: {{ Js::from($blankLine) }},
                          issue: false,
                          add() { this.rows.push(Object.assign({}, this.blank)); }
                      }"
                      class="space-y-4">
                    @csrf

                    <div class="overflow-x-auto -mx-5 px-5">
                        <table class="w-full min-w-[900px] text-sm">
                            <thead>
                                <tr class="text-[10px] uppercase tracking-wide text-gray-400 text-start">
                                    <th class="text-start pb-2 w-8">#</th>
                                    <th class="text-start pb-2">{{ __('health.presc_medicine') }}</th>
                                    <th class="text-start pb-2">{{ __('health.presc_strength') }}</th>
                                    <th class="text-start pb-2">{{ __('health.presc_form') }}</th>
                                    <th class="text-start pb-2">{{ __('health.presc_dose') }}</th>
                                    <th class="text-start pb-2">{{ __('health.presc_route') }}</th>
                                    <th class="text-start pb-2">{{ __('health.presc_frequency') }}</th>
                                    <th class="text-start pb-2">{{ __('health.presc_days_label') }}</th>
                                    <th class="text-start pb-2">{{ __('health.presc_quantity') }}</th>
                                    <th class="pb-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(row, i) in rows" :key="i">
                                    <tr>
                                        <td class="py-1 text-xs text-gray-400" x-text="i + 1"></td>
                                        <td class="py-1 pe-1">
                                            <input type="text" :name="'items[' + i + '][medicine_name]'" x-model="row.medicine_name" maxlength="200"
                                                   class="w-44 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                            <input type="text" :name="'items[' + i + '][generic_name]'" x-model="row.generic_name" maxlength="200"
                                                   placeholder="{{ __('health.presc_generic') }}"
                                                   class="w-44 mt-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                        </td>
                                        <td class="py-1 pe-1">
                                            <input type="text" :name="'items[' + i + '][strength]'" x-model="row.strength" maxlength="60"
                                                   class="w-20 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                        </td>
                                        <td class="py-1 pe-1">
                                            <select :name="'items[' + i + '][form]'" x-model="row.form"
                                                    class="w-24 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                                <option value="">—</option>
                                                @foreach($forms as $form)
                                                    <option value="{{ $form }}">{{ __('health.form_' . $form) }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="py-1 pe-1">
                                            <input type="text" :name="'items[' + i + '][dose]'" x-model="row.dose" maxlength="60"
                                                   class="w-20 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                        </td>
                                        <td class="py-1 pe-1">
                                            <select :name="'items[' + i + '][route]'" x-model="row.route"
                                                    class="w-24 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                                <option value="">—</option>
                                                @foreach($routes as $r)
                                                    <option value="{{ $r }}">{{ __('health.route_' . $r) }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="py-1 pe-1">
                                            <input type="text" :name="'items[' + i + '][frequency]'" x-model="row.frequency" maxlength="60"
                                                   placeholder="1-0-1"
                                                   class="w-24 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                        </td>
                                        <td class="py-1 pe-1">
                                            <input type="number" min="0" max="3650" :name="'items[' + i + '][duration_days]'" x-model="row.duration_days"
                                                   class="w-16 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                        </td>
                                        <td class="py-1 pe-1">
                                            <input type="number" step="0.01" min="0" :name="'items[' + i + '][quantity]'" x-model="row.quantity"
                                                   class="w-20 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                        </td>
                                        <td class="py-1">
                                            <button type="button" @click="rows.splice(i, 1)"
                                                    class="text-xs font-bold text-red-600 hover:underline">{{ __('health.remove') }}</button>
                                        </td>
                                    </tr>
                                </template>
                                <template x-for="(row, i) in rows" :key="'note-' + i">
                                    <tr>
                                        <td></td>
                                        <td colspan="9" class="pb-2">
                                            <input type="text" :name="'items[' + i + '][instructions]'" x-model="row.instructions" maxlength="300"
                                                   :placeholder="'{{ __('health.presc_line_instructions') }} #' + (i + 1)"
                                                   class="w-full rounded-lg border-gray-200 dark:border-gray-700 dark:bg-gray-900 text-xs">
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <button type="button" @click="add()"
                            class="px-3 py-2 rounded-xl border border-gray-300 dark:border-gray-600 text-xs font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        {{ __('health.presc_add_line') }}
                    </button>

                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.presc_instructions') }}</label>
                            <textarea name="general_instructions" rows="2"
                                      class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">{{ old('general_instructions', $prescription->general_instructions ?? '') }}</textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.presc_valid_until') }}</label>
                            <input type="date" name="valid_until" min="{{ now()->toDateString() }}"
                                   value="{{ old('valid_until', ($prescription && $prescription->valid_until) ? Carbon::parse($prescription->valid_until)->toDateString() : '') }}"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                    </div>

                    <input type="hidden" name="issue" :value="issue ? 1 : 0">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit" @click="issue = false"
                                class="px-5 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">{{ __('health.presc_save_draft') }}</button>
                        <button type="submit" @click="issue = true"
                                class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">{{ __('health.presc_issue') }}</button>
                    </div>
                    <p class="text-[11px] text-gray-400">{{ __('health.presc_issue_hint') }}</p>
                </form>
            @endif
        </div>

        {{-- ── Attachments ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
            <h2 class="text-base font-black mb-4">{{ __('health.attachments') }}</h2>

            @if($visit->attachments->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('health.attachments_none') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700 -mx-5">
                    @foreach($visit->attachments as $attachment)
                        <div class="px-5 py-3 flex flex-wrap items-center gap-3 text-sm">
                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                {{ __('health.attach_kind_' . $attachment->kind) }}
                            </span>
                            <a href="{{ route('health.clinical.attachments.download', $attachment->id) }}"
                               class="font-bold text-teal-700 dark:text-teal-300 hover:underline">{{ $attachment->original_name }}</a>
                            <span class="text-xs text-gray-400">{{ $attachment->size_label }}</span>
                            @if($attachment->caption)<span class="text-xs text-gray-500 dark:text-gray-400">{{ $attachment->caption }}</span>@endif
                            @unless($readOnly)
                                <form method="POST" action="{{ route('health.clinical.attachments.delete', $attachment->id) }}" class="ms-auto">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-xs font-bold text-red-600 hover:underline">{{ __('health.remove') }}</button>
                                </form>
                            @endunless
                        </div>
                    @endforeach
                </div>
            @endif

            @unless($readOnly)
                <form method="POST" action="{{ route('health.clinical.attachments.store', $visit->id) }}" enctype="multipart/form-data"
                      class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700 flex flex-wrap items-end gap-3">
                    @csrf
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.attach_file') }}</label>
                        <input type="file" name="file" required
                               class="text-xs file:me-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-teal-50 file:text-teal-700">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.attach_kind') }}</label>
                        <select name="kind" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                            @foreach(HealthVisitAttachment::KINDS as $kind)
                                <option value="{{ $kind }}">{{ __('health.attach_kind_' . $kind) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex-1 min-w-[180px]">
                        <label class="block text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.attach_caption') }}</label>
                        <input type="text" name="caption" maxlength="300"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                    </div>
                    <button type="submit" class="px-4 py-2 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-xs font-black transition">{{ __('health.attach_upload') }}</button>
                </form>
                <p class="text-[11px] text-gray-400 mt-2">{{ __('health.attach_hint') }}</p>
            @endunless
        </div>

        {{-- ── Previous encounters ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-base font-black">{{ __('health.visit_previous') }}</h2>
            </div>
            @if($history->isEmpty())
                <p class="px-5 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.patient_no_visits') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($history as $past)
                        <div class="px-5 py-3 text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-xs font-black text-teal-700 dark:text-teal-300">{{ $past->visit_no }}</span>
                                <span class="font-bold">{{ Carbon::parse($past->visit_date)->format('d M Y') }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $past->doctor?->name }}</span>
                                <a href="{{ route('health.clinical.visit', $past->id) }}"
                                   class="ms-auto text-xs font-bold text-teal-700 dark:text-teal-300 hover:underline">{{ __('health.open') }}</a>
                            </div>
                            @if($past->diagnosis)
                                <p class="text-xs mt-0.5"><span class="text-gray-500 dark:text-gray-400">{{ __('health.visit_diagnosis') }}:</span> {{ \Illuminate\Support\Str::limit($past->diagnosis, 160) }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-health-layout>
