@php
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <h1 class="text-xl font-black tracking-tight">{{ __('health.acc_recon_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.acc_recon_subtitle') }}</p>
        </div>

        @include('health.accounts.partials.nav')

        <form method="GET" action="{{ route('health.accounts.reconciliations') }}"
              class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 grid grid-cols-1 md:grid-cols-4 gap-3">
            <div class="md:col-span-2">
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_account') }}</label>
                <select name="account_id" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    @foreach($accounts as $account)
                        <option value="{{ $account->id }}" @selected($selected === (int) $account->id)>{{ $account->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_as_at') }}</label>
                <input type="date" name="as_at" value="{{ $asAt }}"
                       class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
            </div>
            <div class="flex items-end">
                <button class="w-full px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.apply') }}</button>
            </div>
        </form>

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-[11px] uppercase tracking-wide font-bold text-gray-500 dark:text-gray-400">{{ __('health.acc_book_balance') }}</div>
            <div class="text-2xl font-black">{{ $money($bookBalance) }}</div>
        </div>

        @if($canManage)
            {{-- A difference is never quietly absorbed into an expense account.
                 It parks in Suspense with a note, and stays visible until
                 somebody explains it — an unexplained shortfall that has been
                 tidied away is a shortfall nobody will ever investigate. --}}
            <form method="POST" action="{{ route('health.accounts.reconciliations.store') }}"
                  class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                @csrf
                <h2 class="font-black">{{ __('health.acc_new_recon') }}</h2>
                <input type="hidden" name="health_account_id" value="{{ $selected }}">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_statement_date') }}</label>
                        <input type="date" name="statement_date" required value="{{ old('statement_date', $asAt) }}"
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_period_from') }}</label>
                        <input type="date" name="period_from" value="{{ old('period_from') }}"
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_statement_balance') }}</label>
                        <input type="number" step="0.01" name="statement_balance" required value="{{ old('statement_balance') }}"
                               class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </div>
                </div>
                <input name="notes" maxlength="500" placeholder="{{ __('health.acc_memo') }}" value="{{ old('notes') }}"
                       class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                <button class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.save') }}</button>
            </form>
        @endif

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_statement_date') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_account') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_book_balance') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_statement_balance') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.acc_difference') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.status') }}</th>
                            <th class="px-3 py-2.5 text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-700/60">
                                <td class="px-3 py-2.5 whitespace-nowrap">{{ optional($row->statement_date)->format('d M Y') }}</td>
                                <td class="px-3 py-2.5">{{ $row->account?->displayName() }}</td>
                                <td class="px-3 py-2.5 text-end">{{ $money($row->book_balance) }}</td>
                                <td class="px-3 py-2.5 text-end">{{ $money($row->statement_balance) }}</td>
                                <td class="px-3 py-2.5 text-end font-bold {{ abs((float) $row->difference) > 0.005 ? 'text-rose-700 dark:text-rose-300' : '' }}">
                                    {{ $money($row->difference) }}
                                </td>
                                <td class="px-3 py-2.5 text-xs font-bold">{{ __('health.acc_recon_' . $row->status) }}</td>
                                <td class="px-3 py-2.5 text-end">
                                    @if($canManage && $row->status === 'open')
                                        <form method="POST" action="{{ route('health.accounts.reconciliations.close', $row->id) }}" class="flex gap-1 justify-end">
                                            @csrf
                                            <input name="reason" maxlength="300" placeholder="{{ __('health.acc_reason') }}"
                                                   class="w-40 px-2 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-xs">
                                            <button class="px-3 py-1.5 rounded-lg bg-teal-700 text-white text-xs font-bold">{{ __('health.acc_close') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('health.acc_no_recons') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-health-layout>
