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
    use \App\Http\Controllers\Concerns\FbrPlanGate;

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
        // Pending Deliveries panel (Task 122 — FBR port of PRA Task 114): the
        // sale screen needs business_date / order_type / rider context to show
        // "aaj ke pending deliveries". fbr_pos_transactions may not have these
        // columns (no riders on FBR POS; business_date arrives with the FBR
        // business-day work) — hasColumn guards + created_at fallback keep the
        // API shape identical to PRA's PosController::apiProvisionalBills.
        $hasBizDate   = \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'business_date');
        $hasOrderType = \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'order_type');
        $hasAddress   = \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'delivery_address');
        $bills = \App\Models\FbrPosTransaction::where('company_id', $companyId)
            ->where('invoice_mode', 'local')
            ->where('fbr_status', 'local')
            ->withCount('items')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'invoice_number', 'total_amount', 'created_at', 'customer_name', 'customer_phone', 'payment_method',
                   ...($hasBizDate ? ['business_date'] : []),
                   ...($hasOrderType ? ['order_type'] : []),
                   ...($hasAddress ? ['delivery_address'] : [])])
            ->map(function ($b) use ($companyId, $hasBizDate, $hasOrderType, $hasAddress) {
                return [
                    'id' => $b->id,
                    'invoice_number' => $b->invoice_number,
                    'total_amount' => (float) $b->total_amount,
                    'customer_name' => $b->customer_name ?? 'Walk-in',
                    'customer_phone' => $b->customer_phone,
                    'payment_method' => $b->payment_method,
                    'items_count' => (int) ($b->items_count ?? 0),
                    'created_human' => $b->created_at?->diffForHumans(),
                    'created_at' => optional($b->created_at)->format('d M, h:i A'),
                    'created_time' => $b->created_at?->format('h:i A'),
                    // No business_date column yet → derive from created_at via
                    // PosBusinessDay so the fallback uses the SAME cutoff rule
                    // as business_today below (a 01:00 bill must never mismatch
                    // the badge's date filter) and the panel stays scoped to
                    // TODAY (never floods with old confidential provisionals).
                    'business_date' => ($hasBizDate && $b->business_date)
                        ? (string) $b->business_date
                        : ($b->created_at ? \App\Services\PosBusinessDay::forMomentFbr((int) $companyId, $b->created_at) : null),
                    'order_type' => $hasOrderType ? $b->order_type : null,
                    'delivery_address' => $hasAddress ? $b->delivery_address : null,
                    // FBR POS has no delivery riders — mirror fields stay empty
                    // so the shared panel markup degrades gracefully.
                    'rider_name' => null,
                    'rider_unsettled' => false,
                    'rider_id' => null,
                    'rider_open_count' => 0,
                    'rider_open_amount' => 0,
                    'kot_pending' => false,
                ];
            });
        // Task 517 (FBR port of PRA Task 513): UNASSIGNED final delivery bills
        // in the Pending Deliveries popup — cashier rider yahin se assign kare,
        // Deliveries board kholne ki zaroorat na rahe. Same 7-din window as the
        // PRA popup / board pending tab (purane pre-feature delivery bills popup
        // ko flood na karein). Display + assign ONLY — promote (Final Cash/Card)
        // in par KABHI nahi chalta (bill pehle se final hai).
        $hasRiderCols  = \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'rider_id');
        $hasDelStatus  = \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'delivery_status');
        $hasSettleCol  = \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'rider_settlement_id');
        // Task 521 (FBR port of PRA parity): the popup now ALSO lists assigned/
        // dispatched final bills and delivered-CASH bills still on the rider's
        // unsettled khata — Delivered mark + whole-khata Settle happen right in
        // the popup (PRA Tasks 123/513 port), Deliveries board na kholna pare.
        // Current business day (00:00–05:59 counts in yesterday). The badge's
        // client-side date filter uses it, and Task 524 stamps purani unassigned
        // bills server-side against the same authoritative date.
        $bizToday = \App\Services\PosBusinessDay::currentFbr($companyId);
        $finalData = collect();
        if ($hasRiderCols && $hasDelStatus && $hasSettleCol && $hasOrderType) {
            $finalBills = \App\Models\FbrPosTransaction::where('company_id', $companyId)
                ->where('status', 'completed')
                // NOT a provisional (local+local pair = FBR provisional definition).
                ->whereNot(function ($q) {
                    $q->where('invoice_mode', 'local')->where('fbr_status', 'local');
                })
                ->where(function ($q) {
                    $q->where(function ($qa) {
                        $qa->whereNotNull('rider_id')
                            // Settled bills kabhi popup mein na aayen — settle_all
                            // assigned/dispatched cash bills ko bhi settle karta hai
                            // (Task 522 review): settlement stamp = popup se bahar.
                            ->whereNull('rider_settlement_id')
                            ->where(function ($qb) {
                                // Abhi raste mein…
                                $qb->whereIn('delivery_status', ['assigned', 'dispatched'])
                                    // …ya deliver ho gaya par cash abhi rider ke paas.
                                    ->orWhere(function ($q2) {
                                        $q2->where('delivery_status', 'delivered')
                                           ->where('payment_method', 'cash')
                                           ->whereNull('rider_settlement_id');
                                    });
                            });
                    })
                    // Task 517: UNASSIGNED delivery bills (rider NULL, status NULL,
                    // unsettled) — cashier rider yahin se assign kare. Same 7-din
                    // window as the board pending tab (purane bills flood na karein).
                    ->orWhere(function ($qu) {
                        $qu->whereNull('rider_id')
                            ->whereNull('delivery_status')
                            ->whereNull('rider_settlement_id')
                            ->where('order_type', 'delivery')
                            ->where('created_at', '>=', now()->subDays(7));
                    });
                })
                ->withCount('items')
                ->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'invoice_number', 'customer_name', 'customer_phone', 'order_type',
                       'total_amount', 'payment_method', 'created_at',
                       'rider_id', 'rider_settlement_id', 'delivery_status',
                       ...($hasAddress ? ['delivery_address'] : []),
                       ...($hasBizDate ? ['business_date'] : [])]);

            // Rider names + open-khata summary — one batch lookup (PRA Task 123
            // port). Settle button settles the rider's ENTIRE khata (all dates),
            // same scope as FbrPosRiderController::settle with settle_all — show
            // the cashier the real count+amount so "poore rider ka settle" is clear.
            $riderNames = [];
            $riderOpen  = []; // rider_id => ['count' => n, 'amount' => rs]
            $riderIds = $finalBills->pluck('rider_id')->filter()->unique();
            if ($riderIds->isNotEmpty() && \Illuminate\Support\Facades\Schema::hasTable('pos_riders')) {
                $riderNames = DB::table('pos_riders')
                    ->where('company_id', $companyId)
                    ->whereIn('id', $riderIds)
                    ->pluck('name', 'id')
                    ->all();
                $riderOpen = \App\Models\FbrPosTransaction::where('company_id', $companyId)
                    ->whereIn('rider_id', $riderIds)
                    ->where('payment_method', 'cash')
                    ->whereNull('rider_settlement_id')
                    ->where(function ($q) {
                        $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', 'returned');
                    })
                    ->selectRaw('rider_id, COUNT(*) as c, COALESCE(SUM(' . \App\Models\PosRider::remainingExpr('fbr_pos_transactions') . '),0) as amt')
                    ->groupBy('rider_id')
                    ->get()
                    ->mapWithKeys(fn ($r) => [$r->rider_id => ['count' => (int) $r->c, 'amount' => (float) $r->amt]])
                    ->all();
            }

            $finalData = $finalBills
                ->map(function ($b) use ($companyId, $hasBizDate, $bizToday, $hasAddress, $riderNames, $riderOpen) {
                    // Task 524 (FBR mirror): purana (pichhle business day ka)
                    // UNASSIGNED bill — popup ise alag collapsed "Purani
                    // deliveries" group mein dikhata hai aur badge ki ginti se
                    // bahar rakhta hai. Flag SERVER par banta hai.
                    $billDay = ($hasBizDate && $b->business_date)
                        ? (string) $b->business_date
                        : ($b->created_at ? \App\Services\PosBusinessDay::forMomentFbr((int) $companyId, $b->created_at) : null);
                    return [
                        'id'               => $b->id,
                        'is_final'         => true,
                        'invoice_number'   => $b->invoice_number,
                        'customer_name'    => $b->customer_name,
                        'customer_phone'   => $b->customer_phone,
                        'order_type'       => $b->order_type,
                        'delivery_address' => $hasAddress ? $b->delivery_address : null,
                        'total_amount'     => (float) $b->total_amount,
                        'payment_method'   => $b->payment_method,
                        'items_count'      => (int) ($b->items_count ?? 0),
                        'created_human'    => $b->created_at?->diffForHumans(),
                        'created_time'     => $b->created_at?->format('h:i A'),
                        'business_date'    => $billDay,
                        'delivery_status'  => $b->delivery_status,
                        'rider_id'         => $b->rider_id ? (int) $b->rider_id : null,
                        'rider_name'       => $b->rider_id ? ($riderNames[$b->rider_id] ?? null) : null,
                        // Cash bill jo rider ke khaate par hai (card bills khata par nahi hote).
                        'rider_unsettled'  => (bool) ($b->rider_id && empty($b->rider_settlement_id) && $b->payment_method === 'cash' && $b->delivery_status !== 'returned'),
                        'rider_open_count' => $b->rider_id ? ($riderOpen[$b->rider_id]['count'] ?? 0) : 0,
                        'rider_open_amount'=> $b->rider_id ? ($riderOpen[$b->rider_id]['amount'] ?? 0) : 0,
                        // Task 524: purani unassigned = collapsed group + badge se bahar.
                        'is_stale_unassigned' => (bool) ($bizToday && $billDay && !$b->rider_id
                            && !$b->delivery_status && $billDay < $bizToday),
                    ];
                })
                ->values();
        }

        // Task 517: active riders list + assign permission — the popup renders a
        // rider dropdown on UNASSIGNED bills (POST fbrpos.deliveries.assign, same
        // backend as the board). Plan gate (riders_enabled) + Delivery feature
        // toggle mirror FbrPosRiderController::deliveryGate(); custom-access
        // 'deliveries' verdict mirrors the PRA popup's gating (deliveriesFallback
        // included via customAllows).
        // try/catch fail-closed: plan/feature lookups touch subscriptions —
        // if that ever fails the popup simply hides the dropdown (board stays).
        try {
            $canAssignRider = \App\Services\PosFeatureService::planAllows($pinCompany, 'riders_enabled')
                && !empty(\App\Services\PosFeatureService::forCompany($pinCompany)->delivery)
                && \App\Services\PosAccessService::customAllows(Auth::guard('fbrpos')->user(), 'deliveries') !== false;
        } catch (\Throwable $e) {
            $canAssignRider = false;
        }
        $assignRiders = [];
        if ($canAssignRider && $hasRiderCols && \Illuminate\Support\Facades\Schema::hasTable('pos_riders')) {
            $assignRiders = DB::table('pos_riders')
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name])
                ->values()
                ->all();
        }

        return response()->json([
            'success' => true,
            'bills' => $bills,
            'count' => $bills->count(),
            'final_deliveries' => $finalData,
            'riders' => $assignRiders,
            'can_assign_rider' => $canAssignRider,
            // Current business day for the badge's client-side date filter.
            // PosBusinessDay is company-cutoff based (00:00–05:59 counts in
            // yesterday); the Fbr variant reads fbr_day_close_reports for the
            // "already closed" check (Task 492).
            'business_today' => $bizToday,
        ]);
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
        // Pending Deliveries quick-final (Task 122): cashier picks the settlement
        // method AT promote time (Cash/Card). Amounts are NEVER re-derived on
        // FBR POS (stored subtotal/tax are authoritative) — only the payment
        // method label updates. 'card' normalizes to 'debit_card' (card bucket
        // convention). Missing/invalid method → stored value stays untouched.
        $method = $request->input('payment_method');
        if ($method === 'card') {
            $method = 'debit_card';
        }
        if (!in_array($method, ['cash', 'debit_card', 'credit_card', 'qr_payment'], true)) {
            $method = null;
        }
        // 🔒 Race-safe atomic claim: only flips if still local — prevents double-promote
        $affected = \App\Models\FbrPosTransaction::where('id', $id)
            ->where('company_id', $companyId)
            ->where('invoice_mode', 'local')
            ->where('fbr_status', 'local')
            ->update([
                'invoice_mode' => 'fbr',
                'fbr_status' => $reportingOn ? 'pending' : null,
                ...($method ? ['payment_method' => $method] : []),
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

        // Task 492: dashboard "today" = current TRADING day (business_date via
        // the PosBusinessDay cutoff rule), so a 1 AM sale of a late-night shop
        // stays in yesterday's figures until that day is closed.
        $bizToday = $this->fbrBizToday($companyId);
        // Return / credit-note netting (Task 591 — FBR mirror of PRA Task 578):
        // money figures are SIGNED (returns subtract), bill counts stay
        // SALES-only (a credit note is not a bill). Schema-guarded for prod
        // drift — pre-migration boxes fall back to the old unsigned sums.
        [$dashSignExpr, $dashSaleRowExpr] = $this->fbrReturnNettingExprs();
        $todayStats = FbrPosTransaction::where('company_id', $companyId)
            ->tap($branchScope)
            ->tap(fn ($q) => $this->whereBizDate($q, $bizToday))
            ->selectRaw("COALESCE(SUM({$dashSaleRowExpr}),0) as count, COALESCE(SUM(({$dashSignExpr}) * total_amount),0) as revenue, COALESCE(SUM(({$dashSignExpr}) * tax_amount),0) as tax")
            ->first();

        $monthStats = FbrPosTransaction::where('company_id', $companyId)
            ->tap($branchScope)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->selectRaw("COALESCE(SUM({$dashSaleRowExpr}),0) as count, COALESCE(SUM(({$dashSignExpr}) * total_amount),0) as revenue, COALESCE(SUM(({$dashSignExpr}) * tax_amount),0) as tax")
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

        // Task 112: Pending Bills tile (mirrors PRA dashboard, Task 109) —
        // provisional bills of the current day that are still not FINAL.
        // Triple-filter per pos-provisional rules: completed + invoice_mode='local'
        // + fbr_status='local'. "Current day" = current trading day (Task 492).
        $pendingProvisional = FbrPosTransaction::where('company_id', $companyId)
            ->tap($branchScope)
            ->where('status', 'completed')
            ->where('invoice_mode', 'local')
            ->where('fbr_status', 'local')
            ->tap(fn ($q) => $this->whereBizDate($q, $bizToday))
            ->count();

        // Same admin/manager rule as PRA dashboard tile (admin-only surface).
        $user = Auth::guard('fbrpos')->user();
        $isAdmin = in_array($user->pos_role ?? $user->role ?? '', ['pos_admin', 'pos_manager', 'company_admin']);

        // Stranded-day warning (Task 479 — FBR mirror of PRA Task 466): prior
        // days with bills but no day-close report. Compact echo on the
        // dashboard — everyone lands here. dayCloseAllowed decides whether the
        // link is actionable (cashiers without day-close rights get info-only
        // text, not a dead-end link).
        $unclosedPriorDays = $this->unclosedPriorBusinessDays($companyId);
        $canDayClose = \App\Services\PosAccessService::dayCloseAllowed($user, $company);

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
            'dashboardStyle', 'notifications', 'pendingProvisional', 'isAdmin',
            'unclosedPriorDays', 'canDayClose'
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
        $closedDates = FbrDayCloseReport::where('company_id', $companyId)
            ->pluck('report_date')
            ->map(fn($d) => $d instanceof \Carbon\Carbon ? $d->format('Y-m-d') : (string) $d)
            ->all();

        // Task 492: days key on business_date (trading day), "prior" = before
        // the current OPEN trading day — a late-night shop's 1 AM bills belong
        // to the still-open yesterday and are never offered for auto-close.
        $bizToday = $this->fbrBizToday($companyId);
        $expr = $this->hasBizDate() ? 'business_date' : 'DATE(created_at)';
        // Return / credit-note netting (Task 607 — FBR mirror of the PRA
        // day-close netting): totals SIGNED (returns subtract), counts
        // SALES-only (a credit note is not a bill).
        [$signExpr, $saleRowExpr] = $this->fbrReturnNettingExprs();
        $rows = \DB::table('fbr_pos_transactions')
            ->select(
                \DB::raw($expr . ' as d'),
                \DB::raw("COALESCE(SUM({$saleRowExpr}),0) as cnt"),
                \DB::raw("COALESCE(SUM(({$signExpr}) * total_amount),0) as total")
            )
            ->where('company_id', $companyId)
            ->when($this->hasBizDate(),
                fn ($q) => $q->where('business_date', '<', $bizToday),
                fn ($q) => $q->where('created_at', '<', now()->startOfDay()))
            ->when(!empty($closedDates), fn($q) => $q->whereNotIn(\DB::raw($expr), $closedDates))
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
     * Result of the last auto-finalize sweep run by performDayClose (this request
     * only) — closeDayReport reads it to enrich the success flash.
     * @var array{finalized:int,queued:int,submitted:int,failed:int,skipped:int}|null
     */
    private ?array $lastFinalizeSweep = null;

    /**
     * AUTO-FINALIZE SWEEP ('finalize' pending-bill policy — FBR mirror of the PRA
     * 'Khud Final' option, Aug 2026). Promotes every pending provisional bill
     * (invoice_mode='local' + fbr_status='local', created on or before the close
     * date) through the EXACT race-safe atomic claim the F10 Make Final path
     * (apiPromoteProvisional) uses:
     *   - reporting-ON  → fbr/'pending' (queued for submission)
     *   - reporting-OFF → fbr/NULL (FINAL, no submission — Reporting-OFF Finals
     *     Invariant: NEVER leave 'pending' with reporting disabled)
     * Submission after the flip mirrors the store() path:
     *   - Fiscal Device (agent) mode: bill stays 'pending' — desktop agent polls it.
     *   - Cloud mode: submitFbrPosTransaction; failure/offline leaves the bill
     *     'pending'/'failed' — retryable from the Fail Queue, NEVER lost.
     * QUOTA: FBR POS quota (billableCount / free_invoice_limit) counts rows at
     * CREATION time regardless of mode, so promoting an existing provisional
     * consumes no extra quota — same as a manual F10 promote.
     * MONTH GATE (owner rule Jul 2026, mirrored from PRA): previous-month
     * provisionals are closed — never submitted late; they are skipped/carried.
     * NO receipt print — the sweep runs server-side without a customer present.
     */
    private function finalizeFbrProvisionalsAtDayClose(int $companyId, Company $company, string $date): array
    {
        $sweep = ['finalized' => 0, 'queued' => 0, 'submitted' => 0, 'failed' => 0, 'skipped' => 0];

        $reportingOn = (bool) ($company->fbr_reporting_enabled ?? false);
        $agentMode = $company->agentServesFbr() && $company->agent_enabled;
        $fbrService = null;

        // Task 492: sweep keys on business_date so an after-midnight provisional
        // (created 00:30, business_date = the close date) is finalized with its
        // trading day. The MONTH GATE below stays on real created_at (FBR truth).
        $rows = FbrPosTransaction::where('company_id', $companyId)
            ->tap(fn ($q) => $this->whereBizDate($q, $date, '<='))
            ->where('invoice_mode', 'local')
            ->where('fbr_status', 'local')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            // MONTH GATE: previous-month locals stay provisional (carried forward).
            if ($row->created_at && $row->created_at->lt(now()->startOfMonth())) {
                $sweep['skipped']++;
                continue;
            }
            // 🔒 Race-safe atomic claim — identical to apiPromoteProvisional: only
            // flips if still local, so a concurrent F10 promote can never double-fire.
            $affected = FbrPosTransaction::where('id', $row->id)
                ->where('company_id', $companyId)
                ->where('invoice_mode', 'local')
                ->where('fbr_status', 'local')
                ->update([
                    'invoice_mode' => 'fbr',
                    'fbr_status' => $reportingOn ? 'pending' : null,
                    'updated_at' => now(),
                ]);
            if ($affected === 0) {
                $sweep['skipped']++; // raced with a manual promote/delete — carry on
                continue;
            }
            $sweep['finalized']++;

            if (!$reportingOn) {
                continue; // reporting-OFF: bill is FINAL (fbr + NULL), nothing to send
            }
            if ($agentMode) {
                $sweep['queued']++; // Fiscal Device: desktop agent polls 'pending' bills
                continue;
            }
            // Cloud mode: submit now, mirroring store(). Any failure leaves the bill
            // retryable ('pending'/'failed' both surface in the Fail Queue) — never lost.
            try {
                $tx = FbrPosTransaction::where('company_id', $companyId)->find($row->id);
                if (!$tx) {
                    continue;
                }
                $tx->load(['items', 'company']);
                $fbrService = $fbrService ?: new FbrService();
                $result = $fbrService->submitFbrPosTransaction($tx);
                if (($result['status'] ?? null) === 'success') {
                    $sweep['submitted']++;
                } else {
                    $sweep['failed']++; // service stamped its own status; Fail Queue retryable
                }
            } catch (\Throwable $e) {
                // Internet/FBR down at close time — bill stays 'pending' (Fail Queue retryable).
                \Log::warning('FBR day-close auto-finalize submit failed', ['company' => $companyId, 'tx' => $row->id, 'err' => $e->getMessage()]);
                $sweep['failed']++;
            }
        }

        return $sweep;
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
    private function performDayClose(int $companyId, string $date, ?int $userId, ?string $notes = null, ?array $cashRecon = null, bool $allowEmpty = false): ?FbrDayCloseReport
    {
        // Fast-path: already closed → return without locking
        $existing = FbrDayCloseReport::where('company_id', $companyId)
            ->where('report_date', $date)->first();
        if ($existing) {
            return $existing;
        }

        // ── AUTO-FINALIZE SWEEP (FBR mirror of the PRA 'Khud Final' day-close policy,
        // Aug 2026): when the company opted in, promote every pending provisional
        // through the SAME atomic-claim path F10 Make Final uses, BEFORE the day's
        // figures are queried so freshly-finalized bills count in this very Z-report.
        // NO receipt print (customer not present; printing is client-side anyway).
        // Bills that cannot be finalized are CARRIED — never deleted.
        $company = Company::find($companyId);
        if (($company?->pos_dayclose_provisional_action ?? null) === 'finalize') {
            $this->lastFinalizeSweep = $this->finalizeFbrProvisionalsAtDayClose($companyId, $company, $date);
        }

        // Task 492: the persisted Z-report keys on business_date — same bucket
        // as the preview/PDF/thermal paths, so a 1 AM sale closes with its
        // trading day, never the next calendar day.
        $transactions = FbrPosTransaction::where('company_id', $companyId)
            ->tap(fn ($q) => $this->whereBizDate($q, $date))
            ->with('creator')->orderBy('created_at')->get();
        // Task 519 (FBR mirror of PRA Task 516): $allowEmpty lets a stranded
        // PRIOR day close with a zero-figure Z-report so it finally leaves the
        // banner. Today's empty close still refuses (allowEmpty=false).
        if ($transactions->isEmpty() && !$allowEmpty) {
            return null;
        }

        // Return / credit-note netting (Task 607 — FBR mirror of the PRA
        // Task 570 Z-report netting): returns NET the stored figures, never
        // inflate them. Counts + fiscal serial range stay SALES-only (credit
        // notes carry FRET- numbers outside the sale sequence). Schema-guarded
        // for prod drift — pre-migration boxes treat every row as a sale.
        [$saleRows, $returnRows] = $this->splitFbrSaleReturnRows($transactions);

        $baseData = [
            'company_id' => $companyId,
            'report_date' => $date,
            'total_invoices' => $saleRows->count(),
            'fbr_invoices' => $saleRows->where('fbr_status', 'submitted')->count(),
            'local_invoices' => $saleRows->where('fbr_status', 'local')->count(),
            'failed_invoices' => $saleRows->whereIn('fbr_status', ['failed', 'pending'])->count(),
            'gross_sales' => $saleRows->sum('subtotal') - $returnRows->sum('subtotal'),
            'total_discount' => $saleRows->sum('discount_amount') - $returnRows->sum('discount_amount'),
            'net_sales' => ($saleRows->sum('subtotal') - $returnRows->sum('subtotal'))
                - ($saleRows->sum('discount_amount') - $returnRows->sum('discount_amount')),
            'total_tax' => $saleRows->sum('tax_amount') - $returnRows->sum('tax_amount'),
            'total_fbr_fee' => $saleRows->sum('fbr_service_charge') - $returnRows->sum('fbr_service_charge'),
            'total_amount' => $saleRows->sum('total_amount') - $returnRows->sum('total_amount'),
            // Refunds net their own bucket (a cash refund reduces the expected
            // drawer cash) — mirrors the PRA POS Z-report netting. Buckets use
            // the shared classifier so khata refunds net udhaar (Task 607).
            'cash_amount' => $this->fbrBucketNet($saleRows, $returnRows, 'cash'),
            'card_amount' => $this->fbrBucketNet($saleRows, $returnRows, 'card'),
            'udhaar_amount' => $this->fbrBucketNet($saleRows, $returnRows, 'udhaar'),
            'other_amount' => $this->fbrBucketNet($saleRows, $returnRows, 'other'),
            'first_invoice_number' => $saleRows->first()->invoice_number ?? null,
            'last_invoice_number' => $saleRows->last()->invoice_number ?? null,
            'first_invoice_time' => $saleRows->first()->created_at ?? null,
            'last_invoice_time' => $saleRows->last()->created_at ?? null,
            'closed_by' => $userId,
            'notes' => $notes,
        ];

        // Returns detail on the Z-report (schema-guarded — the columns arrive
        // with a later migration; drift self-heal convention).
        if (\Schema::hasColumn('fbr_day_close_reports', 'returns_count')) {
            $baseData['returns_count'] = $returnRows->count();
        }
        if (\Schema::hasColumn('fbr_day_close_reports', 'returns_amount')) {
            $baseData['returns_amount'] = round((float) $returnRows->sum('total_amount'), 2);
        }

        // Delivery Riders (Task 541 — FBR mirror of the PRA Jul 2026 figures):
        // computed BEFORE the recon so expected cash reflects rider khata.
        $riderFigures = $this->buildFbrRiderDayFigures($companyId, $date);

        // Cash reconciliation (Z-report): expected = opening float + cash sales
        // − rider cash still out with riders + rider cash received today for
        // earlier days' bills; variance = counted − expected. Columns nullable +
        // schema-guarded (prod drift self-heal) — auto-close paths pass no
        // $cashRecon. Merged BEFORE the hash so the recon figures are
        // integrity-protected too.
        if ($cashRecon !== null && \Schema::hasColumn('fbr_day_close_reports', 'opening_float')) {
            $openingFloat = $cashRecon['opening_float'] ?? null;
            $countedCash = $cashRecon['counted_cash'] ?? null;
            $expectedCash = round((float) ($openingFloat ?? 0) + (float) $baseData['cash_amount']
                - (float) $riderFigures['cash_out'] + (float) $riderFigures['cash_in'], 2);
            $baseData['opening_float'] = $openingFloat;
            $baseData['counted_cash'] = $countedCash;
            $baseData['expected_cash'] = $expectedCash;
            $baseData['cash_variance'] = $countedCash !== null ? round((float) $countedCash - $expectedCash, 2) : null;
        }

        // Retry loop — max 3 attempts to handle rare concurrent report_number collisions
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return \DB::transaction(function () use ($companyId, $date, $baseData, $riderFigures) {
                    // Race-safe re-check inside transaction
                    $locked = FbrDayCloseReport::where('company_id', $companyId)
                        ->where('report_date', $date)->lockForUpdate()->first();
                    if ($locked) return $locked;

                    // Atomic MAX+1 — parses trailing digits from existing 'ZRPT-XXXXX' numbers.
                    // Far safer than count()+1 (which double-counts under concurrency).
                    // SUBSTRING_INDEX is MySQL-only — sqlite (test suite) computes in PHP;
                    // the row-level lock above keeps both paths race-safe.
                    $maxNum = in_array(\DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                        ? (int) FbrDayCloseReport::where('company_id', $companyId)
                            ->max(\DB::raw("CAST(SUBSTRING_INDEX(report_number, '-', -1) AS UNSIGNED)"))
                        : (int) FbrDayCloseReport::where('company_id', $companyId)
                            ->pluck('report_number')
                            ->map(fn ($n) => (int) \Illuminate\Support\Str::afterLast((string) $n, '-'))
                            ->max();
                    $reportNumber = 'ZRPT-' . str_pad($maxNum + 1, 5, '0', STR_PAD_LEFT);

                    $data = array_merge($baseData, ['report_number' => $reportNumber]);
                    $data['hash'] = hash('sha256', json_encode($data));
                    $report = FbrDayCloseReport::create($data);

                    // Delivery Riders (Task 541): rider day detail on the
                    // Z-report. Same schema-drift try/catch pattern as PRA —
                    // never fail the close over reporting.
                    if (!empty($riderFigures['active'])) {
                        try {
                            $report->forceFill(['rider_summary' => $riderFigures])->save();
                        } catch (\Throwable $e) {
                            // rider_summary column missing pre-migration — skip detail
                        }
                    }
                    return $report;
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
        if (!$this->fbrPlanAllows('loyalty_enabled')) {
            // Display-only mask (never saved): plan without loyalty must not
            // offer redemption on the sale screen; store() rejects it too.
            $loyaltySettings->is_enabled = false;
        }
        $heldCount = \App\Models\FbrPosHeldSale::where('company_id', $companyId)->count();
        $activePromos = $this->fbrPlanAllows('deals_enabled')
            ? \App\Models\FbrPosPromotion::where('company_id', $companyId)
                ->where('is_active', true)->orderByDesc('id')->limit(20)->get()
            : collect();

        // 🌐 Classic create screen RETIRED (Aug 2026, owner order): the universal
        // screen is the ONLY FBR sale screen — fbr_universal_enabled no longer
        // consulted. fbr-pos/create.blade.php kept on disk as a DEAD file (PRA
        // convention: keep legacy views for reviewable diffs, never render them).
        $viewName = 'fbr-pos.universal';

        // Universal screen needs the customer list for its phone-lookup bar.
        // Task 100 (Aug 2026): never bake thousands of customers — over the cap
        // bake only the most-recently-active subset (instant/OFFLINE fallback);
        // /fbr-pos/api/customer-search is the source of truth for lookups.
        $custBakeCap = 500;
        $customersTruncated = false;
        $customers = collect();
        if ($viewName === 'fbr-pos.universal') {
            $custBase = \App\Models\PosCustomer::where('company_id', $companyId)->where('is_active', true);
            $customersTruncated = (clone $custBase)->count() > $custBakeCap;
            $customers = $customersTruncated
                ? (clone $custBase)->orderByDesc('updated_at')->limit($custBakeCap)
                    ->get(['id', 'name', 'phone', 'khata_balance'])
                    ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()
                : $custBase->orderBy('name')->get(['id', 'name', 'phone', 'khata_balance']);
        }

        // OFFLINE-FIRST BOOT (Aug 2026 — PRA port): fingerprint baked into the
        // page so a SW-cached copy of this screen can detect staleness via
        // /fbr-pos/api/boot-check. offlineAllowed = plan gate for NEW offline
        // queueing (baked, so it also joins the settings fingerprint).
        $offlineAllowed = $this->fbrPlanAllows('offline_enabled');
        $bootFp = $this->fbrBootFingerprint($company, Auth::guard('fbrpos')->user());

        return response(view($viewName, compact(
            'company', 'products', 'fbrReportingEnabled', 'frequentProducts',
            'terminals', 'currentShift', 'loyaltySettings', 'heldCount', 'activePromos',
            'pendingDayCloses', 'customers', 'customersTruncated', 'bootFp', 'offlineAllowed'
        )))
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->header('Pragma', 'no-cache')
        ->header('Expires', '0');
    }

    /**
     * OFFLINE-FIRST SALE SCREEN (Aug 2026 — FBR port of PRA): the service worker
     * serves /fbr-pos/create cache-first (SALE_CACHE in public/sw.js). This
     * fingerprint — baked into the rendered page AND served by bootCheck() —
     * lets a cached copy detect that it is stale (new deploy, catalog change,
     * settings change, user/company switch) and reload itself once.
     * NEVER hash raw companies.updated_at (frequent writers → reload loop);
     * posConfigRev() hashes an explicit whitelist incl. fbr_reporting_enabled.
     */
    private function fbrBootFingerprint(Company $company, $user): array
    {
        $companyId = $company->id;
        $agg = function ($query) {
            $row = $query->selectRaw('COUNT(*) AS cnt, MAX(updated_at) AS mx')->first();
            return ($row->cnt ?? 0) . ':' . (string) ($row->mx ?? '');
        };
        $promoAgg = $agg(\App\Models\FbrPosPromotion::where('company_id', $companyId));
        $catalogRev = md5(implode('|', [
            $agg(Product::where('company_id', $companyId)),
            $promoAgg,
            $agg(\App\Models\FbrPosTerminal::where('company_id', $companyId)),
            // Promotions carry date windows — a day change must refresh the
            // screen, but ONLY for companies that actually have promos (PRA
            // deals lesson: no needless morning reload churn for the rest).
            str_starts_with($promoAgg, '0:') ? '' : now()->toDateString(),
        ]));

        $settingsRev = md5(json_encode([
            $company->posConfigRev(),
            optional($user->updated_at)->timestamp,
            (string) ($user->role ?? ''),
            $agg(\App\Models\FbrPosLoyaltySetting::where('company_id', $companyId)),
            // Plan gates baked into the screen — a plan change (upgrade/downgrade)
            // must refresh the offline-cached copy.
            (bool) $this->fbrPlanAllows('offline_enabled'),
            (bool) $this->fbrPlanAllows('deals_enabled'),
            (bool) $this->fbrPlanAllows('loyalty_enabled'),
        ]));

        $screenPath = resource_path('views/fbr-pos/universal.blade.php');
        return [
            'u' => (int) $user->id,
            'c' => (int) $companyId,
            's' => is_file($screenPath) ? (string) @filemtime($screenPath) : '0',
            'cat' => $catalogRev,
            'set' => $settingsRev,
        ];
    }

    /**
     * GET /fbr-pos/api/boot-check — tiny freshness probe for the SW-cached sale
     * screen. Never cached ('/api/' is in the SW skip list; no-store headers).
     */
    public function bootCheck()
    {
        $user = Auth::guard('fbrpos')->user();
        $company = Company::find(app('currentCompanyId'));
        if (!$user || !$company) {
            return response()->json(['ok' => false], 401);
        }
        return response()->json(['ok' => true, 'fp' => $this->fbrBootFingerprint($company, $user)])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
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
            // 'credit' = Udhaar/Khata sale (Aug 2026 — Retail Core): requires a saved
            // customer; amount lands in fbr_customer_ledgers + pos_customers.khata_balance.
            'payment_method' => 'required|in:cash,card,bank_transfer,online,credit',
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
            // Task 156: order-type + delivery-address SNAPSHOT — frozen on the
            // bill so later edits to the customer's saved address never rewrite
            // receipts. Feeds the Pending Deliveries panel (Task 122).
            'order_type' => 'nullable|string|max:20',
            'delivery_address' => 'nullable|string|max:500',
            // OFFLINE-FIRST FBR POS (Aug 2026 — PRA port): client idempotency UUID
            // rides on EVERY attempt; queued-bill replays also carry the ORIGINAL
            // sale moment + cashier + branch so a next-morning sync books the bill
            // under the right date/user/branch (server clamps + company-checks).
            'offline_uuid' => 'nullable|string|max:64',
            'offline_queued_at' => 'nullable|date',
            'offline_queued_by' => 'nullable|integer',
            'offline_branch_id' => 'nullable|integer',
            ]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            Log::warning('FBR POS Store: validation failed', [
                'errors' => $ve->errors(),
                'item_count' => count($request->input('items', [])),
                'payment_method' => $request->input('payment_method'),
            ]);
            throw $ve;
        }

        // ── IDEMPOTENCY REPLAY GUARD (Aug 2026) ──────────────────────────────────
        // Mirrors PosController::store() offline_uuid pattern. The client generates
        // one UUID per bill attempt and sends it on EVERY submit — online and any
        // retry after a lost response. If a transaction with this UUID already exists
        // for this company, return the existing bill's success payload immediately,
        // BEFORE quota checks, ledger writes, or stock deductions. This is the
        // primary protection against double-submit / network-retry duplicates.
        //
        // Schema guard: the column may not exist yet on a not-yet-migrated PROD.
        // In that window the guard is simply skipped — no crash, no duplicate
        // protection until the migration lands (consistent with PRA pattern).
        $offlineUuid = trim((string) $request->input('offline_uuid', ''));
        $offlineUuidColumnExists = $offlineUuid !== '' && \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'offline_uuid');
        if ($offlineUuidColumnExists) {
            $existing = FbrPosTransaction::where('company_id', $companyId)
                ->where('offline_uuid', $offlineUuid)
                ->first();
            if ($existing) {
                $replayMsg = __('pos.invoice_already_synced', ['number' => $existing->invoice_number]);
                if ($request->wantsJson()) {
                    return response()->json([
                        'success'          => true,
                        'replayed'         => true,
                        'transaction_id'   => $existing->id,
                        'invoice_number'   => $existing->invoice_number,
                        'total_amount'     => (float) $existing->total_amount,
                        'fbr_invoice_number' => $existing->fbr_invoice_number,
                        'fbr_status'       => $existing->fbr_status,
                        'invoice_mode'     => $existing->invoice_mode,
                        'change_due'       => (float) $existing->change_due,
                        'message'          => $replayMsg,
                    ]);
                }
                return redirect()->route('fbrpos.show', $existing->id)->with('success', $replayMsg);
            }
        }

        $fbrEnabled = (bool) $company->fbr_reporting_enabled;
        // 💾 SAVE AS PROVISIONAL (universal sale screen) — cashier explicitly asked
        // for a local bill (no FBR submission now). Same semantics as a local-mode
        // sale: invoice_mode='local' + fbr_status='local'. Promote later via F10.
        $saveAsProvisional = $request->boolean('save_as_provisional');

        // Order-type flow rules (owner, Jul 2026 — PRA parity, Task 164): on restaurant-ish
        // companies (order-type widget visible = any of tables/kot/kitchen/delivery on),
        // provisional bills are DELIVERY-only — Dine-In uses the Hold/KOT/recall procedure,
        // Takeaway is billed directly as final. Only enforced when the client sent
        // order_type (older queued offline payloads lack it — never strand a replay).
        if ($saveAsProvisional && $request->filled('order_type') && $request->input('order_type') !== 'delivery') {
            $flowFeatures = \App\Services\PosFeatureService::forCompany($company);
            if (($flowFeatures->tables ?? false) || ($flowFeatures->kot ?? false) || ($flowFeatures->kitchen ?? false) || ($flowFeatures->delivery ?? false)) {
                $flowMsg = __('pos.provisional_delivery_only_flow');
                if ($request->expectsJson()) {
                    return response()->json(['success' => false, 'error' => $flowMsg, 'message' => $flowMsg], 422);
                }
                return back()->withInput()->with('error', $flowMsg);
            }
        }

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

        // OFFLINE-FIRST FBR POS (Aug 2026 — PRA port): honor the ORIGINAL sale
        // moment + cashier + branch for offline-queued bills. Only trusted when
        // the request also carries an offline_uuid (came through the offline
        // queue). Timestamp clamped to [now-3d, now] — a wrong PC clock or a
        // stale queue can never back-date beyond the wash window or post-date.
        // Attribution only sticks when the claimed user/branch belongs to THIS
        // company (spoof-safe) AND the plan actually includes offline billing —
        // server-side gate so a hand-crafted POST can't backdate bills on a
        // package without the offline feature (offline_uuid dedupe itself stays
        // ungated: it must ALWAYS work to stop lost-response duplicates).
        $offlineQueuedAt = null;
        $offlineQueuedBy = null;
        $offlineBranchId = null;
        if ($offlineUuidColumnExists && $this->fbrPlanAllows('offline_enabled')) {
            if ($request->filled('offline_queued_at')) {
                try {
                    // Browser sends UTC ISO ("...Z") — convert to app TZ (Asia/Karachi)
                    // or the stored created_at lands 5h early → wrong business day.
                    $qa = \Carbon\Carbon::parse($request->input('offline_queued_at'))->setTimezone(config('app.timezone'));
                    if ($qa->lt(now()->subDays(3))) {
                        $qa = now()->subDays(3);
                    }
                    if ($qa->gt(now())) {
                        $qa = now();
                    }
                    $offlineQueuedAt = $qa;
                } catch (\Throwable $e) {
                    $offlineQueuedAt = null;
                }
            }
            if ($request->filled('offline_queued_by')) {
                $qbId = (int) $request->input('offline_queued_by');
                if ($qbId > 0 && $qbId !== (int) Auth::guard('fbrpos')->id()) {
                    $offlineQueuedBy = \App\Models\User::where('id', $qbId)
                        ->where('company_id', $companyId)
                        ->value('id');
                }
            }
            if ($request->filled('offline_branch_id')) {
                $obId = (int) $request->input('offline_branch_id');
                if ($obId > 0) {
                    $offlineBranchId = \App\Models\Branch::where('company_id', $companyId)
                        ->where('id', $obId)
                        ->value('id');
                }
            }
        }

        try {
            $transaction = DB::transaction(function () use ($request, $companyId, $company, $invoiceMode, $initialFbrStatus, $offlineUuid, $offlineUuidColumnExists, $offlineQueuedAt, $offlineQueuedBy, $offlineBranchId) {
                $subtotal = 0;
                $totalTax = 0;
                $itemsData = [];

                $defaultTaxRate = 18;

                // ── COST SNAPSHOT (Munafa report, Aug 2026) ──────────────────────
                // Freeze the purchase cost per line at SALE time so later purchase
                // rate changes never rewrite profit history. avg first, last as
                // fallback; manual items (no product_id) stay NULL = cost unknown.
                $costProductIds = collect($request->items)->pluck('product_id')->filter()->unique()->values();
                $costMap = $costProductIds->isEmpty() ? collect() :
                    \App\Models\InventoryStock::where('company_id', $companyId)
                        ->whereNull('branch_id')
                        ->whereIn('product_id', $costProductIds)
                        ->get(['product_id', 'avg_purchase_price', 'last_purchase_price'])
                        ->keyBy('product_id');

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

                    // ─── Third Schedule: resolve from DB FIRST (authoritative) ───────────
                    // Must happen before $isExempt / $taxRate so that Third-Schedule
                    // products always get 0-tax regardless of what the client sent.
                    // For product-backed lines: DB is the only truth — client payload
                    // is ignored to prevent tax-spoofing via crafted requests.
                    // For manual lines (no product_id): flag is always false (cashier
                    // cannot manually create a Third-Schedule ad-hoc line).
                    $fbrProdId = $item['product_id'] ?? null;
                    $fbrIsThirdSchedule = false;
                    $fbrDbLookupDone = false;
                    if ($fbrProdId && \Illuminate\Support\Facades\Schema::hasColumn('products', 'is_third_schedule')) {
                        // Scoped to current company — prevents cross-company flag injection
                        $fbrProd = \App\Models\Product::where('company_id', $companyId)->find($fbrProdId);
                        $fbrIsThirdSchedule = $fbrProd ? (bool) $fbrProd->is_third_schedule : false;
                        $fbrDbLookupDone = true;
                    }
                    // Client payload is authoritative ONLY when the product has no catalog
                    // entry (e.g. barcode-scanned item that was never persisted); for all
                    // catalog-backed lines the DB flag wins unconditionally.
                    // (No fallback: manual/custom lines default to false above.)

                    $isExempt = !empty($item['is_tax_exempt']);
                    // Third Schedule overrides exempt (belt-and-suspenders)
                    if ($fbrIsThirdSchedule) { $isExempt = true; }
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

                    $itemDataRow = [
                        'item_name' => $item['item_name'],
                        'hs_code' => $item['hs_code'] ?? null,
                        'uom' => $uom,
                        'product_id' => $item['product_id'] ?? null,
                        'quantity' => $qty,
                        'unit_price' => $price,
                        'cost_price' => (function () use ($item, $costMap) {
                            $s = !empty($item['product_id']) ? $costMap->get($item['product_id']) : null;
                            if (!$s) { return null; }
                            $avg = (float) $s->avg_purchase_price;
                            $last = (float) $s->last_purchase_price;
                            return $avg > 0 ? $avg : ($last > 0 ? $last : null);
                        })(),
                        'discount' => 0,
                        'item_discount' => $itemDiscount,
                        'tax_rate' => $taxRate,
                        'tax_amount' => $lineTax,
                        'subtotal' => $lineSubtotal,
                        'total' => $lineTotal,
                        'is_tax_exempt' => $isExempt,
                    ];
                    if (\Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transaction_items', 'is_third_schedule')) {
                        $itemDataRow['is_third_schedule'] = $fbrIsThirdSchedule;
                    }
                    $itemsData[] = $itemDataRow;
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
                // Operational deals gate: a plan without deals_enabled must not
                // APPLY promotions at billing time (management pages are gated
                // separately). Explicit reject — never a silent total mismatch.
                if ($request->promotion_id && !$this->fbrPlanAllows('deals_enabled')) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'promotion_id' => __('pos.plan_locked_feature'),
                    ]);
                }
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
                // Operational loyalty gate — redemption changes the payable
                // total, so reject explicitly when the plan lacks loyalty.
                $loyaltyPlanOk = $this->fbrPlanAllows('loyalty_enabled');
                if ($loyaltyPointsRedeemed > 0 && !$loyaltyPlanOk) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'loyalty_points_redeemed' => __('pos.plan_locked_feature'),
                    ]);
                }
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

                // Loyalty earn (1 point per rs_per_point on net total) —
                // accrual silently skipped when the plan lacks loyalty (earn
                // never changes the payable total, so no hard reject needed).
                $loyaltyPointsEarned = 0;
                if ($loyaltyPlanOk && $loyaltySettings->is_enabled && $request->customer_id && $loyaltySettings->rs_per_point > 0) {
                    $loyaltyPointsEarned = (int) floor($totalAmount / (float) $loyaltySettings->rs_per_point);
                }

                // ── UDHAAR GUARD (Aug 2026 — Retail Core) ────────────────────────
                // Credit sale without a saved customer is meaningless (no one to
                // collect from). Block server-side; UI enforces it too.
                if ($request->payment_method === 'credit' && !$request->customer_id) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'customer_id' => 'Udhaar bill ke liye customer chunna zaroori hai.',
                    ]);
                }
                // Plan gate (strict feature binding): khata/udhaar sale needs
                // a plan with khata_enabled — same source hierarchy as PRA
                // (internal/override/trial pass via planAllows).
                if ($request->payment_method === 'credit'
                    && !$this->fbrPlanAllows('khata_enabled')) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'payment_method' => __('pos.plan_locked_feature'),
                    ]);
                }
                // Tenant guard: the chosen customer must belong to THIS company —
                // a foreign customer_id must never create a ledger row or mutate
                // another tenant's khata balance.
                if ($request->payment_method === 'credit') {
                    $khataOwnerOk = \App\Models\PosCustomer::where('company_id', $companyId)
                        ->where('id', $request->customer_id)->exists();
                    if (!$khataOwnerOk) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'customer_id' => 'Customer nahi mila — dobara select karein.',
                        ]);
                    }
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

                // Task 156: order-type + delivery-address snapshot. hasColumn-guarded
                // so a not-yet-migrated PROD schema never 500s a sale (fields simply
                // don't stick until the migration lands — PRA rider-columns pattern).
                $orderTypeColumnsExist = \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'order_type')
                    && \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'delivery_address');
                $orderTypeSnapshot = $request->filled('order_type')
                    ? substr((string) $request->input('order_type'), 0, 20)
                    : null;
                $orderTypeFields = $orderTypeColumnsExist ? [
                    'order_type' => $orderTypeSnapshot,
                    'delivery_address' => $orderTypeSnapshot === 'delivery'
                        ? ($request->input('delivery_address') ?: null)
                        : null,
                ] : [];

                // Order Matching (Aug 2026) — token_no / order_code from held sale.
                // hasColumn-guarded: silently skipped on a not-yet-migrated PROD schema.
                $omFields = [];
                if (\Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'token_no')) {
                    $inTokenNo = $request->input('token_no');
                    $omFields['token_no'] = $inTokenNo !== null ? (int) $inTokenNo : null;
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'order_code')) {
                    $inOrderCode = trim((string) ($request->input('order_code', '') ?? ''));
                    $omFields['order_code'] = $inOrderCode !== '' ? strtoupper(substr($inOrderCode, 0, 10)) : null;
                }

                // Pass the validated offline_uuid into the row only when the column exists.
                // use() captures the outer-scope values (set before the DB::transaction closure).
                $offlineUuidField = ($offlineUuidColumnExists && $offlineUuid !== '')
                    ? ['offline_uuid' => $offlineUuid]
                    : [];

                $transaction = FbrPosTransaction::create($orderTypeFields + $omFields + $offlineUuidField + [
                    'company_id' => $companyId,
                    // Offline sync: book under the branch the bill was RUNG UP on
                    // (company-verified above), not whoever's session synced it.
                    'branch_id' => $offlineBranchId ?? (app()->bound('currentBranchId') ? app('currentBranchId') : null),
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
                    // Offline sync: credit the cashier who RANG UP the bill, not
                    // whoever's session replayed the queue next morning.
                    'created_by' => $offlineQueuedBy ?: Auth::guard('fbrpos')->id(),
                ]);

                // Offline sync: stamp the bill with the ORIGINAL (clamped) sale
                // moment. created_at is NOT mass-assignable — set + save explicitly.
                if ($offlineQueuedAt) {
                    $transaction->created_at = $offlineQueuedAt;
                    // Task 492: the creating hook already stamped business_date
                    // from "now" (the SYNC moment) — re-stamp from the original
                    // sale moment so an offline 1 AM bill lands in the right
                    // trading day.
                    try {
                        if (\Schema::hasColumn('fbr_pos_transactions', 'business_date')) {
                            $transaction->business_date = \App\Services\PosBusinessDay::forMomentFbr((int) $companyId, $offlineQueuedAt);
                        }
                    } catch (\Throwable $e) {
                        // Never block a sync over the stamp — backfill repairs it.
                    }
                    $transaction->save();
                }

                // Update promotion usage
                if ($promo && $promotionDiscount > 0) {
                    $promo->increment('usage_count');
                }

                // Update shift totals
                if ($shift) {
                    $cashTotal = 0; $cardTotal = 0; $udhaarTotal = 0; $otherTotal = 0;
                    foreach ($paymentBreakdown as $pb) {
                        $m = strtolower($pb['method'] ?? '');
                        $a = (float) ($pb['amount'] ?? 0);
                        if ($m === 'cash') $cashTotal += $a;
                        elseif (in_array($m, ['card','credit_card','debit_card'])) $cardTotal += $a;
                        elseif ($m === 'credit') $udhaarTotal += $a; // Udhaar/Khata — not in the cash drawer
                        else $otherTotal += $a;
                    }
                    $shift->sales_count = (int) $shift->sales_count + 1;
                    $shift->total_sales = (float) $shift->total_sales + $totalAmount;
                    $shift->total_cash = (float) $shift->total_cash + $cashTotal;
                    $shift->total_card = (float) $shift->total_card + $cardTotal;
                    // total_udhaar column guarded: silently no-ops on old schema until migration lands.
                    if (\Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_shifts', 'total_udhaar')) {
                        $shift->total_udhaar = (float) $shift->total_udhaar + $udhaarTotal;
                    }
                    $shift->total_other = (float) $shift->total_other + $otherTotal;
                    $shift->save();
                }

                // Update customer loyalty + stats
                if ($request->customer_id) {
                    $customer = \App\Models\PosCustomer::where('company_id', $companyId)
                        ->find($request->customer_id);
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

                // ── UDHAAR/KHATA LEDGER (Aug 2026 — Retail Core) ─────────────────
                // Same DB transaction as the sale: ledger row + cached balance move
                // together or not at all.
                if ($request->payment_method === 'credit' && $request->customer_id) {
                    $khataCustomer = \App\Models\PosCustomer::lockForUpdate()
                        ->where('company_id', $companyId)
                        ->find($request->customer_id);
                    if (!$khataCustomer) {
                        // Customer vanished between guard and lock — abort the whole
                        // sale rather than booking a credit bill with no ledger row.
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'customer_id' => 'Customer nahi mila — udhaar bill cancel.',
                        ]);
                    }
                    if ($khataCustomer) {
                        $newBalance = round((float) $khataCustomer->khata_balance + $totalAmount, 2);
                        \App\Models\FbrCustomerLedger::create([
                            'company_id' => $companyId,
                            'customer_id' => $khataCustomer->id,
                            'entry_type' => 'udhaar',
                            'amount' => $totalAmount,
                            'balance_after' => $newBalance,
                            'transaction_id' => $transaction->id,
                            'note' => "Udhaar bill {$invoiceNumber}",
                            'created_by' => Auth::guard('fbrpos')->id(),
                        ]);
                        $khataCustomer->update(['khata_balance' => $newBalance]);
                    }
                }

                // ── STOCK DEDUCT (Aug 2026 — Retail Core) ────────────────────────
                // Only when the company has stock tracking ON. Sale is NEVER blocked
                // by stock (retail reality: bill first, count later) — quantity may
                // go negative and shows as a red badge in the stock report.
                if ($company->inventory_enabled) {
                    foreach ($itemsData as $itemData) {
                        if (!empty($itemData['product_id']) && (float) $itemData['quantity'] > 0) {
                            try {
                                \App\Services\InventoryService::deductStock(
                                    $companyId,
                                    $itemData['product_id'],
                                    (float) $itemData['quantity'],
                                    (float) $itemData['unit_price'],
                                    \App\Models\InventoryMovement::TYPE_SALE,
                                    null,
                                    ['type' => 'fbr_pos_transaction', 'id' => $transaction->id, 'number' => $invoiceNumber],
                                    null,
                                    Auth::guard('fbrpos')->id()
                                );
                            } catch (\Throwable $stockEx) {
                                // Stock failure must NEVER kill a sale — log and move on.
                                Log::warning('FBR POS stock deduct failed', ['tx' => $transaction->id, 'err' => $stockEx->getMessage()]);
                            }
                        }
                    }
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

            // ==== AUTO-RETRY ENGINE ====
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
        } catch (\Illuminate\Database\QueryException $qe) {
            // ── RACE-LOSER RECOVERY (concurrent identical submits) ──────────────────
            // When two requests carrying the same offline_uuid hit store() at the same
            // instant, both pass the app-level replay guard (neither row exists yet),
            // and both enter DB::transaction. One wins the INSERT; the other gets a
            // MySQL 1062 Duplicate Entry on the unique(company_id, offline_uuid) index.
            // Without this catch, the loser would surface as a 500 that the client
            // cannot distinguish from a real failure — the cashier would see an error
            // popup and possibly try to ring up the bill again.
            //
            // Recovery: detect the 1062 on our index, re-lookup the winner's row, and
            // return its success payload — exactly the same shape as the app-level
            // replay path above. The client sees a transparent success.
            $isUniqueViolation = $qe->getCode() == 23000        // SQLSTATE: integrity constraint
                && str_contains($qe->getMessage(), '1062')      // MySQL: Duplicate entry
                && str_contains($qe->getMessage(), 'fbr_txn_offline_uuid_unique');

            if ($isUniqueViolation && $offlineUuidColumnExists) {
                $winner = FbrPosTransaction::where('company_id', $companyId)
                    ->where('offline_uuid', $offlineUuid)
                    ->first();
                if ($winner) {
                    $raceMsg = __('pos.invoice_already_synced', ['number' => $winner->invoice_number]);
                    Log::info('FBR POS race-loser recovered', ['company' => $companyId, 'uuid' => $offlineUuid, 'winner_id' => $winner->id]);
                    if ($request->wantsJson()) {
                        return response()->json([
                            'success'            => true,
                            'replayed'           => true,
                            'transaction_id'     => $winner->id,
                            'invoice_number'     => $winner->invoice_number,
                            'total_amount'       => (float) $winner->total_amount,
                            'fbr_invoice_number' => $winner->fbr_invoice_number,
                            'fbr_status'         => $winner->fbr_status,
                            'invoice_mode'       => $winner->invoice_mode,
                            'change_due'         => (float) $winner->change_due,
                            'message'            => $raceMsg,
                        ]);
                    }
                    return redirect()->route('fbrpos.show', $winner->id)->with('success', $raceMsg);
                }
            }

            // Any other QueryException (real DB error) falls through to the generic handler.
            Log::error('FBR POS Store Error', ['error' => $qe->getMessage()]);
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => __('pos.failed_create_sale', ['error' => $qe->getMessage()])], 500);
            }
            return back()->withInput()->with('error', __('pos.failed_create_sale', ['error' => $qe->getMessage()]));
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

        // config_error bills are included in the Fail Queue page (but NOT in the
        // auto-retry pool on the sale screen). They get a distinct visual treatment
        // and a "Fix Settings" note so the admin knows what to do.
        $query = FbrPosTransaction::where('company_id', $companyId)
            ->whereIn('fbr_status', ['failed', 'pending', 'config_error'])
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
                SUM(CASE WHEN fbr_status IN ('failed','config_error') THEN total_amount ELSE 0 END) as failed_amount,
                SUM(CASE WHEN fbr_status = 'config_error' THEN 1 ELSE 0 END) as config_error_count
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
     * Schedule retry job for a single failed invoice (also accepts config_error bills).
     */
    public function failQueueRetryOne($id)
    {
        $companyId = app('currentCompanyId');
        $tx = FbrPosTransaction::where('company_id', $companyId)->findOrFail($id);

        if ($tx->fbr_status === 'submitted') {
            return back()->with('error', __('pos.already_submitted_fbr_short'));
        }

        // config_error bills may be manually retried from the Fail Queue page after
        // the admin has fixed the FBR Settings (POSID / token). Reset to 'pending' so
        // the job can attempt submission; if config is still broken it will re-land as
        // 'config_error' (not 'failed') and stay out of the auto-retry pool.
        $tx->fbr_submission_hash = null;
        $tx->fbr_status = 'pending';
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

            // Pending-bill day-close policy (FBR mirror of PRA 'Khud Final', Aug 2026):
            // 'carry' = leave provisionals untouched (default) | 'finalize' = auto-promote at day close.
            if ($request->has('dayclose_pending_update')) {
                $request->validate(['pending_policy' => 'required|in:carry,finalize']);
                $company->update(['pos_dayclose_provisional_action' => $request->pending_policy]);
                return back()->with('success', __('pos.fbr_dayclose_policy_saved'));
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

    /**
     * FBR POS receipt print-style settings (bold + logo position).
     * Stored in invoice_display_prefs['pos_style'] — same key as PRA, so
     * posReceiptStyle() reads changes here immediately on the FBR receipt.
     * logo_finals_only is deliberately omitted: FBR POS has no local-bill flow.
     */
    public function fbrReceiptSettings(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company   = Company::find($companyId);
        $user      = Auth::guard('fbrpos')->user();

        if ($user->role !== 'company_admin') {
            abort(403);
        }

        if ($request->isMethod('post')) {
            $request->validate([
                'rp_style_bold'   => 'nullable|in:1',
                'rp_logo_style'   => 'required|in:side,center',
                'rp_order_match'  => 'nullable|in:off,token,code',
                'rp_print_confirm' => 'nullable|in:1',
            ]);

            $prefs = $company->invoice_display_prefs ?? [];
            $style = is_array($prefs['pos_style'] ?? null) ? $prefs['pos_style'] : [];

            // Preserve keys we don't touch on this page (show_logo, logo_finals_only,
            // show_menu_qr, pdf_paper) so saving here never clobbers PRA settings.
            $style['bold'] = $request->has('rp_style_bold');
            $style['logo'] = $request->input('rp_logo_style', 'center');

            $prefs['pos_style'] = $style;
            $company->invoice_display_prefs = $prefs;

            // Order Matching style — stored directly on the companies row (shared with PRA).
            // hasColumn guard: silently no-ops on a not-yet-migrated PROD schema.
            // Task 652: validate against the allowed set (mirrors PRA) — a missing
            // or garbage value keeps the company's current style instead of
            // silently forcing 'off' (which would break the new 'code' default).
            if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'order_match_style')
                && in_array($request->input('rp_order_match'), ['off', 'token', 'code'], true)) {
                $company->order_match_style = $request->input('rp_order_match');
            }

            // Task 565: opt-in Yes/No print-confirm dialog — shared flag with PRA
            // POS, stored in pos_printer_settings (normalized-shape rebuild so no
            // other key gets dropped).
            $pset = $company->printerSettings();
            $pset['print_confirm_ask'] = $request->has('rp_print_confirm');
            $company->pos_printer_settings = $pset;

            $company->save();

            return back()->with('success', __('pos.receipt_style_saved'));
        }

        return view('fbr-pos.receipt-settings', compact('company'));
    }

    /**
     * FBR KOT — kitchen ticket for a held sale.
     * GET /fbr-pos/held/{id}/kitchen-ticket
     */
    public function kotTicket(int $id)
    {
        if ($resp = $this->fbrPlanGate('kot_enabled')) return $resp;
        $companyId = app('currentCompanyId');
        $company   = Company::find($companyId);
        $held      = \App\Models\FbrPosHeldSale::where('company_id', $companyId)->findOrFail($id);

        $cartData  = $held->cart_data ?? [];
        $items     = is_array($cartData['items'] ?? null) ? $cartData['items'] : [];
        $tokenNo   = isset($held->token_no)   ? (int)  $held->token_no   : (isset($cartData['token_no'])   ? (int)  $cartData['token_no']   : null);
        $orderCode = isset($held->order_code) ? (string) $held->order_code : (isset($cartData['order_code']) ? (string) $cartData['order_code'] : null);
        $customerName = $held->customer_name ?? ($cartData['customer_name'] ?? null);
        $kitchenNotes = $cartData['kitchen_notes'] ?? null;
        $now = now();

        $autoPrint = request()->boolean('auto_print');

        return view('fbr-pos.kitchen-ticket', compact(
            'company', 'held', 'items', 'tokenNo', 'orderCode',
            'customerName', 'kitchenNotes', 'now', 'autoPrint'
        ));
    }

    /**
     * FBR KOT — kitchen ticket reprinted from a completed transaction.
     * GET /fbr-pos/transaction/{id}/kot-reprint
     */
    public function kotReprint(int $id)
    {
        if ($resp = $this->fbrPlanGate('kot_enabled')) return $resp;
        $companyId = app('currentCompanyId');
        $company   = Company::find($companyId);

        $transaction = \App\Models\FbrPosTransaction::with('items')
            ->where('company_id', $companyId)
            ->findOrFail($id);

        $tokenNo   = \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'token_no')
            ? ($transaction->token_no ? (int) $transaction->token_no : null)
            : null;
        $orderCode = \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'order_code')
            ? ($transaction->order_code ?: null)
            : null;

        // Build a simple items array from the transaction lines (KOT only needs name+qty).
        $items = $transaction->items->map(function ($it) {
            return [
                'item_name'     => $it->item_name,
                'quantity'      => (float) $it->quantity,
                'special_notes' => null,
            ];
        })->all();

        $customerName = $transaction->customer_name;
        $kitchenNotes = null;
        $now = $transaction->created_at ?? now();
        $held = null; // not a held sale — template branches on this

        $autoPrint = request()->boolean('auto_print');

        return view('fbr-pos.kitchen-ticket', compact(
            'company', 'held', 'items', 'tokenNo', 'orderCode',
            'customerName', 'kitchenNotes', 'now', 'autoPrint'
        ));
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
     * 🍽️ Toggle Auto-KOT for FBR POS (admin-only, company-scoped).
     *
     * FBR equivalent of PosController::toggleAutoKot.  Persists in
     * companies.auto_print_kot (same column as PRA); a hasColumn guard
     * keeps the site alive if the migration has not yet run on a given
     * deployment.
     *
     * Gate: kitchen_printer_enabled must be ON (FBR uses this column
     * instead of PosFeatureService's features->kot which always returns
     * false for FBR companies).
     */
    public function toggleAutoKot()
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') {
            return response()->json(['success' => false, 'message' => __('pos.only_company_admin_toggle_fbr')], 403);
        }
        if ($resp = $this->fbrPlanGate('kot_enabled')) return $resp;

        $companyId = app('currentCompanyId');
        $company   = Company::findOrFail($companyId);

        if (!$company->kitchen_printer_enabled) {
            return response()->json([
                'success' => false,
                'message' => __('pos.auto_kot_requires_feature'),
            ], 422);
        }

        if (!\Illuminate\Support\Facades\Schema::hasColumn('companies', 'auto_print_kot')) {
            return response()->json([
                'success' => false,
                'message' => 'Schema not ready — run migrations first.',
            ], 503);
        }

        $company->auto_print_kot = !(bool) $company->auto_print_kot;
        $company->save();

        return response()->json([
            'success' => true,
            'enabled' => (bool) $company->auto_print_kot,
            'message' => $company->auto_print_kot
                ? __('pos.auto_kot_enabled')
                : __('pos.auto_kot_disabled'),
        ]);
    }

    /**
     * 🌐 Universal sale screen toggle — RETIRED (Aug 2026).
     * The classic create screen is gone; /fbr-pos/create always serves the
     * universal screen. Endpoint kept so stale cached settings/customize pages
     * don't 404 — it now only force-enables and reports ON, never disables.
     */
    public function toggleUniversal()
    {
        if (Auth::guard('fbrpos')->user()->role !== 'company_admin') {
            return response()->json(['success' => false, 'message' => __('pos.only_company_admin_toggle_universal')], 403);
        }

        Company::where('id', app('currentCompanyId'))->update(['fbr_universal_enabled' => true]);

        return response()->json([
            'success' => true,
            'enabled' => true,
            'message' => __('pos.universal_enabled'),
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

        // Task 492: shop-facing sales reports group by TRADING day
        // (business_date) — a 1 AM sale shows in yesterday's figures. FBR /
        // tax reporting surfaces keep real created_at.
        $bizToday = $this->fbrBizToday($companyId);
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $monthScope = fn ($q) => $this->hasBizDate()
            ? $q->whereBetween('business_date', [$monthStart, $monthEnd])
            : $q->whereYear('created_at', now()->year)->whereMonth('created_at', now()->month);

        // Return / credit-note netting (Task 591 — FBR mirror of PRA Task 570/578
        // reports convention): SIGNED money sums, SALES-only bill counts.
        [$signExpr, $saleRowExpr] = $this->fbrReturnNettingExprs();

        $todayStats = FbrPosTransaction::where('company_id', $companyId)
            ->tap(fn ($q) => $this->whereBizDate($q, $bizToday))
            ->selectRaw("COALESCE(SUM({$saleRowExpr}),0) as count, COALESCE(SUM(({$signExpr}) * total_amount),0) as revenue, COALESCE(SUM(({$signExpr}) * tax_amount),0) as tax, COALESCE(SUM(({$signExpr}) * discount_amount),0) as discount")
            ->first();

        $monthStats = FbrPosTransaction::where('company_id', $companyId)
            ->tap($monthScope)
            ->selectRaw("COALESCE(SUM({$saleRowExpr}),0) as count, COALESCE(SUM(({$signExpr}) * total_amount),0) as revenue, COALESCE(SUM(({$signExpr}) * tax_amount),0) as tax, COALESCE(SUM(({$signExpr}) * discount_amount),0) as discount")
            ->first();

        $dailySales = FbrPosTransaction::where('company_id', $companyId)
            ->tap($monthScope)
            ->selectRaw($this->bizDateExpr() . " as date, COALESCE(SUM({$saleRowExpr}),0) as count, COALESCE(SUM(({$signExpr}) * total_amount),0) as revenue")
            ->groupByRaw($this->bizDateExpr())
            ->orderBy('date')
            ->get();

        $paymentBreakdown = FbrPosTransaction::where('company_id', $companyId)
            ->tap($monthScope)
            ->selectRaw("payment_method, COALESCE(SUM({$saleRowExpr}),0) as count, COALESCE(SUM(({$signExpr}) * total_amount),0) as revenue")
            ->groupBy('payment_method')
            ->get();

        [$from, $to] = $this->resolveFbrReportRange($request);
        $rangeAnalytics = $this->buildFbrReportRangeAnalytics($companyId, $from, $to, Auth::guard('fbrpos')->user());

        return view('fbr-pos.reports', compact('company', 'todayStats', 'monthStats', 'dailySales', 'paymentBreakdown', 'rangeAnalytics'));
    }

    /**
     * ═══ Staff Hazri — FBR mirror (Task #560) ═══
     * Attendance report page — ADMIN/MANAGER-ONLY (cashiers kabhi staff ki
     * hazri na dekhein). Data = pos_user_sessions (fbrpos-guard logins bhi
     * Task 558 ke baad yahin likhte hain) + us business day ke FBR bills.
     * PRA report (PosController::hazriReport) untouched.
     */
    public function hazriReport(Request $request)
    {
        if ($resp = $this->fbrPlanGate('hazri_enabled')) {
            return $resp;
        }
        $companyId = app('currentCompanyId');
        $user = Auth::guard('fbrpos')->user();
        if (!$user || !$user->isPosAdmin()) {
            abort(403);
        }
        $company = Company::find($companyId);

        $date = $request->get('date');
        try {
            $date = $date
                ? \Carbon\Carbon::parse($date)->toDateString()
                : \App\Services\PosBusinessDay::currentFbr($companyId);
        } catch (\Throwable $e) {
            $date = \App\Services\PosBusinessDay::currentFbr($companyId);
        }

        $rows = $this->buildFbrHazriRows($companyId, $date);

        return view('fbr-pos.reports-hazri', compact('company', 'date', 'rows'));
    }

    /**
     * Hazri rows for one BUSINESS day (6 AM → next 6 AM window, wahi rule jo
     * PRA-side buildHazriRows ka hai). Ek row per staff member: pehla login,
     * aakhri logout (ya last-seen jab logout kabhi dabaya hi nahi), session
     * count, duty minutes + FBR bills. Table na ho (prod migrate pending) to
     * khali array — report kabhi na toote.
     */
    private function buildFbrHazriRows(int $companyId, string $date): array
    {
        return \App\Support\PosSessionHazriRows::build(
            $companyId,
            $date,
            // Bills of the SAME business day. business_date column prod par
            // abhi missing ho sakta hai — created_at window fallback.
            function ($start, $end) use ($companyId, $date) {
                $billQuery = FbrPosTransaction::where('company_id', $companyId);
                if ($this->hasBizDate()) {
                    $billQuery->where('business_date', $date);
                } else {
                    $billQuery->where('created_at', '>=', $start)->where('created_at', '<', $end);
                }
                return $billQuery
                    ->selectRaw('created_by, COUNT(*) as bill_count, MIN(created_at) as first_sale, MAX(created_at) as last_sale, SUM(total_amount) as revenue')
                    ->groupBy('created_by')
                    ->get()
                    ->keyBy('created_by');
            },
            'fbr hazri rows failed'
        );
    }

    /**
     * Biometric punch rows for one BUSINESS day (FBR mirror of PRA
     * PosController::buildBiometricRows — Task #563). Same 6 AM → 6 AM window.
     * One row per staff member (or unmapped PIN) with first check-in, last
     * check-out, punch counts and duty hours. Returns [] when the table is
     * missing or on any error.
     */
    private function buildFbrBiometricRows(int $companyId, string $date): array
    {
        return \App\Support\PosBiometricRows::build($companyId, $date);
    }

    /**
     * A4 PDF export of the range analytics (FBR mirror of the PRA version).
     */
    public function reportsAnalyticsPdf(Request $request)
    {
        if ($resp = $this->fbrPlanGate('analytics_enabled')) return $resp;
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        [$from, $to] = $this->resolveFbrReportRange($request);
        $analytics = $this->buildFbrReportRangeAnalytics($companyId, $from, $to, Auth::guard('fbrpos')->user());

        return $this->renderReportPdf(
            'fbr-pos.reports-analytics-pdf',
            compact('company', 'analytics'),
            'FBR-Sales-Analytics-' . $analytics->from . '-to-' . $analytics->to . '.pdf'
        );
    }

    /**
     * CSV export of range transactions (FBR mirror of PosController::exportReportCsv).
     * reports_enabled plan gate; cashiers/viewers are locked to their own sales.
     * GET /fbr-pos/reports/export-csv?from=&to=&branch_id=
     */
    public function exportReportCsv(Request $request)
    {
        if ($resp = $this->fbrPlanGate('reports_enabled')) return $resp;

        $companyId = app('currentCompanyId');
        [$from, $to] = $this->resolveFbrReportRange($request);
        $user = Auth::guard('fbrpos')->user();

        $q = FbrPosTransaction::where('company_id', $companyId)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->with('creator:id,name')
            ->orderBy('created_at');
        if ($user && in_array($user->pos_role ?? '', ['pos_cashier', 'local_viewer'], true)) {
            $q->where('created_by', $user->id);
        }
        // Branch isolation, resolved SERVER-side: a requested branch must be
        // accessible to THIS user (admin = all, manager = pivot branches,
        // cashier = own branch only); with no explicit request the active
        // branch context applies (legacy NULL rows stay visible, same
        // convention as BranchContextService::applyToQuery everywhere else).
        $branchCtx = app(\App\Services\BranchContextService::class);
        if ($request->filled('branch_id')) {
            $requestedBranch = (int) $request->branch_id;
            abort_unless(!$user || $branchCtx->canAccess($requestedBranch), 403, __('pos.access_denied'));
            $q->where('branch_id', $requestedBranch);
        } else {
            $branchCtx->applyToQuery($q);
        }

        $filename = 'FBR-Sales-' . $from->toDateString() . '-to-' . $to->toDateString() . '.csv';
        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Invoice #', 'FBR Invoice #', 'Customer', 'Payment', 'Subtotal', 'Discount', 'Tax', 'Total', 'FBR Status', 'Staff']);
            $q->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $t) {
                    fputcsv($out, [
                        $t->created_at->format('Y-m-d H:i'),
                        $t->invoice_number,
                        $t->fbr_invoice_number,
                        $t->customer_name,
                        $t->payment_method,
                        $t->subtotal,
                        $t->discount_amount,
                        $t->tax_amount,
                        $t->total_amount,
                        $t->fbr_status,
                        $t->creator->name ?? '',
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 👥 TEAM MANAGEMENT — FBR twin of /pos/team (strict feature binding
    // pass, Aug 2026). Owner/pos_admin only; quota = plan user_limit via
    // PlanLimitService::canAddPosUser (pos_manager + pos_cashier count).
    // ═══════════════════════════════════════════════════════════════════

    /** Team management is owner/admin only (managers/cashiers never see it). */
    private function fbrTeamAdminOnly(): void
    {
        $u = Auth::guard('fbrpos')->user();
        if (!$u || !($u->role === 'company_admin' || ($u->pos_role ?? '') === 'pos_admin')) {
            abort(403, __('pos.access_denied'));
        }
    }

    public function fbrTeam()
    {
        $this->fbrTeamAdminOnly();
        $companyId = app('currentCompanyId');

        $team = \App\Models\User::where('company_id', $companyId)
            ->whereIn('pos_role', ['pos_admin', 'pos_manager', 'pos_cashier'])
            ->orderByRaw("CASE WHEN pos_role = 'pos_admin' THEN 0 WHEN pos_role = 'pos_manager' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get();

        // Owner rule (Jul 2026, same as PRA): admin can VIEW stored team
        // passwords. Decrypted server-side, team roles only, admin-gated page.
        $teamPasswords = [];
        foreach ($team as $member) {
            if (in_array($member->pos_role, ['pos_cashier', 'pos_manager'], true)
                && !empty($member->pos_team_password_enc)) {
                try {
                    $teamPasswords[$member->id] = \Illuminate\Support\Facades\Crypt::decryptString($member->pos_team_password_enc);
                } catch (\Throwable $e) {
                    // APP_KEY rotated / corrupt payload — treat as "not stored".
                }
            }
        }

        $branches = \App\Models\Branch::where('company_id', $companyId)->orderBy('name')->get();
        $quota = \App\Services\PlanLimitService::canAddPosUser($companyId);

        return view('fbr-pos.team', compact('team', 'teamPasswords', 'branches', 'quota'));
    }

    public function fbrStoreTeamMember(Request $request)
    {
        $this->fbrTeamAdminOnly();
        $companyId = app('currentCompanyId');

        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:150|unique:users,email',
            'password' => 'required|string|min:6|max:100',
            'pos_role' => 'required|in:pos_cashier,pos_manager',
            'default_branch_id' => 'nullable|integer',
            // Task 529 (twin of PRA storeCashier): optional short login name —
            // globally unique, no spaces/@ (must never look like an email).
            'username' => \App\Services\LoginIdentifierResolver::usernameRules(),
        ], [
            ...\App\Services\LoginIdentifierResolver::usernameMessages(),
        ]);

        $check = \App\Services\PlanLimitService::canAddPosUser($companyId);
        if (!($check['allowed'] ?? true)) {
            return back()->with('error', $check['reason'] ?? __('pos.plan_locked_feature'));
        }

        $user = new \App\Models\User();
        $user->name = $request->name;
        $user->email = $request->email;
        // Blank → NULL (unique index must keep allowing username-less accounts).
        // hasColumn = schema-drift guard (same pattern as pos_team_password_enc).
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'username')) {
            $user->username = $request->input('username') ?: null;
        }
        $user->password = \Illuminate\Support\Facades\Hash::make($request->password);
        $user->company_id = $companyId;
        $user->role = 'employee';
        $user->pos_role = $request->pos_role;
        $user->is_active = true;
        $user->default_branch_id = $this->fbrResolveBranchId($request, $companyId);
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'pos_team_password_enc')) {
            $user->pos_team_password_enc = \Illuminate\Support\Facades\Crypt::encryptString($request->password);
        }
        $user->save();

        return back()->with('success', __('pos.member_added'));
    }

    public function fbrUpdateTeamMember(Request $request, int $id)
    {
        $this->fbrTeamAdminOnly();
        $companyId = app('currentCompanyId');

        // Never lets the admin row be edited through this path.
        $member = \App\Models\User::where('company_id', $companyId)
            ->whereIn('pos_role', ['pos_cashier', 'pos_manager'])
            ->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:150|unique:users,email,' . $member->id,
            'password' => 'nullable|string|min:6|max:100',
            'pos_role' => 'required|in:pos_cashier,pos_manager',
            'default_branch_id' => 'nullable|integer',
            // Task 529: set/change username from the edit row (own row exempt).
            'username' => \App\Services\LoginIdentifierResolver::usernameRules($member->id),
        ], [
            ...\App\Services\LoginIdentifierResolver::usernameMessages(),
        ]);

        $member->name = $request->name;
        $member->email = $request->email;
        // Blank clears the username (back to email-only login) — NULL, never ''.
        // hasColumn = schema-drift guard (same pattern as pos_team_password_enc).
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'username')) {
            $member->username = $request->input('username') ?: null;
        }
        $member->pos_role = $request->pos_role;
        $member->default_branch_id = $this->fbrResolveBranchId($request, $companyId);
        if ($request->filled('password')) {
            $member->password = \Illuminate\Support\Facades\Hash::make($request->password);
            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'pos_team_password_enc')) {
                $member->pos_team_password_enc = \Illuminate\Support\Facades\Crypt::encryptString($request->password);
            }
        }
        $member->save();

        return back()->with('success', __('pos.member_updated'));
    }

    public function fbrToggleTeamMember(int $id)
    {
        $this->fbrTeamAdminOnly();
        $companyId = app('currentCompanyId');

        $member = \App\Models\User::where('company_id', $companyId)
            ->whereIn('pos_role', ['pos_cashier', 'pos_manager'])
            ->findOrFail($id);

        // Reactivation re-checks the quota (deactivate → create → reactivate
        // must not bypass user_limit — same rule as PRA toggleCashier).
        if (!$member->is_active) {
            $check = \App\Services\PlanLimitService::canAddPosUser($companyId);
            if (!($check['allowed'] ?? true)) {
                return back()->with('error', $check['reason'] ?? __('pos.plan_locked_feature'));
            }
        }

        $member->is_active = !$member->is_active;
        $member->save();

        return back()->with('success', $member->is_active ? __('pos.activate') : __('pos.deactivate'));
    }

    /** Company-owned branch id from the request, or null (main shop). */
    private function fbrResolveBranchId(Request $request, int $companyId): ?int
    {
        if (!$request->filled('default_branch_id')) {
            return null;
        }
        $branchId = (int) $request->default_branch_id;
        return \App\Models\Branch::where('company_id', $companyId)->where('id', $branchId)->exists()
            ? $branchId : null;
    }

    /**
     * Shared range parsing for the reports analytics surfaces: defaults to the
     * current month, swaps reversed inputs, caps the window at 366 days.
     *
     * @return array{0:\Carbon\Carbon,1:\Carbon\Carbon}
     */
    /**
     * Render an A4 report PDF via mPDF for 'ur' locale or DomPDF for en/rur.
     * Mirrors PosController::renderReportPdf — see that method for full notes.
     */
    private function renderReportPdf(
        string $view,
        array $data,
        string $filename,
        string $orientation = 'portrait'
    ): \Illuminate\Http\Response {
        $isUrdu = app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT;
        $data['pdfUrdu'] = $isUrdu;

        if ($isUrdu) {
            try {
                return \App\Support\MpdfRenderer::render(
                    $view, $data, 'a4-report', $filename, false, $orientation
                );
            } catch (\Throwable $e) {
                \Log::warning("mPDF report render failed [{$filename}]: " . $e->getMessage());
            }
        }

        \App\Support\PosLocale::applyPdfSafeLocale();
        $data['pdfUrdu'] = false;

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($view, $data)
            ->setPaper('a4', $orientation);

        return $pdf->download($filename);
    }

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
     * profit (ADMIN-ONLY, coverage-aware), previous-period comparison, daily +
     * hourly chart data, cashier performance, top customers, payment split,
     * FBR submission health.
     *
     * PROFIT-FREEZE (Task 416, owner decision): cost basis = the cost_price
     * SNAPSHOT frozen on each sold line at sale time — same basis as the
     * Stock-page Munafa report. NEVER the product's current cost: a kharid-rate
     * edit must not retro-rewrite a past range's profit. Lines without a
     * stored snapshot are cost-unknown and excluded (coverage_pct shows it).
     */
    private function buildFbrReportRangeAnalytics(int $companyId, \Carbon\Carbon $from, \Carbon\Carbon $to, $user): object
    {
        $isAdminView = $user && $user->role === 'company_admin';

        // Return / credit-note rows are EXCLUDED from the range deep-dive
        // entirely (Task 591 — same convention as the PRA range analytics):
        // item rankings, hourly/cashier/payment breakdowns must never be
        // polluted or netted by return lines. Schema-guarded for prod drift.
        $excludeReturns = function ($q) {
            try {
                if (\Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'transaction_type')) {
                    $q->where(function ($w) {
                        $w->whereNull('transaction_type')->orWhere('transaction_type', '!=', 'return');
                    });
                }
            } catch (\Throwable $e) {
                // pre-migration schema — keep the old all-rows behaviour
            }
        };

        $transactions = FbrPosTransaction::where('company_id', $companyId)
            ->whereBetween('created_at', [$from, $to])
            ->tap($excludeReturns)
            ->get(['id', 'created_at', 'created_by', 'customer_id', 'customer_name', 'customer_phone', 'subtotal', 'total_amount', 'tax_amount', 'discount_amount', 'payment_method', 'fbr_status']);

        $ids = $transactions->pluck('id')->all();
        $items = empty($ids) ? collect() : FbrPosTransactionItem::whereIn('transaction_id', $ids)
            ->get(['transaction_id', 'product_id', 'item_name', 'quantity', 'subtotal', 'tax_amount', 'item_discount', 'promotion_discount', 'cost_price']);

        // Cost resolution: per-line frozen snapshot ONLY (no live product cost).
        $items->each(function ($it) use ($isAdminView) {
            $cost = null;
            if ($isAdminView && $it->cost_price !== null && (float) $it->cost_price > 0) {
                $cost = (float) $it->cost_price * (float) $it->quantity;
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

        // Profit summary (ADMIN-ONLY): only lines carrying a frozen sale-time
        // cost snapshot count — coverage_pct tells the admin how complete it is.
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
            ->tap($excludeReturns)
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
        // Task 579: ADMIN-ONLY (FBR twin of the POS PosAdminOnly route group).
        // Business Profile now edits the company CNIC — a LOGIN identifier —
        // so a cashier must never reach this page's GET or POST.
        $gateUser = Auth::guard('fbrpos')->user();
        if (!$gateUser || !$gateUser->isPosAdmin()) {
            abort(403);
        }

        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        if ($request->isMethod('post')) {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'address' => 'nullable|string|max:500',
                'phone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:255',
                'ntn' => 'nullable|string|max:20',
                // Task 579: owner-facing CNIC — same rules the login router
                // understands (13 digits, dash-tolerant, globally unique).
                'cnic' => \App\Services\LoginIdentifierResolver::cnicRules($company->id),
                'print_paper_size' => 'nullable|in:thermal,thermal58,a4',
                'kot_align_center' => 'nullable|in:0,1',
                'kot_left_margin_mm' => 'nullable|integer|min:0|max:30',
                'receipt_footer_note' => 'nullable|string|max:255',
                'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
                'remove_logo' => 'nullable|boolean',
            ], \App\Services\LoginIdentifierResolver::cnicMessages());

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
            ]);

            // Task 579: owner-facing CNIC — stored as plain digits (login
            // compares the digit form). hasColumn = PROD schema-drift guard.
            if ($request->has('cnic') && \Illuminate\Support\Facades\Schema::hasColumn('companies', 'cnic')) {
                $company->cnic = \App\Services\LoginIdentifierResolver::normalizeCnic($validated['cnic'] ?? null);
            }

            $company->save();

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
                // Task 529: shared rules — no spaces/@, no identifier-shaped
                // digits (login routers would divert those to phone/NTN/CNIC).
                'username' => \App\Services\LoginIdentifierResolver::usernameRules($user->id),
                'current_password' => 'nullable|required_with:new_password',
                'new_password' => 'nullable|min:8|confirmed',
            ], \App\Services\LoginIdentifierResolver::usernameMessages());

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

        // Task 260: locale 'ur' → mPDF (Arabic OTL shaping). en/rur → DomPDF unchanged.
        if (app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT) {
            try {
                return \App\Support\MpdfRenderer::render(
                    'fbr-pos.invoice-pdf',
                    compact('transaction', 'company'),
                    'a4',
                    "FBR-POS-Invoice-{$transaction->invoice_number}.pdf",
                    false
                );
            } catch (\Throwable $e) {
                \Log::warning('mPDF render failed for FBR downloadPdf, falling back to DomPDF Roman Urdu: ' . $e->getMessage());
            }
        }

        \App\Support\PosLocale::applyPdfSafeLocale(); // DomPDF can't shape Urdu script — PDF falls back to Roman Urdu

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

        // Task 260: locale 'ur' → mPDF (Arabic OTL shaping). en/rur → DomPDF unchanged.
        if (app()->getLocale() === \App\Support\PosLocale::URDU_SCRIPT) {
            try {
                return \App\Support\MpdfRenderer::render(
                    'fbr-pos.invoice-pdf',
                    compact('transaction', 'company'),
                    'a4',
                    "FBR-POS-Invoice-{$transaction->invoice_number}.pdf",
                    true
                );
            } catch (\Throwable $e) {
                \Log::warning('mPDF render failed for FBR previewPdf, falling back to DomPDF Roman Urdu: ' . $e->getMessage());
            }
        }

        \App\Support\PosLocale::applyPdfSafeLocale(); // DomPDF can't shape Urdu script — PDF falls back to Roman Urdu

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('fbr-pos.invoice-pdf', compact('transaction', 'company'));
        $pdf->setPaper('a4', 'portrait');

        return $pdf->stream("FBR-POS-Invoice-{$transaction->invoice_number}.pdf");
    }

    public function dayCloseReport(Request $request)
    {
        // Owner rule (5 Aug 2026, mirrored from PRA): Day Close is admin/manager
        // work by DEFAULT. A cashier reaches it only when the company switch or
        // a Team Custom Access tick re-opens it — dayCloseAllowed = single
        // verdict shared with the nav and dashboard links.
        $dayCloseUser = Auth::guard('fbrpos')->user();
        if ($dayCloseUser && !\App\Services\PosAccessService::dayCloseAllowed($dayCloseUser)) {
            return redirect()->route('fbrpos.dashboard')->with('error', __('pos.custom_access_denied'));
        }
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $date = $request->get('date', today()->format('Y-m-d'));

        $existingReport = FbrDayCloseReport::where('company_id', $companyId)
            ->where('report_date', $date)
            ->first();

        // Task 492: a trading day = its business_date bucket, so a 1 AM sale
        // closes with yesterday's day, not today's.
        $transactions = FbrPosTransaction::where('company_id', $companyId)
            ->tap(fn ($q) => $this->whereBizDate($q, $date))
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        // Return / credit-note netting (Task 607): the preview nets returns
        // exactly like the stored Z-report (performDayClose) so the page, PDF
        // and thermal never disagree. Counts + serial range stay SALES-only.
        [$dcSaleRows, $dcReturnRows] = $this->splitFbrSaleReturnRows($transactions);

        $stats = (object) [
            'total_invoices' => $dcSaleRows->count(),
            'fbr_invoices' => $dcSaleRows->where('fbr_status', 'submitted')->count(),
            'local_invoices' => $dcSaleRows->where('fbr_status', 'local')->count(),
            'failed_invoices' => $dcSaleRows->whereIn('fbr_status', ['failed', 'pending'])->count(),
            'gross_sales' => $dcSaleRows->sum('subtotal') - $dcReturnRows->sum('subtotal'),
            'total_discount' => $dcSaleRows->sum('discount_amount') - $dcReturnRows->sum('discount_amount'),
            'net_sales' => ($dcSaleRows->sum('subtotal') - $dcReturnRows->sum('subtotal'))
                - ($dcSaleRows->sum('discount_amount') - $dcReturnRows->sum('discount_amount')),
            'total_tax' => $dcSaleRows->sum('tax_amount') - $dcReturnRows->sum('tax_amount'),
            'total_fbr_fee' => $dcSaleRows->sum('fbr_service_charge') - $dcReturnRows->sum('fbr_service_charge'),
            'total_amount' => $dcSaleRows->sum('total_amount') - $dcReturnRows->sum('total_amount'),
            // Refunds net their own bucket (cash refund reduces drawer cash).
            'cash_amount' => $this->fbrBucketNet($dcSaleRows, $dcReturnRows, 'cash'),
            'card_amount' => $this->fbrBucketNet($dcSaleRows, $dcReturnRows, 'card'),
            'udhaar_amount' => $this->fbrBucketNet($dcSaleRows, $dcReturnRows, 'udhaar'),
            'other_amount' => $this->fbrBucketNet($dcSaleRows, $dcReturnRows, 'other'),
            'first_invoice' => $dcSaleRows->first(),
            'last_invoice' => $dcSaleRows->last(),
            // Returns detail line for the page/PDF/thermal.
            'returns_count' => $dcReturnRows->count(),
            'returns_amount' => round((float) $dcReturnRows->sum('total_amount'), 2),
        ];

        // Cashier figures are SIGNED (Task 607): refunds net revenue/tax;
        // counts stay sales-only. Shared with the A4 PDF + thermal Z-report.
        $cashierBreakdown = $this->fbrCashierBreakdown($transactions);

        $previousReports = FbrDayCloseReport::where('company_id', $companyId)
            ->orderBy('report_date', 'desc')
            ->limit(10)
            ->get();

        $analytics = $this->buildFbrDayCloseAnalytics($companyId, $date, $transactions);

        // 'Khud Final' policy preview (Task mirror of PRA UX, Aug 2026): when the
        // company opted into auto-finalize, count exactly the bills the day-close
        // sweep (finalizeFbrProvisionalsAtDayClose) will flip — same filters incl.
        // the MONTH GATE (previous-month locals are carried, never finalized).
        $pendingAutoFinal = 0;
        if (!$existingReport && ($company->pos_dayclose_provisional_action ?? null) === 'finalize') {
            $pendingAutoFinal = FbrPosTransaction::where('company_id', $companyId)
                ->tap(fn ($q) => $this->whereBizDate($q, $date, '<='))
                ->where('created_at', '>=', now()->startOfMonth())
                ->where('invoice_mode', 'local')
                ->where('fbr_status', 'local')
                ->count();
        }

        // Stranded-day banner (Task 479 — FBR mirror of PRA Task 455): prior
        // days never closed, EXCLUDING the day currently being viewed (no
        // self-referential "close this day" nag on its own page).
        $unclosedPriorDays = $this->unclosedPriorBusinessDays($companyId, $date);

        // Delivery Riders (Task 541 — FBR mirror of the PRA Jul 2026 section):
        // live rider cash figures for the recon preview (unsettled rider cash
        // is OUT of the drawer; earlier-day settlements are IN).
        $riderFigures = $this->buildFbrRiderDayFigures($companyId, $date);

        return view('fbr-pos.day-close', compact('company', 'date', 'stats', 'existingReport', 'cashierBreakdown', 'previousReports', 'transactions', 'analytics', 'pendingAutoFinal', 'unclosedPriorDays', 'riderFigures'));
    }

    /**
     * Does fbr_pos_transactions have the business_date column yet? Guarded so
     * a tar-deploy window before `migrate --force` falls back to created_at
     * dates instead of 500ing (same convention as the PRA side).
     */
    private function hasBizDate(): bool
    {
        // No static cache: it would leak across the sqlite test suites (each
        // rebuilds a different schema in-process). hasColumn is cheap.
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'business_date');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Current open TRADING day for an FBR company (PosBusinessDay cutoff rule). */
    private function fbrBizToday(int $companyId): string
    {
        return \App\Services\PosBusinessDay::currentFbr($companyId);
    }

    /**
     * Scope a query to one/ranged business date(s) — business_date when the
     * column exists, DATE(created_at) fallback pre-migration. Shop-facing
     * grouping ONLY: FBR / tax reporting must keep filtering on created_at.
     */
    private function whereBizDate($q, string $date, string $op = '=')
    {
        return $this->hasBizDate()
            ? $q->where('business_date', $op, $date)
            : $q->whereDate('created_at', $op, $date);
    }

    /** SQL expression for the business-date bucket (grouping / select). */
    private function bizDateExpr(): string
    {
        return $this->hasBizDate() ? 'business_date' : 'DATE(created_at)';
    }

    /**
     * Return / credit-note netting expressions (Task 591 — FBR mirror of PRA
     * Tasks 570/578): [signExpr, saleRowExpr]. Money sums multiply by signExpr
     * (returns subtract, amounts are stored POSITIVE on return rows); bill
     * counts SUM saleRowExpr (a credit note is not a bill). Schema-guarded for
     * prod drift — pre-migration boxes fall back to the old unsigned sums.
     */
    private function fbrReturnNettingExprs(): array
    {
        $ready = false;
        try {
            $ready = \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'transaction_type');
        } catch (\Throwable $e) {
            $ready = false;
        }

        return $ready
            ? ["CASE WHEN transaction_type = 'return' THEN -1 ELSE 1 END",
               "CASE WHEN transaction_type = 'return' THEN 0 ELSE 1 END"]
            : ['1', '1'];
    }

    /**
     * Split a loaded day's transactions into [saleRows, returnRows] for the
     * day-close netting (Task 607 — FBR mirror of the PRA Task 570 split).
     * Schema-guarded: pre-migration boxes treat every row as a sale.
     */
    private function splitFbrSaleReturnRows($transactions): array
    {
        $ready = false;
        try {
            $ready = \Illuminate\Support\Facades\Schema::hasColumn('fbr_pos_transactions', 'transaction_type');
        } catch (\Throwable $e) {
            $ready = false;
        }
        if (!$ready) {
            return [$transactions, collect()];
        }

        return [
            $transactions->reject(fn ($t) => ($t->transaction_type ?? 'sale') === 'return')->values(),
            $transactions->filter(fn ($t) => ($t->transaction_type ?? 'sale') === 'return')->values(),
        ];
    }

    /**
     * Per-cashier day-close figures — SIGNED revenue/tax (refunds subtract),
     * SALES-only counts (Task 607). Single source shared by the day-close
     * page, the A4 PDF and the 80mm thermal Z-report so they never disagree.
     */
    /**
     * Shared payment-bucket classifier for day-close figures (Task 607).
     * 'cash' | 'card' (all stored card aliases) | 'udhaar' | 'other'.
     * Sales pay with 'credit' for udhaar; the return flow refunds onto the
     * ledger with 'khata' — BOTH must land in the udhaar bucket or a khata
     * refund silently inflates "Other" and misses the udhaar netting.
     */
    private function fbrPayBucket(?string $method): string
    {
        return match ($method) {
            'cash' => 'cash',
            'card', 'debit_card', 'credit_card' => 'card',
            'credit', 'khata' => 'udhaar',
            default => 'other',
        };
    }

    /** Signed (sale − return) total_amount for one payment bucket. */
    private function fbrBucketNet($saleRows, $returnRows, string $bucket): float
    {
        $sum = fn ($rows) => (float) $rows
            ->filter(fn ($t) => $this->fbrPayBucket($t->payment_method) === $bucket)
            ->sum('total_amount');
        return $sum($saleRows) - $sum($returnRows);
    }

    /**
     * Render-time udhaar figures for the PDF / thermal Z-report:
     * [$displayUdhaar, $displayOther].
     *
     * New rows store a SIGNED udhaar_amount (credit refunds net it — may be
     * negative) which is trusted verbatim whenever it is non-zero. Old
     * pre-column / zero rows fall back to a SIGNED derivation from the day's
     * transactions (credit sales minus credit returns, Task 607); only the
     * legacy POSITIVE portion was ever baked into other_amount, so only that
     * portion is subtracted back out for display.
     */
    private function fbrUdhaarDisplay($report, $transactions): array
    {
        [$uSaleRows, $uReturnRows] = $this->splitFbrSaleReturnRows($transactions);
        $derivedUdhaar = $this->fbrBucketNet($uSaleRows, $uReturnRows, 'udhaar');

        $hasUdhaarColumn = \Illuminate\Support\Facades\Schema::hasColumn('fbr_day_close_reports', 'udhaar_amount');
        $storedUdhaar = $hasUdhaarColumn ? (float) $report->udhaar_amount : 0.0;

        if ($hasUdhaarColumn && $storedUdhaar != 0.0) {
            return [$storedUdhaar, (float) $report->other_amount];
        }

        return [
            $derivedUdhaar,
            max(0.0, (float) $report->other_amount - max(0.0, $derivedUdhaar)),
        ];
    }

    private function fbrCashierBreakdown($transactions)
    {
        return $transactions->groupBy(fn ($t) => $t->creator ? $t->creator->name : 'Unknown')->map(function ($group) {
            $sign = fn ($t) => ($t->transaction_type ?? 'sale') === 'return' ? -1 : 1;
            return (object) [
                'count' => $group->filter(fn ($t) => ($t->transaction_type ?? 'sale') !== 'return')->count(),
                'revenue' => $group->sum(fn ($t) => $sign($t) * (float) $t->total_amount),
                'tax' => $group->sum(fn ($t) => $sign($t) * (float) $t->tax_amount),
            ];
        });
    }

    /**
     * Stranded-day detection (Task 479 — FBR mirror of PosController::
     * unclosedPriorBusinessDays): prior TRADING days that have bills but NO
     * FbrDayCloseReport row. Task 492: keyed on real business_date (created_at
     * date on pre-migration schemas), with "prior" = before the current open
     * trading day — this replaces the Task 489 calendar-day + pre-cutoff grace
     * heuristic: a 1 AM bill now CARRIES yesterday's business_date, so an
     * open late-night yesterday is simply "today" and never flagged.
     * Returns an ascending collection of Y-m-d strings (max 30 days back).
     */
    private function unclosedPriorBusinessDays(int $companyId, ?string $excludeDate = null, bool $oldestFirst = false)
    {
        $bizToday = $this->fbrBizToday($companyId);
        // Closed dates FIRST, excluded inside the query (Task 519 — same fix
        // as PRA Task 516): the old shape limited to the newest 30 dates
        // BEFORE dropping closed ones, so once a 30-date page was all closed,
        // remaining still-open days became invisible — the bulk close could
        // never finish a 31+ day backlog. Normalized in PHP (no whereIn on
        // report_date — drivers that store DATE with a time part would miss
        // every match); a company has few report rows.
        $closedDates = FbrDayCloseReport::where('company_id', $companyId)
            ->pluck('report_date')
            ->map(fn ($d) => \Carbon\Carbon::parse($d)->toDateString());
        $priorDates = FbrPosTransaction::where('company_id', $companyId)
            ->tap(fn ($q) => $this->whereBizDate($q, $bizToday, '<'))
            ->selectRaw($this->bizDateExpr() . ' as d')
            ->when($closedDates->isNotEmpty(),
                fn ($q) => $q->whereNotIn(\DB::raw($this->bizDateExpr()), $closedDates->all()))
            ->groupBy('d')
            // oldestFirst (Task 519 bulk close): page from the OLDEST open day so
            // chronological closing never skips days beyond the 30-row window.
            ->orderBy('d', $oldestFirst ? 'asc' : 'desc')
            ->limit(30)
            ->pluck('d')
            ->map(fn ($d) => (string) $d);

        return $priorDates
            ->reject(fn ($d) => $closedDates->contains($d) || $d === $excludeDate)
            ->sort()
            ->values();
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
        // Return / credit-note netting (Task 607): analytics follow the SAME
        // signed / sales-only convention as the report totals — return item
        // lines net product figures, hourly revenue is signed, counts are
        // sales-only, averages divide signed revenue by sale count.
        [$anSaleRows, $anReturnRows] = $this->splitFbrSaleReturnRows($transactions);
        $returnIds = $anReturnRows->pluck('id')->flip();
        $rowSign = fn ($t) => $returnIds->has($t->id) ? -1 : 1;
        $itemSign = fn ($it) => $returnIds->has($it->transaction_id) ? -1 : 1;

        $ids = $transactions->pluck('id')->all();

        $items = empty($ids) ? collect() : FbrPosTransactionItem::whereIn('transaction_id', $ids)
            ->get(['transaction_id', 'product_id', 'item_name', 'quantity', 'subtotal', 'tax_amount', 'item_discount', 'promotion_discount']);

        $itemRevenueTotal = (float) $items->sum(fn ($it) => $itemSign($it) * (float) $it->subtotal);
        $topProducts = $items->groupBy('item_name')->map(function ($g) use ($itemRevenueTotal, $itemSign) {
            $revenue = (float) $g->sum(fn ($it) => $itemSign($it) * (float) $it->subtotal);
            return (object) [
                'qty' => (float) $g->sum(fn ($it) => $itemSign($it) * (float) $it->quantity),
                'revenue' => $revenue,
                'tax' => (float) $g->sum(fn ($it) => $itemSign($it) * (float) $it->tax_amount),
                'share' => $itemRevenueTotal > 0 ? round($revenue / $itemRevenueTotal * 100, 1) : 0,
            ];
        })->sortByDesc('revenue')->take(10);

        // Hourly sales — full 24-slot map so the chart x-axis stays stable.
        // Revenue SIGNED (a refund dents its hour), counts sales-only.
        $hourly = [];
        for ($h = 0; $h < 24; $h++) {
            $hourly[$h] = (object) ['count' => 0, 'revenue' => 0.0];
        }
        foreach ($transactions as $t) {
            if (!$t->created_at) {
                continue;
            }
            $h = (int) $t->created_at->format('G');
            if ($rowSign($t) > 0) {
                $hourly[$h]->count++;
            }
            $hourly[$h]->revenue += $rowSign($t) * (float) $t->total_amount;
        }

        // FBR submission health — every pipeline state at a glance.
        // SALES-only: a submitted credit note is not a submitted bill (it
        // would contradict the sales-only invoice counts on the same report).
        $fbrHealth = (object) [
            'submitted' => $anSaleRows->where('fbr_status', 'submitted')->count(),
            'pending' => $anSaleRows->where('fbr_status', 'pending')->count(),
            'failed' => $anSaleRows->where('fbr_status', 'failed')->count(),
            'local' => $anSaleRows->where('fbr_status', 'local')->count(),
        ];

        // Discounts: sale-side counts, SIGNED totals (returned discount share
        // leaves the day's discount figure — mirrors the report totals).
        $discountBills = $anSaleRows->filter(fn ($t) => (float) $t->discount_amount > 0);
        $itemDiscountTotal = (float) $items->sum(fn ($it) => $itemSign($it) * ((float) $it->item_discount + (float) $it->promotion_discount));
        $billDiscountTotal = (float) $discountBills->sum('discount_amount')
            - (float) $anReturnRows->sum('discount_amount');
        $discounts = (object) [
            'bill_count' => $discountBills->count(),
            'bill_total' => $billDiscountTotal,
            'item_total' => $itemDiscountTotal,
            'total' => $billDiscountTotal + $itemDiscountTotal,
        ];

        $billCount = $anSaleRows->count();
        $netRevenue = (float) $transactions->sum(fn ($t) => $rowSign($t) * (float) $t->total_amount);
        $avgBill = $billCount > 0 ? $netRevenue / $billCount : 0.0;
        $uniqueCustomers = $anSaleRows
            ->filter(fn ($t) => $t->customer_id || !empty($t->customer_phone) || !empty($t->customer_name))
            ->unique(fn ($t) => $t->customer_id ?: ($t->customer_phone ?: mb_strtolower(trim((string) $t->customer_name))))
            ->count();

        // Yesterday + same-day-last-week comparison — SIGNED (Task 607):
        // returns net the revenue/tax, credit notes never count as invoices.
        [$cmpSignExpr, $cmpSaleRowExpr] = $this->fbrReturnNettingExprs();
        $compareFor = function (string $cmpDate) use ($companyId, $cmpSignExpr, $cmpSaleRowExpr) {
            $row = FbrPosTransaction::where('company_id', $companyId)
                ->tap(fn ($q) => $this->whereBizDate($q, $cmpDate))
                ->selectRaw("COALESCE(SUM({$cmpSaleRowExpr}),0) as cnt, COALESCE(SUM(({$cmpSignExpr}) * total_amount),0) as revenue, COALESCE(SUM(({$cmpSignExpr}) * tax_amount),0) as tax")
                ->first();
            return (object) [
                'date' => $cmpDate,
                'invoices' => (int) ($row->cnt ?? 0),
                'revenue' => (float) ($row->revenue ?? 0),
                'tax' => (float) ($row->tax ?? 0),
            ];
        };
        // Today's side of the comparison must use the SAME signed convention
        // as $compareFor, or the pct would compare gross-today vs netted-prev.
        $todayRevenue = $netRevenue;
        $cmpBillCount = $billCount;
        $pct = function (float $prev, float $cur): ?float {
            return $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : null;
        };
        $yesterday = $compareFor(\Carbon\Carbon::parse($date)->subDay()->toDateString());
        $lastWeek = $compareFor(\Carbon\Carbon::parse($date)->subDays(7)->toDateString());
        $comparison = (object) [
            'yesterday' => $yesterday,
            'last_week' => $lastWeek,
            'vs_yesterday_revenue_pct' => $pct($yesterday->revenue, $todayRevenue),
            'vs_yesterday_invoices_pct' => $pct((float) $yesterday->invoices, (float) $cmpBillCount),
            'vs_last_week_revenue_pct' => $pct($lastWeek->revenue, $todayRevenue),
            'vs_last_week_invoices_pct' => $pct((float) $lastWeek->invoices, (float) $cmpBillCount),
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

    /**
     * Delivery Riders (Task 541 — FBR mirror of PosController::
     * buildRiderDayFigures): per-day rider figures for the Z-report.
     *
     * cash_out = TODAY's FBR-set cash delivery bills' REMAINING (total −
     *            rider_partial_paid) still unsettled at close — that cash sits
     *            with the rider, NOT in the drawer.
     * cash_in  = settlements received TODAY for EARLIER days' FBR-set cash
     *            bills — HYBRID: allocation entries (panel='fbr', exact rupees)
     *            for Task-525 rows, legacy bill totals for pre-feature
     *            settlements (allocation NULL), never double-counting.
     * Both stay FBR-set (invoice_mode 'fbr'/NULL) for consistency with the
     * stored cash_amount; local provisionals are excluded from both sides.
     * Per-rider rows cover ALL rider bills of the day (operational truth).
     * Schema-guarded — returns inactive on prod mid-deploy.
     */
    private function buildFbrRiderDayFigures(int $companyId, string $date): array
    {
        $empty = ['active' => false, 'riders' => [], 'cash_out' => 0.0, 'cash_in' => 0.0];
        try {
            if (!\Schema::hasTable('pos_riders') || !\Schema::hasColumn('fbr_pos_transactions', 'rider_id')) {
                return $empty;
            }

            $dayBills = FbrPosTransaction::where('company_id', $companyId)
                ->tap(fn ($q) => $this->whereBizDate($q, $date))
                ->whereNotNull('rider_id')
                ->get();

            // rider_settled_at stays on the REAL calendar date (settlement
            // timestamps carry no business date) — same v1 limitation as PRA.
            // Partial settlements (Task 525): allocation-carrying settlement
            // rows contribute cash-in from their entries (exact rupees received
            // today against older bills); the legacy bill-based query stays for
            // pre-feature settlements (no allocation) so the transition day
            // never double-counts.
            $hasAllocation = \Schema::hasColumn('pos_rider_settlements', 'allocation');
            $legacyCashInQ = FbrPosTransaction::where('company_id', $companyId)
                ->whereNotNull('rider_id')
                ->where('payment_method', 'cash')
                ->whereNotNull('rider_settlement_id')
                ->whereDate('rider_settled_at', $date)
                ->tap(fn ($q) => $this->whereBizDate($q, $date, '<'))
                ->where(function ($q) {
                    $q->where('invoice_mode', 'fbr')->orWhereNull('invoice_mode');
                });
            if ($hasAllocation) {
                $legacyCashInQ->whereNotIn('rider_settlement_id', function ($q) use ($companyId) {
                    $q->select('id')->from('pos_rider_settlements')
                        ->where('company_id', $companyId)
                        ->whereNotNull('allocation');
                });
            }
            $cashIn = (float) $legacyCashInQ->sum('total_amount');
            if ($hasAllocation) {
                $allocCashIn = 0.0;
                \App\Models\PosRiderSettlement::where('company_id', $companyId)
                    ->where('panel', 'fbr')
                    ->whereNotNull('allocation')
                    ->whereDate('created_at', $date)
                    ->get()
                    ->each(function ($s) use (&$allocCashIn, $date) {
                        foreach ((array) $s->allocation as $entry) {
                            if (!empty($entry['business_date']) && $entry['business_date'] < $date) {
                                $allocCashIn += (float) ($entry['amount'] ?? 0);
                            }
                        }
                    });
                $cashIn += $allocCashIn;
            }

            if ($dayBills->isEmpty() && $cashIn == 0.0) {
                return $empty;
            }

            $isOpenCash = fn ($t) => $t->payment_method === 'cash'
                && !$t->rider_settlement_id
                && $t->delivery_status !== 'returned';

            // Khata remaining per bill — partial cash already received today is
            // IN the drawer, only the unpaid remainder is out with the rider.
            $hasPartialCol = \Schema::hasColumn('fbr_pos_transactions', 'rider_partial_paid');
            $remainingOf = fn ($t) => (float) $t->total_amount - ($hasPartialCol ? (float) ($t->rider_partial_paid ?? 0) : 0);

            $cashOut = (float) $dayBills
                ->filter(fn ($t) => ($t->invoice_mode === 'fbr' || $t->invoice_mode === null) && $isOpenCash($t))
                ->sum($remainingOf);

            $riderNames = \App\Models\PosRider::where('company_id', $companyId)
                ->whereIn('id', $dayBills->pluck('rider_id')->unique())
                ->pluck('name', 'id');
            $riders = [];
            foreach ($dayBills->groupBy('rider_id') as $rid => $rows) {
                $riders[] = [
                    'name' => $riderNames[$rid] ?? ('Rider #' . $rid),
                    'deliveries' => $rows->count(),
                    'delivered' => $rows->where('delivery_status', 'delivered')->count(),
                    'returned' => $rows->where('delivery_status', 'returned')->count(),
                    'cash_total' => round((float) $rows->filter(fn ($t) => $t->payment_method === 'cash' && $t->delivery_status !== 'returned')->sum('total_amount'), 2),
                    'cash_pending' => round((float) $rows->filter($isOpenCash)->sum($remainingOf), 2),
                ];
            }

            return ['active' => true, 'riders' => $riders, 'cash_out' => round($cashOut, 2), 'cash_in' => round($cashIn, 2)];
        } catch (\Throwable $e) {
            // Rider figures are reporting sugar — never let them break day-close.
            return $empty;
        }
    }

    /**
     * Task 519 (FBR mirror of PRA Task 516): bulk-close ALL stranded prior
     * trading days in one click. Iterates chronologically and calls the SAME
     * performDayClose routine per day — no parallel close path. Empty stranded
     * days get a zero-figure Z-report ($allowEmpty) so they leave the banner.
     */
    public function closeAllPriorDays(Request $request)
    {
        // Same authority gate as the single close (owner rule 5 Aug 2026).
        $user = Auth::guard('fbrpos')->user();
        if ($user && !\App\Services\PosAccessService::dayCloseAllowed($user)) {
            return redirect()->route('fbrpos.dashboard')->with('error', __('pos.custom_access_denied'));
        }
        $companyId = app('currentCompanyId');

        if ($this->unclosedPriorBusinessDays($companyId)->isEmpty()) {
            return back()->with('success', __('pos.dc_bulk_none_pending'));
        }

        $closed = 0;
        $zeroDays = 0;
        // The detector returns at most 30 dates per query — RE-QUERY until the
        // backlog is exhausted so 31+ open days still finish in ONE click.
        // oldestFirst: pages come CHRONOLOGICALLY (oldest day first) so reports
        // number in trading order. Guard caps the loop; each pass must make
        // progress or we bail (never spin).
        for ($pass = 0; $pass < 30; $pass++) {
            $pending = $this->unclosedPriorBusinessDays($companyId, null, true); // oldest 30, ascending
            if ($pending->isEmpty()) {
                break;
            }
            $closedThisPass = 0;
            foreach ($pending as $day) {
                $report = $this->performDayClose($companyId, $day, $user?->id, null, null, true);
                if ($report) {
                    $closed++;
                    $closedThisPass++;
                    if ((int) ($report->total_invoices ?? 0) === 0) {
                        $zeroDays++;
                    }
                }
            }
            if ($closedThisPass === 0) {
                break;
            }
        }

        $msg = __('pos.dc_bulk_done', ['closed' => $closed, 'zero' => $zeroDays]);
        // Sweep summary (same as the single close): tell the cashier what the
        // 'Khud Final' policy did across the bulk run, if anything.
        $sweep = $this->lastFinalizeSweep;
        if (($sweep['finalized'] ?? 0) > 0) {
            $msg .= __('pos.dayclose_bills_finalized', ['count' => $sweep['finalized']]);
        }

        // Honest "all": if anything is somehow still pending after the capped
        // passes (900 days) or a stalled pass, say so instead of implying done.
        $remaining = $this->unclosedPriorBusinessDays($companyId, null, true)->count();
        if ($remaining > 0) {
            return redirect()->route('fbrpos.day-close')
                ->with('error', $msg . ' ' . __('pos.dc_bulk_partial', ['remaining' => $remaining]));
        }

        return redirect()->route('fbrpos.day-close')->with('success', $msg);
    }

    public function closeDayReport(Request $request)
    {
        // Owner rule (5 Aug 2026, mirrored from PRA): cashier day-close only
        // via company switch / Custom Access tick.
        $dayCloseUser = Auth::guard('fbrpos')->user();
        if ($dayCloseUser && !\App\Services\PosAccessService::dayCloseAllowed($dayCloseUser)) {
            return redirect()->route('fbrpos.dashboard')->with('error', __('pos.custom_access_denied'));
        }
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

        // Route through shared writer (transaction + atomic numbering + race-safe).
        // Task 519 (mirror of PRA Task 516): a PRIOR trading day may close with
        // zero figures (stranded empty day) — today's empty close still refuses.
        $allowEmpty = $date < $this->fbrBizToday($companyId);
        $report = $this->performDayClose($companyId, $date, $user->id, $request->input('notes'), $cashRecon, $allowEmpty);

        if (!$report) {
            return back()->with('error', __('pos.dayclose_no_transactions'));
        }

        $msg = __('pos.dayclose_report_generated_for', ['number' => $report->report_number, 'date' => \Carbon\Carbon::parse($date)->format('d M Y')]);
        // Sweep summary (task: cashier ko batayein) — only when the 'finalize' policy
        // actually ran and did something. Breaks down FBR outcomes so the cashier knows
        // how many were submitted, how many are queued for the desktop agent, and how
        // many landed in the Fail Queue (retryable — never lost).
        $sweep = $this->lastFinalizeSweep;
        if (($sweep['finalized'] ?? 0) > 0) {
            $msg .= __('pos.dayclose_bills_finalized', ['count' => $sweep['finalized']]);
            if (($sweep['submitted'] ?? 0) > 0) {
                $msg .= __('pos.dayclose_bills_submitted', ['count' => $sweep['submitted']]);
            }
            if (($sweep['queued'] ?? 0) > 0) {
                $msg .= __('pos.dayclose_bills_queued', ['count' => $sweep['queued']]);
            }
            if (($sweep['failed'] ?? 0) > 0) {
                $msg .= __('pos.dayclose_bills_failed', ['count' => $sweep['failed']]);
            }
        }
        return back()->with('success', $msg);
    }

    public function dayCloseReportPdf($id)
    {
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);
        $report = FbrDayCloseReport::where('company_id', $companyId)->findOrFail($id);

        $transactions = FbrPosTransaction::where('company_id', $companyId)
            ->tap(fn ($q) => $this->whereBizDate($q, $report->report_date->toDateString()))
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        // Cashier figures SIGNED, counts sales-only (Task 607) — same helper
        // as the day-close page so page/PDF/thermal never disagree.
        $cashierBreakdown = $this->fbrCashierBreakdown($transactions);

        $analytics = $this->buildFbrDayCloseAnalytics($companyId, $report->report_date->toDateString(), $transactions);

        // Historical reports: render-time udhaar derivation — shared signed
        // helper (Task 607) so PDF/thermal honor negative netted udhaar.
        [$displayUdhaar, $displayOther] = $this->fbrUdhaarDisplay($report, $transactions);

        // Staff Hazri section (Task #561 — FBR mirror of the PRA day-close PDF).
        // Same plan gate as the hazri report page; builder returns [] on any error.
        $hazri = $this->fbrPlanAllows('hazri_enabled')
            ? $this->buildFbrHazriRows($companyId, $report->report_date->toDateString())
            : [];

        // Biometric punches section (Task #563 — FBR mirror of the PRA day-close PDF).
        // Same plan gate as session hazri; builder returns [] on any error.
        $bioPunches = $this->fbrPlanAllows('hazri_enabled')
            ? $this->buildFbrBiometricRows($companyId, $report->report_date->toDateString())
            : [];

        return $this->renderReportPdf(
            'fbr-pos.day-close-pdf',
            compact('company', 'report', 'transactions', 'cashierBreakdown', 'analytics', 'displayUdhaar', 'displayOther', 'hazri', 'bioPunches'),
            "Day-Close-{$report->report_number}-{$report->report_date->format('Y-m-d')}.pdf"
        );
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
            ->tap(fn ($q) => $this->whereBizDate($q, $report->report_date->toDateString()))
            ->with('creator')
            ->orderBy('created_at')
            ->get();

        // Cashier figures SIGNED, counts sales-only (Task 607) — same helper
        // as the day-close page so page/PDF/thermal never disagree.
        $cashierBreakdown = $this->fbrCashierBreakdown($transactions);

        $analytics = $this->buildFbrDayCloseAnalytics($companyId, $report->report_date->toDateString(), $transactions);

        // Historical reports: render-time derivation (same signed helper as
        // dayCloseReportPdf — Task 607).
        [$displayUdhaar, $displayOther] = $this->fbrUdhaarDisplay($report, $transactions);

        // Staff Hazri section (Task #561 — FBR mirror of the PRA thermal Z-report).
        $hazri = $this->fbrPlanAllows('hazri_enabled')
            ? $this->buildFbrHazriRows($companyId, $report->report_date->toDateString())
            : [];

        // Biometric punches section (Task #563 — FBR mirror of the PRA thermal Z-report).
        $bioPunches = $this->fbrPlanAllows('hazri_enabled')
            ? $this->buildFbrBiometricRows($companyId, $report->report_date->toDateString())
            : [];

        return view('fbr-pos.day-close-thermal', compact('company', 'report', 'transactions', 'cashierBreakdown', 'analytics', 'displayUdhaar', 'displayOther', 'hazri', 'bioPunches'));
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
        // Usage-vs-cap banner (Task 362): visibility for shops at/over their
        // plan's product cap (e.g. after a downgrade). null = unlimited.
        $productLimitStatus = \App\Services\PlanLimitService::productLimitStatus($companyId, 'fbr');
        return view('fbr-pos.products', compact('products', 'search', 'productLimitStatus'));
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
            'opening_stock' => 'nullable|numeric|min:0',
            'min_stock_level' => 'nullable|numeric|min:0',
        ]);

        $taxType = $request->tax_type;
        $taxRate = $taxType === 'taxable' ? 18 : ($taxType === 'exempt' ? 0 : ($request->default_tax_rate ?? 0));
        $isThirdScheduleFbr = $request->boolean('is_third_schedule');
        if ($isThirdScheduleFbr) { $taxType = 'exempt'; $taxRate = 0; }

        $createFbrData = [
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
        ];
        if (\Illuminate\Support\Facades\Schema::hasColumn('products', 'is_third_schedule')) {
            $createFbrData['is_third_schedule'] = $isThirdScheduleFbr;
        }
        $product = Product::create($createFbrData);

        // Retail Core (Aug 2026): optional opening stock + low-stock threshold.
        $this->applyProductStockFields($request, $product);

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
            'opening_stock' => 'nullable|numeric|min:0',
            'min_stock_level' => 'nullable|numeric|min:0',
        ]);

        $taxType = $request->tax_type;
        $taxRate = $taxType === 'taxable' ? 18 : ($taxType === 'exempt' ? 0 : ($request->default_tax_rate ?? 0));
        $isThirdScheduleFbrUpd = $request->boolean('is_third_schedule');
        if ($isThirdScheduleFbrUpd) { $taxType = 'exempt'; $taxRate = 0; }

        $updateFbrData = [
            'name' => $request->name,
            'barcode' => $request->barcode ?: null,
            'sku' => $request->sku ?: null,
            'default_price' => $request->default_price,
            'is_price_editable' => $request->boolean('is_price_editable'),
            'hs_code' => $request->hs_code,
            'uom' => $request->uom ?? 'U',
            'tax_type' => $taxType,
            'default_tax_rate' => $taxRate,
        ];
        if (\Illuminate\Support\Facades\Schema::hasColumn('products', 'is_third_schedule')) {
            $updateFbrData['is_third_schedule'] = $isThirdScheduleFbrUpd;
        }
        $product->update($updateFbrData);

        // Retail Core (Aug 2026): optional opening stock + low-stock threshold.
        $this->applyProductStockFields($request, $product);

        return redirect()->route('fbrpos.products')->with('success', __('pos.product_updated_success'));
    }

    /**
     * Retail Core (Aug 2026): apply the optional stock fields from the product
     * form. min_stock_level always saves (it's just a threshold). opening_stock
     * adds an OPENING movement — only when a value > 0 is supplied AND the
     * product has no opening movement yet (never duplicates on edit re-save).
     */
    private function applyProductStockFields(Request $request, Product $product): void
    {
        $companyId = app('currentCompanyId');

        if ($request->filled('min_stock_level')) {
            $stock = \App\Models\InventoryStock::firstOrCreate(
                ['company_id' => $companyId, 'product_id' => $product->id, 'branch_id' => null],
                ['quantity' => 0, 'min_stock_level' => 0, 'avg_purchase_price' => 0, 'last_purchase_price' => 0]
            );
            $stock->update(['min_stock_level' => (float) $request->min_stock_level]);
        }

        $opening = (float) ($request->opening_stock ?? 0);
        if ($opening > 0) {
            $alreadyHasOpening = \App\Models\InventoryMovement::where('company_id', $companyId)
                ->where('product_id', $product->id)
                ->where('type', \App\Models\InventoryMovement::TYPE_OPENING)
                ->exists();
            if (!$alreadyHasOpening) {
                try {
                    \App\Services\InventoryService::addStock(
                        $companyId,
                        $product->id,
                        $opening,
                        0,
                        \App\Models\InventoryMovement::TYPE_OPENING,
                        null,
                        ['type' => 'product_form', 'id' => $product->id, 'number' => null],
                        'Opening stock (product form)',
                        Auth::guard('fbrpos')->id()
                    );
                } catch (\Throwable $e) {
                    Log::warning('FBR POS opening stock failed', ['product' => $product->id, 'err' => $e->getMessage()]);
                }
            }
        }
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

    // ═══════════════════════════════════════════════════════════════════
    // 📦 Product Excel export/template/import — FBR mirror of the PRA POS
    // round-trip (PosController::downloadProductTemplate / importProducts).
    // Real .xlsx (never CSV: barcodes mangle to 8.9E+12), SKU/Barcode written
    // as EXPLICIT strings, Third Schedule Yes/No round-trip with the
    // third-schedule-implies-0-tax rule.
    // ═══════════════════════════════════════════════════════════════════

    // Sample rows shown in the blank template. importFbrProducts() silently skips a
    // row that still matches one of these EXACTLY (name+price+sku) so an untouched
    // sample never becomes a real product in the shop's list.
    private const FBR_IMPORT_SAMPLE_ROWS = [
        ['Lux Soap 100g', 120.0, 'LUX-100'],
        ['Pepsi 500ml', 120.0, 'PEP-500'],
        ['Sugar 1kg', 180.0, 'SUG-001'],
    ];

    public function downloadProductTemplate()
    {
        // Route sits behind fbrpos auth; extra belt-and-braces admin check like the
        // other product actions (null user = direct/CLI test invocation, allowed).
        $u = Auth::guard('fbrpos')->user();
        if ($u && $u->role !== 'company_admin') abort(403, 'Only admin can manage products.');
        if ($resp = $this->fbrPlanGate('excel_enabled')) return $resp;

        $companyId = app('currentCompanyId');
        $existingProducts = Product::where('company_id', $companyId)->orderBy('name')->get();

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Products');

        // A..I — FBR product columns (no Description/Category on the products table;
        // HS Code replaces them; the rest mirrors the PRA set incl. Third Schedule).
        $headers = ['Name', 'Price', 'HS Code', 'SKU', 'Barcode', 'Tax Rate %', 'Unit (UOM)', 'Tax Exempt (Yes/No)', 'Third Schedule (Yes/No)'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
        $sheet->getStyle('A1:I1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('BFDBFE');
        foreach (['A' => 32, 'B' => 10, 'C' => 16, 'D' => 14, 'E' => 18, 'F' => 11, 'G' => 12, 'H' => 18, 'I' => 20] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        // SKU + Barcode + HS Code columns forced to TEXT so Excel never converts
        // long codes to scientific notation or strips leading zeros.
        $sheet->getStyle('C:E')->getNumberFormat()
            ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);

        $rowNum = 2;
        if ($existingProducts->isEmpty()) {
            $samples = [
                ['Lux Soap 100g', 120, '3401.1100', 'LUX-100', '8964000112345', 18, 'U', 'No', 'Yes'],
                ['Pepsi 500ml', 120, '2202.1010', 'PEP-500', '8964000154321', 18, 'U', 'No', 'No'],
                ['Sugar 1kg', 180, '1701.9910', 'SUG-001', '', 0, 'KG', 'Yes', 'No'],
            ];
            foreach ($samples as $s) {
                $this->writeFbrProductRow($sheet, $rowNum++, $s);
            }
        } else {
            foreach ($existingProducts as $p) {
                $this->writeFbrProductRow($sheet, $rowNum++, [
                    $p->name,
                    (float) $p->default_price,
                    $p->hs_code ?? '',
                    $p->sku ?? '',
                    $p->barcode ?? '',
                    (float) ($p->default_tax_rate ?? 0),
                    $p->uom ?? 'U',
                    ($p->tax_type ?? '') === 'exempt' ? 'Yes' : 'No',
                    !empty($p->is_third_schedule) ? 'Yes' : 'No',
                ]);
            }
        }

        $filename = $existingProducts->isEmpty() ? 'fbr_products_template.xlsx' : 'fbr_products_export.xlsx';
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    private function writeFbrProductRow($sheet, int $rowNum, array $vals): void
    {
        // A..I = Name, Price, HS Code, SKU, Barcode, Tax %, UOM, Tax Exempt, Third Schedule.
        // HS Code/SKU/Barcode written as EXPLICIT strings (Excel would otherwise turn
        // 8964000112345 into 8.964E+12 the moment the file is opened).
        $sheet->setCellValue('A' . $rowNum, $vals[0]);
        $sheet->setCellValue('B' . $rowNum, $vals[1]);
        $sheet->setCellValueExplicit('C' . $rowNum, (string) $vals[2], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('D' . $rowNum, (string) $vals[3], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('E' . $rowNum, (string) $vals[4], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue('F' . $rowNum, $vals[5]);
        $sheet->setCellValue('G' . $rowNum, $vals[6]);
        $sheet->setCellValue('H' . $rowNum, $vals[7] ?? 'No');
        $sheet->setCellValue('I' . $rowNum, $vals[8] ?? 'No');
    }

    public function importProducts(Request $request)
    {
        $u = Auth::guard('fbrpos')->user();
        if ($u && $u->role !== 'company_admin') abort(403, 'Only admin can manage products.');
        if ($resp = $this->fbrPlanGate('excel_enabled')) return $resp;

        $companyId = app('currentCompanyId');

        // Subscription access gate (Task 361): the route deliberately has no
        // plan.limit middleware (an at-cap shop must still be able to run an
        // UPDATE-only import — the middleware 403s the whole request at cap),
        // so the middleware's Step-1 access check is applied here instead.
        // Per-row plan cap is enforced in the loop below.
        // FAIL CLOSED: no exception swallowing — an access-evaluation failure
        // aborts the request. The only pass-through is the narrow schema-compat
        // guard for minimal test schemas without a subscriptions table.
        if (\Illuminate\Support\Facades\Schema::hasTable('subscriptions')) {
            $accessCompany = Company::find($companyId);
            if ($accessCompany) {
                $access = \App\Services\SubscriptionAccessService::hasAccess($accessCompany);
                if (!$access['allowed']) {
                    return back()->with('error', \App\Services\SubscriptionAccessService::localizedLockReason($access['reason']));
                }
            }
        }

        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt,xlsx,xls|max:5120',
        ], [
            'csv_file.mimes' => 'File Excel (.xlsx) ya CSV honi chahiye.',
            'csv_file.max' => 'File 5 MB se choti honi chahiye.',
        ]);

        $file = $request->file('csv_file');
        $ext = strtolower($file->getClientOriginalExtension());

        try {
            $rows = in_array($ext, ['xlsx', 'xls'], true)
                ? $this->readFbrImportRowsExcel($file->getRealPath())
                : $this->readFbrImportRowsCsv($file->getRealPath());
        } catch (\Throwable $e) {
            Log::error('FBR POS product import parse failed: ' . $e->getMessage());
            return back()->with('error', __('pos.file_unreadable'));
        }

        if (count($rows) < 2) {
            return back()->with('error', __('pos.file_empty_rows'));
        }
        if (count($rows) > 5001) {
            return back()->with('error', __('pos.file_max_products'));
        }

        $header = array_map(function ($h) {
            return strtolower(trim(preg_replace('/[\x{FEFF}]/u', '', (string) $h)));
        }, $rows[0]);

        $nameIdx = $this->findFbrColumn($header, ['name', 'product name', 'product', 'item name', 'item']);
        $priceIdx = $this->findFbrColumn($header, ['price', 'sale price', 'rate', 'unit price', 'price (rs)', 'price rs', 'default price']);

        if ($nameIdx === false || $priceIdx === false) {
            return back()->with('error', __('pos.file_missing_name_price'));
        }

        $hsIdx = $this->findFbrColumn($header, ['hs code', 'hs_code', 'hscode', 'pct code', 'pct']);
        $skuIdx = $this->findFbrColumn($header, ['sku', 'code', 'item code', 'product code']);
        $barcodeIdx = $this->findFbrColumn($header, ['barcode', 'bar code', 'ean']);
        $taxIdx = $this->findFbrColumn($header, ['tax rate %', 'tax rate', 'tax_rate', 'tax', 'tax %']);
        $uomIdx = $this->findFbrColumn($header, ['unit (uom)', 'unit', 'uom']);
        $exemptIdx = $this->findFbrColumn($header, ['tax exempt (yes/no)', 'tax exempt', 'exempt (yes/no)', 'exempt', 'tax_exempt', 'is_tax_exempt']);
        // Third Schedule column: round-trip Yes/No; blank = leave flag as-is.
        $thirdIdx = $this->findFbrColumn($header, ['third schedule (yes/no)', 'third schedule', 'third_schedule', 'is_third_schedule', 'third']);

        // 🔒 ATOMIC QUOTA ADMISSION (Task 361 review): the whole catalog read +
        // allowance computation + row writes run in ONE transaction under a
        // company-row lock, so two simultaneous imports serialize — the second
        // recounts AFTER the first commits and can never double-spend the cap.
        DB::beginTransaction();
        try {
        Company::where('id', $companyId)->lockForUpdate()->get();

        // Preload the whole catalog ONCE — match precedence barcode → SKU → name.
        // Maps updated after each create so a duplicate row in the same file
        // updates instead of double-creating.
        $catalog = Product::where('company_id', $companyId)->get();
        $byBarcode = []; $bySku = []; $byName = [];
        foreach ($catalog as $p) {
            if (trim((string) $p->barcode) !== '') $byBarcode[strtolower(trim($p->barcode))] = $p;
            if (trim((string) $p->sku) !== '') $bySku[strtolower(trim($p->sku))] = $p;
            $byName[strtolower(trim($p->name))] = $p;
        }

        $hasThirdCol = \Illuminate\Support\Facades\Schema::hasColumn('products', 'is_third_schedule');

        // Plan product cap (Task 361): the route middleware only gates ENTRY —
        // a shop 1 under its cap could still land 5,000 rows over. Creation
        // stops at the remaining allowance; UPDATES to existing products always
        // apply. null = unlimited.
        $planRemaining = \App\Services\PlanLimitService::remainingProductAllowance((int) $companyId, 'fbr');
        $planSkipped = 0;

        $added = 0; $updated = 0; $skipped = 0; $samplesSkipped = 0;
        $errors = [];

        for ($i = 1; $i < count($rows); $i++) {
            $data = $rows[$i];
            $rowNo = $i + 1;

            $name = trim((string) ($data[$nameIdx] ?? ''));
            $priceRaw = $data[$priceIdx] ?? '';
            $rowEmpty = true;
            foreach ($data as $cell) { if (trim((string) $cell) !== '') { $rowEmpty = false; break; } }
            if ($rowEmpty) continue;

            if ($name === '') { $errors[] = "Row {$rowNo}: naam khali hai"; $skipped++; continue; }

            $price = $this->cleanFbrImportNumber($priceRaw);
            if ($price === null || $price < 0) {
                $errors[] = "Row {$rowNo}: '{$name}' ki price samajh nahi aayi (" . trim((string) $priceRaw) . ")";
                $skipped++;
                continue;
            }

            $hsCode = $hsIdx !== false ? $this->cleanFbrImportCode($data[$hsIdx] ?? null) : null;
            $sku = $skuIdx !== false ? $this->cleanFbrImportCode($data[$skuIdx] ?? null) : null;
            $barcode = $barcodeIdx !== false ? $this->cleanFbrImportCode($data[$barcodeIdx] ?? null) : null;
            $tax = $taxIdx !== false ? $this->cleanFbrImportNumber($data[$taxIdx] ?? '') : null;
            $uom = $uomIdx !== false ? strtoupper(trim((string) ($data[$uomIdx] ?? ''))) : '';

            // Third Schedule cell: Yes/No (tolerant); blank = leave flag as-is.
            $thirdSchedule = null;
            if ($thirdIdx !== false) {
                $tsRaw = strtolower(trim((string) ($data[$thirdIdx] ?? '')));
                if ($tsRaw !== '') {
                    $thirdSchedule = in_array($tsRaw, ['yes', 'y', '1', 'true', 'haan', 'han'], true);
                }
            }

            // Exempt cell: Yes/No (tolerant); blank = leave existing tax_type as-is
            // (new products default taxable). Unrecognized value → clear warning.
            $exempt = null;
            if ($exemptIdx !== false) {
                $exRaw = strtolower(trim((string) ($data[$exemptIdx] ?? '')));
                if ($exRaw !== '') {
                    if (in_array($exRaw, ['yes', 'y', '1', 'true', 'haan', 'han', 'exempt'], true)) {
                        $exempt = true;
                    } elseif (in_array($exRaw, ['no', 'n', '0', 'false', 'nahi'], true)) {
                        $exempt = false;
                    } else {
                        $errors[] = "Row {$rowNo}: '{$name}' ke Tax Exempt column mein '" . trim((string) ($data[$exemptIdx] ?? '')) . "' samajh nahi aaya — Yes ya No likhein (value ignore hui)";
                    }
                }
            }
            // Tax Rate cell must be a number; 'exempt' written there is understood
            // (flag ON, rate 0), anything else non-numeric gets a clear warning.
            if ($taxIdx !== false && $tax === null) {
                $taxRaw = trim((string) ($data[$taxIdx] ?? ''));
                if ($taxRaw !== '') {
                    if (strcasecmp($taxRaw, 'exempt') === 0) {
                        $exempt = $exempt ?? true;
                        $tax = 0.0;
                    } else {
                        $errors[] = "Row {$rowNo}: '{$name}' ka Tax Rate '{$taxRaw}' samajh nahi aaya — number likhein (masalan 18), exempt ke liye Tax Exempt column mein Yes likhein";
                    }
                }
            }

            // Untouched template sample rows never become real products.
            foreach (self::FBR_IMPORT_SAMPLE_ROWS as $s) {
                if (strcasecmp($name, $s[0]) === 0 && abs($price - $s[1]) < 0.001 && strcasecmp((string) $sku, $s[2]) === 0) {
                    $samplesSkipped++;
                    continue 2;
                }
            }

            $existing = null;
            if ($barcode !== null && isset($byBarcode[strtolower($barcode)])) $existing = $byBarcode[strtolower($barcode)];
            if (!$existing && $sku !== null && isset($bySku[strtolower($sku)])) $existing = $bySku[strtolower($sku)];
            if (!$existing && isset($byName[strtolower($name)])) $existing = $byName[strtolower($name)];

            // Third Schedule implies exempt (which implies 0 tax below) —
            // mirrors storeProduct/updateProduct's rule.
            if ($thirdSchedule === true) { $exempt = true; }

            // tax_type/default_tax_rate resolution (FBR model: taxable=18 / exempt=0 / custom=N):
            //   exempt Yes            → exempt, 0
            //   rate given, no exempt → 18 = taxable, else custom at that rate
            //   nothing given         → existing values (update) / taxable 18 (create)
            $resolveTax = function (?string $curType, $curRate) use ($exempt, $tax) {
                if ($exempt === true) return ['exempt', 0.0];
                if ($tax !== null) {
                    if ($exempt === false && abs($tax) < 0.001) return ['custom', 0.0];
                    if (abs($tax - 18.0) < 0.001) return ['taxable', 18.0];
                    if (abs($tax) < 0.001) return ['exempt', 0.0];
                    return ['custom', (float) $tax];
                }
                if ($exempt === false && $curType === 'exempt') {
                    // Explicit No on a currently-exempt product with no rate → taxable default.
                    return ['taxable', 18.0];
                }
                return [$curType, $curRate];
            };

            if ($existing) {
                [$newType, $newRate] = $resolveTax($existing->tax_type, (float) ($existing->default_tax_rate ?? 0));
                $updateData = [
                    'name' => $name,
                    'default_price' => $price,
                    'hs_code' => $hsCode !== null ? $hsCode : $existing->hs_code,
                    'sku' => $sku !== null ? $sku : $existing->sku,
                    'barcode' => $barcode !== null ? $barcode : $existing->barcode,
                    'uom' => $uom !== '' ? $uom : $existing->uom,
                    'tax_type' => $newType ?? 'taxable',
                    'default_tax_rate' => $newRate ?? 0,
                ];
                if ($hasThirdCol) {
                    $updateData['is_third_schedule'] = $thirdSchedule !== null ? $thirdSchedule : (bool) $existing->is_third_schedule;
                }
                $existing->update($updateData);
                $updated++;
                $product = $existing;
            } else {
                // Plan cap: stop CREATING once remaining allowance is used up
                // (updates above still apply; skipped rows counted for the flash).
                if ($planRemaining !== null && $planRemaining <= 0) {
                    $planSkipped++;
                    continue;
                }
                [$newType, $newRate] = $resolveTax(null, null);
                $createData = [
                    'company_id' => $companyId,
                    'name' => $name,
                    'default_price' => $price,
                    'hs_code' => $hsCode,
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'uom' => $uom !== '' ? $uom : 'U',
                    'tax_type' => $newType ?? 'taxable',
                    'default_tax_rate' => $newRate ?? 18,
                    'is_active' => true,
                    'show_on_sale' => true, // explicit — never trust the DB default (prod drift)
                ];
                if ($hasThirdCol) {
                    $createData['is_third_schedule'] = $thirdSchedule === true;
                }
                $product = Product::create($createData);
                $added++;
                if ($planRemaining !== null) { $planRemaining--; }
            }

            // Keep maps fresh so later rows in the same file match this product.
            if ($barcode !== null) $byBarcode[strtolower($barcode)] = $product;
            if ($sku !== null) $bySku[strtolower($sku)] = $product;
            $byName[strtolower($name)] = $product;
        }

        DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $parts = [];
        if ($added > 0) $parts[] = __('pos.import_new_products_added', ['count' => $added]);
        if ($updated > 0) $parts[] = __('pos.import_updated', ['count' => $updated]);
        if ($samplesSkipped > 0) $parts[] = __('pos.import_sample_rows_skipped', ['count' => $samplesSkipped]);
        if ($skipped > 0) $parts[] = __('pos.import_rows_skipped', ['count' => $skipped]);
        if ($planSkipped > 0) $parts[] = __('pos.import_plan_limit_skipped', ['count' => $planSkipped]);
        $msg = $parts ? implode(', ', $parts) . '.' : __('pos.import_no_rows');
        if (!empty($errors)) $msg .= __('pos.import_issues', ['issues' => implode('; ', array_slice($errors, 0, 5))]) . (count($errors) > 5 ? __('pos.import_more_suffix', ['count' => count($errors) - 5]) : '');

        if ($added === 0 && $updated === 0) {
            return back()->with('error', $msg);
        }
        return back()->with('success', $msg);
    }

    private function readFbrImportRowsExcel(string $path): array
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        // Row-cap BEFORE materializing the sheet — a 5MB xlsx can hold hundreds of
        // thousands of rows (zip compression); toArray() on that would OOM shared
        // cPanel PHP before the post-parse count check ever ran.
        if ($sheet->getHighestDataRow() > 5001) {
            $spreadsheet->disconnectWorksheets();
            return array_fill(0, 5002, []); // triggers the friendly >5000 error upstream
        }

        $rows = $sheet->toArray(null, true, false, false);
        $spreadsheet->disconnectWorksheets();
        return $rows;
    }

    private function readFbrImportRowsCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) throw new \RuntimeException('Could not open file');

        // Excel (regional settings) sometimes saves CSV with ; or TAB — auto-detect
        // from the header line instead of assuming comma.
        $firstLine = fgets($handle) ?: '';
        $delims = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($delims);
        $delim = array_key_first($delims);
        if ($delims[$delim] === 0) $delim = ',';
        rewind($handle);

        $rows = [];
        while (($data = fgetcsv($handle, 0, $delim)) !== false) {
            $rows[] = $data;
            if (count($rows) > 5002) break;
        }
        fclose($handle);
        return $rows;
    }

    // "Rs 1,200", "1200.50", "16%" → float; anything non-numeric → null.
    private function cleanFbrImportNumber($raw): ?float
    {
        if (is_int($raw) || is_float($raw)) return (float) $raw;
        $s = trim((string) $raw);
        if ($s === '') return null;
        $s = str_ireplace(['rs.', 'rs', 'pkr', '%'], '', $s);
        $s = str_replace([',', ' '], '', $s);
        if (!is_numeric($s)) return null;
        return (float) $s;
    }

    // SKU/Barcode/HS-code cleaner: Excel numeric cells arrive as floats
    // (8964000112345.0) and CSV round-trips arrive as scientific notation
    // ("8.964E+12") — both restored to plain digit strings. Empty → null
    // (never overwrite with blank).
    private function cleanFbrImportCode($raw): ?string
    {
        if ($raw === null) return null;
        if (is_int($raw)) return (string) $raw;
        if (is_float($raw)) {
            // Whole number → plain digits (barcodes); fractional → keep decimals
            // (HS codes like 3401.1100 must never truncate to "3401").
            return floor($raw) == $raw
                ? sprintf('%.0f', $raw)
                : rtrim(rtrim(sprintf('%.4f', $raw), '0'), '.');
        }
        $s = trim((string) $raw);
        if ($s === '') return null;
        if (preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $s)) return sprintf('%.0f', (float) $s);
        if (preg_match('/^\d+\.0+$/', $s)) return preg_replace('/\.0+$/', '', $s);
        return $s;
    }

    private function findFbrColumn(array $header, array $names): int|false
    {
        foreach ($names as $name) {
            $idx = array_search($name, $header);
            if ($idx !== false) return $idx;
        }
        return false;
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

        $baseQuery = FbrPosTransaction::where('company_id', $companyId)
            ->whereNull('fbr_invoice_number')
            ->where(function ($q) {
                $q->whereNull('invoice_mode')->orWhere('invoice_mode', '!=', 'local');
            });

        // Auto-retry pool: failed/offline/pending only — config_error intentionally excluded
        // so the 30-second auto-sync loop never picks up permanently-misconfigured bills.
        $bills = (clone $baseQuery)
            ->whereIn('fbr_status', ['failed', 'offline', 'pending'])
            ->orderBy('id', 'desc')
            ->limit(100)
            ->get(['id', 'invoice_number', 'customer_name', 'total_amount', 'fbr_status', 'fbr_response_code', 'fbr_error_message', 'created_at']);

        $mapBill = fn ($b) => [
            'id'             => (int) $b->id,
            'invoice_number' => $b->invoice_number,
            'customer_name'  => $b->customer_name,
            'total_amount'   => (float) $b->total_amount,
            'fbr_status'     => $b->fbr_status,
            'error_code'     => $b->fbr_response_code,
            // Task 627: asal wajah (timeout / HTTP code / FBR message) — F11 modal.
            'error_message'  => $b->fbr_error_message,
            'created_human'  => $b->created_at?->diffForHumans(),
            'created_at'     => $b->created_at?->toDateTimeString(),
        ];

        $data = $bills->map($mapBill);

        // Config-error bills: shown separately in the F11 panel with a "Fix Settings" note.
        // These are NOT in the auto-retry pool but ARE manually retryable (apiRetryFailed accepts them).
        $configErrorBills = (clone $baseQuery)
            ->where('fbr_status', 'config_error')
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get(['id', 'invoice_number', 'customer_name', 'total_amount', 'fbr_status', 'fbr_response_code', 'fbr_error_message', 'created_at'])
            ->map($mapBill);

        return response()->json([
            'success'            => true,
            'count'              => $data->count(),
            'bills'              => $data,
            'config_error_bills' => $configErrorBills,
        ]);
    }

    /**
     * 🔄 Auto-sync API — retry a single failed FBR POS bill via JSON.
     * Race-safe atomic claim prevents duplicate FBR submissions on
     * double-click / concurrent poller / queued RetryFbrPosSubmissionJob.
     *
     * Accepts an optional JSON body flag: { "manual": true }
     *  - manual=true  → explicit user action (F11 panel Retry button):
     *      resets fbr_auto_retry_count to 0, no cap check.
     *  - manual=false (default, auto-sync tick):
     *      enforces SyncFbrPosOfflineInvoicesJob::MAX_AUTO_RETRY cap;
     *      increments counter on failure.
     */
    public function apiRetryFailed(Request $request, $id)
    {
        $companyId = app('currentCompanyId');
        $isManual  = (bool) $request->json('manual', false);
        $maxRetry  = \App\Jobs\SyncFbrPosOfflineInvoicesJob::MAX_AUTO_RETRY;

        // Auto-sync cap check: if the caller is the automated 30-second loop
        // (manual=false) and this bill has already exhausted its retry budget,
        // refuse immediately so the loop can never spin past the cap.
        if (!$isManual) {
            $capCheck = FbrPosTransaction::where('company_id', $companyId)
                ->where('id', $id)
                ->whereNull('fbr_invoice_number')
                ->whereIn('fbr_status', ['failed', 'offline', 'pending'])
                ->where(function ($q) {
                    $q->whereNull('invoice_mode')->orWhere('invoice_mode', '!=', 'local');
                })
                ->first(['fbr_auto_retry_count', 'fbr_status']);

            if ($capCheck && $capCheck->fbr_auto_retry_count >= $maxRetry) {
                return response()->json([
                    'success' => false,
                    'retry_exhausted' => true,
                    'message' => __('pos.retry_cap_reached'),
                ], 429);
            }
        }

        // Build the update payload for the atomic claim.
        // Manual retry resets the counter; auto retry leaves it untouched here
        // (incremented below on failure).
        $claimUpdate = ['fbr_status' => 'pending', 'fbr_submission_hash' => null];
        if ($isManual) {
            $claimUpdate['fbr_auto_retry_count'] = 0;
        }

        // Atomic claim: flip from failed/offline/pending/config_error → pending only if still
        // un-submitted. Conditional UPDATE returns affected-row count;
        // 0 = another caller already claimed it.
        // config_error is included here to allow MANUAL retry after the admin fixes settings
        // (POSID / token). The auto-sync loop never picks up config_error bills (apiFailedBills
        // only returns IN ('failed','offline','pending')), so this path is manual-only.
        $claimQuery = FbrPosTransaction::where('company_id', $companyId)
            ->where('id', $id)
            ->whereNull('fbr_invoice_number')
            ->whereIn('fbr_status', ['failed', 'offline', 'pending', 'config_error'])
            ->where(function ($q) {
                $q->whereNull('invoice_mode')->orWhere('invoice_mode', '!=', 'local');
            });

        // Auto-sync: also enforce cap in the atomic claim to close the race window
        // between the cap check above and this UPDATE.
        if (!$isManual) {
            $claimQuery->where('fbr_auto_retry_count', '<', $maxRetry);
        }

        $claimed = $claimQuery->update($claimUpdate);

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
            // Could be cap-blocked (auto-sync raced with another tick).
            if (!$isManual && $tx->fbr_auto_retry_count >= $maxRetry) {
                return response()->json([
                    'success' => false,
                    'retry_exhausted' => true,
                    'message' => __('pos.retry_cap_reached'),
                ], 429);
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
                // Reset counter on success (belt-and-suspenders: FbrService may have already done it).
                FbrPosTransaction::where('id', $id)->update(['fbr_auto_retry_count' => 0]);
                return response()->json([
                    'success'   => true,
                    'submitted' => true,
                    'message'   => __('pos.fbr_submission_successful_num_short', ['number' => $transaction->fbr_invoice_number ?? 'N/A']),
                    'fbr_invoice_number' => $transaction->fbr_invoice_number,
                    'id' => $transaction->id,
                ]);
            }

            // Automated call: increment counter on failure so the cap is enforced
            // over time even if page refreshes reset the session-level 3-strike cap.
            if (!$isManual) {
                FbrPosTransaction::where('id', $id)->increment('fbr_auto_retry_count');
            }

            return response()->json([
                'success' => false,
                'message' => __('pos.fbr_retry_failed_errors', ['error' => implode(', ', $result['errors'] ?? [__('pos.unknown_error')])]),
            ], 422);
        } catch (\Throwable $e) {
            if (!$isManual) {
                FbrPosTransaction::where('id', $id)->increment('fbr_auto_retry_count');
            }
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

        // Pizza Master (Aug 2026): match phone on digits-only both sides (dashes/spaces tolerated).
        // Phone-only grammar gate (same as client isPhoneLike): letters = name search, never phone.
        $digits = preg_match('/^[0-9+()\s\-]+$/', trim($q)) ? preg_replace('/\D+/', '', $q) : '';
        $customers = \App\Models\PosCustomer::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($query) use ($q, $digits) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($q) . '%'])
                    ->orWhereRaw('LOWER(phone) LIKE ?', ['%' . strtolower($q) . '%']);
                if (strlen($digits) >= 4) {
                    $query->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''),'-',''),' ',''),'(',''),')',''),'+','') LIKE ?", ['%' . $digits . '%']);
                }
            })
            ->limit(8)
            ->get(['id', 'name', 'phone', 'email', 'address', 'khata_balance']);

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
                // Retail Core (Aug 2026): udhaar balance shown on the selected-customer card.
                'khata_balance' => round((float) ($c->khata_balance ?? 0), 2),
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
        // SERVER-SIDE GATE: quick-create exists ONLY for inventory-OFF companies (the UI
        // hides it when inventory is ON, but a direct POST must be refused too — otherwise
        // stock-tracked catalogs grow rows that bypass opening-stock / movement bookkeeping).
        $gateCompany = Company::find($companyId);
        if ($gateCompany && $gateCompany->inventory_enabled) {
            return response()->json(['ok' => false, 'error' => 'Quick-create is disabled while inventory tracking is ON. Use the Products page.'], 403);
        }
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'price'    => 'required|numeric|min:0',
            'barcode'  => 'nullable|string|max:64',
            // KEEP IN SYNC with product-form.blade.php $uomList (same codes).
            'uom'      => 'nullable|string|in:U,PCS,KG,GM,LTR,ML,MTR,SQM,FT,IN,YDS,PKT,DOZ,BOX,CTN,BAG,BTL,TIN,CAN,BUN,ROL,SET',
            'tax_mode' => 'nullable|in:standard,exempt,custom',
            'tax_rate' => 'nullable|required_if:tax_mode,custom|numeric|min:0|max:100',
            'hs_code'  => 'nullable|string|max:50',
            // Edit-mode (owner, Aug 2026): the popup reopens for an EXISTING unpriced
            // product — Save updates that row instead of creating/deduping a twin.
            'existing_id' => 'nullable|integer',
        ]);
        $name = trim($data['name']);
        if ($name === '') {
            return response()->json(['ok' => false, 'error' => 'Name required'], 422);
        }
        $barcode = isset($data['barcode']) ? (trim((string) $data['barcode']) ?: null) : null;
        // Tax mapping mirrors storeProduct: standard => taxable 18%, exempt => 0%, custom => given rate.
        $taxMode = $data['tax_mode'] ?? 'standard';
        $taxType = $taxMode === 'standard' ? 'taxable' : $taxMode;
        $taxRate = $taxMode === 'exempt' ? 0 : ($taxMode === 'custom' ? (float) ($data['tax_rate'] ?? 0) : 18);
        // DEDUPE (Aug 2026 scanner bug): repeated scanner Enters were quick-creating the
        // same barcode-named product on every scan. An active same-name (case-insensitive)
        // product is returned as-is instead of creating a twin row.
        // ATOMIC across concurrent requests (two terminals scanning the same unknown code
        // at the same instant): a MySQL named lock makes the check-then-insert a critical
        // section — no unique-index schema change needed (legit same-name products may
        // already exist historically). Scoped to the COMPANY (not company+name): dedupe
        // matches by name OR barcode, and two terminals scanning the same unknown barcode
        // with different typed names would otherwise take different per-name locks and
        // both insert. Quick-create is rare enough that a per-company section is free.
        // MySQL-only; the sqlite test env skips the lock (single-connection).
        // Subscription access gate (Task 362 review): the route deliberately has
        // no plan.limit middleware (the middleware 403s the whole request at cap,
        // breaking dedupe/reprice of EXISTING products for at-cap shops), so the
        // middleware's Step-1 access check is applied here instead — suspended/
        // expired/trial-ended shops are blocked before ANY write or dedupe read.
        // FAIL CLOSED: no exception swallowing. The only pass-through is the
        // narrow schema-compat guard for minimal test schemas.
        if (\Illuminate\Support\Facades\Schema::hasTable('subscriptions')) {
            $accessCompany = Company::find($companyId);
            if ($accessCompany) {
                $access = \App\Services\SubscriptionAccessService::hasAccess($accessCompany);
                if (!$access['allowed']) {
                    return response()->json(['ok' => false, 'error' => \App\Services\SubscriptionAccessService::localizedLockReason($access['reason'])], 403);
                }
            }
        }

        $lockName  = 'qc_prod_' . $companyId;
        $usingLock = DB::getDriverName() === 'mysql';
        $gotLock   = false;
        if ($usingLock) {
            $lockRow = DB::selectOne('SELECT GET_LOCK(?, 3) AS l', [$lockName]);
            $gotLock = (int) ($lockRow->l ?? 0) === 1;
            // Timed out waiting (l=0): proceed best-effort — the dedupe read below still
            // runs; behavior degrades to the pre-lock check-then-insert, never blocks a sale.
        }
        try {
            // Edit-mode: an explicit existing_id (popup reopened for an unpriced row) wins
            // over name/barcode dedupe — company-scoped, never cross-tenant.
            $existing = null;
            $viaId    = false;
            if (!empty($data['existing_id'])) {
                $existing = Product::where('company_id', $companyId)
                    ->where('is_active', true)
                    ->find((int) $data['existing_id']);
                $viaId = $existing !== null;
            }
            if (!$existing) {
                $existing = Product::where('company_id', $companyId)
                    ->where('is_active', true)
                    ->where(function ($q) use ($name, $barcode) {
                        $q->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
                        if ($barcode !== null) {
                            $q->orWhere('barcode', $barcode);
                        }
                    })
                    ->first();
            }
            if ($existing) {
                // OWNER (Aug 2026): an UNPRICED (Rs.0) row saved from the full-details popup
                // gets UPDATED — price always; missing barcode/hs filled; uom/tax taken over.
                // Priced rows are returned untouched (dedupe semantics unchanged).
                if ((float) $existing->default_price <= 0 && isset($data['price']) && (float) $data['price'] > 0) {
                    $existing->default_price    = (float) $data['price'];
                    if ($barcode !== null && !$existing->barcode) {
                        $existing->barcode = $barcode;
                    }
                    if (!empty($data['uom'])) {
                        $existing->uom = $data['uom'];
                    }
                    $existing->tax_type         = $taxType;
                    $existing->default_tax_rate = $taxRate;
                    $hsIn = isset($data['hs_code']) ? (trim((string) $data['hs_code']) ?: null) : null;
                    if ($hsIn !== null && !$existing->hs_code) {
                        $existing->hs_code = $hsIn;
                    }
                    // Name correction only in explicit edit-mode, and only when it doesn't
                    // collide with another active product (dedupe key stays trustworthy).
                    if ($viaId && $name !== '' && mb_strtolower($name) !== mb_strtolower($existing->name)) {
                        $nameClash = Product::where('company_id', $companyId)
                            ->where('is_active', true)
                            ->where('id', '!=', $existing->id)
                            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                            ->exists();
                        if (!$nameClash) {
                            $existing->name = $name;
                        }
                    }
                    $existing->save();
                }
                return response()->json([
                    'ok' => true,
                    'product' => [
                        'id'            => $existing->id,
                        'name'          => $existing->name,
                        'price'         => (float) $existing->default_price,
                        'category'      => 'Quick',
                        'type'          => 'product',
                        'image'         => null,
                        'is_tax_exempt' => $existing->tax_type === 'exempt',
                        'tax_rate'      => (float) ($existing->default_tax_rate ?? 0),
                        'hs_code'       => $existing->hs_code,
                        'uom'           => $existing->uom ?? 'U',
                        'barcode'       => $existing->barcode,
                        'sku'           => $existing->sku,
                        'hasRecipe'     => false,
                        'stockStatus'   => null,
                        'isQuickCreated'=> true,
                    ],
                ]);
            }
            // Plan product cap (Task 362): checked HERE — after dedupe/edit-mode —
            // so an at-cap shop can still scan/reprice EXISTING products via the
            // quick popup; only a genuinely NEW row is blocked. (The route-level
            // plan.limit middleware was removed for exactly this reason.)
            // 🔒 ATOMIC QUOTA ADMISSION (same pattern as importProducts): count +
            // insert run in ONE transaction under a company-row lock — the named
            // GET_LOCK above only covers MySQL; this holds on every driver.
            try {
                $product = DB::transaction(function () use ($companyId, $name, $barcode, $data, $taxRate, $taxType) {
                    Company::where('id', $companyId)->lockForUpdate()->get();
                    $remaining = \App\Services\PlanLimitService::remainingProductAllowance($companyId, 'fbr');
                    if ($remaining !== null && $remaining <= 0) {
                        throw new \App\Exceptions\PlanLimitReachedException();
                    }
                    return Product::create([
                        'company_id'       => $companyId,
                        'name'             => $name,
                        'barcode'          => $barcode,
                        'default_price'    => $data['price'] ?? 0,
                        'default_tax_rate' => $taxRate,
                        'tax_type'         => $taxType,
                        'uom'              => $data['uom'] ?? 'U',
                        'hs_code'          => isset($data['hs_code']) ? (trim((string) $data['hs_code']) ?: null) : null,
                        'sku'              => 'QC-' . substr((string) time(), -6) . '-' . strtoupper(substr(uniqid(), -3)),
                        'is_price_editable'=> true,
                        'is_active'        => true,
                    ]);
                });
            } catch (\App\Exceptions\PlanLimitReachedException $e) {
                return response()->json([
                    'ok' => false,
                    'error' => __('pos.product_limit_reached_error'),
                    'reason' => 'plan_limit',
                ], 403);
            }
            return response()->json([
                'ok' => true,
                'product' => [
                    'id'            => $product->id,
                    'name'          => $product->name,
                    'price'         => (float) $product->default_price,
                    'category'      => 'Quick',
                    'type'          => 'product',
                    'image'         => null,
                    'is_tax_exempt' => $taxType === 'exempt',
                    'tax_rate'      => (float) $taxRate,
                    'hs_code'       => $product->hs_code,
                    'uom'           => $product->uom ?? 'U',
                    'barcode'       => $product->barcode,
                    'sku'           => $product->sku,
                    'hasRecipe'     => false,
                    'stockStatus'   => null,
                    'isQuickCreated'=> true,
                ],
            ]);
        } finally {
            if ($usingLock && $gotLock) {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS r', [$lockName]);
            }
        }
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
