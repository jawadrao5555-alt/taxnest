<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\BranchStockService;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * FBR POS Stock / Purchase / Supplier module (Aug 2026 — Retail Core).
 * Reuses the shared inventory infrastructure (inventory_stocks, suppliers,
 * purchase_orders, InventoryService) that the DI panel built — products live
 * in the shared `products` table so the native product() relation works.
 *
 * Design decisions:
 *  - Stock tracking is gated on companies.inventory_enabled (single switch
 *    for FBR POS — the PRA POS dual-switch trap does not apply here).
 *  - Sales are NEVER blocked by stock; negative quantities show red.
 *  - Purchase entry = one-shot "stock received" (PO created as RECEIVED and
 *    stock added immediately) — small retailers don't do draft/ordered flows.
 */
class FbrPosStockController extends Controller
{
    private function user() { return Auth::guard('fbrpos')->user(); }
    private function companyId(): int { return (int) $this->user()->company_id; }

    /**
     * Stock & purchase is owner/manager territory — cashiers and viewers must
     * not toggle tracking, receive stock, or edit suppliers/min levels.
     */
    private function assertNotCashier(): void
    {
        $u = $this->user();
        if (in_array($u->pos_role ?? '', ['pos_cashier', 'local_viewer'], true)) {
            abort(403, 'Sirf admin/manager stock manage kar sakte hain.');
        }
    }

    /**
     * Per-branch stock (Task 1365) — FBR POS now keys every stock row by
     * branch exactly like PRA POS does. Before anything is read or written,
     * make sure no pre-branch (branch-less) row is stranded: those goods
     * belong to the head office. Cheap and idempotent — see
     * BranchStockService::healLegacyRows().
     */
    private function healBranchStock(int $companyId): void
    {
        BranchStockService::healLegacyRows($companyId);
    }

