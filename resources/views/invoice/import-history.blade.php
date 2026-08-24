<x-app-layout>
    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <div>
                    <nav class="flex items-center text-xs text-gray-500 dark:text-gray-400 mb-1.5">
                        <a href="{{ route('dashboard') }}" class="hover:text-emerald-600 dark:hover:text-emerald-400 transition font-medium">Dashboard</a>
                        <svg class="w-3.5 h-3.5 mx-1.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        <a href="{{ route('invoices.index') }}" class="hover:text-emerald-600 dark:hover:text-emerald-400 transition font-medium">Invoices</a>
                        <svg class="w-3.5 h-3.5 mx-1.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        <span class="text-gray-800 dark:text-gray-200 font-semibold">Import History</span>
                    </nav>
                    <h2 class="font-extrabold text-2xl text-gray-900 dark:text-white leading-tight tracking-tight">Bulk Import History</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Past bulk uploads with their results — download error reports again anytime.</p>
                </div>
                <a href="{{ route('invoices.index') }}" class="inline-flex items-center px-4 py-2.5 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-widest hover:border-emerald-300 dark:hover:border-emerald-700 transition">
                    <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    Back to Invoices
                </a>
            </div>

            @if($batches->isEmpty())
                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-10 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <p class="mt-3 text-sm font-semibold text-gray-600 dark:text-gray-300">No bulk imports yet.</p>
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Use the "Bulk Import" button on the Invoices page to upload an Excel/CSV file.</p>
                </div>
            @else
                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm table-cards">
                            <thead class="bg-gray-50 dark:bg-gray-800">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">File</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Format</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Valid</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Created</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Failed</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Error Report</th>
                                    <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Review</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach($batches as $batch)
                                    @php
                                        $hasErrors = ($batch->invalid_rows ?? 0) > 0
                                            || ($batch->failed_rows ?? 0) > 0
                                            || count($batch->resultArray()['row_errors'] ?? []) > 0;
                                        $statusStyles = [
                                            'validated' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
                                            'queued' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
                                            'processing' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
                                            'completed' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
                                            'failed' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                                        ];
                                    @endphp
                                    <tr class="hover:bg-gray-50/60 dark:hover:bg-gray-800/40 transition">
                                        <td class="px-4 py-3 whitespace-nowrap text-gray-700 dark:text-gray-300">
                                            <div class="font-semibold">{{ $batch->created_at->format('d M Y') }}</div>
                                            <div class="text-xs text-gray-400">{{ $batch->created_at->format('h:i A') }}</div>
                                        </td>
                                        <td class="px-4 py-3 max-w-[220px]">
                                            <span class="block truncate font-medium text-gray-800 dark:text-gray-200" title="{{ $batch->original_filename }}">{{ $batch->original_filename ?: '—' }}</span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-bold uppercase bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ $batch->source_format }}</span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-bold {{ $statusStyles[$batch->status] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' }}">{{ ucfirst($batch->status) }}</span>
                                            @if($batch->status === 'failed' && $batch->error_message)
                                                <p class="mt-0.5 text-xs text-red-500 max-w-[200px] truncate" title="{{ $batch->error_message }}">{{ $batch->error_message }}</p>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold text-gray-700 dark:text-gray-300">{{ number_format($batch->total_rows ?? 0) }}</td>
                                        <td class="px-4 py-3 text-right text-emerald-600 dark:text-emerald-400 font-semibold">{{ number_format($batch->valid_rows ?? 0) }}</td>
                                        <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300 font-semibold">{{ number_format($batch->created_invoices ?? 0) }}</td>
                                        <td class="px-4 py-3 text-right font-semibold {{ (($batch->invalid_rows ?? 0) + ($batch->failed_rows ?? 0)) > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-400' }}">
                                            {{ number_format(($batch->invalid_rows ?? 0) + ($batch->failed_rows ?? 0)) }}
                                        </td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            @if($hasErrors)
                                                <a href="{{ route('invoices.import-error-report', $batch->id) }}" class="inline-flex items-center px-3 py-1.5 bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300 rounded-lg text-xs font-bold hover:bg-red-200 dark:hover:bg-red-900/60 transition">
                                                    <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                                    .xlsx
                                                </a>
                                            @else
                                                <span class="text-xs text-gray-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right whitespace-nowrap">
                                            @if(($batch->created_invoices ?? 0) > 0)
                                                <a href="{{ route('invoices.batch-review', ['import', $batch->id]) }}" class="inline-flex items-center px-3 py-1.5 bg-teal-600 text-white rounded-lg text-xs font-bold hover:bg-teal-700 transition">
                                                    Review drafts
                                                </a>
                                            @else
                                                <span class="text-xs text-gray-400">—</span>
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
