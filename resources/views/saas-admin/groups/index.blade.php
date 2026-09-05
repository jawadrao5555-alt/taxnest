<x-admin-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
        <h1 class="text-2xl font-bold text-white">Business Groups</h1>
        @unless($disabled)
        <form method="POST" action="{{ route('saas.admin.groups.resync') }}" onsubmit="return confirm('Re-check every account for group matches? Detached pairs stay detached.');">
            @csrf
            <button type="submit" class="flex items-center gap-2 px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-gray-300 hover:text-white text-xs rounded-lg transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                Re-sync
            </button>
        </form>
        @endunless
    </div>
    <p class="text-sm text-gray-500 mb-6 max-w-3xl">
        One person can legitimately hold a PRA POS, an FBR POS and a Digital Invoice account — same NTN, same CNIC, three separate businesses.
        The accounts stay fully separate for them; this section is ours: who is really one customer, what they have not bought yet, and who keeps starting fresh trials.
    </p>

    @if($disabled)
    <div class="bg-amber-900/20 border border-amber-800/60 rounded-xl p-4 text-sm text-amber-300">
        Grouping tables are not migrated on this server yet. Run <code class="text-amber-200">php artisan migrate</code> and reload.
    </div>
    @else

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        @foreach([
            ['Groups', $stats['groups'] ?? 0, 'text-indigo-400'],
            ['Accounts grouped', $stats['accounts'] ?? 0, 'text-emerald-400'],
            ['Biggest group', $stats['biggest'] ?? 0, 'text-sky-400'],
            ['3+ accounts', $stats['multi'] ?? 0, 'text-amber-400'],
        ] as [$label, $value, $tone])
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <p class="text-xs text-gray-500">{{ $label }}</p>
            <p class="text-2xl font-bold {{ $tone }}">{{ $value }}</p>
        </div>
        @endforeach
    </div>

    <form method="GET" class="mb-5 flex flex-wrap gap-2">
        <input type="text" name="q" value="{{ $q }}" placeholder="Group code, company, account code, NTN, CNIC, email or phone"
               class="flex-1 min-w-[240px] px-3 py-2 bg-gray-900 border border-gray-800 rounded-lg text-sm text-gray-200 placeholder-gray-600 focus:outline-none focus:border-indigo-600">
        <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Search</button>
        @if($q !== '')
        <a href="{{ route('saas.admin.groups') }}" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 text-gray-300 text-sm rounded-lg transition">Clear</a>
        @endif
    </form>

    @if($groups->isEmpty())
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-8 text-center">
        <p class="text-gray-400 text-sm">{{ $q !== '' ? 'No group matched that search.' : 'No business groups yet — they appear automatically when one person signs up on more than one product.' }}</p>
    </div>
    @else

    <div class="space-y-3">
        @foreach($groups as $group)
        @php $card = $cards[$group->id] ?? ['products' => [], 'missing' => [], 'trials' => 0, 'duplicates' => [], 'trial_abuse' => false]; @endphp
        <a href="{{ route('saas.admin.groups.show', $group->id) }}" class="block bg-gray-900 border border-gray-800 hover:border-indigo-700 rounded-xl p-4 transition">
            <div class="flex flex-wrap items-center gap-2 mb-2">
                <span class="font-mono text-xs text-indigo-400">{{ $group->code }}</span>
                <span class="text-white font-semibold">{{ $group->displayName() }}</span>
                <span class="text-xs text-gray-500">{{ $group->members_count }} accounts</span>

                @if($card['trial_abuse'])
                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-red-900/30 text-red-400 border border-red-800">
                    {{ $card['duplicates'] ? 'Duplicate accounts' : 'Multiple trials' }}
                </span>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                @foreach($card['products'] as $type => $count)
                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ \App\Support\ProductCatalog::chipClass($type) }}">
                    {{ \App\Support\ProductCatalog::label($type) }}@if($count > 1) &times;{{ $count }}@endif
                </span>
                @endforeach

                @foreach($card['missing'] as $type)
                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium border border-dashed border-gray-700 text-gray-500">
                    no {{ \App\Support\ProductCatalog::label($type) }}
                </span>
                @endforeach
            </div>
        </a>
        @endforeach
    </div>

    <div class="mt-6">{{ $groups->links() }}</div>
    @endif
    @endif
</div>
</x-admin-layout>
