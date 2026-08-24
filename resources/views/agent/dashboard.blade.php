<x-agent-layout>
<h1 class="text-2xl font-bold mb-6">Agent Dashboard</h1>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    @foreach(['companies' => 'Companies Brought', 'earned' => 'Commission Earned', 'pending' => 'Pending / Owed', 'paid' => 'Paid'] as $key => $label)
    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-5">
        <p class="text-xs text-gray-500">{{ $label }}</p>
        <p class="text-2xl font-bold mt-1">{{ $key === 'companies' ? number_format($totals[$key]) : 'Rs '.number_format($totals[$key], 2) }}</p>
    </div>
    @endforeach
</div>
<div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-5">
    <h2 class="font-semibold mb-2">Your Referral Link</h2>
    <p class="text-sm text-gray-500 mb-3">Companies that register through this link are attributed to you on first touch.</p>
    <input readonly value="{{ url('/register?ref='.$agent->referral_code) }}" class="w-full bg-gray-100 dark:bg-gray-800 rounded-lg px-4 py-3 text-indigo-500">
</div>
</x-agent-layout>