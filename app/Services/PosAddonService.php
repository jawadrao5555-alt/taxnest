<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\PosAddon;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
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

        // An "active" row is not enough: some old packages remain active after
        // their end date. Never sell a zero-month feature or attach an already
        // expired entitlement to one of those rows.
        $remainingMonths = self::remainingMonths($sub);
        if ($remainingMonths === null || $remainingMonths === 0) {
            return ['allowed' => false, 'reason_key' => 'pos.addons_package_expired'];
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

        return PosAddonPricingService::normalizeCycle($proof->billing_cycle);
    }

    /**
     * The cycle an add-on SHOULD be sold on for this company: the one its
     * package already runs on.
     *
     * An add-on always expires with the package (see activate()). The billing
     * page defaults to the package's cycle, although the shop may choose a
     * different rate ladder and is then charged for its remaining months.
     */
    public static function cycleForCompany(?Company $company): string
    {
        $sub = self::activeSubscription($company);

        return PosAddonPricingService::normalizeCycle($sub?->billing_cycle);
    }

    /**
     * Exact add-on charge for this shop.
     *
     * Paid features end when the active package ends. Charge only the remaining
     * whole months (a partial month rounds up), using the selected rate ladder:
     * annual rate ÷ 12, quarterly ÷ 3, monthly × 1. Without a dated package,
     * such as on the public signup picker, show one complete selected cycle.
     *
     * @return array{cycle:string,codes:array,lines:array,full_lines:array,total:int,months:int,cycle_months:int,prorated:bool,until:?string}
     */
    public static function quote(
        array $codes,
        string $cycle,
        ?Company $company = null,
        ?Subscription $subscription = null
    ): array
    {
        $cycle = PosAddonPricingService::normalizeCycle($cycle);
        $codes = array_values(array_unique(array_filter(
            $codes,
            fn ($code) => is_string($code) && isset(PosAddonPricingService::ADDONS[$code])
        )));

        $subscription ??= $company ? self::activeSubscription($company) : null;
        $cycleMonths = PosAddonPricingService::monthsForCycle($cycle);
        $months = self::remainingMonths($subscription) ?? $cycleMonths;
        $lines = [];
        $fullLines = [];
        $total = 0;
        foreach ($codes as $code) {
            $fullPrice = PosAddonPricingService::price($code, $cycle);
            $price = (int) round($fullPrice * $months / $cycleMonths);
            $lines[$code] = $price;
            $fullLines[$code] = $fullPrice;
            $total += $price;
        }

        return [
            'cycle' => $cycle,
            'codes' => $codes,
            'lines' => $lines,
            'full_lines' => $fullLines,
            'total' => $total,
            'months' => $months,
            'cycle_months' => $cycleMonths,
            'prorated' => $months !== $cycleMonths,
            'until' => $subscription?->end_date
                ? Carbon::parse($subscription->end_date)->toDateString()
                : null,
        ];
    }

    /**
     * Keep an approved subset on the submitted quote, rather than silently
     * charging it at a later price or with fewer remaining package months.
     */
    public static function narrowSnapshotQuote(array $snapshot, array $codes): ?array
    {
        $snapshotCodes = array_values(array_unique(array_filter(
            $snapshot['codes'] ?? [],
            fn ($code) => is_string($code) && isset(PosAddonPricingService::ADDONS[$code])
        )));
        $codes = array_values(array_intersect($snapshotCodes, $codes));
        $lines = is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [];
        if (empty($codes)) {
            return null;
        }

        $selectedLines = [];
        foreach ($codes as $code) {
            if (!array_key_exists($code, $lines) || !is_numeric($lines[$code])) {
                return null;
            }
            $selectedLines[$code] = max(0, (int) round((float) $lines[$code]));
        }

        return array_merge($snapshot, [
            'codes' => $codes,
            'lines' => $selectedLines,
            'total' => array_sum($selectedLines),
        ]);
    }

    /**
     * Whole remaining months through the package end date. A partial month is
     * chargeable as a full month, matching the paid extra-branch rule.
     */
    private static function remainingMonths(?Subscription $subscription): ?int
    {
        if (!$subscription || empty($subscription->end_date)) {
            return null;
        }

        try {
            $end = Carbon::parse($subscription->end_date)->endOfDay();
        } catch (\Throwable $e) {
            return null;
        }

        if ($end->isPast()) {
            return 0;
        }

        $today = Carbon::now()->startOfDay();
        $expiry = $end->startOfDay();

        // Calendar-month arithmetic is deliberate: a package from 1 December
        // to 1 March is exactly three months even though it spans 90–92 days,
        // and an annual term across a leap day remains twelve months. Any
        // fraction beyond a whole calendar-month boundary rounds up.
        $months = (($expiry->year - $today->year) * 12) + ($expiry->month - $today->month);
        $wholeMonthBoundary = $today->copy()->addMonthsNoOverflow($months);
        if ($wholeMonthBoundary->lt($expiry)) {
            $months++;
        }

        return max(1, $months);
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
        ?Subscription $subscription,
        ?array $approvedQuote = null
    ): void {
        $end = $subscription?->end_date;
        if (!$subscription || !$end) {
            throw new \InvalidArgumentException(
                'Cannot activate a POS add-on without an active subscription end date.'
            );
        }
        $end = $end instanceof \DateTimeInterface ? $end->format('Y-m-d') : (string) $end;

        $quote = $approvedQuote ?? self::quote($codes, $cycle, $company, $subscription);
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