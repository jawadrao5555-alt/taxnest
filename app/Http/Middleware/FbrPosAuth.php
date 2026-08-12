<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FbrPosAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::guard('fbrpos')->check()) {
            return redirect('/fbr-pos/login');
        }

        $user = Auth::guard('fbrpos')->user();

        if (!$user->is_active) {
            Auth::guard('fbrpos')->logout();
            return redirect('/fbr-pos/login')->with('error', 'Your account has been deactivated.');
        }

        if (!$user->company_id) {
            Auth::guard('fbrpos')->logout();
            return redirect('/fbr-pos/login')->with('error', 'No company associated with your account.');
        }

        $company = \App\Models\Company::find($user->company_id);
        if (!$company) {
            Auth::guard('fbrpos')->logout();
            return redirect('/fbr-pos/login')->with('error', 'Company not found. Please contact admin.');
        }

        if (!$company->fbr_pos_enabled || $company->product_type !== 'fbrpos') {
            Auth::guard('fbrpos')->logout();
            return redirect('/fbr-pos/login')->with('error', 'FBR POS is not enabled for your company.');
        }

        app()->instance('currentCompanyId', $user->company_id);

        // ═══ Online heartbeat (Task 558 — Live Activity) ═══
        // Same throttled "last seen" stamp as PRA POS: max ONE UPDATE per
        // user per 5 minutes on the latest OPEN session row. Failure never
        // blocks a request.
        try {
            $beatKey = 'pos_hazri_beat_' . $user->id;
            if (!cache()->has($beatKey)) {
                cache()->put($beatKey, 1, 300);
                \Illuminate\Support\Facades\DB::table('pos_user_sessions')
                    ->where('user_id', $user->id)
                    ->whereNull('logout_at')
                    ->orderByDesc('id')
                    ->limit(1)
                    ->update(['last_activity_at' => now(), 'updated_at' => now()]);
            }
        } catch (\Throwable $e) {
            // Table not migrated yet — ignore.
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
