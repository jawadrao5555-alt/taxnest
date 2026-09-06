@php
    use App\Models\HealthDoctorShareRule;
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <h1 class="text-xl font-black tracking-tight">{{ __('health.dsh_rules_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.dsh_rules_subtitle') }}</p>
        </div>

        @include('health.accounts.partials.nav')

        @unless($settings->doctor_shares_enabled)
            <div class="rounded-2xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-4 text-sm text-amber-900 dark:text-amber-200">
                {{ __('health.dsh_disabled_note') }}
                <a href="{{ route('health.accounts.settings') }}" class="font-bold underline">{{ __('health.acc_nav_settings') }}</a>
            </div>
        @endunless

        @if($canManage)
            {{-- Most specific rule wins: a rule naming this doctor AND this charge
                 category beats one naming only the department. Priority breaks a
                 genuine tie. Written down here because the alternative — an
                 accountant guessing which of four rules applied — is how share
                 disputes start. --}}
            <details class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
                <summary class="px-4 py-3 font-black cursor-pointer">{{ __('health.dsh_new_rule') }}</summary>
                <form method="POST" action="{{ route('health.accounts.share-rules.store') }}" class="p-4 pt-0 grid grid-cols-1 md:grid-cols-4 gap-3">
                    @csrf
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.name') }}</label>
                        <input name="name" required maxlength="150" value="{{ old('name') }}"
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.doctor') }}</label>
                        <select name="health_doctor_id" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            <option value="">{{ __('health.dsh_any_doctor') }}</option>
                            @foreach($doctors as $doctor)
                                <option value="{{ $doctor->id }}">{{ $doctor->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.department') }}</label>
                        <select name="health_department_id" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            <option value="">{{ __('health.dsh_any_department') }}</option>
                            @foreach($departments as $department)
                                <option value="{{ $department->id }}">{{ $department->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.category') }}</label>
                        <select name="charge_category" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            <option value="all">{{ __('health.dsh_any_category') }}</option>
                            @foreach($categories as $category)
                                <option value="{{ $category }}">{{ __('health.charge_cat_' . $category) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.dsh_basis') }}</label>
                        <select name="basis" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            @foreach($bases as $basis)
                                <option value="{{ $basis }}">{{ __('health.dsh_basis_' . $basis) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.dsh_value') }}</label>
                        <input type="number" step="0.01" min="0" name="value" required value="{{ old('value') }}"
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.dsh_base') }}</label>
                        <select name="base" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            @foreach($baseAmounts as $base)
                                <option value="{{ $base }}">{{ __('health.dsh_base_' . $base) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.dsh_effective_from') }}</label>
                        <input type="date" name="effective_from" value="{{ old('effective_from') }}"
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.dsh_effective_to') }}</label>
                        <input type="date" name="effective_to" value="{{ old('effective_to') }}"
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.dsh_priority') }}</label>
                        <input type="number" min="0" max="999" name="priority" value="{{ old('priority', 0) }}"
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <div class="md:col-span-4">
                        <button class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.save') }}</button>
                    </div>
                </form>
            </details>
        @endif

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2.5 text-start">{{ __('health.name') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.dsh_applies_to') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.dsh_share') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.dsh_window') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.dsh_priority') }}</th>
                            <th class="px-3 py-2.5 text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rules as $rule)
                            <tr class="border-t border-gray-100 dark:border-gray-700/60 {{ $rule->is_active ? '' : 'opacity-50' }}">
                                <td class="px-3 py-2.5 font-bold">{{ $rule->name }}</td>
                                <td class="px-3 py-2.5 text-xs text-gray-600 dark:text-gray-300">
                                    {{ $rule->doctor?->name ?? __('health.dsh_any_doctor') }}
                                    · {{ $rule->department?->name ?? __('health.dsh_any_department') }}
                                    · {{ $rule->charge_category === HealthDoctorShareRule::CATEGORY_ALL
                                            ? __('health.dsh_any_category')
                                            : __('health.charge_cat_' . $rule->charge_category) }}
                                </td>
                                <td class="px-3 py-2.5">
                                    <span class="font-bold">
                                        {{ $rule->basis === HealthDoctorShareRule::BASIS_PERCENT
                                            ? ((float) $rule->value) . '%'
                                            : $money($rule->value) }}
                                    </span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.dsh_base_' . $rule->base) }}</span>
                                </td>
                                <td class="px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ optional($rule->effective_from)->format('d M Y') ?? '—' }}
                                    &rarr;
                                    {{ optional($rule->effective_to)->format('d M Y') ?? '—' }}
                                </td>
                                <td class="px-3 py-2.5 text-end">{{ $rule->priority }}</td>
                                <td class="px-3 py-2.5 text-end">
                                    @if($canManage)
                                        <form method="POST" action="{{ route('health.accounts.share-rules.toggle', $rule->id) }}">
                                            @csrf
                                            <button class="text-xs font-bold text-gray-500 hover:text-rose-600">
                                                {{ $rule->is_active ? __('health.disable') : __('health.enable') }}
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('health.dsh_no_rules') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-health-layout>
