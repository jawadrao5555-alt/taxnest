<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Company;
use App\Models\HealthMedicine;
use App\Services\BranchStockService;
use App\Services\HealthAccessService;
use App\Services\HealthPharmacyService;
use App\Services\HealthPlatformService;
use App\Services\HealthScopeService;
use App\Support\HealthPanel;
use Illuminate\Support\Facades\Auth;

/**
 * Shared context for every pharmacy screen (Task 1549).
 *
 * The pharmacy spans five controllers; without one place resolving "who, which
 * company, which branch, and may they write", each of them would answer that
 * question slightly differently — which is how a cashier ends up moving another
 * branch's stock.
 */
trait HealthPharmacyContext
{
    protected function user()
    {
        return Auth::guard(HealthPanel::GUARD)->user();
    }

    protected function companyId(): int
    {
        return (int) app('currentCompanyId');
    }

    protected function company(): ?Company
    {
        return Company::find($this->companyId());
    }

    protected function settings()
    {
        return HealthPharmacyService::settings($this->companyId());
    }

    /**
     * Capability re-check inside the controller.
     *
     * The route gate proves the person may open the pharmacy at all; a write
     * needs its own capability, and a POST can always arrive without the screen
     * that hid the button.
     */
    protected function assertCan(string $capability): void
    {
        if (!HealthAccessService::can($this->user(), $capability, $this->company())) {
            abort(403, __('health.denied_no_permission'));
        }
    }

    /**
     * The branch being VIEWED. NULL means "the whole organisation" for a
     * single-branch company, and for a multi-branch owner looking at everything.
     */
    protected function viewBranchId(): ?int
    {
        $companyId = $this->companyId();
        BranchStockService::healLegacyRows($companyId);

        return BranchStockService::viewBranchId($companyId);
    }

    /**
     * The branch stock will actually be WRITTEN to. A multi-branch company must
     * name the shop; guessing head office would silently move the wrong stock.
     */
    protected function writeBranchId(?int $preferred = null): ?int
    {
        $companyId = $this->companyId();
        BranchStockService::healLegacyRows($companyId);

        if ($preferred !== null && !BranchStockService::actorCanUse($companyId, $preferred)) {
            abort(403, __('health.dept_branch_not_yours'));
        }

        return BranchStockService::writeBranchId($companyId, $preferred);
    }

    /**
     * May this person see a record filed under this branch?
     *
     * A record with NO branch predates the split into branches and stays
     * visible to everyone in the company — the same rule the branch scope
     * already applies to lists. A single-branch company has no boundary to
     * enforce at all. Everywhere else the record's own branch must be one this
     * person may work in.
     */
    protected function branchIsVisible(?int $branchId): bool
    {
        if (!$branchId) {
            return true;
        }

        $companyId = $this->companyId();

        if (!BranchStockService::isMultiBranch($companyId)) {
            return true;
        }

        return BranchStockService::actorCanUse($companyId, $branchId);
    }

    /**
     * The same check as a hard stop, for a record fetched BY ID.
     *
     * A list screen is already branch-scoped, but an id in the URL is not: the
     * only thing standing between a pharmacist and another branch's
     * prescription, bill or lot is this call on every read and every write.
     */
    protected function assertBranchVisible(?int $branchId): void
    {
        if (!$this->branchIsVisible($branchId)) {
            abort(403, __('health.dept_branch_not_yours'));
        }
    }

    /** True when the actor is on the "all branches" view and must pick one. */
    protected function mustPickBranch(?int $picked): bool
    {
        return $picked === null
            && BranchStockService::isMultiBranch($this->companyId())
            && BranchStockService::viewingAllBranches($this->companyId());
    }

    protected function branches()
    {
        return HealthPlatformService::accessibleBranches();
    }

    protected function departments()
    {
        return HealthScopeService::selectableDepartments($this->user());
    }

    /** Active catalogue for a picker, ordered the way a pharmacist reads it. */
    protected function medicineOptions()
    {
        return HealthMedicine::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id', 'name', 'generic_name', 'strength', 'form', 'unit_uom',
                'sale_price', 'tax_rate', 'requires_prescription', 'is_controlled',
                'is_narcotic', 'barcode', 'code', 'default_dosage', 'product_id',
            ]);
    }
}
