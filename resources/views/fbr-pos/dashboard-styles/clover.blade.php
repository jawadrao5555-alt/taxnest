<style>
@keyframes fbcl { from { opacity: 0; transform: translateX(-8px); } to { opacity: 1; transform: translateX(0); } }
.fbcl-a { animation: fbcl 0.3s ease forwards; }
.fbcl-1 { animation-delay: 0ms; } .fbcl-2 { animation-delay: 60ms; } .fbcl-3 { animation-delay: 120ms; } .fbcl-4 { animation-delay: 180ms; }
.fbcl-c { background: white; border-radius: 16px; border: 1px solid #e5e7eb; transition: all 0.2s; }
.dark .fbcl-c { background: rgb(17,24,39); border-color: rgb(31,41,55); }
.fbcl-c:hover { border-color: #a7f3d0; box-shadow: 0 4px 20px rgba(34,197,94,0.08); }
.fbcl-hero { background: linear-gradient(135deg, #064e3b 0%, #065f46 40%, #047857 100%); border-radius: 20px; position: relative; overflow: hidden; }
.fbcl-hero::before { content: ''; position: absolute; top: -50%; right: -30%; width: 60%; height: 120%; background: radial-gradient(circle, rgba(52,211,153,0.15) 0%, transparent 70%); }
.fbcl-bar { height: 5px; border-radius: 3px; background: #f3f4f6; overflow: hidden; }
.dark .fbcl-bar { background: rgb(31,41,55); }
</style>
<div class="space-y-5">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 fbcl-a fbcl-1">
        <div><h1 class="text-lg font-black text-gray-900 dark:text-white">{{ $company->name ?? 'Business' }}</h1><p class="text-[11px] text-gray-400">{{ now()->format('l, d M Y') }} — FBR POS</p></div>
        <div class="flex items-center gap-2">
            @include('fbr-pos.dashboard-styles._style-picker')
            <div x-data="{ fbrEnabled: {{ $fbrReportingStatus ? 'true' : 'false' }}, loading: false }" class="flex items-center gap-2 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-1.5"><span class="text-[10px] font-bold text-gray-500">FBR</span><button @click="loading=true; fetch('{{ route('fbrpos.api.toggle-fbr-reporting') }}', {method:'POST', headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Content-Type':'application/json'}}).then(r=>r.json()).then(d=>{fbrEnabled=d.enabled; loading=false;})" :class="fbrEnabled ? 'bg-emerald-600' : 'bg-gray-300'" class="relative inline-flex h-5 w-9 rounded-full transition-colors" :disabled="loading"><span :class="fbrEnabled ? 'translate-x-4' : 'translate-x-0'" class="pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow mt-0.5 ml-0.5 transition"></span></button><span x-text="fbrEnabled ? 'ON' : 'OFF'" :class="fbrEnabled ? 'text-emerald-600' : 'text-red-500'" class="text-[9px] font-bold"></span></div>
            <a href="{{ route('fbrpos.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 text-white text-[11px] font-bold rounded-xl hover:bg-emerald-700 transition"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg> New Sale</a>
        </div>
    </div>
    <div class="fbcl-hero p-6 fbcl-a fbcl-2">
        <div class="relative z-10 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div><p class="text-[10px] font-bold text-emerald-300/70 uppercase tracking-widest">Today's Revenue</p><p class="text-3xl font-black text-white mt-1">Rs. {{ number_format($todayStats->revenue ?? 0) }}</p>@if($company->fbr_pos_id)<p class="text-[9px] text-emerald-300/60 mt-1">FBR POS #{{ $company->fbr_pos_id }}</p>@endif</div>
            <div class="grid grid-cols-3 gap-4">
                <div class="text-center"><p class="text-2xl font-black text-white">{{ $todayStats->count ?? 0 }}</p><p class="text-[8px] text-emerald-300/60 uppercase font-bold">Orders</p></div>
                <div class="text-center"><p class="text-2xl font-black text-white">{{ $fbrSubmitted }}</p><p class="text-[8px] text-emerald-300/60 uppercase font-bold">FBR Done</p></div>
                <div class="text-center"><p class="text-2xl font-black text-white">{{ $fbrPending }}</p><p class="text-[8px] text-emerald-300/60 uppercase font-bold">Pending</p></div>
            </div>
        </div>
    </div>
    <div class="grid grid-cols-3 gap-3 fbcl-a fbcl-3">
        <div class="fbcl-c p-4"><div class="flex items-center justify-between mb-2"><p class="text-[9px] font-bold text-gray-400 uppercase">Monthly Revenue</p></div><p class="text-lg font-black text-gray-900 dark:text-white">Rs.{{ number_format($monthStats->revenue ?? 0) }}</p><div class="fbcl-bar mt-2"><div class="h-full bg-emerald-500 rounded" style="width: {{ min(100, ($monthStats->revenue ?? 0) > 0 ? 60 : 0) }}%"></div></div></div>
        <div class="fbcl-c p-4"><p class="text-[9px] font-bold text-gray-400 uppercase mb-2">Monthly Tax</p><p class="text-lg font-black text-gray-900 dark:text-white">Rs.{{ number_format($monthStats->tax ?? 0) }}</p><div class="fbcl-bar mt-2"><div class="h-full bg-cyan-500 rounded" style="width: {{ min(100, ($monthStats->tax ?? 0) > 0 ? 50 : 0) }}%"></div></div></div>
        <div class="fbcl-c p-4"><p class="text-[9px] font-bold text-gray-400 uppercase mb-2">Today's Tax</p><p class="text-lg font-black text-gray-900 dark:text-white">Rs.{{ number_format($todayStats->tax ?? 0) }}</p><div class="fbcl-bar mt-2"><div class="h-full bg-blue-500 rounded" style="width: {{ min(100, ($todayStats->tax ?? 0) > 0 ? 40 : 0) }}%"></div></div></div>
    </div>
    <div class="fbcl-c overflow-hidden fbcl-a fbcl-4">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between"><h3 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Recent Transactions</h3><a href="{{ route('fbrpos.transactions') }}" class="text-[10px] font-bold text-emerald-600">VIEW ALL</a></div>
        <div class="overflow-x-auto"><table class="w-full"><thead><tr class="bg-gray-50/60 dark:bg-gray-800/30"><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-4">Invoice</th><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Customer</th><th class="text-right text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Amount</th><th class="text-center text-[9px] text-gray-400 font-bold uppercase py-2 px-3">FBR</th><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-4">Date</th></tr></thead><tbody>
            @forelse($recentTransactions as $txn)
            <tr class="border-b border-gray-50 dark:border-gray-800/50 hover:bg-emerald-50/30 transition"><td class="py-2 px-4"><a href="{{ route('fbrpos.show', $txn->id) }}" class="text-[11px] font-bold text-emerald-600">{{ $txn->invoice_number }}</a></td><td class="py-2 px-3 text-[11px] text-gray-600 dark:text-gray-400">{{ $txn->customer_name ?? 'Walk-in' }}</td><td class="py-2 px-3 text-right text-[11px] font-bold text-gray-900 dark:text-white">Rs.{{ number_format($txn->total_amount, 2) }}</td><td class="py-2 px-3 text-center"><span class="text-[8px] font-bold px-1.5 py-0.5 rounded-full {{ $txn->fbr_status === 'submitted' ? 'bg-green-100 text-green-700' : ($txn->fbr_status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">{{ ucfirst($txn->fbr_status ?? 'pending') }}</span></td><td class="py-2 px-4 text-[10px] text-gray-400">{{ $txn->created_at->format('d M h:i A') }}</td></tr>
            @empty<tr><td colspan="5" class="py-8 text-center text-[11px] text-gray-400">No transactions yet</td></tr>@endforelse
        </tbody></table></div>
    </div>
</div>
