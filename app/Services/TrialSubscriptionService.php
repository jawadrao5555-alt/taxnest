<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guarantees that a freshly-created company ALWAYS ends up with a usable
 * subscription row (trial plan when one exists, otherwise a plan-less row
 * carrying trial_ends_at so SubscriptionAccessService still has a bounded
 * trial window instead of nothing at all).
 *
 * Why: the payment-proof free-access hole existed only because a company
 * could end up with NO subscription row. hasAccess() now fails closed on
 * bare plan-less rows, but the healthier guarantee is that no signup path
 * (DI, PRA POS, FBR POS, admin-created) can leave a company without a
 * trial/paid subscription row.
 */
class TrialSubscriptionService
{
    /**
     * Attach the standard trial subscription to a company. Idempotent: if the
     * company already has an active subscription, nothing new is created.
     */
    public static function ensureSelectedTrial(int $companyId, string $productType, int $planId): Subscription
    {
        return DB::transaction(function () use ($companyId,$productType,$planId) {
            $company=Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $existing=Subscription::where('company_id',$companyId)->where('active',true)->lockForUpdate()->first();
            if($existing)return $existing;
            if (\App\Support\NestErps::canonicalProductType($company->product_type) !== \App\Support\NestErps::canonicalProductType($productType)) throw \Illuminate\Validation\ValidationException::withMessages(['product_type'=>'Select the matching product trial.']);
            $plan=PricingPlan::whereKey($planId)->lockForUpdate()->firstOrFail();
            $snapshot=ProspectiveSubscriptionPolicy::trial($company,$plan);
            $trialStart=\Carbon\Carbon::parse($snapshot['trial_starts_at'])->setTimezone(config('app.timezone'));
            $trialEnd=\Carbon\Carbon::parse($snapshot['trial_ends_at'])->setTimezone(config('app.timezone'));
            return Subscription::create(['company_id'=>$companyId,'pricing_plan_id'=>$planId,'billing_cycle'=>'monthly',
                'discount_percent'=>0,'final_price'=>0,'start_date'=>$trialStart,'end_date'=>$trialEnd,
                'trial_ends_at'=>$trialEnd,'active'=>true,'purchase_snapshot'=>$snapshot['trial_purchase_snapshot'],'lifecycle_snapshot'=>$snapshot]);
        });
    }

    public static function ensureTrial(int $companyId, string $productType, int $days = 3): Subscription
    {
        return DB::transaction(function () use ($companyId, $productType, $days) {
            // Same replacement mutex as paid assignment: retries/concurrent signup
            // cannot stack trials or race a paid term into being replaced.
            $company = Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $existing = Subscription::where('company_id', $companyId)
                ->where('active', true)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }
            $actualProduct = \App\Support\NestErps::canonicalProductType($company->product_type);
            if ($actualProduct && $actualProduct !== \App\Support\NestErps::canonicalProductType($productType)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'product_type' => 'The trial must belong to the company product.',
                ]);
            }

            // A missing product seed retains the existing bounded, plan-less trial;
            // another product's feature/limit catalogue is never an entitlement.
            $trialPlan = PricingPlan::whereIn('product_type', \App\Support\NestErps::storedTypesFor($productType))
                ->where('is_trial', true)
                ->orderBy('id')->lockForUpdate()->first();

            $attributes = [
                'company_id' => $companyId,
                'pricing_plan_id' => $trialPlan?->id,
                'billing_cycle' => 'monthly',
                'discount_percent' => 0,
                'final_price' => 0,
                'start_date' => now(),
                'end_date' => now()->addDays($days),
                'trial_ends_at' => now()->addDays($days),
                'active' => true,
            ];
            if ($trialPlan && Schema::hasColumn('subscriptions', 'purchase_snapshot')) {
                $attributes['purchase_snapshot'] = SubscriptionPurchaseSnapshot::capture($company, $trialPlan,
                    ['cycle' => 'monthly', 'final_price' => 0, 'base_price' => 0, 'extra_branch_price' => 0,
                        'extra_branch_slots' => 0, 'discount_percent' => 0],
                    $attributes['start_date']->toDateString(), $attributes['end_date']->toDateString(), false);
                $attributes['purchase_snapshot']['source'] = 'trial_assignment';
            }

            return Subscription::create($attributes);
        });
    }
}
