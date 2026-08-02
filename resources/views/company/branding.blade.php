<x-app-layout>
    <div class="py-8">
        <div class="{{ $allowed ? 'max-w-7xl' : 'max-w-4xl' }} mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-6">
                <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Invoice Branding</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">White-label your invoice PDFs, public share pages and invoice emails with your own logo, color and footer.</p>
            </div>

            @if(!$allowed)
            {{-- ═══ Upgrade nudge — plan does not include white_label ═══ --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-8 text-center">
                <div class="mx-auto w-14 h-14 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center mb-4">
                    <svg class="w-7 h-7 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </div>
                <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">White-Label Branding is a Premium feature</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 max-w-md mx-auto">Show your own logo, accent color and footer text on invoice PDFs, public share pages and invoice emails — and optionally remove TaxNest branding entirely.</p>
                <ul class="text-sm text-gray-600 dark:text-gray-300 mt-5 space-y-2 max-w-xs mx-auto text-left">
                    <li class="flex items-start gap-2"><svg class="w-4 h-4 text-emerald-500 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Your logo on every invoice PDF style</li>
                    <li class="flex items-start gap-2"><svg class="w-4 h-4 text-emerald-500 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Custom accent color on PDFs &amp; share pages</li>
                    <li class="flex items-start gap-2"><svg class="w-4 h-4 text-emerald-500 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Your own footer lines</li>
                    <li class="flex items-start gap-2"><svg class="w-4 h-4 text-emerald-500 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Hide TaxNest branding completely</li>
                </ul>
                <a href="/billing/plans" class="inline-flex items-center gap-2 mt-6 px-6 py-3 rounded-lg text-sm font-semibold text-white" style="background-color: #0a4d5c;">
                    Upgrade to Premium
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                </a>
                <p class="text-xs text-gray-400 mt-3">Available on the DI Premium plan.</p>
            </div>
            @else

            @if($errors->any())
            <div class="mb-4 p-4 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700">
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            <div x-data="brandingPreview({
                enabled: @js(session()->hasOldInput() ? old('branding_enabled') == '1' : (bool) $settings['enabled']),
                accent: @js(old('accent_color', $settings['accent'] ?? '')),
                footer1: @js(old('footer_line1', $settings['footer_line1'])),
                footer2: @js(old('footer_line2', $settings['footer_line2'])),
                hidePlatform: @js(session()->hasOldInput() ? old('hide_platform_branding') == '1' : (bool) $settings['hide_platform']),
                savedLogoUrl: @js($logoUrl),
                companyName: @js($company->name),
            })" class="lg:grid lg:grid-cols-2 lg:gap-6 lg:items-start">

            <form method="POST" action="/company/branding" enctype="multipart/form-data" class="space-y-6">
                @csrf
                @method('PUT')

                {{-- Master toggle --}}
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" name="branding_enabled" value="1" x-model="enabled" class="mt-1 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                        <span>
                            <span class="block text-sm font-semibold text-gray-800 dark:text-gray-100">Enable white-label branding</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">When off, invoices use the standard TaxNest look — the B&amp;W PDF style stays pure black &amp; white.</span>
                        </span>
                    </label>
                </div>

                {{-- Logo --}}
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-1">Logo</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">PNG or JPG, up to 1 MB (min 32&times;32, max 2500&times;2500 px). A transparent PNG looks best. Appears in the PDF header, share page and emails.</p>
                    @if($logoUrl)
                    <div class="flex items-center gap-4 mb-4">
                        <img src="{{ $logoUrl }}" alt="Current logo" class="h-12 w-auto rounded border border-gray-200 dark:border-gray-700 bg-white p-1">
                        <label class="flex items-center gap-2 text-sm text-red-600 cursor-pointer">
                            <input type="checkbox" name="remove_logo" value="1" x-model="removeLogo" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                            Remove current logo
                        </label>
                    </div>
                    @endif
                    <input type="file" name="logo" accept=".png,.jpg,.jpeg" @change="onLogoPick($event)" class="block w-full text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 cursor-pointer">
                </div>

                {{-- Accent color --}}
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-1">Accent color</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Used for heading bars and highlights on PDFs and the share page. Leave empty to keep each PDF style's default colors.</p>
                    <div class="flex items-center gap-3">
                        <input type="color" :value="accent || '#0a4d5c'" @input="accent = $event.target.value" class="h-10 w-14 p-1 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 cursor-pointer">
                        <input type="text" name="accent_color" x-model="accent" placeholder="#0A4D5C" maxlength="7" class="w-36 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500 text-sm">
                        <button type="button" @click="accent = ''" class="text-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 underline">Use default</button>
                    </div>
                </div>

                {{-- Footer lines --}}
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-1">Footer text</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Up to two lines printed at the bottom of every invoice PDF, share page and email — e.g. your tagline, return policy or contact line.</p>
                    <div class="space-y-3">
                        <input type="text" name="footer_line1" x-model="footer1" maxlength="150" placeholder="Footer line 1" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500 text-sm">
                        <input type="text" name="footer_line2" x-model="footer2" maxlength="150" placeholder="Footer line 2 (optional)" class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500 text-sm">
                    </div>
                </div>

                {{-- Hide platform branding + compliance note --}}
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" name="hide_platform_branding" value="1" x-model="hidePlatform" class="mt-1 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                        <span>
                            <span class="block text-sm font-semibold text-gray-800 dark:text-gray-100">Hide TaxNest branding</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">Removes the "TaxNest" credit line from PDFs, the share page and invoice emails.</span>
                        </span>
                    </label>
                    <div class="mt-4 p-3 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800 text-xs text-blue-800 dark:text-blue-200 leading-relaxed">
                        <strong>FBR compliance:</strong> the FBR QR code, FBR invoice number and tax breakdown always remain on your invoices — branding never hides or changes them. POS receipts are not affected by these settings.
                    </div>
                </div>

                <div class="flex items-center justify-end">
                    <button type="submit" class="inline-flex items-center px-6 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-lg shadow-sm">Save Branding</button>
                </div>
            </form>

            {{-- ═══ Live preview — sample invoice reflecting unsaved changes ═══ --}}
            <div class="mt-8 lg:mt-0 lg:sticky lg:top-6">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 uppercase tracking-wide">Live preview</h3>
                    <span class="text-xs text-gray-400">Sample invoice — updates as you type, nothing is saved until you click Save</span>
                </div>

                <div x-show="!enabled" class="mb-3 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-xs text-amber-800 dark:text-amber-200">
                    White-label branding is switched <strong>off</strong> — invoices will use the standard TaxNest look shown below.
                </div>

                <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden text-gray-800" style="font-size: 13px;">
                    {{-- Header bar (accent zone) --}}
                    <div class="px-5 py-4 flex items-center justify-between" :style="{ backgroundColor: previewAccent, color: previewAccentText }">
                        <div class="flex items-center gap-3 min-w-0">
                            <template x-if="previewLogo">
                                <img :src="previewLogo" alt="Logo preview" class="h-10 w-auto max-w-[120px] object-contain bg-white rounded p-0.5 flex-shrink-0">
                            </template>
                            <div class="min-w-0">
                                <div class="font-bold text-base truncate" x-text="companyName"></div>
                                <div class="text-[11px] opacity-80">NTN: 1234567-8 &middot; Sample Address, Lahore</div>
                            </div>
                        </div>
                        <div class="text-right flex-shrink-0 ml-3">
                            <div class="font-bold tracking-wide">INVOICE</div>
                            <div class="text-[11px] opacity-80">INV-2026-0001</div>
                        </div>
                    </div>

                    {{-- FBR strip — always present, never affected by branding --}}
                    <div class="px-5 py-2.5 bg-gray-50 border-b border-gray-200 flex items-center justify-between gap-3">
                        <div class="text-[11px] text-gray-600">
                            <div><span class="font-semibold">FBR Invoice No:</span> 100000000000001</div>
                            <div class="text-gray-400">Verify at fbr.gov.pk — always shown</div>
                        </div>
                        <div class="w-12 h-12 flex-shrink-0 border-2 border-gray-300 rounded grid place-items-center bg-white" title="FBR QR code — always printed">
                            <svg class="w-8 h-8 text-gray-400" fill="currentColor" viewBox="0 0 24 24"><path d="M3 3h8v8H3V3zm2 2v4h4V5H5zm8-2h8v8h-8V3zm2 2v4h4V5h-4zM3 13h8v8H3v-8zm2 2v4h4v-4H5zm10 0h2v2h-2v-2zm4 0h2v2h-2v-2zm-4 4h2v2h-2v-2zm4 0h2v2h-2v-2z"/></svg>
                        </div>
                    </div>

                    {{-- Sample line items --}}
                    <div class="px-5 py-3">
                        <table class="w-full text-[12px]">
                            <thead>
                                <tr class="border-b" :style="{ borderColor: previewAccent, color: previewAccent }">
                                    <th class="text-left py-1.5 font-semibold">Item</th>
                                    <th class="text-right py-1.5 font-semibold">Qty</th>
                                    <th class="text-right py-1.5 font-semibold">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-700">
                                <tr class="border-b border-gray-100"><td class="py-1.5">Sample Product A</td><td class="text-right">2</td><td class="text-right">Rs 5,000</td></tr>
                                <tr class="border-b border-gray-100"><td class="py-1.5">Sample Service B</td><td class="text-right">1</td><td class="text-right">Rs 3,000</td></tr>
                            </tbody>
                        </table>
                        <div class="mt-3 ml-auto w-48 space-y-1 text-[12px]">
                            <div class="flex justify-between text-gray-600"><span>Subtotal</span><span>Rs 8,000</span></div>
                            <div class="flex justify-between text-gray-600"><span>Sales Tax (18%)</span><span>Rs 1,440</span></div>
                            <div class="flex justify-between font-bold border-t pt-1" :style="{ borderColor: previewAccent, color: previewAccent }"><span>Total</span><span>Rs 9,440</span></div>
                        </div>
                        <p class="mt-2 text-[10px] text-gray-400">Tax breakdown is FBR-required and always shown.</p>
                    </div>

                    {{-- Footer (branding zone) --}}
                    <div class="px-5 py-3 border-t border-gray-200 text-center text-[11px] text-gray-500 space-y-0.5">
                        <template x-if="enabled && footer1.trim()"><div x-text="footer1"></div></template>
                        <template x-if="enabled && footer2.trim()"><div x-text="footer2"></div></template>
                        <div x-show="!(enabled && hidePlatform)" class="text-gray-400">Generated with TaxNest</div>
                    </div>
                </div>

                <p class="mt-3 text-xs text-gray-400 leading-relaxed">This is a simplified sample — actual PDFs vary by your chosen invoice style, but the logo, accent color and footer lines apply the same way. FBR QR, invoice number and tax breakdown are never affected.</p>
            </div>

            </div>

            <script>
                function brandingPreview(init) {
                    return {
                        enabled: !!init.enabled,
                        accent: init.accent || '',
                        footer1: init.footer1 || '',
                        footer2: init.footer2 || '',
                        hidePlatform: !!init.hidePlatform,
                        savedLogoUrl: init.savedLogoUrl || null,
                        companyName: init.companyName || 'Your Company',
                        removeLogo: false,
                        pickedLogo: null,
                        onLogoPick(e) {
                            const file = e.target.files && e.target.files[0];
                            if (!file) { this.pickedLogo = null; return; }
                            const reader = new FileReader();
                            reader.onload = (ev) => { this.pickedLogo = ev.target.result; };
                            reader.readAsDataURL(file);
                        },
                        get previewLogo() {
                            if (!this.enabled) return null;
                            if (this.pickedLogo) return this.pickedLogo;
                            if (this.removeLogo) return null;
                            return this.savedLogoUrl;
                        },
                        get previewAccent() {
                            const a = (this.accent || '').trim();
                            if (this.enabled && /^#[0-9a-fA-F]{6}$/.test(a)) return a.toLowerCase();
                            return '#0a4d5c';
                        },
                        get previewAccentText() {
                            const hex = this.previewAccent;
                            const r = parseInt(hex.substr(1, 2), 16);
                            const g = parseInt(hex.substr(3, 2), 16);
                            const b = parseInt(hex.substr(5, 2), 16);
                            const yiq = (r * 299 + g * 587 + b * 114) / 1000;
                            return yiq >= 150 ? '#111111' : '#ffffff';
                        },
                    };
                }
            </script>
            @endif
        </div>
    </div>
</x-app-layout>
