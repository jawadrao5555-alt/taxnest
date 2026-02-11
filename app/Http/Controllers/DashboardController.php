<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Invoice;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\InvoiceActivityLog;
use App\Models\FbrLog;
use App\Services\ComplianceScoreService;
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
        $complianceBadge = ComplianceScoreService::getBadge($complianceScore);

        $complianceTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $monthInvoices = Invoice::where('company_id', $companyId)
                ->whereYear('created_at', $month->year)
                ->whereMonth('created_at', $month->month)
                ->count();
            $monthLocked = Invoice::where('company_id', $companyId)
                ->where('status', 'locked')
                ->whereYear('created_at', $month->year)
                ->whereMonth('created_at', $month->month)
                ->count();
            $complianceTrend[] = [
                'month' => $month->format('M'),
                'score' => $monthInvoices > 0 ? round(($monthLocked / $monthInvoices) * 100) : 100,
            ];
        }

        $recentActivity = InvoiceActivityLog::where('company_id', $companyId)
            ->with('user', 'invoice')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        return view('dashboard', compact(
            'company', 'totalInvoices', 'draftCount', 'submittedCount', 'lockedCount',
            'totalRevenue', 'subscription', 'invoiceLimit', 'invoicesUsed',
            'statusData', 'monthlyData', 'recentInvoices',
            'draftAging', 'fbrSuccessRate', 'complianceScore', 'complianceBadge',
            'complianceTrend', 'recentActivity'
        ));
    }
}
