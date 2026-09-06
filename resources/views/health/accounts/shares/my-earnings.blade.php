@php
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        {{-- A doctor's own earnings, resolved from the signed-in account and
             never from a query string. This page carries no finance permission,
             so the identity has to come from who they are, not what they ask for. --}}
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ __('health.dsh_my_earnings') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ $from }} &rarr; {{ $to }}</p>
            </div>
            <form method="GET" action="{{ route('health.my.earnings') }}" class="flex items-end gap-2 print:hidden">
                <input type="date" name="from" value="{{ $from }}" class="px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                <input type="date" name="to" value="{{ $to }}" class="px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                <button class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.apply') }}</button>
            </form>
        </div>

        @include('health.accounts.shares.partials.statement-body', ['statement' => $statement, 'from' => $from, 'to' => $to, 'money' => $money, 'showSettlements' => true])
    </div>
</x-health-layout>
