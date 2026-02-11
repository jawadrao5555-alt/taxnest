<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'company'])->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::get('/billing/plans', [BillingController::class, 'plans']);
    Route::post('/billing/subscribe', [BillingController::class, 'subscribe']);

    Route::middleware(['role:company_admin,employee'])->group(function () {
        Route::get('/invoice/create', [InvoiceController::class, 'create']);
        Route::post('/invoice/store', [InvoiceController::class, 'store']);
        Route::put('/invoice/{invoice}', [InvoiceController::class, 'update']);
        Route::post('/invoice/{invoice}/submit', [InvoiceController::class, 'submit']);
    });

    Route::get('/invoice/{invoice}/pdf', [InvoiceController::class, 'pdf']);

    Route::middleware(['role:super_admin'])->group(function () {
        Route::get('/admin/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/admin', function () {
            return "Super Admin Panel";
        });
    });

});
