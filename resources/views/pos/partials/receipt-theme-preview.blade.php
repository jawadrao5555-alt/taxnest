{{-- Live sample-receipt preview (Task 712) — shared by PRA + FBR receipt-settings.
     Expects the enclosing Alpine scope from rcptThemePicker() (script below).
     Receipt text is ENGLISH-ONLY by design (matches printed receipts' look);
     dummy shop/items data fills in whatever the company hasn't set.
     Markup mirrors receipt_80mm/58mm structure (Arial stack, dashed separators,
     bold-all vs plain, center vs side logo) WITHOUT touching the real templates.
     Vars: $company, $mode = 'pra' | 'fbr'. --}}
@php
    $tnMode = ($mode ?? 'pra') === 'fbr' ? 'fbr' : 'pra';
    $tnLogoUri = $company->receiptLogoDataUri();
    $tnName = $company->name ?: 'Al-Madina Foods';
    $tnAddress = trim(($company->address ?? '') . ($company->city ? ', ' . $company->city : '')) ?: '12-B Main Bazaar, Lahore';
    $tnPhone = trim(implode(' / ', array_filter([$company->phone ?? null, $company->mobile ?? null]))) ?: '0300-1234567';
    $tnEmail = $company->email ?: 'shop@example.com';
    $tnNtn = $company->ntn ?: '1234567-8';
@endphp
<div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden">
    <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-start justify-between gap-2">
        <div>
            <h3 class="text-sm font-bold text-gray-900 dark:text-white">👁️ {{ __('pos.rcpt_preview_title') }}</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.rcpt_preview_sub') }}</p>
        </div>
        @if($tnMode === 'pra')
        <span class="shrink-0 text-[11px] font-semibold px-2 py-1 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300"
              x-text="tab === 'local' ? '{{ __('pos.local_receipt') }}' : '{{ __('pos.pra_receipt') }}'"></span>
        @endif
    </div>

    <div class="p-4 sm:p-5 bg-gray-100 dark:bg-gray-800 flex justify-center">
        <div class="shadow-lg tn-rcpt-prev" :class="thBold() ? 'tn-prev-bold' : ''"
             data-tn-typography-preview :style="'width:' + (paper === '58mm' ? '219px' : '302px') + ';font-size:' + (paper === '58mm' ? '11px' : '12px')"
             style="background:#fff;color:#000;font-family:Arial,Helvetica,sans-serif;padding:12px 10px;line-height:1.35;word-wrap:break-word;font-weight:500;">

            <style x-text="typographyPreviewCss()"></style>
            {{-- ── Header: logo + business name (theme-driven layout) ── --}}
            <div x-show="showLogoNow() && thLogo() === 'center'" style="text-align:center;padding-top:2px;">
                @if($tnLogoUri)
                <img data-optional-logo src="{{ $tnLogoUri }}" alt="" style="width:96px;max-height:80px;object-fit:contain;display:block;margin:0 auto;">
                @else
                <span data-optional-logo style="display:inline-block;width:64px;height:46px;border:2px solid #111;border-radius:8px;line-height:42px;font-weight:bold;font-size:11px;text-align:center;">LOGO</span>
                @endif
            </div>
            <div x-show="showLogoNow() && thLogo() === 'side'">
                <table style="width:100%;border-collapse:collapse;margin-bottom:2px;">
                    <tr>
                        <td style="text-align:left;vertical-align:middle;width:64%;padding:0;">
                            <span x-show="optionShow('show_business_name', p.bizname)" style="display:block;font-size:15px;font-weight:bold;text-align:left;" data-optional-heading data-tn-prev="name">{{ $tnName }}</span>
                        </td>
                        <td style="text-align:right;vertical-align:middle;width:36%;padding:0;">
                            @if($tnLogoUri)
                            <img data-optional-logo src="{{ $tnLogoUri }}" alt="" style="max-width:60px;max-height:34px;object-fit:contain;">
                            @else
                            <span data-optional-logo style="display:inline-block;width:34px;height:26px;border:2px solid #111;border-radius:5px;line-height:22px;font-weight:bold;font-size:8px;text-align:center;">LOGO</span>
                            @endif
                        </td>
                    </tr>
                </table>
            </div>
            <div x-show="optionShow('show_business_name',p.bizname) && !(showLogoNow() && thLogo() === 'side')" style="text-align:center;">
                <span style="font-size:15px;font-weight:bold;" data-optional-heading data-tn-prev="name">{{ $tnName }}</span>
            </div>

            <div style="text-align:center;font-size:10px;font-weight:600;line-height:1.4;">
                <div x-show="optionShow('show_address', p.address)"><span data-tn-prev="address">{{ $tnAddress }}</span></div>
                <div x-show="optionShow('show_mobile', p.phone)">Tel: <span data-tn-prev="phone">{{ $tnPhone }}</span></div>
                @if($tnMode === 'pra')
                <div x-show="optionShow('show_email', p.email)"><span data-tn-prev="email">{{ $tnEmail }}</span></div>
                @endif
                <div x-show="optionShow('show_ntn', p.ntn)"><strong>NTN:</strong> <span data-tn-prev="ntn">{{ $tnNtn }}</span></div>
            </div>

            <div style="border-top:1px dashed #000;margin:4px 0;"></div>

            {{-- ── Serial box (top badge) ── --}}
            <div style="border:1.5px solid #000;padding:5px 4px;margin:5px 0;text-align:center;">
                <div data-optional-heading style="font-size:12px;font-weight:bold;letter-spacing:1px;">SALE RECEIPT</div>
                <div style="font-size:11px;font-weight:bold;margin-top:2px;" x-text="serialNow()"></div>
            </div>

            {{-- ── Order Matching token / code (live radios on both panels) ── --}}
            <div x-show="p.orderMatch === 'token' && optionShow('show_order_match')" style="text-align:center;padding:2px 0 3px;">
                <span style="display:inline-block;border:2px solid #000;padding:2px 14px;font-size:15px;font-weight:900;">Token 42</span>
            </div>
            <div x-show="p.orderMatch === 'code' && optionShow('show_order_match')" style="text-align:center;padding:2px 0 3px;">
                <span style="display:inline-block;border:2px solid #000;padding:2px 14px;font-size:13px;font-weight:900;letter-spacing:2px;">ORD-7K3F</span>
            </div>

            {{-- ── Info lines ── --}}
            <table data-optional-info style="width:100%;border-collapse:collapse;margin:2px 0;">
                <tr x-show="optionShow('show_date')"><td style="font-size:11px;font-weight:bold;padding:1px 0;white-space:nowrap;">Date:</td><td style="font-size:11px;text-align:right;padding:1px 0;font-weight:600;" x-text="optional.date_format === 'iso' ? '2026-08-14 19:45' : (optional.date_format === '24h' ? '14/08/2026 19:45' : '14/08/2026 07:45 PM')">14/08/2026 07:45 PM</td></tr>
                <tr x-show="optionShow('show_payment')"><td style="font-size:11px;font-weight:bold;padding:1px 0;white-space:nowrap;">Payment:</td><td style="font-size:11px;text-align:right;padding:1px 0;font-weight:600;">Cash</td></tr>
                <tr x-show="optionShow('show_cashier', p.cashier)"><td style="font-size:11px;font-weight:bold;padding:1px 0;white-space:nowrap;">Cashier:</td><td style="font-size:11px;text-align:right;padding:1px 0;font-weight:600;">Ali Raza</td></tr>
            </table>

            @if($tnMode === 'pra')
            <div x-show="optionShow('show_order_type')" style="text-align:center;font-weight:bold;">DINE-IN</div>
            <div data-optional-preview="terminal" x-show="optionShow('show_terminal')" style="font-size:11px;">Terminal: Sample Counter</div>
            <div x-show="optionShow('show_closed_by')" style="font-size:11px;">Closed by: Sample Staff</div>
            <div data-optional-preview="waiter" x-show="optionShow('show_waiter')" style="font-size:11px;">Waiter: Sample Waiter</div>
            <div x-show="optionShow('show_table')" style="font-size:11px;">Table: T-1 <span x-show="optionShow('show_floor')">— Ground Floor</span></div>
            <div x-show="optionShow('show_business_activity')" style="font-size:10px;text-align:center;">Sample business activity</div>
            <div x-show="optionShow('show_website')" style="font-size:10px;text-align:center;">shop.example</div>
            @endif
            {{-- ── Items ── --}}
            <table class="tn-preview-items" style="width:100%;border-collapse:collapse;margin:4px 0;table-layout:fixed;">
                <tr>
                    <td style="width:44%;font-size:10px;font-weight:bold;text-transform:uppercase;border-top:1.5px solid #000;border-bottom:1.5px solid #000;padding:3px 1px;">Item</td>
                    <td x-show="optionShow('show_quantity')" style="width:12%;font-size:10px;font-weight:bold;text-transform:uppercase;border-top:1.5px solid #000;border-bottom:1.5px solid #000;padding:3px 1px;text-align:center;">Qty</td>
                    <td x-show="optionShow('show_rate')" style="width:20%;font-size:10px;font-weight:bold;text-transform:uppercase;border-top:1.5px solid #000;border-bottom:1.5px solid #000;padding:3px 1px;text-align:right;">Price</td>
                    <td style="width:24%;font-size:10px;font-weight:bold;text-transform:uppercase;border-top:1.5px solid #000;border-bottom:1.5px solid #000;padding:3px 1px;text-align:right;">Total</td>
                </tr>
                @foreach(\App\Support\PosVocabulary::for()['receipt_lines'] as $tnPreviewLine)
                <tr>
                    <td style="font-size:11px;padding:3px 1px;border-bottom:1px dashed #000;font-weight:600;"><span x-show="optionShow('show_item_serial',false)">{{ $loop->iteration }}. </span>{{ $tnPreviewLine['name'] }}</td>
                    <td x-show="optionShow('show_quantity')" style="font-size:11px;padding:3px 1px;border-bottom:1px dashed #000;text-align:center;font-weight:600;">{{ $tnPreviewLine['qty'] }}</td>
                    <td x-show="optionShow('show_rate')" style="font-size:11px;padding:3px 1px;border-bottom:1px dashed #000;text-align:right;font-weight:600;">{{ number_format($tnPreviewLine['price'], 2) }}</td>
                    <td style="font-size:11px;padding:3px 1px;border-bottom:1px dashed #000;text-align:right;font-weight:bold;">{{ number_format($tnPreviewLine['qty'] * $tnPreviewLine['price'], 2) }}</td>
                </tr>
                @endforeach
            </table>

            @if($tnMode === 'pra')
            <div x-show="optionShow('show_item_notes')" style="font-size:9px;">* Sample item comment</div>
            <div x-show="optionShow('show_deal_components')" style="font-size:9px;">• Sample deal contents (when applicable)</div>
            <div x-show="optionShow('show_bill_notes')" style="font-size:9px;">Note: Sample bill note</div>
            @endif
            {{-- ── Totals (tax rows follow the show-tax toggle) ── --}}
            <table style="width:100%;border-collapse:collapse;margin:3px 0 0;">
                <tr x-show="p.tax && optionShow('show_subtotal')"><td style="font-size:11px;padding:2px 0;font-weight:600;">Subtotal:</td><td style="font-size:11px;text-align:right;padding:2px 0;font-weight:bold;white-space:nowrap;">PKR 1,330.00</td></tr>
                <tr x-show="p.tax"><td style="font-size:11px;padding:2px 0;font-weight:600;">Sales Tax:</td><td style="font-size:11px;text-align:right;padding:2px 0;font-weight:bold;white-space:nowrap;">PKR 213.00</td></tr>
                <tr data-optional-total>
                    <td style="font-size:16px;font-weight:900;padding:5px 2px;border-top:2.5px solid #000;border-bottom:2.5px solid #000;letter-spacing:0.3px;">TOTAL:</td>
                    <td style="font-size:16px;font-weight:900;padding:5px 2px;border-top:2.5px solid #000;border-bottom:2.5px solid #000;text-align:right;white-space:nowrap;">PKR 1,543.00</td>
                </tr>
            </table>

            {{-- ── QR / fiscal block ── --}}
            @if($tnMode === 'fbr')
            <div style="border:1.5px solid #000;padding:4px;margin:6px 0 4px;text-align:center;font-weight:600;">
                <div style="font-size:12px;font-weight:bold;margin-bottom:3px;">FBR Invoice #</div>
                <div style="font-size:9px;font-weight:bold;word-break:break-all;">1000000123456789</div>
                <span style="display:inline-block;width:56px;height:56px;border:2px solid #000;margin-top:4px;padding:5px;">
                    <span style="display:block;width:100%;height:100%;background:
                        linear-gradient(90deg,#000 25%,transparent 25%,transparent 50%,#000 50%,#000 75%,transparent 75%),
                        linear-gradient(#000 25%,transparent 25%,transparent 50%,#000 50%,#000 75%,transparent 75%);
                        background-size:12px 12px;"></span>
                </span>
                {{-- Task 769: gated by the fbrpos verify-line pref; undefined (pages
                     that don't pass it, e.g. business-profile) keeps it visible. --}}
                <div style="font-size:9px;margin-top:2px;" x-show="p.verifyLine !== false">Verify at FBR Tax Asaan</div>
            </div>
            @else
            <div x-show="qrNow()" style="text-align:center;margin:6px 0 2px;">
                <span data-optional-qr style="display:inline-block;width:64px;height:64px;border:2px solid #000;padding:6px;">
                    <span style="display:block;width:100%;height:100%;background:
                        linear-gradient(90deg,#000 25%,transparent 25%,transparent 50%,#000 50%,#000 75%,transparent 75%),
                        linear-gradient(#000 25%,transparent 25%,transparent 50%,#000 50%,#000 75%,transparent 75%);
                        background-size:14px 14px;"></span>
                </span>
                <div style="font-size:10px;margin-top:2px;font-weight:600;" x-show="tab === 'local' ? optionShow('show_qr_caption') : optionShow('show_verify_line',p.verifyLine)" x-text="tab === 'local' ? 'Scan to view invoice' : 'Verify via PRA Sahulat app'"></div>
            </div>
            @endif

            {{-- ── Footer ── --}}
            <div style="text-align:center;font-size:10px;margin-top:5px;line-height:1.5;font-weight:600;">
                <div x-show="optionShow('show_footer', p.footer)" x-text="(p.footerText && p.footerText.length) ? p.footerText : 'Thank you for your purchase!'"></div>
                @if($tnMode === 'fbr')
                <div style="font-weight:bold;">FBR POS INTEGRATED</div>
                <div>Powered by TaxNest FBR POS</div>
                @else
                <div x-show="optionShow('show_developed_by', p.devby)">Developed by: taxnest.pk</div>
                <div x-show="optionShow('show_print_time')">14/08/2026 07:45:12 PM</div>
                <div x-text="optional.extra_note || ''"></div>
                @endif
            </div>
        </div>
    </div>

    <p class="px-4 py-3 text-[11px] text-gray-400 dark:text-gray-500 text-center border-t border-gray-200 dark:border-gray-700">{{ __('pos.rcpt_preview_sample_note') }}</p>
</div>

<style>
    /* Mirrors the real receipts' opt-in BOLD PRINT STYLE block (weight 700, no stroke). */
    .tn-prev-bold, .tn-prev-bold * { font-weight: bold !important; }
    [x-cloak] { display: none !important; }
</style>

<script>
if (!window.rcptThemePicker) {
    // Alpine state factory for the theme cards + live preview (Task 712).
    // cfg: { theme, themes: {key:{bold,logo}}, mode: 'pra'|'fbr', live: bool,
    //        formId, paper: '80mm'|'58mm', prefs: {...initial preview prefs},
    //        fieldMap: {previewKey: formFieldName|null}   (Task 717, optional) —
    //          per-page override of the default rp_/lp_ receipt-settings field
    //          names; explicit null = "this page has no such field, keep the
    //          initial pref". Lets business-profile pages (rd_*, receipt_footer_note,
    //          print_paper_size) reuse the same live preview.
    //        textMap: {name|address|phone|ntn|email: [fieldNames...]} (optional) —
    //          live header text from identity inputs; empty inputs fall back to
    //          the server-rendered sample text. }
    window.rcptThemePicker = function (cfg) {
        return {
            tab: 'pra',
            optional: {},
            theme: cfg.theme,
            typography: Object.assign({font_family:'legacy',item_text:'legacy',wide_names:false},cfg.typography||{}),
            paper: cfg.paper || '80mm',
            p: Object.assign({}, cfg.prefs || {}),
            init: function () {
                var self = this;
                var form = document.getElementById(cfg.formId);
                if (!form) { return; }
                ['input', 'change'].forEach(function (ev) {
                    form.addEventListener(ev, function () { self.sync(); });
                });
                this.$watch('tab', function () { self.sync(); });
                this.sync();
            },
            sync: function () {
                var self = this;
                var form = document.getElementById(cfg.formId);
                if (!form) { return; }
                var val = function (name, dflt) {
                    var el = form.querySelector('[name="' + name + '"]');
                    return (el && el.value) ? el.value : dflt;
                };
                // Task 717: resolve a preview key to this page's form field name.
                var fname = function (key, dflt) {
                    if (cfg.fieldMap && Object.prototype.hasOwnProperty.call(cfg.fieldMap, key)) { return cfg.fieldMap[key]; }
                    return dflt;
                };
                var omName = fname('orderMatch', 'rp_order_match');
                if (omName) {
                    var om = form.querySelector('input[name="' + omName + '"]:checked');
                    this.p.orderMatch = om ? om.value : (this.p.orderMatch || 'off');
                }
                ['font_family','item_text'].forEach(function(key){var el=form.querySelector('[name="rp_'+key+'"]');if(el)self.typography[key]=el.value;});
                var wn=form.querySelector('[name="rp_wide_names"]');if(wn)this.typography.wide_names=wn.checked;
                if (cfg.live) {
                    var pre = this.tab === 'local' ? 'lp_' : 'rp_';
                    // Missing checkbox = page doesn't expose that toggle — keep the
                    // initial pref (was hardcoded defaults before Task 717).
                    var setChk = function (key, dflt) {
                        var n = fname(key, dflt);
                        if (!n) { return; }
                        var el = form.querySelector('input[type="checkbox"][name="' + n + '"]');
                        if (el) { self.p[key] = el.checked; }
                    };
                    setChk('address', pre + 'show_address');
                    setChk('ntn', pre + 'show_ntn');
                    setChk('email', pre + 'show_email');
                    setChk('phone', pre + 'show_mobile');
                    setChk('cashier', pre + 'show_cashier');
                    setChk('bizname', pre + 'show_business_name');
                    setChk('devby', pre + 'show_developed_by');
                    setChk('footer', pre + 'show_footer');
                    setChk('tax', pre + 'show_tax');
                    setChk('logo', 'rp_show_logo');
                    setChk('logoFinalsOnly', 'rp_logo_finals_only');
                    setChk('menuQr', 'rp_show_menu_qr');
                    // verifyLine applies to both PRA and FBR — always use rp_show_verify_line regardless of tab.
                    setChk('verifyLine', 'rp_show_verify_line');
                    var ftName = fname('footerText', pre + 'footer_text');
                    if (ftName) { this.p.footerText = (val(ftName, '') || '').trim(); }
                    var paperName = fname('paper', 'rp_printer_size');
                    if (paperName) {
                        var pv = val(paperName, this.paper);
                        // business-profile pages post print_paper_size (thermal/thermal58/a4)
                        this.paper = pv === 'thermal58' ? '58mm' : ((pv === 'thermal' || pv === 'a4') ? '80mm' : pv);
                    }
                }
                this.optional = {};
                var stream = this.tab === 'local' ? 'local' : 'pra';
                form.querySelectorAll('[name^="receipt_options_' + stream + '["]').forEach(function (el) {
                    var match = el.name.match(/\[([^\]]+)\]$/);
                    if (match) self.optional[match[1]] = el.value;
                });
                // Task 717: live header text from identity inputs (business-profile).
                if (cfg.textMap) {
                    var root = this.$root || document;
                    Object.keys(cfg.textMap).forEach(function (key) {
                        var parts = (cfg.textMap[key] || []).map(function (n) { return (val(n, '') || '').trim(); }).filter(Boolean);
                        var sep = key === 'address' ? ', ' : (key === 'phone' ? ' / ' : ' ');
                        var text = parts.join(sep);
                        root.querySelectorAll('[data-tn-prev="' + key + '"]').forEach(function (el) {
                            if (el.dataset.tnDflt === undefined) { el.dataset.tnDflt = el.textContent; }
                            el.textContent = text || el.dataset.tnDflt;
                        });
                    });
                }
            },
            optionShow: function (key, legacy) {
                if (legacy === undefined) legacy = true;
                return this.optional[key] === 'show' ? true : (this.optional[key] === 'hide' ? false : legacy);
            },
            typographyPreviewCss: function () {
                var t=Object.assign({},this.typography),o=this.optional,css='',scope='[data-tn-typography-preview]';
                ['font_family','item_text'].forEach(function(k){if(o[k]&&o[k]!=='inherit')t[k]=o[k];});
                if(t.font_family==='sans'||t.font_family==='mono')css+=scope+','+scope+' *{font-family:'+(t.font_family==='mono'?'"Courier New",Courier,monospace':'Arial,Helvetica,sans-serif')+' !important;}';
                if(t.item_text==='compact'||t.item_text==='large')css+=scope+' .tn-preview-items td{font-size:'+(t.item_text==='compact'?(this.paper==='58mm'?9:10):(this.paper==='58mm'?12:14))+'px !important;}';
                if(t.wide_names)css+=scope+' .tn-preview-items tr>:nth-child(1){width:52% !important;}'+scope+' .tn-preview-items tr>:nth-child(2){width:12% !important;}'+scope+' .tn-preview-items tr>:nth-child(3){width:16% !important;}'+scope+' .tn-preview-items tr>:nth-child(4){width:20% !important;}';
                var weights={item_bold:'.tn-preview-items td,.tn-preview-items td *',heading_bold:'[data-optional-heading]',total_bold:'[data-optional-total] td'};
                Object.keys(weights).forEach(function(k){if(o[k]==='bold'||o[k]==='normal')css+=weights[k].split(',').map(function(sel){return scope+' '+sel;}).join(',')+'{font-weight:'+(o[k]==='bold'?700:400)+' !important;}';});
                ['heading_size','total_size'].forEach(function(k){if(o[k]==='compact'||o[k]==='large')css+=scope+' '+(k==='heading_size'?'[data-optional-heading]':'[data-optional-total] td')+'{font-size:'+(o[k]==='compact'?(this.paper==='58mm'?12:14):(this.paper==='58mm'?17:20))+'px !important;}';},this);
                var sizes={small:14,medium:22,large:this.paper==='58mm'?28:32};
                if(sizes[o.logo_size])css+=scope+' [data-optional-logo]{width:'+sizes[o.logo_size]+'mm !important;max-width:100%;height:auto !important;max-height:'+sizes[o.logo_size]+'mm !important;}';
                var qr={small:64,medium:84,large:100};
                if(this.tab==='local'&&qr[o.extra_qr_size])css+=scope+' [data-optional-qr]{width:'+qr[o.extra_qr_size]+'px !important;height:'+qr[o.extra_qr_size]+'px !important;}';
                if(o.spacing==='compact'||o.spacing==='roomy')css+=scope+' .tn-preview-items td,'+scope+' [data-optional-info] td{padding-top:'+(o.spacing==='compact'?1:4)+'px !important;padding-bottom:'+(o.spacing==='compact'?1:4)+'px !important;}';
                return css;
            },
            thBold: function () {
                var t = cfg.themes[this.theme];
                return t ? !!t.bold : true;
            },
            thLogo: function () {
                if(this.optional.logo_position==='center'||this.optional.logo_position==='side')return this.optional.logo_position;
                var t = cfg.themes[this.theme];
                return t ? t.logo : 'center';
            },
            showLogoNow: function () {
                if (!this.optionShow('show_logo',this.p.logo)) { return false; }
                // Mirrors the real gate: logo_finals_only suppresses the logo on
                // local/provisional bills (PRA panel's Local tab preview only).
                if (cfg.mode === 'pra' && this.tab === 'local' && this.p.logoFinalsOnly) { return false; }
                return true;
            },
            serialNow: function () {
                if (cfg.mode === 'fbr') { return 'INV-2026-00817'; }
                return this.tab === 'local' ? 'L0817' : 'P817';
            },
            qrNow: function () {
                if (cfg.mode === 'fbr') { return true; }
                // PRA fiscal QR always prints; Menu-QR toggle gates the local bill's QR.
                if (this.tab === 'local' && (this.optional.show_menu_qr === 'show' || this.optional.show_menu_qr === 'hide')) return this.optionShow('show_menu_qr', !!this.p.menuQr);
                return this.tab === 'local' ? !!this.p.menuQr : true;
            }
        };
    };
}
</script>
