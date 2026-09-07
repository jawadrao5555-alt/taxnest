<x-admin-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Live Ops</h1>
        <p class="text-sm text-gray-400 mt-1">NestPOS PRA diagnostics and owner-approved remediation. Diagnosis never auto-fixes.</p>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-800 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-300">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg border border-red-800 bg-red-950/40 px-4 py-3 text-sm text-red-300">{{ session('error') }}</div>
    @endif

    <div class="grid lg:grid-cols-2 gap-6 mb-8">
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <h2 class="text-sm font-semibold text-white mb-3">Company search</h2>
            <p class="text-[11px] text-gray-500 mb-3">Same filter pattern as Companies (search + status). NestPOS PRA scope only. Super admin can open any matching shop — POS managers/viewers cannot use this panel.</p>
            <form method="get" action="{{ route('saas.admin.live-ops') }}" class="grid grid-cols-1 sm:grid-cols-3 gap-2 mb-3">
                <input type="text" name="search" value="{{ $search ?? $q }}" placeholder="Search name, NTN, owner, id, account code…" class="sm:col-span-2 w-full rounded-lg bg-gray-950 border border-gray-700 text-sm text-white px-3 py-2">
                <select name="status" class="rounded-lg bg-gray-950 border border-gray-700 text-sm text-white px-3 py-2">
                    <option value="">All statuses</option>
                    <option value="approved" @selected(($status ?? '') === 'approved')>Approved</option>
                    <option value="pending" @selected(($status ?? '') === 'pending')>Pending</option>
                    <option value="suspended" @selected(($status ?? '') === 'suspended')>Suspended</option>
                </select>
                <button class="sm:col-span-3 px-3 py-2 rounded-lg bg-indigo-600 text-white text-sm">Search</button>
            </form>
            <ul class="space-y-2 text-sm">
                @forelse($companies as $c)
                    <li class="flex items-center justify-between gap-2 border-b border-gray-800 pb-2">
                        <div>
                            <span class="text-white">{{ $c->name }}</span>
                            <span class="text-gray-500">#{{ $c->id }}</span>
                            @if($c->account_code)<span class="text-gray-600">{{ $c->account_code }}</span>@endif
                            @if($c->status)<span class="text-[10px] uppercase text-gray-500">{{ $c->status }}</span>@endif
                        </div>
                        <a href="{{ route('saas.admin.live-ops.company', $c->id) }}" class="text-indigo-400 hover:underline">Diagnostic</a>
                    </li>
                @empty
                    <li class="text-gray-500">{{ ($search ?? $q ?? '') === '' && ($status ?? '') === '' ? 'Enter a search term (same idea as /admin/companies).' : 'No NestPOS PRA matches.' }}</li>
                @endforelse
            </ul>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <h2 class="text-sm font-semibold text-white mb-3">Run diagnostic</h2>
            <form method="post" action="{{ route('saas.admin.live-ops.diagnose') }}" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Operation</label>
                    <select name="operation" class="w-full rounded-lg bg-gray-950 border border-gray-700 text-sm text-white px-3 py-2">
                        @foreach($operations as $op)
                            <option value="{{ $op }}" @selected($op === 'COMPANY_DIAGNOSTIC')>{{ $op }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Company ID</label>
                        <input type="number" name="company_id" class="w-full rounded-lg bg-gray-950 border border-gray-700 text-sm text-white px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Company name</label>
                        <input type="text" name="company_name" class="w-full rounded-lg bg-gray-950 border border-gray-700 text-sm text-white px-3 py-2">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Date from</label>
                        <input type="date" name="date_from" class="w-full rounded-lg bg-gray-950 border border-gray-700 text-sm text-white px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Date to</label>
                        <input type="date" name="date_to" class="w-full rounded-lg bg-gray-950 border border-gray-700 text-sm text-white px-3 py-2">
                    </div>
                </div>
                <button class="px-3 py-2 rounded-lg bg-indigo-600 text-white text-sm">Run (read-only)</button>
            </form>
        </div>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-4 mb-8">
        <h2 class="text-sm font-semibold text-white mb-3">Propose remediation (does not execute)</h2>
        <form method="post" action="{{ route('saas.admin.live-ops.propose') }}" class="grid md:grid-cols-2 gap-3">
            @csrf
            <div>
                <label class="block text-xs text-gray-500 mb-1">Action</label>
                <select name="action" class="w-full rounded-lg bg-gray-950 border border-gray-700 text-sm text-white px-3 py-2">
                    @foreach($actions as $a)
                        <option value="{{ $a }}">{{ $a }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Company ID</label>
                <input type="number" name="company_id" required class="w-full rounded-lg bg-gray-950 border border-gray-700 text-sm text-white px-3 py-2">
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs text-gray-500 mb-1">Parameters JSON</label>
                <textarea name="parameters_json" rows="3" placeholder='{"printer":"XP-80","transaction_id":123,"command_type":"RESYNC"}' class="w-full rounded-lg bg-gray-950 border border-gray-700 text-sm text-white px-3 py-2 font-mono"></textarea>
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs text-gray-500 mb-1">Proposal</label>
                <input type="text" name="proposal" class="w-full rounded-lg bg-gray-950 border border-gray-700 text-sm text-white px-3 py-2">
            </div>
            <div>
                <button class="px-3 py-2 rounded-lg bg-amber-600 text-white text-sm">Propose</button>
            </div>
        </form>
        <p class="text-xs text-gray-500 mt-2">Approval phrase required later: <code class="text-gray-400">{{ $approvalPhrase }}</code>. High-risk actions are rejected.</p>
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <h2 class="text-sm font-semibold text-white mb-3">Recent diagnostics</h2>
            <ul class="space-y-2 text-xs text-gray-300">
                @forelse($recentReports as $r)
                    <li>#{{ $r->id }} {{ $r->operation }} company={{ $r->company_id ?? '-' }} <span class="text-gray-500">{{ $r->created_at }}</span></li>
                @empty
                    <li class="text-gray-500">None yet</li>
                @endforelse
            </ul>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <h2 class="text-sm font-semibold text-white mb-3">Recent remediations</h2>
            <ul class="space-y-2 text-xs text-gray-300">
                @forelse($recentRemediations as $r)
                    <li>
                        <a href="{{ route('saas.admin.live-ops.remediation', $r->action_id) }}" class="text-indigo-400 hover:underline">{{ $r->action_id }}</a>
                        {{ $r->action }} {{ $r->status }} company={{ $r->company_id }}
                    </li>
                @empty
                    <li class="text-gray-500">None yet</li>
                @endforelse
            </ul>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <h2 class="text-sm font-semibold text-white mb-3">Agent commands</h2>
            <ul class="space-y-2 text-xs text-gray-300">
                @forelse($recentCommands as $c)
                    <li>{{ $c->command_type }} {{ $c->status }} company={{ $c->company_id }}</li>
                @empty
                    <li class="text-gray-500">None yet</li>
                @endforelse
            </ul>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <h2 class="text-sm font-semibold text-white mb-3">Audit trail</h2>
            <ul class="space-y-2 text-xs text-gray-300">
                @forelse($recentAudit as $a)
                    <li>{{ $a->event_type }} {{ $a->operation }} <span class="text-gray-500">{{ $a->created_at }}</span></li>
                @empty
                    <li class="text-gray-500">None yet</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
</x-admin-layout>
