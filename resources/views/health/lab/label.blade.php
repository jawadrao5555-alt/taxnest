<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $specimen->barcode }}</title>
    @if(isset($barcodeSvg))
        <style>
            @page { size: 80mm 35mm; margin: 0; }
            body { margin: 0; }
            @media print { .print-action { display: none; } }
        </style>
    @endif
</head>
<body style="text-align:center">
    @if($browserPrint ?? false)
        <button class="print-action" type="button" onclick="window.print()">{{ __('health.print') }}</button>
    @endif
    @if(isset($barcodeSvg))
        <img src="{{ $barcodeSvg }}" alt="{{ $specimen->barcode }}" style="width:79.75mm;height:10mm;margin-top:2mm">
    @else
        <barcode code="{{ $specimen->barcode }}" type="C128B" size="0.75" height="0.75" />
    @endif
    <p style="font-family:dejavusans, sans-serif;font-size:10pt;margin:1mm 0">{{ $specimen->barcode }}</p>
    <p style="font-family:dejavusans, sans-serif;font-size:7pt;margin:1mm 0">{{ $order->order_no }}</p>
</body>
</html>
