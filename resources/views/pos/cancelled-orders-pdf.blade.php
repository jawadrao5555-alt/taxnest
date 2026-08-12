<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        .sub { color: #666; margin-bottom: 10px; }
        .summary { margin: 8px 0 12px; }
        .summary span { display: inline-block; margin-right: 24px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background: #f3f4f6; font-size: 9px; text-transform: uppercase; }
        td.num { text-align: right; }
        .kot { color: #c2410c; font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $company->name }} — Cancelled Orders</h1>
    <div class="sub">{{ $from }} to {{ $to }} • Generated {{ now()->format('d M Y, h:i A') }}</div>
    <div class="summary">
        <span>Total cancelled: {{ number_format($summary['count']) }}</span>
        <span>Cancelled value: Rs {{ number_format($summary['value']) }}</span>
        @if (($summary['waste'] ?? 0) > 0)<span>Wasted (made) value: Rs {{ number_format($summary['waste']) }}</span>@endif
    </div>
    <table>
        <thead>
            <tr>
                <th>Order #</th><th>Cancelled At</th><th>Table</th><th>Type</th><th>Items</th><th>Rs</th><th>KOT</th><th>Punched By</th><th>Cancelled By</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders as $o)
            <tr>
                <td>{{ $o->order_number }}</td>
                <td>{{ optional($o->cancelled_at ?? $o->updated_at)->format('d M Y, h:i A') }}</td>
                <td>{{ $o->table?->table_number ? 'T-' . $o->table->table_number : '-' }}</td>
                <td>{{ $o->order_type }}</td>
                <td>{{ $o->items->map(fn ($i) => $i->quantity . 'x ' . $i->item_name . ($i->was_made ? ' [MADE]' : ''))->implode(', ') }}</td>
                <td class="num">{{ number_format($o->total_amount) }}</td>
                <td>@if ($o->kot_sent_at)<span class="kot">YES</span>@else - @endif</td>
                <td>{{ $o->creator?->name ?? '-' }}</td>
                <td>{{ $o->canceller?->name ?? 'System' }}</td>
            </tr>
            @empty
            <tr><td colspan="9">No cancelled orders in this period.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
