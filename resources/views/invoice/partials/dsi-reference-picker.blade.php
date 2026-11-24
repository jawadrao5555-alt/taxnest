<details class="mb-3 rounded-lg border border-gray-200 dark:border-gray-700"
    x-data="dsiReferencePicker()">
    <summary class="cursor-pointer px-3 py-2 text-sm font-medium text-emerald-700 dark:text-emerald-300">
        Search FBR tax references
    </summary>
    <div class="space-y-2 px-3 pb-3">
        <div class="grid gap-2 sm:grid-cols-2">
            <label class="text-xs font-medium">
                Reference type
                <select x-model="category" @change="query = ''; $refs.referenceQuery.value = ''; feedback = ''"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:bg-gray-700">
                    <option value="hs">HS code</option>
                    <option value="sale_type">Sale type</option>
                    <option value="rate">Rate</option>
                    <option value="sro">Schedule or SRO</option>
                    <option value="serial">Item serial</option>
                    <option value="uom">Unit of measure</option>
                </select>
            </label>
            <label class="text-xs font-medium">
                Search
                <input type="search" x-ref="referenceQuery" @input="query = $event.target.value" maxlength="100" placeholder="Type a code or description"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:bg-gray-700">
            </label>
        </div>
        <p class="text-xs text-gray-600 dark:text-gray-300">
            These workbook entries are independent. Use Validate FBR Payload to check your selections before submission.
        </p>
        <p x-show="category === 'hs' && item.product_id" class="text-xs text-gray-600 dark:text-gray-300">
            Change the tax settings on the linked product to update its HS code.
        </p>
        <ul class="max-h-48 overflow-y-auto" aria-label="Tax reference results">
            <template x-for="entry in results" :key="entry.label">
                <li class="flex items-center justify-between gap-3 border-b border-gray-100 py-1.5 text-sm dark:border-gray-700">
                    <span x-text="entry.label"></span>
                    <button type="button" x-show="canApply(entry, item)" :disabled="applying"
                        @click="apply(entry, item, { lookup: () => lookupHsCode(index), schedule: () => onScheduleChange(index), calculate: () => calcTax(index) })"
                        class="shrink-0 rounded border border-emerald-600 px-2 py-0.5 text-xs text-emerald-700 disabled:opacity-50 dark:text-emerald-300">
                        Use
                    </button>
                    <span x-show="!canApply(entry, item)" class="shrink-0 text-xs text-gray-500 dark:text-gray-400">Reference only</span>
                </li>
            </template>
        </ul>
        <p x-show="results.length === 0" class="text-xs text-gray-600 dark:text-gray-300"
            x-text="category === 'hs' && !query.trim() ? 'Enter an HS code or description to search.' : 'No matching references.'"></p>
        <p x-show="results.length === 25" class="text-xs text-gray-600 dark:text-gray-300">Showing up to 25 results. Narrow the search for more specific matches.</p>
        <p x-show="feedback" x-text="feedback" class="text-xs text-gray-600 dark:text-gray-300" aria-live="polite"></p>
    </div>
</details>
