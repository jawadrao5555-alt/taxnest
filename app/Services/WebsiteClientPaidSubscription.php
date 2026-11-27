<?php

namespace App\Services;

use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;

/** Checks current paid package coverage without granting access or changing publication approval. */
final class WebsiteClientPaidSubscription
{
    /**
     * @param list<int> $companyIds
     * @return list<int>
     */
    public static function eligibleCompanyIds(array $companyIds): array
    {
        if ($companyIds === []) {
            return [];
        }

        $latest = Subscription::query()->whereIn('company_id', $companyIds)->where('active', true)
            ->selectRaw('MAX(id)')->groupBy('company_id');
        $subscriptions = Subscription::query()->whereIn('id', $latest)
            ->with(['company:id,product_type,erps_vertical', 'pricingPlan:id,product_type,erps_vertical,is_trial'])->get();
        $now = CarbonImmutable::now();

        $legacy = $subscriptions->filter(fn (Subscription $term) => !$term->hasLifecycleSnapshot()
            && self::isCurrentLegacyTerm($term, $now));
        $paidLegacy = self::paidLegacyTerms($legacy->modelKeys(), $now);

        return $subscriptions->filter(fn (Subscription $term) => $term->hasLifecycleSnapshot()
            ? self::isCurrentProspectiveTerm($term, $now)
            : isset($paidLegacy[$term->getKey()]))
            ->map(fn (Subscription $term): int => (int) $term->company_id)->values()->all();
    }

    private static function isCurrentLegacyTerm(Subscription $term, CarbonImmutable $now): bool
    {
        $plan = $term->entitlementPlan();

        return $term->company && $plan && $plan->is_trial === false && ! $term->trial_ends_at
            && PlanCompanyCompatibility::matches($term->company, $plan)
            && (float) $term->final_price > 0 && $term->start_date && $term->end_date
            && $term->start_date->lessThanOrEqualTo($now) && $term->end_date->greaterThan($now);
    }

    private static function isCurrentProspectiveTerm(Subscription $term, CarbonImmutable $now): bool
    {
        if (!$term->company || !is_array($term->lifecycle_snapshot['prior_entitlement_periods'] ?? [])) {
            return false;
        }
        $lifecycle = ProspectiveSubscriptionPolicy::snapshot($term);
        if (!$lifecycle || empty($lifecycle['paid_starts_at'])) {
            return false;
        }

        $periods = $lifecycle['prior_entitlement_periods'] ?? [];
        $periods[] = [
            'starts_at' => $lifecycle['paid_starts_at'],
            'ends_at' => $lifecycle['paid_ends_at'],
            'purchase_snapshot' => $term->purchase_snapshot,
        ];
        foreach ($periods as $period) {
            if ($now->lessThan(CarbonImmutable::parse($period['starts_at']))
                || !$now->lessThan(CarbonImmutable::parse($period['ends_at']))) {
                continue;
            }

            // The validated lifecycle retained this purchase when full payment was verified.
            $purchase = $period['purchase_snapshot'];
            $plan = (new PricingPlan)->setRawAttributes($purchase['plan']);

            return ($purchase['source'] ?? null) === 'paid_assignment'
                && (float) ($purchase['price']['final_price'] ?? 0) > 0
                && $plan->is_trial === false && PlanCompanyCompatibility::matches($term->company, $plan);
        }

        return false;
    }

    /**
     * @param list<int> $subscriptionIds
     * @return array<int, true>
     */
    private static function paidLegacyTerms(array $subscriptionIds, CarbonImmutable $now): array
    {
        if ($subscriptionIds === []) {
            return [];
        }

        $settled = SubscriptionInvoice::query()->packageCharge()
            ->join('subscriptions', function (JoinClause $join) {
                $join->on('subscriptions.id', '=', 'subscription_invoices.subscription_id')
                    ->on('subscriptions.company_id', '=', 'subscription_invoices.company_id');
            })
            ->whereIn('subscription_invoices.subscription_id', $subscriptionIds)
            ->where('subscription_invoices.status', 'paid')
            ->where('subscription_invoices.paid_at', '<=', $now)
            ->whereColumn('subscription_invoices.amount', '>=', 'subscriptions.final_price')
            ->distinct()->pluck('subscription_invoices.subscription_id');

        // Older verifications may predate issued documents. A linked document takes priority.
        $verified = PaymentProof::query()->subscriptionKind()
            ->join('subscriptions', function (JoinClause $join) {
                $join->on('subscriptions.id', '=', 'payment_proofs.subscription_id')
                    ->on('subscriptions.company_id', '=', 'payment_proofs.company_id')
                    ->on('subscriptions.pricing_plan_id', '=', 'payment_proofs.pricing_plan_id');
            })
            ->whereIn('payment_proofs.subscription_id', array_diff($subscriptionIds, $settled->all()))
            ->where('payment_proofs.status', 'verified')
            ->where('payment_proofs.verified_by', '>', 0)
            ->where('payment_proofs.verified_at', '<=', $now)
            ->whereRaw('COALESCE(payment_proofs.distributor_net_amount, payment_proofs.amount) >= subscriptions.final_price')
            ->whereNotExists(fn ($documents) => $documents->selectRaw('1')->from('subscription_invoices')
                ->whereColumn('subscription_invoices.payment_proof_id', 'payment_proofs.id'))
            ->distinct()->pluck('payment_proofs.subscription_id');

        return array_fill_keys($settled->concat($verified)->all(), true);
    }
}
