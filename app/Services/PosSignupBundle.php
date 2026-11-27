<?php

namespace App\Services;

use App\Models\{Company, PaymentProof, PosAddon, PricingPlan, SignupPackageRequest, Subscription};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** A PRA signup can pay for one federal connection and branch slots with its first term. */
final class PosSignupBundle
{
    public const KEY = 'pra_signup_bundle';

    public static function available(): bool
    {
        return ProspectiveSubscriptionPolicy::available()
            && Schema::hasTable('signup_package_requests')
            && Schema::hasColumn('payment_proofs', 'distributor_quote_snapshot')
            && Schema::hasColumn('subscription_invoices', 'payment_proof_id')
            && BranchAddonService::slotsColumnExists() && Schema::hasColumn('pos_addons', 'subscription_id');
    }

    public static function branchEligible(PricingPlan $plan): bool
    {
        return BranchAddonService::supportsPlan($plan)
            && $plan->branch_limit !== null && (int) $plan->branch_limit >= 0;
    }

    public static function federalPrices(): array
    {
        $prices = [];
        foreach (PosFederalAddonAccess::CODES as $code) {
            if (($price = PosAddonPricingService::price($code)) > 0) {
                $prices[$code] = $price;
            }
        }
        return $prices;
    }

    public static function addToQuote(array $quote, Company $company, PricingPlan $plan, ?string $federal, int $slots): array
    {
        if (!self::available() || $company->product_type !== 'pos' || !PosAddonService::isPraCompany($company)) {
            self::invalid('This signup add-on bundle is currently unavailable.');
        }
        if ($slots < 0 || $slots > BranchAddonService::MAX_QTY || ($slots > 0 && !self::branchEligible($plan))) {
            self::invalid('Choose a package with a fixed branch limit to buy extra slots.');
        }
        $annualPrice = $federal === null ? 0 : (self::federalPrices()[$federal] ?? 0);
        if ($federal !== null && ($annualPrice <= 0 || $quote['cycle'] !== 'annual')) {
            self::invalid('Choose a priced federal integration on an annual package.');
        }
        $branchPrice = (int) BranchAddonService::priceForMonths($slots, BranchAddonService::monthsForCycle($quote['cycle']));
        $quote[self::KEY] = [
            'federal' => $federal,
            'federal_amount' => $annualPrice,
            'branch_slots' => $slots,
            'branch_amount' => $branchPrice,
        ];
        $quote['undiscounted_addon_amount'] += $branchPrice;
        $quote['pricing_snapshot']['extra_branch_slots'] += $slots;
        $quote['pricing_snapshot']['extra_branch_price'] += $branchPrice;
        $quote['pricing_snapshot']['final_price'] += $branchPrice;
        $quote['net_quote'] = round($quote['net_quote'] + $branchPrice + $annualPrice, 2);
        return $quote;
    }

    /** Reads only the stored server quote. There is no quote data in the customer POST. */
    public static function pending(Company $company): ?SignupPackageRequest
    {
        return self::saleQuery($company)?->where('status', 'pending')->first();
    }

    public static function reservation(Company $company): ?SignupPackageRequest
    {
        return self::saleQuery($company)?->first();
    }

    private static function saleQuery(Company $company): ?Builder
    {
        if ($company->product_type !== 'pos' || !Schema::hasTable('signup_package_requests')) {
            return null;
        }
        return SignupPackageRequest::where('company_id', $company->id)->whereNotNull('quote_snapshot->'.self::KEY);
    }

    public static function validateQuote(array $quote, Company $company, PricingPlan $plan): ?string
    {
        if (!array_key_exists(self::KEY, $quote)) return null;
        $bundle = $quote[self::KEY];
        if (!is_array($bundle) || !array_key_exists('federal', $bundle) || $company->product_type !== 'pos' || !PosAddonService::isPraCompany($company)
            || $plan->product_type !== 'pos' || $plan->is_trial) {
            return 'The signup add-ons do not match this PRA package.';
        }
        $code = $bundle['federal'] ?? null;
        $federal = $bundle['federal_amount'] ?? null;
        $slots = $bundle['branch_slots'] ?? null;
        $branches = $bundle['branch_amount'] ?? null;
        if (!is_int($federal) || !is_int($slots) || !is_int($branches)
            || $slots < 0 || $slots > BranchAddonService::MAX_QTY || $branches < 0
            || ($slots === 0 ? $branches !== 0 : (!self::branchEligible($plan) || $branches <= 0))
            || ($code === null ? $federal !== 0 : (!is_string($code) || !PosFederalAddonAccess::isFederalCode($code)
                || $federal <= 0 || ($quote['cycle'] ?? null) !== 'annual'))
            || ($slots === 0 && $code === null)
            || (int) ($quote['pricing_snapshot']['extra_branch_slots'] ?? -1) !== $slots
            || round((float) ($quote['undiscounted_addon_amount'] ?? -1), 2) !== (float) $branches
            || round((float) ($quote['pricing_snapshot']['extra_branch_price'] ?? -1), 2) !== (float) $branches) {
            return 'The frozen PRA signup add-on breakdown is inconsistent.';
        }
        return null;
    }

