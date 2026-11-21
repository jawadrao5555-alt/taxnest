@if(($company->fbr_environment ?? 'sandbox') === 'sandbox')
    @php($sandboxRole = old('sandbox_buyer_role', $invoice->sandbox_buyer_role ?? ''))
    <div class="mb-4" x-show="document_type === 'Sale Invoice' && ['standard', '3rd_schedule'].includes(items[0]?.schedule_type)" x-cloak>
        <label for="sandbox_buyer_role" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sandbox sale to</label>
        <select id="sandbox_buyer_role" name="sandbox_buyer_role" class="block rounded-lg dark:bg-gray-700">
            <option value="" @selected($sandboxRole === '')>Automatic from buyer details</option>
            <option value="business" @selected($sandboxRole === 'business')>Business buyer</option>
            <option value="retail_consumer" @selected($sandboxRole === 'retail_consumer')>Retail end consumer</option>
        </select>
        <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">Choose the buyer’s role for this sandbox test. The saved draft shows the scenario before submission.</p>
    </div>
@endif
