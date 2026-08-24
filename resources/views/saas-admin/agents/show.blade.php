<x-admin-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <a href="{{ route('saas.admin.agents') }}" class="text-xs text-gray-500 hover:text-gray-300">&larr; Agents / Partners</a>
            <h1 class="text-2xl font-bold text-white mt-1">{{ $agent->name }}
                <span class="ml-2 align-middle inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $agent->status === 'active' ? 'bg-emerald-900/30 text-emerald-400' : 'bg-red-900/30 text-red-400' }}">{{ $agent->status }}</span>
            </h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                {{ $agent->cnic ?? 'CNIC —' }} · {{ $agent->phone ?? 'Phone —' }} · {{ $agent->territory ?? 'Territory —' }}
                · Schedule A: New {{ rtrim(rtrim(number_format($agent->rate_new, 2), '0'), '.') }}% / Renewal {{ rtrim(rtrim(number_format($agent->rate_renewal, 2), '0'), '.') }}%
            </p>
            <p class="text-xs text-indigo-400 mt-1">Referral code: {{ $agent->referral_code }} · {{ url('/register?ref='.$agent->referral_code) }}</p>
        </div>
    </div>

    {{-- Edit agent --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6" x-data="{ showEdit: false }">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-semibold text-white">Agent Profile</h3>
            <button @click="showEdit = !showEdit" class="text-xs text-indigo-400 hover:underline" x-text="showEdit ? 'Hide' : 'Edit'"></button>
        </div>
        <form x-show="showEdit" method="POST" action="{{ route('saas.admin.agents.update', $agent->id) }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end mt-3">
            @csrf @method('PUT')
            <div><label class="text-xs text-gray-400 mb-1 block">Name *</label>
                <input type="text" name="name" required value="{{ old('name', $agent->name) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500"></div>
            <div><label class="text-xs text-gray-400 mb-1 block">CNIC</label>
                <input type="text" name="cnic" value="{{ old('cnic', $agent->cnic) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500"></div>
            <div><label class="text-xs text-gray-400 mb-1 block">Phone</label>
                <input type="text" name="phone" value="{{ old('phone', $agent->phone) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500"></div>
            <div><label class="text-xs text-gray-400 mb-1 block">Email</label>
                <input type="email" name="email" value="{{ old('email', $agent->email) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500"></div>
            <div><label class="text-xs text-gray-400 mb-1 block">New Portal Password</label>
                <input type="password" name="password" minlength="8" placeholder="Leave blank to keep current" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500"></div>
            <div><label class="text-xs text-gray-400 mb-1 block">Territory</label>
                <input type="text" name="territory" value="{{ old('territory', $agent->territory) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500"></div>
            <div><label class="text-xs text-gray-400 mb-1 block">New Sale % *</label>
                <input type="number" name="rate_new" required step="0.01" min="0" max="100" value="{{ old('rate_new', $agent->rate_new) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500"></div>
            <div><label class="text-xs text-gray-400 mb-1 block">Renewal % *</label>
                <input type="number" name="rate_renewal" required step="0.01" min="0" max="100" value="{{ old('rate_renewal', $agent->rate_renewal) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500"></div>
            <div><label class="text-xs text-gray-400 mb-1 block">Notes</label>
                <input type="text" name="notes" value="{{ old('notes', $agent->notes) }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500"></div>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Save (rates apply to future commissions)</button>
        </form>
    </div>

    {{-- Monthly commission report --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <h3 class="text-sm font-semibold text-white">Commission Report — {{ $month->format('F Y') }}</h3>
            <div class="flex items-center gap-2">
                <form method="GET" action="{{ route('saas.admin.agents.show', $agent->id) }}">
                    <select name="month" onchange="this.form.submit()" class="bg-gray-800 border border-gray-700 rounded-lg text-white text-xs px-2 py-1.5 focus:ring-2 focus:ring-indigo-500">
                        @foreach($months as $m)
                            <option value="{{ $m }}" {{ $m === $month->format('Y-m') ? 'selected' : '' }}>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $m)->format('F Y') }}</option>
                        @endforeach
                    </select>
                </form>
                <a href="{{ route('saas.admin.agents.export', ['id' => $agent->id, 'month' => $month->format('Y-m')]) }}" class="px-3 py-1.5 bg-emerald-700 hover:bg-emerald-600 text-white text-xs font-semibold rounded-lg transition">Export CSV</a>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
            <div class="bg-gray-800/60 rounded-lg p-3"><p class="text-[11px] text-gray-500 uppercase">Earned</p><p class="text-lg font-bold text-emerald-400">Rs {{ number_format($totals['earned'], 2) }}</p></div>
            <div class="bg-gray-800/60 rounded-lg p-3"><p class="text-[11px] text-gray-500 uppercase">Clawback</p><p class="text-lg font-bold text-red-400">Rs {{ number_format($totals['clawback'], 2) }}</p></div>
            <div class="bg-gray-800/60 rounded-lg p-3"><p class="text-[11px] text-gray-500 uppercase">Net Payable ({{ $month->format('M Y') }})</p><p class="text-lg font-bold text-white">Rs {{ number_format($totals['net'], 2) }}</p></div>
            <div class="bg-gray-800/60 rounded-lg p-3"><p class="text-[11px] text-gray-500 uppercase">Lifetime Net</p><p class="text-lg font-bold text-indigo-400">Rs {{ number_format($totals['lifetime'], 2) }}</p></div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-800 bg-gray-800/50">
                        <th class="px-3 py-2">Date</th>
                        <th class="px-3 py-2">Company</th>
                        <th class="px-3 py-2">Type</th>
                        <th class="px-3 py-2 text-right">Base (Rs)</th>
                        <th class="px-3 py-2 text-right">Rate %</th>
                        <th class="px-3 py-2 text-right">Commission (Rs)</th>
                        <th class="px-3 py-2">Description</th>
                        <th class="px-3 py-2">Payout</th>
                        <th class="px-3 py-2 text-center">Refund?</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @forelse($lines as $l)
                    <tr class="hover:bg-gray-800/50">
                        <td class="px-3 py-2 text-gray-400 whitespace-nowrap">{{ optional($l->created_at)->format('d M Y') }}</td>
                        <td class="px-3 py-2 text-white">{{ $l->company_name ?: optional($l->company)->name ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $l->type === 'clawback' ? 'bg-red-900/30 text-red-400' : ($l->type === 'new' ? 'bg-emerald-900/30 text-emerald-400' : 'bg-sky-900/30 text-sky-400') }}">{{ $l->type }}</span>
                        </td>
                        <td class="px-3 py-2 text-right text-gray-300">{{ number_format((float) $l->base_amount, 2) }}</td>
                        <td class="px-3 py-2 text-right text-gray-300">{{ rtrim(rtrim(number_format($l->rate_percent, 2), '0'), '.') }}</td>
                        <td class="px-3 py-2 text-right font-medium {{ (float) $l->amount < 0 ? 'text-red-400' : 'text-white' }}">{{ number_format((float) $l->amount, 2) }}</td>
                        <td class="px-3 py-2 text-gray-400">{{ $l->description }}</td>
                        <td class="px-3 py-2">
                            @if(in_array($l->type, ['new', 'renewal']) && $l->status !== 'paid')
                            <form method="POST" action="{{ route('saas.admin.agents.mark-paid', [$agent->id, $l->id]) }}">@csrf<button class="text-xs text-emerald-400 hover:underline">Mark paid</button></form>
                            @else
                            <span class="text-xs text-gray-400">{{ $l->status }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-center">
                            @if(in_array($l->type, ['new', 'renewal']))
                            <button type="button" class="text-xs text-red-400 hover:underline"
                                onclick="document.getElementById('clawback-form').style.display='block'; document.getElementById('cb-commission-id').value='{{ $l->id }}'; document.getElementById('cb-line-label').textContent='Line #{{ $l->id }} — {{ addslashes($l->company_name) }} (Rs {{ number_format((float) $l->amount, 2) }})'; document.getElementById('cb-amount').max='{{ (float) $l->amount }}';">Clawback</button>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="9" class="px-3 py-10 text-center text-gray-500 dark:text-gray-400">No commission lines in {{ $month->format('F Y') }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Clawback form (refund/reversal adjustment) --}}
        <form id="clawback-form" style="display:none" method="POST" action="{{ route('saas.admin.agents.clawback', $agent->id) }}" class="mt-4 bg-red-900/10 border border-red-900/40 rounded-lg p-4">
            @csrf
            <input type="hidden" name="commission_id" id="cb-commission-id">
            <p class="text-xs text-red-300 mb-2">Refund/reversal clawback against: <span id="cb-line-label" class="font-semibold"></span></p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
                <div><label class="text-xs text-gray-400 mb-1 block">Amount (Rs) — blank = full remaining</label>
                    <input type="number" name="amount" id="cb-amount" step="0.01" min="0.01" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-red-500"></div>
                <div><label class="text-xs text-gray-400 mb-1 block">Reason *</label>
                    <input type="text" name="reason" required maxlength="500" placeholder="e.g. Customer refunded — payment reversed" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-red-500 placeholder-gray-600"></div>
                <div class="flex gap-2">
                    <button type="submit" class="px-4 py-2 bg-red-700 hover:bg-red-600 text-white text-sm font-semibold rounded-lg transition">Record Clawback</button>
                    <button type="button" onclick="document.getElementById('clawback-form').style.display='none'" class="px-3 py-2 bg-gray-800 text-gray-300 text-sm rounded-lg">Cancel</button>
                </div>
            </div>
        </form>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Introduced companies --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-3">Introduced Companies ({{ $companies->count() }})</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm table-cards">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-800 bg-gray-800/50">
                            <th class="px-3 py-2">Company</th>
                            <th class="px-3 py-2">Product</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2">Since</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        @forelse($companies as $c)
                        <tr class="hover:bg-gray-800/50">
                            <td class="px-3 py-2">
                                @if($c->deleted_at)
                                    <span class="text-gray-500">{{ $c->name }} (deleted)</span>
                                @else
                                    <a href="{{ route('saas.admin.companies.show', $c->id) }}" class="text-indigo-400 hover:underline">{{ $c->name }}</a>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-gray-400 uppercase text-xs">{{ $c->product_type }}</td>
                            <td class="px-3 py-2"><span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $c->company_status === 'active' ? 'bg-emerald-900/30 text-emerald-400' : 'bg-amber-900/30 text-amber-400' }}">{{ $c->company_status ?? $c->status }}</span></td>
                            <td class="px-3 py-2 text-gray-400 whitespace-nowrap">{{ optional($c->created_at)->format('d M Y') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">No companies linked yet. Set "Introduced by Agent" on a company's edit page.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Cleared payments --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-white mb-3">Cleared Payments (latest {{ $clearedPayments->count() }})</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm table-cards">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-800 bg-gray-800/50">
                            <th class="px-3 py-2">Verified</th>
                            <th class="px-3 py-2">Company</th>
                            <th class="px-3 py-2">Package</th>
                            <th class="px-3 py-2 text-right">Amount (Rs)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        @forelse($clearedPayments as $p)
                        <tr class="hover:bg-gray-800/50">
                            <td class="px-3 py-2 text-gray-400 whitespace-nowrap">{{ optional($p->verified_at)->format('d M Y') ?? '—' }}</td>
                            <td class="px-3 py-2 text-white">{{ optional($p->company)->name ?? ('Company #' . $p->company_id) }}</td>
                            <td class="px-3 py-2 text-gray-400">{{ optional($p->pricingPlan)->name ?? '—' }} {{ $p->billing_cycle ? '(' . $p->billing_cycle . ')' : '' }}</td>
                            <td class="px-3 py-2 text-right text-white">{{ number_format((float) $p->amount, 2) }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">No cleared payments yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</x-admin-layout>
