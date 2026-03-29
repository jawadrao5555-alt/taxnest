<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitchen Ticket - {{ $order->order_number }}</title>
    <style>
        @page { size: 80mm auto; margin: 0; }
        @media print {
            body { width: 80mm; }
            .no-print { display: none !important; }
            .station-section { page-break-after: auto; }
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', 'Lucida Console', monospace;
            font-size: 13px;
            width: 80mm;
            max-width: 80mm;
            margin: 0 auto;
            padding: 3mm;
            background: #fff;
            color: #000;
            line-height: 1.5;
        }
        .separator { border-top: 2px dashed #000; margin: 6px 0; }
        .separator-light { border-top: 1px dotted #999; margin: 4px 0; }
        .separator-station { border-top: 3px solid #000; margin: 8px 0 4px; }
        .bold { font-weight: bold; }
        .text-center { text-align: center; }
        .text-lg { font-size: 16px; }
        .text-xl { font-size: 20px; }
        .text-sm { font-size: 11px; }
        .text-xs { font-size: 9px; }
        .mt-1 { margin-top: 4px; }
        .mt-2 { margin-top: 8px; }
        .mb-1 { margin-bottom: 4px; }
        .flex { display: flex; justify-content: space-between; align-items: center; }
        .items-table { width: 100%; border-collapse: collapse; margin: 4px 0; }
        .items-table td { padding: 4px 2px; vertical-align: top; font-size: 14px; }
        .items-table .qty { width: 15%; font-weight: bold; font-size: 16px; text-align: center; }
        .items-table .name { width: 85%; }
        .items-table .note { font-size: 10px; font-style: italic; color: #555; padding-left: 10px; }
        .items-table tr { border-bottom: 1px dotted #999; }
        .items-table tr:last-child { border-bottom: none; }
        .order-type-badge {
            display: inline-block; padding: 2px 10px; border: 2px solid #000;
            font-weight: bold; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;
        }
        .priority-badge {
            display: inline-block; padding: 3px 12px; border: 3px solid #000;
            font-weight: bold; font-size: 16px; text-transform: uppercase; letter-spacing: 2px;
            background: #000; color: #fff;
        }
        .station-header {
            font-size: 15px; font-weight: bold; text-transform: uppercase;
            padding: 4px 8px; margin: 0 0 4px 0; border: 2px solid #000;
            text-align: center; letter-spacing: 2px; background: #eee;
        }
        .station-section { margin-bottom: 8px; }
        .station-item-count { font-size: 10px; text-align: center; color: #555; margin-bottom: 4px; }
        .kitchen-notes {
            border: 2px solid #000; padding: 6px 8px; margin-top: 6px;
            font-size: 12px; font-weight: bold; background: #f5f5f5;
        }
        .print-btn {
            display: block; width: 100%; padding: 12px; margin-top: 10px;
            background: #7c3aed; color: #fff; border: none; border-radius: 8px;
            font-size: 14px; font-weight: bold; cursor: pointer;
        }
        .print-btn:hover { background: #6d28d9; }
        .print-btn-row { display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap; }
        .print-btn-row button { flex: 1; min-width: 80px; padding: 10px; border: none; border-radius: 8px; font-size: 12px; font-weight: bold; cursor: pointer; }
        .btn-reprint { background: #f59e0b; color: #fff; }
        .btn-station { background: #3b82f6; color: #fff; font-size: 11px; }
        .btn-station:hover { background: #2563eb; }
    </style>
</head>
<body>
    <div class="text-center">
        @if($order->priority ?? false)
            <p class="priority-badge">!!! RUSH !!!</p>
        @endif
        <p class="text-xl bold mt-1">*** KITCHEN ***</p>
        <p class="text-lg bold mt-1">{{ $order->order_number }}</p>
    </div>

    <div class="separator"></div>

    <div class="flex">
        <span class="bold">{{ $order->created_at->format('M d, Y') }}</span>
        <span class="bold">{{ $order->created_at->format('h:i A') }}</span>
    </div>

    <div class="flex mt-1">
        <span class="order-type-badge">{{ str_replace('_', ' ', $order->order_type) }}</span>
        @if($order->table)
            <span class="bold text-lg">T-{{ $order->table->table_number }}</span>
        @endif
    </div>

    @if($order->customer_name)
    <div class="mt-1">
        <span class="bold text-sm">Customer: {{ $order->customer_name }}</span>
    </div>
    @endif

    <div class="separator"></div>

    @php
        $grouped = $order->items->groupBy(function($item) {
            if ($item->item_type === 'service') return 'Services';
            $product = \App\Models\PosProduct::find($item->item_id);
            return $product && $product->category ? $product->category : 'General';
        });
        $stationNames = $grouped->keys()->toArray();
    @endphp

    @foreach($grouped as $station => $items)
        <div class="station-section" data-station="{{ $station }}">
            @if($grouped->count() > 1)
                <div class="station-header">{{ $station }}</div>
                <div class="station-item-count">{{ $items->count() }} item(s)</div>
            @endif
            <table class="items-table">
                @foreach($items as $item)
                <tr>
                    <td class="qty">x{{ number_format($item->quantity, $item->quantity == intval($item->quantity) ? 0 : 2) }}</td>
                    <td class="name">
                        <span class="bold">{{ $item->item_name }}</span>
                        @if($item->special_notes)
                            <br><span class="note">!! {{ $item->special_notes }}</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </table>
            @if(!$loop->last && $grouped->count() > 1)
                <div class="separator-light"></div>
            @endif
        </div>
    @endforeach

    @if($order->kitchen_notes)
    <div class="separator"></div>
    <div class="kitchen-notes">
        NOTES: {{ $order->kitchen_notes }}
    </div>
    @endif

    <div class="separator"></div>

    <div class="text-center text-sm">
        <p>Prepared by: {{ $order->creator->name ?? 'Staff' }}</p>
        <p class="mt-1">{{ $order->items->count() }} item(s) total</p>
    </div>

    <div class="separator"></div>
    <p class="text-center bold text-sm">{{ $company->name ?? 'Restaurant' }}</p>

    <div class="no-print print-btn-row">
        <button class="print-btn" onclick="printAll()">Print Full KOT</button>
        <button class="btn-reprint" onclick="printAll()">Reprint</button>
    </div>

    @if($grouped->count() > 1)
    <div class="no-print" style="margin-top: 8px;">
        <p style="font-size: 11px; font-weight: bold; color: #666; text-align: center; margin-bottom: 6px;">Print by Station:</p>
        <div class="print-btn-row">
            @foreach($stationNames as $sName)
            <button class="btn-station" onclick="printStation('{{ $sName }}')">{{ $sName }}</button>
            @endforeach
        </div>
    </div>
    @endif

    <script>
        let hasPrinted = false;

        function printAll() {
            document.querySelectorAll('.station-section').forEach(s => s.style.display = '');
            window.print();
        }

        function printStation(station) {
            document.querySelectorAll('.station-section').forEach(s => {
                s.style.display = s.dataset.station === station ? '' : 'none';
            });
            window.print();
            setTimeout(() => {
                document.querySelectorAll('.station-section').forEach(s => s.style.display = '');
            }, 500);
        }

        window.onload = function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('auto_print') === '1' && !hasPrinted) {
                hasPrinted = true;
                setTimeout(function() { window.print(); }, 500);
            }
            const station = urlParams.get('station');
            if (station) {
                setTimeout(() => printStation(station), 600);
            }
        };
    </script>
</body>
</html>
