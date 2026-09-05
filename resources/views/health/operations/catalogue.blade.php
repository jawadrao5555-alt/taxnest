@php
    use Illuminate\Support\Js;

    $scrub = function ($value) {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', (string) $value);
        return $clean === false ? '' : $clean;
    };

    $procedurePayload = $procedures->map(fn ($p) => [
        'id' => (int) $p->id,
        'name' => $scrub($p->name),
        'code' => $scrub($p->code),
        'category' => $scrub($p->category),
        'description' => $scrub($p->description),
        'health_department_id' => $p->health_department_id ? (int) $p->health_department_id : '',
        'base_price' => (string) $p->base_price,
        'is_package' => (bool) $p->is_package,
        'package_price' => $p->package_price === null ? '' : (string) $p->package_price,
        'package_includes' => $scrub($p->package_includes),
        'default_anaesthesia' => $p->default_anaesthesia ?: '',
        'estimated_minutes' => (int) ($p->estimated_minutes ?: 60),
        'pre_op_checklist' => $scrub($p->pre_op_checklist),
    ])->values()->all();

    $blankProcedure = [
        'id' => null, 'name' => '', 'code' => '', 'category' => '', 'description' => '',
        'health_department_id' => '', 'base_price' => '0', 'is_package' => false, 'package_price' => '',
        'package_includes' => '', 'default_anaesthesia' => '', 'estimated_minutes' => 60, 'pre_op_checklist' => '',
    ];

    $theatrePayload = $theatres->map(fn ($t) => [
        'id' => (int) $t->id,
        'name' => $scrub($t->name),
        'code' => $scrub($t->code),
        'branch_id' => $t->branch_id ? (int) $t->branch_id : '',
        'turnaround_minutes' => (int) $t->turnaround_minutes,
        'notes' => $scrub($t->notes),
    ])->values()->all();

    $blankTheatre = ['id' => null, 'name' => '', 'code' => '', 'branch_id' => '', 'turnaround_minutes' => 30, 'notes' => ''];
