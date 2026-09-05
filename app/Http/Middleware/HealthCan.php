<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\HealthAccessService;
use App\Support\HealthPanel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Route-level capability gate: `health.can:patients.manage`.
 *
 * HealthAuth covers whole path prefixes; this covers the finer distinction
 * inside one prefix — the difference between reading a screen and changing it.
 * Several capabilities may be listed and ANY of them opens the route.
 */
class HealthCan
{
    public function handle(Request $request, Closure $next, string ...$capabilities)
    {
        $user = Auth::guard(HealthPanel::GUARD)->user();
        $company = Company::find(app()->bound('currentCompanyId') ? app('currentCompanyId') : null);

        foreach ($capabilities as $capability) {
            if (HealthAccessService::can($user, $capability, $company)) {
                return $next($request);
            }
        }

        $message = __('health.denied_no_permission');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        abort(403, $message);
    }
}
