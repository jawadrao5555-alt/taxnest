<?php

namespace App\Services;

use App\Models\PricingPlan;
use App\Models\SystemSetting;
use App\Support\HealthPanel;
use App\Support\NestErps;
use App\Support\SchoolErpPanel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ErpPublicPricing
{
    /** Quote-only and private packages remain assignable through admin workflows. */
    public static function availableForPurchase(PricingPlan $plan): bool
    {
        if (!NestErps::isProductType($plan->product_type)) {
            return false;
        }

        $vertical = $plan->getAttribute(NestErps::VERTICAL_COLUMN) ?: NestErps::DEFAULT_VERTICAL;

        return $plan->is_public !== false
            && $plan->erp_website_price_visible !== false
            && !$plan->is_trial
            && (float) $plan->price > 0
            && NestErps::hasVertical($vertical)
            && ($vertical === NestErps::SCHOOL
                ? $plan->erp_website_price_visible === true
                : NestErps::verticalValue($vertical, 'live', false));
    }

    public static function packages(?string $vertical = null): Collection
    {
        if (!Schema::hasColumn('pricing_plans', 'product_type')) {
            return collect();
        }

        return PricingPlan::whereIn('product_type', NestErps::PRODUCT_TYPES)
            ->where('is_trial', false)
            ->where('price', '>', 0)
            ->orderBy('price')->orderBy('id')->get()
            ->filter(fn (PricingPlan $plan) => self::availableForPurchase($plan)
                && ($vertical === null || NestErps::verticalOf($plan) === $vertical))
            ->values();
    }

    public static function websitePackages(): Collection
    {
        $legacyRatesPublished = SystemSetting::get(SystemSetting::APPROVED_BILLING_RATE_CARD_APPLIED) === '1';

        return self::packages()->filter(fn (PricingPlan $plan) =>
            $plan->erp_website_price_visible ?? ($legacyRatesPublished
                && NestErps::verticalOf($plan) === NestErps::HEALTH
                && in_array($plan->name, ['Clinic', 'Hospital'], true)))
            ->groupBy(fn (PricingPlan $plan) => NestErps::verticalOf($plan));
    }

    public static function availableForRegistration(PricingPlan $plan, string $vertical = HealthPanel::VERTICAL): bool
    {
        $matches = $vertical === HealthPanel::VERTICAL
            ? HealthPanel::ownsErpRecord($plan)
            : $plan->getAttribute(NestErps::VERTICAL_COLUMN) === $vertical;

        return $matches && ($vertical !== NestErps::SCHOOL || SchoolErpPanel::registrationOpen())
            && self::availableForPurchase($plan);
    }

    public static function registrationPackages(string $vertical = HealthPanel::VERTICAL): Collection
    {
        return $vertical === NestErps::SCHOOL && !SchoolErpPanel::registrationOpen()
            ? collect() : self::packages($vertical);
    }

    /** Use the same price and cycle policy as selection, proof and approval. */
    public static function prices(PricingPlan $plan): array
    {
        return collect(SubscriptionAssignmentService::cyclesForPlan($plan))
            ->mapWithKeys(fn (string $cycle) => [
                $cycle => SubscriptionAssignmentService::computePrice($plan, $cycle)['final_price'],
            ])->all();
    }

    public static function registrationPlan(?int $id, ?string $name): ?PricingPlan
    {
        $packages = self::registrationPackages();
        $name = trim($name ?? '');
        if ($id !== null) {
            $plan = $packages->firstWhere('id', $id);
            if (!$plan || ($name !== '' && strcasecmp($plan->name, $name) !== 0)) {
                throw ValidationException::withMessages(['pricing_plan_id' => 'Select an available Healthcare package.']);
            }

            return $plan;
        }
        if ($name === '') {
            return null;
        }

        $matches = $packages->filter(fn (PricingPlan $plan) => strcasecmp($plan->name, $name) === 0);
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['requested_plan' => 'Select an available Healthcare package.']);
        }

        return $matches->sole();
    }
}
