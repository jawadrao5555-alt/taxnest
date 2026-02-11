<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\InvoiceController;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'company'])->group(function () {

    Route::get('/dashboard', function () {
        return "Company Dashboard Active";
    });

    Route::put('/invoice/{invoice}', [InvoiceController::class, 'update']);
    Route::post('/invoice/{invoice}/submit', [InvoiceController::class, 'submit']);

});
