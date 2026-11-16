<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Frozen owner-approved prospective contract; null historical rows remain legacy. */
final class ProspectiveSubscriptionPolicy
{
    public const VERSION = 'selected_trial_v1';

    public static function available(): bool
    {
        return Schema::hasColumn('subscriptions', 'lifecycle_snapshot');
    }

    public static function registrationPlans(string $product)
    {
        if (! Schema::hasTable('pricing_plans')) {
            return collect();
        }

        return PricingPlan::whereIn('product_type', \App\Support\NestErps::storedTypesFor($product))->where('is_trial', false)->get()->filter(fn ($p) => ! PlanSellabilityService::isRetired($p))->values();
    }

    public static function resolveRegistrationSelection(\Illuminate\Http\Request $request, string $product): ?PricingPlan
    {
        $plan = RequestedPackageService::resolvePlan($request->input('requested_plan'), $product);
        if (! self::available()) {
            return $plan;
        }
        if ($request->filled('requested_plan') && ! $plan) {
            throw ValidationException::withMessages(['requested_plan' => 'Select an available package for this product.']);
        }
        if ($request->filled('pricing_plan_id')) {
            $idPlan = self::registrationPlans($product)->firstWhere('id', (int) $request->input('pricing_plan_id'));
            if (! $idPlan || ($plan && (int) $plan->id !== (int) $idPlan->id)) {
                throw ValidationException::withMessages(['pricing_plan_id' => 'The selected package must match this product and selection.']);
            }
            $plan ??= $idPlan;
        }
        self::requireSelection($plan);

        return $plan;
    }

    public static function requireSelection(?PricingPlan $plan): void
    {
        if (self::available() && ! $plan) {
            throw ValidationException::withMessages(['requested_plan' => 'Select an available package for this product.']);
        }
    }

