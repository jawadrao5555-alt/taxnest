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
        $company = \App\Models\Company::find($companyId);
        if ($company && $company->is_internal_account) {
            return ['allowed' => true, 'internal' => true];
        }

        if ($company && $company->invoice_limit_override !== null) {
            if ($company->invoice_limit_override === -1) {
                return ['allowed' => true, 'unlimited' => true];
            }
            $count = Invoice::where('company_id', $companyId)->count();
            $limit = $company->invoice_limit_override;
            if ($count >= $limit) {
                return ['allowed' => false, 'reason' => "Invoice limit reached ({$count}/{$limit}). Please contact admin."];
            }
            return ['allowed' => true, 'remaining' => $limit - $count];
        }

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
        $company = \App\Models\Company::find($companyId);
        if ($company && $company->is_internal_account) {
            return ['allowed' => true, 'internal' => true];
        }

        if ($company && $company->user_limit_override !== null) {
            if ($company->user_limit_override === -1) {
                return ['allowed' => true, 'unlimited' => true];
            }
            $count = User::where('company_id', $companyId)->where('is_active', true)->count();
            $limit = $company->user_limit_override;
            if ($count >= $limit) {
                return ['allowed' => false, 'reason' => "User limit reached ({$count}/{$limit}). Please contact admin."];
            }
            return ['allowed' => true, 'remaining' => $limit - $count];
        }

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
        $company = \App\Models\Company::find($companyId);
        if ($company && $company->is_internal_account) {
            return ['allowed' => true, 'internal' => true];
        }

        if ($company && $company->branch_limit_override !== null) {
            if ($company->branch_limit_override === -1) {
                return ['allowed' => true, 'unlimited' => true];
            }
            $count = Branch::where('company_id', $companyId)->count();
            $limit = $company->branch_limit_override;
            if ($count >= $limit) {
                return ['allowed' => false, 'reason' => "Branch limit reached ({$count}/{$limit}). Please contact admin."];
            }
            return ['allowed' => true, 'remaining' => $limit - $count];
        }

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

    public static function getEffectiveLimits(int $companyId): array
    {
        $company = \App\Models\Company::find($companyId);
        $sub = self::getActiveSubscription($companyId);
        $plan = $sub?->pricingPlan;

        $invoiceLimit = $company?->invoice_limit_override ?? $plan?->invoice_limit ?? 0;
        $userLimit = $company?->user_limit_override ?? $plan?->user_limit ?? 0;
        $branchLimit = $company?->branch_limit_override ?? $plan?->branch_limit ?? 0;

        $invoiceCount = Invoice::where('company_id', $companyId)->count();
        $userCount = User::where('company_id', $companyId)->where('is_active', true)->count();
        $branchCount = Branch::where('company_id', $companyId)->count();

        return [
            'invoice' => [
                'limit' => $invoiceLimit,
                'used' => $invoiceCount,
                'source' => $company?->invoice_limit_override !== null ? 'admin_override' : 'plan',
                'display' => $invoiceLimit === -1 ? 'Unlimited' : $invoiceLimit,
            ],
            'user' => [
                'limit' => $userLimit,
                'used' => $userCount,
                'source' => $company?->user_limit_override !== null ? 'admin_override' : 'plan',
                'display' => $userLimit === -1 ? 'Unlimited' : $userLimit,
            ],
            'branch' => [
                'limit' => $branchLimit,
                'used' => $branchCount,
                'source' => $company?->branch_limit_override !== null ? 'admin_override' : 'plan',
                'display' => $branchLimit === -1 ? 'Unlimited' : $branchLimit,
            ],
        ];
    }
}
