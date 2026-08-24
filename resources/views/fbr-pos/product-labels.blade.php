@php $urduScript = app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT; @endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('pos.product_labels') }} — {{ $company->name ?? 'POS' }}</title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; margin: 0; background: #f3f4f6; color: #111; }
        .toolbar { position: sticky; top: 0; z-index: 20; background: #fff; border-bottom: 1px solid #e5e7eb; padding: 10px 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
        .toolbar h1 { font-size: 15px; margin: 0; font-weight: 700; }
        .toolbar .spacer { flex: 1; }
        .btn { border: 0; border-radius: 8px; padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .btn-print { background: #2563eb; color: #fff; }
        .btn-back { background: #e5e7eb; color: #374151; text-decoration: none; display: inline-block; }
        .field { font-size: 12px; color: #6b7280; display: flex; align-items: center; gap: 4px; }
        .field select { padding: 5px 6px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px; background: #fff; }
        .wrap { display: flex; gap: 14px; align-items: flex-start; max-width: 1200px; margin: 14px auto; padding: 0 12px; }
        /* ── Picker panel ─────────────────────────────────────────── */
        .picker { width: 340px; flex-shrink: 0; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
        .picker input[type=search] { width: 100%; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; margin-bottom: 8px; }
        .chips { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
        .chip { border: 1px solid #d1d5db; background: #f9fafb; color: #374151; border-radius: 999px; padding: 4px 10px; font-size: 11px; font-weight: 600; cursor: pointer; }
        .chip.on { background: #2563eb; border-color: #2563eb; color: #fff; }
        .selrow { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #4b5563; margin-bottom: 6px; }
        .selrow button { border: 0; background: none; color: #2563eb; font-size: 12px; font-weight: 600; cursor: pointer; padding: 2px 4px; }
        .plist { max-height: 60vh; overflow-y: auto; border-top: 1px solid #f3f4f6; }
        .prow { display: flex; align-items: center; gap: 8px; padding: 7px 2px; border-bottom: 1px solid #f3f4f6; }
        .prow .nm { flex: 1; min-width: 0; }
        .prow .nm .n { font-size: 12.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .prow .nm .c { font-size: 10px; color: #9ca3af; }
        .prow .pr { font-size: 11px; color: #6b7280; white-space: nowrap; }
        .prow input[type=number] { width: 50px; padding: 3px 5px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px; }
        .hint { font-size: 12px; color: #6b7280; padding: 16px 6px; text-align: center; }
        /* ── Sheet / labels ───────────────────────────────────────── */
        .sheetwrap { flex: 1; min-width: 0; }
        .sheet { background: #fff; padding: 8mm; box-shadow: 0 2px 12px rgba(0,0,0,.08); border-radius: 8px; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4mm; }
        .label { border: 1px dashed #cbd5e1; border-radius: 6px; padding: 8px 10px; text-align: center; break-inside: avoid; overflow: hidden; }
        .label .name { font-size: 12px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .label .price { font-size: 15px; font-weight: 800; margin: 2px 0; }
        .label svg { max-width: 100%; height: 42px; }
        .label .sku { font-size: 9px; color: #6b7280; letter-spacing: .5px; }
        .empty { text-align: center; padding: 60px; color: #6b7280; }
        /* Thermal roll preview: fixed mm-size labels, one per page when printed */
        body.fmt-roll .grid { display: block; }
        body.fmt-roll .label { width: var(--lw); height: var(--lh); margin: 0 auto 4mm; padding: 1.5mm; border-radius: 0; display: flex; flex-direction: column; justify-content: center; }
        body.fmt-roll .label .name { font-size: 8.5pt; }
        body.fmt-roll .label .price { font-size: 10pt; margin: 0.5mm 0; }
        body.fmt-roll .label svg { height: 8mm; }
        body.fmt-roll .label .sku { font-size: 6pt; }
        @media (max-width: 760px) {
            .wrap { flex-direction: column; }
            .picker { width: 100%; }
            .plist { max-height: 40vh; }
        }
        @media print {
            .toolbar, .picker { display: none; }
            body { background: #fff; }
            .wrap { margin: 0; padding: 0; max-width: none; display: block; }
            .sheet { box-shadow: none; margin: 0; max-width: none; padding: 0; border-radius: 0; }
            .grid { gap: 3mm; }
            body.fmt-roll .label { margin: 0; page-break-after: always; border: 0; }
        }
@if($urduScript)
        /* Urdu-script labels (Task 1287): Jameel Noori Nastaleeq for the Arabic
           range only — Latin names, prices and SKUs keep the Segoe stack. The
           name row is nowrap+ellipsis, so give Nastaleeq the extra vertical room
           it needs or its descenders clip inside the box. */
        @include('partials.urdu-print-font')
        body { font-family: 'Jameel Noori Nastaleeq', 'Segoe UI', Tahoma, Arial, sans-serif; }
        .label .name { line-height: 1.9; }
@endif
    </style>
    <style id="pageStyle"></style>
</head>
<body class="fmt-a4">
    @php
        // UTF-8-safe encode (universal-screen rule): one broken product name must
        // not blank the whole page's JS.
        $jsEnc = function ($value, $fallback = '[]') {
            $json = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
            return $json === false ? $fallback : $json;
        };
        $newCutoff = now()->subDays(30);
        $productsJson = $products->map(fn ($p) => [
            'id' => (int) $p->id,
            'name' => $p->name,
            'price' => (float) ($p->default_price ?? 0),
            'code' => $p->barcode ?: ($p->sku ?: ('FBR-' . $p->id)),
            'active' => (bool) $p->is_active,
            'hidden' => !($p->show_on_sale ?? true),
            'isNew' => $p->created_at && $p->created_at->gte($newCutoff),
        ])->values();
    @endphp

    <div class="toolbar">
        <h1>🏷️ {{ __('pos.product_labels') }} — <span id="labelCount">0</span></h1>
        <div class="spacer"></div>
        <label class="field">{{ __('pos.label_format') }}
            <select id="fmt" onchange="setFormat(this.value)">
                <option value="a4-3">{{ __('pos.a4_sheet') }} ×3</option>
                <option value="a4-4">{{ __('pos.a4_sheet') }} ×4</option>
                <option value="a4-2">{{ __('pos.a4_sheet') }} ×2</option>
                <option value="roll-38x25">{{ __('pos.thermal_roll_size', ['size' => '38×25mm']) }}</option>
                <option value="roll-50x25">{{ __('pos.thermal_roll_size', ['size' => '50×25mm']) }}</option>
                <option value="roll-50x30">{{ __('pos.thermal_roll_size', ['size' => '50×30mm']) }}</option>
            </select>
        </label>
        <a href="{{ route('fbrpos.products') }}" class="btn btn-back">← {{ __('pos.back_word') }}</a>
        <button class="btn btn-print" onclick="window.print()">🖨 {{ __('pos.print') }}</button>
    </div>

    <div class="wrap">
        <div class="picker">
            <input type="search" id="q" placeholder="{{ __('pos.search_word') }}…" oninput="renderList()">
            <div class="chips" id="chips">
                <button class="chip on" data-f="all" onclick="setFilter('all')">{{ __('pos.all_word') }}</button>
                <button class="chip" data-f="new" onclick="setFilter('new')">{{ __('pos.new_filter_30d') }}</button>
                <button class="chip" data-f="active" onclick="setFilter('active')">{{ __('pos.active_word') }}</button>
                <button class="chip" data-f="hidden" onclick="setFilter('hidden')">{{ __('pos.hidden_word') }}</button>
            </div>
            <div class="selrow">
                <button onclick="selectVisible(true)">{{ __('pos.select_all') }}</button> ·
                <button onclick="selectVisible(false)">{{ __('pos.clear') }}</button>
                <span class="spacer" style="flex:1"></span>
                <span id="selCount"></span>
            </div>
            <div class="plist" id="plist"></div>
        </div>

        <div class="sheetwrap">
            <div class="sheet">
                <div class="grid" id="grid"></div>
                <div class="empty" id="emptyMsg">{{ __('pos.pick_products_hint') }}</div>
            </div>
        </div>
    </div>

    <script>
        var PRODUCTS = {!! $jsEnc($productsJson) !!};
        var PRESELECT = {!! $jsEnc(collect($preselectedIds)->map(fn ($i) => (int) $i)->values()) !!};
        var qty = {};   // id -> label count (selected products only)
        var filter = 'all';
        var fmt = 'a4-3';

        PRESELECT.forEach(function (id) {
            if (PRODUCTS.some(function (p) { return p.id === id; })) qty[id] = 1;
        });

        function visibleProducts() {
            var q = (document.getElementById('q').value || '').trim().toLowerCase();
            return PRODUCTS.filter(function (p) {
                if (q && (p.name || '').toLowerCase().indexOf(q) === -1 && (p.code || '').toLowerCase().indexOf(q) === -1) return false;
                if (filter === 'new' && !p.isNew) return false;
                if (filter === 'active' && !p.active) return false;
                if (filter === 'hidden' && !p.hidden) return false;
                return true;
            });
        }

        function setFilter(f) {
            filter = f;
            document.querySelectorAll('#chips .chip').forEach(function (c) {
                c.classList.toggle('on', c.getAttribute('data-f') === f);
            });
            renderList();
        }

        function selectVisible(on) {
            if (on) { visibleProducts().forEach(function (p) { if (!qty[p.id]) qty[p.id] = 1; }); }
            else { qty = {}; }
            renderList(); renderSheet();
        }

        function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

        function renderList() {
            var host = document.getElementById('plist');
            host.innerHTML = '';
            visibleProducts().forEach(function (p) {
                var row = document.createElement('div');
                row.className = 'prow';
                row.innerHTML =
                    '<input type="checkbox"' + (qty[p.id] ? ' checked' : '') + '>' +
                    '<div class="nm"><div class="n">' + esc(p.name) + '</div><div class="c">' + esc(p.code) + '</div></div>' +
                    '<div class="pr">Rs ' + Math.round(p.price).toLocaleString() + '</div>' +
                    '<input type="number" min="1" max="500" value="' + (qty[p.id] || 1) + '"' + (qty[p.id] ? '' : ' style="visibility:hidden"') + '>';
                var cb = row.querySelector('input[type=checkbox]');
                var qi = row.querySelector('input[type=number]');
                cb.addEventListener('change', function () {
                    if (cb.checked) { qty[p.id] = parseInt(qi.value, 10) || 1; qi.style.visibility = 'visible'; }
                    else { delete qty[p.id]; qi.style.visibility = 'hidden'; }
                    renderSheet();
                });
                qi.addEventListener('input', function () {
                    var n = Math.max(1, Math.min(500, parseInt(qi.value, 10) || 1));
                    if (qty[p.id]) { qty[p.id] = n; renderSheet(); }
                });
                host.appendChild(row);
            });
        }

        function setFormat(f) {
            fmt = f;
            var page = document.getElementById('pageStyle');
            var grid = document.getElementById('grid');
            var m = /^roll-(\d+)x(\d+)$/.exec(f);
            if (m) {
                var w = m[1], h = m[2];
                document.body.className = 'fmt-roll';
                document.body.style.setProperty('--lw', w + 'mm');
                document.body.style.setProperty('--lh', h + 'mm');
                page.textContent = '@page { size: ' + w + 'mm ' + h + 'mm; margin: 0; }';
                grid.style.gridTemplateColumns = '';
            } else {
                var cols = parseInt(f.split('-')[1], 10) || 3;
                document.body.className = 'fmt-a4';
                page.textContent = '@page { size: A4; margin: 8mm; }';
                grid.style.gridTemplateColumns = 'repeat(' + cols + ', 1fr)';
            }
            renderSheet();
        }

        function renderSheet() {
            var grid = document.getElementById('grid');
            var roll = document.body.className === 'fmt-roll';
            grid.innerHTML = '';
            var total = 0;
            PRODUCTS.forEach(function (p) {
                var n = qty[p.id] || 0;
                for (var i = 0; i < n; i++) {
                    total++;
                    var el = document.createElement('div');
                    el.className = 'label';
                    el.innerHTML =
                        '<div class="name">' + esc(p.name) + '</div>' +
                        '<div class="price">Rs ' + Math.round(p.price).toLocaleString() + '</div>' +
                        '<svg class="bc" data-code="' + esc(p.code) + '"></svg>' +
                        '<div class="sku">' + esc(p.code) + '</div>';
                    grid.appendChild(el);
                }
            });
            document.getElementById('labelCount').textContent = total;
            document.getElementById('selCount').textContent = Object.keys(qty).length + ' ✓';
            document.getElementById('emptyMsg').style.display = total === 0 ? '' : 'none';
            grid.querySelectorAll('svg.bc').forEach(function (el) {
                try {
                    JsBarcode(el, el.getAttribute('data-code') || '', {
                        format: 'CODE128',
                        width: roll ? 1.1 : 1.4,
                        height: roll ? 26 : 42,
                        displayValue: false,
                        margin: 0
                    });
                } catch (e) {
                    el.outerHTML = '<div style="font-size:10px;color:#ef4444;">✕</div>';
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            setFormat('a4-3');
            renderList();
            renderSheet();
        });
    </script>
</body>
</html>
