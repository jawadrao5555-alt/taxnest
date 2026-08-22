<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\PosAddon;
use App\Models\Subscription;
use Illuminate\Support\Facades\Schema;

// PosFeatureService is the single gate; purchasableCodes() asks it what the
// package already grants so the shop is never sold something it owns.

/**
 * PRA POS add-on eligibility, quoting and entitlement resolution.
 *
 * The package gate remains the single call site used by the app. This service
 * only supplies an additional allow when a Business+ PRA company has a
 * verified, unexpired add-on.
 */
class PosAddonService
{
    /**
     * A public landing selection survives registration in the browser session
     * until the paid Business+ shop opens the authenticated billing page.
     * Only catalogue codes + cycle live here; every price is quoted afresh.
     */
    public const SIGNUP_SESSION_KEY = 'pos.requested_addons';

    /** company_id => [addon_code, ...] — one query per company per request. */
    protected static array $activeCache = [];

    /** hasTable() per gate call = 6 DB round trips per request; memoize. */
    private static ?bool $tableExists = null;

    public static function flushCache(): void
    {
        self::$activeCache = [];
        self::$tableExists = null;
    }

    public static function tableExists(): bool
    {
        if (self::$tableExists === null) {
            try {
                self::$tableExists = Schema::hasTable('pos_addons');
            } catch (\Throwable $e) {
                self::$tableExists = false;
            }
        }

        return self::$tableExists;
    }

    public static function catalog(): array
    {
        return PosAddonPricingService::catalog();
    }

    public static function isPraCompany(?Company $company): bool
    {
        return $company !== null
            && ($company->pos_integration_mode ?? 'pra') === 'pra';
    }

    public static function activeSubscription(?Company $company): ?Subscription
    {
        if (!$company) {
            return null;
        }

        return PlanLimitService::getActiveSubscription($company->id);
    }

    /**
     * Add-ons can be purchased by paid Business+ PRA packages only.
     * Starter and trial accounts must upgrade first.
     */
    public static function purchaseEligibility(?Company $company): array
    {
        if (!self::isPraCompany($company)) {
            return ['allowed' => false, 'reason_key' => 'pos.addons_not_available'];
        }

        $sub = self::activeSubscription($company);
        $plan = $sub?->pricingPlan;
        if (!$sub || !$plan || $plan->is_trial || ($plan->product_type ?? null) !== 'pos') {
            return ['allowed' => false, 'reason_key' => 'pos.addons_upgrade_required'];
        }

        if ($plan->name === 'Starter') {
            return ['allowed' => false, 'reason_key' => 'pos.addons_upgrade_required'];
        }

        return ['allowed' => true, 'subscription' => $sub, 'plan' => $plan];
    }

    public static function codeForGate(string $gate): ?string
    {
        foreach (PosAddonPricingService::ADDONS as $code => $addon) {
            if (($addon['gate'] ?? null) === $gate) {
                return $code;
            }
        }

        return null;
    }

    public static function hasActive(?Company $company, string $code): bool
    {
        if (!$company || !self::isPraCompany($company) || !isset(PosAddonPricingService::ADDONS[$code])) {
            return false;
        }

        return in_array($code, self::activeCodes($company), true);
    }

    public static function allowsGate(?Company $company, string $gate): bool
    {
        $code = self::codeForGate($gate);
        return $code !== null && self::hasActive($company, $code);
    }

    public static function activeCodes(?Company $company): array
    {
        if (!$company) {
            return [];
        }
        if (array_key_exists($company->id, self::$activeCache)) {
            return self::$activeCache[$company->id];
        }
        if (!self::tableExists()) {
            return self::$activeCache[$company->id] = [];
        }

        return self::$activeCache[$company->id] = PosAddon::where('company_id', $company->id)
            ->where('active', true)
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', now()->toDateString());
            })
            ->pluck('addon_code')
            ->filter(fn ($code) => isset(PosAddonPricingService::ADDONS[$code]))
            ->values()
            ->all();
    }

    public static function pendingCodes(?Company $company): array
    {
        if (!$company || !PaymentProof::addonCodesColumnExists()) {
            return [];
        }

        return PaymentProof::posAddonKind()
            ->where('company_id', $company->id)
            ->where('status', 'pending')
            ->get()
            ->flatMap(fn ($proof) => $proof->addonCodeList())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Add-ons this company can still buy: catalogue minus what the package
     * already grants, minus what is already active or pending review.
     */
    public static function purchasableCodes(?Company $company): array
    {
        if (!$company) {
            return [];
        }

        $taken = array_merge(self::activeCodes($company), self::pendingCodes($company));
        $out = [];
        foreach (PosAddonPricingService::ADDONS as $code => $addon) {
            if (in_array($code, $taken, true)) {
                continue;
            }
            // Already included in the package? Then there is nothing to sell.
            if (PosFeatureService::planAllows($company, $addon['gate'])) {
                continue;
            }
            $out[] = $code;
        }

        return $out;
    }

    /** Cycle the shop actually requested on a pos_addon proof. */
    public static function cycleForProof(?PaymentProof $proof): ?string
    {
        if (!$proof || !$proof->isPosAddon()) {
            return null;
        }

        return $proof->billing_cycle === 'quarterly' ? 'quarterly' : 'annual';
    }

    public static function quote(array $codes, string $cycle): array
    {
        $cycle = $cycle === 'quarterly' ? 'quarterly' : 'annual';
        $codes = array_values(array_unique(array_filter(
            $codes,
            fn ($code) => is_string($code) && isset(PosAddonPricingService::ADDONS[$code])
        )));

        $lines = [];
        $total = 0;
        foreach ($codes as $code) {
            $price = PosAddonPricingService::price($code, $cycle);
            $lines[$code] = $price;
            $total += $price;
        }

        return ['cycle' => $cycle, 'codes' => $codes, 'lines' => $lines, 'total' => $total];
    }

    /**
     * Switch the bought features on.
     *
     * An add-on ALWAYS expires with the package it was bought against — there
     * is deliberately no fallback expiry. If the subscription vanished or has
     * no end date between request and approval, the caller must refuse the
     * approval rather than mint an entitlement on an invented date.
     *
     * @throws \InvalidArgumentException when the subscription cannot date it
     */
    public static function activate(
        Company $company,
        array $codes,
        string $cycle,
        float|int $amount,
        int $proofId,
        ?Subscription $subscription
    ): void {
        $end = $subscription?->end_date;
        if (!$subscription || !$end) {
            throw new \InvalidArgumentException(
                'Cannot activate a POS add-on without an active subscription end date.'
            );
        }
        $end = $end instanceof \DateTimeInterface ? $end->format('Y-m-d') : (string) $end;

        $quote = self::quote($codes, $cycle);
        $start = now()->toDateString();

        foreach ($quote['codes'] as $code) {
            PosAddon::updateOrCreate(
                ['company_id' => $company->id, 'addon_code' => $code],
                [
                    'active' => true,
                    'billing_cycle' => $quote['cycle'],
                    'amount' => $quote['lines'][$code],
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'payment_proof_id' => $proofId,
                    'subscription_id' => $subscription?->id,
                ]
            );
        }
    }
}