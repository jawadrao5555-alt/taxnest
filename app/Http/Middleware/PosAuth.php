<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PosAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::guard('pos')->check()) {
            return redirect('/pos/login');
        }

        $user = Auth::guard('pos')->user();

        if (!$user->is_active) {
            Auth::guard('pos')->logout();
            return redirect('/pos/login')->with('error', 'Your account has been deactivated.');
        }

        if (!$user->company_id) {
            Auth::guard('pos')->logout();
            return redirect('/pos/login')->with('error', 'No company associated with your account.');
        }

        $company = \App\Models\Company::find($user->company_id);
        if (!$company) {
            Auth::guard('pos')->logout();
            return redirect('/pos/login')->with('error', 'Company not found. Please contact admin.');
        }

        if ($company->product_type !== 'pos') {
            Auth::guard('pos')->logout();
            if ($company->product_type === 'fbrpos') {
                return redirect('/fbr-pos/login')->with('error', 'This is an FBR POS account. Please login from the FBR POS portal.');
            }
            return redirect('/login')->with('error', 'This account is not registered for NestPOS.');
        }

        app()->instance('currentCompanyId', $user->company_id);

        // ═══ Archive Viewer isolation ═══
        // Users with pos_role='archive_viewer' are confined to /pos/archive/* and
        // /pos/logout. They never see normal POS pages — any other /pos/* URL
        // is redirected back to the archive portal. POS admin/cashier panels never
        // expose this role (Team page filters it out).
        if (($user->pos_role ?? null) === 'archive_viewer') {
            $path = ltrim($request->path(), '/');
            $allowed = str_starts_with($path, 'pos/archive')
                || $path === 'pos/logout'
                || $path === 'pos/login';
            if (!$allowed) {
                return redirect('/pos/archive');
            }
        } else {
            // Conversely, non-archive users cannot access archive routes.
            if (str_starts_with(ltrim($request->path(), '/'), 'pos/archive')) {
                abort(404);
            }
        }

        // ═══ Local Bills Viewer isolation ═══
        // Users with pos_role='local_viewer' are confined to /pos/local-bills/* and
        // /pos/logout — the ONLY surface where local (non-PRA) bills are visible.
        // Every other pos_role gets a 404 on these routes.
        if (($user->pos_role ?? null) === 'local_viewer') {
            $path = ltrim($request->path(), '/');
            $allowed = str_starts_with($path, 'pos/local-bills')
                || $path === 'pos/logout'
                || $path === 'pos/login';
            if (!$allowed) {
                return redirect('/pos/local-bills');
            }
        } else {
            if (str_starts_with(ltrim($request->path(), '/'), 'pos/local-bills')) {
                abort(404);
            }
        }

        // Resolve & bind active branch (returns null if no branches exist yet).
        // NOTE: use bind() not instance() — instance(name, null) is treated as "not bound" by Laravel.
        $branchId = app(\App\Services\BranchContextService::class)->getActiveBranchId();
        app()->bind('currentBranchId', fn() => $branchId);
        view()->share('currentBranchId', $branchId);
        view()->share('currentBranch', $branchId ? \App\Models\Branch::find($branchId) : null);

        return $next($request);
    }
}
