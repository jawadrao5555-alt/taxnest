<fieldset x-cloak x-show="selectedProduct === '{{ \App\Support\NestErps::PRODUCT_TYPE }}'"
          :disabled="selectedProduct !== '{{ \App\Support\NestErps::PRODUCT_TYPE }}'" class="space-y-2 mt-3">
    <legend class="text-xs font-semibold">Included hospital modules</legend>
    <input type="hidden" name="configure_health_modules" value="1">
    @foreach(\App\Services\HealthModuleService::MODULES as $module)
        <label class="block text-sm"><input type="checkbox" name="health_modules[]" value="{{ $module }}" @checked(in_array($module, $selected, true))>
            {{ __(\App\Services\HealthModuleService::moduleLabelKey($module)) }}</label>
    @endforeach
</fieldset>
