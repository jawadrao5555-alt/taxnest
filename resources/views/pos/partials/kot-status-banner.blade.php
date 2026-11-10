@php
    $kotLabels = [];
    foreach (['pending', 'printing', 'printed', 'waiting', 'action_required', 'no_document', 'recovered'] as $state) {
        $kotLabels[$state] = __("pos.kot_status_{$state}");
    }
@endphp
<aside x-cloak x-show="kotStatusJobs.length" data-kot-status-banner
       class="fixed right-3 top-16 z-40 w-80 max-w-[calc(100vw-1.5rem)] rounded-xl border border-slate-200 bg-white p-2 shadow-lg dark:border-slate-700 dark:bg-slate-900"
       aria-label="{{ __('pos.ti_print_kot') }}">
    <ul role="status" aria-live="polite" class="max-h-[30vh] space-y-1 overflow-y-auto">
        <template x-for="job in kotStatusJobs" :key="job.id">
            <li class="rounded-lg px-2 py-1.5 text-xs font-semibold"
                :class="{
                    'bg-emerald-100 text-emerald-900': job.state === 'printed',
                    'bg-sky-100 text-sky-900': ['pending', 'printing'].includes(job.state),
                    'bg-amber-100 text-amber-900': ['waiting', 'recovered', 'no_document'].includes(job.state),
                    'bg-rose-100 text-rose-900': job.state === 'action_required'
                }">
                <div x-text="(@js($kotLabels))[job.state]"></div>
                <div dir="auto" class="mt-0.5 break-words"
                     x-text="['#' + (job.restaurant_order_id || job.transaction_id || job.id), job.printer].filter(Boolean).join(' · ')"></div>
            </li>
        </template>
    </ul>
</aside>
