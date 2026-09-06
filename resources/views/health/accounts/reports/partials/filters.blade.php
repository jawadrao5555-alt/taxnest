@php
    /**
     * The window every report shares.
     *
     * $mode is 'range' for reports that cover a period and 'as_at' for the ones
     * that photograph a moment. Keeping one partial means a branch filter added
     * here reaches every report at once, instead of arriving on three of them
     * and quietly missing the fourth.
     */
    $mode = $mode ?? 'range';
    $route = $route ?? request()->route()?->getName();
    $branches = $branches ?? collect();
@endphp
<form method="GET" action="{{ route($route) }}"
      class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 grid grid-cols-2 md:grid-cols-5 gap-3">
    @foreach(($extra ?? []) as $key => $value)
        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
    @endforeach

    @if($mode === 'range')
        <div>
            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.from') }}</label>
            <input type="date" name="from" value="{{ $from ?? '' }}"
                   class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.to') }}</label>
            <input type="date" name="to" value="{{ $to ?? '' }}"
                   class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
        </div>
    @else
        <div>
            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.acc_as_at') }}</label>
            <input type="date" name="as_at" value="{{ $asAt ?? '' }}"
                   class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
        </div>
    @endif

    @if($branches->count() > 1)
        <div>
            <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 mb-1">{{ __('health.branch') }}</label>
            <select name="branch_id" class="w-full px-3 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                <option value="">{{ __('health.all') }}</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((int) request('branch_id') === (int) $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>
    @endif

    {{ $slot ?? '' }}

    <div class="flex items-end gap-2">
        <button class="flex-1 px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.apply') }}</button>
        <a href="{{ route($route, array_merge(request()->query(), ['export' => 'csv'])) }}"
           class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.export_csv') }}</a>
    </div>
</form>
