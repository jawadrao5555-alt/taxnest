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
use Illuminate\Support\Facades\Log;
use App\Services\ProductImageService;
use App\Services\AuditLogService;

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

        $customers = PosCustomer::where('company_id', $companyId)->orderBy('name')->get();

        $taxRate = PosTaxRule::getRateForMethod('cash');

        $stockStatus = [];
        $recipes = ProductRecipe::where('company_id', $companyId)
            ->with('ingredient')
            ->get()
            ->groupBy('product_id');

        foreach ($recipes as $productId => $productRecipes) {
            $status = 'available';
            foreach ($productRecipes as $recipe) {
                $ing = $recipe->ingredient;
                if (!$ing || !$ing->is_active) continue;
                if ((float)$ing->current_stock < (float)$recipe->quantity_needed) {
                    $status = 'out';
                    break;
                } elseif ($ing->isLowStock()) {
                    $status = 'low';
                }
            }
            $stockStatus[$productId] = $status;
        }

        $blockOutOfStock = (bool)($company->block_out_of_stock ?? false);

        $user = Auth::guard('pos')->user();
        $posRole = $user->pos_role ?? 'pos_cashier';
        $discountLimit = $posRole === 'pos_admin'
            ? (float)($company->manager_discount_limit ?? 50)
            : (float)($company->cashier_discount_limit ?? 10);
        $hasManagerPin = !empty($company->manager_override_pin);

        $ingredientCosts = [];
        foreach ($recipes as $productId => $productRecipes) {
            $cost = 0;
            foreach ($productRecipes as $recipe) {
                $ing = $recipe->ingredient;
                if ($ing) $cost += (float)$recipe->quantity_needed * (float)($ing->cost_per_unit ?? 0);
            }
            $ingredientCosts[$productId] = round($cost, 2);
        }

        $lowStockAlerts = Ingredient::where('company_id', $companyId)
            ->where('is_active', true)
            ->whereColumn('current_stock', '<=', 'min_stock_level')
            ->select('name', 'current_stock', 'min_stock_level', 'unit')
            ->get();

        return view('pos.restaurant.pos', compact(
            'company', 'products', 'services', 'categories',
            'recipeLookup', 'tables', 'selectedTable', 'heldOrders',
            'customers', 'taxRate', 'stockStatus', 'blockOutOfStock',
            'posRole', 'discountLimit', 'hasManagerPin', 'ingredientCosts',
            'lowStockAlerts'
        ));
    }

    public function holdOrder(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $user = Auth::guard('pos')->user();

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer',
            'items.*.item_type' => 'required|in:product,service',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.item_discount_type' => 'nullable|in:percentage,amount',
            'items.*.item_discount_value' => 'nullable|numeric|min:0|max:999999',
            'order_type' => 'required|in:dine_in,takeaway,delivery',
            'discount_type' => 'nullable|in:percentage,amount',
            'discount_value' => 'nullable|numeric|min:0|max:999999',
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

        $cartHash = md5(json_encode($request->items) . $request->table_id . $request->customer_id . $user->id);
        $cacheKey = 'hold_dedup_' . $companyId . '_' . $cartHash;
        if (cache()->has($cacheKey)) {
            return response()->json(['success' => false, 'message' => 'Duplicate order detected. Please wait.'], 429);
        }
        cache()->put($cacheKey, true, 5);

        $resolvedItems = [];
        foreach ($request->items as $item) {
            $qty = (float)$item['quantity'];
            if ($item['item_type'] === 'product') {
                $product = PosProduct::where('company_id', $companyId)->where('id', $item['item_id'])->first();
                if (!$product) {
                    return response()->json(['success' => false, 'message' => "Product not found: #{$item['item_id']}"], 400);
                }
                $lineTotal = round($qty * (float)$product->price, 2);
                $itemDiscountType = $item['item_discount_type'] ?? null;
                $itemDiscountValue = (float)($item['item_discount_value'] ?? 0);
                $itemDiscountAmount = 0;
                if ($itemDiscountValue > 0 && $itemDiscountType === 'percentage') {
                    $itemDiscountAmount = round($lineTotal * min(100, $itemDiscountValue) / 100, 2);
                } elseif ($itemDiscountValue > 0 && $itemDiscountType === 'amount') {
                    $itemDiscountAmount = min($lineTotal, round($itemDiscountValue, 2));
                }
                $resolvedItems[] = [
                    'item_type' => 'product',
                    'item_id' => $product->id,
                    'item_name' => $product->name,
                    'quantity' => $qty,
                    'unit_price' => (float)$product->price,
                    'subtotal' => round($lineTotal - $itemDiscountAmount, 2),
                    'special_notes' => $item['special_notes'] ?? null,
                    'is_tax_exempt' => (bool)($product->is_tax_exempt ?? false),
                    'item_discount_type' => $itemDiscountValue > 0 ? $itemDiscountType : null,
                    'item_discount_value' => $itemDiscountValue,
                    'item_discount_amount' => $itemDiscountAmount,
                ];
            } else {
                $service = PosService::where('company_id', $companyId)->where('id', $item['item_id'])->first();
                if (!$service) {
                    return response()->json(['success' => false, 'message' => "Service not found: #{$item['item_id']}"], 400);
                }
                $lineTotal = round($qty * (float)$service->price, 2);
                $itemDiscountType = $item['item_discount_type'] ?? null;
                $itemDiscountValue = (float)($item['item_discount_value'] ?? 0);
                $itemDiscountAmount = 0;
                if ($itemDiscountValue > 0 && $itemDiscountType === 'percentage') {
                    $itemDiscountAmount = round($lineTotal * min(100, $itemDiscountValue) / 100, 2);
                } elseif ($itemDiscountValue > 0 && $itemDiscountType === 'amount') {
                    $itemDiscountAmount = min($lineTotal, round($itemDiscountValue, 2));
                }
                $resolvedItems[] = [
                    'item_type' => 'service',
                    'item_id' => $service->id,
                    'item_name' => $service->name,
                    'quantity' => $qty,
                    'unit_price' => (float)$service->price,
                    'subtotal' => round($lineTotal - $itemDiscountAmount, 2),
                    'special_notes' => $item['special_notes'] ?? null,
                    'is_tax_exempt' => (bool)($service->is_tax_exempt ?? false),
                    'item_discount_type' => $itemDiscountValue > 0 ? $itemDiscountType : null,
                    'item_discount_value' => $itemDiscountValue,
                    'item_discount_amount' => $itemDiscountAmount,
                ];
            }
        }

        $subtotal = array_sum(array_column($resolvedItems, 'subtotal'));
        $orderNumber = 'ORD-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

        $discountType = $request->discount_type;
        $discountValue = (float)($request->discount_value ?? 0);
        $discountAmount = (float)($request->discount_amount ?? 0);

        $maxDiscountPct = 100;
        if ($user->pos_role === 'pos_cashier') {
            $maxDiscountPct = (float)($company->cashier_discount_limit ?? 10);
        }
        if ($discountType === 'percentage' && $discountValue > 0) {
            $discountValue = min($discountValue, $maxDiscountPct);
            $discountAmount = round($subtotal * min(100, $discountValue) / 100, 2);
        } elseif ($discountType === 'amount' && $discountValue > 0) {
            $maxAmountFromPct = round($subtotal * $maxDiscountPct / 100, 2);
            $discountAmount = min($subtotal, min($maxAmountFromPct, round($discountValue, 2)));
        }
        $discountAmount = max(0, $discountAmount);

        $taxableSubtotal = array_sum(array_column(array_filter($resolvedItems, fn($i) => !($i['is_tax_exempt'] ?? false)), 'subtotal'));
        $discountRatio = $subtotal > 0 ? ($subtotal - $discountAmount) / $subtotal : 1;
        $adjustedTaxable = round($taxableSubtotal * $discountRatio, 2);

        $taxRate = PosTaxRule::getRateForMethod('cash');
        $taxAmount = round($adjustedTaxable * $taxRate / 100, 2);
        $totalAmount = round($subtotal - $discountAmount + $taxAmount, 2);

        DB::beginTransaction();
        try {
            if ($request->recalled_order_id) {
                $oldOrder = RestaurantOrder::where('id', $request->recalled_order_id)
                    ->where('company_id', $companyId)
                    ->whereIn('status', ['held', 'preparing', 'ready'])
                    ->lockForUpdate()
                    ->first();
                if ($oldOrder) {
                    $oldOrder->items()->delete();
                    $oldOrder->update(['status' => 'cancelled']);
                    if ($oldOrder->table_id) {
                        $activeOnTable = RestaurantOrder::where('table_id', $oldOrder->table_id)
                            ->where('company_id', $companyId)
                            ->where('id', '!=', $oldOrder->id)
                            ->whereIn('status', ['held', 'preparing', 'ready'])
                            ->exists();
                        if (!$activeOnTable) {
                            RestaurantTable::where('id', $oldOrder->table_id)->update(['status' => 'available', 'locked_by_user_id' => null, 'locked_at' => null]);
                        }
                    }
                }
            }

            $estimatedCost = 0;
            $recipeLookup = ProductRecipe::where('company_id', $companyId)->with('ingredient')->get()->groupBy('product_id');
            foreach ($resolvedItems as $ri) {
                if ($ri['item_type'] === 'product' && isset($recipeLookup[$ri['item_id']])) {
                    foreach ($recipeLookup[$ri['item_id']] as $recipe) {
                        $ing = $recipe->ingredient;
                        if ($ing) $estimatedCost += (float)$recipe->quantity_needed * (float)($ing->cost_per_unit ?? 0) * $ri['quantity'];
                    }
                }
            }

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
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'estimated_cost' => round($estimatedCost, 2),
                'kitchen_notes' => $request->kitchen_notes,
                'priority' => (bool)($request->priority ?? false),
                'created_by' => $user->id,
            ]);

            foreach ($resolvedItems as $item) {
                RestaurantOrderItem::create(array_merge($item, ['order_id' => $order->id]));
            }

            if ($request->table_id) {
                $table = RestaurantTable::where('company_id', $companyId)->where('id', $request->table_id)->first();
                if ($table && $table->locked_by_user_id && $table->locked_by_user_id !== $user->id) {
                    $lockAge = $table->locked_at ? now()->diffInMinutes($table->locked_at) : 0;
                    if ($lockAge < 30) {
                        DB::rollBack();
                        return response()->json(['success' => false, 'message' => 'Table is locked by another user'], 423);
                    }
                }
                RestaurantTable::where('company_id', $companyId)
                    ->where('id', $request->table_id)
                    ->update([
                        'status' => 'occupied',
                        'locked_by_user_id' => null,
                        'locked_at' => null,
                    ]);
            }

            DB::commit();

            try {
                $auditMeta = ['order_number' => $orderNumber, 'total' => $totalAmount, 'items_count' => count($resolvedItems)];
                if ($discountAmount > 0) {
                    $auditMeta['discount_type'] = $discountType;
                    $auditMeta['discount_value'] = $discountValue;
                    $auditMeta['discount_amount'] = $discountAmount;
                    AuditLogService::log('discount_applied', 'restaurant_order', $order->id, null, $auditMeta, $companyId, $user->id);
                }
                AuditLogService::log('order_created', 'restaurant_order', $order->id, null, $auditMeta, $companyId, $user->id);
            } catch (\Exception $e) {
                Log::warning('Audit log failed: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => "Order {$orderNumber} held successfully",
                'order' => $order->load('items'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Hold order failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to hold order. Please try again.'], 500);
        }
    }

    public function deleteOrder(Request $request, $orderId)
    {
        $companyId = app('currentCompanyId');
        if (!is_numeric($orderId) || $orderId < 1) {
            return response()->json(['success' => false, 'message' => 'Invalid order ID'], 400);
        }
        $order = RestaurantOrder::where('company_id', $companyId)->findOrFail($orderId);
        if ($order->status === 'completed') {
            return response()->json(['success' => false, 'message' => 'Cannot delete a completed order'], 400);
        }
        DB::beginTransaction();
        try {
            $order->items()->delete();
            $order->delete();
            if (class_exists(\App\Services\AuditLogService::class)) {
                \App\Services\AuditLogService::log('order_deleted', 'restaurant_order', $orderId, ['order_number' => $order->order_number, 'total_amount' => $order->total_amount], $companyId);
            }
            DB::commit();
            return response()->json(['success' => true, 'message' => 'Order deleted']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Failed to delete order'], 500);
        }
    }

    public function payOrder(Request $request, $orderId)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $user = Auth::guard('pos')->user();

        if (!is_numeric($orderId) || $orderId < 1) {
            return response()->json(['success' => false, 'message' => 'Invalid order ID'], 400);
        }

        $request->validate([
            'payment_method' => 'nullable|string|in:cash,card,online,split',
        ]);

        $order = RestaurantOrder::where('company_id', $companyId)
            ->with('items')
            ->findOrFail($orderId);

        if ($order->status === 'completed') {
            return response()->json(['success' => false, 'message' => 'Order already paid'], 400);
        }

        $paymentMethod = $request->input('payment_method', 'cash');
        $taxRate = PosTaxRule::getRateForMethod($paymentMethod);

        $subtotal = $order->items->sum('subtotal');
        $discountAmount = (float)($order->discount_amount ?? 0);
        $discountRatio = $subtotal > 0 ? ($subtotal - $discountAmount) / $subtotal : 1;
        $taxableSubtotal = $order->items->where('is_tax_exempt', false)->sum('subtotal');
        $adjustedTaxable = round($taxableSubtotal * max(0, $discountRatio), 2);
        $taxAmount = round($adjustedTaxable * $taxRate / 100, 2);
        $totalAmount = round($subtotal - $discountAmount + $taxAmount, 2);
        $totalItemDiscounts = $order->items->sum('item_discount_amount');

        $praEnabled = (bool) $company->pra_reporting_enabled;
        $invoiceMode = $praEnabled ? 'pra' : 'local';

        DB::beginTransaction();
        try {
            $stockErrors = $this->validateStockForOrder($companyId, $order, true);
            if (!empty($stockErrors)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'stock_error' => true,
                    'message' => 'Insufficient stock: ' . implode(', ', $stockErrors),
                ], 400);
            }
            $invoiceNumber = $invoiceMode === 'local'
                ? $this->generateLocalInvoiceNumber($companyId)
                : $this->generateInvoiceNumber($companyId);

            $submissionHash = hash('sha256', $companyId . '|' . $invoiceNumber . '|' . $totalAmount . '|' . now()->timestamp);

            $transaction = PosTransaction::create([
                'company_id' => $companyId,
                'invoice_number' => $invoiceNumber,
                'invoice_mode' => $invoiceMode,
                'customer_id' => $order->customer_id,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'subtotal' => $subtotal,
                'discount_type' => $order->discount_type ?? 'amount',
                'discount_value' => (float)($order->discount_value ?? 0),
                'discount_amount' => $discountAmount,
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
                $lineAfterOrderDisc = $subtotal > 0 ? round($item->subtotal * max(0, $discountRatio), 2) : $item->subtotal;
                $lineTax = $item->is_tax_exempt ? 0 : round($lineAfterOrderDisc * $taxRate / 100, 2);
                PosTransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'item_type' => $item->item_type,
                    'item_id' => (int) $item->item_id,
                    'item_name' => $item->item_name,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                    'is_tax_exempt' => (bool) $item->is_tax_exempt,
                    'tax_rate' => $item->is_tax_exempt ? 0 : $taxRate,
                    'tax_amount' => $lineTax,
                    'item_discount_type' => $item->item_discount_type ?? 'percentage',
                    'item_discount_value' => (float) ($item->item_discount_value ?? 0),
                    'item_discount_amount' => (float) ($item->item_discount_amount ?? 0),
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

            try {
                AuditLogService::log('order_paid', 'restaurant_order', $order->id, null, [
                    'order_number' => $order->order_number,
                    'invoice_number' => $invoiceNumber,
                    'total' => $totalAmount,
                    'payment_method' => $paymentMethod,
                    'discount_amount' => (float)($order->discount_amount ?? 0),
                ], $companyId, $user->id);
            } catch (\Exception $e) {
                Log::warning('Audit log failed: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => "Payment received. Invoice: {$invoiceNumber}",
                'transaction_id' => $transaction->id,
                'invoice_number' => $invoiceNumber,
                'total_amount' => $totalAmount,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payment failed for order ' . $orderId . ': ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Payment processing failed. Please try again.'], 500);
        }
    }

    private function validateStockForOrder($companyId, $order, $lock = false)
    {
        $aggregated = [];
        foreach ($order->items->where('item_type', 'product') as $item) {
            $recipes = ProductRecipe::where('company_id', $companyId)
                ->where('product_id', $item->item_id)
                ->get();

            foreach ($recipes as $recipe) {
                $needed = round($recipe->quantity_needed * $item->quantity, 4);
                $ingId = $recipe->ingredient_id;
                if (!isset($aggregated[$ingId])) {
                    $aggregated[$ingId] = 0;
                }
                $aggregated[$ingId] += $needed;
            }
        }

        $errors = [];
        foreach ($aggregated as $ingredientId => $totalNeeded) {
            $query = Ingredient::where('id', $ingredientId)->where('company_id', $companyId);
            $ingredient = $lock ? $query->lockForUpdate()->first() : $query->first();
            if ($ingredient && $ingredient->current_stock < $totalNeeded) {
                $errors[] = "{$ingredient->name} (need {$totalNeeded} {$ingredient->unit}, have {$ingredient->current_stock})";
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

        $companyId = $customer->company_id;
        $totalOrders = PosTransaction::where('company_id', $companyId)->where('customer_id', $customerId)->where('status', 'completed')->count();
        $restaurantOrders = RestaurantOrder::where('company_id', $companyId)->where('customer_id', $customerId)->where('status', 'completed')->count();

        $totalVisits = $totalOrders + $restaurantOrders;
        if ($totalVisits >= 5 && $customer->type !== 'frequent') {
            $customer->update(['type' => 'frequent']);
        }
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
            return response()->json(['customers' => []]);
        }

        $customers = PosCustomer::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($query) use ($q) {
                $query->where('name', 'ilike', "%{$q}%")
                    ->orWhere('phone', 'ilike', "%{$q}%");
            })
            ->limit(8)
            ->get(['id', 'name', 'phone', 'email', 'address']);

        $result = [];
        foreach ($customers as $c) {
            $posOrders = PosTransaction::where('company_id', $companyId)
                ->where('customer_id', $c->id)
                ->where('status', 'completed')
                ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as total')
                ->first();
            $restOrders = RestaurantOrder::where('company_id', $companyId)
                ->where('customer_id', $c->id)
                ->where('status', 'completed')
                ->count();
            $totalOrders = ($posOrders->cnt ?? 0) + $restOrders;
            $totalSpent = round($posOrders->total ?? 0, 2);
            $result[] = [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'address' => $c->address,
                'stats' => [
                    'total_orders' => $totalOrders,
                    'total_spent' => $totalSpent,
                    'is_frequent' => $totalOrders >= 5,
                ],
            ];
        }

        return response()->json(['customers' => $result]);
    }

    public function customerStore(Request $request)
    {
        $companyId = app('currentCompanyId');

        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:30',
            'address' => 'nullable|string|max:500',
        ]);

        $existing = PosCustomer::where('company_id', $companyId)
            ->where('phone', $request->phone)
            ->first();

        if ($existing) {
            if ($request->address && !$existing->address) {
                $existing->update(['address' => $request->address]);
            }
            return response()->json(['success' => true, 'customer' => $existing, 'existing' => true]);
        }

        $customer = PosCustomer::create([
            'company_id' => $companyId,
            'name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
            'address' => $request->address,
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

    public function kitchenTicket($orderId)
    {
        $companyId = app('currentCompanyId');
        $order = RestaurantOrder::where('company_id', $companyId)
            ->with(['items', 'table', 'creator'])
            ->findOrFail($orderId);

        $company = Company::find($companyId);

        return view('pos.restaurant.kitchen-ticket', compact('order', 'company'));
    }

    public function checkStock(Request $request)
    {
        $companyId = app('currentCompanyId');
        $productId = $request->get('product_id');

        if (!$productId) {
            return response()->json(['status' => 'unknown']);
        }

        $recipes = ProductRecipe::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->with('ingredient')
            ->get();

        if ($recipes->isEmpty()) {
            return response()->json(['status' => 'available', 'has_recipe' => false]);
        }

        $qty = max(1, (float)$request->get('quantity', 1));
        $status = 'available';
        $details = [];

        foreach ($recipes as $recipe) {
            $ingredient = $recipe->ingredient;
            if (!$ingredient || !$ingredient->is_active) continue;

            $needed = $recipe->quantity_needed * $qty;
            $available = (float)$ingredient->current_stock;

            $itemStatus = 'available';
            if ($available < $needed) {
                $itemStatus = 'out';
                $status = 'out';
            } elseif ($available <= $ingredient->min_stock_level) {
                $itemStatus = 'low';
                if ($status !== 'out') $status = 'low';
            }

            $details[] = [
                'name' => $ingredient->name,
                'needed' => round($needed, 2),
                'available' => round($available, 2),
                'unit' => $ingredient->unit,
                'status' => $itemStatus,
            ];
        }

        return response()->json([
            'status' => $status,
            'has_recipe' => true,
            'details' => $details,
        ]);
    }

    public function customerLookup(Request $request)
    {
        $companyId = app('currentCompanyId');
        $phone = $request->get('phone', '');

        if (strlen($phone) < 4) {
            return response()->json(['found' => false]);
        }

        $customer = PosCustomer::where('company_id', $companyId)
            ->where('phone', $phone)
            ->first();

        if (!$customer) {
            $partials = PosCustomer::where('company_id', $companyId)
                ->where('phone', 'ilike', '%' . $phone . '%')
                ->limit(5)
                ->get(['id', 'name', 'phone', 'address']);

            return response()->json(['found' => false, 'suggestions' => $partials]);
        }

        $totalOrders = PosTransaction::where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->count();

        $totalSpent = PosTransaction::where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->sum('total_amount');

        $lastOrder = PosTransaction::where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->first();

        $restaurantOrders = RestaurantOrder::where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->count();

        $totalVisits = $totalOrders + $restaurantOrders;

        return response()->json([
            'found' => true,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'address' => $customer->address,
            ],
            'stats' => [
                'total_orders' => $totalVisits,
                'total_spent' => round($totalSpent, 2),
                'is_frequent' => $totalVisits >= 5,
                'last_order_date' => $lastOrder ? $lastOrder->created_at->format('M d, Y') : null,
                'last_order_amount' => $lastOrder ? round($lastOrder->total_amount, 2) : null,
            ],
        ]);
    }

    public function refreshProductImage(Request $request, $productId)
    {
        $companyId = app('currentCompanyId');
        $newImage = ProductImageService::refreshImage($productId, $companyId);

        if ($newImage) {
            return response()->json([
                'success' => true,
                'image_url' => asset('storage/products/' . $newImage),
            ]);
        }

        return response()->json(['success' => false, 'message' => 'Could not fetch image']);
    }

    public function dashboard()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $user = auth('pos')->user();
        if ($user && $user->pos_role !== 'pos_admin' && $user->role !== 'company_admin') {
            return redirect('/pos/restaurant/pos');
        }

        $today = now()->startOfDay();
        $yesterday = now()->subDay()->startOfDay();

        $todaySales = RestaurantOrder::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $today)
            ->sum('total_amount');

        $yesterdaySales = RestaurantOrder::where('company_id', $companyId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$yesterday, $today])
            ->sum('total_amount');

        $todayOrders = RestaurantOrder::where('company_id', $companyId)
            ->where('created_at', '>=', $today)
            ->count();

        $heldCount = RestaurantOrder::where('company_id', $companyId)
            ->where('created_at', '>=', $today)
            ->whereIn('status', ['held', 'preparing', 'ready'])
            ->count();

        $completedCount = RestaurantOrder::where('company_id', $companyId)
            ->where('created_at', '>=', $today)
            ->where('status', 'completed')
            ->count();

        $totalTables = RestaurantTable::where('company_id', $companyId)->count();
        $occupiedTables = RestaurantTable::where('company_id', $companyId)->where('status', 'occupied')->count();

        $topProducts = RestaurantOrderItem::select('item_name',
            DB::raw('SUM(quantity) as total_qty'),
            DB::raw('SUM(subtotal) as total_revenue'))
            ->whereHas('order', function ($q) use ($companyId, $today) {
                $q->where('company_id', $companyId)
                    ->where('status', 'completed')
                    ->where('created_at', '>=', $today->copy()->subDays(7));
            })
            ->groupBy('item_name')
            ->orderByDesc('total_qty')
            ->limit(8)
            ->get();

        $lowStockItems = Ingredient::where('company_id', $companyId)
            ->where('is_active', true)
            ->whereColumn('current_stock', '<=', 'min_stock_level')
            ->orderBy('current_stock')
            ->limit(10)
            ->get();

        $recentOrders = RestaurantOrder::where('company_id', $companyId)
            ->with(['items', 'table'])
            ->where('created_at', '>=', $today)
            ->orderBy('created_at', 'desc')
            ->limit(15)
            ->get();

        $salesChartLabels = [];
        $salesChartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $salesChartLabels[] = $day->format('D');
            $salesChartData[] = (float) RestaurantOrder::where('company_id', $companyId)
                ->where('status', 'completed')
                ->whereDate('created_at', $day->toDateString())
                ->sum('total_amount');
        }

        $orderTypeCounts = RestaurantOrder::where('company_id', $companyId)
            ->where('created_at', '>=', $today->copy()->subDays(7))
            ->where('status', 'completed')
            ->select('order_type', DB::raw('count(*) as cnt'))
            ->groupBy('order_type')
            ->pluck('cnt', 'order_type')
            ->toArray();

        if (empty($orderTypeCounts)) {
            $orderTypeCounts = ['dine_in' => 0, 'takeaway' => 0, 'delivery' => 0];
        }

        $peakHour = null;
        $peakData = RestaurantOrder::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $today)
            ->select(DB::raw('EXTRACT(HOUR FROM created_at) as hr'), DB::raw('SUM(total_amount) as total'))
            ->groupBy('hr')
            ->orderByDesc('total')
            ->first();
        if ($peakData && $peakData->total > 0) {
            $h = (int) $peakData->hr;
            $peakHour = date('g:00 A', mktime($h)) . ' - ' . date('g:00 A', mktime($h + 1));
        }

        $todayTax = RestaurantOrder::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $today)
            ->sum('tax_amount');

        $todayDiscount = RestaurantOrder::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $today)
            ->sum('discount_amount');

        $todayCost = RestaurantOrder::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $today)
            ->sum('estimated_cost');
        $todayProfit = $todaySales - $todayCost;

        return view('pos.restaurant.dashboard', compact(
            'company', 'todaySales', 'yesterdaySales', 'todayOrders',
            'heldCount', 'completedCount', 'totalTables', 'occupiedTables',
            'topProducts', 'lowStockItems', 'recentOrders',
            'salesChartLabels', 'salesChartData', 'orderTypeCounts',
            'peakHour', 'todayTax', 'todayDiscount',
            'todayCost', 'todayProfit'
        ));
    }

    public function receipt($transactionId)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $transaction = PosTransaction::where('company_id', $companyId)
            ->with(['items', 'creator'])
            ->findOrFail($transactionId);

        $order = RestaurantOrder::where('company_id', $companyId)
            ->where('pos_transaction_id', $transaction->id)
            ->with('table')
            ->first();

        return view('pos.restaurant.receipt', compact('transaction', 'company', 'order'));
    }

    public function verifyManagerPin(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $pin = $request->input('pin');
        if (!$company->manager_override_pin) {
            return response()->json(['success' => false, 'message' => 'Manager PIN not configured'], 400);
        }
        if (password_verify($pin, $company->manager_override_pin)) {
            return response()->json(['success' => true]);
        }
        return response()->json(['success' => false, 'message' => 'Invalid PIN'], 403);
    }

    public function markReceiptPrinted(Request $request, $transactionId)
    {
        $companyId = app('currentCompanyId');
        $txn = PosTransaction::where('company_id', $companyId)->findOrFail($transactionId);
        if ($txn->receipt_printed_at) {
            $txn->increment('reprint_count');
            return response()->json(['success' => true, 'reprint' => true, 'count' => $txn->reprint_count]);
        }
        $txn->update(['receipt_printed_at' => now()]);
        return response()->json(['success' => true, 'reprint' => false]);
    }

    public function customerHistory($customerId)
    {
        $companyId = app('currentCompanyId');
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($customerId);

        $recentOrders = RestaurantOrder::where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->with('items')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($o) {
                return [
                    'id' => $o->id,
                    'order_number' => $o->order_number,
                    'total' => (float)$o->total_amount,
                    'date' => $o->created_at->format('M d, g:i A'),
                    'items' => $o->items->map(fn($i) => [
                        'item_id' => $i->item_id,
                        'item_type' => $i->item_type,
                        'name' => $i->item_name,
                        'qty' => (float)$i->quantity,
                        'price' => (float)$i->unit_price,
                    ]),
                ];
            });

        $favorites = RestaurantOrderItem::whereHas('order', function ($q) use ($companyId, $customer) {
            $q->where('company_id', $companyId)
              ->where('customer_id', $customer->id)
              ->where('status', 'completed');
        })
        ->select('item_id', 'item_type', 'item_name', DB::raw('SUM(quantity) as total_qty'), DB::raw('COUNT(*) as order_count'))
        ->groupBy('item_id', 'item_type', 'item_name')
        ->orderByDesc('total_qty')
        ->limit(5)
        ->get();

        $totalOrders = RestaurantOrder::where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->count();
        $totalSpent = RestaurantOrder::where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->sum('total_amount');

        return response()->json([
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'total_orders' => $totalOrders,
            'total_spent' => round((float)$totalSpent, 2),
            'recent_orders' => $recentOrders,
            'favorites' => $favorites->map(fn($f) => ['name' => $f->item_name, 'count' => (int)$f->total_qty]),
        ]);
    }

    public function saveManagerPin(Request $request)
    {
        $companyId = app('currentCompanyId');
        $user = Auth::guard('pos')->user();
        if ($user->pos_role !== 'pos_admin' && $user->role !== 'company_admin') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        $request->validate([
            'pin' => 'nullable|digits_between:4,6',
            'cashier_discount_limit' => 'nullable|numeric|min:0|max:100',
            'manager_discount_limit' => 'nullable|numeric|min:0|max:100',
        ]);
        $updates = [];
        if ($request->pin) {
            $updates['manager_override_pin'] = bcrypt($request->pin);
        }
        if ($request->has('cashier_discount_limit')) {
            $updates['cashier_discount_limit'] = $request->cashier_discount_limit;
        }
        if ($request->has('manager_discount_limit')) {
            $updates['manager_discount_limit'] = $request->manager_discount_limit;
        }
        if (!empty($updates)) {
            $oldValues = Company::where('id', $companyId)->first(['cashier_discount_limit', 'manager_discount_limit'])?->toArray();
            Company::where('id', $companyId)->update($updates);
            try {
                $logUpdates = $updates;
                unset($logUpdates['manager_override_pin']);
                if ($request->pin) $logUpdates['pin_changed'] = true;
                AuditLogService::log('settings_updated', 'company', $companyId, $oldValues, $logUpdates, $companyId, $user->id);
            } catch (\Exception $e) {
                Log::warning('Audit log failed: ' . $e->getMessage());
            }
        }
        return response()->json(['success' => true, 'message' => 'Settings saved']);
    }
}
