@php
    use App\Models\HealthDoctorSlot;
    use Illuminate\Support\Js;

    // Built here, not inside an x-data attribute: a multi-line array literal in
    // a Blade directive attribute breaks Blade's bracket matching and 500s the
    // page, and Js::from does the JS-context escaping properly.
    $scrub = function ($value) {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', (string) $value);
        return $clean === false ? '' : $clean;
    };

    $doctorPayload = $doctors->map(fn ($d) => [
        'id' => (int) $d->id,
        'name' => $scrub($d->name),
        'specialty' => $scrub($d->specialty),
        'qualification' => $scrub($d->qualification),
        'registration_no' => $scrub($d->registration_no),
        'phone' => $scrub($d->phone),
        'email' => $scrub($d->email),
        'room' => $scrub($d->room),
        'consultation_fee' => (string) $d->consultation_fee,
        'follow_up_fee' => (string) $d->follow_up_fee,
        'follow_up_days' => (int) $d->follow_up_days,
        'slot_minutes' => (int) $d->slot_minutes,
        'branch_id' => $d->branch_id ? (int) $d->branch_id : '',
        'health_department_id' => $d->health_department_id ? (int) $d->health_department_id : '',
        'user_id' => $d->user_id ? (int) $d->user_id : '',
    ])->values()->all();

    $blank = [
        'id' => null, 'name' => '', 'specialty' => '', 'qualification' => '', 'registration_no' => '',
        'phone' => '', 'email' => '', 'room' => '', 'consultation_fee' => '0', 'follow_up_fee' => '0',
        'follow_up_days' => 7, 'slot_minutes' => 15, 'branch_id' => '', 'health_department_id' => '', 'user_id' => '',
    ];
