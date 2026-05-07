<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FBR Reference Data Demo — TaxNest</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>
<body class="bg-slate-50 min-h-screen">
<div class="max-w-6xl mx-auto p-6" x-data="demo()">

    <header class="mb-6">
        <h1 class="text-3xl font-extrabold text-slate-800">🇵🇰 FBR Sales-Invoice Reference Demo</h1>
        <p class="text-slate-600 mt-1">Live data from MySQL — extracted from FBR Excel Template ({{ array_sum($stats) }} total rows)</p>
    </header>

    <!-- STATS GRID -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 mb-8">
        @foreach($stats as $name => $count)
            <div class="bg-white rounded-xl p-3 shadow-sm border border-slate-200">
                <div class="text-2xl font-extrabold text-emerald-600">{{ number_format($count) }}</div>
                <div class="text-[11px] font-mono text-slate-500 truncate">{{ $name }}</div>
            </div>
        @endforeach
    </div>

    <!-- LIVE HS CODE SEARCH -->
    <section class="bg-white rounded-2xl p-6 shadow-md border border-slate-200 mb-6">
        <h2 class="text-xl font-bold text-slate-800 mb-3">🔍 Live HS Code Search ({{ number_format($stats['fbr_hs_codes']) }} codes)</h2>
        <input type="text" x-model="hsQuery" @input.debounce.250ms="searchHs"
               placeholder="Type product name or code: rice, tea, mobile, 0902, 1006…"
               class="w-full text-base px-4 py-3 rounded-lg border-2 border-slate-200 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 outline-none">
        <div class="mt-3 max-h-96 overflow-y-auto divide-y divide-slate-100">
            <template x-if="hsLoading"><div class="p-3 text-slate-500 text-sm">Searching…</div></template>
            <template x-for="r in hsResults" :key="r.code">
                <div class="p-3 hover:bg-emerald-50 flex items-start gap-3 cursor-pointer" @click="selectHs(r)">
                    <span class="font-mono font-bold text-emerald-700 text-sm whitespace-nowrap" x-text="r.code"></span>
                    <div class="flex-1">
                        <span class="text-slate-700 text-sm" x-text="r.description || '(no description)'"></span>
                        <template x-if="r.inherited_from">
                            <span class="ml-1 text-[10px] text-slate-400" x-text="'↳ from ' + r.inherited_from"></span>
                        </template>
                    </div>
                </div>
            </template>
            <template x-if="!hsLoading && hsResults.length === 0 && hsQuery.length > 0">
                <div class="p-3 text-slate-400 text-sm">No matches</div>
            </template>
        </div>
        <template x-if="picked">
            <div class="mt-3 space-y-2">
                <div class="p-3 bg-emerald-50 border border-emerald-300 rounded-lg">
                    <div class="text-xs font-semibold text-emerald-700 uppercase">Selected ✓</div>
                    <div class="font-mono font-bold text-emerald-900" x-text="picked.code + ' — ' + (picked.description || '')"></div>
                </div>

                <!-- LINKED TAX DETAILS -->
                <template x-if="hsDetailLoading">
                    <div class="p-3 bg-slate-50 border border-slate-200 rounded-lg text-sm text-slate-500">🔍 Loading linked tax info…</div>
                </template>
                <template x-if="!hsDetailLoading && hsLinks.length === 0 && hsDetailLoaded">
                    <div class="p-3 bg-amber-50 border border-amber-300 rounded-lg text-sm text-amber-800">
                        ⚠️ Is HS code ke liye abhi tak rate mapping seed nahi hui. Aap admin panel se add kar sakte hain.
                    </div>
                </template>
                <template x-for="link in hsLinks" :key="link.id">
                    <div class="p-4 bg-gradient-to-r from-blue-50 to-emerald-50 border-2 border-blue-300 rounded-xl">
                        <div class="text-xs font-bold text-blue-700 uppercase mb-2">🔗 Linked Tax Info — Auto-Applied for FBR Submission</div>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-3 text-sm">
                            <div><div class="text-[10px] text-slate-500 uppercase">Schedule</div><div class="font-bold text-slate-900" x-text="link.schedule_type"></div></div>
                            <div><div class="text-[10px] text-slate-500 uppercase">Tax Rate</div><div class="font-bold text-emerald-700 text-base" x-text="link.rate_label"></div></div>
                            <div><div class="text-[10px] text-slate-500 uppercase">Sale Type</div><div class="font-medium text-slate-800" x-text="link.sale_type || '—'"></div></div>
                            <div><div class="text-[10px] text-slate-500 uppercase">SRO Number</div><div class="font-mono text-slate-800" x-text="link.sro_number || '—'"></div></div>
                            <div><div class="text-[10px] text-slate-500 uppercase">Item Sr No (3rd Sch)</div><div class="font-mono font-bold text-blue-700" x-text="link.sr_no || '—'"></div></div>
                            <div><div class="text-[10px] text-slate-500 uppercase">UoM</div><div class="font-medium text-slate-800" x-text="link.uom || '—'"></div></div>
                        </div>
                        <template x-if="link.notes">
                            <div class="mt-2 text-xs text-slate-600 italic" x-text="'📝 ' + link.notes"></div>
                        </template>
                        <template x-if="link.tax_rate !== null">
                            <div class="mt-3 pt-3 border-t border-blue-200 text-xs text-slate-700">
                                💰 Tax on Rs.1000 = <span class="font-bold text-emerald-700">Rs.<span x-text="(1000 * (link.tax_rate / 100)).toFixed(2)"></span></span>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </template>
    </section>

    <!-- LIVE SRO + SERIAL NUMBER SEARCH -->
    <div class="grid md:grid-cols-2 gap-6 mb-6">

        <!-- SRO SEARCH -->
        <section class="bg-white rounded-2xl p-5 shadow-md border border-slate-200">
            <h2 class="text-lg font-bold text-slate-800 mb-2">📜 SRO Number ({{ $stats['fbr_sros'] }} entries)</h2>
            <input type="text" x-model="sroQuery" @input.debounce.250ms="searchSro"
                   placeholder="Type SRO: 2022, 1636, DTRE…"
                   class="w-full text-sm px-3 py-2 rounded-lg border-2 border-slate-200 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 outline-none">
            <div class="mt-2 max-h-64 overflow-y-auto divide-y divide-slate-100">
                <template x-for="s in sroResults" :key="s.sro_number">
                    <div class="p-2 hover:bg-emerald-50 cursor-pointer text-sm font-mono text-slate-700"
                         @click="pickedSro = s.sro_number" x-text="s.sro_number"></div>
                </template>
                <template x-if="sroResults.length === 0 && sroQuery.length > 0">
                    <div class="p-2 text-slate-400 text-sm">No matches</div>
                </template>
            </div>
            <template x-if="pickedSro">
                <div class="mt-2 p-2 bg-emerald-50 border border-emerald-300 rounded text-xs">
                    <span class="font-semibold text-emerald-700">Selected SRO:</span>
                    <span class="font-mono text-emerald-900" x-text="pickedSro"></span>
                </div>
            </template>
        </section>

        <!-- ITEM SR NO (3rd Schedule) -->
        <section class="bg-white rounded-2xl p-5 shadow-md border border-slate-200">
            <h2 class="text-lg font-bold text-slate-800 mb-2">📑 Item Serial Number — 3rd Schedule ({{ $stats['fbr_item_sr_numbers'] }})</h2>
            <input type="text" x-model="srQuery" @input.debounce.250ms="searchSr"
                   placeholder="Type serial: 1, 25, 100…"
                   class="w-full text-sm px-3 py-2 rounded-lg border-2 border-slate-200 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 outline-none">
            <div class="mt-2 max-h-48 overflow-y-auto">
                <div class="grid grid-cols-6 gap-1">
                    <template x-for="r in srResults" :key="r.sr_no">
                        <div class="px-2 py-1 bg-slate-50 hover:bg-emerald-100 border border-slate-200 rounded text-center text-xs font-mono cursor-pointer"
                             :class="pickedSr === r.sr_no ? 'bg-emerald-200 border-emerald-500 font-bold' : ''"
                             @click="pickedSr = r.sr_no" x-text="r.sr_no"></div>
                    </template>
                </div>
            </div>
            <div class="mt-3">
                <label class="text-xs font-semibold text-slate-600 mb-1 block">Linked Tax Rate (3rd Schedule item):</label>
                <select x-model="pickedRate" class="w-full text-sm px-3 py-2 rounded-lg border-2 border-slate-200 focus:border-emerald-500 outline-none">
                    <option value="">— Select Rate —</option>
                    @foreach($rates as $r)
                        <option value="{{ $r->label }}">{{ $r->label }}{{ $r->numeric_value !== null ? ' ('.($r->numeric_value*100).'%)' : ' (fixed)' }}</option>
                    @endforeach
                </select>
            </div>
            <template x-if="pickedSr || pickedRate">
                <div class="mt-2 p-2 bg-emerald-50 border border-emerald-300 rounded text-xs space-y-1">
                    <template x-if="pickedSr"><div><span class="font-semibold text-emerald-700">Sr. No:</span> <span class="font-mono text-emerald-900" x-text="pickedSr"></span></div></template>
                    <template x-if="pickedRate"><div><span class="font-semibold text-emerald-700">Rate:</span> <span class="font-mono text-emerald-900" x-text="pickedRate"></span></div></template>
                </div>
            </template>
        </section>
    </div>

    <!-- DROPDOWN GRID -->
    <div class="grid md:grid-cols-2 gap-6 mb-6">

        <!-- SALE TYPES -->
        <div class="bg-white rounded-2xl p-5 shadow-md border border-slate-200">
            <h3 class="font-bold text-slate-800 mb-2">Sale Type ({{ $saleTypes->count() }})</h3>
            <select class="w-full px-3 py-2 rounded-lg border-2 border-slate-200 focus:border-emerald-500 outline-none">
                @foreach($saleTypes as $t)<option>{{ $t }}</option>@endforeach
            </select>
        </div>

        <!-- UoMs -->
        <div class="bg-white rounded-2xl p-5 shadow-md border border-slate-200">
            <h3 class="font-bold text-slate-800 mb-2">UoM ({{ $uoms->count() }})</h3>
            <select class="w-full px-3 py-2 rounded-lg border-2 border-slate-200 focus:border-emerald-500 outline-none">
                @foreach($uoms as $u)<option>{{ $u }}</option>@endforeach
            </select>
        </div>

        <!-- PROVINCES -->
        <div class="bg-white rounded-2xl p-5 shadow-md border border-slate-200">
            <h3 class="font-bold text-slate-800 mb-2">Province ({{ $provinces->count() }})</h3>
            <select class="w-full px-3 py-2 rounded-lg border-2 border-slate-200 focus:border-emerald-500 outline-none">
                @foreach($provinces as $p)<option>{{ $p }}</option>@endforeach
            </select>
        </div>

        <!-- BUYER TYPE -->
        <div class="bg-white rounded-2xl p-5 shadow-md border border-slate-200">
            <h3 class="font-bold text-slate-800 mb-2">Buyer Type ({{ $buyerTypes->count() }})</h3>
            <select class="w-full px-3 py-2 rounded-lg border-2 border-slate-200 focus:border-emerald-500 outline-none">
                @foreach($buyerTypes as $b)<option>{{ $b }}</option>@endforeach
            </select>
        </div>

        <!-- DOC TYPE -->
        <div class="bg-white rounded-2xl p-5 shadow-md border border-slate-200">
            <h3 class="font-bold text-slate-800 mb-2">Document Type ({{ $docTypes->count() }})</h3>
            <select class="w-full px-3 py-2 rounded-lg border-2 border-slate-200 focus:border-emerald-500 outline-none">
                @foreach($docTypes as $d)<option>{{ $d }}</option>@endforeach
            </select>
        </div>

        <!-- REASONS -->
        <div class="bg-white rounded-2xl p-5 shadow-md border border-slate-200">
            <h3 class="font-bold text-slate-800 mb-2">Reason — Credit/Debit Note ({{ $reasons->count() }})</h3>
            <select class="w-full px-3 py-2 rounded-lg border-2 border-slate-200 focus:border-emerald-500 outline-none">
                @foreach($reasons as $r)<option>{{ $r }}</option>@endforeach
            </select>
        </div>
    </div>

    @php
        $low=[]; $std=[]; $high=[]; $fixed=[];
        foreach($rates as $r) {
            if ($r->numeric_value === null) { $fixed[] = $r; continue; }
            $p = $r->numeric_value * 100;
            if ($p <= 5)        $low[]  = $r;
            elseif ($p <= 20)   $std[]  = $r;
            else                $high[] = $r;
        }
    @endphp

    <!-- TAX RATE CALCULATOR -->
    <section class="bg-white rounded-2xl p-5 shadow-md border border-slate-200">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-bold text-slate-800 text-lg">💰 Tax Rate Calculator ({{ $rates->count() }} rates)</h3>
            <div class="flex items-center gap-2 text-sm">
                <label class="text-slate-600 font-semibold">Sample Amount:</label>
                <span class="text-slate-500">Rs.</span>
                <input type="number" x-model.number="sampleAmount" class="w-28 px-2 py-1 border-2 border-slate-200 rounded-lg focus:border-emerald-500 outline-none font-mono">
            </div>
        </div>

        <!-- TABS -->
        <div class="flex gap-2 mb-3 border-b-2 border-slate-100 overflow-x-auto">
            <button @click="rateTab='low'" :class="rateTab==='low' ? 'bg-emerald-600 text-white' : 'bg-emerald-50 text-emerald-700'" class="px-4 py-2 rounded-t-lg font-semibold text-sm whitespace-nowrap">
                🟢 Low (0–5%) · {{ count($low) }}
            </button>
            <button @click="rateTab='std'" :class="rateTab==='std' ? 'bg-blue-600 text-white' : 'bg-blue-50 text-blue-700'" class="px-4 py-2 rounded-t-lg font-semibold text-sm whitespace-nowrap">
                🔵 Standard (5–20%) · {{ count($std) }}
            </button>
            <button @click="rateTab='high'" :class="rateTab==='high' ? 'bg-red-600 text-white' : 'bg-red-50 text-red-700'" class="px-4 py-2 rounded-t-lg font-semibold text-sm whitespace-nowrap">
                🔴 High (20%+) · {{ count($high) }}
            </button>
            <button @click="rateTab='fixed'" :class="rateTab==='fixed' ? 'bg-amber-600 text-white' : 'bg-amber-50 text-amber-700'" class="px-4 py-2 rounded-t-lg font-semibold text-sm whitespace-nowrap">
                🟡 Fixed (Rs.) · {{ count($fixed) }}
            </button>
        </div>

        <!-- LOW RATES -->
        <div x-show="rateTab==='low'" class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-2">
            @foreach($low as $r)
                <button @click="pickRate('{{ $r->label }}', {{ $r->numeric_value }}, false)"
                        :class="activeRate==='{{ $r->label }}' ? 'ring-2 ring-emerald-500 bg-emerald-100' : ''"
                        class="px-3 py-2 bg-emerald-50 hover:bg-emerald-100 border-2 border-emerald-200 rounded-lg text-left transition">
                    <div class="font-mono font-bold text-emerald-900 text-base">{{ $r->numeric_value * 100 }}%</div>
                    <div class="text-[10px] text-emerald-700">label: {{ $r->label }}</div>
                    <div class="text-[10px] text-slate-600 mt-1">Tax on Rs.<span x-text="sampleAmount"></span> = <span class="font-bold text-emerald-700">Rs.<span x-text="(sampleAmount * {{ $r->numeric_value }}).toFixed(2)"></span></span></div>
                </button>
            @endforeach
        </div>

        <!-- STANDARD RATES -->
        <div x-show="rateTab==='std'" class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-2">
            @foreach($std as $r)
                <button @click="pickRate('{{ $r->label }}', {{ $r->numeric_value }}, false)"
                        :class="activeRate==='{{ $r->label }}' ? 'ring-2 ring-blue-500 bg-blue-100' : ''"
                        class="px-3 py-2 bg-blue-50 hover:bg-blue-100 border-2 border-blue-200 rounded-lg text-left transition">
                    <div class="font-mono font-bold text-blue-900 text-base">{{ $r->numeric_value * 100 }}%</div>
                    <div class="text-[10px] text-blue-700">label: {{ $r->label }}</div>
                    <div class="text-[10px] text-slate-600 mt-1">Tax on Rs.<span x-text="sampleAmount"></span> = <span class="font-bold text-blue-700">Rs.<span x-text="(sampleAmount * {{ $r->numeric_value }}).toFixed(2)"></span></span></div>
                </button>
            @endforeach
        </div>

        <!-- HIGH RATES -->
        <div x-show="rateTab==='high'" class="grid grid-cols-2 md:grid-cols-4 gap-2">
            @foreach($high as $r)
                <button @click="pickRate('{{ $r->label }}', {{ $r->numeric_value }}, false)"
                        :class="activeRate==='{{ $r->label }}' ? 'ring-2 ring-red-500 bg-red-100' : ''"
                        class="px-3 py-2 bg-red-50 hover:bg-red-100 border-2 border-red-200 rounded-lg text-left transition">
                    <div class="font-mono font-bold text-red-900 text-base">{{ $r->numeric_value * 100 }}%</div>
                    <div class="text-[10px] text-slate-600 mt-1">Tax on Rs.<span x-text="sampleAmount"></span> = <span class="font-bold text-red-700">Rs.<span x-text="(sampleAmount * {{ $r->numeric_value }}).toFixed(2)"></span></span></div>
                </button>
            @endforeach
        </div>

        <!-- FIXED RATES -->
        <div x-show="rateTab==='fixed'" class="grid grid-cols-3 md:grid-cols-6 lg:grid-cols-8 gap-2 max-h-72 overflow-y-auto">
            @foreach($fixed as $r)
                <button @click="pickRate('{{ $r->label }}', null, true)"
                        :class="activeRate==='{{ $r->label }}' ? 'ring-2 ring-amber-500 bg-amber-100' : ''"
                        class="px-2 py-2 bg-amber-50 hover:bg-amber-100 border-2 border-amber-200 rounded-lg text-center transition">
                    <div class="font-mono font-bold text-amber-900 text-xs">{{ $r->label }}</div>
                    <div class="text-[9px] text-amber-700 mt-1">fixed/special</div>
                </button>
            @endforeach
        </div>

        <!-- LIVE PREVIEW -->
        <template x-if="activeRate">
            <div class="mt-4 p-4 bg-gradient-to-r from-emerald-50 to-blue-50 border-2 border-emerald-300 rounded-xl">
                <div class="text-xs font-semibold text-emerald-700 uppercase mb-2">✓ Selected Rate — Live Calculation</div>
                <div class="grid grid-cols-3 gap-3 text-sm">
                    <div><div class="text-slate-500 text-xs">Rate Label</div><div class="font-mono font-bold text-slate-900" x-text="activeRate"></div></div>
                    <template x-if="!activeFixed">
                        <div><div class="text-slate-500 text-xs">Percentage</div><div class="font-bold text-emerald-700" x-text="(activePercent*100).toFixed(2) + '%'"></div></div>
                    </template>
                    <template x-if="activeFixed">
                        <div><div class="text-slate-500 text-xs">Type</div><div class="font-bold text-amber-700">Fixed / Special</div></div>
                    </template>
                    <template x-if="!activeFixed">
                        <div><div class="text-slate-500 text-xs">Tax on Rs.<span x-text="sampleAmount"></span></div><div class="font-bold text-emerald-700">Rs.<span x-text="(sampleAmount*activePercent).toFixed(2)"></span></div></div>
                    </template>
                </div>
            </div>
        </template>
    </section>

    <footer class="mt-8 text-center text-xs text-slate-400">
        Source: FBR Sales Invoice Template (.xlsm) → 11 MySQL tables → live API
    </footer>
