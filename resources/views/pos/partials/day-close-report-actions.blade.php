{{-- Keep the existing GET routes and their authorization. X/Z is a state,
     not a choice the shop owner should have to make. --}}
@if(!$existingReport || !($dcIso ?? false))
@php
    $reportPdfUrl = $existingReport
        ? route('pos.day-close-pdf', $existingReport->id)
        : route('pos.day-close-x-pdf', ['date' => $date]);
    $reportThermalUrl = $existingReport
        ? route('pos.day-close-thermal', $existingReport->id)
        : route('pos.day-close-x-thermal', ['date' => $date]);
@endphp
<div class="flex flex-wrap items-center gap-2" data-day-close-report-actions>
    <a href="{{ $reportPdfUrl }}" target="_blank" rel="noopener" class="px-4 py-2 bg-emerald-700 text-white text-sm font-semibold rounded-lg hover:bg-emerald-800 transition">
        {{ __('pos.dc_report_pdf') }}
    </a>
    <a href="{{ $reportThermalUrl }}" target="_blank" rel="noopener" class="px-4 py-2 bg-gray-800 text-white text-sm font-semibold rounded-lg hover:bg-gray-900 transition">
        {{ __('pos.dc_report_thermal') }}
    </a>
</div>
@endif
