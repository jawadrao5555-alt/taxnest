<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PosProduct;
use App\Models\InventoryStock;
use App\Models\InventoryMovement;
use App\Models\InventoryAdjustment;
use App\Services\BranchStockService;
use App\Services\RecipeInventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PosInventoryController extends Controller
{
    private function ensureInventoryEnabled()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company || !$company->inventory_enabled) {
            // Company admin has full visibility of the links — guide them to
            // enable the module instead of a hard 403.
            abort(redirect()->route('pos.features')->with('error', 'Inventory module is OFF. Enable it from POS Features to use these pages.'));
        }
        // Per-branch stock (Task 1354): before anything is read or written,
        // make sure no pre-branch (branch-less) row is stranded — those goods
        // belong to the head office. Cheap and idempotent; see
        // BranchStockService::healLegacyRows().
        BranchStockService::healLegacyRows((int) $companyId);
        return [$companyId, $company];
    }

    /**
     * View-model shared by every inventory page: which branch is being looked
     * at, its name, and the branch list for the "sab branches" column.
     */
    private function branchView(int $companyId): array
    {
        $branches = BranchStockService::branches($companyId);
        $activeBranchId = BranchStockService::viewBranchId($companyId);

        return [
            // Pickers only ever offer branches THIS user may touch; the
            // company-wide list stays behind branchNames (labels) so an
            // owner's all-branches table can still name every shop.
            'branches' => BranchStockService::actorBranches($companyId),
            'multiBranch' => $branches->isNotEmpty(),
            'canTransfer' => BranchStockService::canTransfer($companyId),
            'activeBranchId' => $activeBranchId,
            'activeBranchName' => BranchStockService::branchName($companyId, $activeBranchId),
            'allBranches' => BranchStockService::viewingAllBranches($companyId),
            'branchNames' => $branches->pluck('name', 'id')->all(),
        ];
    }

    /**
     * A branch id that arrived from the browser must be one the user is
     * allowed to touch — company ownership alone is not enough, or a manager
     * confined to Gulberg could adjust or empty Main Shop.
     */
    private function assertBranchAllowed(int $companyId, ?int $branchId): void
    {
        if ($branchId !== null && !BranchStockService::actorCanUse($companyId, $branchId)) {
            abort(403, __('pos.access_denied'));
        }
    }

    /** Branch-scope every inventory query the same way. */
    private function scopeBranch($query, int $companyId)
    {
        return BranchStockService::applyViewFilter($query, $companyId);
    }

    /** Moving goods between branches is an owner/manager job, never a cashier's. */
    private function assertNotCashier(): void
    {
        $user = auth('pos')->user();
        if ($user && $user->posCashierBlocked()) {
            abort(403, __('pos.access_denied'));
        }
    }

    /**
     * True only when the DB actually has the in-transit columns (Task 1434).
     *
     * The owner's cPanel PROD schema drifts — a migration can be marked "Ran"
     * without the column existing (see prod-schema-drift-selfheal). Every
     * query/write touching transfer_status or received_quantity is gated on
     * this so the transfer + inventory pages DEGRADE to the old instant-transfer
     * behaviour instead of 500-ing on a database where the migration has not
     * landed. Memoized — the schema does not change mid-request.
     */
    private ?bool $transferColumnsReady = null;
    private function transferColumnsReady(): bool
    {
        if ($this->transferColumnsReady === null) {
            try {
                $this->transferColumnsReady = Schema::hasColumn('inventory_movements', 'transfer_status')
                    && Schema::hasColumn('inventory_movements', 'received_quantity');
            } catch (\Throwable $e) {
                $this->transferColumnsReady = false;
            }
        }
        return $this->transferColumnsReady;
    }

    public function dashboard()
    {
        [$companyId, $company] = $this->ensureInventoryEnabled();
        $branchView = $this->branchView($companyId);

        // Product catalogue stays company-wide (the same item is sold in every
        // shop); only the STOCK figures below follow the selected branch.
        $totalProducts = PosProduct::where('company_id', $companyId)->where('is_active', true)->count();
        $totalStockValue = $this->scopeBranch(InventoryStock::where('company_id', $companyId), $companyId)
            ->selectRaw('COALESCE(SUM(quantity * avg_purchase_price), 0) as value')
            ->value('value');

        $lowStockItems = $this->scopeBranch(InventoryStock::where('company_id', $companyId), $companyId)
            ->lowStock()
            ->with('posProduct')
            ->get();

        $outOfStockCount = $this->scopeBranch(InventoryStock::where('company_id', $companyId), $companyId)
            ->where('quantity', '<=', 0)
            ->count();

        $totalTracked = $this->scopeBranch(InventoryStock::where('company_id', $companyId), $companyId)->count();
        $healthyCount = $this->scopeBranch(InventoryStock::where('company_id', $companyId), $companyId)
            ->whereRaw('quantity > min_stock_level')->count();

        $recentMovements = $this->scopeBranch(InventoryMovement::where('company_id', $companyId), $companyId)
            ->with(['posProduct', 'creator'])
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        $topMovers = $this->scopeBranch(InventoryMovement::where('company_id', $companyId), $companyId)
            ->where('type', 'sale')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('product_id, SUM(quantity) as total_sold')
            ->groupBy('product_id')
            ->orderByDesc('total_sold')
            ->take(5)
            ->with('posProduct')
            ->get();

        // "Kis branch mein kitna maal para hai" — the answer the owner opens
        // this page for. Only meaningful on the company-wide view.
        $branchTotals = collect();
        if ($branchView['allBranches']) {
            $rows = InventoryStock::where('company_id', $companyId)
                ->selectRaw('branch_id, COUNT(*) as items, COALESCE(SUM(quantity), 0) as qty, COALESCE(SUM(quantity * avg_purchase_price), 0) as value')
                ->groupBy('branch_id')
                ->get()
                ->keyBy(fn ($r) => (int) ($r->branch_id ?? 0));
            // Company-wide list on purpose: this table is the owner's
            // "koi maal ghayab na ho" check, so a switched-OFF branch that
            // still holds goods must appear in it too.
            $branchTotals = BranchStockService::branches($companyId)->map(function ($b) use ($rows) {
                $row = $rows->get((int) $b->id);
                return (object) [
                    'id' => (int) $b->id,
                    'name' => $b->name,
                    'is_head_office' => (bool) ($b->is_head_office ?? false),
                    'items' => (int) ($row->items ?? 0),
                    'qty' => (float) ($row->qty ?? 0),
                    'value' => (float) ($row->value ?? 0),
                ];
            });
        }

        return view('pos.inventory.dashboard', array_merge($branchView, compact(
            'company', 'totalProducts', 'totalStockValue', 'lowStockItems',
            'outOfStockCount', 'recentMovements', 'topMovers', 'totalTracked',
            'healthyCount', 'branchTotals'
        )));
    }

    public function stock(Request $request)
    {
        [$companyId, $company] = $this->ensureInventoryEnabled();
        $branchView = $this->branchView($companyId);

        $query = $this->scopeBranch(
            InventoryStock::where('company_id', $companyId)->with(['posProduct', 'branch']),
            $companyId
        );

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('posProduct', function ($q) use ($search) {
                $q->where('name', \App\Helpers\DbCompat::like(), "%{$search}%");
            });
        }

        if ($request->filled('filter')) {
            if ($request->filter === 'low') {
                $query->lowStock();
            } elseif ($request->filter === 'out') {
                $query->where('quantity', '<=', 0);
            }
        }

        $stocks = $query->orderBy('updated_at', 'desc')->paginate(25)->appends($request->all());

        return view('pos.inventory.stock', array_merge($branchView, compact('company', 'stocks')));
    }

    public function movements(Request $request)
    {
        [$companyId, $company] = $this->ensureInventoryEnabled();
        $branchView = $this->branchView($companyId);

        $query = $this->scopeBranch(
            InventoryMovement::where('company_id', $companyId)->with(['posProduct', 'creator', 'branch']),
            $companyId
        );

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $movements = $query->orderBy('created_at', 'desc')->paginate(30)->appends($request->all());
        $products = PosProduct::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();

        return view('pos.inventory.movements', array_merge($branchView, compact('company', 'movements', 'products')));
    }

    public function lowStockAlerts()
    {
        [$companyId, $company] = $this->ensureInventoryEnabled();
        $branchView = $this->branchView($companyId);

        $alerts = $this->scopeBranch(InventoryStock::where('company_id', $companyId), $companyId)
            ->lowStock()
            ->with(['posProduct', 'branch'])
            ->orderByRaw('quantity - min_stock_level ASC')
            ->get();

        $outOfStock = $this->scopeBranch(InventoryStock::where('company_id', $companyId), $companyId)
            ->where('quantity', '<=', 0)
            ->with(['posProduct', 'branch'])
            ->get();

        return view('pos.inventory.low-stock', array_merge($branchView, compact('company', 'alerts', 'outOfStock')));
    }

    public function adjustStock(Request $request)
    {
        [$companyId, $company] = $this->ensureInventoryEnabled();
        $branchView = $this->branchView($companyId);

        if ($request->isMethod('get')) {
            $products = PosProduct::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();
            return view('pos.inventory.adjust-stock', array_merge($branchView, compact('company', 'products')));
        }

        $request->validate([
            'product_id' => 'required|exists:pos_products,id',
            'type' => 'required|in:add,remove,set',
            'quantity' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string|max:500',
            'purchase_price' => 'nullable|numeric|min:0',
            'branch_id' => 'nullable|integer',
        ]);

        $product = PosProduct::where('company_id', $companyId)->findOrFail($request->product_id);

        // The adjustment lands on the branch the user picked (owner on the
        // all-branches view), else on the branch they are standing in.
        $picked = $request->filled('branch_id') ? (int) $request->branch_id : null;
        $this->assertBranchAllowed($companyId, $picked);
        $branchId = BranchStockService::writeBranchId($companyId, $picked);

        DB::beginTransaction();
        try {
            $stock = BranchStockService::stockRow($companyId, (int) $product->id, $branchId);

            $previousQty = $stock->quantity;

            if ($request->type === 'add') {
                $newQty = $previousQty + $request->quantity;
                $movementType = InventoryMovement::TYPE_ADJUSTMENT_IN;
            } elseif ($request->type === 'remove') {
                $newQty = max(0, $previousQty - $request->quantity);
                $movementType = InventoryMovement::TYPE_ADJUSTMENT_OUT;
            } else {
                $newQty = $request->quantity;
                $movementType = $request->quantity > $previousQty
                    ? InventoryMovement::TYPE_ADJUSTMENT_IN
                    : InventoryMovement::TYPE_ADJUSTMENT_OUT;
            }

            $stockUpdate = ['quantity' => $newQty];

            if ($request->type === 'add' && $request->filled('purchase_price') && $request->purchase_price > 0) {
                $purchasePrice = (float) $request->purchase_price;
                if ($previousQty > 0 && $stock->avg_purchase_price > 0) {
                    $totalOldValue = $previousQty * $stock->avg_purchase_price;
                    $totalNewValue = $request->quantity * $purchasePrice;
                    $stockUpdate['avg_purchase_price'] = round(($totalOldValue + $totalNewValue) / $newQty, 2);
                } else {
                    $stockUpdate['avg_purchase_price'] = $purchasePrice;
                }
                $stockUpdate['last_purchase_price'] = $purchasePrice;
            }

            $stock->update($stockUpdate);

            // Mirror sync: keep pos_products.stock_quantity (shown on the
            // /pos/products page + sale-screen loaders) in step with the
            // inventory module's authoritative inventory_stocks.quantity.
            // With branches the mirror carries the COMPANY TOTAL, so it is
            // recomputed from every branch rather than set to this one.
            BranchStockService::syncProductMirror($companyId, (int) $product->id);

            InventoryMovement::create([
                'company_id' => $companyId,
                'product_id' => $product->id,
                'branch_id' => $branchId,
                'type' => $movementType,
                'quantity' => abs($newQty - $previousQty),
                'balance_after' => $newQty,
                'reference_type' => 'adjustment',
                'notes' => $request->reason . ($request->notes ? ' — ' . $request->notes : ''),
                'created_by' => auth('pos')->id(),
            ]);

            InventoryAdjustment::create([
                'company_id' => $companyId,
                'product_id' => $product->id,
                'type' => $request->type,
                'quantity' => $request->quantity,
                'previous_quantity' => $previousQty,
                'new_quantity' => $newQty,
                'reason' => $request->reason,
                'notes' => $request->notes,
                'created_by' => auth('pos')->id(),
            ]);

            DB::commit();
            $branchLabel = BranchStockService::branchName($companyId, $branchId);
            return redirect()->route('pos.inventory.stock')
                ->with('success', "Stock adjusted: {$product->name} — {$previousQty} → {$newQty}"
                    . ($branchLabel ? " ({$branchLabel})" : ''));
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Failed to adjust stock: ' . $e->getMessage());
        }
    }

    public function updateMinStock(Request $request)
    {
        [$companyId, $company] = $this->ensureInventoryEnabled();

        $request->validate([
            'product_id' => 'required|exists:pos_products,id',
            'min_stock_level' => 'required|numeric|min:0',
            'branch_id' => 'nullable|integer',
        ]);

        // Ownership check — PosProduct has no global company scope, so without
        // this a POS user could seed stock rows for another company's product.
        $product = PosProduct::where('company_id', $companyId)->findOrFail($request->product_id);

        // Alert thresholds are per branch — a big shop and a kiosk do not want
        // the same "kam ho gaya" line.
        $picked = $request->filled('branch_id') ? (int) $request->branch_id : null;
        $this->assertBranchAllowed($companyId, $picked);
        $branchId = BranchStockService::writeBranchId($companyId, $picked);
        $stock = BranchStockService::stockRow($companyId, (int) $product->id, $branchId);

        $stock->update(['min_stock_level' => $request->min_stock_level]);

        return response()->json(['success' => true, 'min_stock_level' => $stock->min_stock_level]);
    }

    /**
     * Branch-to-branch stock transfer (Task 1354) — "maal Main Shop se Gulberg
     * bhej do". Deliberately built on the existing movement ledger instead of a
     * new table: one TRANSFER_OUT at the source and one TRANSFER_IN at the
     * destination, paired by a shared reference number, so both branches'
     * Movements pages tell the same story and the company total never changes.
     */
    public function transfers(Request $request)
    {
        [$companyId, $company] = $this->ensureInventoryEnabled();
        $this->assertNotCashier();
        $branchView = $this->branchView($companyId);

        // Needs two shops THIS user may move stock between — a manager tied to
        // a single branch has nowhere to send it, so the page is not theirs.
        if (!$branchView['canTransfer']) {
            abort(403, __('pos.access_denied'));
        }

        // Everything below is limited to the user's own branches: the picker,
        // the availability map and the history all leak holdings otherwise.
        $branchIds = collect($branchView['branches'])->pluck('id')->map(fn ($id) => (int) $id)->all();

        $products = PosProduct::where('company_id', $companyId)
            ->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku', 'uom']);

        // Current holdings per branch for the picker — the user must see
        // what is actually available before choosing a quantity.
        $stockMap = [];
        foreach (InventoryStock::where('company_id', $companyId)->whereIn('branch_id', $branchIds)->get() as $row) {
            $stockMap[(int) $row->branch_id][(int) $row->product_id] = (float) $row->quantity;
        }

        // Schema-drift safe (Task 1434): on a PROD DB that lacks the in-transit
        // columns we simply have no in-transit list, and "recent" falls back to
        // every branch_transfer OUT row — exactly the old instant-transfer page.
        $columnsReady = $this->transferColumnsReady();

        // In-transit list: "raste mein pare transfers" — visible to BOTH ends.
        // A transfer is the user's if either the source branch or the
        // destination (reference_id) is one they may touch, so the sending shop
        // can cancel it and the receiving shop can confirm it.
        $inTransit = collect();
        if ($columnsReady) {
            $inTransit = InventoryMovement::where('company_id', $companyId)
                ->where('reference_type', 'branch_transfer')
                ->where('type', InventoryMovement::TYPE_TRANSFER_OUT)
                ->where('transfer_status', InventoryMovement::TRANSFER_IN_TRANSIT)
                ->where(function ($q) use ($branchIds) {
                    $q->whereIn('branch_id', $branchIds)
                        ->orWhereIn('reference_id', $branchIds);
                })
                ->with(['posProduct', 'creator', 'branch'])
                ->orderByDesc('created_at')
                ->get();
        }

        // Branches this user may RECEIVE into — drives which in-transit rows
        // show a "wasool ho gaya" button vs. a read-only "raste mein" badge.
        $receivableBranchIds = $branchIds;

        // History: transfers already received or cancelled (the OUT row still
        // holds the final state), so the old "recent transfers" table keeps
        // telling the same story once the maal has landed. Without the columns
        // it is every OUT row, as before.
        $recent = InventoryMovement::where('company_id', $companyId)
            ->where('reference_type', 'branch_transfer')
            ->where('type', InventoryMovement::TYPE_TRANSFER_OUT)
            ->when($columnsReady, fn ($q) => $q->whereIn('transfer_status', [
                InventoryMovement::TRANSFER_RECEIVED, InventoryMovement::TRANSFER_CANCELLED,
            ]))
            ->where(function ($q) use ($branchIds) {
                $q->whereIn('branch_id', $branchIds)
                    ->orWhereIn('reference_id', $branchIds);
            })
            ->with(['posProduct', 'creator', 'branch'])
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return view('pos.inventory.transfer', array_merge($branchView, compact(
            'company', 'products', 'stockMap', 'recent', 'inTransit', 'receivableBranchIds', 'columnsReady'
        )));
    }

    public function storeTransfer(Request $request)
    {
        [$companyId, $company] = $this->ensureInventoryEnabled();
        $this->assertNotCashier();

        $request->validate([
            'product_id' => 'required|exists:pos_products,id',
            'from_branch_id' => 'required|integer',
            'to_branch_id' => 'required|integer|different:from_branch_id',
            'quantity' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
        ]);

        $from = (int) $request->from_branch_id;
        $to = (int) $request->to_branch_id;

        // BOTH ends must be branches this user may touch — company ownership
        // alone would let a confined manager drain a branch that is not theirs.
        if (!BranchStockService::actorCanUse($companyId, $from) || !BranchStockService::actorCanUse($companyId, $to)) {
            return back()->withInput()->with('error', __('pos.transfer_branch_invalid'));
        }

        $product = PosProduct::where('company_id', $companyId)->findOrFail($request->product_id);
        $qty = round((float) $request->quantity, 3);
        // On a drifted PROD DB without the in-transit columns, we cannot record
        // "raste mein" state — degrade to the old instant transfer so the page
        // still works (see prod-schema-drift-selfheal).
        $columnsReady = $this->transferColumnsReady();

        try {
            $result = DB::transaction(function () use ($companyId, $product, $from, $to, $qty, $request, $columnsReady) {
                $source = BranchStockService::stockRow($companyId, (int) $product->id, $from);

                // A transfer can only move goods that exist — unlike a sale
                // (oversell is allowed there by design), sending stock a shop
                // does not have would invent inventory out of nothing.
                if ((float) $source->quantity < $qty) {
                    return ['error' => __('pos.transfer_not_enough_stock', [
                        'available' => rtrim(rtrim(number_format((float) $source->quantity, 2, '.', ''), '0'), '.'),
                    ])];
                }

                $reference = 'TRF-' . now()->format('ymdHis') . '-' . $product->id;
                $userId = auth('pos')->id();
                $note = trim(__('pos.transfer_movement_note', [
                    'from' => BranchStockService::branchName($companyId, $from) ?? '—',
                    'to' => BranchStockService::branchName($companyId, $to) ?? '—',
                ]) . ($request->filled('notes') ? ' — ' . $request->notes : ''));

                // In-transit transfers (Task 1434): sending ONLY removes the
                // maal from the source shelf. The destination's sellable stock
                // is NOT touched — those units are on the road and the
                // receiving cashier must not bill against goods that have not
                // arrived yet. Only when that branch clicks "wasool ho gaya"
                // (receiveTransfer) does the maal land in its inventory_stocks.
                $sourceQty = round((float) $source->quantity - $qty, 3);
                $source->update(['quantity' => $sourceQty]);

                // The lone TRANSFER_OUT row IS the transfer record: it carries
                // transfer_status=in_transit and the cost the goods travel with
                // (unit_price), so the receiving side can re-weight on arrival.
                // reference_id points at the DESTINATION branch, matching the
                // existing paired-ledger convention.
                $outData = [
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'branch_id' => $from,
                    'type' => InventoryMovement::TYPE_TRANSFER_OUT,
                    'quantity' => $qty,
                    'unit_price' => (float) $source->avg_purchase_price,
                    'total_price' => round($qty * (float) $source->avg_purchase_price, 2),
                    'balance_after' => $sourceQty,
                    'reference_type' => 'branch_transfer',
                    'reference_id' => $to,
                    'reference_number' => $reference,
                    'notes' => $note,
                    'created_by' => $userId,
                ];
                if ($columnsReady) {
                    $outData['transfer_status'] = InventoryMovement::TRANSFER_IN_TRANSIT;
                }
                InventoryMovement::create($outData);

                // Legacy fallback: no in-transit columns → keep the pre-Task
                // behaviour, crediting the destination immediately with a paired
                // TRANSFER_IN (cost re-weighted on arrival) so the page never
                // 500s on a database the migration has not reached.
                if (!$columnsReady) {
                    $destination = BranchStockService::stockRow($companyId, (int) $product->id, $to);
                    $destQtyBefore = (float) $destination->quantity;
                    $movedCost = (float) $source->avg_purchase_price;
                    $destQty = round($destQtyBefore + $qty, 3);
                    $destination->update([
                        'quantity' => $destQty,
                        'avg_purchase_price' => BranchStockService::blendCost(
                            $destQtyBefore, (float) $destination->avg_purchase_price, $qty, $movedCost
                        ),
                        'last_purchase_price' => $movedCost > 0
                            ? round($movedCost, 2)
                            : (float) $destination->last_purchase_price,
                    ]);
                    InventoryMovement::create([
                        'company_id' => $companyId,
                        'product_id' => $product->id,
                        'branch_id' => $to,
                        'type' => InventoryMovement::TYPE_TRANSFER_IN,
                        'quantity' => $qty,
                        'unit_price' => $movedCost,
                        'total_price' => round($qty * $movedCost, 2),
                        'balance_after' => $destQty,
                        'reference_type' => 'branch_transfer',
                        'reference_id' => $from,
                        'reference_number' => $reference,
                        'notes' => $note,
                        'created_by' => $userId,
                    ]);
                }

                // The maal left the source shelf but is still the company's:
                // syncProductMirror now counts in-transit qty, so the company
                // total (products page) stays whole while it is on the road.
                BranchStockService::syncProductMirror($companyId, (int) $product->id);

                return ['ok' => true];
            });
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', __('pos.transfer_failed', ['error' => $e->getMessage()]));
        }

        if (isset($result['error'])) {
            return back()->withInput()->with('error', $result['error']);
        }

        // Different confirmation depending on whether the maal is now on the
        // road (in-transit) or already landed (legacy fallback).
        return redirect()->route('pos.inventory.transfers')->with('success', __(
            $columnsReady ? 'pos.transfer_sent' : 'pos.transfer_done',
            [
                'qty' => rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.'),
                'product' => $product->name,
                'from' => BranchStockService::branchName($companyId, $from) ?? '—',
                'to' => BranchStockService::branchName($companyId, $to) ?? '—',
            ]
        ));
    }

    /**
     * Receive an in-transit transfer (Task 1434) — the destination branch
     * clicks "wasool ho gaya". ONLY now does the maal land in the receiving
     * branch's sellable stock. The actual quantity received may be LESS than
     * what was sent (goods lost or damaged on the road); the shortfall simply
     * never arrives anywhere, so the company total drops by exactly that gap
     * and the difference is visible on the ledger.
     */
    public function receiveTransfer(Request $request, int $movement)
    {
        [$companyId, $company] = $this->ensureInventoryEnabled();
        $this->assertNotCashier();

        // Without the columns there is no in-transit concept to receive.
        if (!$this->transferColumnsReady()) {
            return back()->with('error', __('pos.transfer_not_in_transit'));
        }

        $request->validate([
            'received_quantity' => 'nullable|numeric|min:0',
        ]);

        // Cheap unlocked read ONLY to resolve the branch for the 403/404 gate.
        // The authoritative state check happens under lockForUpdate below.
        $out = InventoryMovement::where('company_id', $companyId)
            ->where('reference_type', 'branch_transfer')
            ->where('type', InventoryMovement::TYPE_TRANSFER_OUT)
            ->where('id', $movement)
            ->first();

        if (!$out || !$out->isInTransit()) {
            return back()->with('error', __('pos.transfer_not_in_transit'));
        }

        $to = (int) $out->reference_id;   // destination branch (see storeTransfer)
        $from = (int) $out->branch_id;    // source branch

        // Receiving is the DESTINATION branch's action — only someone who may
        // touch that branch can pull the maal onto its shelf.
        if (!BranchStockService::actorCanUse($companyId, $to)) {
            abort(403, __('pos.access_denied'));
        }

        $rawReceived = $request->filled('received_quantity')
            ? round((float) $request->received_quantity, 3)
            : null;

        try {
            $result = DB::transaction(function () use ($companyId, $movement, $from, $to, $rawReceived) {
                // Single-consumption guard (khata ledger discipline): re-fetch
                // the row LOCKED and re-check its state INSIDE the transaction.
                // A concurrent receive/cancel that got here first has already
                // flipped transfer_status, so this one sees a non-transit row
                // and writes NOTHING — the status is not "last writer wins".
                $row = InventoryMovement::where('company_id', $companyId)
                    ->where('reference_type', 'branch_transfer')
                    ->where('type', InventoryMovement::TYPE_TRANSFER_OUT)
                    ->where('id', $movement)
                    ->lockForUpdate()
                    ->first();

                if (!$row || !$row->isInTransit()) {
                    return ['stale' => true];
                }

                $sent = (float) $row->quantity;
                // Blank = "sab kuch pohanch gaya"; otherwise the real arriving
                // figure, capped at what was sent (can't receive more than left).
                $received = $rawReceived === null ? $sent : min($sent, $rawReceived);

                // ONE guarded transition BEFORE any stock is touched: flip the
                // status conditionally on it still being in_transit. If a racer
                // slipped between the lock and here (it cannot, but belt-and-
                // braces), affected=0 aborts with nothing written.
                $flipped = InventoryMovement::where('id', $row->id)
                    ->where('transfer_status', InventoryMovement::TRANSFER_IN_TRANSIT)
                    ->update([
                        'transfer_status' => InventoryMovement::TRANSFER_RECEIVED,
                        'received_quantity' => $received,
                    ]);
                if ($flipped === 0) {
                    return ['stale' => true];
                }

                $destination = BranchStockService::stockRow($companyId, (int) $row->product_id, $to);

                // Cost travels WITH the goods: the destination's average is
                // re-weighted across what it already held and what just
                // arrived. Keeping its old rate would mis-value the maal and
                // every later sale there would snapshot the wrong cost.
                $destQtyBefore = (float) $destination->quantity;
                $movedCost = (float) $row->unit_price;
                $destQty = round($destQtyBefore + $received, 3);
                $destination->update([
                    'quantity' => $destQty,
                    'avg_purchase_price' => BranchStockService::blendCost(
                        $destQtyBefore, (float) $destination->avg_purchase_price, $received, $movedCost
                    ),
                    // These units are the most recent arrival on that shelf.
                    'last_purchase_price' => $movedCost > 0
                        ? round($movedCost, 2)
                        : (float) $destination->last_purchase_price,
                ]);

                // The paired TRANSFER_IN — both branches can explain the change,
                // exactly as the old instant-transfer did, just at receive time.
                $shortfall = round($sent - $received, 3);
                $note = trim(($row->notes ?? '') . ($shortfall > 0
                    ? ' — ' . __('pos.transfer_shortfall_note', [
                        'lost' => rtrim(rtrim(number_format($shortfall, 2, '.', ''), '0'), '.'),
                    ])
                    : ''));

                InventoryMovement::create([
                    'company_id' => $companyId,
                    'product_id' => $row->product_id,
                    'branch_id' => $to,
                    'type' => InventoryMovement::TYPE_TRANSFER_IN,
                    'quantity' => $received,
                    'unit_price' => $movedCost,
                    'total_price' => round($received * $movedCost, 2),
                    'balance_after' => $destQty,
                    'reference_type' => 'branch_transfer',
                    'reference_id' => $from,
                    'reference_number' => $row->reference_number,
                    'notes' => $note,
                    'created_by' => auth('pos')->id(),
                ]);

                // The in-transit qty for this product is now lower (this one
                // arrived); resync so the company total reflects the shortfall
                // (if any) as a genuine loss.
                BranchStockService::syncProductMirror($companyId, (int) $row->product_id);

                return ['received' => $received];
            });
        } catch (\Throwable $e) {
            return back()->with('error', __('pos.transfer_failed', ['error' => $e->getMessage()]));
        }

        if (!empty($result['stale'])) {
            return back()->with('error', __('pos.transfer_not_in_transit'));
        }

        return redirect()->route('pos.inventory.transfers')->with('success', __('pos.transfer_received_ok', [
            'qty' => rtrim(rtrim(number_format($result['received'], 2, '.', ''), '0'), '.'),
            'branch' => BranchStockService::branchName($companyId, $to) ?? '—',
        ]));
    }

    /**
     * Cancel an in-transit transfer (Task 1434) — the goods never left, or the
     * send was a mistake. The full sent quantity goes BACK to the source shelf
     * and the transfer is marked cancelled. Only possible while still in
     * transit: once received, a return is a fresh transfer the other way.
     */
    public function cancelTransfer(Request $request, int $movement)
    {
        [$companyId, $company] = $this->ensureInventoryEnabled();
        $this->assertNotCashier();

        // Without the columns there is no in-transit concept to cancel.
        if (!$this->transferColumnsReady()) {
            return back()->with('error', __('pos.transfer_not_in_transit'));
        }

        // Cheap unlocked read ONLY for the 403/404 gate; the authoritative
        // state check happens under lockForUpdate inside the transaction.
        $out = InventoryMovement::where('company_id', $companyId)
            ->where('reference_type', 'branch_transfer')
            ->where('type', InventoryMovement::TYPE_TRANSFER_OUT)
            ->where('id', $movement)
            ->first();

        if (!$out || !$out->isInTransit()) {
            return back()->with('error', __('pos.transfer_not_in_transit'));
        }

        $from = (int) $out->branch_id;   // goods return whence they came
        $to = (int) $out->reference_id;

        // Either end's keeper may call it off — the person holding the road
        // consignment or the shop that sent it. Both must be reachable.
        if (!BranchStockService::actorCanUse($companyId, $from) && !BranchStockService::actorCanUse($companyId, $to)) {
            abort(403, __('pos.access_denied'));
        }

        try {
            $result = DB::transaction(function () use ($companyId, $movement, $from) {
                // Single-consumption guard (khata ledger discipline): re-fetch
                // LOCKED and re-check state INSIDE the transaction. A receive
                // (or another cancel) that got here first already flipped the
                // status, so this call writes NOTHING and does not return the
                // goods a second time.
                $row = InventoryMovement::where('company_id', $companyId)
                    ->where('reference_type', 'branch_transfer')
                    ->where('type', InventoryMovement::TYPE_TRANSFER_OUT)
                    ->where('id', $movement)
                    ->lockForUpdate()
                    ->first();

                if (!$row || !$row->isInTransit()) {
                    return ['stale' => true];
                }

                // ONE guarded transition BEFORE any stock is touched.
                $flipped = InventoryMovement::where('id', $row->id)
                    ->where('transfer_status', InventoryMovement::TRANSFER_IN_TRANSIT)
                    ->update([
                        'transfer_status' => InventoryMovement::TRANSFER_CANCELLED,
                        'received_quantity' => 0,
                    ]);
                if ($flipped === 0) {
                    return ['stale' => true];
                }

                $qty = (float) $row->quantity;
                $source = BranchStockService::stockRow($companyId, (int) $row->product_id, $from);
                $sourceQty = round((float) $source->quantity + $qty, 3);
                $source->update(['quantity' => $sourceQty]);

                // A TRANSFER_IN back at the SOURCE closes the loop so its
                // ledger shows the maal returning, not just vanishing from
                // transit.
                InventoryMovement::create([
                    'company_id' => $companyId,
                    'product_id' => $row->product_id,
                    'branch_id' => $from,
                    'type' => InventoryMovement::TYPE_TRANSFER_IN,
                    'quantity' => $qty,
                    'unit_price' => (float) $row->unit_price,
                    'total_price' => round($qty * (float) $row->unit_price, 2),
                    'balance_after' => $sourceQty,
                    'reference_type' => 'branch_transfer',
                    'reference_id' => $from,
                    'reference_number' => $row->reference_number,
                    'notes' => trim(($row->notes ?? '') . ' — ' . __('pos.transfer_cancelled_note')),
                    'created_by' => auth('pos')->id(),
                ]);

                // In-transit qty for this product drops back to the source's
                // real stock; the company total is unchanged (goods returned).
                BranchStockService::syncProductMirror($companyId, (int) $row->product_id);

                return ['ok' => true];
            });
        } catch (\Throwable $e) {
            return back()->with('error', __('pos.transfer_failed', ['error' => $e->getMessage()]));
        }

        if (!empty($result['stale'])) {
            return back()->with('error', __('pos.transfer_not_in_transit'));
        }

        return redirect()->route('pos.inventory.transfers')->with('success', __('pos.transfer_cancelled_ok', [
            'branch' => BranchStockService::branchName($companyId, $from) ?? '—',
        ]));
    }

    public function toggleInventory(Request $request)
    {
        // Same refusal as the sibling switch on /pos/settings/inventory-toggle:
        // whether a shop tracks stock is an owner/manager decision, never a
        // cashier's.
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            abort(403, __('pos.only_admin_change_setting'));
        }

        $companyId = app('currentCompanyId');
        $company = Company::findOrFail($companyId);
        // The master column is only HALF the switch — the inventory feature
        // flag is the other half, and the next features save re-derives the
        // column from that map. Writing only the column silently reverted.
        $company->update(\App\Services\PosFeatureService::inventoryToggleUpdates(
            $company,
            !$company->inventory_enabled
        ));

        $status = $company->inventory_enabled ? 'enabled' : 'disabled';
        return back()->with('success', "Inventory module has been {$status}.");
    }

    /**
     * @param int|null $branchId Branch the BILL belongs to (Task 1354). Pass the
     *   transaction's own branch_id — never the session's — so a sale can only
     *   ever move the stock of the shop that made it. NULL keeps the pre-branch
     *   behaviour for companies that have no branches.
     */
    public static function deductStockForInvoice(int $companyId, array $items, int $transactionId, string $invoiceNumber, ?int $userId = null, ?int $branchId = null): array
    {
        $company = Company::find($companyId);
        if (!$company || !$company->inventory_enabled) {
            return ['skipped' => true, 'message' => 'Inventory not enabled'];
        }

        $branchId = BranchStockService::writeBranchId($companyId, $branchId);
        $warnings = [];
        $recipeItems = [];
        foreach ($items as $item) {
            if (($item['type'] ?? 'product') === 'product'
                && RecipeInventoryService::itemUsesRecipe($companyId, $item)) {
                $recipeItems[] = $item;
            }
        }
        if ($recipeItems) {
            try {
                // One shared path for retail POS and restaurant settlement. This
                // happens before direct-product deduction so a mixed cart remains
                // atomic from the kitchen ledger's point of view.
                RecipeInventoryService::consumeForInvoice(
                    $companyId, $recipeItems, $transactionId, $invoiceNumber, $userId, $branchId
                );
            } catch (\Throwable $e) {
                Log::error('Recipe inventory consumption failed', [
                    'company_id' => $companyId, 'transaction_id' => $transactionId, 'error' => $e->getMessage(),
                ]);
                return ['skipped' => false, 'warnings' => ['Kitchen stock could not be updated: ' . $e->getMessage()]];
            }
        }

        foreach ($items as $item) {
            if (($item['type'] ?? 'product') !== 'product' || empty($item['item_id'])) {
                continue;
            }

            $productId = (int) $item['item_id'];
            $qty = (float) ($item['quantity'] ?? 0);
            $dealDerived = !empty($item['_deal_derived']);
            if ($qty <= 0) continue;
            if (RecipeInventoryService::itemUsesRecipe($companyId, $item)) {
                // A recipe dish consumes ingredients, never a finished-product
                // stock row.  This also prevents the old restaurant path from
                // double-counting a dish after it uses the shared service.
                continue;
            }

            try {
                $stock = BranchStockService::stockRow($companyId, $productId, $branchId, false);

                if ($dealDerived && (!$stock || (float) $stock->quantity < $qty)) {
                    $productName = \App\Models\PosProduct::where('company_id', $companyId)
                        ->whereKey($productId)->value('name') ?? "Product #{$productId}";
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'items' => ["Insufficient stock for '{$productName}'."],
                    ]);
                }

                if (!$stock) {
                    // Company-scoped lookup — PosProduct has no global scope, so
                    // a tampered foreign item_id must NOT seed a stock row here.
                    $posProduct = \App\Models\PosProduct::where('company_id', $companyId)->find($productId);
                    if (!$posProduct) continue;

                    $stock = BranchStockService::stockRow($companyId, $productId, $branchId);
                }

                $previousQty = $stock->quantity;
                $newQty = $stock->quantity - $qty;

                if ($newQty < 0) {
                    $productName = \App\Models\PosProduct::where('company_id', $companyId)->find($productId)?->name ?? 'Unknown';
                    $warnings[] = "Low stock warning: {$productName} (Available: {$previousQty}, Sold: {$qty})";
                }

                $stock->update(['quantity' => $newQty]);

                // Mirror sync: pos_products.stock_quantity feeds the products
                // page + sale-screen loaders; keep it in step (atomic decrement,
                // only when the product actually tracks a quantity). The mirror
                // is the COMPANY TOTAL, and a sale lowers that total by exactly
                // the sold quantity no matter which branch it came out of — so
                // the atomic decrement stays correct with branches.
                \App\Models\PosProduct::where('id', $productId)
                    ->where('company_id', $companyId)
                    ->whereNotNull('stock_quantity')
                    ->decrement('stock_quantity', (int) round($qty));

                InventoryMovement::create([
                    'company_id' => $companyId,
                    'product_id' => $productId,
                    'branch_id' => $branchId,
                    'type' => InventoryMovement::TYPE_SALE,
                    'quantity' => $qty,
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                    'total_price' => round($qty * (float) ($item['unit_price'] ?? 0), 2),
                    'balance_after' => $newQty,
                    'reference_type' => 'pos_transaction',
                    'reference_id' => $transactionId,
                    'reference_number' => $invoiceNumber,
                    'notes' => 'POS sale deduction',
                    'created_by' => $userId,
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                throw $e;
            } catch (\Exception $e) {
                $productName = \App\Models\PosProduct::find($productId)?->name ?? "Product #{$productId}";
                $warnings[] = "Inventory update skipped for {$productName}: " . $e->getMessage();
                continue;
            }
        }

        return ['skipped' => false, 'warnings' => $warnings];
    }

    /**
     * Reverse a prior POS-sale deduction — adds the sold quantities back to
     * inventory when a bill is deleted/voided or its items are edited. Mirror of
     * deductStockForInvoice: same company-scoped, tamper-safe product lookup, and
     * it keeps pos_products.stock_quantity in lockstep with inventory_stocks.
     * No-ops when inventory tracking is off (nothing was ever deducted).
     */
    /**
     * @param int|null $branchId Branch the BILL belongs to (Task 1354) — the
     *   goods go back to the shop they left, not to whichever branch the person
     *   voiding the bill happens to be viewing.
     */
    public static function restoreStockForInvoice(int $companyId, array $items, int $transactionId, string $invoiceNumber, ?int $userId = null, string $referenceType = 'pos_void', ?int $branchId = null): array
    {
        $company = Company::find($companyId);
        if (!$company || !$company->inventory_enabled) {
            return ['skipped' => true, 'message' => 'Inventory not enabled'];
        }

        $branchId = BranchStockService::writeBranchId($companyId, $branchId);
        $warnings = [];
        // The sale ledger is the authoritative record of what direct product
        // stock actually left. This prevents enabling inventory later, changing
        // a recipe, or tampering with return lines from minting stock.
        $deductedDirect = InventoryMovement::where('company_id', $companyId)
            ->where('reference_type', 'pos_transaction')
            ->where('reference_id', $transactionId)
            ->where('type', InventoryMovement::TYPE_SALE)
            ->selectRaw('product_id, SUM(quantity) as quantity')
            ->groupBy('product_id')->pluck('quantity', 'product_id')
            // Legacy deductions stored a negative movement quantity; current
            // paths store the positive magnitude. Both mean stock actually left.
            ->map(fn ($qty) => abs((float) $qty))->all();
        try {
            RecipeInventoryService::reverseForInvoice(
                $companyId, $transactionId, $branchId, $userId, $referenceType
            );
        } catch (\Throwable $e) {
            Log::error('Recipe inventory reversal failed', [
                'company_id' => $companyId, 'transaction_id' => $transactionId, 'error' => $e->getMessage(),
            ]);
            $warnings[] = 'Kitchen stock could not be restored: ' . $e->getMessage();
        }

        foreach ($items as $item) {
            if (($item['type'] ?? 'product') !== 'product' || empty($item['item_id'])) {
                continue;
            }

            $productId = (int) $item['item_id'];
            $qty = (float) ($item['quantity'] ?? 0);
            if ($qty <= 0) continue;
            if (!array_key_exists($productId, $deductedDirect)) {
                continue;
            }
            $qty = min($qty, max(0, (float) $deductedDirect[$productId]));
            $deductedDirect[$productId] = round((float) $deductedDirect[$productId] - $qty, 4);
            if ($qty <= 0) continue;

            try {
                $stock = BranchStockService::stockRow($companyId, $productId, $branchId, false);

                if (!$stock) {
                    // Company-scoped lookup — PosProduct has no global scope, so
                    // a tampered foreign item_id must NOT seed a stock row here.
                    $posProduct = \App\Models\PosProduct::where('company_id', $companyId)->find($productId);
                    if (!$posProduct) continue;

                    $stock = BranchStockService::stockRow($companyId, $productId, $branchId);
                }

                $newQty = $stock->quantity + $qty;
                $stock->update(['quantity' => $newQty]);

                // Mirror sync: pos_products.stock_quantity feeds the products
                // page + sale-screen loaders; keep it in step (atomic increment,
                // only when the product actually tracks a quantity). The mirror
                // holds the company total, which moves by the same delta.
                \App\Models\PosProduct::where('id', $productId)
                    ->where('company_id', $companyId)
                    ->whereNotNull('stock_quantity')
                    ->increment('stock_quantity', (int) round($qty));

                InventoryMovement::create([
                    'company_id' => $companyId,
                    'product_id' => $productId,
                    'branch_id' => $branchId,
                    'type' => InventoryMovement::TYPE_RETURN_IN,
                    'quantity' => $qty,
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                    'total_price' => round($qty * (float) ($item['unit_price'] ?? 0), 2),
                    'balance_after' => $newQty,
                    'reference_type' => $referenceType,
                    'reference_id' => $transactionId,
                    'reference_number' => $invoiceNumber,
                    'notes' => 'Stock restored (bill void/edit)',
                    'created_by' => $userId,
                ]);
            } catch (\Exception $e) {
                $productName = \App\Models\PosProduct::find($productId)?->name ?? "Product #{$productId}";
                $warnings[] = "Inventory restore skipped for {$productName}: " . $e->getMessage();
                continue;
            }
        }

        if ($deductedDirect) {
            InventoryMovement::where('company_id', $companyId)
                ->where('reference_type', 'pos_transaction')
                ->where('reference_id', $transactionId)
                ->where('type', InventoryMovement::TYPE_SALE)
                ->update(['reference_type' => $referenceType]);
        }

        return ['skipped' => false, 'warnings' => $warnings];
    }
}