</div>

<script>
function demo() {
    return {
        hsQuery: '', hsResults: [], hsLoading: false, picked: null,
        hsLinks: [], hsDetailLoading: false, hsDetailLoaded: false,
        sroQuery: '', sroResults: [], pickedSro: null,
        srQuery: '', srResults: [], pickedSr: null, pickedRate: '',
        rateTab: 'low', sampleAmount: 1000, activeRate: null, activePercent: 0, activeFixed: false,
        pickRate(label, percent, isFixed) {
            this.activeRate = label;
            this.activePercent = percent || 0;
            this.activeFixed = isFixed;
        },
        async searchHs() {
            if (!this.hsQuery) { this.hsResults = []; return; }
            this.hsLoading = true;
            try {
                const r = await fetch('/api/fbr/hs-search?q=' + encodeURIComponent(this.hsQuery));
                const d = await r.json();
                this.hsResults = d.results || [];
            } finally { this.hsLoading = false; }
        },
        async selectHs(r) {
            this.picked = r;
            this.hsLinks = []; this.hsDetailLoaded = false; this.hsDetailLoading = true;
            try {
                const res = await fetch('/api/fbr/hs-detail?code=' + encodeURIComponent(r.code));
                const d = await res.json();
                this.hsLinks = d.links || [];
            } finally { this.hsDetailLoading = false; this.hsDetailLoaded = true; }
        },
        async searchSro() {
            if (!this.sroQuery) { this.sroResults = []; return; }
            const r = await fetch('/api/fbr/sro-search?q=' + encodeURIComponent(this.sroQuery));
            const d = await r.json();
            this.sroResults = d.results || [];
        },
        async searchSr() {
            const r = await fetch('/api/fbr/item-sr-search?q=' + encodeURIComponent(this.srQuery));
            const d = await r.json();
            this.srResults = d.results || [];
        },
        async init() { await this.searchSr(); }
    };
}
</script>
</body>
</html>
