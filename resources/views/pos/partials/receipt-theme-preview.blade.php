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
             :style="'width:' + (paper === '58mm' ? '219px' : '302px') + ';font-size:' + (paper === '58mm' ? '11px' : '12px')"
             style="background:#fff;color:#000;font-family:Arial,Helvetica,sans-serif;padding:12px 10px;line-height:1.35;word-wrap:break-word;font-weight:500;">

            {{-- ── Header: logo + business name (theme-driven layout) ── --}}
            <div x-show="showLogoNow() && thLogo() === 'center'" style="text-align:center;padding-top:2px;">
                @if($tnLogoUri)
                <img src="{{ $tnLogoUri }}" alt="" style="width:96px;max-height:80px;object-fit:contain;display:block;margin:0 auto;">
                @else
                <span style="display:inline-block;width:64px;height:46px;border:2px solid #111;border-radius:8px;line-height:42px;font-weight:bold;font-size:11px;text-align:center;">LOGO</span>
                @endif
            </div>
            <div x-show="showLogoNow() && thLogo() === 'side'">
                <table style="width:100%;border-collapse:collapse;margin-bottom:2px;">
                    <tr>
                        <td style="text-align:left;vertical-align:middle;width:64%;padding:0;">
                            <span x-show="p.bizname" style="display:block;font-size:15px;font-weight:bold;text-align:left;">{{ $tnName }}</span>
                        </td>
                        <td style="text-align:right;vertical-align:middle;width:36%;padding:0;">
                            @if($tnLogoUri)
                            <img src="{{ $tnLogoUri }}" alt="" style="max-width:60px;max-height:34px;object-fit:contain;">
                            @else
                            <span style="display:inline-block;width:34px;height:26px;border:2px solid #111;border-radius:5px;line-height:22px;font-weight:bold;font-size:8px;text-align:center;">LOGO</span>
                            @endif
                        </td>
                    </tr>
                </table>
            </div>
            <div x-show="p.bizname && !(showLogoNow() && thLogo() === 'side')" style="text-align:center;">
                <span style="font-size:15px;font-weight:bold;">{{ $tnName }}</span>
            </div>

            <div style="text-align:center;font-size:10px;font-weight:600;line-height:1.4;">
                <div x-show="p.address">{{ $tnAddress }}</div>
                <div x-show="p.phone">Tel: {{ $tnPhone }}</div>
                @if($tnMode === 'pra')
                <div x-show="p.email">{{ $tnEmail }}</div>
                @endif
                <div x-show="p.ntn"><strong>NTN:</strong> {{ $tnNtn }}</div>
            </div>

            <div style="border-top:1px dashed #000;margin:4px 0;"></div>

            {{-- ── Serial box (top badge) ── --}}
            <div style="border:1.5px solid #000;padding:5px 4px;margin:5px 0;text-align:center;">
                <div style="font-size:12px;font-weight:bold;letter-spacing:1px;">SALE RECEIPT</div>
                <div style="font-size:11px;font-weight:bold;margin-top:2px;" x-text="serialNow()"></div>
            </div>

            {{-- ── Order Matching token / code (live radios on both panels) ── --}}
            <div x-show="p.orderMatch === 'token'" style="text-align:center;padding:2px 0 3px;">
                <span style="display:inline-block;border:2px solid #000;padding:2px 14px;font-size:15px;font-weight:900;">Token 42</span>
            </div>
            <div x-show="p.orderMatch === 'code'" style="text-align:center;padding:2px 0 3px;">
                <span style="display:inline-block;border:2px solid #000;padding:2px 14px;font-size:13px;font-weight:900;letter-spacing:2px;">ORD-7K3F</span>
            </div>

            {{-- ── Info lines ── --}}
            <table style="width:100%;border-collapse:collapse;margin:2px 0;">
                <tr><td style="font-size:11px;font-weight:bold;padding:1px 0;white-space:nowrap;">Date:</td><td style="font-size:11px;text-align:right;padding:1px 0;font-weight:600;">14/08/2026 07:45 PM</td></tr>
                <tr><td style="font-size:11px;font-weight:bold;padding:1px 0;white-space:nowrap;">Payment:</td><td style="font-size:11px;text-align:right;padding:1px 0;font-weight:600;">Cash</td></tr>
                <tr x-show="p.cashier"><td style="font-size:11px;font-weight:bold;padding:1px 0;white-space:nowrap;">Cashier:</td><td style="font-size:11px;text-align:right;padding:1px 0;font-weight:600;">Ali Raza</td></tr>
            </table>

            {{-- ── Items ── --}}
            <table style="width:100%;border-collapse:collapse;margin:4px 0;table-layout:fixed;">
                <tr>
                    <td style="width:44%;font-size:10px;font-weight:bold;text-transform:uppercase;border-top:1.5px solid #000;border-bottom:1.5px solid #000;padding:3px 1px;">Item</td>
                    <td style="width:12%;font-size:10px;font-weight:bold;text-transform:uppercase;border-top:1.5px solid #000;border-bottom:1.5px solid #000;padding:3px 1px;text-align:center;">Qty</td>
                    <td style="width:20%;font-size:10px;font-weight:bold;text-transform:uppercase;border-top:1.5px solid #000;border-bottom:1.5px solid #000;padding:3px 1px;text-align:right;">Price</td>
                    <td style="width:24%;font-size:10px;font-weight:bold;text-transform:uppercase;border-top:1.5px solid #000;border-bottom:1.5px solid #000;padding:3px 1px;text-align:right;">Total</td>
                </tr>
                <tr>
                    <td style="font-size:11px;padding:3px 1px;border-bottom:1px dashed #000;font-weight:600;">Chicken Burger</td>
                    <td style="font-size:11px;padding:3px 1px;border-bottom:1px dashed #000;text-align:center;font-weight:600;">2</td>
                    <td style="font-size:11px;padding:3px 1px;border-bottom:1px dashed #000;text-align:right;font-weight:600;">450.00</td>
                    <td style="font-size:11px;padding:3px 1px;border-bottom:1px dashed #000;text-align:right;font-weight:bold;">900.00</td>
                </tr>
                <tr>
                    <td style="font-size:11px;padding:3px 1px;border-bottom:1px dashed #000;font-weight:600;">Fries (Large)</td>
                    <td style="font-size:11px;padding:3px 1px;border-bottom:1px dashed #000;text-align:center;font-weight:600;">1</td>
                    <td style="font-size:11px;padding:3px 1px;border-bottom:1px dashed #000;text-align:right;font-weight:600;">250.00</td>
                    <td style="font-size:11px;padding:3px 1px;border-bottom:1px dashed #000;text-align:right;font-weight:bold;">250.00</td>
                </tr>
                <tr>
                    <td style="font-size:11px;padding:3px 1px;font-weight:600;">Soft Drink 1.5L</td>
                    <td style="font-size:11px;padding:3px 1px;text-align:center;font-weight:600;">1</td>
                    <td style="font-size:11px;padding:3px 1px;text-align:right;font-weight:600;">180.00</td>
                    <td style="font-size:11px;padding:3px 1px;text-align:right;font-weight:bold;">180.00</td>
                </tr>
            </table>

            {{-- ── Totals (tax rows follow the show-tax toggle) ── --}}
            <table style="width:100%;border-collapse:collapse;margin:3px 0 0;">
                <tr x-show="p.tax"><td style="font-size:11px;padding:2px 0;font-weight:600;">Subtotal:</td><td style="font-size:11px;text-align:right;padding:2px 0;font-weight:bold;white-space:nowrap;">PKR 1,330.00</td></tr>
                <tr x-show="p.tax"><td style="font-size:11px;padding:2px 0;font-weight:600;">Sales Tax:</td><td style="font-size:11px;text-align:right;padding:2px 0;font-weight:bold;white-space:nowrap;">PKR 213.00</td></tr>
                <tr>
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
                <div style="font-size:9px;margin-top:2px;">Verify at FBR Tax Asaan</div>
            </div>
            @else
            <div x-show="qrNow()" style="text-align:center;margin:6px 0 2px;">
                <span style="display:inline-block;width:64px;height:64px;border:2px solid #000;padding:6px;">
                    <span style="display:block;width:100%;height:100%;background:
                        linear-gradient(90deg,#000 25%,transparent 25%,transparent 50%,#000 50%,#000 75%,transparent 75%),
                        linear-gradient(#000 25%,transparent 25%,transparent 50%,#000 50%,#000 75%,transparent 75%);
                        background-size:14px 14px;"></span>
                </span>
                <div style="font-size:10px;margin-top:2px;font-weight:600;" x-text="tab === 'local' ? 'Scan to view invoice' : 'Verify via PRA Sahulat app'"></div>
            </div>
            @endif

            {{-- ── Footer ── --}}
            <div style="text-align:center;font-size:10px;margin-top:5px;line-height:1.5;font-weight:600;">
                <div x-show="p.footer" x-text="(p.footerText && p.footerText.length) ? p.footerText : 'Thank you for your purchase!'"></div>
                @if($tnMode === 'fbr')
                <div style="font-weight:bold;">FBR POS INTEGRATED</div>
                <div>Powered by TaxNest FBR POS</div>
                @else
                <div x-show="p.devby">Developed by: taxnest.com.pk</div>
                <div>14/08/2026 07:45:12 PM</div>
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
    //        formId, paper: '80mm'|'58mm', prefs: {...initial preview prefs} }
    window.rcptThemePicker = function (cfg) {
        return {
            tab: 'pra',
            theme: cfg.theme,
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
                var form = document.getElementById(cfg.formId);
                if (!form) { return; }
                var chk = function (name, dflt) {
                    var el = form.querySelector('input[type="checkbox"][name="' + name + '"]');
                    return el ? el.checked : dflt;
                };
                var val = function (name, dflt) {
                    var el = form.querySelector('[name="' + name + '"]');
                    return (el && el.value) ? el.value : dflt;
                };
                var om = form.querySelector('input[name="rp_order_match"]:checked');
                this.p.orderMatch = om ? om.value : (this.p.orderMatch || 'off');
                if (!cfg.live) { return; } // FBR: display prefs are static (business-profile owns them)
                var pre = this.tab === 'local' ? 'lp_' : 'rp_';
                this.p.address = chk(pre + 'show_address', true);
                this.p.ntn = chk(pre + 'show_ntn', true);
                this.p.email = chk(pre + 'show_email', true);
                this.p.phone = chk(pre + 'show_mobile', true);
                this.p.cashier = chk(pre + 'show_cashier', true);
                this.p.bizname = chk(pre + 'show_business_name', true);
                this.p.devby = chk(pre + 'show_developed_by', true);
                this.p.footer = chk(pre + 'show_footer', true);
                this.p.footerText = (val(pre + 'footer_text', '') || '').trim();
                this.p.tax = chk(pre + 'show_tax', true);
                this.p.logo = chk('rp_show_logo', true);
                this.p.logoFinalsOnly = chk('rp_logo_finals_only', false);
                this.p.menuQr = chk('rp_show_menu_qr', true);
                this.paper = val('rp_printer_size', this.paper);
            },
            thBold: function () {
                var t = cfg.themes[this.theme];
                return t ? !!t.bold : true;
            },
            thLogo: function () {
                var t = cfg.themes[this.theme];
                return t ? t.logo : 'center';
            },
            showLogoNow: function () {
                if (!this.p.logo) { return false; }
                // Mirrors the real gate: logo_finals_only suppresses the logo on
                // local/provisional bills (PRA panel's Local tab preview only).
                if (cfg.mode === 'pra' && this.tab === 'local' && this.p.logoFinalsOnly) { return false; }
                return true;
            },
            serialNow: function () {
                if (cfg.mode === 'fbr') { return 'INV-2026-00817'; }
                return this.tab === 'local' ? 'L-0817' : 'POS-2026-00817';
            },
            qrNow: function () {
                if (cfg.mode === 'fbr') { return true; }
                // PRA fiscal QR always prints; Menu-QR toggle gates the local bill's QR.
                return this.tab === 'local' ? !!this.p.menuQr : true;
            }
        };
    };
}
</script>
