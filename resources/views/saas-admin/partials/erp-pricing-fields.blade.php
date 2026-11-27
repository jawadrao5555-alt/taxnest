@php($erpPlan = $erpPlan ?? null)
<div class="grid gap-3 sm:grid-cols-2">
    @if(\Illuminate\Support\Facades\Schema::hasColumn('pricing_plans', 'erp_website_price_visible'))
    <label class="text-sm text-gray-300">
        Website price
        <select name="erp_website_price_visible" class="mt-1 block w-full rounded border-gray-700 bg-gray-800 text-white">
            <option value="0" @selected($erpPlan === null || $erpPlan->erp_website_price_visible === false)>Hide price · request a quote</option>
            <option value="1" @selected($erpPlan?->erp_website_price_visible === true)>Publish configured prices on the ERP website</option>
            @if($erpPlan !== null && $erpPlan->erp_website_price_visible === null)
            <option value="" selected>Keep existing website visibility</option>
            @endif
        </select>
    </label>
    @endif
    @foreach(['branch_limit' => 'Branch limit', 'user_limit' => 'User limit', 'health_department_limit' => 'Healthcare department limit'] as $field => $label)
    <label class="text-sm text-gray-300">
        {{ $label }}
        <input name="{{ $field }}" type="number" min="-1" step="1" value="{{ $erpPlan?->getAttribute($field) }}" placeholder="-1 for unlimited" class="mt-1 block w-full rounded border-gray-700 bg-gray-800 text-white">
    </label>
    @endforeach
</div>
<div class="mt-3">
    @php($includedModules = \App\Services\HealthModuleService::normalize($erpPlan?->health_modules ?? []))
    <label class="text-sm text-gray-300"><input type="checkbox" name="configure_health_modules" value="1"> Save included Healthcare modules</label>
    <div class="mt-2 flex flex-wrap gap-3">
        @foreach(\App\Services\HealthModuleService::MODULES as $module)
        <label class="text-sm text-gray-300"><input type="checkbox" name="health_modules[]" value="{{ $module }}" @checked(in_array($module, $includedModules, true))> {{ __(\App\Services\HealthModuleService::moduleLabelKey($module)) }}</label>
        @endforeach
    </div>
</div>
