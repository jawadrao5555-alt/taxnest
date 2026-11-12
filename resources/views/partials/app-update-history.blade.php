<x-dynamic-component :component="$layout">
    <div class="max-w-3xl mx-auto px-4 py-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.app_updates_heading') }}</h1>
        <div class="mt-4 space-y-3">
            @forelse($updates as $update)
                <details class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <summary class="cursor-pointer font-semibold text-gray-900 dark:text-white">
                        {{ $update->customerTitle() }} <x-wn-type-badge :update="$update" />
                        <time class="text-sm font-normal text-gray-500 dark:text-gray-400" datetime="{{ $update->created_at->toDateString() }}">{{ $update->created_at->format('d M Y') }}</time>
                    </summary>
                    @if($update->image_path)
                        <img src="{{ asset('storage/' . $update->image_path) }}" alt="{{ __('pos.update_image_alt') }}" loading="lazy" class="mt-3 max-w-full rounded-lg">
                    @endif
                    <ul class="mt-3 list-disc ps-5 text-gray-700 dark:text-gray-300">
                        @foreach($update->customerPoints() as $point)
                            <li>{{ $point }}</li>
                        @endforeach
                    </ul>
                </details>
            @empty
                <p class="text-gray-500 dark:text-gray-400">{{ __('pos.app_updates_empty') }}</p>
            @endforelse
        </div>
        <div class="mt-4">{{ $updates->links() }}</div>
    </div>
</x-dynamic-component>
