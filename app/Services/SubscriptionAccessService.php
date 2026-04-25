<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\Invoice;
use Carbon\Carbon;

/**
 * Decides whether a company is allowed to perform billable actions
 * (creating invoices, products, users, terminals).
 *
 * Logic order (first match wins):
 *   1. override_type = lifetime          -> ALLOW
 *   2. override_type = usage_free        -> ALLOW until invoice_count >= free_invoice_limit
 *   3. override_type = temporary | grace -> ALLOW while now < override_until
 *   4. ELSE                              -> normal subscription check (active + not expired)
 *
 * Returns: ['allowed' => bool, 'reason' => string, 'override' => string|null]
 */
class SubscriptionAccessService
{
    public static function hasAccess(Company $company): array
    {
        $subscription = Subscription::where('company_id', $company->id)
            ->where('active', true)
            ->orderByDesc('id')
            ->first();

        // No subscription at all — fail closed (matches existing onboarding flow that requires a plan)
        if (!$subscription) {
            return ['allowed' => false, 'reason' => 'No active subscription. Please subscribe to a plan.', 'override' => null];
        }

        $type = $subscription->override_type ?? 'none';

        // 1. Lifetime
        if ($type === 'lifetime') {
            return ['allowed' => true, 'reason' => 'Lifetime free access.', 'override' => 'lifetime'];
        }

        // 2. Usage-free (cap by invoice count)
        if ($type === 'usage_free') {
            $limit = (int) ($subscription->free_invoice_limit ?? 0);
            if ($limit <= 0) {
                return ['allowed' => false, 'reason' => 'Free invoice limit not configured.', 'override' => 'usage_free'];
            }
            $count = Invoice::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)
                ->where('company_id', $company->id)
                ->count();
            if ($count >= $limit) {
                return [
                    'allowed' => false,
                    'reason' => "Free invoice limit reached ({$count}/{$limit}). Please upgrade your plan.",
                    'override' => 'usage_free',
                ];
            }
            return [
                'allowed' => true,
                'reason' => "Usage-free: {$count}/{$limit} invoices used.",
                'override' => 'usage_free',
            ];
        }

        // 3. Temporary / grace
        if (in_array($type, ['temporary', 'grace'], true)) {
            $until = $subscription->override_until ? Carbon::parse($subscription->override_until) : null;
            if ($until && $until->isFuture()) {
                return [
                    'allowed' => true,
                    'reason' => ucfirst($type) . " access until {$until->format('Y-m-d')}.",
                    'override' => $type,
                ];
            }
            // Override expired — fall through to normal subscription check below
        }

        // 4. Normal subscription check
        if (!$subscription->active) {
            return ['allowed' => false, 'reason' => 'Your subscription is inactive. Contact admin.', 'override' => null];
        }

        if ($subscription->end_date && Carbon::parse($subscription->end_date)->isPast()) {
            return ['allowed' => false, 'reason' => 'Your plan has expired. Contact admin.', 'override' => null];
        }

        if ($subscription->isTrialExpired()) {
            return ['allowed' => false, 'reason' => 'Your trial has expired. Please subscribe.', 'override' => null];
        }

        return ['allowed' => true, 'reason' => 'Active subscription.', 'override' => null];
    }
}
