<x-pos-layout>
<div class="max-w-5xl mx-auto">
    @include('pos.partials.back-link')
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ $existingReport ? __('pos.dc_summary_zreport') : __('pos.dc_summary_xreport') }}
            </h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ \Carbon\Carbon::parse($date)->format('l, d F Y') }}</p>
            @if($dcBranchName ?? null)
            <p class="text-xs text-purple-600 dark:text-purple-400 mt-1">{{ __('pos.dayclose_branch_scope', ['branch' => $dcBranchName]) }}</p>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">
            @if($existingReport && !($dcIso ?? false))
            <a target="_blank" href="{{ route('pos.day-close-summary-thermal', $existingReport->id) }}" class="px-3 py-2 bg-gray-800 text-white text-sm font-semibold rounded-lg">{{ __('pos.dc_summary_thermal') }}</a>
            <a href="{{ route('pos.day-close-summary-pdf', $existingReport->id) }}" class="px-3 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg">{{ __('pos.dc_summary_pdf') }}</a>
            @elseif(!$existingReport)
            <a target="_blank" href="{{ route('pos.day-close-x-summary-thermal', ['date' => $date]) }}" class="px-3 py-2 bg-gray-800 text-white text-sm font-semibold rounded-lg">{{ __('pos.dc_summary_thermal') }}</a>
            <a href="{{ route('pos.day-close-x-summary-pdf', ['date' => $date]) }}" class="px-3 py-2 bg-sky-600 text-white text-sm font-semibold rounded-lg">{{ __('pos.dc_summary_pdf') }}</a>
            @endif
            <a href="{{ route('pos.day-close', ['date' => $date]) }}" class="px-3 py-2 bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-white text-sm font-semibold rounded-lg">{{ __('pos.receipt_back') }}</a>
        </div>
    </div>

    @if(!$existingReport)
    <div class="mb-5 rounded-xl border-2 border-amber-400 bg-amber-50 dark:bg-amber-900/20 px-4 py-3 text-center">
        <p class="font-bold text-amber-800 dark:text-amber-300">{{ __('pos.dc_provisional_watermark') }}</p>
        <p class="text-xs text-amber-700 dark:text-amber-400 mt-1">{{ __('pos.dc_summary_x_hint') }}</p>
    </div>
    @endif

    @include('pos.partials.day-close-summary', ['summary' => $summary])
</div>
</x-pos-layout>