<?php

use App\Http\Controllers\Health\HealthLabCatalogueController;
use App\Http\Controllers\Health\HealthLabOperationsController;
use App\Http\Controllers\Health\HealthLabOrderController;
use App\Http\Controllers\Health\LabWorkspaceController;
use App\Http\Middleware\HealthSubscriptionLock;
use Illuminate\Support\Facades\Route;

Route::get('/lab/costs', [HealthLabOperationsController::class, 'costs'])
    ->middleware(['health.module:lab', 'health.can:lab.costing.view'])->name('health.lab.costs');

Route::middleware(['health.module:lab', 'health.can:lab.view'])->group(function () {
    Route::get('/lab', [LabWorkspaceController::class, 'index'])->name('health.lab');
    Route::post('/lab/layout', [LabWorkspaceController::class, 'saveLayout'])->name('health.lab.layout');

    Route::prefix('lab')->name('health.lab.')->group(function () {
        Route::middleware('health.can:lab.operations.view')->group(function () {
            Route::get('/operations', [HealthLabOperationsController::class, 'index'])->name('operations');
            Route::get('/operations/export', [HealthLabOperationsController::class, 'export'])->name('operations.export');
        });

        Route::middleware('health.can:lab.catalogue.manage')->group(function () {
            Route::get('/catalogue', [HealthLabCatalogueController::class, 'index'])->name('catalogue');
            Route::post('/catalogue', [HealthLabCatalogueController::class, 'store'])->name('catalogue.store');
            Route::get('/catalogue/{test}/edit', [HealthLabCatalogueController::class, 'edit'])->whereNumber('test')->name('catalogue.edit');
            Route::patch('/catalogue/{test}', [HealthLabCatalogueController::class, 'update'])->whereNumber('test')->name('catalogue.update');
        });

        Route::middleware('health.can:lab.orders.manage')->group(function () {
            Route::get('/orders/create', [HealthLabOrderController::class, 'create'])->name('orders.create');
            Route::post('/orders', [HealthLabOrderController::class, 'store'])->name('orders.store');
        });

        Route::get('/orders/{order}', [HealthLabOrderController::class, 'show'])->whereNumber('order')->name('orders.show');
        Route::get('/orders/{order}/report', [HealthLabOrderController::class, 'report'])
            ->whereNumber('order')->middleware('health.can:lab.report')->name('orders.report');

        Route::post('/orders/{order}/billing', [HealthLabOrderController::class, 'sendFeesToBilling'])
            ->whereNumber('order')->middleware(['health.module:accounts', 'health.can:billing.charge', HealthSubscriptionLock::class])
            ->name('orders.billing');

        Route::prefix('orders/{order}/items/{item}')->where(['order' => '[0-9]+', 'item' => '[0-9]+'])->group(function () {
            Route::get('/specimens/{specimen}/label', [HealthLabOrderController::class, 'label'])
                ->whereNumber('specimen')->middleware('health.can:lab.collect')->name('orders.label');
            Route::post('/collect', [HealthLabOrderController::class, 'collect'])
                ->middleware('health.can:lab.collect')->name('orders.collect');
            Route::post('/reject', [HealthLabOrderController::class, 'reject'])
                ->middleware('health.can:lab.collect')->name('orders.reject');
            Route::post('/result', [HealthLabOrderController::class, 'result'])
                ->middleware('health.can:lab.result')->name('orders.result');
            Route::post('/verify', [HealthLabOrderController::class, 'verify'])
                ->middleware('health.can:lab.verify')->name('orders.verify');
        });
    });
});
