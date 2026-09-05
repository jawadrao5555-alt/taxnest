<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Models\FbrPosTransactionItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\PharmacyClaim;
use App\Models\PharmacyClaimItem;
use App\Models\PharmacyStockAction;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Supplier;
use App\Services\BranchStockService;
use App\Services\InventoryService;
use App\Services\PharmacyBatchService;
use App\Services\PosFeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * FBR POS Pharmacy Mode (Task 1558).
 *
 * Everything a medical store needs that a general retail counter does not:
 * batch-and-expiry stock, near-expiry visibility, quarantine and write-off with
 * a reason and a person against it, distributor expiry claims, and the pharmacy
 * reports the shop actually prints.
 *
 * EVERY action here goes through pharmacyGate(): the package must carry the
 * module AND the shop must have switched it on. Navigation hides these screens
 * when the mode is off, and this gate makes sure a bookmarked URL cannot walk
 * around that — a panel must never do quietly what it refuses to advertise.
 */
class FbrPosPharmacyController extends Controller
{
    private function user() { return Auth::guard('fbrpos')->user(); }
    private function companyId(): int { return (int) $this->user()->company_id; }
    private function company(): ?Company { return Company::find($this->companyId()); }

    /**
     * The one entry gate. Returns null when the caller may proceed, or a
     * response when it may not.
     */
    private function pharmacyGate(bool $json = false)
    {
        $company = $this->company();
        if (!PosFeatureService::pharmacyLive($company)) {
            return $json
                ? response()->json(['success' => false, 'message' => __('pos.ph_mode_off')], 403)
                : redirect()->route('fbrpos.customize')->with('error', __('pos.ph_mode_off'));
        }

        return null;
    }

    /** Batch and claim work is owner/manager territory, exactly like stock. */
    private function assertNotCashier(): void
    {
        $u = $this->user();
        if (in_array($u->pos_role ?? '', ['pos_cashier', 'local_viewer'], true)) {
            abort(403, __('pos.access_denied'));
        }
    }

    /** Same branch view-model the stock pages use, so scoping stays identical. */
    private function branchView(int $companyId): array
    {
        $branches = BranchStockService::branches($companyId);

        return [
            'branches' => BranchStockService::actorBranches($companyId),
            'multiBranch' => $branches->isNotEmpty(),
            'activeBranchId' => BranchStockService::viewBranchId($companyId),
            'activeBranchName' => BranchStockService::branchName($companyId, BranchStockService::viewBranchId($companyId)),
            'allBranches' => BranchStockService::viewingAllBranches($companyId),
            'branchNames' => $branches->pluck('name', 'id')->all(),
        ];
    }

    /** Branch-scope a product_batches / pharmacy_* query the same STRICT way. */
    private function scopeBranch($query, int $companyId)
    {
        $branchId = BranchStockService::viewBranchId($companyId);
        if (!$branchId) {
            return $query;
        }

        return $query->where('branch_id', $branchId);
    }

