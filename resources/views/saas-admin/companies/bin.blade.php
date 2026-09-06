<x-admin-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto" x-data="{ deleteId: null, deleteName: '', deleteHold: false, deleteEligible: '', deleteSiblings: [], typedName: '' }">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('saas.admin.companies') }}" class="text-gray-500 dark:text-gray-400 hover:text-indigo-400 transition text-sm">&larr; Back to Companies</a>
        <h1 class="text-2xl font-bold text-white">Bin</h1>
        <span class="text-xs text-gray-500 dark:text-gray-400">({{ $companies->total() }} deleted)</span>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-4 mb-6">
        <form method="GET" class="flex gap-3">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search deleted companies..." class="flex-1 bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-4 py-2 focus:ring-2 focus:ring-indigo-500">
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Search</button>
        </form>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="text-left text-[10px] text-gray-500 dark:text-gray-400 uppercase border-b border-gray-800 bg-gray-800/50">
                        <th class="px-4 py-3">Company</th>
                        <th class="px-4 py-3 text-center">Type</th>
                        <th class="px-4 py-3 hidden sm:table-cell">Reason</th>
                        <th class="px-4 py-3 hidden sm:table-cell">Deleted On</th>
                        <th class="px-4 py-3 hidden sm:table-cell">Permanent Delete</th>
                        <th class="px-4 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @forelse($companies as $company)
                    <tr class="hover:bg-gray-800/50">
                        <td class="px-4 py-3">
                            <span class="text-white font-medium">{{ $company->name }}</span>
                            <p class="text-[10px] text-gray-600 dark:text-gray-400">{{ $company->ntn ?? '' }} &middot; {{ $company->owner_name ?? '' }}</p>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ \App\Support\ProductCatalog::chipClass($company->product_type) }}">{{ \App\Support\ProductCatalog::shortLabel($company->product_type) }}</span>
                        </td>
                        <td class="px-4 py-3 text-gray-400 text-xs hidden sm:table-cell">{{ $company->deleted_reason ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400 text-xs hidden sm:table-cell">{{ $company->deleted_at->format('d M Y, h:i A') }}</td>
                        {{-- Task 1585: 7-day holding period before a purge is possible --}}
                        @php $binHold = $company->purgeHoldActive(); $binEligible = $company->purgeEligibleAt()?->format('d M Y, h:i A'); @endphp
                        <td class="px-4 py-3 text-xs hidden sm:table-cell">
                            @if($binHold)
                                <span class="text-amber-400">possible from {{ $binEligible }}</span>
                            @else
                                <span class="text-gray-500">possible now (hold since {{ $binEligible }})</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            <div class="flex items-center justify-center gap-1">
                                <form method="POST" action="{{ route('saas.admin.companies.restore', $company->id) }}" class="inline">
                                    @csrf
                                    <button class="px-2 py-1 bg-emerald-600/20 text-emerald-400 text-[10px] rounded hover:bg-emerald-600/40 transition">Restore</button>
                                </form>
                                <button @click="deleteId = {{ $company->id }}; deleteName = @js($company->name); deleteHold = @js($binHold); deleteEligible = @js($binEligible); deleteSiblings = @js($siblingNames[$company->id] ?? []); typedName = ''" class="px-2 py-1 bg-red-600/20 text-red-400 text-[10px] rounded hover:bg-red-600/40 transition">Delete Forever</button>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center">
                            <svg class="w-12 h-12 mx-auto text-gray-700 dark:text-gray-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            <p class="text-gray-500 dark:text-gray-400">Bin is empty</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($companies->hasPages())<div class="mt-4">{{ $companies->links() }}</div>@endif

    <div x-show="deleteId" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="deleteId = null">
        <div class="bg-gray-900 border border-red-800 rounded-2xl p-6 w-full max-w-md mx-4" @click.stop>
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 rounded-full bg-red-900/30 flex items-center justify-center">
                    <svg class="w-5 h-5 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-white">Permanent Delete</h3>
                    <p class="text-xs text-red-400">This action cannot be undone!</p>
                </div>
            </div>
            <p class="text-sm text-gray-400 mb-4">Are you absolutely sure you want to permanently delete "<span class="text-white font-medium" x-text="deleteName"></span>"? All associated data will be lost forever.</p>

            {{-- Task 1585: same customer's OTHER product accounts. Deleting this
                 one does NOT touch them, but the admin must see they exist. --}}
            <template x-if="deleteSiblings.length">
                <div class="mb-4 p-3 rounded-lg bg-amber-900/20 border border-amber-800">
                    <p class="text-xs text-amber-300 font-semibold mb-1">Is customer ke doosre accounts bhi hain:</p>
                    <ul class="text-xs text-amber-200/80 list-disc list-inside space-y-0.5">
                        <template x-for="s in deleteSiblings" :key="s"><li x-text="s"></li></template>
                    </ul>
                    <p class="text-[11px] text-amber-200/60 mt-1">Yeh delete sirf isi account ka hai — baqi accounts par asar nahi hoga.</p>
                </div>
            </template>

            {{-- Task 1585: holding period. Refused server-side too — this is
                 only the honest UI in front of that gate. --}}
            <template x-if="deleteHold">
                <div class="mb-4 p-3 rounded-lg bg-red-900/20 border border-red-800">
                    <p class="text-xs text-red-300">Yeh company abhi <span class="font-semibold">bin hold</span> mein hai. Permanent delete <span class="font-semibold" x-text="deleteEligible"></span> se possible hai.</p>
                    @if($isSuperAdmin)
                        <label class="block text-[11px] text-red-200/80 mt-2 mb-1">Override karne ke liye company ka poora naam type karein:</label>
                        <input type="text" x-model="typedName" name="confirm_name" form="binDeleteForm" autocomplete="off"
                               class="w-full px-3 py-2 bg-gray-950 border border-red-800 rounded-lg text-sm text-gray-100 placeholder-gray-600 focus:outline-none focus:border-red-600"
                               :placeholder="deleteName">
                    @else
                        <p class="text-[11px] text-red-200/70 mt-1">Sirf ek super-admin hold override kar sakta hai.</p>
                    @endif
                </div>
            </template>

            <form id="binDeleteForm" :action="`{{ url('/admin/bin') }}/${deleteId}/destroy`" method="POST">
                @csrf
                @method('DELETE')
                <div class="flex gap-2 justify-end">
                    <button type="button" @click="deleteId = null" class="px-4 py-2 bg-gray-800 text-gray-300 text-sm rounded-lg hover:bg-gray-700 transition">Cancel</button>
                    <button type="submit"
                            :disabled="deleteHold && (!@js($isSuperAdmin) || typedName !== deleteName)"
                            :class="(deleteHold && (!@js($isSuperAdmin) || typedName !== deleteName)) ? 'opacity-40 cursor-not-allowed' : 'hover:bg-red-700'"
                            class="px-4 py-2 bg-red-600 text-white text-sm rounded-lg font-medium transition">Delete Forever</button>
                </div>
            </form>
        </div>
    </div>
</div>
</x-admin-layout>
