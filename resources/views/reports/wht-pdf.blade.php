@extends('reports.pdf-layout')

@section('content')
    <div class="report-title">
        <h2>WHT Collection Report</h2>
        <div class="period">Period: {{ $fromDate }} to {{ $toDate }} | View: {{ ucfirst($viewType ?? 'Whole') }}{{ isset($partyName) ? ' | Party: ' . $partyName : '' }}</div>
    </div>

    <div class="summary-grid">
        <div class="summary-card highlight">
            <div class="value">{{ number_format($totals['invoice_count']) }}</div>
            <div class="label">Total Invoices</div>
        </div>
        <div class="summary-card">
            <div class="value">{{ number_format($totals['total_value'], 2) }}</div>
            <div class="label">Total Value (Excl ST)</div>
        </div>
        <div class="summary-card">
            <div class="value">{{ number_format($totals['total_sales_tax'], 2) }}</div>
            <div class="label">Sales Tax</div>
        </div>
        <div class="summary-card warning">
            <div class="value">{{ number_format($totals['total_wht'], 2) }}</div>
            <div class="label">WHT Collected</div>
        </div>
        <div class="summary-card highlight">
            <div class="value">{{ number_format($totals['total_net_receivable'], 2) }}</div>
            <div class="label">Net Receivable</div>
        </div>
    </div>

    @if(isset($viewType) && $viewType === 'partywise')
        @foreach($partyGroups as $party => $rows)
        <div class="party-header">
            <h3>{{ $party }}</h3>
            <div class="detail">NTN: {{ $rows->first()->buyer_ntn ?? '-' }} | CNIC: {{ $rows->first()->buyer_cnic ?? '-' }} | Invoices: {{ $rows->sum('invoice_count') }}</div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Period</th>
                    <th class="center">Invoices</th>
                    <th class="right">Value (Excl ST)</th>
                    <th class="right">Sales Tax</th>
                    <th class="center">WHT Rate</th>
                    <th class="right">WHT Amount</th>
                    <th class="right">Net Receivable</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                <tr>
                    <td>{{ $row->period_label }}</td>
                    <td class="center">{{ $row->invoice_count }}</td>
                    <td class="right">{{ number_format($row->total_value, 2) }}</td>
                    <td class="right">{{ number_format($row->total_sales_tax, 2) }}</td>
                    <td class="center"><span class="badge badge-amber">{{ number_format($row->avg_wht_rate, 1) }}%</span></td>
                    <td class="right bold amber">{{ number_format($row->total_wht, 2) }}</td>
                    <td class="right bold">{{ number_format($row->total_net_receivable, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td>Subtotal</td>
                    <td class="center">{{ $rows->sum('invoice_count') }}</td>
                    <td class="right">{{ number_format($rows->sum('total_value'), 2) }}</td>
                    <td class="right">{{ number_format($rows->sum('total_sales_tax'), 2) }}</td>
                    <td class="center">-</td>
                    <td class="right">{{ number_format($rows->sum('total_wht'), 2) }}</td>
                    <td class="right">{{ number_format($rows->sum('total_net_receivable'), 2) }}</td>
                </tr>
            </tfoot>
        </table>
        @endforeach
    @else
        <table>
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Party Name</th>
                    <th>NTN</th>
                    <th>CNIC</th>
                    <th class="center">Invoices</th>
                    <th class="right">Value (Excl ST)</th>
                    <th class="right">Sales Tax</th>
                    <th class="center">WHT Rate</th>
                    <th class="right">WHT Amount</th>
                    <th class="right">Net Receivable</th>
                </tr>
            </thead>
            <tbody>
                @forelse($results as $row)
                <tr>
                    <td>{{ $row->period_label }}</td>
                    <td class="bold">{{ $row->buyer_name }}</td>
                    <td>{{ $row->buyer_ntn ?? '-' }}</td>
                    <td>{{ $row->buyer_cnic ?? '-' }}</td>
                    <td class="center">{{ $row->invoice_count }}</td>
                    <td class="right">{{ number_format($row->total_value, 2) }}</td>
                    <td class="right">{{ number_format($row->total_sales_tax, 2) }}</td>
                    <td class="center"><span class="badge badge-amber">{{ number_format($row->avg_wht_rate, 1) }}%</span></td>
                    <td class="right bold amber">{{ number_format($row->total_wht, 2) }}</td>
                    <td class="right bold">{{ number_format($row->total_net_receivable, 2) }}</td>
                </tr>
                @empty
                <tr><td colspan="10" style="text-align:center; padding:20px; color:#9ca3af;">No WHT records found for the selected period.</td></tr>
                @endforelse
            </tbody>
            @if(count($results) > 0)
            <tfoot>
                <tr>
                    <td colspan="4">Grand Total</td>
                    <td class="center">{{ number_format($totals['invoice_count']) }}</td>
                    <td class="right">{{ number_format($totals['total_value'], 2) }}</td>
                    <td class="right">{{ number_format($totals['total_sales_tax'], 2) }}</td>
                    <td class="center">-</td>
                    <td class="right">{{ number_format($totals['total_wht'], 2) }}</td>
                    <td class="right">{{ number_format($totals['total_net_receivable'], 2) }}</td>
                </tr>
            </tfoot>
            @endif
        </table>
    @endif
@endsection
