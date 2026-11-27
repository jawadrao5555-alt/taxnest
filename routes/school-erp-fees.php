<?php

use App\Http\Controllers\SchoolErpFeeController;
use App\Http\Middleware\SchoolErpAccess;
use App\Support\SchoolErpPanel;
use Illuminate\Support\Facades\Route;

Route::prefix(SchoolErpPanel::PREFIX)->name('school-erp.')->middleware(SchoolErpAccess::class)->group(function () {
    Route::get('/fees', [SchoolErpFeeController::class, 'index'])->name('fees');
    Route::post('/fees/schedules', [SchoolErpFeeController::class, 'schedule'])->name('fees.schedule');
    Route::post('/fees/invoices', [SchoolErpFeeController::class, 'issue'])->name('fees.issue');
    Route::post('/fees/invoices/{invoice}/receipts', [SchoolErpFeeController::class, 'receive'])
        ->whereNumber('invoice')->name('fees.receive');
    Route::post('/fees/invoices/{invoice}/receipts/{receipt}/reverse', [SchoolErpFeeController::class, 'reverse'])
        ->whereNumber(['invoice', 'receipt'])->name('fees.reverse');
    Route::get('/fees/receipts/{receipt}', [SchoolErpFeeController::class, 'receipt'])
        ->whereNumber('receipt')->name('fees.receipt');
});
