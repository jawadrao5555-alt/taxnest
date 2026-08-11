<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
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

    public function index()
    {
        $this->assertNotCashier();
        $companyId = $this->companyId();
        $company = Company::find($companyId);

        $products = Product::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'barcode', 'uom', 'default_price']);

        $stocks = InventoryStock::where('company_id', $companyId)
            ->whereNull('branch_id')
            ->get()
            ->keyBy('product_id');

        $rows = $products->map(function ($p) use ($stocks) {
            $s = $stocks->get($p->id);
            return (object) [
                'product_id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'uom' => $p->uom ?: 'U',
                'default_price' => (float) $p->default_price,
                'quantity' => $s ? (float) $s->quantity : 0.0,
                'min_stock_level' => $s ? (float) $s->min_stock_level : 0.0,
                'last_purchase_price' => $s ? (float) $s->last_purchase_price : 0.0,
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
        $recentPurchases = PurchaseOrder::where('company_id', $companyId)
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

        return view('fbr-pos.stock', [
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
        ]);
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

        $data = $request->validate([
            'q' => 'nullable|string|max:100',
            'date' => 'nullable|date_format:Y-m-d',
            'page' => 'nullable|integer|min:1|max:100000',
        ]);
        $q = trim((string) ($data['q'] ?? ''));
        $page = max(1, (int) ($data['page'] ?? 1));

        $query = PurchaseOrder::where('company_id', $companyId)
            ->with('supplier:id,name', 'items.product:id,name');

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

        $data = $request->validate([
            'product_id' => 'required|integer',
            'date' => 'nullable|date_format:Y-m-d',
            'page' => 'nullable|integer|min:1|max:100000',
        ]);
        $page = max(1, (int) ($data['page'] ?? 1));

        // Company scope enforced through the product lookup — a foreign
        // product_id 404s before any movement row is read.
        $product = Product::where('company_id', $companyId)->findOrFail($data['product_id']);

        $query = InventoryMovement::where('company_id', $companyId)
            ->where('product_id', $product->id)
            ->with('creator:id,name')
            ->orderByDesc('id');

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
        $query = InventoryMovement::where('company_id', $companyId)
            ->whereIn('type', [InventoryMovement::TYPE_ADJUSTMENT_IN, InventoryMovement::TYPE_ADJUSTMENT_OUT])
            ->with('product:id,name', 'creator:id,name')
            ->orderByDesc('id');

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
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_price' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:300',
        ]);

        $companyId = $this->companyId();

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

        $po = DB::transaction(function () use ($request, $companyId, $supplier) {
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
                    null,
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

        DB::transaction(function () use ($po, $companyId) {
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
                $fallback = InventoryMovement::where('inventory_movements.company_id', $companyId)
                    ->where('inventory_movements.product_id', $item->product_id)
                    ->where('inventory_movements.type', InventoryMovement::TYPE_PURCHASE)
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
                    null,
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

        $stock = InventoryStock::firstOrCreate(
            ['company_id' => $companyId, 'product_id' => $product->id, 'branch_id' => null],
            ['quantity' => 0, 'min_stock_level' => 0, 'avg_purchase_price' => 0, 'last_purchase_price' => 0]
        );
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
                $stock = InventoryStock::firstOrCreate(
                    ['company_id' => $companyId, 'product_id' => $product->id, 'branch_id' => null],
                    ['quantity' => 0, 'min_stock_level' => 0, 'avg_purchase_price' => 0, 'last_purchase_price' => 0]
                );
                $stock->update(['avg_purchase_price' => $rate, 'last_purchase_price' => $rate]);
                $changedAny = true;
            }
        }

        // ── Quantity correction (adjustment movement, never an overwrite).
        if ($request->filled('new_quantity')) {
            $newQty = round((float) $request->new_quantity, 3);
            $qtyOrig = $request->filled('quantity_orig') ? round((float) $request->quantity_orig, 3) : null;
            if ($qtyOrig === null || abs($newQty - $qtyOrig) > 0.0005) {
                $reason = trim((string) ($request->qty_reason ?? ''));
                $note = 'Stock correction (quick edit)' . ($reason !== '' ? ' — ' . $reason : '');
                DB::transaction(function () use ($companyId, $product, $newQty, $note, &$changedAny) {
                    $stock = InventoryStock::lockForUpdate()->firstOrCreate(
                        ['company_id' => $companyId, 'product_id' => $product->id, 'branch_id' => null],
                        ['quantity' => 0, 'min_stock_level' => 0, 'avg_purchase_price' => 0, 'last_purchase_price' => 0]
                    );
                    $delta = round($newQty - (float) $stock->quantity, 3);
                    if (abs($delta) < 0.0005) {
                        return;
                    }
                    $ref = ['type' => 'stock_quick_edit', 'id' => $product->id, 'number' => null];
                    if ($delta > 0) {
                        InventoryService::addStock($companyId, $product->id, $delta, 0,
                            InventoryMovement::TYPE_ADJUSTMENT_IN, null, $ref, $note, $this->user()->id);
                    } else {
                        InventoryService::deductStock($companyId, $product->id, abs($delta), 0,
                            InventoryMovement::TYPE_ADJUSTMENT_OUT, null, $ref, $note, $this->user()->id);
                    }
                    $changedAny = true;
                });
            }
        }

        return redirect()->route('fbrpos.stock')->with('success', $changedAny
            ? __('pos.stock_item_updated', ['name' => $product->name])
            : __('pos.stock_item_no_change', ['name' => $product->name]));
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
