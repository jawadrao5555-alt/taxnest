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
     * Owner-only "all branches" sentinel (Task 1347). Stored in the SAME session
     * key; getActiveBranchId() reports NULL for it, which every consumer already
     * treats as "no branch filter" — so company-wide reporting (including legacy
     * pre-branch rows) needs no special-casing anywhere else.
     */
    public const ALL = 'all';

    /**
     * The two isolated read-only portals (Archive + Local Bills). Their logins
     * are provisioned as COMPANY-level audit accounts — no branch is assigned,
     * and PosAuth confines them to their own portal, so there is no other panel
     * from which they could ever change branch.
     *
     * Task 1361 scopes both portals by branch. A branch-less audit login must
     * therefore stay company-wide (see autoSelectBranch) and keep owner-style
     * branch rights (see effectiveRole) — otherwise switching the filter on
     * would silently pin the shop's only archive window to head office. Assign
     * such an account a default branch and it is welded to it, like a cashier.
     */
    public const PORTAL_AUDIT_ROLES = ['archive_viewer', 'local_viewer'];

    /** Per-request memo of accessibleBranches() — the switcher + guards ask repeatedly. */
    private $branchMemo = null;

    /** Per-instance memo of the `branches` table check (see branchesReady()). */
    private ?bool $tableMemo = null;

    /**
     * True when the `branches` table is actually present.
     *
     * Task 1347: branch context is now consulted on hot read paths (dashboard,
     * transactions, every report). Those must NOT explode where the table is
     * absent — lean test schemas that build only the tables they exercise, and
     * (per the known cPanel schema drift) any deployment whose branch migration
     * never landed. Missing table = single-branch behaviour, exactly like a
     * company with no branches. Memoised per instance; never cached statically,
     * so a test that rebuilds its schema is re-checked.
     */
    private function branchesReady(): bool
    {
        if ($this->tableMemo === null) {
            try {
                $this->tableMemo = \Illuminate\Support\Facades\Schema::hasTable('branches');
            } catch (\Throwable $e) {
                $this->tableMemo = false;
            }
        }
        return $this->tableMemo;
    }

    /**
     * Resolve the active branch ID for the current request/session.
     * Returns null if user has no branches yet (system still works company-wide),
     * or when an owner has explicitly selected the "all branches" view.
     */
    public function getActiveBranchId(): ?int
    {
        $sessionId = session(self::SESSION_KEY);
        if ($sessionId === self::ALL) {
            // Only an owner/admin may hold the company-wide view; a demoted
            // account falls back to normal auto-selection.
            if ($this->isOwner()) {
                return null;
            }
            session()->forget(self::SESSION_KEY);
            $sessionId = null;
        }
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

    /** True when the owner has selected the company-wide ("all branches") view. */
    public function isAllBranches(): bool
    {
        return session(self::SESSION_KEY) === self::ALL && $this->isOwner();
    }

    /**
     * Branch a NEW row must be stamped with. Never null while the company has
     * branches — the owner's "all branches" reporting view must not produce
     * branch-less bills, so it falls back to their default / head office.
     */
    public function stampBranchId(): ?int
    {
        $active = $this->getActiveBranchId();
        if ($active) {
            return $active;
        }
        $user = $this->currentUser();
        return $user ? $this->autoSelectBranch($user) : null;
    }

    /** @param int|string $branchId  a branch id, or self::ALL for the owner-only company-wide view */
    public function setActiveBranch($branchId): bool
    {
        if ($branchId === self::ALL) {
            if (!$this->isOwner()) {
                return false;
            }
            session([self::SESSION_KEY => self::ALL]);
            app()->instance('currentBranchId', null);
            return true;
        }
        $branchId = (int) $branchId;
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
        if ($this->branchMemo !== null) {
            return $this->branchMemo;
        }

        $user = $this->currentUser();
        if (!$user || !$user->company_id || !$this->branchesReady()) return collect();

        $role = $this->effectiveRole($user);

        return $this->branchMemo = $this->resolveAccessibleBranches($user, $role);
    }

    private function resolveAccessibleBranches($user, string $role)
    {

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

        // Cashier / employee → only their default branch, and only while that
        // branch is still switched ON. A deactivated branch must stop taking
        // sales (Task 1347) — its staff fall through to the safe fallback
        // below (head office / first active branch) instead of being locked
        // out or quietly billing a closed shop.
        if ($user->default_branch_id) {
            $own = Branch::where('company_id', $user->company_id)
                ->where('id', $user->default_branch_id)
                ->where(function ($q) {
                    $q->where('is_active', true)->orWhereNull('is_active');
                })
                ->get();
            if ($own->isNotEmpty()) {
                return $own;
            }
        }

        // Fallback: no usable default branch — head office, else first active.
        return Branch::where('company_id', $user->company_id)
            ->where(function ($q) {
                $q->where('is_active', true)->orWhereNull('is_active');
            })
            ->orderByDesc('is_head_office')
            ->orderBy('id')
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
     * Effective role — checks both `role` and `pos_role` columns, then NORMALISES
     * the panel role names onto this class's branch hierarchy.
     *
     * Task 1347: without the mapping a POS/FBR owner (role=company_admin,
     * pos_role=pos_admin) resolved to the literal 'pos_admin', matched neither
     * the owner nor the manager branch, and fell through to the cashier
     * fallback — so the switcher only ever offered ONE branch and the whole
     * multi-branch panel looked dead. pos_role wins over role by design (a
     * company_admin acting as a POS cashier stays a cashier).
     */
    public function effectiveRole($user): string
    {
        $role = $user->pos_role ?: ($user->role ?: 'employee');

        // Read-only portal audit logins (Task 1361): company-wide by default —
        // they may look at any branch AND at the company-wide view, because
        // their portal is the only page they can open. Given an explicit branch
        // they drop to the cashier tier and stay welded to it.
        if (in_array($role, self::PORTAL_AUDIT_ROLES, true)) {
            return ($user->default_branch_id ?? null) ? 'cashier' : 'company_admin';
        }

        // Healthcare ERP roles live in their own column (users.health_role) and
        // are mapped onto the branch tiers this service already understands:
        // owner/administrator run the whole organisation, everybody else is
        // limited to the branches they are actually posted to (branch_user),
        // which is the 'manager' tier here. This mirrors
        // HealthAccessService::isAdministrative() — keep the two in step.
        $healthRole = $user->health_role ?? null;
        if ($healthRole) {
            return in_array($healthRole, ['health_owner', 'health_admin'], true)
                ? 'company_admin'
                : 'manager';
        }

        return match ($role) {
            'pos_admin', 'admin' => 'company_admin',
            'pos_manager' => 'manager',
            'pos_cashier', 'pos_kitchen', 'pos_waiter', 'pos_delivery', 'pos_rider' => 'cashier',
            default => $role,
        };
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
        // 'health' joins the list so the Healthcare ERP panel reuses the SAME
        // branch context (active branch, branch_user pivot, owner "all
        // branches") instead of growing a second notion of "which branch am I
        // in". Left out, every healthcare page saw a branch-less company.
        foreach (['fbrpos', 'pos', 'health', 'web'] as $guard) {
            $user = Auth::guard($guard)->user();
            if ($user) return $user;
        }
        return null;
    }

    private function autoSelectBranch($user): ?int
    {
        if (!$this->branchesReady()) {
            return null;
        }

        // Task 1361: a branch-less portal audit login is NOT auto-pinned to head
        // office. Its portal is the only page it can open, so a silent pin would
        // hide the rest of the company's archived / local history behind a
        // switcher nobody told them about. NULL = company-wide, which is exactly
        // what these accounts are provisioned for; they can still narrow down to
        // one branch from the portal's switcher.
        if (!($user->default_branch_id ?? null)
            && in_array($user->pos_role ?? '', self::PORTAL_AUDIT_ROLES, true)) {
            return null;
        }

        // A branch that has been switched OFF is never auto-selected — not even
        // as somebody's saved default (Task 1347): staff of a closed branch move
        // to head office rather than keep billing under it.
        $active = function ($q) { $q->where('is_active', true)->orWhereNull('is_active'); };

        if ($user->default_branch_id) {
            $exists = Branch::where('company_id', $user->company_id)
                ->where('id', $user->default_branch_id)
                ->where($active)
                ->exists();
            if ($exists) return (int) $user->default_branch_id;
        }

        // Head office for company
        $head = Branch::where('company_id', $user->company_id)
            ->where('is_head_office', true)
            ->where($active)
            ->first();
        if ($head) return (int) $head->id;

        // First active branch
        $first = Branch::where('company_id', $user->company_id)
            ->where($active)
            ->orderBy('id')
            ->first();
        return $first ? (int) $first->id : null;
    }
}
