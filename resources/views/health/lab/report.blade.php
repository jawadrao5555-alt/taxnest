<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ur' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><title>{{ $order->order_no }}</title>
    <style>
        body { color: #1f2937; font-family: 'DejaVu Sans', dejavusans, sans-serif; font-size: 10pt; }
        html[dir=rtl] body { font-family: jameelnoorinastaleeq, 'DejaVu Sans', dejavusans, sans-serif; }
        h1 { font-size: 18pt; }
        h2 { font-size: 12pt; margin: 0 0 2mm; }
        .test { border-top: 0.3mm solid #9ca3af; margin-top: 5mm; padding-top: 3mm; page-break-inside: avoid; }
        .meta { font-size: 8pt; }
        @media print { .print-action { display: none; } }
    </style>
</head>
<body>
    @if($browserPrint ?? false)
        <button class="print-action" type="button" onclick="window.print()">{{ __('health.print') }}</button>
    @endif
    <h1>{{ __('lab_workflow.report') }}</h1>
    <p>{{ $company?->name }}</p>
    <p>{{ $order->patient?->name }} · <bdi>{{ $order->patient?->mrn }}</bdi> · <bdi>{{ $order->order_no }}</bdi></p>
    @foreach($lines as $line)
        @php
            $result = $line['result'];
        @endphp
        <div class="test">
            <h2>{{ $line['item']->test_name }} <bdi>{{ $line['item']->test_code }}</bdi></h2>
            <p>{!! nl2br(e($result->value)) !!} {{ $result->unit }}</p>
            @if($result->reference)
                <p>{{ __('lab_workflow.reference') }} {{ $result->reference }}</p>
            @endif
            <p class="meta">{{ __('lab_workflow.verified_result', ['number' => $result->revision]) }} · {{ $result->verifier_name }} · <bdi>{{ $result->verified_at->format('Y-m-d H:i') }}</bdi></p>
            @if($result->amendment_reason)
                <p class="meta">{{ __('lab_workflow.amendment_reason') }} {{ $result->amendment_reason }}</p>
            @endif
        </div>
    @endforeach
</body>
</html>
