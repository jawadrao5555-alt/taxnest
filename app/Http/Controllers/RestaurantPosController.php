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
use Illuminate\Support\Facades\Schema;
use App\Services\ProductImageService;
use App\Services\AuditLogService;

class RestaurantPosController extends Controller
{
    public function pos(Request $request)
    {
        // POS UNIFICATION: the dedicated restaurant sale screen is retired. Every cashier
        // now bills on the single universal screen (pos.universal), which adapts to
        // restaurant mode via company feature settings. Carry table_id through so a table
        // tap still opens the order on the universal screen with that table selected.
        return redirect()->route('pos.invoice.create', $request->only('table_id'));

        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        // Load ALL active products (including show_on_sale=false). "Hidden from sale screen"
        // products MUST stay loaded so the cashier can still SEARCH them by name and add to the
        // cart — the hidden flag only declutters the browsable grid, it never blocks search.
        $products = PosProduct::where('company_id', $companyId)
            ->where('is_active', true)
            ->get();

        $services = PosService::where('company_id', $companyId)
            ->where('is_active', true)
            ->get();

        // Category pills are built from VISIBLE products only — a category that contains only
        // hidden products should not surface an (apparently empty) browse pill.
        $categories = $products->filter(fn($p) => (bool)($p->show_on_sale ?? true))
            ->pluck('category')->filter()->unique()->sort()->values();

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

        $taxRate = PosTaxRule::getRateForMethod('cash', $company);
        $taxRules = PosTaxRule::effectiveRules($company);

        // Inventory master switch — when company has inventory_enabled = false, suppress
        // ALL stock indicators (OUT/LOW dots, low-stock popup, block_out_of_stock).
        // Recipes are still loaded (for ingredient-cost reporting), but no UI badges/alerts emitted.
        $inventoryOn = (bool)($company->inventory_enabled ?? false);

        $stockStatus = [];
        $recipes = ProductRecipe::where('company_id', $companyId)
            ->with('ingredient')
            ->get()
            ->groupBy('product_id');

        if ($inventoryOn) {
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
        }

        // block_out_of_stock is meaningless when inventory module is OFF — force false.
        $blockOutOfStock = $inventoryOn ? (bool)($company->block_out_of_stock ?? false) : false;

        $user = Auth::guard('pos')->user();
        $posRole = $user->pos_role ?? 'pos_cashier';
        $discountLimit = $posRole === 'pos_admin'
            ? (float)($company->manager_discount_limit ?? 50)
            : (float)($company->cashier_discount_limit ?? 50);
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

        // Inventory OFF → no low-stock query at all (popup cannot open).
        $lowStockAlerts = $inventoryOn
            ? Ingredient::where('company_id', $companyId)
                ->where('is_active', true)
                ->whereColumn('current_stock', '<=', 'min_stock_level')
                ->select('name', 'current_stock', 'min_stock_level', 'unit')
                ->get()
            : collect();

        // Frontend defensive double-guard — even if some downstream code populates lowStockAlerts
        // (e.g. cache rehydrate, accidental flag flip), the inventoryEnabled gate keeps the UI hidden.
        $inventoryEnabled = $inventoryOn;

        return view('pos.restaurant.pos', compact(
            'company', 'products', 'services', 'categories',
            'recipeLookup', 'tables', 'selectedTable', 'heldOrders',
            'customers', 'taxRate', 'taxRules', 'stockStatus', 'blockOutOfStock',
            'posRole', 'discountLimit', 'hasManagerPin', 'ingredientCosts',
            'lowStockAlerts', 'inventoryEnabled'
        ));
    }

