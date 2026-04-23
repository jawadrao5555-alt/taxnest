<?php

namespace App\Http\Middleware;

use App\Services\BranchContextService;
use Closure;
use Illuminate\Http\Request;

/**
 * Resolves and binds the active branch ID into the container.
 * Place AFTER auth middleware (PosAuth / FbrPosAuth / CompanyIsolation).
 *
 * Usage in any controller:
 *   $branchId = app('currentBranchId');  // int|null
 *
 * If a query needs branch filtering:
 *   app(BranchContextService::class)->applyToQuery($query);
 */
class SetBranchContext
{
    public function handle(Request $request, Closure $next)
    {
        $svc = app(BranchContextService::class);
        $branchId = $svc->getActiveBranchId();
        // bind() not instance() — instance(name, null) is treated as "not bound".
        app()->bind('currentBranchId', fn() => $branchId);
        // Also expose to all views
        view()->share('currentBranchId', $branchId);
        view()->share('currentBranch', $branchId ? \App\Models\Branch::find($branchId) : null);

        return $next($request);
    }
}
