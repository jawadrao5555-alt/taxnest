<?php

use App\Http\Controllers\SaasAdmin\WebsiteClientController;
use App\Http\Controllers\WebsiteClientDirectoryController;
use App\Http\Controllers\WebsiteClientLogoController;
use Illuminate\Support\Facades\Route;

Route::get('/our-clients', WebsiteClientDirectoryController::class)->name('website-clients.index');

Route::get('/website-clients/{client}/logo/{revision}', WebsiteClientLogoController::class)
    ->whereNumber(['client', 'revision'])->name('website-clients.logo');

Route::get('/admin/website-clients/company/{company}/logo', [WebsiteClientLogoController::class, 'preview'])
    ->middleware('admin.auth')->whereNumber('company')->name('saas.admin.website-clients.company-logo');

Route::prefix('admin/website-clients')->middleware('admin.auth')->name('saas.admin.website-clients.')
    ->controller(WebsiteClientController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/new', 'edit')->name('create');
        Route::post('/', 'save')->name('store');
        Route::get('/{client}/edit', 'edit')->whereNumber('client')->name('edit');
        Route::get('/{client}/logo/{revision}', 'logo')->whereNumber(['client', 'revision'])->name('logo');
        Route::put('/{client}', 'save')->whereNumber('client')->name('update');
        Route::post('/{client}/visibility', 'visibility')->whereNumber('client')->name('visibility');
    });
