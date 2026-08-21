<!DOCTYPE html>
{{-- (Khata upgrade Aug 2026) Wasooli ki rasid — thermal payment slip for a
     recorded wasooli. Mirrors fbr-pos/receipt.blade.php thermal conventions:
     NEVER forces body width to the physical paper width in @media print (see
     .agents/memory/thermal-print-width.md) — the PRINTABLE-WIDTH FIX block is
     the LAST rule set so a later base rule can't silently override it. --}}
@php $urduScript = app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT; @endphp
<html lang="{{ $urduScript ? 'ur' : 'en' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('pos.wasooli_receipt_title') }} - {{ $entry->id }}</title>
    @php
        $paperSize = $company->print_paper_size ?? 'thermal';
        $is58 = $paperSize === 'thermal58';
        $amount = abs((float) $entry->amount);
    @endphp
    <style>
        @if($is58)
            @page { size: 58mm auto; margin: 0; }
        @else
            @page { size: 80mm auto; margin: 0; }
        @endif
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: {{ $is58 ? '11px' : '12px' }};
            width: {{ $is58 ? '58mm' : '80mm' }};
            max-width: {{ $is58 ? '58mm' : '80mm' }};
            margin: 0 auto;
            padding: {{ $is58 ? '2mm' : '3mm' }};
            background: #fff;
            color: #000;
            line-height: 1.35;
            word-wrap: break-word;
            overflow-wrap: break-word;
            font-weight: 500;
        }
        .text-center { text-align: center; }
        .bold { font-weight: bold; }
        .separator { border-top: 1px dashed #000; margin: 3px 0; }
        .double-separator { border-top: 2px solid #000; margin: 3px 0; }
        .header h1 { font-size: 15px; font-weight: bold; margin-bottom: 3px; word-wrap: break-word; color: #000; }
        .slip-title { border: 2px solid #000; padding: 5px 4px; margin: 5px 0; text-align: center; font-size: 13px; font-weight: bold; letter-spacing: 1px; }
        .info-table { width: 100%; border-collapse: collapse; margin: 2px 0; }
        .info-table td { font-size: 11px; padding: 2px 0; vertical-align: top; color: #000; font-weight: 600; }
        .info-table .info-label { width: 40%; font-weight: bold; white-space: nowrap; }
        .info-table .info-value { width: 60%; text-align: right; word-wrap: break-word; }
        .amount-row td { font-size: {{ $is58 ? '15px' : '17px' }}; font-weight: 900; padding: 6px 4px; border-top: 2.5px solid #000; border-bottom: 2.5px solid #000; }
        .footer { margin-top: 4px; font-size: 10px; line-height: 1.5; color: #000; font-weight: 600; }
        @media screen {
            body { padding: 10px; }
            .no-print { margin-bottom: 15px; text-align: center; }
        }
        @if($urduScript)
        @include('partials.urdu-print-font')
        body { font-family: 'Jameel Noori Nastaleeq', 'Noto Naskh Arabic', 'Urdu Typesetting', Tahoma, Arial, 'Segoe UI', sans-serif; line-height: 1.9; }
        @endif
        {{-- PRINTABLE-WIDTH FIX (Khata upgrade Aug 2026): 80mm paper prints only
             ~72mm (58mm → ~48mm). Cap at the SAFE printable width, never force the
             physical width. This block is LAST so no later base rule overrides it
             (thermal-print-width.md v6 ordering rule). --}}
        @media print {
            body { width: auto; max-width: {{ $is58 ? '48mm' : '72mm' }}; padding: 1mm; margin: 0; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()" style="padding: 10px 30px; background: #16a34a; color: white; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; margin-right: 10px;">{{ __('pos.receipt_print') }}</button>
        <a href="{{ route('fbrpos.khata') }}" target="_top" style="padding: 10px 30px; background: #6b7280; color: white; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-block;">{{ __('pos.receipt_back') }}</a>
    </div>

    <div class="header text-center">
        <h1>{{ $company->name }}</h1>
        @if($company->address)<p style="font-size:10px; font-weight:600;">{{ $company->address }}</p>@endif
        @if($company->phone)<p style="font-size:10px; font-weight:600;">{{ __('pos.rcpt_tel') }} {{ $company->phone }}</p>@endif
    </div>

    <div class="slip-title">{{ __('pos.wasooli_receipt_title') }}</div>

    <table class="info-table">
        <tr>
            <td class="info-label">{{ __('pos.receipt_date') }}:</td>
            <td class="info-value">{{ $entry->created_at->format('d/m/Y h:i A') }}</td>
        </tr>
        <tr>
            <td class="info-label">{{ __('pos.receipt_customer') }}:</td>
            <td class="info-value">{{ $entry->customer?->name ?? '—' }}</td>
        </tr>
        @if($entry->customer?->phone)
        <tr>
            <td class="info-label">{{ __('pos.receipt_phone') }}:</td>
            <td class="info-value">{{ $entry->customer->phone }}</td>
        </tr>
        @endif
    </table>

    <div class="separator"></div>

    <table class="info-table">
        <tr class="amount-row">
            <td class="info-label">{{ __('pos.wasooli_receipt_received') }}:</td>
            <td class="info-value">Rs {{ number_format($amount, 2) }}</td>
        </tr>
        <tr>
            <td class="info-label" style="padding-top:5px;">{{ __('pos.wasooli_receipt_balance_now') }}:</td>
            <td class="info-value" style="padding-top:5px;">Rs {{ number_format((float) $entry->balance_after, 2) }}</td>
        </tr>
    </table>

    <div class="separator"></div>

    <table class="info-table">
        <tr>
            <td class="info-label">{{ __('pos.wasooli_receipt_received_by') }}:</td>
            <td class="info-value">{{ $entry->creator?->name ?? '—' }}</td>
        </tr>
        @if($entry->note)
        <tr>
            <td class="info-label">{{ __('pos.wasooli_receipt_note') }}:</td>
            <td class="info-value">{{ $entry->note }}</td>
        </tr>
        @endif
    </table>

    <div class="double-separator"></div>

    <div class="footer text-center">
        <p>{{ __('pos.wasooli_receipt_thank') }}</p>
    </div>
</body>
</html>
