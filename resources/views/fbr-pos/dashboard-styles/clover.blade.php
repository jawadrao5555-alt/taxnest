<style>
@keyframes fbcSlide { from { opacity: 0; transform: translateX(-8px); } to { opacity: 1; transform: translateX(0); } }
.fbc-a { animation: fbcSlide 0.3s ease forwards; }
.fbc-1{animation-delay:0ms}.fbc-2{animation-delay:60ms}.fbc-3{animation-delay:120ms}.fbc-4{animation-delay:180ms}
.fbc-sidebar { background: linear-gradient(180deg, #064e3b 0%, #065f46 50%, #047857 100%); border-radius: 20px; }
.fbc-card { background: white; border-radius: 14px; border: 1px solid #e5e7eb; }
.dark .fbc-card { background: rgb(17,24,39); border-color: rgb(31,41,55); }
.fbc-metric { padding: 12px 14px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.05); }
.fbc-prog { height: 4px; border-radius: 2px; background: rgba(255,255,255,0.1); overflow: hidden; }
.fbc-prog-fill { height: 100%; border-radius: 2px; background: #34d399; }
</style>
<div class="space-y-4">
    <div class="flex items-center justify-between fbc-a fbc-1">
        <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-lg bg-emerald-600 flex items-center justify-center"><svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg></div>
            <div><h1 class="text-lg font-black text-gray-900 dark:text-white">{{ $company->name ?? 'Business' }}</h1><p class="text-[10px] text-gray-400">{{ now()->format('l, d M Y') }} — FBR POS</p></div>
        </div>
        <div class="flex items-center gap-2">
            @include('fbr-pos.dashboard-styles._style-picker')
            <a href="{{ route('fbrpos.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 text-white text-[11px] font-bold rounded-xl hover:bg-emerald-700 transition shadow-sm"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>New Sale</a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
        <div class="fbc-sidebar p-5 lg:row-span-2 fbc-a fbc-2">
            <p class="text-[9px] font-bold text-emerald-300/60 uppercase tracking-widest mb-4">Today's Summary</p>

            <div class="fbc-metric mb-3">
                <p class="text-[9px] text-emerald-200/60 font-bold uppercase">Revenue</p>
                <p class="text-2xl font-black text-white mt-1">Rs.{{ number_format($todayStats->revenue ?? 0) }}</p>
            </div>

            <div class="grid grid-cols-2 gap-2 mb-3">
                <div class="fbc-metric"><p class="text-[9px] text-emerald-200/60 font-bold">ORDERS</p><p class="text-lg font-black text-white">{{ $todayStats->count ?? 0 }}</p></div>
                <div class="fbc-metric"><p class="text-[9px] text-emerald-200/60 font-bold">TAX</p><p class="text-lg font-black text-white">Rs.{{ number_format($todayStats->tax ?? 0) }}</p></div>
            </div>

            <div class="grid grid-cols-2 gap-2 mb-4">
                <div class="fbc-metric"><p class="text-[9px] text-emerald-200/60 font-bold">FBR DONE</p><p class="text-lg font-black text-emerald-300">{{ $fbrSubmitted }}</p></div>
                <div class="fbc-metric"><p class="text-[9px] text-emerald-200/60 font-bold">PENDING</p><p class="text-lg font-black text-amber-300">{{ $fbrPending }}</p></div>
            </div>

            <div class="border-t border-white/10 pt-3 mb-4 space-y-2">
                <div class="flex items-center justify-between"><span class="text-[9px] text-emerald-200/60 font-bold">MONTHLY</span><span class="text-sm font-black text-white">Rs.{{ number_format($monthStats->revenue ?? 0) }}</span></div>
                <div class="flex items-center justify-between"><span class="text-[9px] text-emerald-200/60 font-bold">M.TAX</span><span class="text-sm font-black text-cyan-300">Rs.{{ number_format($monthStats->tax ?? 0) }}</span></div>
            </div>

            <div x-data="{ fbrEnabled: {{ $fbrReportingStatus ? 'true' : 'false' }}, loading: false }" class="fbc-metric mb-3">
                <div class="flex items-center justify-between">
                    <span class="text-[9px] text-emerald-200/60 font-bold">FBR REPORTING</span>
                    <button @click="loading=true; fetch('{{ route('fbrpos.api.toggle-fbr-reporting') }}', {method:'POST', headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Content-Type':'application/json'}}).then(r=>r.json()).then(d=>{fbrEnabled=d.enabled; loading=false;})" :class="fbrEnabled ? 'bg-emerald-500' : 'bg-gray-500'" class="relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full transition-colors" :disabled="loading"><span :class="fbrEnabled ? 'translate-x-4' : 'translate-x-0'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow mt-0.5 ml-0.5 transition"></span></button>
                </div>
            </div>

            @if($company->fbr_pos_id)
            <div class="fbc-metric"><p class="text-[9px] text-emerald-200/60 font-bold">FBR POS #</p><p class="text-sm font-bold text-white">{{ $company->fbr_pos_id }}</p><p class="text-[8px] text-emerald-200/40">{{ ucfirst($company->fbr_pos_environment ?? 'sandbox') }}</p></div>
            @endif
        </div>

        <div class="lg:col-span-3 space-y-4">
            <div class="fbc-card overflow-hidden fbc-a fbc-3">
                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                    <h2 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Recent Transactions</h2>
                    <a href="{{ route('fbrpos.transactions') }}" class="text-[10px] font-bold text-emerald-600 hover:text-emerald-700">VIEW ALL →</a>
                </div>
                <div class="overflow-x-auto"><table class="w-full"><thead><tr class="bg-gray-50/60 dark:bg-gray-800/30"><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-4">Invoice</th><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Customer</th><th class="text-right text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Amount</th><th class="text-center text-[9px] text-gray-400 font-bold uppercase py-2 px-3">FBR</th><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-4">Date</th></tr></thead><tbody>
                    @forelse($recentTransactions as $txn)
                    <tr class="border-b border-gray-50 dark:border-gray-800/50 hover:bg-emerald-50/20 dark:hover:bg-emerald-900/5 transition">
                        <td class="py-2.5 px-4"><a href="{{ route('fbrpos.show', $txn->id) }}" class="text-[11px] font-bold text-emerald-600">{{ $txn->invoice_number }}</a></td>
                        <td class="py-2.5 px-3 text-[11px] text-gray-600 dark:text-gray-400">{{ $txn->customer_name ?? 'Walk-in' }}</td>
                        <td class="py-2.5 px-3 text-right text-[11px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($txn->total_amount, 2) }}</td>
                        <td class="py-2.5 px-3 text-center"><span class="text-[8px] font-bold px-2 py-0.5 rounded-full {{ $txn->fbr_status === 'submitted' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : ($txn->fbr_status === 'failed' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400') }}">{{ ucfirst($txn->fbr_status ?? 'pending') }}</span></td>
                        <td class="py-2.5 px-4 text-[10px] text-gray-400">{{ $txn->created_at->format('d M h:i A') }}</td>
                    </tr>
                    @empty<tr><td colspan="5" class="py-8 text-center text-[11px] text-gray-400">No transactions yet</td></tr>@endforelse
                </tbody></table></div>
            </div>
        </div>
    </div>
</div>
