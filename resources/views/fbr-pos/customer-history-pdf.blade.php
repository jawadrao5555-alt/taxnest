<!DOCTYPE html>
{{-- Task 1260: FBR customer history PDF — port of pos/customer-history-pdf.blade.php
     (Mode column: Local vs FBR; no PRA spend-snapshot rows). --}}
<html lang="{{ ($pdfUrdu ?? false) ? 'ur' : 'en' }}" dir="{{ ($pdfUrdu ?? false) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 12px; margin: 0; }
        .head { border-bottom: 2px solid #111827; padding-bottom: 8px; margin-bottom: 14px; }
        .head h1 { font-size: 18px; margin: 0; }
        .head .sub { font-size: 11px; color: #6b7280; margin-top: 2px; }
        .meta { margin-bottom: 14px; font-size: 12px; }
        .meta b { display: inline-block; min-width: 90px; color: #374151; }
        .kpis { width: 100%; margin-bottom: 14px; border-collapse: collapse; }
        .kpis td { width: 33%; border: 1px solid #e5e7eb; padding: 8px 10px; }
        .kpis .lbl { font-size: 9px; color: #6b7280; text-transform: uppercase; }
        .kpis .val { font-size: 15px; font-weight: bold; }
        table.tx { width: 100%; border-collapse: collapse; }
        table.tx th { background: #f3f4f6; text-align: left; padding: 6px 8px; font-size: 10px; text-transform: uppercase; color: #4b5563; border-bottom: 1px solid #d1d5db; }
        table.tx td { padding: 6px 8px; border-bottom: 1px solid #eee; }
        table.tx .r { text-align: right; }
        .tot { font-weight: bold; }
        .foot { margin-top: 16px; font-size: 9px; color: #9ca3af; text-align: center; }
    </style>
    @if($pdfUrdu ?? false)
    <style>
        * { font-family: 'XB Riyaz', DejaVu Sans, sans-serif; }
        body { direction: rtl; }
        table.tx, .kpis { direction: rtl; }
        table.tx th { text-transform: none; letter-spacing: 0; }
        .kpis .lbl { text-transform: none; }
    </style>
    @endif
</head>
<body>
    <div class="head">
        <h1>{{ __('pos.ch_title') }}</h1>
        <div class="sub">{{ $company->name ?? '' }} &middot; {{ __('pos.dcp_generated') }} {{ now()->format('d M Y, H:i') }}</div>
    </div>

    <div class="meta">
        <div><b>{{ __('pos.ch_label_customer') }}</b> {{ $customer->name }}</div>
        <div><b>{{ __('pos.ch_label_phone') }}</b> {{ $customer->phone ?: '—' }}</div>
        @if($customer->cnic)<div><b>{{ __('pos.ch_label_cnic') }}</b> {{ $customer->cnic }}</div>@endif
        @if($customer->city)<div><b>{{ __('pos.ch_label_city') }}</b> {{ $customer->city }}</div>@endif
        <div><b>{{ __('pos.ch_label_type') }}</b> {{ ucfirst($customer->type) }}</div>
    </div>

    <table class="kpis">
        <tr>
            <td><div class="lbl">{{ __('pos.ch_total_orders') }}</div><div class="val">{{ number_format($totalOrders) }}</div></td>
            <td><div class="lbl">{{ __('pos.ch_total_spent') }}</div><div class="val">PKR {{ number_format($totalSpent, 0) }}</div></td>
            <td><div class="lbl">{{ __('pos.ch_avg_order') }}</div><div class="val">PKR {{ number_format($totalOrders > 0 ? $totalSpent / $totalOrders : 0, 0) }}</div></td>
        </tr>
    </table>

    <table class="tx">
        <thead>
            <tr>
                <th>{{ __('pos.dc_date') }}</th>
                <th>{{ __('pos.ch_col_invoice') }}</th>
                <th>{{ __('pos.ch_col_mode') }}</th>
                <th>{{ __('pos.ch_col_payment') }}</th>
                <th class="r">{{ __('pos.tr_col_subtotal') }}</th>
                <th class="r">{{ __('pos.tr_col_discount') }}</th>
                <th class="r">{{ __('pos.tr_col_tax_amt') }}</th>
                <th class="r">{{ __('pos.ch_col_total') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transactions as $t)
            <tr>
                <td>{{ $t->created_at->format('d M Y H:i') }}</td>
                <td>{{ $t->invoice_number }}</td>
                <td>{{ ($t->invoice_mode ?? '') === 'local' ? __('pos.dc_local') : 'FBR' }}</td>
                <td>{{ ucwords(str_replace('_', ' ', (string) $t->payment_method)) }}</td>
                <td class="r">{{ number_format($t->subtotal, 0) }}</td>
                <td class="r">{{ number_format($t->discount_amount, 0) }}</td>
                <td class="r">{{ number_format($t->tax_amount, 0) }}</td>
                <td class="r">{{ number_format($t->total_amount, 0) }}</td>
            </tr>
            @empty
            <tr><td colspan="8" style="text-align:center; padding:20px; color:#9ca3af;">{{ __('pos.ch_no_history') }}</td></tr>
            @endforelse
        </tbody>
        @if($transactions->count())
        <tfoot>
            <tr class="tot">
                <td colspan="7" class="r">{{ __('pos.ch_total_label') }}</td>
                <td class="r">PKR {{ number_format($totalSpent, 0) }}</td>
            </tr>
        </tfoot>
        @endif
    </table>

    <div class="foot">{{ __('pos.ch_footer') }}</div>
</body>
</html>
