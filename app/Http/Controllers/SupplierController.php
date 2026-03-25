<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Product;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $companyId = auth()->user()->company_id;
        $search = $request->get('search', '');

        $query = Supplier::where('company_id', $companyId);

        if ($search) {
            $like = \App\Helpers\DbCompat::like();
            $query->where(function ($q) use ($search, $like) {
                $q->where('name', $like, "%{$search}%")
                  ->orWhere('ntn', $like, "%{$search}%")
                  ->orWhere('phone', $like, "%{$search}%")
                  ->orWhere('city', $like, "%{$search}%");
            });
        }

        $suppliers = $query->orderBy('name')->paginate(25)->appends($request->query());

        return view('inventory.suppliers', compact('suppliers', 'search'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'ntn' => 'nullable|string|max:50',
            'cnic' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'contact_person' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $validated['company_id'] = auth()->user()->company_id;
        Supplier::create($validated);

        return redirect()->route('suppliers.index')->with('success', 'Supplier added successfully.');
    }

    public function update(Request $request, $id)
    {
        $companyId = auth()->user()->company_id;
        $supplier = Supplier::where('company_id', $companyId)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'ntn' => 'nullable|string|max:50',
            'cnic' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'contact_person' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        $validated['is_active'] = $request->boolean('is_active');
        $supplier->update($validated);

        return redirect()->route('suppliers.index')->with('success', 'Supplier updated.');
    }

    public function destroy($id)
    {
        $companyId = auth()->user()->company_id;
        $supplier = Supplier::where('company_id', $companyId)->findOrFail($id);

        if ($supplier->purchaseOrders()->exists()) {
            return redirect()->route('suppliers.index')->with('error', 'Cannot delete supplier with existing purchase orders.');
        }

        $supplier->delete();
        return redirect()->route('suppliers.index')->with('success', 'Supplier deleted.');
    }

    public function purchaseOrders(Request $request)
    {
        $companyId = auth()->user()->company_id;
        $search = $request->get('search', '');
        $statusFilter = $request->get('status', '');

        $query = PurchaseOrder::with(['supplier', 'branch', 'items'])
            ->where('company_id', $companyId);

        if ($search) {
            $like = \App\Helpers\DbCompat::like();
            $query->where(function ($q) use ($search, $like) {
                $q->where('po_number', $like, "%{$search}%")
                  ->orWhereHas('supplier', function ($sq) use ($search, $like) {
                      $sq->where('name', $like, "%{$search}%");
                  });
            });
        }

        if ($statusFilter) {
            $query->where('status', $statusFilter);
        }

        $purchaseOrders = $query->orderByDesc('order_date')->paginate(25)->appends($request->query());
        $suppliers = Supplier::where('company_id', $companyId)->active()->orderBy('name')->get();
        $products = Product::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();
        $branches = Branch::where('company_id', $companyId)->orderBy('name')->get();

        return view('inventory.purchase-orders', compact('purchaseOrders', 'suppliers', 'products', 'branches', 'search', 'statusFilter'));
    }

    public function storePurchaseOrder(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'branch_id' => 'nullable|exists:branches,id',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        $companyId = auth()->user()->company_id;

        DB::transaction(function () use ($validated, $companyId) {
            $lastPo = PurchaseOrder::where('company_id', $companyId)
                ->orderByDesc('id')->first();
            $nextNum = $lastPo ? intval(preg_replace('/\D/', '', $lastPo->po_number)) + 1 : 1;
            $poNumber = 'PO-' . str_pad($nextNum, 5, '0', STR_PAD_LEFT);

            $totalAmount = 0;
            foreach ($validated['items'] as $item) {
                $totalAmount += round($item['quantity'] * $item['unit_price'], 2);
            }

            $po = PurchaseOrder::create([
                'company_id' => $companyId,
                'supplier_id' => $validated['supplier_id'],
                'branch_id' => $validated['branch_id'] ?? null,
                'po_number' => $poNumber,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'order_date' => $validated['order_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'total_amount' => $totalAmount,
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($validated['items'] as $item) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total_price' => round($item['quantity'] * $item['unit_price'], 2),
                ]);
            }
        });

        return redirect()->route('purchase-orders.index')->with('success', 'Purchase order created.');
    }

    public function receivePurchaseOrder(Request $request, $id)
    {
        $companyId = auth()->user()->company_id;
        $po = PurchaseOrder::with('items.product')
            ->where('company_id', $companyId)
            ->findOrFail($id);

        if ($po->status === PurchaseOrder::STATUS_RECEIVED || $po->status === PurchaseOrder::STATUS_CANCELLED) {
            return redirect()->route('purchase-orders.index')->with('error', 'This PO is already ' . $po->status . '.');
        }

        DB::transaction(function () use ($po, $companyId) {
            foreach ($po->items as $item) {
                $remainingQty = $item->quantity - $item->received_quantity;
                if ($remainingQty <= 0) continue;

                InventoryService::addStock(
                    $companyId, $item->product_id, $remainingQty, $item->unit_price,
                    InventoryMovement::TYPE_PURCHASE,
                    $po->branch_id,
                    ['type' => 'purchase_order', 'id' => $po->id, 'number' => $po->po_number],
                    'Received from PO: ' . $po->po_number,
                    auth()->id()
                );

                $item->received_quantity = $item->quantity;
                $item->save();
            }

            $po->status = PurchaseOrder::STATUS_RECEIVED;
            $po->received_date = now()->toDateString();
            $po->save();
        });

        return redirect()->route('purchase-orders.index')->with('success', 'Purchase order received. Stock updated.');
    }

    public function cancelPurchaseOrder($id)
    {
        $companyId = auth()->user()->company_id;
        $po = PurchaseOrder::where('company_id', $companyId)->findOrFail($id);

        if ($po->status === PurchaseOrder::STATUS_RECEIVED) {
            return redirect()->route('purchase-orders.index')->with('error', 'Cannot cancel a received PO.');
        }

        $po->update(['status' => PurchaseOrder::STATUS_CANCELLED]);
        return redirect()->route('purchase-orders.index')->with('success', 'Purchase order cancelled.');
    }
}