    public static function snapshot(?Subscription $sub): ?array
    {
        $s = $sub?->lifecycle_snapshot;
        if (! is_array($s) || ($s['version'] ?? null) !== self::VERSION || (int) ($s['company_id'] ?? 0) !== (int) $sub->company_id) {
            return null;
        }
        foreach (['trial_starts_at', 'trial_ends_at'] as $k) {
            if (empty($s[$k])) {
                return null;
            }try {
                CarbonImmutable::parse($s[$k]);
            } catch (\Throwable) {
                return null;
            }
        }
        try {
            if (!CarbonImmutable::parse($s['trial_ends_at'])->greaterThan(CarbonImmutable::parse($s['trial_starts_at']))
                || CarbonImmutable::parse($s['trial_ends_at'])->diffInSeconds(CarbonImmutable::parse($s['trial_starts_at']), true) !== 259200.0) {
                return null;
            }
            $paid = array_filter(array_intersect_key($s, array_flip(['paid_starts_at', 'paid_ends_at', 'grace_ends_at'])));
            if ($paid && count($paid) !== 3) {
                return null;
            }
            if ($paid && (empty($s['first_paid_starts_at']) || CarbonImmutable::parse($s['first_paid_starts_at'])->lessThan(CarbonImmutable::parse($s['trial_ends_at'])) || CarbonImmutable::parse($s['first_paid_starts_at'])->greaterThan(CarbonImmutable::parse($s['paid_starts_at'])))) return null;
            if ($paid && (! CarbonImmutable::parse($s['paid_ends_at'])->greaterThan(CarbonImmutable::parse($s['paid_starts_at'])) || CarbonImmutable::parse($s['paid_ends_at'])->addDays(7)->notEqualTo(CarbonImmutable::parse($s['grace_ends_at'])))) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        $trial = $s['trial_purchase_snapshot'] ?? null;
        if (!self::validPurchase($trial, $sub->company_id, (int) ($s['selected_plan_id'] ?? 0))
            || ($trial['source'] ?? null) !== 'selected_package_trial'
            || ($trial['plan']['is_trial'] ?? null) !== true
            || ($trial['plan']['invoice_limit'] ?? null) !== -1) return null;
        if (!self::validPurchase($sub->purchase_snapshot, $sub->company_id, $sub->pricing_plan_id)) return null;
        $priorEnd = null;
        foreach ($s['prior_entitlement_periods'] ?? [] as $period) {
            try {
                $start = CarbonImmutable::parse($period['starts_at']);
                $end = CarbonImmutable::parse($period['ends_at']);
                if (!$end->greaterThan($start) || $start->lessThan(CarbonImmutable::parse($s['trial_ends_at']))
                    || empty($s['paid_starts_at']) || $end->greaterThan(CarbonImmutable::parse($s['paid_starts_at']))
                    || ($priorEnd && $start->lessThan($priorEnd))
                    || !self::validPurchase($period['purchase_snapshot'] ?? null, $sub->company_id)) return null;
                $priorEnd = $end;
            } catch (\Throwable) { return null; }
        }
        return $s;
    }

    private static function validPurchase(mixed $purchase, int $companyId, ?int $planId = null): bool
    {
        if (!is_array($purchase) || ($purchase['version'] ?? null) !== 1
            || (int) ($purchase['company_id'] ?? 0) !== $companyId
            || !is_array($purchase['plan'] ?? null) || (int) ($purchase['plan']['id'] ?? 0) <= 0
            || ($planId !== null && (int) $purchase['plan']['id'] !== $planId)) return false;
        foreach (['id','name','product_type','is_trial','invoice_limit','user_limit','branch_limit'] as $field) {
            if (!array_key_exists($field, $purchase['plan'])) return false;
        }
        try {
            return !empty($purchase['period']['start']) && !empty($purchase['period']['end'])
                && CarbonImmutable::parse($purchase['period']['end'])->greaterThanOrEqualTo(CarbonImmutable::parse($purchase['period']['start']));
        } catch (\Throwable) { return false; }
    }

    public static function trial(Company $company, PricingPlan $plan): array
    {
        PlanCompanyCompatibility::assertMatches($company, $plan);
        if ($plan->is_trial || PlanSellabilityService::isRetired($plan)) {
            throw ValidationException::withMessages(['pricing_plan_id' => 'Select an available package for this product.']);
        }
        $start = CarbonImmutable::now('UTC');
        $end = $start->addHours(72);
        $purchase = SubscriptionPurchaseSnapshot::capture($company, $plan, ['cycle' => 'monthly', 'final_price' => 0, 'base_price' => 0, 'extra_branch_price' => 0, 'extra_branch_slots' => 0, 'discount_percent' => 0], $start->setTimezone(config('app.timezone'))->toDateString(), $end->setTimezone(config('app.timezone'))->toDateString(), false);
        // Trial identity is not paid/add-on entitlement; only bill count is uncapped.
        $purchase['source'] = 'selected_package_trial';
        $purchase['plan']['is_trial'] = true;
        $purchase['plan']['invoice_limit'] = -1;

        return ['version' => self::VERSION, 'company_id' => (int) $company->id, 'selected_plan_id' => (int) $plan->id, 'selected_plan_name' => $plan->name,
            'trial_starts_at' => $start->toIso8601String(), 'trial_ends_at' => $end->toIso8601String(), 'trial_purchase_snapshot' => $purchase,
            'paid_starts_at' => null, 'paid_ends_at' => null, 'grace_ends_at' => null];
    }

    public static function paid(Subscription $previous, int $months, int $planId, bool $verified, ?float $received, float $required): ?array
    {
        $s = self::snapshot($previous);
        if (! $s) {
            if ($previous->hasLifecycleSnapshot()) {
                throw ValidationException::withMessages(['pricing_plan_id' => 'Stored subscription policy requires review.']);
            }

            return null;
        }
        if (! $verified || $received === null || ! is_finite($received) || ! is_finite($required) || $required <= 0 || round($received, 2) < round($required, 2)) {
            throw ValidationException::withMessages(['verified_received_amount' => 'The full verified package amount is required before this prospective paid term can be confirmed.']);
        }
        $now = CarbonImmutable::now('UTC');
        $start = $now;
        if (empty($s['paid_starts_at'])) {
            $trialEnd = CarbonImmutable::parse($s['trial_ends_at']);
            if ($trialEnd->greaterThan($now)) {
                $start = $trialEnd;
            }
        } elseif ((int) $previous->pricing_plan_id === $planId && ! $previous->hasActiveOverride()) {
            $paidEnd = CarbonImmutable::parse($s['paid_ends_at']);
            if ($paidEnd->greaterThan($now)) {
                $start = $paidEnd;
            }
        }
        // Even a second verified selection cannot activate paid features during the trial.
        $start = $start->max(CarbonImmutable::parse($s['trial_ends_at']));
        // Preserve only periods before the new effective term. A paid plan change
        // starts now, so it must not keep the old package's future entitlements.
        $periods = $s['prior_entitlement_periods'] ?? [];
        if (!empty($s['paid_starts_at'])) $periods[] = ['starts_at' => $s['paid_starts_at'], 'ends_at' => $s['paid_ends_at'], 'purchase_snapshot' => $previous->purchase_snapshot];
        $s['prior_entitlement_periods'] = [];
        foreach ($periods as $period) {
            $periodEnd = CarbonImmutable::parse($period['ends_at'])->min($start);
            if ($periodEnd->greaterThan(CarbonImmutable::parse($period['starts_at']))) {
                $period['ends_at'] = $periodEnd->toIso8601String();
                $s['prior_entitlement_periods'][] = $period;
            }
        }
        $end = $start->addMonthsNoOverflow($months);

        return array_merge($s, ['first_paid_starts_at' => $s['first_paid_starts_at'] ?? $start->toIso8601String(), 'paid_starts_at' => $start->toIso8601String(), 'paid_ends_at' => $end->toIso8601String(), 'grace_ends_at' => $end->addDays(7)->toIso8601String()]);
    }

    public static function display(?Subscription $sub): ?array
    {
        $s = self::snapshot($sub);
        if (! $s) {
            return null;
        }$now = CarbonImmutable::now('UTC');
        $trialEnd = CarbonImmutable::parse($s['trial_ends_at']);
        if ($now->lessThan($trialEnd) && (empty($s['paid_starts_at']) || $now->lessThan(CarbonImmutable::parse($s['paid_starts_at'])))) {
            $phase = 'trial';
        } elseif (! empty($s['paid_ends_at']) && $now->lessThan(CarbonImmutable::parse($s['paid_ends_at']))) {
            $phase = 'paid';
        } elseif (! empty($s['grace_ends_at']) && $now->lessThan(CarbonImmutable::parse($s['grace_ends_at']))) {
            $phase = 'grace';
        } else {
            $phase = 'expired';
        }

        return array_intersect_key($s, array_flip(['version', 'selected_plan_name', 'trial_starts_at', 'trial_ends_at', 'paid_starts_at', 'paid_ends_at', 'grace_ends_at'])) + ['phase' => $phase];
    }

    public static function effectivePurchase(Subscription $sub): ?array
    {
        if ($trial = self::trialPurchase($sub)) {
            return $trial;
        }
        $s = self::snapshot($sub);
        if (! $s) {
            return null;
        }$now = CarbonImmutable::now('UTC');
        foreach ($s['prior_entitlement_periods'] ?? [] as $period) {
            if ($now->greaterThanOrEqualTo(CarbonImmutable::parse($period['starts_at'])) && $now->lessThan(CarbonImmutable::parse($period['ends_at']))) {
                return $period['purchase_snapshot'];
            }
        }

        return null;
    }

    public static function trialPurchase(Subscription $sub): ?array
    {
        return (self::display($sub)['phase'] ?? null) === 'trial' ? (self::snapshot($sub)['trial_purchase_snapshot'] ?? null) : null;
    }
}
