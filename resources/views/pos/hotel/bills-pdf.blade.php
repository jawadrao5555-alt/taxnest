<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:9px}table{width:100%;border-collapse:collapse}th,td{padding:5px;border-bottom:1px solid #ddd;text-align:left}th{background:#eee}a{color:#123}span{display:block;font-size:8px}</style></head><body>
<h2>{{ $company->name }} · {{ __('hotel_billing.title') }}</h2>
<p>{{ $dateLabel }} · {{ $stream }} · {{ $taxRateLabel }}</p>
<p>{{ __('hotel_billing.net_bills') }}: {{ number_format($summary->total_sales, 2) }} · {{ __('pos.kpi_total_tax') }}: {{ number_format($summary->total_tax, 2) }}</p>
@include('pos.hotel._billing-table', ['billingPdf' => true])
<p>{{ __('hotel_billing.balance_help') }}</p>
</body></html>
