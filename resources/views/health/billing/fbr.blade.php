@php
    use App\Models\HealthFbrSubmission;

    $money = fn ($v) => number_format((float) $v, 2);

    $statusChip = [
        HealthFbrSubmission::STATUS_SUBMITTED    => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        HealthFbrSubmission::STATUS_QUEUED_AGENT => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
        HealthFbrSubmission::STATUS_PENDING      => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
        HealthFbrSubmission::STATUS_FAILED       => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
        HealthFbrSubmission::STATUS_CONFIG_ERROR => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
        HealthFbrSubmission::STATUS_BLOCKED      => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
    ];

    $reportableTotal = $reportable->sum(fn ($l) => (float) $l->total_amount);
@endphp
<x-health-layout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-5 space-y-5" x-data="{ open: null }">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ __('health.fbr_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    <a href="{{ route('health.billing.bill', $bill->id) }}" class="font-bold text-teal-700 dark:text-teal-300">{{ $bill->bill_no }}</a>
                    · {{ $bill->patient->name ?? '' }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('health.billing.fbr.reconcile', $bill->id) }}">
                    @csrf
                    <button class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.fbr_recheck') }}</button>
                </form>
                @if($mayManage && empty($bill->fbr_invoice_number))
                    <form method="POST" action="{{ route('health.billing.fbr.submit', $bill->id) }}">
                        @csrf
                        <button @disabled(!$eligibility['ok'])
                                class="px-4 py-2.5 rounded-xl text-white text-sm font-bold {{ $eligibility['ok'] ? 'bg-teal-700 hover:bg-teal-800' : 'bg-gray-400 cursor-not-allowed' }}">
                            {{ __('health.fbr_submit') }}
                        </button>
                    </form>
                @endif
            </div>
        </div>

        {{-- Current state --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
            @if($bill->fbr_invoice_number)
                <div class="text-sm">
                    <div class="text-xs text-gray-500 dark:text-gray-400 font-bold uppercase tracking-wide">{{ __('health.fbr_invoice_number') }}</div>
                    <div class="mt-1 text-lg font-black font-mono text-emerald-700 dark:text-emerald-300">{{ $bill->fbr_invoice_number }}</div>
                    @if($bill->fbr_submitted_at)
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $bill->fbr_submitted_at->format('d M Y, h:i A') }}</div>
                    @endif
                </div>
            @elseif(!$eligibility['ok'])
                <div class="text-sm">
                    <div class="font-bold text-amber-700 dark:text-amber-300">{{ __('health.fbr_cannot_file') }}</div>
                    <p class="text-gray-600 dark:text-gray-300 mt-1">{{ __('health.fbr_err_' . $eligibility['reason']) }}</p>
                </div>
            @else
                <div class="text-sm font-bold text-amber-700 dark:text-amber-300">{{ __('health.fbr_ready_not_filed') }}</div>
            @endif

            @if($bill->fbr_error_message)
                <div class="mt-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 border border-rose-200 dark:border-rose-800 px-3 py-2 text-sm text-rose-800 dark:text-rose-200">
                    {{ $bill->fbr_error_message }}
                    @if($bill->fbr_response_code)
                        <span class="font-mono text-xs">({{ $bill->fbr_response_code }})</span>
                    @endif
                </div>
            @endif

            @if((int) $bill->fbr_retry_count > 0)
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('health.fbr_attempts', ['count' => $bill->fbr_retry_count]) }}</p>
            @endif
        </div>

        {{-- Exactly which lines go. Shown in full, because "what did we tell the
             regulator" must be answerable without opening a payload. --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-black">{{ __('health.fbr_reported_lines') }}</h2>
                <span class="text-sm font-black">{{ $money($reportableTotal) }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2.5 text-start">{{ __('health.description') }}</th>
                            <th class="px-4 py-2.5 text-start">{{ __('health.pct_code') }}</th>
                            <th class="px-4 py-2.5 text-end">{{ __('health.led_net') }}</th>
                            <th class="px-4 py-2.5 text-end">{{ __('health.tax') }}</th>
                            <th class="px-4 py-2.5 text-end">{{ __('health.total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($reportable as $line)
                            <tr>
                                <td class="px-4 py-2.5 font-bold">{{ $line->description }}</td>
                                <td class="px-4 py-2.5 font-mono text-xs">{{ $line->pct_code ?: '—' }}</td>
                                <td class="px-4 py-2.5 text-end">{{ $money($line->net_amount) }}</td>
                                <td class="px-4 py-2.5 text-end">{{ $money($line->tax_amount) }}</td>
                                <td class="px-4 py-2.5 text-end font-bold">{{ $money($line->total_amount) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">{{ __('health.fbr_no_reportable') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 border-t border-gray-100 dark:border-gray-700">
                {{ __('health.fbr_local_excluded_hint') }}
            </p>
        </div>

        {{-- Every attempt, kept forever. --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700">
                <h2 class="font-black">{{ __('health.fbr_attempt_history') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.fbr_evidence_hint') }}</p>
            </div>

            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($submissions as $s)
                    <div class="px-4 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-2 flex-wrap text-sm">
                                <span class="font-black">#{{ $s->attempt_no }}</span>
                                <span class="text-[11px] font-bold px-2 py-1 rounded-lg {{ $statusChip[$s->status] ?? '' }}">{{ __('health.fbr_st_' . $s->status) }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ optional($s->submitted_at)->format('d M Y, h:i:s A') }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.fbr_trigger_' . $s->trigger) }}</span>
                                @if($s->duration_ms !== null)
                                    <span class="text-xs text-gray-400">{{ $s->duration_ms }} ms</span>
                                @endif
                            </div>
                            <button type="button" @click="open = (open === {{ $s->id }} ? null : {{ $s->id }})"
                                    class="text-xs font-bold text-teal-700 dark:text-teal-300 hover:underline">{{ __('health.fbr_view_payload') }}</button>
                        </div>

                        @if($s->invoice_number)
                            <div class="mt-1 text-xs font-mono text-emerald-700 dark:text-emerald-300">{{ $s->invoice_number }}</div>
                        @endif
                        @if($s->error_message)
                            <div class="mt-1 text-xs text-rose-700 dark:text-rose-300">{{ $s->error_message }}</div>
                        @endif

                        <div x-show="open === {{ $s->id }}" x-cloak class="mt-3 grid grid-cols-1 lg:grid-cols-2 gap-3">
                            <div>
                                <div class="text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.fbr_request') }}</div>
                                <pre class="text-[11px] bg-gray-50 dark:bg-gray-900 rounded-xl p-3 overflow-x-auto max-h-72">{{ $s->prettyRequest() ?: '—' }}</pre>
                            </div>
                            <div>
                                <div class="text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('health.fbr_response') }}</div>
                                <pre class="text-[11px] bg-gray-50 dark:bg-gray-900 rounded-xl p-3 overflow-x-auto max-h-72">{{ $s->prettyResponse() ?: '—' }}</pre>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('health.fbr_no_attempts') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</x-health-layout>
