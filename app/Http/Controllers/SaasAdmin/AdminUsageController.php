<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUsageStat;

class AdminUsageController extends Controller
{
    public function index()
    {
        $companies = Company::orderBy('name')->orderBy('id')->paginate(50);
        $usageStats = CompanyUsageStat::snapshotsForCompanies($companies->getCollection());

        return view('saas-admin.company-usage', compact('usageStats', 'companies'));
    }
}
