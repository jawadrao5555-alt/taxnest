<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\AdminAuditLog;
use App\Models\CompanyUsageStat;
use Illuminate\Http\Request;

class AdminCompanyController extends Controller
{
    public function index(Request $request)
    {
        $query = Company::query()->with('franchise');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('ntn', 'ilike', "%{$search}%");
            });
        }

        $companies = $query->orderBy('created_at', 'desc')->paginate(20)->appends($request->all());
        return view('saas-admin.companies.index', compact('companies'));
    }

    public function show($id)
    {
        $company = Company::with(['franchise'])->findOrFail($id);
        $usageStats = CompanyUsageStat::refreshForCompany($id);
        return view('saas-admin.companies.show', compact('company', 'usageStats'));
    }

    public function approve($id)
    {
        $company = Company::findOrFail($id);
        $company->update(['status' => 'approved']);
        AdminAuditLog::log(auth('admin')->id(), 'Company approved', 'Company', $id, ['name' => $company->name]);
        return back()->with('success', "Company '{$company->name}' has been approved.");
    }

    public function reject($id)
    {
        $company = Company::findOrFail($id);
        $company->update(['status' => 'rejected']);
        AdminAuditLog::log(auth('admin')->id(), 'Company rejected', 'Company', $id, ['name' => $company->name]);
        return back()->with('success', "Company '{$company->name}' has been rejected.");
    }

    public function suspend($id)
    {
        $company = Company::findOrFail($id);
        $company->update(['status' => 'suspended']);
        AdminAuditLog::log(auth('admin')->id(), 'Company suspended', 'Company', $id, ['name' => $company->name]);
        return back()->with('success', "Company '{$company->name}' has been suspended.");
    }

    public function activate($id)
    {
        $company = Company::findOrFail($id);
        $company->update(['status' => 'approved']);
        AdminAuditLog::log(auth('admin')->id(), 'Company activated', 'Company', $id, ['name' => $company->name]);
        return back()->with('success', "Company '{$company->name}' has been activated.");
    }
}
