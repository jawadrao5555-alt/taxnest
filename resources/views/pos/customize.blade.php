<x-pos-layout>
    @php
        $densityKey = in_array($company->pos_ui_density, ['simple', 'standard', 'premium'], true) ? $company->pos_ui_density : 'standard';
        $density = __('pos.density_' . $densityKey);
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
                'title' => __('pos.sec_setup_features'),
                'desc'  => __('pos.sec_setup_features_desc'),
                'items' => [
                    ['label' => __('pos.card_modules_features'), 'desc' => __('pos.card_modules_features_desc'), 'url' => route('pos.features'), 'tone' => 'purple', 'badge' => $density, 'icon' => 'M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4'],
                    ['label' => __('pos.business_profile'), 'desc' => __('pos.card_business_profile_desc'), 'url' => route('pos.business-profile'), 'tone' => 'purple', 'badge' => __('pos.badge_identity'), 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
                    ['label' => __('pos.card_receipt_display'), 'desc' => __('pos.card_receipt_display_desc'), 'url' => route('pos.receipt-settings'), 'tone' => 'purple', 'badge' => __('pos.badge_receipt'), 'icon' => 'M9 17v-2a2 2 0 012-2h2a2 2 0 012 2v2m-6 4h6a2 2 0 002-2V7a2 2 0 00-2-2H9a2 2 0 00-2 2v12a2 2 0 002 2z'],
                    ['label' => __('pos.printer_settings'), 'desc' => __('pos.card_printer_settings_desc'), 'url' => route('pos.printer-settings'), 'tone' => ($company->printerSettings()['silent_print_enabled'] ?? false) ? 'emerald' : 'purple', 'badge' => ($company->printerSettings()['silent_print_enabled'] ?? false) ? __('pos.badge_silent_on') : __('pos.badge_popup'), 'icon' => 'M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z'],
                    ['label' => __('pos.card_pra_compliance'), 'desc' => __('pos.card_pra_compliance_desc'), 'url' => route('pos.pra-settings'), 'tone' => $praOn ? 'emerald' : 'amber', 'badge' => $praOn ? __('pos.badge_pra_on') : __('pos.badge_pra_off'), 'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
                    // ZFC request (1 Aug 2026): Kitchen/KOT settings were buried under
                    // Restaurant — surface a direct card here for restaurant-mode shops.
                    ...(((bool) ($company->restaurant_mode ?? false)) ? [
                        ['label' => __('pos.card_kitchen_kot'), 'desc' => __('pos.card_kitchen_kot_desc'), 'url' => route('pos.restaurant.kitchen-settings'), 'tone' => 'purple', 'badge' => __('pos.badge_kot'), 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
                    ] : []),
                ],
            ],
            [
                'title' => __('pos.sec_operations'),
                'desc'  => __('pos.sec_operations_desc'),
                'items' => [
                    ['label' => __('pos.card_services'), 'desc' => __('pos.card_services_desc'), 'url' => route('pos.services'), 'tone' => 'purple', 'badge' => __('pos.badge_manage'), 'icon' => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10'],
                    // Plan gate (Aug 2026 matrix): Deals card sirf jab package mein ho
                    ...(\App\Services\PosFeatureService::planAllows(\App\Models\Company::find(app('currentCompanyId')), 'deals_enabled')
                        ? [['label' => __('pos.card_deals'), 'desc' => __('pos.card_deals_desc'), 'url' => route('pos.deals'), 'tone' => 'purple', 'badge' => __('pos.badge_manage'), 'icon' => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z']]
                        : []),
                    ['label' => __('pos.card_terminals'), 'desc' => __('pos.card_terminals_desc'), 'url' => route('pos.terminals'), 'tone' => 'purple', 'badge' => __('pos.badge_manage'), 'icon' => 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
                    // Multi-branch v1 (Task 1347): the only entry point into the
                    // POS panel's branch management page.
                    ['label' => __('pos.card_branches'), 'desc' => __('pos.card_branches_desc'), 'url' => route('pos.branches'), 'tone' => 'purple', 'badge' => __('pos.badge_manage'), 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
                    ['label' => __('pos.card_team'), 'desc' => __('pos.card_team_desc'), 'url' => route('pos.team'), 'tone' => 'purple', 'badge' => __('pos.badge_manage'), 'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
                ],
            ],
            [
                'title' => __('pos.sec_account_system'),
                'desc'  => __('pos.sec_account_system_desc'),
                'items' => [
                    ['label' => __('pos.card_billing_plan'), 'desc' => __('pos.card_billing_plan_desc'), 'url' => route('pos.billing'), 'tone' => 'purple', 'badge' => __('pos.badge_plan'), 'icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
                    ['label' => __('pos.card_pra_sync_agent'), 'desc' => __('pos.card_pra_sync_agent_desc'), 'url' => route('pos.agent'), 'tone' => $agentOn ? 'emerald' : 'blue', 'badge' => $agentOn ? __('pos.badge_agent_on') : __('pos.badge_direct'), 'icon' => 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
                    ['label' => __('pos.my_profile'), 'desc' => __('pos.card_my_profile_desc'), 'url' => route('pos.user-profile'), 'tone' => 'purple', 'badge' => __('pos.badge_account'), 'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
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
            'toast'      => [__('pos.style_toast'), '#f59e0b'],
            'lightspeed' => [__('pos.style_lightspeed'), '#6366f1'],
            'clover'     => [__('pos.style_clover'), '#16a34a'],
            'oscar'      => [__('pos.style_oscar'), '#0ea5e9'],
            'shopify'    => [__('pos.style_shopify'), '#334155'],
        ];
        $onFancy = array_key_exists($curStyle, $fancyStyles);
    @endphp

    <div x-data="{ currentTheme: '{{ $company->pos_theme ?? 'purple' }}', guidedOn: {{ ($company->pos_guided_flow_enabled ?? true) ? 'true' : 'false' }}, savingGuided: false, quickOn: {{ ($company->pos_quick_type_enabled ?? false) ? 'true' : 'false' }}, savingQuick: false, cashRecvOn: {{ ($company->pos_cash_received_enabled ?? false) ? 'true' : 'false' }}, savingCashRecv: false, invOn: {{ $invOn ? 'true' : 'false' }}, savingInv: false, restockOn: {{ ($company->pos_restock_on_void ?? true) ? 'true' : 'false' }}, savingRestock: false, autoDaycloseOn: {{ ($company->pos_auto_dayclose_24h ?? false) ? 'true' : 'false' }}, savingDayclose: false, cashierDcOn: {{ ($company->pos_cashier_dayclose ?? false) ? 'true' : 'false' }}, savingCdc: false, cashierOcOn: {{ ($company->pos_cashier_order_cancel ?? false) ? 'true' : 'false' }}, savingCoc: false, kdsAutoOn: {{ ($company->pos_kds_auto_print ?? false) ? 'true' : 'false' }}, savingKdsAuto: false, wCancelOn: {{ ($company->pos_waiter_cancel_enabled ?? false) ? 'true' : 'false' }}, savingWCancel: false, wTakeawayOn: {{ ($company->pos_waiter_takeaway_enabled ?? true) ? 'true' : 'false' }}, savingWTakeaway: false, saveWaiterPerm(perm) { const isCancel = perm === 'cancel'; const on = isCancel ? this.wCancelOn : this.wTakeawayOn; const saving = isCancel ? 'savingWCancel' : 'savingWTakeaway'; this[saving] = true; fetch('/pos/settings/waiter-permission', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({permission:perm, enabled:on})}).then(r=>r.json()).then(d=>{ if (!d || d.success !== true) { if (isCancel) { this.wCancelOn = !on; } else { this.wTakeawayOn = !on; } alert((d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}); } }).catch(()=>{ if (isCancel) { this.wCancelOn = !on; } else { this.wTakeawayOn = !on; } alert({{ Js::from(__('pos.setting_save_failed')) }}); }).finally(()=>{ this[saving] = false; }); }, lbFinal: '{{ in_array($company->pos_dayclose_final_local_action ?? 'save', ['save','delete'], true) ? ($company->pos_dayclose_final_local_action ?? 'save') : 'save' }}', lbProv: '{{ in_array($company->pos_dayclose_provisional_action ?? 'save', ['save','delete','carry','finalize'], true) ? ($company->pos_dayclose_provisional_action ?? 'save') : 'save' }}', lbPersist: {{ ($company->pos_customer_spend_persist ?? true) ? 'true' : 'false' }}, savingLB: false, rcSecs: {{ (int) ($company->pos_receipt_autoclose_seconds ?? 10) }}, savingRc: false, setRc(s) { if (this.rcSecs === s || this.savingRc) return; const prev = this.rcSecs; this.rcSecs = s; this.savingRc = true; fetch('/pos/settings/receipt-autoclose', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({seconds:s})}).then(r=>r.json()).then(d=>{ if (!d || d.success !== true) { this.rcSecs = prev; alert((d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}); } }).catch(()=>{ this.rcSecs = prev; alert({{ Js::from(__('pos.setting_save_failed')) }}); }).finally(()=>{ this.savingRc = false; }); }, taxMode: '{{ $taxMode }}', savingTaxInc: false, calcPrice: 590, cashRate: {{ (float) $cashRate }}, cardRate: {{ (float) $cardRate }}, get taxInc() { return this.taxMode !== 'exclusive'; }, calc(rate) { const p = parseFloat(this.calcPrice) || 0; if (this.taxMode === 'inclusive_card_save') { const base = p * 100 / (100 + this.cashRate); const tax = base * rate / 100; return { base: base, tax: tax, total: Math.round(base + tax) }; } if (this.taxInc) { const tax = p * rate / (100 + rate); return { base: p - tax, tax: tax, total: Math.round(p) }; } const tax = p * rate / 100; return { base: p, tax: tax, total: Math.round(p + tax) }; }, fmt(n) { return 'Rs ' + (Math.round(n * 100) / 100).toLocaleString(); }, setTaxMode(mode) { if (this.taxMode === mode || this.savingTaxInc) return; const prev = this.taxMode; this.taxMode = mode; this.savingTaxInc = true; fetch('/pos/settings/tax-pricing-mode', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({mode:mode})}).then(r=>r.json()).then(d=>{ if (!d || d.success !== true) { this.taxMode = prev; alert((d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}); } }).catch(()=>{ this.taxMode = prev; alert({{ Js::from(__('pos.setting_save_failed')) }}); }).finally(()=>{ this.savingTaxInc = false; }); }, saveLB() { this.savingLB = true; fetch('/pos/settings/local-billing', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({final_action:this.lbFinal, provisional_action:this.lbProv, spend_persist:this.lbPersist})}).then(r=>r.json()).catch(()=>{}).finally(()=>{ this.savingLB=false; }) } }"
         class="max-w-5xl mx-auto p-4 sm:p-6 space-y-6">

        {{-- ═══════════ HERO ═══════════ --}}
        <div class="rounded-2xl bg-purple-600 p-6 sm:p-8 text-white shadow-xl relative overflow-hidden">
            <div class="relative">
                <div class="flex items-center gap-2 mb-2">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white/20 backdrop-blur text-[10px] font-bold uppercase tracking-wider">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        {{ __('pos.pos_control_center') }}
                    </span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold mb-1.5">{{ __('pos.customize_pos') }}</h1>
                <p class="text-sm sm:text-base text-white/85 max-w-2xl">{{ __('pos.customize_hero_sub') }}</p>
            </div>
        </div>

        {{-- ═══════════ POS STYLE — Full vs Saaf (owner, 22 Jul 2026) ═══════════ --}}
        <section id="style" x-data="{ curStyle: '{{ $curStyle }}', savingStyle: false, moreStyles: {{ $onFancy ? 'true' : 'false' }},
            setStyle(s) {
                if (this.curStyle === s || this.savingStyle) return;
                this.savingStyle = true;
                fetch('{{ route('pos.settings.dashboard-style') }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({style:s})})
                    .then(r=>r.json())
                    .then(d=>{ if (d && d.success) { this.curStyle = s; window.location.reload(); } else { this.savingStyle = false; alert((d && d.message) || {{ Js::from(__('pos.style_save_failed')) }}); } })
                    .catch(()=>{ this.savingStyle = false; alert({{ Js::from(__('pos.style_save_failed_net')) }}); });
            } }">
            <div class="px-1 mb-3 flex items-center justify-between gap-3 flex-wrap">
                <div>
                    <h2 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wide">{{ __('pos.pos_style_heading') }}</h2>
                    <p class="text-[12px] text-gray-500 dark:text-gray-400">{{ __('pos.pos_style_sub') }}</p>
                </div>
                <span class="shrink-0 text-[10px] font-semibold text-gray-400" x-show="savingStyle" x-cloak>{{ __('pos.changing_ellipsis') }}</span>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">

                {{-- FULL (default) --}}
                <button type="button" @click="setStyle('default')"
                    class="text-left rounded-2xl border-2 p-4 transition bg-white dark:bg-gray-900"
                    :class="curStyle === 'default' ? 'border-teal-600' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                    <div class="flex items-center justify-between mb-1.5">
                        <p class="text-sm font-extrabold text-gray-900 dark:text-white">{{ __('pos.style_full_title') }}</p>
                        <span x-show="curStyle === 'default'" x-cloak class="px-2 py-0.5 rounded-full bg-teal-600 text-white text-[10px] font-bold">{{ __('pos.active_badge') }}</span>
                    </div>
                    <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-relaxed mb-3">{{ __('pos.style_full_desc') }}</p>
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
                        <p class="text-sm font-extrabold text-gray-900 dark:text-white">{{ __('pos.style_saaf_title') }}</p>
                        <span x-show="curStyle === 'saaf'" x-cloak class="px-2 py-0.5 rounded-full bg-teal-600 text-white text-[10px] font-bold">{{ __('pos.active_badge') }}</span>
                    </div>
                    <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-relaxed mb-3">{{ __('pos.style_saaf_desc') }}</p>
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
                    {{ __('pos.more_styles_link') }}
                </button>
                <div x-show="moreStyles" x-cloak class="mt-2 flex flex-wrap gap-2">
                    @foreach($fancyStyles as $fid => [$fname, $fcolor])
                    <button type="button" @click="setStyle('{{ $fid }}')"
                        class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border-2 text-[12px] font-bold transition bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300"
                        :class="curStyle === '{{ $fid }}' ? 'border-teal-600' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'">
                        <span class="w-3 h-3 rounded-full shrink-0" style="background: {{ $fcolor }}"></span>
                        {{ $fname }}
                        <span x-show="curStyle === '{{ $fid }}'" x-cloak class="px-1.5 py-0.5 rounded-full bg-teal-600 text-white text-[9px] font-bold">{{ __('pos.active_badge') }}</span>
                    </button>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ═══════════ APPEARANCE & EXPERIENCE ═══════════ --}}
        <section>
            <div class="px-1 mb-3">
                <h2 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wide">{{ __('pos.sec_appearance') }}</h2>
                <p class="text-[12px] text-gray-500 dark:text-gray-400">{{ __('pos.sec_appearance_sub') }}</p>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">

                {{-- Theme picker --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.pos_theme') }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400" x-text="{{ Js::from(__('pos.active_colon') . ' ') }} + currentTheme.charAt(0).toUpperCase() + currentTheme.slice(1)"></p>
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
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.guided_keyboard_billing') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.guided_keyboard_billing_sub') }}</p>
                    </div>
                    <button type="button"
                        @click="guidedOn=!guidedOn; savingGuided=true; fetch('/pos/settings/guided-flow', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:guidedOn})}).then(r=>r.json()).catch(()=>{}).finally(()=>{ savingGuided=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="guidedOn ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="guidedOn && 'translate-x-6'"></span>
                    </button>
                </div>

                {{-- Caller ID (Task 1039): Android companion app + sale-screen popup.
                     Card-local Alpine state; toggle POST follows the guided-flow
                     pattern. Status lines read the company columns directly
                     (hasColumn-guarded for prod schema drift). Download button
                     appears only once caller_app_latest_version is set — the
                     public release gate flips AFTER the owner phone-test. --}}
                @php
                    $tnCallerReady = \Illuminate\Support\Facades\Schema::hasColumn('companies', 'caller_id_enabled');
                    $tnCallerOn = $tnCallerReady && ($company->caller_id_enabled ?? false);
                    $tnCallerUser = ($tnCallerReady && ($company->caller_app_user_id ?? null)) ? \App\Models\User::find($company->caller_app_user_id) : null;
                    $tnCallerSeen = ($tnCallerReady && ($company->caller_app_last_seen_at ?? null)) ? \Carbon\Carbon::parse($company->caller_app_last_seen_at) : null;
                    $tnCallerLastEvent = ($tnCallerReady && \Illuminate\Support\Facades\Schema::hasTable('pos_caller_events'))
                        ? \Illuminate\Support\Facades\DB::table('pos_caller_events')->where('company_id', $company->id)->orderByDesc('id')->value('created_at')
                        : null;
                    // NOTE: Blade {{-- --}} comments are NOT stripped inside @php
                    // blocks — one here compiled to invalid PHP and 500'd the page
                    // (white-screen preflight caught it). Use PHP comments only.
                    // Release gate: SystemSetting AND the hosted file must both exist
                    // (APKs are scp'd to live public/downloads, never committed —
                    // repo is public; same pattern as rider/waiter apps) so the
                    // download button can never 404.
                    $tnCallerApkLive = trim((string) \App\Models\SystemSetting::get('caller_app_latest_version', '')) !== ''
                        && is_file(public_path('downloads/taxnest-caller.apk'));
                    // Task 1345: default download ab "clean" build hai (sirf SIM
                    // calls, Play Protect ki blocked chaar permissions se paak —
                    // is liye bina rukawat install hoti hai). WhatsApp wali
                    // ("plus") build ka apna gate hai aur woh apne alag hisse
                    // mein Play Protect band/chaalu karne ke qadam ke sath dikhti
                    // hai — default button jhoota WhatsApp ka wada na kare.
                    $tnCallerPlusApkLive = trim((string) \App\Models\SystemSetting::get('caller_app_plus_latest_version', '')) !== ''
                        && is_file(public_path('downloads/taxnest-caller-plus.apk'));
                    // Unlimited gate (owner, 17 Aug 2026): Caller ID is plan-locked.
                    $tnCallerPlanAllowed = \App\Services\PosFeatureService::planAllows($company, 'caller_id_enabled');
                    // v2 (Task 1101): multi-device rows — legacy companies-row phone
                    // shows alongside as a 'legacy' pseudo-row so an old pairing stays
                    // visible/revocable. Online = contact within the controller window.
                    $tnCallerOffCutoff = now()->subMinutes(\App\Http\Controllers\PosCallerIdController::OFFLINE_AFTER_MINUTES);
                    $tnCallerDevices = [];
                    if ($tnCallerReady && \Illuminate\Support\Facades\Schema::hasTable('pos_caller_devices')) {
                        foreach (\Illuminate\Support\Facades\DB::table('pos_caller_devices')->where('company_id', $company->id)->orderByDesc('id')->get() as $tnCdRow) {
                            $tnCdSeen = $tnCdRow->last_seen_at ? \Carbon\Carbon::parse($tnCdRow->last_seen_at) : null;
                            $tnCallerDevices[] = [
                                'id' => (int) $tnCdRow->id,
                                'user' => optional(\App\Models\User::find($tnCdRow->user_id))->name ?? '—',
                                'device' => (string) ($tnCdRow->device ?? ''),
                                'seen' => $tnCdSeen ? $tnCdSeen->diffForHumans() : null,
                                'online' => $tnCdSeen ? $tnCdSeen->gt($tnCallerOffCutoff) : false,
                            ];
                        }
                    }
                    if ($tnCallerUser) {
                        $tnCallerDevices[] = [
                            'id' => 'legacy',
                            'user' => $tnCallerUser->name,
                            'device' => (string) ($company->caller_app_device ?? ''),
                            'seen' => $tnCallerSeen ? $tnCallerSeen->diffForHumans() : null,
                            'online' => $tnCallerSeen ? $tnCallerSeen->gt($tnCallerOffCutoff) : false,
                        ];
                    }
                @endphp
                @if($tnCallerReady)
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm" x-data="{ callerOn: {{ $tnCallerOn ? 'true' : 'false' }} }">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-sky-100 text-sky-600 dark:bg-sky-900/30 dark:text-sky-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.caller_id_title') }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.caller_id_sub') }}</p>
                        </div>
                        @if($tnCallerPlanAllowed)
                        <button type="button"
                            @click="callerOn=!callerOn; fetch('/pos/settings/caller-id', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:callerOn})}).catch(()=>{})"
                            class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="callerOn ? 'bg-sky-500' : 'bg-gray-300 dark:bg-gray-600'">
                            <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="callerOn && 'translate-x-6'"></span>
                        </button>
                        @else
                        <span class="shrink-0 inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">🔒 {{ __('pos.auth_unlimited') }}</span>
                        @endif
                    </div>
                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800 space-y-1">
                        @unless($tnCallerPlanAllowed)
                            <p class="text-[11px] font-bold text-amber-600 dark:text-amber-400">{{ __('pos.caller_id_plan_locked') }}</p>
                            <a href="{{ route('pos.billing') }}" class="inline-flex items-center gap-1 text-[11px] font-bold text-sky-600 dark:text-sky-400 hover:underline">
                                <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                                {{ __('pos.upgrade_plan_btn') }}
                            </a>
                        @endunless
                        @if(count($tnCallerDevices))
                            @foreach($tnCallerDevices as $tnCd)
                                <div class="flex items-center gap-2 text-[11px] text-gray-600 dark:text-gray-300" x-data="{ revoked: false }" x-show="!revoked">
                                    <span class="inline-flex items-center gap-1 shrink-0 px-1.5 py-0.5 rounded-full text-[9px] font-bold {{ $tnCd['online'] ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' }}">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $tnCd['online'] ? 'bg-emerald-500' : 'bg-gray-400' }}"></span>
                                        {{ $tnCd['online'] ? __('pos.caller_dev_online') : __('pos.caller_dev_offline') }}
                                    </span>
                                    <span class="truncate min-w-0">
                                        <span class="font-bold">{{ $tnCd['user'] }}</span>{{ $tnCd['device'] !== '' ? ' · ' . $tnCd['device'] : '' }}
                                        @if($tnCd['seen']) · {{ __('pos.caller_id_last_seen') }}: {{ $tnCd['seen'] }} @endif
                                    </span>
                                    <button type="button"
                                        @click="if (confirm('{{ __('pos.caller_dev_revoke_confirm') }}')) { revoked = true; fetch('{{ route('pos.settings.caller-devices.revoke') }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({device_id: '{{ $tnCd['id'] }}'})}).catch(()=>{}); }"
                                        class="ml-auto shrink-0 px-2 py-0.5 rounded-lg text-[10px] font-bold text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800 hover:bg-red-50 dark:hover:bg-red-900/20 transition">{{ __('pos.caller_dev_revoke') }}</button>
                                </div>
                            @endforeach
                        @else
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.caller_id_no_device') }}</p>
                        @endif
                        @if($tnCallerLastEvent)
                            <p class="text-[11px] text-gray-600 dark:text-gray-300"><span class="font-bold">{{ __('pos.caller_id_last_event') }}:</span> {{ \Carbon\Carbon::parse($tnCallerLastEvent)->diffForHumans() }}</p>
                        @endif
                        @if($tnCallerApkLive && $tnCallerPlanAllowed)
                            <div class="pt-2">
                                <a href="{{ url('downloads/taxnest-caller.apk') }}" class="inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-white text-xs font-bold transition">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a2 2 0 002 2h12a2 2 0 002-2v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                    {{ __('pos.caller_id_download') }}
                                </a>
                                <p class="text-[10px] text-gray-400 mt-1">{{ __('pos.caller_id_download_hint') }}</p>
                            </div>
                            @if($tnCallerPlusApkLive)
                                {{-- WhatsApp wali build: alag, kholne par hi qadam dikhein
                                     (Play Protect ki wajah se yeh install ek extra qadam
                                     mangta hai — default button ko saada rakhna hai). --}}
                                <div class="pt-2" x-data="{ plusOpen: false }">
                                    <button type="button" @click="plusOpen = !plusOpen"
                                        class="inline-flex items-center gap-1.5 text-[11px] font-bold text-sky-600 dark:text-sky-400 hover:underline">
                                        <svg class="w-3.5 h-3.5 shrink-0 transition-transform" :class="plusOpen && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                        {{ __('pos.caller_id_plus_title') }}
                                    </button>
                                    <div x-show="plusOpen" x-cloak class="mt-2 rounded-xl bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-800 p-3">
                                        <p class="text-[11px] text-gray-700 dark:text-gray-200 leading-relaxed">{{ __('pos.caller_id_plus_intro') }}</p>
                                        <ol class="mt-2 space-y-1 text-[11px] text-gray-600 dark:text-gray-300 list-decimal pl-4 leading-relaxed">
                                            <li>{{ __('pos.caller_id_plus_step1') }}</li>
                                            <li>{{ __('pos.caller_id_plus_step2') }}</li>
                                            <li>{{ __('pos.caller_id_plus_step3') }}</li>
                                            <li>{{ __('pos.caller_id_plus_step4') }}</li>
                                        </ol>
                                        <a href="{{ url('downloads/taxnest-caller-plus.apk') }}" class="mt-3 inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-white text-xs font-bold transition">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a2 2 0 002 2h12a2 2 0 002-2v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                            {{ __('pos.caller_id_plus_download') }}
                                        </a>
                                        <p class="text-[10px] text-amber-700 dark:text-amber-400 mt-2 font-bold">{{ __('pos.caller_id_plus_warn') }}</p>
                                    </div>
                                </div>
                            @endif
                        @endif

                    </div>
                </div>
                @endif

                {{-- Company default language (owner, 30 Jul 2026) --}}
                {{-- Mobile fix (Task 540): card wraps on phones so the 3 language buttons
                     drop below the text instead of pushing off-screen; desktop unchanged. --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex flex-wrap sm:flex-nowrap items-center gap-3">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <div class="w-10 h-10 rounded-xl bg-sky-100 text-sky-600 dark:bg-sky-900/30 dark:text-sky-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.company_default_language') }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.company_default_language_hint') }}</p>
                        </div>
                    </div>
                    @php $tnCoLang = \App\Support\PosLocale::normalize($company->default_language ?? null); @endphp
                    <div class="flex flex-wrap gap-2 w-full sm:w-auto sm:shrink-0">
                        <form method="POST" action="{{ route('pos.settings.default-language') }}">
                            @csrf
                            <input type="hidden" name="default_language" value="rur">
                            <button type="submit" class="px-3 py-1.5 rounded-lg text-[11px] font-bold border transition {{ $tnCoLang === 'rur' ? 'bg-sky-600 text-white border-sky-600' : 'text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800' }}">{{ __('pos.language_roman_urdu') }}</button>
                        </form>
                        <form method="POST" action="{{ route('pos.settings.default-language') }}">
                            @csrf
                            <input type="hidden" name="default_language" value="en">
                            <button type="submit" class="px-3 py-1.5 rounded-lg text-[11px] font-bold border transition {{ $tnCoLang === 'en' ? 'bg-sky-600 text-white border-sky-600' : 'text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800' }}">{{ __('pos.language_english') }}</button>
                        </form>
                        <form method="POST" action="{{ route('pos.settings.default-language') }}">
                            @csrf
                            <input type="hidden" name="default_language" value="ur">
                            <button type="submit" class="px-3 py-1.5 rounded-lg text-[11px] font-bold border transition {{ $tnCoLang === 'ur' ? 'bg-sky-600 text-white border-sky-600' : 'text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800' }}">{{ __('pos.language_urdu_script') }}</button>
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
                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.receipt_popup_autoclose') }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.receipt_popup_autoclose_sub') }}</p>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ([0 => __('pos.never_word'), 5 => __('pos.n_sec', ['n' => 5]), 10 => __('pos.n_sec', ['n' => 10]), 15 => __('pos.n_sec', ['n' => 15]), 30 => __('pos.n_sec', ['n' => 30])] as $s => $label)
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
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.quick_type_mode') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.quick_type_mode_sub') }}</p>
                    </div>
                    <button type="button"
                        @click="quickOn=!quickOn; savingQuick=true; fetch('/pos/settings/quick-type', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:quickOn})}).then(r=>r.json()).catch(()=>{}).finally(()=>{ savingQuick=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="quickOn ? 'bg-sky-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="quickOn && 'translate-x-6'"></span>
                    </button>
                </div>

                {{-- Cash Received / Wapsi box — per-company OPT-IN (default OFF, owner Aug 2026) --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.cash_received_toggle') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.cash_received_toggle_sub') }}</p>
                    </div>
                    <button type="button"
                        @click="cashRecvOn=!cashRecvOn; savingCashRecv=true; fetch('/pos/settings/cash-received-toggle', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:cashRecvOn})}).then(r=>r.json()).then(d=>{ if (!d || d.success !== true) { cashRecvOn=!cashRecvOn; alert((d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}); } }).catch(()=>{ cashRecvOn=!cashRecvOn; alert({{ Js::from(__('pos.setting_save_failed')) }}); }).finally(()=>{ savingCashRecv=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="cashRecvOn ? 'bg-green-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="cashRecvOn && 'translate-x-6'"></span>
                    </button>
                </div>

                {{-- WhatsApp Bill (Task 1036, owner voice note 17 Aug 2026): receipt popup ka
                     WhatsApp button (default ON) + optional auto-open mode (default OFF).
                     Pro+ plan gate (owner, 17 Aug 2026): locked below Pro. --}}
                @php $tnWaPlanAllowed = \App\Services\PosFeatureService::planAllows($company, 'whatsapp_enabled'); @endphp
                <div x-data="{ waOn: {{ ($tnWaPlanAllowed && ($company->pos_whatsapp_bill_enabled ?? true)) ? 'true' : 'false' }}, waAutoOn: {{ ($company->pos_whatsapp_bill_auto_open ?? false) ? 'true' : 'false' }}, savingWa: false,
                        saveWa(payload, revert) { this.savingWa = true; fetch('{{ route('pos.settings.whatsapp-bill-toggle') }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify(payload)}).then(r=>r.json()).then(d=>{ if (!d || d.success !== true) { revert(); alert((d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}); } }).catch(()=>{ revert(); alert({{ Js::from(__('pos.setting_save_failed')) }}); }).finally(()=>{ this.savingWa = false; }); } }"
                     class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.wa_bill_toggle') }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.wa_bill_toggle_sub') }}</p>
                        </div>
                        @if($tnWaPlanAllowed)
                        <button type="button"
                            @click="waOn=!waOn; saveWa({enabled: waOn}, () => { waOn=!waOn; })"
                            class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="waOn ? 'bg-green-500' : 'bg-gray-300 dark:bg-gray-600'">
                            <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="waOn && 'translate-x-6'"></span>
                        </button>
                        @else
                        <span class="shrink-0 inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">🔒 Pro+</span>
                        @endif
                    </div>
                    @unless($tnWaPlanAllowed)
                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800 space-y-1">
                        <p class="text-[11px] font-bold text-amber-600 dark:text-amber-400">{{ __('pos.wa_bill_plan_locked') }}</p>
                        <a href="{{ route('pos.billing') }}" class="inline-flex items-center gap-1 text-[11px] font-bold text-sky-600 dark:text-sky-400 hover:underline">
                            <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                            {{ __('pos.upgrade_plan_btn') }}
                        </a>
                    </div>
                    @endunless
                    {{-- Auto-open sub-option — only meaningful while the feature is ON --}}
                    <div x-show="waOn" x-collapse.duration.150ms class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800 flex items-center gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-[12px] font-bold text-gray-800 dark:text-gray-200">{{ __('pos.wa_bill_auto_open') }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.wa_bill_auto_open_sub') }}</p>
                        </div>
                        <button type="button"
                            @click="waAutoOn=!waAutoOn; saveWa({auto_open: waAutoOn}, () => { waAutoOn=!waAutoOn; })"
                            class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="waAutoOn ? 'bg-green-500' : 'bg-gray-300 dark:bg-gray-600'">
                            <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="waAutoOn && 'translate-x-6'"></span>
                        </button>
                    </div>
                </div>

                {{-- Inventory tracking (moved here from Business Profile) --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.inventory_tracking') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.inventory_tracking_sub') }}</p>
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
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.restock_on_void') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.restock_on_void_sub') }}</p>
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
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.kds_auto_print_kot') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.kds_auto_print_kot_sub') }}</p>
                    </div>
                    <button type="button"
                        @click="kdsAutoOn=!kdsAutoOn; savingKdsAuto=true; fetch('/pos/settings/kds-auto-print', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:kdsAutoOn})}).then(r=>r.json()).catch(()=>{}).finally(()=>{ savingKdsAuto=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="kdsAutoOn ? 'bg-teal-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="kdsAutoOn && 'translate-x-6'"></span>
                    </button>
                </div>

                {{-- Task 527 (owner, 12 Aug 2026): waiter self-cancel permission — default OFF --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 18L18 6M6 6l12 12"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.waiter_cancel_toggle') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.waiter_cancel_toggle_sub') }}</p>
                    </div>
                    <button type="button"
                        @click="wCancelOn=!wCancelOn; saveWaiterPerm('cancel')"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="wCancelOn ? 'bg-red-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="wCancelOn && 'translate-x-6'"></span>
                    </button>
                </div>

                {{-- Task 527: waiter takeaway punch permission — default ON --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.waiter_takeaway_toggle') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.waiter_takeaway_toggle_sub') }}</p>
                    </div>
                    <button type="button"
                        @click="wTakeawayOn=!wTakeawayOn; saveWaiterPerm('takeaway')"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="wTakeawayOn ? 'bg-amber-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="wTakeawayOn && 'translate-x-6'"></span>
                    </button>
                </div>
                @endif
            </div>
        </section>

        {{-- ═══════════ TAX PRICING MODE (Menu-Rate-Final) ═══════════ --}}
        <section>
            <div class="px-1 mb-3">
                <h2 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wide">{{ __('pos.sec_tax_pricing_mode') }}</h2>
                <p class="text-[12px] text-gray-500 dark:text-gray-400">{{ __('pos.sec_tax_pricing_mode_sub') }}</p>
            </div>
            <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-600 dark:bg-teal-900/30 dark:text-teal-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21l-2-1-2 1-2-1-2 1-2-1-2 1V5a2 2 0 012-2h10a2 2 0 012 2v16z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.how_tax_applies_q') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.how_tax_applies_sub') }}</p>
                    </div>
                    <span class="shrink-0 text-[10px] font-semibold text-gray-400" x-show="savingTaxInc" x-cloak>{{ __('pos.saving_ellipsis') }}</span>
                </div>

                <div class="grid sm:grid-cols-3 gap-3">
                    {{-- Option 1: Standard (tax add-on) --}}
                    <button type="button" @click="setTaxMode('exclusive')"
                        class="text-left rounded-xl border-2 p-4 transition"
                        :class="taxMode === 'exclusive' ? 'border-teal-600 bg-teal-50 dark:bg-teal-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 hover:border-gray-300'">
                        <div class="flex items-center justify-between mb-1.5">
                            <p class="text-sm font-extrabold text-gray-900 dark:text-white">{{ __('pos.tax_mode_exclusive_title') }}</p>
                            <span x-show="taxMode === 'exclusive'" x-cloak class="px-2 py-0.5 rounded-full bg-teal-600 text-white text-[10px] font-bold">{{ __('pos.active_badge') }}</span>
                        </div>
                        <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-relaxed">{{ __('pos.tax_mode_exclusive_desc') }}</p>
                        <p class="text-[11px] font-semibold text-gray-700 dark:text-gray-300 mt-2">{!! __('pos.tax_mode_exclusive_example_html', ['rate' => e(rtrim(rtrim(number_format($cashRate, 2), '0'), '.')), 'total' => '<span class="font-extrabold">Rs ' . e(number_format(round(500 + 500 * $cashRate / 100))) . '</span>']) !!}</p>
                    </button>

                    {{-- Option 2: Tax-Inclusive (Menu-Rate-Final — same total on cash & card) --}}
                    <button type="button" @click="setTaxMode('inclusive')"
                        class="text-left rounded-xl border-2 p-4 transition"
                        :class="taxMode === 'inclusive' ? 'border-teal-600 bg-teal-50 dark:bg-teal-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 hover:border-gray-300'">
                        <div class="flex items-center justify-between mb-1.5">
                            <p class="text-sm font-extrabold text-gray-900 dark:text-white">{{ __('pos.tax_mode_inclusive_title') }}</p>
                            <span x-show="taxMode === 'inclusive'" x-cloak class="px-2 py-0.5 rounded-full bg-teal-600 text-white text-[10px] font-bold">{{ __('pos.active_badge') }}</span>
                        </div>
                        <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-relaxed">{{ __('pos.tax_mode_inclusive_desc') }}</p>
                        <p class="text-[11px] font-semibold text-gray-700 dark:text-gray-300 mt-2">{!! __('pos.tax_mode_inclusive_example_html', ['cash' => '<span class="font-extrabold">Rs 590</span>', 'card' => '<span class="font-extrabold">Rs 590</span>']) !!}</p>
                    </button>

                    {{-- Option 3: Card-save (menu inclusive at CASH rate; card = base + card tax) --}}
                    <button type="button" @click="setTaxMode('inclusive_card_save')"
                        class="text-left rounded-xl border-2 p-4 transition"
                        :class="taxMode === 'inclusive_card_save' ? 'border-teal-600 bg-teal-50 dark:bg-teal-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 hover:border-gray-300'">
                        <div class="flex items-center justify-between mb-1.5">
                            <p class="text-sm font-extrabold text-gray-900 dark:text-white">{{ __('pos.tax_mode_card_save_title') }}</p>
                            <span x-show="taxMode === 'inclusive_card_save'" x-cloak class="px-2 py-0.5 rounded-full bg-teal-600 text-white text-[10px] font-bold">{{ __('pos.active_badge') }}</span>
                        </div>
                        <p class="text-[11px] text-gray-600 dark:text-gray-400 leading-relaxed">{{ __('pos.tax_mode_card_save_desc') }}</p>
                        <p class="text-[11px] font-semibold text-gray-700 dark:text-gray-300 mt-2">{!! __('pos.tax_mode_card_save_example_html', ['cash' => '<span class="font-extrabold">Rs 590</span>', 'card' => '<span class="font-extrabold">Rs ' . e(number_format($csCard590)) . '</span>']) !!}</p>
                    </button>
                </div>

                {{-- Live calculator --}}
                <div class="mt-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60 p-4">
                    <div class="flex items-center justify-between gap-3 flex-wrap mb-3">
                        <p class="text-[12px] font-bold text-gray-800 dark:text-gray-200 uppercase tracking-wide">{{ __('pos.live_calculator') }}</p>
                        <div class="flex items-center gap-2">
                            <label class="text-[11px] font-semibold text-gray-500 dark:text-gray-400">{{ __('pos.menu_price_label') }}</label>
                            <input type="number" min="0" step="1" x-model="calcPrice"
                                autocomplete="off" name="tax_calc_price_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                                class="w-28 px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-sm font-bold text-gray-900 dark:text-white focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <template x-for="m in [{label: {{ Js::from(__('pos.cash_word')) }}, rate: cashRate}, {label: {{ Js::from(__('pos.card_digital_word')) }}, rate: cardRate}]" :key="m.label">
                            <div class="rounded-lg bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-3">
                                <p class="text-[11px] font-extrabold text-gray-900 dark:text-white mb-2" x-text="m.label + ' (' + m.rate + {{ Js::from('% ' . __('pos.tax_word') . ')') }}"></p>
                                <div class="space-y-1 text-[11px]">
                                    <div class="flex justify-between text-gray-500 dark:text-gray-400"><span>{{ __('pos.base_price') }}</span><span x-text="fmt(calc(m.rate).base)"></span></div>
                                    <div class="flex justify-between text-gray-500 dark:text-gray-400"><span x-text="taxInc ? {{ Js::from(__('pos.tax_included_paren')) }} : {{ Js::from(__('pos.tax_added_paren')) }}"></span><span x-text="fmt(calc(m.rate).tax)"></span></div>
                                    <div class="flex justify-between font-extrabold text-gray-900 dark:text-white pt-1 border-t border-gray-100 dark:border-gray-800"><span>{{ __('pos.customer_pays') }}</span><span x-text="fmt(calc(m.rate).total)"></span></div>
                                </div>
                            </div>
                        </template>
                    </div>
                    <p class="text-[10px] text-gray-400 dark:text-gray-500 mt-2" x-show="taxMode === 'inclusive'" x-cloak>{{ __('pos.tax_inclusive_note') }}</p>
                    <p class="text-[10px] text-gray-400 dark:text-gray-500 mt-2" x-show="taxMode === 'inclusive_card_save'" x-cloak>{{ __('pos.tax_card_save_note') }}</p>
                </div>

                {{-- New-bills-only warning --}}
                <div class="mt-4 flex items-start gap-2.5 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 p-3">
                    <svg class="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <p class="text-[11px] text-amber-800 dark:text-amber-300 leading-relaxed">{!! __('pos.new_bills_only_warning_html', ['bold' => '<span class="font-bold">' . e(__('pos.new_bills_only_bold')) . '</span>']) !!}</p>
                </div>
            </div>
        </section>

        {{-- ═══════════ LOCAL BILLS & DAY-CLOSE ═══════════ --}}
        <section>
            <div class="px-1 mb-3">
                <h2 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wide">{{ __('pos.sec_local_bills_dayclose') }}</h2>
                <p class="text-[12px] text-gray-500 dark:text-gray-400">{{ __('pos.sec_local_bills_dayclose_sub') }}</p>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">

                {{-- Local Billing day-close policy (F1) — save/delete per bill kind + spend persist --}}
                <div class="sm:col-span-2 rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-600 dark:bg-teal-900/30 dark:text-teal-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.local_billing_dayclose_policy') }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.local_billing_dayclose_sub') }}</p>
                        </div>
                        <p class="mb-3 rounded-lg bg-teal-50 dark:bg-teal-900/20 px-3 py-2 text-[11px] font-semibold text-teal-800 dark:text-teal-300">{{ __('pos.local_billing_numbering_never_resets') }}</p>
                        <span class="shrink-0 text-[10px] font-semibold text-gray-400" x-show="savingLB" x-cloak>{{ __('pos.saving_ellipsis') }}</span>
                    </div>
                    <div class="space-y-3">
                        @php
                            $localNumberStyle = in_array(($company->local_number_style ?? 'serial'), ['serial', 'token', 'daily'], true)
                                ? $company->local_number_style
                                : 'serial';
                        @endphp
                        <div class="rounded-xl border border-indigo-200 dark:border-indigo-800 bg-indigo-50/70 dark:bg-indigo-900/20 p-3"
                             x-data="{
                                style: {{ Js::from($localNumberStyle) }},
                                savedStyle: {{ Js::from($localNumberStyle) }},
                                busy: false,
                                msg: '',
                                err: '',
                                save() {
                                    if (this.busy || this.style === this.savedStyle) return;
                                    const previous = this.savedStyle;
                                    this.busy = true; this.msg = ''; this.err = '';
                                    fetch('{{ route('pos.settings.local-billing.number-style', [], false) }}', {
                                        method: 'POST',
                                        headers: {'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},
                                        body: JSON.stringify({style: this.style})
                                    }).then(r => r.json().then(d => ({ok:r.ok,d})))
                                      .then(({ok,d}) => {
                                          if (ok && d && d.success === true && d.style === this.style) {
                                              this.savedStyle = d.style;
                                              this.msg = d.message || '';
                                          } else {
                                              this.style = previous;
                                              this.err = (d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }};
                                          }
                                      })
                                      .catch(() => {
                                          this.style = previous;
                                          this.err = {{ Js::from(__('pos.setting_save_failed')) }};
                                      })
                                      .finally(() => { this.busy = false; });
                                }
                             }">
                            <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                                <div class="min-w-0 flex-1">
                                    <p class="text-[13px] font-bold text-indigo-900 dark:text-indigo-200">
                                        {{ __('pos.local_number_display_title') }}
                                        <x-new-badge feature="local_daily_number" class="ml-1" />
                                    </p>
                                    <p class="text-[11px] text-indigo-800 dark:text-indigo-300 mt-0.5">{{ __('pos.local_number_display_sub') }}</p>
                                </div>
                                <select x-model="style" @change="save()" :disabled="busy"
                                    class="w-full sm:w-72 rounded-lg border-indigo-300 dark:border-indigo-700 bg-white dark:bg-gray-900 text-sm font-semibold text-gray-800 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="daily">{{ __('pos.number_style_daily') }}</option>
                                    <option value="serial">{{ __('pos.number_style_serial') }}</option>
                                    <option value="token">{{ __('pos.number_style_token') }}</option>
                                </select>
                            </div>
                            <p class="mt-2 text-[11px] font-semibold text-emerald-700 dark:text-emerald-400">{{ __('pos.number_style_daily_hint') }}</p>
                            <p x-show="msg" x-cloak class="mt-2 text-[11px] font-bold text-emerald-700 dark:text-emerald-400" x-text="msg"></p>
                            <p x-show="err" x-cloak class="mt-2 text-[11px] font-bold text-red-700 dark:text-red-400" x-text="err"></p>
                        </div>
                        <div class="flex items-center justify-between gap-3 flex-wrap">
                            <div class="min-w-0">
                                <p class="text-[13px] font-semibold text-gray-800 dark:text-gray-200">{{ __('pos.final_local_bills') }}</p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.final_local_bills_sub') }}</p>
                            </div>
                            <div class="inline-flex rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden shrink-0">
                                <button type="button" @click="lbFinal='save'; saveLB()" class="px-4 py-1.5 text-[12px] font-bold transition" :class="lbFinal==='save' ? 'bg-teal-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300'">{{ __('pos.save_btn') }}</button>
                                <button type="button" @click="lbFinal='delete'; saveLB()" class="px-4 py-1.5 text-[12px] font-bold transition border-l border-gray-200 dark:border-gray-700" :class="lbFinal==='delete' ? 'bg-red-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300'">{{ __('pos.delete') }}</button>
                            </div>
                        </div>
                        <div class="flex items-center justify-between gap-3 flex-wrap">
                            <div class="min-w-0">
                                <p class="text-[13px] font-semibold text-gray-800 dark:text-gray-200">{{ __('pos.provisional_bills') }}</p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.provisional_bills_sub') }}</p>
                            </div>
                            <div class="inline-flex flex-wrap sm:flex-nowrap max-w-full rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden shrink-0">
                                <button type="button" @click="lbProv='save'; saveLB()" class="px-4 py-1.5 text-[12px] font-bold transition" :class="lbProv==='save' ? 'bg-teal-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300'">{{ __('pos.save_btn') }}</button>
                                <button type="button" @click="lbProv='delete'; saveLB()" class="px-4 py-1.5 text-[12px] font-bold transition border-l border-gray-200 dark:border-gray-700" :class="lbProv==='delete' ? 'bg-red-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300'">{{ __('pos.delete') }}</button>
                                <button type="button" @click="lbProv='carry'; saveLB()" title="{{ __('pos.ti_carry_forward') }}" class="px-4 py-1.5 text-[12px] font-bold transition border-l border-gray-200 dark:border-gray-700" :class="lbProv==='carry' ? 'bg-indigo-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300'">{{ __('pos.badge_carry') }}</button>
                                {{-- Auto Make Final at day close (owner option, Aug 2026) --}}
                                <button type="button" @click="lbProv='finalize'; saveLB()" title="{{ __('pos.ti_finalize_dayclose') }}" class="px-4 py-1.5 text-[12px] font-bold transition border-l border-gray-200 dark:border-gray-700" :class="lbProv==='finalize' ? 'bg-emerald-600 text-white' : 'bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300'">{{ __('pos.badge_finalize') }}</button>
                            </div>
                        </div>
                        {{-- Loud tradeoff warning: auto-finalize sends bills to the tax record irreversibly. --}}
                        <div x-show="lbProv==='finalize'" x-cloak class="p-2.5 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700">
                            <p class="text-[11px] font-semibold text-amber-800 dark:text-amber-300">{{ __('pos.lb_finalize_warning') }}</p>
                        </div>
                        <div class="flex items-center justify-between gap-3 pt-3 border-t border-gray-100 dark:border-gray-800">
                            <div class="min-w-0">
                                <p class="text-[13px] font-semibold text-gray-800 dark:text-gray-200">{{ __('pos.customer_spent_record') }}</p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.customer_spent_record_sub') }}</p>
                            </div>
                            <button type="button" @click="lbPersist=!lbPersist; saveLB()"
                                class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="lbPersist ? 'bg-teal-600' : 'bg-gray-300 dark:bg-gray-600'">
                                <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="lbPersist && 'translate-x-6'"></span>
                            </button>
                        </div>

                        {{-- Leftover customer-spend lines of ALREADY-deleted local bills
                             (owner, 25 Aug 2026): the switch above only stops new ones,
                             so the shop can also wipe what is still sitting in customer
                             history. Admin-only + confirmed; real bills are untouched. --}}
                        @if(($localSeries['spend_records'] ?? 0) > 0)
                        <div class="pt-3 border-t border-gray-100 dark:border-gray-800"
                             x-data="{ srOpen: false, srBusy: false, srDone: false, srMsg: '', srErr: '',
                                clearSpend() {
                                    if (this.srBusy) return;
                                    this.srBusy = true; this.srErr = '';
                                    fetch('{{ route('pos.settings.local-billing.clear-spend-records', [], false) }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:'{}'})
                                        .then(r=>r.json().then(d=>({ok:r.ok,d})))
                                        .then(({ok,d})=>{ if (ok && d && d.success === true) { this.srMsg = d.message || ''; this.srDone = true; this.srOpen = false; } else { this.srErr = (d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}; } })
                                        .catch(()=>{ this.srErr = {{ Js::from(__('pos.setting_save_failed')) }}; })
                                        .finally(()=>{ this.srBusy = false; });
                                } }">
                            <div x-show="!srDone" class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700">
                                <p class="text-[11px] font-bold text-gray-800 dark:text-gray-200">{{ __('pos.spend_records_line', ['count' => $localSeries['spend_records']]) }} <x-new-badge feature="spend_records_clear" class="ml-1" /></p>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.spend_records_hint') }}</p>
                                <button type="button" x-show="!srOpen" @click="srOpen = true"
                                    class="mt-2.5 px-3.5 py-1.5 rounded-lg bg-red-600 hover:bg-red-700 text-white text-[12px] font-bold transition">{{ __('pos.spend_records_clear_btn') }}</button>
                                <div x-show="srOpen" x-cloak class="mt-2.5">
                                    <p class="text-[11px] font-semibold text-red-700 dark:text-red-300">{{ __('pos.spend_records_confirm', ['count' => $localSeries['spend_records']]) }}</p>
                                    <div class="mt-2 flex items-center gap-2">
                                        <button type="button" @click="clearSpend()" :disabled="srBusy"
                                            class="px-3.5 py-1.5 rounded-lg bg-red-600 hover:bg-red-700 disabled:opacity-60 text-white text-[12px] font-bold transition">{{ __('pos.spend_records_confirm_btn') }}</button>
                                        <button type="button" @click="srOpen = false" :disabled="srBusy"
                                            class="px-3.5 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-[12px] font-bold transition">{{ __('pos.spend_records_cancel_btn') }}</button>
                                    </div>
                                </div>
                                <p x-show="srErr" x-cloak class="mt-2 text-[11px] font-semibold text-red-600 dark:text-red-400" x-text="srErr"></p>
                            </div>
                            <div x-show="srDone" x-cloak class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-300 dark:border-emerald-700">
                                <p class="text-[11px] font-bold text-emerald-800 dark:text-emerald-300" x-text="srMsg"></p>
                            </div>
                        </div>
                        @endif

                        {{-- Archived local-record housekeeping. L-references are now
                             monotonic and never reused, so this clear removes old rows
                             without resetting the company's next number. The clear is
                             owner-confirmed and permanent (never automatic). --}}
                        @if(($localSeries['count'] ?? 0) > 0)
                        <div class="pt-3 border-t border-gray-100 dark:border-gray-800"
                             x-data="{ lsOpen: false, lsBusy: false, lsDone: false, lsMsg: '', lsErr: '', lsKept: 0, lsKeptMsg: '',
                                clearSeries() {
                                    if (this.lsBusy) return;
                                    this.lsBusy = true; this.lsErr = '';
                                    fetch('{{ route('pos.settings.local-billing.clear-archived', [], false) }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:'{}'})
                                        .then(r=>r.json())
                                        .then(d=>{ if (d && d.success === true) { this.lsMsg = d.message || ''; this.lsKept = Number(d.rider_held || 0); this.lsKeptMsg = d.rider_held_message || ''; this.lsDone = true; this.lsOpen = false; } else { this.lsErr = (d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}; } })
                                        .catch(()=>{ this.lsErr = {{ Js::from(__('pos.setting_save_failed')) }}; })
                                        .finally(()=>{ this.lsBusy = false; });
                                } }">
                            <div x-show="!lsDone" class="p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700">
                                <p class="text-[11px] font-bold text-amber-900 dark:text-amber-300">{{ __('pos.local_series_stuck_line', ['count' => $localSeries['count'], 'from' => $localSeries['from'], 'to' => $localSeries['to'], 'next' => $localSeries['next']]) }}</p>
                                <p class="text-[11px] text-amber-800 dark:text-amber-400 mt-1">{{ __('pos.local_series_stuck_hint') }}</p>
                                <button type="button" @click="lsOpen = true"
                                    class="mt-2.5 px-3.5 py-1.5 rounded-lg bg-red-600 hover:bg-red-700 text-white text-[12px] font-bold transition">{{ __('pos.local_series_clear_btn') }}</button>
                            </div>
                            <div x-show="lsDone" x-cloak class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-300 dark:border-emerald-700">
                                <p class="text-[11px] font-bold text-emerald-800 dark:text-emerald-300" x-text="lsMsg"></p>
                                {{-- Bills whose rider cash is still unsettled are deliberately
                                     spared (same rule as the day-close wash). Say how many records
                                     were kept and where to settle them; numbering stays monotonic
                                     regardless of whether these rows are later removed. --}}
                                <div x-show="lsKept > 0" x-cloak class="mt-2 pt-2 border-t border-emerald-200 dark:border-emerald-800">
                                    <p class="text-[11px] font-semibold text-amber-800 dark:text-amber-300" x-text="lsKeptMsg"></p>
                                    <a href="{{ route('pos.deliveries', [], false) }}"
                                        class="inline-block mt-1.5 text-[11px] font-bold text-teal-700 dark:text-teal-400 underline">{{ __('pos.local_series_rider_kept_link') }} &rarr;</a>
                                </div>
                            </div>

                            <template x-teleport="body">
                            <div x-show="lsOpen" x-cloak class="fixed inset-0 flex items-center justify-center p-4"
                                 style="z-index:120; background: rgba(15,10,40,0.55); backdrop-filter: blur(3px);"
                                 @keydown.escape.window="if (!lsBusy) lsOpen = false">
                                <div class="w-full max-w-md bg-white dark:bg-gray-900 rounded-2xl shadow-2xl overflow-hidden" @click.outside="if (!lsBusy) lsOpen = false"
                                     x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">
                                    <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800">
                                        <h3 class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.local_series_modal_title') }}</h3>
                                        <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.local_series_modal_sub', ['next' => $localSeries['next_after']]) }}</p>
                                    </div>
                                    <div class="px-5 py-4 space-y-2.5">
                                        <p class="text-[12px] font-bold text-gray-800 dark:text-gray-200">{{ __('pos.local_series_modal_count', ['count' => $localSeries['count'], 'from' => $localSeries['from'], 'to' => $localSeries['to']]) }}</p>
                                        <p class="text-[11px] text-red-700 dark:text-red-400 font-semibold">{{ __('pos.local_series_modal_permanent') }}</p>
                                        <p class="text-[11px] text-gray-600 dark:text-gray-300">{{ __('pos.local_series_modal_safe') }}</p>
                                        <p x-show="lbPersist" class="text-[11px] text-gray-600 dark:text-gray-300">{{ __('pos.local_series_modal_spend') }}</p>
                                        <p x-show="lsErr" x-cloak class="text-[11px] font-bold text-red-700 dark:text-red-400" x-text="lsErr"></p>
                                    </div>
                                    <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800 flex items-center justify-end gap-2">
                                        <button type="button" @click="lsOpen = false" :disabled="lsBusy"
                                            class="px-4 py-2 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-200 text-[12px] font-bold">{{ __('pos.cancel') }}</button>
                                        <button type="button" @click="clearSeries()" :disabled="lsBusy" :class="lsBusy && 'opacity-60 cursor-not-allowed'"
                                            class="px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white text-[12px] font-bold">
                                            <span x-show="!lsBusy">{{ __('pos.local_series_modal_confirm', ['next' => $localSeries['next_after']]) }}</span>
                                            <span x-show="lsBusy" x-cloak>{{ __('pos.local_series_clearing') }}</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            </template>
                        </div>
                        @endif

                    </div>
                    {{-- Explicit reset is available only after every issued L-reference
                         is gone. It never runs as part of day close or Clear. --}}
                    @if(($localSeries['can_reset'] ?? false) && $localNumberStyle !== 'daily')
                    <div class="pt-3 border-t border-gray-100 dark:border-gray-800"
                         x-data="{ lrBusy: false, lrDone: false, lrMsg: '', lrErr: '', resetSeries() { if (this.lrBusy) return; this.lrBusy = true; this.lrErr = ''; fetch('{{ route('pos.settings.local-billing.reset-numbering', [], false) }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:'{}'}).then(r=>r.json().then(d=>({ok:r.ok,d}))).then(({ok,d})=>{ if (ok && d && d.success === true) { this.lrMsg = d.message || ''; this.lrDone = true; } else { this.lrErr = (d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}; } }).catch(()=>{ this.lrErr = {{ Js::from(__('pos.setting_save_failed')) }}; }).finally(()=>{ this.lrBusy = false; }); } }">
                        <div x-show="!lrDone" class="p-3 rounded-lg bg-teal-50 dark:bg-teal-900/20 border border-teal-300 dark:border-teal-700">
                            <p class="text-[11px] font-bold text-teal-900 dark:text-teal-300">{{ __('pos.local_series_reset_title', ['next' => \App\Services\PosLocalSeries::format(1)]) }} <x-new-badge feature="local_series_reset" class="ml-1" /></p>
                            <p class="text-[11px] text-teal-800 dark:text-teal-400 mt-1">{{ __('pos.local_series_reset_hint') }}</p>
                            <p x-show="lrErr" x-cloak class="text-[11px] font-bold text-red-700 dark:text-red-400 mt-1.5" x-text="lrErr"></p>
                            <button type="button" @click="resetSeries()" :disabled="lrBusy" class="mt-2.5 px-3.5 py-1.5 rounded-lg bg-teal-600 hover:bg-teal-700 text-white text-[12px] font-bold transition">{{ __('pos.local_series_reset_btn', ['next' => \App\Services\PosLocalSeries::format(1)]) }}</button>
                        </div>
                        <div x-show="lrDone" x-cloak class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-300 dark:border-emerald-700"><p class="text-[11px] font-bold text-emerald-800 dark:text-emerald-300" x-text="lrMsg"></p></div>
                    </div>
                    @endif
                </div>

                {{-- Auto day-close at 6:00 AM the next morning --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-600 dark:bg-teal-900/30 dark:text-teal-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.auto_dayclose_6am') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.auto_dayclose_6am_sub') }}</p>
                    </div>
                    <button type="button"
                        @click="autoDaycloseOn=!autoDaycloseOn; savingDayclose=true; fetch('/pos/settings/auto-dayclose-toggle', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:autoDaycloseOn})}).then(r=>r.json()).catch(()=>{}).finally(()=>{ savingDayclose=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="autoDaycloseOn ? 'bg-teal-600' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="autoDaycloseOn && 'translate-x-6'"></span>
                    </button>
                </div>

                {{-- Cashier can run Day Close (owner rule 5 Aug 2026 — default OFF:
                     Day Close is admin/manager work; this re-opens it for cashiers). --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.cashier_dayclose_title') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.cashier_dayclose_sub') }}</p>
                    </div>
                    <button type="button"
                        @click="cashierDcOn=!cashierDcOn; savingCdc=true; fetch('/pos/settings/cashier-dayclose-toggle', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:cashierDcOn})}).then(r=>r.json()).catch(()=>{}).finally(()=>{ savingCdc=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="cashierDcOn ? 'bg-teal-600' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="cashierDcOn && 'translate-x-6'"></span>
                    </button>
                </div>

                {{-- Cashier can cancel restaurant orders (Task #643, owner voice note
                     13 Aug 2026 — default OFF: order cancel is owner/manager work). --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.cashier_ordercancel_title') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.cashier_ordercancel_sub') }}</p>
                    </div>
                    <button type="button"
                        @click="cashierOcOn=!cashierOcOn; savingCoc=true; fetch('/pos/settings/cashier-ordercancel-toggle', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:cashierOcOn})}).then(r=>r.json()).catch(()=>{}).finally(()=>{ savingCoc=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="cashierOcOn ? 'bg-teal-600' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="cashierOcOn && 'translate-x-6'"></span>
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
                            {{-- 26 Aug 2026: agar is card ke page par koi naya switch hai to
                                 yahin se nazar aa jaye — shop ko andar ja kar dhoondna na pare. --}}
                            <x-new-badge :url="$c['url']" panel="pos" />
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
                {{ __('pos.back_to_dashboard') }}
            </a>
        </div>
    </div>
</x-pos-layout>
