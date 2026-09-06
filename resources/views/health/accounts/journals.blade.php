@php
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <h1 class="text-xl font-black tracking-tight">{{ __('health.acc_journals_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.acc_journals_subtitle') }}</p>
        </div>

        @include('health.accounts.partials.nav')

        <form method="GET" action="{{ route('health.accounts.journals') }}"
              class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 grid grid-cols-2 md:grid-cols-6 gap-3">
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.from') }}</label>
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}"
                       class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.to') }}</label>
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}"
                       class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_type') }}</label>
                <select name="type" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <option value="">{{ __('health.all') }}</option>
                    @foreach($types as $type)
                        <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ __('health.jrn_type_' . $type) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_source') }}</label>
                <select name="source_type" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <option value="">{{ __('health.all') }}</option>
                    @foreach($sources as $source)
                        <option value="{{ $source }}" @selected(($filters['source_type'] ?? '') === $source)>{{ __('health.jrn_src_' . $source) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.search') }}</label>
                <input name="q" value="{{ $filters['q'] ?? '' }}"
                       class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div class="flex items-end">
                <button class="w-full px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.apply') }}</button>
            </div>
        </form>

        @if($canManage)
            {{-- A manual journal is the only way to write to the books by hand,
                 and it must balance before it is accepted. The running totals
                 below are advisory; the server refuses an unbalanced entry
                 whatever the browser believes. --}}
            <details class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
                <summary class="px-4 py-3 font-black cursor-pointer">{{ __('health.acc_new_journal') }}</summary>
                <form method="POST" action="{{ route('health.accounts.journals.store') }}" class="p-4 pt-0 space-y-3"
                      x-data="{ lines: [0,1,2,3] }">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.date') }}</label>
                            <input type="date" name="journal_date" required value="{{ old('journal_date', now()->toDateString()) }}"
                                   class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_type') }}</label>
                            <select name="type" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="manual">{{ __('health.jrn_type_manual') }}</option>
                                <option value="adjustment">{{ __('health.jrn_type_adjustment') }}</option>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_memo') }}</label>
                            <input name="memo" required maxlength="300" value="{{ old('memo') }}"
                                   class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                    </div>

                    <template x-for="i in lines" :key="i">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-2">
                            <select :name="'lines[' + i + '][account_id]'"
                                    class="md:col-span-2 w-full px-3 py-2 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">{{ __('health.acc_pick_account') }}</option>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->displayName() }}</option>
                                @endforeach
                            </select>
                            <input type="number" step="0.01" min="0" :name="'lines[' + i + '][debit]'" placeholder="{{ __('health.acc_debit') }}"
                                   class="w-full px-3 py-2 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            <input type="number" step="0.01" min="0" :name="'lines[' + i + '][credit]'" placeholder="{{ __('health.acc_credit') }}"
                                   class="w-full px-3 py-2 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </div>
                    </template>

                    <div class="flex flex-wrap gap-2">
                        <button type="button" @click="lines.push(lines.length)"
                                class="px-4 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.acc_add_line') }}</button>
                        <button class="px-5 py-2 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.acc_post') }}</button>
                    </div>
                </form>
            </details>
        @endif

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_date') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_journal_no') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_memo') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_source') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_amount') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($journals as $journal)
                            <tr class="border-t border-gray-100 dark:border-gray-700/60">
                                <td class="px-3 py-2.5 whitespace-nowrap">{{ optional($journal->journal_date)->format('d M Y') }}</td>
                                <td class="px-3 py-2.5 font-bold">
                                    <a href="{{ route('health.accounts.journal', $journal->id) }}" class="text-teal-700 dark:text-teal-300">{{ $journal->journal_no }}</a>
                                </td>
                                <td class="px-3 py-2.5 text-gray-600 dark:text-gray-300">{{ $journal->memo }}</td>
                                <td class="px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $journal->source_type ? __('health.jrn_src_' . $journal->source_type) : '—' }}
                                    @if($journal->source_reference)
                                        <span class="block font-mono">{{ $journal->source_reference }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-end font-bold">{{ $money($journal->total_debit) }}</td>
                                <td class="px-3 py-2.5">
                                    <span class="px-2 py-0.5 rounded-lg text-[11px] font-bold
                                        {{ $journal->status === 'reversed'
                                             ? 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300'
                                             : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' }}">
                                        {{ __('health.acc_status_' . $journal->status) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('health.acc_no_journals') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $journals->links() }}
    </div>
</x-health-layout>
