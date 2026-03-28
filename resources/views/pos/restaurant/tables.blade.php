<x-pos-layout>
<div x-data="tableView()" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Tables Overview</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Real-time table status</p>
        </div>
        <button @click="refreshStatus()" class="px-4 py-2 text-sm rounded-lg bg-purple-600 text-white hover:bg-purple-700 font-medium">Refresh</button>
    </div>

    @forelse($floors as $floor)
    <div class="mb-8">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-3">{{ $floor->name }}</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
            @foreach($floor->tables as $table)
            <a href="{{ route('pos.restaurant.pos', ['table_id' => $table->id]) }}" class="block bg-white dark:bg-gray-800 rounded-xl border-2 p-4 text-center transition-all hover:shadow-lg {{ $table->status === 'available' ? 'border-green-300 dark:border-green-700 hover:border-green-500' : ($table->status === 'occupied' ? 'border-red-300 dark:border-red-700 hover:border-red-500' : 'border-amber-300 dark:border-amber-700 hover:border-amber-500') }}">
                <div class="text-2xl mb-1">
                    @if($table->status === 'available') 🟢 @elseif($table->status === 'occupied') 🔴 @else 🟡 @endif
                </div>
                <div class="text-lg font-bold text-gray-900 dark:text-white">{{ $table->table_number }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $table->seats }} seats</div>
                <div class="mt-1 text-xs font-medium {{ $table->status === 'available' ? 'text-green-600 dark:text-green-400' : ($table->status === 'occupied' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400') }}">
                    {{ ucfirst($table->status) }}
                </div>
            </a>
            @endforeach
        </div>
    </div>
    @empty
    <div class="text-center py-16 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 12h16M4 18h16"/></svg>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">No Tables Configured</h3>
        <p class="text-gray-500 dark:text-gray-400 mb-4">Set up your restaurant floor and tables first.</p>
        <a href="{{ route('pos.restaurant.table-management') }}" class="inline-flex items-center px-4 py-2 rounded-lg bg-purple-600 text-white hover:bg-purple-700 text-sm font-medium">Go to Table Setup</a>
    </div>
    @endforelse
</div>

<script>
function tableView() {
    return {
        async refreshStatus() {
            try {
                const res = await fetch('{{ route("pos.restaurant.table-status") }}');
                if (res.ok) location.reload();
            } catch (e) {}
        },
    };
}
</script>
</x-pos-layout>
