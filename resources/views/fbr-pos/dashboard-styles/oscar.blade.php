<style>
@keyframes fboUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
.fbo-a { animation: fboUp 0.3s ease forwards; }
.fbo-1{animation-delay:0ms}.fbo-2{animation-delay:60ms}.fbo-3{animation-delay:120ms}.fbo-4{animation-delay:180ms}
.fbo-banner { background: linear-gradient(135deg, #0c4a6e 0%, #0369a1 50%, #0284c7 100%); border-radius: 16px; position: relative; overflow: hidden; }
.fbo-banner::before { content: ''; position: absolute; right: 0; top: 0; width: 200px; height: 100%; background: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Ccircle cx='100' cy='100' r='80' fill='none' stroke='rgba(255,255,255,0.05)' stroke-width='40'/%3E%3C/svg%3E") no-repeat center; }
.fbo-strip { display: flex; gap: 1px; background: #e5e7eb; border-radius: 12px; overflow: hidden; }
.dark .fbo-strip { background: rgb(31,41,55); }
.fbo-strip-cell { background: white; padding: 12px 16px; flex: 1; min-width: 0; }
.dark .fbo-strip-cell { background: rgb(17,24,39); }
.fbo-card { background: white; border: 1px solid #e5e7eb; border-radius: 12px; }
.dark .fbo-card { background: rgb(17,24,39); border-color: rgb(31,41,55); }
.fbo-accent { position: relative; }
.fbo-accent::before { content: ''; position: absolute; top: 0; left: 16px; right: 16px; height: 3px; border-radius: 0 0 3px 3px; }
.fbo-accent-blue::before { background: #0284c7; }
.fbo-accent-emerald::before { background: #059669; }
.fbo-accent-amber::before { background: #d97706; }
</style>
<div class="space-y-4">
    <div class="fbo-banner p-5 fbo-a fbo-1">
        <div class="relative z-10 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-xl bg-white/10 flex items-center justify-center flex-shrink-0"><svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg></div>
                <div>
                    <h1 class="text-xl font-black text-white">{{ $company->name ?? 'Business' }}</h1>
                    <p class="text-[10px] text-sky-200/60">{{ now()->format('l, d M Y') }} — FBR Compliant POS</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block"><p class="text-[9px] text-sky-200/50 font-bold uppercase">Tax Today</p><p class="text-lg font-black text-white">Rs.{{ number_format($todayStats->tax ?? 0) }}</p></div>
                @if($company->fbr_pos_id)<span class="px-3 py-1.5 rounded-lg text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-400/20"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block mr-1"></span>POS #{{ $company->fbr_pos_id }}</span>@endif
                @include('fbr-pos.dashboard-styles._style-picker')
                <div x-data="{ fbrEnabled: {{ $fbrReportingStatus ? 'true' : 'false' }}, loading: false }" class="flex items-center gap-2 bg-white/10 backdrop-blur rounded-lg px-3 py-1.5">
                    <span class="text-[10px] font-bold text-white/70">FBR</span>
                    <button @click="loading=true; fetch('{{ route('fbrpos.api.toggle-fbr-reporting') }}', {method:'POST', headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Content-Type':'application/json'}}).then(r=>r.json()).then(d=>{fbrEnabled=d.enabled; loading=false;})" :class="fbrEnabled ? 'bg-emerald-500' : 'bg-gray-400'" class="relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full transition-colors" :disabled="loading"><span :class="fbrEnabled ? 'translate-x-4' : 'translate-x-0'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow mt-0.5 ml-0.5 transition"></span></button>
                </div>
                <a href="{{ route('fbrpos.create') }}" class="px-4 py-2 bg-white/15 hover:bg-white/25 text-white text-[11px] font-bold rounded-lg transition backdrop-blur">+ New Sale</a>
            </div>
        </div>
    </div>

    <div class="fbo-strip fbo-a fbo-2">
        <div class="fbo-strip-cell"><p class="text-[9px] font-bold text-sky-600 uppercase">Revenue</p><p class="text-xl font-black text-gray-900 dark:text-white" style="font-variant-numeric:tabular-nums">Rs.{{ number_format($todayStats->revenue ?? 0) }}</p></div>
        <div class="fbo-strip-cell"><p class="text-[9px] font-bold text-sky-600 uppercase">Orders</p><p class="text-xl font-black text-gray-900 dark:text-white">{{ $todayStats->count ?? 0 }}</p></div>
        <div class="fbo-strip-cell"><p class="text-[9px] font-bold text-sky-600 uppercase">FBR Done</p><p class="text-xl font-black text-emerald-600">{{ $fbrSubmitted }}</p></div>
        <div class="fbo-strip-cell"><p class="text-[9px] font-bold text-sky-600 uppercase">Pending</p><p class="text-xl font-black text-amber-600">{{ $fbrPending }}</p></div>
    </div>

    <div class="grid grid-cols-3 gap-3 fbo-a fbo-3">
        <div class="fbo-card fbo-accent fbo-accent-blue p-3 pt-5"><p class="text-[8px] text-gray-400 font-bold uppercase">Monthly Revenue</p><p class="text-base font-black text-gray-900 dark:text-white">Rs.{{ number_format($monthStats->revenue ?? 0) }}</p><p class="text-[9px] text-gray-400">{{ $monthStats->count ?? 0 }} txns</p></div>
        <div class="fbo-card fbo-accent fbo-accent-emerald p-3 pt-5"><p class="text-[8px] text-gray-400 font-bold uppercase">Monthly Tax</p><p class="text-base font-black text-gray-900 dark:text-white">Rs.{{ number_format($monthStats->tax ?? 0) }}</p></div>
        <div class="fbo-card fbo-accent fbo-accent-amber p-3 pt-5"><p class="text-[8px] text-gray-400 font-bold uppercase">Today's Tax</p><p class="text-base font-black text-gray-900 dark:text-white">Rs.{{ number_format($todayStats->tax ?? 0) }}</p></div>
    </div>

    <div class="fbo-card overflow-hidden fbo-a fbo-4">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
            <h3 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Recent Transactions</h3>
            <a href="{{ route('fbrpos.transactions') }}" class="text-[10px] font-bold text-sky-600">VIEW ALL →</a>
        </div>
        <div class="overflow-x-auto"><table class="w-full"><thead><tr class="bg-gray-50/60 dark:bg-gray-800/30 text-left text-[9px] font-bold text-gray-400 uppercase"><th class="py-2 px-4">Invoice</th><th class="py-2 px-3">Customer</th><th class="py-2 px-3 text-right">Amount</th><th class="py-2 px-3 text-center">FBR</th><th class="py-2 px-4">Date</th></tr></thead><tbody>
            @forelse($recentTransactions as $txn)
            <tr class="border-b border-gray-50 dark:border-gray-800/50 hover:bg-sky-50/20 transition">
                <td class="py-2.5 px-4"><a href="{{ route('fbrpos.show', $txn->id) }}" class="text-[11px] font-bold text-sky-600">{{ $txn->invoice_number }}</a></td>
                <td class="py-2.5 px-3 text-[11px] text-gray-600 dark:text-gray-400">{{ $txn->customer_name ?? 'Walk-in' }}</td>
                <td class="py-2.5 px-3 text-right text-[11px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($txn->total_amount, 2) }}</td>
                <td class="py-2.5 px-3 text-center"><span class="text-[8px] font-bold px-1.5 py-0.5 rounded-full {{ $txn->fbr_status === 'submitted' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : ($txn->fbr_status === 'failed' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400') }}">{{ ucfirst($txn->fbr_status ?? 'pending') }}</span></td>
                <td class="py-2.5 px-4 text-[10px] text-gray-400">{{ $txn->created_at->format('d M h:i A') }}</td>
            </tr>
            @empty<tr><td colspan="5" class="py-8 text-center text-[11px] text-gray-400">No transactions</td></tr>@endforelse
        </tbody></table></div>
    </div>
</div>
