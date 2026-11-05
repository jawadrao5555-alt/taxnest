<!doctype html><html lang="en"><head><meta charset="utf-8"><style>body{font:12pt DejaVu Sans,sans-serif;color:#16333b}p{margin:8px 0}h1{font-size:19pt}.result{white-space:pre-wrap;border-top:1px solid #bbc;padding-top:12px}</style></head>
<body><h1>{{ $order->service === 'lab' ? 'Laboratory' : 'Radiology' }} report</h1>
<p>{{ $patient->name }} · MRN {{ $patient->mrn }}</p><p>{{ $order->test_name }} · Accession {{ $order->accession }}</p>
@php($current = $order->amendments->last())
<h2>{{ $current ? 'Corrected report · Revision '.$current->revision : 'Original signed report' }}</h2>
<p class="result">{{ $current?->result ?? $order->result }} {{ $order->unit }}</p>@if($order->reference_interval)<p>Reference interval {{ $order->reference_interval }}</p>@endif
<p>Signed {{ $current?->signed_at ?? $order->released_at }} · Staff ID {{ $current?->signed_by ?? $order->released_by }}</p>
@if($current)
<p>Correction reason: {{ $current->reason }}</p>
<h2>Superseded results — history only</h2>
<p>Original · {{ $order->released_at }} · Staff ID {{ $order->released_by }}</p><p class="result">{{ $order->result }}</p>
@foreach($order->amendments as $amendment)
@if($amendment->id !== $current->id)
<p>Revision {{ $amendment->revision }} · {{ $amendment->signed_at }} · Staff ID {{ $amendment->signed_by }}</p>
<p class="result">{{ $amendment->result }}</p><p>Correction reason: {{ $amendment->reason }}</p>
@endif
@endforeach
@endif
</body></html>
