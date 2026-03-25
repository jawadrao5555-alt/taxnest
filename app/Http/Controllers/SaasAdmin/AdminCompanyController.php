<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\AdminAuditLog;
use App\Models\CompanyUsageStat;
use App\Models\Invoice;
use App\Models\PosTransaction;
use App\Models\User;
use App\Models\Subscription;
use Illuminate\Http\Request;

class AdminCompanyController extends Controller
{
    public function index(Request $request)
    {
        $query = Company::query()->with(['franchise', 'activeSubscription']);

        if ($request->filled('product_type')) {
            $query->where('product_type', $request->product_type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $like = \App\Helpers\DbCompat::like();
            $query->where(function ($q) use ($search, $like) {
                $q->where('name', $like, "%{$search}%")
                  ->orWhere('ntn', $like, "%{$search}%")
                  ->orWhere('owner_name', $like, "%{$search}%");
            });
        }

        $companies = $query->orderBy('created_at', 'desc')->paginate(20)->appends($request->all());
        return view('saas-admin.companies.index', compact('companies'));
    }

    public function show($id)
    {
        $company = Company::withTrashed()->with(['franchise', 'activeSubscription'])->findOrFail($id);
        $usageStats = CompanyUsageStat::refreshForCompany($id);

        $extraStats = [];
        if ($company->product_type === 'di') {
            $extraStats['total_invoices'] = Invoice::where('company_id', $id)->count();
            $extraStats['locked_invoices'] = Invoice::where('company_id', $id)->where('fbr_status', 'locked')->count();
            $extraStats['total_revenue'] = Invoice::where('company_id', $id)->where('fbr_status', 'locked')->sum('total_amount');
            $extraStats['draft_invoices'] = Invoice::where('company_id', $id)->where('fbr_status', 'draft')->count();
        } else {
            $extraStats['total_transactions'] = PosTransaction::where('company_id', $id)->where('status', 'completed')->count();
            $extraStats['total_revenue'] = PosTransaction::where('company_id', $id)->where('status', 'completed')->sum('total_amount');
            $extraStats['today_transactions'] = PosTransaction::where('company_id', $id)->where('status', 'completed')->whereDate('created_at', today())->count();
        }

        $extraStats['total_users'] = User::where('company_id', $id)->count();
        $extraStats['active_subscription'] = Subscription::where('company_id', $id)->where('active', true)->with('pricingPlan')->first();

        return view('saas-admin.companies.show', compact('company', 'usageStats', 'extraStats'));
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
        $company->update(['status' => 'suspended', 'suspended_at' => now()]);
        AdminAuditLog::log(auth('admin')->id(), 'Company suspended', 'Company', $id, ['name' => $company->name]);
        return back()->with('success', "Company '{$company->name}' has been suspended.");
    }

    public function activate($id)
    {
        $company = Company::findOrFail($id);
        $company->update(['status' => 'approved', 'suspended_at' => null]);
        AdminAuditLog::log(auth('admin')->id(), 'Company activated', 'Company', $id, ['name' => $company->name]);
        return back()->with('success', "Company '{$company->name}' has been activated.");
    }

    public function updateLimits(Request $request, $id)
    {
        $company = Company::findOrFail($id);
        $request->validate([
            'invoice_limit_override' => 'nullable|integer|min:0',
            'user_limit_override' => 'nullable|integer|min:0',
            'branch_limit_override' => 'nullable|integer|min:0',
        ]);

        $company->update($request->only(['invoice_limit_override', 'user_limit_override', 'branch_limit_override']));
        AdminAuditLog::log(auth('admin')->id(), 'Company limits updated', 'Company', $id, [
            'name' => $company->name,
            'invoice_limit' => $request->invoice_limit_override,
            'user_limit' => $request->user_limit_override,
            'branch_limit' => $request->branch_limit_override,
        ]);
        return back()->with('success', "Limits updated for '{$company->name}'.");
    }

    public function softDelete(Request $request, $id)
    {
        $company = Company::findOrFail($id);
        $company->update(['deleted_reason' => $request->input('reason', 'Moved to bin by admin')]);
        $company->delete();
        AdminAuditLog::log(auth('admin')->id(), 'Company moved to bin', 'Company', $id, ['name' => $company->name, 'reason' => $request->input('reason')]);
        return redirect()->route('saas.admin.companies')->with('success', "Company '{$company->name}' moved to bin.");
    }

    public function bin(Request $request)
    {
        $query = Company::onlyTrashed()->with('franchise');

        if ($request->filled('search')) {
            $search = $request->search;
            $like = \App\Helpers\DbCompat::like();
            $query->where(function ($q) use ($search, $like) {
                $q->where('name', $like, "%{$search}%")
                  ->orWhere('ntn', $like, "%{$search}%");
            });
        }

        $companies = $query->orderBy('deleted_at', 'desc')->paginate(20)->appends($request->all());
        return view('saas-admin.companies.bin', compact('companies'));
    }

    public function restore($id)
    {
        $company = Company::onlyTrashed()->findOrFail($id);
        $company->restore();
        $company->update(['deleted_reason' => null]);
        AdminAuditLog::log(auth('admin')->id(), 'Company restored from bin', 'Company', $id, ['name' => $company->name]);
        return back()->with('success', "Company '{$company->name}' has been restored.");
    }

    public function forceDelete($id)
    {
        $company = Company::onlyTrashed()->findOrFail($id);
        $companyName = $company->name;
        $company->forceDelete();
        AdminAuditLog::log(auth('admin')->id(), 'Company permanently deleted', 'Company', $id, ['name' => $companyName]);
        return redirect()->route('saas.admin.companies.bin')->with('success', "Company '{$companyName}' has been permanently deleted.");
    }

    public function changeProductType(Request $request, $id)
    {
        $company = Company::findOrFail($id);
        $request->validate(['product_type' => 'required|in:di,pos']);
        $old = $company->product_type;
        $company->update(['product_type' => $request->product_type]);
        AdminAuditLog::log(auth('admin')->id(), 'Company type changed', 'Company', $id, [
            'name' => $company->name, 'from' => $old, 'to' => $request->product_type
        ]);
        return back()->with('success', "Company type changed to " . strtoupper($request->product_type) . ".");
    }
}