    public function holdOrder(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $user = Auth::guard('pos')->user();

        // T006 — `manual` item_type allows cashier-typed cart lines with no product master
        // (Enter-fallback when no product matches search). item_id is null; item_name +
        // unit_price are required and bounded. For product/service, item_id remains required.
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_type' => 'required|in:product,service,manual',
            'items.*.item_id' => 'required_if:items.*.item_type,product,service|nullable|integer',
            'items.*.item_name' => 'required_if:items.*.item_type,manual|nullable|string|max:120',
            'items.*.unit_price' => 'required_if:items.*.item_type,manual|nullable|numeric|min:0|max:9999999',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.item_discount_type' => 'nullable|in:percentage,amount',
            'items.*.item_discount_value' => 'nullable|numeric|min:0|max:999999',
            'items.*.is_tax_exempt' => 'nullable|boolean',
            'order_type' => 'required|in:dine_in,takeaway,delivery',
            'discount_type' => 'nullable|in:percentage,amount',
            'discount_value' => 'nullable|numeric|min:0|max:999999',
        ]);

        // Order-type flow rules (owner, Jul 2026): Hold / Send-to-Kitchen is the Dine-In
        // procedure ONLY on companies where the order-type widget is visible (any of
        // tables/kot/kitchen/delivery on). Takeaway = direct final bill; Delivery = final
        // or provisional. Plain retail (widget hidden) keeps hold unrestricted.
        // billing_flow EXEMPTION: the universal screen's normal payment pipeline routes
        // plain-product restaurant sales through hold-then-pay (processPayment →
        // hold → payOrder) for KOT/restaurant_orders bookkeeping — that internal
        // pass-through sends billing_flow=true and must NOT be blocked, or every
        // final Takeaway/Delivery sale 422s. This is a workflow rule (not a security
        // boundary), so trusting the client flag is acceptable: the explicit Hold
        // button / F5 sends no flag and stays gated. Provisional abuse is impossible
        // via this bypass — payOrder has its own delivery-only provisional gate.
        $flowFeatures = \App\Services\PosFeatureService::forCompany($company);
        $typeFlowGate = ($flowFeatures->tables ?? false) || ($flowFeatures->kot ?? false) || ($flowFeatures->kitchen ?? false) || ($flowFeatures->delivery ?? false);
        if ($typeFlowGate && !$request->boolean('billing_flow') && $request->input('order_type') !== 'dine_in') {
            return response()->json(['success' => false, 'message' => 'Hold / Send to Kitchen is for Dine-In orders only. Takeaway is billed directly; Delivery is final or provisional.'], 422);
        }

        // Table-required invariant (owner voice note, 9 Aug 2026): when the company
        // manages tables, a Dine-In punch — explicit Hold/KOT *or* the internal
        // billing pass-through (billing_flow) — must never create an order/KOT
        // without a table. Waiter punch path has the same guard.
        if (($flowFeatures->tables ?? false) && $request->input('order_type') === 'dine_in' && !$request->table_id) {
            return response()->json(['success' => false, 'message' => __('pos.dine_in_table_required')], 422);
        }

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
            $qty = (int)$item['quantity'];
            if ($item['item_type'] === 'manual') {
                // T006 — cashier-typed cart line. Trust the typed price within validator bounds;
                // no product/service lookup, no recipe/stock binding, no master record created.
                $manualName = trim((string)($item['item_name'] ?? ''));
                $manualPrice = round((float)($item['unit_price'] ?? 0), 2);
                if ($manualName === '') {
                    return response()->json(['success' => false, 'message' => 'Manual line: name is required'], 400);
                }
                $lineTotal = round($qty * $manualPrice, 2);
                $itemDiscountType = $item['item_discount_type'] ?? null;
                $itemDiscountValue = (float)($item['item_discount_value'] ?? 0);
                $itemDiscountAmount = 0;
                if ($itemDiscountValue > 0 && $itemDiscountType === 'percentage') {
                    $itemDiscountAmount = round($lineTotal * min(100, $itemDiscountValue) / 100, 2);
                } elseif ($itemDiscountValue > 0 && $itemDiscountType === 'amount') {
                    $itemDiscountAmount = min($lineTotal, round($itemDiscountValue, 2));
                }
                $itemExempt = array_key_exists('is_tax_exempt', $item) ? (bool)$item['is_tax_exempt'] : false;
                $resolvedItems[] = [
                    'item_type' => 'manual',
                    'item_id' => null,
                    'item_name' => $manualName,
                    'quantity' => $qty,
                    'unit_price' => $manualPrice,
                    'subtotal' => round($lineTotal - $itemDiscountAmount, 2),
                    // Task 636: cashier path gets the same identity-autofill note discard as waiter punches
                    'special_notes' => RestaurantWaiterController::stripIdentityNote($item['special_notes'] ?? null, $user),
                    'is_tax_exempt' => $itemExempt,
                    'item_discount_type' => $itemDiscountValue > 0 ? $itemDiscountType : null,
                    'item_discount_value' => $itemDiscountValue,
                    'item_discount_amount' => $itemDiscountAmount,
                ];
            } elseif ($item['item_type'] === 'product') {
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
                // Tax-exempt: cashier's cart toggle wins over the product master setting.
                // If `items.*.is_tax_exempt` is sent in the payload, honor it; otherwise fall back
                // to the product's master flag. This lets the cashier mark any line ad-hoc exempt
                // (or remove a default exemption) without editing the product record.
                $itemExempt = array_key_exists('is_tax_exempt', $item)
                    ? (bool)$item['is_tax_exempt']
                    : (bool)($product->is_tax_exempt ?? false);
                $resolvedItems[] = [
                    'item_type' => 'product',
                    'item_id' => $product->id,
                    'item_name' => $product->name,
                    'quantity' => $qty,
                    'unit_price' => (float)$product->price,
                    'subtotal' => round($lineTotal - $itemDiscountAmount, 2),
                    'special_notes' => RestaurantWaiterController::stripIdentityNote($item['special_notes'] ?? null, $user),
                    'is_tax_exempt' => $itemExempt,
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
                $itemExempt = array_key_exists('is_tax_exempt', $item)
                    ? (bool)$item['is_tax_exempt']
                    : (bool)($service->is_tax_exempt ?? false);
                $resolvedItems[] = [
                    'item_type' => 'service',
                    'item_id' => $service->id,
                    'item_name' => $service->name,
                    'quantity' => $qty,
                    'unit_price' => (float)$service->price,
                    'subtotal' => round($lineTotal - $itemDiscountAmount, 2),
                    'special_notes' => RestaurantWaiterController::stripIdentityNote($item['special_notes'] ?? null, $user),
                    'is_tax_exempt' => $itemExempt,
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
            $maxDiscountPct = (float)($company->cashier_discount_limit ?? 50);
        }
        if ($discountType === 'percentage' && $discountValue > 0) {
            $discountValue = min($discountValue, $maxDiscountPct);
            $discountAmount = round($subtotal * min(100, $discountValue) / 100, 2);
        } elseif ($discountType === 'amount' && $discountValue > 0) {
            // Amount discounts follow the SAME percentage cap (owner rule, Jul 2026:
            // both discount types limited alike) — cashier capped at limit% of subtotal;
            // matches the cart UI's checkDiscountLimit / maxAmountDiscount logic.
            $maxAmountFromPct = round($subtotal * min(100, $maxDiscountPct) / 100, 2);
            $discountAmount = min($subtotal, min($maxAmountFromPct, round($discountValue, 2)));
        }
        $discountAmount = max(0, $discountAmount);

        $taxableSubtotal = array_sum(array_column(array_filter($resolvedItems, fn($i) => !($i['is_tax_exempt'] ?? false)), 'subtotal'));
        $discountRatio = $subtotal > 0 ? ($subtotal - $discountAmount) / $subtotal : 1;
        $adjustedTaxable = round($taxableSubtotal * $discountRatio, 2);

        $taxRate = PosTaxRule::getRateForMethod('cash', $company);
        // Tax-Inclusive Pricing (Menu-Rate-Final): held-order totals are ESTIMATES at
        // the cash rate — final math happens in payOrder. Inclusive mode: menu price
        // IS the total, tax shown is the included portion.
        if ((bool) ($company->pos_tax_inclusive ?? false)) {
            $taxAmount = \App\Services\PosTaxMath::inclusiveLineTax((float) $adjustedTaxable, (float) $taxRate);
            $totalAmount = (float) round($subtotal - $discountAmount);
        } else {
            // Whole-rupee rounding — matches PosController::storeInvoice and the universal
            // cart's roundedTotal getter (Math.round). Pakistan POS convention: tax + bill
            // always whole rupees, no paisa. Was round(...,2) → 533.60 vs frontend 534.
            $taxAmount = (float) round($adjustedTaxable * $taxRate / 100);
            $totalAmount = (float) round($subtotal - $discountAmount + $taxAmount);
        }

        DB::beginTransaction();
        try {
            // Phase 5 — KOT tracking. If this is a re-send (recalled order)
            // carry the prior print count forward so the new ticket prints "UPDATED".
            $carriedKotCount = 0;
            // KOT delta on recall (owner, Jul 2026): recall+re-hold recreates the
            // order, which used to wipe kot_printed_at on EVERY line — the next
            // ticket re-fired all dishes. Task 778 (Pizza Master video, Aug 2026):
            // the carry used to match lines QUANTITY-INCLUSIVE, so bumping a sent
            // item's qty made the WHOLE line unprinted and the add-on slip printed
            // the CUMULATIVE qty (kitchen fired 1+2=3 bottles for a 2-bottle
            // order). Carry is now IDENTITY-based (type/id/name/notes) with
            // per-identity printed-qty chunks: an increased line is SPLIT into
            // stamped "already sent" row(s) + ONE unprinted delta row, so every
            // delta path prints only the added qty. Decreases just consume fewer
            // chunks — nothing new prints (no phantom KOT). Duplicate identical
            // lines keep the shared-pool shift behaviour.
            $printedPool = [];
            $hadPrintedRows = false;
            $oldOrder = null;
            $kotCarryKey = function ($type, $id, $name, $notes) {
                return implode('|', [$type, (string) $id, mb_strtolower(trim((string) $name)), trim((string) $notes)]);
            };
            if ($request->recalled_order_id) {
                $oldOrder = RestaurantOrder::where('id', $request->recalled_order_id)
                    ->where('company_id', $companyId)
                    ->whereIn('status', ['held', 'preparing', 'ready'])
                    ->lockForUpdate()
                    ->first();
                if ($oldOrder) {
                    $carriedKotCount = (int) ($oldOrder->kot_print_count ?? 0);
                    foreach ($oldOrder->items()->whereNotNull('kot_printed_at')->orderBy('kot_batch_no')->orderBy('id')->get() as $oi) {
                        $printedPool[$kotCarryKey($oi->item_type, $oi->item_id, $oi->item_name, $oi->special_notes)][] = [
                            'qty' => (float) $oi->quantity,
                            'kot_printed_at' => $oi->kot_printed_at,
                            'kot_batch_no' => $oi->kot_batch_no,
                            // Task 794: the pool key lowercases the name (identity
                            // matching) — keep the display-cased name for the void slip.
                            'display_name' => $oi->item_name,
                        ];
                        $hadPrintedRows = true;
                    }
                    $oldOrder->items()->delete();
                    // Task 778 ORDER IDENTITY CARRY: the replacement order keeps the
                    // ORIGINAL order_number (token carried below) so the kitchen
                    // reads both slips as ONE order — the add-on slip used to get a
                    // brand-new ORD number. order_number is UNIQUE — free it by
                    // suffixing the superseded row ('~'+id stays unique + traceable;
                    // superseded rows are excluded from every report/list).
                    $orderNumber = $oldOrder->order_number;
                    // Task 506: yeh SYSTEM supersede hai (recall + re-hold), human
                    // cancel nahi — superseded_at stamp karo taake Cancelled Orders
                    // report / dashboard tile mein ghost row na bane. Status
                    // 'cancelled' hi rehta hai (blacklist queries leak-safe).
                    $supersede = [
                        'status' => 'cancelled',
                        'order_number' => mb_substr($oldOrder->order_number, 0, 30 - strlen('~' . $oldOrder->id)) . '~' . $oldOrder->id,
                    ];
                    if (Schema::hasColumn('restaurant_orders', 'superseded_at')) {
                        $supersede['superseded_at'] = now();
                    }
                    $oldOrder->update($supersede);
                    if ($oldOrder->table_id) {
                        $activeOnTable = RestaurantOrder::where('table_id', $oldOrder->table_id)
                            ->where('company_id', $companyId)
                            ->where('id', '!=', $oldOrder->id)
                            ->whereIn('status', ['held', 'preparing', 'ready'])
                            ->exists();
                        if (!$activeOnTable) {
                            RestaurantTable::where('id', $oldOrder->table_id)->update(['status' => 'available', 'locked_by_user_id' => null, 'locked_at' => null, 'occupied_since' => null]);
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

            // Order Matching (Aug 2026): Daily Token allocated company-centrally
            // at ORDER birth — add-ons/KOT reprints/final bill all keep this token.
            // Task 778: a recalled order's replacement CARRIES the original token
            // (the update slip must match the first slip) — never draws a fresh one.
            $tokenNo = ($oldOrder && $oldOrder->token_no !== null)
                ? (int) $oldOrder->token_no
                : ((($company->order_match_style ?? 'off') === 'token')
                    ? \App\Services\OrderTokenService::nextToken($companyId)
                    : null);

            $order = RestaurantOrder::create([
                'company_id' => $companyId,
                'order_number' => $orderNumber,
                'token_no' => $tokenNo,
                'table_id' => $request->table_id,
                'order_type' => $request->order_type,
                'status' => 'held',
                'customer_id' => $request->customer_id,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                // Task 183: delivery-address snapshot on the HELD row (FBR Task 170
                // parity) — recall restores it; pay falls back to it when the screen
                // doesn't resend one.
                'delivery_address' => ($request->input('order_type') === 'delivery' && $request->filled('delivery_address'))
                    ? substr((string) $request->input('delivery_address'), 0, 500)
                    : null,
                'subtotal' => $subtotal,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'estimated_cost' => round($estimatedCost, 2),
                // Task 636: kitchen_notes on the held order header is printed on every
                // KOT — apply the same identity-autofill discard as per-item notes.
                'kitchen_notes' => RestaurantWaiterController::stripIdentityNote(
                    $request->kitchen_notes,
                    $user
                ),
                'priority' => (bool)($request->priority ?? false),
                'created_by' => $user->id,
                // Phase 5 — every held order is implicitly "sent to kitchen".
                // Carry kot_print_count forward on re-send so the ticket shows "UPDATED".
                'kot_sent_at' => now(),
                'kot_print_count' => $carriedKotCount + 1,
            ]);

            foreach ($resolvedItems as $item) {
                // Task 778 QUANTITY-AWARE CARRY: consume this identity's printed
                // chunks first; any qty beyond the already-sent total becomes ONE
                // unprinted delta row — every delta path then prints just that.
                $ck = $kotCarryKey($item['item_type'], $item['item_id'], $item['item_name'], $item['special_notes'] ?? '');
                $lineQty = (float) $item['quantity'];
                $chunks = [];
                $remaining = $lineQty;
                while ($remaining > 0.001 && !empty($printedPool[$ck])) {
                    $chunk = array_shift($printedPool[$ck]);
                    $use = min((float) $chunk['qty'], $remaining);
                    $chunks[] = ['qty' => $use, 'kot_printed_at' => $chunk['kot_printed_at'], 'kot_batch_no' => $chunk['kot_batch_no']];
                    if ((float) $chunk['qty'] - $use > 0.001) {
                        // Qty DECREASED: return the leftover for a possible duplicate
                        // line; unclaimed leftovers simply aren't re-created (and
                        // nothing new prints — no phantom KOT on decrease/remove).
                        $chunk['qty'] = (float) $chunk['qty'] - $use;
                        array_unshift($printedPool[$ck], $chunk);
                    }
                    $remaining -= $use;
                }
                if ($remaining > 0.001 || empty($chunks)) {
                    $chunks[] = ['qty' => max($remaining, 0), 'kot_printed_at' => null, 'kot_batch_no' => null];
                }
                // Chunks partition the line's quantity exactly. Split the money
                // columns proportionally; the LAST chunk absorbs the rounding
                // remainder so split rows always sum EXACTLY to the original line
                // (bill/system totals unchanged). Single chunk = original figures.
                $n = count($chunks);
                $lineSubtotal = (float) $item['subtotal'];
                $lineDiscAmt = (float) ($item['item_discount_amount'] ?? 0);
                $lineDiscVal = (float) ($item['item_discount_value'] ?? 0);
                $discType = $item['item_discount_type'] ?? null;
                $accSub = 0.0; $accDisc = 0.0; $accVal = 0.0;
                foreach ($chunks as $ci => $chunk) {
                    $row = $item;
                    $row['quantity'] = $chunk['qty'];
                    // Only carried chunks write the stamp keys — fresh/delta rows
                    // omit them entirely (prod schema-drift safe, matches the
                    // pre-778 behaviour for plain holds).
                    if ($chunk['kot_printed_at'] !== null) {
                        $row['kot_printed_at'] = $chunk['kot_printed_at'];
                        $row['kot_batch_no'] = $chunk['kot_batch_no'];
                    }
                    if ($ci === $n - 1) {
                        $row['subtotal'] = round($lineSubtotal - $accSub, 2);
                        $row['item_discount_amount'] = round($lineDiscAmt - $accDisc, 2);
                        $row['item_discount_value'] = $discType === 'amount' ? round($lineDiscVal - $accVal, 2) : $lineDiscVal;
                    } else {
                        $ratio = $lineQty > 0 ? ($chunk['qty'] / $lineQty) : 0;
                        $row['subtotal'] = round($lineSubtotal * $ratio, 2);
                        $row['item_discount_amount'] = round($lineDiscAmt * $ratio, 2);
                        // 'amount' discounts must split too — recall maps rows back
                        // to cart lines 1:1, a full value on BOTH rows would double
                        // the discount on the next re-hold. Percentage stays as-is.
                        $row['item_discount_value'] = $discType === 'amount' ? round($lineDiscVal * $ratio, 2) : $lineDiscVal;
                        $accSub += $row['subtotal'];
                        $accDisc += $row['item_discount_amount'];
                        $accVal += $discType === 'amount' ? $row['item_discount_value'] : 0;
                    }
                    RestaurantOrderItem::create(array_merge($row, ['order_id' => $order->id]));
                }
            }

            // Task 794 VOID SLIP: leftover chunks in the carry pool = printed qty
            // the new cart did NOT re-claim (cashier removed a dish / decreased a
            // qty below what already went to the kitchen). Nothing new prints as
            // a KOT (no phantom slips) — instead the kitchen gets a small VOID
            // slip telling it to STOP making the removed qty. Collected here
            // (inside the txn, pool is fully consumed); enqueued after commit.
            $voidItems = [];
            foreach ($printedPool as $poolKey => $chunks) {
                $leftQty = 0.0;
                $displayName = null;
                foreach ($chunks as $chunk) {
                    $leftQty += (float) $chunk['qty'];
                    $displayName = $displayName ?? ($chunk['display_name'] ?? null);
                }
                if ($leftQty > 0.001) {
                    // Key format mirrors kotCarryKey(): type|id|name(lowercased)|notes.
                    $parts = explode('|', $poolKey, 4);
                    $voidItems[] = [
                        'item_type' => $parts[0] ?? 'product',
                        'item_id'   => (isset($parts[1]) && $parts[1] !== '') ? $parts[1] : null,
                        'item_name' => $displayName ?? ($parts[2] ?? ''),
                        'notes'     => $parts[3] ?? '',
                        'qty'       => round($leftQty, 2),
                    ];
                }
            }

            if ($request->table_id) {
                $table = RestaurantTable::where('company_id', $companyId)->where('id', $request->table_id)->first();
                // Int-cast both sides: some MySQL/PDO setups (emulated prepares on
                // shared hosting) return integer columns as STRINGS, so a strict !==
                // against the int user id false-positives "another user" for the SAME
                // cashier who just reserved the table.
                if ($table && $table->locked_by_user_id && (int) $table->locked_by_user_id !== (int) $user->id) {
                    // Carbon 3: now()->diffInMinutes($past) is SIGNED (negative) — the old
                    // `< 30` check made every other-user lock permanent. Compare timestamps
                    // directly instead: block only locks fresher than 30 minutes.
                    $lockIsFresh = $table->locked_at && $table->locked_at->gt(now()->subMinutes(30));
                    if ($lockIsFresh) {
                        DB::rollBack();
                        return response()->json(['success' => false, 'message' => "Table T-{$table->table_number} is reserved by another cashier — pick a different table or ask them to release it"], 423);
                    }
                }
                RestaurantTable::where('company_id', $companyId)
                    ->where('id', $request->table_id)
                    ->update([
                        'status' => 'occupied',
                        'locked_by_user_id' => null,
                        'locked_at' => null,
                        // Occupied timer (owner, Jul 2026): keep the ORIGINAL sit-down
                        // time when appending/recalling on an already-occupied table;
                        // stamp fresh on a new occupation.
                        'occupied_since' => ($table && $table->status === 'occupied' && $table->occupied_since)
                            ? $table->occupied_since
                            : now(),
                    ]);
            }

            DB::commit();

            // Task 753 (Pizza Master, Aug 2026): APPEND-DELTA GUARANTEE.
            // KDS-auto-print config (KDS Auto Print ON) mein Print-on-Hold aksar
            // OFF hota hai — recall+append ki chhoti (delta) KOT ka wahid raasta
            // KDS board tha; board band/idle ho to slip kahin se nahi nikalti
            // thi. Ab jab recall par pehle-se-chhapi rows carry hui hon (yaani
            // genuinely APPEND case), server khud delta job enqueue karta hai —
            // KDS/cashier ke double-fire apiCreatePrintJob + KotPrintService ke
            // 2-min in-flight dedupe mein jazb ho jate hain. Best-effort: fail
            // par hold kabhi nahi rukta; client (universal.blade.php holdOrder)
            // flag=false dekh kar apna fallback (silent → iframe) chalata hai.
            $kotDeltaQueued = false;
            try {
                if ($request->recalled_order_id && $hadPrintedRows
                    && (bool) ($company->kds_enabled ?? true) && (bool) ($company->pos_kds_auto_print ?? false)) {
                    $enq = \App\Services\KotPrintService::enqueueForOrder($company, $order, $user->id, true);
                    $kotDeltaQueued = (bool) ($enq['printed'] ?? false) && !empty($enq['job_ids'] ?? []);
                }
            } catch (\Throwable $e) {
                Log::warning('Hold-time delta KOT enqueue failed: ' . $e->getMessage(), ['order_id' => $order->id]);
            }

            // Task 794: VOID slip for removed/decreased printed items. Silent path
            // first (Desktop Agent print job); the client falls back to the iframe
            // void-ticket route when the agent didn't take it. Best-effort — a
            // failed enqueue never blocks the hold. Unlike the delta enqueue above
            // this is NOT gated on KDS auto-print: a void is a stop-work order,
            // it must reach the kitchen whenever silent printing is available.
            $kotVoidQueued = false;
            $kotVoidUrl    = null;
            if (!empty($voidItems)) {
                try {
                    $enqVoid = \App\Services\KotPrintService::enqueueVoid($company, $order, $voidItems, $user->id);
                    $kotVoidQueued = (bool) ($enqVoid['printed'] ?? false) && !empty($enqVoid['job_ids'] ?? []);
                } catch (\Throwable $e) {
                    Log::warning('Hold-time void KOT enqueue failed: ' . $e->getMessage(), ['order_id' => $order->id]);
                }
                // Iframe fallback URL — same relative-URL convention as other POS
                // fetch/print URLs (route-absolute-https trap on plain-http dev).
                $kotVoidUrl = route('pos.restaurant.void-ticket', $order->id, false)
                    . '?void_items=' . urlencode(base64_encode(json_encode($voidItems)));
            }

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
                // Item #4 (owner, Jul 2026): load table too — the Held Orders modal
                // renders "Table: T-N" via x-if="order.table"; items-only left fresh
                // holds table-less in the list until a full page reload.
                'order' => $order->load(['items', 'table']),
                // Task 753: TRUE = server ne recall+append ki delta KOT queue kar
                // di — client dobara fire na kare (sirf toast dikhaye).
                'kot_delta_queued' => $kotDeltaQueued,
                // Task 794: void slip — queued TRUE = Desktop Agent job already
                // created (client shows a toast only); URL = iframe fallback the
                // client opens when queued is FALSE. NULL url = nothing voided.
                'kot_void_queued' => $kotVoidQueued,
                'kot_void_url'    => $kotVoidUrl,
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
        // Task #643 (owner voice note 13 Aug 2026): cancel = owner/manager work.
        // Single verdict (PosAccessService::orderCancelAllowed) drives this gate
        // AND every cancel UI entry point (board / bell panel / claimed cart).
        // Waiter self-cancel has its OWN endpoint + toggle — untouched.
        if (!\App\Services\PosAccessService::orderCancelAllowed(Auth::guard('pos')->user())) {
            return response()->json(['success' => false, 'message' => __('pos.order_cancel_not_allowed')], 403);
        }
        $order = RestaurantOrder::where('company_id', $companyId)->find($orderId);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }
        if ($order->status === 'completed') {
            return response()->json(['success' => false, 'message' => 'Cannot delete a completed order'], 400);
        }

        // Task 840 — WHOLE-ORDER VOID SLIP: collect every printed item BEFORE
        // the cancel so we can tell the kitchen to stop ALL dishes. Only items
        // with a kot_printed_at stamp are counted — a fresh hold cancelled
        // before any KOT got out stays silent (nothing printed = nothing to void).
        $company = Company::find($companyId);
        $printedItems = $order->items()->whereNotNull('kot_printed_at')->get();
        $voidItems = [];
        foreach ($printedItems as $oi) {
            $voidItems[] = [
                'item_type' => $oi->item_type ?? 'product',
                'item_id'   => $oi->item_id,
                'item_name' => $oi->item_name ?? '',
                'notes'     => $oi->special_notes ?? '',
                'qty'       => (float) $oi->quantity,
            ];
        }

        DB::beginTransaction();
        try {
            $orderData = ['order_number' => $order->order_number, 'total_amount' => $order->total_amount, 'status' => $order->status];
            $tableId = $order->table_id;
            // ZFC (2 Aug 2026): cancel = SOFT cancel. Order + items mehfooz rehte
            // hain (status='cancelled') taake Cancelled Orders report ban sake.
            // Har active-order query pehle se held/preparing/ready ya completed
            // par filter karti hai, is liye cancelled kahin leak nahi hota.
            $order->status = 'cancelled';
            if (Schema::hasColumn('restaurant_orders', 'cancelled_at')) {
                $order->cancelled_at = now();
            }
            if (Schema::hasColumn('restaurant_orders', 'cancelled_by')) {
                $order->cancelled_by = Auth::guard('pos')->id();
            }
            $order->save();
            // Item-wise made/unmade (ZFC, 2 Aug 2026): modal se aaye ids jin par
            // cashier ne "ban gaya tha" tick kiya. Sirf tab likho jab client ne
            // bheja ho (warna NULL = poochha nahi gaya).
            if ($request->has('made_item_ids') && Schema::hasColumn('restaurant_order_items', 'was_made')) {
                $madeIds = array_filter(array_map('intval', (array) $request->input('made_item_ids', [])));
                $order->items()->update(['was_made' => false]);
                if ($madeIds) {
                    $order->items()->whereIn('id', $madeIds)->update(['was_made' => true]);
                }
            }
            // F3 (Jul 2026): deleting a held order frees its table — unless another
            // active order is still parked on the same table.
            if ($tableId) {
                $stillActive = RestaurantOrder::where('company_id', $companyId)
                    ->where('table_id', $tableId)
                    ->whereIn('status', ['held', 'preparing', 'ready'])
                    ->exists();
                if (!$stillActive) {
                    RestaurantTable::where('company_id', $companyId)->where('id', $tableId)
                        ->update(['status' => 'available', 'locked_by_user_id' => null, 'locked_at' => null, 'occupied_since' => null]);
                }
            }
            try {
                if (class_exists(\App\Services\AuditLogService::class)) {
                    \App\Services\AuditLogService::log('order_deleted', 'restaurant_order', $orderId, $orderData, null, $companyId, Auth::guard('pos')->id());
                }
            } catch (\Exception $auditEx) {
            }
            DB::commit();

            // Task 840 — post-commit void enqueue. Best-effort: a failed enqueue
            // never affects the cancel itself. Desktop Agent silent path first;
            // client falls back to the iframe void-ticket route when queued=false.
            $kotVoidQueued = false;
            $kotVoidUrl    = null;
            if (!empty($voidItems) && $company) {
                try {
                    $enqVoid = \App\Services\KotPrintService::enqueueVoid($company, $order, $voidItems, Auth::guard('pos')->id());
                    $kotVoidQueued = (bool) ($enqVoid['printed'] ?? false) && !empty($enqVoid['job_ids'] ?? []);
                } catch (\Throwable $e) {
                    Log::warning('deleteOrder void KOT enqueue failed: ' . $e->getMessage(), ['order_id' => $order->id]);
                }
                // Iframe fallback URL — same relative-URL convention as holdOrder's
                // void path (route-absolute-https trap on plain-http dev, see
                // route-absolute-https-fetch.md in memory).
                $kotVoidUrl = route('pos.restaurant.void-ticket', $order->id, false)
                    . '?void_items=' . urlencode(base64_encode(json_encode($voidItems)));
            }

            return response()->json([
                'success'          => true,
                'message'          => 'Order deleted',
                'kot_void_queued'  => $kotVoidQueued,
                'kot_void_url'     => $kotVoidUrl,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Failed to delete order: ' . $e->getMessage()], 500);
        }
    }

    // ── Table Shift (owner batch, 26 Jul 2026) ─────────────────────────────
    // Move a HELD order to another AVAILABLE table. Har POS role kar sakta hai
    // (owner: "har ID se ho"). Rules: target khali ho, timer (occupied_since)
    // chalta rahe, KOT dobara NAHI — kot_* columns untouched. Race-safe:
    // lockForUpdate on order + BOTH tables, 409 if anything changed under us.
    public function shiftTable(Request $request, $orderId)
    {
        $companyId = app('currentCompanyId');
        if (!is_numeric($orderId) || $orderId < 1) {
            return response()->json(['success' => false, 'message' => 'Invalid order ID'], 400);
        }
        $request->validate(['table_id' => 'required|integer|min:1']);
        $targetTableId = (int) $request->input('table_id');

        DB::beginTransaction();
        try {
            $order = RestaurantOrder::where('company_id', $companyId)
                ->lockForUpdate()->find($orderId);
            if (!$order) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Order nahi mila'], 404);
            }
            if ($order->status !== 'held') {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Order ab held nahi — shift possible nahi'], 409);
            }
            if ((int) $order->table_id === $targetTableId) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Order isi table par hai'], 422);
            }

            $target = RestaurantTable::where('company_id', $companyId)
                ->where('is_active', true)
                ->lockForUpdate()->find($targetTableId);
            if (!$target) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Table nahi mila'], 404);
            }
            // Sirf KHALI table par shift (owner rule). Reserved/occupied = block.
            // Defense-in-depth: koi active order us table par parked na ho.
            $targetBusy = RestaurantOrder::where('company_id', $companyId)
                ->where('table_id', $target->id)
                ->whereIn('status', ['held', 'preparing', 'ready'])
                ->exists();
            if ($target->status !== 'available' || $targetBusy) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'T-' . $target->table_number . ' khali nahi hai'], 409);
            }

            $oldTableId = $order->table_id;
            $oldTable = $oldTableId
                ? RestaurantTable::where('company_id', $companyId)->lockForUpdate()->find($oldTableId)
                : null;

            // Timer continue: purane table ka occupied_since carry karo
            // (fallback order creation time — bina-table held order shift case).
            $since = ($oldTable && $oldTable->occupied_since) ? $oldTable->occupied_since : $order->created_at;

            RestaurantTable::where('company_id', $companyId)->where('id', $target->id)->update([
                'status' => 'occupied',
                'locked_by_user_id' => null,
                'locked_at' => null,
                'occupied_since' => $since,
            ]);

            $order->table_id = $target->id;
            $order->save();

            // Purana table free — magar sirf tab jab koi AUR active order us par na ho.
            if ($oldTableId) {
                $stillActive = RestaurantOrder::where('company_id', $companyId)
                    ->where('table_id', $oldTableId)
                    ->whereIn('status', ['held', 'preparing', 'ready'])
                    ->exists();
                if (!$stillActive) {
                    RestaurantTable::where('company_id', $companyId)->where('id', $oldTableId)
                        ->update(['status' => 'available', 'locked_by_user_id' => null, 'locked_at' => null, 'occupied_since' => null]);
                }
            }

            try {
                if (class_exists(AuditLogService::class)) {
                    AuditLogService::log(
                        'order_table_shifted',
                        'restaurant_order',
                        $order->id,
                        ['table_id' => $oldTableId, 'table_number' => $oldTable?->table_number],
                        ['table_id' => $target->id, 'table_number' => $target->table_number, 'order_number' => $order->order_number],
                        $companyId,
                        Auth::guard('pos')->id()
                    );
                }
            } catch (\Exception $auditEx) {
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Order T-' . $target->table_number . ' par shift ho gaya',
                'table' => ['id' => $target->id, 'table_number' => $target->table_number],
            ]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            DB::rollBack();
            throw $ve;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Table shift failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Shift nahi hua — dobara koshish karein'], 500);
        }
    }

    public function payOrder(Request $request, $orderId)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $user = Auth::guard('pos')->user();

        Log::info('[PAY] Incoming', [
            'order_id' => $orderId,
            'company_id' => $companyId,
            'user_id' => $user?->id,
            'payment_method' => $request->input('payment_method'),
            'payload' => $request->all(),
        ]);

        if (!is_numeric($orderId) || $orderId < 1) {
            return response()->json(['success' => false, 'message' => 'Invalid order ID'], 400);
        }

        try {
            $request->validate([
                // Task 407: MUST mirror PosController::storeInvoice's accepted set.
                // The Delivery Prepaid toggle (Task 287) overrides the method to
                // 'qr_payment' on EVERY submit path — rejecting it here 422'd every
                // Cash/Card/PAY/Provisional press on prepaid delivery bills at a
                // live shop (ZFC Pizza Point, 10 Aug 2026). Legacy 'online'/'split'
                // kept for old tabs still open on the previous build.
                'payment_method' => 'nullable|string|in:cash,card,debit_card,credit_card,qr_payment,online,split',
            ]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            Log::warning('[PAY] Validation failed', ['errors' => $ve->errors(), 'input' => $request->all()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation: ' . collect($ve->errors())->flatten()->implode(' | '),
                'errors' => $ve->errors(),
            ], 422);
        }

        $order = RestaurantOrder::where('company_id', $companyId)
            ->with('items')
            ->findOrFail($orderId);

        if ($order->status === 'completed') {
            return response()->json(['success' => false, 'message' => 'Order already paid'], 400);
        }

        // Monthly bill quota (paid-plan package limits, Jul 2026) — paying a
        // restaurant order creates a FINAL bill, same gate as storeInvoice.
        // Task 215: provisional settles are quota-FREE (mirrors storeInvoice's
        // save_as_provisional skip) so a quota-full shop can still park delivery
        // orders as provisionals mid-day instead of deadlocking. The quota is
        // charged later at promote time (retryPra / apiPromoteProvisional).
        if (!$request->boolean('save_as_provisional')) {
            $quota = \App\Services\PlanLimitService::canCreatePosBill($companyId);
            if (!($quota['allowed'] ?? true)) {
                // Task 216: tell the sale screen this 403 is the QUOTA gate and whether a
                // provisional retry would pass the flow rules (delivery-only on restaurant-ish
                // companies — mirrors the $saveAsProvisional gate further down). The UI then
                // offers a one-click "save as provisional" retry instead of a dead-end error.
                $flowFeatures = \App\Services\PosFeatureService::forCompany($company);
                $restaurantish = ($flowFeatures->tables ?? false) || ($flowFeatures->kot ?? false) || ($flowFeatures->kitchen ?? false) || ($flowFeatures->delivery ?? false);
                $provisionalAllowed = !$restaurantish || $order->order_type === 'delivery';
                return response()->json([
                    'success' => false,
                    'message' => \App\Services\SubscriptionAccessService::localizedLockReason($quota['reason']),
                    'quota_full' => true,
                    'provisional_allowed' => $provisionalAllowed,
                ], 403);
            }
        }

        $paymentMethod = $request->input('payment_method', 'cash');
        // Normalize aliases → canonical stored buckets (mirrors storeInvoice):
        // 'card'/'online' front-end aliases become 'debit_card' so PosTaxRule,
        // PRA PaymentMode mapping, and cash/card aggregations all see one bucket.
        if (in_array($paymentMethod, ['card', 'online', 'split'], true)) {
            $paymentMethod = 'debit_card';
        }
        $taxRate = PosTaxRule::getRateForMethod($paymentMethod, $company);

        $subtotal = $order->items->sum('subtotal');
        $discountAmount = (float)($order->discount_amount ?? 0);
        $discountRatio = $subtotal > 0 ? ($subtotal - $discountAmount) / $subtotal : 1;
        $taxableSubtotal = $order->items->where('is_tax_exempt', false)->sum('subtotal');
        $adjustedTaxable = round($taxableSubtotal * max(0, $discountRatio), 2);
        // Tax-Inclusive Pricing (Menu-Rate-Final, owner Jul 2026): order lines are menu
        // (tax-in) prices — back-calculate the included tax for the CHOSEN method and
        // store the header in ex-tax-consistent semantics (see PosTaxMath docblock).
        // Snapshot column guard mirrors PosController::storeInvoice.
        $taxInclusiveColumnExists = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'tax_inclusive');
        $pricingMode = $company->posTaxPricingMode();
        $taxInclusive = $taxInclusiveColumnExists && in_array($pricingMode, ['inclusive', 'inclusive_card_save'], true);
        // Card-save (mode 3): menu inclusive at the CASH rate, this bill's own
        // method rate applied on the derived base (mirrors PosController::storeInvoice).
        $menuRateColumnExists = \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'tax_menu_rate');
        $menuRate = null;
        if ($taxInclusive && $pricingMode === 'inclusive_card_save' && $menuRateColumnExists) {
            $menuRate = (float) PosTaxRule::getRateForMethod('cash', $company);
        }
        if ($taxInclusive) {
            $inc = \App\Services\PosTaxMath::inclusiveHeader((float) $subtotal, (float) $taxableSubtotal, (float) $discountAmount, (float) $taxRate, $menuRate);
            $taxAmount = $inc['tax_amount'];
            $totalAmount = $inc['total_amount'];
        } else {
            // Whole-rupee rounding — matches PosController::storeInvoice and the universal
            // cart's roundedTotal getter (Math.round). Was round(...,2) → decimal totals on
            // held/table-order bills while direct cart bills were whole-rupee.
            $taxAmount = (float) round($adjustedTaxable * $taxRate / 100);
            $totalAmount = (float) round($subtotal - $discountAmount + $taxAmount);
        }
        $totalItemDiscounts = $order->items->sum('item_discount_amount');

        // PROVISIONAL BILL FLOW — when cashier saves as provisional, the bill is created
        // with pra_status='local' regardless of company.pra_reporting_enabled, and PRA
        // submission is skipped entirely. The bill remains editable / deletable until
        // promoted to final via PosController::retryPra (the "Submit to PRA — Make Final"
        // button on the transaction-show provisional card).
        $saveAsProvisional = (bool) $request->input('save_as_provisional', false);
        // Order-type flow rules (owner, Jul 2026): provisional bills are DELIVERY-only on
        // restaurant-ish companies — Dine-In/Takeaway held orders must settle as FINAL bills.
        if ($saveAsProvisional && $order->order_type !== 'delivery') {
            $flowFeatures = \App\Services\PosFeatureService::forCompany($company);
            if (($flowFeatures->tables ?? false) || ($flowFeatures->kot ?? false) || ($flowFeatures->kitchen ?? false) || ($flowFeatures->delivery ?? false)) {
                return response()->json(['success' => false, 'message' => 'Provisional bills are for Delivery orders only — this held order must be paid as a final bill.'], 422);
            }
        }
        // Per-cashier toggle (owner rule Jul 2026): the ACTING user's own reporting switch.
        $praEnabled = (bool) auth('pos')->user()?->praReportingEnabled($company) && !$saveAsProvisional;
        // Reporting-OFF Finals Invariant — three-branch (mirrors PosController::storeInvoice):
        // provisional → local/'local'; reporting-ON final → pra/'pending';
        // reporting-OFF FINAL → pra/NULL (NEVER 'local' — local mode hides the bill from
        // the normal panel and pollutes the F10 provisional modal where cashiers could
        // edit/delete a final bill).
        if ($saveAsProvisional) {
            $invoiceMode = 'local';
            $initialPraStatus = 'local';
        } elseif ($praEnabled) {
            $invoiceMode = 'pra';
            $initialPraStatus = 'pending';
        } else {
            $invoiceMode = 'pra';
            $initialPraStatus = null;
        }

        // ── Billing Scope (owner request 07 Aug 2026) — mirrors storeInvoice ──
        // PRA stream = pra_status 'pending' at birth; local stream = provisionals
        // AND reporting-OFF finals. Guards direct POSTs; UI hides the buttons.
        $billingScope = auth('pos')->user()?->posBillingScope() ?? 'both';
        if ($billingScope === 'pra' && $initialPraStatus !== 'pending') {
            return response()->json(['success' => false, 'message' => __('pos.billing_scope_pra_only')], 403);
        }
        if ($billingScope === 'local' && $initialPraStatus === 'pending') {
            return response()->json(['success' => false, 'message' => __('pos.billing_scope_local_only')], 403);
        }

        // ── Bill Number Style (owner request 07 Aug 2026) — mirrors storeInvoice ──
        $billTokenFields = [];
        $billStream = $initialPraStatus === 'pending' ? 'pra' : 'local';
        $billStyleCol = $billStream === 'pra' ? 'pra_number_style' : 'local_number_style';
        if (($company->{$billStyleCol} ?? 'serial') === 'token'
            && \Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'bill_token')) {
            $payBillToken = \App\Services\OrderTokenService::nextBillToken($companyId, $billStream);
            if ($payBillToken !== null) {
                $billTokenFields = ['bill_token' => $payBillToken];
            }
        }

        DB::beginTransaction();
        try {
            // ── Single-winner guard (Table Board, Jul 2026) ──────────────────
            // Cross-terminal pays are first-class now (sale-screen Table Board):
            // re-fetch the order under a ROW LOCK and re-check status INSIDE the
            // txn — without this, two terminals settling the same held order both
            // pass the pre-txn status check and create DUPLICATE final bills (and
            // duplicate PRA submissions). Loser gets 409 + a refresh hint.
            $freshOrder = RestaurantOrder::where('company_id', $companyId)
                ->with('items')
                ->lockForUpdate()
                ->find($orderId);
            if (!$freshOrder || in_array($freshOrder->status, ['completed', 'cancelled'], true)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'This order was already settled or cancelled on another terminal — refresh the table board.',
                ], 409);
            }
            // Drift guard: header amounts were computed from the PRE-lock read. If a
            // waiter appended/edited lines in between, those amounts are stale — bail
            // out instead of writing a bill whose header doesn't match its lines.
            $freshSubtotal = round((float) $freshOrder->items->sum('subtotal'), 2);
            if ($freshSubtotal !== round((float) $subtotal, 2) || $freshOrder->items->count() !== $order->items->count()) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Order was just updated (new items) — refresh and try again.',
                ], 409);
            }
            $order = $freshOrder;

            $stockErrors = $this->validateStockForOrder($companyId, $order, true);
            if (!empty($stockErrors)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'stock_error' => true,
                    'message' => 'Insufficient stock: ' . implode(', ', $stockErrors),
                ], 400);
            }
            // Serial split (owner rule Jul 2026): POS fiscal serials only for bills
            // actually reported to PRA; provisionals AND reporting-OFF finals use
            // the L-series (mirrors PosController::storeInvoice).
            $invoiceNumber = $praEnabled
                ? $this->generateInvoiceNumber($companyId)
                : $this->generateLocalInvoiceNumber($companyId);

            $submissionHash = hash('sha256', $companyId . '|' . $invoiceNumber . '|' . $totalAmount . '|' . now()->timestamp);

            $transactionData = [
                'company_id' => (int) $companyId,
                'invoice_number' => $invoiceNumber,
                'invoice_mode' => $invoiceMode,
                'customer_id' => $order->customer_id ? (int) $order->customer_id : null,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                // Item #1 (Jul 2026): delivery-address SNAPSHOT sent with the PAY
                // request; frozen on the bill. Task 183: the held row now stores its
                // own snapshot too — fall back to it when a held DELIVERY order is
                // paid directly (no recall), so the address isn't silently dropped.
                'delivery_address' => $request->filled('delivery_address')
                    ? substr((string) $request->input('delivery_address'), 0, 500)
                    : (($order->order_type === 'delivery' && !empty($order->delivery_address))
                        ? substr((string) $order->delivery_address, 0, 500)
                        : null),
                'subtotal' => $taxInclusive ? $inc['subtotal_col'] : (float) $subtotal,
                'discount_type' => $order->discount_type ?? 'amount',
                'discount_value' => (float)($order->discount_value ?? 0),
                'discount_amount' => (float) $discountAmount,
                'tax_rate' => (float) $taxRate,
                'tax_amount' => (float) $taxAmount,
                'exempt_amount' => $taxInclusive ? $inc['exempt_amount'] : (float) ($subtotal - $taxableSubtotal),
                'total_amount' => (float) $totalAmount,
                'payment_method' => $paymentMethod,
                // Cash Received / Wapsi (owner request, Jul 2026): optional cashier
                // input from the pay modal — parity with PosController::storeInvoice.
                'cash_received' => $paymentMethod === 'cash' ? ((float) $request->input('cash_received') ?: $totalAmount) : null,
                'change_due' => $paymentMethod === 'cash' && (float) $request->input('cash_received') > 0 ? max(0, round((float) $request->input('cash_received') - (float) $totalAmount, 2)) : null,
                'status' => 'completed',
                'pra_status' => $initialPraStatus,
                'submission_hash' => $submissionHash,
                'created_by' => (int) $user->id,
                'notes' => $order->kitchen_notes,
            ] + $billTokenFields;
            if ($taxInclusiveColumnExists) {
                $transactionData['tax_inclusive'] = $taxInclusive;
            }
            if ($menuRateColumnExists) {
                $transactionData['tax_menu_rate'] = $menuRate;
            }
            // Delivery Riders (Jul 2026): snapshot the held order's type + optional
            // rider from the PAY request. Delivery-only; invalid rider ids silently
            // dropped (never block a payment). invoice_mode three-branch untouched.
            if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'rider_id')) {
                $payRiderId = null;
                // Plan gate (Aug 2026): riders is Pro+ — silently drop (never block payment).
                if ($order->order_type === 'delivery' && $request->filled('rider_id')
                    && \App\Services\PosFeatureService::planAllows($company, 'riders_enabled')) {
                    $payRiderId = \App\Models\PosRider::where('company_id', $companyId)
                        ->where('id', (int) $request->input('rider_id'))
                        ->where('is_active', true)
                        ->value('id');
                }
                $transactionData['order_type'] = $order->order_type;
                $transactionData['rider_id'] = $payRiderId;
                $transactionData['delivery_status'] = $payRiderId ? 'assigned' : null;
            }
            $transaction = PosTransaction::create($transactionData);

            // Task 778: KOT qty-carry may have SPLIT an increased line into
            // stamped + delta rows (kitchen bookkeeping only). The customer bill
            // must show ONE line per dish — consolidate identical rows before
            // creating transaction items. Sums are exact by construction.
            foreach ($this->consolidateBillLines($order->items) as $item) {
                $lineAfterOrderDisc = $subtotal > 0 ? round($item->subtotal * max(0, $discountRatio), 2) : $item->subtotal;
                $lineTax = $item->is_tax_exempt ? 0 : ($taxInclusive
                    ? \App\Services\PosTaxMath::inclusiveLineTax((float) $lineAfterOrderDisc, (float) $taxRate, $menuRate)
                    : round($lineAfterOrderDisc * $taxRate / 100, 2));
                $itemQty = max(1, (int) $item->quantity);
                PosTransactionItem::create([
                    'transaction_id' => (int) $transaction->id,
                    'item_type' => $item->item_type,
                    // T006 — manual lines carry NULL item_id (no product/service master).
                    'item_id' => $item->item_id !== null ? (int) $item->item_id : null,
                    'item_name' => (string) $item->item_name,
                    'quantity' => $itemQty,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                    'is_tax_exempt' => (bool) $item->is_tax_exempt,
                    'tax_rate' => $item->is_tax_exempt ? 0 : (float) $taxRate,
                    'tax_amount' => (float) $lineTax,
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
                        'occupied_since' => null,
                    ]);
                }
            }

            if ($order->customer_id) {
                $this->updateCustomerStats($order->customer_id, $totalAmount);
            }

            DB::commit();

            // Slow-bill diagnosis (ZFC video, 1 Aug 2026: "30 sec Creating bill"):
            // time the PRA leg + whole request; warn when a settle is slow so we
            // can tell PRA/network delay apart from server work in live logs.
            $praMs = null;
            if ($praEnabled) {
                $praT0 = microtime(true);
                // Agent Sync / Fiscal Device (Task 631, ZFC video 13 Aug 2026): NEVER
                // direct-submit from the server on the settle path — the US server's
                // curl to PRA cloud times out after 8s (confirmed live: txn 1786
                // "Operation timed out after 8002 milliseconds"), freezing the
                // cashier's "Creating bill" spinner for the full timeout before the
                // bill fell back to 'pending' anyway. Mirrors PosController::storeInvoice:
                // the bill is already 'pending' at birth — the desktop agent polls it
                // within seconds and submits from the shop PC's Pakistani IP.
                if ($company->agentHandlesPra()) {
                    // bill stays 'pending'; nothing to do — settle returns instantly.
                } else {
                    try {
                        $praService = new \App\Services\PraIntegrationService($company);
                        $praResult = $praService->sendInvoice($transaction);
                        // Task 760: exempt items now report at 0%, so all-exempt
                        // bills submit like any other — sendInvoice no longer
                        // returns the old exempt_only flag (kept in the guard for
                        // safety: success without a real submission must never be
                        // stamped 'submitted' with an empty fiscal number — live
                        // bug precedent: ZFC bills 1787/1791, 13 Aug 2026).
                        if ($praResult && !empty($praResult['success']) && empty($praResult['exempt_only'])) {
                            $transaction->update([
                                'pra_status' => 'submitted',
                                'pra_invoice_number' => $praResult['pra_invoice_number'] ?? null,
                                'pra_response_code' => $praResult['response_code'] ?? null,
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $transaction->update(['pra_status' => 'offline']);
                        Log::warning('PRA submission failed: ' . $e->getMessage());
                    }
                }
                $praMs = (int) round((microtime(true) - $praT0) * 1000);
                if ($praMs > 3000) {
                    Log::warning('Slow settle: PRA leg took ' . $praMs . 'ms', [
                        'company_id' => $companyId,
                        'transaction_id' => $transaction->id,
                    ]);
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
                'pra_invoice_number' => $transaction->pra_invoice_number ?? null,
                'pra_status' => $transaction->pra_status ?? null,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            $errorDetail = $e->getMessage() ?: '(empty message)';
            $errorClass = get_class($e);
            $errorWhere = basename($e->getFile()) . ':' . $e->getLine();
            if ($e->getPrevious()) {
                $errorDetail .= ' | PREVIOUS: ' . $e->getPrevious()->getMessage();
            }
            if ($e instanceof \Illuminate\Database\QueryException) {
                $errorDetail .= ' | SQL: ' . $e->getSql() . ' | Bindings: ' . json_encode($e->getBindings());
            }
            Log::error('[PAY] Failed order ' . $orderId . ' [' . $errorClass . ' @ ' . $errorWhere . ']: ' . $errorDetail, [
                'payment_method' => $request->input('payment_method'),
                'company_id' => $companyId,
                'trace_top' => collect(explode("\n", $e->getTraceAsString()))->take(5)->implode(' | '),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Payment failed [' . class_basename($errorClass) . ' @ ' . $errorWhere . ']: ' . $errorDetail,
            ], 500);
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
        $prefix = "POS-{$year}-";
        // withoutGlobalScope('hide_archived'): archived rows still occupy the
        // UNIQUE(company_id, invoice_number) index — the counter must see them.
        $all = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('invoice_mode', 'pra')
            ->where('invoice_number', 'like', "{$prefix}%")
            ->pluck('invoice_number');

        $maxNum = 0;
        foreach ($all as $inv) {
            if (preg_match('/POS-' . $year . '-(\d+)/', $inv, $m)) {
                $maxNum = max($maxNum, (int) $m[1]);
            }
        }
        return $prefix . str_pad($maxNum + 1, 5, '0', STR_PAD_LEFT);
    }

    private function generateLocalInvoiceNumber($companyId)
    {
        // T004 — vendor-requested short L-NNN provisional invoice format, IDENTICAL to
        // PosController::generateLocalInvoiceNumber so retail + restaurant share one
        // sequence per company (keep both in sync).
        // Owner rule (22 Jul 2026) — SMALLEST FREE NUMBER, not max+1: deleted numbers
        // are reused by NEW bills (gap-fill / daily restart after day-close delete);
        // archived rows keep their numbers (withoutGlobalScope('hide_archived'));
        // existing bills are never renumbered. Exclude legacy "LOCAL-YYYY-NNNNN" rows
        // so the counter is not corrupted. lockForUpdate + UNIQUE(company_id,
        // invoice_number) guard concurrent generators.
        $taken = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('invoice_number', 'like', 'L-%')
            ->where('invoice_number', 'not like', 'LOCAL-%')
            ->lockForUpdate()
            ->pluck('invoice_number');

        $used = [];
        foreach ($taken as $serial) {
            if (preg_match('/^L-(\d+)$/', $serial, $matches)) {
                $used[(int) $matches[1]] = true;
            }
        }

        $next = 1;
        while (isset($used[$next])) {
            $next++;
        }

        return 'L-' . str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    public function customerSearch(Request $request)
    {
        $companyId = app('currentCompanyId');
        $q = $request->get('q', '');

        if (strlen($q) < 2) {
            return response()->json(['customers' => []]);
        }

        // Pizza Master (Aug 2026): cashiers type numbers with dashes/spaces (03001-1234567)
        // and old rows may be STORED with separators too — match on digits-only both sides.
        // Phone-only grammar gate (same as client isPhoneLike): letters = name search, never phone.
        $digits = preg_match('/^[0-9+()\s\-]+$/', trim($q)) ? preg_replace('/\D+/', '', $q) : '';
        $customers = PosCustomer::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($query) use ($q, $digits) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($q) . '%'])
                    ->orWhereRaw('LOWER(phone) LIKE ?', ['%' . strtolower($q) . '%']);
                if (strlen($digits) >= 4) {
                    $query->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''),'-',''),' ',''),'(',''),')',''),'+','') LIKE ?", ['%' . $digits . '%']);
                }
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

        // Name is OPTIONAL (owner request, Jul 2026): shops always capture the phone
        // number, but asking every walk-in for a name is awkward. Blank name = the
        // phone number doubles as the display name (pos_customers.name is NOT NULL,
        // and receipts/ledgers expect a printable name).
        $request->validate([
            'name' => 'nullable|string|max:255',
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
            'name' => trim((string) $request->name) !== '' ? trim($request->name) : $request->phone,
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

    /**
     * Proof Bill (Pizza Master feedback, Jul 2026): a thermal PRE-BILL the
     * cashier can hand to the customer WITHOUT finalizing — no PosTransaction,
     * no serial, no PRA reporting; the restaurant order stays open on its table.
     */
    public function proofBill($orderId)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $order = RestaurantOrder::where('company_id', $companyId)
            ->whereNotIn('status', ['cancelled'])
            ->with(['items', 'table', 'creator'])
            ->findOrFail($orderId);

        // Task 778: customer-facing pre-bill — merge KOT-split rows into one
        // line per dish (display-only; DB rows keep their print stamps).
        $order->setRelation('items', $this->consolidateBillLines($order->items));

        return view('pos.restaurant.proof-bill', compact('order', 'company'));
    }

    /**
     * Task 778 — customer bill line consolidation; single truth lives in
     * PosBillLineConsolidator (shared with AgentController's proof job path).
     */
    private function consolidateBillLines($items)
    {
        return \App\Services\PosBillLineConsolidator::consolidate($items);
    }

    public function kitchenSettings()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        // Counter/Station routing (owner, Jul 2026): stations claim product
        // categories; unmatched items fall to the main Kitchen.
        $stations = \App\Models\PosStation::where('company_id', $companyId)
            ->orderBy('sort')->orderBy('id')->get();
        $categories = \App\Models\PosProduct::where('company_id', $companyId)
            ->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->orderBy('category')->pluck('category');
        $printers = collect($company->printerSettings()['available_printers'])->pluck('name')->filter()->values();

        // Task 767: opening this page counts as "notified" — the in-page
        // Task 761 warning takes over from here (it persists while centering
        // stays explicitly ON), so clear the one-time layout banner stamp.
        if ($company
            && \Illuminate\Support\Facades\Schema::hasColumn('companies', 'kot_center_notice_at')
            && $company->kot_center_notice_at !== null) {
            \Illuminate\Support\Facades\DB::table('companies')
                ->where('id', $company->id)
                ->update(['kot_center_notice_at' => null]);
        }

        return view('pos.restaurant.kitchen-settings', compact('company', 'stations', 'categories', 'printers'));
    }

    /**
     * Counter/Station CRUD (admin-only). Validation rule: a category may be
     * claimed by at most ONE active station per company — overlapping claims
     * would make item routing ambiguous.
     */
    private function validateStationRequest(Request $request, int $companyId, ?int $ignoreStationId = null): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:60',
            'categories' => 'nullable|array',
            'categories.*' => 'string|max:100',
            'printer_name' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        $isActive = $request->boolean('is_active');
        $categories = collect($validated['categories'] ?? [])
            ->map(fn ($c) => trim((string) $c))->filter()->unique()->values();

        // Duplicate counter names would merge sections on an unfiltered full
        // ticket (routing by id stays correct, but the header reads wrong).
        $name = trim($validated['name']);
        $dupe = \App\Models\PosStation::where('company_id', $companyId)
            ->when($ignoreStationId, fn ($q) => $q->where('id', '!=', $ignoreStationId))
            ->get()
            ->first(fn ($s) => mb_strtolower(trim($s->name)) === mb_strtolower($name));
        if ($dupe) {
            abort(redirect()->back()->withErrors([
                'name' => "A counter named \"{$name}\" already exists.",
            ])->withInput());
        }

        // Overlap check only matters when THIS station is active.
        if ($isActive && $categories->isNotEmpty()) {
            $others = \App\Models\PosStation::where('company_id', $companyId)
                ->where('is_active', true)
                ->when($ignoreStationId, fn ($q) => $q->where('id', '!=', $ignoreStationId))
                ->get();
            $claimed = \App\Models\PosStation::categoryMap($others); // catKey => station_id
            foreach ($categories as $cat) {
                $key = \App\Models\PosStation::catKey($cat);
                if (isset($claimed[$key])) {
                    $ownerName = optional($others->firstWhere('id', $claimed[$key]))->name ?? 'another counter';
                    abort(redirect()->back()->withErrors([
                        'categories' => "Category \"{$cat}\" is already assigned to counter \"{$ownerName}\". A category can belong to only one active counter.",
                    ])->withInput());
                }
            }
        }

        // Printer must be one the agent actually reported (or blank = use company
        // KOT printer). Unknown name = loud error, NOT a silent null — otherwise
        // the admin believes a dedicated printer is set when it isn't.
        $company = Company::find($companyId);
        $known = collect($company->printerSettings()['available_printers'])->pluck('name')->all();
        $printer = trim((string) ($validated['printer_name'] ?? ''));
        if ($printer !== '' && !in_array($printer, $known, true)) {
            abort(redirect()->back()->withErrors([
                'printer_name' => "Printer \"{$printer}\" is not known to the Desktop Agent. Refresh the agent's printer list or leave blank to use the company KOT printer.",
            ])->withInput());
        }
        $printer = $printer !== '' ? $printer : null;

        return [
            'name' => $name,
            'categories' => $categories->all(),
            'printer_name' => $printer,
            'is_active' => $isActive,
        ];
    }

    public function storeStation(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            abort(403, 'Only POS administrators can manage counters.');
        }
        $companyId = app('currentCompanyId');

        $data = $this->validateStationRequest($request, $companyId);
        $data['company_id'] = $companyId;
        $data['sort'] = ((int) \App\Models\PosStation::where('company_id', $companyId)->max('sort')) + 1;
        \App\Models\PosStation::create($data);

        return back()->with('success', 'Counter added.');
    }

    public function updateStation(Request $request, $id)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            abort(403, 'Only POS administrators can manage counters.');
        }
        $companyId = app('currentCompanyId');
        $station = \App\Models\PosStation::where('company_id', $companyId)->findOrFail($id);

        $station->update($this->validateStationRequest($request, $companyId, (int) $station->id));

        return back()->with('success', 'Counter updated.');
    }

    public function deleteStation(Request $request, $id)
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            abort(403, 'Only POS administrators can manage counters.');
        }
        $companyId = app('currentCompanyId');
        \App\Models\PosStation::where('company_id', $companyId)->findOrFail($id)->delete();

        return back()->with('success', 'Counter removed. Its categories now print with the main Kitchen.');
    }

    public function updateKitchenSettings(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $updates = [
            'kds_enabled' => (bool) $request->kds_enabled,
            'kitchen_printer_enabled' => (bool) $request->kitchen_printer_enabled,
            'print_on_hold' => (bool) $request->print_on_hold,
            'print_on_pay' => (bool) $request->print_on_pay,
        ];
        // Dine-In Auto KOT (owner, Jul 2026): table select auto-holds + fires KOT.
        // hasColumn guard = prod self-heal parity (code may land before migrate --force).
        if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'dine_in_auto_kot')) {
            $updates['dine_in_auto_kot'] = (bool) $request->dine_in_auto_kot;
        }
        // KOT Full Mode (ZFC feedback, Jul 2026) — hasColumn guard = prod self-heal parity.
        if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_kot_full_mode')) {
            $updates['pos_kot_full_mode'] = (bool) $request->pos_kot_full_mode;
        }
        // Delivery: payment pehle, KOT baad (customer voice note, 1 Aug 2026) —
        // provisional delivery bills par KOT promote/finalize tak ruki rahti hai.
        if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'delivery_kot_after_payment')) {
            $updates['delivery_kot_after_payment'] = (bool) $request->delivery_kot_after_payment;
        }
        // KOT Print Style (customer feedback 27 Jul 2026): paper-saving toggles +
        // print position. hasColumn guards = prod self-heal parity.
        foreach (['kot_compact', 'kot_show_customer', 'kot_show_orderby', 'kot_show_barcode', 'kot_show_footer', 'kot_show_kitchen_notes', 'kot_align_center'] as $kotFlag) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('companies', $kotFlag)) {
                $updates[$kotFlag] = (bool) $request->input($kotFlag);
            }
        }
        // Receipt fallback guard (Task 718): receipt_80mm/58mm + proof-bill fall
        // back to kot_align_center while receipt_align_center is NULL. This save
        // writes kot_align_center EXPLICITLY (center is pre-selected for the new
        // Pizza Master default), so freeze the receipt position at its CURRENT
        // effective value first — a kitchen-settings save must only move the KOT,
        // never the customer receipt.
        if (array_key_exists('kot_align_center', $updates)
            && \Illuminate\Support\Facades\Schema::hasColumn('companies', 'receipt_align_center')
            && $company->receipt_align_center === null) {
            $updates['receipt_align_center'] = (bool) ($company->kot_align_center ?? false);
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'kot_left_margin_mm')) {
            $updates['kot_left_margin_mm'] = max(0, min(30, (int) $request->input('kot_left_margin_mm', 0)));
        }
        // Task 767: a kitchen-settings save is an explicit verify — clear the
        // one-time "centering still ON" layout banner stamp (belt & braces:
        // the GET already clears it when the page is opened).
        if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'kot_center_notice_at')) {
            $updates['kot_center_notice_at'] = null;
        }
        $company->update($updates);

        return back()->with('success', 'Kitchen settings updated successfully.');
    }

    public function kitchenTicket(Request $request, $orderId)
    {
        $companyId = app('currentCompanyId');
        $order = RestaurantOrder::where('company_id', $companyId)
            ->with(['items', 'table', 'creator'])
            ->findOrFail($orderId);

        $company = Company::find($companyId);

        // P7 (F6) delta-KOT: ?delta=1 renders ONLY not-yet-printed items (appended
        // rows) so the kitchen never re-fires dishes already on the pass.
        // KOT Full Mode (ZFC feedback, Jul 2026): company opts into COMPLETE
        // tickets — a delta request with new rows prints the WHOLE order (new
        // rows flagged NEW); a delta request with NOTHING new keeps the empty
        // render so the blank-print guard still skips duplicates.
        $delta = $request->query('delta') == '1';
        $fullMode = (bool) ($company->pos_kot_full_mode ?? false);
        $unprinted = $order->items->whereNull('kot_printed_at');
        // Task 753 MISSED-DELTA RECOVERY: ?batch=last — akhri chhapi KOT batch
        // ki rows (+ jo rows ab tak unprinted hain) ko delta-style CLEAN ticket
        // ke tor par dobara render karo. Physical print fail par add-on slip
        // wapas mil jati hai; neeche wala stamp whereNull-guarded hai is liye
        // chhapi hui rows kabhi re-number/re-consume nahi hotin. Batch data hi
        // na ho (legacy rows) to poora ticket render hota hai.
        $batchLast = $request->query('batch') === 'last';
        if ($batchLast) {
            $delta = true;
            $maxBatch = (int) $order->items->max('kot_batch_no');
            $ticketItems = $order->items
                ->filter(fn ($i) => $i->kot_printed_at === null || ($maxBatch > 0 && (int) $i->kot_batch_no === $maxBatch))
                ->values();
            if ($ticketItems->isEmpty()) {
                $ticketItems = $order->items;
            }
        } elseif ($delta && $fullMode && $unprinted->isNotEmpty()) {
            $ticketItems = $order->items;
        } else {
            $ticketItems = $delta ? $unprinted->values() : $order->items;
        }
        $newItemIds = ($fullMode && !$batchLast) ? $unprinted->pluck('id') : collect();

        // Counter/Station routing (owner, Jul 2026): optional ?station= filter
        // (numeric id, 0 = main Kitchen). Zero configured stations => legacy
        // raw-category grouping, no filter. Grouping resolved HERE (bulk lookup)
        // — the blade never queries per item.
        $prep = \App\Models\PosStation::prepareTicket($companyId, $ticketItems, $request->query('station'));
        $ticketItems = $prep['items'];
        $grouped = $prep['grouped'];
        $stationLabel = $prep['stationLabel'];

        // Item #6 (owner, Jul 2026): every print BATCH carries a stable "KOT #N".
        // Reprints/plain views show the batch already stamped on the rendered rows.
        $kotBatchNo = $ticketItems->max('kot_batch_no');

        // Stamp on ACTUAL print renders only (auto_print=1) — plain views never
        // consume the delta. Full prints stamp everything they render too, so a
        // later delta covers only genuinely new rows. kot_batch_no is stamped in
        // the SAME update as kot_printed_at (deterministic, reprint-stable —
        // render-time kot_print_count+1 would renumber on races/reprints).
        if ($request->query('auto_print') == '1' && $ticketItems->whereNull('kot_printed_at')->isNotEmpty()) {
            $nextBatch = ((int) \App\Models\RestaurantOrderItem::where('order_id', $order->id)->max('kot_batch_no')) + 1;
            \App\Models\RestaurantOrderItem::whereIn('id', $ticketItems->pluck('id'))
                ->whereNull('kot_printed_at')
                ->update(['kot_printed_at' => now(), 'kot_batch_no' => $nextBatch]);
            $kotBatchNo = $nextBatch;
        }

        return view('pos.restaurant.kitchen-ticket', compact('order', 'company', 'ticketItems', 'delta', 'kotBatchNo', 'grouped', 'stationLabel', 'newItemIds'));
    }

    /**
     * Task 794 — GET /pos/restaurant/orders/{id}/void-ticket
     * VOID / CANCEL slip: items removed (or qty decreased) from a running order
     * AFTER their KOT already fired — the kitchen must STOP making them.
     * void_items = base64(json([{item_type, item_id, item_name, notes, qty}]))
     * built by holdOrder from the leftover carry-pool chunks. Iframe fallback
     * path; the silent Desktop Agent path renders the same view via its
     * kot_void print job (AgentController::printJobContent).
     */
    public function voidTicket(Request $request, $orderId)
    {
        $companyId = app('currentCompanyId');
        $order = RestaurantOrder::where('company_id', $companyId)
            ->with(['table', 'creator'])
            ->findOrFail($orderId);
        $company = Company::find($companyId);

        $voidItems = collect();
        if ($request->query('void_items')) {
            $decoded = json_decode(base64_decode((string) $request->query('void_items'), true) ?: '', true);
            if (is_array($decoded)) {
                $voidItems = collect($decoded);
            }
        }

        return view('pos.restaurant.kitchen-ticket', [
            'order'        => $order,
            'company'      => $company,
            'void'         => true,
            'voidItems'    => $voidItems,
            'ticketItems'  => collect(),
            'grouped'      => collect(),
            'stationLabel' => null,
            'delta'        => false,
            'kotBatchNo'   => null,
            'newItemIds'   => collect(),
        ]);
    }

    /**
     * Delivery KOT from a TRANSACTION (ZFC, 28 Jul 2026): delivery bills saved
     * straight from the cart (provisional rider-khata bills + manual-cart
     * finals) have NO restaurant order, so the order-based KOT never fires and
     * the kitchen gets nothing. Render the SAME kitchen ticket from the
     * transaction's items via an unsaved RestaurantOrder shim. Shared by the
     * HTTP route and the Agent print-job content endpoint.
     */
    public static function renderTransactionKot(int $companyId, int $transactionId): ?string
    {
        $transaction = \App\Models\PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->with(['items', 'creator'])
            ->find($transactionId);
        if (!$transaction || $transaction->items->isEmpty()) {
            return null;
        }
        $company = Company::find($companyId);

        $order = new RestaurantOrder([
            'order_number' => $transaction->invoice_number ?: ('TXN-' . $transaction->id),
            'order_type' => $transaction->order_type ?: 'delivery',
            'customer_name' => $transaction->customer_name ?? null,
        ]);
        $order->created_at = $transaction->created_at;
        $order->kot_print_count = 1; // never a "REPRINT" banner
        $order->priority = false;
        // Aug 2026 (review catch): txn bills DO carry the cashier's bill note
        // (PosTransaction.notes) — map it onto the KOT so promoted delivery
        // tickets print the (multi-line, numbered) notes like order KOTs do.
        $order->kitchen_notes = $transaction->notes ?: null;
        $order->setRelation('table', null);
        $order->setRelation('creator', $transaction->creator);

        $ticketItems = $transaction->items->map(function ($it) {
            $row = new \App\Models\RestaurantOrderItem([
                'item_type' => $it->item_type,
                'item_id' => $it->item_id,
                'item_name' => $it->item_name,
                'quantity' => $it->quantity,
                'unit_price' => $it->unit_price,
                'special_notes' => $it->special_notes,
            ]);
            $row->id = $it->id; // stable keys for the blade loops
            return $row;
        })->values();

        // Same station grouping resolver as order KOTs (no station filter —
        // one full ticket; multi-station splitting stays an order-KOT feature).
        $prep = \App\Models\PosStation::prepareTicket($companyId, $ticketItems, null);

        // Task 777 (ZFC, 16 Aug 2026): when this bill's STREAM number style is
        // 'token' (same predicate as receipts — local = isLocalBill/exempt),
        // the shim KOT prints the bill token big with the serial as small ref,
        // so the kitchen slip matches the receipt's calling number. Both the
        // browser route and the Agent print path go through this render.
        $shimBillToken = null;
        try {
            if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'bill_token') && $transaction->bill_token) {
                $shimIsLocal = $transaction->isLocalBill() || $transaction->isExemptStream();
                $shimStyle = $shimIsLocal ? ($company->local_number_style ?? 'serial') : ($company->pra_number_style ?? 'serial');
                if ($shimStyle === 'token') {
                    $shimBillToken = (int) $transaction->bill_token;
                }
            }
        } catch (\Throwable $e) {
            $shimBillToken = null;
        }

        // "Payment First, Then KOT" v2 (Aug 2026): stamp the txn the first time its
        // kitchen ticket is rendered — the F10 "Send KOT" button and the promote-time
        // auto-KOT both check this so the kitchen never gets the same ticket twice.
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'kot_sent_at') && !$transaction->kot_sent_at) {
            PosTransaction::where('id', $transaction->id)->update(['kot_sent_at' => now()]);
        }

        return view('pos.restaurant.kitchen-ticket', [
            'order' => $order,
            'company' => $company,
            'ticketItems' => $prep['items'],
            'grouped' => $prep['grouped'],
            'stationLabel' => $prep['stationLabel'],
            'delta' => false,
            'kotBatchNo' => null,
            'newItemIds' => collect(),
            'shimBillToken' => $shimBillToken,
        ])->render();
    }

    /** GET /pos/transactions/{id}/kitchen-ticket — iframe/popup KOT for order-less bills. */
    public function transactionKitchenTicket(Request $request, $transactionId)
    {
        $html = self::renderTransactionKot((int) app('currentCompanyId'), (int) $transactionId);
        abort_if($html === null, 404);

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Phase 5 — Re-send an existing held order to the kitchen.
     * Increments kot_print_count and refreshes kot_sent_at so the next
     * printed ticket is marked "UPDATED". Does NOT touch items, totals,
     * payment, PRA, or invoice numbers — pure KOT bookkeeping.
     */
    public function resendKitchen($orderId)
    {
        $companyId = app('currentCompanyId');

        // Atomic single-statement update — protects against lost increments
        // when two cashiers click "Re-send" on the same order at once.
        $affected = RestaurantOrder::where('company_id', $companyId)
            ->where('id', $orderId)
            ->whereIn('status', ['held', 'preparing', 'ready'])
            ->update([
                'kot_sent_at' => now(),
                'kot_print_count' => DB::raw('COALESCE(kot_print_count, 0) + 1'),
                'updated_at' => now(),
            ]);

        if (!$affected) {
            return response()->json(['success' => false, 'message' => 'Order not found or already completed'], 404);
        }

        $fresh = RestaurantOrder::where('company_id', $companyId)->find($orderId);

        return response()->json([
            'success' => true,
            'order_id' => $fresh->id,
            'kot_print_count' => (int) $fresh->kot_print_count,
            'message' => 'Re-sent to kitchen',
        ]);
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
                ->whereRaw('LOWER(phone) LIKE ?', ['%' . strtolower($phone) . '%'])
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
        if ($user && !in_array($user->pos_role, ['pos_admin', 'pos_manager'], true) && $user->role !== 'company_admin') {
            return redirect('/pos/invoice/create');
        }

        // Business-day window (ZFC, 2 Aug 2026: dashboard sab Rs 0 dikhata tha
        // aadhi raat ke baad): "aaj" = current BUSINESS day — cutoff (default
        // 06:00) se ab tak. restaurant_orders ka business_date column nahi,
        // is liye time-window se wahi grouping banti hai jo day-close ki hai.
        $bizDate = \App\Services\PosBusinessDay::current($companyId);
        $cutoff = \App\Services\PosBusinessDay::cutoffFor($companyId);
        $today = \Carbon\Carbon::parse($bizDate . ' ' . $cutoff, config('app.timezone'));
        $yesterday = $today->copy()->subDay();

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
            ->where('status', '!=', 'cancelled')
            ->count();

        $heldCount = RestaurantOrder::where('company_id', $companyId)
            ->where('created_at', '>=', $today)
            ->whereIn('status', ['held', 'preparing', 'ready'])
            ->count();

        $completedCount = RestaurantOrder::where('company_id', $companyId)
            ->where('created_at', '>=', $today)
            ->where('status', 'completed')
            ->count();

        // Task 109 (ZFC, 2 Aug 2026): Pending Bills tile — dashboard se hi pata
        // chal jaye ke kitne bills abhi FINAL nahi hue.
        // (a) Provisional delivery bills: pos_transactions triple-filter
        //     (completed + invoice_mode='local' + pra_status='local'), current
        //     BUSINESS day; hide_archived global scope already excludes archived.
        $pendingProvisional = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('invoice_mode', 'local')
            ->where('pra_status', 'local')
            ->where('business_date', $bizDate)
            ->count();
        // (b) Open dine-in/held orders: still un-settled regardless of when they
        //     were opened — a table left open from before the cutoff is still pending.
        //     Task 507 diagnosis (11 Aug 2026, ZFC Pizza Point "ghost 1"): a lone
        //     count here with zero provisionals = a genuinely ABANDONED held order
        //     (no paid txn attached), NOT a pay-path bug. It's actionable: the
        //     Tables page lists every open order (even table-less / deleted-table)
        //     and day-close hard-blocks while any remain — so the count always
        //     leads somewhere staff can settle or cancel.
        $openOrdersCount = RestaurantOrder::where('company_id', $companyId)
            ->whereIn('status', ['held', 'preparing', 'ready'])
            ->count();
        // Task 644 (ZFC video, 13 Aug 2026): EXCEPTION to the note above —
        // TABLELESS waiter ("counter") orders are DELIBERATELY invisible on the
        // Tables page (owner rule 5 Aug 2026: counter orders surface ONLY in the
        // sale screen's incoming-orders bell panel). Lumping them under "Open
        // orders → Tables page" made the tile a DEAD END ("bill khulta hi
        // nahi"). Split them out: the tile shows this slice as its own chip that
        // opens the sale screen with the bell panel auto-opened
        // (?open_incoming=1); claiming stays on the atomic claim path.
        $counterOrdersCount = RestaurantOrder::where('company_id', $companyId)
            ->whereIn('status', ['held', 'preparing', 'ready'])
            ->where('source', 'waiter')
            ->whereNull('table_id')
            ->count();

        // Task 113 (ZFC, 2 Aug 2026): Cancelled Orders tile — current BUSINESS
        // day's cancelled count, same cutoff window the dashboard's "today"
        // metrics use ($today = bizDate + cutoff). cancelled_at NULL fallback
        // (column nayi hai) → updated_at, matching the report's query.
        // Task 506: genuineCancelled scope — recall-supersede ghosts excluded,
        // same predicate as the Cancelled Orders report.
        $cancelledTodayCount = RestaurantOrder::where('company_id', $companyId)
            ->genuineCancelled()
            ->whereRaw('COALESCE(cancelled_at, updated_at) >= ?', [$today])
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

        // Inventory master switch — when company has inventory_enabled = false,
        // the dashboard low-stock badge/section must stay empty.
        $inventoryOn = (bool)($company->inventory_enabled ?? false);
        $lowStockItems = $inventoryOn
            ? Ingredient::where('company_id', $companyId)
                ->where('is_active', true)
                ->whereColumn('current_stock', '<=', 'min_stock_level')
                ->orderBy('current_stock')
                ->limit(10)
                ->get()
            : collect();

        $recentOrders = RestaurantOrder::where('company_id', $companyId)
            ->with(['items', 'table'])
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', $today)
            ->orderBy('created_at', 'desc')
            ->limit(15)
            ->get();

        // 7-din chart: har bar ek BUSINESS day hai (cutoff→cutoff window), taa ke
        // 00:00–cutoff ki sales pichle din ke bar mein aayen — day-close report
        // ke figures se match karein. $bizDate/$cutoff upar set ho chuke hain.
        $salesChartLabels = [];
        $salesChartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = \Carbon\Carbon::parse($bizDate, config('app.timezone'))->subDays($i);
            $windowStart = \Carbon\Carbon::parse($day->toDateString() . ' ' . $cutoff, config('app.timezone'));
            $windowEnd = $windowStart->copy()->addDay();
            $salesChartLabels[] = $day->format('D');
            $salesChartData[] = (float) RestaurantOrder::where('company_id', $companyId)
                ->where('status', 'completed')
                ->where('created_at', '>=', $windowStart)
                ->where('created_at', '<', $windowEnd)
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
            ->select(DB::raw(\App\Helpers\DbCompat::extractHour('created_at') . ' as hr'), DB::raw('SUM(total_amount) as total'))
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

        $allowedStyles = ['default', 'toast', 'lightspeed', 'clover', 'oscar', 'shopify', 'saaf'];
        $dashboardStyle = in_array($company->pos_dashboard_style, $allowedStyles) ? $company->pos_dashboard_style : 'default';
        $isRestaurant = true;
        $isAdmin = in_array($user->pos_role ?? $user->role ?? '', ['pos_admin', 'pos_manager', 'company_admin']);
        $praStatus = (bool) $user?->praReportingEnabled($company);
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';

        // ─── Kitchen Efficiency (owner, Jul 2026) ─────────────────────────
        // Derived from KDS timestamps (kot_sent → started → ready → cleared),
        // stamped by RestaurantKdsController::kitchenStatus. All averages are
        // null (view shows "—") until the kitchen actually uses the KDS.
        // Carbon 3: diffInMinutes is SIGNED — always pass absolute=true.
        $kdsRows = RestaurantOrder::where('company_id', $companyId)
            ->whereNotNull('kot_sent_at')
            ->where('created_at', '>=', $today->copy()->subDays(7))
            ->get(['id', 'created_at', 'kot_sent_at', 'kitchen_started_at', 'kitchen_ready_at', 'kitchen_cleared_at']);

        $prepPairs = $kdsRows->filter(fn ($o) => $o->kot_sent_at && $o->kitchen_ready_at);
        $todayPrep = $prepPairs->filter(fn ($o) => $o->created_at >= $today)
            ->map(fn ($o) => $o->kot_sent_at->diffInMinutes($o->kitchen_ready_at, true));
        $weekPrep = $prepPairs->map(fn ($o) => $o->kot_sent_at->diffInMinutes($o->kitchen_ready_at, true));
        $todayClear = $kdsRows->filter(fn ($o) => $o->kitchen_ready_at && $o->kitchen_cleared_at && $o->created_at >= $today)
            ->map(fn ($o) => $o->kitchen_ready_at->diffInMinutes($o->kitchen_cleared_at, true));

        $kitchenStats = [
            'avg_prep_today'  => $todayPrep->count() ? round($todayPrep->avg(), 1) : null,
            'avg_prep_week'   => $weekPrep->count() ? round($weekPrep->avg(), 1) : null,
            'avg_clear_today' => $todayClear->count() ? round($todayClear->avg(), 1) : null,
            'cleared_today'   => $kdsRows->filter(fn ($o) => $o->kitchen_cleared_at && $o->kitchen_cleared_at >= $today)->count(),
            'in_kitchen_now'  => RestaurantOrder::where('company_id', $companyId)
                ->whereIn('status', ['held', 'preparing', 'ready'])
                ->whereNotNull('kot_sent_at')
                ->whereNull('kitchen_cleared_at')
                ->count(),
        ];

        // Task 666 parity (14 Aug 2026, Malik Chicken Broast): restaurant-mode
        // dashboard is a SEPARATE page — the "Aaj ka Khaata" card was only on
        // the retail dashboard, so restaurant owners never saw it. Same shared
        // builder + partial as PosController::dashboard.
        $todayKhata = \App\Services\PosTodayKhata::build($companyId, $bizDate, $user);

        return view('pos.restaurant.dashboard', compact(
            'company', 'todaySales', 'yesterdaySales', 'todayOrders',
            'heldCount', 'completedCount', 'totalTables', 'occupiedTables',
            'topProducts', 'lowStockItems', 'recentOrders',
            'salesChartLabels', 'salesChartData', 'orderTypeCounts',
            'peakHour', 'todayTax', 'todayDiscount',
            'todayCost', 'todayProfit', 'kitchenStats',
            'dashboardStyle', 'isRestaurant', 'isAdmin', 'praStatus', 'isCashier',
            'pendingProvisional', 'openOrdersCount', 'cancelledTodayCount',
            'counterOrdersCount', 'todayKhata'
        ));
    }

    public function receipt($transactionId)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $transaction = PosTransaction::where('company_id', $companyId)
            ->with(['items', 'payments', 'creator', 'terminal'])
            ->findOrFail($transactionId);

        // Restaurant POS sale-time + reprint now use the SAME beautiful
        // typewriter-style thermal receipt as the universal Reports flow
        // (pos.receipts.receipt_80mm / receipt_58mm). Single source of truth
        // — a single template change updates both flows. The order/KOT
        // linkage stays separate (KOT prints via pos.restaurant.kitchen-ticket).
        $printerSize = $company->receipt_printer_size ?? '80mm';
        $receiptView = $printerSize === '58mm' ? 'pos.receipts.receipt_58mm' : 'pos.receipts.receipt_80mm';

        return view($receiptView, compact('transaction', 'company'));
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
            // 'transaction' eager-loaded: live par lazy-loading STRICT hai —
            // asal bill number (L-xx) + View Receipt ke liye (owner, 3 Aug 2026).
            ->with(['items', 'transaction:id,invoice_number'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($o) {
                return [
                    'id' => $o->id,
                    'order_number' => $o->order_number,
                    // Asal POS bill number + receipt link target (NULL jab order
                    // kabhi bill nahi bana — modal ORD- number par fallback karta hai).
                    'txn_id' => $o->pos_transaction_id ? (int) $o->pos_transaction_id : null,
                    'invoice_number' => $o->transaction?->invoice_number,
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
        if (!in_array($user->pos_role, ['pos_admin', 'pos_manager'], true) && $user->role !== 'company_admin') {
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

    // ── Cancelled Orders report (ZFC, 2 Aug 2026) ──────────────────────────
    // Cancel ab soft hai (status='cancelled'), is liye report ban sakti hai.
    // Filters: from/to date (default: aakhri 7 din). Admin/manager only.

    /** Shared query builder + gate for the report page/CSV/PDF. */
    private function cancelledOrdersQuery(Request $request, ?string &$from = null, ?string &$to = null)
    {
        $companyId = app('currentCompanyId');
        $from = $request->query('from') ?: now()->subDays(6)->toDateString();
        $to = $request->query('to') ?: now()->toDateString();
        // Guard: swapped/garbage dates → sane defaults
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = now()->subDays(6)->toDateString();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = now()->toDateString();
        if ($from > $to) { [$from, $to] = [$to, $from]; }

        return RestaurantOrder::where('company_id', $companyId)
            // Task 506: sirf asli (human) cancels — recall+re-hold ke
            // system-supersede ghosts is shared scope se bahar rehte hain.
            ->genuineCancelled()
            // cancelled_at NULL fallback (column abhi nayi hai) → updated_at
            ->whereRaw('DATE(COALESCE(cancelled_at, updated_at)) BETWEEN ? AND ?', [$from, $to])
            ->with(['items', 'table', 'creator', 'canceller'])
            ->orderByDesc(DB::raw('COALESCE(cancelled_at, updated_at)'));
    }

    private function cancelledOrdersGate()
    {
        $user = auth('pos')->user();
        if ($user && !in_array($user->pos_role, ['pos_admin', 'pos_manager'], true) && $user->role !== 'company_admin') {
            return redirect('/pos/invoice/create');
        }
        // Strict plan binding (owner, 9 Aug 2026): cancelled-orders & kitchen-waste
        // report is a Pro plan-card promise — restaurant-module gate (Pro+ or trial).
        $company = Company::find(app('currentCompanyId'));
        if (!\App\Services\PosFeatureService::restaurantAllowed($company)) {
            return redirect()->route('pos.billing')->with('error', __('pos.plan_locked_feature'));
        }
        return null;
    }

    public function cancelledOrders(Request $request)
    {
        if ($gate = $this->cancelledOrdersGate()) return $gate;
        $company = Company::find(app('currentCompanyId'));
        $orders = $this->cancelledOrdersQuery($request, $from, $to)->paginate(50)->withQueryString();
        $summary = [
            'count' => $orders->total(),
            'value' => (float) $this->cancelledOrdersQuery($request)->sum('total_amount'),
            // Waste = cancel ke waqt "ban gaya tha" tick hue items ki maliyat
            'waste' => Schema::hasColumn('restaurant_order_items', 'was_made')
                ? (float) RestaurantOrderItem::where('was_made', true)
                    ->whereIn('order_id', $this->cancelledOrdersQuery($request)->select('id'))
                    ->sum('subtotal')
                : 0.0,
        ];
        return view('pos.cancelled-orders', compact('company', 'orders', 'from', 'to', 'summary'));
    }

    public function cancelledOrdersCsv(Request $request)
    {
        if ($gate = $this->cancelledOrdersGate()) return $gate;
        $orders = $this->cancelledOrdersQuery($request, $from, $to)->limit(5000)->get();
        $filename = "cancelled-orders-{$from}-to-{$to}.csv";
        return response()->streamDownload(function () use ($orders) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel)
            fputcsv($out, ['Order #', 'Date/Time Cancelled', 'Table', 'Order Type', 'Items', 'Made Items', 'Amount (Rs)', 'KOT Sent', 'Punched By', 'Cancelled By']);
            foreach ($orders as $o) {
                $items = $o->items->map(fn ($i) => $i->quantity . 'x ' . $i->item_name)->implode('; ');
                $made = $o->items->where('was_made', true)->map(fn ($i) => $i->quantity . 'x ' . $i->item_name)->implode('; ');
                fputcsv($out, [
                    $o->order_number,
                    optional($o->cancelled_at ?? $o->updated_at)->format('Y-m-d H:i'),
                    $o->table?->table_number ? 'T-' . $o->table->table_number : '-',
                    $o->order_type,
                    $items,
                    $made,
                    (int) round($o->total_amount),
                    $o->kot_sent_at ? 'YES' : 'no',
                    $o->creator?->name ?? '-',
                    $o->canceller?->name ?? 'System',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function cancelledOrdersPdf(Request $request)
    {
        if ($gate = $this->cancelledOrdersGate()) return $gate;
        $company = Company::find(app('currentCompanyId'));
        $orders = $this->cancelledOrdersQuery($request, $from, $to)->limit(2000)->get();
        $summary = [
            'count' => $orders->count(),
            'value' => (float) $orders->sum('total_amount'),
            'waste' => (float) $orders->flatMap->items->where('was_made', true)->sum('subtotal'),
        ];
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pos.cancelled-orders-pdf', compact('company', 'orders', 'from', 'to', 'summary'));
        return $pdf->download("cancelled-orders-{$from}-to-{$to}.pdf");
    }
}
