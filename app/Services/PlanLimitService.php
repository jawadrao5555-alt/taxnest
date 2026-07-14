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

    /**
     * PRA POS monthly bill quota (package restructure, Jul 2026).
     *
     * Plans store invoice_limit as bills-per-CALENDAR-MONTH for POS:
     *   Starter 500 / Business 2000 / Pro -1 (unlimited).
     *
     * Counted: FINAL bills only — status='completed' and invoice_mode != 'local'
     * (deliberate provisionals don't consume quota until promoted to PRA).
     * Archived rows still count (they were real sales this month).
     * Trial plans are excluded — the 20-bill total trial cap lives in
     * SubscriptionAccessService::hasAccess and stays untouched.
     */
    public static function canCreatePosBill(int $companyId): array
    {
        $company = \App\Models\Company::find($companyId);
        if ($company && $company->is_internal_account) {
            return ['allowed' => true, 'internal' => true];
        }

        $monthlyCount = function () use ($companyId): int {
            $live = \App\Models\PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('status', 'completed')
                ->where(function ($q) {
                    $q->whereNull('invoice_mode')->orWhere('invoice_mode', '!=', 'local');
                })
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count();

            // Reporting-OFF finals hard-deleted by the day-close DELETE policy would
            // otherwise vanish from this count (quota bypass) — add back the counts
            // persisted on that month's day-close reports. try/catch: column may not
            // exist yet on a prod box mid-deploy (schema-drift self-heal pattern).
            $deletedFinals = 0;
            try {
                $deletedFinals = (int) \App\Models\PosDayCloseReport::where('company_id', $companyId)
                    ->whereBetween('report_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                    ->sum('deleted_final_count');
            } catch (\Throwable $e) {
                // column missing pre-migration — quota falls back to live count only
            }

            return $live + $deletedFinals;
        };

        // Admin override wins (interpreted as bills/month for POS companies).
        if ($company && $company->invoice_limit_override !== null) {
            if ((int) $company->invoice_limit_override === -1) {
                return ['allowed' => true, 'unlimited' => true];
            }
            $limit = (int) $company->invoice_limit_override;
            $count = $monthlyCount();
            if ($count >= $limit) {
                return ['allowed' => false, 'reason' => "Monthly bill limit reached ({$count}/{$limit} this month). Please contact admin."];
            }
            return ['allowed' => true, 'remaining' => $limit - $count];
        }

        $sub = self::getActiveSubscription($companyId);
        if (!$sub || !$sub->pricingPlan) {
            // Access/subscription gating is owned by SubscriptionAccessService —
            // don't double-block here.
            return ['allowed' => true];
        }

        $plan = $sub->pricingPlan;
        if ($plan->is_trial) {
            return ['allowed' => true]; // trial cap handled by SubscriptionAccessService
        }

        $limit = (int) ($plan->invoice_limit ?? -1);
        if ($limit === -1 || $limit <= 0) {
            return ['allowed' => true];
        }

        $count = $monthlyCount();
        if ($count >= $limit) {
            return ['allowed' => false, 'reason' => "Monthly bill limit reached ({$count}/{$limit} bills this month on the {$plan->name} plan). Please upgrade your plan to keep billing."];
        }

        return ['allowed' => true, 'remaining' => $limit - $count];
    }

    /**
     * PRA POS team-account quota: plan user_limit counts the POS panel accounts
     * (pos_admin + pos_cashier) — Starter 1 (admin only), Business 5, Pro -1.
     * Read-only portal accounts (local_viewer / archive_viewer) are super-admin
     * provisioned and never consume the quota.
     */
    public static function canAddPosUser(int $companyId): array
    {
        $company = \App\Models\Company::find($companyId);
        if ($company && $company->is_internal_account) {
            return ['allowed' => true, 'internal' => true];
        }

        $count = User::where('company_id', $companyId)
            ->where('is_active', true)
            ->whereIn('pos_role', ['pos_admin', 'pos_manager', 'pos_cashier'])
            ->count();

        if ($company && $company->user_limit_override !== null) {
            if ((int) $company->user_limit_override === -1) {
                return ['allowed' => true, 'unlimited' => true];
            }
            $limit = (int) $company->user_limit_override;
            if ($count >= $limit) {
                return ['allowed' => false, 'reason' => "Team account limit reached ({$count}/{$limit}). Please contact admin."];
            }
            return ['allowed' => true, 'remaining' => $limit - $count];
        }

        $sub = self::getActiveSubscription($companyId);
        if (!$sub || !$sub->pricingPlan) {
            return ['allowed' => true];
        }

        $limit = $sub->pricingPlan->user_limit;
        if ($limit === null || (int) $limit === -1) {
            return ['allowed' => true];
        }

        if ($count >= (int) $limit) {
            return ['allowed' => false, 'reason' => "Team account limit reached ({$count}/{$limit} on the {$sub->pricingPlan->name} plan). Please upgrade your plan to add more accounts."];
        }

        return ['allowed' => true, 'remaining' => (int) $limit - $count];
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
