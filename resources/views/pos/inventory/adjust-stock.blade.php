<x-pos-layout>
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="flex items-center space-x-3 mb-6">
        <a href="{{ route('pos.inventory.stock') }}" class="inline-flex items-center text-gray-500 hover:text-purple-600 transition text-sm">
            <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Back
        </a>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Adjust Stock</h1>
    </div>

    @if(session('error'))
    <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-lg p-3 text-sm text-red-800 dark:text-red-300">{{ session('error') }}</div>
    @endif

    @if($errors->any())
    <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-lg p-3">
        <ul class="text-sm text-red-800 dark:text-red-300 list-disc pl-4">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ route('pos.inventory.adjust') }}" class="space-y-6">
        @csrf
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5 space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Product</label>
                <select name="product_id" required class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">
                    <option value="">Select Product</option>
                    @foreach($products as $p)
                    <option value="{{ $p->id }}" {{ old('product_id', request('product_id')) == $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Adjustment Type</label>
                <div class="grid grid-cols-3 gap-2" x-data="{ type: '{{ old('type', 'add') }}' }">
                    <label @click="type = 'add'" :class="type === 'add' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 ring-2 ring-emerald-500/30' : 'border-gray-200 dark:border-gray-700'" class="cursor-pointer flex flex-col items-center p-3 rounded-xl border-2 transition">
                        <input type="radio" name="type" value="add" x-model="type" class="hidden">
                        <span class="text-lg mb-1">+</span>
                        <span class="text-xs font-semibold" :class="type === 'add' ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-600 dark:text-gray-400'">Add Stock</span>
                    </label>
                    <label @click="type = 'remove'" :class="type === 'remove' ? 'border-red-500 bg-red-50 dark:bg-red-900/20 ring-2 ring-red-500/30' : 'border-gray-200 dark:border-gray-700'" class="cursor-pointer flex flex-col items-center p-3 rounded-xl border-2 transition">
                        <input type="radio" name="type" value="remove" x-model="type" class="hidden">
                        <span class="text-lg mb-1">-</span>
                        <span class="text-xs font-semibold" :class="type === 'remove' ? 'text-red-700 dark:text-red-300' : 'text-gray-600 dark:text-gray-400'">Remove Stock</span>
                    </label>
                    <label @click="type = 'set'" :class="type === 'set' ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20 ring-2 ring-blue-500/30' : 'border-gray-200 dark:border-gray-700'" class="cursor-pointer flex flex-col items-center p-3 rounded-xl border-2 transition">
                        <input type="radio" name="type" value="set" x-model="type" class="hidden">
                        <span class="text-lg mb-1">=</span>
                        <span class="text-xs font-semibold" :class="type === 'set' ? 'text-blue-700 dark:text-blue-300' : 'text-gray-600 dark:text-gray-400'">Set Exact</span>
                    </label>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Quantity</label>
                <input type="number" name="quantity" value="{{ old('quantity') }}" min="0.01" step="0.01" required placeholder="Enter quantity" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reason</label>
                <select name="reason" required class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">
                    <option value="">Select Reason</option>
                    <option value="New Purchase" {{ old('reason') === 'New Purchase' ? 'selected' : '' }}>New Purchase</option>
                    <option value="Physical Count" {{ old('reason') === 'Physical Count' ? 'selected' : '' }}>Physical Count</option>
                    <option value="Damaged/Expired" {{ old('reason') === 'Damaged/Expired' ? 'selected' : '' }}>Damaged / Expired</option>
                    <option value="Return from Customer" {{ old('reason') === 'Return from Customer' ? 'selected' : '' }}>Return from Customer</option>
                    <option value="Opening Stock" {{ old('reason') === 'Opening Stock' ? 'selected' : '' }}>Opening Stock</option>
                    <option value="Correction" {{ old('reason') === 'Correction' ? 'selected' : '' }}>Correction</option>
                    <option value="Other" {{ old('reason') === 'Other' ? 'selected' : '' }}>Other</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Notes <span class="text-gray-400">(Optional)</span></label>
                <textarea name="notes" rows="2" placeholder="Additional details..." class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">{{ old('notes') }}</textarea>
            </div>
        </div>

        <button type="submit" class="w-full px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white font-semibold rounded-xl shadow-sm transition">
            Adjust Stock
        </button>
    </form>
</div>
</x-pos-layout>
