<x-admin-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold text-white mb-1">Consultants</h1>
    <p class="text-sm text-gray-400 mb-6">Affiliate program oversight — consultants, client links, commissions and payouts.</p>

    {{-- Consultants --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-gray-800"><h3 class="text-sm font-semibold text-white">Registered consultants</h3></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-800 bg-gray-800/50">
                        <th class="px-4 py-3">Consultant</th>
                        <th class="px-4 py-3">Code</th>
                        <th class="px-4 py-3 text-center">Clients</th>
                        <th class="px-4 py-3 text-center">Referred</th>
                        <th class="px-4 py-3 text-right">Pending (Rs)</th>
                        <th class="px-4 py-3 text-right">Paid (Rs)</th>
                        <th class="px-4 py-3 text-center">Rate %</th>
                        <th class="px-4 py-3 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @forelse($profiles as $p)
                    @php($pSums = $sums->get($p->user_id, collect()))
                    <tr class="hover:bg-gray-800/50">
                        <td class="px-4 py-3">
                            <p class="text-white font-medium">{{ $p->user->name ?? '—' }}</p>
                            <p class="text-xs text-gray-500">{{ $p->user->email ?? '' }}</p>
                        </td>
                        <td class="px-4 py-3 text-gray-300 font-mono text-xs">{{ $p->referral_code }}</td>
                        <td class="px-4 py-3 text-center text-gray-300">{{ $activeLinks[$p->user_id] ?? 0 }}</td>
                        <td class="px-4 py-3 text-center text-gray-300">{{ $referred[$p->user_id] ?? 0 }}</td>
                        <td class="px-4 py-3 text-right text-amber-400">{{ number_format((float) optional($pSums->firstWhere('status', 'pending'))->total) }}</td>
                        <td class="px-4 py-3 text-right text-emerald-400">{{ number_format((float) optional($pSums->firstWhere('status', 'paid'))->total) }}</td>
                        <td class="px-4 py-3 text-center">
                            <form method="POST" action="{{ route('saas.admin.consultants.rate', $p->id) }}" class="inline-flex items-center gap-1">
                                @csrf
                                <input type="number" name="commission_rate" value="{{ (float) $p->commission_rate }}" step="0.5" min="0" max="100"
                                       class="w-16 bg-gray-800 border border-gray-700 rounded text-white text-xs px-2 py-1 text-center">
                                <button class="text-xs text-indigo-400 hover:underline">Save</button>
                            </form>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <form method="POST" action="{{ route('saas.admin.consultants.toggle', $p->id) }}" class="inline">
                                @csrf
                                <button class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $p->status === 'active' ? 'bg-emerald-900/30 text-emerald-400 hover:bg-red-900/30 hover:text-red-400' : 'bg-red-900/30 text-red-400 hover:bg-emerald-900/30 hover:text-emerald-400' }}"
                                        title="Click to {{ $p->status === 'active' ? 'disable' : 'enable' }}">
                                    {{ ucfirst($p->status) }}
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-500">No consultants yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Pending payouts --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-gray-800 flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-sm font-semibold text-white">Pending commissions</h3>
            <div class="flex items-center gap-4">
                <form method="POST" action="{{ route('saas.admin.consultants.min-payout') }}" class="inline-flex items-center gap-1" title="Pending balance below this carries over; 0 = no minimum">
                    @csrf
                    <label class="text-xs text-gray-500">Min payout Rs</label>
                    <input type="number" name="min_payout" value="{{ (float) ($minPayout ?? 0) }}" min="0" step="100"
                           class="w-24 bg-gray-800 border border-gray-700 rounded text-white text-xs px-2 py-1 text-right">
                    <button class="text-xs text-indigo-400 hover:underline">Save</button>
                </form>
                <span class="text-xs text-gray-500">Rs {{ number_format((float) $pendingCommissions->sum('amount')) }} total</span>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-800 bg-gray-800/50">
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Consultant</th>
                        <th class="px-4 py-3">Client</th>
                        <th class="px-4 py-3">Payment</th>
                        <th class="px-4 py-3">Payout details</th>
                        <th class="px-4 py-3 text-right">Amount (Rs)</th>
                        <th class="px-4 py-3 text-right">Mark paid</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @forelse($pendingCommissions as $c)
                    <tr class="hover:bg-gray-800/50">
                        <td class="px-4 py-3 text-gray-400 text-xs">{{ $c->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-white">{{ $c->consultant->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-300">{{ $c->company_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-400 text-xs">{{ $c->description }} · base {{ number_format((float) $c->base_amount) }} @ {{ rtrim(rtrim(number_format((float) $c->rate_percent, 2), '0'), '.') }}%</td>
                        <td class="px-4 py-3 text-xs">
                            @php($pp = $profilesByUser->get($c->consultant_user_id))
                            @if($pp && $pp->hasPayoutDetails())
                                <p class="text-gray-300 font-medium">{{ $pp->payoutMethodLabel() }}@if($pp->payout_method === 'bank' && $pp->payout_bank_name) · {{ $pp->payout_bank_name }}@endif</p>
                                <p class="text-gray-500">{{ $pp->payout_account_title }}</p>
                                <p class="text-gray-400 font-mono">{{ $pp->payout_account_number }}</p>
                            @else
                                <span class="text-gray-600">Not provided</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-amber-400 font-semibold">{{ number_format((float) $c->amount) }}</td>
                        <td class="px-4 py-3 text-right">
                            <form method="POST" action="{{ route('saas.admin.consultants.mark-paid', $c->id) }}" class="inline-flex items-center gap-1 justify-end">
                                @csrf
                                <input type="text" name="payout_reference" placeholder="Ref (optional)" maxlength="255"
                                       class="w-28 bg-gray-800 border border-gray-700 rounded text-white text-xs px-2 py-1">
                                <button class="px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded transition">Paid</button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-500">Nothing pending — all settled.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
        {{-- Links --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-800"><h3 class="text-sm font-semibold text-white">Client links (latest 50)</h3></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm table-cards">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-800 bg-gray-800/50">
                            <th class="px-4 py-3">Consultant</th>
                            <th class="px-4 py-3">Company</th>
                            <th class="px-4 py-3 text-center">Status</th>
                            <th class="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        @forelse($links as $l)
                        <tr class="hover:bg-gray-800/50">
                            <td class="px-4 py-3 text-white text-xs">{{ $l->consultant->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-300 text-xs">{{ $l->company->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium {{ $l->status === 'active' ? 'bg-emerald-900/30 text-emerald-400' : ($l->status === 'pending' ? 'bg-amber-900/30 text-amber-400' : 'bg-gray-800 text-gray-500') }}">{{ ucfirst($l->status) }}</span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if($l->status !== 'revoked')
                                <form method="POST" action="{{ route('saas.admin.consultants.revoke-link', $l->id) }}" class="inline"
                                      onsubmit="return confirm('Revoke this link?');">
                                    @csrf
                                    <button class="text-xs text-red-400 hover:text-red-300">Revoke</button>
                                </form>
                                @else
                                <span class="text-xs text-gray-600">{{ $l->revoked_by }}</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="px-4 py-10 text-center text-gray-500">No links yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Paid history --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-800"><h3 class="text-sm font-semibold text-white">Payout history (latest 30)</h3></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm table-cards">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 uppercase border-b border-gray-800 bg-gray-800/50">
                            <th class="px-4 py-3">Paid</th>
                            <th class="px-4 py-3">Consultant</th>
                            <th class="px-4 py-3">Client</th>
                            <th class="px-4 py-3 text-right">Amount (Rs)</th>
                            <th class="px-4 py-3">Ref</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        @forelse($paidCommissions as $c)
                        <tr class="hover:bg-gray-800/50">
                            <td class="px-4 py-3 text-gray-400 text-xs">{{ $c->paid_at?->format('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-3 text-white text-xs">{{ $c->consultant->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-300 text-xs">{{ $c->company_name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right text-emerald-400 font-semibold">{{ number_format((float) $c->amount) }}</td>
                            <td class="px-4 py-3 text-gray-500 text-xs">{{ $c->payout_reference ?? '—' }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-gray-500">No payouts recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</x-admin-layout>
