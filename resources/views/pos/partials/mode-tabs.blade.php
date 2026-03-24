@php
    $currentTab = $tab ?? 'pra';
    $hasPinSet = $hasPinSet ?? false;
    $localCount = $localCount ?? 0;
    $baseUrl = $baseUrl ?? request()->url();
@endphp
<div class="flex items-center gap-2 mb-6">
    <a href="{{ $baseUrl }}?tab=pra"
        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold transition-all duration-200
        {{ $currentTab === 'pra' ? 'bg-purple-600 text-white shadow-md' : 'bg-white dark:bg-gray-900 text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800' }}">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
        PRA Invoices
    </a>
    @if($localCount > 0 || !$hasPinSet)
    <button onclick="{{ $hasPinSet ? "checkPinSessionAndSwitch('" . $baseUrl . "?tab=local')" : "window.location.href='" . $baseUrl . "?tab=local'" }}"
        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold transition-all duration-200
        {{ $currentTab === 'local' ? 'bg-amber-600 text-white shadow-md' : 'bg-white dark:bg-gray-900 text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800' }}">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
        Local Invoices
    </button>
    @endif
</div>
