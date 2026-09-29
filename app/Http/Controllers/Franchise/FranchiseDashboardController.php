<?php

namespace App\Http\Controllers\Franchise;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\FranchiseCommission;
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
        $stats = [
            'total_companies' => Company::where('franchise_id', $franchiseId)->count(),
            'pending_approvals' => Company::where('franchise_id', $franchiseId)
                ->where('status', 'pending')->where('company_status', 'pending')
                ->whereNotIn('id', DB::table('franchise_company_approvals')->select('company_id'))->count(),
            'commission_balance' => FranchiseCommission::where('franchise_id', $franchiseId)
                ->where('status', 'pending')->sum('amount'),
        ];

        $recentCompanies = Company::where('franchise_id', $franchiseId)->orderBy('created_at', 'desc')->take(5)->get();

        return view('franchise.dashboard', compact('stats', 'recentCompanies'));
    }

    public function companies()
    {
        $franchiseId = $this->franchiseId();
        $companies = Company::where('franchise_id', $this->franchiseId())
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $reviewedIds = DB::table('franchise_company_approvals')
            ->where('franchise_id', $franchiseId)
            ->whereIn('company_id', $companies->pluck('id'))
            ->pluck('company_id')->all();

        return view('franchise.companies', compact('companies', 'reviewedIds'));
    }

    public function revenue()
    {
        $query = FranchiseCommission::where('franchise_id', $this->franchiseId());
        $totals = [
            'earned' => (clone $query)->where('type', 'earned')->where('status', '!=', 'attribution_conflict')->sum('amount'),
            'balance' => (clone $query)->where('status', 'pending')->sum('amount'),
            'paid' => (clone $query)->where('status', 'paid')->sum('amount'),
        ];
        $commissions = $query->orderByDesc('earned_at')->orderByDesc('id')->paginate(20);

        return view('franchise.revenue', compact('commissions', 'totals'));
    }
}
