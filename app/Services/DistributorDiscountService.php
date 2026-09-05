<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Models\Subscription;
use Illuminate\Support\Facades\Schema;

/** Single source of truth for first annual distributor package discounts. */
class DistributorDiscountService
{
    public const VERSION = 1;

    public static function quote(Company $company, PricingPlan $plan, string $cycle, bool $respectPending = true): array
    {
        $price = SubscriptionAssignmentService::computePrice($plan, $cycle, $company);
        $cycle = $price['cycle'];
        $grossPackage = round((float) $price['base_price'], 2);
        $addon = round((float) $price['extra_branch_price'], 2);
        $hasVerifiedPurchase = PaymentProof::subscriptionKind()
            ->where('company_id', $company->id)
            ->where('status', 'verified')
            ->exists();

        // DI's direct plan-selection route creates the subscription before a
        // receipt exists. Reuse that active immutable quote so repeat clicks,
        // policy edits, or a later payment-proof upload cannot mint a second or
        // differently-priced "first purchase" discount.
        if (!$hasVerifiedPurchase
            && Schema::hasTable('subscriptions')
            && Schema::hasColumn('subscriptions', 'distributor_quote_snapshot')) {
            $activeSnapshot = Subscription::where('company_id', $company->id)
                ->where('active', true)
                ->whereNotNull('distributor_quote_snapshot')
                ->latest('id')
                ->value('distributor_quote_snapshot');
            if (is_string($activeSnapshot)) $activeSnapshot = json_decode($activeSnapshot, true);
            if (is_array($activeSnapshot)
                && self::validateSnapshot($activeSnapshot, $company, $plan, $cycle) === null) {
                return $activeSnapshot;
            }
        }

        $eligible = in_array($cycle, ['annual', 'yearly'], true) && !$hasVerifiedPurchase;
        if ($respectPending) {
            $eligible = $eligible && !PaymentProof::subscriptionKind()->where('company_id', $company->id)
                ->where('status', 'pending')->whereNotNull('distributor_quote_snapshot')->exists();
        }
        $attributedAgentId = $company->agent_id ? (int) $company->agent_id : null;
        $agent = $eligible && $attributedAgentId ? Agent::find($attributedAgentId) : null;
        $discount = $agent?->isActive() ? DistributorPolicyService::discountFor($agent) : 0.0;
        $discountAmount = round($grossPackage * $discount / 100, 2);
        $net = round($grossPackage - $discountAmount + $addon, 2);
        return [
            'version'=>self::VERSION, 'agent_id'=>$attributedAgentId, 'company_id'=>$company->id,
            'plan_id'=>$plan->id, 'product_type'=>$plan->product_type ?? 'di', 'cycle'=>'annual',
            'gross_package_price'=>$grossPackage, 'undiscounted_addon_amount'=>$addon,
            'discount_percent'=>$discount, 'discount_amount'=>$discountAmount,
            'net_quote'=>$net, 'policy_cap'=>(float)DistributorPolicyService::policy()['max_discount'],
            'created_at'=>now()->toIso8601String(),
        ];
    }

    public static function validateSnapshot(array $s, Company $company, PricingPlan $plan, string $cycle): ?string
    {
        if (($s['version'] ?? null) !== self::VERSION) return 'Unsupported distributor quote version.';
        if ((int)($s['company_id'] ?? 0) !== $company->id || (int)($s['plan_id'] ?? 0) !== $plan->id) return 'Distributor quote does not belong to this company/package.';
        if (($s['product_type'] ?? null) !== ($plan->product_type ?? 'di') || ($s['cycle'] ?? null) !== 'annual' || $cycle !== 'annual') return 'Distributor quote product or billing cycle is inconsistent.';
        $snapshotAgent = ($s['agent_id'] ?? null) === null ? null : (int)$s['agent_id'];
        $companyAgent = $company->agent_id === null ? null : (int)$company->agent_id;
        if ($snapshotAgent !== $companyAgent) return 'Distributor attribution changed after this quote. Submit a fresh payment proof.';
        $math = round((float)$s['gross_package_price'] - (float)$s['discount_amount'] + (float)($s['undiscounted_addon_amount'] ?? 0), 2);
        if ($math !== round((float)$s['net_quote'],2) || (float)$s['discount_percent'] > (float)$s['policy_cap'] || (float)$s['policy_cap'] > 10) return 'Distributor quote arithmetic is invalid.';
        return null;
    }
}