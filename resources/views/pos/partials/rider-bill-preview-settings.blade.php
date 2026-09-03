@php($rbp = app(\App\Services\RiderBillPreviewService::class)->prefs($company))
<div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5" data-rider-preview-settings>
    <input type="hidden" name="rider_bill_preview_present" value="1">
    <h3 class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.rider_bill_preview') }}</h3>
    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 mb-3">{{ __('pos.rider_bill_preview_hint') }}</p>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
    @foreach(['enabled', 'quantity', 'prices', 'tax', 'ntn', 'qr', 'customer_name', 'customer_phone', 'customer_address', 'customer_code', 'business'] as $key)
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200"><input type="checkbox" name="{{ $key }}" value="1" {{ !empty($rbp[$key]) ? 'checked' : '' }} class="rounded text-purple-600"> {{ __('pos.rider_bill_preview_option_' . $key) }}</label>
    @endforeach
    </div>
    <button type="button" class="mt-4 px-4 py-2 rounded-lg bg-purple-600 text-white text-sm font-semibold" onclick="(async function(b){const c=b.closest('[data-rider-preview-settings]'),f=new FormData();f.append('_token','{{ csrf_token() }}');c.querySelectorAll('input').forEach(i=>{if(i.type==='hidden'||i.checked)f.append(i.name,i.value)});const r=await fetch('{{ route($settingRoute ?? 'rider.preview.settings') }}',{method:'POST',headers:{'Accept':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:f});if(r.ok)location.reload();else alert('{{ __('pos.rider_bill_preview_save_failed') }}');})(this)">{{ __('pos.save_rider_bill_preview') }}</button>
</div>