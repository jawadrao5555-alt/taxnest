<!DOCTYPE html>
<html lang="{{ ($pdfUrdu ?? false) ? 'ur' : app()->getLocale() }}" dir="{{ ($pdfUrdu ?? false) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('pos.top_selling_items') }}</title>
    <style>
        @page { margin: 12mm 15mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #111827; }
        h1 { font-size: 18px; }
        h2 { font-size: 14px; }
        p { margin: 3px 0; }
        .item-sales-table { width: 100%; margin-top: 14px; border-collapse: collapse; }
        .item-sales-table th, .item-sales-table td { padding: 6px; border-bottom: 1px solid #d1d5db; }
        .item-sales-table thead, .item-sales-table tfoot { background: #f3f4f6; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        @if($pdfUrdu ?? false)
        body { font-family: 'Jameel Noori Nastaleeq', 'XB Riyaz', 'DejaVu Sans', sans-serif; }
        @endif
    </style>
    @include('pos.partials.report-print-style')
</head>
<body>
    <h1>{{ $company->name }}</h1>
    <h2>{{ __('pos.top_selling_items') }}</h2>
    <p>{{ __('pos.ra_mode_prefix') }} {{ strtoupper($tab) }} · {{ $itemSales['from'] }} — {{ $itemSales['to'] }}</p>
    @if($itemSales['cashier'])<p>{{ __('pos.lbl_view_sales_by') }} · {{ $itemSales['cashier'] }}</p>@endif
    @if($itemSales['category'] !== '')<p>{{ __('pos.th_category') }} · {{ $itemSales['category'] }}</p>@endif
    @if($itemSales['item'] !== '')<p>{{ __('pos.item_name') }} · {{ $itemSales['item'] }}</p>@endif
    @include('pos.partials.report-item-sales-table')
</body>
</html>
