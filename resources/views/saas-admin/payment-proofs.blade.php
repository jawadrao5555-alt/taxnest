<x-admin-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-white">Payment Proofs</h1>
    </div>

    @if(!empty($tableMissing) && $tableMissing)
        <div class="bg-amber-900/30 border border-amber-700 text-amber-300 rounded-lg px-4 py-3 text-sm">
            The payment_proofs table is not present yet. Run <code class="font-mono">php artisan migrate --force</code> to enable this feature.
        </div>
    @else
        @php
            $tabs = ['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected', 'all' => 'All'];
            $activeTab = in_array($status ?? 'pending', array_keys($tabs), true) ? ($status ?? 'pending') : 'pending';
        @endphp

        <div class="flex flex-wrap gap-2 mb-5">
            @foreach($tabs as $key => $label)
                <a href="{{ route('saas.admin.payment-proofs', ['status' => $key]) }}"
                   class="px-3 py-1.5 rounded-lg text-xs font-semibold transition {{ $activeTab === $key ? 'admin-btn text-white' : 'bg-gray-800 text-gray-400 hover:text-gray-200' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm table-cards">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-800 bg-gray-800/50">
                            <th class="px-4 py-3">Company</th>
                            <th class="px-4 py-3">Requested Plan</th>
                            <th class="px-4 py-3">Amount</th>
                            <th class="px-4 py-3 hidden md:table-cell">Reference</th>
                            <th class="px-4 py-3 hidden lg:table-cell">Paid On</th>
                            <th class="px-4 py-3 hidden sm:table-cell">Submitted</th>
                            <th class="px-4 py-3 text-center">Proof</th>
                            <th class="px-4 py-3 text-center">Status</th>
                            <th class="px-4 py-3 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        @forelse($proofs as $proof)
                        <tr class="hover:bg-gray-800/50 align-top" x-data="{ panel: null }">
                            <td class="px-4 py-3 text-white font-medium">
                                {{ $proof->company->name ?? ('#' . $proof->company_id) }}
                                @if($proof->notes)
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-[220px]">{{ $proof->notes }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-300">
                                @if($proof->isExtraBranch())
                                    {{-- Extra-branch add-on request — no package, no cycle. --}}
                                    @php $ebQty = max(1, (int) ($proof->extra_branch_qty ?? 1)); @endphp
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-bold bg-sky-900/40 text-sky-300 border border-sky-700/60">Extra branch &times; {{ $ebQty }}</span>
                                    <span class="block text-[11px] text-gray-500 dark:text-gray-400 mt-1">
                                        Add-on only · Rs {{ number_format(\App\Services\BranchAddonService::PRICE_PER_YEAR) }}/branch/year
                                        @if($proof->pricingPlan)
                                            · on {{ $proof->pricingPlan->name }}
                                        @endif
                                    </span>
                                @elseif($proof->pricingPlan)
                                    @php
                                        $reqCycle = \App\Services\SubscriptionAssignmentService::normalizeCycle($proof->billing_cycle);
                                        $reqPriced = \App\Services\SubscriptionAssignmentService::computePrice($proof->pricingPlan, $reqCycle, $proof->company);
                                    @endphp
                                    <span class="text-white font-medium">{{ $proof->pricingPlan->name }}</span>
                                    <span class="block text-[11px] text-gray-500 dark:text-gray-400">
                                        {{ \App\Models\Subscription::getCycleLabel($reqPriced['cycle']) }} · PKR {{ number_format($reqPriced['final_price']) }}
                                        @if(($reqPriced['extra_branch_price'] ?? 0) > 0)
                                            <span class="block">= PKR {{ number_format($reqPriced['base_price']) }} package + PKR {{ number_format($reqPriced['extra_branch_price']) }} for {{ $reqPriced['extra_branch_slots'] }} extra branch(es)</span>
                                        @endif
                                    </span>
                                @else
                                    <span class="text-gray-500 dark:text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-300">
                                {{ $proof->amount !== null ? 'PKR ' . number_format((float) $proof->amount) : '—' }}
                                @php $pmLabels = ['bank' => 'Bank Transfer', 'jazzcash' => 'JazzCash', 'easypaisa' => 'EasyPaisa', 'other' => 'Other']; @endphp
                                @if($proof->payment_method)
                                    <span class="block text-[11px] text-gray-500 dark:text-gray-400">{{ $pmLabels[$proof->payment_method] ?? ucfirst($proof->payment_method) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-400 hidden md:table-cell">{{ $proof->reference ?: '—' }}</td>
                            <td class="px-4 py-3 text-gray-400 text-xs hidden lg:table-cell">{{ optional($proof->payment_date)->format('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-400 text-xs hidden sm:table-cell">{{ $proof->created_at->format('d M Y, H:i') }}</td>
                            <td class="px-4 py-3 text-center">
                                @if($proof->file_pruned_at)
                                    <span class="text-[11px] text-gray-500 dark:text-gray-400" title="File deleted by retention cleanup on {{ $proof->file_pruned_at->format('d M Y') }} — record kept for audit">File removed</span>
                                @else
                                    <a href="{{ route('saas.admin.payment-proofs.download', $proof->id) }}"
                                       class="inline-flex items-center gap-1 text-xs text-indigo-400 hover:text-indigo-300">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                        View
                                    </a>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                @php
                                    $badge = [
                                        'pending' => 'bg-amber-900/30 text-amber-400',
                                        'verified' => 'bg-emerald-900/30 text-emerald-400',
                                        'rejected' => 'bg-red-900/30 text-red-400',
                                    ][$proof->status] ?? 'bg-gray-800 text-gray-400';
                                @endphp
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $badge }}">{{ ucfirst($proof->status) }}</span>
                                @if($proof->status === 'pending' && $proof->auto_access_until)
                                    @php $expSoon = $proof->auto_access_until->isFuture() && $proof->auto_access_until->lte(now()->addDays(2)->endOfDay()); @endphp
                                    @if($expSoon)
                                        <span class="mt-1 inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-bold bg-red-900/40 text-red-300 border border-red-700/60"
                                              title="Temporary access ends {{ $proof->auto_access_until->format('d M Y') }} — verify NOW or the customer gets locked out automatically">
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M12 3l9.196 15.9A1 1 0 0120.33 20H3.67a1 1 0 01-.866-1.5L12 3z"/></svg>
                                            Access ends {{ $proof->auto_access_until->isToday() ? 'TODAY' : $proof->auto_access_until->format('d M') }}
                                        </span>
                                    @else
                                        <p class="text-[11px] mt-1 {{ $proof->auto_access_until->isFuture() ? 'text-emerald-400' : 'text-red-400' }}"
                                           title="10-day access auto-granted on upload — expires if not verified">
                                            Temp access {{ $proof->auto_access_until->isFuture() ? 'until ' . $proof->auto_access_until->format('d M Y') : 'EXPIRED ' . $proof->auto_access_until->format('d M Y') }}
                                        </p>
                                    @endif
                                @endif
                                @if($proof->status === 'rejected' && $proof->reject_reason)
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-1 max-w-[180px] mx-auto">{{ $proof->reject_reason }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center whitespace-nowrap">
                                @if($proof->status === 'pending')
                                    <button @click="panel = (panel === 'approve' ? null : 'approve')" class="text-xs text-emerald-400 hover:text-emerald-300 mr-2">Approve</button>
                                    <button @click="panel = (panel === 'reject' ? null : 'reject')" class="text-xs text-red-400 hover:text-red-300">Reject</button>

                                    @if($proof->isExtraBranch())
                                    {{-- Add-on approval: SIRF slots barhte hain. Koi plan/cycle
                                         select nahi — subscription row, miyaad aur qeemat
                                         bilkul waise hi rehte hain. --}}
                                    <div x-show="panel === 'approve'" x-cloak class="mt-3 text-left bg-gray-800/60 border border-gray-700 rounded-lg p-3 space-y-2 min-w-[240px]">
                                        @php $ebQtySel = max(1, (int) ($proof->extra_branch_qty ?? 1)); @endphp
                                        <p class="text-[11px] text-gray-400 mb-1">Extra branch request. Approving adds <span class="text-gray-200 font-medium">branch slots only</span> — the package, its expiry and the subscription row are not touched.</p>
                                        <p class="text-[11px] text-gray-500">Current paid slots: {{ (int) ($proof->company->extra_branch_slots ?? 0) }}</p>
                                        <form method="POST" action="{{ route('saas.admin.payment-proofs.approve', $proof->id) }}" class="space-y-2">
                                            @csrf
                                            <label class="block text-[11px] text-gray-400">Branch slots to add</label>
                                            <input type="number" name="extra_branch_qty" value="{{ $ebQtySel }}" min="1" max="{{ \App\Services\BranchAddonService::MAX_QTY }}" required class="w-full bg-gray-900 border border-gray-700 rounded-lg text-white text-xs px-2 py-2">
                                            <button type="submit" class="w-full px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-lg transition">Approve Extra Branch</button>
                                        </form>
                                    </div>
                                    @else
                                    <div x-show="panel === 'approve'" x-cloak class="mt-3 text-left bg-gray-800/60 border border-gray-700 rounded-lg p-3 space-y-2 min-w-[240px]">
                                        @php $reqCycleSel = \App\Services\SubscriptionAssignmentService::normalizeCycle($proof->billing_cycle); @endphp
                                        @if($proof->pricingPlan)
                                            <p class="text-[11px] text-gray-400 mb-1">Requested: <span class="text-gray-200 font-medium">{{ $proof->pricingPlan->name }}</span> · {{ \App\Models\Subscription::getCycleLabel($reqCycleSel) }}. Approve as-is or change below.</p>
                                        @else
                                            <p class="text-[11px] text-gray-400 mb-1">No package requested — choose one to assign.</p>
                                        @endif
                                        <form method="POST" action="{{ route('saas.admin.payment-proofs.approve', $proof->id) }}" class="space-y-2">
                                            @csrf
                                            <select name="pricing_plan_id" required class="w-full bg-gray-900 border border-gray-700 rounded-lg text-white text-xs px-2 py-2">
                                                <option value="">Select Plan</option>
                                                @foreach($plans as $p)
                                                    <option value="{{ $p->id }}" @selected($proof->pricing_plan_id == $p->id)>{{ $p->name }} — {{ strtoupper($p->product_type ?? 'di') }} (PKR {{ number_format($p->price) }})</option>
                                                @endforeach
                                            </select>
                                            <select name="billing_cycle" required class="w-full bg-gray-900 border border-gray-700 rounded-lg text-white text-xs px-2 py-2">
                                                <option value="monthly" @selected($reqCycleSel === 'monthly')>Monthly</option>
                                                <option value="quarterly" @selected($reqCycleSel === 'quarterly')>Quarterly</option>
                                                <option value="semi_annual" @selected($reqCycleSel === 'semi_annual')>Semi-Annual</option>
                                                <option value="annual" @selected($reqCycleSel === 'annual')>Annual</option>
                                            </select>
                                            <button type="submit" class="w-full px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-lg transition">Approve &amp; Unlock</button>
                                        </form>
                                    </div>
                                    @endif

                                    <div x-show="panel === 'reject'" x-cloak class="mt-3 text-left bg-gray-800/60 border border-gray-700 rounded-lg p-3 space-y-2 min-w-[240px]">
                                        <form method="POST" action="{{ route('saas.admin.payment-proofs.reject', $proof->id) }}" class="space-y-2">
                                            @csrf
                                            <input type="text" name="reject_reason" maxlength="255" placeholder="Reason (optional)" class="w-full bg-gray-900 border border-gray-700 rounded-lg text-white text-xs px-2 py-2">
                                            <button type="submit" class="w-full px-3 py-2 bg-red-600 hover:bg-red-700 text-white text-xs font-semibold rounded-lg transition">Confirm Reject</button>
                                        </form>
                                    </div>
                                @else
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ optional($proof->verified_at)->format('d M Y') ?? '—' }}</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="9" class="px-4 py-12 text-center text-gray-500 dark:text-gray-400">No payment proofs found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if($proofs->hasPages())<div class="mt-4">{{ $proofs->links() }}</div>@endif
    @endif
</div>
</x-admin-layout>
