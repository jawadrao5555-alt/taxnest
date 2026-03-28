<x-pos-layout>
<div x-data="{ showFloorModal: false, showTableModal: false, editFloor: null, editTable: null, selectedFloorId: null }" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Table Setup</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Configure floors and tables for your restaurant</p>
        </div>
        <button @click="showFloorModal = true; editFloor = null" class="px-4 py-2 rounded-lg bg-gradient-to-r from-purple-600 to-violet-600 text-white text-sm font-semibold hover:from-purple-700 hover:to-violet-700">+ Add Floor</button>
    </div>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 text-sm">{{ session('error') }}</div>
    @endif

    @forelse($floors as $floor)
    <div class="mb-6 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="px-5 py-4 bg-gray-50 dark:bg-gray-800/80 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $floor->name }}</h2>
                <span class="text-xs bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 px-2 py-0.5 rounded-full">{{ $floor->tables->count() }} tables</span>
                @if(!$floor->is_active)
                <span class="text-xs bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 px-2 py-0.5 rounded-full">Inactive</span>
                @endif
            </div>
            <div class="flex gap-2">
                <button @click="showTableModal = true; editTable = null; selectedFloorId = {{ $floor->id }}" class="px-3 py-1.5 text-xs rounded-lg bg-purple-600 text-white hover:bg-purple-700 font-medium">+ Add Table</button>
                <form method="POST" action="{{ route('pos.restaurant.floors.delete', $floor->id) }}" onsubmit="return confirm('Delete this floor?')">
                    @csrf @method('DELETE')
                    <button class="px-3 py-1.5 text-xs rounded-lg border border-red-300 text-red-600 hover:bg-red-50 dark:border-red-700 dark:text-red-400">Delete Floor</button>
                </form>
            </div>
        </div>
        <div class="p-5">
            @if($floor->tables->count() > 0)
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                @foreach($floor->tables as $table)
                <div class="bg-gray-50 dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-center relative group">
                    <div class="text-lg font-bold text-gray-900 dark:text-white">{{ $table->table_number }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $table->seats }} seats</div>
                    @if(!$table->is_active)
                    <div class="text-[10px] text-red-500 mt-1">Inactive</div>
                    @endif
                    <div class="absolute top-1 right-1 hidden group-hover:flex gap-1">
                        <form method="POST" action="{{ route('pos.restaurant.tables.delete', $table->id) }}" onsubmit="return confirm('Delete this table?')">
                            @csrf @method('DELETE')
                            <button class="w-5 h-5 rounded bg-red-100 dark:bg-red-900/30 text-red-500 flex items-center justify-center text-xs hover:bg-red-200">&times;</button>
                        </form>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <p class="text-center text-gray-400 py-4 text-sm">No tables on this floor yet.</p>
            @endif
        </div>
    </div>
    @empty
    <div class="text-center py-16 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">No Floors Yet</h3>
        <p class="text-gray-500 dark:text-gray-400 mb-4">Create your first floor to start adding tables.</p>
    </div>
    @endforelse

    <div x-show="showFloorModal" x-transition.opacity class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="showFloorModal = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm">
            <div class="p-5 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Add Floor</h3>
            </div>
            <form method="POST" action="{{ route('pos.restaurant.floors.store') }}" class="p-5 space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Floor Name</label>
                    <input type="text" name="name" required placeholder="e.g., Ground Floor, Rooftop" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 py-2.5 rounded-lg bg-purple-600 text-white hover:bg-purple-700 text-sm font-semibold">Create Floor</button>
                    <button type="button" @click="showFloorModal = false" class="px-4 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div x-show="showTableModal" x-transition.opacity class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="showTableModal = false">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm">
            <div class="p-5 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Add Table</h3>
            </div>
            <form method="POST" action="{{ route('pos.restaurant.tables.store') }}" class="p-5 space-y-4">
                @csrf
                <input type="hidden" name="floor_id" :value="selectedFloorId">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Table Number</label>
                    <input type="text" name="table_number" required placeholder="e.g., T1, A-01" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Seats</label>
                    <input type="number" name="seats" value="4" min="1" max="50" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 py-2.5 rounded-lg bg-purple-600 text-white hover:bg-purple-700 text-sm font-semibold">Add Table</button>
                    <button type="button" @click="showTableModal = false" class="px-4 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
</x-pos-layout>
