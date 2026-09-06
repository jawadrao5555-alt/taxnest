@php
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ $settlement->settlement_no }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    {{ $settlement->doctor?->name }} ·
                    {{ optional($settlement->period_from)->format('d M Y') }} &rarr; {{ optional($settlement->period_to)->format('d M Y') }}
                </p>
            </div>
            <div class="flex gap-2">
                <button type="button" onclick="window.print()" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.print') }}</button>
                <a href="{{ route('health.accounts.settlements') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.back') }}</a>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach([
                ['health.dset_gross', $settlement->gross_amount],
                ['health.dset_deduction', $settlement->deduction_amount],
                ['health.dset_net', $settlement->net_amount],
            ] as [$label, $value])
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                    <div class="text-[11px] uppercase tracking-wide font-bold text-gray-500 dark:text-gray-400">{{ __($label) }}</div>
                    <div class="mt-1 font-black">{{ $money($value) }}</div>
                </div>
            @endforeach
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                <div class="text-[11px] uppercase tracking-wide font-bold text-gray-500 dark:text-gray-400">{{ __('health.status') }}</div>
                <div class="mt-1 font-black">{{ __($settlement->statusLabelKey()) }}</div>
            </div>
        </div>

        @if($settlement->deduction_reason)
            <div class="rounded-2xl bg-gray-50 dark:bg-gray-900/40 border border-gray-200 dark:border-gray-700 p-4 text-sm">
                <span class="font-bold">{{ __('health.dset_deduction') }}:</span> {{ $settlement->deduction_reason }}
            </div>
        @endif

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_date') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_memo') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.dsh_base') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.dsh_rate') }}</th>
                            <th class="px-3 py-2.5 text-end">{{ __('health.dsh_share') }}</th>
                            <th class="px-3 py-2.5 text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($settlement->shares as $share)
                            <tr class="border-t border-gray-100 dark:border-gray-700/60">
                                <td class="px-3 py-2.5 whitespace-nowrap">{{ optional($share->accrual_date)->format('d M Y') }}</td>
                                <td class="px-3 py-2.5 text-gray-600 dark:text-gray-300">{{ $share->description }}</td>
                                <td class="px-3 py-2.5 text-end text-gray-500 dark:text-gray-400">{{ $money($share->base_amount) }}</td>
                                <td class="px-3 py-2.5 text-end text-xs">{{ (float) $share->rate }}</td>
                                <td class="px-3 py-2.5 text-end font-bold">{{ $money($share->share_amount) }}</td>
                                <td class="px-3 py-2.5 text-end">
                                    @if($canManage && $settlement->isDraft())
                                        <form method="POST" action="{{ route('health.accounts.settlements.detach', [$settlement->id, $share->id]) }}">
                                            @csrf
                                            <button class="text-xs font-bold text-gray-500 hover:text-rose-600">{{ __('health.dset_detach') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('health.dsh_none') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Prepare, approve, pay. Three acts, and the middle one belongs to
             somebody else — an accountant who could build and bless their own
             payout is not a control, it is a formality. --}}
        @if($canManage && $settlement->isDraft())
            <form method="POST" action="{{ route('health.accounts.settlements.update', $settlement->id) }}"
                  class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 space-y-3 print:hidden">
                @csrf
                @method('PUT')
                <h2 class="font-black">{{ __('health.dset_adjust') }}</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <input type="number" step="0.01" min="0" name="deduction_amount" value="{{ (float) $settlement->deduction_amount }}"
                           placeholder="{{ __('health.dset_deduction') }}"
                           class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <input name="deduction_reason" maxlength="300" value="{{ $settlement->deduction_reason }}"
                           placeholder="{{ __('health.acc_reason') }}"
                           class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <input name="notes" maxlength="500" value="{{ $settlement->notes }}" placeholder="{{ __('health.acc_memo') }}"
                           class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                </div>
                <button class="px-5 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.save') }}</button>
            </form>
        @endif

        <div class="flex flex-wrap gap-3 print:hidden">
            @if($canApprove && $settlement->isDraft())
                <form method="POST" action="{{ route('health.accounts.settlements.approve', $settlement->id) }}">
                    @csrf
                    <button class="px-5 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.dset_approve') }}</button>
                </form>
            @endif

            @if($canManage && $settlement->isApproved())
                <form method="POST" action="{{ route('health.accounts.settlements.pay', $settlement->id) }}"
                      class="flex flex-wrap items-end gap-2 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_pay_mode') }}</label>
                        <select name="pay_method" class="px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            <option value="cash">{{ __('health.exp_mode_cash') }}</option>
                            <option value="bank">{{ __('health.exp_mode_bank') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_paid_from') }}</label>
                        <select name="paid_from_account_id" class="px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            <option value="">{{ __('health.acc_default') }}</option>
                            @foreach($fundAccounts as $account)
                                <option value="{{ $account->id }}">{{ $account->displayName() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <input name="pay_reference" maxlength="120" placeholder="{{ __('health.acc_reference') }}"
                           class="px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <button class="px-5 py-2.5 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white text-sm font-bold">{{ __('health.dset_pay') }}</button>
                </form>
            @endif

            @if($canApprove && $settlement->isLive())
                <form method="POST" action="{{ route('health.accounts.settlements.reverse', $settlement->id) }}"
                      class="flex items-end gap-2" onsubmit="return confirm('{{ __('health.acc_reverse_confirm') }}')">
                    @csrf
                    <input name="reason" required maxlength="300" placeholder="{{ __('health.acc_reason') }}"
                           class="px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    <button class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-sm font-bold">{{ __('health.acc_reverse') }}</button>
                </form>
            @endif
        </div>
    </div>
</x-health-layout>