@endphp
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="{
            doctors: {{ Js::from($doctorPayload) }},
            blank: {{ Js::from($blank) }},
            form: false,
            values: {{ Js::from($blank) }},
            openNew() { this.values = Object.assign({}, this.blank); this.form = true; },
            openEdit(id) {
                const found = this.doctors.find(d => d.id === id);
                this.values = found ? Object.assign({}, found) : Object.assign({}, this.blank);
                this.form = true;
            }
         }">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.doctors_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.doctors_subtitle') }}</p>
            </div>
            <button type="button" @click="openNew()"
                    class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                {{ __('health.doctor_add') }}
            </button>
        </div>

        {{-- ── Create / edit ── --}}
        <div x-show="form" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
            <form method="POST" :action="values.id ? '{{ url('/health/doctors') }}/' + values.id : '{{ url('/health/doctors') }}'" class="space-y-4">
                @csrf
                <template x-if="values.id"><input type="hidden" name="_method" value="PUT"></template>

                <h2 class="text-base font-black" x-text="values.id ? '{{ __('health.doctor_edit') }}' : '{{ __('health.doctor_add') }}'"></h2>

                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.doctor_name') }} *</label>
                        <input type="text" name="name" x-model="values.name" required maxlength="255"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.doctor_specialty') }}</label>
                        <input type="text" name="specialty" x-model="values.specialty" maxlength="120"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.doctor_qualification') }}</label>
                        <input type="text" name="qualification" x-model="values.qualification" maxlength="200"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.doctor_registration') }}</label>
                        <input type="text" name="registration_no" x-model="values.registration_no" maxlength="60"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.doctor_phone') }}</label>
                        <input type="text" name="phone" x-model="values.phone" maxlength="32"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.doctor_email') }}</label>
                        <input type="email" name="email" x-model="values.email" maxlength="255"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.doctor_room') }}</label>
                        <input type="text" name="room" x-model="values.room" maxlength="60"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_branch') }}</label>
                        <select name="branch_id" x-model="values.branch_id"
                                class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            <option value="">{{ __('health.dept_branch_all') }}</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.doctor_department') }}</label>
                        <select name="health_department_id" x-model="values.health_department_id"
                                class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            <option value="">—</option>
                            @foreach($departments as $department)
                                <option value="{{ $department->id }}">{{ $department->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                    <h3 class="text-sm font-black mb-1">{{ __('health.doctor_fee_schedule') }}</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('health.doctor_fee_hint') }}</p>
                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.doctor_consultation_fee') }} *</label>
                            <input type="number" step="0.01" min="0" name="consultation_fee" x-model="values.consultation_fee" required
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.doctor_follow_up_fee') }} *</label>
                            <input type="number" step="0.01" min="0" name="follow_up_fee" x-model="values.follow_up_fee" required
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.doctor_follow_up_days') }} *</label>
                            <input type="number" min="0" max="365" name="follow_up_days" x-model="values.follow_up_days" required
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.doctor_slot_minutes') }} *</label>
                            <input type="number" min="5" max="240" name="slot_minutes" x-model="values.slot_minutes" required
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </div>
                    </div>
                </div>

                <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.doctor_link_user') }}</label>
                    <select name="user_id" x-model="values.user_id"
                            class="w-full sm:w-1/2 rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        <option value="">{{ __('health.doctor_no_login') }}</option>
                        @foreach($linkableUsers as $u)
                            <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>
                        @endforeach
                    </select>
                    <p class="text-[11px] text-gray-400 mt-1">{{ __('health.doctor_link_user_hint') }}</p>
                </div>

                <div class="flex items-center gap-2">
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">{{ __('health.save') }}</button>
                    <button type="button" @click="form = false" class="px-4 py-2.5 rounded-xl text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">{{ __('health.cancel') }}</button>
                </div>
            </form>
        </div>

        {{-- ── List ── --}}
        <div class="space-y-4">
            @if($doctors->isEmpty())
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                    {{ __('health.doctor_none') }}
                </div>
            @endif

            @foreach($doctors as $doctor)
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5"
                     x-data="{ slots: false }">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-black">
                                {{ $doctor->name }}
                                @if($doctor->specialty)
                                    <span class="ms-1.5 text-[10px] font-bold px-1.5 py-0.5 rounded bg-teal-100 dark:bg-teal-900/40 text-teal-700 dark:text-teal-300">{{ $doctor->specialty }}</span>
                                @endif
                                @unless($doctor->is_active)
                                    <span class="ms-1.5 text-[10px] font-black uppercase px-1.5 py-0.5 rounded bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300">{{ __('health.dept_inactive') }}</span>
                                @endunless
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                {{ $doctor->qualification ?: '—' }}
                                @if($doctor->room) &middot; {{ __('health.doctor_room') }} {{ $doctor->room }} @endif
                                @if($doctor->branch) &middot; {{ $doctor->branch->name }} @endif
                                @if($doctor->department) &middot; {{ $doctor->department->name }} @endif
                            </p>
                            <p class="text-xs mt-1">
                                <span class="font-bold">{{ __('health.doctor_consultation_fee') }}:</span> {{ number_format((float) $doctor->consultation_fee, 0) }}
                                &middot;
                                <span class="font-bold">{{ __('health.doctor_follow_up_fee') }}:</span> {{ number_format((float) $doctor->follow_up_fee, 0) }}
                                <span class="text-gray-400">({{ __('health.doctor_within_days', ['days' => $doctor->follow_up_days]) }})</span>
                            </p>
                            @if($doctor->user)
                                <p class="text-[11px] text-teal-700 dark:text-teal-300 mt-1">{{ __('health.doctor_linked_to', ['name' => $doctor->user->name]) }}</p>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" @click="slots = !slots"
                                    class="px-3 py-2 rounded-xl border border-gray-300 dark:border-gray-600 text-xs font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                {{ __('health.doctor_availability') }}
                            </button>
                            <button type="button" @click="openEdit({{ (int) $doctor->id }})"
                                    class="px-3 py-2 rounded-xl border border-gray-300 dark:border-gray-600 text-xs font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                {{ __('health.edit') }}
                            </button>
                            <form method="POST" action="{{ route('health.doctors.toggle-active', $doctor->id) }}">
                                @csrf
                                <button type="submit" class="px-3 py-2 rounded-xl border border-gray-300 dark:border-gray-600 text-xs font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                    {{ $doctor->is_active ? __('health.deactivate') : __('health.reactivate') }}
                                </button>
                            </form>
                        </div>
                    </div>

                    {{-- Weekly sittings --}}
                    <div class="mt-3">
                        @if($doctor->slots->isNotEmpty())
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($doctor->slots->sortBy('weekday') as $slot)
                                    <span class="text-[11px] font-bold px-2 py-1 rounded-lg bg-gray-100 dark:bg-gray-700">
                                        {{ __(\App\Models\HealthDoctorSlot::weekdayLabelKey($slot->weekday)) }} {{ $slot->time_range }}
                                        @if($slot->max_tokens > 0) &middot; {{ $slot->max_tokens }} @endif
                                    </span>
                                @endforeach
                            </div>
                        @else
                            <p class="text-xs text-gray-400">{{ __('health.doctor_no_slots') }}</p>
                        @endif
                    </div>

                    <div x-show="slots" x-cloak class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <form method="POST" action="{{ route('health.doctors.slots', $doctor->id) }}"
                              x-data="{ rows: {{ Js::from($doctor->slots->sortBy('weekday')->map(fn ($s) => [
                                    'weekday' => (int) $s->weekday,
                                    'start_time' => substr((string) $s->start_time, 0, 5),
                                    'end_time' => substr((string) $s->end_time, 0, 5),
                                    'branch_id' => $s->branch_id ? (int) $s->branch_id : '',
                                    'slot_minutes' => $s->slot_minutes ? (int) $s->slot_minutes : '',
                                    'max_tokens' => (int) $s->max_tokens,
                              ])->values()->all()) }} }"
                              class="space-y-3">
                            @csrf
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.doctor_slots_hint') }}</p>

                            <template x-for="(row, i) in rows" :key="i">
                                <div class="grid grid-cols-2 sm:grid-cols-6 gap-2 items-end">
                                    <select :name="'slots[' + i + '][weekday]'" x-model="row.weekday"
                                            class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                        @foreach(HealthDoctorSlot::WEEKDAYS as $wd)
                                            <option value="{{ $wd }}">{{ __('health.weekday_' . $wd) }}</option>
                                        @endforeach
                                    </select>
                                    <input type="time" :name="'slots[' + i + '][start_time]'" x-model="row.start_time" required
                                           class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                    <input type="time" :name="'slots[' + i + '][end_time]'" x-model="row.end_time" required
                                           class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                    <select :name="'slots[' + i + '][branch_id]'" x-model="row.branch_id"
                                            class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                        <option value="">{{ __('health.dept_branch_all') }}</option>
                                        @foreach($branches as $branch)
                                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                        @endforeach
                                    </select>
                                    <input type="number" min="0" max="500" :name="'slots[' + i + '][max_tokens]'" x-model="row.max_tokens"
                                           placeholder="{{ __('health.doctor_max_tokens') }}"
                                           class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                    <button type="button" @click="rows.splice(i, 1)"
                                            class="px-3 py-2 rounded-xl text-xs font-bold text-red-600 hover:underline">{{ __('health.remove') }}</button>
                                </div>
                            </template>

                            <div class="flex items-center gap-2">
                                <button type="button"
                                        @click="rows.push({ weekday: 1, start_time: '09:00', end_time: '13:00', branch_id: '', slot_minutes: '', max_tokens: 0 })"
                                        class="px-3 py-2 rounded-xl border border-gray-300 dark:border-gray-600 text-xs font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                    {{ __('health.doctor_add_slot') }}
                                </button>
                                <button type="submit" class="px-4 py-2 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-xs font-black transition">{{ __('health.save') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-health-layout>
