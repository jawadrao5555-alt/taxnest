<x-admin-layout>
<div class="p-4 sm:p-6 max-w-5xl mx-auto">
    <div class="flex flex-wrap items-center gap-3 mb-6">
        <a href="{{ route('saas.admin.groups') }}" class="text-gray-400 hover:text-indigo-400 transition text-sm">&larr; Back</a>
        <h1 class="text-2xl font-bold text-white">{{ $group->displayName() }}</h1>
        <span class="font-mono text-xs text-indigo-400">{{ $group->code }}</span>
        @if($card && $card['trial_abuse'])
        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-red-900/30 text-red-400 border border-red-800">
            {{ $card['duplicates'] ? 'Duplicate accounts' : 'Multiple trials' }}
        </span>
        @endif
    </div>

    {{-- Cross-sell: what this customer does NOT run yet. --}}
    @if($card && $card['missing'])
    <div class="bg-emerald-900/15 border border-emerald-800/50 rounded-xl p-4 mb-4">
        <p class="text-sm text-emerald-300 font-semibold mb-1">Not on these products yet</p>
        <div class="flex flex-wrap gap-1.5">
            @foreach($card['missing'] as $type)
            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ \App\Support\ProductCatalog::chipClass($type) }}">{{ \App\Support\ProductCatalog::label($type) }}</span>
            @endforeach
        </div>
        <p class="text-xs text-emerald-200/60 mt-2">Open any account below and use “Clone to another product” to start that trial for them.</p>
    </div>
    @endif

    @if($card && $card['duplicates'])
    <div class="bg-red-900/15 border border-red-800/50 rounded-xl p-4 mb-4">
        <p class="text-sm text-red-300 font-semibold mb-1">More than one account on the same product</p>
        <p class="text-xs text-red-200/70">
            {{ collect($card['duplicates'])->map(fn($t) => \App\Support\ProductCatalog::label($t))->join(', ') }} —
            same identity, repeated sign-up. Worth checking whether an expired trial was simply restarted.
        </p>
    </div>
    @endif

    {{-- Members --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-gray-800">
            <h2 class="text-sm font-semibold text-gray-300">Accounts in this group</h2>
        </div>
        <div class="divide-y divide-gray-800">
            @foreach($group->members as $member)
            @php $company = $member->company; @endphp
            @continue(!$company)
            <div class="p-4 flex flex-wrap items-center gap-3">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('saas.admin.companies.show', $company->id) }}" class="text-white font-semibold hover:text-indigo-400 transition break-words">{{ $company->name }}</a>
                        @if($company->account_code ?? null)
                        <span class="font-mono text-[11px] text-gray-500">{{ $company->account_code }}</span>
                        @endif
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ \App\Support\ProductCatalog::chipClass($company->product_type) }}">
                            {{ \App\Support\ProductCatalog::label($company->product_type, \App\Support\NestErps::verticalOf($company)) }}
                        </span>
                        @if($company->trashed())
                        <span class="px-2 py-0.5 rounded text-[10px] bg-gray-800 text-gray-500">in bin</span>
                        @endif
                    </div>
                    <p class="text-xs text-gray-500 mt-1">
                        {{ $member->reason() }}@if($member->match_value) — <span class="font-mono">{{ $member->match_value }}</span>@endif
                        <span class="mx-1">·</span>
                        <span class="{{ $member->strength === 'strong' ? 'text-emerald-500' : ($member->strength === 'manual' ? 'text-indigo-400' : 'text-amber-500') }}">{{ $member->strength }}</span>
                        <span class="mx-1">·</span>
                        joined {{ optional($company->created_at)->format('d M Y') }}
                    </p>
                </div>
                <form method="POST" action="{{ route('saas.admin.groups.detach', [$group->id, $company->id]) }}"
                      onsubmit="return confirm('Detach {{ addslashes($company->name) }} from this group? It will not be re-added automatically.');">
                    @csrf
                    <button type="submit" class="px-3 py-1.5 bg-gray-800 hover:bg-red-900/40 text-gray-400 hover:text-red-300 text-xs rounded-lg transition">Detach</button>
                </form>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Link another account --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-4 mb-6">
        <h2 class="text-sm font-semibold text-gray-300 mb-2">Link another account</h2>
        <form method="POST" action="{{ route('saas.admin.groups.link', $group->id) }}" class="flex flex-wrap gap-2">
            @csrf
            <input type="text" name="company_ref" required placeholder="Account code (PRA-00026), NTN, or company name"
                   class="flex-1 min-w-[240px] px-3 py-2 bg-gray-950 border border-gray-800 rounded-lg text-sm text-gray-200 placeholder-gray-600 focus:outline-none focus:border-indigo-600">
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Link</button>
        </form>
        @if($excludedCompanies->isNotEmpty())
        <p class="text-xs text-gray-500 mt-3">
            Previously detached: {{ $excludedCompanies->pluck('name')->join(', ') }}. Linking one back overrides that decision.
        </p>
        @endif
    </div>

    {{-- Name + notes --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
        <h2 class="text-sm font-semibold text-gray-300 mb-3">Group details</h2>
        <form method="POST" action="{{ route('saas.admin.groups.update', $group->id) }}" class="space-y-3">
            @csrf
            @method('PUT')
            <div>
                <label class="block text-xs text-gray-500 mb-1">Name</label>
                <input type="text" name="name" value="{{ old('name', $group->name) }}" placeholder="{{ $group->displayName() }}"
                       class="w-full px-3 py-2 bg-gray-950 border border-gray-800 rounded-lg text-sm text-gray-200 focus:outline-none focus:border-indigo-600">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Notes (admin only)</label>
                <textarea name="notes" rows="3" class="w-full px-3 py-2 bg-gray-950 border border-gray-800 rounded-lg text-sm text-gray-200 focus:outline-none focus:border-indigo-600">{{ old('notes', $group->notes) }}</textarea>
            </div>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Save</button>
        </form>
    </div>
</div>
</x-admin-layout>
