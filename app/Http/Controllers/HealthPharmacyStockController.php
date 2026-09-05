<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HealthPharmacyContext;
use App\Models\HealthBatchMovement;
use App\Models\HealthMedicine;
use App\Models\HealthMedicineBatch;
use App\Services\BranchStockService;
use App\Services\HealthPharmacyStockService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Batch stock control — expiry, quarantine, write-offs, transfers, counted
 * corrections and the traceability ledger (Task 1549).
 *
 * Nothing here touches a quantity directly: every action goes through
 * HealthPharmacyStockService so the batch remainder, the branch truth and the
 * ledger move together. What this controller owns is authorisation, branch
 * access and the human reason for the movement.
 */
class HealthPharmacyStockController extends Controller
{
    use HealthPharmacyContext;

    public function index(Request $request)
    {
        $companyId = $this->companyId();
        $branchId = $this->viewBranchId();
        $allBranches = BranchStockService::viewingAllBranches($companyId);

        $filter = $request->query('filter', 'all');
        $search = trim((string) $request->query('q', ''));
        $settings = $this->settings();

        $query = HealthMedicineBatch::withoutGlobalScopes()
            ->with(['medicine', 'branch', 'supplier'])
            ->where('company_id', $companyId)
            ->when(!$allBranches, fn ($q) => $q->where('branch_id', $branchId))
            ->when($search !== '', function ($builder) use ($search, $companyId) {
                $builder->where(function ($inner) use ($search, $companyId) {
                    $inner->where('batch_no', 'like', "%{$search}%")
                        ->orWhereIn('medicine_id', HealthMedicine::withoutGlobalScopes()
                            ->where('company_id', $companyId)
                            ->where(function ($m) use ($search) {
                                $m->where('name', 'like', "%{$search}%")
                                    ->orWhere('generic_name', 'like', "%{$search}%");
                            })
                            ->pluck('id'));
                });
            });

        $today = now()->toDateString();

        match ($filter) {
            'near_expiry' => $query->where('status', '!=', HealthMedicineBatch::STATUS_WRITTEN_OFF)
                ->where('quantity', '>', 0)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '>=', $today)
                ->whereDate('expiry_date', '<=', now()->addDays((int) $settings->near_expiry_days)->toDateString()),
            'expired' => $query->where('status', '!=', HealthMedicineBatch::STATUS_WRITTEN_OFF)
                ->where('quantity', '>', 0)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<', $today),
            'quarantined' => $query->where('status', HealthMedicineBatch::STATUS_QUARANTINED),
            'written_off' => $query->where('status', HealthMedicineBatch::STATUS_WRITTEN_OFF),
            'empty' => $query->where('quantity', '<=', 0),
            default => $query->where('quantity', '>', 0)
                ->where('status', '!=', HealthMedicineBatch::STATUS_WRITTEN_OFF),
        };

        $batches = $query
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->paginate(30)
            ->withQueryString();

