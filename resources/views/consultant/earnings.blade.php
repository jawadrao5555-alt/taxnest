<x-app-layout>
    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
                <div>
                    <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Earnings & Referrals</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Commissions from businesses you referred to TaxNest.</p>
                </div>
                <a href="/consultant" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline font-medium">← Back to Console</a>
            </div>

            @if(session('success'))
            <div class="mb-4 p-4 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-700">{{ session('success') }}</div>
            @endif
            @if(session('error'))
            <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">{{ session('error') }}</div>
            @endif

            {{-- Referral link --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 mb-6">
                <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100 mb-1">Your referral link</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Share this with businesses. When they sign up with your code and pay for a plan, you earn <strong>{{ rtrim(rtrim(number_format((float) $profile->commission_rate, 2), '0'), '.') }}%</strong> of every recorded payment.</p>
                <div class="flex flex-col sm:flex-row gap-2" x-data="{ copied: false }">
                    <input type="text" readonly value="{{ url('/register') }}?ref={{ $profile->referral_code }}" id="refLink"
                           class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm font-mono">
                    <button type="button"
                            @click="navigator.clipboard.writeText(document.getElementById('refLink').value).then(() => { copied = true; setTimeout(() => copied = false, 2000); })"
                            class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700 transition whitespace-nowrap"
                            x-text="copied ? 'Copied!' : 'Copy Link'"></button>
                </div>
                <p class="text-xs text-gray-400 mt-2">Code: <span class="font-mono font-semibold">{{ $profile->referral_code }}</span> — clients can also type it manually on the signup form.</p>
            </div>

            {{-- Totals --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold">Pending</p>
                    <p class="text-lg font-bold text-amber-600 mt-1">Rs {{ number_format($totals['pending']) }}</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold">Paid Out</p>
                    <p class="text-lg font-bold text-emerald-600 mt-1">Rs {{ number_format($totals['paid']) }}</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold">Referred Signups</p>
                    <p class="text-lg font-bold text-gray-900 dark:text-gray-100 mt-1">{{ $totals['referred'] }}</p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold">Linked Clients</p>
                    <p class="text-lg font-bold text-gray-900 dark:text-gray-100 mt-1">{{ $totals['clients'] }}</p>
                </div>
            </div>

            {{-- Ledger --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100">Commission Ledger</h3>
                    <p class="text-xs text-gray-400">Payouts are made manually by TaxNest and marked here.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40">
                                <th class="px-5 py-3">Date</th>
                                <th class="px-4 py-3">Client</th>
                                <th class="px-4 py-3">Payment</th>
                                <th class="px-4 py-3 text-right">Base (Rs)</th>
                                <th class="px-4 py-3 text-center">Rate</th>
                                <th class="px-4 py-3 text-right">Commission (Rs)</th>
                                <th class="px-4 py-3 text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($commissions as $c)
                            <tr>
                                <td class="px-5 py-3 text-xs text-gray-500 dark:text-gray-400">{{ $c->created_at->format('d M Y') }}</td>
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{{ $c->company_name ?? '—' }}</td>
                                <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ $c->description ?? '—' }}</td>
                                <td class="px-4 py-3 text-right text-gray-600 dark:text-gray-300">{{ number_format((float) $c->base_amount) }}</td>
                                <td class="px-4 py-3 text-center text-xs text-gray-500">{{ rtrim(rtrim(number_format((float) $c->rate_percent, 2), '0'), '.') }}%</td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-900 dark:text-gray-100">{{ number_format((float) $c->amount) }}</td>
                                <td class="px-4 py-3 text-center">
                                    @if($c->status === 'paid')
                                        <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200" title="{{ $c->paid_at?->format('d M Y') }} {{ $c->payout_reference }}">Paid</span>
                                    @else
                                        <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200">Pending</span>
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="7" class="px-5 py-12 text-center text-gray-400 text-sm">No commissions yet — share your referral link to start earning.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($commissions->hasPages())<div class="px-5 py-3 border-t border-gray-100 dark:border-gray-700">{{ $commissions->links() }}</div>@endif
            </div>
        </div>
    </div>
</x-app-layout>
