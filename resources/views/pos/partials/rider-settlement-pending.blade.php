{{--
    Rider settlement pending — dashboard alert (owner, 25 Aug 2026).

    Pas-manzar (ZFC): day-close ke waqt ek bill isliye reh gaya ke rider ka cash
    abhi wasool nahi hua tha. Khata guard aisa bill archive karta hai (delete
    nahi) aur baad mein koi usay dobara nahi sameta — shop ko pata hi tab chala
    jab bill agle din tak para raha. Owner: "jis tarah baqi tamam issues
    dashboard par aa jate hain, is ka bhi koi notification ho — kis kis rider ki
    settlement pari hai — aur us par click karein to seedha rider settlement
    mein chala jaye."

    Sirf tab dikhta hai jab waqai kuch para ho ($riderPending khali na ho) —
    pending-bills-tile ki tarah "all clear" wali halat nahi dikhati, warna har
    dukan ke dashboard par muqim kachra ban jata.

    Saat ke saat dashboard styles mein shamil: wrapper blade style-include se
    PEHLE isay laata hai, is liye kisi style ko chhoona nahi parta.
    Sirf mojooda Tailwind classes (koi Vite rebuild darkar nahi).
--}}
@php
    $rpList = collect($riderPending ?? []);
    // Bina rider gaye delivery bills (owner, 25 Aug 2026): "rok na lagayein,
    // magar unka cash bhi dashboard par dikhe". Rok waqai nahi lagi — day-close
    // ka blocker inhe aaj bhi nahi ginta — bas yeh cash ab nazar se ojhal nahi
    // rehta. Delivery board par "Delivered (no rider)" dabate hi yeh khud
    // gayab ho jate hain.
    $rpNoRider = (array) (($riderChip['unassigned'] ?? null) ?: ['count' => 0, 'amount' => 0, 'days' => 0]);
    $rpNoRiderCount = (int) ($rpNoRider['count'] ?? 0);
    $rpNoRiderAmount = (float) ($rpNoRider['amount'] ?? 0);
    $rpTotal = (float) $rpList->sum('owed') + $rpNoRiderAmount;
    $rpBills = (int) $rpList->sum('bills') + $rpNoRiderCount;
    // Sab se purana khata — 1 din ya us se zyada purana ho to LAAL (owner ka
    // "Touseef" wala usool: purana atka cash numaya hona chahiye).
    $rpOldest = max((int) $rpList->max('days'), $rpNoRiderCount > 0 ? (int) ($rpNoRider['days'] ?? 0) : 0);
    $rpStale = $rpOldest >= 1;
    $rpCards = $rpList->count() + ($rpNoRiderCount > 0 ? 1 : 0);
@endphp
@if($rpList->isNotEmpty() || $rpNoRiderCount > 0)
<div class="mb-4 rounded-xl border p-4 {{ $rpStale ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-700' : 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-700' }}">
    <div class="flex flex-col sm:flex-row sm:items-center gap-3 mb-3">
        <div class="flex-1 min-w-0">
            <h3 class="text-sm font-bold {{ $rpStale ? 'text-red-900 dark:text-red-200' : 'text-amber-900 dark:text-amber-200' }}">
                {{ __('pos.rider_pending_title') }}
                <span class="ml-1 inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full {{ $rpStale ? 'bg-red-500' : 'bg-amber-500' }} text-white text-[11px] font-extrabold">{{ $rpCards }}</span>
            </h3>
            <p class="text-[11px] mt-0.5 {{ $rpStale ? 'text-red-700 dark:text-red-300' : 'text-amber-700 dark:text-amber-300' }}">
                {{ __('pos.rider_pending_hint') }}
            </p>
        </div>
        <div class="text-left sm:text-right flex-shrink-0">
            <div class="text-xl font-extrabold {{ $rpStale ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400' }}">Rs. {{ number_format($rpTotal) }}</div>
            <div class="text-[10px] text-gray-500 dark:text-gray-400">{{ __('pos.rider_pending_bills_total', ['count' => $rpBills]) }}</div>
        </div>
    </div>
    {{-- Har rider apna card — click par deliveries page par usi rider par. --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
        @foreach($rpList as $rp)
        <a href="{{ route('pos.deliveries') }}#rider-{{ $rp['id'] }}"
           class="flex items-center justify-between gap-2 px-3 py-2 rounded-lg border bg-white dark:bg-gray-800 {{ $rp['days'] >= 1 ? 'border-red-200 dark:border-red-700 hover:bg-red-50 dark:hover:bg-red-900/30' : 'border-amber-200 dark:border-amber-700 hover:bg-amber-100 dark:hover:bg-amber-900/40' }} transition">
            <span class="min-w-0">
                <span class="block text-[12px] font-bold text-gray-900 dark:text-white truncate">{{ $rp['name'] }}</span>
                <span class="block text-[10px] text-gray-500 dark:text-gray-400">
                    {{ __('pos.unsettled_cash_bills', ['count' => $rp['bills']]) }}
                    @if($rp['days'] >= 1)
                        <span class="font-bold text-red-600 dark:text-red-400">• {{ __('pos.del_oldest_days', ['days' => $rp['days']]) }}</span>
                    @endif
                </span>
            </span>
            <span class="text-sm font-extrabold flex-shrink-0 {{ $rp['days'] >= 1 ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400' }}">Rs. {{ number_format($rp['owed']) }}</span>
        </a>
        @endforeach
        @if($rpNoRiderCount > 0)
        {{-- Bina rider gaya delivery bill: kisi rider ke khaate par nahi, is
             liye "settle" ka rasta nahi — delivery board par "Delivered (no
             rider)" ya rider assign karte hi yeh khud saaf ho jata hai. --}}
        <a href="{{ route('pos.deliveries') }}"
           class="flex items-center justify-between gap-2 px-3 py-2 rounded-lg border bg-white dark:bg-gray-800 {{ (int) ($rpNoRider['days'] ?? 0) >= 1 ? 'border-red-200 dark:border-red-700 hover:bg-red-50 dark:hover:bg-red-900/30' : 'border-amber-200 dark:border-amber-700 hover:bg-amber-100 dark:hover:bg-amber-900/40' }} transition">
            <span class="min-w-0">
                <span class="block text-[12px] font-bold text-gray-900 dark:text-white truncate">{{ __('pos.rider_pending_no_rider') }}</span>
                <span class="block text-[10px] text-gray-500 dark:text-gray-400">
                    {{ __('pos.rider_pending_no_rider_sub', ['count' => $rpNoRiderCount]) }}
                    @if((int) ($rpNoRider['days'] ?? 0) >= 1)
                        <span class="font-bold text-red-600 dark:text-red-400">&bull; {{ __('pos.del_oldest_days', ['days' => (int) $rpNoRider['days']]) }}</span>
                    @endif
                </span>
            </span>
            <span class="text-sm font-extrabold flex-shrink-0 {{ (int) ($rpNoRider['days'] ?? 0) >= 1 ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400' }}">Rs. {{ number_format($rpNoRiderAmount) }}</span>
        </a>
        @endif
    </div>
</div>
@endif
