<style>
@keyframes fbsFade { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
.fbs-a { animation: fbsFade 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards; }
.fbs-1{animation-delay:0ms}.fbs-2{animation-delay:80ms}.fbs-3{animation-delay:160ms}.fbs-4{animation-delay:240ms}
.fbs-hero { background: linear-gradient(145deg, #0f172a 0%, #1e293b 60%, #334155 100%); border-radius: 24px; position: relative; overflow: hidden; }
.fbs-hero::before { content: ''; position: absolute; top: -50%; right: -30%; width: 70%; height: 140%; background: radial-gradient(circle, rgba(99,102,241,0.08) 0%, transparent 60%); }
.fbs-hero::after { content: ''; position: absolute; bottom: -40%; left: -20%; width: 50%; height: 100%; background: radial-gradient(circle, rgba(34,197,94,0.06) 0%, transparent 60%); }
.fbs-min { background: white; border: 1px solid #f1f5f9; border-radius: 14px; transition: all 0.2s; }
.dark .fbs-min { background: rgb(15,23,42); border-color: rgb(30,41,59); }
.fbs-min:hover { border-color: #cbd5e1; box-shadow: 0 1px 8px rgba(0,0,0,0.04); }
.fbs-link { font-size: 11px; font-weight: 700; color: #6366f1; }
.dark .fbs-link { color: #818cf8; }
</style>
<div class="space-y-5">
    <div class="fbs-hero p-8 sm:p-10 fbs-a fbs-1">
        <div class="relative z-10">
            <div class="flex items-start justify-between gap-4 mb-10">
                <div>
                    <p class="text-[10px] font-bold text-slate-500 uppercase tracking-[0.2em]">{{ now()->format('l, d M Y') }}</p>
                    <p class="text-sm text-slate-400 mt-2">{{ $company->name ?? 'Business' }} — FBR POS</p>
                </div>
                <div class="flex items-center gap-2">
                    @include('fbr-pos.dashboard-styles._style-picker')
                    <div x-data="{ fbrEnabled: {{ $fbrReportingStatus ? 'true' : 'false' }}, loading: false }" class="flex items-center gap-2 bg-white/10 backdrop-blur rounded-lg px-3 py-1.5">
                        <span class="text-[10px] font-bold text-white/60">FBR</span>
                        <button @click="loading=true; fetch('{{ route('fbrpos.api.toggle-fbr-reporting') }}', {method:'POST', headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Content-Type':'application/json'}}).then(r=>r.json()).then(d=>{fbrEnabled=d.enabled; loading=false;})" :class="fbrEnabled ? 'bg-emerald-500' : 'bg-gray-400'" class="relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full transition-colors" :disabled="loading"><span :class="fbrEnabled ? 'translate-x-4' : 'translate-x-0'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow mt-0.5 ml-0.5 transition"></span></button>
                    </div>
                </div>
            </div>

            <div class="mb-2">
                <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Today's Revenue</p>
                <p class="text-5xl sm:text-6xl font-black text-white mt-2 tracking-tight" style="font-variant-numeric:tabular-nums;letter-spacing:-0.03em">Rs.{{ number_format($todayStats->revenue ?? 0) }}</p>
            </div>

            <div class="flex gap-10 mt-8 pt-6 border-t border-white/5">
                <div><p class="text-3xl font-black text-white">{{ $todayStats->count ?? 0 }}</p><p class="text-[9px] text-slate-500 font-bold uppercase tracking-wider mt-1">Orders</p></div>
                <div><p class="text-3xl font-black text-emerald-400">{{ $fbrSubmitted }}</p><p class="text-[9px] text-slate-500 font-bold uppercase tracking-wider mt-1">FBR Done</p></div>
                <div><p class="text-3xl font-black text-amber-400">{{ $fbrPending }}</p><p class="text-[9px] text-slate-500 font-bold uppercase tracking-wider mt-1">Pending</p></div>
            </div>

            <div class="mt-6"><a href="{{ route('fbrpos.create') }}" class="inline-flex items-center gap-2 px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold rounded-xl transition shadow-lg shadow-indigo-500/20"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>New Sale</a></div>
        </div>
    </div>

    @if($company->fbr_pos_id)
    <div class="fbs-min p-4 flex items-center gap-3 fbs-a fbs-2">
        <div class="w-8 h-8 rounded-xl bg-indigo-50 dark:bg-indigo-900/20 flex items-center justify-center"><svg class="w-4 h-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg></div>
        <div><p class="text-[12px] font-bold text-gray-900 dark:text-white">FBR Integrated</p><p class="text-[10px] text-gray-400">POS #{{ $company->fbr_pos_id }} · {{ ucfirst($company->fbr_pos_environment ?? 'sandbox') }}</p></div>
    </div>
    @endif

    <div class="fbs-min p-5 fbs-a fbs-3">
        <div class="flex items-center gap-8 flex-wrap">
            <div><p class="text-[9px] text-gray-400 font-bold uppercase tracking-wider">Monthly Revenue</p><p class="text-xl font-black text-gray-900 dark:text-white mt-0.5">Rs.{{ number_format($monthStats->revenue ?? 0) }}</p><p class="text-[9px] text-gray-400">{{ $monthStats->count ?? 0 }} transactions</p></div>
            <div class="w-px h-10 bg-gray-100 dark:bg-gray-800"></div>
            <div><p class="text-[9px] text-gray-400 font-bold uppercase tracking-wider">Monthly Tax</p><p class="text-xl font-black text-gray-900 dark:text-white mt-0.5">Rs.{{ number_format($monthStats->tax ?? 0) }}</p></div>
            <div class="w-px h-10 bg-gray-100 dark:bg-gray-800"></div>
            <div><p class="text-[9px] text-gray-400 font-bold uppercase tracking-wider">Today's Tax</p><p class="text-xl font-black text-gray-900 dark:text-white mt-0.5">Rs.{{ number_format($todayStats->tax ?? 0) }}</p></div>
        </div>
    </div>

    <div class="fbs-min overflow-hidden fbs-a fbs-4">
        <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800/50 flex items-center justify-between">
            <h3 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wider">Recent Transactions</h3>
            <a href="{{ route('fbrpos.transactions') }}" class="fbs-link">View all →</a>
        </div>
        <div class="overflow-x-auto"><table class="w-full"><thead><tr class="text-left text-[9px] font-bold text-gray-400 uppercase tracking-wider bg-gray-50/40 dark:bg-gray-800/20"><th class="py-2.5 px-5">Invoice</th><th class="py-2.5 px-3">Customer</th><th class="py-2.5 px-3 text-right">Amount</th><th class="py-2.5 px-3 text-center">FBR</th><th class="py-2.5 px-3">Date</th></tr></thead><tbody>
            @forelse($recentTransactions as $txn)
            <tr class="border-b border-gray-50 dark:border-gray-800/30 hover:bg-gray-50/50 dark:hover:bg-gray-800/20 transition">
                <td class="py-3 px-5"><a href="{{ route('fbrpos.show', $txn->id) }}" class="text-[12px] font-bold text-indigo-600 dark:text-indigo-400">{{ $txn->invoice_number }}</a></td>
                <td class="py-3 px-3 text-[11px] text-gray-500 dark:text-gray-400">{{ $txn->customer_name ?? 'Walk-in' }}</td>
                <td class="py-3 px-3 text-right text-[12px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($txn->total_amount, 2) }}</td>
                <td class="py-3 px-3 text-center"><span class="text-[9px] font-bold px-2.5 py-1 rounded-lg {{ $txn->fbr_status === 'submitted' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400' : ($txn->fbr_status === 'failed' ? 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400' : 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400') }}">{{ ucfirst($txn->fbr_status ?? 'pending') }}</span></td>
                <td class="py-3 px-3 text-[10px] text-gray-400">{{ $txn->created_at->format('d M h:i A') }}</td>
            </tr>
            @empty<tr><td colspan="5" class="py-10 text-center text-[12px] text-gray-400">No transactions yet</td></tr>@endforelse
        </tbody></table></div>
    </div>
</div>
