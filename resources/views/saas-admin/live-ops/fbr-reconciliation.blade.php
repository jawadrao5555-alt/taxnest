<x-admin-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-widest text-indigo-400 mb-1">Read-only diagnostic</p>
            <h1 class="text-2xl font-bold text-white">FBR POS reconciliation</h1>
            <p class="text-sm text-gray-400 mt-1">{{ $diagnostic['company']['name'] }} · Company #{{ $diagnostic['company']['id'] }}</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="rounded-full border border-gray-700 bg-gray-900 px-3 py-1 text-xs text-gray-400">No retries or changes</span>
            <a href="{{ route('saas.admin.companies.show', $diagnostic['company']['id']) }}" class="text-sm text-indigo-400 hover:underline">Back to company</a>
        </div>
    </div>

    <div class="rounded-xl border border-indigo-800/60 bg-indigo-950/30 p-4 mb-6">
        <p class="text-sm font-semibold text-indigo-200">Boundary of evidence</p>
        <p class="text-sm text-indigo-100/80 mt-1">“Local FBR IMS accepted” records the local fiscal-device callback only. Code 100 or an official number never implies central FBR verification.</p>
    </div>

    @if(!empty($diagnostic['warnings']))
    <div class="space-y-3 mb-6">
        @foreach($diagnostic['warnings'] as $warning)
        <div class="rounded-xl border border-amber-700/70 bg-amber-950/30 px-4 py-3 text-sm text-amber-200">
            <span class="font-semibold">Attention:</span> {{ $warning }}
        </div>
        @endforeach
    </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
        <div class="rounded-xl border border-gray-800 bg-gray-900 p-4">
            <p class="text-xs uppercase tracking-wider text-gray-500">Local result</p>
            <p class="mt-2 text-lg font-semibold text-emerald-300">{{ $diagnostic['local_acceptance']['label'] }}</p>
            <p class="mt-1 text-xs text-gray-400">{{ $diagnostic['local_acceptance']['detail'] }}</p>
        </div>
        <div class="rounded-xl border border-gray-800 bg-gray-900 p-4">
            <p class="text-xs uppercase tracking-wider text-gray-500">Central FBR verification</p>
            <p class="mt-2 text-lg font-semibold {{ $diagnostic['central']['label'] === 'Confirmed' ? 'text-emerald-300' : ($diagnostic['central']['label'] === 'Rejected' ? 'text-red-300' : 'text-amber-300') }}">{{ $diagnostic['central']['label'] }}</p>
            <p class="mt-1 text-xs text-gray-400">{{ $diagnostic['central']['detail'] }}</p>
        </div>
        <div class="rounded-xl border border-gray-800 bg-gray-900 p-4">
            <p class="text-xs uppercase tracking-wider text-gray-500">Desktop Agent</p>
            <p class="mt-2 text-lg font-semibold {{ $diagnostic['agent']['online'] ? 'text-emerald-300' : 'text-amber-300' }}">{{ $diagnostic['agent']['online'] ? 'Online' : 'Offline' }}</p>
            <p class="mt-1 text-xs text-gray-400">Last heartbeat: {{ $diagnostic['agent']['last_heartbeat'] ? \Carbon\Carbon::parse($diagnostic['agent']['last_heartbeat'])->format('d M Y, h:i A T') : 'Never' }}</p>
        </div>
        <div class="rounded-xl border {{ $diagnostic['environment']['mismatch'] ? 'border-red-700/70 bg-red-950/20' : 'border-gray-800 bg-gray-900' }} p-4">
            <p class="text-xs uppercase tracking-wider text-gray-500">Environment</p>
            <p class="mt-2 text-sm font-semibold text-white">Requested: {{ $diagnostic['environment']['requested'] }}</p>
            <p class="mt-1 text-sm {{ $diagnostic['environment']['mismatch'] ? 'text-red-300' : 'text-gray-300' }}">Client reported: {{ $diagnostic['environment']['client_reported'] }}</p>
            @if($diagnostic['environment']['mismatch'])
            <p class="mt-2 text-xs font-semibold text-red-300">Mismatch — stop before submission</p>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <section class="rounded-xl border border-gray-800 bg-gray-900 p-5">
            <h2 class="text-sm font-semibold text-white mb-4">Latest callback diagnostics</h2>
            @if($diagnostic['latest_callback']['available'])
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-3 text-sm">
                <div><dt class="text-xs text-gray-500">Received</dt><dd class="text-gray-200 mt-1">{{ $diagnostic['latest_callback']['received_at'] ? \Carbon\Carbon::parse($diagnostic['latest_callback']['received_at'])->format('d M Y, h:i:s A T') : '—' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Agent version</dt><dd class="text-gray-200 mt-1">{{ $diagnostic['latest_callback']['agent_version'] ?: 'Not reported' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Local IMS component version</dt><dd class="text-gray-200 mt-1">{{ $diagnostic['latest_callback']['ims_version'] ?: 'Not separately reported' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Client environment</dt><dd class="text-gray-200 mt-1">{{ $diagnostic['latest_callback']['client_environment'] }}</dd></div>
                <div><dt class="text-xs text-gray-500">Local IMS code</dt><dd class="text-gray-200 mt-1">{{ $diagnostic['latest_callback']['code'] ?: 'Not reported' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Number field</dt><dd class="text-gray-200 mt-1">{{ $diagnostic['latest_callback']['invoice_number_field'] ?: 'Not reported' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Central field</dt><dd class="text-gray-200 mt-1">{{ $diagnostic['latest_callback']['central_status'] ?: 'Not returned' }}</dd></div>
            </dl>
            @if($diagnostic['latest_callback']['error'])
            <p class="mt-4 rounded-lg border border-red-800/60 bg-red-950/20 px-3 py-2 text-xs text-red-300">{{ $diagnostic['latest_callback']['error'] }}</p>
            @endif
            <p class="mt-4 text-xs text-gray-500">Raw payloads, customer data, credentials, URLs, device identifiers and full fiscal numbers are intentionally excluded.</p>
            @else
            <p class="text-sm text-gray-400">No future callback diagnostic has been recorded yet. Existing invoices remain central verification Unknown.</p>
            @endif
        </section>

        <section class="rounded-xl border border-gray-800 bg-gray-900 p-5">
            <h2 class="text-sm font-semibold text-white mb-4">Direct credential health</h2>
            @if($diagnostic['direct_credentials']['warning'])
            <div class="rounded-lg border border-red-700/70 bg-red-950/30 p-3 text-sm font-semibold text-red-200">
                {{ $diagnostic['direct_credentials']['warning'] }}
            </div>
            @else
            <p class="text-sm text-emerald-300">No direct Production 900901 rejection is currently observed in the available FBR POS logs.</p>
            @endif
            <p class="mt-3 text-xs text-gray-500">This screen never displays direct tokens or request/response payloads.</p>
        </section>
    </div>

    <section class="rounded-xl border border-gray-800 bg-gray-900 p-5 mb-6">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div>
                <h2 class="text-sm font-semibold text-white">Transactions — last 60 days</h2>
                <p class="text-xs text-gray-500 mt-1">Local acceptance and central verdict are shown separately. Historical rows without callback diagnostics remain Unknown.</p>
            </div>
            <span class="text-xs text-gray-500">{{ count($diagnostic['transactions']) }} rows</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-800 text-left text-xs uppercase tracking-wider text-gray-500">
                        <th class="py-2 pr-4">ID</th>
                        <th class="py-2 pr-4">Local invoice</th>
                        <th class="py-2 pr-4">FBR number</th>
                        <th class="py-2 pr-4">Local result</th>
                        <th class="py-2 pr-4">Central</th>
                        <th class="py-2">Status / code</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($diagnostic['transactions'] as $row)
                    <tr class="border-b border-gray-800/70">
                        <td class="py-3 pr-4 text-gray-300">{{ $row['id'] }}</td>
                        <td class="py-3 pr-4 font-mono text-xs text-gray-300">{{ $row['invoice_number'] }}</td>
                        <td class="py-3 pr-4 font-mono text-xs text-gray-300">{{ $row['fbr_number'] }}</td>
                        <td class="py-3 pr-4"><span class="rounded-full bg-emerald-900/30 px-2 py-1 text-xs text-emerald-300">{{ $row['local_acceptance'] ? 'Accepted locally' : 'Not accepted' }}</span></td>
                        <td class="py-3 pr-4"><span class="text-xs {{ $row['central_verdict'] === 'Confirmed' ? 'text-emerald-300' : ($row['central_verdict'] === 'Rejected' ? 'text-red-300' : 'text-amber-300') }}">{{ $row['central_verdict'] }}</span></td>
                        <td class="py-3 text-xs text-gray-400">{{ $row['status'] }} / {{ $row['code'] }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="py-8 text-center text-sm text-gray-500">No FBR POS transactions in the last 60 days.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-xl border border-gray-800 bg-gray-900 p-5">
        <h2 class="text-sm font-semibold text-white mb-3">Safe next actions</h2>
        <ul class="space-y-2 text-sm text-gray-300">
            @foreach($diagnostic['next_actions'] as $action)
            <li class="flex gap-2"><span class="text-indigo-400">•</span><span>{{ $action }}</span></li>
            @endforeach
        </ul>
        <p class="mt-4 border-t border-gray-800 pt-3 text-xs text-gray-500">Read-only observation only. This page does not retry, resubmit, cancel, modify or backfill invoices.</p>
    </section>
</div>
</x-admin-layout>