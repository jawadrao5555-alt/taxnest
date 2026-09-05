<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\HealthModuleService;
use Closure;
use Illuminate\Http\Request;

/**
 * Route-level module gate: `health.module:pharmacy`.
 *
 * HealthAuth already refuses a capability whose module is off, but a module can
 * own routes that need no particular capability (a module landing page, a
 * module-scoped export). Those declare the module explicitly instead of relying
 * on a capability mapping that may not exist yet.
 *
 * Several modules may be listed — the route opens when ANY of them is on.
 */
class HealthModuleEnabled
{
    public function handle(Request $request, Closure $next, string ...$modules)
    {
        $company = Company::find(app()->bound('currentCompanyId') ? app('currentCompanyId') : null);

        foreach ($modules as $module) {
            if (HealthModuleService::isEnabled($company, $module)) {
                return $next($request);
            }
        }

        $first = $modules[0] ?? '';
        $label = $first !== '' ? __(HealthModuleService::moduleLabelKey($first)) : '';
        $message = __('health.denied_module_off', ['module' => $label]);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        abort(403, $message);
    }
}
