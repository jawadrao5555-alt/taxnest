@php
    /**
     * Shared by the finance-facing doctor statement and the doctor's own
     * earnings page. One body means the two can never quietly disagree about
     * what a doctor earned — which is the only argument this page exists to end.
     */
    $money = $money ?? fn ($v) => number_format((float) $v, 2);
    $showSettlements = $showSettlements ?? false;
@endphp

<div class="grid grid-cols-1 md:grid-cols-3 gap-3">
    @foreach([
        ['health.dsh_earned', $statement['earned'], ''],
        ['health.dsh_settled', $statement['paid'], 'text-emerald-700 dark:text-emerald-300'],
        ['health.dsh_outstanding', $statement['outstanding'], 'text-amber-700 dark:text-amber-300'],
    ] as [$label, $value, $tone])
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-[11px] uppercase tracking-wide font-bold text-gray-500 dark:text-gray-400">{{ __($label) }}</div>
            <div class="mt-1 text-lg font-black {{ $tone }}">{{ $money($value) }}</div>
        </div>
    @endforeach
</div>

@if(!empty($statement['by_category']))
    <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
        <h2 class="font-black text-sm uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">{{ __('health.dsh_by_category') }}</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
            @foreach($statement['by_category'] as $category => $amount)
                <div class="flex items-center justify-between gap-2">
                    <span class="text-gray-600 dark:text-gray-300">{{ __('health.charge_cat_' . $category) }}</span>
                    <strong>{{ $money($amount) }}</strong>
                </div>
            @endforeach
        </div>
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
                    <th class="px-3 py-2.5 text-end">{{ __('health.dsh_share') }}</th>
                    <th class="px-3 py-2.5 text-start">{{ __('health.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($statement['shares'] as $share)
                    <tr class="border-t border-gray-100 dark:border-gray-700/60 {{ in_array($share->status, ['excluded', 'reversed'], true) ? 'opacity-60' : '' }}">
                        <td class="px-3 py-2.5 whitespace-nowrap">{{ optional($share->accrual_date)->format('d M Y') }}</td>
                        <td class="px-3 py-2.5 text-gray-600 dark:text-gray-300">
                            {{ $share->description }}
                            @if($share->exclusion_reason)
                                <span class="block text-[11px] text-rose-600 dark:text-rose-300">{{ $share->exclusion_reason }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2.5 text-end text-gray-500 dark:text-gray-400">{{ $money($share->base_amount) }}</td>
                        <td class="px-3 py-2.5 text-end font-bold">{{ $money($share->share_amount) }}</td>
                        <td class="px-3 py-2.5 text-xs font-bold">{{ __($share->statusLabelKey()) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('health.dsh_none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($showSettlements && $statement['settlements']->isNotEmpty())
    <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
        <h2 class="px-4 pt-4 font-black text-sm uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('health.dset_title') }}</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2.5 text-start">{{ __('health.dset_no') }}</th>
                        <th class="px-3 py-2.5 text-start">{{ __('health.dset_period') }}</th>
                        <th class="px-3 py-2.5 text-end">{{ __('health.dset_net') }}</th>
                        <th class="px-3 py-2.5 text-start">{{ __('health.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($statement['settlements'] as $settlement)
                        <tr class="border-t border-gray-100 dark:border-gray-700/60">
                            <td class="px-3 py-2.5 font-bold">{{ $settlement->settlement_no }}</td>
                            <td class="px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ optional($settlement->period_from)->format('d M Y') }} &rarr; {{ optional($settlement->period_to)->format('d M Y') }}
                            </td>
                            <td class="px-3 py-2.5 text-end">{{ $money($settlement->net_amount) }}</td>
                            <td class="px-3 py-2.5 text-xs font-bold">{{ __($settlement->statusLabelKey()) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
