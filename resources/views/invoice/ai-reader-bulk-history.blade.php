<x-app-layout>
    {{--
        Task 1342: past Bulk AI Image Import batches. The workspace only knew
        about the batch it just ran, so a refresh lost the results table and
        the review summary download. Everything here is company-scoped and
        built from stored review data — the private source photos are never
        listed or linked, and a batch whose photos were already pruned still
        shows its results.
    --}}
    <div class="py-8">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="flex items-center text-xs text-gray-500 dark:text-gray-400 mb-2">
                <a href="/invoices" class="hover:text-violet-600">Invoices</a><span class="mx-2">/</span>
                <a href="{{ route('invoices.ai-reader') }}" class="hover:text-violet-600">AI Invoice Reader</a><span class="mx-2">/</span>
                <a href="{{ route('invoices.ai-reader.bulk') }}" class="hover:text-violet-600">Bulk photos</a><span class="mx-2">/</span>
                <span class="font-semibold text-gray-800 dark:text-gray-200">Batch history</span>
            </nav>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight text-gray-900 dark:text-white">Bulk photo batch history</h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Reopen a past batch to review its results, or download the review summary again.</p>
                </div>
                <a href="{{ route('invoices.ai-reader.bulk') }}" class="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2 text-xs font-bold text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">Start a new batch</a>
            </div>

            @if(!$allowed)
                <div class="mt-6 rounded-2xl border border-violet-200 bg-violet-50 p-8 text-center dark:border-violet-800 dark:bg-violet-950/30">
                    <h2 class="font-extrabold text-gray-900 dark:text-white">Bulk AI Image Import is a Premium feature</h2>
                    <a href="/billing/plans" class="mt-4 inline-flex rounded-xl bg-violet-600 px-4 py-2 text-sm font-bold text-white">View Premium plans</a>
                </div>
            @elseif($batches->isEmpty())
                <div class="mt-6 rounded-2xl border border-gray-200 bg-white p-10 text-center dark:border-gray-800 dark:bg-gray-900">
                    <p class="text-sm font-semibold text-gray-600 dark:text-gray-300">No bulk photo batches yet.</p>
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Upload invoice photos from the Bulk AI Image Import page — every batch you run is listed here.</p>
                </div>
            @else
                <div class="mt-6 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm table-cards">
                            <thead class="bg-gray-50 dark:bg-gray-800">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Photos</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Ready</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Needs review</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Duplicate</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Failed</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Summary</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Review</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach($batches as $batch)
                                    @php
                                        $summary = $summaries[$batch->id] ?? ['total' => (int) $batch->total_images, 'processed' => 0, 'counts' => ['ready' => 0, 'needs_review' => 0, 'duplicate' => 0, 'failed' => 0, 'pending' => 0], 'photos_removed' => false, 'state' => 'unfinished', 'state_label' => 'Never finished'];
                                        $stateStyles = [
                                            'completed' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
                                            'in_progress' => 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-200',
                                            'unfinished' => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
                                        ];
                                    @endphp
                                    <tr class="align-top transition hover:bg-gray-50/60 dark:hover:bg-gray-800/40">
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-300">
                                            <div class="font-semibold">{{ $batch->created_at?->format('d M Y') }}</div>
                                            <div class="text-xs text-gray-400">{{ $batch->created_at?->format('h:i A') }}</div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-bold {{ $stateStyles[$summary['state']] ?? $stateStyles['unfinished'] }}">{{ $summary['state_label'] }}</span>
                                            @if($summary['counts']['pending'] > 0)
                                                <p class="mt-1 text-[11px] text-gray-400">{{ $summary['counts']['pending'] }} photo(s) still waiting</p>
                                            @endif
                                            @if($batch->annexure_filename)
                                                <p class="mt-1 max-w-[190px] truncate text-[11px] text-emerald-600 dark:text-emerald-400" title="{{ $batch->annexure_filename }}">Annexure: {{ $batch->annexure_filename }}</p>
                                            @endif
                                            @if($summary['photos_removed'])
                                                <p class="mt-1 text-[11px] text-gray-400">Source photos removed after {{ \App\Services\BulkAiImageImportService::RETENTION_DAYS }} days — review data kept.</p>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold text-gray-700 dark:text-gray-300">{{ number_format($summary['total']) }}</td>
                                        <td class="px-4 py-3 text-right font-semibold {{ $summary['counts']['ready'] > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400' }}">{{ number_format($summary['counts']['ready']) }}</td>
                                        <td class="px-4 py-3 text-right font-semibold {{ $summary['counts']['needs_review'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400' }}">{{ number_format($summary['counts']['needs_review']) }}</td>
                                        <td class="px-4 py-3 text-right font-semibold {{ $summary['counts']['duplicate'] > 0 ? 'text-gray-700 dark:text-gray-300' : 'text-gray-400' }}">{{ number_format($summary['counts']['duplicate']) }}</td>
                                        <td class="px-4 py-3 text-right font-semibold {{ $summary['counts']['failed'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-400' }}">{{ number_format($summary['counts']['failed']) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right">
                                            @if($summary['processed'] > 0)
                                                <a href="{{ route('invoices.ai-reader.bulk.report', $batch->id) }}?format=csv" class="rounded-lg border border-gray-200 px-2 py-1 text-[10px] font-bold text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">CSV</a>
                                                <a href="{{ route('invoices.ai-reader.bulk.report', $batch->id) }}?format=pdf" class="ml-1 rounded-lg border border-gray-200 px-2 py-1 text-[10px] font-bold text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">PDF</a>
                                            @else
                                                <span class="text-xs text-gray-400">—</span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right">
                                            <a href="{{ route('invoices.ai-reader.bulk') }}?batch={{ $batch->id }}" class="inline-flex items-center rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-violet-700">Open batch</a>
                                            @if($summary['counts']['ready'] > 0 || $summary['counts']['needs_review'] > 0)
                                                <a href="{{ route('invoices.batch-review', ['ai', $batch->batch_uuid]) }}" class="mt-1 inline-flex items-center rounded-lg bg-teal-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-teal-700">Review drafts</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-4">
                    {{ $batches->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
