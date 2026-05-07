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
                <div class="p-3 hover:bg-emerald-50 flex items-start gap-3 cursor-pointer" @click="picked = r">
                    <span class="font-mono font-bold text-emerald-700 text-sm whitespace-nowrap" x-text="r.code"></span>
                    <span class="text-slate-700 text-sm" x-text="r.description || '(no description)'"></span>
                </div>
            </template>
            <template x-if="!hsLoading && hsResults.length === 0 && hsQuery.length > 0">
                <div class="p-3 text-slate-400 text-sm">No matches</div>
            </template>
        </div>
        <template x-if="picked">
            <div class="mt-3 p-3 bg-emerald-50 border border-emerald-300 rounded-lg">
                <div class="text-xs font-semibold text-emerald-700 uppercase">Selected ✓</div>
                <div class="font-mono font-bold text-emerald-900" x-text="picked.code + ' — ' + (picked.description || '')"></div>
            </div>
        </template>
    </section>

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

    <!-- RATES TABLE -->
    <section class="bg-white rounded-2xl p-5 shadow-md border border-slate-200">
        <h3 class="font-bold text-slate-800 mb-3">Tax Rates ({{ $rates->count() }})</h3>
        <div class="grid grid-cols-3 md:grid-cols-6 lg:grid-cols-8 gap-2 max-h-72 overflow-y-auto">
            @foreach($rates as $r)
                <div class="px-3 py-2 bg-slate-50 rounded-lg text-center border border-slate-200">
                    <div class="font-mono font-bold text-sm text-slate-800">{{ $r->label }}</div>
                    @if($r->numeric_value !== null)
                        <div class="text-[10px] text-emerald-600">{{ $r->numeric_value * 100 }}%</div>
                    @else
                        <div class="text-[10px] text-amber-600">fixed</div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <footer class="mt-8 text-center text-xs text-slate-400">
        Source: FBR Sales Invoice Template (.xlsm) → 11 MySQL tables → live API
    </footer>
</div>

<script>
function demo() {
    return {
        hsQuery: '',
        hsResults: [],
        hsLoading: false,
        picked: null,
        async searchHs() {
            if (!this.hsQuery) { this.hsResults = []; return; }
            this.hsLoading = true;
            try {
                const r = await fetch('/api/fbr/hs-search?q=' + encodeURIComponent(this.hsQuery));
                const d = await r.json();
                this.hsResults = d.results || [];
            } finally { this.hsLoading = false; }
        }
    };
}
</script>
</body>
</html>
