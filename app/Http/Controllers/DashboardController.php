<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\InvoiceActivityLog;
use App\Models\FbrLog;
use App\Models\ComplianceScore;
use App\Models\ComplianceReport;
use App\Models\AnomalyLog;
use App\Models\Notification;
use App\Models\VendorRiskProfile;
use App\Services\ComplianceRiskService;
use App\Services\ComplianceScoreService;
use App\Services\SmartInsightsService;
use App\Services\HybridComplianceScorer;
use App\Services\AuditDefenseService;
use App\Services\RiskIntelligenceEngine;
use App\Services\VendorRiskEngine;
use App\Services\AuditProbabilityEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        if ($user->role === 'super_admin' && !$user->company_id) {
            return redirect('/admin/dashboard');
        }

        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $invoiceCounts = Invoice::where('company_id', $companyId)
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count"),
                DB::raw("SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted_count"),
                DB::raw("SUM(CASE WHEN status = 'locked' THEN 1 ELSE 0 END) as locked_count"),
                DB::raw('COALESCE(SUM(total_amount), 0) as total_revenue')
            )
            ->first();

        $totalInvoices = $invoiceCounts->total;
        $draftCount = $invoiceCounts->draft_count;
        $submittedCount = $invoiceCounts->submitted_count;
        $lockedCount = $invoiceCounts->locked_count;
        $totalRevenue = $invoiceCounts->total_revenue;

        $subscription = Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->with('pricingPlan')
            ->first();

        $invoiceLimit = $subscription && $subscription->pricingPlan ? $subscription->pricingPlan->invoice_limit : 0;
        $invoicesUsed = $totalInvoices;

        $planTier = 'retail';
        if ($subscription && $subscription->pricingPlan) {
            $planName = strtolower($subscription->pricingPlan->name);
            if (in_array($planName, ['enterprise', 'industrial'])) {
                $planTier = 'enterprise';
            } elseif ($planName === 'business') {
                $planTier = 'business';
            }
        }

        if ($company && $company->is_internal_account) {
            $planTier = 'enterprise';
        }

        $statusData = [
            'draft' => $draftCount,
            'submitted' => $submittedCount,
            'locked' => $lockedCount,
        ];

        $monthlyData = Invoice::where('company_id', $companyId)
            ->selectRaw("TO_CHAR(created_at, 'Mon') as month_label, EXTRACT(MONTH FROM created_at) as month_num, COUNT(*) as count, SUM(total_amount) as total")
            ->groupBy('month_label', 'month_num')
            ->orderBy('month_num')
            ->get();

        $recentInvoices = Invoice::where('company_id', $companyId)
            ->with('items', 'branch')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        $draftAging = Invoice::where('company_id', $companyId)
            ->where('status', 'draft')
            ->select(
                DB::raw("SUM(CASE WHEN created_at >= '" . now()->subDay()->toDateTimeString() . "' THEN 1 ELSE 0 END) as one_day"),
                DB::raw("SUM(CASE WHEN created_at BETWEEN '" . now()->subDays(3)->toDateTimeString() . "' AND '" . now()->subDay()->toDateTimeString() . "' THEN 1 ELSE 0 END) as three_days"),
                DB::raw("SUM(CASE WHEN created_at < '" . now()->subDays(7)->toDateTimeString() . "' THEN 1 ELSE 0 END) as seven_plus")
            )
            ->first();

        $draftAging = [
            '1_day' => $draftAging->one_day ?? 0,
            '3_days' => $draftAging->three_days ?? 0,
            '7_plus' => $draftAging->seven_plus ?? 0,
        ];

        $fbrStats = FbrLog::whereIn('invoice_id', function ($q) use ($companyId) {
                $q->select('id')->from('invoices')->where('company_id', $companyId);
            })
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count"),
                DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count")
            )
            ->first();

        $totalFbrLogs = $fbrStats->total;
        $fbrSuccessRate = $totalFbrLogs > 0 ? round(($fbrStats->success_count / $totalFbrLogs) * 100, 1) : 100;

        $complianceScore = $company->compliance_score ?? 100;

        $hybridResult = HybridComplianceScorer::scoreForCompany($companyId);
        $hybridScore = $hybridResult['final_score'];
        $riskLevel = $hybridResult['risk_level'];
        $riskBadge = HybridComplianceScorer::getRiskBadge($riskLevel);

        $complianceTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $monthReports = ComplianceReport::where('company_id', $companyId)
                ->whereMonth('created_at', $month->month)
                ->whereYear('created_at', $month->year)
                ->get();

            if ($monthReports->isNotEmpty()) {
                $complianceTrend[] = ['month' => $month->format('M'), 'score' => round($monthReports->avg('final_score'))];
            } else {
                $scoreRecord = ComplianceScore::where('company_id', $companyId)
                    ->whereMonth('calculated_date', $month->month)
                    ->whereYear('calculated_date', $month->year)
                    ->orderBy('calculated_date', 'desc')
                    ->first();
                $complianceTrend[] = [
                    'month' => $month->format('M'),
                    'score' => $scoreRecord ? $scoreRecord->score : 100,
                ];
            }
        }

        $recentActivity = InvoiceActivityLog::where('company_id', $companyId)
            ->with('user', 'invoice')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        $smartInsights = SmartInsightsService::getInsights($companyId);

        $notifications = Notification::where('company_id', $companyId)
            ->where('read', false)
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        $trialInfo = null;
        if ($subscription && $subscription->trial_ends_at) {
            $trialInfo = [
                'is_trial' => $subscription->isTrialActive(),
                'is_expired' => $subscription->isTrialExpired(),
                'days_left' => $subscription->trial_ends_at->isFuture() ? now()->diffInDays($subscription->trial_ends_at) : 0,
                'ends_at' => $subscription->trial_ends_at->format('M d, Y'),
            ];
        }

        $topCustomers = Invoice::where('company_id', $companyId)
            ->select('buyer_ntn', 'buyer_name', DB::raw('SUM(total_amount) as total_amount'), DB::raw('COUNT(*) as invoice_count'))
            ->groupBy('buyer_ntn', 'buyer_name')
            ->orderByDesc('total_amount')
            ->take(5)
            ->get();

        $branchComparison = Invoice::where('invoices.company_id', $companyId)
            ->leftJoin('branches', 'invoices.branch_id', '=', 'branches.id')
            ->select(
                DB::raw("COALESCE(branches.name, 'Unassigned') as branch_name"),
                DB::raw('COUNT(invoices.id) as invoice_count'),
                DB::raw('SUM(invoices.total_amount) as total_revenue')
            )
            ->groupBy('branches.name')
            ->orderByDesc('total_revenue')
            ->get();

        $compliancePercent = $totalInvoices > 0 ? round(($lockedCount / $totalInvoices) * 100, 1) : 0;
        $avgInvoiceValue = $totalInvoices > 0 ? round($totalRevenue / $totalInvoices, 2) : 0;
        $rejectionRate = $totalFbrLogs > 0 ? round(($fbrStats->failed_count / $totalFbrLogs) * 100, 1) : 0;

        $kpis = [
            'compliance_percent' => $compliancePercent,
            'avg_invoice_value' => $avgInvoiceValue,
            'rejection_rate' => $rejectionRate,
        ];

        return view('dashboard', compact(
            'company', 'totalInvoices', 'draftCount', 'submittedCount', 'lockedCount',
            'totalRevenue', 'subscription', 'invoiceLimit', 'invoicesUsed',
            'statusData', 'monthlyData', 'recentInvoices',
            'draftAging', 'fbrSuccessRate', 'complianceScore',
            'hybridScore', 'riskLevel', 'riskBadge',
            'complianceTrend', 'recentActivity', 'smartInsights',
            'notifications', 'trialInfo',
            'topCustomers', 'branchComparison', 'kpis', 'planTier'
        ));
    }

    public function executive()
    {
        $user = auth()->user();
        $companyId = app('currentCompanyId');
        $company = Company::find($companyId);

        $monthlyVolume = [];
        $failureRateTrend = [];
        $taxCollectedTrend = [];
        $riskTrend = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $monthStart = $month->copy()->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();

            $monthStats = Invoice::where('company_id', $companyId)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->select(
                    DB::raw('COUNT(*) as count'),
                    DB::raw('COALESCE(SUM(total_amount), 0) as revenue'),
                    DB::raw('COALESCE(SUM(total_sales_tax), 0) as tax_collected')
                )
                ->first();

            $invoiceIds = Invoice::where('company_id', $companyId)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->pluck('id');

            $totalLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->count();
            $failedLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->where('status', 'failed')->count();
            $failRate = $totalLogs > 0 ? round(($failedLogs / $totalLogs) * 100, 1) : 0;

            $monthReport = ComplianceReport::where('company_id', $companyId)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->avg('final_score');

            $monthlyVolume[] = ['month' => $month->format('M'), 'count' => $monthStats->count, 'revenue' => round($monthStats->revenue)];
            $failureRateTrend[] = ['month' => $month->format('M'), 'rate' => $failRate];
            $taxCollectedTrend[] = ['month' => $month->format('M'), 'amount' => round($monthStats->tax_collected)];
            $riskTrend[] = ['month' => $month->format('M'), 'score' => round($monthReport ?? 100)];
        }

        $auditTrend = AuditProbabilityEngine::getTrend($companyId);
        $auditEngine = AuditProbabilityEngine::calculate($companyId);

        $topCustomers = Invoice::where('company_id', $companyId)
            ->select('buyer_ntn', 'buyer_name', DB::raw('SUM(total_amount) as total_amount'), DB::raw('COUNT(*) as invoice_count'))
            ->groupBy('buyer_ntn', 'buyer_name')
            ->orderByDesc('total_amount')
            ->take(5)
            ->get();

        $totalInvoices = Invoice::where('company_id', $companyId)->count();
        $totalRevenue = Invoice::where('company_id', $companyId)->sum('total_amount');
        $lockedCount = Invoice::where('company_id', $companyId)->where('status', 'locked')->count();

        return view('executive-dashboard', compact(
            'company', 'monthlyVolume', 'failureRateTrend', 'taxCollectedTrend',
            'riskTrend', 'auditTrend', 'auditEngine', 'topCustomers',
            'totalInvoices', 'totalRevenue', 'lockedCount'
        ));
    }

    public function riskHeatmap()
    {
        $companyId = app('currentCompanyId');
        return response()->json(self::buildRiskHeatmap($companyId));
    }

    private static function buildRiskHeatmap(int $companyId): array
    {
        $invoiceIds = Invoice::where('company_id', $companyId)->pluck('id');

        $hsCategories = InvoiceItem::whereIn('invoice_id', $invoiceIds)
            ->select(
                DB::raw("SUBSTRING(hs_code, 1, 2) as hs_prefix"),
                DB::raw('COUNT(*) as total_items'),
                DB::raw("SUM(CASE WHEN schedule_type IN ('reduced', '3rd_schedule') THEN 1 ELSE 0 END) as reduced_items"),
                DB::raw('SUM(CAST(tax_rate AS FLOAT)) as total_tax_rate'),
                DB::raw('AVG(CAST(tax_rate AS FLOAT)) as avg_tax_rate')
            )
            ->groupBy('hs_prefix')
            ->orderByDesc('total_items')
            ->take(10)
            ->get()
            ->map(function ($row) {
                $reducedPct = $row->total_items > 0 ? round(($row->reduced_items / $row->total_items) * 100, 1) : 0;
                $riskPct = min(100, $reducedPct * 1.5);
                return [
                    'label' => 'HS ' . ($row->hs_prefix ?: 'N/A'),
                    'total' => $row->total_items,
                    'reduced_pct' => $reducedPct,
                    'risk_pct' => round($riskPct, 1),
                    'avg_rate' => round($row->avg_tax_rate ?? 0, 1),
                ];
            });

        $branchData = Invoice::where('invoices.company_id', $companyId)
            ->leftJoin('branches', 'invoices.branch_id', '=', 'branches.id')
            ->select(
                DB::raw("COALESCE(branches.name, 'Main') as branch_name"),
                DB::raw('COUNT(invoices.id) as total_invoices'),
                DB::raw('SUM(invoices.total_amount) as total_revenue')
            )
            ->groupBy('branches.name')
            ->get();

        $branchHeatmap = [];
        foreach ($branchData as $branch) {
            $branchInvoiceIds = Invoice::where('company_id', $companyId)
                ->when($branch->branch_name !== 'Main', function ($q) use ($branch, $companyId) {
                    $branchId = \App\Models\Branch::where('company_id', $companyId)->where('name', $branch->branch_name)->value('id');
                    return $q->where('branch_id', $branchId);
                }, function ($q) {
                    return $q->whereNull('branch_id');
                })
                ->pluck('id');

            $totalLogs = FbrLog::whereIn('invoice_id', $branchInvoiceIds)->count();
            $failedLogs = FbrLog::whereIn('invoice_id', $branchInvoiceIds)->where('status', 'failed')->count();
            $failPct = $totalLogs > 0 ? round(($failedLogs / $totalLogs) * 100, 1) : 0;

            $reducedItems = InvoiceItem::whereIn('invoice_id', $branchInvoiceIds)
                ->whereIn('schedule_type', ['reduced', '3rd_schedule'])
                ->count();
            $totalItems = InvoiceItem::whereIn('invoice_id', $branchInvoiceIds)->count();
            $reducedPct = $totalItems > 0 ? round(($reducedItems / $totalItems) * 100, 1) : 0;

            $riskPct = min(100, ($failPct * 2) + ($reducedPct * 0.5));

            $branchHeatmap[] = [
                'label' => $branch->branch_name,
                'total_invoices' => $branch->total_invoices,
                'risk_pct' => round($riskPct, 1),
                'failure_pct' => $failPct,
                'reduced_pct' => $reducedPct,
            ];
        }

        return [
            'hs_categories' => $hsCategories,
            'branches' => $branchHeatmap,
        ];
    }
}
