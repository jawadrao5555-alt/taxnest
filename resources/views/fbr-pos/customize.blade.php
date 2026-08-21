<x-fbr-pos-layout>
    @php
        $fbrOn       = (bool) ($company->fbr_pos_enabled ?? false);
        $reportingOn = (bool) ($company->fbr_reporting_enabled ?? false);

        // ── Task 1403: Desktop Agent + FBR-owned feature switches ────────────
        $agentOnline = (bool) ($company->agentOnline() ?? false);

        // Store Slip lives on the companies column; Delivery and the per-item
        // Store note live in feature_flags. kitchen_notes must be read RAW —
        // it is a RESTAURANT_FLAG and every fbrpos plan has restaurant_enabled
        // = 0, so forCompany() would mask it to false forever. Delivery is read
        // resolved, because that is exactly what the sale screen gates on.
        $fbrStoreSlipOn  = (bool) ($company->kitchen_printer_enabled ?? false);
        $fbrDeliveryOn   = (bool) (\App\Services\PosFeatureService::forCompany($company)->delivery ?? false);
        $fbrStoreNoteOn  = \App\Services\PosFeatureService::rawFlag($company, 'kitchen_notes');
        // Package gates: Store Slip + per-item note ride kot_enabled (same gate
        // the FBR sale screen already applies), Delivery rides riders_enabled.
        $fbrKotPlan      = \App\Services\PosFeatureService::planAllows($company, 'kot_enabled');
        $fbrRidersPlan   = \App\Services\PosFeatureService::planAllows($company, 'riders_enabled');
        // Downgrade case: package no longer covers a feature that is still ON.
        // The endpoint always accepts OFF, so the card must keep a working switch
        // (one-way: it can be turned off, not back on) instead of a dead padlock.
        $fbrSlipLockedOn = !$fbrKotPlan    && $fbrStoreSlipOn;
        $fbrDelivLockedOn= !$fbrRidersPlan && $fbrDeliveryOn;
        $fbrNoteLockedOn = !$fbrKotPlan    && $fbrStoreNoteOn;

        // Card sections — every FBR POS setting reachable from this one hub.
        $sections = [
            [
                'title' => __('pos.setup_compliance'),
                'desc'  => __('pos.setup_compliance_desc'),
                'items' => [
                    ['label' => __('pos.business_profile'), 'desc' => __('pos.business_profile_card_desc'), 'url' => route('fbrpos.business-profile'), 'tone' => 'blue', 'badge' => __('pos.badge_identity'), 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
                    ['label' => __('pos.receipt_print_style'), 'desc' => __('pos.fbr_receipt_style_card_desc'), 'url' => route('fbrpos.receipt-settings'), 'tone' => 'blue', 'badge' => __('pos.badge_print'), 'icon' => 'M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z'],
                    ['label' => __('pos.printer_settings'), 'desc' => __('pos.card_printer_settings_desc'), 'url' => route('fbrpos.printer-settings'), 'tone' => ($company->printerSettings()['silent_print_enabled'] ?? false) ? 'emerald' : 'blue', 'badge' => ($company->printerSettings()['silent_print_enabled'] ?? false) ? __('pos.badge_silent_on') : __('pos.badge_popup'), 'icon' => 'M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z'],
                    // Desktop Agent (Task 1403): FBR shops used to reach the agent only
                    // through a card buried inside FBR Settings that rendered only in
                    // fiscal-device mode. It has its own page now, reachable regardless
                    // of the FBR connection mode.
                    ['label' => __('pos.desktop_agent'), 'desc' => __('pos.fbr_card_agent_desc'), 'url' => route('fbrpos.agent'), 'tone' => $agentOnline ? 'emerald' : 'blue', 'badge' => $agentOnline ? __('pos.online') : __('pos.offline'), 'icon' => 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
                    ['label' => __('pos.fbr_settings'), 'desc' => __('pos.fbr_settings_card_desc'), 'url' => route('fbrpos.settings'), 'tone' => $fbrOn ? 'emerald' : 'amber', 'badge' => $fbrOn ? __('pos.fbr_on_badge') : __('pos.fbr_off_badge'), 'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
                    ['label' => __('pos.products_word'), 'desc' => __('pos.products_card_desc'), 'url' => route('fbrpos.products'), 'tone' => 'blue', 'badge' => __('pos.badge_catalog'), 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
                    ['label' => __('pos.card_services'), 'desc' => __('pos.card_services_desc'), 'url' => route('fbrpos.services'), 'tone' => 'blue', 'badge' => __('pos.badge_manage'), 'icon' => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10'],
                ],
            ],
            [
                'title' => __('pos.operations_word'),
                'desc'  => __('pos.operations_desc'),
                'items' => [
                    ['label' => __('pos.terminals_word'), 'desc' => __('pos.terminals_card_desc'), 'url' => route('fbrpos.phase2.terminals'), 'tone' => 'blue', 'badge' => 'Manage', 'icon' => 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
                    ['label' => __('pos.shifts_cash_drawer'), 'desc' => __('pos.shifts_card_desc'), 'url' => route('fbrpos.phase2.shifts'), 'tone' => 'blue', 'badge' => 'Manage', 'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
                    ['label' => __('pos.promotions_word'), 'desc' => __('pos.promotions_card_desc'), 'url' => route('fbrpos.phase2.promotions'), 'tone' => 'blue', 'badge' => 'Manage', 'icon' => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'],
                    // 🍔 Deals (Task 1273): fixed-price combos. Plan-locked companies see the
                    // amber 🔒 badge; the page itself redirects to billing (fbrPlanGate).
                    ['label' => __('pos.deals_title'), 'desc' => __('pos.card_deals_desc'), 'url' => route('fbrpos.deals'), 'tone' => \App\Services\PosFeatureService::planAllows($company, 'deals_enabled') ? 'blue' : 'amber', 'badge' => \App\Services\PosFeatureService::planAllows($company, 'deals_enabled') ? 'Manage' : ('🔒 ' . __('pos.upgrade_plan_btn')), 'icon' => 'M12 8v13m0-13V6a2 2 0 112 2h-2zm0 0V5.5A2.5 2.5 0 109.5 8H12zm-7 4h14M5 12a2 2 0 110-4h14a2 2 0 110 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7'],
                    // Team + Branches (Task 1403): both pages already existed but were
                    // reachable only from the top nav — the hub never listed them.
                    ['label' => __('pos.team_management'), 'desc' => __('pos.fbr_card_team_desc'), 'url' => route('fbrpos.team'), 'tone' => 'blue', 'badge' => __('pos.badge_manage'), 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z'],
                    ['label' => __('pos.branches_title'), 'desc' => __('pos.fbr_card_branches_desc'), 'url' => route('fbrpos.branches'), 'tone' => 'blue', 'badge' => __('pos.badge_manage'), 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
                    ['label' => __('pos.loyalty_program'), 'desc' => __('pos.loyalty_card_desc'), 'url' => route('fbrpos.phase2.loyalty'), 'tone' => 'blue', 'badge' => 'Manage', 'icon' => 'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z'],
                ],
            ],
            [
                'title' => __('pos.reports_account'),
                'desc'  => __('pos.reports_account_desc'),
                'items' => [
                    ['label' => __('pos.bio_setup_title'), 'desc' => __('pos.bio_setup_sub'), 'url' => route('fbrpos.bio-sync.setup'), 'tone' => 'blue', 'badge' => __('pos.staff_hazri'), 'icon' => 'M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 008 3.935m-3.778 3.44A7.962 7.962 0 004 11c0 4.418-1.105 7.02-2 8'],
                    ['label' => __('pos.day_close_z_short'), 'desc' => __('pos.day_close_card_desc'), 'url' => route('fbrpos.day-close'), 'tone' => 'blue', 'badge' => __('pos.badge_z_report'), 'icon' => 'M9 17v-2a2 2 0 012-2h2a2 2 0 012 2v2m-6 4h6a2 2 0 002-2V7a2 2 0 00-2-2H9a2 2 0 00-2 2v12a2 2 0 002 2z'],
                    // The auto day-close + cutoff controls now have a home in the
                    // Local Bills & Day-Close section above (which deep-links to the
                    // Day Close page); no duplicate hub card needed.
                    ['label' => __('pos.reports_word'), 'desc' => __('pos.reports_card_desc'), 'url' => route('fbrpos.reports'), 'tone' => 'blue', 'badge' => __('pos.badge_insights'), 'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
                    ['label' => __('pos.tax_reports'), 'desc' => __('pos.tax_reports_card_desc'), 'url' => route('fbrpos.tax-reports'), 'tone' => $reportingOn ? 'emerald' : 'blue', 'badge' => __('pos.badge_tax'), 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
                    ['label' => __('pos.billing_plan'), 'desc' => __('pos.billing_card_desc'), 'url' => route('fbrpos.billing'), 'tone' => 'blue', 'badge' => __('pos.badge_plan'), 'icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
                    ['label' => __('pos.my_profile'), 'desc' => __('pos.my_profile_card_desc'), 'url' => route('fbrpos.my-profile'), 'tone' => 'blue', 'badge' => __('pos.badge_account'), 'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
                ],
            ],
        ];

        $tones = [
            'blue'    => ['ic' => 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400', 'bd' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'],
            'emerald' => ['ic' => 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400', 'bd' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'],
            'amber'   => ['ic' => 'bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400', 'bd' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'],
        ];

        // blue-X theme family only — the FBR layout remaps blue-X → active accent.
        $themes = [
            'purple'   => ['#312e81', '#7c3aed'],
            'blue'     => ['#1e3a5f', '#2563eb'],
            'emerald'  => ['#064e3b', '#059669'],
            'orange'   => ['#7c2d12', '#ea580c'],
            'midnight' => ['#171717', '#404040'],
            'rose'     => ['#881337', '#e11d48'],
        ];

        // ── Phase B search keywords ──────────────────────────────────────────
        // One keyword blob per setting card: visible title + description PLUS the
        // Roman-Urdu / English synonyms a shopkeeper actually types (peti, udhaar,
        // parchi, chapai, din band, rang, zaban…). These strings feed BOTH the
        // per-card x-show and the concatenated all-page blob for the empty state,
        // so the two can never drift.
        $kw = [
            'theme'      => __('pos.pos_theme') . ' ' . __('pos.active_prefix') . ' theme rang color colour appearance look sale screen',
            'guided'     => __('pos.guided_keyboard_billing') . ' ' . __('pos.guided_billing_desc') . ' keyboard guided billing flow madad arrow keys',
            'whatsapp'   => __('pos.wa_bill_toggle') . ' ' . __('pos.wa_bill_toggle_sub') . ' whatsapp bill receipt rasid parchi send bhejo auto open',
            'dashboard'  => __('pos.dashboard_design') . ' ' . __('pos.dashboard_design_desc') . ' dashboard style design layout screen',
            'language'   => __('pos.fbr_default_language_title') . ' ' . __('pos.fbr_default_language_sub') . ' language zaban urdu roman english default',
            'storeslip'  => __('pos.fbr_feat_store_slip_title') . ' ' . __('pos.fbr_feat_store_slip_sub') . ' store slip kitchen parchi chapai print rasoi',
            'delivery'   => __('pos.fbr_feat_delivery_title') . ' ' . __('pos.fbr_feat_delivery_sub') . ' delivery rider riders home ghar bhejo',
            'storenote'  => __('pos.fbr_feat_store_notes_title') . ' ' . __('pos.fbr_feat_store_notes_sub') . ' note notes hidayat item special store slip',
            'quicktype'  => __('pos.quick_type_mode') . ' ' . __('pos.quick_type_mode_sub') . ' quick type fast tez keyboard input',
            'cashrecv'   => __('pos.cash_received_toggle') . ' ' . __('pos.cash_received_toggle_sub') . ' cash received change wapsi paise pay',
            'autoclose'  => __('pos.receipt_popup_autoclose') . ' ' . __('pos.receipt_popup_autoclose_sub') . ' receipt popup autoclose rasid band second timer',
            'autoslip'   => __('pos.fbr_auto_store_slip') . ' ' . __('pos.fbr_ti_auto_store_slip_hint') . ' auto store slip print chapai payment',
            'slipreprint'=> __('pos.fbr_store_slip_reprint_toggle_title') . ' ' . __('pos.fbr_store_slip_reprint_toggle_sub') . ' reprint store slip dubara chapai',
            'inventory'  => __('pos.inventory_tracking') . ' ' . __('pos.inventory_tracking_sub') . ' inventory stock maal tracking',
            'callerid'   => __('pos.caller_id_title') . ' ' . __('pos.caller_id_sub') . ' caller id call phone number customer',
            'restock'    => __('pos.restock_on_void') . ' ' . __('pos.restock_on_void_sub') . ' restock void delete wapas stock maal',
            'pending'    => __('pos.fbr_dayclose_pending_title') . ' ' . __('pos.fbr_dayclose_pending_desc') . ' pending bills day close din band final carry',
            'cashierdc'  => __('pos.cashier_dayclose_title') . ' ' . __('pos.cashier_dayclose_sub') . ' cashier day close din band z report',
            'daycutoff'  => __('pos.day_cutoff_title') . ' ' . __('pos.fbr_card_dayclose_settings_desc') . ' day close cutoff auto din band time',
        ];
        $kwAppearance = trim(__('pos.appearance_experience') . ' ' . $kw['theme'] . ' ' . $kw['guided'] . ' ' . $kw['whatsapp'] . ' ' . $kw['dashboard'] . ' ' . $kw['language']);
        $kwFeatures   = trim(__('pos.fbr_features_section') . ' ' . $kw['storeslip'] . ' ' . $kw['delivery'] . ' ' . $kw['storenote']);
        $kwSale       = trim(__('pos.sec_sale_billing') . ' ' . $kw['quicktype'] . ' ' . $kw['cashrecv'] . ' ' . $kw['autoclose'] . ' ' . $kw['autoslip'] . ' ' . $kw['slipreprint'] . ' ' . $kw['inventory'] . ' ' . $kw['callerid'] . ' ' . $kw['restock']);
        $kwDayclose   = trim(__('pos.sec_local_bills_dayclose') . ' ' . $kw['pending'] . ' ' . $kw['cashierdc'] . ' ' . $kw['daycutoff']);
        // Card-hub shortcuts join the searchable blob too (label + desc + badge),
        // so a search for e.g. "products" or "reports" still surfaces its card.
        $kwShortcuts = __('pos.cust_nav_shortcuts');
        foreach ($sections as $tnSec) {
            $kwShortcuts .= ' ' . $tnSec['title'] . ' ' . $tnSec['desc'];
            foreach ($tnSec['items'] as $tnItem) {
                $kwShortcuts .= ' ' . ($tnItem['label'] ?? '') . ' ' . ($tnItem['desc'] ?? '');
            }
        }
        $kwShortcuts = trim($kwShortcuts);
        // Whole-page blob drives the "nothing found" empty state.
        $tnAllKw = trim($kwAppearance . ' ' . $kwFeatures . ' ' . $kwSale . ' ' . $kwDayclose . ' ' . $kwShortcuts);

        $styles = [
            ['id' => 'default', 'name' => __('pos.style_default_name'), 'desc' => __('pos.style_default_desc'), 'icon' => '◻', 'colors' => ['#f3f4f6','#e5e7eb','#d1d5db']],
            ['id' => 'toast', 'name' => __('pos.style_toast_name'), 'desc' => __('pos.style_toast_desc'), 'icon' => '📊', 'colors' => ['#fbbf24','#f59e0b','#d97706']],
            ['id' => 'lightspeed', 'name' => __('pos.style_lightspeed_name'), 'desc' => __('pos.style_lightspeed_desc'), 'icon' => '⚡', 'colors' => ['#8b5cf6','#6366f1','#4f46e5']],
            ['id' => 'clover', 'name' => __('pos.style_clover_name'), 'desc' => __('pos.style_clover_desc'), 'icon' => '🍀', 'colors' => ['#22c55e','#16a34a','#15803d']],
            ['id' => 'oscar', 'name' => __('pos.style_oscar_name'), 'desc' => __('pos.style_oscar_desc'), 'icon' => '🇵🇰', 'colors' => ['#0ea5e9','#0284c7','#0369a1']],
            ['id' => 'shopify', 'name' => __('pos.style_shopify_name'), 'desc' => __('pos.style_shopify_desc'), 'icon' => '✨', 'colors' => ['#1e293b','#334155','#475569']],
        ];
    @endphp

    <div x-data="{
            currentTheme: '{{ $company->pos_theme ?? 'blue' }}',
            currentStyle: '{{ $company->pos_dashboard_style ?? 'default' }}',
            guidedOn: {{ ($company->pos_guided_flow_enabled ?? true) ? 'true' : 'false' }}, savingGuided: false,
            quickOn: {{ ($company->pos_quick_type_enabled ?? false) ? 'true' : 'false' }}, savingQuick: false,
            cashRecvOn: {{ ($company->pos_cash_received_enabled ?? false) ? 'true' : 'false' }}, savingCashRecv: false,
            autoKotOn: {{ ($company->auto_print_kot ?? false) ? 'true' : 'false' }}, savingAutoKot: false,
            kotReprintOn: {{ ($company->kot_reprint_enabled ?? true) ? 'true' : 'false' }}, savingKotReprint: false,
            invOn: {{ ($company->inventory_enabled ?? false) ? 'true' : 'false' }}, savingInv: false,
            restockOn: {{ ($company->pos_restock_on_void ?? true) ? 'true' : 'false' }}, savingRestock: false,
            cashierDcOn: {{ ($company->pos_cashier_dayclose ?? false) ? 'true' : 'false' }}, savingCdc: false,
            rcSecs: {{ (int) ($company->pos_receipt_autoclose_seconds ?? 10) }}, savingRc: false,
            {{-- Task 1403 — FBR-owned feature switches (Store Slip / Delivery / per-item Store note) --}}
            storeSlipOn: {{ $fbrStoreSlipOn ? 'true' : 'false' }}, savingStoreSlip: false,
            deliveryOn: {{ $fbrDeliveryOn ? 'true' : 'false' }}, savingDelivery: false,
            storeNoteOn: {{ $fbrStoreNoteOn ? 'true' : 'false' }}, savingStoreNote: false,
            {{-- Downgraded-but-still-ON: the switch stays LIVE so the owner can turn
                 the feature off, and freezes the moment it is off so it cannot come
                 back without an upgrade. Read as: "locked, off-only". --}}
            slipOffOnly: {{ $fbrSlipLockedOn ? 'true' : 'false' }},
            delivOffOnly: {{ $fbrDelivLockedOn ? 'true' : 'false' }},
            noteOffOnly: {{ $fbrNoteLockedOn ? 'true' : 'false' }},
            featSave(feature, prop, savingProp) {
                if (this[savingProp]) return;
                const want = !this[prop];
                this[prop] = want;
                this[savingProp] = true;
                fetch('/fbr-pos/settings/feature-toggle', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({feature:feature, enabled:want})})
                    .then(r => r.json())
                    .then(d => {
                        if (!d || d.success !== true) { this[prop] = !want; alert((d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}); return; }
                        // Trust the server's answer, not the click — a dependency can
                        // force a flag on, and turning Store Slip off also clears the
                        // auto-print switch server-side.
                        this[prop] = !!d.enabled;
                        if (feature === 'store_slip' && !this[prop]) { this.autoKotOn = false; this.storeNoteOn = false; }
                    })
                    .catch(() => { this[prop] = !want; alert({{ Js::from(__('pos.setting_save_failed')) }}); })
                    .finally(() => { this[savingProp] = false; });
            },
            setRc(s) { if (this.rcSecs === s || this.savingRc) return; const prev = this.rcSecs; this.rcSecs = s; this.savingRc = true; fetch('/fbr-pos/settings/receipt-autoclose', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({seconds:s})}).then(r=>r.json()).then(d=>{ if (!d || d.success !== true) { this.rcSecs = prev; alert((d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}); } }).catch(()=>{ this.rcSecs = prev; alert({{ Js::from(__('pos.setting_save_failed')) }}); }).finally(()=>{ this.savingRc = false; }); },
            savingTheme: false, savingStyle: false,
            {{-- A switch must NEVER look saved when the write failed. r.ok is
                 checked as well as the JSON contract: a 403/419/500 (or a
                 production column that never got added) resolves fetch() just
                 like a success, so ignoring it leaves the owner staring at a
                 setting that silently reverts on the next page load. --}}
            saveTheme(t) {
                if (this.savingTheme || this.currentTheme === t) return;
                const prev = this.currentTheme;
                this.currentTheme = t; document.body.setAttribute('data-theme', t);
                this.savingTheme = true;
                const back = () => { this.currentTheme = prev; document.body.setAttribute('data-theme', prev); };
                fetch('{{ route('fbrpos.settings.theme', [], false) }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:t})})
                    .then(r => r.ok ? r.json() : null)
                    .then(d => { if (!d || d.success !== true) { back(); alert((d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}); } })
                    .catch(() => { back(); alert({{ Js::from(__('pos.setting_save_failed')) }}); })
                    .finally(() => { this.savingTheme = false; });
            },
            saveGuided() {
                if (this.savingGuided) return;
                const want = !this.guidedOn;
                this.guidedOn = want; this.savingGuided = true;
                fetch('{{ route('fbrpos.settings.guided-flow', [], false) }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:want})})
                    .then(r => r.ok ? r.json() : null)
                    .then(d => { if (!d || d.success !== true) { this.guidedOn = !want; alert((d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}); return; } this.guidedOn = !!d.enabled; })
                    .catch(() => { this.guidedOn = !want; alert({{ Js::from(__('pos.setting_save_failed')) }}); })
                    .finally(() => { this.savingGuided = false; });
            },
            saveStyle(s) {
                if (this.savingStyle || this.currentStyle === s) return;
                const prev = this.currentStyle;
                this.currentStyle = s; this.savingStyle = true;
                const fail = (m) => { this.currentStyle = prev; this.savingStyle = false; alert(m || {{ Js::from(__('pos.setting_save_failed')) }}); };
                fetch('{{ route('fbrpos.settings.dashboard-style', [], false) }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({style:s})})
                    .then(r => r.ok ? r.json() : null)
                    .then(d => { if (!d || d.success !== true) { fail(d && d.message); return; } window.location.reload(); })
                    .catch(() => { fail(null); });
            },
            {{-- ── Phase B: live search + scroll-spy nav ────────────────────────
                 q is the search box text. hit(kw) is the ONE filter primitive:
                 true when the box is empty, otherwise true when every space-
                 separated word the shopkeeper typed appears somewhere in the
                 card's keyword blob (title + description + Roman-Urdu synonyms).
                 It only drives x-show — it NEVER re-parents a form, so every POST
                 still submits exactly what it did before. searching is a cheap
                 flag the section headers/nav read. --}}
            q: '',
            activeSec: 'appearance',
            {{-- Keyword blobs baked server-side (title + desc + Roman-Urdu
                 synonyms). @js() JSON-encodes safely, so apostrophes / quotes /
                 Urdu script in the lang strings can never break the attribute. --}}
            kw: {
                appearance: @js($kwAppearance),
                features:   @js($kwFeatures),
                sale:       @js($kwSale),
                dayclose:   @js($kwDayclose),
                shortcuts:  @js($kwShortcuts),
                all:        @js($tnAllKw),
                theme:      @js($kw['theme']),
                guided:     @js($kw['guided']),
                whatsapp:   @js($kw['whatsapp']),
                dashboard:  @js($kw['dashboard']),
                language:   @js($kw['language']),
                storeslip:  @js($kw['storeslip']),
                delivery:   @js($kw['delivery']),
                storenote:  @js($kw['storenote']),
                quicktype:  @js($kw['quicktype']),
                cashrecv:   @js($kw['cashrecv']),
                autoclose:  @js($kw['autoclose']),
                autoslip:   @js($kw['autoslip']),
                slipreprint:@js($kw['slipreprint']),
                inventory:  @js($kw['inventory']),
                callerid:   @js($kw['callerid']),
                restock:    @js($kw['restock']),
                pending:    @js($kw['pending']),
                cashierdc:  @js($kw['cashierdc']),
                daycutoff:  @js($kw['daycutoff']),
            },
            get searching() { return this.q.trim() !== ''; },
            norm(s) { return (s || '').toString().toLowerCase(); },
            hit(kw) {
                const needle = this.norm(this.q).trim();
                if (needle === '') return true;
                const hay = this.norm(kw);
                return needle.split(/\s+/).every(w => hay.indexOf(w) !== -1);
            },
            clearSearch() { this.q = ''; if (this.$refs.custSearch) this.$refs.custSearch.focus(); },
            goSec(id) {
                this.q = '';
                this.$nextTick(() => { const el = document.getElementById(id); if (el) el.scrollIntoView({behavior:'smooth', block:'start'}); });
            },
            initSpy() {
                {{-- Scroll-spy: mark the section whose top is nearest the viewport
                     top as active. IntersectionObserver keeps it cheap on modest
                     shop hardware — no scroll-handler thrash. --}}
                const ids = ['appearance','features','sale-billing','dayclose','shortcuts'];
                const obs = new IntersectionObserver((entries) => {
                    entries.forEach(e => { if (e.isIntersecting) this.activeSec = e.target.id; });
                }, { rootMargin: '-96px 0px -60% 0px', threshold: 0 });
                ids.forEach(id => { const el = document.getElementById(id); if (el) obs.observe(el); });
            }
         }"
         x-init="initSpy()"
         @keydown.window.escape="if (searching) clearSearch()"
         class="max-w-5xl mx-auto p-4 sm:p-6 space-y-6">
    @include('fbr-pos.partials.back-link')

        {{-- ═══════════ HERO ═══════════ --}}
        <div class="rounded-2xl bg-blue-600 p-6 sm:p-8 text-white shadow-xl relative overflow-hidden">
            <div class="relative">
                <div class="flex items-center gap-2 mb-2">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white/20 backdrop-blur text-[10px] font-bold uppercase tracking-wider">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        {{ __('pos.fbr_pos_control_center') }}
                    </span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold mb-1.5">{{ __('pos.customize_fbr_pos') }}</h1>
                <p class="text-sm sm:text-base text-white/85 max-w-2xl">{!! __('pos.customize_hero_blurb') !!}</p>
            </div>
        </div>

        {{-- ═══════════ STICKY SECTION NAV + LIVE SEARCH (Phase B) ═══════════
             Sticks under the layout's top bar so a long page stays navigable.
             The nav chips scroll-spy the current section; the search box filters
             every setting card live (hide/show only). Nothing here writes. --}}
        @php
            $tnNav = [
                ['id' => 'appearance',   'label' => __('pos.appearance_experience')],
                ['id' => 'features',     'label' => __('pos.fbr_features_section')],
                ['id' => 'sale-billing', 'label' => __('pos.sec_sale_billing')],
                ['id' => 'dayclose',     'label' => __('pos.sec_local_bills_dayclose')],
                ['id' => 'shortcuts',    'label' => __('pos.cust_nav_shortcuts')],
            ];
        @endphp
        <div class="sticky top-2 z-30 rounded-2xl border border-gray-200 dark:border-gray-800 bg-white/90 dark:bg-gray-900/90 backdrop-blur shadow-sm p-2.5 space-y-2.5">
            {{-- Search --}}
            <div class="relative">
                <span class="absolute inset-y-0 left-3 flex items-center text-gray-400 pointer-events-none">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                </span>
                <input type="text" x-model="q" x-ref="custSearch"
                    autocomplete="off" name="customize_search_nofill" data-lpignore="true" data-form-type="other" data-1p-ignore
                    placeholder="{{ __('pos.cust_search_placeholder') }}"
                    aria-label="{{ __('pos.cust_search_placeholder') }}"
                    class="w-full pl-9 pr-9 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <button type="button" x-show="searching" x-cloak @click="clearSearch()"
                    aria-label="{{ __('pos.cust_search_clear') }}" title="{{ __('pos.cust_search_clear') }}"
                    class="absolute inset-y-0 right-2.5 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            {{-- Section chips (hidden while searching — the results speak for themselves) --}}
            {{-- Phone: ONE swipeable row (5 chips wrapped into 3 cramped rows at 390px).
                 sm+ keeps the old wrap. --}}
            <nav x-show="!searching" aria-label="{{ __('pos.cust_nav_label') }}" class="flex gap-1.5 overflow-x-auto pb-1 sm:flex-wrap sm:overflow-x-visible sm:pb-0">
                @foreach($tnNav as $tnN)
                <button type="button" @click="goSec('{{ $tnN['id'] }}')"
                    class="shrink-0 whitespace-nowrap px-3 py-1.5 rounded-full text-[12px] font-bold border transition"
                    :class="activeSec === '{{ $tnN['id'] }}' ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-blue-400'">
                    {{ $tnN['label'] }}
                </button>
                @endforeach
            </nav>
        </div>

        {{-- No-results empty state — shows only when a search matches nothing anywhere.
             The concatenated blob mirrors every card's keyword string below. --}}
        <div x-show="searching && !hit(kw.all)" x-cloak
             class="rounded-2xl border border-dashed border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 p-8 text-center">
            <div class="mx-auto w-12 h-12 rounded-2xl bg-gray-100 dark:bg-gray-800 text-gray-400 flex items-center justify-center mb-3">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
            </div>
            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.cust_search_empty_title') }}</p>
            <p class="text-[12px] text-gray-500 dark:text-gray-400 mt-1" x-text="@js(__('pos.cust_search_empty_sub')).replace(':q', q)"></p>
        </div>

        {{-- ═══════════ APPEARANCE & EXPERIENCE ═══════════ --}}
        <section id="appearance" x-show="hit(kw.appearance)">
            <div class="px-1 mb-3">
                <h2 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wide">{{ __('pos.appearance_experience') }}</h2>
                <p class="text-[12px] text-gray-500 dark:text-gray-400">{{ __('pos.appearance_desc') }}</p>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">

                {{-- Theme picker (6 themes) --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm" x-show="hit(kw.theme)">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.pos_theme') }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400" x-text="@js(__('pos.active_prefix')) + currentTheme.charAt(0).toUpperCase() + currentTheme.slice(1)"></p>
                        </div>
                    </div>
                    <div class="grid grid-cols-6 gap-2 mt-4">
                        @foreach($themes as $t => $g)
                        <button type="button"
                            @click="saveTheme('{{ $t }}')"
                            class="h-10 rounded-xl ring-2 ring-offset-2 ring-offset-white dark:ring-offset-gray-900 transition"
                            :class="currentTheme==='{{ $t }}' ? 'ring-blue-500' : 'ring-transparent'"
                            style="background:linear-gradient(135deg,{{ $g[0] }},{{ $g[1] }})"
                            title="{{ ucfirst($t) }}"></button>
                        @endforeach
                    </div>
                    {{-- Phase C: mini sale-screen preview — the header + tiles recolour
                         live as currentTheme changes. Illustration only; posts nothing.
                         Uses the same blue-X family the FBR layout theme-engine remaps,
                         so the swatch matches the real screen. --}}
                    <div class="mt-4 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden" aria-hidden="true">
                        <div class="px-3 py-2 flex items-center gap-2"
                             :style="'background:linear-gradient(135deg,' + ({@foreach($themes as $t => $g)'{{ $t }}':'{{ $g[0] }}',@endforeach}[currentTheme] || '#1e3a5f') + ',' + ({@foreach($themes as $t => $g)'{{ $t }}':'{{ $g[1] }}',@endforeach}[currentTheme] || '#2563eb') + ')'">
                            <span class="w-2 h-2 rounded-full bg-white/70"></span>
                            <span class="h-2 w-16 rounded-full bg-white/60"></span>
                            <span class="ml-auto h-2 w-8 rounded-full bg-white/40"></span>
                        </div>
                        <div class="grid grid-cols-3 gap-1.5 p-2.5 bg-gray-50 dark:bg-gray-800">
                            @for($i = 0; $i < 6; $i++)
                            <div class="h-7 rounded-lg border border-gray-200 dark:border-gray-700 flex items-center justify-center"
                                 :style="'background:' + ({@foreach($themes as $t => $g)'{{ $t }}':'{{ $g[1] }}',@endforeach}[currentTheme] || '#2563eb') + '22'">
                                <span class="h-1.5 w-6 rounded-full"
                                      :style="'background:' + ({@foreach($themes as $t => $g)'{{ $t }}':'{{ $g[1] }}',@endforeach}[currentTheme] || '#2563eb')"></span>
                            </div>
                            @endfor
                        </div>
                    </div>
                    <p class="text-[10px] text-gray-400 dark:text-gray-500 mt-1.5">{{ __('pos.cust_preview_theme_hint') }}</p>
                </div>

                {{-- Guided keyboard billing toggle --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3" x-show="hit(kw.guided)">
                    <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 7h14a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2V9a2 2 0 012-2z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 11h.01M11 11h.01M15 11h.01M7 14h10"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.guided_keyboard_billing') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.guided_billing_desc') }}</p>
                    </div>
                    <button type="button"
                        @click="saveGuided()" :disabled="savingGuided"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="guidedOn ? 'bg-blue-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="guidedOn && 'translate-x-6'"></span>
                    </button>
                </div>

                {{-- Task 1271: WhatsApp Bill (PRA Task 1036 port): receipt popup ka
                     WhatsApp button (default ON) + optional auto-open mode (default OFF).
                     Pro+ plan gate: locked below Pro. Shared company columns with PRA. --}}
                @php $tnWaPlanAllowed = \App\Services\PosFeatureService::planAllows($company, 'whatsapp_enabled'); @endphp
                <div x-data="{ waOn: {{ ($tnWaPlanAllowed && ($company->pos_whatsapp_bill_enabled ?? true)) ? 'true' : 'false' }}, waAutoOn: {{ ($company->pos_whatsapp_bill_auto_open ?? false) ? 'true' : 'false' }}, savingWa: false,
                        saveWa(payload, revert) { this.savingWa = true; fetch('{{ route('fbrpos.settings.whatsapp-bill-toggle', [], false) }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify(payload)}).then(r=>r.json()).then(d=>{ if (!d || d.success !== true) { revert(); alert((d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}); } }).catch(()=>{ revert(); alert({{ Js::from(__('pos.setting_save_failed')) }}); }).finally(()=>{ this.savingWa = false; }); } }"
                     x-show="hit(kw.whatsapp)"
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
                        <a href="{{ route('fbrpos.billing') }}" class="inline-flex items-center gap-1 text-[11px] font-bold text-sky-600 dark:text-sky-400 hover:underline">
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
                    {{-- Phase C: mini receipt preview — the green WhatsApp button on the
                         receipt popup appears/disappears as the toggle flips. Pure
                         illustration; no link, no POST. --}}
                    @if($tnWaPlanAllowed)
                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800" aria-hidden="true">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-1.5">{{ __('pos.cust_preview_label') }}</p>
                        <div class="mx-auto max-w-[200px] rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-950 p-2.5 shadow-sm">
                            <div class="flex justify-between text-[10px] text-gray-500 dark:text-gray-400">
                                <span>{{ __('pos.cust_preview_receipt_item') }}</span><span>Rs 590</span>
                            </div>
                            <div class="flex justify-between text-[11px] font-bold text-gray-900 dark:text-white mt-1 pt-1 border-t border-dashed border-gray-200 dark:border-gray-700">
                                <span>{{ __('pos.cust_preview_receipt_total') }}</span><span>Rs 590</span>
                            </div>
                            <div class="mt-2 w-full rounded-md py-1.5 text-center text-[10px] font-bold transition"
                                 :class="waOn ? 'bg-green-500 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-300 dark:text-gray-600 line-through'">
                                {{ __('pos.cust_preview_wa_chip') }}
                            </div>
                        </div>
                    </div>
                    @endif
                </div>

                {{-- Dashboard style picker (6 designs) --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm sm:col-span-2" x-show="hit(kw.dashboard)">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/></svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.dashboard_design') }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.dashboard_design_desc') }}</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                        @foreach($styles as $s)
                        <button type="button"
                            @click="saveStyle('{{ $s['id'] }}')"
                            class="w-full flex items-center gap-3 px-3 py-3 rounded-xl border transition-all"
                            :class="currentStyle === '{{ $s['id'] }}' ? 'bg-blue-100 dark:bg-blue-900/30 ring-2 ring-blue-500 border-transparent' : 'border-gray-200 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800'">
                            <span class="text-2xl w-9 text-center flex-shrink-0">{{ $s['icon'] }}</span>
                            <div class="flex-1 text-left min-w-0">
                                <p class="text-sm font-black text-gray-900 dark:text-white truncate">{{ $s['name'] }}</p>
                                <p class="text-[11px] text-gray-600 dark:text-gray-300 font-semibold truncate">{{ $s['desc'] }}</p>
                            </div>
                            <div class="flex gap-1 flex-shrink-0">
                                @foreach($s['colors'] as $c)
                                <span class="w-4 h-4 rounded-full border-2 border-gray-300 dark:border-gray-500" style="background: {{ $c }}"></span>
                                @endforeach
                            </div>
                            <span x-show="currentStyle === '{{ $s['id'] }}'" class="text-blue-600 flex-shrink-0" x-cloak>
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                            </span>
                        </button>
                        @endforeach
                    </div>
                </div>

                {{-- Shop default language (Task 1403). The endpoint existed since the
                     three-language rollout but had no UI in the FBR panel — an admin
                     could never set the shop-wide default. Plain form POST because the
                     route answers with back()->with('success'), not JSON. --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm" x-show="hit(kw.language)">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-600 dark:bg-indigo-900/30 dark:text-indigo-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.fbr_default_language_title') }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.fbr_default_language_sub') }}</p>
                        </div>
                    </div>
                    @php $tnLang = $company->default_language ?? 'rur'; @endphp
                    <form method="POST" action="{{ route('fbrpos.settings.default-language') }}" class="mt-3 flex flex-wrap gap-2">
                        @csrf
                        @foreach (['rur' => 'Roman Urdu', 'ur' => 'اردو', 'en' => 'English'] as $tnCode => $tnLabel)
                        <button type="submit" name="default_language" value="{{ $tnCode }}"
                            class="px-3.5 py-1.5 rounded-full text-xs font-bold border transition {{ $tnLang === $tnCode ? 'bg-indigo-600 border-indigo-600 text-white' : 'bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-indigo-400' }}">
                            {{ $tnLabel }}
                        </button>
                        @endforeach
                    </form>
                </div>
            </div>
        </section>

        {{-- ═══════════ FEATURES (Task 1403) ═══════════
             FBR-owned module switches. Before this the only writer of these flags
             was PRA's restaurant Kitchen Settings / the super-admin panel, so an FBR
             shop could never turn Store Slip or Delivery on for itself. --}}
        <section id="features" x-show="hit(kw.features)">
            <div class="px-1 mb-3">
                <h2 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wide">{{ __('pos.fbr_features_section') }}</h2>
                <p class="text-[12px] text-gray-500 dark:text-gray-400">{{ __('pos.fbr_features_section_sub') }}</p>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">

                {{-- Store Slip — companies.kitchen_printer_enabled --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm" x-show="hit(kw.storeslip)">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-orange-100 text-orange-600 dark:bg-orange-900/30 dark:text-orange-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.fbr_feat_store_slip_title') }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.fbr_feat_store_slip_sub') }}</p>
                        </div>
                        @if($fbrKotPlan || $fbrSlipLockedOn)
                        <button type="button" :disabled="savingStoreSlip || (slipOffOnly && !storeSlipOn)" @click="featSave('store_slip', 'storeSlipOn', 'savingStoreSlip')"
                            class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200 disabled:opacity-60" :class="storeSlipOn ? 'bg-orange-500' : 'bg-gray-300 dark:bg-gray-600'">
                            <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="storeSlipOn && 'translate-x-6'"></span>
                        </button>
                        @else
                        <span class="shrink-0 inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">🔒</span>
                        @endif
                    </div>
                    @unless($fbrKotPlan)
                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800 space-y-1">
                        <p class="text-[11px] font-bold text-amber-600 dark:text-amber-400">{{ __('pos.plan_locked_feature') }}</p>
                        @if($fbrSlipLockedOn)<p x-show="storeSlipOn" class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.plan_locked_off_only') }}</p>@endif
                        <a href="{{ route('fbrpos.billing') }}" class="inline-flex items-center gap-1 text-[11px] font-bold text-blue-600 dark:text-blue-400 hover:underline">
                            <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                            {{ __('pos.upgrade_plan_btn') }}
                        </a>
                    </div>
                    @else
                    <div x-show="storeSlipOn" x-cloak class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800">
                        <a href="{{ route('fbrpos.printer-settings') }}" class="inline-flex items-center gap-1 text-[11px] font-bold text-blue-600 dark:text-blue-400 hover:underline">
                            <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                            {{ __('pos.printer_settings') }}
                        </a>
                    </div>
                    {{-- Phase C: mini store-slip preview — a torn thermal slip that only
                         appears while Store Slip is ON, and grows a per-item note line
                         when the Store-note switch is ON. Reads the live Alpine flags;
                         posts nothing. --}}
                    <div x-show="storeSlipOn" x-cloak class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800" aria-hidden="true">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-1.5">{{ __('pos.cust_preview_label') }}</p>
                        <div class="mx-auto max-w-[180px] rounded-md bg-white dark:bg-gray-950 border border-gray-200 dark:border-gray-700 p-2.5 font-mono shadow-sm">
                            <p class="text-[10px] font-bold text-center text-gray-900 dark:text-white border-b border-dashed border-gray-300 dark:border-gray-600 pb-1 mb-1">{{ __('pos.cust_preview_slip_title') }}</p>
                            <div class="flex justify-between text-[10px] text-gray-800 dark:text-gray-200">
                                <span>1x {{ __('pos.cust_preview_receipt_item') }}</span>
                            </div>
                            <p x-show="storeNoteOn" x-cloak class="text-[9px] italic text-gray-500 dark:text-gray-400 pl-3">&mdash; {{ __('pos.cust_preview_slip_note') }}</p>
                        </div>
                    </div>
                    @endunless
                </div>

                {{-- Delivery & Riders — feature_flags.delivery (forces customer_profile on) --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm" x-show="hit(kw.delivery)">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.fbr_feat_delivery_title') }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.fbr_feat_delivery_sub') }}</p>
                        </div>
                        @if($fbrRidersPlan || $fbrDelivLockedOn)
                        <button type="button" :disabled="savingDelivery || (delivOffOnly && !deliveryOn)" @click="featSave('delivery', 'deliveryOn', 'savingDelivery')"
                            class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200 disabled:opacity-60" :class="deliveryOn ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600'">
                            <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="deliveryOn && 'translate-x-6'"></span>
                        </button>
                        @else
                        <span class="shrink-0 inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">🔒</span>
                        @endif
                    </div>
                    @unless($fbrRidersPlan)
                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800 space-y-1">
                        <p class="text-[11px] font-bold text-amber-600 dark:text-amber-400">{{ __('pos.plan_locked_feature') }}</p>
                        @if($fbrDelivLockedOn)<p x-show="deliveryOn" class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.plan_locked_off_only') }}</p>@endif
                        <a href="{{ route('fbrpos.billing') }}" class="inline-flex items-center gap-1 text-[11px] font-bold text-blue-600 dark:text-blue-400 hover:underline">
                            <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                            {{ __('pos.upgrade_plan_btn') }}
                        </a>
                    </div>
                    @endunless
                </div>

                {{-- Per-item Store note — feature_flags.kitchen_notes (needs the slip) --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm" x-show="hit(kw.storenote)">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-violet-100 text-violet-600 dark:bg-violet-900/30 dark:text-violet-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.fbr_feat_store_notes_title') }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.fbr_feat_store_notes_sub') }}</p>
                        </div>
                        @if($fbrKotPlan || $fbrNoteLockedOn)
                        <button type="button" :disabled="savingStoreNote || (!storeSlipOn && !storeNoteOn) || (noteOffOnly && !storeNoteOn)" @click="featSave('store_notes', 'storeNoteOn', 'savingStoreNote')"
                            class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200 disabled:opacity-40" :class="storeNoteOn ? 'bg-violet-500' : 'bg-gray-300 dark:bg-gray-600'">
                            <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="storeNoteOn && 'translate-x-6'"></span>
                        </button>
                        @else
                        <span class="shrink-0 inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">🔒</span>
                        @endif
                    </div>
                    @unless($fbrKotPlan)
                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800 space-y-1">
                        <p class="text-[11px] font-bold text-amber-600 dark:text-amber-400">{{ __('pos.plan_locked_feature') }}</p>
                        @if($fbrNoteLockedOn)<p x-show="storeNoteOn" class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.plan_locked_off_only') }}</p>@endif
                        <a href="{{ route('fbrpos.billing') }}" class="inline-flex items-center gap-1 text-[11px] font-bold text-blue-600 dark:text-blue-400 hover:underline">
                            <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                            {{ __('pos.upgrade_plan_btn') }}
                        </a>
                    </div>
                    @else
                    <div x-show="!storeSlipOn" x-cloak class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800">
                        <p class="text-[11px] font-bold text-amber-600 dark:text-amber-400">{{ __('pos.fbr_feat_needs_store_slip') }}</p>
                    </div>
                    @endunless
                </div>
            </div>
        </section>

        {{-- ═══════════ SALE SCREEN & BILLING (Task 1263 — PRA parity toggles) ═══════════ --}}
        <section id="sale-billing" x-show="hit(kw.sale)">
            <div class="px-1 mb-3">
                <h2 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wide">{{ __('pos.sec_sale_billing') }}</h2>
                <p class="text-[12px] text-gray-500 dark:text-gray-400">{{ __('pos.sec_sale_billing_sub') }}</p>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">

                {{-- Quick Type Mode — OPT-IN (default OFF, mirrors PRA) --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3" x-show="hit(kw.quicktype)">
                    <div class="w-10 h-10 rounded-xl bg-sky-100 text-sky-600 dark:bg-sky-900/30 dark:text-sky-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.quick_type_mode') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.quick_type_mode_sub') }}</p>
                    </div>
                    <button type="button"
                        @click="quickOn=!quickOn; savingQuick=true; fetch('/fbr-pos/settings/quick-type', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:quickOn})}).then(r=>r.json()).then(d=>{ if (!d || d.success !== true) { quickOn=!quickOn; alert((d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}); } }).catch(()=>{ quickOn=!quickOn; alert({{ Js::from(__('pos.setting_save_failed')) }}); }).finally(()=>{ savingQuick=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="quickOn ? 'bg-sky-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="quickOn && 'translate-x-6'"></span>
                    </button>
                </div>

                {{-- Cash Received / Change box in Pay popup --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm" x-show="hit(kw.cashrecv)">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.cash_received_toggle') }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.cash_received_toggle_sub') }}</p>
                        </div>
                        <button type="button"
                            @click="cashRecvOn=!cashRecvOn; savingCashRecv=true; fetch('/fbr-pos/settings/cash-received-toggle', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:cashRecvOn})}).then(r=>r.json()).then(d=>{ if (!d || d.success !== true) { cashRecvOn=!cashRecvOn; alert((d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}); } }).catch(()=>{ cashRecvOn=!cashRecvOn; alert({{ Js::from(__('pos.setting_save_failed')) }}); }).finally(()=>{ savingCashRecv=false; })"
                            class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="cashRecvOn ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600'">
                            <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="cashRecvOn && 'translate-x-6'"></span>
                        </button>
                    </div>
                    {{-- Phase C: the Pay popup gains a Cash-received + Change line only
                         when this is ON. Illustration with fixed sample numbers; nothing
                         is posted. --}}
                    <div x-show="cashRecvOn" x-cloak class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800" aria-hidden="true">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-1.5">{{ __('pos.cust_preview_label') }}</p>
                        <div class="mx-auto max-w-[200px] rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-2.5 space-y-1">
                            <div class="flex justify-between text-[11px] font-bold text-gray-900 dark:text-white"><span>{{ __('pos.cust_preview_receipt_total') }}</span><span>Rs 590</span></div>
                            <div class="flex justify-between text-[11px] text-emerald-700 dark:text-emerald-400"><span>{{ __('pos.cust_preview_cash_line') }}</span><span>Rs 1,000</span></div>
                            <div class="flex justify-between text-[11px] font-bold text-gray-900 dark:text-white pt-1 border-t border-dashed border-gray-200 dark:border-gray-700"><span>{{ __('pos.cust_preview_change_line') }}</span><span>Rs 410</span></div>
                        </div>
                    </div>
                </div>

                {{-- Receipt popup auto-close (default 10 sec) --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm" x-show="hit(kw.autoclose)">
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
                    {{-- Phase C: a one-line worked example that says, in words, what the
                         chosen number means. Reads rcSecs live; posts nothing. --}}
                    <p class="mt-2.5 text-[11px] font-semibold text-teal-700 dark:text-teal-400"
                       x-text="rcSecs > 0 ? @js(__('pos.cust_preview_autoclose_on')).replace(':n', rcSecs) : @js(__('pos.cust_preview_autoclose_off'))"></p>
                </div>

                @if($fbrKotPlan)
                {{-- Auto Store Slip after payment (existing auto-KOT endpoint, Store-branded for FBR — Task 1285).
                     Task 1403: was server-rendered behind @if($company->kitchen_printer_enabled) — now bound to the
                     Alpine flag so flipping Store Slip in the Features card shows/hides these two cards live.
                     The package gate stays server-side: the sale screen and both endpoints already refuse
                     kot_enabled-less shops, so showing these switches there would be a lie. --}}
                <div x-show="storeSlipOn && hit(kw.autoslip)" x-cloak class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-orange-100 text-orange-600 dark:bg-orange-900/30 dark:text-orange-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.fbr_auto_store_slip') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.fbr_ti_auto_store_slip_hint') }}</p>
                    </div>
                    <button type="button"
                        @click="autoKotOn=!autoKotOn; savingAutoKot=true; fetch('/fbr-pos/api/toggle-auto-kot', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'}}).then(r=>r.json()).then(d=>{ if (d && d.success) { autoKotOn = !!d.enabled; } else { autoKotOn=!autoKotOn; alert((d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}); } }).catch(()=>{ autoKotOn=!autoKotOn; alert({{ Js::from(__('pos.setting_save_failed')) }}); }).finally(()=>{ savingAutoKot=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="autoKotOn ? 'bg-orange-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="autoKotOn && 'translate-x-6'"></span>
                    </button>
                </div>

                {{-- Store-slip reprint permission (kot_reprint_enabled column, Store-branded for FBR) --}}
                <div x-show="storeSlipOn && hit(kw.slipreprint)" x-cloak class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-rose-100 text-rose-600 dark:bg-rose-900/30 dark:text-rose-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.fbr_store_slip_reprint_toggle_title') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.fbr_store_slip_reprint_toggle_sub') }}</p>
                    </div>
                    <button type="button"
                        @click="kotReprintOn=!kotReprintOn; savingKotReprint=true; fetch('/fbr-pos/settings/kot-reprint-toggle', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:kotReprintOn})}).then(r=>r.json()).then(d=>{ if (!d || d.success !== true) { kotReprintOn=!kotReprintOn; alert((d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}); } }).catch(()=>{ kotReprintOn=!kotReprintOn; alert({{ Js::from(__('pos.setting_save_failed')) }}); }).finally(()=>{ savingKotReprint=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="kotReprintOn ? 'bg-rose-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="kotReprintOn && 'translate-x-6'"></span>
                    </button>
                </div>
                @endif

                {{-- Inventory tracking on/off (dual-switch synced server-side) --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3" x-show="hit(kw.inventory)">
                    <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.inventory_tracking') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.inventory_tracking_sub') }}</p>
                    </div>
                    <button type="button"
                        @click="invOn=!invOn; savingInv=true; fetch('/fbr-pos/settings/inventory-toggle', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:invOn})}).then(r=>r.json()).then(d=>{ if (!d || d.success !== true) { invOn=!invOn; alert((d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}); } }).catch(()=>{ invOn=!invOn; alert({{ Js::from(__('pos.setting_save_failed')) }}); }).finally(()=>{ savingInv=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="invOn ? 'bg-blue-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="invOn && 'translate-x-6'"></span>
                    </button>
                </div>

                {{-- Caller ID (Task 1353 — FBR twin of the PRA customize card):
                     Android companion app + sale-screen popup. Card-local Alpine
                     state; toggle POST follows the inventory-toggle pattern.
                     Status lines read the company columns directly (hasColumn-
                     guarded for prod schema drift). Download buttons appear only
                     once the SystemSetting version AND the hosted APK both exist
                     (APKs are scp'd to live public/downloads, never committed —
                     repo is public) so a button can never 404. --}}
                @php
                    $tnCallerReady = \Illuminate\Support\Facades\Schema::hasColumn('companies', 'caller_id_enabled');
                    $tnCallerOn = $tnCallerReady && ($company->caller_id_enabled ?? false);
                    $tnCallerUser = ($tnCallerReady && ($company->caller_app_user_id ?? null)) ? \App\Models\User::find($company->caller_app_user_id) : null;
                    $tnCallerSeen = ($tnCallerReady && ($company->caller_app_last_seen_at ?? null)) ? \Carbon\Carbon::parse($company->caller_app_last_seen_at) : null;
                    $tnCallerLastEvent = ($tnCallerReady && \Illuminate\Support\Facades\Schema::hasTable('pos_caller_events'))
                        ? \Illuminate\Support\Facades\DB::table('pos_caller_events')->where('company_id', $company->id)->orderByDesc('id')->value('created_at')
                        : null;
                    $tnCallerApkLive = trim((string) \App\Models\SystemSetting::get('caller_app_latest_version', '')) !== ''
                        && is_file(public_path('downloads/taxnest-caller.apk'));
                    // Default download = the "clean" build (SIM calls only, free of
                    // the four permissions Play Protect blocks). The WhatsApp
                    // ("plus") build has its own gate and shows in its own section
                    // with the Play Protect off/on steps, so the default button
                    // never makes a false WhatsApp promise.
                    $tnCallerPlusApkLive = trim((string) \App\Models\SystemSetting::get('caller_app_plus_latest_version', '')) !== ''
                        && is_file(public_path('downloads/taxnest-caller-plus.apk'));
                    // Unlimited gate (owner, 17 Aug 2026): Caller ID is plan-locked.
                    $tnCallerPlanAllowed = \App\Services\PosFeatureService::planAllows($company, 'caller_id_enabled');
                    // Multi-device rows — the legacy companies-row phone shows
                    // alongside as a 'legacy' pseudo-row so an old pairing stays
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
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm" x-data="{ callerOn: {{ $tnCallerOn ? 'true' : 'false' }} }" x-show="hit(kw.callerid)">
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
                            @click="callerOn=!callerOn; fetch('/fbr-pos/settings/caller-id', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:callerOn})}).catch(()=>{})"
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
                            <a href="{{ route('fbrpos.billing') }}" class="inline-flex items-center gap-1 text-[11px] font-bold text-sky-600 dark:text-sky-400 hover:underline">
                                <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                                {{ __('pos.upgrade_plan_btn') }}
                            </a>
                        @endunless
                        @if(count($tnCallerDevices))
                            @foreach($tnCallerDevices as $tnCd)
                                {{-- The row disappears only AFTER the server confirms the revoke
                                     (this endpoint answers {ok:…}, not {success:…}). Hiding it on
                                     click told the owner a device was cut off while it kept
                                     receiving calls. --}}
                                <div class="flex items-center gap-2 text-[11px] text-gray-600 dark:text-gray-300" x-show="!revoked"
                                     x-data="{ revoked: false, busyCd: false,
                                        revokeCd(id) {
                                            if (this.busyCd) return;
                                            if (!confirm({{ Js::from(__('pos.caller_dev_revoke_confirm')) }})) return;
                                            this.busyCd = true;
                                            fetch('{{ route('fbrpos.settings.caller-devices.revoke', [], false) }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({device_id: id})})
                                                .then(r => r.ok ? r.json() : null)
                                                .then(d => { if (!d || d.ok !== true) { alert({{ Js::from(__('pos.setting_save_failed')) }}); return; } this.revoked = true; })
                                                .catch(() => { alert({{ Js::from(__('pos.setting_save_failed')) }}); })
                                                .finally(() => { this.busyCd = false; });
                                        } }">
                                    <span class="inline-flex items-center gap-1 shrink-0 px-1.5 py-0.5 rounded-full text-[9px] font-bold {{ $tnCd['online'] ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' }}">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $tnCd['online'] ? 'bg-emerald-500' : 'bg-gray-400' }}"></span>
                                        {{ $tnCd['online'] ? __('pos.caller_dev_online') : __('pos.caller_dev_offline') }}
                                    </span>
                                    <span class="truncate min-w-0">
                                        <span class="font-bold">{{ $tnCd['user'] }}</span>{{ $tnCd['device'] !== '' ? ' · ' . $tnCd['device'] : '' }}
                                        @if($tnCd['seen']) · {{ __('pos.caller_id_last_seen') }}: {{ $tnCd['seen'] }} @endif
                                    </span>
                                    <button type="button"
                                        @click="revokeCd('{{ $tnCd['id'] }}')" :disabled="busyCd"
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

                {{-- Restock on bill delete / edit (only meaningful with inventory ON) --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3" x-show="invOn && hit(kw.restock)" x-cloak>
                    <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.restock_on_void') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.restock_on_void_sub') }}</p>
                    </div>
                    <button type="button"
                        @click="restockOn=!restockOn; savingRestock=true; fetch('/fbr-pos/settings/restock-toggle', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:restockOn})}).then(r=>r.json()).then(d=>{ if (!d || d.success !== true) { restockOn=!restockOn; alert((d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}); } }).catch(()=>{ restockOn=!restockOn; alert({{ Js::from(__('pos.setting_save_failed')) }}); }).finally(()=>{ savingRestock=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="restockOn ? 'bg-amber-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="restockOn && 'translate-x-6'"></span>
                    </button>
                </div>
            </div>
        </section>

        {{-- ═══════════ LOCAL BILLS & DAY-CLOSE ═══════════
             Mirrors the PRA Customize page's own Day-Close section: the
             day-close-shaped settings live together here instead of being
             scattered across Sale & Billing and the FBR Settings page.
             The auto-close + cutoff controls themselves stay on the Day Close
             page (admin/manager gated) — deep-linked, never cloned, so the two
             copies of one setting can never drift. --}}
        <section id="dayclose" x-show="hit(kw.dayclose)">
            <div class="px-1 mb-3">
                <h2 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wide">{{ __('pos.sec_local_bills_dayclose') }}</h2>
                <p class="text-[12px] text-gray-500 dark:text-gray-400">{{ __('pos.sec_local_bills_dayclose_sub') }}</p>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">

                {{-- Pending Bills at Day Close — FBR mirror of the PRA 'Khud Final'
                     policy (Aug 2026). MOVED here from the FBR Settings page: it is a
                     day-close decision, so it belongs with the day-close controls.
                     Same POST target (fbrpos.settings), same presence marker
                     (dayclose_pending_update) and same input (pending_policy). --}}
                @php $pendingPolicy = ($company->pos_dayclose_provisional_action === 'finalize') ? 'finalize' : 'carry'; @endphp
                <div class="sm:col-span-2 rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm" x-show="hit(kw.pending)">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-600 dark:bg-teal-900/30 dark:text-teal-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.fbr_dayclose_pending_title') }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.fbr_dayclose_pending_desc') }}</p>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('fbrpos.settings') }}" class="space-y-3">
                        @csrf
                        <input type="hidden" name="dayclose_pending_update" value="1">
                        <label class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition {{ $pendingPolicy === 'carry' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700' }}">
                            <input type="radio" name="pending_policy" value="carry" {{ $pendingPolicy === 'carry' ? 'checked' : '' }} class="mt-0.5 text-blue-600 focus:ring-blue-500">
                            <span>
                                <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ __('pos.fbr_dayclose_carry') }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('pos.fbr_dayclose_carry_sub') }}</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition {{ $pendingPolicy === 'finalize' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700' }}">
                            <input type="radio" name="pending_policy" value="finalize" {{ $pendingPolicy === 'finalize' ? 'checked' : '' }} class="mt-0.5 text-blue-600 focus:ring-blue-500">
                            <span>
                                <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ __('pos.fbr_dayclose_finalize') }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('pos.fbr_dayclose_finalize_sub') }}</span>
                            </span>
                        </label>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 transition">{{ __('pos.save_btn') }}</button>
                    </form>
                </div>

                {{-- Cashier can run Day Close (default OFF — admin/manager work).
                     MOVED here from Sale & Billing to sit with the other day-close
                     controls, exactly as the PRA Customize page groups it. Same
                     endpoint + input as before. --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3" x-show="hit(kw.cashierdc)">
                    <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.cashier_dayclose_title') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.cashier_dayclose_sub') }}</p>
                    </div>
                    <button type="button"
                        @click="cashierDcOn=!cashierDcOn; savingCdc=true; fetch('/fbr-pos/settings/cashier-dayclose-toggle', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:cashierDcOn})}).then(r=>r.json()).then(d=>{ if (!d || d.success !== true) { cashierDcOn=!cashierDcOn; alert((d && d.message) || {{ Js::from(__('pos.setting_save_failed')) }}); } }).catch(()=>{ cashierDcOn=!cashierDcOn; alert({{ Js::from(__('pos.setting_save_failed')) }}); }).finally(()=>{ savingCdc=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="cashierDcOn ? 'bg-amber-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="cashierDcOn && 'translate-x-6'"></span>
                    </button>
                </div>

                {{-- Auto day-close + cutoff live on the Day Close page (admin/manager
                     gated). Deep-link to them instead of cloning the toggles — two
                     copies of one setting drift. --}}
                <a href="{{ route('fbrpos.day-close') }}#dayclose-settings" x-show="hit(kw.daycutoff)" class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3 hover:border-teal-400 dark:hover:border-teal-600 hover:shadow-md transition group">
                    <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-600 dark:bg-teal-900/30 dark:text-teal-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.day_cutoff_title') }}</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.fbr_card_dayclose_settings_desc') }}</p>
                    </div>
                    <svg class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-teal-500 group-hover:translate-x-0.5 transition shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
            </div>
        </section>

        {{-- ═══════════ CARD SECTIONS (hub / shortcuts) ═══════════
             One #shortcuts anchor wraps all three card groups so the scroll-spy
             nav has a single target. Each card still filters on its own keyword
             blob (label + description) so a search inside the hub works too. --}}
        <div id="shortcuts" x-show="hit(kw.shortcuts)" class="space-y-6">
        @foreach($sections as $sec)
        @php $tnSecKw = $sec['title'] . ' ' . $sec['desc']; foreach ($sec['items'] as $tnI) { $tnSecKw .= ' ' . ($tnI['label'] ?? '') . ' ' . ($tnI['desc'] ?? ''); } @endphp
        <section x-show="hit(@js(trim($tnSecKw)))">
            <div class="px-1 mb-3">
                <h2 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wide">{{ $sec['title'] }}</h2>
                <p class="text-[12px] text-gray-500 dark:text-gray-400">{{ $sec['desc'] }}</p>
            </div>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach($sec['items'] as $c)
                @php $tn = $tones[$c['tone']] ?? $tones['blue']; @endphp
                <a href="{{ $c['url'] }}" x-show="hit(@js(trim(($c['label'] ?? '') . ' ' . ($c['desc'] ?? '') . ' ' . ($c['badge'] ?? ''))))" class="group flex items-center gap-3 p-4 rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 hover:border-blue-400 dark:hover:border-blue-600 hover:shadow-md transition">
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
                    <svg class="w-4 h-4 text-gray-300 dark:text-gray-600 group-hover:text-blue-500 group-hover:translate-x-0.5 transition shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
                @endforeach
            </div>
        </section>
        @endforeach
        </div>

        <div class="pt-2 text-center">
            <a href="{{ route('fbrpos.dashboard') }}" class="inline-flex items-center gap-1.5 text-[12px] font-semibold text-gray-500 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                {{ __('pos.back_to_dashboard') }}
            </a>
        </div>
    </div>
</x-fbr-pos-layout>
