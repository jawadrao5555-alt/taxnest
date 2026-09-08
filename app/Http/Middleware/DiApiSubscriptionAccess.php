<?php

namespace App\Http\Middleware;

use App\Services\SubscriptionAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * DI push API — subscription access gate for BILLABLE endpoints only.
 *
 * The panel's invoice create path runs CheckPlanLimit, whose first step is
 * SubscriptionAccessService::hasAccess() (expired plan / ended trial / spent
 * grant → locked) and only then the monthly quota. The API's store() mirrored
 * the quota half but not the access half, so a locked-but-approved company
 * could keep creating and submitting invoices through its API key.
 *
 * Runs AFTER DiApiAuth (needs `di_api_company`). Read-only endpoints (status)
 * deliberately do NOT carry this middleware — a locked ERP can still poll the
 * outcome of invoices it already filed.
 */
class DiApiSubscriptionAccess
{
    public const ERROR_CODE = 'subscription_locked';

    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->attributes->get('di_api_company');
        if (!$company) {
            // DiApiAuth did not run — never grant access on a missing context.
            return response()->json([
                'status' => 'error',
                'error' => 'invalid_api_key',
                'message' => 'API key is invalid or has been revoked.',
            ], 401);
        }

        // FAIL CLOSED — the only pass-through is the same schema-compat guard the
        // POS quick-create gate uses (a box where the subscriptions table has not
        // landed yet cannot evaluate access at all).
        if (!Schema::hasTable('subscriptions')) {
            return $next($request);
        }

        $access = SubscriptionAccessService::hasAccess($company);
        if (!($access['allowed'] ?? false)) {
            return response()->json([
                'status' => 'error',
                'error' => self::ERROR_CODE,
                'message' => SubscriptionAccessService::localizedLockReason((string) ($access['reason'] ?? '')),
            ], 402);
        }

        return $next($request);
    }
}
