<!doctype html><html lang="en"><head><meta charset="utf-8"><style>body{font:12pt DejaVu Sans,sans-serif;color:#16333b}p{margin:8px 0}h1{font-size:19pt}.result{white-space:pre-wrap;border-top:1px solid #bbc;padding-top:12px}</style></head>
<body><h1>{{ $order->service === 'lab' ? 'Laboratory' : 'Radiology' }} report</h1>
<p>{{ $patient->name }} · MRN {{ $patient->mrn }}</p><p>{{ $order->test_name }} · Accession {{ $order->accession }}</p>
<p class="result">{{ $order->result }} {{ $order->unit }}</p>@if($order->reference_interval)<p>Reference interval {{ $order->reference_interval }}</p>@endif
<p>Released {{ $order->released_at }} · Staff ID {{ $order->released_by }}</p></body></html>
