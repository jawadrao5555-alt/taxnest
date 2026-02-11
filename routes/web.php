<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'company'])->group(function () {

    Route::get('/dashboard', function () {
        return "Company Dashboard Active";
    });

});
