<x-admin-layout>
    <div class="min-h-[calc(100dvh-4rem)] bg-slate-950 text-slate-100">
        <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 sm:py-10">
            <div class="mb-8 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <div class="mb-3 inline-flex items-center gap-2 rounded-full border border-amber-400/30 bg-amber-400/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-amber-300">
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-300"></span>
                        Production control
                    </div>
                    <h1 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">Deployment approvals</h1>
                    <p class="mt-2 max-w-xl text-sm leading-6 text-slate-400">Review the exact build before it reaches production. Approve only when the commit, requester, and result are expected.</p>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-900/70 px-4 py-3 text-left sm:min-w-[190px]">
                    <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Owner decision</p>
                    <p class="mt-1 text-sm font-semibold text-slate-200">One approval. One release.</p>
                </div>
            </div>

            @if(session('success'))
                <div role="status" class="mb-6 rounded-xl border border-emerald-400/30 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">
                    {{ session('success') }}
                </div>
            @endif
            @if($errors->any())
                <div role="alert" class="mb-6 rounded-xl border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">
                    <p class="font-semibold">We could not complete that action.</p>
                    <ul class="mt-1 list-inside list-disc text-rose-300">
                        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            <section class="mb-8 rounded-2xl border border-slate-800 bg-slate-900 shadow-2xl shadow-black/20">
                <div class="border-b border-slate-800 px-5 py-5 sm:px-6">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-cyan-400">New release request</p>
                    <h2 class="mt-1 text-xl font-semibold text-white">Choose a validated release</h2>
                    <p class="mt-1 text-sm text-slate-400">TaxNest fetches the exact HEAD SHA from GitHub. Only open, non-draft cursor/* PRs targeting main with a green validate check appear here.</p>
                </div>
                <form method="post" action="{{ route('saas.admin.deployment-approval.store') }}" class="grid gap-5 px-5 py-5 sm:grid-cols-[1fr_auto] sm:items-end sm:px-6">
                    @csrf
                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-200">Eligible pull request</span>
                        <select name="pull_request_number" required class="min-h-12 w-full rounded-xl border border-slate-700 bg-slate-950 px-3 text-base text-white outline-none transition focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20">
                            <option value="">Select a green PR</option>
                            @foreach($eligiblePullRequests as $pr)
                                <option value="{{ $pr['number'] }}" @selected((string) old('pull_request_number') === (string) $pr['number'])>
                                    #{{ $pr['number'] }} — {{ $pr['title'] }} — {{ substr($pr['head_sha'], 0, 12) }}
                                </option>
                            @endforeach
                        </select>
                        @if(empty($eligiblePullRequests))
                            <span class="mt-2 block text-xs text-amber-300">No eligible green cursor/* PR is currently available.</span>
                        @endif
                    </label>
                    <button type="submit" @disabled(empty($eligiblePullRequests)) class="min-h-12 rounded-xl bg-cyan-400 px-5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300 disabled:cursor-not-allowed disabled:opacity-40 focus:outline-none focus:ring-2 focus:ring-cyan-300 focus:ring-offset-2 focus:ring-offset-slate-900">Create request &amp; email approvers</button>
                </form>
            </section>

            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-white">Release queue</h2>
                    <p class="mt-1 text-sm text-slate-400">Newest requests are shown first.</p>
                </div>
                <span class="rounded-full bg-slate-800 px-3 py-1 text-xs font-semibold text-slate-400">{{ $requests->count() }} {{ \Illuminate\Support\Str::plural('request', $requests->count()) }}</span>
            </div>

            @forelse($requests as $row)
                @php
                    $status = strtolower((string) $row->status);
                    $isPending = $status === 'pending';
                    $statusClasses = $isPending ? 'border-amber-400/30 bg-amber-400/10 text-amber-300' : ($status === 'approved' ? 'border-emerald-400/30 bg-emerald-400/10 text-emerald-300' : 'border-slate-700 bg-slate-800 text-slate-400');
                @endphp
                <article id="request-{{ $row->request_id }}" class="mb-4 overflow-hidden rounded-2xl border {{ request('review') === $row->request_id ? 'ring-2 ring-cyan-400' : '' }} {{ $isPending ? 'border-amber-400/30' : 'border-slate-800' }} bg-slate-900">
                    <div class="border-b border-slate-800 px-5 py-4 sm:px-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-sm text-slate-500">#{{ $row->pull_request_number }}</span>
                                    <span class="rounded-full border px-2.5 py-1 text-xs font-bold uppercase tracking-wider {{ $statusClasses }}">{{ $row->status }}</span>
                                </div>
                                <p class="mt-2 break-all font-mono text-xs text-slate-400">{{ $row->request_id }}</p>
                            </div>
                            @if($isPending)<span class="inline-flex items-center gap-2 text-sm font-semibold text-amber-200"><span class="h-2 w-2 animate-pulse rounded-full bg-amber-300"></span>Action needed</span>@endif
                        </div>
                    </div>
                    <div class="grid gap-5 px-5 py-5 sm:grid-cols-2 sm:px-6 lg:grid-cols-4">
                        <div class="sm:col-span-2 lg:col-span-4"><p class="text-xs font-bold uppercase tracking-wider text-slate-500">Repository</p><p class="mt-1 break-all font-mono text-sm text-slate-200">{{ $row->repository }}</p></div>
                        <div class="sm:col-span-2 lg:col-span-4"><p class="text-xs font-bold uppercase tracking-wider text-slate-500">Exact commit SHA</p><p class="mt-1 break-all font-mono text-sm text-slate-200">{{ $row->head_sha }}</p></div>
                        <div><p class="text-xs font-bold uppercase tracking-wider text-slate-500">Expires</p><p class="mt-1 text-sm text-slate-200">{{ $row->expires_at ?? 'Not set' }}</p></div>
                        <div><p class="text-xs font-bold uppercase tracking-wider text-slate-500">Requested by</p><p class="mt-1 break-words text-sm text-slate-200">{{ $row->requestedBy?->name ?? 'Not recorded' }}</p></div>
                        <div><p class="text-xs font-bold uppercase tracking-wider text-slate-500">Approved by</p><p class="mt-1 break-words text-sm text-slate-200">{{ $row->approvedBy?->name ?? 'Awaiting approval' }}</p></div>
                    </div>
                    @if($row->deploy_result)
                        <div class="mx-5 mb-5 rounded-xl border border-slate-800 bg-slate-950/70 px-4 py-3 sm:mx-6">
                            <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Deployment result</p>
                            <p class="mt-1 text-sm font-semibold text-slate-200">{{ ucfirst($row->deploy_result) }}</p>
                            @if($row->deployed_sha)<p class="mt-2 break-all font-mono text-xs text-slate-400">{{ $row->deploy_result === 'success' ? 'Deployed SHA' : 'Target SHA' }}: {{ $row->deployed_sha }}</p>@endif
                            @if($row->failure_summary)<p class="mt-2 text-sm text-rose-300">{{ $row->failure_summary }}</p>@endif
                            @if($row->workflow_run_url)<a href="{{ $row->workflow_run_url }}" target="_blank" rel="noopener" class="mt-2 inline-flex min-h-10 items-center text-sm font-semibold text-cyan-300 underline decoration-cyan-400/40 underline-offset-4 hover:text-cyan-200">Open deployment run</a>@endif
                        </div>
                    @endif
                    @if($isPending)
                        <div class="border-t border-amber-400/20 bg-amber-400/5 px-5 py-5 sm:px-6">
                            <p class="text-sm font-semibold text-amber-100">Confirm this release</p>
                            <p class="mt-1 text-sm leading-6 text-slate-400">Enter your current admin password to approve PR #{{ $row->pull_request_number }}. This is the final production gate.</p>
                            <form method="post" action="{{ route('saas.admin.deployment-approval.approve', $row->request_id) }}" class="mt-4 flex flex-col gap-3 sm:flex-row">
                                @csrf
                                <label class="min-w-0 flex-1"><span class="sr-only">Current admin password</span><input class="min-h-12 w-full rounded-xl border border-slate-700 bg-slate-950 px-4 text-base text-white outline-none placeholder:text-slate-600 focus:border-amber-300 focus:ring-2 focus:ring-amber-300/20" type="password" name="password" required autocomplete="current-password" placeholder="Current admin password"></label>
                                <button type="submit" class="min-h-12 rounded-xl bg-amber-300 px-6 text-sm font-bold text-slate-950 transition hover:bg-amber-200 focus:outline-none focus:ring-2 focus:ring-amber-300 focus:ring-offset-2 focus:ring-offset-slate-900">Approve deployment</button>
                            </form>
                        </div>
                    @endif
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-700 bg-slate-900/60 px-6 py-14 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-800 text-cyan-300"><span class="text-xl font-bold">—</span></div>
                    <h3 class="mt-4 text-lg font-semibold text-white">No deployment requests yet</h3>
                    <p class="mx-auto mt-2 max-w-sm text-sm leading-6 text-slate-400">Create a request above when a pull request is ready for an owner decision.</p>
                </div>
            @endforelse
        </div>
    </div>
</x-admin-layout>