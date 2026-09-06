@php
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ $journal->journal_no }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ $journal->memo }}</p>
            </div>
            <a href="{{ route('health.accounts.journals') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.back') }}</a>
        </div>

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
            <div>
                <div class="text-[11px] uppercase tracking-wide font-bold text-gray-500 dark:text-gray-400">{{ __('health.acc_date') }}</div>
                <div class="font-bold">{{ optional($journal->journal_date)->format('d M Y') }}</div>
            </div>
            <div>
                <div class="text-[11px] uppercase tracking-wide font-bold text-gray-500 dark:text-gray-400">{{ __('health.acc_type') }}</div>
                <div class="font-bold">{{ __('health.jrn_type_' . $journal->type) }}</div>
            </div>
            <div>
                <div class="text-[11px] uppercase tracking-wide font-bold text-gray-500 dark:text-gray-400">{{ __('health.acc_source') }}</div>
                <div class="font-bold">
                    {{ $journal->source_type ? __('health.jrn_src_' . $journal->source_type) : '—' }}
                    @if($journal->source_reference)
                        <span class="block font-mono text-xs text-gray-500">{{ $journal->source_reference }}</span>
                    @endif
                </div>
            </div>
            <div>
                <div class="text-[11px] uppercase tracking-wide font-bold text-gray-500 dark:text-gray-400">{{ __('health.status') }}</div>
                <div class="font-bold">{{ __('health.acc_status_' . $journal->status) }}</div>
            </div>
        </div>

        @if($reversal)
            <div class="rounded-2xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-4 text-sm text-amber-900 dark:text-amber-200">
                {{ __('health.jrn_reversal_of', ['no' => $reversal->journal_no]) }}
                <a href="{{ route('health.accounts.journal', $reversal->id) }}" class="font-bold underline">{{ __('health.acc_open') }}</a>
            </div>
        @endif

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_account') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_memo') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_debit') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_credit') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($journal->lines as $line)
                            <tr class="border-t border-gray-100 dark:border-gray-700/60">
                                <td class="px-3 py-2.5 font-bold">
                                    @if($line->account)
                                        <a href="{{ route('health.accounts.reports.ledger', ['account_id' => $line->health_account_id]) }}"
                                           class="text-teal-700 dark:text-teal-300">{{ $line->account->code }} — {{ $line->account->displayName() }}</a>
                                    @endif
                                    @if($line->doctor || $line->department)
                                        <span class="block text-[11px] font-normal text-gray-500 dark:text-gray-400">
                                            {{ collect([$line->doctor?->name, $line->department?->name])->filter()->implode(' · ') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-gray-600 dark:text-gray-300">{{ $line->memo }}</td>
                                <td class="px-3 py-2.5 text-end">{{ (float) $line->debit > 0 ? $money($line->debit) : '' }}</td>
                                <td class="px-3 py-2.5 text-end">{{ (float) $line->credit > 0 ? $money($line->credit) : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50 dark:bg-gray-900/40 font-black">
                        <tr>
                            <td class="px-3 py-2.5" colspan="2">{{ __('health.total') }}</td>
                            <td class="px-3 py-2.5 text-end">{{ $money($journal->total_debit) }}</td>
                            <td class="px-3 py-2.5 text-end">{{ $money($journal->total_credit) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- A posted journal is never edited. The only correction is a reversal
             that leaves both entries visible — the books must show what was
             believed at the time as well as what was decided later. --}}
        @if($canManage && $journal->status !== 'reversed')
            <form method="POST" action="{{ route('health.accounts.journals.reverse', $journal->id) }}"
                  class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                @csrf
                <h2 class="font-black">{{ __('health.acc_reverse_journal') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.acc_reverse_note') }}</p>
                <input name="reason" required maxlength="300" placeholder="{{ __('health.acc_reason') }}"
                       class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                <button class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-sm font-bold">{{ __('health.acc_reverse') }}</button>
            </form>
        @endif
    </div>
</x-health-layout>
