{{--
    Test print slip (agent path only).

    Purpose: a shop whose Windows carries several queues for the SAME physical
    printer ("XP-80C", "XP-80C (copy 2)", "POS-80") cannot tell which one is
    still wired to the device — Windows accepts a job for a dead queue and the
    agent reports success, so nothing on the server ever looks wrong. This slip
    prints the queue's OWN name, so whichever paper comes out names the printer
    that must be selected in Printer Settings.

    Deliberately tiny: one job = a few lines of paper, no logo, no fonts to
    wait for. No auto-print script — the agent prints the loaded document
    itself (browser paths never render this view).
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('pos.test_slip_title') }}</title>
    <style>
        /* Thermal rule: never force body width to the physical paper width —
           the driver already knows its roll; width:auto + a max-width cap. */
        @page { margin: 0; }
        html, body { margin: 0; padding: 0; }
        body {
            width: auto;
            max-width: 72mm;
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            font-size: 12px;
            line-height: 1.45;
            color: #000;
            padding: 4px 2px 10px;
        }
        .c { text-align: center; }
        .hd { font-size: 15px; font-weight: 700; letter-spacing: .5px; }
        .queue {
            font-size: 17px;
            font-weight: 700;
            word-break: break-word;
            border: 2px solid #000;
            padding: 6px 4px;
            margin: 7px 0;
        }
        .sep { border-top: 1px dashed #000; margin: 7px 0; }
        .sm { font-size: 11px; }
        .tiny { font-size: 10px; }
    </style>
</head>
<body>
    <div class="c hd">{{ __('pos.test_slip_title') }}</div>
    <div class="c sm">{{ $company->name }}</div>
    <div class="sep"></div>

    <div class="c tiny">{{ __('pos.test_slip_printer_label') }}</div>
    <div class="c queue">{{ $printerName }}</div>

    <div class="c sm">{{ __('pos.test_slip_hint') }}</div>

    <div class="sep"></div>
    <div class="c tiny">
        {{ $printedAt }}
        @if(!empty($requestedBy))
            <br>{{ $requestedBy }}
        @endif
    </div>
</body>
</html>
