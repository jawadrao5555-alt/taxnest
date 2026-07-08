<x-fbr-pos-layout>
    @php
        $universalOn = (bool) ($company->fbr_universal_enabled ?? false);
        $fbrOn       = (bool) ($company->fbr_pos_enabled ?? false);
        $reportingOn = (bool) ($company->fbr_reporting_enabled ?? false);

        // Card sections — every FBR POS setting reachable from this one hub.
        $sections = [
            [
                'title' => 'Setup & Compliance',
                'desc'  => 'Business info, FBR integration and product catalog',
                'items' => [
                    ['label' => 'Business Profile', 'desc' => 'Store name, NTN, logo, address & contact details', 'url' => route('fbrpos.business-profile'), 'tone' => 'blue', 'badge' => 'Identity', 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
                    ['label' => 'FBR Settings', 'desc' => 'FBR fiscal integration, POS token & confidential PIN', 'url' => route('fbrpos.settings'), 'tone' => $fbrOn ? 'emerald' : 'amber', 'badge' => $fbrOn ? 'FBR ON' : 'FBR OFF', 'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
                    ['label' => 'Products', 'desc' => 'Manzoor shudah HS codes & per-product tax rate ka catalog', 'url' => route('fbrpos.products'), 'tone' => 'blue', 'badge' => 'Catalog', 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
                ],
            ],
            [
                'title' => 'Operations',
                'desc'  => 'Counters, shifts, promotions and loyalty',
                'items' => [
                    ['label' => 'Terminals', 'desc' => 'Multi-counter registers & device terminals', 'url' => route('fbrpos.phase2.terminals'), 'tone' => 'blue', 'badge' => 'Manage', 'icon' => 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
                    ['label' => 'Shifts & Cash Drawer', 'desc' => 'Open/close shifts & cash movement tracking', 'url' => route('fbrpos.phase2.shifts'), 'tone' => 'blue', 'badge' => 'Manage', 'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
                    ['label' => 'Promotions', 'desc' => 'Discount rules & promo codes', 'url' => route('fbrpos.phase2.promotions'), 'tone' => 'blue', 'badge' => 'Manage', 'icon' => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'],
                    ['label' => 'Loyalty Program', 'desc' => 'Customer points & rewards settings', 'url' => route('fbrpos.phase2.loyalty'), 'tone' => 'blue', 'badge' => 'Manage', 'icon' => 'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z'],
                ],
            ],
            [
                'title' => 'Reports & Account',
                'desc'  => 'Day close, insights, tax filing and your account',
                'items' => [
                    ['label' => 'Day Close (Z-Report)', 'desc' => 'End-of-day totals & Z-report generation', 'url' => route('fbrpos.day-close'), 'tone' => 'blue', 'badge' => 'Z-Report', 'icon' => 'M9 17v-2a2 2 0 012-2h2a2 2 0 012 2v2m-6 4h6a2 2 0 002-2V7a2 2 0 00-2-2H9a2 2 0 00-2 2v12a2 2 0 002 2z'],
                    ['label' => 'Reports', 'desc' => 'Sales insights & business intelligence', 'url' => route('fbrpos.reports'), 'tone' => 'blue', 'badge' => 'Insights', 'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
                    ['label' => 'Tax Reports', 'desc' => 'FBR tax summaries for filing', 'url' => route('fbrpos.tax-reports'), 'tone' => $reportingOn ? 'emerald' : 'blue', 'badge' => 'Tax', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
                    ['label' => 'Billing & Plan', 'desc' => 'Subscription, plan & invoices', 'url' => route('fbrpos.billing'), 'tone' => 'blue', 'badge' => 'Plan', 'icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
                    ['label' => 'My Profile', 'desc' => 'Your login, name & password', 'url' => route('fbrpos.my-profile'), 'tone' => 'blue', 'badge' => 'Account', 'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
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

        $styles = [
            ['id' => 'default', 'name' => 'Square Classic', 'desc' => 'Clean & minimal', 'icon' => '◻', 'colors' => ['#f3f4f6','#e5e7eb','#d1d5db']],
            ['id' => 'toast', 'name' => 'Toast Analytics', 'desc' => 'Data-rich insights', 'icon' => '📊', 'colors' => ['#fbbf24','#f59e0b','#d97706']],
            ['id' => 'lightspeed', 'name' => 'Lightspeed Grid', 'desc' => 'Colorful tiles', 'icon' => '⚡', 'colors' => ['#8b5cf6','#6366f1','#4f46e5']],
            ['id' => 'clover', 'name' => 'Clover Insights', 'desc' => 'Card analytics', 'icon' => '🍀', 'colors' => ['#22c55e','#16a34a','#15803d']],
            ['id' => 'oscar', 'name' => 'Oscar Pakistan', 'desc' => 'Tax compliance', 'icon' => '🇵🇰', 'colors' => ['#0ea5e9','#0284c7','#0369a1']],
            ['id' => 'shopify', 'name' => 'Shopify Modern', 'desc' => 'Ultra premium', 'icon' => '✨', 'colors' => ['#1e293b','#334155','#475569']],
        ];
    @endphp

    <div x-data="{
            currentTheme: '{{ $company->pos_theme ?? 'blue' }}',
            currentStyle: '{{ $company->pos_dashboard_style ?? 'default' }}',
            guidedOn: {{ ($company->pos_guided_flow_enabled ?? true) ? 'true' : 'false' }}, savingGuided: false,
            universalOn: {{ $universalOn ? 'true' : 'false' }}, savingUniversal: false, uniMsg: ''
         }"
         class="max-w-5xl mx-auto p-4 sm:p-6 space-y-6">

        {{-- ═══════════ HERO ═══════════ --}}
        <div class="rounded-2xl bg-blue-600 p-6 sm:p-8 text-white shadow-xl relative overflow-hidden">
            <div class="relative">
                <div class="flex items-center gap-2 mb-2">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white/20 backdrop-blur text-[10px] font-bold uppercase tracking-wider">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        FBR POS Control Center
                    </span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold mb-1.5">Customize FBR POS</h1>
                <p class="text-sm sm:text-base text-white/85 max-w-2xl">Aapki FBR POS ki saari settings ab ek hi jagah. Look &amp; feel, billing flow, dashboard design, business info, compliance aur account — sab yahin se control karein.</p>
            </div>
        </div>

        {{-- ═══════════ APPEARANCE & EXPERIENCE ═══════════ --}}
        <section>
            <div class="px-1 mb-3">
                <h2 class="text-sm font-extrabold text-gray-900 dark:text-white uppercase tracking-wide">Appearance &amp; Experience</h2>
                <p class="text-[12px] text-gray-500 dark:text-gray-400">Theme, dashboard design aur billing flow yahin se badal lein — fauran apply ho jata hai</p>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">

                {{-- Theme picker (6 themes) --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400 flex items-center justify-center shrink-0">
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
                            @click="currentTheme='{{ $t }}'; document.body.setAttribute('data-theme','{{ $t }}'); fetch('{{ route('fbrpos.settings.theme') }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({theme:'{{ $t }}'})}).catch(()=>{})"
                            class="h-10 rounded-xl ring-2 ring-offset-2 ring-offset-white dark:ring-offset-gray-900 transition"
                            :class="currentTheme==='{{ $t }}' ? 'ring-blue-500' : 'ring-transparent'"
                            style="background:linear-gradient(135deg,{{ $g[0] }},{{ $g[1] }})"
                            title="{{ ucfirst($t) }}"></button>
                        @endforeach
                    </div>
                </div>

                {{-- Guided keyboard billing toggle --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 7h14a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2V9a2 2 0 012-2z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 11h.01M11 11h.01M15 11h.01M7 14h10"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">Guided Keyboard Billing</p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">Enter-driven tez billing flow cashiers ke liye (Universal screen par)</p>
                    </div>
                    <button type="button"
                        @click="guidedOn=!guidedOn; savingGuided=true; fetch('{{ route('fbrpos.settings.guided-flow') }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({enabled:guidedOn})}).then(r=>r.json()).catch(()=>{}).finally(()=>{ savingGuided=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="guidedOn ? 'bg-blue-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="guidedOn && 'translate-x-6'"></span>
                    </button>
                </div>

                {{-- Universal sale screen toggle --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            Universal Sale Screen
                            <span class="text-[9px] px-1.5 py-0.5 bg-blue-600 text-white rounded font-bold uppercase tracking-wider">New</span>
                        </p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">Modern product-grid billing — OFF karne par classic screen wapas</p>
                        <p class="text-[11px] mt-1 font-medium" :class="universalOn ? 'text-blue-600' : 'text-gray-400'" x-show="uniMsg" x-text="uniMsg" x-cloak></p>
                    </div>
                    <button type="button" :disabled="savingUniversal"
                        @click="savingUniversal=true; uniMsg=''; fetch('{{ route('fbrpos.api.toggle-universal') }}', {method:'POST',headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json'}}).then(r=>r.json()).then(d=>{ if(d.success){ universalOn=d.enabled; uniMsg=d.message; } else { uniMsg=d.message||'Failed to update.'; } }).catch(()=>{ uniMsg='Network error — please try again.'; }).finally(()=>{ savingUniversal=false; })"
                        class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200 disabled:opacity-50" :class="universalOn ? 'bg-blue-500' : 'bg-gray-300 dark:bg-gray-600'">
                        <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="universalOn && 'translate-x-6'"></span>
                    </button>
                </div>

                {{-- Dashboard style picker (6 designs) --}}
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm sm:col-span-2">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/></svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">Dashboard Design</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">Apne dashboard ka layout chunein — save par page reload hoga</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                        @foreach($styles as $s)
                        <button type="button"
                            @click="currentStyle='{{ $s['id'] }}'; fetch('{{ route('fbrpos.settings.dashboard-style') }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({style:'{{ $s['id'] }}'})}).then(()=>window.location.reload())"
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
                @php $tn = $tones[$c['tone']] ?? $tones['blue']; @endphp
                <a href="{{ $c['url'] }}" class="group flex items-center gap-3 p-4 rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 hover:border-blue-400 dark:hover:border-blue-600 hover:shadow-md transition">
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

        <div class="pt-2 text-center">
            <a href="{{ route('fbrpos.dashboard') }}" class="inline-flex items-center gap-1.5 text-[12px] font-semibold text-gray-500 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Back to Dashboard
            </a>
        </div>
    </div>
</x-fbr-pos-layout>
