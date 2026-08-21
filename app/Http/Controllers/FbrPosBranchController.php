<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\PlanLimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * FBR POS branch management (multi-branch v1, Aug 2026).
 *
 * The branch INFRASTRUCTURE already exists product-wide (branches table,
 * BranchContextService, branch switcher in the FBR layout, branch_id
 * stamping on fbr_pos_transactions via FbrPosAuth). This controller only
 * adds the missing management page inside the FBR panel.
 *
 * Quota = plan branch_limit via PlanLimitService::canAddBranch (NULL/-1 =
 * unlimited; internal accounts + overrides bypass). No delete — branches
 * with history must survive; deactivate instead.
 */
class FbrPosBranchController extends Controller
{
    private function user() { return Auth::guard('fbrpos')->user(); }
    private function companyId(): int { return (int) $this->user()->company_id; }

    /** Branch management is owner/admin only. */
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
        return view('fbr-pos.branches', compact('branches', 'quota'));
    }

    public function store(Request $r)
    {
        $this->adminOnly();
        $r->validate([
            'name' => 'required|string|max:100',
            'city' => 'nullable|string|max:100',
        ]);

        $check = PlanLimitService::canAddBranch($this->companyId());
        if (!($check['allowed'] ?? true)) {
            return back()->with('error', $check['reason'] ?? __('pos.plan_locked_feature'));
        }

        Branch::create([
            'company_id' => $this->companyId(),
            'name' => $r->name,
            'city' => $r->city,
            'is_active' => true,
            // First branch of the company becomes head office (main shop).
            'is_head_office' => !Branch::where('company_id', $this->companyId())->exists(),
        ]);

        // Per-branch stock (Task 1365): everything the shop owned before it had
        // branches is branch-less. The moment the FIRST branch exists that stock
        // must land in head office, otherwise the whole inventory would read as
        // zero on every branch-scoped screen. flushMemo first — the branch list
        // was memoised as EMPTY earlier in this same request.
        \App\Services\BranchStockService::flushMemo();
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
