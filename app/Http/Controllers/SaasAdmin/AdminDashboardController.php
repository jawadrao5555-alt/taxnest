<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Franchise;
use App\Models\PosTransaction;
use App\Models\FbrPosTransaction;
use App\Models\AdminAuditLog;
use App\Models\SystemControl;
use App\Services\Reporting\ErpAdminFinancialSummary;
use App\Support\NestErps;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminDashboardController extends Controller
{
    public function index(Request $request, ErpAdminFinancialSummary $erpFinancials)
    {
        $erpDates = $request->validate([
            'erp_from' => 'nullable|date_format:Y-m-d',
            'erp_to' => $request->filled('erp_from') ? 'nullable|date_format:Y-m-d|after_or_equal:erp_from' : 'nullable|date_format:Y-m-d',
        ]);
        $erpFilters = array_filter([
            'date_from' => $erpDates['erp_from'] ?? null,
            'date_to' => $erpDates['erp_to'] ?? null,
        ]);
        $erpType = NestErps::PRODUCT_TYPE;
        $erpTypes = NestErps::storedTypesFor($erpType);
        // Single grouped query instead of loading every company row (scale-safe).
        $productCounts = Company::select('product_type', DB::raw('COUNT(*) as cnt'))
            ->groupBy('product_type')->pluck('cnt', 'product_type');

        $stats = [
            'total_companies' => Company::count(),
            'di_companies' => (int) ($productCounts['di'] ?? 0),
            'pos_companies' => (int) ($productCounts['pos'] ?? 0),
            'fbrpos_companies' => (int) ($productCounts['fbrpos'] ?? 0),
            'erp_companies' => (int) $productCounts->only($erpTypes)->sum(),
            'pending_companies' => Company::where('status', 'pending')->count(),
            'pending_payment_proofs' => Schema::hasTable('payment_proofs')
                ? \App\Models\PaymentProof::where('status', 'pending')->count() : 0,
            // Pending proofs whose auto-granted temporary access ends within
            // 2 days — the dashboard tile flags these in red so the admin
            // verifies before the reconciler locks a paying customer out.
            'expiring_payment_proofs' => (Schema::hasTable('payment_proofs') && Schema::hasColumn('payment_proofs', 'auto_access_until'))
                ? \App\Models\PaymentProof::where('status', 'pending')
                    ->whereNotNull('auto_access_until')
                    ->where('auto_access_until', '<=', now()->addDays(2)->endOfDay())
                    ->count()
                : 0,
            'suspended_companies' => Company::where('status', 'suspended')->count(),
            'binned_companies' => Company::onlyTrashed()->count(),
            'active_subscriptions' => Subscription::where('active', true)->count(),
            'total_users' => User::count(),
            'total_franchises' => Schema::hasTable('franchises') ? Franchise::count() : 0,

            'di_invoices' => Invoice::whereHas('company', fn($q) => $q->where('product_type', 'di'))->count(),
            'di_revenue' => Invoice::whereHas('company', fn($q) => $q->where('product_type', 'di'))->where('status', 'locked')->sum('total_amount'),

            'pos_transactions' => PosTransaction::whereHas('company', fn($q) => $q->where('product_type', 'pos'))->where('status', 'completed')->count(),
            'pos_revenue' => PosTransaction::whereHas('company', fn($q) => $q->where('product_type', 'pos'))->where('status', 'completed')->sum('total_amount'),
            'today_pos_transactions' => PosTransaction::whereHas('company', fn($q) => $q->where('product_type', 'pos'))->where('status', 'completed')
                ->whereDate('created_at', today())->count(),

            'fbrpos_transactions' => FbrPosTransaction::whereHas('company', fn($q) => $q->where('product_type', 'fbrpos'))->count(),
            'fbrpos_revenue' => FbrPosTransaction::whereHas('company', fn($q) => $q->where('product_type', 'fbrpos'))->sum('total_amount'),
            'today_fbrpos_transactions' => FbrPosTransaction::whereHas('company', fn($q) => $q->where('product_type', 'fbrpos'))->whereDate('created_at', today())->count(),
        ];

        // Dashboard shows the latest N per tab; the full paginated list lives at
        // /admin/companies. Loading + rendering every company here collapsed at scale
        // (3000 companies = ~6s response, ~5MB HTML, 6000+ per-row queries).
        $tabLimit = 50;

        $diCompaniesList = Company::where('product_type', 'di')
            ->with(['activeSubscription', 'franchise'])
            ->withCount(['users', 'invoices'])
            ->orderBy('created_at', 'desc')
            ->limit($tabLimit)
            ->get();

        $posCompaniesList = Company::where('product_type', 'pos')
            ->with(['activeSubscription', 'franchise'])
            ->withCount('users')
            ->orderBy('created_at', 'desc')
            ->limit($tabLimit)
            ->get();

        $fbrposCompaniesList = Company::where('product_type', 'fbrpos')
            ->with(['activeSubscription', 'franchise'])
            ->withCount('users')
            ->orderBy('created_at', 'desc')
            ->limit($tabLimit)
            ->get();

        $erpCompaniesList = Company::whereIn('product_type', $erpTypes)
            ->withCount('users')
            ->latest()->orderByDesc('id')
            ->limit($tabLimit)
            ->get();
        $erpSummaries = $erpFinancials->forCompanies($erpCompaniesList, $erpFilters);
        $erpTotals = $erpFinancials->allCompanyTotals($erpFilters);

        // Per-company aggregates via three grouped queries (was 2 queries PER company).
        $posAgg = PosTransaction::whereIn('company_id', $posCompaniesList->pluck('id'))
            ->where('status', 'completed')
            ->select('company_id', DB::raw('COUNT(*) as tx_count'), DB::raw('COALESCE(SUM(total_amount),0) as revenue'))
            ->groupBy('company_id')->get()->keyBy('company_id');
        foreach ($posCompaniesList as $pc) {
            $pc->pos_transaction_count = (int) ($posAgg[$pc->id]->tx_count ?? 0);
            $pc->pos_revenue = (float) ($posAgg[$pc->id]->revenue ?? 0);
        }

        $fbrAgg = FbrPosTransaction::whereIn('company_id', $fbrposCompaniesList->pluck('id'))
            ->select('company_id', DB::raw('COUNT(*) as tx_count'), DB::raw('COALESCE(SUM(total_amount),0) as revenue'))
            ->groupBy('company_id')->get()->keyBy('company_id');
        foreach ($fbrposCompaniesList as $fc) {
            $fc->fbrpos_transaction_count = (int) ($fbrAgg[$fc->id]->tx_count ?? 0);
            $fc->fbrpos_revenue = (float) ($fbrAgg[$fc->id]->revenue ?? 0);
        }

        $diAgg = Invoice::whereIn('company_id', $diCompaniesList->pluck('id'))
            ->where('status', 'locked')
            ->select('company_id', DB::raw('COALESCE(SUM(total_amount),0) as revenue'))
            ->groupBy('company_id')->get()->keyBy('company_id');
        foreach ($diCompaniesList as $dc) {
            $dc->di_revenue = (float) ($diAgg[$dc->id]->revenue ?? 0);
        }

        // Agent Health (Task 635): same logic as /admin/companies (Task 629) —
        // silent-print shops whose Desktop Agent is >2h offline. Red alert card
        // on the dashboard so the admin sees it without opening the companies page.
        $offlineAgentCount = 0;
        if (Schema::hasColumn('companies', 'agent_enabled')) {
            $offlineAgentCount = Company::query()
                ->where('agent_enabled', true)
                ->whereIn('status', ['approved', 'active'])
                ->where(function ($q) {
                    $q->whereNull('agent_last_seen')
                      ->orWhere('agent_last_seen', '<', now()->subHours(2));
                })
                ->get(['id', 'agent_enabled', 'agent_last_seen', 'pos_printer_settings'])
                ->filter(fn ($c) => $c->agentLongOffline())
                ->count();
        }

        $recentAuditLogs = Schema::hasTable('admin_audit_logs')
            ? AdminAuditLog::with('admin')->orderBy('created_at', 'desc')->take(10)->get()
            : collect();
        $systemControls = Schema::hasTable('system_controls') ? SystemControl::all() : collect();

        return view('saas-admin.dashboard', compact(
            'stats', 'diCompaniesList', 'posCompaniesList', 'fbrposCompaniesList',
            'erpType', 'erpDates', 'erpFilters', 'erpCompaniesList', 'erpSummaries', 'erpTotals',
            'recentAuditLogs', 'systemControls', 'offlineAgentCount'
        ));
    }
}