    private function writeBranch(int $companyId, ?int $picked = null): ?int
    {
        return BranchStockService::writeBranchId(
            $companyId,
            $picked ?? BranchStockService::viewBranchId($companyId)
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Batches & expiry
    // ═════════════════════════════════════════════════════════════════════

    public function batches(Request $request)
    {
        if ($resp = $this->pharmacyGate()) return $resp;
        $this->assertNotCashier();

        $companyId = $this->companyId();
        BranchStockService::healLegacyRows($companyId);

        $filter = $request->get('filter', 'all');
        $search = trim((string) $request->get('q', ''));

        $query = ProductBatch::with(['product:id,name,generic_name,strength,dosage_form,uom', 'supplier:id,name'])
            ->where('company_id', $companyId);
        $this->scopeBranch($query, $companyId);

        $today = now()->toDateString();
        $soon = now()->addDays(PharmacyBatchService::NEAR_EXPIRY_DAYS)->toDateString();

        match ($filter) {
            'expired' => $query->whereNotNull('expiry_date')->where('expiry_date', '<', $today)
                ->where('status', '!=', ProductBatch::STATUS_WRITTEN_OFF)->where('quantity', '>', 0),
            'near' => $query->whereNotNull('expiry_date')->whereBetween('expiry_date', [$today, $soon])
                ->where('status', ProductBatch::STATUS_ACTIVE)->where('quantity', '>', 0),
            'quarantined' => $query->where('status', ProductBatch::STATUS_QUARANTINED),
            'written_off' => $query->where('status', ProductBatch::STATUS_WRITTEN_OFF),
            default => $query->where('quantity', '>', 0)->where('status', '!=', ProductBatch::STATUS_WRITTEN_OFF),
        };

        if ($search !== '') {
            $query->where(function ($w) use ($search) {
                $w->where('batch_number', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($p) use ($search) {
                        $p->where('name', 'like', "%{$search}%")
                            ->orWhere('generic_name', 'like', "%{$search}%");
                    });
            });
        }

        $batches = $query
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->paginate(50)
            ->withQueryString();

        // Counts for the filter chips — one cheap grouped query, not five.
        $counts = [
            'all' => (clone $this->scopeBranch(ProductBatch::where('company_id', $companyId), $companyId))
                ->where('quantity', '>', 0)->where('status', '!=', ProductBatch::STATUS_WRITTEN_OFF)->count(),
            'near' => (clone $this->scopeBranch(ProductBatch::where('company_id', $companyId), $companyId))
                ->whereNotNull('expiry_date')->whereBetween('expiry_date', [$today, $soon])
                ->where('status', ProductBatch::STATUS_ACTIVE)->where('quantity', '>', 0)->count(),
            'expired' => (clone $this->scopeBranch(ProductBatch::where('company_id', $companyId), $companyId))
                ->whereNotNull('expiry_date')->where('expiry_date', '<', $today)
                ->where('status', '!=', ProductBatch::STATUS_WRITTEN_OFF)->where('quantity', '>', 0)->count(),
            'quarantined' => (clone $this->scopeBranch(ProductBatch::where('company_id', $companyId), $companyId))
                ->where('status', ProductBatch::STATUS_QUARANTINED)->count(),
            'written_off' => (clone $this->scopeBranch(ProductBatch::where('company_id', $companyId), $companyId))
                ->where('status', ProductBatch::STATUS_WRITTEN_OFF)->count(),
        ];

        $suppliers = Supplier::forCompany($companyId)->orderBy('name')->get(['id', 'name']);

        return view('fbr-pos.pharmacy.batches', array_merge($this->branchView($companyId), [
            'company' => $this->company(),
            'batches' => $batches,
            'counts' => $counts,
            'filter' => $filter,
            'search' => $search,
            'suppliers' => $suppliers,
            'nearDays' => PharmacyBatchService::NEAR_EXPIRY_DAYS,
        ]));
    }

    /**
     * Opening / correction entry for a batch: put a counted quantity of one
     * batch on the shelf. Goes through the ordinary inventory ledger so the
     * aggregate and the batch ledger move together and stay reconcilable.
     */
    public function storeBatch(Request $request)
    {
        if ($resp = $this->pharmacyGate()) return $resp;
        $this->assertNotCashier();

        $data = $request->validate([
            'product_id' => 'required|integer',
            'batch_number' => 'required|string|max:60',
            'expiry_date' => 'nullable|string|max:20',
            'quantity' => 'required|numeric|min:0.001',
            'cost_price' => 'nullable|numeric|min:0',
            'retail_price' => 'nullable|numeric|min:0',
            'supplier_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:255',
        ]);

        $companyId = $this->companyId();
        $product = Product::where('company_id', $companyId)->find($data['product_id']);
        if (!$product) {
            return back()->with('error', __('pos.ph_product_not_found'));
        }

        $picked = $request->filled('branch_id') ? (int) $request->branch_id : null;
        if ($picked !== null && !BranchStockService::actorCanUse($companyId, $picked)) {
            abort(403, __('pos.access_denied'));
        }
        if ($picked === null && BranchStockService::viewingAllBranches($companyId)) {
            return back()->withInput()->with('error', __('pos.stock_edit_pick_branch'));
        }
        $branchId = $this->writeBranch($companyId, $picked);

        $qty = (float) $data['quantity'];
        $cost = (float) ($data['cost_price'] ?? 0);

        DB::transaction(function () use ($companyId, $product, $branchId, $qty, $cost, $data) {
            $batch = PharmacyBatchService::receive(
                $companyId,
                $product->id,
                $branchId,
                $qty,
                $data['batch_number'],
                $data['expiry_date'] ?? null,
                $cost,
                [
                    'retail_price' => $data['retail_price'] ?? null,
                    'supplier_id' => $data['supplier_id'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $this->user()->id,
                ]
            );

            InventoryService::addStock(
                $companyId,
                $product->id,
                $qty,
                $cost,
                InventoryMovement::TYPE_OPENING,
                $branchId,
                ['type' => 'pharmacy_batch', 'id' => $batch?->id, 'number' => $batch?->batch_number],
                $data['notes'] ?? __('pos.ph_batch_opening_note'),
                $this->user()->id,
                [
                    'batch_id' => $batch?->id,
                    'batch_number' => $batch?->batch_number,
                    'batch_expiry' => $batch?->expiry_date?->toDateString(),
                ]
            );
        });

        return back()->with('success', __('pos.ph_batch_saved'));
    }

    /** Quarantine / release / write-off — with a reason and a person. */
    public function batchAction(Request $request, $id)
    {
        if ($resp = $this->pharmacyGate()) return $resp;
        $this->assertNotCashier();

        $data = $request->validate([
            'action' => 'required|in:quarantine,release,write_off',
            'quantity' => 'nullable|numeric|min:0',
            'reason' => 'nullable|string|max:30',
            'responsible_name' => 'nullable|string|max:150',
            'notes' => 'nullable|string|max:500',
        ]);

        $companyId = $this->companyId();
        $batch = ProductBatch::where('company_id', $companyId)->find($id);
        if (!$batch) {
            return back()->with('error', __('pos.ph_batch_not_found'));
        }
        if ($batch->branch_id && !BranchStockService::actorCanUse($companyId, (int) $batch->branch_id)) {
            abort(403, __('pos.access_denied'));
        }

        // A write-off is the one irreversible action here, so it must name a
        // reason and a responsible person — that is the whole point of the
        // accountability record beside the stock movement.
        if ($data['action'] === PharmacyStockAction::ACTION_WRITE_OFF
            && (empty($data['reason']) || empty($data['responsible_name']))) {
            return back()->with('error', __('pos.ph_writeoff_needs_reason'));
        }

        PharmacyBatchService::act($batch, $data['action'], [
            'quantity' => $data['quantity'] ?? null,
            'reason' => $data['reason'] ?? null,
            'responsible_name' => $data['responsible_name'] ?? null,
            'responsible_user_id' => $this->user()->id,
            'notes' => $data['notes'] ?? null,
            'created_by' => $this->user()->id,
        ]);

        return back()->with('success', __('pos.ph_batch_action_done'));
    }

    /**
     * Batch picker for the sale screen. Fetched per product ON DEMAND — never
     * baked into the boot payload, because a 10,000-item pharmacy catalogue
     * with its batches would freeze the counter on every page load.
     */
    public function batchOptions(Request $request)
    {
        if ($resp = $this->pharmacyGate(true)) return $resp;

        $companyId = $this->companyId();
        $productId = (int) $request->get('product_id');
        if ($productId <= 0) {
            return response()->json(['success' => false, 'batches' => []], 422);
        }
        if (!Product::where('company_id', $companyId)->where('id', $productId)->exists()) {
            return response()->json(['success' => false, 'batches' => []], 404);
        }

        $branchId = BranchStockService::writeBranchId($companyId, BranchStockService::viewBranchId($companyId));

        return response()->json([
            'success' => true,
            'batches' => PharmacyBatchService::pickerRows($companyId, $productId, $branchId),
            'untracked' => PharmacyBatchService::untrackedQuantity($companyId, $productId, $branchId),
            'near_days' => PharmacyBatchService::NEAR_EXPIRY_DAYS,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Distributor claims
    // ═════════════════════════════════════════════════════════════════════

    public function claims(Request $request)
    {
        if ($resp = $this->pharmacyGate()) return $resp;
        $this->assertNotCashier();

        $companyId = $this->companyId();
        $status = $request->get('status', 'open');

        $query = PharmacyClaim::with(['supplier:id,name'])
            ->withCount('items')
            ->where('company_id', $companyId);
        $this->scopeBranch($query, $companyId);

        if ($status === 'open') {
            $query->whereIn('status', [PharmacyClaim::STATUS_DRAFT, PharmacyClaim::STATUS_RAISED]);
        } elseif ($status !== 'all' && in_array($status, PharmacyClaim::STATUSES, true)) {
            $query->where('status', $status);
        }

        $claims = $query->orderByDesc('id')->paginate(30)->withQueryString();

        // What is claimable right now: expired / quarantined batches with stock
        // that are not already sitting on an open claim.
        $claimable = $this->claimableBatches($companyId);

        return view('fbr-pos.pharmacy.claims', array_merge($this->branchView($companyId), [
            'company' => $this->company(),
            'claims' => $claims,
            'status' => $status,
            'claimable' => $claimable,
            'suppliers' => Supplier::forCompany($companyId)->orderBy('name')->get(['id', 'name']),
        ]));
    }

    /** Expired / quarantined stock that no open claim already covers. */
    private function claimableBatches(int $companyId)
    {
        $onOpenClaims = PharmacyClaimItem::where('pharmacy_claim_items.company_id', $companyId)
            ->join('pharmacy_claims', 'pharmacy_claims.id', '=', 'pharmacy_claim_items.claim_id')
            ->whereIn('pharmacy_claims.status', [PharmacyClaim::STATUS_DRAFT, PharmacyClaim::STATUS_RAISED])
            ->pluck('pharmacy_claim_items.batch_id')
            ->filter()
            ->all();

        $query = ProductBatch::with(['product:id,name,generic_name,strength', 'supplier:id,name'])
            ->where('company_id', $companyId)
            ->where('quantity', '>', 0)
            ->where('status', '!=', ProductBatch::STATUS_WRITTEN_OFF)
            ->where(function ($w) {
                $w->where('status', ProductBatch::STATUS_QUARANTINED)
                    ->orWhere(function ($e) {
                        $e->whereNotNull('expiry_date')->where('expiry_date', '<', now()->toDateString());
                    });
            });
        $this->scopeBranch($query, $companyId);
        if ($onOpenClaims) {
            $query->whereNotIn('id', $onOpenClaims);
        }

        return $query->orderBy('expiry_date')->limit(300)->get();
    }

    public function storeClaim(Request $request)
    {
        if ($resp = $this->pharmacyGate()) return $resp;
        $this->assertNotCashier();

        $data = $request->validate([
            'supplier_id' => 'nullable|integer',
            'supplier_name' => 'nullable|string|max:150',
            'batch_ids' => 'required|array|min:1',
            'batch_ids.*' => 'integer',
            'reason' => 'nullable|in:expired,damaged,near_expiry,other',
            'notes' => 'nullable|string|max:500',
        ]);

        $companyId = $this->companyId();
        $batches = ProductBatch::with('product:id,name')
            ->where('company_id', $companyId)
            ->whereIn('id', $data['batch_ids'])
            ->where('quantity', '>', 0)
            ->get();

        if ($batches->isEmpty()) {
            return back()->with('error', __('pos.ph_claim_no_batches'));
        }
        foreach ($batches as $b) {
            if ($b->branch_id && !BranchStockService::actorCanUse($companyId, (int) $b->branch_id)) {
                abort(403, __('pos.access_denied'));
            }
        }

        // ?? null: a 'nullable' rule that never sees the field leaves the key
        // out of validate()'s return entirely — reading it bare 500s the page
        // for the ordinary case of a hand-typed distributor with no saved row.
        $supplierId = $data['supplier_id'] ?? null;
        $supplier = $supplierId
            ? Supplier::forCompany($companyId)->find($supplierId)
            : null;

        $claim = DB::transaction(function () use ($companyId, $batches, $supplier, $data) {
            $claim = PharmacyClaim::create([
                'company_id' => $companyId,
                'branch_id' => $batches->first()->branch_id,
                'supplier_id' => $supplier?->id,
                'supplier_name' => $supplier?->name ?: ($data['supplier_name'] ?? null),
                'claim_number' => $this->nextClaimNumber($companyId),
                'status' => PharmacyClaim::STATUS_DRAFT,
                'total_amount' => 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->user()->id,
            ]);

            $total = 0;
            foreach ($batches as $b) {
                $qty = (float) $b->quantity;
                $amount = round($qty * (float) $b->cost_price, 2);
                $total += $amount;
                PharmacyClaimItem::create([
                    'claim_id' => $claim->id,
                    'company_id' => $companyId,
                    'product_id' => $b->product_id,
                    'batch_id' => $b->id,
                    'item_name' => $b->product?->name ?? '',
                    'batch_number' => $b->batch_number,
                    'expiry_date' => $b->expiry_date?->toDateString(),
                    'quantity' => $qty,
                    'cost_price' => (float) $b->cost_price,
                    'total_amount' => $amount,
                    'reason' => $data['reason'] ?? ($b->isExpired() ? 'expired' : 'damaged'),
                ]);

                // Stock stays on the shelf until the claim is RAISED — the shop
                // is still holding the goods while it prepares the list.
                if ($b->status === ProductBatch::STATUS_ACTIVE) {
                    PharmacyBatchService::act($b, PharmacyStockAction::ACTION_QUARANTINE, [
                        'reason' => $data['reason'] ?? 'expired',
                        'responsible_user_id' => $this->user()->id,
                        'responsible_name' => $this->user()->name,
                        'claim_id' => $claim->id,
                        'created_by' => $this->user()->id,
                    ]);
                }
            }

            $claim->update(['total_amount' => round($total, 2)]);

            return $claim;
        });

        return redirect()->route('fbrpos.pharmacy.claim', $claim->id)
            ->with('success', __('pos.ph_claim_created', ['number' => $claim->claim_number]));
    }

    public function showClaim($id)
    {
        if ($resp = $this->pharmacyGate()) return $resp;
        $this->assertNotCashier();

        $companyId = $this->companyId();
        $claim = PharmacyClaim::with(['items.product:id,name,generic_name,strength', 'supplier:id,name,phone,address'])
            ->where('company_id', $companyId)
            ->findOrFail($id);

        return view('fbr-pos.pharmacy.claim-show', [
            'company' => $this->company(),
            'claim' => $claim,
        ]);
    }

    /** The printable hand-over list. */
    public function printClaim($id)
    {
        if ($resp = $this->pharmacyGate()) return $resp;
        $this->assertNotCashier();

        $companyId = $this->companyId();
        $claim = PharmacyClaim::with(['items.product:id,name,generic_name,strength', 'supplier:id,name,phone,address'])
            ->where('company_id', $companyId)
            ->findOrFail($id);

        return view('fbr-pos.pharmacy.claim-print', [
            'company' => $this->company(),
            'claim' => $claim,
        ]);
    }

    /**
     * Move a claim along its lifecycle.
     *
     * RAISED is the moment the goods physically leave for the distributor, so
     * that is where the stock is written off — with the claim recorded against
     * every one of those write-offs, which is what "attributable" has to mean.
     * SETTLED / CREDITED only record the money answer; the stock already left.
     */
    public function updateClaimStatus(Request $request, $id)
    {
        if ($resp = $this->pharmacyGate()) return $resp;
        $this->assertNotCashier();

        $data = $request->validate([
            'status' => 'required|in:raised,settled,credited,rejected',
            'settled_amount' => 'nullable|numeric|min:0',
            'settlement_reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        $companyId = $this->companyId();
        $claim = PharmacyClaim::with('items')->where('company_id', $companyId)->findOrFail($id);

        if ($claim->isClosed()) {
            return back()->with('error', __('pos.ph_claim_closed'));
        }

        DB::transaction(function () use ($claim, $data, $companyId) {
            if ($data['status'] === PharmacyClaim::STATUS_RAISED) {
                foreach ($claim->items as $item) {
                    if (!$item->batch_id) {
                        continue;
                    }
                    $batch = ProductBatch::where('company_id', $companyId)->find($item->batch_id);
                    if (!$batch || $batch->status === ProductBatch::STATUS_WRITTEN_OFF) {
                        continue;
                    }
                    PharmacyBatchService::act($batch, PharmacyStockAction::ACTION_WRITE_OFF, [
                        'quantity' => (float) $item->quantity,
                        'reason' => $item->reason,
                        'responsible_user_id' => $this->user()->id,
                        'responsible_name' => $this->user()->name,
                        'claim_id' => $claim->id,
                        'notes' => __('pos.ph_claim_writeoff_note', ['number' => $claim->claim_number]),
                        'created_by' => $this->user()->id,
                    ]);
                }
                $claim->update([
                    'status' => PharmacyClaim::STATUS_RAISED,
                    'raised_at' => now()->toDateString(),
                    'notes' => $data['notes'] ?? $claim->notes,
                ]);

                return;
            }

            $claim->update([
                'status' => $data['status'],
                'settled_amount' => $data['settled_amount'] ?? null,
                'settlement_reference' => $data['settlement_reference'] ?? null,
                'settled_at' => now()->toDateString(),
                'notes' => $data['notes'] ?? $claim->notes,
            ]);
        });

        return back()->with('success', __('pos.ph_claim_updated'));
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Reports
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Pharmacy reports. Lives beside the panel's existing reports area and
     * obeys the same branch scope and the same "open on a real default"
     * convention — never an accidental All Time scan of a 10k catalogue.
     */
    public function reports(Request $request)
    {
        if ($resp = $this->pharmacyGate()) return $resp;
        if (!PosFeatureService::planAllows($this->company(), 'reports_enabled')
            && in_array($request->get('report'), ['movers'], true)) {
            return redirect()->route('fbrpos.pharmacy.reports')->with('error', __('pos.plan_locked_feature'));
        }
        $this->assertNotCashier();

        $companyId = $this->companyId();
        $report = $request->get('report', 'near_expiry');
        $today = now()->toDateString();
        $soon = now()->addDays(PharmacyBatchService::NEAR_EXPIRY_DAYS)->toDateString();

        $rows = collect();
        $totals = ['quantity' => 0.0, 'cost' => 0.0, 'retail' => 0.0];

        if (in_array($report, ['near_expiry', 'expired', 'batch_stock', 'valuation'], true)) {
            $query = ProductBatch::with(['product:id,name,generic_name,strength,dosage_form,uom', 'supplier:id,name'])
                ->where('company_id', $companyId)
                ->where('quantity', '>', 0)
                ->where('status', '!=', ProductBatch::STATUS_WRITTEN_OFF);
            $this->scopeBranch($query, $companyId);

            if ($report === 'near_expiry') {
                $query->whereNotNull('expiry_date')->whereBetween('expiry_date', [$today, $soon]);
            } elseif ($report === 'expired') {
                $query->whereNotNull('expiry_date')->where('expiry_date', '<', $today);
            }

            $rows = $query->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expiry_date')->limit(2000)->get();

            foreach ($rows as $r) {
                $totals['quantity'] += (float) $r->quantity;
                $totals['cost'] += (float) $r->quantity * (float) $r->cost_price;
                $totals['retail'] += (float) $r->quantity * (float) ($r->retail_price ?? 0);
            }
        } elseif ($report === 'claims') {
            $q = PharmacyClaim::with('supplier:id,name')->withCount('items')->where('company_id', $companyId);
            $this->scopeBranch($q, $companyId);
            $rows = $q->orderByDesc('id')->limit(500)->get();
            $totals['cost'] = (float) $rows->sum('total_amount');
            $totals['retail'] = (float) $rows->sum('settled_amount');
        } elseif ($report === 'prescriptions') {
            $rows = $this->prescriptionRegister($companyId, $request);
        } elseif ($report === 'movers') {
            [$rows, $totals] = $this->moverRows($companyId, $request);
        } elseif ($report === 'writeoffs') {
            $q = PharmacyStockAction::with(['product:id,name,generic_name', 'batch:id,batch_number,expiry_date'])
                ->where('company_id', $companyId)
                ->where('action', PharmacyStockAction::ACTION_WRITE_OFF);
            $this->scopeBranch($q, $companyId);
            $rows = $q->orderByDesc('id')->limit(500)->get();
            $totals['quantity'] = (float) $rows->sum('quantity');
            $totals['cost'] = (float) $rows->sum('cost_value');
        }

        return view('fbr-pos.pharmacy.reports', array_merge($this->branchView($companyId), [
            'company' => $this->company(),
            'report' => $report,
            'rows' => $rows,
            'totals' => $totals,
            'nearDays' => PharmacyBatchService::NEAR_EXPIRY_DAYS,
            'from' => $request->get('from', now()->startOfMonth()->toDateString()),
            'to' => $request->get('to', $today),
        ]));
    }

    /** Bills that captured a prescription, or that sold a schedule medicine. */
    private function prescriptionRegister(int $companyId, Request $request)
    {
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to = $request->get('to', now()->toDateString());

        $query = FbrPosTransaction::with(['items' => function ($q) {
                $q->select('id', 'transaction_id', 'item_name', 'quantity', 'batch_number', 'batch_expiry');
            }])
            ->where('company_id', $companyId)
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->where(function ($w) {
                $w->whereNotNull('doctor_name')
                    ->orWhereNotNull('patient_name')
                    ->orWhereNotNull('prescription_image');
            });

        $branchId = BranchStockService::viewBranchId($companyId);
        if ($branchId && \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'branch_id')) {
            $query->where('branch_id', $branchId);
        }

        return $query->orderByDesc('id')->limit(500)->get();
    }

    /**
     * Fast and slow movers over a window, by quantity sold.
     *
     * A slow mover is deliberately defined as "on the shelf but barely selling"
     * rather than "not sold at all" — a pharmacy needs to see the item that
     * moved twice this quarter and is carrying six months of stock.
     */
    private function moverRows(int $companyId, Request $request): array
    {
        $from = $request->get('from', now()->subDays(90)->toDateString());
        $to = $request->get('to', now()->toDateString());

        $sold = FbrPosTransactionItem::query()
            ->join('fbr_pos_transactions as t', 't.id', '=', 'fbr_pos_transaction_items.transaction_id')
            ->where('t.company_id', $companyId)
            ->whereBetween('t.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->whereNotNull('fbr_pos_transaction_items.product_id')
            ->selectRaw('fbr_pos_transaction_items.product_id,
                         SUM(fbr_pos_transaction_items.quantity) as qty,
                         SUM(fbr_pos_transaction_items.total) as amount')
            ->groupBy('fbr_pos_transaction_items.product_id')
            ->pluck('qty', 'product_id');

        $stockQuery = InventoryStock::with(['product:id,name,generic_name,strength,dosage_form'])
            ->where('company_id', $companyId);
        $branchId = BranchStockService::viewBranchId($companyId);
        if ($branchId) {
            $stockQuery->where('branch_id', $branchId);
        }

        $rows = $stockQuery->limit(3000)->get()->map(function ($s) use ($sold) {
            $qty = (float) ($sold[$s->product_id] ?? 0);
            return (object) [
                'product' => $s->product,
                'sold' => $qty,
                'on_hand' => (float) $s->quantity,
                'value' => round((float) $s->quantity * (float) $s->avg_purchase_price, 2),
            ];
        })->filter(fn ($r) => $r->product !== null)->sortByDesc('sold')->values();

        return [$rows, [
            'quantity' => (float) $rows->sum('sold'),
            'cost' => (float) $rows->sum('value'),
            'retail' => 0.0,
        ]];
    }

    // ═════════════════════════════════════════════════════════════════════
    //  Helpers
    // ═════════════════════════════════════════════════════════════════════

    /**
     * CLM-0001 upward, per company. Allocated inside the claim transaction, and
     * never reused: the unique index on (company_id, claim_number) is the real
     * guard, this only picks the next free one.
     */
    private function nextClaimNumber(int $companyId): string
    {
        $last = PharmacyClaim::where('company_id', $companyId)
            ->orderByDesc('id')
            ->value('claim_number');
        $n = 0;
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $n = (int) $m[1];
        }

        return 'CLM-' . str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT);
    }
}
