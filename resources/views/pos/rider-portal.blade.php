<x-pos-layout>
{{-- Rider portal (Jul 2026) — pos_rider role is CONFINED here by PosAuth.
     Today's own deliveries + mark-delivered; read-only cash khata banner. --}}
<div class="max-w-3xl mx-auto px-4 sm:px-6 py-6">

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">{{ session('error') }}</div>
    @endif

    @if(!$rider)
    <div class="p-6 rounded-xl border border-dashed border-gray-300 dark:border-gray-700 text-center text-sm text-gray-500 dark:text-gray-400">
        Your rider profile was removed. Please contact your manager.
    </div>
    @else

    <div class="flex items-center justify-between mb-1">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">My Deliveries</h1>
        <span class="text-xs text-gray-400">{{ now()->format('d M Y') }}</span>
    </div>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">Salam {{ $rider->name }} — yeh aaj ki deliveries hain. Deliver karne ke baad "Delivered" dabayen.</p>

    {{-- Cash khata banner --}}
    <div class="rounded-xl border {{ $owed > 0 ? 'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20' : 'border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20' }} p-4 mb-6">
        <div class="text-[11px] uppercase tracking-wide font-semibold {{ $owed > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-400' }}">Cash to hand over</div>
        <div class="text-2xl font-bold {{ $owed > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-400' }}">Rs. {{ number_format($owed) }}</div>
        <div class="text-[11px] {{ $owed > 0 ? 'text-amber-600/80 dark:text-amber-500' : 'text-emerald-600/80 dark:text-emerald-500' }}">{{ $owed > 0 ? 'Counter par cash jama karayen — settle hone par yeh zero ho jayega.' : 'Sab clear — koi cash pending nahi.' }}</div>
    </div>

    {{-- Today's bills --}}
    <div class="space-y-3">
        @forelse($bills as $b)
        @php
            $st = $b->delivery_status;
            $stClass = [
                'assigned' => 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300',
                'dispatched' => 'bg-purple-50 dark:bg-purple-900/20 text-purple-700 dark:text-purple-300',
                'delivered' => 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400',
                'returned' => 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400',
            ][$st] ?? 'bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400';
        @endphp
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-semibold text-gray-900 dark:text-white text-sm">{{ $b->invoice_number ?: ('#' . $b->id) }}</span>
                        <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $stClass }}">{{ $st ? ucfirst($st) : '—' }}</span>
                        @if($b->payment_method === 'cash')
                        <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400">Cash — collect Rs. {{ number_format((float) $b->total_amount) }}</span>
                        @else
                        <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300">Paid ({{ ucwords(str_replace('_',' ', $b->payment_method)) }})</span>
                        @endif
                    </div>
                    <div class="text-xs text-gray-600 dark:text-gray-300 mt-1.5">{{ $b->customer_name ?: 'Customer' }}@if($b->customer_phone) · <a href="tel:{{ $b->customer_phone }}" class="underline">{{ $b->customer_phone }}</a>@endif</div>
                    @if($b->delivery_address)
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $b->delivery_address }}</div>
                    @endif
                    <div class="text-[11px] text-gray-400 mt-0.5">{{ $b->created_at->format('h:i A') }} · Rs. {{ number_format((float) $b->total_amount) }}</div>
                </div>
                @if(in_array($st, ['assigned', 'dispatched']))
                <form method="POST" action="{{ route('pos.rider.delivered', $b->id) }}" class="shrink-0">
                    @csrf
                    <button type="submit" class="px-3.5 py-2 rounded-lg bg-emerald-600 text-white text-xs font-semibold shadow-sm hover:bg-emerald-700 transition">Delivered ✓</button>
                </form>
                @endif
            </div>
        </div>
        @empty
        <div class="p-6 rounded-xl border border-dashed border-gray-300 dark:border-gray-700 text-center text-sm text-gray-500 dark:text-gray-400">
            Aaj abhi koi delivery assign nahi hui.
        </div>
        @endforelse
    </div>
    @endif
</div>
</x-pos-layout>
