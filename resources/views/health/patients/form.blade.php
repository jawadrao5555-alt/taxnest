@php
    use App\Models\HealthPatient;

    $editing = $patient !== null;
    // Whatever the duplicate check found on the last POST. Rendered as a list
    // reception must look at once before they may insist the person is new.
    $flagged = session('health_duplicates', []);
@endphp
<x-health-layout>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <a href="{{ $editing ? route('health.patients.show', $patient->id) : route('health.patients') }}"
               class="text-xs font-bold text-teal-700 dark:text-teal-300 hover:underline">&larr; {{ __('health.back') }}</a>
            <h1 class="text-xl sm:text-2xl font-black tracking-tight mt-1">
                {{ $editing ? __('health.patient_edit') : __('health.patient_register') }}
            </h1>
            @if($editing)
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5 font-mono">{{ $patient->mrn }}</p>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.patient_register_hint') }}</p>
            @endif
        </div>

        @if(!empty($flagged))
            <div class="rounded-2xl border-2 border-amber-400 bg-amber-50 dark:bg-amber-900/20 p-5 space-y-3">
                <p class="text-sm font-black text-amber-800 dark:text-amber-200">{{ __('health.patient_dup_warning') }}</p>
                <div class="space-y-2">
                    @foreach($flagged as $match)
                        <div class="flex flex-wrap items-center gap-3 text-sm">
                            <span class="font-mono font-black text-teal-700 dark:text-teal-300">{{ $match['mrn'] }}</span>
                            <span class="font-bold">{{ $match['name'] }}</span>
                            <span class="text-gray-500 dark:text-gray-400 font-mono">{{ $match['phone'] }}</span>
                            <span class="text-[11px] font-bold px-1.5 py-0.5 rounded bg-amber-200 dark:bg-amber-800 text-amber-900 dark:text-amber-100">
                                {{ __('health.dup_reason_' . $match['reason']) }}
                            </span>
                            <a href="{{ route('health.patients.show', $match['id']) }}"
                               class="text-xs font-bold text-teal-700 dark:text-teal-300 hover:underline">{{ __('health.patient_open_existing') }}</a>
                        </div>
                    @endforeach
                </div>
                <p class="text-xs text-amber-800 dark:text-amber-200">{{ __('health.patient_dup_confirm_hint') }}</p>
            </div>
        @endif

        <form method="POST"
              action="{{ $editing ? route('health.patients.update', $patient->id) : route('health.patients.store') }}"
              class="space-y-5">
            @csrf
            @if($editing) @method('PUT') @endif
            @if(!empty($flagged))
                {{-- Set once the desk has read the list above; the server refuses
                     to create the second file without it. --}}
                <input type="hidden" name="confirm_new" value="1">
            @endif

            {{-- ── Identity ── --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 space-y-4">
                <h2 class="text-base font-black">{{ __('health.patient_section_identity') }}</h2>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_name') }} *</label>
                        <input type="text" name="name" required maxlength="255"
                               value="{{ old('name', $patient->name ?? '') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_guardian') }}</label>
                        <input type="text" name="guardian_name" maxlength="255"
                               value="{{ old('guardian_name', $patient->guardian_name ?? '') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_gender') }}</label>
                        <select name="gender" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            <option value="">—</option>
                            @foreach(HealthPatient::GENDERS as $g)
                                <option value="{{ $g }}" @selected(old('gender', $patient->gender ?? '') === $g)>{{ __('health.gender_' . $g) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_dob') }}</label>
                        <input type="date" name="date_of_birth" max="{{ now()->toDateString() }}"
                               value="{{ old('date_of_birth', optional($patient->date_of_birth ?? null)->toDateString()) }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        <p class="text-[11px] text-gray-400 mt-1">{{ __('health.patient_dob_hint') }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_age_years') }}</label>
                            <input type="number" name="age_years" min="0" max="130"
                                   value="{{ old('age_years', $patient->age_years ?? '') }}"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_age_months') }}</label>
                            <input type="number" name="age_months" min="0" max="11"
                                   value="{{ old('age_months', $patient->age_months ?? '') }}"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_cnic') }}</label>
                        <input type="text" name="cnic" maxlength="20"
                               value="{{ old('cnic', $patient->cnic ?? '') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_phone') }}</label>
                        <input type="text" name="phone" maxlength="32"
                               value="{{ old('phone', $patient->phone ?? '') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_alt_phone') }}</label>
                        <input type="text" name="alt_phone" maxlength="32"
                               value="{{ old('alt_phone', $patient->alt_phone ?? '') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_email') }}</label>
                        <input type="email" name="email" maxlength="255"
                               value="{{ old('email', $patient->email ?? '') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_city') }}</label>
                        <input type="text" name="city" maxlength="100"
                               value="{{ old('city', $patient->city ?? '') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_address') }}</label>
                        <input type="text" name="address" maxlength="500"
                               value="{{ old('address', $patient->address ?? '') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_branch') }}</label>
                        <select name="branch_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            <option value="">{{ __('health.dept_branch_all') }}</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected((int) old('branch_id', $defaultBranchId) === (int) $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_marital') }}</label>
                        <select name="marital_status" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            <option value="">—</option>
                            @foreach(HealthPatient::MARITAL_STATUSES as $m)
                                <option value="{{ $m }}" @selected(old('marital_status', $patient->marital_status ?? '') === $m)>{{ __('health.marital_' . $m) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            {{-- ── Clinical background ── --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 space-y-4">
                <h2 class="text-base font-black">{{ __('health.patient_section_background') }}</h2>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_blood_group') }}</label>
                        <select name="blood_group" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            <option value="">—</option>
                            @foreach(HealthPatient::BLOOD_GROUPS as $bg)
                                <option value="{{ $bg }}" @selected(old('blood_group', $patient->blood_group ?? '') === $bg)>{{ $bg }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_allergies') }}</label>
                        <input type="text" name="allergies" maxlength="2000"
                               value="{{ old('allergies', $patient->allergies ?? '') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        <p class="text-[11px] text-gray-400 mt-1">{{ __('health.patient_allergies_hint') }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_chronic') }}</label>
                        <input type="text" name="chronic_conditions" maxlength="2000"
                               value="{{ old('chronic_conditions', $patient->chronic_conditions ?? '') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                </div>
            </div>

            {{-- ── Emergency contact ── --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 space-y-4">
                <h2 class="text-base font-black">{{ __('health.patient_section_emergency') }}</h2>
                <div class="grid sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_ec_name') }}</label>
                        <input type="text" name="emergency_contact_name" maxlength="255"
                               value="{{ old('emergency_contact_name', $patient->emergency_contact_name ?? '') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_ec_phone') }}</label>
                        <input type="text" name="emergency_contact_phone" maxlength="32"
                               value="{{ old('emergency_contact_phone', $patient->emergency_contact_phone ?? '') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_ec_relation') }}</label>
                        <input type="text" name="emergency_contact_relation" maxlength="60"
                               value="{{ old('emergency_contact_relation', $patient->emergency_contact_relation ?? '') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                </div>
            </div>

            {{-- ── Consent & privacy ── --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 space-y-4">
                <h2 class="text-base font-black">{{ __('health.patient_section_consent') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.patient_consent_hint') }}</p>

                @foreach([
                    'consent_treatment' => 'health.consent_treatment',
                    'consent_share_reports' => 'health.consent_share_reports',
                    'consent_contact' => 'health.consent_contact',
                ] as $field => $label)
                    <label class="flex items-start gap-3">
                        <input type="hidden" name="{{ $field }}" value="0">
                        <input type="checkbox" name="{{ $field }}" value="1"
                               @checked((bool) old($field, $patient->{$field} ?? false))
                               class="mt-0.5 rounded border-gray-300 dark:border-gray-600 text-teal-600 focus:ring-teal-500">
                        <span class="text-sm">{{ __($label) }}</span>
                    </label>
                @endforeach

                <label class="flex items-start gap-3 pt-2 border-t border-gray-200 dark:border-gray-700">
                    <input type="hidden" name="is_confidential" value="0">
                    <input type="checkbox" name="is_confidential" value="1"
                           @checked((bool) old('is_confidential', $patient->is_confidential ?? false))
                           class="mt-0.5 rounded border-gray-300 dark:border-gray-600 text-amber-600 focus:ring-amber-500">
                    <span class="text-sm">
                        <span class="font-black">{{ __('health.patient_confidential') }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.patient_confidential_hint') }}</span>
                    </span>
                </label>

                @if($editing && $patient->consent_recorded_at)
                    <p class="text-[11px] text-gray-400">
                        {{ __('health.patient_consent_recorded', ['at' => $patient->consent_recorded_at->format('d M Y, h:i A')]) }}
                    </p>
                @endif
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="px-6 py-3 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                    {{ $editing ? __('health.save') : __('health.patient_register') }}
                </button>
                <a href="{{ $editing ? route('health.patients.show', $patient->id) : route('health.patients') }}"
                   class="px-4 py-3 rounded-xl text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">{{ __('health.cancel') }}</a>
            </div>
        </form>
    </div>
</x-health-layout>
