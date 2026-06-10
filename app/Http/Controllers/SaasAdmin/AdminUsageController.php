<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUsageStat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AdminUsageController extends Controller
{
    public function index()
    {
        // Self-heal guard: on a drifted production schema the usage table may be
        // absent — show an empty page instead of a 500.
        if (!Schema::hasTable('company_usage_stats')) {
            return view('saas-admin.company-usage', ['usageStats' => collect()]);
        }

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
