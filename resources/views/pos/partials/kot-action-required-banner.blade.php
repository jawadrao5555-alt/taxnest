{{-- Immediate KOT Action Required. Parent Alpine must expose kotActionRequired[]
     and reprintKotAttention(job). Never a silent wait after a dead local agent. --}}
<div x-show="kotActionRequired.length > 0" x-cloak
     data-kot-action-required
     role="alert"
     class="mx-3 mt-2 mb-1 rounded-xl border-2 border-rose-500 bg-rose-600 text-white shadow-lg px-3 py-2.5">
    <p class="text-xs font-extrabold uppercase tracking-wide">{{ __('pos.kot_action_required_title') }}</p>
    <p class="text-[12px] font-semibold mt-1 leading-snug">{{ __('pos.kot_action_required_body') }}</p>
    <div class="mt-2 flex flex-wrap gap-2">
        <template x-for="job in kotActionRequired" :key="job.id">
            <button type="button"
                    x-show="job.restaurant_order_id"
                    @click="reprintKotAttention(job)"
                    class="inline-flex items-center px-2.5 py-1 rounded-lg bg-white text-rose-700 text-[11px] font-extrabold uppercase tracking-wide hover:bg-rose-50">
                {{ __('pos.kot_action_required_reprint') }}
                #<span x-text="job.restaurant_order_id"></span>
            </button>
        </template>
    </div>
</div>
