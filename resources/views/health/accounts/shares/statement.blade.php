@php
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<x-health-layout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-5 space-y-5">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-black tracking-tight">{{ $doctor?->name }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.dsh_statement_title') }} · {{ $from }} &rarr; {{ $to }}</p>
            </div>
            <div class="flex gap-2 print:hidden">
                <a href="{{ route('health.accounts.doctor-statement', array_merge([$doctor?->id], request()->query(), ['export' => 'csv'])) }}"
                   class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.export_csv') }}</a>
                <a href="{{ route('health.accounts.shares') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.back') }}</a>
            </div>
        </div>

        @include('health.accounts.shares.partials.statement-body', ['statement' => $statement, 'from' => $from, 'to' => $to, 'money' => $money, 'showSettlements' => true])
    </div>
</x-health-layout>
