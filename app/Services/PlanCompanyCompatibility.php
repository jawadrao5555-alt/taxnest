<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PricingPlan;
use App\Support\NestErps;
use App\Support\ProductCatalog;
use Illuminate\Validation\ValidationException;

final class PlanCompanyCompatibility
{
    /** Null/blank is the original Digital Invoice discriminator. */
    public static function productKey(?string $type): string
    {
        $type = $type ?: ProductCatalog::DI;

        return ProductCatalog::normalize($type) ?? $type;
    }

    public static function matches(Company $company, ?PricingPlan $plan): bool
    {
        if (!$plan) {
            return false;
        }

        $product = self::productKey($company->product_type);
        if (!in_array($product, [...ProductCatalog::TYPES, 'standalone'], true)
            || $product !== self::productKey($plan->product_type)) {
            return false;
        }

        if (NestErps::isProductType($product)) {
            foreach ([$company, $plan] as $model) {
                $vertical = $model->getAttribute(NestErps::VERTICAL_COLUMN);
                if ($vertical !== null && $vertical !== '' && !in_array($vertical, NestErps::verticalKeys(), true)) {
                    return false;
                }
            }
        }

        return !NestErps::isProductType($product)
            || NestErps::verticalOf($company) === NestErps::verticalOf($plan);
    }

    public static function assertMatches(Company $company, ?PricingPlan $plan): void
    {
        if (!self::matches($company, $plan)) {
            throw ValidationException::withMessages([
                'pricing_plan_id' => 'Choose a package for this company\'s product and business type. Its current subscription was not changed.',
            ]);
        }
    }
}
