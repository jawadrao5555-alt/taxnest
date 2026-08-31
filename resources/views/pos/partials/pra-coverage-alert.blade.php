{{--
    "Company ON hai, magar aadha staff OFF hai" — 1 Sep 2026.

    Frost and Brew ka waqia: company ka PRA switch ON, NTN laga hua, phir bhi
    August ke 653 bill (Rs 5,65,000+) PRA tak nahi pohanche — kyunke aik cashier
    ka APNA switch OFF tha. Panel par is ka koi nishan nahi tha, is liye maalik
    poora mahina yeh samajhta raha ke sab report ho raha hai.

    Yeh alert sirf ittila deta hai — kisi ka switch khud nahi badalta.

    Dono dashboard (retail + restaurant) is partial ko include karte hain, aur
    hisaab andar hi service se aata hai — controller data pass karne ki zaroorat
    nahi, is liye do dashboard kabhi alag nahi ho sakte.
--}}
@php
    $praGap = \App\Services\PraReportingCoverage::summary($company ?? null);
    // Sirf maalik/manager — cashier apna switch waise bhi nahi badal sakta.
    $praGapShow = ($isAdmin ?? false) && is_array($praGap);
@endphp
@if($praGapShow)
<div class="mb-4 p-3 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-300 dark:border-red-800 flex flex-col sm:flex-row sm:items-center gap-3">
    <div class="w-8 h-8 rounded-full bg-red-100 dark:bg-red-900/40 flex items-center justify-center flex-shrink-0">
        <svg class="w-4 h-4 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
    </div>
    <div class="flex-1 min-w-0">
        <h3 class="text-sm font-bold text-red-800 dark:text-red-300">{{ __('pos.pra_gap_title') }}</h3>
        <p class="text-[11px] mt-0.5 text-red-700 dark:text-red-400">
            {{ __('pos.pra_gap_body', ['names' => implode('، ', $praGap['members'])]) }}
        </p>
        @if($praGap['bills'] > 0)
        <p class="text-[11px] mt-1 font-bold text-red-800 dark:text-red-300">
            {{ __('pos.pra_gap_missed', [
                'days' => $praGap['days'],
                'bills' => number_format($praGap['bills']),
                'amount' => number_format($praGap['amount']),
            ]) }}
        </p>
        @endif
    </div>
    <a href="{{ route('pos.team') }}"
       class="flex-shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700 transition">
        {{ __('pos.pra_gap_action') }}
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    </a>
</div>
@endif