        return view('health.pharmacy.stock', [
            'batches' => $batches,
            'filter' => $filter,
            'search' => $search,
            'settings' => $settings,
            'reasons' => HealthBatchMovement::REASONS,
            'medicines' => $this->medicineOptions(),
            'branches' => $this->branches(),
            'viewBranchId' => $branchId,
            'isMultiBranch' => BranchStockService::isMultiBranch($companyId),
            'canTransfer' => BranchStockService::canTransfer($companyId),
            'canManage' => \App\Services\HealthAccessService::can($this->user(), 'pharmacy.manage', $this->company()),
            'drift' => \App\Services\HealthAccessService::can($this->user(), 'pharmacy.manage', $this->company())
                ? HealthPharmacyStockService::reconcile($companyId, $branchId, $allBranches)
                : [],
        ]);
    }

    /** The traceability ledger — who moved what, out of which lot, and why. */
    public function movements(Request $request)
    {
        $companyId = $this->companyId();
        $branchId = $this->viewBranchId();
        $allBranches = BranchStockService::viewingAllBranches($companyId);

        $movements = HealthBatchMovement::withoutGlobalScopes()
            ->with(['medicine', 'batch', 'creator'])
            ->where('company_id', $companyId)
            ->when(!$allBranches, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->query('type')))
            ->when($request->filled('medicine_id'), fn ($q) => $q->where('medicine_id', (int) $request->query('medicine_id')))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('health.pharmacy.movements', [
            'movements' => $movements,
            'medicines' => $this->medicineOptions(),
            'types' => [
                HealthBatchMovement::TYPE_PURCHASE,
                HealthBatchMovement::TYPE_DISPENSE,
                HealthBatchMovement::TYPE_SALE_RETURN,
                HealthBatchMovement::TYPE_WASTAGE,
                HealthBatchMovement::TYPE_EXPIRY_WRITEOFF,
                HealthBatchMovement::TYPE_QUARANTINE,
                HealthBatchMovement::TYPE_RELEASE,
                HealthBatchMovement::TYPE_TRANSFER_IN,
                HealthBatchMovement::TYPE_TRANSFER_OUT,
                HealthBatchMovement::TYPE_ADJUSTMENT_IN,
                HealthBatchMovement::TYPE_ADJUSTMENT_OUT,
            ],
            'selectedType' => $request->query('type'),
            'selectedMedicine' => $request->query('medicine_id'),
        ]);
    }

    public function adjust(Request $request, $id)
    {
        $this->assertCan('pharmacy.manage');

        $data = $request->validate([
            'quantity' => 'required|numeric|min:0',
            'reason' => 'required|string|max:64',
            'notes' => 'nullable|string|max:500',
        ]);

        $batch = $this->batch($id);

        return $this->guarded(function () use ($batch, $data) {
            HealthPharmacyStockService::adjust(
                $this->companyId(),
                $batch,
                (float) $data['quantity'],
                $data['reason'],
                $this->user()?->id,
                $data['notes'] ?? null
            );

            return back()->with('success', __('health.ph_adjust_saved'));
        });
    }

    public function writeOff(Request $request, $id)
    {
        $this->assertCan('pharmacy.manage');

        $data = $request->validate([
            'quantity' => 'nullable|numeric|min:0.001',
            'reason' => 'required|string|max:64',
            'notes' => 'nullable|string|max:500',
        ]);

        $batch = $this->batch($id);

        return $this->guarded(function () use ($batch, $data) {
            HealthPharmacyStockService::writeOff(
                $this->companyId(),
                $batch,
                isset($data['quantity']) ? (float) $data['quantity'] : null,
                $data['reason'],
                $this->user()?->id,
                $data['notes'] ?? null
            );

            return back()->with('success', __('health.ph_writeoff_saved'));
        });
    }

    public function quarantine(Request $request, $id)
    {
        $this->assertCan('pharmacy.manage');

        $data = $request->validate(['reason' => 'required|string|max:190']);
        $batch = $this->batch($id);

        HealthPharmacyStockService::quarantine($this->companyId(), $batch, $data['reason'], $this->user()?->id);

        return back()->with('success', __('health.ph_quarantined'));
    }

    public function release($id)
    {
        $this->assertCan('pharmacy.manage');

        HealthPharmacyStockService::release($this->companyId(), $this->batch($id), $this->user()?->id);

        return back()->with('success', __('health.ph_released'));
    }

    public function transfer(Request $request, $id)
    {
        $this->assertCan('pharmacy.manage');

        $data = $request->validate([
            'to_branch_id' => 'required|integer',
            'quantity' => 'required|numeric|min:0.001',
            'notes' => 'nullable|string|max:500',
        ]);

        $companyId = $this->companyId();
        $batch = $this->batch($id);

        // Both ends must be branches this person may touch — a transfer is two
        // stock writes, not one.
        if (!BranchStockService::actorCanUse($companyId, (int) $data['to_branch_id'])) {
            return back()->with('error', __('health.dept_branch_not_yours'));
        }
        if (!BranchStockService::actorCanUse($companyId, $batch->branch_id !== null ? (int) $batch->branch_id : null)) {
            return back()->with('error', __('health.dept_branch_not_yours'));
        }

        return $this->guarded(function () use ($companyId, $batch, $data) {
            HealthPharmacyStockService::transfer(
                $companyId,
                $batch,
                (int) $data['to_branch_id'],
                (float) $data['quantity'],
                $this->user()?->id,
                $data['notes'] ?? null
            );

            return back()->with('success', __('health.ph_transfer_saved'));
        });
    }

    /** Opening / found stock that arrived outside a supplier purchase. */
    public function openingStock(Request $request)
    {
        $this->assertCan('pharmacy.manage');

        $data = $request->validate([
            'medicine_id' => 'required|integer',
            'branch_id' => 'nullable|integer',
            'quantity' => 'required|numeric|min:0.001',
            'cost_price' => 'nullable|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'batch_no' => 'nullable|string|max:64',
            'expiry_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ]);

        $companyId = $this->companyId();
        $picked = $request->filled('branch_id') ? (int) $data['branch_id'] : null;

        if ($this->mustPickBranch($picked)) {
            return back()->with('error', __('health.ph_pick_branch'));
        }

        $medicine = HealthMedicine::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->findOrFail($data['medicine_id']);

        return $this->guarded(function () use ($companyId, $medicine, $data, $picked) {
            HealthPharmacyStockService::receive(
                $companyId,
                $medicine,
                [
                    'quantity' => (float) $data['quantity'],
                    'cost_price' => (float) ($data['cost_price'] ?? $medicine->purchase_price),
                    'sale_price' => (float) ($data['sale_price'] ?? $medicine->sale_price),
                    'batch_no' => $data['batch_no'] ?? null,
                    'expiry_date' => $data['expiry_date'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ],
                $this->writeBranchId($picked),
                ['type' => 'health_opening_stock', 'id' => $medicine->id, 'number' => null],
                $this->user()?->id,
                HealthBatchMovement::TYPE_OPENING
            );

            return back()->with('success', __('health.ph_opening_saved'));
        });
    }

    // ═══════════════════════ Internals ═══════════════════════

    private function batch($id): HealthMedicineBatch
    {
        $batch = HealthMedicineBatch::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->findOrFail($id);

        // Adjust, write-off, quarantine, release and transfer all reach a lot
        // through here. Stock belonging to another shop is not this person's to
        // move, however the id was obtained.
        $this->assertBranchVisible($batch->branch_id !== null ? (int) $batch->branch_id : null);

        return $batch;
    }

    /**
     * The stock service refuses impossible movements with a ValidationException.
     * Surfaced as a flash message so the pharmacist reads WHY it was refused
     * instead of a bare 422 page.
     */
    private function guarded(callable $action)
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }
    }
}
