@php
    use App\Services\HealthOnboardingImportService as Onboarding;
@endphp
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.import_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.import_subtitle') }}</p>
        </div>

        {{-- Temporary logins created by a staff import. Shown ONCE, right here,
             and never written back into a file: a spreadsheet of live passwords
             in a hospital's downloads folder is a breach waiting to happen. --}}
        @if(session('importCredentials'))
            <div class="rounded-2xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-5">
                <h2 class="text-sm font-black text-amber-900 dark:text-amber-200">{{ __('health.import_credentials_title') }}</h2>
                <p class="text-xs text-amber-800 dark:text-amber-300 mt-1 leading-relaxed">{{ __('health.import_credentials_note') }}</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-[11px] uppercase tracking-wide text-amber-800 dark:text-amber-300">
                            <tr>
                                <th class="px-3 py-1.5 text-start font-black">{{ __('health.import_col_name') }}</th>
                                <th class="px-3 py-1.5 text-start font-black">{{ __('health.import_col_email') }}</th>
                                <th class="px-3 py-1.5 text-start font-black">{{ __('health.import_col_password') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-amber-200 dark:divide-amber-800">
                            @foreach(session('importCredentials') as $credential)
                                <tr>
                                    <td class="px-3 py-1.5 font-bold">{{ $credential['name'] }}</td>
                                    <td class="px-3 py-1.5">{{ $credential['email'] }}</td>
                                    <td class="px-3 py-1.5 font-mono font-bold">{{ $credential['password'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if(session('importMessages') && count(session('importMessages')))
            <div class="rounded-2xl border border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-900/20 p-5">
                <h2 class="text-sm font-black text-red-900 dark:text-red-200">{{ __('health.import_failed_rows') }}</h2>
                <ul class="mt-2 space-y-1 text-xs text-red-800 dark:text-red-300 list-disc list-inside">
                    @foreach(session('importMessages') as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($preview)
            @php
                $summary = $preview['summary'];
                $rows = $preview['rows'];
                $shown = array_slice($rows, 0, Onboarding::PREVIEW_ROWS);
                $columns = Onboarding::headers($dataset);
            @endphp

            {{-- ── Step 2: what this file would do ── --}}
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-base font-black">{{ __(Onboarding::labelKey($dataset)) }}</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.import_preview_note') }}</p>
                </div>

                <div class="px-5 py-4 grid grid-cols-3 gap-3">
                    <div class="rounded-xl bg-emerald-50 dark:bg-emerald-900/20 px-4 py-3">
                        <p class="text-[11px] font-black uppercase tracking-wide text-emerald-700 dark:text-emerald-300">{{ __('health.import_will_create') }}</p>
                        <p class="text-2xl font-black text-emerald-800 dark:text-emerald-200">{{ $summary[Onboarding::ACTION_CREATE] }}</p>
                    </div>
                    <div class="rounded-xl bg-sky-50 dark:bg-sky-900/20 px-4 py-3">
                        <p class="text-[11px] font-black uppercase tracking-wide text-sky-700 dark:text-sky-300">{{ __('health.import_will_update') }}</p>
                        <p class="text-2xl font-black text-sky-800 dark:text-sky-200">{{ $summary[Onboarding::ACTION_UPDATE] }}</p>
                    </div>
                    <div class="rounded-xl bg-red-50 dark:bg-red-900/20 px-4 py-3">
                        <p class="text-[11px] font-black uppercase tracking-wide text-red-700 dark:text-red-300">{{ __('health.import_will_skip') }}</p>
                        <p class="text-2xl font-black text-red-800 dark:text-red-200">{{ $summary[Onboarding::ACTION_ERROR] }}</p>
                    </div>
                </div>

                @if(count($rows) > count($shown))
                    <p class="px-5 pb-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('health.import_showing_first', ['shown' => count($shown), 'total' => count($rows)]) }}
                    </p>
                @endif

                <div class="overflow-x-auto border-t border-gray-200 dark:border-gray-700">
                    <table class="min-w-full text-xs">
                        <thead class="bg-gray-50 dark:bg-gray-900/40 text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-3 py-2 text-start font-black">{{ __('health.import_col_row') }}</th>
                                <th class="px-3 py-2 text-start font-black">{{ __('health.import_col_action') }}</th>
                                @foreach($columns as $column)
                                    <th class="px-3 py-2 text-start font-black whitespace-nowrap">{{ $column }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($shown as $entry)
                                <tr class="{{ $entry['action'] === Onboarding::ACTION_ERROR ? 'bg-red-50/60 dark:bg-red-900/10' : '' }}">
                                    <td class="px-3 py-2 font-bold text-gray-500 dark:text-gray-400">{{ $entry['row'] }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        @if($entry['action'] === Onboarding::ACTION_CREATE)
                                            <span class="px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-200 font-black">{{ __('health.import_action_create') }}</span>
                                        @elseif($entry['action'] === Onboarding::ACTION_UPDATE)
                                            <span class="px-2 py-0.5 rounded-full bg-sky-100 dark:bg-sky-900/40 text-sky-800 dark:text-sky-200 font-black">{{ __('health.import_action_update') }}</span>
                                        @else
                                            <span class="px-2 py-0.5 rounded-full bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200 font-black">{{ __('health.import_action_skip') }}</span>
                                        @endif
                                    </td>
                                    @foreach($columns as $column)
                                        <td class="px-3 py-2 whitespace-nowrap max-w-[16rem] truncate">
                                            {{ $entry['data'][$column] === true ? __('health.import_yes') : ($entry['data'][$column] === false ? __('health.import_no') : ($entry['data'][$column] ?? '—')) }}
                                        </td>
                                    @endforeach
                                </tr>
                                @if(!empty($entry['errors']))
                                    <tr class="bg-red-50/60 dark:bg-red-900/10">
                                        <td colspan="{{ count($columns) + 2 }}" class="px-3 pb-2 text-[11px] text-red-700 dark:text-red-300">
                                            {{ implode(' · ', $entry['errors']) }}
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="px-5 py-4 border-t border-gray-200 dark:border-gray-700 flex flex-wrap items-center gap-3">
                    <form method="POST" action="{{ route('health.setup.import.commit', ['dataset' => $dataset, 'token' => $token]) }}">
                        @csrf
                        <button type="submit"
                                @disabled($summary[Onboarding::ACTION_CREATE] + $summary[Onboarding::ACTION_UPDATE] === 0)
                                class="px-5 py-2.5 rounded-xl bg-teal-600 text-white text-sm font-black hover:bg-teal-700 disabled:opacity-40 disabled:cursor-not-allowed transition">
                            {{ __('health.import_confirm', ['count' => $summary[Onboarding::ACTION_CREATE] + $summary[Onboarding::ACTION_UPDATE]]) }}
                        </button>
                    </form>
                    <form method="POST" action="{{ route('health.setup.import.discard', ['token' => $token]) }}">
                        @csrf
                        <button type="submit" class="px-5 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                            {{ __('health.import_discard') }}
                        </button>
                    </form>
                </div>
            </div>
        @endif

        {{-- ── Step 1: the sheets, in the order they must be filled ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-base font-black">{{ __('health.import_sheets_title') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.import_sheets_note') }}</p>
            </div>
            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach($datasets as $index => $key)
                    <div class="px-5 py-4 flex flex-col sm:flex-row sm:items-center gap-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="w-6 h-6 rounded-full bg-teal-50 dark:bg-teal-900/30 text-teal-700 dark:text-teal-300 text-[11px] font-black grid place-items-center flex-shrink-0">{{ $index + 1 }}</span>
                                <h3 class="text-sm font-black truncate">{{ __(Onboarding::labelKey($key)) }}</h3>
                            </div>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 leading-relaxed">{{ __(Onboarding::descriptionKey($key)) }}</p>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <a href="{{ route('health.setup.import.template', ['dataset' => $key]) }}"
                               class="px-3 py-2 rounded-xl border border-gray-300 dark:border-gray-600 text-xs font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition whitespace-nowrap">
                                {{ __('health.import_download_template') }}
                            </a>
                            <form method="POST" action="{{ route('health.setup.import.upload', ['dataset' => $key]) }}"
                                  enctype="multipart/form-data" class="flex items-center gap-2">
                                @csrf
                                <input type="file" name="file" required accept=".xlsx,.xls,.csv"
                                       class="text-xs w-40 file:me-2 file:px-2.5 file:py-1.5 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-teal-50 dark:file:bg-teal-900/30 file:text-teal-700 dark:file:text-teal-300">
                                <button type="submit" class="px-3 py-2 rounded-xl bg-teal-600 text-white text-xs font-black hover:bg-teal-700 transition whitespace-nowrap">
                                    {{ __('health.import_check') }}
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/30 px-5 py-4">
            <h2 class="text-sm font-black">{{ __('health.import_rules_title') }}</h2>
            <ul class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-400 list-disc list-inside leading-relaxed">
                <li>{{ __('health.import_rule_preview') }}</li>
                <li>{{ __('health.import_rule_rerun') }}</li>
                <li>{{ __('health.import_rule_partial') }}</li>
                <li>{{ __('health.import_rule_headers') }}</li>
                <li>{{ __('health.import_rule_history') }}</li>
            </ul>
        </div>
    </div>
</x-health-layout>
