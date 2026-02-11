<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 leading-tight">Companies</h2>
            <a href="/admin/companies/create" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-medium hover:bg-emerald-700 transition">+ Add Company</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">NTN</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoices</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Users</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($companies as $company)
                        <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='/admin/company/{{ $company->id }}'">
                            <td class="px-6 py-4 text-sm font-medium text-emerald-700 hover:text-emerald-900"><a href="/admin/company/{{ $company->id }}">{{ $company->name }}</a></td>
                            <td class="px-6 py-4 text-sm font-mono text-gray-600">{{ $company->ntn }}</td>
                            <td class="px-6 py-4 text-sm text-gray-600">{{ $company->email ?? '-' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-600">{{ $company->phone ?? '-' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900 font-medium">{{ $company->invoices_count }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900 font-medium">{{ $company->users_count }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="px-6 py-8 text-center text-gray-400">No companies yet</td></tr>
                        @endforelse
                    </tbody>
                </table>
                @if($companies->hasPages())
                <div class="px-6 py-4 border-t border-gray-100">{{ $companies->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
