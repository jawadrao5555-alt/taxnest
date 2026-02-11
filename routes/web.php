<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\InvoiceController;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'company'])->group(function () {

    Route::get('/dashboard', function () {
        $invoices = \App\Models\Invoice::where('company_id', app('currentCompanyId'))->get();
        return view('dashboard', compact('invoices'));
    });

    Route::middleware(['role:company_admin,employee'])->group(function () {
        Route::get('/invoice/create', [InvoiceController::class, 'create']);
        Route::post('/invoice/store', [InvoiceController::class, 'store']);
        Route::put('/invoice/{invoice}', [InvoiceController::class, 'update']);
        Route::post('/invoice/{invoice}/submit', [InvoiceController::class, 'submit']);
    });

    Route::get('/invoice/{invoice}/pdf', [InvoiceController::class, 'pdf']);

    Route::middleware(['role:super_admin'])->group(function () {
        Route::get('/admin', function () {
            return "Super Admin Panel";
        });
    });

});
