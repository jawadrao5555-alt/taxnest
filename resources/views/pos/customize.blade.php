<x-pos-layout>
    @php
        $density = $company->pos_ui_density ? ucfirst($company->pos_ui_density) : 'Standard';
        $praOn   = (bool) ($company->pra_reporting_enabled ?? false);
        $agentOn = (bool) ($company->agent_enabled ?? false);
        $invOn   = (bool) ($company->inventory_enabled ?? false);

        // Card sections — every POS feature reachable from this one hub.
        $sections = [
            [
                'title' => 'Setup & Features',
                'desc'  => 'Modules, presets, business info and compliance',
                'items' => [
                    ['label' => 'Modules & Features', 'desc' => 'Har feature ka apna toggle — tables, kitchen, barcode, recipes & more', 'url' => route('pos.features'), 'tone' => 'purple', 'badge' => $density, 'icon' => 'M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4'],
                    ['label' => 'Business Profile', 'desc' => 'Store name, NTN, logo, address & contact details', 'url' => route('pos.business-profile'), 'tone' => 'purple', 'badge' => 'Identity', 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
                    ['label' => 'Receipt Display', 'desc' => 'Receipt par address, NTN, phone & footer message ka control', 'url' => route('pos.receipt-settings'), 'tone' => 'purple', 'badge' => 'Receipt', 'icon' => 'M9 17v-2a2 2 0 012-2h2a2 2 0 012 2v2m-6 4h6a2 2 0 002-2V7a2 2 0 00-2-2H9a2 2 0 00-2 2v12a2 2 0 002 2z'],
                    ['label' => 'PRA Compliance', 'desc' => 'PRA fiscal reporting & device credentials', 'url' => route('pos.pra-settings'), 'tone' => $praOn ? 'emerald' : 'amber', 'badge' => $praOn ? 'PRA ON' : 'PRA OFF', 'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
                ],
            ],
            [
                'title' => 'Operations',
                'desc'  => 'Services, registers and staff',
                'items' => [
                    ['label' => 'Services', 'desc' => 'Add-on services & extra charges', 'url' => route('pos.services'), 'tone' => 'purple', 'badge' => 'Manage', 'icon' => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10'],
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
    @endphp

    <div x-data="{ currentTheme: '{{ $company->pos_theme ?? 'purple' }}', guidedOn: {{ ($company->pos_guided_flow_enabled ?? true) ? 'true' : 'false' }}, savingGuided: false, invOn: {{ $invOn ? 'true' : 'false' }}, savingInv: false, restockOn: {{ ($company->pos_restock_on_void ?? true) ? 'true' : 'false' }}, savingRestock: false, autoPurgeOn: {{ ($company->pos_auto_purge_local_on_dayclose ?? false) ? 'true' : 'false' }}, savingPurge: false, autoDaycloseOn: {{ ($company->pos_auto_dayclose_24h ?? false) ? 'true' : 'false' }}, savingDayclose: false }"
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
            </div>
        </section>

        {{-- ═══════════ LOCAL BILLS & DAY-CLOSE ═══════════ --}}
        <section>
            <div class="px-1 mb-3">
                <h2 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wide">Local Bills &amp; Day-Close</h2>
                <p class="text-[12px] text-gray-500 dark:text-gray-400">Provisional / local bills ka day-close par kya ho — yahin se control karein</p>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">

                {{-- Auto-archive local bills on day-close --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-600 dark:bg-teal-900/30 dark:text-teal-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">Day-close par local bills archive</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">Har day-close par local/provisional bills khud archive ho jayein — data safe rehta hai, delete nahi hota</p>
                    </div>
                    <button type="button"
                        @click="autoPurgeOn=!autoPurgeOn; savingPurge=true; fetch('/pos/settings/auto-purge-local-toggle', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:autoPurgeOn})}).then(r=>r.json()).catch(()=>{}).finally(()=>{ savingPurge=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="autoPurgeOn ? 'bg-teal-600' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="autoPurgeOn && 'translate-x-6'"></span>
                    </button>
                </div>

                {{-- Auto day-close at next midnight (1 full day grace) --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-600 dark:bg-teal-900/30 dark:text-teal-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">Auto day-close — raat 12 baje</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">Din manually close na ho to system khud band kar dega — us din ke poore ek din baad, agli raat 12 baje. (Misaal: Somwaar ka din Mangal ki raat 12 baje band)</p>
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
