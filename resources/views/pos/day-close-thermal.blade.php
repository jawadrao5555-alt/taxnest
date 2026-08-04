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
    .btn-print { background: #0A4D5C; color: #fff; }
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
        .receipt { width: 100%; max-width: 80mm; margin: 0; padding: 0 2mm; }
        @page { margin: 3mm; size: 80mm auto; }
    }
</style>
</head>
<body>
<div class="toolbar">
    <button class="btn-print" onclick="window.print()">🖨 {{ __('pos.dc_print_zreport') }}</button>
    <a class="btn-back" href="{{ route('pos.day-close', ['date' => $report->report_date->format('Y-m-d')]) }}">{{ __('pos.receipt_back') }}</a>
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

    @if($report->counted_cash !== null || $report->opening_float !== null)
    <div class="sec">{{ __('pos.dc_cash_recon') }}</div>
    <table>
        <tr><td>{{ __('pos.dc_opening_float') }}</td><td class="r">{{ number_format($report->opening_float ?? 0, 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_cash_sales') }}</td><td class="r">{{ number_format($report->cash_amount, 2) }}</td></tr>
        @if(is_array($report->rider_summary) && ($report->rider_summary['cash_out'] ?? 0) > 0)
        <tr><td>{{ __('pos.dc_rider_cash') }}</td><td class="r">-{{ number_format($report->rider_summary['cash_out'], 2) }}</td></tr>
        @endif
        @if(is_array($report->rider_summary) && ($report->rider_summary['cash_in'] ?? 0) > 0)
        <tr><td>{{ __('pos.dc_rider_settlements_old') }}</td><td class="r">+{{ number_format($report->rider_summary['cash_in'], 2) }}</td></tr>
        @endif
        <tr><td>{{ __('pos.dc_expected_drawer') }}</td><td class="r">{{ number_format($report->expected_cash ?? 0, 2) }}</td></tr>
        <tr><td>{{ __('pos.dc_counted_cash') }}</td><td class="r">{{ $report->counted_cash !== null ? number_format($report->counted_cash, 2) : '-' }}</td></tr>
        @if($report->counted_cash !== null)
        <tr>
            <td class="b">{{ __('pos.dc_variance') }} {{ abs((float) $report->cash_variance) < 0.01 ? __('pos.dc_balanced') : ((float) $report->cash_variance < 0 ? __('pos.dc_short') : __('pos.dc_over')) }}</td>
            <td class="r b">{{ (float) $report->cash_variance > 0 ? '+' : '' }}{{ number_format($report->cash_variance, 2) }}</td>
        </tr>
        @endif
    </table>
    <div class="hr"></div>
    @endif

    @if(is_array($report->rider_summary) && !empty($report->rider_summary['riders']))
    <div class="sec">{{ __('pos.dc_delivery_riders') }}</div>
    <table>
        @foreach($report->rider_summary['riders'] as $rr)
        <tr>
            <td>{{ $rr['name'] ?? '-' }} ({{ $rr['deliveries'] ?? 0 }} {{ __('pos.dcp_del_word') }})</td>
            <td class="r">{{ ($rr['cash_pending'] ?? 0) > 0 ? __('pos.dcp_owes') . ' ' . number_format($rr['cash_pending'], 2) : __('pos.dcp_clear') }}</td>
        </tr>
        @endforeach
    </table>
    <div class="hr"></div>
    @endif

    <div class="sec">{{ __('pos.dc_pra_status') }}</div>
    <table>
        <tr><td>{{ __('pos.dc_submitted') }}</td><td class="r">{{ $analytics->pra_health->submitted }}</td></tr>
        @if($analytics->pra_health->pending > 0)<tr><td>{{ __('pos.dc_pending') }}</td><td class="r">{{ $analytics->pra_health->pending }}</td></tr>@endif
        @if($analytics->pra_health->offline > 0)<tr><td>{{ __('pos.dc_offline_queue') }}</td><td class="r">{{ $analytics->pra_health->offline }}</td></tr>@endif
        @if($analytics->pra_health->failed > 0)<tr><td>{{ __('pos.dc_failed') }}</td><td class="r">{{ $analytics->pra_health->failed }}</td></tr>@endif
        @if($analytics->pra_health->not_reported > 0)<tr><td>{{ __('pos.dc_not_reported') }}</td><td class="r">{{ $analytics->pra_health->not_reported }}</td></tr>@endif
    </table>
    <div class="hr"></div>

    @if($analytics->categories->isNotEmpty())
    <div class="sec">{{ __('pos.dc_category_sales') }}</div>
    <table>
        @foreach($analytics->categories as $catName => $cat)
        <tr><td>{{ \Illuminate\Support\Str::limit($catName, 18) }}</td><td class="ct">{{ rtrim(rtrim(number_format($cat->qty, 2), '0'), '.') }}</td><td class="r">{{ number_format($cat->revenue, 2) }}</td></tr>
        @endforeach
    </table>
    <div class="hr"></div>
    @endif

    @if($analytics->top_products->isNotEmpty())
    <div class="sec">{{ __('pos.dc_top_products') }}</div>
    <table>
        @foreach($analytics->top_products->take(5) as $pname => $p)
        <tr><td>{{ \Illuminate\Support\Str::limit($pname, 18) }}</td><td class="ct">{{ rtrim(rtrim(number_format($p->qty, 2), '0'), '.') }}</td><td class="r">{{ number_format($p->revenue, 2) }}</td></tr>
        @endforeach
    </table>
    <div class="hr"></div>
    @endif

    @if($analytics->restaurant_enabled && $analytics->order_types->isNotEmpty())
    <div class="sec">{{ __('pos.dc_order_types') }}</div>
    <table>
        @foreach($analytics->order_types as $type => $ot)
        <tr><td>{{ ['dine_in' => __('pos.dc_dine_in'), 'takeaway' => __('pos.dc_takeaway'), 'delivery' => __('pos.dc_delivery'), 'counter' => __('pos.dc_counter')][$type] ?? ucfirst(str_replace('_', ' ', $type)) }}</td><td class="ct">{{ $ot->count }}</td><td class="r">{{ number_format($ot->revenue, 2) }}</td></tr>
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

    {{-- Staff Attendance / Hazri (owner batch, 26 Jul 2026). * = no logout
         pressed — last activity time shown instead. --}}
    @if(!empty($hazri))
    <div class="sec">{{ __('pos.dc_staff_hazri') }}</div>
    <table>
        @foreach($hazri as $h)
        <tr>
            <td>{{ \Illuminate\Support\Str::limit($h->name, 14) }}</td>
            <td class="r">{{ $h->first_in ? \Carbon\Carbon::parse($h->first_in)->format('h:iA') : '-' }} &rarr; {{ $h->last_out ? \Carbon\Carbon::parse($h->last_out)->format('h:iA') : ($h->last_seen ? \Carbon\Carbon::parse($h->last_seen)->format('h:iA') . '*' : '-') }} &middot; {{ $h->bill_count }} {{ __('pos.dcp_bills_word') }}</td>
        </tr>
        @endforeach
    </table>
    <div class="hr"></div>
    @endif

    {{-- Biometric Punches (Aug 2026): from registered ZKTeco/ADMS devices.
         Same plan gate as session hazri; empty = section hidden. --}}
    @if(!empty($bioPunches))
    <div class="sec">{{ __('pos.bio_hazri_section') }}</div>
    <table>
        @foreach($bioPunches as $bp)
        <tr>
            <td>{{ \Illuminate\Support\Str::limit($bp->name ?? __('pos.bio_unmapped_pin', ['pin' => $bp->device_pin ?? '?']), 14) }}</td>
            <td class="r">{{ $bp->first_in ? \Carbon\Carbon::parse($bp->first_in)->format('h:iA') : '-' }} &rarr; {{ $bp->last_out ? \Carbon\Carbon::parse($bp->last_out)->format('h:iA') : '-' }} &middot; {{ \App\Support\PosHazriDutyHours::format($bp->duty_minutes ?? 0) }}{{ !empty($bp->duty_open) ? '*' : '' }}</td>
        </tr>
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
    <div class="c sm">{{ __('pos.dcp_sys_zreport_pra') }}</div>
    <div class="c sm b">{{ __('pos.dcp_powered_nestpos') }}</div>
</div>
</body>
</html>
