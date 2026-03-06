<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUsageStat;
use Illuminate\Http\Request;

class AdminUsageController extends Controller
{
    public function index()
    {
        $companies = Company::all();
        foreach ($companies as $company) {
            CompanyUsageStat::refreshForCompany($company->id);
        }

        $usageStats = CompanyUsageStat::with('company')
            ->orderBy('total_pos_transactions', 'desc')
            ->get();

        return view('saas-admin.company-usage', compact('usageStats'));
    }
}
