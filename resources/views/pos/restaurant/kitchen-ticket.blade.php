<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitchen Ticket - {{ $order->order_number }}</title>
    <style>
        @page { size: 80mm auto; margin: 0; }
        @media print { body { width: 80mm; } .no-print { display: none !important; } }
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
            font-size: 14px; font-weight: bold; text-transform: uppercase;
            padding: 3px 6px; margin: 6px 0 2px 0; border: 1px solid #000;
            text-align: center; letter-spacing: 1px;
        }
        .kitchen-notes {
            border: 2px solid #000; padding: 4px 6px; margin-top: 6px;
            font-size: 12px; font-weight: bold; background: #f5f5f5;
        }
        .print-btn {
            display: block; width: 100%; padding: 12px; margin-top: 10px;
            background: #7c3aed; color: #fff; border: none; border-radius: 8px;
            font-size: 14px; font-weight: bold; cursor: pointer;
        }
        .print-btn:hover { background: #6d28d9; }
        .print-btn-row { display: flex; gap: 6px; margin-top: 8px; }
        .print-btn-row button { flex: 1; padding: 10px; border: none; border-radius: 8px; font-size: 13px; font-weight: bold; cursor: pointer; }
        .btn-reprint { background: #f59e0b; color: #fff; }
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
    @endphp

    @if($grouped->count() > 1)
        @foreach($grouped as $station => $items)
            <div class="station-header">{{ $station }}</div>
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
        @endforeach
    @else
        <table class="items-table">
            @foreach($order->items as $item)
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
    @endif

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
        <button class="print-btn" onclick="window.print()">Print KOT</button>
        <button class="btn-reprint" onclick="window.print()">Reprint</button>
    </div>

    <script>
        window.onload = function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('auto_print') === '1') {
                setTimeout(function() { window.print(); }, 500);
            }
        };
    </script>
</body>
</html>
