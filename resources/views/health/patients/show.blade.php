@php
    use App\Models\HealthAppointment;
    use App\Models\HealthVisit;
@endphp
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <a href="{{ route('health.patients') }}" class="text-xs font-bold text-teal-700 dark:text-teal-300 hover:underline">&larr; {{ __('health.nav_patients') }}</a>
        </div>

        {{-- ── Header card ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ $patient->name }}</h1>
                        <span class="font-mono text-sm font-black text-teal-700 dark:text-teal-300">{{ $patient->mrn }}</span>
                        @if($patient->is_confidential)
                            <span class="text-[10px] font-black uppercase px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300">{{ __('health.patient_confidential_tag') }}</span>
                        @endif
                        @unless($patient->is_active)
                            <span class="text-[10px] font-black uppercase px-2 py-0.5 rounded-full bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300">{{ __('health.patient_archived_label') }}</span>
                        @endunless
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        @if($patient->guardian_name){{ $patient->guardian_name }} &middot; @endif
                        {{ $patient->age_label ?: '—' }}
                        @if($patient->gender) &middot; {{ __('health.gender_' . $patient->gender) }} @endif
                        @if($patient->blood_group) &middot; {{ $patient->blood_group }} @endif
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if($canBook)
                        <a href="{{ route('health.appointments', ['patient_id' => $patient->id]) }}"
                           class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">{{ __('health.patient_book') }}</a>
                    @endif
                    @if($canManage)
                        <a href="{{ route('health.patients.edit', $patient->id) }}"
                           class="px-4 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 text-sm font-bold transition hover:bg-gray-50 dark:hover:bg-gray-700">{{ __('health.edit') }}</a>
                        <form method="POST" action="{{ route('health.patients.toggle-active', $patient->id) }}">
                            @csrf
                            <button type="submit" class="px-4 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 text-sm font-bold transition hover:bg-gray-50 dark:hover:bg-gray-700">
                                {{ $patient->is_active ? __('health.patient_archive') : __('health.patient_restore') }}
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-5 pt-5 border-t border-gray-200 dark:border-gray-700 text-sm">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400">{{ __('health.patient_phone') }}</p>
                    <p class="font-mono">{{ $patient->phone ?: '—' }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400">{{ __('health.patient_cnic') }}</p>
                    <p class="font-mono">{{ $patient->cnic ?: '—' }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400">{{ __('health.patient_city') }}</p>
                    <p>{{ $patient->city ?: '—' }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400">{{ __('health.patient_branch') }}</p>
                    <p>{{ $patient->branch?->name ?? __('health.dept_branch_all') }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400">{{ __('health.patient_ec_name') }}</p>
                    <p>{{ $patient->emergency_contact_name ?: '—' }}
                        @if($patient->emergency_contact_relation)<span class="text-gray-400">({{ $patient->emergency_contact_relation }})</span>@endif
                    </p>
                </div>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400">{{ __('health.patient_ec_phone') }}</p>
                    <p class="font-mono">{{ $patient->emergency_contact_phone ?: '—' }}</p>
                </div>
                <div class="sm:col-span-2">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400">{{ __('health.patient_consent') }}</p>
                    <p class="text-xs">
                        {{ $patient->consent_treatment ? '✔' : '✖' }} {{ __('health.consent_treatment_short') }} &middot;
                        {{ $patient->consent_share_reports ? '✔' : '✖' }} {{ __('health.consent_share_short') }} &middot;
                        {{ $patient->consent_contact ? '✔' : '✖' }} {{ __('health.consent_contact_short') }}
                    </p>
                </div>
            </div>

            @if($patient->allergies)
                <div class="mt-4 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3">
                    <p class="text-xs font-black uppercase tracking-wide text-red-700 dark:text-red-300">{{ __('health.patient_allergies') }}</p>
                    <p class="text-sm text-red-800 dark:text-red-200 mt-0.5">{{ $patient->allergies }}</p>
                </div>
            @endif
            @if($patient->chronic_conditions)
                <div class="mt-3 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 px-4 py-3">
                    <p class="text-xs font-black uppercase tracking-wide text-amber-700 dark:text-amber-300">{{ __('health.patient_chronic') }}</p>
                    <p class="text-sm text-amber-800 dark:text-amber-200 mt-0.5">{{ $patient->chronic_conditions }}</p>
                </div>
            @endif
        </div>

        @if($confidentialBlocked)
            <div class="rounded-2xl border-2 border-amber-400 bg-amber-50 dark:bg-amber-900/20 px-5 py-4">
                <p class="text-sm font-bold text-amber-800 dark:text-amber-200">{{ __('health.clinical_confidential_blocked') }}</p>
            </div>
        @endif

        {{-- ── Timeline ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-base font-black">{{ __('health.patient_timeline') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.patient_timeline_hint') }}</p>
            </div>

            @if($visits->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.patient_no_visits') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($visits as $visit)
                        <div class="px-5 py-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs font-black font-mono text-teal-700 dark:text-teal-300">{{ $visit->visit_no }}</span>
                                <span class="text-sm font-bold">{{ \Illuminate\Support\Carbon::parse($visit->visit_date)->format('d M Y') }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $visit->doctor?->name ?? '—' }}</span>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide {{ \App\Models\HealthVisit::statusClasses($visit->status) }}">
                                    {{ __(\App\Models\HealthVisit::statusLabelKey($visit->status)) }}
                                </span>
                                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                    {{ __(\App\Models\HealthVisit::typeLabelKey($visit->visit_type)) }}
                                </span>
                                @if($canSeeClinical)
                                    <a href="{{ route('health.clinical.visit', $visit->id) }}"
                                       class="ms-auto text-xs font-bold text-teal-700 dark:text-teal-300 hover:underline">{{ __('health.open') }}</a>
                                @endif
                            </div>
                            @if($canSeeClinical && ($visit->diagnosis || $visit->chief_complaint))
                                <p class="text-sm mt-1.5">
                                    @if($visit->chief_complaint)
                                        <span class="text-gray-500 dark:text-gray-400">{{ __('health.visit_complaint') }}:</span> {{ \Illuminate\Support\Str::limit($visit->chief_complaint, 140) }}
                                    @endif
                                </p>
                                @if($visit->diagnosis)
                                    <p class="text-sm mt-0.5">
                                        <span class="text-gray-500 dark:text-gray-400">{{ __('health.visit_diagnosis') }}:</span>
                                        <span class="font-bold">{{ \Illuminate\Support\Str::limit($visit->diagnosis, 160) }}</span>
                                    </p>
                                @endif
                            @endif
                            @if($visit->follow_up_date)
                                <p class="text-xs text-teal-700 dark:text-teal-300 mt-1">
                                    {{ __('health.visit_follow_up') }}: {{ \Illuminate\Support\Carbon::parse($visit->follow_up_date)->format('d M Y') }}
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ── Prescriptions ── --}}
        @if($canSeeClinical && $prescriptions->isNotEmpty())
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-base font-black">{{ __('health.presc_history') }}</h2>
                </div>
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($prescriptions as $prescription)
                        <div class="px-5 py-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs font-black font-mono text-teal-700 dark:text-teal-300">{{ $prescription->prescription_no }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $prescription->created_at?->format('d M Y') }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $prescription->doctor?->name }}</span>
                                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                    {{ __(\App\Models\HealthPrescription::statusLabelKey($prescription->status)) }}
                                </span>
                                <a href="{{ route('health.clinical.prescription.print', $prescription->id) }}" target="_blank" rel="noopener"
                                   class="ms-auto text-xs font-bold text-teal-700 dark:text-teal-300 hover:underline">{{ __('health.presc_print') }}</a>
                            </div>
                            <p class="text-xs text-gray-600 dark:text-gray-300 mt-1">
                                {{ $prescription->items->map(fn ($i) => $i->display_name)->implode(', ') }}
                            </p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ── Appointments ── --}}
        @if($appointments->isNotEmpty())
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-base font-black">{{ __('health.appt_history') }}</h2>
                </div>
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($appointments as $appointment)
                        <div class="px-5 py-3 flex flex-wrap items-center gap-3 text-sm">
                            <span class="font-bold">{{ \Illuminate\Support\Carbon::parse($appointment->appointment_date)->format('d M Y') }}</span>
                            @if($appointment->appointment_time)
                                <span class="text-gray-500 dark:text-gray-400 font-mono">{{ \Illuminate\Support\Carbon::parse($appointment->appointment_time)->format('h:i A') }}</span>
                            @endif
                            @if($appointment->token_no)
                                <span class="text-[10px] font-black px-1.5 py-0.5 rounded bg-teal-100 dark:bg-teal-900/40 text-teal-700 dark:text-teal-300">#{{ $appointment->token_no }}</span>
                            @endif
                            <span class="text-gray-500 dark:text-gray-400">{{ $appointment->doctor?->name }}</span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide {{ \App\Models\HealthAppointment::statusClasses($appointment->status) }}">
                                {{ __(\App\Models\HealthAppointment::statusLabelKey($appointment->status)) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-health-layout>
