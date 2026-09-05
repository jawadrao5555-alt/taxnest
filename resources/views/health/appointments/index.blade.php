@php
    use App\Models\HealthAppointment;
    use App\Models\HealthVisit;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Js;

    $scrub = function ($value) {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', (string) $value);
        return $clean === false ? '' : $clean;
    };

    $doctorPayload = $doctors->map(fn ($d) => [
        'id' => (int) $d->id,
        'name' => $scrub($d->name),
        'fee' => (string) $d->consultation_fee,
        'follow_up_fee' => (string) $d->follow_up_fee,
    ])->values()->all();
@endphp
<x-health-layout>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="{
            booking: false,
            kind: 'walkin',
            doctors: {{ Js::from($doctorPayload) }},
            patientQuery: '',
            patientResults: [],
            chosen: null,
            searching: false,
            async searchPatients() {
                if (this.patientQuery.trim().length < 2) { this.patientResults = []; return; }
                this.searching = true;
                try {
                    const r = await fetch('{{ route('health.appointments.patient-search', [], false) }}?q=' + encodeURIComponent(this.patientQuery), {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!r.ok) { this.patientResults = []; return; }
                    const data = await r.json();
                    this.patientResults = data.patients || [];
                } catch (e) {
                    this.patientResults = [];
                } finally {
                    this.searching = false;
                }
            },
            choose(p) { this.chosen = p; this.patientResults = []; this.patientQuery = p.name; },
            openBooking(kind) { this.kind = kind; this.booking = true; }
         }">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.appointments_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.appointments_subtitle') }}</p>
            </div>
            @if($canManage)
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" @click="openBooking('walkin')"
                            class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                        {{ __('health.appt_new_walkin') }}
                    </button>
                    <button type="button" @click="openBooking('scheduled')"
                            class="px-4 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        {{ __('health.appt_new_booking') }}
                    </button>
                </div>
            @endif
        </div>

        {{-- ── Day summary ── --}}
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
            @foreach([
                'total' => 'health.appt_count_total',
                'waiting' => 'health.appt_count_waiting',
                'in_consultation' => 'health.appt_count_in_consultation',
                'completed' => 'health.appt_count_completed',
                'no_show' => 'health.appt_count_no_show',
            ] as $key => $label)
                <div class="health-tile rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 px-4 py-3">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400">{{ __($label) }}</p>
                    <p class="text-2xl font-black mt-0.5">{{ $counts[$key] }}</p>
                </div>
            @endforeach
        </div>

        {{-- ── Filters ── --}}
        <form method="GET" action="{{ route('health.appointments') }}"
              class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.date') }}</label>
                <input type="date" name="date" value="{{ $date }}"
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
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.status') }}</label>
                <select name="status" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    <option value="">{{ __('health.all') }}</option>
                    @foreach(HealthAppointment::STATUSES as $s)
                        <option value="{{ $s }}" @selected($statusFilter === $s)>{{ __('health.appt_status_' . $s) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">{{ __('health.apply') }}</button>
            <a href="{{ route('health.appointments') }}" class="px-3 py-2.5 text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">{{ __('health.today') }}</a>
        </form>

        {{-- ── Booking form ── --}}
        @if($canManage)
            <div x-show="booking" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.appointments.store') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="kind" :value="kind">

                    <h2 class="text-base font-black"
                        x-text="kind === 'walkin' ? '{{ __('health.appt_new_walkin') }}' : '{{ __('health.appt_new_booking') }}'"></h2>

                    {{-- Patient picker: same search the register uses, so the desk
                         never has to guess which spelling was saved. --}}
                    <div class="relative">
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient') }} *</label>
                        <input type="text" x-model="patientQuery" @input.debounce.300ms="searchPatients()"
                               placeholder="{{ __('health.patient_search_hint') }}"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        <input type="hidden" name="health_patient_id" :value="chosen ? chosen.id : ''" required>

                        <div x-show="patientResults.length" x-cloak
                             class="absolute z-20 mt-1 w-full rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-lg max-h-64 overflow-y-auto">
                            <template x-for="p in patientResults" :key="p.id">
                                <button type="button" @click="choose(p)"
                                        class="w-full text-start px-4 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-700 text-sm border-b border-gray-100 dark:border-gray-700 last:border-0">
                                    <span class="font-mono text-xs font-black text-teal-700 dark:text-teal-300" x-text="p.mrn"></span>
                                    <span class="font-bold ms-2" x-text="p.name"></span>
                                    <span class="text-gray-400 ms-2 text-xs" x-text="p.phone"></span>
                                    <span class="text-gray-400 ms-2 text-xs" x-text="p.age"></span>
                                </button>
                            </template>
                        </div>

                        <p class="text-[11px] mt-1" :class="chosen ? 'text-teal-700 dark:text-teal-300' : 'text-gray-400'"
                           x-text="chosen ? '{{ __('health.appt_patient_chosen') }} ' + chosen.mrn : '{{ __('health.appt_pick_patient') }}'"></p>
                        <a href="{{ route('health.patients.create') }}" class="text-[11px] font-bold text-teal-700 dark:text-teal-300 hover:underline">{{ __('health.appt_register_new') }}</a>
                    </div>

                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.doctor') }} *</label>
                            <select name="health_doctor_id" required
                                    class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                <option value="">—</option>
                                @foreach($doctors as $doctor)
                                    <option value="{{ $doctor->id }}">{{ $doctor->name }}@if($doctor->specialty) — {{ $doctor->specialty }}@endif</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.date') }} *</label>
                            <input type="date" name="appointment_date" required value="{{ $date }}"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div x-show="kind === 'scheduled'">
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.time') }} *</label>
                            <input type="time" name="appointment_time"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_branch') }}</label>
                            <select name="branch_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                <option value="">{{ __('health.dept_branch_all') }}</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected((int) $defaultBranchId === (int) $branch->id)>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.doctor_department') }}</label>
                            <select name="health_department_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                                <option value="">—</option>
                                @foreach($departments as $department)
                                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="sm:col-span-2 lg:col-span-3">
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.appt_reason') }}</label>
                            <input type="text" name="reason" maxlength="500"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                    </div>

                    <label x-show="kind === 'scheduled'" class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="check_in_now" value="1"
                               class="rounded border-gray-300 dark:border-gray-600 text-teal-600 focus:ring-teal-500">
                        {{ __('health.appt_check_in_now') }}
                    </label>

                    <div class="flex items-center gap-2">
                        <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">{{ __('health.save') }}</button>
                        <button type="button" @click="booking = false" class="px-4 py-2.5 rounded-xl text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">{{ __('health.cancel') }}</button>
                    </div>
                </form>
            </div>
        @endif

        {{-- ── Queue ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($appointments->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.appt_none') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($appointments as $appointment)
                        <div class="px-5 py-4" x-data="{ actions: false }">
                            <div class="flex flex-wrap items-center gap-3">
                                <div class="w-12 shrink-0 text-center">
                                    @if($appointment->token_no)
                                        <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-teal-100 dark:bg-teal-900/40 text-teal-800 dark:text-teal-200 font-black">{{ $appointment->token_no }}</span>
                                    @else
                                        <span class="text-xs font-mono text-gray-400">{{ $appointment->appointment_time ? Carbon::parse($appointment->appointment_time)->format('h:i') : '—' }}</span>
                                    @endif
                                </div>

                                <div class="flex-1 min-w-[200px]">
                                    <p class="text-sm font-black">
                                        <a href="{{ route('health.patients.show', $appointment->health_patient_id) }}" class="hover:underline">
                                            {{ $appointment->patient?->name ?? '—' }}
                                        </a>
                                        <span class="ms-1.5 font-mono text-[11px] text-teal-700 dark:text-teal-300">{{ $appointment->patient?->mrn }}</span>
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                        {{ $appointment->doctor?->name ?? '—' }}
                                        @if($appointment->doctor?->room) &middot; {{ $appointment->doctor->room }} @endif
                                        &middot; {{ __('health.appt_kind_' . $appointment->kind) }}
                                        @if($appointment->reason) &middot; {{ \Illuminate\Support\Str::limit($appointment->reason, 60) }} @endif
                                    </p>
                                </div>

                                <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide {{ \App\Models\HealthAppointment::statusClasses($appointment->status) }}">
                                    {{ __(\App\Models\HealthAppointment::statusLabelKey($appointment->status)) }}
                                </span>

                                @if($appointment->visit)
                                    <span class="text-[11px] font-bold px-2 py-0.5 rounded
                                        {{ $appointment->visit->fee_status === 'paid' ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300' : 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300' }}">
                                        {{ __('health.fee') }} {{ number_format((float) $appointment->visit->net_fee, 0) }}
                                        &middot; {{ __('health.fee_status_' . $appointment->visit->fee_status) }}
                                    </span>
                                @endif

                                @if($canManage)
                                    <button type="button" @click="actions = !actions"
                                            class="px-3 py-2 rounded-xl border border-gray-300 dark:border-gray-600 text-xs font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                        {{ __('health.actions') }}
                                    </button>
                                @endif
                            </div>

                            @if($canManage)
                                <div x-show="actions" x-cloak class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700 grid lg:grid-cols-2 gap-5">

                                    {{-- Check in (and capture the fee) --}}
                                    @if(!$appointment->health_visit_id && $appointment->isOpen())
                                        <form method="POST" action="{{ route('health.appointments.check-in', $appointment->id) }}" class="space-y-3">
                                            @csrf
                                            <p class="text-xs font-black uppercase tracking-wide text-gray-400">{{ __('health.appt_check_in') }}</p>
                                            <div class="grid grid-cols-2 gap-2">
                                                <select name="visit_type" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                                    <option value="">{{ __('health.visit_type_auto') }}</option>
                                                    @foreach(HealthVisit::TYPES as $t)
                                                        <option value="{{ $t }}">{{ __('health.visit_type_' . $t) }}</option>
                                                    @endforeach
                                                </select>
                                                <input type="number" step="0.01" min="0" name="fee_amount"
                                                       placeholder="{{ __('health.fee_amount') }}"
                                                       class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                                <input type="number" step="0.01" min="0" name="concession_amount"
                                                       placeholder="{{ __('health.fee_concession') }}"
                                                       class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                                <input type="text" name="concession_reason" maxlength="300"
                                                       placeholder="{{ __('health.fee_concession_reason') }}"
                                                       class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                            </div>
                                            <p class="text-[11px] text-gray-400">{{ __('health.appt_fee_auto_hint') }}</p>
                                            <button type="submit" class="px-4 py-2 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-xs font-black transition">
                                                {{ __('health.appt_check_in') }}
                                            </button>
                                        </form>
                                    @endif

                                    {{-- Fee, once the encounter exists --}}
                                    @if($appointment->visit)
                                        <form method="POST" action="{{ route('health.appointments.fee', $appointment->visit->id) }}" class="space-y-3">
                                            @csrf
                                            <p class="text-xs font-black uppercase tracking-wide text-gray-400">{{ __('health.fee_record') }}</p>
                                            <div class="grid grid-cols-2 gap-2">
                                                <input type="number" step="0.01" min="0" name="fee_amount" required
                                                       value="{{ $appointment->visit->fee_amount }}"
                                                       class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                                <input type="number" step="0.01" min="0" name="concession_amount"
                                                       value="{{ $appointment->visit->concession_amount }}"
                                                       class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                                <input type="text" name="concession_reason" maxlength="300"
                                                       value="{{ $appointment->visit->concession_reason }}"
                                                       placeholder="{{ __('health.fee_concession_reason') }}"
                                                       class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                                <select name="fee_status" required class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                                    @foreach(HealthVisit::FEE_STATUSES as $fs)
                                                        <option value="{{ $fs }}" @selected($appointment->visit->fee_status === $fs)>{{ __('health.fee_status_' . $fs) }}</option>
                                                    @endforeach
                                                </select>
                                                <select name="payment_method" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                                    <option value="">—</option>
                                                    @foreach(HealthVisit::PAYMENT_METHODS as $pm)
                                                        <option value="{{ $pm }}" @selected($appointment->visit->payment_method === $pm)>{{ __('health.pay_' . $pm) }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <button type="submit" class="px-4 py-2 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-xs font-black transition">{{ __('health.save') }}</button>
                                        </form>
                                    @endif

                                    {{-- Reschedule / cancel / no-show --}}
                                    <div class="space-y-3">
                                        @if(!$appointment->health_visit_id && $appointment->isOpen())
                                            <form method="POST" action="{{ route('health.appointments.reschedule', $appointment->id) }}" class="space-y-2">
                                                @csrf
                                                <p class="text-xs font-black uppercase tracking-wide text-gray-400">{{ __('health.appt_reschedule') }}</p>
                                                <div class="grid grid-cols-3 gap-2">
                                                    <input type="date" name="appointment_date" required value="{{ Carbon::parse($appointment->appointment_date)->toDateString() }}"
                                                           class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                                    <input type="time" name="appointment_time" value="{{ $appointment->appointment_time ? Carbon::parse($appointment->appointment_time)->format('H:i') : '' }}"
                                                           class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                                    <select name="health_doctor_id" required class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                                        @foreach($doctors as $doctor)
                                                            <option value="{{ $doctor->id }}" @selected((int) $appointment->health_doctor_id === (int) $doctor->id)>{{ $doctor->name }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <button type="submit" class="px-4 py-2 rounded-xl border border-gray-300 dark:border-gray-600 text-xs font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">{{ __('health.appt_reschedule') }}</button>
                                            </form>
                                        @endif

                                        <div class="flex flex-wrap items-center gap-2">
                                            @if($appointment->isOpen())
                                                <form method="POST" action="{{ route('health.appointments.cancel', $appointment->id) }}" class="flex items-center gap-2">
                                                    @csrf
                                                    <input type="text" name="cancel_reason" maxlength="300" placeholder="{{ __('health.appt_cancel_reason') }}"
                                                           class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                                    <button type="submit" class="px-4 py-2 rounded-xl border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300 text-xs font-bold hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                                                        {{ __('health.appt_cancel') }}
                                                    </button>
                                                </form>
                                            @endif

                                            @if(!$appointment->health_visit_id && $appointment->status === HealthAppointment::STATUS_BOOKED)
                                                <form method="POST" action="{{ route('health.appointments.no-show', $appointment->id) }}">
                                                    @csrf
                                                    <button type="submit" class="px-4 py-2 rounded-xl border border-gray-300 dark:border-gray-600 text-xs font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                                        {{ __('health.appt_mark_no_show') }}
                                                    </button>
                                                </form>
                                            @endif

                                            @if($appointment->visit)
                                                <a href="{{ route('health.clinical.visit', $appointment->visit->id) }}"
                                                   class="px-4 py-2 rounded-xl border border-gray-300 dark:border-gray-600 text-xs font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                                    {{ __('health.appt_open_visit') }} {{ $appointment->visit->visit_no }}
                                                </a>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-health-layout>
