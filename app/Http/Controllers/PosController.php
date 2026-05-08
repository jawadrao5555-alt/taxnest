<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Models\PosDayCloseReport;
use App\Models\PosProduct;
use App\Models\PosCustomer;
use App\Models\PosService;
use App\Models\PosTerminal;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use App\Models\PosPayment;
use App\Models\PosTaxRule;
use App\Models\PraLog;
use App\Models\RestaurantTable;
use App\Models\RestaurantOrder;
use App\Models\ProductRecipe;
use App\Models\Ingredient;
use App\Services\PraIntegrationService;
use App\Services\PosFeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    public function updateTheme(Request $request)
    {
        $theme = $request->input('theme', 'purple');
        $allowed = ['purple', 'blue', 'emerald', 'orange', 'midnight', 'rose'];
        if (!in_array($theme, $allowed)) {
            return response()->json(['success' => false, 'message' => 'Invalid theme'], 422);
        }
        $companyId = app('currentCompanyId');
        Company::where('id', $companyId)->update(['pos_theme' => $theme]);
        return response()->json(['success' => true, 'theme' => $theme]);
    }

    public function updateDashboardStyle(Request $request)
    {
        $user = auth('pos')->user();
        $isAdmin = in_array($user->pos_role ?? $user->role ?? '', ['pos_admin', 'company_admin']);
        if (!$isAdmin) {
            return response()->json(['success' => false, 'message' => 'Only admin can change dashboard style.'], 403);
        }
        $style = $request->json('style') ?? $request->input('style', 'default');
        $allowed = ['default', 'toast', 'lightspeed', 'clover', 'oscar', 'shopify'];
        if (!in_array($style, $allowed)) {
            return response()->json(['success' => false, 'message' => 'Invalid style'], 422);
        }
        $companyId = app('currentCompanyId');
        Company::where('id', $companyId)->update(['pos_dashboard_style' => $style]);
        return response()->json(['success' => true, 'style' => $style]);
    }

    public function dashboard(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $today = now()->startOfDay();

        $todayStats = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $today)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue, COALESCE(AVG(total_amount),0) as avg_ticket')
            ->first();

        $monthStats = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue')
            ->first();

        // ── PROFIT + BI ENGINE (v18) ─────────────────────────────────────────
        // Period filter: ?period=today | week | month  (default: today)
        $period = in_array($request->query('period'), ['today', 'week', 'month'], true)
            ? $request->query('period') : 'today';
        $periodStart = match ($period) {
            'week'  => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            default => now()->startOfDay(),
        };

        // Cost / profit aggregation: JOIN items → products to read cost_price.
        // unit_price kept from item (snapshot at time of sale).
        // Profit = (item.subtotal - cost_price * quantity) summed across all completed orders in period.
        $profitRow = \DB::table('pos_transactions as t')
            ->join('pos_transaction_items as i', 'i.transaction_id', '=', 't.id')
            ->leftJoin('pos_products as p', function ($j) {
                $j->on('p.id', '=', 'i.item_id')->where('i.item_type', '=', 'product');
            })
            ->where('t.company_id', $companyId)
            ->where('t.status', 'completed')
            ->where('t.created_at', '>=', $periodStart)
            ->selectRaw('
                COALESCE(SUM(i.subtotal), 0) as gross_revenue,
                COALESCE(SUM(COALESCE(p.cost_price, 0) * i.quantity), 0) as total_cost
            ')->first();

        $periodOrders = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $periodStart)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue')
            ->first();

        $totalCost     = (float) ($profitRow->total_cost ?? 0);
        $totalRevenue  = (float) ($periodOrders->revenue ?? 0);
        $totalProfit   = max(0, $totalRevenue - $totalCost); // floor at 0 for display
        $marginPct     = $totalRevenue > 0 ? round(($totalRevenue - $totalCost) / $totalRevenue * 100, 1) : 0;

        $profitStats = [
            'period'   => $period,
            'orders'   => (int) ($periodOrders->count ?? 0),
            'revenue'  => $totalRevenue,
            'cost'     => $totalCost,
            'profit'   => $totalProfit,
            'margin'   => $marginPct,
        ];

        // Top products by quantity sold (period)
        $topSold = \DB::table('pos_transactions as t')
            ->join('pos_transaction_items as i', 'i.transaction_id', '=', 't.id')
            ->where('t.company_id', $companyId)
            ->where('t.status', 'completed')
            ->where('t.created_at', '>=', $periodStart)
            ->where('i.item_type', 'product')
            ->selectRaw('i.item_id, MAX(i.item_name) as name, SUM(i.quantity) as qty, SUM(i.subtotal) as revenue')
            ->groupBy('i.item_id')
            ->orderByDesc('qty')
            ->limit(5)
            ->get();

        // Top products by profit (period) — needs cost_price
        $topProfit = \DB::table('pos_transactions as t')
            ->join('pos_transaction_items as i', 'i.transaction_id', '=', 't.id')
            ->join('pos_products as p', 'p.id', '=', 'i.item_id')
            ->where('t.company_id', $companyId)
            ->where('t.status', 'completed')
            ->where('t.created_at', '>=', $periodStart)
            ->where('i.item_type', 'product')
            ->selectRaw('
                i.item_id, MAX(i.item_name) as name,
                SUM(i.subtotal) as revenue,
                SUM(COALESCE(p.cost_price,0) * i.quantity) as cost,
                SUM(i.subtotal - COALESCE(p.cost_price,0) * i.quantity) as profit
            ')
            ->groupBy('i.item_id')
            ->orderByDesc('profit')
            ->limit(5)
            ->get();

        // Low margin alert: products with cost_price > 0 AND (price - cost)/price < 15%
        $lowMargin = PosProduct::where('company_id', $companyId)
            ->where('is_active', true)
            ->where('cost_price', '>', 0)
            ->whereRaw('price > 0')
            ->whereRaw('((price - cost_price) / price) < 0.15')
            ->orderByRaw('((price - cost_price) / NULLIF(price,0)) asc')
            ->limit(5)
            ->get(['id', 'name', 'price', 'cost_price']);

        // Coverage: how many active products have cost_price set (helps user understand accuracy)
        $costCoverage = [
            'with_cost' => PosProduct::where('company_id', $companyId)->where('is_active', true)->where('cost_price', '>', 0)->count(),
            'total'     => PosProduct::where('company_id', $companyId)->where('is_active', true)->count(),
        ];
        // ─────────────────────────────────────────────────────────────────────

        $recentTransactions = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })
            ->with('creator')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        $paymentBreakdown = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $today)
            ->selectRaw("payment_method, COUNT(*) as count, COALESCE(SUM(total_amount),0) as total")
            ->groupBy('payment_method')
            ->get();

        $praStatus = $company->pra_reporting_enabled;

        $drafts = PosTransaction::where('company_id', $companyId)
            ->where('status', 'draft')
            ->with('items')
            ->orderBy('updated_at', 'desc')
            ->take(20)
            ->get();

        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';

        $allowedStyles = ['default', 'toast', 'lightspeed', 'clover', 'oscar', 'shopify'];
        $dashboardStyle = in_array($company->pos_dashboard_style, $allowedStyles) ? $company->pos_dashboard_style : 'default';
        $isRestaurant = false;
        $isAdmin = !$isCashier;

        return view('pos.dashboard', compact(
            'company', 'todayStats', 'monthStats', 'recentTransactions', 'paymentBreakdown', 'praStatus', 'drafts', 'isCashier',
            'dashboardStyle', 'isRestaurant', 'isAdmin',
            'profitStats', 'topSold', 'topProfit', 'lowMargin', 'costCoverage'
        ));
    }

    public function createInvoice(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        \Log::info('POS MODE ACTIVE', [
            'company_id' => $company?->id,
            'mode' => 'UNIVERSAL',
        ]);

        return $this->universalCreateInvoice($request);

        // Legacy paths preserved (unreachable, kept for rollback reference)
        if ($company && $company->restaurant_mode) {
            return app(RestaurantPosController::class)->pos($request);
        }

        $products = PosProduct::where('company_id', $companyId)->where('is_active', true)->get();
        $services = PosService::where('company_id', $companyId)->where('is_active', true)->get();
        $posCustomers = PosCustomer::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();
        $taxRules = PosTaxRule::where('is_active', true)->get()->keyBy('payment_method');
        $terminals = PosTerminal::where('company_id', $companyId)->where('is_active', true)->get();

        $draftInvoice = null;
        if ($request->has('draft_id')) {
            $draftInvoice = PosTransaction::where('company_id', $companyId)
                ->where('id', $request->draft_id)
                ->where('status', 'draft')
                ->with('items')
                ->first();

            if ($draftInvoice) {
                $currentTerminalId = $request->input('terminal_id');
                if ($draftInvoice->isLocked() && $currentTerminalId && $draftInvoice->locked_by_terminal_id != $currentTerminalId) {
                    $lockedTerminal = PosTerminal::find($draftInvoice->locked_by_terminal_id);
                    $terminalName = $lockedTerminal ? $lockedTerminal->terminal_name : 'Unknown';
                    return redirect()->route('pos.invoice.create')
                        ->with('error', "This invoice is currently being edited on another terminal ({$terminalName}).");
                }

                if ($currentTerminalId) {
                    $draftInvoice->acquireLock((int) $currentTerminalId);
                }
            }
        }

        $pendingDrafts = PosTransaction::where('company_id', $companyId)
            ->where('status', 'draft')
            ->where('created_by', auth('pos')->id())
            ->orderBy('updated_at', 'desc')
            ->take(5)
            ->get();

        return view('pos.create-invoice', compact('company', 'products', 'services', 'taxRules', 'terminals', 'draftInvoice', 'pendingDrafts', 'posCustomers'));
    }

    public function universalCreateInvoice(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $features = PosFeatureService::forCompany($company);

        $products = PosProduct::where('company_id', $companyId)->where('is_active', true)->get();
        $services = PosService::where('company_id', $companyId)->where('is_active', true)->get();
        $categories = $products->pluck('category')->filter()->unique()->sort()->values();
        $productIds = $products->pluck('id')->toArray();

        $recipeLookup = [];
        $stockStatus = [];
        $ingredientCosts = [];
        $lowStockAlerts = collect();
        // Inventory master switch — when company has inventory_enabled = false, suppress
        // ALL stock indicators (dots, OUT pills, low-stock popup) so the POS stays clean.
        // Recipes/ingredient costing still computed for cost-of-sale reporting (admin only),
        // but no UI badges are emitted.
        $inventoryOn = (bool)($company->inventory_enabled ?? false);
        if ($features->recipes && class_exists(ProductRecipe::class)) {
            $recipeLookup = ProductRecipe::where('company_id', $companyId)
                ->whereIn('product_id', $productIds)->pluck('product_id')->unique()->toArray();
            $recipes = ProductRecipe::where('company_id', $companyId)->with('ingredient')->get()->groupBy('product_id');
            foreach ($recipes as $productId => $productRecipes) {
                $status = 'available';
                $cost = 0;
                foreach ($productRecipes as $recipe) {
                    $ing = $recipe->ingredient;
                    if (!$ing || !$ing->is_active) continue;
                    if ((float)$ing->current_stock < (float)$recipe->quantity_needed) { $status = 'out'; }
                    elseif (method_exists($ing, 'isLowStock') && $ing->isLowStock()) { $status = 'low'; }
                    $cost += (float)$recipe->quantity_needed * (float)($ing->cost_per_unit ?? 0);
                }
                $stockStatus[$productId] = $status;
                $ingredientCosts[$productId] = round($cost, 2);
            }
            if ($inventoryOn) {
                $lowStockAlerts = Ingredient::where('company_id', $companyId)
                    ->where('is_active', true)
                    ->whereColumn('current_stock', '<=', 'min_stock_level')
                    ->select('name', 'current_stock', 'min_stock_level', 'unit')->get();
            }
        }
        // Inventory OFF → wipe stock map so frontend renders no dots/OUT pills.
        if (!$inventoryOn) {
            $stockStatus = [];
        }

        $tables = collect();
        $selectedTable = null;
        if ($features->tables && class_exists(RestaurantTable::class)) {
            $tables = RestaurantTable::where('company_id', $companyId)
                ->where('is_active', true)->orderBy('sort_order')->get();
            $tableId = $request->get('table_id');
            $selectedTable = $tableId ? RestaurantTable::where('company_id', $companyId)->find($tableId) : null;
        }

        $heldOrders = collect();
        if ($features->kot && class_exists(RestaurantOrder::class)) {
            $heldOrders = RestaurantOrder::where('company_id', $companyId)
                ->whereIn('status', ['held', 'preparing', 'ready'])
                ->with(['table', 'items'])->orderBy('created_at', 'desc')->get();
        }

        $customers = PosCustomer::where('company_id', $companyId)->orderBy('name')->get();
        $taxRate = PosTaxRule::getRateForMethod('cash');
        $taxRules = PosTaxRule::where('is_active', true)->get()->keyBy('payment_method');
        // Inventory master switch governs ALL stock behavior. When OFF:
        //   - block_out_of_stock is FORCED false (cannot block adds based on stock)
        //   - lowStockAlerts is empty (popup cannot open)
        //   - stockStatus is empty (no badges)
        $inventoryEnabled = $inventoryOn;
        $blockOutOfStock = $inventoryEnabled ? (bool)($company->block_out_of_stock ?? false) : false;

        $user = Auth::guard('pos')->user();
        $posRole = $user->pos_role ?? 'pos_cashier';
        $discountLimit = $posRole === 'pos_admin'
            ? (float)($company->manager_discount_limit ?? 50)
            : (float)($company->cashier_discount_limit ?? 50);
        $hasManagerPin = !empty($company->manager_override_pin);

        return response(view('pos.universal', compact(
            'company', 'features', 'products', 'services', 'categories',
            'recipeLookup', 'tables', 'selectedTable', 'heldOrders',
            'customers', 'taxRate', 'taxRules', 'stockStatus', 'blockOutOfStock',
            'posRole', 'discountLimit', 'hasManagerPin', 'ingredientCosts',
            'lowStockAlerts', 'inventoryEnabled'
        )))
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->header('Pragma', 'no-cache')
        ->header('Expires', '0');
    }

    public function featureSettings(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->isPosCashier()) {
            abort(403, 'Only POS administrators can customize POS features.');
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $features = PosFeatureService::forCompany($company);
        $categories = PosFeatureService::categories();
        $allFlags = PosFeatureService::ALL_FLAGS;
        return view('pos.feature-settings', compact('company', 'features', 'categories', 'allFlags'));
    }

    public function updateFeatureSettings(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->isPosCashier()) {
            abort(403, 'Only POS administrators can customize POS features.');
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company) { abort(404); }

        $allowedCategories = PosFeatureService::categories();
        $data = $request->validate([
            'business_category' => 'nullable|string|in:' . implode(',', $allowedCategories),
            'use_universal_pos' => 'nullable|boolean',
            'pos_ui_density'    => 'nullable|in:simple,standard,premium',
            'feature_flags'     => 'nullable|array',
        ]);

        $flags = [];
        foreach (PosFeatureService::ALL_FLAGS as $flag) {
            $flags[$flag] = (bool) $request->input("feature_flags.$flag", false);
        }

        $company->update([
            'business_category' => $data['business_category'] ?? $company->business_category,
            'feature_flags'     => $flags,
            'use_universal_pos' => (bool) ($data['use_universal_pos'] ?? false),
            'pos_ui_density'    => $data['pos_ui_density'] ?? $company->pos_ui_density ?? 'standard',
        ]);

        return redirect()->route('pos.features')->with('success', 'POS features updated.');
    }

    public function resetFeaturesToCategory(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->isPosCashier()) {
            abort(403, 'Only POS administrators can reset POS features.');
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company) { abort(404); }
        $allowedCategories = PosFeatureService::categories();
        $data = $request->validate([
            'business_category' => 'nullable|string|in:' . implode(',', $allowedCategories),
        ]);
        $category = $data['business_category'] ?? ($company->business_category ?: 'retail');
        $defaults = PosFeatureService::defaultsForCategory($category);
        $company->update([
            'business_category' => $category,
            'feature_flags' => $defaults,
        ]);
        return redirect()->route('pos.features')->with('success', 'Features reset to ' . $category . ' defaults.');
    }

    public function storeInvoice(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $request->validate([
            'items' => 'required|array|min:1|max:200',
            'items.*.name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1|max:9999',
            // Sane upper bound (Rs. 10M per unit) to limit damage from any
            // tampered / malformed payload. POS line-item prices in Pakistan
            // never legitimately exceed this.
            'items.*.unit_price' => 'required|numeric|min:0|max:10000000',
            'payment_method' => 'required|in:cash,card,debit_card,credit_card,qr_payment',
            'discount_type' => 'required|in:percentage,amount',
            'discount_value' => 'nullable|numeric|min:0',
            'cash_received' => 'nullable|numeric|min:0',
        ]);

        // Normalize 'card' alias → 'debit_card' (front-end Universal POS sends 'card';
        // PosTaxRule + PRA mapping use 'debit_card'). Without this, downstream tax
        // lookup/PRA payment-mode mapping miss the rule and fall back to defaults.
        if ($request->input('payment_method') === 'card') {
            $request->merge(['payment_method' => 'debit_card']);
        }

        // Cashier discount guardrail — mirrors RestaurantPosController::holdOrder.
        // Without this, a `pos_cashier` user could submit a 100 % percentage
        // discount through the manual-cart bypass / legacy form post and bypass
        // the per-company limit. Admin/manager roles are unaffected.
        $posUser = auth('pos')->user();
        if ($posUser && ($posUser->pos_role ?? null) === 'pos_cashier') {
            $cashierMaxPct = (float) ($company->cashier_discount_limit ?? 50);
            if ($request->discount_type === 'percentage' && (float) $request->discount_value > $cashierMaxPct) {
                $request->merge(['discount_value' => $cashierMaxPct]);
            }
        }

        $companyItems = $this->resolveItemExemptions($request->items, $companyId);
        $subtotal = array_sum(array_column($companyItems, 'lineTotal'));
        $taxableSubtotal = array_sum(array_map(fn($i) => $i['isExempt'] ? 0 : $i['lineTotal'], $companyItems));
        $exemptSubtotal = $subtotal - $taxableSubtotal;

        $discountValue = (float) ($request->discount_value ?? 0);
        $discountType = $request->discount_type;
        if ($discountType === 'percentage') {
            $discountAmount = round($subtotal * $discountValue / 100, 2);
        } else {
            $discountAmount = min($discountValue, $subtotal);
        }

        $afterDiscount = $subtotal - $discountAmount;
        $taxableAfterDiscount = $subtotal > 0 ? round($taxableSubtotal / $subtotal * $afterDiscount, 2) : 0;
        $exemptAfterDiscount = round($afterDiscount - $taxableAfterDiscount, 2);

        $taxRate = PosTaxRule::getRateForMethod($request->payment_method);
        $taxAmount = round($taxableAfterDiscount * $taxRate / 100, 2);
        // Round to nearest integer rupee — matches frontend Math.round(roundedTotal).
        // Pakistan POS convention: bills are always whole rupees, no paisa.
        $totalAmount = (float) round($afterDiscount + $taxAmount);

        if ($request->terminal_id) {
            $terminal = PosTerminal::where('company_id', $companyId)->where('id', $request->terminal_id)->where('is_active', true)->first();
            if (!$terminal) {
                return back()->withInput()->with('error', 'Invalid or inactive terminal selected.');
            }
        }

        // PROVISIONAL BILL FLOW — when cashier explicitly saves as provisional, the bill is
        // created with pra_status='local' regardless of company.pra_reporting_enabled, and
        // PRA submission is skipped. Bill remains editable/deletable until promoted to final
        // via retryPra (the "Submit to PRA — Make Final" button on transaction-show).
        $saveAsProvisional = (bool) $request->input('save_as_provisional', false);
        $praEnabled = (bool) $company->pra_reporting_enabled && !$saveAsProvisional;
        $invoiceMode = $praEnabled ? 'pra' : 'local';
        $initialPraStatus = $praEnabled ? 'pending' : 'local';

        DB::beginTransaction();
        try {
            $draftId = $request->input('draft_id');
            $transaction = null;

            if ($draftId) {
                $transaction = PosTransaction::where('company_id', $companyId)
                    ->where('id', $draftId)
                    ->where('status', 'draft')
                    ->lockForUpdate()
                    ->first();
            }

            if ($transaction) {
                $invoiceNumber = $transaction->invoice_number;
                $submissionHash = hash('sha256', $companyId . '|' . $invoiceNumber . '|' . $totalAmount . '|' . now()->timestamp);

                $transaction->update([
                    'terminal_id' => $request->terminal_id,
                    'customer_name' => $request->customer_name,
                    'customer_phone' => $request->customer_phone,
                    'subtotal' => $subtotal,
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'discount_amount' => $discountAmount,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'exempt_amount' => $exemptAfterDiscount,
                    'total_amount' => $totalAmount,
                    'payment_method' => $request->payment_method,
                    'status' => 'completed',
                    'pra_status' => $initialPraStatus,
                    'submission_hash' => $submissionHash,
                    'locked_by_terminal_id' => null,
                    'lock_time' => null,
                ]);

                $transaction->items()->delete();
            } else {
                $invoiceNumber = $invoiceMode === 'local'
                    ? $this->generateLocalInvoiceNumber($companyId)
                    : $this->generateInvoiceNumber($companyId);
                $submissionHash = hash('sha256', $companyId . '|' . $invoiceNumber . '|' . $totalAmount . '|' . now()->timestamp);

                $transaction = PosTransaction::create([
                    'company_id' => $companyId,
                    'branch_id' => app()->bound('currentBranchId') ? app('currentBranchId') : null,
                    'terminal_id' => $request->terminal_id,
                    'invoice_number' => $invoiceNumber,
                    'invoice_mode' => $invoiceMode,
                    'customer_name' => $request->customer_name,
                    'customer_phone' => $request->customer_phone,
                    'subtotal' => $subtotal,
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'discount_amount' => $discountAmount,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'exempt_amount' => $exemptAfterDiscount,
                    'total_amount' => $totalAmount,
                    'payment_method' => $request->payment_method,
                    'cash_received' => $request->payment_method === 'cash' ? ($request->cash_received ?: $totalAmount) : null,
                    'change_due' => $request->payment_method === 'cash' && $request->cash_received ? max(0, round($request->cash_received - $totalAmount, 2)) : null,
                    'status' => 'completed',
                    'pra_status' => $initialPraStatus,
                    'submission_hash' => $submissionHash,
                    'created_by' => auth('pos')->id(),
                ]);
            }

            foreach ($companyItems as $ri) {
                $itemTaxRate = $ri['isExempt'] ? 0 : $taxRate;
                $itemDiscountShare = $subtotal > 0 ? round($discountAmount * ($ri['lineTotal'] / $subtotal), 2) : 0;
                $itemTaxableAmount = $ri['lineTotal'] - $itemDiscountShare;
                $itemTaxAmount = round($itemTaxableAmount * $itemTaxRate / 100, 2);

                PosTransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'item_type' => $ri['type'],
                    'item_id' => $ri['item_id'],
                    'item_name' => $ri['name'],
                    'special_notes' => $ri['notes'] ?? null,
                    'quantity' => $ri['quantity'],
                    'unit_price' => $ri['price'],
                    'subtotal' => $ri['lineTotal'],
                    'is_tax_exempt' => $ri['isExempt'],
                    'tax_rate' => $itemTaxRate,
                    'tax_amount' => $itemTaxAmount,
                ]);
            }

            PosPayment::create([
                'transaction_id' => $transaction->id,
                'payment_method' => $request->payment_method,
                'amount' => $totalAmount,
                'reference_number' => $request->reference_number,
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $errMsg = 'Failed to create invoice: ' . $e->getMessage();
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $errMsg], 500);
            }
            return back()->withInput()->with('error', $errMsg);
        }

        $inventoryResult = PosInventoryController::deductStockForInvoice(
            $companyId,
            $request->items,
            $transaction->id,
            $invoiceNumber,
            auth('pos')->id()
        );

        $praMessage = '';
        if ($praEnabled) {
            // ENTERPRISE SAFE MODE — Phase 1: when desktop agent is enrolled, skip server-side direct submission.
            // The agent (running on the company's local Pakistani PC) will pick this up via /api/agent/pending-invoices.
            if ($company->agent_enabled) {
                $transaction->update(['pra_status' => 'pending']);
                $praMessage = ' | 🟡 Awaiting Sync: Desktop agent will submit to PRA from your local PC.';
            } else {
                try {
                    $praService = new PraIntegrationService($company);
                    $praResult = $praService->sendInvoice($transaction);
                    $transaction->refresh();

                    if ($praResult['success']) {
                        $praMessage = ' | PRA Fiscal Invoice Number: ' . ($transaction->pra_invoice_number ?? 'N/A');
                    } else {
                        $transaction->update(['pra_status' => 'offline']);
                        $praMessage = ' | Offline Mode: Invoice saved locally and will sync automatically.';
                    }
                } catch (\Exception $e) {
                    $transaction->update(['pra_status' => 'offline']);
                    $praMessage = ' | Offline Mode: Invoice saved locally and will sync automatically.';
                }
            }
        } else {
            $praMessage = ' | Local invoice (PRA reporting is off).';
        }

        $successMessage = 'Invoice Created Successfully! POS Invoice Number: ' . $invoiceNumber . $praMessage;

        // JSON callers (Universal POS manual-cart bypass) expect a structured
        // response so the receipt modal can render in-place. Legacy form-POST
        // callers (pos/create-invoice.blade.php) continue to receive the
        // traditional redirect-with-flash response.
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'transaction_id' => $transaction->id,
                'invoice_number' => $invoiceNumber,
                'total_amount' => $totalAmount,
                'pra_invoice_number' => $transaction->pra_invoice_number ?? null,
                'pra_status' => $transaction->pra_status ?? null,
                'message' => $successMessage,
            ]);
        }

        return redirect()->route('pos.transaction.show', $transaction->id)
            ->with('success', $successMessage);
    }

    public function editTransaction($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $transaction = PosTransaction::where('company_id', $companyId)
            ->with('items')
            ->findOrFail($id);

        if ($transaction->pra_invoice_number) {
            return redirect()->route('pos.transaction.show', $id)
                ->with('error', 'Cannot edit — this invoice has been submitted to PRA. PRA Fiscal #: ' . $transaction->pra_invoice_number);
        }

        $products = PosProduct::where('company_id', $companyId)->where('is_active', true)->get();
        $services = PosService::where('company_id', $companyId)->where('is_active', true)->get();
        $posCustomers = PosCustomer::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();
        $taxRules = PosTaxRule::where('is_active', true)->get()->keyBy('payment_method');
        $terminals = PosTerminal::where('company_id', $companyId)->where('is_active', true)->get();

        $transactionItems = $transaction->items->map(fn($item) => [
            'type' => $item->item_type ?? 'product',
            'item_id' => $item->item_id ?? '',
            'name' => $item->item_name ?? '',
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
        ])->values();

        return view('pos.edit-transaction', compact('company', 'transaction', 'transactionItems', 'products', 'services', 'taxRules', 'terminals', 'posCustomers'));
    }

    public function updateTransaction(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $transaction = PosTransaction::where('company_id', $companyId)->findOrFail($id);

        if ($transaction->pra_invoice_number) {
            return redirect()->route('pos.transaction.show', $id)
                ->with('error', 'Cannot edit — this invoice has been submitted to PRA.');
        }

        $request->validate([
            'items' => 'required|array|min:1|max:200',
            'items.*.name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1|max:9999',
            'items.*.unit_price' => 'required|numeric|min:0|max:10000000',
            'payment_method' => 'required|in:cash,card,debit_card,credit_card,qr_payment',
            'discount_type' => 'required|in:percentage,amount',
            'discount_value' => 'nullable|numeric|min:0',
            'cash_received' => 'nullable|numeric|min:0',
        ]);

        // Normalize 'card' alias → 'debit_card' (see storeInvoice() for rationale).
        if ($request->input('payment_method') === 'card') {
            $request->merge(['payment_method' => 'debit_card']);
        }

        // Cashier discount guardrail (mirrors RestaurantPosController::holdOrder
        // and storeInvoice). Stops a `pos_cashier` from bypassing the per-company
        // percentage limit through the edit/update path.
        $posUser = auth('pos')->user();
        if ($posUser && ($posUser->pos_role ?? null) === 'pos_cashier') {
            $cashierMaxPct = (float) ($company->cashier_discount_limit ?? 50);
            if ($request->discount_type === 'percentage' && (float) $request->discount_value > $cashierMaxPct) {
                $request->merge(['discount_value' => $cashierMaxPct]);
            }
        }

        $companyItems = $this->resolveItemExemptions($request->items, $companyId);
        $subtotal = array_sum(array_column($companyItems, 'lineTotal'));
        $taxableSubtotal = array_sum(array_map(fn($i) => $i['isExempt'] ? 0 : $i['lineTotal'], $companyItems));

        $discountValue = (float) ($request->discount_value ?? 0);
        $discountType = $request->discount_type;
        if ($discountType === 'percentage') {
            $discountAmount = round($subtotal * $discountValue / 100, 2);
        } else {
            $discountAmount = min($discountValue, $subtotal);
        }

        $afterDiscount = $subtotal - $discountAmount;
        $taxableAfterDiscount = $subtotal > 0 ? round($taxableSubtotal / $subtotal * $afterDiscount, 2) : 0;
        $exemptAfterDiscount = round($afterDiscount - $taxableAfterDiscount, 2);

        $taxRate = PosTaxRule::getRateForMethod($request->payment_method);
        $taxAmount = round($taxableAfterDiscount * $taxRate / 100, 2);
        // Round to nearest integer rupee — matches frontend Math.round(roundedTotal).
        // Pakistan POS convention: bills are always whole rupees, no paisa.
        $totalAmount = (float) round($afterDiscount + $taxAmount);

        DB::beginTransaction();
        try {
            $transaction->update([
                'terminal_id' => $request->terminal_id,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'subtotal' => $subtotal,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'discount_amount' => $discountAmount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'exempt_amount' => $exemptAfterDiscount,
                'total_amount' => $totalAmount,
                'payment_method' => $request->payment_method,
                'pra_status' => $company->pra_reporting_enabled ? 'pending' : ($transaction->pra_status ?? 'local'),
            ]);

            $transaction->items()->delete();

            foreach ($companyItems as $ri) {
                $itemTaxRate = $ri['isExempt'] ? 0 : $taxRate;
                $itemDiscountShare = $subtotal > 0 ? round($discountAmount * ($ri['lineTotal'] / $subtotal), 2) : 0;
                $itemTaxableAmount = $ri['lineTotal'] - $itemDiscountShare;
                $itemTaxAmount = round($itemTaxableAmount * $itemTaxRate / 100, 2);

                PosTransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'item_type' => $ri['type'],
                    'item_id' => $ri['item_id'],
                    'item_name' => $ri['name'],
                    'special_notes' => $ri['notes'] ?? null,
                    'quantity' => $ri['quantity'],
                    'unit_price' => $ri['price'],
                    'subtotal' => $ri['lineTotal'],
                    'is_tax_exempt' => $ri['isExempt'],
                    'tax_rate' => $itemTaxRate,
                    'tax_amount' => $itemTaxAmount,
                ]);
            }

            $transaction->payments()->delete();
            PosPayment::create([
                'transaction_id' => $transaction->id,
                'payment_method' => $request->payment_method,
                'amount' => $totalAmount,
                'reference_number' => $request->reference_number,
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Failed to update invoice: ' . $e->getMessage());
        }

        $praMessage = '';
        if ($company->pra_reporting_enabled) {
            // ENTERPRISE SAFE MODE — Phase 1: agent-enabled companies bypass server-side submission.
            if ($company->agent_enabled) {
                $transaction->update(['pra_status' => 'pending', 'pra_response_code' => null]);
                $praMessage = ' | 🟡 Awaiting Sync (desktop agent).';
            } else {
                try {
                    $transaction->update(['pra_status' => 'pending', 'pra_response_code' => null]);
                    $praService = new PraIntegrationService($company);
                    $praResult = $praService->sendInvoice($transaction);
                    $transaction->refresh();

                    if ($praResult['success']) {
                        $praMessage = ' | PRA Fiscal #: ' . ($transaction->pra_invoice_number ?? 'N/A');
                    } else {
                        $transaction->update(['pra_status' => 'offline']);
                        $praMessage = ' | Offline: Will sync automatically.';
                    }
                } catch (\Exception $e) {
                    $transaction->update(['pra_status' => 'offline']);
                    $praMessage = ' | Offline: Will sync automatically.';
                }
            }
        }

        return redirect()->route('pos.transaction.show', $transaction->id)
            ->with('success', 'Invoice updated successfully!' . $praMessage);
    }

    public function deleteTransaction($id)
    {
        $companyId = app('currentCompanyId');
        $transaction = PosTransaction::where('company_id', $companyId)->findOrFail($id);

        if ($transaction->pra_invoice_number) {
            return redirect()->route('pos.transaction.show', $id)
                ->with('error', 'Cannot delete — this invoice has been submitted to PRA. PRA Fiscal #: ' . $transaction->pra_invoice_number);
        }

        DB::beginTransaction();
        try {
            $transaction->items()->delete();
            $transaction->payments()->delete();
            $transaction->praLogs()->delete();
            $transaction->delete();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to delete invoice: ' . $e->getMessage());
        }

        return redirect()->route('pos.transactions')
            ->with('success', 'Invoice ' . $transaction->invoice_number . ' deleted successfully.');
    }

    public function retryPra($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $transaction = PosTransaction::where('company_id', $companyId)->findOrFail($id);

        if ($transaction->pra_invoice_number) {
            return back()->with('error', 'This invoice has already been submitted to PRA. PRA Fiscal Invoice #: ' . $transaction->pra_invoice_number);
        }

        if ($transaction->pra_status === 'submitted') {
            return back()->with('error', 'This invoice has already been successfully submitted to PRA.');
        }

        // Provisional ('local') bills CAN be promoted to final — this is the
        // "Submit to PRA — Make Final" path. They will be re-queued as 'pending'
        // and submitted just like any pending/failed/offline retry.
        if (!in_array($transaction->pra_status, ['pending', 'failed', 'offline', 'local'])) {
            return back()->with('error', 'This invoice cannot be submitted. Current status: ' . $transaction->pra_status);
        }

        if (!$company->pra_reporting_enabled) {
            return back()->with('error', 'PRA reporting is currently disabled. Enable it from PRA Settings first.');
        }

        // Promoting a provisional bill to final — flip mode + status before submission so
        // generators / templates treat it as a real PRA invoice from this point onward.
        if ($transaction->pra_status === 'local') {
            $transaction->update([
                'pra_status' => 'pending',
                'invoice_mode' => 'pra',
                'pra_response_code' => null,
            ]);
        }

        // ENTERPRISE SAFE MODE — Phase 1: agent-enabled companies just re-queue; the agent polls every 10s.
        if ($company->agent_enabled) {
            $transaction->update(['pra_status' => 'pending', 'pra_response_code' => null]);
            return back()->with('success', '🟡 Re-queued for desktop agent — will sync within seconds.');
        }

        try {
            $praService = new PraIntegrationService($company);
            $praResult = $praService->sendInvoice($transaction);
            $transaction->refresh();

            if ($praResult['success']) {
                return back()->with('success', 'PRA submission successful! PRA Fiscal Invoice Number: ' . ($transaction->pra_invoice_number ?? 'N/A'));
            } else {
                return back()->with('error', 'PRA submission failed: ' . ($praResult['message'] ?? 'Unknown error'));
            }
        } catch (\Exception $e) {
            $transaction->update(['pra_status' => 'offline']);
            return back()->with('error', 'PRA connection failed — invoice will sync automatically when connection is restored.');
        }
    }

    public function bulkRetryPra()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if (!$company->pra_reporting_enabled) {
            return back()->with('error', 'PRA reporting is currently disabled. Enable it from PRA Settings first.');
        }

        $pendingInvoices = PosTransaction::where('company_id', $companyId)
            ->whereIn('pra_status', ['failed', 'offline', 'pending'])
            ->whereNull('pra_invoice_number')
            ->orderBy('id', 'asc')
            ->get();

        if ($pendingInvoices->isEmpty()) {
            return back()->with('info', 'No failed or offline invoices to retry.');
        }

        // ENTERPRISE SAFE MODE — Phase 1: agent-enabled companies just bulk re-queue; the agent will pick them up.
        if ($company->agent_enabled) {
            $count = $pendingInvoices->count();
            DB::table('pos_transactions')
                ->where('company_id', $companyId)
                ->whereIn('id', $pendingInvoices->pluck('id'))
                ->update(['pra_status' => 'pending', 'pra_response_code' => null, 'updated_at' => now()]);
            return back()->with('success', "🟡 {$count} invoice(s) re-queued for desktop agent.");
        }

        $praService = new PraIntegrationService($company);
        $successCount = 0;
        $failCount = 0;
        $errors = [];

        foreach ($pendingInvoices as $transaction) {
            try {
                $result = $praService->sendInvoice($transaction);
                if ($result['success']) {
                    $successCount++;
                } else {
                    $failCount++;
                    $errors[] = $transaction->invoice_number . ': ' . ($result['message'] ?? 'Unknown error');
                }
            } catch (\Exception $e) {
                $failCount++;
                $transaction->update(['pra_status' => 'offline']);
                $errors[] = $transaction->invoice_number . ': Connection failed';
            }
        }

        $message = '';
        if ($successCount > 0) {
            $message = $successCount . ' invoice(s) successfully submitted to PRA.';
        }
        if ($failCount > 0) {
            $errorDetail = $failCount . ' invoice(s) failed.';
            if ($successCount > 0) {
                return back()->with('warning', $message . ' ' . $errorDetail);
            }
            return back()->with('error', $errorDetail . ' ' . implode(' | ', array_slice($errors, 0, 3)));
        }

        return back()->with('success', $message);
    }

    /**
     * ─────────────────────────────────────────────────────────────────────────
     * PROVISIONAL BILLS API — header shortcut endpoints (universal POS).
     * Returns lightweight JSON payload of all bills with pra_status='local'
     * for the current company. Used by the "Local" header button + F10 modal.
     * ─────────────────────────────────────────────────────────────────────────
     */
    public function apiProvisionalBills(Request $request)
    {
        $companyId = app('currentCompanyId');
        $bills = PosTransaction::where('company_id', $companyId)
            ->where('pra_status', 'local')
            ->orderBy('id', 'desc')
            ->limit(100)
            ->get(['id', 'invoice_number', 'customer_name', 'total_amount', 'created_at']);

        $data = $bills->map(function ($b) {
            return [
                'id'             => $b->id,
                'invoice_number' => $b->invoice_number,
                'customer_name'  => $b->customer_name,
                'total_amount'   => (float) $b->total_amount,
                'items_count'    => PosTransactionItem::where('transaction_id', $b->id)->count(),
                'created_human'  => $b->created_at?->diffForHumans(),
                'created_at'     => $b->created_at?->toDateTimeString(),
            ];
        });

        return response()->json([
            'success' => true,
            'count'   => $data->count(),
            'bills'   => $data,
        ]);
    }

    /**
     * Delete a provisional bill (only pra_status='local' allowed via this API).
     * Submitted/pending bills MUST go through the regular delete route which
     * enforces stricter checks.
     */
    public function apiDeleteProvisional(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $tx = PosTransaction::where('company_id', $companyId)->where('id', $id)->first();

        if (!$tx) {
            return response()->json(['success' => false, 'message' => 'Bill not found'], 404);
        }
        if ($tx->pra_status !== 'local') {
            return response()->json([
                'success' => false,
                'message' => 'Only provisional (local) bills can be deleted via this endpoint.',
            ], 422);
        }

        DB::transaction(function () use ($tx) {
            PosTransactionItem::where('transaction_id', $tx->id)->delete();
            PosPayment::where('transaction_id', $tx->id)->delete();
            $tx->delete();
        });

        return response()->json(['success' => true, 'message' => 'Provisional bill deleted', 'id' => $id]);
    }

    /**
     * Promote a provisional ('local') bill to a final PRA submission. Mirrors
     * the existing retryPra() flow but returns JSON for the inline modal.
     * Flips pra_status='local' → 'pending' + invoice_mode='pra' before submit.
     */
    public function apiPromoteProvisional(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $tx = PosTransaction::where('company_id', $companyId)->where('id', $id)->first();

        if (!$tx) {
            return response()->json(['success' => false, 'message' => 'Bill not found'], 404);
        }
        if ($tx->pra_status !== 'local') {
            return response()->json([
                'success' => false,
                'message' => 'Only provisional bills can be promoted. Current status: ' . $tx->pra_status,
            ], 422);
        }
        if (!$company || !$company->pra_reporting_enabled) {
            return response()->json([
                'success' => false,
                'message' => 'PRA reporting is currently disabled. Enable it from PRA Settings first.',
            ], 422);
        }

        // Flip provisional → pending + lock to PRA mode (same as retryPra L897-902).
        $tx->update([
            'pra_status'        => 'pending',
            'invoice_mode'      => 'pra',
            'pra_response_code' => null,
        ]);

        // Agent-enabled: just leave it queued — desktop agent picks it up within 10s.
        if ($company->agent_enabled) {
            return response()->json([
                'success' => true,
                'queued'  => true,
                'message' => '🟡 Re-queued for desktop agent — will sync within seconds.',
                'id'      => $id,
            ]);
        }

        try {
            $praService = new PraIntegrationService($company);
            $result = $praService->sendInvoice($tx);
            $tx->refresh();

            if (!empty($result['success'])) {
                return response()->json([
                    'success'    => true,
                    'submitted'  => true,
                    'message'    => 'PRA submission successful! PRA Fiscal Invoice Number: ' . ($tx->pra_invoice_number ?? 'N/A'),
                    'pra_number' => $tx->pra_invoice_number,
                    'id'         => $id,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'PRA submission failed: ' . ($result['message'] ?? 'Unknown error'),
                'id'      => $id,
            ], 502);
        } catch (\Exception $e) {
            $tx->update(['pra_status' => 'offline']);
            return response()->json([
                'success' => false,
                'offline' => true,
                'message' => 'PRA connection failed — will sync automatically when restored.',
                'id'      => $id,
            ], 503);
        }
    }

    /**
     * List failed/offline PRA bills for the F11 header shortcut modal.
     * Returns bills with pra_status IN ('failed','offline','pending') that
     * have NOT yet received a pra_invoice_number (i.e. need cashier attention).
     */
    public function apiFailedBills(Request $request)
    {
        $companyId = app('currentCompanyId');
        $bills = PosTransaction::where('company_id', $companyId)
            ->whereIn('pra_status', ['failed', 'offline', 'pending'])
            ->whereNull('pra_invoice_number')
            ->orderBy('id', 'desc')
            ->limit(100)
            ->get(['id', 'invoice_number', 'customer_name', 'total_amount', 'pra_status', 'pra_response_code', 'created_at']);

        $data = $bills->map(function ($b) {
            return [
                'id'             => $b->id,
                'invoice_number' => $b->invoice_number,
                'customer_name'  => $b->customer_name,
                'total_amount'   => (float) $b->total_amount,
                'pra_status'     => $b->pra_status,
                'error_code'     => $b->pra_response_code,
                'items_count'    => PosTransactionItem::where('transaction_id', $b->id)->count(),
                'created_human'  => $b->created_at?->diffForHumans(),
                'created_at'     => $b->created_at?->toDateTimeString(),
            ];
        });

        return response()->json([
            'success' => true,
            'count'   => $data->count(),
            'bills'   => $data,
        ]);
    }

    /**
     * Retry a failed/offline PRA submission via JSON (F11 modal action).
     * Mirrors retryPra() but returns JSON instead of back()->with().
     */
    public function apiRetryFailed(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if (!$company || !$company->pra_reporting_enabled) {
            return response()->json([
                'success' => false,
                'message' => 'PRA reporting is currently disabled. Enable it from PRA Settings first.',
            ], 422);
        }

        // ATOMIC CLAIM — race-safe. Conditional UPDATE returns affected-row
        // count; if 0, another concurrent request already claimed/submitted
        // this bill (double-click, two tabs, queue worker, etc.).
        $claimed = PosTransaction::where('company_id', $companyId)
            ->where('id', $id)
            ->whereNull('pra_invoice_number')
            ->whereIn('pra_status', ['pending', 'failed', 'offline'])
            ->update(['pra_status' => 'pending', 'pra_response_code' => null]);

        if ($claimed === 0) {
            // Either bill doesn't exist, was already submitted, or another
            // request claimed it. Re-fetch to give the cashier the right reason.
            $tx = PosTransaction::where('company_id', $companyId)->where('id', $id)->first();
            if (!$tx) {
                return response()->json(['success' => false, 'message' => 'Bill not found'], 404);
            }
            if ($tx->pra_invoice_number) {
                return response()->json([
                    'success' => false,
                    'message' => 'Already submitted. PRA #: ' . $tx->pra_invoice_number,
                ], 422);
            }
            return response()->json([
                'success' => false,
                'message' => 'Cannot retry — already in progress or status changed (' . $tx->pra_status . ')',
            ], 409);
        }

        $tx = PosTransaction::where('company_id', $companyId)->where('id', $id)->first();

        if ($company->agent_enabled) {
            return response()->json([
                'success' => true,
                'queued'  => true,
                'message' => '🟡 Re-queued for desktop agent — will sync within seconds.',
                'id'      => $id,
            ]);
        }

        try {
            $praService = new PraIntegrationService($company);
            $result = $praService->sendInvoice($tx);
            $tx->refresh();

            if (!empty($result['success'])) {
                return response()->json([
                    'success'    => true,
                    'submitted'  => true,
                    'message'    => 'PRA submission successful! PRA #: ' . ($tx->pra_invoice_number ?? 'N/A'),
                    'pra_number' => $tx->pra_invoice_number,
                    'id'         => $id,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'PRA submission failed: ' . ($result['message'] ?? 'Unknown error'),
                'id'      => $id,
            ], 502);
        } catch (\Exception $e) {
            $tx->update(['pra_status' => 'offline']);
            return response()->json([
                'success' => false,
                'offline' => true,
                'message' => 'PRA connection failed — will sync automatically when restored.',
                'id'      => $id,
            ], 503);
        }
    }

    private function verifyPinSession(): bool
    {
        return session('confidential_pin_verified', false) === true;
    }

    private function clearPinSession(): void
    {
        session()->forget(['confidential_pin_verified', 'confidential_pin_verified_at']);
    }

    private function requirePinForLocalTab(string $tab, Company $company)
    {
        if ($tab !== 'local') {
            $this->clearPinSession();
            return null;
        }

        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';

        if ($isCashier) {
            if (empty($company->confidential_pin)) {
                return redirect()->back()->with('error', 'Local data access is restricted. Admin must set a PIN first.');
            }
            if (!$this->verifyPinSession()) {
                return redirect()->back()->with('error', 'PIN verification required to access local data.');
            }
        } elseif (!empty($company->confidential_pin) && !$this->verifyPinSession()) {
            return redirect()->back()->with('error', 'PIN verification required to access local data.');
        }

        $this->clearPinSession();

        return null;
    }

    public function transactions(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $tab = $request->get('tab', 'pra');

        $pinRedirect = $this->requirePinForLocalTab($tab, $company);
        if ($pinRedirect) return $pinRedirect;

        $query = PosTransaction::where('company_id', $companyId)->where('status', 'completed')->with('creator');

        if ($tab === 'local') {
            $query->where('invoice_mode', 'local');
        } else {
            $query->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $like = \App\Helpers\DbCompat::like();
                $q->where('invoice_number', $like, "%{$search}%")
                    ->orWhere('customer_name', $like, "%{$search}%");
            });
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $transactions = $query->orderBy('created_at', 'desc')->paginate(20);

        $hasPinSet = !empty($company->confidential_pin);
        $localCount = PosTransaction::where('company_id', $companyId)->where('status', 'completed')->where('invoice_mode', 'local')->count();
        $user = auth('pos')->user();

        return view('pos.transactions', compact('transactions', 'tab', 'hasPinSet', 'localCount', 'user'));
    }

    public function transactionShow($id)
    {
        $companyId = app('currentCompanyId');
        $transaction = PosTransaction::where('company_id', $companyId)
            ->with(['items', 'payments', 'praLogs', 'creator', 'terminal'])
            ->findOrFail($id);

        return view('pos.transaction-show', compact('transaction'));
    }

    public function receipt($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $transaction = PosTransaction::where('company_id', $companyId)
            ->with(['items', 'payments', 'creator', 'terminal'])
            ->findOrFail($id);

        $printerSize = $company->receipt_printer_size ?? '80mm';
        $receiptView = $printerSize === '58mm' ? 'pos.receipts.receipt_58mm' : 'pos.receipts.receipt_80mm';

        return view($receiptView, compact('transaction', 'company'));
    }

    public function downloadInvoicePdf($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $transaction = PosTransaction::where('company_id', $companyId)
            ->with(['items', 'terminal', 'creator'])
            ->findOrFail($id);

        // Use the same thermal receipt template as the screen-print path so the
        // downloadable PDF matches what the cashier sees / prints. 80mm = 226.77pt
        // wide; height set to A4-ish so DomPDF auto-paginates if a receipt grows
        // unusually long. 58mm (164.41pt) for narrow thermal printers.
        $printerSize = $company->receipt_printer_size ?? '80mm';
        $receiptView = $printerSize === '58mm' ? 'pos.receipts.receipt_58mm' : 'pos.receipts.receipt_80mm';
        $paperWidthPt = $printerSize === '58mm' ? 164.41 : 226.77;

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($receiptView, compact('transaction', 'company'))
            ->setOption('isRemoteEnabled', true)
            ->setOption('isHtml5ParserEnabled', true);
        $pdf->setPaper([0, 0, $paperWidthPt, 1200], 'portrait');

        return $pdf->download("Invoice-{$transaction->invoice_number}.pdf");
    }

    public function generateShareLink($id)
    {
        $companyId = app('currentCompanyId');
        $transaction = PosTransaction::where('company_id', $companyId)->findOrFail($id);

        if (!$transaction->share_token) {
            $transaction->update([
                'share_token' => bin2hex(random_bytes(32)),
                'share_token_created_at' => now(),
            ]);
        }

        $shareUrl = url("/pos/invoice/share/{$transaction->share_token}");

        return response()->json(['url' => $shareUrl, 'token' => $transaction->share_token]);
    }

    public function publicInvoicePdf($token)
    {
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            abort(404);
        }

        $transaction = PosTransaction::where('share_token', $token)
            ->with(['items', 'terminal', 'creator'])
            ->firstOrFail();

        if ($transaction->share_token_created_at && $transaction->share_token_created_at < now()->subDays(30)) {
            abort(410, 'This share link has expired.');
        }

        $company = Company::find($transaction->company_id);
        if (!$company) {
            abort(404);
        }

        // Match the thermal receipt format used everywhere else (screen, print,
        // download). Share-link recipients (WhatsApp / Email) get the exact same
        // receipt their cashier handed over.
        $printerSize = $company->receipt_printer_size ?? '80mm';
        $receiptView = $printerSize === '58mm' ? 'pos.receipts.receipt_58mm' : 'pos.receipts.receipt_80mm';
        $paperWidthPt = $printerSize === '58mm' ? 164.41 : 226.77;

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($receiptView, compact('transaction', 'company'))
            ->setOption('isRemoteEnabled', true)
            ->setOption('isHtml5ParserEnabled', true);
        $pdf->setPaper([0, 0, $paperWidthPt, 1200], 'portrait');

        return $pdf->stream("Invoice-{$transaction->invoice_number}.pdf");
    }

    private function applyReportFilters($query, $tab, $cashierFilter = null)
    {
        if ($tab === 'local') {
            $query->where('invoice_mode', 'local');
        } else {
            $query->where(function ($sub) {
                $sub->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            });
        }

        if ($cashierFilter && $cashierFilter !== 'all') {
            $query->where('created_by', $cashierFilter);
        }

        return $query;
    }

    public function reports(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $tab = $request->get('tab', 'pra');

        $pinRedirect = $this->requirePinForLocalTab($tab, $company);
        if ($pinRedirect) return $pinRedirect;

        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';
        $cashierFilter = $request->get('cashier', 'all');

        if ($isCashier && $cashierFilter !== 'all' && $cashierFilter != $user->id) {
            $cashierFilter = $user->id;
        }

        $teamMembers = User::where('company_id', $companyId)
            ->whereNotNull('pos_role')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'pos_role']);

        $modeFilter = function ($q) use ($tab, $cashierFilter) {
            $this->applyReportFilters($q, $tab, $cashierFilter);
        };

        $dailySales = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(30))
            ->tap($modeFilter)
            ->selectRaw("DATE(created_at) as date, COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue")
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date', 'desc')
            ->get();

        $paymentSummary = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->startOfMonth())
            ->tap($modeFilter)
            ->selectRaw("payment_method, COUNT(*) as count, COALESCE(SUM(total_amount),0) as total, COALESCE(SUM(tax_amount),0) as tax")
            ->groupBy('payment_method')
            ->get();

        $topItems = PosTransactionItem::whereHas('transaction', function ($q) use ($companyId, $tab, $cashierFilter) {
            $q->where('company_id', $companyId)->where('status', 'completed')->where('created_at', '>=', now()->startOfMonth());
            $this->applyReportFilters($q, $tab, $cashierFilter);
        })
            ->selectRaw("item_name, SUM(quantity) as total_qty, SUM(subtotal) as total_revenue")
            ->groupBy('item_name')
            ->orderByDesc('total_revenue')
            ->take(10)
            ->get();

        $monthlyTrend = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subMonths(6)->startOfMonth())
            ->tap($modeFilter)
            ->selectRaw(\App\Helpers\DbCompat::dateFormat('created_at', 'YYYY-MM') . " as month, COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue")
            ->groupByRaw(\App\Helpers\DbCompat::dateFormat('created_at', 'YYYY-MM'))
            ->orderBy('month')
            ->get();

        $hasPinSet = !empty($company->confidential_pin);
        $localCount = PosTransaction::where('company_id', $companyId)->where('status', 'completed')->where('invoice_mode', 'local')->count();
        $selectedCashier = $cashierFilter;

        return view('pos.reports', compact('dailySales', 'paymentSummary', 'topItems', 'monthlyTrend', 'tab', 'hasPinSet', 'localCount', 'user', 'teamMembers', 'isCashier', 'selectedCashier'));
    }

    public function exportReportCsv(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $tab = $request->get('tab', 'pra');

        $pinRedirect = $this->requirePinForLocalTab($tab, $company);
        if ($pinRedirect) return $pinRedirect;

        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';
        $cashierFilter = $request->get('cashier', 'all');

        if ($isCashier && $cashierFilter !== 'all' && $cashierFilter != $user->id) {
            $cashierFilter = $user->id;
        }

        $transactions = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(30))
            ->when($tab === 'local', fn($q) => $q->where('invoice_mode', 'local'))
            ->when($tab !== 'local', fn($q) => $q->where(function ($s) { $s->where('invoice_mode', 'pra')->orWhereNull('invoice_mode'); }))
            ->when($cashierFilter && $cashierFilter !== 'all', fn($q) => $q->where('created_by', $cashierFilter))
            ->with('creator')
            ->orderBy('created_at', 'desc')
            ->get();

        $filterLabel = $cashierFilter === 'all' ? 'All Staff' : ($transactions->first()?->creator?->name ?? 'Staff #' . $cashierFilter);
        $filename = 'POS_Report_' . ($cashierFilter === 'all' ? 'AllStaff' : 'Staff') . '_' . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($transactions, $filterLabel, $tab, $company) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['POS Sales Report — ' . $company->name]);
            fputcsv($file, ['Mode: ' . strtoupper($tab), 'Filter: ' . $filterLabel, 'Generated: ' . now()->format('d M Y H:i')]);
            fputcsv($file, []);
            fputcsv($file, ['Date', 'Invoice #', 'Customer', 'Payment Method', 'Subtotal', 'Tax', 'Total', 'Staff']);

            foreach ($transactions as $t) {
                fputcsv($file, [
                    $t->created_at->format('d M Y H:i'),
                    $t->invoice_number,
                    $t->customer_name ?: 'Walk-in',
                    ucwords(str_replace('_', ' ', $t->payment_method)),
                    number_format($t->subtotal, 2),
                    number_format($t->tax_amount, 2),
                    number_format($t->total_amount, 2),
                    $t->creator?->name ?? '-',
                ]);
            }

            $totalRevenue = $transactions->sum('total_amount');
            $totalTax = $transactions->sum('tax_amount');
            fputcsv($file, []);
            fputcsv($file, ['', '', '', 'TOTALS', '', number_format($totalTax, 2), number_format($totalRevenue, 2), '']);
            fputcsv($file, ['Total Transactions: ' . $transactions->count()]);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function buildTaxReportQuery(Request $request, $tab = 'pra', $skipTaxRateFilter = false)
    {
        $companyId = app('currentCompanyId');
        $query = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->with('terminal');

        if ($tab === 'local') {
            $query->where('invoice_mode', 'local');
        } else {
            $query->where(function ($q) { $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode'); });
        }

        if (!$skipTaxRateFilter && $request->filled('tax_rate')) {
            if ($request->tax_rate === 'exempt') {
                $query->where('exempt_amount', '>', 0);
            } else {
                $rate = (float) $request->tax_rate;
                $query->where('tax_rate', $rate);
            }
        }

        if ($skipTaxRateFilter && $request->filled('tax_rate')) {
            if ($request->tax_rate === 'exempt') {
                $query->whereHas('items', function ($q) {
                    $q->where('is_tax_exempt', true);
                });
            } else {
                $rate = (float) $request->tax_rate;
                $query->whereHas('items', function ($q) use ($rate) {
                    $q->where('is_tax_exempt', false)->where('tax_rate', $rate);
                });
            }
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('customer')) {
            $query->where('customer_name', \App\Helpers\DbCompat::like(), '%' . $request->customer . '%');
        }

        $hasDateRange = $request->filled('date_from') || $request->filled('date_to');

        if ($hasDateRange) {
            if ($request->filled('date_from')) {
                $fromDate = \Carbon\Carbon::parse($request->date_from)->startOfDay();
                $query->where('created_at', '>=', $fromDate);
            }
            if ($request->filled('date_to')) {
                $toDate = \Carbon\Carbon::parse($request->date_to)->endOfDay();
                $query->where('created_at', '<=', $toDate);
            }
        } elseif ($request->filled('period')) {
            switch ($request->period) {
                case 'today':
                    $query->where('created_at', '>=', now()->startOfDay());
                    break;
                case 'yesterday':
                    $query->where('created_at', '>=', now()->subDay()->startOfDay())
                          ->where('created_at', '<=', now()->subDay()->endOfDay());
                    break;
                case 'weekly':
                    $query->where('created_at', '>=', now()->startOfWeek());
                    break;
                case 'monthly':
                    $query->where('created_at', '>=', now()->startOfMonth());
                    break;
                case 'last_month':
                    $query->where('created_at', '>=', now()->subMonth()->startOfMonth())
                          ->where('created_at', '<=', now()->subMonth()->endOfMonth());
                    break;
            }
        }

        $query->orderBy('created_at', 'desc');
        return $query;
    }

    private function getReportDateLabel(Request $request): string
    {
        $hasDateRange = $request->filled('date_from') || $request->filled('date_to');

        if ($hasDateRange) {
            if ($request->filled('date_from') && $request->filled('date_to')) {
                $from = \Carbon\Carbon::parse($request->date_from)->format('d M Y');
                $to = \Carbon\Carbon::parse($request->date_to)->format('d M Y');
                return $from . ' to ' . $to;
            }
            if ($request->filled('date_from')) {
                return \Carbon\Carbon::parse($request->date_from)->format('d M Y') . ' to Present';
            }
            if ($request->filled('date_to')) {
                return 'Up to ' . \Carbon\Carbon::parse($request->date_to)->format('d M Y');
            }
        }

        if ($request->filled('period')) {
            return match ($request->period) {
                'today' => 'Today (' . now()->format('d M Y') . ')',
                'yesterday' => 'Yesterday (' . now()->subDay()->format('d M Y') . ')',
                'weekly' => 'This Week (' . now()->startOfWeek()->format('d M') . ' - ' . now()->endOfWeek()->format('d M Y') . ')',
                'monthly' => 'This Month (' . now()->format('M Y') . ')',
                'last_month' => 'Last Month (' . now()->subMonth()->format('M Y') . ')',
                default => 'All Time',
            };
        }
        return 'All Time';
    }

    private function buildItemLevelSummary($transactionIds, $taxRateFilter)
    {
        $itemQuery = \App\Models\PosTransactionItem::whereIn('transaction_id', $transactionIds);

        if ($taxRateFilter === 'exempt') {
            $itemQuery->where('is_tax_exempt', true);
        } elseif ($taxRateFilter !== null && $taxRateFilter !== '') {
            $rate = (float) $taxRateFilter;
            $itemQuery->where('is_tax_exempt', false)->where('tax_rate', $rate);
        }

        return $itemQuery->selectRaw('
            COUNT(DISTINCT transaction_id) as total_invoices,
            COALESCE(SUM(subtotal), 0) as total_sales,
            COALESCE(SUM(tax_amount), 0) as total_tax,
            COALESCE(SUM(CASE WHEN is_tax_exempt = true THEN subtotal ELSE 0 END), 0) as total_exempt,
            COALESCE(SUM(CASE WHEN is_tax_exempt = false THEN subtotal ELSE 0 END), 0) as total_taxable
        ')->first();
    }

    private function getItemLevelValuesForTransactions($transactions, $taxRateFilter)
    {
        $transactionIds = $transactions->pluck('id')->toArray();
        if (empty($transactionIds)) return [];

        $itemQuery = \App\Models\PosTransactionItem::whereIn('transaction_id', $transactionIds);

        if ($taxRateFilter === 'exempt') {
            $itemQuery->where('is_tax_exempt', true);
        } elseif ($taxRateFilter !== null && $taxRateFilter !== '') {
            $rate = (float) $taxRateFilter;
            $itemQuery->where('is_tax_exempt', false)->where('tax_rate', $rate);
        } else {
            return [];
        }

        return $itemQuery->selectRaw('
            transaction_id,
            COALESCE(SUM(subtotal), 0) as item_subtotal,
            COALESCE(SUM(tax_amount), 0) as item_tax,
            COALESCE(SUM(CASE WHEN is_tax_exempt = true THEN subtotal ELSE 0 END), 0) as item_exempt,
            COALESCE(SUM(CASE WHEN is_tax_exempt = false THEN subtotal ELSE 0 END), 0) as item_taxable
        ')->groupBy('transaction_id')->get()->keyBy('transaction_id')->toArray();
    }

    public function taxReports(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $tab = $request->get('tab', 'pra');

        $pinRedirect = $this->requirePinForLocalTab($tab, $company);
        if ($pinRedirect) return $pinRedirect;

        $taxRateFilter = $request->filled('tax_rate') ? $request->tax_rate : null;

        $baseQuery = $this->buildTaxReportQuery($request, $tab, true);
        $transactions = $baseQuery->paginate(50)->appends($request->all());

        $itemValues = [];
        if ($taxRateFilter) {
            $allIdsQuery = $this->buildTaxReportQuery($request, $tab, true);
            $allIds = $allIdsQuery->pluck('id')->toArray();

            $summary = $this->buildItemLevelSummary($allIds, $taxRateFilter);
            $summary->total_discount = 0;

            $itemValues = $this->getItemLevelValuesForTransactions($transactions, $taxRateFilter);
        } else {
            $summaryQuery = $this->buildTaxReportQuery($request, $tab, true);
            $summary = $summaryQuery->reorder()->selectRaw('
                COUNT(*) as total_invoices,
                COALESCE(SUM(total_amount), 0) as total_sales,
                COALESCE(SUM(discount_amount), 0) as total_discount,
                COALESCE(SUM(subtotal - discount_amount - COALESCE(exempt_amount, 0)), 0) as total_taxable,
                COALESCE(SUM(tax_amount), 0) as total_tax,
                COALESCE(SUM(exempt_amount), 0) as total_exempt
            ')->first();
        }

        $dateLabel = $this->getReportDateLabel($request);

        $taxRateLabel = 'All Taxes';
        if ($taxRateFilter) {
            $taxRateLabel = $taxRateFilter === 'exempt' ? 'Exempt Items Only' : $taxRateFilter . '% Tax Only';
        }

        $hasPinSet = !empty($company->confidential_pin);
        $localCount = PosTransaction::where('company_id', $companyId)->where('status', 'completed')->where('invoice_mode', 'local')->count();
        $user = auth('pos')->user();

        return view('pos.tax-reports', compact('company', 'transactions', 'summary', 'dateLabel', 'taxRateLabel', 'tab', 'hasPinSet', 'localCount', 'user', 'itemValues', 'taxRateFilter'));
    }

    public function exportTaxReportCsv(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $tab = $request->get('tab', 'pra');

        $pinRedirect = $this->requirePinForLocalTab($tab, $company);
        if ($pinRedirect) return $pinRedirect;

        $taxRateFilter = $request->filled('tax_rate') ? $request->tax_rate : null;

        $query = $this->buildTaxReportQuery($request, $tab, (bool)$taxRateFilter);
        $transactions = $query->get();

        $dateLabel = $this->getReportDateLabel($request);
        $taxRateLabel = 'All Taxes';
        if ($taxRateFilter) {
            $taxRateLabel = $taxRateFilter === 'exempt' ? 'Exempt Items' : $taxRateFilter . '% Tax';
        }

        $itemValues = [];
        if ($taxRateFilter) {
            $itemValues = $this->getItemLevelValuesForTransactions($transactions, $taxRateFilter);
        }

        $filename = 'NestPOS_Tax_Report_' . str_replace([' ', '/', '(', ')'], '_', $taxRateLabel) . '_' . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($transactions, $taxRateFilter, $itemValues, $taxRateLabel) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            if ($taxRateFilter) {
                fputcsv($file, [
                    'POS Invoice Number',
                    'PRA Fiscal Invoice Number',
                    'Invoice Date',
                    'Customer Name',
                    'Payment Method',
                    $taxRateLabel . ' Value (PKR)',
                    $taxRateLabel . ' Tax Amount (PKR)',
                    $taxRateLabel . ' Total (PKR)',
                    'Terminal Name',
                    'PRA Status',
                ]);

                $totalValue = 0;
                $totalTax = 0;
                $totalWithTax = 0;

                foreach ($transactions as $t) {
                    $iv = $itemValues[$t->id] ?? null;
                    if (!$iv) continue;

                    $itemSub = (float)($iv['item_subtotal'] ?? 0);
                    $itemTax = (float)($iv['item_tax'] ?? 0);
                    $itemTotal = $itemSub + $itemTax;

                    $totalValue += $itemSub;
                    $totalTax += $itemTax;
                    $totalWithTax += $itemTotal;

                    fputcsv($file, [
                        $t->invoice_number,
                        $t->pra_invoice_number ?? 'N/A',
                        $t->created_at->format('d/m/Y H:i'),
                        $t->customer_name ?? 'Walk-in',
                        ucwords(str_replace('_', ' ', $t->payment_method)),
                        number_format($itemSub, 2, '.', ''),
                        number_format($itemTax, 2, '.', ''),
                        number_format($itemTotal, 2, '.', ''),
                        $t->terminal?->terminal_name ?? 'N/A',
                        strtoupper($t->pra_status ?? 'N/A'),
                    ]);
                }

                fputcsv($file, []);
                fputcsv($file, ['SUMMARY — ' . $taxRateLabel]);
                fputcsv($file, ['Invoices with ' . $taxRateLabel . ' items', count(array_filter($itemValues, fn($v) => ($v['item_subtotal'] ?? 0) > 0))]);
                fputcsv($file, [$taxRateLabel . ' Value (PKR)', number_format($totalValue, 2, '.', '')]);
                fputcsv($file, [$taxRateLabel . ' Tax Amount (PKR)', number_format($totalTax, 2, '.', '')]);
                fputcsv($file, [$taxRateLabel . ' Total (PKR)', number_format($totalWithTax, 2, '.', '')]);
            } else {
                fputcsv($file, [
                    'POS Invoice Number',
                    'PRA Fiscal Invoice Number',
                    'Invoice Date',
                    'Customer Name',
                    'Payment Method',
                    'Subtotal (PKR)',
                    'Discount Amount (PKR)',
                    'Taxable Amount (PKR)',
                    'Tax Exempt Amount (PKR)',
                    'Tax Rate (%)',
                    'Tax Amount (PKR)',
                    'Total Amount (PKR)',
                    'Terminal Name',
                    'PRA Status',
                ]);

                foreach ($transactions as $t) {
                    fputcsv($file, [
                        $t->invoice_number,
                        $t->pra_invoice_number ?? 'N/A',
                        $t->created_at->format('d/m/Y H:i'),
                        $t->customer_name ?? 'Walk-in',
                        ucwords(str_replace('_', ' ', $t->payment_method)),
                        number_format($t->subtotal, 2, '.', ''),
                        number_format($t->discount_amount, 2, '.', ''),
                        number_format($t->subtotal - $t->discount_amount - ($t->exempt_amount ?? 0), 2, '.', ''),
                        number_format($t->exempt_amount ?? 0, 2, '.', ''),
                        number_format($t->tax_rate, 2, '.', ''),
                        number_format($t->tax_amount, 2, '.', ''),
                        number_format($t->total_amount, 2, '.', ''),
                        $t->terminal?->terminal_name ?? 'N/A',
                        strtoupper($t->pra_status ?? 'N/A'),
                    ]);
                }

                fputcsv($file, []);
                fputcsv($file, ['SUMMARY']);
                fputcsv($file, ['Total Invoices', $transactions->count()]);
                fputcsv($file, ['Total Sales Amount (PKR)', number_format($transactions->sum('total_amount'), 2, '.', '')]);
                fputcsv($file, ['Total Discount Amount (PKR)', number_format($transactions->sum('discount_amount'), 2, '.', '')]);
                fputcsv($file, ['Total Taxable Amount (PKR)', number_format($transactions->sum(fn($t) => $t->subtotal - $t->discount_amount - ($t->exempt_amount ?? 0)), 2, '.', '')]);
                fputcsv($file, ['Total Tax Exempt Amount (PKR)', number_format($transactions->sum('exempt_amount'), 2, '.', '')]);
                fputcsv($file, ['Total Tax Amount (PKR)', number_format($transactions->sum('tax_amount'), 2, '.', '')]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportTaxReportPdf(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $tab = $request->get('tab', 'pra');

        $pinRedirect = $this->requirePinForLocalTab($tab, $company);
        if ($pinRedirect) return $pinRedirect;

        $taxRateFilter = $request->filled('tax_rate') ? $request->tax_rate : null;

        $query = $this->buildTaxReportQuery($request, $tab, (bool)$taxRateFilter);
        $transactions = $query->get();

        $itemValues = [];
        if ($taxRateFilter) {
            $allIds = $transactions->pluck('id')->toArray();
            $summary = $this->buildItemLevelSummary($allIds, $taxRateFilter);
            $summary->total_discount = 0;
            $itemValues = $this->getItemLevelValuesForTransactions($transactions, $taxRateFilter);
        } else {
            $summaryQuery = $this->buildTaxReportQuery($request, $tab, false);
            $summary = $summaryQuery->reorder()->selectRaw('
                COUNT(*) as total_invoices,
                COALESCE(SUM(total_amount), 0) as total_sales,
                COALESCE(SUM(discount_amount), 0) as total_discount,
                COALESCE(SUM(subtotal - discount_amount - COALESCE(exempt_amount, 0)), 0) as total_taxable,
                COALESCE(SUM(tax_amount), 0) as total_tax,
                COALESCE(SUM(exempt_amount), 0) as total_exempt
            ')->first();
        }

        $dateLabel = $this->getReportDateLabel($request);
        $taxRateLabel = 'All Taxes';
        if ($taxRateFilter) {
            $taxRateLabel = $taxRateFilter === 'exempt' ? 'Exempt Items Only' : $taxRateFilter . '% Tax Only';
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pos.tax-report-pdf', compact(
            'company', 'transactions', 'summary', 'dateLabel', 'taxRateLabel', 'taxRateFilter', 'itemValues'
        ));

        $pdf->setPaper('a4', 'landscape');

        $filename = 'NestPOS_Tax_Report_' . str_replace([' ', '/', '(', ')'], '_', $taxRateLabel) . '_' . now()->format('Ymd_His') . '.pdf';

        return $pdf->download($filename);
    }

    public function services()
    {
        $companyId = app('currentCompanyId');
        $services = PosService::where('company_id', $companyId)->orderBy('name')->get();
        return view('pos.services', compact('services'));
    }

    public function storeService(Request $request)
    {
        $companyId = app('currentCompanyId');
        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        PosService::create([
            'company_id' => $companyId,
            'name' => $request->name,
            'description' => $request->description,
            'price' => $request->price,
            'tax_rate' => $request->tax_rate ?? 0,
            'is_active' => true,
            'is_tax_exempt' => $request->has('is_tax_exempt'),
        ]);

        return back()->with('success', 'Service added successfully.');
    }

    public function updateService(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $service = PosService::where('company_id', $companyId)->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
        ]);

        $service->update([
            'name' => $request->name,
            'description' => $request->description,
            'price' => $request->price,
            'tax_rate' => $request->tax_rate ?? $service->tax_rate,
            'is_active' => $request->has('is_active'),
            'is_tax_exempt' => $request->has('is_tax_exempt'),
        ]);

        return back()->with('success', 'Service updated successfully.');
    }

    public function deleteService($id)
    {
        $companyId = app('currentCompanyId');
        PosService::where('company_id', $companyId)->findOrFail($id)->delete();
        return back()->with('success', 'Service deleted.');
    }

    public function getTaxRate(Request $request)
    {
        $method = $request->payment_method ?? 'cash';
        $rate = PosTaxRule::getRateForMethod($method);
        return response()->json(['tax_rate' => $rate]);
    }

    public function togglePra(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $company->pra_reporting_enabled = !$company->pra_reporting_enabled;
        $company->save();

        return response()->json([
            'success' => true,
            'enabled' => $company->pra_reporting_enabled,
            'message' => $company->pra_reporting_enabled ? 'PRA Reporting enabled' : 'PRA Reporting disabled',
        ]);
    }

    /**
     * Phase 4 — flip the "auto-print receipt on sale" setting.
     * Reads/writes the existing companies.print_on_pay column (default true).
     */
    public function toggleAutoPrint(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $company->print_on_pay = ! (bool) $company->print_on_pay;
        $company->save();

        return response()->json([
            'success' => true,
            'enabled' => (bool) $company->print_on_pay,
            'message' => $company->print_on_pay ? 'Auto-print enabled' : 'Auto-print disabled',
        ]);
    }

    /**
     * Phase 5+ — flip the "auto-print kitchen ticket on sale" setting.
     * Only meaningful for restaurant_mode companies; we still persist the bit
     * for everyone but the toggle pill is only rendered for restaurants.
     */
    public function toggleAutoKot(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if (!($company->restaurant_mode ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Auto-KOT is only available in restaurant mode.',
            ], 422);
        }

        $company->auto_print_kot = ! (bool) $company->auto_print_kot;
        $company->save();

        return response()->json([
            'success' => true,
            'enabled' => (bool) $company->auto_print_kot,
            'message' => $company->auto_print_kot ? 'Auto-KOT enabled' : 'Auto-KOT disabled',
        ]);
    }

    public function terminals()
    {
        $companyId = app('currentCompanyId');
        $terminals = PosTerminal::where('company_id', $companyId)->orderBy('terminal_name')->get();
        return view('pos.terminals', compact('terminals'));
    }

    public function storeTerminal(Request $request)
    {
        $companyId = app('currentCompanyId');
        $request->validate([
            'terminal_name' => 'required|string|max:255',
            'terminal_code' => 'required|string|max:100|unique:pos_terminals,terminal_code',
            'location' => 'nullable|string|max:255',
        ]);

        PosTerminal::create([
            'company_id' => $companyId,
            'terminal_name' => $request->terminal_name,
            'terminal_code' => $request->terminal_code,
            'location' => $request->location,
            'is_active' => true,
        ]);

        return back()->with('success', 'Terminal added successfully.');
    }

    public function updateTerminal(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $terminal = PosTerminal::where('company_id', $companyId)->findOrFail($id);

        $request->validate([
            'terminal_name' => 'required|string|max:255',
            'terminal_code' => 'required|string|max:100|unique:pos_terminals,terminal_code,' . $id,
            'location' => 'nullable|string|max:255',
        ]);

        $terminal->update([
            'terminal_name' => $request->terminal_name,
            'terminal_code' => $request->terminal_code,
            'location' => $request->location,
            'is_active' => $request->has('is_active'),
        ]);

        return back()->with('success', 'Terminal updated successfully.');
    }

    public function deleteTerminal($id)
    {
        $companyId = app('currentCompanyId');
        $terminal = PosTerminal::where('company_id', $companyId)->findOrFail($id);

        if ($terminal->transactions()->exists()) {
            return back()->with('error', 'Cannot delete terminal with existing transactions. Deactivate it instead.');
        }

        $terminal->delete();
        return back()->with('success', 'Terminal deleted.');
    }

    public function praSettings(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $user = auth('pos')->user();

        if ($request->isMethod('post')) {
            if ($user->isPosCashier()) {
                return back()->with('error', 'Only company admin can change settings.');
            }

            $request->validate([
                'pra_environment' => 'required|in:sandbox,production',
                'pra_pos_id' => 'nullable|string',
                'pra_production_token' => 'nullable|string',
                'pra_proxy_url' => 'nullable|url',
                'receipt_printer_size' => 'nullable|in:80mm,58mm',
            ]);

            $updateData = [
                'pra_environment' => $request->pra_environment,
                'receipt_printer_size' => $request->receipt_printer_size ?? '80mm',
            ];

            if ($request->filled('pra_pos_id')) {
                $updateData['pra_pos_id'] = $request->pra_pos_id;
            }

            if ($request->filled('pra_production_token')) {
                $updateData['pra_production_token'] = $request->pra_production_token;
            }

            $updateData['pra_proxy_url'] = $request->pra_proxy_url ?: null;

            $company->update($updateData);

            if ($request->filled('confidential_pin')) {
                $request->validate([
                    'confidential_pin' => 'string|min:4|max:6|regex:/^\d+$/',
                ]);
                $company->update([
                    'confidential_pin' => bcrypt($request->confidential_pin),
                ]);
                return back()->with('success', 'Settings & Confidential PIN updated.');
            }

            if ($request->has('remove_pin') && $request->remove_pin) {
                $company->update(['confidential_pin' => null]);
                return back()->with('success', 'Settings updated. Confidential PIN removed.');
            }

            return back()->with('success', 'PRA settings updated successfully.');
        }

        $praLogs = PraLog::where('company_id', $companyId)->orderBy('created_at', 'desc')->take(20)->get();
        $hasPinSet = !empty($company->confidential_pin);
        return view('pos.pra-settings', compact('company', 'praLogs', 'hasPinSet'));
    }

    public function verifyPin(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $lockKey = 'pin_lockout_' . $companyId;
        $attemptsKey = 'pin_attempts_' . $companyId;

        if (cache()->get($lockKey)) {
            $remaining = cache()->get($lockKey) - now()->timestamp;
            return response()->json([
                'success' => false,
                'message' => 'Too many wrong attempts. Try again in ' . ceil($remaining / 60) . ' minutes.',
                'locked' => true,
            ], 429);
        }

        if (empty($company->confidential_pin)) {
            return response()->json(['success' => false, 'message' => 'Confidential PIN not set. Admin must set it from Settings.'], 400);
        }

        $pin = $request->input('pin', '');
        if (\Hash::check($pin, $company->confidential_pin)) {
            cache()->forget($attemptsKey);
            session(['confidential_pin_verified' => true, 'confidential_pin_verified_at' => now()->timestamp]);
            return response()->json(['success' => true, 'message' => 'PIN verified.']);
        }

        $attempts = (int) cache()->get($attemptsKey, 0) + 1;
        cache()->put($attemptsKey, $attempts, 900);

        if ($attempts >= 5) {
            cache()->put($lockKey, now()->addMinutes(15)->timestamp, 900);
            cache()->forget($attemptsKey);
            return response()->json([
                'success' => false,
                'message' => 'Too many wrong attempts. Locked for 15 minutes.',
                'locked' => true,
            ], 429);
        }

        return response()->json([
            'success' => false,
            'message' => 'Wrong PIN. ' . (5 - $attempts) . ' attempts remaining.',
            'remaining' => 5 - $attempts,
        ], 401);
    }

    public function checkPinSession()
    {
        $verified = session('confidential_pin_verified', false);
        $verifiedAt = session('confidential_pin_verified_at', 0);
        $isValid = $verified && (now()->timestamp - $verifiedAt) < 1800;

        return response()->json(['verified' => $isValid]);
    }

    public function posTeam(Request $request)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        if ($user->isPosCashier()) {
            return redirect()->route('pos.dashboard')->with('error', 'Access denied.');
        }

        $team = User::where('company_id', $companyId)
            ->whereIn('pos_role', ['pos_admin', 'pos_cashier'])
            ->orderByRaw("CASE WHEN pos_role = 'pos_admin' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();

        return view('pos.team', compact('team'));
    }

    public function storeCashier(Request $request)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        if ($user->isPosCashier()) {
            return back()->with('error', 'Access denied.');
        }

        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|string|min:6',
        ]);

        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => bcrypt($request->password),
            'company_id' => $companyId,
            'role' => 'employee',
            'pos_role' => 'pos_cashier',
            'is_active' => true,
        ]);

        return back()->with('success', 'Cashier account created successfully.');
    }

    public function updateCashier(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        if ($user->isPosCashier()) {
            return back()->with('error', 'Access denied.');
        }

        $cashier = User::where('company_id', $companyId)->where('pos_role', 'pos_cashier')->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email,' . $cashier->id,
            'phone' => 'nullable|string|max:20',
        ]);

        $cashier->update([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
        ]);

        if ($request->filled('password')) {
            $cashier->update(['password' => bcrypt($request->password)]);
        }

        return back()->with('success', 'Cashier updated.');
    }

    public function toggleCashier($id)
    {
        $companyId = app('currentCompanyId');
        $user = auth('pos')->user();

        if ($user->isPosCashier()) {
            return back()->with('error', 'Access denied.');
        }

        $cashier = User::where('company_id', $companyId)->where('pos_role', 'pos_cashier')->findOrFail($id);
        $cashier->update(['is_active' => !$cashier->is_active]);

        return back()->with('success', $cashier->is_active ? 'Cashier activated.' : 'Cashier deactivated.');
    }

    public function products()
    {
        $companyId = app('currentCompanyId');
        $products = PosProduct::where('company_id', $companyId)->orderBy('name')->get();
        $company = \App\Models\Company::find($companyId);
        $posType = $company->pos_type ?? 'retail';
        $categoryFields = PosProduct::categoryFields()[$posType] ?? [];
        return view('pos.products', compact('products', 'posType', 'categoryFields'));
    }

    /**
     * Smart Quick-Create endpoint for Simple POS mode.
     * Creates a minimal POS product on the fly when cashier types something
     * not in catalog AND inventory is OFF. Defense in depth: refuses when
     * inventory is enabled (UI must direct user to /pos/products instead).
     *
     * Inputs: name (required), price (optional, defaults 0)
     * Returns: { ok, product:{...} } shaped to slot into Alpine `allProducts`.
     */
    public function apiQuickCreate(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = \App\Models\Company::find($companyId);
        if (!$company) {
            return response()->json(['ok' => false, 'error' => 'Company not found'], 404);
        }
        // Server-side inventory guard — quick-create only allowed in Simple Mode.
        if (!empty($company->inventory_enabled)) {
            return response()->json([
                'ok' => false,
                'error' => 'Quick-create is disabled when inventory mode is on. Add product from Product Management.',
                'reason' => 'inventory_enabled',
            ], 422);
        }
        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
        ]);
        $name = trim($data['name']);
        if ($name === '') {
            return response()->json(['ok' => false, 'error' => 'Name required'], 422);
        }
        $product = PosProduct::create([
            'company_id'    => $companyId,
            'name'          => $name,
            'price'         => $data['price'] ?? 0,
            'cost_price'    => $data['cost_price'] ?? 0,
            'tax_rate'      => 0,
            'is_active'     => true,
            'is_tax_exempt' => false,
            'category'      => 'Quick',
            'sku'           => 'QC-' . substr((string) time(), -6) . '-' . strtoupper(substr(uniqid(), -3)),
            'uom'           => 'NOS',
        ]);
        return response()->json([
            'ok' => true,
            'product' => [
                'id'            => $product->id,
                'name'          => $product->name,
                'price'         => (float) $product->price,
                'category'      => $product->category,
                'type'          => 'product',
                'image'         => null,
                'is_tax_exempt' => false,
                'hasRecipe'     => false,
                'stockStatus'   => null,
                'isQuickCreated'=> true,
            ],
        ]);
    }

    /**
     * Inline price update for a freshly quick-created product.
     * Cashier sets the price right after add; this persists it.
     */
    public function apiQuickUpdatePrice(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $data = $request->validate([
            'price' => 'required|numeric|min:0',
        ]);
        $product = PosProduct::where('company_id', $companyId)->where('id', $id)->first();
        if (!$product) {
            return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        }
        $product->price = $data['price'];
        $product->save();
        return response()->json(['ok' => true, 'price' => (float) $product->price]);
    }

    public function storeProduct(Request $request)
    {
        $companyId = app('currentCompanyId');
        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'category' => 'nullable|string|max:100',
            'sku' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:100',
            'uom' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:500',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
            'batch_number' => 'nullable|string|max:100',
            'expiry_date' => 'nullable|date',
            'drug_type' => 'nullable|string|max:50',
            'unit_type' => 'nullable|string|max:20',
            'size' => 'nullable|string|max:30',
            'color' => 'nullable|string|max:50',
            'season' => 'nullable|string|max:30',
            'serial_number' => 'nullable|string|max:100',
            'warranty_months' => 'nullable|integer|min:0',
            'imei' => 'nullable|string|max:20',
            'bulk_discount_qty' => 'nullable|integer|min:0',
            'bulk_discount_pct' => 'nullable|numeric|min:0|max:100',
            'service_duration' => 'nullable|integer|min:0',
            'staff_assignment' => 'nullable|string|max:100',
            'vehicle_make' => 'nullable|string|max:50',
            'vehicle_model' => 'nullable|string|max:50',
            'part_number' => 'nullable|string|max:100',
            'box_type' => 'nullable|string|max:50',
        ]);

        $imageName = null;
        if ($request->hasFile('image')) {
            $imageName = $companyId . '_' . time() . '_' . uniqid() . '.' . $request->file('image')->getClientOriginalExtension();
            $request->file('image')->storeAs('products', $imageName, 'public');
        }

        $isExempt = $request->has('is_tax_exempt');
        $data = [
            'company_id' => $companyId,
            'name' => $request->name,
            'description' => $request->description,
            'price' => $request->price,
            'cost_price' => $request->filled('cost_price') ? $request->cost_price : 0,
            // Backend hardening: exempt MUST persist tax_rate=0 regardless of what (if anything) UI submitted
            'tax_rate' => $isExempt ? 0 : ($request->tax_rate ?? 0),
            'category' => $request->category,
            'sku' => $request->sku,
            'barcode' => $request->barcode,
            'uom' => $request->uom ?? 'NOS',
            'is_tax_exempt' => $isExempt,
            'image' => $imageName,
            'prescription_required' => $request->has('prescription_required'),
            'weight_based' => $request->has('weight_based'),
            'custom_order' => $request->has('custom_order'),
        ];

        $categoryExtraFields = [
            'batch_number', 'expiry_date', 'drug_type', 'unit_type',
            'size', 'color', 'season', 'serial_number', 'warranty_months', 'imei',
            'bulk_discount_qty', 'bulk_discount_pct', 'service_duration', 'staff_assignment',
            'vehicle_make', 'vehicle_model', 'part_number', 'box_type',
        ];
        foreach ($categoryExtraFields as $field) {
            if ($request->filled($field)) {
                $data[$field] = $request->$field;
            }
        }

        $product = PosProduct::create($data);

        // Auto-fetch image ONLY if cashier explicitly chose image_mode=auto.
        // Default (none) leaves the image field blank so the list shows
        // a name-only row — exactly what the user asked for.
        if (!$imageName && $request->name && $request->input('image_mode') === 'auto') {
            try {
                $autoImage = \App\Services\ProductImageService::fetchForProduct($request->name, $companyId);
                if ($autoImage) {
                    $product->update(['image' => $autoImage]);
                }
            } catch (\Exception $e) {}
        }

        return back()->with('success', 'Product added successfully.');
    }

    public function downloadProductTemplate()
    {
        $companyId = app('currentCompanyId');
        $existingProducts = PosProduct::where('company_id', $companyId)->orderBy('name')->get();

        $headers = ['Name', 'Price', 'Description', 'Category', 'SKU', 'Barcode', 'Tax Rate %', 'Unit (UOM)'];

        $callback = function() use ($headers, $existingProducts) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($file, $headers);

            if ($existingProducts->isEmpty()) {
                fputcsv($file, ['Chicken Biryani', '450', 'Full plate biryani with raita', 'Food', 'CB-001', '8901234567890', '16', 'NOS']);
                fputcsv($file, ['Pepsi 500ml', '120', 'Cold drink bottle', 'Beverages', 'PEP-500', '8901234567891', '5', 'NOS']);
                fputcsv($file, ['Naan', '30', 'Tandoori naan', 'Food', 'NAN-001', '', '0', 'NOS']);
            } else {
                foreach ($existingProducts as $p) {
                    fputcsv($file, [
                        $p->name,
                        $p->price,
                        $p->description ?? '',
                        $p->category ?? '',
                        $p->sku ?? '',
                        $p->barcode ?? '',
                        $p->tax_rate ?? 0,
                        $p->uom ?? 'NOS',
                    ]);
                }
            }

            fclose($file);
        };

        $filename = $existingProducts->isEmpty() ? 'pos_products_template.csv' : 'pos_products_export.csv';

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    public function importProducts(Request $request)
    {
        $companyId = app('currentCompanyId');

        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $file = $request->file('csv_file');
        $handle = fopen($file->getRealPath(), 'r');

        if (!$handle) {
            return back()->with('error', 'Could not read file.');
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return back()->with('error', 'Empty file or invalid format.');
        }

        $header = array_map(function($h) {
            return strtolower(trim(preg_replace('/[\x{FEFF}]/u', '', $h)));
        }, $header);

        $nameIdx = array_search('name', $header);
        $priceIdx = array_search('price', $header);

        if ($nameIdx === false || $priceIdx === false) {
            fclose($handle);
            return back()->with('error', 'CSV must have "Name" and "Price" columns. Download the template for the correct format.');
        }

        $descIdx = $this->findColumn($header, ['description']);
        $catIdx = $this->findColumn($header, ['category']);
        $skuIdx = $this->findColumn($header, ['sku']);
        $barcodeIdx = $this->findColumn($header, ['barcode']);
        $taxIdx = $this->findColumn($header, ['tax rate %', 'tax rate', 'tax_rate', 'tax']);
        $uomIdx = $this->findColumn($header, ['unit (uom)', 'unit', 'uom']);

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $row = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $row++;
            $name = trim($data[$nameIdx] ?? '');
            $price = trim($data[$priceIdx] ?? '');

            if ($name === '' || $price === '') {
                $skipped++;
                continue;
            }

            if (!is_numeric($price) || $price < 0) {
                $errors[] = "Row {$row}: Invalid price for '{$name}'";
                $skipped++;
                continue;
            }

            $existing = PosProduct::where('company_id', $companyId)
                ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                ->first();

            if ($existing) {
                $existing->update([
                    'price' => (float) $price,
                    'description' => $descIdx !== false ? trim($data[$descIdx] ?? '') ?: $existing->description : $existing->description,
                    'category' => $catIdx !== false ? trim($data[$catIdx] ?? '') ?: $existing->category : $existing->category,
                    'sku' => $skuIdx !== false ? trim($data[$skuIdx] ?? '') ?: $existing->sku : $existing->sku,
                    'barcode' => $barcodeIdx !== false ? trim($data[$barcodeIdx] ?? '') ?: $existing->barcode : $existing->barcode,
                    'tax_rate' => $taxIdx !== false && is_numeric(trim($data[$taxIdx] ?? '')) ? (float) trim($data[$taxIdx]) : $existing->tax_rate,
                    'uom' => $uomIdx !== false && trim($data[$uomIdx] ?? '') !== '' ? strtoupper(trim($data[$uomIdx])) : $existing->uom,
                ]);
                $imported++;
            } else {
                PosProduct::create([
                    'company_id' => $companyId,
                    'name' => $name,
                    'price' => (float) $price,
                    'description' => $descIdx !== false ? trim($data[$descIdx] ?? '') : null,
                    'category' => $catIdx !== false ? trim($data[$catIdx] ?? '') : null,
                    'sku' => $skuIdx !== false ? trim($data[$skuIdx] ?? '') : null,
                    'barcode' => $barcodeIdx !== false ? trim($data[$barcodeIdx] ?? '') : null,
                    'tax_rate' => $taxIdx !== false && is_numeric(trim($data[$taxIdx] ?? '')) ? (float) trim($data[$taxIdx]) : 0,
                    'uom' => $uomIdx !== false && trim($data[$uomIdx] ?? '') !== '' ? strtoupper(trim($data[$uomIdx])) : 'NOS',
                    'is_active' => true,
                ]);
                $imported++;
            }
        }

        fclose($handle);

        $msg = "{$imported} products imported successfully.";
        if ($skipped > 0) $msg .= " {$skipped} rows skipped.";
        if (!empty($errors)) $msg .= " Issues: " . implode('; ', array_slice($errors, 0, 3));

        return back()->with('success', $msg);
    }

    private function findColumn(array $header, array $names): int|false
    {
        foreach ($names as $name) {
            $idx = array_search($name, $header);
            if ($idx !== false) return $idx;
        }
        return false;
    }

    public function updateProduct(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $product = PosProduct::where('company_id', $companyId)->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'category' => 'nullable|string|max:100',
            'sku' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:100',
            'uom' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:500',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
            'batch_number' => 'nullable|string|max:100',
            'expiry_date' => 'nullable|date',
            'drug_type' => 'nullable|string|max:50',
            'unit_type' => 'nullable|string|max:20',
            'size' => 'nullable|string|max:30',
            'color' => 'nullable|string|max:50',
            'season' => 'nullable|string|max:30',
            'serial_number' => 'nullable|string|max:100',
            'warranty_months' => 'nullable|integer|min:0',
            'imei' => 'nullable|string|max:20',
            'bulk_discount_qty' => 'nullable|integer|min:0',
            'bulk_discount_pct' => 'nullable|numeric|min:0|max:100',
            'service_duration' => 'nullable|integer|min:0',
            'staff_assignment' => 'nullable|string|max:100',
            'vehicle_make' => 'nullable|string|max:50',
            'vehicle_model' => 'nullable|string|max:50',
            'part_number' => 'nullable|string|max:100',
            'box_type' => 'nullable|string|max:50',
        ]);

        $isExempt = $request->has('is_tax_exempt');
        $data = array_merge(
            $request->only(['name', 'description', 'price', 'category', 'sku', 'barcode', 'uom']),
            [
                'cost_price' => $request->filled('cost_price') ? $request->cost_price : 0,
                // Backend hardening: exempt MUST force tax_rate=0; otherwise honor submitted value (or keep current if absent)
                'tax_rate' => $isExempt ? 0 : ($request->has('tax_rate') ? $request->tax_rate : $product->tax_rate),
                'is_tax_exempt' => $isExempt,
                'prescription_required' => $request->has('prescription_required'),
                'weight_based' => $request->has('weight_based'),
                'custom_order' => $request->has('custom_order'),
            ]
        );

        $categoryExtraFields = [
            'batch_number', 'expiry_date', 'drug_type', 'unit_type',
            'size', 'color', 'season', 'serial_number', 'warranty_months', 'imei',
            'bulk_discount_qty', 'bulk_discount_pct', 'service_duration', 'staff_assignment',
            'vehicle_make', 'vehicle_model', 'part_number', 'box_type',
        ];
        foreach ($categoryExtraFields as $field) {
            $data[$field] = $request->filled($field) ? $request->$field : null;
        }

        if ($request->has('remove_image') && $request->remove_image === '1') {
            if ($product->image && \Illuminate\Support\Facades\Storage::disk('public')->exists('products/' . $product->image)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete('products/' . $product->image);
            }
            $data['image'] = null;
        }

        if ($request->hasFile('image')) {
            if ($product->image && \Illuminate\Support\Facades\Storage::disk('public')->exists('products/' . $product->image)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete('products/' . $product->image);
            }
            $imageName = $companyId . '_' . time() . '_' . uniqid() . '.' . $request->file('image')->getClientOriginalExtension();
            $request->file('image')->storeAs('products', $imageName, 'public');
            $data['image'] = $imageName;
        }

        $product->update($data);

        // Auto-fetch image ONLY if cashier explicitly chose image_mode=auto on edit.
        // Other modes (keep / upload / remove) are already handled above.
        if ($request->input('image_mode') === 'auto' && empty($data['image'] ?? null) && $product->name) {
            try {
                $autoImage = \App\Services\ProductImageService::fetchForProduct($product->name, $companyId);
                if ($autoImage) {
                    $product->update(['image' => $autoImage]);
                }
            } catch (\Exception $e) {}
        }

        return back()->with('success', 'Product updated successfully.');
    }

    public function deleteProduct($id)
    {
        $companyId = app('currentCompanyId');
        $product = PosProduct::where('company_id', $companyId)->findOrFail($id);
        $product->delete();
        return back()->with('success', 'Product deleted successfully.');
    }

    public function toggleProduct($id)
    {
        $companyId = app('currentCompanyId');
        $product = PosProduct::where('company_id', $companyId)->findOrFail($id);
        $product->update(['is_active' => !$product->is_active]);
        return back()->with('success', $product->is_active ? 'Product activated.' : 'Product deactivated.');
    }

    public function customers()
    {
        $companyId = app('currentCompanyId');
        $customers = PosCustomer::where('company_id', $companyId)->orderBy('name')->get();
        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';
        return view('pos.customers', compact('customers', 'isCashier'));
    }

    public function storeCustomer(Request $request)
    {
        $companyId = app('currentCompanyId');
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'ntn' => 'nullable|string|max:50',
            'cnic' => 'nullable|string|max:20',
            'type' => 'required|in:registered,unregistered',
        ]);

        $customer = PosCustomer::create(array_merge($request->only(['name', 'email', 'phone', 'address', 'city', 'ntn', 'cnic', 'type']), [
            'company_id' => $companyId,
        ]));

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'customer' => ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone]]);
        }

        return back()->with('success', 'Customer added successfully.');
    }

    public function updateCustomer(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'ntn' => 'nullable|string|max:50',
            'cnic' => 'nullable|string|max:20',
            'type' => 'required|in:registered,unregistered',
        ]);

        $customer->update($request->only(['name', 'email', 'phone', 'address', 'city', 'ntn', 'cnic', 'type']));
        return back()->with('success', 'Customer updated successfully.');
    }

    public function deleteCustomer($id)
    {
        $companyId = app('currentCompanyId');
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);
        $customer->delete();
        return back()->with('success', 'Customer deleted successfully.');
    }

    public function toggleCustomer($id)
    {
        $companyId = app('currentCompanyId');
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);
        $customer->update(['is_active' => !$customer->is_active]);
        return back()->with('success', $customer->is_active ? 'Customer activated.' : 'Customer deactivated.');
    }

    public function getLastOrder(Request $request)
    {
        $companyId = app('currentCompanyId');
        $userId = auth()->id();

        $last = \App\Models\PosTransaction::where('company_id', $companyId)
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->orderByDesc('id')
            ->first();

        if (!$last) {
            return response()->json(['success' => false, 'message' => 'No previous order found.']);
        }

        $items = \DB::table('pos_transaction_items')
            ->where('transaction_id', $last->id)
            ->get()
            ->map(function ($it) {
                return [
                    'type' => $it->item_type ?? 'product',
                    'item_id' => $it->item_id ?? '',
                    'name' => $it->item_name ?? '',
                    'quantity' => (float) ($it->quantity ?? 1),
                    'unit_price' => (float) ($it->unit_price ?? 0),
                    'is_tax_exempt' => (bool) ($it->is_tax_exempt ?? false),
                    '_isNew' => false,
                ];
            })->values();

        return response()->json([
            'success' => true,
            'invoice_number' => $last->invoice_number,
            'customer_name' => $last->customer_name,
            'customer_phone' => $last->customer_phone,
            'payment_method' => $last->payment_method,
            'items' => $items,
        ]);
    }

    public function saveDraft(Request $request)
    {
        $companyId = app('currentCompanyId');

        $request->validate([
            'draft_data' => 'required|array',
        ]);

        $draftId = $request->input('draft_id');
        $draftData = $request->input('draft_data');

        if ($draftId) {
            $draft = PosTransaction::where('company_id', $companyId)
                ->where('id', $draftId)
                ->where('status', 'draft')
                ->first();

            if ($draft) {
                if ($draft->isLocked() && $draft->locked_by_terminal_id != $request->input('terminal_id')) {
                    return response()->json(['success' => false, 'message' => 'This invoice is currently being edited on another terminal.'], 423);
                }

                $draft->update([
                    'customer_name' => $draftData['customer_name'] ?? null,
                    'customer_phone' => $draftData['customer_phone'] ?? null,
                    'terminal_id' => $draftData['terminal_id'] ?? null,
                    'subtotal' => $draftData['subtotal'] ?? 0,
                    'discount_type' => $draftData['discount_type'] ?? 'percentage',
                    'discount_value' => $draftData['discount_value'] ?? 0,
                    'discount_amount' => $draftData['discount_amount'] ?? 0,
                    'tax_rate' => $draftData['tax_rate'] ?? 0,
                    'tax_amount' => $draftData['tax_amount'] ?? 0,
                    'total_amount' => $draftData['total_amount'] ?? 0,
                    'payment_method' => $draftData['payment_method'] ?? 'cash',
                ]);

                $draft->items()->delete();
                foreach (($draftData['items'] ?? []) as $item) {
                    PosTransactionItem::create([
                        'transaction_id' => $draft->id,
                        'item_type' => $item['type'] ?? 'product',
                        'item_id' => $item['item_id'] ?? null,
                        'item_name' => $item['name'] ?? '',
                        'quantity' => $item['quantity'] ?? 1,
                        'unit_price' => $item['unit_price'] ?? 0,
                        'subtotal' => round(($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0), 2),
                    ]);
                }

                return response()->json(['success' => true, 'draft_id' => $draft->id]);
            }
        }

        DB::beginTransaction();
        try {
            $company = Company::find($companyId);
            $praEnabled = (bool) $company->pra_reporting_enabled;
            $invoiceMode = $praEnabled ? 'pra' : 'local';
            $invoiceNumber = $invoiceMode === 'local'
                ? $this->generateLocalInvoiceNumber($companyId)
                : $this->generateInvoiceNumber($companyId);

            $draft = PosTransaction::create([
                'company_id' => $companyId,
                'terminal_id' => $draftData['terminal_id'] ?? null,
                'invoice_number' => $invoiceNumber,
                'invoice_mode' => $invoiceMode,
                'customer_name' => $draftData['customer_name'] ?? null,
                'customer_phone' => $draftData['customer_phone'] ?? null,
                'subtotal' => $draftData['subtotal'] ?? 0,
                'discount_type' => $draftData['discount_type'] ?? 'percentage',
                'discount_value' => $draftData['discount_value'] ?? 0,
                'discount_amount' => $draftData['discount_amount'] ?? 0,
                'tax_rate' => $draftData['tax_rate'] ?? 0,
                'tax_amount' => $draftData['tax_amount'] ?? 0,
                'total_amount' => $draftData['total_amount'] ?? 0,
                'payment_method' => $draftData['payment_method'] ?? 'cash',
                'status' => 'draft',
                'pra_status' => $praEnabled ? 'pending' : 'local',
                'created_by' => auth('pos')->id(),
                'locked_by_terminal_id' => $draftData['terminal_id'] ?? null,
                'lock_time' => now(),
            ]);

            foreach (($draftData['items'] ?? []) as $item) {
                PosTransactionItem::create([
                    'transaction_id' => $draft->id,
                    'item_type' => $item['type'] ?? 'product',
                    'item_id' => $item['item_id'] ?? null,
                    'item_name' => $item['name'] ?? '',
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => $item['unit_price'] ?? 0,
                    'subtotal' => round(($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0), 2),
                ]);
            }

            DB::commit();
            return response()->json(['success' => true, 'draft_id' => $draft->id]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getDrafts()
    {
        $companyId = app('currentCompanyId');

        $drafts = PosTransaction::where('company_id', $companyId)
            ->where('status', 'draft')
            ->with('items')
            ->orderBy('updated_at', 'desc')
            ->take(10)
            ->get();

        return response()->json(['drafts' => $drafts]);
    }

    public function deleteDraft($id)
    {
        $companyId = app('currentCompanyId');
        $draft = PosTransaction::where('company_id', $companyId)
            ->where('id', $id)
            ->where('status', 'draft')
            ->first();

        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found.'], 404);
        }

        $draft->items()->delete();
        $draft->payments()->delete();
        $draft->delete();

        return response()->json(['success' => true]);
    }

    public function lockInvoice(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $terminalId = $request->input('terminal_id');

        if (!$terminalId) {
            return response()->json(['success' => false, 'message' => 'Terminal ID required.'], 400);
        }

        $transaction = PosTransaction::where('company_id', $companyId)->findOrFail($id);

        if ($transaction->isLocked() && $transaction->locked_by_terminal_id != $terminalId) {
            $lockedTerminal = PosTerminal::find($transaction->locked_by_terminal_id);
            $terminalName = $lockedTerminal ? $lockedTerminal->terminal_name : 'Unknown';
            return response()->json([
                'success' => false,
                'message' => "This invoice is currently being edited on another terminal ({$terminalName}).",
                'locked_by' => $terminalName,
                'lock_time' => $transaction->lock_time?->toISOString(),
            ], 423);
        }

        $transaction->acquireLock((int) $terminalId);
        return response()->json(['success' => true]);
    }

    public function unlockInvoice($id)
    {
        $companyId = app('currentCompanyId');
        $transaction = PosTransaction::where('company_id', $companyId)->findOrFail($id);
        $transaction->releaseLock();
        return response()->json(['success' => true]);
    }

    private function resolveItemExemptions(array $requestItems, int $companyId): array
    {
        $resolved = [];
        foreach ($requestItems as $item) {
            $itemType = $item['type'] ?? 'product';
            $itemId = $item['item_id'] ?? null;
            $itemName = trim($item['name'] ?? '');
            $itemPrice = (float) ($item['unit_price'] ?? 0);
            $qty = (float) ($item['quantity'] ?? 0);
            $isExempt = !empty($item['is_tax_exempt']);

            if ($itemId) {
                if ($itemType === 'product') {
                    $obj = PosProduct::where('company_id', $companyId)->where('id', $itemId)->first();
                    if ($obj) {
                        $isExempt = (bool) $obj->is_tax_exempt;
                    } else {
                        $itemId = null;
                    }
                } else {
                    $obj = PosService::where('company_id', $companyId)->where('id', $itemId)->first();
                    if ($obj) {
                        $isExempt = (bool) $obj->is_tax_exempt;
                    } else {
                        $itemId = null;
                    }
                }
            }

            // Skip auto-create for cashier "manual" lines — these are ephemeral
            // ad-hoc entries (Quick Type unmatched + "+ Manual" button in
            // inventory-OFF mode) and must NOT pollute the product master.
            // Frontend sends `_manual: true` in the payload to flag them.
            if (!$itemId && $itemType === 'product' && $itemName !== '' && empty($item['_manual'])) {
                $existing = PosProduct::where('company_id', $companyId)
                    ->whereRaw('LOWER(name) = ?', [strtolower($itemName)])
                    ->first();
                if ($existing) {
                    $itemId = $existing->id;
                    $isExempt = (bool) $existing->is_tax_exempt;
                } else {
                    $userExempt = !empty($item['is_tax_exempt']);
                    $newProduct = PosProduct::create([
                        'company_id' => $companyId,
                        'name' => $itemName,
                        'price' => $itemPrice,
                        'tax_rate' => 0,
                        'uom' => 'NOS',
                        'is_active' => true,
                        'is_tax_exempt' => $userExempt,
                    ]);
                    $itemId = $newProduct->id;
                    $isExempt = $userExempt;
                }
            }

            $resolved[] = [
                'type' => $itemType,
                'item_id' => $itemId,
                'name' => $itemName,
                'price' => $itemPrice,
                'quantity' => $qty,
                'lineTotal' => round($qty * $itemPrice, 2),
                'isExempt' => $isExempt,
                'notes' => isset($item['special_notes']) ? (string) $item['special_notes'] : null,
            ];
        }
        return $resolved;
    }

    private function generateInvoiceNumber(int $companyId): string
    {
        $year = now()->format('Y');

        $lastTransaction = PosTransaction::where('company_id', $companyId)
            ->where('invoice_number', 'like', "POS-{$year}-%")
            ->orderBy('id', 'desc')
            ->lockForUpdate()
            ->first();

        if ($lastTransaction && preg_match('/POS-\d{4}-(\d+)/', $lastTransaction->invoice_number, $matches)) {
            $next = (int) $matches[1] + 1;
        } else {
            $next = 1;
        }

        return 'POS-' . $year . '-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }

    private function generateLocalInvoiceNumber(int $companyId): string
    {
        $year = now()->format('Y');

        $lastTransaction = PosTransaction::where('company_id', $companyId)
            ->where('invoice_number', 'like', "LOCAL-{$year}-%")
            ->orderBy('id', 'desc')
            ->lockForUpdate()
            ->first();

        if ($lastTransaction && preg_match('/LOCAL-\d{4}-(\d+)/', $lastTransaction->invoice_number, $matches)) {
            $next = (int) $matches[1] + 1;
        } else {
            $next = 1;
        }

        return 'LOCAL-' . $year . '-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }

    public function billing()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $plans = \App\Models\PricingPlan::where('is_trial', false)->orderBy('price')->get();
        $currentSubscription = \App\Models\Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->with('pricingPlan')
            ->first();

        return view('pos.billing', compact('company', 'plans', 'currentSubscription'));
    }

    public function businessProfile(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if ($request->isMethod('post')) {
            $rules = [
                'name' => 'required|string|max:255',
                'owner_name' => 'nullable|string|max:255',
                'ntn' => 'nullable|string|max:50',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:30',
                'mobile' => 'nullable|string|max:30',
                'address' => 'nullable|string|max:500',
                'city' => 'nullable|string|max:100',
                'business_activity' => 'nullable|string|max:255',
                'website' => 'nullable|url|max:255',
                'logo' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
            ];

            $request->validate($rules);

            $data = $request->only(['name', 'owner_name', 'ntn', 'email', 'phone', 'mobile', 'address', 'city', 'business_activity', 'website']);
            $data['inventory_enabled'] = $request->has('inventory_enabled');
            $data['restaurant_mode'] = $request->has('restaurant_mode');

            if ($request->hasFile('logo')) {
                if ($company->logo_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($company->logo_path)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($company->logo_path);
                }
                $path = $request->file('logo')->store('company-logos', 'public');
                $data['logo_path'] = $path;
            }

            if ($request->has('remove_logo') && $request->remove_logo === '1') {
                if ($company->logo_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($company->logo_path)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($company->logo_path);
                }
                $data['logo_path'] = null;
            }

            $company->update($data);
            return back()->with('success', 'Business profile updated successfully.');
        }

        return view('pos.business-profile', compact('company'));
    }

    public function userProfile(Request $request)
    {
        $user = \Illuminate\Support\Facades\Auth::guard('pos')->user();

        if ($request->isMethod('post')) {
            $action = $request->input('action');

            if ($action === 'update_profile') {
                $request->validate([
                    'name' => 'required|string|max:255',
                    'email' => 'required|email|max:255|unique:users,email,' . $user->id,
                    'phone' => 'nullable|string|max:30',
                    'username' => 'nullable|string|max:100|unique:users,username,' . $user->id,
                ]);

                $user->update($request->only(['name', 'email', 'phone', 'username']));
                return back()->with('success', 'Profile updated successfully.');
            }

            if ($action === 'change_password') {
                $request->validate([
                    'current_password' => 'required',
                    'new_password' => 'required|string|min:8|confirmed',
                ]);

                if (!\Illuminate\Support\Facades\Hash::check($request->current_password, $user->password)) {
                    return back()->withErrors(['current_password' => 'Current password is incorrect.']);
                }

                $user->update([
                    'password' => \Illuminate\Support\Facades\Hash::make($request->new_password),
                ]);
                return back()->with('success', 'Password changed successfully.');
            }
        }

        return view('pos.user-profile', compact('user'));
    }

    public function dayCloseReport(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $date = $request->get('date', today()->format('Y-m-d'));

        $existingReport = PosDayCloseReport::where('company_id', $companyId)
            ->where('report_date', $date)
            ->first();

        $transactions = PosTransaction::where('company_id', $companyId)
            ->whereDate('created_at', $date)
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        $stats = (object) [
            'total_invoices' => $transactions->count(),
            'pra_invoices' => $transactions->where('pra_status', 'submitted')->count(),
            'local_invoices' => $transactions->whereIn('pra_status', ['local', null])->count(),
            'offline_invoices' => $transactions->where('pra_status', 'offline')->count(),
            'gross_sales' => $transactions->sum('subtotal'),
            'total_discount' => $transactions->sum('discount_amount'),
            'net_sales' => $transactions->sum('subtotal') - $transactions->sum('discount_amount'),
            'total_tax' => $transactions->sum('tax_amount'),
            'total_amount' => $transactions->sum('total_amount'),
            'cash_amount' => $transactions->where('payment_method', 'cash')->sum('total_amount'),
            'card_amount' => $transactions->where('payment_method', 'card')->sum('total_amount'),
            'other_amount' => $transactions->whereNotIn('payment_method', ['cash', 'card'])->sum('total_amount'),
            'first_invoice' => $transactions->first(),
            'last_invoice' => $transactions->last(),
        ];

        $cashierBreakdown = $transactions->groupBy(fn($t) => $t->creator ? $t->creator->name : 'Unknown')->map(function ($group) {
            return (object) [
                'count' => $group->count(),
                'revenue' => $group->sum('total_amount'),
                'tax' => $group->sum('tax_amount'),
            ];
        });

        $previousReports = PosDayCloseReport::where('company_id', $companyId)
            ->orderBy('report_date', 'desc')
            ->limit(10)
            ->get();

        return view('pos.day-close', compact('company', 'date', 'stats', 'existingReport', 'cashierBreakdown', 'previousReports', 'transactions'));
    }

    public function closeDayReport(Request $request)
    {
        $companyId = app('currentCompanyId');
        $user = \Illuminate\Support\Facades\Auth::guard('pos')->user();
        $date = $request->input('date', today()->format('Y-m-d'));

        $existing = PosDayCloseReport::where('company_id', $companyId)
            ->where('report_date', $date)
            ->first();

        if ($existing) {
            return back()->with('error', 'Day Close Report for this date already exists.');
        }

        $transactions = PosTransaction::where('company_id', $companyId)
            ->whereDate('created_at', $date)
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        if ($transactions->isEmpty()) {
            return back()->with('error', 'No transactions found for this date.');
        }

        $reportCount = PosDayCloseReport::where('company_id', $companyId)->count();
        $reportNumber = 'ZRPT-POS-' . str_pad($reportCount + 1, 5, '0', STR_PAD_LEFT);

        $data = [
            'company_id' => $companyId,
            'report_date' => $date,
            'report_number' => $reportNumber,
            'total_invoices' => $transactions->count(),
            'pra_invoices' => $transactions->where('pra_status', 'submitted')->count(),
            'local_invoices' => $transactions->whereIn('pra_status', ['local', null])->count(),
            'offline_invoices' => $transactions->where('pra_status', 'offline')->count(),
            'gross_sales' => $transactions->sum('subtotal'),
            'total_discount' => $transactions->sum('discount_amount'),
            'net_sales' => $transactions->sum('subtotal') - $transactions->sum('discount_amount'),
            'total_tax' => $transactions->sum('tax_amount'),
            'total_amount' => $transactions->sum('total_amount'),
            'cash_amount' => $transactions->where('payment_method', 'cash')->sum('total_amount'),
            'card_amount' => $transactions->where('payment_method', 'card')->sum('total_amount'),
            'other_amount' => $transactions->whereNotIn('payment_method', ['cash', 'card'])->sum('total_amount'),
            'first_invoice_number' => $transactions->first()->invoice_number ?? null,
            'last_invoice_number' => $transactions->last()->invoice_number ?? null,
            'first_invoice_time' => $transactions->first()->created_at ?? null,
            'last_invoice_time' => $transactions->last()->created_at ?? null,
            'closed_by' => $user->id,
            'notes' => $request->input('notes'),
        ];

        $hashString = json_encode($data);
        $data['hash'] = hash('sha256', $hashString);

        PosDayCloseReport::create($data);

        return back()->with('success', 'Day Close Report ' . $reportNumber . ' generated successfully for ' . \Carbon\Carbon::parse($date)->format('d M Y'));
    }

    public function dayCloseReportPdf($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $report = PosDayCloseReport::where('company_id', $companyId)->findOrFail($id);

        $transactions = PosTransaction::where('company_id', $companyId)
            ->whereDate('created_at', $report->report_date)
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        $cashierBreakdown = $transactions->groupBy(fn($t) => $t->creator ? $t->creator->name : 'Unknown')->map(function ($group) {
            return (object) [
                'count' => $group->count(),
                'revenue' => $group->sum('total_amount'),
                'tax' => $group->sum('tax_amount'),
            ];
        });

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pos.day-close-pdf', compact('company', 'report', 'transactions', 'cashierBreakdown'));
        $pdf->setPaper('a4', 'portrait');

        return $pdf->download("Day-Close-{$report->report_number}-{$report->report_date->format('Y-m-d')}.pdf");
    }
}
