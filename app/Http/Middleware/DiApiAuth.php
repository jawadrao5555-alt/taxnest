<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * Task 1231: stateless Bearer-key auth for the DI invoice push API.
 *
 * Key format: dik_{companyId}_{random}. Only the SHA-256 hash is stored
 * (companies.di_api_key_hash) — same machine-token convention as the rider
 * app tokens. The company id inside the key gives O(1) lookup; the hash
 * comparison (hash_equals) proves possession of the full key.
 *
 * Rejects suspended / rejected / pending companies on every call, and binds
 * currentCompanyId so CompanyScope + shared services behave exactly as they
 * do for panel requests.
 */
class DiApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->bearerToken();

        if ($key === '') {
            return response()->json([
                'status' => 'error',
                'error' => 'missing_api_key',
                'message' => 'Provide your API key in the Authorization header: Bearer dik_...',
            ], 401);
        }

        $company = null;
        if (preg_match('/^dik_(\d+)_/', $key, $m)) {
            $company = Company::find((int) $m[1]);
        }

        if (!$company
            || empty($company->di_api_key_hash)
            || !hash_equals($company->di_api_key_hash, hash('sha256', $key))) {
            return response()->json([
                'status' => 'error',
                'error' => 'invalid_api_key',
                'message' => 'API key is invalid or has been revoked.',
            ], 401);
        }

        // Company standing — dual status columns both live (status + company_status).
        if ($company->status === 'suspended' || $company->company_status === 'suspended') {
            return response()->json([
                'status' => 'error',
                'error' => 'company_suspended',
                'message' => 'This company account is suspended. Contact TaxNest support.',
            ], 403);
        }
        if ($company->status === 'rejected') {
            return response()->json([
                'status' => 'error',
                'error' => 'company_rejected',
                'message' => 'This company registration was rejected.',
            ], 403);
        }
        if ($company->status === 'pending' || $company->company_status === 'pending') {
            return response()->json([
                'status' => 'error',
                'error' => 'company_pending_approval',
                'message' => 'This company is pending admin approval. API access is enabled after approval.',
            ], 403);
        }

        // Same tenant context the panel gets — CompanyScope, PlanLimitService,
        // AuditLogService etc. all read this binding.
        app()->instance('currentCompanyId', $company->id);
        $request->attributes->set('di_api_company', $company);

        // last-used telemetry, throttled to once/minute; raw DB update so we
        // don't churn companies.updated_at or fire model events per API call.
        $lastUsed = $company->di_api_key_last_used_at
            ? \Illuminate\Support\Carbon::parse($company->di_api_key_last_used_at)
            : null;
        if (!$lastUsed || $lastUsed->lt(now()->subMinute())) {
            try {
                DB::table('companies')->where('id', $company->id)
                    ->update(['di_api_key_last_used_at' => now()]);
            } catch (\Throwable $e) {
                // column missing pre-migration — never block the call
            }
        }

        return $next($request);
    }
}
