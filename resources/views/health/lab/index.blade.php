<x-health-layout>
    <section data-lab-layout="{{ $layout }}" class="mx-auto max-w-5xl space-y-5 px-4 py-6 sm:px-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <h1 class="text-2xl font-bold">{{ __('health.nav_lab') }}</h1>
            <form method="POST" action="{{ route('health.lab.layout') }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <label class="block text-sm font-medium">
                    {{ __('lab_workspace.layout') }}
                    <select name="layout" class="mt-1 block rounded-lg border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800">
                        @foreach($layouts as $option)
                            <option value="{{ $option }}" @selected($layout === $option)>{{ __('lab_workspace.'.$option) }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="rounded-lg bg-teal-700 px-4 py-2 font-semibold text-white" type="submit">{{ __('lab_workspace.apply') }}</button>
            </form>
        </div>

        <div @class([
            'grid gap-3',
            'sm:grid-cols-2' => $layout === 'modern',
            'sm:grid-cols-3' => $layout === 'classic',
            'max-w-xl' => $layout === 'fast_reception',
        ])>
            @if(in_array('patients.view', $healthCapabilities ?? [], true))
                <a href="{{ route('health.patients') }}" @class(['rounded-xl border border-gray-300 p-4 font-semibold dark:border-gray-700', 'bg-teal-50 dark:bg-teal-900/30' => $layout === 'fast_reception'])>{{ __('health.nav_patients') }}</a>
            @endif
            @if(in_array('billing.view', $healthCapabilities ?? [], true))
                <a href="{{ route('health.billing') }}" class="rounded-xl border border-gray-300 p-4 font-semibold dark:border-gray-700">{{ __('health.nav_billing') }}</a>
            @endif
        </div>
    </section>
</x-health-layout>
