<x-admin-layout>
<div class="p-4 sm:p-6 max-w-7xl mx-auto">
    <div class="flex items-center justify-between gap-3"><h1 class="text-2xl font-bold text-white mb-1">Agents / Partners</h1><a href="{{ route('saas.admin.agent-claims') }}" class="text-sm text-indigo-400 hover:underline">Review Sale Claims</a></div>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Commission agents (Agency Agreement — Model A). Payments come straight to TaxNest; agents earn Schedule A rates on cleared payments of companies they introduced.</p>

    @if($tableMissing)
        <div class="bg-amber-900/30 border border-amber-700 text-amber-300 text-sm rounded-xl p-4">The agents table does not exist yet — run migrations.</div>
    @else

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6" x-data="{ showForm: false }">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-white">Add Agent</h3>
            <button @click="showForm = !showForm" class="text-xs text-indigo-400 hover:underline" x-text="showForm ? 'Hide' : 'New Agent'"></button>
        </div>
        <form x-show="showForm" method="POST" action="{{ route('saas.admin.agents.store') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
            @csrf
            <div><label class="text-xs text-gray-400 mb-1 block">Name *</label>
                <input type="text" name="name" required value="{{ old('name') }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500"></div>
            <div><label class="text-xs text-gray-400 mb-1 block">CNIC</label>
                <input type="text" name="cnic" value="{{ old('cnic') }}" placeholder="35202-1234567-1" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600"></div>
            <div><label class="text-xs text-gray-400 mb-1 block">Phone</label>
                <input type="text" name="phone" value="{{ old('phone') }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500"></div>
            <div><label class="text-xs text-gray-400 mb-1 block">Email *</label>
                <input type="email" name="email" required value="{{ old('email') }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500"></div>
            <div><label class="text-xs text-gray-400 mb-1 block">Portal Password *</label>
                <input type="password" name="password" required minlength="8" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500"></div>
            <div><label class="text-xs text-gray-400 mb-1 block">Territory</label>
                <input type="text" name="territory" value="{{ old('territory') }}" placeholder="e.g. Lahore, Gujranwala" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500 placeholder-gray-600"></div>
            <div><label class="text-xs text-gray-400 mb-1 block">New Sale % *</label>
                <input type="number" name="rate_new" required step="0.01" min="0" max="100" value="{{ old('rate_new') }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500"></div>
            <div><label class="text-xs text-gray-400 mb-1 block">Renewal % *</label>
                <input type="number" name="rate_renewal" required step="0.01" min="0" max="100" value="{{ old('rate_renewal') }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500"></div>
            <div><label class="text-xs text-gray-400 mb-1 block">Notes</label>
                <input type="text" name="notes" value="{{ old('notes') }}" class="w-full bg-gray-800 border border-gray-700 rounded-lg text-white text-sm px-3 py-2 focus:ring-2 focus:ring-indigo-500"></div>
            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">Create Agent</button>
        </form>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm table-cards">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b border-gray-800 bg-gray-800/50">
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">CNIC</th>
                        <th class="px-4 py-3">Phone</th>
                        <th class="px-4 py-3">Territory</th>
                        <th class="px-4 py-3 text-right">New %</th>
                        <th class="px-4 py-3 text-right">Renewal %</th>
                        <th class="px-4 py-3 text-right">Companies</th>
                        <th class="px-4 py-3 text-right">Net Earned (Rs)</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3 text-center">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @forelse($agents as $a)
                    <tr class="hover:bg-gray-800/50">
                        <td class="px-4 py-3"><a href="{{ route('saas.admin.agents.show', $a->id) }}" class="text-indigo-400 hover:underline font-medium">{{ $a->name }}</a></td>
                        <td class="px-4 py-3 text-gray-400">{{ $a->cnic ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-400">{{ $a->phone ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-400">{{ $a->territory ?? '—' }}</td>
                        <td class="px-4 py-3 text-right text-indigo-400 font-medium">{{ rtrim(rtrim(number_format($a->rate_new, 2), '0'), '.') }}%</td>
                        <td class="px-4 py-3 text-right text-indigo-400 font-medium">{{ rtrim(rtrim(number_format($a->rate_renewal, 2), '0'), '.') }}%</td>
                        <td class="px-4 py-3 text-right text-white">{{ $companyCounts[$a->id] ?? 0 }}</td>
                        <td class="px-4 py-3 text-right text-white">{{ number_format((float) ($earnedTotals[$a->id] ?? 0), 2) }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $a->status === 'active' ? 'bg-emerald-900/30 text-emerald-400' : 'bg-red-900/30 text-red-400' }}">{{ $a->status }}</span>
                        </td>
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            <a href="{{ route('saas.admin.agents.show', $a->id) }}" class="text-xs text-indigo-400 hover:underline mr-3">Detail</a>
                            <form method="POST" action="{{ route('saas.admin.agents.toggle', $a->id) }}" class="inline" onsubmit="return confirm('{{ $a->status === 'active' ? 'Terminate this agent? They will earn NO new commissions.' : 'Re-activate this agent?' }}')">@csrf
                                <button class="text-xs {{ $a->status === 'active' ? 'text-red-400' : 'text-emerald-400' }}">{{ $a->status === 'active' ? 'Terminate' : 'Activate' }}</button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="10" class="px-4 py-12 text-center text-gray-500 dark:text-gray-400">No agents yet. Add your first agent above.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
</x-admin-layout>
