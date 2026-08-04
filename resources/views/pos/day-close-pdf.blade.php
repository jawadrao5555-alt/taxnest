<!DOCTYPE html>
<html lang="{{ ($pdfUrdu ?? false) ? 'ur' : 'en' }}" dir="{{ ($pdfUrdu ?? false) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>Day Close Report {{ $report->report_number }}</title>
    <style>
        @page { margin: 10mm 15mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif; font-size: 11px; color: #000000; line-height: 1.5; background: #fff; }

        .header { background-color: #1e1b4b; padding: 16px 20px; text-align: center; margin-bottom: 14px; }
        .header h1 { font-size: 16px; font-weight: bold; color: #ffffff; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 4px; }
        .header p { font-size: 10px; color: #e5e7eb; }

        .report-title { text-align: center; margin-bottom: 14px; }
        .report-title h2 { font-size: 14px; font-weight: bold; color: #1e1b4b; text-transform: uppercase; letter-spacing: 1px; }
        .report-title p { font-size: 10px; color: #374151; margin-top: 2px; }

        .info-box { border: 2px solid #1e1b4b; padding: 8px 14px; margin-bottom: 14px; }
        .info-row { display: table; width: 100%; }
        .info-row .lbl { display: table-cell; width: 36%; font-size: 10px; font-weight: bold; padding: 3px 0; color: #000000; }
        .info-row .val { display: table-cell; width: 64%; font-size: 10px; text-align: right; padding: 3px 0; color: #000000; font-weight: 700; }

        .section-title { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; color: #1e1b4b; margin: 12px 0 6px; padding-bottom: 3px; border-bottom: 1.5px solid #1e1b4b; }

        table.data { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.data thead th { font-size: 9px; text-transform: uppercase; padding: 6px 5px; text-align: left; font-weight: bold; color: #ffffff; background-color: #1e1b4b; }
        table.data thead th.r { text-align: right; }
        table.data thead th.c { text-align: center; }
        table.data tbody td { font-size: 10px; padding: 5px 5px; border-bottom: 1px solid #d1d5db; color: #000000; }
        table.data tbody td.r { text-align: right; font-weight: 700; }
        table.data tbody td.c { text-align: center; }
        table.data tbody tr:nth-child(even) { background-color: #f5f3ff; }
        table.data tfoot td { font-size: 10px; padding: 6px 5px; font-weight: bold; border-top: 2px solid #1e1b4b; color: #000000; }
        table.data tfoot td.r { text-align: right; }

        .summary-box { background-color: #1e1b4b; padding: 10px 16px; margin: 10px 0; display: table; width: 100%; }
        .summary-box .lbl { display: table-cell; text-align: left; font-size: 14px; font-weight: bold; color: #ffffff; vertical-align: middle; }
        .summary-box .val { display: table-cell; text-align: right; font-size: 14px; font-weight: bold; color: #ffffff; vertical-align: middle; }

        .hash-box { margin-top: 10px; padding: 6px; border: 1px solid #d1d5db; text-align: center; }
        .hash-box p { font-size: 7px; color: #374151; word-break: break-all; }
        .hash-box .label { font-size: 8px; font-weight: bold; color: #1e1b4b; margin-bottom: 2px; }

        .footer { margin-top: 14px; text-align: center; padding-top: 8px; border-top: 1.5px solid #9ca3af; }
        .footer p { font-size: 9px; color: #374151; }
        .footer .brand { font-size: 10px; font-weight: bold; color: #1e1b4b; margin-top: 3px; }
    </style>
    @if($pdfUrdu ?? false)
    <style>
        /* Urdu script: XB Riyaz OTL shaping (mPDF default_font), RTL layout,
           no uppercase/letter-spacing which disrupt Arabic contextual joining. */
        body { font-family: 'XB Riyaz', 'DejaVu Sans', sans-serif; direction: rtl; }
        table.data, .info-box, .info-row { direction: rtl; }
        .section-title { text-transform: none; letter-spacing: 0; }
        table.data thead th { text-transform: none; letter-spacing: 0; }
        .header h1 { text-transform: none; letter-spacing: 0; }
        .report-title h2 { text-transform: none; letter-spacing: 0; }
    </style>
    @endif
</head>
<body>
    <div class="header">
        <h1>{{ $company->name }}</h1>
        @if($company->address)<p>{{ $company->address }}</p>@endif
        @if($company->phone)<p>{{ __('pos.rcpt_tel') }} {{ $company->phone }}</p>@endif
        @if($company->ntn)<p>NTN: {{ $company->ntn }}</p>@endif
    </div>

    <div class="report-title">
        <h2>{{ __('pos.dcp_report_title') }}</h2>
        <p>{{ __('pos.dcp_pra_eod_summary') }}</p>
        <p style="font-weight:bold; margin-top:4px;">{{ $report->report_date->format('l, d F Y') }}</p>
    </div>

    <div class="info-box">
        <div class="info-row">
            <div class="lbl">{{ __('pos.dc_report_no') }}:</div>
            <div class="val">{{ $report->report_number }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">{{ __('pos.dcp_report_date') }}</div>
            <div class="val">{{ $report->report_date->format('d/m/Y') }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">{{ __('pos.dc_generated') }}:</div>
            <div class="val">{{ $report->created_at->format('d/m/Y h:i A') }}</div>
        </div>
    </div>

    <div class="section-title">{{ __('pos.dcp_invoice_summary') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('pos.dc_th_category') }}</th>
                <th class="c">{{ __('pos.dc_th_count') }}</th>
                <th class="r">{{ __('pos.dc_th_amount_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ __('pos.dcp_pra_submitted_invoices') }}</td>
                <td class="c">{{ $report->pra_invoices }}</td>
                <td class="r">-</td>
            </tr>
            <tr>
                <td>{{ __('pos.dcp_local_invoices') }}</td>
                <td class="c">{{ $report->local_invoices }}</td>
                <td class="r">-</td>
            </tr>
            @if($report->offline_invoices > 0)
            <tr>
                <td>{{ __('pos.dcp_offline_invoices') }}</td>
                <td class="c">{{ $report->offline_invoices }}</td>
                <td class="r">-</td>
            </tr>
            @endif
            @if(($report->deleted_final_count ?? 0) > 0)
            <tr>
                <td>{{ __('pos.dcp_local_final_deleted') }}</td>
                <td class="c">{{ $report->deleted_final_count }}</td>
                <td class="r">-</td>
            </tr>
            @endif
            @if(($report->deleted_provisional_count ?? 0) > 0)
            <tr>
                <td>{{ __('pos.dcp_provisional_deleted') }}</td>
                <td class="c">{{ $report->deleted_provisional_count }}</td>
                <td class="r">-</td>
            </tr>
            @endif
        </tbody>
        <tfoot>
            <tr>
                <td>{{ __('pos.dc_total_invoices') }}</td>
                <td class="c" style="font-weight:bold;">{{ $report->total_invoices }}</td>
                <td class="r">-</td>
            </tr>
        </tfoot>
    </table>

    {{-- Local-bill wash detail (comprehensive Z-report, Jul 2026): what the close
         did with non-PRA local bills, incl. backlog swept from earlier dates. --}}
    @if(is_array($report->local_summary) && (collect($report->local_summary)->sum('count') > 0 || collect($report->local_summary)->sum('finalized') > 0))
    <div class="section-title">{{ __('pos.dcp_local_bills_closed') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('pos.dcp_bill_kind') }}</th>
                <th class="c">{{ __('pos.dcp_action') }}</th>
                <th class="c">{{ __('pos.dc_th_count') }}</th>
                <th class="c">{{ __('pos.dcp_from_earlier_dates') }}</th>
                <th class="r">{{ __('pos.dc_th_amount_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach(['provisional' => __('pos.dcp_provisional_lseries'), 'final_local' => __('pos.dcp_final_reporting_off')] as $kind => $label)
                @php $ls = $report->local_summary[$kind] ?? null; @endphp
                @if($ls && (($ls['count'] ?? 0) > 0 || ($ls['finalized'] ?? 0) > 0))
                <tr>
                    <td>{{ $label }}</td>
                    <td class="c">{{ ($ls['action'] ?? 'save') === 'delete' ? __('pos.dcp_deleted_per_policy') : (($ls['action'] ?? 'save') === 'carry' ? __('pos.dcp_carried') : (($ls['action'] ?? 'save') === 'finalize' ? __('pos.dcp_finalized') : __('pos.dcp_archived'))) }}</td>
                    <td class="c">{{ $ls['count'] }}</td>
                    <td class="c">{{ $ls['backlog'] ?? 0 }}</td>
                    <td class="r">{{ number_format($ls['amount'] ?? 0, 2) }}</td>
                </tr>
                @if(($ls['action'] ?? 'save') === 'finalize')
                {{-- Auto-finalize sweep detail (Aug 2026) --}}
                <tr>
                    <td colspan="5" style="font-size:9px;">{{ __('pos.wash_finalized_detail', ['count' => $ls['finalized'] ?? 0, 'amount' => number_format($ls['finalized_amount'] ?? 0), 'submitted' => $ls['submitted'] ?? 0, 'queued' => $ls['queued'] ?? 0, 'offline' => $ls['offline'] ?? 0, 'left' => $ls['count'] ?? 0]) }}</td>
                </tr>
                @endif
                @endif
            @endforeach
        </tbody>
    </table>
    @endif

    {{-- Delivery Riders (Jul 2026): rider day detail stored on the report --}}
    @if(is_array($report->rider_summary) && !empty($report->rider_summary['riders']))
    <div class="section-title">{{ __('pos.dc_delivery_riders') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('pos.dcp_rider') }}</th>
                <th class="c">{{ __('pos.dcp_deliveries') }}</th>
                <th class="c">{{ __('pos.dcp_delivered') }}</th>
                <th class="c">{{ __('pos.dcp_returned') }}</th>
                <th class="r">{{ __('pos.dcp_cash_bills_pkr') }}</th>
                <th class="r">{{ __('pos.dcp_unsettled_at_close_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($report->rider_summary['riders'] as $rr)
            <tr>
                <td>{{ $rr['name'] ?? '-' }}</td>
                <td class="c">{{ $rr['deliveries'] ?? 0 }}</td>
                <td class="c">{{ $rr['delivered'] ?? 0 }}</td>
                <td class="c">{{ $rr['returned'] ?? 0 }}</td>
                <td class="r">{{ number_format($rr['cash_total'] ?? 0, 2) }}</td>
                <td class="r" style="{{ ($rr['cash_pending'] ?? 0) > 0 ? 'color:#dc2626; font-weight:bold;' : '' }}">{{ ($rr['cash_pending'] ?? 0) > 0 ? number_format($rr['cash_pending'], 2) : __('pos.dcp_clear') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="section-title">{{ __('pos.dcp_financial_summary') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th style="width:60%;">{{ __('pos.dcp_description') }}</th>
                <th class="r" style="width:40%;">{{ __('pos.dc_th_amount_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ __('pos.dc_gross_sales') }}</td>
                <td class="r">{{ number_format($report->gross_sales, 2) }}</td>
            </tr>
            @if($report->total_discount > 0)
            <tr>
                <td>{{ __('pos.dcp_total_discount') }}</td>
                <td class="r" style="color:#dc2626;">-{{ number_format($report->total_discount, 2) }}</td>
            </tr>
            @endif
            <tr>
                <td>{{ __('pos.dc_net_sales') }}</td>
                <td class="r">{{ number_format($report->net_sales, 2) }}</td>
            </tr>
            <tr>
                <td>{{ __('pos.dcp_sales_tax_collected') }}</td>
                <td class="r">{{ number_format($report->total_tax, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="summary-box">
        <div class="lbl">{{ __('pos.dc_total_revenue') }}</div>
        <div class="val">PKR {{ number_format($report->total_amount, 2) }}</div>
    </div>

    <div class="section-title">{{ __('pos.dcp_payment_method_breakdown') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th style="width:60%;">{{ __('pos.dcp_payment_method') }}</th>
                <th class="r" style="width:40%;">{{ __('pos.dc_th_amount_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ __('pos.dc_cash') }}</td>
                <td class="r">{{ number_format($report->cash_amount, 2) }}</td>
            </tr>
            <tr>
                <td>{{ __('pos.dc_card') }}</td>
                <td class="r">{{ number_format($report->card_amount, 2) }}</td>
            </tr>
            @if($report->other_amount > 0)
            <tr>
                <td>{{ __('pos.dc_other') }}</td>
                <td class="r">{{ number_format($report->other_amount, 2) }}</td>
            </tr>
            @endif
        </tbody>
    </table>

    @if($report->counted_cash !== null || $report->opening_float !== null)
    <div class="section-title">{{ __('pos.dc_cash_recon') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th style="width:60%;">{{ __('pos.dcp_description') }}</th>
                <th class="r" style="width:40%;">{{ __('pos.dc_th_amount_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ __('pos.dc_opening_float') }}</td>
                <td class="r">{{ number_format($report->opening_float ?? 0, 2) }}</td>
            </tr>
            <tr>
                <td>{{ __('pos.dc_cash_sales') }}</td>
                <td class="r">{{ number_format($report->cash_amount, 2) }}</td>
            </tr>
            @if(is_array($report->rider_summary) && ($report->rider_summary['cash_out'] ?? 0) > 0)
            <tr>
                <td>{{ __('pos.dcp_cash_with_riders') }}</td>
                <td class="r" style="color:#dc2626;">-{{ number_format($report->rider_summary['cash_out'], 2) }}</td>
            </tr>
            @endif
            @if(is_array($report->rider_summary) && ($report->rider_summary['cash_in'] ?? 0) > 0)
            <tr>
                <td>{{ __('pos.dcp_rider_settlements_received') }}</td>
                <td class="r">+{{ number_format($report->rider_summary['cash_in'], 2) }}</td>
            </tr>
            @endif
            <tr>
                <td>{{ __('pos.dcp_expected_cash_drawer') }}</td>
                <td class="r">{{ number_format($report->expected_cash ?? 0, 2) }}</td>
            </tr>
            <tr>
                <td>{{ __('pos.dcp_counted_cash_physical') }}</td>
                <td class="r">{{ $report->counted_cash !== null ? number_format($report->counted_cash, 2) : '&mdash;' }}</td>
            </tr>
            @if($report->counted_cash !== null)
            <tr>
                <td style="font-weight:bold;">{{ __('pos.dc_variance') }} {{ abs((float) $report->cash_variance) < 0.01 ? __('pos.dc_balanced') : ((float) $report->cash_variance < 0 ? __('pos.dc_short') : __('pos.dc_over')) }}</td>
                <td class="r" style="font-weight:bold; {{ abs((float) $report->cash_variance) < 0.01 ? '' : 'color:#dc2626;' }}">{{ (float) $report->cash_variance > 0 ? '+' : '' }}{{ number_format($report->cash_variance, 2) }}</td>
            </tr>
            @endif
        </tbody>
    </table>
    @endif

    @if($cashierBreakdown->isNotEmpty())
    <div class="section-title">{{ __('pos.dcp_cashier_performance') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('pos.dcp_cashier') }}</th>
                <th class="c">{{ __('pos.dcp_sales') }}</th>
                <th class="r">{{ __('pos.dc_th_revenue_pkr') }}</th>
                <th class="r">{{ __('pos.dc_th_tax_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($cashierBreakdown as $name => $data)
            <tr>
                <td>{{ $name }}</td>
                <td class="c">{{ $data->count }}</td>
                <td class="r">{{ number_format($data->revenue, 2) }}</td>
                <td class="r">{{ number_format($data->tax, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    {{-- ═══ Staff Attendance / Hazri (owner batch, 26 Jul 2026) ═══
         From pos_user_sessions: first login, last logout (or last-seen when
         Logout was never pressed), bills + first/last sale per staff member. --}}
    @if(!empty($hazri))
    <div class="section-title">{{ __('pos.dcp_staff_attendance') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('pos.dcp_staff') }}</th>
                <th class="c">{{ __('pos.dcp_first_in') }}</th>
                <th class="c">{{ __('pos.dcp_last_out') }}</th>
                <th class="c">{{ __('pos.dcp_logins') }}</th>
                <th class="c">{{ __('pos.dcp_bills') }}</th>
                <th class="c">{{ __('pos.dcp_first_sale') }}</th>
                <th class="c">{{ __('pos.dcp_last_sale') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($hazri as $h)
            <tr>
                <td>{{ $h->name }}</td>
                <td class="c">{{ $h->first_in ? \Carbon\Carbon::parse($h->first_in)->format('h:i A') : '-' }}</td>
                <td class="c">
                    @if($h->last_out)
                        {{ \Carbon\Carbon::parse($h->last_out)->format('h:i A') }}
                    @elseif($h->last_seen)
                        {{ \Carbon\Carbon::parse($h->last_seen)->format('h:i A') }}*
                    @else
                        -
                    @endif
                </td>
                <td class="c">{{ $h->session_count }}</td>
                <td class="c">{{ $h->bill_count }}</td>
                <td class="c">{{ $h->first_sale ? \Carbon\Carbon::parse($h->first_sale)->format('h:i A') : '-' }}</td>
                <td class="c">{{ $h->last_sale ? \Carbon\Carbon::parse($h->last_sale)->format('h:i A') : '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div style="font-size:8px; color:#6b7280; margin-top:2px;">{{ __('pos.dcp_no_logout_note') }}</div>
    @endif

    {{-- ═══ Comprehensive Z-Report analytics (owner request Jul 2026) ═══ --}}
    @if($analytics->categories->isNotEmpty())
    <div class="section-title">{{ __('pos.dcp_category_wise_sales') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('pos.dc_th_category') }}</th>
                <th class="c">{{ __('pos.receipt_qty') }}</th>
                <th class="r">{{ __('pos.dc_th_revenue_pkr') }}</th>
                <th class="r">{{ __('pos.dc_th_tax_pkr') }}</th>
                <th class="c">{{ __('pos.dc_th_share') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($analytics->categories as $catName => $cat)
            <tr>
                <td>{{ $catName }}</td>
                <td class="c">{{ rtrim(rtrim(number_format($cat->qty, 2), '0'), '.') }}</td>
                <td class="r">{{ number_format($cat->revenue, 2) }}</td>
                <td class="r">{{ number_format($cat->tax, 2) }}</td>
                <td class="c">{{ $cat->share }}%</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if($analytics->top_products->isNotEmpty())
    <div class="section-title">{{ __('pos.dcp_top_products') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th class="c" style="width:8%;">#</th>
                <th>{{ __('pos.receipt_item') }}</th>
                <th class="c">{{ __('pos.receipt_qty') }}</th>
                <th class="r">{{ __('pos.dc_th_revenue_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($analytics->top_products as $pname => $p)
            <tr>
                <td class="c">{{ $loop->iteration }}</td>
                <td>{{ $pname }}</td>
                <td class="c">{{ rtrim(rtrim(number_format($p->qty, 2), '0'), '.') }}</td>
                <td class="r">{{ number_format($p->revenue, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="section-title">{{ __('pos.dcp_pra_submission_health') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th class="c">{{ __('pos.dc_submitted') }}</th>
                <th class="c">{{ __('pos.dc_pending') }}</th>
                <th class="c">{{ __('pos.dc_offline_queue') }}</th>
                <th class="c">{{ __('pos.dc_failed') }}</th>
                <th class="c">{{ __('pos.dcp_not_reported') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="c">{{ $analytics->pra_health->submitted }}</td>
                <td class="c">{{ $analytics->pra_health->pending }}</td>
                <td class="c">{{ $analytics->pra_health->offline }}</td>
                <td class="c">{{ $analytics->pra_health->failed }}</td>
                <td class="c">{{ $analytics->pra_health->not_reported }}</td>
            </tr>
        </tbody>
    </table>

    @if($analytics->discounts->total > 0)
    <div class="section-title">{{ __('pos.dcp_discount_summary') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th class="c">{{ __('pos.dcp_bills_with_discount') }}</th>
                <th class="r">{{ __('pos.dcp_bill_level_pkr') }}</th>
                <th class="r">{{ __('pos.dcp_item_level_pkr') }}</th>
                <th class="r">{{ __('pos.dcp_total_discount_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="c">{{ $analytics->discounts->bill_count }}</td>
                <td class="r">{{ number_format($analytics->discounts->bill_total, 2) }}</td>
                <td class="r">{{ number_format($analytics->discounts->item_total, 2) }}</td>
                <td class="r">{{ number_format($analytics->discounts->total, 2) }}</td>
            </tr>
        </tbody>
    </table>
    @endif

    @if($analytics->restaurant_enabled && $analytics->deals->isNotEmpty())
    <div class="section-title">{{ __('pos.dcp_deals_performance') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('pos.dcp_deal') }}</th>
                <th class="c">{{ __('pos.receipt_qty') }}</th>
                <th class="r">{{ __('pos.dc_th_revenue_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($analytics->deals as $dealName => $deal)
            <tr>
                <td>{{ $dealName }}</td>
                <td class="c">{{ rtrim(rtrim(number_format($deal->qty, 2), '0'), '.') }}</td>
                <td class="r">{{ number_format($deal->revenue, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    @if($analytics->restaurant_enabled && $analytics->order_types->isNotEmpty())
    <div class="section-title">{{ __('pos.dcp_order_type_split') }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('pos.dcp_order_type') }}</th>
                <th class="c">{{ __('pos.dcp_bills') }}</th>
                <th class="r">{{ __('pos.dc_th_revenue_pkr') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($analytics->order_types as $type => $ot)
            <tr>
                <td>{{ ['dine_in' => __('pos.dc_dine_in'), 'takeaway' => __('pos.dc_takeaway'), 'delivery' => __('pos.dc_delivery'), 'counter' => __('pos.dc_counter')][$type] ?? ucfirst(str_replace('_', ' ', $type)) }}</td>
                <td class="c">{{ $ot->count }}</td>
                <td class="r">{{ number_format($ot->revenue, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="section-title">{{ __('pos.dcp_day_stats_comparison') }}</div>
    <div class="info-box">
        <div class="info-row">
            <div class="lbl">{{ __('pos.dcp_avg_bill_value') }}</div>
            <div class="val">PKR {{ number_format($analytics->avg_bill, 2) }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">{{ __('pos.dcp_unique_customers') }}</div>
            <div class="val">{{ $analytics->unique_customers }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">{{ __('pos.dc_vs_yesterday') }} ({{ \Carbon\Carbon::parse($analytics->comparison->yesterday->date)->format('d M') }}):</div>
            <div class="val">PKR {{ number_format($analytics->comparison->yesterday->revenue, 2) }} ({{ $analytics->comparison->yesterday->invoices }} {{ __('pos.dcp_bills_word') }}){{ $analytics->comparison->vs_yesterday_revenue_pct !== null ? ' — ' . ($analytics->comparison->vs_yesterday_revenue_pct >= 0 ? '+' : '') . $analytics->comparison->vs_yesterday_revenue_pct . '% ' . __('pos.dcp_today_word') : '' }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">{{ __('pos.dc_vs_last_week') }} ({{ \Carbon\Carbon::parse($analytics->comparison->last_week->date)->format('d M') }}):</div>
            <div class="val">PKR {{ number_format($analytics->comparison->last_week->revenue, 2) }} ({{ $analytics->comparison->last_week->invoices }} {{ __('pos.dcp_bills_word') }}){{ $analytics->comparison->vs_last_week_revenue_pct !== null ? ' — ' . ($analytics->comparison->vs_last_week_revenue_pct >= 0 ? '+' : '') . $analytics->comparison->vs_last_week_revenue_pct . '% ' . __('pos.dcp_today_word') : '' }}</div>
        </div>
    </div>

    <div class="section-title">{{ __('pos.dc_invoice_range') }}</div>
    <div class="info-box">
        <div class="info-row">
            <div class="lbl">{{ __('pos.dcp_first_invoice') }}</div>
            <div class="val">{{ $report->first_invoice_number ?? '-' }} @ {{ $report->first_invoice_time ? $report->first_invoice_time->format('h:i A') : '-' }}</div>
        </div>
        <div class="info-row">
            <div class="lbl">{{ __('pos.dcp_last_invoice') }}</div>
            <div class="val">{{ $report->last_invoice_number ?? '-' }} @ {{ $report->last_invoice_time ? $report->last_invoice_time->format('h:i A') : '-' }}</div>
        </div>
    </div>

    @if($report->notes)
    <div class="section-title">{{ __('pos.dcp_notes') }}</div>
    <p style="font-size:10px; color:#374151; padding:4px 0;">{{ $report->notes }}</p>
    @endif

    <div class="hash-box">
        <div class="label">{{ __('pos.dcp_integrity_hash') }}</div>
        <p>{{ $report->hash }}</p>
    </div>

    <div class="footer">
        <p>{{ __('pos.dcp_sys_report_pra') }}</p>
        <div class="brand">{{ __('pos.dcp_powered_nestpos') }}</div>
        <p>{{ __('pos.dcp_generated') }} {{ $report->created_at->format('d/m/Y h:i:s A') }}</p>
    </div>
</body>
</html>
