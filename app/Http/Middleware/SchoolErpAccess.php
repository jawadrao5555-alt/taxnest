<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\TwoStepLogin;
use App\Support\SchoolErpPanel;
use Closure;
use Illuminate\Http\Request;

final class SchoolErpAccess
{
    public function handle(Request $request, Closure $next)
    {
        $guard = auth(SchoolErpPanel::GUARD);
        $user = $guard->user()?->fresh();
        $company = $user?->company_id ? Company::find($user->company_id) : null;

        if (!$user || !SchoolErpPanel::maySignIn($user) || !SchoolErpPanel::ownsCompany($company)) {
            $guard->logoutCurrentDevice();

            return redirect()->away(SchoolErpPanel::LOGIN_PATH);
        }

        abort_if(in_array($company->status, ['suspended', 'rejected'], true)
            || in_array($company->company_status, ['suspended', 'rejected', 'deleted'], true), 403);
        if ($challenge = TwoStepLogin::challenge($request, SchoolErpPanel::GUARD)) {
            return $challenge;
        }

        app()->instance('currentCompanyId', $company->id);
        $request->attributes->set('schoolErpCompany', $company);
        $request->attributes->set('schoolErpUser', $user);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
