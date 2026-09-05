<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\HealthDepartment;
use App\Services\HealthAccessService;
use App\Services\HealthModuleService;
use App\Services\HealthPlatformService;
use App\Services\HealthScopeService;
use App\Support\HealthPanel;
use App\Support\PosLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Healthcare ERP panel shell: the dashboard every role lands on, the owner's
 * module switchboard, and the small per-user preferences the panel shares with
 * the rest of the platform.
 *
 * No clinical, pharmacy, inpatient, accounting or HR behaviour lives here —
 * those are separate modules. What lives here is the frame they will hang on.
 */
class HealthController extends Controller
{
    private function user()
    {
        return Auth::guard(HealthPanel::GUARD)->user();
    }

    private function company(): ?Company
    {
        return Company::find(app('currentCompanyId'));
    }

    /**
     * Public product page for /healthcare.
     *
     * Guest route on purpose: it is where a signed-out visitor (and the logout
     * redirect) lands. Packages come from the sellability service, never a raw
     * product_type query — a retired package must disappear from the public
     * page the moment it stops being sellable.
     */
    public function landing()
    {
        return view('health.landing', [
            'plans' => HealthPlatformService::sellablePlans(),
            'modules' => HealthModuleService::MODULES,
            'moduleMeta' => HealthModuleService::MODULE_META,
        ]);
    }

    /**
     * Dashboard shell.
     *
     * Deliberately counts only what the FOUNDATION owns — branches,
     * departments, staff — plus the organisation's own configuration state.
     * It does not invent patient or revenue tiles for data that does not exist
     * yet; each module adds its own tile when it ships.
     */
    public function dashboard()
    {
        $company = $this->company();
        $user = $this->user();

        $departmentQuery = HealthDepartment::query()->where('is_active', true);
        HealthScopeService::applyBranchScope($departmentQuery, $user);
        $departmentIds = HealthScopeService::departmentIdsFor($user);
        if ($departmentIds !== null) {
            $departmentQuery->whereIn('id', $departmentIds ?: [0]);
        }

        $departments = $departmentQuery->orderBy('name')->get();

        $branches = HealthPlatformService::accessibleBranches();

        $staffCount = 0;
        if (HealthAccessService::can($user, 'staff.manage', $company)) {
            $staffCount = HealthPlatformService::staff($company)->count();
        }

        $enabled = HealthModuleService::enabled($company);

        return view('health.dashboard', [
            'opdToday' => $this->opdToday($user, $company, $enabled),
            'company' => $company,
            'orgType' => HealthPlatformService::orgType($company),
            'enabledModules' => $enabled,
            'planModules' => HealthModuleService::planModules($company),
            'departments' => $departments,
            'branches' => $branches,
            'staffCount' => $staffCount,
            'plan' => HealthPlatformService::activePlan($company),
            'notifications' => HealthPlatformService::notifications($company),
            'fbrReadiness' => HealthPlatformService::fbrReadiness($company),
            'setupComplete' => (bool) ($company->health_setup_completed ?? false),
        ]);
    }

    /**
     * Today's OPD pulse for the dashboard tiles.
     *
     * Deliberately cheap (four counters, no rows) and deliberately silent when
     * the module is off or the signed-in person has no business seeing the
     * desk: the dashboard must never become a side channel that leaks patient
     * volume to a role that cannot open a single patient file.
     */
    private function opdToday($user, ?Company $company, array $enabled): ?array
    {
        if (!in_array('opd', $enabled, true) || !$company) {
            return null;
        }

        $maySee = HealthAccessService::can($user, 'appointments.manage', $company)
            || HealthAccessService::can($user, 'clinical.view', $company)
            || HealthAccessService::can($user, 'reports.view', $company);

        if (!$maySee || !Schema::hasTable('health_appointments')) {
            return null;
        }

        $today = now()->toDateString();

        $base = fn () => HealthScopeService::apply(
            \App\Models\HealthAppointment::query()->whereDate('appointment_date', $today),
            $user
        );

        return [
            'total' => (clone $base())->count(),
            'waiting' => (clone $base())->whereIn('status', [
                \App\Models\HealthAppointment::STATUS_BOOKED,
                \App\Models\HealthAppointment::STATUS_CHECKED_IN,
            ])->count(),
            'in_consultation' => (clone $base())
                ->where('status', \App\Models\HealthAppointment::STATUS_IN_CONSULTATION)->count(),
            'completed' => (clone $base())
                ->where('status', \App\Models\HealthAppointment::STATUS_COMPLETED)->count(),
        ];
    }

