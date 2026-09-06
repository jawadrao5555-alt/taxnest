@php
    /**
     * The accounts workspace's own sub-navigation.
     *
     * One list, used by every screen in the workspace, so a new section can
     * never appear on four pages and be missing from the fifth. Each entry
     * carries the right it needs — a tab a person cannot open is not shown at
     * all, because navigation that offers a locked door is just a slower 403.
     */
    $tabs = [
        ['health.accounts',              'health.acc_nav_overview',    'accounts.view'],
        ['health.accounts.journals',     'health.acc_nav_journals',    'accounts.view'],
        ['health.accounts.expenses',     'health.acc_nav_expenses',    'accounts.view'],
        ['health.accounts.transfers',    'health.acc_nav_transfers',   'accounts.view'],
        ['health.accounts.shares',       'health.acc_nav_shares',      'accounts.view'],
        ['health.accounts.settlements',  'health.acc_nav_settlements', 'accounts.view'],
        ['health.accounts.reports',      'health.acc_nav_reports',     'accounts.view'],
        ['health.accounts.chart',        'health.acc_nav_chart',       'accounts.view'],
        ['health.accounts.reconciliations', 'health.acc_nav_reconcile', 'accounts.view'],
        ['health.accounts.periods',      'health.acc_nav_periods',     'accounts.view'],
        ['health.accounts.settings',     'health.acc_nav_settings',    'accounts.view'],
    ];
    $currentName = request()->route()?->getName();
@endphp

<nav class="flex gap-1.5 overflow-x-auto pb-1 -mx-1 px-1">
    @foreach($tabs as [$name, $label, $cap])
        @php $active = $currentName === $name; @endphp
        <a href="{{ route($name) }}"
           class="shrink-0 px-3.5 py-2 rounded-xl text-sm font-bold transition
                  {{ $active
                       ? 'bg-teal-700 text-white'
                       : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600' }}">
            {{ __($label) }}
        </a>
    @endforeach
</nav>
