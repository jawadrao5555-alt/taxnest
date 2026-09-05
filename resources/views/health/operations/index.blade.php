@php
    use App\Models\HealthOperation;
    use App\Models\HealthProcedure;
    use Illuminate\Support\Js;

    $scrub = function ($value) {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', (string) $value);
        return $clean === false ? '' : $clean;
    };

    // The catalogue drives the booking form: picking a procedure fills the
    // price, the anaesthesia and the slot length, so the desk does not retype
    // what the hospital already priced.
    $procedurePayload = $procedures->map(fn ($p) => [
        'id' => (int) $p->id,
        'name' => $scrub($p->name),
        'price' => number_format($p->effectivePrice(), 2, '.', ''),
        'anaesthesia' => $p->default_anaesthesia ?: '',
        'minutes' => (int) ($p->estimated_minutes ?: 60),
    ])->values()->all();

    $statusChip = [
        'scheduled'   => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
        'in_progress' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
        'completed'   => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        'cancelled'   => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
        'postponed'   => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
    ];
@endphp
<x-health-layout>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="{
            bookForm: false,
            procedures: {{ Js::from($procedurePayload) }},
            values: { health_procedure_id: '', title: '', price: '', anaesthesia_type: '', scheduled_start: '', scheduled_end: '' },
            pickProcedure() {
                const found = this.procedures.find(p => String(p.id) === String(this.values.health_procedure_id));
                if (!found) { return; }
                this.values.title = found.name;
                this.values.price = found.price;
                this.values.anaesthesia_type = found.anaesthesia;
                if (this.values.scheduled_start) { this.fillEnd(found.minutes); }
            },
            fillEnd(minutes) {
                const start = new Date(this.values.scheduled_start);
                if (isNaN(start.getTime())) { return; }
                const end = new Date(start.getTime() + (minutes || 60) * 60000);
                const pad = n => String(n).padStart(2, '0');
                this.values.scheduled_end = end.getFullYear() + '-' + pad(end.getMonth() + 1) + '-' + pad(end.getDate())
                    + 'T' + pad(end.getHours()) + ':' + pad(end.getMinutes());
            }
         }">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.ot_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.ot_subtitle') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('health.operations.catalogue') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.ot_catalogue') }}</a>
                @if($mayManage)
                    <button type="button" @click="bookForm = !bookForm"
                            class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                        {{ __('health.op_schedule') }}
                    </button>
                @endif
            </div>
        </div>

        {{-- ── status strip ── --}}
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
            @foreach(HealthOperation::STATUSES as $s)
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_status_' . $s) }}</p>
                    <p class="text-2xl font-black mt-0.5">{{ $statusCounts[$s] ?? 0 }}</p>
                </div>
            @endforeach
        </div>

        {{-- ── booking ── --}}
        @if($mayManage)
            <div x-show="bookForm" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.operations.store') }}" class="space-y-4">
                    @csrf
                    <h2 class="text-base font-black">{{ __('health.op_schedule') }}</h2>

                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <label class="block lg:col-span-2">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_admission') }}</span>
                            <select name="health_admission_id" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">{{ __('health.op_daycare') }}</option>
                                @foreach($openAdmissions as $adm)
                                    <option value="{{ $adm->id }}">{{ $adm->admission_no }} — {{ $adm->patient->name ?? '' }}</option>
                                @endforeach
                            </select>
                            <span class="text-[11px] text-gray-500">{{ __('health.op_admission_hint') }}</span>
                        </label>
                        <label class="block lg:col-span-2">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.patient_mrn_or_id') }}</span>
                            <input type="number" name="health_patient_id" min="1" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            <span class="text-[11px] text-gray-500">{{ __('health.op_patient_hint') }}</span>
                        </label>

                        <label class="block lg:col-span-2">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.procedure') }}</span>
                            <select name="health_procedure_id" x-model="values.health_procedure_id" @change="pickProcedure()"
                                    class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">—</option>
                                @foreach($procedures as $procedure)
                                    <option value="{{ $procedure->id }}">{{ $procedure->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block lg:col-span-2">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_title') }}</span>
                            <input type="text" name="title" x-model="values.title" maxlength="200"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>

                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.theatre') }}</span>
                            <select name="health_operation_theatre_id" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">—</option>
                                @foreach($theatres as $theatre)
                                    <option value="{{ $theatre->id }}">{{ $theatre->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_urgency') }}</span>
                            <select name="urgency" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach(HealthOperation::URGENCIES as $urgency)
                                    <option value="{{ $urgency }}">{{ __('health.op_urgency_' . $urgency) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_start') }}</span>
                            <input type="datetime-local" name="scheduled_start" x-model="values.scheduled_start" @change="pickProcedure()"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_end') }}</span>
                            <input type="datetime-local" name="scheduled_end" x-model="values.scheduled_end"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>

                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_surgeon') }}</span>
                            <select name="primary_surgeon_id" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">—</option>
                                @foreach($doctors as $doctor)
                                    <option value="{{ $doctor->id }}">{{ $doctor->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_anaesthetist') }}</span>
                            <select name="anaesthetist_id" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">—</option>
                                @foreach($doctors as $doctor)
                                    <option value="{{ $doctor->id }}">{{ $doctor->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_anaesthesia') }}</span>
                            <select name="anaesthesia_type" x-model="values.anaesthesia_type" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">—</option>
                                @foreach(HealthProcedure::ANAESTHESIA_TYPES as $type)
                                    <option value="{{ $type }}">{{ __('health.anaesthesia_' . $type) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_price') }}</span>
                            <input type="number" step="0.01" min="0" name="price" x-model="values.price"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block lg:col-span-2">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_consent') }}</span>
                            <input type="text" name="consent_reference" maxlength="120" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
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
                    </div>

                    <div class="flex gap-2">
                        <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.save') }}</button>
                        <button type="button" @click="bookForm = false" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.cancel') }}</button>
                    </div>
                </form>
            </div>
        @endif

        {{-- ── the list ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 dark:border-gray-700">
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('health.operations', ['date' => $date->toDateString()]) }}"
                       class="px-3 py-2 rounded-xl text-xs font-bold {{ $view === 'day' ? 'bg-teal-700 text-white' : 'bg-gray-100 dark:bg-gray-700' }}">
                        {{ __('health.ot_day_list') }}
                    </a>
                    <a href="{{ route('health.operations', ['view' => 'pending']) }}"
                       class="px-3 py-2 rounded-xl text-xs font-bold {{ $view === 'pending' ? 'bg-teal-700 text-white' : 'bg-gray-100 dark:bg-gray-700' }}">
                        {{ __('health.ot_pending') }}
                    </a>
                </div>
                <form method="GET" class="flex flex-wrap gap-2">
                    <input type="hidden" name="view" value="{{ $view }}">
                    <input type="date" name="date" value="{{ $date->toDateString() }}" onchange="this.form.submit()"
                           class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <select name="theatre_id" onchange="this.form.submit()" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        <option value="">{{ __('health.all_theatres') }}</option>
                        @foreach($theatres as $theatre)
                            <option value="{{ $theatre->id }}" @selected(request('theatre_id') == $theatre->id)>{{ $theatre->name }}</option>
                        @endforeach
                    </select>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900 text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.op_start') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.op_no') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.patient') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.procedure') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.theatre') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.op_surgeon') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($operations as $operation)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="px-4 py-2.5 text-xs whitespace-nowrap">
                                    {{ $operation->scheduled_start?->format('d M H:i') ?? __('health.op_unslotted') }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('health.operations.show', $operation->id) }}" class="font-black text-teal-700 dark:text-teal-300">{{ $operation->operation_no }}</a>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="font-bold">{{ $operation->patient->name ?? '—' }}</span>
                                    @if($operation->admission)
                                        <span class="block text-[11px] text-gray-500">{{ $operation->admission->admission_no }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5">{{ $operation->procedure->name ?? $operation->title }}</td>
                                <td class="px-4 py-2.5">{{ $operation->theatre->name ?? '—' }}</td>
                                <td class="px-4 py-2.5">{{ $operation->surgeon->name ?? '—' }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="px-2 py-0.5 rounded-lg text-[11px] font-bold {{ $statusChip[$operation->status] ?? '' }}">
                                        {{ __('health.op_status_' . $operation->status) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-10 text-center text-sm text-gray-500">{{ __('health.no_operations') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-health-layout>
