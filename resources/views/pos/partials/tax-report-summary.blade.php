    @php
        $money = fn ($amount) => 'PKR ' . number_format($amount, 2);
        $taxStats = ($taxRateFilter ?? false) ? [
            [__('pos.kpi_invoices'), number_format($summary->total_invoices)],
            [__('pos.kpi_rate_value', ['rate' => $taxRateLabel]), $money($summary->total_sales)],
            [__('pos.kpi_rate_tax', ['rate' => $taxRateLabel]), $money($summary->total_tax)],
            [__('pos.kpi_rate_total', ['rate' => $taxRateLabel]), $money($summary->total_sales + $summary->total_tax)],
        ] : [
            [__('pos.kpi_total_invoices'), number_format($summary->total_invoices)],
            [__('pos.kpi_total_sales'), $money($summary->total_sales)],
            [__('pos.kpi_total_discount'), $money($summary->total_discount)],
            [__('pos.kpi_total_taxable'), $money($summary->total_taxable)],
            [__('pos.kpi_third_schedule'), $money($summary->total_third_schedule ?? 0)],
            [__('pos.kpi_tax_exempt_other'), $money($summary->total_exempt_other ?? max(0, ($summary->total_exempt ?? 0) - ($summary->total_third_schedule ?? 0)))],
            [__('pos.kpi_total_tax'), $money($summary->total_tax)],
        ];
    @endphp
    <section aria-label="{{ __('pos.tr_summary_title') }}">
        <div class="section-title">{{ __('pos.tr_summary_title') }} · {{ $taxRateLabel }}@if(($billTypeFilter ?? '') === 'returns') · {{ __('pos.opt_credit_notes_only') }}@endif</div>
        <table class="data">
            <tbody>
                @foreach($taxStats as [$label, $value])
                    <tr><td>{{ $label }}</td><td class="r">{{ $value }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </section>
