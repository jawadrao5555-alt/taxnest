<x-app-layout>
    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-6">
                <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">Consultant Access</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Control which tax consultants can monitor this company. You can revoke access at any time.</p>
            </div>

            @if(session('success'))
            <div class="mb-4 p-4 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-700">{{ session('success') }}</div>
            @endif
            @if(session('error'))
            <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">{{ session('error') }}</div>
            @endif

            {{-- What consultants can do --}}
            <div class="mb-6 p-4 bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-800 rounded-lg text-xs text-sky-800 dark:text-sky-200 leading-relaxed">
                A linked consultant sees your company's <strong>health summary</strong> (plan, expiry, invoice quota, FBR failures) on their console and can <strong>open your account</strong> to work inside it — every entry is logged in your audit trail with their name. They never get access without your approval here.
            </div>

            {{-- Pending requests --}}
            @php($pending = $links->where('status', 'pending'))
            @if($pending->isNotEmpty())
            <div class="bg-amber-50 dark:bg-amber-900/10 border border-amber-300 dark:border-amber-700 rounded-xl p-5 mb-6">
                <h3 class="text-sm font-bold text-amber-800 dark:text-amber-300 mb-3">Pending consultant requests</h3>
                <div class="space-y-2">
                    @foreach($pending as $link)
                    <div class="flex flex-wrap items-center justify-between gap-3 bg-white dark:bg-gray-800 border border-amber-200 dark:border-amber-800 rounded-lg px-4 py-3">
                        <div>
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $link->consultant->name ?? 'Unknown user' }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $link->consultant->email ?? '' }} · requested {{ $link->updated_at->diffForHumans() }}</p>
                        </div>
                        <div class="flex gap-2">
                            <form method="POST" action="{{ route('company.consultants.approve', $link->id) }}">
                                @csrf
                                <button type="submit" class="px-4 py-1.5 bg-emerald-600 text-white rounded-lg text-xs font-semibold hover:bg-emerald-700 transition">Approve</button>
                            </form>
                            <form method="POST" action="{{ route('company.consultants.reject', $link->id) }}">
                                @csrf
                                <button type="submit" class="px-4 py-1.5 bg-red-600 text-white rounded-lg text-xs font-semibold hover:bg-red-700 transition">Reject</button>
                            </form>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Active consultants --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden mb-6">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100">Linked consultants</h3>
                </div>
                @php($active = $links->where('status', 'active'))
                @if($active->isEmpty())
                <p class="px-5 py-10 text-center text-gray-400 text-sm">No consultant is linked to this company.</p>
                @else
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($active as $link)
                    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div>
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $link->consultant->name ?? 'Unknown user' }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $link->consultant->email ?? '' }} · linked {{ $link->approved_at?->format('d M Y') ?? $link->updated_at->format('d M Y') }}</p>
                        </div>
                        <form method="POST" action="{{ route('company.consultants.revoke', $link->id) }}"
                              onsubmit="return confirm('Revoke this consultant\'s access? They will be removed immediately, even mid-session.');">
                            @csrf
                            <button type="submit" class="px-4 py-1.5 bg-red-600 text-white rounded-lg text-xs font-semibold hover:bg-red-700 transition">Revoke access</button>
                        </form>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>

            {{-- Invite codes --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
                    <div>
                        <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100">Invite your consultant</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Generate a code and share it — your consultant enters it on their console. Single use, valid 7 days.</p>
                    </div>
                    <form method="POST" action="{{ route('company.consultants.invite') }}">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700 transition">Generate invite code</button>
                    </form>
                </div>
                @if($invites->isNotEmpty())
                <div class="mt-3 space-y-2">
                    @foreach($invites as $invite)
                    <div class="flex flex-wrap items-center justify-between gap-3 border border-gray-100 dark:border-gray-700 rounded-lg px-4 py-2.5">
                        <div class="flex items-center gap-3">
                            <span class="font-mono font-bold text-gray-900 dark:text-gray-100 tracking-wider">{{ $invite->code }}</span>
                            <span class="text-xs text-gray-400">expires {{ $invite->expires_at->format('d M Y') }}</span>
                        </div>
                        <form method="POST" action="{{ route('company.consultants.invite-revoke', $invite->id) }}">
                            @csrf
                            <button type="submit" class="text-xs text-red-500 hover:text-red-600 font-semibold">Revoke</button>
                        </form>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
