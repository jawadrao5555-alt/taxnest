<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $specimen->barcode }}</title></head>
<body style="text-align:center">
    <barcode code="{{ $specimen->barcode }}" type="C128B" size="0.75" height="0.75" />
    <p style="font-family:dejavusans, sans-serif;font-size:10pt;margin:1mm 0">{{ $specimen->barcode }}</p>
    <p style="font-family:dejavusans, sans-serif;font-size:7pt;margin:1mm 0">{{ $order->order_no }}</p>
</body>
</html>
