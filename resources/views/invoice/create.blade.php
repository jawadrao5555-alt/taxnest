<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 leading-tight">Create Invoice</h2>
            <a href="/invoices" class="text-sm text-gray-600 hover:text-gray-800">Back to Invoices</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <form method="POST" action="/invoice/store" x-data="invoiceForm()" class="space-y-6">
                @csrf

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Buyer Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Buyer Name</label>
                            <input type="text" name="buyer_name" value="{{ old('buyer_name') }}" required
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('buyer_name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Buyer NTN</label>
                            <input type="text" name="buyer_ntn" value="{{ old('buyer_ntn') }}" required placeholder="1234567-8"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            @error('buyer_ntn') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800">Invoice Items</h3>
                        <button type="button" @click="addItem()" class="inline-flex items-center px-3 py-1.5 bg-emerald-50 text-emerald-700 rounded-lg text-sm font-medium hover:bg-emerald-100 transition">
                            + Add Item
                        </button>
                    </div>

                    <template x-for="(item, index) in items" :key="index">
                        <div class="border border-gray-200 rounded-lg p-4 mb-4">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-sm font-medium text-gray-600" x-text="'Item #' + (index + 1)"></span>
                                <button type="button" @click="removeItem(index)" x-show="items.length > 1" class="text-red-500 hover:text-red-700 text-sm">Remove</button>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">HS Code</label>
                                    <input type="text" :name="'items[' + index + '][hs_code]'" x-model="item.hs_code" required placeholder="8471.3010"
                                        class="w-full rounded-lg border-gray-300 shadow-sm text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                                <div class="md:col-span-2 lg:col-span-1">
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Description</label>
                                    <input type="text" :name="'items[' + index + '][description]'" x-model="item.description" required
                                        class="w-full rounded-lg border-gray-300 shadow-sm text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Quantity</label>
                                    <input type="number" step="0.01" min="0.01" :name="'items[' + index + '][quantity]'" x-model="item.quantity" required
                                        class="w-full rounded-lg border-gray-300 shadow-sm text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Unit Price (Rs.)</label>
                                    <input type="number" step="0.01" min="0" :name="'items[' + index + '][price]'" x-model="item.price" required
                                        class="w-full rounded-lg border-gray-300 shadow-sm text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Tax (Rs.)</label>
                                    <input type="number" step="0.01" min="0" :name="'items[' + index + '][tax]'" x-model="item.tax" required
                                        class="w-full rounded-lg border-gray-300 shadow-sm text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                            </div>
                            <div class="mt-2 text-right text-sm text-gray-500">
                                Line Total: <span class="font-medium text-gray-800" x-text="'Rs. ' + ((parseFloat(item.price || 0) * parseFloat(item.quantity || 0)) + parseFloat(item.tax || 0)).toFixed(2)"></span>
                            </div>
                        </div>
                    </template>

                    <div class="mt-4 p-4 bg-gray-50 rounded-lg flex justify-between items-center">
                        <span class="text-lg font-semibold text-gray-700">Grand Total</span>
                        <span class="text-2xl font-bold text-emerald-600" x-text="'Rs. ' + grandTotal()"></span>
                    </div>
                </div>

                <div class="flex justify-end space-x-3">
                    <a href="/invoices" class="px-6 py-2.5 border border-gray-300 rounded-lg text-gray-700 font-medium hover:bg-gray-50 transition">Cancel</a>
                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition">
                        Create Invoice
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function invoiceForm() {
            return {
                items: [{ hs_code: '', description: '', quantity: 1, price: 0, tax: 0 }],
                addItem() {
                    this.items.push({ hs_code: '', description: '', quantity: 1, price: 0, tax: 0 });
                },
                removeItem(index) {
                    this.items.splice(index, 1);
                },
                grandTotal() {
                    return this.items.reduce((total, item) => {
                        return total + (parseFloat(item.price || 0) * parseFloat(item.quantity || 0)) + parseFloat(item.tax || 0);
                    }, 0).toFixed(2);
                }
            };
        }
    </script>
</x-app-layout>
