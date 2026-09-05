<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\HealthDepartment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branch AND department boundaries for the Nest ERPS Healthcare panel.
 *
 * Branch scoping deliberately reuses the platform's existing
 * BranchContextService (active-branch session, branch_user pivot, owner "all
 * branches" switch) rather than growing a second, healthcare-only notion of
 * "which branch am I in" that would drift from the rest of the product.
 *
 * Departments are the healthcare-only half: a nurse posted to Ward B must not
 * see Ward A even inside the same branch. Both helpers return NULL to mean
 * "no restriction" — callers must treat NULL and [] as opposites (an empty
 * array means the person is posted nowhere and must see nothing).
 */
class HealthScopeService
{
    /** Per-request memo: user_id => branch id list (or null). */
    protected static array $branchCache = [];

    /** Per-request memo: user_id => department id list (or null). */
    protected static array $departmentCache = [];

    /**
     * Branch ids this person may work in, or NULL for "every branch".
     *
     * Owner and administrator see the whole organisation. Everyone else is
     * limited to the branches they are actually attached to; a member attached
     * to no branch at all falls back to the head office so a half-finished
     * staff record does not produce a blank panel.
     */
    public static function branchIdsFor(?User $user): ?array
    {
        if (!$user) {
            return [];
        }

        if (HealthAccessService::isAdministrative($user)) {
            return null;
        }

        $key = (int) $user->id;
        if (array_key_exists($key, self::$branchCache)) {
            return self::$branchCache[$key];
        }

        $ids = [];
        try {
            if (Schema::hasTable('branch_user')) {
                $ids = DB::table('branch_user')
                    ->where('user_id', $user->id)
                    ->pluck('branch_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }

            if (empty($ids) && Schema::hasTable('branches')) {
                $fallback = Branch::withoutGlobalScopes()
                    ->where('company_id', $user->company_id)
                    ->where('is_active', true)
                    ->orderByDesc('is_head_office')
                    ->orderBy('id')
                    ->value('id');
                if ($fallback) {
                    $ids = [(int) $fallback];
                }
            }
        } catch (\Throwable $e) {
            $ids = [];
        }

        return self::$branchCache[$key] = array_values(array_unique($ids));
    }

    /**
     * Department ids this person may work in, or NULL for "every department".
     *
     * The primary posting (users.health_department_id) is unioned with the
     * pivot, so a doctor covering three departments needs one row per extra
     * department and nothing else. A member with no posting at all is scoped to
     * the organisation-wide departments (branch_id NULL) — never to everything.
     */
    public static function departmentIdsFor(?User $user): ?array
    {
        if (!$user) {
            return [];
        }

        if (HealthAccessService::isAdministrative($user)) {
            return null;
        }

        $key = (int) $user->id;
        if (array_key_exists($key, self::$departmentCache)) {
            return self::$departmentCache[$key];
        }

        $ids = [];
        try {
            if (Schema::hasColumn('users', 'health_department_id')) {
                $primary = $user->getAttributeValue('health_department_id');
                if ($primary) {
                    $ids[] = (int) $primary;
                }
            }

            if (Schema::hasTable('health_department_user')) {
                $extra = DB::table('health_department_user')
                    ->where('user_id', $user->id)
                    ->pluck('health_department_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $ids = array_merge($ids, $extra);
            }

            if (empty($ids) && Schema::hasTable('health_departments')) {
                $ids = HealthDepartment::withoutGlobalScopes()
                    ->where('company_id', $user->company_id)
                    ->whereNull('branch_id')
                    ->where('is_active', true)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }
        } catch (\Throwable $e) {
            $ids = [];
        }

        return self::$departmentCache[$key] = array_values(array_unique($ids));
    }

    /**
     * Restrict a query to the branches this person may see.
     *
     * Rows with a NULL branch_id are ALWAYS included: on this platform a NULL
     * branch means "recorded before the organisation split into branches", and
     * hiding those would make a shop's own history disappear the day it adds a
     * second branch. Same rule BranchContextService already applies.
     */
    public static function applyBranchScope($query, ?User $user, string $column = 'branch_id')
    {
        $ids = self::branchIdsFor($user);
        if ($ids === null) {
            return $query;
        }

        $table = $query->getModel()->getTable();
        $qualified = str_contains($column, '.') ? $column : $table . '.' . $column;

        if (empty($ids)) {
            return $query->whereNull($qualified);
        }

        return $query->where(function ($q) use ($qualified, $ids) {
            $q->whereIn($qualified, $ids)->orWhereNull($qualified);
        });
    }

    /**
     * Restrict a query to the departments this person is posted to.
     *
     * Unlike branches, a NULL department is NOT auto-included: a healthcare
     * record with no department is an organisation-wide record and is included,
     * but only because the caller's own department list already contains the
     * organisation-wide departments. Filed-nowhere rows stay visible so nothing
     * becomes unreachable, matching the branch rule.
     */
    public static function applyDepartmentScope($query, ?User $user, string $column = 'health_department_id')
    {
        $ids = self::departmentIdsFor($user);
        if ($ids === null) {
            return $query;
        }

        $table = $query->getModel()->getTable();
        $qualified = str_contains($column, '.') ? $column : $table . '.' . $column;

        if (empty($ids)) {
            return $query->whereNull($qualified);
        }

        return $query->where(function ($q) use ($qualified, $ids) {
            $q->whereIn($qualified, $ids)->orWhereNull($qualified);
        });
    }

    /** Both boundaries at once — what a healthcare list screen should call. */
    public static function apply($query, ?User $user, string $branchColumn = 'branch_id', string $departmentColumn = 'health_department_id')
    {
        $query = self::applyBranchScope($query, $user, $branchColumn);

        return self::applyDepartmentScope($query, $user, $departmentColumn);
    }

    public static function canAccessBranch(?User $user, $branchId): bool
    {
        if (!$branchId) {
            return true;
        }

        $ids = self::branchIdsFor($user);

        return $ids === null || in_array((int) $branchId, $ids, true);
    }

    public static function canAccessDepartment(?User $user, $departmentId): bool
    {
        if (!$departmentId) {
            return true;
        }

        $ids = self::departmentIdsFor($user);

        return $ids === null || in_array((int) $departmentId, $ids, true);
    }

    /** Departments this person may pick from on a form. */
    public static function selectableDepartments(?User $user)
    {
        if (!$user) {
            return collect();
        }

        try {
            if (!Schema::hasTable('health_departments')) {
                return collect();
            }

            $query = HealthDepartment::withoutGlobalScopes()
                ->where('company_id', $user->company_id)
                ->where('is_active', true)
                ->orderBy('name');

            $ids = self::departmentIdsFor($user);
            if ($ids !== null) {
                $query->whereIn('id', $ids ?: [0]);
            }

            $branchIds = self::branchIdsFor($user);
            if ($branchIds !== null) {
                $query->where(function ($q) use ($branchIds) {
                    $q->whereNull('branch_id');
                    if (!empty($branchIds)) {
                        $q->orWhereIn('branch_id', $branchIds);
                    }
                });
            }

            return $query->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    public static function forget(?int $userId = null): void
    {
        if ($userId === null) {
            self::$branchCache = [];
            self::$departmentCache = [];

            return;
        }

        unset(self::$branchCache[$userId], self::$departmentCache[$userId]);
    }
}
