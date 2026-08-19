<!DOCTYPE html>
<html lang="{{ ($pdfUrdu ?? false) ? 'ur' : 'en' }}" dir="{{ ($pdfUrdu ?? false) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('pos.tr_report_title') }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif; font-size: 9px; color: #1a1a1a; line-height: 1.4; }
        .header { padding: 15px 20px; border-bottom: 3px solid #7c3aed; margin-bottom: 10px; }
        .company-name { font-size: 18px; font-weight: bold; color: #7c3aed; margin-bottom: 2px; }
        .report-title { font-size: 13px; font-weight: bold; color: #374151; margin-bottom: 4px; }
        .report-meta { font-size: 9px; color: #6b7280; }
        .report-meta span { margin-right: 15px; }
        .content { padding: 0 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th { background-color: #f3f0ff; color: #4c1d95; font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.5px; padding: 6px 4px; text-align: left; border-bottom: 2px solid #7c3aed; }
        th.right { text-align: right; }
        td { padding: 5px 4px; border-bottom: 1px solid #e5e7eb; font-size: 8.5px; }
        td.right { text-align: right; }
        tr:nth-child(even) { background-color: #fafafa; }
        .badge { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 7px; font-weight: bold; }
        .badge-cash { background: #d1fae5; color: #065f46; }
        .badge-card { background: #dbeafe; color: #1e40af; }
        .badge-submitted { background: #d1fae5; color: #065f46; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-failed { background: #fee2e2; color: #991b1b; }
        .badge-offline { background: #ffedd5; color: #9a3412; }
        .badge-local { background: #f3f4f6; color: #4b5563; }
        .badge-return { background: #ffe4e6; color: #be123c; }
        .cn-line { border: 1px solid #fda4af; background: #fff1f2; border-radius: 5px; padding: 7px 10px; margin: 8px 0 4px; font-size: 8.5px; color: #9f1239; }
        .cn-line b { margin-right: 12px; }
        .summary-box { border: 2px solid #7c3aed; border-radius: 6px; padding: 12px; margin-top: 10px; page-break-inside: avoid; }
        .summary-title { font-size: 11px; font-weight: bold; color: #7c3aed; margin-bottom: 8px; }
        .summary-grid { display: table; width: 100%; }
        .summary-item { display: table-cell; text-align: center; padding: 5px; }
        .summary-label { font-size: 8px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.3px; }
        .summary-value { font-size: 13px; font-weight: bold; color: #1a1a1a; margin-top: 2px; }
        .summary-value.purple { color: #7c3aed; }
        .summary-value.green { color: #059669; }
        .summary-value.red { color: #dc2626; }
        .footer { text-align: center; color: #9ca3af; font-size: 7.5px; margin-top: 15px; padding: 10px 20px; border-top: 1px solid #e5e7eb; }
        .total-row { background-color: #f3f0ff !important; font-weight: bold; }
        .total-row td { border-top: 2px solid #7c3aed; border-bottom: 2px solid #7c3aed; }
    </style>
    @if($pdfUrdu ?? false)
    <style>
        body { font-family: 'Jameel Noori Nastaleeq', 'XB Riyaz', 'DejaVu Sans', sans-serif; direction: rtl; }
        table { direction: rtl; }
        th { text-transform: none; letter-spacing: 0; }
        .summary-label { text-transform: none; letter-spacing: 0; }
    </style>
    @endif
</head>
<body>
    <div class="header">
        <div class="company-name">{{ $company->company_name ?? $company->name ?? 'Company' }}</div>
        <div class="report-title">{{ __('pos.tr_report_title') }} &mdash; {{ $taxRateLabel }}</div>
        <div class="report-meta">
            <span>{{ __('pos.tr_period') }} {{ $dateLabel }}</span>
            <span>{{ __('pos.dcp_generated') }} {{ now()->format('d M Y, h:i A') }}</span>
            <span>NTN: {{ $company->ntn ?? 'N/A' }}</span>
        </div>
    </div>

    <div class="content">
        <table>
            <thead>
                <tr>
                    <th>{{ __('pos.tr_col_pos_inv') }}</th>
                    <th>{{ __('pos.tr_col_pra_fiscal') }}</th>
                    <th>{{ __('pos.dc_date') }}</th>
                    <th>{{ __('pos.receipt_customer') }}</th>
                    <th>{{ __('pos.tr_col_payment') }}</th>
                    @if($taxRateFilter ?? false)
                    <th class="right">{{ $taxRateLabel }} {{ __('pos.tr_col_subtotal') }}</th>
                    <th class="right">{{ $taxRateLabel }} {{ __('pos.tr_col_tax_amt') }}</th>
                    <th class="right">{{ $taxRateLabel }} {{ __('pos.tr_col_total') }}</th>
                    @else
                    <th class="right">{{ __('pos.tr_col_subtotal') }}</th>
                    <th class="right">{{ __('pos.tr_col_discount') }}</th>
                    <th class="right">{{ __('pos.tr_col_taxable') }}</th>
                    <th class="right">{{ __('pos.tr_col_exempt') }}</th>
                    <th class="right">{{ __('pos.tr_col_tax_pct') }}</th>
                    <th class="right">{{ __('pos.tr_col_tax_amt') }}</th>
                    <th class="right">{{ __('pos.tr_col_total') }}</th>
                    @endif
                    <th>{{ __('pos.tr_col_terminal') }}</th>
                    <th>{{ __('pos.tr_col_pra_status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($transactions as $t)
                @php
                    $iv = ($taxRateFilter ?? false) ? ($itemValues[$t->id] ?? null) : null;
                    // Credit-note row marking (Task 695): badge + signed amounts
                    // in netted views; credit-notes-only stays positive (refunded).
                    $rowIsReturn = ($billTypeReady ?? false) && ($t->transaction_type ?? 'sale') === 'return';
                    $rowSign = ($rowIsReturn && ($billTypeFilter ?? '') !== 'returns') ? -1 : 1;
                    $retStyle = $rowIsReturn ? 'color:#dc2626;' : '';
                @endphp
                @if(($taxRateFilter ?? false) && !$iv)
                    @continue
                @endif
                <tr>
                    <td style="font-weight:bold;">{{ $t->invoice_number }}
                        @if($rowIsReturn)<span class="badge badge-return">{{ __('pos.credit_note_badge') }}</span>@endif
                    </td>
                    <td>{{ $t->pra_invoice_number ?? '—' }}</td>
                    <td>{{ $t->created_at->format('d/m/Y H:i') }}</td>
                    <td>{{ $t->customer_name ?? __('pos.tr_walk_in') }}</td>
                    <td>
                        <span class="badge {{ $t->payment_method === 'cash' ? 'badge-cash' : 'badge-card' }}">
                            {{ ucwords(str_replace('_', ' ', $t->payment_method)) }}
                        </span>
                    </td>
                    @if($taxRateFilter ?? false)
                    <td class="right" style="{{ $retStyle ?: 'color:#059669;' }}font-weight:bold;">{{ number_format($rowSign * (float)($iv['item_subtotal'] ?? 0), 2) }}</td>
                    <td class="right" style="{{ $retStyle ?: 'color:#7c3aed;' }}font-weight:bold;">{{ number_format($rowSign * (float)($iv['item_tax'] ?? 0), 2) }}</td>
                    <td class="right" style="{{ $retStyle }}font-weight:bold;">{{ number_format($rowSign * ((float)($iv['item_subtotal'] ?? 0) + (float)($iv['item_tax'] ?? 0)), 2) }}</td>
                    @else
                    <td class="right" style="{{ $retStyle }}">{{ number_format($rowSign * $t->subtotal, 2) }}</td>
                    <td class="right" style="color:#dc2626;">{{ number_format($rowSign * $t->discount_amount, 2) }}</td>
                    <td class="right" style="{{ $retStyle }}">{{ number_format($rowSign * ($t->subtotal - $t->discount_amount - ($t->exempt_amount ?? 0)), 2) }}</td>
                    <td class="right" style="{{ $retStyle ?: 'color:#d97706;' }}">{{ ($t->exempt_amount ?? 0) > 0 ? number_format($rowSign * $t->exempt_amount, 2) : '—' }}</td>
                    <td class="right" style="font-weight:bold;">{{ number_format($t->tax_rate, 0) }}%</td>
                    <td class="right" style="{{ $retStyle ?: 'color:#7c3aed;' }}font-weight:bold;">{{ number_format($rowSign * $t->tax_amount, 2) }}</td>
                    <td class="right" style="{{ $retStyle }}font-weight:bold;">{{ number_format($rowSign * $t->total_amount, 2) }}</td>
                    @endif
                    <td>{{ $t->terminal?->terminal_name ?? '—' }}</td>
                    <td>
                        @php
                            $badgeClass = match($t->pra_status) {
                                'submitted' => 'badge-submitted',
                                'pending' => 'badge-pending',
                                'failed' => 'badge-failed',
                                'offline' => 'badge-offline',
                                default => 'badge-local',
                            };
                        @endphp
                        <span class="badge {{ $badgeClass }}">{{ ucfirst($t->pra_status ?? 'N/A') }}</span>
                    </td>
                </tr>
                @endforeach
                <tr class="total-row">
                    @if($taxRateFilter ?? false)
                    <td colspan="5" style="font-size:9px;">{{ $taxRateLabel }} {{ __('pos.tr_totals', ['count' => $summary->total_invoices]) }}</td>
                    <td class="right" style="color:#059669;">{{ number_format($summary->total_sales, 2) }}</td>
                    <td class="right" style="color:#7c3aed;">{{ number_format($summary->total_tax, 2) }}</td>
                    <td class="right">{{ number_format($summary->total_sales + $summary->total_tax, 2) }}</td>
                    <td colspan="2"></td>
                    @else
                    <td colspan="5" style="font-size:9px;">{{ __('pos.tr_totals', ['count' => $summary->total_invoices]) }}</td>
                    <td class="right">—</td>
                    <td class="right" style="color:#dc2626;">{{ number_format($summary->total_discount, 2) }}</td>
                    <td class="right">{{ number_format($summary->total_taxable, 2) }}</td>
                    <td class="right" style="color:#d97706;">{{ number_format($summary->total_exempt ?? 0, 2) }}</td>
                    <td class="right">—</td>
                    <td class="right" style="color:#7c3aed;">{{ number_format($summary->total_tax, 2) }}</td>
                    <td class="right" style="color:#059669;">{{ number_format($summary->total_sales, 2) }}</td>
                    <td colspan="2"></td>
                    @endif
                </tr>
            </tbody>
        </table>

        {{-- Credit-note summary line (Task 695): refunds are never hidden — the
             netted totals above already subtract them. --}}
        @if(($billTypeReady ?? false) && ((($billTypeFilter ?? '') === 'returns') || ($summary->return_count ?? 0) > 0))
        <div class="cn-line">
            <b>{{ __('pos.tr_credit_notes') }}: {{ number_format($summary->return_count ?? 0) }}</b>
            <b>{{ __('pos.tr_refunded_amount') }}: PKR {{ number_format($summary->return_amount ?? 0, 2) }}</b>
            <b>{{ __('pos.tr_tax_reversed') }}: PKR {{ number_format($summary->return_tax ?? 0, 2) }}</b>
            {{ ($billTypeFilter ?? '') === 'returns' ? __('pos.tr_cn_only_note') : __('pos.tr_cn_netted_note') }}
        </div>
        @endif

        <div class="summary-box">
            <div class="summary-title">{{ __('pos.tr_summary_title') }} &mdash; {{ $taxRateLabel }}{{ ($billTypeFilter ?? '') === 'returns' ? ' — ' . __('pos.opt_credit_notes_only') : '' }}</div>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-label">{{ __('pos.tr_sum_invoices') }}</div>
                    <div class="summary-value">{{ number_format($summary->total_invoices) }}</div>
                </div>
                @if($taxRateFilter ?? false)
                <div class="summary-item">
                    <div class="summary-label">{{ $taxRateLabel }} {{ __('pos.tr_col_subtotal') }}</div>
                    <div class="summary-value green">PKR {{ number_format($summary->total_sales, 2) }}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">{{ $taxRateLabel }} {{ __('pos.tr_col_tax_amt') }}</div>
                    <div class="summary-value purple">PKR {{ number_format($summary->total_tax, 2) }}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">{{ $taxRateLabel }} {{ __('pos.tr_col_total') }}</div>
                    <div class="summary-value">PKR {{ number_format($summary->total_sales + $summary->total_tax, 2) }}</div>
                </div>
                @else
                <div class="summary-item">
                    <div class="summary-label">{{ __('pos.tr_sum_total_sales') }}</div>
                    <div class="summary-value green">PKR {{ number_format($summary->total_sales, 2) }}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">{{ __('pos.tr_sum_discount') }}</div>
                    <div class="summary-value red">PKR {{ number_format($summary->total_discount, 2) }}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">{{ __('pos.tr_sum_taxable') }}</div>
                    <div class="summary-value">PKR {{ number_format($summary->total_taxable, 2) }}</div>
                </div>
                <div class="summary-item" style="border-left:3px solid #2563eb;">
                    <div class="summary-label" style="color:#1d4ed8;">{{ __('pos.kpi_third_schedule') }}</div>
                    <div class="summary-value" style="color:#1d4ed8;">PKR {{ number_format($summary->total_third_schedule ?? 0, 2) }}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">{{ __('pos.kpi_tax_exempt_other') }}</div>
                    <div class="summary-value" style="color:#d97706;">PKR {{ number_format($summary->total_exempt_other ?? max(0, ($summary->total_exempt ?? 0) - ($summary->total_third_schedule ?? 0)), 2) }}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">{{ __('pos.tr_sum_tax') }}</div>
                    <div class="summary-value purple">PKR {{ number_format($summary->total_tax, 2) }}</div>
                </div>
                @endif
            </div>
        </div>
    </div>

    <div class="footer">
        {{ __('pos.tr_footer') }} &mdash; {{ $company->company_name ?? $company->name ?? 'Company' }} &mdash; {{ now()->format('d M Y, h:i A') }}
    </div>
</body>
</html>
