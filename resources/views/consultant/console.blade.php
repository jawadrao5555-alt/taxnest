<x-app-layout>
    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
                <div>
                    <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Consultant Console</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">One login — monitor all your client companies.</p>
                </div>
                @if($profile)
                <a href="/consultant/earnings" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition shadow-sm">Earnings & Referrals</a>
                @endif
            </div>

            @if(session('success'))
            <div class="mb-4 p-4 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-700">{{ session('success') }}</div>
            @endif
            @if(session('error'))
            <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">{{ session('error') }}</div>
            @endif
            @if($errors->any())
            <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">{{ $errors->first() }}</div>
            @endif

            @if($switched)
            <div class="mb-4 p-4 bg-indigo-50 border border-indigo-200 rounded-lg text-indigo-700 text-sm">
                You are currently inside a client session — use the <strong>Exit client</strong> button in the top banner before switching to another client.
            </div>
            @endif

            @if(!$profile)
                {{-- Opt-in CTA --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-8 max-w-2xl">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-3">Become a Tax Consultant on TaxNest</h3>
                    <ul class="space-y-2 text-sm text-gray-600 dark:text-gray-300 mb-6 list-disc pl-5">
                        <li><strong>One login, many clients</strong> — monitor every client's FBR health, invoice quota and plan expiry from a single dashboard.</li>
                        <li><strong>Work inside client accounts</strong> — switch into a linked client with one click (with the client's consent, fully audited).</li>
                        <li><strong>Earn commission</strong> — get your personal referral code; when a business you referred pays for a plan, you earn a share. Track earnings and payouts here.</li>
                        <li><strong>Clients stay in control</strong> — access works only after the client approves, and they can revoke it any time.</li>
                    </ul>
                    <form method="POST" action="{{ route('consultant.join') }}">
                        @csrf
                        <button type="submit" class="px-5 py-2.5 bg-emerald-600 text-white rounded-lg text-sm font-semibold hover:bg-emerald-700 transition shadow-sm">Activate Consultant Profile</button>
                    </form>
                </div>
            @else
                @if(!$profile->isActive())
                <div class="mb-4 p-4 bg-amber-50 border border-amber-200 rounded-lg text-amber-800 text-sm">
                    Your consultant profile is <strong>disabled</strong>. Existing links are paused and no new commissions accrue. Please contact support.
                </div>
                @endif

                {{-- Summary strip --}}
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold">Referral Code</p>
                        <p class="text-lg font-bold text-gray-900 dark:text-gray-100 mt-1 font-mono">{{ $profile->referral_code }}</p>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold">Linked Clients</p>
                        <p class="text-lg font-bold text-gray-900 dark:text-gray-100 mt-1">{{ count($clients) }}</p>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold">Pending Earnings</p>
                        <p class="text-lg font-bold text-amber-600 mt-1">Rs {{ number_format($earnings['pending']) }}</p>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold">Paid Out</p>
                        <p class="text-lg font-bold text-emerald-600 mt-1">Rs {{ number_format($earnings['paid']) }}</p>
                    </div>
                </div>

                {{-- Add client --}}
                <div class="grid md:grid-cols-2 gap-4 mb-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                        <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100 mb-1">Have an invite code?</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Your client generates it under <em>Management → Consultant Access</em>. Redeeming links you instantly.</p>
                        <form method="POST" action="{{ route('consultant.redeem') }}" class="flex gap-2">
                            @csrf
                            <input type="text" name="invite_code" required maxlength="30" placeholder="CI-XXXXXXXX"
                                   autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                                   class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm font-mono uppercase">
                            <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-semibold hover:bg-emerald-700 transition">Redeem</button>
                        </form>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                        <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100 mb-1">Request access by NTN</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">The client's admin must approve your request before you see anything.</p>
                        <form method="POST" action="{{ route('consultant.request') }}" class="flex gap-2">
                            @csrf
                            <input type="text" name="ntn" required maxlength="50" placeholder="Client NTN e.g. 1234567-8"
                                   autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                                   class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700 transition">Request</button>
                        </form>
                    </div>
                </div>

                {{-- Pending requests --}}
                @if($pendingLinks->isNotEmpty())
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 mb-6">
                    <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100 mb-3">Awaiting client approval</h3>
                    <div class="space-y-2">
                        @foreach($pendingLinks as $pl)
                        <div class="flex items-center justify-between gap-3 text-sm border border-gray-100 dark:border-gray-700 rounded-lg px-3 py-2">
                            <span class="text-gray-700 dark:text-gray-200">{{ $pl->company->name }} <span class="text-xs text-gray-400">(requested {{ $pl->updated_at->diffForHumans() }})</span></span>
                            <form method="POST" action="{{ route('consultant.cancel', $pl->id) }}">
                                @csrf
                                <button type="submit" class="text-xs text-red-500 hover:text-red-600 font-semibold">Cancel</button>
                            </form>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Clients & health --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                        <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100">Your Clients</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40">
                                    <th class="px-5 py-3">Company</th>
                                    <th class="px-4 py-3">Plan</th>
                                    <th class="px-4 py-3">Expiry</th>
                                    <th class="px-4 py-3">Invoice Quota</th>
                                    <th class="px-4 py-3">FBR (30d)</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @forelse($clients as $row)
                                @php($h = $row['health'])
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/30">
                                    <td class="px-5 py-3">
                                        <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $row['company']->name }}</p>
                                        <p class="text-xs text-gray-400">NTN: {{ $row['company']->ntn ?? '—' }}</p>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($h['plan_name'])
                                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium {{ $h['is_trial'] ? 'bg-sky-50 text-sky-700 border border-sky-200' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200' }}">{{ $h['plan_name'] }}</span>
                                        @else
                                            <span class="text-xs text-gray-400">No plan</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($h['days_left'] === null)
                                            <span class="text-xs text-gray-400">—</span>
                                        @elseif($h['days_left'] < 0)
                                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-semibold bg-red-50 text-red-700 border border-red-200">Expired {{ abs($h['days_left']) }}d ago</span>
                                        @elseif($h['days_left'] <= 7)
                                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200">{{ $h['days_left'] }}d left</span>
                                        @else
                                            <span class="text-xs text-gray-600 dark:text-gray-300">{{ $h['expiry']->format('d M Y') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($h['quota_limit'] === null)
                                            <span class="text-xs text-gray-600 dark:text-gray-300">{{ number_format($h['quota_used']) }} / ∞</span>
                                        @else
                                            <span class="text-xs font-medium {{ $h['quota_pct'] >= 90 ? 'text-red-600' : ($h['quota_pct'] >= 70 ? 'text-amber-600' : 'text-gray-600 dark:text-gray-300') }}">
                                                {{ number_format($h['quota_used']) }} / {{ number_format($h['quota_limit']) }} ({{ $h['quota_pct'] }}%)
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if($h['failed_30d'] > 0)
                                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-semibold bg-red-50 text-red-700 border border-red-200">{{ $h['failed_30d'] }} failed</span>
                                        @else
                                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">OK</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if(!$h['access_allowed'])
                                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-semibold bg-red-50 text-red-700 border border-red-200" title="{{ $h['access_reason'] }}">Locked</span>
                                        @elseif($h['approval_pending'])
                                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200">Approval pending</span>
                                        @else
                                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">Active</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <form method="POST" action="{{ route('consultant.switch', $row['company']->id) }}" class="inline">
                                            @csrf
                                            <button type="submit" {{ $switched || !$profile->isActive() ? 'disabled' : '' }}
                                                class="px-3 py-1.5 rounded-lg text-xs font-semibold transition {{ $switched || !$profile->isActive() ? 'bg-gray-200 dark:bg-gray-700 text-gray-400 cursor-not-allowed' : 'bg-indigo-600 text-white hover:bg-indigo-700' }}">
                                                Open Client
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('consultant.revoke', $row['link']->id) }}" class="inline"
                                              onsubmit="return confirm('Unlink {{ addslashes($row['company']->name) }}? You will lose console access to this client.');">
                                            @csrf
                                            <button type="submit" class="ml-2 text-xs text-red-500 hover:text-red-600 font-semibold">Unlink</button>
                                        </form>
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="7" class="px-5 py-12 text-center text-gray-400 text-sm">No linked clients yet — redeem an invite code or send a request above.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
