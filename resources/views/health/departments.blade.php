@php use App\Models\HealthDepartment; @endphp
<x-health-layout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="{ form: false, editing: null, values: { name: '', code: '', type: 'opd', description: '', branch_id: '' },
                   openNew() { this.editing = null; this.values = { name: '', code: '', type: 'opd', description: '', branch_id: '' }; this.form = true; },
                   openEdit(d) { this.editing = d.id; this.values = { name: d.name, code: d.code || '', type: d.type, description: d.description || '', branch_id: d.branch_id || '' }; this.form = true; } }">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.departments_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.departments_subtitle') }}</p>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">
                    {{ __('health.dept_limit_label') }}: {{ $departmentCount }}@if($departmentLimit >= 0) / {{ $departmentLimit }}@else / {{ __('health.unlimited') }}@endif
                </span>
                {{-- The package limit is enforced server-side too; hiding the button
                     is a courtesy, never the guard. --}}
                @if($departmentLimit < 0 || $departmentCount < $departmentLimit)
                    <button type="button" @click="openNew()"
                            class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                        {{ __('health.dept_add') }}
                    </button>
                @endif
            </div>
        </div>

        {{-- ── Create / edit form ── --}}
        <div x-show="form" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
            <form method="POST" :action="editing ? '{{ url('/health/departments') }}/' + editing : '{{ url('/health/departments') }}'" class="space-y-4">
                @csrf
                <template x-if="editing"><input type="hidden" name="_method" value="PUT"></template>

                <h2 class="text-base font-black" x-text="editing ? '{{ __('health.dept_edit') }}' : '{{ __('health.dept_add') }}'"></h2>

                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.dept_name') }}</label>
                        <input type="text" name="name" x-model="values.name" required
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.dept_code') }}</label>
                        <input type="text" name="code" x-model="values.code" maxlength="32"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.dept_type') }}</label>
                        <select name="type" x-model="values.type"
                                class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            @foreach($types as $type)
                                <option value="{{ $type }}">{{ __(HealthDepartment::typeLabelKey($type)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.dept_branch') }}</label>
                        <select name="branch_id" x-model="values.branch_id"
                                class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                            <option value="">{{ __('health.dept_branch_all') }}</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.dept_description') }}</label>
                        <input type="text" name="description" x-model="values.description" maxlength="500"
                               class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    </div>
                </div>

                <input type="hidden" name="is_active" value="1">

                <div class="flex items-center gap-2">
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                        {{ __('health.dept_save') }}
                    </button>
                    <button type="button" @click="form = false" class="px-4 py-2.5 rounded-xl text-sm font-bold text-gray-500 dark:text-gray-400 hover:underline">
                        {{ __('health.cancel') }}
                    </button>
                </div>
            </form>
        </div>

        {{-- ── List ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($departments->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.dept_none') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($departments as $department)
                        <div class="px-5 py-4 flex flex-wrap items-center gap-3">
                            <div class="flex-1 min-w-[200px]">
                                <p class="text-sm font-black">
                                    {{ $department->name }}
                                    @if($department->code)
                                        <span class="ms-1.5 text-[10px] font-bold px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">{{ $department->code }}</span>
                                    @endif
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    {{ __(HealthDepartment::typeLabelKey($department->type)) }}
                                    &middot; {{ $department->branch?->name ?? __('health.dept_branch_all') }}
                                    @if($department->description) &middot; {{ $department->description }} @endif
                                </p>
                            </div>

                            <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide
                                         {{ $department->is_active ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300' : 'bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300' }}">
                                {{ $department->is_active ? __('health.dept_active') : __('health.dept_inactive') }}
                            </span>

                            {{-- Built above the attribute on purpose: a multi-line
                                 @json([...]) inside a Blade directive attribute
                                 breaks Blade's bracket matching and 500s the page.
                                 Js::from does the JS-context escaping, and the
                                 UTF-8 scrub keeps one malformed department name
                                 from killing the whole Alpine component. --}}
                            @php
                                $deptText = function ($value) {
                                    $value = (string) ($value ?? '');

                                    return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                                };
                                try {
                                    $editPayload = \Illuminate\Support\Js::from([
                                        'id'          => (int) $department->id,
                                        'name'        => $deptText($department->name),
                                        'code'        => $deptText($department->code),
                                        'type'        => $deptText($department->type),
                                        'description' => $deptText($department->description),
                                        'branch_id'   => $department->branch_id ? (int) $department->branch_id : '',
                                    ]);
                                } catch (\Throwable $e) {
                                    $editPayload = \Illuminate\Support\Js::from(['id' => (int) $department->id]);
                                }
                            @endphp
                            <button type="button"
                                    @click="openEdit({{ $editPayload }})"
                                    class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-600 text-xs font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                {{ __('health.edit') }}
                            </button>

                            {{-- Deactivate, never delete: other healthcare records are
                                 filed under a department and must keep resolving. --}}
                            @if($department->is_active)
                                <form method="POST" action="{{ url('/health/departments/' . $department->id . '/deactivate') }}"
                                      onsubmit="return confirm('{{ __('health.confirm') }}');">
                                    @csrf
                                    <button type="submit" class="px-3 py-1.5 rounded-lg border border-red-200 dark:border-red-800 text-xs font-bold text-red-700 dark:text-red-300 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                                        {{ __('health.dept_deactivate') }}
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ url('/health/departments/' . $department->id . '/reactivate') }}">
                                    @csrf
                                    <button type="submit" class="px-3 py-1.5 rounded-lg border border-emerald-200 dark:border-emerald-800 text-xs font-bold text-emerald-700 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 transition">
                                        {{ __('health.dept_reactivate') }}
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-health-layout>
