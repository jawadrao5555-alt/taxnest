<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\FbrDayCloseReport;
use App\Models\FbrPosLog;
use App\Models\FbrPosTransaction;
use App\Models\FbrPosTransactionItem;
use App\Models\Product;
use App\Services\FbrService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FbrPosController extends Controller
{
    // 🎯 VALUE MODE — UoM gating: only measure-based UoMs allow value(Rs) → qty derivation
    // Per FBR PRAL spec: weight/volume/length UoMs accept decimal qty; piece-based UoMs do not.
    const VALUE_MODE_UOMS = ['KG', 'GM', 'LTR', 'ML', 'MTR', 'SQM'];

    // ═══════════════════════════════════════════════════════════════════
    // 🎯 Universal Header API — Local (Provisional) Bills + Failed Bills
    // Powers the F10 / F11 header shortcuts available on every FBR POS page
    // ═══════════════════════════════════════════════════════════════════
    public function apiProvisionalBills(Request $request)
    {
        $companyId = Auth::guard('fbrpos')->user()->company_id ?? app('currentCompanyId');
        // 🔒 CONFIDENTIAL PIN GATE — local (provisional) bills are PIN-protected,
        // same rule as transactions?tab=local. 30-min verified-session window.
        $pinCompany = Company::find($companyId);
        if (!empty($pinCompany?->confidential_pin) && !$this->isPinSessionValid()) {
            return response()->json(['success' => false, 'pin_required' => true, 'message' => __('pos.pin_verification_required')], 403);
        }
        $bills = \App\Models\FbrPosTransaction::where('company_id', $companyId)
            ->where('invoice_mode', 'local')
            ->where('fbr_status', 'local')
            ->withCount('items')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'invoice_number', 'total_amount', 'created_at', 'customer_name', 'customer_phone'])
            ->map(function ($b) {
                return [
                    'id' => $b->id,
                    'invoice_number' => $b->invoice_number,
                    'total_amount' => (float) $b->total_amount,
                    'customer_name' => $b->customer_name ?? 'Walk-in',
                    'customer_phone' => $b->customer_phone,
                    'items_count' => (int) ($b->items_count ?? 0),
                    'created_human' => $b->created_at?->diffForHumans(),
                    'created_at' => optional($b->created_at)->format('d M, h:i A'),
                ];
            });
        return response()->json(['success' => true, 'bills' => $bills, 'count' => $bills->count()]);
    }

    public function apiDeleteProvisional(Request $request, $id)
    {
        $companyId = Auth::guard('fbrpos')->user()->company_id ?? app('currentCompanyId');
        // 🔒 CONFIDENTIAL PIN GATE — mirrors apiProvisionalBills.
        $pinCompany = Company::find($companyId);
        if (!empty($pinCompany?->confidential_pin) && !$this->isPinSessionValid()) {
            return response()->json(['success' => false, 'pin_required' => true, 'message' => __('pos.pin_verification_required')], 403);
        }
        $bill = \App\Models\FbrPosTransaction::where('company_id', $companyId)
            ->where('id', $id)
            ->where('invoice_mode', 'local')
            ->where('fbr_status', 'local')
            ->first();
        if (!$bill) {
            return response()->json(['success' => false, 'message' => __('pos.provisional_bill_not_found')], 404);
        }
        \DB::transaction(function () use ($bill) {
            \App\Models\FbrPosTransactionItem::where('transaction_id', $bill->id)->delete();
            $bill->delete();
        });
        return response()->json(['success' => true]);
    }

    public function apiPromoteProvisional(Request $request, $id)
    {
        $companyId = Auth::guard('fbrpos')->user()->company_id ?? app('currentCompanyId');
        // 🔒 CONFIDENTIAL PIN GATE — mirrors apiProvisionalBills.
        $pinCompany = Company::find($companyId);
        if (!empty($pinCompany?->confidential_pin) && !$this->isPinSessionValid()) {
            return response()->json(['success' => false, 'pin_required' => true, 'message' => __('pos.pin_verification_required')], 403);
        }
        // Reporting-OFF Finals Invariant — mirrors PosController::apiPromoteProvisional:
        // reporting-ON promote → fbr/'pending' (queued for submission);
        // reporting-OFF promote → fbr/NULL (FINAL, no submission — NEVER leave 'pending',
        // it would sit forever in the fail-queue/agent lists with reporting disabled).
        $reportingOn = (bool) (($pinCompany ?? Company::find($companyId))?->fbr_reporting_enabled ?? false);
        // 🔒 Race-safe atomic claim: only flips if still local — prevents double-promote
        $affected = \App\Models\FbrPosTransaction::where('id', $id)
            ->where('company_id', $companyId)
            ->where('invoice_mode', 'local')
            ->where('fbr_status', 'local')
            ->update([
                'invoice_mode' => 'fbr',
                'fbr_status' => $reportingOn ? 'pending' : null,
                'updated_at' => now(),
            ]);
        if ($affected === 0) {
            return response()->json(['success' => false, 'message' => __('pos.bill_not_found_or_promoted')], 409);
        }
        if (!$reportingOn) {
            return response()->json([
                'success'   => true,
                'submitted' => false,
                'message'   => __('pos.bill_now_final_fbr_off'),
                'id'        => $id,
            ]);
        }
        return response()->json(['success' => true, 'redirect' => route('fbrpos.failQueue')]);
    }

    public function updateTheme(Request $request)
    {
        $theme = $request->input('theme', 'blue');
        $allowed = ['purple', 'blue', 'emerald', 'orange', 'midnight', 'rose'];
        if (!in_array($theme, $allowed)) {
            return response()->json(['success' => false, 'message' => __('pos.invalid_theme')], 422);
        }
        $companyId = Auth::guard('fbrpos')->user()->company_id ?? app('currentCompanyId');
        Company::where('id', $companyId)->update(['pos_theme' => $theme]);
        return response()->json(['success' => true, 'theme' => $theme]);
    }

    public function updateDashboardStyle(Request $request)
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') {
            return response()->json(['success' => false, 'message' => __('pos.only_company_admin_change_dashboard_style')], 403);
        }
        $style = $request->json('style') ?? $request->input('style', 'default');
        $allowed = ['default', 'toast', 'lightspeed', 'clover', 'oscar', 'shopify'];
        if (!in_array($style, $allowed)) {
            return response()->json(['success' => false, 'message' => __('pos.invalid_style')], 422);
        }
        $companyId = app('currentCompanyId');
        Company::where('id', $companyId)->update(['pos_dashboard_style' => $style]);
        return response()->json(['success' => true, 'style' => $style]);
    }

    public function dashboard()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $branchSvc = app(\App\Services\BranchContextService::class);
        $branchScope = fn ($q) => $branchSvc->applyToQuery($q);

        $todayStats = FbrPosTransaction::where('company_id', $companyId)
            ->tap($branchScope)
            ->whereDate('created_at', today())
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue, COALESCE(SUM(tax_amount), 0) as tax')
            ->first();

        $monthStats = FbrPosTransaction::where('company_id', $companyId)
            ->tap($branchScope)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue, COALESCE(SUM(tax_amount), 0) as tax')
            ->first();

        $fbrSubmitted = FbrPosTransaction::where('company_id', $companyId)
            ->tap($branchScope)
            ->where('invoice_mode', 'fbr')
            ->whereNotNull('fbr_invoice_number')
            ->count();

        $fbrPending = FbrPosTransaction::where('company_id', $companyId)
            ->tap($branchScope)
            ->where('invoice_mode', 'fbr')
            ->where('fbr_status', 'pending')
            ->count();

        $recentTransactions = FbrPosTransaction::where('company_id', $companyId)
            ->tap($branchScope)
            ->where(function ($q) {
                $q->where('invoice_mode', 'fbr')->orWhereNull('invoice_mode');
            })
            ->with('creator')
            ->latest()
            ->take(10)
            ->get();

        $fbrReportingStatus = (bool) $company->fbr_reporting_enabled;

        $allowedStyles = ['default', 'toast', 'lightspeed', 'clover', 'oscar', 'shopify'];
        $dashboardStyle = in_array($company->pos_dashboard_style, $allowedStyles) ? $company->pos_dashboard_style : 'default';

        // Same pattern as DI dashboard: unread company notifications, 30-day auto-expiry.
        $notifications = \App\Models\Notification::where('company_id', $companyId)
            ->where('read', false)
            ->where('created_at', '>=', now()->subDays(30))
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        return view('fbr-pos.dashboard', compact(
            'company', 'todayStats', 'monthStats',
            'fbrSubmitted', 'fbrPending', 'recentTransactions', 'fbrReportingStatus',
            'dashboardStyle', 'notifications'
        ));
    }

    /**
     * Dismiss (mark read) a single in-app notification — mirrors
     * DashboardController::dismissNotification. NEVER deletes rows:
     * SendTrialReminders dedupes on row existence.
     */
    public function dismissNotification($id)
    {
        $companyId = app('currentCompanyId');

        \App\Models\Notification::where('company_id', $companyId)
            ->where('id', $id)
            ->update(['read' => true]);

        return back();
    }

    public function dismissAllNotifications()
    {
        $companyId = app('currentCompanyId');

        \App\Models\Notification::where('company_id', $companyId)
            ->where('read', false)
            ->update(['read' => true]);

        return back();
    }

    /**
     * 🧮 Detect dates that have sales but no Day Close report (EXCLUDES today).
     * Used by smart "pending day close" modal on /create — auto-closes pending days
     * when cashier opens POS after a rush/holiday with unclosed shifts.
     *
     * Timezone-safe: uses `created_at < startOfToday` (range query) instead of
     * `whereDate(< $today)` so app TZ ↔ DB TZ midnight edge is consistent and
     * the query can use a composite index on (company_id, created_at).
     */
    private function getPendingDayCloses(int $companyId, int $limit = 10): array
    {
        $startOfToday = now()->startOfDay();
        $closedDates = FbrDayCloseReport::where('company_id', $companyId)
            ->pluck('report_date')
            ->map(fn($d) => $d instanceof \Carbon\Carbon ? $d->format('Y-m-d') : (string) $d)
            ->all();

        $rows = \DB::table('fbr_pos_transactions')
            ->select(
                \DB::raw('DATE(created_at) as d'),
                \DB::raw('COUNT(*) as cnt'),
                \DB::raw('SUM(total_amount) as total')
            )
            ->where('company_id', $companyId)
            ->where('created_at', '<', $startOfToday)  // Timezone-safe + index-friendly
            ->when(!empty($closedDates), fn($q) => $q->whereNotIn(\DB::raw('DATE(created_at)'), $closedDates))
            ->groupBy('d')
            ->orderBy('d', 'desc')
            ->limit($limit)
            ->get();

        return $rows->map(fn($r) => [
            'date' => $r->d,
            'count' => (int) $r->cnt,
            'total' => (float) $r->total,
            'date_display' => \Carbon\Carbon::parse($r->d)->format('d M Y, D'),
        ])->all();
    }

    /**
     * 🧾 Shared day-close writer — single source of truth used by:
     *   1. Manual close (closeDayReport)
     *   2. Auto-close on next-open (apiAutoCloseDay)
     *
     * Concurrency-safe:
     *   - Wrapped in DB transaction with row-level lock on the duplicate check
     *   - Atomic MAX(report_number)+1 for sequential numbering (no count()+1 race)
     *   - Retry loop on 23000 unique-constraint violation (returns existing row if same date raced;
     *     retries with fresh MAX if report_number collided)
     *   - Idempotent: returns existing report when already closed (never throws on duplicate intent)
     *   - Returns null only when no transactions exist for that date
     */
    private function performDayClose(int $companyId, string $date, ?int $userId, ?string $notes = null, ?array $cashRecon = null): ?FbrDayCloseReport
    {
        // Fast-path: already closed → return without locking
        $existing = FbrDayCloseReport::where('company_id', $companyId)
            ->where('report_date', $date)->first();
        if ($existing) {
            return $existing;
        }

        $transactions = FbrPosTransaction::where('company_id', $companyId)
            ->whereDate('created_at', $date)
            ->with('creator')->orderBy('created_at')->get();
        if ($transactions->isEmpty()) {
            return null;
        }

        $baseData = [
            'company_id' => $companyId,
            'report_date' => $date,
            'total_invoices' => $transactions->count(),
            'fbr_invoices' => $transactions->where('fbr_status', 'submitted')->count(),
            'local_invoices' => $transactions->where('fbr_status', 'local')->count(),
            'failed_invoices' => $transactions->whereIn('fbr_status', ['failed', 'pending'])->count(),
            'gross_sales' => $transactions->sum('subtotal'),
            'total_discount' => $transactions->sum('discount_amount'),
            'net_sales' => $transactions->sum('subtotal') - $transactions->sum('discount_amount'),
            'total_tax' => $transactions->sum('tax_amount'),
            'total_fbr_fee' => $transactions->sum('fbr_service_charge'),
            'total_amount' => $transactions->sum('total_amount'),
            'cash_amount' => $transactions->where('payment_method', 'cash')->sum('total_amount'),
            // Card bucket includes stored aliases (debit/credit card) so card sales
            // never silently land in "Other" — mirrors the PRA POS day-close fix.
            'card_amount' => $transactions->whereIn('payment_method', ['card', 'debit_card', 'credit_card'])->sum('total_amount'),
            'other_amount' => $transactions->whereNotIn('payment_method', ['cash', 'card', 'debit_card', 'credit_card'])->sum('total_amount'),
            'first_invoice_number' => $transactions->first()->invoice_number ?? null,
            'last_invoice_number' => $transactions->last()->invoice_number ?? null,
            'first_invoice_time' => $transactions->first()->created_at ?? null,
            'last_invoice_time' => $transactions->last()->created_at ?? null,
            'closed_by' => $userId,
            'notes' => $notes,
        ];

        // Cash reconciliation (Z-report): expected = opening float + cash sales;
        // variance = counted − expected. Columns nullable + schema-guarded (prod
        // drift self-heal) — auto-close paths pass no $cashRecon. Merged BEFORE
        // the hash so the recon figures are integrity-protected too.
        if ($cashRecon !== null && \Schema::hasColumn('fbr_day_close_reports', 'opening_float')) {
            $openingFloat = $cashRecon['opening_float'] ?? null;
            $countedCash = $cashRecon['counted_cash'] ?? null;
            $expectedCash = round((float) ($openingFloat ?? 0) + (float) $baseData['cash_amount'], 2);
            $baseData['opening_float'] = $openingFloat;
            $baseData['counted_cash'] = $countedCash;
            $baseData['expected_cash'] = $expectedCash;
            $baseData['cash_variance'] = $countedCash !== null ? round((float) $countedCash - $expectedCash, 2) : null;
        }

        // Retry loop — max 3 attempts to handle rare concurrent report_number collisions
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return \DB::transaction(function () use ($companyId, $date, $baseData) {
                    // Race-safe re-check inside transaction
                    $locked = FbrDayCloseReport::where('company_id', $companyId)
                        ->where('report_date', $date)->lockForUpdate()->first();
                    if ($locked) return $locked;

                    // Atomic MAX+1 — parses trailing digits from existing 'ZRPT-XXXXX' numbers.
                    // Far safer than count()+1 (which double-counts under concurrency).
                    $maxNum = (int) FbrDayCloseReport::where('company_id', $companyId)
                        ->max(\DB::raw("CAST(SUBSTRING_INDEX(report_number, '-', -1) AS UNSIGNED)"));
                    $reportNumber = 'ZRPT-' . str_pad($maxNum + 1, 5, '0', STR_PAD_LEFT);

                    $data = array_merge($baseData, ['report_number' => $reportNumber]);
                    $data['hash'] = hash('sha256', json_encode($data));
                    return FbrDayCloseReport::create($data);
                });
            } catch (\Illuminate\Database\QueryException $e) {
                // 23000 = Integrity constraint (unique violation)
                $isDuplicate = $e->getCode() === '23000'
                    || str_contains(strtolower($e->getMessage()), 'duplicate');
                if ($isDuplicate) {
                    // Case A: (company_id, report_date) raced — same day already closed by other request
                    $row = FbrDayCloseReport::where('company_id', $companyId)
                        ->where('report_date', $date)->first();
                    if ($row) return $row;
                    // Case B: report_number collision (no unique index, but defensive) → retry with fresh MAX
                    if ($attempt < 3) continue;
                }
                \Log::error('performDayClose failed', ['company' => $companyId, 'date' => $date, 'err' => $e->getMessage()]);
                throw $e;
            }
        }
        return null;
    }

    /**
     * 🚀 API: Auto-close one or all pending past day(s).
     * Safety: NEVER closes today or future dates.
     * Body: { date: 'YYYY-MM-DD' } OR { all: true }
     */
    public function apiAutoCloseDay(Request $request)
    {
        try {
            $companyId = app('currentCompanyId');
            $userId = Auth::guard('fbrpos')->id();
            $today = today()->format('Y-m-d');

            $closed = [];
            if ($request->boolean('all')) {
                foreach ($this->getPendingDayCloses($companyId, 30) as $p) {
                    $r = $this->performDayClose($companyId, $p['date'], $userId, 'Auto-closed on next open (rush recovery)');
                    if ($r) $closed[] = ['number' => $r->report_number, 'date' => $p['date']];
                }
            } else {
                $date = $request->input('date');
                if (!$date) {
                    return response()->json(['ok' => false, 'error' => 'date required'], 422);
                }
                if ($date >= $today) {
                    return response()->json(['ok' => false, 'error' => 'Cannot auto-close today or future dates'], 422);
                }
                $r = $this->performDayClose($companyId, $date, $userId, 'Auto-closed on next open (rush recovery)');
                if ($r) $closed[] = ['number' => $r->report_number, 'date' => $date];
            }

            return response()->json(['ok' => true, 'closed' => $closed, 'count' => count($closed)]);
        } catch (\Throwable $e) {
            \Log::error('apiAutoCloseDay failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
        }
    }

    public function create()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $products = Product::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();
        $fbrReportingEnabled = (bool) $company->fbr_reporting_enabled;

        // 🧮 Pending Day-Close detection (rush/holiday recovery)
        $pendingDayCloses = $this->getPendingDayCloses($companyId, 10);

        // 🔥 Frequently sold products (last 30 days, top 12 by total qty sold)
        // Used by the bottom "Quick Add" tile grid on the create page so cashiers can
        // one-click add their routine high-velocity items without typing/searching.
        $topIds = \DB::table('fbr_pos_transaction_items as fi')
            ->join('fbr_pos_transactions as ft', 'ft.id', '=', 'fi.transaction_id')
            ->where('ft.company_id', $companyId)
            ->where('ft.created_at', '>=', now()->subDays(30))
            ->whereNotNull('fi.product_id')
            ->select('fi.product_id', \DB::raw('SUM(fi.quantity) as sold_qty'))
            ->groupBy('fi.product_id')
            ->orderByDesc('sold_qty')
            ->limit(12)
            ->pluck('fi.product_id')
            ->all();
        if (!empty($topIds)) {
            $orderClause = 'FIELD(id,' . implode(',', array_map('intval', $topIds)) . ')';
            $frequentProducts = Product::where('company_id', $companyId)
                ->where('is_active', true)
                ->where('show_on_sale', true)
                ->whereIn('id', $topIds)
                ->orderByRaw($orderClause)
                ->get();
        } else {
            // Cold start (no sales yet) — fall back to the first 12 active products by name
            // (grid tiles respect show_on_sale; hidden products stay searchable + billable)
            $frequentProducts = $products->where('show_on_sale', true)->take(12)->values();
        }

        // Phase 2: terminals, shift, loyalty, promotions
        $terminals = \App\Models\FbrPosTerminal::where('company_id', $companyId)->where('is_active', true)->orderBy('terminal_name')->get();
        $currentShift = \App\Models\FbrPosShift::where('company_id', $companyId)
            ->where('user_id', Auth::guard('fbrpos')->id())
            ->where('status', 'open')->latest('id')->first();
        $loyaltySettings = \App\Models\FbrPosLoyaltySetting::forCompany($companyId);
        $heldCount = \App\Models\FbrPosHeldSale::where('company_id', $companyId)->count();
        $activePromos = \App\Models\FbrPosPromotion::where('company_id', $companyId)
            ->where('is_active', true)->orderByDesc('id')->limit(20)->get();

        // 🌐 FBR Universal sale screen (per-company opt-in, Phase 1 toggle).
        // Falls back to the classic create screen until the universal view ships
        // AND the company has explicitly enabled it — zero risk to existing flow.
        $viewName = ((bool) ($company->fbr_universal_enabled ?? false) && view()->exists('fbr-pos.universal'))
            ? 'fbr-pos.universal'
            : 'fbr-pos.create';

        // Universal screen needs the customer list for its phone-lookup bar.
        $customers = $viewName === 'fbr-pos.universal'
            ? \App\Models\PosCustomer::where('company_id', $companyId)
                ->where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone'])
            : collect();

        return view($viewName, compact(
            'company', 'products', 'fbrReportingEnabled', 'frequentProducts',
            'terminals', 'currentShift', 'loyaltySettings', 'heldCount', 'activePromos',
            'pendingDayCloses', 'customers'
        ));
    }

    public function store(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        // 🧹 Server-side empty-row scrub — defense-in-depth in case JS cleanEmptyItems missed any
        $rawItems = $request->input('items', []);
        if (is_array($rawItems)) {
            $cleanItems = array_values(array_filter($rawItems, function ($it) {
                if (!is_array($it)) return false;
                $name = trim((string)($it['item_name'] ?? ''));
                $qty = (float)($it['quantity'] ?? 0);
                $price = (float)($it['unit_price'] ?? 0);
                return $name !== '' && $qty > 0 && $price > 0;
            }));
            $request->merge(['items' => $cleanItems]);
        }

        try {
            $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_price' => 'required|numeric|min:0.01',
            'items.*.hs_code' => 'nullable|string|max:20',
            'items.*.uom' => 'nullable|string|in:U,KG,GM,LTR,ML,MTR,SQM,PCS,PKT,DOZ,BOX,SET,BAG,BTL,CTN,ROL,FT,IN,YDS,TIN,CAN,BUN',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.is_tax_exempt' => 'nullable|boolean',
            'items.*.item_discount' => 'nullable|numeric|min:0',
            'items.*.value_input' => 'nullable|numeric|min:0.01',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'customer_ntn' => 'nullable|string|max:30',
            'payment_method' => 'required|in:cash,card,bank_transfer,online',
            'discount_type' => 'nullable|in:percentage,fixed',
            'discount_value' => 'nullable|numeric|min:0',
            'terminal_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'promotion_id' => 'nullable|integer',
            'promotion_code' => 'nullable|string|max:50',
            'loyalty_points_redeemed' => 'nullable|integer|min:0',
            'cash_received' => 'nullable|numeric|min:0',
            'payment_breakdown' => 'nullable|array',
            'payment_breakdown.*.method' => 'required_with:payment_breakdown|string',
            'payment_breakdown.*.amount' => 'required_with:payment_breakdown|numeric|min:0',
            'tax_inclusive' => 'nullable|boolean',
            ]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            Log::warning('FBR POS Store: validation failed', [
                'errors' => $ve->errors(),
                'item_count' => count($request->input('items', [])),
                'payment_method' => $request->input('payment_method'),
            ]);
            throw $ve;
        }

        $fbrEnabled = (bool) $company->fbr_reporting_enabled;
        // 💾 SAVE AS PROVISIONAL (universal sale screen) — cashier explicitly asked
        // for a local bill (no FBR submission now). Same semantics as a local-mode
        // sale: invoice_mode='local' + fbr_status='local'. Promote later via F10.
        $saveAsProvisional = $request->boolean('save_as_provisional');
        if ($saveAsProvisional) {
            // Deliberate provisional — editable/deletable via F10 Local modal until promoted.
            $invoiceMode = 'local';
            $initialFbrStatus = 'local';
        } elseif ($fbrEnabled) {
            $invoiceMode = 'fbr';
            $initialFbrStatus = 'pending';
        } else {
            // FBR-reporting-OFF company, FINAL sale. Must NOT be local-mode — local
            // hides the bill from transactions/KPIs (which filter to fbr/NULL) and
            // pollutes the F10 provisional modal where cashiers could edit/delete a
            // final bill. 'fbr' mode + NULL fbr_status = normal bill, no FBR
            // involvement (fail-queue/retry/promote all key off fbr_status).
            $invoiceMode = 'fbr';
            $initialFbrStatus = null;
        }

        try {
            $transaction = DB::transaction(function () use ($request, $companyId, $company, $invoiceMode, $initialFbrStatus) {
                $subtotal = 0;
                $totalTax = 0;
                $itemsData = [];

                $defaultTaxRate = 18;

                foreach ($request->items as $item) {
                    $price = (float) $item['unit_price'];
                    $uom = strtoupper($item['uom'] ?? 'U');
                    $valueInput = isset($item['value_input']) && $item['value_input'] !== ''
                        ? (float) $item['value_input'] : 0;

                    // 🔒 FIXED-PRICE ENFORCEMENT (server-side guard against payload tampering)
                    // If product is linked AND is_price_editable=false, force unit_price from DB
                    // and reject any value-mode (Rs) entry. Cashier UI already hides these, but
                    // a crafted request could otherwise bypass and submit arbitrary prices.
                    if (!empty($item['product_id'])) {
                        $product = \App\Models\Product::where('id', $item['product_id'])
                            ->where('company_id', $companyId)
                            ->first();
                        if ($product && $product->is_price_editable === false) {
                            // NOTE: column is default_price — reading the non-existent
                            // ->price attribute silently returned null → every fixed-price
                            // product line became Rs 0 (total = just the Rs1 FBR charge).
                            $price = (float) $product->default_price;
                            $valueInput = 0; // hard-reject value-mode for fixed-price products
                        }
                    }

                    // 🎯 VALUE MODE — derive qty from Rs amount (authoritative) for measure UoMs only
                    if ($valueInput > 0) {
                        if (!in_array($uom, self::VALUE_MODE_UOMS, true)) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'items' => "Value (Rs) entry only allowed for KG/GM/LTR/ML/MTR/SQM. Got '{$uom}' for item '{$item['item_name']}'.",
                            ]);
                        }
                        if ($price <= 0) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'items' => "Cannot derive quantity from value: unit price must be > 0 for '{$item['item_name']}'.",
                            ]);
                        }
                        $qty = round($valueInput / $price, 4);
                    } else {
                        $qty = round((float) $item['quantity'], 4);
                    }

                    // 🚫 Reject qty <= 0 outright (no silent fallback to 1)
                    if ($qty <= 0) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'items' => "Item '{$item['item_name']}' has invalid quantity (must be > 0).",
                        ]);
                    }

                    // 🚫 Decimal qty NOT allowed for unit-based UoMs (PCS/U/BOX/PKT/...)
                    if (!in_array($uom, self::VALUE_MODE_UOMS, true) && abs($qty - round($qty)) > 0.0001) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'items' => "Decimal quantity not allowed for unit-based UoM '{$uom}' on item '{$item['item_name']}'. Use whole numbers only (or switch UoM to KG/LTR for value-mode).",
                        ]);
                    }

                    $isExempt = !empty($item['is_tax_exempt']);
                    $taxRate = $isExempt ? 0 : (float) ($item['tax_rate'] ?? $defaultTaxRate);
                    $itemDiscount = round((float) ($item['item_discount'] ?? 0), 2);

                    // 🎯 TAX-INCLUSIVE MODE — cart-level toggle (e.g. "150 ka rice" should TOTAL 150)
                    // unit_price is treated as INCLUSIVE of tax → reverse-calculate the net.
                    // net_per_unit = unit_price / (1 + tax_rate/100)
                    // tax_per_unit = unit_price - net_per_unit
                    // lineTotal stays = unit_price * qty (after item discount applied to net)
                    $taxInclusive = $request->boolean('tax_inclusive');
                    if ($taxInclusive && $taxRate > 0) {
                        $grossInclusiveLine = round($price * $qty, 2);
                        if ($itemDiscount > $grossInclusiveLine) { $itemDiscount = $grossInclusiveLine; }
                        $afterDiscInclusive = $grossInclusiveLine - $itemDiscount;
                        $lineSubtotal = round($afterDiscInclusive / (1 + $taxRate / 100), 2);
                        $lineTax = round($afterDiscInclusive - $lineSubtotal, 2);
                        $lineTotal = round($lineSubtotal + $lineTax, 2);
                    } else {
                        $grossLine = round($price * $qty, 2);
                        if ($itemDiscount > $grossLine) { $itemDiscount = $grossLine; }
                        $lineSubtotal = round($grossLine - $itemDiscount, 2);
                        $lineTax = round($lineSubtotal * $taxRate / 100, 2);
                        $lineTotal = round($lineSubtotal + $lineTax, 2);
                    }

                    $subtotal += $lineSubtotal;
                    $totalTax += $lineTax;

                    $itemsData[] = [
                        'item_name' => $item['item_name'],
                        'hs_code' => $item['hs_code'] ?? null,
                        'uom' => $uom,
                        'product_id' => $item['product_id'] ?? null,
                        'quantity' => $qty,
                        'unit_price' => $price,
                        'discount' => 0,
                        'item_discount' => $itemDiscount,
                        'tax_rate' => $taxRate,
                        'tax_amount' => $lineTax,
                        'subtotal' => $lineSubtotal,
                        'total' => $lineTotal,
                        'is_tax_exempt' => $isExempt,
                    ];
                }

                $discountType = $request->discount_type;
                $discountValue = (float) ($request->discount_value ?? 0);
                $discountAmount = 0;
                if ($discountType === 'percentage' && $discountValue > 0) {
                    $discountAmount = round($subtotal * $discountValue / 100, 2);
                } elseif ($discountType === 'fixed' && $discountValue > 0) {
                    $discountAmount = min($discountValue, $subtotal);
                }

                // Rs 1 POS service fee applies ONLY to bills actually reported to FBR.
                // FBR-reporting-OFF finals are 'fbr' mode with NULL status (never
                // submitted) — no fee. Provisionals (local) — no fee until promoted.
                $fbrServiceCharge = ($invoiceMode === 'fbr' && $initialFbrStatus !== null) ? 1.00 : 0.00;

                // Phase 2: Promotion discount (cart-level, separate from manual discount)
                $promotionDiscount = 0;
                $promo = null;
                if ($request->promotion_id) {
                    $promo = \App\Models\FbrPosPromotion::where('company_id', $companyId)
                        ->where('id', $request->promotion_id)->where('is_active', true)->first();
                    if ($promo) {
                        $check = $promo->isUsable($subtotal);
                        if ($check['ok']) {
                            $promotionDiscount = $promo->calcDiscount($subtotal);
                            $discountAmount += $promotionDiscount;
                        }
                    }
                }

                // Phase 2: Loyalty redemption
                $loyaltyRedemptionAmount = 0;
                $loyaltyPointsRedeemed = (int) ($request->loyalty_points_redeemed ?? 0);
                $loyaltySettings = \App\Models\FbrPosLoyaltySetting::forCompany($companyId);
                if ($loyaltyPointsRedeemed > 0 && $loyaltySettings->is_enabled && $request->customer_id) {
                    $customer = \App\Models\PosCustomer::where('company_id', $companyId)
                        ->where('id', $request->customer_id)->first();
                    if ($customer && $customer->loyalty_points >= $loyaltyPointsRedeemed
                        && $loyaltyPointsRedeemed >= $loyaltySettings->min_redeem_points) {
                        $loyaltyRedemptionAmount = round($loyaltyPointsRedeemed * $loyaltySettings->point_value, 2);
                        // cap to remaining total
                        $maxRedeem = max(0, $subtotal - $discountAmount + $totalTax);
                        $loyaltyRedemptionAmount = min($loyaltyRedemptionAmount, $maxRedeem);
                    } else {
                        $loyaltyPointsRedeemed = 0;
                    }
                }

                $totalAmount = round($subtotal - $discountAmount + $totalTax + $fbrServiceCharge - $loyaltyRedemptionAmount, 2);
                if ($totalAmount < 0) $totalAmount = 0;

                // Loyalty earn (1 point per rs_per_point on net total)
                $loyaltyPointsEarned = 0;
                if ($loyaltySettings->is_enabled && $request->customer_id && $loyaltySettings->rs_per_point > 0) {
                    $loyaltyPointsEarned = (int) floor($totalAmount / (float) $loyaltySettings->rs_per_point);
                }

                // Cash received & change
                $cashReceived = (float) ($request->cash_received ?? 0);
                // 💵 SERVER-SIDE CASH GUARD — block sale if cash payment & received < total
                if ($request->payment_method === 'cash' && $cashReceived < $totalAmount) {
                    $shortBy = number_format($totalAmount - $cashReceived, 2);
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'cash_received' => "Cash received (Rs " . number_format($cashReceived, 2) . ") is less than total (Rs " . number_format($totalAmount, 2) . "). Short by Rs {$shortBy}. Sale blocked.",
                    ]);
                }
                $changeDue = max(0, $cashReceived - $totalAmount);

                // Payment breakdown
                $paymentBreakdown = $request->payment_breakdown;
                if (!$paymentBreakdown) {
                    $paymentBreakdown = [['method' => $request->payment_method, 'amount' => $totalAmount]];
                }

                // Active shift
                $shift = \App\Models\FbrPosShift::where('company_id', $companyId)
                    ->where('user_id', Auth::guard('fbrpos')->id())
                    ->where('status', 'open')->latest('id')->first();

                $invoiceNumber = $invoiceMode === 'local'
                    ? $this->generateLocalInvoiceNumber($companyId)
                    : $this->generateInvoiceNumber($companyId);

                $transaction = FbrPosTransaction::create([
                    'company_id' => $companyId,
                    'branch_id' => app()->bound('currentBranchId') ? app('currentBranchId') : null,
                    'terminal_id' => $request->terminal_id,
                    'shift_id' => $shift?->id,
                    'invoice_number' => $invoiceNumber,
                    'invoice_mode' => $invoiceMode,
                    'transaction_type' => 'sale',
                    'customer_name' => $request->customer_name,
                    'customer_phone' => $request->customer_phone,
                    'customer_ntn' => $request->customer_ntn,
                    'customer_id' => $request->customer_id,
                    'subtotal' => $subtotal,
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'discount_amount' => $discountAmount,
                    'promotion_id' => $promo?->id,
                    'promotion_code' => $promo?->code,
                    'tax_rate' => $defaultTaxRate,
                    'tax_amount' => $totalTax,
                    'fbr_service_charge' => $fbrServiceCharge,
                    'loyalty_points_earned' => $loyaltyPointsEarned,
                    'loyalty_points_redeemed' => $loyaltyPointsRedeemed,
                    'loyalty_redemption_amount' => $loyaltyRedemptionAmount,
                    'total_amount' => $totalAmount,
                    'payment_method' => $request->payment_method,
                    'payment_breakdown' => $paymentBreakdown,
                    'cash_received' => $cashReceived,
                    'change_due' => $changeDue,
                    'status' => 'completed',
                    'fbr_status' => $initialFbrStatus,
                    'created_by' => Auth::guard('fbrpos')->id(),
                ]);

                // Update promotion usage
                if ($promo && $promotionDiscount > 0) {
                    $promo->increment('usage_count');
                }

                // Update shift totals
                if ($shift) {
                    $cashTotal = 0; $cardTotal = 0; $otherTotal = 0;
                    foreach ($paymentBreakdown as $pb) {
                        $m = strtolower($pb['method'] ?? '');
                        $a = (float) ($pb['amount'] ?? 0);
                        if ($m === 'cash') $cashTotal += $a;
                        elseif (in_array($m, ['card','credit_card','debit_card'])) $cardTotal += $a;
                        else $otherTotal += $a;
                    }
                    $shift->sales_count = (int) $shift->sales_count + 1;
                    $shift->total_sales = (float) $shift->total_sales + $totalAmount;
                    $shift->total_cash = (float) $shift->total_cash + $cashTotal;
                    $shift->total_card = (float) $shift->total_card + $cardTotal;
                    $shift->total_other = (float) $shift->total_other + $otherTotal;
                    $shift->save();
                }

                // Update customer loyalty + stats
                if ($request->customer_id) {
                    $customer = \App\Models\PosCustomer::find($request->customer_id);
                    if ($customer) {
                        $netPoints = $loyaltyPointsEarned - $loyaltyPointsRedeemed;
                        $customer->loyalty_points = max(0, (int) $customer->loyalty_points + $netPoints);
                        $customer->total_spent = (float) $customer->total_spent + $totalAmount;
                        $customer->total_orders = (int) $customer->total_orders + 1;
                        $customer->save();

                        if ($loyaltyPointsRedeemed > 0) {
                            \App\Models\FbrPosLoyaltyLedger::create([
                                'company_id' => $companyId, 'customer_id' => $customer->id,
                                'transaction_id' => $transaction->id, 'type' => 'redeem',
                                'points' => -$loyaltyPointsRedeemed,
                                'balance_after' => $customer->loyalty_points,
                                'note' => "Redeemed on invoice {$invoiceNumber}",
                            ]);
                        }
                        if ($loyaltyPointsEarned > 0) {
                            \App\Models\FbrPosLoyaltyLedger::create([
                                'company_id' => $companyId, 'customer_id' => $customer->id,
                                'transaction_id' => $transaction->id, 'type' => 'earn',
                                'points' => $loyaltyPointsEarned,
                                'balance_after' => $customer->loyalty_points,
                                'note' => "Earned on invoice {$invoiceNumber}",
                            ]);
                        }
                    }
                }

                foreach ($itemsData as $itemData) {
                    $transaction->items()->create($itemData);
                }

                return $transaction;
            });

            if ($invoiceMode === 'local') {
                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => true,
                        'transaction_id' => $transaction->id,
                        'invoice_number' => $transaction->invoice_number,
                        'total_amount' => (float) $transaction->total_amount,
                        'change_due' => (float) $transaction->change_due,
                        'invoice_mode' => 'local',
                        'fbr_status' => 'local',
                        'fbr_invoice_number' => null,
                        'message' => __('pos.local_sale_created_fbr_off', ['number' => $transaction->invoice_number]),
                    ]);
                }
                return redirect()->route('fbrpos.show', $transaction->id)
                    ->with('success', __('pos.local_sale_created_fbr_off_full', ['number' => $transaction->invoice_number, 'amount' => number_format($transaction->total_amount, 2)]));
            }

            // FBR-reporting-OFF company, FINAL sale — bill is saved as a normal
            // transaction (fbr_status NULL) and FBR submission is skipped entirely.
            if (!$fbrEnabled) {
                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => true,
                        'transaction_id' => $transaction->id,
                        'invoice_number' => $transaction->invoice_number,
                        'total_amount' => (float) $transaction->total_amount,
                        'change_due' => (float) $transaction->change_due,
                        'invoice_mode' => 'fbr',
                        'fbr_status' => null,
                        'fbr_invoice_number' => null,
                        'message' => __('pos.bill_created_fbr_off_json', ['number' => $transaction->invoice_number, 'amount' => number_format($transaction->total_amount, 2)]),
                    ]);
                }
                return redirect()->route('fbrpos.show', $transaction->id)
                    ->with('success', __('pos.bill_created_fbr_off_flash', ['number' => $transaction->invoice_number, 'amount' => number_format($transaction->total_amount, 2)]));
            }

            // FISCAL DEVICE MODE — FBR retired cloud bulk PostData (Code 112). Instead of a direct
            // cloud submit, queue the reporting-ON final bill 'pending' for the Desktop Sync Agent,
            // which POSTs it to the LOCAL FBR IMS component (localhost:8524) on the shop PC. The FBR
            // invoice number lands later via the agent's submit-result callback.
            if ($company->agentServesFbr() && $company->agent_enabled) {
                $transaction->update(['fbr_status' => 'pending']);
                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => true,
                        'transaction_id' => $transaction->id,
                        'invoice_number' => $transaction->invoice_number,
                        'total_amount' => (float) $transaction->total_amount,
                        'change_due' => (float) $transaction->change_due,
                        'invoice_mode' => 'fbr',
                        'fbr_status' => 'pending',
                        'fbr_invoice_number' => null,
                        'message' => __('pos.bill_queued_fiscal_device_json', ['number' => $transaction->invoice_number]),
                    ]);
                }
                return redirect()->route('fbrpos.show', $transaction->id)
                    ->with('success', __('pos.bill_queued_fiscal_device_flash', ['number' => $transaction->invoice_number, 'amount' => number_format($transaction->total_amount, 2)]));
            }

            $transaction->load(['items', 'company']);
            $fbrService = new FbrService();
            $fbrResult = $fbrService->submitFbrPosTransaction($transaction);

            if ($fbrResult['status'] === 'success') {
                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => true,
                        'transaction_id' => $transaction->id,
                        'invoice_number' => $transaction->invoice_number,
                        'total_amount' => (float) $transaction->total_amount,
                        'change_due' => (float) $transaction->change_due,
                        'invoice_mode' => 'fbr',
                        'fbr_status' => 'success',
                        'fbr_invoice_number' => $fbrResult['fbr_invoice_number'] ?? null,
                        'message' => __('pos.sale_submitted_fbr_json', ['number' => $transaction->invoice_number, 'fbr' => $fbrResult['fbr_invoice_number'] ?? '']),
                    ]);
                }
                return redirect()->route('fbrpos.show', $transaction->id)
                    ->with('success', __('pos.sale_created_submitted_fbr', ['number' => $transaction->invoice_number, 'fbr' => $fbrResult['fbr_invoice_number']]));
            }

            $fbrErrors = implode(', ', $fbrResult['errors'] ?? ['Unknown error']);

            // ============ AUTO-RETRY ENGINE ============
            // For transient failures (curl/network/empty 200/timeout), schedule auto-retry job (10s, 20s, 30s, max 3 tries)
            // For hard failures (token missing, validation), no retry — manual fix needed
            $errorString = strtolower($fbrErrors);
            $isTransient = $fbrResult['status'] === 'retry'
                || str_contains($errorString, 'connection failed')
                || str_contains($errorString, 'timeout')
                || str_contains($errorString, 'empty response')
                || str_contains($errorString, 'unexpected response')
                || (isset($fbrResult['http_status']) && $fbrResult['http_status'] >= 500);

            if ($isTransient) {
                \App\Jobs\RetryFbrPosSubmissionJob::dispatch($transaction->id)->delay(now()->addSeconds(10));
                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => true,
                        'transaction_id' => $transaction->id,
                        'invoice_number' => $transaction->invoice_number,
                        'total_amount' => (float) $transaction->total_amount,
                        'change_due' => (float) $transaction->change_due,
                        'invoice_mode' => 'fbr',
                        'fbr_status' => 'pending',
                        'fbr_invoice_number' => null,
                        'message' => __('pos.sale_saved', ['number' => $transaction->invoice_number]),
                        'warning' => __('pos.fbr_temp_failed_autoretry'),
                    ]);
                }
                return redirect()->route('fbrpos.show', $transaction->id)
                    ->with('success', __('pos.sale_saved_amount', ['number' => $transaction->invoice_number, 'amount' => number_format($transaction->total_amount, 2)]))
                    ->with('warning', __('pos.fbr_temp_failed_autoretry_detail', ['error' => $fbrErrors]));
            }

            // ✅ Sale was saved locally. FBR is a separate retry-able step — don't scare the cashier with red error.
            $isTokenIssue = str_contains(strtolower($fbrErrors), 'token');
            $warningMsg = $isTokenIssue
                ? __('pos.bill_saved_token_pending')
                : __('pos.bill_saved_fbr_pending', ['error' => $fbrErrors]);

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'transaction_id' => $transaction->id,
                    'invoice_number' => $transaction->invoice_number,
                    'total_amount' => (float) $transaction->total_amount,
                    'change_due' => (float) $transaction->change_due,
                    'invoice_mode' => 'fbr',
                    'fbr_status' => 'failed',
                    'fbr_invoice_number' => null,
                    'message' => __('pos.bill_created_amount', ['number' => $transaction->invoice_number, 'amount' => number_format($transaction->total_amount, 2)]),
                    'warning' => $warningMsg,
                ]);
            }

            return redirect()->route('fbrpos.show', $transaction->id)
                ->with('success', __('pos.bill_created_amount', ['number' => $transaction->invoice_number, 'amount' => number_format($transaction->total_amount, 2)]))
                ->with('warning', $warningMsg);

        } catch (\Illuminate\Validation\ValidationException $ve) {
            // 💵 Field-level validation errors (e.g. cash_received < total) MUST propagate
            // through Laravel's normal error bag so the error appears next to the cash input.
            // Re-throw before the generic Exception catch swallows it. (wantsJson requests
            // automatically get a 422 JSON error bag from the framework.)
            throw $ve;
        } catch (\Exception $e) {
            Log::error('FBR POS Store Error', ['error' => $e->getMessage()]);
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => __('pos.failed_create_sale', ['error' => $e->getMessage()])], 500);
            }
            return back()->withInput()->with('error', __('pos.failed_create_sale', ['error' => $e->getMessage()]));
        }
    }

    public function transactions(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $tab = $request->get('tab', 'fbr');

        $branchSvc = app(\App\Services\BranchContextService::class);
        $query = FbrPosTransaction::where('company_id', $companyId)
            ->tap(fn($q) => $branchSvc->applyToQuery($q))
            ->with('creator');

        if ($tab === 'local') {
            if (!empty($company->confidential_pin) && !$this->isPinSessionValid()) {
                return redirect()->route('fbrpos.transactions', ['tab' => 'fbr'])
                    ->with('error', __('pos.pin_required_local_invoices'));
            }
            $query->where('invoice_mode', 'local');
        } else {
            $query->where(function ($q) {
                $q->where('invoice_mode', 'fbr')->orWhereNull('invoice_mode');
            });
        }

        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(invoice_number) LIKE ?', ['%' . strtolower($search) . '%'])
                    ->orWhereRaw('LOWER(customer_name) LIKE ?', ['%' . strtolower($search) . '%'])
                    ->orWhereRaw('LOWER(fbr_invoice_number) LIKE ?', ['%' . strtolower($search) . '%']);
            });
        }

        if ($request->status) {
            $query->where('fbr_status', $request->status);
        }

        if ($request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $transactions = $query->latest()->paginate(20)->withQueryString();

        $stats = FbrPosTransaction::where('company_id', $companyId)
            ->where(function ($q) use ($tab) {
                if ($tab === 'local') {
                    $q->where('invoice_mode', 'local');
                } else {
                    $q->where('invoice_mode', 'fbr')->orWhereNull('invoice_mode');
                }
            })
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN fbr_status = 'submitted' THEN 1 ELSE 0 END) as submitted,
                SUM(CASE WHEN fbr_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN fbr_status = 'failed' THEN 1 ELSE 0 END) as failed
            ")
            ->first();

        $localCount = FbrPosTransaction::where('company_id', $companyId)
            ->where('invoice_mode', 'local')
            ->count();

        $localRevenue = 0;
        if ($tab === 'local') {
            $localRevenue = FbrPosTransaction::where('company_id', $companyId)
                ->where('invoice_mode', 'local')
                ->sum('total_amount');
        }

        $hasPinSet = !empty($company->confidential_pin);

        return view('fbr-pos.transactions', compact('transactions', 'stats', 'tab', 'localCount', 'localRevenue', 'hasPinSet', 'company'));
    }

    public function show($id)
    {
        $companyId = app('currentCompanyId');
        $transaction = FbrPosTransaction::where('company_id', $companyId)
            ->with(['items', 'creator', 'fbrLogs'])
            ->findOrFail($id);

        if ($transaction->invoice_mode === 'local') {
            $company = Company::find($companyId);
            if (!empty($company->confidential_pin) && !$this->isPinSessionValid()) {
                return redirect()->route('fbrpos.transactions')
                    ->with('error', __('pos.pin_required_view_local_invoices'));
            }
        }

        return view('fbr-pos.show', compact('transaction'));
    }

    public function retryFbr($id)
    {
        $companyId = app('currentCompanyId');
        $transaction = FbrPosTransaction::where('company_id', $companyId)->findOrFail($id);

        if ($transaction->fbr_status === 'submitted') {
            return redirect()->route('fbrpos.show', $id)->with('error', __('pos.already_submitted_fbr'));
        }

        if ($transaction->invoice_mode === 'local') {
            return redirect()->route('fbrpos.show', $id)->with('error', __('pos.local_cannot_submit_fbr'));
        }

        $transaction->fbr_submission_hash = null;
        $transaction->save();

        $transaction->load(['items', 'company']);
        $fbrService = new FbrService();
        $fbrResult = $fbrService->submitFbrPosTransaction($transaction);

        if ($fbrResult['status'] === 'success') {
            return redirect()->route('fbrpos.show', $id)
                ->with('success', __('pos.fbr_submission_successful_num', ['fbr' => $fbrResult['fbr_invoice_number']]));
        }

        $fbrErrors = implode(', ', $fbrResult['errors'] ?? ['Unknown error']);
        return redirect()->route('fbrpos.show', $id)
            ->with('error', __('pos.fbr_retry_failed', ['error' => $fbrErrors]));
    }

    /**
     * ✏️ Edit & Retry — show editable form for a FAILED FBR submission.
     * Cashier can fix the issue (e.g. wrong HS code, wrong tax rate) without regenerating the bill.
     * Only allowed for fbr_status in ['failed', 'pending_verification'] — never for submitted invoices.
     */
    public function editFailed($id)
    {
        $companyId = app('currentCompanyId');
        $transaction = FbrPosTransaction::where('company_id', $companyId)
            ->with(['items', 'fbrLogs' => function ($q) { $q->latest()->limit(1); }])
            ->findOrFail($id);

        if ($transaction->fbr_status === 'submitted') {
            return redirect()->route('fbrpos.show', $id)
                ->with('error', __('pos.already_submitted_no_edit'));
        }

        if ($transaction->invoice_mode === 'local') {
            return redirect()->route('fbrpos.show', $id)
                ->with('error', __('pos.local_no_fbr_retry'));
        }

        // 🔒 Concurrency guard — only allow edits on `failed` (terminal-failed). `pending`/`pending_verification`
        // may have a queued retry job in-flight (RetryFbrPosSubmissionJob), so editing them risks duplicate FBR sends.
        if ($transaction->fbr_status !== 'failed') {
            return redirect()->route('fbrpos.show', $id)
                ->with('error', __('pos.edit_retry_failed_only'));
        }

        $lastError = optional($transaction->fbrLogs->first())->error_message;

        return view('fbr-pos.edit-failed', compact('transaction', 'lastError'));
    }

    /**
     * 💾 Save edits + immediately re-submit to FBR. Snapshots the original line-items to
     * fbr_pos_logs (status='edit_snapshot') for audit before mutating.
     * Recomputes subtotal/tax/total since user may have fixed qty/price/tax_rate.
     */
    public function updateAndRetry(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $transaction = FbrPosTransaction::where('company_id', $companyId)
            ->with('items')
            ->findOrFail($id);

        if ($transaction->fbr_status === 'submitted') {
            return redirect()->route('fbrpos.show', $id)
                ->with('error', __('pos.already_submitted_no_edit'));
        }
        if ($transaction->invoice_mode === 'local') {
            return redirect()->route('fbrpos.show', $id)
                ->with('error', __('pos.local_cannot_submit_fbr'));
        }
        // 🔒 Concurrency guard — only `failed` is editable. `pending`/`pending_verification` may collide
        // with the queued RetryFbrPosSubmissionJob and trigger duplicate FBR submissions.
        if ($transaction->fbr_status !== 'failed') {
            return redirect()->route('fbrpos.show', $id)
                ->with('error', __('pos.edit_retry_failed_only_short'));
        }

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.hs_code' => 'nullable|string|max:20',
            'items.*.uom' => 'nullable|string|in:U,KG,GM,LTR,ML,MTR,SQM,PCS,PKT,DOZ,BOX,SET,BAG,BTL,CTN,ROL,FT,IN,YDS,TIN,CAN,BUN',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_price' => 'required|numeric|min:0.01',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.is_tax_exempt' => 'nullable|boolean',
            'items.*.item_discount' => 'nullable|numeric|min:0',
            'items.*.value_input' => 'nullable|numeric|min:0.01',
            'edit_reason' => 'nullable|string|max:500',
        ]);

        // 🔐 STRICT item integrity check — prevent tampered payloads dropping/adding rows.
        // Every existing item MUST appear in the submitted payload exactly once, and every
        // submitted ID MUST belong to this transaction. No silent skip.
        $existingIds = $transaction->items->pluck('id')->map(fn($v) => (int) $v)->sort()->values()->all();
        $submittedIds = collect($request->items)->pluck('id')->map(fn($v) => (int) $v)->sort()->values()->all();
        if (count($submittedIds) !== count(array_unique($submittedIds))) {
            return redirect()->route('fbrpos.editFailed', $id)
                ->with('error', __('pos.duplicate_item_ids'));
        }
        if ($existingIds !== $submittedIds) {
            $missing = array_diff($existingIds, $submittedIds);
            $extra = array_diff($submittedIds, $existingIds);
            $msg = __('pos.item_set_mismatch');
            if (!empty($missing)) $msg .= __('pos.item_set_missing_ids', ['ids' => implode(',', $missing)]);
            if (!empty($extra))   $msg .= __('pos.item_set_unknown_ids', ['ids' => implode(',', $extra)]);
            return redirect()->route('fbrpos.editFailed', $id)->with('error', $msg);
        }

        // 📜 FULL audit snapshot — items + transaction header pre-state for deterministic rollback
        $editAttemptId = (string) Str::uuid();
        $originalItems = $transaction->items->map(function ($it) {
            return [
                'id' => $it->id,
                'item_name' => $it->item_name,
                'hs_code' => $it->hs_code,
                'uom' => $it->uom,
                'quantity' => (float) $it->quantity,
                'unit_price' => (float) $it->unit_price,
                'tax_rate' => (float) $it->tax_rate,
                'is_tax_exempt' => (bool) $it->is_tax_exempt,
                'item_discount' => (float) $it->item_discount,
                'subtotal' => (float) $it->subtotal,
                'tax_amount' => (float) $it->tax_amount,
                'total' => (float) $it->total,
            ];
        })->all();
        $originalHeader = [
            'subtotal' => (float) $transaction->subtotal,
            'discount_type' => $transaction->discount_type,
            'discount_value' => (float) $transaction->discount_value,
            'discount_amount' => (float) $transaction->discount_amount,
            'tax_amount' => (float) $transaction->tax_amount,
            'fbr_service_charge' => (float) ($transaction->fbr_service_charge ?? 0),
            'loyalty_redemption_amount' => (float) ($transaction->loyalty_redemption_amount ?? 0),
            'total_amount' => (float) $transaction->total_amount,
            'fbr_status' => $transaction->fbr_status,
            'fbr_submission_hash' => $transaction->fbr_submission_hash,
            'fbr_invoice_number' => $transaction->fbr_invoice_number,
        ];

        \App\Models\FbrPosLog::create([
            'company_id' => $companyId,
            'transaction_id' => $transaction->id,
            'request_payload' => [
                'edit_attempt_id' => $editAttemptId,
                'original_items' => $originalItems,
                'original_header' => $originalHeader,
                'submitted_items' => $request->items,
            ],
            'response_payload' => [
                'edit_attempt_id' => $editAttemptId,
                'edited_by_user_id' => Auth::guard('fbrpos')->id(),
                'edit_reason' => $request->edit_reason,
                'edited_at' => now()->toIso8601String(),
            ],
            'response_code' => 0,
            'status' => 'edit_snapshot',
            'error_message' => 'Cashier edited line items before FBR retry (attempt ' . $editAttemptId . ')',
        ]);

        // 🔁 Apply edits — update items, then RECOMPUTE totals from PERSISTED rows (not request)
        $submittedById = collect($request->items)->keyBy(fn($r) => (int) $r['id']);

        DB::transaction(function () use ($transaction, $submittedById) {
            foreach ($transaction->items as $item) {
                $row = $submittedById[$item->id]; // guaranteed present by integrity check above
                $price = (float) $row['unit_price'];
                $uom = strtoupper($row['uom'] ?? 'U');
                $valueInput = isset($row['value_input']) && $row['value_input'] !== ''
                    ? (float) $row['value_input'] : 0;

                // 🎯 VALUE MODE — derive qty from Rs amount (authoritative) for measure UoMs only
                if ($valueInput > 0) {
                    if (!in_array($uom, self::VALUE_MODE_UOMS, true)) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'items' => "Value (Rs) entry only allowed for KG/GM/LTR/ML/MTR/SQM. Got '{$uom}' for item ID #{$item->id}.",
                        ]);
                    }
                    if ($price <= 0) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'items' => "Cannot derive quantity from value: unit price must be > 0 for item ID #{$item->id}.",
                        ]);
                    }
                    $qty = round($valueInput / $price, 4);
                } else {
                    $qty = round((float) $row['quantity'], 4);
                }

                // 🚫 Reject qty <= 0 (no silent fallback to 1)
                if ($qty <= 0) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'items' => "Invalid quantity (must be > 0) for item ID #{$item->id}.",
                    ]);
                }

                // 🚫 Decimal qty NOT allowed for unit-based UoMs
                if (!in_array($uom, self::VALUE_MODE_UOMS, true) && abs($qty - round($qty)) > 0.0001) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'items' => "Decimal quantity not allowed for unit-based UoM '{$uom}' on item ID #{$item->id}. Use whole numbers only.",
                    ]);
                }

                $isExempt = !empty($row['is_tax_exempt']);
                $taxRate = $isExempt ? 0 : (float) ($row['tax_rate'] ?? 18);
                $itemDiscount = round((float) ($row['item_discount'] ?? 0), 2);
                $grossLine = round($price * $qty, 2);
                if ($itemDiscount > $grossLine) $itemDiscount = $grossLine;
                $lineSubtotal = round($grossLine - $itemDiscount, 2);
                $lineTax = round($lineSubtotal * $taxRate / 100, 2);
                $lineTotal = round($lineSubtotal + $lineTax, 2);

                $item->update([
                    'item_name' => $row['item_name'],
                    'hs_code' => $row['hs_code'] ?? null,
                    'uom' => $uom,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'tax_rate' => $taxRate,
                    'is_tax_exempt' => $isExempt,
                    'item_discount' => $itemDiscount,
                    'subtotal' => $lineSubtotal,
                    'tax_amount' => $lineTax,
                    'total' => $lineTotal,
                ]);
            }

            // 🧮 Recompute totals from FRESH DB read so we never trust request math
            $persisted = FbrPosTransactionItem::where('transaction_id', $transaction->id)->get();
            $newSubtotal = round($persisted->sum('subtotal'), 2);
            $newTotalTax = round($persisted->sum('tax_amount'), 2);

            // Re-apply existing transaction-level discount (percentage/fixed) on new subtotal
            $discountAmount = 0;
            if ($transaction->discount_type === 'percentage' && $transaction->discount_value > 0) {
                $discountAmount = round($newSubtotal * (float) $transaction->discount_value / 100, 2);
            } elseif ($transaction->discount_type === 'fixed' && $transaction->discount_value > 0) {
                $discountAmount = min((float) $transaction->discount_value, $newSubtotal);
            }

            $fbrServiceCharge = (float) ($transaction->fbr_service_charge ?? 0);
            $loyaltyRedemption = (float) ($transaction->loyalty_redemption_amount ?? 0);
            $newTotal = round($newSubtotal - $discountAmount + $newTotalTax + $fbrServiceCharge - $loyaltyRedemption, 2);
            if ($newTotal < 0) $newTotal = 0;

            $transaction->update([
                'subtotal' => $newSubtotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => $newTotalTax,
                'total_amount' => $newTotal,
                'fbr_submission_hash' => null, // 🔓 reset so FBR accepts the new payload
            ]);
        });

        // 🚀 Re-submit to FBR with corrected data
        $transaction->refresh()->load(['items', 'company']);
        $fbrService = new FbrService();
        $fbrResult = $fbrService->submitFbrPosTransaction($transaction);

        if ($fbrResult['status'] === 'success') {
            return redirect()->route('fbrpos.show', $id)
                ->with('success', __('pos.edited_submitted_fbr', ['fbr' => $fbrResult['fbr_invoice_number']]));
        }

        $fbrErrors = implode(', ', $fbrResult['errors'] ?? ['Unknown error']);
        return redirect()->route('fbrpos.editFailed', $id)
            ->with('error', __('pos.edits_saved_fbr_rejected', ['error' => $fbrErrors]));
    }

    /**
     * Fail Queue — list of all failed/pending FBR POS transactions for this company
     */
    public function failQueue(Request $request)
    {
        $companyId = app('currentCompanyId');

        $query = FbrPosTransaction::where('company_id', $companyId)
            ->whereIn('fbr_status', ['failed', 'pending'])
            ->where(function ($q) {
                $q->where('invoice_mode', 'fbr')->orWhereNull('invoice_mode');
            })
            ->with(['fbrLogs' => function ($q) {
                $q->latest()->limit(1);
            }]);

        if ($request->search) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->whereRaw('LOWER(invoice_number) LIKE ?', ['%' . strtolower($s) . '%'])
                    ->orWhereRaw('LOWER(customer_name) LIKE ?', ['%' . strtolower($s) . '%']);
            });
        }

        $transactions = $query->latest()->paginate(20)->withQueryString();

        $stats = FbrPosTransaction::where('company_id', $companyId)
            ->where(function ($q) {
                $q->where('invoice_mode', 'fbr')->orWhereNull('invoice_mode');
            })
            ->selectRaw("
                SUM(CASE WHEN fbr_status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                SUM(CASE WHEN fbr_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN fbr_status = 'submitted' THEN 1 ELSE 0 END) as submitted_count,
                SUM(CASE WHEN fbr_status = 'failed' THEN total_amount ELSE 0 END) as failed_amount
            ")
            ->first();

        return view('fbr-pos.fail-queue', compact('transactions', 'stats'));
    }

    /**
     * Bulk retry — schedule auto-retry job for all failed invoices
     */
    public function failQueueRetryAll()
    {
        $companyId = app('currentCompanyId');

        $failed = FbrPosTransaction::where('company_id', $companyId)
            ->whereIn('fbr_status', ['failed', 'pending'])
            ->where(function ($q) {
                $q->where('invoice_mode', 'fbr')->orWhereNull('invoice_mode');
            })
            ->get();

        $count = 0;
        foreach ($failed as $tx) {
            $tx->fbr_submission_hash = null;
            $tx->save();
            \App\Jobs\RetryFbrPosSubmissionJob::dispatch($tx->id)->delay(now()->addSeconds(10));
            $count++;
        }

        return redirect()->route('fbrpos.failQueue')
            ->with('success', __('pos.autoretry_scheduled_failed', ['count' => $count]));
    }

    /**
     * Schedule retry job for a single failed invoice
     */
    public function failQueueRetryOne($id)
    {
        $companyId = app('currentCompanyId');
        $tx = FbrPosTransaction::where('company_id', $companyId)->findOrFail($id);

        if ($tx->fbr_status === 'submitted') {
            return back()->with('error', __('pos.already_submitted_fbr_short'));
        }

        $tx->fbr_submission_hash = null;
        $tx->save();
        \App\Jobs\RetryFbrPosSubmissionJob::dispatch($tx->id)->delay(now()->addSeconds(5));

        return back()->with('success', __('pos.autoretry_scheduled_one', ['number' => $tx->invoice_number]));
    }

    public function fbrSettings(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $user = Auth::guard('fbrpos')->user();

        if ($user->role !== 'company_admin') {
            return back()->with('error', __('pos.only_company_admin_fbr_settings'));
        }

        if ($request->isMethod('post')) {
            if ($request->has('pin_update')) {
                if ($request->has('remove_pin')) {
                    $company->update(['confidential_pin' => null]);
                    return back()->with('success', __('pos.confidential_pin_removed'));
                }

                if ($request->filled('confidential_pin')) {
                    $request->validate(['confidential_pin' => 'required|digits_between:4,6']);
                    $company->update(['confidential_pin' => \Hash::make($request->confidential_pin)]);
                    return back()->with('success', __('pos.confidential_pin_updated'));
                }

                return back()->with('error', __('pos.enter_valid_pin'));
            }

            // Regenerate the Desktop Sync Agent API key (Fiscal Device mode). Invalidates the old key,
            // so any agent using the previous key must be reconnected with the new one.
            if ($request->has('regenerate_agent_key')) {
                $company->update([
                    'fbr_connection_mode' => 'fiscal_device',
                    'agent_enabled' => true,
                    'agent_api_key' => 'tnk_' . \Illuminate\Support\Str::random(48),
                ]);
                return back()->with('success', __('pos.agent_key_regenerated'));
            }

            $request->validate([
                'fbr_pos_environment' => 'required|in:sandbox,production',
                'fbr_connection_mode' => 'nullable|in:cloud,fiscal_device',
                'fbr_pos_id' => 'nullable|string|max:100',
                'fbr_pos_token' => 'nullable|string|max:2000',
                'fbr_access_code' => 'nullable|string|max:500',
            ]);

            $updateData = [
                'fbr_pos_environment' => $request->fbr_pos_environment,
            ];

            if ($request->filled('fbr_connection_mode')) {
                $updateData['fbr_connection_mode'] = $request->fbr_connection_mode;

                // Fiscal Device submissions only happen from the shop PC — the desktop agent is
                // mandatory (FBR retired cloud bulk PostData, Code 112). Auto-enable it + mint a key.
                if ($request->fbr_connection_mode === 'fiscal_device') {
                    $updateData['agent_enabled'] = true;
                    if (empty($company->agent_api_key)) {
                        $updateData['agent_api_key'] = 'tnk_' . \Illuminate\Support\Str::random(48);
                    }
                }
            }

            if ($request->filled('fbr_pos_id')) {
                $updateData['fbr_pos_id'] = $request->fbr_pos_id;
            }

            if ($request->filled('fbr_pos_token')) {
                $updateData['fbr_pos_token'] = Crypt::encryptString(trim($request->fbr_pos_token));
            }

            // IRIS "Point of Sale Registration" grid Access Code — needed once at FBRIMS
            // (fiscal component) install time. Stored encrypted so the cloud-VPS setup can be
            // done without asking the client again.
            if ($request->filled('fbr_access_code')) {
                $updateData['fbr_access_code'] = Crypt::encryptString(trim($request->fbr_access_code));
            }

            $company->update($updateData);

            return back()->with('success', __('pos.fbr_settings_updated'));
        }

        $fbrLogs = FbrPosLog::where('company_id', $companyId)->orderBy('created_at', 'desc')->take(20)->get();

        $posToken = '';
        if ($company->fbr_pos_token) {
            try { $posToken = Crypt::decryptString($company->fbr_pos_token); } catch (\Exception $e) { $posToken = $company->fbr_pos_token; }
        }
        $maskedPosToken = $posToken ? substr($posToken, 0, 8) . '****' . substr($posToken, -4) : '';

        $accessCode = '';
        if ($company->fbr_access_code) {
            try { $accessCode = Crypt::decryptString($company->fbr_access_code); } catch (\Exception $e) { $accessCode = $company->fbr_access_code; }
        }
        $maskedAccessCode = $accessCode ? substr($accessCode, 0, 3) . '****' . substr($accessCode, -2) : '';

        $hasSandboxFallback = !empty($company->fbr_sandbox_token);
        $hasProductionFallback = !empty($company->fbr_production_token);

        return view('fbr-pos.settings', compact('company', 'fbrLogs', 'maskedPosToken', 'maskedAccessCode', 'hasSandboxFallback', 'hasProductionFallback'));
    }

    public function testConnection()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') {
            return response()->json(['success' => false, 'message' => __('pos.only_company_admin_test_connection')]);
        }

        $env = $company->fbr_pos_environment ?? 'sandbox';
        $fbrService = new FbrService();

        $ref = new \ReflectionMethod($fbrService, 'getFbrPosToken');
        $ref->setAccessible(true);
        $token = $ref->invoke($fbrService, $company);

        if (empty($token)) {
            return response()->json([
                'success' => false,
                'message' => __('pos.no_token_configured', ['env' => $env]),
            ]);
        }

        $urlRef = new \ReflectionMethod($fbrService, 'getFbrPosUrl');
        $urlRef->setAccessible(true);
        $url = $urlRef->invoke($fbrService, $company);

        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                return response()->json([
                    'success' => false,
                    'message' => __('pos.connection_failed_detail', ['error' => $curlError]),
                ]);
            }

            $body = (string) $response;
            $faultCode = null;
            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['fault']['code'])) {
                $faultCode = (string) $decoded['fault']['code'];
            } elseif (preg_match('/\b(9009\d{2})\b/', $body, $m)) {
                $faultCode = $m[1];
            }

            $envLabel = $env === 'production' ? __('pos.env_production_live') : __('pos.env_sandbox_testing');
            $otherLabel = $env === 'production' ? __('pos.env_sandbox') : __('pos.env_production');

            if ($faultCode === '900901' || $httpCode === 401) {
                return response()->json([
                    'success' => false,
                    'message' => __('pos.fbr_token_rejected_env', ['envLabel' => $envLabel, 'otherLabel' => $otherLabel]),
                ]);
            }

            if ($faultCode === '900908') {
                return response()->json([
                    'success' => false,
                    'message' => __('pos.fbr_api_not_enabled', ['envLabel' => $envLabel]),
                ]);
            }

            if ($faultCode === '900902') {
                return response()->json([
                    'success' => false,
                    'message' => __('pos.fbr_no_token_received'),
                ]);
            }

            if ($faultCode !== null) {
                return response()->json([
                    'success' => false,
                    'message' => __('pos.fbr_gateway_rejected', ['code' => $faultCode, 'envLabel' => $envLabel]),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => __('pos.fbr_token_accepted', ['envLabel' => $envLabel, 'code' => $httpCode]),
                'environment' => $env,
                'http_code' => $httpCode,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => __('pos.connection_error', ['error' => $e->getMessage()]),
            ]);
        }
    }

    public function toggleFbrReporting()
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') {
            return response()->json(['success' => false, 'message' => __('pos.only_company_admin_toggle_fbr')], 403);
        }

        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $company->fbr_reporting_enabled = !$company->fbr_reporting_enabled;
        $company->save();

        return response()->json([
            'success' => true,
            'enabled' => $company->fbr_reporting_enabled,
            'message' => $company->fbr_reporting_enabled ? __('pos.fbr_reporting_enabled_msg') : __('pos.fbr_reporting_disabled_msg'),
        ]);
    }

    /**
     * 🌐 Toggle the FBR Universal sale screen (admin-only).
     * Classic create screen remains the fallback whenever this is OFF.
     */
    public function toggleUniversal()
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') {
            return response()->json(['success' => false, 'message' => __('pos.only_company_admin_toggle_universal')], 403);
        }

        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $company->fbr_universal_enabled = !$company->fbr_universal_enabled;
        $company->save();

        return response()->json([
            'success' => true,
            'enabled' => (bool) $company->fbr_universal_enabled,
            'message' => $company->fbr_universal_enabled
                ? __('pos.universal_enabled')
                : __('pos.universal_disabled'),
        ]);
    }

    /**
     * 🎛️ Customize FBR POS — single consolidated settings hub (admin-only).
     * Mirrors the PRA POS Customize page but stays in the blue-X theme family
     * so the FBR layout's accent-remap engine themes it correctly.
     */
    public function customize(Request $request)
    {
        $user = Auth::guard('fbrpos')->user();
        if (!$user || $user->role !== 'company_admin') {
            abort(403, 'Only company admin can customize FBR POS.');
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        if (!$company) {
            abort(404);
        }
        return view('fbr-pos.customize', compact('company'));
    }

    /**
     * 🎯 Toggle guided keyboard billing flow (admin-only) — Enter-driven fast
     * billing that the Universal sale screen reads via pos_guided_flow_enabled.
     */
    public function updateGuidedFlow(Request $request)
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') {
            return response()->json(['success' => false, 'message' => __('pos.only_company_admin_change_setting')], 403);
        }
        $enabled = $request->boolean('enabled');
        $companyId = app('currentCompanyId');
        Company::where('id', $companyId)->update(['pos_guided_flow_enabled' => $enabled]);
        return response()->json(['success' => true, 'enabled' => $enabled]);
    }

    public function verifyPin(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if (empty($company->confidential_pin)) {
            session(['fbr_pos_pin_verified' => true, 'fbr_pos_pin_verified_at' => now()->timestamp]);
            return response()->json(['success' => true, 'message' => __('pos.no_pin_set_access_granted')]);
        }

        $cacheKey = "fbrpos_pin_lockout_{$companyId}";
        $attemptsKey = "fbrpos_pin_attempts_{$companyId}";

        if (cache()->get($cacheKey)) {
            $remaining = (int) ceil((cache()->get($cacheKey) - now()->timestamp) / 60);
            return response()->json([
                'success' => false,
                'message' => __('pos.account_locked_minutes', ['minutes' => $remaining]),
            ], 429);
        }

        $pin = $request->input('pin', '');

        if (!\Hash::check($pin, $company->confidential_pin)) {
            $attempts = (int) cache()->get($attemptsKey, 0) + 1;
            cache()->put($attemptsKey, $attempts, 900);

            if ($attempts >= 5) {
                cache()->put($cacheKey, now()->addMinutes(15)->timestamp, 900);
                cache()->forget($attemptsKey);
                return response()->json([
                    'success' => false,
                    'message' => __('pos.too_many_failed_locked'),
                ], 429);
            }

            return response()->json([
                'success' => false,
                'message' => __('pos.incorrect_pin_remaining', ['count' => 5 - $attempts]),
            ]);
        }

        cache()->forget($attemptsKey);
        session(['fbr_pos_pin_verified' => true, 'fbr_pos_pin_verified_at' => now()->timestamp]);

        return response()->json(['success' => true, 'message' => __('pos.pin_verified')]);
    }

    public function checkPinSession()
    {
        return response()->json(['verified' => $this->isPinSessionValid()]);
    }

    private function isPinSessionValid(): bool
    {
        $verified = session('fbr_pos_pin_verified', false);
        $verifiedAt = session('fbr_pos_pin_verified_at', 0);
        return $verified && (now()->timestamp - $verifiedAt) < 1800;
    }

    private function generateInvoiceNumber(int $companyId): string
    {
        $year = now()->format('Y');
        $prefix = "FPOS-{$year}-";

        $lastInvoice = FbrPosTransaction::where('company_id', $companyId)
            ->where('invoice_number', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('invoice_number');

        if ($lastInvoice) {
            $lastNum = (int) str_replace($prefix, '', $lastInvoice);
            $nextNum = $lastNum + 1;
        } else {
            $nextNum = 1;
        }

        return $prefix . str_pad($nextNum, 5, '0', STR_PAD_LEFT);
    }

    private function generateLocalInvoiceNumber(int $companyId): string
    {
        $year = now()->format('Y');
        $prefix = "FLOCAL-{$year}-";

        $lastInvoice = FbrPosTransaction::where('company_id', $companyId)
            ->where('invoice_number', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('invoice_number');

        if ($lastInvoice) {
            $lastNum = (int) str_replace($prefix, '', $lastInvoice);
            $nextNum = $lastNum + 1;
        } else {
            $nextNum = 1;
        }

        return $prefix . str_pad($nextNum, 5, '0', STR_PAD_LEFT);
    }

    public function billing()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $plans = \App\Models\PricingPlan::where('is_trial', false)->where('product_type', 'fbrpos')->orderBy('price')->get();
        $currentSubscription = \App\Models\Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->with('pricingPlan')
            ->first();

        return view('fbr-pos.billing', compact('company', 'plans', 'currentSubscription'));
    }

    public function receipt($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $transaction = FbrPosTransaction::where('company_id', $companyId)
            ->with(['items', 'creator'])
            ->findOrFail($id);

        return view('fbr-pos.receipt', compact('transaction', 'company'));
    }

    public function reports(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $todayStats = FbrPosTransaction::where('company_id', $companyId)
            ->whereDate('created_at', today())
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue, COALESCE(SUM(tax_amount), 0) as tax, COALESCE(SUM(discount_amount), 0) as discount')
            ->first();

        $monthStats = FbrPosTransaction::where('company_id', $companyId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue, COALESCE(SUM(tax_amount), 0) as tax, COALESCE(SUM(discount_amount), 0) as discount')
            ->first();

        $dailySales = FbrPosTransaction::where('company_id', $companyId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get();

        $paymentBreakdown = FbrPosTransaction::where('company_id', $companyId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->selectRaw('payment_method, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue')
            ->groupBy('payment_method')
            ->get();

        [$from, $to] = $this->resolveFbrReportRange($request);
        $rangeAnalytics = $this->buildFbrReportRangeAnalytics($companyId, $from, $to, Auth::guard('fbrpos')->user());

        return view('fbr-pos.reports', compact('company', 'todayStats', 'monthStats', 'dailySales', 'paymentBreakdown', 'rangeAnalytics'));
    }

    /**
     * A4 PDF export of the range analytics (FBR mirror of the PRA version).
     */
    public function reportsAnalyticsPdf(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        [$from, $to] = $this->resolveFbrReportRange($request);
        $analytics = $this->buildFbrReportRangeAnalytics($companyId, $from, $to, Auth::guard('fbrpos')->user());

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('fbr-pos.reports-analytics-pdf', compact('company', 'analytics'));
        $pdf->setPaper('a4', 'portrait');

        return $pdf->download('FBR-Sales-Analytics-' . $analytics->from . '-to-' . $analytics->to . '.pdf');
    }

    /**
     * Shared range parsing for the reports analytics surfaces: defaults to the
     * current month, swaps reversed inputs, caps the window at 366 days.
     *
     * @return array{0:\Carbon\Carbon,1:\Carbon\Carbon}
     */
    private function resolveFbrReportRange(Request $request): array
    {
        try {
            $from = $request->filled('from') ? \Carbon\Carbon::parse($request->input('from'))->startOfDay() : now()->startOfMonth();
        } catch (\Throwable) {
            $from = now()->startOfMonth();
        }
        try {
            $to = $request->filled('to') ? \Carbon\Carbon::parse($request->input('to'))->endOfDay() : now()->endOfDay();
        } catch (\Throwable) {
            $to = now()->endOfDay();
        }
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366)->startOfDay();
        }

        return [$from, $to];
    }

    /**
     * Range analytics for the FBR POS Reports page (owner request Jul 2026 —
     * mirror of the PRA version): date-window deep dive — product breakdown
     * (the `products` table has NO category column, so no category layer),
     * profit (ADMIN-ONLY, products.cost_price based, coverage-aware), previous-
     * period comparison, daily + hourly chart data, cashier performance, top
     * customers, payment split, FBR submission health.
     */
    private function buildFbrReportRangeAnalytics(int $companyId, \Carbon\Carbon $from, \Carbon\Carbon $to, $user): object
    {
        $isAdminView = $user && $user->role === 'company_admin';

        $transactions = FbrPosTransaction::where('company_id', $companyId)
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'created_at', 'created_by', 'customer_id', 'customer_name', 'customer_phone', 'subtotal', 'total_amount', 'tax_amount', 'discount_amount', 'payment_method', 'fbr_status']);

        $ids = $transactions->pluck('id')->all();
        $items = empty($ids) ? collect() : FbrPosTransactionItem::whereIn('transaction_id', $ids)
            ->get(['transaction_id', 'product_id', 'item_name', 'quantity', 'subtotal', 'tax_amount', 'item_discount', 'promotion_discount']);

        // Cost resolution (company-scoped product lookup).
        $productIds = $items->pluck('product_id')->filter()->unique()->values();
        $productMap = $productIds->isEmpty() ? collect() : Product::where('company_id', $companyId)
            ->whereIn('id', $productIds)->get(['id', 'cost_price'])->keyBy('id');

        $items->each(function ($it) use ($productMap, $isAdminView) {
            $cost = null;
            if ($isAdminView && $it->product_id) {
                $p = $productMap[$it->product_id] ?? null;
                if ($p && $p->cost_price !== null && (float) $p->cost_price > 0) {
                    $cost = (float) $p->cost_price * (float) $it->quantity;
                }
            }
            $it->resolved_cost = $cost;
        });

        $revenueTotal = (float) $items->sum('subtotal');
        $products = $items->groupBy('item_name')->map(function ($g) use ($revenueTotal, $isAdminView) {
            $revenue = (float) $g->sum('subtotal');
            $withCost = $g->filter(fn ($it) => $it->resolved_cost !== null);
            $cost = (float) $withCost->sum('resolved_cost');
            $costedRevenue = (float) $withCost->sum('subtotal');
            return (object) [
                'qty' => (float) $g->sum('quantity'),
                'revenue' => $revenue,
                'tax' => (float) $g->sum('tax_amount'),
                'share' => $revenueTotal > 0 ? round($revenue / $revenueTotal * 100, 1) : 0,
                'profit' => ($isAdminView && $withCost->isNotEmpty()) ? round($costedRevenue - $cost, 2) : null,
            ];
        })->sortByDesc('revenue')->take(25);

        // Profit summary (ADMIN-ONLY): only items whose product has a cost_price
        // set count toward cost — coverage_pct tells the admin how complete it is.
        $profit = null;
        if ($isAdminView) {
            $withCost = $items->filter(fn ($it) => $it->resolved_cost !== null);
            $cost = (float) $withCost->sum('resolved_cost');
            $costedRevenue = (float) $withCost->sum('subtotal');
            $productQty = (float) $items->where('product_id', '!=', null)->sum('quantity');
            $costedQty = (float) $withCost->sum('quantity');
            $profit = (object) [
                'cost' => $cost,
                'revenue' => $costedRevenue,
                'profit' => round($costedRevenue - $cost, 2),
                'margin_pct' => $costedRevenue > 0 ? round(($costedRevenue - $cost) / $costedRevenue * 100, 1) : null,
                'coverage_pct' => $productQty > 0 ? (int) round($costedQty / $productQty * 100) : 0,
            ];
        }

        // Daily trend — zero-filled so the chart x-axis has every day of the range.
        $daily = [];
        $cursor = $from->copy()->startOfDay();
        $endDay = $to->copy()->startOfDay();
        while ($cursor->lte($endDay)) {
            $daily[$cursor->toDateString()] = (object) ['count' => 0, 'revenue' => 0.0];
            $cursor->addDay();
        }
        foreach ($transactions as $t) {
            $d = $t->created_at?->toDateString();
            if ($d !== null && isset($daily[$d])) {
                $daily[$d]->count++;
                $daily[$d]->revenue += (float) $t->total_amount;
            }
        }

        $hourly = [];
        for ($h = 0; $h < 24; $h++) {
            $hourly[$h] = (object) ['count' => 0, 'revenue' => 0.0];
        }
        foreach ($transactions as $t) {
            if (!$t->created_at) {
                continue;
            }
            $h = (int) $t->created_at->format('G');
            $hourly[$h]->count++;
            $hourly[$h]->revenue += (float) $t->total_amount;
        }

        $cashierNames = \App\Models\User::where('company_id', $companyId)->pluck('name', 'id');
        $cashiers = $transactions->groupBy('created_by')->map(function ($g) use ($cashierNames) {
            $revenue = (float) $g->sum('total_amount');
            return (object) [
                'name' => $cashierNames[$g->first()->created_by] ?? 'Unknown',
                'count' => $g->count(),
                'revenue' => $revenue,
                'tax' => (float) $g->sum('tax_amount'),
                'avg' => round($revenue / max(1, $g->count()), 2),
            ];
        })->sortByDesc('revenue')->values();

        $topCustomers = $transactions
            ->filter(fn ($t) => $t->customer_id || !empty($t->customer_phone) || trim((string) $t->customer_name) !== '')
            ->groupBy(fn ($t) => $t->customer_id ?: ($t->customer_phone ?: mb_strtolower(trim((string) $t->customer_name))))
            ->map(function ($g) {
                $t0 = $g->first();
                return (object) [
                    'name' => trim((string) $t0->customer_name) !== '' ? $t0->customer_name : ($t0->customer_phone ?: ('Customer #' . $t0->customer_id)),
                    'count' => $g->count(),
                    'revenue' => (float) $g->sum('total_amount'),
                ];
            })->sortByDesc('revenue')->take(10)->values();

        $payments = $transactions->groupBy('payment_method')->map(function ($g) {
            return (object) [
                'count' => $g->count(),
                'revenue' => (float) $g->sum('total_amount'),
            ];
        })->sortByDesc('revenue');

        // FBR submission health across the range.
        $fbrHealth = (object) [
            'submitted' => $transactions->where('fbr_status', 'submitted')->count(),
            'pending' => $transactions->where('fbr_status', 'pending')->count(),
            'failed' => $transactions->where('fbr_status', 'failed')->count(),
            'local' => $transactions->where('fbr_status', 'local')->count(),
        ];

        // Previous equal-length period (immediately before the range).
        $days = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $prevFrom = $from->copy()->subDays($days)->startOfDay();
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevRow = FbrPosTransaction::where('company_id', $companyId)
            ->whereBetween('created_at', [$prevFrom, $prevTo])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as revenue, COALESCE(SUM(tax_amount),0) as tax')
            ->first();
        $pct = function (float $prev, float $cur): ?float {
            return $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : null;
        };

        $revenue = (float) $transactions->sum('total_amount');
        $tax = (float) $transactions->sum('tax_amount');
        $billCount = $transactions->count();
        $summary = (object) [
            'bills' => $billCount,
            'revenue' => $revenue,
            'tax' => $tax,
            'discount' => (float) $transactions->sum('discount_amount') + (float) $items->sum('item_discount') + (float) $items->sum('promotion_discount'),
            'avg_bill' => $billCount > 0 ? $revenue / $billCount : 0.0,
            'unique_customers' => $transactions
                ->filter(fn ($t) => $t->customer_id || !empty($t->customer_phone) || trim((string) $t->customer_name) !== '')
                ->unique(fn ($t) => $t->customer_id ?: ($t->customer_phone ?: mb_strtolower(trim((string) $t->customer_name))))
                ->count(),
        ];
        $previous = (object) [
            'from' => $prevFrom->toDateString(),
            'to' => $prevTo->toDateString(),
            'bills' => (int) ($prevRow->cnt ?? 0),
            'revenue' => (float) ($prevRow->revenue ?? 0),
            'tax' => (float) ($prevRow->tax ?? 0),
            'revenue_pct' => $pct((float) ($prevRow->revenue ?? 0), $revenue),
            'bills_pct' => $pct((float) ($prevRow->cnt ?? 0), (float) $billCount),
            'tax_pct' => $pct((float) ($prevRow->tax ?? 0), $tax),
        ];

        return (object) [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => $summary,
            'previous' => $previous,
            'products' => $products,
            'profit' => $profit,
            'is_admin_view' => $isAdminView,
            'daily' => $daily,
            'hourly' => $hourly,
            'cashiers' => $cashiers,
            'top_customers' => $topCustomers,
            'payments' => $payments,
            'fbr_health' => $fbrHealth,
        ];
    }

    public function taxReports()
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $monthlyTax = FbrPosTransaction::where('company_id', $companyId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->selectRaw('COALESCE(SUM(tax_amount), 0) as total_tax, COALESCE(SUM(subtotal), 0) as total_sales, COALESCE(SUM(fbr_service_charge), 0) as total_pos_fee, COUNT(*) as invoice_count')
            ->first();

        $fbrStats = FbrPosTransaction::where('company_id', $companyId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->selectRaw("
                COUNT(CASE WHEN fbr_status = 'submitted' THEN 1 END) as submitted,
                COUNT(CASE WHEN fbr_status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN fbr_status = 'failed' THEN 1 END) as failed,
                COUNT(CASE WHEN fbr_status = 'local' THEN 1 END) as local_count
            ")->first();

        $taxByRate = FbrPosTransaction::where('company_id', $companyId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->selectRaw('tax_rate, COUNT(*) as count, COALESCE(SUM(tax_amount), 0) as tax_total, COALESCE(SUM(subtotal), 0) as sales_total')
            ->groupBy('tax_rate')
            ->orderBy('tax_rate')
            ->get();

        return view('fbr-pos.tax-reports', compact('company', 'monthlyTax', 'fbrStats', 'taxByRate'));
    }

    public function businessProfile(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if ($request->isMethod('post')) {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'address' => 'nullable|string|max:500',
                'phone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:255',
                'ntn' => 'nullable|string|max:20',
                'print_paper_size' => 'nullable|in:thermal,thermal58,a4',
                'kot_align_center' => 'nullable|in:0,1',
                'kot_left_margin_mm' => 'nullable|integer|min:0|max:30',
                'receipt_footer_note' => 'nullable|string|max:255',
                'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
                'remove_logo' => 'nullable|boolean',
            ]);

            // Handle logo upload / removal
            if ($request->boolean('remove_logo') && $company->logo_path) {
                \Storage::disk('public')->delete($company->logo_path);
                $company->logo_path = null;
            }
            if ($request->hasFile('logo')) {
                if ($company->logo_path) {
                    \Storage::disk('public')->delete($company->logo_path);
                }
                $company->logo_path = $request->file('logo')->store('company-logos', 'public');
            }

            // Receipt Display toggles (owner, 22 Jul 2026): stored under the
            // 'fbrpos' key of invoice_display_prefs — same generic set the PRA
            // receipt-settings page uses ('pos'/'pos_local' keys untouched).
            $prefs = $company->invoice_display_prefs ?? [];
            $prefs['fbrpos'] = [
                'show_address' => $request->has('rd_show_address'),
                'show_ntn' => $request->has('rd_show_ntn'),
                'show_mobile' => $request->has('rd_show_phone'),
                'show_cashier' => $request->has('rd_show_cashier'),
                'show_footer' => $request->has('rd_show_footer'),
            ];

            // Print position (31 Jul 2026 — mirrors PRA slips): opt-in center /
            // left-margin correction. hasColumn guards = prod self-heal parity.
            if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'kot_align_center')) {
                $company->kot_align_center = (bool) $request->input('kot_align_center');
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'kot_left_margin_mm')) {
                $company->kot_left_margin_mm = max(0, min(30, (int) $request->input('kot_left_margin_mm', 0)));
            }

            $company->fill([
                'name' => $validated['name'],
                'address' => $validated['address'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,
                'ntn' => $validated['ntn'] ?? null,
                'print_paper_size' => $validated['print_paper_size'] ?? 'thermal',
                // Print position (31 Jul 2026): shared company columns with PRA slips.
                'kot_align_center' => (bool) $request->input('kot_align_center', false),
                'kot_left_margin_mm' => max(0, min(30, (int) $request->input('kot_left_margin_mm', 0))),
                'receipt_footer_note' => $validated['receipt_footer_note'] ?? null,
                'invoice_display_prefs' => $prefs,
            ])->save();

            return redirect()->route('fbrpos.business-profile')->with('success', __('pos.business_profile_updated'));
        }

        return view('fbr-pos.business-profile', compact('company'));
    }

    public function myProfile(Request $request)
    {
        $user = Auth::guard('fbrpos')->user();

        if ($request->isMethod('post')) {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255|unique:users,email,' . $user->id,
                'phone' => 'nullable|string|max:20',
                'username' => 'nullable|string|max:100|unique:users,username,' . $user->id,
                'current_password' => 'nullable|required_with:new_password',
                'new_password' => 'nullable|min:8|confirmed',
            ]);

            $user->name = $validated['name'];
            $user->email = $validated['email'];
            $user->phone = $validated['phone'] ?? $user->phone;
            $user->username = $validated['username'] ?? $user->username;

            if (!empty($validated['current_password'])) {
                if (!\Hash::check($validated['current_password'], $user->password)) {
                    return back()->withErrors(['current_password' => __('pos.current_password_incorrect')]);
                }
                $user->password = \Hash::make($validated['new_password']);
            }

            $user->save();
            return redirect()->route('fbrpos.my-profile')->with('success', __('pos.profile_updated_success'));
        }

        return view('fbr-pos.my-profile', compact('user'));
    }

    public function downloadPdf($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $transaction = FbrPosTransaction::where('company_id', $companyId)
            ->with(['items', 'creator'])
            ->findOrFail($id);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('fbr-pos.invoice-pdf', compact('transaction', 'company'));
        $pdf->setPaper('a4', 'portrait');

        return $pdf->download("FBR-POS-Invoice-{$transaction->invoice_number}.pdf");
    }

    public function previewPdf($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $transaction = FbrPosTransaction::where('company_id', $companyId)
            ->with(['items', 'creator'])
            ->findOrFail($id);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('fbr-pos.invoice-pdf', compact('transaction', 'company'));
        $pdf->setPaper('a4', 'portrait');

        return $pdf->stream("FBR-POS-Invoice-{$transaction->invoice_number}.pdf");
    }

    public function dayCloseReport(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $date = $request->get('date', today()->format('Y-m-d'));

        $existingReport = FbrDayCloseReport::where('company_id', $companyId)
            ->where('report_date', $date)
            ->first();

        $transactions = FbrPosTransaction::where('company_id', $companyId)
            ->whereDate('created_at', $date)
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        $stats = (object) [
            'total_invoices' => $transactions->count(),
            'fbr_invoices' => $transactions->where('fbr_status', 'submitted')->count(),
            'local_invoices' => $transactions->where('fbr_status', 'local')->count(),
            'failed_invoices' => $transactions->whereIn('fbr_status', ['failed', 'pending'])->count(),
            'gross_sales' => $transactions->sum('subtotal'),
            'total_discount' => $transactions->sum('discount_amount'),
            'net_sales' => $transactions->sum('subtotal') - $transactions->sum('discount_amount'),
            'total_tax' => $transactions->sum('tax_amount'),
            'total_fbr_fee' => $transactions->sum('fbr_service_charge'),
            'total_amount' => $transactions->sum('total_amount'),
            'cash_amount' => $transactions->where('payment_method', 'cash')->sum('total_amount'),
            // Card bucket includes stored aliases (debit/credit card) so card sales
            // never silently land in "Other" — mirrors the PRA POS day-close fix.
            'card_amount' => $transactions->whereIn('payment_method', ['card', 'debit_card', 'credit_card'])->sum('total_amount'),
            'other_amount' => $transactions->whereNotIn('payment_method', ['cash', 'card', 'debit_card', 'credit_card'])->sum('total_amount'),
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

        $previousReports = FbrDayCloseReport::where('company_id', $companyId)
            ->orderBy('report_date', 'desc')
            ->limit(10)
            ->get();

        $analytics = $this->buildFbrDayCloseAnalytics($companyId, $date, $transactions);

        return view('fbr-pos.day-close', compact('company', 'date', 'stats', 'existingReport', 'cashierBreakdown', 'previousReports', 'transactions', 'analytics'));
    }

    /**
     * Comprehensive day-close analytics (owner request Jul 2026 — FBR mirror of
     * the PRA version) shared by the day-close page, A4 PDF and 80mm thermal
     * Z-report: top products, hourly breakdown, FBR submission health, discount
     * summary, averages and yesterday / last-week comparisons. Pure read.
     * NOTE: `products` table has NO category column, so the FBR side reports a
     * product breakdown instead of a category breakdown.
     */
    private function buildFbrDayCloseAnalytics(int $companyId, string $date, $transactions): object
    {
        $ids = $transactions->pluck('id')->all();

        $items = empty($ids) ? collect() : FbrPosTransactionItem::whereIn('transaction_id', $ids)
            ->get(['transaction_id', 'product_id', 'item_name', 'quantity', 'subtotal', 'tax_amount', 'item_discount', 'promotion_discount']);

        $itemRevenueTotal = (float) $items->sum('subtotal');
        $topProducts = $items->groupBy('item_name')->map(function ($g) use ($itemRevenueTotal) {
            $revenue = (float) $g->sum('subtotal');
            return (object) [
                'qty' => (float) $g->sum('quantity'),
                'revenue' => $revenue,
                'tax' => (float) $g->sum('tax_amount'),
                'share' => $itemRevenueTotal > 0 ? round($revenue / $itemRevenueTotal * 100, 1) : 0,
            ];
        })->sortByDesc('revenue')->take(10);

        // Hourly sales — full 24-slot map so the chart x-axis stays stable.
        $hourly = [];
        for ($h = 0; $h < 24; $h++) {
            $hourly[$h] = (object) ['count' => 0, 'revenue' => 0.0];
        }
        foreach ($transactions as $t) {
            if (!$t->created_at) {
                continue;
            }
            $h = (int) $t->created_at->format('G');
            $hourly[$h]->count++;
            $hourly[$h]->revenue += (float) $t->total_amount;
        }

        // FBR submission health — every pipeline state at a glance.
        $fbrHealth = (object) [
            'submitted' => $transactions->where('fbr_status', 'submitted')->count(),
            'pending' => $transactions->where('fbr_status', 'pending')->count(),
            'failed' => $transactions->where('fbr_status', 'failed')->count(),
            'local' => $transactions->where('fbr_status', 'local')->count(),
        ];

        $discountBills = $transactions->filter(fn ($t) => (float) $t->discount_amount > 0);
        $itemDiscountTotal = (float) $items->sum('item_discount') + (float) $items->sum('promotion_discount');
        $discounts = (object) [
            'bill_count' => $discountBills->count(),
            'bill_total' => (float) $discountBills->sum('discount_amount'),
            'item_total' => $itemDiscountTotal,
            'total' => (float) $discountBills->sum('discount_amount') + $itemDiscountTotal,
        ];

        $billCount = $transactions->count();
        $avgBill = $billCount > 0 ? (float) $transactions->sum('total_amount') / $billCount : 0.0;
        $uniqueCustomers = $transactions
            ->filter(fn ($t) => $t->customer_id || !empty($t->customer_phone) || !empty($t->customer_name))
            ->unique(fn ($t) => $t->customer_id ?: ($t->customer_phone ?: mb_strtolower(trim((string) $t->customer_name))))
            ->count();

        // Yesterday + same-day-last-week comparison.
        $compareFor = function (string $cmpDate) use ($companyId) {
            $row = FbrPosTransaction::where('company_id', $companyId)
                ->whereDate('created_at', $cmpDate)
                ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as revenue, COALESCE(SUM(tax_amount),0) as tax')
                ->first();
            return (object) [
                'date' => $cmpDate,
                'invoices' => (int) ($row->cnt ?? 0),
                'revenue' => (float) ($row->revenue ?? 0),
                'tax' => (float) ($row->tax ?? 0),
            ];
        };
        $todayRevenue = (float) $transactions->sum('total_amount');
        $pct = function (float $prev, float $cur): ?float {
            return $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : null;
        };
        $yesterday = $compareFor(\Carbon\Carbon::parse($date)->subDay()->toDateString());
        $lastWeek = $compareFor(\Carbon\Carbon::parse($date)->subDays(7)->toDateString());
        $comparison = (object) [
            'yesterday' => $yesterday,
            'last_week' => $lastWeek,
            'vs_yesterday_revenue_pct' => $pct($yesterday->revenue, $todayRevenue),
            'vs_yesterday_invoices_pct' => $pct((float) $yesterday->invoices, (float) $billCount),
            'vs_last_week_revenue_pct' => $pct($lastWeek->revenue, $todayRevenue),
            'vs_last_week_invoices_pct' => $pct((float) $lastWeek->invoices, (float) $billCount),
        ];

        return (object) [
            'top_products' => $topProducts,
            'hourly' => $hourly,
            'fbr_health' => $fbrHealth,
            'discounts' => $discounts,
            'avg_bill' => $avgBill,
            'unique_customers' => $uniqueCustomers,
            'comparison' => $comparison,
        ];
    }

    public function closeDayReport(Request $request)
    {
        $companyId = app('currentCompanyId');
        $user = Auth::guard('fbrpos')->user();
        $date = $request->input('date', today()->format('Y-m-d'));

        // Friendly UX: tell the user if they're trying to re-close (vs. silent idempotency)
        $alreadyClosed = FbrDayCloseReport::where('company_id', $companyId)
            ->where('report_date', $date)->exists();
        if ($alreadyClosed) {
            return back()->with('error', __('pos.dayclose_report_exists'));
        }

        // Cash reconciliation (optional): opening float + physically-counted cash.
        $request->validate([
            'opening_float' => 'nullable|numeric|min:0|max:99999999',
            'counted_cash' => 'nullable|numeric|min:0|max:99999999',
        ]);
        $cashRecon = null;
        if ($request->filled('opening_float') || $request->filled('counted_cash')) {
            $cashRecon = [
                'opening_float' => $request->filled('opening_float') ? (float) $request->input('opening_float') : null,
                'counted_cash' => $request->filled('counted_cash') ? (float) $request->input('counted_cash') : null,
            ];
        }

        // Route through shared writer (transaction + atomic numbering + race-safe)
        $report = $this->performDayClose($companyId, $date, $user->id, $request->input('notes'), $cashRecon);

        if (!$report) {
            return back()->with('error', __('pos.dayclose_no_transactions'));
        }

        return back()->with('success', __('pos.dayclose_report_generated_for', ['number' => $report->report_number, 'date' => \Carbon\Carbon::parse($date)->format('d M Y')]));
    }

    public function dayCloseReportPdf($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $report = FbrDayCloseReport::where('company_id', $companyId)->findOrFail($id);

        $transactions = FbrPosTransaction::where('company_id', $companyId)
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

        $analytics = $this->buildFbrDayCloseAnalytics($companyId, $report->report_date->toDateString(), $transactions);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('fbr-pos.day-close-pdf', compact('company', 'report', 'transactions', 'cashierBreakdown', 'analytics'));
        $pdf->setPaper('a4', 'portrait');

        return $pdf->download("Day-Close-{$report->report_number}-{$report->report_date->format('Y-m-d')}.pdf");
    }

    /**
     * 80mm thermal Z-report (print-optimized HTML — FBR mirror of the PRA
     * version). Shares the analytics builder with the page + A4 PDF.
     */
    public function dayCloseThermal($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $report = FbrDayCloseReport::where('company_id', $companyId)->findOrFail($id);

        $transactions = FbrPosTransaction::where('company_id', $companyId)
            ->whereDate('created_at', $report->report_date)
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        $cashierBreakdown = $transactions->groupBy(fn ($t) => $t->creator ? $t->creator->name : 'Unknown')->map(function ($group) {
            return (object) [
                'count' => $group->count(),
                'revenue' => $group->sum('total_amount'),
                'tax' => $group->sum('tax_amount'),
            ];
        });

        $analytics = $this->buildFbrDayCloseAnalytics($companyId, $report->report_date->toDateString(), $transactions);

        return view('fbr-pos.day-close-thermal', compact('company', 'report', 'transactions', 'cashierBreakdown', 'analytics'));
    }

    public function products(Request $request)
    {
        $companyId = app('currentCompanyId');
        $search = $request->get('search', '');
        $query = Product::where('company_id', $companyId);
        if ($search) {
            $like = \App\Helpers\DbCompat::like();
            $query->where(function ($q) use ($search, $like) {
                $q->where('name', $like, "%{$search}%")
                  ->orWhere('hs_code', $like, "%{$search}%");
            });
        }
        $products = $query->orderBy('name')->paginate(20);
        return view('fbr-pos.products', compact('products', 'search'));
    }

    public function createProduct()
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') abort(403, 'Only admin can manage products.');
        return view('fbr-pos.product-form');
    }

    public function storeProduct(Request $request)
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') abort(403, 'Only admin can manage products.');
        $request->validate([
            'name' => 'required|string|max:255',
            'default_price' => 'required|numeric|min:0',
            'hs_code' => 'nullable|string|max:50',
            'uom' => 'nullable|string|max:20',
            'barcode' => 'nullable|string|max:64',
            'sku' => 'nullable|string|max:64',
            'tax_type' => 'required|in:taxable,exempt,custom',
            'default_tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $taxType = $request->tax_type;
        $taxRate = $taxType === 'taxable' ? 18 : ($taxType === 'exempt' ? 0 : ($request->default_tax_rate ?? 0));

        Product::create([
            'company_id' => app('currentCompanyId'),
            'name' => $request->name,
            'barcode' => $request->barcode ?: null,
            'sku' => $request->sku ?: null,
            'default_price' => $request->default_price,
            'is_price_editable' => $request->boolean('is_price_editable'),
            'hs_code' => $request->hs_code,
            'uom' => $request->uom ?? 'U',
            'tax_type' => $taxType,
            'default_tax_rate' => $taxRate,
        ]);

        return redirect()->route('fbrpos.products')->with('success', __('pos.product_created_success'));
    }

    public function editProduct($id)
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') abort(403, 'Only admin can manage products.');
        $companyId = app('currentCompanyId');
        $product = Product::where('company_id', $companyId)->findOrFail($id);
        return view('fbr-pos.product-form', compact('product'));
    }

    public function updateProduct(Request $request, $id)
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') abort(403, 'Only admin can manage products.');
        $companyId = app('currentCompanyId');
        $product = Product::where('company_id', $companyId)->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'default_price' => 'required|numeric|min:0',
            'hs_code' => 'nullable|string|max:50',
            'uom' => 'nullable|string|max:20',
            'barcode' => 'nullable|string|max:64',
            'sku' => 'nullable|string|max:64',
            'tax_type' => 'required|in:taxable,exempt,custom',
            'default_tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $taxType = $request->tax_type;
        $taxRate = $taxType === 'taxable' ? 18 : ($taxType === 'exempt' ? 0 : ($request->default_tax_rate ?? 0));

        $product->update([
            'name' => $request->name,
            'barcode' => $request->barcode ?: null,
            'sku' => $request->sku ?: null,
            'default_price' => $request->default_price,
            'is_price_editable' => $request->boolean('is_price_editable'),
            'hs_code' => $request->hs_code,
            'uom' => $request->uom ?? 'U',
            'tax_type' => $taxType,
            'default_tax_rate' => $taxRate,
        ]);

        return redirect()->route('fbrpos.products')->with('success', __('pos.product_updated_success'));
    }

    public function toggleProduct($id)
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') abort(403, 'Only admin can manage products.');
        $companyId = app('currentCompanyId');
        $product = Product::where('company_id', $companyId)->findOrFail($id);
        $product->update(['is_active' => !$product->is_active]);
        return redirect()->route('fbrpos.products')->with('success', __('pos.product_status_updated'));
    }

    /**
     * Toggle a single product's sale-screen visibility (show_on_sale).
     * Hidden products stay searchable + billable — they only leave the grid.
     */
    public function toggleProductSale($id)
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') abort(403, 'Only admin can manage products.');
        $companyId = app('currentCompanyId');
        $product = Product::where('company_id', $companyId)->findOrFail($id);
        $product->update(['show_on_sale' => !$product->show_on_sale]);
        return back()->with('success', $product->show_on_sale ? __('pos.product_visible_on_sale') : __('pos.product_hidden_from_sale'));
    }

    /**
     * Bulk hide/show ALL products on the sale-screen grid (port of the PRA
     * NestPOS bulk toggle). Admin-only. Uses the show_on_sale flag, so hidden
     * products stay searchable + billable exactly like single-hide.
     * NOTE: FBR products have no category column, so there is no category scope.
     */
    public function bulkToggleSale(Request $request)
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') {
            abort(403, 'Only admin can bulk-change sale screen visibility.');
        }
        $request->validate([
            'action' => 'required|in:hide,show',
        ]);
        $companyId = app('currentCompanyId');
        $show = $request->action === 'show';

        // Only flip rows actually in the opposite state so the flashed
        // count = products genuinely affected.
        $count = Product::where('company_id', $companyId)
            ->where('show_on_sale', !$show)
            ->update(['show_on_sale' => $show]);

        $msg = $show
            ? __('pos.products_shown_scope', ['count' => number_format($count), 'scope' => ''])
            : __('pos.products_hidden_scope', ['count' => number_format($count), 'scope' => '']);
        return back()->with('success', $msg);
    }

    public function destroyProduct($id)
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') abort(403, 'Only admin can manage products.');
        $companyId = app('currentCompanyId');
        $product = Product::where('company_id', $companyId)->findOrFail($id);
        $name = $product->name;
        $product->delete();
        return redirect()->route('fbrpos.products')->with('success', __('pos.product_deleted_named', ['name' => $name]));
    }

    public function searchProducts(Request $request)
    {
        $companyId = app('currentCompanyId');
        $q = trim((string) $request->get('q', ''));
        $like = \App\Helpers\DbCompat::like();
        $products = Product::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($query) use ($q, $like) {
                $query->where('name', $like, "%{$q}%")
                      ->orWhere('hs_code', $like, "%{$q}%")
                      ->orWhere('barcode', $like, "%{$q}%")
                      ->orWhere('sku', $like, "%{$q}%");
            })
            ->take(15)
            ->get(['id', 'name', 'hs_code', 'barcode', 'sku', 'default_price', 'is_price_editable', 'default_tax_rate', 'tax_type', 'uom']);

        return response()->json($products);
    }

    /**
     * 🔄 Auto-sync API — list FBR POS bills awaiting submission.
     * Returns bills with fbr_status IN (failed, offline, pending) AND no
     * fbr_invoice_number yet. Used by both header "Failed" modal and the
     * silent 30-sec poller in fbr-pos/create.blade.php.
     */
    public function apiFailedBills(Request $request)
    {
        $companyId = app('currentCompanyId');
        $bills = FbrPosTransaction::where('company_id', $companyId)
            ->whereIn('fbr_status', ['failed', 'offline', 'pending'])
            ->whereNull('fbr_invoice_number')
            ->where(function ($q) {
                $q->whereNull('invoice_mode')->orWhere('invoice_mode', '!=', 'local');
            })
            ->orderBy('id', 'desc')
            ->limit(100)
            ->get(['id', 'invoice_number', 'customer_name', 'total_amount', 'fbr_status', 'created_at']);

        $data = $bills->map(function ($b) {
            return [
                'id'             => $b->id,
                'invoice_number' => $b->invoice_number,
                'customer_name'  => $b->customer_name,
                'total_amount'   => (float) $b->total_amount,
                'fbr_status'     => $b->fbr_status,
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
     * 🔄 Auto-sync API — retry a single failed FBR POS bill via JSON.
     * Race-safe atomic claim prevents duplicate FBR submissions on
     * double-click / concurrent poller / queued RetryFbrPosSubmissionJob.
     */
    public function apiRetryFailed(Request $request, $id)
    {
        $companyId = app('currentCompanyId');

        // Atomic claim: flip from failed/offline → pending only if still
        // un-submitted. Conditional UPDATE returns affected-row count;
        // 0 = another caller already claimed it.
        $claimed = FbrPosTransaction::where('company_id', $companyId)
            ->where('id', $id)
            ->whereNull('fbr_invoice_number')
            ->whereIn('fbr_status', ['failed', 'offline', 'pending'])
            ->where(function ($q) {
                $q->whereNull('invoice_mode')->orWhere('invoice_mode', '!=', 'local');
            })
            ->update(['fbr_status' => 'pending', 'fbr_submission_hash' => null]);

        if ($claimed === 0) {
            $tx = FbrPosTransaction::where('company_id', $companyId)->where('id', $id)->first();
            if (!$tx) {
                return response()->json(['success' => false, 'message' => __('pos.bill_not_found')], 404);
            }
            if ($tx->fbr_invoice_number) {
                return response()->json([
                    'success' => false,
                    'message' => __('pos.already_submitted_fbr_num', ['number' => $tx->fbr_invoice_number]),
                ], 422);
            }
            if ($tx->invoice_mode === 'local') {
                return response()->json(['success' => false, 'message' => __('pos.local_bill_not_submitted_design')], 422);
            }
            return response()->json([
                'success' => false,
                'message' => __('pos.cannot_retry_status_changed', ['status' => $tx->fbr_status]),
            ], 409);
        }

        $transaction = FbrPosTransaction::where('company_id', $companyId)
            ->where('id', $id)
            ->with(['items', 'company'])
            ->first();

        try {
            $fbrService = new FbrService();
            $result = $fbrService->submitFbrPosTransaction($transaction);
            $transaction->refresh();

            if (($result['status'] ?? '') === 'success') {
                return response()->json([
                    'success'   => true,
                    'submitted' => true,
                    'message'   => __('pos.fbr_submission_successful_num_short', ['number' => $transaction->fbr_invoice_number ?? 'N/A']),
                    'fbr_invoice_number' => $transaction->fbr_invoice_number,
                    'id' => $transaction->id,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => __('pos.fbr_retry_failed_errors', ['error' => implode(', ', $result['errors'] ?? [__('pos.unknown_error')])]),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => __('pos.exception_error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    public function lookupByBarcode(Request $request)
    {
        $companyId = app('currentCompanyId');
        $code = trim((string) $request->get('code', ''));
        if ($code === '') {
            return response()->json(['found' => false]);
        }
        $product = Product::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($q) use ($code) {
                $q->where('barcode', $code)->orWhere('sku', $code);
            })
            ->first(['id', 'name', 'hs_code', 'barcode', 'sku', 'default_price', 'is_price_editable', 'default_tax_rate', 'tax_type', 'uom']);

        if (!$product) {
            return response()->json(['found' => false]);
        }
        return response()->json(['found' => true, 'product' => $product]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 🌐 UNIVERSAL SALE SCREEN APIs (customer + quick product)
    // Mirrors the PRA universal endpoints' JSON shapes so the ported
    // fbr-pos/universal view works without frontend shape changes.
    // All scoped by company_id; customers live in the shared pos_customers table.
    // ═══════════════════════════════════════════════════════════════════

    public function apiCustomerSearch(Request $request)
    {
        $companyId = app('currentCompanyId');
        $q = $request->get('q', '');

        if (strlen($q) < 2) {
            return response()->json(['customers' => []]);
        }

        $customers = \App\Models\PosCustomer::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($query) use ($q) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($q) . '%'])
                    ->orWhereRaw('LOWER(phone) LIKE ?', ['%' . strtolower($q) . '%']);
            })
            ->limit(8)
            ->get(['id', 'name', 'phone', 'email', 'address']);

        $result = [];
        foreach ($customers as $c) {
            $agg = FbrPosTransaction::where('company_id', $companyId)
                ->where('customer_id', $c->id)
                ->where('status', 'completed')
                ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as total')
                ->first();
            $totalOrders = (int) ($agg->cnt ?? 0);
            $result[] = [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'address' => $c->address,
                'stats' => [
                    'total_orders' => $totalOrders,
                    'total_spent' => round((float) ($agg->total ?? 0), 2),
                    'is_frequent' => $totalOrders >= 5,
                ],
            ];
        }

        return response()->json(['customers' => $result]);
    }

    public function apiCustomerLookup(Request $request)
    {
        $companyId = app('currentCompanyId');
        $phone = $request->get('phone', '');

        if (strlen($phone) < 4) {
            return response()->json(['found' => false]);
        }

        $customer = \App\Models\PosCustomer::where('company_id', $companyId)
            ->where('phone', $phone)
            ->first();

        if (!$customer) {
            $partials = \App\Models\PosCustomer::where('company_id', $companyId)
                ->whereRaw('LOWER(phone) LIKE ?', ['%' . strtolower($phone) . '%'])
                ->limit(5)
                ->get(['id', 'name', 'phone', 'address']);

            return response()->json(['found' => false, 'suggestions' => $partials]);
        }

        $agg = FbrPosTransaction::where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as total, MAX(created_at) as last_at')
            ->first();

        return response()->json([
            'found' => true,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'address' => $customer->address,
            ],
            'stats' => [
                'total_orders' => (int) ($agg->cnt ?? 0),
                'total_spent' => round((float) ($agg->total ?? 0), 2),
                'last_order_at' => $agg->last_at,
                'is_frequent' => (int) ($agg->cnt ?? 0) >= 5,
            ],
        ]);
    }

    public function apiCustomerStore(Request $request)
    {
        $companyId = app('currentCompanyId');

        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:30',
            'address' => 'nullable|string|max:500',
        ]);

        $existing = \App\Models\PosCustomer::where('company_id', $companyId)
            ->where('phone', $request->phone)
            ->first();

        if ($existing) {
            if ($request->address && !$existing->address) {
                $existing->update(['address' => $request->address]);
            }
            return response()->json(['success' => true, 'customer' => $existing, 'existing' => true]);
        }

        $customer = \App\Models\PosCustomer::create([
            'company_id' => $companyId,
            'name' => $request->name,
            'phone' => $request->phone,
            'address' => $request->address,
            'type' => 'unregistered',
        ]);

        return response()->json(['success' => true, 'customer' => $customer]);
    }

    public function apiCustomerHistory($id)
    {
        $companyId = app('currentCompanyId');
        $customer = \App\Models\PosCustomer::where('company_id', $companyId)->find($id);
        if (!$customer) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $recentOrders = FbrPosTransaction::where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->with('items')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($t) {
                return [
                    'id' => $t->id,
                    'order_number' => $t->invoice_number,
                    'total' => (float) $t->total_amount,
                    'date' => $t->created_at->format('M d, g:i A'),
                    'items' => $t->items->map(fn($i) => [
                        'item_id' => $i->product_id,
                        'item_type' => $i->product_id ? 'product' : 'manual',
                        'name' => $i->item_name,
                        'qty' => (float) $i->quantity,
                        'price' => (float) $i->unit_price,
                    ]),
                ];
            });

        $favorites = FbrPosTransactionItem::whereHas('transaction', function ($q) use ($companyId, $customer) {
            $q->where('company_id', $companyId)
              ->where('customer_id', $customer->id)
              ->where('status', 'completed');
        })
        ->select('item_name', DB::raw('SUM(quantity) as total_qty'))
        ->groupBy('item_name')
        ->orderByDesc('total_qty')
        ->limit(5)
        ->get();

        $agg = FbrPosTransaction::where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as total')
            ->first();

        return response()->json([
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'total_orders' => (int) ($agg->cnt ?? 0),
            'total_spent' => round((float) ($agg->total ?? 0), 2),
            'recent_orders' => $recentOrders,
            'favorites' => $favorites->map(fn($f) => ['name' => $f->item_name, 'count' => (int) $f->total_qty]),
        ]);
    }

    /**
     * ⚡ Quick-create a product from the universal sale screen search box.
     * FBR defaults: standard 18% tax, UOM 'U', price editable. Cashier sets
     * price inline right after (apiQuickUpdatePrice). HS code left empty —
     * admin can fill it later from Products; store() falls back to default rate.
     */
    public function apiQuickCreateProduct(Request $request)
    {
        $companyId = app('currentCompanyId');
        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'price' => 'nullable|numeric|min:0',
        ]);
        $name = trim($data['name']);
        if ($name === '') {
            return response()->json(['ok' => false, 'error' => 'Name required'], 422);
        }
        $product = Product::create([
            'company_id'       => $companyId,
            'name'             => $name,
            'default_price'    => $data['price'] ?? 0,
            'default_tax_rate' => 18,
            'tax_type'         => 'standard',
            'uom'              => 'U',
            'sku'              => 'QC-' . substr((string) time(), -6) . '-' . strtoupper(substr(uniqid(), -3)),
            'is_price_editable'=> true,
            'is_active'        => true,
        ]);
        return response()->json([
            'ok' => true,
            'product' => [
                'id'            => $product->id,
                'name'          => $product->name,
                'price'         => (float) $product->default_price,
                'category'      => 'Quick',
                'type'          => 'product',
                'image'         => null,
                'is_tax_exempt' => false,
                'tax_rate'      => 18.0,
                'hs_code'       => null,
                'uom'           => 'U',
                'hasRecipe'     => false,
                'stockStatus'   => null,
                'isQuickCreated'=> true,
            ],
        ]);
    }

    public function apiQuickUpdatePrice(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $data = $request->validate([
            'price' => 'required|numeric|min:0',
        ]);
        $product = Product::where('company_id', $companyId)->where('id', $id)->first();
        if (!$product) {
            return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        }
        $product->default_price = $data['price'];
        $product->save();
        return response()->json(['ok' => true, 'price' => (float) $product->default_price]);
    }
}
