<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PosProduct;
use App\Models\PosService;
use App\Models\PosCustomer;
use App\Models\PosTaxRule;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use App\Models\RestaurantTable;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\ProductRecipe;
use App\Models\Ingredient;
use App\Models\InventoryStock;
use App\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RestaurantPosController extends Controller
{
    public function pos(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $products = PosProduct::where('company_id', $companyId)
            ->where('is_active', true)
            ->get();

        $services = PosService::where('company_id', $companyId)
            ->where('is_active', true)
            ->get();

        $categories = $products->pluck('category')->filter()->unique()->sort()->values();

        $productIds = $products->pluck('id')->toArray();
        $recipeLookup = ProductRecipe::where('company_id', $companyId)
            ->whereIn('product_id', $productIds)
            ->pluck('product_id')
            ->unique()
            ->toArray();

        $tables = RestaurantTable::where('company_id', $companyId)
            ->where('is_active', true)
            ->with('floor')
            ->orderBy('sort_order')
            ->get();

        $tableId = $request->get('table_id');
        $selectedTable = $tableId ? RestaurantTable::where('company_id', $companyId)->find($tableId) : null;

        $heldOrders = RestaurantOrder::where('company_id', $companyId)
            ->whereIn('status', ['held', 'preparing', 'ready'])
            ->with(['table', 'items'])
            ->orderBy('created_at', 'desc')
            ->get();

        return view('pos.restaurant.pos', compact(
            'company', 'products', 'services', 'categories',
            'recipeLookup', 'tables', 'selectedTable', 'heldOrders'
        ));
    }

    public function holdOrder(Request $request)
    {
        $companyId = app('currentCompanyId');
        $user = Auth::guard('pos')->user();

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer',
            'items.*.item_type' => 'required|in:product,service',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'order_type' => 'required|in:dine_in,takeaway,delivery',
        ]);

        if ($request->table_id) {
            $table = RestaurantTable::where('company_id', $companyId)->where('id', $request->table_id)->first();
            if (!$table) {
                return response()->json(['success' => false, 'message' => 'Invalid table'], 400);
            }
        }

        if ($request->customer_id) {
            $customer = PosCustomer::where('company_id', $companyId)->where('id', $request->customer_id)->first();
            if (!$customer) {
                return response()->json(['success' => false, 'message' => 'Invalid customer'], 400);
            }
        }

        $resolvedItems = [];
        foreach ($request->items as $item) {
            $qty = (float)$item['quantity'];
            if ($item['item_type'] === 'product') {
                $product = PosProduct::where('company_id', $companyId)->where('id', $item['item_id'])->first();
                if (!$product) {
                    return response()->json(['success' => false, 'message' => "Product not found: #{$item['item_id']}"], 400);
                }
                $resolvedItems[] = [
                    'item_type' => 'product',
                    'item_id' => $product->id,
                    'item_name' => $product->name,
                    'quantity' => $qty,
                    'unit_price' => (float)$product->price,
                    'subtotal' => round($qty * (float)$product->price, 2),
                    'special_notes' => $item['special_notes'] ?? null,
                    'is_tax_exempt' => (bool)($product->is_tax_exempt ?? false),
                ];
            } else {
                $service = PosService::where('company_id', $companyId)->where('id', $item['item_id'])->first();
                if (!$service) {
                    return response()->json(['success' => false, 'message' => "Service not found: #{$item['item_id']}"], 400);
                }
                $resolvedItems[] = [
                    'item_type' => 'service',
                    'item_id' => $service->id,
                    'item_name' => $service->name,
                    'quantity' => $qty,
                    'unit_price' => (float)$service->price,
                    'subtotal' => round($qty * (float)$service->price, 2),
                    'special_notes' => $item['special_notes'] ?? null,
                    'is_tax_exempt' => (bool)($service->is_tax_exempt ?? false),
                ];
            }
        }

        $subtotal = array_sum(array_column($resolvedItems, 'subtotal'));
        $orderCount = RestaurantOrder::where('company_id', $companyId)->count();
        $orderNumber = 'ORD-' . str_pad($orderCount + 1, 5, '0', STR_PAD_LEFT);

        $taxRate = PosTaxRule::getRateForMethod('cash');
        $taxAmount = round($subtotal * $taxRate / 100, 2);
        $totalAmount = round($subtotal + $taxAmount, 2);

        DB::beginTransaction();
        try {
            $order = RestaurantOrder::create([
                'company_id' => $companyId,
                'order_number' => $orderNumber,
                'table_id' => $request->table_id,
                'order_type' => $request->order_type,
                'status' => 'held',
                'customer_id' => $request->customer_id,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'kitchen_notes' => $request->kitchen_notes,
                'created_by' => $user->id,
            ]);

            foreach ($resolvedItems as $item) {
                RestaurantOrderItem::create(array_merge($item, ['order_id' => $order->id]));
            }

            if ($request->table_id) {
                RestaurantTable::where('company_id', $companyId)
                    ->where('id', $request->table_id)
                    ->update([
                        'status' => 'occupied',
                        'locked_by_user_id' => null,
                        'locked_at' => null,
                    ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Order {$orderNumber} held successfully",
                'order' => $order->load('items'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function payOrder(Request $request, $orderId)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $user = Auth::guard('pos')->user();

        $order = RestaurantOrder::where('company_id', $companyId)
            ->with('items')
            ->findOrFail($orderId);

        if ($order->status === 'completed') {
            return response()->json(['success' => false, 'message' => 'Order already paid'], 400);
        }

        $paymentMethod = $request->input('payment_method', 'cash');
        $taxRate = PosTaxRule::getRateForMethod($paymentMethod);

        $subtotal = $order->items->sum('subtotal');
        $taxableSubtotal = $order->items->where('is_tax_exempt', false)->sum('subtotal');
        $taxAmount = round($taxableSubtotal * $taxRate / 100, 2);
        $totalAmount = round($subtotal + $taxAmount, 2);

        $praEnabled = (bool) $company->pra_reporting_enabled;
        $invoiceMode = $praEnabled ? 'pra' : 'local';

        $stockErrors = $this->validateStockForOrder($companyId, $order);
        if (!empty($stockErrors)) {
            return response()->json([
                'success' => false,
                'stock_error' => true,
                'message' => 'Insufficient stock: ' . implode(', ', $stockErrors),
            ], 400);
        }

        DB::beginTransaction();
        try {
            $invoiceNumber = $invoiceMode === 'local'
                ? $this->generateLocalInvoiceNumber($companyId)
                : $this->generateInvoiceNumber($companyId);

            $submissionHash = hash('sha256', $companyId . '|' . $invoiceNumber . '|' . $totalAmount . '|' . now()->timestamp);

            $transaction = PosTransaction::create([
                'company_id' => $companyId,
                'invoice_number' => $invoiceNumber,
                'invoice_mode' => $invoiceMode,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'subtotal' => $subtotal,
                'discount_type' => 'amount',
                'discount_value' => 0,
                'discount_amount' => 0,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'exempt_amount' => $subtotal - $taxableSubtotal,
                'total_amount' => $totalAmount,
                'payment_method' => $paymentMethod,
                'status' => 'completed',
                'pra_status' => $praEnabled ? 'pending' : 'local',
                'submission_hash' => $submissionHash,
                'created_by' => $user->id,
            ]);

            foreach ($order->items as $item) {
                PosTransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'item_type' => $item->item_type,
                    'item_id' => $item->item_id,
                    'item_name' => $item->item_name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'subtotal' => $item->subtotal,
                    'is_tax_exempt' => $item->is_tax_exempt,
                    'tax_rate' => $item->is_tax_exempt ? 0 : $taxRate,
                    'tax_amount' => $item->is_tax_exempt ? 0 : round($item->subtotal * $taxRate / 100, 2),
                ]);
            }

            $order->update([
                'status' => 'completed',
                'payment_method' => $paymentMethod,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'pos_transaction_id' => $transaction->id,
            ]);

            $this->deductInventoryForOrder($companyId, $order, $transaction->id, $invoiceNumber, $user->id);

            if ($order->table_id) {
                $otherActive = RestaurantOrder::where('company_id', $companyId)
                    ->where('table_id', $order->table_id)
                    ->where('id', '!=', $order->id)
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->exists();

                if (!$otherActive) {
                    RestaurantTable::where('company_id', $companyId)->where('id', $order->table_id)->update([
                        'status' => 'available',
                        'locked_by_user_id' => null,
                        'locked_at' => null,
                    ]);
                }
            }

            if ($order->customer_id) {
                $this->updateCustomerStats($order->customer_id, $totalAmount);
            }

            DB::commit();

            if ($praEnabled) {
                try {
                    $praService = new \App\Services\PraIntegrationService();
                    $praResult = $praService->submitInvoice($transaction, $company);
                    if ($praResult && isset($praResult['success']) && $praResult['success']) {
                        $transaction->update([
                            'pra_status' => 'submitted',
                            'pra_invoice_number' => $praResult['pra_invoice_number'] ?? null,
                            'pra_response_code' => $praResult['response_code'] ?? null,
                        ]);
                    }
                } catch (\Exception $e) {
                    $transaction->update(['pra_status' => 'offline']);
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Payment received. Invoice: {$invoiceNumber}",
                'transaction_id' => $transaction->id,
                'invoice_number' => $invoiceNumber,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function validateStockForOrder($companyId, $order)
    {
        $errors = [];
        foreach ($order->items->where('item_type', 'product') as $item) {
            $recipes = ProductRecipe::where('company_id', $companyId)
                ->where('product_id', $item->item_id)
                ->with('ingredient')
                ->get();

            if ($recipes->isNotEmpty()) {
                foreach ($recipes as $recipe) {
                    $needed = round($recipe->quantity_needed * $item->quantity, 4);
                    $ingredient = $recipe->ingredient;
                    if ($ingredient && $ingredient->current_stock < $needed) {
                        $errors[] = "{$ingredient->name} (need {$needed} {$ingredient->unit}, have {$ingredient->current_stock})";
                    }
                }
            }
        }
        return $errors;
    }

    private function deductInventoryForOrder($companyId, $order, $transactionId, $invoiceNumber, $userId)
    {
        $company = Company::find($companyId);
        if (!$company || !$company->inventory_enabled) return;

        foreach ($order->items as $item) {
            if ($item->item_type !== 'product') continue;

            $recipes = ProductRecipe::where('company_id', $companyId)
                ->where('product_id', $item->item_id)
                ->with('ingredient')
                ->get();

            if ($recipes->isNotEmpty()) {
                foreach ($recipes as $recipe) {
                    $deductQty = round($recipe->quantity_needed * $item->quantity, 4);
                    $ingredient = Ingredient::where('id', $recipe->ingredient_id)
                        ->where('company_id', $companyId)
                        ->lockForUpdate()
                        ->first();

                    if ($ingredient) {
                        $ingredient->update(['current_stock' => $ingredient->current_stock - $deductQty]);

                        InventoryMovement::create([
                            'company_id' => $companyId,
                            'product_id' => $item->item_id,
                            'type' => 'recipe_sale',
                            'quantity' => $deductQty,
                            'unit_price' => $ingredient->cost_per_unit,
                            'total_price' => round($deductQty * $ingredient->cost_per_unit, 2),
                            'balance_after' => $ingredient->current_stock,
                            'reference_type' => 'restaurant_order',
                            'reference_id' => $order->id,
                            'reference_number' => $invoiceNumber,
                            'notes' => "Recipe: {$ingredient->name} for {$item->item_name}",
                            'created_by' => $userId,
                        ]);
                    }
                }
            } else {
                $itemData = [
                    ['type' => 'product', 'item_id' => $item->item_id, 'quantity' => $item->quantity, 'unit_price' => $item->unit_price]
                ];
                \App\Http\Controllers\PosInventoryController::deductStockForInvoice($companyId, $itemData, $transactionId, $invoiceNumber, $userId);
            }
        }
    }

    private function updateCustomerStats($customerId, $amount)
    {
        $customer = PosCustomer::find($customerId);
        if (!$customer) return;
        // Stats tracking is done via query aggregation, no separate columns needed
    }

    private function generateInvoiceNumber($companyId)
    {
        $year = date('Y');
        $last = PosTransaction::where('company_id', $companyId)
            ->where('invoice_mode', 'pra')
            ->where('invoice_number', 'like', "POS-{$year}-%")
            ->orderByRaw("CAST(SUBSTRING(invoice_number FROM 'POS-{$year}-([0-9]+)') AS INTEGER) DESC NULLS LAST")
            ->first();

        $nextNum = 1;
        if ($last && preg_match('/POS-' . $year . '-(\d+)/', $last->invoice_number, $m)) {
            $nextNum = (int) $m[1] + 1;
        }
        return "POS-{$year}-" . str_pad($nextNum, 5, '0', STR_PAD_LEFT);
    }

    private function generateLocalInvoiceNumber($companyId)
    {
        $year = date('Y');
        $last = PosTransaction::where('company_id', $companyId)
            ->where('invoice_mode', 'local')
            ->where('invoice_number', 'like', "LOCAL-{$year}-%")
            ->orderByRaw("CAST(SUBSTRING(invoice_number FROM 'LOCAL-{$year}-([0-9]+)') AS INTEGER) DESC NULLS LAST")
            ->first();

        $nextNum = 1;
        if ($last && preg_match('/LOCAL-' . $year . '-(\d+)/', $last->invoice_number, $m)) {
            $nextNum = (int) $m[1] + 1;
        }
        return "LOCAL-{$year}-" . str_pad($nextNum, 5, '0', STR_PAD_LEFT);
    }

    public function customerSearch(Request $request)
    {
        $companyId = app('currentCompanyId');
        $q = $request->get('q', '');

        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $customers = PosCustomer::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($query) use ($q) {
                $query->where('name', 'ilike', "%{$q}%")
                    ->orWhere('phone', 'ilike', "%{$q}%");
            })
            ->limit(10)
            ->get(['id', 'name', 'phone', 'email']);

        return response()->json($customers);
    }

    public function customerStore(Request $request)
    {
        $companyId = app('currentCompanyId');

        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:30',
        ]);

        $customer = PosCustomer::create([
            'company_id' => $companyId,
            'name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
            'type' => 'unregistered',
        ]);

        return response()->json(['success' => true, 'customer' => $customer]);
    }

    public function getOrdersByTable($tableId)
    {
        $companyId = app('currentCompanyId');

        $orders = RestaurantOrder::where('company_id', $companyId)
            ->where('table_id', $tableId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->with('items')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($orders);
    }

    public function kitchenSettings()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        return view('pos.restaurant.kitchen-settings', compact('company'));
    }

    public function updateKitchenSettings(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $company->update([
            'kds_enabled' => (bool) $request->kds_enabled,
            'kitchen_printer_enabled' => (bool) $request->kitchen_printer_enabled,
            'print_on_hold' => (bool) $request->print_on_hold,
            'print_on_pay' => (bool) $request->print_on_pay,
        ]);

        return back()->with('success', 'Kitchen settings updated successfully.');
    }
}
