@extends('reports.pdf-layout')

@section('content')
    <div class="report-title">
        <h2>Tax Collection Summary - {{ $year }}</h2>
        <div class="period">Financial Year {{ $year }} | View: {{ ucfirst($viewType ?? 'Whole') }}{{ isset($partyName) && $partyName ? ' | Party: ' . $partyName : '' }}</div>
    </div>

    <table class="summary-table">
        <tr>
            <td style="width:20%; padding:4px;">
                <div class="summary-card highlight">
                    <div class="value">{{ number_format($yearTotals['invoice_count']) }}</div>
                    <div class="label">Total Invoices</div>
                </div>
            </td>
            <td style="width:20%; padding:4px;">
                <div class="summary-card">
                    <div class="value">PKR {{ number_format($yearTotals['total_billed'], 2) }}</div>
                    <div class="label">Total Billed</div>
                </div>
            </td>
            <td style="width:20%; padding:4px;">
                <div class="summary-card highlight">
                    <div class="value">PKR {{ number_format($yearTotals['total_sales_tax'], 2) }}</div>
                    <div class="label">Sales Tax Collected</div>
                </div>
            </td>
            <td style="width:20%; padding:4px;">
                <div class="summary-card warning">
                    <div class="value">PKR {{ number_format($yearTotals['total_wht'], 2) }}</div>
                    <div class="label">WHT Collected</div>
                </div>
            </td>
            <td style="width:20%; padding:4px;">
                <div class="summary-card">
                    <div class="value">PKR {{ number_format($yearTotals['total_net'], 2) }}</div>
                    <div class="label">Net Amount</div>
                </div>
            </td>
        </tr>
    </table>

    @if(isset($viewType) && $viewType === 'partywise')
        @foreach($partyGroups as $party => $rows)
        <div class="party-header">
            <h3>{{ $party }}</h3>
            <div class="detail">Invoices: {{ $rows->sum('invoice_count') }} | Total Billed: PKR {{ number_format($rows->sum('total_billed'), 2) }}</div>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th class="center">Invoices</th>
                    <th class="right">Total Billed</th>
                    <th class="right">Sales Tax</th>
                    <th class="right">WHT</th>
                    <th class="right">Net Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                <tr>
                    <td class="bold">{{ \Carbon\Carbon::createFromFormat('Y-m', $row->month_label)->format('F Y') }}</td>
                    <td class="center">{{ $row->invoice_count }}</td>
                    <td class="right">PKR {{ number_format($row->total_billed, 2) }}</td>
                    <td class="right green bold">PKR {{ number_format($row->total_sales_tax, 2) }}</td>
                    <td class="right amber bold">PKR {{ number_format($row->total_wht, 2) }}</td>
                    <td class="right bold">PKR {{ number_format($row->total_net, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td>Subtotal</td>
                    <td class="center">{{ $rows->sum('invoice_count') }}</td>
                    <td class="right">PKR {{ number_format($rows->sum('total_billed'), 2) }}</td>
                    <td class="right">PKR {{ number_format($rows->sum('total_sales_tax'), 2) }}</td>
                    <td class="right">PKR {{ number_format($rows->sum('total_wht'), 2) }}</td>
                    <td class="right">PKR {{ number_format($rows->sum('total_net'), 2) }}</td>
                </tr>
            </tfoot>
        </table>
        @endforeach

        <div class="section-title">Grand Total - All Parties</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Party</th>
                    <th class="center">Invoices</th>
                    <th class="right">Total Billed</th>
                    <th class="right">Sales Tax</th>
                    <th class="right">WHT</th>
                    <th class="right">Net Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($partyGroups as $party => $rows)
                <tr>
                    <td class="bold">{{ $party }}</td>
                    <td class="center">{{ $rows->sum('invoice_count') }}</td>
                    <td class="right">PKR {{ number_format($rows->sum('total_billed'), 2) }}</td>
                    <td class="right green bold">PKR {{ number_format($rows->sum('total_sales_tax'), 2) }}</td>
                    <td class="right amber bold">PKR {{ number_format($rows->sum('total_wht'), 2) }}</td>
                    <td class="right bold">PKR {{ number_format($rows->sum('total_net'), 2) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td>Grand Total</td>
                    <td class="center">{{ number_format($yearTotals['invoice_count']) }}</td>
                    <td class="right">PKR {{ number_format($yearTotals['total_billed'], 2) }}</td>
                    <td class="right">PKR {{ number_format($yearTotals['total_sales_tax'], 2) }}</td>
                    <td class="right">PKR {{ number_format($yearTotals['total_wht'], 2) }}</td>
                    <td class="right">PKR {{ number_format($yearTotals['total_net'], 2) }}</td>
                </tr>
            </tfoot>
        </table>
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th class="center">Invoices</th>
                    <th class="right">Total Billed</th>
                    <th class="right">Sales Tax Collected</th>
                    <th class="right">WHT Collected</th>
                    <th class="right">Net Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse($monthly as $row)
                <tr>
                    <td class="bold">{{ \Carbon\Carbon::createFromFormat('Y-m', $row->month_label)->format('F Y') }}</td>
                    <td class="center">{{ $row->invoice_count }}</td>
                    <td class="right">PKR {{ number_format($row->total_billed, 2) }}</td>
                    <td class="right green bold">PKR {{ number_format($row->total_sales_tax, 2) }}</td>
                    <td class="right amber bold">PKR {{ number_format($row->total_wht, 2) }}</td>
                    <td class="right bold">PKR {{ number_format($row->total_net, 2) }}</td>
                </tr>
                @empty
                <tr><td colspan="6" style="text-align:center; padding:20px; color:#9ca3af;">No production invoices found for {{ $year }}.</td></tr>
                @endforelse
            </tbody>
            @if($monthly->count() > 0)
            <tfoot>
                <tr>
                    <td>Yearly Total</td>
                    <td class="center">{{ number_format($yearTotals['invoice_count']) }}</td>
                    <td class="right">PKR {{ number_format($yearTotals['total_billed'], 2) }}</td>
                    <td class="right">PKR {{ number_format($yearTotals['total_sales_tax'], 2) }}</td>
                    <td class="right">PKR {{ number_format($yearTotals['total_wht'], 2) }}</td>
                    <td class="right">PKR {{ number_format($yearTotals['total_net'], 2) }}</td>
                </tr>
            </tfoot>
            @endif
        </table>
    @endif

    @if($yearTotals['total_sales_tax'] > 0)
    <div style="background:#ecfdf5; border:1px solid #a7f3d0; border-radius:6px; padding:12px; margin-top:12px;">
        <div style="font-size:11px; font-weight:700; color:#065f46;">FBR Payment Due</div>
        <div style="font-size:9px; color:#047857; margin-top:3px;">Total Sales Tax collected in {{ $year }} to be deposited with FBR: <strong>PKR {{ number_format($yearTotals['total_sales_tax'], 2) }}</strong></div>
    </div>
    @endif
@endsection
