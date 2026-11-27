<x-health-layout>
    <section class="mx-auto max-w-5xl space-y-5 px-4 py-6 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">{{ __('lab_operations.'.($costing ? 'cost_title' : 'title')) }}</h1>
            @if(in_array('lab.view', $healthCapabilities ?? [], true))
                <a href="{{ route('health.lab') }}" class="text-teal-700 underline dark:text-teal-300">{{ __('health.nav_lab') }}</a>
            @endif
        </div>
        <form method="GET" action="{{ route($costing ? 'health.lab.costs' : 'health.lab.operations') }}" class="flex flex-wrap items-end gap-3">
            <label class="text-sm font-medium">
                {{ __('lab_operations.from') }}
                <input type="date" name="from" value="{{ $from }}" required class="mt-1 block rounded-lg border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800">
            </label>
            <label class="text-sm font-medium">
                {{ __('lab_operations.through') }}
                <input type="date" name="through" value="{{ $through }}" required class="mt-1 block rounded-lg border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800">
            </label>
            <label class="text-sm font-medium">
                {{ __('lab_operations.branch') }}
                <select name="branch_id" class="mt-1 block max-w-full rounded-lg border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800">
                    <option value="">{{ __('lab_operations.all_branches') }}</option>
                    @foreach($branches as $id => $name)
                        <option value="{{ $id }}" @selected($branchId === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <button class="rounded-lg bg-teal-700 px-4 py-2 font-semibold text-white">{{ __('lab_operations.apply') }}</button>
        </form>
        @if($errors->any())
            <p role="alert" class="text-sm text-red-700 dark:text-red-400">{{ $errors->first() }}</p>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('lab_operations.'.($costing ? 'cost_basis' : 'date_basis')) }}</p>
            @unless($costing)
                <a href="{{ route('health.lab.operations.export', ['from' => $from, 'through' => $through, 'branch_id' => $branchId]) }}"
                   class="rounded-lg border border-gray-300 px-4 py-2 font-semibold dark:border-gray-600">{{ __('lab_operations.export') }}</a>
            @endunless
        </div>
        @forelse($rows as $row)
            <article class="rounded-xl border border-gray-300 p-4 dark:border-gray-700">
                <h2 class="break-words text-base font-semibold">{{ $row->branch_name }}</h2>
                <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach($metrics as $metric)
                        <div>
                            <dt class="text-sm text-gray-600 dark:text-gray-300">{{ __('lab_operations.'.$metric) }}</dt>
                            <dd class="text-lg font-semibold">
                                @if($metric === 'known_cost_paisa')
                                    {{ $row->{$metric} === null ? __('lab_operations.unrecorded') : 'PKR '.number_format($row->{$metric} / 100, 2) }}
                                @else
                                    {{ number_format((int) $row->{$metric}) }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </article>
        @empty
            <p class="rounded-xl border border-gray-300 p-4 dark:border-gray-700">{{ __('lab_operations.empty') }}</p>
        @endforelse
    </section>
</x-health-layout>
