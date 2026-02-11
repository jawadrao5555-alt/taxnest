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
use App\Services\SmartInsightsService;
use App\Services\HybridComplianceScorer;
use App\Services\AuditDefenseService;
use App\Services\VendorRiskEngine;
use Illuminate\Support\Facades\DB;
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

        $invoices = Invoice::where('company_id', $companyId)
            ->orderBy('created_at', 'desc')
            ->get();

        $totalInvoices = $invoices->count();
        $draftCount = $invoices->where('status', 'draft')->count();
        $submittedCount = $invoices->where('status', 'submitted')->count();
        $lockedCount = $invoices->where('status', 'locked')->count();
        $totalRevenue = $invoices->sum('total_amount');

        $subscription = Subscription::where('company_id', $companyId)
            ->where('active', true)
            ->with('pricingPlan')
            ->first();

        $invoiceLimit = $subscription ? $subscription->pricingPlan->invoice_limit : 0;
        $invoicesUsed = $totalInvoices;

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

        $recentInvoices = $invoices->take(10);

        $draftAging = [
            '1_day' => Invoice::where('company_id', $companyId)->where('status', 'draft')->where('created_at', '>=', now()->subDay())->count(),
            '3_days' => Invoice::where('company_id', $companyId)->where('status', 'draft')->whereBetween('created_at', [now()->subDays(3), now()->subDay()])->count(),
            '7_plus' => Invoice::where('company_id', $companyId)->where('status', 'draft')->where('created_at', '<', now()->subDays(7))->count(),
        ];

        $invoiceIds = Invoice::where('company_id', $companyId)->pluck('id');
        $totalFbrLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->count();
        $successFbrLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->where('status', 'success')->count();
        $fbrSuccessRate = $totalFbrLogs > 0 ? round(($successFbrLogs / $totalFbrLogs) * 100, 1) : 100;

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

        $recentAnomalies = AnomalyLog::where('company_id', $companyId)
            ->where('resolved', false)
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        $notifications = Notification::where('company_id', $companyId)
            ->where('read', false)
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        $avgComplianceAllCompanies = Company::whereNotNull('compliance_score')->avg('compliance_score');
        $industryBenchmark = [
            'average' => round($avgComplianceAllCompanies ?? 100),
            'your_score' => $hybridScore,
            'above_average' => $hybridScore >= ($avgComplianceAllCompanies ?? 100),
        ];

        $trialInfo = null;
        if ($subscription && $subscription->trial_ends_at) {
            $trialInfo = [
                'is_trial' => $subscription->isTrialActive(),
                'is_expired' => $subscription->isTrialExpired(),
                'days_left' => $subscription->trial_ends_at->isFuture() ? now()->diffInDays($subscription->trial_ends_at) : 0,
                'ends_at' => $subscription->trial_ends_at->format('M d, Y'),
            ];
        }

        $vendorRisks = VendorRiskProfile::where('company_id', $companyId)
            ->orderBy('vendor_score', 'asc')
            ->take(5)
            ->get();

        $auditProbability = AuditDefenseService::calculateAuditProbability($companyId);

        $recentReports = ComplianceReport::where('company_id', $companyId)
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        $momGrowth = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $count = Invoice::where('company_id', $companyId)
                ->whereMonth('created_at', $month->month)
                ->whereYear('created_at', $month->year)
                ->count();
            $revenue = Invoice::where('company_id', $companyId)
                ->whereMonth('created_at', $month->month)
                ->whereYear('created_at', $month->year)
                ->sum('total_amount');
            $momGrowth[] = ['month' => $month->format('M'), 'count' => $count, 'revenue' => $revenue];
        }

        $taxVariance = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $invoiceIdsForTax = Invoice::where('company_id', $companyId)
                ->whereMonth('created_at', $month->month)
                ->whereYear('created_at', $month->year)
                ->pluck('id');
            $actualTax = InvoiceItem::whereIn('invoice_id', $invoiceIdsForTax)->sum('tax');
            $subtotal = InvoiceItem::whereIn('invoice_id', $invoiceIdsForTax)
                ->selectRaw('SUM(price * quantity) as s')->value('s') ?? 0;
            $expectedTax = $subtotal * 0.18;
            $taxVariance[] = [
                'month' => $month->format('M'),
                'actual' => round($actualTax),
                'expected' => round($expectedTax),
            ];
        }

        $hsRiskData = InvoiceItem::whereIn('invoice_id',
                Invoice::where('company_id', $companyId)->pluck('id'))
            ->select(
                DB::raw("SUBSTRING(hs_code, 1, 2) as hs_prefix"),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(tax) as total_tax'),
                DB::raw('SUM(price * quantity) as total_value')
            )
            ->groupBy('hs_prefix')
            ->orderByDesc('count')
            ->take(10)
            ->get();

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
        $failedFbrLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->where('status', 'failed')->count();
        $rejectionRate = $totalFbrLogs > 0 ? round(($failedFbrLogs / $totalFbrLogs) * 100, 1) : 0;

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
            'complianceTrend', 'recentActivity', 'smartInsights', 'recentAnomalies',
            'notifications', 'industryBenchmark', 'trialInfo',
            'vendorRisks', 'auditProbability', 'recentReports',
            'momGrowth', 'taxVariance', 'hsRiskData',
            'topCustomers', 'branchComparison', 'kpis'
        ));
    }
}
