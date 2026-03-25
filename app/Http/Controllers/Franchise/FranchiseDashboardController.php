<?php

namespace App\Http\Controllers\Franchise;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\PosTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FranchiseDashboardController extends Controller
{
    private function franchiseId()
    {
        return auth('franchise')->id();
    }

    public function dashboard()
    {
        $franchiseId = $this->franchiseId();
        $companyIds = Company::where('franchise_id', $franchiseId)->pluck('id');

        $stats = [
            'total_companies' => $companyIds->count(),
            'active_subscriptions' => Subscription::whereIn('company_id', $companyIds)->where('active', true)->count(),
            'total_revenue' => PosTransaction::whereIn('company_id', $companyIds)->where('status', 'completed')->sum('total_amount'),
            'today_transactions' => PosTransaction::whereIn('company_id', $companyIds)->where('status', 'completed')->whereDate('created_at', today())->count(),
        ];

        $recentCompanies = Company::where('franchise_id', $franchiseId)->orderBy('created_at', 'desc')->take(5)->get();

        return view('franchise.dashboard', compact('stats', 'recentCompanies'));
    }

    public function companies()
    {
        $companies = Company::where('franchise_id', $this->franchiseId())
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('franchise.companies', compact('companies'));
    }

    public function subscriptions()
    {
        $companyIds = Company::where('franchise_id', $this->franchiseId())->pluck('id');

        $subscriptions = Subscription::whereIn('company_id', $companyIds)
            ->with(['company', 'pricingPlan'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('franchise.subscriptions', compact('subscriptions'));
    }

    public function revenue(Request $request)
    {
        $companyIds = Company::where('franchise_id', $this->franchiseId())->pluck('id');

        $monthlyRevenue = PosTransaction::whereIn('company_id', $companyIds)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subMonths(12))
            ->selectRaw(\App\Helpers\DbCompat::dateFormat('created_at', 'YYYY-MM') . " as month, SUM(total_amount) as revenue, COUNT(*) as count")
            ->groupByRaw(\App\Helpers\DbCompat::dateFormat('created_at', 'YYYY-MM'))
            ->orderBy('month')
            ->get();

        $totalRevenue = PosTransaction::whereIn('company_id', $companyIds)
            ->where('status', 'completed')
            ->sum('total_amount');

        $commissionRate = auth('franchise')->user()->commission_rate;
        $totalCommission = round($totalRevenue * $commissionRate / 100, 2);

        return view('franchise.revenue', compact('monthlyRevenue', 'totalRevenue', 'commissionRate', 'totalCommission'));
    }
}
