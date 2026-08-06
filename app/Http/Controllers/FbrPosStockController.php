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
                'uom' => $p->uom ?: 'U',
                'quantity' => $s ? (float) $s->quantity : 0.0,
                'min_stock_level' => $s ? (float) $s->min_stock_level : 0.0,
                'last_purchase_price' => $s ? (float) $s->last_purchase_price : 0.0,
                'tracked' => (bool) $s,
            ];
        });

        $lowStock = $rows->filter(fn ($r) => $r->tracked && $r->min_stock_level > 0 && $r->quantity <= $r->min_stock_level)->values();
        $negative = $rows->filter(fn ($r) => $r->quantity < 0)->values();

        $suppliers = Supplier::forCompany($companyId)->orderBy('name')->get();

        $recentPurchases = PurchaseOrder::where('company_id', $companyId)
            ->with('supplier:id,name', 'items')
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        return view('fbr-pos.stock', [
            'company' => $company,
            'rows' => $rows,
            'lowStock' => $lowStock,
            'negative' => $negative,
            'suppliers' => $suppliers,
            'recentPurchases' => $recentPurchases,
            'stockEnabled' => (bool) $company->inventory_enabled,
        ]);
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
}
