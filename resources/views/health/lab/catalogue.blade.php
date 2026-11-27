<x-health-layout>
    <section class="mx-auto max-w-4xl space-y-5 px-4 py-6 sm:px-6">
        <a href="{{ route('health.lab') }}" class="text-sm font-semibold text-teal-700 underline dark:text-teal-300">{{ __('health.nav_lab') }}</a>
        <h1 class="text-2xl font-bold">{{ __('lab_workflow.catalogue') }}</h1>

        <form method="POST" action="{{ $editTest ? route('health.lab.catalogue.update', $editTest) : route('health.lab.catalogue.store') }}" class="space-y-3 rounded-xl border border-gray-300 p-4 dark:border-gray-700">
            @csrf
            @if($editTest)
                @method('PATCH')
            @endif
            <h2 class="font-semibold">{{ __('lab_workflow.'.($editTest ? 'edit_test' : 'new_test')) }}</h2>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="text-sm font-medium">
                    {{ __('lab_workflow.test_code') }}
                    <input name="code" value="{{ old('code', $editTest?->code) }}" maxlength="32" class="mt-1 block w-full rounded-lg border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800" dir="ltr" required>
                </label>
                <label class="text-sm font-medium">
                    {{ __('lab_workflow.test_name') }}
                    <input name="name" value="{{ old('name', $editTest?->name) }}" maxlength="120" class="mt-1 block w-full rounded-lg border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800" required>
                </label>
                <label class="text-sm font-medium">
                    {{ __('lab_workflow.price') }}
                    <input name="price" value="{{ old('price', $editTest ? number_format($editTest->price_paisa / 100, 2, '.', '') : '') }}" inputmode="decimal" class="mt-1 block w-full rounded-lg border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800" dir="ltr" required>
                </label>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $editTest?->is_active ?? true))>
                {{ __('lab_workflow.active_test') }}
            </label>
            <button class="rounded-lg bg-teal-700 px-4 py-2 font-semibold text-white">{{ __('lab_workflow.save_test') }}</button>
        </form>

        <ul class="divide-y divide-gray-200 overflow-hidden rounded-xl border border-gray-300 dark:divide-gray-700 dark:border-gray-700">
            @forelse($tests as $test)
                <li class="flex flex-wrap items-center justify-between gap-2 p-4">
                    <span><bdi>{{ $test->code }}</bdi> · {{ $test->name }} · <bdi dir="ltr">PKR {{ number_format($test->price_paisa / 100, 2) }}</bdi> @unless($test->is_active) · {{ __('lab_workflow.inactive') }} @endunless</span>
                    <a href="{{ route('health.lab.catalogue.edit', $test) }}" class="font-semibold text-teal-700 underline dark:text-teal-300">{{ __('lab_workflow.edit_test') }}</a>
                </li>
            @empty
                <li class="p-4">{{ __('lab_workflow.no_tests') }}</li>
            @endforelse
        </ul>
        {{ $tests->links() }}
    </section>
</x-health-layout>
