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
use App\Http\Controllers\ShareController;
use App\Http\Controllers\CompanyUserController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\CustomerLedgerController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\TaxOverrideController;

Route::get('/share/invoice/{uuid}', [ShareController::class, 'show']);

Route::get('/demo-login/{role}', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'demoLogin'])
    ->where('role', 'super_admin|company_admin|demo');

Route::get('/', function () {
    return \Illuminate\Support\Facades\Auth::check()
        ? redirect('/dashboard')
        : view('landing');
});

Route::middleware(['auth', 'company', 'rate_limit_company'])->group(function () {

    Route::get('/onboarding', [OnboardingController::class, 'index'])->name('onboarding');
    Route::post('/onboarding/complete', [OnboardingController::class, 'complete']);
    Route::post('/onboarding/skip', [OnboardingController::class, 'skip']);

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/billing/plans', [BillingController::class, 'plans'])->name('billing.plans');
    Route::post('/billing/subscribe', [BillingController::class, 'subscribe']);
    Route::post('/api/billing/calculate', [BillingController::class, 'calculatePrice']);

    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');

    Route::middleware(['role:company_admin,employee'])->group(function () {
        Route::get('/invoice/create', [InvoiceController::class, 'create'])->name('invoice.create');
        Route::post('/invoice/store', [InvoiceController::class, 'store']);
        Route::get('/invoice/{invoice}/edit', [InvoiceController::class, 'edit']);
        Route::put('/invoice/{invoice}', [InvoiceController::class, 'update']);
        Route::post('/invoice/{invoice}/submit', [InvoiceController::class, 'submit']);
        Route::post('/invoice/{invoice}/validate', [InvoiceController::class, 'validateInvoice']);
        Route::post('/invoice/{invoice}/validate-fbr', [InvoiceController::class, 'validateFbrPayload']);

        Route::get('/customers', [CustomerLedgerController::class, 'index'])->name('customers.index');
        Route::get('/customers/{ntn}/ledger', [CustomerLedgerController::class, 'show']);
        Route::post('/customers/payment', [CustomerLedgerController::class, 'addPayment']);
        Route::post('/customers/adjustment', [CustomerLedgerController::class, 'addAdjustment']);

        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::post('/products/{product}/toggle', [ProductController::class, 'deactivate'])->name('products.toggle');
        Route::get('/products/search', [ProductController::class, 'search'])->name('products.search');
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
    Route::get('/api/schedule/config', function () {
        return response()->json(\App\Services\ScheduleEngine::$scheduleTypes);
    });
    Route::get('/api/hs-lookup', function (\Illuminate\Http\Request $request) {
        $hsCode = $request->get('hs_code', '');
        $companyId = app('currentCompanyId');
        $company = \App\Models\Company::find($companyId);
        $standardTaxRate = $company ? $company->getStandardTaxRateValue() : 18.0;
        $customerNtn = $request->get('customer_ntn');

        $resolved = \App\Services\TaxResolutionService::resolve($hsCode, $company, $customerNtn);
        if ($resolved['pct_code']) {
            $rules = \App\Services\ScheduleEngine::resolveValidationRules($resolved['schedule_type'], $resolved['tax_rate'], $standardTaxRate);
            $resolved['requires_sro'] = $rules['requires_sro'];
            $resolved['requires_serial'] = $rules['requires_serial'];
            $resolved['requires_mrp'] = $rules['requires_mrp'];
            $resolved['standard_tax_rate'] = $standardTaxRate;
            return response()->json($resolved);
        }

        $result = \App\Services\ScheduleEngine::lookupByHsCode($hsCode, $standardTaxRate);
        if ($result) {
            $result['standard_tax_rate'] = $standardTaxRate;
        }
        return response()->json($result ?: ['found' => false]);
    });
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
    Route::get('/invoice/{invoice}/preview', [InvoiceController::class, 'preview']);
    Route::get('/invoice/{invoice}/pdf', [InvoiceController::class, 'pdf']);
    Route::get('/invoice/{invoice}/download', [InvoiceController::class, 'download']);
    Route::post('/invoice/{invoice}/verify', [InvoiceController::class, 'verifyIntegrity'])->name('invoice.verify');

    Route::get('/compliance/certificate', [ComplianceCertificateController::class, 'generate'])->name('compliance.certificate');
    Route::get('/compliance/risk-report', [RiskReportController::class, 'show'])->name('compliance.risk-report');

    Route::get('/mis', [MISController::class, 'index'])->name('mis.index');
    Route::get('/mis/export', [MISController::class, 'exportCsv'])->name('mis.export');

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
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
