<style>
@keyframes fbSlide { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
.fb-anim { animation: fbSlide 0.35s ease forwards; }
.fb-d1 { animation-delay: 0ms; } .fb-d2 { animation-delay: 60ms; } .fb-d3 { animation-delay: 120ms; } .fb-d4 { animation-delay: 180ms; }
.fb-card { background: white; border: 1px solid #e5e7eb; border-radius: 14px; transition: all 0.2s; }
.dark .fb-card { background: rgb(17,24,39); border-color: rgb(31,41,55); }
.fb-card:hover { box-shadow: 0 4px 15px rgba(0,0,0,0.06); }
.fb-stat { position: relative; overflow: hidden; border-radius: 16px; }
.fb-stat::before { content: ''; position: absolute; inset: 0; opacity: 0.03; background: repeating-linear-gradient(45deg, transparent, transparent 8px, currentColor 8px, currentColor 9px); pointer-events: none; }
.fb-stat:hover { transform: translateY(-2px); }
</style>

<div class="space-y-5">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 fb-anim fb-d1">
        <div>
            <h1 class="text-xl font-extrabold text-gray-900 dark:text-white">FBR POS Dashboard</h1>
            <p class="text-[11px] text-gray-400 mt-0.5">{{ now()->format('l, d M Y') }} — {{ $company->name ?? 'Business' }}</p>
        </div>
        <div class="flex items-center gap-2">
            @include('fbr-pos.dashboard-styles._style-picker')
            <div x-data="{ fbrEnabled: {{ $fbrReportingStatus ? 'true' : 'false' }}, loading: false }" class="flex items-center gap-2 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-1.5">
                <span class="text-[10px] font-bold text-gray-500">FBR</span>
                <button @click="loading=true; fetch('{{ route('fbrpos.api.toggle-fbr-reporting') }}', {method:'POST', headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Content-Type':'application/json'}}).then(r=>r.json()).then(d=>{fbrEnabled=d.enabled; loading=false;})" :class="fbrEnabled ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600'" class="relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full transition-colors" :disabled="loading">
                    <span :class="fbrEnabled ? 'translate-x-4' : 'translate-x-0'" class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow mt-0.5 ml-0.5 transition"></span>
                </button>
                <span x-text="fbrEnabled ? 'ON' : 'OFF'" :class="fbrEnabled ? 'text-blue-600' : 'text-red-500'" class="text-[9px] font-bold"></span>
            </div>
            <a href="{{ route('fbrpos.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white text-[11px] font-bold rounded-xl hover:bg-blue-700 transition shadow-sm">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                New Sale
            </a>
        </div>
    </div>

    @if($company->fbr_pos_id)
    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl px-4 py-3 flex items-center gap-3 fb-anim fb-d1">
        <div class="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center flex-shrink-0"><svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg></div>
        <div><p class="text-[11px] font-bold text-blue-800 dark:text-blue-300">Integrated with FBR</p><p class="text-[9px] text-blue-600 dark:text-blue-400">POS #: {{ $company->fbr_pos_id }} | {{ ucfirst($company->fbr_pos_environment ?? 'sandbox') }}</p></div>
    </div>
    @endif

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 fb-anim fb-d2">
        <div class="fb-stat bg-gradient-to-br from-blue-500 to-blue-700 p-4 shadow-lg shadow-blue-500/15">
            <div class="relative z-10"><span class="text-[9px] font-bold uppercase tracking-wider text-blue-100/70">Today's Sales</span><p class="text-xl font-extrabold text-white mt-1">Rs. {{ number_format($todayStats->revenue ?? 0) }}</p></div>
        </div>
        <div class="fb-stat bg-gradient-to-br from-indigo-500 to-indigo-700 p-4 shadow-lg shadow-indigo-500/15">
            <div class="relative z-10"><span class="text-[9px] font-bold uppercase tracking-wider text-indigo-100/70">Transactions</span><p class="text-xl font-extrabold text-white mt-1">{{ $todayStats->count ?? 0 }}</p></div>
        </div>
        <div class="fb-stat bg-gradient-to-br from-emerald-500 to-emerald-700 p-4 shadow-lg shadow-emerald-500/15">
            <div class="relative z-10"><span class="text-[9px] font-bold uppercase tracking-wider text-emerald-100/70">FBR Submitted</span><p class="text-xl font-extrabold text-white mt-1">{{ $fbrSubmitted }}</p></div>
        </div>
        <div class="fb-stat bg-gradient-to-br from-amber-500 to-amber-700 p-4 shadow-lg shadow-amber-500/15">
            <div class="relative z-10"><span class="text-[9px] font-bold uppercase tracking-wider text-amber-100/70">FBR Pending</span><p class="text-xl font-extrabold text-white mt-1">{{ $fbrPending }}</p></div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 fb-anim fb-d3">
        <div class="fb-card p-4"><p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Monthly Revenue</p><p class="text-lg font-extrabold text-gray-900 dark:text-white">Rs. {{ number_format($monthStats->revenue ?? 0) }}</p><p class="text-[9px] text-gray-400 mt-0.5">{{ $monthStats->count ?? 0 }} transactions</p></div>
        <div class="fb-card p-4"><p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Monthly Tax</p><p class="text-lg font-extrabold text-gray-900 dark:text-white">Rs. {{ number_format($monthStats->tax ?? 0) }}</p></div>
        <div class="fb-card p-4"><p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Today's Tax</p><p class="text-lg font-extrabold text-gray-900 dark:text-white">Rs. {{ number_format($todayStats->tax ?? 0) }}</p></div>
    </div>

    <div class="fb-card overflow-hidden fb-anim fb-d4">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
            <h3 class="text-[11px] font-bold text-gray-900 dark:text-white uppercase">Recent Transactions</h3>
            <a href="{{ route('fbrpos.transactions') }}" class="text-[10px] font-bold text-blue-600">VIEW ALL</a>
        </div>
        <div class="overflow-x-auto"><table class="w-full"><thead><tr class="bg-gray-50/60 dark:bg-gray-800/30"><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-4">Invoice</th><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Customer</th><th class="text-right text-[9px] text-gray-400 font-bold uppercase py-2 px-3">Amount</th><th class="text-center text-[9px] text-gray-400 font-bold uppercase py-2 px-3">FBR Status</th><th class="text-left text-[9px] text-gray-400 font-bold uppercase py-2 px-4">Date</th></tr></thead><tbody>
            @forelse($recentTransactions as $txn)
            <tr class="border-b border-gray-50 dark:border-gray-800/50 hover:bg-blue-50/30 dark:hover:bg-blue-900/5 transition">
                <td class="py-2 px-4"><a href="{{ route('fbrpos.show', $txn->id) }}" class="text-[11px] font-bold text-blue-600">{{ $txn->invoice_number }}</a></td>
                <td class="py-2 px-3 text-[11px] text-gray-600 dark:text-gray-400">{{ $txn->customer_name ?? 'Walk-in' }}</td>
                <td class="py-2 px-3 text-right text-[11px] font-bold text-gray-900 dark:text-white">Rs.{{ number_format($txn->total_amount, 2) }}</td>
                <td class="py-2 px-3 text-center"><span class="text-[8px] font-bold px-1.5 py-0.5 rounded-full {{ $txn->fbr_status === 'submitted' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : ($txn->fbr_status === 'failed' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400') }}">{{ ucfirst($txn->fbr_status ?? 'pending') }}</span></td>
                <td class="py-2 px-4 text-[10px] text-gray-400">{{ $txn->created_at->format('d M Y h:i A') }}</td>
            </tr>
            @empty
            <tr><td colspan="5" class="py-8 text-center text-[11px] text-gray-400">No transactions yet</td></tr>
            @endforelse
        </tbody></table></div>
    </div>
</div>