@endphp
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="{
            procedures: {{ Js::from($procedurePayload) }},
            theatres: {{ Js::from($theatrePayload) }},
            blankProcedure: {{ Js::from($blankProcedure) }},
            blankTheatre: {{ Js::from($blankTheatre) }},
            form: null,
            values: {},
            openProcedure(id) {
                const found = id ? this.procedures.find(p => p.id === id) : null;
                this.values = Object.assign({}, found || this.blankProcedure);
                this.form = 'procedure';
            },
            openTheatre(id) {
                const found = id ? this.theatres.find(t => t.id === id) : null;
                this.values = Object.assign({}, found || this.blankTheatre);
                this.form = 'theatre';
            }
         }">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.ot_catalogue') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.ot_catalogue_subtitle') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('health.operations') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.ot_title') }}</a>
                @if($mayManage)
                    <button type="button" @click="openTheatre(null)" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.theatre_add') }}</button>
                    <button type="button" @click="openProcedure(null)" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">{{ __('health.procedure_add') }}</button>
                @endif
            </div>
        </div>

        @if($mayManage)
            {{-- ── procedure form ── --}}
            <div x-show="form === 'procedure'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" :action="values.id ? '{{ url('/health/operations/procedures') }}/' + values.id : '{{ url('/health/operations/procedures') }}'" class="space-y-4">
                    @csrf
                    <template x-if="values.id"><input type="hidden" name="_method" value="PUT"></template>
                    <h2 class="text-base font-black" x-text="values.id ? '{{ __('health.procedure_edit') }}' : '{{ __('health.procedure_add') }}'"></h2>

                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <label class="block lg:col-span-2">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.name') }}</span>
                            <input type="text" name="name" x-model="values.name" required maxlength="200"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.code') }}</span>
                            <input type="text" name="code" x-model="values.code" maxlength="40"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.category') }}</span>
                            <input type="text" name="category" x-model="values.category" maxlength="80"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.department') }}</span>
                            <select name="health_department_id" x-model="values.health_department_id" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">—</option>
                                @foreach($departments as $department)
                                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_base_price') }}</span>
                            <input type="number" step="0.01" min="0" name="base_price" x-model="values.base_price" required
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_anaesthesia') }}</span>
                            <select name="default_anaesthesia" x-model="values.default_anaesthesia" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">—</option>
                                @foreach($anaesthesiaTypes as $type)
                                    <option value="{{ $type }}">{{ __('health.anaesthesia_' . $type) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_minutes') }}</span>
                            <input type="number" min="0" max="1440" name="estimated_minutes" x-model="values.estimated_minutes"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                    </div>

                    <div class="rounded-xl bg-gray-50 dark:bg-gray-900 p-4 space-y-3">
                        <label class="flex items-center gap-2 text-sm font-bold">
                            <input type="hidden" name="is_package" value="0">
                            <input type="checkbox" name="is_package" value="1" x-model="values.is_package" class="rounded">
                            <span>{{ __('health.op_is_package') }}</span>
                        </label>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.op_package_hint') }}</p>
                        <div class="grid sm:grid-cols-2 gap-3" x-show="values.is_package" x-cloak>
                            <label class="block">
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_package_price') }}</span>
                                <input type="number" step="0.01" min="0" name="package_price" x-model="values.package_price"
                                       class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm">
                            </label>
                            <label class="block">
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_package_includes') }}</span>
                                <input type="text" name="package_includes" x-model="values.package_includes" maxlength="1000"
                                       class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm">
                            </label>
                        </div>
                    </div>

                    <label class="block">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_checklist') }}</span>
                        <textarea name="pre_op_checklist" x-model="values.pre_op_checklist" rows="4" maxlength="2000"
                                  class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm"></textarea>
                        <span class="text-[11px] text-gray-500">{{ __('health.op_checklist_hint') }}</span>
                    </label>

                    <label class="block">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.description') }}</span>
                        <textarea name="description" x-model="values.description" rows="2" maxlength="1000"
                                  class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm"></textarea>
                    </label>

                    <div class="flex gap-2">
                        <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 text-white text-sm font-bold">{{ __('health.save') }}</button>
                        <button type="button" @click="form = null" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.cancel') }}</button>
                    </div>
                </form>
            </div>

            {{-- ── theatre form ── --}}
            <div x-show="form === 'theatre'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" :action="values.id ? '{{ url('/health/operations/theatres') }}/' + values.id : '{{ url('/health/operations/theatres') }}'" class="space-y-4">
                    @csrf
                    <template x-if="values.id"><input type="hidden" name="_method" value="PUT"></template>
                    <h2 class="text-base font-black" x-text="values.id ? '{{ __('health.theatre_edit') }}' : '{{ __('health.theatre_add') }}'"></h2>

                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.name') }}</span>
                            <input type="text" name="name" x-model="values.name" required maxlength="120"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.code') }}</span>
                            <input type="text" name="code" x-model="values.code" maxlength="40"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.branch') }}</span>
                            <select name="branch_id" x-model="values.branch_id" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">{{ __('health.all_branches') }}</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_turnaround') }}</span>
                            <input type="number" min="0" max="480" name="turnaround_minutes" x-model="values.turnaround_minutes" required
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.notes') }}</span>
                        <textarea name="notes" x-model="values.notes" rows="2" maxlength="500"
                                  class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm"></textarea>
                    </label>

                    <div class="flex gap-2">
                        <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 text-white text-sm font-bold">{{ __('health.save') }}</button>
                        <button type="button" @click="form = null" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.cancel') }}</button>
                    </div>
                </form>
            </div>
        @endif

        {{-- ── theatres ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-base font-black">{{ __('health.theatres') }}</h2>
            </div>
            <div class="p-5 grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @forelse($theatres as $theatre)
                    <div class="rounded-xl border border-gray-200 dark:border-gray-700 p-4 {{ $theatre->is_active ? '' : 'opacity-50' }}">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-black">{{ $theatre->name }}</span>
                            @if($theatre->code)<span class="text-xs font-mono text-gray-500">{{ $theatre->code }}</span>@endif
                        </div>
                        <p class="text-xs text-gray-500 mt-1">
                            {{ $theatre->branch->name ?? __('health.all_branches') }}
                            · {{ __('health.op_turnaround') }}: {{ $theatre->turnaround_minutes }}
                        </p>
                        @if($theatre->notes)<p class="text-xs text-gray-500 mt-1">{{ $theatre->notes }}</p>@endif
                        @if($mayManage)
                            <div class="flex gap-3 mt-2">
                                <button type="button" @click="openTheatre({{ $theatre->id }})" class="text-xs font-bold text-teal-700 dark:text-teal-300">{{ __('health.edit') }}</button>
                                <form method="POST" action="{{ route('health.operations.theatres.toggle', $theatre->id) }}">
                                    @csrf
                                    <button type="submit" class="text-xs font-bold text-gray-500">{{ $theatre->is_active ? __('health.deactivate') : __('health.reactivate') }}</button>
                                </form>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-gray-500 col-span-full">{{ __('health.no_theatres') }}</p>
                @endforelse
            </div>
        </div>

        {{-- ── procedures ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="text-base font-black">{{ __('health.procedures') }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900 text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.name') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.category') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.department') }}</th>
                            <th class="text-start px-4 py-2 font-bold">{{ __('health.op_anaesthesia') }}</th>
                            <th class="text-end px-4 py-2 font-bold">{{ __('health.op_minutes') }}</th>
                            <th class="text-end px-4 py-2 font-bold">{{ __('health.op_price') }}</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($procedures as $procedure)
                            <tr class="{{ $procedure->is_active ? '' : 'opacity-50' }}">
                                <td class="px-4 py-2.5">
                                    <span class="font-bold">{{ $procedure->name }}</span>
                                    @if($procedure->is_package)
                                        <span class="ms-1 px-2 py-0.5 rounded-lg text-[10px] font-bold bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-200">{{ __('health.op_package') }}</span>
                                    @endif
                                    @if($procedure->code)<span class="block text-[11px] font-mono text-gray-500">{{ $procedure->code }}</span>@endif
                                </td>
                                <td class="px-4 py-2.5">{{ $procedure->category ?: '—' }}</td>
                                <td class="px-4 py-2.5">{{ $procedure->department->name ?? '—' }}</td>
                                <td class="px-4 py-2.5">{{ $procedure->default_anaesthesia ? __('health.anaesthesia_' . $procedure->default_anaesthesia) : '—' }}</td>
                                <td class="px-4 py-2.5 text-end">{{ $procedure->estimated_minutes ?: '—' }}</td>
                                <td class="px-4 py-2.5 text-end font-bold">{{ number_format($procedure->effectivePrice(), 2) }}</td>
                                <td class="px-4 py-2.5 text-end">
                                    @if($mayManage)
                                        <div class="flex gap-3 justify-end">
                                            <button type="button" @click="openProcedure({{ $procedure->id }})" class="text-xs font-bold text-teal-700 dark:text-teal-300">{{ __('health.edit') }}</button>
                                            <form method="POST" action="{{ route('health.operations.procedures.toggle', $procedure->id) }}">
                                                @csrf
                                                <button type="submit" class="text-xs font-bold text-gray-500">{{ $procedure->is_active ? __('health.deactivate') : __('health.reactivate') }}</button>
                                            </form>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-10 text-center text-sm text-gray-500">{{ __('health.no_procedures') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-health-layout>
