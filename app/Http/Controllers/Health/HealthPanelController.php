<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\HealthDoctor;
use App\Models\User;
use App\Services\HealthAccessService;
use App\Services\HealthPlatformService;
use App\Services\HealthScopeService;
use App\Support\HealthPanel;
use Illuminate\Support\Facades\Auth;

/**
 * Shared plumbing for the clinical controllers.
 *
 * Nothing here decides anything — it only makes the four things every OPD
 * screen needs (the signed-in user, their company, their capability check and
 * their branch/department boundary) impossible to spell differently in five
 * different controllers. The moment one screen forgets a boundary, that screen
 * leaks another ward's patients.
 */
abstract class HealthPanelController extends Controller
{
    protected function user(): ?User
    {
        return Auth::guard(HealthPanel::GUARD)->user();
    }

    protected function company(): ?Company
    {
        return Company::find(app()->bound('currentCompanyId') ? app('currentCompanyId') : null);
    }

    protected function can(string $capability): bool
    {
        return HealthAccessService::can($this->user(), $capability, $this->company());
    }

    /** Refuse with the panel's own wording rather than a bare 403 page. */
    protected function require(string $capability): void
    {
        if (!$this->can($capability)) {
            abort(403, __('health.denied_no_permission'));
        }
    }

    /** Branch + department boundary, applied together, on any healthcare query. */
    protected function scope($query, string $branchColumn = 'branch_id', string $departmentColumn = 'health_department_id')
    {
        return HealthScopeService::apply($query, $this->user(), $branchColumn, $departmentColumn);
    }

    /** Branches this person may file a record against. */
    protected function branches()
    {
        return HealthPlatformService::accessibleBranches();
    }

    /**
     * The practitioner profile(s) linked to the signed-in account.
     *
     * A doctor's own queue is derived from this, not from a role check: the
     * role says "you are a doctor", the link says "you are THIS doctor".
     */
    protected function ownDoctorIds(): array
    {
        $user = $this->user();
        if (!$user) {
            return [];
        }

        return HealthDoctor::query()
            ->where('user_id', $user->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Doctors this person may pick on a form — active, inside their branch, and
     * inside their department posting.
     */
    protected function selectableDoctors()
    {
        $query = HealthDoctor::query()->where('is_active', true)->orderBy('name');

        return $this->scope($query)->get();
    }
}
