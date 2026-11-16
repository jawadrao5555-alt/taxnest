<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;

/** Local collection workspaces never inherit retail controls through a shared URL. */
class SchoolWorkspace
{
    public function handle(Request $request, Closure $next)
    {
        $companyId = app()->bound('currentCompanyId') ? app('currentCompanyId') : auth('fbrpos')->user()?->company_id;
        $company = $companyId ? Company::find($companyId) : null;
        if (!$company?->isLocalCollectionPilot()) {
            return $next($request);
        }
        $school = $company->isSchoolFeePilot();
        $root = $school ? '/fbr-pos/school-fees' : '/fbr-pos/hospital';
        $message = $school ? 'school_workspace.workspace_only' : 'hospital_workspace.workspace_only';
        $path = trim($request->path(), '/');
        $suffix = substr($path, strlen('fbr-pos/'));
        if (in_array($suffix, ['settings', 'customize', 'business-profile', 'receipt-settings', 'printer-settings', 'branches'], true)) {
            if (!$request->isMethod('get')) {
                abort(422, __($message));
            }
            return redirect()->away($root.'/settings')->header('Cache-Control', 'private, no-store');
        }
        $allowed = ($path === ltrim($root, '/') || str_starts_with($path, ltrim($root, '/').'/'))
            || ($school && in_array($suffix, ['school-fees/reports.csv', 'school-fees/reports.pdf', 'agent', 'agent/download', 'agent/generate', 'agent/regenerate'], true))
            || in_array($suffix, ['dashboard', 'transactions', 'day-close', 'set-language', 'set-dark-mode', 'billing', 'billing/checkout', 'payment-proof', 'my-profile', 'suggestions', 'tutorials', 'team', 'logout'], true)
            || $request->is('fbr-pos/team/*', 'fbr-pos/billing/documents/*')
            // Read-only freshness probes remain available; integration decisions may rewrite legacy invoices.
            || in_array($suffix, ['api/boot-check', 'api/boot-diagnostics'], true);
        if (!$allowed) {
            if ($request->isMethod('get')) {
                return redirect()->away($root)->header('Cache-Control', 'private, no-store');
            }
            abort(422, __($message));
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
