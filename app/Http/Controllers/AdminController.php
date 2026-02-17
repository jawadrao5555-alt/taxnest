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
use App\Services\AuditLogService;
use App\Services\IntegrityHashService;
use App\Services\HsIntelligenceService;
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
        $submittedInvoices = Invoice::where('is_fbr_processing', true)->count();
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

        $pendingCompanies = Company::where('company_status', 'pending')->count();

        $overrideStats = [
            'sector_rules' => \App\Models\SectorTaxRule::where('is_active', true)->count(),
            'province_rules' => \App\Models\ProvinceTaxRule::where('is_active', true)->count(),
            'customer_rules' => \App\Models\CustomerTaxRule::where('is_active', true)->count(),
            'sro_rules' => \App\Models\SpecialSroRule::where('is_active', true)->count(),
            'total_overrides_applied' => \App\Models\OverrideUsageLog::count(),
            'overrides_this_month' => \App\Models\OverrideUsageLog::where('created_at', '>=', now()->startOfMonth())->count(),
        ];

        $topRejectedHsCodes = HsIntelligenceService::getTopRejectedHsCodes(30, 10);
        $totalHsMaster = \App\Models\HsMasterGlobal::count();
        $totalUnmapped = DB::table('hs_unmapped_queue')->count();

        $atRiskCompanies = Company::whereNotNull('compliance_score')
            ->orderBy('compliance_score', 'asc')
            ->take(5)
            ->get();

        $platformAuditStats = [
            'total_anomalies' => \App\Models\AnomalyLog::where('resolved', false)->count(),
            'high_risk_companies' => Company::whereNotNull('compliance_score')->where('compliance_score', '<', 50)->count(),
            'avg_compliance' => round(Company::whereNotNull('compliance_score')->avg('compliance_score') ?? 100),
            'total_vendor_risks' => \App\Models\VendorRiskProfile::where('vendor_score', '<', 40)->count(),
        ];

        $companyScores = Company::whereNotNull('compliance_score')
            ->select('name', 'compliance_score', 'ntn')
            ->orderBy('compliance_score', 'desc')
            ->take(10)
            ->get();

        $topCompanies = Company::withCount('invoices')
            ->select('companies.*')
            ->selectRaw('(SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE invoices.company_id = companies.id) as company_revenue')
            ->orderByDesc('company_revenue')
            ->take(5)
            ->get();

        $monthlyRevenue = Invoice::selectRaw("TO_CHAR(created_at, 'YYYY-MM') as month, SUM(total_amount) as revenue, COUNT(*) as count")
            ->where('created_at', '>=', now()->subMonths(6)->startOfMonth())
            ->groupByRaw("TO_CHAR(created_at, 'YYYY-MM')")
            ->orderBy('month')
            ->get();

        $expiringTrials = Subscription::with('company')
            ->where('active', true)
            ->where('ends_at', '<=', now()->addDays(7))
            ->where('ends_at', '>', now())
            ->orderBy('ends_at')
            ->take(10)
            ->get();

        $activityFeed = \App\Models\AuditLog::with('user')
            ->orderBy('created_at', 'desc')
            ->take(15)
            ->get();

        $todayInvoices = Invoice::where('created_at', '>=', now()->startOfDay())->count();
        $todayRevenue = Invoice::where('created_at', '>=', now()->startOfDay())->sum('total_amount');
        $newCompaniesThisMonth = Company::where('created_at', '>=', now()->startOfMonth())->count();

        return view('admin.dashboard', compact(
            'totalCompanies', 'totalUsers', 'totalInvoices',
            'draftInvoices', 'submittedInvoices', 'lockedInvoices',
            'failedLogs', 'totalRevenue', 'activeSubscriptions',
            'recentInvoices', 'recentCompanies', 'recentAnomalies',
            'pendingCompanies', 'overrideStats',
            'topRejectedHsCodes', 'totalHsMaster', 'totalUnmapped',
            'atRiskCompanies', 'platformAuditStats', 'companyScores',
            'topCompanies', 'monthlyRevenue', 'expiringTrials',
            'activityFeed', 'todayInvoices', 'todayRevenue', 'newCompaniesThisMonth'
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
            'owner_name' => 'nullable|string|max:255',
            'ntn' => 'required|string|max:50|unique:companies,ntn',
            'cnic' => 'nullable|string|max:20',
            'fbr_registration_no' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'business_activity' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'admin_name' => 'nullable|string|max:255',
            'admin_email' => 'nullable|email|unique:users,email',
            'admin_password' => 'nullable|string|min:6',
        ]);

        $company = Company::create(array_merge(
            $request->only(['name', 'owner_name', 'ntn', 'cnic', 'fbr_registration_no', 'email', 'phone', 'business_activity', 'address']),
            ['company_status' => 'active']
        ));

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

        if ($request->filled('admin_name') && $request->filled('admin_email') && $request->filled('admin_password')) {
            User::create([
                'name' => $request->admin_name,
                'email' => $request->admin_email,
                'password' => Hash::make($request->admin_password),
                'role' => 'company_admin',
                'company_id' => $company->id,
            ]);
        }

        SecurityLogService::log('company_created', auth()->id(), ['company_id' => $company->id, 'name' => $company->name]);

        return redirect('/admin/companies')->with('success', 'Company created successfully.');
    }

    public function suspendCompany(Company $company)
    {
        if ($company->company_status === 'suspended') {
            $company->update(['company_status' => 'active', 'suspended_at' => null]);
            $action = 'unsuspended';
        } else {
            $company->update(['company_status' => 'suspended', 'suspended_at' => now()]);
            $action = 'suspended';
        }

        SecurityLogService::log("company_{$action}", auth()->id(), ['company_id' => $company->id]);
        AuditLogService::log("company_{$action}", 'Company', $company->id, null, ['status' => $company->company_status]);

        return redirect('/admin/company/' . $company->id)->with('success', "Company {$action} successfully.");
    }

    public function pendingCompanies()
    {
        $companies = Company::where('company_status', 'pending')->withCount('invoices', 'users')->paginate(15);
        return view('admin.companies', compact('companies'));
    }

    public function approveCompany(Company $company)
    {
        $company->update(['company_status' => 'active']);
        SecurityLogService::log('company_approved', auth()->id(), ['company_id' => $company->id, 'name' => $company->name]);
        AuditLogService::log('company_approved', 'Company', $company->id, null, ['name' => $company->name]);
        return redirect('/admin/company/' . $company->id)->with('success', 'Company approved successfully.');
    }

    public function rejectCompany(Company $company)
    {
        $company->update(['company_status' => 'rejected']);
        SecurityLogService::log('company_rejected', auth()->id(), ['company_id' => $company->id, 'name' => $company->name]);
        AuditLogService::log('company_rejected', 'Company', $company->id, null, ['name' => $company->name]);
        return redirect('/admin/company/' . $company->id)->with('success', 'Company rejected successfully.');
    }

    public function changePlan(Request $request, Company $company)
    {
        $request->validate([
            'pricing_plan_id' => 'required|exists:pricing_plans,id',
        ]);

        Subscription::where('company_id', $company->id)->where('active', true)->update(['active' => false]);

        Subscription::create([
            'company_id' => $company->id,
            'pricing_plan_id' => $request->pricing_plan_id,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
            'active' => true,
        ]);

        SecurityLogService::log('company_plan_changed', auth()->id(), [
            'company_id' => $company->id,
            'new_plan_id' => $request->pricing_plan_id,
        ]);

        AuditLogService::log('company_plan_changed', 'Company', $company->id, null, [
            'new_plan_id' => $request->pricing_plan_id,
        ]);

        return redirect('/admin/company/' . $company->id)->with('success', 'Plan changed successfully.');
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

        $fbrMetrics30d = FbrLog::where('created_at', '>=', now()->subDays(30))
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count"),
                DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count"),
                DB::raw("SUM(CASE WHEN retry_count > 0 THEN 1 ELSE 0 END) as retried_count"),
                DB::raw('AVG(submission_latency_ms) as avg_latency'),
                DB::raw('MAX(submission_latency_ms) as max_latency'),
                DB::raw('MIN(CASE WHEN submission_latency_ms > 0 THEN submission_latency_ms END) as min_latency')
            )
            ->first();

        $fbrObservability = [
            'avg_submission_time' => $fbrMetrics30d->avg_latency ? round($fbrMetrics30d->avg_latency) : ($avgResponseTime ? round($avgResponseTime) : null),
            'max_submission_time' => $fbrMetrics30d->max_latency ? round($fbrMetrics30d->max_latency) : null,
            'min_submission_time' => $fbrMetrics30d->min_latency ? round($fbrMetrics30d->min_latency) : null,
            'total_submissions' => $fbrMetrics30d->total,
            'success_count' => $fbrMetrics30d->success_count,
            'failure_count' => $fbrMetrics30d->failed_count,
            'failure_ratio' => $fbrMetrics30d->total > 0 ? round(($fbrMetrics30d->failed_count / $fbrMetrics30d->total) * 100, 1) : 0,
            'retry_ratio' => $fbrMetrics30d->total > 0 ? round(($fbrMetrics30d->retried_count / $fbrMetrics30d->total) * 100, 1) : 0,
        ];

        $failureCategoryBreakdown = FbrLog::where('status', 'failed')
            ->whereNotNull('failure_category')
            ->where('created_at', '>=', now()->subDays(30))
            ->select('failure_category', DB::raw('count(*) as count'))
            ->groupBy('failure_category')
            ->orderByDesc('count')
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
            'recentSecurityLogs', 'healthScore', 'recentAnomalies',
            'fbrObservability', 'failureCategoryBreakdown'
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
        $auditLogs = \App\Models\AuditLog::with('company', 'user')->orderBy('id')->get();

        $csvContent = "ID,Date,Company,User,Action,Entity Type,Entity ID,New Values,SHA256 Hash\n";

        $runningHash = '';
        foreach ($auditLogs as $log) {
            $rowHash = $log->sha256_hash ?: hash('sha256', implode('|', [
                $log->id, $log->action, $log->entity_type, $log->entity_id,
                json_encode($log->new_values), $log->created_at?->toIso8601String(),
            ]));

            $csvContent .= implode(',', [
                $log->id,
                '"' . ($log->created_at?->toIso8601String() ?? '') . '"',
                '"' . ($log->company->name ?? 'System') . '"',
                '"' . ($log->user->name ?? 'System') . '"',
                '"' . $log->action . '"',
                '"' . ($log->entity_type ?? '') . '"',
                $log->entity_id ?? '',
                '"' . str_replace('"', '""', json_encode($log->new_values) ?? '') . '"',
                $rowHash,
            ]) . "\n";

            $runningHash = hash('sha256', $runningHash . $rowHash);
        }

        $csvContent .= "\n# Verification Hash: " . $runningHash . "\n";
        $csvContent .= "# Generated: " . now()->toIso8601String() . "\n";
        $csvContent .= "# Total Records: " . $auditLogs->count() . "\n";

        return Response::make($csvContent, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="taxnest_audit_export_' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    public function auditLogs(Request $request)
    {
        $query = \App\Models\AuditLog::with('company', 'user')->orderBy('created_at', 'desc');

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        $actionTypes = \App\Models\AuditLog::select('action')->distinct()->orderBy('action')->pluck('action');
        $logs = $query->paginate(25)->appends($request->query());

        return view('admin.audit-logs', compact('logs', 'actionTypes'));
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

        $taxOverrideUsage = \App\Models\OverrideUsageLog::with('company', 'invoice')
            ->orderBy('created_at', 'desc')
            ->take(50)
            ->get();

        $layerStats = \App\Models\OverrideUsageLog::select('override_layer', DB::raw('count(*) as count'))
            ->groupBy('override_layer')
            ->get()
            ->pluck('count', 'override_layer');

        return view('admin.override-logs', compact('logs', 'taxOverrideUsage', 'layerStats'));
    }

    public function companyShow(Company $company)
    {
        $invoiceIds = Invoice::where('company_id', $company->id)->pluck('id');

        $stats = [
            'total_users' => User::where('company_id', $company->id)->count(),
            'total_invoices' => Invoice::where('company_id', $company->id)->count(),
            'locked' => Invoice::where('company_id', $company->id)->where('status', 'locked')->count(),
            'draft' => Invoice::where('company_id', $company->id)->where('status', 'draft')->count(),
            'failed' => FbrLog::whereIn('invoice_id', $invoiceIds)->where('status', 'failed')->count(),
            'total_branches' => \App\Models\Branch::where('company_id', $company->id)->count(),
        ];

        $activePlan = null;
        $sub = Subscription::where('company_id', $company->id)->where('active', true)->first();
        if ($sub) {
            $plan = PricingPlan::find($sub->pricing_plan_id);
            $activePlan = $plan ? $plan->name : 'Unknown';
        }

        $users = User::where('company_id', $company->id)->get()->map(function ($user) {
            $user->user_invoice_count = \App\Models\InvoiceActivityLog::where('user_id', $user->id)->where('action', 'created')->count();
            return $user;
        });

        $totalInvoicedAmount = Invoice::where('company_id', $company->id)->sum('total_amount');

        $totalTaxCollected = \App\Models\InvoiceItem::whereIn('invoice_id', $invoiceIds)->sum('tax');

        $taxRateSummary = \App\Models\InvoiceItem::whereIn('invoice_id', $invoiceIds)
            ->whereNotNull('tax_rate')
            ->where('tax_rate', '>', 0)
            ->select('tax_rate', DB::raw('count(*) as count'), DB::raw('SUM(tax) as total_tax'))
            ->groupBy('tax_rate')
            ->get();

        $outstandingAmount = \App\Models\CustomerLedger::where('company_id', $company->id)
            ->select('customer_ntn', DB::raw('MAX(id) as max_id'))
            ->groupBy('customer_ntn')
            ->get()
            ->sum(function ($row) {
                $entry = \App\Models\CustomerLedger::find($row->max_id);
                return $entry ? $entry->balance_after : 0;
            });

        $monthlyRevenue = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $revenue = Invoice::where('company_id', $company->id)
                ->whereMonth('created_at', $month->month)
                ->whereYear('created_at', $month->year)
                ->sum('total_amount');
            $count = Invoice::where('company_id', $company->id)
                ->whereMonth('created_at', $month->month)
                ->whereYear('created_at', $month->year)
                ->count();
            $monthlyRevenue[] = ['month' => $month->format('M Y'), 'revenue' => $revenue, 'count' => $count];
        }

        $financial = [
            'total_invoiced' => $totalInvoicedAmount,
            'total_tax' => $totalTaxCollected,
            'tax_rate_summary' => $taxRateSummary,
            'outstanding' => $outstandingAmount,
            'monthly_revenue' => $monthlyRevenue,
        ];

        $complianceReports = \App\Models\ComplianceReport::where('company_id', $company->id)->get();
        $failedSubmissions = FbrLog::whereIn('invoice_id', $invoiceIds)->where('status', 'failed')->count();
        $compliance = [
            'avg_score' => $complianceReports->avg('final_score') ?? 0,
            'total_reports' => $complianceReports->count(),
            'audit_probability' => $this->calcAuditProbability($company),
            'failed_submissions' => $failedSubmissions,
            'risk_distribution' => [
                'LOW' => $complianceReports->where('risk_level', 'LOW')->count(),
                'MEDIUM' => $complianceReports->where('risk_level', 'MEDIUM')->count(),
                'HIGH' => $complianceReports->where('risk_level', 'HIGH')->count(),
                'CRITICAL' => $complianceReports->where('risk_level', 'CRITICAL')->count(),
            ],
        ];

        $activityLogs = \App\Models\InvoiceActivityLog::where('company_id', $company->id)
            ->with('invoice', 'user')
            ->orderBy('created_at', 'desc')
            ->take(20)
            ->get();

        $plans = PricingPlan::all();

        return view('admin.company-show', compact('company', 'stats', 'activePlan', 'users', 'financial', 'compliance', 'activityLogs', 'plans'));
    }

    public function toggleInternalAccount(Request $request, Company $company)
    {
        $company->update(['is_internal_account' => !$company->is_internal_account]);
        AuditLogService::log(
            $company->is_internal_account ? 'internal_account_enabled' : 'internal_account_disabled',
            'Company', $company->id, null,
            ['is_internal_account' => $company->is_internal_account]
        );
        SecurityLogService::log('internal_account_toggled', auth()->id(), [
            'company_id' => $company->id,
            'is_internal_account' => $company->is_internal_account,
        ]);
        return redirect('/admin/company/' . $company->id)->with('success', 'Internal account status updated.');
    }

    public function toggleInventory(Request $request, Company $company)
    {
        $company->update(['inventory_enabled' => !$company->inventory_enabled]);
        AuditLogService::log(
            $company->inventory_enabled ? 'inventory_enabled' : 'inventory_disabled',
            'Company', $company->id, null,
            ['inventory_enabled' => $company->inventory_enabled]
        );
        SecurityLogService::log('inventory_toggled', auth()->id(), [
            'company_id' => $company->id,
            'inventory_enabled' => $company->inventory_enabled,
        ]);
        return redirect('/admin/company/' . $company->id)->with('success', 'Inventory module ' . ($company->inventory_enabled ? 'enabled' : 'disabled') . '.');
    }

    public function updateCompanyLimits(Request $request, Company $company)
    {
        $request->validate([
            'invoice_limit_override' => 'nullable|integer|min:-1',
            'user_limit_override' => 'nullable|integer|min:-1',
            'branch_limit_override' => 'nullable|integer|min:-1',
        ]);

        $data = [];
        $data['invoice_limit_override'] = $request->input('invoice_limit_override') !== null && $request->input('invoice_limit_override') !== '' ? (int) $request->input('invoice_limit_override') : null;
        $data['user_limit_override'] = $request->input('user_limit_override') !== null && $request->input('user_limit_override') !== '' ? (int) $request->input('user_limit_override') : null;
        $data['branch_limit_override'] = $request->input('branch_limit_override') !== null && $request->input('branch_limit_override') !== '' ? (int) $request->input('branch_limit_override') : null;

        $company->update($data);

        AuditLogService::log('company_limits_updated', 'Company', $company->id, null, $data);
        SecurityLogService::log('company_limits_updated', auth()->id(), [
            'company_id' => $company->id,
            'limits' => $data,
        ]);

        return redirect('/admin/company/' . $company->id)->with('success', 'Company limits updated successfully.');
    }

    public function resetCompanyLimits(Request $request, Company $company)
    {
        $company->update([
            'invoice_limit_override' => null,
            'user_limit_override' => null,
            'branch_limit_override' => null,
        ]);

        AuditLogService::log('company_limits_reset', 'Company', $company->id, null, []);
        SecurityLogService::log('company_limits_reset', auth()->id(), ['company_id' => $company->id]);

        return redirect('/admin/company/' . $company->id)->with('success', 'Company limits reset to plan defaults.');
    }

    private function calcAuditProbability(Company $company)
    {
        $score = $company->compliance_score ?? 75;
        if ($score >= 80) return rand(5, 15);
        if ($score >= 60) return rand(20, 40);
        if ($score >= 40) return rand(45, 65);
        return rand(70, 90);
    }
}
