<x-admin-layout>
<div class="p-4 sm:p-6 max-w-5xl mx-auto">
    <div class="mb-4 flex items-center justify-between gap-2">
        <div>
            <h1 class="text-2xl font-bold text-white">Diagnostic report</h1>
            <p class="text-sm text-gray-400">{{ $report['operation'] ?? '' }} · {{ $report['report_id'] ?? '' }}</p>
        </div>
        <a href="{{ route('saas.admin.live-ops') }}" class="text-sm text-indigo-400 hover:underline">Back</a>
    </div>
    <p class="text-sm text-emerald-300 mb-4">{{ $report['summary_text'] ?? '' }}</p>
    @if(!empty($report['data']['overall']['status']))
        <p class="text-sm mb-4">
            Overall:
            <span class="font-semibold {{ $report['data']['overall']['status'] === 'CRITICAL' ? 'text-red-400' : ($report['data']['overall']['status'] === 'ATTENTION' ? 'text-amber-300' : 'text-emerald-300') }}">{{ $report['data']['overall']['status'] }}</span>
        </p>
    @endif
    <p class="text-xs text-gray-500 mb-2">digest {{ $report['artifact_digest'] ?? '' }} · results {{ $report['result_count'] ?? 0 }}</p>
    <pre class="bg-gray-950 border border-gray-800 rounded-xl p-4 text-xs text-gray-200 overflow-auto max-h-[70vh]">{{ json_encode($report['data'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
</div>
</x-admin-layout>
