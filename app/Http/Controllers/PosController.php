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

    public function updateGuidedFlow(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->isPosCashier()) {
            return response()->json(['success' => false, 'message' => 'Only POS administrators can change this setting.'], 403);
        }
        $enabled = $request->boolean('enabled');
        $companyId = app('currentCompanyId');
        Company::where('id', $companyId)->update(['pos_guided_flow_enabled' => $enabled]);
        return response()->json(['success' => true, 'enabled' => $enabled]);
    }

    public function updateRestockToggle(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->isPosCashier()) {
            return response()->json(['success' => false, 'message' => 'Only POS administrators can change this setting.'], 403);
        }
        $enabled = $request->boolean('enabled');
        $companyId = app('currentCompanyId');
        Company::where('id', $companyId)->update(['pos_restock_on_void' => $enabled]);
        return response()->json(['success' => true, 'enabled' => $enabled]);
    }

    public function updateInventoryToggle(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->isPosCashier()) {
            return response()->json(['success' => false, 'message' => 'Only POS administrators can change this setting.'], 403);
        }
        $enabled = $request->boolean('enabled');
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if ($company) {
            // Keep the Features-wizard "Inventory Tracking" flag in lockstep
            // with the master module switch so both surfaces always agree.
            $flags = is_array($company->feature_flags) ? $company->feature_flags : [];
            $flags['inventory'] = $enabled;
            // Canonicalize so dependent children (e.g. recipes) cascade off in
            // STORED flags too — keeps the Features wizard display truthful.
            $flags = \App\Services\PosFeatureService::normalize($flags);
            $company->update(['inventory_enabled' => $enabled, 'feature_flags' => $flags]);
        }
        return response()->json(['success' => true, 'enabled' => $enabled]);
    }

    /**
     * Receipt Display Options — moved out of Business Profile into its own
     * Customize POS sub-page.
     */
    public function receiptSettings(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->isPosCashier()) {
            abort(403, 'Only POS administrators can change receipt settings.');
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company) { abort(404); }

        if ($request->isMethod('post')) {
            $request->validate(['rp_footer_text' => 'nullable|string|max:150']);
            $prefs = $company->invoice_display_prefs ?? [];
            $prefs['pos'] = [
                'show_address' => $request->has('rp_show_address'),
                'show_ntn' => $request->has('rp_show_ntn'),
                'show_email' => $request->has('rp_show_email'),
                'show_mobile' => $request->has('rp_show_mobile'),
                'show_footer' => $request->has('rp_show_footer'),
                'footer_text' => trim((string) $request->input('rp_footer_text', '')) ?: null,
            ];
            $company->update([
                'invoice_display_prefs' => $prefs,
                // Owner decision (Jul 2026): tax display toggle lives HERE (receipt
                // customization), not on the Features page. OFF = customer copy
                // shows grand TOTAL only; tax is always submitted to PRA in full.
                'pos_receipt_show_tax' => $request->has('rp_show_tax'),
            ]);
            return redirect()->route('pos.receipt-settings')->with('success', 'Receipt display settings saved.');
        }

        return view('pos.receipt-settings', compact('company'));
    }

    /**
     * Customize POS — single consolidated settings hub (admin-only).
     * Surfaces every POS customization feature from one place; complex
     * sub-features link out to their existing pages.
     */
    public function customize(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->isPosCashier()) {
            abort(403, 'Only POS administrators can customize POS.');
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company) { abort(404); }
        return view('pos.customize', compact('company'));
    }

    public function dashboard(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        // First-time POS admins are guided through the setup wizard before anything else.
        if ($this->needsPosSetup($company)) {
            return redirect()->route('pos.features', ['welcome' => 1]);
        }

        $today = now()->startOfDay();

        // Local (non-PRA) bills are excluded from ALL dashboard KPIs — they are
        // visible only in the isolated Local Bills Portal (pos_role='local_viewer').
        $excludeLocal = function ($q) {
            $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
        };
        $excludeLocalRaw = function ($q) {
            $q->where('t.invoice_mode', 'pra')->orWhereNull('t.invoice_mode');
        };

        $todayStats = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $today)
            ->where($excludeLocal)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount),0) as revenue, COALESCE(AVG(total_amount),0) as avg_ticket')
            ->first();

        $monthStats = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->startOfMonth())
            ->where($excludeLocal)
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
            ->where(function ($q) { $q->where('t.is_archived', false)->orWhereNull('t.is_archived'); })
            ->where($excludeLocalRaw)
            ->selectRaw('
                COALESCE(SUM(i.subtotal), 0) as gross_revenue,
                COALESCE(SUM(COALESCE(p.cost_price, 0) * i.quantity), 0) as total_cost
            ')->first();

        $periodOrders = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $periodStart)
            ->where($excludeLocal)
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
            ->where(function ($q) { $q->where('t.is_archived', false)->orWhereNull('t.is_archived'); })
            ->where($excludeLocalRaw)
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
            ->where(function ($q) { $q->where('t.is_archived', false)->orWhereNull('t.is_archived'); })
            ->where($excludeLocalRaw)
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
            ->where($excludeLocal)
            ->selectRaw("payment_method, COUNT(*) as count, COALESCE(SUM(total_amount),0) as total")
            ->groupBy('payment_method')
            ->get();

        // Per-cashier toggle (owner rule Jul 2026): dashboard pill shows THIS user's
        // effective reporting state, not the company-wide flag.
        $praStatus = (bool) auth('pos')->user()?->praReportingEnabled($company);

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
        $taxRules = PosTaxRule::effectiveRules($company);
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

        // First-time POS admins are guided through the setup wizard before billing.
        if ($this->needsPosSetup($company)) {
            return redirect()->route('pos.features', ['welcome' => 1]);
        }

        $features = PosFeatureService::forCompany($company);

        // Load ALL active products (including show_on_sale=false). "Hidden from sale screen"
        // products are kept OUT of the browsable grid on the client, but MUST stay loaded so
        // the cashier can still SEARCH them by name and add them to the cart at any time.
        $products = PosProduct::where('company_id', $companyId)->where('is_active', true)->get();
        $services = PosService::where('company_id', $companyId)->where('is_active', true)->get();
        $categories = $products->where('show_on_sale', true)->pluck('category')->filter()->unique()->sort()->values();
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
        $taxRate = PosTaxRule::getRateForMethod('cash', $company);
        $taxRules = PosTaxRule::effectiveRules($company);
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

    /**
     * Does this company still need to run the POS setup wizard?
     * Cashiers are NEVER trapped — only POS admins configure the POS.
     * Existing companies were backfilled pos_setup_completed=true by migration,
     * so only brand-new companies auto-launch into the wizard.
     */
    private function needsPosSetup(?Company $company): bool
    {
        $user = auth('pos')->user();
        if (!$user || $user->isPosCashier()) {
            return false;
        }
        if (!$company) {
            return false;
        }
        return !($company->pos_setup_completed ?? false);
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
        // First-time setup → wizard shows welcome banner + opens on Step 1.
        $isFirstTime = $request->boolean('welcome') || !($company->pos_setup_completed ?? false);
        // Global defaults shown as placeholders in the Sales Tax Rates card.
        $globalTaxRates = [
            'cash' => PosTaxRule::getRateForMethod('cash'),
            'card' => PosTaxRule::getRateForMethod('debit_card'),
        ];
        return view('pos.feature-settings', compact('company', 'features', 'categories', 'allFlags', 'isFirstTime', 'globalTaxRates'));
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
            'pos_tax_rate_cash' => 'nullable|numeric|min:0|max:100',
            'pos_tax_rate_card' => 'nullable|numeric|min:0|max:100',
        ]);

        $flags = [];
        foreach (PosFeatureService::ALL_FLAGS as $flag) {
            $flags[$flag] = (bool) $request->input("feature_flags.$flag", false);
        }
        // Canonicalize server-side: a child feature can't persist ON while its
        // required parent is OFF (mirrors the wizard's client-side resolveDeps).
        $flags = PosFeatureService::normalize($flags);

        $company->update([
            'business_category'    => $data['business_category'] ?? $company->business_category,
            'feature_flags'        => $flags,
            // Master inventory module switch follows the wizard's
            // "Inventory Tracking" flag so both surfaces always agree.
            'inventory_enabled'    => (bool) ($flags['inventory'] ?? false),
            'use_universal_pos'    => (bool) ($data['use_universal_pos'] ?? false),
            'pos_ui_density'       => $data['pos_ui_density'] ?? $company->pos_ui_density ?? 'standard',
            // Kitchen preferences (checkboxes — absent value = off).
            // NOTE: pos_receipt_show_tax moved to the Receipt Settings page
            // (receiptSettings) — do NOT save it here or every Features save
            // would force it off.
            'auto_print_kot'       => (bool) $request->input('auto_print_kot', false),
            'kot_reprint_enabled'  => (bool) $request->input('kot_reprint_enabled', false),
            'pos_guided_flow_enabled' => (bool) $request->input('pos_guided_flow_enabled', false),
            // Manual PRA tax-rate overrides — blank clears back to the global default.
            'pos_tax_rate_cash' => $request->filled('pos_tax_rate_cash') ? round((float) $request->input('pos_tax_rate_cash'), 2) : null,
            'pos_tax_rate_card' => $request->filled('pos_tax_rate_card') ? round((float) $request->input('pos_tax_rate_card'), 2) : null,
            // Finishing the wizard marks setup complete so it never auto-launches again.
            'pos_setup_completed'  => true,
        ]);

        // Step 3 "Start Using POS" → drop the cashier straight onto the sale screen.
        return redirect()->route('pos.invoice.create')->with('success', 'Your POS is ready — start billing!');
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
            'inventory_enabled' => (bool) ($defaults['inventory'] ?? false),
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

        $taxRate = PosTaxRule::getRateForMethod($request->payment_method, $company);
        // Round tax to nearest whole rupee — matches frontend Math.round(taxAmount).
        // Pakistan POS convention: tax + bill always whole rupees, no paisa.
        $taxAmount = (float) round($taxableAfterDiscount * $taxRate / 100);
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

        // Monthly bill quota (paid-plan package limits, Jul 2026) — FINAL bills only.
        // Provisionals stay allowed; they consume quota when promoted to final.
        if (!$saveAsProvisional) {
            $quota = \App\Services\PlanLimitService::canCreatePosBill($companyId);
            if (!($quota['allowed'] ?? true)) {
                if ($request->expectsJson()) {
                    return response()->json(['success' => false, 'error' => $quota['reason'], 'message' => $quota['reason']], 403);
                }
                return back()->withInput()->with('error', $quota['reason']);
            }
        }

        // Per-cashier toggle (owner rule Jul 2026): the ACTING user's own reporting
        // switch decides this bill's fate — never another cashier's or (once the user
        // has personally toggled) the company-wide flag.
        $praEnabled = $posUser->praReportingEnabled($company) && !$saveAsProvisional;
        if ($saveAsProvisional) {
            // Deliberate provisional — editable/deletable via F10 Local modal until promoted.
            $invoiceMode = 'local';
            $initialPraStatus = 'local';
        } elseif ($praEnabled) {
            $invoiceMode = 'pra';
            $initialPraStatus = 'pending';
        } else {
            // PRA-reporting-OFF company, FINAL sale. Must NOT be 'local' — local mode
            // hides the bill from the normal panel (transactions/KPIs/reports filter to
            // pra/NULL) and pollutes the F10 provisional modal where cashiers could
            // edit/delete a final bill. 'pra' mode + NULL pra_status = normal bill with
            // no PRA involvement (agent/failed/retry lists all key off pra_status).
            $invoiceMode = 'pra';
            $initialPraStatus = null;
        }

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
                // Serial split (owner rule Jul 2026): re-resolve the serial if the
                // reporting toggle changed between draft save and finalize. A PRA-bound
                // final must carry a POS fiscal serial (PRA must never receive an
                // L-NNN USIN), and a non-PRA bill must not hold a fiscal serial.
                $isPosSerial = str_starts_with($invoiceNumber, 'POS-');
                if ($praEnabled && !$isPosSerial) {
                    $invoiceNumber = $this->generateInvoiceNumber($companyId);
                } elseif (!$praEnabled && $isPosSerial) {
                    $invoiceNumber = $this->generateLocalInvoiceNumber($companyId);
                }
                $submissionHash = hash('sha256', $companyId . '|' . $invoiceNumber . '|' . $totalAmount . '|' . now()->timestamp);

                $transaction->update([
                    'invoice_number' => $invoiceNumber,
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
                    // Keep invoice_mode in sync with the resolved mode so a resumed
                    // draft is never orphaned from the Local / Failed UI lists.
                    'invoice_mode' => $invoiceMode,
                    'pra_status' => $initialPraStatus,
                    'submission_hash' => $submissionHash,
                    'locked_by_terminal_id' => null,
                    'lock_time' => null,
                    'notes' => $request->input('kitchen_notes'),
                ]);

                $transaction->items()->delete();
            } else {
                // Serial split (owner rule Jul 2026): POS-YYYY-NNNNN fiscal serials are
                // ONLY for bills actually reported to PRA. Provisionals AND
                // reporting-OFF finals both draw from the L-series — the fiscal
                // sequence must never be consumed by a bill PRA will never see.
                $invoiceNumber = $praEnabled
                    ? $this->generateInvoiceNumber($companyId)
                    : $this->generateLocalInvoiceNumber($companyId);
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
                    'notes' => $request->input('kitchen_notes'),
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

        $products = PosProduct::where('company_id', $companyId)->where('is_active', true)->where('show_on_sale', true)->get();
        $services = PosService::where('company_id', $companyId)->where('is_active', true)->get();
        $posCustomers = PosCustomer::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();
        $taxRules = PosTaxRule::effectiveRules($company);
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
        $transaction = PosTransaction::where('company_id', $companyId)->with('items')->findOrFail($id);

        if ($transaction->pra_invoice_number) {
            return redirect()->route('pos.transaction.show', $id)
                ->with('error', 'Cannot edit — this invoice has been submitted to PRA.');
        }

        // Snapshot the OLD line items before they are replaced, so the edit can
        // reconcile inventory (restore old qty, deduct new qty) when the owner
        // opted into restock-on-void. Captured now — items are deleted below.
        $restockOnEdit = $company && $company->inventory_enabled && $company->pos_restock_on_void;
        $oldStockItems = $restockOnEdit
            ? $transaction->items->map(fn ($i) => [
                'type' => $i->item_type ?? 'product',
                'item_id' => $i->item_id,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
            ])->all()
            : [];

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

        $taxRate = PosTaxRule::getRateForMethod($request->payment_method, $company);
        // Round tax to nearest whole rupee — matches frontend Math.round(taxAmount).
        // Pakistan POS convention: tax + bill always whole rupees, no paisa.
        $taxAmount = (float) round($taxableAfterDiscount * $taxRate / 100);
        $totalAmount = (float) round($afterDiscount + $taxAmount);

        // Per-cashier toggle (owner rule Jul 2026): the EDITING user's own switch
        // decides whether this edit re-queues the bill for PRA.
        $praEnabledEdit = (bool) auth('pos')->user()?->praReportingEnabled($company);
        $isProvisionalEdit = ($transaction->invoice_mode === 'local' && $transaction->pra_status === 'local');
        // Serial split: an L-series final re-queued for PRA must first get a real
        // fiscal serial (PRA must never receive an L-NNN USIN). A POS-serial bill
        // edited by a reporting-OFF user keeps its number (never renumber downward).
        $invoiceNumberEdit = $transaction->invoice_number;
        if (!$isProvisionalEdit && $praEnabledEdit && !str_starts_with($invoiceNumberEdit, 'POS-')) {
            $invoiceNumberEdit = $this->generateInvoiceNumber($companyId);
        }

        DB::beginTransaction();
        try {
            $transaction->update([
                'invoice_number' => $invoiceNumberEdit,
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
                // Mirror storeInvoice's three-branch invariant:
                // provisional stays provisional; PRA-on final re-queues as 'pending';
                // PRA-off final keeps NULL (never regress to 'local' — that would hide
                // it from transactions/KPIs and expose it in the F10 modal).
                'pra_status' => $isProvisionalEdit
                    ? 'local'
                    : ($praEnabledEdit ? 'pending' : null),
                'notes' => $request->input('kitchen_notes'),
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

            // Reconcile inventory for the edit: put the old quantities back, then
            // deduct the new quantities. Net effect keeps stock true to the bill
            // as it now stands (only when restock-on-void is enabled).
            if ($restockOnEdit) {
                PosInventoryController::restoreStockForInvoice(
                    $companyId, $oldStockItems, $transaction->id, $transaction->invoice_number, auth('pos')->id(), 'pos_edit'
                );
                $newStockItems = array_map(fn ($ri) => [
                    'type' => $ri['type'],
                    'item_id' => $ri['item_id'],
                    'quantity' => (float) $ri['quantity'],
                    'unit_price' => (float) $ri['price'],
                ], $companyItems);
                PosInventoryController::deductStockForInvoice(
                    $companyId, $newStockItems, $transaction->id, $transaction->invoice_number, auth('pos')->id()
                );
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Failed to update invoice: ' . $e->getMessage());
        }

        $praMessage = '';
        if ($praEnabledEdit && !$isProvisionalEdit) {
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

        // Edited from the sale screen (F10/F11 modals pass from=sale) → return the cashier
        // straight back to the sale screen instead of the transaction detail page.
        if ($request->input('from') === 'sale') {
            return redirect()->route('pos.invoice.create')
                ->with('success', 'Invoice updated successfully!' . $praMessage);
        }

        return redirect()->route('pos.transaction.show', $transaction->id)
            ->with('success', 'Invoice updated successfully!' . $praMessage);
    }

    public function deleteTransaction($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        // Cashiers may create sales and finalize provisional bills, but NEVER delete —
        // deletion is a company-admin decision (owner rule Jul 2026).
        $posUser = auth('pos')->user();
        if ($posUser && $posUser->isPosCashier()) {
            return back()->with('error', 'Aap ke paas bill delete karne ki ijazat nahi — sirf company admin bill delete kar sakta hai.');
        }

        $transaction = PosTransaction::where('company_id', $companyId)->with('items')->findOrFail($id);

        if ($transaction->pra_invoice_number) {
            return redirect()->route('pos.transaction.show', $id)
                ->with('error', 'Cannot delete — this invoice has been submitted to PRA. PRA Fiscal #: ' . $transaction->pra_invoice_number);
        }

        DB::beginTransaction();
        try {
            // Return the sold stock to inventory before the items disappear —
            // only when tracking is on AND the owner opted into restock-on-void.
            if ($company && $company->inventory_enabled && $company->pos_restock_on_void) {
                $restoreItems = $transaction->items->map(fn ($i) => [
                    'type' => $i->item_type ?? 'product',
                    'item_id' => $i->item_id,
                    'quantity' => (float) $i->quantity,
                    'unit_price' => (float) $i->unit_price,
                ])->all();
                PosInventoryController::restoreStockForInvoice(
                    $companyId, $restoreItems, $transaction->id, $transaction->invoice_number, auth('pos')->id(), 'pos_void'
                );
            }

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

    /**
     * Race-safe local→final promotion for retryPra: locks + re-verifies the bill is
     * still a genuine provisional (triple completed/local/local) INSIDE the transaction
     * before allotting the POS serial — a double-POST (or a race with
     * apiPromoteProvisional) must never renumber twice or clobber a bill already
     * queued/submitted to PRA. Returns false when the bill is no longer promotable.
     * $newPraStatus: 'pending' (reporting ON, will submit) or null (reporting OFF final).
     */
    private function promoteLocalToPosSerial(PosTransaction $transaction, int $companyId, ?string $newPraStatus): bool
    {
        try {
            DB::transaction(function () use ($transaction, $companyId, $newPraStatus) {
                $locked = PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $companyId)
                    ->where('id', $transaction->id)
                    ->lockForUpdate()
                    ->first();

                if (!$locked || $locked->pra_status !== 'local' || $locked->status !== 'completed' || $locked->invoice_mode !== 'local') {
                    throw new \RuntimeException('NOT_PROVISIONAL');
                }

                $locked->update([
                    // Serial split (owner rule Jul 2026): the L-series number changes
                    // to a real POS fiscal serial ONLY when the bill actually goes to
                    // PRA ('pending'). A reporting-OFF finalize keeps its L number —
                    // fiscal serials are reserved for PRA-reported bills.
                    'invoice_number' => $newPraStatus !== null
                        ? $this->generateInvoiceNumber($companyId)
                        : $locked->invoice_number,
                    'pra_status' => $newPraStatus,
                    'invoice_mode' => 'pra',
                    'pra_response_code' => null,
                    // Un-archive: a promoted bill is a live PRA bill and must appear
                    // on the normal POS surfaces.
                    'is_archived' => false,
                    'archived_at' => null,
                ]);
            });
        } catch (\RuntimeException $e) {
            return false;
        }

        $transaction->refresh();
        return true;
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

        // Monthly bill quota (paid-plan package limits, Jul 2026): promoting a
        // provisional here creates a NEW final — same gate as storeInvoice /
        // apiPromoteProvisional. Plain retries of already-final failed/offline/
        // pending bills are NOT re-charged (they consumed quota at creation).
        if ($transaction->pra_status === 'local') {
            $quota = \App\Services\PlanLimitService::canCreatePosBill($companyId);
            if (!($quota['allowed'] ?? true)) {
                return back()->with('error', $quota['reason']);
            }
            // MONTH GATE (owner rule Jul 2026): only CURRENT-MONTH local bills may be
            // promoted to PRA — previous months are closed (report/view only).
            if ($transaction->created_at && $transaction->created_at->lt(now()->startOfMonth())) {
                return back()->with('error', 'Sirf CURRENT month ke local bills PRA par submit ho sakte hain — pichhle month ke bills close ho chuke hain.');
            }
        }

        if (!auth('pos')->user()?->praReportingEnabled($company)) {
            // PRA-reporting-OFF user promoting a provisional → finalize WITHOUT any
            // PRA submission: 'pra' mode + NULL pra_status = normal final bill.
            if ($transaction->pra_status === 'local') {
                if (!$this->promoteLocalToPosSerial($transaction, $companyId, null)) {
                    return back()->with('error', 'Bill is no longer a provisional/local bill — refresh the page and try again.');
                }
                return back()->with('success', 'Bill ' . $transaction->invoice_number . ' is now FINAL. PRA reporting is OFF — no PRA submission needed.');
            }
            return back()->with('error', 'PRA reporting is currently disabled. Enable it from PRA Settings first.');
        }

        // Promoting a provisional bill to final — flip mode + status before submission so
        // generators / templates treat it as a real PRA invoice from this point onward.
        if ($transaction->pra_status === 'local') {
            if (!$this->promoteLocalToPosSerial($transaction, $companyId, 'pending')) {
                return back()->with('error', 'Bill is no longer a provisional/local bill — refresh the page and try again.');
            }
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

        if (!auth('pos')->user()?->praReportingEnabled($company)) {
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
        // Provisional list MUST mirror the Transactions "Local" tab definition:
        // a deliberately-saved provisional bill is a COMPLETED sale in local mode.
        // Filtering on all three keeps drafts (status='draft', invoice_mode='pra')
        // out of this list — those were polluting the F10 modal before.
        $bills = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('invoice_mode', 'local')
            ->where('pra_status', 'local')
            ->orderBy('id', 'desc')
            ->limit(100)
            ->get(['id', 'invoice_number', 'customer_name', 'total_amount', 'payment_method', 'created_at']);

        $data = $bills->map(function ($b) {
            return [
                'id'             => $b->id,
                'invoice_number' => $b->invoice_number,
                'customer_name'  => $b->customer_name,
                'total_amount'   => (float) $b->total_amount,
                'payment_method' => $b->payment_method,
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

        // Cashiers may create + finalize provisional bills, but NEVER delete —
        // deletion is a company-admin decision (owner rule Jul 2026).
        $posUser = auth('pos')->user();
        if ($posUser && $posUser->isPosCashier()) {
            return response()->json([
                'success' => false,
                'message' => 'Aap ke paas bill delete karne ki ijazat nahi — sirf company admin delete kar sakta hai.',
            ], 403);
        }

        $company = Company::find($companyId);
        $tx = PosTransaction::where('company_id', $companyId)->where('id', $id)->with('items')->first();

        if (!$tx) {
            return response()->json(['success' => false, 'message' => 'Bill not found'], 404);
        }
        if ($tx->pra_status !== 'local' || $tx->status !== 'completed' || $tx->invoice_mode !== 'local') {
            return response()->json([
                'success' => false,
                'message' => 'Only provisional (local) bills can be deleted via this endpoint.',
            ], 422);
        }

        // Provisional bills deduct stock at sale time just like finals, so the
        // F10 "Local" modal delete must restore it too — same rule as
        // deleteTransaction (only when tracking + restock-on-void are on).
        $restoreItems = ($company && $company->inventory_enabled && $company->pos_restock_on_void)
            ? $tx->items->map(fn ($i) => [
                'type' => $i->item_type ?? 'product',
                'item_id' => $i->item_id,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
            ])->all()
            : [];

        DB::transaction(function () use ($tx, $companyId, $restoreItems) {
            if (!empty($restoreItems)) {
                PosInventoryController::restoreStockForInvoice(
                    $companyId, $restoreItems, $tx->id, $tx->invoice_number, auth('pos')->id(), 'pos_void'
                );
            }
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

        if (!$company) {
            return response()->json(['success' => false, 'message' => 'Company not found'], 404);
        }

        // ── LOCAL FINAL (owner request Jul 2026): finalize WITHOUT sending to PRA ──
        // The bill keeps its local invoice number, amounts and payment method exactly
        // as saved, and is archived immediately — it leaves the F10 provisional list
        // but stays visible in the Local Bills Portal (which reads archived bills).
        // pra_status stays 'local' + invoice_mode stays 'local' (deliberate local bill,
        // consistent with the Reporting-OFF Finals Invariant).
        if (!$request->boolean('send_to_pra', true)) {
            $tx = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('id', $id)
                ->where('status', 'completed')
                ->where('invoice_mode', 'local')
                ->where('pra_status', 'local')
                ->first();
            if (!$tx) {
                return response()->json(['success' => false, 'message' => 'Bill not found'], 404);
            }
            $tx->update(['is_archived' => true, 'archived_at' => now()]);
            return response()->json([
                'success'        => true,
                'submitted'      => false,
                'local_final'    => true,
                'invoice_number' => $tx->invoice_number,
                'total_amount'   => (float) $tx->total_amount,
                'message'        => '✓ Bill ' . $tx->invoice_number . ' finalized as LOCAL (Rs. ' . number_format((float) $tx->total_amount) . ') — NOT sent to PRA.',
                'id'             => $tx->id,
            ]);
        }

        // Cashier picks the settlement method AT promote time — cash vs card carry
        // different PRA tax rates (e.g. 16% vs 8%), so the bill is RE-TAXED for the
        // chosen method. Falls back to the stored method when none is supplied.
        $method = $request->input('payment_method');
        if ($method === 'card') {
            $method = 'debit_card';
        }
        if (!in_array($method, ['cash', 'debit_card', 'credit_card', 'qr_payment'], true)) {
            $method = null; // resolve from the stored value inside the transaction
        }

        // Promoting a provisional to a FINAL bill consumes monthly quota — same
        // gate as storeInvoice finals (paid-plan package limits, Jul 2026).
        $quota = \App\Services\PlanLimitService::canCreatePosBill($companyId);
        if (!($quota['allowed'] ?? true)) {
            return response()->json(['success' => false, 'message' => $quota['reason']], 403);
        }

        // Per-cashier toggle (owner rule Jul 2026): the promoting user's own switch decides.
        $reportingOn = (bool) auth('pos')->user()?->praReportingEnabled($company);
        $newNumber = null;
        $newTotal  = null;

        try {
            DB::transaction(function () use ($companyId, $company, $id, $method, $reportingOn, &$newNumber, &$newTotal) {
                // Race-safe: lock the row and re-verify it is still a genuine provisional.
                // The F10 modal fires promote on Enter, so double-Enter is a real
                // double-promote path — the lock + re-check closes it.
                // hide_archived bypassed: archived local bills (local finals / day-close
                // archived) are promotable from the Local Invoices report too.
                $tx = PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $companyId)
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (!$tx) {
                    throw new \RuntimeException('NOT_FOUND');
                }
                if ($tx->pra_status !== 'local' || $tx->status !== 'completed' || $tx->invoice_mode !== 'local') {
                    throw new \RuntimeException('NOT_PROVISIONAL:' . $tx->status . '/' . $tx->pra_status);
                }

                // ARCHIVED local bills (deliberate LOCAL FINALS / day-close archived)
                // are promotable ONLY by admins — cashiers use F10 for LIVE provisionals
                // and must not resurrect a bill the owner finalized as local.
                if ($tx->is_archived && !(auth('pos')->user()?->isPosAdmin())) {
                    throw new \RuntimeException('ARCHIVED_ADMIN_ONLY');
                }

                // MONTH GATE (owner rule Jul 2026): only CURRENT-MONTH local bills may
                // be promoted to PRA. Once the month changes, previous-month locals are
                // closed — view/report only, never submitted late to PRA.
                if ($tx->created_at && $tx->created_at->lt(now()->startOfMonth())) {
                    throw new \RuntimeException('MONTH_CLOSED');
                }

                $payMethod = $method ?? $tx->payment_method;

                // Recompute tax from the STORED bill for the chosen method — mirrors
                // storeInvoice (stored subtotal, absolute discount, non-exempt lines).
                $items = $tx->items()->get();
                $subtotal = (float) $tx->subtotal;
                $discountAmount = (float) $tx->discount_amount;
                $afterDiscount = $subtotal - $discountAmount;
                $taxableSubtotal = (float) $items->reject(fn($it) => (bool) $it->is_tax_exempt)->sum('subtotal');
                $taxableAfterDiscount = $subtotal > 0 ? round($taxableSubtotal / $subtotal * $afterDiscount, 2) : 0;
                $exemptAfterDiscount = round($afterDiscount - $taxableAfterDiscount, 2);

                $taxRate = PosTaxRule::getRateForMethod($payMethod, $company);
                // Whole-rupee tax + total (Pakistan POS convention) — item lines stay 2dp.
                $taxAmount = (float) round($taxableAfterDiscount * $taxRate / 100);
                $totalAmount = (float) round($afterDiscount + $taxAmount);

                // Serial split (owner rule Jul 2026): a real POS fiscal serial replaces
                // the provisional L-NNN ONLY when the bill actually goes to PRA
                // (reporting ON). Reporting-OFF finalize keeps the L number — fiscal
                // serials are reserved for PRA-reported bills. generateInvoiceNumber's
                // lockForUpdate is effective inside this transaction.
                $newInvoiceNumber = $reportingOn
                    ? $this->generateInvoiceNumber($companyId)
                    : $tx->invoice_number;
                $newHash = hash('sha256', $companyId . '|' . $newInvoiceNumber . '|' . $totalAmount . '|' . now()->timestamp);

                $tx->update([
                    'invoice_number'    => $newInvoiceNumber,
                    'payment_method'    => $payMethod,
                    'tax_rate'          => $taxRate,
                    'tax_amount'        => $taxAmount,
                    'exempt_amount'     => $exemptAfterDiscount,
                    'total_amount'      => $totalAmount,
                    'cash_received'     => $payMethod === 'cash' ? $totalAmount : null,
                    'change_due'        => null,
                    'submission_hash'   => $newHash,
                    // Finalize out of 'local'. Reporting-OFF = 'pra' mode + NULL status
                    // (normal final, no submission). Reporting-ON = 'pending' for submit.
                    'invoice_mode'      => 'pra',
                    'pra_status'        => $reportingOn ? 'pending' : null,
                    'pra_response_code' => null,
                    // Un-archive: a promoted bill is a live PRA bill and must appear
                    // on the normal POS surfaces (archived local finals included).
                    'is_archived'       => false,
                    'archived_at'       => null,
                ]);

                // Re-tax each line for the new rate — the PRA payload reads per-item tax_rate.
                foreach ($items as $it) {
                    $itemTaxRate = (bool) $it->is_tax_exempt ? 0 : $taxRate;
                    $itemDiscountShare = $subtotal > 0 ? round($discountAmount * ((float) $it->subtotal / $subtotal), 2) : 0;
                    $itemTaxableAmount = (float) $it->subtotal - $itemDiscountShare;
                    $itemTaxAmount = round($itemTaxableAmount * $itemTaxRate / 100, 2);
                    $it->update([
                        'tax_rate'   => $itemTaxRate,
                        'tax_amount' => $itemTaxAmount,
                    ]);
                }

                // Sync the payment record — restaurant-origin provisionals may have none,
                // so updateOrCreate (a plain update would silently no-op).
                PosPayment::updateOrCreate(
                    ['transaction_id' => $tx->id],
                    ['payment_method' => $payMethod, 'amount' => $totalAmount]
                );

                $newNumber = $newInvoiceNumber;
                $newTotal  = $totalAmount;
            });
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if ($msg === 'NOT_FOUND') {
                return response()->json(['success' => false, 'message' => 'Bill not found'], 404);
            }
            if ($msg === 'MONTH_CLOSED') {
                return response()->json([
                    'success' => false,
                    'message' => 'Sirf CURRENT month ke local bills PRA par submit ho sakte hain — pichhle month ke bills close ho chuke hain (sirf report mein dekhe ja sakte hain).',
                ], 422);
            }
            if ($msg === 'ARCHIVED_ADMIN_ONLY') {
                return response()->json([
                    'success' => false,
                    'message' => 'Ye bill LOCAL FINAL ho chuka hai — sirf company admin ise PRA par submit kar sakta hai.',
                ], 403);
            }
            if (str_starts_with($msg, 'NOT_PROVISIONAL:')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only completed provisional (local) bills can be promoted. Current status: ' . substr($msg, 16),
                ], 422);
            }
            return response()->json(['success' => false, 'message' => 'Promote failed: ' . $msg], 500);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Promote failed: ' . $e->getMessage()], 500);
        }

        // ── Post-commit: PRA submission happens STRICTLY outside the transaction ──
        if (!$reportingOn) {
            return response()->json([
                'success'        => true,
                'submitted'      => false,
                'invoice_number' => $newNumber,
                'total_amount'   => $newTotal,
                'message'        => '✓ Bill ' . $newNumber . ' is now FINAL (Rs. ' . number_format($newTotal) . ') — PRA reporting is OFF, no submission needed.',
                'id'             => $id,
            ]);
        }

        $tx = PosTransaction::where('company_id', $companyId)->where('id', $id)->first();

        // Agent-enabled: just leave it queued — desktop agent picks it up within 10s.
        if ($company->agent_enabled) {
            return response()->json([
                'success'        => true,
                'queued'         => true,
                'invoice_number' => $newNumber,
                'total_amount'   => $newTotal,
                'message'        => '🟡 Bill ' . $newNumber . ' re-queued for desktop agent — will sync within seconds.',
                'id'             => $id,
            ]);
        }

        try {
            $praService = new PraIntegrationService($company);
            $result = $praService->sendInvoice($tx);
            $tx->refresh();

            if (!empty($result['success'])) {
                return response()->json([
                    'success'        => true,
                    'submitted'      => true,
                    'invoice_number' => $newNumber,
                    'total_amount'   => $newTotal,
                    'message'        => 'PRA submission successful! PRA Fiscal Invoice Number: ' . ($tx->pra_invoice_number ?? 'N/A'),
                    'pra_number'     => $tx->pra_invoice_number,
                    'id'             => $id,
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

        if (!$company || !auth('pos')->user()?->praReportingEnabled($company)) {
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
        // Local bills are ONLY visible in the isolated Local Bills Portal
        // (pos_role='local_viewer'). Normal surfaces always show PRA-mode bills.
        $tab = 'pra';

        $query = PosTransaction::where('company_id', $companyId)->where('status', 'completed')->with('creator');

        $query->where(function ($q) {
            $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
        });

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
        $localCount = 0;
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
        // withoutGlobalScope: a bill finalized as LOCAL (no PRA) is archived on the
        // spot — its receipt must still render for the post-finalize popup/print.
        // Bypass is limited to LOCAL-mode bills so archived PRA bills stay hidden
        // (preserves the "nothing sees archived bills" invariant for PRA data).
        $transaction = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where(function ($q) {
                if (\Schema::hasColumn('pos_transactions', 'is_archived')) {
                    $q->where('is_archived', false)
                      ->orWhereNull('is_archived')
                      ->orWhere('invoice_mode', 'local');
                }
            })
            ->with(['items', 'payments', 'creator', 'terminal'])
            ->findOrFail($id);

        $printerSize = $company->receipt_printer_size ?? '80mm';
        $receiptView = $printerSize === '58mm' ? 'pos.receipts.receipt_58mm' : 'pos.receipts.receipt_80mm';

        return view($receiptView, compact('transaction', 'company'));
    }

    /**
     * Estimate the page height (in points) for a thermal receipt PDF.
     *
     * DomPDF ignores the receipt CSS `@page { size: 80mm auto }`, so we MUST pass an
     * explicit height to setPaper(). The old hard-coded 1200pt truncated any receipt
     * taller than that — the cashier reported the downloaded slip "poori nahi hoti"
     * (cut off). We size the page to its content and over-estimate slightly: trailing
     * whitespace on a thermal roll is harmless, a clipped receipt is not.
     */
    private function estimateReceiptHeightPt($transaction, $company, string $printerSize): float
    {
        $charsPerLine = $printerSize === '58mm' ? 12 : 18; // monospace chars fitting the Item column
        $perLine = 22.0;                                    // pt consumed per (wrapped) item line

        $itemLines = 0;
        foreach ($transaction->items as $it) {
            $len = mb_strlen((string) ($it->item_name ?? ''));
            $itemLines += max(1, (int) ceil($len / max(1, $charsPerLine)));
        }

        $height  = 490.0;                                          // header + info + totals + footer chrome (+ header-field wrap headroom)
        $height += ($company && $company->logo_path) ? 70.0 : 0.0; // logo block
        $height += $itemLines * $perLine;                          // item rows
        $height += ($transaction->discount_amount > 0) ? 26.0 : 0.0;
        // Notes wrap to several lines on a narrow thermal roll — scale by length, never assume one line.
        if (!empty($transaction->notes)) {
            $noteCharsPerLine = $printerSize === '58mm' ? 28 : 40;
            $noteLines = max(1, (int) ceil(mb_strlen((string) $transaction->notes) / $noteCharsPerLine));
            $height += 20.0 + ($noteLines * 14.0);
        }
        $height += 250.0;                                          // PRA/provisional badge + 100px QR + caption
        $height += 80.0;                                           // safety tail so nothing clips

        return max(640.0, $height);
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

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($receiptView, ['transaction' => $transaction, 'company' => $company, 'pdfMode' => true])
            ->setOption('isRemoteEnabled', true)
            ->setOption('isHtml5ParserEnabled', true);
        $pdf->setPaper([0, 0, $paperWidthPt, $this->estimateReceiptHeightPt($transaction, $company, $printerSize)], 'portrait');

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

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($receiptView, ['transaction' => $transaction, 'company' => $company, 'pdfMode' => true])
            ->setOption('isRemoteEnabled', true)
            ->setOption('isHtml5ParserEnabled', true);
        $pdf->setPaper([0, 0, $paperWidthPt, $this->estimateReceiptHeightPt($transaction, $company, $printerSize)], 'portrait');

        return $pdf->stream("Invoice-{$transaction->invoice_number}.pdf");
    }

    private function applyReportFilters($query, $tab, $cashierFilter = null)
    {
        // Two FULLY ISOLATED report sets (owner rule Jul 2026) — split by whether the
        // bill was actually REPORTED to PRA (fiscal), not just by invoice_mode:
        //   tab='pra'   → bills in the PRA pipeline: pra_status NOT NULL (pending /
        //                 completed / failed / offline) OR a PRA fiscal number exists.
        //   tab='local' → bills PRA never saw: L-series (invoice_mode='local') PLUS
        //                 reporting-OFF finals (mode pra/NULL + pra_status NULL + no
        //                 fiscal number — "jis pe PRA fiscal nahi aya wo local hai").
        //                 INCLUDING archived ones, so hide_archived is bypassed.
        //                 Admin-only (callers force 'pra' for cashiers).
        if ($tab === 'local') {
            $query->withoutGlobalScope('hide_archived')->where(function ($sub) {
                $sub->where('invoice_mode', 'local')
                    ->orWhere(function ($s) {
                        $s->whereNull('pra_status')->whereNull('pra_invoice_number');
                    });
            });
        } else {
            $query->where(function ($sub) {
                $sub->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })->where(function ($sub) {
                $sub->whereNotNull('pra_status')->orWhereNotNull('pra_invoice_number');
            });
        }

        if ($cashierFilter && $cashierFilter !== 'all') {
            $query->where('created_by', $cashierFilter);
        }

        return $query;
    }

    /**
     * Tax-rate options for the report filter dropdown — DYNAMIC, never hardcoded:
     *   1. every distinct item-level rate that actually exists in THIS tab's data
     *      (historical bills keep old rates filterable), and
     *   2. the currently CONFIGURED effective rates (global pos_tax_rules + the
     *      company's overrides), so an updated rate appears immediately.
     * Computed PER TAB so PRA and Local rate sets stay fully isolated.
     */
    private function availableTaxRates(int $companyId, string $tab, ?Company $company): array
    {
        $txQuery = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->select('id');
        $this->applyReportFilters($txQuery, $tab);

        $dataRates = \App\Models\PosTransactionItem::whereIn('transaction_id', $txQuery)
            ->where('is_tax_exempt', false)
            ->where('tax_rate', '>', 0)
            ->distinct()
            ->pluck('tax_rate');

        $configuredRates = PosTaxRule::effectiveRules($company)->pluck('tax_rate');

        return $dataRates->concat($configuredRates)
            ->map(fn ($r) => round((float) $r, 2))
            ->filter(fn ($r) => $r > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function reports(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';
        // Local Invoices tab is ADMIN-ONLY — cashiers are always forced to PRA.
        $tab = ($request->get('tab') === 'local' && $user?->isPosAdmin()) ? 'local' : 'pra';
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
        $localCount = 0;
        $selectedCashier = $cashierFilter;

        // Local tab: list the individual local invoices so the admin can promote
        // any CURRENT-MONTH bill to PRA (previous months are closed — view only).
        $localBills = null;
        $monthStart = now()->startOfMonth();
        if ($tab === 'local') {
            $localBills = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->where('status', 'completed')
                // Same non-reported set as applyReportFilters: L-series bills PLUS
                // reporting-OFF finals (no PRA fiscal ever).
                ->where(function ($sub) {
                    $sub->where('invoice_mode', 'local')
                        ->orWhere(function ($s) {
                            $s->whereNull('pra_status')->whereNull('pra_invoice_number');
                        });
                })
                ->when($cashierFilter && $cashierFilter !== 'all', fn ($q) => $q->where('created_by', $cashierFilter))
                ->with('creator')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString();
        }

        return view('pos.reports', compact('dailySales', 'paymentSummary', 'topItems', 'monthlyTrend', 'tab', 'hasPinSet', 'localCount', 'user', 'teamMembers', 'isCashier', 'selectedCashier', 'localBills', 'monthStart'));
    }

    public function exportReportCsv(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $user = auth('pos')->user();
        $isCashier = ($user->pos_role ?? 'pos_admin') === 'pos_cashier';
        // Local export is ADMIN-ONLY — cashiers always export PRA data.
        $tab = ($request->get('tab') === 'local' && $user?->isPosAdmin()) ? 'local' : 'pra';
        $cashierFilter = $request->get('cashier', 'all');

        if ($isCashier && $cashierFilter !== 'all' && $cashierFilter != $user->id) {
            $cashierFilter = $user->id;
        }

        $transactions = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(30))
            ->tap(fn ($q) => $this->applyReportFilters($q, $tab))
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

        // Isolated tab sets: PRA (default) vs Local (admin-only, callers gate).
        $this->applyReportFilters($query, $tab);

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
        // Local Invoices tab is ADMIN-ONLY — cashiers are always forced to PRA.
        $tab = ($request->get('tab') === 'local' && auth('pos')->user()?->isPosAdmin()) ? 'local' : 'pra';

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
        $localCount = 0;
        $user = auth('pos')->user();
        $availableRates = $this->availableTaxRates($companyId, $tab, $company);

        return view('pos.tax-reports', compact('company', 'transactions', 'summary', 'dateLabel', 'taxRateLabel', 'tab', 'hasPinSet', 'localCount', 'user', 'itemValues', 'taxRateFilter', 'availableRates'));
    }

    public function exportTaxReportCsv(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        // Local export is ADMIN-ONLY — cashiers always export PRA data.
        $tab = ($request->get('tab') === 'local' && auth('pos')->user()?->isPosAdmin()) ? 'local' : 'pra';

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
        // Local export is ADMIN-ONLY — cashiers always export PRA data.
        $tab = ($request->get('tab') === 'local' && auth('pos')->user()?->isPosAdmin()) ? 'local' : 'pra';

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
        $company = Company::find(app('currentCompanyId'));
        $rate = PosTaxRule::getRateForMethod($method, $company);
        return response()->json(['tax_rate' => $rate]);
    }

    /**
     * Standalone → PRA upgrade: flips the company onto the PRA-integrated
     * edition (plans + PRA settings become relevant). One-way from the UI —
     * the reverse (PRA → standalone) is an admin decision, not self-serve.
     */
    public function enablePraIntegration(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        // PRA integration submits your NTN with every fiscal invoice — require it on file
        // first. NTN is optional at registration; the company adds it here in the profile.
        if (empty($company->ntn)) {
            return redirect()->route('pos.business-profile')
                ->with('error', 'PRA integration se pehle apna NTN (National Tax Number) Business Profile mein daalein.');
        }

        if (($company->pos_integration_mode ?? 'pra') === 'standalone') {
            $company->pos_integration_mode = 'pra';
            $company->save();
        }

        return redirect()->route('pos.pra-settings')->with('success', 'PRA integration enabled — configure your PRA credentials below, then turn on PRA Reporting when ready.');
    }

    public function togglePra(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        // Standalone edition has ZERO government integration — PRA reporting can
        // never be flipped on (sales would queue for a submission that must fail).
        // The sale-screen toggle is hidden for standalone; this guards direct POSTs.
        if (($company->pos_integration_mode ?? 'pra') === 'standalone') {
            return response()->json([
                'success' => false,
                'enabled' => false,
                'message' => 'PRA Reporting is not available on the Standalone edition. Enable PRA Integration from PRA Settings first.',
            ], 422);
        }

        // Per-cashier toggle (owner rule Jul 2026): flip ONLY the acting user's own
        // switch — the company-wide flag stays untouched, so one cashier turning
        // reporting on/off NEVER affects another cashier or the admin.
        $togglingUser = auth('pos')->user();
        $effectiveNow = $togglingUser->praReportingEnabled($company);

        // Turning PRA Reporting ON requires an NTN on file (submitted with every fiscal
        // invoice). Turning it OFF is always allowed. NTN is optional at registration.
        if (!$effectiveNow && empty($company->ntn)) {
            return response()->json([
                'success' => false,
                'enabled' => false,
                'message' => 'PRA Reporting on karne se pehle apna NTN Business Profile mein daalein.',
            ], 422);
        }

        $togglingUser->pra_reporting_enabled = !$effectiveNow;
        $togglingUser->save();

        return response()->json([
            'success' => true,
            'enabled' => (bool) $togglingUser->pra_reporting_enabled,
            'message' => $togglingUser->pra_reporting_enabled
                ? 'PRA Reporting enabled (sirf aap ke apne bills ke liye)'
                : 'PRA Reporting disabled (sirf aap ke apne bills ke liye)',
        ]);
    }

    /**
     * Customize POS → Local Bills — persist "auto-archive local bills on day-close".
     * When ON, EVERY day-close (manual or the midnight auto command) archives that day's
     * local/provisional bills to the Archive Portal. Rows are kept, never deleted.
     */
    public function toggleAutoPurgeLocal(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->isPosCashier()) {
            return response()->json(['success' => false, 'message' => 'Only POS administrators can change this setting.'], 403);
        }
        $company = Company::find(app('currentCompanyId'));
        $company->pos_auto_purge_local_on_dayclose = $request->boolean('enabled');
        $company->save();

        return response()->json([
            'success' => true,
            'enabled' => (bool) $company->pos_auto_purge_local_on_dayclose,
            'message' => $company->pos_auto_purge_local_on_dayclose ? 'Auto-archive on day-close enabled' : 'Auto-archive on day-close disabled',
        ]);
    }

    /**
     * Customize POS → Local Bills — persist "auto day-close at midnight".
     * When ON, the scheduled pos:auto-dayclose command closes any un-closed prior day
     * at the second midnight after it (1 full day grace — yesterday stays open;
     * see routes/console.php).
     */
    public function toggleAutoDayclose(Request $request)
    {
        $user = auth('pos')->user();
        if (!$user || $user->isPosCashier()) {
            return response()->json(['success' => false, 'message' => 'Only POS administrators can change this setting.'], 403);
        }
        $company = Company::find(app('currentCompanyId'));
        $company->pos_auto_dayclose_24h = $request->boolean('enabled');
        $company->save();

        return response()->json([
            'success' => true,
            'enabled' => (bool) $company->pos_auto_dayclose_24h,
            'message' => $company->pos_auto_dayclose_24h ? 'Auto day-close enabled' : 'Auto day-close disabled',
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

        $features = \App\Services\PosFeatureService::forCompany($company);
        if (!$features->kot) {
            return response()->json([
                'success' => false,
                'message' => 'Auto-KOT requires the KOT feature to be enabled (Customize POS → Modules).',
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
                'pra_connection_mode' => 'nullable|in:cloud,fiscal_device',
                'pra_pos_id' => 'nullable|string',
                'pra_production_token' => 'nullable|string',
                'pra_proxy_url' => 'nullable|url',
                'receipt_printer_size' => 'nullable|in:80mm,58mm',
            ]);

            $updateData = [
                'pra_environment' => $request->pra_environment,
                'receipt_printer_size' => $request->receipt_printer_size ?? '80mm',
            ];

            if ($request->filled('pra_connection_mode')) {
                $updateData['pra_connection_mode'] = $request->pra_connection_mode;

                // Fiscal Device submissions only happen from the shop PC — the desktop agent is mandatory.
                if ($request->pra_connection_mode === 'fiscal_device') {
                    $updateData['agent_enabled'] = true;
                    if (empty($company->agent_api_key)) {
                        $updateData['agent_api_key'] = 'tnk_' . \Illuminate\Support\Str::random(48);
                    }
                }
            }

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

        // Team-account quota (paid-plan package limits, Jul 2026):
        // Starter 1 (admin only), Business 5, Pro unlimited.
        $quota = \App\Services\PlanLimitService::canAddPosUser($companyId);
        if (!($quota['allowed'] ?? true)) {
            return back()->with('error', $quota['reason']);
        }

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

        // Reactivating a cashier re-consumes a team-account slot — same gate as
        // storeCashier, otherwise deactivate→create→reactivate bypasses the limit.
        if (!$cashier->is_active) {
            $quota = \App\Services\PlanLimitService::canAddPosUser($companyId);
            if (!($quota['allowed'] ?? true)) {
                return back()->with('error', $quota['reason']);
            }
        }

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
        // For unified Product+Recipe modal: existing ingredients dropdown source
        $ingredients = \App\Models\Ingredient::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'cost_per_unit']);
        // Existing-recipe quick-copy source: products in this company that already have a recipe.
        // Cashier can pick one to auto-populate ingredient rows (then tweak names/qty).
        $existingRecipes = [];
        if (class_exists(\App\Models\ProductRecipe::class)) {
            $recipeRows = \App\Models\ProductRecipe::where('company_id', $companyId)
                ->with(['product:id,name', 'ingredient:id,name,unit,cost_per_unit'])
                ->get();
            $grouped = $recipeRows->groupBy('product_id');
            foreach ($grouped as $productId => $rows) {
                $prodName = optional($rows->first()->product)->name;
                if (!$prodName) continue;
                $items = [];
                foreach ($rows as $r) {
                    if (!$r->ingredient) continue;
                    $items[] = [
                        'ingredient_id'   => (int) $r->ingredient_id,
                        'name'            => $r->ingredient->name,
                        'unit'            => $r->ingredient->unit,
                        'quantity_needed' => (float) $r->quantity_needed,
                    ];
                }
                if (!empty($items)) {
                    $existingRecipes[] = [
                        'product_id'   => (int) $productId,
                        'product_name' => $prodName,
                        'ingredients'  => $items,
                    ];
                }
            }
            usort($existingRecipes, fn($a, $b) => strcasecmp($a['product_name'], $b['product_name']));
        }
        return view('pos.products', compact('products', 'posType', 'categoryFields', 'ingredients', 'existingRecipes'));
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
            'stock_quantity' => 'nullable|integer|min:0',
            'low_stock_threshold' => 'nullable|integer|min:0',
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
            'stock_quantity' => $request->filled('stock_quantity') ? (int) $request->stock_quantity : null,
            'low_stock_threshold' => $request->filled('low_stock_threshold') ? (int) $request->low_stock_threshold : 10,
            // Backend hardening: exempt MUST persist tax_rate=0 regardless of what (if anything) UI submitted
            'tax_rate' => $isExempt ? 0 : ($request->tax_rate ?? 0),
            'category' => $request->category,
            'sku' => $request->sku,
            'barcode' => $request->barcode,
            'uom' => $request->uom ?? 'NOS',
            'is_tax_exempt' => $isExempt,
            'show_on_sale' => $request->has('show_on_sale'),
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

        // ═══ Unified Product + Recipe (Single Box) ═══
        // Wraps PosProduct creation AND optional recipe rows in one transaction
        // so a failed ingredient/recipe write rolls back the product too — no orphans.
        // Tracks counts so the cashier sees exactly what landed and what was skipped.
        $recipeAdded = 0;
        $recipeSkipped = 0;
        $product = \DB::transaction(function () use ($data, $request, $companyId, &$recipeAdded, &$recipeSkipped) {
            $product = PosProduct::create($data);

            // Inventory mirror seed: when the inventory module is ON and the
            // cashier gave an opening stock, create the authoritative
            // inventory_stocks row (+ opening movement) so the module and the
            // products page start in sync instead of the module seeing 0.
            $companyRow = \App\Models\Company::find($companyId);
            if ($companyRow && $companyRow->inventory_enabled && $product->stock_quantity !== null) {
                $stockRow = \App\Models\InventoryStock::firstOrCreate(
                    ['company_id' => $companyId, 'product_id' => $product->id, 'branch_id' => null],
                    [
                        'quantity' => (float) $product->stock_quantity,
                        'min_stock_level' => (float) ($product->low_stock_threshold ?? 0),
                        'avg_purchase_price' => (float) ($product->cost_price ?? 0),
                        'last_purchase_price' => (float) ($product->cost_price ?? 0),
                    ]
                );
                if ($stockRow->wasRecentlyCreated && $product->stock_quantity > 0) {
                    \App\Models\InventoryMovement::create([
                        'company_id' => $companyId,
                        'product_id' => $product->id,
                        'type' => \App\Models\InventoryMovement::TYPE_OPENING,
                        'quantity' => (float) $product->stock_quantity,
                        'balance_after' => (float) $product->stock_quantity,
                        'reference_type' => 'product_create',
                        'notes' => 'Opening stock from product creation',
                        'created_by' => auth('pos')->id(),
                    ]);
                }
            }

            // Prefer JSON payload (robust to Alpine template-nesting); fall back to array form fields.
            $ingredientRows = [];
            if ($request->filled('ingredients_json')) {
                $decoded = json_decode((string) $request->input('ingredients_json'), true);
                if (is_array($decoded)) $ingredientRows = $decoded;
            } elseif ($request->has('ingredients') && is_array($request->input('ingredients'))) {
                $ingredientRows = $request->input('ingredients');
            }

            if (!empty($ingredientRows)) {
                foreach ($ingredientRows as $row) {
                    if (!is_array($row)) continue;
                    $qty = $row['quantity_needed'] ?? null;
                    if ($qty === null || $qty === '' || !is_numeric($qty) || (float)$qty <= 0) {
                        // Only count as skipped if the row had any meaningful intent
                        if (!empty($row['ingredient_id']) || !empty($row['new_name'])) $recipeSkipped++;
                        continue;
                    }

                    $ingredient = null;
                    if (!empty($row['ingredient_id'])) {
                        $ingredient = \App\Models\Ingredient::where('company_id', $companyId)
                            ->where('id', $row['ingredient_id'])->first();
                    } elseif (!empty($row['new_name']) && !empty($row['new_unit'])) {
                        $name = trim($row['new_name']);
                        $unit = trim($row['new_unit']);
                        // Reuse if same name+unit already exists (case-insensitive) to avoid dupes
                        $ingredient = \App\Models\Ingredient::where('company_id', $companyId)
                            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                            ->where('unit', $unit)
                            ->first();
                        if (!$ingredient) {
                            $ingredient = \App\Models\Ingredient::create([
                                'company_id' => $companyId,
                                'name' => $name,
                                'unit' => $unit,
                                'cost_per_unit' => isset($row['new_cost']) && is_numeric($row['new_cost']) ? (float)$row['new_cost'] : 0,
                                'current_stock' => 0,
                                'min_stock_level' => 0,
                                'is_active' => true,
                            ]);
                        }
                    }
                    if (!$ingredient) { $recipeSkipped++; continue; }

                    // Avoid duplicate (product, ingredient) pair
                    $exists = \App\Models\ProductRecipe::where('company_id', $companyId)
                        ->where('product_id', $product->id)
                        ->where('ingredient_id', $ingredient->id)
                        ->exists();
                    if ($exists) { $recipeSkipped++; continue; }

                    \App\Models\ProductRecipe::create([
                        'company_id' => $companyId,
                        'product_id' => $product->id,
                        'ingredient_id' => $ingredient->id,
                        'quantity_needed' => (float)$qty,
                    ]);
                    $recipeAdded++;
                }
            }
            return $product;
        });

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

        // Build feedback message with recipe context so cashier sees exactly what landed
        $msg = 'Product added successfully.';
        if ($recipeAdded > 0) {
            $msg .= " Recipe: {$recipeAdded} ingredient" . ($recipeAdded === 1 ? '' : 's') . " linked.";
        }
        if ($recipeSkipped > 0) {
            $msg .= " ({$recipeSkipped} recipe row" . ($recipeSkipped === 1 ? '' : 's') . " skipped — missing qty or duplicate.)";
        }
        return back()->with('success', $msg);
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
            'stock_quantity' => 'nullable|integer|min:0',
            'low_stock_threshold' => 'nullable|integer|min:0',
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
                'stock_quantity' => $request->filled('stock_quantity') ? (int) $request->stock_quantity : null,
                'low_stock_threshold' => $request->filled('low_stock_threshold') ? (int) $request->low_stock_threshold : ($product->low_stock_threshold ?? 10),
                // Backend hardening: exempt MUST force tax_rate=0; otherwise honor submitted value (or keep current if absent)
                'tax_rate' => $isExempt ? 0 : ($request->has('tax_rate') ? $request->tax_rate : $product->tax_rate),
                'is_tax_exempt' => $isExempt,
                'show_on_sale' => $request->has('show_on_sale'),
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

        // Inventory mirror sync on edit: apply an explicit stock_quantity change
        // as a 'set' adjustment on the inventory module (movement + audit row),
        // never a blind overwrite. Runs only when the module is ON and the
        // submitted value actually differs from the current one.
        $companyRow = \App\Models\Company::find($companyId);
        $oldStockQty = $product->stock_quantity;
        $newStockQty = $data['stock_quantity'];
        if (
            $companyRow && $companyRow->inventory_enabled
            && $newStockQty !== null && (int) $newStockQty !== (int) ($oldStockQty ?? PHP_INT_MIN)
        ) {
            \DB::transaction(function () use ($companyId, $product, $newStockQty) {
                $stockRow = \App\Models\InventoryStock::firstOrCreate(
                    ['company_id' => $companyId, 'product_id' => $product->id, 'branch_id' => null],
                    ['quantity' => 0, 'min_stock_level' => 0, 'avg_purchase_price' => 0, 'last_purchase_price' => 0]
                );
                $prevQty = (float) $stockRow->quantity;
                $setQty = (float) $newStockQty;
                if ($prevQty !== $setQty) {
                    $stockRow->update(['quantity' => $setQty]);
                    \App\Models\InventoryMovement::create([
                        'company_id' => $companyId,
                        'product_id' => $product->id,
                        'type' => $setQty > $prevQty
                            ? \App\Models\InventoryMovement::TYPE_ADJUSTMENT_IN
                            : \App\Models\InventoryMovement::TYPE_ADJUSTMENT_OUT,
                        'quantity' => abs($setQty - $prevQty),
                        'balance_after' => $setQty,
                        'reference_type' => 'adjustment',
                        'notes' => 'Stock set via product edit',
                        'created_by' => auth('pos')->id(),
                    ]);
                    \App\Models\InventoryAdjustment::create([
                        'company_id' => $companyId,
                        'product_id' => $product->id,
                        'type' => 'set',
                        'quantity' => $setQty,
                        'previous_quantity' => $prevQty,
                        'new_quantity' => $setQty,
                        'reason' => 'Product edit',
                        'notes' => null,
                        'created_by' => auth('pos')->id(),
                    ]);
                }
            });
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
        // Inventory mirror cleanup — otherwise deleted products linger in the
        // module's tracked/out-of-stock counts forever (movement history stays).
        \App\Models\InventoryStock::where('company_id', $companyId)
            ->where('product_id', $product->id)
            ->delete();
        return back()->with('success', 'Product deleted successfully.');
    }

    public function toggleProduct($id)
    {
        $companyId = app('currentCompanyId');
        $product = PosProduct::where('company_id', $companyId)->findOrFail($id);
        $product->update(['is_active' => !$product->is_active]);
        return back()->with('success', $product->is_active ? 'Product activated.' : 'Product deactivated.');
    }

    public function toggleProductSale($id)
    {
        $companyId = app('currentCompanyId');
        $product = PosProduct::where('company_id', $companyId)->findOrFail($id);
        $product->update(['show_on_sale' => !$product->show_on_sale]);
        return back()->with('success', $product->show_on_sale ? 'Product is now visible on the sale screen.' : 'Product hidden from the sale screen.');
    }

    /**
     * Bulk actions on selected products (company-scoped).
     * action: activate | deactivate | delete | category (with category_value).
     */
    public function bulkProductAction(Request $request)
    {
        $companyId = app('currentCompanyId');
        $request->validate([
            'action' => 'required|string|in:activate,deactivate,delete,category',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'category_value' => 'nullable|string|max:100',
        ]);

        $query = PosProduct::where('company_id', $companyId)->whereIn('id', $request->ids);
        $count = (clone $query)->count();

        switch ($request->action) {
            case 'activate':
                $query->update(['is_active' => true]);
                $msg = "{$count} product(s) activated.";
                break;
            case 'deactivate':
                $query->update(['is_active' => false]);
                $msg = "{$count} product(s) deactivated.";
                break;
            case 'category':
                $query->update(['category' => $request->category_value ?: null]);
                $msg = "{$count} product(s) re-categorized.";
                break;
            case 'delete':
                // Clean up images before delete
                foreach ((clone $query)->whereNotNull('image')->pluck('image') as $img) {
                    if ($img && \Illuminate\Support\Facades\Storage::disk('public')->exists('products/' . $img)) {
                        \Illuminate\Support\Facades\Storage::disk('public')->delete('products/' . $img);
                    }
                }
                $query->delete();
                $msg = "{$count} product(s) deleted.";
                break;
            default:
                $msg = 'No action taken.';
        }

        return back()->with('success', $msg);
    }

    /**
     * Printable barcode/price label sheet for selected products (or all).
     * Renders A4 grid of labels; client-side JsBarcode draws Code128 from
     * barcode (fallback: sku, then PRA-<id>).
     */
    public function productLabels(Request $request)
    {
        $companyId = app('currentCompanyId');
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids', ''))));
        $query = PosProduct::where('company_id', $companyId)->orderBy('name');
        if (!empty($ids)) {
            $query->whereIn('id', $ids);
        }
        $products = $query->get();
        $company = \App\Models\Company::find($companyId);
        return view('pos.product-labels', compact('products', 'company'));
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

    /**
     * Neutralize CSV formula-injection: cells starting with = + - @ (or a
     * leading tab/CR) are prefixed with a single quote so spreadsheets treat
     * them as text instead of executing them as a formula.
     */
    private function csvSafe($value)
    {
        $s = (string) $value;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $s;
        }
        return $s;
    }

    /**
     * Export the company's POS customers to a CSV (streamed). Live order/spend
     * totals match the history view (customer_id OR phone) without double-counting.
     */
    public function exportCustomers()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $customers = PosCustomer::where('company_id', $companyId)->orderBy('name')->get();

        // Aggregate by linked id, then by phone for rows WITHOUT a linked id
        // (so a sale counted by id is never also counted by phone).
        $aggById = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, COUNT(*) as cnt, SUM(total_amount) as spent')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        $aggByPhone = PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->whereNull('customer_id')
            ->whereNotNull('customer_phone')
            ->selectRaw('customer_phone, COUNT(*) as cnt, SUM(total_amount) as spent')
            ->groupBy('customer_phone')
            ->get()
            ->keyBy('customer_phone');

        $filename = 'POS_Customers_' . now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($customers, $aggById, $aggByPhone, $company) {
            $file = fopen('php://output', 'w');
            // UTF-8 BOM so Excel renders Urdu / special chars correctly.
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, [$this->csvSafe('POS Customers — ' . ($company->name ?? ''))]);
            fputcsv($file, ['Generated: ' . now()->format('d M Y H:i')]);
            fputcsv($file, []);
            fputcsv($file, ['Name', 'Phone', 'Email', 'CNIC', 'NTN', 'City', 'Address', 'Type', 'Status', 'Total Orders', 'Total Spent']);
            foreach ($customers as $c) {
                $byId = $aggById[$c->id] ?? null;
                $byPhone = $c->phone ? ($aggByPhone[$c->phone] ?? null) : null;
                $cnt = (int) ($byId->cnt ?? 0) + (int) ($byPhone->cnt ?? 0);
                $spent = (float) ($byId->spent ?? 0) + (float) ($byPhone->spent ?? 0);
                fputcsv($file, [
                    $this->csvSafe($c->name),
                    $this->csvSafe($c->phone),
                    $this->csvSafe($c->email),
                    $this->csvSafe($c->cnic),
                    $this->csvSafe($c->ntn),
                    $this->csvSafe($c->city),
                    $this->csvSafe($c->address),
                    ucfirst($c->type),
                    $c->is_active ? 'Active' : 'Inactive',
                    $cnt,
                    number_format($spent, 2, '.', ''),
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Download a blank CSV template (with one example row) for customer import.
     */
    public function downloadCustomerTemplate()
    {
        $filename = 'POS_Customers_Import_Template.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];
        $callback = function () {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Name', 'Phone', 'Email', 'CNIC', 'NTN', 'City', 'Address', 'Type']);
            fputcsv($file, ['Ahmed Khan', '03001234567', 'ahmed@example.com', '35201-1234567-1', '1234567-8', 'Lahore', '123 Main Street', 'unregistered']);
            fclose($file);
        };
        return response()->stream($callback, 200, $headers);
    }

    /**
     * Import POS customers from an uploaded CSV. Forces company_id, dedupes by
     * phone then CNIC within the company (updates existing), skips invalid rows.
     */
    public function importCustomers(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $companyId = app('currentCompanyId');
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            return back()->with('error', 'Could not read the uploaded file.');
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return back()->with('error', 'The CSV file appears to be empty.');
        }
        // Strip a UTF-8 BOM that Excel may prepend to the first header cell.
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }

        $map = [];
        foreach ($header as $i => $col) {
            $key = strtolower(trim(str_replace([' ', '_', '-'], '', (string) $col)));
            if ($key !== '') {
                $map[$key] = $i;
            }
        }

        $get = function ($row, $keys) use ($map) {
            foreach ((array) $keys as $k) {
                if (isset($map[$k]) && isset($row[$map[$k]])) {
                    return trim((string) $row[$map[$k]]);
                }
            }
            return null;
        };

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $rowNum = 1;
        $errors = [];

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;
                if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) {
                    continue; // wholly blank line
                }

                $name = $get($row, ['name', 'customername', 'fullname']);
                if (empty($name)) {
                    $skipped++;
                    if (count($errors) < 10) $errors[] = "Row {$rowNum}: missing name — skipped.";
                    continue;
                }

                // Truncate per column length so a long cell never throws a
                // QueryException that rolls back the entire import.
                $name = mb_substr($name, 0, 255);
                $phone = ($v = $get($row, ['phone', 'mobile', 'contact', 'phonenumber'])) !== null && $v !== '' ? mb_substr($v, 0, 20) : null;
                $cnic = ($v = $get($row, ['cnic'])) !== null && $v !== '' ? mb_substr($v, 0, 20) : null;
                $email = ($v = $get($row, ['email'])) !== null && $v !== '' ? mb_substr($v, 0, 255) : null;
                $ntn = ($v = $get($row, ['ntn'])) !== null && $v !== '' ? mb_substr($v, 0, 50) : null;
                $city = ($v = $get($row, ['city'])) !== null && $v !== '' ? mb_substr($v, 0, 100) : null;
                $address = ($v = $get($row, ['address'])) !== null && $v !== '' ? mb_substr($v, 0, 500) : null;

                // Only treat type as authoritative when the cell actually has a value,
                // so a partial CSV never silently flips registered → unregistered.
                $typeRaw = $get($row, ['type']);
                $hasType = $typeRaw !== null && trim($typeRaw) !== '';
                $type = strtolower((string) $typeRaw) === 'registered' ? 'registered' : 'unregistered';

                $existing = null;
                if (!empty($phone)) {
                    $existing = PosCustomer::where('company_id', $companyId)->where('phone', $phone)->first();
                }
                if (!$existing && !empty($cnic)) {
                    $existing = PosCustomer::where('company_id', $companyId)->where('cnic', $cnic)->first();
                }

                $fields = [
                    'name' => $name,
                    'phone' => $phone,
                    'email' => $email,
                    'cnic' => $cnic,
                    'ntn' => $ntn,
                    'city' => $city,
                    'address' => $address,
                ];

                if ($existing) {
                    // Non-destructive: only overwrite fields the CSV actually carries,
                    // so blank cells never null out existing data.
                    $updateData = [];
                    foreach ($fields as $k => $val) {
                        if ($val !== null && $val !== '') {
                            $updateData[$k] = $val;
                        }
                    }
                    if ($hasType) {
                        $updateData['type'] = $type;
                    }
                    if (!empty($updateData)) {
                        $existing->update($updateData);
                    }
                    $updated++;
                } else {
                    PosCustomer::create(array_merge($fields, [
                        'type' => $hasType ? $type : 'unregistered',
                        'company_id' => $companyId,
                        'is_active' => true,
                    ]));
                    $imported++;
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($handle);
            \Log::error('POS customer import failed', ['company_id' => $companyId, 'error' => $e->getMessage()]);
            return back()->with('error', 'Import failed due to an unexpected error. Please check the file format and try again.');
        }
        fclose($handle);

        $msg = "Import complete: {$imported} added, {$updated} updated" . ($skipped ? ", {$skipped} skipped" : '') . '.';
        return back()->with('success', $msg)->with('import_errors', $errors);
    }

    /**
     * Resolve a customer's completed transactions, matching by id OR phone
     * (POS records customer_phone on walk-in sales even without a linked id).
     */
    private function customerTransactions($companyId, PosCustomer $customer)
    {
        return PosTransaction::where('company_id', $companyId)
            ->where('status', 'completed')
            ->where(function ($q) use ($customer) {
                $q->where('customer_id', $customer->id);
                if (!empty($customer->phone)) {
                    $q->orWhere('customer_phone', $customer->phone);
                }
            })
            ->orderByDesc('created_at');
    }

    /**
     * Per-customer purchase history page.
     */
    public function customerHistory($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);

        $transactions = $this->customerTransactions($companyId, $customer)->get();
        $totalSpent = $transactions->sum('total_amount');
        $totalOrders = $transactions->count();
        $avgOrder = $totalOrders > 0 ? $totalSpent / $totalOrders : 0;
        $lastOrder = $transactions->first();

        return view('pos.customer-history', compact('company', 'customer', 'transactions', 'totalSpent', 'totalOrders', 'avgOrder', 'lastOrder'));
    }

    /**
     * Download a single customer's purchase history as CSV.
     */
    public function exportCustomerHistory($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);
        $transactions = $this->customerTransactions($companyId, $customer)->get();

        $filename = 'Customer_History_' . preg_replace('/[^A-Za-z0-9]+/', '_', $customer->name) . '_' . now()->format('Ymd') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($transactions, $customer, $company) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, [$this->csvSafe('Customer Purchase History — ' . ($company->name ?? ''))]);
            fputcsv($file, [$this->csvSafe('Customer: ' . $customer->name), 'Phone: ' . ($customer->phone ?: '—')]);
            fputcsv($file, ['Generated: ' . now()->format('d M Y H:i')]);
            fputcsv($file, []);
            fputcsv($file, ['Date', 'Invoice #', 'Mode', 'Payment', 'Subtotal', 'Discount', 'Tax', 'Total']);
            foreach ($transactions as $t) {
                fputcsv($file, [
                    $t->created_at->format('d M Y H:i'),
                    $this->csvSafe($t->invoice_number),
                    $t->invoice_mode === 'local' ? 'Local' : 'PRA',
                    ucwords(str_replace('_', ' ', (string) $t->payment_method)),
                    number_format($t->subtotal, 2, '.', ''),
                    number_format($t->discount_amount, 2, '.', ''),
                    number_format($t->tax_amount, 2, '.', ''),
                    number_format($t->total_amount, 2, '.', ''),
                ]);
            }
            fputcsv($file, []);
            fputcsv($file, ['', '', '', 'TOTAL', '', '', '', number_format($transactions->sum('total_amount'), 2, '.', '')]);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Download a single customer's purchase history as a PDF.
     */
    public function customerHistoryPdf($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($id);
        $transactions = $this->customerTransactions($companyId, $customer)->get();
        $totalSpent = $transactions->sum('total_amount');
        $totalOrders = $transactions->count();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pos.customer-history-pdf', compact('company', 'customer', 'transactions', 'totalSpent', 'totalOrders'))
            ->setPaper('a4', 'portrait');

        $filename = 'Customer_History_' . preg_replace('/[^A-Za-z0-9]+/', '_', $customer->name) . '_' . now()->format('Ymd') . '.pdf';
        return $pdf->download($filename);
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
            // Per-cashier toggle (owner rule Jul 2026): the drafting user's own switch.
            $praEnabled = (bool) auth('pos')->user()?->praReportingEnabled($company);
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
            // Cashier's per-line T-toggle is authoritative — frontend already initializes
            // is_tax_exempt from product master default when item is added to cart, then
            // user may flip it via T-key. We MUST honor that override here.
            $isExempt = !empty($item['is_tax_exempt']);

            if ($itemId) {
                if ($itemType === 'product') {
                    $obj = PosProduct::where('company_id', $companyId)->where('id', $itemId)->first();
                    if (!$obj) {
                        $itemId = null;
                    }
                    // NOTE: Do NOT overwrite $isExempt from $obj->is_tax_exempt here.
                    // Cart payload already reflects user's intent (master default OR T-toggle override).
                } else {
                    $obj = PosService::where('company_id', $companyId)->where('id', $itemId)->first();
                    if (!$obj) {
                        $itemId = null;
                    }
                    // Same as above — honor cart payload, not DB master.
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

        // Order by the NUMERIC serial, NOT by id: a promoted local bill (old row,
        // low id) is RENUMBERED to the newest serial — id-ordering would then read
        // a stale max from the latest row and hand out a DUPLICATE serial.
        $lastTransaction = PosTransaction::where('company_id', $companyId)
            ->where('invoice_number', 'like', "POS-{$year}-%")
            ->orderByRaw(\App\Helpers\DbCompat::cast('SUBSTR(invoice_number, 10)', 'int') . ' DESC')
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
        // Vendor-requested short format: L-001 (lifetime sequence per company, 3-digit pad,
        // grows naturally past 999). Distinct from "POS-{year}-NNNNN" final invoices so cashiers
        // can spot provisional bills at a glance in lists/receipts/PDFs.
        // Exclude legacy "LOCAL-YYYY-NNNNN" rows from the new "L-NNN" sequence — the LIKE 'L-%'
        // pattern would otherwise match both formats and corrupt the counter.
        // Order by NUMERIC serial, not id — the draft-resume POS→L downgrade path can
        // assign a fresh (max) L number to an OLD row, so id-ordering would re-issue it
        // and trip the UNIQUE(company_id, invoice_number) index (same lesson as
        // generateInvoiceNumber's numeric-ordering fix).
        $lastTransaction = PosTransaction::where('company_id', $companyId)
            ->where('invoice_number', 'like', 'L-%')
            ->where('invoice_number', 'not like', 'LOCAL-%')
            ->orderByRaw(\App\Helpers\DbCompat::cast("SUBSTR(invoice_number, 3)", 'int') . ' DESC')
            ->lockForUpdate()
            ->first();

        if ($lastTransaction && preg_match('/^L-(\d+)$/', $lastTransaction->invoice_number, $matches)) {
            $next = (int) $matches[1] + 1;
        } else {
            $next = 1;
        }

        return 'L-' . str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    public function billing()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        // Standalone edition retired (Jul 2026) — everyone sees the PRA POS plans.
        $plans = \App\Models\PricingPlan::where('is_trial', false)->where('product_type', 'pos')->orderBy('price')->get();
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
                'rp_footer_text' => 'nullable|string|max:150',
            ];

            $request->validate($rules);

            // NTN is submitted with EVERY PRA fiscal invoice — do not allow clearing it
            // while PRA reporting is live, or subsequent submissions would carry a null NTN.
            // (NTN is optional at registration; it only becomes mandatory once PRA is ON.)
            if ($company->praReportingActive() && $request->has('ntn') && trim((string) $request->input('ntn')) === '') {
                return back()->withInput()->with('error', 'PRA Reporting on hai — NTN khali nahi kiya ja sakta. Pehle PRA Reporting band karein ya sahi NTN daalein.');
            }

            $data = $request->only(['name', 'owner_name', 'ntn', 'email', 'phone', 'mobile', 'address', 'city', 'business_activity', 'website']);

            // Receipt display preferences (per-company, POS product scope)
            if ($request->has('receipt_prefs_submitted')) {
                $prefs = $company->invoice_display_prefs ?? [];
                $prefs['pos'] = [
                    'show_address' => $request->has('rp_show_address'),
                    'show_ntn' => $request->has('rp_show_ntn'),
                    'show_email' => $request->has('rp_show_email'),
                    'show_mobile' => $request->has('rp_show_mobile'),
                    'show_footer' => $request->has('rp_show_footer'),
                    'footer_text' => trim((string) $request->input('rp_footer_text', '')) ?: null,
                ];
                $data['invoice_display_prefs'] = $prefs;
            }

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
            return redirect()->route('pos.customize')->with('success', 'Business profile updated successfully.');
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

        // Local (non-PRA) bills are excluded from the day-close view & figures —
        // visible only in the isolated Local Bills Portal (pos_role='local_viewer').
        $transactions = PosTransaction::where('company_id', $companyId)
            ->whereDate('created_at', $date)
            ->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })
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
        $company = Company::find($companyId);
        $user = \Illuminate\Support\Facades\Auth::guard('pos')->user();
        $date = $request->input('date', today()->format('Y-m-d'));

        // Destructive purge guard — only POS admin / company admin may REQUEST a local-bill
        // purge from the checkbox. Cashiers can still close the day, but a manual purge flag
        // under cashier authority is rejected.
        $requestedPurge = $request->boolean('purge_local_bills');
        $canPurge = $user && in_array(($user->pos_role ?? null), ['pos_admin', 'company_admin'], true);
        if ($requestedPurge && !$canPurge) {
            return back()->with('error', 'Only POS admin can purge local/provisional bills at day-close.');
        }

        // Company policy (Customize POS → Local Bills) auto-purges on EVERY day-close,
        // regardless of who closes the day — a standing admin decision, not cashier authority.
        // By this point a manual purge request is already admin-validated above.
        $purge = $requestedPurge || (bool) ($company->pos_auto_purge_local_on_dayclose ?? false);

        $result = $this->performDayClose($companyId, $date, $user?->id, $purge, $request->input('notes'));

        if ($result['status'] === 'exists') {
            return back()->with('error', 'Day Close Report for this date already exists.');
        }
        if ($result['status'] === 'empty') {
            return back()->with('error', 'No transactions found for this date.');
        }

        $msg = 'Day Close Report ' . $result['report_number'] . ' generated for ' . \Carbon\Carbon::parse($date)->format('d M Y');
        if ($result['archived'] > 0) {
            $msg .= " — {$result['archived']} local/provisional bill(s) moved to Archive.";
        }
        return back()->with('success', $msg);
    }

    /**
     * Core day-close logic shared by the HTTP endpoint (closeDayReport) and the
     * midnight auto-close command (pos:auto-dayclose). The caller decides whether to
     * $purge (archive local bills) and who $closedBy is — this method does NOT
     * enforce role authority, so authorize before calling.
     *
     * @return array{status:string,report:?\App\Models\PosDayCloseReport,archived:int,report_number:?string}
     *         status is one of 'created' | 'exists' | 'empty'.
     */
    public function performDayClose(int $companyId, string $date, ?int $closedBy, bool $purge, ?string $notes = null): array
    {
        $existing = PosDayCloseReport::where('company_id', $companyId)
            ->where('report_date', $date)
            ->first();

        if ($existing) {
            return ['status' => 'exists', 'report' => $existing, 'archived' => 0, 'report_number' => $existing->report_number];
        }

        // Local (non-PRA) bills stay OUT of the stored day-close figures — they are
        // visible only in the isolated Local Bills Portal. The purge/archive query
        // below still targets them so day-close archiving keeps working.
        $transactions = PosTransaction::where('company_id', $companyId)
            ->whereDate('created_at', $date)
            ->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        $hasLocalBills = PosTransaction::where('company_id', $companyId)
            ->whereDate('created_at', $date)
            ->where('invoice_mode', 'local')
            ->exists();

        if ($transactions->isEmpty() && !$hasLocalBills) {
            return ['status' => 'empty', 'report' => null, 'archived' => 0, 'report_number' => null];
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
            'closed_by' => $closedBy,
            'notes' => $notes,
        ];

        $hashString = json_encode($data);
        $data['hash'] = hash('sha256', $hashString);

        // Optional archive of local/provisional bills at day-close. Uses the CANONICAL
        // local-bill definition — invoice_mode='local' AND pra_status='local' — so
        // reporting-OFF finals (invoice_mode='pra' + NULL pra_status) are NEVER swept in.
        // Archive (NOT delete): is_archived=true hides rows via the global 'hide_archived'
        // scope while keeping them fully recoverable/audit-safe. Wrapped in one DB
        // transaction so report creation + archive succeed/fail atomically.
        $archivedCount = 0;
        $report = null;
        \DB::transaction(function () use ($data, $companyId, $date, $purge, &$archivedCount, &$report) {
            $report = PosDayCloseReport::create($data);
            if ($purge) {
                $archivedCount = PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $companyId)
                    ->whereDate('created_at', $date)
                    ->where('invoice_mode', 'local')
                    ->where('pra_status', 'local')
                    ->whereNull('pra_invoice_number')
                    ->where('is_archived', false)
                    ->update([
                        'is_archived' => true,
                        'archived_at' => now(),
                        'archived_by_report_id' => $report->id,
                    ]);
            }
        });

        return ['status' => 'created', 'report' => $report, 'archived' => $archivedCount, 'report_number' => $reportNumber];
    }

    public function dayCloseReportPdf($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $report = PosDayCloseReport::where('company_id', $companyId)->findOrFail($id);

        // Day-Close PDF shows HISTORICAL truth — include archived bills so the
        // closed-day report stays consistent even after rows were archived.
        // Local (non-PRA) bills excluded — visible only in the Local Bills Portal.
        $transactions = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->whereDate('created_at', $report->report_date)
            ->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })
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
