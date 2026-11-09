<section class="space-y-4" aria-labelledby="pr-history-heading">
    <h2 id="pr-history-heading" class="text-xl font-bold">Pull request history</h2>
    <p class="text-sm text-slate-400">Browse every PR in the repository. Deployment and live verification are matched to the exact commit released to production.</p>
    <form method="get" action="{{ route('saas.admin.deployment-approval') }}" class="grid gap-3 rounded-xl border border-slate-800 bg-slate-900 p-4 sm:grid-cols-3">
        <input type="hidden" name="history" value="1">
        <label class="block text-sm sm:col-span-3">Search PRs
            <input class="mt-1 block w-full rounded border-slate-700 bg-slate-950 text-white" type="search" name="q" value="{{ $historyFilters['q'] ?? '' }}" maxlength="120" placeholder="PR number, issue #, title or keyword">
        </label>
        @foreach([
            'state' => ['PR status', \App\Services\DeploymentReleaseHistory::STATES],
            'module' => ['Module', \App\Services\DeploymentReleaseHistory::MODULES],
            'deployment' => ['Deployment status', \App\Services\DeploymentReleaseHistory::DEPLOYMENTS],
        ] as $field => [$label, $options])
            <label class="block text-sm">{{ $label }}
                <select name="{{ $field }}" class="mt-1 block w-full rounded border-slate-700 bg-slate-950 text-white">
                    <option value="">All</option>
                    @foreach($options as $value => $caption)
                        <option value="{{ $value }}" @selected(($historyFilters[$field] ?? '') === $value)>{{ $caption }}</option>
                    @endforeach
                </select>
            </label>
        @endforeach
        <label class="block text-sm">Activity from
            <input class="mt-1 block w-full rounded border-slate-700 bg-slate-950 text-white" type="date" name="from" value="{{ $historyFilters['from'] ?? '' }}">
        </label>
        <label class="block text-sm">Activity to
            <input class="mt-1 block w-full rounded border-slate-700 bg-slate-950 text-white" type="date" name="to" value="{{ $historyFilters['to'] ?? '' }}">
        </label>
        <div class="flex items-end gap-3">
            <button class="rounded bg-cyan-300 px-3 py-2 text-sm font-semibold text-slate-950" type="submit">Apply filters</button>
            <a href="{{ route('saas.admin.deployment-approval', ['history' => 1]) }}" class="px-2 py-2 text-sm text-cyan-300 underline">Clear</a>
        </div>
    </form>
    @if(session('success'))<p role="status">{{ session('success') }}</p>@endif
    @if($errors->any())
        <ul role="alert" class="text-amber-300">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    @endif
    @if($historyWarning)
        <p role="alert" class="text-amber-300">{{ $historyWarning }}</p>
    @else
        <p class="text-sm text-slate-400" data-pr-result-count>{{ $releases->total() }} {{ $releases->total() === 1 ? 'PR' : 'PRs' }} found</p>
        @forelse($releases as $release)
            <article class="rounded-xl border border-slate-800 bg-slate-900 p-4" data-pr-number="{{ $release['number'] }}">
                <h3 class="break-words font-semibold"><a class="text-cyan-300 underline" href="https://github.com/jawadrao5555-alt/taxnest/pull/{{ $release['number'] }}">#{{ $release['number'] }} {{ $release['title'] }}</a></h3>
                <p class="mt-1 text-sm text-slate-300">{{ \App\Services\DeploymentReleaseHistory::STATES[$release['state']] }} · {{ $release['activity_date'] ?: 'Date unavailable' }}
                    @if($release['base'] !== '') · {{ $release['base'] }} @endif
                </p>
                <p class="mt-1 text-sm text-slate-400">{{ implode(' · ', array_map(fn ($module) => \App\Services\DeploymentReleaseHistory::MODULES[$module], $release['modules'])) }}</p>
                @if($release['references'])
                    <p class="mt-1 flex flex-wrap gap-2 text-sm text-slate-400">References
                        @foreach(array_slice($release['references'], 0, 8) as $reference)
                            <a class="text-cyan-300 underline" href="https://github.com/jawadrao5555-alt/taxnest/issues/{{ $reference }}">#{{ $reference }}</a>
                        @endforeach
                    </p>
                @endif
                @if($release['description'] !== '')
                    @if($release['summary'] !== '')
                        <p class="mt-2 break-words text-sm">{{ $release['summary'] }}</p>
                    @endif
                    <details class="mt-2 text-sm">
                        <summary class="cursor-pointer text-cyan-300">PR details</summary>
                        <p class="mt-2 max-h-72 overflow-auto whitespace-pre-wrap break-words text-slate-300">{{ $release['description'] }}</p>
                    </details>
                @else
                    <p class="mt-2 text-sm text-slate-400">No PR description provided.</p>
                @endif
                @if($release['merge_sha'])
                    <p class="mt-2 break-all font-mono text-xs text-slate-400">{{ $release['merge_sha'] }}</p>
                @endif
                <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                    <div><dt>Admin approval</dt><dd>{{ $release['approval_recorded'] ? 'Recorded for this commit' : 'No matching approval record' }}</dd></div>
                    <div><dt>Deployment</dt><dd>{{ $release['live_verified'] ? 'Deployed' : \App\Services\DeploymentReleaseHistory::DEPLOYMENTS[$release['deployment']] }}</dd></div>
                    <div><dt>Live verification</dt><dd>{{ $release['live_verified'] ? 'Passed for this commit' : 'Unverified' }}</dd></div>
                </dl>
                @if($release['run_id'])
                    <a class="mt-2 inline-block text-sm text-cyan-300 underline" href="https://github.com/jawadrao5555-alt/taxnest/actions/runs/{{ $release['run_id'] }}">Deployment evidence</a>
                @endif
                @if($release['can_request'])
                    <form method="post" action="{{ route('saas.admin.deployment-approval.store') }}" class="mt-4 flex flex-wrap gap-2">
                        @csrf
                        <input type="hidden" name="pull_request_number" value="{{ $release['number'] }}">
                        <input type="hidden" name="merge_sha" value="{{ $release['merge_sha'] }}">
                        <label class="text-sm">Current admin password
                            <input class="rounded bg-slate-950 text-white" name="password" type="password" required autocomplete="current-password">
                        </label>
                        <button class="rounded bg-amber-300 px-3 py-2 font-semibold text-slate-950" type="submit">Approve deployment of this commit</button>
                    </form>
                @endif
            </article>
        @empty
            <p class="text-sm text-slate-400">No PRs match these filters.</p>
        @endforelse
        @if($releases->hasPages())
            <nav class="flex flex-wrap items-center gap-4 text-sm" aria-label="PR history pages">
                @if($releases->previousPageUrl())<a class="text-cyan-300 underline" href="{{ $releases->previousPageUrl() }}">Previous</a>@endif
                <span>Page {{ $releases->currentPage() }} of {{ $releases->lastPage() }}</span>
                @if($releases->nextPageUrl())<a class="text-cyan-300 underline" href="{{ $releases->nextPageUrl() }}">Next</a>@endif
            </nav>
        @endif
    @endif
</section>
