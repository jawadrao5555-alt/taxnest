@php
    $reportUser = auth('pos')->user();
    $reportCompany = $reportUser?->company;
    $reportAvailable = ($reportCompany?->pos_integration_mode ?? 'pra') !== 'standalone';
    $reportOn = $reportAvailable && $reportUser?->praReportingEnabled($reportCompany);
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
</div>
