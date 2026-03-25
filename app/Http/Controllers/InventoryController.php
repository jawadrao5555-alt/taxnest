<?php

namespace App\Http\Controllers;

use App\Models\InventoryStock;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Branch;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $companyId = auth()->user()->company_id;
        $search = $request->get('search', '');
        $branchFilter = $request->get('branch_id', '');
        $stockFilter = $request->get('stock_filter', '');

        $query = InventoryStock::with(['product', 'branch'])
            ->where('company_id', $companyId);

        if ($search) {
            $productIds = Product::where('company_id', $companyId)
                ->where(function ($q) use ($search) {
                    $like = \App\Helpers\DbCompat::like();
                    $q->where('name', $like, "%{$search}%")
                      ->orWhere('hs_code', $like, "%{$search}%");
                })->pluck('id');
            $query->whereIn('product_id', $productIds);
        }

        if ($branchFilter) {
            $query->where('branch_id', $branchFilter);
        }

        if ($stockFilter === 'low') {
            $query->lowStock();
        } elseif ($stockFilter === 'out') {
            $query->where('quantity', '<=', 0);
        } elseif ($stockFilter === 'in') {
            $query->where('quantity', '>', 0);
        }

        $stocks = $query->orderBy('updated_at', 'desc')->paginate(25)->appends($request->query());

        $stats = [
            'total_products' => InventoryStock::where('company_id', $companyId)->count(),
            'total_value' => InventoryStock::where('company_id', $companyId)
                ->selectRaw('SUM(quantity * avg_purchase_price) as val')->value('val') ?? 0,
            'low_stock' => InventoryStock::where('company_id', $companyId)->lowStock()->count(),
            'out_of_stock' => InventoryStock::where('company_id', $companyId)->where('quantity', '<=', 0)->count(),
        ];

        $branches = Branch::where('company_id', $companyId)->orderBy('name')->get();

        return view('inventory.index', compact('stocks', 'stats', 'search', 'branchFilter', 'stockFilter', 'branches'));
    }

    public function movements(Request $request)
    {
        $companyId = auth()->user()->company_id;
        $search = $request->get('search', '');
        $typeFilter = $request->get('type', '');
        $dateFrom = $request->get('date_from', '');
        $dateTo = $request->get('date_to', '');

        $query = InventoryMovement::with(['product', 'branch', 'creator'])
            ->where('company_id', $companyId);

        if ($search) {
            $productIds = Product::where('company_id', $companyId)
                ->where('name', \App\Helpers\DbCompat::like(), "%{$search}%")->pluck('id');
            $like = \App\Helpers\DbCompat::like();
            $query->where(function ($q) use ($productIds, $search, $like) {
                $q->whereIn('product_id', $productIds)
                  ->orWhere('reference_number', $like, "%{$search}%")
                  ->orWhere('notes', $like, "%{$search}%");
            });
        }

        if ($typeFilter) {
            $query->where('type', $typeFilter);
        }

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $movements = $query->orderByDesc('created_at')->paginate(30)->appends($request->query());

        return view('inventory.movements', compact('movements', 'search', 'typeFilter', 'dateFrom', 'dateTo'));
    }

    public function adjust(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'branch_id' => 'nullable|exists:branches,id',
            'type' => 'required|in:adjustment_in,adjustment_out,opening',
            'quantity' => 'required|numeric|min:0.01',
            'unit_price' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $companyId = auth()->user()->company_id;
        $product = Product::where('company_id', $companyId)->findOrFail($validated['product_id']);
        $unitPrice = $validated['unit_price'] ?? $product->default_price;

        if ($validated['type'] === 'adjustment_out') {
            InventoryService::deductStock(
                $companyId, $product->id, $validated['quantity'], $unitPrice,
                InventoryMovement::TYPE_ADJUSTMENT_OUT,
                $validated['branch_id'] ?? null,
                [], $validated['notes'] ?? 'Manual adjustment (out)', auth()->id()
            );
        } else {
            $type = $validated['type'] === 'opening' ? InventoryMovement::TYPE_OPENING : InventoryMovement::TYPE_ADJUSTMENT_IN;
            InventoryService::addStock(
                $companyId, $product->id, $validated['quantity'], $unitPrice,
                $type, $validated['branch_id'] ?? null,
                [], $validated['notes'] ?? 'Manual adjustment (in)', auth()->id()
            );
        }

        return redirect()->route('inventory.index')->with('success', 'Stock adjusted successfully for ' . $product->name);
    }

    public function updateMinStock(Request $request, $id)
    {
        $companyId = auth()->user()->company_id;
        $stock = InventoryStock::where('company_id', $companyId)->findOrFail($id);

        $validated = $request->validate([
            'min_stock_level' => 'required|numeric|min:0',
            'max_stock_level' => 'nullable|numeric|min:0',
        ]);

        $stock->update($validated);

        return redirect()->route('inventory.index')->with('success', 'Stock levels updated.');
    }

    public function productMovements($productId, Request $request)
    {
        $companyId = auth()->user()->company_id;
        $product = Product::where('company_id', $companyId)->findOrFail($productId);

        $movements = InventoryMovement::with(['branch', 'creator'])
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->orderByDesc('created_at')
            ->paginate(30);

        $stock = InventoryStock::where('company_id', $companyId)
            ->where('product_id', $productId)->first();

        return view('inventory.product-movements', compact('product', 'movements', 'stock'));
    }

    public function apiStockCheck($productId)
    {
        $companyId = auth()->user()->company_id;
        $branchId = auth()->user()->branch_id ?? null;

        $stock = InventoryStock::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->first();

        return response()->json([
            'quantity' => $stock ? $stock->quantity : 0,
            'min_stock_level' => $stock ? $stock->min_stock_level : 0,
            'is_low' => $stock ? $stock->isLowStock() : false,
        ]);
    }
}
