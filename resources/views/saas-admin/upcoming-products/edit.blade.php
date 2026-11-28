<x-admin-layout>
    <main class="tn-content-rail mx-auto max-w-2xl space-y-4 p-4 sm:p-6">
        <p><a class="underline" href="{{ route('saas.admin.upcoming-products.index') }}">Back to Upcoming Products</a></p>
        <h1 class="text-2xl font-bold">{{ $product->exists ? 'Edit roadmap product' : 'Add roadmap product' }}</h1>
        @if($errors->any())
            <ul class="tn-admin-notice tn-admin-notice--error rounded-lg px-4 py-2" role="alert">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        @endif
        @php($field = 'block w-full rounded-lg border border-slate-500 bg-white p-2 text-slate-900 focus:ring-2 focus:ring-indigo-500 dark:bg-slate-900 dark:text-white')
        <form class="space-y-4" method="POST" action="{{ $product->exists ? route('saas.admin.upcoming-products.update', $product) : route('saas.admin.upcoming-products.store') }}">
            @csrf
            @if($product->exists)
                @method('PUT')
                <p>Public path <code>/upcoming-products#{{ $product->slug }}</code></p>
            @else
                <label class="block">Product slug <input class="{{ $field }}" name="slug" required maxlength="80" value="{{ old('slug') }}" placeholder="product-name"></label>
            @endif
            <label class="block">Name <input class="{{ $field }}" name="name" required maxlength="120" value="{{ old('name', $product->name) }}"></label>
            <label class="block">Introduction <textarea class="{{ $field }}" name="introduction" required maxlength="500" rows="3">{{ old('introduction', $product->introduction) }}</textarea></label>
            @foreach(['benefits' => 'Benefits', 'features' => 'Proposed features', 'industries' => 'Target industries'] as $name => $label)
                <label class="block">{{ $label }} (one per line)
                    <textarea class="{{ $field }}" name="{{ $name }}" required maxlength="1000" rows="4">{{ old($name, implode("\n", $product->{$name} ?? [])) }}</textarea>
                </label>
            @endforeach
            <label class="block">Existing TaxNest product link (optional)
                <input class="{{ $field }}" name="existing_product_path" maxlength="160" value="{{ old('existing_product_path', $product->existing_product_path) }}" placeholder="/nest-erps">
            </label>
            @foreach(['status' => ['Status', \App\Models\UpcomingProduct::STATUSES], 'concept' => ['Concept illustration', \App\Models\UpcomingProduct::CONCEPTS]] as $name => [$label, $options])
                <label class="block">{{ $label }}
                    <select class="{{ $field }}" name="{{ $name }}" required>
                        @foreach($options as $key => $description)
                            <option value="{{ $key }}" @selected(old($name, $product->{$name}) === $key)>{{ $description }}</option>
                        @endforeach
                    </select>
                </label>
            @endforeach
            <label class="block">Sort order <input class="{{ $field }}" name="sort_order" type="number" min="0" max="5000" required value="{{ old('sort_order', $product->sort_order) }}"></label>
            <input type="hidden" name="published" value="0">
            <label class="block"><input type="checkbox" name="published" value="1" @checked(old('published', (bool) $product->published_at))> Publish on Upcoming Products</label>
            <button type="submit" class="tn-admin-action tn-admin-action--confirm rounded-lg px-4 py-2">Save roadmap product</button>
        </form>
    </main>
</x-admin-layout>
