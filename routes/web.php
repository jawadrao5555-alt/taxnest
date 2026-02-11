<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;

Route::get('/', function () {
    return \Illuminate\Support\Facades\Auth::check()
        ? redirect('/dashboard')
        : redirect('/login');
});

Route::middleware(['auth', 'company'])->group(function () {

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
    });

    Route::get('/invoice/{invoice}', [InvoiceController::class, 'show'])->name('invoice.show');
    Route::get('/invoice/{invoice}/pdf', [InvoiceController::class, 'pdf']);

    Route::middleware(['role:super_admin'])->group(function () {
        Route::get('/admin/dashboard', [AdminController::class, 'dashboard'])->name('admin.dashboard');
        Route::get('/admin/companies', [AdminController::class, 'companies']);
        Route::get('/admin/companies/create', [AdminController::class, 'createCompany']);
        Route::post('/admin/companies', [AdminController::class, 'storeCompany']);
        Route::get('/admin/users', [AdminController::class, 'users']);
        Route::post('/admin/users', [AdminController::class, 'storeUser']);
        Route::get('/admin/fbr-logs', [AdminController::class, 'fbrLogs']);
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