    /**
     * View-model shared by the stock pages: which branch is on screen, its
     * name, and the branch list for the picker / "sab branches" view.
     */
    private function branchView(int $companyId): array
    {
        $branches = BranchStockService::branches($companyId);
        $activeBranchId = BranchStockService::viewBranchId($companyId);

        return [
            // Pickers only ever offer branches THIS user may touch; the
            // company-wide list stays behind branchNames (labels) so an
            // owner's all-branches view can still name every shop.
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
     * confined to Gulberg could receive stock into Main Shop.
     */
    private function assertBranchAllowed(int $companyId, ?int $branchId): void
    {
        if ($branchId !== null && !BranchStockService::actorCanUse($companyId, $branchId)) {
            abort(403, __('pos.access_denied'));
        }
    }

    /** Branch-scope every stock query the same (STRICT) way. */
    private function scopeBranch($query, int $companyId)
    {
        return BranchStockService::applyViewFilter($query, $companyId);
    }

    /**
     * Task 1365: purchase_orders has NO branch column — a purchase belongs to
     * whichever branch its PURCHASE movements landed in. Scope the history
     * list to the branch on screen so a manager confined to one shop can
     * neither see nor void another shop's purchase.
     *
     * NULL viewBranchId = single-shop company, or the owner's all-branches
     * view; both legitimately see everything.
     */
    private function scopePurchaseBranch($query, int $companyId)
    {
        $branchId = BranchStockService::viewBranchId($companyId);
        if (!$branchId) {
            return $query;
        }

        return $query->whereExists(function ($sub) use ($companyId, $branchId) {
            $sub->selectRaw('1')
                ->from('inventory_movements')
                ->whereColumn('inventory_movements.reference_id', 'purchase_orders.id')
                ->where('inventory_movements.company_id', $companyId)
                ->where('inventory_movements.reference_type', 'purchase_order')
                ->where('inventory_movements.type', InventoryMovement::TYPE_PURCHASE)
                ->where('inventory_movements.branch_id', $branchId);
        });
    }

    /**
     * The ONE branch a purchase's goods actually went into, read back from its
     * PURCHASE movements, plus the permission check that must pass before any
     * of it is reversed.
     *
     * Returns ['ok' => true, 'branch' => ?int] or ['ok' => false, 'error' => string].
     *
     * A purchase whose movements straddle two branches is refused rather than
     * guessed: reversing it would deduct one shop's stock to unwind another's.
     */
    private function resolvePurchaseBranch(int $companyId, PurchaseOrder $po): array
    {
        // Single-shop company — branch stays NULL exactly as before.
        if (!BranchStockService::isMultiBranch($companyId)) {
            return ['ok' => true, 'branch' => null];
        }

        $branchIds = InventoryMovement::where('company_id', $companyId)
            ->where('type', InventoryMovement::TYPE_PURCHASE)
            ->where('reference_type', 'purchase_order')
            ->where('reference_id', $po->id)
            ->distinct()
            ->pluck('branch_id')
            ->map(fn ($b) => $b === null ? null : (int) $b)
            ->unique()
            ->values();

        if ($branchIds->count() > 1) {
            return ['ok' => false, 'error' => __('pos.stock_pur_void_branch_mixed')];
        }

        // No movement to read (nothing was ever put in, or the rows are gone):
        // fall back to the branch on screen, and refuse to guess from the
        // owner's all-branches view.
        $branchId = $branchIds->first();
        if ($branchId === null) {
            if (BranchStockService::viewingAllBranches($companyId)) {
                return ['ok' => false, 'error' => __('pos.stock_edit_pick_branch')];
            }
            $branchId = BranchStockService::viewBranchId($companyId);
        }

        if (!BranchStockService::actorCanUse($companyId, $branchId)) {
            return ['ok' => false, 'error' => __('pos.branch_switch_denied')];
        }

        return ['ok' => true, 'branch' => (int) $branchId];
    }

    /**
     * Which branch a stock WRITE from the stock page lands in: the explicitly
     * picked shop, else the one being viewed. NULL only for a company with no
     * branches (single-shop, unchanged behaviour).
     *
     * On the owner's all-branches view there is no single answer — callers
     * must refuse (viewingAllBranches) instead of letting this silently fall
     * back to head office.
     */
    private function writeBranch(int $companyId, ?int $picked = null): ?int
    {
        return BranchStockService::writeBranchId(
            $companyId,
            $picked ?? BranchStockService::viewBranchId($companyId)
        );
    }

    public function index()
    {
        $this->assertNotCashier();
        $companyId = $this->companyId();
        $company = Company::find($companyId);

        $this->healBranchStock($companyId);
        $branchView = $this->branchView($companyId);

        $products = Product::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'barcode', 'uom', 'default_price']);

        // One row per product on screen. On a single branch that is exactly
        // that branch's row; on the owner's all-branches view the branch rows
        // are summed (STRICT filter — no NULL rows sneak in and double-count).
        $stocks = [];
        $stockQuery = $this->scopeBranch(InventoryStock::where('company_id', $companyId), $companyId);
        foreach ($stockQuery->orderByDesc('updated_at')->get() as $row) {
            $pid = (int) $row->product_id;
            if (!isset($stocks[$pid])) {
                $stocks[$pid] = [
                    'quantity' => 0.0,
                    'min_stock_level' => 0.0,
                    // Rate shown company-wide = the most recently updated
                    // branch row that actually has one (rows come newest first).
                    'last_purchase_price' => 0.0,
                ];
            }
            $stocks[$pid]['quantity'] += (float) $row->quantity;
            $stocks[$pid]['min_stock_level'] = max($stocks[$pid]['min_stock_level'], (float) $row->min_stock_level);
            if ($stocks[$pid]['last_purchase_price'] <= 0) {
                $stocks[$pid]['last_purchase_price'] = (float) $row->last_purchase_price;
            }
        }

        $rows = $products->map(function ($p) use ($stocks) {
            $s = $stocks[(int) $p->id] ?? null;
            return (object) [
                'product_id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'uom' => $p->uom ?: 'U',
                'default_price' => (float) $p->default_price,
                'quantity' => $s ? round($s['quantity'], 3) : 0.0,
                'min_stock_level' => $s ? $s['min_stock_level'] : 0.0,
                'last_purchase_price' => $s ? $s['last_purchase_price'] : 0.0,
                'tracked' => (bool) $s,
            ];
        });

        $lowStock = $rows->filter(fn ($r) => $r->tracked && $r->min_stock_level > 0 && $r->quantity <= $r->min_stock_level)->values();
        $negative = $rows->filter(fn ($r) => $r->quantity < 0)->values();

        // withCount decides Delete vs Deactivate per row (history must stay intact).
        $suppliers = Supplier::forCompany($companyId)->withCount('purchaseOrders')->orderBy('name')->get();

        // First page of purchase history (page size + 1 to detect "has more");
        // the blade renders these via Alpine and fetches older/searched pages
        // from purchases() below. items.product eager-loaded — live runs with
        // strict lazy-loading, every relation the serializer reads must be
        // covered by with().
        $recentPurchases = $this->scopePurchaseBranch(
                PurchaseOrder::where('company_id', $companyId), $companyId
            )
            ->with('supplier:id,name', 'items.product:id,name')
            ->orderByDesc('id')
            ->limit(self::PURCHASES_PER_PAGE + 1)
            ->get();
        $purchasesHasMore = $recentPurchases->count() > self::PURCHASES_PER_PAGE;
        $recentPurchases = $recentPurchases->take(self::PURCHASES_PER_PAGE);

        // First page of the company-wide Recent Corrections list (adjustment
        // movements across all products) — same bake-first-page pattern as
        // Recent Purchases; older pages come from corrections() below.
        $recentCorrections = $this->correctionsQuery($companyId)
            ->limit(self::CORRECTIONS_PER_PAGE + 1)
            ->get();
        $correctionsHasMore = $recentCorrections->count() > self::CORRECTIONS_PER_PAGE;
        $recentCorrections = $recentCorrections->take(self::CORRECTIONS_PER_PAGE);

        return view('fbr-pos.stock', array_merge($branchView, [
            'company' => $company,
            'recentCorrectionsData' => $this->serializeCorrections($recentCorrections),
            'correctionsHasMore' => $correctionsHasMore,
            'rows' => $rows,
            'lowStock' => $lowStock,
            'negative' => $negative,
            'suppliers' => $suppliers,
            'recentPurchasesData' => $this->serializePurchases($recentPurchases),
            'purchasesHasMore' => $purchasesHasMore,
            'stockEnabled' => (bool) $company->inventory_enabled,
        ]));
    }

    /** Page size for the Recent Purchases list (initial render + search/load-more). */
    private const PURCHASES_PER_PAGE = 15;

