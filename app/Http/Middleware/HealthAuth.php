<?php

namespace App\Http\Middleware;

use App\Services\HealthAccessService;
use App\Services\HealthModuleService;
use App\Services\HealthScopeService;
use App\Support\HealthPanel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Healthcare ERP panel gate.
 *
 * Modelled on FbrPosAuth so the panel behaves exactly like the others where it
 * should (inactive account, missing company, wrong product, tenant + branch
 * binding) and adds the two boundaries healthcare needs on top:
 *
 *  - the signed-in account must actually hold a healthcare role, and
 *  - the path it asked for must be covered by a capability that role holds,
 *    with the capability's module switched on.
 *
 * Doing the capability check HERE rather than only on individual routes means a
 * forgotten middleware argument on a future route cannot silently open a
 * medical or financial screen to everyone.
 */
class HealthAuth
{
    public function handle(Request $request, Closure $next)
    {
        $guard = HealthPanel::GUARD;

        if (!Auth::guard($guard)->check()) {
            return $this->toLogin();
        }

        $user = Auth::guard($guard)->user();

        if (!$user->is_active) {
            Auth::guard($guard)->logout();
            return $this->toLogin()->with('error', __('health.auth_deactivated'));
        }

        if (!$user->company_id) {
            Auth::guard($guard)->logout();
            return $this->toLogin()->with('error', __('health.auth_no_company'));
        }

        $company = \App\Models\Company::find($user->company_id);
        if (!$company) {
            Auth::guard($guard)->logout();
            return $this->toLogin()->with('error', __('health.auth_company_missing'));
        }

        // Product isolation, both ways: a healthcare panel only ever serves a
        // healthcare company, and a POS / DI company can never reach it.
        if (($company->product_type ?? null) !== HealthPanel::PRODUCT_TYPE) {
            Auth::guard($guard)->logout();
            return $this->toLogin()->with('error', __('health.auth_not_healthcare'));
        }

        // The account must hold a healthcare role. A user row that belongs to a
        // healthcare company but was never given a role is NOT staff yet.
        $role = HealthAccessService::roleFor($user);
        if ($role === null) {
            Auth::guard($guard)->logout();
            return $this->toLogin()->with('error', __('health.auth_no_role'));
        }

        app()->instance('currentCompanyId', $user->company_id);

        // Active branch comes from the shared platform service — healthcare
        // never keeps its own idea of "which branch am I in".
        // NOTE: bind() not instance() — instance(name, null) reads as unbound.
        $branchId = app(\App\Services\BranchContextService::class)->getActiveBranchId();
        app()->bind('currentBranchId', fn () => $branchId);
        view()->share('currentBranchId', $branchId);
        view()->share('currentBranch', $branchId ? \App\Models\Branch::find($branchId) : null);

        // Panel-wide context every healthcare view may rely on.
        view()->share('healthCompany', $company);
        view()->share('healthUser', $user);
        view()->share('healthRole', $role);
        view()->share('healthModules', HealthModuleService::enabled($company));
        view()->share('healthCapabilities', HealthAccessService::capabilitiesFor($user, $company));
        view()->share('healthDepartmentIds', HealthScopeService::departmentIdsFor($user));

        // Path-level capability enforcement (see PATH_MAP). An unmapped path
        // needs no capability — dashboard, profile, language, logout.
        $required = HealthAccessService::capabilityForPath($request->path());
        if ($required !== null && !HealthAccessService::canAny($user, $required, $company)) {
            // Report the FIRST alternative: it is the primary reason a person
            // would be here, so the refusal message names the right module.
            return $this->deny($request, explode('|', $required)[0]);
        }

        return $next($request);
    }

    /**
     * Refuse honestly, and say WHICH of the two reasons applies: a module the
     * organisation never switched on reads very differently to "your role does
     * not cover this". Guessing wrong sends the owner hunting in the wrong place.
     */
    private function deny(Request $request, string $capability)
    {
        $module = HealthModuleService::moduleForCapability($capability);
        $company = \App\Models\Company::find(app('currentCompanyId'));
        $moduleOff = $module !== null && !HealthModuleService::isEnabled($company, $module);

        $message = $moduleOff
            ? __('health.denied_module_off', ['module' => __(HealthModuleService::moduleLabelKey($module))])
            : __('health.denied_no_permission');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        abort(403, $message);
    }

    /**
     * PATH-RELATIVE redirect on purpose: the live app forces HTTPS URLs, but
     * the development preview reaches Laravel over a local HTTP bridge, so an
     * absolute forced-HTTPS Location never arrives. Same rule as FbrPosAuth.
     */
    private function toLogin(): \Illuminate\Http\RedirectResponse
    {
        return redirect()->away(HealthPanel::LOGIN_PATH);
    }
}
