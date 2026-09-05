@php use App\Models\HealthPrescription; @endphp
{{--
    Prescriptions.

    This is a DISPENSING queue, not a clinical record — the patient identity is
    a snapshot taken at intake and no diagnosis is stored. Open prescriptions
    come first because they are the pharmacy's actual work list.
--}}
<x-health-layout>
    @php
        $medicineList = $medicines->map(fn ($m) => [
            'id' => (int) $m->id,
            'label' => mb_convert_encoding(trim($m->name . ' ' . ($m->strength ?? '')), 'UTF-8', 'UTF-8'),
            'dosage' => mb_convert_encoding((string) ($m->default_dosage ?? ''), 'UTF-8', 'UTF-8'),
        ])->values();
    @endphp

    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="pharmacyRx({{ \Illuminate\Support\Js::from($medicineList) }})">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.ph_rx_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.ph_rx_subtitle') }}</p>
            </div>
            @if($canDispense)
                <button type="button" @click="form = !form"
                        class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                    {{ __('health.ph_rx_add') }}
                </button>
            @endif
        </div>

        {{-- ── Intake ── --}}
        @if($canDispense)
            <div x-show="form" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.pharmacy.prescriptions.store') }}" class="space-y-4">
                    @csrf
                    <h2 class="text-base font-black">{{ __('health.ph_rx_add') }}</h2>

                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_patient_name') }}</label>
                            <input type="text" name="patient_name" required maxlength="190"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_patient_mr') }}</label>
                            <input type="text" name="patient_mr_no" maxlength="64"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_patient_phone') }}</label>
                            <input type="text" name="patient_phone" maxlength="32"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_patient_age') }}</label>
                                <input type="text" name="patient_age" maxlength="16"
                                       class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_patient_gender') }}</label>
                                <select name="patient_gender" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                    <option value="">—</option>
                                    <option value="male">{{ __('health.ph_gender_male') }}</option>
                                    <option value="female">{{ __('health.ph_gender_female') }}</option>
                                    <option value="other">{{ __('health.ph_gender_other') }}</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_doctor') }}</label>
                            <input type="text" name="doctor_name" maxlength="190"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_rx_date') }}</label>
                            <input type="date" name="prescribed_on" value="{{ now()->toDateString() }}"
                                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        @if($departments->isNotEmpty())
                            <div>
                                <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_department') }}</label>
                                <select name="health_department_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                    <option value="">—</option>
                                    @foreach($departments as $department)
                                        <option value="{{ $department->id }}">{{ $department->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        @if($isMultiBranch)
                            <div>
                                <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_branch') }}</label>
                                <select name="branch_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                    <option value="">—</option>
                                    @foreach($branches as $branch)
                                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>

                    {{-- Lines. A medicine that is not in the catalogue may still be
                         written down by name: the prescription is what the doctor
                         wrote, and refusing to record it would lose the request. --}}
                    <div class="space-y-2">
                        <template x-for="(line, index) in lines" :key="line.key">
                            <div class="grid sm:grid-cols-2 lg:grid-cols-6 gap-2 items-end p-3 rounded-xl border border-gray-200 dark:border-gray-700">
                                <div class="lg:col-span-2">
                                    <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_medicine') }}</label>
                                    <select :name="'items[' + index + '][medicine_id]'" x-model.number="line.medicine_id" @change="applyDefaults(index)"
                                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                        <option value="">{{ __('health.ph_free_text') }}</option>
                                        <template x-for="m in medicines" :key="m.id">
                                            <option :value="m.id" x-text="m.label"></option>
                                        </template>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_written_as') }}</label>
                                    <input type="text" :name="'items[' + index + '][medicine_name]'" x-model="line.medicine_name" maxlength="190"
                                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_qty') }}</label>
                                    <input type="number" step="0.001" min="0.001" required
                                           :name="'items[' + index + '][quantity]'" x-model.number="line.quantity"
                                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_dosage') }}</label>
                                    <input type="text" :name="'items[' + index + '][dosage]'" x-model="line.dosage" maxlength="190" placeholder="1 x 3"
                                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                </div>
                                <div class="flex items-end gap-2">
                                    <div class="flex-1">
                                        <label class="block text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.ph_duration') }}</label>
                                        <input type="text" :name="'items[' + index + '][duration]'" x-model="line.duration" maxlength="64" placeholder="5 din"
                                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                    </div>
                                    <button type="button" @click="removeLine(index)" x-show="lines.length > 1"
                                            class="px-2.5 py-2 rounded-lg border border-red-200 dark:border-red-800 text-red-600 dark:text-red-300 text-xs font-bold">✕</button>
                                </div>
                                <div class="sm:col-span-2 lg:col-span-6">
                                    <input type="text" :name="'items[' + index + '][instructions]'" x-model="line.instructions" maxlength="255"
                                           placeholder="{{ __('health.ph_instructions') }}"
                                           class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                </div>
                            </div>
                        </template>
                        <button type="button" @click="addLine()"
                                class="px-4 py-2 rounded-xl border border-dashed border-gray-300 dark:border-gray-600 text-xs font-bold text-gray-600 dark:text-gray-300 hover:border-teal-400 transition">
                            + {{ __('health.ph_add_line') }}
                        </button>
                    </div>

                    <input type="text" name="general_instructions" maxlength="500" placeholder="{{ __('health.ph_f_notes') }}"
                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">

                    <div class="flex items-center gap-2">
                        <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">{{ __('health.ph_save') }}</button>
                        <button type="button" @click="form = false" class="px-4 py-2.5 rounded-xl text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">{{ __('health.cancel') }}</button>
                    </div>
                </form>
            </div>
        @endif

        {{-- ── Filter ── --}}
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <input type="text" name="q" value="{{ $search }}" placeholder="{{ __('health.ph_search_rx') }}"
                   class="flex-1 min-w-[180px] rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            <select name="status" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                @foreach(['open', 'dispensed', 'cancelled', 'all'] as $option)
                    <option value="{{ $option }}" @selected($status === $option)>{{ __('health.ph_rx_filter_' . $option) }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                {{ __('health.ph_apply') }}
            </button>
        </form>

        {{-- ── List ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($prescriptions->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.ph_rx_none') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($prescriptions as $prescription)
                        @php
                            $tone = match ($prescription->dispense_status) {
                                HealthPrescription::DISPENSE_DISPENSED => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-200',
                                HealthPrescription::DISPENSE_PARTIAL => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200',
                                HealthPrescription::DISPENSE_CANCELLED => 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200',
                                default => 'bg-sky-100 dark:bg-sky-900/40 text-sky-800 dark:text-sky-200',
                            };
                        @endphp
                        <a href="{{ route('health.pharmacy.prescriptions.show', $prescription->id) }}"
                           class="px-5 py-4 flex flex-wrap items-center gap-3 hover:bg-gray-50 dark:hover:bg-gray-700/40 transition">
                            <div class="flex-1 min-w-[220px]">
                                <p class="text-sm font-black">
                                    {{ $prescription->prescription_no }}
                                    <span class="ms-1.5 text-[10px] font-black px-2 py-0.5 rounded-full uppercase {{ $tone }}">
                                        {{ __('health.rx_status_' . $prescription->dispense_status) }}
                                    </span>
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    {{ $prescription->patient_display_name }}
                                    @if($prescription->patient_display_mr) &middot; {{ $prescription->patient_display_mr }} @endif
                                    @if($prescription->doctor_display_name) &middot; {{ $prescription->doctor_display_name }} @endif
                                </p>
                            </div>
                            <div class="text-end">
                                <p class="text-sm font-bold">{{ $prescription->items_count }}</p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('health.ph_rx_lines') }}</p>
                            </div>
                            <div class="text-end min-w-[90px]">
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ ($prescription->prescribed_on ?? $prescription->issued_at) ? \Illuminate\Support\Carbon::parse($prescription->prescribed_on ?? $prescription->issued_at)->format('d-m-Y') : '' }}
                                </p>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        <div>{{ $prescriptions->links() }}</div>
    </div>

    <script>
        function pharmacyRx(medicines) {
            let counter = 0;
            const blank = () => ({ key: ++counter, medicine_id: '', medicine_name: '', quantity: '', dosage: '', duration: '', instructions: '' });

            return {
                medicines: medicines,
                form: false,
                lines: [blank()],
                addLine() { this.lines.push(blank()); },
                removeLine(index) { this.lines.splice(index, 1); if (!this.lines.length) this.lines.push(blank()); },
                applyDefaults(index) {
                    const line = this.lines[index];
                    const found = this.medicines.find((m) => m.id === Number(line.medicine_id));
                    if (!found) return;
                    if (!line.medicine_name) line.medicine_name = found.label;
                    if (!line.dosage) line.dosage = found.dosage;
                },
            };
        }
    </script>
</x-health-layout>