    /**
     * Owner's module switchboard.
     *
     * Shows every module the product has, marks which ones the organisation's
     * package sells, and lets the owner switch on only the ones they use. A
     * module the package does not sell is shown as locked rather than hidden —
     * the owner should be able to see what upgrading would give them, and this
     * is the ONE screen where naming an unavailable feature is honest.
     */
    public function modules()
    {
        $company = $this->company();

        return view('health.modules', [
            'company' => $company,
            'orgType' => HealthPlatformService::orgType($company),
            'allModules' => HealthModuleService::MODULES,
            'planModules' => HealthModuleService::planModules($company),
            'enabledModules' => HealthModuleService::enabled($company),
            'moduleMeta' => HealthModuleService::MODULE_META,
            'plan' => HealthPlatformService::activePlan($company),
        ]);
    }

    public function updateModules(Request $request)
    {
        $company = $this->company();

        $request->validate([
            'modules' => 'nullable|array',
            'modules.*' => 'string|in:' . implode(',', HealthModuleService::MODULES),
            'org_type' => 'nullable|in:' . implode(',', HealthPanel::ORG_TYPES),
        ]);

        if ($request->filled('org_type') && Schema::hasColumn('companies', 'health_org_type')) {
            $company->health_org_type = HealthPanel::normalizeOrgType($request->input('org_type'));
            $company->save();
        }

        $saved = HealthModuleService::setForCompany($company, $request->input('modules', []));

        // Honest confirmation: say what was actually stored, not what was posted.
        // A module the package does not sell is silently dropped by the service,
        // and the owner must not be told it is on.
        $requested = HealthModuleService::normalize($request->input('modules', []));
        $refused = array_values(array_diff($requested, $saved));

        $message = __('health.modules_saved', ['count' => count($saved)]);
        if (!empty($refused)) {
            $names = implode(', ', array_map(
                fn (string $key) => __(HealthModuleService::moduleLabelKey($key)),
                $refused
            ));
            $message .= ' ' . __('health.modules_refused', ['modules' => $names]);
        }

        return redirect()->route('health.settings.modules')->with('success', $message);
    }

    /** Settings hub — the panel's own configuration landing page. */
    public function settings()
    {
        $company = $this->company();
        $user = $this->user();

        return view('health.settings', [
            'company' => $company,
            'orgType' => HealthPlatformService::orgType($company),
            'enabledModules' => HealthModuleService::enabled($company),
            'isOwner' => HealthAccessService::isOwner($user),
            'plan' => HealthPlatformService::activePlan($company),
            'departmentCount' => HealthDepartment::query()->count(),
            'departmentLimit' => HealthPlatformService::departmentLimit($company),
            'fbrReadiness' => HealthPlatformService::fbrReadiness($company),
        ]);
    }

    /** Per-user language, shared with every other panel's three locales. */
    public function setLanguage(Request $request)
    {
        $request->validate(['language' => 'required|string']);
        $language = PosLocale::normalize($request->input('language'));

        $user = $this->user();
        if ($user && Schema::hasColumn('users', 'language')) {
            $user->language = $language;
            $user->save();
        }
        $request->session()->put(PosLocale::SESSION_KEY, $language);

        return back();
    }

    /** Guest-side language choice on login / register. */
    public function guestLanguage(Request $request)
    {
        $language = $request->input('language');
        if (PosLocale::isValid($language)) {
            $request->session()->put(PosLocale::SESSION_KEY, $language);
        }

        return back();
    }

    /** Per-user display preference (same users.dark_mode column as POS). */
    public function toggleDarkMode(Request $request)
    {
        $user = $this->user();
        if ($user && Schema::hasColumn('users', 'dark_mode')) {
            $user->dark_mode = $request->boolean('dark_mode');
            $user->save();
        }

        return response()->json(['ok' => true, 'dark_mode' => (bool) ($user->dark_mode ?? false)]);
    }
}
