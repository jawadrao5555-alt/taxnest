@php
    $money = fn ($value) => number_format((float) ($value ?? 0), 2);
    $payments = $summary['payments'] ?? [];
    $cashRecon = $summary['cash_recon'] ?? ['visible' => false];
    $pra = $summary['pra'] ?? [];
    $local = $summary['local'] ?? [];
@endphp

<div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5">
    <div class="flex items-start justify-between gap-3 mb-4">
        <div>
            <h2 class="text-lg font-bold text-gray-900 dark:text-white">{{ __('pos.dc_summary_totals') }}</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.dc_summary_decision_hint') }}</p>
        </div>
        <span class="text-xs font-bold px-2 py-1 rounded {{ $summary['is_frozen'] ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
            {{ $summary['is_frozen'] ? __('pos.dc_summary_frozen') : __('pos.dc_provisional_watermark') }}
        </span>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-5">
        @foreach([
            [__('pos.dc_summary_invoice_count'), $summary['invoice_count'], ''],
            [__('pos.dc_gross_sales'), 'PKR ' . $money($summary['gross_sales']), ''],
            [__('pos.dc_discount'), 'PKR ' . $money($summary['discount']), 'text-rose-600'],
            [__('pos.dc_net_sales'), 'PKR ' . $money($summary['net_sales']), ''],
            [__('pos.dc_sales_tax'), 'PKR ' . $money($summary['tax']), ''],
            [__('pos.dc_total_revenue'), 'PKR ' . $money($summary['total']), 'text-emerald-600'],
        ] as $metric)
        <div class="rounded-lg bg-gray-50 dark:bg-gray-800 px-3 py-3">
            <p class="text-[10px] font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $metric[0] }}</p>
            <p class="mt-1 text-lg font-bold {{ $metric[2] }} text-gray-900 dark:text-white">{{ $metric[1] }}</p>
        </div>
        @endforeach
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
        @foreach([
            [__('pos.dc_cash'), $payments['cash'] ?? 0],
            [__('pos.dc_card'), $payments['card'] ?? 0],
            [__('pos.dc_summary_online'), $payments['online'] ?? 0],
            [__('pos.dc_other'), $payments['other'] ?? 0],
        ] as $payment)
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $payment[0] }}</p>
            <p class="font-bold text-gray-900 dark:text-white">PKR {{ $money($payment[1]) }}</p>
        </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
            <h3 class="text-xs font-bold uppercase text-gray-500 dark:text-gray-400 mb-2">{{ __('pos.dc_summary_streams') }}</h3>
            <div class="flex items-center justify-between text-sm">
                <span>{{ __('pos.dc_stream_pra') }} ({{ $summary['pra_invoices'] ?? 0 }})</span>
                <strong>PKR {{ $money($pra['sales'] ?? $summary['total']) }}</strong>
            </div>
            @if($summary['show_local'] ?? false)
            <div class="flex items-center justify-between text-sm mt-1">
                <span>{{ __('pos.dc_stream_local') }} ({{ $summary['local_invoices'] ?? 0 }})</span>
                <strong>PKR {{ $money($local['sales'] ?? 0) }}</strong>
            </div>
            @endif
            @if(($summary['offline_invoices'] ?? 0) > 0)
            <div class="flex items-center justify-between text-xs mt-2 text-gray-500 dark:text-gray-400">
                <span>{{ __('pos.dcp_offline_invoices') }}</span>
                <strong>{{ $summary['offline_invoices'] }}</strong>
            </div>
            @endif
        </div>

        @if(($summary['returns_count'] ?? 0) > 0)
        <div class="rounded-lg border border-rose-200 dark:border-rose-800 p-3">
            <h3 class="text-xs font-bold uppercase text-rose-600 dark:text-rose-400 mb-2">{{ __('pos.dc_summary_returns') }}</h3>
            <div class="flex items-center justify-between text-sm">
                <span>{{ __('pos.dc_summary_return_count') }}</span>
                <strong>{{ $summary['returns_count'] }}</strong>
            </div>
            <div class="flex items-center justify-between text-sm mt-1">
                <span>{{ __('pos.dc_summary_refund_amount') }}</span>
                <strong class="text-rose-600">PKR {{ $money($summary['returns_amount']) }}</strong>
            </div>
        </div>
        @endif
    </div>

    @if($cashRecon['visible'] ?? false)
    <div class="mt-4 rounded-lg border border-amber-200 dark:border-amber-800 p-3">
        <h3 class="text-xs font-bold uppercase text-amber-700 dark:text-amber-400 mb-2">{{ __('pos.dc_summary_cash_recon') }}</h3>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
            <div><span class="block text-xs text-gray-500">{{ __('pos.dc_opening_float') }}</span><strong>PKR {{ $cashRecon['opening'] === null ? '—' : $money($cashRecon['opening']) }}</strong></div>
            <div><span class="block text-xs text-gray-500">{{ __('pos.dcp_expected_cash_drawer') }}</span><strong>PKR {{ $cashRecon['expected'] === null ? '—' : $money($cashRecon['expected']) }}</strong></div>
            <div><span class="block text-xs text-gray-500">{{ __('pos.dc_counted_cash') }}</span><strong>PKR {{ $cashRecon['counted'] === null ? '—' : $money($cashRecon['counted']) }}</strong></div>
            <div><span class="block text-xs text-gray-500">{{ __('pos.dc_variance') }}</span><strong class="{{ ((float) ($cashRecon['variance'] ?? 0)) < 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ $cashRecon['variance'] === null ? '—' : 'PKR ' . $money($cashRecon['variance']) }}</strong></div>
        </div>
    </div>
    @endif
</div>