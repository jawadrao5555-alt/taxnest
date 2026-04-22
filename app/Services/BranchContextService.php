<?php

namespace App\Services;

use App\Models\Branch;
use Illuminate\Support\Facades\Auth;

/**
 * BranchContextService — single source of truth for the active branch.
 *
 * Active branch is stored in session under `active_branch_id` and resolved
 * lazily by middleware (PosAuth / FbrPosAuth / CompanyIsolation).
 *
 * Role hierarchy for branch access:
 *   - owner / company_admin / super_admin → all branches in their company
 *   - manager → only branches in their `branch_user` pivot (multi-branch allowed)
 *   - cashier / employee → single branch (default_branch_id, locked)
 */
class BranchContextService
{
    public const SESSION_KEY = 'active_branch_id';

    /**
     * Resolve the active branch ID for the current request/session.
     * Returns null if user has no branches yet (system still works company-wide).
     */
    public function getActiveBranchId(): ?int
    {
        $sessionId = session(self::SESSION_KEY);
        if ($sessionId) {
            // Validate it's still accessible
            if ($this->canAccess((int) $sessionId)) {
                return (int) $sessionId;
            }
            session()->forget(self::SESSION_KEY);
        }

        // Auto-assign: pick user's default, else head office, else first active
        $user = $this->currentUser();
        if (!$user) return null;

        $branchId = $this->autoSelectBranch($user);
        if ($branchId) {
            $this->setActiveBranch($branchId);
        }
        return $branchId;
    }

    public function setActiveBranch(int $branchId): bool
    {
        if (!$this->canAccess($branchId)) {
            return false;
        }
        session([self::SESSION_KEY => $branchId]);
        app()->instance('currentBranchId', $branchId);
        return true;
    }

    /**
     * Branches the current user is allowed to switch to.
     * Returns Collection<Branch>.
     */
    public function accessibleBranches()
    {
        $user = $this->currentUser();
        if (!$user || !$user->company_id) return collect();

        $role = $this->effectiveRole($user);

        // Owner / admin → all branches in company
        if (in_array($role, ['owner', 'company_admin', 'super_admin'])) {
            return Branch::where('company_id', $user->company_id)
                ->where(function ($q) {
                    $q->where('is_active', true)->orWhereNull('is_active');
                })
                ->orderByDesc('is_head_office')
                ->orderBy('name')
                ->get();
        }

        // Manager → pivoted branches (or fall back to default if pivot empty)
        if ($role === 'manager') {
            $pivotIds = \DB::table('branch_user')->where('user_id', $user->id)->pluck('branch_id')->all();
            if (empty($pivotIds) && $user->default_branch_id) {
                $pivotIds = [$user->default_branch_id];
            }
            return Branch::where('company_id', $user->company_id)
                ->whereIn('id', $pivotIds)
                ->where(function ($q) {
                    $q->where('is_active', true)->orWhereNull('is_active');
                })
                ->orderBy('name')
                ->get();
        }

        // Cashier / employee → only their default branch
        if ($user->default_branch_id) {
            return Branch::where('company_id', $user->company_id)
                ->where('id', $user->default_branch_id)
                ->get();
        }

        // Fallback: no specific branch — return all (will pick head office)
        return Branch::where('company_id', $user->company_id)
            ->where(function ($q) {
                $q->where('is_active', true)->orWhereNull('is_active');
            })
            ->limit(1)
            ->get();
    }

    public function canAccess(int $branchId): bool
    {
        return $this->accessibleBranches()->contains('id', $branchId);
    }

    public function canSwitch(): bool
    {
        return $this->accessibleBranches()->count() > 1;
    }

    /**
     * Apply branch filter to a query builder (Eloquent or DB).
     * Honors the active branch and falls back to no filter when null.
     * Use ->where('company_id', ...) BEFORE calling this.
     */
    public function applyToQuery($query, string $column = 'branch_id')
    {
        $branchId = $this->getActiveBranchId();
        if ($branchId) {
            $query->where(function ($q) use ($branchId, $column) {
                // Include rows for the active branch + legacy NULL rows
                // (so existing data without branch_id remains visible until backfilled)
                $q->where($column, $branchId)->orWhereNull($column);
            });
        }
        return $query;
    }

    /**
     * Effective role — checks both `role` and `pos_role` columns.
     */
    public function effectiveRole($user): string
    {
        return $user->pos_role ?: ($user->role ?: 'employee');
    }

    public function isOwner($user = null): bool
    {
        $user = $user ?: $this->currentUser();
        return $user && in_array($this->effectiveRole($user), ['owner', 'company_admin', 'super_admin']);
    }

    public function isManager($user = null): bool
    {
        $user = $user ?: $this->currentUser();
        return $user && $this->effectiveRole($user) === 'manager';
    }

    public function isCashier($user = null): bool
    {
        $user = $user ?: $this->currentUser();
        return $user && in_array($this->effectiveRole($user), ['cashier', 'employee']);
    }

    private function currentUser()
    {
        foreach (['fbrpos', 'pos', 'web'] as $guard) {
            $user = Auth::guard($guard)->user();
            if ($user) return $user;
        }
        return null;
    }

    private function autoSelectBranch($user): ?int
    {
        if ($user->default_branch_id) {
            $exists = Branch::where('company_id', $user->company_id)
                ->where('id', $user->default_branch_id)
                ->exists();
            if ($exists) return (int) $user->default_branch_id;
        }

        // Head office for company
        $head = Branch::where('company_id', $user->company_id)
            ->where('is_head_office', true)
            ->first();
        if ($head) return (int) $head->id;

        // First active branch
        $first = Branch::where('company_id', $user->company_id)
            ->where(function ($q) { $q->where('is_active', true)->orWhereNull('is_active'); })
            ->orderBy('id')
            ->first();
        return $first ? (int) $first->id : null;
    }
}
