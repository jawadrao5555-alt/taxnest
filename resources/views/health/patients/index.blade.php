<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.patients_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.patients_subtitle') }}</p>
            </div>
            @if($canManage)
                <a href="{{ route('health.patients.create') }}"
                   class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                    {{ __('health.patient_register') }}
                </a>
            @endif
        </div>

        {{-- Search is the point of this screen: a file nobody can find gets
             re-created, and then the patient has two half-histories. --}}
        <form method="GET" action="{{ route('health.patients') }}"
              class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[220px]">
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.patient_search') }}</label>
                <input type="search" name="q" value="{{ $q }}" autofocus
                       placeholder="{{ __('health.patient_search_hint') }}"
                       class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">{{ __('health.status') }}</label>
                <select name="status" class="rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 focus:border-teal-500 focus:ring-teal-500 text-sm">
                    <option value="active" @selected($status === 'active')>{{ __('health.patient_active') }}</option>
                    <option value="archived" @selected($status === 'archived')>{{ __('health.patient_archived_label') }}</option>
                    <option value="all" @selected($status === 'all')>{{ __('health.all') }}</option>
                </select>
            </div>
            <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-black transition">
                {{ __('health.search') }}
            </button>
        </form>

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($patients->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.patient_none') }}</p>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($patients as $patient)
                        <a href="{{ route('health.patients.show', $patient->id) }}"
                           class="px-5 py-4 flex flex-wrap items-center gap-3 hover:bg-gray-50 dark:hover:bg-gray-700/40 transition">
                            <div class="w-24 shrink-0">
                                <span class="text-xs font-black font-mono text-teal-700 dark:text-teal-300">{{ $patient->mrn }}</span>
                            </div>
                            <div class="flex-1 min-w-[180px]">
                                <p class="text-sm font-black">
                                    {{ $patient->name }}
                                    @if($patient->is_confidential)
                                        <span class="ms-1.5 text-[10px] font-bold px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300">{{ __('health.patient_confidential_tag') }}</span>
                                    @endif
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    @if($patient->guardian_name){{ $patient->guardian_name }} &middot; @endif
                                    {{ $patient->age_label ?: '—' }}
                                    @if($patient->gender) &middot; {{ __('health.gender_' . $patient->gender) }} @endif
                                    @if($patient->branch) &middot; {{ $patient->branch->name }} @endif
                                </p>
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 font-mono">{{ $patient->phone ?: '—' }}</div>
                            @unless($patient->is_active)
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wide bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                    {{ __('health.patient_archived_label') }}
                                </span>
                            @endunless
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        <div>{{ $patients->links() }}</div>
    </div>
</x-health-layout>
