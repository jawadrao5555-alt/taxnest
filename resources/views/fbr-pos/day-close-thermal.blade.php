<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Z-Report {{ $report->report_number }}</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body { background: #f3f4f6; font-family: 'Courier New', Courier, monospace; color: #000; }
    .toolbar { max-width: 340px; margin: 10px auto 0; display: flex; gap: 8px; }
    .toolbar button, .toolbar a { flex: 1; padding: 10px; font-size: 13px; font-weight: bold; border: none; border-radius: 8px; cursor: pointer; text-align: center; text-decoration: none; font-family: inherit; }
    .btn-print { background: #1e3a8a; color: #fff; }
    .btn-back { background: #e5e7eb; color: #111; }
    .receipt { width: 302px; margin: 10px auto 30px; background: #fff; padding: 10px 8px; font-size: 11px; line-height: 1.45; }
    .c { text-align: center; }
    .b { font-weight: bold; }
    .lg { font-size: 13px; }
    .xl { font-size: 15px; }
    .sm { font-size: 9px; }
    .hr { border-top: 1px dashed #000; margin: 5px 0; }
    .hr2 { border-top: 2px solid #000; margin: 5px 0; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 1px 0; font-size: 11px; vertical-align: top; }
    td.r { text-align: right; white-space: nowrap; }
    td.ct { text-align: center; }
    .sec { font-weight: bold; text-transform: uppercase; font-size: 10px; letter-spacing: 1px; margin-top: 4px; }
    .wrap { word-break: break-all; }
    @media print {
        html, body { background: #fff; }
        .toolbar { display: none; }
        @php $zIs58 = ($company->print_paper_size ?? 'thermal') === 'thermal58'; @endphp
        {{-- 58mm rolls print ~48mm — cap at printable width, never the physical width. --}}
        .receipt { width: 100%; max-width: {{ $zIs58 ? '48mm' : '72mm' }}; margin: 0; padding: 0 2mm; {{ $zIs58 ? 'font-size: 10px;' : '' }} }
        @page { margin: 3mm; size: {{ $zIs58 ? '58mm' : '80mm' }} auto; }
    }
    {{-- COMPANY PRINT POSITION (31 Jul 2026, ported from PRA slips): opt-in
         center / left-margin correction. The Z-report strip is `.receipt`
         (max-width capped) inside a full-width body, so position it there;
         default (all OFF) keeps the left-pinned behavior untouched. --}}
    @php
        $pmAlign = (bool) ($company->kot_align_center ?? false);
        $pmMm    = max(0, min(30, (int) ($company->kot_left_margin_mm ?? 0)));
    @endphp
    @if($pmAlign)
    @media print { html body .receipt { margin-left: auto; margin-right: auto; } }
    @elseif($pmMm > 0)
    @media print { html body .receipt { margin-left: {{ $pmMm }}mm; margin-right: auto; } }
    @endif
</style>
</head>
<body>
<div class="toolbar">
    <button class="btn-print" onclick="window.print()">🖨 {{ __('pos.dc_print_zreport') }}</button>
    <a class="btn-back" href="{{ route('fbrpos.day-close', ['date' => $report->report_date->format('Y-m-d')]) }}">{{ __('pos.receipt_back') }}</a>
</div>

<div class="receipt">
    <div class="c b lg">{{ $company->name }}</div>
    @if($company->address)<div class="c sm">{{ $company->address }}</div>@endif
    @if($company->ntn)<div class="c sm">NTN: {{ $company->ntn }}</div>@endif
    <div class="hr2"></div>
    <div class="c b xl">{{ __('pos.dc_zreport') }}</div>
    <div class="c">{{ $report->report_number }}</div>
    <div class="c">{{ $report->report_date->format('l, d M Y') }}</div>
    <div class="c sm">{{ __('pos.dc_closed') }}: {{ $report->created_at->format('d/m/Y h:i A') }}@if($report->closedByUser) {{ __('pos.dcp_by_word') }} {{ $report->closedByUser->name }}@endif</div>
    <div class="hr2"></div>

    <div class="sec">{{ __('pos.dc_sales_summary') }}</div>
    <table>
        <tr><td>{{ __('pos.dc_total_invoices') }}</td><td class="r b">{{ $report->total_invoices }}</td></tr>
        <tr><td>{{ __('pos.dc_gross_sales') }}</td><td class="r">{{ number_format($report->gross_sales, 2) }}</td></tr>
        @if($report->total_discount > 0)
        <tr><td>{{ __('pos.dc_discount') }}</td><td class="r">-{{ number_format($report->total_discount, 2) }}</td></tr>
        @endif
        <tr><td>{{ __('pos.dc_net_sales') }}</td><td class="r">{{ number_format($report->net_sales, 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_sales_tax') }}</td><td class="r">{{ number_format($report->total_tax, 2) }}</td></tr>
        @if($report->total_fbr_fee > 0)
        <tr><td>{{ __('pos.dcp_fbr_pos_fee') }}</td><td class="r">{{ number_format($report->total_fbr_fee, 2) }}</td></tr>
        @endif
        <tr><td class="b lg">{{ __('pos.dc_total_revenue') }}</td><td class="r b lg">{{ number_format($report->total_amount, 2) }}</td></tr>
    </table>
    <div class="hr"></div>

    <div class="sec">{{ __('pos.dc_payments') }}</div>
    <table>
        <tr><td>{{ __('pos.dc_cash') }}</td><td class="r">{{ number_format($report->cash_amount, 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_card') }}</td><td class="r">{{ number_format($report->card_amount, 2) }}</td></tr>
        @if($report->other_amount > 0)
        <tr><td>{{ __('pos.dc_other') }}</td><td class="r">{{ number_format($report->other_amount, 2) }}</td></tr>
        @endif
    </table>
    <div class="hr"></div>

    @if($report->counted_cash !== null)
    <div class="sec">{{ __('pos.dc_cash_recon') }}</div>
    <table>
        <tr><td>{{ __('pos.dc_opening_float') }}</td><td class="r">{{ number_format($report->opening_float ?? 0, 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_cash_sales') }}</td><td class="r">{{ number_format($report->cash_amount, 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_expected_drawer') }}</td><td class="r">{{ number_format($report->expected_cash ?? 0, 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_counted_cash') }}</td><td class="r">{{ number_format($report->counted_cash, 2) }}</td></tr>
        <tr>
            <td class="b">{{ __('pos.dc_variance') }} {{ abs((float) $report->cash_variance) < 0.01 ? __('pos.dc_balanced') : ((float) $report->cash_variance < 0 ? __('pos.dc_short') : __('pos.dc_over')) }}</td>
            <td class="r b">{{ (float) $report->cash_variance > 0 ? '+' : '' }}{{ number_format($report->cash_variance, 2) }}</td>
        </tr>
    </table>
    <div class="hr"></div>
    @endif

    <div class="sec">{{ __('pos.dc_fbr_status') }}</div>
    <table>
        <tr><td>{{ __('pos.dc_submitted') }}</td><td class="r">{{ $analytics->fbr_health->submitted }}</td></tr>
        @if($analytics->fbr_health->pending > 0)<tr><td>{{ __('pos.dc_pending') }}</td><td class="r">{{ $analytics->fbr_health->pending }}</td></tr>@endif
        @if($analytics->fbr_health->failed > 0)<tr><td>{{ __('pos.dc_failed') }}</td><td class="r">{{ $analytics->fbr_health->failed }}</td></tr>@endif
        @if($analytics->fbr_health->local > 0)<tr><td>{{ __('pos.dc_local') }}</td><td class="r">{{ $analytics->fbr_health->local }}</td></tr>@endif
    </table>
    <div class="hr"></div>

    @if($analytics->top_products->isNotEmpty())
    <div class="sec">{{ __('pos.dc_top_products') }}</div>
    <table>
        @foreach($analytics->top_products->take(5) as $pname => $p)
        <tr><td>{{ \Illuminate\Support\Str::limit($pname, 18) }}</td><td class="ct">{{ rtrim(rtrim(number_format($p->qty, 2), '0'), '.') }}</td><td class="r">{{ number_format($p->revenue, 2) }}</td></tr>
        @endforeach
    </table>
    <div class="hr"></div>
    @endif

    @if($cashierBreakdown->isNotEmpty())
    <div class="sec">{{ __('pos.dc_cashiers') }}</div>
    <table>
        @foreach($cashierBreakdown as $name => $data)
        <tr><td>{{ \Illuminate\Support\Str::limit($name, 18) }}</td><td class="ct">{{ $data->count }}</td><td class="r">{{ number_format($data->revenue, 2) }}</td></tr>
        @endforeach
    </table>
    <div class="hr"></div>
    @endif

    <div class="sec">{{ __('pos.dc_stats') }}</div>
    <table>
        <tr><td>{{ __('pos.dc_avg_bill') }}</td><td class="r">{{ number_format($analytics->avg_bill, 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_unique_customers') }}</td><td class="r">{{ $analytics->unique_customers }}</td></tr>
        @if($analytics->discounts->total > 0)
        <tr><td>{{ __('pos.dc_discounts') }} ({{ $analytics->discounts->bill_count }} {{ __('pos.dc_bills_sfx') }})</td><td class="r">{{ number_format($analytics->discounts->total, 2) }}</td></tr>
        @endif
    </table>
    <div class="hr"></div>

    <table>
        <tr><td>{{ __('pos.dc_first_inv') }}</td><td class="r">{{ $report->first_invoice_number ?? '-' }}</td></tr>
        <tr><td>{{ __('pos.dc_last_inv') }}</td><td class="r">{{ $report->last_invoice_number ?? '-' }}</td></tr>
    </table>
    <div class="hr2"></div>
    <div class="c sm">{{ __('pos.dcp_sha256_hash') }}</div>
    <div class="c sm wrap">{{ $report->hash }}</div>
    <div class="hr"></div>
    <div class="c sm">{{ __('pos.dcp_sys_zreport_fbr') }}</div>
    <div class="c sm b">{{ __('pos.dcp_powered_nestpos') }}</div>
</div>
</body>
</html>
