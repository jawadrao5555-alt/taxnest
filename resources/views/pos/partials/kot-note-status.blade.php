{{-- A bill note also becomes kitchen_notes, but its KOT visibility is a separate
     saved choice. Never let the note editor imply an OFF choice will print. --}}
@if(\App\Services\PosFeatureService::moduleAvailable($company, 'kot'))
@php
    $noteUser = auth('pos')->user();
    $noteCustomize = \App\Services\PosAccessService::customAllows($noteUser, 'customize');
    $canEnableKotNotes = $noteUser && (int) $noteUser->company_id === (int) $company->id
        && ($noteCustomize === true || ($noteCustomize === null && $noteUser->isPosAdmin()));
@endphp
<div data-kot-note-status x-show="String(kitchenNotes || '').trim().length > 0" x-cloak
     x-data="{
        enabled: @js((bool) ($company->kot_show_kitchen_notes ?? false)),
        busy: false, failed: false,
        async enableNotes() {
            if (this.busy) return;
            this.busy = true; this.failed = false;
            try {
                const response = await fetch(@js(route('pos.restaurant.kitchen-notes.enable')), {
                    method: 'POST', credentials: 'same-origin', signal: AbortSignal.timeout(12000),
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) }
                });
                if (!response.ok) throw new Error('save_failed');
                const data = await response.json();
                if (data.success !== true || data.enabled !== true) throw new Error('save_failed');
                this.enabled = true;
                window.dispatchEvent(new CustomEvent('kot-notes-enabled'));
            } catch (error) { this.failed = true; }
            finally { this.busy = false; }
        }
     }"
     @kot-notes-enabled.window="enabled = true"
     class="mt-1 text-xs leading-relaxed">
    <p x-show="!enabled" class="font-semibold text-amber-800 dark:text-amber-300">{{ __('pos.kot_note_off_warning') }}</p>
    <p x-show="enabled" class="text-green-700 dark:text-green-300">{{ __('pos.kot_note_on_status') }}</p>
    <p class="text-gray-600 dark:text-gray-300">{{ __('pos.kot_note_already_sent') }}</p>
    @if($canEnableKotNotes)
    <button data-kot-note-enable type="button" x-show="!enabled" :disabled="busy" @click.stop="enableNotes()"
            class="mt-1 rounded border border-amber-500 px-2 py-1 font-bold text-amber-900 dark:text-amber-200 disabled:opacity-50">
        <span x-show="!busy">{{ __('pos.kot_note_enable_shop') }}</span>
        <span x-show="busy">{{ __('pos.kot_note_saving') }}</span>
    </button>
    @else
    <p x-show="!enabled" class="text-amber-800 dark:text-amber-300">{{ __('pos.kot_note_ask_admin') }}</p>
    @endif
    <p x-show="failed" role="alert" class="font-semibold text-red-700 dark:text-red-300">{{ __('pos.kot_note_save_failed') }}</p>
</div>
@endif
