@php $packingRows = old('selling_packings', isset($product) ? ($product->selling_packings ?? []) : []); @endphp
<section class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4"
    x-data="{ rows: {{ \Illuminate\Support\Js::from($packingRows) }} }">
    <input type="hidden" name="selling_packings_present" value="1">
    <div class="flex flex-wrap justify-between gap-3">
        <div><h3 class="font-semibold">Selling packings <span class="text-sm font-normal">(optional)</span></h3>
            <p class="text-sm text-gray-500">Enter how much of the selected FBR base UOM is in one selling pack. Example: a 25 KG bag has factor 25; a carton of twelve 500 ml bottles has factor 6 when the base UOM is litres.</p></div>
        <button type="button" @click="rows.push({id:'',label:'',factor:'',price:'',barcode:''})" class="px-3 py-2 rounded-lg bg-emerald-600 text-white">Add packing</button>
    </div>
    <template x-for="(row, i) in rows" :key="i">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 border rounded-lg p-3 dark:border-gray-700">
            <input type="hidden" :name="'selling_packings['+i+'][id]'" :value="row.id">
            <label class="text-sm">Packing label<input required maxlength="80" :name="'selling_packings['+i+'][label]'" x-model="row.label" class="block w-full rounded-lg dark:bg-gray-800" placeholder="25 KG bag"></label>
            <label class="text-sm">Base quantity per packing<input required type="number" step="0.000001" min="0.000001" :name="'selling_packings['+i+'][factor]'" x-model="row.factor" class="block w-full rounded-lg dark:bg-gray-800"></label>
            <label class="text-sm">Packing price (optional)<input type="number" step="0.01" min="0.01" :name="'selling_packings['+i+'][price]'" x-model="row.price" class="block w-full rounded-lg dark:bg-gray-800"></label>
            <label class="text-sm">Packing barcode (optional)<input maxlength="80" :name="'selling_packings['+i+'][barcode]'" x-model="row.barcode" class="block w-full rounded-lg dark:bg-gray-800"></label>
            <button type="button" @click="rows.splice(i,1)" class="text-red-600 text-sm text-left">Remove packing</button>
        </div>
    </template>
    <p class="text-xs text-gray-500">Existing invoices retain their original packing. Clear packings before changing a product's base UOM. These packings do not change its wholesale pack size or pharmacy strip composition.</p>
</section>
