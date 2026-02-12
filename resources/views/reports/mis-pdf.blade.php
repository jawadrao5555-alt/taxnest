@extends('reports.pdf-layout')

@section('content')
    <div class="report-title">
        <h2>MIS Report - {{ $reportTitle }}</h2>
        <div class="period">Generated: {{ now()->format('d M Y') }}{{ isset($viewType) && $viewType === 'partywise' ? ' | View: Party-wise' : '' }}</div>
    </div>

    @if($type === 'monthly')
        <table class="summary-table">
            <tr>
                <td style="width:25%; padding:4px;">
                    <div class="summary-card highlight">
                        <div class="value">{{ count($invoices) }}</div>
                        <div class="label">Total Invoices</div>
                    </div>
                </td>
                <td style="width:25%; padding:4px;">
                    <div class="summary-card">
                        <div class="value">{{ number_format($invoices->sum('total_amount'), 2) }}</div>
                        <div class="label">Total Revenue</div>
                    </div>
                </td>
                <td style="width:25%; padding:4px;">
                    <div class="summary-card highlight">
                        <div class="value">{{ number_format($invoices->sum('total_sales_tax'), 2) }}</div>
                        <div class="label">Sales Tax</div>
                    </div>
                </td>
                <td style="width:25%; padding:4px;">
                    <div class="summary-card warning">
                        <div class="value">{{ number_format($invoices->sum('wht_amount'), 2) }}</div>
                        <div class="label">WHT</div>
                    </div>
                </td>
            </tr>
        </table>

        @if(isset($viewType) && $viewType === 'partywise')
            @foreach($partyGroups as $party => $rows)
            <div class="party-header">
                <h3>{{ $party }}</h3>
                <div class="detail">Invoices: {{ count($rows) }} | Total: {{ number_format($rows->sum('total_amount'), 2) }}</div>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice No</th>
                        <th>Date</th>
                        <th>NTN</th>
                        <th class="right">Subtotal</th>
                        <th class="right">Sales Tax</th>
                        <th class="right">WHT</th>
                        <th class="right">Total</th>
                        <th class="center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $inv)
                    <tr>
                        <td class="bold">{{ $inv->internal_invoice_number ?? $inv->invoice_number }}</td>
                        <td>{{ $inv->invoice_date }}</td>
                        <td>{{ $inv->buyer_ntn ?? '-' }}</td>
                        <td class="right">{{ number_format($inv->total_value_excluding_st ?? ($inv->total_amount - $inv->total_sales_tax), 2) }}</td>
                        <td class="right green">{{ number_format($inv->total_sales_tax, 2) }}</td>
                        <td class="right amber">{{ number_format($inv->wht_amount, 2) }}</td>
                        <td class="right bold">{{ number_format($inv->total_amount, 2) }}</td>
                        <td class="center"><span class="badge {{ $inv->status === 'locked' ? 'badge-green' : ($inv->status === 'draft' ? 'badge-amber' : 'badge-red') }}">{{ $inv->status === 'locked' ? 'Production' : ($inv->status === 'submitted' ? 'Failed' : ucfirst($inv->status)) }}</span></td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3">Subtotal</td>
                        <td class="right">{{ number_format($rows->sum(fn($i) => $i->total_value_excluding_st ?? ($i->total_amount - $i->total_sales_tax)), 2) }}</td>
                        <td class="right">{{ number_format($rows->sum('total_sales_tax'), 2) }}</td>
                        <td class="right">{{ number_format($rows->sum('wht_amount'), 2) }}</td>
                        <td class="right">{{ number_format($rows->sum('total_amount'), 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            @endforeach
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice No</th>
                        <th>Date</th>
                        <th>Buyer Name</th>
                        <th>NTN</th>
                        <th class="right">Subtotal</th>
                        <th class="right">Sales Tax</th>
                        <th class="right">WHT</th>
                        <th class="right">Total</th>
                        <th class="center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($invoices as $inv)
                    <tr>
                        <td class="bold">{{ $inv->internal_invoice_number ?? $inv->invoice_number }}</td>
                        <td>{{ $inv->invoice_date }}</td>
                        <td>{{ $inv->buyer_name }}</td>
                        <td>{{ $inv->buyer_ntn ?? '-' }}</td>
                        <td class="right">{{ number_format($inv->total_value_excluding_st ?? ($inv->total_amount - $inv->total_sales_tax), 2) }}</td>
                        <td class="right green">{{ number_format($inv->total_sales_tax, 2) }}</td>
                        <td class="right amber">{{ number_format($inv->wht_amount, 2) }}</td>
                        <td class="right bold">{{ number_format($inv->total_amount, 2) }}</td>
                        <td class="center"><span class="badge {{ $inv->status === 'locked' ? 'badge-green' : ($inv->status === 'draft' ? 'badge-amber' : 'badge-red') }}">{{ $inv->status === 'locked' ? 'Production' : ($inv->status === 'submitted' ? 'Failed' : ucfirst($inv->status)) }}</span></td>
                    </tr>
                    @empty
                    <tr><td colspan="9" style="text-align:center; padding:20px; color:#9ca3af;">No invoices found.</td></tr>
                    @endforelse
                </tbody>
                @if(count($invoices) > 0)
                <tfoot>
                    <tr>
                        <td colspan="4">Grand Total</td>
                        <td class="right">{{ number_format($invoices->sum(fn($i) => $i->total_value_excluding_st ?? ($i->total_amount - $i->total_sales_tax)), 2) }}</td>
                        <td class="right">{{ number_format($invoices->sum('total_sales_tax'), 2) }}</td>
                        <td class="right">{{ number_format($invoices->sum('wht_amount'), 2) }}</td>
                        <td class="right">{{ number_format($invoices->sum('total_amount'), 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
                @endif
            </table>
        @endif

    @elseif($type === 'tax')
        <table class="data-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th class="right">Tax Collected</th>
                    <th class="right">Subtotal</th>
                    <th class="center">Effective Rate</th>
                </tr>
            </thead>
            <tbody>
                @foreach($taxSummary as $row)
                <tr>
                    <td class="bold">{{ $row['month'] }}</td>
                    <td class="right green bold">{{ number_format($row['tax_collected'], 2) }}</td>
                    <td class="right">{{ number_format($row['subtotal'], 2) }}</td>
                    <td class="center"><span class="badge badge-green">{{ $row['effective_rate'] }}%</span></td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td>Total</td>
                    <td class="right">{{ number_format(collect($taxSummary)->sum('tax_collected'), 2) }}</td>
                    <td class="right">{{ number_format(collect($taxSummary)->sum('subtotal'), 2) }}</td>
                    <td class="center">-</td>
                </tr>
            </tfoot>
        </table>

    @elseif($type === 'hs')
        <table class="data-table">
            <thead>
                <tr>
                    <th>HS Prefix</th>
                    <th class="center">Item Count</th>
                    <th class="right">Total Value</th>
                    <th class="right">Total Tax</th>
                    <th class="center">Tax Rate</th>
                </tr>
            </thead>
            <tbody>
                @foreach($hsData as $hs)
                <tr>
                    <td class="bold">{{ $hs->hs_prefix }}</td>
                    <td class="center">{{ $hs->item_count }}</td>
                    <td class="right">{{ number_format($hs->total_value, 2) }}</td>
                    <td class="right green bold">{{ number_format($hs->total_tax, 2) }}</td>
                    <td class="center"><span class="badge badge-green">{{ $hs->total_value > 0 ? number_format(($hs->total_tax / $hs->total_value) * 100, 1) : 0 }}%</span></td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td>Total</td>
                    <td class="center">{{ $hsData->sum('item_count') }}</td>
                    <td class="right">{{ number_format($hsData->sum('total_value'), 2) }}</td>
                    <td class="right">{{ number_format($hsData->sum('total_tax'), 2) }}</td>
                    <td class="center">-</td>
                </tr>
            </tfoot>
        </table>

    @elseif($type === 'vendor')
        <table class="data-table">
            <thead>
                <tr>
                    <th>Vendor Name</th>
                    <th>NTN</th>
                    <th class="center">Risk Score</th>
                    <th class="center">Total Invoices</th>
                    <th class="center">Rejected</th>
                    <th class="center">Tax Mismatches</th>
                    <th class="center">Anomalies</th>
                    <th class="center">Risk Level</th>
                </tr>
            </thead>
            <tbody>
                @forelse($vendors as $v)
                <tr>
                    <td class="bold">{{ $v->vendor_name ?? 'Unknown' }}</td>
                    <td>{{ $v->vendor_ntn }}</td>
                    <td class="center bold">{{ $v->vendor_score }}</td>
                    <td class="center">{{ $v->total_invoices }}</td>
                    <td class="center {{ $v->rejected_invoices > 0 ? 'amber' : '' }}">{{ $v->rejected_invoices }}</td>
                    <td class="center {{ $v->tax_mismatches > 0 ? 'amber' : '' }}">{{ $v->tax_mismatches }}</td>
                    <td class="center">{{ $v->anomaly_count }}</td>
                    <td class="center">
                        @if($v->vendor_score >= 80)
                            <span class="badge badge-green">Low Risk</span>
                        @elseif($v->vendor_score >= 50)
                            <span class="badge badge-amber">Medium</span>
                        @else
                            <span class="badge badge-red">High Risk</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" style="text-align:center; padding:20px; color:#9ca3af;">No vendor data available.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif
@endsection
