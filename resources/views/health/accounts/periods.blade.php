@php
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div>
            <h1 class="text-xl font-black tracking-tight">{{ __('health.acc_periods_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.acc_periods_subtitle') }}</p>
        </div>

        @include('health.accounts.partials.nav')

        @if(($pending['total'] ?? 0) > 0)
            <div class="rounded-2xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-4 text-sm text-amber-900 dark:text-amber-200">
                {{ __('health.acc_close_pending_warning', ['count' => $pending['total']]) }}
            </div>
        @endif

        @if($canManage)
            <form method="POST" action="{{ route('health.accounts.periods.ensure') }}"
                  class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_open_month_for') }}</label>
                    <input type="date" name="date" required value="{{ now()->toDateString() }}"
                           class="px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                </div>
                <button class="px-5 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.acc_open_month') }}</button>
            </form>
        @endif

        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2.5 text-start">{{ __('health.acc_period') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.from') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.to') }}</th>
                            <th class="px-3 py-2.5 text-start">{{ __('health.status') }}</th>
                            <th class="px-3 py-2.5 text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($periods as $period)
                            <tr class="border-t border-gray-100 dark:border-gray-700/60">
                                <td class="px-3 py-2.5 font-bold">{{ $period->name }}</td>
                                <td class="px-3 py-2.5">{{ optional($period->starts_on)->format('d M Y') }}</td>
                                <td class="px-3 py-2.5">{{ optional($period->ends_on)->format('d M Y') }}</td>
                                <td class="px-3 py-2.5">
                                    <span class="px-2 py-0.5 rounded-lg text-[11px] font-bold
                                        {{ $period->status === 'closed'
                                             ? 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200'
                                             : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' }}">
                                        {{ __('health.acc_period_' . $period->status) }}
                                    </span>
                                    @if($period->closed_at)
                                        <span class="block text-[11px] text-gray-500 dark:text-gray-400">{{ $period->closed_at->format('d M Y') }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-end">
                                    {{-- Closing is one-way. After it, a correction is an adjustment
                                         journal in the next open month — the closed month's figures
                                         are what was reported, and rewriting them makes every
                                         statement already sent out a lie. --}}
                                    @if($canApprove && $period->status === 'open')
                                        <form method="POST" action="{{ route('health.accounts.periods.close', $period->id) }}"
                                              onsubmit="return confirm('{{ __('health.acc_close_confirm') }}')">
                                            @csrf
                                            <button class="px-3 py-1.5 rounded-lg bg-teal-700 hover:bg-teal-800 text-white text-xs font-bold">
                                                {{ __('health.acc_close_period') }}
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('health.acc_no_periods') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @unless($canApprove)
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.acc_close_needs_approver') }}</p>
        @endunless
    </div>
</x-health-layout>
