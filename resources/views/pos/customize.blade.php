<x-pos-layout>
    @php
        $density = $company->pos_ui_density ? ucfirst($company->pos_ui_density) : 'Standard';
        $praOn   = (bool) (auth('pos')->user()?->praReportingEnabled($company) ?? false);
        $agentOn = $company->agentHandlesPra(); // submission mode badge (Agent ON vs Direct)
        $invOn   = (bool) ($company->inventory_enabled ?? false);
        // Tax-Inclusive Pricing (Menu-Rate-Final) — effective rates via PosTaxRule
        // helpers ONLY (global defaults + per-company overrides), never raw table reads.
        $taxIncOn  = (bool) ($company->pos_tax_inclusive ?? false);
        // 3-mode tax pricing (Jul 2026): exclusive | inclusive | inclusive_card_save
        // (helper validates + falls back to the legacy bool if the column is missing).
        $taxMode   = $company->posTaxPricingMode();
        $cashRate  = \App\Models\PosTaxRule::getRateForMethod('cash', $company);
        $cardRate  = \App\Models\PosTaxRule::getRateForMethod('card', $company);
        // Card-save example figures for the option card (Rs 590 menu price).
        $csBase590 = 590 * 100 / (100 + $cashRate);
        $csCard590 = round($csBase590 * (1 + $cardRate / 100));

        // Card sections — every POS feature reachable from this one hub.
        $sections = [
            [
                'title' => 'Setup & Features',
                'desc'  => 'Modules, presets, business info and compliance',
                'items' => [
                    ['label' => 'Modules & Features', 'desc' => 'Har feature ka apna toggle — tables, kitchen, barcode, recipes & more', 'url' => route('pos.features'), 'tone' => 'purple', 'badge' => $density, 'icon' => 'M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4'],
                    ['label' => 'Business Profile', 'desc' => 'Store name, NTN, logo, address & contact details', 'url' => route('pos.business-profile'), 'tone' => 'purple', 'badge' => 'Identity', 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
                    ['label' => 'Receipt Display', 'desc' => 'Receipt par address, NTN, phone & footer message ka control', 'url' => route('pos.receipt-settings'), 'tone' => 'purple', 'badge' => 'Receipt', 'icon' => 'M9 17v-2a2 2 0 012-2h2a2 2 0 012 2v2m-6 4h6a2 2 0 002-2V7a2 2 0 00-2-2H9a2 2 0 00-2 2v12a2 2 0 002 2z'],
                    ['label' => 'Printer Settings', 'desc' => 'Silent printing — bill aur KOT seedha printer par, bina popup ke', 'url' => route('pos.printer-settings'), 'tone' => ($company->printerSettings()['silent_print_enabled'] ?? false) ? 'emerald' : 'purple', 'badge' => ($company->printerSettings()['silent_print_enabled'] ?? false) ? 'Silent ON' : 'Popup', 'icon' => 'M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z'],
                    ['label' => 'PRA Compliance', 'desc' => 'PRA fiscal reporting & device credentials', 'url' => route('pos.pra-settings'), 'tone' => $praOn ? 'emerald' : 'amber', 'badge' => $praOn ? 'PRA ON' : 'PRA OFF', 'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
                ],
            ],
            [
                'title' => 'Operations',
                'desc'  => 'Services, registers and staff',
                'items' => [
                    ['label' => 'Services', 'desc' => 'Add-on services & extra charges', 'url' => route('pos.services'), 'tone' => 'purple', 'badge' => 'Manage', 'icon' => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10'],
                    ['label' => 'Deals', 'desc' => 'Day-wise combo deals & promo pricing', 'url' => route('pos.deals'), 'tone' => 'purple', 'badge' => 'Manage', 'icon' => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'],
                    ['label' => 'Terminals', 'desc' => 'Registers & device terminals', 'url' => route('pos.terminals'), 'tone' => 'purple', 'badge' => 'Manage', 'icon' => 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
                    ['label' => 'Team', 'desc' => 'Cashiers & staff access', 'url' => route('pos.team'), 'tone' => 'purple', 'badge' => 'Manage', 'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
                ],
            ],
            [
                'title' => 'Account & System',
                'desc'  => 'Plan, sync agent and your profile',
                'items' => [
                    ['label' => 'Billing & Plan', 'desc' => 'Subscription, plan & invoices', 'url' => route('pos.billing'), 'tone' => 'purple', 'badge' => 'Plan', 'icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
                    ['label' => 'PRA Sync Agent', 'desc' => 'Desktop sync app & access keys', 'url' => route('pos.agent'), 'tone' => $agentOn ? 'emerald' : 'blue', 'badge' => $agentOn ? 'Agent ON' : 'Direct', 'icon' => 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
                    ['label' => 'My Profile', 'desc' => 'Your login, name & password', 'url' => route('pos.user-profile'), 'tone' => 'purple', 'badge' => 'Account', 'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
                ],
            ],
        ];

        $tones = [
            'purple'  => ['ic' => 'bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400', 'bd' => 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300'],
            'emerald' => ['ic' => 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400', 'bd' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'],
            'amber'   => ['ic' => 'bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400', 'bd' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'],
            'blue'    => ['ic' => 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400', 'bd' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'],
        ];

        $themes = [
            'purple'   => ['#312e81', '#7c3aed'],
            'blue'     => ['#1e3a5f', '#2563eb'],
            'emerald'  => ['#064e3b', '#059669'],
            'orange'   => ['#7c2d12', '#ea580c'],
            'midnight' => ['#171717', '#404040'],
            'rose'     => ['#881337', '#e11d48'],
        ];

        // POS Style (owner, 22 Jul 2026): the dashboard style choice lives HERE now —
        // two main packages (Full / Saaf) + legacy fancy styles tucked away below.
        // The old dashboard style-picker dropdown was removed; this is the only place.
        $allowedStyles = ['default', 'toast', 'lightspeed', 'clover', 'oscar', 'shopify', 'saaf'];
        $curStyle = in_array($company->pos_dashboard_style, $allowedStyles, true) ? $company->pos_dashboard_style : 'default';
        $fancyStyles = [
            'toast'      => ['Toast Analytics', '#f59e0b'],
            'lightspeed' => ['Lightspeed Grid', '#6366f1'],
            'clover'     => ['Clover Insights', '#16a34a'],
            'oscar'      => ['Oscar Pakistan', '#0ea5e9'],
            'shopify'    => ['Shopify Modern', '#334155'],
        ];
        $onFancy = array_key_exists($curStyle, $fancyStyles);
    @endphp

    <div x-data="{ currentTheme: '{{ $company->pos_theme ?? 'purple' }}', guidedOn: {{ ($company->pos_guided_flow_enabled ?? true) ? 'true' : 'false' }}, savingGuided: false, quickOn: {{ ($company->pos_quick_type_enabled ?? false) ? 'true' : 'false' }}, savingQuick: false, invOn: {{ $invOn ? 'true' : 'false' }}, savingInv: false, restockOn: {{ ($company->pos_restock_on_void ?? true) ? 'true' : 'false' }}, savingRestock: false, autoDaycloseOn: {{ ($company->pos_auto_dayclose_24h ?? false) ? 'true' : 'false' }}, savingDayclose: false, kdsAutoOn: {{ ($company->pos_kds_auto_print ?? false) ? 'true' : 'false' }}, savingKdsAuto: false, lbFinal: '{{ in_array($company->pos_dayclose_final_local_action ?? 'save', ['save','delete'], true) ? ($company->pos_dayclose_final_local_action ?? 'save') : 'save' }}', lbProv: '{{ in_array($company->pos_dayclose_provisional_action ?? 'save', ['save','delete'], true) ? ($company->pos_dayclose_provisional_action ?? 'save') : 'save' }}', lbPersist: {{ ($company->pos_customer_spend_persist ?? true) ? 'true' : 'false' }}, savingLB: false, rcSecs: {{ (int) ($company->pos_receipt_autoclose_seconds ?? 10) }}, savingRc: false, setRc(s) { if (this.rcSecs === s || this.savingRc) return; const prev = this.rcSecs; this.rcSecs = s; this.savingRc = true; fetch('/pos/settings/receipt-autoclose', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({seconds:s})}).then(r=>r.json()).then(d=>{ if (!d || d.success !== true) { this.rcSecs = prev; alert((d && d.message) || 'Setting save nahi hui — dobara koshish karein.'); } }).catch(()=>{ this.rcSecs = prev; alert('Setting save nahi hui — dobara koshish karein.'); }).finally(()=>{ this.savingRc = false; }); }, taxMode: '{{ $taxMode }}', savingTaxInc: false, calcPrice: 590, cashRate: {{ (float) $cashRate }}, cardRate: {{ (float) $cardRate }}, get taxInc() { return this.taxMode !== 'exclusive'; }, calc(rate) { const p = parseFloat(this.calcPrice) || 0; if (this.taxMode === 'inclusive_card_save') { const base = p * 100 / (100 + this.cashRate); const tax = base * rate / 100; return { base: base, tax: tax, total: Math.round(base + tax) }; } if (this.taxInc) { const tax = p * rate / (100 + rate); return { base: p - tax, tax: tax, total: Math.round(p) }; } const tax = p * rate / 100; return { base: p, tax: tax, total: Math.round(p + tax) }; }, fmt(n) { return 'Rs ' + (Math.round(n * 100) / 100).toLocaleString(); }, setTaxMode(mode) { if (this.taxMode === mode || this.savingTaxInc) return; const prev = this.taxMode; this.taxMode = mode; this.savingTaxInc = true; fetch('/pos/settings/tax-pricing-mode', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({mode:mode})}).then(r=>r.json()).then(d=>{ if (!d || d.success !== true) { this.taxMode = prev; alert((d && d.message) || 'Setting save nahi hui — dobara koshish karein.'); } }).catch(()=>{ this.taxMode = prev; alert('Setting save nahi hui — dobara koshish karein.'); }).finally(()=>{ this.savingTaxInc = false; }); }, saveLB() { this.savingLB = true; fetch('/pos/settings/local-billing', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({final_action:this.lbFinal, provisional_action:this.lbProv, spend_persist:this.lbPersist})}).then(r=>r.json()).catch(()=>{}).finally(()=>{ this.savingLB=false; }) } }"
         class="max-w-5xl mx-auto p-4 sm:p-6 space-y-6">

        {{-- ═══════════ HERO ═══════════ --}}
        <div class="rounded-2xl bg-purple-600 p-6 sm:p-8 text-white shadow-xl relative overflow-hidden">
            <div class="relative">
                <div class="flex items-center gap-2 mb-2">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white/20 backdrop-blur text-[10px] font-bold uppercase tracking-wider">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        POS Control Center
                    </span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold mb-1.5">Customize POS</h1>
                <p class="text-sm sm:text-base text-white/85 max-w-2xl">Aapki POS ki saari settings ab ek hi jagah. Look &amp; feel, billing flow, modules, business info, compliance, team aur account — sab yahin se control karein.</p>
            </div>
        </div>

        {{-- ═══════════ POS STYLE — Full vs Saaf (owner, 22 Jul 2026) ═══════════ --}}
        <section id="style" x-data="{ curStyle: '{{ $curStyle }}', savingStyle: false, moreStyles: {{ $onFancy ? 'true' : 'false' }},
            setStyle(s) {
                if (this.curStyle === s || this.savingStyle) return;
                this.savingStyle = true;
                fetch('{{ route('pos.settings.dashboard-style') }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({style:s})})
                    .then(r=>r.json())
                    .then(d=>{ if (d && d.success) { this.curStyle = s; window.location.reload(); } else { this.savingStyle = false; alert((d && d.message) || 'Style save nahi hua — dobara koshish karein.'); } })
                    .catch(()=>{ this.savingStyle = false; alert('Style save nahi hua — internet check kar ke dobara koshish karein.'); });
            } }">
            <div class="px-1 mb-3 flex items-center justify-between gap-3 flex-wrap">
                <div>
                    <h2 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wide">POS Ka Style</h2>
                    <p class="text-[12px] text-gray-500 dark:text-gray-400">Ek click par poora andaz badal jata hai — dashboard aur navigation dono. Aap ka data aur sale screen bilkul wohi rehte hain.</p>
                </div>
                <span class="shrink-0 text-[10px] font-semibold text-gray-400" x-show="savingStyle" x-cloak>Badla ja raha hai…</span>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">

                {{-- FULL (default) --}}
                <button type="button" @click="setStyle('default')"
                    class="text-left rounded-2xl border-2 p-4 transition bg-white dark:bg-gray-900"
                    :class="curStyle === 'default' ? 'border-teal-600' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                    <div class="flex items-center justify-between mb-1.5">
                        <p class="text-sm font-extrabold text-gray-900 dark:text-white">Full — Poora Dashboard</p>
                        <span x-show="curStyle === 'default'" x-cloak class="px-2 py-0.5 rounded-full bg-teal-600 text-white text-[10px] font-bold">ACTIVE</span>
                    </div>
                    <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-relaxed mb-3">Saare widgets, analytics aur quick actions — poori detail ek nazar mein. Zyada maloomat pasand karne walon ke liye.</p>
                    {{-- mini preview (pure CSS mockup) --}}
                    <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden pointer-events-none select-none" aria-hidden="true">
                        <div class="h-6 bg-purple-900 flex items-center gap-1 px-2">
                            <span class="w-2 h-2 rounded-full bg-white/50 shrink-0"></span>
                            @for($i = 0; $i < 7; $i++)<span class="h-2 w-7 rounded-full bg-white/25"></span>@endfor
                        </div>
                        <div class="bg-gray-100 dark:bg-gray-800 p-2 space-y-1.5">
                            <div class="h-5 rounded-md bg-purple-600"></div>
                            <div class="grid grid-cols-4 gap-1.5">
                                @for($i = 0; $i < 4; $i++)
                                <div class="h-9 rounded-md bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-1">
                                    <div class="h-1.5 w-2/3 rounded bg-purple-300 mb-1"></div>
                                    <div class="h-2 w-1/2 rounded bg-gray-300 dark:bg-gray-600"></div>
                                </div>
                                @endfor
                            </div>
                            <div class="grid grid-cols-2 gap-1.5">
                                <div class="h-10 rounded-md bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700"></div>
                                <div class="h-10 rounded-md bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700"></div>
                            </div>
                        </div>
                    </div>
                </button>

                {{-- SAAF (simple) --}}
                <button type="button" @click="setStyle('saaf')"
                    class="text-left rounded-2xl border-2 p-4 transition bg-white dark:bg-gray-900"
                    :class="curStyle === 'saaf' ? 'border-teal-600' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                    <div class="flex items-center justify-between mb-1.5">
                        <p class="text-sm font-extrabold text-gray-900 dark:text-white">Saaf — Simple</p>
                        <span x-show="curStyle === 'saaf'" x-cloak class="px-2 py-0.5 rounded-full bg-teal-600 text-white text-[10px] font-bold">ACTIVE</span>
                    </div>
                    <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-relaxed mb-3">Bilkul saaf dashboard + simple 5-button navigation. Sale screen bhi saada look mein — faltu buttons "Mazeed" ke peechay, lekin saare features waise hi kaam karte hain. Kam cheezein, seedhi baat — tez kaam karne walon ke liye.</p>
                    {{-- mini preview (pure CSS mockup) --}}
                    <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden pointer-events-none select-none" aria-hidden="true">
                        <div class="h-6 flex items-center gap-1.5 px-2" style="background:#0A4D5C">
                            <span class="w-2 h-2 rounded-full bg-white/50 shrink-0"></span>
                            @for($i = 0; $i < 5; $i++)<span class="h-2.5 w-10 rounded-full bg-white/25"></span>@endfor
                        </div>
                        <div class="bg-gray-100 dark:bg-gray-800 p-2 space-y-1.5">
                            <div class="grid grid-cols-3 gap-1.5">
                                @for($i = 0; $i < 3; $i++)
                                <div class="h-12 rounded-md bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-1.5">
                                    <div class="h-1.5 w-2/3 rounded mb-1.5" style="background:#99f6e4"></div>
                                    <div class="h-2.5 w-1/2 rounded" style="background:#0d9488"></div>
                                </div>
                                @endfor
                            </div>
                            <div class="h-9 rounded-md bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700"></div>
                        </div>
                    </div>
                </button>
            </div>

            {{-- Legacy fancy styles — tucked away so the main choice stays 2 cards --}}
            <div class="mt-3 px-1">
                <button type="button" @click="moreStyles = !moreStyles" class="inline-flex items-center gap-1.5 text-[12px] font-semibold text-gray-500 dark:text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition">
                    <svg class="w-3.5 h-3.5 transition-transform" :class="moreStyles && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                    Mazeed styles (purane designs)
                </button>
                <div x-show="moreStyles" x-cloak class="mt-2 flex flex-wrap gap-2">
                    @foreach($fancyStyles as $fid => [$fname, $fcolor])
                    <button type="button" @click="setStyle('{{ $fid }}')"
                        class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border-2 text-[12px] font-bold transition bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300"
                        :class="curStyle === '{{ $fid }}' ? 'border-teal-600' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                        <span class="w-3 h-3 rounded-full shrink-0" style="background: {{ $fcolor }}"></span>
                        {{ $fname }}
                        <span x-show="curStyle === '{{ $fid }}'" x-cloak class="px-1.5 py-0.5 rounded-full bg-teal-600 text-white text-[9px] font-bold">ACTIVE</span>
                    </button>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ═══════════ APPEARANCE & EXPERIENCE ═══════════ --}}
        <section>
            <div class="px-1 mb-3">
                <h2 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wide">Appearance &amp; Experience</h2>
                <p class="text-[12px] text-gray-500 dark:text-gray-400">Theme aur billing flow yahin se badal lein — fauran apply ho jata hai</p>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">

                {{-- Theme picker --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">POS Theme</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400" x-text="'Active: ' + currentTheme.charAt(0).toUpperCase() + currentTheme.slice(1)"></p>
                        </div>
                    </div>
                    <div class="grid grid-cols-6 gap-2 mt-4">
                        @foreach($themes as $t => $g)
                        <button type="button"
                            @click="currentTheme='{{ $t }}'; document.body.setAttribute('data-theme','{{ $t }}'); fetch('/pos/settings/theme', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'{{ $t }}'})}).catch(()=>{})"
                            class="h-10 rounded-xl ring-2 ring-offset-2 ring-offset-white dark:ring-offset-gray-900 transition"
                            :class="currentTheme==='{{ $t }}' ? 'ring-purple-500' : 'ring-transparent'"
                            style="background:linear-gradient(135deg,{{ $g[0] }},{{ $g[1] }})"
                            title="{{ ucfirst($t) }}"></button>
                        @endforeach
                    </div>
                </div>

                {{-- Guided keyboard billing --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 7h14a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2V9a2 2 0 012-2z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 11h.01M11 11h.01M15 11h.01M7 14h10"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">Guided Keyboard Billing</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">Enter-driven tez billing flow cashiers ke liye</p>
                    </div>
                    <button type="button"
                        @click="guidedOn=!guidedOn; savingGuided=true; fetch('/pos/settings/guided-flow', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:guidedOn})}).then(r=>r.json()).catch(()=>{}).finally(()=>{ savingGuided=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="guidedOn ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="guidedOn && 'translate-x-6'"></span>
                    </button>
                </div>

                {{-- Company default language (owner, 30 Jul 2026) --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-sky-100 text-sky-600 dark:bg-sky-900/30 dark:text-sky-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.company_default_language') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.company_default_language_hint') }}</p>
                    </div>
                    @php $tnCoLang = in_array($company->default_language ?? 'ur', ['ur','en'], true) ? ($company->default_language ?? 'ur') : 'ur'; @endphp
                    <div class="flex gap-2 shrink-0">
                        <form method="POST" action="{{ route('pos.settings.default-language') }}">
                            @csrf
                            <input type="hidden" name="default_language" value="ur">
                            <button type="submit" class="px-3 py-1.5 rounded-lg text-[11px] font-bold border transition {{ $tnCoLang === 'ur' ? 'bg-sky-600 text-white border-sky-600' : 'text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800' }}">{{ __('pos.language_roman_urdu') }}</button>
                        </form>
                        <form method="POST" action="{{ route('pos.settings.default-language') }}">
                            @csrf
                            <input type="hidden" name="default_language" value="en">
                            <button type="submit" class="px-3 py-1.5 rounded-lg text-[11px] font-bold border transition {{ $tnCoLang === 'en' ? 'bg-sky-600 text-white border-sky-600' : 'text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800' }}">{{ __('pos.language_english') }}</button>
                        </form>
                    </div>
                </div>

                {{-- Receipt popup auto-close (owner, 23 Jul 2026 — default 10 sec) --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-600 dark:bg-teal-900/30 dark:text-teal-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">Receipt Popup Auto-Close</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">Bill ke baad receipt popup itne second mein khud band ho jaye — cashier ke haath lagane par timer ruk jata hai. "Kabhi nahi" = popup khula rahe (purana tareeqa).</p>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ([0 => 'Kabhi nahi', 5 => '5 sec', 10 => '10 sec', 15 => '15 sec', 30 => '30 sec'] as $s => $label)
                        <button type="button" @click="setRc({{ $s }})"
                            class="px-3.5 py-1.5 rounded-full text-xs font-bold border transition"
                            :class="rcSecs === {{ $s }} ? 'bg-teal-600 border-teal-600 text-white' : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-teal-400'">
                            {{ $label }}
                        </button>
                        @endforeach
                    </div>
                </div>

                {{-- Quick Type Mode — OPT-IN (default OFF, owner 22 Jul 2026) --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-sky-100 text-sky-600 dark:bg-sky-900/30 dark:text-sky-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">Quick Type Mode</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">Sale screen par "Quick" button (F7) — "chai 2, samosa 1" type kar ke seedha cart. Khaane-peene ki dukaano ke liye behtareen.</p>
                    </div>
                    <button type="button"
                        @click="quickOn=!quickOn; savingQuick=true; fetch('/pos/settings/quick-type', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:quickOn})}).then(r=>r.json()).catch(()=>{}).finally(()=>{ savingQuick=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="quickOn ? 'bg-sky-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="quickOn && 'translate-x-6'"></span>
                    </button>
                </div>

                {{-- Inventory tracking (moved here from Business Profile) --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">Inventory Tracking</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">Har sale par stock khud minus ho — low-stock alerts ke saath</p>
                    </div>
                    <button type="button"
                        @click="invOn=!invOn; savingInv=true; fetch('/pos/settings/inventory-toggle', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:invOn})}).then(r=>r.json()).catch(()=>{}).finally(()=>{ savingInv=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="invOn ? 'bg-blue-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="invOn && 'translate-x-6'"></span>
                    </button>
                </div>

                {{-- Restock on bill delete / edit --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3" x-show="invOn" x-cloak>
                    <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">Bill delete/edit par stock wapas</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">Bill delete ya edit hone par becha gaya maal khud inventory mein wapas add ho</p>
                    </div>
                    <button type="button"
                        @click="restockOn=!restockOn; savingRestock=true; fetch('/pos/settings/restock-toggle', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:restockOn})}).then(r=>r.json()).catch(()=>{}).finally(()=>{ savingRestock=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="restockOn ? 'bg-amber-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="restockOn && 'translate-x-6'"></span>
                    </button>
                </div>

                @if($company->restaurant_mode ?? false)
                {{-- KDS auto-print KOT (P6, F5) — kitchen display device prints new orders itself --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-600 dark:bg-teal-900/30 dark:text-teal-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">Kitchen Display auto-print KOT</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">Naya order aate hi KDS screen wali device khud KOT ticket print kare (kitchen printer KDS device se attached ho)</p>
                    </div>
                    <button type="button"
                        @click="kdsAutoOn=!kdsAutoOn; savingKdsAuto=true; fetch('/pos/settings/kds-auto-print', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:kdsAutoOn})}).then(r=>r.json()).catch(()=>{}).finally(()=>{ savingKdsAuto=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="kdsAutoOn ? 'bg-teal-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="kdsAutoOn && 'translate-x-6'"></span>
                    </button>
                </div>
                @endif
            </div>
        </section>

        {{-- ═══════════ TAX PRICING MODE (Menu-Rate-Final) ═══════════ --}}
        <section>
            <div class="px-1 mb-3">
                <h2 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wide">Tax Pricing Mode</h2>
                <p class="text-[12px] text-gray-500 dark:text-gray-400">Menu price mein tax upar se lage ya andar hi shamil ho — apni shop ka style chunein</p>
            </div>
            <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-600 dark:bg-teal-900/30 dark:text-teal-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21l-2-1-2 1-2-1-2 1-2-1-2 1V5a2 2 0 012-2h10a2 2 0 012 2v16z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">Bill par tax kaise lage?</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">Tax hamesha poora PRA ko jata hai — farq sirf yeh hai ke customer se kitna wusool hota hai</p>
                    </div>
                    <span class="shrink-0 text-[10px] font-semibold text-gray-400" x-show="savingTaxInc" x-cloak>Saving…</span>
                </div>

                <div class="grid sm:grid-cols-3 gap-3">
                    {{-- Option 1: Standard (tax add-on) --}}
                    <button type="button" @click="setTaxMode('exclusive')"
                        class="text-left rounded-xl border-2 p-4 transition"
                        :class="taxMode === 'exclusive' ? 'border-teal-600 bg-teal-50 dark:bg-teal-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 hover:border-gray-300'">
                        <div class="flex items-center justify-between mb-1.5">
                            <p class="text-sm font-extrabold text-gray-900 dark:text-white">Standard — Tax Upar Se</p>
                            <span x-show="taxMode === 'exclusive'" x-cloak class="px-2 py-0.5 rounded-full bg-teal-600 text-white text-[10px] font-bold">ACTIVE</span>
                        </div>
                        <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-relaxed">Menu price par tax alag se add hota hai. Customer menu price se zyada ada karta hai.</p>
                        <p class="text-[11px] font-semibold text-gray-700 dark:text-gray-300 mt-2">Misal: Rs 500 + {{ rtrim(rtrim(number_format($cashRate, 2), '0'), '.') }}% tax = customer <span class="font-extrabold">Rs {{ number_format(round(500 + 500 * $cashRate / 100)) }}</span> deta hai</p>
                    </button>

                    {{-- Option 2: Tax-Inclusive (Menu-Rate-Final — same total on cash & card) --}}
                    <button type="button" @click="setTaxMode('inclusive')"
                        class="text-left rounded-xl border-2 p-4 transition"
                        :class="taxMode === 'inclusive' ? 'border-teal-600 bg-teal-50 dark:bg-teal-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 hover:border-gray-300'">
                        <div class="flex items-center justify-between mb-1.5">
                            <p class="text-sm font-extrabold text-gray-900 dark:text-white">Menu Rate Final — Sab Same</p>
                            <span x-show="taxMode === 'inclusive'" x-cloak class="px-2 py-0.5 rounded-full bg-teal-600 text-white text-[10px] font-bold">ACTIVE</span>
                        </div>
                        <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-relaxed">Menu price hi final total hai — cash ho ya card, customer wohi ada karta hai jo menu par likha hai. Tax andar se nikalta hai.</p>
                        <p class="text-[11px] font-semibold text-gray-700 dark:text-gray-300 mt-2">Misal: Rs 590 menu price = cash <span class="font-extrabold">Rs 590</span>, card bhi <span class="font-extrabold">Rs 590</span></p>
                    </button>

                    {{-- Option 3: Card-save (menu inclusive at CASH rate; card = base + card tax) --}}
                    <button type="button" @click="setTaxMode('inclusive_card_save')"
                        class="text-left rounded-xl border-2 p-4 transition"
                        :class="taxMode === 'inclusive_card_save' ? 'border-teal-600 bg-teal-50 dark:bg-teal-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 hover:border-gray-300'">
                        <div class="flex items-center justify-between mb-1.5">
                            <p class="text-sm font-extrabold text-gray-900 dark:text-white">Menu Rate Final — Card Bachat</p>
                            <span x-show="taxMode === 'inclusive_card_save'" x-cloak class="px-2 py-0.5 rounded-full bg-teal-600 text-white text-[10px] font-bold">ACTIVE</span>
                        </div>
                        <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-relaxed">Menu price cash ke hisaab se final hai. Card/digital par tax kam lagta hai, is liye customer ko card par bachat milti hai — receipt par "Card Discount" line dikhti hai.</p>
                        <p class="text-[11px] font-semibold text-gray-700 dark:text-gray-300 mt-2">Misal: Rs 590 menu price = cash <span class="font-extrabold">Rs 590</span>, card sirf <span class="font-extrabold">Rs {{ number_format($csCard590) }}</span></p>
                    </button>
                </div>

                {{-- Live calculator --}}
                <div class="mt-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60 p-4">
                    <div class="flex items-center justify-between gap-3 flex-wrap mb-3">
                        <p class="text-[12px] font-bold text-gray-800 dark:text-gray-200 uppercase tracking-wide">Live Calculator</p>
                        <div class="flex items-center gap-2">
                            <label class="text-[11px] font-semibold text-gray-500 dark:text-gray-400">Menu price:</label>
                            <input type="number" min="0" step="1" x-model="calcPrice"
                                autocomplete="off" name="tax_calc_price_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                                class="w-28 px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-sm font-bold text-gray-900 dark:text-white focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <template x-for="m in [{label: 'Cash', rate: cashRate}, {label: 'Card / Digital', rate: cardRate}]" :key="m.label">
                            <div class="rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-3">
                                <p class="text-[11px] font-extrabold text-gray-900 dark:text-white mb-2" x-text="m.label + ' (' + m.rate + '% tax)'"></p>
                                <div class="space-y-1 text-[11px]">
                                    <div class="flex justify-between text-gray-500 dark:text-gray-400"><span>Base price</span><span x-text="fmt(calc(m.rate).base)"></span></div>
                                    <div class="flex justify-between text-gray-500 dark:text-gray-400"><span x-text="taxInc ? 'Tax (andar shamil)' : 'Tax (upar se)'"></span><span x-text="fmt(calc(m.rate).tax)"></span></div>
                                    <div class="flex justify-between font-extrabold text-gray-900 dark:text-white pt-1 border-t border-gray-100 dark:border-gray-800"><span>Customer deta hai</span><span x-text="fmt(calc(m.rate).total)"></span></div>
                                </div>
                            </div>
                        </template>
                    </div>
                    <p class="text-[10px] text-gray-400 dark:text-gray-500 mt-2" x-show="taxMode === 'inclusive'" x-cloak>Tax-Inclusive mein cash ho ya card — customer ka total wohi menu price rehta hai; sirf andar ka base/tax split badalta hai.</p>
                    <p class="text-[10px] text-gray-400 dark:text-gray-500 mt-2" x-show="taxMode === 'inclusive_card_save'" x-cloak>Card Bachat mode mein base price cash rate se nikalta hai — cash par customer menu price hi deta hai; card/digital par kam tax lagta hai to total menu price se kam banta hai (farq receipt par "Card Discount" ki soorat mein dikhta hai). Tax har soorat poora PRA ko jata hai.</p>
                </div>

                {{-- New-bills-only warning --}}
                <div class="mt-4 flex items-start gap-2.5 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 p-3">
                    <svg class="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <p class="text-[11px] text-amber-800 dark:text-amber-300 leading-relaxed"><span class="font-bold">Sirf naye bills par lagu hoga.</span> Purane bills, un ki receipts aur reports apne waqt ke mode ke mutabiq hi rahenge — kuch bhi peechhe ja kar tabdeel nahi hota. Tax har soorat poora PRA ko report hota hai.</p>
                </div>
            </div>
        </section>

        {{-- ═══════════ LOCAL BILLS & DAY-CLOSE ═══════════ --}}
        <section>
            <div class="px-1 mb-3">
                <h2 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wide">Local Bills &amp; Day-Close</h2>
                <p class="text-[12px] text-gray-500 dark:text-gray-400">Provisional / local bills ka day-close par kya ho — yahin se control karein</p>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">

                {{-- Local Billing day-close policy (F1) — save/delete per bill kind + spend persist --}}
                <div class="sm:col-span-2 rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-600 dark:bg-teal-900/30 dark:text-teal-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">Local Billing — day-close policy</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">Day-close par local bills ka kya ho — save (archive, wapas mil sakte hain) ya delete (hamesha ke liye khatam). PRA ko bheje gaye bills par kabhi asar nahi hota.</p>
                        </div>
                        <span class="shrink-0 text-[10px] font-semibold text-gray-400" x-show="savingLB" x-cloak>Saving…</span>
                    </div>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-3 flex-wrap">
                            <div class="min-w-0">
                                <p class="text-[13px] font-semibold text-gray-800 dark:text-gray-200">Final local bills (reporting OFF)</p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">Woh mukammal bills jo PRA ko report nahi hue</p>
                            </div>
                            <div class="inline-flex rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden shrink-0">
                                <button type="button" @click="lbFinal='save'; saveLB()" class="px-4 py-1.5 text-[12px] font-bold transition" :class="lbFinal==='save' ? 'bg-teal-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300'">Save</button>
                                <button type="button" @click="lbFinal='delete'; saveLB()" class="px-4 py-1.5 text-[12px] font-bold transition border-l border-gray-200 dark:border-gray-700" :class="lbFinal==='delete' ? 'bg-red-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300'">Delete</button>
                            </div>
                        </div>
                        <div class="flex items-center justify-between gap-3 flex-wrap">
                            <div class="min-w-0">
                                <p class="text-[13px] font-semibold text-gray-800 dark:text-gray-200">Provisional bills</p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">Cashier ke save kiye hue kachche (local) bills</p>
                            </div>
                            <div class="inline-flex rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden shrink-0">
                                <button type="button" @click="lbProv='save'; saveLB()" class="px-4 py-1.5 text-[12px] font-bold transition" :class="lbProv==='save' ? 'bg-teal-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300'">Save</button>
                                <button type="button" @click="lbProv='delete'; saveLB()" class="px-4 py-1.5 text-[12px] font-bold transition border-l border-gray-200 dark:border-gray-700" :class="lbProv==='delete' ? 'bg-red-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300'">Delete</button>
                            </div>
                        </div>
                        <div class="flex items-center justify-between gap-3 pt-3 border-t border-gray-100 dark:border-gray-800">
                            <div class="min-w-0">
                                <p class="text-[13px] font-semibold text-gray-800 dark:text-gray-200">Customer spent record</p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">Bill delete hone par bhi customer ki purchase history mehfooz rahe</p>
                            </div>
                            <button type="button" @click="lbPersist=!lbPersist; saveLB()"
                                class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="lbPersist ? 'bg-teal-600' : 'bg-gray-300 dark:bg-gray-600'">
                                <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="lbPersist && 'translate-x-6'"></span>
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Auto day-close at 6:00 AM the next morning --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-600 dark:bg-teal-900/30 dark:text-teal-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">Auto day-close — agli subah 6 baje</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">Din manually close na ho to system agle din subah 6:00 baje khud band kar dega. (Misaal: Somwaar ka din Mangal subah 6 baje band)</p>
                    </div>
                    <button type="button"
                        @click="autoDaycloseOn=!autoDaycloseOn; savingDayclose=true; fetch('/pos/settings/auto-dayclose-toggle', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:autoDaycloseOn})}).then(r=>r.json()).catch(()=>{}).finally(()=>{ savingDayclose=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="autoDaycloseOn ? 'bg-teal-600' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="autoDaycloseOn && 'translate-x-6'"></span>
                    </button>
                </div>
            </div>
        </section>

        {{-- ═══════════ CARD SECTIONS ═══════════ --}}
        @foreach($sections as $sec)
        <section>
            <div class="px-1 mb-3">
                <h2 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wide">{{ $sec['title'] }}</h2>
                <p class="text-[12px] text-gray-500 dark:text-gray-400">{{ $sec['desc'] }}</p>
            </div>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach($sec['items'] as $c)
                @php $tn = $tones[$c['tone']] ?? $tones['purple']; @endphp
                <a href="{{ $c['url'] }}" class="group flex items-center gap-3 p-4 rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 hover:border-purple-400 dark:hover:border-purple-600 hover:shadow-md transition">
                    <div class="w-10 h-10 rounded-xl {{ $tn['ic'] }} flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="{{ $c['icon'] }}"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <p class="text-sm font-bold text-gray-900 dark:text-white truncate">{{ $c['label'] }}</p>
                            @if(!empty($c['badge']))
                            <span class="shrink-0 text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded-full {{ $tn['bd'] }}">{{ $c['badge'] }}</span>
                            @endif
                        </div>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400 truncate">{{ $c['desc'] }}</p>
                    </div>
                    <svg class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-purple-500 group-hover:translate-x-0.5 transition shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
                @endforeach
            </div>
        </section>
        @endforeach

        <div class="pt-2 text-center">
            <a href="{{ route('pos.dashboard') }}" class="inline-flex items-center gap-1.5 text-[12px] font-semibold text-gray-500 dark:text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Back to Dashboard
            </a>
        </div>
    </div>
</x-pos-layout>
