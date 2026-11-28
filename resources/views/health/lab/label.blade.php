<!doctype html>
@php $urduScript = ($browserPrint ?? false) && app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT; @endphp
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8"><title>{{ $specimen->barcode }}</title>
    <style>
        @page { size: 80mm 35mm; margin: 0; }
        body { margin: 0; }
        @if($urduScript)
            @include('partials.urdu-print-font')
            .print-action { font-family: 'Jameel Noori Nastaleeq', 'Noto Naskh Arabic', 'Urdu Typesetting', Tahoma, sans-serif; }
        @endif
        @media print { .print-action { display: none; } }
    </style>
</head>
<body style="text-align:center">
    @if($browserPrint ?? false)
        <button class="print-action" type="button" dir="{{ $urduScript ? 'rtl' : 'ltr' }}" onclick="window.print()">{{ __('health.print') }}</button>
    @endif
    <img src="{{ $barcodeSvg }}" alt="{{ $specimen->barcode }}" style="width:79.75mm;height:10mm;margin-top:2mm">
    <p dir="ltr" style="font-family:dejavusans, sans-serif;font-size:10pt;margin:1mm 0">{{ $specimen->barcode }}</p>
    <p dir="ltr" style="font-family:dejavusans, sans-serif;font-size:7pt;margin:1mm 0">{{ $order->order_no }}</p>
</body>
</html>
