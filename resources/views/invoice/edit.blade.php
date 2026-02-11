<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-bold text-xl text-gray-800 leading-tight">Edit Invoice {{ $invoice->invoice_number ?? '#' . $invoice->id }}</h2>
            <a href="/invoice/{{ $invoice->id }}" class="text-sm text-gray-600 hover:text-gray-800">Back to Invoice</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <form method="POST" action="/invoice/{{ $invoice->id }}" x-data="invoiceEditForm()" class="space-y-6">
                @csrf
                @method('PUT')

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Buyer Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Buyer Name</label>
                            <input type="text" name="buyer_name" value="{{ old('buyer_name', $invoice->buyer_name) }}" required
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Buyer NTN</label>
                            <input type="text" name="buyer_ntn" value="{{ old('buyer_ntn', $invoice->buyer_ntn) }}" required
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800">Invoice Items</h3>
                        <button type="button" @click="addItem()" class="inline-flex items-center px-3 py-1.5 bg-emerald-50 text-emerald-700 rounded-lg text-sm font-medium hover:bg-emerald-100 transition">+ Add Item</button>
                    </div>

                    <template x-for="(item, index) in items" :key="index">
                        <div class="border border-gray-200 rounded-lg p-4 mb-4">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-sm font-medium text-gray-600" x-text="'Item #' + (index + 1)"></span>
                                <button type="button" @click="removeItem(index)" x-show="items.length > 1" class="text-red-500 hover:text-red-700 text-sm">Remove</button>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">HS Code</label>
                                    <input type="text" :name="'items[' + index + '][hs_code]'" x-model="item.hs_code" required class="w-full rounded-lg border-gray-300 shadow-sm text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Description</label>
                                    <input type="text" :name="'items[' + index + '][description]'" x-model="item.description" required class="w-full rounded-lg border-gray-300 shadow-sm text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Quantity</label>
                                    <input type="number" step="0.01" :name="'items[' + index + '][quantity]'" x-model="item.quantity" required class="w-full rounded-lg border-gray-300 shadow-sm text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Price (Rs.)</label>
                                    <input type="number" step="0.01" :name="'items[' + index + '][price]'" x-model="item.price" required class="w-full rounded-lg border-gray-300 shadow-sm text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Tax (Rs.)</label>
                                    <input type="number" step="0.01" :name="'items[' + index + '][tax]'" x-model="item.tax" required class="w-full rounded-lg border-gray-300 shadow-sm text-sm focus:ring-emerald-500 focus:border-emerald-500">
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="flex justify-end space-x-3">
                    <a href="/invoice/{{ $invoice->id }}" class="px-6 py-2.5 border border-gray-300 rounded-lg text-gray-700 font-medium hover:bg-gray-50 transition">Cancel</a>
                    <button type="submit" class="px-6 py-2.5 bg-emerald-600 text-white rounded-lg font-medium hover:bg-emerald-700 transition">Update Invoice</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function invoiceEditForm() {
            return {
                items: {!! json_encode($invoice->items->map(fn($i) => ['hs_code' => $i->hs_code, 'description' => $i->description, 'quantity' => $i->quantity, 'price' => $i->price, 'tax' => $i->tax])) !!},
                addItem() { this.items.push({ hs_code: '', description: '', quantity: 1, price: 0, tax: 0 }); },
                removeItem(index) { this.items.splice(index, 1); }
            };
        }
    </script>
</x-app-layout>
