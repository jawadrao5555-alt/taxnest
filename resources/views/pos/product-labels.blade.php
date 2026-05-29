<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Product Labels — {{ $company->name ?? 'POS' }}</title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; margin: 0; background: #f3f4f6; color: #111; }
        .toolbar { position: sticky; top: 0; background: #fff; border-bottom: 1px solid #e5e7eb; padding: 12px 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        .toolbar h1 { font-size: 15px; margin: 0; font-weight: 700; }
        .toolbar .spacer { flex: 1; }
        .btn { border: 0; border-radius: 8px; padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .btn-print { background: #7c3aed; color: #fff; }
        .btn-back { background: #e5e7eb; color: #374151; text-decoration: none; display: inline-block; }
        .field { font-size: 12px; color: #6b7280; display: flex; align-items: center; gap: 4px; }
        .field input { width: 56px; padding: 4px 6px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px; }
        .sheet { max-width: 210mm; margin: 16px auto; background: #fff; padding: 10mm; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4mm; }
        .label { border: 1px dashed #cbd5e1; border-radius: 6px; padding: 8px 10px; text-align: center; break-inside: avoid; }
        .label .name { font-size: 12px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .label .price { font-size: 15px; font-weight: 800; margin: 2px 0; }
        .label svg { max-width: 100%; height: 42px; }
        .label .sku { font-size: 9px; color: #6b7280; letter-spacing: .5px; }
        .empty { text-align: center; padding: 60px; color: #6b7280; }
        @media print {
            .toolbar { display: none; }
            body { background: #fff; }
            .sheet { box-shadow: none; margin: 0; max-width: none; padding: 6mm; }
            .grid { gap: 3mm; }
            .label { border: 1px dashed #cbd5e1; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <h1>🏷️ Product Labels &mdash; {{ $products->count() }} item(s)</h1>
        <div class="spacer"></div>
        <label class="field">Columns
            <input type="number" id="cols" value="3" min="1" max="5" onchange="setCols(this.value)">
        </label>
        <a href="{{ route('pos.products') }}" class="btn btn-back">← Back</a>
        <button class="btn btn-print" onclick="window.print()">🖨 Print</button>
    </div>

    <div class="sheet">
        @if($products->count() === 0)
            <div class="empty">No products to print.</div>
        @else
        <div class="grid" id="grid">
            @foreach($products as $p)
            @php
                $code = $p->barcode ?: ($p->sku ?: ('PRA-' . $p->id));
            @endphp
            <div class="label">
                <div class="name">{{ $p->name }}</div>
                <div class="price">Rs {{ number_format($p->price, 0) }}</div>
                <svg class="bc" data-code="{{ $code }}"></svg>
                <div class="sku">{{ $code }}</div>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    <script>
        function renderBarcodes() {
            document.querySelectorAll('svg.bc').forEach(function (el) {
                var code = el.getAttribute('data-code') || '';
                try {
                    JsBarcode(el, code, { format: 'CODE128', width: 1.4, height: 42, displayValue: false, margin: 0 });
                } catch (e) {
                    el.outerHTML = '<div style="font-size:10px;color:#ef4444;">bad code</div>';
                }
            });
        }
        function setCols(n) {
            var g = document.getElementById('grid');
            if (!g) return;
            n = Math.max(1, Math.min(5, parseInt(n, 10) || 3));
            g.style.gridTemplateColumns = 'repeat(' + n + ', 1fr)';
        }
        document.addEventListener('DOMContentLoaded', renderBarcodes);
    </script>
</body>
</html>
