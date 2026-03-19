<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ComplianceCertificateController;
use App\Http\Controllers\RiskReportController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\MISController;
use App\Http\Controllers\HsMasterExportController;
use App\Http\Controllers\GlobalHsMasterController;
use App\Http\Controllers\SroReferenceController;
use App\Http\Controllers\Admin\HsMasterController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\CompanyUserController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\CustomerLedgerController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PosInventoryController;
use App\Http\Controllers\PosAuthController;
use App\Http\Controllers\HsCodeMappingController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\TaxOverrideController;
use App\Http\Controllers\CustomerProfileController;
use App\Http\Controllers\WhtReportController;
use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\AnnouncementController;

Route::get('/share/invoice/{uuid}', [ShareController::class, 'show']);

Route::get('/demo-login/{role}', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'demoLogin'])
    ->where('role', 'super_admin|company_admin|demo');

Route::get('/health', function () {
    $replitdbContent = '';
    if (file_exists('/tmp/replitdb')) {
        $raw = trim(file_get_contents('/tmp/replitdb'));
        if (preg_match('/^(postgres(ql)?:\/\/[^:]+:[^@]+@)([^\/]+)(\/\S+)/', $raw, $m)) {
            $replitdbContent = 'postgres_url_host=' . $m[3];
        } else {
            $replitdbContent = substr($raw, 0, 30) . '...';
        }
    }
    $dbUrlGetenv = getenv('DATABASE_URL') ?: 'empty';
    $dbUrlPhpEnv = $_ENV['DATABASE_URL'] ?? 'empty';
    $dbUrlServer = $_SERVER['DATABASE_URL'] ?? 'empty';
    $dbUrlLaravel = env('DATABASE_URL') ?: 'empty';
    $dbUrlFile = file_exists('/tmp/prod_database_url') ? trim(file_get_contents('/tmp/prod_database_url')) : 'empty';
    $dbUrlSources = [
        'getenv' => ($dbUrlGetenv !== 'empty') ? 'set' : 'empty',
        '_ENV' => ($dbUrlPhpEnv !== 'empty') ? 'set' : 'empty',
        '_SERVER' => ($dbUrlServer !== 'empty') ? 'set' : 'empty',
        'laravel_env' => ($dbUrlLaravel !== 'empty') ? 'set' : 'empty',
        'file_dump' => ($dbUrlFile !== 'empty' && !empty($dbUrlFile)) ? 'set' : 'empty',
    ];
    $foundUrl = null;
    foreach ([$dbUrlGetenv, $dbUrlPhpEnv, $dbUrlServer, $dbUrlLaravel] as $candidate) {
        if ($candidate !== 'empty' && preg_match('/^postgres(ql)?:\/\//', $candidate)) {
            $foundUrl = $candidate;
            break;
        }
    }
    $resolvedHost = 'none';
    if ($foundUrl) {
        $p = parse_url(preg_replace('/^postgres:\/\//', 'postgresql://', $foundUrl));
        $resolvedHost = $p['host'] ?? 'parse_fail';
    }
    $info = [
        'status' => 'ok',
        'db_host' => config('database.connections.pgsql.host'),
        'db_port' => config('database.connections.pgsql.port'),
        'db_name' => config('database.connections.pgsql.database'),
        'db_user' => config('database.connections.pgsql.username'),
        'session_driver' => config('session.driver'),
        'cache_driver' => config('cache.default'),
        'config_cached' => file_exists(base_path('bootstrap/cache/config.php')),
        'replitdb_exists' => file_exists('/tmp/replitdb'),
        'replitdb_content' => $replitdbContent,
        'database_url_sources' => $dbUrlSources,
        'resolved_db_host' => $resolvedHost,
        'pghost_getenv' => getenv('PGHOST') ?: 'not_set',
        'db_host_getenv' => getenv('DB_HOST') ?: 'not_set',
    ];
    try {
        $start = microtime(true);
        \DB::connection()->getPdo();
        $info['db'] = 'connected';
        $info['db_time'] = round((microtime(true) - $start) * 1000) . 'ms';
    } catch (\Exception $e) {
        $info['db'] = 'failed';
        $info['db_error'] = substr($e->getMessage(), 0, 300);
    }
    return response()->json($info);
});

Route::get('/', function () {
    return view('landing', ['showLogin' => false]);
});

Route::get('/digital-invoice', function () {
    $plans = \App\Models\PricingPlan::where('is_trial', false)->where('product_type', 'di')->orderBy('price')->get();
    return view('di-landing', ['plans' => $plans]);
})->name('di.landing');

Route::get('/di', function () {
    return redirect('/digital-invoice');
});

