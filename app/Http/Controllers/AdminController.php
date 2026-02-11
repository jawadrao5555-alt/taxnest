<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\FbrLog;
use App\Models\User;
use App\Models\Subscription;
use App\Models\PricingPlan;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    public function dashboard()
    {
        $totalCompanies = Company::count();
        $totalUsers = User::count();
        $totalInvoices = Invoice::count();
        $draftInvoices = Invoice::where('status', 'draft')->count();
        $submittedInvoices = Invoice::where('status', 'submitted')->count();
        $lockedInvoices = Invoice::where('status', 'locked')->count();
        $failedLogs = FbrLog::where('status', 'failed')->count();
        $totalRevenue = Invoice::sum('total_amount');
        $activeSubscriptions = Subscription::where('active', true)->count();

        $recentInvoices = Invoice::with('company')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        $recentCompanies = Company::withCount('invoices', 'users')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        return view('admin.dashboard', compact(
            'totalCompanies', 'totalUsers', 'totalInvoices',
            'draftInvoices', 'submittedInvoices', 'lockedInvoices',
            'failedLogs', 'totalRevenue', 'activeSubscriptions',
            'recentInvoices', 'recentCompanies'
        ));
    }

    public function companies()
    {
        $companies = Company::withCount('invoices', 'users')->paginate(15);
        return view('admin.companies', compact('companies'));
    }

    public function createCompany()
    {
        return view('admin.company-create');
    }

    public function storeCompany(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'ntn' => 'required|string|max:50|unique:companies,ntn',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
        ]);

        $company = Company::create($request->only(['name', 'ntn', 'email', 'phone', 'address']));

        $freePlan = PricingPlan::orderBy('price')->first();
        if ($freePlan) {
            Subscription::create([
                'company_id' => $company->id,
                'pricing_plan_id' => $freePlan->id,
                'start_date' => now(),
                'end_date' => now()->addMonth(),
                'active' => true,
            ]);
        }

        return redirect('/admin/companies')->with('success', 'Company created successfully.');
    }

    public function users()
    {
        $users = User::with('company')->paginate(15);
        $companies = Company::all();
        return view('admin.users', compact('users', 'companies'));
    }

    public function storeUser(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => 'required|in:super_admin,company_admin,employee,viewer',
            'company_id' => 'nullable|exists:companies,id',
        ]);

        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'company_id' => $request->company_id,
        ]);

        return redirect('/admin/users')->with('success', 'User created successfully.');
    }

    public function fbrLogs()
    {
        $logs = FbrLog::with('invoice.company')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('admin.fbr-logs', compact('logs'));
    }
}
