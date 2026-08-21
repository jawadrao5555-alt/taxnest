<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\PlanLimitService;
use App\Services\SubscriptionAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * PRA POS branch management (multi-branch v1, Aug 2026 — Task 1347).
 *
 * Straight port of FbrPosBranchController into the PRA POS panel: the branch
 * INFRASTRUCTURE is product-wide already (branches table, BranchContextService,
 * the switcher in pos-app.blade.php, branch_id stamping on pos_transactions via
 * PosAuth) — the /pos panel was simply missing the management page, so a PRA
 * shop could never create the branches its plan promises.
 *
 * Quota = plan branch_limit via PlanLimitService::canAddBranch (NULL/-1 =
 * unlimited; internal accounts + admin branch_limit_override bypass). No
 * delete — a branch with billing history must survive; deactivate instead.
 */
class PosBranchController extends Controller
{
    private function user() { return Auth::guard('pos')->user(); }

    private function companyId(): int { return (int) app('currentCompanyId'); }

    /** Branch management is owner/admin only (managers/cashiers never reach it). */
    private function adminOnly(): void
    {
        $u = $this->user();
        if (!$u || !($u->role === 'company_admin' || ($u->pos_role ?? '') === 'pos_admin')) {
            abort(403, __('pos.access_denied'));
        }
    }

    public function index()
    {
        $this->adminOnly();
        $branches = Branch::where('company_id', $this->companyId())
            ->orderByDesc('is_head_office')->orderBy('name')->get();
        $quota = PlanLimitService::canAddBranch($this->companyId());
        return view('pos.branches', compact('branches', 'quota'));
    }

    public function store(Request $r)
    {
        $this->adminOnly();
        $r->validate([
            'name' => 'required|string|max:100',
            'city' => 'nullable|string|max:100',
        ]);

        // Package limit (branch_limit + admin override) — the upgrade reason is
        // run through the shared localizer so the message follows the panel language.
        $check = PlanLimitService::canAddBranch($this->companyId());
        if (!($check['allowed'] ?? true)) {
            return back()->with('error', SubscriptionAccessService::localizedLockReason(
                $check['reason'] ?? __('pos.plan_locked_feature')
            ));
        }

        Branch::create([
            'company_id' => $this->companyId(),
            'name' => $r->name,
            'city' => $r->city,
            'is_active' => true,
            // First branch of the company becomes head office (main shop).
            'is_head_office' => !Branch::where('company_id', $this->companyId())->exists(),
        ]);

        // Per-branch stock (Task 1354): everything the shop owned before it had
        // branches is branch-less. The moment the FIRST branch exists that stock
        // must land in head office, otherwise the whole inventory would read as
        // zero on every branch-scoped screen.
        \App\Services\BranchStockService::adoptLegacyRows($this->companyId());

        return back()->with('success', __('pos.branch_added'));
    }

    public function update(Request $r, int $id)
    {
        $this->adminOnly();
        $branch = Branch::where('company_id', $this->companyId())->findOrFail($id);
        $r->validate([
            'name' => 'required|string|max:100',
            'city' => 'nullable|string|max:100',
        ]);
        $branch->update(['name' => $r->name, 'city' => $r->city]);
        return back()->with('success', __('pos.branch_updated'));
    }

    public function toggle(int $id)
    {
        $this->adminOnly();
        $branch = Branch::where('company_id', $this->companyId())->findOrFail($id);
        $branch->update(['is_active' => !$branch->is_active]);
        return back()->with('success', __('pos.branch_updated'));
    }
}
