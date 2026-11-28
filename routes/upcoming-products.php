<?php

use App\Http\Controllers\SaasAdmin\UpcomingProductController as AdminUpcomingProductController;
use App\Http\Controllers\UpcomingProductController;
use Illuminate\Support\Facades\Route;

Route::prefix('upcoming-products')->name('upcoming-products.')->controller(UpcomingProductController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/{slug}/interest', 'interest')->where('slug', '[a-z0-9-]+')
        ->middleware('throttle:8,1')->name('interest');
    Route::get('/manage-interest', 'manage')->middleware('throttle:30,1')->name('manage');
    Route::post('/manage-interest', 'changeInterest')->middleware('throttle:10,1')->name('change-interest');
});

Route::prefix('admin/upcoming-products')->middleware('admin.auth')->name('saas.admin.upcoming-products.')
    ->controller(AdminUpcomingProductController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/{product}/edit', 'edit')->whereNumber('product')->name('edit');
        Route::put('/{product}', 'update')->whereNumber('product')->name('update');
        Route::get('/{product}/interests', 'interests')->whereNumber('product')->name('interests');
    });
