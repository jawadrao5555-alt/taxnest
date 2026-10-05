@php
    $reportUser = auth('pos')->user();
    $reportCompany = $reportUser?->company;
    $reportAvailable = ($reportCompany?->pos_integration_mode ?? 'pra') !== 'standalone';
    $reportOn = $reportAvailable && $reportUser?->praReportingEnabled($reportCompany);
    $connectionBranch = app(\App\Services\BranchContextService::class)->getActiveBranchId();
    $hotelBillIds = \App\Models\HotelFolioEntry::where('company_id', $reportCompany?->id ?? 0)
        ->whereIn('stay_id', \App\Models\HotelStay::where('company_id', $reportCompany?->id ?? 0)
            ->when($connectionBranch, fn ($q) => $q->where('branch_id', $connectionBranch))->select('id'))
        ->where('entry_type', 'charge')->whereNotNull('pos_transaction_id')->select('pos_transaction_id');
    $lastAccepted = \App\Models\PosTransaction::withoutGlobalScope('hide_archived')->where('company_id', $reportCompany?->id ?? 0)
        ->whereIn('id', $hotelBillIds)->where('pra_status', 'submitted')->whereNotNull('pra_invoice_number')
        ->where('pra_invoice_number', '!=', '')->latest('id')->first();
    $reportReadOnly = (bool) data_get(session('impersonation'), 'readonly', false);
    $reportCanToggle = $reportAvailable && $reportUser?->isPosAdmin() && !$reportUser?->isPosCashier()
        && $reportUser?->posBillingScopeExplicit() === 'both' && !$reportReadOnly;
@endphp
<div data-hotel-pra-reporting="1" class="mb-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-gray-900 p-3"
     x-data="{ on: @js((bool) $reportOn), busy: false, error: '', async change() {
         if (this.busy) return;
         this.busy = true; this.error = '';
         try {
             const response = await fetch(@js(route('pos.api.toggle-pra')), {
                 method: 'POST', credentials: 'same-origin',
                 headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token())}
             });
             const data = await response.json();
             if (!response.ok || !data.success) { this.error = data.message || @js(__('hotel_reporting.failed')); return; }
             this.on = !!data.enabled;
         } catch (_) { this.error = @js(__('hotel_reporting.failed')); }
         finally { this.busy = false; }
     } }">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <p class="text-sm font-semibold dark:text-white">{{ __('hotel_reporting.title') }}
                <span data-hotel-pra-status="1" class="ml-2 rounded-full px-2 py-1 text-xs font-bold"
                    :class="on ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-700'"
                    x-text="on ? @js(__('hotel_reporting.on')) : @js(__('hotel_reporting.off'))"></span>
            </p>
            <p class="mt-1 text-xs text-slate-500">{{ $reportAvailable ? __('hotel_reporting.account') : __('pos.pra_not_available_standalone') }}</p>
        </div>
        @if($reportCanToggle)
            <button type="button" role="switch" :aria-checked="on.toString()" :disabled="busy" @click="change()"
                data-hotel-pra-toggle="1" class="rounded-lg border px-3 py-2 text-sm font-semibold disabled:opacity-50"
                x-text="busy ? @js(__('hotel_reporting.saving')) : (on ? @js(__('hotel_reporting.turn_off')) : @js(__('hotel_reporting.turn_on')))"></button>
        @else
            <span class="text-xs text-slate-500">{{ __('hotel_reporting.readonly') }}</span>
        @endif
    </div>
    <p x-show="error" x-cloak x-text="error" role="alert" class="mt-2 text-sm text-red-600"></p>
    <p class="mt-1 text-xs text-slate-500">{{ __('hotel_reporting.note') }}</p>
    <div data-hotel-pra-connection class="mt-3 border-t pt-2 text-xs space-y-1">
        <p>{{ __('hotel_reporting.connection') }}: {{ $reportCompany?->agentHandlesPra() ? __('hotel_reporting.device') : __('hotel_reporting.cloud') }}</p>
        @if($reportCompany?->agentHandlesPra())
        <p>{{ __('hotel_reporting.agent') }}: {{ $reportCompany->agentOnline() ? __('hotel_reporting.agent_online') : __('hotel_reporting.agent_offline') }}</p>
        @endif
        @if($lastAccepted)
        <p>{{ __('hotel_reporting.last_accepted') }}: <a class="underline" target="_blank" rel="noopener" href="{{ route('pos.receipt', $lastAccepted->id) }}">{{ $lastAccepted->pra_invoice_number }}</a></p>
        @else
        <p>{{ __('hotel_reporting.no_acceptance') }}</p>
        @endif
        <p>{{ __('hotel_reporting.proof') }}</p>
    </div>
</div>
