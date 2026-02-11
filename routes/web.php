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

Route::get('/', function () {
    return \Illuminate\Support\Facades\Auth::check()
        ? redirect('/dashboard')
        : redirect('/login');
});

Route::middleware(['auth', 'company', 'rate_limit_company'])->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/billing/plans', [BillingController::class, 'plans'])->name('billing.plans');
    Route::post('/billing/subscribe', [BillingController::class, 'subscribe']);

    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');

    Route::middleware(['role:company_admin,employee'])->group(function () {
        Route::get('/invoice/create', [InvoiceController::class, 'create'])->name('invoice.create');
        Route::post('/invoice/store', [InvoiceController::class, 'store']);
        Route::get('/invoice/{invoice}/edit', [InvoiceController::class, 'edit']);
        Route::put('/invoice/{invoice}', [InvoiceController::class, 'update']);
        Route::post('/invoice/{invoice}/submit', [InvoiceController::class, 'submit']);
        Route::post('/invoice/{invoice}/validate', [InvoiceController::class, 'validateInvoice']);

        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::post('/products/{product}/toggle', [ProductController::class, 'deactivate'])->name('products.toggle');
        Route::get('/products/search', [ProductController::class, 'search'])->name('products.search');
    });

    Route::get('/api/products/search', [ProductController::class, 'search']);
    Route::post('/api/compliance/check', [InvoiceController::class, 'complianceCheck']);
    Route::get('/api/enterprise/invoice/{invoice}/status', [InvoiceController::class, 'apiStatus']);
    Route::get('/api/enterprise/company/compliance', [InvoiceController::class, 'apiComplianceStatus']);

    Route::get('/invoice/{invoice}', [InvoiceController::class, 'show'])->name('invoice.show');
    Route::get('/invoice/{invoice}/preview', [InvoiceController::class, 'preview']);
    Route::get('/invoice/{invoice}/pdf', [InvoiceController::class, 'pdf']);
    Route::post('/invoice/{invoice}/verify', [InvoiceController::class, 'verifyIntegrity'])->name('invoice.verify');

    Route::get('/compliance/certificate', [ComplianceCertificateController::class, 'generate'])->name('compliance.certificate');
    Route::get('/compliance/risk-report', [RiskReportController::class, 'show'])->name('compliance.risk-report');

    Route::get('/mis', [MISController::class, 'index'])->name('mis.index');
    Route::get('/mis/export', [MISController::class, 'exportCsv'])->name('mis.export');

    Route::middleware(['role:super_admin'])->group(function () {
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
        Route::get('/admin/anomalies', [AdminController::class, 'anomalies'])->name('admin.anomalies');
        Route::get('/admin/risk-settings', [AdminController::class, 'riskSettings']);
        Route::post('/admin/risk-settings', [AdminController::class, 'updateRiskSettings']);
        Route::get('/admin/override-logs', [AdminController::class, 'overrideLogs']);
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