Route::get('/pos', function () {
    $plans = \App\Models\PricingPlan::where('is_trial', false)->where('product_type', 'pos')->orderBy('price')->get();
    return view('pos.landing', ['plans' => $plans]);
})->name('pos.landing');
Route::get('/pos/login', [PosAuthController::class, 'showLogin'])->name('pos.login');
Route::post('/pos/login', [PosAuthController::class, 'login']);
Route::get('/pos/register', [PosAuthController::class, 'showRegister'])->name('pos.register');
Route::post('/pos/register', [PosAuthController::class, 'register']);
Route::post('/pos/logout', [PosAuthController::class, 'logout'])->name('pos.logout');

Route::get('/pos/invoice/share/{token}', [PosController::class, 'publicInvoicePdf'])->name('pos.invoice.share');

Route::middleware(['auth', 'company', 'rate_limit_company', 'company.approval'])->group(function () {

    Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding');
    Route::post('/onboarding/complete', [OnboardingController::class, 'complete']);
    Route::post('/onboarding/skip', [OnboardingController::class, 'skip']);

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/billing/plans', [BillingController::class, 'plans'])->name('billing.plans');
    Route::post('/billing/subscribe', [BillingController::class, 'subscribe']);
    Route::post('/api/billing/calculate', [BillingController::class, 'calculatePrice']);
    Route::get('/billing/custom-plan', [BillingController::class, 'customPlanBuilder']);
    Route::post('/billing/calculate-custom', [BillingController::class, 'calculateCustomPlan']);
    Route::post('/billing/subscribe-custom', [BillingController::class, 'subscribeCustomPlan']);

    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');

    Route::middleware(['role:company_admin,employee'])->group(function () {
        Route::get('/invoice/create', [InvoiceController::class, 'create'])->name('invoice.create');
        Route::post('/invoice/store', [InvoiceController::class, 'store']);
        Route::get('/invoice/{invoice}/edit', [InvoiceController::class, 'edit']);
        Route::put('/invoice/{invoice}', [InvoiceController::class, 'update']);
        Route::post('/invoice/{invoice}/submit', [InvoiceController::class, 'submit']);
        Route::post('/invoice/{invoice}/retry', [InvoiceController::class, 'retry']);
        Route::post('/invoice/{invoice}/resubmit-fbr', [InvoiceController::class, 'resubmitToFbr']);
        Route::post('/invoice/{invoice}/validate', [InvoiceController::class, 'validateInvoice']);
        Route::post('/invoice/{invoice}/validate-fbr', [InvoiceController::class, 'validateFbrPayload']);
        Route::post('/invoice/{invoice}/confirm-fbr', [InvoiceController::class, 'confirmFbrStatus']);
        Route::post('/invoice/{invoice}/update-fbr-number', [InvoiceController::class, 'updateFbrNumber']);
        Route::post('/invoice/{invoice}/duplicate', [InvoiceController::class, 'duplicate'])->name('invoice.duplicate');
        Route::delete('/invoice/{invoice}', [InvoiceController::class, 'destroy'])->name('invoice.destroy');

        Route::get('/invoices/csv-template', [CsvImportController::class, 'template'])->name('invoices.csv-template');
        Route::post('/invoices/csv-upload', [CsvImportController::class, 'upload'])->name('invoices.csv-upload');
        Route::post('/invoices/csv-process', [CsvImportController::class, 'process'])->name('invoices.csv-process');

        Route::get('/customers', [CustomerLedgerController::class, 'index'])->name('customers.index');
        Route::get('/customers/{ntn}/ledger', [CustomerLedgerController::class, 'show']);
        Route::post('/customers/payment', [CustomerLedgerController::class, 'addPayment']);
        Route::post('/customers/adjustment', [CustomerLedgerController::class, 'addAdjustment']);

        Route::get('/sro-reference', [SroReferenceController::class, 'index'])->name('sro-reference');
        Route::get('/api/sro-reference/search', [SroReferenceController::class, 'apiSearch'])->name('sro-reference.search');

        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::post('/products/{product}/toggle', [ProductController::class, 'deactivate'])->name('products.toggle');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
        Route::get('/products/search', [ProductController::class, 'search'])->name('products.search');

        Route::get('/customer-profiles', [CustomerProfileController::class, 'index'])->name('customer-profiles.index');
        Route::get('/customer-profiles/create', [CustomerProfileController::class, 'create'])->name('customer-profiles.create');
        Route::post('/customer-profiles', [CustomerProfileController::class, 'store'])->name('customer-profiles.store');
        Route::get('/customer-profiles/{customerProfile}/edit', [CustomerProfileController::class, 'edit'])->name('customer-profiles.edit');
        Route::put('/customer-profiles/{customerProfile}', [CustomerProfileController::class, 'update'])->name('customer-profiles.update');
        Route::post('/customer-profiles/{customerProfile}/toggle', [CustomerProfileController::class, 'toggle'])->name('customer-profiles.toggle');
    });


    Route::middleware(['role:company_admin'])->group(function () {
        Route::get('/company/users', [CompanyUserController::class, 'index']);
        Route::post('/company/users', [CompanyUserController::class, 'store']);
        Route::patch('/company/users/{user}/role', [CompanyUserController::class, 'updateRole']);
        Route::patch('/company/users/{user}/reset-password', [CompanyUserController::class, 'resetPassword']);
        Route::patch('/company/users/{user}/toggle', [CompanyUserController::class, 'toggleActive']);

        Route::get('/company/profile', [CompanySettingsController::class, 'profile']);
        Route::put('/company/profile', [CompanySettingsController::class, 'updateProfile']);
        Route::get('/company/fbr-settings', [CompanySettingsController::class, 'fbrSettings']);
        Route::put('/company/fbr-settings', [CompanySettingsController::class, 'updateFbrSettings']);
        Route::post('/company/fbr-settings-ajax', [CompanySettingsController::class, 'updateFbrSettingsAjax']);
        Route::post('/company/test-connection', [CompanySettingsController::class, 'testConnection']);
        Route::post('/company/sandbox-test/{type}', [CompanySettingsController::class, 'sandboxTest']);

        Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
        Route::get('/branches/create', [BranchController::class, 'create'])->name('branches.create');
        Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
        Route::get('/branches/{branch}/edit', [BranchController::class, 'edit'])->name('branches.edit');
        Route::put('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
        Route::delete('/branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');
    });

    Route::get('/api/products/search', [ProductController::class, 'search']);
    Route::post('/api/products/quick-create', [ProductController::class, 'quickCreate']);
    Route::get('/api/customer-profiles/search', [CustomerProfileController::class, 'search']);
    Route::get('/api/schedule/config', function () {
        return response()->json(\App\Services\ScheduleEngine::$scheduleTypes);
    });
    Route::get('/api/hs-lookup', [GlobalHsMasterController::class, 'apiLookup']);
    Route::get('/api/hs-search', [GlobalHsMasterController::class, 'apiSearch']);
    Route::get('/api/hs-mapping-suggestions/{hsCode}', [HsCodeMappingController::class, 'apiSuggestions']);
    Route::post('/api/hs-mapping-response', [HsCodeMappingController::class, 'apiRecordResponse']);
    Route::get('/api/sro-suggest', function (\Illuminate\Http\Request $request) {
        $scheduleType = $request->get('schedule_type', 'standard');
        $taxRate = $request->get('tax_rate') ? floatval($request->get('tax_rate')) : null;
        $hsCode = $request->get('hs_code');
        $companyId = app('currentCompanyId');
        $company = \App\Models\Company::find($companyId);
        $standardTaxRate = $company ? $company->getStandardTaxRateValue() : 18.0;
        return response()->json(\App\Services\SroSuggestionService::getApiResponse($scheduleType, $taxRate, $hsCode, $standardTaxRate));
    });
    Route::get('/api/tax-resolve', function (\Illuminate\Http\Request $request) {
        $companyId = app('currentCompanyId');
        $hsCode = $request->get('hs_code', '');
        $customerNtn = $request->get('customer_ntn');
        return response()->json(\App\Services\TaxResolutionService::resolveForApi($hsCode, $companyId, $customerNtn));
    });
    Route::get('/api/invoice/{invoice}/risk-analysis', function (\Illuminate\Http\Request $request, \App\Models\Invoice $invoice) {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId && auth()->user()->role !== 'super_admin') {
            abort(403);
        }
        return response()->json(\App\Services\RiskIntelligenceEngine::analyzeInvoice($invoice));
    });
    Route::get('/api/hs-usage-suggestions/{hsCode}', function (string $hsCode) {
        $suggestions = \App\Services\HsUsagePatternService::getSuggestions($hsCode);
        if ($suggestions && count($suggestions) > 0) {
            $best = $suggestions[0];
            $best['hs_code'] = $hsCode;
            $best['all_suggestions'] = $suggestions;
            return response()->json($best);
        }
        return response()->json(['hs_code' => $hsCode]);
    });

    Route::get('/api/smart-tax-recommend', function (\Illuminate\Http\Request $request) {
        $companyId = app('currentCompanyId');
        $hsCode = $request->get('hs_code', '');
        $province = $request->get('province');
        $buyerRegType = $request->get('buyer_registration_type');
        $sectorType = $request->get('sector_type');
        return response()->json(\App\Services\SmartTaxEngine::recommend($hsCode, $province, $buyerRegType, $sectorType, $companyId));
    });

    Route::post('/api/rejection-probability', function (\Illuminate\Http\Request $request) {
        $companyId = app('currentCompanyId');
        return response()->json(\App\Services\RejectionProbabilityEngine::simulateFromRequest($request->all(), $companyId));
    });

    Route::get('/api/invoice/{invoice}/rejection-probability', function (\App\Models\Invoice $invoice) {
        $companyId = app('currentCompanyId');
        if ($invoice->company_id !== $companyId && auth()->user()->role !== 'super_admin') {
            abort(403);
        }
        return response()->json(\App\Services\RejectionProbabilityEngine::simulate($invoice));
    });

    Route::get('/api/audit-probability', function () {
        $companyId = app('currentCompanyId');
        return response()->json(\App\Services\AuditProbabilityEngine::calculate($companyId));
    });

    Route::get('/api/risk-heatmap', [DashboardController::class, 'riskHeatmap']);

    Route::get('/executive-dashboard', [DashboardController::class, 'executive'])->name('executive.dashboard');

    Route::post('/toggle-dark-mode', function () {
        $user = auth()->user();
        $user->dark_mode = !$user->dark_mode;
        $user->save();
        return back();
    })->name('toggle.dark-mode');

    Route::post('/api/compliance/check', [InvoiceController::class, 'complianceCheck']);
    Route::get('/api/enterprise/invoice/{invoice}/status', [InvoiceController::class, 'apiStatus']);
    Route::get('/api/enterprise/company/compliance', [InvoiceController::class, 'apiComplianceStatus']);

    Route::get('/invoice/{invoice}', [InvoiceController::class, 'show'])->name('invoice.show');
    Route::get('/invoice/{invoice}/status-json', [InvoiceController::class, 'statusJson']);
    Route::get('/invoice/{invoice}/preview', [InvoiceController::class, 'preview']);
    Route::get('/invoice/{invoice}/pdf', [InvoiceController::class, 'pdf']);
    Route::get('/invoice/{invoice}/download', [InvoiceController::class, 'download']);
    Route::post('/invoice/{invoice}/update-wht', [InvoiceController::class, 'updateWht'])->name('invoice.updateWht');
    Route::post('/invoice/{invoice}/update-wht-ajax', [InvoiceController::class, 'updateWhtAjax'])->name('invoice.updateWhtAjax');
    Route::post('/invoice/{invoice}/correct-wht-ajax', [InvoiceController::class, 'correctWhtAjax'])->name('invoice.correctWhtAjax');
    Route::get('/wht-management', [InvoiceController::class, 'whtManagement'])->name('wht.management');
    Route::post('/invoice/{invoice}/verify', [InvoiceController::class, 'verifyIntegrity'])->name('invoice.verify');

    Route::get('/compliance/certificate', [ComplianceCertificateController::class, 'generate'])->name('compliance.certificate');
    Route::get('/compliance/risk-report', [RiskReportController::class, 'show'])->name('compliance.risk-report');

    Route::get('/reports/wht', [WhtReportController::class, 'index'])->name('reports.wht');
    Route::get('/reports/wht/download', [WhtReportController::class, 'downloadWht'])->name('reports.wht.download');
    Route::get('/reports/wht/pdf', [WhtReportController::class, 'pdfWht'])->name('reports.wht.pdf');
    Route::get('/reports/tax-summary', [WhtReportController::class, 'taxSummary'])->name('reports.tax-summary');
    Route::get('/reports/tax-summary/download', [WhtReportController::class, 'downloadTaxSummary'])->name('reports.tax-summary.download');
    Route::get('/reports/tax-summary/pdf', [WhtReportController::class, 'pdfTaxSummary'])->name('reports.tax-summary.pdf');

    Route::get('/mis', [MISController::class, 'index'])->name('mis.index');
    Route::get('/mis/export', [MISController::class, 'exportCsv'])->name('mis.export');
    Route::get('/mis/pdf', [MISController::class, 'exportPdf'])->name('mis.pdf');

    Route::middleware(['role:company_admin,super_admin'])->group(function () {
        Route::get('/tax-overrides', [TaxOverrideController::class, 'index'])->name('tax-overrides.index');
        Route::post('/tax-overrides/customer', [TaxOverrideController::class, 'storeCustomerRule'])->name('tax-overrides.customer.store');
        Route::put('/tax-overrides/customer/{id}', [TaxOverrideController::class, 'updateCustomerRule'])->name('tax-overrides.customer.update');
        Route::delete('/tax-overrides/customer/{id}', [TaxOverrideController::class, 'deleteCustomerRule'])->name('tax-overrides.customer.delete');
    });

    Route::middleware(['role:super_admin'])->group(function () {
        Route::post('/tax-overrides/sector', [TaxOverrideController::class, 'storeSectorRule'])->name('tax-overrides.sector.store');
        Route::put('/tax-overrides/sector/{id}', [TaxOverrideController::class, 'updateSectorRule'])->name('tax-overrides.sector.update');
        Route::delete('/tax-overrides/sector/{id}', [TaxOverrideController::class, 'deleteSectorRule'])->name('tax-overrides.sector.delete');
        Route::post('/tax-overrides/province', [TaxOverrideController::class, 'storeProvinceRule'])->name('tax-overrides.province.store');
        Route::put('/tax-overrides/province/{id}', [TaxOverrideController::class, 'updateProvinceRule'])->name('tax-overrides.province.update');
        Route::delete('/tax-overrides/province/{id}', [TaxOverrideController::class, 'deleteProvinceRule'])->name('tax-overrides.province.delete');
        Route::post('/tax-overrides/sro', [TaxOverrideController::class, 'storeSroRule'])->name('tax-overrides.sro.store');
        Route::put('/tax-overrides/sro/{id}', [TaxOverrideController::class, 'updateSroRule'])->name('tax-overrides.sro.update');
        Route::delete('/tax-overrides/sro/{id}', [TaxOverrideController::class, 'deleteSroRule'])->name('tax-overrides.sro.delete');
        Route::get('/tax-overrides/analytics', [TaxOverrideController::class, 'overrideAnalytics'])->name('tax-overrides.analytics');

        Route::get('/admin/dashboard', [AdminController::class, 'dashboard'])->name('admin.dashboard');
        Route::get('/admin/companies', [AdminController::class, 'companies']);
        Route::get('/admin/companies/create', [AdminController::class, 'createCompany']);
        Route::post('/admin/companies', [AdminController::class, 'storeCompany']);
        Route::get('/admin/users', [AdminController::class, 'users']);
        Route::post('/admin/users', [AdminController::class, 'storeUser']);
        Route::get('/admin/fbr-logs', [AdminController::class, 'fbrLogs']);
        Route::get('/admin/system-health', [AdminController::class, 'systemHealth']);
        Route::get('/admin/security-logs', [AdminController::class, 'securityLogs']);
        Route::get('/admin/audit/export', [AdminController::class, 'auditExport'])->name('admin.audit.export');
        Route::get('/admin/audit-logs', [AdminController::class, 'auditLogs'])->name('admin.audit-logs');
        Route::get('/admin/anomalies', [AdminController::class, 'anomalies'])->name('admin.anomalies');
        Route::get('/admin/risk-settings', [AdminController::class, 'riskSettings']);
        Route::post('/admin/risk-settings', [AdminController::class, 'updateRiskSettings']);
        Route::get('/admin/override-logs', [AdminController::class, 'overrideLogs']);
        Route::get('/admin/company/{company}', [AdminController::class, 'companyShow']);
        Route::post('/admin/company/{company}/suspend', [AdminController::class, 'suspendCompany']);
        Route::post('/admin/company/{company}/approve', [AdminController::class, 'approveCompany']);
        Route::post('/admin/company/{company}/reject', [AdminController::class, 'rejectCompany']);
        Route::get('/admin/companies/pending', [AdminController::class, 'pendingCompanies']);
        Route::post('/admin/company/{company}/change-plan', [AdminController::class, 'changePlan']);
        Route::post('/admin/company/{company}/toggle-internal', [AdminController::class, 'toggleInternalAccount']);
        Route::post('/admin/company/{company}/toggle-inventory', [AdminController::class, 'toggleInventory']);
        Route::post('/admin/company/{company}/update-limits', [AdminController::class, 'updateCompanyLimits']);
        Route::post('/admin/company/{company}/reset-limits', [AdminController::class, 'resetCompanyLimits']);
        Route::get('/admin/announcements', [AnnouncementController::class, 'index'])->name('admin.announcements');
        Route::post('/admin/announcements', [AnnouncementController::class, 'store'])->name('admin.announcements.store');
        Route::post('/admin/announcements/{id}/toggle', [AnnouncementController::class, 'toggle'])->name('admin.announcements.toggle');
        Route::delete('/admin/announcements/{id}/delete', [AnnouncementController::class, 'destroy'])->name('admin.announcements.destroy');

        Route::get('/admin/invoice-override', [AdminController::class, 'invoiceSearch'])->name('admin.invoice-override');
        Route::post('/admin/invoice-override/{id}', [AdminController::class, 'invoiceOverride'])->name('admin.invoice-override.action');

        Route::get('/admin/hs-master-export', [HsMasterExportController::class, 'index'])->name('admin.hs-master-export');

        Route::get('/admin/hs-master', [GlobalHsMasterController::class, 'index'])->name('admin.hs-master');
        Route::post('/admin/hs-master', [GlobalHsMasterController::class, 'store'])->name('admin.hs-master.store');
        Route::put('/admin/hs-master/{id}', [GlobalHsMasterController::class, 'update'])->name('admin.hs-master.update');
        Route::post('/admin/hs-master/seed', [GlobalHsMasterController::class, 'seed'])->name('admin.hs-master.seed');
        Route::post('/admin/hs-master/map-unmapped', [GlobalHsMasterController::class, 'mapUnmapped'])->name('admin.hs-master.map-unmapped');

        Route::get('/admin/hs-master-global', [HsMasterController::class, 'index'])->name('admin.hs-master-global.index');
        Route::get('/admin/hs-master-global/{id}/edit', [HsMasterController::class, 'edit'])->name('admin.hs-master-global.edit');
        Route::post('/admin/hs-master-global/{id}', [HsMasterController::class, 'update'])->name('admin.hs-master-global.update');
        Route::get('/admin/hs-unmapped', [HsMasterController::class, 'unmapped'])->name('admin.hs-master-global.unmapped');
        Route::post('/admin/hs-unmapped/{id}/map', [HsMasterController::class, 'mapFromQueue'])->name('admin.hs-master-global.map');
        Route::post('/admin/hs-unmapped/{id}/reject', [HsMasterController::class, 'rejectSuggestion'])->name('admin.hs-master-global.reject');
        Route::post('/admin/hs-unmapped/{id}/regenerate', [HsMasterController::class, 'regenerateSuggestion'])->name('admin.hs-master-global.regenerate');

        Route::get('/admin/hs-mapping-engine', [HsCodeMappingController::class, 'index'])->name('admin.hs-mapping-engine');
        Route::get('/admin/hs-mapping-engine/export', [HsCodeMappingController::class, 'exportCsv'])->name('admin.hs-mapping-engine.export');
        Route::post('/admin/hs-mapping-engine/import', [HsCodeMappingController::class, 'importCsv'])->name('admin.hs-mapping-engine.import');
        Route::post('/admin/hs-mapping-engine', [HsCodeMappingController::class, 'store'])->name('admin.hs-mapping-engine.store');
        Route::put('/admin/hs-mapping-engine/{id}', [HsCodeMappingController::class, 'update'])->name('admin.hs-mapping-engine.update');
        Route::delete('/admin/hs-mapping-engine/{id}', [HsCodeMappingController::class, 'destroy'])->name('admin.hs-mapping-engine.destroy');
        Route::post('/admin/hs-mapping-engine/{id}/clone', [HsCodeMappingController::class, 'duplicate'])->name('admin.hs-mapping-engine.clone');
        Route::get('/api/hs-mapping-autofill/{hsCode}', [HsCodeMappingController::class, 'apiHsAutoFill']);
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/company', [ProfileController::class, 'updateCompany'])->name('profile.updateCompany');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware('auth')->group(function () {
    Route::post('/announcements/{id}/dismiss', [AnnouncementController::class, 'dismiss'])->name('announcements.dismiss');

    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::get('/inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements');
    Route::post('/inventory/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust');
    Route::put('/inventory/stock/{id}/min-stock', [InventoryController::class, 'updateMinStock'])->name('inventory.update-min-stock');
    Route::get('/inventory/product/{productId}/movements', [InventoryController::class, 'productMovements'])->name('inventory.product-movements');
    Route::get('/api/inventory/stock/{productId}', [InventoryController::class, 'apiStockCheck'])->name('api.inventory.stock-check');

    Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::put('/suppliers/{id}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');

    Route::get('/purchase-orders', [SupplierController::class, 'purchaseOrders'])->name('purchase-orders.index');
    Route::post('/purchase-orders', [SupplierController::class, 'storePurchaseOrder'])->name('purchase-orders.store');
    Route::post('/purchase-orders/{id}/receive', [SupplierController::class, 'receivePurchaseOrder'])->name('purchase-orders.receive');
    Route::post('/purchase-orders/{id}/cancel', [SupplierController::class, 'cancelPurchaseOrder'])->name('purchase-orders.cancel');
});

Route::middleware(['pos.auth'])->prefix('pos')->group(function () {
    Route::get('/dashboard', [PosController::class, 'dashboard'])->name('pos.dashboard');
    Route::get('/invoice/create', [PosController::class, 'createInvoice'])->name('pos.invoice.create');
    Route::post('/invoice/store', [PosController::class, 'storeInvoice'])->name('pos.invoice.store');
    Route::get('/transactions', [PosController::class, 'transactions'])->name('pos.transactions');
    Route::get('/transaction/{id}', [PosController::class, 'transactionShow'])->name('pos.transaction.show');
    Route::get('/transaction/{id}/edit', [PosController::class, 'editTransaction'])->name('pos.transaction.edit');
    Route::put('/transaction/{id}', [PosController::class, 'updateTransaction'])->name('pos.transaction.update');
    Route::delete('/transaction/{id}', [PosController::class, 'deleteTransaction'])->name('pos.transaction.delete');
    Route::post('/transaction/{id}/retry-pra', [PosController::class, 'retryPra'])->name('pos.transaction.retry-pra');
    Route::post('/transactions/bulk-retry-pra', [PosController::class, 'bulkRetryPra'])->name('pos.transactions.bulk-retry-pra');
    Route::get('/transaction/{id}/receipt', [PosController::class, 'receipt'])->name('pos.receipt');
    Route::get('/transaction/{id}/pdf', [PosController::class, 'downloadInvoicePdf'])->name('pos.invoice.pdf');
    Route::post('/transaction/{id}/share-link', [PosController::class, 'generateShareLink'])->name('pos.invoice.share-link');
    Route::get('/reports', [PosController::class, 'reports'])->name('pos.reports');
    Route::get('/tax-reports', [PosController::class, 'taxReports'])->name('pos.tax-reports');
    Route::get('/tax-reports/csv', [PosController::class, 'exportTaxReportCsv'])->name('pos.tax-reports.csv');
    Route::get('/tax-reports/pdf', [PosController::class, 'exportTaxReportPdf'])->name('pos.tax-reports.pdf');
    Route::get('/services', [PosController::class, 'services'])->name('pos.services');
    Route::post('/services', [PosController::class, 'storeService'])->name('pos.services.store');
    Route::put('/services/{id}', [PosController::class, 'updateService'])->name('pos.services.update');
    Route::delete('/services/{id}', [PosController::class, 'deleteService'])->name('pos.services.delete');
    Route::get('/terminals', [PosController::class, 'terminals'])->name('pos.terminals');
    Route::post('/terminals', [PosController::class, 'storeTerminal'])->name('pos.terminals.store');
    Route::put('/terminals/{id}', [PosController::class, 'updateTerminal'])->name('pos.terminals.update');
    Route::delete('/terminals/{id}', [PosController::class, 'deleteTerminal'])->name('pos.terminals.delete');
    Route::get('/api/tax-rate', [PosController::class, 'getTaxRate'])->name('pos.api.tax-rate');
    Route::post('/api/toggle-pra', [PosController::class, 'togglePra'])->name('pos.api.toggle-pra');
    Route::post('/api/draft/save', [PosController::class, 'saveDraft'])->name('pos.api.draft.save');
    Route::get('/api/draft/list', [PosController::class, 'getDrafts'])->name('pos.api.draft.list');
    Route::delete('/api/draft/{id}', [PosController::class, 'deleteDraft'])->name('pos.api.draft.delete');
    Route::post('/api/invoice/{id}/lock', [PosController::class, 'lockInvoice'])->name('pos.api.invoice.lock');
    Route::post('/api/invoice/{id}/unlock', [PosController::class, 'unlockInvoice'])->name('pos.api.invoice.unlock');
    Route::match(['get', 'post'], '/pra-settings', [PosController::class, 'praSettings'])->name('pos.pra-settings');
    Route::get('/billing', [PosController::class, 'billing'])->name('pos.billing');
    Route::match(['get', 'post'], '/business-profile', [PosController::class, 'businessProfile'])->name('pos.business-profile');
    Route::match(['get', 'post'], '/my-profile', [PosController::class, 'userProfile'])->name('pos.user-profile');
    Route::get('/products', [PosController::class, 'products'])->name('pos.products');
    Route::post('/products', [PosController::class, 'storeProduct'])->name('pos.products.store');
    Route::get('/products/template', [PosController::class, 'downloadProductTemplate'])->name('pos.products.template');
    Route::post('/products/import', [PosController::class, 'importProducts'])->name('pos.products.import');
    Route::put('/products/{id}', [PosController::class, 'updateProduct'])->name('pos.products.update');
    Route::delete('/products/{id}', [PosController::class, 'deleteProduct'])->name('pos.products.delete');
    Route::post('/products/{id}/toggle', [PosController::class, 'toggleProduct'])->name('pos.products.toggle');

    Route::get('/customers', [PosController::class, 'customers'])->name('pos.customers');
    Route::post('/customers', [PosController::class, 'storeCustomer'])->name('pos.customers.store');
    Route::put('/customers/{id}', [PosController::class, 'updateCustomer'])->name('pos.customers.update');
    Route::delete('/customers/{id}', [PosController::class, 'deleteCustomer'])->name('pos.customers.delete');
    Route::post('/customers/{id}/toggle', [PosController::class, 'toggleCustomer'])->name('pos.customers.toggle');

    Route::get('/inventory', [PosInventoryController::class, 'dashboard'])->name('pos.inventory.dashboard');
    Route::get('/inventory/stock', [PosInventoryController::class, 'stock'])->name('pos.inventory.stock');
    Route::get('/inventory/movements', [PosInventoryController::class, 'movements'])->name('pos.inventory.movements');
    Route::get('/inventory/low-stock', [PosInventoryController::class, 'lowStockAlerts'])->name('pos.inventory.low-stock');
    Route::match(['get', 'post'], '/inventory/adjust', [PosInventoryController::class, 'adjustStock'])->name('pos.inventory.adjust');
    Route::post('/inventory/min-stock', [PosInventoryController::class, 'updateMinStock'])->name('pos.inventory.min-stock');
    Route::post('/inventory/toggle', [PosInventoryController::class, 'toggleInventory'])->name('pos.inventory.toggle');
});

use App\Http\Controllers\SaasAdmin\AdminAuthController;
use App\Http\Controllers\SaasAdmin\AdminDashboardController;
use App\Http\Controllers\SaasAdmin\AdminCompanyController;
use App\Http\Controllers\SaasAdmin\AdminPlanController;
use App\Http\Controllers\SaasAdmin\AdminSubscriptionController;
use App\Http\Controllers\SaasAdmin\AdminFranchiseController;
use App\Http\Controllers\SaasAdmin\AdminUsageController;
use App\Http\Controllers\SaasAdmin\AdminSystemController;
use App\Http\Controllers\SaasAdmin\AdminAuditController;
use App\Http\Controllers\Franchise\FranchiseAuthController;
use App\Http\Controllers\Franchise\FranchiseDashboardController;

Route::get('/admin/login', [AdminAuthController::class, 'showLogin'])->name('admin.login');
Route::post('/admin/login', [AdminAuthController::class, 'login']);
Route::post('/admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

Route::prefix('admin')->middleware(['admin.auth'])->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('saas.admin.dashboard');
    Route::get('/companies', [AdminCompanyController::class, 'index'])->name('saas.admin.companies');
    Route::get('/companies/{id}', [AdminCompanyController::class, 'show'])->name('saas.admin.companies.show');
    Route::post('/companies/{id}/approve', [AdminCompanyController::class, 'approve'])->name('saas.admin.companies.approve');
    Route::post('/companies/{id}/reject', [AdminCompanyController::class, 'reject'])->name('saas.admin.companies.reject');
    Route::post('/companies/{id}/suspend', [AdminCompanyController::class, 'suspend'])->name('saas.admin.companies.suspend');
    Route::post('/companies/{id}/activate', [AdminCompanyController::class, 'activate'])->name('saas.admin.companies.activate');
    Route::get('/plans', [AdminPlanController::class, 'index'])->name('saas.admin.plans');
    Route::post('/plans', [AdminPlanController::class, 'store'])->name('saas.admin.plans.store');
    Route::put('/plans/{id}', [AdminPlanController::class, 'update'])->name('saas.admin.plans.update');
    Route::get('/subscriptions', [AdminSubscriptionController::class, 'index'])->name('saas.admin.subscriptions');
    Route::post('/subscriptions/assign', [AdminSubscriptionController::class, 'assign'])->name('saas.admin.subscriptions.assign');
    Route::post('/subscriptions/{id}/toggle', [AdminSubscriptionController::class, 'toggle'])->name('saas.admin.subscriptions.toggle');
    Route::get('/franchises', [AdminFranchiseController::class, 'index'])->name('saas.admin.franchises');
    Route::post('/franchises', [AdminFranchiseController::class, 'store'])->name('saas.admin.franchises.store');
    Route::put('/franchises/{id}', [AdminFranchiseController::class, 'update'])->name('saas.admin.franchises.update');
    Route::post('/franchises/{id}/toggle', [AdminFranchiseController::class, 'toggleStatus'])->name('saas.admin.franchises.toggle');
    Route::get('/company-usage', [AdminUsageController::class, 'index'])->name('saas.admin.usage');
    Route::get('/system-control', [AdminSystemController::class, 'index'])->name('saas.admin.system');
    Route::post('/system-control/{key}/toggle', [AdminSystemController::class, 'toggle'])->name('saas.admin.system.toggle');
    Route::get('/audit-logs', [AdminAuditController::class, 'index'])->name('saas.admin.audit');
});

Route::get('/franchise/login', [FranchiseAuthController::class, 'showLogin'])->name('franchise.login');
Route::post('/franchise/login', [FranchiseAuthController::class, 'login']);
Route::post('/franchise/logout', [FranchiseAuthController::class, 'logout'])->name('franchise.logout');

Route::prefix('franchise')->middleware(['franchise.auth'])->group(function () {
    Route::get('/dashboard', [FranchiseDashboardController::class, 'dashboard'])->name('franchise.dashboard');
    Route::get('/companies', [FranchiseDashboardController::class, 'companies'])->name('franchise.companies');
    Route::get('/subscriptions', [FranchiseDashboardController::class, 'subscriptions'])->name('franchise.subscriptions');
    Route::get('/revenue', [FranchiseDashboardController::class, 'revenue'])->name('franchise.revenue');
});

require __DIR__.'/auth.php';
