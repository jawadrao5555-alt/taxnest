<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Invoice;
use App\Models\Company;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

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

        return view('dashboard', compact(
            'company', 'totalInvoices', 'draftCount', 'submittedCount', 'lockedCount',
            'totalRevenue', 'subscription', 'invoiceLimit', 'invoicesUsed',
            'statusData', 'monthlyData', 'recentInvoices'
        ));
    }
}
