@php
    $product = $product ?? null;
    $inputClass = 'w-full text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-purple-500';
    $smallInputClass = 'text-sm rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-2 py-1.5 w-full';
    $isCompact = $isCompact ?? false;
    $ic = $isCompact ? $smallInputClass : $inputClass;
@endphp

@if(in_array('batch_number', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Batch Number</label>
    <input type="text" name="batch_number" value="{{ $product->batch_number ?? '' }}" placeholder="e.g. BATCH-2026-001" class="{{ $ic }}">
</div>
@endif

@if(in_array('expiry_date', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Expiry Date</label>
    <input type="date" name="expiry_date" value="{{ $product && $product->expiry_date ? $product->expiry_date->format('Y-m-d') : '' }}" class="{{ $ic }}">
</div>
@endif

@if(in_array('drug_type', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Drug Type</label>
    <select name="drug_type" class="{{ $ic }}">
        <option value="">Select</option>
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
        <span class="text-xs font-medium text-gray-600 dark:text-gray-400">Prescription Required</span>
    </label>
</div>
@endif

@if(in_array('weight_based', $categoryFields))
<div class="flex items-center gap-2 {{ $isCompact ? '' : 'pt-5' }}">
    <label class="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" name="weight_based" value="1" {{ ($product->weight_based ?? false) ? 'checked' : '' }} class="rounded border-gray-300 text-amber-600 focus:ring-amber-500">
        <span class="text-xs font-medium text-gray-600 dark:text-gray-400">Weight Based</span>
    </label>
</div>
@endif

@if(in_array('unit_type', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Unit Type</label>
    <select name="unit_type" class="{{ $ic }}">
        <option value="">Select</option>
        @foreach(['kg', 'g', 'lb', 'oz', 'ltr', 'ml', 'ft', 'm', 'pcs', 'pair', 'dozen', 'box', 'bag'] as $ut)
        <option value="{{ $ut }}" {{ ($product->unit_type ?? '') === $ut ? 'selected' : '' }}>{{ strtoupper($ut) }}</option>
        @endforeach
    </select>
</div>
@endif

@if(in_array('size', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Size</label>
    <select name="size" class="{{ $ic }}">
        <option value="">Select</option>
        @foreach(['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', 'Free Size', '28', '30', '32', '34', '36', '38', '40', '42'] as $sz)
        <option value="{{ $sz }}" {{ ($product->size ?? '') === $sz ? 'selected' : '' }}>{{ $sz }}</option>
        @endforeach
    </select>
</div>
@endif

@if(in_array('color', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Color</label>
    <input type="text" name="color" value="{{ $product->color ?? '' }}" placeholder="e.g. Red, Blue" class="{{ $ic }}">
</div>
@endif

@if(in_array('season', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Season</label>
    <select name="season" class="{{ $ic }}">
        <option value="">Select</option>
        @foreach(['summer', 'winter', 'spring', 'autumn', 'all-season'] as $s)
        <option value="{{ $s }}" {{ ($product->season ?? '') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
        @endforeach
    </select>
</div>
@endif

@if(in_array('serial_number', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Serial Number</label>
    <input type="text" name="serial_number" value="{{ $product->serial_number ?? '' }}" placeholder="Device serial #" class="{{ $ic }}">
</div>
@endif

@if(in_array('warranty_months', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Warranty (Months)</label>
    <input type="number" name="warranty_months" value="{{ $product->warranty_months ?? '' }}" min="0" placeholder="0" class="{{ $ic }}">
</div>
@endif

@if(in_array('imei', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">IMEI</label>
    <input type="text" name="imei" value="{{ $product->imei ?? '' }}" placeholder="15-digit IMEI" maxlength="20" class="{{ $ic }}">
</div>
@endif

@if(in_array('bulk_discount_qty', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Bulk Discount Qty</label>
    <input type="number" name="bulk_discount_qty" value="{{ $product->bulk_discount_qty ?? '' }}" min="0" placeholder="Min qty for discount" class="{{ $ic }}">
</div>
@endif

@if(in_array('bulk_discount_pct', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Bulk Discount %</label>
    <input type="number" name="bulk_discount_pct" value="{{ $product->bulk_discount_pct ?? '' }}" step="0.01" min="0" max="100" placeholder="0.00" class="{{ $ic }}">
</div>
@endif

@if(in_array('service_duration', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Duration (minutes)</label>
    <input type="number" name="service_duration" value="{{ $product->service_duration ?? '' }}" min="0" placeholder="e.g. 30" class="{{ $ic }}">
</div>
@endif

@if(in_array('staff_assignment', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Staff / Stylist</label>
    <input type="text" name="staff_assignment" value="{{ $product->staff_assignment ?? '' }}" placeholder="Assigned staff name" class="{{ $ic }}">
</div>
@endif

@if(in_array('vehicle_make', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Vehicle Make</label>
    <input type="text" name="vehicle_make" value="{{ $product->vehicle_make ?? '' }}" placeholder="e.g. Toyota, Honda" class="{{ $ic }}">
</div>
@endif

@if(in_array('vehicle_model', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Vehicle Model</label>
    <input type="text" name="vehicle_model" value="{{ $product->vehicle_model ?? '' }}" placeholder="e.g. Corolla, Civic" class="{{ $ic }}">
</div>
@endif

@if(in_array('part_number', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Part Number</label>
    <input type="text" name="part_number" value="{{ $product->part_number ?? '' }}" placeholder="OEM part #" class="{{ $ic }}">
</div>
@endif

@if(in_array('custom_order', $categoryFields))
<div class="flex items-center gap-2 {{ $isCompact ? '' : 'pt-5' }}">
    <label class="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" name="custom_order" value="1" {{ ($product->custom_order ?? false) ? 'checked' : '' }} class="rounded border-gray-300 text-pink-600 focus:ring-pink-500">
        <span class="text-xs font-medium text-gray-600 dark:text-gray-400">Custom Order</span>
    </label>
</div>
@endif

@if(in_array('box_type', $categoryFields))
<div>
    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Box Type</label>
    <select name="box_type" class="{{ $ic }}">
        <option value="">Select</option>
        @foreach(['standard', 'gift-box', 'premium', 'party-pack', 'half-kg', '1-kg', '2-kg', 'custom'] as $bt)
        <option value="{{ $bt }}" {{ ($product->box_type ?? '') === $bt ? 'selected' : '' }}>{{ ucfirst(str_replace('-', ' ', $bt)) }}</option>
        @endforeach
    </select>
</div>
@endif
