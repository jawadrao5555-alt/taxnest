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
                @error('layout')
                    <p role="alert" class="w-full text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
                @enderror
            </form>
        </div>

        @if($orders !== null)
            <div class="flex flex-wrap gap-3">
                @if(in_array('lab.orders.manage', $healthCapabilities ?? [], true))
                    <a href="{{ route('health.lab.orders.create') }}" class="rounded-lg bg-teal-700 px-4 py-2 font-semibold text-white">{{ __('lab_workflow.new_order') }}</a>
                @endif
                @if(in_array('lab.catalogue.manage', $healthCapabilities ?? [], true))
                    <a href="{{ route('health.lab.catalogue') }}" class="rounded-lg border border-gray-300 px-4 py-2 font-semibold dark:border-gray-600">{{ __('lab_workflow.catalogue') }}</a>
                @endif
            </div>

            <form method="GET" action="{{ route('health.lab') }}" class="flex flex-wrap items-end gap-2">
                <label class="grow text-sm font-medium">
                    {{ __('lab_workflow.worklist_search') }}
                    <input name="q" value="{{ $search }}" maxlength="60" class="mt-1 block w-full rounded-lg border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800" dir="ltr">
                </label>
                <button class="rounded-lg border border-gray-300 px-4 py-2 font-semibold dark:border-gray-600">{{ __('lab_workflow.search') }}</button>
            </form>

            <ul class="divide-y divide-gray-200 overflow-hidden rounded-xl border border-gray-300 dark:divide-gray-700 dark:border-gray-700">
                @forelse($orders as $order)
                    <li class="flex flex-wrap items-center justify-between gap-2 p-4">
                        <div>
                            <a href="{{ route('health.lab.orders.show', $order) }}" class="font-semibold text-teal-700 underline dark:text-teal-300" dir="ltr">{{ $order->order_no }}</a>
                            <p>{{ $order->patient?->name }} · <bdi>{{ $order->patient?->mrn }}</bdi></p>
                        </div>
                        <span class="text-sm">{{ __('lab_workflow.verified_count', ['verified' => $order->verified_items_count, 'total' => $order->items_count]) }}</span>
                    </li>
                @empty
                    <li class="p-4 text-sm">{{ __('lab_workflow.no_orders') }}</li>
                @endforelse
            </ul>
            {{ $orders->links() }}
        @endif

        <div @class([
            'grid gap-3',
            'sm:grid-cols-2' => $layout === 'modern',
            'sm:grid-cols-3' => $layout === 'classic',
            'max-w-xl' => $layout === 'fast_reception',
        ])>
            @if($layout === 'fast_reception' && in_array('patients.view', $healthCapabilities ?? [], true) && in_array('patients.manage', $healthCapabilities ?? [], true))
                <a href="{{ route('health.patients.create') }}" class="rounded-xl border border-teal-700 bg-teal-700 p-4 font-semibold text-white">{{ __('health.patient_register') }}</a>
            @endif
            @if(in_array('patients.view', $healthCapabilities ?? [], true))
                <a href="{{ route('health.patients') }}" @class(['rounded-xl border border-gray-300 p-4 font-semibold dark:border-gray-700', 'bg-teal-50 dark:bg-teal-900/30' => $layout === 'fast_reception'])>{{ __('health.nav_patients') }}</a>
            @endif
            @if(in_array('accounts', $healthModules ?? [], true) && in_array('billing.view', $healthCapabilities ?? [], true))
                <a href="{{ route('health.billing') }}" class="rounded-xl border border-gray-300 p-4 font-semibold dark:border-gray-700">{{ __('health.nav_billing') }}</a>
            @endif
        </div>
    </section>
</x-health-layout>
