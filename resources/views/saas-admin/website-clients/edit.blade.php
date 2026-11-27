<x-admin-layout>
<div class="mx-auto max-w-2xl space-y-4 p-4 text-gray-100 sm:p-6">
    <a href="{{ route('saas.admin.website-clients.index') }}" class="text-sm underline">Back to Website Clients</a>
    <h1 class="text-2xl font-bold">{{ $entry->exists ? 'Review client' : 'Add client' }}</h1>
    @if($errors->any())<p role="alert" class="text-red-200">{{ $errors->first() }}</p>@endif
    @if($source)
        <p class="text-sm text-gray-300">Company account: {{ $source->name }}@if($source->account_code) · {{ $source->account_code }}@endif</p>
    @elseif($entry->company_id)
        <p class="text-sm text-amber-200">The linked company is currently ineligible. This card is hidden.</p>
    @endif

    <form method="POST" enctype="multipart/form-data"
        action="{{ $entry->exists ? route('saas.admin.website-clients.update', $entry->id) : route('saas.admin.website-clients.store') }}"
        class="space-y-4 rounded-xl border border-gray-800 bg-gray-900 p-4">
        @csrf
        @if($entry->exists)
            @method('PUT')
            <input type="hidden" name="revision" value="{{ $entry->revision }}">
        @elseif($source)
            <input type="hidden" name="company_id" value="{{ $source->id }}">
        @endif
        <label class="block text-sm">Public business name
            <input name="display_name" maxlength="120" required value="{{ old('display_name', $entry->display_name) }}" class="mt-1 block w-full rounded-lg border-gray-700 bg-gray-950">
        </label>
        @if($source)
        <p class="text-sm">Product: {{ \App\Models\WebsiteClient::PRODUCTS[$source->website_product] }}</p>
        <input type="hidden" name="product" value="{{ $source->website_product }}">
        @else
        <label class="block text-sm">Product
            <select name="product" required class="mt-1 block w-full rounded-lg border-gray-700 bg-gray-950">
                <option value="">Select a product</option>
                @foreach(\App\Models\WebsiteClient::PRODUCTS as $code => $productName)
                    <option value="{{ $code }}" @selected(old('product', $entry->product) === $code)>{{ $productName }}</option>
                @endforeach
            </select>
        </label>
        @endif
        @if($entry->logo_mime)
            <img src="{{ route('saas.admin.website-clients.logo', [$entry->id, $entry->revision]) }}" alt="Current client logo" class="h-16 max-w-48 rounded bg-white object-contain p-1">
            <label class="block text-sm"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>
        @endif
        <label class="block text-sm">Logo (optional)
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="mt-1 block w-full text-sm">
            <span class="text-gray-400">PNG, JPEG or WebP, up to 256 KB and 1200 × 1200 pixels.</span>
        </label>
        @if($entry->published_at)
            <p class="text-sm text-amber-200">Saving edits makes this entry a draft until it is approved again.</p>
        @endif
        @if(!$entry->company_id || $source)
            <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Save draft</button>
        @endif
    </form>

    @if($entry->exists && !$entry->published_at && (!$entry->company_id || ($source && $source->website_product === $entry->product)))
    <form method="POST" action="{{ route('saas.admin.website-clients.visibility', $entry->id) }}"
        class="space-y-3 rounded-xl border border-gray-800 bg-gray-900 p-4 text-sm">
        @csrf
        <input type="hidden" name="revision" value="{{ $entry->revision }}">
        <h2 class="font-semibold">Publish saved details</h2>
        <p class="text-gray-400">For fiscal integrations, check that the regulator accepted a production invoice or receipt.</p>
        <label class="block"><input name="live_use" type="checkbox" value="1" required> I verified this client uses the selected product in production.</label>
        <label class="block"><input name="consent" type="checkbox" value="1" required> The client approved displaying this name and any uploaded logo.</label>
        <button name="action" value="publish" class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white">Publish</button>
    </form>
    @endif
</div>
</x-admin-layout>
