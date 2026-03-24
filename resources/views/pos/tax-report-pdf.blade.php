<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>NestPOS Tax Report</title>
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
</head>
<body>
    <div class="header">
        <div class="company-name">{{ $company->company_name ?? $company->name ?? 'Company' }}</div>
        <div class="report-title">NestPOS Tax Report &mdash; {{ $taxRateLabel }}</div>
        <div class="report-meta">
            <span>Period: {{ $dateLabel }}</span>
            <span>Generated: {{ now()->format('d M Y, h:i A') }}</span>
            <span>NTN: {{ $company->ntn ?? 'N/A' }}</span>
        </div>
    </div>

    <div class="content">
        <table>
            <thead>
                <tr>
                    <th>POS Invoice #</th>
                    <th>PRA Fiscal #</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Payment</th>
                    @if($taxRateFilter ?? false)
                    <th class="right">{{ $taxRateLabel }} Value</th>
                    <th class="right">{{ $taxRateLabel }} Tax</th>
                    <th class="right">{{ $taxRateLabel }} Total</th>
                    @else
                    <th class="right">Subtotal</th>
                    <th class="right">Discount</th>
                    <th class="right">Taxable</th>
                    <th class="right">Exempt</th>
                    <th class="right">Tax %</th>
                    <th class="right">Tax Amt</th>
                    <th class="right">Total</th>
                    @endif
                    <th>Terminal</th>
                    <th>PRA Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($transactions as $t)
                @php
                    $iv = ($taxRateFilter ?? false) ? ($itemValues[$t->id] ?? null) : null;
                @endphp
                @if(($taxRateFilter ?? false) && !$iv)
                    @continue
                @endif
                <tr>
                    <td style="font-weight:bold;">{{ $t->invoice_number }}</td>
                    <td>{{ $t->pra_invoice_number ?? '—' }}</td>
                    <td>{{ $t->created_at->format('d/m/Y H:i') }}</td>
                    <td>{{ $t->customer_name ?? 'Walk-in' }}</td>
                    <td>
                        <span class="badge {{ $t->payment_method === 'cash' ? 'badge-cash' : 'badge-card' }}">
                            {{ ucwords(str_replace('_', ' ', $t->payment_method)) }}
                        </span>
                    </td>
                    @if($taxRateFilter ?? false)
                    <td class="right" style="color:#059669;font-weight:bold;">{{ number_format((float)($iv['item_subtotal'] ?? 0), 2) }}</td>
                    <td class="right" style="color:#7c3aed;font-weight:bold;">{{ number_format((float)($iv['item_tax'] ?? 0), 2) }}</td>
                    <td class="right" style="font-weight:bold;">{{ number_format((float)($iv['item_subtotal'] ?? 0) + (float)($iv['item_tax'] ?? 0), 2) }}</td>
                    @else
                    <td class="right">{{ number_format($t->subtotal, 2) }}</td>
                    <td class="right" style="color:#dc2626;">{{ number_format($t->discount_amount, 2) }}</td>
                    <td class="right">{{ number_format($t->subtotal - $t->discount_amount - ($t->exempt_amount ?? 0), 2) }}</td>
                    <td class="right" style="color:#d97706;">{{ ($t->exempt_amount ?? 0) > 0 ? number_format($t->exempt_amount, 2) : '—' }}</td>
                    <td class="right" style="font-weight:bold;">{{ number_format($t->tax_rate, 0) }}%</td>
                    <td class="right" style="color:#7c3aed;font-weight:bold;">{{ number_format($t->tax_amount, 2) }}</td>
                    <td class="right" style="font-weight:bold;">{{ number_format($t->total_amount, 2) }}</td>
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
                    <td colspan="5" style="font-size:9px;">{{ $taxRateLabel }} TOTALS ({{ $summary->total_invoices }} invoices)</td>
                    <td class="right" style="color:#059669;">{{ number_format($summary->total_sales, 2) }}</td>
                    <td class="right" style="color:#7c3aed;">{{ number_format($summary->total_tax, 2) }}</td>
                    <td class="right">{{ number_format($summary->total_sales + $summary->total_tax, 2) }}</td>
                    <td colspan="2"></td>
                    @else
                    <td colspan="5" style="font-size:9px;">TOTALS ({{ $summary->total_invoices }} invoices)</td>
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

        <div class="summary-box">
            <div class="summary-title">Report Summary &mdash; {{ $taxRateLabel }}</div>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-label">Invoices</div>
                    <div class="summary-value">{{ number_format($summary->total_invoices) }}</div>
                </div>
                @if($taxRateFilter ?? false)
                <div class="summary-item">
                    <div class="summary-label">{{ $taxRateLabel }} Value</div>
                    <div class="summary-value green">PKR {{ number_format($summary->total_sales, 2) }}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">{{ $taxRateLabel }} Tax</div>
                    <div class="summary-value purple">PKR {{ number_format($summary->total_tax, 2) }}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">{{ $taxRateLabel }} Total</div>
                    <div class="summary-value">PKR {{ number_format($summary->total_sales + $summary->total_tax, 2) }}</div>
                </div>
                @else
                <div class="summary-item">
                    <div class="summary-label">Total Sales Amount</div>
                    <div class="summary-value green">PKR {{ number_format($summary->total_sales, 2) }}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Total Discount</div>
                    <div class="summary-value red">PKR {{ number_format($summary->total_discount, 2) }}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Total Taxable Amount</div>
                    <div class="summary-value">PKR {{ number_format($summary->total_taxable, 2) }}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Tax Exempt Amount</div>
                    <div class="summary-value" style="color:#d97706;">PKR {{ number_format($summary->total_exempt ?? 0, 2) }}</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Total Tax Collected</div>
                    <div class="summary-value purple">PKR {{ number_format($summary->total_tax, 2) }}</div>
                </div>
                @endif
            </div>
        </div>
    </div>

    <div class="footer">
        Generated by NestPOS &mdash; {{ $company->company_name ?? $company->name ?? 'Company' }} &mdash; {{ now()->format('d M Y, h:i A') }}
    </div>
</body>
</html>
