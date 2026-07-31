@php
    $product = $product ?? null;
    $inputClass = 'w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500';
    $smallInputClass = 'text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-2 py-1.5 w-full';
    $isCompact = $isCompact ?? false;
    $ic = $isCompact ? $smallInputClass : $inputClass;
@endphp

@if(in_array('batch_number', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.batch_number') }}</label>
    <input type="text" name="batch_number" value="{{ $product->batch_number ?? '' }}" placeholder="e.g. BATCH-2026-001" class="{{ $ic }}">
</div>
@endif

@if(in_array('expiry_date', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.expiry_date') }}</label>
    <input type="date" name="expiry_date" value="{{ $product && $product->expiry_date ? $product->expiry_date->format('Y-m-d') : '' }}" class="{{ $ic }}">
</div>
@endif

@if(in_array('drug_type', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.drug_type') }}</label>
    <select name="drug_type" class="{{ $ic }}">
        <option value="">{{ __('pos.select') }}</option>
        @foreach(['tablet', 'capsule', 'syrup', 'injection', 'cream', 'drops', 'inhaler', 'powder', 'other'] as $type)
        <option value="{{ $type }}" {{ ($product->drug_type ?? '') === $type ? 'selected' : '' }}>{{ ucfirst($type) }}</option>
        @endforeach
    </select>
</div>
@endif

@if(in_array('prescription_required', $categoryFields))
<div class="flex items-center gap-2 {{ $isCompact ? '' : 'pt-5' }}">
    <label class="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" name="prescription_required" value="1" {{ ($product->prescription_required ?? false) ? 'checked' : '' }} class="rounded border-gray-300 text-red-600 focus:ring-red-500">
        <span class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('pos.prescription_required') }}</span>
    </label>
</div>
@endif

@if(in_array('weight_based', $categoryFields))
<div class="flex items-center gap-2 {{ $isCompact ? '' : 'pt-5' }}">
    <label class="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" name="weight_based" value="1" {{ ($product->weight_based ?? false) ? 'checked' : '' }} class="rounded border-gray-300 text-amber-600 focus:ring-amber-500">
        <span class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('pos.weight_based') }}</span>
    </label>
</div>
@endif

@if(in_array('unit_type', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.unit_type') }}</label>
    <select name="unit_type" class="{{ $ic }}">
        <option value="">{{ __('pos.select') }}</option>
        @foreach(['kg', 'g', 'lb', 'oz', 'ltr', 'ml', 'ft', 'm', 'pcs', 'pair', 'dozen', 'box', 'bag'] as $ut)
        <option value="{{ $ut }}" {{ ($product->unit_type ?? '') === $ut ? 'selected' : '' }}>{{ strtoupper($ut) }}</option>
        @endforeach
    </select>
</div>
@endif

@if(in_array('size', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.size_label') }}</label>
    <select name="size" class="{{ $ic }}">
        <option value="">{{ __('pos.select') }}</option>
        @foreach(['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', 'Free Size', '28', '30', '32', '34', '36', '38', '40', '42'] as $sz)
        <option value="{{ $sz }}" {{ ($product->size ?? '') === $sz ? 'selected' : '' }}>{{ $sz }}</option>
        @endforeach
    </select>
</div>
@endif

@if(in_array('color', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.color_label') }}</label>
    <input type="text" name="color" value="{{ $product->color ?? '' }}" placeholder="{{ __('pos.ph_color_eg') }}" class="{{ $ic }}">
</div>
@endif

@if(in_array('season', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.season_label') }}</label>
    <select name="season" class="{{ $ic }}">
        <option value="">{{ __('pos.select') }}</option>
        @foreach(['summer', 'winter', 'spring', 'autumn', 'all-season'] as $s)
        <option value="{{ $s }}" {{ ($product->season ?? '') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
        @endforeach
    </select>
</div>
@endif

@if(in_array('serial_number', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.serial_number') }}</label>
    <input type="text" name="serial_number" value="{{ $product->serial_number ?? '' }}" placeholder="{{ __('pos.ph_device_serial') }}" class="{{ $ic }}">
</div>
@endif

@if(in_array('warranty_months', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.warranty_months') }}</label>
    <input type="number" name="warranty_months" value="{{ $product->warranty_months ?? '' }}" min="0" placeholder="0" class="{{ $ic }}">
</div>
@endif

@if(in_array('imei', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.imei_label') }}</label>
    <input type="text" name="imei" value="{{ $product->imei ?? '' }}" placeholder="{{ __('pos.ph_imei_15') }}" maxlength="20" class="{{ $ic }}">
</div>
@endif

@if(in_array('bulk_discount_qty', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.bulk_discount_qty') }}</label>
    <input type="number" name="bulk_discount_qty" value="{{ $product->bulk_discount_qty ?? '' }}" min="0" placeholder="{{ __('pos.ph_min_qty_discount') }}" class="{{ $ic }}">
</div>
@endif

@if(in_array('bulk_discount_pct', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.bulk_discount_pct') }}</label>
    <input type="number" name="bulk_discount_pct" value="{{ $product->bulk_discount_pct ?? '' }}" step="0.01" min="0" max="100" placeholder="0.00" class="{{ $ic }}">
</div>
@endif

@if(in_array('service_duration', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.duration_minutes') }}</label>
    <input type="number" name="service_duration" value="{{ $product->service_duration ?? '' }}" min="0" placeholder="e.g. 30" class="{{ $ic }}">
</div>
@endif

@if(in_array('staff_assignment', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.staff_stylist') }}</label>
    <input type="text" name="staff_assignment" value="{{ $product->staff_assignment ?? '' }}" placeholder="{{ __('pos.ph_assigned_staff') }}" class="{{ $ic }}">
</div>
@endif

@if(in_array('vehicle_make', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.vehicle_make') }}</label>
    <input type="text" name="vehicle_make" value="{{ $product->vehicle_make ?? '' }}" placeholder="{{ __('pos.ph_vehicle_make_eg') }}" class="{{ $ic }}">
</div>
@endif

@if(in_array('vehicle_model', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.vehicle_model') }}</label>
    <input type="text" name="vehicle_model" value="{{ $product->vehicle_model ?? '' }}" placeholder="{{ __('pos.ph_vehicle_model_eg') }}" class="{{ $ic }}">
</div>
@endif

@if(in_array('part_number', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.part_number') }}</label>
    <input type="text" name="part_number" value="{{ $product->part_number ?? '' }}" placeholder="{{ __('pos.ph_oem_part') }}" class="{{ $ic }}">
</div>
@endif

@if(in_array('custom_order', $categoryFields))
<div class="flex items-center gap-2 {{ $isCompact ? '' : 'pt-5' }}">
    <label class="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" name="custom_order" value="1" {{ ($product->custom_order ?? false) ? 'checked' : '' }} class="rounded border-gray-300 text-pink-600 focus:ring-pink-500">
        <span class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('pos.custom_order') }}</span>
    </label>
</div>
@endif

@if(in_array('box_type', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.box_type') }}</label>
    <select name="box_type" class="{{ $ic }}">
        <option value="">{{ __('pos.select') }}</option>
        @foreach(['standard', 'gift-box', 'premium', 'party-pack', 'half-kg', '1-kg', '2-kg', 'custom'] as $bt)
        <option value="{{ $bt }}" {{ ($product->box_type ?? '') === $bt ? 'selected' : '' }}>{{ ucfirst(str_replace('-', ' ', $bt)) }}</option>
        @endforeach
    </select>
</div>
@endif
