<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PosProduct;
use App\Models\InventoryStock;
use App\Models\InventoryMovement;
use App\Models\InventoryAdjustment;
use App\Services\BranchStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $recent = InventoryMovement::where('company_id', $companyId)
            ->where('reference_type', 'branch_transfer')
            ->where('type', InventoryMovement::TYPE_TRANSFER_OUT)
            ->whereIn('branch_id', $branchIds)
            ->with(['posProduct', 'creator', 'branch'])
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return view('pos.inventory.transfer', array_merge($branchView, compact(
            'company', 'products', 'stockMap', 'recent'
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

        try {
            $result = DB::transaction(function () use ($companyId, $product, $from, $to, $qty, $request) {
                $source = BranchStockService::stockRow($companyId, (int) $product->id, $from);

                // A transfer can only move goods that exist — unlike a sale
                // (oversell is allowed there by design), sending stock a shop
                // does not have would invent inventory out of nothing.
                if ((float) $source->quantity < $qty) {
                    return ['error' => __('pos.transfer_not_enough_stock', [
                        'available' => rtrim(rtrim(number_format((float) $source->quantity, 2, '.', ''), '0'), '.'),
                    ])];
                }

                $destination = BranchStockService::stockRow($companyId, (int) $product->id, $to);

                $reference = 'TRF-' . now()->format('ymdHis') . '-' . $product->id;
                $userId = auth('pos')->id();
                $note = trim(__('pos.transfer_movement_note', [
                    'from' => BranchStockService::branchName($companyId, $from) ?? '—',
                    'to' => BranchStockService::branchName($companyId, $to) ?? '—',
                ]) . ($request->filled('notes') ? ' — ' . $request->notes : ''));

                $sourceQty = round((float) $source->quantity - $qty, 3);
                $source->update(['quantity' => $sourceQty]);

                // Cost travels WITH the goods: the destination's average is
                // re-weighted across what it already held and what just
                // arrived. Keeping its old rate would mis-value the maal and
                // every later sale there would snapshot the wrong cost.
                $destQtyBefore = (float) $destination->quantity;
                $movedCost = (float) $source->avg_purchase_price;
                $destQty = round($destQtyBefore + $qty, 3);
                $destination->update([
                    'quantity' => $destQty,
                    'avg_purchase_price' => BranchStockService::blendCost(
                        $destQtyBefore, (float) $destination->avg_purchase_price, $qty, $movedCost
                    ),
                    // These units are the most recent arrival on that shelf.
                    'last_purchase_price' => $movedCost > 0
                        ? round($movedCost, 2)
                        : (float) $destination->last_purchase_price,
                ]);

                foreach ([
                    [InventoryMovement::TYPE_TRANSFER_OUT, $from, $to, $sourceQty],
                    [InventoryMovement::TYPE_TRANSFER_IN, $to, $from, $destQty],
                ] as [$type, $branchId, $otherBranchId, $balance]) {
                    InventoryMovement::create([
                        'company_id' => $companyId,
                        'product_id' => $product->id,
                        'branch_id' => $branchId,
                        'type' => $type,
                        'quantity' => $qty,
                        'unit_price' => (float) $source->avg_purchase_price,
                        'total_price' => round($qty * (float) $source->avg_purchase_price, 2),
                        'balance_after' => $balance,
                        'reference_type' => 'branch_transfer',
                        'reference_id' => $otherBranchId,
                        'reference_number' => $reference,
                        'notes' => $note,
                        'created_by' => $userId,
                    ]);
                }

                // Company total is unchanged by a transfer, but the mirror is
                // resynced anyway so a pre-existing drift heals here too.
                BranchStockService::syncProductMirror($companyId, (int) $product->id);

                return ['ok' => true];
            });
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', __('pos.transfer_failed', ['error' => $e->getMessage()]));
        }

        if (isset($result['error'])) {
            return back()->withInput()->with('error', $result['error']);
        }

        return redirect()->route('pos.inventory.transfers')->with('success', __('pos.transfer_done', [
            'qty' => rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.'),
            'product' => $product->name,
            'from' => BranchStockService::branchName($companyId, $from) ?? '—',
            'to' => BranchStockService::branchName($companyId, $to) ?? '—',
        ]));
    }

    public function toggleInventory(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::findOrFail($companyId);
        $company->update(['inventory_enabled' => !$company->inventory_enabled]);

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

        foreach ($items as $item) {
            if (($item['type'] ?? 'product') !== 'product' || empty($item['item_id'])) {
                continue;
            }

            $productId = (int) $item['item_id'];
            $qty = (float) ($item['quantity'] ?? 0);
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

        foreach ($items as $item) {
            if (($item['type'] ?? 'product') !== 'product' || empty($item['item_id'])) {
                continue;
            }

            $productId = (int) $item['item_id'];
            $qty = (float) ($item['quantity'] ?? 0);
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

        return ['skipped' => false, 'warnings' => $warnings];
    }
}
