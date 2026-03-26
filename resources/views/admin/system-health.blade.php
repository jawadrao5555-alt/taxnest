<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">System Health Monitor</h2>
            <div class="flex items-center space-x-2">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-bold
                    {{ $healthScore >= 80 ? 'bg-green-100 text-green-800' : ($healthScore >= 50 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') }}">
                    Health: {{ $healthScore }}%
                </span>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Pending Jobs</p>
                            <p class="text-3xl font-bold {{ $pendingJobs > 10 ? 'text-yellow-600' : 'text-gray-900' }}">{{ $pendingJobs }}</p>
                        </div>
                        <div class="p-3 bg-blue-50 rounded-lg">
                            <svg class="w-6 h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                    </div>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">In queue</p>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Failed Jobs</p>
                            <p class="text-3xl font-bold {{ $failedJobs > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $failedJobs }}</p>
                        </div>
                        <div class="p-3 {{ $failedJobs > 0 ? 'bg-red-50' : 'bg-green-50' }} rounded-lg">
                            <svg class="w-6 h-6 {{ $failedJobs > 0 ? 'text-red-600' : 'text-green-600' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.268 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        </div>
                    </div>
                    <p class="mt-2 text-sm {{ $failedJobs > 0 ? 'text-red-500 font-medium' : 'text-gray-500 dark:text-gray-400' }}">{{ $failedJobs > 0 ? 'Needs attention' : 'All clear' }}</p>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Avg FBR Response</p>
                            <p class="text-3xl font-bold text-gray-900">{{ $avgResponseTime ? number_format($avgResponseTime) : 'N/A' }}</p>
                        </div>
                        <div class="p-3 bg-purple-50 rounded-lg">
                            <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </div>
                    </div>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">milliseconds (30d avg)</p>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Retries (24h)</p>
                            <p class="text-3xl font-bold {{ $totalRetries24h > 5 ? 'text-orange-600' : 'text-gray-900' }}">{{ $totalRetries24h }}</p>
                        </div>
                        <div class="p-3 bg-orange-50 rounded-lg">
                            <svg class="w-6 h-6 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        </div>
                    </div>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Total retry attempts</p>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">System Health Indicator</h3>
                <div class="flex items-center space-x-4">
                    <div class="flex-1">
                        <div class="w-full bg-gray-200 rounded-full h-4">
                            <div class="h-4 rounded-full transition-all duration-700
                                {{ $healthScore >= 80 ? 'bg-green-500' : ($healthScore >= 50 ? 'bg-yellow-500' : 'bg-red-500') }}"
                                style="width: {{ $healthScore }}%"></div>
                        </div>
                    </div>
                    <span class="text-lg font-bold {{ $healthScore >= 80 ? 'text-green-600' : ($healthScore >= 50 ? 'text-yellow-600' : 'text-red-600') }}">{{ $healthScore }}%</span>
                </div>
                <div class="mt-4 grid grid-cols-3 gap-4 text-center">
                    <div class="p-3 rounded-lg {{ $healthScore >= 80 ? 'bg-green-50' : 'bg-gray-50' }}">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Queue</p>
                        <p class="text-sm font-semibold {{ $pendingJobs > 50 ? 'text-red-600' : 'text-green-600' }}">{{ $pendingJobs > 50 ? 'Overloaded' : ($pendingJobs > 10 ? 'Busy' : 'Healthy') }}</p>
                    </div>
                    <div class="p-3 rounded-lg {{ $failedJobs === 0 ? 'bg-green-50' : 'bg-red-50' }}">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Jobs</p>
                        <p class="text-sm font-semibold {{ $failedJobs > 0 ? 'text-red-600' : 'text-green-600' }}">{{ $failedJobs > 0 ? $failedJobs . ' Failed' : 'All Good' }}</p>
                    </div>
                    <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">FBR Today</p>
                        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $fbrSuccessToday }}/{{ $fbrLogsToday }} success</p>
                    </div>
                </div>
            </div>

            @if(isset($fbrObservability))
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">FBR Submission Metrics (30 Days)</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="p-3 bg-blue-50 rounded-lg text-center">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Avg Submission Time</p>
                        <p class="text-xl font-bold text-blue-700">{{ $fbrObservability['avg_submission_time'] ? number_format($fbrObservability['avg_submission_time']) . 'ms' : 'N/A' }}</p>
                    </div>
                    <div class="p-3 bg-green-50 rounded-lg text-center">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Success Rate</p>
                        <p class="text-xl font-bold text-green-700">{{ $fbrObservability['total_submissions'] > 0 ? round(($fbrObservability['success_count'] / $fbrObservability['total_submissions']) * 100, 1) : 0 }}%</p>
                    </div>
                    <div class="p-3 bg-red-50 rounded-lg text-center">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Failure Ratio</p>
                        <p class="text-xl font-bold text-red-700">{{ $fbrObservability['failure_ratio'] }}%</p>
                    </div>
                    <div class="p-3 bg-orange-50 rounded-lg text-center">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Retry Ratio</p>
                        <p class="text-xl font-bold text-orange-700">{{ $fbrObservability['retry_ratio'] }}%</p>
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-3 gap-4 text-sm">
                    <div class="text-center">
                        <p class="text-gray-500 dark:text-gray-400">Total Submissions</p>
                        <p class="font-semibold text-gray-800 dark:text-gray-100">{{ $fbrObservability['total_submissions'] }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-gray-500 dark:text-gray-400">Min Latency</p>
                        <p class="font-semibold text-gray-800 dark:text-gray-100">{{ $fbrObservability['min_submission_time'] ? number_format($fbrObservability['min_submission_time']) . 'ms' : 'N/A' }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-gray-500 dark:text-gray-400">Max Latency</p>
                        <p class="font-semibold text-gray-800 dark:text-gray-100">{{ $fbrObservability['max_submission_time'] ? number_format($fbrObservability['max_submission_time']) . 'ms' : 'N/A' }}</p>
                    </div>
                </div>
            </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">FBR Failure Breakdown</h3>
                    @if($failureBreakdown->count() > 0)
                    <div class="space-y-3">
                        @foreach($failureBreakdown as $failure)
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium
                                    @if($failure->failure_type === 'network_error') bg-red-100 text-red-700
                                    @elseif($failure->failure_type === 'token_error') bg-orange-100 text-orange-700
                                    @elseif($failure->failure_type === 'validation_error') bg-yellow-100 text-yellow-700
                                    @else bg-gray-100 text-gray-700 dark:text-gray-300
                                    @endif">
                                    {{ str_replace('_', ' ', ucfirst($failure->failure_type)) }}
                                </span>
                            </div>
                            <span class="text-sm font-bold text-gray-900">{{ $failure->count }}</span>
                        </div>
                        @endforeach
                    </div>
                    @else
                    <p class="text-gray-400 text-center py-4">No failures recorded</p>
                    @endif
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Company Risk Distribution</h3>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between p-3 bg-green-50 rounded-lg">
                            <span class="text-sm font-medium text-green-700">SAFE (80-100)</span>
                            <span class="text-lg font-bold text-green-700">{{ $companiesSafe }}</span>
                        </div>
                        <div class="flex items-center justify-between p-3 bg-yellow-50 rounded-lg">
                            <span class="text-sm font-medium text-yellow-700">MODERATE (50-79)</span>
                            <span class="text-lg font-bold text-yellow-700">{{ $companiesModerate }}</span>
                        </div>
                        <div class="flex items-center justify-between p-3 bg-red-50 rounded-lg">
                            <span class="text-sm font-medium text-red-700">AT RISK (0-49)</span>
                            <span class="text-lg font-bold text-red-700">{{ $companiesAtRisk }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Recent Security Events</h3>
                    <a href="/admin/security-logs" class="text-sm text-emerald-600 hover:text-emerald-700 font-medium">View All</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Action</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">User</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">IP</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse($recentSecurityLogs as $log)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 dark:bg-gray-800">
                                <td class="px-4 py-3">
                                    <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium
                                        @if($log->action === 'failed_login') bg-red-100 text-red-700
                                        @elseif($log->action === 'login') bg-green-100 text-green-700
                                        @else bg-blue-100 text-blue-700
                                        @endif">
                                        {{ str_replace('_', ' ', $log->action) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ $log->user->name ?? 'Unknown' }}</td>
                                <td class="px-4 py-3 text-sm font-mono text-gray-500 dark:text-gray-400">{{ $log->ip_address }}</td>
                                <td class="px-4 py-3 text-sm text-gray-400">{{ $log->created_at->diffForHumans() }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No security events</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-6 flex space-x-4">
                <a href="/admin/dashboard" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg font-medium text-sm hover:bg-gray-700 transition">Back to Dashboard</a>
                <a href="/admin/fbr-logs" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg font-medium text-sm hover:bg-purple-700 transition">FBR Logs</a>
                <a href="/admin/security-logs" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg font-medium text-sm hover:bg-blue-700 transition">Security Logs</a>
            </div>
        </div>
    </div>
</x-admin-layout>
