<?php

namespace App\Services;

use App\Models\{Company, PaymentProof, PricingPlan};

/** Server-created selection for the existing receipt review flow; grants no access. */
final class PendingSubscriptionSelection
{
    public static function key(int $companyId): string
    {
        return 'billing.pending_selection.'.$companyId;
    }

    public static function quote(Company $company, PricingPlan $plan): ?array
    {
        $selection = session(self::key((int) $company->id));
        $value = is_array($selection) ? ($selection['quote'] ?? null) : null;
        if (!is_array($value) || DistributorDiscountService::validateSnapshot($value, $company, $plan, 'annual') !== null) {
            return null;
        }
        // A completed purchase ends the earlier first-purchase selection.
        if (($selection['verified_proof_id'] ?? null) !== self::lastVerifiedProof((int) $company->id)) {
            return null;
        }

        return $value;
    }

    public static function remember(Company $company, array $quote): void
    {
        session()->put(self::key((int) $company->id), [
            'quote' => $quote, 'verified_proof_id' => self::lastVerifiedProof((int) $company->id),
        ]);
    }

    private static function lastVerifiedProof(int $companyId): int
    {
        return (int) PaymentProof::subscriptionKind()->where('company_id', $companyId)
            ->where('status', 'verified')->max('id');
    }
}
