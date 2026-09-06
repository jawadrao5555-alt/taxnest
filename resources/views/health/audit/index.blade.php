{{--
    The one-click audit workspace.

    The whole screen is one question: "show me what is worth looking at in this
    period". Presets first, because an owner who has to fill in a date range
    before they can find out whether anything is wrong will simply never run an
    audit. The filters below the presets are there for the second press, once
    the first one has pointed somewhere.
--}}
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.audit_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.audit_subtitle') }}</p>
            </div>
            <a href="{{ route('health.audit.trail') }}"
               class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-sm font-bold transition">
                {{ __('health.audit_open_trail') }}
            </a>
        </div>

        {{-- ══ What this screen is, and is not ══ --}}
        <div class="rounded-2xl bg-teal-50 dark:bg-teal-900/20 border border-teal-200 dark:border-teal-800 p-4">
            <p class="text-sm text-teal-900 dark:text-teal-100 leading-relaxed">{{ __('health.audit_disclaimer') }}</p>
        </div>

        {{-- ══ Run an audit ══ --}}
        <form method="POST" action="{{ route('health.audit.run') }}"
              x-data="{ preset: 'last_30', filters: false }"
              class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 space-y-4">
            @csrf

            <div>
                <h2 class="text-base font-black">{{ __('health.audit_run_heading') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.audit_run_hint') }}</p>
            </div>

            {{-- Presets. One press is the feature; everything else is optional. --}}
            <div class="flex flex-wrap gap-2">
                @foreach($presets as $key)
                    <label class="cursor-pointer">
                        <input type="radio" name="preset" value="{{ $key }}" class="sr-only peer"
                               x-model="preset" @if($key === 'last_30') checked @endif>
                        <span class="inline-block px-3.5 py-2 rounded-xl text-xs font-black border transition
                                     border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200
                                     peer-checked:bg-teal-700 peer-checked:text-white peer-checked:border-teal-700">
                            {{ __('health.audit_preset_' . $key) }}
                        </span>
                    </label>
                @endforeach
            </div>

            {{-- Custom dates appear only when they are the answer. --}}
            <div x-show="preset === 'custom'" x-cloak class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.audit_date_from') }}</label>
                    <input type="date" name="date_from" value="{{ $defaultRange[0] }}"
                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.audit_date_to') }}</label>
                    <input type="date" name="date_to" value="{{ $defaultRange[1] }}"
                           class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                </div>
            </div>

            <button type="button" @click="filters = !filters"
                    class="text-xs font-black text-teal-700 dark:text-teal-300 hover:underline">
                <span x-show="!filters">{{ __('health.audit_narrow_scope') }}</span>
                <span x-show="filters" x-cloak>{{ __('health.audit_hide_scope') }}</span>
            </button>

            <div x-show="filters" x-cloak class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.branch') }}</label>
                    <select name="branch_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        <option value="">{{ __('health.audit_all_branches') }}</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.department') }}</label>
                    <select name="health_department_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        <option value="">{{ __('health.audit_all_departments') }}</option>
                        @foreach($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.doctor') }}</label>
                    <select name="health_doctor_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        <option value="">{{ __('health.audit_all_doctors') }}</option>
                        @foreach($doctors as $doctor)
                            <option value="{{ $doctor->id }}">{{ $doctor->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.audit_staff_member') }}</label>
                    <select name="subject_user_id" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                        <option value="">{{ __('health.audit_all_staff') }}</option>
                        @foreach($staff as $member)
                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <button type="submit"
                    class="w-full sm:w-auto px-6 py-3 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                {{ __('health.audit_run_now') }}
            </button>
        </form>

        {{-- ══ The most recent answer, in one line ══ --}}
        @if($latest)
            <a href="{{ route('health.audit.show', $latest->id) }}"
               class="block rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 hover:border-teal-400 dark:hover:border-teal-600 transition">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.audit_latest') }}</p>
                        <p class="text-base font-black mt-1">
                            {{ $latest->date_from->format('d M Y') }} — {{ $latest->date_to->format('d M Y') }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            {{ __('health.audit_run_by', ['name' => $latest->actor_name ?: '—', 'at' => optional($latest->completed_at)->format('d M Y H:i') ?: '—']) }}
                        </p>
                    </div>
                    <div class="flex items-center gap-5">
                        <div class="text-center">
                            <p class="text-2xl font-black {{ $latest->findings_critical ? 'text-red-600 dark:text-red-400' : 'text-gray-400' }}">{{ $latest->findings_critical }}</p>
                            <p class="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.audit_sev_critical') }}</p>
                        </div>
                        <div class="text-center">
                            <p class="text-2xl font-black {{ $latest->findings_warning ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400' }}">{{ $latest->findings_warning }}</p>
                            <p class="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.audit_sev_warning') }}</p>
                        </div>
                        <div class="text-center">
                            <p class="text-2xl font-black text-gray-500 dark:text-gray-300">{{ $latest->findings_info }}</p>
                            <p class="text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.audit_sev_info') }}</p>
                        </div>
                    </div>
                </div>
            </a>
        @endif

        {{-- ══ Earlier runs ══ --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-base font-black">{{ __('health.audit_history') }}</h2>
            </div>

            @if($runs->isEmpty())
                <p class="px-5 py-8 text-sm text-center text-gray-500 dark:text-gray-400">{{ __('health.audit_no_runs') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-3 text-start font-bold">{{ __('health.audit_period') }}</th>
                                <th class="px-4 py-3 text-start font-bold">{{ __('health.audit_run_by_label') }}</th>
                                <th class="px-4 py-3 text-center font-bold">{{ __('health.audit_sev_critical') }}</th>
                                <th class="px-4 py-3 text-center font-bold">{{ __('health.audit_sev_warning') }}</th>
                                <th class="px-4 py-3 text-center font-bold">{{ __('health.audit_sev_info') }}</th>
                                <th class="px-4 py-3 text-center font-bold">{{ __('health.audit_risk_score') }}</th>
                                <th class="px-4 py-3 text-end font-bold"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($runs as $run)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <td class="px-4 py-3">
                                        <span class="font-bold">{{ $run->date_from->format('d M Y') }} — {{ $run->date_to->format('d M Y') }}</span>
                                        @if($run->rules_failed)
                                            <span class="ms-2 inline-block px-2 py-0.5 rounded-lg bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200 text-[10px] font-black">
                                                {{ __('health.audit_incomplete') }}
                                            </span>
                                        @endif
                                        @if($run->status !== 'ready')
                                            <span class="ms-2 inline-block px-2 py-0.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-[10px] font-black">
                                                {{ __('health.audit_status_' . $run->status) }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                        {{ $run->actor_name ?: '—' }}
                                        <span class="block text-xs text-gray-400">{{ optional($run->completed_at)->format('d M Y H:i') }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-center font-black {{ $run->findings_critical ? 'text-red-600 dark:text-red-400' : 'text-gray-400' }}">{{ $run->findings_critical }}</td>
                                    <td class="px-4 py-3 text-center font-black {{ $run->findings_warning ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400' }}">{{ $run->findings_warning }}</td>
                                    <td class="px-4 py-3 text-center font-black text-gray-500 dark:text-gray-300">{{ $run->findings_info }}</td>
                                    <td class="px-4 py-3 text-center font-black">{{ $run->risk_score }}</td>
                                    <td class="px-4 py-3 text-end">
                                        <a href="{{ route('health.audit.show', $run->id) }}"
                                           class="text-teal-700 dark:text-teal-300 font-bold hover:underline">{{ __('health.audit_open') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-health-layout>
