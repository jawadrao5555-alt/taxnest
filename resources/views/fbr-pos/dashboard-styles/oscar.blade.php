<style>
@keyframes fbo { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
.fbo-a { animation: fbo 0.3s ease forwards; }
.fbo-1 { animation-delay: 0ms; } .fbo-2 { animation-delay: 60ms; } .fbo-3 { animation-delay: 120ms; } .fbo-4 { animation-delay: 180ms; }
.fbo-c { background: white; border: 1px solid #e5e7eb; border-radius: 12px; }
.dark .fbo-c { background: rgb(17,24,39); border-color: rgb(31,41,55); }
.fbo-banner { background: linear-gradient(135deg, #0c4a6e 0%, #0369a1 100%); border-radius: 14px; }
.fbo-stat { background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px; position: relative; }
.dark .fbo-stat { background: rgb(17,24,39); border-color: rgb(31,41,55); }
.fbo-accent { position: absolute; top: 0; left: 0; width: 100%; height: 3px; border-radius: 10px 10px 0 0; }
</style>
<div class="space-y-4">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 fbo-a fbo-1">
        <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-lg bg-sky-600 flex items-center justify-center"><svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg></div>
            <div><h1 class="text-lg font-black text-gray-900 dark:text-white">{{ $company->name ?? 'Business' }}</h1><p class="text-[10px] text-gray-400">{{ now()->format('l, d M Y') }} — FBR POS</p></div>
        </div>
        <div class="flex items-center gap-2">
            @include('fbr-pos.dashboard-styles._style-picker')
            <div x-data="{ fbrEnabled: {{ $fbrReportingStatus ? 'true' : 'false' }}, loading: false }" class="flex items-center gap-2 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-1.5"><span class="text-[10px] font-bold text-gray-500">FBR</span><button @click="loading=true; fetch('{{ route('fbrpos.api.toggle-fbr-reporting') }}', {method:'POST', headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Content-Type':'application/json'}}).then(r=>r.json()).then(d=>{fbrEnabled=d.enabled; loading=false;})" :class="fbrEnabled ? 'bg-sky-600' : 'bg-gray-300'" class="relative inline-flex h-5 w-9 rounded-full transition-colors" :disabled="loading"><span :class="fbrEnabled ? 'translate-x-4' : 'translate-x-0'" class="pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow mt-0.5 ml-0.5 transition"></span></button><span x-text="fbrEnabled ? 'ON' : 'OFF'" :class="fbrEnabled ? 'text-sky-600' : 'text-red-500'" class="text-[9px] font-bold"></span></div>
            <a href="{{ route('fbrpos.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-sky-600 text-white text-[11px] font-bold rounded-xl hover:bg-sky-700 transition"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg> New Sale</a>
        </div>
    </div>
    <div class="fbo-banner p-4 flex flex-col sm:flex-row items-center justify-between gap-3 fbo-a fbo-2">
        <div class="flex items-center gap-3"><div class="w-10 h-10 rounded-xl bg-white/10 flex items-center justify-center"><svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg></div><div><p class="text-[10px] font-bold text-sky-200/70 uppercase tracking-wider">Tax Status</p><p class="text-sm font-bold text-white">Rs. {{ number_format($todayStats->tax ?? 0) }} today | Rs. {{ number_format($monthStats->tax ?? 0) }} month</p></div></div>
        @if($company->fbr_pos_id)<span class="px-3 py-1 rounded-lg text-[10px] font-bold bg-white/10 text-emerald-300">POS #{{ $company->fbr_pos_id }}</span>@endif
    </div>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 fbo-a fbo-3">
        <div class="fbo-stat"><div class="fbo-accent bg-blue-500"></div><p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Today's Sales</p><p class="text-xl font-black text-gray-900 dark:text-white">Rs.{{ number_format($todayStats->revenue ?? 0) }}</p></div>
        <div class="fbo-stat"><div class="fbo-accent bg-indigo-500"></div><p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Orders</p><p class="text-xl font-black text-gray-900 dark:text-white">{{ $todayStats->count ?? 0 }}</p></div>
        <div class="fbo-stat"><div class="fbo-accent bg-emerald-500"></div><p class="text-[9px] font-bold text-gray-400 uppercase mb-1">FBR Submitted</p><p class="text-xl font-black text-emerald-600">{{ $fbrSubmitted }}</p></div>
        <div class="fbo-stat"><div class="fbo-accent bg-amber-500"></div><p class="text-[9px] font-bold text-gray-400 uppercase mb-1">FBR Pending</p><p class="text-xl font-black text-amber-600">{{ $fbrPending }}</p></div>
    </div>
    <div class="grid grid-cols-3 gap-3 fbo-a fbo-3">
        <div class="fbo-c p-3"><p class="text-[8px] text-gray-400 font-bold uppercase">Monthly Revenue</p><p class="text-base font-black text-gray-900 dark:text-white">Rs.{{ number_format($monthStats->revenue ?? 0) }}</p><p class="text-[9px] text-gray-400">{{ $monthStats->count ?? 0 }} txns</p></div>
        <div class="fbo-c p-3"><p class="text-[8px] text-gray-400 font-bold uppercase">Monthly Tax</p><p class="text-base font-black text-gray-900 dark:text-white">Rs.{{ number_format($monthStats->tax ?? 0) }}</p></div>
        <div class="fbo-c p-3"><p class="text-[8px] text-gray-400 font-bold uppercase">Today's Tax</p><p class="text-base font-black text-gray-900 dark:text-white">Rs.{{ number_format($todayStats->tax ?? 0) }}</p></div>
    </div>
    <div class="fbo-c overflow-hidden fbo-a fbo-4">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between"><h3 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Recent Transactions</h3><a href="{{ route('fbrpos.transactions') }}" class="text-[10px] font-bold text-sky-600">VIEW ALL</a></div>
        <div class="overflow-x-auto"><table class="w-full"><thead><tr class="bg-gray-50/60 dark:bg-gray-800/30"><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-4">Invoice</th><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Customer</th><th class="text-right text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Amount</th><th class="text-center text-[9px] text-gray-400 font-bold uppercase py-2 px-3">FBR</th><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-4">Date</th></tr></thead><tbody>
            @forelse($recentTransactions as $txn)
            <tr class="border-b border-gray-50 dark:border-gray-800/50 hover:bg-sky-50/30 transition"><td class="py-2 px-4"><a href="{{ route('fbrpos.show', $txn->id) }}" class="text-[11px] font-bold text-sky-600">{{ $txn->invoice_number }}</a></td><td class="py-2 px-3 text-[11px] text-gray-600 dark:text-gray-400">{{ $txn->customer_name ?? 'Walk-in' }}</td><td class="py-2 px-3 text-right text-[11px] font-bold text-gray-900 dark:text-white">Rs.{{ number_format($txn->total_amount, 2) }}</td><td class="py-2 px-3 text-center"><span class="text-[8px] font-bold px-1.5 py-0.5 rounded-full {{ $txn->fbr_status === 'submitted' ? 'bg-green-100 text-green-700' : ($txn->fbr_status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">{{ ucfirst($txn->fbr_status ?? 'pending') }}</span></td><td class="py-2 px-4 text-[10px] text-gray-400">{{ $txn->created_at->format('d M h:i A') }}</td></tr>
            @empty<tr><td colspan="5" class="py-8 text-center text-[11px] text-gray-400">No transactions yet</td></tr>@endforelse
        </tbody></table></div>
    </div>
</div>
