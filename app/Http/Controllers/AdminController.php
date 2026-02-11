<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\FbrLog;
use App\Models\User;
use App\Models\Subscription;
use App\Models\PricingPlan;
use App\Models\SecurityLog;
use App\Models\ComplianceScore;
use App\Models\AnomalyLog;
use App\Services\SecurityLogService;
use App\Services\IntegrityHashService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Carbon\Carbon;

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

        $recentAnomalies = AnomalyLog::with('company')
            ->where('resolved', false)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        return view('admin.dashboard', compact(
            'totalCompanies', 'totalUsers', 'totalInvoices',
            'draftInvoices', 'submittedInvoices', 'lockedInvoices',
            'failedLogs', 'totalRevenue', 'activeSubscriptions',
            'recentInvoices', 'recentCompanies', 'recentAnomalies'
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
                'trial_ends_at' => now()->addDays(14),
                'active' => true,
            ]);
        }

        SecurityLogService::log('company_created', auth()->id(), ['company_id' => $company->id, 'name' => $company->name]);

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

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'company_id' => $request->company_id,
        ]);

        SecurityLogService::log('user_created', auth()->id(), ['new_user_id' => $user->id, 'role' => $request->role]);

        return redirect('/admin/users')->with('success', 'User created successfully.');
    }

    public function fbrLogs()
    {
        $logs = FbrLog::with('invoice.company')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('admin.fbr-logs', compact('logs'));
    }

    public function systemHealth()
    {
        $pendingJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();

        $avgResponseTime = FbrLog::whereNotNull('response_time_ms')
            ->where('created_at', '>=', now()->subDays(30))
            ->avg('response_time_ms');

        $totalRetries24h = FbrLog::where('retry_count', '>', 0)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $fbrLogsToday = FbrLog::where('created_at', '>=', now()->subDay())->count();
        $fbrSuccessToday = FbrLog::where('status', 'success')->where('created_at', '>=', now()->subDay())->count();

        $failureBreakdown = FbrLog::where('status', 'failed')
            ->whereNotNull('failure_type')
            ->select('failure_type', DB::raw('count(*) as count'))
            ->groupBy('failure_type')
            ->get();

        $companiesAtRisk = Company::where('compliance_score', '<', 50)->count();
        $companiesModerate = Company::whereBetween('compliance_score', [50, 79])->count();
        $companiesSafe = Company::where('compliance_score', '>=', 80)->count();

        $recentSecurityLogs = SecurityLog::with('user')
            ->orderBy('created_at', 'desc')
            ->take(20)
            ->get();

        $healthScore = 100;
        if ($failedJobs > 10) $healthScore -= 20;
        elseif ($failedJobs > 0) $healthScore -= 5;
        if ($pendingJobs > 50) $healthScore -= 15;
        elseif ($pendingJobs > 10) $healthScore -= 5;
        if ($avgResponseTime && $avgResponseTime > 5000) $healthScore -= 15;
        if ($totalRetries24h > 20) $healthScore -= 10;
        $healthScore = max(0, $healthScore);

        $recentAnomalies = AnomalyLog::with('company')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        return view('admin.system-health', compact(
            'pendingJobs', 'failedJobs', 'avgResponseTime', 'totalRetries24h',
            'fbrLogsToday', 'fbrSuccessToday', 'failureBreakdown',
            'companiesAtRisk', 'companiesModerate', 'companiesSafe',
            'recentSecurityLogs', 'healthScore', 'recentAnomalies'
        ));
    }

    public function securityLogs()
    {
        $logs = SecurityLog::with('user')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('admin.security-logs', compact('logs'));
    }

    public function auditExport()
    {
        $invoices = Invoice::with('items', 'company')->orderBy('id')->get();

        $csvContent = "Invoice Number,Company,Buyer Name,Buyer NTN,Total Amount,Tax Amount,Status,Created At,SHA256 Signature\n";

        foreach ($invoices as $invoice) {
            $taxAmount = $invoice->items->sum('tax');
            $hashData = implode('|', [
                $invoice->invoice_number,
                $invoice->total_amount,
                $taxAmount,
                $invoice->company_id,
                $invoice->created_at->toIso8601String(),
            ]);
            $sha256 = hash('sha256', $hashData);

            $csvContent .= implode(',', [
                '"' . ($invoice->invoice_number ?? '') . '"',
                '"' . ($invoice->company->name ?? '') . '"',
                '"' . $invoice->buyer_name . '"',
                '"' . $invoice->buyer_ntn . '"',
                $invoice->total_amount,
                $taxAmount,
                $invoice->status,
                '"' . $invoice->created_at->toIso8601String() . '"',
                $sha256,
            ]) . "\n";
        }

        return Response::make($csvContent, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="taxnest_audit_export_' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    public function anomalies()
    {
        $anomalies = AnomalyLog::with('company')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('admin.anomalies', compact('anomalies'));
    }

    public function riskSettings()
    {
        $settings = [
            'mom_spike_threshold' => \App\Models\SystemSetting::get('mom_spike_threshold', '200'),
            'tax_drop_threshold' => \App\Models\SystemSetting::get('tax_drop_threshold', '60'),
            'critical_score_threshold' => \App\Models\SystemSetting::get('critical_score_threshold', '40'),
            'stability_bonus_weight' => \App\Models\SystemSetting::get('stability_bonus_weight', '10'),
        ];
        return view('admin.risk-settings', compact('settings'));
    }

    public function updateRiskSettings(Request $request)
    {
        $request->validate([
            'mom_spike_threshold' => 'required|numeric|min:50|max:1000',
            'tax_drop_threshold' => 'required|numeric|min:10|max:100',
            'critical_score_threshold' => 'required|numeric|min:10|max:90',
            'stability_bonus_weight' => 'required|numeric|min:0|max:30',
        ]);

        \App\Models\SystemSetting::set('mom_spike_threshold', $request->mom_spike_threshold);
        \App\Models\SystemSetting::set('tax_drop_threshold', $request->tax_drop_threshold);
        \App\Models\SystemSetting::set('critical_score_threshold', $request->critical_score_threshold);
        \App\Models\SystemSetting::set('stability_bonus_weight', $request->stability_bonus_weight);

        \App\Services\SecurityLogService::log('risk_settings_updated', auth()->id(), [
            'settings' => $request->only(['mom_spike_threshold', 'tax_drop_threshold', 'critical_score_threshold', 'stability_bonus_weight']),
        ]);

        return redirect('/admin/risk-settings')->with('success', 'Risk settings updated successfully.');
    }

    public function overrideLogs()
    {
        $logs = \App\Models\OverrideLog::with('invoice', 'user', 'company')
            ->orderBy('created_at', 'desc')
            ->paginate(20);
        return view('admin.override-logs', compact('logs'));
    }
}
