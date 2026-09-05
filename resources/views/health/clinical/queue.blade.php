@php
    use Illuminate\Support\Carbon;
@endphp
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.clinical_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.clinical_subtitle') }}</p>
            </div>
        </div>

        <form method="GET" action="{{ route('health.clinical') }}"
              class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.date') }}</label>
                <input type="date" name="date" value="{{ $date }}"
                       class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.doctor') }}</label>
                <select name="doctor_id" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    <option value="0">{{ __('health.all') }}</option>
                    @foreach($doctors as $doctor)
                        <option value="{{ $doctor->id }}" @selected($doctorFilter === (int) $doctor->id)>
                            {{ $doctor->name }}@if(in_array((int) $doctor->id, $ownDoctorIds, true)) — {{ __('health.clinical_my_queue') }}@endif
                        </option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">{{ __('health.apply') }}</button>
        </form>

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($visits->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.clinical_queue_empty') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($visits as $visit)
                        <div class="px-5 py-4 flex flex-wrap items-center gap-3">
                            <div class="w-20 shrink-0">
                                <span class="text-xs font-mono font-black text-teal-700 dark:text-teal-300">{{ $visit->visit_no }}</span>
                            </div>

                            <div class="flex-1 min-w-[200px]">
                                <p class="text-sm font-black">
                                    {{ $visit->patient?->name ?? '—' }}
                                    <span class="ms-1.5 font-mono text-[11px] text-gray-500 dark:text-gray-400">{{ $visit->patient?->mrn }}</span>
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    {{ $visit->patient?->age_label ?: '—' }}
                                    @if($visit->patient?->gender) &middot; {{ __('health.gender_' . $visit->patient->gender) }} @endif
                                    &middot; {{ $visit->doctor?->name }}
                                    @if($visit->department) &middot; {{ $visit->department->name }} @endif
                                </p>
                                @if($visit->patient?->allergies)
                                    <p class="text-[11px] font-bold text-red-600 dark:text-red-400 mt-0.5">
                                        {{ __('health.patient_allergies') }}: {{ \Illuminate\Support\Str::limit($visit->patient->allergies, 80) }}
                                    </p>
                                @endif
                            </div>

                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                {{ __(\App\Models\HealthVisit::typeLabelKey($visit->visit_type)) }}
                            </span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide {{ \App\Models\HealthVisit::statusClasses($visit->status) }}">
                                {{ __(\App\Models\HealthVisit::statusLabelKey($visit->status)) }}
                            </span>
                            @if($visit->hasVitals())
                                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300">
                                    {{ __('health.vitals_done') }}
                                </span>
                            @endif

                            <a href="{{ route('health.clinical.visit', $visit->id) }}"
                               class="px-4 py-2 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-xs font-black transition">
                                {{ __('health.open') }}
                            </a>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-health-layout>
