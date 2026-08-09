<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Company;
use App\Services\PosFeatureService;
use Illuminate\Support\Facades\Auth;

/**
 * FBR POS plan gate (Aug 2026 strict feature binding) — FBR twin of
 * PosController::planGate(). Call at the TOP of a gated entry point:
 *
 *     if ($resp = $this->fbrPlanGate('excel_enabled')) return $resp;
 *
 * Returns a redirect to FBR billing (pages) or aborts 403 (JSON requests)
 * when the company's plan lacks the premium column; returns null when
 * allowed. Internal accounts, active overrides and active trials all pass
 * via PosFeatureService::planAllows() (same hierarchy as PRA POS).
 */
trait FbrPlanGate
{
    protected function fbrPlanGate(string $planColumn)
    {
        $user = Auth::guard('fbrpos')->user();
        if (!$user) {
            // FBR controller convention: null user = direct/CLI/test
            // invocation, allowed (every web route sits behind FbrPosAuth,
            // so real requests always carry a user).
            return null;
        }
        $company = Company::find((int) $user->company_id);
        if (!PosFeatureService::planAllows($company, $planColumn)) {
            if (request()->expectsJson()) {
                abort(403, __('pos.plan_locked_feature'));
            }
            return redirect()->route('fbrpos.billing')->with('error', __('pos.plan_locked_feature'));
        }
        return null;
    }

    /**
     * Boolean twin of fbrPlanGate() for call sites that need their OWN
     * response shape (sale-screen JSON APIs, in-transaction ValidationException
     * branches). Same null-user convention: direct/CLI/test invocation passes.
     */
    protected function fbrPlanAllows(string $planColumn): bool
    {
        $user = Auth::guard('fbrpos')->user();
        if (!$user) {
            return true;
        }
        return PosFeatureService::planAllows(Company::find((int) $user->company_id), $planColumn);
    }
}
