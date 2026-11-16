<?php
namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** No product change silently reinterprets history or carries paid access across products. */
final class ProductTransitionCompatibility
{
    public static function target(string $product, ?string $category, ?string $package): array
    {
        if (in_array($product, ['pos', 'fbrpos'], true)
            && !in_array($category, PosFeatureService::categories($product === 'fbrpos' ? 'fbr' : 'pra'), true)) {
            throw ValidationException::withMessages(['target_category' => 'Select a category supported by the target product.']);
        }
        if (!$package) throw ValidationException::withMessages(['target_package' => 'Select the target package or the explicit trial option.']);
        $plan = $package === 'trial' ? null : RequestedPackageService::resolvePlan($package, $product);
        if ($package !== 'trial' && !$plan) throw ValidationException::withMessages(['target_package' => 'Select an available package for the target product.']);
        return ['category' => $category, 'plan' => $plan];
    }

    public static function assertPristine(Company $company): void
    {
        // A sibling clone leaves every source record and entitlement accessible.
        foreach (Schema::getTables() as $metadata) {
            $table = $metadata['name'];
            if (in_array($table, ['users', 'companies'], true)) continue;
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')
                && DB::table($table)->where('company_id', $company->id)->exists()) {
                throw ValidationException::withMessages(['product_type' => 'This company has product history, configuration or access. Keep it unchanged and create a separate product account instead.']);
            }
        }
    }

    public static function attributes(string $product, array $target): array
    {
        $attributes = ['product_type' => $product];
        if (in_array($product, ['pos', 'fbrpos'], true)) {
            $attributes += PosFeatureService::registrationAttributes($target['category']);
            if (Schema::hasColumn('companies', 'pos_type')) $attributes['pos_type'] = $target['category'];
        }
        foreach (['fbr_reporting_enabled', 'pra_reporting_enabled'] as $flag) {
            if (Schema::hasColumn('companies', $flag)) $attributes[$flag] = false;
        }
        if (Schema::hasColumn('companies', 'fbr_pos_enabled')) $attributes['fbr_pos_enabled'] = $product === 'fbrpos';
        if (Schema::hasColumn('companies', 'requested_plan_id')) $attributes['requested_plan_id'] = null;
        if (Schema::hasColumn('companies', 'requested_billing_cycle')) $attributes['requested_billing_cycle'] = null;
        return array_replace($attributes, RequestedPackageService::companyAttributes($target['plan']));
    }
}
