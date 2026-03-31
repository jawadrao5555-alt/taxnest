<style>
@keyframes fbt { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
.fbt-a { animation: fbt 0.3s ease forwards; }
.fbt-1{animation-delay:0ms}.fbt-2{animation-delay:50ms}.fbt-3{animation-delay:100ms}.fbt-4{animation-delay:150ms}
</style>
<div class="space-y-4">
    <div class="flex items-center justify-between fbt-a fbt-1">
        <div><h1 class="text-lg font-extrabold text-gray-900 dark:text-white">Dashboard</h1><p class="text-[11px] text-gray-400">{{ now()->format('D, d M Y') }} — {{ $company->name ?? 'Business' }}</p></div>
        <div class="flex items-center gap-2">
            @include('fbr-pos.dashboard-styles._style-picker')
            <div x-data="{ fbrEnabled: {{ $fbrReportingStatus ? 'true' : 'false' }}, loading: false }" class="flex items-center gap-2 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-1.5">
                <span class="text-[10px] font-bold text-gray-500">FBR</span>
                <button @click="loading=true; fetch('{{ route('fbrpos.api.toggle-fbr-reporting') }}', {method:'POST', headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Content-Type':'application/json'}}).then(r=>r.json()).then(d=>{fbrEnabled=d.enabled; loading=false;})" :class="fbrEnabled ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600'" class="relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full transition-colors" :disabled="loading"><span :class="fbrEnabled ? 'translate-x-4' : 'translate-x-0'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow mt-0.5 ml-0.5 transition"></span></button>
                <span x-text="fbrEnabled ? 'ON' : 'OFF'" :class="fbrEnabled ? 'text-blue-600' : 'text-red-500'" class="text-[9px] font-bold"></span>
            </div>
            <a href="{{ route('fbrpos.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-amber-500 text-white text-[11px] font-bold rounded-xl hover:bg-amber-600 transition shadow-sm"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>New Sale</a>
        </div>
    </div>

    @if($company->fbr_pos_id)
    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl px-4 py-2.5 flex items-center gap-3 fbt-a fbt-1">
        <div class="w-7 h-7 rounded-lg bg-blue-600 flex items-center justify-center flex-shrink-0"><svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg></div>
        <p class="text-[10px] font-bold text-blue-800 dark:text-blue-300">FBR Integrated — POS #{{ $company->fbr_pos_id }} ({{ ucfirst($company->fbr_pos_environment ?? 'sandbox') }})</p>
    </div>
    @endif

    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 overflow-hidden fbt-a fbt-2">
        <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-gray-100 dark:divide-gray-800">
            <div class="p-4 sm:p-5">
                <div class="flex items-center gap-1.5 mb-1"><span class="w-2 h-2 rounded-full bg-amber-500"></span><span class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Revenue</span></div>
                <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white" style="font-variant-numeric:tabular-nums">Rs.{{ number_format($todayStats->revenue ?? 0) }}</p>
            </div>
            <div class="p-4 sm:p-5">
                <div class="flex items-center gap-1.5 mb-1"><span class="w-2 h-2 rounded-full bg-blue-500"></span><span class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Orders</span></div>
                <p class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white">{{ $todayStats->count ?? 0 }}</p>
            </div>
            <div class="p-4 sm:p-5">
                <div class="flex items-center gap-1.5 mb-1"><span class="w-2 h-2 rounded-full bg-emerald-500"></span><span class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">FBR Done</span></div>
                <p class="text-xl sm:text-2xl font-black text-emerald-600">{{ $fbrSubmitted }}</p>
            </div>
            <div class="p-4 sm:p-5">
                <div class="flex items-center gap-1.5 mb-1"><span class="w-2 h-2 rounded-full bg-amber-500"></span><span class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Pending</span></div>
                <p class="text-xl sm:text-2xl font-black text-amber-600">{{ $fbrPending }}</p>
            </div>
        </div>
        <div class="h-1 flex"><div class="flex-1 bg-amber-400"></div><div class="flex-1 bg-blue-400"></div><div class="flex-1 bg-emerald-400"></div><div class="flex-1 bg-amber-400"></div></div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-100 dark:border-gray-800 p-4 fbt-a fbt-3">
        <div class="flex items-center gap-6 flex-wrap">
            <div class="flex items-center gap-3 border-l-3 border-l-blue-500 pl-3"><div><p class="text-[8px] text-gray-400 font-bold uppercase">Monthly Revenue</p><p class="text-base font-black text-gray-900 dark:text-white">Rs.{{ number_format($monthStats->revenue ?? 0) }}</p><p class="text-[9px] text-gray-400">{{ $monthStats->count ?? 0 }} txns</p></div></div>
            <div class="w-px h-8 bg-gray-100 dark:bg-gray-800"></div>
            <div class="flex items-center gap-3 border-l-3 border-l-cyan-500 pl-3"><div><p class="text-[8px] text-gray-400 font-bold uppercase">Monthly Tax</p><p class="text-base font-black text-gray-900 dark:text-white">Rs.{{ number_format($monthStats->tax ?? 0) }}</p></div></div>
            <div class="w-px h-8 bg-gray-100 dark:bg-gray-800"></div>
            <div class="flex items-center gap-3 border-l-3 border-l-emerald-500 pl-3"><div><p class="text-[8px] text-gray-400 font-bold uppercase">Today's Tax</p><p class="text-base font-black text-gray-900 dark:text-white">Rs.{{ number_format($todayStats->tax ?? 0) }}</p></div></div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-100 dark:border-gray-800 overflow-hidden fbt-a fbt-4">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between"><div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-amber-500"></span><h3 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Recent Transactions</h3></div><a href="{{ route('fbrpos.transactions') }}" class="text-[10px] font-bold text-amber-600">View All →</a></div>
        <div class="overflow-x-auto"><table class="w-full"><thead><tr class="bg-gray-50/50 dark:bg-gray-800/30"><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-4">Invoice</th><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Customer</th><th class="text-right text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Amount</th><th class="text-center text-[9px] text-gray-400 font-bold uppercase py-2 px-3">FBR</th><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-4">Date</th></tr></thead><tbody>
            @forelse($recentTransactions as $txn)
            <tr class="border-b border-gray-50 dark:border-gray-800/50 hover:bg-amber-50/30 dark:hover:bg-amber-900/5 transition"><td class="py-2.5 px-4"><a href="{{ route('fbrpos.show', $txn->id) }}" class="text-[11px] font-bold text-amber-600">{{ $txn->invoice_number }}</a></td><td class="py-2.5 px-3 text-[11px] text-gray-600 dark:text-gray-400">{{ $txn->customer_name ?? 'Walk-in' }}</td><td class="py-2.5 px-3 text-right text-[11px] font-black text-gray-900 dark:text-white">Rs.{{ number_format($txn->total_amount, 2) }}</td><td class="py-2.5 px-3 text-center"><span class="text-[8px] font-bold px-1.5 py-0.5 rounded-full {{ $txn->fbr_status === 'submitted' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : ($txn->fbr_status === 'failed' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400') }}">{{ ucfirst($txn->fbr_status ?? 'pending') }}</span></td><td class="py-2.5 px-4 text-[10px] text-gray-400">{{ $txn->created_at->format('d M h:i A') }}</td></tr>
            @empty<tr><td colspan="5" class="py-8 text-center text-[11px] text-gray-400">No transactions yet</td></tr>@endforelse
        </tbody></table></div>
    </div>
</div>
