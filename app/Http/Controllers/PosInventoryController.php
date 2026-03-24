<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Product;
use App\Models\InventoryStock;
use App\Models\InventoryMovement;
use App\Models\InventoryAdjustment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosInventoryController extends Controller
{
    private function ensureInventoryEnabled()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company || !$company->inventory_enabled) {
            abort(403, 'Inventory module is not enabled for this company.');
        }
        return [$companyId, $company];
    }

    public function dashboard()
    {
        [$companyId, $company] = $this->ensureInventoryEnabled();

        $totalProducts = Product::where('company_id', $companyId)->where('is_active', true)->count();
        $totalStockValue = InventoryStock::where('company_id', $companyId)
            ->selectRaw('COALESCE(SUM(quantity * avg_purchase_price), 0) as value')
            ->value('value');

        $lowStockItems = InventoryStock::where('company_id', $companyId)
            ->lowStock()
            ->with('product')
            ->get();

        $outOfStockCount = InventoryStock::where('company_id', $companyId)
            ->where('quantity', '<=', 0)
            ->count();

        $recentMovements = InventoryMovement::where('company_id', $companyId)
            ->with(['product', 'creator'])
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        $topMovers = InventoryMovement::where('company_id', $companyId)
            ->where('type', 'sale')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('product_id, SUM(quantity) as total_sold')
            ->groupBy('product_id')
            ->orderByDesc('total_sold')
            ->take(5)
            ->with('product')
            ->get();

        return view('pos.inventory.dashboard', compact(
            'company', 'totalProducts', 'totalStockValue', 'lowStockItems',
            'outOfStockCount', 'recentMovements', 'topMovers'
        ));
    }

    public function stock(Request $request)
    {
        [$companyId, $company] = $this->ensureInventoryEnabled();

        $query = InventoryStock::where('company_id', $companyId)->with('product');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%");
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

        return view('pos.inventory.stock', compact('company', 'stocks'));
    }

    public function movements(Request $request)
    {
        [$companyId, $company] = $this->ensureInventoryEnabled();

        $query = InventoryMovement::where('company_id', $companyId)->with(['product', 'creator']);

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
        $products = Product::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();

        return view('pos.inventory.movements', compact('company', 'movements', 'products'));
    }

    public function lowStockAlerts()
    {
        [$companyId, $company] = $this->ensureInventoryEnabled();

        $alerts = InventoryStock::where('company_id', $companyId)
            ->lowStock()
            ->with('product')
            ->orderByRaw('quantity - min_stock_level ASC')
            ->get();

        $outOfStock = InventoryStock::where('company_id', $companyId)
            ->where('quantity', '<=', 0)
            ->with('product')
            ->get();

        return view('pos.inventory.low-stock', compact('company', 'alerts', 'outOfStock'));
    }

    public function adjustStock(Request $request)
    {
        [$companyId, $company] = $this->ensureInventoryEnabled();

        if ($request->isMethod('get')) {
            $products = Product::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();
            return view('pos.inventory.adjust-stock', compact('company', 'products'));
        }

        $request->validate([
            'product_id' => 'required|exists:products,id',
            'type' => 'required|in:add,remove,set',
            'quantity' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string|max:500',
            'purchase_price' => 'nullable|numeric|min:0',
        ]);

        $product = Product::where('company_id', $companyId)->findOrFail($request->product_id);

        DB::beginTransaction();
        try {
            $stock = InventoryStock::firstOrCreate(
                ['company_id' => $companyId, 'product_id' => $product->id, 'branch_id' => null],
                ['quantity' => 0, 'min_stock_level' => 0, 'avg_purchase_price' => 0, 'last_purchase_price' => 0]
            );

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

            InventoryMovement::create([
                'company_id' => $companyId,
                'product_id' => $product->id,
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
            return redirect()->route('pos.inventory.stock')
                ->with('success', "Stock adjusted: {$product->name} — {$previousQty} → {$newQty}");
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Failed to adjust stock: ' . $e->getMessage());
        }
    }

    public function updateMinStock(Request $request)
    {
        [$companyId, $company] = $this->ensureInventoryEnabled();

        $request->validate([
            'product_id' => 'required|exists:products,id',
            'min_stock_level' => 'required|numeric|min:0',
        ]);

        $stock = InventoryStock::firstOrCreate(
            ['company_id' => $companyId, 'product_id' => $request->product_id, 'branch_id' => null],
            ['quantity' => 0, 'avg_purchase_price' => 0, 'last_purchase_price' => 0]
        );

        $stock->update(['min_stock_level' => $request->min_stock_level]);

        return response()->json(['success' => true, 'min_stock_level' => $stock->min_stock_level]);
    }

    public function toggleInventory(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::findOrFail($companyId);
        $company->update(['inventory_enabled' => !$company->inventory_enabled]);

        $status = $company->inventory_enabled ? 'enabled' : 'disabled';
        return back()->with('success', "Inventory module has been {$status}.");
    }

    public static function deductStockForInvoice(int $companyId, array $items, int $transactionId, string $invoiceNumber, ?int $userId = null): array
    {
        $company = Company::find($companyId);
        if (!$company || !$company->inventory_enabled) {
            return ['skipped' => true, 'message' => 'Inventory not enabled'];
        }

        $warnings = [];

        foreach ($items as $item) {
            if (($item['type'] ?? 'product') !== 'product' || empty($item['item_id'])) {
                continue;
            }

            $productId = (int) $item['item_id'];
            $qty = (float) ($item['quantity'] ?? 0);
            if ($qty <= 0) continue;

            try {
                $stock = InventoryStock::where('company_id', $companyId)
                    ->where('product_id', $productId)
                    ->where('branch_id', null)
                    ->lockForUpdate()
                    ->first();

                if (!$stock) {
                    $posProduct = \App\Models\PosProduct::find($productId);
                    if (!$posProduct) continue;

                    $stock = InventoryStock::create([
                        'company_id' => $companyId,
                        'product_id' => $productId,
                        'branch_id' => null,
                        'quantity' => 0,
                        'min_stock_level' => 0,
                        'avg_purchase_price' => 0,
                        'last_purchase_price' => 0,
                    ]);
                }

                $previousQty = $stock->quantity;
                $newQty = $stock->quantity - $qty;

                if ($newQty < 0) {
                    $productName = \App\Models\PosProduct::find($productId)?->name ?? 'Unknown';
                    $warnings[] = "Low stock warning: {$productName} (Available: {$previousQty}, Sold: {$qty})";
                }

                $stock->update(['quantity' => $newQty]);

                InventoryMovement::create([
                    'company_id' => $companyId,
                    'product_id' => $productId,
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
}
