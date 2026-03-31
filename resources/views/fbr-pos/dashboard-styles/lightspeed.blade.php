<style>
@keyframes fblPop { from { opacity: 0; transform: scale(0.92); } to { opacity: 1; transform: scale(1); } }
.fbl-a { animation: fblPop 0.35s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; }
.fbl-1{animation-delay:0ms}.fbl-2{animation-delay:50ms}.fbl-3{animation-delay:100ms}.fbl-4{animation-delay:150ms}
.fbl-hero { background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 50%, #5b21b6 100%); border-radius: 28px; position: relative; overflow: hidden; }
.fbl-hero::before { content: ''; position: absolute; top: -60%; right: -40%; width: 80%; height: 150%; background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 60%); }
.fbl-glass { background: rgba(255,255,255,0.9); backdrop-filter: blur(16px); border: 1px solid rgba(0,0,0,0.04); border-radius: 20px; }
.dark .fbl-glass { background: rgba(17,24,39,0.9); border-color: rgba(255,255,255,0.06); }
.fbl-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 9px; font-weight: 800; }
</style>
<div class="space-y-5">
    <div class="fbl-hero p-6 sm:p-8 fbl-a fbl-1">
        <div class="relative z-10">
            <div class="flex items-start justify-between gap-4 mb-8">
                <div>
                    <p class="text-[10px] font-bold text-violet-300/60 uppercase tracking-[0.2em]">FBR POS</p>
                    <h1 class="text-2xl sm:text-3xl font-black text-white mt-1">{{ $company->name ?? 'Business' }}</h1>
                    <p class="text-[11px] text-violet-200/50 mt-1">{{ now()->format('l, d M Y') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    @include('fbr-pos.dashboard-styles._style-picker')
                    <div x-data="{ fbrEnabled: {{ $fbrReportingStatus ? 'true' : 'false' }}, loading: false }" class="flex items-center gap-2 bg-white/10 backdrop-blur rounded-xl px-3 py-1.5">
                        <span class="text-[10px] font-bold text-white/70">FBR</span>
                        <button @click="loading=true; fetch('{{ route('fbrpos.api.toggle-fbr-reporting') }}', {method:'POST', headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Content-Type':'application/json'}}).then(r=>r.json()).then(d=>{fbrEnabled=d.enabled; loading=false;})" :class="fbrEnabled ? 'bg-emerald-500' : 'bg-gray-400'" class="relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full transition-colors" :disabled="loading"><span :class="fbrEnabled ? 'translate-x-4' : 'translate-x-0'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow mt-0.5 ml-0.5 transition"></span></button>
                    </div>
                </div>
            </div>
            <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-5">
                <p class="text-[9px] font-bold text-violet-200/60 uppercase tracking-wider mb-1">Today's Revenue</p>
                <div class="flex items-end gap-4 flex-wrap">
                    <p class="text-4xl sm:text-5xl font-black text-white leading-none" style="font-variant-numeric:tabular-nums">Rs.{{ number_format($todayStats->revenue ?? 0) }}</p>
                </div>
                <div class="flex gap-8 mt-4">
                    <div><p class="text-2xl font-black text-white">{{ $todayStats->count ?? 0 }}</p><p class="text-[9px] text-violet-200/50 font-bold uppercase">Orders</p></div>
                    <div><p class="text-2xl font-black text-emerald-300">{{ $fbrSubmitted }}</p><p class="text-[9px] text-violet-200/50 font-bold uppercase">FBR Done</p></div>
                    <div><p class="text-2xl font-black text-amber-300">{{ $fbrPending }}</p><p class="text-[9px] text-violet-200/50 font-bold uppercase">Pending</p></div>
                </div>
            </div>
            <div class="mt-4">
                <a href="{{ route('fbrpos.create') }}" class="inline-flex items-center gap-2 px-6 py-3 bg-white/15 hover:bg-white/25 text-white text-sm font-bold rounded-xl transition backdrop-blur"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>New Sale</a>
            </div>
        </div>
    </div>

    @if($company->fbr_pos_id)
    <div class="bg-violet-50 dark:bg-violet-900/20 border border-violet-200 dark:border-violet-800 rounded-xl px-4 py-2.5 flex items-center gap-3 fbl-a fbl-2">
        <div class="w-7 h-7 rounded-lg bg-violet-600 flex items-center justify-center flex-shrink-0"><svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg></div>
        <p class="text-[10px] font-bold text-violet-800 dark:text-violet-300">FBR Integrated — POS #{{ $company->fbr_pos_id }} ({{ ucfirst($company->fbr_pos_environment ?? 'sandbox') }})</p>
    </div>
    @endif

    <div class="fbl-glass p-5 fbl-a fbl-3">
        <p class="text-[9px] font-bold text-gray-400 uppercase tracking-widest mb-3">Monthly Overview</p>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
            <div><p class="text-lg font-black text-gray-900 dark:text-white">Rs.{{ number_format($monthStats->revenue ?? 0) }}</p><p class="text-[9px] text-gray-400 font-bold">Revenue ({{ $monthStats->count ?? 0 }} txns)</p></div>
            <div><p class="text-lg font-black text-cyan-600">Rs.{{ number_format($monthStats->tax ?? 0) }}</p><p class="text-[9px] text-gray-400 font-bold">Monthly Tax</p></div>
            <div><p class="text-lg font-black text-violet-600">Rs.{{ number_format($todayStats->tax ?? 0) }}</p><p class="text-[9px] text-gray-400 font-bold">Today's Tax</p></div>
        </div>
    </div>

    <div class="fbl-glass overflow-hidden fbl-a fbl-4">
        <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between"><h3 class="text-xs font-bold text-gray-900 dark:text-white uppercase">Transactions</h3><a href="{{ route('fbrpos.transactions') }}" class="text-[10px] font-bold text-violet-600">VIEW ALL</a></div>
        <div class="overflow-x-auto"><table class="w-full"><thead><tr class="text-left text-[9px] font-bold text-gray-400 uppercase bg-gray-50/50 dark:bg-gray-800/30"><th class="py-2.5 px-5">Invoice</th><th class="py-2.5 px-3">Customer</th><th class="py-2.5 px-3 text-right">Amount</th><th class="py-2.5 px-3 text-center">FBR</th><th class="py-2.5 px-3">Date</th></tr></thead><tbody>
            @forelse($recentTransactions as $txn)
            <tr class="border-b border-gray-50 dark:border-gray-800/50 hover:bg-violet-50/30 transition"><td class="py-2.5 px-5"><a href="{{ route('fbrpos.show', $txn->id) }}" class="text-[11px] font-bold text-violet-600">{{ $txn->invoice_number }}</a></td><td class="py-2.5 px-3 text-[11px] text-gray-600 dark:text-gray-400">{{ $txn->customer_name ?? 'Walk-in' }}</td><td class="py-2.5 px-3 text-right text-[11px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($txn->total_amount, 2) }}</td><td class="py-2.5 px-3 text-center"><span class="text-[8px] font-bold px-1.5 py-0.5 rounded-full {{ $txn->fbr_status === 'submitted' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : ($txn->fbr_status === 'failed' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400') }}">{{ ucfirst($txn->fbr_status ?? 'pending') }}</span></td><td class="py-2.5 px-3 text-[10px] text-gray-400">{{ $txn->created_at->format('d M h:i A') }}</td></tr>
            @empty<tr><td colspan="5" class="py-8 text-center text-[11px] text-gray-400">No transactions</td></tr>@endforelse
        </tbody></table></div>
    </div>
</div>
