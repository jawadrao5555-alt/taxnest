<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyUsageStat;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminUsageController extends Controller
{
    public function index(?Request $request = null)
    {
        $request ??= request();
        $filters = $request->validate([
            'company_id' => 'nullable|integer|min:1',
            'branch_id' => 'nullable|integer|min:0',
            'period' => 'nullable|in:custom,daily,monthly,yearly,two_year',
            'date_from' => 'exclude_unless:period,custom,null|nullable|date_format:Y-m-d',
            'date_to' => 'exclude_unless:period,custom,null|nullable|date_format:Y-m-d|after_or_equal:date_from',
        ]);
        if (isset($filters['branch_id']) && !isset($filters['company_id'])) {
            throw ValidationException::withMessages(['branch_id' => 'Choose a company before applying a campus filter.']);
        }

        $today = today()->toImmutable();
        $period = $filters['period'] ?? 'custom';
        $from = match ($period) {
            'daily' => $today,
            'monthly' => $today->startOfMonth(),
            'yearly', 'two_year' => $today->startOfYear(),
            default => null,
        };
        if ($from) {
            $filters['date_from'] = $from->toDateString();
            $filters['date_to'] = $today->toDateString();
        }
        if ($period === 'two_year') {
            $lastYear = $today->subYearNoOverflow();
            $filters['comparison'] = [
                'date_from' => $lastYear->startOfYear()->toDateString(),
                'date_to' => $lastYear->toDateString(),
            ];
        }
        $companies = Company::when(isset($filters['company_id']), fn($q)=>$q->whereKey($filters['company_id']))->orderBy('name')->orderBy('id')->paginate(50)->withQueryString();
        $usageStats = CompanyUsageStat::snapshotsForCompanies($companies->getCollection(), $filters);

        return view('saas-admin.company-usage', compact('usageStats', 'companies', 'filters'));
    }
}