    public static function federalAmount(?array $quote): int
    {
        return (int) ($quote[self::KEY]['federal_amount'] ?? 0);
    }

    public static function belongsToPendingQuote(?array $quote, ?SignupPackageRequest $sale): bool
    {
        return $sale !== null && $quote == $sale->quote_snapshot;
    }

    /** Both collection routes call this while holding the company lock. */
    public static function guardSettlement(Company $company, ?array $quote, float $received): void
    {
        $sale = self::pending($company);
        $bundle = $quote[self::KEY] ?? null;
        if (!$sale && !$bundle) return;
        if (!$bundle || !self::available() || !self::belongsToPendingQuote($quote, $sale)) {
            self::invalid('Use the pending signup quote and its original add-ons to confirm payment.');
        }
        if ($received !== round((float) $sale->quoted_amount, 2)) {
            self::invalid('The confirmed amount must equal the original signup package and add-on total.');
        }
        if (in_array($company->status, ['rejected', 'suspended'], true)
            || in_array($company->company_status, ['rejected', 'suspended'], true)
            || BranchAddonService::slots($company) !== 0
            || ($bundle['branch_slots'] > 0 && ($company->is_internal_account || $company->branch_limit_override !== null))
            || PaymentProof::subscriptionKind()->where('company_id', $company->id)->where('status', 'verified')->exists()) {
            self::invalid('The company’s branch or paid-package setup changed. Review the signup quote before confirming payment.');
        }
        if ($bundle['federal'] !== null && (!PosFederalAddonAccess::selectionAllowed($company, [$bundle['federal']])
            || PosAddon::where('company_id', $company->id)->whereIn('addon_code', PosFederalAddonAccess::CODES)->exists())) {
            self::invalid('Review the company’s existing federal setup before confirming the signup quote.');
        }
    }

    /** Assign the already verified, term-bound grants in the receipt transaction. */
    public static function activate(Company $company, Subscription $subscription, PaymentProof $proof): void
    {
        $bundle = $proof->distributor_quote_snapshot[self::KEY] ?? null;
        if (!$bundle) return;
        if ($bundle['branch_slots'] > 0) {
            $company->update(['extra_branch_slots' => $bundle['branch_slots']]);
        }
        $code = $bundle['federal'];
        if ($code !== null) {
            PosAddonService::activate($company, [$code], $subscription->billing_cycle, $bundle['federal_amount'], $proof->id,
                $subscription, ['codes' => [$code], 'cycle' => $subscription->billing_cycle, 'lines' => [$code => $bundle['federal_amount']]]);
            PosAddonService::flushCache();
        }
    }

    public static function coversFederalGrant(PaymentProof $proof, PosAddon $grant): bool
    {
        $quote = $proof->distributor_quote_snapshot;
        $bundle = $quote[self::KEY] ?? null;
        return $proof->kind() === 'subscription' && is_array($bundle)
            && (int) $proof->pricing_plan_id === (int) ($quote['plan_id'] ?? 0)
            && (int) $proof->company_id === (int) ($quote['company_id'] ?? 0)
            && $proof->billing_cycle === ($quote['cycle'] ?? null)
            && $grant->addon_code === ($bundle['federal'] ?? null)
            && (float) $grant->amount === (float) ($bundle['federal_amount'] ?? 0)
            && round((float) $proof->distributor_net_amount, 2) === round((float) ($quote['net_quote'] ?? 0), 2);
    }

    private static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['pra_signup_bundle' => $message]);
    }
}
