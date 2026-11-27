<x-admin-layout>
<div class="tn-website-clients mx-auto max-w-2xl space-y-4 p-4 sm:p-6">
    <a href="{{ route('saas.admin.website-clients.index') }}" class="text-sm underline">Back to Website Clients</a>
    <h1 class="text-2xl font-bold">{{ $entry->exists ? 'Review client' : 'Add client' }}</h1>
    @if($source)
        <section aria-label="Linked company" class="tn-website-client-panel break-words rounded-xl border p-4 text-sm">
            <p class="font-semibold">{{ $source->name }}</p>
            <p>Company ID {{ $source->product_company_id ?? $source->id }}@if($source->account_code) · {{ $source->account_code }}@endif</p>
            @if(!$entry->company_id)
                <a href="{{ $entry->exists ? route('saas.admin.website-clients.edit', $entry->id) : route('saas.admin.website-clients.index') }}" class="mt-2 inline-block underline">Choose another company</a>
            @endif
        </section>
        @if(!$subscriptionEligible)
            <p class="tn-admin-notice tn-admin-notice--warning rounded-lg px-4 py-3 text-sm">The card stays private until the linked company has an active paid subscription.</p>
        @endif
    @elseif($entry->company_id)
        <p class="tn-admin-notice tn-admin-notice--warning rounded-lg px-4 py-3 text-sm">The linked company is currently ineligible. This card is hidden.</p>
    @else
        <section aria-labelledby="website-client-link-heading" class="tn-website-client-panel space-y-2 rounded-xl border p-4">
            <h2 id="website-client-link-heading" class="font-semibold">Link an existing company</h2>
            @include('saas-admin.website-clients.company-lookup', ['lookupAction' => $entry->exists ? route('saas.admin.website-clients.edit', $entry->id) : null])
            @if($entry->exists)
                <p class="text-sm">Link this draft to its company before publishing.</p>
            @else
                <a href="{{ route('saas.admin.website-clients.index') }}" class="inline-block text-sm underline">Search by name or account code</a>
            @endif
        </section>
    @endif

    <form method="POST" enctype="multipart/form-data"
        action="{{ $entry->exists ? route('saas.admin.website-clients.update', $entry->id) : route('saas.admin.website-clients.store') }}"
        class="tn-website-client-panel space-y-4 rounded-xl border p-4">
        @csrf
        @if(!$entry->exists && !$source)<h2 class="font-semibold">Add manually</h2>@endif
        @if($entry->exists)
            @method('PUT')
            <input type="hidden" name="revision" value="{{ $entry->revision }}">
        @endif
        @if(!$entry->company_id && $source)
            <input type="hidden" name="company_id" value="{{ $source->id }}">
        @endif
        <label class="block text-sm">Public business name
            <input name="display_name" maxlength="120" required value="{{ old('display_name', $entry->display_name) }}" class="tn-website-client-field mt-1 block w-full rounded-lg">
        </label>
        @if($source)
        <p class="text-sm">Product: {{ \App\Models\WebsiteClient::PRODUCTS[$source->website_product] }}</p>
        <input type="hidden" name="product" value="{{ $source->website_product }}">
        @else
        <label class="block text-sm">Product
            <select name="product" required class="tn-website-client-field mt-1 block w-full rounded-lg">
                <option value="">Select a product</option>
                @foreach(\App\Models\WebsiteClient::PRODUCTS as $code => $productName)
                    <option value="{{ $code }}" @selected(old('product', $entry->product) === $code)>{{ $productName }}</option>
                @endforeach
            </select>
        </label>
        @endif
        @if($source)
            <div class="space-y-1 text-sm">
                <p class="font-medium">Company profile logo</p>
                @if(\App\Services\WebsiteClientLogo::companyImage($source))
                    <img src="{{ route('saas.admin.website-clients.company-logo', $source->id) }}" alt="Company profile logo" class="h-16 max-w-48 rounded bg-white object-contain p-1">
                @else
                    <p>The card uses the business name when a logo is unavailable.</p>
                @endif
            </div>
        @endif
        @if($entry->logo_mime)
            <img src="{{ route('saas.admin.website-clients.logo', [$entry->id, $entry->revision]) }}" alt="Uploaded website logo" class="h-16 max-w-48 rounded bg-white object-contain p-1">
            <label class="block text-sm"><input type="checkbox" name="remove_logo" value="1"> Remove uploaded website logo</label>
        @endif
        <label class="block text-sm">{{ $source ? 'Upload a website logo override (optional)' : 'Logo (optional)' }}
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="tn-website-client-field mt-1 block w-full rounded-lg text-sm">
            <span class="mt-1 block">PNG, JPEG or WebP, up to 256 KB and 1200 × 1200 pixels.</span>
        </label>
        @if($entry->published_at)
            <p class="tn-admin-notice tn-admin-notice--warning rounded-lg px-4 py-3 text-sm">Saving edits makes this entry a draft until it is approved again.</p>
        @endif
        @if(!$entry->company_id || $source)
            <button class="tn-website-client-action tn-website-client-action--primary">Save draft</button>
        @endif
    </form>

    @if($canPublish)
    <form method="POST" action="{{ route('saas.admin.website-clients.visibility', $entry->id) }}"
        class="tn-website-client-panel space-y-3 rounded-xl border p-4 text-sm">
        @csrf
        <input type="hidden" name="revision" value="{{ $entry->revision }}">
        <h2 class="font-semibold">Publish saved details</h2>
        <p>For fiscal integrations, check that the regulator accepted a production invoice or receipt.</p>
        <label class="block"><input name="live_use" type="checkbox" value="1" required> I verified this client uses the selected product in production.</label>
        <label class="block"><input name="consent" type="checkbox" value="1" required> The client approved displaying this name and any uploaded logo.</label>
        @if($source)
            <label class="block"><input name="sync_company_logo" type="checkbox" value="1" @checked(old('sync_company_logo'))> The client also approved displaying its current and future company profile logo.</label>
        @endif
        <button name="action" value="publish" class="tn-website-client-action tn-website-client-action--primary">Publish</button>
    </form>
    @endif
</div>
</x-admin-layout>
