<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUsageStat;

class AdminUsageController extends Controller
{
    public function index(?\Illuminate\Http\Request $request = null)
    {
        $request ??= request();
        $filters=$request->validate(['company_id'=>'nullable|integer|min:1','branch_id'=>'nullable|integer|min:0','date_from'=>'nullable|date_format:Y-m-d','date_to'=>'nullable|date_format:Y-m-d|after_or_equal:date_from']);
        if (isset($filters['branch_id']) && !isset($filters['company_id'])) throw \Illuminate\Validation\ValidationException::withMessages(['branch_id'=>'Choose a company before applying a campus filter.']);
        $companies = Company::when(isset($filters['company_id']), fn($q)=>$q->whereKey($filters['company_id']))->orderBy('name')->orderBy('id')->paginate(50)->withQueryString();
        $usageStats = CompanyUsageStat::snapshotsForCompanies($companies->getCollection(), $filters);

        return view('saas-admin.company-usage', compact('usageStats', 'companies', 'filters'));
    }
}
