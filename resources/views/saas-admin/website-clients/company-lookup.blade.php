<form method="GET" action="{{ $lookupAction ?? route('saas.admin.website-clients.create') }}" class="flex flex-wrap items-end gap-2">
    <label class="block min-w-0 flex-auto text-sm font-medium">Company ID
        <input name="company" value="{{ old('company') }}" maxlength="32" required
            @error('company') aria-invalid="true" @enderror class="tn-website-client-field mt-1 block w-full rounded-lg">
    </label>
    <button type="submit" class="tn-website-client-action tn-website-client-action--primary">Find by ID</button>
</form>