    /**
     * Search + paginate the company's full purchase history (JSON).
     * Matches purchase number, supplier name, or product name — server-side,
     * so the page never bakes thousands of rows.
     */
    public function purchases(Request $request)
    {
        $this->assertNotCashier();
        $companyId = $this->companyId();
        // Standalone AJAX endpoint: a legacy branch-less purchase movement must
        // be adopted onto head office BEFORE the branch filter runs, or the
        // purchase would simply vanish from the list.
        $this->healBranchStock($companyId);

        $data = $request->validate([
            'q' => 'nullable|string|max:100',
            'date' => 'nullable|date_format:Y-m-d',
            'supplier_id' => 'nullable|integer|min:1',
            'page' => 'nullable|integer|min:1|max:100000',
        ]);
        $q = trim((string) ($data['q'] ?? ''));
        $page = max(1, (int) ($data['page'] ?? 1));

        // Task 1365: the history follows the branch on screen — a manager
        // pinned to Gulberg must not even see Main Shop's purchases (they are
        // voidable from this list).
        $query = $this->scopePurchaseBranch(
            PurchaseOrder::where('company_id', $companyId), $companyId
        )->with('supplier:id,name', 'items.product:id,name');

        // Optional exact supplier filter (Task 488) — company-scoped lookup so
        // a foreign supplier_id 404s instead of silently matching nothing.
        if (!empty($data['supplier_id'])) {
            $supplier = Supplier::forCompany($companyId)->findOrFail($data['supplier_id']);
            $query->where('supplier_id', $supplier->id);
        }

        // Optional single-day filter (Task 469) — same range predicate (not
        // whereDate) as movements()/correctionsQuery() so a created_at index
        // stays usable. Matches received_date too (the date the list shows)
        // in case an old row's received day differs from its entry day.
        if (!empty($data['date'])) {
            $day = \Illuminate\Support\Carbon::parse($data['date'], config('app.timezone'));
            $query->where(function ($w) use ($day, $data) {
                $w->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
                  ->orWhere('received_date', $data['date']);
            });
        }

        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $query->where(function ($w) use ($like) {
                $w->where('po_number', 'like', $like)
                  ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $like))
                  ->orWhereHas('items.product', fn ($p) => $p->where('name', 'like', $like));
            });
        }

        $rows = $query->orderByDesc('id')
            ->skip(($page - 1) * self::PURCHASES_PER_PAGE)
            ->take(self::PURCHASES_PER_PAGE + 1)
            ->get();

        $hasMore = $rows->count() > self::PURCHASES_PER_PAGE;

        return response()->json([
            'purchases' => $this->serializePurchases($rows->take(self::PURCHASES_PER_PAGE)),
            'has_more' => $hasMore,
            'page' => $page,
        ]);
    }

    /**
     * One shape for both the baked first page and the JSON endpoint, so the
     * blade has a single Alpine rendering path. Numbers pre-formatted here;
     * ids cast to int (live PDO returns string ints).
     */
    private function serializePurchases($purchases): array
    {
        $trimQty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');

        return $purchases->map(fn ($po) => [
            'id' => (int) $po->id,
            'po_number' => (string) $po->po_number,
            'date' => ($po->received_date ?? $po->created_at)?->format('d M Y'),
            'supplier' => $po->supplier?->name,
            'total' => number_format((float) $po->total_amount, 2),
            'voided' => $po->status === PurchaseOrder::STATUS_CANCELLED,
            'can_void' => $po->status === PurchaseOrder::STATUS_RECEIVED,
            'items' => $po->items->map(fn ($it) => [
                'name' => $it->product?->name ?? ('#' . $it->product_id),
                'qty' => $trimQty($it->quantity),
            ])->values()->all(),
        ])->values()->all();
    }

    /** Page size for the per-product movement history modal. */
    private const MOVEMENTS_PER_PAGE = 20;

    /**
     * Per-product stock movement history (JSON) — Task 425.
     * Lets the shopkeeper audit corrections: every inventory_movements row
     * (purchase received, sold, adjustment in/out, returns, opening) with
     * date, delta, running balance, the optional reason note and who did it.
     * Owner/manager only (same gate as the rest of this module).
     */
    public function movements(Request $request)
    {
        $this->assertNotCashier();
        $companyId = $this->companyId();
        // Standalone AJAX endpoint — heal here too, so a direct hit never
        // reads a branch-filtered history while legacy rows are still NULL.
        $this->healBranchStock($companyId);

        $data = $request->validate([
            'product_id' => 'required|integer',
            'date' => 'nullable|date_format:Y-m-d',
            'page' => 'nullable|integer|min:1|max:100000',
        ]);
        $page = max(1, (int) ($data['page'] ?? 1));

        // Company scope enforced through the product lookup — a foreign
        // product_id 404s before any movement row is read.
        $product = Product::where('company_id', $companyId)->findOrFail($data['product_id']);

        // Task 1365: the history follows the branch on screen — a manager
        // looking at Gulberg must not see Main Shop's movements.
        $query = $this->scopeBranch(
            InventoryMovement::where('company_id', $companyId)->where('product_id', $product->id),
            $companyId
        )->with('creator:id,name')->orderByDesc('id');

        // Optional single-day filter (Task 465) — same range predicate (not
        // whereDate) as correctionsQuery() so an index on created_at stays usable.
        if (!empty($data['date'])) {
            $day = \Illuminate\Support\Carbon::parse($data['date'], config('app.timezone'));
            $query->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);
        }

        $rows = $query
            ->skip(($page - 1) * self::MOVEMENTS_PER_PAGE)
            ->take(self::MOVEMENTS_PER_PAGE + 1)
            ->get();

        $hasMore = $rows->count() > self::MOVEMENTS_PER_PAGE;
        $trimQty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');

        $movements = $rows->take(self::MOVEMENTS_PER_PAGE)->map(fn ($m) => [
            'id' => (int) $m->id,
            'date' => $m->created_at?->format('d M Y') ?? '',
            'time' => $m->created_at?->format('h:i A') ?? '',
            'type' => (string) $m->type,
            'in' => $m->isIncoming(),
            'qty' => $trimQty($m->quantity),
            'balance' => $m->balance_after !== null ? $trimQty($m->balance_after) : null,
            'ref' => $m->reference_number ?: null,
            'notes' => $m->notes ?: null,
            'by' => $m->creator?->name,
        ])->values()->all();

        return response()->json([
            'movements' => $movements,
            'has_more' => $hasMore,
            'page' => $page,
        ]);
    }

    /** Page size for the company-wide Recent Corrections list. */
    private const CORRECTIONS_PER_PAGE = 15;

    /**
     * Company-wide recent stock corrections (Task 447) — every manual
     * adjustment_in / adjustment_out movement across ALL products, newest
     * first, so the shopkeeper can audit the whole shop in one list instead
     * of opening the per-product History modal product by product.
     * Owner/manager only (same gate as the rest of this module).
     */
    public function corrections(Request $request)
    {
        $this->assertNotCashier();
        $companyId = $this->companyId();
        $this->healBranchStock($companyId);

        $data = $request->validate([
            'q' => 'nullable|string|max:100',
            'date' => 'nullable|date_format:Y-m-d',
            'page' => 'nullable|integer|min:1|max:100000',
        ]);
        $page = max(1, (int) ($data['page'] ?? 1));

        $rows = $this->correctionsQuery($companyId, trim((string) ($data['q'] ?? '')), $data['date'] ?? null)
            ->skip(($page - 1) * self::CORRECTIONS_PER_PAGE)
            ->take(self::CORRECTIONS_PER_PAGE + 1)
            ->get();

        $hasMore = $rows->count() > self::CORRECTIONS_PER_PAGE;

        return response()->json([
            'corrections' => $this->serializeCorrections($rows->take(self::CORRECTIONS_PER_PAGE)),
            'has_more' => $hasMore,
            'page' => $page,
        ]);
    }

    /**
     * Shared base query for the baked first page and the JSON endpoint.
     * Optional server-side filters (Task 459): product-name search + a single
     * calendar day — so "what happened to Sugar last Tuesday" is one query,
     * not a page-through of the whole history.
     */
    private function correctionsQuery(int $companyId, string $q = '', ?string $date = null)
    {
        // product + creator eager-loaded — live runs with strict lazy-loading,
        // every relation the serializer reads must be covered by with().
        // Task 1365: branch-scoped like every other stock read.
        $query = $this->scopeBranch(
            InventoryMovement::where('company_id', $companyId)
                ->whereIn('type', [InventoryMovement::TYPE_ADJUSTMENT_IN, InventoryMovement::TYPE_ADJUSTMENT_OUT]),
            $companyId
        )->with('product:id,name', 'creator:id,name')->orderByDesc('id');

        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $query->whereHas('product', fn ($p) => $p->where('name', 'like', $like));
        }

        if ($date) {
            // Range predicate (not whereDate) so an index on created_at stays usable.
            $day = \Illuminate\Support\Carbon::parse($date, config('app.timezone'));
            $query->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);
        }

        return $query;
    }

    /** One shape for the baked first page and the JSON endpoint. */
    private function serializeCorrections($rows): array
    {
        $trimQty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');

        return $rows->map(fn ($m) => [
            'id' => (int) $m->id,
            'date' => $m->created_at?->format('d M Y') ?? '',
            'time' => $m->created_at?->format('h:i A') ?? '',
            'product' => $m->product?->name ?? ('#' . $m->product_id),
            'type' => (string) $m->type,
            'in' => $m->isIncoming(),
            'qty' => $trimQty($m->quantity),
            'balance' => $m->balance_after !== null ? $trimQty($m->balance_after) : null,
            'notes' => $m->notes ?: null,
            'by' => $m->creator?->name,
        ])->values()->all();
    }

    /** Toggle stock tracking for the company (owner/admin action). */
    public function toggle(Request $request)
    {
        $this->assertNotCashier();
        $company = Company::find($this->companyId());
        $company->update(['inventory_enabled' => (bool) $request->boolean('enabled')]);
        return redirect()->route('fbrpos.stock')
            ->with('success', $company->inventory_enabled ? 'Stock tracking ON ho gaya.' : 'Stock tracking OFF ho gaya.');
    }

    public function storeSupplier(Request $request)
    {
        $this->assertNotCashier();
        $request->validate([
            'name' => 'required|string|max:150',
            'phone' => 'nullable|string|max:30',
            'city' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:300',
        ]);

        Supplier::create([
            'company_id' => $this->companyId(),
            'name' => $request->name,
            'phone' => $request->phone,
            'city' => $request->city,
            'notes' => $request->notes,
            'is_active' => true,
        ]);

        return redirect()->route('fbrpos.stock')->with('success', 'Supplier add ho gaya: ' . $request->name);
    }

    /** Edit a supplier's basic fields (same fields as the add form). */
    public function updateSupplier(Request $request, $id)
    {
        $this->assertNotCashier();
        $supplier = Supplier::forCompany($this->companyId())->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:150',
            'phone' => 'nullable|string|max:30',
            'city' => 'nullable|string|max:80',
        ]);

        $supplier->update([
            'name' => $request->name,
            'phone' => $request->phone,
            'city' => $request->city,
        ]);

        return redirect()->route('fbrpos.stock')
            ->with('success', __('pos.stock_sup_updated', ['name' => $supplier->name]));
    }

    /**
     * Delete a supplier — hard-delete ONLY when it has no purchase history.
     * Suppliers table is shared with the DI panel; a supplier referenced by
     * purchase orders is deactivated instead (hidden from the purchase-entry
     * dropdown, still shown on old purchase rows, reactivatable).
     */
    public function deleteSupplier($id)
    {
        $this->assertNotCashier();
        $supplier = Supplier::forCompany($this->companyId())->findOrFail($id);

        if ($supplier->purchaseOrders()->exists()) {
            $supplier->update(['is_active' => false]);
            return redirect()->route('fbrpos.stock')
                ->with('success', __('pos.stock_sup_deactivated', ['name' => $supplier->name]));
        }

        $name = $supplier->name;
        $supplier->delete();

        return redirect()->route('fbrpos.stock')
            ->with('success', __('pos.stock_sup_deleted', ['name' => $name]));
    }

    /** Bring a deactivated supplier back into the purchase-entry dropdown. */
    public function reactivateSupplier($id)
    {
        $this->assertNotCashier();
        $supplier = Supplier::forCompany($this->companyId())->findOrFail($id);
        $supplier->update(['is_active' => true]);

        return redirect()->route('fbrpos.stock')
            ->with('success', __('pos.stock_sup_reactivated', ['name' => $supplier->name]));
    }

    /**
     * One-shot purchase entry: stock received from a supplier.
     * Creates a RECEIVED purchase order + adds stock via InventoryService
     * (movement rows + avg/last purchase price maintained automatically).
     */
    public function storePurchase(Request $request)
    {
        $this->assertNotCashier();
        $request->validate([
            'supplier_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_price' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:300',
        ]);

        $companyId = $this->companyId();
        $this->healBranchStock($companyId);

        // Task 1365: received goods land in ONE shop. The form posts the
        // branch explicitly (multi-branch companies); otherwise the branch
        // being viewed is used. A single-shop company resolves to NULL and
        // behaves exactly as before.
        $picked = $request->filled('branch_id') ? (int) $request->branch_id : null;
        $this->assertBranchAllowed($companyId, $picked);
        if ($picked === null && BranchStockService::viewingAllBranches($companyId)) {
            // Owner on the "sab branches" view — head office would be a silent
            // guess, so ask which shop received the maal instead.
            return back()->withInput()->with('error', __('pos.stock_edit_pick_branch'));
        }
        $branchId = $this->writeBranch($companyId, $picked);

        $supplier = null;
        if ($request->supplier_id) {
            $supplier = Supplier::forCompany($companyId)->find($request->supplier_id);
        }

        // Validate all products belong to this company BEFORE writing anything.
        $productIds = collect($request->items)->pluck('product_id')->map(fn ($v) => (int) $v)->unique();
        $validIds = Product::where('company_id', $companyId)->whereIn('id', $productIds)->pluck('id')->all();
        $invalid = $productIds->diff($validIds);
        if ($invalid->isNotEmpty()) {
            return back()->with('error', 'Ghalat product select hua — dobara koshish karein.');
        }

        $po = DB::transaction(function () use ($request, $companyId, $supplier, $branchId) {
            $total = 0;
            foreach ($request->items as $row) {
                $total += round((float) $row['quantity'] * (float) $row['unit_price'], 2);
            }

            $po = PurchaseOrder::create([
                'company_id' => $companyId,
                'supplier_id' => $supplier?->id,
                'po_number' => 'PUR-' . date('ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(4)),
                'status' => PurchaseOrder::STATUS_RECEIVED,
                'order_date' => now()->toDateString(),
                'received_date' => now()->toDateString(),
                'total_amount' => $total,
                'notes' => $request->notes,
                'created_by' => $this->user()->id,
            ]);

            foreach ($request->items as $row) {
                $qty = (float) $row['quantity'];
                $price = (float) $row['unit_price'];
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => (int) $row['product_id'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'total_price' => round($qty * $price, 2),
                    'received_quantity' => $qty,
                ]);

                InventoryService::addStock(
                    $companyId,
                    (int) $row['product_id'],
                    $qty,
                    $price,
                    InventoryMovement::TYPE_PURCHASE,
                    $branchId,
                    ['type' => 'purchase_order', 'id' => $po->id, 'number' => $po->po_number],
                    null,
                    $this->user()->id
                );
            }

            return $po;
        });

        return redirect()->route('fbrpos.stock')
            ->with('success', "Stock receive ho gaya — {$po->po_number} (Rs " . number_format($po->total_amount, 2) . ")");
    }

    /**
     * Void a received purchase (Task 419) — the "galat stock receive" fix.
     * Admin/manager only (same gate), confirm handled client-side.
     *
     * Reverses each line's received stock as a return_out movement and rolls
     * back the kharid rates via InventoryService::reversePurchase (see its
     * docblock for the avg-price approach — un-weight the running average,
     * fall back to the most recent OTHER purchase price when degenerate).
     * The PO row is kept (status=cancelled) so the audit trail stays intact.
     */
    public function voidPurchase($id)
    {
        $this->assertNotCashier();
        $companyId = $this->companyId();
        $this->healBranchStock($companyId);
        $po = PurchaseOrder::where('company_id', $companyId)->with('items')->findOrFail($id);

        if ($po->status === PurchaseOrder::STATUS_CANCELLED) {
            return redirect()->route('fbrpos.stock')->with('error', __('pos.stock_pur_void_already'));
        }
        // Only fully RECEIVED purchases can be voided — this table is shared
        // with the DI panel's PO workflow, where draft/ordered/partial rows
        // have NOT put (all) their stock in yet. Reversing those would deduct
        // stock that was never added.
        if ($po->status !== PurchaseOrder::STATUS_RECEIVED) {
            return redirect()->route('fbrpos.stock')->with('error', __('pos.stock_pur_void_not_received'));
        }

        // Task 1365: resolve — and AUTHORIZE — the branch this purchase's goods
        // actually went into BEFORE anything is mutated. Without this a manager
        // confined to one shop could void another shop's purchase and deduct
        // its stock. Voiding also has to unwind the goods where they landed:
        // the session's branch may be a different shop by now, which would
        // invent stock there and leave a hole in the real one.
        $resolved = $this->resolvePurchaseBranch($companyId, $po);
        if (!$resolved['ok']) {
            return redirect()->route('fbrpos.stock')->with('error', $resolved['error']);
        }
        $branchId = $resolved['branch'];

        DB::transaction(function () use ($po, $companyId, $branchId) {
            // Row lock + re-check — double-submit must not reverse stock twice.
            $locked = PurchaseOrder::lockForUpdate()->find($po->id);
            if (!$locked || $locked->status !== PurchaseOrder::STATUS_RECEIVED) {
                return;
            }

            foreach ($po->items as $item) {
                // Only what was ACTUALLY received ever hit the stock — never
                // fall back to the ordered quantity.
                $qty = (float) $item->received_quantity;
                if ($qty <= 0) {
                    continue;
                }

                // Most recent STILL-VALID purchase price — the value the
                // last/avg kharid rolls back to when this purchase set them.
                // Movements of previously-voided POs stay in history, so a
                // PO-referenced movement only qualifies while its PO is still
                // RECEIVED (never this one, never a cancelled one). NULL =
                // no valid prior purchase → reversePurchase resets to 0.
                // Task 1365: the rate must come from THIS branch's own purchase
                // history — another shop's kharid rate is not this shelf's cost.
                $fallback = InventoryMovement::where('inventory_movements.company_id', $companyId)
                    ->where('inventory_movements.product_id', $item->product_id)
                    ->where('inventory_movements.type', InventoryMovement::TYPE_PURCHASE)
                    ->where(function ($b) use ($branchId) {
                        return $branchId
                            ? $b->where('inventory_movements.branch_id', $branchId)
                            : $b->whereNull('inventory_movements.branch_id');
                    })
                    ->where(function ($w) use ($po) {
                        $w->where(function ($q) use ($po) {
                            $q->where('reference_type', 'purchase_order')
                              ->where('reference_id', '!=', $po->id)
                              ->whereExists(function ($sub) {
                                  $sub->selectRaw('1')
                                      ->from('purchase_orders')
                                      ->whereColumn('purchase_orders.id', 'inventory_movements.reference_id')
                                      ->where('purchase_orders.status', PurchaseOrder::STATUS_RECEIVED);
                              });
                        })
                        ->orWhere('reference_type', '!=', 'purchase_order')
                        ->orWhereNull('reference_type');
                    })
                    ->orderByDesc('id')
                    ->value('unit_price');

                InventoryService::reversePurchase(
                    $companyId,
                    (int) $item->product_id,
                    $qty,
                    (float) $item->unit_price,
                    $branchId,
                    ['type' => 'purchase_void', 'id' => $po->id, 'number' => $po->po_number],
                    'Purchase void — ' . $po->po_number,
                    $this->user()->id,
                    $fallback !== null ? (float) $fallback : null
                );
            }

            $locked->update(['status' => PurchaseOrder::STATUS_CANCELLED]);
        });

        return redirect()->route('fbrpos.stock')
            ->with('success', __('pos.stock_pur_voided_msg', ['number' => $po->po_number]));
    }

    /** Inline update of a product's min stock level (alert threshold). */
    public function updateMinLevel(Request $request)
    {
        $this->assertNotCashier();
        $request->validate([
            'product_id' => 'required|integer',
            'min_stock_level' => 'required|numeric|min:0',
        ]);

        $companyId = $this->companyId();
        $product = Product::where('company_id', $companyId)->findOrFail($request->product_id);
        $this->healBranchStock($companyId);

        // Task 1365: the alert threshold belongs to a branch's shelf, not to
        // the whole company — on the "sab branches" view there is no single
        // row to write, so the page disables the input and this refuses.
        if (BranchStockService::viewingAllBranches($companyId)) {
            return response()->json(['success' => false, 'message' => __('pos.stock_edit_pick_branch')], 422);
        }

        $stock = BranchStockService::stockRow($companyId, (int) $product->id, $this->writeBranch($companyId));
        $stock->update(['min_stock_level' => (float) $request->min_stock_level]);

        return response()->json(['success' => true]);
    }

    /**
     * Per-row quick edit from the Stock List modal (Task 416):
     * sale price + unit → shared products table (same fields the full product
     * form writes — the sale screen's baked catalog refreshes via the boot
     * fingerprint because products.updated_at moves);
     * quantity correction → NEVER a raw column overwrite: the delta is booked
     * through InventoryService as an adjustment_in/out movement (audit trail),
     * so the row, stat tiles and low-stock alerts all follow;
     * kharid-rate correction → inventory_stocks purchase-price fields ONLY.
     *
     * PROFIT-FREEZE RULE (owner decision): a kharid-rate edit changes the cost
     * frozen onto FUTURE bills only. It must never touch any sold line's
     * stored cost_price — past bills' profit stays exactly as reported.
     *
     * kharid_rate / new_quantity apply only when they differ from the *_orig
     * hidden fields (what the modal was opened with) — an untouched field on
     * re-save never rewrites avg_purchase_price or books a zero adjustment.
     */
    public function updateItem(Request $request)
    {
        $this->assertNotCashier();
        $request->validate([
            'product_id' => 'required|integer',
            // Same rules the full product-update path applies to these fields.
            'default_price' => 'required|numeric|min:0',
            'uom' => 'nullable|string|max:20',
            'kharid_rate' => 'nullable|numeric|min:0',
            'kharid_rate_orig' => 'nullable|numeric',
            'new_quantity' => 'nullable|numeric|min:-9999999|max:9999999',
            'quantity_orig' => 'nullable|numeric',
            'qty_reason' => 'nullable|string|max:200',
        ]);

        $companyId = $this->companyId();
        $product = Product::where('company_id', $companyId)->findOrFail($request->product_id);
        $this->healBranchStock($companyId);

        // Task 1365: sale price / unit stay company-wide (same item everywhere),
        // but kharid rate and the quantity correction live on ONE branch's
        // shelf. The all-branches view has no single row to write, so those two
        // are skipped with a clear message instead of guessing head office.
        $stockBranchId = $this->writeBranch($companyId);
        $branchAmbiguous = BranchStockService::viewingAllBranches($companyId);
        $skippedStockEdit = false;

        $changedAny = false;

        // ── Sale price / unit (products table — Eloquent only saves dirty
        // attributes, so an unchanged form does not touch updated_at / the
        // sale-screen boot fingerprint).
        $product->fill([
            'default_price' => round((float) $request->default_price, 2),
            'uom' => strtoupper(trim((string) ($request->uom ?: $product->uom ?: 'U'))),
        ]);
        if ($product->isDirty()) {
            $product->save();
            $changedAny = true;
        }

        // ── Kharid rate (purchase-price fields only — the cost snapshot at
        // sale time reads avg first, last as fallback, so both are set).
        if ($request->filled('kharid_rate')) {
            $rate = round((float) $request->kharid_rate, 2);
            $rateOrig = $request->filled('kharid_rate_orig') ? round((float) $request->kharid_rate_orig, 2) : null;
            if ($rateOrig === null || abs($rate - $rateOrig) > 0.009) {
                if ($branchAmbiguous) {
                    $skippedStockEdit = true;
                } else {
                    $stock = BranchStockService::stockRow($companyId, (int) $product->id, $stockBranchId);
                    $stock->update(['avg_purchase_price' => $rate, 'last_purchase_price' => $rate]);
                    $changedAny = true;
                }
            }
        }

        // ── Quantity correction (adjustment movement, never an overwrite).
        if ($request->filled('new_quantity')) {
            $newQty = round((float) $request->new_quantity, 3);
            $qtyOrig = $request->filled('quantity_orig') ? round((float) $request->quantity_orig, 3) : null;
            if ($qtyOrig === null || abs($newQty - $qtyOrig) > 0.0005) {
                if ($branchAmbiguous) {
                    $skippedStockEdit = true;
                } else {
                    $reason = trim((string) ($request->qty_reason ?? ''));
                    $note = 'Stock correction (quick edit)' . ($reason !== '' ? ' — ' . $reason : '');
                    DB::transaction(function () use ($companyId, $product, $newQty, $note, $stockBranchId, &$changedAny) {
                        $stock = BranchStockService::stockRow($companyId, (int) $product->id, $stockBranchId);
                        $delta = round($newQty - (float) $stock->quantity, 3);
                        if (abs($delta) < 0.0005) {
                            return;
                        }
                        $ref = ['type' => 'stock_quick_edit', 'id' => $product->id, 'number' => null];
                        if ($delta > 0) {
                            InventoryService::addStock($companyId, $product->id, $delta, 0,
                                InventoryMovement::TYPE_ADJUSTMENT_IN, $stockBranchId, $ref, $note, $this->user()->id);
                        } else {
                            InventoryService::deductStock($companyId, $product->id, abs($delta), 0,
                                InventoryMovement::TYPE_ADJUSTMENT_OUT, $stockBranchId, $ref, $note, $this->user()->id);
                        }
                        $changedAny = true;
                    });
                }
            }
        }

        if ($skippedStockEdit) {
            return redirect()->route('fbrpos.stock')->with('error', __('pos.stock_edit_pick_branch'));
        }

        return redirect()->route('fbrpos.stock')->with('success', $changedAny
            ? __('pos.stock_item_updated', ['name' => $product->name])
            : __('pos.stock_item_no_change', ['name' => $product->name]));
    }

    /**
     * Branch-to-branch stock transfer (Task 1365 — FBR port of the PRA POS
     * page). Moves goods from one shop to another; the ledger keeps a paired
     * TRANSFER_OUT / TRANSFER_IN so both branches tell the same story.
     */
    public function transfers()
    {
        $this->assertNotCashier();
        $companyId = $this->companyId();
        $company = Company::find($companyId);
        $this->healBranchStock($companyId);
        $branchView = $this->branchView($companyId);

        // Needs two shops THIS user may move stock between — a manager tied to
        // a single branch has nowhere to send it, so the page is not theirs.
        if (!$branchView['canTransfer']) {
            abort(403, __('pos.access_denied'));
        }

        // Everything below is limited to the user's own branches: the picker,
        // the availability map and the history all leak holdings otherwise.
        $branchIds = collect($branchView['branches'])->pluck('id')->map(fn ($id) => (int) $id)->all();

        $products = Product::where('company_id', $companyId)
            ->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku', 'uom']);

        // Current holdings per branch for the picker — the user must see what
        // is actually available before choosing a quantity.
        $stockMap = [];
        foreach (InventoryStock::where('company_id', $companyId)->whereIn('branch_id', $branchIds)->get() as $row) {
            $stockMap[(int) $row->branch_id][(int) $row->product_id] = (float) $row->quantity;
        }

        $recent = InventoryMovement::where('company_id', $companyId)
            ->where('reference_type', 'branch_transfer')
            ->where('type', InventoryMovement::TYPE_TRANSFER_OUT)
            ->whereIn('branch_id', $branchIds)
            ->with(['product:id,name', 'creator:id,name'])
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return view('fbr-pos.stock-transfer', array_merge($branchView, compact(
            'company', 'products', 'stockMap', 'recent'
        )));
    }

    public function storeTransfer(Request $request)
    {
        $this->assertNotCashier();
        $companyId = $this->companyId();
        $this->healBranchStock($companyId);

        $request->validate([
            'product_id' => 'required|integer',
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

        $product = Product::where('company_id', $companyId)->findOrFail($request->product_id);
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
                $userId = Auth::guard('fbrpos')->id();
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

                return ['ok' => true];
            });
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', __('pos.transfer_failed', ['error' => $e->getMessage()]));
        }

        if (isset($result['error'])) {
            return back()->withInput()->with('error', $result['error']);
        }

        return redirect()->route('fbrpos.stock.transfers')->with('success', __('pos.transfer_done', [
            'qty' => rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.'),
            'product' => $product->name,
            'from' => BranchStockService::branchName($companyId, $from) ?? '—',
            'to' => BranchStockService::branchName($companyId, $to) ?? '—',
        ]));
    }

    /**
     * Munafa (profit) report — Aug 2026.
     * Product-wise: sale value (ex-tax, net of item discounts) minus purchase
     * cost. Cost basis = the cost_price SNAPSHOT frozen on each sold line at
     * sale time — ONLY (Task 416, owner decision): pre-snapshot lines (no
     * stored cost) are cost-unknown, EXCLUDED from munafa totals and surfaced
     * via a "cost record nahi" count, so a later kharid-rate edit can never
     * retro-change reported profit. Returns subtract.
     */
    public function munafa(Request $request)
    {
        $this->assertNotCashier();
        $companyId = $this->companyId();

        $from = $request->input('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->input('to') ?: now()->toDateString();
        try {
            $fromDt = \Carbon\Carbon::parse($from)->startOfDay();
            $toDt = \Carbon\Carbon::parse($to)->endOfDay();
        } catch (\Throwable $e) {
            $fromDt = now()->startOfMonth();
            $toDt = now()->endOfDay();
            $from = $fromDt->toDateString();
            $to = $toDt->toDateString();
        }
        if ($fromDt->gt($toDt)) {
            [$fromDt, $toDt] = [$toDt->copy()->startOfDay(), $fromDt->copy()->endOfDay()];
            [$from, $to] = [$to, $from];
        }
        // Cap range at 1 year — protects prod DB from unbounded full-history scans.
        if ($fromDt->diffInDays($toDt) > 366) {
            $fromDt = $toDt->copy()->subDays(366)->startOfDay();
        }

        $sign = "CASE WHEN t.transaction_type = 'return' THEN -1 ELSE 1 END";

        // FROZEN COST ONLY (Task 416): no live-rate fallback. A line either
        // carries its sale-time cost snapshot or it is cost-unknown — its sale
        // value stays visible but never enters the profit math.
        $rows = DB::table('fbr_pos_transaction_items as i')
            ->join('fbr_pos_transactions as t', 't.id', '=', 'i.transaction_id')
            ->where('t.company_id', $companyId)
            ->where('t.status', 'completed')
            ->whereBetween('t.created_at', [$fromDt, $toDt])
            ->groupBy('i.product_id', 'i.item_name')
            ->selectRaw("
                i.product_id,
                i.item_name,
                SUM(({$sign}) * i.quantity) as qty,
                SUM(({$sign}) * i.subtotal) as sale_value,
                SUM(CASE WHEN i.cost_price IS NOT NULL THEN ({$sign}) * i.quantity * i.cost_price ELSE 0 END) as cost_value,
                SUM(CASE WHEN i.cost_price IS NOT NULL THEN ({$sign}) * i.subtotal ELSE 0 END) as costed_sale_value,
                SUM(CASE WHEN i.cost_price IS NOT NULL THEN 1 ELSE 0 END) as costed_lines,
                SUM(CASE WHEN i.cost_price IS NULL THEN 1 ELSE 0 END) as unknown_lines
            ")
            ->orderByDesc('sale_value')
            ->get()
            ->map(function ($r) {
                $r->qty = round((float) $r->qty, 3);
                $r->sale_value = round((float) $r->sale_value, 2);
                $r->cost_value = round((float) $r->cost_value, 2);
                $r->costed_sale_value = round((float) $r->costed_sale_value, 2);
                $r->costed_lines = (int) $r->costed_lines;
                $r->unknown_lines = (int) $r->unknown_lines;
                $r->cost_unknown = $r->unknown_lines > 0;
                // Profit only over the costed portion; fully-unknown rows have none.
                $r->profit = $r->costed_lines > 0 ? round($r->costed_sale_value - $r->cost_value, 2) : null;
                $r->margin = ($r->profit !== null && $r->costed_sale_value > 0)
                    ? round($r->profit / $r->costed_sale_value * 100, 1) : null;
                return $r;
            })
            ->filter(fn ($r) => abs($r->qty) > 0.0001 || abs($r->sale_value) > 0.009)
            ->values();

        // Header-level reductions (bill discounts + loyalty point redemptions)
        // reduce what was actually collected — net profit subtracts their
        // signed sums for the range. Product rows stay gross of these (they
        // are bill-level, not attributable to a single line).
        $header = \App\Models\FbrPosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$fromDt, $toDt])
            ->selectRaw("
                COALESCE(SUM(CASE WHEN transaction_type = 'return' THEN -discount_amount ELSE discount_amount END), 0) as d,
                COALESCE(SUM(CASE WHEN transaction_type = 'return' THEN -loyalty_redemption_amount ELSE loyalty_redemption_amount END), 0) as l
            ")
            ->first();
        $billDiscounts = round((float) ($header->d ?? 0), 2);
        $loyaltyRedemptions = round((float) ($header->l ?? 0), 2);

        $revenue = round($rows->sum('sale_value'), 2);
        $costedRevenue = round($rows->sum('costed_sale_value'), 2);
        $cost = round($rows->sum('cost_value'), 2);
        // Gross profit over COSTED lines only — cost-unknown sale value is
        // excluded (never estimated from the current rate).
        $grossProfit = round($costedRevenue - $cost, 2);
        $netProfit = round($grossProfit - $billDiscounts - $loyaltyRedemptions, 2);
        $unknownCount = $rows->where('cost_unknown', true)->count();
        $unknownLines = (int) $rows->sum('unknown_lines');
        $unknownSaleValue = round($revenue - $costedRevenue, 2);
        // TRUE when at least one sold line in the period carries a frozen cost
        // snapshot (costed_lines > 0).  Used by the blade to distinguish:
        //   anyCostedLines = false → "all-unknown" first-time setup banner
        //   anyCostedLines = true  → "partial exclusion" amber box
        // Derived from the line count (not signed revenue) so a costed
        // sale + matching costed return that nets costedRevenue to zero is
        // still treated as "some lines costed" — the partial box is shown.
        $anyCostedLines = (int) $rows->sum('costed_lines') > 0;

        return view('fbr-pos.munafa', [
            'rows' => $rows,
            'from' => $fromDt->toDateString(),
            'to' => $toDt->toDateString(),
            'revenue' => $revenue,
            'costedRevenue' => $costedRevenue,
            'cost' => $cost,
            'grossProfit' => $grossProfit,
            'billDiscounts' => $billDiscounts,
            'loyaltyRedemptions' => $loyaltyRedemptions,
            'netProfit' => $netProfit,
            'unknownCount' => $unknownCount,
            'unknownLines' => $unknownLines,
            'unknownSaleValue' => $unknownSaleValue,
            'anyCostedLines' => $anyCostedLines,
        ]);
    }
}
