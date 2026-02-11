<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Branch;

class PlanLimitService
{
    public static function getActiveSubscription(int $companyId): ?Subscription
    {
        return Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->with('pricingPlan')
            ->first();
    }

    public static function canCreateInvoice(int $companyId): array
    {
        $sub = self::getActiveSubscription($companyId);

        if (!$sub) {
            return ['allowed' => false, 'reason' => 'No active subscription. Please subscribe to a plan.'];
        }

        if ($sub->isExpired() && !$sub->isTrialActive()) {
            return ['allowed' => false, 'reason' => 'Your subscription has expired. Please renew your plan.'];
        }

        if ($sub->pricingPlan->is_trial && $sub->isTrialExpired()) {
            return ['allowed' => false, 'reason' => 'Your free trial has expired. Please subscribe to a plan.'];
        }

        $limit = $sub->pricingPlan->invoice_limit;
        if ($limit === -1) {
            return ['allowed' => true];
        }

        $count = Invoice::where('company_id', $companyId)->count();
        if ($count >= $limit) {
            return ['allowed' => false, 'reason' => "Invoice limit reached ({$count}/{$limit}). Please upgrade your plan."];
        }

        return ['allowed' => true, 'remaining' => $limit - $count];
    }

    public static function canAddUser(int $companyId): array
    {
        $sub = self::getActiveSubscription($companyId);

        if (!$sub) {
            return ['allowed' => false, 'reason' => 'No active subscription.'];
        }

        $limit = $sub->pricingPlan->user_limit;
        if ($limit === null || $limit === -1) {
            return ['allowed' => true];
        }

        $count = User::where('company_id', $companyId)->where('is_active', true)->count();
        if ($count >= $limit) {
            return ['allowed' => false, 'reason' => "User limit reached ({$count}/{$limit}). Please upgrade your plan."];
        }

        return ['allowed' => true, 'remaining' => $limit - $count];
    }

    public static function canAddBranch(int $companyId): array
    {
        $sub = self::getActiveSubscription($companyId);

        if (!$sub) {
            return ['allowed' => false, 'reason' => 'No active subscription.'];
        }

        $limit = $sub->pricingPlan->branch_limit;
        if ($limit === null || $limit === -1) {
            return ['allowed' => true];
        }

        $count = Branch::where('company_id', $companyId)->count();
        if ($count >= $limit) {
            return ['allowed' => false, 'reason' => "Branch limit reached ({$count}/{$limit}). Please upgrade your plan."];
        }

        return ['allowed' => true, 'remaining' => $limit - $count];
    }
}
